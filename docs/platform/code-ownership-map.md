# SEMIZZY ONE — Existing Code Ownership Map

**Repository:** https://github.com/Emmizzy01/semizzy-vtu  
**Branch:** `main` only  
**Purpose:** Categorize the existing implementation before moving service ownership behind runtime-managed addons. This is an inventory and migration guide, not a claim that conversion is complete.  
**Theme freeze:** Existing admin and customer dashboard designs are protected. This map authorizes no visual redesign.

## Ownership rules

1. Core owns identity/authentication, roles and permissions, account tier policy, wallet and immutable ledger, deposits/funding, payouts/transfers, pricing, global settings, security/audit, transaction recovery, and the addon manager.
2. Service addons own only their service catalogue, service-specific inputs and validation, service orders, service provider adapters, service-specific admin controls, and service-specific recovery orchestration.
3. Shared services remain shared. An addon must call Core contracts for authentication, authorization, wallet/ledger mutations, pricing, feature gates, audit and recovery rather than cloning those systems.
4. Provider credentials and internal cost/routing data stay server-side. A database manifest alone does not mean an addon is runtime-registered or ready for sale.
5. Preserve existing URLs, route names, middleware, request/response contracts, database records, customer history and admin/user themes during migration.
6. Keep accepted transactions recoverable when an addon is disabled. Disablement may stop new operations but must not strand an existing payment, order, refund or payout.
7. Make only additive, forward-compatible database changes. Never reset or truncate production data. No live financial/provider transactions during tests.

## Existing Core locations

| Category | Existing source locations | Boundary |
|---|---|---|
| Addon manager and lifecycle foundation | `app/Models/PlatformAddon.php`; `app/Services/Addons/PlatformAddonRegistry.php`; `app/Services/Addons/AddonLifecyclePolicy.php`; `app/Http/Controllers/Admin/PlatformAddonController.php`; `resources/js/Pages/Admin/PlatformAddons/Index.tsx` | Core registry/policy and admin control surface. Lifecycle policy currently needs explicit runtime implementation registration and carefully scoped admission wiring; do not equate registry status with executable integration. |
| Authentication, account and identity | `app/Http/Controllers/Account/`; authentication-related routes, middleware, policies and security services | Core identity. Do not relocate account identity into individual service addons. |
| Admin governance and global controls | `app/Http/Controllers/Admin/FeatureControlController.php`; `GovernanceController.php`; `IntegrationSettingsController.php`; `PlatformSettingsController.php`; `StaffAccessController.php`; `SecurityEventsController.php` | Core owns global feature switches, integration secrets, global settings, staff access and audit/security controls. Service-specific catalogue settings may live in an addon, subject to Core authorization and audit. |
| Wallet, ledger and funding | Wallet/finance controllers, models, services, migrations and `docs/finance/` | Core money movement and reconciliation. Addons must never write balances directly or maintain their own wallet. |
| Shared provider infrastructure | `app/Providers/AppServiceProvider.php`, shared integration settings and Core reconciliation/webhook components | Shared infrastructure. Provider bindings currently include service-specific adapters; future relocation must preserve binding names and be done with characterization tests. |
| Shared UI shell and navigation | `resources/js/Pages/`, shared layouts/components and dashboard route definitions | Keep the current theme unchanged. Only change ownership/wiring when required; no redesign in addon conversion. |

## Service addon boundaries

| Addon | Existing evidence | Target ownership |
|---|---|---|
| VTU / airtime / data | `app/Http/Controllers/Vtu/`, `app/Models/Vtu*`, `app/Services/Vtu/`, VTU routes and catalogue/order tests | Service catalogue, service input/verification, order orchestration, provider adapters and service-specific admin catalogue. Core remains owner of debit/ledger, pricing primitives, audit and generic recovery. |
| Bill payments | VTU biller verification controllers/services and biller catalogue/order paths | Electricity, TV/IUC and other approved billers; provider-backed verification must fail closed when inconclusive. Keep this separate from wallet ownership. |
| Education | `app/Http/Controllers/EducationServiceController.php`; `app/Services/Education/`; `app/Models/Education*`; `resources/js/Pages/Services/Education.tsx`; `resources/js/Pages/Admin/EducationServices/`; education migrations and tests | Education catalogue, inputs, encrypted delivery payload, provider attempts and education-order orchestration. Core still owns payment/ledger primitives and generic recovery safeguards. |
| Streaming subscriptions | Streaming controller, catalogue model, customer/admin pages and tests | Streaming catalogue, validation, purchase orchestration and adapter-specific logic. Preserve existing page and route contracts. |
| Government document registration | Existing document-related routes/controllers/models, if present | Approved document registration categories and provider-specific submission/status. Do not label unimplemented provider paths as live. |
| Identity/document verification | Existing NIN/BVN/passport/licence verification components, if present | Verification workflows and adapters. Keep distinct from document registration and use Core identity, authorization and audit. |
| VTU website-request service | `VtuWebsiteRequestController` and `/service-requests/vtu-website` routes | Customer request intake and staff fulfilment tracking only; not a website builder, domains or hosting product. |
| Additional approved services | Requirement register and current source audit | Add only after tracing existing code and approved scope. Do not create placeholder addons or invent readiness. |

## Existing route contracts that must be characterized before migration

The current route/recovery audit records these customer entry points: `education-services.index`, `education-services.delivery`, `vtu-website-requests.index`, `vtu-website-requests.store`, streaming subscription routes, `wallet.vtu.*`, funding/deposit/refund/virtual-account/transfer/payout routes, and scheduled-purchase routes. Admin contracts include `/addons`, manual-deposit review/settings, payment-gateway settings, provider catalogue import, education administration, VTU order reconciliation, streaming administration, VTU website-request administration, refund operations and VTU product catalogue management.

Before moving a handler, capture its route name, middleware, role/permission gate, validation, side effects, provider calls, persistence, audit events, customer response shape and failure/recovery behavior. Keep webhooks and accepted-operation recovery out of blanket addon admission middleware.

## Safe conversion sequence

1. Reconcile this map with `docs/platform/addon-requirement-register.md`, `docs/platform/addon-route-recovery-map.md`, the binding blueprint and the owner's agreed 20-phase roadmap.
2. Inventory every service's routes, controllers, models, migrations, jobs, commands, events/listeners, provider bindings, permissions, UI pages and tests.
3. Add characterization tests for current route contracts, authorization, provider outcomes, idempotency and recovery before moving code.
4. Introduce explicit reviewed implementation registration and per-addon readiness; no arbitrary uploaded code execution.
5. Convert one bounded service at a time through compatibility-preserving delegation. Do not bulk-move files before dependency and migration analysis.
6. For each service, run focused PHP tests, authorization tests, provider/recovery tests, MariaDB concurrency tests where relevant, frontend checks and full CI on the resulting exact `main` commit.
7. Verify the admin/user themes and routes remain unchanged; build/publish assets for cPanel deployment.
8. Track real-provider credentials, HTTPS passkey checks, real-device OTP and cPanel-specific checks as deployment gates, separate from code changes.

## Current audit limits

The inspected repository tree at the time this map was authored contains 691 entries and the latest observed `main` commit was `6cf99b653496526583bb9d2cee27916a194550d9`. Existing addon foundations and per-service code are present, but the checked-in addon lifecycle contract explicitly says it is not yet connected to routes, jobs or provider resolution. This map does not claim any service has completed runtime addon conversion, and no local test runner or production database was used for this inventory.
