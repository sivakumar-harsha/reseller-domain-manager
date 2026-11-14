<?php

declare(strict_types=1);

namespace App\Libraries\ResellerClub;

use Closure;
use Config\ResellerClub as ResellerClubConfig;
use Throwable;

/**
 * ResellerClub HTTP API client. Read operations: domains/search.json (list
 * Domain Registration orders), domains/details.json, billing/reseller-balance.json
 * products/reseller-cost-price.json and the read-only action lookups
 * (actions/search-current.json, actions/search-archived.json). The ONLY write operation is
 * renewDomain() (POST domains/renew.json), which is refused unless the
 * renewal execution switch is on, is never retried and uses its own transport.
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

    /** @var Closure(string, array<string, scalar>, int): array{status: int, body: string} */
    private Closure $postTransport;

    /**
     * @param (callable(string, array<string, scalar>, int): array{status: int, body: string})|null $transport     HTTP GET; defaults to CodeIgniter's cURL client
     * @param (callable(int): void)|null                                                              $sleep         milliseconds; defaults to usleep
     * @param (callable(string, array<string, scalar>, int): array{status: int, body: string})|null $postTransport HTTP POST (form body); defaults to CodeIgniter's cURL client
     */
    public function __construct(private readonly ResellerClubConfig $config, ?callable $transport = null, ?callable $sleep = null, ?callable $postTransport = null)
    {
        $this->transport     = Closure::fromCallable($transport ?? $this->curlTransport(...));
        $this->postTransport = Closure::fromCallable($postTransport ?? $this->curlPostTransport(...));
        $this->sleep         = Closure::fromCallable($sleep ?? static function (int $ms): void {
            usleep($ms * 1000);
        });
    }

    /** The two ResellerClub invoice options this application supports, exact case. */
    public const INVOICE_OPTIONS = ['KeepInvoice', 'NoInvoice'];

    /**
     * Renews one Domain Registration order: POST domains/renew.json with the
     * documented parameters auth-userid, api-key, order-id, years, exp-date
     * (current expiry, epoch seconds), auto-renew (required boolean) and
     * invoice-option. Credentials and parameters travel in the form body, never
     * in the URL.
     *
     * Safety contract:
     * - Everything is validated, and the execution switch checked, BEFORE any
     *   request. A problem there throws a ResellerClubException; nothing was sent.
     * - After the request is sent this method never throws: the result is a
     *   RenewalResponse (succeeded / failed / uncertain).
     * - The request is sent AT MOST ONCE. There is no retry loop and no sleep.
     * - Nothing about the request or response is logged.
     *
     * @throws ResellerClubException execution_disabled | not_configured | invalid_renewal_request (nothing was sent)
     */
    public function renewDomain(string $orderId, int $years, int $expiryEpoch, bool $autoRenew, string $invoiceOption): RenewalResponse
    {
        if (! $this->config->renewalExecutionEnabled) {
            throw new ResellerClubException('execution_disabled');
        }

        if (! $this->isConfigured()) {
            throw new ResellerClubException('not_configured');
        }

        if (preg_match('/^[1-9][0-9]{0,11}$/', $orderId) !== 1
            || $years < 1 || $years > 10
            || $expiryEpoch < 946684800 || $expiryEpoch > 4102444800
            || ! in_array($invoiceOption, self::INVOICE_OPTIONS, true)) {
            throw new ResellerClubException('invalid_renewal_request');
        }

        $form = [
            'auth-userid'    => $this->config->authUserId,
            'api-key'        => $this->config->apiKey,
            'order-id'       => $orderId,
            'years'          => $years,
            'exp-date'       => $expiryEpoch,
            'auto-renew'     => $autoRenew ? 'true' : 'false',
            'invoice-option' => $invoiceOption,
        ];

        try {
            $response = ($this->postTransport)(rtrim($this->config->baseUrl, '/') . '/domains/renew.json', $form, min(30, $this->config->timeout));
        } catch (Throwable) {
            // Timeout, connection reset, TLS failure: the provider may have processed the request.
            return RenewalResponse::uncertain('network_error');
        }

        return $this->classifyRenewal($response);
    }

    /**
     * @param array{status: int, body: string} $response
     */
    private function classifyRenewal(array $response): RenewalResponse
    {
        $status = $response['status'];
        $data   = json_decode($response['body'], true);

        if ($status < 200 || $status >= 300) {
            // 4xx other than 408/429: the provider refused the request before acting.
            if ($status >= 400 && $status < 500 && $status !== 408 && $status !== 429) {
                return RenewalResponse::failed('http_' . $status, null, null, $this->providerMessage($data));
            }

            // 3xx (never followed), 408, 429 and 5xx: the outcome is unknown.
            return RenewalResponse::uncertain('http_' . $status);
        }

        if (! is_array($data) || $data === []) {
            return RenewalResponse::uncertain('malformed_response');
        }

        if (strtoupper((string) ($data['status'] ?? '')) === 'ERROR') {
            // The provider's explanation is kept (sanitised, capped) so the app can show it.
            return RenewalResponse::failed('provider_rejected', null, null, $this->providerMessage($data));
        }

        $actionId = $this->digits($data['eaqid'] ?? null, 20);
        $action   = $this->token($data['actionstatus'] ?? null);

        if ($actionId === null || $action === null) {
            return RenewalResponse::uncertain('malformed_response');
        }

        $description = $this->text($data['actionstatusdesc'] ?? null);
        $invoiceId   = $this->digits($data['invoiceid'] ?? null, 20);

        // Exact matching only; HTTP 200 alone never means success.
        if (in_array($action, $this->config->renewalSuccessStatuses, true)) {
            return RenewalResponse::succeeded($actionId, $action, $description, $invoiceId);
        }

        if (in_array($action, $this->config->renewalFailureStatuses, true)) {
            return RenewalResponse::failed('provider_action_failed', $actionId, $action, $description);
        }

        // Accepted, but the status is not a verified success/failure value.
        return RenewalResponse::uncertain('status_unrecognised', $actionId, $action, $description, $invoiceId);
    }

    /**
     * The provider's own error text from an error body, or null. The key names
     * (`message`, then `error`) are the ones ResellerClub's error bodies are
     * generally reported to use; they are NOT confirmed by its documentation,
     * so an unrecognised shape simply yields no message.
     */
    private function providerMessage(mixed $data): ?string
    {
        if (! is_array($data)) {
            return null;
        }

        return $this->text($data['message'] ?? null) ?? $this->text($data['error'] ?? null);
    }

    private function digits(mixed $value, int $max): ?string
    {
        if (! is_string($value) && ! is_int($value)) {
            return null;
        }

        return preg_match('/^[0-9]{1,' . $max . '}$/', (string) $value) === 1 ? (string) $value : null;
    }

    /** A short alphanumeric status word, or null. */
    private function token(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return preg_match('/^[A-Za-z0-9_ -]{1,32}$/', $value) === 1 ? $value : null;
    }

    /** Provider text kept only in memory: control characters removed, length capped. */
    private function text(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $clean = trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value));

        // A credential must never be stored even if a provider echoes it back.
        $clean = str_replace([$this->config->apiKey], '[hidden]', $clean);

        return $clean === '' ? null : mb_substr($clean, 0, 200);
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
     * One order's details (domains/details.json, options=All). The decoded
     * body is returned as is for the enrichment service to whitelist from; it
     * must never be stored, logged or returned to a client. Same retry policy
     * as searchDomains().
     *
     * @return array<string, mixed>
     *
     * @throws ResellerClubException
     */
    public function domainDetails(string $orderId): array
    {
        if (! $this->isConfigured()) {
            throw new ResellerClubException('not_configured');
        }

        $query = [
            'auth-userid' => $this->config->authUserId,
            'api-key'     => $this->config->apiKey,
            'order-id'    => $orderId,
            'options'     => 'All',
        ];

        $attempt = 0;

        while (true) {
            $attempt++;

            try {
                return $this->parseDetails($this->request('/domains/details.json', $query));
            } catch (ResellerClubException $e) {
                if (! $e->retryable || $attempt >= max(1, $this->config->maxAttempts)) {
                    throw $e;
                }

                ($this->sleep)(500 * (2 ** ($attempt - 1)));
            }
        }
    }

    /**
     * The reseller's own available balance (billing/reseller-balance.json, with
     * the configured auth user id as reseller-id). Only the six verified fields
     * (selling and accounting balance, symbol and locked balance) are kept,
     * separately and never converted; the raw response is never stored, logged
     * or returned. Same retry policy as searchDomains().
     *
     * @return array{selling_balance: string, selling_currency: string, selling_locked_balance: ?string, accounting_balance: string, accounting_currency: string, accounting_locked_balance: ?string}
     *
     * @throws ResellerClubException
     */
    public function getBalance(): array
    {
        // Worst case 2 x 15 s + 0.5 s backoff = 30.5 s, inside the app's 45 s timeout.
        $maxAttempts = min(2, max(1, $this->config->maxAttempts));
        $timeout     = min(15, $this->config->timeout);

        if (! $this->isConfigured()) {
            throw new ResellerClubException('not_configured');
        }

        $query = [
            'auth-userid' => $this->config->authUserId,
            'api-key'     => $this->config->apiKey,
            'reseller-id' => $this->config->authUserId,
        ];

        $attempt = 0;

        while (true) {
            $attempt++;

            try {
                return $this->parseBalance($this->request('/billing/reseller-balance.json', $query, $timeout));
            } catch (ResellerClubException $e) {
                if (! $e->retryable || $attempt >= $maxAttempts) {
                    throw $e;
                }

                ($this->sleep)(500 * (2 ** ($attempt - 1)));
            }
        }
    }

    /**
     * The reseller's own cost price list (products/reseller-cost-price.json, GET,
     * auth-userid and api-key only). The response covers every product, keyed by
     * product key, then action, then tenure; it is large and slow, so callers
     * must cache what they extract. The decoded body is returned as is and must
     * never be stored whole, logged or returned to a client. It carries no
     * currency. Same retry policy as getBalance(), with a longer timeout because
     * of the size.
     *
     * @return array<string, mixed>
     *
     * @throws ResellerClubException
     */
    public function resellerCostPrices(): array
    {
        // Worst case 2 x 20 s + 0.5 s backoff = 40.5 s, inside the app's 45 s timeout.
        $maxAttempts = min(2, max(1, $this->config->maxAttempts));
        $timeout     = min(20, $this->config->timeout);

        if (! $this->isConfigured()) {
            throw new ResellerClubException('not_configured');
        }

        $query = [
            'auth-userid' => $this->config->authUserId,
            'api-key'     => $this->config->apiKey,
        ];

        $attempt = 0;

        while (true) {
            $attempt++;

            try {
                return $this->parsePriceList($this->request('/products/reseller-cost-price.json', $query, $timeout));
            } catch (ResellerClubException $e) {
                if (! $e->retryable || $attempt >= $maxAttempts) {
                    throw $e;
                }

                ($this->sleep)(500 * (2 ** ($attempt - 1)));
            }
        }
    }

    /**
     * Read-only lookup of ONE action by its id in the CURRENT actions list
     * (GET actions/search-current.json). Only the paging parameters and the
     * action id are sent; order and action type are verified locally by the
     * caller against the returned records. The current list is not a complete
     * history, so an empty result never proves that an action did not happen.
     *
     * @return list<array<string, mixed>>
     *
     * @throws ResellerClubException
     */
    public function searchCurrentActions(string $actionId): array
    {
        return $this->searchActions('/actions/search-current.json', $actionId);
    }

    /**
     * Read-only lookup of ONE action by its id in the ARCHIVED actions list
     * (GET actions/search-archived.json). Same rules as searchCurrentActions().
     *
     * @return list<array<string, mixed>>
     *
     * @throws ResellerClubException
     */
    public function searchArchivedActions(string $actionId): array
    {
        return $this->searchActions('/actions/search-archived.json', $actionId);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function searchActions(string $path, string $actionId): array
    {
        if (! $this->isConfigured()) {
            throw new ResellerClubException('not_configured');
        }

        if (preg_match('/^[0-9]{1,20}$/', $actionId) !== 1) {
            throw new ResellerClubException('invalid_action_id');
        }

        $query = [
            'auth-userid'   => $this->config->authUserId,
            'api-key'       => $this->config->apiKey,
            'eaq-id'        => $actionId,
            'no-of-records' => 10,
            'page-no'       => 1,
        ];

        $attempt = 0;

        while (true) {
            $attempt++;

            try {
                return $this->parse($this->request($path, $query))['records'];
            } catch (ResellerClubException $e) {
                if (! $e->retryable || $attempt >= max(1, $this->config->maxAttempts)) {
                    throw $e;
                }

                ($this->sleep)(500 * (2 ** ($attempt - 1)));
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function parsePriceList(string $body): array
    {
        $data = json_decode($body, true);

        if (! is_array($data) || $data === []) {
            throw new ResellerClubException('malformed_response');
        }

        if (strtoupper((string) ($data['status'] ?? '')) === 'ERROR') {
            // Provider message text is deliberately dropped.
            throw new ResellerClubException('provider_error');
        }

        return $data;
    }

    /**
     * @return array{selling_balance: string, selling_currency: string, selling_locked_balance: ?string, accounting_balance: string, accounting_currency: string, accounting_locked_balance: ?string}
     */
    private function parseBalance(string $body): array
    {
        $data = json_decode($body, true);

        if (! is_array($data)) {
            throw new ResellerClubException('malformed_response');
        }

        if (strtoupper((string) ($data['status'] ?? '')) === 'ERROR') {
            // Provider message text is deliberately dropped.
            throw new ResellerClubException('provider_error');
        }

        $amount = static function (mixed $value): string {
            if (! is_string($value) && ! is_int($value) && ! is_float($value)) {
                throw new ResellerClubException('malformed_response');
            }

            // Tolerate thousands separators; require a plain decimal afterwards.
            $clean = str_replace(',', '', trim((string) $value));

            if (preg_match('/^-?[0-9]{1,15}(\.[0-9]{1,4})?$/', $clean) !== 1) {
                throw new ResellerClubException('malformed_response');
            }

            return $clean;
        };

        $symbol = static function (mixed $value): string {
            $value = is_string($value) ? trim($value) : '';

            if ($value === '' || mb_strlen($value) > 8) {
                throw new ResellerClubException('malformed_response');
            }

            return $value;
        };

        // Locked balances: absent -> null (never shown as 0); present but
        // invalid -> the whole response is rejected.
        $locked = static fn (string $key): ?string => ! array_key_exists($key, $data) || $data[$key] === null
            ? null
            : $amount($data[$key]);

        return [
            'selling_balance'            => $amount($data['sellingcurrencybalance'] ?? null),
            'selling_currency'           => $symbol($data['sellingcurrencysymbol'] ?? null),
            'selling_locked_balance'     => $locked('sellingcurrencylockedbalance'),
            'accounting_balance'         => $amount($data['accountingcurrencybalance'] ?? null),
            'accounting_currency'        => $symbol($data['accountingcurrencysymbol'] ?? null),
            'accounting_locked_balance'  => $locked('accountingcurrencylockedbalance'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function parseDetails(string $body): array
    {
        $data = json_decode($body, true);

        if (! is_array($data)) {
            throw new ResellerClubException('malformed_response');
        }

        if (strtoupper((string) ($data['status'] ?? '')) === 'ERROR') {
            // Provider message text is deliberately dropped.
            throw new ResellerClubException('provider_error');
        }

        if (! isset($data['orderid']) || ! is_scalar($data['orderid'])) {
            throw new ResellerClubException('malformed_response');
        }

        return $data;
    }

    /**
     * @param array<string, scalar> $query
     */
    private function request(string $path, array $query, ?int $timeout = null): string
    {
        try {
            $response = ($this->transport)(rtrim($this->config->baseUrl, '/') . $path, $query, $timeout ?? $this->config->timeout);
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
     * @param array<string, scalar> $form
     *
     * @return array{status: int, body: string}
     */
    private function curlPostTransport(string $url, array $form, int $timeout): array
    {
        $client = service('curlrequest', $this->httpOptions($timeout), null, null, false);

        $response = $client->post($url, ['form_params' => $form, 'headers' => ['Accept' => 'application/json']]);

        return ['status' => $response->getStatusCode(), 'body' => (string) $response->getBody()];
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
