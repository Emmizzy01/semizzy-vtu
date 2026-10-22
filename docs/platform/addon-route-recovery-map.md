# SEMIZZY ONE — Service Route and Recovery Ownership Audit

**Branch:** `main` only  
**State:** Initial route mapping from `routes/web.php`; not complete endpoint acceptance.  
**Rule:** Preserve existing URL, route-name, middleware, request/response and UI contracts during future delegation.

This map is a source audit to guide addon conversion. Route declarations can inherit middleware from surrounding groups; the exact source declaration remains authoritative. Before any route is moved, inspect the full group context and controller behavior.

## Customer service entry points found

| Current route / name | Current handler | Intended owner | Conversion rule |
|---|---|---|---|
| `GET /services/education` — `education-services.index` | `EducationServiceController@index` | Education addon | Keep route name and customer response contract |
| `GET /services/education/orders/{order:public_id}/delivery` — `education-services.delivery` | `EducationServiceController@delivery` | Education addon | Keep ownership/authorization and encrypted delivery protections |
| `GET /service-requests/vtu-website` — `vtu-website-requests.index` | `VtuWebsiteRequestController@index` | VTU website-request addon | Preserve as service request; not website-builder scope |
| `POST /service-requests/vtu-website` — `vtu-website-requests.store` | `VtuWebsiteRequestController@store` | VTU website-request addon | Keep throttling, validation, persistence and audit events |
| `GET /services/streaming-subscriptions` | `StreamingSubscriptionController@index` | Streaming addon | Preserve existing UI and availability gate |
| `POST /wallet/manual-deposits` — `wallet.manual-deposits.submit` | `ManualDepositController@submit` | Core finance | Pending review only; never credit from uploaded evidence |
| `POST /wallet/funding-intents` — `wallet.funding-intents.create` | `PaymentFundingController@createIntent` | Core finance | Keep gateway mode, idempotency and webhook settlement boundaries |
| `POST /wallet/refund-requests` — `wallet.refund-requests.create` | `PaymentRefundController@requestRefund` | Core finance | Preserve eligibility and safe refund state transitions |
| `GET /wallet/virtual-accounts`, `POST /wallet/virtual-accounts` | `UserVirtualAccountController` | Core finance | Provisioning remains gated; ambiguous outcomes need recovery |
| `GET /wallet/transfers/history`, `POST /wallet/transfers` | `PeerWalletTransferController` | Core finance | Preserve row locking, ledger and idempotency |
| `GET/POST /wallet/bank-transfer` and beneficiary/history subroutes | `BankPayoutController` | Core finance | Keep payout submission gated and reconciliation available |
| `/wallet/scheduled-purchases` | `ScheduledServicePurchaseController` | Core scheduler + addon handler | Only supported service handlers; unknown outcomes pause safely |
| `/wallet/vtu/*` | `VtuOrderController`, `VtuBillerVerificationController` and existing Inertia routes | VTU/bills addon(s) | Keep products/orders/bulk/recovery routes and customer response contracts |

## Admin entry points found

| Current route / name | Current handler | Intended owner | Conversion rule |
|---|---|---|---|
| `GET/POST /addons`, `PUT /addons/{addon}/status` | `PlatformAddonController` | Core addon manager | Registry state must not imply runtime readiness |
| `GET /manual-deposits`, `/manual-deposits/settings`, evidence and review routes | `ManualDepositController`, `ManualDepositSettingsController` | Core finance | Preserve staff authorization, pending-review semantics and audit |
| `GET/PUT /payment-gateway` | `IntegrationSettingsController` | Core finance/settings | Preserve explicit Test Mode and secret handling |
| `/api-providers* `, `/provider-catalogue/{provider}/import` | `IntegrationSettingsController`, `ProviderCatalogueImportController` | Core settings + addon-specific adapter declarations | Do not leak provider identity/cost/routing to customer catalogue |
| `/education-services*` | `EducationServiceController` | Education addon | Preserve admin permission and catalogue validation |
| `/admin/vtu-orders`, `/admin/vtu-orders/{publicId}/reconcile` | `VtuOrderOperationsController` | VTU addon + Core recovery primitives | Reconciliation must remain possible after service deactivation |
| `/admin/streaming-subscriptions*` | `StreamingSubscriptionController` | Streaming addon | Preserve existing admin authorization |
| `/vtu-website-requests*` | `VtuWebsiteRequestController` | VTU website-request addon | Preserve customer request history and staff status audit |
| `/admin/payment-refunds*` | `PaymentRefundController` | Core finance | Separate refund approval permission from general admin access |
| `/admin/vtu-products*` | `VtuProductCatalogueController` | VTU addon | Keep import, activation and pricing gates server-side |

## Provider, job and domain ownership observations

