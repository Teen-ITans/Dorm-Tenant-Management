<?php
require_once __DIR__ . '/../config/app.php';
require_role('tenant');

$db = get_db();
$tenant = current_tenant();

if (!$tenant || $tenant['approval_status'] !== 'Approved') {
    redirect('/tenant/dashboard.php');
}

$room = null;
if ($tenant['room_id']) {
    $stmt = $db->prepare('SELECT * FROM dorm_rooms WHERE room_id = ?');
    $stmt->execute([$tenant['room_id']]);
    $room = $stmt->fetch();
}

$contract = null;
if ($room) {
    $stmt = $db->prepare("SELECT * FROM contracts WHERE tenant_id = ? ORDER BY contract_end DESC LIMIT 1");
    $stmt->execute([$tenant['tenant_id']]);
    $contract = $stmt->fetch();
}

$pageTitle = 'My Room & Contract';
include __DIR__ . '/../includes/header.php';
?>
<div class="page-header"><div><h1>My Room &amp; Contract</h1></div></div>

<?php if (!$room): ?>
  <div class="alert alert-info">No room has been assigned to you yet.</div>
<?php else: ?>
<div class="row g-4">
  <div class="col-md-5">
    <div class="room-detail-card">
      <div class="d-flex justify-content-between align-items-start">
        <div>
          <h2 class="mb-0">Room <?= clean($room['room_number']) ?></h2>
          <div class="opacity-75"><?= clean($room['room_type']) ?> · Floor <?= (int) $room['floor_number'] ?></div>
        </div>
        <span class="room-icon"><i class="bi bi-house-door-fill"></i></span>
      </div>
      <div class="row mt-4 g-3">
        <div class="col-6"><div class="opacity-75 small">Capacity</div><strong><?= (int) $room['capacity'] ?> tenant(s)</strong></div>
        <div class="col-6"><div class="opacity-75 small">Monthly Rate</div><strong><?= peso($room['monthly_rate']) ?></strong></div>
        <div class="col-6"><div class="opacity-75 small">Move-in</div><strong><?= $tenant['checkin_date'] ? clean(date('M j, Y', strtotime($tenant['checkin_date']))) : 'Not checked in yet' ?></strong></div>
        <div class="col-6"><div class="opacity-75 small">Status</div><strong><?= clean($tenant['status']) ?></strong></div>
      </div>
    </div>
  </div>
  <div class="col-md-7">
    <div class="panel">
      <div class="panel-header"><h2>Contract Information</h2></div>
      <?php if (!$contract): ?>
        <p class="text-muted py-3">No contract has been created for you yet — check with the admin office.</p>
      <?php else: $daysLeft = days_until($contract['contract_end']); ?>
        <div class="detail-row"><span>Contract Period</span><strong><?= clean(date('M j, Y', strtotime($contract['contract_start']))) ?> – <?= clean(date('M j, Y', strtotime($contract['contract_end']))) ?></strong></div>
        <div class="detail-row"><span>Monthly Rent</span><strong><?= peso($contract['monthly_rent']) ?></strong></div>
        <div class="detail-row"><span>Security Deposit</span><strong><?= peso($contract['security_deposit']) ?></strong></div>
        <div class="detail-row"><span>Contract Status</span><span class="badge badge-<?= status_badge_class($contract['contract_status']) ?>"><?= clean($contract['contract_status']) ?></span></div>
        <div class="detail-row"><span>Days Remaining</span><strong class="<?= $daysLeft <= 14 ? 'text-danger' : '' ?>"><?= $daysLeft ?> days</strong></div>
        <?php if ($daysLeft <= 30): ?>
          <div class="mt-3 p-3 rounded-3" style="border:1px solid var(--border);">
            <strong>⏰ Contract Expiring Soon</strong>
            <p class="text-muted small mb-0">Your contract expires in <?= $daysLeft ?> day<?= $daysLeft === 1 ? '' : 's' ?>. Please visit the office to renew.</p>
          </div>
        <?php endif; ?>
        <?php if ($contract['contract_file']): ?>
          <a href="<?= BASE_URL . '/' . clean($contract['contract_file']) ?>" target="_blank" class="btn btn-outline-maroon btn-sm mt-3"><i class="bi bi-file-earmark-text"></i> View Contract File</a>
        <?php endif; ?>
      <?php endif; ?>
    </div>
  </div>
</div>
<?php endif; ?>
<?php include __DIR__ . '/../includes/footer.php'; ?>
