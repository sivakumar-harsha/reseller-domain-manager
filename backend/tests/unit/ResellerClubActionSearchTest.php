<?php

declare(strict_types=1);

use App\Libraries\ResellerClub\ResellerClubClient;
use App\Libraries\ResellerClub\ResellerClubException;
use CodeIgniter\Test\CIUnitTestCase;
use Config\ResellerClub as ResellerClubConfig;
use Tests\Support\Libraries\IsolatedResellerClubConfig;

/**
 * The read-only action lookups (actions/search-current.json and
 * actions/search-archived.json) against a FAKE GET transport. No network
 * access, and the POST transport fails the test if it is ever used.
 *
 * @internal
 */
final class ResellerClubActionSearchTest extends CIUnitTestCase
{
    private const SECRET_KEY = 'sentinel-api-key-never-store';

    /** @var list<array{0: string, 1: array<string, scalar>}> */
    private array $gets = [];

    private int $posts = 0;

    private int $sleeps = 0;

    /**
     * @param list<array{status: int, body: string}|Throwable> $script
     * @param array<string, mixed>                             $cfg
     */
    private function client(array $script, array $cfg = []): ResellerClubClient
    {
        $c                          = IsolatedResellerClubConfig::make();
        $c->baseUrl                 = 'https://test.httpapi.example/api';
        $c->authUserId              = '123456';
        $c->apiKey                  = self::SECRET_KEY;
        $c->maxAttempts             = 3;
        $c->renewalExecutionEnabled = true; // in-memory only; a renewal POST would still fail this test

        foreach ($cfg as $k => $v) {
            $c->{$k} = $v;
        }

        return new ResellerClubClient(
            $c,
            function (string $url, array $query) use (&$script): array {
                $this->gets[] = [$url, $query];
                $next         = array_shift($script);

                if ($next === null) {
                    throw new RuntimeException('unexpected provider GET');
                }

                if ($next instanceof Throwable) {
                    throw $next;
                }

                return $next;
            },
            function (): void {
                $this->sleeps++;
            },
            function (): array {
                $this->posts++;

                throw new RuntimeException('a POST must never be sent by an action lookup');
            },
        );
    }

    /** @param list<array<string, mixed>> $records */
    private function envelope(array $records): array
    {
        $body = ['recsonpage' => (string) count($records), 'recsindb' => (string) count($records)];

        foreach ($records as $i => $r) {
            $body[(string) ($i + 1)] = $r;
        }

        return ['status' => 200, 'body' => (string) json_encode($body)];
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function lookups(): array
    {
        return [
            'current'  => ['searchCurrentActions', '/actions/search-current.json'],
            'archived' => ['searchArchivedActions', '/actions/search-archived.json'],
        ];
    }

    /** @dataProvider lookups */
    public function testLookupIsOneGetWithTheDocumentedParametersOnly(string $method, string $path): void
    {
        $record = ['eaqid' => '111222', 'actionstatus' => 'Success', 'actionstatusdesc' => 'Domain renewed successfully'];
        $client = $this->client([$this->envelope([$record])]);

        $this->assertSame([$record], $client->{$method}('111222'));

        $this->assertCount(1, $this->gets);
        $this->assertSame('https://test.httpapi.example/api' . $path, $this->gets[0][0]);
        $this->assertSame(
            ['auth-userid' => '123456', 'api-key' => self::SECRET_KEY, 'eaq-id' => '111222', 'no-of-records' => 10, 'page-no' => 1],
            $this->gets[0][1],
        );
        $this->assertSame(0, $this->posts);
    }

    /** @dataProvider lookups */
    public function testActionIdIsSentAsEaqIdAndNeverAsEaqid(string $method, string $path): void
    {
        $this->client([$this->envelope([])])->{$method}('111222');

        $this->assertCount(1, $this->gets);
        $this->assertStringEndsWith($path, $this->gets[0][0]);
        $this->assertArrayHasKey('eaq-id', $this->gets[0][1]);
        $this->assertSame('111222', $this->gets[0][1]['eaq-id']);
        $this->assertArrayNotHasKey('eaqid', $this->gets[0][1], 'eaqid is the RESPONSE field, not the request parameter.');
    }

    /** @dataProvider lookups */
    public function testEmptyResultIsAnEmptyListNotAnError(string $method): void
    {
        $this->assertSame([], $this->client([$this->envelope([])])->{$method}('5'));
    }

    /** @dataProvider lookups */
    public function testInvalidActionIdIsRefusedBeforeAnyRequest(string $method): void
    {
        foreach (['', 'abc', '12 3', '1;2', str_repeat('1', 21), '-1'] as $bad) {
            try {
                $this->client([])->{$method}($bad);
                $this->fail('Expected refusal for ' . $bad);
            } catch (ResellerClubException $e) {
                $this->assertSame('invalid_action_id', $e->getMessage());
            }
        }

        $this->assertSame([], $this->gets);
    }

    /** @dataProvider lookups */
    public function testUnconfiguredClientRefusesBeforeAnyRequest(string $method): void
    {
        try {
            $this->client([], ['apiKey' => ''])->{$method}('1');
            $this->fail('Expected not_configured');
        } catch (ResellerClubException $e) {
            $this->assertSame('not_configured', $e->getMessage());
        }

        $this->assertSame([], $this->gets);
    }

    /** @dataProvider lookups */
    public function testProviderErrorAndMalformedBodiesFailClosedAndDropProviderText(string $method): void
    {
        foreach ([
            ['{"status":"ERROR","message":"SENTINEL secret"}', 'provider_error'],
            ['<html>', 'malformed_response'],
            ['{"recsonpage":"1"}', 'malformed_response'],
        ] as [$body, $code]) {
            try {
                $this->client([['status' => 200, 'body' => $body]])->{$method}('1');
                $this->fail('Expected ' . $code);
            } catch (ResellerClubException $e) {
                $this->assertSame($code, $e->getMessage());
                $this->assertStringNotContainsString('SENTINEL', $e->getMessage());
            }
        }
    }

    /** @dataProvider lookups */
    public function testServerErrorsAreRetriedAsReadsAndThenFail(string $method): void
    {
        $client = $this->client([['status' => 503, 'body' => ''], ['status' => 503, 'body' => ''], ['status' => 503, 'body' => '']]);

        try {
            $client->{$method}('1');
            $this->fail('Expected http_503');
        } catch (ResellerClubException $e) {
            $this->assertSame('http_503', $e->getMessage());
        }

        $this->assertCount(3, $this->gets);
        $this->assertSame(0, $this->posts);
    }

    /** @dataProvider lookups */
    public function testTransportTimeoutIsAFixedCodeWithoutTheUrl(string $method): void
    {
        $client = $this->client([new RuntimeException('timeout for https://x?api-key=' . self::SECRET_KEY)], ['maxAttempts' => 1]);

        try {
            $client->{$method}('1');
            $this->fail('Expected network_error');
        } catch (ResellerClubException $e) {
            $this->assertSame('network_error', $e->getMessage());
            $this->assertStringNotContainsString(self::SECRET_KEY, $e->getMessage());
        }
    }
}