- `AppServiceProvider::register()` currently binds four education adapters (Sogo, GSUBZ, VTPass and Dataway) and builds `MultiProviderVtuRouter` with VTU provider clients. These are direct Core-container bindings today; replace with an explicit, deterministic registration seam only after interface and resolution tests are added.
- Existing background/recovery components include `ProcessVtuBulkPurchaseBatch`, the scheduled purchase runner, VTU provider webhook processing/monitoring, education order coordination, payment reconciliation/refund monitoring and bank-payout reconciliation. A route-only gate would not safely control these operations.
- Models and migrations already persist VTU orders, provider attempts and webhook events; education orders/attempts; streaming products; VTU website requests/events; bulk purchase batches/items; scheduled purchases; manual deposits/audits; payment intents/webhook events/refunds/reconciliation; and wallet ledger/transfers/payouts. Do not move shared financial records into service-owned tables or delete history during conversion.
- Existing feature controls and addon registry are distinct. Service admission should require the relevant addon to be installed/registered, compatible, enabled and independently ready, while Core settlement/recovery capabilities must remain available for already accepted work.
- The route map above is intentionally not exhaustive for authentication, generic account/security, support/privacy, every payment webhook, console scheduler, event listener or queue entry point. Those remain mandatory audit items before runtime-gating code is introduced.

## Required next checks before code conversion

1. Trace the complete route group middleware and route names, including every webhook/callback and all admin actions.
2. Enumerate console commands, scheduled callbacks, queue jobs, event listeners and retry/reconciliation entry points.
3. Map each endpoint to permission, feature gate, provider readiness, wallet debit/credit, idempotency key, order state transition and recovery action.
4. Add characterization tests proving existing route names and response contracts remain unchanged.
5. Define separate policies for **new-request admission** and **accepted-operation recovery**.
6. Implement the smallest compatibility layer and tests before migrating one service at a time; start with a bounded service and keep all live purchase gates closed.

## Follow-up audit checkpoint — 2026-10-22

### Global middleware and API boundary confirmed from source

- `bootstrap/app.php` loads `routes/web.php`, `routes/api.php`, and `routes/console.php`; the health endpoint is `/up`.
- The web middleware stack appends `HandleInertiaRequests`, `EnsurePlatformAvailable`, `EnsureAccountIsActive`, and `TrackUserSession`. Route-specific `auth`, `can:...`, and throttle middleware remain additional constraints; these must be captured per route before changing service ownership.
- The API middleware stack appends `EnsurePlatformAvailable` only. `POST /api/payments/webhooks/{provider}` is throttled at 60/minute and named `payments.webhooks.receive`; `POST /api/vtu/webhooks/{provider}` is constrained to `sogo|dataway`, throttled at 60/minute, and named `vtu.provider-webhooks.receive`.
- Neither API webhook route currently applies addon admission middleware. Do not add a blanket addon gate to webhook intake: validate provider signature/event identity and let accepted-operation processing/reconciliation decide whether an existing order can advance.

### Scheduled command and recovery entry points confirmed from `routes/console.php`

| Command | Schedule in source | Ownership / safety requirement |
|---|---|---|
| `vtu:run-scheduled-purchases --limit=100` | Every minute | Core scheduler; dispatch only through the verified service handler and revalidate funds/product/recipient; pause unknown outcomes |
| `queue:work database --stop-when-empty --timeout=60 --max-time=55` | Every minute | Core queue drain; must not depend on a customer-facing service route being enabled |
| `bank-payouts:quarantine-stale-queued --minutes=30 --limit=100` | Every five minutes | Core finance; quarantine stale submissions for reconciliation, never blindly resubmit |
| `payments:reconcile-paystack --limit=100` | Every five minutes | Core funding; provider verification and idempotent settlement |
| `payments:reconcile-refunds --limit=100` | Every five minutes | Core finance; reconcile approved/existing refunds safely |
| `vtu:process-provider-webhooks --limit=100` | Every minute | VTU accepted-order recovery; process verified webhook events idempotently |
| `vtu:reconcile-orders --limit=100` | Every five minutes | VTU accepted-order recovery; reconcile ambiguous orders without blind purchase resubmission |
| `education:reconcile-orders --limit=100` | Every five minutes | Education accepted-order recovery; only dispatched orders, no new purchase submission |
| `vtu:monitor-orders` | Every 15 minutes | VTU operations monitoring |
| `vtu:recover-bulk-purchase-batches --limit=100` | Every minute | VTU bulk recovery; preserve atomic claim and quarantine unknown/stale work |

Additional commands declared in the same file but without an explicit schedule in the inspected source include `payments:monitor-refunds` and `vtu:catalog-sync`; the latter has an explicit `--apply` flag. Do not infer that either command is scheduled. `semizzy:schema-audit` is read-only and does not repair or mutate the schema.

### Conversion implications and next audit actions

- `AppServiceProvider::register()` now contains no direct VTU or education adapter construction; its comment says adapters are registered by addon-owned providers. Verify those addon providers, their registration tests, and dependency resolution before changing the registry or declaring provider ownership complete.
- The registry currently maps `service.vtu`, `service.airtime`, `service.data`, legacy `service.airtime_data`, `service.electricity`, `service.tv`, `service.bills`, `service.education`, `service.streaming`, and `service.vtu-website-requests`. These entries share some controller handlers and are not proof that every service has an independent end-to-end implementation or provider-ready purchase operation.
- Streaming currently registers catalogue/admin catalogue operations only; there is no registered customer purchase operation. Keep customer purchase disabled until provider lookup and accepted-order recovery contracts have test evidence.
- Remaining audit work: capture exact route names/middleware/authorization for the rest of the admin routes and service routes; enumerate all job classes and dispatchers plus provider adapter registrations; map each operation to idempotency, wallet ledger effects, state transitions, and recovery path; add characterization tests before migrating one bounded service.
- No runtime gates, routes, financial behavior, provider activation, database schema, or theme were changed in this audit checkpoint.
