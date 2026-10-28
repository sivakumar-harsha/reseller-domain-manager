<?php

declare(strict_types=1);

use CodeIgniter\Test\CIUnitTestCase;

/**
 * Database-free checks of the authentication wiring and logging hygiene.
 *
 * @internal
 */
final class AuthStaticTest extends CIUnitTestCase
{
    private const AUTH_FILES = [
        'Controllers/Api/V1/AuthController.php',
        'Libraries/Auth/AuthService.php',
        'Libraries/Auth/AuthOutcome.php',
        'Filters/AuthFilter.php',
        'Filters/ThrottleFilter.php',
        'Commands/CreateOwner.php',
    ];

    private function loadedRoutes(): \CodeIgniter\Router\RouteCollection
    {
        $routes = service('routes');
        $routes->loadRoutes();

        return $routes;
    }

    public function testOnlyTheExpectedAuthRoutesExistWithTheirProtections(): void
    {
        $routes = $this->loadedRoutes();
        $post   = $routes->getRoutes('POST');
        $get    = $routes->getRoutes('GET');

        foreach (['login', 'refresh', 'logout'] as $action) {
            $this->assertArrayHasKey("api/v1/auth/{$action}", $post);
            $this->assertArrayNotHasKey("api/v1/auth/{$action}", $get);
        }

        $this->assertArrayHasKey('api/v1/auth/me', $get);

        $options = $routes->getRoutesOptions('api/v1/auth/login', 'POST');
        $this->assertSame(['throttle:login'], (array) ($options['filter'] ?? null));

        $options = $routes->getRoutesOptions('api/v1/auth/me', 'GET');
        $this->assertSame(['auth'], (array) ($options['filter'] ?? null));
    }

    public function testNoRegistrationOrAccountCreationRouteIsDefined(): void
    {
        $all = [];

        foreach (['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS', 'HEAD', 'CLI', '*'] as $verb) {
            $all = array_merge($all, array_keys($this->loadedRoutes()->getRoutes($verb)));
        }

        $this->assertContains('api/v1/health', $all, 'Routes were not loaded.');

        foreach ($all as $route) {
            $this->assertDoesNotMatchRegularExpression('/regist|signup|sign-up|account|users|password/i', $route, "Unexpected route: {$route}");
        }
    }

    public function testAuthCodeNeverLogsRequestDataOrSecrets(): void
    {
        foreach (self::AUTH_FILES as $file) {
            $source = (string) file_get_contents(APPPATH . $file);

            $this->assertDoesNotMatchRegularExpression('/\b(var_dump|print_r|var_export|dd)\s*\(/', $source, $file);

            preg_match_all('/log_message\((.*?)\);/s', $source, $calls);

            foreach ($calls[1] as $call) {
                $this->assertDoesNotMatchRegularExpression(
                    '/password|token|getBody|getJSON|getPost|getRawInput|getUri|current_url|base_url|\$e->getMessage|getMessage\(\)|\$_(GET|POST|SERVER)/i',
                    // Placeholder names such as {user_id} are fine; strip quoted text first.
                    (string) preg_replace("/'[^']*'/", "''", $call),
                    "{$file}: log_message must not include request data, tokens or exception messages",
                );
            }
        }
    }

    public function testPasswordsAreHashedWithPasswordHashAndTokensWithSha256(): void
    {
        $service = (string) file_get_contents(APPPATH . 'Libraries/Auth/AuthService.php');

        $this->assertStringContainsString('password_verify(', $service);
        $this->assertStringContainsString("hash('sha256'", $service);
        $this->assertStringContainsString('random_bytes(32)', $service);
        $this->assertDoesNotMatchRegularExpression('/\b(md5|sha1|crypt|mt_rand|rand|uniqid)\s*\(/', $service);
    }

    public function testAuthConfigDefaultsMatchThePlan(): void
    {
        $config = config('Auth');

        $this->assertSame(900, $config->accessTtl);
        $this->assertSame(30 * 86400, $config->refreshTtl);
        $this->assertGreaterThanOrEqual(3, $config->maxFailures);
        $this->assertGreaterThanOrEqual(12, $config->minPasswordLength);
        $this->assertLessThanOrEqual(72, $config->maxPasswordLength);
    }
}
