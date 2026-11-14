<?php

declare(strict_types=1);

use App\Libraries\Renewal\RenewalException;
use App\Libraries\Renewal\RenewalExecutionService;
use App\Libraries\Renewal\RenewalReconciliationService;
use App\Libraries\ResellerClub\ResellerClubClient;
use CodeIgniter\Test\CIUnitTestCase;
use Config\ResellerClub as ResellerClubConfig;
use Tests\Support\Libraries\IsolatedResellerClubConfig;
use Tests\Support\Database\UsesEnrichmentSchema;
use Tests\Support\Database\UsesIsolatedAuthDatabase;
use Tests\Support\Database\UsesRenewalSchema;

/**
 * Read-only renewal reconciliation against FAKE provider transports and the
 * isolated *_test database. The GET transport serves scripted answers per
 * endpoint path; the POST transport records any call and the tests assert there
 * never is one. The execution switch is OFF for every service built here: the
 * reconciliation must not need it.
 *
 * NOTE: the action record field names `actiontype` and `entityid` (and the
 * response field `eaqid`) are verified against a real archived-actions response;
 * the fixtures use them, and a record without them is asserted to be unresolved.
 * The action id REQUEST parameter is `eaq-id`; the fake GET transport records
 * every query so this can be asserted.
 *
 * @internal
 */
final class RenewalReconciliationServiceTest extends CIUnitTestCase
{
    use UsesIsolatedAuthDatabase {
        tearDownAfterClass as private tearDownAuthSchema;
    }
    use UsesEnrichmentSchema;
    use UsesRenewalSchema;

    private const EXPIRY_AT = '2027-01-01 00:00:00';
    private const NOW       = '2026-11-09 10:00:00';
    private const OLD       = '2026-11-09 09:00:00';  // 1 h before NOW: stuck
    private const YOUNG     = '2026-11-09 09:55:00';  // 5 min before NOW: maybe in flight
    private const KEY_A     = 'idem-key-aaaaaaaaaaaa';
    private const ACTION    = '111222';

    /** @var array<string, list<array{status: int, body: string}|Throwable>> path => answers */
    private array $script = [];

    /** @var list<string> */
    private array $getPaths = [];

    /** @var list<array{0: string, 1: array<string, mixed>}> path, query of every provider GET */
    private array $getQueries = [];

    /** @var list<string> */
    private array $postCalls = [];

    private int $userId = 0;

    protected function setUp(): void
    {
        parent::setUp();

        self::emptyRenewalTables();

        $this->setUpIsolatedAuthDatabase();
        $this->setUpEnrichmentSchema();
        $this->setUpRenewalSchema();

        $this->script    = [];
        $this->getPaths   = [];
        $this->getQueries = [];
        $this->postCalls  = [];
        $this->userId   = $this->insertUser();
    }

    protected function tearDown(): void
    {
        $this->assertSame([], $this->postCalls, 'Reconciliation must never send a POST.');

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
        $c->apiKey      = 'sentinel-api-key-never-store';
        $c->maxAttempts = 1;
        // Execution deliberately left at its shipped default (OFF).

        return $c;
    }

