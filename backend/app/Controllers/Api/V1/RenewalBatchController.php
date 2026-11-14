<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1;

use App\Controllers\BaseController;
use App\Libraries\Pricing\RenewalQuoteService;
use App\Libraries\Renewal\RenewalBatchReader;
use App\Libraries\Renewal\RenewalEligibility;
use App\Libraries\Renewal\RenewalException;
use App\Libraries\ResellerClub\ResellerClubException;
use App\Models\DomainModel;
use CodeIgniter\HTTP\ResponseInterface;
use DateTimeImmutable;
use DateTimeZone;
use Throwable;

/**
 * Renewal batches over the API. Protected by the `auth` filter in the route
 * definitions; the user is ALWAYS the authenticated user, never a request value.
 *
 * - create: stores a batch of LOCAL pending records (amounts are snapshotted
 *   from a server-side quote; order ids, customer ids and expiries come from the
 *   stored domain rows). It never renews anything.
 * - index / show: the user's own batches only; another user's batch is a 404.
 * - reconcile: READ-ONLY provider lookups for items already submitted; it never
 *   sends a renewal and never resets an item.
 *
 * This controller has no access to renewal execution: it does not call
 * processItem(), processBatch() or renewDomain(), and no route reaches them.
 * Responses carry fixed error codes only: no SQL, exceptions, credentials or
 * provider text.
 */
class RenewalBatchController extends BaseController
{
    private const MAX_PER_PAGE     = 100;
    private const DEFAULT_PER_PAGE = 20;
    private const MAX_BODY_BYTES   = 8192;

    private const CREATE_FIELDS = ['domain_ids', 'years', 'invoice_option', 'idempotency_key'];

    /** `POST renewal-batches` with {"domain_ids":[1,2],"years":1,"invoice_option":"KeepInvoice","idempotency_key":"..."} */
    public function create(): ResponseInterface
    {
        return $this->guard(function (): ResponseInterface {
            $userId = $this->userId();
            $input  = $this->parseCreate();

            if (is_string($input)) {
                return $this->error(400, $input);
            }

            // An existing batch for THIS user's key is returned BEFORE any domain lookup or pricing request.
            // This lookup is only an optimisation: createBatch() keeps its own unique-key and transaction
            // protection, so a concurrent identical request still resolves to one batch.
            $existing = service('renewalBatchService')->findExistingBatch(
                $userId,
                $input['idempotency_key'],
                $input['invoice_option'],
                $input['years'],
                $input['domain_ids'],
            );

            if ($existing !== null) {
                $batch = (new RenewalBatchReader())->findForUser($userId, $existing);

                return $batch === null
                    ? $this->error(500, 'server_error')
                    : $this->json(200, ['replayed' => true, 'batch' => $batch]);
            }

            // Domains come from the stored inventory (one owner: there is no per-domain owner column).
            $model   = new DomainModel();
            $domains = [];

            foreach ($input['domain_ids'] as $id) {
                $row = $model->select('id, domain_name, status, expires_at, is_missing')->where('id', $id)->first();

                if ($row === null) {
                    return $this->error(422, 'domain_not_found', ['domain_id' => $id]);
                }

                // The same eligibility rule as the quote and execution, before any pricing request.
                // (createBatch() applies it again: this early answer only saves a provider call.)
                if ((int) $row['is_missing'] !== 1) {
                    $eligibility = RenewalEligibility::evaluate(
                        $row['status'] === null ? null : (string) $row['status'],
                        $row['expires_at'] === null ? null : (new DateTimeImmutable((string) $row['expires_at'], new DateTimeZone('UTC')))->getTimestamp(),
                        time(),
                    );

                    if (! $eligibility['eligible']) {
                        return $this->error(422, (string) $eligibility['reason'], ['domain_id' => (int) $row['id']]);
                    }
                }

                $domains[] = $row;
            }

            $lines   = [];
            $quoting = service('renewalQuoteService');

            foreach ($domains as $row) {
                try {
                    $quote = $quoting->quote((string) $row['domain_name'], $input['years'], (bool) $row['is_missing']);
                } catch (ResellerClubException $e) {
                    return $e->getMessage() === 'not_configured'
                        ? $this->error(503, 'provider_not_configured')
                        : $this->error(502, 'provider_error');
                }

                foreach (['renewal_amount', 'tax', 'vat', 'total', 'currency'] as $field) {
                    if (! is_string($quote[$field] ?? null)) {
                        return $this->error(422, 'quote_unavailable', ['domain_id' => (int) $row['id']]);
                    }
                }

                if ($quote['status'] !== 'available' || $quote['tax_status'] !== 'estimated') {
                    return $this->error(422, 'quote_unavailable', ['domain_id' => (int) $row['id']]);
                }

                $lines[] = [
                    'domain_id'      => (int) $row['id'],
                    'renewal_amount' => $quote['renewal_amount'],
                    'gst_amount'     => $quote['tax'],
                    'vat_amount'     => $quote['vat'],
                    'total_amount'   => $quote['total'],
                    'currency'       => $quote['currency'],
                ];
            }

            try {
                $result = service('renewalBatchService')->createBatch($userId, $input['idempotency_key'], $input['invoice_option'], $input['years'], $lines);
            } catch (RenewalException $e) {
                if ($e->errorCode() === 'duplicate_cycle') {
                    return $this->duplicateCycle($userId, $input['domain_ids']);
                }

                return $this->renewalError($e);
            }

            $batch = (new RenewalBatchReader())->findForUser($userId, $result['batch_id']);

            if ($batch === null) {
                return $this->error(500, 'server_error');
            }

            return $this->json($result['replayed'] ? 200 : 201, ['replayed' => $result['replayed'], 'batch' => $batch]);
        });
    }

