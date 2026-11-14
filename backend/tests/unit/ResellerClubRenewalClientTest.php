<?php

declare(strict_types=1);

use App\Libraries\ResellerClub\RenewalResponse;
use App\Libraries\ResellerClub\ResellerClubClient;
use App\Libraries\ResellerClub\ResellerClubException;
use CodeIgniter\Test\CIUnitTestCase;
use Config\ResellerClub as ResellerClubConfig;
use Tests\Support\Libraries\IsolatedResellerClubConfig;

/**
 * renewDomain() against FAKE transports only. Nothing here can reach the
 * network: every client gets an injected POST transport, and the GET transport
 * and sleep are spies that must stay unused. The execution switch is turned on
 * only on private in-memory config objects (never the shared application
 * config, never .env), and the success/failure status vocabulary is test data
 * because ResellerClub's reference does not publish the real values.
 *
 * @internal
 */
final class ResellerClubRenewalClientTest extends CIUnitTestCase
{
    private const SECRET_KEY = 'sentinel-api-key-never-log';
    private const EXPIRY     = 1798761600; // 2027-01-01 00:00:00 UTC

    /** @var list<array{0: string, 1: array<string, scalar>, 2: int}> */
    private array $posts = [];

    private int $gets   = 0;
    private int $sleeps = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->posts  = [];
        $this->gets   = 0;
        $this->sleeps = 0;
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function config(array $overrides = []): ResellerClubConfig
    {
        $c                         = IsolatedResellerClubConfig::make();
        $c->baseUrl                = 'https://test.httpapi.example/api';
        $c->authUserId             = '123456';
        $c->apiKey                 = self::SECRET_KEY;
        $c->renewalExecutionEnabled = true; // in-memory only; fake transport below
        $c->renewalSuccessStatuses = ['Success'];
        $c->renewalFailureStatuses = ['Failed'];

        foreach ($overrides as $k => $v) {
            $c->{$k} = $v;
        }

        return $c;
    }

    /**
     * @param list<array{status: int, body: string}|Throwable> $script
     * @param array<string, mixed>                              $cfg
     */
    private function client(array $script, array $cfg = []): ResellerClubClient
    {
        return new ResellerClubClient(
            $this->config($cfg),
            function (): array {
                $this->gets++;

                throw new RuntimeException('GET transport must not be used');
            },
            function (): void {
                $this->sleeps++;
            },
            function (string $url, array $form, int $timeout) use (&$script): array {
                $this->posts[] = [$url, $form, $timeout];
                $next          = array_shift($script);

                if ($next === null) {
                    throw new RuntimeException('unexpected extra POST');
                }

                if ($next instanceof Throwable) {
                    throw $next;
                }

                return $next;
            },
        );
    }

    /** @param array<string, mixed> $body */
    private function ok(array $body = []): array
    {
        return ['status' => 200, 'body' => (string) json_encode($body + [
            'eaqid' => '111222', 'actionstatus' => 'Success', 'actionstatusdesc' => 'Renewed', 'invoiceid' => '333444', 'status' => 'Success',
        ])];
    }

    private function renew(ResellerClubClient $c, string $invoice = 'KeepInvoice', string $order = '987654', int $years = 1, int $expiry = self::EXPIRY, bool $auto = true): RenewalResponse
    {
        return $c->renewDomain($order, $years, $expiry, $auto, $invoice);
    }

    // ---- execution switch -----------------------------------------------------

    public function testExecutionIsDisabledByDefaultAndTheSharedConfigIsOff(): void
    {
        $this->assertFalse((IsolatedResellerClubConfig::make())->renewalExecutionEnabled);
        $this->assertFalse(IsolatedResellerClubConfig::make()->renewalExecutionEnabled, 'The application configuration must have execution disabled.');
        // Only the verified success status is configured; no failure status is verified, so none is guessed.
        $this->assertSame(['Success'], (IsolatedResellerClubConfig::make())->renewalSuccessStatuses);
        $this->assertSame([], (IsolatedResellerClubConfig::make())->renewalFailureStatuses);
    }

    public function testDisabledClientRefusesBeforeAnyRequest(): void
    {
        $client = $this->client([$this->ok()], ['renewalExecutionEnabled' => false]);

        try {
            $this->renew($client);
            $this->fail('Expected the disabled switch to refuse the renewal.');
        } catch (ResellerClubException $e) {
            $this->assertSame('execution_disabled', $e->getMessage());
        }

        $this->assertSame([], $this->posts);
    }

    // ---- request mapping -------------------------------------------------------

