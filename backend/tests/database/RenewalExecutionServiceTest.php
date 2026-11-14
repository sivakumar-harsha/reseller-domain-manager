<?php

declare(strict_types=1);

use App\Libraries\Renewal\RenewalException;
use App\Libraries\Renewal\RenewalExecutionService;
use App\Libraries\ResellerClub\ResellerClubClient;
use CodeIgniter\Test\CIUnitTestCase;
use Config\ResellerClub as ResellerClubConfig;
use Tests\Support\Libraries\IsolatedResellerClubConfig;
use Tests\Support\Database\UsesEnrichmentSchema;
use Tests\Support\Database\UsesIsolatedAuthDatabase;
use Tests\Support\Database\UsesRenewalSchema;

/**
 * RenewalExecutionService against FAKE provider transports and the isolated
 * *_test database. No network access: both the GET and the POST transport are
 * injected spies, and an unexpected extra call fails the test. The execution
 * switch is turned on only on private in-memory config objects (never the
 * shared application config or .env), and the success status vocabulary is test
 * data because ResellerClub's reference does not publish the real values.
 *
 * @internal
 */
final class RenewalExecutionServiceTest extends CIUnitTestCase
{
    use UsesIsolatedAuthDatabase {
        tearDownAfterClass as private tearDownAuthSchema;
    }
    use UsesEnrichmentSchema;
    use UsesRenewalSchema;

    private const EXPIRY_AT    = '2027-01-01 00:00:00';
    private const EXPIRY_EPOCH = 1798761600;
    private const NOW          = '2026-11-09 10:00:00';
    private const KEY_A        = 'idem-key-aaaaaaaaaaaa';
    private const KEY_B        = 'idem-key-bbbbbbbbbbbb';

    /** @var list<array{status: int, body: string}|Throwable> */
    private array $getScript = [];

    /** @var list<array{status: int, body: string}|Throwable|callable> */
    private array $postScript = [];

    /** @var list<array<string, scalar>> */
    private array $getCalls = [];

    /** @var list<array{0: string, 1: array<string, scalar>}> */
    private array $postCalls = [];

    private int $userId = 0;

    protected function setUp(): void
    {
        parent::setUp();

        // First: the RESTRICT foreign keys would block the other traits emptying users/domains.
        self::emptyRenewalTables();

        $this->setUpIsolatedAuthDatabase();
        $this->setUpEnrichmentSchema();
        $this->setUpRenewalSchema();

        $this->getScript  = [];
        $this->postScript = [];
        $this->getCalls   = [];
        $this->postCalls  = [];
        $this->userId     = $this->insertUser();
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
        $c->apiKey                  = 'sentinel-api-key-never-store';
        $c->maxAttempts             = 1;
        $c->renewalExecutionEnabled = true; // in-memory only; fake transports below
        $c->renewalSuccessStatuses  = ['Success'];
        $c->renewalFailureStatuses  = ['Failed'];

        foreach ($overrides as $k => $v) {
            $c->{$k} = $v;
        }

        return $c;
    }

