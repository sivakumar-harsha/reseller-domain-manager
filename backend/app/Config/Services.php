<?php

namespace Config;

use CodeIgniter\Config\BaseService;

/**
 * Services Configuration file.
 *
 * Services are simply other classes/libraries that the system uses
 * to do its job. This is used by CodeIgniter to allow the core of the
 * framework to be swapped out easily without affecting the usage within
 * the rest of your application.
 *
 * This file holds any application-specific services, or service overrides
 * that you might need. An example has been included with the general
 * method format you should use for your service methods. For more examples,
 * see the core Services file at system/Config/Services.php.
 */
class Services extends BaseService
{
    public static function authService(bool $getShared = true): \App\Libraries\Auth\AuthService
    {
        if ($getShared) {
            return static::getSharedInstance('authService');
        }

        return new \App\Libraries\Auth\AuthService();
    }

    public static function domainEnrichmentService(bool $getShared = true): \App\Libraries\Enrichment\DomainDetailsEnrichmentService
    {
        if ($getShared) {
            return static::getSharedInstance('domainEnrichmentService');
        }

        return new \App\Libraries\Enrichment\DomainDetailsEnrichmentService(
            \Config\Database::connect(),
            new \App\Libraries\ResellerClub\ResellerClubClient(config('ResellerClub')),
        );
    }

    public static function domainSyncService(bool $getShared = true): \App\Libraries\Sync\DomainSyncService
    {
        if ($getShared) {
            return static::getSharedInstance('domainSyncService');
        }

        $config = config('ResellerClub');

        return new \App\Libraries\Sync\DomainSyncService(
            \Config\Database::connect(),
            new \App\Libraries\ResellerClub\ResellerClubClient($config),
            $config,
        );
    }

    public static function bulkEnrichmentService(bool $getShared = true): \App\Libraries\Enrichment\BulkEnrichmentService
    {
        if ($getShared) {
            return static::getSharedInstance('bulkEnrichmentService');
        }

        return new \App\Libraries\Enrichment\BulkEnrichmentService(
            \Config\Database::connect(),
            static::domainEnrichmentService(),
            WRITEPATH . 'enrichment_batches',
            sync: static::domainSyncService(),
        );
    }

    /** Server-side renewal price quotes (read-only provider GETs); used to snapshot batch amounts. */
    public static function renewalQuoteService(bool $getShared = true): \App\Libraries\Pricing\RenewalQuoteService
    {
        if ($getShared) {
            return static::getSharedInstance('renewalQuoteService');
        }

        return new \App\Libraries\Pricing\RenewalQuoteService(
            new \App\Libraries\ResellerClub\ResellerClubClient(config('ResellerClub')),
            config('ResellerClub'),
        );
    }

    /**
     * Stores renewal batches (local pending rows only). The gateway exposes
     * ONLY createBatch() and findExistingBatch(), so the API controller holds
     * no object that could execute a renewal. There is no execution route.
     */
    public static function renewalBatchService(bool $getShared = true): \App\Libraries\Renewal\RenewalBatchGateway
    {
        if ($getShared) {
            return static::getSharedInstance('renewalBatchService');
        }

        return new \App\Libraries\Renewal\RenewalBatchGateway(static::renewalExecutionService(false));
    }

    /**
     * Renewal EXECUTION (the only object able to reach the renewal POST).
     * Registered separately on purpose: NO controller or route uses it in
     * Phase 1, and the POST stays dead until RENEWAL_EXECUTION_ENABLED=true
     * AND RENEWAL_DEBIT_VERIFIED=true are deliberately set.
     */
    public static function renewalExecutionService(bool $getShared = true): \App\Libraries\Renewal\RenewalExecutionService
    {
        if ($getShared) {
            return static::getSharedInstance('renewalExecutionService');
        }

        return new \App\Libraries\Renewal\RenewalExecutionService(
            new \App\Libraries\ResellerClub\ResellerClubClient(config('ResellerClub')),
            config('ResellerClub'),
            \Config\Database::connect(),
            null,
            static::renewalPreflightService(false),
        );
    }

    /**
     * The narrow object RenewalExecutionController uses: ONE method, renewItem().
     * It still refuses everything unless RENEWAL_EXECUTION_ENABLED and
     * RENEWAL_DEBIT_VERIFIED are both deliberately on (both ship OFF).
     */
    public static function renewalExecutionGateway(bool $getShared = true): \App\Libraries\Renewal\RenewalExecutionGateway
    {
        if ($getShared) {
            return static::getSharedInstance('renewalExecutionGateway');
        }

        return new \App\Libraries\Renewal\RenewalExecutionGateway(static::renewalExecutionService(false));
    }

    /** Read-only pre-execution checks (two provider GETs, never a POST, never a write). */
    public static function renewalPreflightService(bool $getShared = true): \App\Libraries\Renewal\RenewalPreflightService
    {
        if ($getShared) {
            return static::getSharedInstance('renewalPreflightService');
        }

        return new \App\Libraries\Renewal\RenewalPreflightService(
            new \App\Libraries\ResellerClub\ResellerClubClient(config('ResellerClub')),
            config('ResellerClub'),
            static::renewalQuoteService(false),
            \Config\Database::connect(),
        );
    }

    /** Read-only reconciliation (provider GET lookups only). */
    public static function renewalReconciliationService(bool $getShared = true): \App\Libraries\Renewal\RenewalReconciliationService
    {
        if ($getShared) {
            return static::getSharedInstance('renewalReconciliationService');
        }

        return new \App\Libraries\Renewal\RenewalReconciliationService(
            new \App\Libraries\ResellerClub\ResellerClubClient(config('ResellerClub')),
            config('ResellerClub'),
            \Config\Database::connect(),
        );
    }

    /*
     * public static function example($getShared = true)
     * {
     *     if ($getShared) {
     *         return static::getSharedInstance('example');
     *     }
     *
     *     return new \CodeIgniter\Example();
     * }
     */
}
