<?php

declare(strict_types=1);

namespace App\Libraries\Auth;

/**
 * Result of an authentication operation. Failure codes are deliberately
 * coarse so that responses cannot reveal which part of a credential was wrong.
 */
final class AuthOutcome
{
    public const OK                  = 'ok';
    public const INVALID_CREDENTIALS = 'invalid_credentials';
    public const ACCOUNT_LOCKED      = 'account_locked';
    public const INVALID_TOKEN       = 'invalid_token';

    /**
     * @param array<string, mixed>|null $tokens
     */
    private function __construct(
        public readonly string $code,
        public readonly ?array $tokens = null,
        public readonly ?int $retryAfter = null,
    ) {
    }

    /**
     * @param array<string, mixed> $tokens
     */
    public static function ok(array $tokens): self
    {
        return new self(self::OK, $tokens);
    }

    public static function fail(string $code, ?int $retryAfter = null): self
    {
        return new self($code, null, $retryAfter);
    }

    public function isOk(): bool
    {
        return $this->code === self::OK;
    }
}
