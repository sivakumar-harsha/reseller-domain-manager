# ResellerClub Domain Manager — Project Plan

Status: Phase 3 database foundation COMPLETE (`sync_runs` and `domains` migrations, strict SQL mode; isolated schema tests passed: 11 tests, 52 assertions, 0 skipped). Note: this "Phase 3" is the working label for the database-foundation step, which belongs to Phase 1 and 2 in the table in section 7; the table's own Phase 3 is the Flutter app. Backend authentication is implemented and tested locally (see `AUTHENTICATION.md`; migrations for `users` and `refresh_tokens` not yet applied to the development database). Sync service and app are not implemented yet.
Last updated: 2026-10-27

Note: the phase numbers in section 7 are the original plan; the work sessions have so far been numbered separately (session phases 1 to 3 covered the skeleton, local database setup and the two migrations below).

Items marked **VERIFY** come from general knowledge of the ResellerClub API and must be confirmed against the official documentation before use.

## 1. Purpose and requirements

A personal Android app to manage 100+ domains held in one ResellerClub account. Single owner, no public registration.

1. Dashboard: domain totals and expiry summaries.
2. Searchable, filterable domain inventory.
3. Domain details (status, expiry and other fields the API provides).
4. Expiry reminders and notifications.
5. Synchronisation history and error reporting.
6. Manual domain renewal (later phase, after explicit confirmation, subject to API capability and account permissions). Registration is out of scope unless added later.
7. Secure remote access, deferred until after local development and testing.

## 2. Architecture and data flow

```
Flutter app --HTTPS + token--> CodeIgniter 4 API --> MariaDB (cache, owner account, sync history)
                                     |
                                     +--HTTPS (server side only)--> ResellerClub API
```

Rules:
- Flutter talks only to the CodeIgniter backend.
- Only the backend talks to ResellerClub.
- ResellerClub credentials exist only in backend environment configuration.
- The app reads cached data from MariaDB. ResellerClub remains the authoritative source; the UI shows "last synced at".
- Synchronisation runs from a CLI command (`php spark ...`), triggered manually at first and scheduled later. The app may request a rate-limited manual sync.
- The first versions are read-only.

## 3. Repository layout

```
reseller-domain-manager/
  backend/   CodeIgniter 4 REST API
  mobile/    Flutter Android app
  docs/      documentation (this file)
  .gitignore
```

Only `backend/public/` is ever to be web-exposed. For local development, use an Apache virtual host with its document root at `backend/public`, or `php spark serve`, so `.env` is never reachable through `htdocs`.

## 4. Database design (proposal)

MariaDB 10.4.32, InnoDB, utf8mb4. All instants stored in UTC.

