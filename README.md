# FTM IT Property Management System

PHP 8 + PostgreSQL 16 application for IT asset tracking, equipment handovers, and received-equipment applications. Runs in Docker on Windows via `start-ftm-system.bat`.

---

## Quick Start

1. Start Docker Desktop.
2. Copy `.env.example` to `.env` and fill in all required values (see [Environment Variables](#environment-variables)).
3. Run `start-ftm-system.bat` from the project root.
4. Open [http://localhost:8000/auth/login.php](http://localhost:8000/auth/login.php) and log in as `admin`.

To stop: `docker compose down`

---

## Environment Variables

Copy `.env.example` to `.env`. Never commit `.env`.

| Variable | Required | Description |
|---|---|---|
| `DB_PASSWORD` | Yes | PostgreSQL user password |
| `POSTGRES_PASSWORD` | Yes | Same value as `DB_PASSWORD` |
| `ADMIN_PASSWORD` | Yes | Password for the built-in `admin` account |
| `ALLOW_PUBLIC_SIGNUP` | No | `false` (default) — prevents self-registration |
| `APP_ENV` | No | `production` hides PHP errors from users; `development` shows them |
| `SMTP_ENABLED` | No | `true` to send password-reset emails; `false` (default) |

Changing `ADMIN_PASSWORD` and restarting Docker updates the admin login automatically.

---

## Docker Services

| Service | Container | Host Port | Purpose |
|---|---|---|---|
| `php` | `ftm_php` | `8000` | Apache + PHP application |
| `postgres` | `ftm_postgres` | `5433` | Main database |

The PHP container connects to Postgres using `DB_SERVER=postgres` (Docker internal hostname).

---

## Database Setup

`init.sql` runs automatically on the **first** container start and creates all tables:

- `users`, `items`, `categories`, `departments`, `password_reset_tokens`
- `activity_log` — DB-backed audit trail
- `employees` — 15 IT staff who receive equipment (replaces all hardcoded name lists)
- `handovers`, `handover_devices` — equipment handover records
- `applications` — received equipment requests

If you are connecting to an **existing** database, run the migrations manually:

```bash
docker exec -it ftm_postgres psql -U postgres -d ftm_it_property_records
```

```sql
CREATE TABLE IF NOT EXISTS employees (
    id SERIAL PRIMARY KEY, name VARCHAR(190) NOT NULL,
    ftm_pin VARCHAR(50), department VARCHAR(100),
    active BOOLEAN DEFAULT TRUE, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE IF NOT EXISTS activity_log (
    id SERIAL PRIMARY KEY, actor_user_id INTEGER,
    action VARCHAR(100) NOT NULL, entity_type VARCHAR(50) NOT NULL,
    entity_id INTEGER NOT NULL, details TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
ALTER TABLE items ADD COLUMN IF NOT EXISTS ftm_pin VARCHAR(100) NULL;
ALTER TABLE applications ADD COLUMN IF NOT EXISTS urgency VARCHAR(50) DEFAULT 'normal';
```

`setup_database.php` (admin-only) can also be used to create missing tables on an existing database.

---

## Features

### Items (`items/`)

| Page | Description |
|---|---|
| `items.php` | List, search, filter, bulk-delete items |
| `add_item.php` | Add items with duplicate detection and batch quantity |
| `edit_item.php` | Edit name, serial, category, description, status |
| `delete_item.php` | POST-only delete (admin only) |
| `checkout.php` | Check out items to staff (dropdown from `employees` table) |
| `return_item.php` | Multi-select return with condition and notes |
| `assign_permanent.php` | Permanently assign items to employees with FTM PIN |
| `permanently_assigned.php` | View permanent assignments; admins can Revoke or Delete |
| `revoke_permanent.php` | Revoke a permanent assignment; item returns to Available |
| `item_history.php` | Full audit trail for a single item (DB + file log) |
| `import_csv.php` | Bulk import from CSV with duplicate detection |

### Handovers (`handovers/`)

Record devices issued to staff (employee, FTM PIN, department, serial numbers).
Export to PDF, view summaries and detailed breakdowns per employee.

### Received Applications (`reports/received_applications.php`)

Track equipment requests:
- Reference number, applicant, department, FTM PIN, job card number
- Item, quantity, purpose
- Urgency: Low / Normal / High / Urgent
- Status: Pending → Approved → Allocated / Completed / Rejected / Cancelled
- Allocation date and remarks

Filtered views: All, Handed Over, Approved, Pending. Admins can add, edit, and delete records.

### Activity Log (`reports/activity.php`)

All create/update/delete/checkout/return actions are logged to both:
- `logs/activity-YYYY-MM-DD.json` (flat file, always available)
- `activity_log` DB table (queryable; per-item history via `item_history.php`)

### Dashboard (`index.php`)

Single-query stat cards: Total, Available, Checked Out, Permanently Assigned, Handovers, Applications. Overdue items alert shown when any checked-out item is past its expected return date.

---

## Project Layout

```
auth/                   Login, logout, password reset, signup
items/                  All item management pages
handovers/              Handover forms, summary, details, export
reports/                Received applications, activity log
includes/               Shared header, footer, nav
api/                    JSON API endpoints (legacy; not used by current pages)
logs/                   Daily JSON activity logs (gitignored)
vendor/                 Composer packages: dompdf (PDF export)
config.php              DB connection, auth helpers, CSRF, activity logging
init.sql                First-run schema for all tables + employee seed data
setup_database.php      Admin-only page to create/verify tables on existing DBs
docker-compose.yml      PHP + Postgres service definitions
Dockerfile              PHP/Apache image with pdo_pgsql
start-ftm-system.bat    Windows helper to start Docker and open browser
.env.example            Template — copy to .env, never commit .env
```

---

## Security

- **CSRF tokens** on every POST form site-wide (per-session, `hash_equals` verified).
- **POST-only deletes** — no delete action is reachable via a GET URL.
- **Bcrypt** password hashing for all user accounts.
- **Role-based access** — admin-only pages call `require_admin()`.
- **Prepared statements** throughout — no raw string interpolation in SQL.
- **Error display** controlled by `APP_ENV` — set `production` to suppress PHP errors from users.
- Credentials belong in `.env` (gitignored). Use Vaultwarden for shared secrets.

---

## Troubleshooting

**Checkout "Taken By" list is empty**
The `employees` table is missing. Run the migration SQL above, or restart with a fresh Docker volume so `init.sql` runs.

**Cannot connect to database**
Check container health: `docker compose ps`. The app expects Postgres at host `postgres` inside the Docker network — not `localhost`.

**Applications or handover pages blank / table missing**
Visit `setup_database.php` as an admin to create missing tables.

**PHP errors visible in browser**
Set `APP_ENV=production` in `.env` and restart PHP: `docker compose restart php`.
