<?php

declare(strict_types=1);

namespace App\Libraries\Renewal;

/**
 * Result of reconciling one renewal item against the provider's action lists.
 *
 * `result`:
 * - `succeeded`   the provider confirmed the renewal (exact `Success`); the item was moved to succeeded.
 * - `unresolved`  the provider could not confirm it; the item keeps (or moves to) a state that needs
 *                 manual review and its duplicate-protection slot stays occupied.
 * - `skipped`     nothing to reconcile (the item is not submitting/uncertain) or it may still be in flight.
 *
 * `reason` is a fixed code. `statusDescription` is the provider's description, sanitised
 * (control characters removed, length capped); it exists only in this in-memory result
 * (there is no column for it), and is never logged.
 */
final class ReconciliationOutcome
{
    public function __construct(
        public readonly int $itemId,
        public readonly string $previousState,
        public readonly string $state,
        public readonly string $result,
        public readonly string $reason,
        public readonly bool $changed,
        public readonly ?string $actionId = null,
        public readonly ?string $actionStatus = null,
        public readonly ?string $statusDescription = null,
    ) {
    }
}
