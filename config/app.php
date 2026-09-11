<?php
/**
 * config/app.php
 * Bootstrap file — every page in the app starts with:
 *   require_once __DIR__ . '/../config/app.php';   (or the matching relative path)
 *
 * It starts the session, defines site-wide constants, and loads the
 * database connection + helper functions so nothing else has to.
 */

declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '1'); // set to '0' before showing this to anyone but yourself

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// If you rename the project folder in htdocs, update this to match,
// e.g. '' if the project sits directly at http://localhost/
define('BASE_URL', '/dorm-tenant-system');
define('SITE_NAME', 'Dorm Tenant Management System');

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/email.php';
