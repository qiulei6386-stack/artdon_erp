<?php

function notification_category_map(): array
{
    return [
        'system_notice' => 'system',
        'mail_new' => 'mail',
        'mail_send_failed' => 'mail',
        'task_due' => 'task',
        'task_overdue' => 'task',
        'task_assigned' => 'task',
        'task_returned' => 'task',
        'task_confirming' => 'task',
        'followup_due' => 'task',
        'followup_overdue' => 'task',
        'dispatch_task_unread' => 'task',
        'dispatch_notice' => 'task',
        'dispatch_due' => 'task',
        'dispatch_overdue' => 'task',
        'sample_pending_ship' => 'sample',
        'sample_shipped' => 'sample',
        'sample_signed' => 'sample',
        'sample_followup_due' => 'sample',
        'sample_tracking_missing' => 'sample',
        'sample_error' => 'sample',
        'opportunity_due' => 'opportunity',
        'opportunity_stage_changed' => 'opportunity',
        'quote_no_reply' => 'quote',
        'quote_confirming' => 'quote',
        'promotion_manual_due' => 'promotion',
        'promotion_group_due' => 'promotion',
        'promotion_failed' => 'promotion',
        'ai_confirm_waiting' => 'ai',
        'ai_error' => 'ai',
        'api_error' => 'error',
        'permission_change' => 'system',
        'visit_followup_reminder' => 'task',
        'website_inquiry_new' => 'customer',
    ];
}

function notification_categories(): array
{
    return [
        'all' => '全部',
        'unread' => '未读',
        'system' => '系统',
        'mail' => '邮件',
        'customer' => '客户',
        'task' => '任务',
        'opportunity' => '商机',
        'quote' => '报价',
        'promotion' => '推广',
        'sample' => '样品',
        'ai' => 'AI',
        'error' => '异常',
    ];
}

