<?php

declare(strict_types=1);

use App\Database\Migrations\CreateDomains;
use App\Database\Migrations\CreateSyncRuns;
use App\Filters\ThrottleFilter;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Database;
use Config\Services;
use Tests\Support\Database\UsesIsolatedAuthDatabase;

/**
 * HTTP-level tests for the read-only domains API against the isolated test
 * database. Reuses the auth helper for users/refresh_tokens and creates
 * sync_runs/domains the same way (only if absent; only those are dropped).
 *
 * @internal
 */
final class DomainEndpointsTest extends CIUnitTestCase
{
    use FeatureTestTrait;
    use UsesIsolatedAuthDatabase {
        tearDownAfterClass as private tearDownAuthSchema;
    }

    private const EMAIL    = 'owner@example.test';
    private const PASSWORD = 'Sentinel-Pass-123!';

    private static bool $domainSchemaCreated = false;

    private string $token = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpIsolatedAuthDatabase();

        if (! self::$domainSchemaCreated) {
            if ($this->testDb->tableExists('domains') || $this->testDb->tableExists('sync_runs')) {
                $this->markTestSkipped('Domain API tests NOT executed: the test database already contains domains/sync_runs; refusing to touch them.');
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

            self::$domainSchemaCreated = true;
        }

        // Only reached for tables this class created (guard above).
        $this->testDb->table('domains')->emptyTable();

        $this->resetThrottleBuckets();
        Services::resetSingle('authService');

        $this->insertUser(['email' => self::EMAIL, 'password_hash' => password_hash(self::PASSWORD, PASSWORD_DEFAULT)]);

        $login = $this->withBodyFormat('json')->post('api/v1/auth/login', ['email' => self::EMAIL, 'password' => self::PASSWORD]);
        $this->assertSame(200, $login->response()->getStatusCode());
        $this->token = json_decode((string) $login->response()->getBody(), true)['access_token'];
    }

    protected function tearDown(): void
    {
        Services::resetSingle('authService');
        $this->resetThrottleBuckets();

        parent::tearDown();
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$domainSchemaCreated) {
            $forge = Database::forge('tests');
            $forge->dropTable('domains', true);
            $forge->dropTable('sync_runs', true);
            self::$domainSchemaCreated = false;
        }

