<?php
require_once __DIR__ . '/../config/app.php';
logout_user();
redirect('/auth/login.php');
