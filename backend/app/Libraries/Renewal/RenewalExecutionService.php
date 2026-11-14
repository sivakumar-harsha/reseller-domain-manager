<?php

declare(strict_types=1);

namespace App\Libraries\Renewal;

use App\Libraries\Pricing\RenewalQuoteService;
use App\Libraries\ResellerClub\RenewalResponse;
use App\Libraries\ResellerClub\ResellerClubClient;
use App\Libraries\ResellerClub\ResellerClubException;
use Closure;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Exceptions\DatabaseException;
use Config\Database;
use Config\ResellerClub as ResellerClubConfig;
use DateTimeImmutable;
use DateTimeZone;
use Throwable;

/**
 * Executes domain renewals one item at a time through renewal_batches and
 * renewal_items. The only route that reaches it is the manual, confirmed
 * `POST renewal-batches/{id}/items/{id}/renew` through RenewalExecutionGateway,
 * and execution is OFF by default (Config\ResellerClub::$renewalExecutionEnabled).
 *
 * Item states: pending -> submitting -> succeeded | failed | uncertain.
 *
 * - createBatch() validates and stores a batch (no provider call, allowed while
 *   execution is disabled). It is idempotent per (user, idempotency key).
 * - processItem() claims a pending item with one atomic conditional UPDATE
 *   (pending -> submitting), so a double tap, a repeated request or a second
 *   worker can never send it twice. Everything before the POST is a read or a
 *   local check, and an item that fails there is marked failed (nothing sent).
 * - The expiry sent to the provider is read fresh from the provider
 *   (domains/details.json) and must equal the snapshot stored with the batch,
 *   otherwise the item fails with expiry_changed and a new quote is required.
 *   Nothing the mobile app supplies is ever used as an expiry.
 * - No database transaction is open during a network request. The POST is sent
 *   at most once; a timeout or any unclear answer is recorded as `uncertain`,
 *   and an item that is not `pending` is never submitted again.
 * - Items are independent: one item's failure never touches another item's
 *   stored result, and the batch status is derived from the item states.
 *
 * Stored error codes are fixed strings; provider text is never stored or logged.
 */
final class RenewalExecutionService
{
    public const MAX_LINES = 50;

    /** Global single-flight MySQL named lock: at most one manual renewal runs at a time. */
    private const LOCK_NAME = 'renewal_execute';

    private const FORMAT = 'Y-m-d H:i:s';

    /** The only provider `currentstatus` value that allows a renewal (exact match). */
    private const ACTIVE_STATUS = 'Active';

    /** Plausible range for an expiry in epoch seconds (2000-01-01 .. 2100-01-01); same bounds as the client. */
    private const MIN_EPOCH = 946684800;
    private const MAX_EPOCH = 4102444800;

