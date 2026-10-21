<?php

namespace Tests\Feature;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class DashboardLinkRouteMatrixTest extends TestCase
{
    public function test_customer_dashboard_primary_links_resolve_to_registered_get_routes(): void
    {
        $destinations = [
            '/dashboard', '/wallet/manual-deposits', '/wallet/transfer',
            '/wallet/vtu/airtime', '/wallet/vtu/data', '/wallet/vtu/electricity', '/wallet/vtu/tv',
            '/services', '/services/education', '/services/coming-soon/internet',
            '/services/coming-soon/water-bill', '/services/coming-soon/betting',
            '/services/coming-soon/airtime-to-cash', '/services/coming-soon/e-pin',
            '/services/coming-soon/bulk-sms', '/services/coming-soon/gift-cards',
            '/services/coming-soon/flight', '/services/coming-soon/gadget-catalogue',
            '/account/referrals', '/profile', '/support',
        ];

        foreach ($destinations as $destination) {
            try {
                $route = Route::getRoutes()->match(Request::create($destination, 'GET'));
            } catch (\Symfony\Component\HttpKernel\Exception\NotFoundHttpException) {
                $this->fail('Dashboard destination has no matching GET route: '.$destination);
            }

            $this->assertContains('GET', $route->methods(), 'Dashboard destination must support GET: '.$destination);
        }
    }

    public function test_admin_routes_require_authentication_and_an_explicit_capability(): void
    {
        $capabilities = ['can:admin.dashboard', 'can:staff.permissions.manage', 'can:refunds.approve'];
        $checked = 0;

        foreach (Route::getRoutes() as $route) {
            $uri = ltrim($route->uri(), '/');
            if ($uri !== 'admin' && ! str_starts_with($uri, 'admin/')) {
                continue;
            }

            $checked++;
            $middleware = $route->gatherMiddleware();
            $this->assertContains('auth', $middleware, 'Admin route must require authentication: '.$route->uri());
            $this->assertNotEmpty(array_intersect($capabilities, $middleware), 'Admin route must have a capability gate: '.$route->uri());
        }

        $this->assertGreaterThan(0, $checked);
    }

    public function test_gadget_preview_does_not_present_unverified_sample_prices_as_live_offers(): void
    {
        $dashboard = file_get_contents(resource_path('js/Pages/Dashboard.tsx'));
        $this->assertIsString($dashboard);
        $this->assertStringContainsString('Sample product cards only.', $dashboard);
        $this->assertStringContainsString('verified pricing are not available yet.', $dashboard);
        $this->assertStringNotContainsString("price:'₦850,000'", $dashboard);
        $this->assertStringNotContainsString("price:'₦720,000'", $dashboard);
        $this->assertStringContainsString('/services/coming-soon/gadget-catalogue', $dashboard);
    }
}
