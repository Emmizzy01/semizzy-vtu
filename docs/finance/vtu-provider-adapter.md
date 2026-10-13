# VTU provider adapter contract

Semizzy's first VTU adapter is intentionally configurable. No live VTU vendor has been selected or credentials supplied, so this code is **not yet connected to a real airtime/data provider**.

## Required server environment

Configure these values only after choosing a vendor and confirming that vendor supports the contract below:

- `VTU_PROVIDER_NAME`: short internal provider identifier.
- `VTU_PROVIDER_ENDPOINT`: HTTPS API base URL.
- `VTU_PROVIDER_TOKEN`: server-side API token. Never put this in frontend props or commit a real value.

## Contract implemented by the adapter

Submit an order:

- `POST {endpoint}/orders`
- Bearer token authentication
- JSON request: `client_reference`, `product_code`, `service_type`, `network`, `recipient`, `amount_minor`, `currency`
- JSON response status: `success`, `failed`, or `pending`; optional `provider_reference` and `message_code`

Reconcile an existing order:

- `GET {endpoint}/orders/{client_reference}`
- Must return the provider's current `success`, `failed`, or `pending` state for the same stable client reference.

This is an adapter contract, not a claim that a vendor offers these exact endpoints. Before production, implement or configure a vendor-specific adapter and verify request signing/authentication, amount units, product codes, recipient format, definitive failure semantics, status lookup, rate limits, and sandbox behaviour against that vendor's official documentation.

## Order and wallet safety

- Product prices are server-side catalogue values; clients cannot submit their own price.
- The order and wallet debit are recorded together through the existing wallet ledger.
- The provider call happens after the database transaction commits.
- A request timeout or ambiguous HTTP response is marked `awaiting_reconciliation`; it is not automatically refunded and is never blindly resubmitted.
- A definitive provider failure triggers an idempotent ledger credit.
- `vtu:reconcile-orders` checks old processing/ambiguous orders. Configure the Laravel scheduler/cron on the host before relying on automatic reconciliation.
- The provider catalogue must be populated from the chosen vendor's verified products and prices. The code deliberately does not invent current airtime/data prices.
