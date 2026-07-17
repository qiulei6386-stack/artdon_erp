<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/artdon_sso_core.php';
require_once __DIR__ . '/dispatch_next_schema.php';
require_once __DIR__ . '/crm_log.php';

@ini_set('display_errors', '0');
@ini_set('log_errors', '1');
error_reporting(E_ALL);

$CURRENT_USER = artdon_sso_require_api('dispatch');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

function dn_ok($data = null): void
{
    echo json_encode(['ok' => true, 'data' => $data], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function dn_fail(string $error, int $code = 400): void
{
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $error], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function dn_input(): array
{
    $raw = file_get_contents('php://input');
    $json = $raw ? json_decode($raw, true) : [];
    return array_merge($_GET, $_POST, is_array($json) ? $json : []);
}

function dn_str($v, int $max = 5000): string
{
    $s = trim((string)($v ?? ''));
    return $max > 0 && mb_strlen($s, 'UTF-8') > $max ? mb_substr($s, 0, $max, 'UTF-8') : $s;
}

function dn_date($v, ?string $fallback = null): string
{
    $s = dn_str($v, 20);
    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $s) ? $s : ($fallback ?: date('Y-m-d'));
}

function dn_dt($v): ?string
{
    $s = dn_str($v, 30);
    if ($s === '') return null;
    $ts = strtotime($s);
    return $ts ? date('Y-m-d H:i:s', $ts) : null;
}

function dn_due_dt($v): ?string
{
    $s = dn_str($v, 30);
    if ($s === '') return null;
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $s)) return $s . ' 23:59:59';
    $ts = strtotime($s);
    return $ts ? date('Y-m-d H:i:s', $ts) : null;
}

function dn_required_due_dt($v, string $message = '派工待办必须填写截止日期'): string
{
    $due = dn_due_dt($v);
    if ($due === null) dn_fail($message);
    return $due;
}

function dn_due_is_overdue($v, string $status): bool
{
    if (empty($v) || in_array($status, ['done','cancelled'], true)) return false;
    return dn_due_has_passed($v);
}

function dn_due_ts($v): ?int
{
    if (empty($v)) return null;
    $s = trim((string)$v);
    $ts = strtotime($s);
    if (!$ts) return null;
    if (preg_match('/^\d{4}-\d{2}-\d{2}(?: 00:00:00)?$/', $s)) {
        $ts = strtotime(substr($s, 0, 10) . ' 23:59:59');
    }
    return $ts ?: null;
}

function dn_due_has_passed($v): bool
{
    $ts = dn_due_ts($v);
    return $ts !== null && $ts < time();
}

function dn_due_status($v, string $status): array
{
    if (empty($v) || in_array($status, ['done','cancelled'], true)) return ['state' => '', 'label' => ''];
    $ts = dn_due_ts($v);
    if ($ts === null) return ['state' => '', 'label' => ''];
    $now = time();
    if ($ts < $now) return ['state' => 'overdue', 'label' => '已逾期'];
    if ($ts - $now <= 7200) return ['state' => 'due_soon', 'label' => '2小时内到期'];
    if (date('Y-m-d', $ts) === date('Y-m-d', $now)) return ['state' => 'due_today', 'label' => '今日到期'];
    return ['state' => '', 'label' => ''];
}

function dn_json($v): string
{
    return json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function dn_user(): array
{
    global $CURRENT_USER;
    return $CURRENT_USER ?: [];
}

function dn_uid(): int
{
    return (int)(dn_user()['id'] ?? 0);
}

function dn_is_admin(): bool
{
    $u = dn_user();
    return artdon_sso_role_is_admin($u) || artdon_sso_can('dispatch', 'admin', $u);
}

function dn_can(string $cap): bool
{
    if (dn_is_admin() || artdon_sso_can('dispatch', $cap, dn_user())) return true;
    $aliases = [
        'create_personal' => ['create'],
        'create_private' => ['create'],
        'create_dispatch' => ['create'],
        'create_multi' => ['create'],
        'create_plan' => ['create'],
        'create_recurring' => ['create'],
        'edit_own' => ['edit'],
        'edit_assigned' => ['edit'],
        'edit_cell' => ['edit'],
        'change_status' => ['edit'],
        'change_assignee' => ['edit'],
        'change_due_date' => ['edit'],
        'comment' => ['edit'],
        'urge' => ['edit'],
        'upload_image' => ['edit'],
        'upload_attachment' => ['edit'],
        'delete_own' => ['delete'],
        'delete_all' => ['delete'],
        'delete_multi' => ['delete'],
        'restore' => ['edit', 'delete'],
        'backup' => ['export'],
        'view_logs' => ['admin'],
        'manage_visibility' => ['admin'],
        'manage_permissions' => ['admin'],
        'init_schema' => ['admin'],
        'custom_fields' => ['table_customize', 'edit'],
    ];
    foreach (($aliases[$cap] ?? []) as $fallback) {
        if (artdon_sso_can('dispatch', $fallback, dn_user())) return true;
    }
    return false;
}

function dn_require(string $cap, string $message): void
{
    if (!dn_can($cap)) dn_fail($message, 403);
}

function dn_can_edit_task(array $task, string $field = ''): bool
{
    if (dn_is_admin() || dn_can('edit_all')) return true;
    if ($field === 'due_at') return dn_can_change_due_at($task);
    return dn_can('edit') || dn_can('edit_cell') || dn_can('edit_own') || dn_can('edit_assigned');
}

function dn_can_change_due_at(array $task): bool
{
    $uid = dn_uid();
    if ($uid > 0 && (int)($task['created_by'] ?? 0) === $uid) return true;
    if ($uid > 0 && (int)($task['assigned_to'] ?? 0) === $uid && !dn_due_has_passed($task['due_at'] ?? null)) return true;
    return false;
}

function dn_users(): array
{
    $pdo = dispatch_next_db();
    if (!artdon_sso_table_exists('crm_users')) return [];
    $rows = $pdo->query("SELECT u.id, u.username, COALESCE(NULLIF(u.real_name,''), u.username) AS name, COALESCE(d.name,'') AS department, COALESCE(r.role_name,'') AS role_name, COALESCE(r.role_key,'') AS role, u.status
        FROM crm_users u
        LEFT JOIN crm_departments d ON d.id = u.department_id
        LEFT JOIN crm_roles r ON r.id = u.role_id
        WHERE u.status='active'
        ORDER BY d.sort_order, d.name, u.real_name, u.username")->fetchAll();
    foreach ($rows as &$r) {
        $r['id'] = (int)$r['id'];
        $r['role_key'] = artdon_sso_role_key($r);
        $r['role_label'] = artdon_sso_role_label($r['role_key']);
    }
    return $rows;
}

function dn_user_name(int $id): string
{
    static $map = null;
    if ($map === null) {
        $map = [];
        foreach (dn_users() as $u) $map[(int)$u['id']] = (string)$u['name'];
    }
    return $map[$id] ?? ('用户#' . $id);
}

function dn_visible_sql(string $alias = 't'): array
{
    $uid = dn_uid();
    $rules = dn_visibility_rule_for($uid);
    $deny = array_values(array_unique(array_map('intval', (array)($rules['deny'] ?? []))));
    $privateSql = "({$alias}.task_type <> 'private' OR {$alias}.created_by = ?)";
    if (dn_is_admin()) {
        if (!$deny) return [$privateSql, [$uid]];
        [$denySql, $denyParams] = dn_user_scope_sql($alias, $deny);
        return ["{$privateSql} AND NOT ({$denySql})", array_merge([$uid], $denyParams)];
    }
    $allow = array_values(array_unique(array_filter(array_merge([$uid], array_map('intval', (array)($rules['allow'] ?? []))), fn($v) => $v > 0)));
    [$allowSql, $allowParams] = dn_user_scope_sql($alias, $allow);
    if ($deny) {
        [$denySql, $denyParams] = dn_user_scope_sql($alias, $deny);
        return ["{$privateSql} AND ({$allowSql}) AND NOT ({$denySql})", array_merge([$uid], $allowParams, $denyParams)];
    }
    return ["{$privateSql} AND {$allowSql}", array_merge([$uid], $allowParams)];
}

function dn_visibility_rules(): array
{
    return (array)(dn_dispatch_permissions_get()['visibility'] ?? []);
}

function dn_visibility_rule_for(int $uid): array
{
    $rules = dn_visibility_rules();
    return is_array($rules[(string)$uid] ?? null) ? $rules[(string)$uid] : ['allow' => [], 'deny' => []];
}

function dn_normalize_dispatch_permissions($value): array
{
    if (is_string($value)) {
        $decoded = json_decode($value, true);
        $value = is_array($decoded) ? $decoded : [];
    }
    $value = is_array($value) ? $value : [];
    if (isset($value['permissions']) && is_array($value['permissions'])) {
        $value = $value['permissions'];
    }
    $visibility = [];
    foreach ((array)($value['visibility'] ?? []) as $viewerId => $rule) {
        $uid = (int)$viewerId;
        if ($uid <= 0 || !is_array($rule)) continue;
        $allow = array_values(array_unique(array_filter(array_map('intval', (array)($rule['allow'] ?? [])), fn($id) => $id > 0 && $id !== $uid)));
        $deny = array_values(array_unique(array_filter(array_map('intval', (array)($rule['deny'] ?? [])), fn($id) => $id > 0 && $id !== $uid)));
        $visibility[(string)$uid] = ['allow' => $allow, 'deny' => $deny];
    }
    return [
        'note' => dn_str($value['note'] ?? '', 2000),
        'visibility' => $visibility,
    ];
}

function dn_dispatch_permissions_get(): array
{
    return dn_normalize_dispatch_permissions(dn_global_prefs_get('dispatch_permissions', []));
}

function dn_dispatch_permissions_save($permissions): array
{
    $clean = dn_normalize_dispatch_permissions($permissions);
    dn_global_prefs_save('dispatch_permissions', $clean);
    return ['permissions' => $clean];
}

function dn_user_scope_sql(string $alias, array $userIds): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $userIds), fn($v) => $v > 0)));
    if (!$ids) return ['0=1', []];
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $parts = ["{$alias}.created_by IN ({$placeholders})", "{$alias}.assigned_to IN ({$placeholders})"];
    $params = array_merge($ids, $ids);
    foreach ($ids as $id) {
        $parts[] = "JSON_CONTAINS(COALESCE({$alias}.helper_ids_json,'[]'), JSON_QUOTE(CAST(? AS CHAR)))";
        $params[] = $id;
    }
    return ['(' . implode(' OR ', $parts) . ')', $params];
}

function dn_task_no(string $prefix = 'DN'): string
{
    return $prefix . date('YmdHis') . strtoupper(bin2hex(random_bytes(2)));
}

function dn_log(?int $taskId, string $action, ?string $field = null, $old = null, $new = null, string $note = ''): void
{
    $pdo = dispatch_next_db();
    $oldS = is_scalar($old) || $old === null ? (string)($old ?? '') : dn_json($old);
    $newS = is_scalar($new) || $new === null ? (string)($new ?? '') : dn_json($new);
    $note = dn_log_note($action, $field, $oldS, $newS, $note);
    if ($field && $taskId) {
        $st = $pdo->prepare("SELECT id, old_value FROM dispatch_next_logs WHERE task_id=? AND user_id=? AND field_name=? AND created_at >= DATE_SUB(NOW(), INTERVAL 5 MINUTE) ORDER BY id DESC LIMIT 1");
        $st->execute([$taskId, dn_uid(), $field]);
        $last = $st->fetch();
        if ($last) {
            $mergedOld = (string)($last['old_value'] ?? $oldS);
            $pdo->prepare("UPDATE dispatch_next_logs SET new_value=?, note=?, updated_at=NOW() WHERE id=?")->execute([$newS, dn_log_note($action, $field, $mergedOld, $newS, $note), (int)$last['id']]);
            return;
        }
    }
    $pdo->prepare("INSERT INTO dispatch_next_logs(task_id,user_id,action_type,field_name,old_value,new_value,note,ip,user_agent,created_at) VALUES(?,?,?,?,?,?,?,?,?,NOW())")
        ->execute([$taskId, dn_uid(), $action, $field, $oldS, $newS, $note, artdon_sso_ip(), substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255)]);
}

function dn_log_field_label(?string $field): string
{
    $map = [
        'title' => '任务标题',
        'project' => '项目 / 任务内容',
        'description' => '详细说明',
        'priority' => '优先级',
        'status' => '状态',
        'assigned_to' => '负责人',
        'due_at' => '截止日期',
        'task_date' => '任务日期',
        'progress' => '进度',
        'dispatch_mode' => '方式',
        'is_deleted' => '删除状态',
        'remind_count' => '催办次数',
        'step' => '执行步骤',
        'step_status' => '步骤状态',
        'child_task_id' => '子派工',
    ];
    if ($field && str_starts_with($field, 'custom_')) return '自定义列 ' . substr($field, 7);
    return $field ? ($map[$field] ?? $field) : '任务';
}

function dn_log_value_label(?string $field, string $value): string
{
    if ($value === '') return '空';
    if ($field === 'priority') return ['normal'=>'普通','important'=>'重要','urgent'=>'紧急','today'=>'今日'][$value] ?? $value;
    if ($field === 'status') return ['pending_accept'=>'待接收','accepted'=>'已接收','in_progress'=>'进行中','paused'=>'暂停','submitted'=>'待确认','returned'=>'退回','rejected'=>'拒绝','done'=>'已完成','cancelled'=>'已取消'][$value] ?? $value;
    if ($field === 'assigned_to' && ctype_digit($value)) {
        $st = dispatch_next_db()->prepare("SELECT COALESCE(NULLIF(real_name,''), username) FROM crm_users WHERE id=? LIMIT 1");
        $st->execute([(int)$value]);
        return (string)($st->fetchColumn() ?: ('用户#' . $value));
    }
    if ($field === 'progress') return $value . '%';
    return $value;
}

function dn_log_clip(string $value, int $max = 800): string
{
    $value = trim(preg_replace("/\r\n|\r/", "\n", $value));
    if (mb_strlen($value, 'UTF-8') <= $max) return $value;
    return mb_substr($value, 0, $max, 'UTF-8') . '...';
}

function dn_value_text($value, ?string $field = null): string
{
    $s = is_scalar($value) || $value === null ? (string)($value ?? '') : dn_json($value);
    return dn_log_clip(dn_log_value_label($field, $s), 120);
}

function dn_log_note(string $action, ?string $field, string $old, string $new, string $fallback = ''): string
{
    $label = dn_log_field_label($field);
    $oldText = dn_log_clip(dn_log_value_label($field, $old));
    $newText = dn_log_clip(dn_log_value_label($field, $new));
    if ($oldText === $newText && $fallback !== '') return $fallback;
    if (in_array($action, ['create','create_plan'], true)) return $fallback ?: '创建任务';
    if (str_starts_with($action, 'upload')) return ($fallback ?: '上传文件') . ($newText !== '空' ? '：' . $newText : '');
    if (in_array($action, ['delete','restore'], true)) return $fallback ?: ($action === 'delete' ? '删除任务' : '恢复任务');
    if (in_array($action, ['delete_attachment','delete_comment','step_delete'], true)) {
        return ($fallback ?: '删除记录') . ($oldText !== '空' ? '：' . $oldText : '');
    }
    if (mb_strlen($oldText, 'UTF-8') > 80 || mb_strlen($newText, 'UTF-8') > 80 || str_contains($oldText, "\n") || str_contains($newText, "\n")) {
        return $label . " 已修改\n从：\n" . $oldText . "\n改为：\n" . $newText;
    }
    return $label . '：从「' . $oldText . '」改为「' . $newText . '」';
}

function dn_notify(int $recipientId, ?int $taskId, string $type, string $title, string $message): void
{
    dispatch_next_db()->prepare("INSERT INTO dispatch_next_notifications(recipient_id,sender_id,task_id,type,title,message,created_at) VALUES(?,?,?,?,?,?,NOW())")
        ->execute([$recipientId, dn_uid(), $taskId, $type, $title, $message]);
}

function dn_notify_task_update_merged(int $recipientId, int $taskId, string $message): void
{
    $pdo = dispatch_next_db();
    $st = $pdo->prepare("SELECT id FROM dispatch_next_notifications WHERE recipient_id=? AND task_id=? AND type='urge' AND title='任务已更新' AND is_read=0 ORDER BY id DESC LIMIT 1");
    $st->execute([$recipientId, $taskId]);
    $noticeId = (int)($st->fetchColumn() ?: 0);
    if ($noticeId > 0) {
        $pdo->prepare("UPDATE dispatch_next_notifications SET sender_id=?, message=?, created_at=NOW(), read_at=NULL WHERE id=?")
            ->execute([dn_uid(), $message, $noticeId]);
        return;
    }
    dn_notify($recipientId, $taskId, 'urge', '任务已更新', $message);
}

function dn_merge_duplicate_update_notifications(?int $recipientId = null): array
{
    $pdo = dispatch_next_db();
    $uid = $recipientId ?: dn_uid();
    $st = $pdo->prepare("SELECT recipient_id, task_id, type, title, COUNT(*) AS c, MAX(id) AS keep_id FROM dispatch_next_notifications WHERE recipient_id=? AND task_id IS NOT NULL AND type='urge' AND title='任务已更新' GROUP BY recipient_id, task_id, type, title HAVING c>1 LIMIT 80");
    $st->execute([$uid]);
    $groups = $st->fetchAll();
    $merged = 0;
    foreach ($groups as $g) {
        $keepId = (int)$g['keep_id'];
        $count = (int)$g['c'];
        if ($keepId <= 0 || $count <= 1) continue;
        $msgSt = $pdo->prepare("SELECT message FROM dispatch_next_notifications WHERE id=? LIMIT 1");
        $msgSt->execute([$keepId]);
        $message = (string)($msgSt->fetchColumn() ?: '');
        $cleanMessage = trim((string)preg_replace('/\n?连续更新合并：共\s*\d+\s*条，已保留最新一条。/u', '', $message));
        if ($cleanMessage !== $message) $pdo->prepare("UPDATE dispatch_next_notifications SET message=? WHERE id=?")->execute([$cleanMessage, $keepId]);
        $pdo->prepare("UPDATE dispatch_next_notifications SET is_read=1, read_at=COALESCE(read_at,NOW()) WHERE recipient_id=? AND task_id=? AND type=? AND title=? AND id<>?")
            ->execute([(int)$g['recipient_id'], (int)$g['task_id'], (string)$g['type'], (string)$g['title'], $keepId]);
        $merged += $count - 1;
    }
    return ['groups' => count($groups), 'merged' => $merged];
}

function dn_task(int $id): array
{
    $pdo = dispatch_next_db();
    [$where, $params] = dn_visible_sql('t');
    $st = $pdo->prepare("SELECT t.* FROM dispatch_next_tasks t WHERE t.id=? AND {$where} LIMIT 1");
    $st->execute(array_merge([$id], $params));
    $row = $st->fetch();
    if (!$row) dn_fail('任务不存在或没有权限', 404);
    return $row;
}

function dn_can_delete_task(array $task): bool
{
    if (dn_is_admin() || dn_can('delete_all')) return true;
    if (($task['dispatch_mode'] ?? '') === 'multi' || (int)($task['parent_group_id'] ?? 0) > 0) return (int)$task['created_by'] === dn_uid();
    $uid = dn_uid();
    if ($task['task_type'] === 'personal' || $task['task_type'] === 'private') return (int)$task['created_by'] === $uid && dn_can('delete_own');
    return (int)$task['created_by'] === $uid && dn_can('delete_own');
}

