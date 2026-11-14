<?php

declare(strict_types=1);

use App\Filters\ThrottleFilter;
use App\Libraries\Pricing\RenewalQuoteService;
use App\Libraries\Renewal\RenewalExecutionGateway;
use App\Libraries\Renewal\RenewalExecutionService;
use App\Libraries\Renewal\RenewalPreflightService;
use App\Libraries\ResellerClub\ResellerClubClient;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Database;
use Config\ResellerClub as ResellerClubConfig;
use Tests\Support\Libraries\IsolatedResellerClubConfig;
use Config\Services;
use Tests\Support\Database\UsesEnrichmentSchema;
use Tests\Support\Database\UsesIsolatedAuthDatabase;
use Tests\Support\Database\UsesRenewalSchema;

/**
 * HTTP-level tests for `POST renewal-batches/{batch}/items/{item}/renew` against
 * the isolated *_test database. Every provider transport is FAKE: GETs are served
 * from a queue and the POST transport is a counting stub (a "provider POST" here
 * is a recorded array entry, never a network request). The execution switch and
 * debit verification are turned on ONLY inside in-memory config objects; the
 * shipped defaults stay OFF.
 *
 * @internal
 */
final class RenewalExecutionEndpointTest extends CIUnitTestCase
{
    use FeatureTestTrait;
    use UsesIsolatedAuthDatabase {
        tearDownAfterClass as private tearDownAuthSchema;
    }
    use UsesEnrichmentSchema;
    use UsesRenewalSchema;

    private const PASSWORD = 'Sentinel-Pass-123!';
    private const EXPIRY   = '2035-01-01 00:00:00';
    private const EPOCH    = 2051222400;
    private const NOW      = '2026-11-09 10:00:00';
    private const API_KEY  = 'sentinel-api-key-never-return';
    private const LOCK     = 'renewal_execute';

    /** @var array<string, string> */
    private array $tokens = [];

    /** @var array<string, int> */
    private array $userIds = [];

    /** @var list<array<string, mixed>|Throwable> */
    private array $getScript = [];

    /** @var list<array<string, mixed>|Throwable> */
    private array $postScript = [];

    /** @var list<string> */
    private array $getCalls = [];

    /** @var list<array{0: string, 1: array<string, mixed>}> */
    private array $postCalls = [];

    private string $now = self::NOW;

    private ?\CodeIgniter\Database\BaseConnection $other = null;

    protected function setUp(): void
    {
        parent::setUp();

        self::emptyRenewalTables();

        $this->setUpIsolatedAuthDatabase();
        $this->setUpEnrichmentSchema();
        $this->setUpRenewalSchema();

        $this->resetThrottleBuckets();
        Services::resetSingle('authService');

        $this->tokens     = [];
        $this->userIds    = [];
        $this->getScript  = [];
        $this->postScript = [];
        $this->getCalls   = [];
        $this->postCalls  = [];
        $this->now        = self::NOW;

        $this->userIds['a@example.test'] = $this->insertUser(['email' => 'a@example.test', 'password_hash' => password_hash(self::PASSWORD, PASSWORD_DEFAULT)]);
        $this->userIds['b@example.test'] = $this->insertUser(['email' => 'b@example.test', 'password_hash' => password_hash(self::PASSWORD, PASSWORD_DEFAULT)]);

        foreach (array_keys($this->userIds) as $email) {
            $login = $this->withBodyFormat('json')->post('api/v1/auth/login', ['email' => $email, 'password' => self::PASSWORD]);
            $this->assertSame(200, $login->response()->getStatusCode());
            $this->tokens[$email] = json_decode((string) $login->response()->getBody(), true)['access_token'];
        }

        $this->wire();
    }

