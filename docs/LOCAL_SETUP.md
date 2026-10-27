# Local Setup and Development Guide

Status: Phase 1 (backend skeleton). See `PROJECT_PLAN.md` for architecture and phases.

## 1. PHP 8.2 compatibility decisions

- Target runtime is PHP 8.2.12 (XAMPP, `D:\xampp\php\php.exe`).
- The `php` on PATH is 8.5.5. Do not use it for this project.
- `backend/composer.json` sets `config.platform.php` to `8.2.12`, so Composer only resolves packages that run on 8.2.12 even if run by a newer PHP.
- CodeIgniter 4.7.5 requires `php ^8.2` and the `intl` and `mbstring` extensions. All required extensions are present in XAMPP's PHP (intl, mbstring, json, mysqlnd, curl, libxml, openssl, fileinfo, mysqli, pdo_mysql, dom, xml, xmlwriter, tokenizer, ctype, zip). `composer check-platform-reqs` passes.
- No global PHP, Apache or Composer configuration was changed.

Always run backend commands through the XAMPP binary. In PowerShell, from `backend/`:

```powershell
$php = "D:\xampp\php\php.exe"
$composer = "$env:LOCALAPPDATA\ComposerSetup\bin\composer.phar"
```

## 2. Backend setup

```powershell
cd D:\xampp\htdocs\reseller-domain-manager\backend
& $php $composer install          # restores vendor/ from composer.lock
Copy-Item .env.example .env       # then edit .env with your real local values
```

`.env` is git-ignored. Never commit it. Never put ResellerClub credentials anywhere except `backend/.env`.

Run the API locally (bound to localhost only):

```powershell
& $php spark serve --host 127.0.0.1 --port 8080
```

Do not serve the project from Apache's `htdocs` root. If Apache is used later, point a virtual host's document root at `backend/public` so `.env` can never be reached over HTTP.

## 3. Health endpoint

`GET /api/v1/health` returns HTTP 200 with:

```json
{"status":"ok","time":"2026-10-27T10:00:00Z"}
```

It needs no authentication, returns no version, environment, database or configuration information, and sends `Cache-Control: no-store`.

## 4. Tests

```powershell
cd D:\xampp\htdocs\reseller-domain-manager\backend
& $php vendor\phpunit\phpunit\phpunit
# or
& $php $composer test
```

A "No code coverage driver available" warning is expected (no Xdebug/PCOV installed) and is harmless.

Current tests: configuration sanity (`tests/unit/HealthTest.php`) and the health endpoint, root route not exposed, and no registration routes (`tests/feature/HealthEndpointTest.php`). These tests need no database.

## 5. Database setup (not yet applied)

Findings (read-only inspection):
- The local MariaDB holds many unrelated databases belonging to other applications. They must not be touched.
- `reseller_domain_manager` does not exist yet, and no user named for it exists.
- The MariaDB `root` account accepted a connection without a password on this machine. This is the XAMPP default and a local-only risk; do not expose MariaDB beyond localhost.

Proposed SQL, to be run only after explicit approval, as `root` in the MariaDB shell. Choose a strong password; do not paste it into documentation or Git.

```sql
CREATE DATABASE `reseller_domain_manager`
  CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;

CREATE USER 'reseller_dm_app'@'localhost' IDENTIFIED BY '<strong-password-chosen-by-owner>';
CREATE USER 'reseller_dm_app'@'127.0.0.1' IDENTIFIED BY '<strong-password-chosen-by-owner>';

GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, INDEX, DROP, REFERENCES
  ON `reseller_domain_manager`.* TO 'reseller_dm_app'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, INDEX, DROP, REFERENCES
  ON `reseller_domain_manager`.* TO 'reseller_dm_app'@'127.0.0.1';
```

Impact: creates one new empty database and one new user whose privileges are limited to that database only. No existing database, table or user is modified. `CREATE/ALTER/DROP/INDEX` are needed for migrations in development; in production they would be reduced to data privileges plus a separate migration user.

An optional second database, `reseller_domain_manager_test`, can be added later for automated database tests.

## 6. Git workflow

- One repository: `backend/`, `mobile/`, `docs/`.
- Remote: `origin` = `https://github.com/sivakumar-harsha/reseller-domain-manager.git`. At setup time it was empty (no commits, no default branch) and public. Because it is public, never commit secrets, `.env`, signing keys or database dumps.
- Local branch: `main`.
- Work on short-lived feature branches (for example `phase-1-backend`) and merge to `main` through reviewed pull requests once branch protection is chosen.
- Commits and pushes happen only after the owner reviews the staged file list and approves.
- No force-push, no history rewriting, no `git reset --hard` on shared history.
- Before each commit: run the tests and a secret-pattern scan.

## 7. Remaining decisions and risks

Decisions: D1 hosting (deferred), D3 Firebase push, D6 notification lead times and time zone, D7 raw provider JSON storage, and approval of the database SQL above. Resolved: D2 monorepo, D4 Composer platform pin to 8.2.12, D5 TOTP optional, D8 database name `reseller_domain_manager`.

Risks:
- The GitHub repository is public; a leaked secret in any commit is permanent in practice.
- Passwordless local MariaDB `root`.
- The ResellerClub API details (rate limits, field names, IP whitelisting, renewal behaviour) are still to be verified against the official documentation.
- Authentication (password hashing, rate limiting, lockout, tokens) is not implemented yet; the API currently has only the health route.
