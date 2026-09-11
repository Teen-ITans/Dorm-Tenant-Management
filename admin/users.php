<?php
require_once __DIR__ . '/../config/app.php';
require_role('admin');

$db = get_db();

// ---- Handle actions -------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        $first = str_input($_POST, 'first_name');
        $last  = str_input($_POST, 'last_name');
        $email = str_input($_POST, 'email');
        $role  = $_POST['role'] ?? 'tenant';
        $password = str_input($_POST, 'password', '', false);

        if ($first === '' || $last === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 8 || !in_array($role, ['admin', 'tenant'], true)) {
            flash('error', 'Please fill every field correctly (password needs at least 8 characters).');
        } else {
            $check = $db->prepare('SELECT user_id FROM users WHERE email = ?');
            $check->execute([$email]);
            if ($check->fetch()) {
                flash('error', 'That email is already registered.');
            } else {
                $db->prepare('INSERT INTO users (first_name, last_name, email, password_hash, role) VALUES (?, ?, ?, ?, ?)')
                   ->execute([$first, $last, $email, password_hash($password, PASSWORD_DEFAULT), $role]);
                if ($role === 'tenant') {
                    $newId = (int) $db->lastInsertId();
                    $db->prepare('INSERT INTO tenants (user_id, status, approval_status) VALUES (?, "Pending", "Approved")')
                       ->execute([$newId]);
                }
                flash('success', 'Account created for ' . $first . ' ' . $last . '.');
            }
        }
    }

    redirect('/admin/users.php');
}

$roleInfo = [
    'admin'  => ['Administrator', 'Full access to all system features including user management, property management, payments, contracts, and reports.'],
    'tenant' => ['Tenant', 'Tenant portal access for viewing room info, submitting payments, requesting maintenance, and viewing announcements.'],
];

$pageTitle = 'Register Account';
include __DIR__ . '/../includes/header.php';
?>
<div class="page-header">
  <div>
    <h1>Register Account</h1>
    <p class="text-muted">Create new user accounts for the system.</p>
  </div>
</div>

<div class="panel">
  <form method="post" class="needs-validation split-form" novalidate>
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="create">
    <div>
      <p class="fw-semibold small mb-2"><i class="bi bi-person-fill"></i> User Information</p>
      <div class="row g-3">
        <div class="col-md-6"><label class="form-label">First Name</label><input class="form-control" name="first_name" placeholder="Enter first name" required></div>
        <div class="col-md-6"><label class="form-label">Last Name</label><input class="form-control" name="last_name" placeholder="Enter last name" required></div>
      </div>
      <div class="mb-0 mt-3"><label class="form-label">Email Address</label><input type="email" class="form-control" name="email" placeholder="user@email.com" required></div>
      <div class="mb-0 mt-3"><label class="form-label">Password</label><input type="password" class="form-control" name="password" placeholder="••••••••" minlength="8" required></div>
      <div class="mt-3"><button class="btn btn-maroon w-100">Create Account</button></div>
    </div>
    <div>
      <p class="fw-semibold small mb-1"><i class="bi bi-circle"></i> Identify Role</p>
      <p class="text-muted small mb-2">Select the appropriate role for this user account</p>
      <div class="role-option-list">
        <?php foreach ($roleInfo as $value => [$label, $desc]): ?>
        <label class="role-option">
          <input type="radio" name="role" value="<?= $value ?>" <?= $value === 'tenant' ? 'checked' : '' ?>>
          <span><strong><?= clean($label) ?></strong><p><?= clean($desc) ?></p></span>
        </label>
        <?php endforeach; ?>
      </div>
    </div>
  </form>
</div>

<?php
$extraScripts = "<script>
document.querySelectorAll('.role-option').forEach(function (opt) {
  opt.addEventListener('click', function () { opt.querySelector('input[type=radio]').checked = true; });
});
</script>";
include __DIR__ . '/../includes/footer.php';
?>
