# Local Setup and Development Guide

Status: backend skeleton, local database and Phase 3 migrations (`sync_runs`, `domains`) in place; Phase 3 complete (isolated schema tests: 11 tests, 52 assertions, 0 skipped). See `PROJECT_PLAN.md` for architecture and phases.

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

Exit code: `phpunit.dist.xml` sets `failOnWarning="true"` and contains a `<coverage>` section, but no coverage driver (Xdebug/PCOV) is installed. PHPUnit therefore reports the runner warning "No code coverage driver available" and, because warnings fail the run, exits with code 1 even though every test passed ("OK, but there were issues!"). This is the only cause. For a clean exit code without coverage, run:

```powershell
& $php vendor\phpunit\phpunit\phpunit --no-coverage tests\unit tests\feature
```

(`--no-coverage` states that coverage is not being collected; `failOnWarning` stays on for real warnings.) `composer test` still runs plain `phpunit` and exits 1 until a driver is installed or the script is changed. `tests/database` is excluded from the command above on purpose.

Authentication tests: `tests/unit/AuthStaticTest.php` (no database) and, on the isolated test database, `tests/database/AuthSchemaTest.php`, `AuthServiceTest.php`, `AuthEndpointsTest.php` and `CreateOwnerCommandTest.php`. They create and drop only `users` and `refresh_tokens` there. Run the whole suite with `& $php vendor\phpunit\phpunit\phpunit --no-coverage`. See `AUTHENTICATION.md`.

Current tests: configuration sanity (`tests/unit/HealthTest.php`) and the health endpoint, root route not exposed, and no registration routes (`tests/feature/HealthEndpointTest.php`). These tests need no database.

Migration tests:
- `tests/unit/MigrationFilesTest.php` is static (no database). It checks the migration filenames and order on disk, that each migration implements its own `up()` and `down()`, and that they contain no destructive or raw SQL.
- `tests/database/SchemaConstraintsTest.php` applies the real migrations and checks the constraints (unique keys, NOT NULL, ENUMs, the foreign key and `ON DELETE SET NULL`, indexes). It needs a separately configured, isolated MariaDB test database. With the default configuration all of its tests are reported as skipped, which means the constraints were not checked. With the isolated database configured it passed: 11 tests, 52 assertions, 0 failures, 0 skipped. Run it with:

```powershell
& $php vendor\phpunit\phpunit\phpunit --no-coverage tests\database
```

The owner created `reseller_domain_manager_test` and a user limited to it. For reference, the settings required in `backend/.env` are:

```
database.tests.hostname = 127.0.0.1
database.tests.database = reseller_domain_manager_test
database.tests.username = <test user>
database.tests.password = <test password>
database.tests.DBDriver = MySQLi
database.tests.DBPrefix =
database.tests.charset = utf8mb4
database.tests.DBCollat = utf8mb4_general_ci
```

The test refuses to run (skips) unless the driver, `_test` suffix, charset, collation and empty prefix all match, and if `sync_runs` or `domains` already exist. It creates and drops only those two tables. Note that the local MariaDB's wildcard grants on `test` and `test\_%` are a separate unresolved hardening item. The `tests` group defaults to in-memory SQLite3, which this PHP does not load, so the tests skip.

Never point `database.tests.*` at the development database or another application's database.

## 5. Database setup

Sub-sections: 5.1 current state (done), 5.2 how to apply migrations and the Phase 3 schema, 5.3 pending steps, 5.4 historical record (do not re-run).

### 5.1 Current state (completed)

The database `reseller_domain_manager` and the user `reseller_dm_app` were created locally (Phase 2), with the privileges listed in 5.4 on that database only. Credentials live only in the git-ignored `backend/.env`. Do not create them again.

### 5.2 Applying migrations

```powershell
cd D:\xampp\htdocs\reseller-domain-manager\backend
& $php spark migrate            # apply pending migrations to the configured database
& $php spark migrate:status     # show what has run
```

`& $php spark migrate:rollback` undoes the latest batch and deletes that data. Do not use it casually on the development database.

Phase 3 schema and SQL mode:

SQL mode: `app/Config/Database.php` sets `strictOn = true`, so each application connection adds `STRICT_ALL_TABLES`. The server default here is non-strict. Over-long or invalid values are therefore rejected rather than truncated or coerced; see `PROJECT_PLAN.md` section 4.1.

Tables from Phase 3: `sync_runs` (history of read-only sync attempts; column `trigger_type`) and `domains` (local cache of the domain inventory). `migrate:rollback` drops them in reverse order and deletes their data; `sync_runs` history cannot be rebuilt from ResellerClub.

### 5.3 Pending steps (not done, owner decisions)

- (Done) The isolated `*_test` database and its user exist; the schema tests passed (section 4).
- The MariaDB `root` account has no password (XAMPP default), and wildcard grants let any user use `test` and `test\_%`. Both are unresolved local-hardening items; this project has not changed them.

### 5.4 Historical record: original proposal (ALREADY EXECUTED, DO NOT RE-RUN)

Findings at the time (read-only inspection, before Phase 2):
- The local MariaDB holds many unrelated databases belonging to other applications. They must not be touched.
- `reseller_domain_manager` did not exist yet, and no user named for it existed.
- The MariaDB `root` account accepted a connection without a password on this machine.

The SQL below was the reviewed proposal that was run once in Phase 2. It is kept for reference only; running it again would fail because the objects exist. The password placeholder stands for a generated value that is stored only in `backend/.env`.

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

Impact of that SQL: one new empty database and one new user limited to that database. `CREATE/ALTER/DROP/INDEX` are needed for migrations in development; in production they would be reduced to data privileges plus a separate migration user. The optional `reseller_domain_manager_test` database is a pending step (5.3), not part of this record.

## 6. Git workflow

- One repository: `backend/`, `mobile/`, `docs/`.
- Remote: `origin` = `https://github.com/sivakumar-harsha/reseller-domain-manager.git`. At setup time it was empty (no commits, no default branch) and public. Because it is public, never commit secrets, `.env`, signing keys or database dumps.
- Local branch: `main`.
- Work on short-lived feature branches (for example `phase-1-backend`) and merge to `main` through reviewed pull requests once branch protection is chosen.
- Commits and pushes happen only after the owner reviews the staged file list and approves.
- No force-push, no history rewriting, no `git reset --hard` on shared history.
- Before each commit: run the tests and a secret-pattern scan.

## 7. Remaining decisions and risks

Decisions: D1 hosting (deferred), D3 Firebase push, D6 notification lead times and time zone, D7 raw provider JSON storage, D9 sync-phase decisions (overlapping runs and abandoned `running` records, domain upsert key, NULL-safe missing-domain detection; see `PROJECT_PLAN.md` section 4.2). Resolved: D2 monorepo, D4 Composer platform pin to 8.2.12, D5 TOTP optional, D8 database created, D10 isolated test database (schema tests passed).

Risks:
- The GitHub repository is public; a leaked secret in any commit is permanent in practice.
- Passwordless local MariaDB `root`.
- The ResellerClub API details (rate limits, field names, IP whitelisting, renewal behaviour) are still to be verified against the official documentation.
- Authentication (password hashing, rate limiting, lockout, tokens) is not implemented yet; the API currently has only the health route.
