# Addon lifecycle contract — admission and recovery

**State:** Pure policy contract implemented; not yet connected to routes, jobs or provider resolution. No service has been migrated.  
**Code:** app/Services/Addons/AddonLifecyclePolicy.php  
**Tests:** tests/Unit/AddonLifecyclePolicyTest.php

## New-operation admission

A service may admit a new operation only when every condition is true:

1. Registry status is enabled.
2. Compatibility has been assessed as compatible.
3. A reviewed implementation is registered in the application; a database row alone is not implementation registration.
4. The existing Core feature gate is enabled.
5. The service's provider readiness contract is satisfied.

The policy fails closed and returns a stable reason code otherwise. It does not claim a service is available merely because its manifest exists. Provider readiness must be a service-specific check; a transient provider outage should prevent new purchase submission without disabling Core recovery.

## Accepted-operation recovery

Core recovery is deliberately separate from addon admission. An already accepted, non-terminal operation may continue through verified webhook processing, status lookup, reconciliation, refund/reversal and audit only when the relevant Core recovery capability is available. The addon's current enabled/disabled status is not an input to that decision. A terminal operation is not reopened by this policy, and recovery must never create a new provider submission.

This protects wallet/accounting integrity and ensures an addon can be disabled for new sales without stranding money or orders. It does not permit blind retry after an ambiguous provider result.

## State transitions and operator behavior

- enabled + compatible + registered + feature enabled + provider ready: new-operation admission allowed.
- Any missing gate: new-operation admission denied with a stable reason code; customer-facing response mapping must preserve existing API contracts.
- Addon disabled after acceptance: no new service operations; already accepted work remains eligible for Core recovery.
- Provider unavailable or outcome ambiguous: do not auto-resubmit; preserve stable idempotency/reference and reconcile.
- Re-enable: re-evaluate compatibility, implementation registration, feature gate and provider readiness; do not replay historical operations as a side effect.
- Registry changes and admission denials should be auditable without logging credentials or sensitive payloads.

## Wiring sequence

1. Finish route, middleware, webhook, command, scheduler, queue, listener, permission, idempotency and recovery inventory.
2. Add characterization tests for current route names, middleware, customer/admin responses and webhook contracts.
3. Implement a deterministic implementation-registration catalogue (reviewed code only; no uploaded executable packages) and service-specific readiness checks.
4. Wire admission only to new-operation entry points, never indiscriminately to all routes or all controller methods.
5. Keep accepted-operation endpoints/jobs and Core finance/recovery pathways available after service disablement.
6. Migrate one bounded service behind compatibility-preserving delegation; compare route list and run focused, full CI, concurrency and frontend checks before moving another service.

## Current source audit — webhook routes

- POST /api/payments/webhooks/{provider}, route name payments.webhooks.receive, throttle 60/minute; handled by PaymentFundingController::webhook.
- POST /api/vtu/webhooks/{provider}, route name vtu.provider-webhooks.receive, provider allowlist sogo|dataway, throttle 60/minute; handled by VtuProviderWebhookController.
- API middleware appends EnsurePlatformAvailable. Do not add addon admission middleware to webhook routes. Signed events must be recorded idempotently and processed/reconciled even if new purchases are disabled.

## Scheduled commands and queue drain

Source is routes/console.php (Laravel 11/12 style console route registration; app/Console/Kernel.php does not exist in the current tree).

| Command / schedule | Current cadence | Ownership and safety note |
|---|---|---|
| vtu:run-scheduled-purchases --limit=100 | Every minute; without overlap | New due purchase must pass VTU admission; stale processing rows pause for review; accepted order recovery remains allowed |
| queue:work database --stop-when-empty --timeout=60 --max-time=55 | Every minute; without overlap | Generic durable queue worker; inspect each job handler and retry policy; do not block Core finance/recovery jobs by addon state |
| payments:reconcile-paystack --limit=100 | Every five minutes; without overlap | Core funding reconciliation |
| payments:reconcile-refunds --limit=100 | Every five minutes; without overlap | Core refund reconciliation and stale-refund monitoring |
| vtu:process-provider-webhooks --limit=100 | Every minute; without overlap | Process recorded signed VTU webhook events idempotently |
| vtu:reconcile-orders --limit=100 | Every five minutes; without overlap | Recover recorded VTU orders; provider configuration check applies |
| education:reconcile-orders --limit=100 | Every five minutes; without overlap | Recover recorded education orders |
| vtu:monitor-orders | Every 15 minutes; without overlap | Operational monitoring; should remain available during addon disablement |
| payments:monitor-refunds | Command available; no explicit schedule declaration found | Operator/automation invocation must be verified separately |
| vtu:catalog-sync --apply | Command available; no explicit schedule declaration found | Catalogue mutation requires explicit apply; new service admission stays separately gated |
| semizzy:bootstrap-admin and semizzy:permission:grant/revoke | Operator-invoked CLI | Privileged operations, allowlisted and force-confirmed; not service addon commands |

## Middleware and authorization observations

- Web routes use guest/auth groups and per-route throttles; admin routes mostly inherit auth + can:staff.permissions.manage from the /admin group. Refund approvals use the narrower can:refunds.approve permission. Preserve all middleware when delegating.
- Global API middleware includes EnsurePlatformAvailable, not service-specific lifecycle checks. The lifecycle contract is not yet applied to HTTP routes.
- Existing payment and VTU webhook endpoints authenticate signatures and validate provider/event data. A disabled service must not make webhook receipt or Core recovery unavailable.
- Provider catalogue import is admin-only, provider-allowlisted and throttled. Catalogues are not proof that live purchasing is released.

## Remaining audit items

- Enumerate every queue job class and its tries, retryUntil, timeout, unique/overlap policy, failed handler and dispatch origin.
- Complete all web route group middleware and endpoint-level permission/idempotency/ledger mappings.
- Trace every webhook consumer and provider callback/return route, including event replay and payload-conflict behavior.
- Review all scheduler declarations, console commands, event listeners, queued notifications and cleanup/retention jobs.
- Add tests that assert webhook/reconciliation behavior remains callable after an addon is disabled and that new purchase endpoints deny admission.
- Run AddonLifecyclePolicyTest in CI and verify all existing workflows. Passing tests must be confirmed from Actions rather than assumed.
