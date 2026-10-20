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

## Scheduler and queue evidence

Repository schedule definitions include:
- scheduled purchases every minute, with overlap protection;
- database queue drain every minute with bounded execution and overlap protection;
- provider webhook processing every minute;
- VTU order reconciliation on a scheduled cadence;
- Paystack payment and refund reconciliation every five minutes.

**Runtime status: NOT INDEPENDENTLY VERIFIED.** The operator previously confirmed that cPanel scheduler setup is complete. That confirms setup only, not successful execution. Before sign-off, collect timestamped cPanel Cron Job execution/output or application logs showing successful runs for the scheduler, queue drain, provider webhook processor, VTU reconciler, Paystack reconciler and refund reconciler. Redact secrets, API keys, customer identifiers and payment data. Do not trigger purchases or change production records to create evidence.

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