<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1;

use App\Controllers\BaseController;
use App\Models\DomainModel;
use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\I18n\Time;
use DateTimeImmutable;
use Throwable;

/**
 * Read-only expiry dashboard data. Protected by the `auth` filter in the route
 * definitions. Takes no input; the counts come from one aggregate query.
 */
class ExpiryController extends BaseController
{
    /** `GET expiry/summary` */
    public function summary(): ResponseInterface
    {
        try {
            if (trim((string) $this->request->getBody()) !== '' || $this->request->getGet() !== []) {
                return $this->json(400, ['error' => 'invalid_request']);
            }

            // Time::now() honours Time::setTestNow(), which keeps the boundaries testable.
            $now = DateTimeImmutable::createFromInterface(Time::now('UTC'));

            return $this->json(200, (new DomainModel())->expirySummary($now));
        } catch (Throwable $e) {
            // Class name only: database messages can contain SQL and data.
            log_message('error', 'Expiry summary failed: {class}', ['class' => $e::class]);

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
