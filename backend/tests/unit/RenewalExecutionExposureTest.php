<?php

declare(strict_types=1);

use App\Controllers\Api\V1\RenewalBatchController;
use App\Libraries\Renewal\RenewalBatchGateway;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Phase 1 exposure guard: proves at the source and type level that no route,
 * controller or service wiring can reach renewal execution.
 *
 * @internal
 */
final class RenewalExecutionExposureTest extends CIUnitTestCase
{
    public function testTheRouteFileDefinesNoExecutionRoute(): void
    {
        // php_strip_whitespace drops comments: only CODE is examined.
        $routes = php_strip_whitespace(APPPATH . 'Config/Routes.php');

        // The renewal-batch routes are create, index, show, reconcile and the ONE manual item renewal (Phase 2).
        preg_match_all('/\$routes->(get|post|put|patch|delete)\(\'renewal-batches[^\']*\'/', $routes, $m);
        $this->assertSame(
            [
                "\$routes->post('renewal-batches'",
                "\$routes->get('renewal-batches'",
                "\$routes->get('renewal-batches/active'",
                "\$routes->get('renewal-batches/(:num)'",
                "\$routes->post('renewal-batches/(:num)/reconcile'",
                "\$routes->post('renewal-batches/(:num)/items/(:num)/renew'",
                "\$routes->post('renewal-batches/(:num)/items/(:num)/debit-verification'",
            ],
            $m[0],
            'The renewal-batch route list changed: re-audit before allowing this.',
        );

        // Exactly one renewal action route, POST only, on a single item, behind auth AND its own throttle.
        preg_match_all('/\$routes->\w+\(\'[^\']*renew\'[^\n]*/', $routes, $renew);
        $this->assertCount(1, $renew[0]);
        $this->assertStringStartsWith("\$routes->post('renewal-batches/(:num)/items/(:num)/renew', 'RenewalExecutionController::renew/\$1/\$2'", $renew[0][0]);
        $this->assertStringContainsString("'filter' => ['auth', 'throttle:renewal_execute']", $renew[0][0]);

        // No route PATH may offer a batch-wide or generic execute/renew-all action.
        preg_match_all('/\$routes->\w+\(\'([^\']*)\'/', $routes, $paths);

        foreach ($paths[1] as $path) {
            $this->assertStringNotContainsStringIgnoringCase('execute', $path);
            $this->assertStringNotContainsStringIgnoringCase('renew-all', $path);
            $this->assertStringNotContainsStringIgnoringCase('process', $path);
        }
    }

    public function testOnlyTheExecutionControllerReachesExecutionAndOnlyThroughItsNarrowGateway(): void
    {
        $source = php_strip_whitespace(APPPATH . 'Controllers/Api/V1/RenewalExecutionController.php');

        foreach (['executeForUser', 'executeItemForUser', 'processItem(', 'processBatch(', 'renewDomain(', "service('renewalExecutionService')", 'ResellerClubClient'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $source, "The execution controller must never reference {$forbidden}.");
        }

        $this->assertStringContainsString("service('renewalExecutionGateway')->renewItem(", $source);

        $methods = array_map(
            static fn (ReflectionMethod $m): string => $m->getName(),
            (new ReflectionClass(\App\Libraries\Renewal\RenewalExecutionGateway::class))->getMethods(ReflectionMethod::IS_PUBLIC),
        );
        sort($methods);
        $this->assertSame(['__construct', 'renewItem'], $methods);

        // Every other controller source is free of the gateway and the execution service.
        foreach (glob(APPPATH . 'Controllers/Api/V1/*.php') ?: [] as $file) {
            if (basename($file) === 'RenewalExecutionController.php') {
                continue;
            }

            $code = php_strip_whitespace($file);
            $this->assertStringNotContainsString('renewalExecutionGateway', $code, basename($file));
            $this->assertStringNotContainsString('renewalExecutionService', $code, basename($file));
        }
    }

    public function testTheShippedSafetyDefaultsAreStillOff(): void
    {
        $source = (string) file_get_contents(APPPATH . 'Config/ResellerClub.php');

        $this->assertMatchesRegularExpression('/public bool \$renewalExecutionEnabled\s*=\s*false;/', $source);
        $this->assertMatchesRegularExpression('/public bool \$renewalDebitVerified\s*=\s*false;/', $source);
    }

    public function testTheBatchControllerSourceNeverTouchesExecution(): void
    {
        // php_strip_whitespace drops comments: only CODE is examined.
        $source = php_strip_whitespace(APPPATH . 'Controllers/Api/V1/RenewalBatchController.php');

        foreach (['executeForUser', 'processItem(', 'processBatch(', 'renewDomain(', 'renewalExecutionService'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $source, "The controller must never reference {$forbidden}.");
        }
    }

    public function testTheControllerFacingGatewayExposesOnlyBatchCreationAndLookup(): void
    {
        $methods = array_map(
            static fn (ReflectionMethod $m): string => $m->getName(),
            (new ReflectionClass(RenewalBatchGateway::class))->getMethods(ReflectionMethod::IS_PUBLIC),
        );
        sort($methods);

        $this->assertSame(['__construct', 'createBatch', 'findActiveBatchIds', 'findExistingBatch'], $methods);
    }

    public function testTheBatchServiceAccessorIsTypedToTheGateway(): void
    {
        $type = (new ReflectionMethod(\Config\Services::class, 'renewalBatchService'))->getReturnType();

        $this->assertSame(RenewalBatchGateway::class, (string) $type);
    }

    public function testTheControllerDeclaresNoExecutionCapableMethod(): void
    {
        $methods = array_map(
            static fn (ReflectionMethod $m): string => strtolower($m->getName()),
            (new ReflectionClass(RenewalBatchController::class))->getMethods(),
        );

        foreach ($methods as $name) {
            $this->assertStringNotContainsString('execute', $name);
            $this->assertStringNotContainsString('renew', str_replace(['renewalerror'], '', $name));
        }
    }
}
