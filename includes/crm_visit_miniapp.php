<?php
/**
 * Mini-program visit extensions: extra visit_type values and shared labels.
 * Loaded from crm_visit.php and miniapp/api/index.php.
 */

function crm_visit_type_codes(): array
{
    return ['customer_visit', 'customer_arrival', 'video_meeting', 'daily_followup'];
}

function crm_visit_normalize_type(string $type): string
{
    $type = trim($type);
    return in_array($type, crm_visit_type_codes(), true) ? $type : 'customer_visit';
}

function crm_visit_is_extended_followup_type(string $type): bool
{
    return in_array(trim($type), ['video_meeting', 'daily_followup'], true);
}

/** Client photo_category → stored file_role (names match client for communication/background_check). */
function crm_visit_photo_category_to_file_role(string $photoCategory): string
{
    $photoCategory = trim($photoCategory);
    static $map = [
        'communication' => 'communication',
        'progress' => 'progress',
        'background_check' => 'background_check',
        'visit_followup' => 'visit_followup',
    ];
    return $map[$photoCategory] ?? '';
}

function crm_visit_file_role_to_photo_category(string $fileRole): string
{
    $fileRole = trim($fileRole);
    if ($fileRole === 'followup_progress') {
        return 'progress';
    }
    if ($fileRole === 'visit_followup') {
        return 'visit_followup';
    }
    if (in_array($fileRole, ['communication', 'progress', 'background_check'], true)) {
        return $fileRole;
    }
    return '';
}

/** @param array<string,mixed> $row */
function crm_visit_type_label_for_row(array $row): string
{
    if (function_exists('crm_visit_is_followup_record') && crm_visit_is_followup_record($row)) {
        return '跟进';
    }
    $type = (string)($row['visit_type'] ?? '');
    static $map = [
        'customer_visit' => '拜访',
        'customer_arrival' => '来访',
        'video_meeting' => '视频会议',
        'daily_followup' => '平时跟进',
    ];
    return $map[$type] ?? '拜访';
}

