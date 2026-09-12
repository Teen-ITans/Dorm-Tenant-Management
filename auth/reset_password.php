<?php
require_once __DIR__ . '/../config/app.php';

if (is_logged_in()) {
    redirect(current_role() === 'admin' ? '/admin/dashboard.php' : '/tenant/dashboard.php');
}

$errors = [];
$success = false;
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

    $pwdError = password_policy_error($newPassword);

    if (!$validCode) {
        $errors[] = 'That code is invalid or has expired. Please request a new one.';
    } elseif ($pwdError !== null) {
        $errors[] = $pwdError;
    } elseif ($newPassword !== $confirmPassword) {
        $errors[] = 'Passwords do not match.';
    } else {
        get_db()->prepare('UPDATE users SET password_hash = ?, reset_otp = NULL, reset_otp_expires = NULL, password_changed_at = NOW() WHERE user_id = ?')
                ->execute([password_hash($newPassword, PASSWORD_DEFAULT), $user['user_id']]);
        $success = true;
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
      <?php if ($success): ?>
        <div class="recovery-success">
          <span class="recovery-icon"><i class="bi bi-check-lg"></i></span>
          <h2>Password Reset Successful</h2>
          <p class="text-muted">Your password has been updated. Sign in with your new credentials.</p>
          <div class="callout callout-success">
            <i class="bi bi-shield-check"></i>
            <div>For your security, you'll be signed out of any other active session the next time it loads a page.</div>
          </div>
          <a href="<?= BASE_URL ?>/auth/login.php" class="btn btn-maroon w-100">Return to Login</a>
        </div>
      <?php else: ?>
        <a href="<?= BASE_URL ?>/auth/forgot_password.php" class="recovery-back"><i class="bi bi-arrow-left"></i> Back</a>

        <div class="recovery-header">
          <span class="recovery-icon"><i class="bi bi-key-fill"></i></span>
          <div>
            <h2>Verify &amp; Reset</h2>
            <div class="step-caption">
              <span class="step-track"><span class="bar active"></span><span class="bar active"></span></span>
              Step 2 of 2 &middot; Code sent to <span class="recovery-code-target"><?= clean($oldEmail) ?></span>
            </div>
          </div>
        </div>

        <?php if ($errors): ?>
          <div class="alert alert-danger">
            <?php foreach ($errors as $err): ?><div><?= clean($err) ?></div><?php endforeach; ?>
          </div>
        <?php endif; ?>

        <form method="post" class="needs-validation" novalidate id="resetForm">
          <?= csrf_field() ?>
          <input type="hidden" name="email" value="<?= clean($oldEmail) ?>">
          <input type="hidden" name="otp" id="otp">

          <label class="form-label">Verification Code <span class="text-danger">*</span></label>
          <div class="otp-boxes">
            <?php for ($i = 0; $i < 6; $i++): ?>
              <input type="text" class="otp-box" id="otpBox<?= $i ?>" inputmode="numeric" maxlength="1" autocomplete="one-time-code">
            <?php endfor; ?>
          </div>
          <div class="otp-count"><span id="otpCount">0</span>/6</div>

          <div class="resend-row">
            <span id="resendTimer">Resend code in <span id="resendSeconds">60</span>s</span>
            <button type="button" id="resendBtn" style="display:none" disabled>Resend Code</button>
          </div>

          <div class="callout callout-info">
            <i class="bi bi-info-circle-fill"></i>
            <div>Password requirements: at least 8 characters, containing uppercase, lowercase, a number and a special character (e.g. <code>Hrm@2026!</code>).</div>
          </div>

          <div class="mb-1">
            <label class="form-label">Create New Password <span class="text-danger">*</span></label>
            <div class="input-icon">
              <span class="icon-prefix"><i class="bi bi-lock-fill"></i></span>
              <input type="password" name="new_password" id="password" class="form-control" placeholder="e.g. Hrm@2026!" required>
            </div>
          </div>
          <div class="strength-meter">
            <span class="seg" id="seg0"></span><span class="seg" id="seg1"></span><span class="seg" id="seg2"></span><span class="seg" id="seg3"></span><span class="seg" id="seg4"></span>
          </div>
          <div class="strength-label" id="strengthLabel">&nbsp;</div>

          <ul class="req-checklist" id="reqChecklist">
            <li id="reqLen"><i class="bi bi-circle"></i> At least 8 characters</li>
            <li id="reqUpper"><i class="bi bi-circle"></i> Uppercase letter (A-Z)</li>
            <li id="reqLower"><i class="bi bi-circle"></i> Lowercase letter (a-z)</li>
            <li id="reqNumber"><i class="bi bi-circle"></i> Number (0-9)</li>
            <li id="reqSpecial"><i class="bi bi-circle"></i> Special character (@!#…)</li>
          </ul>

          <div class="mb-1">
            <label class="form-label">Confirm New Password <span class="text-danger">*</span></label>
            <div class="input-icon">
              <span class="icon-prefix"><i class="bi bi-lock-fill"></i></span>
              <input type="password" name="confirm_password" id="confirm_password" class="form-control" placeholder="Re-enter your new password" required>
            </div>
          </div>
          <div class="match-hint" id="matchHint"></div>

          <div class="wizard-status">
            <span id="statusOtp"><span class="dot"></span>OTP Complete</span>
            <span id="statusPwd"><span class="dot"></span>Password Valid</span>
            <span id="statusMatch"><span class="dot"></span>Passwords Match</span>
          </div>

          <button type="submit" class="btn btn-maroon w-100" id="submitBtn" disabled>
            <i class="bi bi-shield-check"></i> Reset Password &amp; Log In
          </button>
        </form>
        <form method="post" action="<?= BASE_URL ?>/auth/forgot_password.php" id="resendForm" style="display:none">
          <?= csrf_field() ?>
          <input type="hidden" name="email" value="<?= clean($oldEmail) ?>">
        </form>
        <p class="text-center mt-3 mb-0">
          Didn't get a code? <a href="<?= BASE_URL ?>/auth/forgot_password.php">Request a new one</a>
        </p>
      <?php endif; ?>
    </div>
  </div>
</div>

<?php
$extraScripts = <<<'HTML'
<script>
(function () {
  const otpBoxes = Array.from(document.querySelectorAll('.otp-box'));
  const otpHidden = document.getElementById('otp');
  const otpCount = document.getElementById('otpCount');
  const statusOtp = document.getElementById('statusOtp');
  if (!otpBoxes.length) return;

  function syncOtp() {
    const value = otpBoxes.map(b => b.value).join('');
    otpHidden.value = value;
    otpCount.textContent = value.length;
    otpBoxes.forEach(b => b.classList.toggle('filled', b.value !== ''));
    statusOtp.classList.toggle('ok', value.length === 6);
    updateSubmit();
  }

  otpBoxes.forEach((box, i) => {
    box.addEventListener('input', function () {
      box.value = box.value.replace(/[^0-9]/g, '').slice(0, 1);
      if (box.value && i < otpBoxes.length - 1) otpBoxes[i + 1].focus();
      syncOtp();
    });
    box.addEventListener('keydown', function (e) {
      if (e.key === 'Backspace' && !box.value && i > 0) {
        otpBoxes[i - 1].focus();
      }
    });
    box.addEventListener('paste', function (e) {
      const digits = (e.clipboardData.getData('text').match(/[0-9]/g) || []).slice(0, otpBoxes.length);
      if (!digits.length) return;
      e.preventDefault();
      digits.forEach((d, idx) => { if (otpBoxes[idx]) otpBoxes[idx].value = d; });
      const next = otpBoxes[Math.min(digits.length, otpBoxes.length - 1)];
      next.focus();
      syncOtp();
    });
  });

  // ---- Resend cooldown (fixed 60s, purely a UI convenience) ----
  const resendTimer = document.getElementById('resendTimer');
  const resendSeconds = document.getElementById('resendSeconds');
  const resendBtn = document.getElementById('resendBtn');
  const resendForm = document.getElementById('resendForm');
  let remaining = 60;
  const tick = setInterval(function () {
    remaining -= 1;
    if (remaining <= 0) {
      clearInterval(tick);
      resendTimer.style.display = 'none';
      resendBtn.style.display = '';
      resendBtn.disabled = false;
    } else {
      resendSeconds.textContent = remaining;
    }
  }, 1000);
  resendBtn.addEventListener('click', function () {
    resendForm.submit();
  });

  // ---- Password strength + requirement checklist ----
  const password = document.getElementById('password');
  const confirmPassword = document.getElementById('confirm_password');
  const segs = [0, 1, 2, 3, 4].map(i => document.getElementById('seg' + i));
  const strengthLabel = document.getElementById('strengthLabel');
  const reqs = {
    reqLen: p => p.length >= 8,
    reqUpper: p => /[A-Z]/.test(p),
    reqLower: p => /[a-z]/.test(p),
    reqNumber: p => /[0-9]/.test(p),
    reqSpecial: p => /[^A-Za-z0-9]/.test(p)
  };
  const statusPwd = document.getElementById('statusPwd');
  const statusMatch = document.getElementById('statusMatch');
  const matchHint = document.getElementById('matchHint');
  const submitBtn = document.getElementById('submitBtn');

  const colors = ['#c0392b', '#e07b1a', '#e0b400', '#7ab547', '#1a9c5c'];
  const labels = ['Very Weak', 'Weak', 'Fair', 'Strong', 'Very Strong'];

  function evaluatePassword() {
    const value = password.value;
    let metCount = 0;
    Object.keys(reqs).forEach(function (id) {
      const met = reqs[id](value);
      const li = document.getElementById(id);
      li.classList.toggle('met', met);
      li.querySelector('i').className = met ? 'bi bi-check-circle-fill' : 'bi bi-circle';
      if (met) metCount++;
    });

    segs.forEach((seg, i) => {
      seg.style.background = value && i < metCount ? colors[metCount - 1] : '';
    });
    strengthLabel.innerHTML = value ? labels[Math.max(0, metCount - 1)] : '&nbsp;';
    strengthLabel.style.color = value ? colors[Math.max(0, metCount - 1)] : '';

    const valid = metCount === 5;
    statusPwd.classList.toggle('ok', valid);
    evaluateMatch();
    return valid;
  }

  function evaluateMatch() {
    if (!confirmPassword.value) {
      matchHint.textContent = '';
      matchHint.className = 'match-hint';
      statusMatch.classList.remove('ok');
      updateSubmit();
      return;
    }
    const match = password.value === confirmPassword.value;
    matchHint.textContent = match ? 'Passwords match' : 'Passwords do not match';
    matchHint.className = 'match-hint ' + (match ? 'ok' : 'bad');
    statusMatch.classList.toggle('ok', match);
    updateSubmit();
  }

  function updateSubmit() {
    const otpDone = otpHidden.value.length === 6;
    const pwdOk = statusPwd.classList.contains('ok');
    const matchOk = statusMatch.classList.contains('ok');
    submitBtn.disabled = !(otpDone && pwdOk && matchOk);
  }

  password.addEventListener('input', evaluatePassword);
  confirmPassword.addEventListener('input', evaluateMatch);
})();
</script>
HTML;
include __DIR__ . '/../includes/footer.php';
?>