- `users`: id, email (unique), password_hash, totp_secret_enc (nullable), is_active, failed_logins, locked_until, timestamps. Exactly one owner row, created by a CLI command.
- `refresh_tokens`: id, user_id, token_hash, family_id, device_label, expires_at, revoked_at, last_used_at.
- `domains` (IMPLEMENTED, migration `2026-10-27-120100_CreateDomains`): local cache of the domain inventory; ResellerClub stays authoritative. Columns: id, rc_order_id (unique), domain_name (unique, case-insensitive collation; lowercase is an application rule), rc_customer_id, status, registered_at, expires_at, auto_renew, privacy_enabled, registrar_lock, nameservers_json, content_hash, first_seen_at, last_synced_at, last_seen_sync_id, is_missing, timestamps. Provider-derived columns are nullable (NULL = unknown); their names and meanings are NOT yet verified against official ResellerClub responses. Indexes on expires_at, status; unique keys cover rc_order_id and domain_name. `last_seen_sync_id` is a foreign key to `sync_runs.id` with ON DELETE SET NULL, so pruning old runs keeps the domain rows.
- `sync_runs` (IMPLEMENTED, migration `2026-10-27-120000_CreateSyncRuns`): one row per read-only synchronisation attempt, as history and audit. Columns: id, `trigger_type` (manual/scheduled; named `trigger_type` instead of the earlier plan's `trigger`, to avoid the SQL keyword TRIGGER), status (running/success/partial/failed, default running), started_at, finished_at (nullable), pages_fetched, records_seen, inserted, updated, unchanged, error_count (unsigned counters, default 0), error_summary (nullable, sanitised), timestamps. Indexes on started_at, status.
- `sync_errors`: id, sync_run_id, scope, http_status, error_code, message (sanitised), retryable, created_at.
- `device_tokens` and `notification_log`: unique key on (domain_id, expiry_date, lead_days) so each reminder is sent once.
- `audit_log`: id, user_id, action, target, ip, created_at.
- Renewal phase only: `renewal_requests` (see section 9).

Raw provider payloads are stored only if explicitly approved (decision D7), and never with credentials.

### 4.1 Strict SQL mode

`backend/app/Config/Database.php` sets `strictOn = true` on the default connection, so each connection adds `STRICT_ALL_TABLES` to the session `sql_mode`. The local MariaDB server default is non-strict, and without this change invalid ENUM values, NULL in NOT NULL columns and over-long strings would be silently coerced or truncated. Practical implications:
- A provider value longer than its column (for example `status` over 64 characters or `domain_name` over 253) makes the INSERT/UPDATE fail instead of being truncated. The sync code must validate or deliberately truncate and record the problem.
- It applies to every connection through the default group, including any future production connection.
- It is a per-session setting and does not change the server configuration.

### 4.2 Open design decisions for the synchronisation phase

These are NOT resolved. They must be decided and recorded before the sync service is written.
- **Overlapping runs and abandoned `running` records.** The schema has no lock and no constraint tying `status` to `finished_at`. Decide how to prevent a manual and a scheduled run overlapping (for example a database lock or a single-active-run check) and how to treat a `running` row left behind by a crash (for example mark it `failed` after a timeout, before starting a new run).
- **Definitive domain upsert key.** Both `rc_order_id` and `domain_name` are unique. Which one identifies a domain, and what happens when a domain is transferred, re-registered or renamed under a new order ID, depends on how the official ResellerClub API responses behave. Pending verification against the official documentation and real responses; until then neither is declared the key.
- **Missing-domain detection when `last_seen_sync_id` is NULL.** `ON DELETE SET NULL` clears the pointer when old runs are pruned, and `NULL <> run_id` evaluates to NULL, not true, so a naive `last_seen_sync_id <> :current` filter would skip those rows. Detection must handle NULL explicitly (for example `last_seen_sync_id IS NULL OR last_seen_sync_id <> :current`, or a null-safe comparison) or rely on other fields, and only run after a successful complete sync.
- **Meaning of `last_synced_at`.** It is set per row when that row is fetched, so a row touched by a run that later fails or is partial still has a fresh value. Define what the UI means by "last synced" before building it.

## 5. Authentication and security

- Single owner account created via CLI. No registration endpoint exists.
- Email and password login, optional TOTP (decision D5).
- Short-lived access token (about 15 minutes) and rotating refresh token (about 30 days). Refresh tokens are stored hashed. Reuse of a rotated token revokes its whole family.
- Login rate limiting per IP and per account, with lockout and backoff.
- Flutter stores tokens in `flutter_secure_storage` (Android Keystore). Never SharedPreferences.
- Production mode in the backend so errors never leak configuration. Logs never contain credentials, tokens, full request URLs or request bodies.
- ResellerClub credentials: backend `.env` only (git-ignored). `.env.example` holds placeholders only. A single client class reads them and redacts them from any log.
- A secret scan is run before each commit (tool choice pending; Phase 0 used a manual pattern scan).
- The owner password is never written to documentation or Git.

## 6. Local development and testing

- Backend runs under XAMPP PHP 8.2.12; MariaDB from XAMPP; local database named for this project only (the XAMPP folder holds unrelated databases, so no existing database is touched).
- HTTP is acceptable locally. The Android emulator reaches the host at `10.0.2.2`. A physical device needs the PC's LAN address. Cleartext HTTP must be allowed only in debug builds.
- Sync is tested first against a small page size, then the full account, read-only.
- PHP version strategy:
  - Target runtime is PHP 8.2.
  - The CLI on PATH is 8.5.5 and Composer runs on it.
  - Planned (to be confirmed in Phase 1 before changing anything): set `config.platform.php` to `8.2.12` in `backend/composer.json`, and run `spark` and Composer through `D:\xampp\php\php.exe` so CLI behaviour matches Apache.
  - Phase 1 first checks that the project's dependency set resolves under that platform setting.
- No system, PATH, XAMPP, Java or Flutter configuration changes are planned.

## 7. Development phases and acceptance criteria

| Phase | Scope | Acceptance criteria |
|---|---|---|
| 0 | Repository, `.gitignore`, this plan | Git initialised; layout in place; no secrets in the repository; plan reviewed and approved |
| 1 | CI4 skeleton in `backend/`, PHP 8.2 platform pinning, local database and migrations, owner CLI command, auth (login, refresh, logout), rate limiting, health endpoint | Runs on XAMPP PHP 8.2; Composer resolves under the 8.2 platform; login and token rotation work; lockout works; no registration route exists; tests pass |
| 2 | Read-only sync service, `sync_runs`, `sync_errors`, CLI command | Full paginated list synced; re-run creates no duplicates; a simulated failure yields `partial`; no secrets in logs; no write calls exist in the client |
| 3 | Flutter app: login, dashboard, inventory with search and filters, details, sync history | Works against the local backend; search and filters correct on 100+ domains; tokens only in Keystore |
| 4 | Expiry reminders | Each lead time sent once per domain per expiry; time zones shown correctly |
| 5 | Hardening, backups, release signing | Test coverage on auth and sync; signed release APK built locally; restore tested |
| 6 | Deployment (deferred) | See section 8 |
| 7 | Renewal | See section 9 |

## 8. Future live deployment requirements

Hosting is deferred until local development and testing are complete. No provider is selected. Requirements when chosen:
- Valid HTTPS certificate and HSTS; Android release builds use HTTPS only.
- PHP 8.2 or newer matching the tested version; MariaDB bound to localhost.
- A scheduler (cron) for sync and notifications.
- A stable outbound IP if ResellerClub requires IP whitelisting (**VERIFY** for this account).
- Off-server database backups with a tested restore, firewall, monitoring.
- `.env` supplied outside the web root; document root set to `backend/public`.
- Local XAMPP alone is insufficient for reliable remote access: the PC must stay on and reachable, home IPs and ISPs are unreliable, XAMPP defaults are not hardened, and there is no backup or monitoring.

## 9. Future renewal requirements and safety controls

Not implemented until the official documentation is verified and the owner approves.

To VERIFY before implementation:
- The renewal operation (likely `domains/renew.json`, POST) and its required parameters (likely order id, years, current expiry as epoch, invoice option).
- Pricing lookup, currency, and whether payment is taken from the reseller balance or invoiced; behaviour on insufficient funds.
- Per-TLD renewal limits, grace and redemption periods and fees.
- Success, failure and timeout response formats; how to confirm the new expiry afterwards.
- Whether a sandbox or demo environment exists.

Safety controls:
1. Feature flag, off by default, plus a separate `can_renew` permission. Sync code uses a client with no write methods.
2. Two-step flow: `prepare` re-reads live expiry and price and stores a `renewal_requests` row (`pending_confirmation`, short expiry). `confirm` requires the owner to retype the domain name and re-authenticate (password or biometric).
3. Idempotency key per request; one active request per domain enforced by the database.
4. Before sending, re-fetch the expiry and price; abort if either changed from what was confirmed.
5. Ambiguous or timed-out responses become `unknown`, are never auto-retried, and are reconciled by a read-only check of the expiry.
6. Limits on years per request and on daily renewals or spend.
7. Every state transition recorded in `audit_log`.
8. First live renewal tested on one low-cost domain with explicit approval.

## 10. Outstanding decisions and risks

Decisions needing approval:
- D1: Hosting option and API domain (deferred).
- D2: Monorepo (chosen for now) versus separate repositories.
- D3: Firebase Cloud Messaging for push notifications, with local notifications as fallback.
- D4: RESOLVED. Composer platform pinned to PHP 8.2.12 (Phase 1; see `LOCAL_SETUP.md`).
- D5: RESOLVED. TOTP is optional for now and may be added later.
- D6: Notification lead times and display time zone (default assumption Asia/Kolkata).
- D7: Store full raw provider JSON or selected fields only.
- D8: Database `reseller_domain_manager` and the dedicated least-privilege user were created locally (see `LOCAL_SETUP.md`).
- D9: Open. Sync-phase decisions listed in section 4.2 (overlap and stale runs, upsert key, NULL-safe missing detection, meaning of `last_synced_at`).
- D10: RESOLVED. The isolated `reseller_domain_manager_test` database and a dedicated user were created by the owner. `tests/database` ran against it: 11 tests, 52 assertions, 0 failures, 0 skipped (see `LOCAL_SETUP.md`).

Risks:
- The ResellerClub API detail is unverified: rate limits, page-size limits, field names, IP whitelisting and renewal behaviour.
- The PHP 8.2 / 8.5.5 mismatch could pull in dependencies that Apache's PHP cannot run.
- Exposing `backend/.env` if the project is served from `htdocs` with the wrong document root.
- Loss of the Android signing key makes updates impossible; back it up securely.
- The XAMPP installation holds unrelated databases; use a dedicated database and user.
- The account's live data is real: sync must stay strictly read-only.