    public function testPostParametersMatchTheDocumentedEndpoint(): void
    {
        $this->renew($this->client([$this->ok()]), 'KeepInvoice', '987654', 2, self::EXPIRY, true);

        $this->assertCount(1, $this->posts);
        [$url, $form, $timeout] = $this->posts[0];

        $this->assertSame('https://test.httpapi.example/api/domains/renew.json', $url);
        $this->assertStringNotContainsString('?', $url);
        $this->assertStringNotContainsString(self::SECRET_KEY, $url);
        $this->assertSame([
            'auth-userid'    => '123456',
            'api-key'        => self::SECRET_KEY,
            'order-id'       => '987654',
            'years'          => 2,
            'exp-date'       => self::EXPIRY,
            'auto-renew'     => 'true',
            'invoice-option' => 'KeepInvoice',
        ], $form);
        $this->assertLessThanOrEqual(30, $timeout);
        $this->assertSame(0, $this->gets);
    }

    public function testAutoRenewFalseAndNoInvoiceAreSentExactly(): void
    {
        $this->renew($this->client([$this->ok(['invoiceid' => null])]), 'NoInvoice', '987654', 1, self::EXPIRY, false);

        $form = $this->posts[0][1];
        $this->assertSame('false', $form['auto-renew']);
        $this->assertSame('NoInvoice', $form['invoice-option']);
    }

    /** @return array<string, array{0: string}> */
    public static function badInvoiceOptions(): array
    {
        return [
            'lowercase'  => ['keepinvoice'],
            'upper'      => ['NOINVOICE'],
            'PayInvoice' => ['PayInvoice'],
            'OnlyAdd'    => ['OnlyAdd'],
            'empty'      => [''],
            'padded'     => [' KeepInvoice'],
        ];
    }

    /** @dataProvider badInvoiceOptions */
    public function testInvoiceOptionMustMatchExactCaseAndBeOneOfTwo(string $option): void
    {
        $this->assertSame(['KeepInvoice', 'NoInvoice'], ResellerClubClient::INVOICE_OPTIONS);
        $this->assertRefusedBeforeSending(fn (ResellerClubClient $c) => $this->renew($c, $option));
    }

    /** @return array<string, array{0: int}> */
    public static function badYears(): array
    {
        return ['zero' => [0], 'negative' => [-1], 'eleven' => [11], 'huge' => [100]];
    }

    /** @dataProvider badYears */
    public function testYearsOutsideOneToTenAreRefused(int $years): void
    {
        $this->assertRefusedBeforeSending(fn (ResellerClubClient $c) => $this->renew($c, 'KeepInvoice', '987654', $years));
    }

    public function testYearsOneAndTenAreAccepted(): void
    {
        $this->renew($this->client([$this->ok(), $this->ok()]), 'KeepInvoice', '987654', 1);
        $this->renew($this->client([$this->ok()]), 'KeepInvoice', '987654', 10);

        $this->assertSame([1, 10], array_column(array_column($this->posts, 1), 'years'));
    }

    /** @return array<string, array{0: string}> */
    public static function badOrderIds(): array
    {
        return [
            'empty'   => [''], 'letters' => ['abc'], 'leading zero' => ['0123'], 'decimal' => ['12.5'],
            'negative' => ['-5'], 'space' => ['12 34'], 'too long' => ['1234567890123'], 'injection' => ['1&auth-userid=9'],
        ];
    }

    /** @dataProvider badOrderIds */
    public function testInvalidOrderIdsAreRefused(string $orderId): void
    {
        $this->assertRefusedBeforeSending(fn (ResellerClubClient $c) => $this->renew($c, 'KeepInvoice', $orderId));
    }

    /** @return array<string, array{0: int}> */
    public static function badExpiries(): array
    {
        return ['zero' => [0], 'one' => [1], 'negative' => [-100], 'year 2200' => [7258118400]];
    }

    /** @dataProvider badExpiries */
    public function testInvalidExpiryEpochIsRefused(int $expiry): void
    {
        $this->assertRefusedBeforeSending(fn (ResellerClubClient $c) => $this->renew($c, 'KeepInvoice', '987654', 1, $expiry));
    }

    public function testUnconfiguredClientRefusesBeforeSending(): void
    {
        $client = $this->client([$this->ok()], ['apiKey' => '']);

        try {
            $this->renew($client);
            $this->fail('Expected not_configured.');
        } catch (ResellerClubException $e) {
            $this->assertSame('not_configured', $e->getMessage());
        }

        $this->assertSame([], $this->posts);
    }

