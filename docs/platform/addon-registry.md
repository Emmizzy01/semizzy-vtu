# SEMIZZY ONE Add-on Registry

The registry is a core-owned manifest and compatibility control surface. It does not upload, install, or execute arbitrary PHP or JavaScript. Deployment of add-on code remains a separately reviewed release operation.

## Manifest contract

- Each add-on has a unique key, manifest version, minimum core version, description, and declared add-on dependencies.
- Core compatibility is checked against `platform.core_version` in Integration Settings; if unset or malformed, the safe default is `1.0.0`.
- Dependencies must already be registered and enabled before an add-on can be marked enabled.
- Enabled dependents prevent a dependency from being disabled.
- Self-dependencies, dependency cycles, and duplicate core-owned capabilities (`core.auth`, `core.wallet`, `core.pricing`, `core.settings`) are rejected.
- Manifest registration and status changes are behind the existing admin staff-permissions middleware and are recorded in the platform settings audit table with actor, hashed state, and a reason for state changes.
- Status changes affect registry state only. They do not dynamically load code or imply that a provider, API, or customer-facing service is ready.

## Release / operations notes

1. Deploy the migration before opening `/admin/addons`.
2. Set `platform.core_version` to the release version during a reviewed deployment.
3. Register only manifests for code already reviewed and deployed through the normal release process.
4. Review dependency state and compatibility messages before enabling an entry.
5. Keep provider purchases and financial integrations gated until their own acceptance tests and onboarding checks pass.

The registry does not yet provide code package installation, signatures, automatic migrations, runtime module loading, or third-party code execution. Those are intentionally outside this safe first implementation.
