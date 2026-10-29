<?php

declare(strict_types=1);

namespace App\Libraries\Sync;

use App\Libraries\Domain\DomainNameCanonicalizer;
use App\Libraries\ResellerClub\ResellerClubClient;
use App\Libraries\ResellerClub\ResellerClubException;
use CodeIgniter\Database\BaseConnection;
use Closure;
use Config\ResellerClub as ResellerClubConfig;
use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;
use Throwable;

/**
 * Read-only synchronisation of the domain inventory from ResellerClub into
 * `domains`, recorded in `sync_runs`. Nothing is ever written to ResellerClub.
 *
 * Decisions (see docs/PROJECT_PLAN.md section 4.2):
 * - Upsert key is the provider order id (`rc_order_id`). If a domain name
 *   already belongs to a different order id (in the database or earlier on the
 *   same page), the record is skipped and reported as a warning; nothing is
 *   overwritten, merged or deleted, and the rest of the page is processed. The
 *   unique `domain_name` constraint cannot keep two orders for one name.
 * - Overlap is prevented with a named database lock (GET_LOCK, available on
 *   MySQL and MariaDB). Holding the lock means no other run is live, so any
 *   `running` row found afterwards was abandoned and is marked `failed`.
 * - Counters and seen order ids only ever describe committed pages: a page that
 *   rolls back contributes nothing but one error.
 * - An unusable optional provider field never overwrites a stored value with
 *   NULL; the stored value is kept and a warning is recorded.
 * - A page that adds no new order id stops the run (incomplete).
 * - Missing-domain detection runs only after a fully successful run (every
 *   page fetched, no errors, at least one record) and is NULL-safe. It refuses
 *   to flag more than half of the known domains at once.
 * - registrar_lock is not synced: the meaning of orders.resellerlock,
 *   orders.customerlock and orders.transferlock is unverified, so none of them
 *   is mapped to any column and registrar_lock stays NULL (unknown).
 * - Domain names are stored in canonical form (lowercase ASCII, Punycode for
 *   internationalised names; see DomainNameCanonicalizer) and are never trimmed.
 *   A record with a valid order id but an unusable name is SKIPPED: it is never
 *   inserted, an existing row keeps its name and business data and is only
 *   touched for tracking (last_seen_sync_id, last_synced_at, is_missing), and its
 *   order id counts as seen. Any skipped record makes the run partial (never
 *   success), so missing-domain detection stays off. Only order ids are
 *   reported, never the raw name. sync_runs has no skipped column; the count is
 *   in error_summary and in the SyncResult.
 * - Dates arrive as UNIX timestamps and are stored as UTC.
 * - auto_renew comes from orders.autorenew ("true"/"false"). nameservers_json is
 *   not part of the search response and is left untouched here.
 * - Database failures (lock, bookkeeping writes) are logged by exception class
 *   only and reported through distinct SyncResult statuses.
 */
class DomainSyncService
{
    public const LOCK_NAME = 'rdm_domain_sync';

    private const FORMAT = 'Y-m-d H:i:s';

    /** Columns that a sync may leave unknown but must never blank out once known. */
    private const OPTIONAL_FIELDS = ['rc_customer_id', 'status', 'registered_at', 'expires_at', 'auto_renew', 'privacy_enabled', 'registrar_lock'];

    private const FLAG_FIELDS = ['auto_renew', 'privacy_enabled', 'registrar_lock'];

    /** @var Closure(): DateTimeImmutable */
    private Closure $clock;

    /** @var Closure(int): void */
    private Closure $sleep;

    /**
     * @param (callable(): DateTimeImmutable)|null $clock
     * @param (callable(int): void)|null           $sleep milliseconds
     */
    public function __construct(
        private readonly BaseConnection $db,
        private readonly ResellerClubClient $client,
        private readonly ResellerClubConfig $config,
        ?callable $clock = null,
        ?callable $sleep = null,
    ) {
        $this->clock = Closure::fromCallable($clock ?? static fn (): DateTimeImmutable => new DateTimeImmutable('now', new DateTimeZone('UTC')));
        $this->sleep = Closure::fromCallable($sleep ?? static function (int $ms): void {
            usleep($ms * 1000);
        });
    }

    /**
     * @param 'manual'|'scheduled' $trigger
     */
    public function run(string $trigger = 'manual'): SyncResult
    {
        if (! $this->client->isConfigured()) {
            return SyncResult::notRun(SyncResult::NOT_CONFIGURED);
        }

        $locked = $this->acquireLock();

        if ($locked === null) {
            return SyncResult::notRun(SyncResult::LOCK_ERROR);
        }

        if ($locked === false) {
            return SyncResult::notRun(SyncResult::ALREADY_RUNNING);
        }

        try {
            return $this->runLocked($trigger);
        } finally {
            $this->releaseLock();
        }
    }