        self::tearDownAuthSchema();
    }

    private function resetThrottleBuckets(): void
    {
        $throttler = service('throttler');

        foreach (array_keys(config('Auth')->ipLimits) as $bucket) {
            foreach (['0.0.0.0', '127.0.0.1', '::1'] as $ip) {
                $throttler->remove(ThrottleFilter::key($bucket, $ip));
            }
        }
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function insertDomain(string $name, array $overrides = []): int
    {
        static $n = 0;
        $n++;

        $this->testDb->table('domains')->insert($overrides + [
            'rc_order_id'    => 'order-' . $n,
            'domain_name'    => $name,
            'first_seen_at'  => '2026-10-01 00:00:00',
            'last_synced_at' => '2026-10-20 08:00:00',
            'created_at'     => '2026-10-01 00:00:00',
            'updated_at'     => '2026-10-01 00:00:00',
        ]);

        return (int) $this->testDb->insertID();
    }

    /**
     * @return array{0: int, 1: array<string, mixed>}
     */
    private function getJson(string $path, bool $authed = true): array
    {
        $req    = $authed ? $this->withHeaders(['Authorization' => 'Bearer ' . $this->token]) : $this;
        $result = $req->get('api/v1/' . $path);

        return [$result->response()->getStatusCode(), json_decode((string) $result->response()->getBody(), true) ?? []];
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return list<string>
     */
    private function names(array $body): array
    {
        return array_column($body['data'], 'domain_name');
    }

    public function testBothEndpointsRejectUnauthenticatedRequests(): void
    {
        $id = $this->insertDomain('a.test');

        foreach (['domains', 'domains/' . $id] as $path) {
            [$status, $body] = $this->getJson($path, false);
            $this->assertSame(401, $status, $path);
            $this->assertSame(['error' => 'unauthorized'], $body);
        }

        $result = $this->withHeaders(['Authorization' => 'Bearer ' . str_repeat('A', 43)])->get('api/v1/domains');
        $this->assertSame(401, $result->response()->getStatusCode());
    }

    public function testEmptyInventoryReturnsEmptyList(): void
    {
        [$status, $body] = $this->getJson('domains');

        $this->assertSame(200, $status);
        $this->assertSame([], $body['data']);
        $this->assertSame(['page' => 1, 'per_page' => 20, 'total' => 0, 'total_pages' => 0], $body['meta']);
        $this->assertNull($body['last_synced_at']);
    }

    public function testAuthenticatedListOnAnEmptyTestDatabaseHasTheExactEmptyContract(): void
    {
        $this->assertSame(0, $this->testDb->table('domains')->countAllResults());

        $result = $this->withHeaders(['Authorization' => 'Bearer ' . $this->token])->get('api/v1/domains');
        $raw    = (string) $result->response()->getBody();
        $body   = json_decode($raw, true);

        $this->assertSame(200, $result->response()->getStatusCode());
        $this->assertSame(['data', 'meta', 'last_synced_at'], array_keys($body));

        // A JSON array, not an object: json_decode(..., true) cannot tell [] from {}.
        $this->assertMatchesRegularExpression('/"data"\s*:\s*\[\s*\]/', $raw);
        $this->assertSame([], $body['data']);

        $this->assertSame(['page', 'per_page', 'total', 'total_pages'], array_keys($body['meta']));
        $this->assertSame(['page' => 1, 'per_page' => 20, 'total' => 0, 'total_pages' => 0], $body['meta']);

        $this->assertArrayHasKey('last_synced_at', $body);
        $this->assertNull($body['last_synced_at']);
    }

    public function testListReturnsDomainsWithTypedFieldsAndNoInternalColumns(): void
    {
        $id = $this->insertDomain('example.test', [
            'status' => 'Active', 'expires_at' => '2027-01-02 03:04:05',
            'auto_renew' => 1, 'privacy_enabled' => 0, 'content_hash' => str_repeat('a', 64),
        ]);

        [$status, $body] = $this->getJson('domains');

        $this->assertSame(200, $status);
        $this->assertCount(1, $body['data']);
        $this->assertSame([
            'id'              => $id,
            'domain_name'     => 'example.test',
            'status'          => 'Active',
            'expires_at'      => '2027-01-02T03:04:05Z',
            'auto_renew'      => true,
            'privacy_enabled' => false,
            'registrar_lock'  => null,
            'is_missing'      => false,
            'last_synced_at'  => '2026-10-20T08:00:00Z',
        ], $body['data'][0]);
        $this->assertSame('2026-10-20T08:00:00Z', $body['last_synced_at']);
        $this->assertSame(1, $body['meta']['total']);
    }

    public function testLastSyncedAtIsTheNewestAndIgnoresSearch(): void
    {
        $this->insertDomain('old.test', ['last_synced_at' => '2026-10-01 00:00:00']);
        $this->insertDomain('new.test', ['last_synced_at' => '2026-10-25 12:30:00']);

        [, $body] = $this->getJson('domains?q=old');

        $this->assertSame(['old.test'], $this->names($body));
        $this->assertSame('2026-10-25T12:30:00Z', $body['last_synced_at']);
    }

    public function testSearchFiltersByDomainNameCaseInsensitively(): void
    {
        $this->insertDomain('alpha.com');
        $this->insertDomain('beta.net');
        $this->insertDomain('alphabet.org');

        [, $body] = $this->getJson('domains?q=ALPHA');

        $this->assertSame(['alpha.com', 'alphabet.org'], $this->names($body));
        $this->assertSame(2, $body['meta']['total']);

        [, $none] = $this->getJson('domains?q=zzz');
        $this->assertSame([], $none['data']);
    }

    public function testSearchTreatsWildcardsAndSqlAsLiteralText(): void
    {
        $this->insertDomain('plain.test');
        $this->insertDomain('under_score.test');
        $this->insertDomain('per%cent.test');

        $this->assertSame(['per%cent.test'], $this->names($this->getJson('domains?q=' . urlencode('%'))[1]));
        $this->assertSame(['under_score.test'], $this->names($this->getJson('domains?q=' . urlencode('_'))[1]));
        $this->assertSame([], $this->names($this->getJson('domains?q=' . urlencode('p_ain'))[1]));
        $this->assertSame([], $this->names($this->getJson('domains?q=' . urlencode("' OR '1'='1"))[1]));
        $this->assertSame([], $this->names($this->getJson('domains?q=' . urlencode("x'; DROP TABLE domains; --"))[1]));

        // The table is still intact and fully listed.
        $this->assertSame(3, $this->getJson('domains')[1]['meta']['total']);
    }

    public function testInvalidQueryParametersAreRejected(): void
    {
        foreach (['page=0', 'page=-1', 'page=abc', 'page=1.5', 'per_page=0', 'per_page=x', 'page[]=1', 'q[]=a', 'q=' . str_repeat('a', 254)] as $qs) {
            $this->assertSame(400, $this->getJson('domains?' . $qs)[0], $qs);
        }
    }

    public function testPaginationAndPerPageCap(): void
    {
        for ($i = 1; $i <= 105; $i++) {
            $this->insertDomain(sprintf('d%03d.test', $i), ['expires_at' => sprintf('2027-01-01 00:%02d:%02d', intdiv($i, 60), $i % 60)]);
        }

        [, $p1] = $this->getJson('domains?per_page=10');
        $this->assertSame(['page' => 1, 'per_page' => 10, 'total' => 105, 'total_pages' => 11], $p1['meta']);
        $this->assertSame('d001.test', $p1['data'][0]['domain_name']);

        [, $p2] = $this->getJson('domains?per_page=10&page=2');
        $this->assertSame('d011.test', $p2['data'][0]['domain_name']);
        $this->assertCount(10, $p2['data']);

        [, $last] = $this->getJson('domains?per_page=10&page=11');
        $this->assertCount(5, $last['data']);

        [, $beyond] = $this->getJson('domains?per_page=10&page=12');
        $this->assertSame([], $beyond['data']);
        $this->assertSame(105, $beyond['meta']['total']);

        [, $capped] = $this->getJson('domains?per_page=1000');
        $this->assertSame(100, $capped['meta']['per_page']);
        $this->assertCount(100, $capped['data']);
        $this->assertSame(2, $capped['meta']['total_pages']);

        [, $default] = $this->getJson('domains');
        $this->assertCount(20, $default['data']);
    }

    public function testOrderingIsByExpiryThenIdWithUnknownExpiryLast(): void
    {
        $this->insertDomain('c.test', ['expires_at' => '2027-03-01 00:00:00']);
        $this->insertDomain('null1.test');
        $this->insertDomain('a.test', ['expires_at' => '2027-01-01 00:00:00']);
        $this->insertDomain('tie1.test', ['expires_at' => '2027-02-01 00:00:00']);
        $this->insertDomain('tie2.test', ['expires_at' => '2027-02-01 00:00:00']);
        $this->insertDomain('null2.test');

        $expected = ['a.test', 'tie1.test', 'tie2.test', 'c.test', 'null1.test', 'null2.test'];

        $this->assertSame($expected, $this->names($this->getJson('domains')[1]));
        $this->assertSame($expected, $this->names($this->getJson('domains')[1]), 'Order must be repeatable.');

        // Pages stitch together without gaps or duplicates.
        $stitched = [];

        foreach ([1, 2, 3] as $page) {
            $stitched = array_merge($stitched, $this->names($this->getJson("domains?per_page=2&page={$page}")[1]));
        }

        $this->assertSame($expected, $stitched);
    }

    public function testDetailReturnsTheMatchingRecord(): void
    {
        $this->insertDomain('other.test');
        $id = $this->insertDomain('detail.test', [
            'rc_order_id' => 'ord-77', 'rc_customer_id' => 'cust-9', 'status' => 'Active',
            'registered_at' => '2025-01-02 03:04:05', 'expires_at' => '2027-01-02 03:04:05',
            'registrar_lock' => 1, 'is_missing' => 1,
            'nameservers_json' => '["ns1.example.test","ns2.example.test"]',
            'content_hash' => str_repeat('b', 64),
        ]);

        [$status, $body] = $this->getJson('domains/' . $id);

        $this->assertSame(200, $status);
        $this->assertSame($id, $body['id']);
        $this->assertSame('detail.test', $body['domain_name']);
        $this->assertSame('ord-77', $body['rc_order_id']);
        $this->assertSame('cust-9', $body['rc_customer_id']);
        $this->assertSame('2025-01-02T03:04:05Z', $body['registered_at']);
        $this->assertSame('2027-01-02T03:04:05Z', $body['expires_at']);
        $this->assertTrue($body['registrar_lock']);
        $this->assertNull($body['auto_renew']);
        $this->assertTrue($body['is_missing']);
        $this->assertSame(['ns1.example.test', 'ns2.example.test'], $body['nameservers']);
        $this->assertArrayNotHasKey('content_hash', $body);
        $this->assertArrayNotHasKey('last_seen_sync_id', $body);
    }

    public function testMissingOrOversizedIdReturns404(): void
    {
        $id = $this->insertDomain('here.test');

        [$status, $body] = $this->getJson('domains/' . ($id + 1000));
        $this->assertSame(404, $status);
        $this->assertSame(['error' => 'not_found'], $body);

        $this->assertSame(404, $this->getJson('domains/99999999999999999999999')[0]);
    }

    public function testWriteMethodsAreNotRouted(): void
    {
        $id = $this->insertDomain('keep.test');

        foreach ([['post', 'api/v1/domains'], ['put', 'api/v1/domains/' . $id], ['delete', 'api/v1/domains/' . $id]] as [$method, $path]) {
            try {
                $status = $this->withHeaders(['Authorization' => 'Bearer ' . $this->token])->{$method}($path)->response()->getStatusCode();
            } catch (\CodeIgniter\Exceptions\PageNotFoundException) {
                $status = 404;
            }

            $this->assertSame(404, $status, $method);
        }

        $this->assertSame(1, $this->testDb->table('domains')->countAllResults());
    }
}
