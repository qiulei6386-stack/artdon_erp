<?php
/**
 * Artdon PLM <-> Dispatch V21.8D
 * 给 PLM 页面“关联派工”弹窗读取：
 * 1) 当前 PLM 项目相关派工任务
 * 2) 派工状态/进度日志
 * 3) 派工附件
 */
declare(strict_types=1);
/* ARTDON_SSO_GATE_V2_START */
require_once __DIR__.'/includes/artdon_sso_core.php';
artdon_sso_require_api('plm');
/* ARTDON_SSO_GATE_V2_END */

@ini_set('display_errors','0');
@ini_set('log_errors','1');
error_reporting(E_ALL);

if (session_status() === PHP_SESSION_NONE) {
    @session_name('ARTDON_SYS');
    @session_start();
}

if (file_exists(__DIR__ . '/plm_auth.php')) {
    require_once __DIR__ . '/plm_auth.php';
    if (function_exists('plm_auth_require')) {
        plm_auth_require('plm', 'view');
    }
}
if (file_exists(__DIR__ . '/config.php')) @require_once __DIR__ . '/config.php';

function pds_json(array $data, int $code=200): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
function pds_s($v, int $max=255): string {
    $v = trim((string)($v ?? ''));
    if ($max > 0 && function_exists('mb_strlen') && mb_strlen($v,'UTF-8') > $max) $v = mb_substr($v,0,$max,'UTF-8');
    elseif ($max > 0 && strlen($v) > $max) $v = substr($v,0,$max);
    return $v;
}
function pds_pdo(): PDO {
    if (function_exists('artdon_sso_db')) {
        $pdo = artdon_sso_db();
        if ($pdo instanceof PDO) return $pdo;
    }
    if (function_exists('plm_auth_pdo')) {
        $pdo = plm_auth_pdo();
        if ($pdo instanceof PDO) return $pdo;
    }
    if (function_exists('db')) {
        $pdo = db();
        if ($pdo instanceof PDO) return $pdo;
    }
    $host = defined('DB_HOST') ? DB_HOST : (defined('PLM_DB_HOST') ? PLM_DB_HOST : 'localhost');
    $name = defined('DB_NAME') ? DB_NAME : (defined('PLM_DB_NAME') ? PLM_DB_NAME : '');
    $user = defined('DB_USER') ? DB_USER : (defined('PLM_DB_USER') ? PLM_DB_USER : '');
    $pass = defined('DB_PASS') ? DB_PASS : (defined('PLM_DB_PASS') ? PLM_DB_PASS : '');
    if ($name === '' || $user === '') throw new RuntimeException('数据库配置不完整');
    return new PDO("mysql:host={$host};dbname={$name};charset=utf8mb4", $user, $pass, [
        PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
        PDO::MYSQL_ATTR_INIT_COMMAND=>'SET NAMES utf8mb4'
    ]);
}
function pds_table_exists(PDO $pdo, string $table): bool {
    $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?");
    $st->execute([$table]);
    return (int)$st->fetchColumn() > 0;
}
function pds_cols(PDO $pdo, string $table): array {
    $st = $pdo->prepare("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?");
    $st->execute([$table]);
    return array_fill_keys(array_column($st->fetchAll(), 'COLUMN_NAME'), true);
}
function pds_has(array $cols, string $col): bool { return isset($cols[$col]); }
function pds_status_label(string $s): string {
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
    ][$s] ?? ($s ?: '-');
}
function pds_action_label(string $a): string {
    return [
        'plm_create'=>'PLM生成',
        'create'=>'新增',
        'update_task'=>'修改任务',
        'comment'=>'进度记录',
        'start'=>'开始',
        'pause'=>'暂停',
        'resume'=>'恢复',
        'quick_done'=>'完成',
        'undo_done'=>'取消完成',
        'delete'=>'删除',
        'attachment'=>'上传附件',
        'attachment_delete'=>'删除附件'
    ][$a] ?? ($a ?: '-');
}
function pds_is_overdue(array $t): int {
    $s = (string)($t['status'] ?? '');
    if (in_array($s, ['done','cancelled','rejected'], true)) return 0;
    $due = (string)($t['due_at'] ?? '');
    if ($due === '' || $due === '0000-00-00 00:00:00') return 0;
    $ts = strtotime($due);
    return ($ts && $ts < time()) ? 1 : 0;
}

function pds_query_links(PDO $pdo, string $projectId): array {
    if (!pds_table_exists($pdo, 'plm_dispatch_links')) return [];
    $st = $pdo->prepare("SELECT * FROM plm_dispatch_links WHERE plm_project_id=? ORDER BY updated_at DESC,id DESC LIMIT 500");
    $st->execute([$projectId]);
    return $st->fetchAll();
}

