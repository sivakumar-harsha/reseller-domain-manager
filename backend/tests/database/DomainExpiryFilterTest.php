<?php

declare(strict_types=1);

use App\Database\Migrations\CreateDomains;
use App\Database\Migrations\CreateSyncRuns;
use App\Filters\ThrottleFilter;
use CodeIgniter\I18n\Time;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Database;
use Config\Services;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\Database\UsesIsolatedAuthDatabase;

/**
 * `expiry` filters and `sort` options of GET api/v1/domains against the isolated
 * test database. The clock is fixed (2026-10-30 17:30 Asia/Kolkata); calendar days
 * are Kolkata days and stored instants are UTC.
 *
 * @internal
 */
final class DomainExpiryFilterTest extends CIUnitTestCase
{
    use FeatureTestTrait;
    use UsesIsolatedAuthDatabase {
        tearDownAfterClass as private tearDownAuthSchema;
    }

    private const EMAIL    = 'owner@example.test';
    private const PASSWORD = 'Sentinel-Pass-123!';
    private const NOW_UTC  = '2026-10-30 12:00:00';

    private static bool $domainSchemaCreated = false;

    private string $token = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpIsolatedAuthDatabase();

        if (! self::$domainSchemaCreated) {
            if ($this->testDb->tableExists('domains') || $this->testDb->tableExists('sync_runs')) {
                $this->markTestSkipped('Expiry filter tests NOT executed: the test database already contains domains/sync_runs; refusing to touch them.');
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

        Time::setTestNow(self::NOW_UTC, 'UTC');
    }

    protected function tearDown(): void
    {
        Time::setTestNow();
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

    /** UTC "Y-m-d H:i:s" of a wall-clock time on the Kolkata day $offset days from 2026-10-30. */
    private function ist(int $offset, string $time): string
    {
        return (new DateTimeImmutable('2026-10-30 ' . $time, new DateTimeZone('Asia/Kolkata')))
            ->modify(($offset >= 0 ? '+' : '') . $offset . ' days')
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d H:i:s');
    }

    private function insertDomain(string $name, ?string $expiresAt, bool $missing = false): void
    {
        static $n = 0;
        $n++;

        $this->testDb->table('domains')->insert([
            'rc_order_id'    => 'order-' . $n,
            'domain_name'    => $name,
            'expires_at'     => $expiresAt,
            'is_missing'     => $missing ? 1 : 0,
            'first_seen_at'  => '2026-10-01 00:00:00',
            'last_synced_at' => '2026-10-20 08:00:00',
            'created_at'     => '2026-10-01 00:00:00',
            'updated_at'     => '2026-10-01 00:00:00',
        ]);
    }

    /**
     * Ten active domains on the boundaries plus three missing ones. Insert order
     * is the id order.
     */
    private function seedInventory(): void
    {
        $this->insertDomain('a-exp.test', $this->ist(-1, '23:59:00'));
        $this->insertDomain('b-today.test', $this->ist(0, '00:00:00'));
        $this->insertDomain('c-d7.test', $this->ist(7, '23:59:00'));
        $this->insertDomain('d-d8.test', $this->ist(8, '00:00:00'));
        $this->insertDomain('e-d30.test', $this->ist(30, '23:59:00'));
        $this->insertDomain('f-d60.test', $this->ist(60, '23:59:00'));
        $this->insertDomain('g-d90.test', $this->ist(90, '23:59:00'));
        $this->insertDomain('h-d91.test', $this->ist(91, '00:00:00'));
        $this->insertDomain('i-null.test', null);
        $this->insertDomain('j-null.test', null);
        $this->insertDomain('missing-old.test', $this->ist(-5, '10:00:00'), true);
        $this->insertDomain('missing-soon.test', $this->ist(3, '10:00:00'), true);
        $this->insertDomain('missing-null.test', null, true);
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
     * @return list<string>
     */
    private function names(string $query): array
    {
        [$status, $body] = $this->getJson('domains?per_page=100' . ($query === '' ? '' : '&' . $query));
        $this->assertSame(200, $status, $query);

        return array_column($body['data'], 'domain_name');
    }

    /**
     * @return array<string, array{0: string, 1: list<string>}>
     */
    public static function validFilters(): array
    {
        return [
            'expired'        => ['expired', ['a-exp.test']],
            'within_7_days'  => ['within_7_days', ['b-today.test', 'c-d7.test']],
            'within_30_days' => ['within_30_days', ['b-today.test', 'c-d7.test', 'd-d8.test', 'e-d30.test']],
            'within_60_days' => ['within_60_days', ['b-today.test', 'c-d7.test', 'd-d8.test', 'e-d30.test', 'f-d60.test']],
            'within_90_days' => ['within_90_days', ['b-today.test', 'c-d7.test', 'd-d8.test', 'e-d30.test', 'f-d60.test', 'g-d90.test']],
            'unknown'        => ['unknown', ['i-null.test', 'j-null.test']],
        ];
    }

    /**
     * @param list<string> $expected
     */
    #[DataProvider('validFilters')]
    public function testEachExpiryFilterReturnsItsCumulativeSetInExpiryOrder(string $filter, array $expected): void
    {
        $this->seedInventory();

        $this->assertSame($expected, $this->names('expiry=' . $filter));
    }

    public function testFilterAllAndNoFilterKeepMissingDomains(): void
    {
        $this->seedInventory();

        $all = $this->names('expiry=all');

        $this->assertCount(13, $all);
        $this->assertContains('missing-old.test', $all);
        $this->assertSame($all, $this->names(''));
    }

    public function testEveryExpiryFilterExcludesMissingDomains(): void
    {
        $this->seedInventory();

        foreach (['expired', 'within_7_days', 'within_90_days', 'unknown'] as $filter) {
            foreach ($this->names('expiry=' . $filter) as $name) {
                $this->assertStringStartsNotWith('missing-', $name, $filter);
            }
        }
    }

    public function testInvalidExpiryFilterIsRejected(): void
    {
        $this->seedInventory();

        foreach (['bogus', '', 'within_14_days', 'EXPIRED', 'expired%20', 'expiry[]=x'] as $bad) {
            $query = str_contains($bad, '=') ? $bad : 'expiry=' . $bad;
            [$status, $body] = $this->getJson('domains?' . $query);

            $this->assertSame(400, $status, $query);
            $this->assertSame(['error' => 'invalid_request'], $body, $query);
        }
    }

    public function testDefaultOrderIsNearestExpiryFirstWithNullsLast(): void
    {
        $this->seedInventory();

        $expected = [
            'missing-old.test', 'a-exp.test', 'b-today.test', 'missing-soon.test', 'c-d7.test', 'd-d8.test',
            'e-d30.test', 'f-d60.test', 'g-d90.test', 'h-d91.test', 'i-null.test', 'j-null.test', 'missing-null.test',
        ];

        $this->assertSame($expected, $this->names(''));
        $this->assertSame($expected, $this->names('sort=expiry_asc'));
    }

    public function testExpiryDescendingKeepsNullsLast(): void
    {
        $this->seedInventory();

        $this->assertSame([
            'h-d91.test', 'g-d90.test', 'f-d60.test', 'e-d30.test', 'd-d8.test', 'c-d7.test', 'missing-soon.test',
            'b-today.test', 'a-exp.test', 'missing-old.test', 'i-null.test', 'j-null.test', 'missing-null.test',
        ], $this->names('sort=expiry_desc'));
    }

    public function testNameAscendingIsAlphabetical(): void
    {
        $this->seedInventory();

        $this->assertSame([
            'a-exp.test', 'b-today.test', 'c-d7.test', 'd-d8.test', 'e-d30.test', 'f-d60.test', 'g-d90.test',
            'h-d91.test', 'i-null.test', 'j-null.test', 'missing-null.test', 'missing-old.test', 'missing-soon.test',
        ], $this->names('sort=name_asc'));
    }

    public function testEqualExpiryDatesFallBackToIdOrderInBothDirections(): void
    {
        $same = $this->ist(10, '12:00:00');
        $this->insertDomain('zeta.test', $same);
        $this->insertDomain('alpha.test', $same);
        $this->insertDomain('mid.test', $same);

        $this->assertSame(['zeta.test', 'alpha.test', 'mid.test'], $this->names('sort=expiry_asc'));
        $this->assertSame(['zeta.test', 'alpha.test', 'mid.test'], $this->names('sort=expiry_desc'));
    }

    public function testInvalidSortIsRejected(): void
    {
        $this->seedInventory();

        foreach (['sort=bogus', 'sort=', 'sort=NAME_ASC', 'sort=name_desc', 'sort[]=name_asc'] as $query) {
            [$status, $body] = $this->getJson('domains?' . $query);

            $this->assertSame(400, $status, $query);
            $this->assertSame(['error' => 'invalid_request'], $body, $query);
        }
    }

    public function testSortCombinesWithAFilter(): void
    {
        $this->seedInventory();

        $this->assertSame(
            ['g-d90.test', 'f-d60.test', 'e-d30.test', 'd-d8.test', 'c-d7.test', 'b-today.test'],
            $this->names('expiry=within_90_days&sort=expiry_desc'),
        );
        $this->assertSame(
            ['b-today.test', 'c-d7.test', 'd-d8.test', 'e-d30.test', 'f-d60.test', 'g-d90.test'],
            $this->names('expiry=within_90_days&sort=name_asc'),
        );
    }

    public function testSearchCombinesWithExpiryFilters(): void
    {
        $this->seedInventory();

        $this->assertSame(['c-d7.test'], $this->names('q=d7&expiry=within_30_days'));
        $this->assertSame([], $this->names('q=d7&expiry=expired'));
        // The search still sees the missing domain without a filter, but a filter drops it.
        $this->assertSame(['i-null.test', 'j-null.test', 'missing-null.test'], $this->names('q=null'));
        $this->assertSame(['i-null.test', 'j-null.test'], $this->names('q=null&expiry=unknown'));
    }

    public function testPaginationMetaAndPagesFollowTheFilter(): void
    {
        $this->seedInventory();

        [$status, $page2] = $this->getJson('domains?expiry=within_90_days&per_page=2&page=2');

        $this->assertSame(200, $status);
        $this->assertSame(['d-d8.test', 'e-d30.test'], array_column($page2['data'], 'domain_name'));
        $this->assertSame(['page' => 2, 'per_page' => 2, 'total' => 6, 'total_pages' => 3], $page2['meta']);

        [, $page3] = $this->getJson('domains?expiry=within_90_days&per_page=2&page=3');
        $this->assertSame(['f-d60.test', 'g-d90.test'], array_column($page3['data'], 'domain_name'));

        [, $beyond] = $this->getJson('domains?expiry=within_90_days&per_page=2&page=4');
        $this->assertSame([], $beyond['data']);
        $this->assertSame(6, $beyond['meta']['total']);
    }

    public function testFilteredTotalsMatchTheSummaryCounts(): void
    {
        $this->seedInventory();

        [, $summary] = $this->getJson('expiry/summary');

        foreach (['expired', 'within_7_days', 'within_30_days', 'within_60_days', 'within_90_days', 'unknown'] as $key) {
            [, $list] = $this->getJson('domains?per_page=1&expiry=' . $key);

            $this->assertSame($summary[$key], $list['meta']['total'], $key);
        }
    }

    public function testFiltersFollowTheKolkataDayAtMidnight(): void
    {
        $this->insertDomain('edge.test', '2026-10-30 18:29:30'); // 23:59:30 IST on the 30th

        Time::setTestNow('2026-10-30 18:29:59', 'UTC');
        $this->assertSame(['edge.test'], $this->names('expiry=within_7_days'));
        $this->assertSame([], $this->names('expiry=expired'));

        Time::setTestNow('2026-10-30 18:30:00', 'UTC');
        $this->assertSame([], $this->names('expiry=within_7_days'));
        $this->assertSame(['edge.test'], $this->names('expiry=expired'));
    }

    public function testFilteredListStillRequiresAuthenticationAndIsNotCached(): void
    {
        [$status] = $this->getJson('domains?expiry=expired', false);
        $this->assertSame(401, $status);

        $result = $this->withHeaders(['Authorization' => 'Bearer ' . $this->token])->get('api/v1/domains?expiry=expired');
        $this->assertStringContainsString('no-store', $result->response()->getHeaderLine('Cache-Control'));
    }
}
