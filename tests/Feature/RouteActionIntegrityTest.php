<?php

namespace Tests\Feature;

use Illuminate\Routing\Route;
use Tests\TestCase;

class RouteActionIntegrityTest extends TestCase
{
    public function test_every_registered_controller_route_resolves_to_an_existing_class_and_method(): void
    {
        $failures = [];

        foreach (app('router')->getRoutes() as $route) {
            /** @var Route $route */
            $action = $route->getActionName();

            if ($action === 'Closure' || str_contains($action, 'SerializableClosure')) {
                continue;
            }

            [$class, $method] = str_contains($action, '@')
                ? explode('@', $action, 2)
                : [$action, '__invoke'];

            if (! class_exists($class)) {
                $failures[] = sprintf('%s %s: missing controller class %s', implode('|', $route->methods()), $route->uri(), $class);
                continue;
            }

            if (! method_exists($class, $method)) {
                $failures[] = sprintf('%s %s: missing action %s::%s', implode('|', $route->methods()), $route->uri(), $class, $method);
            }
        }

        $this->assertSame([], $failures, implode("\n", $failures));
    }
}