    private function client(): ResellerClubClient
    {
        return new ResellerClubClient(
            $this->config(),
            function (string $url, array $query = []): array {
                $path                = (string) parse_url($url, PHP_URL_PATH);
                $this->getPaths[]    = $path;
                $this->getQueries[]  = [$path, $query];
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

    private function service(): RenewalReconciliationService
    {
        return new RenewalReconciliationService(
            $this->client(),
            $this->config(),
            $this->testDb,
            static fn (): DateTimeImmutable => new DateTimeImmutable(self::NOW, new DateTimeZone('UTC')),
        );
    }

    /** Only used to create batches; never talks to the provider. */
    private function executionService(): RenewalExecutionService
    {
        return new RenewalExecutionService(
            $this->client(),
            $this->config(),
            $this->testDb,
            static fn (): DateTimeImmutable => new DateTimeImmutable(self::NOW, new DateTimeZone('UTC')),
        );
    }

    private function domain(int $n = 1): int
    {
        return $this->insertEnrichableDomain([
            'rc_order_id' => (string) (900000 + $n),
            'domain_name' => "d{$n}.test",
            'expires_at'  => self::EXPIRY_AT,
            'auto_renew'  => 1,
        ]);
    }

    /**
     * @param list<int> $domainIds
     */
    private function batch(array $domainIds, string $key = self::KEY_A): int
    {
        $lines = array_map(static fn (int $d): array => [
            'domain_id' => $d, 'renewal_amount' => '1419.00', 'gst_amount' => '255.42',
            'vat_amount' => '0.00', 'total_amount' => '1674.42', 'currency' => 'INR',
        ], $domainIds);

        return $this->executionService()->createBatch($this->userId, $key, 'KeepInvoice', 1, $lines)['batch_id'];
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

    /** A one-domain batch whose single item is put into the given state. Returns [batchId, itemId]. */
    private function itemIn(string $state, ?string $actionId, string $updatedAt = self::YOUNG, int $n = 1, string $key = self::KEY_A): array
    {
        $batch = $this->batch([$this->domain($n)], $key);
        $item  = $this->itemIds($batch)[0];

        $this->testDb->table('renewal_items')->where('id', $item)->update([
            'state' => $state, 'provider_action_id' => $actionId, 'updated_at' => $updatedAt,
        ]);

        return [$batch, $item];
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function action(array $overrides = [], int $n = 1): array
    {
        return $overrides + [
            'eaqid' => self::ACTION, 'actiontype' => 'RenewDomain', 'entityid' => (string) (900000 + $n),
            'actionstatus' => 'Success', 'actionstatusdesc' => 'Domain renewed successfully', 'actioncompleted' => '1794489745',
        ];
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

    /** @param list<array<string, mixed>> $current @param list<array<string, mixed>> $archived */
    private function provider(array $current, array $archived): void
    {
        $this->script['/api/actions/search-current.json']  = [$this->answer($current)];
        $this->script['/api/actions/search-archived.json'] = [$this->answer($archived)];
    }

    // ---- confirmation ---------------------------------------------------------------

    public function testExecutionSwitchIsOffAndReconciliationDoesNotNeedIt(): void
    {
        $this->assertFalse((IsolatedResellerClubConfig::make())->renewalExecutionEnabled);
        $this->assertFalse(IsolatedResellerClubConfig::make()->renewalExecutionEnabled);
        $this->assertFalse($this->config()->renewalExecutionEnabled);

        [, $item] = $this->itemIn('uncertain', self::ACTION);
        $this->provider([$this->action()], []);

        $this->assertSame('succeeded', $this->service()->reconcileItem($item)->state);
    }

    public function testServiceSourceNeverReferencesTheRenewalCall(): void
    {
        $source = (string) file_get_contents(APPPATH . 'Libraries/Renewal/RenewalReconciliationService.php');
        $code   = (string) preg_replace('~/\*.*?\*/|//[^\n]*~s', '', $source);

        $this->assertStringNotContainsString('renewDomain', $code);
        $this->assertStringNotContainsString('renew.json', $code);
        $this->assertStringNotContainsString('postTransport', $code);
    }

    // ---- matching from current / archived ----------------------------------------------

    public function testUncertainItemIsConfirmedFromCurrentActions(): void
    {
        [$batch, $item] = $this->itemIn('uncertain', self::ACTION);
        $this->provider([$this->action()], []);

        $o = $this->service()->reconcileItem($item);

        $this->assertSame(['uncertain', 'succeeded', 'succeeded', 'provider_confirmed', true], [$o->previousState, $o->state, $o->result, $o->reason, $o->changed]);
        $this->assertSame([self::ACTION, 'Success', 'Domain renewed successfully'], [$o->actionId, $o->actionStatus, $o->statusDescription]);

        $row = $this->item($item);
        $this->assertSame(['succeeded', self::ACTION, null, self::NOW], [$row['state'], $row['provider_action_id'], $row['error_code'], $row['completed_at']]);
        $this->assertSame(
            ['unverified', null, null, null, null, null],
            [$row['debit_verification_status'], $row['debit_verified_at'], $row['debit_transaction_id'], $row['debit_verified_amount'], $row['debit_verified_currency'], $row['debit_verification_note']],
            'Action reconciliation never confirms a debit.',
        );
        $this->assertSame(['completed', self::NOW], [$this->batchRow($batch)['status'], $this->batchRow($batch)['finished_at']]);
        $this->assertSame(['/api/actions/search-current.json'], $this->getPaths, 'A confirmed current action needs no archived lookup.');
    }

    public function testItemIsConfirmedFromArchivedActionsWhenCurrentHasNothing(): void
    {
        [, $item] = $this->itemIn('uncertain', self::ACTION);
        $this->provider([], [$this->action()]);

        $this->assertSame('succeeded', $this->service()->reconcileItem($item)->state);
        $this->assertSame(['/api/actions/search-current.json', '/api/actions/search-archived.json'], $this->getPaths);
    }

    public function testBothSearchesSendTheDocumentedEaqIdParameterAndNeverEaqid(): void
    {
        [, $item] = $this->itemIn('uncertain', self::ACTION);
        $this->provider([], [$this->action()]);

        // Matching still reads the response fields eaqid, actiontype and entityid.
        $this->assertSame('succeeded', $this->service()->reconcileItem($item)->state);

        $this->assertSame(
            ['/api/actions/search-current.json', '/api/actions/search-archived.json'],
            array_column($this->getQueries, 0),
        );

        foreach ($this->getQueries as [$path, $query]) {
            $this->assertArrayHasKey('eaq-id', $query, $path);
            $this->assertSame(self::ACTION, (string) $query['eaq-id'], $path);
            $this->assertArrayNotHasKey('eaqid', $query, $path);
        }
    }

    public function testMatchingUsesTheResponseFieldsEaqidActiontypeAndEntityid(): void
    {
        // A record that is right on the request side but whose response fields differ must not match.
        foreach ([
            ['eaqid' => '999'],                  // other action id
            ['actiontype' => 'AddNewDomain'],    // other type
            ['entityid' => '123'],               // other order
        ] as $n => $override) {
            [, $item] = $this->itemIn('uncertain', self::ACTION, self::YOUNG, $n + 1, 'idem-key-fields-' . $n . '-aaaaaa');
            $this->provider([$this->action($override, $n + 1)], []);

            $this->assertNotSame('succeeded', $this->service()->reconcileItem($item)->state, 'case ' . $n);
        }
    }

    public function testACurrentLookupErrorDoesNotStopTheArchivedLookup(): void
    {
        [, $item] = $this->itemIn('uncertain', self::ACTION);
        $this->script['/api/actions/search-current.json']  = [['status' => 200, 'body' => '{"status":"ERROR","message":"x"}']];
        $this->script['/api/actions/search-archived.json'] = [$this->answer([$this->action()])];

        $this->assertSame('succeeded', $this->service()->reconcileItem($item)->state);
    }

    public function testOnlyTheRecordWithTheStoredActionIdIsUsed(): void
    {
        [, $item] = $this->itemIn('uncertain', self::ACTION);
        $this->provider([$this->action(['eaqid' => '999', 'actionstatus' => 'Success'])], []);

        $o = $this->service()->reconcileItem($item);

        $this->assertSame(['uncertain', 'unresolved', 'action_not_found'], [$o->state, $o->result, $o->reason]);
    }

    public function testTwoRecordsWithTheSameActionIdAreAmbiguousAndNeverSucceed(): void
    {
        [, $item] = $this->itemIn('uncertain', self::ACTION);
        $this->provider([$this->action(), $this->action()], []);

        $o = $this->service()->reconcileItem($item);

        $this->assertSame(['uncertain', 'ambiguous_action'], [$o->state, $o->reason]);
        $this->assertSame('ambiguous_action', $this->item($item)['error_code']);
    }

    // ---- wrong order / wrong type / unmatched --------------------------------------------

    /** @return array<string, array{0: array<string, mixed>}> */
    public static function mismatchedActions(): array
    {
        return [
            'wrong order id'      => [['entityid' => '123']],
            'wrong action type'   => [['actiontype' => 'AddNewDomain']],
            'lowercase type'      => [['actiontype' => 'renewdomain']],
            'missing order field' => [['entityid' => null]],
            'missing type field'  => [['actiontype' => null]],
            'array order'         => [['entityid' => ['900001']]],
        ];
    }

    /**
     * @dataProvider mismatchedActions
     *
     * @param array<string, mixed> $override
     */
    public function testActionOfTheWrongOrderOrTypeIsNeverAccepted(array $override): void
    {
        [$batch, $item] = $this->itemIn('uncertain', self::ACTION);
        $this->provider([$this->action($override)], []);

        $o = $this->service()->reconcileItem($item);

        $this->assertSame(['uncertain', 'unresolved', 'action_mismatch'], [$o->state, $o->result, $o->reason]);
        $this->assertSame(['uncertain', 'action_mismatch'], [$this->item($item)['state'], $this->item($item)['error_code']]);
        $this->assertSame('needs_review', $this->batchRow($batch)['status']);
    }

    public function testUnmatchedActionLeavesTheItemExactlyAsItWas(): void
    {
        [, $item] = $this->itemIn('uncertain', self::ACTION);
        $this->provider([], []);
        $before = $this->item($item);

        $o = $this->service()->reconcileItem($item);

        $this->assertSame(['unresolved', 'action_not_found', false], [$o->result, $o->reason, $o->changed]);
        $this->assertSame($before, $this->item($item));
    }

    public function testBothLookupsFailingIsNotEvidenceAndChangesNothing(): void
    {
        [, $item] = $this->itemIn('uncertain', self::ACTION);
        $this->script['/api/actions/search-current.json']  = [['status' => 503, 'body' => '']];
        $this->script['/api/actions/search-archived.json'] = [new RuntimeException('timeout')];
        $before = $this->item($item);

        $o = $this->service()->reconcileItem($item);

        $this->assertSame(['unresolved', 'lookup_failed', false], [$o->result, $o->reason, $o->changed]);
        $this->assertSame($before, $this->item($item));
    }

    // ---- statuses -------------------------------------------------------------------------

    /** @return array<string, array{0: array<string, mixed>, 1: string}> */
    public static function nonSuccessStatuses(): array
    {
        return [
            'unknown word'   => [['actionstatus' => 'InProcess'], 'status_unrecognised'],
            'wrong case'     => [['actionstatus' => 'success'], 'status_unrecognised'],
            'near miss'      => [['actionstatus' => 'Successful'], 'status_unrecognised'],
            'missing status' => [['actionstatus' => null], 'status_missing'],
            'markup status'  => [['actionstatus' => '<b>Success</b>'], 'status_missing'],
            'array status'   => [['actionstatus' => ['Success']], 'status_missing'],
        ];
    }

    /**
     * @dataProvider nonSuccessStatuses
     *
     * @param array<string, mixed> $override
     */
    public function testUnknownOrMissingStatusStaysUncertainNeverSuccessOrFailure(array $override, string $reason): void
    {
        [$batch, $item] = $this->itemIn('uncertain', self::ACTION);
        $this->provider([$this->action($override)], []);

        $o = $this->service()->reconcileItem($item);

        $this->assertSame(['uncertain', 'unresolved', $reason], [$o->state, $o->result, $o->reason]);
        $this->assertSame(self::ACTION, $o->actionId);
        $this->assertSame(['uncertain', $reason], [$this->item($item)['state'], $this->item($item)['error_code']]);
        $this->assertSame('needs_review', $this->batchRow($batch)['status']);
    }

    public function testUnknownStatusIsReportedWithASanitisedDescriptionButTheDescriptionIsNotStored(): void
    {
        [, $item] = $this->itemIn('uncertain', self::ACTION);
        $this->provider([$this->action(['actionstatus' => 'InProcess', 'actionstatusdesc' => "Line one\x00\n" . str_repeat('x', 400)])], []);

        $o = $this->service()->reconcileItem($item);

        $this->assertSame('InProcess', $o->actionStatus);
        $this->assertLessThanOrEqual(200, mb_strlen((string) $o->statusDescription));
        $this->assertDoesNotMatchRegularExpression('/[\x00-\x1F]/', (string) $o->statusDescription);
        $this->assertStringNotContainsString('Line one', (string) json_encode($this->item($item)));
    }

    // ---- stuck / uncertain items --------------------------------------------------------------

    public function testStuckSubmittingItemBecomesUncertainAndTheBatchNeedsReview(): void
    {
        [$batch, $item] = $this->itemIn('submitting', null, self::OLD);

        $o = $this->service()->reconcileItem($item);

        $this->assertSame(['submitting', 'uncertain', 'unresolved', 'no_action_id', true], [$o->previousState, $o->state, $o->result, $o->reason, $o->changed]);
        $this->assertSame(['uncertain', 'stuck_submitting', null], [$this->item($item)['state'], $this->item($item)['error_code'], $this->item($item)['completed_at']]);
        $this->assertSame('needs_review', $this->batchRow($batch)['status']);
        $this->assertSame([], $this->getPaths, 'Without an action id there is nothing to look up.');
    }

    public function testStuckItemKeepsItsDuplicateProtectionAndIsNeverResent(): void
    {
        [, $item] = $this->itemIn('submitting', null, self::OLD);
        $this->service()->reconcileItem($item);

        // The slot for this domain/expiry cycle is still occupied: a new batch for it is refused.
        try {
            $this->executionService()->createBatch($this->userId, 'idem-key-second-0001', 'KeepInvoice', 1, [[
                'domain_id' => (int) $this->item($item)['domain_id'], 'renewal_amount' => '1419.00', 'gst_amount' => '255.42',
                'vat_amount' => '0.00', 'total_amount' => '1674.42', 'currency' => 'INR',
            ]]);
            $this->fail('The duplicate-cycle guard must still be in force.');
        } catch (RenewalException $e) {
            $this->assertSame('duplicate_cycle', $e->errorCode());
        }

        $this->assertNotSame('pending', $this->item($item)['state']);
    }

    public function testRecentSubmittingItemMayStillBeInFlightAndIsLeftAlone(): void
    {
        [$batch, $item] = $this->itemIn('submitting', null, self::YOUNG);
        $before         = $this->item($item);

        $o = $this->service()->reconcileItem($item);

        $this->assertSame(['skipped', 'in_flight', 'submitting', false], [$o->result, $o->reason, $o->state, $o->changed]);
        $this->assertSame($before, $this->item($item));
        $this->assertSame('pending', $this->batchRow($batch)['status'], 'The batch row is not even touched.');
    }

    public function testUncertainItemWithoutAnActionIdStaysUnresolvedWithoutAnyLookup(): void
    {
        [, $item] = $this->itemIn('uncertain', null, self::OLD);
        $before   = $this->item($item);

        $o = $this->service()->reconcileItem($item);

        $this->assertSame(['unresolved', 'no_action_id', false], [$o->result, $o->reason, $o->changed]);
        $this->assertSame($before, $this->item($item));
        $this->assertSame([], $this->getPaths);
    }

    public function testSubmittingItemWithAKnownActionIdIsLookedUpWhateverItsAge(): void
    {
        [, $item] = $this->itemIn('submitting', self::ACTION, self::YOUNG);
        $this->provider([$this->action()], []);

        $this->assertSame('succeeded', $this->service()->reconcileItem($item)->state);
    }

    public function testSubmittingItemWhoseActionHasAnUnknownStatusBecomesUncertain(): void
    {
        [$batch, $item] = $this->itemIn('submitting', self::ACTION, self::YOUNG);
        $this->provider([$this->action(['actionstatus' => 'Mystery'])], []);

        $o = $this->service()->reconcileItem($item);

        $this->assertSame(['submitting', 'uncertain', 'status_unrecognised'], [$o->previousState, $o->state, $o->reason]);
        $this->assertSame('needs_review', $this->batchRow($batch)['status']);
    }

    public function testNoItemIsEverMovedToFailedOrBackToPending(): void
    {
        $outcomes = [];

        foreach ([
            ['uncertain', self::ACTION, [['actionstatus' => 'Failed']]],
            ['uncertain', self::ACTION, [['actionstatus' => 'Error']]],
            ['submitting', null, null],
        ] as $n => [$state, $action, $records]) {
            [, $item] = $this->itemIn($state, $action, self::OLD, $n + 1, 'idem-key-never-fail-' . $n . 'a');

            if ($records !== null) {
                $this->provider([$this->action($records[0], $n + 1)], []);
            }

            $outcomes[] = $this->service()->reconcileItem($item)->state;
        }

        $this->assertSame(['uncertain', 'uncertain', 'uncertain'], $outcomes);
    }

    // ---- idempotency / isolation ------------------------------------------------------------------

    public function testRepeatingAConfirmedReconciliationChangesNothing(): void
    {
        [, $item] = $this->itemIn('uncertain', self::ACTION);
        $this->provider([$this->action()], []);
        $svc = $this->service();

        $svc->reconcileItem($item);
        $afterFirst = $this->item($item);

        $second = $svc->reconcileItem($item);

        $this->assertSame(['succeeded', 'skipped', 'not_reconcilable', false], [$second->state, $second->result, $second->reason, $second->changed]);
        $this->assertSame($afterFirst, $this->item($item));
        $this->assertCount(1, $this->getPaths, 'The second run made no provider request.');
    }

    public function testRepeatingAnUnresolvedReconciliationWritesNothingTheSecondTime(): void
    {
        [, $item] = $this->itemIn('uncertain', self::ACTION);
        $this->script['/api/actions/search-current.json']  = [$this->answer([$this->action(['actionstatus' => 'InProcess'])]), $this->answer([$this->action(['actionstatus' => 'InProcess'])])];
        $this->script['/api/actions/search-archived.json'] = [];
        $svc = $this->service();

        $first      = $svc->reconcileItem($item);
        $afterFirst = $this->item($item);
        $second     = $svc->reconcileItem($item);

        $this->assertTrue($first->changed, 'The reason is recorded once.');
        $this->assertFalse($second->changed);
        $this->assertSame($afterFirst, $this->item($item));
    }

    public function testRepeatingTheStuckRuleChangesNothingTheSecondTime(): void
    {
        [, $item] = $this->itemIn('submitting', null, self::OLD);
        $svc      = $this->service();

        $this->assertTrue($svc->reconcileItem($item)->changed);
        $afterFirst = $this->item($item);

        $this->assertFalse($svc->reconcileItem($item)->changed);
        $this->assertSame($afterFirst, $this->item($item));
    }

    public function testAnAlreadySucceededOrFailedOrPendingItemIsSkippedWithoutAnyRequest(): void
    {
        foreach (['pending', 'succeeded', 'failed'] as $n => $state) {
            [, $item] = $this->itemIn($state, $state === 'pending' ? null : self::ACTION, self::OLD, $n + 1, 'idem-key-skip-' . $n . '-aaaaa');
            $before   = $this->item($item);

            $o = $this->service()->reconcileItem($item);

            $this->assertSame(['skipped', 'not_reconcilable', $state, false], [$o->result, $o->reason, $o->state, $o->changed]);
            $this->assertSame($before, $this->item($item));
        }

        $this->assertSame([], $this->getPaths);
    }

    public function testOtherItemsAndBatchesAreNeverTouched(): void
    {
        $d1    = $this->domain(1);
        $d2    = $this->domain(2);
        $batch = $this->batch([$d1, $d2]);
        [$i1, $i2] = $this->itemIds($batch);

        $other = $this->batch([$this->domain(3)], 'idem-key-other-batch-1');
        $oi    = $this->itemIds($other)[0];

        foreach ([$i1, $i2, $oi] as $id) {
            $this->testDb->table('renewal_items')->where('id', $id)->update(['state' => 'uncertain', 'provider_action_id' => self::ACTION, 'updated_at' => self::YOUNG]);
        }

        $secondBefore = $this->item($i2);
        $otherBefore  = $this->item($oi);
        $otherBatch   = $this->batchRow($other);

        $this->provider([$this->action()], []);
        $this->assertSame('succeeded', $this->service()->reconcileItem($i1)->state);

        $this->assertSame($secondBefore, $this->item($i2));
        $this->assertSame($otherBefore, $this->item($oi));
        $this->assertSame($otherBatch, $this->batchRow($other));
        $this->assertSame('needs_review', $this->batchRow($batch)['status']);
    }

    public function testBatchReconciliationOnlyTouchesUnresolvedItemsAndDerivesTheStatus(): void
    {
        $batch = $this->batch([$this->domain(1), $this->domain(2), $this->domain(3), $this->domain(4)]);
        [$a, $b, $c, $d] = $this->itemIds($batch);

        $this->testDb->table('renewal_items')->where('id', $a)->update(['state' => 'uncertain', 'provider_action_id' => self::ACTION, 'updated_at' => self::OLD]);
        $this->testDb->table('renewal_items')->where('id', $b)->update(['state' => 'submitting', 'provider_action_id' => null, 'updated_at' => self::OLD]);
        $this->testDb->table('renewal_items')->where('id', $c)->update(['state' => 'succeeded', 'provider_action_id' => '777', 'updated_at' => self::OLD]);
        $this->testDb->table('renewal_items')->where('id', $d)->update(['state' => 'failed', 'error_code' => 'provider_rejected', 'updated_at' => self::OLD]);
        $cBefore = $this->item($c);
        $dBefore = $this->item($d);

        $this->provider([$this->action()], []);
        $result = $this->service()->reconcileBatch($batch);

        $this->assertCount(2, $result['items'], 'Only the uncertain and the submitting item are reconciled.');
        $this->assertSame(['succeeded', 'uncertain'], [$this->item($a)['state'], $this->item($b)['state']]);
        $this->assertSame($cBefore, $this->item($c));
        $this->assertSame($dBefore, $this->item($d));
        $this->assertSame('needs_review', $result['batch_status']);
        $this->assertSame('needs_review', $this->batchRow($batch)['status']);
    }

    public function testRepeatedBatchReconciliationIsStable(): void
    {
        $batch = $this->batch([$this->domain(1), $this->domain(2)]);
        [$a, $b] = $this->itemIds($batch);
        $this->testDb->table('renewal_items')->where('id', $a)->update(['state' => 'uncertain', 'provider_action_id' => self::ACTION, 'updated_at' => self::OLD]);
        $this->testDb->table('renewal_items')->where('id', $b)->update(['state' => 'submitting', 'provider_action_id' => null, 'updated_at' => self::OLD]);

        $this->provider([$this->action()], []);
        $svc = $this->service();
        $svc->reconcileBatch($batch);

        $rows  = [$this->item($a), $this->item($b)];
        $brow  = $this->batchRow($batch);
        $again = $svc->reconcileBatch($batch);

        $this->assertSame($rows, [$this->item($a), $this->item($b)]);
        $this->assertSame($brow, $this->batchRow($batch));
        $this->assertSame(['needs_review'], [$again['batch_status']]);
        $this->assertCount(1, $this->getPaths, 'The repeat made no further provider request.');
    }

    public function testUnknownItemOrBatchIsRefused(): void
    {
        foreach ([fn () => $this->service()->reconcileItem(999999), fn () => $this->service()->reconcileBatch(999999)] as $call) {
            try {
                $call();
                $this->fail('Expected not_found');
            } catch (RenewalException $e) {
                $this->assertSame('not_found', $e->errorCode());
            }
        }
    }

    public function testStoredCodesAreFixedAndNoProviderTextOrSecretIsStored(): void
    {
        [, $item] = $this->itemIn('uncertain', self::ACTION);
        $this->provider([$this->action(['actionstatus' => 'InProcess', 'actionstatusdesc' => 'SENTINEL provider text'])], []);

        $this->service()->reconcileItem($item);

        $row = $this->item($item);
        $this->assertMatchesRegularExpression('/^[a-z0-9_]{1,64}$/', (string) $row['error_code']);
        $this->assertStringNotContainsString('sentinel', strtolower((string) json_encode($row)));
    }
}
