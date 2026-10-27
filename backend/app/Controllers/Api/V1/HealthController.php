<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1;

use App\Controllers\BaseController;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Unauthenticated liveness check. Deliberately exposes nothing about the
 * environment, configuration, database or software versions.
 */
class HealthController extends BaseController
{
    public function index(): ResponseInterface
    {
        return $this->response
            ->setHeader('Cache-Control', 'no-store')
            ->setJSON([
                'status' => 'ok',
                'time'   => gmdate('Y-m-d\TH:i:s\Z'),
            ]);
    }
}
