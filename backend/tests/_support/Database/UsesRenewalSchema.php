<?php

declare(strict_types=1);

namespace Tests\Support\Database;

use App\Database\Migrations\AddDebitVerificationToRenewalItems;
use App\Database\Migrations\CreateRenewalBatches;
use App\Database\Migrations\CreateRenewalDebitVerifications;
use App\Database\Migrations\CreateRenewalItems;
use Config\Database;
use Throwable;

/**
 * Gives a test class the `renewal_batches` and `renewal_items` tables in the
 * isolated test database, created once per class. Use AFTER
 * setUpIsolatedAuthDatabase() and setUpEnrichmentSchema() (they create the
 * `users` and `domains` tables these reference), and call
 * emptyRenewalTables() at the very START of setUp(): the renewal foreign keys
 * are RESTRICT, so the other traits cannot empty `users` or `domains` while
 * renewal rows exist.
 *
 * Same rules as the other database tests: if either table already exists the
 * test is SKIPPED (never touched), and only tables this class created are dropped.
 */
trait UsesRenewalSchema
{
    private static bool $renewalSchemaCreated = false;

    protected static function emptyRenewalTables(): void
    {
        if (! self::$renewalSchemaCreated) {
            return;
        }

        $db = Database::connect('tests');
        $db->query('DELETE FROM renewal_debit_verifications');
        $db->query('DELETE FROM renewal_items');
        $db->query('DELETE FROM renewal_batches');
    }

    protected function setUpRenewalSchema(): void
    {
        if (! self::$renewalSchemaCreated) {
            foreach (['renewal_batches', 'renewal_items', 'renewal_debit_verifications'] as $table) {
                if ($this->testDb->tableExists($table)) {
                    $this->markTestSkipped('Renewal tests NOT executed: the test database already contains ' . $table . '; refusing to touch it.');
                }
            }

            require_once APPPATH . 'Database/Migrations/2026-11-09-100000_CreateRenewalBatches.php';
            require_once APPPATH . 'Database/Migrations/2026-11-09-100100_CreateRenewalItems.php';
            require_once APPPATH . 'Database/Migrations/2026-11-11-100000_AddDebitVerificationToRenewalItems.php';
            require_once APPPATH . 'Database/Migrations/2026-11-11-100100_CreateRenewalDebitVerifications.php';
            require_once APPPATH . 'Database/Migrations/2026-11-12-100000_AddProviderMessageToRenewalItems.php';

            $forge = Database::forge('tests');

            try {
                (new CreateRenewalBatches($forge))->up();
                (new CreateRenewalItems($forge))->up();
                (new AddDebitVerificationToRenewalItems($forge))->up();
                (new CreateRenewalDebitVerifications($forge))->up();
                (new \App\Database\Migrations\AddProviderMessageToRenewalItems($forge))->up();
            } catch (Throwable $e) {
                self::dropRenewalSchema($forge);

                throw $e;
            }

            self::$renewalSchemaCreated = true;
        }
    }

    protected static function tearDownRenewalSchema(): void
    {
        if (self::$renewalSchemaCreated) {
            self::dropRenewalSchema(Database::forge('tests'));
            self::$renewalSchemaCreated = false;
        }
    }

    private static function dropRenewalSchema(\CodeIgniter\Database\Forge $forge): void
    {
        $forge->dropTable('renewal_debit_verifications', true);
        $forge->dropTable('renewal_items', true);
        $forge->dropTable('renewal_batches', true);
    }
}
