<?php

declare(strict_types=1);

namespace Tests\Support\Libraries;

use Config\ResellerClub as ResellerClubConfig;

/**
 * Builds a ResellerClub config that does NOT depend on the developer's real
 * `.env`. The two renewal safety flags are read from the environment in the
 * config constructor, so a developer who enabled them locally would otherwise
 * change what the tests see. The variables are hidden only for the duration of
 * the constructor and then restored exactly; `.env` is never touched and the
 * production constructor logic is exercised unchanged.
 */
final class IsolatedResellerClubConfig
{
    private const KEYS = [
        'RENEWAL_EXECUTION_ENABLED',
        'RENEWAL_DEBIT_VERIFIED',
        'RENEWAL_DEBIT_CURRENCY',
        'RENEWAL_GST_RATE',
        'RENEWAL_VAT_RATE',
    ];

    /** A config exactly as shipped: both safety flags OFF, no environment overrides. */
    public static function make(): ResellerClubConfig
    {
        $saved = [];

        foreach (self::KEYS as $key) {
            $saved[$key] = [$_ENV[$key] ?? null, $_SERVER[$key] ?? null, getenv($key)];
            unset($_ENV[$key], $_SERVER[$key]);
            putenv($key);
        }

        try {
            return new ResellerClubConfig();
        } finally {
            foreach ($saved as $key => [$e, $s, $g]) {
                if ($e !== null) {
                    $_ENV[$key] = $e;
                }

                if ($s !== null) {
                    $_SERVER[$key] = $s;
                }

                if ($g !== false) {
                    putenv($key . '=' . $g);
                }
            }
        }
    }
}
