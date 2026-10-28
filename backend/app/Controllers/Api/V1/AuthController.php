<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1;

use App\Controllers\BaseController;
use App\Libraries\Auth\AuthOutcome;
use CodeIgniter\HTTP\ResponseInterface;
use Throwable;

/**
 * Login, token refresh, logout. There is deliberately no registration action:
 * the owner is created with `php spark auth:create-owner`.
 *
 * Request bodies, passwords and tokens are never logged; unexpected failures
 * are logged by exception class only and answered with a generic message.
 */
class AuthController extends BaseController
{
    private const MAX_BODY_BYTES = 4096;

    public function login(): ResponseInterface
    {
        return $this->guard(function (): ResponseInterface {
            $in = $this->jsonInput();

            if ($in === null || ! $this->isString($in['email'] ?? null, 254) || ! $this->isString($in['password'] ?? null, 1024)) {
                return $this->error(400, 'invalid_request');
            }

            $label = $in['device_label'] ?? null;

            if ($label !== null && ! is_string($label)) {
                return $this->error(400, 'invalid_request');
            }

            return $this->respond(service('authService')->login($in['email'], $in['password'], $label));
        });
    }

    public function refresh(): ResponseInterface
    {
        return $this->guard(function (): ResponseInterface {
            $in = $this->jsonInput();

            if ($in === null || ! $this->isString($in['refresh_token'] ?? null, 128)) {
                return $this->error(400, 'invalid_request');
            }

            return $this->respond(service('authService')->refresh($in['refresh_token']));
        });
    }

    /**
     * Revokes the whole token family of the given refresh token and/or the
     * bearer access token. Always answers 204 so it reveals nothing.
     */
    public function logout(): ResponseInterface
    {
        return $this->guard(function (): ResponseInterface {
            $in      = $this->jsonInput() ?? [];
            $refresh = isset($in['refresh_token']) && $this->isString($in['refresh_token'], 128) ? $in['refresh_token'] : null;

            service('authService')->logout($refresh, $this->bearerToken());

            return $this->response->setStatusCode(204)->setHeader('Cache-Control', 'no-store');
        });
    }

    /** Protected by the `auth` filter; returns the authenticated owner. */
    public function me(): ResponseInterface
    {
        return $this->guard(function (): ResponseInterface {
            $user = service('authService')->currentUser();

            return $this->json(200, ['id' => $user['id'], 'email' => $user['email']]);
        });
    }

    private function respond(AuthOutcome $outcome): ResponseInterface
    {
        return match ($outcome->code) {
            AuthOutcome::OK                  => $this->json(200, $outcome->tokens),
            AuthOutcome::ACCOUNT_LOCKED      => $this->error(429, 'account_locked', $outcome->retryAfter),
            AuthOutcome::INVALID_TOKEN       => $this->error(401, 'invalid_token'),
            default                          => $this->error(401, 'invalid_credentials'),
        };
    }

    /**
     * @param callable(): ResponseInterface $action
     */
    private function guard(callable $action): ResponseInterface
    {
        try {
            return $action();
        } catch (Throwable $e) {
            // Class name only: messages from the database layer can contain SQL and data.
            log_message('error', 'Authentication request failed: {class}', ['class' => $e::class]);

            return $this->error(500, 'server_error');
        }
    }

    /**
     * @return array<string, mixed>|null null when the body is missing, too large or not a JSON object
     */
    private function jsonInput(): ?array
    {
        $body = (string) $this->request->getBody();

        if ($body === '' || strlen($body) > self::MAX_BODY_BYTES) {
            return null;
        }

        $data = json_decode($body, true);

        return is_array($data) && ! array_is_list($data) ? $data : null;
    }

    private function isString(mixed $value, int $max): bool
    {
        return is_string($value) && $value !== '' && strlen($value) <= $max;
    }

    private function bearerToken(): ?string
    {
        if (preg_match('/^Bearer ([A-Za-z0-9_-]{43})$/', $this->request->getHeaderLine('Authorization'), $m) === 1) {
            return $m[1];
        }

        return null;
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

    private function error(int $status, string $code, ?int $retryAfter = null): ResponseInterface
    {
        $response = $this->json($status, ['error' => $code]);

        if ($retryAfter !== null) {
            $response->setHeader('Retry-After', (string) $retryAfter);
        }

        return $response;
    }
}
