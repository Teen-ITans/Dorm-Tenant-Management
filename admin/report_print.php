<?php
require_once __DIR__ . '/../config/app.php';
require_role('admin');
require_once __DIR__ . '/../includes/report_data.php';

$db = get_db();
$type = $_GET['type'] ?? '';
$validTypes = report_types();

if (!isset($validTypes[$type])) {
    die('Unknown report type.');
}

$viewOnly = ($_GET['mode'] ?? '') === 'view';

$data = fetch_report_data($db, $type);
$rows = $data['rows'];
$columns = $data['columns'];

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
    <button class="print-btn no-print" onclick="window.print()"><i class="bi bi-printer-fill"></i> Print</button>
  </div>

  <?= render_report_table($columns, $rows) ?>

  <?php if (!$viewOnly): ?>
  <script>window.addEventListener('load', function () { window.print(); });</script>
  <?php endif; ?>
</body>
</html>
