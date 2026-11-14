<?php

declare(strict_types=1);

namespace App\Libraries\Enrichment;

use App\Libraries\Sync\DomainSyncService;
use App\Libraries\Sync\SyncResult;
use CodeIgniter\Database\BaseConnection;
use Throwable;

/**
 * Resumable bulk enrichment on top of DomainDetailsEnrichmentService.
 *
 * A batch is a snapshot of eligible domain ids plus a cursor, kept in one JSON
 * file under the writable directory (ids, counters and fixed error codes only;
 * no contact data). No database table is involved. Each step takes an exclusive
 * file lock, processes a few domains strictly one after another (so there is
 * never more than one provider request in flight), and advances the cursor
 * after every domain, so work already done is never repeated and survives a
 * client disconnect. The service writes nothing to the domains table itself;
 * every write goes through the single-domain enrichment service.
 *
 * When a DomainSyncService is supplied, a batch starts with a discovery phase:
 * the existing read-only inventory sync runs once (first step), then the ids
 * needing details are snapshotted and the enrichment phase proceeds as above.
 */
class BulkEnrichmentService
{
    public const MODE_MISSING = 'missing';
    public const MODE_REFRESH = 'refresh';

    public const MODES = [self::MODE_MISSING, self::MODE_REFRESH];

    public function __construct(
        private readonly BaseConnection $db,
        private readonly DomainDetailsEnrichmentService $enricher,
        private readonly string $dir,
        private readonly int $timeBudgetSeconds = 20,
        private readonly int $maxPerStep = 5,
        private readonly ?DomainSyncService $sync = null,
        private readonly int $skipDiscoveryWithinSeconds = 600,
    ) {
    }

    public function eligibleCount(string $mode): int
    {
        return $this->eligibleQuery($mode)->countAllResults();
    }

    /**
     * @return array<string, mixed>
     *
     * @throws BulkEnrichmentException batch_in_progress, batch_busy, nothing_to_do
     */
    public function start(string $mode): array
    {
        return $this->locked(function () use ($mode): array {
            $state = $this->read();

            if ($state !== null && $state['status'] === 'running') {
                throw new BulkEnrichmentException('batch_in_progress', $state['batch_id']);
            }

            // A clean inventory sync that finished moments ago (the app's own
            // Refresh or automatic refresh) already did the discovery.
            $discover = $this->sync !== null && ! $this->inventoryRecentlySynced();
            $ids      = $discover ? [] : $this->snapshotIds($mode);

            if (! $discover && $ids === []) {
                throw new BulkEnrichmentException('nothing_to_do');
            }

            $now   = gmdate('Y-m-d\TH:i:s\Z');
            $state = [
                'batch_id'   => bin2hex(random_bytes(8)),
                'mode'       => $mode,
                'status'     => 'running',
                'phase'      => $discover ? 'discover' : 'enrich',
                'discovery'  => ['state' => $discover ? 'pending' : 'skipped'],
                'started_at' => $now,
                'updated_at' => $now,
                'ids'        => $ids,
                'cursor'     => 0,
                'succeeded'  => 0,
                'failed'     => 0,
                'skipped'    => 0,
                'failures'   => [],
            ];
            $this->write($state);

            return $this->view($state);
        });
    }

    /**
     * Processes up to maxPerStep domains (stopping early once the time budget
     * is used) and returns the progress.
     *
     * @return array<string, mixed>
     *
     * @throws BulkEnrichmentException batch_not_found, batch_busy
     */
    public function step(string $batchId): array
    {
        return $this->locked(function () use ($batchId): array {
            $state = $this->read();

            if ($state === null || $state['batch_id'] !== $batchId) {
                throw new BulkEnrichmentException('batch_not_found');
            }

            if ($state['status'] !== 'running') {
                return $this->view($state);
            }

            if (($state['phase'] ?? 'enrich') === 'discover') {
                return $this->discover($state);
            }

            $deadline = microtime(true) + $this->timeBudgetSeconds;
            $done     = 0;
            $total    = count($state['ids']);

            while ($state['cursor'] < $total && $done < $this->maxPerStep && ($done === 0 || microtime(true) < $deadline)) {
                $domainId = $state['ids'][$state['cursor']];
                $outcome  = $this->processOne($domainId, $state['mode']);

                $state['cursor']++;
                $done++;

                if ($outcome === 'ok') {
                    $state['succeeded']++;
                } elseif ($outcome === 'skipped') {
                    $state['skipped']++;
                } else {
                    $state['failed']++;
                    $state['failures'][] = ['domain_id' => $domainId, 'error' => $outcome];
                }

                if ($outcome === EnrichmentResult::NOT_CONFIGURED) {
                    // Every further domain would fail the same way.
                    $state['status'] = 'aborted';
                    $state['abort']  = 'provider_not_configured';
                    $this->write($state);

                    return $this->view($state);
                }

                // Persist after every domain: a dropped connection loses nothing.
                $this->write($state);
            }

            if ($state['cursor'] >= $total) {
                $state['status'] = 'completed';
            }

            $this->write($state);

            return $this->view($state);
        });
    }