    protected function tearDown(): void
    {
        // Whatever happened, a different connection must be able to take the lock: it is never left held.
        if ($this->other !== null || isset($this->testDb)) {
            $this->assertLockFree();
        }

        $this->other?->close();
        $this->other = null;

        foreach (['renewalExecutionGateway', 'authService'] as $name) {
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

    /** @param array<string, mixed> $overrides */
    private function config(array $overrides = []): ResellerClubConfig
    {
        $c                          = IsolatedResellerClubConfig::make();
        $c->baseUrl                 = 'https://test.httpapi.example/api';
        $c->authUserId              = '123456';
        $c->apiKey                  = self::API_KEY;
        $c->maxAttempts             = 1;
        $c->renewalSuccessStatuses  = ['Success'];
        $c->renewalFailureStatuses  = ['Failed'];
        $c->renewalExecutionEnabled = true;  // in-memory only
        $c->renewalDebitVerified    = true;  // in-memory only

        foreach ($overrides as $k => $v) {
            $c->{$k} = $v;
        }

        return $c;
    }

    /** @param array<string, mixed> $cfg */
    private function service(array $cfg = []): RenewalExecutionService
    {
        $config = $this->config($cfg);

        $client = new ResellerClubClient(
            $config,
            function (string $url): array {
                $this->getCalls[] = (string) parse_url($url, PHP_URL_PATH);
                $next             = array_shift($this->getScript);

                if ($next === null) {
                    throw new RuntimeException('unexpected provider GET');
                }

                if ($next instanceof Throwable) {
                    throw $next;
                }

                return $next;
            },
            static function (int $ms): void {
            },
            function (string $url, array $form): array {
                $this->postCalls[] = [$url, $form];
                $next              = array_shift($this->postScript);

                if ($next === null) {
                    throw new RuntimeException('unexpected provider POST');
                }

                if ($next instanceof Throwable) {
                    throw $next;
                }

                return $next;
            },
        );

        $clock = fn (): DateTimeImmutable => new DateTimeImmutable($this->now, new DateTimeZone('UTC'));
        $store = [];
        $cache = $this->createMock(\CodeIgniter\Cache\CacheInterface::class);
        $cache->method('get')->willReturnCallback(static fn (string $k) => $store[$k] ?? null);
        $cache->method('save')->willReturn(true);

        $preflight = new RenewalPreflightService($client, $config, new RenewalQuoteService($client, $config, $cache), $this->testDb, $clock);

        return new RenewalExecutionService($client, $config, $this->testDb, $clock, $preflight);
    }

    /** @param array<string, mixed> $cfg */
    private function wire(array $cfg = []): void
    {
        Services::injectMock('renewalExecutionGateway', new RenewalExecutionGateway($this->service($cfg)));
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
            'domain_name' => "d{$n}.com",
            'expires_at'  => self::EXPIRY,
            'auto_renew'  => 1,
        ]);
    }

