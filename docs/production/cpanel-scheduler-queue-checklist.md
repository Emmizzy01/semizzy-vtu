# cPanel scheduler and queue operations checklist

## Purpose and safety

This is an operator checklist for the existing Laravel application at a cPanel/shared-hosting deployment. It documents the required runtime configuration; it does not run commands, alter production data, or authorize provider purchases or live financial transactions.

## Required cPanel cron entry

Laravel scheduled tasks only run when the host invokes the scheduler. In cPanel **Cron Jobs**, configure one job to run every minute, using the actual application path and PHP CLI binary verified in that account:

```cron
* * * * * cd /home/oyejogas/semizzy && /usr/local/bin/php artisan schedule:run >> /dev/null 2>&1
```

Before saving, verify that `/home/oyejogas/semizzy` is the current Laravel application directory and that `/usr/local/bin/php` is the PHP CLI binary selected by the hosting provider. Some hosts use a different PHP binary; do not assume the example path is valid without checking. If the provider's cPanel UI supplies a PHP 8.4 command path, use that verified path.

Do not create multiple identical cron entries. Record the configured command, frequency, application path, PHP CLI version, and operator/time in the restricted deployment record. Keep the environment file and secrets outside the public web root.

## Scheduled jobs currently registered

The application's schedule includes:
- Paystack funding reconciliation every five minutes.
- Refund reconciliation and stale-refund monitoring every five minutes.
- VTU order reconciliation every five minutes, and unresolved-order monitoring every fifteen minutes.
- Verified VTU provider-webhook processing every minute.
- Scheduled service purchases every minute; unresolved or uncertain outcomes must pause rather than be resubmitted.
- Database queue draining in bounded scheduled windows using `queue:work database --stop-when-empty --timeout=60 --max-time=55`.
- Education order reconciliation every five minutes.

The schedule registration is code-level evidence only. It does not prove the cPanel cron entry exists, runs on time, or completes successfully.

## Queue requirements

1. Confirm `QUEUE_CONNECTION=database` only if the deployed database queue tables exist and the deployed application has the required migrations applied.
2. For shared hosting without a persistent process supervisor, the registered bounded queue-drain task relies on the once-per-minute Laravel scheduler. For a VPS or hosting plan with a process supervisor, a supervised queue worker may be used instead; avoid accidentally running redundant workers with incompatible deployment/configuration.
3. Check queue failures through the approved application/hosting logs and the `failed_jobs` table where present. Redact credentials and customer data before sharing excerpts.
4. A worker timeout, crash, or retry does not prove that an external provider did not receive a request. Do not manually retry an ambiguous purchase or refund until the existing order/refund has been reconciled against provider evidence.
5. If a scheduler or overlap lock appears stale, first verify that the corresponding process is no longer running and inspect logs. Do not blindly clear all scheduler/cache locks or delete queued/failed-job records.

## Read-only verification after an approved deployment

Use the deployed application's correct PHP CLI binary and application directory. These commands inspect configuration/schedule state; they do not perform a provider purchase:

```bash
php artisan --version
php artisan schedule:list
php artisan migrate:status
```

Run them only when the operator has access to the correct deployment. `migrate:status` is read-only; do not run migrations or change production schema as part of this checklist.

Then verify in cPanel:
- Cron entry is enabled and there is only one scheduler entry.
- Cron execution records or host logs show recurring invocations.
- Laravel logs show no repeated scheduler, queue, or reconciliation exceptions.
- A controlled read-only review of pending/reconciliation orders, refunds, webhook events, and failed jobs is available to authorized staff.
- Alert delivery configuration is present and its endpoint is protected; never disclose webhook URLs or tokens.

## Release and safety gate

- [ ] Latest PHP 8.3 and PHP 8.4 CI jobs pass.
- [ ] Frontend build passes.
- [ ] Actual cPanel PHP CLI path/version is confirmed.
- [ ] Single once-per-minute cron entry is configured and execution is evidenced.
- [ ] Queue tables, worker strategy, failed-job visibility, and logs are reviewed.
- [ ] Provider webhook retries/backoff and order/refund reconciliation tests pass.
- [ ] Backup/restore readiness is separately verified before any schema change.
- [ ] Live payments and provider purchases remain disabled until their separate production-readiness approval.

A green CI run does not verify the live cPanel cron configuration or prove a production worker is running.
