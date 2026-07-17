<?php
/**
 * Artdon PLM -> Dispatch V21.8D
 * 支持：
 * 1) PLM 页面弹窗 AJAX 生成派工，不跳转新页面
 * 2) 兼容旧链接打开的普通页面
 * 3) 当前创建人优先使用 PLM 登录账号，不再错误显示 boss/unknown
 */
declare(strict_types=1);
/* ARTDON_SSO_GATE_V2_START */
require_once __DIR__.'/includes/artdon_sso_core.php';
artdon_sso_require_api('plm');
/* ARTDON_SSO_GATE_V2_END */


ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

if (session_status() === PHP_SESSION_NONE) session_start();

$config = __DIR__ . '/config.php';
if (file_exists($config)) require_once $config;

// V21.8D：PLM 生成派工时，创建人必须优先取 PLM 当前登录用户，不能误用派工旧 session。
$plmAuth = __DIR__ . '/plm_auth.php';
if (file_exists($plmAuth)) {
    require_once $plmAuth;
}

function pd_json(array $data, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
function pd_h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function pd_s($v, int $max = 5000): string {
    $v = trim((string)($v ?? ''));
    if ($max > 0 && mb_strlen($v, 'UTF-8') > $max) $v = mb_substr($v, 0, $max, 'UTF-8');
    return $v;
}
function pd_now(): string { return date('Y-m-d H:i:s'); }
function pd_pdo(): PDO {
    if (function_exists('artdon_sso_db')) {
        $pdo = artdon_sso_db();
        if ($pdo instanceof PDO) return $pdo;
    }
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
    if ($name === '' || $user === '') throw new RuntimeException('数据库配置不完整：请检查 config.php。');
    return new PDO("mysql:host={$host};dbname={$name};charset=utf8mb4", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::MYSQL_ATTR_INIT_COMMAND => 'SET NAMES utf8mb4'
    ]);
}
function pd_table_exists(PDO $pdo, string $table): bool {
    $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?");
    $st->execute([$table]);
    return (int)$st->fetchColumn() > 0;
}
function pd_columns(PDO $pdo, string $table): array {
    $st = $pdo->prepare("SELECT COLUMN_NAME, DATA_TYPE, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, EXTRA FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?");
    $st->execute([$table]);
    $out = [];
    foreach ($st->fetchAll() as $r) $out[$r['COLUMN_NAME']] = $r;
    return $out;
}
function pd_has(array $cols, string $col): bool { return isset($cols[$col]); }
function pd_is_int(array $cols, string $col): bool {
    $t = strtolower((string)($cols[$col]['DATA_TYPE'] ?? $cols[$col]['COLUMN_TYPE'] ?? ''));
    return in_array($t, ['tinyint','smallint','mediumint','int','integer','bigint','decimal','float','double'], true);
}
function pd_default_for(array $c) {
    $type = strtolower((string)($c['DATA_TYPE'] ?? $c['COLUMN_TYPE'] ?? ''));
    if (in_array($type, ['tinyint','smallint','mediumint','int','integer','bigint','decimal','float','double'], true)) return 0;
    if ($type === 'date') return date('Y-m-d');
    if (str_contains($type, 'time') || $type === 'timestamp' || $type === 'datetime') return pd_now();
    return '';
}
function pd_add(&$data, array $cols, array $names, $value, $intValue = null): void {
    foreach ($names as $n) {
        if (!pd_has($cols, $n)) continue;
        if (pd_is_int($cols, $n)) $data[$n] = (int)($intValue ?? $value);
        else $data[$n] = (string)$value;
    }
}
function pd_body(): array {
    $raw = file_get_contents('php://input');
    $j = $raw ? json_decode($raw, true) : null;
    if (is_array($j)) return array_merge($_GET, $_POST, $j);
    return array_merge($_GET, $_POST);
}
function pd_task_no(): string {
    return 'PLM-' . date('Ymd-His') . '-' . substr(bin2hex(random_bytes(3)), 0, 6);
}
function pd_role_is_boss(string $role, string $username = ''): bool {
    $r = strtolower($role);
    $u = strtolower($username);
    return in_array($u, ['boss','admin','administrator','owner'], true) ||
        str_contains($r, 'boss') || str_contains($r, 'admin') || str_contains($r, '老板') || str_contains($r, '管理');
}
function pd_all_users(PDO $pdo): array {
    if (!pd_table_exists($pdo, 'dispatch_users')) return [];
    $cols = pd_columns($pdo, 'dispatch_users');
    $select = ['id','username'];
    foreach (['name','role','department','is_active','hidden_at','last_seen_at'] as $c) if (pd_has($cols, $c)) $select[] = $c;
    $sql = "SELECT `" . implode("`,`", array_unique($select)) . "` FROM dispatch_users WHERE 1=1";
    if (pd_has($cols, 'is_active')) $sql .= " AND is_active=1";
    if (pd_has($cols, 'hidden_at')) $sql .= " AND (hidden_at IS NULL OR hidden_at='' OR hidden_at='0000-00-00 00:00:00')";
    $sql .= " ORDER BY role='boss' DESC, name ASC, username ASC";
    $rows = $pdo->query($sql)->fetchAll();
    $out = [];
    foreach ($rows as $r) {
        $out[] = [
            'id' => (int)($r['id'] ?? 0),
            'username' => (string)($r['username'] ?? ''),
            'name' => (string)($r['name'] ?? $r['username'] ?? ''),
            'role' => (string)($r['role'] ?? ''),
            'department' => (string)($r['department'] ?? ''),
        ];
    }
    return $out;
}
function pd_find_user(PDO $pdo, string $username): ?array {
    if ($username === '' || !pd_table_exists($pdo, 'dispatch_users')) return null;
    $st = $pdo->prepare("SELECT * FROM dispatch_users WHERE username=? LIMIT 1");
    $st->execute([$username]);
    $r = $st->fetch();
    return $r ?: null;
}
function pd_user_by_id(PDO $pdo, int $id): ?array {
    if ($id <= 0 || !pd_table_exists($pdo, 'dispatch_users')) return null;
    $st = $pdo->prepare("SELECT * FROM dispatch_users WHERE id=? LIMIT 1");
    $st->execute([$id]);
    $r = $st->fetch();
    return $r ?: null;
}