    private function runLocked(string $trigger): SyncResult
    {
        try {
            $now = $this->now();

            // The lock is ours, so no run is live: leftovers crashed.
            $this->db->table('sync_runs')->where('status', 'running')->update([
                'status'        => 'failed',
                'finished_at'   => $now,
                'error_summary' => 'abandoned_run',
                'updated_at'    => $now,
            ]);

            $this->db->table('sync_runs')->insert([
                'trigger_type' => $trigger,
                'status'       => 'running',
                'started_at'   => $now,
                'created_at'   => $now,
                'updated_at'   => $now,
            ]);
            $runId = (int) $this->db->insertID();

            if ($runId <= 0) {
                throw new RuntimeException('no_run_id');
            }
        } catch (Throwable $e) {
            $this->logFailure('start', $e);

            return SyncResult::notRun(SyncResult::STORAGE_ERROR);
        }

        $c          = ['pages' => 0, 'seen' => 0, 'inserted' => 0, 'updated' => 0, 'unchanged' => 0, 'errors' => 0, 'warnings' => 0, 'skipped' => 0];
        $skippedIds = []; // order ids of skipped records on committed pages
        $notes      = []; // errors
        $warnings   = [];
        $seenIds  = []; // order ids of committed pages only
        $page     = 1;
        $total    = null;
        $aborted  = false;
        $flagged  = 0;

        try {
            while (true) {
                if ($page > $this->config->maxPages) {
                    $notes[] = 'max_pages_reached';
                    $aborted = true;

                    break;
                }

                try {
                    $result = $this->client->searchDomains($page, $this->config->pageSize);
                } catch (ResellerClubException $e) {
                    $c['errors']++;
                    $notes[] = "page {$page}: " . $e->getMessage();
                    $aborted = true;

                    break;
                }

                $c['pages']++;
                $total ??= $result['total'];

                // CodeIgniter does NOT throw for a failed query inside a transaction unless
                // asked to; without this a failed write would be silently skipped and the
                // rest of the page committed.
                $this->db->transException(true);
                $this->db->transBegin();

                try {
                    $p = $this->applyPage($runId, $result['records'], $seenIds);

                    if ($this->db->transStatus() === false || $this->db->transCommit() === false) {
                        throw new RuntimeException('commit_failed');
                    }
                } catch (Throwable $e) {
                    $this->db->transRollback();
                    // Class name only: database messages can contain SQL and data.
                    $c['errors']++;
                    $notes[] = "page {$page}: storage_error " . $e::class;
                    $aborted = true;

                    break;
                } finally {
                    $this->db->transException(false);
                }

                // Committed: only now do the page's results count.
                foreach ($p['newIds'] as $id) {
                    $seenIds[$id] = true;
                }

                foreach (['seen', 'inserted', 'updated', 'unchanged', 'errors', 'warnings', 'skipped'] as $k) {
                    $c[$k] += $p[$k];
                }

                array_push($skippedIds, ...$p['skippedIds']);

                array_push($notes, ...$p['notes']);
                array_push($warnings, ...$p['warningNotes']);

                $got = count($result['records']);

                if ($got > 0 && $p['newIds'] === []) {
                    // The provider is repeating itself (or sending nothing usable): stop rather than loop.
                    $c['errors']++;
                    $notes[] = "page {$page}: no_new_orders";
                    $aborted = true;

                    break;
                }

                if ($got === 0 || $got < $this->config->pageSize || count($seenIds) >= $total) {
                    break;
                }

                $page++;

                if ($this->config->pageDelayMs > 0) {
                    ($this->sleep)($this->config->pageDelayMs);
                }
            }

            $clean = ! $aborted && $c['errors'] === 0;

            if ($clean && $total !== null && $total !== count($seenIds)) {
                // The provider's own count disagrees with what was received. Skipped
                // records are in $seenIds, so they do not cause this.
                $clean   = false;
                $notes[] = 'count_mismatch';
                $c['errors']++;
            }

            // A skipped record means the imported set is incomplete: never success.
            $complete = $clean && $c['skipped'] === 0;

            if ($complete) {
                $flagged = $this->flagMissing($runId, count($seenIds), $warnings);
            }

            $status = match (true) {
                $complete => 'success',
                $clean    => 'partial', // clean apart from skipped records
                default   => $this->incompleteStatus($c),
            };
        } catch (Throwable $e) {
            $status = $this->incompleteStatus($c);
            $c['errors']++;
            $notes[] = 'unexpected ' . $e::class;
        }

        $summary = array_merge($this->skippedNote($skippedIds), $notes, $warnings);

        try {
            $finished = $this->now();

            $this->db->table('sync_runs')->where('id', $runId)->update([
                'status'        => $status,
                'finished_at'   => $finished,
                'pages_fetched' => $c['pages'],
                'records_seen'  => $c['seen'],
                'inserted'      => $c['inserted'],
                'updated'       => $c['updated'],
                'unchanged'     => $c['unchanged'],
                'error_count'   => $c['errors'],
                'error_summary' => $summary === [] ? null : mb_substr(implode('; ', array_slice($summary, 0, 20)), 0, 2000),
                'updated_at'    => $finished,
            ]);
        } catch (Throwable $e) {
            // The row stays 'running' and is cleaned up as abandoned by the next run.
            $this->logFailure('record', $e);

            return new SyncResult($runId, SyncResult::RECORD_FAILED, $c['pages'], $c['seen'], $c['inserted'], $c['updated'], $c['unchanged'], $c['errors'], $flagged, $c['warnings'], $status, $c['skipped']);
        }

        return new SyncResult($runId, $status, $c['pages'], $c['seen'], $c['inserted'], $c['updated'], $c['unchanged'], $c['errors'], $flagged, $c['warnings'], skipped: $c['skipped']);
    }

