<?php

use CodeIgniter\Router\RouteCollection;

/**
 * API-only application: there is no web front end and no registration route.
 *
 * @var RouteCollection $routes
 */
$routes->group('api/v1', ['namespace' => 'App\Controllers\Api\V1'], static function (RouteCollection $routes): void {
    $routes->get('health', 'HealthController::index');
});