    /**
     * Discovery step: one run of the existing inventory sync, then the
     * enrichment snapshot. Safe to repeat after an interruption: the sync is an
     * idempotent upsert and nothing is snapshotted until it has finished.
     *
     * @param array<string, mixed> $state
     *
     * @return array<string, mixed>
     */
    private function discover(array $state): array
    {
        // A full inventory read is several provider pages; the batch step budget does not apply.
        @set_time_limit(280);

        try {
            $result = $this->sync->run('manual');
        } catch (Throwable $e) {
            log_message('error', 'Bulk discovery failed: {class}', ['class' => $e::class]);
            $result = SyncResult::notRun(SyncResult::STORAGE_ERROR);
        }

        if ($result->status === SyncResult::ALREADY_RUNNING) {
            // A sync started elsewhere (e.g. the CLI) is live; try again shortly.
            throw new BulkEnrichmentException('sync_in_progress');
        }

        if ($result->status === SyncResult::NOT_CONFIGURED) {
            $state['status']    = 'aborted';
            $state['abort']     = 'provider_not_configured';
            $state['discovery'] = ['state' => 'failed', 'run_status' => $result->status];
            $this->write($state);

            return $this->view($state);
        }

        $state['discovery'] = $result->ran() ? [
            'state'      => 'done',
            'run_status' => $result->status === SyncResult::RECORD_FAILED ? (string) $result->outcome : $result->status,
            'seen'       => $result->seen,
            'new'        => $result->inserted,
            'updated'    => $result->updated,
            'unchanged'  => $result->unchanged,
            'skipped'    => $result->skipped,
            'errors'     => $result->errors,
        ] : ['state' => 'failed', 'run_status' => $result->status];

        // A partial or failed discovery still leaves the stored domains enrichable.
        $state['ids']    = $this->snapshotIds($state['mode']);
        $state['cursor'] = 0;
        $state['phase']  = 'enrich';

        if ($state['ids'] === []) {
            $state['status'] = 'completed';
        }

        $this->write($state);

        return $this->view($state);
    }

