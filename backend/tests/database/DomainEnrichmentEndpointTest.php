<?php

declare(strict_types=1);

use App\Filters\ThrottleFilter;
use App\Libraries\Enrichment\DomainDetailsEnrichmentService;
use App\Libraries\ResellerClub\ResellerClubClient;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use Config\ResellerClub as ResellerClubConfig;
use Tests\Support\Libraries\IsolatedResellerClubConfig;
use Config\Services;
use Tests\Support\Database\UsesEnrichmentSchema;
use Tests\Support\Database\UsesIsolatedAuthDatabase;

/**
 * HTTP-level tests for POST /api/v1/domains/{id}/enrich against the isolated
 * test database. The service is wired to a fake provider transport: no network
 * access, synthetic data only.
 *
 * @internal
 */
final class DomainEnrichmentEndpointTest extends CIUnitTestCase
{
    use FeatureTestTrait;
    use UsesIsolatedAuthDatabase {
        tearDownAfterClass as private tearDownAuthSchema;
    }
    use UsesEnrichmentSchema;

    private const EMAIL    = 'owner@example.test';
    private const PASSWORD = 'Sentinel-Pass-123!';

    private string $token = '';

    private int $domainId = 0;

    /** @var list<array{status: int, body: string}> */
    private array $script = [];

    private int $providerCalls = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpIsolatedAuthDatabase();
        $this->setUpEnrichmentSchema();

        $this->resetThrottleBuckets();
        Services::resetSingle('authService');

        $this->script        = [];
        $this->providerCalls = 0;
        $this->domainId      = $this->insertEnrichableDomain();

        $config              = IsolatedResellerClubConfig::make();
        $config->baseUrl     = 'https://test.httpapi.example/api';
        $config->authUserId  = '123456';
        $config->apiKey      = 'sentinel-api-key-never-return';
        $config->maxAttempts = 1;

        $client = new ResellerClubClient($config, function (): array {
            $this->providerCalls++;

            return array_shift($this->script) ?? throw new RuntimeException('unexpected provider call');
        }, static function (int $ms): void {
        });

        Services::injectMock('domainEnrichmentService', new DomainDetailsEnrichmentService(
            $this->testDb,
            $client,
            static fn (): DateTimeImmutable => new DateTimeImmutable('2026-10-31 09:00:00', new DateTimeZone('UTC')),
        ));

        $this->insertUser(['email' => self::EMAIL, 'password_hash' => password_hash(self::PASSWORD, PASSWORD_DEFAULT)]);

