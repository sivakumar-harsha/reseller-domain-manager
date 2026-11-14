<?php

declare(strict_types=1);

use CodeIgniter\Test\CIUnitTestCase;

/**
 * Static checks on the migration files. These need no database.
 *
 * @internal
 */
final class MigrationFilesTest extends CIUnitTestCase
{
    private const EXPECTED = [
        '2026-10-27-120000_CreateSyncRuns.php' => ['App\Database\Migrations\CreateSyncRuns', 'sync_runs'],
        '2026-10-27-120100_CreateDomains.php'  => ['App\Database\Migrations\CreateDomains', 'domains'],
        '2026-10-28-100000_CreateUsers.php'    => ['App\Database\Migrations\CreateUsers', 'users'],
        '2026-10-28-100100_CreateRefreshTokens.php' => ['App\Database\Migrations\CreateRefreshTokens', 'refresh_tokens'],
        '2026-10-30-100000_CreateRcContacts.php'    => ['App\Database\Migrations\CreateRcContacts', 'rc_contacts'],
        '2026-10-30-100100_CreateDomainContacts.php' => ['App\Database\Migrations\CreateDomainContacts', 'domain_contacts'],
        '2026-10-30-100200_AddDetailsSyncedAtToDomains.php' => ['App\Database\Migrations\AddDetailsSyncedAtToDomains', 'domains'],
        '2026-11-09-100000_CreateRenewalBatches.php' => ['App\Database\Migrations\CreateRenewalBatches', 'renewal_batches'],
        '2026-11-09-100100_CreateRenewalItems.php'   => ['App\Database\Migrations\CreateRenewalItems', 'renewal_items'],
        '2026-11-11-100000_AddDebitVerificationToRenewalItems.php' => ['App\Database\Migrations\AddDebitVerificationToRenewalItems', 'renewal_items'],
        '2026-11-11-100100_CreateRenewalDebitVerifications.php' => ['App\Database\Migrations\CreateRenewalDebitVerifications', 'renewal_debit_verifications'],
        '2026-11-12-100000_AddProviderMessageToRenewalItems.php' => ['App\Database\Migrations\AddProviderMessageToRenewalItems', 'renewal_items'],
    ];

    /** Migrations that alter an existing table instead of creating one. */
    private const ALTERING = [
        '2026-10-30-100200_AddDetailsSyncedAtToDomains.php' => 'details_synced_at',
        '2026-11-12-100000_AddProviderMessageToRenewalItems.php' => 'provider_message',
    ];

    public function testMigrationFilesExistWithTimestampNames(): void
    {
        $files = array_map('basename', glob(APPPATH . 'Database/Migrations/*.php') ?: []);
        sort($files);

        $expected = array_keys(self::EXPECTED);
        sort($expected);

        $this->assertSame($expected, $files);
    }

    public function testFilenamesMatchTheCodeIgniterPatternAndRunInDependencyOrder(): void
    {
        // domains has a foreign key to sync_runs, refresh_tokens to users and
        // domain_contacts to domains and rc_contacts, so each parent table must
        // sort before its child.
        // Order is taken from the files actually on disk, in the same
        // lexical order the migration runner uses.
        $files = array_map('basename', glob(APPPATH . 'Database/Migrations/*.php') ?: []);
        sort($files, SORT_STRING);

        foreach ($files as $file) {
            $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}-\d{6}_[A-Z][A-Za-z0-9]+\.php$/', $file);
        }

        $this->assertCount(count(self::EXPECTED), $files);
        $this->assertSame(array_keys(self::EXPECTED), $files, 'Migration files are missing, misnamed or out of order.');

