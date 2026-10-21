# SEMIZZY ONE — Blueprint Requirement and Addon Ownership Register

**Repository:** `Emmizzy01/semizzy-vtu`  
**Branch:** `main` only  
**State:** Working audit register; not release acceptance.  
**Safety:** Existing UI/theme frozen. No live transactions, provider purchases, destructive schema actions, or production changes are part of this audit.

This register reconciles the checked-in blueprint and project rules with explicit owner decisions already recorded for SEMIZZY ONE. “Present” means source evidence exists, not that the requirement has passed end-to-end acceptance. Requirements remain open until implementation and evidence meet the project rules.

## Core ownership — never duplicated inside service addons

| ID | Core-owned requirement | Acceptance boundary | Current audit state |
|---|---|---|---|
| CORE-01 | Authentication, user identity, sessions and account status | Existing login contracts remain stable; username/email/verified phone, configured OTP/TOTP/passkey gates and revocation are enforced server-side | Implemented areas exist; full blueprint acceptance open |
| CORE-02 | Roles, staff permissions, user type and user level | Role/permission authorization is server-side; account type is distinct from transaction/KYC level; no self-escalation | Models, gates and level policies exist; permission matrix open |
| CORE-03 | Wallet, immutable ledger and accounting invariants | Every financial movement is auditable, idempotent, locked against races and reconciled; no direct balance edits | Core services/tests exist; endpoint-by-endpoint acceptance open |
| CORE-04 | Funding, manual deposit, payment intents and gateway configuration | Manual deposit remains pending until authorised review; duplicate references/approvals prevented; Test Mode selectable before live credentials; signatures verified | Workflow exists; full acceptance and deployment checks open |
| CORE-05 | Refunds, payouts, transfers and settlement recovery | Least-privilege approval, separation of duties, idempotency and uncertain-outcome reconciliation; accepted work is recoverable after addon disablement | Workflows/tests exist; full recovery matrix open |
| CORE-06 | Global settings, pricing, feature controls and audit | Core owns global configuration, pricing, permission checks and change audit; no duplicate addon-wide equivalents | Existing mechanisms found; relationship to addon lifecycle open |
| CORE-07 | Addon registry, manifest validation and lifecycle orchestration | Reviewed code only; explicit service registration, compatibility, dependencies and lifecycle state; no arbitrary uploaded PHP/JS execution | Registry foundation exists; runtime service integration open |
| CORE-08 | Notifications, security/risk controls and transaction state | Attempt limits, PIN/step-up, fraud/risk signals, safe state transitions and audit records apply to every relevant endpoint | Existing components found; cross-service coverage open |
| CORE-09 | Privacy, retention and incident management | Access controls, minimal exports, retention safety, audit trail and incident lifecycle meet documented policy | Implementation documented; legal/operational acceptance open |

## Approved service inventory and intended addon boundaries

| ID | Addon / service | Scope and boundaries | Evidence / status |
|---|---|---|---|
| SVC-01 | VTU / airtime / data | Airtime and data catalogues, purchase orchestration, provider adapters, order monitoring/reconciliation; supports Lebara and dynamic plan validity/type metadata | Routes, models, provider adapters, bulk/job and tests exist; runtime addon binding and live contracts open |
| SVC-02 | Bill payments | Billers and bill-payment catalogues/orders; provider verification must be explicit and inconclusive results must not be treated as verified | Some VTU biller-verification infrastructure exists; coverage must be mapped before acceptance |
| SVC-03 | Education services | Catalogue, customer inputs, encrypted delivery data, provider attempts, order orchestration and recovery | Controller, services, models and tests exist; runtime addon boundary open |
| SVC-04 | Government document registration | Passport/immigration; birth/death/civil certificates; driver’s licence/vehicle documents; tax/TIN; police clearance/background checks; education/examination records; land/property; health/social services; all affidavit categories; admin-extensible categories | Full real end-to-end coverage not accepted; do not represent placeholders as live |
| SVC-05 | Documents/identity verification | NIN, BVN, passport, driver’s licence and supported verification/modification/name-change APIs, separated from document registration | Full provider-backed coverage not accepted |
| SVC-06 | Streaming subscriptions | Subscription catalogue, price/availability, purchase and safe provider recovery | Controller, catalogue model/page/tests exist; runtime boundary and purchase acceptance open |
| SVC-07 | Betting platforms | Provider-backed supported betting workflows only, with explicit provider contract, security, limits and reconciliation | Not accepted as operational; keep gated until verified |
| SVC-08 | SMM | Service catalogue, customer inputs, pricing and recoverable order status | Not accepted as operational; keep gated until verified |
| SVC-09 | Social media accounts | Approved account-related services only; credential/privacy and fraud controls required | Not accepted as operational; keep gated until verified |
| SVC-10 | Number verification | Explicit provider-backed verification flow, not an invented result or generic document-registration flow | Not accepted as operational; keep gated until verified |
| SVC-11 | VTU website request services | Customer request/intake and staff status workflow; this is a service request, not a website builder/domain/hosting product | Controller, model, events, routes and tests exist; runtime addon boundary open |
| SVC-12 | Physical listings/location data | Nigerian states/locations, used/new flag and location-aware listings where in approved blueprint | Coverage and actual schema/routes require inventory |

### Explicit exclusions and scope distinctions

- Do not add loans, forex, investment, website builder, domain sales or hosting as product scope.
- Preserve the separately approved **VTU website request services** workflow.
- Do not enable historical disabled/not-implemented `service.websites` feature-control data.
- Each service should own its service-specific API/provider adapters unless a reviewed shared abstraction is genuinely justified.
- Core wallet, authentication, users/roles/types/levels, pricing, settings, permission/audit infrastructure, transaction state and recovery must not be duplicated in addons.

