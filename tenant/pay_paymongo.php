<?php
require_once __DIR__ . '/../config/app.php';
require_role('tenant');

$db = get_db();
$tenant = current_tenant();

if (!$tenant || $tenant['approval_status'] !== 'Approved') {
    redirect('/tenant/dashboard.php');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('/tenant/payments.php');
}

csrf_verify();

$contractStmt = $db->prepare("SELECT * FROM contracts WHERE tenant_id = ? AND contract_status IN ('Active','Expiring Soon') ORDER BY contract_end DESC LIMIT 1");
$contractStmt->execute([$tenant['tenant_id']]);
$contract = $contractStmt->fetch();

$month  = str_input($_POST, 'payment_for_month');
$amount = (float) ($_POST['payment_amount'] ?? 0);

if (!$contract) {
    flash('error', 'You don\'t have an active contract yet, so there\'s nothing to pay against.');
    redirect('/tenant/payments.php');
}
if ($month === '' || $amount <= 0) {
    flash('error', 'Please provide the billing month and a valid amount.');
    redirect('/tenant/payments.php');
}

// Recorded as Pending immediately, same as a manually-declared payment —
// paymongo_return.php flips it to Paid only after PayMongo itself
// confirms the checkout session was actually paid.
$db->prepare('INSERT INTO payments (contract_id, tenant_id, payment_amount, payment_for_month, due_date, payment_status, payment_method) VALUES (?,?,?,?,CURDATE(),"Pending","PayMongo")')
   ->execute([$contract['contract_id'], $tenant['tenant_id'], $amount, $month]);
$paymentId = (int) $db->lastInsertId();

try {
    $session = paymongo_create_checkout_session(
        $amount,
        'Rent payment - ' . $month,
        absolute_url('/tenant/paymongo_return.php?payment_id=' . $paymentId . '&result=success'),
        absolute_url('/tenant/paymongo_return.php?payment_id=' . $paymentId . '&result=cancel'),
        trim(($_SESSION['first_name'] ?? '') . ' ' . ($_SESSION['last_name'] ?? '')),
        $_SESSION['email'] ?? ''
    );

    $db->prepare('UPDATE payments SET paymongo_checkout_id = ? WHERE payment_id = ?')
       ->execute([$session['id'], $paymentId]);

    header('Location: ' . $session['attributes']['checkout_url']);
    exit;
} catch (RuntimeException $e) {
    // Nothing was actually charged — don't leave a dangling Pending row behind.
    $db->prepare('DELETE FROM payments WHERE payment_id = ?')->execute([$paymentId]);
    flash('error', $e->getMessage());
    redirect('/tenant/payments.php');
}
