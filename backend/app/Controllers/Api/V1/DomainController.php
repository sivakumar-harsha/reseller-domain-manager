<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1;

use App\Controllers\BaseController;
use App\Models\DomainModel;
use CodeIgniter\HTTP\ResponseInterface;
use DateTimeImmutable;
use DateTimeZone;
use Throwable;

/**
 * Read-only access to the cached domain inventory. Protected by the `auth`
 * filter in the route definitions; there are no write actions.
 */
class DomainController extends BaseController
{
    private const MAX_PER_PAGE     = 100;
    private const DEFAULT_PER_PAGE = 20;
    private const MAX_QUERY_LENGTH = 253;

    public function index(): ResponseInterface
    {
        return $this->guard(function (): ResponseInterface {
            $q = $this->request->getGet('q');

            if ($q !== null && (! is_string($q) || strlen($q) > self::MAX_QUERY_LENGTH)) {
                return $this->error(400, 'invalid_request');
            }

            $page    = $this->positiveInt($this->request->getGet('page'), 1);
            $perPage = $this->positiveInt($this->request->getGet('per_page'), self::DEFAULT_PER_PAGE);

            if ($page === null || $perPage === null) {
                return $this->error(400, 'invalid_request');
            }

            $perPage = min($perPage, self::MAX_PER_PAGE);
            $model   = new DomainModel();
            $result  = $model->search($q === null ? null : trim($q), $page, $perPage);

            return $this->json(200, [
                'data' => array_map(fn (array $r): array => $this->present($r), $result['rows']),
                'meta' => [
                    'page'        => $page,
                    'per_page'    => $perPage,
                    'total'       => $result['total'],
                    'total_pages' => (int) ceil($result['total'] / $perPage),
                ],
                'last_synced_at' => $this->iso($model->latestSyncedAt()),
            ]);
        });
    }

    public function show(string $id = ''): ResponseInterface
    {
        return $this->guard(function () use ($id): ResponseInterface {
            $row = strlen($id) <= 18 ? (new DomainModel())->findDetail((int) $id) : null;

            if ($row === null) {
                return $this->error(404, 'not_found');
            }

            $out = $this->present($row);
            $ns  = isset($row['nameservers_json']) ? json_decode((string) $row['nameservers_json'], true) : null;

            $out['nameservers']    = is_array($ns) ? array_values($ns) : null;
            $out['rc_order_id']    = $row['rc_order_id'];
            $out['rc_customer_id'] = $row['rc_customer_id'];
            $out['registered_at']  = $this->iso($row['registered_at']);
            $out['first_seen_at']  = $this->iso($row['first_seen_at']);

            return $this->json(200, $out);
        });
    }

    /**
     * Shared fields. Provider-derived values stay null when unknown; flags
     * are true/false/null, never coerced.
     *
     * @param array<string, mixed> $r
     *
     * @return array<string, mixed>
     */
    private function present(array $r): array
    {
        return [
            'id'              => (int) $r['id'],
            'domain_name'     => $r['domain_name'],
            'status'          => $r['status'],
            'expires_at'      => $this->iso($r['expires_at']),
            'auto_renew'      => $this->flag($r['auto_renew']),
            'privacy_enabled' => $this->flag($r['privacy_enabled']),
            'registrar_lock'  => $this->flag($r['registrar_lock']),
            'is_missing'      => (bool) $r['is_missing'],
            'last_synced_at'  => $this->iso($r['last_synced_at']),
        ];
    }

    private function flag(mixed $value): ?bool
    {
        return $value === null ? null : (bool) $value;
    }

    /** UTC "Y-m-d H:i:s" to ISO 8601 with a Z suffix. */
    private function iso(?string $utc): ?string
    {
        if ($utc === null) {
            return null;
        }

        return (new DateTimeImmutable($utc, new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z');
    }

    /** null means "present but invalid". */
    private function positiveInt(mixed $value, int $default): ?int
    {
        if ($value === null) {
            return $default;
        }

        if (! is_string($value) || preg_match('/^[1-9][0-9]{0,8}$/', $value) !== 1) {
            return null;
        }

        return (int) $value;
    }

    /**
     * @param callable(): ResponseInterface $action
     */
    private function guard(callable $action): ResponseInterface
    {
        try {
            return $action();
        } catch (Throwable $e) {
            // Class name only: database messages can contain SQL and data.
            log_message('error', 'Domain request failed: {class}', ['class' => $e::class]);

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
