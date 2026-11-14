<?php

declare(strict_types=1);

use App\Filters\ThrottleFilter;
use App\Libraries\Pricing\RenewalQuoteService;
use App\Libraries\Renewal\RenewalBatchGateway;
use App\Libraries\Renewal\RenewalExecutionService;
use App\Libraries\Renewal\RenewalReconciliationService;
use App\Libraries\ResellerClub\ResellerClubClient;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use Config\ResellerClub as ResellerClubConfig;
use Tests\Support\Libraries\IsolatedResellerClubConfig;
use Config\Services;
use Tests\Support\Database\UsesEnrichmentSchema;
use Tests\Support\Database\UsesIsolatedAuthDatabase;
use Tests\Support\Database\UsesRenewalSchema;

/**
 * HTTP-level tests for the renewal-batch API against the isolated *_test
 * database. Every provider transport is FAKE: GETs are served from a script,
 * and the POST transport fails the test (and is asserted never to have been
 * used). The execution switch stays at its shipped default (OFF).
 *
 * @internal
 */
final class RenewalBatchEndpointsTest extends CIUnitTestCase
{
    use FeatureTestTrait;
    use UsesIsolatedAuthDatabase {
        tearDownAfterClass as private tearDownAuthSchema;
    }
    use UsesEnrichmentSchema;
    use UsesRenewalSchema;

    private const PASSWORD = 'Sentinel-Pass-123!';
    private const EXPIRY   = '2035-01-01 00:00:00';
    private const ACTION   = '111222';
    private const API_KEY  = 'sentinel-api-key-never-return';

    /** @var array<string, string> email => token */
    private array $tokens = [];

    /** @var array<string, int> email => user id */
    private array $userIds = [];

    /** @var array<string, list<array{status: int, body: string}|Throwable>> path => answers */
    private array $script = [];

    /** @var list<string> */
    private array $getPaths = [];

    /** @var list<string> */
    private array $postCalls = [];

    private int $quoteCalls = 0;

    /** When true the stub quote service simulates a provider outage. */
    private bool $quoteOutage = false;

    /** @var array<string, mixed> overrides applied to every stub quote */
    private array $quoteOverride = [];

    protected function setUp(): void
    {
        parent::setUp();

        self::emptyRenewalTables();

        $this->setUpIsolatedAuthDatabase();
        $this->setUpEnrichmentSchema();
        $this->setUpRenewalSchema();

        $this->resetThrottleBuckets();
        Services::resetSingle('authService');

        $this->tokens        = [];
        $this->userIds       = [];
        $this->script        = [];
        $this->getPaths      = [];
        $this->postCalls     = [];
        $this->quoteCalls    = 0;
        $this->quoteOutage   = false;
        $this->quoteOverride = [];

        $config              = $this->config();
        $client              = $this->client($config);
        $test                = $this;

        Services::injectMock('renewalBatchService', new RenewalBatchGateway(new RenewalExecutionService($client, $config, $this->testDb)));
        Services::injectMock('renewalReconciliationService', new RenewalReconciliationService($client, $config, $this->testDb));
        Services::injectMock('renewalQuoteService', new class ($client, $config, $test) extends RenewalQuoteService {
            public function __construct(ResellerClubClient $client, ResellerClubConfig $config, private readonly RenewalBatchEndpointsTest $test)
            {
                parent::__construct($client, $config);
            }

            public function quote(string $domainName, int $years, bool $domainMissing = false, bool $freshPrice = false): array
            {
                return $this->test->stubQuote($domainName, $years);
            }
        });

        $this->userIds['a@example.test'] = $this->insertUser(['email' => 'a@example.test', 'password_hash' => password_hash(self::PASSWORD, PASSWORD_DEFAULT)]);
        $this->userIds['b@example.test'] = $this->insertUser(['email' => 'b@example.test', 'password_hash' => password_hash(self::PASSWORD, PASSWORD_DEFAULT)]);

        foreach (array_keys($this->userIds) as $email) {
            $login = $this->withBodyFormat('json')->post('api/v1/auth/login', ['email' => $email, 'password' => self::PASSWORD]);
            $this->assertSame(200, $login->response()->getStatusCode());
            $this->tokens[$email] = json_decode((string) $login->response()->getBody(), true)['access_token'];
        }
    }

    protected function tearDown(): void
    {
        $this->assertSame([], $this->postCalls, 'No endpoint may ever send a provider POST.');

        foreach (['renewalBatchService', 'renewalReconciliationService', 'renewalQuoteService', 'authService'] as $name) {
            Services::resetSingle($name);
        }

        $this->resetThrottleBuckets();

        parent::tearDown();
    }

    public static function tearDownAfterClass(): void
    {
        self::tearDownRenewalSchema();
        self::tearDownEnrichmentSchema();
        self::tearDownAuthSchema();
    }

    // ---- helpers ------------------------------------------------------------------

    private function config(): ResellerClubConfig
    {
        $c              = IsolatedResellerClubConfig::make();
        $c->baseUrl     = 'https://test.httpapi.example/api';
        $c->authUserId  = '123456';
        $c->apiKey      = self::API_KEY;
        $c->maxAttempts = 1;
        // Execution deliberately left at its shipped default (OFF).

        return $c;
    }

    private function client(ResellerClubConfig $config): ResellerClubClient
    {
        return new ResellerClubClient(
            $config,
            function (string $url): array {
                $path             = (string) parse_url($url, PHP_URL_PATH);
                $this->getPaths[] = $path;
                $next             = array_shift($this->script[$path]);

                if ($next === null) {
                    throw new RuntimeException('unexpected provider GET ' . $path);
                }

                if ($next instanceof Throwable) {
                    throw $next;
                }

                return $next;
            },
            static function (int $ms): void {
            },
            function (string $url): array {
                $this->postCalls[] = $url;

                throw new RuntimeException('unexpected provider POST');
            },
        );
    }

