# Phase 2 — Bulk Action 3: Provider integration and recovery audit

**Status: In progress — no provider approved for live customer purchases.**

## Safety posture

- `VTU_MULTI_PROVIDER_ENABLED` defaults to `false`.
- `VTU_FAILOVER_ENABLED` is not an operator override: `services.vtu.multi_provider.failover_enabled` is hard-coded `false`.
- The definitive-failure provider allowlist defaults empty.
- VTpass purchase submission remains isolated; lookup/requery remains available for recovery.
- Imported catalogue products remain inactive/unapproved until reviewed.
- This audit uses source review and mocked HTTP tests. It does not call live provider APIs or create purchases.

## Provider-by-provider control matrix

| Provider | Submit / mapping coverage | Lookup and ambiguity controls | Webhook controls | Current decision |
|---|---|---|---|---|
| Sogo Africa | Provider-specific network/plan mappings, stable idempotency key, response normalization, and only documented final failures treated as definitive | Original idempotency key is retained; sandbox/custom-host 404 and non-404 errors do not authorize resubmission; reconciliation uses the recorded provider attempt | Raw-body signature verification; replay/event handling and order binding covered by webhook tests | **Blocked** pending account scopes, live catalogue/mapping and recovery acceptance evidence |
| GSUBZ | Provider-specific plan mapping; business status is checked even on HTTP 200 | Uses documented verify endpoint and original request ID; unknown/transient failure codes remain ambiguous | Timestamp-bound signature and replay-window tests | **Blocked** pending live account/wallet readiness, mapping and provider-confirmed final-failure evidence |
| Dataway | Product IDs validated before dispatch; unverified/non-positive/fractional IDs rejected | Pending, HTTP errors and unknown states remain ambiguous; excluded from automatic failover while lookup/requery contract is unconfirmed | Raw-body HMAC signature verification | **Blocked** until support confirms transaction lookup/recovery contract and account-specific service/pricing evidence |
| VTpass | Catalogue mapping, recipient-field validation, service type checks, conservative status mapping and sandbox request contract tests | Existing order lookup stays available during isolation; purchases remain blocked; support confirmation on duplicate request ID/requery behavior is outstanding | Existing provider webhook verification/recovery path requires release review | **Blocked** by technical-support recovery confirmation and account-specific acceptance evidence |

## Existing automated regression coverage identified

- `tests/Unit/ProviderFailoverPolicyTest.php`
  - unknown, pending, generic failed and non-definitive results cannot trigger failover;
  - failover requires explicit definitive failure, provider allowlisting and global enablement;
  - a definitive failure can still be handled as refundable without enabling failover.
- `tests/Feature/MultiProviderVtuRouterTest.php`
  - pre-dispatch provider selection and provider-specific mapping;
  - Dataway exclusion pending recovery contract;
  - reconciliation follows the provider recorded on the attempt, not current priority;
  - stale-attempt lookup uses that attempt's provider and reference;
  - no eligible provider fails closed;
  - ambiguous sandbox and GSUBZ results do not authorize resubmission.
- `tests/Feature/VtuProviderAdaptersTest.php`
  - provider-specific submit and status mapping;
  - Sogo same-key lookup recovery only under the documented live-host contract;
  - GSUBZ request-ID verification and unknown-status handling;
  - Dataway pending/error ambiguity and product ID validation;
  - webhook signature/tamper and replay-window checks.
- `tests/Feature/VtuProviderWebhookRecoveryTest.php`
  - event/status contradiction is ignored;
  - processing exceptions do not abort the batch and are deferred with backoff;
  - recently claimed events are not processed concurrently.
- `tests/Feature/VtpassProviderClientTest.php` and `tests/Feature/VtpassCatalogueFailureSafetyTest.php`
  - purchase isolation blocks outbound submit;
  - lookup remains available during isolation;
  - production cannot accidentally submit to sandbox;
  - catalogue failure does not partially update prices; imports remain inactive.
- `tests/Feature/ProviderCatalogueImportTest.php`
  - malformed or partial provider catalogue results fail safely and imported products require review.

## Release evidence still missing

Repository tests cannot establish merchant-account readiness. Before any provider is approved for live purchases, collect and review:

1. Account-specific enabled service scopes, live credentials status and wallet/funding readiness — never paste secrets into source control or chat.
2. Operator-reviewed live catalogue snapshot: service/product IDs, plan/variation codes, currency, effective customer price, provider fees, min/max and recipient fields.
3. Provider-confirmed final-failure, timeout, duplicate-reference and lookup/requery semantics for that merchant account.
4. Signed webhook configuration, actual event delivery/retry behavior, and support escalation route.
5. Full PHP 8.3/8.4, SQLite/MariaDB, concurrency and frontend CI results for the exact candidate commit.
6. Deployment-only evidence for queue/scheduler execution, captured without exposing secrets or customer data.

## Execution / acceptance gates

- Do not submit a second provider attempt after timeout, reset, HTTP 5xx, malformed response, pending or unknown result.
- Reconcile against the provider and reference saved for the exact attempt.
- Do not allow a catalogue import to activate purchases or silently change reviewed prices/mappings.
- Keep VTpass isolated and multi-provider customer routing disabled until the outstanding evidence is independently reviewed.
- Keep real provider purchases, payouts and refunds gated while automated verification is in progress.

**CI note:** The prior candidate's Laravel PHP 8.3/8.4 jobs were still running at the last check; a passed SQLite suite or frontend build is not a substitute for completed full CI. Recheck workflows after this document commit and report exact-SHA results only.
