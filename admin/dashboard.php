<?php
require_once __DIR__ . '/../config/app.php';
require_role('admin');

$db = get_db();

$totalRooms    = (int) $db->query("SELECT COUNT(*) c FROM dorm_rooms")->fetch()['c'];
$occupiedRooms = (int) $db->query("SELECT COUNT(*) c FROM dorm_rooms WHERE status = 'Occupied'")->fetch()['c'];
$vacantRooms   = $totalRooms - $occupiedRooms;
$occupancyRate = $totalRooms > 0 ? round(($occupiedRooms / $totalRooms) * 100) : 0;

$pending = $db->query("SELECT COUNT(*) c, COALESCE(SUM(payment_amount),0) total FROM payments WHERE payment_status IN ('Pending','Overdue')")->fetch();

<<<<<<< HEAD
$expiringContracts = (int) $db->query(
    "SELECT COUNT(*) c FROM contracts WHERE contract_status IN ('Active','Expiring Soon') AND contract_end <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)"
)->fetch()['c'];
=======
// Leases that need a decision: inside the 30-day window, or already
// lapsed and still waiting to be renewed or terminated.
refresh_contract_statuses($db);
$expiringContracts = (int) $db->query(
    "SELECT COUNT(*) c FROM contracts WHERE contract_status IN ('Active','Expiring Soon','Expired') AND contract_end <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)"
)->fetch()['c'];
$expiredContracts = (int) $db->query("SELECT COUNT(*) c FROM contracts WHERE contract_status = 'Expired'")->fetch()['c'];
>>>>>>> origin/james

$openMaintenance = (int) $db->query("SELECT COUNT(*) c FROM maintenance_requests WHERE status IN ('Pending','Ongoing')")->fetch()['c'];
$urgentMaintenance = (int) $db->query("SELECT COUNT(*) c FROM maintenance_requests WHERE status IN ('Pending','Ongoing') AND priority_level = 'Urgent'")->fetch()['c'];