    private function assertRefusedBeforeSending(callable $call): void
    {
        $client = $this->client([$this->ok()]);

        try {
            $call($client);
            $this->fail('Expected invalid_renewal_request.');
        } catch (ResellerClubException $e) {
            $this->assertSame('invalid_renewal_request', $e->getMessage());
        }

        $this->assertSame([], $this->posts, 'Nothing may be sent for an invalid request.');
    }

    // ---- responses -----------------------------------------------------------------

    public function testSuccessfulActionRecordsActionIdStatusAndInvoiceId(): void
    {
        $r = $this->renew($this->client([$this->ok()]), 'KeepInvoice');

        $this->assertSame(RenewalResponse::SUCCEEDED, $r->outcome);
        $this->assertNull($r->errorCode);
        $this->assertSame('111222', $r->actionId);
        $this->assertSame('Success', $r->actionStatus);
        $this->assertSame('Renewed', $r->actionDescription);
        $this->assertSame('333444', $r->invoiceId);
    }

    public function testNoInvoiceSuccessHasNoInvoiceId(): void
    {
        // The reference says invoiceid is omitted for NoInvoice.
        $body = ['eaqid' => '555', 'actionstatus' => 'Success', 'actionstatusdesc' => 'Renewed'];
        $r    = $this->renew($this->client([['status' => 200, 'body' => (string) json_encode($body)]]), 'NoInvoice');

        $this->assertSame(RenewalResponse::SUCCEEDED, $r->outcome);
        $this->assertSame('555', $r->actionId);
        $this->assertNull($r->invoiceId);
    }

    public function testInvalidInvoiceIdIsDroppedNotStored(): void
    {
        $r = $this->renew($this->client([$this->ok(['invoiceid' => 'abc<script>'])]));

        $this->assertSame(RenewalResponse::SUCCEEDED, $r->outcome);
        $this->assertNull($r->invoiceId);
    }

    /** @return array<string, array{0: string}> */
    public static function nearMissStatuses(): array
    {
        return ['upper' => ['SUCCESS'], 'lower' => ['success'], 'prefix' => ['Successful'], 'padded inside' => ['Suc cess']];
    }

    /** @dataProvider nearMissStatuses */
    public function testActionStatusComparisonIsExact(string $status): void
    {
        $r = $this->renew($this->client([$this->ok(['actionstatus' => $status])]));

        $this->assertSame(RenewalResponse::UNCERTAIN, $r->outcome);
        $this->assertSame('status_unrecognised', $r->errorCode);
    }

    public function testShippedDefaultConfigurationClassifiesExactSuccessAsSucceeded(): void
    {
        // No overrides of the status lists: the real application defaults.
        $c = IsolatedResellerClubConfig::make();
        $c->baseUrl = 'https://test.httpapi.example/api';
        $c->authUserId = '123456';
        $c->apiKey = self::SECRET_KEY;
        $c->renewalExecutionEnabled = true; // in-memory only
        $client = new ResellerClubClient($c, null, null, fn (): array => $this->ok());

        $this->assertSame(RenewalResponse::SUCCEEDED, $this->renew($client)->outcome);
    }

    public function testHttp200WithoutAnActionStatusIsNeverSuccess(): void
    {
        $r = $this->renew($this->client([['status' => 200, 'body' => '{"status":"Success","eaqid":"5"}']]));

        $this->assertSame(RenewalResponse::UNCERTAIN, $r->outcome);
    }

    public function testAcceptedResponseWithUnverifiedStatusIsUncertainNotSuccess(): void
    {
        // Default config: the status vocabulary is not verified, so nothing counts as success.
        $client = $this->client([$this->ok()], ['renewalSuccessStatuses' => [], 'renewalFailureStatuses' => []]);
        $r      = $this->renew($client);

        $this->assertSame(RenewalResponse::UNCERTAIN, $r->outcome);
        $this->assertSame('status_unrecognised', $r->errorCode);
        $this->assertSame('111222', $r->actionId, 'The action id is still kept for reconciliation.');
    }

    public function testUnknownStatusWordIsUncertain(): void
    {
        $r = $this->renew($this->client([$this->ok(['actionstatus' => 'InProcess'])]));

        $this->assertSame(RenewalResponse::UNCERTAIN, $r->outcome);
        $this->assertSame('status_unrecognised', $r->errorCode);
    }

    public function testConfiguredFailureStatusIsADefiniteFailure(): void
    {
        $r = $this->renew($this->client([$this->ok(['actionstatus' => 'Failed'])]));

        $this->assertSame(RenewalResponse::FAILED, $r->outcome);
        $this->assertSame('provider_action_failed', $r->errorCode);
        $this->assertSame('111222', $r->actionId);
    }

