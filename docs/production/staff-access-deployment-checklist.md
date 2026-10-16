# Staff access workspace production deployment checklist

This checklist applies to the staff access workspace and its permission-audit table. A green GitHub Actions run validates the automated test environment; it does not verify a particular hosting account, production database, HTTPS configuration, or live permissions.

## 1. Release gate

- [ ] Record the exact commit SHA selected for deployment; do not rely on a stale hard-coded commit reference.
- [ ] Confirm both `frontend-build` and `php-tests` jobs passed for that exact commit SHA.
- [ ] Follow the [production backup and recovery runbook](backup-and-recovery.md) and verify a restorable backup before any approved production migration.
- [ ] Confirm the hosting PHP version and required extensions match the project requirements.
- [ ] Confirm the production environment file is configured outside version control. Never copy test credentials or commit `.env`.

## 2. Backup and migration

- [ ] Schedule a maintenance window if required by the hosting environment.
- [ ] Create a database backup using the host's supported backup tool or database console.
- [ ] Verify that the backup file exists, has a plausible non-zero size, and is stored somewhere separate from the live database. Where possible, test restoring it to a non-production database.
- [ ] Preserve the currently deployed code/release so it can be restored independently of the database.
- [ ] Deploy the reviewed code and build production assets using the project's deployment process.
- [ ] Run migrations from the application root:

  ```bash
  php artisan down
  php artisan migrate:status
  php artisan migrate --force
  php artisan migrate:status
  ```

- [ ] Review migration output and confirm `staff_permission_audits` and `user_permissions` exist with the expected indexes and constraints.
- [ ] Only bring the site back up after smoke checks pass:

  ```bash
  php artisan up
  ```

If a migration fails, stop and investigate. Do not blindly run `migrate:rollback` on production: assess whether the migration is destructive and restore only under the approved recovery plan.

## 3. Bootstrap the first access manager

- [ ] Verify the target user's email and account identity independently before granting access.
- [ ] Use a restricted server shell or authorized deployment operator account. Do not expose this operation through a public route.
- [ ] Grant only the management permission required for the trusted administrator:

  ```bash
  php artisan semizzy:permission:grant trusted-admin@example.com staff.permissions.manage --force
  ```

- [ ] Confirm the CLI output, then verify the permission exists in the database.
- [ ] Remember that CLI audit entries identify the actor as `Server CLI`; retain restricted shell/deployment logs to identify the human operator.
- [ ] Never paste production credentials, session cookies, API keys, or full environment files into tickets or chat.

## 4. Authorization and workspace smoke checks

Use a private browser session or test accounts approved for production verification.

- [ ] The designated manager can open `/admin/staff`, load the user directory, and view permission audits.
- [ ] A normal customer and a refund approver without `staff.permissions.manage` receive a forbidden response for the staff workspace and its APIs.
- [ ] A manager can grant and revoke only `refunds.approve` with a meaningful reason.
- [ ] The permission record and audit record are created together; repeated grants/revocations do not create misleading duplicate audit events.
- [ ] Self-modification and attempts to grant `staff.permissions.manage` through the web UI are rejected.
- [ ] Verify both light and dark theme modes, responsive layout, pagination, search, refresh, and validation/error messages.
- [ ] Verify the UI's status and audit feed reflect a completed change after refresh.
- [ ] Revoke any temporary test permission and remove temporary test accounts when checks finish.

## 5. Hosting, security, and operations

- [ ] Confirm all public traffic redirects to HTTPS and the certificate is valid.
- [ ] Confirm production has `APP_ENV=production`, `APP_DEBUG=false`, a strong stable `APP_KEY`, and the intended database/cache/session configuration.
- [ ] Confirm session cookies use secure settings appropriate to HTTPS, session storage is writable only by the application account, and session regeneration occurs at login.
- [ ] Confirm database credentials use a least-privilege account and are not exposed to public directories or source control.
- [ ] Confirm only required application directories are writable by the web process; do not make the whole project world-writable.
- [ ] Confirm `storage` and `bootstrap/cache` permissions, error logging, log rotation/retention, disk-space monitoring, and alert delivery.
- [ ] Confirm scheduled Laravel commands run from the host cron, and `php artisan schedule:list` shows expected tasks.
- [ ] Check application and web-server logs for migration errors, 403/500 spikes, CSRF failures, and unexpected permission changes.
- [ ] Confirm the database backup schedule and restore procedure with the hosting provider or operator.

## 6. Rollback and sign-off

- [ ] Record the deployed commit SHA, deployment time, operator, migration output, backup location, and smoke-test result in the restricted deployment record.
- [ ] If application code must be rolled back, restore the previous known-good release using the hosting provider's deployment procedure.
- [ ] Do not remove the new audit table or reverse schema changes during a code rollback unless the migration's rollback is confirmed safe and the recovery plan explicitly calls for it.
- [ ] If access was granted incorrectly, revoke it using the restricted CLI and confirm the audit entry and effective access state.
- [ ] Obtain the designated operator's sign-off before enabling production financial workflows.

## Current validation boundary

GitHub Actions passing is necessary but not sufficient. Mark this checklist complete only after the hosting operator has performed and recorded the real production checks above. Paystack sandbox lifecycle verification is a separate release gate for payment/refund workflows.
