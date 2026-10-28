<?php

declare(strict_types=1);

namespace Tests\Support\Database;

use App\Database\Migrations\CreateRefreshTokens;
use App\Database\Migrations\CreateUsers;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Forge;
use Config\Database;

/**
 * Gives a test class the `users` and `refresh_tokens` tables in the isolated
 * MariaDB test database, created once per class and emptied before each test.
 *
 * Same safety rules as tests/database/SchemaConstraintsTest.php: the tests are
 * SKIPPED (never passed) unless the 'tests' connection group is a MySQLi
 * connection to a localhost database whose name ends in "_test" and differs
 * from the default database; the live session is verified; the tables are
 * created only if absent, and only tables this class created are dropped.
 * Under PHPUnit the framework's default group is 'tests', so application code
 * using the default connection also talks to the isolated database (asserted).
 */
trait UsesIsolatedAuthDatabase
{
    private static bool $authSchemaCreated = false;

    protected BaseConnection $testDb;

    protected function setUpIsolatedAuthDatabase(): void
    {
        $dbConfig = config('Database');
        $cfg      = $dbConfig->tests;

        $required = [
            'DBDriver' => 'MySQLi',
            'charset'  => 'utf8mb4',
            'DBCollat' => 'utf8mb4_general_ci',
            'DBPrefix' => '',
            'DBDebug'  => true,
        ];

        foreach ($required as $key => $expected) {
            if (($cfg[$key] ?? null) !== $expected) {
                $this->markTestSkipped("Auth database tests NOT executed: tests connection needs {$key}=" . var_export($expected, true) . '.');
            }
        }

        if (! str_ends_with((string) ($cfg['database'] ?? ''), '_test')
            || ! in_array($cfg['hostname'] ?? '', ['127.0.0.1', 'localhost'], true)
            || ($cfg['DSN'] ?? '') !== ''
            || strcasecmp((string) $cfg['database'], (string) ($dbConfig->default['database'] ?? '')) === 0
        ) {
            $this->markTestSkipped('Auth database tests NOT executed: no isolated *_test MariaDB database on localhost is configured.');
        }

        $this->assertSame('tests', $dbConfig->defaultGroup, 'Application code would not use the isolated test database.');

        $this->testDb = Database::connect('tests');

        $session = $this->testDb->query('SELECT DATABASE() AS db, @@SESSION.sql_mode AS mode')->getRowArray();

        $this->assertSame($cfg['database'], $session['db'], 'Connected to a different database than configured.');
        $this->assertStringContainsString('STRICT_ALL_TABLES', (string) $session['mode'], 'Test session is not in strict mode.');

        if (! self::$authSchemaCreated) {
            if ($this->testDb->tableExists('users') || $this->testDb->tableExists('refresh_tokens')) {
                $this->markTestSkipped('Auth database tests NOT executed: the test database already contains users/refresh_tokens; refusing to touch them.');
            }

            require_once APPPATH . 'Database/Migrations/2026-10-28-100000_CreateUsers.php';
            require_once APPPATH . 'Database/Migrations/2026-10-28-100100_CreateRefreshTokens.php';

            $forge = Database::forge('tests');

            try {
                (new CreateUsers($forge))->up();
                (new CreateRefreshTokens($forge))->up();
            } catch (\Throwable $e) {
                self::dropAuthSchema($forge);

                throw $e;
            }

            self::$authSchemaCreated = true;
        }

        // Only reached for tables this class created (guard above).
        $this->testDb->table('refresh_tokens')->emptyTable();
        $this->testDb->table('users')->emptyTable();
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$authSchemaCreated) {
            self::dropAuthSchema(Database::forge('tests'));
            self::$authSchemaCreated = false;
        }

        parent::tearDownAfterClass();
    }

    private static function dropAuthSchema(Forge $forge): void
    {
        $forge->dropTable('refresh_tokens', true);
        $forge->dropTable('users', true);
    }

    /**
     * @param array<string, mixed> $overrides
     */
    protected function insertUser(array $overrides = []): int
    {
        $this->testDb->table('users')->insert($overrides + [
            'email'         => 'owner@example.test',
            'password_hash' => password_hash('correct horse battery', PASSWORD_DEFAULT),
            'created_at'    => '2026-10-28 10:00:00',
            'updated_at'    => '2026-10-28 10:00:00',
        ]);

        return (int) $this->testDb->insertID();
    }
}
