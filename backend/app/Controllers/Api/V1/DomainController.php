<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1;

use App\Controllers\BaseController;
use App\Libraries\Enrichment\EnrichmentResult;
use App\Models\DomainModel;
use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\I18n\Time;
use DateTimeImmutable;
use DateTimeZone;
use Throwable;

/**
 * Read access to the cached domain inventory, plus the single-domain details
 * enrichment. Protected by the `auth` filter in the route definitions. The
 * enrichment only reads from ResellerClub; the order and customer ids always
 * come from the stored domain row, never from the request.
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

            $expiry = $this->request->getGet('expiry') ?? 'all';
            $sort   = $this->request->getGet('sort');

            if (! is_string($expiry) || ! in_array($expiry, DomainModel::EXPIRY_FILTERS, true)
                || ($sort !== null && (! is_string($sort) || ! in_array($sort, DomainModel::SORTS, true)))) {
                return $this->error(400, 'invalid_request');
            }

            $perPage = min($perPage, self::MAX_PER_PAGE);
            $model   = new DomainModel();
            $result  = $model->search(
                $q === null ? null : trim($q),
                $page,
                $perPage,
                $expiry,
                $sort,
                // Time::now() honours Time::setTestNow(), as in the expiry summary.
                DateTimeImmutable::createFromInterface(Time::now('UTC')),
            );

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
     * `GET domains/{id}/details`: the domain, its nameservers, the last
     * enrichment time and the four stored contacts. A role with no stored
     * contact is null, so an un-enriched domain still loads.
     */
    public function details(string $id = ''): ResponseInterface
    {
        return $this->guard(function () use ($id): ResponseInterface {
            $model = new DomainModel();
            $row   = preg_match('/^[1-9][0-9]{0,17}$/', $id) === 1 ? $model->findDetail((int) $id) : null;

            if ($row === null) {
                return $this->error(404, 'not_found');
            }

            $ns       = isset($row['nameservers_json']) ? json_decode((string) $row['nameservers_json'], true) : null;
            $stored   = $model->findContactsByRole((int) $row['id']);
            $contacts = [];

            foreach (['registrant', 'admin', 'tech', 'billing'] as $role) {
                $contacts[$role] = isset($stored[$role])
                    ? array_map(static fn (mixed $v): ?string => $v === null || $v === '' ? null : (string) $v, $stored[$role])
                    : null;
            }

            $out                      = $this->present($row);
            $out['rc_order_id']       = $row['rc_order_id'];
            $out['rc_customer_id']    = $row['rc_customer_id'];
            $out['registered_at']     = $this->iso($row['registered_at']);
            $out['first_seen_at']     = $this->iso($row['first_seen_at']);
            $out['nameservers']       = is_array($ns) ? array_values($ns) : null;
            $out['details_synced_at'] = $this->iso($model->findDetailsSyncedAt((int) $row['id']));
            $out['contacts']          = $contacts;

            return $this->json(200, $out);
        });
    }

    public function enrich(string $id = ''): ResponseInterface
    {
        return $this->guard(function () use ($id): ResponseInterface {
            $body = trim((string) $this->request->getBody());

            // No client input is accepted: ids come from the stored domain.
            if ((! in_array($body, ['', '{}', '[]'], true)) || $this->request->getGet() !== []) {
                return $this->error(400, 'invalid_request');
            }

            if (preg_match('/^[1-9][0-9]{0,17}$/', $id) !== 1) {
                return $this->error(404, 'not_found');
            }

            $result = service('domainEnrichmentService')->enrich((int) $id);

            return match ($result->status) {
                EnrichmentResult::SUCCESS => $this->json(200, [
                    'domain_id'           => (int) $id,
                    'status'              => 'enriched',
                    'details_synced_at'   => $this->iso($result->detailsSyncedAt),
                    'contacts_created'    => $result->contactsCreated,
                    'contacts_updated'    => $result->contactsUpdated,
                    'roles_linked'        => $result->rolesLinked,
                    'roles_changed'       => $result->rolesChanged,
                    'roles_preserved'     => $result->rolesPreserved,
                    'nameservers_updated' => $result->nameserversUpdated,
                    'warnings'            => $result->warnings,
                ]),
                EnrichmentResult::NOT_FOUND         => $this->error(404, 'not_found'),
                EnrichmentResult::NOT_CONFIGURED    => $this->error(503, 'provider_not_configured'),
                EnrichmentResult::CUSTOMER_UNKNOWN  => $this->error(409, 'customer_unknown'),
                EnrichmentResult::CUSTOMER_MISMATCH => $this->error(409, 'customer_mismatch'),
                EnrichmentResult::PROVIDER_ERROR    => $this->error(502, 'provider_error'),
                default                             => $this->error(500, 'server_error'),
            };
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
