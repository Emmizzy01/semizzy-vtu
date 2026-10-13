# Refund operations dashboard and monitoring

## Dashboard

Authorized staff can open `/admin/refunds`. The page uses the existing `refunds.approve` gate and calls the protected queue endpoint. It supports status filters, search, manual refresh, status visibility, and approval/recheck actions. The layout uses SEMIZZY's existing theme tokens and supports light and dark mode.

- Queue API: `GET /admin/payment-refunds?per_page=100`, optionally with `status`.
- Approval: `POST /admin/payment-refunds/{public_id}/approve`.
- Customer status: `GET /wallet/refund-requests/{public_id}`.
- Grant access only through a trusted operator provisioning process; never expose permission grants to customers.

## Automated stale-refund monitoring

The scheduler runs `payments:monitor-refunds` every five minutes. It flags `processing`, `provider_pending`, and `review_required` records whose `updated_at` is older than `PAYMENTS_REFUND_STALE_MINUTES` (default 15, clamped to 5–1440 minutes).

The command writes critical records to the application log, including the refund ID, current status, amount, provider reference, and last update. If `PAYMENTS_ALERT_WEBHOOK_URL` is configured with an HTTPS URL, it also posts a structured alert. Identical status snapshots are throttled for 15 minutes using the configured Laravel cache store. Keep that cache shared between scheduler instances in multi-server deployments.

Example environment values:

```dotenv
PAYMENTS_REFUND_STALE_MINUTES=15
PAYMENTS_ALERT_WEBHOOK_URL=
```

Run manually:

```bash
php artisan payments:monitor-refunds
php artisan schedule:list
```

The host must run Laravel's scheduler (for example, a once-per-minute cron invoking `php artisan schedule:run`). Ensure logs and the optional alert receiver are monitored.

## CI and production verification

The GitHub Actions workflow `.github/workflows/laravel.yml` runs the Laravel feature suite against MariaDB and builds the frontend on pushes to `main` and pull requests targeting `main`.

CI and mocked HTTP tests are not substitutes for a Paystack sandbox run. Before enabling live refunds:
1. Configure Paystack test keys only in the test environment.
2. Test partial and full refund submissions, pending/processed/failed events, duplicate webhooks, invalid signatures, provider timeouts, and insufficient wallet funds.
3. Confirm a refund failure restores the wallet exactly once and a processed refund never restores it.
4. Verify scheduler execution, application log retention, alert delivery, and alert deduplication.
5. Review all stuck or mismatched cases manually; never release reserved funds solely because a request timed out.
6. Run the full CI workflow and review its actual result before deployment.
