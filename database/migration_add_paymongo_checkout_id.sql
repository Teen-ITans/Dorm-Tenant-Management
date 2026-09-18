-- ============================================================
-- Migration: add paymongo_checkout_id to payments
-- ============================================================
-- Lets tenant/paymongo_return.php look a payment row back up by its
-- PayMongo Checkout Session id when the tenant is redirected back
-- from the hosted checkout page, so it can ask PayMongo directly
-- whether that session was actually paid before marking the row
-- Paid (never trusting the redirect alone).
--
-- Run this the same way as the other migration files:
--   phpMyAdmin → dorm_tenant_system → SQL tab → paste → Go
--   or: mysql -u root -p dorm_tenant_system < database/migration_add_paymongo_checkout_id.sql
--
-- Setting up fresh right now? Skip this — schema.sql already
-- includes this column.
-- ============================================================

USE dorm_tenant_system;

ALTER TABLE payments
  ADD COLUMN IF NOT EXISTS paymongo_checkout_id VARCHAR(100) DEFAULT NULL AFTER reference_no;
