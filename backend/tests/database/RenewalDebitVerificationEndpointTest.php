<?php

declare(strict_types=1);

use App\Filters\ThrottleFilter;
use App\Libraries\Renewal\RenewalExecutionGateway;
use App\Libraries\Renewal\RenewalExecutionService;
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
 * HTTP-level tests for `POST renewal-batches/{batch}/items/{item}/debit-verification`
 * against the isolated *_test database. The provider client wired into the
 * execution gateway counts EVERY transport call (GET and POST) and throws, so any
 * provider contact by the verification endpoint would fail these tests.
 *
 * @internal
 */
final class RenewalDebitVerificationEndpointTest extends CIUnitTestCase
{
    use FeatureTestTrait;
    use UsesIsolatedAuthDatabase {
        tearDownAfterClass as private tearDownAuthSchema;
    }
    use UsesEnrichmentSchema;
    use UsesRenewalSchema;

    private const PASSWORD = 'Sentinel-Pass-123!';
    private const EXPIRY   = '2035-01-01 00:00:00';

    /** @var array<string, string> */
    private array $tokens = [];

    /** @var array<string, int> */
    private array $userIds = [];

    private int $providerCalls = 0;

    protected function setUp(): void
    {
        parent::setUp();

        self::emptyRenewalTables();

        $this->setUpIsolatedAuthDatabase();
        $this->setUpEnrichmentSchema();
        $this->setUpRenewalSchema();

        $throttler = service('throttler');

        foreach (array_keys(config('Auth')->ipLimits) as $bucket) {
            foreach (['0.0.0.0', '127.0.0.1', '::1'] as $ip) {
                $throttler->remove(ThrottleFilter::key($bucket, $ip));
            }
        }

        Services::resetSingle('authService');

        $this->tokens        = [];
        $this->userIds       = [];
        $this->providerCalls = 0;

        foreach (['a@example.test', 'b@example.test'] as $email) {
            $this->userIds[$email] = $this->insertUser(['email' => $email, 'password_hash' => password_hash(self::PASSWORD, PASSWORD_DEFAULT)]);
            $login                 = $this->withBodyFormat('json')->post('api/v1/auth/login', ['email' => $email, 'password' => self::PASSWORD]);
            $this->assertSame(200, $login->response()->getStatusCode());
            $this->tokens[$email] = json_decode((string) $login->response()->getBody(), true)['access_token'];
        }

        $config                          = IsolatedResellerClubConfig::make();
        $config->baseUrl                 = 'https://test.httpapi.example/api';
        $config->authUserId              = '123456';
        $config->apiKey                  = 'sentinel-api-key-never-return';
        $config->maxAttempts             = 1;
        $config->renewalExecutionEnabled = true; // in-memory only: proves verification never executes even then
        $config->renewalDebitVerified    = true; // in-memory only

        $fail = function (): array {
            $this->providerCalls++;

            throw new RuntimeException('provider must never be contacted');
        };

        $client = new ResellerClubClient($config, $fail, static function (int $ms): void {
        }, $fail);

        Services::injectMock('renewalExecutionGateway', new RenewalExecutionGateway(new RenewalExecutionService($client, $config, $this->testDb)));
    }

    protected function tearDown(): void
    {
        Services::resetSingle('renewalExecutionGateway');
        Services::resetSingle('authService');

        parent::tearDown();
    }

    public static function tearDownAfterClass(): void
    {
        self::tearDownRenewalSchema();
        self::tearDownEnrichmentSchema();
        self::tearDownAuthSchema();
    }

    // ---- helpers ------------------------------------------------------------------

    /** @return array{0: int, 1: int} [batchId, itemId] */
    private function itemIn(string $state, string $email = 'a@example.test', int $n = 1): array
    {
        $domain = $this->insertEnrichableDomain([
            'rc_order_id' => (string) (900000 + $n), 'domain_name' => "d{$n}.com", 'expires_at' => self::EXPIRY, 'auto_renew' => 1,
        ]);

        $service = new RenewalExecutionService(
            new ResellerClubClient(IsolatedResellerClubConfig::make(), static fn (): array => [], static function (int $ms): void {
            }),
            IsolatedResellerClubConfig::make(),
            $this->testDb,
        );

        $batch = $service->createBatch($this->userIds[$email], 'idem-key-' . str_pad((string) $n, 12, 'x'), 'KeepInvoice', 1, [[
            'domain_id' => $domain, 'renewal_amount' => '1419.00', 'gst_amount' => '255.42',
            'vat_amount' => '0.00', 'total_amount' => '1674.42', 'currency' => 'INR',
        ]])['batch_id'];

        $item = (int) $this->testDb->table('renewal_items')->select('id')->where('batch_id', $batch)->get()->getRowArray()['id'];

        if ($state !== 'pending') {
            $this->testDb->table('renewal_items')->where('id', $item)->update(['state' => $state]);
        }

        return [$batch, $item];
    }

