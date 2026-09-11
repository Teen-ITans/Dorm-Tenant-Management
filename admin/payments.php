<?php
require_once __DIR__ . '/../config/app.php';
require_role('admin');

$db = get_db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';

    if ($action === 'create_contract') {
        $tenantId = (int) ($_POST['tenant_id'] ?? 0);
        $rent     = (float) ($_POST['monthly_rent'] ?? 0);
        $deposit  = (float) ($_POST['security_deposit'] ?? 0);
        $start    = $_POST['contract_start'] ?? '';
        $end      = $_POST['contract_end'] ?? '';

        $t = $db->prepare('SELECT room_id FROM tenants WHERE tenant_id = ?');
        $t->execute([$tenantId]);
        $t = $t->fetch();

        $existing = $db->prepare("SELECT COUNT(*) c FROM contracts WHERE tenant_id = ? AND contract_status IN ('Active','Expiring Soon')");
        $existing->execute([$tenantId]);
        $hasActiveContract = (int) $existing->fetch()['c'] > 0;

        if (!$t || !$t['room_id']) {
            flash('error', 'This tenant needs a room assigned (Property Management) before you can create a contract.');
        } elseif ($hasActiveContract) {
            flash('error', 'This tenant already has an active contract. End it first if you need to replace it.');
        } elseif ($rent <= 0 || !$start || !$end || $end <= $start) {
            flash('error', 'Please provide a valid rent amount and date range.');
        } else {
            try {
                $file = handle_upload('contract_file', 'contracts', ['pdf']);
                $db->prepare('INSERT INTO contracts (tenant_id, room_id, monthly_rent, security_deposit, contract_start, contract_end, contract_file) VALUES (?,?,?,?,?,?,?)')
                   ->execute([$tenantId, $t['room_id'], $rent, $deposit, $start, $end, $file]);
                flash('success', 'Contract created.');
            } catch (RuntimeException $e) {
                flash('error', $e->getMessage());
            }
        }
    }

    if ($action === 'record_payment') {
        $contractId = (int) ($_POST['contract_id'] ?? 0);
        $tenantId   = (int) ($_POST['tenant_id'] ?? 0);
        $amount     = (float) ($_POST['payment_amount'] ?? 0);
        $forMonth   = str_input($_POST, 'payment_for_month');
        $method     = str_input($_POST, 'payment_method');

        $validPair = $db->prepare('SELECT contract_id FROM contracts WHERE contract_id = ? AND tenant_id = ?');
        $validPair->execute([$contractId, $tenantId]);

        if ($amount <= 0 || $forMonth === '') {
            flash('error', 'Please provide an amount and billing month.');
        } elseif (!$validPair->fetch()) {
            flash('error', 'That contract could not be found for this tenant — it may have changed since the page loaded. Please refresh and try again.');
        } else {
            $db->prepare('INSERT INTO payments (contract_id, tenant_id, payment_amount, payment_for_month, payment_date, due_date, payment_status, payment_method) VALUES (?,?,?,?,CURDATE(),CURDATE(),"Paid",?)')
               ->execute([$contractId, $tenantId, $amount, $forMonth, $method ?: 'Cash']);
            flash('success', 'Payment recorded.');
        }
    }

    if ($action === 'verify_payment') {
        $paymentId = (int) ($_POST['payment_id'] ?? 0);
        $newStatus = $_POST['new_status'] ?? 'Paid';
        $db->prepare('UPDATE payments SET payment_status = ?, payment_date = IF(? = "Paid", CURDATE(), payment_date) WHERE payment_id = ?')
           ->execute([$newStatus, $newStatus, $paymentId]);
        flash('success', 'Payment marked as ' . $newStatus . '.');
    }

    redirect('/admin/payments.php');
}