    /** @param array<string, mixed> $cfg */
    private function service(array $cfg = [], string $now = self::NOW): RenewalExecutionService
    {
        $config = $this->config($cfg);

        $client = new ResellerClubClient(
            $config,
            function (string $url, array $query): array {
                $this->getCalls[] = $query;
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
            function (string $url, array $form) use (&$client): array {
                $this->postCalls[] = [$url, $form];
                $next              = array_shift($this->postScript);

                if ($next === null) {
                    throw new RuntimeException('unexpected provider POST');
                }

                if ($next instanceof Throwable) {
                    throw $next;
                }

                return is_callable($next) ? $next() : $next;
            },
        );

        $clock = static fn (): DateTimeImmutable => new DateTimeImmutable($now, new DateTimeZone('UTC'));

        // The preflight shares the fake client; its quote service gets an
        // empty in-memory cache so nothing is read from or written to the
        // real cache, and the fresh-price fetch always hits the GET spy.
        $store = [];
        $cache = $this->createMock(\CodeIgniter\Cache\CacheInterface::class);
        $cache->method('get')->willReturnCallback(static fn (string $k) => $store[$k] ?? null);
        $cache->method('save')->willReturn(true);

        $preflight = new \App\Libraries\Renewal\RenewalPreflightService(
            $client,
            $config,
            new \App\Libraries\Pricing\RenewalQuoteService($client, $config, $cache),
            $this->testDb,
            $clock,
        );

        return new RenewalExecutionService($client, $config, $this->testDb, $clock, $preflight);
    }

    /** @param array<string, mixed> $overrides */
    private function domain(int $n = 1, array $overrides = []): int
    {
        return $this->insertEnrichableDomain($overrides + [
            'rc_order_id' => (string) (900000 + $n),
            'domain_name' => "d{$n}.test",
            'expires_at'  => self::EXPIRY_AT,
            'auto_renew'  => 1,
        ]);
    }

    /** @return array<string, mixed> */
    private function line(int $domainId): array
    {
        return [
            'domain_id' => $domainId, 'renewal_amount' => '1419.00', 'gst_amount' => '255.42',
            'vat_amount' => '0.00', 'total_amount' => '1674.42', 'currency' => 'INR',
        ];
    }

    /**
     * @param list<int> $domainIds
     *
     * @return int batch id
     */
    private function batch(array $domainIds, string $invoice = 'KeepInvoice', int $years = 1, string $key = self::KEY_A): int
    {
        return $this->service()->createBatch($this->userId, $key, $invoice, $years, array_map(fn (int $d): array => $this->line($d), $domainIds))['batch_id'];
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

    /** @return array<string, mixed> */
    private function batchRow(int $id): array
    {
        return $this->testDb->table('renewal_batches')->where('id', $id)->get()->getRowArray();
    }

    /** @param array<string, mixed> $overrides */
    private function details(int $n = 1, array $overrides = []): array
    {
        return ['status' => 200, 'body' => (string) json_encode($overrides + [
            'orderid' => (string) (900000 + $n), 'customerid' => '555001', 'domainname' => "d{$n}.test",
            'endtime' => (string) self::EXPIRY_EPOCH, 'currentstatus' => 'Active', 'recurring' => true,
        ])];
    }

    /** @param array<string, mixed> $overrides */
    private function accepted(array $overrides = []): array
    {
        return ['status' => 200, 'body' => (string) json_encode($overrides + [
            'eaqid' => '111222', 'actionstatus' => 'Success', 'actionstatusdesc' => 'Renewed', 'invoiceid' => '333444', 'status' => 'Success',
        ])];
    }

    private function assertNothingSent(): void
    {
        $this->assertSame([], $this->postCalls, 'No renewal POST may have been sent.');
    }

    // ---- createBatch ---------------------------------------------------------------

    public function testCreateBatchStoresSnapshotsFromTheDomainRowAndSkipsTheProvider(): void
    {
        $d = $this->domain(1);

        $result = $this->service(['renewalExecutionEnabled' => false])->createBatch($this->userId, self::KEY_A, 'NoInvoice', 2, [
            $this->line($d) + ['rc_order_id' => '1', 'expiry_at_request' => '2030-01-01 00:00:00'], // caller values must be ignored
        ]);

        $this->assertFalse($result['replayed']);
        $batch = $this->batchRow($result['batch_id']);
        $this->assertSame(['NoInvoice', '2', 'pending', null], [$batch['invoice_option'], (string) $batch['years'], $batch['status'], $batch['finished_at']]);

        $item = $this->item($this->itemIds($result['batch_id'])[0]);
        $this->assertSame('900001', $item['rc_order_id']);
        $this->assertSame(self::EXPIRY_AT, $item['expiry_at_request']);
        $this->assertSame(['1419.00', '255.42', '0.00', '1674.42', 'INR', 'pending'], [
            $item['renewal_amount'], $item['gst_amount'], $item['vat_amount'], $item['total_amount'], $item['currency'], $item['state'],
        ]);
        $this->assertSame([], $this->getCalls);
        $this->assertNothingSent();
    }

    public function testSameKeyAndContentIsAReplayNotADuplicate(): void
    {
        $d = $this->domain(1);

        $first  = $this->service()->createBatch($this->userId, self::KEY_A, 'KeepInvoice', 1, [$this->line($d)]);
        $second = $this->service()->createBatch($this->userId, self::KEY_A, 'KeepInvoice', 1, [$this->line($d)]);

        $this->assertSame($first['batch_id'], $second['batch_id']);
        $this->assertTrue($second['replayed']);
        $this->assertSame(1, $this->testDb->table('renewal_batches')->countAllResults());
        $this->assertSame(1, $this->testDb->table('renewal_items')->countAllResults());
    }

    public function testSameKeyWithDifferentContentIsRefused(): void
    {
        $a = $this->domain(1);
        $b = $this->domain(2);
        $this->service()->createBatch($this->userId, self::KEY_A, 'KeepInvoice', 1, [$this->line($a)]);

        foreach ([
            ['NoInvoice', 1, [$a]],
            ['KeepInvoice', 2, [$a]],
            ['KeepInvoice', 1, [$a, $b]],
            ['KeepInvoice', 1, [$b]],
        ] as [$invoice, $years, $ids]) {
            $this->assertRefused('idempotency_conflict', fn () => $this->service()->createBatch(
                $this->userId,
                self::KEY_A,
                $invoice,
                $years,
                array_map(fn (int $d): array => $this->line($d), $ids),
            ));
        }

        $this->assertSame(1, $this->testDb->table('renewal_batches')->countAllResults());
    }

    /** @return array<string, array{0: string, 1: string, 2: int, 3: string}> */
    public static function badBatchHeaders(): array
    {
        return [
            'short key'          => ['invalid_idempotency_key', 'KeepInvoice', 1, 'short'],
            'key with spaces'    => ['invalid_idempotency_key', 'KeepInvoice', 1, 'idem key with spaces!!'],
            'lowercase option'   => ['invalid_invoice_option', 'keepinvoice', 1, self::KEY_A],
            'PayInvoice'         => ['invalid_invoice_option', 'PayInvoice', 1, self::KEY_A],
            'empty option'       => ['invalid_invoice_option', '', 1, self::KEY_A],
            'zero years'         => ['invalid_years', 'KeepInvoice', 0, self::KEY_A],
            'eleven years'       => ['invalid_years', 'NoInvoice', 11, self::KEY_A],
        ];
    }

    /** @dataProvider badBatchHeaders */
    public function testInvalidBatchHeadersAreRefusedBeforeAnythingIsStored(string $code, string $invoice, int $years, string $key): void
    {
        $d = $this->domain(1);

        $this->assertRefused($code, fn () => $this->service()->createBatch($this->userId, $key, $invoice, $years, [$this->line($d)]));
        $this->assertSame(0, $this->testDb->table('renewal_batches')->countAllResults());
    }

    public function testInvalidBatchContentIsRefused(): void
    {
        $d = $this->domain(1);
        $ok = $this->line($d);

        $cases = [
            'invalid_batch_size'  => [[]],
            'invalid_line'        => [[['domain_id' => '1'] + $ok]],
            'duplicate_domain'    => [[$ok, $ok]],
            'domain_not_found'    => [[['domain_id' => 999999] + $ok]],
            'invalid_amount'      => [[['total_amount' => '1674.43'] + $ok], [['renewal_amount' => '1419,00'] + $ok], [['gst_amount' => null] + $ok], [['vat_amount' => '-1.00', 'total_amount' => '1673.42'] + $ok]],
            'invalid_currency'    => [[['currency' => 'inr'] + $ok], [['currency' => '₹'] + $ok], [['currency' => null] + $ok]],
        ];

        foreach ($cases as $code => $variants) {
            foreach ($variants as $lines) {
                $this->assertRefused($code, fn () => $this->service()->createBatch($this->userId, self::KEY_A, 'KeepInvoice', 1, $lines));
            }
        }

        $this->assertRefused('invalid_batch_size', fn () => $this->service()->createBatch($this->userId, self::KEY_A, 'KeepInvoice', 1, array_fill(0, 51, $ok)));
        $this->assertRefused('invalid_user', fn () => $this->service()->createBatch(999999, self::KEY_A, 'KeepInvoice', 1, [$ok]));
        $this->assertSame(0, $this->testDb->table('renewal_batches')->countAllResults());
    }

    public function testDomainsThatCannotBeRenewedAreRefused(): void
    {
        $missing = $this->domain(1, ['is_missing' => 1]);
        $noCust  = $this->domain(2, ['rc_customer_id' => null]);
        $noExp   = $this->domain(3, ['expires_at' => null]);
        $badId   = $this->domain(4, ['rc_order_id' => 'ABC123']);
        $inactive = $this->insertUser(['email' => 'off@example.test', 'is_active' => 0]);

        $this->assertRefused('domain_missing', fn () => $this->service()->createBatch($this->userId, self::KEY_A, 'KeepInvoice', 1, [$this->line($missing)]));
        $this->assertRefused('customer_unknown', fn () => $this->service()->createBatch($this->userId, self::KEY_A, 'KeepInvoice', 1, [$this->line($noCust)]));
        $this->assertRefused('expiry_unknown', fn () => $this->service()->createBatch($this->userId, self::KEY_A, 'KeepInvoice', 1, [$this->line($noExp)]));
        $this->assertRefused('invalid_order_id', fn () => $this->service()->createBatch($this->userId, self::KEY_A, 'KeepInvoice', 1, [$this->line($badId)]));
        $this->assertRefused('invalid_user', fn () => $this->service()->createBatch($inactive, self::KEY_A, 'KeepInvoice', 1, [$this->line($this->domain(5))]));
        $this->assertSame(0, $this->testDb->table('renewal_batches')->countAllResults());
    }

    public function testSecondBatchForTheSameDomainCycleIsRefusedAndLeavesNothingBehind(): void
    {
        $a = $this->domain(1);
        $b = $this->domain(2);
        $this->batch([$a], 'KeepInvoice', 1, self::KEY_A);

        // The new batch would also cover a fresh domain: the whole batch must roll back.
        $this->assertRefused('duplicate_cycle', fn () => $this->service()->createBatch($this->userId, 'idem-key-bbbbbbbbbbbb', 'KeepInvoice', 1, [$this->line($b), $this->line($a)]));

        $this->assertSame(1, $this->testDb->table('renewal_batches')->countAllResults());
        $this->assertSame(1, $this->testDb->table('renewal_items')->countAllResults());

        // The failed transaction must not poison the connection: a valid batch still works.
        $this->batch([$b], 'KeepInvoice', 1, 'idem-key-cccccccccccc');
        $this->assertSame(2, $this->testDb->table('renewal_batches')->countAllResults());
        $this->assertSame(2, $this->testDb->table('renewal_items')->countAllResults());
    }

    public function testAfterADefiniteFailureTheCycleCanBeQuotedAgain(): void
    {
        $a     = $this->domain(1);
        $first = $this->batch([$a], 'KeepInvoice', 1, self::KEY_A);

        $this->getScript[]  = $this->details(1, ['endtime' => '1700000000']);
        $this->service()->processItem($this->itemIds($first)[0]);
        $this->assertSame('failed', $this->item($this->itemIds($first)[0])['state']);

        $second = $this->batch([$a], 'KeepInvoice', 1, 'idem-key-bbbbbbbbbbbb');
        $this->assertNotSame($first, $second);
    }

    // ---- execution switch ------------------------------------------------------------

    public function testExecutionDisabledRefusesWithoutTouchingAnything(): void
    {
        $batch = $this->batch([$this->domain(1)]);
        $item  = $this->itemIds($batch)[0];

        $this->assertRefused('execution_disabled', fn () => $this->service(['renewalExecutionEnabled' => false])->processItem($item));
        $this->assertRefused('execution_disabled', fn () => $this->service(['renewalExecutionEnabled' => false])->processBatch($batch));

        $this->assertSame('pending', $this->item($item)['state']);
        $this->assertSame('pending', $this->batchRow($batch)['status']);
        $this->assertSame([], $this->getCalls, 'Not even the read-only verification may run while disabled.');
        $this->assertNothingSent();
    }

    public function testTheSharedApplicationConfigurationHasExecutionDisabled(): void
    {
        $this->assertFalse(IsolatedResellerClubConfig::make()->renewalExecutionEnabled);
        $this->assertFalse(IsolatedResellerClubConfig::make()->renewalDebitVerified, 'The debit treatment is unverified; the flag must ship false.');
        $this->assertSame(900, IsolatedResellerClubConfig::make()->renewalBatchMaxAgeSeconds);
        $this->assertSame(1, IsolatedResellerClubConfig::make()->renewalMaxItemsPerExecution);
    }

    // ---- successful execution --------------------------------------------------------

    public function testKeepInvoiceSuccessRecordsActionAndInvoiceIdsAndCompletesTheBatch(): void
    {
        $domainId = $this->domain(1);
        $domainBefore = $this->testDb->table('domains')->where('id', $domainId)->get()->getRowArray();
        $batch    = $this->batch([$domainId], 'KeepInvoice', 2);
        $item     = $this->itemIds($batch)[0];

        $this->getScript[]  = $this->details(1);
        $this->postScript[] = $this->accepted();

        $outcome = $this->service()->processItem($item);

        $this->assertFalse($outcome->skipped);
        $this->assertSame(['succeeded', null, '111222', '333444'], [$outcome->state, $outcome->errorCode, $outcome->actionId, $outcome->invoiceId]);

        $row = $this->item($item);
        $this->assertSame(['succeeded', '111222', '333444', null, self::NOW], [$row['state'], $row['provider_action_id'], $row['provider_invoice_id'], $row['error_code'], $row['completed_at']]);

        // The verified expiry and exact parameters were sent, once.
        $this->assertCount(1, $this->postCalls);
        $this->assertSame('https://test.httpapi.example/api/domains/renew.json', $this->postCalls[0][0]);
        $form = $this->postCalls[0][1];
        $this->assertSame(['900001', 2, self::EXPIRY_EPOCH, 'true', 'KeepInvoice'], [$form['order-id'], $form['years'], $form['exp-date'], $form['auto-renew'], $form['invoice-option']]);

        $this->assertSame('unverified', $row['debit_verification_status'], 'Provider success never confirms a debit.');

        $batchRow = $this->batchRow($batch);
        $this->assertSame(['completed', self::NOW], [$batchRow['status'], $batchRow['finished_at']]);

        // The local domain record is not modified by a renewal (the next sync refreshes it).
        $this->assertSame($domainBefore, $this->testDb->table('domains')->where('id', $domainId)->get()->getRowArray());
    }

    public function testNoInvoiceSuccessStoresNoInvoiceIdWhenNoneIsReturned(): void
    {
        $batch = $this->batch([$this->domain(1, ['auto_renew' => 0])], 'NoInvoice');
        $item  = $this->itemIds($batch)[0];

        $this->getScript[]  = $this->details(1, ['recurring' => false]);
        $this->postScript[] = $this->accepted(['invoiceid' => null]);

        $this->assertSame('succeeded', $this->service()->processItem($item)->state);

        $row = $this->item($item);
        $this->assertNull($row['provider_invoice_id']);
        $this->assertSame('111222', $row['provider_action_id']);
        $this->assertSame(['false', 'NoInvoice'], [$this->postCalls[0][1]['auto-renew'], $this->postCalls[0][1]['invoice-option']]);
    }

    // ---- verification failures (nothing is ever sent) -----------------------------------

    public function testExpiryMismatchStopsAndRequiresANewQuote(): void
    {
        $batch = $this->batch([$this->domain(1)]);
        $item  = $this->itemIds($batch)[0];

        $this->getScript[] = $this->details(1, ['endtime' => (string) (self::EXPIRY_EPOCH + 86400)]);

        $outcome = $this->service()->processItem($item);

        $this->assertSame(['failed', 'expiry_changed'], [$outcome->state, $outcome->errorCode]);
        $this->assertSame('failed', $this->batchRow($batch)['status']);
        $this->assertNothingSent();
    }

    /** @return array<string, array{0: array<string, mixed>, 1: string}> */
    public static function badProviderDetails(): array
    {
        return [
            'no endtime'         => [['endtime' => null], 'expiry_unverified'],
            'text endtime'       => [['endtime' => 'tomorrow'], 'expiry_unverified'],
            'negative endtime'   => [['endtime' => '-5'], 'expiry_unverified'],
            'zero endtime'       => [['endtime' => '0'], 'expiry_unverified'],
            'other order id'     => [['orderid' => '123'], 'order_mismatch'],
            // The client's own parser already rejects a details body without an order id.
            'missing order id'   => [['orderid' => null], 'provider_unavailable'],
            'other customer'     => [['customerid' => '777'], 'customer_mismatch'],
            'missing customer'   => [['customerid' => null], 'customer_mismatch'],
        ];
    }

    /**
     * @dataProvider badProviderDetails
     *
     * @param array<string, mixed> $override
     */
    public function testUnverifiableProviderDetailsFailTheItemWithoutSending(array $override, string $code): void
    {
        $batch = $this->batch([$this->domain(1)]);
        $item  = $this->itemIds($batch)[0];

        $this->getScript[] = $this->details(1, $override);

        $this->assertSame(['failed', $code], $this->outcomePair($this->service()->processItem($item)));
        $this->assertNothingSent();
    }

    // ---- expired domains: no local grace rule, the provider decides -------------------------

    private const AFTER_EXPIRY = '2027-01-05 00:00:00'; // 4 days after the 2027-01-01 expiry

    public function testBatchForAnAlreadyExpiredActiveDomainIsAccepted(): void
    {
        $batch = $this->batch([$this->domain(1, ['expires_at' => '2026-01-01 00:00:00'])]);

        $this->assertCount(1, $this->itemIds($batch));
        $this->assertSame('pending', $this->item($this->itemIds($batch)[0])['state']);
    }

    public function testExpiredDomainReachesTheProviderWithItsCurrentExpiryAndIsRenewedExactlyOnce(): void
    {
        $domain = $this->domain(1); // expires 2027-01-01
        $batch  = $this->service([], self::AFTER_EXPIRY)->createBatch($this->userId, self::KEY_A, 'KeepInvoice', 1, [$this->line($domain)])['batch_id'];

        $this->getScript[]  = $this->details(1);
        $this->postScript[] = $this->accepted();

        $this->assertSame(['succeeded', null], $this->outcomePair($this->service([], self::AFTER_EXPIRY)->processItem($this->itemIds($batch)[0])));
        $this->assertCount(1, $this->postCalls);
        $this->assertSame(self::EXPIRY_EPOCH, $this->postCalls[0][1]['exp-date'], 'The provider expiry (in the past), not the clock, is sent as exp-date.');

        // Never retried: a second call is skipped and sends nothing more.
        $again = $this->service([], self::AFTER_EXPIRY)->processItem($this->itemIds($batch)[0]);
        $this->assertSame([true, 'succeeded'], [$again->skipped, $again->state]);
        $this->assertCount(1, $this->postCalls);
    }

    public function testProviderRejectionOfAnExpiredDomainIsRecordedWithItsMessageAndNeverRetried(): void
    {
        $batch = $this->service([], self::AFTER_EXPIRY)->createBatch($this->userId, self::KEY_A, 'KeepInvoice', 1, [$this->line($this->domain(1))])['batch_id'];
        $item  = $this->itemIds($batch)[0];

        $this->getScript[]  = $this->details(1);
        $this->postScript[] = ['status' => 200, 'body' => '{"status":"ERROR","message":"The domain has expired and cannot be renewed."}'];

        $service = $this->service([], self::AFTER_EXPIRY);
        $outcome = $service->processItem($item);

        $this->assertSame(['failed', 'provider_rejected'], $this->outcomePair($outcome));
        $this->assertCount(1, $this->postCalls);

        $row = $this->item($item);
        $this->assertSame('failed', $row['state']);
        $this->assertSame('The domain has expired and cannot be renewed.', $row['provider_message']);
        $this->assertNull($row['provider_invoice_id']);
        $this->assertNotNull($row['completed_at']);
        $this->assertSame('failed', $this->batchRow($batch)['status']);

        // No automatic retry: executing the failed item again is skipped and sends nothing.
        $again = $service->processItem($item);
        $this->assertSame([true, 'failed'], [$again->skipped, $again->state]);
        $this->assertCount(1, $this->postCalls);
    }

    public function testBatchCreatedBeforeExpiryAndExecutedAfterItStillReachesTheProvider(): void
    {
        $batch = $this->batch([$this->domain(1)]); // created while still valid (NOW = 2026-11-09)

        $this->getScript[]  = $this->details(1);
        $this->postScript[] = $this->accepted();

        $outcome = $this->service([], self::AFTER_EXPIRY)->processItem($this->itemIds($batch)[0]);

        $this->assertSame(['succeeded', null], $this->outcomePair($outcome));
        $this->assertCount(1, $this->postCalls);
    }

    public function testExpiredDomainStillFailsTheExistingSafetyChecksWithoutSending(): void
    {
        $batch = $this->batch([$this->domain(1)]);

        // Not Active at the provider (suspended): refused locally.
        $this->getScript[] = $this->details(1, ['currentstatus' => 'Suspended']);
        $this->assertSame(['failed', 'domain_not_active'], $this->outcomePair($this->service([], self::AFTER_EXPIRY)->processItem($this->itemIds($batch)[0])));
        $this->assertNothingSent();

        // The provider's expiry moved: needs a new quote, nothing sent.
        $batch2            = $this->batch([$this->domain(2)], 'KeepInvoice', 1, self::KEY_B);
        $this->getScript[] = $this->details(2, ['endtime' => (string) (self::EXPIRY_EPOCH + 86400)]);
        $this->assertSame(['failed', 'expiry_changed'], $this->outcomePair($this->service([], self::AFTER_EXPIRY)->processItem($this->itemIds($batch2)[0])));
        $this->assertNothingSent();
    }

    /** @return array<string, array{0: string, 1: string, 2: string|null}> [status, now, expected reason] */
    public static function consistencyScenarios(): array
    {
        return [
            'before expiry'             => ['Active', '2026-12-01 00:00:00', null],
            'just expired'              => ['Active', '2027-01-01 00:00:05', null],
            'long expired'              => ['Active', '2028-06-01 00:00:00', null],
            'suspended'                 => ['Suspended', '2026-12-01 00:00:00', 'domain_not_active'],
            'locked'                    => ['Locked', '2027-01-05 00:00:00', 'domain_not_active'],
            'redemption'                => ['RedemptionPeriod', '2027-01-05 00:00:00', 'domain_not_active'],
            'restoration'               => ['Restoration', '2027-01-05 00:00:00', 'domain_not_active'],
            'pending delete restorable' => ['Pending Delete Restorable', '2027-01-05 00:00:00', 'domain_not_active'],
            'deleted'                   => ['Deleted', '2026-12-01 00:00:00', 'domain_not_active'],
        ];
    }

    /**
     * The quote (the pure rule), batch creation (stored status) and execution (provider status) must
     * all reach the same verdict for the same status, expiry and time.
     *
     * @dataProvider consistencyScenarios
     */
    public function testQuoteBatchCreationAndExecutionApplyTheSameRule(string $status, string $now, ?string $reason): void
    {
        $domain = $this->domain(1, ['status' => $status]);
        $nowTs  = (new DateTimeImmutable($now, new DateTimeZone('UTC')))->getTimestamp();

        // 1. Quote: the same pure rule the quote endpoint exposes.
        $this->assertSame($reason, \App\Libraries\Renewal\RenewalEligibility::evaluate($status, self::EXPIRY_EPOCH, $nowTs)['reason']);

        // 2. Batch creation on the stored status.
        try {
            $this->service([], $now)->createBatch($this->userId, self::KEY_A, 'KeepInvoice', 1, [$this->line($domain)]);
            $created = null;
            $this->testDb->table('renewal_items')->where('domain_id', $domain)->delete();
            $this->testDb->table('renewal_batches')->where('user_id', $this->userId)->delete();
        } catch (RenewalException $e) {
            $created = $e->errorCode();
        }

        $this->assertSame($reason, $created);

        // 3. Execution on the provider's fresh status (the stored row is made valid so a batch exists).
        $this->testDb->table('domains')->where('id', $domain)->update(['status' => 'Active']);
        $batch = $this->service([], self::NOW)->createBatch($this->userId, self::KEY_A, 'KeepInvoice', 1, [$this->line($domain)])['batch_id'];

        $this->getScript[] = $this->details(1, ['currentstatus' => $status]);

        if ($reason === null) {
            $this->postScript[] = $this->accepted();
        }

        $outcome = $this->service([], $now)->processItem($this->itemIds($batch)[0]);

        if ($reason === null) {
            $this->assertSame('succeeded', $outcome->state);
            $this->assertCount(1, $this->postCalls);
        } else {
            $this->assertSame(['failed', $reason], $this->outcomePair($outcome));
            $this->assertNothingSent();
        }
    }

    public function testFailedExpiredItemDoesNotTouchOtherItemsOrDuplicateProtection(): void
    {
        $batch = $this->batch([$this->domain(1), $this->domain(2)]);
        $items = $this->itemIds($batch);

        $this->getScript[]  = $this->details(1);
        $this->postScript[] = ['status' => 200, 'body' => '{"status":"ERROR","message":"Renewal not allowed"}'];
        $this->getScript[]  = $this->details(2);
        $this->postScript[] = $this->accepted();

        $later = $this->service([], self::AFTER_EXPIRY);
        $this->assertSame(['failed', 'provider_rejected'], $this->outcomePair($later->processItem($items[0])));
        $this->assertSame('succeeded', $later->processItem($items[1])->state);
        $this->assertCount(2, $this->postCalls);

        // Each item was sent once and is never resubmitted.
        $first  = $later->processItem($items[0]);
        $second = $later->processItem($items[1]);
        $this->assertSame([true, 'failed', true, 'succeeded'], [$first->skipped, $first->state, $second->skipped, $second->state]);
        $this->assertCount(2, $this->postCalls);
    }

    public function testProviderUnavailableDuringVerificationFailsWithoutSending(): void
    {
        $batch = $this->batch([$this->domain(1)]);

        $this->getScript[] = new RuntimeException('connection refused');

        $this->assertSame(['failed', 'provider_unavailable'], $this->outcomePair($this->service()->processItem($this->itemIds($batch)[0])));
        $this->assertNothingSent();
    }

    // ---- fresh status verification (Step 2B, Task A) ---------------------------------------

    public function testActiveStatusIsRequiredAndAllowsTheRenewal(): void
    {
        $batch = $this->batch([$this->domain(1)]);

        $this->getScript[]  = $this->details(1, ['currentstatus' => 'Active']);
        $this->postScript[] = $this->accepted();

        $this->assertSame(['succeeded', null], $this->outcomePair($this->service()->processItem($this->itemIds($batch)[0])));
        $this->assertCount(1, $this->postCalls);
    }

    /** @return array<string, array{0: mixed, 1: string}> */
    public static function badStatuses(): array
    {
        return [
            'suspended'      => ['Suspended', 'domain_not_active'],
            'deleted'        => ['Deleted', 'domain_not_active'],
            'inactive'       => ['InActive', 'domain_not_active'],
            'pending delete' => ['PendingDeleteRestorable', 'domain_not_active'],
            'locked'         => ['Locked', 'domain_not_active'],
            'redemption'     => ['RedemptionPeriod', 'domain_not_active'],
            'restoration'    => ['Restoration', 'domain_not_active'],
            'lowercase'      => ['active', 'domain_not_active'],
            'padded'         => ['Active ', 'domain_not_active'],
            'empty string'   => ['', 'domain_not_active'],
            'missing'        => [null, 'status_unverified'],
            'number'         => [1, 'status_unverified'],
            'boolean'        => [true, 'status_unverified'],
            'array'          => [['Active'], 'status_unverified'],
        ];
    }

    /** @dataProvider badStatuses */
    public function testAnythingButExactActiveFailsClosedWithoutSending(mixed $status, string $code): void
    {
        $batch = $this->batch([$this->domain(1)]);

        $this->getScript[] = $this->details(1, ['currentstatus' => $status]);

        $this->assertSame(['failed', $code], $this->outcomePair($this->service()->processItem($this->itemIds($batch)[0])));
        $this->assertNothingSent();
    }

    public function testMissingStatusKeyEntirelyFailsClosed(): void
    {
        $batch = $this->batch([$this->domain(1)]);
        $body  = json_decode($this->details(1)['body'], true);
        unset($body['currentstatus']);

        $this->getScript[] = ['status' => 200, 'body' => (string) json_encode($body)];

        $this->assertSame(['failed', 'status_unverified'], $this->outcomePair($this->service()->processItem($this->itemIds($batch)[0])));
        $this->assertNothingSent();
    }

    public function testFreshEndtimeIsParsedAsEpochSecondsAndMustEqualTheSnapshot(): void
    {
        $batch = $this->batch([$this->domain(1, ['expires_at' => '2026-11-12 11:22:25'])]);

        // Verified shape from the real account: a numeric epoch (seconds), here as a JSON integer.
        $this->getScript[]  = $this->details(1, ['endtime' => 1794482545]);
        $this->postScript[] = $this->accepted();

        $this->assertSame('succeeded', $this->service()->processItem($this->itemIds($batch)[0])->state);
        $this->assertSame(1794482545, $this->postCalls[0][1]['exp-date']);
    }

    /** @return array<string, array{0: mixed}> */
    public static function badEndtimes(): array
    {
        return [
            'milliseconds' => [self::EXPIRY_EPOCH * 1000],
            'float string' => ['1798761600.5'],
            'too small'    => ['12345'],
            'far future'   => ['99999999999'],
            'array'        => [[self::EXPIRY_EPOCH]],
            'boolean'      => [true],
            'date text'    => ['2027-01-01'],
        ];
    }

    /** @dataProvider badEndtimes */
    public function testMalformedEndtimeIsRejectedWithoutSending(mixed $endtime): void
    {
        $batch = $this->batch([$this->domain(1)]);

        $this->getScript[] = $this->details(1, ['endtime' => $endtime]);

        $this->assertSame(['failed', 'expiry_unverified'], $this->outcomePair($this->service()->processItem($this->itemIds($batch)[0])));
        $this->assertNothingSent();
    }

    public function testStatusIsCheckedBeforeAnythingElse(): void
    {
        $batch = $this->batch([$this->domain(1)]);

        $this->getScript[] = $this->details(1, ['currentstatus' => 'Suspended', 'orderid' => '1', 'customerid' => '2']);

        $this->assertSame(['failed', 'domain_not_active'], $this->outcomePair($this->service()->processItem($this->itemIds($batch)[0])));
        $this->assertNothingSent();
    }

    // ---- fresh auto-renew (Step 2B, Task B) ----------------------------------------------------

    /** @return array<string, array{0: mixed, 1: string}> */
    public static function acceptedAutoRenew(): array
    {
        return [
            'json true'    => [true, 'true'],
            'json false'   => [false, 'false'],
            'string true'  => ['true', 'true'],
            'string false' => ['false', 'false'],
        ];
    }

    /** @dataProvider acceptedAutoRenew */
    public function testFreshProviderAutoRenewIsPassedThroughUnchanged(mixed $recurring, string $sent): void
    {
        // The cached local value deliberately disagrees with the provider in both directions.
        foreach ([0, 1] as $local) {
            $this->postCalls = [];
            $batch           = $this->batch([$this->domain(1 + $local, ['auto_renew' => $local])], 'KeepInvoice', 1, 'idem-key-local-' . $local . '-aaaa');

            $this->getScript[]  = $this->details(1 + $local, ['recurring' => $recurring]);
            $this->postScript[] = $this->accepted();

            $this->assertSame('succeeded', $this->service()->processItem($this->itemIds($batch)[0])->state);
            $this->assertSame($sent, $this->postCalls[0][1]['auto-renew'], 'The provider value, not local auto_renew=' . $local . ', must be sent.');
        }
    }

    public function testNullLocalAutoRenewDoesNotBlockWhenTheProviderValueIsKnown(): void
    {
        $batch = $this->batch([$this->domain(1, ['auto_renew' => null])]);

        $this->getScript[]  = $this->details(1, ['recurring' => false]);
        $this->postScript[] = $this->accepted();

        $this->assertSame('succeeded', $this->service()->processItem($this->itemIds($batch)[0])->state);
        $this->assertSame('false', $this->postCalls[0][1]['auto-renew']);
    }

    /** @return array<string, array{0: mixed}> */
    public static function badAutoRenew(): array
    {
        return [
            'missing'      => [null],
            'integer one'  => [1],
            'integer zero' => [0],
            'yes'          => ['yes'],
            'capital True' => ['True'],
            'empty string' => [''],
            'zero string'  => ['0'],
            'array'        => [[]],
            'padded'       => [' true'],
        ];
    }

    /** @dataProvider badAutoRenew */
    public function testMissingOrUnrecognisedAutoRenewFailsClosedWithoutSending(mixed $recurring): void
    {
        $batch = $this->batch([$this->domain(1, ['auto_renew' => 1])]);

        $this->getScript[] = $this->details(1, ['recurring' => $recurring]);

        $this->assertSame(['failed', 'auto_renew_unknown'], $this->outcomePair($this->service()->processItem($this->itemIds($batch)[0])));
        $this->assertNothingSent();
    }

    public function testMissingRecurringKeyEntirelyFailsClosed(): void
    {
        $batch = $this->batch([$this->domain(1)]);
        $body  = json_decode($this->details(1)['body'], true);
        unset($body['recurring']);

        $this->getScript[] = ['status' => 200, 'body' => (string) json_encode($body)];

        $this->assertSame(['failed', 'auto_renew_unknown'], $this->outcomePair($this->service()->processItem($this->itemIds($batch)[0])));
        $this->assertNothingSent();
    }

    public function testProcessingNeverWritesTheLocalAutoRenewPreference(): void
    {
        $domainId = $this->domain(1, ['auto_renew' => 1]);
        $batch    = $this->batch([$domainId]);

        $this->getScript[]  = $this->details(1, ['recurring' => false]);
        $this->postScript[] = $this->accepted();
        $this->service()->processItem($this->itemIds($batch)[0]);

        $this->assertSame(1, (int) $this->testDb->table('domains')->where('id', $domainId)->get()->getRowArray()['auto_renew']);
    }

    // ---- verified action status (Step 2B, Task C) ----------------------------------------------

    public function testShippedStatusConfigurationHasOnlyTheVerifiedSuccessValue(): void
    {
        $config = IsolatedResellerClubConfig::make();

        $this->assertSame(['Success'], $config->renewalSuccessStatuses);
        $this->assertSame([], $config->renewalFailureStatuses, 'No failure status is verified; none may be guessed.');
    }

    /** @return array<string, array{0: array<string, mixed>, 1: string, 2: ?string}> */
    public static function actionStatusCases(): array
    {
        return [
            'exact Success'  => [[], 'succeeded', null],
            'unknown status' => [['actionstatus' => 'Processing'], 'uncertain', 'status_unrecognised'],
            'wrong case'     => [['actionstatus' => 'success'], 'uncertain', 'status_unrecognised'],
            'missing status' => [['actionstatus' => null], 'uncertain', 'malformed_response'],
            'provider ERROR' => [['status' => 'ERROR', 'message' => 'x'], 'failed', 'provider_rejected'],
        ];
    }

    /**
     * @dataProvider actionStatusCases
     *
     * @param array<string, mixed> $override
     */
    public function testActionStatusIsClassifiedByExactVerifiedValuesOnly(array $override, string $state, ?string $code): void
    {
        $batch = $this->batch([$this->domain(1)]);

        $this->getScript[]  = $this->details(1);
        $this->postScript[] = $this->accepted($override);

        // The shipped lists: Success only, and no failure status.
        $svc = $this->service(['renewalSuccessStatuses' => ['Success'], 'renewalFailureStatuses' => []]);

        $this->assertSame([$state, $code], $this->outcomePair($svc->processItem($this->itemIds($batch)[0])));
    }

    public function testMalformedTimeoutAndServerErrorAreUncertainAndHttp200IsNotEnough(): void
    {
        $cases = [
            ['status' => 200, 'body' => '<html>'],
            ['status' => 200, 'body' => '{"status":"Success"}'],
            ['status' => 503, 'body' => ''],
        ];

        foreach ($cases as $n => $response) {
            $batch = $this->batch([$this->domain(10 + $n)], 'KeepInvoice', 1, 'idem-key-status-' . $n . '-aaaa');

            $this->getScript[]  = $this->details(10 + $n);
            $this->postScript[] = $response;

            $this->assertSame('uncertain', $this->service()->processItem($this->itemIds($batch)[0])->state, 'case ' . $n);
        }

        $batch              = $this->batch([$this->domain(20)], 'KeepInvoice', 1, 'idem-key-status-timeout');
        $this->getScript[]  = $this->details(20);
        $this->postScript[] = new RuntimeException('cURL error 28');
        $this->assertSame('uncertain', $this->service()->processItem($this->itemIds($batch)[0])->state);
    }

    public function testDomainRowChangingAfterTheBatchWasCreatedIsDetected(): void
    {
        $changed = $this->domain(1);
        $gone    = $this->domain(2);
        $batch   = $this->batch([$changed, $gone]);
        [$i1, $i2] = $this->itemIds($batch);

        $this->testDb->table('domains')->where('id', $changed)->update(['rc_order_id' => '777777']);
        $this->testDb->table('domains')->where('id', $gone)->update(['is_missing' => 1]);

        $svc = $this->service();
        $this->assertSame(['failed', 'order_changed'], $this->outcomePair($svc->processItem($i1)));
        $this->assertSame(['failed', 'domain_missing'], $this->outcomePair($svc->processItem($i2)));
        $this->assertNothingSent();
    }

    // ---- provider outcomes ---------------------------------------------------------------

    public function testDefiniteProviderRejectionFailsTheItem(): void
    {
        $batch = $this->batch([$this->domain(1)]);
        $item  = $this->itemIds($batch)[0];

        $this->getScript[]  = $this->details(1);
        $this->postScript[] = ['status' => 200, 'body' => '{"status":"ERROR","message":"SENTINEL provider text"}'];

        $outcome = $this->service()->processItem($item);

        $this->assertSame(['failed', 'provider_rejected'], $this->outcomePair($outcome));
        $row = $this->item($item);
        $this->assertNull($row['provider_invoice_id']);
        $this->assertNotNull($row['completed_at']);
        $this->assertSame('SENTINEL provider text', $row['provider_message'], 'The provider explanation is kept for the app to show.');
        $this->assertSame('failed', $this->batchRow($batch)['status']);
    }

    public function testMalformedProviderResponseIsUncertainAndNeedsReview(): void
    {
        $batch = $this->batch([$this->domain(1)]);
        $item  = $this->itemIds($batch)[0];

        $this->getScript[]  = $this->details(1);
        $this->postScript[] = ['status' => 200, 'body' => '<html>gateway</html>'];

        $this->assertSame(['uncertain', 'malformed_response'], $this->outcomePair($this->service()->processItem($item)));

        $row = $this->item($item);
        $this->assertNull($row['completed_at']);
        $this->assertNull($row['provider_action_id']);
        $batchRow = $this->batchRow($batch);
        $this->assertSame(['needs_review', null], [$batchRow['status'], $batchRow['finished_at']]);
    }

    public function testUnverifiedStatusVocabularyRecordsUncertainWithTheActionId(): void
    {
        $batch = $this->batch([$this->domain(1)]);
        $item  = $this->itemIds($batch)[0];

        $this->getScript[]  = $this->details(1);
        $this->postScript[] = $this->accepted();

        $outcome = $this->service(['renewalSuccessStatuses' => [], 'renewalFailureStatuses' => []])->processItem($item);

        $this->assertSame(['uncertain', 'status_unrecognised'], $this->outcomePair($outcome));
        $this->assertSame('111222', $this->item($item)['provider_action_id']);
    }

    public function testTimeoutIsUncertainAndIsNeverSubmittedAgain(): void
    {
        $batch = $this->batch([$this->domain(1)]);
        $item  = $this->itemIds($batch)[0];

        $this->getScript[]  = $this->details(1);
        $this->postScript[] = new RuntimeException('cURL error 28: timed out');
        $this->postScript[] = $this->accepted(); // must never be consumed

        $svc = $this->service();
        $this->assertSame(['uncertain', 'network_error'], $this->outcomePair($svc->processItem($item)));
        $this->assertCount(1, $this->postCalls);

        // Calling again, directly or through the batch, must not send anything.
        $again = $svc->processItem($item);
        $this->assertTrue($again->skipped);
        $this->assertSame('uncertain', $again->state);

        $result = $svc->processBatch($batch);
        $this->assertSame([], $result['items']);
        $this->assertSame('needs_review', $result['batch_status']);
        $this->assertCount(1, $this->postCalls, 'An uncertain request is never resubmitted.');
        $this->assertCount(1, $this->getCalls);
    }

    // ---- duplicate and concurrent submission ------------------------------------------------

    public function testProcessingTheSameItemTwiceSendsOnlyOneRequest(): void
    {
        $batch = $this->batch([$this->domain(1)]);
        $item  = $this->itemIds($batch)[0];

        $this->getScript[]  = $this->details(1);
        $this->postScript[] = $this->accepted();

        $svc   = $this->service();
        $first = $svc->processItem($item);
        $again = $svc->processItem($item);

        $this->assertSame('succeeded', $first->state);
        $this->assertTrue($again->skipped);
        $this->assertSame('succeeded', $again->state);
        $this->assertCount(1, $this->postCalls);
    }

    public function testASecondRequestArrivingWhileTheFirstIsInFlightIsSkipped(): void
    {
        $batch = $this->batch([$this->domain(1)]);
        $item  = $this->itemIds($batch)[0];

        $this->getScript[] = $this->details(1);
        $inFlight          = [];

        $this->postScript[] = function () use ($item, &$inFlight): array {
            // The first request is "on the wire": simulate a double tap and another worker now.
            $inFlight['state']     = $this->item($item)['state'];
            $inFlight['trans']     = $this->testDb->transDepth;
            $inFlight['same']      = $this->service()->processItem($item);
            $inFlight['other']     = $this->service()->processBatch($this->batchRowIdOf($item));

            return $this->accepted();
        };

        $outcome = $this->service()->processItem($item);

        $this->assertSame('submitting', $inFlight['state']);
        $this->assertSame(0, $inFlight['trans'], 'No database transaction may be open during the provider request.');
        $this->assertTrue($inFlight['same']->skipped);
        $this->assertSame('submitting', $inFlight['same']->state);
        $this->assertSame([], $inFlight['other']['items']);
        $this->assertSame('processing', $inFlight['other']['batch_status']);
        $this->assertCount(1, $this->postCalls, 'Concurrent requests must not send a second POST.');
        $this->assertSame('succeeded', $outcome->state);
    }

    private function batchRowIdOf(int $itemId): int
    {
        return (int) $this->item($itemId)['batch_id'];
    }

    public function testAnItemClaimedElsewhereIsNeverSent(): void
    {
        $batch = $this->batch([$this->domain(1)]);
        $item  = $this->itemIds($batch)[0];

        $this->testDb->table('renewal_items')->where('id', $item)->update(['state' => 'submitting']);

        $outcome = $this->service()->processItem($item);

        $this->assertTrue($outcome->skipped);
        $this->assertSame('submitting', $outcome->state);
        $this->assertSame([], $this->getCalls);
        $this->assertNothingSent();
    }

    // ---- per-domain independence and batch status ----------------------------------------------

    public function testEachDomainGetsItsOwnResultAndSuccessesSurviveOtherFailures(): void
    {
        $ids   = [$this->domain(1), $this->domain(2), $this->domain(3), $this->domain(4)];
        $batch = $this->batch($ids);
        [$i1, $i2, $i3, $i4] = $this->itemIds($batch);

        $this->getScript[]  = $this->details(1);                                                // d1: succeeds
        $this->postScript[] = $this->accepted(['eaqid' => '1001', 'invoiceid' => '2001']);
        $this->getScript[]  = $this->details(2, ['endtime' => '1700000000']);                  // d2: expiry changed
        $this->getScript[]  = $this->details(3);                                                // d3: rejected
        $this->postScript[] = ['status' => 200, 'body' => '{"status":"ERROR"}'];
        $this->getScript[]  = $this->details(4);                                                // d4: timeout
        $this->postScript[] = new RuntimeException('timed out');

        $result = $this->service()->processBatch($batch);

        $this->assertSame(['succeeded', 'failed', 'failed', 'uncertain'], array_map(fn ($o) => $o->state, $result['items']));
        $this->assertSame('needs_review', $result['batch_status']);

        $this->assertSame(['succeeded', '1001', '2001', null], $this->pick($i1));
        $this->assertSame(['failed', null, null, 'expiry_changed'], $this->pick($i2));
        $this->assertSame(['failed', null, null, 'provider_rejected'], $this->pick($i3));
        $this->assertSame(['uncertain', null, null, 'network_error'], $this->pick($i4));

        $this->assertCount(3, $this->postCalls, 'd2 failed verification and was never sent.');
        $this->assertSame('900001', $this->postCalls[0][1]['order-id']);
        $this->assertSame('900003', $this->postCalls[1][1]['order-id']);
        $this->assertSame('900004', $this->postCalls[2][1]['order-id']);
    }

    /** @return list<mixed> */
    private function pick(int $itemId): array
    {
        $r = $this->item($itemId);

        return [$r['state'], $r['provider_action_id'], $r['provider_invoice_id'], $r['error_code']];
    }

    public function testBatchStatusFollowsTheItemOutcomes(): void
    {
        // success + failure => partial
        $batch = $this->batch([$this->domain(1), $this->domain(2)], 'KeepInvoice', 1, 'idem-key-partial-0001');
        $this->getScript[]  = $this->details(1);
        $this->postScript[] = $this->accepted();
        $this->getScript[]  = $this->details(2, ['customerid' => '999']);

        $result = $this->service()->processBatch($batch);
        $this->assertSame('partial', $result['batch_status']);
        $this->assertSame(self::NOW, $this->batchRow($batch)['finished_at']);

        // all succeed => completed
        $batch2 = $this->batch([$this->domain(3), $this->domain(4)], 'NoInvoice', 1, 'idem-key-complete-001');
        foreach ([3, 4] as $n) {
            $this->getScript[]  = $this->details($n);
            $this->postScript[] = $this->accepted();
        }
        $this->assertSame('completed', $this->service()->processBatch($batch2)['batch_status']);

        // all fail => failed
        $batch3 = $this->batch([$this->domain(5)], 'KeepInvoice', 1, 'idem-key-failed-00001');
        $this->getScript[] = $this->details(5, ['orderid' => '1']);
        $this->assertSame('failed', $this->service()->processBatch($batch3)['batch_status']);
    }

    public function testStoredErrorCodesAreFixedSafeStringsAndOnlyTheProviderMessageIsKeptAsText(): void
    {
        $batch = $this->batch([$this->domain(1), $this->domain(2)]);

        $this->getScript[]  = $this->details(1);
        $this->postScript[] = ['status' => 200, 'body' => '{"status":"ERROR","message":"SENTINEL secret sk-12345"}'];
        $this->getScript[]  = $this->details(2);
        $this->postScript[] = new RuntimeException('https://x/renew.json?api-key=sentinel-api-key-never-store');

        $this->service()->processBatch($batch);

        foreach ($this->testDb->table('renewal_items')->get()->getResultArray() as $row) {
            $this->assertMatchesRegularExpression('/^[a-z0-9_]{1,64}$/', (string) $row['error_code']);
            $this->assertStringNotContainsString('sentinel-api-key', strtolower((string) json_encode($row)), 'Transport error text and credentials are never stored.');
            $this->assertStringNotContainsString('renew.json', (string) json_encode($row));
        }
    }

    public function testProcessingAnUnknownItemOrBatchIsRefused(): void
    {
        $this->assertRefused('not_found', fn () => $this->service()->processItem(999999));
        $this->assertRefused('not_found', fn () => $this->service()->processBatch(999999));
        $this->assertNothingSent();
    }

    // ---- executeForUser (the only user-facing entry point; unrouted in Phase 1) ------------------

    /** A one-year .com price list whose taxed total equals the line() snapshot (1419.00 + 18% = 1674.42). */
    private function priceList(string $price = '1419.00'): array
    {
        return ['status' => 200, 'body' => (string) json_encode(['domcno' => ['renewdomain' => ['1' => $price]]])];
    }

    /** @param array<string, mixed> $overrides */
    private function balanceBody(array $overrides = []): array
    {
        return ['status' => 200, 'body' => (string) json_encode($overrides + [
            'sellingcurrencybalance'    => '6244.40',
            'sellingcurrencysymbol'     => 'USD',
            'accountingcurrencybalance' => '5000.0000',
            'accountingcurrencysymbol'  => 'INR',
        ])];
    }

    /** A domain whose name maps to the quoted .com price list. */
    private function comDomain(int $n): int
    {
        return $this->domain($n, ['domain_name' => "d{$n}.com"]);
    }

    public function testExecuteForUserRenewsExactlyOneItemAfterAFullPreflight(): void
    {
        $batch = $this->batch([$this->comDomain(1), $this->comDomain(2)]);

        $this->getScript    = [$this->priceList(), $this->balanceBody(), $this->details(1)];
        $this->postScript[] = $this->accepted();

        $result = $this->service(['renewalDebitVerified' => true])->executeForUser($this->userId, $batch);

        $this->assertCount(1, $result['items']);
        $this->assertSame('succeeded', $result['items'][0]->state);
        $this->assertSame('processing', $result['batch_status'], 'The second item is untouched, so the batch is still processing.');
        $this->assertSame('1674.42', $result['preflight']['estimated_total']);

        [$pending, $done] = [$this->item($this->itemIds($batch)[1]), $this->item($this->itemIds($batch)[0])];
        $this->assertSame('pending', $pending['state'], 'Exactly ONE item per request may be processed.');
        $this->assertSame('succeeded', $done['state']);

        $this->assertCount(1, $this->postCalls);
        $this->assertSame('900001', $this->postCalls[0][1]['order-id']);
    }

    public function testExecuteForUserRefusesWhileExecutionIsDisabledBeforeAnyProviderCall(): void
    {
        $batch = $this->batch([$this->comDomain(1)]);

        $this->assertRefused('execution_disabled', fn () => $this->service(['renewalExecutionEnabled' => false, 'renewalDebitVerified' => true])->executeForUser($this->userId, $batch));
        $this->assertSame([], $this->getCalls);
        $this->assertNothingSent();
    }

    public function testExecuteForUserBlocksWhileTheDebitTreatmentIsUnverified(): void
    {
        // renewalDebitVerified defaults to false exactly as shipped.
        $batch = $this->batch([$this->comDomain(1)]);

        $this->assertRefused('balance_currency_unverified', fn () => $this->service()->executeForUser($this->userId, $batch));
        $this->assertSame('pending', $this->item($this->itemIds($batch)[0])['state']);
        $this->assertSame([], $this->getCalls);
        $this->assertNothingSent();
    }

    public function testExecuteForUserTreatsAForeignBatchAsMissing(): void
    {
        $batch = $this->batch([$this->comDomain(1)]);
        $other = $this->insertUser(['email' => 'other@example.test']);

        $this->assertRefused('not_found', fn () => $this->service(['renewalDebitVerified' => true])->executeForUser($other, $batch));
        $this->assertRefused('not_found', fn () => $this->service(['renewalDebitVerified' => true])->executeForUser($this->userId, 999999));

        $this->assertSame('pending', $this->item($this->itemIds($batch)[0])['state']);
        $this->assertSame([], $this->getCalls);
        $this->assertNothingSent();
    }

    public function testExecuteForUserRejectsMoreThanOneItemPerRequest(): void
    {
        $batch = $this->batch([$this->comDomain(1), $this->comDomain(2)]);

        $this->assertRefused('execution_limit_exceeded', fn () => $this->service(['renewalDebitVerified' => true])->executeForUser($this->userId, $batch, 2));
        $this->assertSame([], $this->getCalls);
        $this->assertNothingSent();
    }

    public function testExecuteForUserRefusesAnExpiredBatchWithEveryItemLeftPending(): void
    {
        $batch = $this->batch([$this->comDomain(1)]); // created at NOW by the NOW-clocked service

        $late = $this->service(['renewalDebitVerified' => true], '2026-11-09 10:15:01'); // 901 s later

        $this->assertRefused('batch_expired', fn () => $late->executeForUser($this->userId, $batch));
        $this->assertSame('pending', $this->item($this->itemIds($batch)[0])['state']);
        $this->assertSame([], $this->getCalls);
        $this->assertNothingSent();
    }

    public function testExecuteForUserPreflightFailureLeavesTheItemPendingAndSendsNothing(): void
    {
        $batch           = $this->batch([$this->comDomain(1)]);
        $this->getScript = [$this->priceList(), $this->balanceBody(['accountingcurrencysymbol' => 'USD'])];

        $this->assertRefused('balance_currency_mismatch', fn () => $this->service(['renewalDebitVerified' => true])->executeForUser($this->userId, $batch));

        $this->assertSame('pending', $this->item($this->itemIds($batch)[0])['state']);
        $this->assertSame('pending', $this->batchRow($batch)['status']);
        $this->assertNothingSent();
    }

    public function testExecuteForUserNeverResubmitsItemsThatAreNotPending(): void
    {
        $batch = $this->batch([$this->comDomain(1)]);
        $this->testDb->table('renewal_items')->where('batch_id', $batch)->update(['state' => 'uncertain']);
        (new \App\Libraries\Renewal\RenewalBatchRefresher($this->testDb, static fn (): DateTimeImmutable => new DateTimeImmutable(self::NOW, new DateTimeZone('UTC'))))->refresh($batch);

        $result = $this->service(['renewalDebitVerified' => true])->executeForUser($this->userId, $batch);

        $this->assertSame([], $result['items'], 'An uncertain item must never be picked up again.');
        $this->assertNull($result['preflight']['item_id']);
        $this->assertSame('uncertain', $this->item($this->itemIds($batch)[0])['state']);
        $this->assertSame([], $this->getCalls, 'With nothing pending there is no provider traffic at all.');
        $this->assertNothingSent();
    }

    // ---- stale, never-submitted items release their domain slot ---------------------------------

    private const STALE_NOW = '2026-11-09 10:15:01'; // 901 s after NOW: past the 900 s policy
    private const EDGE_NOW  = '2026-11-09 10:15:00'; // exactly 900 s: NOT past it

    /** @return array<string, array{0: array<string, mixed>}> */
    public static function neverReleasedProvider(): array
    {
        return [
            'provider action id'      => [['provider_action_id' => '777']],
            'provider invoice id'     => [['provider_invoice_id' => 'INV-1']],
            'submitting'              => [['state' => 'submitting']],
            'uncertain'               => [['state' => 'uncertain', 'provider_action_id' => '777']],
            'uncertain, no action id' => [['state' => 'uncertain']],
            'succeeded'               => [['state' => 'succeeded', 'provider_action_id' => '777']],
            'debit confirmed'         => [['debit_verification_status' => 'confirmed']],
            'debit disputed'          => [['debit_verification_status' => 'disputed']],
            'debit not found'         => [['debit_verification_status' => 'not_found']],
            'debit transaction id'    => [['debit_transaction_id' => 'T-1']],
            'debit verified at'       => [['debit_verified_at' => '2026-11-09 10:05:00']],
            'debit note'              => [['debit_verification_note' => 'seen']],
        ];
    }

    public function testAFreshPendingBatchIsNotReleasedAndStillBlocksANewOne(): void
    {
        $d     = $this->domain(1);
        $batch = $this->batch([$d]);

        $this->assertSame(0, $this->service()->releaseStaleUnsubmitted([$d]));
        $this->assertSame('pending', $this->item($this->itemIds($batch)[0])['state']);
        $this->assertRefused('duplicate_cycle', fn () => $this->service()->createBatch($this->userId, 'idem-key-second-aaaaaa', 'KeepInvoice', 1, [$this->line($d)]));
        $this->assertSame([$d => $batch], $this->service()->findActiveBatchIds($this->userId, [$d]));
    }

    public function testTheAgeBoundaryIsTheExistingPolicyExactly(): void
    {
        $d = $this->domain(1);
        $this->batch([$d]);

        $this->assertSame(0, $this->service([], self::EDGE_NOW)->releaseStaleUnsubmitted([$d]), '900 s old is not past the limit.');
        $this->assertSame(1, $this->service([], self::STALE_NOW)->releaseStaleUnsubmitted([$d]), '901 s old is.');
        $this->assertSame(0, $this->service([], self::STALE_NOW)->releaseStaleUnsubmitted([$d]), 'A second release finds nothing: idempotent.');

        // The limit is read from the same configuration value the preflight uses.
        $d2 = $this->domain(2);
        $this->batch([$d2], 'KeepInvoice', 1, 'idem-key-other-aaaaaaa');
        $this->assertSame(1, $this->service(['renewalBatchMaxAgeSeconds' => 60], '2026-11-09 10:01:01')->releaseStaleUnsubmitted([$d2]));
    }

    public function testAnExpiredNeverSubmittedItemIsFailedWithAReasonAndKeptForAudit(): void
    {
        $d     = $this->domain(1);
        $batch = $this->batch([$d]);
        $item  = $this->itemIds($batch)[0];

        $this->assertSame(1, $this->service([], self::STALE_NOW)->releaseStaleUnsubmitted([$d]));

        $row = $this->item($item);
        $this->assertSame('failed', $row['state']);
        $this->assertSame('stale_unsubmitted', $row['error_code']);
        $this->assertNotNull($row['completed_at']);
        $this->assertNull($row['active_expiry_at'], 'The duplicate-protection slot is released.');
        $this->assertNull($row['provider_action_id']);
        $this->assertSame('unverified', $row['debit_verification_status']);

        $batchRow = $this->batchRow($batch);
        $this->assertSame('failed', $batchRow['status']);
        $this->assertNotNull($batchRow['finished_at']);
        $this->assertSame(0, (int) $this->testDb->table('renewal_debit_verifications')->countAllResults(), 'No verification record is invented.');
        $this->assertNothingSent();
    }

    public function testANewBatchCanBeCreatedOnlyAfterTheOldSlotIsReleased(): void
    {
        $d   = $this->domain(1);
        $old = $this->batch([$d]);

        $new = $this->service([], self::STALE_NOW)->createBatch($this->userId, 'idem-key-second-aaaaaa', 'KeepInvoice', 1, [$this->line($d)]);

        $this->assertFalse($new['replayed']);
        $this->assertNotSame($old, $new['batch_id']);
        $this->assertSame('failed', $this->item($this->itemIds($old)[0])['state']);
        $this->assertSame('pending', $this->item($this->itemIds($new['batch_id'])[0])['state']);
        $this->assertSame(2, (int) $this->testDb->table('renewal_batches')->countAllResults());

        // Exactly one live item per cycle remains.
        $this->assertRefused('duplicate_cycle', fn () => $this->service([], self::STALE_NOW)->createBatch($this->userId, 'idem-key-third-aaaaaaa', 'KeepInvoice', 1, [$this->line($d)]));
    }

    /**
     * @dataProvider neverReleasedProvider
     *
     * @param array<string, mixed> $set
     */
    public function testItemsWithAnyEvidenceOrNonPendingStateAreNeverReleased(array $set): void
    {
        $d     = $this->domain(1);
        $batch = $this->batch([$d]);
        $item  = $this->itemIds($batch)[0];
        $this->testDb->table('renewal_items')->where('id', $item)->update($set);
        $before = $this->item($item);

        $this->assertSame(0, $this->service([], self::STALE_NOW)->releaseStaleUnsubmitted([$d]));
        $this->assertSame($before, $this->item($item), 'The item must not change at all.');
        $this->assertRefused('duplicate_cycle', fn () => $this->service([], self::STALE_NOW)->createBatch($this->userId, 'idem-key-second-aaaaaa', 'KeepInvoice', 1, [$this->line($d)]));
        $this->assertSame(1, (int) $this->testDb->table('renewal_batches')->countAllResults());
    }

    public function testAnItemWithAVerificationRecordIsNeverReleased(): void
    {
        $d    = $this->domain(1);
        $item = $this->itemIds($this->batch([$d]))[0];
        $this->testDb->table('renewal_debit_verifications')->insert([
            'renewal_item_id' => $item, 'user_id' => $this->userId, 'previous_status' => 'unverified',
            'new_status' => 'unverified', 'created_at' => self::NOW,
        ]);

        $this->assertSame(0, $this->service([], self::STALE_NOW)->releaseStaleUnsubmitted([$d]));
        $this->assertSame('pending', $this->item($item)['state']);
    }

    public function testReleaseOnlyTouchesTheRequestedDomains(): void
    {
        $d1 = $this->domain(1);
        $d2 = $this->domain(2);
        $b  = $this->batch([$d1, $d2]);

        $this->assertSame(1, $this->service([], self::STALE_NOW)->releaseStaleUnsubmitted([$d1]));

        [$i1, $i2] = $this->itemIds($b);
        $this->assertSame('failed', $this->item($i1)['state']);
        $this->assertSame('pending', $this->item($i2)['state']);
        $this->assertNotSame('failed', $this->batchRow($b)['status'], 'One pending item remains, so the batch is not failed.');
    }

    public function testAClaimedItemCannotBeReleasedAndAReleasedItemCannotBeClaimed(): void
    {
        $d    = $this->domain(1);
        $item = $this->itemIds($this->batch([$d]))[0];

        // Claimed first (pending -> submitting): the release's guarded UPDATE matches nothing.
        $this->testDb->table('renewal_items')->where('id', $item)->update(['state' => 'submitting']);
        $this->assertSame(0, $this->service([], self::STALE_NOW)->releaseStaleUnsubmitted([$d]));
        $this->assertSame('submitting', $this->item($item)['state']);

        // Released first: the claim's conditional UPDATE (state = 'pending') matches nothing.
        $this->testDb->table('renewal_items')->where('id', $item)->update(['state' => 'pending']);
        $this->assertSame(1, $this->service([], self::STALE_NOW)->releaseStaleUnsubmitted([$d]));
        $this->testDb->table('renewal_items')->where('id', $item)->where('state', 'pending')->update(['state' => 'submitting']);
        $this->assertSame(0, $this->testDb->affectedRows());
        $this->assertSame('failed', $this->item($item)['state']);
    }

    public function testAnExpiredBatchStillCannotBeExecuted(): void
    {
        $d     = $this->domain(1);
        $batch = $this->batch([$d]);

        $this->assertRefused('batch_expired', fn () => $this->service(['renewalDebitVerified' => true], self::STALE_NOW)->executeForUser($this->userId, $batch));
        $this->assertSame('pending', $this->item($this->itemIds($batch)[0])['state'], 'Execution itself never changes the item.');
        $this->assertNothingSent();
    }

    public function testActiveLookupIsScopedToTheUserAndToTheCurrentExpiryCycle(): void
    {
        $d     = $this->domain(1);
        $other = $this->insertUser(['email' => 'other@example.test']);
        $batch = $this->batch([$d]);

        $this->assertSame([$d => $batch], $this->service()->findActiveBatchIds($this->userId, [$d]));
        $this->assertSame([], $this->service()->findActiveBatchIds($other, [$d]), 'Never another user\'s batch.');
        $this->assertSame([], $this->service([], self::STALE_NOW)->findActiveBatchIds($this->userId, [$d]), 'A releasable stale item is not active.');

        // A renewed domain moves to a new expiry: the old cycle is no longer its active batch.
        $this->testDb->table('domains')->where('id', $d)->update(['expires_at' => '2028-01-01 00:00:00']);
        $this->assertSame([], $this->service()->findActiveBatchIds($this->userId, [$d]));
        $this->assertSame([], $this->service()->findActiveBatchIds($this->userId, []));
    }

    public function testActiveLookupReportsSubmittedAndUncertainBatchesEvenWhenOld(): void
    {
        foreach (['uncertain', 'succeeded', 'submitting'] as $n => $state) {
            $d    = $this->domain($n + 1);
            $b    = $this->batch([$d], 'KeepInvoice', 1, 'idem-key-state-' . $n . '-aaaaaa');
            $item = $this->itemIds($b)[0];
            $this->testDb->table('renewal_items')->where('id', $item)->update(['state' => $state, 'provider_action_id' => '5' . $n]);

            $this->assertSame([$d => $b], $this->service([], self::STALE_NOW)->findActiveBatchIds($this->userId, [$d]), $state);
        }
    }

    public function testFailedItemsAreNotActive(): void
    {
        $d    = $this->domain(1);
        $item = $this->itemIds($this->batch([$d]))[0];
        $this->testDb->table('renewal_items')->where('id', $item)->update(['state' => 'failed']);

        $this->assertSame([], $this->service()->findActiveBatchIds($this->userId, [$d]));
    }

    // ---- helpers ----------------------------------------------------------------------------------

    /** @return array{0: string, 1: ?string} */
    private function outcomePair(\App\Libraries\Renewal\RenewalItemOutcome $o): array
    {
        return [$o->state, $o->errorCode];
    }

    private function assertRefused(string $code, callable $fn): void
    {
        try {
            $fn();
            $this->fail("Expected the service to refuse with {$code}.");
        } catch (RenewalException $e) {
            $this->assertSame($code, $e->errorCode());
        }
    }
}
