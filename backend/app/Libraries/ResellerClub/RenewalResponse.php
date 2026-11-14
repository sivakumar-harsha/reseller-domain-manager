<?php

declare(strict_types=1);

namespace App\Libraries\ResellerClub;

/**
 * What the provider's answer to a renewal POST means, after the request was
 * actually sent. Three outcomes only:
 *
 * - SUCCEEDED: the provider reported an action status configured as success.
 * - FAILED: a DEFINITE rejection (the provider reported an error, or an HTTP
 *   4xx other than 408/429, or a configured failure status): nothing was renewed.
 * - UNCERTAIN: anything else (timeout, transport error, HTTP 5xx/429/3xx, an
 *   unparseable body, or an accepted response whose action status is not a
 *   configured success or failure value). The renewal may or may not have
 *   happened and the request must never be sent again automatically.
 *
 * Only fixed codes, sanitised provider identifiers and the provider's short
 * explanation (control characters removed, capped at 200 characters, the API
 * key masked) are kept. The raw body is never retained.
 */
final class RenewalResponse
{
    public const SUCCEEDED = 'succeeded';
    public const FAILED    = 'failed';
    public const UNCERTAIN = 'uncertain';

    private function __construct(
        public readonly string $outcome,
        public readonly ?string $errorCode,
        public readonly ?string $actionId = null,
        public readonly ?string $actionStatus = null,
        public readonly ?string $actionDescription = null,
        public readonly ?string $invoiceId = null,
    ) {
    }

    public static function succeeded(string $actionId, string $actionStatus, ?string $description, ?string $invoiceId): self
    {
        return new self(self::SUCCEEDED, null, $actionId, $actionStatus, $description, $invoiceId);
    }

    public static function failed(string $errorCode, ?string $actionId = null, ?string $actionStatus = null, ?string $description = null): self
    {
        return new self(self::FAILED, $errorCode, $actionId, $actionStatus, $description);
    }

    public static function uncertain(string $errorCode, ?string $actionId = null, ?string $actionStatus = null, ?string $description = null, ?string $invoiceId = null): self
    {
        return new self(self::UNCERTAIN, $errorCode, $actionId, $actionStatus, $description, $invoiceId);
    }
}
