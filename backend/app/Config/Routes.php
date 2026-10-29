<?php

use CodeIgniter\Router\RouteCollection;

/**
 * API-only application: there is no web front end and no registration route.
 *
 * @var RouteCollection $routes
 */
$routes->group('api/v1', ['namespace' => 'App\Controllers\Api\V1'], static function (RouteCollection $routes): void {
    $routes->get('health', 'HealthController::index');

    // No registration route exists: the owner is created with `php spark auth:create-owner`.
    $routes->post('auth/login', 'AuthController::login', ['filter' => 'throttle:login']);
    $routes->post('auth/refresh', 'AuthController::refresh', ['filter' => 'throttle:refresh']);
    $routes->post('auth/logout', 'AuthController::logout', ['filter' => 'throttle:logout']);
    $routes->get('auth/me', 'AuthController::me', ['filter' => 'auth']);

    $routes->get('domains', 'DomainController::index', ['filter' => 'auth']);
    $routes->get('domains/(:num)', 'DomainController::show/$1', ['filter' => 'auth']);
});
