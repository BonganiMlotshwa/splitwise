# FTM IT Property Management System

PHP + PostgreSQL application for IT asset checkout, equipment handovers, and received equipment applications. Runs in Docker via `start-ftm-system.bat`.

## Quick Start

1. Start Docker Desktop.
2. Run `start-ftm-system.bat` from the project root.
3. Copy `.env.example` to `.env` and set strong passwords (see [Security](#security) below).
4. Open [http://localhost:8000/auth/login.php](http://localhost:8000/auth/login.php)

**Stop the stack**

```bat
docker-compose down
```

## Docker Services

| Service         | Container        | Host port | Purpose                                      |
|-----------------|------------------|-----------|----------------------------------------------|
| `php`           | `ftm_php`        | `8000`    | Apache + PHP application                     |
| `postgres`      | `ftm_postgres`   | `5432`    | Main DB: items, users, applications, handovers |
| `apps-postgres` | `ftm_apps_postgres` | `5433` | Legacy secondary DB (not used by current PHP pages) |

The PHP container connects to `postgres` using the environment variables defined in `docker-compose.yml` (mirrored in `config.php`).

## Configuration (`config.php`)

All pages include `config.php`. Key settings:

| Setting | Source | Default (local Docker) |
|---------|--------|-------------------------|
| `DB_SERVER` | `getenv('DB_SERVER')` | `postgres` |
| `DB_USERNAME` | `getenv('DB_USERNAME')` | `postgres` |
| `DB_PASSWORD` | `getenv('DB_PASSWORD')` | *(required in `.env`)* |
| `ADMIN_PASSWORD` | `getenv('ADMIN_PASSWORD')` | *(required in `.env`)* |
| `ALLOW_PUBLIC_SIGNUP` | `getenv('ALLOW_PUBLIC_SIGNUP')` | `false` |
| `DB_NAME` | `getenv('DB_NAME')` | `ftm_it_property_records` |
| `DB_PORT` | `getenv('DB_PORT')` | `5432` |
| `BASE_PATH` | constant | `/` |
| `SITE_NAME` | constant | `FTM IT PROPERTY RECORDS` |

Session timeouts: admins 30 minutes, users 45 minutes.

SMTP and AI assistant options are also defined in `config.php` (`SMTP_ENABLED`, `AI_PROVIDER`, etc.).

### Automatic schema updates

On each request, `config.php` ensures these columns exist on older databases:

- `items.ftm_pin` — FTM PIN for permanent item assignments
- `applications.urgency` — priority level (`low`, `normal`, `high`, `urgent`; default `normal`)

No manual migration is required for those columns after pulling the latest code.

### First-time / full database setup

`init.sql` seeds core tables (users, items, categories) when the Postgres volume is first created.

Handover and application tables are created by **`setup_database.php`**:

[http://localhost:8000/setup_database.php](http://localhost:8000/setup_database.php)

Run this once on a new environment (or after restoring an old database) to create:

- `handovers`, `handover_devices` — equipment handover records
- `applications` — received application requests (includes `urgency`)

## Main Features

### Items (`items/`)

Check out and return IT equipment, import CSV, manage categories and departments.

### Equipment Handovers (`handovers/`)

Record devices issued to staff (employee, FTM PIN, department, serial numbers). Export and summary reports included.

### Received Applications (`reports/received_applications.php`)

Track equipment requests with:

- Reference number, applicant, department, FTM PIN, job card
- Item, quantity, purpose
- **Urgency**: Low, Normal, High, Urgent
- **Status**: Pending, Approved, Allocated, Completed, Rejected, Cancelled
- Handed-over (allocation) date

Filtered views: Handed Over, Approved, All. Admins can delete records.

## Project Layout

```
auth/           Login, password reset
items/          Item checkout, returns, import
handovers/      Equipment handover forms and exports
reports/        Received applications and other reports
includes/       Shared layout (header, footer, nav)
api/            JSON API endpoints
config.php      DB connection, auth helpers, auto-migrations
setup_database.php   Create/update handover & application tables
init.sql        Docker first-run schema for core tables
docker-compose.yml
start-ftm-system.bat
```

## Security

Credentials are **not** stored in this repository. Before first run:

1. Copy `.env.example` to `.env`.
2. Set `ADMIN_PASSWORD` to a strong password (used for the `admin` login).
3. Set `DB_PASSWORD` and `POSTGRES_PASSWORD` to matching strong values.
4. Keep `ALLOW_PUBLIC_SIGNUP=false` so visitors cannot create their own accounts.

The app syncs `ADMIN_PASSWORD` from `.env` to the database on startup. Change `.env` and restart Docker to rotate the admin password.

If this project was ever public with old default passwords, change all passwords in `.env` immediately and consider making the GitHub repository private.

## Troubleshooting

**`column "urgency" of relation "applications" does not exist`**

Pull the latest `config.php` and reload any page (auto-migration runs on connect), or run `setup_database.php`. You can also apply manually:

```sql
ALTER TABLE applications ADD COLUMN urgency VARCHAR(50) DEFAULT 'normal';
```

**Applications or handover pages empty / table missing**

Open [setup_database.php](http://localhost:8000/setup_database.php) once.

**Cannot connect to database**

Ensure containers are healthy: `docker-compose ps`. The app expects Postgres at host `postgres` inside the Docker network (not `localhost` from inside PHP).

## Notes

- PHP entry point: `/auth/login.php` (not Angular).
- Activity is logged to `logs/activity-YYYY-MM-DD.log`.
- Composer vendor packages (e.g. Dompdf for PDF export) live under `vendor/`.