function dn_insert_task(array $d): int
{
    if (dn_due_dt($d['due_at'] ?? null) === null) dn_fail('派工待办必须填写截止日期');
    $pdo = dispatch_next_db();
    $pdo->prepare("INSERT INTO dispatch_next_tasks(task_no,task_type,dispatch_mode,parent_group_id,title,project,description,priority,status,created_by,assigned_to,helper_ids_json,task_date,due_at,progress,is_read,linked_system,linked_table,linked_id,linked_title,linked_json,extra_json,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW(),NOW())")
        ->execute([
            dn_task_no($d['dispatch_mode'] === 'multi' ? 'MG' : 'DN'),
            $d['task_type'], $d['dispatch_mode'], $d['parent_group_id'] ?? null,
            $d['title'], $d['project'] ?? '', $d['description'] ?? '', $d['priority'] ?? 'normal',
            $d['status'], $d['created_by'], $d['assigned_to'], dn_json($d['helper_ids'] ?? []),
            $d['task_date'], $d['due_at'] ?? null, (int)($d['progress'] ?? 0),
            (int)($d['is_read'] ?? 0), $d['linked_system'] ?? null, $d['linked_table'] ?? null, $d['linked_id'] ?? null,
            $d['linked_title'] ?? null, dn_json($d['linked'] ?? []), dn_json($d['extra'] ?? []),
        ]);
    $id = (int)$pdo->lastInsertId();
    dn_log($id, 'create', null, '', '', '创建任务');
    if (($d['status'] ?? '') === 'pending_accept' && (int)$d['assigned_to'] !== (int)$d['created_by']) {
        dn_notify((int)$d['assigned_to'], $id, 'new_dispatch', '新派工待接收', (string)$d['title']);
    }
    return $id;
}

function dn_refresh_group(int $groupId): void
{
    if ($groupId <= 0) return;
    $pdo = dispatch_next_db();
    $st = $pdo->prepare("SELECT COUNT(*) total_count, SUM(status='done') done_count FROM dispatch_next_tasks WHERE parent_group_id=? AND is_deleted=0");
    $st->execute([$groupId]);
    $r = $st->fetch() ?: ['total_count' => 0, 'done_count' => 0];
    $status = (int)$r['total_count'] > 0 && (int)$r['total_count'] === (int)$r['done_count'] ? 'done' : 'active';
    $pdo->prepare("UPDATE dispatch_next_groups SET total_count=?, done_count=?, status=?, updated_at=NOW() WHERE id=?")->execute([(int)$r['total_count'], (int)$r['done_count'], $status, $groupId]);
}

function dn_list(array $in): array
{
    dn_maybe_auto_backup();
    dn_run_recurring(['date' => $in['date'] ?? date('Y-m-d')], false);
    $pdo = dispatch_next_db();
    $date = dn_date($in['date'] ?? null);
    [$vis, $params] = dn_visible_sql('t');
    $personalPersonIds = array_values(array_unique(array_filter(array_map('intval', (array)($in['personal_people_ids'] ?? $in['people_ids'] ?? [])), fn($v) => $v > 0)));
    $dispatchPersonIds = array_values(array_unique(array_filter(array_map('intval', (array)($in['dispatch_people_ids'] ?? $in['people_ids'] ?? [])), fn($v) => $v > 0)));
    $q = dn_str($in['q'] ?? '', 120);
    $today = date('Y-m-d');
    if ($q !== '') {
        $dateSql = "(t.task_no LIKE ? OR t.title LIKE ? OR t.project LIKE ? OR t.description LIKE ? OR cu.real_name LIKE ? OR cu.username LIKE ? OR au.real_name LIKE ? OR au.username LIKE ? OR t.status LIKE ? OR t.priority LIKE ? OR t.due_at LIKE ?)";
        $like = '%' . $q . '%';
        $dateParams = array_fill(0, 11, $like);
    } elseif ($date === $today) {
        $dateSql = "((t.status NOT IN ('done','cancelled') AND (t.task_date<=? OR t.status='pending_accept')) OR (t.status='done' AND DATE(COALESCE(t.completed_at,t.updated_at))=?) OR (t.status='cancelled' AND DATE(COALESCE(t.cancelled_at,t.updated_at))=?))";
        $dateParams = [$date, $date, $date];
    } else {
        $dateSql = "((t.status='done' AND DATE(COALESCE(t.completed_at,t.updated_at))=?) OR (t.status='cancelled' AND DATE(COALESCE(t.cancelled_at,t.updated_at))=?))";
        $dateParams = [$date, $date];
    }
    $sql = "SELECT t.*,
        cu.username AS creator_username, COALESCE(NULLIF(cu.real_name,''), cu.username) AS creator_name,
        au.username AS assignee_username, COALESCE(NULLIF(au.real_name,''), au.username) AS assignee_name,
        (SELECT COUNT(*) FROM dispatch_next_attachments a WHERE a.task_id=t.id AND a.is_deleted=0) AS attachment_count,
        (SELECT COUNT(*) FROM dispatch_next_attachments a WHERE a.task_id=t.id AND a.file_kind='image' AND a.is_deleted=0) AS image_count,
        (SELECT COUNT(*) FROM dispatch_next_attachments a WHERE a.task_id=t.id AND a.is_deleted=0 AND (a.expires_at IS NULL OR a.expires_at>NOW())) AS valid_attachment_count
        FROM dispatch_next_tasks t
        LEFT JOIN crm_users cu ON cu.id=t.created_by
        LEFT JOIN crm_users au ON au.id=t.assigned_to
        WHERE {$vis} AND t.is_deleted=0 AND {$dateSql}
        ORDER BY FIELD(t.priority,'urgent','today','important','normal'), t.sort_order, COALESCE(t.due_at,'2999-12-31'), t.id DESC";
    $st = $pdo->prepare($sql);
    $st->execute(array_merge($params, $dateParams));
    $personal = [];
    $dispatch = [];
    $done = [];
    $groupIds = [];
    $rows = $st->fetchAll();
    dn_attach_custom_values($rows);
    $orders = dn_task_orders();
    foreach ($rows as $r) {
        $r = dn_decorate_task($r);
        $r['user_sort_order'] = $orders[(int)$r['id']] ?? 0;
        $parentGroupId = (int)($r['parent_group_id'] ?? 0);
        if ($parentGroupId > 0) {
            if (dn_task_matches_people($r, $dispatchPersonIds)) $groupIds[$parentGroupId] = true;
            continue;
        }
        if ($r['status'] === 'done' || $r['status'] === 'cancelled') {
            if (dn_task_matches_people($r, $personalPersonIds) || dn_task_matches_people($r, $dispatchPersonIds) || (!$personalPersonIds && !$dispatchPersonIds)) $done[] = $r;
        }
        elseif ($r['task_type'] === 'personal' || $r['task_type'] === 'private') {
            if (dn_task_matches_people($r, $personalPersonIds)) $personal[] = $r;
        }
        else {
            if (!dn_task_matches_people($r, $dispatchPersonIds)) continue;
            $dispatch[] = $r;
        }
    }
    foreach (array_keys($groupIds) as $gid) {
        $g = dn_group_row($gid, $dispatchPersonIds);
        if ($g) $dispatch[] = $g;
    }
    $sorter = function ($a, $b): int {
        $ownerCmp = strcmp(dn_task_owner_sort_key($a), dn_task_owner_sort_key($b));
        if ($ownerCmp !== 0) return $ownerCmp;
        $orderCmp = ((int)($a['user_sort_order'] ?? 0) <=> (int)($b['user_sort_order'] ?? 0));
        if ($orderCmp !== 0) return $orderCmp;
        $priorityRank = ['urgent' => 10, 'today' => 20, 'important' => 30, 'normal' => 40];
        $priorityCmp = (($priorityRank[(string)($a['priority'] ?? 'normal')] ?? 50) <=> ($priorityRank[(string)($b['priority'] ?? 'normal')] ?? 50));
        if ($priorityCmp !== 0) return $priorityCmp;
        $dueA = trim((string)($a['due_at'] ?? '')) ?: '2999-12-31 23:59:59';
        $dueB = trim((string)($b['due_at'] ?? '')) ?: '2999-12-31 23:59:59';
        $dueCmp = strcmp($dueA, $dueB);
        if ($dueCmp !== 0) return $dueCmp;
        return strcmp((string)($a['sort_key'] ?? $a['title']), (string)($b['sort_key'] ?? $b['title']));
    };
    usort($personal, $sorter);
    usort($dispatch, $sorter);
    $pending = dn_pending_accept();
    $unreadDispatch = dn_unread_dispatch();
    return [
        'date' => $date,
        'personal' => $personal,
        'dispatch' => $dispatch,
        'done' => $done,
        'pending_accept' => $pending,
        'unread_dispatch' => $unreadDispatch,
        'notifications' => dn_notifications(false),
        'me' => dn_me_data(),
        'version' => dn_sync_version()['version'],
    ];
}

function dn_sync_version(): array
{
    $pdo = dispatch_next_db();
    $queries = [
        'dispatch_next_tasks' => "SELECT COALESCE(MAX(UNIX_TIMESTAMP(updated_at)),0) AS ts, COUNT(*) AS cnt, COALESCE(MAX(id),0) AS max_id FROM dispatch_next_tasks",
        'dispatch_next_groups' => "SELECT COALESCE(MAX(UNIX_TIMESTAMP(updated_at)),0) AS ts, COUNT(*) AS cnt, COALESCE(MAX(id),0) AS max_id FROM dispatch_next_groups",
        'dispatch_next_comments' => "SELECT COALESCE(MAX(UNIX_TIMESTAMP(created_at)),0) AS ts, COUNT(*) AS cnt, COALESCE(MAX(id),0) AS max_id FROM dispatch_next_comments",
        'dispatch_next_attachments' => "SELECT COALESCE(MAX(GREATEST(UNIX_TIMESTAMP(created_at),COALESCE(UNIX_TIMESTAMP(deleted_at),0))),0) AS ts, COUNT(*) AS cnt, COALESCE(MAX(id),0) AS max_id FROM dispatch_next_attachments",
        'dispatch_next_notifications' => "SELECT COALESCE(MAX(GREATEST(UNIX_TIMESTAMP(created_at),COALESCE(UNIX_TIMESTAMP(read_at),0))),0) AS ts, COUNT(*) AS cnt, COALESCE(MAX(id),0) AS max_id FROM dispatch_next_notifications",
        'dispatch_next_logs' => "SELECT COALESCE(MAX(GREATEST(UNIX_TIMESTAMP(created_at),COALESCE(UNIX_TIMESTAMP(updated_at),0))),0) AS ts, COUNT(*) AS cnt, COALESCE(MAX(id),0) AS max_id FROM dispatch_next_logs",
        'dispatch_next_task_values' => "SELECT COALESCE(MAX(UNIX_TIMESTAMP(updated_at)),0) AS ts, COUNT(*) AS cnt, COALESCE(MAX(id),0) AS max_id FROM dispatch_next_task_values",
        'dispatch_next_task_orders' => "SELECT COALESCE(MAX(UNIX_TIMESTAMP(updated_at)),0) AS ts, COUNT(*) AS cnt, COALESCE(MAX(id),0) AS max_id FROM dispatch_next_task_orders",
        'dispatch_next_custom_fields' => "SELECT COALESCE(MAX(UNIX_TIMESTAMP(updated_at)),0) AS ts, COUNT(*) AS cnt, COALESCE(MAX(id),0) AS max_id FROM dispatch_next_custom_fields",
    ];
    $parts = [];
    foreach ($queries as $table => $sql) {
        if (!artdon_sso_table_exists($table)) continue;
        $row = $pdo->query($sql)->fetch() ?: ['ts' => 0, 'cnt' => 0, 'max_id' => 0];
        $parts[] = $table . ':' . (int)$row['ts'] . ':' . (int)$row['cnt'] . ':' . (int)$row['max_id'];
    }
    return ['version' => hash('sha1', implode('|', $parts)), 'server_time' => date('Y-m-d H:i:s')];
}

function dn_attach_custom_values(array &$rows): void
{
    $ids = array_values(array_filter(array_map(fn($r) => (int)($r['id'] ?? 0), $rows)));
    if (!$ids) return;
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $st = dispatch_next_db()->prepare("SELECT task_id, field_key, field_value FROM dispatch_next_task_values WHERE task_id IN ({$placeholders})");
    $st->execute($ids);
    $values = [];
    foreach ($st->fetchAll() as $row) {
        $values[(int)$row['task_id']][(string)$row['field_key']] = (string)($row['field_value'] ?? '');
    }
    foreach ($rows as &$row) {
        $row['custom_values'] = $values[(int)$row['id']] ?? [];
    }
}

function dn_task_orders(): array
{
    $st = dispatch_next_db()->prepare("SELECT task_id, sort_order FROM dispatch_next_task_orders WHERE user_id=?");
    $st->execute([dn_uid()]);
    $map = [];
    foreach ($st->fetchAll() as $row) $map[(int)$row['task_id']] = (int)$row['sort_order'];
    return $map;
}

function dn_task_owner_sort_key(array $task): string
{
    if (!empty($task['is_group'])) {
        $children = is_array($task['children'] ?? null) ? $task['children'] : [];
        $ids = array_values(array_unique(array_filter(array_map(fn($row) => (int)($row['assigned_to'] ?? 0), $children), fn($id) => $id > 0)));
        sort($ids);
        if ($ids) return 'g:' . implode(',', $ids);
        return 'g:' . (string)($task['assignee_name'] ?? '多人');
    }
    $uid = (int)($task['assigned_to'] ?? 0);
    if ($uid > 0) return 'u:' . str_pad((string)$uid, 8, '0', STR_PAD_LEFT);
    return 'z:' . (string)($task['assignee_name'] ?? '');
}

function dn_task_matches_people(array $task, array $personIds): bool
{
    if (!$personIds) return true;
    $ids = array_map('intval', $personIds);
    if (in_array((int)($task['created_by'] ?? 0), $ids, true)) return true;
    if (in_array((int)($task['assigned_to'] ?? 0), $ids, true)) return true;
    $helpers = json_decode((string)($task['helper_ids_json'] ?? '[]'), true);
    foreach ((array)$helpers as $id) {
        if (in_array((int)$id, $ids, true)) return true;
    }
    return false;
}

function dn_decorate_task(array $r): array
{
    $r['id'] = (int)$r['id'];
    $r['created_by'] = (int)$r['created_by'];
    $r['assigned_to'] = (int)$r['assigned_to'];
    $r['progress'] = (int)$r['progress'];
    $r['is_read'] = (int)$r['is_read'];
    $r['remind_count'] = (int)$r['remind_count'];
    $r['attachment_count'] = (int)($r['attachment_count'] ?? 0);
    $r['image_count'] = (int)($r['image_count'] ?? 0);
    $r['valid_attachment_count'] = (int)($r['valid_attachment_count'] ?? 0);
    $r['assignee_name'] = (string)($r['assignee_name'] ?? dn_user_name($r['assigned_to']));
    $r['creator_name'] = (string)($r['creator_name'] ?? dn_user_name($r['created_by']));
    $r['method_label'] = dn_method_label($r['task_type'], $r['dispatch_mode']);
    $r['can_urge'] = ($r['task_type'] === 'dispatch' && (dn_is_admin() || (int)$r['created_by'] === dn_uid()));
    $r['can_delete'] = dn_can_delete_task($r);
    $r['is_overdue'] = dn_due_is_overdue($r['due_at'] ?? null, (string)$r['status']);
    $due = dn_due_status($r['due_at'] ?? null, (string)$r['status']);
    $r['due_state'] = $due['state'];
    $r['due_label'] = $due['label'];
    return $r;
}

function dn_method_label(string $type, string $mode): string
{
    if ($type === 'private') return '私人';
    if ($type === 'personal') return '个人';
    return ['multi' => '多人', 'plan' => '计划派工', 'recurring' => '周期派工', 'single' => '派工'][$mode] ?? '派工';
}

function dn_group_row(int $gid, array $personIds = []): ?array
{
    $pdo = dispatch_next_db();
    $st = $pdo->prepare("SELECT * FROM dispatch_next_groups WHERE id=? LIMIT 1");
    $st->execute([$gid]);
    $g = $st->fetch();
    if (!$g) return null;
    [$vis, $params] = dn_visible_sql('t');
    $peopleSql = '';
    $peopleParams = [];
    if ($personIds) {
        [$peopleScope, $peopleParams] = dn_user_scope_sql('t', $personIds);
        $peopleSql = " AND {$peopleScope}";
    }
    $st = $pdo->prepare("SELECT t.id,t.status,t.priority,t.assigned_to,t.progress,t.is_read FROM dispatch_next_tasks t WHERE t.parent_group_id=? AND t.is_deleted=0 AND {$vis}{$peopleSql} ORDER BY t.id");
    $st->execute(array_merge([$gid], $params, $peopleParams));
    $children = $st->fetchAll();
    $done = 0;
    $mineUnread = false;
    foreach ($children as $c) {
        if ($c['status'] === 'done') $done++;
        if ((int)$c['assigned_to'] === dn_uid() && !(int)$c['is_read']) $mineUnread = true;
    }
    $groupStatus = count($children) > 0 && $done === count($children) ? 'done' : 'in_progress';
    $due = dn_due_status($g['due_at'] ?? null, $groupStatus);
    return [
        'id' => 'g' . $gid,
        'group_id' => $gid,
        'is_group' => true,
        'title' => $g['title'],
        'project' => $g['project'],
        'description' => $g['description'],
        'priority' => $children[0]['priority'] ?? 'normal',
        'status' => $groupStatus,
        'task_date' => $g['task_date'],
        'due_at' => $g['due_at'],
        'due_state' => $due['state'],
        'due_label' => $due['label'],
        'is_overdue' => $due['state'] === 'overdue',
        'created_by' => (int)$g['created_by'],
        'creator_name' => dn_user_name((int)$g['created_by']),
        'assigned_to' => 0,
        'assignee_name' => '多人',
        'dispatch_mode' => $g['group_type'],
        'method_label' => $g['group_type'] === 'recurring' ? '周期派工' : '多人',
        'done_count' => $done,
        'total_count' => count($children),
        'progress' => count($children) ? (int)floor($done * 100 / count($children)) : 0,
        'is_read' => $mineUnread ? 0 : 1,
        'can_urge' => dn_is_admin() || (int)$g['created_by'] === dn_uid(),
        'can_delete' => dn_is_admin() || (int)$g['created_by'] === dn_uid(),
        'children' => array_map(fn($c) => ['id'=>(int)$c['id'],'assigned_to'=>(int)$c['assigned_to'],'assignee_name'=>dn_user_name((int)$c['assigned_to']),'status'=>$c['status'],'progress'=>(int)$c['progress']], $children),
        'sort_key' => '0-' . $g['title'],
    ];
}

function dn_pending_accept(): array
{
    $st = dispatch_next_db()->prepare("SELECT id,title,project,due_at,created_by,created_at,is_read FROM dispatch_next_tasks WHERE assigned_to=? AND status='pending_accept' AND is_deleted=0 ORDER BY created_at DESC LIMIT 30");
    $st->execute([dn_uid()]);
    $rows = $st->fetchAll();
    foreach ($rows as &$r) {
        $r['id'] = (int)$r['id'];
        $r['is_read'] = (int)$r['is_read'];
        $r['creator_name'] = dn_user_name((int)$r['created_by']);
    }
    return $rows;
}

function dn_unread_dispatch(): array
{
    $st = dispatch_next_db()->prepare("SELECT id,title,project,due_at,created_by,created_at,status,task_type,dispatch_mode FROM dispatch_next_tasks WHERE assigned_to=? AND is_read=0 AND is_deleted=0 AND status NOT IN ('done','cancelled') ORDER BY created_at DESC LIMIT 50");
    $st->execute([dn_uid()]);
    $rows = $st->fetchAll();
    foreach ($rows as &$r) {
        $r['id'] = (int)$r['id'];
        $r['creator_name'] = dn_user_name((int)$r['created_by']);
        $r['method_label'] = dn_method_label((string)$r['task_type'], (string)$r['dispatch_mode']);
    }
    return $rows;
}

