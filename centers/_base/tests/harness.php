<?php
declare(strict_types=1);

/**
 * 契约测试夹具：不连正式库，用内存 SQLite 模拟正式库的账号权限表。
 *
 * - 建表语句取自仓库 sql/001_auth_permission_base.sql、sql/002_permission_requests.sql，
 *   按 SQLite 语法做机械转换，表名和字段与正式库一致；
 * - 正式库里后来补上的字段（crm_users.position、email 可空）在这里显式补上；
 * - 只加载新系统的类，不引用旧系统任何代码。
 *
 * 用法：require 本文件后调用 centers_test_reset() 得到干净的数据库。
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

error_reporting(E_ALL);
ini_set('display_errors', '1');
if (session_status() !== PHP_SESSION_ACTIVE) {
    @session_start();
}

$GLOBALS['centers_test_root'] = dirname(__DIR__, 3);
$GLOBALS['centers_test_failures'] = [];
$GLOBALS['centers_test_passed'] = 0;

require_once dirname(__DIR__) . '/autoload.php';

\Artdon\Centers\Base\Config::set([
    'db' => [],
    'app' => ['session_name' => 'ARTDON_CENTERS_TEST', 'login_max_failures' => 5, 'login_lock_minutes' => 15, 'timezone' => 'Asia/Shanghai'],
]);
date_default_timezone_set('Asia/Shanghai');

/** 测试里直接取连接。 */
function db(): PDO
{
    return \Artdon\Centers\Base\Db::pdo();
}

/** 把仓库里的 MySQL 建表语句转成 SQLite。只处理这几份文件用到的语法。 */
function centers_test_mysql_to_sqlite(string $sql): string
{
    $sql = preg_replace('/--[^\n]*/', '', $sql);
    $out = [];
    foreach (preg_split('/;\s*\n/', $sql) as $statement) {
        $statement = trim($statement);
        if (stripos($statement, 'CREATE TABLE') !== 0) {
            continue;
        }
        $lines = [];
        foreach (preg_split('/\n/', $statement) as $line) {
            $t = trim($line);
            if (preg_match('/^(KEY|INDEX)\s/i', $t)) {
                continue;
            }
            if (preg_match('/^UNIQUE KEY\s+\S+\s*(\(.*\))/i', $t, $m)) {
                $line = '  UNIQUE ' . $m[1] . (substr($t, -1) === ',' ? ',' : '');
            }
            $lines[] = $line;
        }
        $s = implode("\n", $lines);
        $s = preg_replace('/(BIG)?INT UNSIGNED AUTO_INCREMENT PRIMARY KEY/i', 'INTEGER PRIMARY KEY AUTOINCREMENT', $s);
        $s = preg_replace("/ENUM\([^)]*\)/i", 'TEXT', $s);
        $s = preg_replace('/\bJSON\b/i', 'TEXT', $s);
        $s = preg_replace('/\bUNSIGNED\b/i', '', $s);
        $s = preg_replace('/ON UPDATE CURRENT_TIMESTAMP/i', '', $s);
        $s = preg_replace('/\)\s*ENGINE=[^\n]*$/i', ')', $s);
        $s = preg_replace('/,(\s*\))\s*$/', '$1', $s);
        $out[] = $s;
    }
    return implode(";\n", $out) . ';';
}

/** 重建内存库并写入基础数据：两个部门、两个角色、一个停用部门。 */
function centers_test_reset(): PDO
{
    $pdo = new PDO('sqlite::memory:', null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $root = $GLOBALS['centers_test_root'];
    foreach (['sql/001_auth_permission_base.sql', 'sql/002_permission_requests.sql'] as $file) {
        $sql = (string) file_get_contents($root . '/' . $file);
        // 正式库中 email 已被旧代码 ensure_user_optional_email_schema() 改为可空
        $sql = str_replace('email VARCHAR(190) NOT NULL,', 'email VARCHAR(190) NULL,', $sql);
        $pdo->exec(centers_test_mysql_to_sqlite($sql));
    }
    // 正式库中由旧代码 ensure_user_profile_schema() 补上的字段
    $pdo->exec('ALTER TABLE crm_users ADD COLUMN position VARCHAR(120) NULL');

    $pdo->exec("INSERT INTO crm_departments (id, name, code, status, sort_order) VALUES (1, '外贸部', 'overseas_sales', 'active', 11), (2, '财务部', 'finance', 'active', 40), (3, '旧总部', 'headquarters', 'disabled', 1)");
    $pdo->exec("INSERT INTO crm_roles (id, role_key, role_name, status) VALUES (1, 'super_admin', '超级管理员', 'active'), (2, 'sales', '业务员', 'active')");
    \Artdon\Centers\Base\Db::set($pdo);
    \Artdon\Centers\Base\Permission::clearCache();
    \Artdon\Centers\Base\Auth::refresh();
    $_SESSION = [];
    return $pdo;
}

/** 插入一个用户，返回 id。 */
function centers_test_user(array $data = []): int
{
    $row = array_merge([
        'username' => 'wanglei',
        'password' => 'Artdon2026',
        'real_name' => '王磊',
        'email' => 'wanglei@example.com',
        'department_id' => 1,
        'role_id' => 2,
        'status' => 'active',
        'is_super_admin' => 0,
        'force_password_change' => 0,
    ], $data);
    db()->prepare('INSERT INTO crm_users (username, password_hash, real_name, email, department_id, role_id, status, is_super_admin, force_password_change) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')
        ->execute([$row['username'], password_hash($row['password'], PASSWORD_DEFAULT), $row['real_name'], $row['email'], $row['department_id'], $row['role_id'], $row['status'], $row['is_super_admin'], $row['force_password_change']]);
    return (int) db()->lastInsertId();
}

function check(bool $ok, string $message): void
{
    if ($ok) {
        $GLOBALS['centers_test_passed']++;
        fwrite(STDOUT, "通过  {$message}\n");
        return;
    }
    $GLOBALS['centers_test_failures'][] = $message;
    fwrite(STDOUT, "失败  {$message}\n");
}

/** 断言回调抛出指定异常类，返回异常对象。 */
function expect_throws(string $class, callable $fn, string $message): ?Throwable
{
    try {
        $fn();
    } catch (Throwable $e) {
        check($e instanceof $class, $message . '（抛出 ' . get_class($e) . '）');
        return $e;
    }
    check(false, $message . '（没有抛出异常）');
    return null;
}

function centers_test_finish(string $name): void
{
    $failed = count($GLOBALS['centers_test_failures']);
    fwrite(STDOUT, sprintf("\n%s：通过 %d 项，失败 %d 项\n", $name, $GLOBALS['centers_test_passed'], $failed));
    exit($failed === 0 ? 0 : 1);
}

centers_test_reset();
