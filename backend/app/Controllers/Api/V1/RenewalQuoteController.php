<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1;

use App\Controllers\BaseController;
use App\Libraries\Pricing\RenewalQuoteService;
use App\Libraries\Renewal\RenewalEligibility;
use App\Libraries\ResellerClub\ResellerClubClient;
use App\Libraries\ResellerClub\ResellerClubException;
use App\Models\DomainModel;
use CodeIgniter\HTTP\ResponseInterface;
use DateTimeImmutable;
use DateTimeZone;
use Throwable;

/**
 * Read-only renewal price quote for one stored domain. Protected by the `auth`
 * filter in the route definitions. The domain comes from the stored row (never
 * from the request body), the order id is not read or returned, and nothing is
 * renewed, invoiced or charged.
 */
class RenewalQuoteController extends BaseController
{
    /** `GET domains/{id}/renewal-quote?years=1` */
    public function show(string $id = ''): ResponseInterface
    {
        try {
            $years = $this->request->getGet('years') ?? '1';

            if (trim((string) $this->request->getBody()) !== ''
                || array_diff(array_keys($this->request->getGet() ?? []), ['years']) !== []
                || ! is_string($years) || preg_match('/^[0-9]{1,2}$/', $years) !== 1
                || ! RenewalQuoteService::validYears((int) $years)) {
                return $this->json(400, ['error' => 'invalid_request']);
            }

            $row = strlen($id) <= 18
                ? (new DomainModel())->select('id, domain_name, status, expires_at, is_missing')->where('id', (int) $id)->first()
                : null;

            if ($row === null) {
                return $this->json(404, ['error' => 'not_found']);
            }

            $service = new RenewalQuoteService(new ResellerClubClient(config('ResellerClub')), config('ResellerClub'));

            $quote = $service->quote((string) $row['domain_name'], (int) $years, (bool) $row['is_missing']);

            // The same eligibility rule batch creation and execution apply. A price is still shown
            // for an ineligible domain so the app can explain why it cannot be renewed.
            $quote['eligibility'] = RenewalEligibility::evaluate(
                $row['status'] === null ? null : (string) $row['status'],
                $row['expires_at'] === null ? null : (new DateTimeImmutable((string) $row['expires_at'], new DateTimeZone('UTC')))->getTimestamp(),
                time(),
            );

            return $this->json(200, $quote);
        } catch (ResellerClubException $e) {
            // Fixed codes only; the exception carries no provider text.
            return match ($e->getMessage()) {
                'not_configured' => $this->json(503, ['error' => 'provider_not_configured']),
                default          => $this->json(502, ['error' => 'provider_error']),
            };
        } catch (Throwable $e) {
            log_message('error', 'Renewal quote request failed: {class}', ['class' => $e::class]);

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