function dn_detail(array $in): array
{
    dn_cleanup_expired_attachments();
    $id = (int)($in['id'] ?? $in['task_id'] ?? 0);
    $task = dn_decorate_task(dn_task($id));
    if ((int)$task['assigned_to'] === dn_uid() && !(int)$task['is_read']) dn_mark_task_read(['id' => $id], false);
    $pdo = dispatch_next_db();
    $comments = $pdo->prepare("SELECT c.*, COALESCE(NULLIF(u.real_name,''), u.username) AS user_name FROM dispatch_next_comments c LEFT JOIN crm_users u ON u.id=c.user_id WHERE c.task_id=? ORDER BY c.id DESC");
    $comments->execute([$id]);
    $attachments = $pdo->prepare("SELECT a.*, (a.expires_at IS NULL OR a.expires_at>NOW()) AS is_valid, COALESCE(NULLIF(u.real_name,''), u.username) AS user_name FROM dispatch_next_attachments a LEFT JOIN crm_users u ON u.id=a.user_id WHERE a.task_id=? AND a.is_deleted=0 ORDER BY a.id DESC");
    $attachments->execute([$id]);
    $steps = $pdo->prepare("SELECT *, COALESCE((SELECT NULLIF(real_name,'') FROM crm_users WHERE id=owner_id), (SELECT username FROM crm_users WHERE id=owner_id)) AS owner_name FROM dispatch_next_steps WHERE task_id=? ORDER BY sort_order,id");
    $steps->execute([$id]);
    $logs = $pdo->prepare("SELECT l.*, COALESCE(NULLIF(u.real_name,''), u.username) AS user_name FROM dispatch_next_logs l LEFT JOIN crm_users u ON u.id=l.user_id WHERE l.task_id=? ORDER BY l.id DESC LIMIT 80");
    $logs->execute([$id]);
    $group = null;
    $groupChildren = [];
    if ((int)($task['parent_group_id'] ?? 0) > 0) {
        $gst = $pdo->prepare("SELECT * FROM dispatch_next_groups WHERE id=? LIMIT 1");
        $gst->execute([(int)$task['parent_group_id']]);
        $group = $gst->fetch() ?: null;
        $cst = $pdo->prepare("SELECT t.id,t.title,t.status,t.progress,t.assigned_to,t.due_at,t.completed_at,COALESCE(NULLIF(u.real_name,''),u.username) AS assignee_name FROM dispatch_next_tasks t LEFT JOIN crm_users u ON u.id=t.assigned_to WHERE t.parent_group_id=? AND t.is_deleted=0 ORDER BY t.id");
        $cst->execute([(int)$task['parent_group_id']]);
        $groupChildren = $cst->fetchAll();
    }
    return ['task' => $task, 'comments' => $comments->fetchAll(), 'attachments' => $attachments->fetchAll(), 'steps' => $steps->fetchAll(), 'logs' => $logs->fetchAll(), 'group' => $group, 'group_children' => $groupChildren];
}

function dn_create_task(array $in): array
{
    $type = in_array(($in['task_type'] ?? 'personal'), ['personal','dispatch','private'], true) ? $in['task_type'] : 'personal';
    if ($type === 'dispatch') dn_require('create_dispatch', '没有新增派工权限');
    if ($type === 'personal') dn_require('create_personal', '没有新增个人权限');
    if ($type === 'private') dn_require('create_private', '没有新增私人权限');
    $uid = dn_uid();
    $assigned = $type === 'personal' || $type === 'private' ? $uid : (int)($in['assigned_to'] ?? 0);
    if ($assigned <= 0) dn_fail('请选择负责人');
    $title = dn_str($in['title'] ?? '', 240);
    if ($title === '') dn_fail('请输入任务标题');
    $dueAt = dn_required_due_dt($in['due_at'] ?? null);
    $id = dn_insert_task([
        'task_type' => $type,
        'dispatch_mode' => $type === 'dispatch' ? 'single' : 'single',
        'title' => $title,
        'project' => dn_str($in['project'] ?? '', 180),
        'description' => dn_str($in['description'] ?? '', 8000),
        'priority' => dn_priority($in['priority'] ?? 'normal'),
        'status' => $type === 'dispatch' && $assigned !== $uid ? 'pending_accept' : 'in_progress',
        'created_by' => $uid,
        'assigned_to' => $assigned,
        'task_date' => dn_date($in['task_date'] ?? null),
        'due_at' => $dueAt,
        'is_read' => $assigned === $uid ? 1 : 0,
    ]);
    return ['id' => $id];
}

function dn_task_updated_at(int $id): string
{
    $st = dispatch_next_db()->prepare("SELECT updated_at FROM dispatch_next_tasks WHERE id=? LIMIT 1");
    $st->execute([$id]);
    return (string)($st->fetchColumn() ?: '');
}

function dn_check_task_conflict(array $task, array $in): void
{
    $client = dn_str($in['client_updated_at'] ?? '', 30);
    if ($client === '') return;
    $server = (string)($task['updated_at'] ?? '');
    if ($server !== '' && $client !== $server) {
        dn_fail('这条派工已被别人更新，请刷新后再修改。', 409);
    }
}

function dn_notify_task_change(array $task, string $field, $old, $new): void
{
    if ((string)$old === (string)$new) return;
    $uid = dn_uid();
    $base = [(int)($task['created_by'] ?? 0), (int)($task['assigned_to'] ?? 0)];
    if ($field === 'assigned_to' && (int)$new > 0) $base[] = (int)$new;
    $recipients = array_values(array_unique(array_filter($base, fn($id) => $id > 0 && $id !== $uid)));
    if (!$recipients) return;
    $label = dn_log_field_label($field);
    $message = $label . '：' . dn_value_text($old, $field) . ' → ' . dn_value_text($new, $field);
    foreach ($recipients as $rid) {
        dn_notify_task_update_merged($rid, (int)$task['id'], $message);
    }
}

function dn_priority($v): string
{
    return in_array($v, ['normal','important','urgent','today'], true) ? $v : 'normal';
}

function dn_update_task(array $in): array
{
    $id = (int)($in['id'] ?? 0);
    $task = dn_task($id);
    dn_check_task_conflict($task, $in);
    $nextDue = array_key_exists('due_at', $in) ? dn_due_dt($in['due_at']) : dn_due_dt($task['due_at'] ?? null);
    if ($nextDue === null) dn_fail('派工待办必须填写截止日期');
    $allowed = ['title','project','description','priority','status','assigned_to','due_at','task_date','progress'];
    $sets = [];
    $params = [];
    $changes = [];
    foreach ($allowed as $f) {
        if (!array_key_exists($f, $in)) continue;
        if (!dn_can_edit_task($task, $f)) dn_fail('没有修改多人或该字段的权限', 403);
        $old = $task[$f] ?? '';
        $new = $in[$f];
        if ($f === 'title') $new = dn_str($new, 240);
        if ($f === 'project') $new = dn_str($new, 180);
        if ($f === 'description') $new = dn_str($new, 8000);
        if ($f === 'priority') $new = dn_priority($new);
        if ($f === 'status') $new = dn_status($new);
        if ($f === 'assigned_to') $new = (int)$new;
        if ($f === 'progress') $new = max(0, min(100, (int)$new));
        if ($f === 'due_at') $new = dn_due_dt($new);
        if ($f === 'task_date') $new = dn_date($new, (string)$task['task_date']);
        if ((string)$old === (string)$new) continue;
        $sets[] = "{$f}=?";
        $params[] = $new;
        $changes[] = [$f, $old, $new];
        dn_log($id, 'update', $f, $old, $new, '字段修改');
    }
    if (!$sets) return ['id' => $id, 'changed' => 0, 'updated_at' => (string)($task['updated_at'] ?? '')];
    if (isset($in['status'])) {
        $status = dn_status($in['status']);
        if ($status === 'accepted') $sets[] = "accepted_at=COALESCE(accepted_at,NOW())";
        if ($status === 'in_progress') {
            $sets[] = "started_at=COALESCE(started_at,NOW())";
            $sets[] = "completed_at=NULL";
            $sets[] = "cancelled_at=NULL";
        }
        if ($status === 'done') {
            $sets[] = "completed_at=NOW()";
            $sets[] = "progress=100";
            if ((int)$task['created_by'] !== dn_uid()) dn_notify((int)$task['created_by'], $id, 'done', '派工已完成', (string)$task['title']);
        }
        if ($status === 'cancelled') $sets[] = "cancelled_at=NOW()";
    }
    $params[] = $id;
    dispatch_next_db()->prepare("UPDATE dispatch_next_tasks SET " . implode(',', $sets) . ", updated_at=NOW() WHERE id=?")->execute($params);
    foreach ($changes as $change) dn_notify_task_change($task, $change[0], $change[1], $change[2]);
    dn_refresh_group((int)($task['parent_group_id'] ?? 0));
    return ['id' => $id, 'changed' => count($sets), 'updated_at' => dn_task_updated_at($id)];
}

function dn_status($v): string
{
    $all = ['pending_accept','accepted','in_progress','paused','submitted','returned','rejected','done','cancelled'];
    return in_array($v, $all, true) ? $v : 'in_progress';
}

function dn_delete_task(array $in): array
{
    $id = (int)($in['id'] ?? 0);
    $task = dn_task($id);
    if (!dn_can_delete_task($task)) dn_fail('没有删除该任务的权限', 403);
    dispatch_next_db()->prepare("UPDATE dispatch_next_tasks SET is_deleted=1, deleted_at=NOW(), deleted_by=?, updated_at=NOW() WHERE id=?")->execute([dn_uid(), $id]);
    dn_log($id, 'delete', 'is_deleted', 0, 1, '删除任务');
    dn_refresh_group((int)($task['parent_group_id'] ?? 0));
    return ['id' => $id];
}

function dn_restore_task(array $in): array
{
    $id = (int)($in['id'] ?? 0);
    dn_require('restore', '没有恢复权限');
    dispatch_next_db()->prepare("UPDATE dispatch_next_tasks SET is_deleted=0, deleted_at=NULL, deleted_by=NULL, updated_at=NOW() WHERE id=?")->execute([$id]);
    dn_log($id, 'restore', 'is_deleted', 1, 0, '恢复任务');
    return ['id' => $id];
}

function dn_create_multi(array $in): array
{
    dn_require('create_multi', '没有多人权限');
    $ids = array_values(array_unique(array_map('intval', (array)($in['assignee_ids'] ?? []))));
    $ids = array_filter($ids, fn($v) => $v > 0);
    if (!$ids) dn_fail('请选择执行人');
    $title = dn_str($in['title'] ?? '', 240);
    if ($title === '') dn_fail('请输入派工标题');
    $dueAt = dn_required_due_dt($in['due_at'] ?? null);
    $pdo = dispatch_next_db();
    $pdo->prepare("INSERT INTO dispatch_next_groups(group_no,group_type,title,project,description,created_by,assignee_ids_json,total_count,task_date,due_at,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,NOW(),NOW())")
        ->execute([dn_task_no('DG'), 'multi', $title, dn_str($in['project'] ?? '', 180), dn_str($in['description'] ?? '', 8000), dn_uid(), dn_json($ids), count($ids), dn_date($in['task_date'] ?? null), $dueAt]);
    $gid = (int)$pdo->lastInsertId();
    foreach ($ids as $aid) {
        dn_insert_task([
            'task_type' => 'dispatch', 'dispatch_mode' => 'multi', 'parent_group_id' => $gid,
            'title' => $title, 'project' => dn_str($in['project'] ?? '', 180), 'description' => dn_str($in['description'] ?? '', 8000),
            'priority' => dn_priority($in['priority'] ?? 'normal'), 'status' => $aid === dn_uid() ? 'in_progress' : 'pending_accept',
            'created_by' => dn_uid(), 'assigned_to' => $aid, 'task_date' => dn_date($in['task_date'] ?? null), 'due_at' => $dueAt,
            'is_read' => $aid === dn_uid() ? 1 : 0,
        ]);
    }
    dn_refresh_group($gid);
    return ['group_id' => $gid];
}

function dn_update_multi(array $in): array
{
    $gid = (int)($in['group_id'] ?? 0);
    if ($gid <= 0) dn_fail('缺少多人组 ID');
    $pdo = dispatch_next_db();
    $st = $pdo->prepare("SELECT * FROM dispatch_next_groups WHERE id=? LIMIT 1");
    $st->execute([$gid]);
    $g = $st->fetch();
    if (!$g) dn_fail('多人组不存在', 404);
    if (!dn_is_admin() && (int)$g['created_by'] !== dn_uid()) dn_fail('只有派工人可以修改多人组', 403);
    if (dn_due_dt(array_key_exists('due_at', $in) ? $in['due_at'] : ($g['due_at'] ?? null)) === null) dn_fail('派工待办必须填写截止日期');
    $fields = ['title' => 240, 'project' => 180, 'description' => 8000];
    $sets = [];
    $params = [];
    foreach ($fields as $f => $max) {
        if (!array_key_exists($f, $in)) continue;
        $new = dn_str($in[$f], $max);
        if ((string)$g[$f] === $new) continue;
        $sets[] = "{$f}=?";
        $params[] = $new;
        dn_log(null, 'update_multi', $f, $g[$f], $new, '修改多人组');
        $pdo->prepare("UPDATE dispatch_next_tasks SET {$f}=?, updated_at=NOW() WHERE parent_group_id=?")->execute([$new, $gid]);
    }
    if (array_key_exists('due_at', $in)) {
        $newDue = dn_required_due_dt($in['due_at']);
        if ((string)($g['due_at'] ?? '') !== (string)$newDue) {
            $sets[] = "due_at=?";
            $params[] = $newDue;
            dn_log(null, 'update_multi', 'due_at', $g['due_at'] ?? '', $newDue, '修改多人组截止时间');
            $pdo->prepare("UPDATE dispatch_next_tasks SET due_at=?, updated_at=NOW() WHERE parent_group_id=?")->execute([$newDue, $gid]);
        }
    }
    if ($sets) {
        $params[] = $gid;
        $pdo->prepare("UPDATE dispatch_next_groups SET " . implode(',', $sets) . ", updated_at=NOW() WHERE id=?")->execute($params);
    }
    dn_refresh_group($gid);
    return ['group_id' => $gid, 'changed' => count($sets)];
}

function dn_create_plan(array $in): array
{
    dn_require('create_plan', '没有计划派工权限');
    $in['task_type'] = 'dispatch';
    $in['task_date'] = dn_date($in['task_date'] ?? date('Y-m-d', strtotime('+1 day')));
    $id = dn_create_task($in)['id'];
    dispatch_next_db()->prepare("UPDATE dispatch_next_tasks SET dispatch_mode='plan' WHERE id=?")->execute([$id]);
    dn_log($id, 'create_plan', 'dispatch_mode', 'single', 'plan', '创建计划派工');
    return ['id' => $id];
}

function dn_create_recurring(array $in): array
{
    dn_require('create_recurring', '没有周期派工权限');
    $ids = array_values(array_filter(array_map('intval', (array)($in['assignee_ids'] ?? [])), fn($v) => $v > 0));
    if (!$ids) dn_fail('请选择执行人');
    $rule = [
        'freq' => in_array(($in['freq'] ?? 'daily'), ['daily','weekly','monthly'], true) ? $in['freq'] : 'daily',
        'weekdays' => array_values(array_map('intval', (array)($in['weekdays'] ?? []))),
        'monthdays' => array_values(array_map('intval', (array)($in['monthdays'] ?? []))),
        'start_date' => dn_date($in['start_date'] ?? null),
        'end_date' => dn_str($in['end_date'] ?? '', 10),
    ];
    $title = dn_str($in['title'] ?? '', 240);
    if ($title === '') dn_fail('请输入周期派工标题');
    $dueAt = dn_required_due_dt($in['due_at'] ?? null);
    $pdo = dispatch_next_db();
    $pdo->prepare("INSERT INTO dispatch_next_groups(group_no,group_type,title,project,description,created_by,assignee_ids_json,total_count,task_date,due_at,recurring_rule_json,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,NOW(),NOW())")
        ->execute([dn_task_no('DR'), 'recurring', $title, dn_str($in['project'] ?? '', 180), dn_str($in['description'] ?? '', 8000), dn_uid(), dn_json($ids), count($ids), $rule['start_date'], $dueAt, dn_json($rule)]);
    return ['group_id' => (int)$pdo->lastInsertId(), 'rule' => $rule];
}

function dn_run_recurring(array $in, bool $return = true): array
{
    if ($return) dn_require('create_recurring', '没有执行周期派工权限');
    $date = dn_date($in['date'] ?? null);
    $pdo = dispatch_next_db();
    $groups = $pdo->query("SELECT * FROM dispatch_next_groups WHERE group_type='recurring' AND is_active=1")->fetchAll();
    $created = 0;
    foreach ($groups as $g) {
        $rule = json_decode((string)($g['recurring_rule_json'] ?? '{}'), true) ?: [];
        if (!dn_rule_due($rule, $date)) continue;
        $st = $pdo->prepare("SELECT COUNT(*) FROM dispatch_next_tasks WHERE parent_group_id=? AND task_date=? AND is_deleted=0");
        $st->execute([(int)$g['id'], $date]);
        if ((int)$st->fetchColumn() > 0) continue;
        foreach (json_decode((string)$g['assignee_ids_json'], true) ?: [] as $aid) {
            dn_insert_task([
                'task_type' => 'dispatch', 'dispatch_mode' => 'recurring', 'parent_group_id' => (int)$g['id'],
                'title' => $g['title'], 'project' => $g['project'], 'description' => $g['description'],
                'priority' => 'normal', 'status' => (int)$aid === dn_uid() ? 'in_progress' : 'pending_accept',
                'created_by' => (int)$g['created_by'], 'assigned_to' => (int)$aid, 'task_date' => $date, 'due_at' => $g['due_at'], 'is_read' => 0,
            ]);
            $created++;
        }
        dn_refresh_group((int)$g['id']);
    }
    $data = ['date' => $date, 'created' => $created];
    if ($return) return $data;
    return $data;
}