function notification_ensure_schema(): void
{
    static $checked = false;
    if ($checked) {
        return;
    }
    $checked = true;
    if (!function_exists('db_table_exists') || !config_installed()) {
        return;
    }
    db()->exec("CREATE TABLE IF NOT EXISTS sys_notifications (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id INT UNSIGNED NULL,
        type VARCHAR(80) NOT NULL DEFAULT 'system',
        title VARCHAR(180) NOT NULL,
        content VARCHAR(800) NULL,
        payload_json JSON NULL,
        read_at DATETIME NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_notification_user (user_id),
        KEY idx_notification_type (type),
        KEY idx_notification_read (read_at),
        KEY idx_notification_created (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    notification_add_column('sys_notifications', 'department_id', 'INT UNSIGNED NULL');
    notification_add_column('sys_notifications', 'role_scope', "VARCHAR(80) NULL");
    notification_add_column('sys_notifications', 'notification_type', "VARCHAR(80) NULL");
    notification_add_column('sys_notifications', 'category', "VARCHAR(40) NOT NULL DEFAULT 'system'");
    notification_add_column('sys_notifications', 'source_module', "VARCHAR(80) NOT NULL DEFAULT ''");
    notification_add_column('sys_notifications', 'source_id', "VARCHAR(120) NOT NULL DEFAULT ''");
    notification_add_column('sys_notifications', 'target_module', "VARCHAR(80) NOT NULL DEFAULT ''");
    notification_add_column('sys_notifications', 'target_id', "VARCHAR(120) NOT NULL DEFAULT ''");
    notification_add_column('sys_notifications', 'target_url', "VARCHAR(500) NOT NULL DEFAULT ''");
    notification_add_column('sys_notifications', 'related_customer_id', 'INT UNSIGNED NULL');
    notification_add_column('sys_notifications', 'related_contact_id', 'INT UNSIGNED NULL');
    notification_add_column('sys_notifications', 'related_opportunity_id', 'INT UNSIGNED NULL');
    notification_add_column('sys_notifications', 'related_task_id', 'BIGINT UNSIGNED NULL');
    notification_add_column('sys_notifications', 'related_quote_id', "VARCHAR(80) NULL");
    notification_add_column('sys_notifications', 'related_mail_id', 'BIGINT UNSIGNED NULL');
    notification_add_column('sys_notifications', 'related_ai_task_id', 'BIGINT UNSIGNED NULL');
    notification_add_column('sys_notifications', 'severity', "VARCHAR(30) NOT NULL DEFAULT 'normal'");
    notification_add_column('sys_notifications', 'is_archived', 'TINYINT(1) NOT NULL DEFAULT 0');
    notification_add_column('sys_notifications', 'dedupe_key', "VARCHAR(190) NULL");
    notification_add_column('sys_notifications', 'created_by', 'INT UNSIGNED NULL');
    notification_add_column('sys_notifications', 'updated_at', 'DATETIME NULL');
    notification_add_column('sys_notifications', 'deleted_at', 'DATETIME NULL');
    notification_add_index('sys_notifications', 'idx_notification_category', 'category');
    notification_add_index('sys_notifications', 'idx_notification_deleted', 'deleted_at');
    notification_add_index('sys_notifications', 'idx_notification_archived', 'is_archived');
    notification_add_index('sys_notifications', 'idx_notification_dedupe', 'dedupe_key');
    db()->exec("UPDATE sys_notifications SET notification_type = type WHERE notification_type IS NULL OR notification_type = ''");
    db()->exec("UPDATE sys_notifications SET category = CASE
        WHEN type LIKE 'mail_%' THEN 'mail'
        WHEN type LIKE 'task_%' OR type LIKE 'visit_%' THEN 'task'
        WHEN type LIKE 'sample_%' THEN 'sample'
        WHEN type LIKE 'quote_%' THEN 'quote'
        WHEN type LIKE 'opportunity_%' THEN 'opportunity'
        WHEN type LIKE 'promotion_%' THEN 'promotion'
        WHEN type LIKE 'ai_%' THEN 'ai'
        WHEN type LIKE '%error%' OR type LIKE '%failed%' THEN 'error'
        ELSE category
    END WHERE category = 'system' OR category = ''");

    db()->exec("CREATE TABLE IF NOT EXISTS sys_notification_settings (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id INT UNSIGNED NOT NULL,
        setting_key VARCHAR(80) NOT NULL,
        setting_value VARCHAR(30) NOT NULL DEFAULT '1',
        updated_at DATETIME NOT NULL,
        UNIQUE KEY uk_notification_setting (user_id, setting_key)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function notification_table_ready(): bool
{
    static $ready = null;
    if ($ready !== null) {
        return $ready;
    }
    $ready = function_exists('db_table_exists') && config_installed() && db_table_exists('sys_notifications');
    return $ready;
}

function notification_add_column(string $table, string $column, string $definition): void
{
    $stmt = db()->prepare('SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?');
    $stmt->execute([$table, $column]);
    if ((int)$stmt->fetchColumn() === 0) {
        db()->exec("ALTER TABLE {$table} ADD COLUMN {$column} {$definition}");
    }
}

function notification_add_index(string $table, string $index, string $columns): void
{
    $stmt = db()->prepare('SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?');
    $stmt->execute([$table, $index]);
    if ((int)$stmt->fetchColumn() === 0) {
        db()->exec("ALTER TABLE {$table} ADD INDEX {$index} ({$columns})");
    }
}

function notification_current_user_id(?int $userId = null): int
{
    if ($userId) return $userId;
    $user = function_exists('current_user') ? current_user() : [];
    return (int)($user['id'] ?? 0);
}

function notification_type_category(string $type, array $payload = []): string
{
    if (!empty($payload['category'])) return preg_replace('/[^a-z_]/', '', (string)$payload['category']) ?: 'system';
    $map = notification_category_map();
    return $map[$type] ?? (str_contains($type, 'mail') ? 'mail' : (str_contains($type, 'error') || str_contains($type, 'failed') ? 'error' : 'system'));
}

function notification_scope_sql(int $userId): array
{
    return ['(user_id = ? OR user_id IS NULL)', [$userId]];
}

function notification_sync_sources(?int $userId = null): void
{
    $uid = notification_current_user_id($userId);
    if ($uid <= 0 || !db_table_exists('sys_notifications')) return;
    try {
        notification_sync_task_sources($uid);
        notification_sync_followup_sources($uid);
        notification_sync_dispatch_sources($uid);
        notification_sync_sample_sources($uid);
    } catch (Throwable $e) {
        error_log('notification sync failed: ' . $e->getMessage());
    }
}

function notification_sync_sources_throttled(?int $userId = null, int $seconds = 120): void
{
    $uid = notification_current_user_id($userId);
    if ($uid <= 0 || !notification_table_ready()) {
        return;
    }
    $dir = __DIR__ . '/../storage';
    if (!is_dir($dir) || !is_writable($dir)) {
        notification_sync_sources($uid);
        return;
    }
    $file = $dir . '/notification_sync_' . $uid . '.stamp';
    $last = is_file($file) ? (int)@filemtime($file) : 0;
    if ($last > 0 && time() - $last < $seconds) {
        return;
    }
    @touch($file);
    notification_sync_sources($uid);
}

function notification_sync_task_sources(int $userId): void
{
    if (!db_table_exists('crm_tasks')) return;
    $stmt = db()->prepare("SELECT id, task_type, title, source_type, source_id, customer_id, contact_id, opportunity_id, quote_id, due_at, status
        FROM crm_tasks
        WHERE deleted_at IS NULL
          AND assigned_user_id = ?
          AND status NOT IN ('done','closed','cancelled')
          AND due_at IS NOT NULL
          AND (due_at < NOW() OR DATE(due_at) = CURDATE())
        ORDER BY due_at ASC
        LIMIT 80");
    $stmt->execute([$userId]);
    foreach ($stmt->fetchAll() as $task) {
        $overdue = strtotime((string)$task['due_at']) < time();
        $type = $overdue ? 'task_overdue' : 'task_due';
        $title = $overdue ? '任务已逾期' : '今日任务提醒';
        $content = ($task['title'] ?: '未命名任务') . ' · 截止 ' . (string)$task['due_at'];
        create_system_notification($userId, $type, $title, $content, [
            'module' => 'tasks',
            'category' => 'task',
            'source_module' => 'tasks',
            'source_id' => (string)$task['id'],
            'target_module' => 'tasks',
            'target_id' => (string)$task['id'],
            'related_task_id' => (int)$task['id'],
            'related_customer_id' => (int)($task['customer_id'] ?? 0) ?: null,
            'related_contact_id' => (int)($task['contact_id'] ?? 0) ?: null,
            'related_opportunity_id' => (int)($task['opportunity_id'] ?? 0) ?: null,
            'related_quote_id' => (string)($task['quote_id'] ?? ''),
            'severity' => $overdue ? 'danger' : 'warning',
            'dedupe_key' => 'task:' . $type . ':' . $userId . ':' . (int)$task['id'],
        ]);
    }
}

function notification_sync_dispatch_sources(int $userId): void
{
    if (db_table_exists('dispatch_next_tasks')) {
        $stmt = db()->prepare("SELECT id, title, project, priority, status, created_by, assigned_to, due_at, is_read, created_at
            FROM dispatch_next_tasks
            WHERE is_deleted = 0
              AND assigned_to = ?
              AND status NOT IN ('done','cancelled')
              AND (is_read = 0 OR due_at < NOW() OR DATE(due_at) = CURDATE())
            ORDER BY is_read ASC, due_at ASC, created_at DESC
            LIMIT 80");
        $stmt->execute([$userId]);
        foreach ($stmt->fetchAll() as $task) {
            $dueAt = trim((string)($task['due_at'] ?? ''));
            $isOverdue = $dueAt !== '' && strtotime($dueAt) && strtotime($dueAt) < time();
            $isDueToday = $dueAt !== '' && date('Y-m-d', strtotime($dueAt)) === date('Y-m-d');
            $type = 'dispatch_task_unread';
            $title = '新派工待办';
            $severity = 'normal';
            if ($isOverdue) {
                $type = 'dispatch_overdue';
                $title = '派工已逾期';
                $severity = 'danger';
            } elseif ($isDueToday) {
                $type = 'dispatch_due';
                $title = '今日派工提醒';
                $severity = 'warning';
            }
            create_system_notification($userId, $type, $title, trim((string)($task['title'] ?? '未命名派工')) . ($dueAt !== '' ? ' · 截止 ' . $dueAt : ''), [
                'module' => 'dispatch',
                'category' => 'task',
                'source_module' => 'dispatch',
                'source_id' => (string)$task['id'],
                'target_module' => 'dispatch',
                'target_id' => (string)$task['id'],
                'target_url' => 'dispatch_next.php',
                'severity' => $severity,
                'dedupe_key' => 'dispatch:task:' . $type . ':' . $userId . ':' . (int)$task['id'],
            ]);
        }
    }
    if (db_table_exists('dispatch_next_notifications')) {
        $stmt = db()->prepare("SELECT id, sender_id, task_id, type, title, message, created_at
            FROM dispatch_next_notifications
            WHERE recipient_id = ? AND is_read = 0
            ORDER BY id DESC
            LIMIT 80");
        $stmt->execute([$userId]);
        foreach ($stmt->fetchAll() as $notice) {
            create_system_notification($userId, 'dispatch_notice', trim((string)($notice['title'] ?? '派工通知')) ?: '派工通知', trim((string)($notice['message'] ?? '')), [
                'module' => 'dispatch',
                'category' => 'task',
                'source_module' => 'dispatch_notification',
                'source_id' => (string)$notice['id'],
                'target_module' => 'dispatch',
                'target_id' => (string)($notice['task_id'] ?? ''),
                'target_url' => 'dispatch_next.php',
                'severity' => 'normal',
                'dedupe_key' => 'dispatch:notice:' . $userId . ':' . (int)$notice['id'],
            ]);
        }
    }
}

function notification_sync_followup_sources(int $userId): void
{
    if (!db_table_exists('crm_customer_followups')) return;
    $stmt = db()->prepare("SELECT f.id, f.customer_id, f.contact_id, f.followup_time, f.followup_type, f.content, f.next_plan, f.next_remind_time, f.status, f.created_by,
            c.customer_name, c.owner_user_id, ct.name AS contact_name
        FROM crm_customer_followups f
        LEFT JOIN crm_customers c ON c.id = f.customer_id
        LEFT JOIN crm_contacts ct ON ct.id = f.contact_id
        WHERE f.deleted_at IS NULL
          AND COALESCE(f.status,'open') NOT IN ('done','completed','closed','cancelled')
          AND COALESCE(c.owner_user_id, f.created_by) = ?
          AND f.next_remind_time IS NOT NULL
          AND (f.next_remind_time < NOW() OR DATE(f.next_remind_time) = CURDATE())
        ORDER BY f.next_remind_time ASC
        LIMIT 100");
    $stmt->execute([$userId]);
    foreach ($stmt->fetchAll() as $followup) {
        notification_create_followup_reminder($followup);
    }
}

function notification_create_followup_reminder(array $followup, int $taskId = 0): bool
{
    $followupId = (int)($followup['id'] ?? 0);
    if ($followupId <= 0) return false;
    $remindAt = trim((string)($followup['next_remind_time'] ?? ''));
    if ($remindAt === '') return false;
    $ts = strtotime($remindAt);
    if (!$ts || $ts > strtotime('today 23:59:59')) return false;
    $userId = (int)($followup['owner_user_id'] ?? 0);
    if ($userId <= 0) $userId = (int)($followup['created_by'] ?? 0);
    if ($userId <= 0) return false;
    if ($taskId <= 0 && db_table_exists('crm_tasks')) {
        $st = db()->prepare("SELECT id FROM crm_tasks WHERE source_type='followup' AND source_id=? AND task_type='customer_followup' AND deleted_at IS NULL LIMIT 1");
        $st->execute([(string)$followupId]);
        $taskId = (int)$st->fetchColumn();
    }
    $overdue = $ts < time();
    $type = $overdue ? 'followup_overdue' : 'followup_due';
    $customerName = trim((string)($followup['customer_name'] ?? '')) ?: '客户';
    $content = trim((string)($followup['content'] ?? ''));
    if (mb_strlen($content, 'UTF-8') > 80) $content = mb_substr($content, 0, 80, 'UTF-8') . '...';
    $title = $overdue ? '客户跟进已逾期' : '今日客户跟进';
    return create_system_notification($userId, $type, $title, $customerName . ' · ' . ($content ?: '跟进提醒') . ' · ' . $remindAt, [
        'module' => 'tasks',
        'category' => 'task',
        'source_module' => 'followup',
        'source_id' => (string)$followupId,
        'target_module' => 'tasks',
        'target_id' => $taskId > 0 ? (string)$taskId : (string)$followupId,
        'related_task_id' => $taskId > 0 ? $taskId : null,
        'related_customer_id' => (int)($followup['customer_id'] ?? 0) ?: null,
        'related_contact_id' => (int)($followup['contact_id'] ?? 0) ?: null,
        'severity' => $overdue ? 'danger' : 'warning',
        'dedupe_key' => 'followup:' . $type . ':' . $userId . ':' . $followupId,
    ]);
}

function notification_create_task_assigned(array $task): bool
{
    $taskId = (int)($task['id'] ?? 0);
    $userId = (int)($task['assigned_user_id'] ?? 0);
    if ($taskId <= 0 || $userId <= 0) return false;
    $title = trim((string)($task['title'] ?? '')) ?: '未命名任务';
    return create_system_notification($userId, 'task_assigned', '新任务分配', $title, [
        'module' => 'tasks',
        'category' => 'task',
        'source_module' => 'tasks',
        'source_id' => (string)$taskId,
        'target_module' => 'tasks',
        'target_id' => (string)$taskId,
        'related_task_id' => $taskId,
        'related_customer_id' => (int)($task['customer_id'] ?? 0) ?: null,
        'related_contact_id' => (int)($task['contact_id'] ?? 0) ?: null,
        'related_opportunity_id' => (int)($task['opportunity_id'] ?? 0) ?: null,
        'related_quote_id' => (string)($task['quote_id'] ?? ''),
        'severity' => 'normal',
        'dedupe_key' => 'task:assigned:' . $userId . ':' . $taskId,
    ]);
}

function notification_sync_sample_sources(int $userId): void
{
    if (!db_table_exists('crm_sample_shipments')) return;
    $stmt = db()->prepare("SELECT s.id, s.task_id, s.customer_id, s.contact_id, s.opportunity_id, s.quote_id, s.sample_name, s.status, s.courier_company, s.tracking_no, s.expected_arrival_date, s.followup_time, c.customer_name
        FROM crm_sample_shipments s
        LEFT JOIN crm_customers c ON c.id = s.customer_id
        WHERE s.deleted_at IS NULL
          AND s.owner_user_id = ?
          AND (
            s.status IN ('preparing','sample_ready','pending_ship')
            OR (s.status IN ('shipped','transporting','signed') AND s.tracking_no = '')
            OR (s.status='signed' AND s.followup_time IS NOT NULL AND s.followup_time < NOW())
          )
        ORDER BY s.updated_at DESC
        LIMIT 80");
    $stmt->execute([$userId]);
    foreach ($stmt->fetchAll() as $sample) {
        $type = 'sample_pending_ship';
        $title = '样品待寄出';
        $severity = 'warning';
        if (in_array((string)$sample['status'], ['shipped','transporting','signed'], true) && trim((string)$sample['tracking_no']) === '') {
            $type = 'sample_tracking_missing';
            $title = '样品快递单号缺失';
            $severity = 'danger';
        } elseif ((string)$sample['status'] === 'signed' && !empty($sample['followup_time']) && strtotime((string)$sample['followup_time']) < time()) {
            $type = 'sample_followup_due';
            $title = '样品签收后未跟进';
            $severity = 'danger';
        }
        $content = ($sample['customer_name'] ?: '客户') . ' · ' . ($sample['sample_name'] ?: '样品寄送');
        create_system_notification($userId, $type, $title, $content, [
            'module' => 'tasks',
            'category' => 'sample',
            'source_module' => 'sample',
            'source_id' => (string)$sample['id'],
            'target_module' => 'sample',
            'target_id' => (string)$sample['id'],
            'related_task_id' => (int)($sample['task_id'] ?? 0) ?: null,
            'related_customer_id' => (int)($sample['customer_id'] ?? 0) ?: null,
            'related_contact_id' => (int)($sample['contact_id'] ?? 0) ?: null,
            'related_opportunity_id' => (int)($sample['opportunity_id'] ?? 0) ?: null,
            'related_quote_id' => (string)($sample['quote_id'] ?? ''),
            'severity' => $severity,
            'dedupe_key' => 'sample:' . $type . ':' . $userId . ':' . (int)$sample['id'],
        ]);
    }
}

function notification_unread_count(?int $userId = null): int
{
    if (!notification_table_ready()) return 0;
    $targetUserId = notification_current_user_id($userId);
    if (!$targetUserId) return 0;
    [$scope, $params] = notification_scope_sql($targetUserId);
    $stmt = db()->prepare("SELECT COUNT(*) FROM sys_notifications WHERE {$scope} AND read_at IS NULL AND is_archived = 0 AND deleted_at IS NULL");
    $stmt->execute($params);
    return (int)$stmt->fetchColumn();
}

function notification_category_counts(int $userId): array
{
    [$scope, $params] = notification_scope_sql($userId);
    $stmt = db()->prepare("SELECT category, COUNT(*) AS total, SUM(CASE WHEN read_at IS NULL THEN 1 ELSE 0 END) AS unread
        FROM sys_notifications
        WHERE {$scope} AND is_archived = 0 AND deleted_at IS NULL
        GROUP BY category");
    $stmt->execute($params);
    $counts = array_fill_keys(array_keys(notification_categories()), 0);
    $total = 0;
    $unread = 0;
    foreach ($stmt->fetchAll() as $row) {
        $cat = (string)($row['category'] ?: 'system');
        $counts[$cat] = (int)$row['total'];
        $total += (int)$row['total'];
        $unread += (int)$row['unread'];
    }
    $counts['all'] = $total;
    $counts['unread'] = $unread;
    return $counts;
}

function notification_list(?int $userId = null, int $limit = 80, string $category = 'all'): array
{
    if (!notification_table_ready()) return [];
    $targetUserId = notification_current_user_id($userId);
    if (!$targetUserId) return [];
    notification_sync_sources_throttled($targetUserId);
    $limit = max(1, min(200, $limit));
    [$scope, $params] = notification_scope_sql($targetUserId);
    $where = [$scope, 'is_archived = 0', 'deleted_at IS NULL'];
    $category = preg_replace('/[^a-z_]/', '', $category) ?: 'all';
    if ($category === 'unread') {
        $where[] = 'read_at IS NULL';
    } elseif ($category !== 'all') {
        $where[] = 'category = ?';
        $params[] = $category;
    }
    $stmt = db()->prepare('SELECT * FROM sys_notifications WHERE ' . implode(' AND ', $where) . " ORDER BY read_at IS NULL DESC, id DESC LIMIT {$limit}");
    $stmt->execute($params);
    return array_map('notification_normalize_row', $stmt->fetchAll());
}

function notification_normalize_row(array $item): array
{
    $payload = json_decode((string)($item['payload_json'] ?? ''), true);
    if (!is_array($payload)) $payload = [];
    $type = (string)($item['notification_type'] ?: ($item['type'] ?? 'system_notice'));
    $category = (string)($item['category'] ?: notification_type_category($type, $payload));
    if ($category === 'mail' && empty($item['related_mail_id'])) {
        $resolvedMailId = notification_resolve_mail_id($item, $payload);
        if ($resolvedMailId > 0) {
            $item['related_mail_id'] = $resolvedMailId;
            if (empty($item['target_module'])) $item['target_module'] = 'mail';
            if (empty($item['target_id'])) $item['target_id'] = (string)$resolvedMailId;
        }
    }
    if ($category === 'mail' && $type === 'mail_new') {
        [$mailTitle, $mailContent, $payload] = notification_mail_display_text($item, $payload);
        if ($mailTitle !== '') $item['title'] = $mailTitle;
        if ($mailContent !== '') $item['content'] = $mailContent;
    }
    return [
        'id' => (int)$item['id'],
        'notification_type' => $type,
        'type' => $type,
        'category' => $category,
        'title' => (string)$item['title'],
        'content' => (string)($item['content'] ?? ''),
        'source_module' => (string)($item['source_module'] ?: ($payload['source_module'] ?? $payload['module'] ?? '')),
        'source_id' => (string)($item['source_id'] ?: ($payload['source_id'] ?? '')),
        'target_module' => (string)($item['target_module'] ?: ($payload['target_module'] ?? $payload['module'] ?? '')),
        'target_id' => (string)($item['target_id'] ?: ($payload['target_id'] ?? '')),
        'target_url' => (string)($item['target_url'] ?: ($payload['target_url'] ?? '')),
        'related_task_id' => (int)($item['related_task_id'] ?? 0),
        'related_mail_id' => (int)($item['related_mail_id'] ?? 0),
        'related_customer_id' => (int)($item['related_customer_id'] ?? 0),
        'related_opportunity_id' => (int)($item['related_opportunity_id'] ?? 0),
        'related_quote_id' => (string)($item['related_quote_id'] ?? ''),
        'severity' => (string)($item['severity'] ?: 'normal'),
        'read_at' => $item['read_at'] ?? null,
        'created_at' => (string)($item['created_at'] ?? ''),
        'payload' => $payload,
    ];
}

function notification_resolve_mail_id(array $item, array $payload): int
{
    $direct = (int)($payload['related_mail_id'] ?? $payload['mail_id'] ?? 0);
    if ($direct > 0) return $direct;
    $ids = $payload['mail_ids'] ?? [];
    if (is_string($ids)) $ids = json_decode($ids, true) ?: [];
    if (is_array($ids)) {
        foreach ($ids as $id) {
            if ((int)$id > 0) return (int)$id;
        }
    }
    if (!db_table_exists('crm_mails')) return 0;
    $userId = (int)($item['user_id'] ?? 0);
    $accountId = (int)($payload['account_id'] ?? 0);
    $createdAt = (string)($item['created_at'] ?? '');
    if ($userId <= 0 || $accountId <= 0 || $createdAt === '') return 0;
    $stmt = db()->prepare("SELECT id FROM crm_mails
        WHERE user_id = ?
          AND mail_account_id = ?
          AND created_at BETWEEN DATE_SUB(?, INTERVAL 15 MINUTE) AND DATE_ADD(?, INTERVAL 5 MINUTE)
        ORDER BY ABS(TIMESTAMPDIFF(SECOND, created_at, ?)) ASC, id DESC
        LIMIT 1");
    $stmt->execute([$userId, $accountId, $createdAt, $createdAt, $createdAt]);
    return (int)$stmt->fetchColumn();
}

function notification_mail_display_text(array $item, array $payload): array
{
    $subjects = [];
    foreach (($payload['mail_subjects'] ?? []) as $subject) {
        $subject = notification_text_snippet((string)$subject, 80);
        if ($subject !== '') $subjects[] = $subject;
    }
    if (!$subjects && !empty($payload['subject_preview'])) {
        $subject = notification_text_snippet((string)$payload['subject_preview'], 80);
        if ($subject !== '') $subjects[] = $subject;
    }
    if (!$subjects) {
        $ids = $payload['mail_ids'] ?? [];
        if (is_string($ids)) $ids = json_decode($ids, true) ?: preg_split('/\s*,\s*/', $ids);
        if (!is_array($ids)) $ids = [];
        $relatedMailId = (int)($item['related_mail_id'] ?? 0);
        if ($relatedMailId > 0) array_unshift($ids, $relatedMailId);
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        $subjects = notification_mail_subjects_by_ids($ids);
    }
    if (!$subjects) return ['', '', $payload];
    $newCount = max(1, (int)($payload['new_count'] ?? count($subjects)));
    $email = (string)($payload['email_address'] ?? $payload['mailbox_account'] ?? '');
    $title = '收件箱 · ' . ($subjects[0] ?: '无主题邮件');
    $content = '共 ' . $newCount . ' 封新邮件：' . implode('；', array_slice($subjects, 0, 3));
    if ($newCount > 3) $content .= ' 等';
    if ($email !== '') $content .= '。邮箱：' . $email;
    $payload['subject_preview'] = $subjects[0];
    $payload['mail_subjects'] = array_slice($subjects, 0, 5);
    return [$title, $content, $payload];
}

function notification_mail_subjects_by_ids(array $mailIds): array
{
    $mailIds = array_values(array_unique(array_filter(array_map('intval', $mailIds))));
    if (!$mailIds || !db_table_exists('crm_mails')) return [];
    $placeholders = implode(',', array_fill(0, count($mailIds), '?'));
    $stmt = db()->prepare("SELECT id, subject FROM crm_mails WHERE id IN ({$placeholders}) ORDER BY FIELD(id, {$placeholders})");
    $params = array_merge($mailIds, $mailIds);
    $stmt->execute($params);
    $subjects = [];
    foreach ($stmt->fetchAll() as $row) {
        $subject = notification_text_snippet((string)($row['subject'] ?? ''), 80);
        if ($subject !== '') $subjects[] = $subject;
    }
    return $subjects;
}

function notification_text_snippet(string $text, int $limit = 80): string
{
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = trim(preg_replace('/\s+/u', ' ', $text) ?: '');
    if ($text === '') return '';
    if (function_exists('mb_strlen') && function_exists('mb_substr')) {
        return mb_strlen($text, 'UTF-8') > $limit ? mb_substr($text, 0, $limit, 'UTF-8') . '...' : $text;
    }
    return strlen($text) > $limit ? substr($text, 0, $limit) . '...' : $text;
}

function notification_summary(?int $userId = null, int $limit = 8, string $category = 'all'): array
{
    $uid = notification_current_user_id($userId);
    $items = notification_list($uid, $limit, $category);
    return [
        'items' => $items,
        'unread_count' => notification_unread_count($uid),
        'category_counts' => notification_category_counts($uid),
        'categories' => notification_categories(),
    ];
}

function create_system_notification(int $userId, string $type, string $title, string $content = '', array $payload = []): bool
{
    notification_ensure_schema();
    if (!db_table_exists('sys_notifications')) return false;
    if ($userId <= 0 && empty($payload['broadcast'])) return false;
    $category = notification_type_category($type, $payload);
    if ($userId > 0) {
        $settings = notification_settings($userId);
        if (array_key_exists($category, $settings) && (string)$settings[$category] === '0') {
            return false;
        }
    }
    $dedupeKey = trim((string)($payload['dedupe_key'] ?? ''));
    if ($dedupeKey === '' && $type === 'mail_new') {
        $mailHash = (string)($payload['mail_ids_hash'] ?? '');
        $dedupeKey = implode(':', ['mail_new', $userId, (string)($payload['mailbox_account'] ?? $payload['email_address'] ?? ''), (string)($payload['sync_batch_id'] ?? $payload['sync_id'] ?? ''), $mailHash]);
    }
    $dedupeKey = $dedupeKey !== '' ? substr($dedupeKey, 0, 190) : null;
    $targetModule = (string)($payload['target_module'] ?? $payload['module'] ?? '');
    $sourceModule = (string)($payload['source_module'] ?? $payload['module'] ?? '');
    $sourceId = (string)($payload['source_id'] ?? '');
    $targetId = (string)($payload['target_id'] ?? ($payload['mail_id'] ?? ($payload['related_mail_id'] ?? '')));
    $row = [
        'user_id' => $userId ?: null,
        'type' => $type,
        'notification_type' => $type,
        'category' => $category,
        'title' => $title,
        'content' => $content,
        'payload_json' => json_encode($payload, JSON_UNESCAPED_UNICODE),
        'source_module' => $sourceModule,
        'source_id' => $sourceId,
        'target_module' => $targetModule,
        'target_id' => $targetId,
        'target_url' => (string)($payload['target_url'] ?? ''),
        'related_customer_id' => (int)($payload['related_customer_id'] ?? 0) ?: null,
        'related_contact_id' => (int)($payload['related_contact_id'] ?? 0) ?: null,
        'related_opportunity_id' => (int)($payload['related_opportunity_id'] ?? 0) ?: null,
        'related_task_id' => (int)($payload['related_task_id'] ?? 0) ?: null,
        'related_quote_id' => (string)($payload['related_quote_id'] ?? '') ?: null,
        'related_mail_id' => (int)($payload['related_mail_id'] ?? $payload['mail_id'] ?? 0) ?: null,
        'related_ai_task_id' => (int)($payload['related_ai_task_id'] ?? 0) ?: null,
        'severity' => (string)($payload['severity'] ?? 'normal'),
        'dedupe_key' => $dedupeKey,
        'created_by' => (int)($payload['created_by'] ?? 0) ?: null,
    ];
    if ($dedupeKey !== null) {
        $existing = db()->prepare('SELECT id, read_at FROM sys_notifications WHERE dedupe_key = ? AND user_id <=> ? AND deleted_at IS NULL LIMIT 1');
        $existing->execute([$dedupeKey, $row['user_id']]);
        $found = $existing->fetch();
        if ($found) {
            db()->prepare('UPDATE sys_notifications SET type=?, notification_type=?, category=?, title=?, content=?, payload_json=?, source_module=?, source_id=?, target_module=?, target_id=?, target_url=?, related_customer_id=?, related_contact_id=?, related_opportunity_id=?, related_task_id=?, related_quote_id=?, related_mail_id=?, related_ai_task_id=?, severity=?, is_archived=0, updated_at=NOW() WHERE id=?')
                ->execute([$row['type'], $row['notification_type'], $row['category'], $row['title'], $row['content'], $row['payload_json'], $row['source_module'], $row['source_id'], $row['target_module'], $row['target_id'], $row['target_url'], $row['related_customer_id'], $row['related_contact_id'], $row['related_opportunity_id'], $row['related_task_id'], $row['related_quote_id'], $row['related_mail_id'], $row['related_ai_task_id'], $row['severity'], (int)$found['id']]);
            notification_log('notification_update', (int)$found['id'], null, ['type' => $type, 'dedupe_key' => $dedupeKey]);
            return true;
        }
    }
    db()->prepare('INSERT INTO sys_notifications (user_id,type,notification_type,category,title,content,payload_json,source_module,source_id,target_module,target_id,target_url,related_customer_id,related_contact_id,related_opportunity_id,related_task_id,related_quote_id,related_mail_id,related_ai_task_id,severity,dedupe_key,created_by,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW(),NOW())')
        ->execute([$row['user_id'], $row['type'], $row['notification_type'], $row['category'], $row['title'], $row['content'], $row['payload_json'], $row['source_module'], $row['source_id'], $row['target_module'], $row['target_id'], $row['target_url'], $row['related_customer_id'], $row['related_contact_id'], $row['related_opportunity_id'], $row['related_task_id'], $row['related_quote_id'], $row['related_mail_id'], $row['related_ai_task_id'], $row['severity'], $row['dedupe_key'], $row['created_by']]);
    notification_log('notification_create', (int)db()->lastInsertId(), null, ['type' => $type, 'category' => $category]);
    return true;
}

function notification_mark_read($ids, ?int $userId = null): int
{
    notification_ensure_schema();
    $uid = notification_current_user_id($userId);
    $ids = array_values(array_filter(array_map('intval', is_array($ids) ? $ids : [$ids])));
    if (!$uid || !$ids) return 0;
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $params = array_merge($ids, [$uid]);
    $before = notification_rows_for_source_sync($ids, $uid);
    $stmt = db()->prepare("UPDATE sys_notifications SET read_at = COALESCE(read_at, NOW()), updated_at = NOW() WHERE id IN ({$placeholders}) AND (user_id = ? OR user_id IS NULL) AND deleted_at IS NULL");
    $stmt->execute($params);
    $count = $stmt->rowCount();
    notification_mark_source_rows_read($before, $uid);
    notification_log('notification_mark_read', 0, null, ['ids' => $ids, 'affected' => $count]);
    return $count;
}

function notification_mark_all_read(?int $userId = null, string $category = 'all'): int
{
    notification_ensure_schema();
    $uid = notification_current_user_id($userId);
    if (!$uid) return 0;
    [$scope, $params] = notification_scope_sql($uid);
    $where = [$scope, 'read_at IS NULL', 'deleted_at IS NULL'];
    $category = preg_replace('/[^a-z_]/', '', $category) ?: 'all';
    if ($category !== 'all' && $category !== 'unread') {
        $where[] = 'category = ?';
        $params[] = $category;
    }
    $select = db()->prepare('SELECT id, source_module, source_id, target_module, target_id FROM sys_notifications WHERE ' . implode(' AND ', $where));
    $select->execute($params);
    $before = $select->fetchAll();
    $stmt = db()->prepare('UPDATE sys_notifications SET read_at = NOW(), updated_at = NOW() WHERE ' . implode(' AND ', $where));
    $stmt->execute($params);
    $count = $stmt->rowCount();
    notification_mark_source_rows_read($before, $uid);
    notification_log('notification_mark_all_read', 0, null, ['category' => $category, 'affected' => $count]);
    return $count;
}

function notification_rows_for_source_sync(array $ids, int $userId): array
{
    if (!$ids || !db_table_exists('sys_notifications')) return [];
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = db()->prepare("SELECT id, source_module, source_id, target_module, target_id FROM sys_notifications WHERE id IN ({$placeholders}) AND (user_id = ? OR user_id IS NULL)");
    $stmt->execute(array_merge($ids, [$userId]));
    return $stmt->fetchAll();
}

function notification_mark_source_rows_read(array $rows, int $userId): void
{
    foreach ($rows as $row) {
        $source = (string)($row['source_module'] ?? '');
        $sourceId = (int)($row['source_id'] ?? 0);
        $target = (string)($row['target_module'] ?? '');
        $targetId = (int)($row['target_id'] ?? 0);
        try {
            if ($source === 'dispatch_notification' && $sourceId > 0 && db_table_exists('dispatch_next_notifications')) {
                db()->prepare('UPDATE dispatch_next_notifications SET is_read=1, read_at=COALESCE(read_at,NOW()) WHERE id=? AND recipient_id=?')->execute([$sourceId, $userId]);
            } elseif (($source === 'dispatch' || $target === 'dispatch') && ($sourceId > 0 || $targetId > 0) && db_table_exists('dispatch_next_tasks')) {
                db()->prepare('UPDATE dispatch_next_tasks SET is_read=1, read_at=COALESCE(read_at,NOW()) WHERE id=? AND assigned_to=?')->execute([$targetId ?: $sourceId, $userId]);
            }
        } catch (Throwable $e) {
            error_log('notification source read sync failed: ' . $e->getMessage());
        }
    }
}

function notification_archive($ids, ?int $userId = null): int
{
    $uid = notification_current_user_id($userId);
    $ids = array_values(array_filter(array_map('intval', is_array($ids) ? $ids : [$ids])));
    if (!$uid || !$ids) return 0;
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = db()->prepare("UPDATE sys_notifications SET is_archived = 1, updated_at = NOW() WHERE id IN ({$placeholders}) AND (user_id = ? OR user_id IS NULL)");
    $stmt->execute(array_merge($ids, [$uid]));
    $count = $stmt->rowCount();
    notification_log('notification_archive', 0, null, ['ids' => $ids, 'affected' => $count]);
    return $count;
}

function notification_clear_read(?int $userId = null): int
{
    $uid = notification_current_user_id($userId);
    if (!$uid) return 0;
    $stmt = db()->prepare('UPDATE sys_notifications SET deleted_at = NOW(), updated_at = NOW() WHERE (user_id = ? OR user_id IS NULL) AND read_at IS NOT NULL AND deleted_at IS NULL');
    $stmt->execute([$uid]);
    $count = $stmt->rowCount();
    notification_log('notification_clear_read', 0, null, ['affected' => $count]);
    return $count;
}

function notification_settings(?int $userId = null): array
{
    notification_ensure_schema();
    $uid = notification_current_user_id($userId);
    $defaults = [
        'mail' => '1',
        'task' => '1',
        'sample' => '1',
        'opportunity' => '1',
        'quote' => '1',
        'promotion' => '1',
        'ai' => '1',
        'system' => '1',
        'error' => '1',
    ];
    if (!$uid || !db_table_exists('sys_notification_settings')) return $defaults;
    $stmt = db()->prepare('SELECT setting_key, setting_value FROM sys_notification_settings WHERE user_id = ?');
    $stmt->execute([$uid]);
    foreach ($stmt->fetchAll() as $row) {
        $defaults[(string)$row['setting_key']] = (string)$row['setting_value'];
    }
    return $defaults;
}

function notification_save_settings(array $input, ?int $userId = null): array
{
    notification_ensure_schema();
    $uid = notification_current_user_id($userId);
    if (!$uid) return [];
    $keys = ['mail','task','sample','opportunity','quote','promotion','ai','system','error'];
    $stmt = db()->prepare('INSERT INTO sys_notification_settings (user_id, setting_key, setting_value, updated_at) VALUES (?, ?, ?, NOW()) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()');
    foreach ($keys as $key) {
        if (array_key_exists($key, $input)) {
            $stmt->execute([$uid, $key, empty($input[$key]) || $input[$key] === '0' ? '0' : '1']);
        }
    }
    notification_log('notification_settings_save', 0, null, notification_settings($uid));
    return notification_settings($uid);
}

function notification_log(string $action, int $notificationId = 0, $before = null, $after = null, bool $success = true, string $failure = ''): void
{
    if (function_exists('crm_log_event')) {
        crm_log_event('notifications', $action, 'notification', $notificationId ? (string)$notificationId : '', $before, $after, $success, $failure);
    }
}
