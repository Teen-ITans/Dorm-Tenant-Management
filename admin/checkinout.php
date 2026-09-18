<?php
require_once __DIR__ . '/../config/app.php';
require_role('admin');

$db = get_db();
$selfPath = '/admin/checkinout.php';
require __DIR__ . '/../includes/tenant_action_handler.php';

$checkedIn  = (int) $db->query("SELECT COUNT(*) c FROM tenants WHERE status = 'Active'")->fetch()['c'];
$checkedOut = (int) $db->query("SELECT COUNT(*) c FROM tenants WHERE status = 'Checked Out'")->fetch()['c'];
$keysOut    = (int) $db->query("SELECT COUNT(*) c FROM tenants WHERE status = 'Checked Out' AND key_returned = FALSE")->fetch()['c'];

$result = paginate(
    $db,
    "SELECT t.*, u.first_name, u.last_name, r.room_number
     FROM tenants t
     JOIN users u ON u.user_id = t.user_id
     LEFT JOIN dorm_rooms r ON r.room_id = t.room_id
     WHERE t.status IN ('Active','Checked Out')
<<<<<<< HEAD
       AND t.tenant_id NOT IN (SELECT tenant_id FROM dismissed_records WHERE page = 'checkinout')
     ORDER BY FIELD(t.status,'Active','Checked Out'), t.checkin_date DESC",
    "SELECT COUNT(*) c FROM tenants t WHERE t.status IN ('Active','Checked Out')
       AND t.tenant_id NOT IN (SELECT tenant_id FROM dismissed_records WHERE page = 'checkinout')"
=======
     ORDER BY FIELD(t.status,'Active','Checked Out'), t.checkin_date DESC",
    "SELECT COUNT(*) c FROM tenants t WHERE t.status IN ('Active','Checked Out')"
>>>>>>> origin/james
);
$records = $result['rows'];

$pageTitle = 'Check-in / Check-out Monitor';
include __DIR__ . '/../includes/header.php';
?>
<div class="page-header"><div><h1>Check-in / Check-out Monitor</h1><p class="text-muted">Track tenant check-in and check-out dates with key return status.</p></div></div>

<div class="stat-grid stat-grid-3">
  <div class="stat-card"><div class="stat-card-body"><div class="stat-label">Currently Checked In</div><div class="stat-value"><?= $checkedIn ?></div></div><div class="stat-icon stat-icon-outline"><i class="bi bi-box-arrow-in-right"></i></div></div>
  <div class="stat-card"><div class="stat-card-body"><div class="stat-label">Checked Out</div><div class="stat-value"><?= $checkedOut ?></div></div><div class="stat-icon stat-icon-outline"><i class="bi bi-box-arrow-left"></i></div></div>
  <div class="stat-card"><div class="stat-card-body"><div class="stat-label">Keys Not Returned</div><div class="stat-value text-danger"><?= $keysOut ?></div></div><div class="stat-icon stat-icon-outline"><i class="bi bi-key-fill"></i></div></div>
</div>

<div class="panel mt-2">
<<<<<<< HEAD
  <div class="panel-header">
    <h2>All Records</h2>
    <?php if ($records): ?>
    <div class="dropdown">
      <button class="btn btn-sm btn-outline-maroon dropdown-toggle" type="button" data-bs-toggle="dropdown"><i class="bi bi-eraser-fill"></i> Clear</button>
      <ul class="dropdown-menu dropdown-menu-end">
        <li><h6 class="dropdown-header">Clear from this view only</h6></li>
        <li><form method="post" onsubmit="return confirm('Clear Checked In records from this view? They stay in the database for reports.');"><?= csrf_field() ?><input type="hidden" name="action" value="clear_view"><input type="hidden" name="page" value="checkinout"><input type="hidden" name="filter" value="Active"><button class="dropdown-item" type="submit">Clear Checked In only</button></form></li>
        <li><form method="post" onsubmit="return confirm('Clear Checked Out records from this view? They stay in the database for reports.');"><?= csrf_field() ?><input type="hidden" name="action" value="clear_view"><input type="hidden" name="page" value="checkinout"><input type="hidden" name="filter" value="Checked Out"><button class="dropdown-item" type="submit">Clear Checked Out only</button></form></li>
        <li><hr class="dropdown-divider"></li>
        <li><form method="post" onsubmit="return confirm('Clear ALL records from this view? They stay in the database for reports.');"><?= csrf_field() ?><input type="hidden" name="action" value="clear_view"><input type="hidden" name="page" value="checkinout"><input type="hidden" name="filter" value="all"><button class="dropdown-item" type="submit">Clear all</button></form></li>
      </ul>
    </div>
    <?php endif; ?>
  </div>
=======
  <div class="panel-header"><h2>All Records</h2></div>
>>>>>>> origin/james
  <div class="table-responsive">
    <table class="table app-table align-middle">
      <thead><tr><th>Tenant</th><th>Room</th><th>Check-in Date</th><th>Check-out Date</th><th>Key Return</th><th>Status</th><th class="text-end">Actions</th></tr></thead>
      <tbody>
      <?php if (!$records): ?><tr><td colspan="7" class="text-center text-muted py-4">No check-in records yet.</td></tr><?php endif; ?>
      <?php foreach ($records as $t): ?>
        <tr>
          <td>
            <div class="cell-person">
              <div class="user-avatar-md" style="color:var(--maroon);background:var(--maroon-soft);"><?= clean(strtoupper(substr($t['first_name'], 0, 1))) ?></div>
              <div><?= clean($t['first_name'] . ' ' . $t['last_name']) ?></div>
            </div>
          </td>
          <td><?= $t['room_number'] ? '<i class="bi bi-house-door-fill"></i> Room ' . clean($t['room_number']) : '<span class="text-muted">—</span>' ?></td>
          <td class="text-muted small"><i class="bi bi-calendar-event"></i> <?= $t['checkin_date'] ? clean(date('n/j/Y', strtotime($t['checkin_date']))) : '—' ?></td>
          <td class="text-muted small"><?= $t['checkout_date'] ? '<i class="bi bi-calendar-event"></i> ' . clean(date('n/j/Y', strtotime($t['checkout_date']))) : '—' ?></td>
          <td>
            <?php if ($t['status'] !== 'Checked Out'): ?>
              <span class="text-muted">—</span>
            <?php elseif ($t['key_returned']): ?>
              <span class="badge badge-success">Returned</span>
            <?php else: ?>
              <span class="badge badge-danger">Not Returned</span>
            <?php endif; ?>
          </td>
          <td><span class="badge badge-<?= $t['status'] === 'Active' ? 'info' : 'secondary' ?>"><?= $t['status'] === 'Active' ? 'Checked In' : 'Checked Out' ?></span></td>
          <td class="text-end">
            <?php if ($t['status'] === 'Active'): ?>
              <form method="post" class="d-inline" onsubmit="return confirm('Check out this tenant?');">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="checkout">
                <input type="hidden" name="tenant_id" value="<?= $t['tenant_id'] ?>">
                <label class="small text-muted me-1"><input type="checkbox" name="keys_returned" checked> Keys returned</label>
                <button class="btn btn-sm btn-outline-maroon">Check Out</button>
              </form>
            <?php elseif (!$t['key_returned']): ?>
              <form method="post" class="d-inline">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="mark_key_returned">
                <input type="hidden" name="tenant_id" value="<?= $t['tenant_id'] ?>">
                <button class="btn btn-sm btn-outline-maroon">Mark Key Returned</button>
              </form>
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
