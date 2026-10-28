<?php

declare(strict_types=1);

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Throwable;

/**
 * Requires a valid bearer access token. The failure response is the same
 * whether the token is missing, malformed, expired or revoked.
 */
class AuthFilter implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        $service = service('authService');
        $service->setCurrentUser(null);

        $user = null;

        if (preg_match('/^Bearer ([A-Za-z0-9_-]{43})$/', $request->getHeaderLine('Authorization'), $m) === 1) {
            try {
                $user = $service->authenticate($m[1]);
            } catch (Throwable $e) {
                log_message('error', 'Token validation failed: {class}', ['class' => $e::class]);

                return service('response')
                    ->setStatusCode(500)
                    ->setHeader('Cache-Control', 'no-store')
                    ->setJSON(['error' => 'server_error']);
            }
        }

        if ($user === null) {
            return service('response')
                ->setStatusCode(401)
                ->setHeader('WWW-Authenticate', 'Bearer')
                ->setHeader('Cache-Control', 'no-store')
                ->setJSON(['error' => 'unauthorized']);
        }

        $service->setCurrentUser($user);

        return null;
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        return null;
    }
}
