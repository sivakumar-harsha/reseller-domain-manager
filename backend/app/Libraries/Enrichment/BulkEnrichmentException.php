<?php

declare(strict_types=1);

namespace App\Libraries\Enrichment;

use RuntimeException;

/**
 * A bulk-enrichment request that cannot proceed. Carries a fixed code only.
 */
final class BulkEnrichmentException extends RuntimeException
{
    public function __construct(public readonly string $errorCode, public readonly ?string $batchId = null)
    {
        parent::__construct($errorCode);
    }
}
