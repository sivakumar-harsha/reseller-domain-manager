<?php

declare(strict_types=1);

use CodeIgniter\Test\CIUnitTestCase;
use Config\ResellerClub;

/**
 * Fail-closed parsing of the two renewal safety flags. CodeIgniter's env()
 * converts "true"/"false" into booleans, so both forms must be handled.
 *
 * @internal
 */
final class ResellerClubRenewalFlagsTest extends CIUnitTestCase
{
    private const KEYS = ['RENEWAL_EXECUTION_ENABLED', 'RENEWAL_DEBIT_VERIFIED'];

    /** @var array<string, array{0: mixed, 1: mixed, 2: string|false}> */
    private array $saved = [];

    protected function setUp(): void
    {
        parent::setUp();

        foreach (self::KEYS as $key) {
            $this->saved[$key] = [$_ENV[$key] ?? null, $_SERVER[$key] ?? null, getenv($key)];
        }
    }

    protected function tearDown(): void
    {
        foreach (self::KEYS as $key) {
            [$e, $s, $g] = $this->saved[$key];
            $this->restore($_ENV, $key, $e);
            $this->restore($_SERVER, $key, $s);
            $g === false ? putenv($key) : putenv($key . '=' . $g);
        }

        parent::tearDown();
    }

    private function restore(array &$bag, string $key, mixed $value): void
    {
        if ($value === null) {
            unset($bag[$key]);
        } else {
            $bag[$key] = $value;
        }
    }

    private function setEnv(string $key, mixed $value): void
    {
        unset($_ENV[$key], $_SERVER[$key]);
        putenv($key);

        if ($value !== null) {
            $_SERVER[$key] = $value;
        }
    }

    /**
     * @return array<string, array{0: mixed, 1: bool}>
     */
    public static function valueProvider(): array
    {
        return [
            'bool true'      => [true, true],
            'string true'    => ['true', true],
            'string TRUE'    => ['TRUE', true],
            'padded true'    => [' true ', true],
            'bool false'     => [false, false],
            'string false'   => ['false', false],
            'null / absent'  => [null, false],
            'empty string'   => ['', false],
            'string 1'       => ['1', false],
            'int 1'          => [1, false],
            'string yes'     => ['yes', false],
            'string on'      => ['on', false],
            'string garbage' => ['maybe', false],
        ];
    }

    /**
     * @dataProvider valueProvider
     */
    public function testExecutionFlagParsing(mixed $value, bool $expected): void
    {
        $this->setEnv('RENEWAL_EXECUTION_ENABLED', $value);
        $this->setEnv('RENEWAL_DEBIT_VERIFIED', null);

        $config = new ResellerClub();

        $this->assertSame($expected, $config->renewalExecutionEnabled);
        $this->assertFalse($config->renewalDebitVerified);
    }

    /**
     * @dataProvider valueProvider
     */
    public function testDebitVerifiedFlagParsing(mixed $value, bool $expected): void
    {
        $this->setEnv('RENEWAL_DEBIT_VERIFIED', $value);
        $this->setEnv('RENEWAL_EXECUTION_ENABLED', null);

        $config = new ResellerClub();

        $this->assertSame($expected, $config->renewalDebitVerified);
        $this->assertFalse($config->renewalExecutionEnabled);
    }

    public function testBothFlagsDefaultToFalseWhenAbsent(): void
    {
        $this->setEnv('RENEWAL_EXECUTION_ENABLED', null);
        $this->setEnv('RENEWAL_DEBIT_VERIFIED', null);

        $config = new ResellerClub();

        $this->assertFalse($config->renewalExecutionEnabled);
        $this->assertFalse($config->renewalDebitVerified);
    }
}