    /**
     * @param list<int> $domainIds
     */
    private function batch(string $email, array $domainIds, string $key = 'idem-key-aaaaaaaaaaaa', string $invoice = 'KeepInvoice'): int
    {
        $lines = array_map(static fn (int $d): array => [
            'domain_id' => $d, 'renewal_amount' => '1419.00', 'gst_amount' => '255.42',
            'vat_amount' => '0.00', 'total_amount' => '1674.42', 'currency' => 'INR',
        ], $domainIds);

        return $this->service()->createBatch($this->userIds[$email], $key, $invoice, 1, $lines)['batch_id'];
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

    private function setState(int $itemId, string $state): void
    {
        $this->testDb->table('renewal_items')->where('id', $itemId)->update(['state' => $state]);
    }

    /** @return list<string> */
    private function states(int $batchId): array
    {
        return array_map('strval', array_column($this->testDb->table('renewal_items')->select('state')->where('batch_id', $batchId)->orderBy('id')->get()->getResultArray(), 'state'));
    }

    /**
     * @param array<string, mixed> $override
     *
     * @return array<string, mixed>
     */
    private function confirm(int $n = 1, array $override = []): array
    {
        return $override + [
            'acknowledge_charge' => true, 'confirm_domain_name' => "d{$n}.com", 'confirm_total_amount' => '1674.42',
            'confirm_currency' => 'INR', 'confirm_years' => 1, 'confirm_invoice_option' => 'KeepInvoice',
        ];
    }

    /**
     * @param array<string, mixed>|null $body
     *
     * @return array{0: int, 1: array<string, mixed>, 2: string}
     */
    private function renew(int $batchId, int $itemId, ?array $body, ?string $as = 'a@example.test', ?string $raw = null): array
    {
        $req = $as === null ? $this : $this->withHeaders(['Authorization' => 'Bearer ' . $this->tokens[$as]]);

        if ($raw !== null) {
            $req = $req->withBody($raw);
        } elseif ($body !== null) {
            $req = $req->withBodyFormat('json');
        }

        try {
            $result = $req->call('POST', "api/v1/renewal-batches/{$batchId}/items/{$itemId}/renew", $raw !== null ? null : $body);
        } catch (\CodeIgniter\Exceptions\PageNotFoundException) {
            return [404, [], ''];
        }

        $text = (string) $result->response()->getBody();

        return [$result->response()->getStatusCode(), json_decode($text, true) ?? [], $text];
    }

    /** @return array<string, mixed> */
    private function priceList(string $price = '1419.00'): array
    {
        return ['status' => 200, 'body' => (string) json_encode(['domcno' => ['renewdomain' => ['1' => $price]]])];
    }

    /** @return array<string, mixed> */
    private function balance(string $accounting = '5000.0000', string $symbol = 'INR'): array
    {
        return ['status' => 200, 'body' => (string) json_encode([
            'sellingcurrencybalance' => '6244.40', 'sellingcurrencysymbol' => 'USD',
            'accountingcurrencybalance' => $accounting, 'accountingcurrencysymbol' => $symbol,
        ])];
    }

    /** @return array<string, mixed> */
    private function details(int $n = 1): array
    {
        return ['status' => 200, 'body' => (string) json_encode([
            'orderid' => (string) (900000 + $n), 'customerid' => '555001', 'domainname' => "d{$n}.com",
            'endtime' => (string) self::EPOCH, 'currentstatus' => 'Active', 'recurring' => true,
        ])];
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function accepted(array $overrides = []): array
    {
        return ['status' => 200, 'body' => (string) json_encode($overrides + [
            'eaqid' => '111222', 'actionstatus' => 'Success', 'actionstatusdesc' => 'Renewed', 'invoiceid' => '333444', 'status' => 'Success',
        ])];
    }

    /** Queues the three GETs of one successful request: price, balance, details. */
    private function passingGets(int $n = 1): void
    {
        $this->getScript = [$this->priceList(), $this->balance(), $this->details($n)];
    }

    private function assertNothingCalled(): void
    {
        $this->assertSame([], $this->getCalls, 'No provider GET may have been made.');
        $this->assertSame([], $this->postCalls, 'No renewal POST may have been sent.');
    }

    private function otherConnection(): \CodeIgniter\Database\BaseConnection
    {
        return $this->other ??= Database::connect('tests', false);
    }

    private function assertLockFree(): void
    {
        $conn = Database::connect('tests', false);

        try {
            $got = (int) $conn->query('SELECT GET_LOCK(?, 0) AS got', [self::LOCK])->getRowArray()['got'];
            $this->assertSame(1, $got, 'The single-flight lock must never be left held.');
            $conn->query('SELECT RELEASE_LOCK(?)', [self::LOCK]);
        } finally {
            $conn->close();
        }
    }

    // ---- exposure and authentication ---------------------------------------------

    public function testTheShippedConfigStaysOff(): void
    {
        $c = IsolatedResellerClubConfig::make();

        $this->assertFalse($c->renewalExecutionEnabled);
        $this->assertFalse($c->renewalDebitVerified);
    }

    public function testTheEndpointRequiresAuthentication(): void
    {
        $batch = $this->batch('a@example.test', [$this->domain(1)]);
        $item  = $this->itemIds($batch)[0];

        [$status, $body] = $this->renew($batch, $item, $this->confirm(), null);

        $this->assertSame(401, $status);
        $this->assertNothingCalled();
        $this->assertSame(['pending'], $this->states($batch));
    }

    public function testOnlyPostIsRoutedAndNoBatchWideExecuteRouteExists(): void
    {
        $batch = $this->batch('a@example.test', [$this->domain(1)]);
        $item  = $this->itemIds($batch)[0];

        foreach (['GET', 'PUT', 'PATCH', 'DELETE'] as $method) {
            try {
                $status = $this->withHeaders(['Authorization' => 'Bearer ' . $this->tokens['a@example.test']])->call($method, "api/v1/renewal-batches/{$batch}/items/{$item}/renew")->response()->getStatusCode();
            } catch (\CodeIgniter\Exceptions\PageNotFoundException) {
                $status = 404;
            }

            $this->assertContains($status, [404, 405], $method);
        }

        foreach (["api/v1/renewal-batches/{$batch}/renew", "api/v1/renewal-batches/{$batch}/execute", "api/v1/renewal-batches/{$batch}/items/renew"] as $path) {
            try {
                $status = $this->withHeaders(['Authorization' => 'Bearer ' . $this->tokens['a@example.test']])->call('POST', $path)->response()->getStatusCode();
            } catch (\CodeIgniter\Exceptions\PageNotFoundException) {
                $status = 404;
            }

            $this->assertSame(404, $status, $path);
        }

        $this->assertNothingCalled();
    }

    public function testTheSwitchOffRefusesBeforeAnyProviderCall(): void
    {
        $this->wire(['renewalExecutionEnabled' => false]);
        $batch = $this->batch('a@example.test', [$this->domain(1)]);
        $item  = $this->itemIds($batch)[0];

        [$status, $body] = $this->renew($batch, $item, $this->confirm());

        $this->assertSame(503, $status);
        $this->assertSame('execution_disabled', $body['error']);
        $this->assertNothingCalled();
        $this->assertSame(['pending'], $this->states($batch));
    }

    public function testUnverifiedDebitReturnsBalanceCurrencyUnverifiedEvenWithTheSwitchOn(): void
    {
        // The SHIPPED debit default (false) with the switch temporarily on, in memory only.
        $this->wire(['renewalDebitVerified' => false]);
        $batch = $this->batch('a@example.test', [$this->domain(1)]);
        $item  = $this->itemIds($batch)[0];
        $this->passingGets();

        [$status, $body] = $this->renew($batch, $item, $this->confirm());

        $this->assertSame(503, $status);
        $this->assertSame('balance_currency_unverified', $body['error']);
        $this->assertNothingCalled();
        $this->assertSame(['pending'], $this->states($batch));
    }

    // ---- ownership -----------------------------------------------------------------

    public function testAnotherUsersBatchIsIndistinguishableFromAMissingOne(): void
    {
        $batch = $this->batch('b@example.test', [$this->domain(1)]);
        $item  = $this->itemIds($batch)[0];

        [$foreign, $fb] = $this->renew($batch, $item, $this->confirm(), 'a@example.test');
        [$missing, $mb] = $this->renew($batch + 999, $item, $this->confirm(), 'a@example.test');

        $this->assertSame([404, 404], [$foreign, $missing]);
        $this->assertSame($mb, $fb);
        $this->assertNothingCalled();
        $this->assertSame(['pending'], $this->states($batch));
    }

    public function testAnItemOfAnotherBatchIsRefusedAndNeverSubstituted(): void
    {
        $one = $this->batch('a@example.test', [$this->domain(1)], 'idem-key-aaaaaaaaaaaa');
        $two = $this->batch('a@example.test', [$this->domain(2)], 'idem-key-bbbbbbbbbbbb');

        [$status] = $this->renew($one, $this->itemIds($two)[0], $this->confirm(2));

        $this->assertSame(404, $status);
        $this->assertNothingCalled();
        $this->assertSame(['pending'], $this->states($two));
    }

    public function testMalformedIdsAreNotFound(): void
    {
        foreach (['abc', '0', '-1', '01', '1.5'] as $bad) {
            try {
                $status = $this->withHeaders(['Authorization' => 'Bearer ' . $this->tokens['a@example.test']])
                    ->withBodyFormat('json')->call('POST', "api/v1/renewal-batches/{$bad}/items/1/renew", $this->confirm())->response()->getStatusCode();
            } catch (\CodeIgniter\Exceptions\PageNotFoundException) {
                $status = 404;
            }

            $this->assertSame(404, $status, $bad);
        }

        $this->assertNothingCalled();
    }

    // ---- request validation and confirmation ---------------------------------------

    /**
     * @return array<string, array{0: array<string, mixed>|null, 1: string|null}>
     */
    public static function badBodies(): array
    {
        $good = ['acknowledge_charge' => true, 'confirm_domain_name' => 'd1.com', 'confirm_total_amount' => '1674.42', 'confirm_currency' => 'INR', 'confirm_years' => 1, 'confirm_invoice_option' => 'KeepInvoice'];

        return [
            'no body'           => [null, null],
            'not json'          => [null, 'not json'],
            'json list'         => [null, '[1,2]'],
            'unknown user_id'   => [$good + ['user_id' => 1], null],
            'unknown amount'    => [$good + ['amount' => '1.00'], null],
            'unknown order id'  => [$good + ['order_id' => '1'], null],
            'missing field'     => [array_diff_key($good, ['confirm_currency' => 1]), null],
            'years as string'   => [['confirm_years' => '1'] + $good, null],
            'total as number'   => [['confirm_total_amount' => 1674.42] + $good, null],
            'oversize'          => [null, '{"confirm_domain_name":"' . str_repeat('a', 3000) . '"}'],
        ];
    }

    /**
     * @dataProvider badBodies
     *
     * @param array<string, mixed>|null $body
     */
    public function testMalformedBodiesAreRefusedWithoutAnyProviderCall(?array $body, ?string $raw): void
    {
        $batch = $this->batch('a@example.test', [$this->domain(1)]);
        $item  = $this->itemIds($batch)[0];

        [$status, $json] = $this->renew($batch, $item, $body, 'a@example.test', $raw);

        $this->assertSame(400, $status);
        $this->assertSame('invalid_request', $json['error']);
        $this->assertNothingCalled();
        $this->assertSame(['pending'], $this->states($batch));
    }

    /**
     * @return array<string, array{0: mixed}>
     */
    public static function badAcknowledgements(): array
    {
        return ['false' => [false], 'string true' => ['true'], 'one' => [1], 'null' => [null]];
    }

    /**
     * @dataProvider badAcknowledgements
     */
    public function testTheChargeMustBeAcknowledgedWithLiteralTrue(mixed $ack): void
    {
        $batch = $this->batch('a@example.test', [$this->domain(1)]);
        $item  = $this->itemIds($batch)[0];

        [$status, $json] = $this->renew($batch, $item, $this->confirm(1, ['acknowledge_charge' => $ack]));

        $this->assertSame(422, $status);
        $this->assertSame('confirmation_mismatch', $json['error']);
        $this->assertNothingCalled();
        $this->assertSame(['pending'], $this->states($batch));
    }

    /**
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function mismatches(): array
    {
        return [
            'domain'          => [['confirm_domain_name' => 'other.com']],
            'domain case'     => [['confirm_domain_name' => 'D1.com']],
            'total lower'     => [['confirm_total_amount' => '1.00']],
            'total format'    => [['confirm_total_amount' => '1674.4200']],
            'total no zeroes' => [['confirm_total_amount' => '1674.4']],
            'currency'        => [['confirm_currency' => 'USD']],
            'years'           => [['confirm_years' => 2]],
            'invoice option'  => [['confirm_invoice_option' => 'NoInvoice']],
        ];
    }

    /**
     * @dataProvider mismatches
     *
     * @param array<string, mixed> $override
     */
    public function testAnyConfirmationMismatchIsRefusedBeforeAnyProviderCall(array $override): void
    {
        $batch = $this->batch('a@example.test', [$this->domain(1)]);
        $item  = $this->itemIds($batch)[0];
        $this->passingGets();

        [$status, $json] = $this->renew($batch, $item, $this->confirm(1, $override));

        $this->assertSame(422, $status);
        $this->assertSame(['error' => 'confirmation_mismatch'], $json);
        $this->assertNothingCalled();
        $this->assertSame(['pending'], $this->states($batch));
    }

    public function testAnItemIdOfAnotherDomainFailsTheDomainConfirmation(): void
    {
        $batch = $this->batch('a@example.test', [$this->domain(1), $this->domain(2)]);
        [$one, $two] = $this->itemIds($batch);

        // The screen showed d1.test but the request names the second item.
        [$status, $json] = $this->renew($batch, $two, $this->confirm(1));

        $this->assertSame(422, $status);
        $this->assertSame('confirmation_mismatch', $json['error']);
        $this->assertNothingCalled();
        $this->assertSame(['pending', 'pending'], $this->states($batch));
    }

    // ---- preflight refusals --------------------------------------------------------

    /**
     * @return array<string, array{0: string, 1: int, 2: string}>
     */
    public static function preflightCases(): array
    {
        return [
            'price rose'           => ['price_up', 409, 'price_changed'],
            'balance symbol'       => ['symbol', 503, 'balance_currency_mismatch'],
            'balance outage'       => ['outage', 503, 'balance_unavailable'],
            'batch expired'        => ['expired', 409, 'batch_expired'],
        ];
    }

    /**
     * @dataProvider preflightCases
     */
    public function testPreflightFailuresLeaveTheItemPendingAndSendNothing(string $case, int $httpStatus, string $code): void
    {
        $batch = $this->batch('a@example.test', [$this->domain(1)]);
        $item  = $this->itemIds($batch)[0];

        match ($case) {
            'price_up'     => $this->getScript = [$this->priceList('1500.00'), $this->balance()],
            'symbol'       => $this->getScript = [$this->priceList(), $this->balance('5000.0000', 'USD')],
            'outage'       => $this->getScript = [$this->priceList(), new \App\Libraries\ResellerClub\ResellerClubException('http_503', true)],
            'expired'      => $this->now = '2026-11-09 10:15:01',
        };

        $this->wire();

        [$status, $json] = $this->renew($batch, $item, $this->confirm());

        $this->assertSame($httpStatus, $status);
        $this->assertSame($code, $json['error']);
        $this->assertSame([], $this->postCalls);
        $this->assertSame(['pending'], $this->states($batch));
    }

    // ---- successful execution ------------------------------------------------------

    public function testAConfirmedRenewalSendsExactlyOnePostAndRecordsSuccess(): void
    {
        $batch = $this->batch('a@example.test', [$this->domain(1)]);
        $item  = $this->itemIds($batch)[0];
        $this->passingGets();
        $this->postScript = [$this->accepted()];

        [$status, $json, $text] = $this->renew($batch, $item, $this->confirm());

        $this->assertSame(200, $status);
        $this->assertFalse($json['replayed']);
        $this->assertSame('succeeded', $json['item']['state']);
        $this->assertSame(['111222', '333444'], [$json['item']['provider_action_id'], $json['item']['provider_invoice_id']]);
        $this->assertSame('none', $json['next']);
        $this->assertSame(['estimated_total' => '1674.42', 'currency' => 'INR', 'basis' => 'estimated_inclusive_of_gst_not_final_invoice'], $json['amount']);
        $this->assertCount(1, $this->postCalls);
        $this->assertSame('https://test.httpapi.example/api/domains/renew.json', $this->postCalls[0][0]);
        $this->assertSame('KeepInvoice', $this->postCalls[0][1]['invoice-option']);
        $this->assertSame(['succeeded'], $this->states($batch));

        // Provider success is NOT a debit confirmation: the two statuses are independent.
        $this->assertSame('unverified', $json['batch']['items'][0]['debit_verification_status']);
        $row = $this->item($item);
        $this->assertSame(['unverified', null, null], [$row['debit_verification_status'], $row['debit_verified_at'], $row['debit_transaction_id']]);

        // Nothing sensitive is returned: no key, no balance.
        $this->assertStringNotContainsString(self::API_KEY, $text);
        $this->assertStringNotContainsString('5000', $text);
        $this->assertStringNotContainsString('available_balance', $text);
    }

    public function testBothInvoiceOptionsExecuteTheRenewalAndOnlyChangeInvoiceHandling(): void
    {
        foreach (['KeepInvoice', 'NoInvoice'] as $i => $option) {
            $this->postCalls = [];
            $batch           = $this->batch('a@example.test', [$this->domain($i + 1)], 'idem-key-opt-' . str_repeat((string) $i, 8) . 'xxxx', $option);
            $item            = $this->itemIds($batch)[0];
            $this->getScript  = [$this->priceList(), $this->balance(), $this->details($i + 1)];
            $this->postScript = [$this->accepted()];

            [$status, $json] = $this->renew($batch, $item, $this->confirm($i + 1, ['confirm_invoice_option' => $option]));

            $this->assertSame(200, $status, $option);
            $this->assertSame('succeeded', $json['item']['state'], $option);
            $this->assertCount(1, $this->postCalls, $option);
            $this->assertSame($option, $this->postCalls[0][1]['invoice-option'], $option);
        }
    }

    public function testADefiniteProviderRejectionIsRecordedAsFailedAndNeverRetried(): void
    {
        $batch = $this->batch('a@example.test', [$this->domain(1)]);
        $item  = $this->itemIds($batch)[0];
        $this->passingGets();
        $this->postScript = [$this->accepted(['actionstatus' => 'Failed', 'actionstatusdesc' => 'no'])];

        [$status, $json] = $this->renew($batch, $item, $this->confirm());

        $this->assertSame(200, $status);
        $this->assertSame('failed', $json['item']['state']);
        $this->assertCount(1, $this->postCalls);

        // A second tap does not resubmit.
        $this->getCalls = [];
        [$again, $body] = $this->renew($batch, $item, $this->confirm());
        $this->assertSame(409, $again);
        $this->assertSame('item_not_pending', $body['error']);
        $this->assertCount(1, $this->postCalls);
        $this->assertSame([], $this->getCalls);
    }

    public function testAnUnclearProviderAnswerIsUncertainAcceptedAndNeverResubmitted(): void
    {
        $batch = $this->batch('a@example.test', [$this->domain(1)]);
        $item  = $this->itemIds($batch)[0];
        $this->passingGets();
        $this->postScript = [$this->accepted(['actionstatus' => 'Processing'])];

        [$status, $json] = $this->renew($batch, $item, $this->confirm());

        $this->assertSame(202, $status);
        $this->assertSame('uncertain', $json['item']['state']);
        $this->assertSame('reconcile', $json['next']);

        $this->getCalls = [];
        [$again, $body] = $this->renew($batch, $item, $this->confirm());

        $this->assertSame(409, $again);
        $this->assertSame(['item_not_pending'], [$body['error']]);
        $this->assertSame('reconcile', $body['next']);
        $this->assertCount(1, $this->postCalls);
        $this->assertSame([], $this->getCalls);
    }

    public function testAProviderTimeoutOnThePostIsUncertainAndSentOnce(): void
    {
        $batch = $this->batch('a@example.test', [$this->domain(1)]);
        $item  = $this->itemIds($batch)[0];
        $this->passingGets();
        $this->postScript = [new \App\Libraries\ResellerClub\ResellerClubException('timeout', true)];

        [$status, $json] = $this->renew($batch, $item, $this->confirm());

        $this->assertSame(202, $status);
        $this->assertSame('uncertain', $json['item']['state']);
        $this->assertCount(1, $this->postCalls);
        $this->assertSame(['uncertain'], $this->states($batch));
    }

    public function testOnlyTheNamedItemOfAMultiItemBatchIsRenewed(): void
    {
        $batch = $this->batch('a@example.test', [$this->domain(1), $this->domain(2), $this->domain(3)]);
        [$one, $two, $three] = $this->itemIds($batch);
        $this->getScript  = [$this->priceList(), $this->balance(), $this->details(2)];
        $this->postScript = [$this->accepted()];

        [$status, $json] = $this->renew($batch, $two, $this->confirm(2));

        $this->assertSame(200, $status);
        $this->assertSame($two, $json['item']['id']);
        $this->assertSame(['pending', 'succeeded', 'pending'], $this->states($batch));
        $this->assertCount(1, $this->postCalls);
    }

    // ---- repeats and stored states -------------------------------------------------

    public function testARepeatAfterSuccessIsAReplayWithNoProviderCall(): void
    {
        $batch = $this->batch('a@example.test', [$this->domain(1)]);
        $item  = $this->itemIds($batch)[0];
        $this->passingGets();
        $this->postScript = [$this->accepted()];
        $this->renew($batch, $item, $this->confirm());

        $this->getCalls = [];
        [$status, $json] = $this->renew($batch, $item, $this->confirm());

        $this->assertSame(200, $status);
        $this->assertTrue($json['replayed']);
        $this->assertSame('succeeded', $json['item']['state']);
        $this->assertCount(1, $this->postCalls, 'The renewal is sent once, ever.');
        $this->assertSame([], $this->getCalls);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function nonPendingStates(): array
    {
        return ['submitting' => ['submitting'], 'failed' => ['failed'], 'uncertain' => ['uncertain']];
    }

    /**
     * @dataProvider nonPendingStates
     */
    public function testAnItemThatIsNotPendingIsNeverResubmitted(string $state): void
    {
        $batch = $this->batch('a@example.test', [$this->domain(1)]);
        $item  = $this->itemIds($batch)[0];
        $this->setState($item, $state);
        $this->passingGets();

        [$status, $json] = $this->renew($batch, $item, $this->confirm());

        $this->assertSame(409, $status);
        $this->assertSame('item_not_pending', $json['error']);
        $this->assertSame($state, $json['item']['state']);
        $this->assertNothingCalled();
        $this->assertSame([$state], $this->states($batch));
    }

    public function testTwoSequentialRequestsSendOnePostInTotal(): void
    {
        $batch = $this->batch('a@example.test', [$this->domain(1)]);
        $item  = $this->itemIds($batch)[0];
        $this->passingGets();
        $this->postScript = [$this->accepted(), $this->accepted()];

        $first  = $this->renew($batch, $item, $this->confirm());
        $second = $this->renew($batch, $item, $this->confirm());

        $this->assertSame([200, 200], [$first[0], $second[0]]);
        $this->assertTrue($second[1]['replayed']);
        $this->assertCount(1, $this->postCalls);
    }

    // ---- single-flight lock --------------------------------------------------------

    public function testARenewalIsRefusedWhileAnotherConnectionHoldsTheLock(): void
    {
        $batch = $this->batch('a@example.test', [$this->domain(1)]);
        $item  = $this->itemIds($batch)[0];
        $this->passingGets();
        $this->postScript = [$this->accepted()];

        $held = (int) $this->otherConnection()->query('SELECT GET_LOCK(?, 0) AS got', [self::LOCK])->getRowArray()['got'];
        $this->assertSame(1, $held);

        [$status, $json] = $this->renew($batch, $item, $this->confirm());

        $this->assertSame(409, $status);
        $this->assertSame('execution_in_progress', $json['error']);
        $this->assertNothingCalled();
        $this->assertSame(['pending'], $this->states($batch));

        // The other holder keeps its lock: this request did not steal or release it.
        $this->assertSame(1, (int) $this->otherConnection()->query('SELECT IS_USED_LOCK(?) = CONNECTION_ID() AS mine', [self::LOCK])->getRowArray()['mine']);

        // Once the other holder lets go, the same request proceeds.
        $this->otherConnection()->query('SELECT RELEASE_LOCK(?)', [self::LOCK]);
        [$after, $ok] = $this->renew($batch, $item, $this->confirm());

        $this->assertSame(200, $after);
        $this->assertSame('succeeded', $ok['item']['state']);
        $this->assertCount(1, $this->postCalls);
    }

    public function testTheLockIsReleasedAfterEveryKindOfOutcome(): void
    {
        // success, definite failure, uncertain, preflight refusal, claim-time rejection, confirmation mismatch
        $cases = [
            'success'   => [[$this->priceList(), $this->balance(), $this->details(1)], [$this->accepted()], 200],
            'failed'    => [[$this->priceList(), $this->balance(), $this->details(2)], [$this->accepted(['actionstatus' => 'Failed'])], 200],
            'uncertain' => [[$this->priceList(), $this->balance(), $this->details(3)], [$this->accepted(['actionstatus' => 'Weird'])], 202],
            'preflight' => [[$this->priceList('1500.00'), $this->balance()], [], 409],
            'details'   => [[$this->priceList(), $this->balance(), new \App\Libraries\ResellerClub\ResellerClubException('http_503', true)], [], 200],
        ];

        $n = 0;

        foreach ($cases as $name => [$gets, $posts, $expected]) {
            $n++;
            $batch = $this->batch('a@example.test', [$this->domain($n)], 'idem-key-lock-' . str_pad((string) $n, 8, 'x') . 'xx');
            $item  = $this->itemIds($batch)[0];
            $this->getScript  = $gets;
            $this->postScript = $posts;

            $this->resetThrottleBuckets(); // six calls in one test would otherwise trip the real throttle
            [$status] = $this->renew($batch, $item, $this->confirm($n));

            $this->assertSame($expected, $status, $name);
            $this->assertLockFree();
        }

        $this->resetThrottleBuckets();
        [$mismatch] = $this->renew($batch, $item, $this->confirm($n, ['confirm_currency' => 'USD']));
        $this->assertSame(422, $mismatch);
        $this->assertLockFree();
    }

    public function testTheLockIsReleasedWhenAnUnexpectedExceptionEscapes(): void
    {
        $batch = $this->batch('a@example.test', [$this->domain(1)]);
        $item  = $this->itemIds($batch)[0];
        // Nothing queued: the first provider GET throws a RuntimeException from inside the fake transport.
        $this->getScript = [];

        [$status] = $this->renew($batch, $item, $this->confirm());

        $this->assertContains($status, [409, 500, 503]);
        $this->assertSame([], $this->postCalls);
        $this->assertSame(['pending'], $this->states($batch));
        $this->assertLockFree();
    }

    public function testTheLockIsHeldOnTheSameConnectionThatSendsTheRenewal(): void
    {
        $batch = $this->batch('a@example.test', [$this->domain(1)]);
        $item  = $this->itemIds($batch)[0];
        $this->passingGets();

        // While the POST is "in flight" a second connection must see the lock as taken.
        $probe  = null;
        $other  = $this->otherConnection();
        $config = $this->config();

        $spyClient = new ResellerClubClient(
            $config,
            function (string $url): array {
                $this->getCalls[] = (string) parse_url($url, PHP_URL_PATH);
                $next             = array_shift($this->getScript);

                return $next;
            },
            static function (int $ms): void {
            },
            function (string $url, array $form) use ($other, &$probe): array {
                $this->postCalls[] = [$url, $form];
                $probe             = (int) $other->query('SELECT GET_LOCK(?, 0) AS got', [self::LOCK])->getRowArray()['got'];

                return $this->accepted();
            },
        );

        $store = [];
        $cache = $this->createMock(\CodeIgniter\Cache\CacheInterface::class);
        $cache->method('get')->willReturnCallback(static fn (string $k) => $store[$k] ?? null);
        $cache->method('save')->willReturn(true);
        $clock = fn (): DateTimeImmutable => new DateTimeImmutable($this->now, new DateTimeZone('UTC'));

        $spy = new RenewalExecutionService(
            $spyClient,
            $config,
            $this->testDb,
            $clock,
            new RenewalPreflightService($spyClient, $config, new RenewalQuoteService($spyClient, $config, $cache), $this->testDb, $clock),
        );
        Services::injectMock('renewalExecutionGateway', new RenewalExecutionGateway($spy));

        [$status, $json] = $this->renew($batch, $item, $this->confirm());

        $this->assertSame(200, $status);
        $this->assertSame('succeeded', $json['item']['state']);
        $this->assertSame(0, $probe, 'During the POST the lock must be held by the renewing connection.');
    }

    // ---- throttle ------------------------------------------------------------------

    public function testTheEndpointHasItsOwnStrictThrottle(): void
    {
        $this->wire(['renewalExecutionEnabled' => false]);
        $batch = $this->batch('a@example.test', [$this->domain(1)]);
        $item  = $this->itemIds($batch)[0];

        $this->assertSame(5, config('Auth')->ipLimits['renewal_execute']);

        $statuses = [];

        for ($i = 0; $i < 6; $i++) {
            $statuses[] = $this->renew($batch, $item, $this->confirm())[0];
        }

        $this->assertSame([503, 503, 503, 503, 503, 429], $statuses);
        $this->assertNothingCalled();
    }
}
