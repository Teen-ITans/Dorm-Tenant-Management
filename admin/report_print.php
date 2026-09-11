<?php
require_once __DIR__ . '/../config/app.php';
require_role('admin');

$db = get_db();
$type = $_GET['type'] ?? '';
$validTypes = ['occupancy' => 'Occupancy', 'payment' => 'Payment', 'tenant' => 'Tenant', 'maintenance' => 'Maintenance'];

if (!isset($validTypes[$type])) {
    die('Unknown report type.');
}

switch ($type) {
    case 'occupancy':
        $rows = $db->query("SELECT room_number, room_type, capacity, monthly_rate, floor_number, status FROM dorm_rooms ORDER BY floor_number, room_number")->fetchAll();
        $columns = ['Room' => 'room_number', 'Type' => 'room_type', 'Capacity' => 'capacity', 'Monthly Rate' => 'monthly_rate', 'Floor' => 'floor_number', 'Status' => 'status'];
        break;
    case 'payment':
        $rows = $db->query("
            SELECT u.first_name, u.last_name, r.room_number, p.payment_for_month, p.payment_amount, p.payment_status, p.payment_date
            FROM payments p JOIN tenants t ON t.tenant_id=p.tenant_id JOIN users u ON u.user_id=t.user_id LEFT JOIN dorm_rooms r ON r.room_id=t.room_id
            ORDER BY p.due_date DESC
        ")->fetchAll();
        $columns = ['First Name' => 'first_name', 'Last Name' => 'last_name', 'Room' => 'room_number', 'Month' => 'payment_for_month', 'Amount' => 'payment_amount', 'Status' => 'payment_status', 'Date' => 'payment_date'];
        break;
    case 'tenant':
        $rows = $db->query("
            SELECT u.first_name, u.last_name, u.email, u.phone, r.room_number, t.status, t.checkin_date
            FROM tenants t JOIN users u ON u.user_id=t.user_id LEFT JOIN dorm_rooms r ON r.room_id=t.room_id
            WHERE t.approval_status='Approved' ORDER BY u.first_name
        ")->fetchAll();
        $columns = ['First Name' => 'first_name', 'Last Name' => 'last_name', 'Email' => 'email', 'Phone' => 'phone', 'Room' => 'room_number', 'Status' => 'status', 'Check-in' => 'checkin_date'];
        break;
    case 'maintenance':
        $rows = $db->query("
            SELECT m.issue_title, u.first_name, u.last_name, m.priority_level, m.status, m.assigned_to, m.date_submitted, m.date_resolved
            FROM maintenance_requests m JOIN tenants t ON t.tenant_id=m.tenant_id JOIN users u ON u.user_id=t.user_id
            ORDER BY m.date_submitted DESC
        ")->fetchAll();
        $columns = ['Issue' => 'issue_title', 'Tenant' => 'first_name', 'Priority' => 'priority_level', 'Status' => 'status', 'Assigned To' => 'assigned_to', 'Submitted' => 'date_submitted', 'Resolved' => 'date_resolved'];
        break;
}

// Log this generation
$db->prepare('INSERT INTO reports (report_type, generated_by, period_start, period_end, total_records) VALUES (?,?,?,?,?)')
   ->execute([$validTypes[$type], current_user_id(), date('Y-m-01'), date('Y-m-d'), count($rows)]);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title><?= $validTypes[$type] ?> Report · <?= SITE_NAME ?></title>
<style>
  body { font-family: Arial, sans-serif; color: #212529; padding: 2rem; }
  .report-head { display:flex; justify-content:space-between; align-items:center; border-bottom: 3px solid #800000; padding-bottom: 1rem; margin-bottom: 1.5rem; }
  .report-head h1 { font-size: 1.4rem; margin: 0; color: #800000; }
  .report-head p { margin: .2rem 0 0; color: #6c757d; font-size: .85rem; }
  table { width: 100%; border-collapse: collapse; font-size: .85rem; }
  th, td { border: 1px solid #dee2e6; padding: 8px 10px; text-align: left; }
  th { background: #f8f4f5; color: #800000; }
  tr:nth-child(even) { background: #fafafa; }
  .print-btn { background:#800000; color:#fff; border:none; padding:.5rem 1.2rem; border-radius:6px; cursor:pointer; font-size:.9rem; }
  @media print { .no-print { display:none; } body { padding: 0; } }
</style>
</head>
<body>
  <div class="report-head">
    <div>
      <h1><?= SITE_NAME ?></h1>
      <p><?= $validTypes[$type] ?> Report · Generated <?= date('F j, Y g:i A') ?> · <?= count($rows) ?> record(s)</p>
    </div>
    <button class="print-btn no-print" onclick="window.print()"><i class="bi bi-printer-fill"></i> Print / Save as PDF</button>
  </div>

  <table>
    <thead><tr><?php foreach (array_keys($columns) as $label): ?><th><?= htmlspecialchars($label) ?></th><?php endforeach; ?></tr></thead>
    <tbody>
      <?php if (!$rows): ?>
        <tr><td colspan="<?= count($columns) ?>" style="text-align:center;color:#6c757d;">No records found.</td></tr>
      <?php endif; ?>
      <?php foreach ($rows as $row): ?>
        <tr>
          <?php foreach ($columns as $field): ?>
            <td><?= htmlspecialchars((string) ($row[$field] ?? '—')) ?></td>
          <?php endforeach; ?>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</body>
</html>
