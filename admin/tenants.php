<?php
require_once __DIR__ . '/../config/app.php';
require_role('admin');

$db = get_db();
$selfPath = '/admin/tenants.php';
require __DIR__ . '/../includes/tenant_action_handler.php';

$pending = $db->query("
    SELECT t.tenant_id, u.first_name, u.last_name, u.email, u.phone, t.date_registered
    FROM tenants t JOIN users u ON u.user_id = t.user_id
    WHERE t.approval_status = 'Pending'
    ORDER BY t.date_registered ASC
")->fetchAll();

$recentlyProcessed = $db->query("
    SELECT t.tenant_id, u.first_name, u.last_name, r.room_number, t.approval_status, t.rejection_reason
    FROM tenants t
    JOIN users u ON u.user_id = t.user_id
    LEFT JOIN dorm_rooms r ON r.room_id = t.room_id
    WHERE t.approval_status IN ('Approved','Rejected')
    ORDER BY t.date_registered DESC
    LIMIT 5
")->fetchAll();

$pageTitle = 'Tenant Registration/Approval';
include __DIR__ . '/../includes/header.php';
?>
<div class="page-header"><div><h1>Tenant Registration/Approval</h1><p class="text-muted">Review and approve new tenant applications.</p></div></div>

<div class="panel">
  <div class="panel-header"><h2>Pending Applications (<?= count($pending) ?>)</h2></div>
  <?php if (!$pending): ?>
    <p class="text-muted py-3">No pending applications.</p>
  <?php else: ?>
  <div class="table-responsive">
    <table class="table app-table align-middle">
      <thead><tr><th>Applicant</th><th>Contact</th><th>Applied</th><th class="text-end">Actions</th></tr></thead>
      <tbody>
      <?php foreach ($pending as $p): ?>
        <tr>
          <td>
            <div class="cell-person">
              <div class="user-avatar-md" style="color:var(--maroon);background:var(--maroon-soft);"><?= clean(strtoupper(substr($p['first_name'], 0, 1))) ?></div>
              <div><?= clean($p['first_name'] . ' ' . $p['last_name']) ?></div>
            </div>
          </td>
          <td class="text-muted small"><i class="bi bi-envelope-fill"></i> <?= clean($p['email']) ?><?= $p['phone'] ? '<br><i class="bi bi-telephone-fill"></i> ' . clean($p['phone']) : '' ?></td>
          <td class="text-muted small"><i class="bi bi-calendar-event"></i> <?= clean(date('n/j/Y', strtotime($p['date_registered']))) ?></td>
          <td class="text-end">
            <form method="post" class="d-inline"><?= csrf_field() ?><input type="hidden" name="action" value="approve"><input type="hidden" name="tenant_id" value="<?= $p['tenant_id'] ?>"><button class="btn btn-sm btn-maroon">Approve</button></form>
            <button type="button" class="btn btn-sm btn-outline-danger reject-btn" data-bs-toggle="modal" data-bs-target="#rejectModal"
              data-id="<?= $p['tenant_id'] ?>" data-name="<?= clean($p['first_name']) ?>">Reject</button>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>

<div class="panel mt-2">
  <div class="panel-header"><h2>Recently Processed</h2></div>
  <?php if (!$recentlyProcessed): ?>
    <p class="text-muted py-3">Nothing processed yet.</p>
  <?php endif; ?>
  <?php foreach ($recentlyProcessed as $r): ?>
    <div class="list-row align-items-start">
      <div>
        <strong><?= clean($r['first_name'] . ' ' . $r['last_name']) ?></strong>
        <div class="text-muted small"><?= $r['room_number'] ? 'Room ' . clean($r['room_number']) : 'No room assigned' ?></div>
        <?php if ($r['approval_status'] === 'Rejected' && $r['rejection_reason']): ?>
          <div class="text-muted small mt-1">Reason: <?= clean($r['rejection_reason']) ?></div>
        <?php endif; ?>
      </div>
      <div class="text-end">
        <span class="badge badge-<?= $r['approval_status'] === 'Approved' ? 'success' : 'danger' ?>"><?= clean($r['approval_status']) ?></span>
        <?php if ($r['approval_status'] === 'Rejected'): ?>
          <form method="post" class="d-inline mt-1 d-block"><?= csrf_field() ?><input type="hidden" name="action" value="reconsider"><input type="hidden" name="tenant_id" value="<?= $r['tenant_id'] ?>"><button class="btn btn-sm btn-outline-maroon">Reconsider</button></form>
        <?php endif; ?>
      </div>
    </div>
  <?php endforeach; ?>
</div>

<!-- Reject Modal -->
<div class="modal fade" id="rejectModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="reject">
        <input type="hidden" name="tenant_id" id="reject_tenant_id">
        <div class="modal-header"><h5 class="modal-title">Reject Application — <span id="reject_tenant_name"></span></h5><button class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
          <label class="form-label">Reason <span class="text-muted">(optional, but the applicant will see it)</span></label>
          <textarea class="form-control" name="reason" rows="3" placeholder="e.g. Missing required documents, no rooms matching your request…"></textarea>
        </div>
        <div class="modal-footer"><button class="btn btn-light" data-bs-dismiss="modal" type="button">Cancel</button><button class="btn btn-outline-danger">Reject Application</button></div>
      </form>
    </div>
  </div>
</div>

<?php
$extraScripts = "<script>
document.getElementById('rejectModal').addEventListener('show.bs.modal', function (e) {
  const btn = e.relatedTarget;
  document.getElementById('reject_tenant_id').value = btn.dataset.id;
  document.getElementById('reject_tenant_name').textContent = btn.dataset.name;
});
</script>";
include __DIR__ . '/../includes/footer.php';
?>
