# Stockwise (MBIMSD) v0.6: Sprint 7: forecasting research

Multi-Branch Inventory Management System and Dashboard. Final year project, Bachelor of Information Technology,
Zambia University College of Technology. Author: Katongo K. Mwansa.

> This is a staged snapshot of the system at the end of Sprint 7. The complete system is version **v1.0**.

## What this version contains

- Everything in v0.5, plus the `ml/` folder (Python):
- Sales history generator with weekly, payday, December and trend patterns (`generate_history.py`)
- Four forecasting methods: 7-day moving average, same weekday last week, Holt-Winters and a global random forest (`models.py`)
- Rolling-origin comparison of the methods (`evaluate_models.py`, results in `ml/results/`)
- Stock calculations: safety stock, reorder point, suggested order, stockout replay (`inventory.py`)
- Unit tests (`ml/tests/test_ml.py`)

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

