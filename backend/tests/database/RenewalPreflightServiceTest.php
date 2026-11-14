<?php

declare(strict_types=1);

use App\Libraries\Pricing\RenewalQuoteService;
use App\Libraries\Renewal\RenewalException;
use App\Libraries\Renewal\RenewalExecutionService;
use App\Libraries\Renewal\RenewalPreflightService;
use App\Libraries\ResellerClub\ResellerClubClient;
use CodeIgniter\Cache\CacheInterface;
use CodeIgniter\Test\CIUnitTestCase;
use Config\ResellerClub as ResellerClubConfig;
use Tests\Support\Libraries\IsolatedResellerClubConfig;
use Tests\Support\Database\UsesEnrichmentSchema;
use Tests\Support\Database\UsesIsolatedAuthDatabase;
use Tests\Support\Database\UsesRenewalSchema;

/**
 * RenewalPreflightService against FAKE transports and the isolated *_test
 * database. The POST transport fails the test if it is ever touched: the
 * preflight must be GET-only. The execution switch stays FALSE throughout —
 * the preflight must not need it — and renewalDebitVerified is turned on only
 * on private in-memory config objects, never the shared application config.
 *
 * Standing assertions in every refusal test: the items are still `pending`
 * and no POST was sent, so a preflight failure can never have spent money.
 *
 * @internal
 */
final class RenewalPreflightServiceTest extends CIUnitTestCase
{
    use UsesIsolatedAuthDatabase {
        tearDownAfterClass as private tearDownAuthSchema;
    }
    use UsesEnrichmentSchema;
    use UsesRenewalSchema;

    private const EXPIRY_AT = '2027-01-01 00:00:00';
    private const NOW       = '2026-11-09 10:00:00';
    private const KEY_A     = 'idem-key-aaaaaaaaaaaa';

    /** @var list<array{status: int, body: string}|Throwable> */
    private array $getScript = [];

    /** @var list<array{0: string, 1: array<string, scalar>}> */
    private array $getCalls = [];

    /** @var list<array{0: string, 1: array<string, scalar>}> */
    private array $postCalls = [];

    private int $userId = 0;

    protected function setUp(): void
    {
        parent::setUp();

        self::emptyRenewalTables();

        $this->setUpIsolatedAuthDatabase();
        $this->setUpEnrichmentSchema();
        $this->setUpRenewalSchema();

        $this->getScript = [];
        $this->getCalls  = [];
        $this->postCalls = [];
        $this->userId    = $this->insertUser();
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
        $c                       = IsolatedResellerClubConfig::make();
        $c->baseUrl              = 'https://test.httpapi.example/api';
        $c->authUserId           = '123456';
        $c->apiKey               = 'sentinel-api-key-never-store';
        $c->maxAttempts          = 1;
        $c->renewalDebitVerified = true; // in-memory only; the shipped default stays false

        foreach ($overrides as $k => $v) {
            $c->{$k} = $v;
        }

        return $c;
    }

    private function client(ResellerClubConfig $config): ResellerClubClient
    {
        return new ResellerClubClient(
            $config,
            function (string $url, array $query): array {
                $this->getCalls[] = [$url, $query];
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

                throw new RuntimeException('the preflight must never POST');
            },
        );
    }

    /** @param array<string, mixed> $cfg */
    private function preflight(array $cfg = [], string $now = self::NOW): RenewalPreflightService
    {
        $config = $this->config($cfg);
        $client = $this->client($config);

        $store = [];
        $cache = $this->createMock(CacheInterface::class);
        $cache->method('get')->willReturnCallback(static fn (string $k) => $store[$k] ?? null);
        $cache->method('save')->willReturn(true);

        return new RenewalPreflightService(
            $client,
            $config,
            new RenewalQuoteService($client, $config, $cache),
            $this->testDb,
            static fn (): DateTimeImmutable => new DateTimeImmutable($now, new DateTimeZone('UTC')),
        );
    }

    /** @param array<string, mixed> $overrides */
    private function domain(int $n = 1, array $overrides = []): int
    {
        return $this->insertEnrichableDomain($overrides + [
            'rc_order_id' => (string) (900000 + $n),
            'domain_name' => "d{$n}.com",
            'expires_at'  => self::EXPIRY_AT,
        ]);
    }

