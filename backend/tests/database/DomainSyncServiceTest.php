<?php

declare(strict_types=1);

use App\Database\Migrations\CreateDomains;
use App\Database\Migrations\CreateSyncRuns;
use App\Libraries\ResellerClub\ResellerClubClient;
use App\Libraries\Sync\DomainSyncService;
use App\Libraries\Sync\SyncResult;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Exceptions\DatabaseException;
use CodeIgniter\Database\MySQLi\Connection as MySQLiConnection;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Database;
use Config\ResellerClub as ResellerClubConfig;
use Tests\Support\Database\UsesIsolatedAuthDatabase;

/**
 * DomainSyncService against a fake provider transport and the isolated test
 * database. No network access. Creates sync_runs/domains only if absent and
 * drops only what it created (same rules as the other database tests).
 *
 * @internal
 */
final class DomainSyncServiceTest extends CIUnitTestCase
{
    use UsesIsolatedAuthDatabase {
        tearDownAfterClass as private tearDownAuthSchema;
    }

    private static bool $tablesCreated = false;

    /** @var list<array{status: int, body: string}|Throwable> */
    private array $script = [];

    /** @var list<array<string, scalar>> */
    private array $queries = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpIsolatedAuthDatabase();

        if (! self::$tablesCreated) {
            if ($this->testDb->tableExists('domains') || $this->testDb->tableExists('sync_runs')) {
                $this->markTestSkipped('Sync tests NOT executed: the test database already contains domains/sync_runs; refusing to touch them.');
            }

            require_once APPPATH . 'Database/Migrations/2026-10-27-120000_CreateSyncRuns.php';
            require_once APPPATH . 'Database/Migrations/2026-10-27-120100_CreateDomains.php';

            $forge = Database::forge('tests');

            try {
                (new CreateSyncRuns($forge))->up();
                (new CreateDomains($forge))->up();
            } catch (Throwable $e) {
                $forge->dropTable('domains', true);
                $forge->dropTable('sync_runs', true);

                throw $e;
            }

            self::$tablesCreated = true;
        }

        // Only reached for tables this class created (guard above).
        $this->testDb->table('domains')->emptyTable();
        $this->testDb->query('DELETE FROM sync_runs');

