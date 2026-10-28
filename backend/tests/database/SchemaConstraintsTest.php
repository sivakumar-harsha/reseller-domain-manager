<?php

declare(strict_types=1);

use App\Database\Migrations\CreateDomains;
use App\Database\Migrations\CreateSyncRuns;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Exceptions\DatabaseException;
use CodeIgniter\Database\Forge;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Database;

/**
 * Applies the real migrations to an isolated MariaDB test database and checks
 * the schema constraints.
 *
 * Safety: the tests are SKIPPED (not passed) unless the 'tests' connection
 * group is a MySQLi connection to a database whose name ends in "_test", with
 * utf8mb4 / utf8mb4_general_ci, an empty DBPrefix and DBDebug on (configure it
 * with database.tests.* in .env). They also refuse to run if the tables
 * already exist, so nothing pre-existing is ever dropped. Cleanup only drops
 * tables this test attempted to create.
 *
 * A skip means the schema constraints were NOT checked in that run.
 *
 * @internal
 */
final class SchemaConstraintsTest extends CIUnitTestCase
{
    // MariaDB error numbers.
    private const ER_BAD_NULL          = 1048;
    private const ER_DUP_ENTRY         = 1062;
    private const ER_DATA_TRUNCATED    = 1265;
    private const ER_NO_REFERENCED_ROW = 1452;

    private BaseConnection $conn;
    private Forge $forge;
    private string $prefix = '';

    /**
     * Tables this test successfully created, in creation order.
     *
     * @var list<string>
     */
    private array $attempted = [];

    protected function setUp(): void
    {
        parent::setUp();

        $cfg = config('Database')->tests;

        $required = [
            'DBDriver' => 'MySQLi',
            'charset'  => 'utf8mb4',
            'DBCollat' => 'utf8mb4_general_ci',
            'DBPrefix' => '',
            'DBDebug'  => true,
        ];

        foreach ($required as $key => $expected) {
            if (($cfg[$key] ?? null) !== $expected) {
                $this->markTestSkipped(sprintf(
                    'Schema-constraint tests NOT executed: tests connection needs %s=%s (set database.tests.%s in .env).',
                    $key,
                    var_export($expected, true),
                    $key,
                ));
            }
        }

        if (! str_ends_with((string) ($cfg['database'] ?? ''), '_test')) {
            $this->markTestSkipped('Schema-constraint tests NOT executed: no isolated MariaDB test database configured (database.tests.database must end in _test).');
        }

        if (! in_array($cfg['hostname'] ?? '', ['127.0.0.1', 'localhost'], true)) {
            $this->markTestSkipped('Schema-constraint tests NOT executed: database.tests.hostname must be 127.0.0.1 or localhost.');
        }

        if (($cfg['DSN'] ?? '') !== '') {
            $this->markTestSkipped('Schema-constraint tests NOT executed: database.tests.DSN must be empty.');
        }

        $defaultDb = (string) (config('Database')->default['database'] ?? '');

        if (strcasecmp((string) $cfg['database'], $defaultDb) === 0) {
            $this->markTestSkipped('Schema-constraint tests NOT executed: the test database must differ from the default database.');
        }

        $this->conn   = Database::connect('tests');
        $this->forge  = Database::forge('tests');
        $this->prefix = $this->conn->getPrefix();

        // Check the live session, not just the configuration. A mismatch is a
        // safety problem, so fail rather than skip.
        $session = $this->conn->query('SELECT DATABASE() AS db, @@SESSION.sql_mode AS mode')->getRowArray();

        $this->assertSame($cfg['database'], $session['db'], 'Connected to a different database than configured.');
        $this->assertStringContainsString('STRICT_ALL_TABLES', (string) $session['mode'], 'Test session is not in strict mode.');

        if ($this->conn->tableExists('sync_runs') || $this->conn->tableExists('domains')) {
            $this->markTestSkipped('Schema-constraint tests NOT executed: test database already contains sync_runs/domains; refusing to touch them.');
        }

        require_once APPPATH . 'Database/Migrations/2026-10-27-120000_CreateSyncRuns.php';
        require_once APPPATH . 'Database/Migrations/2026-10-27-120100_CreateDomains.php';

        try {
            // A table is recorded only after its up() succeeded, so cleanup can
            // never drop a table this test did not create (for example one
            // created concurrently by another process, which makes up() fail).
            (new CreateSyncRuns($this->forge))->up();
            $this->attempted[] = 'sync_runs';

            (new CreateDomains($this->forge))->up();
            $this->attempted[] = 'domains';
        } catch (Throwable $e) {
            // Remove any partial setup, then surface the original failure.
            $this->dropAttempted(true);

            throw $e;
        }
    }

    protected function tearDown(): void
    {
        $this->dropAttempted(false);

        parent::tearDown();
    }

    /**
     * Drops only the tables this test created (recorded after a successful
     * up(), and absent beforehand per the guard in setUp()), newest first.
     * When $quiet is true a cleanup failure is ignored so it cannot mask the
     * setup exception already in flight; otherwise it is reported.
     */
    private function dropAttempted(bool $quiet): void
    {
        $tables          = array_reverse($this->attempted);
        $this->attempted = [];

        foreach ($tables as $table) {
            try {
                $this->forge->dropTable($table, true);
            } catch (Throwable $e) {
                if (! $quiet) {
                    throw $e;
                }
            }
        }
    }

    private function t(string $table): string
    {
        return $this->prefix . $table;
    }

