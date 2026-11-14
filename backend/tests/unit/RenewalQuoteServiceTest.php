<?php

declare(strict_types=1);

use App\Libraries\Pricing\RenewalQuoteService;
use App\Libraries\ResellerClub\ResellerClubClient;
use App\Libraries\ResellerClub\ResellerClubException;
use CodeIgniter\Cache\CacheInterface;
use CodeIgniter\Test\CIUnitTestCase;
use Config\ResellerClub as ResellerClubConfig;
use Tests\Support\Libraries\IsolatedResellerClubConfig;

/**
 * Quote behaviour against a fake transport and an in-memory cache. No network.
 *
 * @internal
 */
final class RenewalQuoteServiceTest extends CIUnitTestCase
{
    private const SECRET_KEY = 'sentinel-api-key-never-log';

    private function config(): ResellerClubConfig
    {
        $c              = IsolatedResellerClubConfig::make();
        $c->baseUrl     = 'https://test.httpapi.example/api';
        $c->authUserId  = '123456';
        $c->apiKey      = self::SECRET_KEY;
        $c->maxAttempts = 2;

        return $c;
    }

    /**
     * @param list<array{status: int, body: string}> $script
     * @param list<array{0: string, 1: array<string, scalar>}> $calls
     */
    private function service(array $script, array &$calls = [], ?ResellerClubConfig $config = null, ?array &$store = null): RenewalQuoteService
    {
        $config ??= $this->config();
        $store ??= [];

        $cache = $this->createMock(CacheInterface::class);
        $cache->method('get')->willReturnCallback(static function (string $k) use (&$store) {
            return $store[$k] ?? null;
        });
        $cache->method('save')->willReturnCallback(static function (string $k, $v) use (&$store): bool {
            $store[$k] = $v;

            return true;
        });

        $client = new ResellerClubClient(
            $config,
            static function (string $url, array $query) use (&$script, &$calls): array {
                $calls[] = [$url, $query];

                return array_shift($script);
            },
            static function (int $ms): void {},
        );

        return new RenewalQuoteService($client, $config, $cache);
    }

    private function prices(array $extra = []): array
    {
        return ['status' => 200, 'body' => json_encode($extra + [
            'domcno' => ['renewdomain' => ['1' => '1219.00', '2' => '2438.00'], 'addnewdomain' => ['1' => '1.00']],
            'dotnet' => ['renewdomain' => ['1' => '999.00']],
        ])];
    }

    public function testComOneYearQuoteUsesTheCostPriceListOnly(): void
    {
        $calls = [];
        $quote = $this->service([$this->prices()], $calls)->quote('alagarfarms.com', 1);

        $this->assertSame('available', $quote['status']);
        $this->assertSame('1219.00', $quote['renewal_amount']);
        $this->assertSame('INR', $quote['currency']);
        $this->assertSame('219.42', $quote['tax']);
        $this->assertSame('1438.42', $quote['total']);
        $this->assertSame('estimated', $quote['tax_status']);
        $this->assertFalse($quote['cached']);

        $this->assertCount(1, $calls);
        $this->assertSame('https://test.httpapi.example/api/products/reseller-cost-price.json', $calls[0][0]);
        $this->assertSame(['auth-userid', 'api-key'], array_keys($calls[0][1]));
    }

    public function testSecondQuoteIsServedFromCacheWithoutAnotherRequest(): void
    {
        $calls   = [];
        $service = $this->service([$this->prices()], $calls);

        $service->quote('alagarfarms.com', 1);
        $second = $service->quote('other.com', 2);

        $this->assertCount(1, $calls);
        $this->assertTrue($second['cached']);
        $this->assertSame('2438.00', $second['renewal_amount']);
    }

    public function testUnsupportedTldHasNoAmountAndMakesNoRequest(): void
    {
        $calls = [];
        $quote = $this->service([], $calls)->quote('example.media', 1);

        $this->assertSame('unsupported_tld', $quote['status']);
        $this->assertNull($quote['renewal_amount']);
        $this->assertSame([], $calls);
    }

    public function testMultiLabelSuffixIsNotTreatedAsCom(): void
    {
        $quote = $this->service([])->quote('shop.example.com', 1);

        $this->assertSame('unsupported_tld', $quote['status']);
    }

    public function testMissingTenureIsPriceUnavailable(): void
    {
        $quote = $this->service([$this->prices()])->quote('alagarfarms.com', 5);

        $this->assertSame('price_unavailable', $quote['status']);
        $this->assertNull($quote['renewal_amount']);
    }