    /** @return array<string, mixed> */
    private function row(int $item): array
    {
        return $this->testDb->table('renewal_items')->where('id', $item)->get()->getRowArray();
    }

    /** @return array<string, mixed> */
    private function evidence(array $override = []): array
    {
        return $override + [
            'status' => 'confirmed', 'transaction_id' => 'TXN-778899', 'amount' => '1674.42',
            'currency' => 'INR', 'note' => 'Matched in ResellerClub transaction history.',
        ];
    }

    /**
     * @param array<string, mixed>|null $body
     *
     * @return array{0: int, 1: array<string, mixed>}
     */
    private function verify(int $batch, int $item, ?array $body, ?string $as = 'a@example.test', ?string $raw = null): array
    {
        $req = $as === null ? $this : $this->withHeaders(['Authorization' => 'Bearer ' . $this->tokens[$as]]);
        $req = $raw !== null ? $req->withBody($raw) : $req->withBodyFormat('json');

        try {
            $result = $req->call('POST', "api/v1/renewal-batches/{$batch}/items/{$item}/debit-verification", $raw !== null ? null : $body);
        } catch (\CodeIgniter\Exceptions\PageNotFoundException) {
            return [404, []];
        }

        return [$result->response()->getStatusCode(), json_decode((string) $result->response()->getBody(), true) ?? []];
    }

    private function auditCount(int $item): int
    {
        return (int) $this->testDb->table('renewal_debit_verifications')->where('renewal_item_id', $item)->countAllResults();
    }

    // ---- defaults and shipped safety ------------------------------------------------

    public function testNewItemsDefaultToUnverifiedAndTheShippedSwitchesStayOff(): void
    {
        [, $item] = $this->itemIn('pending');
        $row      = $this->row($item);

        $this->assertSame(['pending', 'unverified', null, null, null, null, null], [
            $row['state'], $row['debit_verification_status'], $row['debit_verified_at'], $row['debit_transaction_id'],
            $row['debit_verified_amount'], $row['debit_verified_currency'], $row['debit_verification_note'],
        ]);

        $c = IsolatedResellerClubConfig::make();
        $this->assertFalse($c->renewalExecutionEnabled);
        $this->assertFalse($c->renewalDebitVerified);
    }

    public function testRequiresAuthentication(): void
    {
        [$batch, $item] = $this->itemIn('succeeded');

        [$status] = $this->verify($batch, $item, $this->evidence(), null);

        $this->assertSame(401, $status);
        $this->assertSame('unverified', $this->row($item)['debit_verification_status']);
    }

    // ---- confirm --------------------------------------------------------------------

