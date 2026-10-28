<?php

declare(strict_types=1);

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Per-IP rate limit (token bucket in the application cache). Usage in a
 * route: ['filter' => 'throttle:login']. The bucket name selects the limit in
 * Config\Auth::$ipLimits. Per-account lockout lives in AuthService.
 *
 * Only the client address is used; no request data is read or stored.
 */
class ThrottleFilter implements FilterInterface
{
    public static function key(string $bucket, string $ip): string
    {
        return 'auththrottle' . hash('sha256', $bucket . '|' . $ip);
    }

    public function before(RequestInterface $request, $arguments = null)
    {
        $config = config('Auth');
        $bucket = (string) ($arguments[0] ?? 'login');
        $limit  = $config->ipLimits[$bucket] ?? null;

        if ($limit === null) {
            // Misconfiguration must not silently disable the limit.
            return service('response')->setStatusCode(500)->setJSON(['error' => 'server_error']);
        }

        $throttler = service('throttler');

        if ($throttler->check(self::key($bucket, $request->getIPAddress()), $limit, $config->ipWindow)) {
            return null;
        }

        return service('response')
            ->setStatusCode(429)
            ->setHeader('Retry-After', (string) $throttler->getTokenTime())
            ->setHeader('Cache-Control', 'no-store')
            ->setJSON(['error' => 'too_many_requests']);
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        return null;
    }
}