    /**
     * A batch created at NOW with one 1419.00 + 255.42 GST = 1674.42 INR line
     * per domain (created through the real createBatch, no provider call).
     *
     * @param list<int> $domainIds
     */
    private function batch(array $domainIds, string $key = self::KEY_A): int
    {
        $config  = $this->config(['renewalExecutionEnabled' => false]);
        $service = new RenewalExecutionService(
            $this->client($config),
            $config,
            $this->testDb,
            static fn (): DateTimeImmutable => new DateTimeImmutable(self::NOW, new DateTimeZone('UTC')),
        );

        $lines = array_map(static fn (int $d): array => [
            'domain_id' => $d, 'renewal_amount' => '1419.00', 'gst_amount' => '255.42',
            'vat_amount' => '0.00', 'total_amount' => '1674.42', 'currency' => 'INR',
        ], $domainIds);

        return $service->createBatch($this->userId, $key, 'KeepInvoice', 1, $lines)['batch_id'];
    }

    /** The cost-price list answer: .com renews at $price for one year. */
    private function priceList(string $price = '1419.00'): array
    {
        return ['status' => 200, 'body' => (string) json_encode(['domcno' => ['renewdomain' => ['1' => $price]]])];
    }

    /** @param array<string, mixed> $overrides */
    private function balance(array $overrides = []): array
    {
        return ['status' => 200, 'body' => (string) json_encode($overrides + [
            'sellingcurrencybalance'    => '6244.40',
            'sellingcurrencysymbol'     => 'USD',
            'accountingcurrencybalance' => '5000.0000',
            'accountingcurrencysymbol'  => 'INR',
        ])];
    }

    /** @return list<string> */
    private function itemStates(int $batchId): array
    {
        return array_map('strval', array_column(
            $this->testDb->table('renewal_items')->select('state')->where('batch_id', $batchId)->orderBy('id')->get()->getResultArray(),
            'state',
        ));
    }

    private function assertAllPendingAndNothingSent(int $batchId): void
    {
        $this->assertSame([], array_diff($this->itemStates($batchId), ['pending']), 'Every item must still be pending.');
        $this->assertSame([], $this->postCalls, 'The preflight must never send a POST.');
    }

    private function assertRefused(int $batchId, string $code, callable $run): void
    {
        try {
            $run();
            $this->fail('Expected RenewalException ' . $code);
        } catch (RenewalException $e) {
            $this->assertSame($code, $e->errorCode());
        }

        $this->assertAllPendingAndNothingSent($batchId);
    }

    // ---- passing checks ------------------------------------------------------------

    public function testPassesWithUnchangedPriceAndSufficientBalance(): void
    {
        $batchId         = $this->batch([$this->domain(1)]);
        $this->getScript = [$this->priceList(), $this->balance()];

        $report = $this->preflight()->check($batchId);

        $this->assertSame('1674.42', $report['estimated_total']);
        $this->assertSame('1674.42', $report['fresh_total']);
        $this->assertFalse($report['price_decreased']);
        $this->assertSame('INR', $report['currency']);
        $this->assertSame('5000.0000', $report['available_balance']);
        $this->assertIsInt($report['item_id']);

        // GET-only: one price fetch, one balance fetch, in that order.
        $this->assertCount(2, $this->getCalls);
        $this->assertStringContainsString('reseller-cost-price', $this->getCalls[0][0]);
        $this->assertStringContainsString('reseller-balance', $this->getCalls[1][0]);
        $this->assertAllPendingAndNothingSent($batchId);
    }

    public function testBalanceExactlyEqualToTheTotalPasses(): void
    {
        $batchId         = $this->batch([$this->domain(1)]);
        $this->getScript = [$this->priceList(), $this->balance(['accountingcurrencybalance' => '1674.4200'])];

        $report = $this->preflight()->check($batchId);

        $this->assertIsInt($report['item_id']);
        $this->assertAllPendingAndNothingSent($batchId);
    }

