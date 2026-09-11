<?php
require_once __DIR__ . '/../config/app.php';
require_role('tenant');

$db = get_db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    $first = str_input($_POST, 'first_name');
    $last  = str_input($_POST, 'last_name');
    $email = str_input($_POST, 'email');
    $phone = str_input($_POST, 'phone');
    $emergencyContact = str_input($_POST, 'emergency_contact');
    $emergencyRelation = str_input($_POST, 'emergency_relation');
    $emergencyPhone   = str_input($_POST, 'emergency_phone');
    $newPassword = str_input($_POST, 'new_password', '', false);

    if ($first === '' || $last === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        flash('error', 'Please fill in a valid name and email.');
    } elseif ($newPassword !== '' && strlen($newPassword) < 8) {
        flash('error', 'New password needs at least 8 characters.');
    } else {
        $db->beginTransaction();
        try {
            if ($newPassword !== '') {
                $db->prepare('UPDATE users SET first_name=?, last_name=?, email=?, phone=?, password_hash=? WHERE user_id=?')
                   ->execute([$first, $last, $email, $phone ?: null, password_hash($newPassword, PASSWORD_DEFAULT), current_user_id()]);
            } else {
                $db->prepare('UPDATE users SET first_name=?, last_name=?, email=?, phone=? WHERE user_id=?')
                   ->execute([$first, $last, $email, $phone ?: null, current_user_id()]);
            }
            // Emergency contact "relationship" isn't its own column — folded into the name field, matching the prototype's display.
            $contactValue = $emergencyContact . ($emergencyRelation ? " ($emergencyRelation)" : '');
            $db->prepare('UPDATE tenants SET emergency_contact=?, emergency_phone=? WHERE user_id=?')
               ->execute([$contactValue ?: null, $emergencyPhone ?: null, current_user_id()]);

            $db->commit();
            $_SESSION['first_name'] = $first;
            $_SESSION['last_name']  = $last;
            $_SESSION['email']      = $email;
            flash('success', 'Profile updated.');
        } catch (Exception $e) {
            $db->rollBack();
            flash('error', 'Could not update profile. Please try again.');
        }
    }
    redirect('/tenant/profile.php');
}

$stmt = $db->prepare('SELECT u.*, t.tenant_id, t.emergency_contact, t.emergency_phone FROM users u JOIN tenants t ON t.user_id = u.user_id WHERE u.user_id = ?');
$stmt->execute([current_user_id()]);
$profile = $stmt->fetch();

// Split "Name (Relationship)" back out for the form
$relation = '';
$contactName = $profile['emergency_contact'] ?? '';
if (preg_match('/^(.*)\s\((.+)\)$/', (string) $contactName, $m)) {
    $contactName = $m[1];
    $relation = $m[2];
}

$pageTitle = 'My Profile';
include __DIR__ . '/../includes/header.php';
?>
<div class="page-header"><div><h1>My Profile</h1></div></div>

<div class="panel mb-3" style="max-width: 720px;">
  <div class="profile-header">
    <div class="user-avatar user-avatar-lg"><?= clean(strtoupper(substr($profile['first_name'], 0, 1))) ?></div>
    <div>
      <h2 class="mb-0"><?= clean($profile['first_name'] . ' ' . $profile['last_name']) ?></h2>
      <span class="text-muted">Tenant · TEN-<?= str_pad((string) $profile['tenant_id'], 4, '0', STR_PAD_LEFT) ?></span>
    </div>
  </div>
</div>

<form method="post" style="max-width: 720px;">
  <?= csrf_field() ?>
  <div class="split-form">
    <div class="panel">
      <p class="fw-semibold small mb-3">Personal Information</p>
      <div class="mb-3"><label class="form-label">First Name</label><input class="form-control" name="first_name" value="<?= clean($profile['first_name']) ?>" required></div>
      <div class="mb-3"><label class="form-label">Last Name</label><input class="form-control" name="last_name" value="<?= clean($profile['last_name']) ?>" required></div>
      <div class="mb-3"><label class="form-label">Email Address</label><input type="email" class="form-control" name="email" value="<?= clean($profile['email']) ?>" required></div>
      <div class="mb-3"><label class="form-label">Phone Number</label><input class="form-control" name="phone" value="<?= clean($profile['phone'] ?? '') ?>"></div>
      <div class="mb-0"><label class="form-label">Tenant ID</label><input class="form-control" value="TEN-<?= str_pad((string) $profile['tenant_id'], 4, '0', STR_PAD_LEFT) ?>" disabled></div>
    </div>
    <div class="panel">
      <p class="fw-semibold small mb-3">Emergency Contact</p>
      <div class="mb-3"><label class="form-label">Contact Name</label><input class="form-control" name="emergency_contact" value="<?= clean($contactName) ?>"></div>
      <div class="mb-3"><label class="form-label">Relationship</label><input class="form-control" name="emergency_relation" value="<?= clean($relation) ?>"></div>
      <div class="mb-0"><label class="form-label">Phone Number</label><input class="form-control" name="emergency_phone" value="<?= clean($profile['emergency_phone'] ?? '') ?>"></div>
    </div>
  </div>

  <div class="panel mt-3">
    <p class="fw-semibold small mb-3">Change Password</p>
    <div class="row g-3">
      <div class="col-md-6">
        <label class="form-label">New Password <span class="text-muted">(leave blank to keep current)</span></label>
        <input type="password" class="form-control" name="new_password" minlength="8">
      </div>
    </div>
  </div>

  <div class="d-flex gap-2 mt-3" style="max-width: 720px;">
    <button class="btn btn-maroon">Save Changes</button>
    <a href="<?= BASE_URL ?>/tenant/profile.php" class="btn btn-light">Cancel</a>
  </div>
</form>

<a href="<?= BASE_URL ?>/auth/logout.php" class="btn btn-maroon w-100 mt-4" style="max-width: 720px;"><i class="bi bi-box-arrow-right"></i> Log Out</a>
<?php include __DIR__ . '/../includes/footer.php'; ?>
