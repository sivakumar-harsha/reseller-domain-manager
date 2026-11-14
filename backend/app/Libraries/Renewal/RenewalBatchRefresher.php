<?php

declare(strict_types=1);

namespace App\Libraries\Renewal;

use Closure;
use CodeIgniter\Database\BaseConnection;
use DateTimeImmutable;
use DateTimeZone;
use Throwable;

/**
 * Derives a batch's status from its item states in one short transaction (no
 * network inside). Shared by execution and reconciliation so both write the same
 * status for the same items; the mapping itself is
 * RenewalExecutionService::batchStatus() (any `uncertain` item with nothing
 * left to process gives `needs_review`).
 */
final class RenewalBatchRefresher
{
    /**
     * @param Closure(): DateTimeImmutable $clock
     */
    public function __construct(private readonly BaseConnection $db, private readonly Closure $clock)
    {
    }

    public function refresh(int $batchId): void
    {
        $this->db->resetTransStatus();
        $this->db->transBegin();

        try {
            $this->db->query('SELECT id FROM renewal_batches WHERE id = ? FOR UPDATE', [$batchId]);

            $counts = [];

            foreach ($this->db->query('SELECT state, COUNT(*) AS n FROM renewal_items WHERE batch_id = ? GROUP BY state', [$batchId])->getResultArray() as $r) {
                $counts[(string) $r['state']] = (int) $r['n'];
            }

            $status   = RenewalExecutionService::batchStatus($counts);
            $terminal = in_array($status, ['completed', 'failed', 'partial'], true);
            $now      = ($this->clock)()->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');

            $this->db->table('renewal_batches')->where('id', $batchId)->update([
                'status'      => $status,
                'updated_at'  => $now,
                'finished_at' => $terminal ? $now : null,
            ]);

            if ($this->db->transStatus() === false) {
                throw new RenewalException('storage_error');
            }

            $this->db->transCommit();
        } catch (Throwable $e) {
            $this->db->transRollback();
            $this->db->resetTransStatus();
            log_message('error', 'Renewal batch {id} status refresh failed: {class}', ['id' => $batchId, 'class' => $e::class]);
        }
    }
}