function dn_rule_due(array $rule, string $date): bool
{
    if (!empty($rule['start_date']) && $date < $rule['start_date']) return false;
    if (!empty($rule['end_date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$rule['end_date']) && $date > $rule['end_date']) return false;
    $ts = strtotime($date);
    if (($rule['freq'] ?? 'daily') === 'daily') return true;
    if (($rule['freq'] ?? '') === 'weekly') return in_array((int)date('N', $ts), array_map('intval', $rule['weekdays'] ?? []), true);
    if (($rule['freq'] ?? '') === 'monthly') return in_array((int)date('j', $ts), array_map('intval', $rule['monthdays'] ?? []), true);
    return false;
}

function dn_add_comment(array $in): array
{
    dn_require('comment', '没有新增进度权限');
    $task = dn_task((int)($in['task_id'] ?? 0));
    $comment = dn_str($in['comment'] ?? '', 8000);
    if ($comment === '') dn_fail('请输入进度记录');
    $progress = array_key_exists('progress', $in) ? max(0, min(100, (int)$in['progress'])) : null;
    $pdo = dispatch_next_db();
    $pdo->prepare("INSERT INTO dispatch_next_comments(task_id,user_id,comment,progress,created_at) VALUES(?,?,?,?,NOW())")->execute([(int)$task['id'], dn_uid(), $comment, $progress]);
    $cid = (int)$pdo->lastInsertId();
    if ($progress !== null && $progress !== (int)$task['progress']) {
        $pdo->prepare("UPDATE dispatch_next_tasks SET progress=?, updated_at=NOW() WHERE id=?")->execute([$progress, (int)$task['id']]);
        dn_log((int)$task['id'], 'update', 'progress', $task['progress'], $progress, '进度记录更新');
    }
    dn_log((int)$task['id'], 'comment', null, '', $comment, '新增进度记录');
    return ['comment_id' => $cid];
}

function dn_delete_comment(array $in): array
{
    $id = (int)($in['comment_id'] ?? 0);
    $pdo = dispatch_next_db();
    $st = $pdo->prepare("SELECT * FROM dispatch_next_comments WHERE id=? LIMIT 1");
    $st->execute([$id]);
    $c = $st->fetch();
    if (!$c) dn_fail('进度记录不存在', 404);
    dn_task((int)$c['task_id']);
    if ((int)$c['user_id'] !== dn_uid() && !dn_is_admin()) dn_fail('没有删除该进度记录的权限', 403);
    $pdo->prepare("DELETE FROM dispatch_next_comments WHERE id=?")->execute([$id]);
    dn_log((int)$c['task_id'], 'delete_comment', null, $c['comment'], '', '删除进度记录');
    return ['comment_id' => $id];
}

function dn_save_progress(array $in): array
{
    $task = dn_task((int)($in['task_id'] ?? 0));
    if (!dn_can_edit_task($task, 'progress')) dn_fail('没有修改进度权限', 403);
    $progress = max(0, min(100, (int)($in['progress'] ?? 0)));
    dispatch_next_db()->prepare("UPDATE dispatch_next_tasks SET progress=?, updated_at=NOW() WHERE id=?")->execute([$progress, (int)$task['id']]);
    dn_log((int)$task['id'], 'update', 'progress', $task['progress'], $progress, '保存进度百分比');
    dn_refresh_group((int)($task['parent_group_id'] ?? 0));
    return ['task_id' => (int)$task['id'], 'progress' => $progress];
}

function dn_set_task_status(array $in, string $status): array
{
    $task = dn_task((int)($in['id'] ?? $in['task_id'] ?? 0));
    if (!dn_can_edit_task($task, 'status')) dn_fail('没有修改状态权限', 403);
    $old = (string)$task['status'];
    $new = dn_status($status);
    $sets = ['status=?', 'updated_at=NOW()'];
    $params = [$new];
    if ($new === 'accepted') $sets[] = "accepted_at=COALESCE(accepted_at,NOW())";
    if ($new === 'in_progress') $sets[] = "started_at=COALESCE(started_at,NOW())";
    if ($new === 'done') {
        $sets[] = "completed_at=NOW()";
        $sets[] = "progress=100";
    }
    if ($new === 'cancelled') $sets[] = "cancelled_at=NOW()";
    $params[] = (int)$task['id'];
    dispatch_next_db()->prepare("UPDATE dispatch_next_tasks SET " . implode(',', $sets) . " WHERE id=?")->execute($params);
    dn_log((int)$task['id'], 'status', 'status', $old, $new, '状态快捷操作');
    dn_refresh_group((int)($task['parent_group_id'] ?? 0));
    return ['task_id' => (int)$task['id'], 'status' => $new];
}

function dn_save_step(array $in): array
{
    dn_require('edit', '没有编辑执行步骤权限');
    $task = dn_task((int)($in['task_id'] ?? 0));
    $id = (int)($in['step_id'] ?? 0);
    $name = dn_str($in['step_name'] ?? '', 240);
    if ($name === '') dn_fail('请输入步骤名称');
    $owner = (int)($in['owner_id'] ?? 0) ?: null;
    $status = in_array(($in['status'] ?? 'pending'), ['pending','in_progress','blocked','done','cancelled'], true) ? $in['status'] : 'pending';
    $due = dn_dt($in['due_at'] ?? null);
    $note = dn_str($in['note'] ?? '', 3000);
    $sort = (int)($in['sort_order'] ?? 0);
    $pdo = dispatch_next_db();
    if ($id > 0) {
        $old = $pdo->prepare("SELECT * FROM dispatch_next_steps WHERE id=? AND task_id=? LIMIT 1");
        $old->execute([$id, (int)$task['id']]);
        if (!$old->fetch()) dn_fail('步骤不存在', 404);
        $pdo->prepare("UPDATE dispatch_next_steps SET step_name=?, owner_id=?, status=?, due_at=?, note=?, sort_order=?, updated_at=NOW() WHERE id=?")->execute([$name, $owner, $status, $due, $note, $sort, $id]);
        dn_log((int)$task['id'], 'step_update', 'step', $id, $name, '修改执行步骤');
    } else {
        $pdo->prepare("INSERT INTO dispatch_next_steps(task_id,step_name,owner_id,status,due_at,note,sort_order,created_at,updated_at) VALUES(?,?,?,?,?,?,?,NOW(),NOW())")->execute([(int)$task['id'], $name, $owner, $status, $due, $note, $sort]);
        $id = (int)$pdo->lastInsertId();
        dn_log((int)$task['id'], 'step_create', 'step', '', $name, '新增执行步骤');
    }
    return ['step_id' => $id];
}

function dn_delete_step(array $in): array
{
    dn_require('edit', '没有删除执行步骤权限');
    $id = (int)($in['step_id'] ?? 0);
    $pdo = dispatch_next_db();
    $st = $pdo->prepare("SELECT * FROM dispatch_next_steps WHERE id=? LIMIT 1");
    $st->execute([$id]);
    $s = $st->fetch();
    if (!$s) dn_fail('步骤不存在', 404);
    dn_task((int)$s['task_id']);
    $pdo->prepare("DELETE FROM dispatch_next_steps WHERE id=?")->execute([$id]);
    dn_log((int)$s['task_id'], 'step_delete', 'step', $s['step_name'], '', '删除执行步骤');
    return ['step_id' => $id];
}

function dn_reorder_steps(array $in): array
{
    dn_require('edit', '没有排序执行步骤权限');
    $task = dn_task((int)($in['task_id'] ?? 0));
    $ids = array_values(array_filter(array_map('intval', (array)($in['step_ids'] ?? [])), fn($v) => $v > 0));
    $st = dispatch_next_db()->prepare("UPDATE dispatch_next_steps SET sort_order=?, updated_at=NOW() WHERE id=? AND task_id=?");
    foreach ($ids as $i => $id) $st->execute([($i + 1) * 10, $id, (int)$task['id']]);
    dn_log((int)$task['id'], 'step_reorder', 'step', '', implode(',', $ids), '调整执行步骤顺序');
    return ['task_id' => (int)$task['id']];
}

function dn_set_step_status(array $in, string $status): array
{
    dn_require('edit', '没有修改执行步骤权限');
    $id = (int)($in['step_id'] ?? 0);
    $pdo = dispatch_next_db();
    $st = $pdo->prepare("SELECT * FROM dispatch_next_steps WHERE id=? LIMIT 1");
    $st->execute([$id]);
    $s = $st->fetch();
    if (!$s) dn_fail('步骤不存在', 404);
    dn_task((int)$s['task_id']);
    $new = in_array($status, ['pending','in_progress','blocked','done','cancelled'], true) ? $status : 'pending';
    $extra = $new === 'done' ? ", completed_at=NOW()" : ($new === 'in_progress' ? ", started_at=COALESCE(started_at,NOW())" : '');
    $pdo->prepare("UPDATE dispatch_next_steps SET status=?, updated_at=NOW(){$extra} WHERE id=?")->execute([$new, $id]);
    dn_log((int)$s['task_id'], 'step_status', 'step_status', $s['status'], $new, '修改执行步骤状态');
    return ['step_id' => $id, 'status' => $new];
}

function dn_dispatch_step(array $in): array
{
    dn_require('create_dispatch', '没有从步骤生成派工的权限');
    $id = (int)($in['step_id'] ?? 0);
    $pdo = dispatch_next_db();
    $st = $pdo->prepare("SELECT s.*, t.title AS task_title, t.project AS task_project, t.created_by AS parent_created_by FROM dispatch_next_steps s JOIN dispatch_next_tasks t ON t.id=s.task_id WHERE s.id=? LIMIT 1");
    $st->execute([$id]);
    $s = $st->fetch();
    if (!$s) dn_fail('步骤不存在', 404);
    dn_task((int)$s['task_id']);
    if (!empty($s['child_task_id'])) return ['step_id' => $id, 'child_task_id' => (int)$s['child_task_id']];
    $assigned = (int)($s['owner_id'] ?: dn_uid());
    $childId = dn_insert_task([
        'task_type' => 'dispatch',
        'dispatch_mode' => 'single',
        'parent_group_id' => null,
        'title' => (string)$s['step_name'],
        'project' => (string)($s['task_title'] ?? ''),
        'description' => (string)($s['note'] ?? ''),
        'priority' => 'normal',
        'status' => $assigned === dn_uid() ? 'in_progress' : 'pending_accept',
        'created_by' => dn_uid(),
        'assigned_to' => $assigned,
        'task_date' => date('Y-m-d'),
        'due_at' => $s['due_at'] ?? null,
        'is_read' => $assigned === dn_uid() ? 1 : 0,
    ]);
    $pdo->prepare("UPDATE dispatch_next_steps SET child_task_id=?, updated_at=NOW() WHERE id=?")->execute([$childId, $id]);
    dn_log((int)$s['task_id'], 'step_dispatch', 'child_task_id', '', $childId, '步骤生成子派工');
    return ['step_id' => $id, 'child_task_id' => $childId];
}

function dn_upload(array $in, string $kind): array
{
    dn_cleanup_expired_attachments();
    dn_require($kind === 'image' ? 'upload_image' : 'upload_attachment', $kind === 'image' ? '没有上传图片权限' : '没有上传附件权限');
    $task = dn_task((int)($in['task_id'] ?? 0));
    if (empty($_FILES['file'])) dn_fail('请选择文件');
    $f = $_FILES['file'];
    if (!empty($f['error'])) dn_fail('文件上传失败');
    $size = (int)$f['size'];
    $name = basename((string)$f['name']);
    $type = (string)($f['type'] ?? '');
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    if ($kind === 'image') {
        if ($size > 500 * 1024) dn_fail('图片单张不能超过 500KB');
        if (!in_array($ext, ['jpg','jpeg','png','webp','gif'], true)) dn_fail('图片仅支持 JPG / PNG / WEBP / GIF');
        $info = @getimagesize((string)$f['tmp_name']);
        if (!$info || empty($info['mime']) || !in_array((string)$info['mime'], ['image/jpeg','image/png','image/webp','image/gif'], true)) dn_fail('文件内容不是有效图片');
    } else {
        if ($size > 100 * 1024 * 1024) dn_fail('附件不能超过 100MB');
    }
    $dir = __DIR__ . '/uploads/dispatch_next/' . date('Ymd');
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) dn_fail('上传目录不可写');
    $safe = date('His') . '_' . bin2hex(random_bytes(5)) . ($ext ? '.' . $ext : '');
    $path = $dir . '/' . $safe;
    if (!move_uploaded_file((string)$f['tmp_name'], $path)) dn_fail('保存文件失败');
    $rel = 'uploads/dispatch_next/' . date('Ymd') . '/' . $safe;
    $expires = date('Y-m-d H:i:s', strtotime('+7 days'));
    $pdo = dispatch_next_db();
    $pdo->prepare("INSERT INTO dispatch_next_attachments(task_id,comment_id,user_id,file_kind,file_name,file_path,file_type,file_size,expires_at,created_at) VALUES(?,?,?,?,?,?,?,?,?,NOW())")
        ->execute([(int)$task['id'], !empty($in['comment_id']) ? (int)$in['comment_id'] : null, dn_uid(), $kind, $name, $rel, $type, $size, $expires]);
    dn_log((int)$task['id'], 'upload_' . $kind, null, '', $name, '上传文件');
    return ['attachment_id' => (int)$pdo->lastInsertId(), 'path' => $rel, 'expires_at' => $expires];
}

function dn_delete_attachment(array $in): array
{
    dn_require('delete_attachment', '没有删除附件权限');
    $id = (int)($in['attachment_id'] ?? 0);
    $pdo = dispatch_next_db();
    $st = $pdo->prepare("SELECT * FROM dispatch_next_attachments WHERE id=? AND is_deleted=0");
    $st->execute([$id]);
    $a = $st->fetch();
    if (!$a) dn_fail('附件不存在');
    dn_task((int)$a['task_id']);
    $pdo->prepare("UPDATE dispatch_next_attachments SET is_deleted=1, deleted_at=NOW() WHERE id=?")->execute([$id]);
    dn_delete_attachment_file((string)$a['file_path']);
    dn_log((int)$a['task_id'], 'delete_attachment', null, $a['file_name'], '', '删除附件');
    return ['attachment_id' => $id];
}

function dn_attachment_path(string $rel): ?string
{
    $rel = ltrim(str_replace('\\', '/', $rel), '/');
    if ($rel === '' || str_contains($rel, '..') || !str_starts_with($rel, 'uploads/dispatch_next/')) return null;
    return __DIR__ . '/' . $rel;
}

function dn_delete_attachment_file(string $rel): void
{
    $path = dn_attachment_path($rel);
    if (!$path || !is_file($path)) return;
    $base = realpath(__DIR__ . '/uploads/dispatch_next');
    $real = realpath($path);
    if ($base && $real && str_starts_with($real, $base . DIRECTORY_SEPARATOR)) @unlink($real);
}

function dn_cleanup_expired_attachments(): int
{
    static $done = false;
    if ($done) return 0;
    $done = true;
    $pdo = dispatch_next_db();
    $st = $pdo->query("SELECT id,file_path FROM dispatch_next_attachments WHERE is_deleted=0 AND expires_at IS NOT NULL AND expires_at<=NOW() LIMIT 200");
    $rows = $st ? $st->fetchAll() : [];
    if (!$rows) return 0;
    $ids = [];
    foreach ($rows as $row) {
        $ids[] = (int)$row['id'];
        dn_delete_attachment_file((string)($row['file_path'] ?? ''));
    }
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $pdo->prepare("UPDATE dispatch_next_attachments SET is_deleted=1, deleted_at=NOW() WHERE id IN ({$placeholders})")->execute($ids);
    return count($ids);
}

function dn_download_attachment(array $in): void
{
    dn_cleanup_expired_attachments();
    dn_require('download_attachment', '没有下载附件权限');
    $id = (int)($in['attachment_id'] ?? 0);
    $st = dispatch_next_db()->prepare("SELECT * FROM dispatch_next_attachments WHERE id=? AND is_deleted=0");
    $st->execute([$id]);
    $a = $st->fetch();
    if (!$a) dn_fail('附件不存在', 404);
    dn_task((int)$a['task_id']);
    if (!empty($a['expires_at']) && strtotime((string)$a['expires_at']) < time()) dn_fail('附件已过期失效', 410);
    $path = dn_attachment_path((string)$a['file_path']);
    if (!$path) dn_fail('附件路径无效', 404);
    if (!is_file($path)) dn_fail('附件物理文件不存在', 404);
    while (ob_get_level() > 0) @ob_end_clean();
    header('Content-Type: ' . ($a['file_type'] ?: 'application/octet-stream'));
    header('Content-Disposition: attachment; filename="' . rawurlencode((string)$a['file_name']) . '"');
    header('Content-Length: ' . filesize($path));
    readfile($path);
    exit;
}

function dn_notifications(bool $mark = false): array
{
    $pdo = dispatch_next_db();
    dn_merge_duplicate_update_notifications(dn_uid());
    $st = $pdo->prepare("SELECT * FROM dispatch_next_notifications WHERE recipient_id=? ORDER BY id DESC LIMIT 200");
    $st->execute([dn_uid()]);
    $rows = $st->fetchAll();
    if ($mark) $pdo->prepare("UPDATE dispatch_next_notifications SET is_read=1, read_at=COALESCE(read_at,NOW()) WHERE recipient_id=?")->execute([dn_uid()]);
    $unread = 0;
    foreach ($rows as &$r) {
        $r['id'] = (int)$r['id'];
        $r['task_id'] = $r['task_id'] ? (int)$r['task_id'] : null;
        $r['is_read'] = (int)$r['is_read'];
        if (!$r['is_read']) $unread++;
    }
    return ['items' => $rows, 'unread_count' => $unread];
}

function dn_online_status(): array
{
    crm_ensure_tables();
    $u = dn_user();
    $ua = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500);
    $isMobile = preg_match('/Mobile|Android|iPhone|iPad/i', $ua) ? 1 : 0;
    $device = $isMobile ? 'mobile' : 'desktop';
    $browser = 'Browser';
    if (preg_match('/Edg/i', $ua)) $browser = 'Edge';
    elseif (preg_match('/Chrome/i', $ua)) $browser = 'Chrome';
    elseif (preg_match('/Safari/i', $ua)) $browser = 'Safari';
    elseif (preg_match('/Firefox/i', $ua)) $browser = 'Firefox';
    $session = session_id() ?: ('dispatch_' . (int)$u['id']);
    $name = (string)($u['display_name'] ?? $u['real_name'] ?? $u['username'] ?? ('User#' . (int)$u['id']));
    $department = (string)($u['department_name'] ?? $u['department'] ?? '');
    $role = artdon_sso_role_label(artdon_sso_role_key($u));
    db()->prepare("INSERT INTO crm_online_users (user_id, user_name, department, role, status, current_module, login_time, last_active_time, ip_address, device_type, browser, user_agent, session_id, is_mobile, created_at, updated_at) VALUES (?, ?, ?, ?, 'online', '派工待办', NOW(), NOW(), ?, ?, ?, ?, ?, ?, NOW(), NOW()) ON DUPLICATE KEY UPDATE user_name=VALUES(user_name), department=VALUES(department), role=VALUES(role), status='online', current_module=VALUES(current_module), last_active_time=NOW(), ip_address=VALUES(ip_address), device_type=VALUES(device_type), browser=VALUES(browser), user_agent=VALUES(user_agent), is_mobile=VALUES(is_mobile), updated_at=NOW()")
        ->execute([(int)$u['id'], $name, $department, $role, artdon_sso_ip(), $device, $browser, $ua, $session, $isMobile]);
    dn_touch_online_session('heartbeat');
    $rows = db()->query("SELECT user_id, user_name, department, role, status, current_module, login_time, last_active_time, device_type, browser, is_mobile, session_id FROM crm_online_users WHERE status <> 'offline' AND last_active_time >= DATE_SUB(NOW(), INTERVAL 15 MINUTE) ORDER BY last_active_time DESC LIMIT 100")->fetchAll();
    $people = [];
    foreach ($rows as $row) {
        $key = (int)($row['user_id'] ?? 0) > 0 ? 'u' . (int)$row['user_id'] : 's' . (string)($row['session_id'] ?? '');
        if (isset($people[$key])) continue;
        $idle = time() - strtotime((string)$row['last_active_time']);
        $row['status'] = $idle <= 300 ? ((int)$row['is_mobile'] ? '手机在线' : '在线') : '离开';
        $row['device_type'] = trim((string)$row['device_type'] . ' ' . (string)$row['browser']);
        $st = db()->prepare('SELECT active_seconds FROM crm_online_sessions WHERE session_id = ? LIMIT 1');
        $st->execute([(string)($row['session_id'] ?? '')]);
        $row['online_seconds'] = (int)($st->fetchColumn() ?: 0);
        $row['online_text'] = dn_duration_text((int)$row['online_seconds']);
        unset($row['session_id']);
        $people[$key] = $row;
    }
    $people = array_values($people);
    return ['count' => count($people), 'people' => $people];
}

function dn_touch_online_session(string $event = 'heartbeat'): void
{
    crm_ensure_tables();
    $u = dn_user();
    $ua = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500);
    $isMobile = preg_match('/Mobile|Android|iPhone|iPad/i', $ua) ? 1 : 0;
    $device = $isMobile ? 'mobile' : 'desktop';
    $browser = 'Browser';
    if (preg_match('/Edg/i', $ua)) $browser = 'Edge';
    elseif (preg_match('/Chrome/i', $ua)) $browser = 'Chrome';
    elseif (preg_match('/Safari/i', $ua)) $browser = 'Safari';
    elseif (preg_match('/Firefox/i', $ua)) $browser = 'Firefox';
    $session = session_id() ?: ('dispatch_' . (int)$u['id']);
    $name = (string)($u['display_name'] ?? $u['real_name'] ?? $u['username'] ?? ('User#' . (int)$u['id']));
    $department = (string)($u['department_name'] ?? $u['department'] ?? '');
    $role = artdon_sso_role_label(artdon_sso_role_key($u));
    $stmt = db()->prepare('SELECT id, last_active_time FROM crm_online_sessions WHERE session_id = ? LIMIT 1');
    $stmt->execute([$session]);
    $row = $stmt->fetch();
    if ($row) {
        $last = strtotime((string)$row['last_active_time']);
        $delta = $last ? max(0, time() - $last) : 0;
        $add = ($delta > 0 && $delta <= 180) ? $delta : 0;
        $status = $event === 'leave' ? 'offline' : 'active';
        db()->prepare("UPDATE crm_online_sessions SET user_id=?, user_name=?, department=?, role=?, current_module='派工待办', last_active_time=NOW(), logout_time=" . ($event === 'leave' ? 'NOW()' : 'NULL') . ", status=?, active_seconds=active_seconds+?, heartbeat_count=heartbeat_count+1, last_event=?, ip_address=?, device_type=?, browser=?, user_agent=?, is_mobile=?, updated_at=NOW() WHERE id=?")
            ->execute([(int)$u['id'], $name, $department, $role, $status, $add, $event, artdon_sso_ip(), $device, $browser, $ua, $isMobile, $row['id']]);
        return;
    }
    db()->prepare("INSERT INTO crm_online_sessions (user_id, user_name, department, role, current_module, session_id, login_time, last_active_time, logout_time, status, active_seconds, heartbeat_count, last_event, ip_address, device_type, browser, user_agent, is_mobile, created_at, updated_at) VALUES (?, ?, ?, ?, '派工待办', ?, NOW(), NOW(), " . ($event === 'leave' ? 'NOW()' : 'NULL') . ", ?, 0, 1, ?, ?, ?, ?, ?, ?, NOW(), NOW())")
        ->execute([(int)$u['id'], $name, $department, $role, $session, $event === 'leave' ? 'offline' : 'active', $event, artdon_sso_ip(), $device, $browser, $ua, $isMobile]);
}

