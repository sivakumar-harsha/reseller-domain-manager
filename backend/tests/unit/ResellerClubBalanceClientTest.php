<?php

declare(strict_types=1);

use App\Libraries\ResellerClub\ResellerClubClient;
use App\Libraries\ResellerClub\ResellerClubException;
use CodeIgniter\Test\CIUnitTestCase;
use Config\ResellerClub as ResellerClubConfig;
use Tests\Support\Libraries\IsolatedResellerClubConfig;

/**
 * getBalance()/parseBalance() against a FAKE GET transport. No network, no
 * credentials, no POST transport at all. Every call must hit the transport
 * (the balance is never cached anywhere), and every unexpected shape must
 * fail closed with a fixed code.
 *
 * @internal
 */
final class ResellerClubBalanceClientTest extends CIUnitTestCase
{
    private const SECRET_KEY = 'sentinel-api-key-never-log';

    /** @var list<array{0: string, 1: array<string, scalar>}> */
    private array $calls = [];

    private function config(int $maxAttempts = 2): ResellerClubConfig
    {
        $c              = IsolatedResellerClubConfig::make();
        $c->baseUrl     = 'https://test.httpapi.example/api';
        $c->authUserId  = '123456';
        $c->apiKey      = self::SECRET_KEY;
        $c->maxAttempts = $maxAttempts;

        return $c;
    }