function crm_visit_miniapp_meta_ensure(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    if (!function_exists('crm_visit_add_column')) {
        return;
    }
    crm_visit_add_column('crm_visit_records', 'background_check_note', 'background_check_note TEXT NULL AFTER preparation_note');
    crm_visit_add_column('crm_visit_files', 'file_role', "file_role VARCHAR(40) NOT NULL DEFAULT '' AFTER file_kind");
    crm_visit_add_column('crm_visit_files', 'photo_category', "photo_category VARCHAR(40) NOT NULL DEFAULT '' AFTER file_role");
    crm_visit_add_column('crm_visit_files', 'followup_log_id', 'followup_log_id INT UNSIGNED NULL AFTER visit_id');
    if (function_exists('crm_visit_followup_log_ensure')) {
        crm_visit_followup_log_ensure();
    }
    db()->exec("CREATE TABLE IF NOT EXISTS miniapp_visit_meta (
        visit_id INT PRIMARY KEY, start_time TIME NULL, end_time TIME NULL, notes TEXT NULL,
        is_demo TINYINT(1) NOT NULL DEFAULT 0, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    foreach ([
        'communication_content' => 'communication_content TEXT NULL',
        'followup_progress' => 'followup_progress TEXT NULL',
        'participant_names' => 'participant_names VARCHAR(500) NOT NULL DEFAULT \'\'',
        'participant_count' => 'participant_count INT NOT NULL DEFAULT 0',
        'extra_json' => 'extra_json JSON NULL',
    ] as $col => $def) {
        try {
            $st = db()->query("SHOW COLUMNS FROM miniapp_visit_meta LIKE " . db()->quote($col));
            if (!$st->fetch()) {
                db()->exec("ALTER TABLE miniapp_visit_meta ADD COLUMN {$def}");
            }
        } catch (Throwable $e) {
        }
    }
}

/** @return array<string,mixed> */
function crm_visit_miniapp_meta_row(int $visitId): array
{
    crm_visit_miniapp_meta_ensure();
    if ($visitId <= 0) {
        return [];
    }
    $st = db()->prepare('SELECT * FROM miniapp_visit_meta WHERE visit_id = ? LIMIT 1');
    $st->execute([$visitId]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: [];
}

/** @param array<string,mixed> $input */
function crm_visit_miniapp_meta_save(int $visitId, array $input): void
{
    crm_visit_miniapp_meta_ensure();
    if ($visitId <= 0) {
        return;
    }
    $existing = crm_visit_miniapp_meta_row($visitId);
    $communication = array_key_exists('communication_content', $input)
        ? trim((string)$input['communication_content'])
        : (array_key_exists('text_record', $input) ? trim((string)$input['text_record']) : (string)($existing['communication_content'] ?? ''));
    $progress = array_key_exists('progress_update', $input)
        ? trim((string)$input['progress_update'])
        : (array_key_exists('followup_progress', $input)
            ? trim((string)$input['followup_progress'])
            : (array_key_exists('progress', $input) ? trim((string)$input['progress']) : (string)($existing['followup_progress'] ?? '')));
    $participantNames = array_key_exists('participants_text', $input)
        ? trim((string)$input['participants_text'])
        : (array_key_exists('participant_names', $input)
            ? trim((string)$input['participant_names'])
            : (array_key_exists('participants', $input) && is_string($input['participants']) ? trim($input['participants']) : (string)($existing['participant_names'] ?? '')));
    $participantCount = array_key_exists('participant_count', $input)
        ? max(0, (int)$input['participant_count'])
        : (array_key_exists('participants_count', $input) ? max(0, (int)$input['participants_count']) : (int)($existing['participant_count'] ?? 0));
    if ($participantCount === 0 && is_array($input['participants'] ?? null)) {
        $participantCount = count(array_filter($input['participants'], static fn($x) => trim((string)$x) !== ''));
    }
    $notes = array_key_exists('notes', $input)
        ? trim((string)$input['notes'])
        : (array_key_exists('planned_note', $input) ? trim((string)$input['planned_note']) : (string)($existing['notes'] ?? ''));
    // 文字记录 / 沟通内容同一正文：两端字段互相同步，避免只写一侧导致详情空白
    if ($communication === '' && $notes !== '') {
        $communication = $notes;
    }
    if ($notes === '' && $communication !== '') {
        $notes = $communication;
    }
    if (array_key_exists('notes', $input) || array_key_exists('planned_note', $input)) {
        $communication = $notes;
    } elseif (array_key_exists('communication_content', $input) || array_key_exists('text_record', $input)) {
        $notes = $communication;
    }
    $start = null;
    $end = null;
    if (function_exists('mx_time_in')) {
        $start = mx_time_in($input['start_time'] ?? $input['visit_time'] ?? '');
        $end = mx_time_in($input['end_time'] ?? '');
    }
    db()->prepare('INSERT INTO miniapp_visit_meta (visit_id, start_time, end_time, notes, communication_content, followup_progress, participant_names, participant_count)
        VALUES (?,?,?,?,?,?,?,?)
        ON DUPLICATE KEY UPDATE
            start_time=COALESCE(VALUES(start_time), start_time),
            end_time=COALESCE(VALUES(end_time), end_time),
            notes=VALUES(notes),
            communication_content=VALUES(communication_content),
            followup_progress=VALUES(followup_progress),
            participant_names=VALUES(participant_names),
            participant_count=VALUES(participant_count)')
        ->execute([$visitId, $start, $end, $notes, $communication, $progress, mb_substr($participantNames, 0, 500), $participantCount]);
}

/** @return array<string,mixed> */
function crm_visit_miniapp_meta_public(array $meta, array $visitRow = []): array
{
    // 沟通内容兼容回退：communication_content → meta.notes → 调用方已解析的 notes → planned_note（旧文字记录）
    $planned = trim((string)($visitRow['planned_note'] ?? ''));
    $priorNotes = trim((string)($visitRow['notes'] ?? ''));
    $notesMeta = trim((string)($meta['notes'] ?? ''));
    $communication = trim((string)($meta['communication_content'] ?? ''));
    if ($communication === '') {
        if ($notesMeta !== '') {
            $communication = $notesMeta;
        } elseif ($priorNotes !== '') {
            $communication = $priorNotes;
        } else {
            $communication = $planned;
        }
    }
    $notesOut = $notesMeta !== '' ? $notesMeta : $communication;
    $progress = trim((string)($meta['followup_progress'] ?? ''));
    $participantsText = trim((string)($meta['participant_names'] ?? ''));
    $bgNotes = trim((string)($visitRow['background_check_note'] ?? ''));
    return [
        'communication_content' => $communication,
        'communication_content_label' => '沟通内容',
        'text_record' => $communication,
        'progress_update' => $progress,
        'followup_progress' => $progress,
        'participants_text' => $participantsText,
        'participant_count' => (int)($meta['participant_count'] ?? 0),
        'participants' => [
            'text' => $participantsText,
            'count' => (int)($meta['participant_count'] ?? 0),
        ],
        'background_check_notes' => $bgNotes,
        'background_check_note' => $bgNotes,
        'start_time' => isset($meta['start_time']) ? substr((string)$meta['start_time'], 0, 5) : '',
        'end_time' => isset($meta['end_time']) ? substr((string)$meta['end_time'], 0, 5) : '',
        'notes' => $notesOut,
    ];
}

/** @param array<int,array<string,mixed>> $files */
function crm_visit_files_by_role(array $files): array
{
    $out = [
        'communication' => [],
        'progress' => [],
        'background_check' => [],
        'images' => [],
        'attachments' => [],
    ];
    foreach ($files as $f) {
        $role = trim((string)($f['file_role'] ?? ''));
        $photoCat = trim((string)($f['photo_category'] ?? ''));
        if ($photoCat === '' && $role !== '') {
            $photoCat = crm_visit_file_role_to_photo_category($role);
        }
        if ($photoCat !== '') {
            $f['photo_category'] = $photoCat;
        }
        $kind = (string)($f['file_kind'] ?? '');
        if ($role === 'communication' || $photoCat === 'communication') {
            $out['communication'][] = $f;
        } elseif (in_array($role, ['progress', 'followup_progress'], true) || $photoCat === 'progress') {
            $out['progress'][] = $f;
        } elseif ($role === 'background_check' || $photoCat === 'background_check') {
            $out['background_check'][] = $f;
        } elseif ($kind === 'image') {
            $out['images'][] = $f;
        } else {
            $out['attachments'][] = $f;
        }
    }
    return $out;
}
