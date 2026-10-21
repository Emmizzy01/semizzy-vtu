<?php

namespace Tests\Feature;

use Tests\TestCase;

class FinancialMutationAuthenticationTest extends TestCase
{
    public function test_guest_cannot_reach_financial_mutation_or_recovery_endpoints(): void
    {
        $requests = [
            ['POST', '/wallet/manual-deposits'],
            ['POST', '/wallet/refund-requests'],
            ['POST', '/wallet/bank-transfer'],
            ['POST', '/wallet/transfers'],
            ['POST', '/wallet/scheduled-purchases'],
            ['POST', '/wallet/vtu/orders'],
            ['POST', '/wallet/vtu/orders/bulk'],
            ['GET', '/wallet/vtu/orders/recover'],
            ['GET', '/wallet/vtu/orders/bulk-recover'],
        ];

        foreach ($requests as [$method, $uri]) {
            $this->call($method, $uri)->assertRedirect('/login');
        }
    }

    public function test_guest_cannot_read_financial_history_or_recovery_status_endpoints(): void
    {
        $requests = [
            ['GET', '/wallet/manual-deposits'],
            ['GET', '/wallet/refund-requests/00000000-0000-4000-8000-000000000000'],
            ['GET', '/wallet/bank-transfer/history'],
            ['GET', '/wallet/transfers/history'],
            ['GET', '/wallet/scheduled-purchases'],
            ['GET', '/wallet/vtu/orders/00000000-0000-4000-8000-000000000000'],
            ['GET', '/wallet/vtu/orders/bulk/00000000-0000-4000-8000-000000000000'],
        ];

        foreach ($requests as [$method, $uri]) {
            $this->call($method, $uri)->assertRedirect('/login');
        }
    }
}