    public function testAProviderSucceededItemCanBeManuallyConfirmed(): void
    {
        [$batch, $item] = $this->itemIn('succeeded');

        [$status, $json] = $this->verify($batch, $item, $this->evidence(['currency' => 'usd']));

        $this->assertSame(200, $status);
        $this->assertFalse($json['replayed']);
        $this->assertSame('succeeded', $json['item']['state']);
        $this->assertSame(['confirmed', 'TXN-778899', '1674.42', 'USD'], [
            $json['item']['debit_verification_status'], $json['item']['debit_transaction_id'],
            $json['item']['debit_verified_amount'], $json['item']['debit_verified_currency'],
        ]);
        $this->assertNotNull($json['item']['debit_verified_at']);

        $row = $this->row($item);
        $this->assertSame('confirmed', $row['debit_verification_status']);
        $this->assertNotNull($row['debit_verified_at']);
        $this->assertSame(1, $this->auditCount($item));
        $this->assertSame(0, $this->providerCalls);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function nonSucceededStates(): array
    {
        return ['pending' => ['pending'], 'submitting' => ['submitting'], 'failed' => ['failed'], 'uncertain' => ['uncertain']];
    }

    /**
     * @dataProvider nonSucceededStates
     */
    public function testOnlyProviderSucceededItemsCanBeVerified(string $state): void
    {
        [$batch, $item] = $this->itemIn($state);

        foreach (['confirmed', 'not_found', 'disputed'] as $s) {
            [$status, $json] = $this->verify($batch, $item, $this->evidence(['status' => $s]));

            $this->assertSame(409, $status, "{$state}/{$s}");
            $this->assertSame('item_not_succeeded', $json['error']);
        }

        $row = $this->row($item);
        $this->assertSame([$state, 'unverified', null], [$row['state'], $row['debit_verification_status'], $row['debit_verified_at']]);
        $this->assertSame(0, $this->auditCount($item));
        $this->assertSame(0, $this->providerCalls);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function mandatoryFields(): array
    {
        return ['transaction_id' => ['transaction_id'], 'amount' => ['amount'], 'currency' => ['currency'], 'note' => ['note']];
    }

    /**
     * @dataProvider mandatoryFields
     */
    public function testConfirmationRequiresEveryEvidenceField(string $field): void
    {
        [$batch, $item] = $this->itemIn('succeeded');

        foreach ([null, '', '   '] as $blank) {
            $body = $this->evidence();

            if ($blank === null) {
                unset($body[$field]);
            } else {
                $body[$field] = $blank;
            }

            [$status, $json] = $this->verify($batch, $item, $body);

            $this->assertContains($status, [400, 422], "{$field} blank");
            $this->assertArrayHasKey('error', $json);
        }

        $this->assertSame('unverified', $this->row($item)['debit_verification_status']);
        $this->assertSame(0, $this->auditCount($item));
    }

    public function testMalformedEvidenceAndBodiesAreRejected(): void
    {
        [$batch, $item] = $this->itemIn('succeeded');

        foreach ([
            $this->evidence(['amount' => '-5']), $this->evidence(['amount' => '0']), $this->evidence(['amount' => '12.345']),
            $this->evidence(['amount' => 'abc']), $this->evidence(['currency' => 'INRX']), $this->evidence(['currency' => 'I1R']),
            $this->evidence(['transaction_id' => 'bad id!']), $this->evidence(['status' => 'unverified']), $this->evidence(['status' => 'bogus']),
            $this->evidence(['amount' => 1674.42]), $this->evidence(['state' => 'failed']), $this->evidence(['note' => str_repeat('x', 1001)]),
        ] as $body) {
            [$status] = $this->verify($batch, $item, $body);
            $this->assertSame(400, $status, (string) json_encode($body));
        }

        foreach (['', '[]', 'not json', '["x"]'] as $raw) {
            [$status] = $this->verify($batch, $item, null, 'a@example.test', $raw);
            $this->assertSame(400, $status, $raw);
        }

        $this->assertSame('unverified', $this->row($item)['debit_verification_status']);
    }

    // ---- not_found / disputed ----------------------------------------------------------

    /**
     * @return array<string, array{0: string}>
     */
    public static function nonConfirmingStatuses(): array
    {
        return ['not_found' => ['not_found'], 'disputed' => ['disputed']];
    }

    /**
     * @dataProvider nonConfirmingStatuses
     */
    public function testNotFoundAndDisputedNeverBecomeConfirmed(string $newStatus): void
    {
        [$batch, $item] = $this->itemIn('succeeded');

        [$status, $json] = $this->verify($batch, $item, ['status' => $newStatus, 'note' => 'Checked ResellerClub history; nothing matching.']);

        $this->assertSame(200, $status);
        $this->assertSame($newStatus, $json['item']['debit_verification_status']);
        $this->assertNotSame('confirmed', $json['item']['debit_verification_status']);

        $row = $this->row($item);
        $this->assertSame(['succeeded', $newStatus], [$row['state'], $row['debit_verification_status']]);
        $this->assertNotNull($row['debit_verified_at']);

        // Even when confirmation-style evidence is attached, the stored status is the one the operator chose.
        [$s2] = $this->verify($batch, $item, $this->evidence(['status' => $newStatus, 'note' => 'Different note after recheck.']));
        $this->assertSame(200, $s2);
        $this->assertSame($newStatus, $this->row($item)['debit_verification_status']);
        $this->assertSame(0, $this->providerCalls);
    }

    public function testNotFoundMayLaterBeCorrectedToConfirmedAndEveryChangeIsAudited(): void
    {
        [$batch, $item] = $this->itemIn('succeeded');

        $this->verify($batch, $item, ['status' => 'not_found', 'note' => 'Not in history yet.']);
        [$status, $json] = $this->verify($batch, $item, $this->evidence());

        $this->assertSame(200, $status);
        $this->assertSame('confirmed', $json['item']['debit_verification_status']);

        $rows = $this->testDb->table('renewal_debit_verifications')->where('renewal_item_id', $item)->orderBy('id')->get()->getResultArray();
        $this->assertSame([['unverified', 'not_found'], ['not_found', 'confirmed']], array_map(static fn (array $r): array => [$r['previous_status'], $r['new_status']], $rows));
        $this->assertSame($this->userIds['a@example.test'], (int) $rows[1]['user_id']);
    }

    // ---- repeats and overwrite protection ------------------------------------------------

    public function testARepeatedIdenticalSubmissionIsAHarmlessReplay(): void
    {
        [$batch, $item] = $this->itemIn('succeeded');

        [, $first] = $this->verify($batch, $item, $this->evidence());
        $before    = $this->row($item);

        [$status, $second] = $this->verify($batch, $item, $this->evidence());

        $this->assertSame(200, $status);
        $this->assertTrue($second['replayed']);
        $this->assertSame($first['item']['debit_verified_at'], $second['item']['debit_verified_at']);
        $this->assertSame($before, $this->row($item));
        $this->assertSame(1, $this->auditCount($item));
        $this->assertSame(0, $this->providerCalls);
    }

    public function testAConfirmedResultCannotBeSilentlyOverwritten(): void
    {
        [$batch, $item] = $this->itemIn('succeeded');
        $this->verify($batch, $item, $this->evidence());
        $before = $this->row($item);

        foreach ([
            $this->evidence(['transaction_id' => 'TXN-OTHER']), $this->evidence(['amount' => '1.00']),
            $this->evidence(['status' => 'disputed', 'note' => 'changed mind']), ['status' => 'not_found', 'note' => 'gone'],
        ] as $body) {
            [$status, $json] = $this->verify($batch, $item, $body);

            $this->assertSame(409, $status);
            $this->assertSame('already_confirmed', $json['error']);
        }

        $this->assertSame($before, $this->row($item));
        $this->assertSame(1, $this->auditCount($item));
    }

    // ---- independence from the renewal state and the provider ------------------------------

    public function testVerificationNeverChangesRenewalStateOrProviderFieldsAndNeverContactsTheProvider(): void
    {
        [$batch, $item] = $this->itemIn('succeeded');
        $this->testDb->table('renewal_items')->where('id', $item)->update(['provider_action_id' => '111222', 'provider_invoice_id' => '333444', 'completed_at' => '2026-11-09 10:00:00']);
        $batchBefore = $this->testDb->table('renewal_batches')->where('id', $batch)->get()->getRowArray();
        $before      = $this->row($item);

        foreach ([$this->evidence(), ['status' => 'disputed', 'note' => 'x'], $this->evidence()] as $body) {
            $this->verify($batch, $item, $body);
        }

        $after = $this->row($item);

        foreach (['state', 'provider_action_id', 'provider_invoice_id', 'error_code', 'completed_at', 'renewal_amount', 'total_amount', 'currency', 'expiry_at_request', 'active_expiry_at'] as $col) {
            $this->assertSame($before[$col], $after[$col], $col);
        }

        $this->assertSame($batchBefore, $this->testDb->table('renewal_batches')->where('id', $batch)->get()->getRowArray());
        $this->assertSame(0, $this->providerCalls, 'No provider GET or POST may be made.');
    }

    public function testTheVerificationCodeCannotReachTheRenewalApi(): void
    {
        foreach (['Libraries/Renewal/RenewalDebitVerifier.php', 'Controllers/Api/V1/RenewalDebitVerificationController.php'] as $file) {
            $code = (string) preg_replace('~/\*.*?\*/|//[^\n]*~s', '', (string) file_get_contents(APPPATH . $file));

            foreach (['renewDomain', 'renew.json', 'postTransport', 'ResellerClubClient', 'RenewalExecution', 'renewalExecutionGateway', 'processItem'] as $needle) {
                $this->assertStringNotContainsString($needle, $code, $file . ' must not reference ' . $needle);
            }

            $this->assertDoesNotMatchRegularExpression("/'state'\\s*=>/", $code, $file . ' must never write the renewal state');
        }
    }

    // ---- ownership -----------------------------------------------------------------------

    public function testAnotherUsersItemIsIndistinguishableFromAMissingOne(): void
    {
        [$batch, $item] = $this->itemIn('succeeded', 'b@example.test');

        [$foreign, $fb] = $this->verify($batch, $item, $this->evidence(), 'a@example.test');
        [$missing, $mb] = $this->verify($batch + 999, $item, $this->evidence(), 'a@example.test');

        $this->assertSame([404, 404], [$foreign, $missing]);
        $this->assertSame($mb, $fb);
        $this->assertSame('unverified', $this->row($item)['debit_verification_status']);
    }

    // ---- the batch read shows both statuses ----------------------------------------------

    public function testBatchReadExposesBothStatusesSeparately(): void
    {
        [$batch, $item] = $this->itemIn('succeeded');
        $this->verify($batch, $item, $this->evidence());

        $result = $this->withHeaders(['Authorization' => 'Bearer ' . $this->tokens['a@example.test']])->call('GET', "api/v1/renewal-batches/{$batch}");
        $json   = json_decode((string) $result->response()->getBody(), true);
        $it     = $json['batch']['items'][0];

        $this->assertSame(['succeeded', 'confirmed', 'TXN-778899', '1674.42', 'INR'], [
            $it['state'], $it['debit_verification_status'], $it['debit_transaction_id'], $it['debit_verified_amount'], $it['debit_verified_currency'],
        ]);
    }
}
