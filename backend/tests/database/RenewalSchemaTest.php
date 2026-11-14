<?php

declare(strict_types=1);

use App\Database\Migrations\CreateDomains;
use App\Database\Migrations\CreateRenewalBatches;
use App\Database\Migrations\CreateRenewalItems;
use App\Database\Migrations\CreateSyncRuns;
use App\Database\Migrations\CreateUsers;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Exceptions\DatabaseException;
use CodeIgniter\Database\Forge;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Database;

/**
 * Applies the renewal migrations (and the parent tables they reference) to an
 * isolated MariaDB test database and checks the schema constraints.
 *
 * Safety: same rules as SchemaConstraintsTest. The tests are SKIPPED (not
 * passed) unless the 'tests' connection group is a MySQLi connection to a
 * localhost database whose name ends in "_test" and differs from the default
 * database, with strict mode on and an empty DBPrefix. They refuse to run if
 * any involved table already exists, and cleanup drops only tables this test
 * created. A skip means the schema was NOT checked in that run.
 *
 * @internal
 */
final class RenewalSchemaTest extends CIUnitTestCase
{
    private const ER_DUP_ENTRY         = 1062;
    private const ER_NO_REFERENCED_ROW = 1452;
    private const ER_ROW_IS_REFERENCED = 1451;
    private const ER_CHECK_FAILED      = 4025;

    private const TABLES = ['users', 'sync_runs', 'domains', 'renewal_batches', 'renewal_items'];

    private BaseConnection $conn;
    private Forge $forge;

