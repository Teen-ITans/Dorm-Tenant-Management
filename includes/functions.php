<?php
if (!defined('BASE_URL')) { http_response_code(403); exit('Direct access not permitted.'); }
/**
 * includes/functions.php
 * General helpers used across the whole app.
 */

/** Escape a value for safe HTML output. */
function clean(?string $value): string
{
    return htmlspecialchars(trim($value ?? ''), ENT_QUOTES, 'UTF-8');
}

/**
 * Safely read a scalar string out of $_POST or $_GET. A normal form
 * field always arrives as a string, but nothing stops a request from
 * sending `field[]=x` instead — that hands over an array, and PHP 8's
 * trim()/strlen() throw a TypeError on anything but a string, crashing
 * the page for whoever sent it (no login required on a public form
 * like auth/login.php). This returns $default instead of crashing.
 * Trims by default; pass $trimIt = false for fields like passwords,
 * where whitespace is meaningful and shouldn't be silently stripped.
 */
function str_input(array $source, string $key, string $default = '', bool $trimIt = true): string
{
    $value = $source[$key] ?? $default;
    if (!is_string($value)) {
        return $default;
    }
    return $trimIt ? trim($value) : $value;
}

/** Redirect to a path relative to BASE_URL and stop execution. */
function redirect(string $path): void
{
    header('Location: ' . BASE_URL . $path);
    exit;
}

/**
 * Builds a full scheme+host URL for a BASE_URL-relative path. Needed
 * for callback URLs handed to an external service (e.g. PayMongo's
 * success_url/cancel_url) — those can't be sent a host-relative path
 * since the browser is redirected there from paymongo.com, not here.
 */
function absolute_url(string $path): string
{
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    return $scheme . '://' . $_SERVER['HTTP_HOST'] . BASE_URL . $path;
}

/**
 * Run a paginated SELECT. $baseSql must NOT include LIMIT/OFFSET —
 * this appends them. $countSql is the matching "how many rows total"
 * query (same WHERE clause, just COUNT(*) instead of the real
 * columns). Reads the current page from ?page= in the URL.
 *
 * Returns ['rows' => [...], 'page' => int, 'totalPages' => int, 'total' => int].
 */
function paginate(PDO $db, string $baseSql, string $countSql, array $params = [], int $perPage = 12): array
{
    $countStmt = $db->prepare($countSql);
    $countStmt->execute($params);
    $total = (int) $countStmt->fetch()['c'];
    $totalPages = max(1, (int) ceil($total / $perPage));

    $page = max(1, min($totalPages, (int) ($_GET['page'] ?? 1)));
    $offset = ($page - 1) * $perPage;

    // $perPage/$offset are cast to int above, never raw user input,
    // so interpolating them here doesn't open any injection risk —
    // PDO placeholders for LIMIT/OFFSET aren't reliably portable.
    $stmt = $db->prepare($baseSql . " LIMIT {$perPage} OFFSET {$offset}");
    $stmt->execute($params);

    return ['rows' => $stmt->fetchAll(), 'page' => $page, 'totalPages' => $totalPages, 'total' => $total];
}

/** Prev/Next links for a paginate() result, preserving any other query params (search, etc). */
function pagination_links(int $page, int $totalPages): string
{
    if ($totalPages <= 1) {
        return '';
    }
    $params = $_GET;

    $params['page'] = max(1, $page - 1);
    $prevClass = $page <= 1 ? ' disabled' : '';
    $prevHref = clean('?' . http_build_query($params));

    $params['page'] = min($totalPages, $page + 1);
    $nextClass = $page >= $totalPages ? ' disabled' : '';
    $nextHref = clean('?' . http_build_query($params));

    return '<nav class="d-flex justify-content-between align-items-center mt-3">'
         . '<a class="btn btn-sm btn-outline-maroon' . $prevClass . '" href="' . $prevHref . '"><i class="bi bi-chevron-left"></i> Previous</a>'
         . '<span class="text-muted small">Page ' . $page . ' of ' . $totalPages . '</span>'
         . '<a class="btn btn-sm btn-outline-maroon' . $nextClass . '" href="' . $nextHref . '">Next <i class="bi bi-chevron-right"></i></a>'
         . '</nav>';
}

/**
 * Set a one-time flash message, or read + clear one.
 *   flash('error', 'Something went wrong');   // set
 *   $msg = flash('error');                    // read (and clear)
 */