    /**
     * One summary line for the skipped records: the count and provider order ids
     * only (never the raw names), at most ten ids.
     *
     * @param list<string> $orderIds
     *
     * @return list<string>
     */
    private function skippedNote(array $orderIds): array
    {
        if ($orderIds === []) {
            return [];
        }

        $shown = array_slice($orderIds, 0, 10);
        $more  = count($orderIds) - count($shown);

        return [count($orderIds) . ' skipped_unusable_name (orders ' . implode(', ', $shown) . ($more > 0 ? ", +{$more} more" : '') . ')'];
    }

    /**
     * @param array<string, int> $c
     */
    private function incompleteStatus(array $c): string
    {
        return ($c['inserted'] + $c['updated'] + $c['unchanged']) > 0 ? 'partial' : 'failed';
    }

    /**
     * Applies one page inside the caller's transaction. Pure with respect to the
     * run's counters: it returns the page's results and the caller merges them
     * only after the commit succeeded.
     *
     * @param list<array<string, mixed>> $records
     * @param array<int|string, true>    $alreadySeen order ids of earlier committed pages
     *
     * @return array{seen: int, inserted: int, updated: int, unchanged: int, errors: int, warnings: int, skipped: int, notes: list<string>, warningNotes: list<string>, newIds: list<string>, skippedIds: list<string>}
     */
    private function applyPage(int $runId, array $records, array $alreadySeen): array
    {
        $now = $this->now();
        $p   = ['seen' => 0, 'inserted' => 0, 'updated' => 0, 'unchanged' => 0, 'errors' => 0, 'warnings' => 0, 'skipped' => 0, 'notes' => [], 'warningNotes' => [], 'newIds' => [], 'skippedIds' => []];
        $w   = [];

        $parsed  = [];
        $pageIds = [];
        $invalid = 0;

        foreach ($records as $raw) {
            $p['seen']++;
            $row = $this->normalise($raw);

            if ($row === null) {
                $skippedId = $this->orderId($raw);

                if ($skippedId === null || $this->canonicalName($raw) !== null) {
                    // No usable identity, or a different field is bad: an error.
                    $invalid++;

                    continue;
                }

                // Valid order id, unusable name: tracked as seen, never inserted or overwritten.
                if (! isset($alreadySeen[$skippedId]) && ! isset($pageIds[$skippedId])) {
                    $pageIds[$skippedId] = true;
                    $p['newIds'][]       = $skippedId;
                    $p['skippedIds'][]   = $skippedId;
                    $p['skipped']++;
                }

                continue;
            }

            $id = $row['rc_order_id'];

            if (isset($alreadySeen[$id]) || isset($pageIds[$id])) {
                // A repeat of an order already handled in this run.
                continue;
            }

            $pageIds[$id]   = true;
            $parsed[]       = $row;
            $p['newIds'][]  = $id;
        }

        if ($invalid > 0) {
            $p['errors'] += $invalid;
            $p['notes'][] = "{$invalid} invalid_record(s)";
        }

        if ($p['skippedIds'] !== []) {
            // An existing row for a skipped order is touched for tracking only, so it is
            // not flagged missing; its name and business fields are left exactly as stored.
            $this->db->table('domains')->whereIn('rc_order_id', $p['skippedIds'])->update([
                'last_synced_at'    => $now,
                'last_seen_sync_id' => $runId,
                'is_missing'        => 0,
            ]);
        }

        if ($parsed === []) {
            return $p;
        }

        $orderIds = array_column($parsed, 'rc_order_id');
        $names    = array_column($parsed, 'domain_name');

        $byOrder = [];

        foreach ($this->db->table('domains')->select('id, rc_order_id, domain_name, content_hash, is_missing, ' . implode(', ', self::OPTIONAL_FIELDS))->whereIn('rc_order_id', $orderIds)->get()->getResultArray() as $r) {
            $byOrder[$r['rc_order_id']] = $r;
        }

        $byName = [];

        foreach ($this->db->table('domains')->select('id, rc_order_id, domain_name')->whereIn('domain_name', $names)->get()->getResultArray() as $r) {
            $byName[mb_strtolower($r['domain_name'])] = (string) $r['rc_order_id'];
        }

        $claimed = []; // name => order id, for names this page has already handled
        $kept    = 0;

        foreach ($parsed as $row) {
            $name   = $row['domain_name'];
            $oid    = $row['rc_order_id'];
            $holder = $byName[$name] ?? $claimed[$name] ?? null;

            if ($holder !== null && (string) $holder !== $oid) {
                // Same name, different order: never merge, overwrite or delete.
                $p['warnings']++;
                $w[] = "name_conflict order {$oid}";

                continue;
            }

            $claimed[$name] = $oid;
            $existing       = $byOrder[$oid] ?? null;

            if ($existing === null) {
                $this->db->table('domains')->insert($row + [
                    'content_hash'      => $this->hash($row),
                    'first_seen_at'     => $now,
                    'last_synced_at'    => $now,
                    'last_seen_sync_id' => $runId,
                    'is_missing'        => 0,
                    'created_at'        => $now,
                    'updated_at'        => $now,
                ]);
                $p['inserted']++;

                continue;
            }

            // Never replace a stored value with NULL because the provider sent nothing usable.
            foreach (self::OPTIONAL_FIELDS as $field) {
                if ($row[$field] === null && $existing[$field] !== null) {
                    $row[$field] = in_array($field, self::FLAG_FIELDS, true) ? (int) $existing[$field] : (string) $existing[$field];
                    $kept++;
                }
            }

            $hash  = $this->hash($row);
            $touch = [
                'last_synced_at'    => $now,
                'last_seen_sync_id' => $runId,
                'is_missing'        => 0,
            ];

            if ($existing['content_hash'] === $hash && mb_strtolower($existing['domain_name']) === $name) {
                $this->db->table('domains')->where('id', $existing['id'])->update($touch);
                $p['unchanged']++;

                continue;
            }

            $this->db->table('domains')->where('id', $existing['id'])->update($touch + $row + ['content_hash' => $hash, 'updated_at' => $now]);
            $p['updated']++;
        }

        if ($kept > 0) {
            $p['warnings'] += $kept;
            $w[]            = "{$kept} stored value(s) kept: provider field missing or unusable";
        }

        $p['warningNotes'] = $w;

        return $p;
    }