function dn_online_leave(): array
{
    crm_ensure_tables();
    dn_touch_online_session('leave');
    db()->prepare("UPDATE crm_online_users SET status='offline', current_module='派工待办', last_active_time=NOW(), updated_at=NOW() WHERE session_id=?")
        ->execute([session_id() ?: ('dispatch_' . dn_uid())]);
    return ['left' => true];
}

function dn_online_logs(array $in = []): array
{
    dn_require('view_logs', '没有查看在线日志权限');
    crm_ensure_tables();
    $ids = array_values(array_unique(array_filter(array_map('intval', (array)($in['user_ids'] ?? [])), fn($v) => $v > 0)));
    $where = '';
    $params = [];
    if ($ids) {
        $where = 'WHERE user_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')';
        $params = $ids;
    }
    $st = db()->prepare("SELECT user_id, user_name, department, role, current_module, login_time, last_active_time, logout_time, active_seconds AS online_seconds, status, heartbeat_count, device_type, browser, ip_address, is_mobile FROM crm_online_sessions {$where} ORDER BY last_active_time DESC LIMIT 200");
    $st->execute($params);
    $rows = $st->fetchAll();
    $summary = [];
    foreach ($rows as &$row) {
        $seconds = (int)($row['online_seconds'] ?? 0);
        $row['online_seconds'] = $seconds;
        $row['online_text'] = dn_duration_text($seconds);
        $row['device_type'] = trim((string)$row['device_type'] . ' ' . (string)$row['browser']);
        $uid = (int)$row['user_id'];
        if (!isset($summary[$uid])) {
            $summary[$uid] = [
                'user_id' => $uid,
                'user_name' => (string)$row['user_name'],
                'department' => (string)($row['department'] ?? ''),
                'online_seconds' => 0,
                'online_text' => '',
                'session_count' => 0,
                'last_active_time' => (string)$row['last_active_time'],
            ];
        }
        $summary[$uid]['online_seconds'] += $seconds;
        $summary[$uid]['session_count']++;
        if (strtotime((string)$row['last_active_time']) > strtotime((string)$summary[$uid]['last_active_time'])) {
            $summary[$uid]['last_active_time'] = (string)$row['last_active_time'];
        }
    }
    foreach ($summary as &$item) $item['online_text'] = dn_duration_text((int)$item['online_seconds']);
    usort($summary, fn($a, $b) => ((int)$b['online_seconds'] <=> (int)$a['online_seconds']) ?: strcmp((string)$a['user_name'], (string)$b['user_name']));
    return ['summary' => array_values($summary), 'sessions' => $rows];
}

function dn_duration_text(int $seconds): string
{
    $seconds = max(0, $seconds);
    $h = intdiv($seconds, 3600);
    $m = intdiv($seconds % 3600, 60);
    if ($h > 0) return $h . '小时' . $m . '分';
    return $m . '分';
}

function dn_mark_read(array $in): array
{
    $id = (int)($in['id'] ?? 0);
    $sql = $id > 0 ? "UPDATE dispatch_next_notifications SET is_read=1, read_at=COALESCE(read_at,NOW()) WHERE id=? AND recipient_id=?" : "UPDATE dispatch_next_notifications SET is_read=1, read_at=COALESCE(read_at,NOW()) WHERE recipient_id=?";
    dispatch_next_db()->prepare($sql)->execute($id > 0 ? [$id, dn_uid()] : [dn_uid()]);
    return ['id' => $id];
}

function dn_mark_task_read(array $in, bool $return = true): array
{
    $id = (int)($in['id'] ?? 0);
    $task = dn_task($id);
    if ((int)$task['assigned_to'] === dn_uid()) {
        dispatch_next_db()->prepare("UPDATE dispatch_next_tasks SET is_read=1, read_at=COALESCE(read_at,NOW()) WHERE id=?")->execute([$id]);
    }
    $data = ['id' => $id];
    return $data;
}

function dn_urge_task(array $in): array
{
    dn_require('urge', '没有催办权限');
    $id = (int)($in['id'] ?? 0);
    $task = dn_task($id);
    if (!dn_is_admin() && (int)$task['created_by'] !== dn_uid()) dn_fail('只有派工人或管理员可以催办', 403);
    dispatch_next_db()->prepare("UPDATE dispatch_next_tasks SET remind_count=remind_count+1,last_reminded_at=NOW(),updated_at=NOW() WHERE id=?")->execute([$id]);
    dn_notify((int)$task['assigned_to'], $id, 'urge', '派工催办提醒', (string)$task['title']);
    dn_log($id, 'urge', 'remind_count', $task['remind_count'], ((int)$task['remind_count']) + 1, '催办任务');
    return ['id' => $id];
}

function dn_prefs_get(string $key, $fallback = null)
{
    $st = dispatch_next_db()->prepare("SELECT pref_value FROM dispatch_next_user_prefs WHERE user_id=? AND pref_key=? LIMIT 1");
    $st->execute([dn_uid(), $key]);
    $v = $st->fetchColumn();
    if ($v === false) return $fallback;
    $j = json_decode((string)$v, true);
    return $j === null ? $v : $j;
}

function dn_prefs_save(string $key, $value): array
{
    dispatch_next_db()->prepare("INSERT INTO dispatch_next_user_prefs(user_id,pref_key,pref_value,updated_at) VALUES(?,?,?,NOW()) ON DUPLICATE KEY UPDATE pref_value=VALUES(pref_value), updated_at=NOW()")
        ->execute([dn_uid(), $key, is_string($value) ? $value : dn_json($value)]);
    return ['key' => $key];
}

function dn_global_prefs_get(string $key, $fallback = null)
{
    $st = dispatch_next_db()->prepare("SELECT pref_value FROM dispatch_next_user_prefs WHERE user_id=0 AND pref_key=? LIMIT 1");
    $st->execute([$key]);
    $v = $st->fetchColumn();
    if ($v === false) return $fallback;
    $j = json_decode((string)$v, true);
    return $j === null ? $v : $j;
}

function dn_global_prefs_save(string $key, $value): array
{
    dispatch_next_db()->prepare("INSERT INTO dispatch_next_user_prefs(user_id,pref_key,pref_value,updated_at) VALUES(0,?,?,NOW()) ON DUPLICATE KEY UPDATE pref_value=VALUES(pref_value), updated_at=NOW()")
        ->execute([$key, is_string($value) ? $value : dn_json($value)]);
    dn_log(null, 'save_permissions', $key, '', $value, '保存派工可见规则');
    return ['key' => $key];
}

function dn_table_default_columns(): array
{
    return [
        ['key'=>'row_handle','label'=>'','type'=>'handle','visible'=>1,'width'=>34,'minWidth'=>28,'maxWidth'=>56,'order'=>5],
        ['key'=>'complete','label'=>'完成','type'=>'complete','visible'=>1,'width'=>56,'minWidth'=>44,'maxWidth'=>120,'order'=>10],
        ['key'=>'priority','label'=>'优先级','type'=>'select','visible'=>1,'width'=>90,'minWidth'=>70,'maxWidth'=>150,'order'=>20],
        ['key'=>'title','label'=>'任务标题','type'=>'text','visible'=>1,'width'=>260,'minWidth'=>140,'maxWidth'=>720,'order'=>30],
        ['key'=>'project','label'=>'项目','type'=>'textarea','visible'=>1,'width'=>420,'minWidth'=>120,'maxWidth'=>760,'order'=>40],
        ['key'=>'due_at','label'=>'截止日期','type'=>'datetime','visible'=>1,'width'=>140,'minWidth'=>100,'maxWidth'=>220,'order'=>50],
        ['key'=>'assigned_to','label'=>'负责人','type'=>'user','visible'=>1,'width'=>130,'minWidth'=>90,'maxWidth'=>220,'order'=>60],
        ['key'=>'dispatch_mode','label'=>'方式','type'=>'mode','visible'=>1,'width'=>100,'minWidth'=>80,'maxWidth'=>160,'order'=>70],
        ['key'=>'creator_name','label'=>'派工来自','type'=>'readonly','visible'=>1,'width'=>120,'minWidth'=>90,'maxWidth'=>220,'order'=>80],
        ['key'=>'actions','label'=>'操作','type'=>'actions','visible'=>1,'width'=>150,'minWidth'=>120,'maxWidth'=>220,'order'=>90],
    ];
}

function dn_custom_fields(): array
{
    $st = dispatch_next_db()->prepare("SELECT * FROM dispatch_next_custom_fields WHERE user_id=? AND is_enabled=1 ORDER BY sort_order, id");
    $st->execute([dn_uid()]);
    $rows = $st->fetchAll();
    foreach ($rows as &$row) {
        $row['id'] = (int)$row['id'];
        $row['visible'] = 1;
        $row['width'] = 160;
        $row['minWidth'] = 90;
        $row['maxWidth'] = 520;
        $row['order'] = 1000 + (int)$row['sort_order'];
        $row['key'] = 'custom_' . $row['field_key'];
        $row['label'] = $row['field_label'];
        $row['type'] = $row['field_type'];
        $row['options'] = json_decode((string)($row['options_json'] ?? '[]'), true) ?: [];
    }
    return $rows;
}

function dn_get_table_prefs(array $in): array
{
    $scope = dn_str($in['scope'] ?? 'desktop', 20);
    if (!in_array($scope, ['desktop','mobile'], true)) $scope = 'desktop';
    $prefs = dn_prefs_get('table_prefs_' . $scope, []);
    $columns = dn_table_default_columns();
    if ($scope === 'mobile' && empty($prefs['columns'])) {
        $mobileWidths = [
            'row_handle' => 0,
            'complete' => 36,
            'priority' => 50,
            'title' => 52,
            'project' => 260,
            'due_at' => 44,
            'assigned_to' => 66,
            'dispatch_mode' => 48,
            'creator_name' => 54,
            'actions' => 58,
        ];
        foreach ($columns as &$col) {
            $key = (string)($col['key'] ?? '');
            if ($key === 'row_handle') $col['visible'] = 0;
            if (isset($mobileWidths[$key])) $col['width'] = $mobileWidths[$key];
            $col['minWidth'] = 24;
            $col['maxWidth'] = 520;
        }
        unset($col);
    }
    foreach (dn_custom_fields() as $custom) {
        $columns[] = [
            'key' => $custom['key'],
            'field_key' => $custom['field_key'],
            'label' => $custom['label'],
            'type' => $custom['type'],
            'visible' => 1,
            'width' => 160,
            'minWidth' => 90,
            'maxWidth' => 520,
            'order' => $custom['order'],
            'options' => $custom['options'],
            'custom' => 1,
        ];
    }
    $byKey = [];
    foreach ($columns as $col) $byKey[$col['key']] = $col;
    foreach (($prefs['columns'] ?? []) as $saved) {
        $key = (string)($saved['key'] ?? '');
        if (!$key || empty($byKey[$key])) continue;
        foreach (['label','visible','width','order'] as $field) {
            if (array_key_exists($field, $saved)) $byKey[$key][$field] = $saved[$field];
        }
    }
    $columns = array_values($byKey);
    usort($columns, fn($a, $b) => ((int)($a['order'] ?? 0) <=> (int)($b['order'] ?? 0)));
    return [
        'scope' => $scope,
        'columns' => $columns,
        'custom_fields' => dn_custom_fields(),
        'color_prefs' => dn_shared_color_prefs($scope),
    ];
}

function dn_save_table_prefs(array $in): array
{
    dn_require('table_customize', '没有表格自定义权限');
    $scope = dn_str($in['scope'] ?? 'desktop', 20);
    if (!in_array($scope, ['desktop','mobile'], true)) $scope = 'desktop';
    $current = dn_prefs_get('table_prefs_' . $scope, []);
    $colorPrefs = dn_shared_color_prefs($scope);
    if (is_array($in['color_prefs'] ?? null)) {
        $colorPrefs = array_merge(dn_default_color_prefs(), (array)$in['color_prefs']);
        dn_prefs_save('table_color_prefs', $colorPrefs);
    }
    $saved = dn_prefs_save('table_prefs_' . $scope, [
        'columns' => array_values((array)($in['columns'] ?? ($current['columns'] ?? []))),
        'color_prefs' => $colorPrefs,
    ]);
    $saved['color_prefs'] = $colorPrefs;
    return $saved;
}

function dn_default_color_prefs(): array
{
    return [
        'assignee' => '#111827',
        'creator' => '#047857',
        'method' => '#3730a3',
        'mention_naming' => '#2563eb',
        'mention_bom' => '#8b3a1e',
        'mention_quote' => '#047857',
        'mention_plm' => '#6d28d9',
        'mention_snapshot' => '#0f766e',
        'mention_material' => '#7c3aed',
        'mention_dispatch' => '#1f2937',
        'mention_opportunity' => '#b45309',
        'overdue_bg' => '#fff7ed',
        'overdue_line' => '#facc15',
        'done_bg' => '#fafafa',
        'icon_style' => 'minimal',
        'ref_chip_mode' => 'standard',
    ];
}

function dn_shared_color_prefs(string $scope = 'desktop'): array
{
    $shared = dn_prefs_get('table_color_prefs', null);
    if (is_array($shared)) return array_merge(dn_default_color_prefs(), $shared);

    $desktop = dn_prefs_get('table_prefs_desktop', []);
    if (is_array($desktop['color_prefs'] ?? null)) return array_merge(dn_default_color_prefs(), $desktop['color_prefs']);

    $prefs = dn_prefs_get('table_prefs_' . $scope, []);
    if (is_array($prefs['color_prefs'] ?? null)) return array_merge(dn_default_color_prefs(), $prefs['color_prefs']);

    return dn_default_color_prefs();
}

function dn_save_column_width(array $in): array
{
    dn_require('table_customize', '没有列宽调整权限');
    $scope = dn_str($in['scope'] ?? 'desktop', 20);
    $prefs = dn_get_table_prefs(['scope' => $scope]);
    $key = dn_str($in['key'] ?? '', 120);
    $width = max(44, min(760, (int)($in['width'] ?? 120)));
    foreach ($prefs['columns'] as &$col) {
        if ($col['key'] === $key) $col['width'] = $width;
    }
    dn_prefs_save('table_prefs_' . $scope, ['columns' => $prefs['columns'], 'color_prefs' => $prefs['color_prefs'] ?? dn_default_color_prefs()]);
    return ['key' => $key, 'width' => $width];
}

function dn_update_cell(array $in): array
{
    dn_require('edit_cell', '没有表格单元格编辑权限');
    $id = (int)($in['task_id'] ?? 0);
    $field = dn_str($in['field'] ?? '', 120);
    $value = $in['value'] ?? '';
    $task = dn_task($id);
    dn_check_task_conflict($task, $in);
    if (!dn_can_edit_task($task, $field)) dn_fail('没有修改权限', 403);
    $nextDue = $field === 'due_at' ? dn_due_dt($value) : dn_due_dt($task['due_at'] ?? null);
    if ($nextDue === null) dn_fail('派工待办必须填写截止日期');
    $map = [
        'title' => ['col'=>'title','type'=>'string','max'=>240],
        'project' => ['col'=>'project','type'=>'string','max'=>180],
        'description' => ['col'=>'description','type'=>'string','max'=>8000],
        'priority' => ['col'=>'priority','type'=>'priority'],
        'status' => ['col'=>'status','type'=>'status'],
        'assigned_to' => ['col'=>'assigned_to','type'=>'int'],
        'dispatch_mode' => ['col'=>'dispatch_mode','type'=>'mode'],
        'due_at' => ['col'=>'due_at','type'=>'datetime'],
        'task_date' => ['col'=>'task_date','type'=>'date'],
        'progress' => ['col'=>'progress','type'=>'progress'],
    ];
    if (str_starts_with($field, 'custom_')) {
        $key = substr($field, 7);
        $oldSt = dispatch_next_db()->prepare("SELECT field_value FROM dispatch_next_task_values WHERE task_id=? AND field_key=? LIMIT 1");
        $oldSt->execute([$id, $key]);
        $old = (string)($oldSt->fetchColumn() ?: '');
        $new = dn_str($value, 5000);
        dispatch_next_db()->prepare("INSERT INTO dispatch_next_task_values(task_id, field_key, field_value, updated_by, updated_at) VALUES(?,?,?,?,NOW()) ON DUPLICATE KEY UPDATE field_value=VALUES(field_value), updated_by=VALUES(updated_by), updated_at=NOW()")
            ->execute([$id, $key, $new, dn_uid()]);
        dn_log($id, 'update_cell', $field, $old, $new, '表格单元格编辑');
        dn_notify_task_change($task, $field, $old, $new);
        return ['task_id' => $id, 'field' => $field, 'value' => $new, 'updated_at' => (string)($task['updated_at'] ?? '')];
    }
    if (empty($map[$field])) dn_fail('不支持编辑该字段');
    if ($field === 'dispatch_mode') {
        $mode = dn_str($value, 30);
        $oldMethod = dn_method_label((string)$task['task_type'], (string)$task['dispatch_mode']);
        if (in_array($mode, ['personal', 'private'], true)) {
            $newType = $mode;
            $newMode = 'single';
        } else {
            $newType = 'dispatch';
            $newMode = in_array($mode, ['single', 'multi', 'plan', 'recurring'], true) ? $mode : 'single';
        }
        if ($newType === 'dispatch' && dn_due_dt($task['due_at'] ?? null) === null) dn_fail('派工待办必须填写截止日期');
        if ((string)$task['task_type'] !== $newType || (string)$task['dispatch_mode'] !== $newMode) {
            dispatch_next_db()->prepare("UPDATE dispatch_next_tasks SET task_type=?, dispatch_mode=?, updated_at=NOW() WHERE id=?")->execute([$newType, $newMode, $id]);
            dn_log($id, 'update_cell', 'dispatch_mode', $oldMethod, dn_method_label($newType, $newMode), '表格方式编辑');
            dn_notify_task_change($task, 'dispatch_mode', $oldMethod, dn_method_label($newType, $newMode));
            dn_refresh_group((int)($task['parent_group_id'] ?? 0));
        }
        return ['task_id' => $id, 'field' => $field, 'value' => $mode, 'task_type' => $newType, 'dispatch_mode' => $newMode, 'updated_at' => dn_task_updated_at($id)];
    }
    $meta = $map[$field];
    $old = $task[$meta['col']] ?? '';
    if ($meta['type'] === 'string') $new = dn_str($value, (int)$meta['max']);
    elseif ($meta['type'] === 'priority') $new = dn_priority($value);
    elseif ($meta['type'] === 'status') $new = dn_status($value);
    elseif ($meta['type'] === 'int') $new = (int)$value;
    elseif ($meta['type'] === 'mode') $new = in_array($value, ['single','multi','plan','recurring'], true) ? $value : 'single';
    elseif ($meta['type'] === 'datetime') $new = $field === 'due_at' ? dn_due_dt($value) : dn_dt($value);
    elseif ($meta['type'] === 'date') $new = dn_date($value, (string)$task['task_date']);
    elseif ($meta['type'] === 'progress') $new = max(0, min(100, (int)$value));
    else $new = dn_str($value, 500);
    if ((string)$old !== (string)$new) {
        $extra = '';
        if ($field === 'status' && $new === 'done') $extra = ", completed_at=NOW(), progress=100";
        if ($field === 'status' && $new === 'in_progress') $extra = ", started_at=COALESCE(started_at,NOW()), completed_at=NULL, cancelled_at=NULL";
        dispatch_next_db()->prepare("UPDATE dispatch_next_tasks SET {$meta['col']}=?{$extra}, updated_at=NOW() WHERE id=?")->execute([$new, $id]);
        dn_log($id, 'update_cell', $field, $old, $new, '表格单元格编辑');
        dn_notify_task_change($task, $field, $old, $new);
        dn_refresh_group((int)($task['parent_group_id'] ?? 0));
    }
    return ['task_id' => $id, 'field' => $field, 'value' => $new, 'updated_at' => dn_task_updated_at($id)];
}

