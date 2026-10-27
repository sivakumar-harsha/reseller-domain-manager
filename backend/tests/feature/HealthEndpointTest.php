<?php

declare(strict_types=1);

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * @internal
 */
final class HealthEndpointTest extends CIUnitTestCase
{
    use FeatureTestTrait;

    public function testHealthReturnsOkJson(): void
    {
        $result = $this->get('api/v1/health');

        $result->assertStatus(200);
        $result->assertHeader('Content-Type', 'application/json; charset=UTF-8');

        $body = json_decode($result->getJSON(), true);

        $this->assertSame('ok', $body['status']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $body['time']);
    }

    public function testHealthExposesOnlyStatusAndTime(): void
    {
        $body = json_decode($this->get('api/v1/health')->getJSON(), true);

        $this->assertSame(['status', 'time'], array_keys($body));
    }

    public function testHealthIsNotCacheable(): void
    {
        $header = $this->get('api/v1/health')->response()->getHeaderLine('Cache-Control');

        $this->assertStringContainsString('no-store', $header);
    }

    public function testRootRouteIsNotExposed(): void
    {
        $this->expectException(\CodeIgniter\Exceptions\PageNotFoundException::class);

        $this->get('/');
    }

    public function testNoRegistrationRoutesExist(): void
    {
        foreach (['register', 'api/v1/register', 'api/v1/auth/register', 'api/v1/signup'] as $path) {
            $routes = service('routes')->getRoutes('*');
            $this->assertArrayNotHasKey($path, $routes, "Unexpected route: {$path}");
        }
    }
}
