# VTpass sandbox verification runbook

## Purpose and safety boundary

Use this checklist to verify Semizzy against the official VTpass sandbox before any live-provider rollout. Automated tests with mocked responses are not a substitute for a real sandbox check.

- Keep `VTU_PROVIDER_DRIVER=vtpass`.
- Keep `VTU_VTPASS_BASE_URL=https://sandbox.vtpass.com/api`.
- Obtain sandbox-only `VTU_VTPASS_API_KEY` and `VTU_VTPASS_SECRET_KEY` from the VTpass sandbox account.
- Store secrets only in the hosting control panel or server-side `.env`. Never commit them, place them in frontend variables, or paste them into chat or issue comments.
- Do not switch to the live URL or live credentials during this runbook.
- Use only product variations and test recipient details documented by VTpass for your sandbox account. Do not guess variation codes or recipient numbers.

Official resources:
- Sandbox: https://sandbox.vtpass.com/
- Authentication: https://vtpass.com/documentation/authentication/
- Buying services: https://vtpass.com/documentation/buying-services/
- Airtime: https://vtpass.com/documentation/mtn-airtime-vtu-api/
- Data: https://vtpass.com/documentation/mtn-data/
- Requery: https://vtpass.com/documentation/re-query-services/
- Response codes: https://vtpass.com/documentation/response-codes/

## Stage 1 — configuration-only review

1. Back up the current server-side environment file using your host's secure file manager.
2. Set the sandbox driver, base URL, and sandbox credentials in the hosting panel.
3. Ensure the Laravel configuration cache is refreshed using the deployment procedure for this app; never print the environment file or credential values to terminal output.
4. Confirm that the configured base URL is exactly the official sandbox URL above.
5. Confirm logs and error reports do not include request headers, API keys, secret keys, or raw provider response bodies.

Do not run a purchase command or create a paid order during this stage.

## Stage 2 — catalogue validation

For each sandbox product intended for testing, verify against the current VTpass catalogue:

- service type is supported airtime or data;
- network and provider service ID match the VTpass product;
- airtime face value is a positive whole-naira amount and is separate from Semizzy's sale price;
- each data product has a verified server-side `provider_variation_code`;
- provider account permissions and available sandbox balance are adequate.

Do not activate a product with an unverified variation code.

## Catalogue visibility and activation gate

The VTpass sync discovers every category returned by `/service-categories`, then imports each category's service IDs and variation records where available. Airtime denominations and data plans are retained; other categories (including TV, electricity, education, insurance, and other services returned by the account) are imported under their VTpass category identifier with provider metadata. This is catalogue discovery, not purchase approval.

- Every newly discovered product is stored as inactive. Each record retains provider category, service ID, service/variation details, listed amount, available minimum/maximum, convenience fee, product type, and provider image when VTpass returns them.
- Categories without validated customer purchase flows are admin catalogue records only; they cannot be purchased. Service types remain grouped by the VTpass category identifier.
- Existing operator selling prices and activation state are preserved by sync; provider-listed price is retained as reference metadata rather than silently overwriting an operator's selling price.
- Before enabling a VTpass-sourced product, an authorised operator must test that exact service ID and variation against the official sandbox, confirm the recipient/input flow and expected amount, and record a meaningful audit reason in the admin catalogue.
- Use the admin catalogue's **VTpass sandbox verified and approved** control only after that check. The server enforces the approval metadata at the provider purchase boundary; a legacy active flag alone is not sufficient.
- Do not mark products approved based only on a successful catalogue fetch or a mocked automated test. Keep unverified products inactive.

## Stage 3 — controlled transaction scenarios

Run only with VTpass-approved sandbox test products and recipients. Record the Semizzy order ID, provider request ID, expected outcome, and observed outcome without recording secrets.

1. **Delivered success:** verify the order becomes successful and the wallet is debited once.
2. **Pending/initiated:** verify the order remains unresolved, with no refund and no second purchase submission.
3. **Definitive failure:** verify a refund is posted at most once.
4. **Timeout/ambiguous response:** verify the order moves to reconciliation and uses the original provider request ID; never blindly resubmit the purchase.
5. **Repeated reconciliation:** verify repeated checks do not duplicate wallet debits or refunds.
6. **Duplicate client request:** verify the same idempotency key cannot create another charge or provider order.
7. **Unknown/malformed response:** verify it remains unresolved until a definitive provider status is established.

Use the failure modes and test data supported by the official sandbox; do not attempt to force unsupported provider responses against a real customer transaction.

## Stage 4 — evidence and release gate

Record pass/fail for each scenario and inspect the related order and ledger entries through approved application/admin tools. Check that no raw credentials or sensitive provider payloads appear in logs.

Live rollout remains blocked until all of the following are confirmed:

- latest CI run passes PHP tests and frontend build;
- the real sandbox account authenticates successfully;
- supported airtime and data products are verified;
- the current category sync has imported and reviewed every category/service/variation exposed by the configured VTpass account;
- success, failure, pending, timeout, and repeated-reconciliation outcomes are tested;
- the hosting scheduler runs Laravel's reconciliation command correctly;
- production database migration state and deployment plan have been reviewed and explicitly approved;
- live credentials, provider balance, limits, and product permissions are checked by the operator.

Never use `migrate:fresh`, `db:wipe`, or fake migrations to resolve a migration-table warning. Do not enable live provider credentials as part of sandbox testing.

## Request IDs and requery code 015

The current official VTpass response-code documentation describes:

- `014`: the request ID already exists. Do not create a second purchase using that ID; reconcile the existing order.
- `015`: invalid request ID on a requery operation—the supplied ID was not used on VTpass. This is not proof that an earlier timed-out purchase failed.
- `000`: inspect `content.transactions.status`; only `delivered` confirms success.
- `016` and `091`: documented transaction-failure/not-processed codes. The adapter maps these to failure; all unrecognized codes or response shapes remain unresolved.

For `015`, Semizzy keeps the customer order awaiting reconciliation, preserves the same provider request ID, and does not refund or resubmit automatically. Repeated `015` results must remain visible for controlled investigation; do not convert them into a failure solely because a retry count was reached. If the provider continues not to recognize the ID, an operator must investigate using the order/request ID and provider records before a manual resolution.

This policy follows the published response-code and requery guidance, but it cannot establish what happened inside VTpass when their platform returns `015`. Real sandbox verification is still required before activating products for sale.

Official references:
- https://vtpass.com/documentation/response-codes/
- https://vtpass.com/documentation/re-query-services/
- https://vtpass.com/documentation/buying-services/
