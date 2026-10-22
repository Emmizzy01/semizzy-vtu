# SEMIZZY ONE — Addon Architecture Conversion Progress

**Canonical repository:** https://github.com/Emmizzy01/semizzy-vtu  
**Canonical branch:** `main` only  
**UI/theme freeze:** Active. No visual redesign or theme changes are authorized.  
**Production safety:** No live financial/provider actions are authorized by this work.

## Mission and status

## Binding owner rules for all phases

- **Deployment classification:** Anything requiring the live server, cPanel, or deployed environment to test/inspect/verify belongs in **Deployment checklist — execute during deployment**. Mark it deferred until actually run. It is not an unfinished development task and must not keep a development phase or bulk action open. Finish repository/CI/test/sandbox work independently; never claim a live check passed without live evidence.
- **No unsafe production actions:** Do not access/change production, run migrations, alter production data, or trigger real financial/provider transactions merely to clear a checklist. Production schema/data changes require schema and migration review, verified recoverable backup, and explicit authorised operator approval.
- **Roadmap continuity:** Continue the agreed 20-phase addon conversion from the current evidenced checkpoint; do not restart phases or substitute a new roadmap. Do not mark phases complete without acceptance evidence.
- **Dashboard theme freeze:** Preserve the current admin and user dashboard design/themes. No redesign/restyling or layout replacement during addon conversion unless the owner explicitly authorizes it.
- **Core/addon boundary:** Core owns authentication/identity, users and permissions/tier policy, wallet/immutable ledger, payments/funding/payouts/transfers, pricing, global settings, security/audit, notifications, transaction recovery, and addon management. Each approved service addon owns service-specific catalogues, inputs, orders, provider adapter, admin controls, and service-specific recovery orchestration, and must use Core contracts rather than duplicating Core systems.

This document records evidence for the controlled 20-phase reconciliation and addon-conversion mission. It is a progress ledger, not a completion claim. Requirements remain open until implementation and relevant verification evidence are recorded.

## Latest verified checkpoint — Streaming addon boundary

**Evidence date:** 2026-10-22. **Latest test correction:** `095b228daec70affcad0cd424c101a1bf3619d87` on `main`. **Verification pending for this correction.**

