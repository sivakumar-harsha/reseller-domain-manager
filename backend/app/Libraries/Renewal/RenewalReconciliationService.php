<?php

declare(strict_types=1);

namespace App\Libraries\Renewal;

use App\Libraries\ResellerClub\ResellerClubClient;
use App\Libraries\ResellerClub\ResellerClubException;
use Closure;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use Config\ResellerClub as ResellerClubConfig;
use DateTimeImmutable;
use DateTimeZone;
use Throwable;

/**
 * READ-ONLY (towards ResellerClub) reconciliation of renewal items that are
 * `submitting` or `uncertain`. NO ROUTE USES THIS YET.
 *
 * It only ever issues GET requests (actions/search-current.json, then
 * actions/search-archived.json) and has no way to call domains/renew.json: the
 * renewal method is never referenced here. It does not depend on the execution
 * switch because it spends nothing.
 *
 * Rules:
 * - The only automatic state change that resolves an item is
 *   submitting|uncertain -> `succeeded`, and only when the provider returned the
 *   action with the stored action id, for THIS order, of type `RenewDomain`, with
 *   the exact status `Success`. HTTP 200 or a found record alone prove nothing.
 * - An item is never moved to `failed` and never back to `pending`. Releasing the
 *   duplicate-cycle guard (UNIQUE domain/active expiry, freed only by `failed`)
 *   needs verified failure evidence that does not exist yet, so unresolved items
 *   keep their slot and need manual review.
 * - Unknown, missing or malformed statuses stay `uncertain`. "Not found" is never
 *   evidence: neither action list is documented as complete history, and a
 *   lookup error says nothing about the renewal.
 * - Without a stored action id nothing can be matched safely (an older renewal of
 *   the same order could be mistaken for this one), so the item stays unresolved.
 *   A `submitting` item older than the configured stuck threshold is moved to
 *   `uncertain` (code `stuck_submitting`) so the batch shows `needs_review`;
 *   a younger one may still be in flight and is left untouched. This also covers
 *   the case where the result could not be recorded after the POST (the
 *   execution service's "record_failed" outcome leaves the item `submitting`).
 * - Every write is one conditional UPDATE guarded by the expected current state,
 *   and an unchanged item is not written at all, so repeating a run is harmless
 *   and a concurrent run cannot overwrite another one.
 * - Only the item being reconciled is ever touched.
 *
 * The action record fields used for matching are verified against a real
 * archived-actions response: `eaqid` (action id), `actiontype` (RenewDomain),
 * `entityid` (the domain's order id) and `actionstatus`. A record that lacks the
 * type or order field, or differs, is unresolved (`action_mismatch`), never succeeded.
 * (The action id REQUEST parameter is `eaq-id`; the RESPONSE field is `eaqid`.)
 */
final class RenewalReconciliationService
{
    public const ACTION_TYPE        = 'RenewDomain';
    public const ACTION_TYPE_FIELD  = 'actiontype';
    public const ACTION_ORDER_FIELD = 'entityid';

    private const FORMAT = 'Y-m-d H:i:s';

    private BaseConnection $db;

    /** @var Closure(): DateTimeImmutable */
    private Closure $clock;

    /**
     * @param (callable(): DateTimeImmutable)|null $clock
     */
    public function __construct(
        private readonly ResellerClubClient $client,
        private readonly ResellerClubConfig $config,
        ?BaseConnection $db = null,
        ?callable $clock = null,
    ) {
        $this->db    = $db ?? Database::connect();
        $this->clock = Closure::fromCallable($clock ?? static fn (): DateTimeImmutable => new DateTimeImmutable('now', new DateTimeZone('UTC')));
    }

    /**
     * Reconciles every `submitting` / `uncertain` item of a batch, one by one.
     *
     * @return array{batch_status: string, items: list<ReconciliationOutcome>}
     *
     * @throws RenewalException not_found
     */
    public function reconcileBatch(int $batchId): array
    {
        if ($this->db->table('renewal_batches')->select('id')->where('id', $batchId)->get()->getRowArray() === null) {
            throw new RenewalException('not_found');
        }

        $ids = array_map('intval', array_column(
            $this->db->table('renewal_items')->select('id')->where('batch_id', $batchId)->whereIn('state', ['submitting', 'uncertain'])->orderBy('id', 'ASC')->get()->getResultArray(),
            'id',
        ));

        $outcomes = [];

        foreach ($ids as $itemId) {
            try {
                $outcomes[] = $this->reconcileItem($itemId);
            } catch (Throwable $e) {
                log_message('error', 'Renewal item {id} reconciliation aborted: {class}', ['id' => $itemId, 'class' => $e::class]);
            }
        }

        $status = (string) $this->db->table('renewal_batches')->select('status')->where('id', $batchId)->get()->getRowArray()['status'];

        return ['batch_status' => $status, 'items' => $outcomes];
    }

