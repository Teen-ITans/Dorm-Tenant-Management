<?php
/**
 * cron/check_expirations.php
 *
 * Run this on a schedule to auto-generate contract-expiry and
 * overdue-payment notifications (+ email alerts). This is the
 * "Workflow Automation" piece — Notification Management (in the
 * admin panel) covers manual sends; this script covers the automatic
 * ones so nothing has to be checked by hand every day.
 *
 * Windows Task Scheduler setup:
 *   Program/script : C:\xampp\php\php.exe
 *   Arguments      : "C:\xampp\htdocs\dorm-tenant-system\cron\check_expirations.php"
 *   Trigger        : Daily, e.g. 7:00 AM
 *
 * macOS/Linux cron:
 *   0 7 * * * /Applications/XAMPP/xamppfiles/bin/php /path/to/dorm-tenant-system/cron/check_expirations.php
 *
 * You can also just double-click / run this manually while testing.
 */

require_once __DIR__ . '/../config/app.php';

$db = get_db();
$systemUserId = 1; // the default admin seeded in schema.sql — used as the "sender" of automated alerts
$sentCount = 0;

// ---- 0) Roll contract statuses forward --------------------------------
// Leases that ran past their end date become 'Expired', ones inside the
// 30-day window become 'Expiring Soon'. The app does this on page load
// too, but running it here means it happens even on a quiet day when
// nobody signs in.
refresh_contract_statuses($db);

// ---- 1) Contracts expiring within the next 7 days ---------------------
$stmt = $db->query("
    SELECT c.contract_id, c.contract_end, u.first_name, u.email, t.tenant_id
    FROM contracts c
    JOIN tenants t ON t.tenant_id = c.tenant_id
    JOIN users u ON u.user_id = t.user_id
    WHERE c.contract_status IN ('Active','Expiring Soon')
      AND c.contract_end BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)
");
foreach ($stmt->fetchAll() as $row) {
    $subject = 'Your dorm contract is expiring soon';
    $message = "Hi {$row['first_name']}, your contract ends on "
             . date('F j, Y', strtotime($row['contract_end']))
             . '. Please visit the office to renew.';

    $db->prepare("INSERT INTO notifications (sender_id, type, subject, message, target_type, target_value)
                  VALUES (?, 'Contract Expiry Alert', ?, ?, 'tenant', ?)")
       ->execute([$systemUserId, $subject, $message, $row['tenant_id']]);

    send_email_alert($row['email'], $row['first_name'], $subject, email_template($subject, $message));
    $sentCount++;
}

// ---- 1b) Contracts that expired yesterday -----------------------------
// One clear "it's up, here's what happens next" alert on the day after
// the term ends. Bounded to a single day so this can't re-send every
// morning for a lease nobody has gotten around to closing out.
$expiredStmt = $db->query("
    SELECT c.contract_id, c.contract_end, u.first_name, u.email, t.tenant_id
    FROM contracts c
    JOIN tenants t ON t.tenant_id = c.tenant_id
    JOIN users u ON u.user_id = t.user_id
    WHERE c.contract_status = 'Expired'
      AND c.contract_end = DATE_SUB(CURDATE(), INTERVAL 1 DAY)
");
foreach ($expiredStmt->fetchAll() as $row) {
    $subject = 'Your dorm contract has expired';
    $message = "Hi {$row['first_name']}, your contract ended on "
             . date('F j, Y', strtotime($row['contract_end']))
             . '. You can request a renewal from My Room & Contract in your portal, or visit the office to arrange moving out.';

    $db->prepare("INSERT INTO notifications (sender_id, type, subject, message, target_type, target_value)
                  VALUES (?, 'Contract Expiry Alert', ?, ?, 'tenant', ?)")
       ->execute([$systemUserId, $subject, $message, $row['tenant_id']]);

    send_email_alert($row['email'], $row['first_name'], $subject, email_template($subject, $message));
    $sentCount++;
}

// ---- 2) Payments that are now overdue ----------------------------------
// GCash rows are skipped: a Pending one is an open (or abandoned)
// checkout link, not money the tenant owes and withheld. PayMongo
// settles those, or settle_pending_gcash_payment() clears them away —
// chasing them here would bill people for a button they clicked once.
$stmt2 = $db->query("
    SELECT p.payment_id, p.due_date, u.first_name, u.email, t.tenant_id
    FROM payments p
    JOIN tenants t ON t.tenant_id = p.tenant_id
    JOIN users u ON u.user_id = t.user_id
    WHERE p.payment_status = 'Pending' AND p.due_date < CURDATE()
      AND p.paymongo_checkout_id IS NULL
");
foreach ($stmt2->fetchAll() as $row) {
    $db->prepare("UPDATE payments SET payment_status = 'Overdue', reminder_sent = TRUE WHERE payment_id = ?")
       ->execute([$row['payment_id']]);

    $subject = 'Payment overdue — action needed';
    $message = "Hi {$row['first_name']}, your payment due on "
             . date('F j, Y', strtotime($row['due_date']))
             . ' is now overdue. Please settle it as soon as you can.';

    $db->prepare("INSERT INTO notifications (sender_id, type, subject, message, target_type, target_value)
                  VALUES (?, 'Payment Reminder', ?, ?, 'tenant', ?)")
       ->execute([$systemUserId, $subject, $message, $row['tenant_id']]);

    send_email_alert($row['email'], $row['first_name'], $subject, email_template($subject, $message));
    $sentCount++;
}

echo '[' . date('Y-m-d H:i:s') . "] Expiration check complete — {$sentCount} alert(s) generated." . PHP_EOL;