function pd_current_plm_user_from_session(): array {
    $candidates = [];

    if (function_exists('plm_auth_current_user')) {
        try {
            $u = plm_auth_current_user();
            if (is_array($u) && $u) $candidates[] = $u;
        } catch (Throwable $e) {}
    }
    if (function_exists('plm_current_user')) {
        try {
            $u = plm_current_user();
            if (is_array($u) && $u) $candidates[] = $u;
        } catch (Throwable $e) {}
    }

    foreach ([
        'plm_user',
        'plm_current_user',
        'user',
        'auth_user',
        'current_user'
    ] as $k) {
        if (!empty($_SESSION[$k]) && is_array($_SESSION[$k])) $candidates[] = $_SESSION[$k];
    }

    // 常见 PLM session 分散字段
    $loose = [];
    foreach ([
        'plm_username','username','user_name','login_user','account'
    ] as $k) {
        if (!empty($_SESSION[$k]) && is_string($_SESSION[$k])) {
            $loose['username'] = $_SESSION[$k];
            break;
        }
    }
    foreach (['plm_name','name','realname','display_name'] as $k) {
        if (!empty($_SESSION[$k]) && is_string($_SESSION[$k])) {
            $loose['name'] = $_SESSION[$k];
            break;
        }
    }
    foreach (['plm_role','role'] as $k) {
        if (!empty($_SESSION[$k]) && is_string($_SESSION[$k])) {
            $loose['role'] = $_SESSION[$k];
            break;
        }
    }
    if (!empty($loose['username']) || !empty($loose['name'])) $candidates[] = $loose;

    foreach ($candidates as $u) {
        $username = pd_s($u['username'] ?? $u['account'] ?? $u['login'] ?? $u['user_name'] ?? '', 80);
        $name = pd_s($u['name'] ?? $u['realname'] ?? $u['display_name'] ?? $username, 120);
        $role = pd_s($u['role'] ?? $u['user_role'] ?? '', 30);
        $dept = pd_s($u['department'] ?? $u['dept'] ?? '', 120);

        if ($username !== '' && !in_array(strtolower($username), ['当前plm用户','current','unknown','guest'], true)) {
            return ['username'=>$username, 'name'=>$name ?: $username, 'role'=>$role, 'department'=>$dept];
        }
        if ($name !== '' && !in_array(strtolower($name), ['当前plm用户','current','unknown','guest'], true)) {
            return ['username'=>$name, 'name'=>$name, 'role'=>$role, 'department'=>$dept];
        }
    }
    return [];
}
function pd_resolve_dispatch_user_by_plm(PDO $pdo, array $plmUser): ?array {
    if (!$plmUser || !pd_table_exists($pdo, 'dispatch_users')) return null;
    $username = pd_s($plmUser['username'] ?? '', 80);
    $name = pd_s($plmUser['name'] ?? '', 120);

    if ($username !== '') {
        $u = pd_find_user($pdo, $username);
        if ($u) return $u;
    }

    // 如果 PLM 名称与 dispatch_users.name 匹配，也认为是同一个人。
    if ($name !== '') {
        try {
            $st = $pdo->prepare("SELECT * FROM dispatch_users WHERE name=? OR username=? LIMIT 1");
            $st->execute([$name, $name]);
            $r = $st->fetch();
            if ($r) return $r;
        } catch (Throwable $e) {}
    }

    // 如果没有映射，创建一个 PLM 外部用户占位，避免落到旧 dispatch session。
    $username = $username ?: $name;
    if ($username !== '') {
        $now = pd_now();
        $role = pd_s($plmUser['role'] ?? '', 30);
        $dept = pd_s($plmUser['department'] ?? '', 120);
        $showName = $name ?: $username;
        $ins = $pdo->prepare("INSERT INTO dispatch_users(username,password_hash,name,role,department,external_source,is_active,created_at,updated_at) VALUES(?,?,?,?,?,'plm',1,?,?)");
        $ins->execute([$username, '', $showName, pd_role_is_boss($role, $username) ? 'boss' : 'staff', $dept, $now, $now]);
        return pd_user_by_id($pdo, (int)$pdo->lastInsertId());
    }
    return null;
}

