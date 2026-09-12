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
    $stmt = $db->prepare('SELECT room_number FROM dorm_rooms WHERE room_id = ?');
    $stmt->execute([$tenant['room_id']]);
    $room = $stmt->fetch();
}

$stmt = $db->prepare("
    SELECT * FROM notifications
    WHERE target_type = 'all'
       OR (target_type = 'tenant' AND target_value = ?)
       OR (target_type = 'room' AND FIND_IN_SET(?, REPLACE(target_value, ' ', '')))
    ORDER BY date_sent DESC
");
$stmt->execute([$tenant['tenant_id'], $room['room_number'] ?? '__none__']);
$notifications = $stmt->fetchAll();

$typeIcon = ['Announcement' => '<i class="bi bi-megaphone-fill"></i>', 'Payment Reminder' => '<i class="bi bi-credit-card-fill"></i>', 'Contract Expiry Alert' => '⏰'];
$typeTint = ['Announcement' => '', 'Payment Reminder' => 'tint-amber', 'Contract Expiry Alert' => 'tint-blue'];

$pageTitle = 'Notifications';
include __DIR__ . '/../includes/header.php';
?>
<div class="page-header"><div><h1>Notifications</h1><p class="text-muted">Announcements, payment reminders, and contract expiry alerts.</p></div></div>

<div class="panel">
  <?php if (!$notifications): ?>
    <p class="text-muted py-4 text-center">Nothing here yet — you're all caught up.</p>
  <?php endif; ?>
  <?php foreach ($notifications as $n): ?>
    <div class="notification-item <?= $typeTint[$n['type']] ?? '' ?>">
      <div class="notification-icon"><?= $typeIcon[$n['type']] ?? '<i class="bi bi-bell-fill"></i>' ?></div>
      <div class="flex-grow-1">
        <strong><?= clean($n['subject']) ?></strong>
        <span class="badge badge-secondary ms-2"><?= clean($n['type']) ?></span>
        <p class="text-muted small mb-1 mt-1"><?= clean($n['message']) ?></p>
        <div class="text-muted small"><?= clean(date('F j, Y g:i A', strtotime($n['date_sent']))) ?></div>
      </div>
    </div>
  <?php endforeach; ?>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
