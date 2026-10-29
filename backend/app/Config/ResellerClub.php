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

    /** Pause between pages; the provider's rate limits are not yet verified. */
    public int $pageDelayMs = 300;
}
