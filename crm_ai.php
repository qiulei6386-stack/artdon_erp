<?php
require_once __DIR__ . '/crm_customer.php';
require_once __DIR__ . '/crm_opportunity.php';

function crm_ai_ensure_tables(): void
{
    crm_customer_ensure_tables();
    db()->exec("CREATE TABLE IF NOT EXISTS crm_ai_tasks (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        task_type VARCHAR(60) NOT NULL,
        source_type VARCHAR(80) NOT NULL DEFAULT 'manual',
        source_id VARCHAR(120) NULL,
        customer_id INT UNSIGNED NULL,
        contact_id INT UNSIGNED NULL,
        opportunity_id INT UNSIGNED NULL,
        quote_id VARCHAR(80) NULL,
        material_id VARCHAR(80) NULL,
        dispatch_id VARCHAR(80) NULL,
        status VARCHAR(60) NOT NULL DEFAULT 'pending',
        confidence DECIMAL(5,2) NOT NULL DEFAULT 0,
        ai_summary TEXT NULL,
        ai_result_json JSON NULL,
        missing_fields_json JSON NULL,
        suggested_actions_json JSON NULL,
        assigned_user_id INT UNSIGNED NULL,
        confirm_user_id INT UNSIGNED NULL,
        confirmed_at DATETIME NULL,
        rejected_reason TEXT NULL,
        created_by INT UNSIGNED NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        KEY idx_ai_task_type (task_type),
        KEY idx_ai_status (status),
        KEY idx_ai_customer (customer_id),
        KEY idx_ai_assigned (assigned_user_id),
        KEY idx_ai_created (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    db()->exec("CREATE TABLE IF NOT EXISTS crm_ai_logs (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        ai_task_id BIGINT UNSIGNED NULL,
        action_key VARCHAR(120) NOT NULL,
        result_status VARCHAR(40) NOT NULL DEFAULT 'success',
        detail_json JSON NULL,
        operator_id INT UNSIGNED NULL,
        failure_reason VARCHAR(500) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_ai_log_task (ai_task_id),
        KEY idx_ai_log_action (action_key),
        KEY idx_ai_log_created (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    db()->exec("CREATE TABLE IF NOT EXISTS crm_ai_settings (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        setting_key VARCHAR(120) NOT NULL UNIQUE,
        setting_json JSON NULL,
        updated_by INT UNSIGNED NULL,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    crm_ai_ensure_permissions();
}

function crm_ai_ensure_permissions(): void
{
    $items = [
        ['ai.view','ai','view','查看 AI 机器人','low'],
        ['ai.lead_capture','ai','lead_capture','使用 AI 自动获客','medium'],
        ['ai.quote_draft','ai','quote_draft','使用 AI 报价草稿','medium'],
        ['ai.material_draft','ai','material_draft','使用 AI 资料草稿','medium'],
        ['ai.confirm_customer','ai','confirm_customer','确认 AI 客户草稿','medium'],
        ['ai.confirm_opportunity','ai','confirm_opportunity','确认 AI 商机草稿','medium'],
        ['ai.confirm_quote','ai','confirm_quote','确认 AI 报价草稿','high'],
        ['ai.confirm_material','ai','confirm_material','确认 AI 资料草稿','high'],
        ['ai.reject','ai','reject','驳回 AI 草稿','medium'],
        ['ai.logs','ai','logs','查看 AI 日志','low'],
        ['ai.settings','ai','settings','修改 AI 设置','high'],
    ];
    $stmt = db()->prepare('INSERT IGNORE INTO crm_permissions (permission_key, module, action, description, risk_level, created_at) VALUES (?, ?, ?, ?, ?, NOW())');
    foreach ($items as $item) $stmt->execute($item);
    db()->exec("INSERT IGNORE INTO crm_role_permissions (role_id, permission_key)
        SELECT r.id, p.permission_key FROM crm_roles r JOIN crm_permissions p
        WHERE r.role_key = 'super_admin' AND p.module = 'ai'");
    db()->exec("INSERT IGNORE INTO crm_role_permissions (role_id, permission_key)
        SELECT r.id, p.permission_key FROM crm_roles r JOIN crm_permissions p
        WHERE r.role_key = 'manager' AND p.permission_key IN ('ai.view','ai.lead_capture','ai.quote_draft','ai.material_draft','ai.confirm_customer','ai.confirm_opportunity','ai.confirm_quote','ai.confirm_material','ai.reject','ai.logs','ai.settings')");
    db()->exec("INSERT IGNORE INTO crm_role_permissions (role_id, permission_key)
        SELECT r.id, p.permission_key FROM crm_roles r JOIN crm_permissions p
        WHERE r.role_key IN ('sales','marketing') AND p.permission_key IN ('ai.view','ai.lead_capture','ai.quote_draft','ai.material_draft','ai.confirm_customer','ai.confirm_opportunity','ai.confirm_quote','ai.confirm_material','ai.reject','ai.logs')");
}

function crm_ai_json($value): string
{
    return json_encode($value, JSON_UNESCAPED_UNICODE);
}

function crm_ai_decode($value): array
{
    if (is_array($value)) return $value;
    return json_decode((string)$value, true) ?: [];
}

function crm_ai_log(?int $taskId, string $action, array $detail = [], string $status = 'success', string $failure = ''): void
{
    db()->prepare('INSERT INTO crm_ai_logs (ai_task_id, action_key, result_status, detail_json, operator_id, failure_reason, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())')
        ->execute([$taskId ?: null, $action, $status, crm_ai_json($detail), current_user()['id'] ?? null, $failure]);
    crm_log_event('ai', $action, $taskId ? 'ai_task' : 'ai', $taskId ? (string)$taskId : '', null, $detail, $status !== 'failed', $failure);
}

function crm_ai_pending_interface(string $name): array
{
    crm_ai_log(null, 'pending_interface', ['interface' => $name], 'failed', $name . ' 接口待接入');
    return ['pending_interface' => true, 'interface' => $name, 'message' => $name . ' 接口待接入，AI 已保留草稿和确认任务，不会假装成功。'];
}

function crm_ai_extract(string $text, string $taskType = 'lead_capture'): array
{
    $plain = trim(strip_tags($text));
    $lower = mb_strtolower($plain);
    preg_match('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', $plain, $emailMatch);
    preg_match('/\b(?:AT|EX|IN|DE|[0-9]{2})[-.]?[A-Z0-9]{3,12}\b/i', $plain, $modelMatch);
    preg_match('/(?:数量|qty|quantity|pcs|套|个)[^\d]{0,8}(\d{1,7})|(\d{1,7})\s*(?:pcs|套|个)/iu', $plain, $qtyMatch);
    preg_match('/\b(?:India|China|UAE|Korea|Japan|USA|United States|Hong Kong|HK|Germany|France|UK|Dubai|Saudi|Qatar|Singapore)\b/iu', $plain, $countryMatch);
    $needsQuote = (bool)preg_match('/quote|quotation|price|报价|价格|单价/iu', $plain);
    $needsMaterial = (bool)preg_match('/catalog|datasheet|spec|资料|规格书|图册|尺寸图|ies/iu', $plain);
    $needsSample = (bool)preg_match('/sample|样品|打样/iu', $plain);
    $needsTechnical = (bool)preg_match('/custom|technical|drawing|定制|技术|图纸|方案/iu', $plain);
    $company = '';
    if (preg_match('/(?:company|公司)[:：\s]+([^\n\r,;]{2,80})/iu', $plain, $m)) $company = trim($m[1]);
    elseif (!empty($emailMatch[0])) $company = ucfirst(explode('.', explode('@', $emailMatch[0])[1] ?? '')[0] ?? '');
    $contact = '';
    if (preg_match('/(?:name|contact|联系人|姓名)[:：\s]+([^\n\r,;]{2,60})/iu', $plain, $m)) $contact = trim($m[1]);
    $missing = [];
    if (!$company) $missing[] = '客户公司名称';
    if (empty($emailMatch[0])) $missing[] = '邮箱';
    if (empty($modelMatch[0]) && in_array($taskType, ['quote_draft','material_draft'], true)) $missing[] = '产品型号';
    if (empty($qtyMatch[1]) && empty($qtyMatch[2]) && $taskType === 'quote_draft') $missing[] = '数量';
    $basis = [];
    foreach (['quote' => $needsQuote, 'material' => $needsMaterial, 'sample' => $needsSample, 'technical' => $needsTechnical] as $key => $hit) {
        if ($hit) $basis[] = $key;
    }
    $confidence = 35;
    if (!empty($emailMatch[0])) $confidence += 15;
    if ($company) $confidence += 15;
    if (!empty($modelMatch[0])) $confidence += 15;
    if ($needsQuote || $needsMaterial || $needsSample) $confidence += 15;
    if (!$missing) $confidence += 5;
    $confidence = min(96, $confidence);
    return [
        'customer_draft' => [
            'customer_name' => $company,
            'contact_name' => $contact,
            'email' => $emailMatch[0] ?? '',
            'country' => $countryMatch[0] ?? '',
        ],
        'need' => [
            'summary' => mb_substr($plain, 0, 300),
            'product_model' => $modelMatch[0] ?? '',
            'quantity' => $qtyMatch[1] ?? ($qtyMatch[2] ?? ''),
            'need_quote' => $needsQuote ? 1 : 0,
            'need_material' => $needsMaterial ? 1 : 0,
            'need_sample' => $needsSample ? 1 : 0,
            'need_technical' => $needsTechnical ? 1 : 0,
        ],
        'basis' => $basis,
        'missing_fields' => $missing,
        'suggested_actions' => array_values(array_filter([
            $needsQuote ? '创建报价草稿' : '',
            $needsMaterial ? '创建资料草稿' : '',
            '创建确认任务',
            $company ? '查重客户库' : '补充客户名称',
        ])),
        'confidence' => $confidence,
    ];
}

function crm_ai_duplicate_candidates(array $result): array
{
    $draft = $result['customer_draft'] ?? [];
    $email = (string)($draft['email'] ?? '');
    $name = trim((string)($draft['customer_name'] ?? ''));
    $domain = $email && strpos($email, '@') !== false ? substr(strrchr($email, '@'), 1) : '';
    $where = [];
    $params = [];
    if ($domain !== '') {
        $where[] = '(c.email LIKE ? OR EXISTS (SELECT 1 FROM crm_contacts ct WHERE ct.customer_id=c.id AND ct.deleted_at IS NULL AND ct.email LIKE ?))';
        $params[] = '%@' . $domain;
        $params[] = '%@' . $domain;
    }
    if ($name !== '') {
        $where[] = 'c.customer_name LIKE ?';
        $params[] = '%' . $name . '%';
    }
    if (!$where) return [];
    $stmt = db()->prepare('SELECT c.id, c.customer_name, c.country, c.email FROM crm_customers c WHERE c.deleted_at IS NULL AND (' . implode(' OR ', $where) . ') ORDER BY c.updated_at DESC LIMIT 8');
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function crm_ai_create_task(string $taskType, array $input): array
{
    crm_ai_ensure_tables();
    $perm = [
        'lead_capture' => 'ai.lead_capture',
        'quote_draft' => 'ai.quote_draft',
        'material_draft' => 'ai.material_draft',
        'confirm_task' => 'ai.view',
    ][$taskType] ?? 'ai.view';
    crm_require($perm);
    $text = (string)($input['source_text'] ?? $input['content'] ?? '');
    $result = crm_ai_extract($text, $taskType);
    $duplicates = crm_ai_duplicate_candidates($result);
    $result['duplicate_candidates'] = $duplicates;
    $status = $taskType === 'confirm_task' ? 'waiting_confirm' : (in_array($taskType, ['quote_draft','material_draft'], true) ? 'interface_pending' : 'analyzed');
    $assigned = (int)($input['assigned_user_id'] ?? (current_user()['id'] ?? 0)) ?: null;
    $summary = ($result['need']['summary'] ?? '') ?: 'AI 识别草稿';
    $stmt = db()->prepare('INSERT INTO crm_ai_tasks (task_type, source_type, source_id, customer_id, contact_id, opportunity_id, status, confidence, ai_summary, ai_result_json, missing_fields_json, suggested_actions_json, assigned_user_id, created_by, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())');
    $stmt->execute([
        $taskType,
        (string)($input['source_type'] ?? 'manual'),
        (string)($input['source_id'] ?? ''),
        (int)($input['customer_id'] ?? 0) ?: null,
        (int)($input['contact_id'] ?? 0) ?: null,
        (int)($input['opportunity_id'] ?? 0) ?: null,
        $status,
        $result['confidence'],
        $summary,
        crm_ai_json($result),
        crm_ai_json($result['missing_fields'] ?? []),
        crm_ai_json($result['suggested_actions'] ?? []),
        $assigned,
        current_user()['id'] ?? null,
    ]);
    $id = (int)db()->lastInsertId();
    crm_ai_log($id, 'ai_task_create', ['task_type' => $taskType, 'confidence' => $result['confidence'], 'status' => $status]);
    $customerId = (int)($input['customer_id'] ?? 0);
    if ($customerId > 0) {
        crm_customer_timeline_add($customerId, 'ai_task_create', 'AI 创建草稿 / 确认任务', $summary, 'ai_task', (string)$id);
    }
    return ['task_id' => $id, 'task' => crm_ai_task_get($id), 'result' => $result, 'pending_interface' => in_array($taskType, ['quote_draft','material_draft'], true)];
}

function crm_ai_task_get(int $id): array
{
    $stmt = db()->prepare("SELECT t.*, u.username assigned_user_name, cu.username created_by_name, c.customer_name, ct.name contact_name, o.opportunity_name
        FROM crm_ai_tasks t
        LEFT JOIN crm_users u ON u.id=t.assigned_user_id
        LEFT JOIN crm_users cu ON cu.id=t.created_by
        LEFT JOIN crm_customers c ON c.id=t.customer_id
        LEFT JOIN crm_contacts ct ON ct.id=t.contact_id
        LEFT JOIN crm_opportunities o ON o.id=t.opportunity_id
        WHERE t.id=? LIMIT 1");
    $stmt->execute([$id]);
    $row = $stmt->fetch() ?: [];
    foreach (['ai_result_json','missing_fields_json','suggested_actions_json'] as $key) {
        $row[str_replace('_json', '', $key)] = crm_ai_decode($row[$key] ?? '');
    }
    return $row;
}

function crm_ai_tasks(array $input = []): array
{
    crm_ai_ensure_tables();
    crm_require('ai.view');
    $type = trim((string)($input['task_type'] ?? ''));
    $status = trim((string)($input['status'] ?? ''));
    $where = ['1=1'];
    $params = [];
    if ($type !== '') { $where[] = 't.task_type=?'; $params[] = $type; }
    if ($status !== '') { $where[] = 't.status=?'; $params[] = $status; }
    if (!is_super_admin() && !has_permission('ai.settings')) {
        $where[] = '(t.assigned_user_id=? OR t.created_by=?)';
        $params[] = current_user()['id'] ?? 0;
        $params[] = current_user()['id'] ?? 0;
    }
    $stmt = db()->prepare("SELECT t.*, u.username assigned_user_name, cu.username created_by_name, c.customer_name, ct.name contact_name, o.opportunity_name
        FROM crm_ai_tasks t
        LEFT JOIN crm_users u ON u.id=t.assigned_user_id
        LEFT JOIN crm_users cu ON cu.id=t.created_by
        LEFT JOIN crm_customers c ON c.id=t.customer_id
        LEFT JOIN crm_contacts ct ON ct.id=t.contact_id
        LEFT JOIN crm_opportunities o ON o.id=t.opportunity_id
        WHERE " . implode(' AND ', $where) . " ORDER BY t.id DESC LIMIT 120");
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
    foreach ($rows as &$row) {
        $row['missing_fields'] = crm_ai_decode($row['missing_fields_json'] ?? '');
        $row['suggested_actions'] = crm_ai_decode($row['suggested_actions_json'] ?? '');
    }
    return $rows;
}

function crm_ai_logs(array $input = []): array
{
    crm_ai_ensure_tables();
    crm_require('ai.logs');
    $taskId = (int)($input['task_id'] ?? 0);
    $params = [];
    $where = ['1=1'];
    if ($taskId > 0) { $where[] = 'l.ai_task_id=?'; $params[] = $taskId; }
    $stmt = db()->prepare("SELECT l.*, u.username operator_name FROM crm_ai_logs l LEFT JOIN crm_users u ON u.id=l.operator_id WHERE " . implode(' AND ', $where) . " ORDER BY l.id DESC LIMIT 120");
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function crm_ai_bootstrap(array $input = []): array
{
    $tasks = crm_ai_tasks($input);
    $today = date('Y-m-d');
    $kpis = [
        'today_leads' => 0, 'customer_drafts' => 0, 'opportunity_drafts' => 0,
        'quote_drafts' => 0, 'material_drafts' => 0, 'waiting_confirm' => 0,
        'confirmed' => 0, 'rejected' => 0, 'failed' => 0,
    ];
    foreach ($tasks as $task) {
        if (strpos((string)$task['created_at'], $today) === 0 && $task['task_type'] === 'lead_capture') $kpis['today_leads']++;
        if ($task['task_type'] === 'lead_capture') $kpis['customer_drafts']++;
        if ($task['task_type'] === 'quote_draft') $kpis['quote_drafts']++;
        if ($task['task_type'] === 'material_draft') $kpis['material_drafts']++;
        if ($task['status'] === 'waiting_confirm') $kpis['waiting_confirm']++;
        if ($task['status'] === 'confirmed') $kpis['confirmed']++;
        if ($task['status'] === 'rejected') $kpis['rejected']++;
        if (in_array($task['status'], ['failed','interface_pending'], true)) $kpis['failed']++;
    }
    return ['kpis' => $kpis, 'tasks' => $tasks, 'logs' => crm_can('ai.logs') ? crm_ai_logs([]) : [], 'settings' => crm_ai_settings_get()];
}

function crm_ai_settings_get(): array
{
    crm_ai_ensure_tables();
    $defaults = [
        'lead' => ['enabled' => 1, 'min_confidence' => 70, 'auto_create_draft' => 1, 'auto_create_confirm_task' => 1],
        'quote' => ['enabled' => 1, 'min_confidence' => 75, 'auto_create_quote_draft' => 1, 'auto_create_confirm_task' => 1],
        'material' => ['enabled' => 1, 'auto_create_material_task' => 1, 'auto_create_confirm_task' => 1],
        'safety' => ['allow_auto_send_mail' => 0, 'allow_auto_send_quote' => 0, 'allow_auto_send_material' => 0, 'human_confirm_required' => 1],
    ];
    foreach (db()->query('SELECT setting_key, setting_json FROM crm_ai_settings') as $row) {
        $defaults[$row['setting_key']] = array_replace($defaults[$row['setting_key']] ?? [], crm_ai_decode($row['setting_json']));
    }
    return $defaults;
}

function crm_ai_settings_save(array $input): array
{
    crm_ai_ensure_tables();
    crm_require('ai.settings');
    $settings = $input['settings'] ?? [];
    if (is_string($settings)) $settings = json_decode($settings, true) ?: [];
    $settings['safety']['allow_auto_send_mail'] = 0;
    $settings['safety']['allow_auto_send_quote'] = 0;
    $settings['safety']['allow_auto_send_material'] = 0;
    $settings['safety']['human_confirm_required'] = 1;
    $stmt = db()->prepare('INSERT INTO crm_ai_settings (setting_key, setting_json, updated_by, updated_at) VALUES (?, ?, ?, NOW()) ON DUPLICATE KEY UPDATE setting_json=VALUES(setting_json), updated_by=VALUES(updated_by), updated_at=NOW()');
    foreach ($settings as $key => $value) $stmt->execute([$key, crm_ai_json($value), current_user()['id'] ?? null]);
    crm_ai_log(null, 'ai_settings_save', $settings);
    return ['settings' => crm_ai_settings_get()];
}

function crm_ai_contact_email_exists(int $customerId, string $email): ?int
{
    if ($customerId <= 0 || $email === '') return null;
    $stmt = db()->prepare('SELECT id FROM crm_contacts WHERE customer_id = ? AND email = ? AND deleted_at IS NULL LIMIT 1');
    $stmt->execute([$customerId, $email]);
    $id = (int)($stmt->fetchColumn() ?: 0);
    return $id ?: null;
}

function crm_ai_contact_name_exists(int $customerId, string $name): ?int
{
    if ($customerId <= 0 || $name === '') return null;
    $stmt = db()->prepare('SELECT id FROM crm_contacts WHERE customer_id = ? AND name = ? AND deleted_at IS NULL ORDER BY is_primary DESC, id DESC LIMIT 1');
    $stmt->execute([$customerId, $name]);
    $id = (int)($stmt->fetchColumn() ?: 0);
    return $id ?: null;
}

function crm_ai_customer_from_task(array $task, array $input = []): array
{
    $result = crm_ai_decode($task['ai_result_json'] ?? '');
    $draft = $result['customer_draft'] ?? [];
    $need = $result['need'] ?? [];
    $existingId = (int)($input['existing_customer_id'] ?? 0);
    $customerId = (int)($task['customer_id'] ?? 0);
    $contactId = (int)($task['contact_id'] ?? 0);
    $created = ['customer_created' => false, 'contact_created' => false, 'customer_id' => $customerId, 'contact_id' => $contactId];

    if (!$customerId && $existingId > 0) {
        crm_customer_get($existingId);
        $customerId = $existingId;
        $created['customer_id'] = $customerId;
        crm_ai_log((int)$task['id'], 'ai_link_existing_customer', ['customer_id' => $customerId]);
    }

    if (!$customerId) {
        $name = trim((string)($draft['customer_name'] ?? ''));
        if ($name === '') throw new RuntimeException('AI 客户草稿缺少客户名称，不能创建正式客户。请先补充客户名称。');
        $email = trim((string)($draft['email'] ?? ''));
        $contactName = trim((string)($draft['contact_name'] ?? ''));
        if ($contactName === '' && $email !== '') $contactName = strstr($email, '@', true) ?: '主联系人';
        $customer = crm_customer_create_confirmed([
            'customer_name' => $name,
            'country' => trim((string)($draft['country'] ?? '')) ?: 'Unknown',
            'email' => $email,
            'source' => $task['source_type'] === 'mail' ? 'manual' : 'website',
            'source_tags' => 'manual',
            'promotion_channels' => $email !== '' ? ['email'] : [],
            'owner_user_id' => (int)($task['assigned_user_id'] ?? 0) ?: (int)(current_user()['id'] ?? 0),
            'entry_mode' => 'force',
            'duplicate_risk_confirmed' => 1,
            'remark' => 'AI 自动获客确认创建。来源：' . ($task['source_type'] ?? 'manual') . ' #' . ($task['source_id'] ?? ''),
            'contacts_json' => $contactName !== '' ? json_encode([[
                'name' => $contactName,
                'email' => $email,
                'is_primary' => 1,
                'contact_sources' => 'manual',
                'remark' => 'AI 自动获客生成联系人',
            ]], JSON_UNESCAPED_UNICODE) : '[]',
        ]);
        $customerId = (int)($customer['customer']['id'] ?? 0);
        $created['customer_created'] = true;
        $created['customer_id'] = $customerId;
        $contactId = 0;
        if ($email !== '') $contactId = crm_ai_contact_email_exists($customerId, $email) ?: 0;
        if (!$contactId && $contactName !== '') $contactId = crm_ai_contact_name_exists($customerId, $contactName) ?: 0;
        $created['contact_id'] = $contactId;
    } elseif (!$contactId) {
        $email = trim((string)($draft['email'] ?? ''));
        $contactName = trim((string)($draft['contact_name'] ?? ''));
        if ($contactName === '' && $email !== '') $contactName = strstr($email, '@', true) ?: '主联系人';
        if ($email !== '') $contactId = crm_ai_contact_email_exists($customerId, $email) ?: 0;
        if (!$contactId && $contactName !== '') {
            crm_contact_create([
                'customer_id' => $customerId,
                'name' => $contactName,
                'email' => $email,
                'is_primary' => 1,
                'contact_sources' => 'manual',
                'remark' => 'AI 自动获客确认添加联系人',
            ], true);
            $contactId = $email !== '' ? (crm_ai_contact_email_exists($customerId, $email) ?: 0) : 0;
            if (!$contactId) $contactId = crm_ai_contact_name_exists($customerId, $contactName) ?: 0;
            $created['contact_created'] = true;
        }
        $created['contact_id'] = $contactId;
    }

    if ($customerId > 0) {
        crm_customer_timeline_add($customerId, 'ai_customer_confirm', 'AI 确认客户草稿', (string)($need['summary'] ?? $task['ai_summary'] ?? ''), 'ai_task', (string)$task['id']);
    }
    return $created;
}

function crm_ai_confirm_lead_task(array $task, array $input = []): array
{
    crm_require('ai.confirm_customer');
    $result = crm_ai_decode($task['ai_result_json'] ?? '');
    $need = $result['need'] ?? [];
    $created = crm_ai_customer_from_task($task, $input);
    $customerId = (int)($created['customer_id'] ?? 0);
    $contactId = (int)($created['contact_id'] ?? 0);
    $opportunityId = (int)($task['opportunity_id'] ?? 0);
    $needOpportunity = !empty($need['need_quote']) || !empty($need['need_material']) || !empty($need['need_sample']) || !empty($need['need_technical']) || trim((string)($need['product_model'] ?? '')) !== '';

    if ($customerId > 0 && !$opportunityId && $needOpportunity) {
        if (crm_can('opportunity.create')) {
            $customer = crm_customer_get($customerId)['customer'];
            $opportunityName = trim((string)($need['product_model'] ?? '')) ?: 'AI 识别需求';
            $detail = crm_opportunity_save([
                'customer_id' => $customerId,
                'contact_id' => $contactId,
                'opportunity_name' => ($customer['customer_name'] ?? '客户') . ' - ' . $opportunityName,
                'country' => $customer['country'] ?? '',
                'source_type' => 'ai',
                'source_id' => (string)$task['id'],
                'stage' => !empty($need['need_quote']) ? 'requirement_confirm' : 'new_need',
                'owner_user_id' => (int)($task['assigned_user_id'] ?? 0) ?: (int)(current_user()['id'] ?? 0),
                'related_model' => (string)($need['product_model'] ?? ''),
                'quantity' => (string)($need['quantity'] ?? ''),
                'unit' => 'pcs',
                'need_quote' => !empty($need['need_quote']) ? 1 : 0,
                'need_material' => !empty($need['need_material']) ? 1 : 0,
                'need_sample' => !empty($need['need_sample']) ? 1 : 0,
                'need_technical' => !empty($need['need_technical']) ? 1 : 0,
                'next_action' => 'AI 识别需求后人工跟进确认',
                'remark' => (string)($need['summary'] ?? ''),
            ]);
            $opportunityId = (int)($detail['opportunity']['id'] ?? 0);
            $created['opportunity_created'] = $opportunityId > 0;
            $created['opportunity_id'] = $opportunityId;
        } else {
            crm_ai_log((int)$task['id'], 'ai_opportunity_pending_permission', ['customer_id' => $customerId], 'failed', '缺少新建商机权限');
            crm_customer_timeline_add($customerId, 'ai_opportunity_pending_permission', 'AI 商机待创建：权限不足', (string)($need['summary'] ?? ''), 'ai_task', (string)$task['id']);
        }
    }

    if ($customerId > 0 && ($task['source_type'] ?? '') === 'mail' && !empty($task['source_id']) && function_exists('crm_mail_link_customer') && crm_can('mail.link_customer')) {
        try {
            crm_mail_link_customer((int)$task['source_id'], $customerId);
            $created['mail_linked'] = true;
        } catch (Throwable $e) {
            crm_ai_log((int)$task['id'], 'ai_mail_link_failed', ['mail_id' => $task['source_id'], 'customer_id' => $customerId], 'failed', $e->getMessage());
        }
    }

    $result['confirmed_result'] = $created;
    db()->prepare('UPDATE crm_ai_tasks SET customer_id=?, contact_id=?, opportunity_id=?, ai_result_json=?, updated_at=NOW() WHERE id=?')
        ->execute([$customerId ?: null, $contactId ?: null, $opportunityId ?: null, crm_ai_json($result), (int)$task['id']]);
    crm_ai_log((int)$task['id'], 'ai_confirm_apply', $created);
    return $created;
}

function crm_ai_confirm_interface_task(array $task): array
{
    $perm = $task['task_type'] === 'quote_draft' ? 'ai.confirm_quote' : ($task['task_type'] === 'material_draft' ? 'ai.confirm_material' : 'ai.view');
    crm_require($perm);
    $interface = $task['task_type'] === 'quote_draft' ? 'ai_create_quote_draft' : ($task['task_type'] === 'material_draft' ? 'ai_create_material_draft' : 'ai_create_confirm_task');
    crm_ai_log((int)$task['id'], 'ai_confirm_interface_pending', ['interface' => $interface], 'failed', $interface . ' 接口待接入');
    if (!empty($task['customer_id'])) {
        crm_customer_timeline_add((int)$task['customer_id'], 'ai_interface_pending', 'AI 草稿已确认，正式接口待接入', $interface, 'ai_task', (string)$task['id']);
    }
    return ['pending_interface' => true, 'interface' => $interface];
}

function crm_ai_update_confirm(array $input): array
{
    crm_ai_ensure_tables();
    $id = (int)($input['task_id'] ?? 0);
    if ($id <= 0) throw new RuntimeException('AI 任务 ID 无效');
    $mode = (string)($input['mode'] ?? 'confirmed');
    if (!in_array($mode, ['confirmed','rejected','waiting_confirm'], true)) throw new RuntimeException('确认状态无效');
    if ($mode === 'rejected') crm_require('ai.reject');
    $taskBefore = crm_ai_task_get($id);
    $applyResult = [];
    if ($mode === 'confirmed') {
        if (($taskBefore['task_type'] ?? '') === 'lead_capture') {
            $applyResult = crm_ai_confirm_lead_task($taskBefore, $input);
        } elseif (in_array((string)($taskBefore['task_type'] ?? ''), ['quote_draft','material_draft','confirm_task'], true)) {
            $applyResult = crm_ai_confirm_interface_task($taskBefore);
        }
    }
    $reason = trim((string)($input['reason'] ?? ''));
    db()->prepare('UPDATE crm_ai_tasks SET status=?, confirm_user_id=?, confirmed_at=IF(?="confirmed", NOW(), confirmed_at), rejected_reason=?, updated_at=NOW() WHERE id=?')
        ->execute([$mode, current_user()['id'] ?? null, $mode, $reason, $id]);
    crm_ai_log($id, 'ai_confirm_' . $mode, ['reason' => $reason, 'apply_result' => $applyResult]);
    $task = crm_ai_task_get($id);
    if (!empty($task['customer_id'])) {
        crm_customer_timeline_add((int)$task['customer_id'], 'ai_confirm_' . $mode, 'AI 确认任务：' . ($mode === 'confirmed' ? '通过' : '驳回'), $reason ?: ($task['ai_summary'] ?? ''), 'ai_task', (string)$id);
    }
    return ['task' => $task, 'tasks' => crm_ai_tasks([]), 'apply_result' => $applyResult];
}
