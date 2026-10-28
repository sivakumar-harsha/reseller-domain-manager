<?php

declare(strict_types=1);

use App\Database\Migrations\CreateRefreshTokens;
use App\Database\Migrations\CreateUsers;
use CodeIgniter\Database\Exceptions\DatabaseException;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Database;
use Tests\Support\Database\UsesIsolatedAuthDatabase;

/**
 * Constraint tests for `users` and `refresh_tokens` on the isolated test
 * database. Skipped (not passed) when that database is not configured.
 *
 * @internal
 */
final class AuthSchemaTest extends CIUnitTestCase
{
    use UsesIsolatedAuthDatabase;

    private const ER_BAD_NULL          = 1048;
    private const ER_DUP_ENTRY         = 1062;
    private const ER_NO_REFERENCED_ROW = 1452;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpIsolatedAuthDatabase();
    }

    private function insertToken(int $userId, array $overrides = []): void
    {
        $this->testDb->table('refresh_tokens')->insert($overrides + [
            'user_id'           => $userId,
            'token_hash'        => str_repeat('a', 64),
            'family_id'         => str_repeat('f', 32),
            'access_token_hash' => str_repeat('b', 64),
            'access_expires_at' => '2026-10-28 10:15:00',
            'expires_at'        => '2026-11-27 10:00:00',
            'created_at'        => '2026-10-28 10:00:00',
        ]);
    }

    private function assertRejected(callable $fn, int $errno): void
    {
        try {
            $fn();
        } catch (DatabaseException $e) {
            $codes = [$e->getCode()];

            for ($p = $e->getPrevious(); $p !== null; $p = $p->getPrevious()) {
                $codes[] = $p->getCode();
            }

            $this->assertContains($errno, $codes, 'Rejected, but with a different error: ' . $e->getMessage());

            return;
        }

        $this->fail('Expected the database to reject this statement with error ' . $errno . '.');
    }

    public function testBothTablesExist(): void
    {
        $this->assertTrue($this->testDb->tableExists('users'));
        $this->assertTrue($this->testDb->tableExists('refresh_tokens'));
    }

    public function testUserDefaults(): void
    {
        $this->insertUser();
        $row = $this->testDb->table('users')->get()->getRowArray();

        $this->assertSame('1', (string) $row['is_active']);
        $this->assertSame('0', (string) $row['failed_logins']);
        $this->assertNull($row['locked_until']);
        $this->assertNull($row['totp_secret_enc']);
    }

    public function testEmailIsUniqueCaseInsensitively(): void
    {
        $this->insertUser();

        $this->assertRejected(fn () => $this->insertUser(['email' => 'OWNER@example.test']), self::ER_DUP_ENTRY);
    }

    public function testUserRequiredColumnsCannotBeNull(): void
    {
        $this->assertRejected(fn () => $this->insertUser(['email' => null]), self::ER_BAD_NULL);
        $this->assertRejected(fn () => $this->insertUser(['password_hash' => null]), self::ER_BAD_NULL);
    }

    public function testRefreshTokenHashesAreUnique(): void
    {
        $uid = $this->insertUser();
        $this->insertToken($uid);

        $this->assertRejected(fn () => $this->insertToken($uid, ['access_token_hash' => str_repeat('c', 64)]), self::ER_DUP_ENTRY);
        $this->assertRejected(fn () => $this->insertToken($uid, ['token_hash' => str_repeat('d', 64)]), self::ER_DUP_ENTRY);
    }

    public function testRefreshTokenRequiredColumnsCannotBeNull(): void
    {
        $uid = $this->insertUser();

        foreach (['token_hash', 'family_id', 'access_token_hash', 'access_expires_at', 'expires_at'] as $column) {
            $this->assertRejected(fn () => $this->insertToken($uid, [$column => null]), self::ER_BAD_NULL);
        }
    }

    public function testRefreshTokenRejectsUnknownUser(): void
    {
        $this->assertRejected(fn () => $this->insertToken(999999), self::ER_NO_REFERENCED_ROW);
    }

    public function testDeletingUserDeletesTokens(): void
    {
        $uid = $this->insertUser();
        $this->insertToken($uid);

        $this->testDb->table('users')->where('id', $uid)->delete();

        $this->assertSame(0, $this->testDb->table('refresh_tokens')->countAllResults());
    }

    public function testExpectedIndexesExist(): void
    {
        $users  = array_map('strtolower', array_keys($this->testDb->getIndexData('users')));
        $tokens = array_map('strtolower', array_keys($this->testDb->getIndexData('refresh_tokens')));

        foreach (['primary', 'email'] as $name) {
            $this->assertContains($name, $users);
        }

        foreach (['primary', 'token_hash', 'access_token_hash', 'family_id', 'expires_at'] as $name) {
            $this->assertContains($name, $tokens);
        }
    }

    public function testDownDropsTablesInReverseOrder(): void
    {
        $forge = Database::forge('tests');

        (new CreateRefreshTokens($forge))->down();
        (new CreateUsers($forge))->down();

        $this->assertFalse($this->testDb->tableExists('refresh_tokens'));
        $this->assertFalse($this->testDb->tableExists('users'));

        // Recreate so later tests in this class and the shared teardown still work.
        (new CreateUsers($forge))->up();
        (new CreateRefreshTokens($forge))->up();
    }
}