$paidTotal = (float) $db->query("SELECT COALESCE(SUM(payment_amount),0) t FROM payments WHERE payment_status='Paid' AND MONTH(payment_date)=MONTH(CURDATE())")->fetch()['t'];
$pending   = $db->query("SELECT COUNT(*) c, COALESCE(SUM(payment_amount),0) t FROM payments WHERE payment_status='Pending'")->fetch();
$overdue   = $db->query("SELECT COUNT(*) c, COALESCE(SUM(payment_amount),0) t FROM payments WHERE payment_status='Overdue'")->fetch();

$paymentsResult = paginate(
    $db,
    "SELECT p.*, u.first_name, u.last_name, r.room_number
     FROM payments p
     JOIN tenants t ON t.tenant_id = p.tenant_id
     JOIN users u ON u.user_id = t.user_id
     LEFT JOIN dorm_rooms r ON r.room_id = t.room_id
     ORDER BY FIELD(p.payment_status,'Pending','Overdue','Paid'), p.due_date DESC",
    "SELECT COUNT(*) c FROM payments",
    [],
    15
);
$payments = $paymentsResult['rows'];

// Contracts list is capped rather than fully paginated — it grows far
// slower than payments (roughly one row per tenancy, not per month),
// so a generous limit covers realistic use without a second, separate
// pagination control competing with the payments list above for the
// same ?page= query param.
$contracts = $db->query("
    SELECT c.*, u.first_name, u.last_name, r.room_number
    FROM contracts c
    JOIN tenants t ON t.tenant_id = c.tenant_id
    JOIN users u ON u.user_id = t.user_id
    JOIN dorm_rooms r ON r.room_id = c.room_id
    ORDER BY c.contract_end ASC
    LIMIT 30
")->fetchAll();

// Tenants with an active room but no *active* contract yet
$contractableTenants = $db->query("
    SELECT t.tenant_id, u.first_name, u.last_name, r.room_number, r.monthly_rate
    FROM tenants t
    JOIN users u ON u.user_id = t.user_id
    JOIN dorm_rooms r ON r.room_id = t.room_id
    WHERE t.room_id IS NOT NULL
      AND t.tenant_id NOT IN (SELECT tenant_id FROM contracts WHERE contract_status = 'Active')
    ORDER BY u.first_name
")->fetchAll();

$activeContracts = array_filter($contracts, fn($c) => $c['contract_status'] !== 'Terminated' && $c['contract_status'] !== 'Expired');

$pageTitle = 'Payment & Contract Management';
include __DIR__ . '/../includes/header.php';
?>
<div class="page-header">
  <div><h1>Track Payments &amp; Contracts</h1><p class="text-muted">Monitor payment status and manage tenant contracts.</p></div>
  <div>
    <button type="button" class="btn btn-maroon" data-bs-toggle="modal" data-bs-target="#paymentModal">+ Record Payment</button>
  </div>
</div>

<div class="stat-grid stat-grid-3">
  <div class="stat-card"><div class="stat-card-body"><div class="stat-label">Collected This Month</div><div class="stat-value text-success"><?= peso($paidTotal) ?></div></div><div class="stat-icon stat-icon-outline"><i class="bi bi-check-lg"></i></div></div>
  <div class="stat-card"><div class="stat-card-body"><div class="stat-label">Pending</div><div class="stat-value"><?= $pending['c'] ?></div><div class="stat-sub"><?= peso($pending['t']) ?></div></div><div class="stat-icon stat-icon-outline"><i class="bi bi-clock-fill"></i></div></div>
  <div class="stat-card"><div class="stat-card-body"><div class="stat-label">Overdue</div><div class="stat-value text-danger"><?= $overdue['c'] ?></div><div class="stat-sub"><?= peso($overdue['t']) ?></div></div><div class="stat-icon stat-icon-outline">!</div></div>
</div>

<div class="row g-4 mt-1">
  <div class="col-lg-7">
    <div class="panel" id="payments">
      <div class="panel-header"><h2>Payment Status</h2></div>
      <div class="table-responsive">
        <table class="table app-table align-middle">
          <thead><tr><th>Tenant</th><th>Month</th><th>Amount</th><th>Status</th><th class="text-end">Actions</th></tr></thead>
          <tbody>
          <?php if (!$payments): ?><tr><td colspan="5" class="text-center text-muted py-4">No payments recorded yet.</td></tr><?php endif; ?>
          <?php foreach ($payments as $p): ?>
            <tr>
              <td><?= clean($p['first_name'] . ' ' . $p['last_name']) ?><div class="text-muted small"><?= $p['room_number'] ? 'Room ' . clean($p['room_number']) : '' ?></div></td>
              <td class="small"><?= clean($p['payment_for_month'] ?: '—') ?></td>
              <td><?= peso($p['payment_amount']) ?></td>
              <td><span class="badge badge-<?= status_badge_class($p['payment_status']) ?>"><?= clean($p['payment_status']) ?></span></td>
              <td class="text-end">
                <?php if ($p['payment_status'] !== 'Paid'): ?>
                  <form method="post" class="d-inline"><?= csrf_field() ?><input type="hidden" name="action" value="verify_payment"><input type="hidden" name="payment_id" value="<?= $p['payment_id'] ?>"><input type="hidden" name="new_status" value="Paid"><button class="btn btn-sm btn-maroon">Mark Paid</button></form>
                <?php else: ?>
                  <span class="text-muted small"><?= clean(date('n/j/Y', strtotime($p['payment_date']))) ?></span>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?= pagination_links($paymentsResult['page'], $paymentsResult['totalPages']) ?>
    </div>
  </div>

  <div class="col-lg-5">
    <div class="panel" id="contracts">
      <div class="panel-header"><h2>Contracts</h2><button type="button" class="btn btn-sm btn-maroon" data-bs-toggle="modal" data-bs-target="#contractModal"><i class="bi bi-upload"></i> Upload</button></div>
      <div class="contract-list">
        <?php if (!$contracts): ?><p class="text-muted py-3">No contracts yet.</p><?php endif; ?>
        <?php foreach ($contracts as $c):
          $daysLeft = days_until($c['contract_end']);
          $isExpiring = $c['contract_status'] === 'Active' && $daysLeft <= 30;
        ?>
          <div class="contract-item">
            <div>
              <strong><?= clean($c['first_name'] . ' ' . $c['last_name']) ?></strong>
              <div class="text-muted small">Room <?= clean($c['room_number']) ?> · <?= clean(date('M j, Y', strtotime($c['contract_start']))) ?> – <?= clean(date('M j, Y', strtotime($c['contract_end']))) ?></div>
              <?php if ($isExpiring): ?><div class="text-danger small"><i class="bi bi-exclamation-triangle-fill"></i> Expires in <?= $daysLeft ?> day<?= $daysLeft === 1 ? '' : 's' ?></div><?php endif; ?>
            </div>
            <div class="text-end">
              <span class="badge badge-<?= status_badge_class($isExpiring ? 'Expiring Soon' : $c['contract_status']) ?>"><?= $isExpiring ? 'Expiring Soon' : clean($c['contract_status']) ?></span>
              <?php if ($c['contract_file']): ?><div><a href="<?= BASE_URL . '/' . clean($c['contract_file']) ?>" target="_blank" class="small"><i class="bi bi-file-earmark-text"></i> File</a></div><?php endif; ?>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
</div>

<!-- New Contract Modal -->
<div class="modal fade" id="contractModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="post" enctype="multipart/form-data">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="create_contract">
        <div class="modal-header"><h5 class="modal-title">Upload Contract</h5><button class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
          <?php if (!$contractableTenants): ?>
            <p class="text-muted">Every room-assigned tenant already has an active contract. Assign a tenant to a room first in Property Management.</p>
          <?php else: ?>
            <label class="form-label">Tenant</label>
            <select class="form-select mb-3" name="tenant_id" id="contract_tenant_select" required>
              <option value="">Choose…</option>
              <?php foreach ($contractableTenants as $t): ?>
                <option value="<?= $t['tenant_id'] ?>" data-rate="<?= $t['monthly_rate'] ?>"><?= clean($t['first_name'] . ' ' . $t['last_name']) ?> — Room <?= clean($t['room_number']) ?></option>
              <?php endforeach; ?>
            </select>
            <div class="row g-3">
              <div class="col-md-6"><label class="form-label">Monthly Rent (₱)</label><input type="number" step="0.01" min="0" class="form-control" name="monthly_rent" id="contract_rent" required></div>
              <div class="col-md-6"><label class="form-label">Security Deposit (₱)</label><input type="number" step="0.01" min="0" class="form-control" name="security_deposit"></div>
            </div>
            <div class="row g-3 mt-0">
              <div class="col-md-6"><label class="form-label">Start Date</label><input type="date" class="form-control" name="contract_start" required></div>
              <div class="col-md-6"><label class="form-label">End Date</label><input type="date" class="form-control" name="contract_end" required></div>
            </div>
            <div class="mb-1 mt-3">
              <label class="form-label">Contract File (PDF, optional)</label>
              <label class="dropzone d-block">
                <span class="dz-icon"><i class="bi bi-upload"></i></span>
                <div>Click to upload</div>
                <div class="dz-hint">PDF only, max 10MB</div>
                <input type="file" name="contract_file" accept="application/pdf">
              </label>
            </div>
          <?php endif; ?>
        </div>
        <div class="modal-footer"><button class="btn btn-light" data-bs-dismiss="modal" type="button">Cancel</button><?php if ($contractableTenants): ?><button class="btn btn-maroon">Create Contract</button><?php endif; ?></div>
      </form>
    </div>
  </div>
</div>

<!-- Record Payment Modal -->
<div class="modal fade" id="paymentModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="record_payment">
        <div class="modal-header"><h5 class="modal-title">Record Payment</h5><button class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
          <?php if (!$activeContracts): ?>
            <p class="text-muted">No active contracts yet — create one first.</p>
          <?php else: ?>
            <label class="form-label">Contract</label>
            <select class="form-select mb-3" name="contract_id" id="payment_contract_select" required>
              <option value="">Choose…</option>
              <?php foreach ($activeContracts as $c): ?>
                <option value="<?= $c['contract_id'] ?>" data-tenant="<?= $c['tenant_id'] ?>" data-rent="<?= $c['monthly_rent'] ?>"><?= clean($c['first_name'] . ' ' . $c['last_name']) ?> — Room <?= clean($c['room_number']) ?></option>
              <?php endforeach; ?>
            </select>
            <input type="hidden" name="tenant_id" id="payment_tenant_id">
            <div class="row g-3">
              <div class="col-md-6"><label class="form-label">Amount (₱)</label><input type="number" step="0.01" min="0" class="form-control" name="payment_amount" id="payment_amount" required></div>
              <div class="col-md-6"><label class="form-label">For Month</label><input type="text" class="form-control" name="payment_for_month" placeholder="e.g. June 2026" required></div>
            </div>
            <div class="mb-1 mt-3">
              <label class="form-label">Method</label>
              <select class="form-select" name="payment_method">
                <option>Cash</option><option>GCash</option><option>Bank Transfer</option><option>PayMaya</option><option>Card</option>
              </select>
            </div>
          <?php endif; ?>
        </div>
        <div class="modal-footer"><button class="btn btn-light" data-bs-dismiss="modal" type="button">Cancel</button><?php if ($activeContracts): ?><button class="btn btn-maroon">Record Payment</button><?php endif; ?></div>
      </form>
    </div>
  </div>
</div>

<?php
$extraScripts = "<script>
document.getElementById('contract_tenant_select')?.addEventListener('change', function() {
  const rate = this.selectedOptions[0]?.dataset.rate || '';
  document.getElementById('contract_rent').value = rate;
});
document.getElementById('payment_contract_select')?.addEventListener('change', function() {
  document.getElementById('payment_tenant_id').value = this.selectedOptions[0]?.dataset.tenant || '';
  document.getElementById('payment_amount').value = this.selectedOptions[0]?.dataset.rent || '';
});
</script>";
include __DIR__ . '/../includes/footer.php';
?>
