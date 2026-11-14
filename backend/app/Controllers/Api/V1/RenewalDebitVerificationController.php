<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1;

use App\Controllers\BaseController;
use App\Libraries\Renewal\RenewalBatchReader;
use App\Libraries\Renewal\RenewalDebitVerifier;
use App\Libraries\Renewal\RenewalException;
use CodeIgniter\HTTP\ResponseInterface;
use Throwable;

/**
 * Manual debit verification of ONE renewal item:
 * `POST renewal-batches/{batchId}/items/{itemId}/debit-verification`.
 *
 * Protected by the `auth` filter and a throttle in the route definition; the
 * user is always the authenticated user. It records what an operator found in
 * ResellerClub's transaction history. It holds only RenewalDebitVerifier and
 * the read-only batch reader: it can never renew a domain, call the provider
 * or change the renewal state.
 */
class RenewalDebitVerificationController extends BaseController
{
    private const MAX_BODY_BYTES = 4096;

    private const FIELDS = ['status', 'transaction_id', 'amount', 'currency', 'note'];

    public function verify(string $batchId = '', string $itemId = ''): ResponseInterface
    {
        try {
            $batch = $this->id($batchId);
            $item  = $this->id($itemId);

            if ($batch === null || $item === null) {
                return $this->error(404, 'not_found');
            }

            $input = $this->parse();

            if ($input === null) {
                return $this->error(400, 'invalid_request');
            }

            $user = service('authService')->currentUser();

            if (! is_array($user) || ! is_int($user['id'] ?? null)) {
                return $this->error(401, 'unauthorized');
            }

            $result = $this->verifier()->record($user['id'], $batch, $item, $input);
            $view   = (new RenewalBatchReader())->findForUser($user['id'], $batch);
            $stored = null;

            foreach (($view['items'] ?? []) as $candidate) {
                if ($candidate['id'] === $item) {
                    $stored = $candidate;
                }
            }

            return $this->json(200, ['replayed' => $result['replayed'], 'item' => $stored, 'batch' => $view]);
        } catch (RenewalException $e) {
            return match ($e->errorCode()) {
                'not_found'                      => $this->error(404, 'not_found'),
                'invalid_request'                => $this->error(400, 'invalid_request'),
                'verification_evidence_required' => $this->error(422, 'verification_evidence_required'),
                'item_not_succeeded', 'already_confirmed', 'verification_conflict'
                                                 => $this->error(409, $e->errorCode()),
                default                          => $this->error(500, 'server_error'),
            };
        } catch (Throwable $e) {
            log_message('error', 'Debit verification request failed: {class}', ['class' => $e::class]);

            return $this->error(500, 'server_error');
        }
    }

    protected function verifier(): RenewalDebitVerifier
    {
        return new RenewalDebitVerifier();
    }

    /** @return array<string, mixed>|null */
    private function parse(): ?array
    {
        $raw = trim((string) $this->request->getBody());

        if ($raw === '' || strlen($raw) > self::MAX_BODY_BYTES || ($this->request->getGet() ?? []) !== []) {
            return null;
        }

        $body = json_decode($raw, true);

        if (! is_array($body) || array_is_list($body) || array_diff(array_keys($body), self::FIELDS) !== [] || ! array_key_exists('status', $body)) {
            return null;
        }

        return $body;
    }

    private function id(string $id): ?int
    {
        return preg_match('/^[1-9][0-9]{0,17}$/', $id) === 1 ? (int) $id : null;
    }

    /** @param array<string, mixed> $data */
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
