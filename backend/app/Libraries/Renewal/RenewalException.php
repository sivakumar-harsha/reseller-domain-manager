<?php

declare(strict_types=1);

namespace App\Libraries\Renewal;

use RuntimeException;

/**
 * A refusal by the renewal service. The message is always one fixed code (for
 * example execution_disabled, invalid_years, duplicate_cycle); it never carries
 * provider text, credentials or request data.
 *
 * Preflight codes (all raised BEFORE any item is claimed; every item stays
 * `pending` and nothing is sent to the provider):
 * - insufficient_balance          fresh accounting balance below the estimated total
 * - balance_currency_mismatch     quote, snapshot or balance currency differs from the configured debit currency
 * - balance_currency_unverified   the actual debit currency / GST treatment is not yet verified (renewalDebitVerified=false)
 * - balance_unavailable           the fresh balance could not be fetched or parsed
 * - price_changed                 the fresh renewal price is higher than the confirmed snapshot
 * - price_unavailable             the fresh renewal price could not be fetched or validated
 * - batch_expired                 the batch is older than renewalBatchMaxAgeSeconds
 * - execution_limit_exceeded      more items requested than renewalMaxItemsPerExecution allows
 *
 * Eligibility codes (RenewalEligibility; raised by batch creation, nothing stored):
 * - domain_not_active            the stored status is not exactly "Active" (suspended, locked, deleted, redemption, unknown ...)
 * (An expiry date in the past is not refused locally: ResellerClub decides.)
 *
 * Phase 2 manual execution codes (all raised before the claim, nothing sent):
 * - confirmation_mismatch         the confirmation does not equal the stored item/batch snapshot
 * - item_not_pending              the named item is not pending (never resubmitted)
 * - execution_in_progress         another renewal holds the single-flight lock (or this connection lost it)
 */
final class RenewalException extends RuntimeException
{
    public function __construct(string $code)
    {
        parent::__construct($code);
    }

    public function errorCode(): string
    {
        return $this->getMessage();
    }
}
