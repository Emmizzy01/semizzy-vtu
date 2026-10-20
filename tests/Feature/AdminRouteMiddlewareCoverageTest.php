<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class AdminRouteMiddlewareCoverageTest extends TestCase
{
    public function test_every_admin_route_requires_authentication_and_an_explicit_admin_capability(): void
    {
        $capabilityMiddleware = [
            'can:admin.dashboard',
            'can:staff.permissions.manage',
            'can:refunds.approve',
        ];
        $checked = 0;

        foreach (Route::getRoutes() as $route) {
            $uri = ltrim($route->uri(), '/');
            if ($uri !== 'admin' && ! str_starts_with($uri, 'admin/')) {
                continue;
            }

            $checked++;
            $middleware = $route->gatherMiddleware();

            $this->assertContains(
                'auth',
                $middleware,
                sprintf('Admin route [%s] must require authentication.', $route->uri()),
            );
            $this->assertNotEmpty(
                array_intersect($capabilityMiddleware, $middleware),
                sprintf('Admin route [%s] must require an explicit capability gate.', $route->uri()),
            );
        }

        $this->assertGreaterThan(0, $checked, 'The admin route audit must inspect at least one route.');
    }
}
