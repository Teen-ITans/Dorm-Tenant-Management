<?php
require_once __DIR__ . '/../config/app.php';

if (is_logged_in()) {
    redirect(current_role() === 'admin' ? '/admin/dashboard.php' : '/tenant/dashboard.php');
}

$sent = false;
$oldEmail = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $email = str_input($_POST, 'email');
    $oldEmail = $email;

    if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $stmt = get_db()->prepare('SELECT * FROM users WHERE email = ? AND is_active = TRUE');
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if ($user) {
            // Don't spam a fresh code if one was already sent in the last minute
            // (a full code is valid for 15 minutes, so >14 left means <1 minute old).
            $secondsLeft = $user['reset_otp_expires'] ? strtotime($user['reset_otp_expires']) - time() : 0;
            if ($secondsLeft <= 14 * 60) {
                $otp = (string) random_int(100000, 999999);
                $expires = date('Y-m-d H:i:s', time() + 15 * 60);
                get_db()->prepare('UPDATE users SET reset_otp = ?, reset_otp_expires = ? WHERE user_id = ?')
                        ->execute([$otp, $expires, $user['user_id']]);

                $body = "Hi {$user['first_name']}, use this code to reset your password:\n\n{$otp}\n\nThis code expires in 15 minutes. If you didn't request this, you can safely ignore this email.";
                send_email_alert($user['email'], $user['first_name'], 'Your password reset code', email_template('Reset your password', $body));
            }
        }
        // Same message whether or not the email exists — otherwise this page
        // becomes a way to check which emails are registered.
        $sent = true;
    } else {
        flash('error', 'Please enter a valid email address.');
    }
}

$pageTitle = 'Forgot Password';
include __DIR__ . '/../includes/header.php';
?>
<div class="auth-screen">
  <div class="auth-panel">
    <div class="auth-panel-left">
      <span class="brand-icon-lg"><i class="bi bi-mortarboard-fill"></i></span>
      <h1>Dorm Tenant Management</h1>
      <p>Forgot your password? We'll email you a code to reset it.</p>
    </div>
    <div class="auth-panel-right">
      <h2>Reset Your Password</h2>
      <?php if ($sent): ?>
        <p class="text-muted">If that email is registered, we've sent a 6-digit code to it. Enter it on the next page along with your new password.</p>
        <a href="<?= BASE_URL ?>/auth/reset_password.php?email=<?= urlencode($oldEmail) ?>" class="btn btn-maroon w-100">Enter Code</a>
        <p class="text-center mt-3 mb-0"><a href="<?= BASE_URL ?>/auth/login.php">Back to Sign In</a></p>
      <?php else: ?>
        <p class="text-muted">Enter the email on your account and we'll send you a verification code.</p>
        <?php if ($msg = flash('error')): ?><div class="alert alert-danger"><?= clean($msg) ?></div><?php endif; ?>
        <form method="post" class="needs-validation" novalidate>
          <?= csrf_field() ?>
          <div class="mb-3">
            <label class="form-label">Email Address</label>
            <div class="input-icon">
              <span class="icon-prefix"><i class="bi bi-envelope-fill"></i></span>
              <input type="email" name="email" class="form-control" placeholder="Enter your email" value="<?= clean($oldEmail) ?>" required autofocus>
            </div>
          </div>
          <button type="submit" class="btn btn-maroon w-100">Send Code</button>
        </form>
        <p class="text-center mt-3 mb-0"><a href="<?= BASE_URL ?>/auth/login.php">Back to Sign In</a></p>
      <?php endif; ?>
    </div>
  </div>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
