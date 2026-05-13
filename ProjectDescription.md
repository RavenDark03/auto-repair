# MECHANIX — Multi-Tenant Auto Repair SaaS

## Purpose
A multi-tenant SaaS platform for auto repair shops. A single platform operator ("Super Admin") onboards multiple auto repair businesses ("tenants"), each getting an isolated workspace to manage customers, vehicles, appointments, repair jobs, inventory, invoices, and payments.

## Tech Stack
- **Backend:** PHP 8.2, custom (no framework), MariaDB/MySQL via PDO
- **Frontend:** HTML + CSS + vanilla JS, custom design system with light/dark CSS tokens, Tabler Icons, DM Sans font
- **Payments:** PayMongo (Philippines)
- **Hosting:** XAMPP (dev), AlwaysData (prod via GitHub Actions SSH deploy)
- **Mobile:** Android (Kotlin, Jetpack Compose, Material 3) — spec exists, REST API partially built
- **CI/CD:** GitHub Actions (`deploy.yaml`) deploys on push to `main`
- **No Composer or npm deps** — zero-dependency project

## Architecture
- **Multi-tenant isolation** via `tenant_id` on all data tables
- **3 tenant roles:** `admin`, `cashier`, `mechanic` + independent `super_admin` platform role
- **Feature toggle system:** Plans define included features; tenants can override
- **State-machine onboarding:** `pending` → `approved/rejected` → `billing_sent` → `paid` → `converted`
- **Schema-as-code:** `includes/schema.php` detects and applies missing columns/tables at runtime
- **Read-Only plan:** `access_mode = read_only` blocks POST writes via middleware
- **DB triggers:** auto-create jobs on appointment approval, log job status changes, deduct inventory on parts use

## Key Directories

| Path | Purpose |
|---|---|
| `/` | Entry points: landing, login, registration, billing callbacks |
| `config/` | DB/PayMongo/app config (gitignored, `config.example.php` tracked) |
| `includes/` | Core lib: DB, session, auth, feature access, UI helpers, schema, provisioning |
| `admin/` | Tenant workspace: dashboard, customers, vehicles, appointments, jobs, inventory, invoices, payments, reports, staff, subscriptions |
| `admin/actions/` | 33+ POST handlers for tenant CRUD operations |
| `superadmin/` | Platform console: tenant management, billing, plans, earnings |
| `superadmin/actions/` | 13 POST handlers for platform operations |
| `api/v1/` | RESTful JSON API with bearer token auth for mobile app |
| `assets/css/` | 7 CSS files: design tokens, base, landing, admin, superadmin, bridges |
| `assets/js/` | Theme toggle, registration form, admin nav, job tabs, logout dialog, list filters |
| `database/migrations/` | 6 incremental SQL migration files |
| `src/components/` | Single exploratory React JSX component |
| `uploads/` | Owner identity document uploads |

## Database
19 tables: appointments, billing_requests, customers, email_logs, feature_pricing, features, inventory, inventory_purchases, inventory_purchase_items, invoices, invoice_items, jobs, job_parts_used, job_services, job_status_logs, mobile_api_tokens, payments, plan_features, registration_requested_features, subscriptions, subscription_plans, super_admins, suppliers, supplier_payments, tenants, tenant_features, tenant_registrations, users, vehicles.

## Pricing
| Plan | Monthly | Yearly |
|---|---|---|
| Starter | PHP 1,999 | PHP 19,990 |
| Growth | PHP 3,499 | PHP 34,990 |
| Pro | PHP 5,499 | PHP 54,990 |
| Read-Only | PHP 499 | PHP 4,990 |

Optional add-ons (PHP 249–399/mo): inventory, payments, reports, mechanic module.

## Security
- bcrypt password hashing, PDO prepared statements, `htmlspecialchars()` escaping
- Session-based auth with httponly/secure cookies
- Bearer token auth for mobile API (access + refresh tokens)
- File upload restriction via `.htaccess`

## Latest Implementation Update

### Automated Tenant Onboarding & UI/UX Refinement
- Manual billing is fully replaced with automated PayMongo integration.
- Newly registered tenants enter a `pending_payment` state and are shown a PayMongo checkout link.
- Dashboards for pending tenants are overlaid with a blurred payment gate until activation.
- `PHPMailer` is manually integrated (via custom autoloader) and configured for "Magic Login" emails and verification flows.
- UI/UX overhauled across all admin pages: bloated forms compacted, dashboard black-screen anomalies resolved, and spacing unified with the premium landing page design tokens.

### Tenant admin web
- Dashboard now includes revenue, total appointments, active/completed jobs, pending payments, low-stock alerts, mechanic workload, and recent activity events.
- Staff management now supports cashier/mechanic/admin account creation, staff detail edits, activate/deactivate, username reset, password reset, and role-aware access.
- Customer management now supports issuing and updating customer mobile login credentials linked to customer records.
- Appointments now support mechanic assignment, vehicle concern notes, status updates, and cancellation reasons.
- Jobs now use richer repair statuses: pending inspection, in repair, waiting for parts, and completed. Jobs also support priority, issue/description fields, customer-visible notes, internal notes, mechanic notes, parts used, and ready-for-invoice completion timestamps.
- Inventory now records restocks, job deductions, returns, and manual adjustments in `inventory_movements`, with movement history visible per inventory item.
- Invoices now capture services subtotal, parts subtotal, labor fee, inspection fee, subtotal, tax rate, tax amount, total, payment status, and print-view breakdowns.
- Reports now include date-range revenue, completed jobs by mechanic, inventory usage, low stock, payment method summary, and customer service history.
- Tenant settings now provide shop profile editing for business name, contact details, address, operating hours, account status, access mode, and subscription status display.

### Customer mobile API
- Username/password login is supported through bearer-token auth with customer records linked by `users.customer_id`.
- Customer endpoints now expose dashboard summary, profile view/update, vehicle garage details, appointment viewing, job tracking with visible notes, service history, invoice viewing, and customer-side invoice payment recording.
- Customer appointment creation/cancellation endpoints now return shop-call-required errors to match the rule that customers do not book directly in-app.

### Mechanic mobile API
- Mechanic endpoints now expose assigned jobs, job details, scheduled appointments, dashboard counts, status updates, mechanic progress notes, job notes, and parts-used entry.
- Mechanic parts usage auto-deducts inventory through the existing database trigger and writes movement history for audit reporting.

### Runtime schema additions
- `includes/schema.php` now upgrades existing installs with tenant contact/profile fields, `users.customer_id`, appointment mechanic/concern/cancellation fields, expanded job statuses and job metadata, `job_notes`, inventory movement audit logs, job part timestamps/creator, invoice fee/tax breakdowns, and `mobile_api_tokens`.
