<?php
require_once __DIR__ . '/../config/app.php';
require_role('tenant');

$db = get_db();
$tenant = current_tenant();

$paymentId = (int) ($_GET['payment_id'] ?? 0);
$result = $_GET['result'] ?? '';

$stmt = $db->prepare('SELECT * FROM payments WHERE payment_id = ? AND tenant_id = ?');
$stmt->execute([$paymentId, $tenant['tenant_id'] ?? 0]);
$payment = $stmt->fetch();

if (!$payment || !$payment['paymongo_checkout_id']) {
    flash('error', 'That payment could not be found.');
    redirect('/tenant/payments.php');
}

if ($result === 'cancel') {
    // Nothing was charged — don't clutter payment history with an abandoned attempt.
    if ($payment['payment_status'] === 'Pending') {
        $db->prepare('DELETE FROM payments WHERE payment_id = ?')->execute([$paymentId]);
    }
    flash('error', 'Payment cancelled.');
    redirect('/tenant/payments.php');
}

// Never trust the redirect alone — ask PayMongo directly whether this
// checkout session actually completed before marking the row Paid.
try {
    $session = paymongo_get_checkout_session($payment['paymongo_checkout_id']);
    $isPaid = ($session['attributes']['payment_status'] ?? '') === 'paid';

    if ($isPaid && $payment['payment_status'] !== 'Paid') {
        $channel = $session['attributes']['payments'][0]['attributes']['source']['type'] ?? 'online';
        $db->prepare("UPDATE payments SET payment_status = 'Paid', payment_date = CURDATE(), payment_method = ? WHERE payment_id = ?")
           ->execute(['PayMongo (' . ucfirst($channel) . ')', $paymentId]);
        flash('success', 'Payment successful! Thank you.');
    } elseif ($isPaid) {
        flash('success', 'Payment already confirmed.');
    } else {
        flash('error', 'Payment was not completed. If you were charged, please contact the office.');
    }
} catch (RuntimeException $e) {
    flash('error', 'Could not confirm your payment status: ' . $e->getMessage());
}

redirect('/tenant/payments.php');
