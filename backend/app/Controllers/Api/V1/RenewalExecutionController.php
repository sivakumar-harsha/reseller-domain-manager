<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1;

use App\Controllers\BaseController;
use App\Libraries\Renewal\RenewalBatchReader;
use App\Libraries\Renewal\RenewalException;
use CodeIgniter\HTTP\ResponseInterface;
use Throwable;

/**
 * Manual renewal of ONE explicitly selected item:
 * `POST renewal-batches/{batchId}/items/{itemId}/renew`.
 *
 * Protected by the `auth` filter and a strict throttle in the route definition.
 * The user is ALWAYS the authenticated user. The body is a whitelist of
 * confirmation values that must equal the stored batch/item snapshot; it can
 * never supply a user, order, customer, expiry or amount.
 *
 * Nothing is renewed unless the execution switch is on, debit verification is
 * on, the confirmation matches, the read-only preflight passes and the global
 * single-flight lock is free. Both invoice options execute the renewal; the
 * option only controls customer invoice handling. Failed, submitting and
 * uncertain items are never retried or resubmitted: a repeat call reports the
 * stored state. The amounts returned are ESTIMATES, never a final invoice.
 *
 * Responses carry fixed codes only: no SQL, exceptions, credentials, provider
 * text or the live balance.
 */
class RenewalExecutionController extends BaseController
{
    private const MAX_BODY_BYTES = 2048;

    private const FIELDS = ['acknowledge_charge', 'confirm_domain_name', 'confirm_total_amount', 'confirm_currency', 'confirm_years', 'confirm_invoice_option'];

    public function renew(string $batchId = '', string $itemId = ''): ResponseInterface
    {
        // A dropped connection must not abandon a half-recorded result.
        ignore_user_abort(true);

        try {
            $batch = $this->id($batchId);
            $item  = $this->id($itemId);
            $input = $this->parse();

            if ($batch === null || $item === null) {
                return $this->error(404, 'not_found');
            }

            if (is_string($input)) {
                return $this->error($input === 'confirmation_mismatch' ? 422 : 400, $input);
            }

            $userId = $this->userId();
            $result = service('renewalExecutionGateway')->renewItem($userId, $batch, $item, $input);
            $out    = $result['item'];

            $view = (new RenewalBatchReader())->findForUser($userId, $batch);

            if ($view === null) {
                return $this->error(404, 'not_found');
            }

            $stored   = null;

            foreach ($view['items'] as $candidate) {
                if ($candidate['id'] === $item) {
                    $stored = $candidate;
                }
            }

            $body = [
                'replayed' => $out->skipped,
                'item'     => [
                    'id'                  => $out->itemId,
                    'state'               => $out->state,
                    'error_code'          => $stored['error_code'] ?? $out->errorCode,
                    'provider_message'    => $stored['provider_message'] ?? null,
                    'provider_action_id'  => $stored['provider_action_id'] ?? $out->actionId,
                    'provider_invoice_id' => $stored['provider_invoice_id'] ?? $out->invoiceId,
                ],
                'batch'    => $view,
                'amount'   => [
                    'estimated_total' => $input['total_amount'],
                    'currency'        => $input['currency'],
                    'basis'           => 'estimated_inclusive_of_gst_not_final_invoice',
                ],
                'next'     => in_array($out->state, ['submitting', 'uncertain'], true) ? 'reconcile' : 'none',
            ];

            if ($out->skipped) {
                // Nothing was sent by this call. A stored success is a clean replay; any other state is a conflict.
                return $out->state === 'succeeded'
                    ? $this->json(200, $body)
                    : $this->json(409, ['error' => 'item_not_pending', 'item' => $body['item'], 'next' => $body['next']]);
            }

            // The request ran. A definite result is 200 (read item.state); an unsure one is 202 and needs reconciliation.
            return $this->json(in_array($out->state, ['succeeded', 'failed'], true) ? 200 : 202, $body);
        } catch (RenewalException $e) {
            return $this->renewalError($e);
        } catch (Throwable $e) {
            // Class name only: database messages can contain SQL and data.
            log_message('error', 'Renewal execution request failed: {class}', ['class' => $e::class]);

            return $this->error(500, 'server_error');
        }
    }

    /**
     * @return array{domain_name: string, total_amount: string, currency: string, years: int, invoice_option: string}|string a fixed error code
     */
    private function parse(): array|string
    {
        $raw = trim((string) $this->request->getBody());

        if ($raw === '' || strlen($raw) > self::MAX_BODY_BYTES || ($this->request->getGet() ?? []) !== []) {
            return 'invalid_request';
        }

        $body = json_decode($raw, true);

        if (! is_array($body) || array_is_list($body) || array_diff(array_keys($body), self::FIELDS) !== []) {
            return 'invalid_request';
        }

        foreach (self::FIELDS as $field) {
            if (! array_key_exists($field, $body)) {
                return 'invalid_request';
            }
        }

        // The literal JSON true, never a truthy string or number.
        if ($body['acknowledge_charge'] !== true) {
            return 'confirmation_mismatch';
        }

        if (! is_string($body['confirm_domain_name']) || ! is_string($body['confirm_total_amount'])
            || ! is_string($body['confirm_currency']) || ! is_int($body['confirm_years']) || ! is_string($body['confirm_invoice_option'])) {
            return 'invalid_request';
        }

        return [
            'domain_name'    => $body['confirm_domain_name'],
            'total_amount'   => $body['confirm_total_amount'],
            'currency'       => $body['confirm_currency'],
            'years'          => $body['confirm_years'],
            'invoice_option' => $body['confirm_invoice_option'],
        ];
    }

    private function renewalError(RenewalException $e): ResponseInterface
    {
        return match ($e->errorCode()) {
            'not_found'    => $this->error(404, 'not_found'),
            'invalid_user' => $this->error(401, 'unauthorized'),
            'confirmation_mismatch' => $this->error(422, 'confirmation_mismatch'),
            'execution_limit_exceeded' => $this->error(400, 'execution_limit_exceeded'),
            'execution_disabled', 'balance_currency_unverified', 'balance_currency_mismatch', 'balance_unavailable',
            'price_unavailable', 'not_configured'
                           => $this->error(503, $e->errorCode() === 'not_configured' ? 'provider_not_configured' : $e->errorCode()),
            'insufficient_balance', 'price_changed', 'batch_expired', 'item_not_pending', 'execution_in_progress'
                           => $this->error(409, $e->errorCode()),
            default        => $this->error(500, 'server_error'),
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

    private function id(string $id): ?int
    {
        return preg_match('/^[1-9][0-9]{0,17}$/', $id) === 1 ? (int) $id : null;
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
