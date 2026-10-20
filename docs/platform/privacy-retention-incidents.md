# Privacy, retention and security incident workflows

## Customer privacy requests

Authenticated users can submit access, export, correction, and deletion requests at `/privacy/requests`, and can review only their own request history. A duplicate open request of the same type is rejected. The basic self-service JSON export at `/privacy/export` contains the authenticated user's basic profile and privacy-request history only; it is explicitly not a complete platform-data export. It sends `Cache-Control: no-store, private` and `X-Content-Type-Options: nosniff`.

Requests are reviewed through the staff-permission-protected `/admin/governance` workflow. Each review change writes a platform audit record. Rejected and fulfilled requests are terminal. Correction/deletion requests cannot be marked fulfilled by a status toggle: a reviewed, record-specific fulfilment procedure is required. No account or financial data is automatically deleted.

## Retention policy controls

The admin governance page maintains category-level retention periods and audit notes for authentication/security logs, financial records, KYC/identity records, support/privacy requests, and provider diagnostics. Defaults are proposals only, not legal advice. Policy records start disabled and require legal review.

**Automated deletion is intentionally not implemented or enabled.** Before implementing a retention executor, the project needs legal/compliance-approved periods, jurisdiction-specific rules, financial/AML and identity-record obligations, legal-hold support, record ownership maps, verified backups, dry-run reports, and tested irrecoverable-delete boundaries. Changing a configured period cannot enable automatic deletion.

## Security incident lifecycle

Staff with `staff.permissions.manage` can record incidents by category and severity. Initial reports create a timeline event. Status updates are recorded in `security_incident_events` and hashed platform audit records. Supported statuses are open, investigating, contained, resolved, and closed; closure requires a resolved state, and closed incidents cannot be reopened. Use a follow-up incident for newly discovered facts. Do not put passwords, PINs, OTPs, secrets, full identity documents, or unnecessary personal data into incident descriptions or notes.

## Release and acceptance caveats

- Run the dedicated `PrivacyGovernanceWorkflowTest` and full PHP suite before release.
- Verify migration ordering and permissions on a disposable test database before deployment.
- This basic export is not a full data portability implementation; extend it only after mapping all owned data and filtering secrets, credentials, provider payloads, and third-party data.
- Retention policies are not deletion jobs. No production records are removed by this feature.
- Incident records provide an auditable workflow; they do not automatically notify regulators, customers, or on-call staff. Notification/escalation rules require a separately reviewed implementation.