    /**
     * @throws RenewalException not_found
     */
    public function reconcileItem(int $itemId): ReconciliationOutcome
    {
        $item = $this->db->table('renewal_items')
            ->select('id, batch_id, rc_order_id, state, provider_action_id, error_code, updated_at')
            ->where('id', $itemId)->get()->getRowArray();

        if ($item === null) {
            throw new RenewalException('not_found');
        }

        $state = (string) $item['state'];

        if ($state !== 'submitting' && $state !== 'uncertain') {
            return new ReconciliationOutcome($itemId, $state, $state, 'skipped', 'not_reconcilable', false);
        }

        $actionId = $item['provider_action_id'] === null ? '' : (string) $item['provider_action_id'];

        if (preg_match('/^[0-9]{1,20}$/', $actionId) !== 1) {
            return $this->withoutActionId($item);
        }

        $found = $this->findAction($actionId, (string) $item['rc_order_id']);

        if ($found['kind'] === 'succeeded') {
            return $this->markSucceeded($item, $actionId, $found['status'], $found['description']);
        }

        return $this->markUnresolved($item, $found['reason'], $actionId, $found['status'] ?? null, $found['description'] ?? null);
    }

    /**
     * No action id was ever stored. Nothing can be matched, and nothing may be assumed.
     *
     * @param array<string, mixed> $item
     */
    private function withoutActionId(array $item): ReconciliationOutcome
    {
        $itemId = (int) $item['id'];
        $state  = (string) $item['state'];

        if ($state === 'uncertain') {
            return new ReconciliationOutcome($itemId, $state, $state, 'unresolved', 'no_action_id', false);
        }

        $cutoff = ($this->clock)()->setTimezone(new DateTimeZone('UTC'))->modify('-' . max(0, $this->config->renewalStuckAfterSeconds) . ' seconds')->format(self::FORMAT);

        if ((string) $item['updated_at'] > $cutoff) {
            // May still be in flight; leave it alone.
            return new ReconciliationOutcome($itemId, $state, $state, 'skipped', 'in_flight', false);
        }

        // Stuck: the request may or may not have been sent. Never assume it was not.
        $changed = $this->guardedUpdate($itemId, ['submitting'], ['state' => 'uncertain', 'error_code' => 'stuck_submitting'], $cutoff);
        $current = $this->currentState($itemId);

        if ($changed) {
            $this->refresh((int) $item['batch_id']);
        }

        return new ReconciliationOutcome($itemId, $state, $current, 'unresolved', 'no_action_id', $changed);
    }

    /**
     * @param array<string, mixed> $item
     */
    private function markSucceeded(array $item, string $actionId, ?string $status, ?string $description): ReconciliationOutcome
    {
        $itemId = (int) $item['id'];
        $now    = $this->now();

        $changed = $this->guardedUpdate($itemId, ['submitting', 'uncertain'], [
            // Provider success only: debit_verification_status is deliberately NOT written here.
            'state'              => 'succeeded',
            'provider_action_id' => $actionId,
            'error_code'         => null,
            'completed_at'       => $now,
        ]);

        if (! $changed) {
            // Another run or the executor moved it first: report what is there, change nothing.
            $current = $this->currentState($itemId);

            return new ReconciliationOutcome($itemId, (string) $item['state'], $current, $current === 'succeeded' ? 'succeeded' : 'skipped', 'already_resolved', false, $actionId, $status, $description);
        }

        $this->refresh((int) $item['batch_id']);

        return new ReconciliationOutcome($itemId, (string) $item['state'], 'succeeded', 'succeeded', 'provider_confirmed', true, $actionId, $status, $description);
    }

    /**
     * The provider could not confirm the renewal. The item keeps its duplicate
     * protection; a `submitting` item with a known action id becomes `uncertain`
     * (the request was certainly sent), and the stored reason is refreshed only
     * when it differs.
     *
     * @param array<string, mixed> $item
     */
    private function markUnresolved(array $item, string $reason, string $actionId, ?string $status, ?string $description): ReconciliationOutcome
    {
        $itemId   = (int) $item['id'];
        $previous = (string) $item['state'];
        $changed  = false;

        // Only reasons that are about THIS action may be stored; lookup problems leave the item as it is.
        $storable = in_array($reason, ['status_unrecognised', 'status_missing', 'action_mismatch', 'ambiguous_action'], true);

        if ($previous === 'submitting') {
            $changed = $this->guardedUpdate($itemId, ['submitting'], ['state' => 'uncertain', 'error_code' => $storable ? $reason : 'unconfirmed']);
        } elseif ($storable && (string) ($item['error_code'] ?? '') !== $reason) {
            $changed = $this->guardedUpdate($itemId, ['uncertain'], ['error_code' => $reason]);
        }

        if ($changed) {
            $this->refresh((int) $item['batch_id']);
        }

        return new ReconciliationOutcome($itemId, $previous, $this->currentState($itemId), 'unresolved', $reason, $changed, $actionId, $status, $description);
    }

