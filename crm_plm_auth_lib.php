<?php
/**
 * Artdon CRM ↔ PLM 统一账号库
 * 账号来源：artdon_users
 * CRM 模块权限：crm_module_permissions
 */
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) session_start();

function crm_plm_s($v, int $max=5000): string {
    $v = trim((string)($v ?? ''));
    if ($max > 0 && mb_strlen($v, 'UTF-8') > $max) $v = mb_substr($v, 0, $max, 'UTF-8');
    return $v;
}
function crm_plm_pdo(): PDO {
    if (function_exists('db')) {
        $pdo = db();
        if ($pdo instanceof PDO) {
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
            return $pdo;
        }
    }
    $host = defined('DB_HOST') ? DB_HOST : (defined('MYSQL_HOST') ? MYSQL_HOST : 'localhost');
    $name = defined('DB_NAME') ? DB_NAME : (defined('MYSQL_DB') ? MYSQL_DB : '');
    $user = defined('DB_USER') ? DB_USER : (defined('MYSQL_USER') ? MYSQL_USER : '');
    $pass = defined('DB_PASS') ? DB_PASS : (defined('MYSQL_PASS') ? MYSQL_PASS : '');
    if ($name === '' || $user === '') throw new RuntimeException('数据库配置不完整，请检查 config.php。');
    return new PDO("mysql:host={$host};dbname={$name};charset=utf8mb4", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::MYSQL_ATTR_INIT_COMMAND => 'SET NAMES utf8mb4'
    ]);
}
function crm_plm_table_exists(PDO $pdo, string $table): bool {
    $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?");
    $st->execute([$table]);
    return (int)$st->fetchColumn() > 0;
}
function crm_plm_cols(PDO $pdo, string $table): array {
    $st = $pdo->prepare("SELECT COLUMN_NAME,DATA_TYPE,COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?");
    $st->execute([$table]);
    $out = [];
    foreach ($st->fetchAll() as $r) $out[$r['COLUMN_NAME']] = $r;
    return $out;
}
function crm_plm_choose(array $cols, array $names): ?string {
    foreach ($names as $n) if (isset($cols[$n])) return $n;
    return null;
}
function crm_plm_user_spec(PDO $pdo): array {
    if (!crm_plm_table_exists($pdo, 'artdon_users')) throw new RuntimeException('没有找到 artdon_users 表，请先确认 PLM/权限中心账号表存在。');
    $cols = crm_plm_cols($pdo, 'artdon_users');
    $username = crm_plm_choose($cols, ['username','account','user_name','login','email','name']);
    $password = crm_plm_choose($cols, ['password_hash','password','pass','pwd','user_pass','passwd']);
    $name = crm_plm_choose($cols, ['real_name','display_name','name','nickname','full_name','username','account']);
    $role = crm_plm_choose($cols, ['role','type','user_role','is_admin','admin','level']);
    $active = crm_plm_choose($cols, ['is_active','active','enabled','status']);
    $dept = crm_plm_choose($cols, ['department','dept','group_name','team']);
    $phone = crm_plm_choose($cols, ['phone','mobile','tel']);
    $id = crm_plm_choose($cols, ['id','user_id','uid']);
    if (!$username || !$password) throw new RuntimeException('artdon_users 缺少账号或密码字段。');
    return compact('cols','id','username','password','name','role','active','dept','phone');
}
function crm_plm_is_active_row(array $row, ?string $activeCol): bool {
    if (!$activeCol || !array_key_exists($activeCol, $row)) return true;
    $v = strtolower(trim((string)$row[$activeCol]));
    if ($v === '' || $v === '1' || $v === 'true' || $v === 'yes' || $v === 'active' || $v === 'enabled' || $v === 'enable' || $v === '启用' || $v === '正常') return true;
    if ($v === '0' || $v === 'false' || $v === 'no' || $v === 'disabled' || $v === 'disable' || $v === '停用' || $v === '禁用') return false;
    return true;
}
function crm_plm_verify_password(string $plain, string $stored): bool {
    if ($stored === '') return false;
    $info = password_get_info($stored);
    if (!empty($info['algo'])) {
        if (password_verify($plain, $stored)) return true;
    }
    if (hash_equals($stored, $plain)) return true;
    if (strlen($stored) === 32 && hash_equals(strtolower($stored), md5($plain))) return true;
    if (strlen($stored) === 40 && hash_equals(strtolower($stored), sha1($plain))) return true;
    return false;
}
function crm_plm_normalize_role($role, string $username=''): string {
    $r = strtolower((string)$role);
    $u = strtolower($username);
    if (in_array($u, ['boss','admin','administrator','owner'], true)) return 'boss';
    if (str_contains($r, 'boss') || str_contains($r, 'admin') || str_contains($r, 'super') || str_contains($r, '老板') || str_contains($r, '管理员') || str_contains($r, '超级')) return 'boss';
    if (str_contains($r, 'manager') || str_contains($r, '主管') || str_contains($r, '经理')) return 'manager';
    if (str_contains($r, 'sales') || str_contains($r, '业务') || str_contains($r, '销售')) return 'sales';
    if (str_contains($r, 'engineer') || str_contains($r, '工程')) return 'engineer';
    return 'staff';
}
function crm_plm_is_admin(array $u): bool {
    $r = strtolower((string)($u['role'] ?? ''));
    $raw = strtolower((string)($u['role_raw'] ?? ''));
    $un = strtolower((string)($u['username'] ?? ''));
    return in_array($un, ['boss','admin','administrator','owner'], true) || in_array($r, ['boss','admin','manager'], true) ||
        str_contains($raw, '老板') || str_contains($raw, '管理员') || str_contains($raw, 'admin');
}
function crm_plm_modules(): array {
    return [
        'dashboard' => '老板驾驶舱',
        'sales' => '销售人员状态',
        'customers' => '客户中心',
        'customer360' => '客户360°',
        'contacts' => '联系人中心',
        'projects' => '项目/商机',
        'samples' => '样品中心',
        'followups' => '跟进中心',
        'mail' => '邮件中心',
        'whatsapp' => 'WhatsApp',
        'marketing' => '推广中心',
        'plmDocs' => 'PLM资料转发',
        'quotes' => '报价联动',
        'orders' => '订单中心',
        'permissions' => '用户/权限',
        'settings' => '系统设置',
    ];
}
function crm_plm_default_permissions_for(array $u): array {
    $mods = crm_plm_modules();
    $role = strtolower((string)($u['role'] ?? 'staff'));
    $out = [];
    foreach ($mods as $k=>$label) $out[$k] = ['view'=>0,'edit'=>0];

    if (crm_plm_is_admin($u)) {
        foreach ($mods as $k=>$label) $out[$k] = ['view'=>1,'edit'=>1];
        return $out;
    }

    $base = ['customers','customer360','contacts','followups','mail','whatsapp'];
    $sales = ['dashboard','sales','customers','customer360','contacts','projects','samples','followups','mail','whatsapp','marketing','quotes','orders','plmDocs'];
    $engineer = ['customer360','contacts','projects','samples','followups','mail','plmDocs'];
    $allow = ($role === 'sales') ? $sales : (($role === 'engineer') ? $engineer : $base);

    foreach ($allow as $m) if (isset($out[$m])) $out[$m] = ['view'=>1,'edit'=>1];
    return $out;
}
function crm_plm_ensure_tables(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS `crm_module_permissions` (
        `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        `username` VARCHAR(80) NOT NULL DEFAULT '',
        `module` VARCHAR(80) NOT NULL DEFAULT '',
        `can_view` TINYINT(1) NOT NULL DEFAULT 0,
        `can_edit` TINYINT(1) NOT NULL DEFAULT 0,
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uk_crm_module_user` (`username`,`module`),
        KEY `idx_crm_module` (`module`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}
function crm_plm_format_user(PDO $pdo, array $row): array {
    $sp = crm_plm_user_spec($pdo);
    $username = crm_plm_s($row[$sp['username']] ?? '', 80);
    $name = crm_plm_s($sp['name'] ? ($row[$sp['name']] ?? '') : '', 120);
    if ($name === '') $name = $username;
    $roleRaw = $sp['role'] ? (string)($row[$sp['role']] ?? '') : '';
    $role = crm_plm_normalize_role($roleRaw, $username);
    return [
        'id' => (int)($sp['id'] ? ($row[$sp['id']] ?? 0) : 0),
        'username' => $username,
        'real_name' => $name,
        'name' => $name,
        'display_name' => $name,
        'role_raw' => $roleRaw,
        'role' => $role,
        'department' => crm_plm_s($sp['dept'] ? ($row[$sp['dept']] ?? '') : '', 120),
        'phone' => crm_plm_s($sp['phone'] ? ($row[$sp['phone']] ?? '') : '', 80),
        'is_admin' => crm_plm_is_admin(['username'=>$username,'role'=>$role,'role_raw'=>$roleRaw]) ? 1 : 0,
        'source' => 'PLM',
    ];
}
function crm_plm_find_user(PDO $pdo, string $username): ?array {
    $sp = crm_plm_user_spec($pdo);
    $st = $pdo->prepare("SELECT * FROM artdon_users WHERE `{$sp['username']}`=? LIMIT 1");
    $st->execute([$username]);
    $row = $st->fetch();
    if (!$row || !crm_plm_is_active_row($row, $sp['active'])) return null;
    return crm_plm_format_user($pdo, $row);
}
function crm_plm_login(PDO $pdo, string $username, string $password): array {
    $sp = crm_plm_user_spec($pdo);
    $st = $pdo->prepare("SELECT * FROM artdon_users WHERE `{$sp['username']}`=? LIMIT 1");
    $st->execute([$username]);
    $row = $st->fetch();
    if (!$row || !crm_plm_is_active_row($row, $sp['active'])) throw new RuntimeException('账号不存在或已停用。');
    $stored = (string)($row[$sp['password']] ?? '');
    if (!crm_plm_verify_password($password, $stored)) throw new RuntimeException('密码错误。');

    $u = crm_plm_format_user($pdo, $row);
    crm_plm_set_session($u);
    return crm_plm_user_with_permissions($pdo, $u);
}
function crm_plm_set_session(array $u): void {
    $_SESSION['crm_plm_user'] = $u;
    $_SESSION['crm_user'] = $u;
    $_SESSION['crm_user_id'] = (int)($u['id'] ?? 0);
    $_SESSION['crm_username'] = (string)($u['username'] ?? '');
    $_SESSION['artdon_user_id'] = (int)($u['id'] ?? 0);
    $_SESSION['artdon_username'] = (string)($u['username'] ?? '');
    $_SESSION['user_id'] = (int)($u['id'] ?? 0);
    $_SESSION['username'] = (string)($u['username'] ?? '');
    $_SESSION['user'] = $u;
}
function crm_plm_current_user(PDO $pdo): ?array {
    $username = '';
    if (!empty($_SESSION['crm_plm_user']['username'])) $username = (string)$_SESSION['crm_plm_user']['username'];
    elseif (!empty($_SESSION['crm_username'])) $username = (string)$_SESSION['crm_username'];
    elseif (!empty($_SESSION['artdon_username'])) $username = (string)$_SESSION['artdon_username'];
    elseif (!empty($_SESSION['username'])) $username = (string)$_SESSION['username'];
    if ($username === '') return null;
    $u = crm_plm_find_user($pdo, $username);
    if (!$u) return null;
    crm_plm_set_session($u);
    return crm_plm_user_with_permissions($pdo, $u);
}
function crm_plm_user_permissions(PDO $pdo, array $u): array {
    crm_plm_ensure_tables($pdo);
    $default = crm_plm_default_permissions_for($u);
    $st = $pdo->prepare("SELECT module,can_view,can_edit FROM crm_module_permissions WHERE username=?");
    $st->execute([(string)$u['username']]);
    $rows = $st->fetchAll();
    foreach ($rows as $r) {
        $m = (string)$r['module'];
        if (!isset($default[$m])) continue;
        $default[$m] = ['view'=>(int)$r['can_view'], 'edit'=>(int)$r['can_edit']];
    }
    if (crm_plm_is_admin($u)) {
        foreach ($default as $m=>$v) $default[$m] = ['view'=>1,'edit'=>1];
    }
    return $default;
}
function crm_plm_allowed_pages(array $perms): array {
    $out = [];
    foreach ($perms as $m=>$p) if (!empty($p['view'])) $out[] = $m;
    return $out;
}
function crm_plm_user_with_permissions(PDO $pdo, array $u): array {
    $perms = crm_plm_user_permissions($pdo, $u);
    $u['permissions'] = $perms;
    $u['allowed_pages'] = crm_plm_allowed_pages($perms);
    return $u;
}
function crm_plm_all_users(PDO $pdo): array {
    $sp = crm_plm_user_spec($pdo);
    $rows = $pdo->query("SELECT * FROM artdon_users")->fetchAll();
    $out = [];
    foreach ($rows as $row) {
        if (!crm_plm_is_active_row($row, $sp['active'])) continue;
        $u = crm_plm_format_user($pdo, $row);
        $u = crm_plm_user_with_permissions($pdo, $u);
        $out[] = $u;
    }
    usort($out, fn($a,$b)=>($b['is_admin'] <=> $a['is_admin']) ?: strcmp((string)$a['username'], (string)$b['username']));
    return $out;
}
function crm_plm_save_permissions(PDO $pdo, string $username, array $permissions): void {
    crm_plm_ensure_tables($pdo);
    if (!crm_plm_find_user($pdo, $username)) throw new RuntimeException('用户不存在：'.$username);
    $mods = crm_plm_modules();
    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare("INSERT INTO crm_module_permissions(username,module,can_view,can_edit,created_at,updated_at)
            VALUES(?,?,?,?,NOW(),NOW())
            ON DUPLICATE KEY UPDATE can_view=VALUES(can_view), can_edit=VALUES(can_edit), updated_at=NOW()");
        foreach ($mods as $m=>$label) {
            $p = $permissions[$m] ?? [];
            $view = !empty($p['view']) ? 1 : 0;
            $edit = !empty($p['edit']) ? 1 : 0;
            if ($edit) $view = 1;
            $st->execute([$username, $m, $view, $edit]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}
function crm_plm_action_to_module(string $action): array {
    $a = strtolower($action);
    $edit = preg_match('/^(save|delete|send|sync|import|update|create|add|remove)/', $a) === 1;
    if ($a === 'init') return ['module'=>'', 'edit'=>false];

    $map = [
        'customer' => 'customers',
        'contact' => 'contacts',
        'project' => 'projects',
        'sample' => 'samples',
        'follow' => 'followups',
        'task' => 'followups',
        'mail_account' => 'settings',
        'mail' => 'mail',
        'email' => 'mail',
        'whatsapp' => 'whatsapp',
        'marketing' => 'marketing',
        'quote' => 'quotes',
        'order' => 'orders',
        'user' => 'permissions',
        'permission' => 'permissions',
        'setting' => 'settings',
        'plm' => 'plmDocs',
    ];
    foreach ($map as $needle=>$module) {
        if (str_contains($a, $needle)) return ['module'=>$module, 'edit'=>$edit];
    }
    return ['module'=>'', 'edit'=>$edit];
}
function crm_plm_can(array $u, string $module, bool $edit=false): bool {
    if (crm_plm_is_admin($u)) return true;
    if ($module === '') return true;
    $perms = $u['permissions'] ?? [];
    $p = $perms[$module] ?? null;
    if (!$p) return false;
    return $edit ? !empty($p['edit']) : !empty($p['view']);
}
?>