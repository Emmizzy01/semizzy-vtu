# Refund operations dashboard and monitoring

## Dashboard

Authorized staff can open `/admin/refunds`. The page uses the existing `refunds.approve` gate and calls the protected queue endpoint. It supports status filters, search, manual refresh, status visibility, and approval/recheck actions. The layout uses SEMIZZY's existing theme tokens and supports light and dark mode.

- Queue API: `GET /admin/payment-refunds?per_page=100`, optionally with `status`.
- Approval: `POST /admin/payment-refunds/{public_id}/approve`.
- Customer status: `GET /wallet/refund-requests/{public_id}`.
- Grant access only through a trusted operator provisioning process; never expose permission grants to customers.

## Automated stale-refund monitoring

The scheduler runs `payments:reconcile-refunds --limit=100` every five minutes. It fetches stale refunds from Paystack's canonical `GET /refund/{id}` endpoint, validates provider refund ID, original transaction ID, amount, and currency, applies only a matched provider status, and then runs stale-state alerting. It flags `processing`, `provider_pending`, and `review_required` records whose current status has lasted longer than `PAYMENTS_REFUND_STALE_MINUTES` (tracked separately from the last provider check so polling does not reset alert age) (default 15, clamped to 5–1440 minutes).

The command writes critical records to the application log, including the refund ID, current status, amount, provider reference, and last update. If `PAYMENTS_ALERT_WEBHOOK_URL` is configured with an HTTPS URL, it also posts a structured alert. Identical status snapshots are throttled for 15 minutes using the configured Laravel cache store. Keep that cache shared between scheduler instances in multi-server deployments.

Example environment values:

```dotenv
PAYMENTS_REFUND_STALE_MINUTES=15
PAYMENTS_ALERT_WEBHOOK_URL=
```

Run manually:

```bash
php artisan payments:reconcile-refunds --limit=100
php artisan payments:monitor-refunds
php artisan schedule:list
```

The host must run Laravel's scheduler (for example, a once-per-minute cron invoking `php artisan schedule:run`). Ensure logs and the optional alert receiver are monitored.

## CI and production verification

The GitHub Actions workflow `.github/workflows/laravel.yml` runs the Laravel feature suite against MariaDB and builds the frontend on pushes to `main` and pull requests targeting `main`.

The reconciliation uses Paystack's refund fetch endpoint and must never resubmit a refund automatically. See the [official Refund API](https://paystack.com/docs/api/refund/) for the fetch-refund contract.

CI and mocked HTTP tests are not substitutes for a Paystack sandbox run. Before enabling live refunds:
1. Configure Paystack test keys only in the test environment.
2. Test partial and full refund submissions, pending/processed/failed events, duplicate webhooks, invalid signatures, provider timeouts, and insufficient wallet funds.
3. Confirm a refund failure restores the wallet exactly once and a processed refund never restores it.
4. Verify scheduler execution, application log retention, alert delivery, and alert deduplication.
5. Review all stuck or mismatched cases manually; never release reserved funds solely because a request timed out.
6. Run the full CI workflow and review its actual result before deployment.


## Staff permission provisioning

Refund approval access is granted only to a trusted operator through the server CLI; there is no public endpoint for granting permissions. SSH to the application server as an authorized deployment/operator account, confirm the target user's email carefully, and run:

```bash
php artisan semizzy:permission:grant finance-operator@example.com refunds.approve --force
php artisan semizzy:permission:revoke finance-operator@example.com refunds.approve --force
```

Both commands require `--force`, accept only the explicitly allowlisted `refunds.approve` permission, and reject unknown users. Granting is idempotent. Revoke access immediately when an operator changes role or leaves. These commands are deliberately CLI-only; never add a customer-facing permission grant route. Restrict SSH and shell access, and retain deployment/operator shell logs according to your access-control policy.

Run the permission command tests with `php artisan test --filter=StaffPermissionCommandsTest` before deployment.


## Staff access management workspace

The restricted workspace is available at `/admin/staff`. Only accounts with `staff.permissions.manage` can access its page, user directory, audit feed, or permission-change endpoints. Bootstrap the first trusted access manager from the server CLI after verifying their account:

```bash
php artisan semizzy:permission:grant trusted-admin@example.com staff.permissions.manage --force
```

The workspace intentionally manages only `refunds.approve`. It cannot grant or revoke `staff.permissions.manage`, preventing managers from delegating access-management authority through the web UI. Every UI grant/revocation requires a reason, is committed in the same database transaction as its audit record, identifies the actor and target, and rejects self-modification. CLI permission changes are also written to the audit trail with the actor identified as Server CLI; restrict shell access and retain shell/deployment logs to identify the human operator.

The directory is paginated and searchable, and the audit feed shows the latest 50 changes. Before rollout, run `php artisan test --filter=StaffAccessManagementTest` and `php artisan test --filter=StaffPermissionCommandsTest`. Keep access-manager bootstrap/revocation limited to authorized server operators.
