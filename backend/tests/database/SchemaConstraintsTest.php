<?php

declare(strict_types=1);

use App\Database\Migrations\AddDetailsSyncedAtToDomains;
use App\Database\Migrations\CreateDomainContacts;
use App\Database\Migrations\CreateDomains;
use App\Database\Migrations\CreateRcContacts;
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
    private const ER_ROW_IS_REFERENCED = 1451;
    private const ER_DATA_TOO_LONG     = 1406;
    private const ER_CHECK_FAILED      = 4025;

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

        foreach (['sync_runs', 'domains', 'rc_contacts', 'domain_contacts'] as $existing) {
            if ($this->conn->tableExists($existing)) {
                $this->markTestSkipped('Schema-constraint tests NOT executed: test database already contains ' . $existing . '; refusing to touch it.');
            }
        }

        require_once APPPATH . 'Database/Migrations/2026-10-27-120000_CreateSyncRuns.php';
        require_once APPPATH . 'Database/Migrations/2026-10-27-120100_CreateDomains.php';
        require_once APPPATH . 'Database/Migrations/2026-10-30-100000_CreateRcContacts.php';
        require_once APPPATH . 'Database/Migrations/2026-10-30-100100_CreateDomainContacts.php';
        require_once APPPATH . 'Database/Migrations/2026-10-30-100200_AddDetailsSyncedAtToDomains.php';

        try {
            // A table is recorded only after its up() succeeded, so cleanup can
            // never drop a table this test did not create (for example one
            // created concurrently by another process, which makes up() fail).
            (new CreateSyncRuns($this->forge))->up();
            $this->attempted[] = 'sync_runs';

            (new CreateDomains($this->forge))->up();
            $this->attempted[] = 'domains';

            (new CreateRcContacts($this->forge))->up();
            $this->attempted[] = 'rc_contacts';

            (new CreateDomainContacts($this->forge))->up();
            $this->attempted[] = 'domain_contacts';

            // Only adds a column to domains; it is dropped with that table.
            (new AddDetailsSyncedAtToDomains($this->forge))->up();
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

    /**
     * @param array<string, mixed> $overrides
     */
    private function insertContact(array $overrides = []): int
    {
        $this->conn->table('rc_contacts')->insert($overrides + [
            'rc_customer_id' => '5001',
            'rc_contact_id'  => '9001',
            'first_seen_at'  => '2026-10-30 10:00:00',
            'last_synced_at' => '2026-10-30 10:00:00',
            'created_at'     => '2026-10-30 10:00:00',
            'updated_at'     => '2026-10-30 10:00:00',
        ]);

        return (int) $this->conn->insertID();
    }

    private function insertDomainId(string $orderId = '1001', string $name = 'example.com'): int
    {
        $this->insertDomain(['rc_order_id' => $orderId, 'domain_name' => $name]);

        return (int) $this->conn->insertID();
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function insertLink(?int $domainId, ?int $contactId, string $role = 'registrant', array $overrides = []): void
    {
        $this->conn->table('domain_contacts')->insert($overrides + [
            'domain_id'         => $domainId,
            'rc_contact_row_id' => $contactId,
            'role'              => $role,
            'created_at'        => '2026-10-30 10:00:00',
            'updated_at'        => '2026-10-30 10:00:00',
        ]);
    }

    /**
     * Reads the live schema. fieldExists() is not used because CodeIgniter caches
     * column names on the shared connection and ALTER TABLE does not clear them.
     */
    private function columnExists(string $table, string $column): bool
    {
        $row = $this->conn->query(
            'SELECT COUNT(*) AS n FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$this->t($table), $column],
        )->getRowArray();

        return (int) $row['n'] === 1;
    }

    public function testMigrationsCreateAllTablesAndColumn(): void
    {
        foreach (['sync_runs', 'domains', 'rc_contacts', 'domain_contacts'] as $table) {
            $this->assertTrue($this->conn->tableExists($table), $table);
        }

        $this->assertTrue($this->columnExists('domains', 'details_synced_at'));
    }

    public function testDownRollsBackInReverseOrderAndAddDetailsRemovesOnlyItsColumn(): void
    {
        $this->assertTrue($this->columnExists('domains', 'details_synced_at'));

        (new AddDetailsSyncedAtToDomains($this->forge))->down();

        $this->assertFalse($this->columnExists('domains', 'details_synced_at'));
        $this->assertTrue($this->columnExists('domains', 'last_synced_at'));
        $this->assertTrue($this->columnExists('domains', 'nameservers_json'));

        (new CreateDomainContacts($this->forge))->down();
        (new CreateRcContacts($this->forge))->down();
        (new CreateDomains($this->forge))->down();
        (new CreateSyncRuns($this->forge))->down();
        $this->attempted = [];

        foreach (['domain_contacts', 'rc_contacts', 'domains', 'sync_runs'] as $table) {
            $this->assertFalse($this->conn->tableExists($table), $table);
        }
    }

    public function testDetailsSyncedAtIsNullableAndDefaultsToNull(): void
    {
        $this->insertDomain();
        $this->assertNull($this->conn->table('domains')->get()->getRowArray()['details_synced_at']);

        $this->conn->table('domains')->update(['details_synced_at' => '2026-10-30 11:00:00']);
        $this->assertSame('2026-10-30 11:00:00', $this->conn->table('domains')->get()->getRowArray()['details_synced_at']);

        $this->conn->table('domains')->update(['details_synced_at' => null]);
        $this->assertNull($this->conn->table('domains')->get()->getRowArray()['details_synced_at']);
    }

    public function testContactPersonalFieldsAreNullable(): void
    {
        $this->insertContact();

        $row = $this->conn->table('rc_contacts')->get()->getRowArray();

        foreach (['name', 'company', 'email', 'tel_country_code', 'tel_number', 'address1', 'address2', 'address3', 'city', 'state', 'country', 'postal_code'] as $column) {
            $this->assertNull($row[$column], $column);
        }
    }

    public function testRequiredContactColumnsCannotBeNull(): void
    {
        foreach (['rc_customer_id', 'rc_contact_id', 'first_seen_at', 'last_synced_at', 'created_at', 'updated_at'] as $column) {
            $this->assertRejected(fn () => $this->insertContact([$column => null]), self::ER_BAD_NULL);
        }
    }

    public function testContactIsUniquePerCustomerAndContactIdTogether(): void
    {
        $this->insertContact();
        // The same contact id under another customer is a different contact.
        $this->insertContact(['rc_customer_id' => '5002']);
        // The same customer with another contact id.
        $this->insertContact(['rc_contact_id' => '9002']);

        $this->assertRejected(fn () => $this->insertContact(), self::ER_DUP_ENTRY);
        $this->assertSame(3, $this->conn->table('rc_contacts')->countAllResults());
    }

    public function testContactTextColumnsHoldExactly255Characters(): void
    {
        $this->insertContact(['name' => str_repeat('a', 255)]);

        $this->assertRejected(
            fn () => $this->insertContact(['rc_contact_id' => '9002', 'name' => str_repeat('a', 256)]),
            self::ER_DATA_TOO_LONG,
        );
    }

    public function testDomainContactAllowsOneContactPerRolePerDomain(): void
    {
        $domainId  = $this->insertDomainId();
        $contactId = $this->insertContact();
        $otherId   = $this->insertContact(['rc_contact_id' => '9002']);

        $this->insertLink($domainId, $contactId, 'registrant');
        // One contact may fill several roles of the same domain.
        $this->insertLink($domainId, $otherId, 'admin');
        $this->insertLink($domainId, $otherId, 'tech');
        $this->insertLink($domainId, $otherId, 'billing');

        $this->assertRejected(fn () => $this->insertLink($domainId, $otherId, 'registrant'), self::ER_DUP_ENTRY);
        $this->assertSame(4, $this->conn->table('domain_contacts')->countAllResults());

        // The same contact may be shared with another domain.
        $second = $this->insertDomainId('1002', 'second.com');
        $this->insertLink($second, $contactId, 'registrant');
        $this->assertSame(5, $this->conn->table('domain_contacts')->countAllResults());
    }

    public function testRoleCheckConstraintRejectsUnknownRoles(): void
    {
        $domainId  = $this->insertDomainId();
        $contactId = $this->insertContact();

        foreach (['owner', '', 'administrator', 'registrant2'] as $role) {
            $this->assertRejected(fn () => $this->insertLink($domainId, $contactId, $role), self::ER_CHECK_FAILED);
        }

        foreach (['registrant', 'admin', 'tech', 'billing'] as $role) {
            $this->insertLink($domainId, $contactId, $role);
        }

        $this->assertSame(4, $this->conn->table('domain_contacts')->countAllResults());
    }

    public function testRequiredDomainContactColumnsCannotBeNull(): void
    {
        $domainId  = $this->insertDomainId();
        $contactId = $this->insertContact();

        $this->assertRejected(fn () => $this->insertLink(null, $contactId), self::ER_BAD_NULL);
        $this->assertRejected(fn () => $this->insertLink($domainId, null), self::ER_BAD_NULL);
        $this->assertRejected(fn () => $this->insertLink($domainId, $contactId, 'admin', ['role' => null]), self::ER_BAD_NULL);
        $this->assertRejected(fn () => $this->insertLink($domainId, $contactId, 'admin', ['created_at' => null]), self::ER_BAD_NULL);
        $this->assertRejected(fn () => $this->insertLink($domainId, $contactId, 'admin', ['updated_at' => null]), self::ER_BAD_NULL);
    }

    public function testDomainContactForeignKeysRejectUnknownParents(): void
    {
        $domainId  = $this->insertDomainId();
        $contactId = $this->insertContact();

        $this->assertRejected(fn () => $this->insertLink(999999, $contactId), self::ER_NO_REFERENCED_ROW);
        $this->assertRejected(fn () => $this->insertLink($domainId, 999999), self::ER_NO_REFERENCED_ROW);
    }

    public function testDeletingDomainRemovesItsLinksButKeepsContacts(): void
    {
        $domainId  = $this->insertDomainId();
        $contactId = $this->insertContact();
        $this->insertLink($domainId, $contactId);

        $this->conn->table('domains')->where('id', $domainId)->delete();

        $this->assertSame(0, $this->conn->table('domain_contacts')->countAllResults());
        $this->assertSame(1, $this->conn->table('rc_contacts')->countAllResults());
    }

    public function testLinkedContactCannotBeDeleted(): void
    {
        $domainId  = $this->insertDomainId();
        $contactId = $this->insertContact();
        $this->insertLink($domainId, $contactId);

        $this->assertRejected(fn () => $this->conn->table('rc_contacts')->where('id', $contactId)->delete(), self::ER_ROW_IS_REFERENCED);
        $this->assertSame(1, $this->conn->table('rc_contacts')->countAllResults());

        // Once unlinked, it can be deleted.
        $this->conn->table('domain_contacts')->where('rc_contact_row_id', $contactId)->delete();
        $this->conn->table('rc_contacts')->where('id', $contactId)->delete();
        $this->assertSame(0, $this->conn->table('rc_contacts')->countAllResults());
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

        $contactIndexes = array_map('strtolower', array_keys($this->conn->getIndexData($this->t('rc_contacts'))));
        $linkIndexes    = array_map('strtolower', array_keys($this->conn->getIndexData($this->t('domain_contacts'))));

        foreach (['primary', 'uq_rc_contacts_customer_contact'] as $name) {
            $this->assertContains($name, $contactIndexes);
        }

        foreach (['primary', 'uq_domain_contacts_domain_role', 'idx_domain_contacts_rc_contact_row_id'] as $name) {
            $this->assertContains($name, $linkIndexes);
        }

        $foreignKeys = array_map('strtolower', array_keys($this->conn->getForeignKeyData($this->t('domain_contacts'))));

        foreach (['fk_domain_contacts_domain', 'fk_domain_contacts_rc_contact'] as $name) {
            $this->assertContains($name, $foreignKeys);
        }
    }
}
