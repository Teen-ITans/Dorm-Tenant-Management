<?php
require_once __DIR__ . '/../config/app.php';
require_role('admin');

$db = get_db();
$selfPath = '/admin/tenant-status.php';
require __DIR__ . '/../includes/tenant_action_handler.php';

// Stat counts reflect ALL approved tenants, not just the current page.
$counts = ['Active' => 0, 'Pending' => 0, 'Evicted' => 0, 'Checked Out' => 0];
$countRows = $db->query("SELECT status, COUNT(*) c FROM tenants WHERE approval_status = 'Approved' GROUP BY status")->fetchAll();
foreach ($countRows as $row) { $counts[$row['status']] = (int) $row['c']; }

$result = paginate(
    $db,
    "SELECT t.*, u.first_name, u.last_name, r.room_number,
            (SELECT MAX(payment_date) FROM payments p WHERE p.tenant_id = t.tenant_id AND p.payment_status='Paid') AS last_payment
     FROM tenants t
     JOIN users u ON u.user_id = t.user_id
     LEFT JOIN dorm_rooms r ON r.room_id = t.room_id
     WHERE t.approval_status = 'Approved'
       AND t.tenant_id NOT IN (SELECT tenant_id FROM dismissed_records WHERE page = 'status')
     ORDER BY FIELD(t.status,'Active','Pending','Evicted','Checked Out'), u.first_name",
    "SELECT COUNT(*) c FROM tenants t WHERE t.approval_status = 'Approved'
       AND t.tenant_id NOT IN (SELECT tenant_id FROM dismissed_records WHERE page = 'status')"
);
$allTenants = $result['rows'];

$pageTitle = 'Track Tenant Status';
include __DIR__ . '/../includes/header.php';
?>
<div class="page-header"><div><h1>Track Tenant Status</h1><p class="text-muted">Monitor tenant status and activity across all properties.</p></div></div>

<div class="stat-grid stat-grid-4">
  <div class="stat-card"><div class="stat-card-body"><div class="stat-label">Active</div><div class="stat-value text-success"><?= $counts['Active'] ?></div></div><div class="stat-icon stat-icon-outline"><i class="bi bi-check-lg"></i></div></div>
  <div class="stat-card"><div class="stat-card-body"><div class="stat-label">Pending Check-in</div><div class="stat-value"><?= $counts['Pending'] ?></div></div><div class="stat-icon stat-icon-outline"><i class="bi bi-clock-fill"></i></div></div>
  <div class="stat-card"><div class="stat-card-body"><div class="stat-label">Checked Out</div><div class="stat-value"><?= $counts['Checked Out'] ?></div></div><div class="stat-icon stat-icon-outline"><i class="bi bi-box-arrow-in-right"></i></div></div>
  <div class="stat-card"><div class="stat-card-body"><div class="stat-label">Evicted</div><div class="stat-value text-danger"><?= $counts['Evicted'] ?></div></div><div class="stat-icon stat-icon-outline"><i class="bi bi-x-lg"></i></div></div>
</div>

