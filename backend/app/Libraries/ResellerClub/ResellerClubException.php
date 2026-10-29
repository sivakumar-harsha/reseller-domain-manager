<?php

declare(strict_types=1);

namespace App\Libraries\ResellerClub;

use RuntimeException;

/**
 * A provider failure. The message is a short fixed code (for example
 * "http_503" or "malformed_response"), never provider text, a URL or a
 * credential, so it is safe to log and to store in sync_runs.error_summary.
 */
final class ResellerClubException extends RuntimeException
{
    public function __construct(string $code, public readonly bool $retryable = false)
    {
        parent::__construct($code);
    }
}
