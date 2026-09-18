<?php
require_once __DIR__ . '/../config/app.php';
require_role('tenant');

$db = get_db();
$tenant = current_tenant();

if (!$tenant || $tenant['approval_status'] !== 'Approved') {
    redirect('/tenant/dashboard.php');
}

$contractStmt = $db->prepare("SELECT * FROM contracts WHERE tenant_id = ? AND contract_status IN ('Active','Expiring Soon') ORDER BY contract_end DESC LIMIT 1");
$contractStmt->execute([$tenant['tenant_id']]);
$contract = $contractStmt->fetch();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    if (!$contract) {
        flash('error', 'You don\'t have an active contract yet, so there\'s nothing to bill a payment against.');
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

$history = $db->prepare('SELECT * FROM payments WHERE tenant_id = ? ORDER BY COALESCE(payment_date, due_date, created_at) DESC');
$history->execute([$tenant['tenant_id']]);
$history = $history->fetchAll();

$pageTitle = 'Payments';
include __DIR__ . '/../includes/header.php';
?>
<div class="page-header"><div><h1>Payments</h1></div></div>

<div class="row g-4">
  <div class="col-lg-5">
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
            <div class="text-muted small"><?= $p['payment_date'] ? clean(date('M j, Y', strtotime($p['payment_date']))) : 'Awaiting verification' ?><?= $p['payment_method'] ? ' · ' . clean($p['payment_method']) : '' ?></div>
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
$extraScripts = "<script>
const methodLabels = { GCash: 'GCash Reference Number', 'Bank Transfer': 'Bank Transfer Reference Number', PayMaya: 'PayMaya Reference Number', Cash: 'Receipt / OR Number', Card: 'Transaction Reference Number' };
const methodSelect = document.getElementById('paymentMethod');
if (methodSelect) {
  methodSelect.addEventListener('change', function () {
    document.getElementById('referenceLabel').textContent = methodLabels[this.value] || 'Reference Number';
  });
}
</script>";
include __DIR__ . '/../includes/footer.php';
?>