function flash(string $key, ?string $message = null): ?string
{
    if ($message !== null) {
        $_SESSION['flash'][$key] = $message;
        return null;
    }
    if (!empty($_SESSION['flash'][$key])) {
        $msg = $_SESSION['flash'][$key];
        unset($_SESSION['flash'][$key]);
        return $msg;
    }
    return null;
}

/** CSRF token helpers — every state-changing form should use these. */
function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . csrf_token() . '">';
}

function csrf_verify(): void
{
    $token = str_input($_POST, 'csrf_token', '', false);
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
        http_response_code(403);
        die('Your session expired or this form was submitted incorrectly. Please go back and try again.');
    }
}

/** Highlights the active sidebar link. */
function active(string $path): string
{
    return (strpos($_SERVER['REQUEST_URI'] ?? '', $path) !== false) ? 'active' : '';
}

/** Format a number as Philippine peso, e.g. peso(5500) -> "₱5,500.00" */
function peso($amount): string
{
    return '₱' . number_format((float) $amount, 2);
}

/** Turn a status string into a Bootstrap-ish badge class suffix. */
function status_badge_class(string $status): string
{
    $map = [
        'Active'      => 'info',
        'Paid'        => 'success',
        'Approved'    => 'success',
        'Completed'   => 'success',
        'Available'   => 'success',
        'Pending'     => 'warning',
        'Ongoing'     => 'info',
        'Reserved'    => 'info',
        'Expiring Soon' => 'warning',
        'Overdue'     => 'danger',
        'Failed'      => 'danger',
        'Evicted'     => 'danger',
        'Rejected'    => 'danger',
        'Expired'     => 'danger',
        'Terminated'  => 'danger',
        'Occupied'    => 'secondary',
        'Checked Out' => 'secondary',
        'Under Maintenance' => 'secondary',
    ];
    return $map[$status] ?? 'secondary';
}

/**
 * Handle a single file upload safely.
 * Returns the stored relative path (e.g. "uploads/receipts/xyz.jpg"),
 * or null if the field was left empty (not an error — most upload
 * fields in this app are optional).
 * Throws RuntimeException with a user-facing message on failure.
 */