        $this->script  = [];
        $this->queries = [];
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$tablesCreated) {
            $forge = Database::forge('tests');
            $forge->dropTable('domains', true);
            $forge->dropTable('sync_runs', true);
            self::$tablesCreated = false;
        }

        self::tearDownAuthSchema();
    }

    private function service(int $pageSize = 2, bool $configured = true, ?BaseConnection $db = null): DomainSyncService
    {
        $config              = new ResellerClubConfig();
        $config->baseUrl     = $configured ? 'https://test.httpapi.example/api' : '';
        $config->authUserId  = '123456';
        $config->apiKey      = 'sentinel-api-key-never-store';
        $config->pageSize    = $pageSize;
        $config->maxAttempts = 1;
        $config->pageDelayMs = 0;

        $client = new ResellerClubClient($config, function (string $url, array $query): array {
            $this->queries[] = $query;
            $next            = array_shift($this->script);

            if ($next instanceof Throwable) {
                throw $next;
            }

            return $next;
        }, static function (int $ms): void {
        });

        return new DomainSyncService($db ?? $this->testDb, $client, $config, null, static function (int $ms): void {
        });
    }

    /**
     * A second connection to the isolated test database that fails (or rewrites)
     * statements matching a regex. Callers must close() it so its lock is freed.
     *
     * @param array<string, string|null> $faults regex => null (throw) or replacement SQL
     */
    private function faulty(array $faults): FaultyConnection
    {
        $connection         = new FaultyConnection((array) config('Database')->tests);
        $connection->faults = $faults;

        return $connection;
    }

    /**
     * @param list<array<string, mixed>> $records
     *
     * @return array{status: int, body: string}
     */
    private function page(array $records, int $total): array
    {
        $body = ['recsonpage' => (string) count($records), 'recsindb' => (string) $total];

        foreach ($records as $i => $r) {
            $body[(string) ($i + 1)] = $r;
        }

        return ['status' => 200, 'body' => json_encode($body)];
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function rec(int $order, string $name, array $overrides = []): array
    {
        return $overrides + [
            'entity.description'      => $name,
            'orders.orderid'          => (string) $order,
            'entity.customerid'       => '555',
            'entity.currentstatus'    => 'Active',
            'orders.creationtime'     => '1735689600', // 2025-01-01 00:00:00 UTC
            'orders.endtime'          => '1767225600', // 2026-01-01 00:00:00 UTC
            'orders.resellerlock'     => 'true',
            'orders.customerlock'     => 'true',
            'orders.transferlock'     => 'true',
            'orders.privacyprotection' => 'false',
            'orders.autorenew'        => 'true',
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function domain(string $name): ?array
    {
        return $this->testDb->table('domains')->where('domain_name', $name)->get()->getRowArray();
    }

    /**
     * @return array<string, mixed>
     */
    private function runRow(int $id): array
    {
        return $this->testDb->table('sync_runs')->where('id', $id)->get()->getRowArray();
    }

    public function testFirstSyncInsertsAcrossPagesAndMapsFields(): void
    {
        $this->script = [
            $this->page([$this->rec(1, 'Alpha.com'), $this->rec(2, 'beta.net')], 3),
            $this->page([$this->rec(3, 'gamma.org', ['orders.privacyprotection' => 'na', 'orders.endtime' => '0', 'orders.autorenew' => 'false'])], 3),
        ];

        $r = $this->service()->run('manual');

        $this->assertSame('success', $r->status);
        $this->assertSame([2, 3, 3, 0, 0, 0], [$r->pages, $r->seen, $r->inserted, $r->updated, $r->unchanged, $r->errors]);
        $this->assertSame([1, 2], array_column($this->queries, 'page-no'));
        $this->assertSame(3, $this->testDb->table('domains')->countAllResults());

        $a = $this->domain('alpha.com');
        $this->assertSame('1', $a['rc_order_id']);
        $this->assertSame('555', $a['rc_customer_id']);
        $this->assertSame('Active', $a['status']);
        $this->assertSame('2025-01-01 00:00:00', $a['registered_at']);
        $this->assertSame('2026-01-01 00:00:00', $a['expires_at']);
        $this->assertNull($a['registrar_lock'], 'Lock fields are unverified and must not become registrar_lock.');
        $this->assertSame('0', (string) $a['privacy_enabled']);
        $this->assertSame('1', (string) $a['auto_renew']);
        $this->assertSame(64, strlen($a['content_hash']));
        $this->assertSame('0', (string) $a['is_missing']);
        $this->assertSame($r->runId, (int) $a['last_seen_sync_id']);

        $g = $this->domain('gamma.org');
        $this->assertNull($g['privacy_enabled']);
        $this->assertNull($g['expires_at']);
        $this->assertSame('0', (string) $g['auto_renew']);

        $run = $this->runRow($r->runId);
        $this->assertSame('success', $run['status']);
        $this->assertSame('manual', $run['trigger_type']);
        $this->assertNotNull($run['finished_at']);
        $this->assertNull($run['error_summary']);
        $this->assertSame('3', (string) $run['inserted']);
    }

    public function testRepeatedSyncCreatesNoDuplicatesAndReportsUnchanged(): void
    {
        $records = [$this->rec(1, 'alpha.com'), $this->rec(2, 'beta.net')];

        $this->script = [$this->page($records, 2)];
        $this->service()->run();

        $this->script = [$this->page($records, 2)];
        $second       = $this->service()->run('scheduled');

        $this->assertSame('success', $second->status);
        $this->assertSame([0, 0, 2], [$second->inserted, $second->updated, $second->unchanged]);
        $this->assertSame(2, $this->testDb->table('domains')->countAllResults());
        $this->assertSame(2, $this->testDb->table('sync_runs')->countAllResults());
        $this->assertSame('scheduled', $this->runRow($second->runId)['trigger_type']);
    }

    public function testChangedFieldsUpdateTheRowAndKeepLocallyUnknownColumns(): void
    {
        $this->script = [$this->page([$this->rec(1, 'alpha.com')], 1)];
        $this->service()->run();
        $this->testDb->table('domains')->where('domain_name', 'alpha.com')->update(['nameservers_json' => '["ns1.x.test"]']);

        $this->script = [$this->page([$this->rec(1, 'alpha.com', ['entity.currentstatus' => 'Suspended', 'orders.endtime' => '1798761600'])], 1)];
        $r            = $this->service()->run();

        $this->assertSame([0, 1, 0], [$r->inserted, $r->updated, $r->unchanged]);
        $d = $this->domain('alpha.com');
        $this->assertSame('Suspended', $d['status']);
        $this->assertSame('2027-01-01 00:00:00', $d['expires_at']);
        $this->assertSame('1', (string) $d['auto_renew']);
        $this->assertSame('["ns1.x.test"]', $d['nameservers_json']);
        $this->assertSame(1, $this->testDb->table('domains')->countAllResults());
    }

    public function testAutoRenewIsMappedDetectedWhenItChangesAndNeverBlankedByAnUnusableValue(): void
    {
        $this->script = [$this->page([
            $this->rec(1, 'on.test', ['orders.autorenew' => 'true']),
            $this->rec(2, 'off.test', ['orders.autorenew' => 'false']),
            $this->rec(3, 'odd.test', ['orders.autorenew' => 'na']),
            $this->rec(4, 'gone.test', ['orders.autorenew' => 'TRUE']),
        ], 4)];
        $this->service(10)->run();

        $this->assertSame('1', (string) $this->domain('on.test')['auto_renew']);
        $this->assertSame('0', (string) $this->domain('off.test')['auto_renew']);
        $this->assertNull($this->domain('odd.test')['auto_renew']);
        $this->assertSame('1', (string) $this->domain('gone.test')['auto_renew']);

        $hash = $this->domain('on.test')['content_hash'];

        // on.test flips (hash must change, so it is an update); off.test now unusable (stored 0 kept);
        // odd.test becomes known; gone.test sends no field at all (stored 1 kept).
        $missing = $this->rec(4, 'gone.test');
        unset($missing['orders.autorenew']);

        $this->script = [$this->page([
            $this->rec(1, 'on.test', ['orders.autorenew' => 'false']),
            $this->rec(2, 'off.test', ['orders.autorenew' => 'maybe']),
            $this->rec(3, 'odd.test', ['orders.autorenew' => 'true']),
            $missing,
        ], 4)];
        $r = $this->service(10)->run();

        $this->assertSame('success', $r->status);
        $this->assertSame([0, 2, 2], [$r->inserted, $r->updated, $r->unchanged]);
        $this->assertSame('0', (string) $this->domain('on.test')['auto_renew']);
        $this->assertNotSame($hash, $this->domain('on.test')['content_hash']);
        $this->assertSame('0', (string) $this->domain('off.test')['auto_renew']);
        $this->assertSame('1', (string) $this->domain('odd.test')['auto_renew']);
        $this->assertSame('1', (string) $this->domain('gone.test')['auto_renew']);
        $this->assertSame(2, $r->warnings);
    }

    public function testUnverifiedLockFieldsNeverReachRegistrarLockAndAStoredValueIsKept(): void
    {
        $locks = fn (string $v): array => ['orders.resellerlock' => $v, 'orders.customerlock' => $v, 'orders.transferlock' => $v];

        foreach (['true', 'false', 'na'] as $value) {
            $this->testDb->table('domains')->emptyTable();
            $this->script = [$this->page([$this->rec(1, 'lock.test', $locks($value))], 1)];
            $this->service(10)->run();

            $this->assertNull($this->domain('lock.test')['registrar_lock'], "lock fields = {$value}");
        }

        // A value that is already stored is not overwritten, whatever the provider's lock fields say.
        $this->testDb->table('domains')->where('domain_name', 'lock.test')->update(['registrar_lock' => 1]);
        $this->script = [$this->page([$this->rec(1, 'lock.test', $locks('false'))], 1)];
        $this->service(10)->run();

        $this->assertSame('1', (string) $this->domain('lock.test')['registrar_lock']);
    }

    public function testDomainsAbsentFromACompleteSyncAreFlaggedMissingAndRestored(): void
    {
        $all          = array_map(fn (int $i): array => $this->rec($i, "d{$i}.test"), range(1, 5));
        $this->script = [$this->page($all, 5)];
        $this->service(10)->run();

        $this->script = [$this->page(array_slice($all, 0, 4), 4)];
        $r            = $this->service(10)->run();

        $this->assertSame('success', $r->status);
        $this->assertSame(1, $r->flaggedMissing);
        $this->assertSame('1', (string) $this->domain('d5.test')['is_missing']);
        $this->assertSame('0', (string) $this->domain('d1.test')['is_missing']);
        $this->assertSame(5, $this->testDb->table('domains')->countAllResults(), 'Missing domains are flagged, never deleted.');

        $this->script = [$this->page($all, 5)];
        $this->service(10)->run();
        $this->assertSame('0', (string) $this->domain('d5.test')['is_missing']);
    }

    public function testMissingDetectionHandlesNullLastSeenPointers(): void
    {
        $all          = array_map(fn (int $i): array => $this->rec($i, "d{$i}.test"), range(1, 5));
        $this->script = [$this->page($all, 5)];
        $this->service(10)->run();

        // Simulates pruned sync_runs: ON DELETE SET NULL clears the pointer.
        $this->testDb->query('UPDATE domains SET last_seen_sync_id = NULL WHERE domain_name = ?', ['d5.test']);

        $this->script = [$this->page(array_slice($all, 0, 4), 4)];
        $this->service(10)->run();

        $this->assertSame('1', (string) $this->domain('d5.test')['is_missing']);
    }

    public function testMissingDetectionRefusesToFlagMoreThanHalfAtOnce(): void
    {
        $all          = array_map(fn (int $i): array => $this->rec($i, "d{$i}.test"), range(1, 4));
        $this->script = [$this->page($all, 4)];
        $this->service(10)->run();

        $this->script = [$this->page([$all[0]], 1)];
        $r            = $this->service(10)->run();

        $this->assertSame(0, $r->flaggedMissing);
        $this->assertSame(0, $this->testDb->table('domains')->where('is_missing', 1)->countAllResults());
        $this->assertStringContainsString('missing_detection_skipped', (string) $this->runRow($r->runId)['error_summary']);
    }

    public function testAProviderFailureMidwayGivesPartialAndNeverFlagsMissing(): void
    {
        $all          = array_map(fn (int $i): array => $this->rec($i, "d{$i}.test"), range(1, 4));
        $this->script = [$this->page($all, 4)];
        $this->service(10)->run();

        $this->script = [$this->page([$all[0], $all[1]], 4), ['status' => 503, 'body' => '']];
        $r            = $this->service(2)->run();

        $this->assertSame('partial', $r->status);
        $this->assertSame(1, $r->pages);
        $this->assertSame(1, $r->errors);
        $this->assertSame(0, $this->testDb->table('domains')->where('is_missing', 1)->countAllResults());
        $this->assertSame('page 2: http_503', $this->runRow($r->runId)['error_summary']);
    }

    public function testFailureOnTheFirstPageIsFailedWithNoDataChange(): void
    {
        $this->script = [new RuntimeException('boom https://x?api-key=sentinel-api-key-never-store')];
        $r            = $this->service()->run();

        $this->assertSame('failed', $r->status);
        $this->assertSame(0, $this->testDb->table('domains')->countAllResults());

        $summary = (string) $this->runRow($r->runId)['error_summary'];
        $this->assertSame('page 1: network_error', $summary);
        $this->assertStringNotContainsString('sentinel', $summary);
    }

    public function testInvalidRecordsAreSkippedCountedAndBlockMissingDetection(): void
    {
        $good         = array_map(fn (int $i): array => $this->rec($i, "d{$i}.test"), range(1, 3));
        $this->script = [$this->page($good, 3)];
        $this->service(10)->run();

        $this->script = [$this->page([
            $good[0], $good[1],
            ['entity.description' => 'no-order.test'],
            $this->rec(9, 'bad name!.test'),
            $this->rec(10, 'x.test', ['entity.currentstatus' => str_repeat('s', 65)]),
        ], 5)];
        $r = $this->service(10)->run();

        $this->assertSame('partial', $r->status);
        // Errors: no order id, and a status that is too long. The unusable name with a valid order id is skipped, not an error.
        $this->assertSame([5, 2, 1], [$r->seen, $r->errors, $r->skipped]);
        $this->assertSame(0, $r->flaggedMissing, 'An incomplete run must not flag d3.test as missing.');
        $this->assertSame('0', (string) $this->domain('d3.test')['is_missing']);
        $this->assertNull($this->domain('x.test'));
        $this->assertNull($this->domain('bad name!.test'));
    }

    public function testInternationalisedNamesAreStoredAsCanonicalPunycode(): void
    {
        $this->script = [$this->page([
            $this->rec(1, 'Bücher.EXAMPLE'),
            $this->rec(2, 'xn--mnchen-3ya.de'),
            $this->rec(3, '日本語.jp'),
            $this->rec(4, 'MixedCase.Example.COM.'),
        ], 4)];

        $r = $this->service(10)->run();

        $this->assertSame('success', $r->status);
        $this->assertSame([4, 0, 0], [$r->inserted, $r->errors, $r->skipped]);
        $this->assertSame('1', $this->domain('xn--bcher-kva.example')['rc_order_id']);
        $this->assertSame('2', $this->domain('xn--mnchen-3ya.de')['rc_order_id']);
        $this->assertSame('3', $this->domain('xn--wgv71a119e.jp')['rc_order_id']);
        $this->assertSame('4', $this->domain('mixedcase.example.com')['rc_order_id']);
        $this->assertSame(4, $this->testDb->table('domains')->countAllResults());
    }

    public function testUnicodeAndPunycodeSpellingsOfOneNameAreOneIdentityAcrossRuns(): void
    {
        $this->script = [$this->page([$this->rec(1, 'bücher.example')], 1)];
        $this->service(10)->run();

        // Same order, now delivered as Punycode in different case: nothing changes.
        $this->script = [$this->page([$this->rec(1, 'XN--BCHER-KVA.example')], 1)];
        $r            = $this->service(10)->run();

        $this->assertSame('success', $r->status);
        $this->assertSame([0, 0, 1], [$r->inserted, $r->updated, $r->unchanged]);
        $this->assertSame(1, $this->testDb->table('domains')->countAllResults());

        // A different order with the same canonical name is a conflict, never a merge.
        $this->script = [$this->page([$this->rec(1, 'bücher.example'), $this->rec(2, 'BÜCHER.example')], 2)];
        $r            = $this->service(10)->run();

        $this->assertSame('success', $r->status);
        $this->assertSame(1, $r->warnings);
        $this->assertSame(1, $this->testDb->table('domains')->countAllResults());
        $this->assertSame('1', $this->domain('xn--bcher-kva.example')['rc_order_id']);
    }

    public function testAnUnusableNameWithAValidOrderIdIsSkippedAndTheRunIsPartial(): void
    {
        $this->script = [$this->page([
            $this->rec(1, 'good.test'),
            $this->rec(2, 'exa mple.test'),       // whitespace
            $this->rec(3, ' padded.test'),        // never trimmed
            $this->rec(4, 'xn--bcher-kv.example'), // malformed Punycode
            $this->rec(5, 'single'),              // no dot
        ], 5)];

        $r = $this->service(10)->run();

        $this->assertSame('partial', $r->status);
        $this->assertSame([5, 1, 4, 0], [$r->seen, $r->inserted, $r->skipped, $r->errors]);
        $this->assertSame(0, $r->flaggedMissing);
        $this->assertSame(1, $this->testDb->table('domains')->countAllResults(), 'Skipped records are never inserted.');

        $run = $this->runRow($r->runId);
        $this->assertSame('partial', $run['status']);
        $this->assertSame('0', (string) $run['error_count']);
        $this->assertStringContainsString('4 skipped_unusable_name (orders 2, 3, 4, 5)', (string) $run['error_summary']);
        $this->assertStringNotContainsString('count_mismatch', (string) $run['error_summary'], 'Skipped orders still count as seen.');

        foreach (['exa mple', 'padded', 'xn--bcher-kv', 'single'] as $raw) {
            $this->assertStringNotContainsString($raw, (string) $run['error_summary'], 'The summary never carries raw names.');
        }
    }

    public function testAnExistingRowIsOnlyTouchedForTrackingWhenItsNameBecomesUnusable(): void
    {
        $all          = array_map(fn (int $i): array => $this->rec($i, "d{$i}.test"), range(1, 4));
        $this->script = [$this->page($all, 4)];
        $first        = $this->service(10)->run();

        $this->testDb->table('domains')->where('domain_name', 'd2.test')->update(['is_missing' => 1]);
        $before = $this->domain('d2.test');

        // Order 2 now arrives with a bad name and different business data; order 4 is absent from the page.
        $bad          = $this->rec(2, 'bad name.test', ['entity.currentstatus' => 'Suspended', 'orders.endtime' => '1798761600', 'orders.autorenew' => 'false']);
        $this->script = [$this->page([$all[0], $bad, $all[2]], 3)];
        $r            = $this->service(10)->run();

        $this->assertSame('partial', $r->status);
        $this->assertSame(1, $r->skipped);
        $this->assertSame(0, $r->flaggedMissing, 'Missing detection is off for every partial run.');

        $after = $this->domain('d2.test');
        $this->assertSame('d2.test', $after['domain_name']);
        $this->assertSame('0', (string) $after['is_missing'], 'A skipped order that already exists is seen, not missing.');
        $this->assertSame($r->runId, (int) $after['last_seen_sync_id']);

        foreach (['rc_order_id', 'rc_customer_id', 'status', 'registered_at', 'expires_at', 'auto_renew', 'privacy_enabled', 'registrar_lock', 'content_hash', 'first_seen_at', 'updated_at'] as $field) {
            $this->assertSame($before[$field], $after[$field], "{$field} must be preserved");
        }

        $this->assertSame('0', (string) $this->domain('d4.test')['is_missing'], 'Absent from a partial run, still not flagged.');
        $this->assertSame(4, $this->testDb->table('domains')->countAllResults());
        $this->assertGreaterThan($first->runId, $r->runId);
    }

    public function testRepeatedSyncsWithASkippedRecordStayPartialAndCreateNothing(): void
    {
        $page = [$this->rec(1, 'ok.test'), $this->rec(2, 'bad name.test')];

        foreach ([1, 2, 3] as $attempt) {
            $this->script = [$this->page($page, 2)];
            $r            = $this->service(10)->run();

            $this->assertSame('partial', $r->status, "attempt {$attempt}");
            $this->assertSame([$attempt === 1 ? 1 : 0, 1, 0], [$r->inserted, $r->skipped, $r->errors], "attempt {$attempt}");
            $this->assertSame(1, $this->testDb->table('domains')->countAllResults(), "attempt {$attempt}");
        }
    }

    public function testAnOnlySkippedRunIsPartialAndASkippedOrderDoesNotHideACountMismatch(): void
    {
        $this->script = [$this->page([$this->rec(1, 'bad name.test')], 1)];
        $r            = $this->service(10)->run();

        $this->assertSame('partial', $r->status);
        $this->assertSame([0, 1, 0], [$r->inserted, $r->skipped, $r->errors]);

        // The provider claims 3 orders but delivers 1 (skipped): still a mismatch, still not success.
        $this->script = [$this->page([$this->rec(1, 'bad name.test')], 3)];
        $r            = $this->service(10)->run();

        $this->assertNotSame('success', $r->status);
        $this->assertSame(1, $r->errors);
        $this->assertStringContainsString('count_mismatch', (string) $this->runRow($r->runId)['error_summary']);
    }

    public function testADomainNameOwnedByAnotherOrderIsNeverOverwrittenAndDoesNotFailTheRunForever(): void
    {
        $this->script = [$this->page([$this->rec(1, 'alpha.com')], 1)];
        $this->service()->run();

        // Policy: a conflict is a warning. The existing record is kept as is and the run is not stuck as partial.
        foreach ([1, 2] as $attempt) {
            $this->script = [$this->page([$this->rec(2, 'alpha.com'), $this->rec(3, 'other.com')], 2)];
            $r            = $this->service()->run();

            $this->assertSame('success', $r->status, "attempt {$attempt}");
            $this->assertSame(0, $r->errors);
            $this->assertSame(1, $r->warnings);
            $this->assertSame('1', $this->domain('alpha.com')['rc_order_id']);
            $this->assertStringContainsString('name_conflict order 2', (string) $this->runRow($r->runId)['error_summary']);
        }

        $this->assertSame(2, $this->testDb->table('domains')->countAllResults());
        $this->assertNotNull($this->domain('other.com'), 'Unrelated records are still processed.');
        $this->assertNull($this->testDb->table('domains')->where('rc_order_id', '2')->get()->getRowArray(), 'The conflicting order is not stored.');
    }

    public function testDuplicateNamesOnOnePageKeepTheFirstAndTheRestOfThePageIsProcessed(): void
    {
        $page = [$this->rec(1, 'dup.test'), $this->rec(2, 'DUP.test'), $this->rec(3, 'ok.test')];

        foreach ([1, 2] as $attempt) {
            $this->script = [$this->page($page, 3)];
            $r            = $this->service(10)->run();

            $this->assertSame('success', $r->status, "attempt {$attempt}");
            $this->assertSame([0, 1], [$r->errors, $r->warnings]);
            $this->assertSame('1', $this->domain('dup.test')['rc_order_id']);
            $this->assertSame(2, $this->testDb->table('domains')->countAllResults());
        }

        $this->assertSame([0, 0, 2], [$r->inserted, $r->updated, $r->unchanged]);
    }

    public function testARolledBackFirstPageReportsNoCommittedChangesAndIsFailed(): void
    {
        $db = $this->faulty(['/INSERT INTO .*bad\.test/s' => null]);

        try {
            $this->script = [$this->page([$this->rec(1, 'good.test'), $this->rec(2, 'bad.test'), $this->rec(3, 'later.test')], 3)];
            $r            = $this->service(10, true, $db)->run();
        } finally {
            $db->close();
        }

        $this->assertSame('failed', $r->status);
        $this->assertSame([0, 0, 0, 0, 1], [$r->seen, $r->inserted, $r->updated, $r->unchanged, $r->errors]);
        $this->assertSame(0, $this->testDb->table('domains')->countAllResults(), 'good.test was inserted inside the rolled-back transaction.');

        $run = $this->runRow($r->runId);
        $this->assertSame('failed', $run['status']);
        $this->assertSame('0', (string) $run['inserted']);
        $this->assertSame('0', (string) $run['records_seen']);
        $this->assertStringContainsString('page 1: storage_error', (string) $run['error_summary']);
        $this->assertStringNotContainsString('INSERT', (string) $run['error_summary'], 'No SQL in the summary.');
        $this->assertStringNotContainsString('injected', (string) $run['error_summary']);
    }

    public function testARolledBackLaterPageKeepsOnlyCommittedCountersAndNeverFlagsMissing(): void
    {
        $db = $this->faulty(['/INSERT INTO .*bad\.test/s' => null]);

        try {
            $this->script = [
                $this->page([$this->rec(1, 'a.test'), $this->rec(2, 'b.test')], 4),
                $this->page([$this->rec(3, 'c.test'), $this->rec(4, 'bad.test')], 4),
            ];
            $r = $this->service(2, true, $db)->run();
        } finally {
            $db->close();
        }

        $this->assertSame('partial', $r->status);
        $this->assertSame(2, $r->pages);
        $this->assertSame([2, 2, 1], [$r->seen, $r->inserted, $r->errors], 'Only the committed first page is counted.');
        $this->assertSame(2, $this->testDb->table('domains')->countAllResults());
        $this->assertNull($this->domain('c.test'));
        $this->assertSame(0, $r->flaggedMissing);
    }

    public function testAFailingLockQueryIsReportedAsALockErrorNotAsAnotherRun(): void
    {
        foreach ([['/GET_LOCK/' => null], ['/GET_LOCK/' => 'SELECT NULL AS got']] as $faults) {
            $db = $this->faulty($faults);

            try {
                $this->script = [$this->page([$this->rec(1, 'a.test')], 1)];
                $r            = $this->service(10, true, $db)->run();
            } finally {
                $db->close();
            }

            $this->assertSame(SyncResult::LOCK_ERROR, $r->status);
            $this->assertFalse($r->ran());
            $this->assertSame([], $this->queries, 'The provider must not be called without the lock.');
            $this->assertSame(0, $this->testDb->table('sync_runs')->countAllResults());
        }
    }

    public function testAFailingLockReleaseDoesNotHideTheResult(): void
    {
        $db = $this->faulty(['/RELEASE_LOCK/' => null]);

        try {
            $this->script = [$this->page([$this->rec(1, 'a.test')], 1)];
            $r            = $this->service(10, true, $db)->run();
        } finally {
            $db->close(); // closing the connection frees the lock server-side
        }

        $this->assertSame('success', $r->status);
        $this->assertSame(1, $r->inserted);

        // A later run can take the lock again.
        $this->script = [$this->page([$this->rec(1, 'a.test')], 1)];
        $this->assertSame('success', $this->service(10)->run()->status);
    }

    public function testAFailureToStartTheRunRecordIsAStorageErrorAndNothingIsSynced(): void
    {
        $db = $this->faulty(['/INSERT INTO\s+`?sync_runs/' => null]);

        try {
            $this->script = [$this->page([$this->rec(1, 'a.test')], 1)];
            $r            = $this->service(10, true, $db)->run();
        } finally {
            $db->close();
        }

        $this->assertSame(SyncResult::STORAGE_ERROR, $r->status);
        $this->assertFalse($r->ran());
        $this->assertSame([], $this->queries);
        $this->assertSame(0, $this->testDb->table('domains')->countAllResults());
    }

    public function testAFailureToSaveTheResultIsReportedAsSuchAndTheRowIsLaterAbandoned(): void
    {
        $db = $this->faulty(['/pages_fetched/' => null]);

        try {
            $this->script = [$this->page([$this->rec(1, 'a.test')], 1)];
            $r            = $this->service(10, true, $db)->run();
        } finally {
            $db->close();
        }

        $this->assertSame(SyncResult::RECORD_FAILED, $r->status);
        $this->assertSame('success', $r->outcome);
        $this->assertSame(1, $r->inserted);
        $this->assertSame('running', $this->runRow($r->runId)['status'], 'The row could not be finalised.');
        $this->assertSame(1, $this->testDb->table('domains')->countAllResults());

        $this->script = [$this->page([$this->rec(1, 'a.test')], 1)];
        $this->assertSame('success', $this->service(10)->run()->status);

        $stale = $this->runRow($r->runId);
        $this->assertSame('failed', $stale['status']);
        $this->assertSame('abandoned_run', $stale['error_summary']);
    }

    public function testUnusableProviderFieldsNeverBlankStoredValuesAndAreReportedAsWarnings(): void
    {
        $this->script = [$this->page([$this->rec(1, 'a.test'), $this->rec(2, 'b.test')], 2)];
        $this->service(10)->run();

        $stored = $this->domain('a.test');

        $this->script = [$this->page([
            // Everything optional missing, invalid or "na".
            ['entity.description' => 'a.test', 'orders.orderid' => '1', 'entity.customerid' => 'abc', 'entity.currentstatus' => '',
                'orders.creationtime' => 'soon', 'orders.endtime' => '0', 'orders.privacyprotection' => 'na'],
            // One real change (status) next to an unusable expiry.
            $this->rec(2, 'b.test', ['entity.currentstatus' => 'Suspended', 'orders.endtime' => '-5']),
        ], 2)];
        $r = $this->service(10)->run();

        $this->assertSame('success', $r->status);
        $this->assertSame(0, $r->errors);
        $this->assertSame([0, 1, 1], [$r->inserted, $r->updated, $r->unchanged]);
        $this->assertGreaterThan(0, $r->warnings);

        $a = $this->domain('a.test');

        foreach (['rc_customer_id', 'status', 'registered_at', 'expires_at', 'auto_renew', 'privacy_enabled', 'registrar_lock'] as $field) {
            $this->assertSame($stored[$field], $a[$field], $field);
        }

        $b = $this->domain('b.test');
        $this->assertSame('Suspended', $b['status'], 'Usable fields still update.');
        $this->assertSame('2026-01-01 00:00:00', $b['expires_at'], 'Unusable expiry keeps the stored value.');
        $this->assertStringContainsString('stored value(s) kept', (string) $this->runRow($r->runId)['error_summary']);
    }

    public function testARepeatedPageStopsTheRunIncompleteAndSkipsMissingDetection(): void
    {
        $all          = array_map(fn (int $i): array => $this->rec($i, "d{$i}.test"), range(1, 5));
        $this->script = [$this->page($all, 5)];
        $this->service(10)->run();

        $same = $this->page([$all[0], $all[1]], 5);
        $this->script = [$same, $same, $same, $same];
        $this->queries = [];
        $r = $this->service(2)->run();

        $this->assertSame('partial', $r->status);
        $this->assertSame(2, $r->pages);
        $this->assertCount(2, $this->queries, 'The provider is not asked for a third page.');
        $this->assertSame(1, $r->errors);
        $this->assertSame(0, $r->flaggedMissing);
        $this->assertSame(0, $this->testDb->table('domains')->where('is_missing', 1)->countAllResults());
        $this->assertStringContainsString('no_new_orders', (string) $this->runRow($r->runId)['error_summary']);
    }

    public function testRepeatedRecordsAcrossPagesAreCountedOnce(): void
    {
        $this->script = [
            $this->page([$this->rec(1, 'a.test'), $this->rec(2, 'b.test')], 3),
            $this->page([$this->rec(2, 'b.test'), $this->rec(3, 'c.test')], 3),
        ];
        $r = $this->service()->run();

        $this->assertSame(3, $this->testDb->table('domains')->countAllResults());
        $this->assertSame(3, $r->inserted);
        $this->assertSame('success', $r->status);
    }

    public function testAnEmptyInventoryNeverFlagsEverythingMissing(): void
    {
        $this->script = [$this->page([$this->rec(1, 'a.test'), $this->rec(2, 'b.test')], 2)];
        $this->service(10)->run();

        $this->script = [$this->page([], 0)];
        $r            = $this->service(10)->run();

        $this->assertSame(0, $r->flaggedMissing);
        $this->assertSame(0, $this->testDb->table('domains')->where('is_missing', 1)->countAllResults());
    }

    public function testCountMismatchMakesTheRunIncomplete(): void
    {
        $this->script = [$this->page([$this->rec(1, 'a.test')], 5)];
        $r            = $this->service(10)->run();

        $this->assertNotSame('success', $r->status);
        $this->assertStringContainsString('count_mismatch', (string) $this->runRow($r->runId)['error_summary']);
    }

    public function testNotConfiguredCreatesNoRunAndCallsNothing(): void
    {
        $r = $this->service(2, false)->run();

        $this->assertFalse($r->ran());
        $this->assertSame(SyncResult::NOT_CONFIGURED, $r->status);
        $this->assertSame(0, $this->testDb->table('sync_runs')->countAllResults());
        $this->assertSame([], $this->queries);
    }

    public function testAnOverlappingRunIsRefusedWhileTheLockIsHeld(): void
    {
        $other = Database::connect('tests', false);
        $row   = $other->query('SELECT GET_LOCK(?, 0) AS got', [DomainSyncService::LOCK_NAME])->getRowArray();
        $this->assertSame(1, (int) $row['got']);

        try {
            $this->script = [$this->page([$this->rec(1, 'a.test')], 1)];
            $r            = $this->service()->run();

            $this->assertSame(SyncResult::ALREADY_RUNNING, $r->status);
            $this->assertSame(0, $this->testDb->table('sync_runs')->countAllResults());
            $this->assertSame([], $this->queries);
        } finally {
            $other->query('SELECT RELEASE_LOCK(?)', [DomainSyncService::LOCK_NAME]);
            $other->close();
        }
    }

    public function testAbandonedRunningRowsAreMarkedFailedAndTheLockIsReleased(): void
    {
        $this->testDb->table('sync_runs')->insert([
            'trigger_type' => 'manual', 'status' => 'running', 'started_at' => '2026-10-01 00:00:00',
            'created_at' => '2026-10-01 00:00:00', 'updated_at' => '2026-10-01 00:00:00',
        ]);
        $staleId = (int) $this->testDb->insertID();

        $this->script = [$this->page([$this->rec(1, 'a.test')], 1)];
        $this->assertSame('success', $this->service()->run()->status);

        $stale = $this->runRow($staleId);
        $this->assertSame('failed', $stale['status']);
        $this->assertSame('abandoned_run', $stale['error_summary']);
        $this->assertNotNull($stale['finished_at']);

        // The lock was released: a second run can start.
        $this->script = [$this->page([$this->rec(1, 'a.test')], 1)];
        $this->assertSame('success', $this->service()->run()->status);
    }

    public function testCredentialsNeverReachTheDatabase(): void
    {
        $this->script = [$this->page([$this->rec(1, 'a.test')], 1), new RuntimeException('x sentinel-api-key-never-store')];
        $this->service()->run();
        $this->service()->run();

        $dump = json_encode([
            $this->testDb->table('sync_runs')->get()->getResultArray(),
            $this->testDb->table('domains')->get()->getResultArray(),
        ]);

        $this->assertStringNotContainsString('sentinel-api-key-never-store', $dump);
        $this->assertStringNotContainsString('123456', (string) json_encode($this->testDb->table('sync_runs')->get()->getResultArray()));
    }
}

/**
 * Test-only MySQLi connection that throws (or rewrites the SQL) for statements
 * matching a regex, to exercise database failure handling.
 */
final class FaultyConnection extends MySQLiConnection
{
    /** @var array<string, string|null> regex => null (throw) or replacement SQL */
    public array $faults = [];

    protected function execute(string $sql)
    {
        foreach ($this->faults as $pattern => $replacement) {
            if (preg_match($pattern, $sql) === 1) {
                if ($replacement === null) {
                    throw new DatabaseException('injected fault: ' . $sql);
                }

                $sql = $replacement;
            }
        }

        return parent::execute($sql);
    }
}

// CodeIgniter derives the Result/Builder class names by replacing "Connection" in the class name.
class_alias(\CodeIgniter\Database\MySQLi\Result::class, 'FaultyResult');
class_alias(\CodeIgniter\Database\MySQLi\Builder::class, 'FaultyBuilder');
