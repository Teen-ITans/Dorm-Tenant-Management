<?php
require_once __DIR__ . '/../config/app.php';
require_role('tenant');

$db = get_db();
$tenant = current_tenant();

if (!$tenant || $tenant['approval_status'] !== 'Approved') {
    redirect('/tenant/dashboard.php');
}

refresh_contract_statuses($db);

// An expired lease still gets to settle its final month's rent — only a
// terminated one is closed to new payments.
$contract = tenant_current_contract($db, (int) $tenant['tenant_id']);
$contractExpired = contract_is_expired($contract);
$monthOptions = billing_month_options(3, 1);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    if (!$contract) {
        flash('error', 'You don\'t have an active contract yet, so there\'s nothing to bill a payment against.');
        redirect('/tenant/payments.php');
    }

    $month = str_input($_POST, 'payment_for_month');
    if ($month === '') {
        flash('error', 'Please tell us which month this payment is for.');
        redirect('/tenant/payments.php');
    }

    // The month comes from a fixed list, not a free-text box, so every
    // row is stored in the same "June 2026" shape. That's what lets the
    // portal tell reliably whether a given month has been settled.
    if (!in_array($month, $monthOptions, true)) {
        flash('error', 'Please pick one of the billing months listed.');
        redirect('/tenant/payments.php');
    }

    // A month already settled can't be paid twice. A month still
    // showing Pending usually just means an earlier checkout the tenant
    // backed out of, so ask PayMongo what became of it rather than
    // assuming: only a genuine payment stops them paying now.
    $already = $db->prepare("SELECT * FROM payments
                             WHERE tenant_id = ? AND LOWER(TRIM(COALESCE(payment_for_month,''))) = LOWER(?)
                               AND payment_status IN ('Paid','Pending')
                             ORDER BY FIELD(payment_status,'Paid','Pending'), payment_id DESC LIMIT 1");
    $already->execute([$tenant['tenant_id'], $month]);
    $already = $already->fetch() ?: null;

    if ($already && $already['payment_status'] === 'Pending') {
        // Clicking Pay is the tenant saying they're done with whatever
        // checkout came before, so an abandoned one goes now.
        $already = settle_pending_gcash_payment($db, $already);
    }

    if ($already) {
        flash('error', $already['payment_status'] === 'Paid'
            ? $month . ' is already paid — check your payment history below.'
            : 'Your GCash payment for ' . $month . ' is still going through. Give it a moment, then refresh this page.');
        redirect('/tenant/payments.php');
    }

    // Amount is always the contract's monthly rent — not something the
    // tenant can type in — so nobody can check out for ₱1 by editing
    // the form. If you ever need partial/custom amounts, that has to
    // be a deliberate admin action, not a client-controlled field.
    $amount = (float) $contract['monthly_rent'];

    $paymentId = null;
    try {
        $db->beginTransaction();
        $db->prepare('INSERT INTO payments (contract_id, tenant_id, payment_amount, payment_for_month, due_date, payment_status, payment_method) VALUES (?,?,?,?,CURDATE(),"Pending","GCash")')
           ->execute([$contract['contract_id'], $tenant['tenant_id'], $amount, $month]);
        $paymentId = (int) $db->lastInsertId();
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        flash('error', 'Could not start the payment. Please try again.');
        redirect('/tenant/payments.php');
    }

    // Who PayMongo should record as the payer. Without this the
    // checkout page opens with an empty name field, the browser
    // autofills whoever used it last, and every tenant's rent shows up
    // in the dashboard under that person's name.
    $payer = $db->prepare('SELECT first_name, last_name, email, phone FROM users WHERE user_id = ?');
    $payer->execute([$tenant['user_id']]);
    $payer = $payer->fetch() ?: [];

    try {
        $checkout = paymongo_create_gcash_checkout(
            $amount,
            'Rent — ' . $month . ' (Room ' . ($tenant['room_id'] ?? '') . ')',
            'PAY-' . $paymentId,
            ['payment_id' => $paymentId, 'tenant_id' => $tenant['tenant_id']],
            APP_URL . '/tenant/payments.php?gcash=success',
            // The id rides along so backing out of the checkout can
            // clear this exact row instead of guessing at one.
            APP_URL . '/tenant/payments.php?gcash=cancelled&p=' . $paymentId,
            [
                'name'  => trim(($payer['first_name'] ?? '') . ' ' . ($payer['last_name'] ?? '')),
                'email' => $payer['email'] ?? '',
                // PayMongo wants E.164, so the display space comes out.
                'phone' => ph_mobile_e164($payer['phone'] ?? ''),
            ]
        );

        if (empty($checkout['checkout_url']) || empty($checkout['id'])) {
            throw new RuntimeException('PayMongo did not return a checkout link.');
        }

        $db->prepare('UPDATE payments SET paymongo_checkout_id = ? WHERE payment_id = ?')
           ->execute([$checkout['id'], $paymentId]);

        header('Location: ' . $checkout['checkout_url']);
        exit;
    } catch (Throwable $e) {
        // GCash side never got created, or it errored — don't leave a
        // dangling Pending row a tenant can't do anything about.
        $db->prepare("UPDATE payments SET payment_status = 'Failed' WHERE payment_id = ?")->execute([$paymentId]);
        flash('error', 'Could not connect to GCash right now: ' . $e->getMessage());
        redirect('/tenant/payments.php');
    }
}