    public function testLowerFreshPriceProceedsOnTheOriginalSnapshotAndReportsIt(): void
    {
        $batchId         = $this->batch([$this->domain(1)]);
        $this->getScript = [$this->priceList('1000.00'), $this->balance()];

        $report = $this->preflight()->check($batchId);

        $this->assertTrue($report['price_decreased']);
        $this->assertSame('1674.42', $report['estimated_total']); // the confirmed figure is kept
        $this->assertSame('1180.00', $report['fresh_total']);     // 1000.00 + 18% GST
        $this->assertAllPendingAndNothingSent($batchId);
    }

    public function testEachCheckFetchesAFreshPriceAndAFreshBalance(): void
    {
        $batchId         = $this->batch([$this->domain(1)]);
        $this->getScript = [$this->priceList(), $this->balance(), $this->priceList(), $this->balance()];

        $service = $this->preflight();
        $service->check($batchId);
        $service->check($batchId);

        $balanceCalls = array_filter($this->getCalls, static fn (array $c): bool => str_contains($c[0], 'reseller-balance'));
        $priceCalls   = array_filter($this->getCalls, static fn (array $c): bool => str_contains($c[0], 'reseller-cost-price'));

        $this->assertCount(2, $balanceCalls, 'The balance must be fetched fresh on every check.');
        $this->assertCount(2, $priceCalls, 'The price must be fetched fresh (cache bypassed) on every check.');
    }

    // ---- balance refusals ----------------------------------------------------------

    public function testLowAccountingBalanceDoesNotBlock(): void
    {
        $batchId         = $this->batch([$this->domain(1)]);
        $this->getScript = [$this->priceList(), $this->balance(['accountingcurrencybalance' => '1674.4199'])];

        $report = $this->preflight()->check($batchId);

        $this->assertIsInt($report['item_id']);
        $this->assertSame('1674.4199', $report['available_balance']);
        $this->assertAllPendingAndNothingSent($batchId);
    }

    public function testZeroAccountingBalanceDoesNotBlock(): void
    {
        $batchId         = $this->batch([$this->domain(1)]);
        $this->getScript = [$this->priceList(), $this->balance(['accountingcurrencybalance' => '0.0000'])];

        $this->assertIsInt($this->preflight()->check($batchId)['item_id']);
    }

    public function testNegativeAccountingBalanceDoesNotBlock(): void
    {
        $batchId         = $this->batch([$this->domain(1)]);
        $this->getScript = [$this->priceList(), $this->balance(['accountingcurrencybalance' => '-5000.00'])];

        $this->assertIsInt($this->preflight()->check($batchId)['item_id']);
    }

    public function testLowBalanceWithCurrencyMismatchStillBlocks(): void
    {
        $batchId         = $this->batch([$this->domain(1)]);
        $this->getScript = [$this->priceList(), $this->balance(['accountingcurrencybalance' => '1.00', 'accountingcurrencysymbol' => 'USD'])];

        $this->assertRefused($batchId, 'balance_currency_mismatch', fn () => $this->preflight()->check($batchId));
    }

    public function testAccountingCurrencyMismatchBlocks(): void
    {
        $batchId         = $this->batch([$this->domain(1)]);
        $this->getScript = [$this->priceList(), $this->balance(['accountingcurrencysymbol' => 'USD'])];

        $this->assertRefused($batchId, 'balance_currency_mismatch', fn () => $this->preflight()->check($batchId));
    }

    public function testUnrecognisedCurrencySymbolBlocksNotNormalised(): void
    {
        // "Rs." may well be what the live account returns: it must fail closed,
        // never be treated as INR by guesswork.
        $batchId         = $this->batch([$this->domain(1)]);
        $this->getScript = [$this->priceList(), $this->balance(['accountingcurrencysymbol' => 'Rs.'])];

        $this->assertRefused($batchId, 'balance_currency_mismatch', fn () => $this->preflight()->check($batchId));
    }

    public function testMalformedBalanceBlocks(): void
    {
        $batchId         = $this->batch([$this->domain(1)]);
        $this->getScript = [$this->priceList(), $this->balance(['accountingcurrencybalance' => 'abc'])];

        $this->assertRefused($batchId, 'balance_unavailable', fn () => $this->preflight()->check($batchId));
    }