        $login = $this->withBodyFormat('json')->post('api/v1/auth/login', ['email' => self::EMAIL, 'password' => self::PASSWORD]);
        $this->assertSame(200, $login->response()->getStatusCode());
        $this->token = json_decode((string) $login->response()->getBody(), true)['access_token'];
    }

    protected function tearDown(): void
    {
        Services::resetSingle('domainEnrichmentService');
        Services::resetSingle('authService');
        $this->resetThrottleBuckets();

        parent::tearDown();
    }

    public static function tearDownAfterClass(): void
    {
        self::tearDownEnrichmentSchema();
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
     * @param array<string, mixed> $details
     */
    private function provide(array $details): void
    {
        $this->script[] = ['status' => 200, 'body' => (string) json_encode($details)];
    }

    /**
     * @param array<string, mixed>|null $body
     *
     * @return array{0: int, 1: array<string, mixed>, 2: \CodeIgniter\HTTP\ResponseInterface}
     */
    private function enrichRequest(string $path, bool $authed = true, ?array $body = null): array
    {
        $req = $authed ? $this->withHeaders(['Authorization' => 'Bearer ' . $this->token]) : $this;

        if ($body !== null) {
            $req = $req->withBodyFormat('json');
        }

        $result = $req->post('api/v1/' . $path, $body);

        return [$result->response()->getStatusCode(), json_decode((string) $result->response()->getBody(), true) ?? [], $result->response()];
    }

    public function testRequiresBearerAuthentication(): void
    {
        [$status, $body] = $this->enrichRequest("domains/{$this->domainId}/enrich", false);

        $this->assertSame(401, $status);
        $this->assertSame(['error' => 'unauthorized'], $body);
        $this->assertSame(0, $this->providerCalls);

        $result = $this->withHeaders(['Authorization' => 'Bearer ' . str_repeat('A', 43)])->post("api/v1/domains/{$this->domainId}/enrich");
        $this->assertSame(401, $result->response()->getStatusCode());
        $this->assertSame(0, $this->providerCalls);
    }

    public function testSuccessReturnsOnlyCountersWithNoStoreHeaders(): void
    {
        $this->provide($this->providerDetails());

        [$status, $body, $response] = $this->enrichRequest("domains/{$this->domainId}/enrich");

        $this->assertSame(200, $status);
        $this->assertSame(1, $this->providerCalls);
        $this->assertSame([
            'domain_id'           => $this->domainId,
            'status'              => 'enriched',
            'details_synced_at'   => '2026-10-31T09:00:00Z',
            'contacts_created'    => 4,
            'contacts_updated'    => 0,
            'roles_linked'        => 4,
            'roles_changed'       => 0,
            'roles_preserved'     => 0,
            'nameservers_updated' => true,
            'warnings'            => 0,
        ], $body);

        $this->assertStringContainsString('no-store', $response->getHeaderLine('Cache-Control'));
        $this->assertSame('no-cache', $response->getHeaderLine('Pragma'));

        $raw = (string) $response->getBody();

        foreach (['example.test', 'Testville', '1 Test Street', 'SENTINEL-DOMSECRET-9f3a', 'sentinel-api-key-never-return', '555001', '900001'] as $leak) {
            $this->assertStringNotContainsString($leak, $raw);
        }

        $this->assertSame(4, $this->testDb->table('domain_contacts')->countAllResults());
    }

    public function testErrorResponsesAlsoCarryNoStoreHeaders(): void
    {
        [$status, , $response] = $this->enrichRequest('domains/999999/enrich');

        $this->assertSame(404, $status);
        $this->assertStringContainsString('no-store', $response->getHeaderLine('Cache-Control'));
        $this->assertSame('no-cache', $response->getHeaderLine('Pragma'));
    }

    public function testUnknownDomainIsNotFoundAndDoesNotCallTheProvider(): void
    {
        [$status, $body] = $this->enrichRequest('domains/999999/enrich');

        $this->assertSame(404, $status);
        $this->assertSame(['error' => 'not_found'], $body);
        $this->assertSame(0, $this->providerCalls);
    }

    public function testMalformedIdsAndMethodsAreRejected(): void
    {
        foreach (['domains/abc/enrich', 'domains/-1/enrich', 'domains/1.5/enrich', 'domains/enrich'] as $path) {
            try {
                $this->enrichRequest($path);
                $this->fail("Expected no route for {$path}");
            } catch (PageNotFoundException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertSame(404, $this->enrichRequest('domains/0/enrich')[0], 'Id 0 is routed but never matches a domain.');

        try {
            $this->withHeaders(['Authorization' => 'Bearer ' . $this->token])->get("api/v1/domains/{$this->domainId}/enrich");
            $this->fail('GET must not be routed.');
        } catch (PageNotFoundException) {
            $this->addToAssertionCount(1);
        }

        $this->assertSame(0, $this->providerCalls);
    }

    public function testClientCannotSupplyOrderOrCustomerIdsOrAnyOtherInput(): void
    {
        $id = $this->domainId;

        foreach ([['order_id' => '1'], ['order-id' => '1'], ['customer_id' => '1'], ['rc_order_id' => '1', 'rc_customer_id' => '2'], ['anything' => 'x']] as $body) {
            [$status, $json] = $this->enrichRequest("domains/{$id}/enrich", true, $body);

            $this->assertSame(400, $status);
            $this->assertSame(['error' => 'invalid_request'], $json);
        }

        $query = $this->withHeaders(['Authorization' => 'Bearer ' . $this->token])->post("api/v1/domains/{$id}/enrich?order-id=1&customerid=2");
        $this->assertSame(400, $query->response()->getStatusCode());

        $this->assertSame(0, $this->providerCalls);
        $this->assertSame(0, $this->testDb->table('rc_contacts')->countAllResults());
    }

    public function testEmptyJsonObjectBodyIsAccepted(): void
    {
        $this->provide($this->providerDetails());

        $this->assertSame(200, $this->enrichRequest("domains/{$this->domainId}/enrich", true, [])[0]);
    }

    public function testCustomerMismatchIsAConflictAndChangesNothing(): void
    {
        $this->provide($this->providerDetails(['customerid' => '555999']));

        [$status, $body] = $this->enrichRequest("domains/{$this->domainId}/enrich");

        $this->assertSame(409, $status);
        $this->assertSame(['error' => 'customer_mismatch'], $body);
        $this->assertSame(0, $this->testDb->table('rc_contacts')->countAllResults());
        $this->assertNull($this->testDb->table('domains')->where('id', $this->domainId)->get()->getRowArray()['details_synced_at']);
    }

    public function testProviderFailureIsABadGatewayWithoutProviderText(): void
    {
        $this->script[] = ['status' => 200, 'body' => '{"status":"ERROR","message":"SENTINEL-PROVIDER-TEXT"}'];

        [$status, $body, $response] = $this->enrichRequest("domains/{$this->domainId}/enrich");

        $this->assertSame(502, $status);
        $this->assertSame(['error' => 'provider_error'], $body);
        $this->assertStringNotContainsString('SENTINEL-PROVIDER-TEXT', (string) $response->getBody());
        $this->assertStringContainsString('no-store', $response->getHeaderLine('Cache-Control'));
    }

    public function testEnrichmentRoutesAreAnExactProtectedAllowList(): void
    {
        $routes = service('routes');
        $routes->loadRoutes();

        $enrich = [];

        foreach (['GET', 'POST', 'PUT', 'PATCH', 'DELETE', '*'] as $verb) {
            foreach (array_keys($routes->getRoutes($verb)) as $route) {
                if (stripos($route, 'enrich') !== false) {
                    $enrich[] = $verb . ' ' . $route;
                }
            }
        }

        // Exact allow-list (method + pattern): any other enrichment route, such
        // as a verb variant or a new bulk endpoint, fails here and needs review.
        $expected = [
            'GET api/v1/enrichment/batches/current'                => ['auth'],
            'GET api/v1/enrichment/eligible'                       => ['auth'],
            'POST api/v1/domains/([0-9]+)/enrich'                  => ['auth', 'throttle:enrich'],
            'POST api/v1/enrichment/batches'                       => ['auth', 'throttle:enrich_batch'],
            'POST api/v1/enrichment/batches/([a-f0-9]{16})/step'   => ['auth', 'throttle:enrich_batch'],
        ];

        sort($enrich);
        $this->assertSame(array_keys($expected), $enrich);

        // Every route requires auth, with exactly the expected throttle bucket.
        foreach ($expected as $key => $filters) {
            [$verb, $pattern] = explode(' ', $key, 2);

            $this->assertSame($filters, (array) ($routes->getRoutesOptions($pattern, $verb)['filter'] ?? []), $key);
        }
    }

    public function testEnrichRequestsAreThrottled(): void
    {
        $limit = config('Auth')->ipLimits['enrich'];

        for ($i = 0; $i < $limit; $i++) {
            $this->assertSame(404, $this->enrichRequest('domains/999999/enrich')[0]);
        }

        $this->assertSame(429, $this->enrichRequest('domains/999999/enrich')[0]);
        $this->assertSame(0, $this->providerCalls);
    }
}
