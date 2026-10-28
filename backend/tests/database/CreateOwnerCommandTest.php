<?php

declare(strict_types=1);

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\StreamFilterTrait;
use Tests\Support\Database\UsesIsolatedAuthDatabase;

/**
 * The owner-creation CLI command, run against the isolated test database
 * (under PHPUnit the default connection group is the test group).
 *
 * @internal
 */
final class CreateOwnerCommandTest extends CIUnitTestCase
{
    use StreamFilterTrait;
    use UsesIsolatedAuthDatabase;

    private const PASSWORD = 'Sentinel-Owner-Pass-1';

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpIsolatedAuthDatabase();
        putenv('RDM_OWNER_PASSWORD=' . self::PASSWORD);
    }

    protected function tearDown(): void
    {
        putenv('RDM_OWNER_PASSWORD');

        parent::tearDown();
    }

    private function cliOutput(): string
    {
        return $this->getStreamFilterBuffer();
    }

    public function testCreatesTheOwnerWithAHashedPasswordAndNeverPrintsIt(): void
    {
        command('auth:create-owner --email Owner@Example.Test');

        $row = $this->testDb->table('users')->get()->getRowArray();

        $this->assertSame('owner@example.test', $row['email']);
        $this->assertNotSame(self::PASSWORD, $row['password_hash']);
        $this->assertTrue(password_verify(self::PASSWORD, $row['password_hash']));
        $this->assertSame('1', (string) $row['is_active']);
        $this->assertStringContainsString('Owner account created', $this->cliOutput());
        $this->assertStringNotContainsString(self::PASSWORD, $this->cliOutput());
    }

    public function testRefusesToCreateASecondAccount(): void
    {
        command('auth:create-owner --email first@example.test');
        command('auth:create-owner --email second@example.test');

        $this->assertSame(1, $this->testDb->table('users')->countAllResults());
        $this->assertStringContainsString('already exists', $this->cliOutput());
    }

    public function testRejectsAShortPassword(): void
    {
        putenv('RDM_OWNER_PASSWORD=short');

        command('auth:create-owner --email owner@example.test');

        $this->assertSame(0, $this->testDb->table('users')->countAllResults());
        $this->assertStringContainsString('bytes long', $this->cliOutput());
    }

    public function testRejectsAPasswordLongerThanBcryptCanUse(): void
    {
        putenv('RDM_OWNER_PASSWORD=' . str_repeat('a', 73));

        command('auth:create-owner --email owner@example.test');

        $this->assertSame(0, $this->testDb->table('users')->countAllResults());
    }

    public function testRejectsAnInvalidOrMissingEmail(): void
    {
        command('auth:create-owner --email not-an-email');
        command('auth:create-owner');

        $this->assertSame(0, $this->testDb->table('users')->countAllResults());
    }

    public function testCreatedOwnerCanLogIn(): void
    {
        command('auth:create-owner --email owner@example.test');

        $outcome = (new App\Libraries\Auth\AuthService())->login('owner@example.test', self::PASSWORD);

        $this->assertTrue($outcome->isOk());
    }
}