    public function testMissingDomainIsNotQuoted(): void
    {
        $calls = [];
        $quote = $this->service([], $calls)->quote('alagarfarms.com', 1, true);

        $this->assertSame('domain_not_listed', $quote['status']);
        $this->assertSame([], $calls);
    }

    public function testMalformedAmountIsDroppedNotCoerced(): void
    {
        $body  = ['status' => 200, 'body' => json_encode(['domcno' => ['renewdomain' => ['1' => '12,19', '2' => 'abc']]])];
        $quote = $this->service([$body])->quote('alagarfarms.com', 1);

        $this->assertSame('price_unavailable', $quote['status']);
    }

    public function testInvalidCurrencyConfigurationGivesNoAmount(): void
    {
        $config                    = $this->config();
        $config->costPriceCurrency = 'rupees';
        $calls                     = [];
        $quote                     = $this->service([$this->prices()], $calls, $config)->quote('alagarfarms.com', 1);

        $this->assertSame('price_unavailable', $quote['status']);
        $this->assertNull($quote['renewal_amount']);
    }

    public function testProviderErrorBodyThrowsAFixedCodeWithoutSecrets(): void
    {
        $service = $this->service([['status' => 200, 'body' => json_encode(['status' => 'ERROR', 'message' => 'secret detail'])]]);

        try {
            $service->quote('alagarfarms.com', 1);
            $this->fail('Expected ResellerClubException');
        } catch (ResellerClubException $e) {
            $this->assertSame('provider_error', $e->getMessage());
            $this->assertStringNotContainsString(self::SECRET_KEY, $e->getMessage());
        }
    }

    public function testCacheKeyChangesWithTheAccountAndHostAndHoldsNoCredential(): void
    {
        $base = $this->config();
        $user = $this->config();
        $host = $this->config();

        $user->authUserId = '654321';
        $host->baseUrl    = 'https://httpapi.example/api';

        $key = RenewalQuoteService::cacheKey($base);

        $this->assertSame($key, RenewalQuoteService::cacheKey($this->config()));
        $this->assertNotSame($key, RenewalQuoteService::cacheKey($user));
        $this->assertNotSame($key, RenewalQuoteService::cacheKey($host));
        $this->assertStringNotContainsString(self::SECRET_KEY, $key);
        $this->assertStringNotContainsString($base->authUserId, $key);
        $this->assertMatchesRegularExpression('/^[a-z0-9_]+$/', $key);

        // Changing only the API key must not change the namespace.
        $rotated         = $this->config();
        $rotated->apiKey = 'another-key';
        $this->assertSame($key, RenewalQuoteService::cacheKey($rotated));
    }

    public function testAnotherAccountNeverReadsTheFirstAccountsCachedPrices(): void
    {
        $store = [];
        $calls = [];
        $other = $this->config();

        $other->authUserId = '654321';

        $first  = $this->service([$this->prices()], $calls, null, $store)->quote('alagarfarms.com', 1);
        $second = $this->service([['status' => 200, 'body' => json_encode(['domcno' => ['renewdomain' => ['1' => '1300.00']]])]], $calls, $other, $store)->quote('alagarfarms.com', 1);

        $this->assertSame('1219.00', $first['renewal_amount']);
        $this->assertSame('1300.00', $second['renewal_amount']);
        $this->assertFalse($second['cached']);
        $this->assertCount(2, $calls);
        $this->assertCount(2, $store);
    }

    public function testOrgMapsToTheVerifiedDomorgProductKey(): void
    {
        $this->assertSame('domorg', RenewalQuoteService::PRODUCT_KEYS['org']);
        $this->assertSame('domcno', RenewalQuoteService::PRODUCT_KEYS['com']);
        $this->assertSame('dotnet', RenewalQuoteService::PRODUCT_KEYS['net']);
    }

    public function testOrgOneYearQuoteUsesDomorgCostPriceAndConfiguredCurrency(): void
    {
        $calls = [];
        $body  = $this->prices(['domorg' => ['renewdomain' => ['1' => '1419.00']]]);
        $quote = $this->service([$body], $calls)->quote('shivohamfoundation.org', 1);

        $this->assertSame('available', $quote['status']);
        $this->assertSame('1419.00', $quote['renewal_amount']);
        $this->assertSame('INR', $quote['currency']);
        $this->assertSame('255.42', $quote['tax']);
        $this->assertSame('1674.42', $quote['total']);
        $this->assertSame('0.00', $quote['vat']);
        $this->assertSame('estimated', $quote['tax_status']);
        $this->assertCount(1, $calls);
    }