    /**
     * Flags domains not seen by this successful, complete run. NULL-safe for
     * rows whose last_seen_sync_id was cleared by pruning old runs.
     *
     * @param list<string> $notes
     */
    private function flagMissing(int $runId, int $seenCount, array &$notes): int
    {
        if ($seenCount === 0) {
            $notes[] = 'missing_detection_skipped: no records';

            return 0;
        }

        $known = $this->db->table('domains')->where('is_missing', 0)->countAllResults();
        $stale = $this->db->table('domains')->where('is_missing', 0)
            ->groupStart()->where('last_seen_sync_id IS NULL', null, false)->orWhere('last_seen_sync_id <>', $runId)->groupEnd()
            ->countAllResults();

        if ($stale === 0) {
            return 0;
        }

        if ($stale * 2 > $known) {
            $notes[] = "missing_detection_skipped: {$stale} of {$known} would be flagged";

            return 0;
        }

        $this->db->table('domains')->where('is_missing', 0)
            ->groupStart()->where('last_seen_sync_id IS NULL', null, false)->orWhere('last_seen_sync_id <>', $runId)->groupEnd()
            ->update(['is_missing' => 1, 'updated_at' => $this->now()]);

        return $stale;
    }

    /**
     * Maps one provider record to `domains` columns, or null when unusable.
     *
     * @param array<string, mixed> $raw
     *
     * @return array<string, mixed>|null
     */
    private function normalise(array $raw): ?array
    {
        $name    = $this->canonicalName($raw);
        $orderId = $this->orderId($raw);

        if ($name === null || $orderId === null) {
            return null;
        }

        $status = $raw['entity.currentstatus'] ?? null;
        $status = is_string($status) && $status !== '' ? $status : null;

        if ($status !== null && strlen($status) > 64) {
            return null;
        }

        $customer = $raw['entity.customerid'] ?? null;
        $customer = (is_string($customer) || is_int($customer)) && preg_match('/^[0-9]{1,32}$/', (string) $customer) === 1 ? (string) $customer : null;

        return [
            'rc_order_id'     => $orderId,
            'domain_name'     => $name,
            'rc_customer_id'  => $customer,
            'status'          => $status,
            'registered_at'   => $this->epoch($raw['orders.creationtime'] ?? null),
            'expires_at'      => $this->epoch($raw['orders.endtime'] ?? null),
            'auto_renew'      => $this->flag($raw['orders.autorenew'] ?? null),
            'privacy_enabled' => $this->flag($raw['orders.privacyprotection'] ?? null),
            // No verified provider field yet (orders.resellerlock, customerlock and
            // transferlock are deliberately ignored), so this stays unknown and a
            // stored value is kept by the optional-field rule.
            'registrar_lock'  => null,
        ];
    }