    /**
     * `GET renewal-batches/active?domain_ids=46,47`: the signed-in user's ACTIVE batch per domain (read-only).
     * A batch that is stale and was never submitted is not "active": creating a new batch releases it.
     * Answers {"data":[{"domain_id":46,"batch":{...full batch...}}]}; domains without one are omitted.
     */
    public function active(): ResponseInterface
    {
        return $this->guard(function (): ResponseInterface {
            $userId = $this->userId();
            $query  = $this->request->getGet() ?? [];
            $raw    = $query['domain_ids'] ?? null;

            if (! is_string($raw) || array_diff(array_keys($query), ['domain_ids']) !== []) {
                return $this->error(400, 'invalid_request');
            }

            $parts = explode(',', $raw);

            if (count($parts) > 50) {
                return $this->error(400, 'invalid_domain_ids');
            }

            $ids = [];

            foreach ($parts as $p) {
                if (preg_match('/^[1-9][0-9]{0,17}$/', $p) !== 1) {
                    return $this->error(400, 'invalid_domain_ids');
                }

                $ids[] = (int) $p;
            }

            $reader = new RenewalBatchReader();
            $data   = [];

            foreach (service('renewalBatchService')->findActiveBatchIds($userId, array_values(array_unique($ids))) as $domainId => $batchId) {
                $batch = $reader->findForUser($userId, $batchId);

                if ($batch !== null) {
                    $data[] = ['domain_id' => $domainId, 'batch' => $batch];
                }
            }

            return $this->json(200, ['data' => $data]);
        });
    }

    /**
     * 409 duplicate_cycle, with `existing_batch_id` when the user's own active batch can be identified.
     *
     * @param list<int> $domainIds
     */
    private function duplicateCycle(int $userId, array $domainIds): ResponseInterface
    {
        $extra = [];

        try {
            $found = service('renewalBatchService')->findActiveBatchIds($userId, $domainIds);

            if ($found !== []) {
                $extra = ['existing_batch_id' => (int) reset($found), 'domain_id' => (int) array_key_first($found)];
            }
        } catch (Throwable $e) {
            log_message('error', 'Renewal duplicate lookup failed: {class}', ['class' => $e::class]);
        }

        return $this->error(409, 'duplicate_cycle', $extra);
    }

    /** `GET renewal-batches?page=1&per_page=20` */
    public function index(): ResponseInterface
    {
        return $this->guard(function (): ResponseInterface {
            $userId = $this->userId();
            $page   = $this->positiveInt($this->request->getGet('page'), 1);
            $per    = $this->positiveInt($this->request->getGet('per_page'), self::DEFAULT_PER_PAGE);

            if ($page === null || $per === null || array_diff(array_keys($this->request->getGet() ?? []), ['page', 'per_page']) !== []) {
                return $this->error(400, 'invalid_request');
            }

            $per    = min($per, self::MAX_PER_PAGE);
            $result = (new RenewalBatchReader())->listForUser($userId, $page, $per);

            return $this->json(200, [
                'data' => $result['rows'],
                'meta' => [
                    'page'        => $page,
                    'per_page'    => $per,
                    'total'       => $result['total'],
                    'total_pages' => (int) ceil($result['total'] / $per),
                ],
            ]);
        });
    }

    /** `GET renewal-batches/{id}` */
    public function show(string $id = ''): ResponseInterface
    {
        return $this->guard(function () use ($id): ResponseInterface {
            $batchId = $this->batchId($id);
            $batch   = $batchId === null ? null : (new RenewalBatchReader())->findForUser($this->userId(), $batchId);

            // Unknown, malformed and another user's batch all look the same.
            return $batch === null ? $this->error(404, 'not_found') : $this->json(200, ['batch' => $batch]);
        });
    }