    public function testUnverifiedDebitTreatmentBlocksBeforeAnyProviderCall(): void
    {
        // The SHIPPED default: renewalDebitVerified is false.
        $batchId = $this->batch([$this->domain(1)]);

        $this->assertRefused($batchId, 'balance_currency_unverified', fn () => $this->preflight(['renewalDebitVerified' => false])->check($batchId));
        $this->assertSame([], $this->getCalls, 'No provider call may happen while the debit treatment is unverified.');
    }

    public function testLargeAccountingBalanceNeverAuthorizesWhileDebitIsUnverified(): void
    {
        // accountingcurrencybalance is not verified spendable funds: even a huge
        // value must not authorize a renewal while renewalDebitVerified is false.
        $batchId         = $this->batch([$this->domain(1)]);
        $this->getScript = [$this->priceList(), $this->balance(['accountingcurrencybalance' => '99999999.0000'])];

        $this->assertRefused($batchId, 'balance_currency_unverified', fn () => $this->preflight(['renewalDebitVerified' => false])->check($batchId));
        $this->assertSame([], $this->getCalls, 'The balance must not even be fetched while unverified.');
        $this->assertFalse((IsolatedResellerClubConfig::make())->renewalDebitVerified);
        $this->assertFalse((IsolatedResellerClubConfig::make())->renewalExecutionEnabled);
    }

    public function testSnapshotCurrencyMismatchBlocksBeforeAnyProviderCall(): void
    {
        $batchId = $this->batch([$this->domain(1)]);
        $this->testDb->table('renewal_items')->where('batch_id', $batchId)->update(['currency' => 'USD']);

        $this->assertRefused($batchId, 'balance_currency_mismatch', fn () => $this->preflight()->check($batchId));
        $this->assertSame([], $this->getCalls);
    }

    public function testQuoteCurrencyMismatchBlocksBeforeTheBalanceIsFetched(): void
    {
        // The quote service is configured for USD while INR is the debit currency.
        $batchId         = $this->batch([$this->domain(1)]);
        $this->getScript = [$this->priceList()];

        $this->assertRefused($batchId, 'balance_currency_mismatch', fn () => $this->preflight(['costPriceCurrency' => 'USD'])->check($batchId));
        $this->assertCount(1, $this->getCalls, 'Only the price fetch may have happened.');
    }

    // ---- price refusals ------------------------------------------------------------

    public function testHigherFreshPriceBlocks(): void
    {
        $batchId         = $this->batch([$this->domain(1)]);
        $this->getScript = [$this->priceList('1419.01')];

        $this->assertRefused($batchId, 'price_changed', fn () => $this->preflight()->check($batchId));
    }

    public function testMissingFreshPriceBlocks(): void
    {
        $batchId         = $this->batch([$this->domain(1)]);
        $this->getScript = [['status' => 200, 'body' => (string) json_encode(['dotnet' => ['renewdomain' => ['1' => '999.00']]])]];

        $this->assertRefused($batchId, 'price_unavailable', fn () => $this->preflight()->check($batchId));
    }

    public function testProviderErrorDuringThePriceFetchBlocks(): void
    {
        $batchId         = $this->batch([$this->domain(1)]);
        $this->getScript = [['status' => 200, 'body' => (string) json_encode(['status' => 'ERROR'])]];

        $this->assertRefused($batchId, 'price_unavailable', fn () => $this->preflight()->check($batchId));
    }

    // ---- age, limits, existence ----------------------------------------------------

    public function testBatchWithinTheAgeLimitPasses(): void
    {
        $batchId         = $this->batch([$this->domain(1)]);
        $this->getScript = [$this->priceList(), $this->balance()];

        $report = $this->preflight([], '2026-11-09 10:14:59')->check($batchId); // 899 s old

        $this->assertIsInt($report['item_id']);
    }

    public function testExpiredBatchBlocksBeforeAnyProviderCall(): void
    {
        $batchId = $this->batch([$this->domain(1)]);

        $this->assertRefused($batchId, 'batch_expired', fn () => $this->preflight([], '2026-11-09 10:15:01')->check($batchId)); // 901 s old
        $this->assertSame([], $this->getCalls);
    }