function pd_find_or_create_creator(PDO $pdo, array $data): array {
    $username = pd_s($data['creator_username'] ?? '', 80);
    $name = pd_s($data['creator_name'] ?? '', 120);
    $role = pd_s($data['creator_role'] ?? '', 30);
    $dept = pd_s($data['creator_department'] ?? '', 120);

    // 1. V21.8D：优先用 PLM 当前登录用户。
    // 不能优先用 dispatch_user_id，因为同一浏览器可能上次登录过 suki，导致“派工来自”错成 suki。
    $plmUser = pd_current_plm_user_from_session();
    $u = pd_resolve_dispatch_user_by_plm($pdo, $plmUser);
    if ($u) return $u;

    // 2. 再用 PLM 页面明确传来的 creator_username，但忽略“当前PLM用户”这种占位字。
    $badPlaceholders = ['当前plm用户','当前PLM用户','current','unknown','guest'];
    if ($username !== '' && !in_array($username, $badPlaceholders, true)) {
        $u = pd_find_user($pdo, $username);
        if ($u) return $u;

        $now = pd_now();
        $ins = $pdo->prepare("INSERT INTO dispatch_users(username,password_hash,name,role,department,external_source,is_active,created_at,updated_at) VALUES(?,?,?,?,?,'plm',1,?,?)");
        $ins->execute([$username, '', $name ?: $username, pd_role_is_boss($role, $username) ? 'boss' : 'staff', $dept, $now, $now]);
        return pd_user_by_id($pdo, (int)$pdo->lastInsertId()) ?: [
            'id'=>(int)$pdo->lastInsertId(), 'username'=>$username, 'name'=>$name ?: $username, 'role'=>'staff', 'department'=>$dept
        ];
    }

    // 3. 最后才允许使用 dispatch session。
    $sid = (int)($_SESSION['dispatch_user_id'] ?? 0);
    if ($sid > 0) {
        $u = pd_user_by_id($pdo, $sid);
        if ($u) return $u;
    }

    // 4. 兜底：boss/admin，再第一个用户。
    $users = pd_all_users($pdo);
    foreach ($users as $u) {
        if (pd_role_is_boss((string)$u['role'], (string)$u['username'])) return $u;
    }
    if ($users) return $users[0];
    throw new RuntimeException('派工用户表为空，请先打开 dispatch_install.php 安装/同步账号。');
}
function pd_log(PDO $pdo, int $taskId, int $userId, string $action, string $note): void {
    try {
        if (!pd_table_exists($pdo, 'dispatch_task_logs')) return;
        $pdo->prepare("INSERT INTO dispatch_task_logs(task_id,user_id,action,note,created_at) VALUES(?,?,?,?,?)")
            ->execute([$taskId,$userId,$action,$note,pd_now()]);
    } catch (Throwable $e) {}
}
function pd_notify(PDO $pdo, int $recipient, int $sender, int $taskId, string $title, string $type, string $message): void {
    try {
        if ($recipient <= 0 || !pd_table_exists($pdo, 'dispatch_notifications')) return;
        $pdo->prepare("INSERT INTO dispatch_notifications(recipient_id,sender_id,task_id,type,title,message,is_read,created_at) VALUES(?,?,?,?,?,?,0,?)")
            ->execute([$recipient,$sender,$taskId,$type,$title,$message,pd_now()]);
    } catch (Throwable $e) {}
}
function pd_repair_old_plm(PDO $pdo): void {
    try {
        if (!pd_table_exists($pdo, 'dispatch_tasks')) return;
        $cols = pd_columns($pdo, 'dispatch_tasks');
        if (!pd_has($cols, 'source_type')) return;
        $sets = ["source_type='dispatch'"];
        if (pd_has($cols, 'linked_system')) $sets[] = "linked_system=IF(linked_system='', 'PLM', linked_system)";
        if (pd_has($cols, 'updated_at')) $sets[] = "updated_at=NOW()";
        $pdo->exec("UPDATE dispatch_tasks SET ".implode(',', $sets)." WHERE source_type='PLM'");
    } catch (Throwable $e) {}
}