    public function testProviderErrorIsADefiniteRejectionAndItsMessageIsKept(): void
    {
        $body = (string) json_encode(['status' => 'ERROR', 'message' => "The domain\nhas expired.", 'error' => 'x']);
        $r    = $this->renew($this->client([['status' => 200, 'body' => $body]]));

        $this->assertSame(RenewalResponse::FAILED, $r->outcome);
        $this->assertSame('provider_rejected', $r->errorCode);
        $this->assertSame('The domain has expired.', $r->actionDescription, 'Control characters are removed.');
    }

    public function testProviderMessageFallsBackToTheErrorKeyAndNeverKeepsTheApiKey(): void
    {
        $body = (string) json_encode(['status' => 'ERROR', 'error' => 'Bad request for key ' . self::SECRET_KEY]);
        $r    = $this->renew($this->client([['status' => 200, 'body' => $body]]));

        $this->assertSame('Bad request for key [hidden]', $r->actionDescription);
        $this->assertStringNotContainsString(self::SECRET_KEY, (string) json_encode($r));
    }

    public function testProviderErrorWithNoMessageStillFailsCleanly(): void
    {
        $r = $this->renew($this->client([['status' => 200, 'body' => '{"status":"ERROR"}']]));

        $this->assertSame([RenewalResponse::FAILED, 'provider_rejected', null], [$r->outcome, $r->errorCode, $r->actionDescription]);
    }

    public function testExpiredDomainExpiryInThePastIsSentUnchangedAsExpDate(): void
    {
        $past = 1700000000; // November 2023
        $c    = $this->client([$this->ok()]);

        $this->assertSame(RenewalResponse::SUCCEEDED, $c->renewDomain('900001', 1, $past, true, 'KeepInvoice')->outcome);
        $this->assertSame($past, $this->posts[0][1]['exp-date'] ?? null);
    }

    /** @return array<string, array{0: int}> */
    public static function definiteHttpRejections(): array
    {
        return ['400' => [400], '401' => [401], '403' => [403], '404' => [404], '422' => [422]];
    }

    /** @dataProvider definiteHttpRejections */
    public function testHttp4xxIsADefiniteRejection(int $status): void
    {
        $r = $this->renew($this->client([['status' => $status, 'body' => '{"status":"ERROR"}']]));

        $this->assertSame(RenewalResponse::FAILED, $r->outcome);
        $this->assertSame('http_' . $status, $r->errorCode);
    }

    /** @return array<string, array{0: int}> */
    public static function uncertainHttpStatuses(): array
    {
        return ['408' => [408], '429' => [429], '500' => [500], '502' => [502], '503' => [503], '302' => [302]];
    }

    /** @dataProvider uncertainHttpStatuses */
    public function testTransientOrRedirectStatusIsUncertainAndNotRetried(int $status): void
    {
        $client = $this->client([['status' => $status, 'body' => ''], $this->ok()]);
        $r      = $this->renew($client);

        $this->assertSame(RenewalResponse::UNCERTAIN, $r->outcome);
        $this->assertSame('http_' . $status, $r->errorCode);
        $this->assertCount(1, $this->posts, 'The POST must never be retried.');
        $this->assertSame(0, $this->sleeps);
    }

