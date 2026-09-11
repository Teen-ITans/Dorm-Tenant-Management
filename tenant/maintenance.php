<?php
require_once __DIR__ . '/../config/app.php';
require_role('tenant');

$db = get_db();
$tenant = current_tenant();

if (!$tenant || $tenant['approval_status'] !== 'Approved') {
    redirect('/tenant/dashboard.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    if (!$tenant['room_id']) {
        flash('error', "You don't have a room assigned yet, so there's nothing to file a request for.");
    } else {
        $title = str_input($_POST, 'issue_type');
        $desc  = str_input($_POST, 'description');

        if ($title === '' || $desc === '') {
            flash('error', 'Please choose an issue type and describe the problem.');
        } else {
            try {
                $photo = handle_upload('photo', 'maintenance', ['jpg', 'jpeg', 'png']);
                $db->prepare('INSERT INTO maintenance_requests (tenant_id, room_id, issue_title, issue_description, photo_file) VALUES (?,?,?,?,?)')
                   ->execute([$tenant['tenant_id'], $tenant['room_id'], $title, $desc, $photo]);
                flash('success', 'Maintenance request submitted.');
            } catch (RuntimeException $e) {
                flash('error', $e->getMessage());
            }
        }
    }
    redirect('/tenant/maintenance.php');
}

$requests = $db->prepare('SELECT * FROM maintenance_requests WHERE tenant_id = ? ORDER BY date_submitted DESC');
$requests->execute([$tenant['tenant_id']]);
$requests = $requests->fetchAll();

$issueTypes = ['Plumbing', 'Electrical', 'HVAC / Air Conditioning', 'Heating', 'Furniture', 'Security / Locks', 'Pest Control', 'Other'];

$pageTitle = 'Maintenance Requests';
include __DIR__ . '/../includes/header.php';
?>
<div class="page-header"><div><h1>Maintenance Requests</h1><p class="text-muted">Submit and track repair requests for your room.</p></div></div>

<button type="button" class="btn btn-maroon w-100 mb-3" data-bs-toggle="collapse" data-bs-target="#newRequestForm">+ New Maintenance Request</button>

<div class="collapse mb-3" id="newRequestForm">
  <div class="panel">
    <div class="panel-header"><h2>Submit New Request</h2></div>
    <form method="post" enctype="multipart/form-data">
      <?= csrf_field() ?>
      <div class="mb-3">
        <label class="form-label">Issue Type *</label>
        <select class="form-select" name="issue_type" required>
          <option value="">Select issue type…</option>
          <?php foreach ($issueTypes as $type): ?><option><?= clean($type) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="mb-3">
        <label class="form-label">Description *</label>
        <textarea class="form-control" name="description" rows="3" placeholder="Please describe the issue in detail…" required></textarea>
      </div>
      <div class="mb-3">
        <label class="form-label">Upload Photo <span class="text-muted">(optional)</span></label>
        <label class="dropzone d-block">
          <span class="dz-icon"><i class="bi bi-camera-fill"></i></span>
          <div>Take or upload photo</div>
          <input type="file" name="photo" accept="image/*">
        </label>
      </div>
      <div class="d-flex gap-2">
        <button class="btn btn-maroon flex-grow-1">Submit Request</button>
        <button type="button" class="btn btn-light" data-bs-toggle="collapse" data-bs-target="#newRequestForm">Cancel</button>
      </div>
    </form>
  </div>
</div>

<div class="panel">
  <div class="panel-header"><h2>Request History &amp; Status Tracker</h2></div>
  <?php if (!$requests): ?><p class="text-muted py-3">No requests yet.</p><?php endif; ?>
  <?php foreach ($requests as $r):
    $steps = ['Pending' => 1, 'Ongoing' => 2, 'Completed' => 3];
    $progress = $steps[$r['status']] ?? 1;
    $pct = $progress === 1 ? 15 : ($progress === 2 ? 60 : 100);
  ?>
    <div class="request-card">
      <div class="d-flex justify-content-between">
        <strong><?= clean($r['issue_description']) ?></strong>
        <span class="badge badge-<?= status_badge_class($r['status']) ?>"><?= clean($r['status']) ?></span>
      </div>
      <div class="text-muted small mb-1"><?= clean($r['issue_title']) ?> · <?= clean(date('M j, Y', strtotime($r['date_submitted']))) ?></div>
      <?php if ($r['assigned_to']): ?><div class="small mb-2">Assigned to: <?= clean($r['assigned_to']) ?></div><?php endif; ?>
      <div class="d-flex justify-content-between small text-muted"><span>Progress</span><span><?= $pct ?>%</span></div>
      <div class="request-progress-bar"><div class="request-progress-fill" style="width:<?= $pct ?>%"></div></div>
      <div class="timeline">
        <div class="timeline-step done">
          <div class="timeline-dot"><i class="bi bi-check-lg"></i></div>
          <div><div class="timeline-label">Request Received</div><div class="timeline-time"><?= clean(date('M j, Y g:i A', strtotime($r['date_submitted']))) ?></div></div>
        </div>
        <div class="timeline-step <?= $r['assigned_to'] ? 'done' : '' ?>">
          <div class="timeline-dot"><i class="bi bi-check-lg"></i></div>
          <div><div class="timeline-label">Technician Assigned</div><div class="timeline-time"><?= $r['assigned_to'] ? clean($r['assigned_to']) : '—' ?></div></div>
        </div>
        <div class="timeline-step <?= $progress >= 2 ? 'done' : '' ?>">
          <div class="timeline-dot"><i class="bi bi-check-lg"></i></div>
          <div><div class="timeline-label">In Progress</div><div class="timeline-time"><?= $progress >= 2 ? 'Underway' : '—' ?></div></div>
        </div>
        <div class="timeline-step <?= $progress >= 3 ? 'done' : '' ?>">
          <div class="timeline-dot"><i class="bi bi-check-lg"></i></div>
          <div><div class="timeline-label">Completed</div><div class="timeline-time"><?= $r['date_resolved'] ? clean(date('M j, Y g:i A', strtotime($r['date_resolved']))) : '—' ?></div></div>
        </div>
      </div>
    </div>
  <?php endforeach; ?>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
