<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class AdminSidebarRouteMatrixTest extends TestCase
{
    public function test_every_admin_sidebar_destination_has_a_registered_route(): void
    {
        $sidebarRoutes = [
            'admin.dashboard',                    // /admin/dashboard
            'admin.manual-deposits.index',         // /admin/manual-deposits
            'admin.refunds.dashboard',             // /admin/refunds
            'admin.payment-gateway',               // /admin/payment-gateway
            'admin.storefront-commissions.index',  // /admin/storefront-commissions
            'admin.referrals.index',               // /admin/referrals
            'admin.vtu-orders',                    // /admin/vtu-orders
            'admin.vtu-products.index',            // /admin/vtu-products
            'admin.vtu-website-requests.index',    // /admin/vtu-website-requests
            'admin.streaming-subscriptions.index', // /admin/streaming-subscriptions
            'admin.education-services.index',      // /admin/education-services
            'admin.users.index',                   // /admin/users
            'admin.user-levels.index',             // /admin/user-levels
            'admin.user-level-upgrade-requests.index',
            'admin.kyc-reviews.index',              // /admin/kyc-reviews
            'admin.support.index',                  // /admin/support
            'admin.api-providers',                  // /admin/api-providers
            'admin.platform-settings',              // /admin/platform-settings (homepage editor uses #homepage)
            'admin.support-pages.edit',             // /admin/support-pages
            'admin.feature-controls.index',         // /admin/feature-controls
            'admin.security-events',                // /admin/security-events
            'admin.staff.index',                    // /admin/staff
            'admin.staff.audits',                   // /admin/staff/permission-audits
        ];

        foreach ($sidebarRoutes as $routeName) {
            $this->assertTrue(Route::has($routeName), 'Admin sidebar destination is missing a route: '.$routeName);
        }
    }
}
