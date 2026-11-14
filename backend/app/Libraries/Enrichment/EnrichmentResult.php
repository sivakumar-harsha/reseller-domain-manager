<?php

declare(strict_types=1);

namespace App\Libraries\Enrichment;

/**
 * Outcome of enriching one domain. Counters and fixed codes only: no contact
 * data, no provider text, no secrets.
 */
final class EnrichmentResult
{
    public const SUCCESS           = 'success';
    public const NOT_CONFIGURED    = 'not_configured';
    public const NOT_FOUND         = 'not_found';
    public const CUSTOMER_UNKNOWN  = 'customer_unknown';
    public const CUSTOMER_MISMATCH = 'customer_mismatch';
    public const PROVIDER_ERROR    = 'provider_error';
    public const STORAGE_ERROR     = 'storage_error';

    public function __construct(
        public readonly string $status,
        public readonly int $contactsCreated = 0,
        public readonly int $contactsUpdated = 0,
        public readonly int $rolesLinked = 0,
        public readonly int $rolesChanged = 0,
        public readonly int $rolesPreserved = 0,
        public readonly int $warnings = 0,
        public readonly bool $nameserversUpdated = false,
        public readonly ?string $detailsSyncedAt = null,
        public readonly ?string $providerCode = null,
    ) {
    }

    public function succeeded(): bool
    {
        return $this->status === self::SUCCESS;
    }

    public static function failed(string $status, ?string $providerCode = null): self
    {
        return new self($status, providerCode: $providerCode);
    }
}
