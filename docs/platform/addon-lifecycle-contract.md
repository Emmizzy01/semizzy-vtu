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


## Queue/job audit addendum

The current tracked application has one class under app/Jobs: ProcessVtuBulkPurchaseBatch. It declares tries=3 and timeout=60, claims pending items under row lock, records uncertain outcomes as unknown, and its failed handler quarantines stale processing items for reconciliation instead of blindly resubmitting. It redispatches only when pending items remain. This behavior must remain available for already accepted batches even if the VTU addon is disabled.

The tracked app also contains a queued-capable workspace invitation notification, but the current notification's delivery channel is mail; dispatch origin and whether queueing is configured must be checked before any lifecycle change. LoginOtpMail is a Mailable, not itself a queued job. No app/Events or app/Listeners directories were found in the recursive source tree, and no app/Console/Kernel.php exists; console commands and schedules are declared in routes/console.php.

The queue worker is a generic database queue drain. Do not put a global addon check around queue execution: the queue includes Core-owned work as well as service work. Any addon gate belongs at the handler's new-operation admission boundary; accepted work must continue through the Core recovery policy.

### Current route-group middleware boundary

- routes/web.php guest routes include throttles for login, login challenges, OTP/TOTP verification, registration and password reset.
- The main authenticated web group covers account/profile/security, KYC/tier actions, wallet transfers/payouts, service purchase, scheduled purchases and customer service requests. Per-route throttles apply to mutations and sensitive reads.
- The admin prefix group inherits auth and can:staff.permissions.manage; refund approval routes use can:refunds.approve separately. Admin mutations generally carry explicit throttles.
- routes/api.php contains only the two webhook route declarations identified above. bootstrap/app.php appends EnsurePlatformAvailable to API requests and appends HandleInertiaRequests, EnsurePlatformAvailable, EnsureAccountIsActive and TrackUserSession to web requests.
- No addon-specific admission middleware is currently registered in bootstrap/app.php or attached to service routes.

This audit is source-based; it does not yet prove all dispatched notification paths, queue backend settings, database uniqueness constraints, or every route's effective runtime middleware. Those remain acceptance checks.


## Route characterization and dispatch-origin audit — 2026-10-21

**Latest reviewed commit:** `5263b324e6df3b184dd8063db849728197164461`  
**Characterization test:** `tests/Feature/RouteContractCharacterizationTest.php`  
**Verification state:** The newest workflow runs are still in progress. Do not treat this audit as signed off until the current commit's PHP 8.3/8.4, MariaDB, security and asset-build jobs complete successfully.

### Characterized route contracts

The new route characterization test records these existing contracts without issuing live provider requests or changing route behavior:

- `payments.webhooks.receive`: POST `api/payments/webhooks/{provider}`, rate limited at 60/minute.
- `vtu.provider-webhooks.receive`: POST `api/vtu/webhooks/{provider}`, rate limited at 60/minute and constrained to the Sogo/Dataway provider allowlist.
- `login.store`: POST `login`, guest-only and rate limited at 5/minute.
- `wallet.manual-deposits.submit`: authenticated POST `wallet/manual-deposits`, rate limited at 5/minute.
- `admin.manual-deposits.review`: authenticated, staff-permission-gated POST, rate limited at 10/minute.
- `admin.payment-refunds.approve`: authenticated POST guarded by `can:refunds.approve`, rate limited at 20/minute.
- `wallet.vtu.orders.create`: authenticated POST purchase route; inherited 60/minute group throttle plus 10/minute route throttle.

These tests characterize routing and middleware only; they do not prove controller-level financial invariants, signed callback acceptance, or real provider behavior. Webhook routes must remain outside customer authentication and any addon new-operation admission middleware so callbacks for accepted operations can be recorded and reconciled.

### Queue and notification dispatch findings

- The only tracked class under `app/Jobs` is `ProcessVtuBulkPurchaseBatch`. It is dispatched for bulk processing and self-dispatches while pending items remain. It claims only pending items under a row lock; uncertain outcomes become `unknown`; stale in-flight items are quarantined after 30 minutes; its failure handler does not blindly resubmit an item.
- The scheduled-purchase command in `routes/console.php` invokes `VtuOrderService::purchase` using a stable key derived from schedule ID and due timestamp. Stale processing schedules are paused for manual reconciliation, not automatically replayed. Scheduler overlap prevention is declared for the command.
- Database queue configuration defaults to `database`; `after_commit` is false and `retry_after` defaults to 90 seconds. This must be considered when evaluating transactions that dispatch work before commit and worker timeout/retry windows. Do not change these global settings without a separate compatibility review.
- `BusinessWorkspaceController::invite` calls `$target->notify(new WorkspaceInvitationNotification(...))`. The notification uses the mail channel and the `Queueable` trait, but does **not** implement `ShouldQueue`; the trait alone does not make it a queued notification. Do not classify this as a confirmed queued dispatch.
- `LoginOtpMail` is a Mailable, not a job by itself. Its actual dispatch/delivery path must be judged at its call sites.
- The scheduled database queue worker is a generic Core worker. It must not be blocked by addon state, because its queue can contain Core financial/recovery work.

### Callback and idempotency observations

- Payment webhook intake normalizes and validates event structure, stores a payload hash and unique provider/event identity, locks the payment intent while applying a settlement, and checks amount/currency before settled-status shortcuts. A second event ID for the same provider transaction is serialized by the intent lock; a different transaction ID against an already settled intent is treated as a conflict.
- VTU webhook intake accepts only the configured Sogo/Dataway providers, validates provider-specific signatures over the raw body, enforces a payload size bound, stores a payload hash, and returns conflict when an existing event ID is reused with a different payload.
- VTU webhook processing claims eligible events under a row lock and a five-minute backoff window. Unmatched provider references remain pending with an explanatory processing error; transient failures remain eligible for retry after backoff; unsupported event/status combinations are marked ignored.
- Payment reconciliation treats provider unavailability as unresolved evidence, records a review case, and does not infer payment success from an exception. Provider success must match the recorded reference, amount, currency and transaction identifier before settlement.
- Manual-deposit submission creates a unique public ID and an internal idempotency reference, records an audit entry, and atomically claims a unique evidence fingerprint. Duplicate evidence is flagged rather than silently treated as a new trusted receipt. This is not, by itself, proof that approval is idempotent; the existing approval workflow and ledger assertions still require focused regression evidence.

### Remaining audit gates

1. Wait for all workflows on commit `5263b324e6df3b184dd8063db849728197164461` to finish. Current observed status: frontend build in the Laravel workflow passed; PHP 8.3, PHP 8.4, asset publishing and MariaDB security verification were still running at last inspection.
2. If the characterization test or any CI job fails, inspect the failing step, fix the actual cause on `main`, and rerun the affected workflow before continuing.
3. Complete call-site tracing for every job dispatch and mail delivery, plus payment webhook event replay/payload-conflict behavior, manual-deposit approval double-credit protection, refund reconciliation, VTU order idempotency and recovery endpoint permissions.
4. Add/adjust tests where source inspection alone does not prove the behavior. Never trigger a real transaction to test these properties.
5. Keep lifecycle admission unwired and do not migrate a service until the route contracts and required CI gates pass.
