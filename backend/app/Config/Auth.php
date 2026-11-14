<?php

declare(strict_types=1);

namespace Config;

use CodeIgniter\Config\BaseConfig;

/**
 * Authentication settings. Override with `auth.<property>` in .env if needed.
 * See docs/PROJECT_PLAN.md section 5 and docs/AUTHENTICATION.md.
 */
class Auth extends BaseConfig
{
    /** Access token lifetime in seconds (plan: about 15 minutes). */
    public int $accessTtl = 900;

    /** Refresh token lifetime in seconds (plan: about 30 days). */
    public int $refreshTtl = 2592000;

    /** Failed passwords for one account before it is locked. */
    public int $maxFailures = 5;

    /** First lock duration in seconds; doubles with each further failure. */
    public int $lockBaseSeconds = 60;

    /** Upper bound for a lock in seconds. */
    public int $lockMaxSeconds = 3600;

    /** Seconds over which each per-IP bucket refills completely. */
    public int $ipWindow = 60;

    /**
     * Requests per IP per window, by bucket.
     *
     * @var array<string, int>
     */
    public array $ipLimits = [
        'login'   => 10,
        'refresh' => 30,
        'logout'  => 30,
        'enrich'  => 10,
        'enrich_batch' => 120,
        'inventory_sync' => 6,
        'balance' => 30,
        'renewal_quote' => 30,
        'renewal_batch_create' => 20,
        'renewal_batch_read' => 120,
        'renewal_reconcile' => 12,
        'renewal_execute' => 5,
        'renewal_debit_verify' => 20,
    ];

    /** Owner password length bounds in bytes (bcrypt ignores input after 72 bytes). */
    public int $minPasswordLength = 12;
    public int $maxPasswordLength = 72;
}
