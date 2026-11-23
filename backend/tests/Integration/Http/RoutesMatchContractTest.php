<?php

declare(strict_types=1);

namespace SmokeDetector\Tests\Integration\Http;

use SmokeDetector\Bootstrap\ContainerFactory;
use SmokeDetector\Http\AppFactory;
use SmokeDetector\Tests\Support\IntegrationTestCase;
use SmokeDetector\Tests\Support\Paths;
use Symfony\Component\Yaml\Yaml;

/**
 * The routes of the application and the operations of api/openapi.yaml are the same set.
 *
 * So a real endpoint cannot exist without being in the contract (and cannot be tested around the
 * contract with callUndocumented()), and the contract cannot promise an endpoint that does not exist.
 */
final class RoutesMatchContractTest extends IntegrationTestCase
{
    public function test_every_route_is_in_the_contract_and_every_operation_has_a_route(): void
    {
        $routes = $this->routes();
        $operations = $this->operations();

        self::assertNotSame([], $routes);
        self::assertSame([], array_values(array_diff($routes, $operations)), 'Routes that are missing in api/openapi.yaml.');
        self::assertSame([], array_values(array_diff($operations, $routes)), 'Operations of api/openapi.yaml without a route.');
    }

    public function test_health_is_one_of_them(): void
    {
        self::assertContains('GET /health', $this->routes());
    }

    /**
     * @return list<string> "GET /health", sorted.
     */
    private function routes(): array
    {
        $app = (new AppFactory())->create((new ContainerFactory())->create($this->settings()));

        $routes = [];
        foreach ($app->getRouteCollector()->getRoutes() as $route) {
            // Patterns are relative to the base path. "{id:[0-9]+}" in a Slim pattern is "{id}" in OpenAPI.
            $path = (string) preg_replace('/\{([A-Za-z0-9_]+):[^}]+\}/', '{$1}', $route->getPattern());
            foreach ($route->getMethods() as $method) {
                $routes[] = strtoupper($method) . ' ' . $path;
            }
        }
        sort($routes);

        return $routes;
    }

    /**
     * @return list<string> "GET /health", sorted.
     */
    private function operations(): array
    {
        $document = Yaml::parseFile(Paths::openApi());
        self::assertIsArray($document);
        self::assertIsArray($document['paths'] ?? null);

        $operations = [];
        foreach ($document['paths'] as $path => $item) {
            self::assertIsArray($item);
            foreach (['get', 'put', 'post', 'delete', 'options', 'head', 'patch', 'trace'] as $method) {
                if (isset($item[$method])) {
                    $operations[] = strtoupper($method) . ' ' . $path;
                }
            }
        }
        sort($operations);

        return $operations;
    }
}