    /**
     * Looks the action up in the current list, then the archived list.
     *
     * @return array{kind: 'succeeded', status: string, description: ?string}|array{kind: 'unresolved', reason: string, status?: ?string, description?: ?string}
     */
    private function findAction(string $actionId, string $orderId): array
    {
        $lookupFailed = false;

        foreach ([[$this->client, 'searchCurrentActions'], [$this->client, 'searchArchivedActions']] as $lookup) {
            try {
                $records = $lookup($actionId);
            } catch (ResellerClubException) {
                // An error (including "nothing found" style errors) is not evidence either way.
                $lookupFailed = true;

                continue;
            }

            $matches = array_values(array_filter($records, static fn ($r): bool => is_array($r) && is_scalar($r['eaqid'] ?? null) && (string) $r['eaqid'] === $actionId));

            if ($matches === []) {
                continue;
            }

            if (count($matches) > 1) {
                return ['kind' => 'unresolved', 'reason' => 'ambiguous_action'];
            }

            return $this->classify($matches[0], $orderId);
        }

        return ['kind' => 'unresolved', 'reason' => $lookupFailed ? 'lookup_failed' : 'action_not_found'];
    }

    /**
     * @param array<string, mixed> $record
     *
     * @return array{kind: 'succeeded', status: string, description: ?string}|array{kind: 'unresolved', reason: string, status?: ?string, description?: ?string}
     */
    private function classify(array $record, string $orderId): array
    {
        $type  = $record[self::ACTION_TYPE_FIELD] ?? null;
        $order = $record[self::ACTION_ORDER_FIELD] ?? null;

        if (! is_string($type) || $type !== self::ACTION_TYPE || ! is_scalar($order) || (string) $order !== $orderId) {
            // Wrong type, wrong order, or the fields needed to prove either are absent.
            return ['kind' => 'unresolved', 'reason' => 'action_mismatch'];
        }

        $status      = $this->token($record['actionstatus'] ?? null);
        $description = $this->text($record['actionstatusdesc'] ?? null);

        if ($status === null) {
            return ['kind' => 'unresolved', 'reason' => 'status_missing', 'description' => $description];
        }

        if (in_array($status, $this->config->renewalSuccessStatuses, true)) {
            return ['kind' => 'succeeded', 'status' => $status, 'description' => $description];
        }

        return ['kind' => 'unresolved', 'reason' => 'status_unrecognised', 'status' => $status, 'description' => $description];
    }

    /**
     * One UPDATE that applies only while the item is still in one of $states
     * (and, for the stuck rule, has not been touched since $notUpdatedAfter).
     *
     * @param list<string>         $states
     * @param array<string, mixed> $set
     */
    private function guardedUpdate(int $itemId, array $states, array $set, ?string $notUpdatedAfter = null): bool
    {
        try {
            $builder = $this->db->table('renewal_items')->where('id', $itemId)->whereIn('state', $states);

            if ($notUpdatedAfter !== null) {
                $builder->where('updated_at <=', $notUpdatedAfter);
            }

            $builder->update($set + ['updated_at' => $this->now()]);

            return $this->db->affectedRows() === 1;
        } catch (Throwable $e) {
            log_message('error', 'Renewal item {id} reconciliation not recorded: {class}', ['id' => $itemId, 'class' => $e::class]);

            return false;
        }
    }

    private function currentState(int $itemId): string
    {
        $row = $this->db->table('renewal_items')->select('state')->where('id', $itemId)->get()->getRowArray();

        return (string) ($row['state'] ?? 'unknown');
    }

    private function refresh(int $batchId): void
    {
        (new RenewalBatchRefresher($this->db, $this->clock))->refresh($batchId);
    }

    /** A short alphanumeric status word, or null (same shape rule as the renewal POST response). */
    private function token(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return preg_match('/^[A-Za-z0-9_ -]{1,32}$/', $value) === 1 ? $value : null;
    }

    /** Provider text, kept only in memory: control characters removed, length capped. */
    private function text(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $clean = trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value));

        return $clean === '' ? null : mb_substr($clean, 0, 200);
    }

    private function now(): string
    {
        return ($this->clock)()->setTimezone(new DateTimeZone('UTC'))->format(self::FORMAT);
    }
}
