<?php
require_once __DIR__ . '/../config/app.php';
require_role('admin');

$pageTitle = 'Reports & Analytics';
include __DIR__ . '/../includes/header.php';

$categories = [
    'occupancy'   => ['title' => 'Occupancy Reports',   'reports' => [
        ['icon' => '<i class="bi bi-building"></i>', 'title' => 'Monthly Occupancy Report', 'desc' => 'Detailed occupancy statistics, vacancy rates, and room turnover for the current month.'],
    ]],
    'payment'     => ['title' => 'Payment Reports',     'reports' => [
        ['icon' => '<i class="bi bi-credit-card-fill"></i>', 'title' => 'Monthly Payment Report', 'desc' => 'Payment collection summary, outstanding balances, and revenue breakdown.'],
    ]],
    'tenant'      => ['title' => 'Tenant Reports',      'reports' => [
        ['icon' => '<i class="bi bi-people-fill"></i>', 'title' => 'Tenant Directory', 'desc' => 'Complete list of all current tenants with contact information and room assignments.'],
    ]],
    'maintenance' => ['title' => 'Maintenance Reports', 'reports' => [
        ['icon' => '<i class="bi bi-tools"></i>', 'title' => 'Maintenance Summary Report', 'desc' => 'Overview of all maintenance requests, completion rates, and pending tasks.'],
    ]],
];
?>
<div class="page-header"><div><h1>Reports &amp; Analytics</h1><p class="text-muted">Generate and export comprehensive reports for property management.</p></div></div>

<div class="report-category-grid">
  <?php foreach ($categories as $key => $cat): ?>
    <div class="panel" id="<?= $key ?>">
      <div class="panel-header"><h2><?= clean($cat['title']) ?></h2></div>
      <?php foreach ($cat['reports'] as $r): ?>
        <div class="report-card">
          <div class="report-card-top">
            <div class="report-card-icon"><?= $r['icon'] ?></div>
            <div><h3><?= clean($r['title']) ?></h3><p><?= clean($r['desc']) ?></p></div>
          </div>
          <div class="report-card-actions">
            <a href="<?= BASE_URL ?>/admin/report_print.php?type=<?= $key ?>&mode=view" target="_blank" class="btn btn-outline-maroon"><i class="bi bi-eye-fill"></i> Review</a>
            <a href="<?= BASE_URL ?>/admin/report_print.php?type=<?= $key ?>" target="_blank" class="btn btn-maroon"><i class="bi bi-printer-fill"></i> Print</a>
            <a href="<?= BASE_URL ?>/admin/report_export.php?type=<?= $key ?>" class="btn btn-outline-maroon"><i class="bi bi-file-earmark-pdf-fill"></i> Export PDF</a>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endforeach; ?>
</div>

<p class="text-muted small mt-3">"Review" opens the report on-screen to look over. "Print" opens the same view and sends it straight to your printer dialog. "Export PDF" downloads the report as a PDF file instead.</p>
<?php include __DIR__ . '/../includes/footer.php'; ?>
