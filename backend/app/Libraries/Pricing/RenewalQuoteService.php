<?php

declare(strict_types=1);

namespace App\Libraries\Pricing;

use App\Libraries\ResellerClub\ResellerClubClient;
use CodeIgniter\Cache\CacheInterface;
use Config\ResellerClub as ResellerClubConfig;

/**
 * Read-only renewal price quote for ONE domain and ONE tenure.
 *
 * The price is the reseller cost price from products/reseller-cost-price.json:
 * `<product key> -> renewdomain -> <years>`. For .com (`domcno`, one year) it
 * was observed to equal the control panel's renewal cost exactly. The
 * customer-price and reseller-price lists are deliberately never used.
 *
 * - Only TLDs in PRODUCT_KEYS are quoted. A mapping is added only with
 *   evidence; every other TLD yields status `unsupported_tld` and no amount.
 * - The response has no currency; it comes from configuration
 *   (ResellerClub::$costPriceCurrency). No conversion is ever performed.
 * - Tax is an ESTIMATE, never an invoice. ResellerClub support confirmed the
 *   cost price excludes GST and that GST is a flat percentage (18% on this
 *   account); there is no tax API. GST (`tax`) and VAT (`vat`) use the
 *   configured rates and integer minor units (no floats): each is
 *   round-half-up(amount x rate / 100) to 2 decimals and
 *   total = amount + GST + VAT. Invalid rates give no tax and no total.
 * - The full provider list is large: only the renewal prices of the mapped
 *   product keys are kept, and they are cached for costPriceCacheTtl seconds.
 *   A provider failure is never cached and never replaced by a guess.
 */
class RenewalQuoteService
{
    /**
     * TLD (text after the first dot of the domain name) => product key.
     *
     * com/org were verified against this account's prices. The others come from
     * ResellerClub's published "Product Keys" list (cross-checked with the
     * go-resellerclub constants); their prices have not been fetched live, so a
     * key missing from the provider's list yields `price_unavailable`, never an
     * amount. Every TLD not listed here is `unsupported_tld`. Keys differ in
     * pattern (domcno, dotnet, thirdleveldotin, ...): never derive one from the
     * extension name.
     */
    public const PRODUCT_KEYS = [
        'com'    => 'domcno',
        'org'    => 'domorg',
        'net'    => 'dotnet',
        'biz'    => 'dombiz',
        'info'   => 'dominfo',
        'in'     => 'dotin',
        'co.in'  => 'thirdleveldotin',
        'net.in' => 'thirdleveldotin',
        'org.in' => 'thirdleveldotin',
        'gen.in' => 'thirdleveldotin',
        'firm.in' => 'thirdleveldotin',
        'ind.in' => 'thirdleveldotin',
        'co'     => 'dotco',
        'online' => 'dotonline',
        'site'   => 'dotsite',
        'xyz'    => 'dotxyz',
    ];

    public const MIN_YEARS = 1;
    public const MAX_YEARS = 10;

    /** Rates above this are rejected as misconfiguration. */
    private const MAX_RATE_PERCENT = 100;

    private const CACHE_KEY_PREFIX = 'rc_cost_renewal_prices_v3_';

    private ?CacheInterface $cache;

    /**
     * Cache key namespaced by account: a short SHA-256 of the API host and the
     * auth-userid (an identifier, not a credential). The API key is never part
     * of it. Hashing keeps the key within the cache handler's allowed
     * characters whatever the configured values are; the same user id on a
     * different host (test vs live) also gets its own entry.
     */
    public static function cacheKey(ResellerClubConfig $config): string
    {
        return self::CACHE_KEY_PREFIX . substr(hash('sha256', rtrim($config->baseUrl, '/') . "\n" . $config->authUserId), 0, 16);
    }

    public function __construct(private readonly ResellerClubClient $client, private readonly ResellerClubConfig $config, ?CacheInterface $cache = null)
    {
        $this->cache = $cache;
    }

    public static function validYears(int $years): bool
    {
        return $years >= self::MIN_YEARS && $years <= self::MAX_YEARS;
    }

    /** The mapped TLD of a canonical domain name, or null when there is none. */
    public static function tldOf(string $domainName): ?string
    {
        $dot = strpos($domainName, '.');

        return $dot === false ? null : substr($domainName, $dot + 1);
    }

