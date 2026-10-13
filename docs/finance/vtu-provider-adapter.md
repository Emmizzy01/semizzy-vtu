# VTU provider adapter contract

## Current implementation

Semizzy has two adapters behind the `VtuProvider` contract:

- `configured`: the original generic contract, which requires an endpoint and bearer token.
- `vtpass`: a vendor-specific adapter for VTpass airtime and data requests.

VTpass configuration defaults to its **sandbox** API. Live transactions are not enabled by this repository change and require explicit operator configuration, sandbox validation, a funded provider account, verified product catalogue metadata, and deployment checks.

## VTpass official API mapping

The adapter follows VTpass's official documentation:

- API base URL: `https://sandbox.vtpass.com/api` for sandbox or `https://vtpass.com/api` for live.
- Submit: `POST /pay`.
- Requery: `POST /requery`, using the same `request_id` as the original submission.
- POST authentication headers: `api-key` and `secret-key`.
- Request ID: first 12 characters are the current Africa/Lagos `YYYYMMDDHHmm`, followed by a stable order identifier.
- Airtime: `serviceID`, `amount` in whole naira, `phone`, and `request_id`.
- Data: `serviceID`, `variation_code`, `billersCode`, `phone`, and `request_id`.

Official documentation:
- Authentication: https://vtpass.com/documentation/authentication/
- Buying services and request ID format: https://vtpass.com/documentation/buying-services/
- Airtime example: https://vtpass.com/documentation/mtn-airtime-vtu-api/
- Data example: https://vtpass.com/documentation/mtn-data/
- Requery: https://vtpass.com/documentation/re-query-services/
- Response codes: https://vtpass.com/documentation/response-codes/

## Required server environment

Use these server-only variables:

- `VTU_PROVIDER_DRIVER=vtpass` to select the adapter.
- `VTU_VTPASS_BASE_URL=https://sandbox.vtpass.com/api` for sandbox testing.
- `VTU_VTPASS_API_KEY` and `VTU_VTPASS_SECRET_KEY` from the corresponding VTpass sandbox profile.

Do not commit real credentials, expose them to frontend code, or use live credentials in development. The adapter rejects non-HTTPS URLs and hosts other than the official VTpass domains.

## Product catalogue requirements

Do not invent or hardcode live prices or variation codes. Before enabling data products, each product's trusted server-side metadata must include the verified `provider_variation_code`; `provider_service_id` may be supplied when an official service ID differs from the conservative network mapping. Airtime uses the configured product face value (in minor units) as the VTpass airtime amount. Confirm face value and Semizzy's sale price independently.

Only activate catalogue items after checking them against the vendor's current service variations and account permissions.

## Status and money safety

- VTpass `code=000` is not itself proof of success; only `content.transactions.status=delivered` is mapped to success.
- `initiated`, `pending`, incomplete, malformed, non-2xx, and otherwise unclear responses stay unresolved and are re-queried.
- Only VTpass response codes `016` and `091`, documented as transaction failed / not processed, are treated as definitive failure by this adapter. Review these semantics against the current vendor docs before production.
- A timeout or ambiguous response never triggers a blind second purchase.
- A definitive failure is refunded through the existing idempotent wallet ledger.
- The same VTpass request ID is used for reconciliation.
- Provider responses are reduced to allowlisted fields; raw payloads and credentials are not stored.
- The reconciliation command is scheduled every minute, but the hosting provider must run Laravel's scheduler/cron correctly.
- Never migrate or alter the production database until the migration-table state has been inspected and the deployment plan is approved.

## Remaining production gate

Run the complete CI suite, confirm the latest PHP tests and frontend build pass, test the official VTpass sandbox success/failure/pending/timeout scenarios, verify provider wallet balance and product whitelisting, and review logs plus duplicate-request behavior. Only then consider enabling a live endpoint with production credentials.
