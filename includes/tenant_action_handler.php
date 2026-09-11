<?php
if (!defined('BASE_URL')) { http_response_code(403); exit('Direct access not permitted.'); }
/**
 * includes/tenant_action_handler.php
 *
 * Shared POST handler for the tenant lifecycle: approve, reject,
 * check-in, check-out, evict. The prototype spreads these actions
 * across three separate screens (Tenant Registration/Approval, Track
 * Status, Monitor Check-In/Check-out), but the underlying logic —
 * especially the transactional room-freeing on checkout/evict — has
 * to be identical everywhere it's triggered from. Rather than copy
 * the same five if-blocks into three files (and risk them drifting
 * out of sync), each of those three pages just requires this file at
 * the top and sets two variables first: $db and $selfPath (where to
 * redirect back to once the action is done).
 */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action   = $_POST['action'] ?? '';
    $tenantId = (int) ($_POST['tenant_id'] ?? 0);

    $tenantStmt = $db->prepare("SELECT t.*, u.first_name, u.last_name, u.email FROM tenants t JOIN users u ON u.user_id = t.user_id WHERE t.tenant_id = ?");
    $tenantStmt->execute([$tenantId]);
    $tenant = $tenantStmt->fetch();

    if (!$tenant) {
        flash('error', 'Tenant not found.');
        redirect($selfPath);
    }

    if ($action === 'approve') {
        $db->prepare("UPDATE tenants SET approval_status = 'Approved', rejection_reason = NULL WHERE tenant_id = ?")->execute([$tenantId]);
        flash('success', $tenant['first_name'] . ' has been approved. Assign them a room in Property Management next.');
        send_email_alert($tenant['email'], $tenant['first_name'], 'Your application has been approved',
            email_template('You\'re approved!', "Hi {$tenant['first_name']}, your tenant application has been approved. We'll notify you again once a room is assigned."));
    }

    if ($action === 'reject') {
        $reason = str_input($_POST, 'reason');
        $db->prepare("UPDATE tenants SET approval_status = 'Rejected', rejection_reason = ? WHERE tenant_id = ?")
           ->execute([$reason ?: null, $tenantId]);
        flash('success', 'Application rejected.');
        $body = "Hi {$tenant['first_name']}, your tenant application was not approved."
              . ($reason !== '' ? " Reason given: {$reason}" : '')
              . ' If you have questions or believe this was a mistake, please contact the dorm office.';
        send_email_alert($tenant['email'], $tenant['first_name'], 'Update on your tenant application', email_template('Application update', $body));
    }

    if ($action === 'reconsider') {
        $db->prepare("UPDATE tenants SET approval_status = 'Pending', rejection_reason = NULL WHERE tenant_id = ?")->execute([$tenantId]);
        flash('success', $tenant['first_name'] . '\'s application has been moved back to Pending for another look.');
    }

    if ($action === 'checkin') {
        if (!$tenant['room_id']) {
            flash('error', 'Assign a room to this tenant before checking them in.');
        } else {
            $db->prepare("UPDATE tenants SET status = 'Active', checkin_date = CURDATE(), key_returned = FALSE WHERE tenant_id = ?")->execute([$tenantId]);
            flash('success', $tenant['first_name'] . ' checked in.');
        }
    }

    if ($action === 'checkout') {
        $keysReturned = isset($_POST['keys_returned']) ? 1 : 0;
        $db->beginTransaction();
        try {
            $db->prepare("UPDATE tenants SET status = 'Checked Out', checkout_date = CURDATE(), key_returned = ? WHERE tenant_id = ?")->execute([$keysReturned, $tenantId]);
            if ($tenant['room_id']) {
                $db->prepare("UPDATE dorm_rooms SET status = 'Available' WHERE room_id = ?")->execute([$tenant['room_id']]);
            }
            // Close out their lease: 'Expired' if the term had run its course, 'Terminated' if they left early.
            $db->prepare("
                UPDATE contracts SET contract_status = IF(contract_end <= CURDATE(), 'Expired', 'Terminated')
                WHERE tenant_id = ? AND contract_status IN ('Active','Expiring Soon')
            ")->execute([$tenantId]);
            $db->commit();
            flash('success', $tenant['first_name'] . ' checked out. Their room is now available again.');
        } catch (Exception $e) {
            $db->rollBack();
            flash('error', 'Check-out failed. Please try again.');
        }
    }

    if ($action === 'evict') {
        $db->beginTransaction();
        try {
            $db->prepare("UPDATE tenants SET status = 'Evicted' WHERE tenant_id = ?")->execute([$tenantId]);
            if ($tenant['room_id']) {
                $db->prepare("UPDATE dorm_rooms SET status = 'Available' WHERE room_id = ?")->execute([$tenant['room_id']]);
            }
            // Early/forced end of tenancy — the lease no longer runs its natural course.
            $db->prepare("UPDATE contracts SET contract_status = 'Terminated' WHERE tenant_id = ? AND contract_status IN ('Active','Expiring Soon')")->execute([$tenantId]);
            $db->commit();
            flash('success', $tenant['first_name'] . ' marked as evicted.');
        } catch (Exception $e) {
            $db->rollBack();
            flash('error', 'Update failed. Please try again.');
        }
    }

    if ($action === 'mark_key_returned') {
        $db->prepare("UPDATE tenants SET key_returned = TRUE WHERE tenant_id = ?")->execute([$tenantId]);
        flash('success', 'Key return recorded for ' . $tenant['first_name'] . '.');
    }

    redirect($selfPath);
}
