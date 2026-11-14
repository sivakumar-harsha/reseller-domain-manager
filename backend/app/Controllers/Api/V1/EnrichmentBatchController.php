<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1;

use App\Controllers\BaseController;
use App\Libraries\Enrichment\BulkEnrichmentException;
use App\Libraries\Enrichment\BulkEnrichmentService;
use CodeIgniter\HTTP\ResponseInterface;
use Throwable;

/**
 * Resumable bulk domain-details enrichment. Protected by the `auth` filter in
 * the route definitions. Responses carry counters, domain names and fixed
 * codes only: never contact data, credentials or tokens.
 */
class EnrichmentBatchController extends BaseController
{
    /** `GET enrichment/eligible?mode=missing|refresh` */
    public function eligible(): ResponseInterface
    {
        return $this->guard(function (): ResponseInterface {
            $mode = $this->request->getGet('mode') ?? BulkEnrichmentService::MODE_MISSING;

            if (! is_string($mode) || ! in_array($mode, BulkEnrichmentService::MODES, true) || array_diff(array_keys($this->request->getGet()), ['mode']) !== []) {
                return $this->error(400, 'invalid_request');
            }

            return $this->json(200, ['mode' => $mode, 'eligible' => $this->service()->eligibleCount($mode)]);
        });
    }

    /** `POST enrichment/batches` with an optional `{"mode":"missing"|"refresh"}`. */
    public function start(): ResponseInterface
    {
        return $this->guard(function (): ResponseInterface {
            $raw  = trim((string) $this->request->getBody());
            $body = $raw === '' ? [] : json_decode($raw, true);

            if (! is_array($body) || array_diff(array_keys($body), ['mode']) !== [] || $this->request->getGet() !== []) {
                return $this->error(400, 'invalid_request');
            }

            $mode = $body['mode'] ?? BulkEnrichmentService::MODE_MISSING;

            if (! is_string($mode) || ! in_array($mode, BulkEnrichmentService::MODES, true)) {
                return $this->error(400, 'invalid_request');
            }

            return $this->json(200, $this->service()->start($mode));
        });
    }

    /** `POST enrichment/batches/{id}/step`: no body. */
    public function step(string $id = ''): ResponseInterface
    {
        // A dropped phone connection must not abort a domain halfway.
        ignore_user_abort(true);

        return $this->guard(function () use ($id): ResponseInterface {
            if (trim((string) $this->request->getBody()) !== '' && ! in_array(trim((string) $this->request->getBody()), ['{}', '[]'], true)) {
                return $this->error(400, 'invalid_request');
            }

            return $this->json(200, $this->service()->step($id));
        });
    }

    /** `GET enrichment/batches/current`: the latest batch, running or finished. */
    public function current(): ResponseInterface
    {
        return $this->guard(fn (): ResponseInterface => $this->json(200, $this->service()->current()));
    }

    private function service(): BulkEnrichmentService
    {
        return service('bulkEnrichmentService');
    }

    /**
     * @param callable(): ResponseInterface $action
     */
    private function guard(callable $action): ResponseInterface
    {
        try {
            return $action();
        } catch (BulkEnrichmentException $e) {
            $status = match ($e->errorCode) {
                'batch_not_found' => 404,
                default           => 409,
            };

            return $this->json($status, ['error' => $e->errorCode] + ($e->batchId !== null ? ['batch_id' => $e->batchId] : []));
        } catch (Throwable $e) {
            // Class name only: database messages can contain SQL and data.
            log_message('error', 'Enrichment batch request failed: {class}', ['class' => $e::class]);

            return $this->error(500, 'server_error');
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

    private function error(int $status, string $code): ResponseInterface
    {
        return $this->json($status, ['error' => $code]);
    }
}
