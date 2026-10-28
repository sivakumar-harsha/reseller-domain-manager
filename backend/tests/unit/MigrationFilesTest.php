<?php

declare(strict_types=1);

use CodeIgniter\Test\CIUnitTestCase;

/**
 * Static checks on the migration files. These need no database.
 *
 * @internal
 */
final class MigrationFilesTest extends CIUnitTestCase
{
    private const EXPECTED = [
        '2026-10-27-120000_CreateSyncRuns.php' => ['App\Database\Migrations\CreateSyncRuns', 'sync_runs'],
        '2026-10-27-120100_CreateDomains.php'  => ['App\Database\Migrations\CreateDomains', 'domains'],
        '2026-10-28-100000_CreateUsers.php'    => ['App\Database\Migrations\CreateUsers', 'users'],
        '2026-10-28-100100_CreateRefreshTokens.php' => ['App\Database\Migrations\CreateRefreshTokens', 'refresh_tokens'],
    ];

    public function testMigrationFilesExistWithTimestampNames(): void
    {
        $files = array_map('basename', glob(APPPATH . 'Database/Migrations/*.php') ?: []);
        sort($files);

        $expected = array_keys(self::EXPECTED);
        sort($expected);

        $this->assertSame($expected, $files);
    }

    public function testFilenamesMatchTheCodeIgniterPatternAndRunInDependencyOrder(): void
    {
        // domains has a foreign key to sync_runs and refresh_tokens to users,
        // so each parent table must sort before its child.
        // Order is taken from the files actually on disk, in the same
        // lexical order the migration runner uses.
        $files = array_map('basename', glob(APPPATH . 'Database/Migrations/*.php') ?: []);
        sort($files, SORT_STRING);

        foreach ($files as $file) {
            $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}-\d{6}_[A-Z][A-Za-z0-9]+\.php$/', $file);
        }

        $this->assertCount(count(self::EXPECTED), $files);
        $this->assertSame(array_keys(self::EXPECTED), $files, 'Migration files are missing, misnamed or out of order.');

        $order = array_map(static fn (string $f): string => self::EXPECTED[$f][1], $files);
        $this->assertSame(['sync_runs', 'domains', 'users', 'refresh_tokens'], $order);
    }

    public function testEachMigrationImplementsItsOwnUpAndDown(): void
    {
        foreach (self::EXPECTED as $file => [$class]) {
            require_once APPPATH . 'Database/Migrations/' . $file;

            $this->assertTrue(class_exists($class), $class);
            $this->assertTrue(is_subclass_of($class, \CodeIgniter\Database\Migration::class), $class);

            // The base class declares abstract up()/down(); each migration must
            // override both rather than merely inherit the declarations.
            foreach (['up', 'down'] as $method) {
                $declaring = (new ReflectionMethod($class, $method))->getDeclaringClass()->getName();

                $this->assertSame($class, $declaring, "{$class}::{$method} must be implemented by the migration itself");
                $this->assertFalse((new ReflectionMethod($class, $method))->isAbstract(), "{$class}::{$method}");
            }

            $this->assertSame(
                '_' . (new ReflectionClass($class))->getShortName(),
                (string) strstr(basename($file, '.php'), '_'),
                "{$file} must be named after class {$class}",
            );
        }
    }

    public function testMigrationsOnlyTouchTheirOwnTableAndAreNotDestructive(): void
    {
        foreach (self::EXPECTED as $file => [, $table]) {
            $source = (string) file_get_contents(APPPATH . 'Database/Migrations/' . $file);

            $this->assertDoesNotMatchRegularExpression('/\b(DROP\s+DATABASE|TRUNCATE|CREATE\s+DATABASE|GRANT|CREATE\s+USER)\b/i', $source, $file);
            $this->assertSame(1, substr_count($source, "createTable('{$table}'"), $file);
            $this->assertSame(1, substr_count($source, "dropTable('{$table}'"), $file);
            $this->assertSame(0, preg_match('/->query\(/', $source), $file . ' must not run raw SQL');
        }
    }

    public function testDefaultConnectionTargetsApplicationDatabaseInStrictMode(): void
    {
        $default = config('Database')->default;

        $this->assertTrue($default['strictOn']);
        $this->assertSame('MySQLi', $default['DBDriver']);
        $this->assertSame('utf8mb4', $default['charset']);
        $this->assertSame('utf8mb4_general_ci', $default['DBCollat']);
    }
}