    /**
     * The provider's boolean preference as a PHP bool, or null when it is not a
     * representation we recognise. Verified: JSON `false` (details `recurring`,
     * search `orders.autorenew`). The literal strings "true"/"false" are the
     * same value serialised as text. Anything else (1, 0, "yes", "", null, arrays)
     * is NOT accepted and is never defaulted.
     */
    public static function providerBool(mixed $value): ?bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_string($value)) {
            return match ($value) {
                'true'  => true,
                'false' => false,
                default => null,
            };
        }

        return null;
    }

    private BaseConnection $db;

    /** @var Closure(): DateTimeImmutable */
    private Closure $clock;

    /**
     * @param (callable(): DateTimeImmutable)|null $clock
     * @param RenewalPreflightService|null         $preflight built lazily from the same client/config/db/clock when omitted
     */
    public function __construct(
        private readonly ResellerClubClient $client,
        private readonly ResellerClubConfig $config,
        ?BaseConnection $db = null,
        ?callable $clock = null,
        private ?RenewalPreflightService $preflight = null,
    ) {
        $this->db    = $db ?? Database::connect();
        $this->clock = Closure::fromCallable($clock ?? static fn (): DateTimeImmutable => new DateTimeImmutable('now', new DateTimeZone('UTC')));
    }

    /**
     * Batch status from the item state counts. Total over the five item states.
     *
     * @param array<string, int> $counts keys among pending, submitting, succeeded, failed, uncertain
     */
    public static function batchStatus(array $counts): string
    {
        $n = static fn (string $s): int => max(0, (int) ($counts[$s] ?? 0));

        $pending = $n('pending');
        $flight  = $n('submitting');
        $done    = $n('succeeded');
        $failed  = $n('failed');
        $unsure  = $n('uncertain');

        if ($pending + $flight + $done + $failed + $unsure === 0) {
            return 'pending';
        }

        if ($pending > 0 || $flight > 0) {
            return ($flight + $done + $failed + $unsure) > 0 ? 'processing' : 'pending';
        }

        if ($unsure > 0) {
            return 'needs_review';
        }

        if ($failed === 0) {
            return 'completed';
        }

        return $done === 0 ? 'failed' : 'partial';
    }

    /**
     * Validates and stores a batch. $lines are the server-side quote snapshots:
     * each `domain_id` plus decimal-string `renewal_amount`, `gst_amount`,
     * `vat_amount`, `total_amount` and a 3-letter `currency`. The order id and
     * the expiry snapshot are taken from the stored domain row, never from the
     * caller. A repeat of the same (user, key, content) returns the stored
     * batch; the same key with different content is refused.
     *
     * @param list<array<string, mixed>> $lines
     *
     * @return array{batch_id: int, replayed: bool}
     *
     * @throws RenewalException
     */
    public function createBatch(int $userId, string $idempotencyKey, string $invoiceOption, int $years, array $lines): array
    {
        if (preg_match('/^[A-Za-z0-9_-]{16,64}$/', $idempotencyKey) !== 1) {
            throw new RenewalException('invalid_idempotency_key');
        }

        if (! in_array($invoiceOption, ResellerClubClient::INVOICE_OPTIONS, true)) {
            throw new RenewalException('invalid_invoice_option');
        }

        if (! RenewalQuoteService::validYears($years)) {
            throw new RenewalException('invalid_years');
        }

        if ($lines === [] || count($lines) > self::MAX_LINES) {
            throw new RenewalException('invalid_batch_size');
        }

        try {
            $user = $this->db->table('users')->select('id')->where('id', $userId)->where('is_active', 1)->get()->getRowArray();
        } catch (Throwable $e) {
            throw $this->storage('load_user', $e);
        }

        if ($user === null) {
            throw new RenewalException('invalid_user');
        }

        $domainIds = [];

        foreach ($lines as $line) {
            $id = $line['domain_id'] ?? null;

            if (! is_int($id) || $id < 1) {
                throw new RenewalException('invalid_line');
            }

            if (in_array($id, $domainIds, true)) {
                throw new RenewalException('duplicate_domain');
            }

            $domainIds[] = $id;
        }

        $replay = $this->findReplay($userId, $idempotencyKey, $invoiceOption, $years, $domainIds);

        if ($replay !== null) {
            return ['batch_id' => $replay, 'replayed' => true];
        }

        $planned = [];

        foreach ($lines as $line) {
            $planned[] = $this->planLine($line);
        }

        // Only now (the request is valid and is not a replay): free the slot of any stale, never-submitted
        // item for these domains, so an abandoned batch cannot block a fresh one. Anything else still blocks.
        $this->releaseStaleUnsubmitted($domainIds);

        $now   = $this->now();
        $errno = 0;

        // Inside a manual transaction CodeIgniter does NOT throw on a failed
        // query: it returns false and marks the transaction failed. So every
        // write is checked, and any failure rolls the whole batch back.
        // transBegin() does not clear an earlier failure flag, so reset it first.
        $this->db->resetTransStatus();
        $this->db->transBegin();

        try {
            $batchId = $this->insertRow('renewal_batches', [
                'user_id'         => $userId,
                'idempotency_key' => $idempotencyKey,
                'invoice_option'  => $invoiceOption,
                'years'           => $years,
                'status'          => 'pending',
                'created_at'      => $now,
                'updated_at'      => $now,
            ], $errno);

            foreach ($planned as $row) {
                $this->insertRow('renewal_items', $row + [
                    'batch_id'   => $batchId,
                    'state'      => 'pending',
                    // Provider success never confirms a debit; only the manual verification action does.
                    'debit_verification_status' => 'unverified',
                    'created_at' => $now,
                    'updated_at' => $now,
                ], $errno);
            }

            if ($this->db->transStatus() === false) {
                throw new RenewalException('storage_error');
            }

            $this->db->transCommit();
        } catch (Throwable $e) {
            $this->db->transRollback();
            $this->db->resetTransStatus();

            if ($e instanceof DatabaseException) {
                $errno = $this->hasErrno($e, 1062) ? 1062 : $errno;
            }

            // A concurrent identical submission wins the unique (user, key) race: treat it as a replay.
            $replay = $this->findReplay($userId, $idempotencyKey, $invoiceOption, $years, $domainIds);

            if ($replay !== null) {
                return ['batch_id' => $replay, 'replayed' => true];
            }

            if ($errno === 1062) {
                // Another live item already exists for one of these domain expiry cycles.
                throw new RenewalException('duplicate_cycle');
            }

            throw $this->storage('create_batch', $e);
        }

        return ['batch_id' => $batchId, 'replayed' => false];
    }

    /**
     * One INSERT whose failure is never silent. Returns the new id; on failure
     * records the database error number in $errno and throws.
     *
     * @param array<string, mixed> $row
     */
    private function insertRow(string $table, array $row, int &$errno): int
    {
        $ok = $this->db->table($table)->insert($row);

        if ($ok === false) {
            $errno = (int) ($this->db->error()['code'] ?? 0);

            throw new RenewalException('storage_error');
        }

        return (int) $this->db->insertID();
    }

    /**
     * Processes every pending item of a batch, one after another and
     * independently. Refuses when execution is disabled.
     *
     * @return array{batch_status: string, items: list<RenewalItemOutcome>}
     *
     * @throws RenewalException execution_disabled | not_found
     */
    public function processBatch(int $batchId): array
    {
        $this->assertEnabled();

        $batch = $this->db->table('renewal_batches')->select('id')->where('id', $batchId)->get()->getRowArray();

        if ($batch === null) {
            throw new RenewalException('not_found');
        }

        $ids = array_map('intval', array_column(
            $this->db->table('renewal_items')->select('id')->where('batch_id', $batchId)->where('state', 'pending')->orderBy('id', 'ASC')->get()->getResultArray(),
            'id',
        ));

        $outcomes = [];

        foreach ($ids as $itemId) {
            try {
                $outcomes[] = $this->processItem($itemId);
            } catch (Throwable $e) {
                // One item's failure must not stop or alter the others.
                log_message('error', 'Renewal item {id} processing aborted: {class}', ['id' => $itemId, 'class' => $e::class]);
            }
        }

        $status = (string) $this->db->table('renewal_batches')->select('status')->where('id', $batchId)->get()->getRowArray()['status'];

        return ['batch_status' => $status, 'items' => $outcomes];
    }

    /**
     * The ONLY user-facing execution entry point: processes AT MOST ONE
     * pending item of the user's OWN batch, after the full read-only preflight
     * (age, debit verification, fresh price, fresh balance) has passed. NO
     * ROUTE USES THIS in Phase 1 and the execution switch is still checked
     * first, before any lookup or provider call.
     *
     * Order: execution switch -> ownership (a foreign batch is
     * indistinguishable from a missing one) -> preflight (throws with every
     * item left `pending`, nothing sent) -> one processItem() with its atomic
     * claim. A preflight failure can never leave an item in `submitting`,
     * because nothing was claimed yet; failures after the claim keep their
     * existing terminal semantics, and nothing is ever retried or resubmitted.
     *
     * @return array{batch_status: string, items: list<RenewalItemOutcome>, preflight: array<string, mixed>}
     *
     * @throws RenewalException execution_disabled | not_found | the preflight codes
     */
    public function executeForUser(int $userId, int $batchId, int $items = 1): array
    {
        $this->assertEnabled();

        if (! (new RenewalBatchReader($this->db))->ownedBy($userId, $batchId)) {
            throw new RenewalException('not_found');
        }

        $check = $this->preflight()->check($batchId, $items);

        $outcomes = [];

        if ($check['item_id'] !== null) {
            $outcomes[] = $this->processItem((int) $check['item_id']);
        }

        return ['batch_status' => $this->currentBatchStatus($batchId), 'items' => $outcomes, 'preflight' => $check];
    }

    /**
     * Phase 2 manual execution of ONE explicitly named item of the user's OWN
     * batch (the next pending item is never substituted).
     *
     * Order, each step failing closed with a fixed code and nothing sent:
     *  1. execution switch            -> execution_disabled
     *  2. batch ownership             -> not_found (same as a missing batch)
     *  3. item belongs to the batch   -> not_found
     *  4. stateless confirmation of item, domain, estimated total, currency,
     *     years and invoice option against the STORED snapshot
     *                                  -> confirmation_mismatch
     *  5. item not pending            -> returned as skipped, NO lock, NO provider call
     *  6. single-flight lock (MySQL GET_LOCK on THIS connection)
     *                                  -> execution_in_progress
     *  7. read-only preflight for that item (debit verified, age, fresh price,
     *     fresh balance)               -> the preflight codes, item stays pending
     *  8. the lock is verified to still be held by this connection
     *  9. processItem(): atomic claim, fresh provider verification, ONE POST
     * The lock is released in `finally`, on every exit path. Nothing here
     * retries or resubmits: an item that is not pending is never sent.
     *
     * @param array{domain_name: string, total_amount: string, currency: string, years: int, invoice_option: string} $confirmation
     *
     * @return array{batch_status: string, item: RenewalItemOutcome, preflight: array<string, mixed>|null}
     *
     * @throws RenewalException
     */
    public function executeItemForUser(int $userId, int $batchId, int $itemId, array $confirmation, int $items = 1): array
    {
        $this->assertEnabled();

        if (! (new RenewalBatchReader($this->db))->ownedBy($userId, $batchId)) {
            throw new RenewalException('not_found');
        }

        $row = $this->db->query(
            'SELECT i.id, i.state, i.total_amount, i.currency, d.domain_name, b.years, b.invoice_option '
            . 'FROM renewal_items i JOIN renewal_batches b ON b.id = i.batch_id JOIN domains d ON d.id = i.domain_id '
            . 'WHERE i.batch_id = ? AND i.id = ?',
            [$batchId, $itemId],
        )->getRowArray();

        if ($row === null) {
            throw new RenewalException('not_found');
        }

        if (($confirmation['domain_name'] ?? null) !== (string) $row['domain_name']
            || ($confirmation['total_amount'] ?? null) !== (string) $row['total_amount']
            || ($confirmation['currency'] ?? null) !== (string) $row['currency']
            || ($confirmation['years'] ?? null) !== (int) $row['years']
            || ($confirmation['invoice_option'] ?? null) !== (string) $row['invoice_option']) {
            throw new RenewalException('confirmation_mismatch');
        }

        if ($row['state'] !== 'pending') {
            // A repeat or a stale screen: report the stored state, send nothing.
            return ['batch_status' => $this->currentBatchStatus($batchId), 'item' => RenewalItemOutcome::skipped($itemId, (string) $row['state']), 'preflight' => null];
        }

        $connectionId = $this->acquireExecutionLock();

        try {
            $check = $this->preflight()->check($batchId, $items, $itemId);

            if ($check['item_id'] !== $itemId) {
                throw new RenewalException('item_not_pending');
            }

            // The POST must never start without this connection still holding the lock.
            $this->assertExecutionLockHeld($connectionId);

            $outcome = $this->processItem($itemId);
        } finally {
            $this->releaseExecutionLock();
        }

        return ['batch_status' => $this->currentBatchStatus($batchId), 'item' => $outcome, 'preflight' => $check];
    }

    /**
     * Non-blocking acquisition (timeout 0) on the connection used for every
     * query of this service. Returns that connection's id.
     *
     * @throws RenewalException execution_in_progress | storage_error
     */
    private function acquireExecutionLock(): int
    {
        try {
            $row = $this->db->query('SELECT GET_LOCK(?, 0) AS got, CONNECTION_ID() AS cid', [self::LOCK_NAME])->getRowArray();
        } catch (Throwable $e) {
            throw $this->storage('acquire_lock', $e);
        }

        // GET_LOCK returns 1 (acquired), 0 (held elsewhere) or NULL (error): only 1 proceeds.
        if ((int) ($row['got'] ?? 0) !== 1) {
            throw new RenewalException('execution_in_progress');
        }

        return (int) $row['cid'];
    }

    /** @throws RenewalException execution_in_progress when this connection no longer holds the lock */
    private function assertExecutionLockHeld(int $connectionId): void
    {
        try {
            $row = $this->db->query('SELECT IS_USED_LOCK(?) AS holder, CONNECTION_ID() AS cid', [self::LOCK_NAME])->getRowArray();
        } catch (Throwable $e) {
            throw $this->storage('verify_lock', $e);
        }

        if ((int) ($row['holder'] ?? 0) !== $connectionId || (int) ($row['cid'] ?? 0) !== $connectionId) {
            log_message('error', 'Renewal execution lock is no longer held by this connection; refusing to send.');

            throw new RenewalException('execution_in_progress');
        }
    }

    /** Never throws: it runs in `finally` and must not mask the real outcome. */
    private function releaseExecutionLock(): void
    {
        try {
            $row = $this->db->query('SELECT RELEASE_LOCK(?) AS released', [self::LOCK_NAME])->getRowArray();

            if ((int) ($row['released'] ?? 0) !== 1) {
                log_message('error', 'Renewal execution lock was not released by this connection.');
            }
        } catch (Throwable $e) {
            // The lock is connection-scoped: it is dropped when the connection closes.
            log_message('error', 'Renewal execution lock release failed: {class}', ['class' => $e::class]);
        }
    }

    private function preflight(): RenewalPreflightService
    {
        return $this->preflight ??= new RenewalPreflightService(
            $this->client,
            $this->config,
            new RenewalQuoteService($this->client, $this->config),
            $this->db,
            $this->clock,
        );
    }

    private function currentBatchStatus(int $batchId): string
    {
        return (string) ($this->db->table('renewal_batches')->select('status')->where('id', $batchId)->get()->getRowArray()['status'] ?? 'unknown');
    }

    /**
     * Claims and processes one pending item. An item that is not pending is
     * skipped without any provider request.
     *
     * @throws RenewalException execution_disabled | not_found
     */
    public function processItem(int $itemId): RenewalItemOutcome
    {
        $this->assertEnabled();

        $row = $this->loadItem($itemId);

        if ($row === null) {
            throw new RenewalException('not_found');
        }

        if ($row['state'] !== 'pending') {
            return RenewalItemOutcome::skipped($itemId, (string) $row['state']);
        }

        // The only gate to the provider: one atomic conditional UPDATE. Two
        // simultaneous callers cannot both change the row from pending.
        $this->db->table('renewal_items')
            ->where('id', $itemId)
            ->where('state', 'pending')
            ->update(['state' => 'submitting', 'updated_at' => $this->now()]);

        if ($this->db->affectedRows() !== 1) {
            $current = $this->db->table('renewal_items')->select('state')->where('id', $itemId)->get()->getRowArray();

            return RenewalItemOutcome::skipped($itemId, (string) ($current['state'] ?? 'unknown'));
        }

        $this->refreshBatch((int) $row['batch_id']);

        try {
            return $this->runClaimed($itemId, $row);
        } catch (Throwable $e) {
            // Unexpected failure after the claim: the item stays `submitting`, which
            // is never submitted again and is resolved by reconciliation.
            log_message('error', 'Renewal item {id} left in submitting after: {class}', ['id' => $itemId, 'class' => $e::class]);

            return RenewalItemOutcome::decided($itemId, 'submitting', 'internal_error');
        }
    }

    /**
     * @param array<string, mixed> $row
     */
    private function runClaimed(int $itemId, array $row): RenewalItemOutcome
    {
        $batchId     = (int) $row['batch_id'];
        $orderId     = (string) $row['rc_order_id'];
        $invoice     = (string) $row['invoice_option'];
        $years       = (int) $row['years'];
        $customerId  = $row['rc_customer_id'] === null ? '' : (string) $row['rc_customer_id'];
        $reject      = fn (string $code): RenewalItemOutcome => $this->finish($itemId, $batchId, RenewalResponse::failed($code));

        // Local checks: nothing has been sent yet.
        if (! in_array($invoice, ResellerClubClient::INVOICE_OPTIONS, true)) {
            return $reject('invalid_invoice_option');
        }

        if (! RenewalQuoteService::validYears($years)) {
            return $reject('invalid_years');
        }

        if ((int) $row['is_missing'] === 1) {
            return $reject('domain_missing');
        }

        if (preg_match('/^[1-9][0-9]{0,11}$/', $orderId) !== 1) {
            return $reject('invalid_order_id');
        }

        if ((string) $row['domain_order_id'] !== $orderId) {
            return $reject('order_changed');
        }

        if ($customerId === '') {
            return $reject('customer_unknown');
        }

        // Fresh provider verification (a GET). Everything below fails closed:
        // a missing, malformed or unexpected value rejects the item and nothing is sent.
        try {
            $details = $this->client->domainDetails($orderId);
        } catch (ResellerClubException $e) {
            return $reject($e->getMessage() === 'not_configured' ? 'not_configured' : 'provider_unavailable');
        }

        // Verified shape: `currentstatus` is the exact string "Active" for a renewable domain.
        $currentStatus = $details['currentstatus'] ?? null;

        if (! is_string($currentStatus)) {
            return $reject('status_unverified');
        }

        if ($currentStatus !== self::ACTIVE_STATUS) {
            return $reject('domain_not_active');
        }

        if (! is_scalar($details['orderid'] ?? null) || (string) $details['orderid'] !== $orderId) {
            return $reject('order_mismatch');
        }

        if (! is_scalar($details['customerid'] ?? null) || (string) $details['customerid'] !== $customerId) {
            return $reject('customer_mismatch');
        }

        // `endtime` is a Unix timestamp in SECONDS (verified: 1794489745). Digits only,
        // and inside a plausible range, which also rejects millisecond values.
        $endtime = $details['endtime'] ?? null;

        if ((! is_string($endtime) && ! is_int($endtime))
            || preg_match('/^[0-9]{9,11}$/', (string) $endtime) !== 1
            || (int) $endtime < self::MIN_EPOCH || (int) $endtime > self::MAX_EPOCH) {
            return $reject('expiry_unverified');
        }

        // The auto-renew value sent to the provider is the provider's own fresh
        // `recurring` value, never the cached local column, so the customer's
        // existing preference is passed through unchanged.
        $autoRenew = self::providerBool($details['recurring'] ?? null);

        if ($autoRenew === null) {
            return $reject('auto_renew_unknown');
        }

        $providerEpoch = (int) $endtime;
        $snapshotEpoch = (new DateTimeImmutable((string) $row['expiry_at_request'], new DateTimeZone('UTC')))->getTimestamp();

        if ($providerEpoch !== $snapshotEpoch) {
            // The expiry moved since the quote: a new quote and confirmation are required.
            return $reject('expiry_changed');
        }

        // The same rule as the quote and batch creation, on the provider's fresh status and expiry.
        // An expiry in the past is NOT refused here: the request, carrying the provider's own current
        // `endtime` as exp-date, is sent and ResellerClub decides.
        $eligibility = RenewalEligibility::evaluate($currentStatus, $providerEpoch, ($this->clock)()->getTimestamp());

        if (! $eligibility['eligible']) {
            return $reject((string) $eligibility['reason']);
        }

        // The single provider POST. No transaction is open and nothing retries it.
        try {
            $response = $this->client->renewDomain($orderId, $years, $providerEpoch, $autoRenew, $invoice);
        } catch (ResellerClubException $e) {
            // Thrown only before anything is sent.
            return $reject(in_array($e->getMessage(), ['execution_disabled', 'not_configured', 'invalid_renewal_request'], true) ? $e->getMessage() : 'invalid_renewal_request');
        }

        return $this->finish($itemId, $batchId, $response);
    }

    /**
     * Records the result of a claimed item. The UPDATE only applies while the
     * item is still `submitting`.
     */
    private function finish(int $itemId, int $batchId, RenewalResponse $response): RenewalItemOutcome
    {
        $now   = $this->now();
        $state = $response->outcome;

        try {
            $this->db->table('renewal_items')->where('id', $itemId)->where('state', 'submitting')->update([
                'state'               => $state,
                'provider_action_id'  => $response->actionId,
                'provider_invoice_id' => $response->invoiceId,
                'error_code'          => $response->errorCode,
                'provider_message'    => $response->actionDescription,
                'updated_at'          => $now,
                'completed_at'        => $state === RenewalResponse::UNCERTAIN ? null : $now,
            ]);
            $recorded = $this->db->affectedRows() === 1;
        } catch (Throwable $e) {
            log_message('error', 'Renewal item {id} result not recorded: {class}', ['id' => $itemId, 'class' => $e::class]);
            $recorded = false;
        }

        if (! $recorded) {
            // The request may already have been sent: never report this as a clean result.
            return RenewalItemOutcome::decided($itemId, 'submitting', 'record_failed', $response->actionId, $response->invoiceId);
        }

        log_message('info', 'Renewal item {id} finished: {state} {code}', ['id' => $itemId, 'state' => $state, 'code' => $response->errorCode ?? 'none']);
        $this->refreshBatch($batchId);

        return RenewalItemOutcome::decided($itemId, $state, $response->errorCode, $response->actionId, $response->invoiceId);
    }

    /** Derives the batch status from its items in one short transaction (no network inside). */
    private function refreshBatch(int $batchId): void
    {
        (new RenewalBatchRefresher($this->db, $this->clock))->refresh($batchId);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function loadItem(int $itemId): ?array
    {
        return $this->db->query(
            'SELECT i.id, i.batch_id, i.rc_order_id, i.expiry_at_request, i.state, b.invoice_option, b.years, '
            . 'd.rc_order_id AS domain_order_id, d.rc_customer_id, d.is_missing, d.domain_name '
            . 'FROM renewal_items i JOIN renewal_batches b ON b.id = i.batch_id JOIN domains d ON d.id = i.domain_id WHERE i.id = ?',
            [$itemId],
        )->getRowArray();
    }

    /**
     * SQL conditions (aliases i = renewal_items, b = renewal_batches) that identify an item which was
     * provably NEVER submitted and whose batch is older than the execution age limit. ALL must hold:
     * pending, no provider action/invoice id, no debit or verification evidence, batch past its age.
     * One definition shared by the release and the active-batch lookup so they can never disagree.
     */
    private const STALE_UNSUBMITTED_SQL = "i.state = 'pending' AND i.provider_action_id IS NULL AND i.provider_invoice_id IS NULL "
        . "AND i.debit_verification_status = 'unverified' AND i.debit_transaction_id IS NULL AND i.debit_verified_at IS NULL "
        . 'AND i.debit_verified_amount IS NULL AND i.debit_verified_currency IS NULL AND i.debit_verification_note IS NULL '
        . 'AND NOT EXISTS (SELECT 1 FROM renewal_debit_verifications v WHERE v.renewal_item_id = i.id) '
        . 'AND b.created_at < ?';

    /** UTC instant before which a batch is past renewalBatchMaxAgeSeconds (the same policy as preflight). */
    private function staleCutoff(): string
    {
        return ($this->clock)()->setTimezone(new DateTimeZone('UTC'))
            ->modify('-' . max(0, $this->config->renewalBatchMaxAgeSeconds) . ' seconds')->format(self::FORMAT);
    }

    /**
     * Frees the duplicate-protection slot of stale, never-submitted pending items for the given domains by
     * marking them `failed` / `stale_unsubmitted` (rows and batches are kept for audit). Only items that
     * satisfy EVERY condition of STALE_UNSUBMITTED_SQL change; `submitting`, `uncertain` and `succeeded`
     * items, anything with provider or debit evidence, and fresh batches are never touched. Each item is
     * one conditional UPDATE, so a concurrent claim (pending -> submitting) and this release cannot both
     * win. No provider call. Returns the number of items released.
     *
     * @param list<int> $domainIds
     *
     * @throws RenewalException
     */
    public function releaseStaleUnsubmitted(array $domainIds): int
    {
        $domainIds = array_values(array_unique(array_filter($domainIds, static fn ($id): bool => is_int($id) && $id > 0)));

        if ($domainIds === []) {
            return 0;
        }

        $marks  = implode(',', array_fill(0, count($domainIds), '?'));
        $cutoff = $this->staleCutoff();

        try {
            $candidates = $this->db->query(
                'SELECT i.id, i.batch_id FROM renewal_items i JOIN renewal_batches b ON b.id = i.batch_id '
                . "WHERE i.domain_id IN ($marks) AND " . self::STALE_UNSUBMITTED_SQL,
                [...$domainIds, $cutoff],
            )->getResultArray();

            $released = 0;
            $batches  = [];
            $now      = $this->now();

            foreach ($candidates as $row) {
                // Re-states every condition: the item may have been claimed or verified since the SELECT.
                $this->db->query(
                    "UPDATE renewal_items i JOIN renewal_batches b ON b.id = i.batch_id SET i.state = 'failed', "
                    . "i.error_code = 'stale_unsubmitted', i.updated_at = ?, i.completed_at = ? "
                    . 'WHERE i.id = ? AND ' . self::STALE_UNSUBMITTED_SQL,
                    [$now, $now, (int) $row['id'], $cutoff],
                );

                if ($this->db->affectedRows() === 1) {
                    $released++;
                    $batches[(int) $row['batch_id']] = true;
                }
            }

            foreach (array_keys($batches) as $batchId) {
                $this->refreshBatch($batchId);
            }

            if ($released > 0) {
                log_message('info', 'Released {n} stale unsubmitted renewal item(s)', ['n' => $released]);
            }

            return $released;
        } catch (Throwable $e) {
            throw $this->storage('release_stale', $e);
        }
    }

    /**
     * Read-only: for each domain, the newest of THIS user's renewal items that still holds the domain's
     * current expiry cycle (not failed, same expiry as the stored domain) and is NOT releasable as stale.
     * Returns domain id => batch id. Never exposes another user's batch.
     *
     * @param list<int> $domainIds
     *
     * @return array<int, int>
     *
     * @throws RenewalException
     */
    public function findActiveBatchIds(int $userId, array $domainIds): array
    {
        $domainIds = array_values(array_unique(array_filter($domainIds, static fn ($id): bool => is_int($id) && $id > 0)));

        if ($domainIds === []) {
            return [];
        }

        $marks = implode(',', array_fill(0, count($domainIds), '?'));

        try {
            $rows = $this->db->query(
                'SELECT i.domain_id, i.batch_id FROM renewal_items i JOIN renewal_batches b ON b.id = i.batch_id '
                . 'JOIN domains d ON d.id = i.domain_id '
                . "WHERE b.user_id = ? AND i.domain_id IN ($marks) AND i.state <> 'failed' AND i.expiry_at_request = d.expires_at "
                . 'AND NOT (' . self::STALE_UNSUBMITTED_SQL . ') ORDER BY i.id ASC',
                [$userId, ...$domainIds, $this->staleCutoff()],
            )->getResultArray();
        } catch (Throwable $e) {
            throw $this->storage('find_active_batches', $e);
        }

        $out = [];

        foreach ($rows as $row) {
            $out[(int) $row['domain_id']] = (int) $row['batch_id']; // ascending id: the newest wins
        }

        return $out;
    }

    /**
     * Read-only preliminary lookup (an optimisation only): the id of this user's existing batch for
     * the key when the content is identical, null when there is none. Different content throws
     * `idempotency_conflict`. createBatch() still re-checks inside its own unique-key/transaction path.
     *
     * @param list<int> $domainIds
     *
     * @throws RenewalException
     */
    public function findExistingBatch(int $userId, string $idempotencyKey, string $invoiceOption, int $years, array $domainIds): ?int
    {
        try {
            return $this->findReplay($userId, $idempotencyKey, $invoiceOption, $years, $domainIds);
        } catch (RenewalException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw $this->storage('find_existing_batch', $e);
        }
    }

    /**
     * @param list<int> $domainIds
     */
    private function findReplay(int $userId, string $key, string $invoiceOption, int $years, array $domainIds): ?int
    {
        $batch = $this->db->table('renewal_batches')->select('id, invoice_option, years')
            ->where('user_id', $userId)->where('idempotency_key', $key)->get()->getRowArray();

        if ($batch === null) {
            return null;
        }

        $stored = array_map('intval', array_column(
            $this->db->table('renewal_items')->select('domain_id')->where('batch_id', (int) $batch['id'])->get()->getResultArray(),
            'domain_id',
        ));

        sort($stored);
        $wanted = $domainIds;
        sort($wanted);

        if ((string) $batch['invoice_option'] !== $invoiceOption || (int) $batch['years'] !== $years || $stored !== $wanted) {
            throw new RenewalException('idempotency_conflict');
        }

        return (int) $batch['id'];
    }

    /**
     * @param array<string, mixed> $line
     *
     * @return array<string, mixed>
     */
    private function planLine(array $line): array
    {
        try {
            $domain = $this->db->table('domains')->select('id, domain_name, status, rc_order_id, rc_customer_id, expires_at, is_missing')
                ->where('id', (int) $line['domain_id'])->get()->getRowArray();
        } catch (Throwable $e) {
            throw $this->storage('load_domain', $e);
        }

        if ($domain === null) {
            throw new RenewalException('domain_not_found');
        }

        if ((int) $domain['is_missing'] === 1) {
            throw new RenewalException('domain_missing');
        }

        if (preg_match('/^[1-9][0-9]{0,11}$/', (string) $domain['rc_order_id']) !== 1) {
            throw new RenewalException('invalid_order_id');
        }

        if ($domain['rc_customer_id'] === null || (string) $domain['rc_customer_id'] === '') {
            throw new RenewalException('customer_unknown');
        }

        if ($domain['expires_at'] === null) {
            throw new RenewalException('expiry_unknown');
        }

        // Same rule as the quote and execution, on the stored inventory (the provider is re-checked at execution).
        $expiry      = (new DateTimeImmutable((string) $domain['expires_at'], new DateTimeZone('UTC')))->getTimestamp();
        $eligibility = RenewalEligibility::evaluate($domain['status'] === null ? null : (string) $domain['status'], $expiry, ($this->clock)()->getTimestamp());

        if (! $eligibility['eligible']) {
            throw new RenewalException((string) $eligibility['reason']);
        }

        $cents = [];

        foreach (['renewal_amount', 'gst_amount', 'vat_amount', 'total_amount'] as $field) {
            $value = $line[$field] ?? null;

            if (! is_string($value) || preg_match('/^[0-9]{1,10}\.[0-9]{2}$/', $value) !== 1) {
                throw new RenewalException('invalid_amount');
            }

            $cents[$field] = (int) str_replace('.', '', $value);
        }

        if ($cents['renewal_amount'] + $cents['gst_amount'] + $cents['vat_amount'] !== $cents['total_amount']) {
            throw new RenewalException('invalid_amount');
        }

        $currency = $line['currency'] ?? null;

        if (! is_string($currency) || preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
            throw new RenewalException('invalid_currency');
        }

        return [
            'domain_id'         => (int) $domain['id'],
            'rc_order_id'       => (string) $domain['rc_order_id'],
            'expiry_at_request' => (string) $domain['expires_at'],
            'renewal_amount'    => $line['renewal_amount'],
            'gst_amount'        => $line['gst_amount'],
            'vat_amount'        => $line['vat_amount'],
            'total_amount'      => $line['total_amount'],
            'currency'          => $currency,
        ];
    }

    private function assertEnabled(): void
    {
        if (! $this->config->renewalExecutionEnabled) {
            throw new RenewalException('execution_disabled');
        }
    }

    private function hasErrno(Throwable $e, int $errno): bool
    {
        for ($t = $e; $t !== null; $t = $t->getPrevious()) {
            if ($t->getCode() === $errno) {
                return true;
            }
        }

        return false;
    }

    private function storage(string $where, Throwable $e): RenewalException
    {
        log_message('error', 'Renewal storage failure ({where}): {class}', ['where' => $where, 'class' => $e::class]);

        return new RenewalException('storage_error');
    }

    private function now(): string
    {
        return ($this->clock)()->setTimezone(new DateTimeZone('UTC'))->format(self::FORMAT);
    }
}
