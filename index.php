<?php
require_once __DIR__ . '/config/app.php';

if (is_logged_in()) {
    redirect(current_role() === 'admin' ? '/admin/dashboard.php' : '/tenant/dashboard.php');
}

redirect('/auth/login.php');
