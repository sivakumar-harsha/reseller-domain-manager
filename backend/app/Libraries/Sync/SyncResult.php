<?php

declare(strict_types=1);

namespace App\Libraries\Sync;

/**
 * Outcome of one sync attempt. Counters only; no provider data.
 *
 * `warnings` counts non-fatal findings (name conflicts, stored values kept
 * because the provider sent an unusable field). They do not make a run partial.
 *
 * `skipped` counts records with a valid order id but an unusable domain name.
 * They are not inserted and any existing row is only touched for tracking; they
 * always make the run partial.
 */
final class SyncResult
{
    public const NOT_CONFIGURED  = 'not_configured';
    public const ALREADY_RUNNING = 'already_running';

    /** The lock could not be taken because the database errored (not because another run holds it). */
    public const LOCK_ERROR = 'lock_error';

    /** The run could not be started: the sync_runs bookkeeping write failed. Nothing was synced. */
    public const STORAGE_ERROR = 'storage_error';

    /** The run finished but its result could not be written to sync_runs; `outcome` holds what it was. */
    public const RECORD_FAILED = 'record_failed';

    public function __construct(
        public readonly ?int $runId,
        public readonly string $status,
        public readonly int $pages = 0,
        public readonly int $seen = 0,
        public readonly int $inserted = 0,
        public readonly int $updated = 0,
        public readonly int $unchanged = 0,
        public readonly int $errors = 0,
        public readonly int $flaggedMissing = 0,
        public readonly int $warnings = 0,
        public readonly ?string $outcome = null,
        public readonly int $skipped = 0,
    ) {
    }

    /** No run record was created. */
    public static function notRun(string $reason): self
    {
        return new self(null, $reason);
    }

    public function ran(): bool
    {
        return $this->runId !== null;
    }
}
