<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
if (function_exists('logout_user')) logout_user();
header('Location: login.php');
exit;
