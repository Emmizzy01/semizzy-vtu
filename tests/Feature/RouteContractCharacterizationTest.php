<?php

namespace Tests\Feature;

use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as RouteFacade;
use Tests\TestCase;

class RouteContractCharacterizationTest extends TestCase
{
    /** @return Route */
    private function namedRoute(string $name): Route
    {
        $route = RouteFacade::getRoutes()->getByName($name);

        $this->assertNotNull($route, sprintf('Expected route [%s] to remain registered.', $name));

        return $route;
    }

    public function test_payment_and_provider_webhook_contracts_remain_publicly_callable_and_throttled(): void
    {
        $payment = $this->namedRoute('payments.webhooks.receive');
        $this->assertSame(['POST'], $payment->methods());
        $this->assertSame('api/payments/webhooks/{provider}', $payment->uri());
        $this->assertContains('throttle:60,1', $payment->gatherMiddleware());

        $vtu = $this->namedRoute('vtu.provider-webhooks.receive');
        $this->assertSame(['POST'], $vtu->methods());
        $this->assertSame('api/vtu/webhooks/{provider}', $vtu->uri());
        $this->assertContains('throttle:60,1', $vtu->gatherMiddleware());
        $this->assertSame(['sogo', 'dataway'], $vtu->wheres['provider'] ?? null);

        // Webhooks must not require a customer session or addon-admission middleware:
        // accepted operations still need callback processing and reconciliation.
        foreach ([$payment, $vtu] as $route) {
            $middleware = $route->gatherMiddleware();
            $this->assertNotContains('auth', $middleware);
            $this->assertNotContains('addon.admission', $middleware);
        }
    }

    public function test_customer_manual_deposit_and_authentication_route_contracts_remain_throttled(): void
    {
        $login = $this->namedRoute('login.store');
        $this->assertSame(['POST'], $login->methods());
        $this->assertSame('login', $login->uri());
        $this->assertContains('guest', $login->gatherMiddleware());
        $this->assertContains('throttle:5,1', $login->gatherMiddleware());

        $deposit = $this->namedRoute('wallet.manual-deposits.submit');
        $this->assertSame(['POST'], $deposit->methods());
        $this->assertSame('wallet/manual-deposits', $deposit->uri());
        $this->assertContains('auth', $deposit->gatherMiddleware());
        $this->assertContains('throttle:5,1', $deposit->gatherMiddleware());
    }

    public function test_manual_deposit_review_and_refund_approval_keep_admin_capability_boundaries(): void
    {
        $depositReview = $this->namedRoute('admin.manual-deposits.review');
        $this->assertSame(['POST'], $depositReview->methods());
        $this->assertContains('auth', $depositReview->gatherMiddleware());
        $this->assertContains('can:staff.permissions.manage', $depositReview->gatherMiddleware());
        $this->assertContains('throttle:10,1', $depositReview->gatherMiddleware());

        $refundApproval = $this->namedRoute('admin.payment-refunds.approve');
        $this->assertSame(['POST'], $refundApproval->methods());
        $this->assertContains('auth', $refundApproval->gatherMiddleware());
        $this->assertContains('can:refunds.approve', $refundApproval->gatherMiddleware());
        $this->assertContains('throttle:20,1', $refundApproval->gatherMiddleware());
    }

    public function test_vtu_purchase_route_remains_authenticated_and_rate_limited(): void
    {
        $route = $this->namedRoute('vtu.purchase');
        $this->assertContains('POST', $route->methods());
        $this->assertContains('auth', $route->gatherMiddleware());
        $this->assertContains('throttle:60,1', $route->gatherMiddleware());
    }
}