// Ask PayMongo about anything still Pending before rendering, so coming
// back from GCash usually lands straight on "Paid" rather than a
// holding message.
$justConfirmed = sync_pending_gcash_payments($db, (int) $tenant['tenant_id']);

if (isset($_GET['gcash']) && $_GET['gcash'] === 'success') {
    flash('success', $justConfirmed > 0
        ? 'Payment confirmed — your rent is now marked as Paid. Thank you!'
        : 'Thanks! We\'re confirming your GCash payment now — it will show as Paid here and on your home page automatically, usually within a minute.');
} elseif (isset($_GET['gcash']) && $_GET['gcash'] === 'cancelled') {
    // Backing out of the checkout should leave no trace — otherwise the
    // portal keeps showing rent as "being confirmed" for a payment that
    // never happened. Scoped to this tenant's own Pending rows, so a
    // tampered id can't touch anyone else's payment.
    $cancelled = null;
    $stmt = $db->prepare("SELECT * FROM payments WHERE payment_id = ? AND tenant_id = ? AND payment_status = 'Pending'");
    $stmt->execute([(int) ($_GET['p'] ?? 0), $tenant['tenant_id']]);
    if ($row = $stmt->fetch()) {
        $cancelled = settle_pending_gcash_payment($db, $row);
    }

    if ($cancelled && $cancelled['payment_status'] === 'Paid') {
        flash('success', 'Good news — that payment did go through after all. Your rent is marked as Paid.');
    } else {
        flash('error', 'Payment cancelled. No amount was charged.');
    }
}

$rent = tenant_rent_status($db, (int) $tenant['tenant_id'], $contract);

$history = $db->prepare('SELECT * FROM payments WHERE tenant_id = ? ORDER BY COALESCE(payment_date, due_date, created_at) DESC');
$history->execute([$tenant['tenant_id']]);
$history = $history->fetchAll();

$pageTitle = 'Payments';
include __DIR__ . '/../includes/header.php';
?>
<div class="page-header"><div><h1>Payments</h1></div></div>

<div class="row g-4">
  <div class="col-lg-5">
    <div class="panel">
      <div class="panel-header"><h2>Pay Rent with GCash</h2></div>
      <?php if (!$contract): ?>
        <p class="text-muted py-3">You don't have an active contract yet, so there's nothing to pay against right now.</p>
      <?php else: ?>
        <?php if ($contractExpired): ?>
          <div class="alert alert-warning">
            <i class="bi bi-exclamation-triangle-fill"></i> Your contract expired on <?= clean(date('F j, Y', strtotime($contract['contract_end']))) ?>. You can still settle any rent you owe for it — ask the office to renew from <a href="<?= BASE_URL ?>/tenant/services.php">My Room &amp; Contract</a>.
          </div>
        <?php endif; ?>
        <div class="rent-status-card <?= ['Paid' => 'is-paid', 'Pending' => 'is-pending', 'Overdue' => 'is-due', 'Due' => 'is-due', 'None' => 'is-none'][$rent['state']] ?> mt-0 mb-3">
          <div>
            <div class="rent-status-label"><?= $rent['state'] === 'Paid' ? 'Rent' : 'Rent Due' ?> · <?= clean($rent['month']) ?></div>
            <div class="rent-status-amount"><?= peso($rent['amount']) ?></div>
          </div>
          <span class="rent-status-pill"><span class="rent-status-dot"></span><?= clean($rent['label']) ?></span>
        </div>
        <form method="post">
          <?= csrf_field() ?>
          <div class="mb-3">
            <label class="form-label">Payment Month</label>
            <select class="form-select" name="payment_for_month" required>
              <?php foreach ($monthOptions as $monthOption): ?>
                <option value="<?= clean($monthOption) ?>"<?= $monthOption === current_billing_month() ? ' selected' : '' ?>><?= clean($monthOption) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="mb-3">
            <label class="form-label">Amount</label>
            <input type="text" class="form-control" value="<?= peso($contract['monthly_rent']) ?>" disabled>
            <div class="form-text">Your monthly rent, set on your contract.</div>
          </div>
          <button class="btn btn-maroon w-100">
            <i class="bi bi-phone"></i> Pay with GCash
          </button>
          <p class="text-muted small mt-2 mb-0">You'll be redirected to GCash to complete payment securely. Your payment is confirmed automatically — no need to upload a receipt.</p>
        </form>
      <?php endif; ?>
    </div>
  </div>

  <div class="col-lg-7">
    <div class="panel">
      <div class="panel-header"><h2>Payment History</h2></div>
      <?php if (!$history): ?><p class="text-muted py-3">No payments yet.</p><?php endif; ?>
      <?php foreach ($history as $p): ?>
        <div class="list-row">
          <div>
            <strong><?= clean($p['payment_for_month'] ?: '—') ?></strong>
            <div class="text-muted small"><?= $p['payment_date'] ? clean(date('M j, Y', strtotime($p['payment_date']))) : 'Awaiting GCash confirmation' ?><?= $p['payment_method'] ? ' · ' . clean($p['payment_method']) : '' ?></div>
          </div>
          <div class="text-end">
            <?= peso($p['payment_amount']) ?><br>
            <span class="badge badge-<?= status_badge_class($p['payment_status']) ?>"><?= clean($p['payment_status']) ?></span>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
</div>
<?php
include __DIR__ . '/../includes/footer.php';
?>