    public function testBatchFromTheFutureBlocks(): void
    {
        $batchId = $this->batch([$this->domain(1)]);

        $this->assertRefused($batchId, 'batch_expired', fn () => $this->preflight([], '2026-11-09 09:59:00')->check($batchId));
        $this->assertSame([], $this->getCalls);
    }

    public function testMoreThanOneItemPerRequestIsRejected(): void
    {
        $batchId = $this->batch([$this->domain(1), $this->domain(2)]);

        $this->assertRefused($batchId, 'execution_limit_exceeded', fn () => $this->preflight()->check($batchId, 2));
        $this->assertSame([], $this->getCalls);
    }

    public function testMissingBatchIsNotFound(): void
    {
        $this->expectException(RenewalException::class);
        $this->expectExceptionMessage('not_found');

        $this->preflight()->check(999999);
    }

    public function testBatchWithNothingPendingMakesNoProviderCallAtAll(): void
    {
        $batchId = $this->batch([$this->domain(1)]);
        $this->testDb->table('renewal_items')->where('batch_id', $batchId)->update(['state' => 'uncertain']);

        $report = $this->preflight()->check($batchId);

        $this->assertNull($report['item_id']);
        $this->assertSame([], $this->getCalls);
        $this->assertSame([], $this->postCalls);
    }

    public function testMultiDomainBatchChecksOnlyTheNextPendingItem(): void
    {
        $batchId         = $this->batch([$this->domain(1), $this->domain(2)]);
        $this->getScript = [$this->priceList(), $this->balance()];

        $report = $this->preflight()->check($batchId);

        $firstItem = (int) $this->testDb->table('renewal_items')->select('id')->where('batch_id', $batchId)->orderBy('id')->get()->getRowArray()['id'];
        $this->assertSame($firstItem, $report['item_id']);
        $this->assertCount(2, $this->getCalls, 'One price and one balance fetch for ONE item only.');
    }

    // ---- Phase 2: an explicitly named item ---------------------------------------

    public function testANamedSecondItemIsCheckedInsteadOfTheNextPendingOne(): void
    {
        $batchId         = $this->batch([$this->domain(1), $this->domain(2)]);
        $this->getScript = [$this->priceList(), $this->balance()];
        $ids             = array_map('intval', array_column($this->testDb->table('renewal_items')->select('id')->where('batch_id', $batchId)->orderBy('id')->get()->getResultArray(), 'id'));

        $report = $this->preflight()->check($batchId, 1, $ids[1]);

        $this->assertSame($ids[1], $report['item_id']);
        $this->assertSame([], $this->postCalls);
    }

    public function testANamedItemThatIsNotPendingOrNotInTheBatchIsRefusedWithoutProviderCalls(): void
    {
        $one = $this->batch([$this->domain(1)]);
        $two = $this->batch([$this->domain(2)], 'idem-key-bbbbbbbbbbbb');
        $id1 = (int) $this->testDb->table('renewal_items')->select('id')->where('batch_id', $one)->get()->getRowArray()['id'];
        $id2 = (int) $this->testDb->table('renewal_items')->select('id')->where('batch_id', $two)->get()->getRowArray()['id'];

        foreach ([['submitting', 'item_not_pending'], ['uncertain', 'item_not_pending'], ['failed', 'item_not_pending'], ['succeeded', 'item_not_pending']] as [$state, $code]) {
            $this->testDb->table('renewal_items')->where('id', $id1)->update(['state' => $state]);

            try {
                $this->preflight()->check($one, 1, $id1);
                $this->fail('Expected ' . $code);
            } catch (RenewalException $e) {
                $this->assertSame($code, $e->errorCode(), $state);
            }
        }

        try {
            $this->preflight()->check($one, 1, $id2);
            $this->fail('Expected not_found');
        } catch (RenewalException $e) {
            $this->assertSame('not_found', $e->errorCode());
        }

        $this->assertSame([], $this->getCalls);
        $this->assertSame([], $this->postCalls);
    }
}
