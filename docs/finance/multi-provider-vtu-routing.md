# SEMIZZY ONE multi-provider VTU routing

## Selected initial providers

1. Sogo Africa
2. GSUBZ
3. Dataway

VTpass remains isolated and must not be included in provider priority, new purchase routing, or failover while its technical-support release blocker remains open.

**Current operator decision (18 October 2026): automatic post-submit failover is hard-disabled in application config.** An uncertain or failed submitted order must not be sent to another provider. This cannot be overridden by an environment variable in the current release. Initial provider selection remains a separate opt-in via `VTU_MULTI_PROVIDER_ENABLED`.

## Current implementation status

Admin → API Providers now supports encrypted per-provider credentials and audited catalogue imports for Airtime and Data. Imported products are always inactive and require pricing/mapping review. Customer multi-provider purchase routing remains **disabled by default**. The three providers are not yet production-approved for customer purchases; do not turn on `VTU_MULTI_PROVIDER_ENABLED` until purchase mappings, webhook verification, transaction recovery, account setup, tests, and release review are complete.

## Configuration keys

- `VTU_MULTI_PROVIDER_ENABLED=false` — mandatory safe default for customer purchase routing.
- `VTU_PROVIDER_PRIORITY=sogo,gsubz,dataway` — preferred order after a provider has been configured and mapped for a particular product.
- `VTU_SOGO_BASE_URL`, `VTU_SOGO_API_KEY`, `VTU_SOGO_WEBHOOK_SECRET`
- `VTU_GSUBZ_BASE_URL`, `VTU_GSUBZ_API_KEY`, `VTU_GSUBZ_WEBHOOK_SECRET`
- `VTU_DATAWAY_BASE_URL`, `VTU_DATAWAY_API_KEY`, `VTU_DATAWAY_WEBHOOK_SECRET`

Never put provider secrets in source control, frontend props, logs, or customer-visible errors. Use separate credentials for test and live environments.

## Provider contract evidence to verify against each merchant account

### Sogo Africa

Official docs: https://developer.sogo.africa/docs and https://developer.sogo.africa/products/bills

The published contract documents `Idempotency-Key` on financial writes, lookup by the original idempotency key, dynamic bills catalogue/plan endpoints, transaction lookup, and asynchronous status webhooks. Its live-key setup may require KYC/KYB/compliance approval and an active agent tier. Account access, enabled products, pricing, fees, and support commitments still need confirmation.

### GSUBZ

Official docs: https://app.gsubz.com/api_doc/

The published contract documents dynamic service and plan catalogues, unique `requestID`, status lookup via `/api/verify/`, and duplicate-request protection. Purchase requests may return a failed state despite HTTP 200; normalize the provider's business status, not only the HTTP code. The provider's wallet must be funded. Exact status/error semantics must be covered by contract tests before failover is enabled.

### Dataway

Official docs: https://www.dataway.ng/api-docs/

The published contract documents merchant-scoped billers/products, unique references, purchase status webhooks, and HMAC-SHA256 webhook signatures. Its documentation says production webhook delivery may require confirmation with support; the webhook simulator does not automatically retry delivery. Confirm actual production retry behavior, transaction lookup/requery capabilities, account-level service availability, pricing and support before relying on webhook-only recovery.

## Non-negotiable transaction rules

1. Create one Semizzy order and post one wallet debit using the existing immutable ledger and idempotency protections.
2. Resolve provider eligibility and exact provider-specific product mapping before debit. Provider product codes are never interchangeable.
3. Persist the provider that owns each submitted attempt before making its network request.
4. Reconciliation always queries the provider that owns the recorded attempt; it must never query whichever provider currently has the highest priority.
5. A timeout, connection reset, HTTP 5xx, malformed response, pending state, or unknown state is ambiguous. Do not submit the same purchase to another provider while the first attempt may have succeeded.
6. A provider can be considered safe to replace only after its documented and tested contract establishes a definitive non-delivery outcome. Do not infer safety from HTTP status alone.
7. A provider priority change must not redirect in-flight orders.
8. Verify webhook signatures against the raw request body, validate provider/reference/order binding, and process event IDs idempotently. A webhook cannot create a second debit or refund.
9. Keep providers disabled when credentials, mappings, product coverage, balance, transaction lookup, webhook verification, or recovery tests are incomplete.
10. Never enable VTpass while the technical-support release blocker remains open.

## Implementation sequence

1. Build provider-specific adapters and contract tests without contacting real provider endpoints. Keep `VTU_FAILOVER_ENABLED=false` throughout this phase.
2. Build provider selection and preflight routing with test-only fake adapters.
3. Persist provider ownership for each attempt and implement reconciliation against the recorded provider.
4. Add provider-attempt history before enabling post-submit failover. Only explicit, tested definitive failures may allow a new provider attempt.
5. Implement signed webhook endpoints and replay/idempotency protections.
6. Add dynamic catalogue sync with provider-specific mapping and admin approval; never auto-approve imported products.
7. Run Laravel CI and frontend-build CI, including MariaDB concurrency tests. Fix all failures and investigate skips.
8. Complete merchant-account, pricing, wallet funding, support, and production readiness review. Keep the feature flag off until explicitly approved.

No provider sandbox/live purchase call, production migration, or production deployment is authorized by this plan.