    /**
     * @return array{
     *   domain: string, years: int, status: string, currency: string|null,
     *   renewal_amount: string|null, tax: string|null, vat: string|null, total: string|null,
     *   gst_rate: string|null, vat_rate: string|null, tax_status: string,
     *   source: string, cached: bool, fetched_at: string|null
     * }
     *
     * With $freshPrice the cached price list is NEVER read: the provider list
     * is fetched again (a GET; there is no POST anywhere in quoting) and a
     * fetch failure throws instead of falling back to anything stale. The
     * fresh result still refreshes the cache (it is newer data).
     *
     * @throws \App\Libraries\ResellerClub\ResellerClubException
     */
    public function quote(string $domainName, int $years, bool $domainMissing = false, bool $freshPrice = false): array
    {
        $out = [
            'domain'         => $domainName,
            'years'          => $years,
            'status'         => 'price_unavailable',
            'currency'       => null,
            'renewal_amount' => null,
            // Filled in only when a price and valid rates exist: see the class docblock.
            'tax'            => null,
            'vat'            => null,
            'total'          => null,
            'gst_rate'       => null,
            'vat_rate'       => null,
            'tax_status'     => 'not_established',
            'source'         => 'reseller_cost_price',
            'cached'         => false,
            'fetched_at'     => null,
        ];

        if ($domainMissing) {
            $out['status'] = 'domain_not_listed';

            return $out;
        }

        $tld = self::tldOf($domainName);
        $key = $tld === null ? null : (self::PRODUCT_KEYS[$tld] ?? null);

        if ($key === null) {
            $out['status'] = 'unsupported_tld';

            return $out;
        }

        $currency = $this->currency();

        if ($currency === null) {
            return $out;
        }

        [$prices, $cached, $fetchedAt] = $this->prices($freshPrice);

        $amount = $prices[$key][(string) $years] ?? null;

        if ($amount === null) {
            return $out;
        }

        $out['status']         = 'available';
        $out['currency']       = $currency;
        $out['renewal_amount'] = $amount;
        $out['cached']         = $cached;
        $out['fetched_at']     = $fetchedAt;

        $tax = self::computeTax($amount, $this->config->renewalGstRate, $this->config->renewalVatRate);

        if ($tax !== null) {
            $out['renewal_amount'] = $tax['amount'];
            $out['tax']            = $tax['gst'];
            $out['vat']            = $tax['vat'];
            $out['total']          = $tax['total'];
            $out['gst_rate']       = $tax['gst_rate'];
            $out['vat_rate']       = $tax['vat_rate'];
            $out['tax_status']     = 'estimated';
        }

        return $out;
    }

    /**
     * Decimal-safe GST/VAT estimate in integer minor units.
     *
     * The amount is first normalised to 2 decimals (half-up; a no-op for the
     * usual "1419.00"), then GST and VAT are each rounded half-up to 2
     * decimals from that figure and the total is the exact sum of the three.
     * Returns null for an invalid amount or rate (never a guess).
     *
     * @return array{amount: string, gst: string, vat: string, total: string, gst_rate: string, vat_rate: string}|null
     */
    public static function computeTax(string $amount, string $gstRate, string $vatRate): ?array
    {
        // 9 integer digits keeps cents x rate x 2 well inside a 64-bit integer.
        $cents = self::toMinor($amount, 2, 9);
        $gst   = self::parseRate($gstRate);
        $vat   = self::parseRate($vatRate);

        if ($cents === null || $gst === null || $vat === null) {
            return null;
        }

        // cents x rate (1e-4 percent) / 100 / 1e4 => divide by 1e6, rounding half up.
        $gstCents = intdiv($cents * $gst[0] * 2 + 1000000, 2000000);
        $vatCents = intdiv($cents * $vat[0] * 2 + 1000000, 2000000);

        return [
            'amount'   => self::fromMinor($cents),
            'gst'      => self::fromMinor($gstCents),
            'vat'      => self::fromMinor($vatCents),
            'total'    => self::fromMinor($cents + $gstCents + $vatCents),
            'gst_rate' => $gst[1],
            'vat_rate' => $vat[1],
        ];
    }

