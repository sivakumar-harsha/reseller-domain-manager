<?php

declare(strict_types=1);

namespace App\Libraries\Auth;

use Closure;
use CodeIgniter\Database\BaseConnection;
use Config\Auth as AuthConfig;
use Config\Database;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Email/password login with opaque, hashed bearer tokens.
 *
 * Design (see docs/AUTHENTICATION.md):
 * - Tokens are 256 bits from random_bytes(); only their SHA-256 is stored.
 *   They carry no data and are not signed, so there is no cryptographic
 *   scheme to get wrong.
 * - Each refresh_tokens row pairs a refresh token with the access token issued
 *   in the same exchange. Rotating, revoking or reusing a refresh token
 *   therefore also ends the matching access token immediately.
 * - Passwords: password_hash()/password_verify() (bcrypt).
 * - Nothing here logs credentials, tokens or request data.
 */
class AuthService
{
    private const FORMAT = 'Y-m-d H:i:s';

    private BaseConnection $db;
    private AuthConfig $config;
    private Closure $clock;

    /** Identity of the request being served, set by AuthFilter. */
    private ?array $currentUser = null;

    private static ?string $dummyHash = null;

    public function __construct(?BaseConnection $db = null, ?AuthConfig $config = null, ?Closure $clock = null)
    {
        $this->db     = $db ?? Database::connect();
        $this->config = $config ?? config('Auth');
        $this->clock  = $clock ?? static fn (): DateTimeImmutable => new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    public function login(string $email, string $password, ?string $deviceLabel = null): AuthOutcome
    {
        $now  = $this->now();
        $user = $this->db->table('users')->where('email', $this->normalizeEmail($email))->get()->getRowArray();

        if ($user === null) {
            // Spend comparable time so response timing does not reveal whether the email exists.
            password_verify($password, $this->dummyHash());

            return AuthOutcome::fail(AuthOutcome::INVALID_CREDENTIALS);
        }

        if ($user['locked_until'] !== null && $this->parse($user['locked_until']) > $now) {
            return AuthOutcome::fail(
                AuthOutcome::ACCOUNT_LOCKED,
                max(1, $this->parse($user['locked_until'])->getTimestamp() - $now->getTimestamp()),
            );
        }

        $passwordOk = strlen($password) <= $this->config->maxPasswordLength
            && password_verify($password, $user['password_hash']);

        if (! $passwordOk || (int) $user['is_active'] !== 1) {
            $this->recordFailure((int) $user['id'], $now);

            return AuthOutcome::fail(AuthOutcome::INVALID_CREDENTIALS);
        }

        $update = ['failed_logins' => 0, 'locked_until' => null, 'updated_at' => $this->fmt($now)];

        if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
            $update['password_hash'] = password_hash($password, PASSWORD_DEFAULT);
        }

        $this->db->table('users')->where('id', $user['id'])->update($update);

        $tokens = $this->issue((int) $user['id'], bin2hex(random_bytes(16)), $this->cleanLabel($deviceLabel), $now);

        return AuthOutcome::ok($tokens);
    }

    /**
     * Exchanges a refresh token for a new pair. The presented token is
     * single-use: presenting it a second time revokes the whole family.
     */
    public function refresh(string $refreshToken): AuthOutcome
    {
        $now   = $this->now();
        $table = $this->db->prefixTable('refresh_tokens');

        $this->db->transBegin();

        try {
            $row = $this->db
                ->query("SELECT * FROM {$table} WHERE token_hash = ? FOR UPDATE", [$this->hash($refreshToken)])
                ->getRowArray();

            if ($row === null || $row['revoked_at'] !== null) {
                $this->db->transCommit();

                return AuthOutcome::fail(AuthOutcome::INVALID_TOKEN);
            }

            if ($row['rotated_at'] !== null) {
                // A rotated token was presented again: assume it was stolen.
                $this->revokeFamily($row['family_id'], $now);
                $this->db->transCommit();
                log_message('warning', 'Refresh token reuse detected; token family revoked (user_id={user_id}).', ['user_id' => $row['user_id']]);

                return AuthOutcome::fail(AuthOutcome::INVALID_TOKEN);
            }

            $user = $this->db->table('users')->where('id', $row['user_id'])->get()->getRowArray();

            if ($user === null || (int) $user['is_active'] !== 1) {
                $this->revokeFamily($row['family_id'], $now);
                $this->db->transCommit();

                return AuthOutcome::fail(AuthOutcome::INVALID_TOKEN);
            }

            if ($this->parse($row['expires_at']) <= $now) {
                $this->db->transCommit();

                return AuthOutcome::fail(AuthOutcome::INVALID_TOKEN);
            }

            $this->db->table('refresh_tokens')->where('id', $row['id'])->update([
                'rotated_at'   => $this->fmt($now),
                'last_used_at' => $this->fmt($now),
            ]);

            $tokens = $this->issue((int) $row['user_id'], $row['family_id'], $row['device_label'], $now);

            $this->db->transCommit();

            return AuthOutcome::ok($tokens);
        } catch (\Throwable $e) {
            $this->db->transRollback();

            throw $e;
        }
    }

