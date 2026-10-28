# Authentication

Implements `PROJECT_PLAN.md` section 5 for the CodeIgniter 4 backend. Status: implemented and tested locally; not applied to the development database yet (see "Applying it").

## Endpoints (base `/api/v1`, JSON)

| Method and path | Body | Success | Failure |
|---|---|---|---|
| `POST /auth/login` | `email`, `password`, optional `device_label` | 200 `token_type`, `access_token`, `expires_in` (900), `refresh_token`, `refresh_expires_in` (2592000) | 400 `invalid_request`; 401 `invalid_credentials`; 429 `account_locked` (with `Retry-After`) or `too_many_requests` |
| `POST /auth/refresh` | `refresh_token` | 200 new token pair (old pair is dead) | 400; 401 `invalid_token`; 429 |
| `POST /auth/logout` | optional `refresh_token`, and/or `Authorization: Bearer <access>` | 204 always | 429 |
| `GET /auth/me` | `Authorization: Bearer <access>` | 200 `id`, `email` | 401 `unauthorized` |

Protect future routes with `['filter' => 'auth']`. There is **no registration or account-creation endpoint**. All auth responses send `Cache-Control: no-store`.

## Owner account (CLI only)

```powershell
cd D:\xampp\htdocs\reseller-domain-manager\backend
$env:RDM_OWNER_PASSWORD = '<12 to 72 bytes>'     # or omit it and type at the prompt (visible on screen)
& D:\xampp\php\php.exe spark auth:create-owner --email you@example.com
Remove-Item Env:RDM_OWNER_PASSWORD
```

The password is never a command-line argument, is never printed and is stored only as a `password_hash()` (bcrypt) value. The command refuses if any user already exists, which is how "exactly one owner" is enforced.

## Design decisions the plan left open, and why

1. **Opaque tokens instead of JWT.** No JWT library is installed and packages were not approved. Tokens are 256 bits from `random_bytes()`, base64url encoded. Only their SHA-256 is stored, so a database leak does not yield usable tokens (they are high-entropy, so a fast hash is appropriate; a slow hash is for passwords). They carry no data and are not signed, so no cryptographic scheme was invented. The cost is one indexed lookup per request, which is acceptable for a single-owner app and gives instant revocation. The `auth.jwtSecret` setting is therefore unused.
2. **Access token stored on the refresh row.** `refresh_tokens` has extra columns `access_token_hash`, `access_expires_at` and `rotated_at` (the plan listed only the refresh-token columns). This pairs each access token with its refresh token, so rotation, logout and reuse detection end the access token immediately without a second table.
3. **Rotation and reuse.** Each refresh token is single-use. Using it marks it rotated and inserts a successor in the same family. Presenting a rotated token again revokes the whole family and logs a warning containing only the user id. The row is locked (`SELECT ... FOR UPDATE`) in a transaction so concurrent refreshes cannot both succeed. There is no grace window, so a client that loses the response to a refresh must log in again.
4. **Lockout and backoff (per account).** 5 consecutive failures lock the account for 60 s; each further failure doubles the lock, capped at 1 hour. A success resets the counter. While locked, even the right password is refused (429 with `Retry-After`) and attempts do not extend the lock. Chosen so an online guesser gets about 5 tries then slows to a crawl, while the owner is never locked out for long. Values are in `Config\Auth` and can be overridden with `auth.*` in `.env`.
5. **Per-IP rate limit.** CodeIgniter's token-bucket Throttler in the application cache: 10 requests/min on login, 30 on refresh and logout, per client IP. It runs before any authentication code. Behind a reverse proxy, `Config\App::$proxyIPs` must be set at deployment or every client shares one bucket.
6. **No account enumeration.** Unknown email and wrong password give the same 401 body, and a dummy `password_verify` evens out timing. The 429 lock answer does reveal that the owner's email exists; accepted because it is a single-owner system and the email is not a secret.
7. **Passwords.** 12 to 72 bytes (bcrypt ignores input beyond 72). Login input is limited to 254/1024 bytes and bodies to 4 KB.
8. **Logging and errors.** Controllers catch every `Throwable`, log the class name only and answer `{"error":"server_error"}` (exception messages can contain SQL and values). Passwords, tokens, bodies and URLs are never passed to the logger; `tests/unit/AuthStaticTest.php` and `AuthEndpointsTest` check this. **Production deployments must set `CI_ENVIRONMENT = production`** so framework error pages show no configuration.
9. **Clock.** All instants are stored and compared in UTC.

## Not done / follow-ups

- Optional TOTP (decision D5; column `users.totp_secret_enc` is reserved).
- Purging expired and revoked `refresh_tokens` rows (no scheduled job yet).
- Token revocation for "log out everywhere" and password change are not implemented (no requirement yet).
- Production concerns: HTTPS, proxy IPs, a shared cache for the throttler if more than one server is used.

## Applying it

```powershell
cd D:\xampp\htdocs\reseller-domain-manager\backend
& D:\xampp\php\php.exe spark migrate          # creates users and refresh_tokens in the development database
```

This has **not** been run; the tests used only the isolated `reseller_domain_manager_test` database.

## Tests

`& D:\xampp\php\php.exe vendor\phpunit\phpunit\phpunit --no-coverage` runs everything. Database-backed tests (`tests/database/Auth*`, `CreateOwnerCommandTest`) skip unless the isolated test database is configured; see `LOCAL_SETUP.md` section 4.
