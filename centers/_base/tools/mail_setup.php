<?php
declare(strict_types=1);

/**
 * 邮箱系统一次性设置（只在服务器命令行运行，可重复运行）：
 *
 *   php centers/_base/tools/mail_setup.php
 *
 * 1. 找出旧系统加密邮箱授权码用的种子（旧系统按 app_key → db_name → 程序目录 的顺序取），
 *    用已有邮箱逐个验证能否解开，把能解开的种子写进 centers/config.php 的 mail.key_seed。
 *    这样大家不用重新绑定邮箱；以后删除旧系统也不受影响。
 * 2. 补上新权限（已存在的不动）：
 *    mail.view_all          查看全部员工的邮件（只读，每次查看留日志）
 *    customer.mail_summary  客户详情里查看同事与这个客户的往来邮件（只显示主题和时间）
 *
 * 只读取旧配置文件里的这几个值，不加载旧系统任何代码。
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/autoload.php';

use Artdon\Centers\Base\Config;
use Artdon\Centers\Base\Db;
use Artdon\Centers\Mail\MailCrypto;

$target = Config::path();
if (!is_file($target)) {
    fwrite(STDERR, "缺少 centers/config.php，请先运行 php centers/_base/tools/init_config.php\n");
    exit(1);
}
$config = Config::all();
$pdo = Db::pdo();

// ---------- 1. 加密种子 ----------
$samples = $pdo->query("SELECT email_password_encrypted FROM crm_user_mail_accounts WHERE email_password_encrypted IS NOT NULL AND email_password_encrypted <> '' ORDER BY deleted_at IS NULL DESC, id DESC LIMIT 20")->fetchAll(PDO::FETCH_COLUMN);
$candidates = [];
if (!empty($config['mail']['key_seed'])) {
    $candidates[] = (string) $config['mail']['key_seed'];
}
$oldFile = ARTDON_APP_ROOT . '/includes/config.php';
if (is_file($oldFile)) {
    $old = (static function (string $file) {
        return require $file;
    })($oldFile);
    if (is_array($old)) {
        foreach (['app_key', 'db_name'] as $k) {
            if (isset($old[$k]) && is_scalar($old[$k]) && (string) $old[$k] !== '') {
                $candidates[] = (string) $old[$k];
            }
        }
    }
}
$candidates[] = ARTDON_APP_ROOT;
$candidates[] = (string) realpath(ARTDON_APP_ROOT);
$candidates[] = '/www/wwwroot/Artdon/artdon_erp';

if (!$samples) {
    $seed = $candidates[0];
    fwrite(STDOUT, "还没有人绑定邮箱，使用种子：程序目录。\n");
} else {
    $found = MailCrypto::detectSeed(array_map('strval', $samples), $candidates);
    if ($found === null) {
        fwrite(STDERR, '已有 ' . count($samples) . " 个邮箱的授权码，但所有候选种子都解不开。请把这个情况告诉开发人员，不要修改配置。\n");
        exit(1);
    }
    $seed = $found['seed'];
    fwrite(STDOUT, "种子验证通过：抽查 " . count($samples) . " 个邮箱，能解开 {$found['ok']} 个。\n");
}
if (($config['mail']['key_seed'] ?? '') !== $seed) {
    $config['mail'] = array_merge(is_array($config['mail'] ?? null) ? $config['mail'] : [], ['key_seed' => $seed]);
    $owner = fileowner($target);
    $group = filegroup($target);
    $perms = fileperms($target) & 0777;
    $content = "<?php\n// 新系统配置，不进 Git。mail.key_seed 由 centers/_base/tools/mail_setup.php 写入于 " . date('Y-m-d H:i:s') . "。\nreturn " . var_export($config, true) . ";\n";
    if (file_put_contents($target, $content, LOCK_EX) === false) {
        fwrite(STDERR, "写入 centers/config.php 失败，请检查权限。\n");
        exit(1);
    }
    @chmod($target, $perms ?: 0640);
    @chown($target, (int) $owner);
    @chgrp($target, (int) $group);
    fwrite(STDOUT, "已把加密种子写入 centers/config.php（mail.key_seed）。\n");
} else {
    fwrite(STDOUT, "centers/config.php 里已经有正确的加密种子。\n");
}

// ---------- 2. 新权限 ----------
$add = [
    ['mail.view_all', 'mail', 'view_all', '查看全部员工的邮件（只读，每次查看留日志）', 'high'],
    ['customer.mail_summary', 'customer', 'mail_summary', '客户详情里查看同事与客户的往来邮件（只显示主题和时间）', 'medium'],
];
$exists = $pdo->prepare('SELECT 1 FROM crm_permissions WHERE permission_key = ?');
$insert = $pdo->prepare('INSERT INTO crm_permissions (permission_key, module, action, description, risk_level) VALUES (?, ?, ?, ?, ?)');
foreach ($add as $p) {
    $exists->execute([$p[0]]);
    if ($exists->fetchColumn()) {
        fwrite(STDOUT, "权限 {$p[0]} 已存在。\n");
        continue;
    }
    $insert->execute($p);
    fwrite(STDOUT, "已新增权限 {$p[0]}（{$p[3]}），请在权限中心分配给需要的角色。\n");
}
fwrite(STDOUT, "完成。\n");