## Shared end-to-end requirements

| ID | Requirement | Required acceptance evidence | State |
|---|---|---|---|
| FLOW-01 | Provider-driven catalogues and admin management | Imports are atomic/idempotent; malformed/partial provider responses do not erase valid catalogues; only supported categories are exposed | Existing VTU import tests found; broader provider contracts open |
| FLOW-02 | Dynamic data plans | Test 1-day, night, 3-day, weekly, monthly and provider-defined durations; SME, corporate/gifting, direct and provider-defined types; duplicate-priced offers remain independently selectable | Not fully accepted |
| FLOW-03 | Purchase state and recovery | Duplicate submission, concurrent workers, timeout, provider “not found”, ambiguous outcomes and reconciliation tested; no blind retry | Focused service tests exist; full cross-service matrix open |
| FLOW-04 | Bulk and scheduled purchases | Durable batch/item tracking; per-item idempotency; supported handlers only; crash/restart and unknown outcomes pause safely | Models/jobs/tests exist; acceptance open |
| FLOW-05 | User/admin dashboards and site controls | Every control persists, is authorised, audited and wired to real behavior; feature flags accurately reflect readiness | Existing pages and settings found; full control matrix open |
| FLOW-06 | Icon-led catalogue and theme | Existing icon catalogue retained; current customer/admin layout, colours and theme unchanged; visual baseline and comparison required | Visual freeze binding; screenshot comparison outstanding |
| FLOW-07 | Security controls | Transaction PIN, OTP/TOTP/passkey policy, device/session management, rate limits, risk checks and audit are enforced on every relevant endpoint | Multiple tests exist; endpoint matrix open |
| FLOW-08 | KYC tiers and account limits | NIN/BVN/document evidence, duplicate identity protection, review, tier limits and upgrade workflows are server-enforced | Existing KYC/tier components; complete provider and boundary acceptance open |
| FLOW-09 | Messaging channels | Vendor-specific SMS/email/WhatsApp contracts, receipts and recovery; WhatsApp login/bot requires verified channel and authorized provider | Vendor/device delivery not independently verified; keep live channel gates closed as configured |
| FLOW-10 | Gateway/provider Test Mode | Admin can select test mode before live credentials; secrets never reach browser/logs; sandbox tests do not call live endpoints | Configuration exists in areas; full gateway matrix open |
| FLOW-11 | Deployment and portability | Laravel-compatible cPanel/shared hosting first; additive MariaDB-safe migrations; no runtime dependency on unsupported build tools; documented release/rollback sequence | CI checks exist; production schema/browser checks remain deployment-stage |
| FLOW-12 | Data and operations safety | No resets, table drops, destructive reseeding, financial history deletion or arbitrary addon code execution | Binding rule; retain for every phase |

## Source inventory confirmed so far

- `routes/web.php` directly declares authenticated customer routes for education and VTU website requests, wallet transfers/payouts, manual deposits, funding intents, refund requests, scheduled purchases, plus admin routes for addon registry, VTU order operations, refunds and education management. Exact middleware and route names must be retained when delegating to addons.
- `AppServiceProvider::register()` directly binds Sogo/GSUBZ/VTPass/Dataway education adapters and constructs the multi-provider VTU router. These are migration candidates only after dependency tracing.
- Current service source includes `EducationServiceController`, `StreamingSubscriptionController`, `VtuWebsiteRequestController`, VTU order/catalogue/webhook controllers, education/VTU provider registries and adapters, `ProcessVtuBulkPurchaseBatch`, and the scheduled purchase controller/runner.
- Core financial paths include manual deposits, payment funding/webhooks/reconciliation/refunds, virtual accounts, wallet ledger, peer transfer, bank payout and scheduled purchase. These must remain Core-owned and recoverable even when a service addon is disabled.
- The repo tree contains dedicated tests for addon registry, manual deposit, payment funding/refunds/reconciliation, wallet ledger, payouts/transfers, scheduled/bulk purchases, VTU order/provider webhook recovery, education and streaming catalogues, and VTU website requests. Presence does not equal final acceptance.
- `PlatformFeatureControl` and `PlatformAddonRegistry` are distinct mechanisms today. A reviewed lifecycle contract must define their relationship instead of merging them without proof.

## Phase gates

1. **Audit gate:** complete all routes, controllers, jobs, events/listeners, commands/schedulers, migrations/models, permissions, feature controls, frontend pages and tests per service.
2. **Contract gate:** define stable manifest/service identifiers, capability interface, compatibility and dependency checks, service availability semantics, and backward-compatible delegation.
3. **Recovery gate:** prove disabling blocks new requests while preserving history and allowing already accepted work to receive webhooks, reconcile, refund or finish safely.
4. **Migration gate:** additive migrations only; preserve route names, request/response contracts, existing records and current UI.
5. **Verification gate:** relevant PHP suites, MariaDB concurrency tests, frontend production build, security workflow and visual comparison pass. Deployment-only checks are separately recorded.
6. **Release gate:** no live provider purchases or financial transactions until each integration's contracts, onboarding and recovery gates are independently accepted.

## Status vocabulary

- **Found:** source artefact exists.
- **Partial:** only part of the requirement is evidenced.
- **Open:** not accepted; further source audit, implementation or verification required.
- **Verified:** implementation and relevant tests/evidence satisfy the acceptance boundary.
- **Deployment gate:** requires authorised production-like/real-device/browser checks; does not block unrelated development.

Do not label a requirement Verified based on UI presence, a route, a database table, a registry row, mocked output, or a green unrelated workflow.
