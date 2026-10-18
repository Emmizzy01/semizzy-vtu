# Phase 3 Education Provider Capability Review

Review date: 2026-10-18  
Repository branch: `main`  
Status: public-documentation review only; no provider credentials used and no live transactions sent.

## Confirmed from public provider documentation

### GSUBZ — WAEC and NECO result pins

Official API documentation:
- https://app.gsubz.com/api_doc/
- https://gsubz.com/api_doc/

The published exam-pin service IDs include `waec`, `neco`, and `nabteb`. The exam-pin purchase endpoint is `POST https://api.gsubz.com/api/pay/`. The docs describe one plan per exam service and show a `requestID` field that can be used to query transaction status. The provider states that purchases debit the provider wallet, so credentials and a funded provider account are required for real fulfilment.

Capability decision:
- WAEC result pins: documented capability; still needs account/merchant authorization, live catalogue/price check, transaction lookup/recovery tests and delivery-token handling before Semizzy enables customer purchases.
- NECO result pins: documented capability; same verification requirements.
- JAMB ePIN/registration: not listed in the reviewed GSUBZ exam-pin service IDs. Do not map JAMB to this endpoint without written/provider documentation confirmation.

## Sogo — education catalogue and JAMB verification workflow

Official documentation:
- https://developer.sogo.africa/docs
- https://developer.sogo.africa/reference

Sogo documents education/ePIN as supported bill-payment areas, a live catalogue endpoint (`GET /v1/bills/catalog`), education plan listing, JAMB identifier verification, idempotent purchases via `Idempotency-Key`, webhooks, and transaction lookup/reconciliation. Its documentation says JAMB products require customer identifier verification. Live access requires the provider's relevant KYC/KYB/compliance approval.

Capability decision:
- WAEC/NECO: candidate products need to be confirmed from the authenticated live catalogue and relevant education plan endpoint; public docs alone do not prove every exam body/product is available to this merchant account.
- JAMB: documented verification flow exists, but actual JAMB ePIN/registration purchase availability, product codes, eligibility and commercial authorization must be confirmed from the live catalogue and provider support before implementation.
- Never use the Sogo sandbox base URL with live keys or live base URL with test keys.

## ePINs — possible additional provider, not yet integrated

Public documentation:
- https://epins.com.ng/developers/

The docs describe an Exams API at `/exams/` with live and sandbox endpoint placeholders and an exam service parameter including WAEC. Public documentation alone reviewed here does not establish complete NECO/JAMB coverage, live account authorization, or its exact transaction lookup/recovery guarantees.

Capability decision:
- Treat as a candidate for follow-up, not as an approved integrated provider.
- Obtain current official product list, exact API schemas, authorization terms, idempotency rules, transaction lookup behaviour, callback signatures and refund/failed-transaction handling before coding an adapter.

## Release gate before any education purchase goes live

1. Confirm provider authorization and production credentials for the Semizzy merchant account.
2. Fetch current products/prices from the provider; store provider cost separately from Semizzy selling prices.
3. Confirm exact candidate fields and validation endpoints for each product; do not reuse JAMB validation for WAEC/NECO.
4. Persist a unique Semizzy order reference before calling the provider and use the provider's documented idempotency/reference field.
5. Verify definitive success/failure/pending states and retrieve sensitive PIN/token details only from authenticated transaction lookup when required.
6. Simulate timeout/unknown result, duplicate submission, delayed callback and provider lookup failure in tests.
7. Confirm wallet debit, immutable ledger entry, refund/reversal and reconciliation behaviour under concurrency.
8. Keep the education feature and all products inactive/unapproved until the preceding checks pass and are reviewed.

This document records public documentation findings, not a certification of a provider account, live credentials, legal authorization, or successful live transactions.


## Implementation follow-up (2026-10-18)

The provider adapters are present on `main`, but this is not an account-level production certification.

### Public contract confirmations

- **Sogo:** the official Bills API documents `GET /v1/bills/catalog`, `GET /v1/bills/education-plans`, `POST /v1/bills/education/verify-jamb`, and `POST /v1/bills/education`. Education purchase parameters are `provider` (`jamb`/`waec`), `variation_code`, `amount`, plus `profile_id` for JAMB or `service_type` for WAEC. A completed education transaction's sensitive PIN must be retrieved from the authenticated transaction lookup; the webhook intentionally omits it.
- **GSUBZ:** the official docs confirm `waec`, `neco`, `nabteb`, purchase via `POST /api/pay/`, transaction lookup via `POST /api/verify/`, and require reading the explicit `status` rather than HTTP 200 alone. The exam PIN response can carry delivery details in `api_response`; request timeouts should allow at least 60 seconds. The published service list does not establish JAMB support or a separate candidate-validation endpoint for exam pins.
- **VTpass:** its WAEC result-checker docs confirm variation listing, `POST /api/pay`, `POST /api/requery`, and PIN fields such as `cards`, `purchased_code`, and `request_id`. Exact NECO/NABTEB/JAMB product mappings still require provider catalogue evidence.

### Sensitive delivery storage

- Nullable `provider_delivery_payload` and `encrypted_candidate_details` columns have been added to education orders and use Laravel's `encrypted:array` model casts. New coordinator-created orders keep the legacy candidate JSON field empty.
- Adapter success results pass only the expected delivery fields to the coordinator. The coordinator stores them separately from `safe_provider_response`, encrypted at rest.
- An authenticated, throttled delivery endpoint is restricted to the order owner, only serves completed orders with a stored payload, and sends `Cache-Control: no-store`.
- This endpoint is a retrieval primitive, not proof that a customer purchase or customer UI is enabled. The education customer purchase route remains disabled.

### Remaining release gates

1. Run CI against the latest commit and fix any failures.
2. Use provider-issued sandbox credentials (never commit or paste secrets) to fetch and save sanitized catalogue responses for each provider account. Public docs cannot prove Semizzy's merchant access, scopes, current products or current prices.
3. Test JAMB candidate verification only with documented supported providers; do not treat missing or malformed verification responses as valid.
4. Test success, explicit definitive failure, processing, timeout, inconclusive lookup and duplicate reference recovery with sanitized sandbox responses.
5. Confirm encrypted delivery fields can be retrieved only by the owning authenticated user, and test unauthorized access, not-yet-completed orders, empty delivery payloads and cache headers.
6. Keep Dataway disabled until its education endpoints, authorization, lookup guarantees and final-failure semantics are independently documented and tested.
7. Do not enable customer purchases until the provider-account evidence, integration tests, migration deployment plan and customer delivery UI are reviewed.
