<?php

declare(strict_types=1);

namespace App\Libraries\ResellerClub;

use Closure;
use Config\ResellerClub as ResellerClubConfig;
use Throwable;

/**
 * Read-only ResellerClub HTTP API client. The only operation is
 * domains/search.json (list Domain Registration orders); there are no write
 * methods, by design.
 *
 * Verified against ResellerClub's published reference (GET, auth-userid,
 * api-key, no-of-records 10-500, page-no, order-by, response keys
 * entity.description, orders.orderid, entity.customerid, orders.creationtime,
 * orders.endtime, entity.currentstatus, orders.resellerlock,
 * orders.privacyprotection, UNIX timestamps). That covers key names only: the
 * meaning of the lock fields (resellerlock, customerlock, transferlock) is NOT
 * verified and none is mapped to a stored value. Also NOT verified from the
 * documentation: the response envelope keys (recsonpage / recsindb), the
 * error body format and rate limits. The parser therefore fails closed on any
 * shape it does not recognise.
 *
 * The credentials travel in the query string because the API requires it. The
 * request URL is never logged or placed in an exception.
 */
class ResellerClubClient
{
    /** @var Closure(string, array<string, scalar>, int): array{status: int, body: string} */
    private Closure $transport;

    /** @var Closure(int): void */
    private Closure $sleep;

    /**
     * @param (callable(string, array<string, scalar>, int): array{status: int, body: string})|null $transport HTTP GET; defaults to CodeIgniter's cURL client
     * @param (callable(int): void)|null                                                              $sleep     milliseconds; defaults to usleep
     */
    public function __construct(private readonly ResellerClubConfig $config, ?callable $transport = null, ?callable $sleep = null)
    {
        $this->transport = Closure::fromCallable($transport ?? $this->curlTransport(...));
        $this->sleep     = Closure::fromCallable($sleep ?? static function (int $ms): void {
            usleep($ms * 1000);
        });
    }

    public function isConfigured(): bool
    {
        return str_starts_with($this->config->baseUrl, 'https://')
            && ! str_contains($this->config->baseUrl, 'CHANGE_ME')
            && preg_match('/^[0-9]{1,12}$/', $this->config->authUserId) === 1
            && $this->config->apiKey !== ''
            && ! str_contains($this->config->apiKey, 'CHANGE_ME');
    }

    /**
     * One page of domain registration orders, ordered by order id.
     *
     * @return array{total: int, records: list<array<string, mixed>>}
     *
     * @throws ResellerClubException
     */
    public function searchDomains(int $page, int $perPage): array
    {
        if (! $this->isConfigured()) {
            throw new ResellerClubException('not_configured');
        }

        $query = [
            'auth-userid'    => $this->config->authUserId,
            'api-key'        => $this->config->apiKey,
            'no-of-records'  => $perPage,
            'page-no'        => $page,
            'order-by'       => 'orderid',
        ];

        $attempt = 0;

        while (true) {
            $attempt++;

            try {
                return $this->parse($this->request('/domains/search.json', $query));
            } catch (ResellerClubException $e) {
                if (! $e->retryable || $attempt >= max(1, $this->config->maxAttempts)) {
                    throw $e;
                }

                ($this->sleep)(500 * (2 ** ($attempt - 1)));
            }
        }
    }

    /**
     * @param array<string, scalar> $query
     */
    private function request(string $path, array $query): string
    {
        try {
            $response = ($this->transport)(rtrim($this->config->baseUrl, '/') . $path, $query, $this->config->timeout);
        } catch (Throwable) {
            // Transport messages can contain the URL; keep only a fixed code.
            throw new ResellerClubException('network_error', true);
        }

        $status = $response['status'];

        if ($status === 429 || $status >= 500) {
            throw new ResellerClubException('http_' . $status, true);
        }

        if ($status === 401 || $status === 403) {
            throw new ResellerClubException('http_' . $status);
        }

        if ($status >= 300 && $status < 400) {
            // Redirects are never followed (the query string carries credentials) and never trusted.
            throw new ResellerClubException('http_' . $status);
        }

        if ($status < 200 || $status >= 300) {
            // 4xx may still carry a JSON error body; classify it below.
            $decoded = json_decode($response['body'], true);

            if (! is_array($decoded)) {
                throw new ResellerClubException('http_' . $status);
            }
        }

        return $response['body'];
    }

    /**
     * @return array{total: int, records: list<array<string, mixed>>}
     */
    private function parse(string $body): array
    {
        $data = json_decode($body, true);

        if (! is_array($data)) {
            throw new ResellerClubException('malformed_response');
        }

        if (strtoupper((string) ($data['status'] ?? '')) === 'ERROR') {
            // Provider message text is deliberately dropped.
            throw new ResellerClubException('provider_error');
        }

        if (! isset($data['recsindb']) || ! is_numeric($data['recsindb']) || (int) $data['recsindb'] < 0) {
            throw new ResellerClubException('malformed_response');
        }

        $records = [];

        foreach ($data as $key => $value) {
            if (is_int($key) || (is_string($key) && ctype_digit($key))) {
                if (! is_array($value)) {
                    throw new ResellerClubException('malformed_response');
                }

                $records[(int) $key] = $value;
            }
        }

        ksort($records);

        return ['total' => (int) $data['recsindb'], 'records' => array_values($records)];
    }

    /**
     * cURL options for the credential-bearing requests. Redirects are disabled so
     * the API key is never replayed to another URL (or downgraded to http).
     *
     * @return array<string, mixed>
     */
    public function httpOptions(int $timeout): array
    {
        return ['timeout' => $timeout, 'connect_timeout' => 10, 'http_errors' => false, 'verify' => true, 'allow_redirects' => false];
    }

    /**
     * @param array<string, scalar> $query
     *
     * @return array{status: int, body: string}
     */
    private function curlTransport(string $url, array $query, int $timeout): array
    {
        $client = service('curlrequest', $this->httpOptions($timeout), null, null, false);

        $response = $client->get($url, ['query' => $query, 'headers' => ['Accept' => 'application/json']]);

        return ['status' => $response->getStatusCode(), 'body' => (string) $response->getBody()];
    }
}
