<?php
require_once __DIR__ . '/../config/app.php';
require_role('tenant');

$db = get_db();
$tenant = current_tenant();

// ---- Not yet approved / no room -------------------------------------
if (!$tenant || $tenant['approval_status'] !== 'Approved') {
    $pageTitle = 'Home';
    include __DIR__ . '/../includes/header.php';
    if ($tenant && $tenant['approval_status'] === 'Rejected') {
        ?>
        <div class="pending-screen">
          <div class="pending-icon"><i class="bi bi-x-lg"></i></div>
          <h2>Your application wasn't approved</h2>
          <p class="text-muted">
            <?php if ($tenant['rejection_reason']): ?>
              Reason given: <?= clean($tenant['rejection_reason']) ?>
            <?php else: ?>
              No specific reason was given.
            <?php endif; ?>
          </p>
          <p class="text-muted">If you have questions or believe this was a mistake, please contact the dorm office.</p>
        </div>
        <?php
    } else {
        ?>
        <div class="pending-screen">
          <div class="pending-icon">⏳</div>
          <h2>Your application is under review</h2>
          <p class="text-muted">An admin needs to approve your application before your dashboard unlocks. Check back soon — we'll email you once it's approved.</p>
        </div>
        <?php
    }
    include __DIR__ . '/../includes/footer.php';
    exit;
}

$room = null;
if ($tenant['room_id']) {
    $stmt = $db->prepare('SELECT * FROM dorm_rooms WHERE room_id = ?');
    $stmt->execute([$tenant['room_id']]);
    $room = $stmt->fetch();
}

$contract = null;
if ($room) {
    $stmt = $db->prepare("SELECT * FROM contracts WHERE tenant_id = ? AND contract_status IN ('Active','Expiring Soon') ORDER BY contract_end DESC LIMIT 1");
    $stmt->execute([$tenant['tenant_id']]);
    $contract = $stmt->fetch();
}

$recentPayments = $db->prepare('SELECT * FROM payments WHERE tenant_id = ? ORDER BY COALESCE(payment_date, due_date) DESC LIMIT 5');
$recentPayments->execute([$tenant['tenant_id']]);
$recentPayments = $recentPayments->fetchAll();

$notifStmt = $db->prepare("
    SELECT n.* FROM notifications n
    WHERE n.target_type = 'all'
       OR (n.target_type = 'tenant' AND n.target_value = ?)
       OR (n.target_type = 'room' AND FIND_IN_SET(?, REPLACE(n.target_value, ' ', '')))
    ORDER BY n.date_sent DESC LIMIT 4
");
$notifStmt->execute([$tenant['tenant_id'], $room['room_number'] ?? '__none__']);
$announcements = $notifStmt->fetchAll();
$typeTint = ['Announcement' => '', 'Payment Reminder' => 'tint-amber', 'Contract Expiry Alert' => 'tint-blue'];

$nextDue = null;
foreach ($recentPayments as $p) {
    if ($p['payment_status'] !== 'Paid') { $nextDue = $p; break; }
}

$pageTitle = 'Home';
include __DIR__ . '/../includes/header.php';
?>
<h1 class="mb-0">Hello, <?= clean($_SESSION['first_name']) ?>!</h1>
<p class="text-muted">Welcome to your dorm portal.</p>

<?php if ($room): ?>
<div class="room-banner">
  <div class="room-banner-top">
    <div>
      <div class="text-uppercase small opacity-75">Your Room</div>
      <div class="room-banner-number">Room <?= clean($room['room_number']) ?></div>
    </div>
    <?php if ($contract): ?>
      <div class="text-end">
        <div class="text-uppercase small opacity-75">Rent Due</div>
        <div><?= clean(date('F j, Y', strtotime($contract['contract_end']))) ?></div>
      </div>
    <?php endif; ?>
  </div>
  <div class="room-banner-rate-bar"><span>Monthly Rent</span><strong><?= peso($room['monthly_rate']) ?></strong></div>
</div>

<?php if ($contract && days_until($contract['contract_end']) <= 14): ?>
  <div class="alert alert-warning mt-3"><i class="bi bi-exclamation-triangle-fill"></i> Your contract expires in <?= days_until($contract['contract_end']) ?> day(s). Please visit the office to renew.</div>
<?php endif; ?>
<?php if ($nextDue): ?>
  <div class="alert alert-<?= $nextDue['payment_status'] === 'Overdue' ? 'danger' : 'warning' ?> mt-3">
    <i class="bi bi-credit-card-fill"></i> You have a <?= strtolower($nextDue['payment_status']) ?> payment of <?= peso($nextDue['payment_amount']) ?><?= $nextDue['payment_for_month'] ? ' for ' . clean($nextDue['payment_for_month']) : '' ?>.
  </div>
<?php endif; ?>
<?php else: ?>
  <div class="alert alert-info mt-3">You're approved! A room hasn't been assigned to you yet — the admin office will notify you once it is.</div>
<?php endif; ?>

<div class="quick-actions mt-3">
  <a href="<?= BASE_URL ?>/tenant/payments.php" class="quick-action-card">
    <div class="quick-action-icon"><i class="bi bi-credit-card-fill"></i></div><div><strong>Pay Rent</strong><div class="text-muted small">Make a payment</div></div>
  </a>
  <a href="<?= BASE_URL ?>/tenant/maintenance.php" class="quick-action-card">
    <div class="quick-action-icon"><i class="bi bi-tools"></i></div><div><strong>Request Repair</strong><div class="text-muted small">Report an issue</div></div>
  </a>
</div>

<div class="row g-4 mt-1">
  <div class="col-md-6">
    <div class="panel">
      <div class="panel-header"><h2>Recent Payments</h2></div>
      <?php if (!$recentPayments): ?><p class="text-muted py-3">No payments yet.</p><?php endif; ?>
      <?php foreach ($recentPayments as $p): ?>
        <div class="list-row">
          <div><?= clean($p['payment_for_month'] ?: date('M j, Y', strtotime($p['due_date'] ?? $p['created_at']))) ?></div>
          <div class="text-end"><?= peso($p['payment_amount']) ?><br><span class="badge badge-<?= status_badge_class($p['payment_status']) ?>"><?= clean($p['payment_status']) ?></span></div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
  <div class="col-md-6">
    <div class="panel">
      <div class="panel-header"><h2>Latest Announcements</h2></div>
      <?php if (!$announcements): ?><p class="text-muted py-3">No announcements yet.</p><?php endif; ?>
      <?php foreach ($announcements as $n): ?>
        <div class="notification-item <?= $typeTint[$n['type']] ?? '' ?> align-items-start">
          <div class="notification-icon"><i class="bi bi-bell-fill"></i></div>
          <div class="flex-grow-1"><strong><?= clean($n['subject']) ?></strong><div class="text-muted small"><?= clean($n['message']) ?></div></div>
          <div class="text-muted small text-nowrap ms-2"><?= clean(date('M j', strtotime($n['date_sent']))) ?></div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
