# Stockwise (MBIMSD) v1.0: Multi-Branch Inventory Management System and Dashboard

Implements the MBIMSD proposal's stack: **PHP 8.x · MySQL (PDO prepared statements) · Bootstrap 5 · PHPMailer (SMTP) · XAMPP for local development.**

Two roles:
- **General Manager** (`manager` in the database) — owns a company and all its branches; read-only oversight dashboard (stock, expiry, alerts, trends, branch management). No operational stock controls.
- **Branch Staff** (`employee` in the database) — branch managers, clerks and other staff of one branch; scoped to that branch; records stock-in/stock-out, manages products.

## Setup with XAMPP

1. **Copy** this folder to `C:\xampp\htdocs\stockwise-php` (or anywhere you like).
2. **Start Apache and MySQL** in the XAMPP Control Panel.
3. **Import the database** — open phpMyAdmin → *Import* → choose `database/schema.sql`
   (or `mysql -u root < database/schema.sql`). This creates `stockwise_php` with demo data.
4. **Configure**: copy `.env.example` to `.env`. Defaults work for a stock XAMPP (user `root`, empty password).
5. **Point Apache at the `public/` folder.** All links assume `public/` is the web root. Add this to
   `C:\xampp\apache\conf\extra\httpd-vhosts.conf`, then restart Apache:

   ```apache
   <VirtualHost *:80>
       ServerName stockwise.local
       DocumentRoot "C:/xampp/htdocs/stockwise-php/public"
       <Directory "C:/xampp/htdocs/stockwise-php/public">
           Require all granted
       </Directory>
   </VirtualHost>
   ```
   and add `127.0.0.1 stockwise.local` to `C:\Windows\System32\drivers\etc\hosts`. Open **http://stockwise.local**.

   **Quicker alternative** (no Apache config) — use PHP's built-in server from the project folder:
   ```
   C:\xampp\php\php.exe -S localhost:8000 -t public
   ```
   then open **http://localhost:8000**.

PHP needs the `pdo_mysql`, `mbstring`, and `openssl` extensions (all enabled in XAMPP by default).

**No internet needed to run it.** Bootstrap 5.3.3, Chart.js 4.4.4 and the two fonts (DM Sans, JetBrains Mono) are bundled in `public/assets/vendor/`, so every page works fully offline. Internet is only needed once, to download XAMPP, Python and the Python libraries, and for sending real emails over SMTP.

## Demo accounts

| Role | Email | Password | Access |
|---|---|---|---|
| General Manager | manager@stockwise.com | manager123 | All four branches, read-only stock |
| Branch Staff | chifubu@stockwise.com | staff123 | Chifubu branch only |
| Branch Staff | ndeke@stockwise.com | staff123 | Ndeke branch only |
| Branch Staff | masala@stockwise.com | staff123 | Masala branch only |
| Branch Staff | kansenshi@stockwise.com | staff123 | Kansenshi branch only |

The two user types are **General Manager** (oversees every branch of the company) and **Branch Staff** (branch managers, clerks and other staff of one branch). In the database they are stored as the roles `manager` and `employee`.

Change these before using the system for anything real. New accounts: **Create an account** on the login page (a General Manager sets up the company and gets a join code per branch; Branch Staff join with a code).

## Email alerts (SMTP / PHPMailer)

Fill in the `SMTP_*` values in `.env`. With `SMTP_HOST` empty, alerts are **not sent but still logged** to the `notifications` table — handy for local testing.

Alerts fire two ways:
- **Immediately** after a stock-out/stock-in that leaves a product at or below its low-stock threshold.
- **Daily sweep** — `cron/check_alerts.php` emails each branch a digest of expiring/expired batches and low-stock products, plus a consolidated summary to the General Manager.

Schedule the sweep (Linux cron: `0 7 * * * php /path/to/cron/check_alerts.php`; Windows: Task Scheduler running `C:\xampp\php\php.exe C:\...\cron\check_alerts.php`). Run it by hand any time to test.

## Structure

```
public/            web root — one PHP file per page (login.php, dashboard.php, branch.php, ...)
  assets/vendor/   Bootstrap, Chart.js and fonts, bundled so the app runs offline
app/
  bootstrap.php    loaded first by every page
  Config/          env loader, PDO connection, secure session setup
  Middleware/      auth.php — require_login / require_manager / require_branch_access (RBAC on every request)
  Helpers/         CSRF, flash, formatting
  Services/        StockCalculationService (atomic stock-in/out), ExpiryService, NotificationService (PHPMailer),
                   ForecastService (reads the ML forecast tables)
  Views/partials/  shared head / navbars / footer
database/schema.sql          full schema + demo data (includes the ML tables)
database/ml_migration.sql    adds the ML tables to a database created before the ML add-on
database/fixes_migration.sql allows the "not_sent" notification status on a database created before the defect fixes
cron/check_alerts.php
ml/                Python demand forecasting (see ml/README.md)
vendor/phpmailer/  PHPMailer 6.9.3 (bundled — no Composer needed)
```

