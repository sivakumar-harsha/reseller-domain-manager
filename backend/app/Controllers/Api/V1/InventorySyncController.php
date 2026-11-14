<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1;

use App\Controllers\BaseController;
use App\Libraries\Sync\DomainSyncService;
use App\Libraries\Sync\SyncResult;
use CodeIgniter\HTTP\ResponseInterface;
use Throwable;

/**
 * Inventory-only sync: one run of the existing DomainSyncService (new domains,
 * changed expiry dates). No enrichment and no batch state, so it never
 * interferes with the bulk enrichment workflow. Runs are recorded as
 * `scheduled` in sync_runs (manual Global Sync stays `manual`). Protected by the `auth` filter
 * in the route definitions. Responses carry counters and fixed codes only.
 */
class InventorySyncController extends BaseController
{
    /** `POST inventory/sync`: no body. */
    public function run(): ResponseInterface
    {
        // A dropped phone connection must not abort the run halfway.
        ignore_user_abort(true);
        @set_time_limit(280);

        try {
            $body = trim((string) $this->request->getBody());

            if (($body !== '' && ! in_array($body, ['{}', '[]'], true)) || $this->request->getGet() !== []) {
                return $this->json(400, ['error' => 'invalid_request']);
            }

            /** @var DomainSyncService $sync */
            $sync   = service('domainSyncService');
            $result = $sync->run('scheduled');

            if (! $result->ran()) {
                return match ($result->status) {
                    SyncResult::ALREADY_RUNNING => $this->json(409, ['error' => 'sync_in_progress']),
                    SyncResult::NOT_CONFIGURED  => $this->json(503, ['error' => 'provider_not_configured']),
                    default                     => $this->json(500, ['error' => 'server_error']),
                };
            }

            $status = $result->status === SyncResult::RECORD_FAILED ? (string) $result->outcome : $result->status;

            if ($status === 'failed') {
                return $this->json(502, ['error' => 'provider_error']);
            }

            return $this->json(200, [
                'status'    => $status,
                'seen'      => $result->seen,
                'new'       => $result->inserted,
                'updated'   => $result->updated,
                'unchanged' => $result->unchanged,
            ]);
        } catch (Throwable $e) {
            // Class name only: database messages can contain SQL and data.
            log_message('error', 'Inventory sync request failed: {class}', ['class' => $e::class]);

            return $this->json(500, ['error' => 'server_error']);
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    private function json(int $status, array $data): ResponseInterface
    {
        return $this->response
            ->setStatusCode($status)
            ->setHeader('Cache-Control', 'no-store')
            ->setHeader('Pragma', 'no-cache')
            ->setJSON($data);
    }
}