    public function testOrgCurrencyFollowsConfigurationNotAnAssumption(): void
    {
        $config                    = $this->config();
        $config->costPriceCurrency = 'USD';
        $calls                     = [];
        $body                     = $this->prices(['domorg' => ['renewdomain' => ['1' => '1419.00']]]);
        $quote                     = $this->service([$body], $calls, $config)->quote('shivohamfoundation.org', 1);

        $this->assertSame('USD', $quote['currency']);
    }

    public function testOrgWithoutDomorgInTheListIsPriceUnavailable(): void
    {
        $quote = $this->service([$this->prices()])->quote('shivohamfoundation.org', 1);

        $this->assertSame('price_unavailable', $quote['status']);
        $this->assertNull($quote['renewal_amount']);
    }

    public function testComIsUnchangedWhenDomorgIsAlsoListed(): void
    {
        $body  = $this->prices(['domorg' => ['renewdomain' => ['1' => '1419.00']]]);
        $quote = $this->service([$body])->quote('alagarfarms.com', 1);

        $this->assertSame('1219.00', $quote['renewal_amount']);
    }

    public function testOtherTldsRemainUnsupportedAfterOrgMapping(): void
    {
        foreach (['example.media', 'example.shop', 'example.ac.in', 'example.com.au', 'example.tech'] as $name) {
            $calls = [];
            $quote = $this->service([], $calls)->quote($name, 1);

            $this->assertSame('unsupported_tld', $quote['status'], $name);
            $this->assertNull($quote['renewal_amount'], $name);
            $this->assertSame([], $calls, $name);
        }
    }