## Demand forecasting (ML)

A random forest model is trained from a dataset file (`ml/dataset/`) and saved as a model file (`ml/model/demand_model.joblib`). A nightly Python job loads it and forecasts each product's sales for the next 14 days. From that forecast it works out:
- days until stockout
- reorder point and suggested order quantity
- stock likely to expire unsold

Results appear on each branch's **Forecast** tab and on the General Manager dashboard cards. Setup, results and design notes are in **`ml/README.md`**.

## How it maps to the requirements (report, Section 3.6)

| Requirement | Where |
|---|---|
| FR-I Login by email and password, role-specific view | `public/login.php`, `Middleware/auth.php` |
| FR-II Branch Staff limited to their branch; General Manager read-only across the company | `Middleware/auth.php`, called at the top of every page |
| FR-III Company and branch registration, join codes, branch management | `signup_manager.php`, `signup_employee.php`, `branches.php` |
| FR-IV Products with low-stock threshold and expiry alert window | `products` table, `branch_stock.php` |
| FR-V Stock-in / stock-out as batches, soonest-expiring first | `StockCalculationService::recordStockIn` / `recordStockOut`, `stock_batches` |
| FR-VI Expiry status and write-off of expired stock | `ExpiryService`, `StockCalculationService::writeOffExpiredBatch`, Expiry page |
| FR-VII Low-stock alerts and daily summaries | `NotificationService`, `cron/check_alerts.php`, `notifications` log |
| FR-VIII General Manager dashboard with per-branch counts | `public/dashboard.php` |
| FR-IX Nightly 14-day demand forecast | `ml/` (see `ml/README.md`), `ml/forecast_job.py` |
| FR-X Stockout date, reorder point, suggested order on the Forecast page | `ForecastService`, `branch_forecast.php` |
| NFR-I Security | PDO prepared statements, password hashing, session regeneration and 30-minute timeout, CSRF tokens |
| NFR-II Integrity | one database transaction per stock movement (batch, product quantity, audit record) |
| NFR-III Performance | stock shown straight after a transaction; forecast job well under five minutes |
| NFR-IV Concurrency | tested with 10 concurrent users (TC-34) |
| NFR-V Forecast accuracy | `ml/train_model.py` compares the model with a 7-day moving average |

## Production notes

Run behind Apache/Nginx + PHP-FPM with HTTPS, set `SESSION_SECURE_COOKIE=1` and `APP_DEBUG=0`, use a dedicated MySQL user (not `root`), and rotate the demo passwords.

## Testing

- **Unit tests** (forecasting maths and models, 16 tests): from the `ml` folder, run `py -m unittest discover -s tests -v`
- **System tests** (functional, security, performance and regression, 42 tests): start the site, then from the project folder run
  `py tests\functional_tests.py --base http://localhost/stockwise-php/public --php C:\xampp\php\php.exe`
  (adjust the URL and path to your setup). Results are written to `tests/results.md` and `tests/results.csv`.
  Run them on a fresh copy of the demo data and re-import `database/schema.sql` afterwards, because the tests add products, users and stock movements.

## Version history

The project was developed in sprints (report, Section 3.5.1). Each tagged version in this repository is a
working snapshot of the system at the end of a stage, prepared from the finished code so that progress can be
followed step by step. Corrections made after testing are included in the stage where each feature first appears.

| Version | Stage | Main additions |
|---|---|---|
| v0.1 | Sprint 1 | Login, sign-up with branch join codes, role-based access control |
| v0.2 | Sprint 2 | Products, stock-in and stock-out by batch (soonest expiry first), audit trail |
| v0.3 | Sprint 3 | Expiry tracking, Expiry page, write-off of expired stock |
| v0.4 | Sprint 4 | Low-stock email alerts, daily summaries, notification log |
| v0.5 | Sprint 5 | General Manager dashboard, branch management, trends |
| v0.6 | Sprint 7 | Forecasting research: sales history generator, four models, comparison |
| v0.7 | Sprint 8 | Nightly forecast job, Forecast page, dashboard forecast warnings |
| v1.0 | Sprint 9 | Machine learning pipeline (dataset, trained model file), 42 automated system tests |

To look at an earlier version on GitHub, choose it under **Tags** (or the **Releases** page), or locally run `git checkout v0.3`.