<div class="panel mt-2">
  <div class="panel-header">
    <h2>All Tenants</h2>
    <?php if ($allTenants): ?>
    <div class="dropdown">
      <button class="btn btn-sm btn-outline-maroon dropdown-toggle" type="button" data-bs-toggle="dropdown"><i class="bi bi-eraser-fill"></i> Clear</button>
      <ul class="dropdown-menu dropdown-menu-end">
        <li><h6 class="dropdown-header">Clear from this view only</h6></li>
        <?php foreach (['Active', 'Pending', 'Checked Out', 'Evicted'] as $s): ?>
          <li><form method="post" onsubmit="return confirm('Clear <?= clean($s) ?> tenants from this view? They stay in the database for reports.');"><?= csrf_field() ?><input type="hidden" name="action" value="clear_view"><input type="hidden" name="page" value="status"><input type="hidden" name="filter" value="<?= clean($s) ?>"><button class="dropdown-item" type="submit">Clear <?= clean($s) ?> only</button></form></li>
        <?php endforeach; ?>
        <li><hr class="dropdown-divider"></li>
        <li><form method="post" onsubmit="return confirm('Clear ALL tenants from this view? They stay in the database for reports.');"><?= csrf_field() ?><input type="hidden" name="action" value="clear_view"><input type="hidden" name="page" value="status"><input type="hidden" name="filter" value="all"><button class="dropdown-item" type="submit">Clear all</button></form></li>
      </ul>
    </div>
    <?php endif; ?>
  </div>
  <div class="table-responsive">
    <table class="table app-table align-middle">
      <thead><tr><th>Tenant</th><th>Room</th><th>Status</th><th>Contract Period</th><th>Details</th><th class="text-end">Actions</th></tr></thead>
      <tbody>
      <?php if (!$allTenants): ?><tr><td colspan="6" class="text-center text-muted py-4">No approved tenants yet.</td></tr><?php endif; ?>
      <?php foreach ($allTenants as $t):
        if ($t['status'] === 'Active' && $t['last_payment']) {
            $details = 'Last payment: ' . date('n/j/Y', strtotime($t['last_payment']));
        } elseif ($t['status'] === 'Active') {
            $details = 'Awaiting first payment';
        } elseif ($t['status'] === 'Pending') {
            $details = $t['room_id'] ? 'Ready for check-in' : 'Awaiting room assignment';
        } elseif ($t['status'] === 'Checked Out') {
            $details = 'Moved out ' . ($t['checkout_date'] ? date('n/j/Y', strtotime($t['checkout_date'])) : '');
        } else {
            $details = 'Tenancy ended';
        }
      ?>
        <tr>
          <td>
            <div class="cell-person">
              <div class="user-avatar-md" style="color:var(--maroon);background:var(--maroon-soft);"><?= clean(strtoupper(substr($t['first_name'], 0, 1))) ?></div>
              <div><?= clean($t['first_name'] . ' ' . $t['last_name']) ?></div>
            </div>
          </td>
          <td><?= $t['room_number'] ? '<i class="bi bi-house-door-fill"></i> Room ' . clean($t['room_number']) : '<span class="text-muted">Unassigned</span>' ?></td>
          <td><span class="badge badge-<?= status_badge_class($t['status']) ?>"><?= clean($t['status']) ?></span></td>
          <td class="text-muted small"><i class="bi bi-calendar-event"></i> <?= $t['checkin_date'] ? clean(date('n/j/Y', strtotime($t['checkin_date']))) : '—' ?><?= $t['checkout_date'] ? ' – ' . clean(date('n/j/Y', strtotime($t['checkout_date']))) : '' ?></td>
          <td class="text-muted small"><?= clean($details) ?></td>
          <td class="text-end">
            <?php if ($t['status'] === 'Pending' && $t['room_id']): ?>
              <form method="post" class="d-inline"><?= csrf_field() ?><input type="hidden" name="action" value="checkin"><input type="hidden" name="tenant_id" value="<?= $t['tenant_id'] ?>"><button class="btn btn-sm btn-maroon">Check In</button></form>
            <?php elseif ($t['status'] === 'Active'): ?>
              <form method="post" class="d-inline" onsubmit="return confirm('Check out this tenant?');"><?= csrf_field() ?><input type="hidden" name="action" value="checkout"><input type="hidden" name="tenant_id" value="<?= $t['tenant_id'] ?>"><button class="btn btn-sm btn-outline-maroon">Check Out</button></form>
              <form method="post" class="d-inline" onsubmit="return confirm('Mark this tenant as evicted? This frees up their room.');"><?= csrf_field() ?><input type="hidden" name="action" value="evict"><input type="hidden" name="tenant_id" value="<?= $t['tenant_id'] ?>"><button class="btn btn-sm btn-outline-danger">Evict</button></form>
            <?php else: ?>
              <span class="text-muted small">—</span>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?= pagination_links($result['page'], $result['totalPages']) ?>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
