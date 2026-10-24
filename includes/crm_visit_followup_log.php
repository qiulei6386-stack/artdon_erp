<?php
/**
 * Child follow-up timeline entries under a parent visit/arrival (miniapp + web).
 * Distinct from visit_category=跟进记录 parallel tab records.
 */

function crm_visit_followup_log_table(): string
{
    return 'visit_followup_logs';
}

function crm_visit_followup_log_ensure(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    $t = crm_visit_followup_log_table();
    db()->exec("CREATE TABLE IF NOT EXISTS {$t} (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        parent_visit_id INT UNSIGNED NOT NULL,
        follow_date DATE NOT NULL,
        content TEXT NOT NULL,
        created_by INT UNSIGNED NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        deleted_at DATETIME NULL,
        KEY idx_parent_follow_date (parent_visit_id, follow_date, id),
        KEY idx_created_by (created_by)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    if (function_exists('crm_visit_add_column')) {
        crm_visit_add_column('crm_visit_files', 'followup_log_id', 'followup_log_id INT UNSIGNED NULL AFTER visit_id');
    }
}

/** @param array<string,mixed> $row */
function crm_visit_supports_child_followup_logs(array $row): bool
{
    if (function_exists('crm_visit_is_followup_record') && crm_visit_is_followup_record($row)) {
        return false;
    }
    $type = trim((string)($row['visit_type'] ?? ''));
    return in_array($type, ['customer_visit', 'customer_arrival'], true);
}

function crm_visit_followup_log_row(int $id): ?array
{
    crm_visit_followup_log_ensure();
    if ($id <= 0) {
        return null;
    }
    $t = crm_visit_followup_log_table();
    $st = db()->prepare("SELECT l.* FROM {$t} l
        INNER JOIN crm_visit_records v ON v.id = l.parent_visit_id AND v.deleted_at IS NULL
        WHERE l.id = ? AND l.deleted_at IS NULL AND " . crm_visit_scope_sql('v') . " LIMIT 1");
    $st->execute([$id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/** @return array<int,array<string,mixed>> */
function crm_visit_followup_logs_for_parent(int $parentVisitId): array
{
    crm_visit_followup_log_ensure();
    if ($parentVisitId <= 0) {
        return [];
    }
    $parent = crm_visit_row($parentVisitId);
    if (!crm_visit_supports_child_followup_logs($parent)) {
        return [];
    }
    $t = crm_visit_followup_log_table();
    $st = db()->prepare("SELECT l.*, COALESCE(NULLIF(u.real_name,''), u.username, '') AS created_by_name
        FROM {$t} l
        LEFT JOIN crm_users u ON u.id = l.created_by
        WHERE l.parent_visit_id = ? AND l.deleted_at IS NULL
        ORDER BY l.follow_date DESC, l.id DESC");
    $st->execute([$parentVisitId]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $out = [];
    foreach ($rows as $row) {
        $out[] = crm_visit_followup_log_public_row($row, $parentVisitId);
    }
    return $out;
}

/** @param array<string,mixed> $row */
function crm_visit_followup_log_public_row(array $row, int $parentVisitId): array
{
    $id = (int)($row['id'] ?? 0);
    $followDate = (string)($row['follow_date'] ?? '');
    return [
        'id' => $id,
        'followup_id' => $id,
        'parent_visit_id' => (int)($row['parent_visit_id'] ?? $parentVisitId),
        'follow_date' => $followDate,
        'content' => (string)($row['content'] ?? ''),
        'created_by' => (int)($row['created_by'] ?? 0),
        'created_by_name' => (string)($row['created_by_name'] ?? ''),
        'created_at' => (string)($row['created_at'] ?? ''),
        'updated_at' => (string)($row['updated_at'] ?? ''),
        'attachments' => crm_visit_followup_log_attachments($parentVisitId, $id),
    ];
}

/** @return array<int,array<string,mixed>> */
function crm_visit_followup_log_attachments(int $parentVisitId, int $followupLogId): array
{
    crm_visit_followup_log_ensure();
    if ($parentVisitId <= 0 || $followupLogId <= 0) {
        return [];
    }
    $hasCol = function_exists('crm_visit_column_exists') && crm_visit_column_exists('crm_visit_files', 'followup_log_id');
    if (!$hasCol) {
        return [];
    }
    $st = db()->prepare("SELECT f.* FROM crm_visit_files f
        WHERE f.visit_id = ? AND f.followup_log_id = ? AND f.deleted_at IS NULL
        ORDER BY f.id ASC");
    $st->execute([$parentVisitId, $followupLogId]);
    $files = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $f) {
        $files[] = crm_visit_file_public_row($f);
    }
    return $files;
}

/** @param array<string,mixed> $input */
function crm_visit_followup_log_save(array $input, int $userId): array
{
    crm_visit_followup_log_ensure();
    $parentId = (int)($input['parent_visit_id'] ?? $input['visit_id'] ?? 0);
    if ($parentId <= 0) {
        throw new RuntimeException('缺少拜访单 visit_id');
    }
    $parent = crm_visit_row($parentId);
    if (!crm_visit_supports_child_followup_logs($parent)) {
        throw new RuntimeException('仅拜访或来访详情可添加跟进记录');
    }
    $followDate = trim((string)($input['follow_date'] ?? ''));
    if ($followDate === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $followDate)) {
        throw new RuntimeException('请填写有效跟进日期 follow_date（YYYY-MM-DD）');
    }
    $content = trim((string)($input['content'] ?? ''));
    if ($content === '') {
        throw new RuntimeException('请填写跟进内容 content');
    }
    $id = (int)($input['id'] ?? $input['followup_id'] ?? 0);
    $t = crm_visit_followup_log_table();
    if ($id > 0) {
        $existing = crm_visit_followup_log_row($id);
        if (!$existing || (int)$existing['parent_visit_id'] !== $parentId) {
            throw new RuntimeException('跟进记录不存在');
        }
        db()->prepare("UPDATE {$t} SET follow_date = ?, content = ?, updated_at = NOW() WHERE id = ? AND parent_visit_id = ? AND deleted_at IS NULL")
            ->execute([$followDate, $content, $id, $parentId]);
    } else {
        db()->prepare("INSERT INTO {$t} (parent_visit_id, follow_date, content, created_by) VALUES (?,?,?,?)")
            ->execute([$parentId, $followDate, $content, max(0, $userId)]);
        $id = (int)db()->lastInsertId();
    }
    $st = db()->prepare("SELECT l.*, COALESCE(NULLIF(u.real_name,''), u.username, '') AS created_by_name
        FROM {$t} l LEFT JOIN crm_users u ON u.id = l.created_by WHERE l.id = ? LIMIT 1");
    $st->execute([$id]);
    $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];
    return crm_visit_followup_log_public_row($row, $parentId);
}

function crm_visit_followup_log_delete(int $id, int $parentVisitId = 0): void
{
    crm_visit_followup_log_ensure();
    $row = crm_visit_followup_log_row($id);
    if (!$row) {
        throw new RuntimeException('跟进记录不存在');
    }
    if ($parentVisitId > 0 && (int)$row['parent_visit_id'] !== $parentVisitId) {
        throw new RuntimeException('跟进记录不存在');
    }
    $t = crm_visit_followup_log_table();
    db()->prepare("UPDATE {$t} SET deleted_at = NOW() WHERE id = ?")->execute([$id]);
    if (function_exists('crm_visit_column_exists') && crm_visit_column_exists('crm_visit_files', 'followup_log_id')) {
        db()->prepare('UPDATE crm_visit_files SET deleted_at = NOW() WHERE followup_log_id = ? AND deleted_at IS NULL')
            ->execute([$id]);
    }
}

function crm_visit_followup_log_upload_file(int $parentVisitId, int $followupLogId, array $file): array
{
    crm_visit_followup_log_ensure();
    $row = crm_visit_followup_log_row($followupLogId);
    if (!$row || (int)$row['parent_visit_id'] !== $parentVisitId) {
        throw new RuntimeException('跟进记录不存在');
    }
    crm_visit_row($parentVisitId);
    if (!function_exists('crm_visit_column_exists') || !crm_visit_column_exists('crm_visit_files', 'followup_log_id')) {
        throw new RuntimeException('附件列未就绪，请执行 php miniapp/migrate.php');
    }
    $mime = strtolower((string)($file['type'] ?? ''));
    $kind = strpos($mime, 'image/') === 0 ? 'image' : 'attachment';
    $fileRole = 'visit_followup';
    $photoCategory = 'visit_followup';
    $r = crm_visit_upload_files($parentVisitId, $kind, $file, $fileRole);
    foreach ((array)($r['uploaded'] ?? []) as $f) {
        if (empty($f['id'])) {
            continue;
        }
        $fid = (int)$f['id'];
        db()->prepare('UPDATE crm_visit_files SET followup_log_id = ?, photo_category = ?, file_role = ? WHERE id = ?')
            ->execute([$followupLogId, $photoCategory, $fileRole, $fid]);
        $f = crm_visit_file_public_row(crm_visit_file_row($fid));
        return ['uploaded' => [$f], 'file' => $f];
    }
    throw new RuntimeException('上传失败');
}

/** Strip child follow-up attachments from parent visit file lists. */
function crm_visit_files_exclude_followup_logs(array $files): array
{
    if (!function_exists('crm_visit_column_exists') || !crm_visit_column_exists('crm_visit_files', 'followup_log_id')) {
        return $files;
    }
    $out = [];
    foreach ($files as $f) {
        if ((int)($f['followup_log_id'] ?? 0) > 0) {
            continue;
        }
        $role = trim((string)($f['file_role'] ?? ''));
        $cat = trim((string)($f['photo_category'] ?? ''));
        if ($role === 'visit_followup' || $cat === 'visit_followup') {
            continue;
        }
        $out[] = $f;
    }
    return $out;
}
