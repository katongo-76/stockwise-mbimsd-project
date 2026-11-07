-- Stockwise (PHP/MySQL build) — schema + seed data
-- Import with:  mysql -u root -p < database/schema.sql

CREATE DATABASE IF NOT EXISTS stockwise_php CHARACTER SET utf8mb4;
USE stockwise_php;

-- ────────────────────────────────────────────────────────────────
-- Companies, users, branches (unchanged shape from the earlier build)
-- ────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS companies (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    name       VARCHAR(160) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS branches (
    id          VARCHAR(64) PRIMARY KEY,
    company_id  INT NOT NULL,
    branch_code VARCHAR(12) NOT NULL UNIQUE,
    name        VARCHAR(120) NOT NULL,
    city        VARCHAR(120) NOT NULL,
    country     VARCHAR(8)   NOT NULL,
    manager     VARCHAR(120) NOT NULL,
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS users (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    name          VARCHAR(160) NOT NULL DEFAULT '',
    email         VARCHAR(255) NOT NULL UNIQUE,
    phone         VARCHAR(32)  NOT NULL DEFAULT '',
    password_hash VARCHAR(255) NOT NULL,
    role          ENUM('manager', 'employee') NOT NULL DEFAULT 'employee',
    company_id    INT NULL,
    branch_id     VARCHAR(64) NULL,
    created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
    FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE SET NULL
);

-- ────────────────────────────────────────────────────────────────
-- Products — one row per distinct item per branch. current_quantity is
-- a MAINTAINED column: every stock-in/out updates it atomically in the
-- same DB transaction as the stock_batches/transactions insert (never
-- recomputed on the fly). Thresholds are configurable per product.
-- ────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS products (
    id                 INT AUTO_INCREMENT PRIMARY KEY,
    branch_id          VARCHAR(64) NOT NULL,
    name               VARCHAR(160) NOT NULL,
    category           VARCHAR(80) NOT NULL,
    unit_of_measure    VARCHAR(20) NOT NULL DEFAULT 'unit',
    current_quantity   INT NOT NULL DEFAULT 0,
    low_stock_threshold INT NOT NULL DEFAULT 10,
    expiry_alert_days  INT NOT NULL DEFAULT 14,
    created_at         TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE CASCADE
);

-- ────────────────────────────────────────────────────────────────
-- Stock batches — one row per delivery. Expiry is tracked PER BATCH,
-- not per product (FR-III): a product restocked twice has two expiry
-- dates tracked independently.
-- ────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS stock_batches (
    id                 INT AUTO_INCREMENT PRIMARY KEY,
    product_id         INT NOT NULL,
    quantity_received  INT NOT NULL,
    quantity_remaining INT NOT NULL,
    expiry_date        DATE NULL,
    received_date      DATE NOT NULL,
    barcode            VARCHAR(64) NOT NULL DEFAULT '',
    created_at         TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
);

-- ────────────────────────────────────────────────────────────────
-- Transactions — audit log of every stock-in / stock-out / adjustment
-- (Table 3.1: "INSERT into transactions audit log").
-- ────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS transactions (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    product_id   INT NOT NULL,
    branch_id    VARCHAR(64) NOT NULL,
    batch_id     INT NULL,
    type         ENUM('stock_in', 'stock_out', 'adjustment') NOT NULL,
    quantity     INT NOT NULL,
    performed_by INT NULL,
    source       ENUM('app', 'synthetic') NOT NULL DEFAULT 'app',
    created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
    FOREIGN KEY (batch_id) REFERENCES stock_batches(id) ON DELETE SET NULL,
    FOREIGN KEY (performed_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_transactions_product_date (product_id, type, created_at)
);

-- ════════════════════════════════════════════════════════════════
-- SEED DATA
-- ════════════════════════════════════════════════════════════════

INSERT INTO companies (id, name) VALUES (1, 'Stockwise Demo Co');

INSERT INTO branches (id, company_id, branch_code, name, city, country, manager) VALUES
    ('chifubu',   1, 'CHF4F82', 'Chifubu',   'Ndola', 'ZM', 'Mwila Banda'),
    ('ndeke',     1, 'NDK9K3Q', 'Ndeke',     'Ndola', 'ZM', 'Chanda Mulenga'),
    ('masala',    1, 'MSL7X1R', 'Masala',    'Ndola', 'ZM', 'Bwalya Phiri'),
    ('kansenshi', 1, 'KNS2M6B', 'Kansenshi', 'Ndola', 'ZM', 'Natasha Zulu');

-- Demo accounts
--   General Manager (all branches, read-only stock):  manager@stockwise.com   / manager123
--   Branch Staff (one branch each):                   chifubu@stockwise.com   / staff123
--                                                     ndeke@stockwise.com     / staff123
--                                                     masala@stockwise.com    / staff123
--                                                     kansenshi@stockwise.com / staff123
-- Roles are stored as 'manager' (General Manager) and 'employee' (Branch Staff).
INSERT INTO users (name, email, phone, password_hash, role, company_id, branch_id) VALUES
    ('Demo General Manager', 'manager@stockwise.com', '+260 97 000 0100',
     '$2y$10$h124vKl4iRGpjNS3oOfm2.hi1DcscU9xTtOIaHRR75z93aAqnmjGO',
     'manager', 1, NULL),
    ('Mwila Banda',    'chifubu@stockwise.com',   '+260 97 000 0101', '$2y$10$EjGNGDE82sG0zO3XDBp3/.ZmdDs1JWpbyAuKfvEPLtbfrTXv6XdXG', 'employee', 1, 'chifubu'),
    ('Chanda Mulenga', 'ndeke@stockwise.com',     '+260 97 000 0102', '$2y$10$EjGNGDE82sG0zO3XDBp3/.ZmdDs1JWpbyAuKfvEPLtbfrTXv6XdXG', 'employee', 1, 'ndeke'),
    ('Bwalya Phiri',   'masala@stockwise.com',    '+260 97 000 0103', '$2y$10$EjGNGDE82sG0zO3XDBp3/.ZmdDs1JWpbyAuKfvEPLtbfrTXv6XdXG', 'employee', 1, 'masala'),
    ('Natasha Zulu',   'kansenshi@stockwise.com', '+260 97 000 0104', '$2y$10$EjGNGDE82sG0zO3XDBp3/.ZmdDs1JWpbyAuKfvEPLtbfrTXv6XdXG', 'employee', 1, 'kansenshi');

-- Chifubu — richer seed set (products + batches, some near-expiry/expired for demo)
INSERT INTO products (id, branch_id, name, category, unit_of_measure, current_quantity, low_stock_threshold, expiry_alert_days) VALUES
    (1, 'chifubu', 'MacBook Pro 14"', 'Electronics', 'unit', 42, 10, 14),
    (2, 'chifubu', 'Wireless Keyboard', 'Electronics', 'unit', 67, 15, 14),
    (3, 'chifubu', "Men's Polo Shirt", 'Apparel', 'unit', 210, 40, 14),
    (4, 'chifubu', 'Organic Coffee Beans 1kg', 'Food & Beverage', 'kg', 95, 25, 30),
    (5, 'chifubu', 'Almond Milk 6-Pack', 'Food & Beverage', 'pack', 24, 20, 7),
    (6, 'chifubu', 'Cordless Drill', 'Tools', 'unit', 34, 10, 14),
    (7, 'chifubu', 'Vitamin D3 Capsules', 'Health', 'unit', 220, 50, 14),
    (8, 'chifubu', 'Ibuprofen 200mg x50', 'Health', 'unit', 40, 20, 14);

INSERT INTO stock_batches (product_id, quantity_received, quantity_remaining, expiry_date, received_date, barcode) VALUES
    (1, 42, 42, NULL, DATE_SUB(CURDATE(), INTERVAL 40 DAY), '012000001234'),
    (2, 67, 67, NULL, DATE_SUB(CURDATE(), INTERVAL 35 DAY), '012000004563'),
    (3, 210, 210, NULL, DATE_SUB(CURDATE(), INTERVAL 60 DAY), '020000001122'),
    (4, 60, 60, DATE_ADD(CURDATE(), INTERVAL 120 DAY), DATE_SUB(CURDATE(), INTERVAL 10 DAY), '030000001011'),
    (4, 35, 35, DATE_ADD(CURDATE(), INTERVAL 12 DAY), DATE_SUB(CURDATE(), INTERVAL 30 DAY), '030000001012'),
    (5, 24, 24, DATE_ADD(CURDATE(), INTERVAL 5 DAY), DATE_SUB(CURDATE(), INTERVAL 20 DAY), '030000004044'),
    (6, 34, 34, NULL, DATE_SUB(CURDATE(), INTERVAL 90 DAY), '040000001001'),
    (7, 220, 220, DATE_ADD(CURDATE(), INTERVAL 500 DAY), DATE_SUB(CURDATE(), INTERVAL 45 DAY), '080000001001'),
    (8, 40, 40, DATE_SUB(CURDATE(), INTERVAL 3 DAY), DATE_SUB(CURDATE(), INTERVAL 80 DAY), '080000003003');

INSERT INTO transactions (product_id, branch_id, batch_id, type, quantity, performed_by) VALUES
    (1, 'chifubu', 1, 'stock_in', 42, 2),
    (4, 'chifubu', 4, 'stock_in', 60, 2),
    (4, 'chifubu', 5, 'stock_in', 35, 2),
    (5, 'chifubu', 6, 'stock_in', 24, 2);

-- Ndeke, Masala, Kansenshi — lighter seed sets
INSERT INTO products (branch_id, name, category, unit_of_measure, current_quantity, low_stock_threshold, expiry_alert_days) VALUES
    ('ndeke', 'Bluetooth Speaker', 'Electronics', 'unit', 38, 15, 14),
    ('ndeke', "Women's Winter Jacket", 'Apparel', 'unit', 85, 20, 14),
    ('ndeke', 'Green Tea 100 Bags', 'Food & Beverage', 'box', 42, 20, 30),
    ('masala', 'USB-C Hub 7-Port', 'Electronics', 'unit', 118, 25, 14),
    ('masala', 'Running Shorts', 'Apparel', 'unit', 160, 30, 14),
    ('masala', 'Protein Powder 2kg', 'Food & Beverage', 'kg', 48, 15, 60),
    ('kansenshi', 'iPhone 15 Case', 'Electronics', 'unit', 95, 30, 14),
    ('kansenshi', 'Yoga Mat', 'Sporting Goods', 'unit', 52, 15, 14);

-- One batch per product above, so current_quantity matches its batches
-- (as stock-in would have created them). Green Tea expires in 4 days on
-- purpose: the ML forecast should flag part of it as at risk of expiring unsold.
INSERT INTO stock_batches (product_id, quantity_received, quantity_remaining, expiry_date, received_date, barcode)
SELECT id, current_quantity, current_quantity,
       CASE name WHEN 'Green Tea 100 Bags' THEN DATE_ADD(CURDATE(), INTERVAL 4 DAY)
                 WHEN 'Protein Powder 2kg' THEN DATE_ADD(CURDATE(), INTERVAL 150 DAY)
                 ELSE NULL END,
       DATE_SUB(CURDATE(), INTERVAL 21 DAY), ''
FROM products WHERE branch_id IN ('ndeke', 'masala', 'kansenshi');
