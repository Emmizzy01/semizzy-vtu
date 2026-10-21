# Phase 2 — Dashboard link, route, controller, and feature matrix

This matrix distinguishes registered navigation from a service that is ready for live use. A registered route is not proof of a completed provider integration.

| Dashboard destination | Route / handler | Current feature state | Access / release note |
|---|---|---|---|
| Customer dashboard | `dashboard` → `DashboardController::customer` | Implemented | Authenticated user; admin-capable users are redirected to admin dashboard |
| Staff customer preview | `customer.dashboard` → `DashboardController::customerPreview` | Implemented for own account | Authenticated staff view; not customer impersonation |
| Airtime, data, electricity, TV | `wallet.vtu.*` routes → `VtuOrderController` or purchase page | UI and order path exist; provider release remains gated | Do not enable live purchases until provider contracts and ambiguous-outcome recovery are verified |
| Wallet transfer | `wallet.transfer` | Transfer page registered | Actual transfer actions remain subject to server-side authorization and ledger safeguards |
| Manual deposit | `wallet.manual-deposits.index` → `ManualDepositController` | Submission/review workflow exists | Admin-controlled instructions and review; never trust browser-only payment claims |
| Services / education | `services.index`, `education-services.index` | Service catalogue and education flow registered | Per-provider readiness and purchase gates apply |
| Referrals | `account.referrals` | Route registered | Authenticated account |
| Gadget cards / Shop Gadgets | `services.coming-soon` → `gadget-catalogue` | **Preview only** | Sample cards are not inventory, checkout, or verified prices |
| Coming Soon entries | `services.coming-soon` | Explicit preview state | Must not be represented as completed live service |
| Admin dashboard | `admin.dashboard` → `DashboardController::admin` | Implemented dashboard | Requires `staff.permissions.manage` |
| Admin modules | `admin.*` route group | Individual modules vary | Requires authentication and the module's explicit capability; verify direct URL access |

## Acceptance criteria

- Each dashboard link resolves to a registered GET route or is clearly marked as unavailable.
- Admin routes require authentication and an explicit permission gate.
- HTTP tests exercise direct URL access, including denied requests, for ADMIN, STAFF, SUPPORT, and USER.
- PHP 8.3 and 8.4 suites, security suites, and the frontend build must pass on the exact closing commit.
- Provider purchases and live financial transactions remain gated throughout this audit.
