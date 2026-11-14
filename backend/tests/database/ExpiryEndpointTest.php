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
use Tests\Support\Database\UsesIsolatedAuthDatabase;

/**
 * HTTP-level tests for `GET api/v1/expiry/summary` against the isolated test
 * database. The clock is fixed with Time::setTestNow(); all calendar days are
 * Asia/Kolkata days, and stored instants are UTC.
 *
 * @internal
 */
final class ExpiryEndpointTest extends CIUnitTestCase
{
    use FeatureTestTrait;
    use UsesIsolatedAuthDatabase {
        tearDownAfterClass as private tearDownAuthSchema;
    }

    private const EMAIL    = 'owner@example.test';
    private const PASSWORD = 'Sentinel-Pass-123!';

    /** 2026-10-30 17:30 in Asia/Kolkata. */
    private const NOW_UTC = '2026-10-30 12:00:00';

    private static bool $domainSchemaCreated = false;

    private string $token = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpIsolatedAuthDatabase();

        if (! self::$domainSchemaCreated) {
            if ($this->testDb->tableExists('domains') || $this->testDb->tableExists('sync_runs')) {
                $this->markTestSkipped('Expiry API tests NOT executed: the test database already contains domains/sync_runs; refusing to touch them.');
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

    /**
     * @param array<string, mixed> $overrides
     */
    private function insertDomain(?string $expiresAt, array $overrides = []): void
    {
        static $n = 0;
        $n++;

        $this->testDb->table('domains')->insert($overrides + [
            'rc_order_id'    => 'order-' . $n,
            'domain_name'    => 'd' . $n . '.test',
            'expires_at'     => $expiresAt,
            'first_seen_at'  => '2026-10-01 00:00:00',
            'last_synced_at' => '2026-10-20 08:00:00',
            'created_at'     => '2026-10-01 00:00:00',
            'updated_at'     => '2026-10-01 00:00:00',
        ]);
    }

    /**
     * @return array{0: int, 1: array<string, mixed>}
     */
    private function summary(bool $authed = true, string $query = ''): array
    {
        $req    = $authed ? $this->withHeaders(['Authorization' => 'Bearer ' . $this->token]) : $this;
        $result = $req->get('api/v1/expiry/summary' . $query);

        return [$result->response()->getStatusCode(), json_decode((string) $result->response()->getBody(), true) ?? []];
    }

    public function testRejectsUnauthenticatedRequests(): void
    {
        [$status, $body] = $this->summary(false);

        $this->assertSame(401, $status);
        $this->assertSame(['error' => 'unauthorized'], $body);
    }

    public function testEmptyInventoryHasTheExactStructure(): void
    {
        $result = $this->withHeaders(['Authorization' => 'Bearer ' . $this->token])->get('api/v1/expiry/summary');
        $body   = json_decode((string) $result->response()->getBody(), true);

        $this->assertSame(200, $result->response()->getStatusCode());
        $this->assertSame([
            'expired'        => 0,
            'within_7_days'  => 0,
            'within_30_days' => 0,
            'within_60_days' => 0,
            'within_90_days' => 0,
            'unknown'        => 0,
            'as_of_date'     => '2026-10-30',
            'timezone'       => 'Asia/Kolkata',
        ], $body);
        $this->assertStringContainsString('no-store', $result->response()->getHeaderLine('Cache-Control'));
        $this->assertSame('no-cache', $result->response()->getHeaderLine('Pragma'));
    }

    public function testRejectsQueryParameters(): void
    {
        [$status, $body] = $this->summary(true, '?x=1');

        $this->assertSame(400, $status);
        $this->assertSame(['error' => 'invalid_request'], $body);
    }

    public function testCumulativeBucketsAtEveryBoundary(): void
    {
        // Each domain sits on the first or last minute of a Kolkata calendar day.
        $this->insertDomain($this->ist(-1, '23:59:00')); // expired (yesterday)
        $this->insertDomain($this->ist(0, '00:00:00'));  // today, earlier than "now": still today
        $this->insertDomain($this->ist(0, '23:59:00'));  // today, later than "now"
        $this->insertDomain($this->ist(7, '23:59:00'));  // exactly 7 days: last minute of the window
        $this->insertDomain($this->ist(8, '00:00:00'));  // one day beyond 7
        $this->insertDomain($this->ist(30, '23:59:00')); // exactly 30
        $this->insertDomain($this->ist(31, '00:00:00')); // one beyond 30
        $this->insertDomain($this->ist(60, '23:59:00')); // exactly 60
        $this->insertDomain($this->ist(61, '00:00:00')); // one beyond 60
        $this->insertDomain($this->ist(90, '23:59:00')); // exactly 90
        $this->insertDomain($this->ist(91, '00:00:00')); // one beyond 90: in no bucket
        $this->insertDomain(null);                       // unknown

        [$status, $body] = $this->summary();

        $this->assertSame(200, $status);
        $this->assertSame(1, $body['expired']);
        $this->assertSame(3, $body['within_7_days']);   // today x2, +7
        $this->assertSame(5, $body['within_30_days']);  // + 8, +30
        $this->assertSame(7, $body['within_60_days']);  // + 31, +60
        $this->assertSame(9, $body['within_90_days']);  // + 61, +90
        $this->assertSame(1, $body['unknown']);
    }

    public function testADomainInFiveDaysBelongsToAllFourUpcomingBuckets(): void
    {
        $this->insertDomain($this->ist(5, '10:00:00'));

        [, $body] = $this->summary();

        $this->assertSame(0, $body['expired']);
        $this->assertSame(1, $body['within_7_days']);
        $this->assertSame(1, $body['within_30_days']);
        $this->assertSame(1, $body['within_60_days']);
        $this->assertSame(1, $body['within_90_days']);
    }

    public function testUnknownIsCountedOnlyInUnknown(): void
    {
        $this->insertDomain(null);
        $this->insertDomain(null);

        [, $body] = $this->summary();

        $this->assertSame(2, $body['unknown']);
        $this->assertSame(0, $body['expired']);
        $this->assertSame(0, $body['within_90_days']);
    }

    public function testMissingDomainsAreExcludedFromEveryBucket(): void
    {
        $this->insertDomain($this->ist(-3, '10:00:00'), ['is_missing' => 1]);
        $this->insertDomain($this->ist(3, '10:00:00'), ['is_missing' => 1]);
        $this->insertDomain(null, ['is_missing' => 1]);
        $this->insertDomain($this->ist(3, '10:00:00'));

        [, $body] = $this->summary();

        $this->assertSame(0, $body['expired']);
        $this->assertSame(1, $body['within_7_days']);
        $this->assertSame(0, $body['unknown']);
    }

    public function testDaysFollowTheKolkataMidnightNotTheUtcDate(): void
    {
        // 19:00 UTC on 2026-10-30 is 00:30 on 2026-10-31 in Kolkata.
        Time::setTestNow('2026-10-30 19:00:00', 'UTC');

        $this->insertDomain('2026-10-30 18:29:59'); // 23:59:59 IST on the 30th: expired
        $this->insertDomain('2026-10-30 18:30:00'); // 00:00:00 IST on the 31st: today
        $this->insertDomain('2026-10-30 20:00:00'); // 01:30 IST on the 31st: today

        [, $body] = $this->summary();

        $this->assertSame('2026-10-31', $body['as_of_date']);
        $this->assertSame(1, $body['expired']);
        $this->assertSame(2, $body['within_7_days']);
    }

    public function testTheDayChangesExactlyAtKolkataMidnight(): void
    {
        $this->insertDomain('2026-10-30 18:29:30'); // 23:59:30 IST on the 30th

        // One second before Kolkata midnight: still the 30th, so not yet expired.
        Time::setTestNow('2026-10-30 18:29:59', 'UTC');
        [, $before] = $this->summary();

        $this->assertSame('2026-10-30', $before['as_of_date']);
        $this->assertSame(0, $before['expired']);
        $this->assertSame(1, $before['within_7_days']);

        // At Kolkata midnight the 31st starts: the same domain is now expired.
        Time::setTestNow('2026-10-30 18:30:00', 'UTC');
        [, $after] = $this->summary();

        $this->assertSame('2026-10-31', $after['as_of_date']);
        $this->assertSame(1, $after['expired']);
        $this->assertSame(0, $after['within_7_days']);
    }
}