    private function insertRun(array $overrides = []): int
    {
        $this->conn->table('sync_runs')->insert($overrides + [
            'trigger_type' => 'manual',
            'started_at'   => '2026-10-27 10:00:00',
            'created_at'   => '2026-10-27 10:00:00',
            'updated_at'   => '2026-10-27 10:00:00',
        ]);

        return (int) $this->conn->insertID();
    }

    private function insertDomain(array $overrides = []): void
    {
        $this->conn->table('domains')->insert($overrides + [
            'rc_order_id'    => '1001',
            'domain_name'    => 'example.com',
            'first_seen_at'  => '2026-10-27 10:00:00',
            'last_synced_at' => '2026-10-27 10:00:00',
            'created_at'     => '2026-10-27 10:00:00',
            'updated_at'     => '2026-10-27 10:00:00',
        ]);
    }

    /**
     * Asserts the database rejects the statement with the given MariaDB error
     * number. Only a CodeIgniter DatabaseException counts; any other Throwable
     * (TypeError, missing method, ...) propagates and fails the test.
     */
    private function assertRejected(callable $fn, int $errno): void
    {
        try {
            $fn();
        } catch (DatabaseException $e) {
            $codes = [$e->getCode()];

            for ($p = $e->getPrevious(); $p !== null; $p = $p->getPrevious()) {
                $codes[] = $p->getCode();
            }

            $this->assertContains($errno, $codes, 'Rejected, but with a different error: ' . $e->getMessage());

            return;
        }

        $this->fail('Expected the database to reject this statement with error ' . $errno . '.');
    }

    public function testMigrationsCreateBothTables(): void
    {
        $this->assertTrue($this->conn->tableExists('sync_runs'));
        $this->assertTrue($this->conn->tableExists('domains'));
    }

    public function testDownDropsTablesInReverseOrder(): void
    {
        (new CreateDomains($this->forge))->down();
        (new CreateSyncRuns($this->forge))->down();
        $this->attempted = [];

        $this->assertFalse($this->conn->tableExists('domains'));
        $this->assertFalse($this->conn->tableExists('sync_runs'));
    }

    public function testDomainWithOnlyRequiredColumnsIsAcceptedAndProviderFieldsAreNullable(): void
    {
        $this->insertDomain();

        $row = $this->conn->table('domains')->get()->getRowArray();
        $this->assertNull($row['expires_at']);
        $this->assertNull($row['auto_renew']);
        $this->assertNull($row['status']);
        $this->assertSame('0', (string) $row['is_missing']);
    }

    public function testDomainNameIsUniqueCaseInsensitively(): void
    {
        $this->insertDomain();

        $this->assertRejected(fn () => $this->insertDomain(['rc_order_id' => '1002', 'domain_name' => 'EXAMPLE.com']), self::ER_DUP_ENTRY);
    }

    public function testOrderIdIsUnique(): void
    {
        $this->insertDomain();

        $this->assertRejected(fn () => $this->insertDomain(['domain_name' => 'other.com']), self::ER_DUP_ENTRY);
    }

    public function testRequiredDomainColumnsCannotBeNull(): void
    {
        $this->assertRejected(fn () => $this->insertDomain(['domain_name' => null]), self::ER_BAD_NULL);
        $this->assertRejected(fn () => $this->insertDomain(['first_seen_at' => null]), self::ER_BAD_NULL);
    }

    public function testSyncRunRejectsInvalidStatusAndTrigger(): void
    {
        $this->assertRejected(fn () => $this->insertRun(['status' => 'bogus']), self::ER_DATA_TRUNCATED);
        $this->assertRejected(fn () => $this->insertRun(['trigger_type' => 'bogus']), self::ER_DATA_TRUNCATED);
    }

    public function testSyncRunDefaultsAndRequiredColumns(): void
    {
        $id  = $this->insertRun();
        $row = $this->conn->table('sync_runs')->where('id', $id)->get()->getRowArray();

        $this->assertSame('running', $row['status']);
        $this->assertNull($row['finished_at']);
        $this->assertSame('0', (string) $row['records_seen']);
        $this->assertSame('0', (string) $row['error_count']);

        $this->assertRejected(fn () => $this->insertRun(['started_at' => null]), self::ER_BAD_NULL);
    }

    public function testDomainForeignKeyRejectsUnknownSyncRun(): void
    {
        $this->assertRejected(fn () => $this->insertDomain(['last_seen_sync_id' => 999999]), self::ER_NO_REFERENCED_ROW);
    }

    public function testDeletingSyncRunKeepsDomainAndClearsReference(): void
    {
        $runId = $this->insertRun();
        $this->insertDomain(['last_seen_sync_id' => $runId]);

        $this->conn->table('sync_runs')->where('id', $runId)->delete();

        $row = $this->conn->table('domains')->get()->getRowArray();
        $this->assertNotNull($row);
        $this->assertNull($row['last_seen_sync_id']);
    }

    public function testExpectedIndexesExist(): void
    {
        $domainIndexes = array_map('strtolower', array_keys($this->conn->getIndexData($this->t('domains'))));
        $runIndexes    = array_map('strtolower', array_keys($this->conn->getIndexData($this->t('sync_runs'))));

        foreach (['primary', 'rc_order_id', 'domain_name', 'expires_at', 'status'] as $name) {
            $this->assertContains($name, $domainIndexes);
        }

        foreach (['primary', 'started_at', 'status'] as $name) {
            $this->assertContains($name, $runIndexes);
        }
    }
}