    /**
     * `POST renewal-batches/{id}/reconcile` (no body). Read-only: asks the provider about
     * items that were already submitted. Nothing is renewed, retried or reset.
     */
    public function reconcile(string $id = ''): ResponseInterface
    {
        // A dropped connection must not leave a batch half reconciled.
        ignore_user_abort(true);

        return $this->guard(function () use ($id): ResponseInterface {
            $raw = trim((string) $this->request->getBody());

            if (($raw !== '' && ! in_array($raw, ['{}', '[]'], true)) || ($this->request->getGet() ?? []) !== []) {
                return $this->error(400, 'invalid_request');
            }

            $userId  = $this->userId();
            $batchId = $this->batchId($id);
            $reader  = new RenewalBatchReader();

            // Ownership is checked BEFORE anything else happens.
            if ($batchId === null || ! $reader->ownedBy($userId, $batchId)) {
                return $this->error(404, 'not_found');
            }

            try {
                $outcome = service('renewalReconciliationService')->reconcileBatch($batchId);
            } catch (RenewalException) {
                return $this->error(404, 'not_found');
            }

            $batch = $reader->findForUser($userId, $batchId);

            if ($batch === null) {
                return $this->error(404, 'not_found');
            }

            return $this->json(200, [
                'batch'          => $batch,
                'reconciliation' => array_map(static fn ($o): array => [
                    'item_id'        => $o->itemId,
                    'previous_state' => $o->previousState,
                    'state'          => $o->state,
                    'result'         => $o->result,
                    'reason'         => $o->reason,
                    'changed'        => $o->changed,
                ], $outcome['items']),
            ]);
        });
    }

    /**
     * @return array{domain_ids: list<int>, years: int, invoice_option: string, idempotency_key: string}|string a fixed error code
     */
    private function parseCreate(): array|string
    {
        $raw = trim((string) $this->request->getBody());

        if ($raw === '' || strlen($raw) > self::MAX_BODY_BYTES || ($this->request->getGet() ?? []) !== []) {
            return 'invalid_request';
        }

        $body = json_decode($raw, true);

        // Unknown keys are refused: a client can never supply a user id, order id, customer id, expiry or amount.
        if (! is_array($body) || array_is_list($body) || array_diff(array_keys($body), self::CREATE_FIELDS) !== []) {
            return 'invalid_request';
        }

        $ids = $body['domain_ids'] ?? null;

        if (! is_array($ids) || ! array_is_list($ids) || $ids === [] || count($ids) > 50) {
            return 'invalid_domain_ids';
        }

        foreach ($ids as $id) {
            if (! is_int($id) || $id < 1) {
                return 'invalid_domain_ids';
            }
        }

        if (count(array_unique($ids)) !== count($ids)) {
            return 'duplicate_domain';
        }

        $years = $body['years'] ?? null;

        if (! is_int($years) || ! RenewalQuoteService::validYears($years)) {
            return 'invalid_years';
        }

        $option = $body['invoice_option'] ?? null;

        if (! is_string($option) || ! in_array($option, ['KeepInvoice', 'NoInvoice'], true)) {
            return 'invalid_invoice_option';
        }

        $key = $body['idempotency_key'] ?? null;

        if (! is_string($key) || preg_match('/^[A-Za-z0-9_-]{16,64}$/', $key) !== 1) {
            return 'invalid_idempotency_key';
        }

        return ['domain_ids' => array_values($ids), 'years' => $years, 'invoice_option' => $option, 'idempotency_key' => $key];
    }

    private function renewalError(RenewalException $e): ResponseInterface
    {
        return match ($e->errorCode()) {
            'invalid_idempotency_key', 'invalid_invoice_option', 'invalid_years', 'invalid_batch_size', 'invalid_line', 'duplicate_domain'
                         => $this->error(400, $e->errorCode()),
            'idempotency_conflict', 'duplicate_cycle'
                         => $this->error(409, $e->errorCode()),
            'domain_not_found', 'domain_missing', 'invalid_order_id', 'customer_unknown', 'expiry_unknown', 'invalid_amount', 'invalid_currency',
            'domain_not_active'
                         => $this->error(422, $e->errorCode()),
            'invalid_user' => $this->error(401, 'unauthorized'),
            default      => $this->error(500, 'server_error'),
        };
    }

    private function userId(): int
    {
        $user = service('authService')->currentUser();

        // The auth filter guarantees a user; this is a defensive stop, never a fallback id.
        if (! is_array($user) || ! is_int($user['id'] ?? null)) {
            throw new RenewalException('invalid_user');
        }

        return $user['id'];
    }

    private function batchId(string $id): ?int
    {
        return preg_match('/^[1-9][0-9]{0,17}$/', $id) === 1 ? (int) $id : null;
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
        } catch (RenewalException $e) {
            return $this->renewalError($e);
        } catch (Throwable $e) {
            // Class name only: database messages can contain SQL and data.
            log_message('error', 'Renewal batch request failed: {class}', ['class' => $e::class]);

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

    /**
     * @param array<string, mixed> $extra
     */
    private function error(int $status, string $code, array $extra = []): ResponseInterface
    {
        return $this->json($status, ['error' => $code] + $extra);
    }
}