    /** @return array<string, array{0: string}> */
    public static function malformedBodies(): array
    {
        return [
            'not json'        => ['<html>oops</html>'],
            'empty'           => [''],
            'empty object'    => ['{}'],
            'json scalar'     => ['"ok"'],
            'no eaqid'        => ['{"actionstatus":"Success"}'],
            'no actionstatus' => ['{"eaqid":"1"}'],
            'bad eaqid'       => ['{"eaqid":"abc","actionstatus":"Success"}'],
            'array eaqid'     => ['{"eaqid":["1"],"actionstatus":"Success"}'],
            'bad status text' => ['{"eaqid":"1","actionstatus":"<b>Success</b>"}'],
            'long status'     => ['{"eaqid":"1","actionstatus":"' . '' . 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa"}'],
        ];
    }

    /** @dataProvider malformedBodies */
    public function testMalformedSuccessResponseIsUncertain(string $body): void
    {
        $r = $this->renew($this->client([['status' => 200, 'body' => $body]]));

        $this->assertSame(RenewalResponse::UNCERTAIN, $r->outcome);
        $this->assertSame('malformed_response', $r->errorCode);
        $this->assertNotSame(RenewalResponse::SUCCEEDED, $r->outcome);
    }

    public function testTimeoutIsUncertainAndNeverRetried(): void
    {
        $url    = 'https://test.httpapi.example/api/domains/renew.json?api-key=' . self::SECRET_KEY;
        $client = $this->client([new RuntimeException('cURL error 28: Operation timed out for ' . $url), $this->ok()]);
        $r      = $this->renew($client);

        $this->assertSame(RenewalResponse::UNCERTAIN, $r->outcome);
        $this->assertSame('network_error', $r->errorCode);
        $this->assertNull($r->actionId);
        $this->assertCount(1, $this->posts, 'No automatic retry after a timeout.');
        $this->assertSame(0, $this->sleeps, 'No backoff sleep: there is no retry loop.');
        $this->assertSame(0, $this->gets);
        $this->assertStringNotContainsString(self::SECRET_KEY, (string) json_encode($r));
        $this->assertStringNotContainsString('timed out', (string) json_encode($r));
    }

    public function testEachCallSendsExactlyOneRequest(): void
    {
        $client = $this->client([$this->ok(), $this->ok(), $this->ok()]);

        $this->renew($client, 'KeepInvoice', '1001');
        $this->renew($client, 'NoInvoice', '1002');

        $this->assertCount(2, $this->posts);
    }

    // ---- static guarantees ----------------------------------------------------------

    public function testRenewDomainSourceHasNoRetryLoopSleepOrLogging(): void
    {
        $m      = new ReflectionMethod(ResellerClubClient::class, 'renewDomain');
        $lines  = file((string) $m->getFileName());
        $source = implode('', array_slice($lines, $m->getStartLine() - 1, $m->getEndLine() - $m->getStartLine() + 1));

        $this->assertDoesNotMatchRegularExpression('/\b(while|for|foreach|do)\b\s*[\({]/', $source);
        $this->assertDoesNotMatchRegularExpression('/sleep|retry|maxAttempts|log_message/i', $source);

        $classify = new ReflectionMethod(ResellerClubClient::class, 'classifyRenewal');
        $csource  = implode('', array_slice($lines, $classify->getStartLine() - 1, $classify->getEndLine() - $classify->getStartLine() + 1));

        $this->assertDoesNotMatchRegularExpression('/log_message|sleep|retry/i', $csource);
    }

    public function testNoDefaultTransportIsUsedInTheseTests(): void
    {
        // The developer's real .env may enable renewal execution, so the shared application
        // config is NEVER used here. The config is private and in-memory, the base URL is an
        // unroutable example host, and all three transports are fakes: the request must land in
        // the fake POST transport and nowhere else (no cURL client is ever constructed for use).
        $client = $this->client([$this->ok()]);

        $result = $this->renew($client, 'KeepInvoice', '987654');

        $this->assertCount(1, $this->posts, 'The one request must be captured by the fake POST transport.');
        $this->assertSame('https://test.httpapi.example/api/domains/renew.json', $this->posts[0][0]);
        $this->assertStringStartsWith('https://test.httpapi.example/', $this->posts[0][0], 'Never the production ResellerClub host.');
        $this->assertStringNotContainsString('resellerclub.com', $this->posts[0][0]);
        $this->assertSame('987654', $this->posts[0][1]['order-id']);
        $this->assertSame(0, $this->gets, 'The GET transport must stay unused.');
        $this->assertSame(0, $this->sleeps);
        $this->assertNotNull($result);
    }

    public function testDisabledExecutionBlocksBeforeAnyTransportEvenWithValidInput(): void
    {
        $client = $this->client([$this->ok()], ['renewalExecutionEnabled' => false]);

        try {
            $this->renew($client, 'KeepInvoice', '987654');
            $this->fail('execution_disabled expected');
        } catch (ResellerClubException $e) {
            $this->assertSame('execution_disabled', $e->getMessage());
        }

        $this->assertSame([], $this->posts);
        $this->assertSame(0, $this->gets);
    }

    public function testNoBackendTestBuildsAClientWithoutExplicitTransports(): void
    {
        // A client built from the config alone would use the real cURL transports. Forbid it
        // across the whole backend test tree (single-argument constructor calls).
        $offenders = [];
        $iterator  = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__), FilesystemIterator::SKIP_DOTS));

        foreach ($iterator as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php' || $file->getRealPath() === __FILE__) {
                continue;
            }

            $source = (string) file_get_contents($file->getRealPath());

            if (preg_match('/new\s+ResellerClubClient\(\s*[^,()]*(?:\([^()]*\))?\s*\)/', $source) === 1) {
                $offenders[] = $file->getFilename();
            }
        }

        $this->assertSame([], $offenders, 'These tests construct a ResellerClubClient with the default (real) transports.');
    }
}