function pd_label_status(string $v): string {
    return [
        'pending_accept'=>'待接收',
        'accepted'=>'已接收',
        'in_progress'=>'进行中',
        'paused'=>'暂停',
        'submitted'=>'待确认',
        'returned'=>'退回',
        'rejected'=>'驳回',
        'done'=>'已完成',
        'cancelled'=>'已取消'
    ][$v] ?? $v;
}
function pd_user_name(PDO $pdo, int $id): string {
    try {
        if ($id <= 0 || !pd_table_exists($pdo, 'dispatch_users')) return '';
        $st = $pdo->prepare("SELECT name,username FROM dispatch_users WHERE id=? LIMIT 1");
        $st->execute([$id]);
        $u = $st->fetch();
        if (!$u) return '';
        return (string)($u['name'] ?: $u['username'] ?: '');
    } catch (Throwable $e) { return ''; }
}
function pd_plm_link_ensure(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS `plm_dispatch_links` (
        `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        `plm_project_id` VARCHAR(80) NOT NULL DEFAULT '',
        `plm_project_name` VARCHAR(255) NOT NULL DEFAULT '',
        `task_id` BIGINT UNSIGNED NOT NULL DEFAULT 0,
        `task_no` VARCHAR(80) NOT NULL DEFAULT '',
        `task_title` VARCHAR(255) NOT NULL DEFAULT '',
        `task_status` VARCHAR(60) NOT NULL DEFAULT '',
        `status_label` VARCHAR(80) NOT NULL DEFAULT '',
        `source_type` VARCHAR(40) NOT NULL DEFAULT '',
        `priority` VARCHAR(40) NOT NULL DEFAULT '',
        `assigned_to` INT UNSIGNED NOT NULL DEFAULT 0,
        `assigned_to_name` VARCHAR(160) NOT NULL DEFAULT '',
        `created_by` INT UNSIGNED NOT NULL DEFAULT 0,
        `created_by_name` VARCHAR(160) NOT NULL DEFAULT '',
        `is_done` TINYINT(1) NOT NULL DEFAULT 0,
        `is_overdue` TINYINT(1) NOT NULL DEFAULT 0,
        `last_progress` MEDIUMTEXT DEFAULT NULL,
        `last_action` VARCHAR(80) NOT NULL DEFAULT '',
        `last_note` MEDIUMTEXT DEFAULT NULL,
        `last_event_at` DATETIME DEFAULT NULL,
        `due_at` DATETIME DEFAULT NULL,
        `completed_at` DATETIME DEFAULT NULL,
        `task_created_at` DATETIME DEFAULT NULL,
        `task_updated_at` DATETIME DEFAULT NULL,
        `dispatch_url` VARCHAR(500) NOT NULL DEFAULT '',
        `linked_json` MEDIUMTEXT DEFAULT NULL,
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uk_plm_dispatch_task` (`task_id`),
        KEY `idx_plm_dispatch_link_project` (`plm_project_id`,`task_status`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}
function pd_plm_link_upsert(PDO $pdo, int $taskId): void {
    try {
        if ($taskId <= 0) return;
        if (!pd_table_exists($pdo, 'dispatch_tasks')) return;
        $st = $pdo->prepare("SELECT * FROM dispatch_tasks WHERE id=? LIMIT 1");
        $st->execute([$taskId]);
        $t = $st->fetch();
        if (!$t) return;

        $json = json_decode((string)($t['linked_json'] ?? ''), true);
        if (!is_array($json)) $json = [];
        $projectId = (string)($t['linked_id'] ?? ($json['project_id'] ?? ''));
        $projectName = (string)($t['linked_title'] ?? ($json['project_name'] ?? ($t['project'] ?? '')));
        if ($projectId === '') return;

        pd_plm_link_ensure($pdo);

        $status = (string)($t['status'] ?? '');
        $due = (string)($t['due_at'] ?? '');
        $overdue = 0;
        if (!in_array($status, ['done','cancelled','rejected'], true) && $due !== '' && $due !== '0000-00-00 00:00:00') {
            $ts = strtotime($due);
            $overdue = ($ts && $ts < time()) ? 1 : 0;
        }
        $now = pd_now();
        $pdo->prepare("INSERT INTO plm_dispatch_links(
            plm_project_id,plm_project_name,task_id,task_no,task_title,task_status,status_label,source_type,priority,
            assigned_to,assigned_to_name,created_by,created_by_name,is_done,is_overdue,last_progress,last_action,last_note,last_event_at,
            due_at,completed_at,task_created_at,task_updated_at,dispatch_url,linked_json,created_at,updated_at
        ) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
        ON DUPLICATE KEY UPDATE
            plm_project_id=VALUES(plm_project_id),
            plm_project_name=VALUES(plm_project_name),
            task_no=VALUES(task_no),
            task_title=VALUES(task_title),
            task_status=VALUES(task_status),
            status_label=VALUES(status_label),
            source_type=VALUES(source_type),
            priority=VALUES(priority),
            assigned_to=VALUES(assigned_to),
            assigned_to_name=VALUES(assigned_to_name),
            created_by=VALUES(created_by),
            created_by_name=VALUES(created_by_name),
            is_done=VALUES(is_done),
            is_overdue=VALUES(is_overdue),
            last_action=VALUES(last_action),
            last_note=VALUES(last_note),
            last_event_at=VALUES(last_event_at),
            due_at=VALUES(due_at),
            completed_at=VALUES(completed_at),
            task_created_at=VALUES(task_created_at),
            task_updated_at=VALUES(task_updated_at),
            dispatch_url=VALUES(dispatch_url),
            linked_json=VALUES(linked_json),
            updated_at=VALUES(updated_at)")
            ->execute([
                $projectId,
                $projectName,
                $taskId,
                (string)($t['task_no'] ?? ''),
                (string)($t['title'] ?? ''),
                $status,
                pd_label_status($status),
                (string)($t['source_type'] ?? ''),
                (string)($t['priority'] ?? ''),
                (int)($t['assigned_to'] ?? 0),
                pd_user_name($pdo, (int)($t['assigned_to'] ?? 0)),
                (int)($t['created_by'] ?? 0),
                pd_user_name($pdo, (int)($t['created_by'] ?? 0)),
                $status === 'done' ? 1 : 0,
                $overdue,
                (string)($t['description'] ?? ''),
                'plm_create',
                '从 PLM 生成派工',
                $now,
                ($t['due_at'] ?? null) ?: null,
                $status === 'done' ? $now : null,
                ($t['created_at'] ?? null) ?: $now,
                ($t['updated_at'] ?? null) ?: $now,
                'dispatch_todo.php?ver=218332&task_id=' . $taskId,
                (string)($t['linked_json'] ?? ''),
                $now,
                $now
            ]);
    } catch (Throwable $e) {}
}


function pd_create_task(PDO $pdo, array $data): int {
    if (!pd_table_exists($pdo, 'dispatch_tasks')) throw new RuntimeException('没有找到 dispatch_tasks 表，请先运行 dispatch_install.php。');
    $cols = pd_columns($pdo, 'dispatch_tasks');
    $creator = pd_find_or_create_creator($pdo, $data);
    $creatorId = (int)$creator['id'];

    $mode = pd_s($data['mode'] ?? $data['kind'] ?? 'dispatch', 30);
    if ($mode === 'personal') $mode = 'self';
    if (!in_array($mode, ['dispatch','self','private'], true)) $mode = 'dispatch';

    $assigned = (int)($data['assigned_to'] ?? $data['assignee_id'] ?? 0);
    if ($mode === 'self' || $mode === 'private') $assigned = $creatorId;
    if ($assigned <= 0) throw new RuntimeException('请选择负责人 / 接收人。');
    $assignee = pd_user_by_id($pdo, $assigned);
    if (!$assignee) throw new RuntimeException('负责人不存在或已被隐藏。');

    $projectId = pd_s($data['project_id'] ?? '', 80);
    $projectName = pd_s($data['project_name'] ?? $data['project'] ?? '', 160);
    $customer = pd_s($data['customer'] ?? '', 160);
    $model = pd_s($data['model'] ?? $data['product_model'] ?? '', 160);
    $title = pd_s($data['title'] ?? '', 255);
    if ($title === '') $title = 'PLM项目处理：' . ($projectName ?: '未命名项目');

    $taskDate = pd_s($data['task_date'] ?? date('Y-m-d'), 20);
    $dueDate = pd_s($data['due_date'] ?? $taskDate, 20);
    $dueAt = preg_match('/^\d{4}-\d{2}-\d{2}$/', $dueDate) ? ($dueDate . ' 18:00:00') : $dueDate;

    $description = pd_s($data['description'] ?? '', 20000);
    if ($description === '') {
        $description = "来源：PLM\n项目：{$projectName}\n客户：{$customer}\n型号：{$model}";
    }

    $status = ($mode === 'dispatch' && $assigned !== $creatorId) ? 'pending_accept' : 'in_progress';
    $now = pd_now();

    $sort = 10;
    try {
        $st = $pdo->prepare("SELECT COALESCE(MAX(sort_order),0) FROM dispatch_tasks WHERE is_deleted=0 AND task_date=?");
        $st->execute([$taskDate]);
        $sort = (int)$st->fetchColumn() + 10;
    } catch (Throwable $e) { $sort = time(); }

    $linked = [
        'system'=>'PLM',
        'project_id'=>$projectId,
        'project_name'=>$projectName,
        'customer'=>$customer,
        'model'=>$model
    ];

    $row = [];
    pd_add($row,$cols,['task_no'],pd_task_no());
    pd_add($row,$cols,['title'], $title);
    pd_add($row,$cols,['description'], $description);
    pd_add($row,$cols,['task_type'], 'PLM');
    pd_add($row,$cols,['priority'], pd_s($data['priority'] ?? 'normal', 30));
    pd_add($row,$cols,['status'], $status);
    pd_add($row,$cols,['source_type'], $mode);
    pd_add($row,$cols,['boss_private'], $mode === 'private' ? 1 : 0, $mode === 'private' ? 1 : 0);
    pd_add($row,$cols,['created_by'], (string)$creatorId, $creatorId);
    pd_add($row,$cols,['assigned_to'], (string)$assigned, $assigned);
    pd_add($row,$cols,['helpers_json'], '[]');
    pd_add($row,$cols,['customer'], $customer);
    pd_add($row,$cols,['project'], $projectName);
    pd_add($row,$cols,['product_model'], $model);
    pd_add($row,$cols,['linked_system'], 'PLM');
    pd_add($row,$cols,['linked_table'], 'plm_projects');
    pd_add($row,$cols,['linked_id'], $projectId);
    pd_add($row,$cols,['linked_title'], $projectName);
    pd_add($row,$cols,['linked_url'], 'plm.php?project_id=' . rawurlencode($projectId));
    pd_add($row,$cols,['linked_json'], json_encode($linked, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    pd_add($row,$cols,['task_date'], $taskDate);
    pd_add($row,$cols,['extra_json'], json_encode(['from'=>'PLM弹窗生成'], JSON_UNESCAPED_UNICODE));
    pd_add($row,$cols,['due_at'], $dueAt);
    pd_add($row,$cols,['progress'], $status === 'in_progress' ? 0 : 0, 0);
    pd_add($row,$cols,['sort_order'], (string)$sort, $sort);
    pd_add($row,$cols,['created_at'], $now);
    pd_add($row,$cols,['updated_at'], $now);

    // 补齐 NOT NULL 且没有默认值的列，兼容旧表。
    foreach ($cols as $name => $info) {
        if ($name === 'id' || array_key_exists($name, $row)) continue;
        $extra = strtolower((string)($info['EXTRA'] ?? ''));
        if (str_contains($extra, 'auto_increment')) continue;
        $nullable = strtoupper((string)($info['IS_NULLABLE'] ?? 'YES'));
        $def = $info['COLUMN_DEFAULT'] ?? null;
        if ($nullable === 'YES' || $def !== null) continue;
        $row[$name] = pd_default_for($info);
    }
    unset($row['id']);

    $fields = array_keys($row);
    $sql = "INSERT INTO dispatch_tasks(`".implode("`,`",$fields)."`) VALUES(".implode(",", array_fill(0,count($fields),"?")).")";
    $pdo->prepare($sql)->execute(array_values($row));
    $taskId = (int)$pdo->lastInsertId();

    pd_log($pdo, $taskId, $creatorId, 'plm_create', '从 PLM 弹窗生成任务：' . $projectName);
    pd_plm_link_upsert($pdo, $taskId);
    if ($mode === 'dispatch' && $assigned !== $creatorId) {
        pd_notify($pdo, $assigned, $creatorId, $taskId, '收到 PLM 派工：' . $title, 'dispatch_received', '来自 PLM 项目：' . $projectName);
        pd_notify($pdo, $creatorId, $creatorId, $taskId, 'PLM 派工已送达：' . $title, 'dispatch_sent', '已送达给：' . (string)($assignee['name'] ?? $assignee['username'] ?? $assigned));
    }
    return $taskId;
}

try {
    $pdo = pd_pdo();
    pd_repair_old_plm($pdo);
    $data = pd_body();
    $action = pd_s($data['action'] ?? '', 40);
    $isAjax = (($data['ajax'] ?? '') === '1') || str_contains((string)($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json');

    if ($action === 'users') {
        pd_json(['ok'=>true,'users'=>pd_all_users($pdo)]);
    }
    if ($action === 'create') {
        $id = pd_create_task($pdo, $data);
        pd_json(['ok'=>true,'id'=>$id,'message'=>'已生成派工 / 待办','open_url'=>'dispatch_todo.php?ver=218332&task_id=' . $id]);
    }

    // 兼容旧表单提交
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $id = pd_create_task($pdo, $data);
        $successId = $id;
    } else {
        $successId = 0;
    }
} catch (Throwable $e) {
    if (($isAjax ?? false) || ($_GET['action'] ?? '') !== '') pd_json(['ok'=>false,'error'=>$e->getMessage()], 500);
    $pageError = $e->getMessage();
}

$project_id = $_GET['project_id'] ?? $_POST['project_id'] ?? '';
$project_name = $_GET['project_name'] ?? $_POST['project_name'] ?? '';
$customer = $_GET['customer'] ?? $_POST['customer'] ?? '';
$model = $_GET['model'] ?? $_POST['model'] ?? '';
$users = isset($pdo) ? pd_all_users($pdo) : [];
$today = date('Y-m-d');
?><!doctype html>
<html lang="zh-CN">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>PLM 生成派工</title>
<style>
body{margin:0;background:#f3f6fb;color:#111827;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI","Microsoft YaHei",Arial,sans-serif}.wrap{max-width:980px;margin:0 auto;padding:28px}.card{background:#fff;border:1px solid #dbe4ef;border-radius:28px;box-shadow:0 18px 50px rgba(15,23,42,.08);padding:26px}h1{margin:0 0 8px;font-size:34px}.sub{color:#64748b;font-weight:800;line-height:1.7}.grid{display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-top:18px}label{display:block;color:#64748b;font-size:13px;font-weight:900;margin:0 0 6px}input,select,textarea{width:100%;border:1px solid #dbe4ef;border-radius:14px;padding:12px 13px;font:inherit;outline:none;background:#fff}textarea{min-height:110px;resize:vertical}.full{grid-column:1/-1}.actions{display:flex;gap:10px;flex-wrap:wrap;margin-top:18px}.btn{border:1px solid #dbe4ef;background:#fff;border-radius:14px;padding:12px 18px;font-weight:1000;cursor:pointer;text-decoration:none;color:#111827;display:inline-flex}.btn.primary{background:#111827;color:#fff;border-color:#111827}.btn.blue{background:#2563eb;color:#fff;border-color:#2563eb}.info{background:#eff6ff;border:1px solid #bfdbfe;color:#1d4ed8;border-radius:18px;padding:14px;margin:16px 0;font-weight:850}.err{background:#fef2f2;border:1px solid #fecaca;color:#991b1b;border-radius:18px;padding:14px;margin:16px 0;font-weight:900}.ok{background:#ecfdf5;border:1px solid #bbf7d0;color:#166534;border-radius:18px;padding:14px;margin:16px 0;font-weight:900}@media(max-width:720px){.wrap{padding:14px}.grid{grid-template-columns:1fr}.card{padding:18px;border-radius:22px}h1{font-size:26px}}
</style>
</head>
<body><div class="wrap"><div class="card">
<h1>PLM → 生成派工</h1>
<div class="sub">推荐从 PLM 页面弹窗生成；此页面仅作为旧链接兼容。</div>
<?php if (!empty($pageError)): ?><div class="err">错误：<?=pd_h($pageError)?></div><?php endif; ?>
<?php if (!empty($successId)): ?>
<div class="ok">已生成派工 / 待办，任务 ID：<?=pd_h($successId)?></div>
<div class="actions"><a class="btn blue" href="dispatch_todo.php?ver=218332&task_id=<?=pd_h($successId)?>">打开派工待办</a><a class="btn" href="plm.php?project_id=<?=pd_h($project_id)?>">返回 PLM</a></div>
<?php else: ?>
<div class="info">项目：<?=pd_h($project_name ?: '-')?> ｜ 客户：<?=pd_h($customer ?: '-')?> ｜ 型号：<?=pd_h($model ?: '-')?></div>
<form method="post">
<input type="hidden" name="action" value="create">
<input type="hidden" name="project_id" value="<?=pd_h($project_id)?>">
<input type="hidden" name="project_name" value="<?=pd_h($project_name)?>">
<input type="hidden" name="customer" value="<?=pd_h($customer)?>">
<input type="hidden" name="model" value="<?=pd_h($model)?>">
<div class="grid">
<div><label>生成类型</label><select name="mode"><option value="dispatch">派工</option><option value="self">个人待办</option><option value="private">私人待办</option></select></div>
<div><label>负责人 / 接收人</label><select name="assigned_to"><?php foreach($users as $u): ?><option value="<?=pd_h($u['id'])?>"><?=pd_h($u['name'])?> · <?=pd_h($u['username'])?></option><?php endforeach; ?></select></div>
<div class="full"><label>任务标题</label><input name="title" value="<?=pd_h('PLM项目处理：' . ($project_name ?: '未命名项目'))?>"></div>
<div><label>待办日期</label><input type="date" name="task_date" value="<?=pd_h($today)?>"></div>
<div><label>截止日期</label><input type="date" name="due_date" value="<?=pd_h($today)?>"></div>
<div class="full"><label>说明</label><textarea name="description"><?=pd_h("来源：PLM\n项目：".$project_name."\n客户：".$customer."\n型号：".$model)?></textarea></div>
</div>
<div class="actions"><button class="btn primary" type="submit">生成</button><a class="btn" href="plm.php?project_id=<?=pd_h($project_id)?>">返回 PLM</a></div>
</form>
<?php endif; ?>
</div></div></body></html>
