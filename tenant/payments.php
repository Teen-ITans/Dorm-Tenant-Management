<?php
require_once __DIR__ . '/../config/app.php';
require_role('tenant');

$db = get_db();
$tenant = current_tenant();

if (!$tenant || $tenant['approval_status'] !== 'Approved') {
    redirect('/tenant/dashboard.php');
}

<<<<<<< HEAD
$contractStmt = $db->prepare("SELECT * FROM contracts WHERE tenant_id = ? AND contract_status IN ('Active','Expiring Soon') ORDER BY contract_end DESC LIMIT 1");
$contractStmt->execute([$tenant['tenant_id']]);
$contract = $contractStmt->fetch();
=======
refresh_contract_statuses($db);

// An expired lease still gets to settle its final month's rent — only a
// terminated one is closed to new payments.
$contract = tenant_current_contract($db, (int) $tenant['tenant_id']);
$contractExpired = contract_is_expired($contract);
$monthOptions = billing_month_options(3, 1);
>>>>>>> origin/james

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    if (!$contract) {
        flash('error', 'You don\'t have an active contract yet, so there\'s nothing to bill a payment against.');
<<<<<<< HEAD
    } else {
        $month  = str_input($_POST, 'payment_for_month');
        $amount = (float) ($_POST['payment_amount'] ?? 0);
        $method = str_input($_POST, 'payment_method', 'GCash');
        $ref    = str_input($_POST, 'reference_no');

        if ($month === '' || $amount <= 0) {
            flash('error', 'Please provide the billing month and a valid amount.');
        } else {
            try {
                $receipt = handle_upload('receipt_file', 'receipts', ['jpg', 'jpeg', 'png', 'pdf']);
                $db->prepare('INSERT INTO payments (contract_id, tenant_id, payment_amount, payment_for_month, due_date, payment_status, payment_method, reference_no, receipt_file) VALUES (?,?,?,?,CURDATE(),"Pending",?,?,?)')
                   ->execute([$contract['contract_id'], $tenant['tenant_id'], $amount, $month, $method, $ref ?: null, $receipt]);
                flash('success', 'Payment submitted! It will show as Pending until the office verifies it.');
            } catch (RuntimeException $e) {
                flash('error', $e->getMessage());
            }
        }
    }
    redirect('/tenant/payments.php');
}

