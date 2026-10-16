# VTU unresolved-order operations runbook

## Purpose and safety boundary

Use this runbook when Semizzy reports a VTU order that remains `processing` or `awaiting_reconciliation` after provider dispatch began.

The current recovery policy is intentionally conservative while VTpass confirms duplicate-request and requery semantics:

- Do not manually submit a second purchase just because the first response timed out.
- Do not treat a requery response code `015` alone as proof that the original purchase never reached the provider.
- Do not refund an ambiguous order unless an authorized operator has established a definitive failure using approved evidence and the normal refund controls.
- Never change an order status directly in the database to make an alert disappear.
- Keep provider credentials, authentication headers, and sensitive customer details out of chat, tickets, and copied logs.

Monitoring is for escalation. It does not change order status, issue refunds, or submit purchases.

## Monitoring configuration

- `VTU_STUCK_ORDER_MINUTES`: age threshold in minutes; default is `60`. The application clamps the effective value to 5–10,080 minutes.
- `PAYMENTS_ALERT_WEBHOOK_URL`: optional HTTPS webhook for operator alerts. Configure it only with an approved, access-controlled destination.
- The `vtu:monitor-orders` command is scheduled every 15 minutes and uses Laravel's scheduler. Repeated matching alerts are throttled for 15 minutes.
- If no alert webhook is configured, the service still writes a critical application log entry. Confirm that application logs are monitored and retained appropriately.

The monitor checks orders in `processing` or `awaiting_reconciliation` with a non-null dispatch start time and an old `created_at`. It intentionally uses creation time rather than last update time so repeated pending lookups cannot hide an old unresolved order. Each run is capped at 500 matching orders.

## Safe operator response

1. **Confirm the application is ready.** Before running Artisan commands, verify that the approved database migrations have been applied and the required VTU order tables exist. Do not use `migrate:fresh`, `db:wipe`, fake migrations, or destructive schema commands to resolve a missing-table error.
2. **Identify the order.** Use the order's public ID and provider request/reference identifiers from the restricted admin tools or application logs. Do not share customer data or provider secrets in the escalation.
3. **Review the timeline.** Check when dispatch started, the last known provider response, and each reconciliation attempt. Confirm the same original provider request ID was used for lookups.
4. **Check provider evidence.** Use the provider's official requery interface and approved support channel. Save timestamps, request/reference ID, response code, and response status while redacting credentials and unnecessary personal data.
5. **Escalate unresolved outcomes.** Contact provider support with the original request/reference ID and timestamps. Ask whether the purchase was accepted, delivered, or definitively not processed. Record the case/reference number and the written answer in the restricted operational record.
6. **Resolve only from reliable evidence.** Apply the existing authorized order-resolution and refund workflow only after the result is established. Do not make direct database edits or create a second purchase to test the outcome.
7. **Verify the ledger.** Before and after a permitted resolution, confirm the existing wallet debit and any approved refund ledger entry are not duplicated. Record the operator, evidence, decision, and time.

## Commands

After database readiness and deployment have been explicitly verified, an authorized operator can inspect scheduled tasks:

```bash
php artisan schedule:list
```

The monitoring command can be invoked manually to produce the summary and critical log/optional alert:

```bash
php artisan vtu:monitor-orders
```

This is a monitoring-only command. Do not assume that a successful command means an order was resolved; review the reported counts and logs.

On cPanel/shared hosting, Laravel's scheduler generally needs the hosting account's cron facility to run `php artisan schedule:run` every minute from the actual application root. Use the real path and PHP executable supplied by the host; do not copy a placeholder path as-is. Verify cron output and application logs. Do not add a second scheduler if one is already configured.

The separate `vtu:reconcile-orders` task is scheduled every five minutes and can reconcile order state using the application's provider adapter. It is not the same as the monitoring command. Do not disable, manually force, or alter reconciliation behavior as a workaround for an ambiguous provider result; investigate any change separately and retain the no-blind-resubmission policy.

## Current release gate

Do not enable live VTpass purchases or change retry/refund semantics until:

- VTpass provides written clarification of duplicate `request_id` behavior and the meaning/timing of response code `015`;
- the response is reviewed against the implementation and regression tests;
- both PHP tests and frontend build pass on the exact proposed commit;
- database readiness, backup, deployment, scheduler, and alert delivery are separately verified by the authorized operator.

A green CI run does not prove production database or hosting readiness.
