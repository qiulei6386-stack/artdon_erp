<?php
function backup_storage_dir(): string
{
    $dir = __DIR__ . '/../storage/backups';
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }
    return $dir;
}

function backup_version(string $type): string
{
    return strtoupper($type) . '-' . date('Ymd-His') . '-' . bin2hex(random_bytes(3));
}

function backup_table_exists(string $table): bool
{
    return db_table_exists($table);
}

function backup_tables_for(string $type, string $scope = 'all'): array
{
    $template = ['crm_roles', 'crm_departments', 'crm_permissions', 'crm_role_permissions', 'crm_user_scopes', 'crm_field_permissions', 'crm_system_settings'];
    $data = ['crm_users', 'crm_login_logs', 'crm_audit_logs', 'crm_permission_requests', 'crm_permission_grants', 'crm_permission_request_logs'];
    $full = array_merge($template, $data, ['crm_user_permissions', 'crm_user_approval_logs', 'crm_schema_migrations', 'sys_backup_schedule']);
    $map = ['template' => $template, 'data' => $data, 'full' => $full];
    $tables = $map[$type] ?? $data;
    if ($scope !== 'all') {
        $tables = array_values(array_filter($tables, fn($table) => strpos($table, $scope) !== false));
    }
    return array_values(array_filter($tables, 'backup_table_exists'));
}

function backup_schema_for_table(string $table): string
{
    $stmt = db()->query('SHOW CREATE TABLE `' . str_replace('`', '``', $table) . '`');
    $row = $stmt->fetch(PDO::FETCH_NUM);
    return $row[1] ?? '';
}

function backup_rows_for_table(string $table): array
{
    return db()->query('SELECT * FROM `' . str_replace('`', '``', $table) . '`')->fetchAll();
}

function create_system_backup(string $type, string $scope = 'all', string $remark = ''): int
{
    if (!in_array($type, ['template', 'data', 'full'], true)) {
        throw new RuntimeException('备份类型无效。');
    }
    if (!db_table_exists('sys_backup_records')) {
        throw new RuntimeException('备份表未初始化，请先执行 sql/003_system_foundation.sql。');
    }
    $version = backup_version($type);
    $tables = backup_tables_for($type, $scope);
    $payload = [
        'version' => $version,
        'type' => $type,
        'scope' => $scope,
        'created_at' => date('Y-m-d H:i:s'),
        'operator' => current_user()['username'] ?? 'system',
        'tables' => [],
        'config' => $type === 'full' ? db_config() : null,
    ];
    foreach ($tables as $table) {
        $payload['tables'][$table] = [
            'schema' => backup_schema_for_table($table),
            'rows' => backup_rows_for_table($table),
        ];
    }
    $file = backup_storage_dir() . '/' . $version . '.json';
    file_put_contents($file, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX);
    $size = filesize($file) ?: 0;
    $user = current_user();
    $stmt = db()->prepare("INSERT INTO sys_backup_records (backup_type, version_no, title, module_scope, status, file_path, file_size, remark, operator_id, operator_name, created_at) VALUES (?, ?, ?, ?, 'created', ?, ?, ?, ?, ?, NOW())");
    $stmt->execute([$type, $version, strtoupper($type) . ' backup ' . date('Y-m-d H:i'), $scope, $file, $size, $remark, $user['id'] ?? null, $user['username'] ?? 'system']);
    $backupId = (int)db()->lastInsertId();
    db()->prepare("INSERT INTO sys_backup_files (backup_id, file_role, file_path, file_size, checksum, created_at) VALUES (?, 'manifest', ?, ?, ?, NOW())")
        ->execute([$backupId, $file, $size, hash_file('sha256', $file)]);
    if (function_exists('sys_action_log')) {
        sys_action_log('backup', 'create_' . $type . '_backup', 'backup', $backupId, null, ['version' => $version, 'scope' => $scope], $remark, 'sensitive');
    }
    return $backupId;
}

function list_system_backups(string $type = ''): array
{
    if (!db_table_exists('sys_backup_records')) return [];
    if ($type !== '') {
        $stmt = db()->prepare('SELECT * FROM sys_backup_records WHERE backup_type = ? ORDER BY id DESC LIMIT 100');
        $stmt->execute([$type]);
        return $stmt->fetchAll();
    }
    return db()->query('SELECT * FROM sys_backup_records ORDER BY id DESC LIMIT 100')->fetchAll();
}

function restore_system_backup(int $backupId, string $confirm): void
{
    if ($confirm !== 'RESTORE') {
        throw new RuntimeException('恢复前必须输入 RESTORE 二次确认。');
    }
    $stmt = db()->prepare('SELECT * FROM sys_backup_records WHERE id = ? LIMIT 1');
    $stmt->execute([$backupId]);
    $record = $stmt->fetch();
    if (!$record || !is_file($record['file_path'])) {
        throw new RuntimeException('备份文件不存在。');
    }
    $payload = json_decode((string)file_get_contents($record['file_path']), true);
    if (!$payload || empty($payload['tables'])) {
        throw new RuntimeException('备份文件格式无效。');
    }
    db()->beginTransaction();
    try {
        foreach ($payload['tables'] as $table => $data) {
            if (!backup_table_exists($table)) {
                continue;
            }
            db()->exec('DELETE FROM `' . str_replace('`', '``', $table) . '`');
            foreach ($data['rows'] as $row) {
                $cols = array_keys($row);
                $quoted = array_map(fn($col) => '`' . str_replace('`', '``', $col) . '`', $cols);
                $placeholders = implode(',', array_fill(0, count($cols), '?'));
                $sql = 'INSERT INTO `' . str_replace('`', '``', $table) . '` (' . implode(',', $quoted) . ') VALUES (' . $placeholders . ')';
                db()->prepare($sql)->execute(array_values($row));
            }
        }
        db()->prepare("UPDATE sys_backup_records SET status = 'restored', restored_at = NOW() WHERE id = ?")->execute([$backupId]);
        db()->commit();
    } catch (Throwable $e) {
        db()->rollBack();
        throw $e;
    }
    if (function_exists('sys_action_log')) {
        sys_action_log('backup', 'restore_backup', 'backup', $backupId, $record, ['version' => $record['version_no']], '恢复备份', 'danger');
    }
}

function run_due_backup_schedules(): int
{
    if (!db_table_exists('sys_backup_schedule')) return 0;
    $rows = db()->query("SELECT * FROM sys_backup_schedule WHERE enabled = 1 AND (next_run_at IS NULL OR next_run_at <= NOW()) ORDER BY id")->fetchAll();
    $count = 0;
    foreach ($rows as $row) {
        create_system_backup($row['backup_type'], $row['module_scope'], '自动备份：' . $row['schedule_type']);
        $next = '+1 day';
        if ($row['schedule_type'] === 'weekly') $next = '+1 week';
        if ($row['schedule_type'] === 'monthly') $next = '+1 month';
        db()->prepare('UPDATE sys_backup_schedule SET last_run_at = NOW(), next_run_at = ?, updated_at = NOW() WHERE id = ?')
            ->execute([date('Y-m-d ' . $row['run_time'], strtotime($next)), $row['id']]);
        $count++;
    }
    return $count;
}
