<?php
require_once __DIR__ . '/../config/app.php';
require_role('admin');

$db = get_db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';

    if ($action === 'update') {
        $id    = (int) ($_POST['user_id'] ?? 0);
        $first = str_input($_POST, 'first_name');
        $last  = str_input($_POST, 'last_name');
        $email = str_input($_POST, 'email');
        $role  = $_POST['role'] ?? 'tenant';
        $newPassword = str_input($_POST, 'new_password', '', false);

        if ($first === '' || $last === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            flash('error', 'Please fill every field correctly.');
        } elseif ($newPassword !== '' && strlen($newPassword) < 8) {
            flash('error', 'New password needs at least 8 characters — the rest of the update was not saved.');
        } else {
            $dupe = $db->prepare('SELECT user_id FROM users WHERE email = ? AND user_id != ?');
            $dupe->execute([$email, $id]);
            if ($dupe->fetch()) {
                flash('error', 'Another account already uses that email.');
            } else {
                if ($newPassword !== '') {
                    $db->prepare('UPDATE users SET first_name=?, last_name=?, email=?, role=?, password_hash=? WHERE user_id=?')
                       ->execute([$first, $last, $email, $role, password_hash($newPassword, PASSWORD_DEFAULT), $id]);
                } else {
                    $db->prepare('UPDATE users SET first_name=?, last_name=?, email=?, role=? WHERE user_id=?')
                       ->execute([$first, $last, $email, $role, $id]);
                }
                flash('success', 'Account updated.');
            }
        }
    }

    if ($action === 'toggle_active') {
        $id = (int) ($_POST['user_id'] ?? 0);
        if ($id === current_user_id()) {
            flash('error', "You can't deactivate your own account while logged in.");
        } else {
            $db->prepare('UPDATE users SET is_active = NOT is_active WHERE user_id = ?')->execute([$id]);
            flash('success', 'Account status updated.');
        }
    }

    redirect('/admin/credentials.php');
}

$search = str_input($_GET, 'q');
if ($search !== '') {
    $like = "%$search%";
    $result = paginate(
        $db,
        "SELECT * FROM users WHERE first_name LIKE ? OR last_name LIKE ? OR email LIKE ? ORDER BY date_created DESC",
        "SELECT COUNT(*) c FROM users WHERE first_name LIKE ? OR last_name LIKE ? OR email LIKE ?",
        [$like, $like, $like]
    );
} else {
    $result = paginate($db, "SELECT * FROM users ORDER BY date_created DESC", "SELECT COUNT(*) c FROM users");
}
$users = $result['rows'];

$pageTitle = 'Log In Credentials';
include __DIR__ . '/../includes/header.php';
?>
<div class="page-header">
  <div>
    <h1>Log In Credentials</h1>
    <p class="text-muted">Manage user login credentials and authentication.</p>
  </div>
</div>

<div class="panel">
  <div class="panel-header">
    <h2>System Users</h2>
    <form class="search-box" method="get">
      <input type="search" name="q" value="<?= clean($search) ?>" placeholder="Search by name or email…" class="form-control form-control-sm">
    </form>
  </div>
  <div class="table-responsive">
    <table class="table app-table align-middle">
      <thead><tr><th>User</th><th>Password</th><th>Role</th><th>Last Login</th><th>Status</th><th></th></tr></thead>
      <tbody>
        <?php if (!$users): ?><tr><td colspan="6" class="text-center text-muted py-4">No users found.</td></tr><?php endif; ?>
        <?php foreach ($users as $u): ?>
          <tr>
            <td>
              <div class="cell-person">
                <div class="user-avatar-md" style="color:var(--maroon);background:var(--maroon-soft);"><?= clean(strtoupper(substr($u['first_name'], 0, 1))) ?></div>
                <div><?= clean($u['first_name'] . ' ' . $u['last_name']) ?><div class="sub"><?= clean($u['email']) ?></div></div>
              </div>
            </td>
            <td class="text-muted">••••••••</td>
            <td><span class="badge badge-<?= $u['role'] === 'admin' ? 'maroon' : 'info' ?>"><?= clean(ucfirst($u['role'])) ?></span></td>
            <td class="text-muted small"><?= $u['last_login'] ? clean(date('n/j/Y g:i A', strtotime($u['last_login']))) : 'Never' ?></td>
            <td><span class="badge badge-<?= $u['is_active'] ? 'success' : 'secondary' ?>"><?= $u['is_active'] ? 'Active' : 'Inactive' ?></span></td>
            <td class="text-end">
              <button type="button" class="btn btn-icon" title="Edit" data-bs-toggle="modal" data-bs-target="#editUserModal"
                data-id="<?= $u['user_id'] ?>" data-first="<?= clean($u['first_name']) ?>" data-last="<?= clean($u['last_name']) ?>"
                data-email="<?= clean($u['email']) ?>" data-role="<?= clean($u['role']) ?>"><i class="bi bi-pencil-square"></i></button>
              <form method="post" class="d-inline" onsubmit="return confirm('<?= $u['is_active'] ? 'Deactivate' : 'Reactivate' ?> this account?');">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="toggle_active">
                <input type="hidden" name="user_id" value="<?= $u['user_id'] ?>">
                <button class="btn btn-icon" title="<?= $u['is_active'] ? 'Deactivate' : 'Reactivate' ?>"><?= $u['is_active'] ? '<i class="bi bi-slash-circle"></i>' : '<i class="bi bi-check-circle-fill"></i>' ?></button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?= pagination_links($result['page'], $result['totalPages']) ?>
</div>

<!-- Edit User Modal -->
<div class="modal fade" id="editUserModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="post" class="needs-validation" novalidate>
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="update">
        <input type="hidden" name="user_id" id="edit_user_id">
        <div class="modal-header"><h5 class="modal-title">Edit Account</h5><button class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-md-6"><label class="form-label">First Name</label><input class="form-control" name="first_name" id="edit_first_name" required></div>
            <div class="col-md-6"><label class="form-label">Last Name</label><input class="form-control" name="last_name" id="edit_last_name" required></div>
          </div>
          <div class="mb-3 mt-3"><label class="form-label">Email Address</label><input type="email" class="form-control" name="email" id="edit_email" required></div>
          <div class="mb-3">
            <label class="form-label">New Password <span class="text-muted">(leave blank to keep current)</span></label>
            <input type="password" class="form-control" name="new_password" minlength="8">
          </div>
          <div class="mb-1">
            <label class="form-label">Role</label>
            <select class="form-select" name="role" id="edit_role">
              <option value="tenant">Tenant</option>
              <option value="admin">Administrator</option>
            </select>
          </div>
        </div>
        <div class="modal-footer"><button class="btn btn-light" data-bs-dismiss="modal" type="button">Cancel</button><button class="btn btn-maroon">Save Changes</button></div>
      </form>
    </div>
  </div>
</div>

<?php
$extraScripts = "<script>
document.getElementById('editUserModal').addEventListener('show.bs.modal', function (e) {
  const btn = e.relatedTarget;
  document.getElementById('edit_user_id').value = btn.dataset.id;
  document.getElementById('edit_first_name').value = btn.dataset.first;
  document.getElementById('edit_last_name').value = btn.dataset.last;
  document.getElementById('edit_email').value = btn.dataset.email;
  document.getElementById('edit_role').value = btn.dataset.role;
});
</script>";
include __DIR__ . '/../includes/footer.php';
?>