function pds_query_tasks(PDO $pdo, string $projectId): array {
    if (!pds_table_exists($pdo, 'dispatch_tasks')) return [];
    $cols = pds_cols($pdo, 'dispatch_tasks');

    $select = "t.*";
    $join = "";
    if (pds_table_exists($pdo, 'dispatch_users')) {
        $join .= " LEFT JOIN dispatch_users au ON au.id=t.assigned_to LEFT JOIN dispatch_users cu ON cu.id=t.created_by";
        $select .= ", au.name assignee_name, au.username assignee_username, cu.name creator_name, cu.username creator_username";
    }

    $w = ["COALESCE(t.is_deleted,0)=0"];
    $args = [];
    if (pds_has($cols, 'linked_system')) {
        $w[] = "UPPER(COALESCE(t.linked_system,''))='PLM'";
    }
    if (pds_has($cols, 'linked_id')) {
        $w[] = "CAST(t.linked_id AS CHAR)=?";
        $args[] = $projectId;
    } elseif (pds_has($cols, 'linked_json')) {
        $w[] = "t.linked_json LIKE ?";
        $args[] = '%"project_id":"' . $projectId . '"%';
    } else {
        $w[] = "1=0";
    }

    $sql = "SELECT {$select} FROM dispatch_tasks t {$join} WHERE " . implode(" AND ", $w) . " ORDER BY t.id DESC LIMIT 500";
    $st = $pdo->prepare($sql);
    $st->execute($args);
    $rows = $st->fetchAll();

    return array_map(function($t) {
        $t['status_label'] = pds_status_label((string)($t['status'] ?? ''));
        $t['is_overdue'] = pds_is_overdue($t);
        $t['open_url'] = 'dispatch_todo.php?ver=218332&task_id=' . (int)$t['id'];
        return $t;
    }, $rows);
}
function pds_logs(PDO $pdo, array $ids): array {
    if (!$ids || !pds_table_exists($pdo, 'dispatch_task_logs')) return [];
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $join = "";
    $select = "l.*";
    if (pds_table_exists($pdo, 'dispatch_users')) {
        $join .= " LEFT JOIN dispatch_users u ON u.id=l.user_id";
        $select .= ", u.name user_name, u.username";
    }
    if (pds_table_exists($pdo, 'dispatch_tasks')) {
        $join .= " LEFT JOIN dispatch_tasks t ON t.id=l.task_id";
        $select .= ", t.title task_title, t.status task_status";
    }
    $st = $pdo->prepare("SELECT {$select} FROM dispatch_task_logs l {$join} WHERE l.task_id IN ({$ph}) ORDER BY l.id DESC LIMIT 500");
    $st->execute($ids);
    $rows = $st->fetchAll();
    foreach ($rows as &$r) {
        $r['action_label'] = pds_action_label((string)($r['action'] ?? ''));
        $r['new_label'] = pds_status_label((string)($r['new_status'] ?? ''));
    }
    return $rows;
}
function pds_attachments(PDO $pdo, array $ids): array {
    if (!$ids || !pds_table_exists($pdo, 'dispatch_attachments')) return [];
    $cols = pds_cols($pdo, 'dispatch_attachments');
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $join = "";
    $select = "a.*";
    if (pds_table_exists($pdo, 'dispatch_users')) {
        $join .= " LEFT JOIN dispatch_users u ON u.id=a.user_id";
        $select .= ", u.name user_name, u.username";
    }
    if (pds_table_exists($pdo, 'dispatch_tasks')) {
        $join .= " LEFT JOIN dispatch_tasks t ON t.id=a.task_id";
        $select .= ", t.title task_title";
    }
    $where = "a.task_id IN ({$ph})";
    if (pds_has($cols, 'is_deleted')) $where .= " AND COALESCE(a.is_deleted,0)=0";
    $st = $pdo->prepare("SELECT {$select} FROM dispatch_attachments a {$join} WHERE {$where} ORDER BY a.id DESC LIMIT 500");
    $st->execute($ids);
    $rows = $st->fetchAll();
    foreach ($rows as &$r) {
        $path = $r['file_url'] ?? $r['file_path'] ?? '';
        $r['file_path'] = str_replace('\\', '/', (string)$path);
        if (($r['file_name'] ?? '') === '' && ($r['original_name'] ?? '') !== '') $r['file_name'] = $r['original_name'];
    }
    return $rows;
}

try {
    $pdo = pds_pdo();
    $action = pds_s($_GET['action'] ?? $_POST['action'] ?? 'list', 40);
    if ($action !== 'list') pds_json(['ok'=>false,'error'=>'未知 action'], 404);

    $projectId = pds_s($_GET['project_id'] ?? $_POST['project_id'] ?? '', 80);
    if ($projectId === '') pds_json(['ok'=>false,'error'=>'缺少 project_id'], 400);

    $links = pds_query_links($pdo, $projectId);
    $tasks = pds_query_tasks($pdo, $projectId);
    $ids = array_values(array_filter(array_map(fn($x)=>(int)($x['id'] ?? 0), $tasks)));
    if (!$ids && $links) $ids = array_values(array_filter(array_map(fn($x)=>(int)($x['task_id'] ?? 0), $links)));
    $logs = pds_logs($pdo, $ids);
    $attachments = pds_attachments($pdo, $ids);

    $done = 0; $overdue = 0;
    $countBase = $links ?: $tasks;
    foreach ($countBase as $t) {
        if (($t['task_status'] ?? $t['status'] ?? '') === 'done' || !empty($t['is_done'])) $done++;
        if (!empty($t['is_overdue'])) $overdue++;
    }

    pds_json([
        'ok'=>true,
        'project_id'=>$projectId,
        'links'=>$links,
        'tasks'=>$tasks,
        'events'=>$logs,
        'logs'=>$logs,
        'attachments'=>$attachments,
        'counts'=>[
            'tasks'=>count($countBase),
            'done'=>$done,
            'overdue'=>$overdue,
            'attachments'=>count($attachments)
        ]
    ]);
} catch (Throwable $e) {
    pds_json(['ok'=>false,'error'=>$e->getMessage()], 500);
}
