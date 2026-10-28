<?php

declare(strict_types=1);

use App\Libraries\Auth\AuthOutcome;
use App\Libraries\Auth\AuthService;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Auth as AuthConfig;
use Tests\Support\Database\UsesIsolatedAuthDatabase;

/**
 * AuthService behaviour against the isolated test database, with a controllable clock.
 *
 * @internal
 */
final class AuthServiceTest extends CIUnitTestCase
{
    use UsesIsolatedAuthDatabase;

    private const EMAIL    = 'owner@example.test';
    private const PASSWORD = 'correct horse battery';

    private DateTimeImmutable $now;
    private AuthService $auth;
    private AuthConfig $config;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpIsolatedAuthDatabase();

        $this->now    = new DateTimeImmutable('2026-10-28 10:00:00', new DateTimeZone('UTC'));
        $this->config = new AuthConfig();
        $this->auth   = new AuthService($this->testDb, $this->config, fn () => $this->now);

        $this->insertUser(['email' => self::EMAIL]);
    }

    private function advance(string $modifier): void
    {
        $this->now = $this->now->modify($modifier);
    }

    private function login(string $password = self::PASSWORD, string $email = self::EMAIL): AuthOutcome
    {
        return $this->auth->login($email, $password, 'test phone');
    }

    private function failTimes(int $n): void
    {
        for ($i = 0; $i < $n; $i++) {
            $this->assertSame(AuthOutcome::INVALID_CREDENTIALS, $this->login('wrong password!')->code);
        }
    }

    public function testSuccessfulLoginIssuesTokensWithConfiguredLifetimes(): void
    {
        $out = $this->login();

        $this->assertTrue($out->isOk());
        $this->assertSame('Bearer', $out->tokens['token_type']);
        $this->assertSame(900, $out->tokens['expires_in']);
        $this->assertSame(2592000, $out->tokens['refresh_expires_in']);
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/', $out->tokens['access_token']);
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/', $out->tokens['refresh_token']);
        $this->assertNotSame($out->tokens['access_token'], $out->tokens['refresh_token']);
    }

    public function testEmailIsCaseInsensitiveAndTrimmed(): void
    {
        $this->assertTrue($this->login(self::PASSWORD, '  OWNER@Example.Test ')->isOk());
    }

    public function testTokensAreStoredOnlyAsHashes(): void
    {
        $out = $this->login();
        $row = $this->testDb->table('refresh_tokens')->get()->getRowArray();

        $this->assertSame(hash('sha256', $out->tokens['refresh_token']), $row['token_hash']);
        $this->assertSame(hash('sha256', $out->tokens['access_token']), $row['access_token_hash']);

        $dump = json_encode($this->testDb->table('refresh_tokens')->get()->getResultArray());
        $this->assertStringNotContainsString($out->tokens['refresh_token'], $dump);
        $this->assertStringNotContainsString($out->tokens['access_token'], $dump);
        $this->assertSame('test phone', $row['device_label']);
    }

    public function testPasswordIsStoredHashed(): void
    {
        $hash = $this->testDb->table('users')->get()->getRow('password_hash');

        $this->assertNotSame(self::PASSWORD, $hash);
        $this->assertTrue(password_verify(self::PASSWORD, $hash));
    }

    public function testWrongPasswordAndUnknownEmailAreIndistinguishable(): void
    {
        $wrong   = $this->login('not the password');
        $unknown = $this->login(self::PASSWORD, 'nobody@example.test');

        $this->assertSame(AuthOutcome::INVALID_CREDENTIALS, $wrong->code);
        $this->assertEquals($wrong, $unknown);
    }

    public function testInactiveAccountCannotLogIn(): void
    {
        $this->testDb->table('users')->update(['is_active' => 0]);

        $this->assertSame(AuthOutcome::INVALID_CREDENTIALS, $this->login()->code);
    }

    public function testOverlongPasswordIsRejectedWithoutError(): void
    {
        $this->assertSame(AuthOutcome::INVALID_CREDENTIALS, $this->login(str_repeat('x', 500))->code);
    }

    public function testAccessTokenAuthenticatesUntilItExpires(): void
    {
        $tokens = $this->login()->tokens;

        $user = $this->auth->authenticate($tokens['access_token']);
        $this->assertSame(self::EMAIL, $user['email']);

        $this->assertNull($this->auth->authenticate($tokens['refresh_token']), 'A refresh token is not an access token.');

        $this->advance('+14 minutes 59 seconds');
        $this->assertNotNull($this->auth->authenticate($tokens['access_token']));

        $this->advance('+1 second');
        $this->assertNull($this->auth->authenticate($tokens['access_token']));
    }

    public function testRefreshRotatesBothTokensAndKeepsTheFamily(): void
    {
        $first  = $this->login()->tokens;
        $second = $this->auth->refresh($first['refresh_token']);

        $this->assertTrue($second->isOk());
        $this->assertNotSame($first['refresh_token'], $second->tokens['refresh_token']);
        $this->assertNotSame($first['access_token'], $second->tokens['access_token']);

        $families = array_unique(array_column($this->testDb->table('refresh_tokens')->get()->getResultArray(), 'family_id'));
        $this->assertCount(1, $families);

        $this->assertNull($this->auth->authenticate($first['access_token']), 'The old access token ends at rotation.');
        $this->assertNotNull($this->auth->authenticate($second->tokens['access_token']));
    }

    public function testRotatedRefreshTokenReuseRevokesTheWholeFamily(): void
    {
        $first  = $this->login()->tokens;
        $second = $this->auth->refresh($first['refresh_token'])->tokens;
        $third  = $this->auth->refresh($second['refresh_token'])->tokens;

        $reuse = $this->auth->refresh($first['refresh_token']);

        $this->assertSame(AuthOutcome::INVALID_TOKEN, $reuse->code);
        $this->assertNull($this->auth->authenticate($third['access_token']));
        $this->assertSame(AuthOutcome::INVALID_TOKEN, $this->auth->refresh($third['refresh_token'])->code);
        $this->assertSame(0, $this->testDb->table('refresh_tokens')->where('revoked_at', null)->countAllResults());
    }

    public function testReuseOfOneFamilyDoesNotAffectAnotherLogin(): void
    {
        $phone  = $this->login()->tokens;
        $tablet = $this->login()->tokens;

        $this->auth->refresh($phone['refresh_token']);
        $this->auth->refresh($phone['refresh_token']); // reuse: revokes the phone family only

        $this->assertNotNull($this->auth->authenticate($tablet['access_token']));
        $this->assertTrue($this->auth->refresh($tablet['refresh_token'])->isOk());
    }

    public function testExpiredRefreshTokenIsRejected(): void
    {
        $tokens = $this->login()->tokens;

        $this->advance('+30 days');

        $this->assertSame(AuthOutcome::INVALID_TOKEN, $this->auth->refresh($tokens['refresh_token'])->code);
    }

    public function testRefreshWithUnknownTokenIsRejected(): void
    {
        $this->assertSame(AuthOutcome::INVALID_TOKEN, $this->auth->refresh(str_repeat('A', 43))->code);
    }

    public function testRefreshFailsAndRevokesWhenAccountIsDisabled(): void
    {
        $tokens = $this->login()->tokens;
        $this->testDb->table('users')->update(['is_active' => 0]);

        $this->assertSame(AuthOutcome::INVALID_TOKEN, $this->auth->refresh($tokens['refresh_token'])->code);
        $this->assertNull($this->auth->authenticate($tokens['access_token']));
    }

    public function testLogoutByRefreshTokenRevokesTheFamily(): void
    {
        $first  = $this->login()->tokens;
        $second = $this->auth->refresh($first['refresh_token'])->tokens;

        $this->auth->logout($second['refresh_token']);

        $this->assertNull($this->auth->authenticate($second['access_token']));
        $this->assertSame(AuthOutcome::INVALID_TOKEN, $this->auth->refresh($second['refresh_token'])->code);
    }

    public function testLogoutByAccessTokenRevokesTheFamily(): void
    {
        $tokens = $this->login()->tokens;

        $this->auth->logout(null, $tokens['access_token']);

        $this->assertNull($this->auth->authenticate($tokens['access_token']));
        $this->assertSame(AuthOutcome::INVALID_TOKEN, $this->auth->refresh($tokens['refresh_token'])->code);
    }

    public function testLogoutIsIdempotentAndIgnoresUnknownTokens(): void
    {
        $tokens = $this->login()->tokens;

        $this->auth->logout('unknown-token');
        $this->auth->logout($tokens['refresh_token']);
        $this->auth->logout($tokens['refresh_token']);

        $this->assertNull($this->auth->authenticate($tokens['access_token']));
    }

    public function testAccountLocksAtThresholdAndEvenTheRightPasswordIsRefused(): void
    {
        $this->failTimes($this->config->maxFailures - 1);
        $this->assertNull($this->testDb->table('users')->get()->getRow('locked_until'));

        $this->failTimes(1);

        $locked = $this->login(self::PASSWORD);
        $this->assertSame(AuthOutcome::ACCOUNT_LOCKED, $locked->code);
        $this->assertSame(60, $locked->retryAfter);
    }

    public function testLockExpiresAndBackoffDoublesOnFurtherFailures(): void
    {
        $this->failTimes($this->config->maxFailures);   // 1st lock: 60 s

        $this->advance('+61 seconds');
        $this->failTimes(1);                            // 6th failure: 120 s
        $this->assertSame(120, $this->login()->retryAfter);

        $this->advance('+121 seconds');
        $this->failTimes(1);                            // 7th failure: 240 s
        $this->assertSame(240, $this->login()->retryAfter);
    }

    public function testBackoffIsCapped(): void
    {
        $this->testDb->table('users')->update(['failed_logins' => 500]);

        $this->failTimes(1);

        $this->assertSame($this->config->lockMaxSeconds, $this->login()->retryAfter);
    }

    public function testFailedAttemptsWhileLockedDoNotExtendTheLock(): void
    {
        $this->failTimes($this->config->maxFailures);
        $before = $this->testDb->table('users')->get()->getRow('failed_logins');

        $this->login('wrong again');

        $this->assertSame($before, $this->testDb->table('users')->get()->getRow('failed_logins'));
    }

    public function testSuccessfulLoginResetsTheFailureCounter(): void
    {
        $this->failTimes($this->config->maxFailures - 1);

        $this->assertTrue($this->login()->isOk());

        $row = $this->testDb->table('users')->get()->getRowArray();
        $this->assertSame('0', (string) $row['failed_logins']);
        $this->assertNull($row['locked_until']);

        $this->failTimes($this->config->maxFailures - 1);
        $this->assertTrue($this->login()->isOk(), 'Counter restarted from zero.');
    }

    public function testLoginAfterLockExpiryWorks(): void
    {
        $this->failTimes($this->config->maxFailures);

        $this->advance('+61 seconds');

        $this->assertTrue($this->login()->isOk());
    }
}
