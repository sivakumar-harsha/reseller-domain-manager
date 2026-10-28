<?php

declare(strict_types=1);

use App\Filters\ThrottleFilter;
use App\Libraries\Auth\AuthService;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Services;
use Tests\Support\Database\UsesIsolatedAuthDatabase;

/**
 * HTTP-level authentication tests against the isolated test database.
 *
 * @internal
 */
final class AuthEndpointsTest extends CIUnitTestCase
{
    use FeatureTestTrait;
    use UsesIsolatedAuthDatabase;

    private const EMAIL    = 'owner@example.test';
    private const PASSWORD = 'Sentinel-Pass-123!';

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpIsolatedAuthDatabase();

        $this->resetThrottleBuckets();
        Services::resetSingle('authService');

        $this->insertUser(['email' => self::EMAIL, 'password_hash' => password_hash(self::PASSWORD, PASSWORD_DEFAULT)]);
    }

    protected function tearDown(): void
    {
        Services::resetSingle('authService');
        $this->resetThrottleBuckets();

        parent::tearDown();
    }

    /** Per-IP buckets live in the application cache; start and end every test empty. */
    private function resetThrottleBuckets(): void
    {
        $throttler = service('throttler');

        foreach (array_keys(config('Auth')->ipLimits) as $bucket) {
            foreach (['0.0.0.0', '127.0.0.1', '::1'] as $ip) {
                $throttler->remove(ThrottleFilter::key($bucket, $ip));
            }
        }
    }

    private function isNotFound(callable $request): bool
    {
        try {
            return $request()->response()->getStatusCode() === 404;
        } catch (PageNotFoundException) {
            return true;
        }
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array{0: int, 1: array<string, mixed>, 2: \CodeIgniter\HTTP\ResponseInterface}
     */
    private function postJson(string $path, array $body = [], array $headers = []): array
    {
        $result = $this->withHeaders($headers)->withBodyFormat('json')->post('api/v1/' . $path, $body);

        return [$result->response()->getStatusCode(), json_decode((string) $result->response()->getBody(), true) ?? [], $result->response()];
    }

    /**
     * @return array<string, mixed>
     */
    private function loginTokens(): array
    {
        [$status, $body] = $this->postJson('auth/login', ['email' => self::EMAIL, 'password' => self::PASSWORD]);
        $this->assertSame(200, $status);

        return $body;
    }

    private function me(?string $token): int
    {
        $result = $this->withHeaders($token === null ? [] : ['Authorization' => 'Bearer ' . $token])->get('api/v1/auth/me');

        return $result->response()->getStatusCode();
    }

    public function testLoginSucceedsAndResponseIsNotCacheable(): void
    {
        [$status, $body, $response] = $this->postJson('auth/login', ['email' => self::EMAIL, 'password' => self::PASSWORD, 'device_label' => 'Pixel']);

        $this->assertSame(200, $status);
        $this->assertSame(['token_type', 'access_token', 'expires_in', 'refresh_token', 'refresh_expires_in'], array_keys($body));
        $this->assertSame(900, $body['expires_in']);
        $this->assertStringContainsString('no-store', $response->getHeaderLine('Cache-Control'));
    }

    public function testWrongPasswordAndUnknownEmailGiveTheSameGenericAnswer(): void
    {
        [$s1, $b1] = $this->postJson('auth/login', ['email' => self::EMAIL, 'password' => 'wrong password 1']);
        [$s2, $b2] = $this->postJson('auth/login', ['email' => 'nobody@example.test', 'password' => 'wrong password 1']);

        $this->assertSame(401, $s1);
        $this->assertSame(401, $s2);
        $this->assertSame(['error' => 'invalid_credentials'], $b1);
        $this->assertSame($b1, $b2);
    }

    public function testMalformedRequestsAreRejectedWith400(): void
    {
        $this->assertSame(400, $this->withBodyFormat('json')->withBody('{not json')->post('api/v1/auth/login')->response()->getStatusCode());
        $this->assertSame(400, $this->postJson('auth/login', [])[0]);
        $this->assertSame(400, $this->postJson('auth/login', ['email' => self::EMAIL])[0]);
        $this->assertSame(400, $this->postJson('auth/login', ['email' => ['a'], 'password' => 'x'])[0]);
        $this->assertSame(400, $this->postJson('auth/login', ['email' => self::EMAIL, 'password' => str_repeat('x', 5000)])[0]);
        $this->assertSame(400, $this->postJson('auth/refresh', [])[0]);
    }

    public function testLoginIsPostOnly(): void
    {
        $this->assertTrue($this->isNotFound(fn () => $this->get('api/v1/auth/login')));
        $this->assertTrue($this->isNotFound(fn () => $this->get('api/v1/auth/refresh')));
        $this->assertTrue($this->isNotFound(fn () => $this->get('api/v1/auth/logout')));
    }

    public function testProtectedRouteNeedsAValidAccessToken(): void
    {
        $tokens = $this->loginTokens();

        $this->assertSame(401, $this->me(null));
        $this->assertSame(401, $this->me('short'));
        $this->assertSame(401, $this->me(str_repeat('A', 43)));
        $this->assertSame(401, $this->me($tokens['refresh_token']), 'A refresh token must not work as an access token.');
        $this->assertSame(200, $this->me($tokens['access_token']));

        $result = $this->get('api/v1/auth/me');
        $this->assertSame('Bearer', $result->response()->getHeaderLine('WWW-Authenticate'));
    }

    public function testMeReturnsOnlyIdAndEmail(): void
    {
        $tokens = $this->loginTokens();
        $result = $this->withHeaders(['Authorization' => 'Bearer ' . $tokens['access_token']])->get('api/v1/auth/me');

        $this->assertSame(['id', 'email'], array_keys(json_decode((string) $result->response()->getBody(), true)));
    }

    public function testRefreshRotatesTokens(): void
    {
        $first = $this->loginTokens();

        [$status, $second] = $this->postJson('auth/refresh', ['refresh_token' => $first['refresh_token']]);

        $this->assertSame(200, $status);
        $this->assertNotSame($first['refresh_token'], $second['refresh_token']);
        $this->assertSame(401, $this->me($first['access_token']));
        $this->assertSame(200, $this->me($second['access_token']));
    }

    public function testReusingARotatedRefreshTokenRevokesTheFamily(): void
    {
        $first  = $this->loginTokens();
        $second = $this->postJson('auth/refresh', ['refresh_token' => $first['refresh_token']])[1];

        [$status, $body] = $this->postJson('auth/refresh', ['refresh_token' => $first['refresh_token']]);

        $this->assertSame(401, $status);
        $this->assertSame(['error' => 'invalid_token'], $body);
        $this->assertSame(401, $this->me($second['access_token']));
        $this->assertSame(401, $this->postJson('auth/refresh', ['refresh_token' => $second['refresh_token']])[0]);
    }

    public function testLogoutRevokesTheSessionAndAlwaysAnswers204(): void
    {
        $tokens = $this->loginTokens();

        $this->assertSame(204, $this->postJson('auth/logout', ['refresh_token' => $tokens['refresh_token']])[0]);
        $this->assertSame(401, $this->me($tokens['access_token']));
        $this->assertSame(401, $this->postJson('auth/refresh', ['refresh_token' => $tokens['refresh_token']])[0]);

        $this->assertSame(204, $this->postJson('auth/logout', ['refresh_token' => 'unknown'])[0]);
        $this->assertSame(204, $this->postJson('auth/logout')[0]);
    }

    public function testLogoutWithBearerTokenAlone(): void
    {
        $tokens = $this->loginTokens();

        $this->assertSame(204, $this->postJson('auth/logout', [], ['Authorization' => 'Bearer ' . $tokens['access_token']])[0]);
        $this->assertSame(401, $this->me($tokens['access_token']));
        $this->assertSame(401, $this->postJson('auth/refresh', ['refresh_token' => $tokens['refresh_token']])[0]);
    }

    public function testAccountLockoutOverHttp(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->assertSame(401, $this->postJson('auth/login', ['email' => self::EMAIL, 'password' => 'wrong password 1'])[0]);
        }

        [$status, $body, $response] = $this->postJson('auth/login', ['email' => self::EMAIL, 'password' => self::PASSWORD]);

        $this->assertSame(429, $status);
        $this->assertSame(['error' => 'account_locked'], $body);
        $this->assertGreaterThan(0, (int) $response->getHeaderLine('Retry-After'));
    }

    public function testPerIpRateLimitOnLogin(): void
    {
        $limit = config('Auth')->ipLimits['login'];

        for ($i = 0; $i < $limit; $i++) {
            $this->assertSame(401, $this->postJson('auth/login', ['email' => 'nobody@example.test', 'password' => 'x'])[0], "request {$i}");
        }

        [$status, $body, $response] = $this->postJson('auth/login', ['email' => 'nobody@example.test', 'password' => 'x']);

        $this->assertSame(429, $status);
        $this->assertSame(['error' => 'too_many_requests'], $body);
        $this->assertGreaterThan(0, (int) $response->getHeaderLine('Retry-After'));

        // Other buckets are independent.
        $this->assertSame(401, $this->postJson('auth/refresh', ['refresh_token' => str_repeat('A', 43)])[0]);
    }

    public function testThrottledRequestDoesNotReachTheAuthenticationCode(): void
    {
        $limit = config('Auth')->ipLimits['login'];

        for ($i = 0; $i < $limit; $i++) {
            $this->postJson('auth/login', ['email' => self::EMAIL, 'password' => 'wrong password 1']);
        }

        $failuresBefore = (int) $this->testDb->table('users')->get()->getRow('failed_logins');
        $this->postJson('auth/login', ['email' => self::EMAIL, 'password' => 'wrong password 1']);

        $this->assertSame($failuresBefore, (int) $this->testDb->table('users')->get()->getRow('failed_logins'));
    }

    public function testInternalErrorsAreGenericAndLeakNothing(): void
    {
        $failing = new class () extends AuthService {
            public function __construct()
            {
            }

            public function login(string $email, string $password, ?string $deviceLabel = null): \App\Libraries\Auth\AuthOutcome
            {
                throw new RuntimeException('SQLSTATE secret-detail db_password=hunter2 host=10.0.0.5');
            }
        };

        Services::injectMock('authService', $failing);

        [$status, $body] = $this->postJson('auth/login', ['email' => self::EMAIL, 'password' => self::PASSWORD]);

        $this->assertSame(500, $status);
        $this->assertSame(['error' => 'server_error'], $body);
    }

    public function testNoPasswordOrTokenReachesTheLogs(): void
    {
        $tokens = $this->loginTokens();
        $this->postJson('auth/login', ['email' => self::EMAIL, 'password' => self::PASSWORD . 'x']);
        $this->postJson('auth/refresh', ['refresh_token' => $tokens['refresh_token']]);
        $this->postJson('auth/refresh', ['refresh_token' => $tokens['refresh_token']]); // reuse, logs a warning

        $logs = '';

        foreach (glob(WRITEPATH . 'logs/*.log') ?: [] as $file) {
            $logs .= (string) file_get_contents($file);
        }

        foreach ([self::PASSWORD, $tokens['access_token'], $tokens['refresh_token']] as $secret) {
            $this->assertStringNotContainsString($secret, $logs);
        }
    }

    public function testNoRegistrationOrAccountCreationRouteExists(): void
    {
        $routes = array_keys(service('routes')->getRoutes('*'));

        foreach ($routes as $route) {
            $this->assertDoesNotMatchRegularExpression('/regist|signup|sign-up|users|account|password/i', $route, "Unexpected route: {$route}");
        }

        foreach (['register', 'signup', 'auth/register', 'users', 'auth/users'] as $path) {
            $this->assertTrue($this->isNotFound(fn () => $this->withBodyFormat('json')->post('api/v1/' . $path, ['email' => 'x@example.test', 'password' => 'long enough password'])), $path);
        }
    }
}
