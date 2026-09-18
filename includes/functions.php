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