    public function testCacheKeyIsVersion3SoOldV1EntriesAreNeverRead(): void
    {
        $key = RenewalQuoteService::cacheKey($this->config());
        $this->assertStringStartsWith('rc_cost_renewal_prices_v3_', $key);

        // A stale v1 entry holding only domcno must be ignored: a fresh fetch happens.
        $v1key = str_replace('_v3_', '_v1_', $key);
        $store = [$v1key => ['domcno' => ['1' => '1219.00']]];
        $calls = [];
        $body  = $this->prices(['domorg' => ['renewdomain' => ['1' => '1419.00']]]);
        $quote = $this->service([$body], $calls, null, $store)->quote('shivohamfoundation.org', 1);

        $this->assertSame('1419.00', $quote['renewal_amount']);
        $this->assertFalse($quote['cached']);
        $this->assertCount(1, $calls);
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function mappedExtensions(): array
    {
        return [
            'com'    => ['example.com', 'domcno'],
            'org'    => ['example.org', 'domorg'],
            'net'    => ['example.net', 'dotnet'],
            'biz'    => ['example.biz', 'dombiz'],
            'info'   => ['example.info', 'dominfo'],
            'in'     => ['example.in', 'dotin'],
            'co.in'  => ['example.co.in', 'thirdleveldotin'],
            'net.in' => ['example.net.in', 'thirdleveldotin'],
            'org.in' => ['example.org.in', 'thirdleveldotin'],
            'gen.in' => ['example.gen.in', 'thirdleveldotin'],
            'firm.in' => ['example.firm.in', 'thirdleveldotin'],
            'ind.in' => ['example.ind.in', 'thirdleveldotin'],
            'co'     => ['example.co', 'dotco'],
            'online' => ['example.online', 'dotonline'],
            'site'   => ['example.site', 'dotsite'],
            'xyz'    => ['example.xyz', 'dotxyz'],
        ];
    }

    /**
     * @dataProvider mappedExtensions
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('mappedExtensions')]
    public function testEveryMappedExtensionIsQuotedFromItsOwnKey(string $domain, string $key): void
    {
        $tld = RenewalQuoteService::tldOf($domain);
        $this->assertSame($key, RenewalQuoteService::PRODUCT_KEYS[$tld]);

        $body  = $this->prices([$key => ['renewdomain' => ['1' => '500.00', '2' => '950.00']]]);
        $calls = [];
        $one   = $this->service([$body], $calls)->quote($domain, 1);
        $two   = $this->service([$body])->quote($domain, 2);

        $this->assertSame('available', $one['status']);
        $this->assertSame('500.00', $one['renewal_amount']);
        $this->assertSame('90.00', $one['tax']);
        $this->assertSame('590.00', $one['total']);
        $this->assertSame('950.00', $two['renewal_amount']);
        $this->assertSame('171.00', $two['tax']);
    }

    public function testExtensionsSharingAKeyShareItsPriceButNotOtherKeys(): void
    {
        $body = $this->prices(['thirdleveldotin' => ['renewdomain' => ['1' => '700.00']]]);
        $svc  = $this->service([$body]);

        $this->assertSame('700.00', $svc->quote('a.co.in', 1)['renewal_amount']);
        $this->assertSame('700.00', $svc->quote('b.org.in', 1)['renewal_amount']);
        // .in has its own key (dotin), absent from this list.
        $this->assertSame('price_unavailable', $svc->quote('c.in', 1)['status']);
    }

    public function testMappedExtensionMissingFromProviderListIsPriceUnavailableNotAGuess(): void
    {
        $quote = $this->service([$this->prices()])->quote('example.xyz', 1);

        $this->assertSame('price_unavailable', $quote['status']);
        $this->assertNull($quote['renewal_amount']);
        $this->assertNull($quote['total']);
    }

    public function testInvalidPriceForNewExtensionIsDropped(): void
    {
        $body = $this->prices(['dotxyz' => ['renewdomain' => ['1' => '12,00', '2' => '-5', '3' => 'abc']]]);

        foreach ([1, 2, 3] as $years) {
            $this->assertSame('price_unavailable', $this->service([$body])->quote('example.xyz', $years)['status']);
        }
    }

    public function testMixedExtensionsUseOneFetchAndSumIntoOneTotal(): void
    {
        $calls = [];
        $body  = $this->prices([
            'domorg'          => ['renewdomain' => ['1' => '1419.00']],
            'thirdleveldotin' => ['renewdomain' => ['1' => '700.00']],
        ]);
        $svc = $this->service([$body], $calls);

        $quotes = [$svc->quote('a.com', 1), $svc->quote('b.org', 1), $svc->quote('c.co.in', 1), $svc->quote('d.media', 1)];
        $sum    = RenewalQuoteService::sumQuotes($quotes);

        $this->assertCount(1, $calls);
        $this->assertSame('unsupported_tld', $quotes[3]['status']);
        $this->assertSame(3, $sum['count']);
        $this->assertSame('3338.00', $sum['renewal_amount']);
        $this->assertSame('600.84', $sum['tax']);
        $this->assertSame('3938.84', $sum['total']);
    }

    public function testReferenceExampleGstVatAndTotal(): void
    {
        $t = RenewalQuoteService::computeTax('1419.00', '18', '0');

        $this->assertSame('1419.00', $t['amount']);
        $this->assertSame('255.42', $t['gst']);
        $this->assertSame('0.00', $t['vat']);
        $this->assertSame('1674.42', $t['total']);
        $this->assertSame('18', $t['gst_rate']);
        $this->assertSame('0', $t['vat_rate']);
    }

    public function testQuoteResponseCarriesRatesAndEstimatedStatus(): void
    {
        $body  = $this->prices(['domorg' => ['renewdomain' => ['1' => '1419.00']]]);
        $quote = $this->service([$body])->quote('shivohamfoundation.org', 1);

        $this->assertSame('18', $quote['gst_rate']);
        $this->assertSame('0', $quote['vat_rate']);
        $this->assertSame('estimated', $quote['tax_status']);
        $this->assertSame('reseller_cost_price', $quote['source']);
    }

    public function testZeroPrice(): void
    {
        $t = RenewalQuoteService::computeTax('0.00', '18', '0');

        $this->assertSame(['0.00', '0.00', '0.00', '0.00'], [$t['amount'], $t['gst'], $t['vat'], $t['total']]);
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string, 4: string}>
     */
    public static function roundingCases(): array
    {
        return [
            // amount, gst rate, expected gst, expected amount, expected total
            'half rounds up (0.045 -> 0.05)' => ['0.25', '18', '0.05', '0.25', '0.30'],
            'below half rounds down'         => ['0.02', '18', '0.00', '0.02', '0.02'],
            'exact cents'                    => ['1219.00', '18', '219.42', '1219.00', '1438.42'],
            'x.xx5 gst rounds up'            => ['1.25', '18', '0.23', '1.25', '1.48'],
            'whole number, no decimals'      => ['1000', '18', '180.00', '1000.00', '1180.00'],
            'one decimal'                    => ['99.5', '18', '17.91', '99.50', '117.41'],
            'three-decimal price rounds'     => ['10.005', '18', '1.80', '10.01', '11.81'],
            'fractional rate'                => ['100.00', '12.5', '12.50', '100.00', '112.50'],
            'large price'                    => ['999999.99', '18', '180000.00', '999999.99', '1179999.99'],
        ];
    }

    /**
     * @dataProvider roundingCases
     */
    public function testRoundingIsHalfUpAndExact(string $amount, string $rate, string $gst, string $normalised, string $total): void
    {
        $t = RenewalQuoteService::computeTax($amount, $rate, '0');

        $this->assertSame($normalised, $t['amount']);
        $this->assertSame($gst, $t['gst']);
        $this->assertSame($total, $t['total']);
    }

    public function testDifferentPricesAndPeriodsAreEachQuotedFromTheirOwnAmount(): void
    {
        $service = $this->service([$this->prices()]);

        $one = $service->quote('a.com', 1);
        $two = $service->quote('a.com', 2);

        $this->assertSame(['1219.00', '219.42', '1438.42'], [$one['renewal_amount'], $one['tax'], $one['total']]);
        $this->assertSame(['2438.00', '438.84', '2876.84'], [$two['renewal_amount'], $two['tax'], $two['total']]);
        $this->assertSame(2, $two['years']);
        $this->assertTrue($two['cached']);
    }

    public function testBulkTotalsSumTheIndividualDomainQuotes(): void
    {
        $body    = $this->prices(['domorg' => ['renewdomain' => ['1' => '1419.00']]]);
        $service = $this->service([$body]);

        $quotes = [
            $service->quote('shivohamfoundation.org', 1),
            $service->quote('alagarfarms.com', 1),
            $service->quote('other.com', 2),
            $service->quote('example.media', 1), // unsupported: excluded
        ];

        $sum = RenewalQuoteService::sumQuotes($quotes);

        $this->assertSame(3, $sum['count']);
        $this->assertSame('5076.00', $sum['renewal_amount']);
        $this->assertSame('913.68', $sum['tax']);
        $this->assertSame('0.00', $sum['vat']);
        $this->assertSame('5989.68', $sum['total']);

        // Equals the sum of the per-domain totals: 1674.42 + 1438.42 + 2876.84.
        $this->assertSame('5989.68', RenewalQuoteService::sumQuotes([$quotes[0], $quotes[1], $quotes[2]])['total']);
    }

    public function testBulkSumUsesPerDomainRoundingNotCombinedGst(): void
    {
        // Three 0.25 prices: each GST rounds to 0.05 (sum 0.15); GST on 0.75 would be 0.14.
        $q   = ['status' => 'available', 'renewal_amount' => '0.25', 'tax' => '0.05', 'vat' => '0.00', 'total' => '0.30'];
        $sum = RenewalQuoteService::sumQuotes([$q, $q, $q]);

        $this->assertSame('0.15', $sum['tax']);
        $this->assertSame('0.90', $sum['total']);
    }

    public function testBulkSumOfNothingIsZeroWithCountZero(): void
    {
        $sum = RenewalQuoteService::sumQuotes([['status' => 'unsupported_tld', 'renewal_amount' => null, 'tax' => null, 'vat' => null, 'total' => null]]);

        $this->assertSame(0, $sum['count']);
        $this->assertSame('0.00', $sum['total']);
    }

    public function testConfiguredGstAndVatRatesAreUsed(): void
    {
        $calls                  = [];
        $config                 = $this->config();
        $config->renewalGstRate = '9';
        $config->renewalVatRate = '5';
        $quote                  = $this->service([$this->prices()], $calls, $config)->quote('a.com', 1);

        $this->assertSame('109.71', $quote['tax']);
        $this->assertSame('60.95', $quote['vat']);
        $this->assertSame('1389.66', $quote['total']);
        $this->assertSame('9', $quote['gst_rate']);
        $this->assertSame('5', $quote['vat_rate']);
    }

    public function testDefaultsAreTheConfirmedIndiaResellerRates(): void
    {
        $c = IsolatedResellerClubConfig::make();

        $this->assertSame('18', $c->renewalGstRate);
        $this->assertSame('0', $c->renewalVatRate);
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function invalidRates(): array
    {
        return [
            'negative' => ['-1', '0'], 'text' => ['abc', '0'], 'empty' => ['', '0'],
            'over 100' => ['101', '0'], 'comma' => ['18,5', '0'], 'percent sign' => ['18%', '0'],
            'bad vat'  => ['18', 'x'], 'five decimals' => ['18.12345', '0'],
        ];
    }

    /**
     * @dataProvider invalidRates
     */
    public function testInvalidRatesGiveNoTaxAndNoTotalButKeepThePrice(string $gst, string $vat): void
    {
        $this->assertNull(RenewalQuoteService::computeTax('1419.00', $gst, $vat));

        $calls                  = [];
        $config                 = $this->config();
        $config->renewalGstRate = $gst;
        $config->renewalVatRate = $vat;
        $quote                  = $this->service([$this->prices()], $calls, $config)->quote('a.com', 1);

        $this->assertSame('available', $quote['status']);
        $this->assertSame('1219.00', $quote['renewal_amount']);
        $this->assertNull($quote['tax']);
        $this->assertNull($quote['vat']);
        $this->assertNull($quote['total']);
        $this->assertSame('not_established', $quote['tax_status']);
    }

    public function testInvalidAmountGivesNoTax(): void
    {
        foreach (['', 'abc', '-5', '1,419.00', '1419.', '1234567890'] as $amount) {
            $this->assertNull(RenewalQuoteService::computeTax($amount, '18', '0'), $amount);
        }
    }

    public function testNoPriceMeansNoTaxAndNoTotalNeverZero(): void
    {
        foreach ([$this->service([$this->prices()])->quote('a.com', 5), $this->service([])->quote('a.media', 1), $this->service([])->quote('a.com', 1, true)] as $q) {
            $this->assertNull($q['tax']);
            $this->assertNull($q['vat']);
            $this->assertNull($q['total']);
            $this->assertNull($q['gst_rate']);
            $this->assertSame('not_established', $q['tax_status']);
        }
    }

    public function testCachedPricesAreTheUntaxedAmountsSoRateChangesApplyImmediately(): void
    {
        $store = [];
        $calls = [];

        $this->service([$this->prices()], $calls, null, $store)->quote('a.com', 1);

        $config                 = $this->config();
        $config->renewalGstRate = '10';
        $second                 = $this->service([], $calls, $config, $store)->quote('a.com', 1);

        $this->assertTrue($second['cached']);
        $this->assertCount(1, $calls);
        $this->assertSame('121.90', $second['tax']);
        $this->assertSame('1340.90', $second['total']);
    }

    public function testYearBounds(): void
    {
        $this->assertTrue(RenewalQuoteService::validYears(1));
        $this->assertTrue(RenewalQuoteService::validYears(10));
        $this->assertFalse(RenewalQuoteService::validYears(0));
        $this->assertFalse(RenewalQuoteService::validYears(11));
    }

    // ---- cache-bypassing fresh price (preflight) ----------------------------------

    public function testFreshPriceNeverReadsAPrimedCache(): void
    {
        $store = [];
        $calls = [];

        // Prime the cache with one price...
        $this->service([$this->prices()], $calls, null, $store)->quote('a.com', 1);
        $this->assertCount(1, $calls);

        // ...then a fresh-price quote must hit the transport again and use the NEW price.
        $body  = ['status' => 200, 'body' => json_encode(['domcno' => ['renewdomain' => ['1' => '1300.00']]])];
        $quote = $this->service([$body], $calls, null, $store)->quote('a.com', 1, false, true);

        $this->assertCount(2, $calls);
        $this->assertSame('1300.00', $quote['renewal_amount']);
        $this->assertFalse($quote['cached']);
    }

    public function testFreshPriceFailureThrowsInsteadOfFallingBackToTheCache(): void
    {
        $store = [];
        $calls = [];

        $this->service([$this->prices()], $calls, null, $store)->quote('a.com', 1);

        // maxAttempts is 2 in this config: two 5xx answers exhaust the fetch.
        $failing = $this->service([['status' => 500, 'body' => ''], ['status' => 500, 'body' => '']], $calls, null, $store);

        try {
            $failing->quote('a.com', 1, false, true);
            $this->fail('Expected ResellerClubException');
        } catch (ResellerClubException $e) {
            $this->assertSame('http_500', $e->getMessage());
        }
    }

    public function testFreshPriceResultRefreshesTheCacheForLaterCachedQuotes(): void
    {
        $store = [];
        $calls = [];

        $body = ['status' => 200, 'body' => json_encode(['domcno' => ['renewdomain' => ['1' => '1300.00']]])];
        $this->service([$body], $calls, null, $store)->quote('a.com', 1, false, true);

        // A normal quote afterwards is served from the refreshed cache: no request.
        $second = $this->service([], $calls, null, $store)->quote('a.com', 1);

        $this->assertCount(1, $calls);
        $this->assertTrue($second['cached']);
        $this->assertSame('1300.00', $second['renewal_amount']);
    }
}
