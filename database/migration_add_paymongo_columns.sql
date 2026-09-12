-- Adds PayMongo GCash tracking to the payments table.
-- Run once against your existing database:
--   mysql -u root -p dorm_tenant_system < database/migration_add_paymongo_columns.sql

ALTER TABLE payments
  ADD COLUMN paymongo_checkout_id VARCHAR(100) DEFAULT NULL AFTER receipt_file,
  ADD COLUMN paymongo_payment_id  VARCHAR(100) DEFAULT NULL AFTER paymongo_checkout_id,
  ADD COLUMN webhook_received_at  TIMESTAMP NULL DEFAULT NULL AFTER paymongo_payment_id;

-- A payment can now fail at GCash's end (declined, cancelled, timed out)
-- without a human ever touching it, so the status needs to represent that.
ALTER TABLE payments
  MODIFY payment_status ENUM('Pending','Paid','Overdue','Failed') NOT NULL DEFAULT 'Pending';

-- Lets the webhook look a payment up by checkout session id quickly.
-- NULLs don't collide under a UNIQUE index in MySQL, so this is safe
-- for the Cash/Bank Transfer/etc. rows that never get a checkout id.
CREATE UNIQUE INDEX idx_payment_paymongo_checkout ON payments(paymongo_checkout_id);