    /** @var list<string> */
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
                $this->markTestSkipped("Renewal schema tests NOT executed: tests connection needs {$key}=" . var_export($expected, true) . '.');
            }
        }

        if (! str_ends_with((string) ($cfg['database'] ?? ''), '_test')
            || ! in_array($cfg['hostname'] ?? '', ['127.0.0.1', 'localhost'], true)
            || ($cfg['DSN'] ?? '') !== ''
            || strcasecmp((string) $cfg['database'], (string) (config('Database')->default['database'] ?? '')) === 0
        ) {
            $this->markTestSkipped('Renewal schema tests NOT executed: no isolated *_test MariaDB database on localhost is configured.');
        }

        $this->conn  = Database::connect('tests');
        $this->forge = Database::forge('tests');

        // Check the live session, not just the configuration.
        $session = $this->conn->query('SELECT DATABASE() AS db, @@SESSION.sql_mode AS mode')->getRowArray();

        $this->assertSame($cfg['database'], $session['db'], 'Connected to a different database than configured.');
        $this->assertStringContainsString('STRICT_ALL_TABLES', (string) $session['mode'], 'Test session is not in strict mode.');

        foreach (self::TABLES as $existing) {
            if ($this->conn->tableExists($existing)) {
                $this->markTestSkipped("Renewal schema tests NOT executed: test database already contains {$existing}; refusing to touch it.");
            }
        }

        foreach ([
            '2026-10-27-120000_CreateSyncRuns.php',
            '2026-10-27-120100_CreateDomains.php',
            '2026-10-28-100000_CreateUsers.php',
            '2026-11-09-100000_CreateRenewalBatches.php',
            '2026-11-09-100100_CreateRenewalItems.php',
        ] as $file) {
            require_once APPPATH . 'Database/Migrations/' . $file;
        }

        try {
            // Recorded only after up() succeeded, so cleanup never drops a table this test did not create.
            (new CreateSyncRuns($this->forge))->up();
            $this->attempted[] = 'sync_runs';
            (new CreateDomains($this->forge))->up();
            $this->attempted[] = 'domains';
            (new CreateUsers($this->forge))->up();
            $this->attempted[] = 'users';
            (new CreateRenewalBatches($this->forge))->up();
            $this->attempted[] = 'renewal_batches';
            (new CreateRenewalItems($this->forge))->up();
            $this->attempted[] = 'renewal_items';
        } catch (Throwable $e) {
            $this->dropAttempted(true);

            throw $e;
        }
    }

    protected function tearDown(): void
    {
        $this->dropAttempted(false);

        parent::tearDown();
    }

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

    // ---- helpers ------------------------------------------------------------

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

    private function insertUser(string $email = 'owner@example.test'): int
    {
        $this->conn->table('users')->insert([
            'email'         => $email,
            'password_hash' => 'x',
            'created_at'    => '2026-11-09 10:00:00',
            'updated_at'    => '2026-11-09 10:00:00',
        ]);

        return (int) $this->conn->insertID();
    }

    private function insertDomain(string $orderId = '1001', string $name = 'example.com'): int
    {
        $this->conn->table('domains')->insert([
            'rc_order_id'    => $orderId,
            'domain_name'    => $name,
            'first_seen_at'  => '2026-11-09 10:00:00',
            'last_synced_at' => '2026-11-09 10:00:00',
            'created_at'     => '2026-11-09 10:00:00',
            'updated_at'     => '2026-11-09 10:00:00',
        ]);

        return (int) $this->conn->insertID();
    }

    /** @param array<string, mixed> $overrides */
    private function insertBatch(int $userId, array $overrides = []): int
    {
        $this->conn->table('renewal_batches')->insert($overrides + [
            'user_id'         => $userId,
            'idempotency_key' => bin2hex(random_bytes(8)),
            'invoice_option'  => 'KeepInvoice',
            'years'           => 1,
            'created_at'      => '2026-11-09 10:00:00',
            'updated_at'      => '2026-11-09 10:00:00',
        ]);

        return (int) $this->conn->insertID();
    }

    /** @param array<string, mixed> $overrides */
    private function insertItem(int $batchId, int $domainId, array $overrides = []): int
    {
        $this->conn->table('renewal_items')->insert($overrides + [
            'batch_id'          => $batchId,
            'domain_id'         => $domainId,
            'rc_order_id'       => '1001',
            'expiry_at_request' => '2027-01-15 00:00:00',
            'renewal_amount'    => '1419.00',
            'gst_amount'        => '255.42',
            'vat_amount'        => '0.00',
            'total_amount'      => '1674.42',
            'currency'          => 'INR',
            'created_at'        => '2026-11-09 10:00:00',
            'updated_at'        => '2026-11-09 10:00:00',
        ]);

        return (int) $this->conn->insertID();
    }

    /** @return array<string, array<string, mixed>> column name => information_schema row */
    private function columns(string $table): array
    {
        $rows = $this->conn->query(
            'SELECT COLUMN_NAME, COLUMN_TYPE, DATA_TYPE, IS_NULLABLE, NUMERIC_SCALE, COLUMN_DEFAULT, EXTRA FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION',
            [$table],
        )->getResultArray();

        $out = [];

        foreach ($rows as $row) {
            $out[$row['COLUMN_NAME']] = $row;
        }

        return $out;
    }

    /** Whole-schema fingerprint of the tables this migration must not touch. */
    private function parentSnapshot(): array
    {
        return $this->conn->query(
            "SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('users','sync_runs','domains') ORDER BY TABLE_NAME, ORDINAL_POSITION",
        )->getResultArray();
    }

    /** @return list<string> */
    private function indexNames(string $table): array
    {
        $rows = $this->conn->query('SELECT DISTINCT INDEX_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? ORDER BY INDEX_NAME', [$table])->getResultArray();

        return array_column($rows, 'INDEX_NAME');
    }

    /** @return list<string> */
    private function indexColumns(string $table, string $index): array
    {
        $rows = $this->conn->query('SELECT COLUMN_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ? ORDER BY SEQ_IN_INDEX', [$table, $index])->getResultArray();

        return array_column($rows, 'COLUMN_NAME');
    }

    // ---- creation, columns, keys --------------------------------------------

    public function testTestDatabaseIdentityIsTheIsolatedOne(): void
    {
        $name = (string) $this->conn->query('SELECT DATABASE() AS db')->getRowArray()['db'];

        $this->assertStringEndsWith('_test', $name);
        $this->assertNotSame((string) config('Database')->default['database'], $name);
    }

    public function testBothTablesExistWithTheRequiredColumns(): void
    {
        $this->assertTrue($this->conn->tableExists('renewal_batches'));
        $this->assertTrue($this->conn->tableExists('renewal_items'));

        $this->assertSame(
            ['id', 'user_id', 'idempotency_key', 'invoice_option', 'years', 'status', 'created_at', 'updated_at', 'finished_at'],
            array_keys($this->columns('renewal_batches')),
        );

        $this->assertSame(
            [
                'id', 'batch_id', 'domain_id', 'rc_order_id', 'expiry_at_request', 'renewal_amount', 'gst_amount', 'vat_amount',
                'total_amount', 'currency', 'state', 'provider_action_id', 'provider_invoice_id', 'error_code',
                'created_at', 'updated_at', 'completed_at', 'active_expiry_at',
            ],
            array_keys($this->columns('renewal_items')),
        );
    }

    public function testPrimaryAndForeignKeys(): void
    {
        $this->assertSame(['id'], $this->indexColumns('renewal_batches', 'PRIMARY'));
        $this->assertSame(['id'], $this->indexColumns('renewal_items', 'PRIMARY'));

        $fks = $this->conn->query(
            "SELECT k.TABLE_NAME AS t, k.CONSTRAINT_NAME AS c, k.COLUMN_NAME AS col, k.REFERENCED_TABLE_NAME AS rt, k.REFERENCED_COLUMN_NAME AS rcol, r.DELETE_RULE AS del, r.UPDATE_RULE AS upd
             FROM information_schema.KEY_COLUMN_USAGE k
             JOIN information_schema.REFERENTIAL_CONSTRAINTS r ON r.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA AND r.CONSTRAINT_NAME = k.CONSTRAINT_NAME AND r.TABLE_NAME = k.TABLE_NAME
             WHERE k.TABLE_SCHEMA = DATABASE() AND k.TABLE_NAME IN ('renewal_batches','renewal_items') AND k.REFERENCED_TABLE_NAME IS NOT NULL
             ORDER BY k.TABLE_NAME, k.CONSTRAINT_NAME",
        )->getResultArray();

        $got = array_map(static fn (array $r): array => [$r['t'], $r['c'], $r['col'], $r['rt'], $r['rcol'], $r['del']], $fks);

        $this->assertSame([
            ['renewal_batches', 'fk_renewal_batches_user', 'user_id', 'users', 'id', 'RESTRICT'],
            ['renewal_items', 'fk_renewal_items_batch', 'batch_id', 'renewal_batches', 'id', 'RESTRICT'],
            ['renewal_items', 'fk_renewal_items_domain', 'domain_id', 'domains', 'id', 'RESTRICT'],
        ], $got);
    }

    public function testIndexesAndUniqueConstraints(): void
    {
        $this->assertSame(['user_id', 'idempotency_key'], $this->indexColumns('renewal_batches', 'uq_renewal_batches_user_idempotency'));
        $this->assertSame(['batch_id', 'domain_id'], $this->indexColumns('renewal_items', 'uq_renewal_items_batch_domain'));
        $this->assertSame(['domain_id', 'active_expiry_at'], $this->indexColumns('renewal_items', 'uq_renewal_items_domain_active_expiry'));

        $unique = $this->conn->query(
            "SELECT INDEX_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('renewal_batches','renewal_items') AND NON_UNIQUE = 0 AND INDEX_NAME <> 'PRIMARY' ORDER BY INDEX_NAME",
        )->getResultArray();

        $this->assertSame(
            ['uq_renewal_batches_user_idempotency', 'uq_renewal_items_batch_domain', 'uq_renewal_items_domain_active_expiry'],
            array_values(array_unique(array_column($unique, 'INDEX_NAME'))),
        );

        $this->assertContains('idx_renewal_items_state', $this->indexNames('renewal_items'));
        $this->assertContains('idx_renewal_batches_status', $this->indexNames('renewal_batches'));
    }

    public function testEveryDeclaredCheckConstraintActuallyExists(): void
    {
        // Forge silently overwrites raw column strings that share a first word,
        // so the constraints are verified by name, not just by the migration source.
        $rows = $this->conn->query(
            "SELECT CONSTRAINT_NAME FROM information_schema.CHECK_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME IN ('renewal_batches','renewal_items') ORDER BY CONSTRAINT_NAME",
        )->getResultArray();

        $this->assertSame([
            'chk_renewal_batches_idempotency_key',
            'chk_renewal_batches_invoice_option',
            'chk_renewal_batches_status',
            'chk_renewal_batches_years',
            'chk_renewal_items_amounts',
            'chk_renewal_items_order',
            'chk_renewal_items_state',
        ], array_column($rows, 'CONSTRAINT_NAME'));
    }

    // ---- renewal_batches behaviour ------------------------------------------

    public function testIdempotencyKeyIsUniquePerUser(): void
    {
        $u1 = $this->insertUser('a@example.test');
        $u2 = $this->insertUser('b@example.test');

        $this->insertBatch($u1, ['idempotency_key' => 'key-1']);
        $this->insertBatch($u2, ['idempotency_key' => 'key-1']); // another user may reuse the key
        $this->insertBatch($u1, ['idempotency_key' => 'key-2']);

        $this->assertRejected(fn () => $this->insertBatch($u1, ['idempotency_key' => 'key-1']), self::ER_DUP_ENTRY);
        $this->assertRejected(fn () => $this->insertBatch($u1, ['idempotency_key' => '']), self::ER_CHECK_FAILED);
    }

    public function testInvoiceOptionIsRestrictedToTheTwoApprovedValues(): void
    {
        $u = $this->insertUser();

        $this->insertBatch($u, ['invoice_option' => 'KeepInvoice']);
        $this->insertBatch($u, ['invoice_option' => 'NoInvoice']);

        foreach (['PayInvoice', 'OnlyAdd', '', 'Keep'] as $bad) {
            $this->assertRejected(fn () => $this->insertBatch($u, ['invoice_option' => $bad]), self::ER_CHECK_FAILED);
        }
    }

    public function testBatchYearsAndStatusAreConstrained(): void
    {
        $u = $this->insertUser();

        $id = $this->insertBatch($u, ['years' => 10]);
        $this->assertSame('pending', $this->conn->table('renewal_batches')->where('id', $id)->get()->getRowArray()['status']);
        $this->assertNull($this->conn->table('renewal_batches')->where('id', $id)->get()->getRowArray()['finished_at']);

        $this->assertRejected(fn () => $this->insertBatch($u, ['years' => 0]), self::ER_CHECK_FAILED);
        $this->assertRejected(fn () => $this->insertBatch($u, ['years' => 11]), self::ER_CHECK_FAILED);
        $this->assertRejected(fn () => $this->insertBatch($u, ['status' => 'done']), self::ER_CHECK_FAILED);

        foreach (['pending', 'processing', 'completed', 'partial', 'failed', 'needs_review'] as $status) {
            $this->insertBatch($u, ['status' => $status]);
        }
    }

    // ---- renewal_items behaviour --------------------------------------------

    public function testProviderFieldsAreNullableAndStateDefaultsToPending(): void
    {
        $batch  = $this->insertBatch($this->insertUser());
        $domain = $this->insertDomain();
        $item   = $this->insertItem($batch, $domain);

        $row = $this->conn->table('renewal_items')->where('id', $item)->get()->getRowArray();

        $this->assertSame('pending', $row['state']);
        $this->assertNull($row['provider_action_id']);
        $this->assertNull($row['provider_invoice_id']);
        $this->assertNull($row['error_code']);
        $this->assertNull($row['completed_at']);

        $this->conn->table('renewal_items')->where('id', $item)->update([
            'state' => 'succeeded', 'provider_action_id' => '555', 'provider_invoice_id' => '777', 'completed_at' => '2026-11-09 10:05:00',
        ]);
        $row = $this->conn->table('renewal_items')->where('id', $item)->get()->getRowArray();
        $this->assertSame(['555', '777'], [$row['provider_action_id'], $row['provider_invoice_id']]);

        // NoInvoice: no invoice id is returned, so it stays NULL while the action id is kept.
        $this->conn->table('renewal_items')->where('id', $item)->update(['provider_invoice_id' => null]);
        $row = $this->conn->table('renewal_items')->where('id', $item)->get()->getRowArray();
        $this->assertNull($row['provider_invoice_id']);
        $this->assertSame('555', $row['provider_action_id']);
    }

    public function testMoneyColumnsAreDecimalWithTwoPlacesAndPreserveValues(): void
    {
        $cols = $this->columns('renewal_items');

        foreach (['renewal_amount', 'gst_amount', 'vat_amount', 'total_amount'] as $name) {
            $this->assertSame('decimal', $cols[$name]['DATA_TYPE'], $name);
            $this->assertSame('decimal(12,2)', $cols[$name]['COLUMN_TYPE'], $name);
            $this->assertSame('2', (string) $cols[$name]['NUMERIC_SCALE'], $name);
            $this->assertSame('NO', $cols[$name]['IS_NULLABLE'], $name);
        }

        foreach (['renewal_batches', 'renewal_items'] as $table) {
            foreach ($this->columns($table) as $name => $col) {
                $this->assertNotContains($col['DATA_TYPE'], ['float', 'double'], "{$table}.{$name}");
            }
        }

        $batch = $this->insertBatch($this->insertUser());
        $id    = $this->insertItem($batch, $this->insertDomain(), [
            'renewal_amount' => '1219.10', 'gst_amount' => '219.44', 'vat_amount' => '0.00', 'total_amount' => '1438.54',
        ]);
        $row = $this->conn->table('renewal_items')->where('id', $id)->get()->getRowArray();

        $this->assertSame(['1219.10', '219.44', '0.00', '1438.54'], [$row['renewal_amount'], $row['gst_amount'], $row['vat_amount'], $row['total_amount']]);
    }

    public function testAmountChecksRejectNegativesAndInconsistentTotals(): void
    {
        $batch  = $this->insertBatch($this->insertUser());
        $domain = $this->insertDomain();

        $this->assertRejected(fn () => $this->insertItem($batch, $domain, ['total_amount' => '1674.43']), self::ER_CHECK_FAILED);
        $this->assertRejected(fn () => $this->insertItem($batch, $domain, ['renewal_amount' => '-1.00', 'total_amount' => '254.42']), self::ER_CHECK_FAILED);
        $this->assertRejected(fn () => $this->insertItem($batch, $domain, ['state' => 'done']), self::ER_CHECK_FAILED);
        $this->assertRejected(fn () => $this->insertItem($batch, $domain, ['rc_order_id' => '']), self::ER_CHECK_FAILED);
    }

    public function testEveryItemStateIsAccepted(): void
    {
        $batch = $this->insertBatch($this->insertUser());

        foreach (['pending', 'submitting', 'succeeded', 'failed', 'uncertain'] as $i => $state) {
            $this->insertItem($batch, $this->insertDomain((string) (2000 + $i), "d{$i}.com"), ['state' => $state]);
        }

        $this->assertSame(5, $this->conn->table('renewal_items')->countAllResults());
    }

    public function testOneDomainAppearsOncePerBatch(): void
    {
        $batch  = $this->insertBatch($this->insertUser());
        $domain = $this->insertDomain();

        $this->insertItem($batch, $domain, ['state' => 'failed']);
        $this->assertRejected(fn () => $this->insertItem($batch, $domain, ['expiry_at_request' => '2028-01-15 00:00:00']), self::ER_DUP_ENTRY);
    }

    // ---- duplicate-cycle guard ----------------------------------------------

    public function testLiveItemBlocksTheSameDomainAndExpiryCycle(): void
    {
        $user   = $this->insertUser();
        $domain = $this->insertDomain();
        $first  = $this->insertBatch($user);
        $second = $this->insertBatch($user);

        foreach (['pending', 'submitting', 'succeeded', 'uncertain'] as $state) {
            $this->conn->table('renewal_items')->where('id >', 0)->delete();
            $this->insertItem($first, $domain, ['state' => $state]);

            $this->assertRejected(fn () => $this->insertItem($second, $domain), self::ER_DUP_ENTRY);
        }
    }

    public function testFailedItemFreesTheCycleForARetry(): void
    {
        $user   = $this->insertUser();
        $domain = $this->insertDomain();
        $first  = $this->insertBatch($user);
        $second = $this->insertBatch($user);
        $third  = $this->insertBatch($user);

        $failed = $this->insertItem($first, $domain, ['state' => 'failed']);
        $retry  = $this->insertItem($second, $domain); // allowed: the first attempt definitely failed

        // Now the retry holds the slot again.
        $this->assertRejected(fn () => $this->insertItem($third, $domain), self::ER_DUP_ENTRY);

        // A second failed row for the same cycle is fine (history), a live one is not.
        $this->conn->table('renewal_items')->where('id', $retry)->update(['state' => 'failed']);
        $this->insertItem($third, $domain, ['state' => 'failed']);
        $this->assertSame(3, $this->conn->table('renewal_items')->countAllResults());

        // A failed row cannot be revived while another live item exists for the cycle.
        $this->conn->table('renewal_items')->where('id', $retry)->update(['state' => 'pending']);
        $this->assertRejected(
            fn () => $this->conn->table('renewal_items')->where('id', $failed)->update(['state' => 'pending']),
            self::ER_DUP_ENTRY,
        );
    }

    public function testNewExpiryAfterARenewalIsNotBlocked(): void
    {
        $user   = $this->insertUser();
        $domain = $this->insertDomain();

        $this->insertItem($this->insertBatch($user), $domain, ['state' => 'succeeded', 'expiry_at_request' => '2027-01-15 00:00:00']);

        // After the renewal the domain's expiry moved forward: the next cycle is a different key.
        $this->insertItem($this->insertBatch($user), $domain, ['expiry_at_request' => '2028-01-15 00:00:00']);

        // A different domain with the same expiry is also independent.
        $this->insertItem($this->insertBatch($user), $this->insertDomain('1002', 'other.com'));

        $this->assertSame(3, $this->conn->table('renewal_items')->countAllResults());
    }

    // ---- foreign keys behave as audit-safe ----------------------------------

    public function testForeignKeysRejectOrphansAndRestrictDeletes(): void
    {
        $user   = $this->insertUser();
        $domain = $this->insertDomain();
        $batch  = $this->insertBatch($user);

        $this->assertRejected(fn () => $this->insertBatch(999999), self::ER_NO_REFERENCED_ROW);
        $this->assertRejected(fn () => $this->insertItem(999999, $domain), self::ER_NO_REFERENCED_ROW);
        $this->assertRejected(fn () => $this->insertItem($batch, 999999), self::ER_NO_REFERENCED_ROW);

        $this->insertItem($batch, $domain);

        $this->assertRejected(fn () => $this->conn->table('renewal_batches')->where('id', $batch)->delete(), self::ER_ROW_IS_REFERENCED);
        $this->assertRejected(fn () => $this->conn->table('domains')->where('id', $domain)->delete(), self::ER_ROW_IS_REFERENCED);
        $this->assertRejected(fn () => $this->conn->table('users')->where('id', $user)->delete(), self::ER_ROW_IS_REFERENCED);

        $this->assertSame(1, $this->conn->table('renewal_items')->countAllResults());
    }

    // ---- rollback and re-run -------------------------------------------------

    public function testRollbackRemovesOnlyTheTwoNewTables(): void
    {
        $this->insertDomain();
        $this->insertUser();
        $before = $this->parentSnapshot();

        (new CreateRenewalItems($this->forge))->down();
        (new CreateRenewalBatches($this->forge))->down();
        $this->attempted = ['sync_runs', 'domains', 'users']; // the two renewal tables are already gone

        $this->assertFalse($this->conn->tableExists('renewal_items'));
        $this->assertFalse($this->conn->tableExists('renewal_batches'));

        foreach (['users', 'sync_runs', 'domains'] as $table) {
            $this->assertTrue($this->conn->tableExists($table), $table);
        }

        $this->assertSame($before, $this->parentSnapshot());
        $this->assertSame(1, $this->conn->table('domains')->countAllResults());
        $this->assertSame(1, $this->conn->table('users')->countAllResults());

        // down() is safe to repeat.
        (new CreateRenewalItems($this->forge))->down();
        (new CreateRenewalBatches($this->forge))->down();

        // And the tables can be recreated afterwards.
        (new CreateRenewalBatches($this->forge))->up();
        $this->attempted[] = 'renewal_batches';
        (new CreateRenewalItems($this->forge))->up();
        $this->attempted[] = 'renewal_items';
        $this->assertTrue($this->conn->tableExists('renewal_items'));
    }

    public function testRerunningUpFailsCleanlyWithoutChangingAnything(): void
    {
        $user   = $this->insertUser();
        $batch  = $this->insertBatch($user);
        $this->insertItem($batch, $this->insertDomain());

        $batchColumns = $this->columns('renewal_batches');
        $itemColumns  = $this->columns('renewal_items');
        $parents      = $this->parentSnapshot();

        $this->assertRejected(fn () => (new CreateRenewalBatches($this->forge))->up(), 1050); // ER_TABLE_EXISTS_ERROR
        $this->assertRejected(fn () => (new CreateRenewalItems($this->forge))->up(), 1050);

        $this->assertSame($batchColumns, $this->columns('renewal_batches'));
        $this->assertSame($itemColumns, $this->columns('renewal_items'));
        $this->assertSame($parents, $this->parentSnapshot());
        $this->assertSame(1, $this->conn->table('renewal_batches')->countAllResults());
        $this->assertSame(1, $this->conn->table('renewal_items')->countAllResults());
    }
}