function handle_upload(string $field, string $subdir, array $allowedExt = ['jpg', 'jpeg', 'png', 'pdf'], int $maxBytes = 5 * 1024 * 1024): ?string
{
    if (empty($_FILES[$field]['name']) || $_FILES[$field]['error'] === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if ($_FILES[$field]['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException('The file upload failed. Please try again.');
    }
    if ($_FILES[$field]['size'] > $maxBytes) {
        throw new RuntimeException('That file is too large (max ' . round($maxBytes / 1048576, 1) . ' MB).');
    }

    $ext = strtolower(pathinfo($_FILES[$field]['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $allowedExt, true)) {
        throw new RuntimeException('That file type isn\'t allowed. Allowed types: ' . implode(', ', $allowedExt));
    }

    // Don't just trust the extension — a renamed .php file with a
    // ".jpg" name would otherwise sail through the check above.
    // Look at what the file actually contains.
    $tmpPath = $_FILES[$field]['tmp_name'];
    if (in_array($ext, ['jpg', 'jpeg', 'png'], true)) {
        if (@getimagesize($tmpPath) === false) {
            throw new RuntimeException('That file doesn\'t look like a valid image.');
        }
    } elseif ($ext === 'pdf') {
        if (@file_get_contents($tmpPath, false, null, 0, 5) !== '%PDF-') {
            throw new RuntimeException('That file doesn\'t look like a valid PDF.');
        }
    }

    $destDir = __DIR__ . '/../uploads/' . $subdir . '/';
    if (!is_dir($destDir)) {
        mkdir($destDir, 0755, true);
    }

    $filename = uniqid($subdir . '_', true) . '.' . $ext;
    if (!move_uploaded_file($tmpPath, $destDir . $filename)) {
        throw new RuntimeException('The server could not save the uploaded file.');
    }

    return 'uploads/' . $subdir . '/' . $filename;
}

/**
 * Password policy used by the reset-password screen: at least 8
 * characters, one uppercase, one lowercase, one digit, one special
 * character. Returns the first unmet rule as a message, or null if
 * the password satisfies all of them.
 */
function password_policy_error(string $password): ?string
{
    if (strlen($password) < 8) {
        return 'Password needs at least 8 characters.';
    }
    if (!preg_match('/[A-Z]/', $password)) {
        return 'Password needs at least one uppercase letter.';
    }
    if (!preg_match('/[a-z]/', $password)) {
        return 'Password needs at least one lowercase letter.';
    }
    if (!preg_match('/[0-9]/', $password)) {
        return 'Password needs at least one number.';
    }
    if (!preg_match('/[^A-Za-z0-9]/', $password)) {
        return 'Password needs at least one special character.';
    }
    return null;
}

/** Small helper for "8 days left" style countdowns. Negative = already past. */
function days_until(string $date): int
{
    $target = new DateTime($date);
    $today  = new DateTime('today');
    return (int) $today->diff($target)->format('%r%a');
}

/* =====================================================================
   Contract lifecycle helpers
   ===================================================================== */

/**
 * Keep `contracts.contract_status` in step with the calendar.
 *
 * Nothing used to move a lease along on its own — a contract whose end
 * date had long passed still read "Active" everywhere. These three
 * statements do that bookkeeping, and they're idempotent, so calling
 * this at the top of any page that reads contract statuses is safe.
 * A static guard keeps it to one pass per request no matter how many
 * times it's called.
 *
 * 'Terminated' is deliberately never touched — that's an admin
 * decision, not something a date should be able to undo.
 */
function refresh_contract_statuses(PDO $db): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    try {
        // Term has run its course.
        $db->exec("UPDATE contracts SET contract_status = 'Expired'
                    WHERE contract_status IN ('Active','Expiring Soon') AND contract_end < CURDATE()");

        // Inside the 30-day warning window.
        $db->exec("UPDATE contracts SET contract_status = 'Expiring Soon'
                    WHERE contract_status = 'Active'
                      AND contract_end BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)");

        // Renewed back out past the warning window.
        $db->exec("UPDATE contracts SET contract_status = 'Active'
                    WHERE contract_status = 'Expiring Soon'
                      AND contract_end > DATE_ADD(CURDATE(), INTERVAL 30 DAY)");
    } catch (PDOException $e) {
        // Never let status bookkeeping take a page down with it.
        error_log('refresh_contract_statuses failed: ' . $e->getMessage());
    }
}

/**
 * The lease a tenant is currently living under — including one that has
 * already expired, because an expired contract is exactly the case that
 * needs a "Renew" prompt rather than a blank screen. Only a Terminated
 * lease is treated as gone for good.
 */
function tenant_current_contract(PDO $db, int $tenantId): ?array
{
    $stmt = $db->prepare("
        SELECT * FROM contracts
        WHERE tenant_id = ? AND contract_status IN ('Active','Expiring Soon','Expired')
        ORDER BY FIELD(contract_status,'Active','Expiring Soon','Expired'), contract_end DESC
        LIMIT 1
    ");
    $stmt->execute([$tenantId]);
    return $stmt->fetch() ?: null;
}

/** True when a lease has run past its end date and needs renewing or ending. */
function contract_is_expired(?array $contract): bool
{
    return $contract !== null
        && ($contract['contract_status'] === 'Expired' || days_until($contract['contract_end']) < 0);
}

/* =====================================================================
   Rent / billing-month helpers
   ===================================================================== */

/** The billing month we're in right now, in the app's canonical "June 2026" form. */
function current_billing_month(): string
{
    return date('F Y');
}

/**
 * Canonical list of billing months for a <select>, newest-relevant
 * first. Free-typed months ("Sept 2026" vs "September 2026") made it
 * impossible to tell reliably whether this month's rent was settled,
 * so the pay forms pick from this list instead.
 */
function billing_month_options(int $monthsBack = 3, int $monthsForward = 3): array
{
    $months = [];
    for ($i = -$monthsBack; $i <= $monthsForward; $i++) {
        $months[] = date('F Y', strtotime(date('Y-m-01') . ' ' . $i . ' month'));
    }
    return $months;
}

/**
 * Work out where this tenant stands on THIS month's rent.
 *
 * Returns:
 *   state   — 'Paid' | 'Pending' | 'Overdue' | 'Due' | 'None'
 *   label   — what to show the tenant ("Paid", "Unpaid / Due", ...)
 *   badge   — status_badge_class() suffix ('success', 'warning', 'danger')
 *   amount  — the amount in question
 *   month   — the billing month this describes
 *   payment — the matching payments row, if there is one
 *
 * A month is matched on `payment_for_month` (case/space-insensitive),
 * falling back to a row's own payment/due date for older rows that
 * never had the month filled in. Paid rows win over Pending ones, so a
 * retried payment can't drag a settled month back to unpaid.
 */
function tenant_rent_status(PDO $db, int $tenantId, ?array $contract = null): array
{
    $month = current_billing_month();

    if (!$contract) {
        return ['state' => 'None', 'label' => 'No active contract', 'badge' => 'secondary',
                'amount' => 0.0, 'month' => $month, 'payment' => null];
    }

    $stmt = $db->prepare("
        SELECT * FROM payments
        WHERE tenant_id = ?
          AND (
                LOWER(TRIM(COALESCE(payment_for_month,''))) = LOWER(?)
             OR (
                  (payment_for_month IS NULL OR payment_for_month = '')
                  AND DATE_FORMAT(COALESCE(payment_date, due_date, created_at), '%Y-%m') = DATE_FORMAT(CURDATE(), '%Y-%m')
                )
          )
        ORDER BY FIELD(payment_status,'Paid','Overdue','Pending','Failed'), payment_id DESC
        LIMIT 1
    ");
    $stmt->execute([$tenantId, $month]);
    $payment = $stmt->fetch() ?: null;

    $amount = (float) ($payment['payment_amount'] ?? $contract['monthly_rent']);

    if ($payment && $payment['payment_status'] === 'Paid') {
        return ['state' => 'Paid', 'label' => 'Paid', 'badge' => 'success',
                'amount' => $amount, 'month' => $month, 'payment' => $payment];
    }
    if ($payment && $payment['payment_status'] === 'Overdue') {
        return ['state' => 'Overdue', 'label' => 'Overdue', 'badge' => 'danger',
                'amount' => $amount, 'month' => $month, 'payment' => $payment];
    }
    if ($payment && $payment['payment_status'] === 'Pending') {
        return ['state' => 'Pending', 'label' => 'Payment processing', 'badge' => 'warning',
                'amount' => $amount, 'month' => $month, 'payment' => $payment];
    }

    // No row at all, or only a Failed attempt — either way it's still owed.
    return ['state' => 'Due', 'label' => 'Unpaid / Due', 'badge' => 'danger',
            'amount' => (float) $contract['monthly_rent'], 'month' => $month, 'payment' => $payment];
}

/**
 * Confirm this tenant's still-Pending GCash payments straight from
 * PayMongo, so the portal updates itself.
 *
 * webhooks/paymongo.php remains the primary confirmation path, but it
 * needs a publicly reachable URL — which a localhost XAMPP install
 * doesn't have. This closes that gap by asking PayMongo about each
 * open checkout the next time the tenant loads a page. It is
 * deliberately conservative:
 *   - only rows that actually went through GCash (a checkout id) and
 *     are still 'Pending'
 *   - only attempts from the last 24 hours, so old dead rows aren't
 *     re-polled forever
 *   - at most 3 lookups per request, and every failure is swallowed,
 *     so a slow or unreachable PayMongo never blocks the page
 *
 * Returns the number of payments newly marked Paid.
 */
function sync_pending_gcash_payments(PDO $db, int $tenantId): int
{
    static $seen = [];
    if (isset($seen[$tenantId])) {
        return 0;
    }
    $seen[$tenantId] = true;

    $stmt = $db->prepare("
        SELECT payment_id, paymongo_checkout_id
        FROM payments
        WHERE tenant_id = ?
          AND payment_status = 'Pending'
          AND paymongo_checkout_id IS NOT NULL
          AND created_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)
        ORDER BY payment_id DESC
        LIMIT 3
    ");
    $stmt->execute([$tenantId]);
    $openPayments = $stmt->fetchAll();

    $confirmed = 0;
    foreach ($openPayments as $row) {
        try {
            $session = paymongo_get_checkout_session($row['paymongo_checkout_id']);
            $result  = paymongo_checkout_payment_status($session);
        } catch (Throwable $e) {
            error_log('GCash status check failed for payment ' . $row['payment_id'] . ': ' . $e->getMessage());
            continue;
        }

        if ($result['status'] === 'paid') {
            // Same "don't double-process" guard the webhook uses, so
            // whichever path gets there first wins and the other is a
            // no-op instead of a second confirmation.
            $updated = $db->prepare("
                UPDATE payments
                   SET payment_status = 'Paid',
                       payment_date = CURDATE(),
                       paymongo_payment_id = COALESCE(paymongo_payment_id, ?)
                 WHERE payment_id = ? AND payment_status != 'Paid'
            ");
            $updated->execute([$result['payment_id'], $row['payment_id']]);
            $confirmed += $updated->rowCount();
        } elseif ($result['status'] === 'failed') {
            $db->prepare("UPDATE payments SET payment_status = 'Failed' WHERE payment_id = ? AND payment_status = 'Pending'")
               ->execute([$row['payment_id']]);
        }
    }

    return $confirmed;
}