    /** True when a success/partial inventory run finished within the skip window. Any failure to tell means "no". */
    private function inventoryRecentlySynced(): bool
    {
        if ($this->sync === null || $this->skipDiscoveryWithinSeconds <= 0) {
            return false;
        }

        try {
            return $this->db->table('sync_runs')
                ->whereIn('status', ['success', 'partial'])
                ->where('finished_at >=', gmdate('Y-m-d H:i:s', time() - $this->skipDiscoveryWithinSeconds))
                ->countAllResults() > 0;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @return list<int>
     */
    private function snapshotIds(string $mode): array
    {
        return array_map('intval', array_column($this->eligibleQuery($mode)->select('id')->orderBy('id', 'ASC')->get()->getResultArray(), 'id'));
    }

    /**
     * @return array<string, mixed>
     *
     * @throws BulkEnrichmentException batch_not_found
     */
    public function current(): array
    {
        $state = $this->read();

        if ($state === null) {
            throw new BulkEnrichmentException('batch_not_found');
        }

        return $this->view($state);
    }

    /** 'ok', 'skipped', or the fixed failure code. */
    private function processOne(int $domainId, string $mode): string
    {
        try {
            $row = $this->db->table('domains')->select('details_synced_at, is_missing')->where('id', $domainId)->get()->getRowArray();

            if ($row === null || (int) $row['is_missing'] === 1) {
                return 'skipped';
            }

            if ($mode === self::MODE_MISSING && $row['details_synced_at'] !== null) {
                return 'skipped';
            }

            $result = $this->enricher->enrich($domainId);
        } catch (Throwable $e) {
            log_message('error', 'Bulk enrichment step failed: {class}', ['class' => $e::class]);

            return 'server_error';
        }

        return $result->succeeded() ? 'ok' : $result->status;
    }

    private function eligibleQuery(string $mode): \CodeIgniter\Database\BaseBuilder
    {
        $builder = $this->db->table('domains')
            ->where('is_missing', 0)
            ->where("rc_customer_id IS NOT NULL AND rc_customer_id <> '' AND rc_order_id <> ''", null, false);

        if ($mode === self::MODE_MISSING) {
            $builder->where('details_synced_at IS NULL', null, false);
        }

        return $builder;
    }

    /**
     * @param array<string, mixed> $state
     *
     * @return array<string, mixed>
     */
    private function view(array $state): array
    {
        $total     = count($state['ids']);
        $cursor    = (int) $state['cursor'];
        $failedIds = array_column($state['failures'], 'domain_id');
        $wanted    = $failedIds;

        if (($state['phase'] ?? 'enrich') === 'enrich' && $cursor < $total) {
            $wanted[] = $state['ids'][$cursor];
        }

        $names = [];

        if ($wanted !== []) {
            foreach ($this->db->table('domains')->select('id, domain_name')->whereIn('id', array_values(array_unique($wanted)))->get()->getResultArray() as $r) {
                $names[(int) $r['id']] = $r['domain_name'];
            }
        }

        return [
            'batch_id'    => $state['batch_id'],
            'mode'        => $state['mode'],
            'status'      => $state['status'],
            'phase'       => $state['phase'] ?? 'enrich',
            'discovery'   => $state['discovery'] ?? ['state' => 'skipped'],
            'next_action' => match ($state['status']) {
                'running'   => 'continue',
                'completed' => 'done',
                default     => 'stopped',
            },
            'abort_reason' => $state['abort'] ?? null,
            'started_at'   => $state['started_at'],
            'updated_at'   => $state['updated_at'],
            'total'        => $total,
            'processed'    => $cursor,
            'remaining'    => $total - $cursor,
            'succeeded'    => $state['succeeded'],
            'failed'       => $state['failed'],
            'skipped'      => $state['skipped'],
            'current'      => ($state['phase'] ?? 'enrich') === 'enrich' && $cursor < $total
                ? ['domain_id' => $state['ids'][$cursor], 'domain_name' => $names[$state['ids'][$cursor]] ?? null]
                : null,
            'failures' => array_map(static fn (array $f): array => [
                'domain_id'   => $f['domain_id'],
                'domain_name' => $names[$f['domain_id']] ?? null,
                'error'       => $f['error'],
            ], $state['failures']),
        ];
    }

    /**
     * @template T
     *
     * @param callable(): T $action
     *
     * @return T
     */
    private function locked(callable $action): mixed
    {
        if (! is_dir($this->dir) && ! mkdir($this->dir, 0750, true) && ! is_dir($this->dir)) {
            throw new \RuntimeException('batch directory unavailable');
        }

        $handle = fopen($this->dir . DIRECTORY_SEPARATOR . 'batch.lock', 'c');

        if ($handle === false || ! flock($handle, LOCK_EX | LOCK_NB)) {
            if (is_resource($handle)) {
                fclose($handle);
            }

            throw new BulkEnrichmentException('batch_busy');
        }

        try {
            return $action();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function read(): ?array
    {
        $file = $this->dir . DIRECTORY_SEPARATOR . 'current.json';
        $raw  = is_file($file) ? file_get_contents($file) : false;
        $data = $raw === false ? null : json_decode($raw, true);

        return is_array($data) && isset($data['batch_id'], $data['ids'], $data['status']) ? $data : null;
    }

    /**
     * @param array<string, mixed> $state
     */
    private function write(array &$state): void
    {
        $state['updated_at'] = gmdate('Y-m-d\TH:i:s\Z');
        $file                = $this->dir . DIRECTORY_SEPARATOR . 'current.json';
        $tmp                 = $file . '.tmp';

        if (file_put_contents($tmp, json_encode($state), LOCK_EX) === false || ! rename($tmp, $file)) {
            throw new \RuntimeException('batch state not writable');
        }
    }
}
