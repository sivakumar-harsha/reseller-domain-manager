<?php

declare(strict_types=1);

namespace App\Libraries\Renewal;

use App\Libraries\Pricing\RenewalQuoteService;
use App\Libraries\ResellerClub\ResellerClubClient;
use App\Libraries\ResellerClub\ResellerClubException;
use Closure;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use Config\ResellerClub as ResellerClubConfig;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Pre-execution checks for ONE manual renewal request. STRICTLY read-only:
 * it never claims an item, never writes a row and never sends a POST — its
 * only provider traffic is two GETs (a cache-bypassing price list fetch and a
 * fresh balance). Any failure throws a fixed-code RenewalException and leaves
 * every item exactly as it was (`pending`), so nothing can have been spent.
 *
 * Check order (cheapest and least privileged first, no network before 4):
 *  1. requested item count within renewalMaxItemsPerExecution
 *  2. batch exists and is younger than renewalBatchMaxAgeSeconds
 *  3. there is a pending item at all (none -> clean no-op result)
 *  4. renewalDebitVerified: the actual debit currency and GST/debit treatment
 *     must have been confirmed with the provider, otherwise fail closed with
 *     `balance_currency_unverified` (this is Phase 1's master money gate)
 *  5. snapshot currency equals the configured debit currency
 *  6. FRESH price (cache NEVER read): a higher price than the confirmed
 *     snapshot blocks with `price_changed`; a lower one proceeds on the
 *     ORIGINAL snapshot and is only reported
 *  7. FRESH balance (never cached anywhere): fetched for display only; its
 *     currency must equal the configured debit currency, its amount is NOT
 *     compared with the total and never blocks execution
 *
 * All money comparisons use integer 1e-4 units (the balance may carry four
 * decimals, the snapshots two). No floats, no rounding, no conversion.
 */
final class RenewalPreflightService
{
    private BaseConnection $db;

    /** @var Closure(): DateTimeImmutable */
    private Closure $clock;

    /**
     * @param (callable(): DateTimeImmutable)|null $clock
     */
    public function __construct(
        private readonly ResellerClubClient $client,
        private readonly ResellerClubConfig $config,
        private readonly RenewalQuoteService $quotes,
        ?BaseConnection $db = null,
        ?callable $clock = null,
    ) {
        $this->db    = $db ?? Database::connect();
        $this->clock = Closure::fromCallable($clock ?? static fn (): DateTimeImmutable => new DateTimeImmutable('now', new DateTimeZone('UTC')));
    }

    /**
     * Validates the next pending item of the batch. `item_id` is null when the
     * batch has nothing pending (then nothing was fetched and nothing may be
     * executed). The amounts in the result are ESTIMATES, never a final
     * invoice figure.
     *
     * @return array{
     *   item_id: int|null, estimated_total: string|null, fresh_total: string|null,
     *   price_decreased: bool, currency: string|null, available_balance: string|null
     * }
     *
     * With `$itemId` the named item is validated instead (it must belong to the
     * batch and be pending: not_found / item_not_pending otherwise).
     *
     * @throws RenewalException not_found | item_not_pending | execution_limit_exceeded | batch_expired |
     *                          balance_currency_unverified | balance_currency_mismatch |
     *                          balance_unavailable |
     *                          price_unavailable | price_changed | invalid_amount | not_configured
     */
    public function check(int $batchId, int $items = 1, ?int $itemId = null): array
    {
        if ($items < 1 || $items > max(1, $this->config->renewalMaxItemsPerExecution)) {
            throw new RenewalException('execution_limit_exceeded');
        }

        $batch = $this->db->table('renewal_batches')->select('id, years, created_at')->where('id', $batchId)->get()->getRowArray();

        if ($batch === null) {
            throw new RenewalException('not_found');
        }

        $created = (new DateTimeImmutable((string) $batch['created_at'], new DateTimeZone('UTC')))->getTimestamp();
        $age     = ($this->clock)()->getTimestamp() - $created;

        // A negative age means the clocks disagree: that is just as untrustworthy.
        if ($age < 0 || $age > max(0, $this->config->renewalBatchMaxAgeSeconds)) {
            throw new RenewalException('batch_expired');
        }

        if ($itemId !== null) {
            // Phase 2: the caller names ONE item explicitly; the next pending item is never substituted.
            $named = $this->db->query(
                'SELECT i.id, i.state, i.total_amount, i.currency, d.domain_name FROM renewal_items i '
                . 'JOIN domains d ON d.id = i.domain_id WHERE i.batch_id = ? AND i.id = ?',
                [$batchId, $itemId],
            )->getRowArray();

            if ($named === null) {
                throw new RenewalException('not_found');
            }

            if ($named['state'] !== 'pending') {
                throw new RenewalException('item_not_pending');
            }

            $item = $named;
        } else {
            $item = $this->db->query(
                'SELECT i.id, i.total_amount, i.currency, d.domain_name FROM renewal_items i '
                . "JOIN domains d ON d.id = i.domain_id WHERE i.batch_id = ? AND i.state = 'pending' ORDER BY i.id ASC LIMIT 1",
                [$batchId],
            )->getRowArray();
        }

        if ($item === null) {
            // Nothing pending: nothing can be spent, so no provider call is made.
            return ['item_id' => null, 'estimated_total' => null, 'fresh_total' => null, 'price_decreased' => false, 'currency' => null, 'available_balance' => null];
        }

        // Master money gate: the real debit currency and GST/debit treatment
        // are not verified yet, so no comparison would be meaningful.
        if (! $this->config->renewalDebitVerified) {
            throw new RenewalException('balance_currency_unverified');
        }

        $debit = trim($this->config->renewalDebitCurrency);

        if ($debit === '') {
            throw new RenewalException('balance_currency_unverified');
        }

        $snapshot = self::minor4((string) $item['total_amount']);

        if ($snapshot === null || $snapshot < 0) {
            throw new RenewalException('invalid_amount');
        }

        if ((string) $item['currency'] !== $debit) {
            throw new RenewalException('balance_currency_mismatch');
        }

        $freshTotal = $this->freshTotal((string) $item['domain_name'], (int) $batch['years'], $debit);
        $fresh      = self::minor4($freshTotal);

        if ($fresh === null) {
            throw new RenewalException('price_unavailable');
        }

        if ($fresh > $snapshot) {
            // The price moved up after confirmation: never silently charge more.
            throw new RenewalException('price_changed');
        }

        // The accounting balance is INFORMATIONAL only: it is fetched fresh and
        // its currency must match exactly, but its AMOUNT is never compared with
        // the renewal total and never gates execution. ResellerClub itself
        // accepts or rejects the actual payment.
        $available = $this->availableBalance($debit);

        return [
            'item_id'           => (int) $item['id'],
            'estimated_total'   => (string) $item['total_amount'],
            'fresh_total'       => $freshTotal,
            'price_decreased'   => $fresh < $snapshot,
            'currency'          => $debit,
            'available_balance' => $available,
        ];
    }

    /**
     * The GST-inclusive estimated total from a CACHE-BYPASSING fresh quote
     * (GET only). Fails closed on any fetch, shape or currency problem.
     *
     * @throws RenewalException
     */
    private function freshTotal(string $domainName, int $years, string $debit): string
    {
        try {
            $quote = $this->quotes->quote($domainName, $years, false, true);
        } catch (ResellerClubException $e) {
            throw new RenewalException($e->getMessage() === 'not_configured' ? 'not_configured' : 'price_unavailable');
        }

        if (($quote['status'] ?? null) !== 'available'
            || ($quote['tax_status'] ?? null) !== 'estimated'
            || ! is_string($quote['total'] ?? null)
            || ! is_string($quote['currency'] ?? null)) {
            throw new RenewalException('price_unavailable');
        }

        if ($quote['currency'] !== $debit) {
            throw new RenewalException('balance_currency_mismatch');
        }

        return $quote['total'];
    }

    /**
     * The fresh accounting balance in the configured debit currency. The
     * balance endpoint is uncached by construction, so every call is a live
     * GET. Fails closed on any fetch, parse or currency problem.
     *
     * @throws RenewalException
     */
    private function availableBalance(string $debit): string
    {
        try {
            $balance = $this->client->getBalance();
        } catch (ResellerClubException $e) {
            throw new RenewalException($e->getMessage() === 'not_configured' ? 'not_configured' : 'balance_unavailable');
        }

        if ($balance['accounting_currency'] !== $debit) {
            throw new RenewalException('balance_currency_mismatch');
        }

        if (self::minor4($balance['accounting_balance']) === null) {
            throw new RenewalException('balance_unavailable');
        }

        return $balance['accounting_balance'];
    }

    /**
     * Plain decimal string (optionally negative, at most 4 decimals) to an
     * integer in 1e-4 units. Null when malformed. Integer arithmetic only.
     */
    private static function minor4(string $value): ?int
    {
        if (preg_match('/^(-?)([0-9]{1,15})(?:\.([0-9]{1,4}))?$/', $value, $m) !== 1) {
            return null;
        }

        $units = (int) $m[2] * 10000 + (int) str_pad($m[3] ?? '', 4, '0');

        return $m[1] === '-' ? -$units : $units;
    }
}
