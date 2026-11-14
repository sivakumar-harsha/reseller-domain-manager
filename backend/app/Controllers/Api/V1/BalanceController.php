<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1;

use App\Controllers\BaseController;
use App\Libraries\ResellerClub\ResellerClubClient;
use App\Libraries\ResellerClub\ResellerClubException;
use CodeIgniter\HTTP\ResponseInterface;
use Throwable;

/**
 * Read-only reseller balance. Protected by the `auth` filter in the route
 * definitions. Returns the four mapped balance fields only: never the raw
 * provider response, credentials or provider message text.
 */
class BalanceController extends BaseController
{
    /** `GET balance` */
    public function show(): ResponseInterface
    {
        try {
            if (trim((string) $this->request->getBody()) !== '' || $this->request->getGet() !== []) {
                return $this->json(400, ['error' => 'invalid_request']);
            }

            return $this->json(200, (new ResellerClubClient(config('ResellerClub')))->getBalance());
        } catch (ResellerClubException $e) {
            // Fixed codes only; the exception carries no provider text.
            return match ($e->getMessage()) {
                'not_configured' => $this->json(503, ['error' => 'provider_not_configured']),
                default          => $this->json(502, ['error' => 'provider_error']),
            };
        } catch (Throwable $e) {
            log_message('error', 'Balance request failed: {class}', ['class' => $e::class]);

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