function dn_save_row_order(array $in): array
{
    dn_require('row_order', '没有行排序权限');
    $type = in_array(($in['table_type'] ?? 'personal'), ['personal','dispatch','done'], true) ? $in['table_type'] : 'personal';
    $ids = array_values(array_filter(array_map('intval', (array)($in['task_ids'] ?? [])), fn($v) => $v > 0));
    $stmt = dispatch_next_db()->prepare("INSERT INTO dispatch_next_task_orders(user_id, task_id, table_type, sort_order, updated_at) VALUES(?,?,?,?,NOW()) ON DUPLICATE KEY UPDATE sort_order=VALUES(sort_order), updated_at=NOW()");
    foreach ($ids as $i => $id) $stmt->execute([dn_uid(), $id, $type, ($i + 1) * 10]);
    return ['table_type' => $type, 'count' => count($ids)];
}

function dn_save_custom_field(array $in): array
{
    dn_require('custom_fields', '没有自定义列权限');
    $label = dn_str($in['field_label'] ?? '', 120);
    if ($label === '') dn_fail('请输入列名');
    $key = dn_str($in['field_key'] ?? '', 120);
    if ($key === '') $key = 'field_' . strtolower(bin2hex(random_bytes(4)));
    $key = preg_replace('/[^a-zA-Z0-9_]/', '_', $key);
    $type = in_array(($in['field_type'] ?? 'text'), ['text','textarea','number','date','datetime','select','user'], true) ? $in['field_type'] : 'text';
    dispatch_next_db()->prepare("INSERT INTO dispatch_next_custom_fields(user_id, field_key, field_label, field_type, options_json, sort_order, created_at, updated_at) VALUES(?,?,?,?,?,?,NOW(),NOW()) ON DUPLICATE KEY UPDATE field_label=VALUES(field_label), field_type=VALUES(field_type), options_json=VALUES(options_json), sort_order=VALUES(sort_order), is_enabled=1, updated_at=NOW()")
        ->execute([dn_uid(), $key, $label, $type, dn_json($in['options'] ?? []), (int)($in['sort_order'] ?? 0)]);
    return ['field_key' => $key];
}

function dn_delete_custom_field(array $in): array
{
    dn_require('custom_fields', '没有删除自定义列权限');
    $key = dn_str($in['field_key'] ?? '', 120);
    dispatch_next_db()->prepare("UPDATE dispatch_next_custom_fields SET is_enabled=0, updated_at=NOW() WHERE user_id=? AND field_key=?")->execute([dn_uid(), $key]);
    return ['field_key' => $key];
}

function dn_me_data(): array
{
    $u = dn_user();
    $caps = [
        'view_all', 'view_department', 'view_logs', 'view_link_preview', 'view_notifications',
        'create', 'create_personal', 'create_private', 'create_dispatch', 'create_multi', 'create_plan', 'create_recurring',
        'edit', 'edit_own', 'edit_assigned', 'edit_all', 'edit_cell', 'change_status', 'change_assignee', 'change_due_date',
        'row_order', 'table_customize', 'custom_fields', 'comment', 'urge',
        'upload_image', 'upload_attachment', 'download_attachment', 'delete_attachment',
        'delete', 'delete_own', 'delete_all', 'delete_multi', 'restore', 'export', 'backup',
        'manage_visibility', 'manage_permissions', 'init_schema', 'admin',
    ];
    $permissions = [];
    foreach ($caps as $cap) {
        $permissions[$cap] = dn_can($cap);
    }
    return [
        'id' => (int)$u['id'],
        'username' => (string)$u['username'],
        'name' => (string)($u['display_name'] ?? $u['real_name'] ?? $u['english_name'] ?? $u['username']),
        'department' => (string)($u['department'] ?? ''),
        'role_key' => artdon_sso_role_key($u),
        'role_label' => artdon_sso_role_label(artdon_sso_role_key($u)),
        'is_admin' => dn_is_admin(),
        'can_create' => dn_can('create'),
        'can_edit' => dn_can('edit'),
        'can_delete' => dn_can('delete'),
        'permissions' => $permissions,
    ];
}

function dn_backup(): array
{
    dn_require('backup', '没有备份权限');
    return dn_create_backup('manual');
}

function dn_backup_tables(): array
{
    return [
        'dispatch_next_tasks',
        'dispatch_next_groups',
        'dispatch_next_comments',
        'dispatch_next_attachments',
        'dispatch_next_steps',
        'dispatch_next_notifications',
        'dispatch_next_logs',
        'dispatch_next_user_prefs',
        'dispatch_next_task_values',
        'dispatch_next_task_orders',
        'dispatch_next_custom_fields',
    ];
}

function dn_backup_dir(): string
{
    return __DIR__ . '/backup/dispatch_next';
}

function dn_create_backup(string $reason = 'manual'): array
{
    $pdo = dispatch_next_db();
    $data = [];
    foreach (dn_backup_tables() as $table) {
        $data[$table] = $pdo->query("SELECT * FROM `{$table}` ORDER BY id")->fetchAll();
    }
    $dir = dn_backup_dir();
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    if (!is_dir($dir) || !is_writable($dir)) dn_fail('备份目录不可写：backup/dispatch_next', 500);
    $payload = [
        'system' => 'dispatch_next',
        'version' => 2,
        'reason' => $reason,
        'created_at' => date('Y-m-d H:i:s'),
        'created_by' => dn_uid(),
        'tables' => array_map(fn($t) => ['name' => $t, 'count' => count($data[$t] ?? [])], array_keys($data)),
        'data' => $data,
    ];
    $file = $dir . '/dispatch_next_' . preg_replace('/[^a-z0-9_]/i', '_', $reason) . '_' . date('Ymd_His') . '.json';
    if (file_put_contents($file, dn_json($payload)) === false) dn_fail('备份文件写入失败，请检查 backup/dispatch_next 权限', 500);
    dn_log(null, 'backup_create', 'file', '', basename($file), '生成派工待办全量备份：' . $reason);
    dn_prune_backups();
    return ['file' => str_replace(__DIR__ . '/', '', $file), 'name' => basename($file), 'tables' => $payload['tables']];
}

function dn_list_backups(): array
{
    dn_require('backup', '没有备份权限');
    $dir = dn_backup_dir();
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    $files = array_merge(glob($dir . '/dispatch_next_*.json') ?: [], glob($dir . '/dispatch_next_*.zip') ?: []);
    rsort($files);
    $rows = [];
    foreach ($files as $file) {
        $meta = ['created_at' => date('Y-m-d H:i:s', filemtime($file) ?: time()), 'reason' => 'unknown', 'tables' => []];
        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        if ($ext === 'zip') {
            $payload = dn_backup_payload_from_zip($file, false);
            if (is_array($payload)) {
                $meta['created_at'] = (string)($payload['created_at'] ?? $meta['created_at']);
                $meta['reason'] = (string)($payload['reason'] ?? 'uploaded_zip');
            } else {
                $meta['reason'] = 'zip_unreadable';
            }
        } else {
            $raw = @file_get_contents($file, false, null, 0, 8192);
            if ($raw && preg_match('/"created_at"\s*:\s*"([^"]+)"/', $raw, $m)) $meta['created_at'] = $m[1];
            if ($raw && preg_match('/"reason"\s*:\s*"([^"]+)"/', $raw, $m)) $meta['reason'] = $m[1];
        }
        $rows[] = [
            'name' => basename($file),
            'file' => str_replace(__DIR__ . '/', '', $file),
            'size' => filesize($file) ?: 0,
            'type' => strtoupper($ext),
            'created_at' => $meta['created_at'],
            'reason' => $meta['reason'],
        ];
    }
    return ['items' => $rows, 'settings' => dn_backup_settings()];
}

function dn_normalize_backup_data(array $payload): array
{
    $data = is_array($payload['data'] ?? null) ? $payload['data'] : [];
    $legacy = [
        'tasks' => 'dispatch_next_tasks',
        'groups' => 'dispatch_next_groups',
        'comments' => 'dispatch_next_comments',
        'attachments' => 'dispatch_next_attachments',
        'steps' => 'dispatch_next_steps',
        'notifications' => 'dispatch_next_notifications',
        'logs' => 'dispatch_next_logs',
        'user_prefs' => 'dispatch_next_user_prefs',
        'task_values' => 'dispatch_next_task_values',
        'task_orders' => 'dispatch_next_task_orders',
        'custom_fields' => 'dispatch_next_custom_fields',
    ];
    foreach ($legacy as $old => $new) {
        if (isset($data[$old]) && !isset($data[$new])) $data[$new] = $data[$old];
    }
    return $data;
}

function dn_validate_backup_payload($payload): array
{
    if (!is_array($payload)) dn_fail('备份文件不是有效 JSON');
    $data = dn_backup_payload_data_or_null($payload);
    if (!$data) dn_fail('备份文件不包含派工待办数据表');
    return $data;
}

function dn_backup_payload_data_or_null($payload): ?array
{
    if (!is_array($payload)) return null;
    $data = dn_normalize_backup_data($payload);
    foreach (dn_backup_tables() as $table) {
        if (isset($data[$table]) && is_array($data[$table])) return $data;
    }
    return null;
}

function dn_backup_payload_from_zip(string $file, bool $fail = true): ?array
{
    if (!class_exists('ZipArchive')) {
        return dn_backup_payload_from_zip_command($file, $fail);
    }
    $zip = new ZipArchive();
    if ($zip->open($file) !== true) {
        return dn_backup_payload_from_zip_command($file, $fail);
    }
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = (string)$zip->getNameIndex($i);
        if ($name === '' || str_ends_with($name, '/') || !preg_match('/\.json$/i', $name)) continue;
        $raw = $zip->getFromIndex($i);
        if (!is_string($raw) || $raw === '') continue;
        $payload = json_decode($raw, true);
        if (dn_backup_payload_data_or_null($payload)) {
            $payload['_zip_entry'] = $name;
            $zip->close();
            return $payload;
        }
    }
    $zip->close();
    if ($fail) dn_fail('ZIP 中没有可用的派工待办备份 JSON');
    return null;
}

function dn_backup_payload_from_zip_command(string $file, bool $fail = true): ?array
{
    if (!is_readable($file) || !function_exists('shell_exec')) {
        if ($fail) dn_fail('服务器未启用 ZIP 读取能力，不能读取 ZIP 备份', 500);
        return null;
    }
    $unzip = trim((string)@shell_exec('command -v unzip 2>/dev/null'));
    if ($unzip === '') {
        if ($fail) dn_fail('服务器未安装 unzip，不能读取 ZIP 备份', 500);
        return null;
    }
    $list = (string)@shell_exec(escapeshellcmd($unzip) . ' -Z1 ' . escapeshellarg($file) . ' 2>/dev/null');
    foreach (preg_split('/\r?\n/', $list) ?: [] as $entry) {
        $entry = trim($entry);
        if ($entry === '' || str_ends_with($entry, '/') || !preg_match('/\.json$/i', $entry)) continue;
        $raw = (string)@shell_exec(escapeshellcmd($unzip) . ' -p ' . escapeshellarg($file) . ' ' . escapeshellarg($entry) . ' 2>/dev/null');
        if ($raw === '') continue;
        $payload = json_decode($raw, true);
        if (dn_backup_payload_data_or_null($payload)) {
            $payload['_zip_entry'] = $entry;
            return $payload;
        }
    }
    if ($fail) dn_fail('ZIP 中没有可用的派工待办备份 JSON');
    return null;
}

function dn_backup_payload_from_file(string $file): array
{
    $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
    if ($ext === 'zip') {
        $payload = dn_backup_payload_from_zip($file, true);
        return dn_validate_backup_payload($payload);
    }
    $raw = file_get_contents($file);
    $payload = $raw ? json_decode($raw, true) : null;
    return dn_validate_backup_payload($payload);
}

function dn_upload_backup(array $in): array
{
    dn_require('restore', '没有上传恢复权限');
    if (empty($_FILES['file']) || !is_array($_FILES['file'])) dn_fail('请选择备份 JSON 或 ZIP 文件');
    $f = $_FILES['file'];
    if (!empty($f['error'])) dn_fail('上传失败，错误码：' . (int)$f['error']);
    if ((int)($f['size'] ?? 0) <= 0) dn_fail('备份文件为空');
    if ((int)$f['size'] > 100 * 1024 * 1024) dn_fail('备份文件超过 100MB');
    $ext = strtolower(pathinfo((string)($f['name'] ?? ''), PATHINFO_EXTENSION));
    if (!in_array($ext, ['json', 'zip'], true)) dn_fail('只支持上传 JSON 或 ZIP 备份文件');
    $tmp = (string)$f['tmp_name'];
    if ($ext === 'zip') {
        $payload = dn_backup_payload_from_zip($tmp, true);
        dn_validate_backup_payload($payload);
    } else {
        $raw = file_get_contents($tmp);
        $payload = $raw ? json_decode($raw, true) : null;
        dn_validate_backup_payload($payload);
    }
    $dir = dn_backup_dir();
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    if (!is_dir($dir) || !is_writable($dir)) dn_fail('备份目录不可写：backup/dispatch_next', 500);
    $file = $dir . '/dispatch_next_uploaded_' . date('Ymd_His') . '.' . $ext;
    $payload['system'] = 'dispatch_next';
    $payload['uploaded_at'] = date('Y-m-d H:i:s');
    $payload['uploaded_by'] = dn_uid();
    if (empty($payload['created_at'])) $payload['created_at'] = date('Y-m-d H:i:s');
    if ($ext === 'zip') {
        if (!move_uploaded_file($tmp, $file) && !copy($tmp, $file)) dn_fail('上传 ZIP 备份保存失败，请检查目录权限', 500);
    } elseif (file_put_contents($file, dn_json($payload)) === false) {
        dn_fail('上传备份保存失败，请检查目录权限', 500);
    }
    dn_log(null, 'backup_upload', 'file', (string)($f['name'] ?? ''), basename($file), '上传派工待办备份文件');
    return ['name' => basename($file), 'file' => str_replace(__DIR__ . '/', '', $file), 'type' => strtoupper($ext), 'message' => '上传完成，可在备份列表中恢复'];
}

function dn_backup_settings(): array
{
    $defaults = ['enabled' => 1, 'interval_hours' => 24, 'retention_count' => 20, 'last_auto_at' => ''];
    return array_merge($defaults, (array)dn_global_prefs_get('dispatch_backup_settings', []));
}

function dn_save_backup_settings(array $in): array
{
    dn_require('backup', '没有备份权限');
    $cur = dn_backup_settings();
    $settings = [
        'enabled' => !empty($in['enabled']) ? 1 : 0,
        'interval_hours' => max(1, min(168, (int)($in['interval_hours'] ?? $cur['interval_hours']))),
        'retention_count' => max(3, min(100, (int)($in['retention_count'] ?? $cur['retention_count']))),
        'last_auto_at' => (string)($cur['last_auto_at'] ?? ''),
    ];
    dn_global_prefs_save('dispatch_backup_settings', $settings);
    dn_log(null, 'backup_settings', 'settings', dn_json($cur), dn_json($settings), '修改派工待办自动备份设置');
    return $settings;
}

function dn_maybe_auto_backup(): void
{
    $s = dn_backup_settings();
    if (empty($s['enabled'])) return;
    $last = strtotime((string)($s['last_auto_at'] ?? '')) ?: 0;
    if ($last > 0 && time() - $last < ((int)$s['interval_hours'] * 3600)) return;
    try {
        dn_create_backup('auto');
        $s['last_auto_at'] = date('Y-m-d H:i:s');
        dn_global_prefs_save('dispatch_backup_settings', $s);
    } catch (Throwable $e) {
        dn_log(null, 'backup_auto_failed', 'error', '', $e->getMessage(), '自动备份失败');
    }
}

function dn_prune_backups(): void
{
    $s = dn_backup_settings();
    $keep = max(3, (int)($s['retention_count'] ?? 20));
    $files = array_merge(glob(dn_backup_dir() . '/dispatch_next_*.json') ?: [], glob(dn_backup_dir() . '/dispatch_next_*.zip') ?: []);
    usort($files, fn($a, $b) => (filemtime($b) ?: 0) <=> (filemtime($a) ?: 0));
    foreach (array_slice($files, $keep) as $file) @unlink($file);
}

function dn_restore_backup(array $in): array
{
    dn_require('restore', '没有恢复权限');
    $name = basename(dn_str($in['name'] ?? $in['file'] ?? '', 255));
    if ($name === '' || !preg_match('/^dispatch_next_[A-Za-z0-9_]+_\d{8}_\d{6}\.(json|zip)$/i', $name)) dn_fail('备份文件名无效');
    $file = dn_backup_dir() . '/' . $name;
    if (!is_file($file)) dn_fail('备份文件不存在');
    $backupData = dn_backup_payload_from_file($file);
    $pre = dn_create_backup('pre_restore');
    $pdo = dispatch_next_db();
    $tables = dn_backup_tables();
    try {
        $pdo->beginTransaction();
        foreach (array_reverse($tables) as $table) $pdo->exec("DELETE FROM `{$table}`");
        foreach ($tables as $table) {
            $rows = (array)($backupData[$table] ?? []);
            if (!$rows) continue;
            $cols = dn_table_columns($table);
            foreach ($rows as $row) {
                if (!is_array($row)) continue;
                $row = array_intersect_key($row, array_flip($cols));
                if (!$row) continue;
                $fields = array_keys($row);
                $sql = "INSERT INTO `{$table}` (`" . implode('`,`', array_map(fn($c) => str_replace('`', '``', $c), $fields)) . "`) VALUES (" . implode(',', array_fill(0, count($fields), '?')) . ")";
                $pdo->prepare($sql)->execute(array_values($row));
            }
        }
        $pdo->commit();
        dn_log(null, 'backup_restore', 'file', $name, $pre['name'] ?? '', '恢复派工待办备份，恢复前已自动备份');
        return ['restored' => $name, 'pre_restore_backup' => $pre['name'] ?? '', 'message' => '恢复完成；恢复前备份已保留。'];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        dn_log(null, 'backup_restore_failed', 'file', $name, $e->getMessage(), '恢复失败，已自动回滚事务');
        dn_fail('恢复失败，已自动回滚：' . $e->getMessage(), 500);
    }
}