=======
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

    $already = $db->prepare("SELECT payment_id FROM payments
                             WHERE tenant_id = ? AND LOWER(TRIM(COALESCE(payment_for_month,''))) = LOWER(?)
                               AND payment_status IN ('Paid','Pending') LIMIT 1");
    $already->execute([$tenant['tenant_id'], $month]);
    if ($already->fetch()) {
        flash('error', $month . ' is already paid or has a payment being confirmed — check your payment history below.');
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

    try {
        $checkout = paymongo_create_gcash_checkout(
            $amount,
            'Rent — ' . $month . ' (Room ' . ($tenant['room_id'] ?? '') . ')',
            'PAY-' . $paymentId,
            ['payment_id' => $paymentId, 'tenant_id' => $tenant['tenant_id']],
            APP_URL . '/tenant/payments.php?gcash=success',
            APP_URL . '/tenant/payments.php?gcash=cancelled'
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
    flash('error', 'Payment cancelled. No amount was charged.');
}

$rent = tenant_rent_status($db, (int) $tenant['tenant_id'], $contract);

>>>>>>> origin/james
$history = $db->prepare('SELECT * FROM payments WHERE tenant_id = ? ORDER BY COALESCE(payment_date, due_date, created_at) DESC');
$history->execute([$tenant['tenant_id']]);
$history = $history->fetchAll();

$pageTitle = 'Payments';
include __DIR__ . '/../includes/header.php';
?>
<div class="page-header"><div><h1>Payments</h1></div></div>

<div class="row g-4">
  <div class="col-lg-5">
<<<<<<< HEAD
    <?php if ($contract): ?>
    <div class="panel">
      <div class="panel-header">
        <h2>Pay Online</h2>
        <span class="badge badge-outline">Test Mode</span>
      </div>
      <?php if (!paymongo_configured()): ?>
        <p class="text-muted small py-2">Online payment isn't set up yet — use "Submit Payment Manually" instead.</p>
      <?php else: ?>
        <p class="text-muted small">Pay instantly with GCash, Maya, or a card via PayMongo (test mode — no real money moves).</p>
        <form method="post" action="<?= BASE_URL ?>/tenant/pay_paymongo.php">
          <?= csrf_field() ?>
          <div class="mb-3">
            <label class="form-label">Payment Month</label>
            <input type="text" class="form-control" name="payment_for_month" placeholder="e.g. June 2026" required>
          </div>
          <div class="mb-3">
            <label class="form-label">Amount (₱)</label>
            <input type="number" step="0.01" min="0" class="form-control" name="payment_amount" value="<?= $contract['monthly_rent'] ?>" required>
          </div>
          <button class="btn btn-maroon w-100"><i class="bi bi-credit-card-fill"></i> Pay with PayMongo</button>
        </form>
      <?php endif; ?>
    </div>
    <?php endif; ?>

    <div class="panel mt-2">
      <div class="panel-header"><h2>Submit Payment Manually</h2></div>
      <?php if (!$contract): ?>
        <p class="text-muted py-3">You don't have an active contract yet, so there's nothing to pay against right now.</p>
      <?php else: ?>
        <p class="text-muted small">Already paid in cash, bank transfer, or another way? Log it here for the office to verify.</p>
        <form method="post" enctype="multipart/form-data">
          <?= csrf_field() ?>
          <div class="mb-3">
            <label class="form-label">Payment Month</label>
            <input type="text" class="form-control" name="payment_for_month" placeholder="e.g. June 2026" required>
          </div>
          <div class="mb-3">
            <label class="form-label">Amount (₱)</label>
            <input type="number" step="0.01" min="0" class="form-control" name="payment_amount" value="<?= $contract['monthly_rent'] ?>" required>
          </div>
          <div class="mb-3">
            <label class="form-label">Payment Method</label>
            <select class="form-select" name="payment_method" id="paymentMethod">
              <option>GCash</option>
              <option>Bank Transfer</option>
              <option>PayMaya</option>
              <option>Cash</option>
              <option>Card</option>
            </select>
          </div>
          <div class="mb-3">
            <label class="form-label" id="referenceLabel">GCash Reference Number</label>
            <input type="text" class="form-control" name="reference_no" id="referenceInput" placeholder="e.g. 1234 5678 9012">
          </div>
          <div class="mb-3">
            <label class="form-label">Upload Receipt/Reference</label>
            <label class="dropzone d-block">
              <span class="dz-icon"><i class="bi bi-upload"></i></span>
              <div>Click to upload</div>
              <div class="dz-hint">PNG, JPG, or PDF</div>
              <input type="file" name="receipt_file" accept="image/*,application/pdf">
            </label>
          </div>
          <button class="btn btn-outline-maroon w-100">Submit Payment</button>
=======
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
>>>>>>> origin/james
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
<<<<<<< HEAD
            <div class="text-muted small"><?= $p['payment_date'] ? clean(date('M j, Y', strtotime($p['payment_date']))) : 'Awaiting verification' ?><?= $p['payment_method'] ? ' · ' . clean($p['payment_method']) : '' ?></div>
=======
            <div class="text-muted small"><?= $p['payment_date'] ? clean(date('M j, Y', strtotime($p['payment_date']))) : 'Awaiting GCash confirmation' ?><?= $p['payment_method'] ? ' · ' . clean($p['payment_method']) : '' ?></div>
>>>>>>> origin/james
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
<<<<<<< HEAD
$extraScripts = "<script>
const methodLabels = { GCash: 'GCash Reference Number', 'Bank Transfer': 'Bank Transfer Reference Number', PayMaya: 'PayMaya Reference Number', Cash: 'Receipt / OR Number', Card: 'Transaction Reference Number' };
const methodSelect = document.getElementById('paymentMethod');
if (methodSelect) {
  methodSelect.addEventListener('change', function () {
    document.getElementById('referenceLabel').textContent = methodLabels[this.value] || 'Reference Number';
  });
}
</script>";
=======
>>>>>>> origin/james
include __DIR__ . '/../includes/footer.php';
?>
