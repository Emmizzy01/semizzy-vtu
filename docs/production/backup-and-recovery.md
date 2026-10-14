# Production backup and recovery runbook

## Scope and safety

This runbook is for an authorized operator using the hosting provider's control panel or restricted server shell. It is not permission to change the production database. Perform a restore only with explicit approval, a documented incident/recovery plan, and a verified backup.

Never paste database passwords, full environment files, customer records, or backup contents into chat or tickets. Store backups outside the public web root, restrict access, and use the hosting provider's encryption/storage options where available.

## Before deployment or a risky change

1. **Identify the release.** Record the current deployed commit/release, application path, PHP version, database name (not credentials), and operator/time in the restricted deployment record.
2. **Use the hosting backup feature.** Create a database backup through cPanel's supported database backup/export feature or the hosting provider's backup service. If using phpMyAdmin, export only the intended database and follow the host's recommended export settings.
3. **Back up files separately.** Preserve the current application release and the production environment configuration using the host's secure backup facilities. The environment file contains secrets; do not put it in a public directory or commit it to Git.
4. **Verify the artifacts.** Confirm the backup file exists, is non-empty, has the expected timestamp/name, and can be read by the intended recovery operator. Record its restricted storage location and size. A successful export message alone is not proof of recoverability.
5. **Test restoration safely.** When the host supports it, restore into a separate temporary database or isolated staging environment. Verify that the dump imports without errors and that the application can read the restored schema. Do not test restoration over the live database.
6. **Set retention.** Keep backups according to the business, privacy, and hosting requirements. Ensure backup copies are protected from the same account compromise or accidental deletion as the live site.

## Database readiness and migration safety

Before a deployment, inspect the migration state without changing it:

```bash
php artisan migrate:status
```

If the migration table is missing, a required table is absent, or the current schema is unexpected, stop and investigate. Compare the deployed code, migration files, and actual database state with the authorized operator. Do not try to repair uncertainty with `migrate:fresh`, `db:wipe`, fake migrations, direct deletion of migration records, or an unreviewed rollback.

Only run `php artisan migrate --force` after the release and migration plan have been reviewed, a verified backup exists, and the operator has explicitly approved the production change. Review the command output and re-check migration status afterwards.

## Recovery decision

1. Declare the incident and assign an authorized recovery operator.
2. Determine whether the fault is application code, configuration, database schema/data, hosting capacity, or a third-party provider. Preserve relevant logs and timestamps while redacting secrets and unnecessary personal data.
3. If it is code-only, prefer restoring the previous known-good application release, provided that release is compatible with the current database schema.
4. If database restoration is being considered, estimate data loss from the backup timestamp and any newer wallet, payment, refund, order, or audit records. Obtain explicit approval from the system owner and financial operations owner before proceeding.
5. Restore only to the explicitly selected target database using the host's documented process. Verify the target database name immediately before the destructive restore action. Never restore a backup over production as a diagnostic experiment.
6. After restoration, run read-only checks first: migration status, critical table presence, application health route, logs, and approved account/access checks. Then verify wallet ledger consistency, payment intents, refunds, VTU orders, and permission audit records using approved read-only reports.
7. Reconcile financial/provider outcomes with the provider before manually changing an ambiguous transaction. A database restore or code rollback does not reverse a provider purchase and must never trigger blind resubmission or automatic refund.
8. Record the chosen backup, source and target, timestamps, approvals, commands/actions, validation evidence, known data loss, and sign-off in the restricted incident record.

## Recovery completion checklist

- [ ] Recovery was explicitly authorized and the target was independently verified.
- [ ] The previous code release and required environment configuration are available securely.
- [ ] Database restore, if approved, completed without import errors.
- [ ] Migration state and required tables match the intended release.
- [ ] Authentication, authorization, wallet/ledger, payment, refund, VTU, and audit data were checked with read-only tools.
- [ ] Scheduler and alert delivery are operating as expected.
- [ ] Provider-side transactions with uncertain outcomes were escalated; no blind resubmission/refund was made.
- [ ] The operator documented results and obtained sign-off.

A green CI run does not prove that a production backup is valid, a restore will work, or the live hosting environment is ready.