    /** Called by the stub quote service. Public only so the anonymous subclass can reach it. */
    public function stubQuote(string $domainName, int $years): array
    {
        $this->quoteCalls++;

        if ($this->quoteOutage) {
            throw new \App\Libraries\ResellerClub\ResellerClubException('http_503', true);
        }

        return $this->quoteOverride + [
            'domain' => $domainName, 'years' => $years, 'status' => 'available', 'currency' => 'INR',
            'renewal_amount' => '1419.00', 'tax' => '255.42', 'vat' => '0.00', 'total' => '1674.42',
            'gst_rate' => '18', 'vat_rate' => '0', 'tax_status' => 'estimated', 'source' => 'reseller_cost_price',
            'cached' => false, 'fetched_at' => null,
        ];
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

    private function domain(int $n = 1): int
    {
        return $this->insertEnrichableDomain([
            'rc_order_id' => (string) (900000 + $n),
            'domain_name' => "d{$n}.test",
            'expires_at'  => self::EXPIRY,
            'auto_renew'  => 1,
        ]);
    }

    /**
     * @param array<string, mixed>|null $body
     *
     * @return array{0: int, 1: array<string, mixed>, 2: \CodeIgniter\HTTP\ResponseInterface}
     */
    private function req(string $method, string $path, ?string $as = 'a@example.test', ?array $body = null, ?string $rawBody = null): array
    {
        $req = $as === null ? $this : $this->withHeaders(['Authorization' => 'Bearer ' . $this->tokens[$as]]);

        if ($rawBody !== null) {
            $req = $req->withBody($rawBody);
        } elseif ($body !== null) {
            $req = $req->withBodyFormat('json');
        }

        try {
            $result = $req->call($method, 'api/v1/' . $path, $rawBody !== null ? null : $body);
        } catch (\CodeIgniter\Exceptions\PageNotFoundException) {
            // A path that matches no route (for example a non-numeric id) is a 404 too.
            return [404, [], service('response')];
        }

        return [$result->response()->getStatusCode(), json_decode((string) $result->response()->getBody(), true) ?? [], $result->response()];
    }

    /**
     * @param array<string, mixed> $override
     *
     * @return array<string, mixed>
     */
    private function payload(array $domainIds, array $override = []): array
    {
        return $override + [
            'domain_ids' => $domainIds, 'years' => 1, 'invoice_option' => 'KeepInvoice', 'idempotency_key' => 'idem-key-aaaaaaaaaaaa',
        ];
    }

    /** Stores a batch for a user through the same service the controller uses (no HTTP). */
    private function makeBatch(string $email, array $domainIds, string $key): int
    {
        $lines = array_map(static fn (int $d): array => [
            'domain_id' => $d, 'renewal_amount' => '1419.00', 'gst_amount' => '255.42',
            'vat_amount' => '0.00', 'total_amount' => '1674.42', 'currency' => 'INR',
        ], $domainIds);

        return service('renewalBatchService')->createBatch($this->userIds[$email], $key, 'KeepInvoice', 1, $lines)['batch_id'];
    }

    /** @return list<int> */
    private function itemIds(int $batchId): array
    {
        return array_map('intval', array_column($this->testDb->table('renewal_items')->select('id')->where('batch_id', $batchId)->orderBy('id')->get()->getResultArray(), 'id'));
    }

    /** @return array<string, mixed> */
    private function item(int $id): array
    {
        return $this->testDb->table('renewal_items')->where('id', $id)->get()->getRowArray();
    }

    private function setItem(int $id, string $state, ?string $actionId = null): void
    {
        $this->testDb->table('renewal_items')->where('id', $id)->update(['state' => $state, 'provider_action_id' => $actionId]);

        // Keep the batch status consistent with its items, as the services always do.
        $batchId = (int) $this->item($id)['batch_id'];
        (new \App\Libraries\Renewal\RenewalBatchRefresher($this->testDb, static fn (): DateTimeImmutable => new DateTimeImmutable('now', new DateTimeZone('UTC'))))->refresh($batchId);
    }

    /** @param list<array<string, mixed>> $records */
    private function answer(array $records): array
    {
        $body = ['recsonpage' => (string) count($records), 'recsindb' => (string) count($records)];

        foreach ($records as $i => $r) {
            $body[(string) ($i + 1)] = $r;
        }

        return ['status' => 200, 'body' => (string) json_encode($body)];
    }

    /** @return array<string, mixed> */
    private function action(array $overrides = [], int $n = 1): array
    {
        return $overrides + [
            'eaqid' => self::ACTION, 'actiontype' => 'RenewDomain', 'entityid' => (string) (900000 + $n),
            'actionstatus' => 'Success', 'actionstatusdesc' => 'Domain renewed successfully',
        ];
    }

    private function provider(array $current, array $archived): void
    {
        $this->script['/api/actions/search-current.json']  = [$this->answer($current)];
        $this->script['/api/actions/search-archived.json'] = [$this->answer($archived)];
    }

    private function rows(string $table): int
    {
        return (int) $this->testDb->table($table)->countAllResults();
    }

    // ---- authentication -----------------------------------------------------------

    public function testEveryRenewalBatchEndpointRequiresAuthentication(): void
    {
        $batch = $this->makeBatch('a@example.test', [$this->domain(1)], 'idem-key-seed-aaaaaaa');

        foreach ([
            ['POST', 'renewal-batches'],
            ['GET', 'renewal-batches'],
            ['GET', "renewal-batches/{$batch}"],
            ['POST', "renewal-batches/{$batch}/reconcile"],
        ] as [$method, $path]) {
            [$status, $body] = $this->req($method, $path, null);
            $this->assertSame(401, $status, "$method $path");
            $this->assertSame(['error' => 'unauthorized'], $body);

            $result = $this->withHeaders(['Authorization' => 'Bearer ' . str_repeat('A', 43)])->call($method, 'api/v1/' . $path);
            $this->assertSame(401, $result->response()->getStatusCode(), "$method $path bad token");
        }

        $this->assertSame([], $this->getPaths);
        $this->assertSame(1, $this->rows('renewal_batches'));
    }

    public function testAuthenticatedRequestsWork(): void
    {
        $domain = $this->domain(1);

        $this->assertSame(201, $this->req('POST', 'renewal-batches', 'a@example.test', $this->payload([$domain]))[0]);
        $this->assertSame(200, $this->req('GET', 'renewal-batches')[0]);
    }

    // ---- batch creation -----------------------------------------------------------

    public function testValidBatchCreationStoresLocalPendingRecordsOnlyAndSendsNothing(): void
    {
        $d1 = $this->domain(1);
        $d2 = $this->domain(2);

        [$status, $body, $response] = $this->req('POST', 'renewal-batches', 'a@example.test', $this->payload([$d1, $d2]));

        $this->assertSame(201, $status);
        $this->assertFalse($body['replayed']);
        $this->assertSame(['pending', 'KeepInvoice', 1], [$body['batch']['status'], $body['batch']['invoice_option'], $body['batch']['years']]);
        $this->assertSame(['pending' => 2, 'submitting' => 0, 'succeeded' => 0, 'failed' => 0, 'uncertain' => 0], $body['batch']['item_counts']);
        $this->assertCount(2, $body['batch']['items']);

        foreach ($body['batch']['items'] as $item) {
            $this->assertSame('pending', $item['state']);
            $this->assertSame(['1419.00', '255.42', '0.00', '1674.42', 'INR'], [$item['renewal_amount'], $item['gst_amount'], $item['vat_amount'], $item['total_amount'], $item['currency']]);
            $this->assertNull($item['provider_action_id']);
            $this->assertNull($item['provider_invoice_id']);
        }

        // Stored for the authenticated user, nothing else was touched or sent.
        $row = $this->testDb->table('renewal_batches')->get()->getRowArray();
        $this->assertSame($this->userIds['a@example.test'], (int) $row['user_id']);
        $this->assertSame(['pending'], array_column($this->testDb->query('SELECT DISTINCT state FROM renewal_items')->getResultArray(), 'state'));
        $this->assertSame([], $this->getPaths, 'Creating a batch makes no provider request.');
        $this->assertStringContainsString('no-store', $response->getHeaderLine('Cache-Control'));

        // Order and customer ids are server-side only.
        $raw = (string) $response->getBody();

        foreach (['900001', '900002', '555001', self::API_KEY] as $leak) {
            $this->assertStringNotContainsString($leak, $raw);
        }

        $this->assertSame('900001', (string) $this->testDb->table('renewal_items')->where('domain_id', $d1)->get()->getRowArray()['rc_order_id']);
    }

    public function testKeepInvoiceAndNoInvoiceAreBothAccepted(): void
    {
        foreach (['KeepInvoice', 'NoInvoice'] as $n => $option) {
            [$status, $body] = $this->req('POST', 'renewal-batches', 'a@example.test', $this->payload([$this->domain($n + 1)], ['invoice_option' => $option, 'idempotency_key' => 'idem-key-option-' . $n . '-aaaa']));

            $this->assertSame(201, $status, $option);
            $this->assertSame($option, $body['batch']['invoice_option']);
        }
    }

    /** @return array<string, array{0: array<string, mixed>, 1: string}> */
    public static function badCreateInputs(): array
    {
        return [
            'invoice option wrong case' => [['invoice_option' => 'keepinvoice'], 'invalid_invoice_option'],
            'invoice option unknown'    => [['invoice_option' => 'PayNow'], 'invalid_invoice_option'],
            'invoice option missing'    => [['invoice_option' => null], 'invalid_invoice_option'],
            'years zero'                => [['years' => 0], 'invalid_years'],
            'years eleven'              => [['years' => 11], 'invalid_years'],
            'years string'              => [['years' => '1'], 'invalid_years'],
            'years float'               => [['years' => 1.5], 'invalid_years'],
            'years missing'             => [['years' => null], 'invalid_years'],
            'idempotency key missing'   => [['idempotency_key' => null], 'invalid_idempotency_key'],
            'idempotency key short'     => [['idempotency_key' => 'short'], 'invalid_idempotency_key'],
            'idempotency key bad chars' => [['idempotency_key' => 'bad key with spaces!!'], 'invalid_idempotency_key'],
            'idempotency key number'    => [['idempotency_key' => 1234567890123456], 'invalid_idempotency_key'],
            'domain ids empty'          => [['domain_ids' => []], 'invalid_domain_ids'],
            'domain ids not a list'     => [['domain_ids' => 5], 'invalid_domain_ids'],
            'domain id string'          => [['domain_ids' => ['1']], 'invalid_domain_ids'],
            'domain id zero'            => [['domain_ids' => [0]], 'invalid_domain_ids'],
            'domain id negative'        => [['domain_ids' => [-1]], 'invalid_domain_ids'],
            'domain ids missing'        => [['domain_ids' => null], 'invalid_domain_ids'],
            'too many domains'          => [['domain_ids' => range(1, 51)], 'invalid_domain_ids'],
        ];
    }

    /**
     * @dataProvider badCreateInputs
     *
     * @param array<string, mixed> $override
     */
    public function testInvalidCreateInputsAreRejectedWithoutStoringOrQuoting(array $override, string $code): void
    {
        $domain  = $this->domain(1);
        $payload = $this->payload([$domain], $override);

        foreach ($payload as $k => $v) {
            if ($v === null) {
                unset($payload[$k]); // "missing"
            }
        }

        [$status, $body] = $this->req('POST', 'renewal-batches', 'a@example.test', $payload);

        $this->assertSame([400, ['error' => $code]], [$status, $body]);
        $this->assertSame(0, $this->rows('renewal_batches'));
        $this->assertSame(0, $this->quoteCalls);
    }

    public function testDuplicateDomainIdsAreRejected(): void
    {
        $domain = $this->domain(1);

        [$status, $body] = $this->req('POST', 'renewal-batches', 'a@example.test', $this->payload([$domain, $domain]));

        $this->assertSame([400, ['error' => 'duplicate_domain']], [$status, $body]);
        $this->assertSame(0, $this->rows('renewal_batches'));
    }

    public function testClientSuppliedUserIdsOrderIdsCustomerIdsExpiriesAndAmountsAreRefused(): void
    {
        $domain = $this->domain(1);

        foreach (['user_id' => 999, 'rc_order_id' => '1', 'rc_customer_id' => '1', 'expiry' => '2030-01-01', 'total_amount' => '1.00', 'lines' => []] as $field => $value) {
            [$status, $body] = $this->req('POST', 'renewal-batches', 'a@example.test', $this->payload([$domain], [$field => $value]));

            $this->assertSame([400, ['error' => 'invalid_request']], [$status, $body], $field);
        }

        $this->assertSame(0, $this->rows('renewal_batches'));
    }

    public function testMalformedBodiesAreRejected(): void
    {
        foreach ([null, '', 'not json', '[]', '[1,2]', '"x"', 'null'] as $raw) {
            [$status, $body] = $this->req('POST', 'renewal-batches', 'a@example.test', null, $raw ?? '');

            $this->assertSame([400, ['error' => 'invalid_request']], [$status, $body], (string) $raw);
        }

        [$status] = $this->req('POST', 'renewal-batches?x=1', 'a@example.test', $this->payload([$this->domain(1)]));
        $this->assertSame(400, $status);
        $this->assertSame(0, $this->rows('renewal_batches'));
    }

    public function testUnknownDomainIsRejectedAndNothingIsStored(): void
    {
        $domain = $this->domain(1);

        [$status, $body] = $this->req('POST', 'renewal-batches', 'a@example.test', $this->payload([$domain, 987654]));

        $this->assertSame([422, ['error' => 'domain_not_found', 'domain_id' => 987654]], [$status, $body]);
        $this->assertSame(0, $this->rows('renewal_batches'));
        $this->assertSame(0, $this->rows('renewal_items'));
    }

    public function testUnavailableQuoteIsRejectedAndNothingIsStored(): void
    {
        $domain              = $this->domain(1);
        $this->quoteOverride = ['status' => 'price_unavailable', 'renewal_amount' => null, 'tax' => null, 'vat' => null, 'total' => null, 'tax_status' => 'not_established'];

        [$status, $body] = $this->req('POST', 'renewal-batches', 'a@example.test', $this->payload([$domain]));

        $this->assertSame([422, ['error' => 'quote_unavailable', 'domain_id' => $domain]], [$status, $body]);
        $this->assertSame(0, $this->rows('renewal_batches'));
    }

    /** @return array<string, array{0: array<string, mixed>, 1: string}> */
    public static function ineligibleDomains(): array
    {
        return [
            'suspended'                     => [['status' => 'Suspended'], 'domain_not_active'],
            'locked'                        => [['status' => 'Locked'], 'domain_not_active'],
            'redemption'                    => [['status' => 'RedemptionPeriod'], 'domain_not_active'],
            'pending delete restorable'     => [['status' => 'Pending Delete Restorable'], 'domain_not_active'],
        ];
    }

    /**
     * @dataProvider ineligibleDomains
     *
     * @param array<string, mixed> $change
     */
    public function testBatchCreationRefusesIneligibleDomainsBeforeAnyPricingRequestAndStoresNothing(array $change, string $code): void
    {
        $domain = $this->domain(1);
        $this->testDb->table('domains')->where('id', $domain)->update($change);

        [$status, $body] = $this->req('POST', 'renewal-batches', 'a@example.test', $this->payload([$domain]));

        $this->assertSame([422, ['error' => $code, 'domain_id' => $domain]], [$status, $body]);
        $this->assertSame(0, $this->quoteCalls, 'No price is requested for an ineligible domain.');
        $this->assertSame(0, $this->rows('renewal_batches'));
        $this->assertSame(0, $this->rows('renewal_items'));
    }

    public function testQuoteEndpointReportsTheSameEligibilityThatBatchCreationEnforces(): void
    {
        $ok      = $this->domain(1);
        $expired = $this->domain(2);
        $locked  = $this->domain(3);
        $this->testDb->table('domains')->where('id', $expired)->update(['expires_at' => '2026-01-01 00:00:00']);
        $this->testDb->table('domains')->where('id', $locked)->update(['status' => 'Locked']);

        $expect = [
            $ok      => [true, 'pre_expiry', null, false],
            $expired => [true, 'expired', null, true],
            $locked  => [false, 'domain_not_active', 'domain_not_active', false],
        ];

        foreach ($expect as $id => [$eligible, $state, $reason, $isExpired]) {
            [$status, $body] = $this->req('GET', "domains/{$id}/renewal-quote");

            $this->assertSame(200, $status);
            $this->assertSame([$eligible, $state, $reason, $isExpired], [
                $body['eligibility']['eligible'], $body['eligibility']['state'], $body['eligibility']['reason'], $body['eligibility']['expired'],
            ], "domain {$id}");

            // The same domain, the same answer from batch creation.
            [$createStatus, $createBody] = $this->req('POST', 'renewal-batches', 'a@example.test', $this->payload([$id], ['idempotency_key' => 'idem-key-' . str_pad((string) $id, 12, 'x')]));
            $this->assertSame($eligible ? 201 : 422, $createStatus, "domain {$id}");

            if (! $eligible) {
                $this->assertSame($reason, $createBody['error']);
            }
        }

        $this->assertSame('2026-01-01T00:00:00Z', $this->req('GET', "domains/{$expired}/renewal-quote")[1]['eligibility']['expires_at']);
    }

    public function testMissingDomainRowIsRefusedByTheService(): void
    {
        $domain = $this->domain(1);
        $this->testDb->table('domains')->where('id', $domain)->update(['is_missing' => 1]);

        [$status, $body] = $this->req('POST', 'renewal-batches', 'a@example.test', $this->payload([$domain]));

        $this->assertSame([422, ['error' => 'domain_missing']], [$status, $body]);
        $this->assertSame(0, $this->rows('renewal_batches'));
    }

    public function testSameIdempotencyKeyWithIdenticalContentReturnsTheStoredBatch(): void
    {
        $d1 = $this->domain(1);
        $d2 = $this->domain(2);

        [$s1, $b1] = $this->req('POST', 'renewal-batches', 'a@example.test', $this->payload([$d1, $d2]));
        [$s2, $b2] = $this->req('POST', 'renewal-batches', 'a@example.test', $this->payload([$d2, $d1])); // order does not matter

        $this->assertSame([201, false], [$s1, $b1['replayed']]);
        $this->assertSame([200, true], [$s2, $b2['replayed']]);
        $this->assertSame($b1['batch']['id'], $b2['batch']['id']);
        $this->assertSame(1, $this->rows('renewal_batches'));
        $this->assertSame(2, $this->rows('renewal_items'));
    }

    public function testSameIdempotencyKeyWithDifferentContentIsAConflict(): void
    {
        $d1 = $this->domain(1);
        $d2 = $this->domain(2);

        $this->assertSame(201, $this->req('POST', 'renewal-batches', 'a@example.test', $this->payload([$d1]))[0]);

        foreach ([
            $this->payload([$d1, $d2]),
            $this->payload([$d1], ['years' => 2]),
            $this->payload([$d1], ['invoice_option' => 'NoInvoice']),
        ] as $n => $different) {
            [$status, $body] = $this->req('POST', 'renewal-batches', 'a@example.test', $different);

            $this->assertSame([409, ['error' => 'idempotency_conflict']], [$status, $body], 'case ' . $n);
        }

        $this->assertSame(1, $this->rows('renewal_batches'));
        $this->assertSame(1, $this->rows('renewal_items'));
    }

    // ---- idempotent replay happens BEFORE any pricing request ----------------------

    public function testIdenticalReplayReturnsTheExistingBatchWithoutQuotingOrAnyProviderRequest(): void
    {
        $d1 = $this->domain(1);
        $d2 = $this->domain(2);

        [$s1, $b1] = $this->req('POST', 'renewal-batches', 'a@example.test', $this->payload([$d1, $d2]));
        $this->assertSame(201, $s1);
        $this->assertSame(2, $this->quoteCalls);

        $this->quoteCalls = 0;
        $this->getPaths   = [];
        $items            = $this->rows('renewal_items');

        [$s2, $b2] = $this->req('POST', 'renewal-batches', 'a@example.test', $this->payload([$d2, $d1]));

        $this->assertSame([200, true], [$s2, $b2['replayed']]);
        $this->assertSame($b1['batch']['id'], $b2['batch']['id']);
        $this->assertSame(0, $this->quoteCalls, 'a replay must not quote');
        $this->assertSame([], $this->getPaths, 'a replay must make no provider request');
        $this->assertSame($items, $this->rows('renewal_items'), 'a replay must create no items');
        $this->assertSame(1, $this->rows('renewal_batches'));
        $this->assertSame($b1['batch']['items'], $b2['batch']['items']);
    }

    public function testIdenticalReplaySucceedsWhileThePricingProviderIsDown(): void
    {
        $d = $this->domain(1);

        $this->assertSame(201, $this->req('POST', 'renewal-batches', 'a@example.test', $this->payload([$d]))[0]);

        $this->quoteOutage = true;
        $this->quoteCalls  = 0;

        [$status, $body] = $this->req('POST', 'renewal-batches', 'a@example.test', $this->payload([$d]));

        $this->assertSame([200, true], [$status, $body['replayed']]);
        $this->assertSame(0, $this->quoteCalls);

        // A NEW key during the same outage still fails cleanly, creating nothing.
        [$status, $body] = $this->req('POST', 'renewal-batches', 'a@example.test', $this->payload([$this->domain(2)], ['idempotency_key' => 'idem-key-outage-aaaaaa']));

        $this->assertSame([502, ['error' => 'provider_error']], [$status, $body]);
        $this->assertSame(1, $this->rows('renewal_batches'));
    }

    public function testDifferentContentWithTheSameKeyConflictsWithoutQuoting(): void
    {
        $d1 = $this->domain(1);
        $d2 = $this->domain(2);

        $this->assertSame(201, $this->req('POST', 'renewal-batches', 'a@example.test', $this->payload([$d1]))[0]);
        $this->quoteCalls = 0;

        foreach ([$this->payload([$d1, $d2]), $this->payload([$d1], ['years' => 2]), $this->payload([$d1], ['invoice_option' => 'NoInvoice'])] as $n => $different) {
            [$status, $body] = $this->req('POST', 'renewal-batches', 'a@example.test', $different);

            $this->assertSame([409, ['error' => 'idempotency_conflict']], [$status, $body], 'case ' . $n);
        }

        $this->assertSame(0, $this->quoteCalls);
        $this->assertSame(1, $this->rows('renewal_batches'));
        $this->assertSame(1, $this->rows('renewal_items'));
    }

    public function testANewKeyStillQuotesOnTheServer(): void
    {
        $d = $this->domain(1);

        [$status] = $this->req('POST', 'renewal-batches', 'a@example.test', $this->payload([$d]));

        $this->assertSame(201, $status);
        $this->assertSame(1, $this->quoteCalls);
        $this->assertSame('1674.42', $this->testDb->table('renewal_items')->get()->getRowArray()['total_amount']);
    }

    public function testIdempotencyIsScopedToTheAuthenticatedUser(): void
    {
        $d = $this->domain(1);

        [$s1, $b1] = $this->req('POST', 'renewal-batches', 'a@example.test', $this->payload([$d]));
        $this->assertSame(201, $s1);
        $this->quoteCalls = 0;

        // User B reusing A's key never sees or replays A's batch: it is quoted, then hits the cycle guard.
        [$s2, $b2] = $this->req('POST', 'renewal-batches', 'b@example.test', $this->payload([$d]));

        $this->assertSame(1, $this->quoteCalls, 'another user is not a replay');
        $this->assertArrayNotHasKey('batch', $b2);
        $this->assertSame([409, ['error' => 'duplicate_cycle']], [$s2, $b2]);
        $this->assertSame(1, $this->rows('renewal_batches'));

        // User B with a different domain and the same key gets its OWN batch.
        [$s3, $b3] = $this->req('POST', 'renewal-batches', 'b@example.test', $this->payload([$this->domain(2)]));

        $this->assertSame([201, false], [$s3, $b3['replayed']]);
        $this->assertNotSame($b1['batch']['id'], $b3['batch']['id']);
        $this->assertSame(2, $this->rows('renewal_batches'));
    }

    public function testTheUniqueConstraintStillGuardsTheServiceWhenThePreliminaryLookupFindsNothing(): void
    {
        $d     = $this->domain(1);
        $lines = [[
            'domain_id' => $d, 'renewal_amount' => '1419.00', 'gst_amount' => '255.42',
            'vat_amount' => '0.00', 'total_amount' => '1674.42', 'currency' => 'INR',
        ]];
        $service = service('renewalBatchService');
        $user    = $this->userIds['a@example.test'];
        $key     = 'idem-key-race-aaaaaaaa';

        // Both "racing" requests saw nothing in the preliminary lookup...
        $this->assertNull($service->findExistingBatch($user, $key, 'KeepInvoice', 1, [$d]));

        // ...and both reach createBatch(): exactly one batch results, the loser resolves to it.
        $first  = $service->createBatch($user, $key, 'KeepInvoice', 1, $lines);
        $second = $service->createBatch($user, $key, 'KeepInvoice', 1, $lines);

        $this->assertSame([false, true], [$first['replayed'], $second['replayed']]);
        $this->assertSame($first['batch_id'], $second['batch_id']);
        $this->assertSame(1, $this->rows('renewal_batches'));
        $this->assertSame(1, $this->rows('renewal_items'));

        // A racing request with different content gets a conflict, never a second batch.
        try {
            $service->createBatch($user, $key, 'NoInvoice', 1, $lines);
            $this->fail('expected idempotency_conflict');
        } catch (\App\Libraries\Renewal\RenewalException $e) {
            $this->assertSame('idempotency_conflict', $e->errorCode());
        }

        $this->assertSame(1, $this->rows('renewal_batches'));
    }

    public function testARaceLostAfterAStalePreliminaryLookupResolvesToTheWinnerWithoutDuplicates(): void
    {
        $d   = $this->domain(1);
        $key = 'idem-key-race-bbbbbbbb';

        // The loser's preliminary lookup ran BEFORE the winner committed...
        $this->assertNull(service('renewalBatchService')->findExistingBatch($this->userIds['a@example.test'], $key, 'KeepInvoice', 1, [$d]));
        $winner = $this->makeBatch('a@example.test', [$d], $key);

        // ...so a request that skipped that lookup reaches createBatch() and must resolve to the winner.
        $lines = [[
            'domain_id' => $d, 'renewal_amount' => '1419.00', 'gst_amount' => '255.42',
            'vat_amount' => '0.00', 'total_amount' => '1674.42', 'currency' => 'INR',
        ]];
        $result = service('renewalBatchService')->createBatch($this->userIds['a@example.test'], $key, 'KeepInvoice', 1, $lines);

        $this->assertSame(['batch_id' => $winner, 'replayed' => true], $result);
        $this->assertSame(1, $this->rows('renewal_batches'));
        $this->assertSame(1, $this->rows('renewal_items'));
    }

    public function testAnotherLiveBatchForTheSameDomainCycleIsRefused(): void
    {
        $domain = $this->domain(1);

        $this->assertSame(201, $this->req('POST', 'renewal-batches', 'a@example.test', $this->payload([$domain]))[0]);

        [$status, $body] = $this->req('POST', 'renewal-batches', 'a@example.test', $this->payload([$domain], ['idempotency_key' => 'idem-key-second-aaaaaa']));

        // The 409 names the user's own existing batch so the app can open it.
        $this->assertSame(409, $status);
        $this->assertSame('duplicate_cycle', $body['error']);
        $this->assertSame((int) $this->testDb->table('renewal_batches')->get()->getRowArray()['id'], $body['existing_batch_id']);
        $this->assertSame($domain, $body['domain_id']);
        $this->assertSame(1, $this->rows('renewal_batches'));
    }

    public function testDuplicateCycleNeverNamesAnotherUsersBatch(): void
    {
        $domain = $this->domain(1);

        $this->assertSame(201, $this->req('POST', 'renewal-batches', 'a@example.test', $this->payload([$domain]))[0]);

        [$status, $body] = $this->req('POST', 'renewal-batches', 'b@example.test', $this->payload([$domain], ['idempotency_key' => 'idem-key-userb-aaaaaaa']));

        $this->assertSame([409, ['error' => 'duplicate_cycle']], [$status, $body]);
    }

    public function testActiveLookupReturnsTheExistingBatchForTheDomain(): void
    {
        $d1 = $this->domain(1);
        $d2 = $this->domain(2);
        $this->assertSame(201, $this->req('POST', 'renewal-batches', 'a@example.test', $this->payload([$d1]))[0]);

        [$status, $body] = $this->req('GET', "renewal-batches/active?domain_ids={$d1},{$d2}");

        $this->assertSame(200, $status);
        $this->assertCount(1, $body['data']);
        $this->assertSame($d1, $body['data'][0]['domain_id']);
        $this->assertSame('pending', $body['data'][0]['batch']['status']);
        $this->assertSame('pending', $body['data'][0]['batch']['items'][0]['state']);
        $this->assertSame([], $this->postCalls);
    }

    public function testActiveLookupDoesNotExposeAnotherUsersBatch(): void
    {
        $d1 = $this->domain(1);
        $this->makeBatch('a@example.test', [$d1], 'idem-key-seed-aaaaaaa');

        [$status, $body] = $this->req('GET', "renewal-batches/active?domain_ids={$d1}", 'b@example.test');

        $this->assertSame([200, ['data' => []]], [$status, $body]);
    }

    public function testActiveLookupRequiresAuthenticationAndValidInput(): void
    {
        $this->assertSame(401, $this->req('GET', 'renewal-batches/active?domain_ids=1', null)[0]);

        foreach (['abc', '1,,2', '0', '-1', '1,x', implode(',', range(1, 51))] as $bad) {
            $this->assertSame(400, $this->req('GET', 'renewal-batches/active?domain_ids=' . $bad)[0], $bad);
        }

        $this->assertSame(400, $this->req('GET', 'renewal-batches/active')[0]);
        $this->assertSame(400, $this->req('GET', 'renewal-batches/active?domain_ids=1&x=2')[0]);
    }

    public function testAnExpiredNeverSubmittedBatchNoLongerBlocksANewOneAndIsKeptAsFailed(): void
    {
        $domain = $this->domain(1);
        $old    = $this->makeBatch('a@example.test', [$domain], 'idem-key-seed-aaaaaaa');
        $this->testDb->table('renewal_batches')->where('id', $old)->update(['created_at' => gmdate('Y-m-d H:i:s', time() - 1000)]);

        // Stale and never submitted: not reported as active ...
        $this->assertSame(['data' => []], $this->req('GET', "renewal-batches/active?domain_ids={$domain}")[1]);

        // ... and creating a new batch releases it instead of answering duplicate_cycle.
        [$status, $body] = $this->req('POST', 'renewal-batches', 'a@example.test', $this->payload([$domain], ['idempotency_key' => 'idem-key-second-aaaaaa']));

        $this->assertSame(201, $status);
        $this->assertNotSame($old, $body['batch']['id']);

        $oldItem = $this->testDb->table('renewal_items')->where('batch_id', $old)->get()->getRowArray();
        $this->assertSame('failed', $oldItem['state']);
        $this->assertSame('stale_unsubmitted', $oldItem['error_code']);
        $this->assertSame('failed', $this->testDb->table('renewal_batches')->where('id', $old)->get()->getRowArray()['status']);
        $this->assertSame(2, $this->rows('renewal_batches'), 'History is kept, never deleted.');
        $this->assertSame(2, $this->rows('renewal_items'));
        $this->assertSame([], $this->postCalls);
    }

    public function testAnExpiredBatchWithProviderEvidenceStillBlocksANewOne(): void
    {
        $domain = $this->domain(1);
        $old    = $this->makeBatch('a@example.test', [$domain], 'idem-key-seed-aaaaaaa');
        $this->testDb->table('renewal_batches')->where('id', $old)->update(['created_at' => gmdate('Y-m-d H:i:s', time() - 1000)]);
        $this->testDb->table('renewal_items')->where('batch_id', $old)->update(['provider_action_id' => self::ACTION]);

        [$status, $body] = $this->req('POST', 'renewal-batches', 'a@example.test', $this->payload([$domain], ['idempotency_key' => 'idem-key-second-aaaaaa']));

        $this->assertSame(409, $status);
        $this->assertSame($old, $body['existing_batch_id']);
        $this->assertSame('pending', $this->testDb->table('renewal_items')->where('batch_id', $old)->get()->getRowArray()['state']);
    }

    public function testCreationNeverExecutesARenewal(): void
    {
        $domain = $this->domain(1);

        $this->assertFalse((IsolatedResellerClubConfig::make())->renewalExecutionEnabled);
        $this->assertFalse(IsolatedResellerClubConfig::make()->renewalExecutionEnabled);

        $this->req('POST', 'renewal-batches', 'a@example.test', $this->payload([$domain]));

        $this->assertSame([], $this->postCalls);
        $this->assertSame([], $this->getPaths);
        $this->assertSame('pending', $this->testDb->table('renewal_items')->get()->getRowArray()['state']);
    }

    // ---- batch access -------------------------------------------------------------

    public function testUserListsOnlyTheirOwnBatchesNewestFirstWithPaging(): void
    {
        $a1 = $this->makeBatch('a@example.test', [$this->domain(1)], 'idem-key-a-one-aaaaaaa');
        $a2 = $this->makeBatch('a@example.test', [$this->domain(2)], 'idem-key-a-two-aaaaaaa');
        $b1 = $this->makeBatch('b@example.test', [$this->domain(3)], 'idem-key-b-one-aaaaaaa');

        [$status, $body] = $this->req('GET', 'renewal-batches');

        $this->assertSame(200, $status);
        $this->assertSame([$a2, $a1], array_column($body['data'], 'id'));
        $this->assertSame(['page' => 1, 'per_page' => 20, 'total' => 2, 'total_pages' => 1], $body['meta']);
        $this->assertArrayNotHasKey('items', $body['data'][0]);

        [, $other] = $this->req('GET', 'renewal-batches', 'b@example.test');
        $this->assertSame([$b1], array_column($other['data'], 'id'));

        [, $paged] = $this->req('GET', 'renewal-batches?page=2&per_page=1');
        $this->assertSame([$a1], array_column($paged['data'], 'id'));
        $this->assertSame(['page' => 2, 'per_page' => 1, 'total' => 2, 'total_pages' => 2], $paged['meta']);
    }

    public function testInvalidListParametersAreRejected(): void
    {
        foreach (['page=0', 'page=abc', 'per_page=-1', 'per_page=1.5', 'other=1', 'page[]=1'] as $query) {
            $this->assertSame(400, $this->req('GET', 'renewal-batches?' . $query)[0], $query);
        }
    }

    public function testUserCanViewTheirOwnBatchWithOnlyItsItems(): void
    {
        $d1 = $this->domain(1);
        $d2 = $this->domain(2);
        $d3 = $this->domain(3);

        $own   = $this->makeBatch('a@example.test', [$d1, $d2], 'idem-key-own-aaaaaaaaa');
        $other = $this->makeBatch('a@example.test', [$d3], 'idem-key-oth-aaaaaaaaa');

        [$status, $body, $response] = $this->req('GET', "renewal-batches/{$own}");

        $this->assertSame(200, $status);
        $this->assertSame($own, $body['batch']['id']);
        $this->assertSame([$d1, $d2], array_column($body['batch']['items'], 'domain_id'));
        $this->assertSame(['d1.test', 'd2.test'], array_column($body['batch']['items'], 'domain_name'));
        $this->assertSame($this->itemIds($own), array_column($body['batch']['items'], 'id'));
        $this->assertNotContains($d3, array_column($body['batch']['items'], 'domain_id'));
        $this->assertSame($this->itemIds($other), array_column($this->req('GET', "renewal-batches/{$other}")[1]['batch']['items'], 'id'));
        $this->assertSame('2035-01-01T00:00:00Z', $body['batch']['items'][0]['expiry_at_request']);

        foreach (['900001', '555001', self::API_KEY, 'rc_order_id', 'rc_customer_id', 'user_id', 'idempotency_key', 'token'] as $leak) {
            $this->assertStringNotContainsString($leak, (string) $response->getBody());
        }
    }

    public function testUserCannotViewAnotherUsersBatchAndItLooksLikeAMissingOne(): void
    {
        $batch = $this->makeBatch('a@example.test', [$this->domain(1)], 'idem-key-a-only-aaaaaaa');

        [$s1, $b1] = $this->req('GET', "renewal-batches/{$batch}", 'b@example.test');
        [$s2, $b2] = $this->req('GET', 'renewal-batches/999999', 'b@example.test');

        $this->assertSame([404, ['error' => 'not_found']], [$s1, $b1]);
        $this->assertSame([$s1, $b1], [$s2, $b2], 'Another user\'s batch must be indistinguishable from a missing one.');

        foreach (['abc', '0', '-1', '1.5', '99999999999999999999'] as $bad) {
            $this->assertSame(404, $this->req('GET', 'renewal-batches/' . $bad)[0], $bad);
        }
    }

    // ---- reconciliation -----------------------------------------------------------

    public function testOwnedBatchIsReconciledFromAProviderConfirmation(): void
    {
        $batch = $this->makeBatch('a@example.test', [$this->domain(1)], 'idem-key-rec-aaaaaaaaa');
        [$item] = $this->itemIds($batch);
        $this->setItem($item, 'uncertain', self::ACTION);
        $this->provider([$this->action()], []);

        [$status, $body, $response] = $this->req('POST', "renewal-batches/{$batch}/reconcile");

        $this->assertSame(200, $status);
        $this->assertSame('completed', $body['batch']['status']);
        $this->assertSame('succeeded', $body['batch']['items'][0]['state']);
        $this->assertSame(self::ACTION, $body['batch']['items'][0]['provider_action_id']);
        $this->assertSame([['item_id' => $item, 'previous_state' => 'uncertain', 'state' => 'succeeded', 'result' => 'succeeded', 'reason' => 'provider_confirmed', 'changed' => true]], $body['reconciliation']);
        $this->assertSame(['/api/actions/search-current.json'], $this->getPaths);
        $this->assertStringContainsString('no-store', $response->getHeaderLine('Cache-Control'));
        $this->assertStringNotContainsString(self::API_KEY, (string) $response->getBody());
    }

    public function testAnotherUsersBatchCannotBeReconciled(): void
    {
        $batch = $this->makeBatch('a@example.test', [$this->domain(1)], 'idem-key-rec-aaaaaaaaa');
        [$item] = $this->itemIds($batch);
        $this->setItem($item, 'uncertain', self::ACTION);
        $this->provider([$this->action()], []);

        [$status, $body] = $this->req('POST', "renewal-batches/{$batch}/reconcile", 'b@example.test');

        $this->assertSame([404, ['error' => 'not_found']], [$status, $body]);
        $this->assertSame([], $this->getPaths, 'No provider lookup may happen for a batch the user does not own.');
        $this->assertSame('uncertain', $this->item($item)['state']);

        $this->assertSame(404, $this->req('POST', 'renewal-batches/999999/reconcile')[0]);
        $this->assertSame(404, $this->req('POST', 'renewal-batches/abc/reconcile')[0]);
    }

    public function testOnlyEligibleItemsOfTheRequestedBatchAreReconciled(): void
    {
        $ds    = [$this->domain(1), $this->domain(2), $this->domain(3), $this->domain(4)];
        $batch = $this->makeBatch('a@example.test', $ds, 'idem-key-mix-aaaaaaaaa');
        $other = $this->makeBatch('a@example.test', [$this->domain(5)], 'idem-key-oth-aaaaaaaaa');
        $ids   = $this->itemIds($batch);
        [$otherItem] = $this->itemIds($other);

        $this->setItem($ids[0], 'pending');
        $this->setItem($ids[1], 'uncertain', self::ACTION);
        $this->setItem($ids[2], 'failed');
        $this->setItem($ids[3], 'succeeded', '777');
        $this->setItem($otherItem, 'uncertain', '888');
        $this->provider([$this->action(['entityid' => '900002'])], []);

        [$status, $body] = $this->req('POST', "renewal-batches/{$batch}/reconcile");

        $this->assertSame(200, $status);
        $this->assertSame([$ids[1]], array_column($body['reconciliation'], 'item_id'), 'Only the uncertain item is looked at.');
        $this->assertSame(['pending', 'succeeded', 'failed', 'succeeded'], array_column($this->testDb->table('renewal_items')->whereIn('id', $ids)->orderBy('id')->get()->getResultArray(), 'state'));
        $this->assertSame('uncertain', $this->item($otherItem)['state'], 'Another batch is never touched.');
        $this->assertSame(['/api/actions/search-current.json'], $this->getPaths);
    }

    public function testProviderLookupErrorsNeverCauseFalseSuccess(): void
    {
        $batch = $this->makeBatch('a@example.test', [$this->domain(1)], 'idem-key-err-aaaaaaaaa');
        [$item] = $this->itemIds($batch);
        $this->setItem($item, 'uncertain', self::ACTION);

        $this->script['/api/actions/search-current.json']  = [['status' => 503, 'body' => '']];
        $this->script['/api/actions/search-archived.json'] = [new RuntimeException('timeout SENTINEL-PROVIDER-TEXT ' . self::API_KEY)];

        [$status, $body, $response] = $this->req('POST', "renewal-batches/{$batch}/reconcile");

        $this->assertSame(200, $status);
        $this->assertSame(['unresolved', 'uncertain', 'lookup_failed', false], [$body['reconciliation'][0]['result'], $body['reconciliation'][0]['state'], $body['reconciliation'][0]['reason'], $body['reconciliation'][0]['changed']]);
        $this->assertSame('needs_review', $body['batch']['status']);
        $this->assertSame('uncertain', $this->item($item)['state']);

        foreach (['SENTINEL-PROVIDER-TEXT', self::API_KEY, 'timeout'] as $leak) {
            $this->assertStringNotContainsString($leak, (string) $response->getBody());
        }
    }

    public function testUnknownMismatchedAndUnmatchedActionsStayUnresolved(): void
    {
        $round = 0;

        foreach ([
            'unknown status' => [['actionstatus' => 'InProcess'], 'status_unrecognised'],
            'lowercase'      => [['actionstatus' => 'success'], 'status_unrecognised'],
            'other order'    => [['entityid' => '123'], 'action_mismatch'],
            'other type'     => [['actiontype' => 'AddNewDomain'], 'action_mismatch'],
            'other action'   => [['eaqid' => '999'], 'action_not_found'],
        ] as $name => [$override, $reason]) {
            $this->getPaths = [];
            $n              = ++$round;

            $batch = $this->makeBatch('a@example.test', [$this->domain($n)], 'idem-key-unk-' . $n . '-aaaaaaa');
            [$item] = $this->itemIds($batch);
            $this->setItem($item, 'uncertain', self::ACTION);
            $this->provider([$this->action($override, $n)], [$this->action($override, $n)]);

            [, $body] = $this->req('POST', "renewal-batches/{$batch}/reconcile");

            $this->assertSame(['uncertain', 'unresolved', $reason], [$body['reconciliation'][0]['state'], $body['reconciliation'][0]['result'], $body['reconciliation'][0]['reason']], $name);
            $this->assertSame('uncertain', $this->item($item)['state'], $name);

        }
    }

    public function testRepeatedReconciliationIsSafe(): void
    {
        $batch = $this->makeBatch('a@example.test', [$this->domain(1), $this->domain(2)], 'idem-key-rep-aaaaaaaaa');
        $ids   = $this->itemIds($batch);
        $this->setItem($ids[0], 'uncertain', self::ACTION);
        $this->setItem($ids[1], 'uncertain', null); // no action id: can never be matched automatically
        $this->provider([$this->action()], []);

        [, $first] = $this->req('POST', "renewal-batches/{$batch}/reconcile");
        $before    = $this->testDb->table('renewal_items')->orderBy('id')->get()->getResultArray();

        // The confirmed item is no longer eligible, so the second run must not look it up again.
        [$status, $second] = $this->req('POST', "renewal-batches/{$batch}/reconcile");

        $this->assertSame(200, $status);
        $this->assertSame(['succeeded', 'uncertain'], array_column($first['batch']['items'], 'state'));
        $this->assertSame(['succeeded', 'uncertain'], array_column($second['batch']['items'], 'state'));
        $this->assertSame([$ids[1]], array_column($second['reconciliation'], 'item_id'));
        $this->assertSame([false], array_column($second['reconciliation'], 'changed'));
        $this->assertSame($before, $this->testDb->table('renewal_items')->orderBy('id')->get()->getResultArray(), 'A repeat run writes nothing.');
        $this->assertSame(['/api/actions/search-current.json'], $this->getPaths, 'Only the first run reached the provider.');
        $this->assertSame('needs_review', $second['batch']['status']);
    }

    public function testReconciliationNeverResetsItemsToPendingOrFailed(): void
    {
        $batch = $this->makeBatch('a@example.test', [$this->domain(1)], 'idem-key-nrs-aaaaaaaaa');
        [$item] = $this->itemIds($batch);
        $this->setItem($item, 'submitting', self::ACTION);
        $this->provider([], []);

        $this->req('POST', "renewal-batches/{$batch}/reconcile");

        $this->assertContains($this->item($item)['state'], ['submitting', 'uncertain']);
        $this->assertSame([], $this->postCalls);
    }

    public function testReconcileRejectsABodyOrQuery(): void
    {
        $batch = $this->makeBatch('a@example.test', [$this->domain(1)], 'idem-key-bod-aaaaaaaaa');

        [$status, $body] = $this->req('POST', "renewal-batches/{$batch}/reconcile", 'a@example.test', ['item_id' => 1]);
        $this->assertSame([400, ['error' => 'invalid_request']], [$status, $body]);
        $this->assertSame(400, $this->req('POST', "renewal-batches/{$batch}/reconcile?x=1")[0]);
        $this->assertSame([], $this->getPaths);
    }

    // ---- execution safety ---------------------------------------------------------

    public function testNoRouteOrControllerCodeCanExecuteARenewal(): void
    {
        $source = (string) file_get_contents(APPPATH . 'Controllers/Api/V1/RenewalBatchController.php');
        $code   = (string) preg_replace('~/\*.*?\*/|//[^\n]*~s', '', $source);

        foreach (['processItem', 'processBatch', 'renewDomain', 'renew.json', 'postTransport'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $code, $forbidden);
        }

        $routes = service('routes');
        $routes->loadRoutes();

        $found = [];

        foreach (['GET', 'POST', 'PUT', 'PATCH', 'DELETE', '*'] as $verb) {
            foreach ($routes->getRoutes($verb) as $route => $handler) {
                if (stripos($route, 'renewal-batches') !== false || stripos((string) (is_string($handler) ? $handler : ''), 'RenewalBatchController') !== false) {
                    $found[] = $verb . ' ' . $route;
                }
            }
        }

        sort($found);

        $this->assertSame([
            'GET api/v1/renewal-batches',
            'GET api/v1/renewal-batches/([0-9]+)',
            'GET api/v1/renewal-batches/active',
            'POST api/v1/renewal-batches',
            // Manual debit verification (verification fields only), handled by RenewalDebitVerificationController.
            'POST api/v1/renewal-batches/([0-9]+)/items/([0-9]+)/debit-verification',
            // Phase 2: the single manual one-item renewal, handled by RenewalExecutionController (not this controller).
            'POST api/v1/renewal-batches/([0-9]+)/items/([0-9]+)/renew',
            'POST api/v1/renewal-batches/([0-9]+)/reconcile',
        ], $found);

        foreach ($found as $entry) {
            [$verb, $route] = explode(' ', $entry, 2);
            $filters        = (array) $routes->getRoutesOptions($route, $verb)['filter'];

            $this->assertSame('auth', $filters[0], $entry);
        }
    }

    public function testExecutionSwitchStaysOffAndEveryExecutionStyleRequestIsRefused(): void
    {
        $this->assertFalse((IsolatedResellerClubConfig::make())->renewalExecutionEnabled);
        $this->assertFalse(IsolatedResellerClubConfig::make()->renewalExecutionEnabled);

        $batch = $this->makeBatch('a@example.test', [$this->domain(1)], 'idem-key-exe-aaaaaaaaa');

        foreach ([
            ['POST', "renewal-batches/{$batch}/process"],
            ['POST', "renewal-batches/{$batch}/execute"],
            ['POST', "renewal-batches/{$batch}/renew"],
            ['PUT', "renewal-batches/{$batch}"],
            ['DELETE', "renewal-batches/{$batch}"],
        ] as [$method, $path]) {
            try {
                $status = $this->req($method, $path)[0];
            } catch (\CodeIgniter\Exceptions\PageNotFoundException) {
                $status = 404;
            }

            $this->assertContains($status, [404, 405], "$method $path");
        }

        $this->assertSame('pending', $this->testDb->table('renewal_items')->get()->getRowArray()['state']);
        $this->assertSame([], $this->postCalls);
        $this->assertSame([], $this->getPaths);
    }
}
