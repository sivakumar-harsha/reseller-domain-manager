<?php

declare(strict_types=1);

namespace App\Libraries\Renewal;

/**
 * Result of one processItem() call. `skipped` means this call did nothing
 * because the item was not pending (already claimed or finished), so no
 * provider request was made by this call.
 */
final class RenewalItemOutcome
{
    private function __construct(
        public readonly int $itemId,
        public readonly string $state,
        public readonly ?string $errorCode,
        public readonly bool $skipped,
        public readonly ?string $actionId = null,
        public readonly ?string $invoiceId = null,
    ) {
    }

    public static function skipped(int $itemId, string $currentState): self
    {
        return new self($itemId, $currentState, null, true);
    }

    public static function decided(int $itemId, string $state, ?string $errorCode, ?string $actionId = null, ?string $invoiceId = null): self
    {
        return new self($itemId, $state, $errorCode, false, $actionId, $invoiceId);
    }
}