$search = str_input($_GET, 'q');
$params = [];
$where = '';
if ($search !== '') {
    $where = "WHERE (u.first_name LIKE ? OR u.last_name LIKE ? OR r.room_number LIKE ?)";
    $like = "%$search%";
    $params = [$like, $like, $like];
}
$tenantsStmt = $db->prepare("
    SELECT t.tenant_id, u.first_name, u.last_name, r.room_number, t.status,
           c.contract_status,
           COALESCE(
             (SELECT payment_status FROM payments p WHERE p.tenant_id = t.tenant_id ORDER BY p.due_date DESC LIMIT 1),
             '—'
           ) AS last_payment_status
    FROM tenants t
    JOIN users u ON u.user_id = t.user_id
    LEFT JOIN dorm_rooms r ON r.room_id = t.room_id
<<<<<<< HEAD
    LEFT JOIN contracts c ON c.tenant_id = t.tenant_id AND c.contract_status IN ('Active','Expiring Soon')
=======
    LEFT JOIN contracts c ON c.tenant_id = t.tenant_id AND c.contract_status IN ('Active','Expiring Soon','Expired')
>>>>>>> origin/james
    $where
    ORDER BY t.date_registered DESC
    LIMIT 8
");
$tenantsStmt->execute($params);
$tenants = $tenantsStmt->fetchAll();

// Last 6 months of paid revenue, oldest first, for the chart
$monthlyRevenue = $db->query("
    SELECT DATE_FORMAT(payment_date, '%b %Y') AS label, SUM(payment_amount) AS total
    FROM payments
    WHERE payment_status = 'Paid' AND payment_date >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
    GROUP BY DATE_FORMAT(payment_date, '%Y-%m')
    ORDER BY DATE_FORMAT(payment_date, '%Y-%m') ASC
")->fetchAll();
$revenueValues = array_map('floatval', array_column($monthlyRevenue, 'total'));
$currentMonthRevenue = $revenueValues ? end($revenueValues) : 0;
$avgRevenue = $revenueValues ? array_sum($revenueValues) / count($revenueValues) : 0;
$growth = null;
if (count($revenueValues) >= 2) {
    $prev = $revenueValues[count($revenueValues) - 2];
    $growth = $prev > 0 ? round((($currentMonthRevenue - $prev) / $prev) * 100, 1) : null;
}

$pageTitle = 'Admin Dashboard';
include __DIR__ . '/../includes/header.php';
?>
<div class="page-header">
  <div>
    <h1>Dorm Tenant Management System</h1>
    <p class="text-muted">Monitor occupancy, payments, contracts, and maintenance across all dormitories.</p>
  </div>
</div>

<div class="stat-grid">
  <div class="stat-card">
    <div class="stat-card-body">
      <div class="stat-label">Occupancy Rate (%)</div>
      <div class="stat-value"><?= $occupancyRate ?>%</div>
      <div class="stat-sub"><i class="bi bi-arrow-up"></i> <?= $occupiedRooms ?> of <?= $totalRooms ?> rooms occupied</div>
    </div>
    <div class="stat-icon stat-icon-maroon"><i class="bi bi-building"></i></div>
  </div>
  <div class="stat-card">
    <div class="stat-card-body">
      <div class="stat-label">Pending Payments (Count)</div>
      <div class="stat-value"><?= (int) $pending['c'] ?></div>
      <div class="stat-sub text-danger"><i class="bi bi-arrow-down"></i> <?= peso($pending['total']) ?> outstanding</div>
    </div>
    <div class="stat-icon stat-icon-blue"><i class="bi bi-credit-card-fill"></i></div>
  </div>
  <div class="stat-card">
    <div class="stat-card-body">
      <div class="stat-label">Expiring Contracts (Count)</div>
      <div class="stat-value"><?= $expiringContracts ?></div>
<<<<<<< HEAD
      <div class="stat-sub"><i class="bi bi-arrow-down"></i> Within next 30 days</div>
    </div>
    <div class="stat-icon stat-icon-amber"><i class="bi bi-file-earmark-text"></i></div>
=======
      <div class="stat-sub<?= $expiredContracts ? ' text-danger' : '' ?>">
        <i class="bi bi-arrow-down"></i>
        <?= $expiredContracts ? $expiredContracts . ' already expired' : 'Within next 30 days' ?>
      </div>
    </div>
    <div class="stat-icon stat-icon-amber"><a href="<?= BASE_URL ?>/admin/payments.php#contracts" class="text-reset"><i class="bi bi-file-earmark-text"></i></a></div>
>>>>>>> origin/james
  </div>
  <div class="stat-card">
    <div class="stat-card-body">
      <div class="stat-label">Open Maintenance Tasks (Count)</div>
      <div class="stat-value"><?= $openMaintenance ?></div>
      <div class="stat-sub text-danger"><i class="bi bi-arrow-down"></i> <?= $urgentMaintenance ?> urgent tasks</div>
    </div>
    <div class="stat-icon stat-icon-red"><i class="bi bi-tools"></i></div>
  </div>
</div>

<div class="row g-4 mt-1">
  <div class="col-lg-7">
    <div class="panel">
      <div class="panel-header">
        <h2>Tenant Directory</h2>
        <div class="d-flex gap-2">
          <form class="search-box" method="get">
            <input type="search" name="q" value="<?= clean($search) ?>" placeholder="Search by name or room number…" class="form-control form-control-sm">
          </form>
          <a href="<?= BASE_URL ?>/admin/tenants.php" class="btn btn-maroon btn-sm text-nowrap">+ Add Tenant</a>
        </div>
      </div>
      <div class="table-responsive">
        <table class="table app-table">
          <thead><tr><th>Tenant Name</th><th>Room Number</th><th>Rent Status</th><th>Status</th><th>Contract</th></tr></thead>
          <tbody>
            <?php if (!$tenants): ?>
              <tr><td colspan="5" class="text-muted text-center py-4">No tenants found.</td></tr>
            <?php endif; ?>
            <?php foreach ($tenants as $t): ?>
              <tr>
                <td><?= clean($t['first_name'] . ' ' . $t['last_name']) ?></td>
                <td><?= $t['room_number'] ? 'Room ' . clean($t['room_number']) : '<span class="text-muted">Unassigned</span>' ?></td>
                <td><?= clean($t['last_payment_status']) ?></td>
                <td><span class="badge badge-<?= status_badge_class($t['status']) ?>"><?= clean($t['status']) ?></span></td>
                <td class="text-muted"><?= clean($t['contract_status'] ?? '—') ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
  <div class="col-lg-5">
    <div class="panel">
      <div class="panel-header"><h2>Occupancy Overview</h2></div>
      <div class="donut-wrap"><canvas id="occupancyDonut" width="170" height="170"></canvas></div>
      <div class="donut-legend">
        <span><span class="dot" style="background:var(--maroon)"></span>Occupied</span>
        <span><span class="dot" style="background:var(--border)"></span>Vacant</span>
      </div>
      <div class="donut-stats">
        <div class="detail-row"><span class="text-muted">Occupied Rooms</span><strong><?= $occupiedRooms ?> / <?= $totalRooms ?></strong></div>
        <div class="detail-row"><span class="text-muted">Vacant Rooms</span><strong><?= $vacantRooms ?> / <?= $totalRooms ?></strong></div>
        <div class="detail-row"><span class="text-muted">Occupancy Rate</span><strong><?= $occupancyRate ?>%</strong></div>
      </div>
    </div>
  </div>
</div>

<div class="row g-4 mt-1">
  <div class="col-12">
    <div class="panel">
      <div class="panel-header"><h2>Recent Payments</h2><span class="text-muted small">Last 7 months</span></div>
      <canvas id="revenueChart" height="90"></canvas>
      <?php if (!$monthlyRevenue): ?>
        <p class="text-muted text-center mt-3">No paid payments recorded yet.</p>
      <?php else: ?>
        <div class="chart-footer-stats">
          <div><div class="stat-label">Current Month</div><div class="stat-value"><?= peso($currentMonthRevenue) ?></div></div>
          <div><div class="stat-label">Average (<?= count($revenueValues) ?> months)</div><div class="stat-value"><?= peso($avgRevenue) ?></div></div>
          <div><div class="stat-label">Growth</div><div class="stat-value <?= $growth === null ? '' : ($growth >= 0 ? 'text-success' : 'text-danger') ?>"><?= $growth === null ? '—' : ($growth >= 0 ? '+' : '') . $growth . '%' ?></div></div>
        </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<?php
$chartLabels = json_encode(array_column($monthlyRevenue, 'label'));
$chartData   = json_encode($revenueValues);
$extraScripts = "
<script src=\"https://cdn.jsdelivr.net/npm/chart.js@4\"></script>
<script>
new Chart(document.getElementById('occupancyDonut'), {
  type: 'doughnut',
  data: {
    labels: ['Occupied', 'Vacant'],
    datasets: [{ data: [{$occupiedRooms}, {$vacantRooms}], backgroundColor: ['#800000', '#ebedf1'], borderWidth: 0 }]
  },
  options: { cutout: '72%', plugins: { legend: { display: false }, tooltip: { enabled: true } } }
});
" . ($monthlyRevenue ? "
new Chart(document.getElementById('revenueChart'), {
  type: 'line',
  data: {
    labels: {$chartLabels},
    datasets: [{
      label: 'Revenue collected',
      data: {$chartData},
      borderColor: '#800000',
      backgroundColor: 'rgba(128,0,0,0.08)',
      tension: 0.35,
      fill: true,
      pointRadius: 4,
      pointBackgroundColor: '#800000'
    }]
  },
  options: { plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true } } }
});
" : "") . "
</script>";
include __DIR__ . '/../includes/footer.php';
?>
