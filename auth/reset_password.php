<?php
require_once __DIR__ . '/../config/app.php';

if (is_logged_in()) {
    redirect(current_role() === 'admin' ? '/admin/dashboard.php' : '/tenant/dashboard.php');
}

$errors = [];
$oldEmail = str_input($_GET, 'email');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $email           = str_input($_POST, 'email');
    $otp             = str_input($_POST, 'otp');
    $newPassword     = str_input($_POST, 'new_password', '', false);
    $confirmPassword = str_input($_POST, 'confirm_password', '', false);
    $oldEmail        = $email;

    $stmt = get_db()->prepare('SELECT * FROM users WHERE email = ?');
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    $validCode = $user
        && $user['reset_otp'] !== null
        && hash_equals((string) $user['reset_otp'], $otp)
        && $user['reset_otp_expires'] !== null
        && strtotime($user['reset_otp_expires']) >= time();

    if (!$validCode) {
        $errors[] = 'That code is invalid or has expired. Please request a new one.';
    } elseif (strlen($newPassword) < 8) {
        $errors[] = 'New password needs at least 8 characters.';
    } elseif ($newPassword !== $confirmPassword) {
        $errors[] = 'Passwords do not match.';
    } else {
        get_db()->prepare('UPDATE users SET password_hash = ?, reset_otp = NULL, reset_otp_expires = NULL WHERE user_id = ?')
                ->execute([password_hash($newPassword, PASSWORD_DEFAULT), $user['user_id']]);
        flash('success', 'Your password has been reset. Please sign in.');
        redirect('/auth/login.php');
    }
}

$pageTitle = 'Reset Password';
include __DIR__ . '/../includes/header.php';
?>
<div class="auth-screen">
  <div class="auth-panel">
    <div class="auth-panel-left">
      <span class="brand-icon-lg"><i class="bi bi-mortarboard-fill"></i></span>
      <h1>Dorm Tenant Management</h1>
      <p>Enter the 6-digit code we emailed you, then choose a new password.</p>
    </div>
    <div class="auth-panel-right">
      <h2>Enter Code &amp; New Password</h2>
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
            <input type="email" name="email" class="form-control" placeholder="Enter your email" value="<?= clean($oldEmail) ?>" required>
          </div>
        </div>
        <div class="mb-3">
          <label class="form-label">6-Digit Code</label>
          <input type="text" name="otp" class="form-control" placeholder="123456" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" required>
        </div>
        <div class="mb-3">
          <label class="form-label">New Password</label>
          <div class="input-icon">
            <span class="icon-prefix"><i class="bi bi-lock-fill"></i></span>
            <input type="password" name="new_password" id="password" class="form-control" placeholder="At least 8 characters" minlength="8" required>
          </div>
        </div>
        <div class="mb-3">
          <label class="form-label">Confirm New Password</label>
          <div class="input-icon">
            <span class="icon-prefix"><i class="bi bi-lock-fill"></i></span>
            <input type="password" name="confirm_password" id="confirm_password" class="form-control" placeholder="Re-enter new password" minlength="8" required>
          </div>
        </div>
        <button type="submit" class="btn btn-maroon w-100">Reset Password</button>
      </form>
      <p class="text-center mt-3 mb-0">
        Didn't get a code? <a href="<?= BASE_URL ?>/auth/forgot_password.php">Request a new one</a>
      </p>
    </div>
  </div>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
