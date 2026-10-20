# Provider Release-Evidence Checklist

**Audit status:** Open — no provider is cleared for new live purchases by this checklist.
**Scope:** repository configuration, provider catalogue importer, release gates, published API documentation, and scheduled-command definitions.
**Safety:** this audit does not call provider APIs with production credentials, run purchases, modify the production database, or enable a provider.

## Release decision summary

| Provider | Catalogue / endpoint evidence | Account and live permission evidence | Mapping and price evidence | Lookup / recovery gate | Release decision |
|---|---|---|---|---|---|
| VTpass | Production and sandbox base URLs are configured separately; current code has a purchase-isolation gate. | Not independently verified; do not infer from the presence of environment variables. | Variation/catalogue and customer-recipient mappings still need account-specific acceptance evidence. | Provider support confirmation for request-ID reuse and requery/recovery remains outstanding. | **BLOCKED** |
| Sogo | Code defaults to `https://api.sogo.africa/v1` in production and `https://sandbox.sogo.africa/v1` otherwise. Official docs describe the same URLs and bills catalogue/plan endpoints. | Live key requires the documented account approval and relevant `bills:read`, `bills:write` and, where needed, `transactions:read` scopes. Semizzy account/key scopes have not been independently checked. | Importer validates catalogue shape and builds the complete category result before writing. Imported new rows are inactive/unapproved; unsupported categories are catalogue-only. Account-specific slugs, prices and service mappings still need review. | Official docs say to query `GET /v1/transactions/{idempotency-key}?type=bill_payment`; a 404 means safe retry with the same key. Application logic only treats 404 as safe on the exact official live host; sandbox/custom-host recovery is not release-approved. | **BLOCKED pending live account, mapping, and recovery acceptance evidence** |
| GSUBZ | Code defaults to `https://api.gsubz.com`; importer checks category and nested plan response shape. Official docs document `POST /api/pay/` and `POST /api/verify/`. | Live API key/account permissions and wallet readiness have not been independently verified. | Importer classifies categories and imports plans, but each live service ID, plan code, price, and supported product needs a reviewed mapping snapshot. Catalogue imports do not activate purchases. | Official docs say query the original `requestID` or `transactionID` through `POST /api/verify/` before retrying. The live adapter's response/status mapping and exact definitive-failure semantics must be checked against current docs and tested with provider-confirmed non-delivery cases. | **BLOCKED pending account, mapping, and recovery acceptance evidence** |
| Dataway | Code defaults to `https://dataway.ng/api/v1/merchant`; importer uses `GET /billers/` and `GET /billers/{slug}/products/`. Official docs confirm the base URL, bearer API key, and these catalogue endpoints. | Merchant API access, IP allowlist, live key, wallet readiness and account pricing group are not independently verified. | Catalogue price fields can depend on account pricing group. Compare the live account's biller slugs, product IDs, effective totals, currency, and service mapping with an operator-reviewed snapshot. | Repository's purchase recovery contract remains unverified and the provider is excluded from automatic routing/failover. Do not implement retry or declare a failed purchase from a guessed status contract. Obtain the provider's documented transaction lookup/reference semantics and test evidence first. | **BLOCKED — lookup/recovery contract and account evidence required** |

## Repository controls observed

- `config/services.php` sets multi-provider routing off by default and hard-codes post-submit failover off.
- The definitive-failure provider allowlist defaults empty.
- VTpass purchase isolation is set on.
- `ProviderCatalogueImportService` validates malformed/missing catalogue payloads, aborts a category import on nested request failure, and imports new catalogue entries inactive and unapproved.
- Catalogue import is not proof of purchase readiness; the release process must separately verify account permissions, prices, product codes, recipient validation, submit responses, lookup, webhooks, and refund/reconciliation behavior.
- `ProviderFailoverPolicy` requires a failed status plus an explicit definitive-failure signal and configured provider allowlisting; the global failover flag is currently hard-off.

## Code-level gate audit (repository review)

The current `tests/Unit/ProviderFailoverPolicyTest.php` covers unknown, pending, generic failed, and non-definitive results; it also asserts that failover stays off when the global flag is false and requires both an explicit definitive-failure result and provider allowlisting. `tests/Feature/ProviderCatalogueImportTest.php` covers inactive imports and malformed/partial catalogue response handling.

These are repository-level protections, not proof of provider account readiness. The test source was inspected, but this audit did not execute a new test run on this documentation commit. Confirm CI on the exact release candidate before sign-off.