- Test commit `3e17ff021e2be96828dbf7c4fce06fa376356bd4` added a regression test asserting that the customer streaming catalogue remains unavailable when the `service.streaming` addon is disabled, even if its feature flag is enabled.
- GitHub Actions run [39818936774 — Laravel and frontend checks](https://github.com/Emmizzy01/semizzy-vtu/actions/runs/39818936774) reached terminal **failure** on that exact test commit. The frontend build passed; both MariaDB error-123 diagnostic jobs passed. The PHP 8.4 job failed at step **“Lint all PHP application, migration, route, and test files”** before environment preparation or Laravel tests, because `tests/Feature/StreamingSubscriptionCatalogueTest.php:44` contained an unexpected backslash in the fully qualified `PlatformAddon` class reference. This was a test-file syntax defect, not evidence of a runtime addon-gating defect.
- First correction `edff4d28d796cac589e019600c425754821dc5ed` did not fully remove the escaped class reference; the asset workflow's Laravel test step exposed the same parse error at line 45. This was inspected from the actual job log.
- Second correction `bb48dc296f4c3afebf5a80400dd37722aee8a9ec` replaced the malformed reference but introduced a duplicate `PlatformAddon` import. The latest lint log identified the exact fatal error: `Cannot use App\\Models\\PlatformAddon as PlatformAddon because the name is already in use`.
- Third targeted correction committed as `095b228daec70affcad0cd424c101a1bf3619d87`: remove the duplicate import, leaving one valid `App\\Models\\PlatformAddon` import and `PlatformAddon::query()`. No application source behavior or dashboard design was changed.
- **Pending:** CI for `095b228daec70affcad0cd424c101a1bf3619d87` must reach a terminal result. Do not count the streaming regression as passed until lint and the relevant Laravel test/full suite run successfully.
- Existing VTU accepted-order recovery, reconciliation, and refund-once protections remain in place; this test-only correction does not change them. Streaming customer purchases remain locked because provider and accepted-order recovery workflows have not been verified. No live provider or financial transactions were performed.


## Phase 0 — Initial repository baseline

**Status: PARTIALLY VERIFIED — continue baseline inspection before refactoring.**

Evidence captured on 2026-10-21:

- Repository identity verified: `Emmizzy01/semizzy-vtu`.
- Default and active branch: `main`.
- Observed HEAD: `15fa0addcc1856593662b7eb2d304d900b3b9cc6`.
- Runtime stack from repository manifests: Laravel 12, PHP ^8.2, Inertia.js 2, React 19, TypeScript, Vite 6, MySQL/MariaDB.
- Existing addon implementation exists:
  - `app/Models/PlatformAddon.php`
  - `app/Services/Addons/PlatformAddonRegistry.php`
  - `app/Http/Controllers/Admin/PlatformAddonController.php`
  - `resources/js/Pages/Admin/PlatformAddons/Index.tsx`
  - `database/migrations/2026_10_20_000005_create_platform_addons_table.php`
  - `tests/Feature/PlatformAddonRegistryTest.php`
  - `docs/platform/addon-registry.md`
- Existing automated test workflows include Laravel/frontend checks, Phase 1 security verification, and frontend asset build.
- For HEAD `15fa0addcc1856593662b7eb2d304d900b3b9cc6`, these GitHub Actions runs were observed completed successfully:
  - [Laravel and frontend checks](https://github.com/Emmizzy01/semizzy-vtu/actions/runs/39620926729)
  - [Phase 1 security verification](https://github.com/Emmizzy01/semizzy-vtu/actions/runs/39620927236)
  - [Build and publish Semizzy frontend assets](https://github.com/Emmizzy01/semizzy-vtu/actions/runs/39620926867)
- The main branch has no enabled branch protection according to the repository branch API at the time of inspection. This is an operational observation, not permission to bypass careful diff review.
- The current addon registry documentation explicitly states that registry status does not dynamically load code and does not yet provide code-package installation, signed packages, automatic addon migrations, or runtime module loading.

**Baseline limitations:** The GitHub connector provides repository inspection and workflow metadata but does not provide a local shell/test runner in this session. The successful Actions runs are the available CI evidence; tests have not been rerun locally by this audit. Existing production schema/data were not accessed or changed. Browser screenshots/theme comparison have not yet been performed.

## Initial architecture finding

The current addon registry is a manifest/dependency/compatibility registry and admin control surface. It is not yet proof that every service is a runtime-managed addon. Current provider bindings are registered centrally in `app/Providers/AppServiceProvider.php`, while service routes/controllers and service-specific code are registered directly in the application (for example education, VTU, streaming subscriptions, and VTU website requests). This indicates that service ownership and runtime integration need an evidence-based audit before conversion.

Do not remove or relocate code based only on these examples. Trace routes, jobs, models, migrations, provider calls, shared Core dependencies and tests for each service before migration.

## Blueprint authority and scope

The authoritative living specification is `docs/SEMIZZY-BLUEPRINT.md`, supplemented by `docs/PROJECT-RULES.md`, the Phase 1 completion register, the remaining blueprint gap audit, platform/security documentation, and explicit owner decisions from project history.

Binding decisions to preserve:
- `main` only; no feature branches.
- Keep current user and admin themes/design unchanged.
- Preserve all existing agreed functionality and production data.
- Core owns shared authentication, identity/roles/levels, wallet/ledger, pricing, settings, security, transaction state/recovery, and addon infrastructure.
- Service-specific business logic belongs to the appropriate addon; no duplicate Core wallet/auth/pricing/settings.
- Do not reintroduce removed loans, forex, investment, website-builder, domains or hosting scope unless explicitly approved later.
- Live provider purchases and real financial operations remain gated; no real transactions for tests.
- Deployment-only checks must be tracked separately from development/CI work and must not block unrelated implementation.
- Addon disablement must not destroy data or strand accepted financial/service transactions.

## Preliminary ownership map — not final acceptance

| Capability | Intended ownership | Initial status |
|---|---|---|
| Authentication, users, roles, account type/level | Core | Existing implementation; full blueprint audit open |
| Wallet, ledger, payment intents, refunds, payouts and transaction safety | Core | Existing implementation; endpoint/recovery acceptance remains open |
| Global settings, feature controls, permissions and audit infrastructure | Core | Existing implementation; coverage audit open |
| Addon manifest registry and dependency checks | Core | Implemented; existing documentation explicitly limits it to registry status |
| VTU/airtime/data/catalogue/purchase domain | VTU addon | Existing service implementation; runtime addon integration not yet demonstrated |
| Bill payment workflows | Bill-payments addon(s) according to blueprint | Needs inventory and ownership verification |
| Education catalogue/orders/providers | Education addon | Existing implementation; runtime addon integration not yet demonstrated |
| Government document registration | Government-services addon | Blueprint scope must be reconciled against actual implementation |
| NIN/BVN and other identity/document verification | Verification addon or explicitly documented service boundary | Must remain distinct from document registration |
| Streaming subscriptions | Subscription addon | Existing catalogue/page evidence; end-to-end and runtime ownership audit open |
| VTU website requests | VTU website request addon | Existing workflow evidence; runtime ownership audit open |
| Betting, SMM, social accounts, number verification | Separate approved addons as applicable | Blueprint coverage and actual implementation require verification |
| Provider adapters | Service addon ownership unless a genuinely shared abstraction is justified | Current central bindings require migration-map review |
| Current user/admin themes | Existing frontend | Frozen; no redesign permitted |

## Twenty-phase progress ledger

All phases begin as open until their acceptance criteria are evidenced.

| Phase | Scope | Status |
|---|---|---|
| 0 | Baseline and change control | Partially verified; see evidence above |
| 1 | Blueprint reconciliation and requirement register | In progress |
| 2 | Repository/service architecture audit | In progress |
| 3 | Reproducible test/build/theme baseline | CI evidence observed; baseline inventory incomplete |
| 4 | Core/addon ownership boundaries | Proposed boundary documented; code map pending |
| 5 | Addon contract and manifest | Existing contract partially implemented; runtime contract review pending |
| 6 | Registry lifecycle and runtime activation | Open |
| 7 | Admin addon management integration | Registry UI exists; operational behavior acceptance open |
| 8 | Shared Core interfaces | Open |
| 9 | Database/migration safety | Open |
| 10 | VTU/data addon migration | Open |
| 11 | Bills and education addon migration | Open |
| 12 | Government and document services | Open |
| 13 | Remaining approved service addons | Open |
| 14 | Provider/catalogue ownership | Open |
| 15 | Security, roles and transaction recovery | Existing work; comprehensive addon-boundary audit open |
| 16 | Feature flags and site controls | Existing work; addon integration audit open |
| 17 | User/admin functional integration | Open; visual freeze applies |
| 18 | Automated tests and recovery | Existing test coverage; new addon conversion tests pending |
| 19 | Full CI/frontend/deployment verification | Current HEAD CI runs observed successful; final post-change verification pending |
| 20 | Final blueprint coverage acceptance | Open |

## Required next actions

1. Complete the requirement register from the full blueprint and all linked project rules/gap audits.
2. Inventory every service route, controller, domain service, provider adapter, job, event, model/table, feature flag, permission, frontend destination and test.
3. Identify which existing services can be safely registered through a deterministic runtime registry without changing routes or UI.
4. Specify the runtime activation semantics. Registry status must not claim a service is operational unless its implementation is registered, compatible, enabled, and satisfies its own provider/feature safety gates.
5. Design backward-compatible delegation/adapters and additive migrations only after the inventory is complete.
6. Test disablement carefully: block new operations while preserving history and allowing accepted/pending operations to reach a safe terminal state.
7. Keep live purchases, payouts and real financial transactions gated.
8. Update this progress ledger after every verified phase/checkpoint with commit SHA, changed paths, test evidence and open blockers.

## Acceptance reminder

No phase or requirement may be marked complete because a menu item, registry row, route, table or disabled placeholder exists. Completion requires the relevant implementation, authorization, UI integration, failure behavior, tests and documentation. No theme changes, production data resets, unrelated deletions, or unauthorized live transactions are permitted.


## Phase 1/2 — Additional verified source findings (2026-10-21)

### Addon registry behavior confirmed from source

- `PlatformAddonRegistry` validates core-version compatibility, dependencies, self-dependency, dependency cycles, duplicate core-owned keys, and dependency enable/disable order.
- `PlatformAddonController` exposes manifest registration and status toggles under the existing admin permissions group. Status changes are recorded in `platform_setting_audits`.
- `resources/js/Pages/Admin/PlatformAddons/Index.tsx` explicitly describes the page as a registry-only surface and retains the existing `DashboardLayout`.
- `docs/platform/addon-registry.md` explicitly states that status changes do not dynamically load code and that runtime module loading, automatic addon migrations and package installation are not implemented.
- `AppServiceProvider::register()` centrally binds VTU and education provider implementations. This is a migration candidate only after service dependency tracing.
- `routes/web.php` registers VTU, education, streaming subscription, and VTU website request controllers directly. Existing routes and contracts must remain stable during migration.
- `PlatformFeatureControl` is a separate Core feature rollout mechanism. It has owner/dependency/implemented fields, but it is not a substitute for service-addon runtime registration. Do not merge the two registries blindly; define the relationship explicitly.
- The platform feature-control migration includes a disabled, not-implemented `service.websites` row describing website builder/domain workflows. This conflicts with the owner's later explicit removal of website builder and domains/hosting. Preserve historical migration files; do not enable this row or reintroduce removed scope. Keep the separately approved VTU website request workflow distinct.
- The service implementation inventory already includes VTU, education, streaming subscriptions and VTU website requests. The owner blueprint also names additional categories that require coverage verification; an unimplemented category must remain accurately marked unavailable rather than be presented as operational.

### Current architectural conclusion

The present implementation is a **registry foundation, not yet a complete service-addon architecture**. The next implementation must connect a deterministic, reviewed service manifest/registration layer to actual service availability while preserving the existing route/controller contracts and the separate Core feature-control mechanism.

Before implementing runtime activation, finish a route-by-route inventory of customer endpoints, admin endpoints, webhooks, queue jobs, scheduled jobs, reconciliation/monitoring workers, and accepted transaction recovery paths. Disabling a service must prevent new operations but must not block webhook receipt, refunds, reconciliation, status checks, or recovery for already accepted operations.

### Phase 1/2 evidence still outstanding

- Complete blueprint requirement register, including all service categories, account types/levels, security controls, admin controls, money movement, provider contracts, privacy/retention/incident workflows, and deployment-only checks.
- Full endpoint-to-service ownership map, including route names, middleware, jobs, events, model/table ownership, and feature flags.
- Testable definition of addon enabled/disabled behavior for each service.
- Theme screenshot/visual comparison baseline.
- Final CI result for documentation commit `05dbf401c00210d8f96067c429ef8c1868a1f735`.


## Bulk Action — blueprint requirement register and source inventory (2026-10-21)

Created `docs/platform/addon-requirement-register.md` as the working reconciliation register for Core ownership, all explicitly approved service categories, removed scope, cross-cutting safety requirements, source evidence and phase gates. Requirements are conservatively classified as found/partial/open; no requirement is marked verified solely because a route, UI, table or test file exists.

Additional source inventory evidence from the current `main` tree:

- Repository tree contains 587 tracked blobs at the inspected commit; the tree API reported no truncation.
- Customer/admin route declarations confirm direct service-controller wiring for education, streaming subscriptions, VTU website requests and VTU order operations, while Core route groups also expose wallet transfers, bank payouts, manual deposits, payment funding/refund flows and scheduled purchases.
- `AppServiceProvider::register()` constructs the multi-provider VTU router and binds four education adapters directly. These bindings must be replaced only through a tested compatibility-preserving service registration seam, not by moving files in bulk.
- Dedicated models/jobs/tests exist for VTU bulk batches/items, scheduled purchases, VTU orders/provider attempts/webhooks, education orders/provider attempts, streaming catalogue, manual deposits, payment intents/refunds/reconciliation, wallet ledger/transfers and payouts.
- The inventory confirms why service disablement cannot be a global route kill switch: accepted orders may still need provider webhooks, reconciliation, refund, audit or safe recovery. New-request admission and existing-operation recovery need separate policies.

**Bulk action result:** requirement register and initial route/provider/domain inventory documented. This closes a documentation checkpoint only; Phase 1/2 remain open until the route-to-owner matrix includes exact route middleware/names and background entry points, and a deterministic lifecycle contract is implemented and tested. No application code, migration, UI/theme, production data, live provider or financial behavior was changed in this bulk action.


## Bulk Action — route and recovery ownership map (2026-10-21)

Created `docs/platform/addon-route-recovery-map.md` with current customer/admin route-to-owner mappings for education, streaming subscriptions, VTU website requests, VTU, manual deposits, funding/refunds, virtual accounts, transfers, payouts, scheduled purchases, provider catalogues and addon administration. It records the direct provider bindings and background recovery paths that must be considered alongside HTTP routes.

This is a bounded first map, not the final endpoint inventory: webhook/callback routes, all route-group middleware, every console schedule/listener/job, and endpoint-by-endpoint permission/idempotency/ledger mapping remain open. No runtime gating was added before those boundaries can be tested.


## Bulk Action — endpoint/background audit and lifecycle policy checkpoint (2026-10-21)

### Newly inspected entry points

- API routes: `POST /api/payments/webhooks/{provider}` (Paystack/approved payment providers validated in controller) and `POST /api/vtu/webhooks/{provider}` (route allowlist Sogo/Dataway), both throttled at 60/minute. API middleware appends `EnsurePlatformAvailable`; no addon-specific gate is wired.
- `bootstrap/app.php` loads `routes/console.php`; `app/Console/Kernel.php` is absent. Current scheduled work includes scheduled purchases and queue draining every minute; Paystack payment/refund reconciliation every five minutes; VTU provider webhook processing every minute; VTU/education order reconciliation every five minutes; VTU monitoring every 15 minutes. Catalog sync and refund monitoring commands exist without explicit schedule declarations in the inspected source.
- Existing webhook controllers validate signatures and payloads; VTU event intake uses provider/event identity and payload hash conflict checks. Payment settlement has provider/amount/currency validation, replay conflict handling and a live-settlement release gate. VTU and education workflows preserve ambiguous outcomes for reconciliation instead of blind resubmission.
- Admin route groups inherit authentication and staff permission checks; refund approval uses a separate narrow permission. Route-level throttles and names are compatibility requirements during any delegation.

### Lifecycle policy added

- Added `app/Services/Addons/AddonLifecyclePolicy.php`, a pure fail-closed admission policy requiring enabled registry state, compatible core version/dependencies, registered reviewed implementation, Core feature flag and provider readiness.
- Added `tests/Unit/AddonLifecyclePolicyTest.php` covering ready admission, each missing gate, and the key safety rule that Core may recover an accepted non-terminal operation after addon disablement while new, terminal or unrecoverable work is not admitted to recovery.
- Added `docs/platform/addon-lifecycle-contract.md` with the contract, current webhook/worker inventory, and remaining route/job audit work.

**Important boundary:** policy is deliberately not yet wired into routes, jobs or provider resolution. No service was migrated in this checkpoint. This avoids blocking webhooks/reconciliation or falsely declaring a registry row operational before the complete endpoint map and CI results are available. New tests must be confirmed by GitHub Actions before the policy can be treated as verified.

- Follow-up queue audit found one job class, `ProcessVtuBulkPurchaseBatch` (3 attempts, 60-second timeout), which atomically claims batch items and quarantines uncertain/stale work as unknown for reconciliation. No app/Events or app/Listeners directories were present in the current recursive tree; a mail notification and OTP mailable exist, but queue dispatch/configuration still needs trace validation.
- Route-group middleware has been mapped at source level: guest auth flows are throttled, customer service routes inherit the authenticated web group, admin routes inherit authentication plus staff permission, and refund approval remains separately permissioned. No addon admission middleware is currently wired in `bootstrap/app.php`.
- The lifecycle policy has unit tests committed, but it is not yet verified. Current Actions state must be checked on the latest commit; no source-only test result is claimed.


## 2026-10-22 — Runtime admission implementation checkpoint

**Status: IMPLEMENTED IN SOURCE; the recorded commits' CI verification has passed, including the PHP 8.4 full suite on `095b228` and `d446315`. This does not complete the 20-phase roadmap; newer ledger-only commits require their own CI verification.**

- `AddonImplementationRegistry` now registers the existing VTU handler and compatibility keys for `service.airtime_data`, `service.electricity`, `service.tv`, and `service.bills`, alongside the previously registered Education, Streaming, and VTU Website Requests handlers. These are explicit reviewed-code mappings, not dynamic code loading.
- `VtuOrderService::assertMappedAddonAdmission()` now resolves mapped addon keys through that registry and applies the Core lifecycle policy against the persisted addon status and compatibility state. A mapped addon must have a registered handler and the required purchase operation before a new order may pass admission.
- The admission gate is run again on the locked catalogue row inside the same database transaction that creates/debits a new order, after feature and provider-product checks. This closes the race where addon state or catalogue state changes between preflight and wallet debit.
- Existing idempotency handling remains before new-purchase admission: a retry matching an already accepted order returns the existing order rather than being blocked by later addon disablement. Provider reconciliation/recovery remains Core-owned and is not gated by addon admission.
- Added workflow tests for (a) disabled mapped addon blocks a new purchase without order creation, wallet debit or provider submission, and (b) accepted order retry remains idempotent after its addon is disabled. Registry unit tests cover the VTU/bills mappings.
- No new database migration was added in this slice: it uses the existing `platform_addons` schema and existing `vtu_products.metadata` JSON mapping. Avoiding an unnecessary schema migration reduces deployment risk. A future normalized addon-to-service mapping requires a separate backward-compatible migration proposal and tests before adoption.
- Existing legacy catalogue products without an explicit `metadata.addon_key` continue under their current Core feature/provider gates until their mapping is migrated. This is a compatibility bridge, not the final addon-conversion state.
- Relevant commits on `main`: `47d967b` (registry compatibility keys), `eed55ba` (purchase admission), `4b3ae9a` (registry tests), and `8afd986` (workflow tests).
- Exact-commit Laravel/frontend checks, security workflow and asset build must be inspected before reporting verification. cPanel/runtime/provider checks remain on the deployment checklist and must not be represented as passed based on CI.

### 2026-10-22 — Streaming catalogue correction verification

- The corrected test commit `095b228daec70affcad0cd424c101a1bf3619d87` reached terminal success in [Laravel and frontend checks run 39820039218](https://github.com/Emmizzy01/semizzy-vtu/actions/runs/39820039218). PHP 8.4 lint and the full Laravel suite completed successfully: **737 tests passed (5,439 assertions)**. Frontend build and both MariaDB error-123 diagnostics also passed.
- The full-suite output explicitly reports `Tests\\Feature\\StreamingSubscriptionCatalogueTest` passing, including: customer catalogue fails closed when the streaming feature is disabled; customer catalogue fails closed when the streaming addon is disabled even if the feature is enabled; and catalogue scaffolding does not mark streaming as implemented.
- The same full-suite output reports `Tests\\Feature\\VtuOrderWorkflowTest`, `Tests\\Feature\\VtuOrderMariaDbConcurrencyTest`, and relevant refund/ledger protections passing. This test-only correction did not alter application source or VTU recovery behavior.
- Security verification and frontend asset builds also reached success on ledger commit `d446315843e54b519723be384cf561c3b3c18822` ([security run 39820071815](https://github.com/Emmizzy01/semizzy-vtu/actions/runs/39820071815), [build run 39820071816](https://github.com/Emmizzy01/semizzy-vtu/actions/runs/39820071816)).
- The full PHP 8.4 Laravel suite for ledger commit `d446315843e54b519723be384cf561c3b3c18822` subsequently reached terminal success in [run 39820071765](https://github.com/Emmizzy01/semizzy-vtu/actions/runs/39820071765). The exact-commit workflow completed successfully; see the run and job log for the final result.
- Streaming customer purchases remain disabled until provider contract, transaction lookup, and accepted-order recovery are verified. No live provider transactions, production-data tests, or dashboard theme changes were made.

### 2026-10-22 — Middleware, API and scheduled recovery audit extension

- Extended `docs/platform/addon-route-recovery-map.md` in commit `cbcccc392871586c13f887bc853bee5f2122794b` with source-confirmed global middleware, both API webhook contracts, and the scheduled purchase/queue/payment/VTU/education recovery command inventory and cadence.
- Audit confirms global web middleware includes platform availability, active-account enforcement and session tracking; API middleware appends platform availability only. Addon admission is not wired to API webhooks. Preserve that distinction: webhook intake and accepted-operation reconciliation must not be broken by disabling new purchases.
- Recorded explicit Streaming boundary: registry contains catalogue/admin operations only, with no registered customer purchase operation. Purchases remain gated pending provider lookup and accepted-order recovery evidence.
- This is a documentation/audit checkpoint, not a service migration. No runtime code, route middleware, database, provider activation, or UI/theme was changed.
- CI for the route-map audit commit is queued/pending/in progress: [Laravel/frontend](https://github.com/Emmizzy01/semizzy-vtu/actions/runs/39821068484), [security](https://github.com/Emmizzy01/semizzy-vtu/actions/runs/39821068390), [frontend assets](https://github.com/Emmizzy01/semizzy-vtu/actions/runs/39821068367). The newer ledger-only commit `b71e1ce3107dbdcea0e6974714323c91101f7c49` also still has workflows in progress; do not mark these newest commits fully verified until terminal results are available.

### 2026-10-22 — Latest verification and VTU/data characterization checkpoint

- Follow-up polling confirms the Laravel/frontend workflow for `ff2957a0f4e9f05b87961ce9821d9b771af2eb93` completed successfully: PHP 8.4 full suite **737 tests passed (5,439 assertions)**, frontend build passed, and both MariaDB error-123 diagnostic jobs completed successfully. [Workflow run 39821129528](https://github.com/Emmizzy01/semizzy-vtu/actions/runs/39821129528).
- The route-map audit commit `cbcccc392871586c13f887bc853bee5f2122794b` also completed its Laravel/frontend workflow successfully. [Workflow run 39821068484](https://github.com/Emmizzy01/semizzy-vtu/actions/runs/39821068484).
- Added `tests/Feature/VtuDataAddonRouteCharacterizationTest.php` in commit `510bc92adaf2fafcebfc87127447492f23914fad`. It characterizes existing airtime/data page routes, product/order/bulk/recovery route names and URIs, auth/throttle middleware, controller action ownership, and the current shared registry handler. It deliberately documents the compatibility state; it does not claim airtime and data are already independent runtime addons.
- **Verification of the new characterization test is pending** on the commit that includes it. Do not begin moving runtime ownership based solely on the earlier green suite; wait for this test and the complete CI run to finish.
- Next bounded implementation slice remains the agreed VTU/data service-addon conversion phase. Before changing runtime ownership, use the characterization test plus existing `VtuOrderWorkflowTest`, MariaDB concurrency, idempotency, accepted-order recovery, reconciliation and refund-once coverage as the regression baseline. Preserve route contracts and Core-owned wallet/ledger/recovery. No production DB changes or live provider transactions.


### 2026-10-22 — VTU/data scoped admission implementation checkpoint (commit 61320f5)

- Implementation commit: `61320f5fe4163270606a9f31eca979f34156a50d`. The registry now distinguishes `service.airtime.purchase` and `service.data.purchase`; `VtuOrderService` selects the scoped operation for explicit airtime/data addon mappings while retaining the compatibility operation for legacy aggregate mappings. The registry regression test asserts the scoped operations do not cross-appear.
- Successful exact-commit security verification: [run 39823054304](https://github.com/Emmizzy01/semizzy-vtu/actions/runs/39823054304). Both SQLite and MariaDB jobs passed. The selected regression set includes wallet transfers, bank payouts, VTU bulk purchase, VTU MariaDB concurrency, transaction PIN protections, passkey origin/confirmation, authorization boundaries and related financial/authentication checks.
- Successful exact-commit frontend asset build: [run 39823054692](https://github.com/Emmizzy01/semizzy-vtu/actions/runs/39823054692). The Laravel workflow's frontend-build job and both MariaDB error-123 diagnostic jobs also passed in [run 39823054664](https://github.com/Emmizzy01/semizzy-vtu/actions/runs/39823054664).
- **Full-suite verification completed successfully on the exact implementation commit** `61320f5fe4163270606a9f31eca979f34156a50d`: PHP 8.4 full Laravel suite **739 passed, 5,490 assertions**. `Tests\\Feature\\VtuDataAddonRouteCharacterizationTest` passed, as did `Tests\\Feature\\VtuOrderMariaDbConcurrencyTest`, `Tests\\Feature\\WalletLedgerImmutabilityTest`, and `Tests\\Feature\\WalletLedgerServiceTest`. The full log also confirms VTU idempotency-key conflict handling, accepted-order recovery after admission is disabled, reconciliation without resubmission, and refund-once concurrency protections. [Exact-commit Laravel/full-suite run 39823054664](https://github.com/Emmizzy01/semizzy-vtu/actions/runs/39823054664). Security regression workflow with SQLite and MariaDB passed in [run 39823054304](https://github.com/Emmizzy01/semizzy-vtu/actions/runs/39823054304); frontend assets passed in [run 39823054692](https://github.com/Emmizzy01/semizzy-vtu/actions/runs/39823054692); both MariaDB error-123 diagnostic jobs passed in run 39823054664.
- Conversion boundary: this is scoped purchase-admission isolation only. Airtime/data still use the compatibility `VtuOrderController`; independent controller/provider runtime ownership is not complete. Preserve route names/middleware, Core-owned wallet/ledger, idempotency, concurrency, accepted-order recovery, reconciliation and refund-once behavior. Do not activate providers or perform live transactions for verification.


### 2026-10-22 — Bills addon scoped admission: electricity and TV

- Next bounded Phase 11 slice after the verified VTU/data admission checkpoint: separate `service.electricity.purchase` and `service.tv.purchase` from the generic shared order operation for explicitly mapped electricity and TV products. Registry entries keep the existing route and biller-verification compatibility operations while adding service-scoped purchase operations.
- `VtuOrderService::assertMappedAddonAdmission()` now requires the matching scoped operation for explicit `service.electricity` and `service.tv` mappings. The legacy aggregate `service.vtu` and `service.bills` keys retain the generic operation only for their existing compatibility families. Unknown/mismatched mappings fail closed at this admission check.
- Registry unit tests assert the two scoped operations exist and do not cross-appear. This is still an admission-boundary slice, not independent biller controller/provider runtime ownership. Biller verification, wallet/ledger, idempotency, accepted-order recovery, reconciliation and refund-once paths must remain unchanged.
- Implementation and full-suite verification for this slice are pending on the new main commit; do not claim it is verified until the exact-commit CI output is green. No provider activation, live purchase, production database operation, route change or theme change is included.


## 2026-10-22 — Service-list readiness aligned to scoped purchase operations

- Corrected the authenticated `/services` readiness map to check `service.airtime.purchase`, `service.data.purchase`, `service.electricity.purchase`, and `service.tv.purchase` for their matching service addons instead of the generic shared `wallet.vtu.orders.create` operation.
- This closes a registry/readiness mismatch introduced as the scoped admission operations were added. It does not move controller/runtime ownership or enable any new provider workflow; existing purchase gates and Core financial/recovery protections remain unchanged.
- Exact-commit CI verification is pending. Do not mark this slice verified until PHP 8.4 full suite, security suites, and frontend build finish successfully.

## 2026-10-22 — Product endpoint uses service-scoped purchase admission

- Updated the VTU product endpoint's admission metadata to check the matching scoped operation for airtime, data, electricity, and TV rather than treating the shared create-order operation as sufficient for every mapped service.
- This is still a boundary-hardening step, not a completed independent addon conversion. The shared controller and Core order/ledger/recovery service remain in place; no provider or purchase gate is enabled by this change.
- Exact-commit CI is required before this slice is considered verified.


## 2026-10-22 — Airtime/data service-specific purchase policy extraction

- Extracted airtime's required-amount rule and airtime/data independent-feature-setting fallback into separate runtime policy classes selected by a service runtime registry.
- The public shared purchase endpoint, route names, request fields, response messages/statuses, transaction PIN verification, addon admission, and Core VtuOrderService order/financial execution remain in place.
- This is the first runtime-separation slice, not a claim that the entire service lifecycle or routes have been independently converted. Accepted-order recovery, ledger, reconciliation and refund ownership remain in Core.
- Exact-commit CI must pass before expanding this pattern to electricity and TV.
