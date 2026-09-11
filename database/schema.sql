-- =====================================================================
-- Dorm Tenant Management System — Database Schema
-- =====================================================================
-- How to run:
--   phpMyAdmin  -> New -> Import -> choose this file
--   OR command line: mysql -u root -p < schema.sql
--
-- This file DROPS and recreates the database, so it's safe to re-run
-- any time you want to reset to a clean state with demo data.
-- =====================================================================

DROP DATABASE IF EXISTS dorm_tenant_system;
CREATE DATABASE dorm_tenant_system
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE dorm_tenant_system;

-- ---------------------------------------------------------------------
-- 1. USERS — login + profile for BOTH admin and tenant accounts.
--    (One login system, one table, separated by `role`.)
-- ---------------------------------------------------------------------
CREATE TABLE users (
  user_id       INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  first_name    VARCHAR(50)  NOT NULL,
  last_name     VARCHAR(50)  NOT NULL,
  email         VARCHAR(100) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  reset_otp         VARCHAR(10) DEFAULT NULL,
  reset_otp_expires DATETIME DEFAULT NULL,
  password_changed_at DATETIME DEFAULT NULL,
  age           TINYINT UNSIGNED DEFAULT NULL,
  phone         VARCHAR(20)  DEFAULT NULL,
  role          ENUM('admin','tenant') NOT NULL DEFAULT 'tenant',
  is_active     BOOLEAN NOT NULL DEFAULT TRUE,
  last_login    DATETIME DEFAULT NULL,
  date_created  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_users_email (email),
  INDEX idx_users_role (role)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 2. DORM_ROOMS — physical room inventory.
-- ---------------------------------------------------------------------
CREATE TABLE dorm_rooms (
  room_id      INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  room_number  VARCHAR(10)  NOT NULL,
  room_type    VARCHAR(50)  NOT NULL,        -- Studio / Single / 2BR ...
  capacity     TINYINT UNSIGNED NOT NULL DEFAULT 1,
  monthly_rate DECIMAL(10,2) NOT NULL,
  floor_number TINYINT NOT NULL DEFAULT 1,
  status       ENUM('Available','Occupied','Under Maintenance','Reserved')
               NOT NULL DEFAULT 'Available',
  description  TEXT,
  date_added   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_room_number (room_number),
  INDEX idx_room_status (status),
  INDEX idx_room_type (room_type)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 3. TENANTS — a tenant "profile" attached to a user account.
--    Kept separate from `users` because not every user is a tenant,
--    and tenancy has its own lifecycle (approval, check-in/out).
-- ---------------------------------------------------------------------
CREATE TABLE tenants (
  tenant_id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id           INT UNSIGNED NOT NULL,
  room_id           INT UNSIGNED DEFAULT NULL,
  checkin_date      DATE DEFAULT NULL,
  checkout_date     DATE DEFAULT NULL,
  status            ENUM('Pending','Active','Evicted','Checked Out')
                    NOT NULL DEFAULT 'Pending',
  key_returned      BOOLEAN NOT NULL DEFAULT FALSE,
  approval_status   ENUM('Pending','Approved','Rejected')
                    NOT NULL DEFAULT 'Pending',
  rejection_reason  TEXT DEFAULT NULL,
  emergency_contact VARCHAR(100),
  emergency_phone   VARCHAR(20),
  date_registered   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_tenant_user FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE,
  CONSTRAINT fk_tenant_room FOREIGN KEY (room_id) REFERENCES dorm_rooms(room_id) ON DELETE SET NULL,
  UNIQUE KEY uq_tenant_user (user_id),
  INDEX idx_tenant_status (status),
  INDEX idx_tenant_approval (approval_status),
  INDEX idx_tenant_room (room_id)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 4. CONTRACTS — one row per lease term.
--    Split out from "payments" (see note below) because one contract
--    covers MANY monthly payments — the prototype's own "Payment
--    History" screen shows several months per tenant under one lease.
-- ---------------------------------------------------------------------
CREATE TABLE contracts (
  contract_id      INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id        INT UNSIGNED NOT NULL,
  room_id          INT UNSIGNED NOT NULL,
  monthly_rent     DECIMAL(10,2) NOT NULL,
  security_deposit DECIMAL(10,2) NOT NULL DEFAULT 0,
  contract_start   DATE NOT NULL,
  contract_end     DATE NOT NULL,
  contract_status  ENUM('Active','Expiring Soon','Expired','Terminated')
                   NOT NULL DEFAULT 'Active',
  contract_file    VARCHAR(255) DEFAULT NULL, -- uploaded PDF path
  created_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_contract_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(tenant_id) ON DELETE CASCADE,
  CONSTRAINT fk_contract_room   FOREIGN KEY (room_id)   REFERENCES dorm_rooms(room_id),
  INDEX idx_contract_status (contract_status),
  INDEX idx_contract_end (contract_end)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 5. PAYMENTS — one row per monthly payment, linked to its contract.
-- ---------------------------------------------------------------------
CREATE TABLE payments (
  payment_id        INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  contract_id       INT UNSIGNED NOT NULL,
  tenant_id         INT UNSIGNED NOT NULL,
  payment_amount    DECIMAL(10,2) NOT NULL,
  payment_for_month VARCHAR(20)  DEFAULT NULL,  -- e.g. "June 2026"
  payment_date      DATE DEFAULT NULL,
  due_date          DATE DEFAULT NULL,
  payment_status    ENUM('Pending','Paid','Overdue') NOT NULL DEFAULT 'Pending',
  payment_method    VARCHAR(50) DEFAULT NULL,     -- Cash / GCash / Bank Transfer ...
  reference_no      VARCHAR(100) DEFAULT NULL,
  receipt_file      VARCHAR(255) DEFAULT NULL,
  reminder_sent     BOOLEAN NOT NULL DEFAULT FALSE,
  created_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_payment_contract FOREIGN KEY (contract_id) REFERENCES contracts(contract_id) ON DELETE CASCADE,
  CONSTRAINT fk_payment_tenant   FOREIGN KEY (tenant_id)   REFERENCES tenants(tenant_id) ON DELETE CASCADE,
  INDEX idx_payment_status (payment_status),
  INDEX idx_payment_due (due_date)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 6. MAINTENANCE_REQUESTS
-- ---------------------------------------------------------------------
CREATE TABLE maintenance_requests (
  maintenance_id    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id         INT UNSIGNED NOT NULL,
  room_id           INT UNSIGNED NOT NULL,
  issue_title       VARCHAR(100) NOT NULL,
  issue_description TEXT,
  priority_level    ENUM('Low','Medium','High','Urgent') NOT NULL DEFAULT 'Medium',
  status            ENUM('Pending','Ongoing','Completed') NOT NULL DEFAULT 'Pending',
  assigned_to       VARCHAR(100) DEFAULT NULL,   -- e.g. "Plumber Team"
  photo_file        VARCHAR(255) DEFAULT NULL,
  date_submitted    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  date_resolved     DATE DEFAULT NULL,
  CONSTRAINT fk_maint_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(tenant_id) ON DELETE CASCADE,
  CONSTRAINT fk_maint_room   FOREIGN KEY (room_id)   REFERENCES dorm_rooms(room_id),
  INDEX idx_maint_status (status),
  INDEX idx_maint_priority (priority_level)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 7. NOTIFICATIONS — announcements / reminders / expiry alerts.
--    Not in the original data dictionary (only implied by the DFD's
--    "Notification Logs" store) — added so Notification Management
--    is actually functional.
-- ---------------------------------------------------------------------
CREATE TABLE notifications (
  notification_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  sender_id        INT UNSIGNED NOT NULL,
  type             ENUM('Announcement','Payment Reminder','Contract Expiry Alert') NOT NULL,
  subject          VARCHAR(150) NOT NULL,
  message          TEXT NOT NULL,
  target_type      ENUM('all','room','tenant') NOT NULL DEFAULT 'all',
  target_value     VARCHAR(100) DEFAULT NULL,   -- room_number OR tenant_id, depending on target_type
  date_sent        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_notif_sender FOREIGN KEY (sender_id) REFERENCES users(user_id),
  INDEX idx_notif_type (type),
  INDEX idx_notif_target (target_type, target_value)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 8. REPORTS — a log of generated reports (for Reports & Analytics).
-- ---------------------------------------------------------------------
CREATE TABLE reports (
  report_id      INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  report_type    ENUM('Occupancy','Payment','Tenant','Maintenance') NOT NULL,
  generated_by   INT UNSIGNED NOT NULL,
  date_generated TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  period_start   DATE DEFAULT NULL,
  period_end     DATE DEFAULT NULL,
  total_records  INT DEFAULT 0,
  file_path      VARCHAR(255) DEFAULT NULL,
  CONSTRAINT fk_report_user FOREIGN KEY (generated_by) REFERENCES users(user_id)
) ENGINE=InnoDB;

-- =====================================================================
-- SEED / DEMO DATA
-- =====================================================================

-- Default admin account
--   email:    admin@dorm.edu
--   password: Admin@123   (change this after your first login!)
INSERT INTO users (first_name, last_name, email, password_hash, role, is_active)
VALUES ('System', 'Admin', 'admin@dorm.edu',
        '$2b$12$iuxfSC7swH3oeF0qNsxIduPKTXpcvyxUHpD1c0kYANCow.zRZ75yu',
        'admin', 1);

-- Demo tenant account (already approved + assigned, so you have something
-- to look at immediately instead of starting from an empty dashboard)
--   email:    angel@student.dorm.edu
--   password: Tenant@123
INSERT INTO users (first_name, last_name, email, password_hash, age, phone, role, is_active)
VALUES ('Angel', 'Benitez', 'angel@student.dorm.edu',
        '$2b$12$v13v9dJQqKQn51t00TTSwOJr6Ej31mxfsbxF/mIoR3TjY3eORvEH6',
        20, '+63 917 123 4567', 'tenant', 1);

INSERT INTO dorm_rooms (room_number, room_type, capacity, monthly_rate, floor_number, status, description) VALUES
  ('101', 'Studio', 1, 5500.00, 1, 'Available', 'With CR'),
  ('102', 'Studio', 1, 5500.00, 1, 'Occupied',  'With CR'),
  ('103', '1BR',    2, 6500.00, 1, 'Available', NULL),
  ('201', '2BR',    4, 8000.00, 2, 'Available', NULL),
  ('301', '2BR',    4, 8000.00, 3, 'Available', 'Corner unit');

INSERT INTO tenants (user_id, room_id, checkin_date, status, approval_status, emergency_contact, emergency_phone)
VALUES (2, 2, '2025-08-15', 'Active', 'Approved', 'Maria Ayado', '+63 918 987 6543');

INSERT INTO contracts (tenant_id, room_id, monthly_rent, security_deposit, contract_start, contract_end, contract_status)
VALUES (1, 2, 5500.00, 5500.00, '2025-08-15', '2026-05-15', 'Active');

INSERT INTO payments (contract_id, tenant_id, payment_amount, payment_for_month, payment_date, due_date, payment_status, payment_method)
VALUES
  (1, 1, 5500.00, 'December 2025', '2025-12-29', '2025-12-30', 'Paid', 'GCash'),
  (1, 1, 5500.00, 'January 2026',  '2026-01-30', '2026-01-30', 'Paid', 'GCash'),
  (1, 1, 5500.00, 'February 2026', '2026-02-28', '2026-02-28', 'Paid', 'Cash');
