# MECHANIX SaaS Auto Repair

MECHANIX is a multi-tenant auto repair SaaS built in PHP for XAMPP/MariaDB.

This project currently supports the core flow for:

- public tenant registration
- super admin review and billing
- tenant conversion after payment
- tenant admin operations
- cashier invoice and payment handling
- feature-based module access per tenant

## Canonical Schema

Use `auto_repair_saas.sql` as the source of truth.

Do not use `auto_repair_saas_clean.sql` for active setup. It is older and no longer matches the current application state.

## Environment

- XAMPP on Windows
- Apache
- MariaDB / MySQL
- PHP from `C:\xampp\php\php.exe`

App URL:

- `http://localhost/saas-auto-repair`

## Setup

1. Start Apache and MySQL in XAMPP.
2. Create or reset the database by importing `auto_repair_saas.sql`.
3. Copy `config/config.example.php` to `config/config.php`.
4. Confirm `config/config.php` points to:

```php
DB_HOST=localhost
DB_NAME=auto_repair_saas
DB_USER=root
DB_PASS=
```

5. Open:

- landing page: `http://localhost/saas-auto-repair/`
- tenant login: `http://localhost/saas-auto-repair/login.php`
- super admin login: `http://localhost/saas-auto-repair/superadmin/login.php`

## Seeded Accounts

The schema includes seeded usernames:

- tenant admin username: `admin`
- super admin username: `superadmin`

Because the database stores password hashes, the original plain-text passwords may not be known after import.

If needed, reset passwords locally using `hash.php` or direct SQL.

Helper page:

- `http://localhost/saas-auto-repair/hash.php`

## Example Local Admin Reset

To reset the seeded tenant admin account to `admin12345`:

```sql
UPDATE users
SET password_hash = '$2y$10$AeBVVLoZPmRoy8JLgD7brujy6AzBZ1KcenX5lV7F9S.o82Hsbwcqm'
WHERE username = 'admin' AND tenant_id = 1;
```

Then log in with:

- username: `admin`
- password: `admin12345`

## Core Demo Flow

### Tenant Registration

1. Open `register.php`.
2. Register a business with:
   - business name
   - owner name
   - email
   - preferred username
   - billing cycle
   - subscription plan
   - optional add-ons

### Super Admin Workflow

1. Log in as super admin.
2. Open the submitted registration.
3. Click `Approve Registration`.
4. Generate the billing draft.
5. Either:
   - create a PayMongo checkout, or
   - mark billing as `Paid` manually for demo use
6. Click `Convert Paid Registration to Tenant`.
7. Copy the onboarding credentials shown in the dashboard.

### Tenant Feature Toggle Demo

1. In the super admin tenant feature section, enable or disable modules.
2. Log in as the tenant admin.
3. Confirm the sidebar and direct page access match the enabled features.

### Tenant Admin / Cashier Demo

Use the tenant admin or cashier account to verify:

- invoices
- payments
- receivables
- tenant-scoped records only

## Current Scope

In-scope active workflows:

- super admin
- tenant admin
- cashier

Out of scope for the current implementation pass:

- mechanic portal/workflow
- customer portal/workflow

## Notes

- Feature access is enforced both in the UI and in backend guards.
- Tenant conversion now saves `converted_tenant_id`.
- Manual billing status updates maintain `paid_at`.
- Registration and billing actions are state-aware in the super admin dashboard.
- Payments page now surfaces database load errors instead of failing silently.

## GitHub Prep

This repo now includes:

- `.gitignore` for local-only files and installer binaries
- `config/config.example.php` as the tracked config template

Before pushing to GitHub:

1. Keep your real local settings in `config/config.php`.
2. Do not commit live API keys, webhook secrets, or production credentials.
3. Review `auto_repair_saas.sql` before pushing if it contains local or personal data.
4. Do not commit local installer files such as `Git-2.54.0-64-bit.exe`.

Typical first push:

```powershell
git init
git add .
git commit -m "Initial commit"
git branch -M main
git remote add origin https://github.com/YOUR_USERNAME/YOUR_REPO.git
git push -u origin main
```

## Quick Verification

Run PHP lint after edits:

```powershell
C:\xampp\php\php.exe -l path\to\file.php
```

Or lint the whole project:

```powershell
$php = 'C:\xampp\php\php.exe'
Get-ChildItem -Path C:\xampp\htdocs\saas-auto-repair -Recurse -Filter *.php |
    ForEach-Object { & $php -l $_.FullName }
```