        $order = array_map(static fn (string $f): string => self::EXPECTED[$f][1], $files);
        $this->assertSame(
            ['sync_runs', 'domains', 'users', 'refresh_tokens', 'rc_contacts', 'domain_contacts', 'domains', 'renewal_batches', 'renewal_items', 'renewal_items', 'renewal_debit_verifications', 'renewal_items'],
            $order,
        );
    }

    public function testEachMigrationImplementsItsOwnUpAndDown(): void
    {
        foreach (self::EXPECTED as $file => [$class]) {
            require_once APPPATH . 'Database/Migrations/' . $file;

            $this->assertTrue(class_exists($class), $class);
            $this->assertTrue(is_subclass_of($class, \CodeIgniter\Database\Migration::class), $class);

            // The base class declares abstract up()/down(); each migration must
            // override both rather than merely inherit the declarations.
            foreach (['up', 'down'] as $method) {
                $declaring = (new ReflectionMethod($class, $method))->getDeclaringClass()->getName();

                $this->assertSame($class, $declaring, "{$class}::{$method} must be implemented by the migration itself");
                $this->assertFalse((new ReflectionMethod($class, $method))->isAbstract(), "{$class}::{$method}");
            }

            $this->assertSame(
                '_' . (new ReflectionClass($class))->getShortName(),
                (string) strstr(basename($file, '.php'), '_'),
                "{$file} must be named after class {$class}",
            );
        }
    }

    public function testMigrationsOnlyTouchTheirOwnTableAndAreNotDestructive(): void
    {
        foreach (self::EXPECTED as $file => [, $table]) {
            $source = (string) file_get_contents(APPPATH . 'Database/Migrations/' . $file);

            $this->assertDoesNotMatchRegularExpression('/\b(DROP\s+DATABASE|TRUNCATE|CREATE\s+DATABASE|GRANT|CREATE\s+USER)\b/i', $source, $file);

            if ($file === '2026-11-11-100000_AddDebitVerificationToRenewalItems.php') {
                $this->assertSame(0, substr_count($source, 'createTable('), $file);
                $this->assertSame(0, substr_count($source, 'dropTable('), $file);
                $this->assertSame(1, substr_count($source, "addColumn('{$table}'"), $file);
                $this->assertSame(1, substr_count($source, "dropColumn('{$table}'"), $file);
            } elseif (isset(self::ALTERING[$file])) {
                $column = self::ALTERING[$file];

                $this->assertSame(0, substr_count($source, 'createTable('), $file);
                $this->assertSame(0, substr_count($source, 'dropTable('), $file);
                $this->assertSame(1, substr_count($source, "addColumn('{$table}'"), $file);
                $this->assertSame(1, substr_count($source, "dropColumn('{$table}', '{$column}')"), $file);
                $this->assertSame(1, substr_count($source, "'{$column}'") - 1, $file . ' must add and drop only ' . $column);
            } else {
                $this->assertSame(1, substr_count($source, "createTable('{$table}'"), $file);
                $this->assertSame(1, substr_count($source, "dropTable('{$table}'"), $file);
            }

            $this->assertSame(0, preg_match('/->query\(/', $source), $file . ' must not run raw SQL');
        }
    }

    public function testDefaultConnectionTargetsApplicationDatabaseInStrictMode(): void
    {
        $default = config('Database')->default;

        $this->assertTrue($default['strictOn']);
        $this->assertSame('MySQLi', $default['DBDriver']);
        $this->assertSame('utf8mb4', $default['charset']);
        $this->assertSame('utf8mb4_general_ci', $default['DBCollat']);
    }

    public function testRcContactsMigrationHasTheApprovedShape(): void
    {
        $source = (string) file_get_contents(APPPATH . 'Database/Migrations/2026-10-30-100000_CreateRcContacts.php');

        $this->assertStringContainsString("addUniqueKey(['rc_customer_id', 'rc_contact_id'], 'uq_rc_contacts_customer_contact')", $source);
        $this->assertStringNotContainsString('content_hash', $source);
        $this->assertStringNotContainsString('VARBINARY', $source);
    }

    public function testRenewalMigrationsHaveTheApprovedShape(): void
    {
        $batches = (string) file_get_contents(APPPATH . 'Database/Migrations/2026-11-09-100000_CreateRenewalBatches.php');
        $items   = (string) file_get_contents(APPPATH . 'Database/Migrations/2026-11-09-100100_CreateRenewalItems.php');

        $this->assertStringContainsString("addUniqueKey(['user_id', 'idempotency_key'], 'uq_renewal_batches_user_idempotency')", $batches);
        $this->assertStringContainsString("invoice_option IN ('KeepInvoice', 'NoInvoice')", $batches);
        $this->assertStringContainsString("addForeignKey('user_id', 'users', 'id', '', 'RESTRICT'", $batches);

        $this->assertStringContainsString("addUniqueKey(['domain_id', 'active_expiry_at'], 'uq_renewal_items_domain_active_expiry')", $items);
        $this->assertStringContainsString("addForeignKey('batch_id', 'renewal_batches', 'id', '', 'RESTRICT'", $items);
        $this->assertStringContainsString("addForeignKey('domain_id', 'domains', 'id', '', 'RESTRICT'", $items);
        $this->assertStringNotContainsString('CASCADE', $batches . $items, 'audit rows must never cascade');
        $this->assertDoesNotMatchRegularExpression('/\b(FLOAT|DOUBLE|REAL)\b/i', $batches . $items, 'money must be DECIMAL');
    }

    public function testDomainContactsMigrationHasTheApprovedShape(): void
    {
        $source = (string) file_get_contents(APPPATH . 'Database/Migrations/2026-10-30-100100_CreateDomainContacts.php');

        $this->assertStringContainsString("addUniqueKey(['domain_id', 'role'], 'uq_domain_contacts_domain_role')", $source);
        $this->assertStringContainsString("addForeignKey('domain_id', 'domains', 'id', '', 'CASCADE'", $source);
        $this->assertStringContainsString("addForeignKey('rc_contact_row_id', 'rc_contacts', 'id', '', 'RESTRICT'", $source);
        $this->assertStringContainsString("CONSTRAINT chk_domain_contacts_role CHECK (role IN ('registrant', 'admin', 'tech', 'billing'))", $source);
    }
}
