-- ════════════════════════════════════════════════════════════════
-- Stockwise defect fixes — run ONCE on a database created from an
-- older schema.sql (fresh installs from the current schema.sql already
-- have this). Safe to run after ml_migration.sql.
--
--   XAMPP:  phpMyAdmin → select stockwise_php → Import → this file
--   CLI:    mysql -u root -p stockwise_php < database/fixes_migration.sql
-- ════════════════════════════════════════════════════════════════
USE stockwise_php;

-- D5: an alert logged while no SMTP server is configured is now recorded
-- as 'not_sent' instead of 'sent'.
ALTER TABLE notifications
    MODIFY status ENUM('sent', 'failed', 'not_sent') NOT NULL DEFAULT 'sent';