    /**
     * Revokes the token family identified by either token. Unknown tokens are
     * ignored, so the call is idempotent and reveals nothing.
     */
    public function logout(?string $refreshToken, ?string $accessToken = null): void
    {
        $now = $this->now();

        foreach ([['token_hash', $refreshToken], ['access_token_hash', $accessToken]] as [$column, $token]) {
            if ($token === null || $token === '') {
                continue;
            }

            $row = $this->db->table('refresh_tokens')->select('family_id')->where($column, $this->hash($token))->get()->getRowArray();

            if ($row !== null) {
                $this->revokeFamily($row['family_id'], $now);
            }
        }
    }

    /**
     * Validates an access token. Returns the user (id, email) or null.
     *
     * @return array{id: int, email: string}|null
     */
    public function authenticate(string $accessToken): ?array
    {
        $now = $this->now();

        $row = $this->db->table('refresh_tokens')
            ->select('refresh_tokens.access_expires_at, refresh_tokens.expires_at, refresh_tokens.rotated_at, refresh_tokens.revoked_at, users.id AS user_id, users.email, users.is_active')
            ->join('users', 'users.id = refresh_tokens.user_id')
            ->where('refresh_tokens.access_token_hash', $this->hash($accessToken))
            ->get()
            ->getRowArray();

        if (
            $row === null
            || $row['revoked_at'] !== null
            || $row['rotated_at'] !== null
            || (int) $row['is_active'] !== 1
            || $this->parse($row['access_expires_at']) <= $now
            || $this->parse($row['expires_at']) <= $now
        ) {
            return null;
        }

        return ['id' => (int) $row['user_id'], 'email' => $row['email']];
    }

    /**
     * @param array{id: int, email: string}|null $user
     */
    public function setCurrentUser(?array $user): void
    {
        $this->currentUser = $user;
    }

    /**
     * @return array{id: int, email: string}|null
     */
    public function currentUser(): ?array
    {
        return $this->currentUser;
    }

    public function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    /**
     * @return array<string, mixed>
     */
    private function issue(int $userId, string $familyId, ?string $deviceLabel, DateTimeImmutable $now): array
    {
        $access  = $this->newToken();
        $refresh = $this->newToken();

        $this->db->table('refresh_tokens')->insert([
            'user_id'           => $userId,
            'token_hash'        => $this->hash($refresh),
            'family_id'         => $familyId,
            'device_label'      => $deviceLabel,
            'access_token_hash' => $this->hash($access),
            'access_expires_at' => $this->fmt($now->modify("+{$this->config->accessTtl} seconds")),
            'expires_at'        => $this->fmt($now->modify("+{$this->config->refreshTtl} seconds")),
            'created_at'        => $this->fmt($now),
        ]);

        return [
            'token_type'         => 'Bearer',
            'access_token'       => $access,
            'expires_in'         => $this->config->accessTtl,
            'refresh_token'      => $refresh,
            'refresh_expires_in' => $this->config->refreshTtl,
        ];
    }

    private function revokeFamily(string $familyId, DateTimeImmutable $now): void
    {
        $this->db->table('refresh_tokens')
            ->where('family_id', $familyId)
            ->where('revoked_at', null)
            ->update(['revoked_at' => $this->fmt($now)]);
    }

    /**
     * Counts a failed password and, from the configured threshold on, locks
     * the account for an exponentially growing time (capped).
     */
    private function recordFailure(int $userId, DateTimeImmutable $now): void
    {
        $this->db->table('users')->where('id', $userId)->set('failed_logins', 'failed_logins + 1', false)->update();

        $failures = (int) $this->db->table('users')->select('failed_logins')->where('id', $userId)->get()->getRow('failed_logins');

        if ($failures < $this->config->maxFailures) {
            return;
        }

        $exponent = min($failures - $this->config->maxFailures, 20);
        $seconds  = min($this->config->lockBaseSeconds * (2 ** $exponent), $this->config->lockMaxSeconds);

        $this->db->table('users')->where('id', $userId)->update([
            'locked_until' => $this->fmt($now->modify("+{$seconds} seconds")),
            'updated_at'   => $this->fmt($now),
        ]);
    }

    private function newToken(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    private function normalizeEmail(string $email): string
    {
        return mb_strtolower(trim($email));
    }

    private function cleanLabel(?string $label): ?string
    {
        if ($label === null) {
            return null;
        }

        $label = trim((string) preg_replace('/[\x00-\x1F\x7F]/u', '', $label));

        return $label === '' ? null : mb_substr($label, 0, 100);
    }

    private function dummyHash(): string
    {
        return self::$dummyHash ??= password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT);
    }

    private function now(): DateTimeImmutable
    {
        return ($this->clock)();
    }

    private function fmt(DateTimeImmutable $time): string
    {
        return $time->setTimezone(new DateTimeZone('UTC'))->format(self::FORMAT);
    }

    private function parse(string $value): DateTimeImmutable
    {
        return new DateTimeImmutable($value, new DateTimeZone('UTC'));
    }
}
