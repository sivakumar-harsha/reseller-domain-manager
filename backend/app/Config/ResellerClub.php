<?php

declare(strict_types=1);

namespace Config;

use CodeIgniter\Config\BaseConfig;

/**
 * ResellerClub access settings. The three credentials come only from the
 * backend `.env` (resellerclub.baseUrl, resellerclub.authUserId,
 * resellerclub.apiKey); the defaults here are deliberately empty.
 * Read-only use only: the client has no write methods.
 */
class ResellerClub extends BaseConfig
{
    /** HTTPS base of the HTTP API including the /api prefix, no trailing slash. */
    public string $baseUrl = '';

    public string $authUserId = '';

    public string $apiKey = '';

    /** Documented range for domains/search.json is 10 to 500. */
    public int $pageSize = 100;

    /** Seconds per HTTP request. */
    public int $timeout = 30;

    /** Hard stop against a runaway pagination loop (pageSize * maxPages records). */
    public int $maxPages = 200;

    /** Attempts per page for network errors, HTTP 429 and HTTP 5xx. */
    public int $maxAttempts = 3;

    /**
     * Currency of reseller-cost-price.json amounts. The response carries no
     * currency; INR is the value observed on this account (the .com one-year
     * renewal cost matches the control panel's INR figure). Override with
     * resellerclub.costPriceCurrency in the backend `.env`.
     */
    public string $costPriceCurrency = 'INR';

    /** Seconds the extracted renewal cost prices stay cached. */
    public int $costPriceCacheTtl = 21600;

    /** Pause between pages; the provider's rate limits are not yet verified. */
    public int $pageDelayMs = 300;

    /**
     * Renewal tax rates in percent, as decimal strings (e.g. "18", "12.5").
     * ResellerClub support confirmed the cost-price API excludes GST and that
     * GST is 18% for this India reseller account. Override with
     * resellerclub.renewalGstRate / resellerclub.renewalVatRate, or the plain
     * RENEWAL_GST_RATE / RENEWAL_VAT_RATE variables, in the backend `.env`.
     * Server-side only; never exposed to the app as an editable setting.
     */
    public string $renewalGstRate = '18';

    public string $renewalVatRate = '0';

    /**
     * Master safety switch for domain renewal EXECUTION (the POST to
     * domains/renew.json, which spends real money). OFF by default and enabled
     * only by the exact value RENEWAL_EXECUTION_ENABLED=true in the backend
     * `.env` (any other value, a missing value or the generic
     * resellerclub.renewalExecutionEnabled binding leaves it off). Read-only
     * quotation never looks at it.
     */
    public bool $renewalExecutionEnabled = false;

    /**
     * Values of the provider `actionstatus` field. Compared EXACTLY (case and
     * spelling) and only after the request was really answered.
     *
     * `Success` is verified: historical RenewDomain actions returned by
     * actions/search-archived.json carry `actionstatus: Success` with the
     * description "Domain renewed successfully". NO failure or pending value has
     * been verified, so the failure list stays EMPTY on purpose: any other
     * status is "uncertain" (never success, never a definite failure) and needs
     * manual review. Do not add guessed values here.
     *
     * @var list<string>
     */
    public array $renewalSuccessStatuses = ['Success'];

    /** @var list<string> */
    public array $renewalFailureStatuses = [];

    /**
     * Hard ceiling, in seconds, on the age of a renewal batch at EXECUTION
     * time (age = now - created_at). An older batch is refused with
     * `batch_expired` and needs a new quote and a new manual confirmation.
     * Owner-approved value: 900 (15 minutes).
     */
    public int $renewalBatchMaxAgeSeconds = 900;

    /**
     * Maximum renewal items ONE execution request may process. Owner-approved
     * value: 1. A request asking for more is refused with
     * `execution_limit_exceeded`; a larger batch takes one request per domain.
     */
    public int $renewalMaxItemsPerExecution = 1;

    /**
     * The only currency the renewal balance gate will compare in. Both the
     * quote currency and the provider's accounting balance currency must equal
     * this EXACT string, or execution is refused (`balance_currency_mismatch`).
     * No conversion is ever performed. Override with RENEWAL_DEBIT_CURRENCY in
     * the backend `.env` once the provider's actual symbol is observed.
     */
    public string $renewalDebitCurrency = 'INR';

    /**
     * Fail-closed verification flag: whether the ACTUAL renewal debit currency
     * and GST/debit treatment have been confirmed with ResellerClub for this
     * account. While false, every execution attempt stops with
     * `balance_currency_unverified` BEFORE any item is claimed. Off by default
     * and enabled only by the exact value RENEWAL_DEBIT_VERIFIED=true in the
     * backend `.env` (same fail-closed pattern as the execution switch).
     *
     * Still unverified as of 2026-11-10: whether the selling (USD) or
     * accounting (INR) balance is debited, whether the debit includes GST, and
     * the exact accountingcurrencysymbol literal this account returns.
     */
    public bool $renewalDebitVerified = false;

    /**
     * Seconds a renewal item may sit in `submitting` before reconciliation
     * treats it as stuck (marks it `uncertain` for manual review). The POST has
     * a 30 s timeout and verification a few more requests, so 15 minutes is far
     * beyond a live attempt. Reconciliation never resends and never releases the
     * duplicate-cycle guard.
     */
    public int $renewalStuckAfterSeconds = 900;

    public function __construct()
    {
        parent::__construct();

        // Fail closed: ignore any generic env binding of the property above and
        // accept only the dedicated variable with the exact value "true".
        $this->renewalExecutionEnabled = false;
        $switch                        = env('RENEWAL_EXECUTION_ENABLED');

        // env() turns "true" into boolean true, so accept both forms (strict).
        if ($switch === true || (is_string($switch) && strtolower(trim($switch)) === 'true')) {
            $this->renewalExecutionEnabled = true;
        }

        // Same fail-closed pattern for the debit-verification flag: ignore any
        // generic binding and accept only the dedicated variable.
        $this->renewalDebitVerified = false;
        $verified                   = env('RENEWAL_DEBIT_VERIFIED');

        if ($verified === true || (is_string($verified) && strtolower(trim($verified)) === 'true')) {
            $this->renewalDebitVerified = true;
        }

        $currency = env('RENEWAL_DEBIT_CURRENCY');

        if (is_string($currency) && trim($currency) !== '') {
            $this->renewalDebitCurrency = trim($currency);
        }

        foreach (['renewalGstRate' => 'RENEWAL_GST_RATE', 'renewalVatRate' => 'RENEWAL_VAT_RATE'] as $property => $name) {
            $value = env($name);

            if (is_string($value) && trim($value) !== '') {
                $this->{$property} = trim($value);
            }
        }
    }
}
