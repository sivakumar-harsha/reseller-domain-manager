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
    $routes->get('domains/(:num)/details', 'DomainController::details/$1', ['filter' => 'auth']);
    $routes->get('domains/(:num)/renewal-quote', 'RenewalQuoteController::show/$1', ['filter' => ['auth', 'throttle:renewal_quote']]);
    $routes->get('expiry/summary', 'ExpiryController::summary', ['filter' => 'auth']);
    $routes->get('balance', 'BalanceController::show', ['filter' => ['auth', 'throttle:balance']]);
    $routes->post('inventory/sync', 'InventorySyncController::run', ['filter' => ['auth', 'throttle:inventory_sync']]);
    $routes->get('enrichment/eligible', 'EnrichmentBatchController::eligible', ['filter' => 'auth']);
    $routes->get('enrichment/batches/current', 'EnrichmentBatchController::current', ['filter' => 'auth']);
    $routes->post('enrichment/batches', 'EnrichmentBatchController::start', ['filter' => ['auth', 'throttle:enrich_batch']]);
    $routes->post('enrichment/batches/([a-f0-9]{16})/step', 'EnrichmentBatchController::step/$1', ['filter' => ['auth', 'throttle:enrich_batch']]);
    // Renewal batches. RenewalBatchController stores local records and runs read-only reconciliation ONLY.
    // The single manual, confirmed, one-item renewal action is the separate RenewalExecutionController below.
    $routes->post('renewal-batches', 'RenewalBatchController::create', ['filter' => ['auth', 'throttle:renewal_batch_create']]);
    $routes->get('renewal-batches', 'RenewalBatchController::index', ['filter' => ['auth', 'throttle:renewal_batch_read']]);
    $routes->get('renewal-batches/active', 'RenewalBatchController::active', ['filter' => ['auth', 'throttle:renewal_batch_read']]);
    $routes->get('renewal-batches/(:num)', 'RenewalBatchController::show/$1', ['filter' => ['auth', 'throttle:renewal_batch_read']]);
    $routes->post('renewal-batches/(:num)/reconcile', 'RenewalBatchController::reconcile/$1', ['filter' => ['auth', 'throttle:renewal_reconcile']]);
    $routes->post('renewal-batches/(:num)/items/(:num)/renew', 'RenewalExecutionController::renew/$1/$2', ['filter' => ['auth', 'throttle:renewal_execute']]);
    // Manual, operator-recorded debit verification. Writes verification fields only; never calls the provider or renews.
    $routes->post('renewal-batches/(:num)/items/(:num)/debit-verification', 'RenewalDebitVerificationController::verify/$1/$2', ['filter' => ['auth', 'throttle:renewal_debit_verify']]);
    $routes->post('domains/(:num)/enrich', 'DomainController::enrich/$1', ['filter' => ['auth', 'throttle:enrich']]);
});
