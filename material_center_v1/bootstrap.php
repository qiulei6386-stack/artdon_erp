<?php
declare(strict_types=1);
define('MC_ROOT', __DIR__);
$docRoot = isset($_SERVER['DOCUMENT_ROOT']) ? realpath((string)$_SERVER['DOCUMENT_ROOT']) : false;
$rootReal = realpath(MC_ROOT);
if ($docRoot && $rootReal && strpos($rootReal, $docRoot) === 0) {
    define('MC_BASE_URL', rtrim(str_replace(DIRECTORY_SEPARATOR, '/', substr($rootReal, strlen($docRoot))), '/'));
} else {
    define('MC_BASE_URL', '/material_center_v1');
}
require_once MC_ROOT . '/lib/helpers.php';