## Scheduler and queue evidence

Repository schedule definitions include:
- scheduled purchases every minute, with overlap protection;
- database queue drain every minute with bounded execution and overlap protection;
- provider webhook processing every minute;
- VTU order reconciliation on a scheduled cadence;
- Paystack payment and refund reconciliation every five minutes.

**Runtime status: NOT INDEPENDENTLY VERIFIED.** The operator previously confirmed that cPanel scheduler setup is complete. That confirms setup only, not successful execution. Before sign-off, collect timestamped cPanel Cron Job execution/output or application logs showing successful runs for the scheduler, queue drain, provider webhook processor, VTU reconciler, Paystack reconciler and refund reconciler. Redact secrets, API keys, customer identifiers and payment data. Do not trigger purchases or change production records to create evidence.

## Follow-up evidence check — 20 October 2026

### CI status for checklist commit

Checked commit `145042b4d640df4dc046c4d81cb701b247b25523` using the repository's commit-status and associated-workflow-run lookups. The status endpoint returned no status entries, and the workflow-run lookup returned no runs for that commit. **CI is therefore not verified for this exact commit**; this is not evidence of a failing test, but it is also not a green result. The earlier green run on `cf5eea5` predates the subsequent documentation commits. Re-run or locate the current main-branch workflow and confirm the exact tested SHA before release sign-off.

### Additional official contract findings

- **Dataway:** published docs define unique client `reference`, `202 pending` and explicitly say not to retry a pending purchase; they describe `transaction.pending`, `transaction.successful`, and `transaction.failed` webhooks and HMAC-SHA256 signature verification. The public docs reviewed do not establish a transaction-status lookup endpoint for arbitrary prior references. Treat a timed-out or pending transaction as unresolved; ask Dataway support to confirm a lookup/recovery endpoint and contract before implementing safe retry/reconciliation.
- **GSUBZ:** current official docs explicitly document `POST https://api.gsubz.com/api/verify/`, using the original `requestID` or returned `transactionID`. The response `status` must be checked even when HTTP is 200. This establishes the public lookup contract, but does not verify Semizzy's live account, current adapter mapping, or recovery tests against the actual live API.
- **Sogo:** current official docs explicitly document lookup by original idempotency key using `GET /v1/transactions/{idempotency-key}?type=bill_payment`; 404 indicates no transaction was created and same-key retry is safe, while 200 requires checking transaction status. This establishes the public contract, but not Semizzy's account scopes or live endpoint access.

### Evidence collection status

No cPanel logs or provider account credentials/catalogue snapshots were available through the connected repository tools during this action. No credentials were requested in chat, no provider APIs were called with production credentials, no purchases were run, and no production data was changed. Runtime scheduler/queue execution and account-specific evidence remain **operator evidence required**.

## Evidence required before enabling a provider

- [ ] Confirm the exact production base URL from the provider's current official documentation.
- [ ] Confirm the merchant account is approved, the live key is active, required scopes/permissions are granted, and any IP allowlist includes the production server egress IP.
- [ ] Retrieve the provider's live catalogue using an authorized read-only request and preserve a redacted, timestamped snapshot.
- [ ] Review every intended product's provider ID/slug, recipient fields, amount units, price/cost, validity, service type, and any verification requirement.
- [ ] Confirm lookup/requery behavior for success, pending, definitive failure, not found, timeout, and webhook-before-response cases.
- [ ] Confirm the provider's idempotency/reference guarantee and exactly which response means non-delivery; ambiguous responses must remain unresolved.
- [ ] Verify webhook signature validation, duplicate-event idempotency, order matching, and reconciliation behavior.
- [ ] Verify refund/reconciliation paths and no double debit, duplicate submission, or duplicate refund.
- [ ] Confirm scheduled tasks and queue workers have successful runtime logs.
- [ ] Confirm relevant CI is green on the exact candidate commit.
- [ ] Obtain explicit release approval before changing any provider purchase gate.

## Official documentation reviewed

- Sogo API reference: https://developer.sogo.africa/reference
- Sogo integration/reconciliation docs: https://developer.sogo.africa/docs
- GSUBZ API documentation: https://app.gsubz.com/api_doc/
- Dataway Merchant API docs: https://www.dataway.ng/api-docs/

**No activation is authorized by this checklist.** Reassess only after account-specific evidence and the recovery contract have been reviewed.