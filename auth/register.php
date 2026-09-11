<?php
require_once __DIR__ . '/../config/app.php';

if (is_logged_in()) {
    redirect(current_role() === 'admin' ? '/admin/dashboard.php' : '/tenant/dashboard.php');
}

$errors = [];
$old = ['first_name' => '', 'last_name' => '', 'email' => '', 'phone' => '', 'age' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    $old['first_name'] = str_input($_POST, 'first_name');
    $old['last_name']  = str_input($_POST, 'last_name');
    $old['email']      = str_input($_POST, 'email');
    $old['phone']      = str_input($_POST, 'phone');
    $old['age']        = str_input($_POST, 'age');
    $password          = str_input($_POST, 'password', '', false);
    $confirm           = $_POST['confirm_password'] ?? '';
    $emergency_contact = str_input($_POST, 'emergency_contact');
    $emergency_phone   = str_input($_POST, 'emergency_phone');

    if ($old['first_name'] === '' || $old['last_name'] === '') {
        $errors[] = 'First and last name are required.';
    }
    if (!filter_var($old['email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Please enter a valid email address.';
    }
    if (strlen($password) < 8) {
        $errors[] = 'Password must be at least 8 characters.';
    }
    if ($password !== $confirm) {
        $errors[] = 'Passwords do not match.';
    }

    if (empty($errors)) {
        $db = get_db();
        $check = $db->prepare('SELECT user_id FROM users WHERE email = ?');
        $check->execute([$old['email']]);

        if ($check->fetch()) {
            $errors[] = 'An account with that email already exists. Try logging in instead.';
        } else {
            $db->beginTransaction();
            try {
                $hash = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $db->prepare(
                    'INSERT INTO users (first_name, last_name, email, password_hash, age, phone, role)
                     VALUES (?, ?, ?, ?, ?, ?, "tenant")'
                );
                $stmt->execute([
                    $old['first_name'],
                    $old['last_name'],
                    $old['email'],
                    $hash,
                    $old['age'] !== '' ? (int) $old['age'] : null,
                    $old['phone'] ?: null,
                ]);
                $userId = (int) $db->lastInsertId();

                $stmt2 = $db->prepare(
                    'INSERT INTO tenants (user_id, status, approval_status, emergency_contact, emergency_phone)
                     VALUES (?, "Pending", "Pending", ?, ?)'
                );
                $stmt2->execute([$userId, $emergency_contact ?: null, $emergency_phone ?: null]);

                $db->commit();
                flash('success', 'Account created! An admin needs to review and approve your application before your dashboard unlocks.');
                redirect('/auth/login.php');
            } catch (Exception $e) {
                $db->rollBack();
                $errors[] = 'Something went wrong while creating your account. Please try again.';
            }
        }
    }
}

$pageTitle = 'Register';
include __DIR__ . '/../includes/header.php';
?>
<div class="auth-screen">
  <div class="auth-panel">
    <div class="auth-panel-left">
      <span class="brand-icon-lg"><i class="bi bi-mortarboard-fill"></i></span>
      <h1>Dorm Tenant<br>Management System</h1>
      <p>Streamlined property management for educational institutions.</p>
    </div>
    <div class="auth-panel-right">
      <h2>Create your account</h2>
      <p class="text-muted">Sign up as a tenant. An admin will approve your application before you can log in.</p>

      <?php if ($errors): ?>
        <div class="alert alert-danger">
          <ul class="mb-0">
            <?php foreach ($errors as $err): ?><li><?= clean($err) ?></li><?php endforeach; ?>
          </ul>
        </div>
      <?php endif; ?>

      <form method="post" class="needs-validation" novalidate>
        <?= csrf_field() ?>
        <div class="row g-3">
          <div class="col-md-6">
            <label class="form-label">First Name</label>
            <input type="text" name="first_name" class="form-control" value="<?= clean($old['first_name']) ?>" required>
          </div>
          <div class="col-md-6">
            <label class="form-label">Last Name</label>
            <input type="text" name="last_name" class="form-control" value="<?= clean($old['last_name']) ?>" required>
          </div>
        </div>
        <div class="mb-3 mt-3">
          <label class="form-label">Email Address</label>
          <input type="email" name="email" class="form-control" value="<?= clean($old['email']) ?>" required>
        </div>
        <div class="row g-3">
          <div class="col-md-6">
            <label class="form-label">Phone Number</label>
            <input type="text" name="phone" class="form-control" placeholder="+63 9XX XXX XXXX" value="<?= clean($old['phone']) ?>">
          </div>
          <div class="col-md-6">
            <label class="form-label">Age <span class="text-muted">(optional)</span></label>
            <input type="number" min="16" max="100" name="age" class="form-control" value="<?= clean($old['age']) ?>">
          </div>
        </div>
        <div class="row g-3 mt-0">
          <div class="col-md-6">
            <label class="form-label">Password</label>
            <input type="password" name="password" id="password" class="form-control" minlength="8" required>
          </div>
          <div class="col-md-6">
            <label class="form-label">Confirm Password</label>
            <input type="password" name="confirm_password" id="confirm_password" class="form-control" minlength="8" required>
          </div>
        </div>
        <hr class="my-3">
        <p class="text-muted small mb-2">Emergency contact (optional, but recommended)</p>
        <div class="row g-3">
          <div class="col-md-6">
            <label class="form-label">Contact Name</label>
            <input type="text" name="emergency_contact" class="form-control">
          </div>
          <div class="col-md-6">
            <label class="form-label">Contact Phone</label>
            <input type="text" name="emergency_phone" class="form-control">
          </div>
        </div>
        <button type="submit" class="btn btn-maroon w-100 mt-4">Create Account</button>
      </form>
      <p class="text-center mt-3 mb-0">
        Already have an account? <a href="<?= BASE_URL ?>/auth/login.php">Sign in</a>
      </p>
    </div>
  </div>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
