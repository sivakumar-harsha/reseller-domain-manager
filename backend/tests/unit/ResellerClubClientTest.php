<?php

declare(strict_types=1);

use App\Libraries\ResellerClub\ResellerClubClient;
use App\Libraries\ResellerClub\ResellerClubException;
use CodeIgniter\Test\CIUnitTestCase;
use Config\ResellerClub as ResellerClubConfig;
use Tests\Support\Libraries\IsolatedResellerClubConfig;

/**
 * Client behaviour against a fake transport. No network access.
 *
 * @internal
 */
final class ResellerClubClientTest extends CIUnitTestCase
{
    private const SECRET_KEY = 'sentinel-api-key-never-log';

    private function config(array $overrides = []): ResellerClubConfig
    {
        $c             = IsolatedResellerClubConfig::make();
        $c->baseUrl    = 'https://test.httpapi.example/api';
        $c->authUserId = '123456';
        $c->apiKey     = self::SECRET_KEY;
        $c->maxAttempts = 3;

        foreach ($overrides as $k => $v) {
            $c->{$k} = $v;
        }

        return $c;
    }

    /**
     * @param list<array{status: int, body: string}|Throwable> $script
     * @param list<array{0: string, 1: array<string, scalar>}> $calls
     */
    private function client(array $script, array &$calls = [], array &$sleeps = [], array $cfg = []): ResellerClubClient
    {
        return new ResellerClubClient(
            $this->config($cfg),
            static function (string $url, array $query) use (&$script, &$calls): array {
                $calls[] = [$url, $query];
                $next    = array_shift($script);

                if ($next instanceof Throwable) {
                    throw $next;
                }

                return $next;
            },
            static function (int $ms) use (&$sleeps): void {
                $sleeps[] = $ms;
            },
        );
    }

    private function ok(array $records, ?int $total = null): array
    {
        $body = ['recsonpage' => (string) count($records), 'recsindb' => (string) ($total ?? count($records))];

        foreach ($records as $i => $r) {
            $body[(string) ($i + 1)] = $r;
        }

        return ['status' => 200, 'body' => json_encode($body)];
    }

    private function failureCode(callable $fn): string
    {
        try {
            $fn();
        } catch (ResellerClubException $e) {
            return $e->getMessage();
        }

        $this->fail('Expected ResellerClubException');
    }

    public function testRequestIsAGetToTheSearchEndpointWithDocumentedParameters(): void
    {
        $calls  = [];
        $client = $this->client([$this->ok([['orders.orderid' => '1']])], $calls);

        $result = $client->searchDomains(3, 100);

        $this->assertSame(1, $result['total']);
        $this->assertSame([['orders.orderid' => '1']], $result['records']);
        $this->assertSame('https://test.httpapi.example/api/domains/search.json', $calls[0][0]);
        $this->assertSame(['auth-userid' => '123456', 'api-key' => self::SECRET_KEY, 'no-of-records' => 100, 'page-no' => 3, 'order-by' => 'orderid'], $calls[0][1]);
    }

    public function testRecordsComeBackInNumericKeyOrderAndEmptyPagesAreValid(): void
    {
        $body   = json_encode(['recsonpage' => '2', 'recsindb' => '2', '2' => ['n' => 'b'], '1' => ['n' => 'a']]);
        $client = $this->client([['status' => 200, 'body' => $body], $this->ok([], 0)]);

        $this->assertSame([['n' => 'a'], ['n' => 'b']], $client->searchDomains(1, 10)['records']);
        $this->assertSame(['total' => 0, 'records' => []], $client->searchDomains(2, 10));
    }

    public function testNotConfiguredNeverCallsTheTransport(): void
    {
        foreach ([['baseUrl' => 'http://insecure.example/api'], ['baseUrl' => ''], ['authUserId' => 'CHANGE_ME_AUTH_USER_ID'], ['apiKey' => ''], ['apiKey' => 'CHANGE_ME_API_KEY']] as $override) {
            $calls  = [];
            $client = $this->client([], $calls, cfg: $override);

            $this->assertFalse($client->isConfigured());
            $this->assertSame('not_configured', $this->failureCode(fn () => $client->searchDomains(1, 100)));
            $this->assertSame([], $calls);
        }
    }

    public function testTransientFailuresAreRetriedWithBackoffThenSucceed(): void
    {
        $calls = $sleeps = [];
        $client = $this->client([['status' => 503, 'body' => ''], new RuntimeException('connect to https://x?api-key=' . self::SECRET_KEY), $this->ok([])], $calls, $sleeps);

        $this->assertSame(0, $client->searchDomains(1, 10)['total']);
        $this->assertCount(3, $calls);
        $this->assertSame([500, 1000], $sleeps);
    }

    public function testRetriesAreBoundedAndErrorsCarryOnlyAFixedCode(): void
    {
        $calls = [];
        $client = $this->client([new RuntimeException('url with ' . self::SECRET_KEY), new RuntimeException('again'), new RuntimeException('again')], $calls);

        $code = $this->failureCode(fn () => $client->searchDomains(1, 10));

        $this->assertSame('network_error', $code);
        $this->assertCount(3, $calls);
        $this->assertStringNotContainsString(self::SECRET_KEY, $code);
    }

    public function testRateLimitIsRetriedButAuthFailuresAndProviderErrorsAreNot(): void
    {
        foreach ([[401, '', 'http_401'], [403, '', 'http_403'], [400, '{"status":"ERROR","message":"secret detail"}', 'provider_error'], [200, '{"status":"ERROR","message":"x"}', 'provider_error'], [404, 'not json', 'http_404']] as [$status, $body, $expected]) {
            $calls  = [];
            $client = $this->client([['status' => $status, 'body' => $body]], $calls);

            $this->assertSame($expected, $this->failureCode(fn () => $client->searchDomains(1, 10)), (string) $status);
            $this->assertCount(1, $calls);
        }

        $calls  = [];
        $client = $this->client(array_fill(0, 3, ['status' => 429, 'body' => '']), $calls);
        $this->assertSame('http_429', $this->failureCode(fn () => $client->searchDomains(1, 10)));
        $this->assertCount(3, $calls);
    }

    public function testRedirectsAreNeverFollowedOrTrusted(): void
    {
        $options = $this->client([])->httpOptions(30);

        $this->assertFalse($options['allow_redirects']);
        $this->assertTrue($options['verify']);
        $this->assertFalse($options['http_errors']);

        // Even a redirect that carries a plausible JSON body is an error, not data, and is not retried.
        foreach ([301, 302, 307, 308] as $status) {
            $calls  = [];
            $client = $this->client([['status' => $status, 'body' => '{"recsindb":"0"}']], $calls);

            $this->assertSame('http_' . $status, $this->failureCode(fn () => $client->searchDomains(1, 10)));
            $this->assertCount(1, $calls);
        }
    }

    public function testUnrecognisedResponsesFailClosed(): void
    {
        foreach (['', 'null', '[]', '"text"', '{"recsonpage":"1"}', '{"recsindb":"x"}', '{"recsindb":"-1"}', '{"recsindb":"1","1":"not-an-object"}'] as $body) {
            $client = $this->client([['status' => 200, 'body' => $body]]);

            $this->assertSame('malformed_response', $this->failureCode(fn () => $client->searchDomains(1, 10)), $body);
        }
    }
}