    /**
     * Sums already-quoted domains. Each domain keeps its own rounded GST and
     * VAT; the bulk figures are the sums of those, never a re-computation
     * from the combined price. Only `available` quotes with a total count.
     *
     * @param list<array<string, mixed>> $quotes
     *
     * @return array{count: int, renewal_amount: string, tax: string, vat: string, total: string}
     */
    public static function sumQuotes(array $quotes): array
    {
        $sum   = ['renewal_amount' => 0, 'tax' => 0, 'vat' => 0, 'total' => 0];
        $count = 0;

        foreach ($quotes as $q) {
            $parts = [];

            foreach (array_keys($sum) as $field) {
                $parts[$field] = is_string($q[$field] ?? null) ? self::toMinor($q[$field], 2, 12) : null;
            }

            if (($q['status'] ?? null) !== 'available' || in_array(null, $parts, true)) {
                continue;
            }

            foreach ($parts as $field => $value) {
                $sum[$field] += $value;
            }

            $count++;
        }

        return [
            'count'          => $count,
            'renewal_amount' => self::fromMinor($sum['renewal_amount']),
            'tax'            => self::fromMinor($sum['tax']),
            'vat'            => self::fromMinor($sum['vat']),
            'total'          => self::fromMinor($sum['total']),
        ];
    }

    /**
     * "18", "12.5" => [rate scaled by 1e4, canonical string]; null when not a
     * plain non-negative decimal of at most 4 decimals within 0..100.
     *
     * @return array{0: int, 1: string}|null
     */
    private static function parseRate(string $rate): ?array
    {
        $units = self::toMinor(trim($rate), 4, 3);

        if ($units === null || $units > self::MAX_RATE_PERCENT * 10000) {
            return null;
        }

        $text = rtrim(rtrim(sprintf('%d.%04d', intdiv($units, 10000), $units % 10000), '0'), '.');

        return [$units, $text];
    }

    /**
     * Plain decimal string => integer in 10^-$scale units, rounding half up
     * beyond $scale decimals (at most 4 decimals accepted). Null when malformed.
     */
    private static function toMinor(string $value, int $scale, int $maxIntDigits): ?int
    {
        if (preg_match('/^([0-9]{1,' . $maxIntDigits . '})(?:\.([0-9]{1,4}))?$/', $value, $m) !== 1) {
            return null;
        }

        $frac   = str_pad($m[2] ?? '', 4, '0');
        $scaled = (int) $m[1] * 10 ** $scale + (int) substr($frac, 0, $scale);

        if ($scale < 4 && (int) $frac[$scale] >= 5) {
            $scaled++;
        }

        return $scaled;
    }

    private static function fromMinor(int $cents): string
    {
        return intdiv($cents, 100) . '.' . str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
    }

    private function currency(): ?string
    {
        $currency = strtoupper(trim($this->config->costPriceCurrency));

        return preg_match('/^[A-Z]{3}$/', $currency) === 1 ? $currency : null;
    }

    /**
     * @return array{0: array<string, array<string, string>>, 1: bool, 2: string|null} prices by product key and tenure, from cache?, fetch time
     */
    private function prices(bool $bypassCache = false): array
    {
        $cache  = $this->cache ?? cache();
        $key    = self::cacheKey($this->config);
        $stored = $bypassCache ? null : $cache->get($key);

        if (is_array($stored) && is_array($stored['prices'] ?? null) && is_string($stored['fetched_at'] ?? null)) {
            return [$stored['prices'], true, $stored['fetched_at']];
        }

        $list      = $this->client->resellerCostPrices();
        $prices    = $this->extract($list);
        $fetchedAt = gmdate('Y-m-d\TH:i:s\Z');

        // An empty extraction (unexpected shape) is not cached, so it is retried.
        if ($prices !== []) {
            $cache->save($key, ['prices' => $prices, 'fetched_at' => $fetchedAt], max(60, $this->config->costPriceCacheTtl));
        }

        return [$prices, false, $fetchedAt];
    }

    /**
     * Keeps `renewdomain` tenures of the mapped product keys only. Entries that
     * are not plain non-negative decimals are dropped, never coerced.
     *
     * @param array<string, mixed> $list
     *
     * @return array<string, array<string, string>>
     */
    private function extract(array $list): array
    {
        $out = [];

        foreach (array_unique(array_values(self::PRODUCT_KEYS)) as $key) {
            $renew = $list[$key]['renewdomain'] ?? null;

            if (! is_array($renew)) {
                continue;
            }

            foreach ($renew as $tenure => $amount) {
                $tenure = (string) $tenure;

                if (preg_match('/^[0-9]{1,2}$/', $tenure) !== 1 || (! is_string($amount) && ! is_int($amount) && ! is_float($amount))) {
                    continue;
                }

                $amount = trim((string) $amount);

                if (preg_match('/^[0-9]{1,12}(\.[0-9]{1,4})?$/', $amount) === 1) {
                    $out[$key][$tenure] = $amount;
                }
            }
        }

        return $out;
    }
}