    /**
     * The provider order id when it is 1-32 digits, otherwise null.
     *
     * @param array<string, mixed> $raw
     */
    private function orderId(array $raw): ?string
    {
        $orderId = $raw['orders.orderid'] ?? null;

        if ((! is_string($orderId) && ! is_int($orderId)) || preg_match('/^[0-9]{1,32}$/', (string) $orderId) !== 1) {
            return null;
        }

        return (string) $orderId;
    }

    /**
     * The canonical (lowercase ASCII/Punycode) name, or null when unusable. The
     * provider value is deliberately not trimmed or otherwise repaired first.
     *
     * @param array<string, mixed> $raw
     */
    private function canonicalName(array $raw): ?string
    {
        return DomainNameCanonicalizer::canonicalize($raw['entity.description'] ?? null);
    }

    /** UNIX timestamp (seconds) to UTC DATETIME; anything else is unknown. */
    private function epoch(mixed $value): ?string
    {
        if ((! is_string($value) && ! is_int($value)) || preg_match('/^[0-9]{1,11}$/', (string) $value) !== 1 || (int) $value <= 0) {
            return null;
        }

        return gmdate(self::FORMAT, (int) $value);
    }

    /** "true"/"false" to 1/0; "na" or anything else is unknown (NULL). */
    private function flag(mixed $value): ?int
    {
        if (is_bool($value)) {
            return $value ? 1 : 0;
        }

        return match (is_string($value) ? strtolower($value) : null) {
            'true'  => 1,
            'false' => 0,
            default => null,
        };
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hash(array $row): string
    {
        return hash('sha256', json_encode([
            $row['rc_order_id'], $row['domain_name'], $row['rc_customer_id'], $row['status'],
            $row['registered_at'], $row['expires_at'], $row['privacy_enabled'], $row['registrar_lock'],
            $row['auto_renew'],
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * @return bool|null true = acquired, false = held by another run, null = the lock query failed
     */
    private function acquireLock(): ?bool
    {
        try {
            $row = $this->db->query('SELECT GET_LOCK(?, 0) AS got', [self::LOCK_NAME])->getRowArray();
        } catch (Throwable $e) {
            $this->logFailure('lock', $e);

            return null;
        }

        // GET_LOCK returns NULL when it errors, which must not be read as "held".
        $got = $row['got'] ?? null;

        if ($got === null || ! in_array((int) $got, [0, 1], true)) {
            return null;
        }

        return (int) $got === 1;
    }

    /** Never throws: a failed release must not hide the run's result. The lock dies with the connection anyway. */
    private function releaseLock(): void
    {
        try {
            $this->db->query('SELECT RELEASE_LOCK(?)', [self::LOCK_NAME]);
        } catch (Throwable $e) {
            $this->logFailure('unlock', $e);
        }
    }

    /** Exception class only: messages can contain SQL, hosts or data. */
    private function logFailure(string $step, Throwable $e): void
    {
        log_message('error', 'DomainSync ' . $step . ' failed: ' . $e::class);
    }

    private function now(): string
    {
        return ($this->clock)()->setTimezone(new DateTimeZone('UTC'))->format(self::FORMAT);
    }
}
