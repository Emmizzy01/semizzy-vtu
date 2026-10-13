# Payment refund approval and settlement

## Workflow

1. An authenticated customer submits a refund request for a completed Paystack funding intent at `POST /wallet/refund-requests`. The request includes the intent public UUID, amount in minor units (kobo), reason, and a unique idempotency key.
2. The service locks the original intent and ensures all active refund requests for that payment do not exceed the original funding amount. Requests may be partial.
3. An operator with the explicit `refunds.approve` permission approves through `POST /admin/payment-refunds/{refund}/approve`.
4. Approval debits/reserves the requested amount from the customer's available wallet balance through the existing wallet ledger, with a stable idempotency key and audit event. This happens before any provider call.
5. If the wallet lacks available funds, the request becomes `blocked_insufficient_wallet_funds`; Paystack is not contacted. Do not override this by creating a negative balance or issuing an external refund anyway.
6. Once reserved, SEMIZZY sends the refund to Paystack and tracks provider statuses. A timeout or ambiguous provider response becomes `review_required`; the wallet debit remains in place until the provider outcome is confirmed.
7. A confirmed provider failure posts one idempotent compensating wallet credit and marks the request failed. A processed refund remains debited and is marked succeeded. Signed refund webhooks update matching requests; all refund/dispute events are also retained as reconciliation cases.

## Statuses

- `requested`: waiting for an authorized approver.
- `processing`: approved and wallet funds reserved; provider call is in progress.
- `provider_pending`: Paystack accepted the request but has not confirmed final settlement.
- `succeeded`: provider reports the refund processed.
- `failed`: provider definitively failed the refund; reserved wallet funds were restored once.
- `blocked_insufficient_wallet_funds`: wallet available balance was insufficient, so no provider request was sent.
- `review_required`: provider outcome is ambiguous or requires manual investigation.

## Granting the approval permission

The permission gate fails closed. There is no self-service permission-grant endpoint. After verifying the operator's identity and authority through your existing deployment/admin process, grant the permission using a trusted database session, substituting the real user IDs:

```sql
INSERT INTO user_permissions (user_id, permission, granted_by_user_id, created_at, updated_at)
VALUES (<approver_user_id>, 'refunds.approve', <granting_admin_user_id>, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP);
```

Revoke access by deleting that user's `refunds.approve` row. Do not expose permission writes to customer-facing endpoints.

## Operations and limitations

- Amounts are integer minor units; never use floating-point currency.
- Paystack secrets stay server-side. Use test credentials and verify refund lifecycle payloads in a Paystack test environment before production.
- Do not automatically refund when the customer has spent the wallet funds. Escalate the request for human review and a documented business decision.
- A provider timeout is not proof of failure. Reconcile the provider status before retrying or releasing reserved funds.
- The current implementation has API endpoints and backend permission enforcement; it does not yet include an admin UI for reviewing/approving the queue or a dedicated scheduled refund reconciliation command.
- Run the full Laravel test suite and perform a provider sandbox test before enabling live refunds. Automated tests use mocked provider responses and are not a live Paystack test.
