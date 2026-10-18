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