    /**
     * @param list<array{status: int, body: string}|Throwable> $script
     */
    private function client(array $script, ?ResellerClubConfig $config = null): ResellerClubClient
    {
        $this->calls = [];

        return new ResellerClubClient(
            $config ?? $this->config(),
            function (string $url, array $query) use (&$script): array {
                $this->calls[] = [$url, $query];
                $next          = array_shift($script);

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
        );
    }

    /** @param array<string, mixed> $overrides */
    private function body(array $overrides = []): array
    {
        return ['status' => 200, 'body' => (string) json_encode($overrides + [
            'sellingcurrencybalance'    => '6244.40',
            'sellingcurrencysymbol'     => 'USD',
            'accountingcurrencybalance' => '5000.6800',
            'accountingcurrencysymbol'  => 'INR',
            'sellingcurrencylockedbalance'    => 0.0,
            'accountingcurrencylockedbalance' => 0.0,
        ])];
    }

    public function testRealProviderResponseKeepsBothBalancesSeparateAndUnconverted(): void
    {
        $real = ['status' => 200, 'body' => (string) json_encode([
            'sellingcurrencysymbol'           => 'USD',
            'sellingcurrencybalance'          => 2920.68,
            'sellingcurrencylockedbalance'    => 0.0,
            'accountingcurrencybalance'       => 273867.87,
            'accountingcurrencylockedbalance' => 0.0,
            'accountingcurrencysymbol'        => 'INR',
        ])];

        $this->assertSame([
            'selling_balance'           => '2920.68',
            'selling_currency'          => 'USD',
            'selling_locked_balance'    => '0',
            'accounting_balance'        => '273867.87',
            'accounting_currency'       => 'INR',
            'accounting_locked_balance' => '0',
        ], $this->client([$real])->getBalance());
    }

    public function testLockedBalancesAreKeptAndMissingOnesAreNullNotZero(): void
    {
        $b = $this->client([$this->body(['sellingcurrencylockedbalance' => 12.5, 'accountingcurrencylockedbalance' => '1,000.25'])])->getBalance();
        $this->assertSame('12.5', $b['selling_locked_balance']);
        $this->assertSame('1000.25', $b['accounting_locked_balance']);

        $raw  = json_decode($this->body()['body'], true);
        unset($raw['sellingcurrencylockedbalance'], $raw['accountingcurrencylockedbalance']);
        $b = $this->client([['status' => 200, 'body' => (string) json_encode($raw)]])->getBalance();
        $this->assertNull($b['selling_locked_balance']);
        $this->assertNull($b['accounting_locked_balance']);
        $this->assertSame('6244.40', $b['selling_balance']);
    }

    public function testInvalidLockedBalanceRejectsTheResponse(): void
    {
        $this->expectException(ResellerClubException::class);
        $this->expectExceptionMessage('malformed_response');

        $this->client([$this->body(['accountingcurrencylockedbalance' => 'abc'])])->getBalance();
    }

    public function testParsesTheFourVerifiedFieldsAndSendsTheResellerId(): void
    {
        $balance = $this->client([$this->body()])->getBalance();

        $this->assertSame([
            'selling_balance'           => '6244.40',
            'selling_currency'          => 'USD',
            'selling_locked_balance'    => '0',
            'accounting_balance'        => '5000.6800',
            'accounting_currency'       => 'INR',
            'accounting_locked_balance' => '0',
        ], $balance);

        $this->assertCount(1, $this->calls);
        $this->assertSame('https://test.httpapi.example/api/billing/reseller-balance.json', $this->calls[0][0]);
        $this->assertSame('123456', (string) $this->calls[0][1]['reseller-id']);
    }

    public function testEveryCallFetchesFreshNothingIsCached(): void
    {
        $client = $this->client([$this->body(), $this->body(['accountingcurrencybalance' => '1.00'])]);

        $first  = $client->getBalance();
        $second = $client->getBalance();

        $this->assertCount(2, $this->calls);
        $this->assertSame('5000.6800', $first['accounting_balance']);
        $this->assertSame('1.00', $second['accounting_balance']);
    }

    public function testThousandsSeparatorsAreToleratedAndStripped(): void
    {
        $balance = $this->client([$this->body(['accountingcurrencybalance' => '2,920.6800'])])->getBalance();

        $this->assertSame('2920.6800', $balance['accounting_balance']);
    }

    public function testNegativeAndNumericAmountsAreAccepted(): void
    {
        $balance = $this->client([$this->body(['sellingcurrencybalance' => -12.5, 'accountingcurrencybalance' => 100])])->getBalance();

        $this->assertSame('-12.5', $balance['selling_balance']);
        $this->assertSame('100', $balance['accounting_balance']);
    }

    /** @return array<string, array{0: array<string, mixed>}> */
    public static function malformedBodies(): array
    {
        return [
            'missing balance field'   => [['accountingcurrencybalance' => null]],
            'empty symbol'            => [['accountingcurrencysymbol' => '']],
            'overlong symbol'         => [['accountingcurrencysymbol' => 'AAAABBBBC']],
            'non-string symbol'       => [['accountingcurrencysymbol' => 42]],
            'five decimals'           => [['accountingcurrencybalance' => '1.23456']],
            'not a number'            => [['accountingcurrencybalance' => 'abc']],
            'array amount'            => [['sellingcurrencybalance' => ['1.00']]],
        ];
    }

    /**
     * @dataProvider malformedBodies
     *
     * @param array<string, mixed> $overrides
     */
    public function testMalformedBalanceResponsesFailClosed(array $overrides): void
    {
        $this->expectException(ResellerClubException::class);
        $this->expectExceptionMessage('malformed_response');

        $this->client([$this->body($overrides)])->getBalance();
    }

    public function testNonJsonBodyFailsClosed(): void
    {
        $this->expectException(ResellerClubException::class);
        $this->expectExceptionMessage('malformed_response');

        $this->client([['status' => 200, 'body' => 'not json']])->getBalance();
    }

    public function testProviderErrorBodyThrowsAFixedCodeWithoutProviderText(): void
    {
        try {
            $this->client([['status' => 200, 'body' => (string) json_encode(['status' => 'ERROR', 'message' => 'secret detail'])]])->getBalance();
            $this->fail('Expected ResellerClubException');
        } catch (ResellerClubException $e) {
            $this->assertSame('provider_error', $e->getMessage());
            $this->assertStringNotContainsString('secret', $e->getMessage());
        }
    }

    public function testServerErrorIsRetriedOnceThenSucceeds(): void
    {
        $balance = $this->client([['status' => 500, 'body' => ''], $this->body()])->getBalance();

        $this->assertSame('INR', $balance['accounting_currency']);
        $this->assertCount(2, $this->calls);
    }

    public function testAtMostTwoAttemptsEvenWhenConfiguredHigher(): void
    {
        try {
            $this->client([['status' => 500, 'body' => ''], ['status' => 500, 'body' => '']], $this->config(9))->getBalance();
            $this->fail('Expected ResellerClubException');
        } catch (ResellerClubException $e) {
            $this->assertSame('http_500', $e->getMessage());
        }

        $this->assertCount(2, $this->calls);
    }

    public function testAuthFailureIsNeverRetried(): void
    {
        try {
            $this->client([['status' => 403, 'body' => '{}']])->getBalance();
            $this->fail('Expected ResellerClubException');
        } catch (ResellerClubException $e) {
            $this->assertSame('http_403', $e->getMessage());
        }

        $this->assertCount(1, $this->calls);
    }

    public function testUnconfiguredClientRefusesWithoutAnyRequest(): void
    {
        $config         = $this->config();
        $config->apiKey = '';

        try {
            $this->client([], $config)->getBalance();
            $this->fail('Expected ResellerClubException');
        } catch (ResellerClubException $e) {
            $this->assertSame('not_configured', $e->getMessage());
        }

        $this->assertSame([], $this->calls);
    }
}
