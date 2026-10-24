<?php

namespace Tests\Feature;

use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as RouteFacade;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

/**
 * Keeps docs/api/openapi.yaml honest: every /api/v1 route must be documented
 * and every documented operation must exist.
 */
class OpenApiContractTest extends TestCase
{
    private const SPEC = __DIR__.'/../../../docs/api/openapi.yaml';

    /**
     * @return list<string> "METHOD /path" pairs relative to /api/v1
     */
    private function implementedOperations(): array
    {
        $operations = [];

        /** @var Route $route */
        foreach (RouteFacade::getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'api/v1/') && $route->uri() !== 'api/v1') {
                continue;
            }

            $path = '/'.substr($route->uri(), strlen('api/v1/'));

            foreach ($route->methods() as $method) {
                if (in_array($method, ['HEAD', 'OPTIONS'], true)) {
                    continue;
                }
                $operations[] = $method.' '.$path;
            }
        }

        sort($operations);

        return array_values(array_unique($operations));
    }

    /**
     * @return list<string>
     */
    private function documentedOperations(): array
    {
        $this->assertFileExists(self::SPEC);
        $spec = Yaml::parseFile(self::SPEC);
        $operations = [];

        foreach ($spec['paths'] as $path => $item) {
            foreach (array_keys($item) as $method) {
                if (in_array($method, ['get', 'post', 'put', 'patch', 'delete'], true)) {
                    $operations[] = strtoupper($method).' '.$path;
                }
            }
        }

        sort($operations);

        return $operations;
    }

    public function test_every_route_is_documented_and_every_documented_operation_exists(): void
    {
        $implemented = $this->implementedOperations();
        $documented = $this->documentedOperations();

        $this->assertSame([], array_values(array_diff($implemented, $documented)), 'Routes missing from openapi.yaml');
        $this->assertSame([], array_values(array_diff($documented, $implemented)), 'Documented operations with no route');
    }

    public function test_spec_is_openapi_3_1(): void
    {
        $spec = Yaml::parseFile(self::SPEC);

        $this->assertSame('3.1.0', $spec['openapi']);
        $this->assertArrayHasKey('Error', $spec['components']['schemas']);
    }
}
