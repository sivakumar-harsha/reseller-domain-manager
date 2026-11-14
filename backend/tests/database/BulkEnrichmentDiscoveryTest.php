<?php

declare(strict_types=1);

use App\Libraries\Enrichment\BulkEnrichmentException;
use App\Libraries\Enrichment\BulkEnrichmentService;
use App\Libraries\Enrichment\DomainDetailsEnrichmentService;
use App\Libraries\ResellerClub\ResellerClubClient;
use App\Libraries\Sync\DomainSyncService;
use CodeIgniter\Test\CIUnitTestCase;
use Tests\Support\Database\UsesEnrichmentSchema;
use Tests\Support\Database\UsesIsolatedAuthDatabase;
use Tests\Support\Libraries\IsolatedResellerClubConfig;

/**
 * A batch skips its discovery phase (a second full inventory read) when a clean
 * inventory sync finished moments ago, and still enriches only the domains whose
 * details were never synchronised. Fake transport only: any provider call that
 * is not scripted fails the test. No network access.
 *
 * @internal
 */
final class BulkEnrichmentDiscoveryTest extends CIUnitTestCase
{
    use UsesIsolatedAuthDatabase {
        tearDownAfterClass as private tearDownAuthSchema;
    }
    use UsesEnrichmentSchema;

    /** @var list<array{0: string, 1: array<string, scalar>}> */
    private array $calls = [];

    private string $dir = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpIsolatedAuthDatabase();
        $this->setUpEnrichmentSchema();

        $this->calls = [];
        $this->dir   = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'bulk_discovery_' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . DIRECTORY_SEPARATOR . '*') ?: [] as $f) {
            @unlink($f);
        }

        @rmdir($this->dir);
        parent::tearDown();
    }

    public static function tearDownAfterClass(): void
    {
        self::tearDownEnrichmentSchema();
        self::tearDownAuthSchema();
    }

    private function service(): BulkEnrichmentService
    {
        $config              = IsolatedResellerClubConfig::make();
        $config->baseUrl     = 'https://test.httpapi.example/api';
        $config->authUserId  = '123456';
        $config->apiKey      = 'sentinel-api-key-never-store';
        $config->maxAttempts = 1;

        $client = new ResellerClubClient($config, function (string $url, array $query): array {
            $this->calls[] = [$url, $query];

            // Details for any order; the search endpoint is never scripted.
            if (str_contains($url, 'domains/details.json')) {
                return ['status' => 200, 'body' => (string) json_encode([
                    'orderid'    => (string) $query['order-id'],
                    'customerid' => '555001',
                    'ns1'        => 'ns1.example.net',
                    'ns2'        => 'ns2.example.net',
                ])];
            }

            throw new RuntimeException('unexpected provider call: ' . $url);
        }, static function (int $ms): void {
        });

        return new BulkEnrichmentService(
            $this->testDb,
            new DomainDetailsEnrichmentService($this->testDb, $client),
            $this->dir,
            sync: new DomainSyncService($this->testDb, $client, $config),
        );
    }

    private function syncRun(string $status, int $secondsAgo): void
    {
        $at = gmdate('Y-m-d H:i:s', time() - $secondsAgo);
        $this->testDb->table('sync_runs')->insert([
            'trigger_type' => 'scheduled',
            'status'       => $status,
            'started_at'   => $at,
            'finished_at'  => $at,
            'created_at'   => $at,
            'updated_at'   => $at,
        ]);
    }

    private function searchCalls(): int
    {
        return count(array_filter($this->calls, static fn (array $c): bool => str_contains($c[0], 'domains/search.json')));
    }

    private function detailsCalls(): int
    {
        return count(array_filter($this->calls, static fn (array $c): bool => str_contains($c[0], 'domains/details.json')));
    }

    public function testRecentCleanSyncSkipsDiscoveryAndEnrichesOnlyMissingDetails(): void
    {
        $this->insertEnrichableDomain(['rc_order_id' => '900001', 'domain_name' => 'new-one.test']);
        $this->insertEnrichableDomain(['rc_order_id' => '900002', 'domain_name' => 'done-already.test', 'details_synced_at' => '2026-10-30 00:00:00']);
        $this->syncRun('success', 30);

        $service = $this->service();
        $view    = $service->start('missing');

        $this->assertSame('enrich', $view['phase']);
        $this->assertSame('skipped', $view['discovery']['state']);
        $this->assertSame(1, $view['total']);

        $view = $service->step($view['batch_id']);

        $this->assertSame('completed', $view['status']);
        $this->assertSame(1, $this->detailsCalls(), 'only the domain without details is fetched');
        $this->assertSame(0, $this->searchCalls(), 'no second inventory read');
    }

    public function testRecentSyncWithNothingMissingIsNothingToDo(): void
    {
        $this->insertEnrichableDomain(['details_synced_at' => '2026-10-30 00:00:00']);
        $this->syncRun('partial', 10);

        $this->expectException(BulkEnrichmentException::class);

        try {
            $this->service()->start('missing');
        } finally {
            $this->assertSame([], $this->calls);
        }
    }

    public function testStaleOrFailedSyncStillStartsWithDiscovery(): void
    {
        $this->insertEnrichableDomain();
        $this->syncRun('success', 3600);  // too old
        $this->syncRun('failed', 5);      // recent but not clean

        $view = $this->service()->start('missing');

        $this->assertSame('discover', $view['phase']);
        $this->assertSame('pending', $view['discovery']['state']);
        $this->assertSame([], $this->calls, 'starting a batch itself makes no provider call');
    }

    public function testNoSyncHistoryStartsWithDiscovery(): void
    {
        $this->insertEnrichableDomain();

        $this->assertSame('discover', $this->service()->start('refresh')['phase']);
    }

    public function testSingleFlightStillApplies(): void
    {
        $this->insertEnrichableDomain();
        $this->syncRun('success', 5);

        $service = $this->service();
        $service->start('missing');

        $this->expectException(BulkEnrichmentException::class);
        $this->expectExceptionMessage('batch_in_progress');
        $service->start('missing');
    }
}