function dn_preview_link(array $in): array
{
    $system = strtolower(dn_str($in['system'] ?? '', 30));
    $code = dn_str($in['code'] ?? '', 80);
    $systems = [
        'naming' => ['label' => '命名', 'permission' => 'view', 'actions' => ['查看型号', '复制资料', '转资料生成']],
        'bom' => ['label' => 'BOM', 'permission' => 'view', 'actions' => ['查看成本摘要', '生成报价参考']],
        'quote' => ['label' => '报价', 'permission' => 'view', 'actions' => ['查看报价', '发送报价', '导出 PDF']],
        'plm' => ['label' => 'PLM', 'permission' => 'view', 'actions' => ['查看项目', '转资料生成', '创建派工']],
        'snapshot' => ['label' => '快照', 'permission' => 'view', 'actions' => ['查看资料快照', '生成资料包', '下载资料']],
        'material' => ['label' => '资料', 'permission' => 'view', 'actions' => ['查看资料包', '下载资料', '转派工']],
        'dispatch' => ['label' => '派工', 'permission' => 'view', 'actions' => ['查看派工详情', '催办', '复制链接']],
        'opportunity' => ['label' => '商机', 'permission' => 'view', 'actions' => ['查看商机', '转推广任务', '新建跟进']],
    ];
    if (!isset($systems[$system])) dn_fail('不支持的联动系统');
    if ($code === '') dn_fail('缺少联动编号');
    dn_require('view_link_preview', '没有查看联动预览权限');

    $result = [
        'system' => $system,
        'label' => $systems[$system]['label'],
        'code' => $code,
        'status' => 'reserved',
        'status_label' => '待接入',
        'message' => '已预留 ' . $systems[$system]['label'] . ' 预览接口；正式模块接入后会在此弹窗显示真实详情，不跳转页面。',
        'fields' => [
            ['label' => '匹配方式', 'value' => '@' . $systems[$system]['label'] . ' ' . $code],
            ['label' => '接入状态', 'value' => '待接入'],
        ],
        'actions' => array_map(fn($label) => ['label' => $label, 'hint' => '接口待接入'], $systems[$system]['actions']),
    ];

    if ($system === 'naming') {
        $naming = dn_preview_naming_model($code, $systems[$system]['actions']);
        if ($naming) {
            dn_log(null, 'preview_link', $system, '', $code, '派工表格联动预览');
            return $naming;
        }
    }
    if ($system === 'quote') {
        $quote = dn_preview_quote_order($code, $systems[$system]['actions']);
        if ($quote) {
            dn_log(null, 'preview_link', $system, '', $code, '派工表格联动预览');
            return $quote;
        }
    }
    if ($system === 'bom') {
        $bom = dn_preview_bom_project($code, $systems[$system]['actions']);
        if ($bom) {
            dn_log(null, 'preview_link', $system, '', $code, '派工表格联动预览');
            return $bom;
        }
    }
    if ($system === 'snapshot') {
        $snapshot = dn_preview_datasheet_snapshot($code, $systems[$system]['actions']);
        if ($snapshot) {
            dn_log(null, 'preview_link', $system, '', $code, '派工表格联动预览');
            return $snapshot;
        }
    }

    $candidate = dn_preview_link_candidate($system);
    if ($candidate && artdon_sso_table_exists($candidate['table'])) {
        $pdo = dispatch_next_db();
        $cols = dn_table_columns($candidate['table']);
        $matchCols = array_values(array_filter($candidate['match'], fn($col) => in_array($col, $cols, true)));
        if ($matchCols) {
            $where = implode(' OR ', array_map(fn($col) => "`{$col}` = ?", $matchCols));
            $st = $pdo->prepare("SELECT * FROM `{$candidate['table']}` WHERE {$where} ORDER BY id DESC LIMIT 1");
            $st->execute(array_fill(0, count($matchCols), $code));
            $row = $st->fetch();
            if ($row) {
                $fields = [];
                foreach ($candidate['fields'] as $col => $label) {
                    if (array_key_exists($col, $row)) $fields[] = ['label' => $label, 'value' => (string)($row[$col] ?? '')];
                }
                $result['status'] = 'connected';
                $result['status_label'] = '已接入';
                $result['message'] = '已从 ' . $candidate['table'] . ' 读取到匹配记录。';
                $result['fields'] = array_merge([
                    ['label' => '数据表', 'value' => $candidate['table']],
                    ['label' => '记录 ID', 'value' => (string)($row['id'] ?? '')],
                ], $fields);
            }
        }
    }
    dn_log(null, 'preview_link', $system, '', $code, '派工表格联动预览');
    return $result;
}

function dn_preview_datasheet_snapshot(string $code, array $actions): ?array
{
    if (!artdon_sso_table_exists('datasheet_snapshots')) return null;
    if (function_exists('artdon_datasheet_ensure_permissions')) artdon_datasheet_ensure_permissions();
    if (!artdon_sso_can('datasheet', 'view', dn_user())) return null;

    $pdo = dispatch_next_db();
    $where = ['model_no = ?'];
    $args = [$code];
    $norm = dn_normalize_link_code($code);
    if ($norm !== '') {
        $where[] = "REPLACE(REPLACE(REPLACE(REPLACE(UPPER(COALESCE(model_no,'')),'.',''),'-',''),' ',''),'_','') = ?";
        $args[] = $norm;
    }
    if (ctype_digit($code)) {
        $where[] = 'id = ?';
        $args[] = (int)$code;
    }
    $st = $pdo->prepare('SELECT * FROM datasheet_snapshots WHERE ' . implode(' OR ', $where) . ' ORDER BY created_at DESC, id DESC LIMIT 1');
    $st->execute($args);
    $row = $st->fetch();
    if (!$row) return null;

    $snapshot = json_decode((string)($row['snapshot_json'] ?? ''), true);
    if (!is_array($snapshot)) $snapshot = [];
    $product = is_array($snapshot['product'] ?? null) ? $snapshot['product'] : [];
    $params = is_array($snapshot['params'] ?? null) ? $snapshot['params'] : [];
    $files = is_array($snapshot['files'] ?? null) ? $snapshot['files'] : [];
    $curves = is_array($snapshot['photometric_images'] ?? null) ? $snapshot['photometric_images'] : [];
    $accessories = is_array($snapshot['accessories'] ?? null) ? $snapshot['accessories'] : [];

    $image = dn_datasheet_snapshot_media($product, false);
    $drawing = dn_datasheet_snapshot_media($product, true);
    $paramText = [];
    foreach (array_slice($params, 0, 8, true) as $k => $v) {
        if (trim((string)$v) !== '') $paramText[] = $k . ': ' . $v;
    }

    return [
        'system' => 'snapshot',
        'label' => '资料快照',
        'code' => (string)($row['model_no'] ?: $code),
        'status' => 'connected',
        'status_label' => '已接入',
        'message' => '已从资料生成系统读取该型号最新资料快照；不跳转页面。',
        'media' => array_values(array_filter([
            $image ? ['label' => '产品图', 'url' => $image] : null,
            $drawing ? ['label' => '尺寸图', 'url' => $drawing] : null,
        ])),
        'fields' => [
            ['label' => '快照 ID', 'value' => (string)($row['id'] ?? '')],
            ['label' => '型号', 'value' => (string)($row['model_no'] ?? '')],
            ['label' => '标题', 'value' => (string)($row['snapshot_title'] ?? '')],
            ['label' => '客户', 'value' => (string)($row['customer_name'] ?? '')],
            ['label' => '客户邮箱', 'value' => (string)($row['customer_email'] ?? '')],
            ['label' => '创建人', 'value' => (string)($row['created_by'] ?? '')],
            ['label' => '创建时间', 'value' => (string)($row['created_at'] ?? '')],
            ['label' => '产品名称', 'value' => dn_first_value($product, ['title', 'product_name', 'item_name', 'type_name'])],
            ['label' => '系列/分类', 'value' => trim(dn_first_value($product, ['series', 'product_series']) . ' / ' . dn_first_value($product, ['category', 'product_category']), ' /')],
            ['label' => '尺寸', 'value' => dn_first_value($product, ['dimension_text', 'dimensions'])],
            ['label' => '参数摘要', 'value' => implode('；', $paramText)],
            ['label' => '资料数量', 'value' => count($files) . ' 文件 / ' . count($curves) . ' 配光 / ' . count($accessories) . ' 配件'],
            ['label' => '说明', 'value' => (string)($row['send_note'] ?? '')],
        ],
        'actions' => array_map(fn($label) => ['label' => $label, 'hint' => '资料快照预览已接入；下载/生成仍按资料系统权限控制。'], $actions),
    ];
}

function dn_datasheet_snapshot_media(array $product, bool $drawing): string
{
    $keys = $drawing
        ? ['drawing_url', 'drawing_image_url', 'dimension_image_url', 'web_dimension_url']
        : ['image_url', 'product_image_url', 'web_image_url', 'cover_image'];
    foreach ($keys as $key) {
        $value = trim((string)($product[$key] ?? ''));
        if ($value !== '') return dn_bom_asset_url($value);
    }
    return '';
}

function dn_quote_can_view_price(): bool
{
    $user = dn_user();
    return artdon_sso_role_is_admin($user)
        || artdon_sso_can('quote', 'admin', $user)
        || artdon_sso_can('quote', 'approve', $user)
        || artdon_sso_can('quote', 'export', $user);
}

function dn_bom_can_view_cost(): bool
{
    $user = dn_user();
    return artdon_sso_role_is_admin($user)
        || artdon_sso_can('bom', 'admin', $user)
        || artdon_sso_can('bom', 'cost_view', $user);
}

function dn_bom_can_view_supplier(): bool
{
    $user = dn_user();
    return artdon_sso_role_is_admin($user)
        || artdon_sso_can('bom', 'admin', $user)
        || artdon_sso_can('bom', 'supplier_view', $user);
}

function dn_preview_bom_project(string $code, array $actions): ?array
{
    if (!artdon_sso_table_exists('bom_projects')) return null;
    $pdo = dispatch_next_db();
    $cols = dn_table_columns('bom_projects');
    $where = [];
    $args = [];
    foreach (['project_uid', 'model', 'naming_model_no', 'linked_title'] as $col) {
        if (in_array($col, $cols, true)) {
            $where[] = "`{$col}` = ?";
            $args[] = $code;
        }
    }
    $norm = dn_normalize_link_code($code);
    if ($norm !== '') {
        foreach (['model', 'naming_model_no'] as $col) {
            if (in_array($col, $cols, true)) {
                $where[] = "REPLACE(REPLACE(REPLACE(REPLACE(UPPER(COALESCE(`{$col}`,'')),'.',''),'-',''),' ',''),'_','') = ?";
                $args[] = $norm;
            }
        }
        foreach (['linked_title', 'project_uid'] as $col) {
            if (in_array($col, $cols, true)) {
                $where[] = "REPLACE(REPLACE(REPLACE(REPLACE(UPPER(COALESCE(`{$col}`,'')),'.',''),'-',''),' ',''),'_','') = ?";
                $args[] = $norm;
            }
        }
    }
    if (in_array('id', $cols, true) && ctype_digit($code)) {
        $where[] = 'id = ?';
        $args[] = (int)$code;
    }
    if (in_array('linked_id', $cols, true) && artdon_sso_table_exists('naming_models')) {
        $namingId = dn_bom_naming_id_by_model($code);
        if ($namingId > 0) {
            $where[] = 'linked_id = ?';
            $args[] = (string)$namingId;
            if (in_array('naming_id', $cols, true)) {
                $where[] = 'naming_id = ?';
                $args[] = $namingId;
            }
        }
    }
    if (!$where) return null;
    $active = in_array('is_active', $cols, true) ? " AND (is_active=1 OR is_active IS NULL)" : "";
    $st = $pdo->prepare('SELECT * FROM bom_projects WHERE (' . implode(' OR ', $where) . ')' . $active . ' ORDER BY updated_at DESC, id DESC LIMIT 1');
    $st->execute($args);
    $row = $st->fetch();
    if (!$row) return null;

    $canBomView = artdon_sso_can('bom', 'view', dn_user()) || artdon_sso_can('bom', 'dashboard', dn_user()) || artdon_sso_can('bom', 'edit', dn_user());
    $canCost = $canBomView && dn_bom_can_view_cost();
    $canSupplier = $canBomView && dn_bom_can_view_supplier();
    $rows = dn_bom_rows_from_project($row, $canCost, $canSupplier);
    $currency = (string)($row['currency'] ?? 'RMB');
    $materialCost = dn_bom_rows_cost($rows);
    $laborCost = (float)($row['labor'] ?? 0);
    $otherCost = (float)($row['other'] ?? 0);
    $extraCost = $laborCost + $otherCost;
    $totalCost = $materialCost + $extraCost;
    $model = dn_first_value($row, ['model', 'naming_model_no', 'linked_title', 'project_uid']);
    $fields = [
        ['label' => 'BOM UID', 'value' => (string)($row['project_uid'] ?? '')],
        ['label' => '型号', 'value' => $model],
        ['label' => '名称', 'value' => (string)($row['name'] ?? '')],
        ['label' => '客户', 'value' => (string)($row['customer'] ?? '')],
        ['label' => '产品类型', 'value' => (string)($row['product_type'] ?? '')],
        ['label' => '物料行数', 'value' => (string)count($rows)],
        ['label' => '更新时间', 'value' => (string)($row['updated_at'] ?? '')],
        ['label' => '更新人', 'value' => (string)($row['updated_by'] ?? '')],
    ];
    if ($canCost) {
        $fields[] = ['label' => '币种', 'value' => $currency];
        $fields[] = ['label' => '物料成本', 'value' => dn_bom_money($materialCost)];
        $fields[] = ['label' => '人工/其它', 'value' => dn_bom_money($extraCost)];
        $fields[] = ['label' => '总成本', 'value' => dn_bom_money($totalCost)];
    } else {
        $fields[] = ['label' => '成本权限', 'value' => '当前账号无 BOM 成本权限，成本/单价/金额已隐藏'];
    }
    if (!$canBomView) {
        $fields[] = ['label' => 'BOM 查看权限', 'value' => '当前账号没有完整 BOM 查看权限，仅显示派工联动基础预览；成本、供应商已隐藏'];
    } elseif (!$canSupplier) {
        $fields[] = ['label' => '供应商权限', 'value' => '当前账号无供应商权限，供应商字段已隐藏'];
    }
    $publicRows = $rows;
    foreach ($publicRows as &$publicRow) {
        unset($publicRow['_amount_num']);
    }
    unset($publicRow);

    return [
        'system' => 'bom',
        'label' => 'BOM',
        'code' => $model ?: $code,
        'status' => 'connected',
        'status_label' => '已接入',
        'message' => $canCost ? '已从 BOM 系统读取成本单明细。' : '已从 BOM 系统读取成本单明细；成本字段按权限隐藏。',
        'media' => !empty($row['product_image']) ? [['label' => '产品图', 'url' => dn_bom_asset_url((string)$row['product_image'])]] : [],
        'fields' => $fields,
        'items' => $publicRows,
        'summary' => $canCost ? [
            'currency' => $currency,
            'material_cost' => dn_bom_money($materialCost),
            'labor' => dn_bom_money($laborCost),
            'other' => dn_bom_money($otherCost),
            'extra_cost' => dn_bom_money($extraCost),
            'total_cost' => dn_bom_money($totalCost),
        ] : [],
        'can_view_cost' => $canCost ? 1 : 0,
        'can_view_supplier' => $canSupplier ? 1 : 0,
        'actions' => array_map(fn($label) => ['label' => $label, 'hint' => 'BOM 预览已接入；执行操作仍按 BOM 权限控制。'], $actions),
    ];
}

function dn_bom_naming_id_by_model(string $code): int
{
    $cols = dn_table_columns('naming_models');
    if (!in_array('id', $cols, true)) return 0;
    $norm = dn_normalize_link_code($code);
    $where = [];
    $args = [];
    foreach (['model_no', 'product_model', 'code', 'product_code', 'source_id'] as $col) {
        if (!in_array($col, $cols, true)) continue;
        $where[] = "`{$col}` = ?";
        $args[] = $code;
        if ($norm !== '') {
            $where[] = "REPLACE(REPLACE(REPLACE(REPLACE(UPPER(COALESCE(`{$col}`,'')),'.',''),'-',''),' ',''),'_','') = ?";
            $args[] = $norm;
        }
    }
    if (!$where) return 0;
    $st = dispatch_next_db()->prepare('SELECT id FROM naming_models WHERE ' . implode(' OR ', $where) . ' ORDER BY id DESC LIMIT 1');
    $st->execute($args);
    return (int)($st->fetchColumn() ?: 0);
}

function dn_bom_rows_from_project(array $project, bool $canCost, bool $canSupplier): array
{
    $rows = json_decode((string)($project['rows_json'] ?? ''), true);
    if (!is_array($rows)) $rows = [];
    $out = [];
    foreach (array_values($rows) as $i => $row) {
        if (!is_array($row)) continue;
        $qty = is_numeric($row['qty'] ?? null) ? (float)$row['qty'] : 0.0;
        $price = is_numeric($row['price'] ?? null) ? (float)$row['price'] : 0.0;
        $process = is_numeric($row['process'] ?? null) ? (float)$row['process'] : 0.0;
        $finish = is_numeric($row['finishCost'] ?? ($row['finish_cost'] ?? null)) ? (float)($row['finishCost'] ?? ($row['finish_cost'] ?? 0)) : 0.0;
        $finish2 = is_numeric($row['finishCost2'] ?? ($row['finish_cost2'] ?? null)) ? (float)($row['finishCost2'] ?? ($row['finish_cost2'] ?? 0)) : 0.0;
        $amount = $qty * ($price + $process + $finish + $finish2);
        $line = [
            'index' => $i + 1,
            'category' => (string)($row['category'] ?? ''),
            'name' => (string)($row['name'] ?? ''),
            'model' => (string)($row['model'] ?? ($row['materialId'] ?? '')),
            'spec' => (string)($row['spec'] ?? ''),
            'qty' => (string)($row['qty'] ?? ''),
            'unit' => (string)($row['unit'] ?? ''),
            'finish' => trim((string)($row['finish'] ?? '') . ' ' . (string)($row['finish2'] ?? '')),
        ];
        if ($canSupplier) $line['supplier'] = (string)($row['supplier'] ?? '');
        if ($canCost) {
            $line['price'] = dn_bom_money($price);
            $line['process'] = dn_bom_money($process + $finish + $finish2);
            $line['amount'] = dn_bom_money($amount);
            $line['_amount_num'] = $amount;
        }
        $out[] = $line;
    }
    return $out;
}

function dn_bom_rows_cost(array $rows): float
{
    $sum = 0.0;
    foreach ($rows as $row) {
        $sum += (float)($row['_amount_num'] ?? 0);
    }
    return $sum;
}

function dn_bom_money($v): string
{
    return is_numeric($v) ? number_format((float)$v, 2, '.', '') : '';
}

function dn_bom_asset_url(string $path): string
{
    $path = trim(str_replace('\\', '/', $path));
    if ($path === '') return '';
    if (preg_match('#^https?://#i', $path)) return $path;
    if (strpos($path, '//') === 0) return 'https:' . $path;
    return $path;
}

