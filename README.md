# SEMIZZY VTU

A modular Nigerian VTU and fintech platform foundation designed for secure, reliable digital services, wallet operations, reseller tools, and administration.

## Product direction

- **Backend:** Laravel / PHP
- **Frontend:** Inertia.js, React, TypeScript, and Vite
- **Database:** MySQL or MariaDB
- **Hosting target:** cPanel/shared hosting first, with a path to managed VPS/cloud hosting
- **UI:** Responsive mobile-first experience with accessible light and dark themes
- **Architecture:** Modular core with clearly bounded domains and optional add-ons

## Core principles

1. Money movement must be ledger-backed, auditable, and idempotent.
2. Provider credentials must never be committed to source control or exposed to clients.
3. Service requests need explicit state transitions, safe retry rules, reconciliation, and operational visibility.
4. Authorization is enforced on the server; admin actions are logged.
5. Feature modules must integrate through documented contracts rather than patching unrelated modules.
6. Production deployment must not depend on long-running processes unavailable on typical shared hosting.

## Planned domain boundaries

- Identity, authentication, sessions, and access control
- Customer profiles and account security
- Wallet ledger, funding, transfers, fees, and reversals
- Airtime, data, bills, and other VTU service orders
- Provider registry, routing, product mapping, and reconciliation
- Reseller tiers, commissions, and business tools
- Notifications, support, and dispute workflows
- Admin operations, audit logs, reports, and platform settings
- Privacy, consent, data requests, retention, and security incident workflows
- Add-on registry, configuration, and compatibility checks

## Development status

This repository is being initialized. Planned capabilities are not considered implemented until code, tests, and documentation are added and verified.

## Local setup target

The project will use a standard Laravel application with Node.js for frontend assets and MySQL/MariaDB for persistence. Exact setup commands will be documented as the application scaffold is added.

## Security

- Copy `.env.example` to `.env` locally and generate a unique application key.
- Never commit real credentials, API tokens, production database dumps, or customer data.
- Use least-privilege access, HTTPS, validated inputs, server-side authorization, and tested backups.
- Review security and privacy requirements before enabling real-money or provider integrations.

## License

License to be determined by the project owner.
