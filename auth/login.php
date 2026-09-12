<?php
require_once __DIR__ . '/../config/app.php';

if (is_logged_in()) {
    redirect(current_role() === 'admin' ? '/admin/dashboard.php' : '/tenant/dashboard.php');
}

$errors = [];
$oldEmail = '';

if (isset($_GET['deactivated'])) {
    $errors[] = 'This account has been deactivated. Please contact the administrator.';
}

if (isset($_GET['pwreset'])) {
    $errors[] = 'Your password was changed. Please sign in again.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    $oldEmail = str_input($_POST, 'email');
    $password = str_input($_POST, 'password', '', false);

    $stmt = get_db()->prepare('SELECT * FROM users WHERE email = ?');
    $stmt->execute([$oldEmail]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['password_hash'])) {
        $errors[] = 'Incorrect email or password.';
    } elseif (!$user['is_active']) {
        $errors[] = 'This account has been deactivated. Please contact the administrator.';
    } else {
        login_user($user);
        redirect($user['role'] === 'admin' ? '/admin/dashboard.php' : '/tenant/dashboard.php');
    }
}

$pageTitle = 'Sign In';
include __DIR__ . '/../includes/header.php';
?>
<div class="auth-screen">
  <div class="auth-panel">
    <div class="auth-panel-left">
      <span class="brand-icon-lg"><i class="bi bi-mortarboard-fill"></i></span>
      <h1>Dorm Tenant Management</h1>
      <p>Sign in to your account</p>
      <div class="demo-login-box">
        <div class="demo-login-title">Quick Login (Demo)</div>
        <button type="button" class="demo-login-btn" data-email="admin@dorm.edu" data-password="Admin@123">
          <strong>Admin</strong><span>admin@dorm.edu</span>
        </button>
        <button type="button" class="demo-login-btn" data-email="angel@student.dorm.edu" data-password="Tenant@123">
          <strong>Tenant</strong><span>angel@student.dorm.edu</span>
        </button>
      </div>
    </div>
    <div class="auth-panel-right">
      <h2>Welcome Back</h2>
      <p class="text-muted">Sign in to access your dashboard.</p>

      <?php if ($errors): ?>
        <div class="alert alert-danger">
          <?php foreach ($errors as $err): ?><div><?= clean($err) ?></div><?php endforeach; ?>
        </div>
      <?php endif; ?>

      <form method="post" class="needs-validation" novalidate>
        <?= csrf_field() ?>
        <div class="mb-3">
          <label class="form-label">Email Address</label>
          <div class="input-icon">
            <span class="icon-prefix"><i class="bi bi-envelope-fill"></i></span>
            <input type="email" name="email" id="loginEmail" class="form-control" placeholder="Enter your email" value="<?= clean($oldEmail) ?>" required autofocus>
          </div>
        </div>
        <div class="mb-3">
          <div class="d-flex justify-content-between">
            <label class="form-label">Password</label>
            <a href="<?= BASE_URL ?>/auth/forgot_password.php" class="small">Forgot password?</a>
          </div>
          <div class="input-icon">
            <span class="icon-prefix"><i class="bi bi-lock-fill"></i></span>
            <input type="password" name="password" id="loginPassword" class="form-control" placeholder="Enter your password" required>
          </div>
        </div>
        <button type="submit" class="btn btn-maroon w-100">Sign In</button>
      </form>
      <p class="text-center mt-3 mb-0">
        Don't have an account? <a href="<?= BASE_URL ?>/auth/register.php">Register here</a>
      </p>
    </div>
  </div>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
