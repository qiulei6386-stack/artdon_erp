<?php
/*
 * Artdon PLM 7.2 database config
 * 目的：直接沿用你现有网站数据库，不新建独立数据库。
 * 优先读取根目录 config.php 里的数据库配置；这里只作为兜底。
 */

$__plm_root_config = __DIR__ . '/config.php';
if (file_exists($__plm_root_config)) {
    @include_once $__plm_root_config;
}
$__plm_new_config = __DIR__ . '/includes/config.php';
if (file_exists($__plm_new_config)) {
    $__plm_cfg = @include $__plm_new_config;
    if (is_array($__plm_cfg) && isset($__plm_cfg['db']) && is_array($__plm_cfg['db'])) {
        $__plm_db = $__plm_cfg['db'];
        if (!defined('DB_HOST') && !empty($__plm_db['host'])) define('DB_HOST', (string)$__plm_db['host']);
        if (!defined('DB_NAME') && !empty($__plm_db['name'])) define('DB_NAME', (string)$__plm_db['name']);
        if (!defined('DB_USER') && !empty($__plm_db['user'])) define('DB_USER', (string)$__plm_db['user']);
        if (!defined('DB_PASS') && array_key_exists('password', $__plm_db)) define('DB_PASS', (string)$__plm_db['password']);
    }
}

function plm_pick_config_value($constantNames, $varNames, $default = '') {
    foreach ($constantNames as $n) {
        if (defined($n) && constant($n) !== '') return constant($n);
    }
    foreach ($varNames as $n) {
        if (isset($GLOBALS[$n]) && $GLOBALS[$n] !== '') return $GLOBALS[$n];
    }
    foreach (array('config','db_config','database_config','mysql','dbConf') as $arrName) {
        if (!isset($GLOBALS[$arrName]) || !is_array($GLOBALS[$arrName])) continue;
        $arr = $GLOBALS[$arrName];
        foreach (array('db','database','mysql') as $sub) {
            if (isset($arr[$sub]) && is_array($arr[$sub])) {
                foreach (array_merge($constantNames, $varNames) as $k) {
                    $lk = strtolower($k);
                    if (isset($arr[$sub][$k]) && $arr[$sub][$k] !== '') return $arr[$sub][$k];
                    if (isset($arr[$sub][$lk]) && $arr[$sub][$lk] !== '') return $arr[$sub][$lk];
                }
            }
        }
        foreach (array_merge($constantNames, $varNames) as $k) {
            $lk = strtolower($k);
            if (isset($arr[$k]) && $arr[$k] !== '') return $arr[$k];
            if (isset($arr[$lk]) && $arr[$lk] !== '') return $arr[$lk];
        }
    }
    return $default;
}

if (!defined('PLM_DB_HOST')) define('PLM_DB_HOST', plm_pick_config_value(
    array('DB_HOST','DB_HOSTNAME','MYSQL_HOST','DATABASE_HOST'),
    array('DB_HOST','db_host','host','hostname','mysql_host','server','servername'),
    'localhost'
));
if (!defined('PLM_DB_NAME')) define('PLM_DB_NAME', plm_pick_config_value(
    array('DB_NAME','DB_DATABASE','MYSQL_DATABASE','DATABASE_NAME'),
    array('DB_NAME','db_name','dbname','database','mysql_database','database_name'),
    '你的数据库名'
));
if (!defined('PLM_DB_USER')) define('PLM_DB_USER', plm_pick_config_value(
    array('DB_USER','DB_USERNAME','MYSQL_USER','DATABASE_USER'),
    array('DB_USER','db_user','user','username','mysql_user','database_user'),
    '你的数据库用户名'
));
if (!defined('PLM_DB_PASS')) define('PLM_DB_PASS', plm_pick_config_value(
    array('DB_PASS','DB_PASSWORD','MYSQL_PASS','MYSQL_PASSWORD','DATABASE_PASS'),
    array('DB_PASS','db_pass','db_password','pass','password','pwd','mysql_pass','mysql_password','database_pass'),
    '你的数据库密码'
));
if (!defined('PLM_DB_CHARSET')) define('PLM_DB_CHARSET', 'utf8mb4');

// 上传限制，默认 80MB。宝塔/PHP 还需要同步调整 upload_max_filesize 和 post_max_size。
if (!defined('PLM_UPLOAD_MAX_BYTES')) define('PLM_UPLOAD_MAX_BYTES', 80 * 1024 * 1024);
