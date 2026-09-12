<?php
if (!defined('BASE_URL')) { http_response_code(403); exit('Direct access not permitted.'); }
/**
 * includes/sidebar.php
 * Renders ONLY the nav links for the current session's role. An admin
 * session never sees tenant links (and vice versa) because the other
 * branch simply never runs — there's no hiding-via-CSS involved.
 * The real access control still happens in require_role() on each
 * page; this is just what makes the UI match that.
 *
 * Admin nav is grouped with collapsible sub-items, and each sub-item
 * is its own page — matching the prototype's navigation one-for-one.
 * A few sub-items intentionally share a page where the prototype's
 * own screens do too (e.g. Property Management's three sub-items are
 * all the same Room Inventory screen, just highlighting a different
 * part of it).
 */
$role = current_role();
$here = $_SERVER['REQUEST_URI'] ?? '';

// [icon, group label, [ [sub-label, path], ... ] ]
$adminGroups = [
    ['<i class="bi bi-person-fill"></i>', 'User Management', [
        ['Register Account', '/admin/users.php'],
        ['Log In Credentials', '/admin/credentials.php'],
        ['Manage Tenants', '/admin/manage-tenants.php'],
        ['Identify Role', '/admin/users.php'],
    ]],
    ['<i class="bi bi-building"></i>', 'Property Management', [
        ['Add/Update Dorm Info', '/admin/rooms.php'],
        ['Assign Tenants', '/admin/rooms.php'],
        ['Monitor Availability', '/admin/rooms.php'],
    ]],
    ['<i class="bi bi-people-fill"></i>', 'Tenant Management', [
        ['Tenant Registration/Approval', '/admin/tenants.php'],
        ['Track Status', '/admin/tenant-status.php'],
        ['Monitor Check-In/Check-out', '/admin/checkinout.php'],
    ]],
    ['<i class="bi bi-credit-card-fill"></i>', 'Payment & Contract Management', [
        ['Track Payments', '/admin/payments.php'],
        ['Manage Contracts', '/admin/payments.php'],
        ['Monitor Expirations', '/admin/payments.php'],
    ]],
    ['<i class="bi bi-tools"></i>', 'Maintenance Management', [
        ['View Requests', '/admin/maintenance.php'],
        ['Assign Tasks', '/admin/maintenance.php'],
        ['Track Status', '/admin/maintenance.php'],
    ]],
    ['<i class="bi bi-bell-fill"></i>', 'Notification Management', [
        ['Send Announcements', '/admin/notifications.php'],
        ['Send Payment Reminders', '/admin/notifications.php'],
        ['Send Expiry Alerts', '/admin/notifications.php'],
    ]],
    ['<i class="bi bi-graph-up-arrow"></i>', 'Reports & Analytics', [
        ['Print Occupancy', '/admin/reports.php#occupancy'],
        ['Print Payments', '/admin/reports.php#payment'],
        ['Print Tenants', '/admin/reports.php#tenant'],
        ['Print Maintenance', '/admin/reports.php#maintenance'],
    ]],
];

$tenantLinks = [
    ['<i class="bi bi-house-door-fill"></i>', 'Home', '/tenant/dashboard.php'],
    ['<i class="bi bi-file-earmark-text"></i>', 'Services', '/tenant/services.php'],
    ['<i class="bi bi-credit-card-fill"></i>', 'Payments', '/tenant/payments.php'],
    ['<i class="bi bi-tools"></i>', 'Maintenance', '/tenant/maintenance.php'],
    ['<i class="bi bi-person-fill"></i>', 'Profile', '/tenant/profile.php'],
];
?>
<nav class="sidebar" id="sidebar">
  <div class="sidebar-brand">
    <span class="brand-icon"><i class="bi bi-mortarboard-fill"></i></span>
    <?php if ($role === 'admin'): ?>
      <div><div class="brand-title">Dorm Tenant</div><div class="brand-title">Management System</div></div>
    <?php else: ?>
      <div class="brand-subtitle">Tenant Portal</div>
    <?php endif; ?>
  </div>

  <?php if ($role === 'admin'): ?>
  <div class="topbar-toggle d-lg-none">
    <a class="pill-active" href="<?= BASE_URL ?>/admin/dashboard.php">Admin Dashboard</a>
  </div>
  <?php elseif ($role === 'tenant'): ?>
  <div class="topbar-toggle d-lg-none">
    <a class="pill-active" href="<?= BASE_URL ?>/tenant/dashboard.php">Tenant App</a>
  </div>
  <?php endif; ?>

  <div class="sidebar-links">
  <?php if ($role === 'admin'): ?>
    <div class="sidebar-section">Admin Functions</div>
    <a class="sidebar-link <?= active('/admin/dashboard.php') ?>" href="<?= BASE_URL ?>/admin/dashboard.php"><span class="nav-icon"><i class="bi bi-speedometer2"></i></span> Dashboard</a>
    <?php foreach ($adminGroups as $i => $g):
        [$icon, $label, $subs] = $g;
        $groupPaths = array_unique(array_map(fn($s) => explode('#', $s[1])[0], $subs));
        $groupActive = false;
        foreach ($groupPaths as $gp) { if (strpos($here, $gp) !== false) { $groupActive = true; break; } }
        $collapseId = 'grp' . $i;
    ?>
      <button class="nav-group-toggle <?= $groupActive ? 'active-group' : '' ?>" type="button"
        data-bs-toggle="collapse" data-bs-target="#<?= $collapseId ?>" aria-expanded="<?= $groupActive ? 'true' : 'false' ?>">
        <span class="toggle-left"><span class="nav-icon"><?= $icon ?></span> <?= clean($label) ?></span>
        <span class="chevron">▾</span>
      </button>
      <div class="collapse sidebar-subnav <?= $groupActive ? 'show' : '' ?>" id="<?= $collapseId ?>">
        <?php foreach ($subs as [$subLabel, $subPath]):
            $subPageOnly = explode('#', $subPath)[0];
            $subActive = strpos($here, $subPageOnly) !== false;
        ?>
          <a class="subnav-link <?= $subActive ? 'active' : '' ?>" href="<?= BASE_URL . $subPath ?>"><?= clean($subLabel) ?></a>
        <?php endforeach; ?>
      </div>
    <?php endforeach; ?>

  <?php elseif ($role === 'tenant'): ?>
    <?php foreach ($tenantLinks as [$icon, $label, $page]): ?>
      <a class="sidebar-link <?= active($page) ?>" href="<?= BASE_URL . $page ?>"><span class="nav-icon"><?= $icon ?></span> <?= clean($label) ?></a>
    <?php endforeach; ?>
  <?php endif; ?>
  </div>

  <div class="sidebar-user">
    <div class="user-avatar"><?= clean(strtoupper(substr($_SESSION['first_name'] ?? '?', 0, 1))) ?></div>
    <div class="user-meta">
      <div class="user-name"><?= clean(($_SESSION['first_name'] ?? '') . ' ' . ($_SESSION['last_name'] ?? '')) ?></div>
      <div class="user-role"><?= clean(ucfirst($role ?? '')) ?></div>
    </div>
  </div>
  <a href="<?= BASE_URL ?>/auth/logout.php" class="btn-logout"><i class="bi bi-box-arrow-right"></i> Log Out</a>
</nav>
<div class="sidebar-backdrop" id="sidebarBackdrop"></div>