function dn_preview_quote_order(string $code, array $actions): ?array
{
    if (!artdon_sso_table_exists('quote_orders')) return null;
    $pdo = dispatch_next_db();
    $cols = dn_table_columns('quote_orders');
    $where = [];
    $args = [];
    if (in_array('quote_no', $cols, true)) {
        $where[] = 'quote_no = ?';
        $args[] = $code;
    }
    if (in_array('id', $cols, true) && ctype_digit($code)) {
        $where[] = 'id = ?';
        $args[] = (int)$code;
    }
    if (!$where) return null;
    $st = $pdo->prepare('SELECT * FROM quote_orders WHERE ' . implode(' OR ', $where) . ' ORDER BY id DESC LIMIT 1');
    $st->execute($args);
    $row = $st->fetch();
    if (!$row) return null;

    $canPrice = dn_quote_can_view_price();
    $quoteNo = (string)($row['quote_no'] ?? $code);
    $currency = (string)($row['currency'] ?? '');
    $items = dn_quote_items_from_row($row, $canPrice);
    $fields = [
        ['label' => '报价编号', 'value' => $quoteNo],
        ['label' => '客户', 'value' => (string)($row['customer_name'] ?? '')],
        ['label' => '报价日期', 'value' => (string)($row['quote_date'] ?? '')],
        ['label' => '负责人', 'value' => (string)($row['user_name'] ?? '')],
        ['label' => '状态', 'value' => (string)($row['quote_status'] ?? ($row['status'] ?? ''))],
        ['label' => '产品数量', 'value' => (string)count($items)],
    ];
    if ($canPrice) {
        $fields[] = ['label' => '币种', 'value' => $currency];
        $fields[] = ['label' => '总数量', 'value' => (string)($row['qty'] ?? '')];
        $fields[] = ['label' => '总金额', 'value' => trim($currency . ' ' . dn_quote_money($row['amount'] ?? 0))];
    } else {
        $fields[] = ['label' => '价格权限', 'value' => '当前账号无报价价格权限，单价/金额已隐藏'];
    }

    return [
        'system' => 'quote',
        'label' => '报价',
        'code' => $quoteNo,
        'status' => 'connected',
        'status_label' => '已接入',
        'message' => $canPrice ? '已从报价系统读取报价明细。' : '已从报价系统读取报价明细；价格字段按权限隐藏。',
        'fields' => $fields,
        'items' => $items,
        'can_view_price' => $canPrice ? 1 : 0,
        'actions' => array_map(fn($label) => ['label' => $label, 'hint' => '报价预览已接入；执行操作仍按报价系统权限控制。'], $actions),
    ];
}

function dn_quote_items_from_row(array $row, bool $canPrice): array
{
    $items = json_decode((string)($row['items_json'] ?? ''), true);
    if (!is_array($items) || !$items) {
        $product = json_decode((string)($row['product_json'] ?? ''), true);
        if (is_array($product) && $product) {
            $items = [[
                'product' => $product,
                'qty' => $row['qty'] ?? '',
                'price' => $row['price'] ?? '',
                'amount' => $row['amount'] ?? '',
                'color' => $row['color'] ?? '',
                'cct' => $row['cct'] ?? '',
                'cri' => $row['cri'] ?? '',
                'ip' => $row['ip'] ?? '',
                'extra_spec' => $row['extra_spec'] ?? '',
            ]];
        }
    }
    $out = [];
    foreach (array_values(is_array($items) ? $items : []) as $i => $item) {
        if (!is_array($item)) continue;
        $p = is_array($item['product'] ?? null) ? $item['product'] : [];
        $line = [
            'index' => $i + 1,
            'model' => dn_first_value($p, ['code', 'model', 'model_no', 'naming_model_no', 'product_code']),
            'name' => dn_first_value($p, ['name', 'product_name', 'title']),
            'size' => dn_first_value($p, ['size', 'dimension', 'dimensions']),
            'power' => dn_first_value($p, ['power', 'watt', 'wattage']),
            'color' => (string)($item['color'] ?? ($p['color'] ?? '')),
            'cct' => (string)($item['cct'] ?? ($p['cct'] ?? '')),
            'cri' => (string)($item['cri'] ?? ($p['cri'] ?? '')),
            'ip' => (string)($item['ip'] ?? ($p['ip'] ?? '')),
            'qty' => (string)($item['qty'] ?? ''),
            'spec' => trim((string)($item['extra_spec'] ?? '') . ' ' . dn_quote_spec_summary($p)),
        ];
        if ($canPrice) {
            $line['price'] = dn_quote_money($item['price'] ?? 0);
            $line['amount'] = dn_quote_money($item['amount'] ?? 0);
        }
        $out[] = $line;
    }
    return $out;
}

function dn_quote_spec_summary(array $p): string
{
    $parts = [];
    foreach (['category', 'series', 'cutout', 'beam_angle'] as $key) {
        $value = trim((string)($p[$key] ?? ''));
        if ($value !== '') $parts[] = $value;
    }
    return implode(' / ', $parts);
}

function dn_quote_money($v): string
{
    return is_numeric($v) ? number_format((float)$v, 2, '.', '') : '';
}

function dn_preview_naming_model(string $code, array $actions): ?array
{
    if (!artdon_sso_table_exists('naming_models')) return null;
    $pdo = dispatch_next_db();
    $cols = dn_table_columns('naming_models');
    if (!in_array('model_no', $cols, true)) return null;

    $norm = dn_normalize_link_code($code);
    $where = ["model_no = ?"];
    $args = [$code];
    if ($norm !== '') {
        $where[] = "REPLACE(REPLACE(REPLACE(REPLACE(UPPER(COALESCE(model_no,'')),'.',''),'-',''),' ',''),'_','') = ?";
        $args[] = $norm;
    }
    $st = $pdo->prepare("SELECT * FROM naming_models WHERE " . implode(' OR ', $where) . " ORDER BY id DESC LIMIT 1");
    $st->execute($args);
    $row = $st->fetch();
    if (!$row) return null;

    $modelNo = (string)($row['model_no'] ?? $code);
    $series = dn_first_value($row, ['web_series', 'series_name', 'product_name', 'category']);
    $lamp = dn_first_value($row, ['lamp_type', 'item_name', 'web_size_name']);
    $dimensions = dn_first_value($row, ['web_dimensions', 'dimensions', 'size_text']);
    if ($dimensions === '') $dimensions = dn_dimension_summary($row);
    $image = dn_naming_media_url($row, false);
    $drawing = dn_naming_media_url($row, true);
    $source = dn_first_value($row, ['source_url']);
    $isWebsite = ((int)($row['website_sync_managed'] ?? 0) === 1) || (string)($row['source_system'] ?? '') === 'artdon_website';

    return [
        'system' => 'naming',
        'label' => '命名',
        'code' => $modelNo,
        'status' => 'connected',
        'status_label' => '已接入',
        'message' => '已从型号命名库读取真实型号资料，可在派工里直接预览，不跳转页面。',
        'media' => array_values(array_filter([
            $image ? ['label' => '产品图', 'url' => $image] : null,
            $drawing ? ['label' => '尺寸图', 'url' => $drawing] : null,
        ])),
        'fields' => [
            ['label' => '型号', 'value' => $modelNo],
            ['label' => '系列 / 产品', 'value' => $series],
            ['label' => '灯具类型', 'value' => $lamp],
            ['label' => '尺寸 / 规格', 'value' => $dimensions],
            ['label' => '尺寸代码', 'value' => (string)($row['size_code'] ?? '')],
            ['label' => '分类', 'value' => (string)($row['category'] ?? '')],
            ['label' => '状态', 'value' => (string)($row['status'] ?? '')],
            ['label' => '来源', 'value' => $isWebsite ? '官网同步' : '命名中心'],
            ['label' => '更新时间', 'value' => (string)($row['updated_at'] ?? '')],
            ['label' => '官网链接', 'value' => $source],
        ],
        'actions' => array_map(fn($label) => ['label' => $label, 'hint' => '命名资料已接入预览；后续可扩展为一键复制/转资料。'], $actions),
    ];
}

function dn_normalize_link_code(string $code): string
{
    return strtoupper(preg_replace('/[^A-Z0-9]+/i', '', $code));
}

function dn_first_value(array $row, array $keys): string
{
    foreach ($keys as $key) {
        if (isset($row[$key]) && trim((string)$row[$key]) !== '') return trim((string)$row[$key]);
    }
    return '';
}

function dn_dimension_summary(array $row): string
{
    $parts = [];
    $map = [
        'dimension_type' => '类型',
        'dim_opening' => '开孔',
        'dim_outer_d' => '直径',
        'dim_length' => '长',
        'dim_width' => '宽',
        'dim_height' => '高',
    ];
    foreach ($map as $key => $label) {
        $value = trim((string)($row[$key] ?? ''));
        if ($value !== '') $parts[] = $label . ' ' . $value;
    }
    return implode(' / ', $parts);
}

function dn_naming_media_url(array $row, bool $drawing): string
{
    $keys = $drawing
        ? ['drawing_path', 'web_dimension_url', 'web_drawing_url', 'source_drawing_url', 'dimension_url', 'drawing_url', 'size_image_url', 'web_size_image_url']
        : ['image_path', 'web_image_url', 'source_image_url', 'product_image', 'image_url', 'main_image', 'cover_image', 'cover_url'];
    foreach ($keys as $key) {
        $value = trim((string)($row[$key] ?? ''));
        if ($value !== '') return dn_naming_asset_url($value);
    }
    return '';
}

function dn_naming_asset_url(string $path): string
{
    $path = str_replace('\\', '/', trim($path));
    if ($path === '') return '';
    $websiteBase = 'https://artdonlighting.com';
    if (preg_match('#^https?://#i', $path)) {
        $u = @parse_url($path);
        $host = strtolower((string)($u['host'] ?? ''));
        $p = (string)($u['path'] ?? '');
        if ($p !== '' && (strpos($p, '/uploads/website/') === 0 || strpos($p, '/uploads/products/') === 0)) {
            return rtrim($websiteBase, '/') . $p . (!empty($u['query']) ? '?' . $u['query'] : '');
        }
        if (($host === '43.132.210.162' || strpos($host, 'gallin.cn') !== false) && $p !== '') {
            return rtrim($websiteBase, '/') . $p;
        }
        return $path;
    }
    if (strpos($path, '//') === 0) return 'https:' . $path;
    $clean = preg_replace('/[?#].*$/', '', $path);
    if (strpos($clean, '/uploads/website/') === 0 || strpos($clean, '/uploads/products/') === 0) return rtrim($websiteBase, '/') . $clean;
    if (strpos($clean, 'uploads/website/') === 0 || strpos($clean, 'uploads/products/') === 0) return rtrim($websiteBase, '/') . '/' . $clean;
    return $path;
}

function dn_table_columns(string $table): array
{
    try {
        $rows = dispatch_next_db()->query("SHOW COLUMNS FROM `" . str_replace('`', '``', $table) . "`")->fetchAll();
        return array_map(fn($r) => (string)$r['Field'], $rows ?: []);
    } catch (Throwable $e) {
        return [];
    }
}

function dn_preview_link_candidate(string $system): ?array
{
    $map = [
        'quote' => ['table' => 'crm_quotes', 'match' => ['quote_no', 'quote_code', 'id'], 'fields' => ['quote_no' => '报价编号', 'customer_id' => '客户 ID', 'status' => '状态', 'created_at' => '创建时间']],
        'plm' => ['table' => 'crm_plm_projects', 'match' => ['project_no', 'project_code', 'id'], 'fields' => ['project_no' => '项目编号', 'project_name' => '项目名称', 'status' => '状态', 'updated_at' => '更新时间']],
        'bom' => ['table' => 'crm_bom_items', 'match' => ['bom_no', 'model_no', 'id'], 'fields' => ['bom_no' => 'BOM 编号', 'model_no' => '型号', 'status' => '状态', 'updated_at' => '更新时间']],
        'naming' => ['table' => 'crm_product_models', 'match' => ['model_no', 'model_code', 'id'], 'fields' => ['model_no' => '型号', 'product_name' => '产品名称', 'category' => '分类', 'updated_at' => '更新时间']],
    ];
    return $map[$system] ?? null;
}

try {
    dispatch_next_init_schema();
    dn_cleanup_expired_attachments();
    $in = dn_input();
    $action = dn_str($in['action'] ?? 'me', 80);
    switch ($action) {
        case 'me': dn_ok(dn_me_data());
        case 'init_schema': dn_require('init_schema', '没有初始化权限'); dn_ok(dispatch_next_init_schema());
        case 'list_tasks': dn_ok(dn_list($in));
        case 'list': dn_ok(dn_list($in));
        case 'sync_version': dn_ok(dn_sync_version());
        case 'detail': dn_ok(dn_detail($in));
        case 'task_detail': dn_ok(dn_detail($in));
        case 'create_task': dn_ok(dn_create_task($in));
        case 'update_task': dn_ok(dn_update_task($in));
        case 'delete_task': dn_ok(dn_delete_task($in));
        case 'restore_task': dn_ok(dn_restore_task($in));
        case 'create_multi': dn_ok(dn_create_multi($in));
        case 'update_multi': dn_ok(dn_update_multi($in));
        case 'delete_multi':
            $gid = (int)($in['group_id'] ?? 0);
            $gst = dispatch_next_db()->prepare("SELECT created_by FROM dispatch_next_groups WHERE id=? LIMIT 1");
            $gst->execute([$gid]);
            $g = $gst->fetch();
            if (!$g) dn_fail('多人组不存在', 404);
            if (!dn_is_admin() && (int)$g['created_by'] !== dn_uid()) dn_fail('只有派工人可以删除多人组', 403);
            dispatch_next_db()->prepare("UPDATE dispatch_next_tasks SET is_deleted=1, deleted_at=NOW(), deleted_by=? WHERE parent_group_id=?")->execute([dn_uid(), $gid]);
            dispatch_next_db()->prepare("UPDATE dispatch_next_groups SET is_active=0,status='deleted',updated_at=NOW() WHERE id=?")->execute([$gid]);
            dn_log(null, 'delete_multi', 'group_id', $gid, 'deleted', '删除整组多人');
            dn_ok(['group_id' => $gid]);
        case 'create_plan': dn_ok(dn_create_plan($in));
        case 'create_recurring': dn_ok(dn_create_recurring($in));
        case 'run_recurring': dn_ok(dn_run_recurring($in));
        case 'add_comment': dn_ok(dn_add_comment($in));
        case 'list_comments': dn_ok(dn_detail($in)['comments']);
        case 'delete_comment': dn_ok(dn_delete_comment($in));
        case 'save_progress': dn_ok(dn_save_progress($in));
        case 'upload_image': dn_ok(dn_upload($in, 'image'));
        case 'upload_attachment': dn_ok(dn_upload($in, 'attachment'));
        case 'list_images':
            $detail = dn_detail($in);
            dn_ok(array_values(array_filter($detail['attachments'], fn($a) => ($a['file_kind'] ?? '') === 'image')));
        case 'list_attachments':
            $detail = dn_detail($in);
            dn_ok(array_values(array_filter($detail['attachments'], fn($a) => ($a['file_kind'] ?? '') === 'attachment')));
        case 'delete_attachment': dn_ok(dn_delete_attachment($in));
        case 'download_attachment': dn_download_attachment($in);
        case 'preview_image': dn_download_attachment(['attachment_id' => $in['attachment_id'] ?? 0]);
        case 'list_steps': dn_ok(dn_detail($in)['steps']);
        case 'save_step': dn_ok(dn_save_step($in));
        case 'delete_step': dn_ok(dn_delete_step($in));
        case 'reorder_steps': dn_ok(dn_reorder_steps($in));
        case 'complete_step': dn_ok(dn_set_step_status($in, 'done'));
        case 'block_step': dn_ok(dn_set_step_status($in, 'blocked'));
        case 'dispatch_step': dn_ok(dn_dispatch_step($in));
        case 'list_step_templates': dn_ok([]);
        case 'apply_step_template': dn_fail('步骤模板待接入', 501);
        case 'save_step_template': dn_fail('步骤模板待接入', 501);
        case 'accept_task': dn_ok(dn_set_task_status($in, 'accepted'));
        case 'start_task': dn_ok(dn_set_task_status($in, 'in_progress'));
        case 'pause_task': dn_ok(dn_set_task_status($in, 'paused'));
        case 'submit_task': dn_ok(dn_set_task_status($in, 'submitted'));
        case 'complete_task': dn_ok(dn_set_task_status($in, 'done'));
        case 'cancel_task': dn_ok(dn_set_task_status($in, 'cancelled'));
        case 'list_notifications': dn_ok(dn_notifications(!empty($in['mark'])));
        case 'merge_duplicate_notifications': dn_ok(dn_merge_duplicate_update_notifications());
        case 'online_status': dn_ok(dn_online_status());
        case 'online_leave': dn_ok(dn_online_leave());
        case 'online_logs': dn_ok(dn_online_logs($in));
        case 'mark_read': dn_ok(dn_mark_read($in));
        case 'mark_task_read': dn_ok(dn_mark_task_read($in));
        case 'urge_task': dn_ok(dn_urge_task($in));
        case 'log_action':
            dn_log((int)($in['task_id'] ?? 0) ?: null, dn_str($in['type'] ?? 'manual', 80), dn_str($in['field'] ?? '', 80) ?: null, $in['old'] ?? '', $in['new'] ?? '', dn_str($in['note'] ?? '', 500));
            dn_ok(['logged' => true]);
        case 'list_logs':
            dn_require('view_logs', '没有查看派工日志权限');
            $id = (int)($in['task_id'] ?? 0);
            if ($id > 0) dn_task($id);
            $st = dispatch_next_db()->prepare("SELECT l.*, COALESCE(NULLIF(u.real_name,''), u.username) AS user_name FROM dispatch_next_logs l LEFT JOIN crm_users u ON u.id=l.user_id WHERE (?=0 OR l.task_id=?) ORDER BY l.id DESC LIMIT 200");
            $st->execute([$id, $id]);
            dn_ok($st->fetchAll());
        case 'get_table_prefs': dn_ok(dn_get_table_prefs($in));
        case 'save_table_prefs': dn_ok(dn_save_table_prefs($in));
        case 'save_column_width': dn_ok(dn_save_column_width($in));
        case 'update_cell': dn_ok(dn_update_cell($in));
        case 'save_row_order': dn_ok(dn_save_row_order($in));
        case 'get_custom_fields': dn_ok(dn_custom_fields());
        case 'save_custom_field': dn_ok(dn_save_custom_field($in));
        case 'delete_custom_field': dn_ok(dn_delete_custom_field($in));
        case 'list_users': dn_ok(dn_users());
        case 'save_user_pref': dn_ok(dn_prefs_save(dn_str($in['key'] ?? '', 120), $in['value'] ?? ''));
        case 'get_user_pref': dn_ok(['key' => dn_str($in['key'] ?? '', 120), 'value' => dn_prefs_get(dn_str($in['key'] ?? '', 120))]);
        case 'save_columns': dn_ok(dn_save_table_prefs(['scope' => dn_str($in['scope'] ?? 'desktop', 30), 'columns' => $in['columns'] ?? []]));
        case 'get_columns': dn_ok(dn_get_table_prefs(['scope' => dn_str($in['scope'] ?? 'desktop', 30)]));
        case 'save_permissions':
            if (!dn_can('manage_visibility') && !dn_can('manage_permissions')) dn_fail('没有权限设置权限', 403);
            dn_ok(dn_dispatch_permissions_save($in['permissions'] ?? $in));
        case 'get_permissions':
            if (!dn_can('manage_visibility') && !dn_can('manage_permissions')) dn_fail('没有权限设置权限', 403);
            dn_ok(['permissions' => dn_dispatch_permissions_get()]);
        case 'backup': dn_ok(dn_backup());
        case 'list_backups': dn_ok(dn_list_backups());
        case 'upload_backup': dn_ok(dn_upload_backup($in));
        case 'restore_backup': dn_ok(dn_restore_backup($in));
        case 'get_backup_settings': dn_ok(dn_backup_settings());
        case 'save_backup_settings': dn_ok(dn_save_backup_settings($in));
        case 'preview_link': dn_ok(dn_preview_link($in));
        case 'health_check': dn_ok(['php' => PHP_VERSION, 'time' => date('Y-m-d H:i:s'), 'schema' => dispatch_next_init_schema()]);
        default: dn_fail('未知 action：' . $action, 404);
    }
} catch (Throwable $e) {
    dn_fail($e->getMessage(), 500);
}
