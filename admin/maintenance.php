<?php
require_once __DIR__ . '/../config/app.php';
require_role('admin');

$db = get_db();
$teams = ['Electrician Team', 'Plumber Team', 'HVAC Team', 'Maintenance Crew A', 'Maintenance Crew B'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';
    $id = (int) ($_POST['maintenance_id'] ?? 0);

    if ($action === 'assign') {
        $team = $_POST['team'] ?? '';
        if (!in_array($team, $teams, true)) {
            flash('error', 'Please choose a valid team.');
        } else {
            $db->prepare("UPDATE maintenance_requests SET status='Ongoing', assigned_to=? WHERE maintenance_id=?")->execute([$team, $id]);
            flash('success', "Task assigned to $team.");
        }
    }

    if ($action === 'complete') {
        $db->prepare("UPDATE maintenance_requests SET status='Completed', date_resolved=CURDATE() WHERE maintenance_id=?")->execute([$id]);
        flash('success', 'Marked as completed.');
    }

    redirect('/admin/maintenance.php');
}

function fetch_maintenance(PDO $db, string $status): array
{
    $stmt = $db->prepare("
        SELECT m.*, u.first_name, u.last_name, r.room_number
        FROM maintenance_requests m
        JOIN tenants t ON t.tenant_id = m.tenant_id
        JOIN users u ON u.user_id = t.user_id
        JOIN dorm_rooms r ON r.room_id = m.room_id
        WHERE m.status = ?
        ORDER BY FIELD(m.priority_level,'Urgent','High','Medium','Low'), m.date_submitted ASC
    ");
    $stmt->execute([$status]);
    return $stmt->fetchAll();
}

$pendingTasks   = fetch_maintenance($db, 'Pending');
$ongoingTasks   = fetch_maintenance($db, 'Ongoing');
$completedTasks = fetch_maintenance($db, 'Completed');

$pageTitle = 'Maintenance Management';
include __DIR__ . '/../includes/header.php';

function render_task_card(array $m, array $teams): void
{
    $priorityClass = ['Urgent' => 'danger', 'High' => 'danger', 'Medium' => 'warning', 'Low' => 'secondary'][$m['priority_level']] ?? 'secondary';
    ?>
    <div class="task-card">
      <div class="task-card-top">
        <strong><?= clean($m['issue_title']) ?></strong>
        <span class="badge badge-<?= $priorityClass ?>"><?= clean($m['priority_level']) ?></span>
      </div>
      <p class="text-muted small mb-2"><?= clean($m['issue_description']) ?></p>
      <div class="text-muted small"><i class="bi bi-geo-alt-fill"></i> Room <?= clean($m['room_number']) ?> · <i class="bi bi-person-fill"></i> <?= clean($m['first_name'] . ' ' . $m['last_name']) ?></div>
      <div class="text-muted small"><i class="bi bi-calendar-event"></i> <?= clean(date('n/j/Y', strtotime($m['date_submitted']))) ?></div>
      <?php if ($m['assigned_to']): ?><div class="small mt-1"><i class="bi bi-tools"></i> <?= clean($m['assigned_to']) ?></div><?php endif; ?>
      <div><span class="task-tag"><?= clean($m['issue_title']) ?></span></div>

      <?php if ($m['status'] === 'Pending'): ?>
        <form method="post" class="mt-2 d-flex gap-1">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="assign">
          <input type="hidden" name="maintenance_id" value="<?= $m['maintenance_id'] ?>">
          <select name="team" class="form-select form-select-sm" required>
            <option value="">Assign to…</option>
            <?php foreach ($teams as $team): ?><option><?= clean($team) ?></option><?php endforeach; ?>
          </select>
          <button class="btn btn-sm btn-maroon">Go</button>
        </form>
      <?php elseif ($m['status'] === 'Ongoing'): ?>
        <form method="post" class="mt-2"><?= csrf_field() ?><input type="hidden" name="action" value="complete"><input type="hidden" name="maintenance_id" value="<?= $m['maintenance_id'] ?>"><button class="btn btn-sm btn-outline-maroon w-100">Mark Completed</button></form>
      <?php elseif ($m['date_resolved']): ?>
        <div class="text-success small mt-1"><i class="bi bi-check-lg"></i> Resolved <?= clean(date('n/j/Y', strtotime($m['date_resolved']))) ?></div>
      <?php endif; ?>
    </div>
    <?php
}
?>
<div class="page-header"><div><h1>Maintenance Management</h1><p class="text-muted">Manage and assign maintenance tasks across all properties.</p></div></div>

<div class="stat-grid stat-grid-3">
  <div class="stat-card"><div class="stat-card-body"><div class="stat-label">Pending</div><div class="stat-value"><?= count($pendingTasks) ?></div></div><div class="stat-icon stat-icon-outline"><i class="bi bi-clock-fill"></i></div></div>
  <div class="stat-card"><div class="stat-card-body"><div class="stat-label">Ongoing</div><div class="stat-value"><?= count($ongoingTasks) ?></div></div><div class="stat-icon stat-icon-outline"><i class="bi bi-tools"></i></div></div>
  <div class="stat-card"><div class="stat-card-body"><div class="stat-label">Completed</div><div class="stat-value"><?= count($completedTasks) ?></div></div><div class="stat-icon stat-icon-outline"><i class="bi bi-check-lg"></i></div></div>
</div>

<div class="kanban mt-2" id="kanban">
  <div class="kanban-col">
    <div class="kanban-col-header">⏱ Pending (<?= count($pendingTasks) ?>)</div>
    <?php if (!$pendingTasks): ?><p class="text-muted small">Nothing pending.</p><?php endif; ?>
    <?php foreach ($pendingTasks as $m) render_task_card($m, $teams); ?>
  </div>
  <div class="kanban-col col-ongoing">
    <div class="kanban-col-header"><i class="bi bi-tools"></i> Ongoing (<?= count($ongoingTasks) ?>)</div>
    <?php if (!$ongoingTasks): ?><p class="text-muted small">Nothing in progress.</p><?php endif; ?>
    <?php foreach ($ongoingTasks as $m) render_task_card($m, $teams); ?>
  </div>
  <div class="kanban-col">
    <div class="kanban-col-header"><i class="bi bi-check-circle-fill"></i> Completed (<?= count($completedTasks) ?>)</div>
    <?php if (!$completedTasks): ?><p class="text-muted small">No completed tasks yet.</p><?php endif; ?>
    <?php foreach (array_slice($completedTasks, 0, 10) as $m) render_task_card($m, $teams); ?>
  </div>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
