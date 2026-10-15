<?php
declare(strict_types=1);

/**
 * 一次性生成新系统配置 centers/config.php（只在服务器命令行运行）。
 *
 *   php centers/_base/tools/init_config.php            从现有配置文件中复制数据库连接信息
 *   php centers/_base/tools/init_config.php --force    已有 centers/config.php 时覆盖
 *
 * 只复制数据库地址、库名、账号、密码这几项，写入新文件后两边各自独立；
 * 以后删除旧系统时，新系统的配置不受影响。
 * 生成的文件权限为 640，以 root 运行时属主设为 www（网站进程），其他用户读不到密码。
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$centers = dirname(__DIR__, 2);
$target = $centers . '/config.php';
$source = dirname($centers) . '/includes/config.php';
$force = in_array('--force', $argv, true);

if (is_file($target) && !$force) {
    fwrite(STDOUT, "centers/config.php 已存在，未做修改。需要重新生成请加 --force。\n");
    exit(0);
}
if (!is_file($source)) {
    fwrite(STDERR, "找不到 includes/config.php，请复制 centers/config.example.php 为 centers/config.php 后手动填写。\n");
    exit(1);
}

$old = require $source;
$db = is_array($old) && isset($old['db']) && is_array($old['db']) ? $old['db'] : null;
if (!$db || empty($db['name']) || !isset($db['user'])) {
    fwrite(STDERR, "includes/config.php 中没有完整的数据库配置，请手动填写 centers/config.php。\n");
    exit(1);
}

$config = [
    'db' => [
        'host' => (string) ($db['host'] ?? '127.0.0.1'),
        'port' => (int) ($db['port'] ?? 3306),
        'name' => (string) $db['name'],
        'user' => (string) $db['user'],
        'password' => (string) ($db['password'] ?? ''),
        'charset' => (string) ($db['charset'] ?? 'utf8mb4'),
    ],
    'app' => [
        'session_name' => 'ARTDON_CENTERS',
        'login_max_failures' => 5,
        'login_lock_minutes' => 15,
        'timezone' => 'Asia/Shanghai',
    ],
];

// 先验证能连上数据库，再写文件
try {
    $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $config['db']['host'], $config['db']['port'], $config['db']['name'], $config['db']['charset']);
    $pdo = new PDO($dsn, $config['db']['user'], $config['db']['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $count = (int) $pdo->query('SELECT COUNT(*) FROM crm_users')->fetchColumn();
} catch (Throwable $e) {
    fwrite(STDERR, '数据库连接失败：' . $e->getMessage() . "\n");
    exit(1);
}

$content = "<?php\n// 新系统配置，由 centers/_base/tools/init_config.php 生成于 " . date('Y-m-d H:i:s') . "，不进 Git。\nreturn " . var_export($config, true) . ";\n";
if (file_put_contents($target, $content, LOCK_EX) === false) {
    fwrite(STDERR, "写入 centers/config.php 失败，请检查目录权限。\n");
    exit(1);
}
chmod($target, 0640);
// 网站进程（宝塔默认为 www 用户）必须能读这个文件。以 root 运行时把属主改为 www。
if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
    $web = function_exists('posix_getpwnam') ? posix_getpwnam('www') : false;
    if ($web) {
        @chown($target, (int) $web['uid']);
        @chgrp($target, (int) $web['gid']);
    } else {
        @chown($target, (int) fileowner($centers));
        @chgrp($target, (int) filegroup($centers));
    }
}

$ownerName = function_exists('posix_getpwuid') ? ((posix_getpwuid((int) fileowner($target)) ?: [])['name'] ?? '') : '';
fwrite(STDOUT, "已生成 centers/config.php（数据库 {$config['db']['name']}，账号 {$count} 个，文件属主 {$ownerName}，权限 640）。\n");
