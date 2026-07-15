<?php

declare(strict_types=1);

/**
 * Artdon Guangzhou bridge for Hong Kong website inquiries.
 *
 * It receives signed inquiry.created events, stores customer details in a staging
 * pool, creates a website inquiry task reminder, and links the inquiry into
 * Guangzhou CRM lead pool plus CRM task center. It does not create formal
 * CRM customers automatically; users still confirm/merge leads manually.
 *
 * Deploy target:
 *   /www/wwwroot/Artdon/artdon_erp/website_inquiry_staging_bridge.php
 */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Artdon-Bridge-Version: inquiry-staging-v1');

function gz_bridge_json(array $payload, int $status = 200): void
{
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        $json = '{"ok":false,"message":"JSON encode failed."}';
        $status = 500;
    }
    http_response_code($status);
    header('Content-Length: ' . strlen($json));
    echo $json;
    exit;
}
function gz_bridge_load_config(): array
{
    $root = __DIR__;
    foreach ([
        $root . '/website_bridge_config.php',
        $root . '/website_config.php',
        $root . '/config.php',
        $root . '/includes/config.php',
        $root . '/includes/db.php',
    ] as $file) {
        if (!is_file($file)) continue;
        $loaded = require $file;
        if (is_array($loaded)) return $loaded;
    }
    return [];
}

function gz_bridge_cfg(array $config, array $path, mixed $default = ''): mixed
{
    $v = $config;
    foreach ($path as $key) {
        if (!is_array($v) || !array_key_exists($key, $v)) return $default;
        $v = $v[$key];
    }
    return $v;
}

function gz_bridge_env(string $key, string $default = ''): string
{
    $v = getenv($key);
    return is_string($v) && $v !== '' ? $v : $default;
}

function gz_bridge_db(array $config): PDO
{
    $db = is_array($config['db'] ?? null) ? $config['db'] : (is_array($config['database'] ?? null) ? $config['database'] : []);
    $host = (string)($db['host'] ?? gz_bridge_env('ARTDON_ERP_DB_HOST', '127.0.0.1'));
    $port = (string)($db['port'] ?? gz_bridge_env('ARTDON_ERP_DB_PORT', '3306'));
    $name = (string)($db['name'] ?? $db['database'] ?? gz_bridge_env('ARTDON_ERP_DB_NAME', 'artdon_erp'));
    $user = (string)($db['user'] ?? $db['username'] ?? gz_bridge_env('ARTDON_ERP_DB_USER', ''));
    $pass = (string)($db['pass'] ?? $db['password'] ?? gz_bridge_env('ARTDON_ERP_DB_PASS', ''));
    $charset = (string)($db['charset'] ?? 'utf8mb4');
    if ($user === '') throw new RuntimeException('Guangzhou database user is not configured.');
    return new PDO("mysql:host={$host};port={$port};dbname={$name};charset={$charset}", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
}

function gz_bridge_headers_lower(): array
{
    $headers = function_exists('getallheaders') ? getallheaders() : [];
    $lower = [];
    foreach ($headers as $key => $value) $lower[strtolower((string)$key)] = trim((string)$value);
    foreach ($_SERVER as $key => $value) {
        if (str_starts_with($key, 'HTTP_')) {
            $lower[strtolower(str_replace('_', '-', substr($key, 5)))] = trim((string)$value);
        }
    }
    return $lower;
}

function gz_bridge_token(array $config): string
{
    $token = (string)gz_bridge_cfg($config, ['sync', 'bridge_token'], '');
    if ($token === '') $token = (string)gz_bridge_cfg($config, ['bridge_token'], '');
    if ($token === '') $token = (string)gz_bridge_cfg($config, ['hongkong_inbound_token'], '');
    if ($token === '') $token = gz_bridge_env('ARTDON_WEBSITE_BRIDGE_TOKEN', '');
    return trim($token);
}

function gz_bridge_verify(array $config, string $body): void
{
    $headers = gz_bridge_headers_lower();
    $timestamp = trim((string)($headers['x-artdon-timestamp'] ?? ($_GET['artdon_ts'] ?? '')));
    $nonce = trim((string)($headers['x-artdon-nonce'] ?? ($_GET['artdon_nonce'] ?? '')));
    $signature = trim((string)($headers['x-artdon-signature'] ?? ($_GET['artdon_sig'] ?? '')));
    $token = gz_bridge_token($config);
    if ($token === '') throw new RuntimeException('Bridge token is not configured.');
    if ($timestamp === '' || $nonce === '' || $signature === '') throw new RuntimeException('Missing bridge signature headers.');
    if (!ctype_digit($timestamp) || abs(time() - (int)$timestamp) > 300) throw new RuntimeException('Bridge timestamp expired.');
    if (!preg_match('/^[a-f0-9]{16,128}$/i', $nonce)) throw new RuntimeException('Invalid bridge nonce.');
    $expected = hash_hmac('sha256', $timestamp . "\n" . $nonce . "\n" . $body, $token);
    if (!hash_equals($expected, $signature)) throw new RuntimeException('Bridge signature mismatch.');
}

function gz_bridge_clip(mixed $value, int $length): string
{
    $text = trim((string)$value);
    return function_exists('mb_substr') ? mb_substr($text, 0, $length, 'UTF-8') : substr($text, 0, $length);
}

function gz_bridge_migrate(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS website_inquiry_staging (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        hk_inquiry_id BIGINT UNSIGNED NOT NULL,
        idempotency_key VARCHAR(190) NOT NULL,
        status ENUM('new','reviewed','converted','ignored') NOT NULL DEFAULT 'new',
        source VARCHAR(80) NOT NULL DEFAULT 'hongkong_website',
        customer_name VARCHAR(160) NOT NULL DEFAULT '',
        email VARCHAR(190) NOT NULL DEFAULT '',
        phone VARCHAR(120) NOT NULL DEFAULT '',
        whatsapp VARCHAR(120) NOT NULL DEFAULT '',
        company VARCHAR(190) NOT NULL DEFAULT '',
        country VARCHAR(120) NOT NULL DEFAULT '',
        support_type VARCHAR(80) NOT NULL DEFAULT '',
        product VARCHAR(255) NOT NULL DEFAULT '',
        product_link VARCHAR(500) NOT NULL DEFAULT '',
        page_type VARCHAR(80) NOT NULL DEFAULT '',
        page_title VARCHAR(255) NOT NULL DEFAULT '',
        inquiry_message TEXT NULL,
        inquiry_detail TEXT NULL,
        payload_json LONGTEXT NOT NULL,
        received_at DATETIME NOT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_website_inquiry_staging_key (idempotency_key),
        KEY idx_website_inquiry_staging_status (status),
        KEY idx_website_inquiry_staging_created (created_at),
        KEY idx_website_inquiry_staging_email (email)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS website_inquiry_tasks (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        staging_id BIGINT UNSIGNED NOT NULL,
        hk_inquiry_id BIGINT UNSIGNED NOT NULL,
        title VARCHAR(255) NOT NULL DEFAULT '',
        message TEXT NULL,
        contact_summary TEXT NULL,
        product VARCHAR(255) NOT NULL DEFAULT '',
        product_link VARCHAR(500) NOT NULL DEFAULT '',
        priority VARCHAR(30) NOT NULL DEFAULT 'normal',
        assignees VARCHAR(500) NOT NULL DEFAULT '',
        status ENUM('pending','processing','done','cancelled') NOT NULL DEFAULT 'pending',
        due_at DATETIME NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_website_inquiry_tasks_staging (staging_id),
        KEY idx_website_inquiry_tasks_status (status),
        KEY idx_website_inquiry_tasks_due (due_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function gz_bridge_staging(PDO $pdo, array $payload, string $body): int
{
    $hkId = (int)($payload['local_inquiry_id'] ?? 0);
    if ($hkId <= 0) throw new InvalidArgumentException('Missing local_inquiry_id.');
    $key = gz_bridge_clip((string)($payload['idempotency_key'] ?? ('hk-inquiry-' . $hkId)), 190);
    $detail = (string)($payload['inquiry_detail'] ?? $payload['full_inquiry_detail'] ?? $payload['description'] ?? '');
    $stmt = $pdo->prepare("INSERT INTO website_inquiry_staging
        (hk_inquiry_id,idempotency_key,source,customer_name,email,phone,whatsapp,company,country,support_type,product,product_link,page_type,page_title,inquiry_message,inquiry_detail,payload_json,received_at)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())
        ON DUPLICATE KEY UPDATE
        customer_name=VALUES(customer_name), email=VALUES(email), phone=VALUES(phone), whatsapp=VALUES(whatsapp),
        company=VALUES(company), country=VALUES(country), support_type=VALUES(support_type), product=VALUES(product),
        product_link=VALUES(product_link), page_type=VALUES(page_type), page_title=VALUES(page_title),
        inquiry_message=VALUES(inquiry_message), inquiry_detail=VALUES(inquiry_detail), payload_json=VALUES(payload_json)");
    $stmt->execute([
        $hkId,
        $key,
        gz_bridge_clip($payload['source'] ?? 'hongkong_website', 80),
        gz_bridge_clip($payload['name'] ?? '', 160),
        gz_bridge_clip($payload['email'] ?? '', 190),
        gz_bridge_clip($payload['phone'] ?? '', 120),
        gz_bridge_clip($payload['whatsapp'] ?? '', 120),
        gz_bridge_clip($payload['company'] ?? '', 190),
        gz_bridge_clip($payload['country'] ?? '', 120),
        gz_bridge_clip($payload['support_type'] ?? '', 80),
        gz_bridge_clip($payload['product'] ?? $payload['project'] ?? '', 255),
        gz_bridge_clip($payload['product_link'] ?? '', 500),
        gz_bridge_clip($payload['page_type'] ?? '', 80),
        gz_bridge_clip($payload['page_title'] ?? '', 255),
        (string)($payload['message'] ?? ''),
        $detail,
        $body,
    ]);
    $id = (int)$pdo->lastInsertId();
    if ($id > 0) return $id;
    $find = $pdo->prepare('SELECT id FROM website_inquiry_staging WHERE idempotency_key=? LIMIT 1');
    $find->execute([$key]);
    return (int)$find->fetchColumn();
}

function gz_bridge_task(PDO $pdo, int $stagingId, array $payload): int
{
    $hkId = (int)($payload['local_inquiry_id'] ?? 0);
    $product = gz_bridge_clip($payload['product'] ?? $payload['project'] ?? '', 255);
    $name = gz_bridge_clip($payload['name'] ?? '', 80);
    $titleParts = array_values(array_filter(['官网询盘', $product, $name], static fn($v): bool => trim((string)$v) !== ''));
    $title = gz_bridge_clip(implode('｜', $titleParts), 255);
    $contact = trim(implode("\n", array_filter([
        '客户：' . (string)($payload['name'] ?? ''),
        '邮箱：' . (string)($payload['email'] ?? ''),
        '电话：' . (string)($payload['phone'] ?? ''),
        'WhatsApp：' . (string)($payload['whatsapp'] ?? ''),
        '公司：' . (string)($payload['company'] ?? ''),
        '国家：' . (string)($payload['country'] ?? ''),
    ], static fn($v): bool => !str_ends_with($v, '：'))));
    $dueDays = max(0, min(30, (int)($payload['route_due_days'] ?? 0)));
    $stmt = $pdo->prepare("INSERT INTO website_inquiry_tasks
        (staging_id,hk_inquiry_id,title,message,contact_summary,product,product_link,priority,assignees,status,due_at)
        VALUES (?,?,?,?,?,?,?,?,?,'pending',DATE_ADD(NOW(), INTERVAL ? DAY))
        ON DUPLICATE KEY UPDATE
        title=VALUES(title), message=VALUES(message), contact_summary=VALUES(contact_summary), product=VALUES(product),
        product_link=VALUES(product_link), priority=VALUES(priority), assignees=VALUES(assignees)");
    $stmt->execute([
        $stagingId,
        $hkId,
        $title !== '' ? $title : '官网询盘',
        (string)($payload['message'] ?? $payload['description'] ?? ''),
        $contact,
        $product,
        gz_bridge_clip($payload['product_link'] ?? '', 500),
        gz_bridge_clip($payload['route_priority'] ?? 'normal', 30),
        gz_bridge_clip($payload['route_assignees'] ?? '', 500),
        $dueDays,
    ]);
    $id = (int)$pdo->lastInsertId();
    if ($id > 0) return $id;
    $find = $pdo->prepare('SELECT id FROM website_inquiry_tasks WHERE staging_id=? LIMIT 1');
    $find->execute([$stagingId]);
    return (int)$find->fetchColumn();
}

function gz_bridge_assignee_names(array $payload): array
{
    $raw = (string)($payload['route_assignees'] ?? '');
    $parts = preg_split('/[,，;；\s]+/u', $raw) ?: [];
    $names = [];
    foreach ($parts as $part) {
        $name = trim($part);
        if ($name === '') continue;
        $key = strtolower($name);
        if (!isset($names[$key])) $names[$key] = $name;
    }
    return array_values($names);
}

function gz_bridge_user_ids(PDO $pdo, array $names): array
{
    $ids = [];
    foreach ($names as $name) {
        $needle = strtolower(trim((string)$name));
        if ($needle === '') continue;
        $stmt = $pdo->prepare("SELECT id FROM crm_users
            WHERE status='active'
              AND (
                LOWER(username)=?
                OR LOWER(english_name)=?
                OR LOWER(real_name)=?
                OR LOWER(email)=?
                OR LOWER(SUBSTRING_INDEX(email,'@',1))=?
              )
            ORDER BY id ASC LIMIT 1");
        $stmt->execute([$needle, $needle, $needle, $needle, $needle]);
        $id = (int)$stmt->fetchColumn();
        if ($id > 0 && !in_array($id, $ids, true)) $ids[] = $id;
    }
    return $ids;
}

function gz_bridge_system_user_id(PDO $pdo): ?int
{
    foreach ([
        "SELECT u.id FROM crm_users u LEFT JOIN crm_roles r ON r.id=u.role_id WHERE u.status='active' AND (LOWER(u.username) IN ('qiulei','admin') OR LOWER(u.email) LIKE 'qiulei%') ORDER BY u.id ASC LIMIT 1",
        "SELECT u.id FROM crm_users u LEFT JOIN crm_roles r ON r.id=u.role_id WHERE u.status='active' AND r.role_key IN ('super_admin','admin') ORDER BY u.id ASC LIMIT 1",
        "SELECT id FROM crm_users WHERE status='active' ORDER BY id ASC LIMIT 1",
    ] as $sql) {
        try {
            $id = (int)$pdo->query($sql)->fetchColumn();
            if ($id > 0) return $id;
        } catch (Throwable $e) {}
    }
    return null;
}

function gz_bridge_table_exists(PDO $pdo, string $table): bool
{
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?");
        $stmt->execute([$table]);
        return (int)$stmt->fetchColumn() > 0;
    } catch (Throwable $e) {
        return false;
    }
}

function gz_bridge_column_exists(PDO $pdo, string $table, string $column): bool
{
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?");
        $stmt->execute([$table, $column]);
        return (int)$stmt->fetchColumn() > 0;
    } catch (Throwable $e) {
        return false;
    }
}

function gz_bridge_dispatch_no(string $prefix = 'DN'): string
{
    return $prefix . date('YmdHis') . strtoupper(bin2hex(random_bytes(2)));
}

function gz_bridge_lead_pool(PDO $pdo, int $stagingId, array $payload): int
{
    $name = gz_bridge_clip($payload['company'] ?? $payload['name'] ?? '官网询盘客户', 190);
    if ($name === '') $name = '官网询盘客户';
    $email = gz_bridge_clip($payload['email'] ?? '', 190);
    $phone = gz_bridge_clip($payload['phone'] ?? $payload['whatsapp'] ?? '', 80);
    $country = gz_bridge_clip($payload['country'] ?? '', 120);
    $domain = '';
    if ($email !== '' && str_contains($email, '@')) $domain = gz_bridge_clip(substr(strrchr($email, '@') ?: '', 1), 190);
    $payloadForCrm = $payload;
    $payloadForCrm['source'] = $payloadForCrm['source'] ?? 'hongkong_website';
    $payloadForCrm['website_inquiry_staging_id'] = $stagingId;
    $payloadForCrm['crm_entry'] = 'website_inquiry_staging_bridge';
    $payloadJson = json_encode($payloadForCrm, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';

    $find = $pdo->prepare("SELECT id FROM crm_lead_pool
        WHERE status='pending'
          AND (
            payload_json LIKE ?
            OR (raw_email<>'' AND raw_email=? AND raw_name=?)
          )
        ORDER BY id DESC LIMIT 1");
    $find->execute(['%"website_inquiry_staging_id":' . $stagingId . '%', $email, $name]);
    $leadId = (int)$find->fetchColumn();
    if ($leadId > 0) {
        $stmt = $pdo->prepare("UPDATE crm_lead_pool
            SET raw_name=?, raw_email=?, raw_phone=?, raw_country=?, raw_domain=?, payload_json=?, updated_at=NOW()
            WHERE id=?");
        $stmt->execute([$name, $email, $phone, $country, $domain, $payloadJson, $leadId]);
        return $leadId;
    }

    $stmt = $pdo->prepare("INSERT INTO crm_lead_pool
        (raw_name,raw_email,raw_phone,raw_country,raw_domain,payload_json,similarity_matches_json,status,created_by,created_at,updated_at)
        VALUES (?,?,?,?,?,?,'[]','pending',NULL,NOW(),NOW())");
    $stmt->execute([$name, $email, $phone, $country, $domain, $payloadJson]);
    return (int)$pdo->lastInsertId();
}

function gz_bridge_crm_tasks(PDO $pdo, int $stagingId, int $leadId, array $payload): array
{
    $assigneeIds = gz_bridge_user_ids($pdo, gz_bridge_assignee_names($payload));
    if (!$assigneeIds) return [];
    $creatorId = gz_bridge_system_user_id($pdo);
    $collaboratorIds = array_values(array_unique(array_filter(array_merge($assigneeIds, [$creatorId]), static fn($v): bool => (int)$v > 0)));
    $collaboratorJson = json_encode(array_map('strval', $collaboratorIds), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '[]';
    $hkId = (int)($payload['local_inquiry_id'] ?? 0);
    $product = gz_bridge_clip($payload['product'] ?? $payload['project'] ?? '', 255);
    $name = gz_bridge_clip($payload['name'] ?? '', 80);
    $titleParts = array_values(array_filter(['官网询盘', $product, $name], static fn($v): bool => trim((string)$v) !== ''));
    $title = gz_bridge_clip(implode('｜', $titleParts), 255) ?: '官网询盘';
    $message = trim((string)($payload['message'] ?? $payload['description'] ?? ''));
    $detail = trim((string)($payload['inquiry_detail'] ?? $payload['full_inquiry_detail'] ?? ''));
    $description = trim(implode("\n", array_filter([
        '来源：香港官网询盘',
        '香港询盘ID：' . $hkId,
        '广州暂存ID：' . $stagingId,
        'CRM暂存池ID：' . $leadId,
        '客户：' . (string)($payload['name'] ?? ''),
        '公司：' . (string)($payload['company'] ?? ''),
        '国家：' . (string)($payload['country'] ?? ''),
        '邮箱：' . (string)($payload['email'] ?? ''),
        '电话：' . (string)($payload['phone'] ?? ''),
        'WhatsApp：' . (string)($payload['whatsapp'] ?? ''),
        '产品：' . $product,
        '链接：' . (string)($payload['product_link'] ?? ''),
        $message !== '' ? '留言：' . $message : '',
        $detail !== '' ? '详情：' . $detail : '',
    ], static fn($v): bool => !str_ends_with(trim((string)$v), '：'))));
    $priority = gz_bridge_clip($payload['route_priority'] ?? 'normal', 30);
    $dueDays = max(0, min(30, (int)($payload['route_due_days'] ?? 0)));
    $taskIds = [];
    foreach ($assigneeIds as $userId) {
        $sourceId = 'staging:' . $stagingId . ':user:' . $userId;
        $find = $pdo->prepare("SELECT id FROM crm_tasks
            WHERE deleted_at IS NULL AND task_type='dispatch_confirm' AND source_type='website_inquiry' AND source_id=?
            LIMIT 1");
        $find->execute([$sourceId]);
        $taskId = (int)$find->fetchColumn();
        if ($taskId > 0) {
            $stmt = $pdo->prepare("UPDATE crm_tasks
                SET title=?, description=?, assigned_user_id=?, priority=?, status='pending',
                    collaborator_user_ids_json=?, created_by=COALESCE(created_by, ?),
                    due_at=DATE_ADD(NOW(), INTERVAL ? DAY), reminder_at=DATE_ADD(NOW(), INTERVAL ? DAY), updated_at=NOW()
                WHERE id=?");
            $stmt->execute([$title, $description, $userId, $priority, $collaboratorJson, $creatorId, $dueDays, $dueDays, $taskId]);
        } else {
            $stmt = $pdo->prepare("INSERT INTO crm_tasks
                (task_type,title,description,source_type,source_id,customer_id,contact_id,opportunity_id,quote_id,assigned_user_id,collaborator_user_ids_json,priority,status,due_at,reminder_at,created_by,created_at,updated_at)
                VALUES ('dispatch_confirm',?,?,?,?,NULL,NULL,NULL,NULL,?, ?, ?,'pending',DATE_ADD(NOW(), INTERVAL ? DAY),DATE_ADD(NOW(), INTERVAL ? DAY),?,NOW(),NOW())");
            $stmt->execute([$title, $description, 'website_inquiry', $sourceId, $userId, $collaboratorJson, $priority, $dueDays, $dueDays, $creatorId]);
            $taskId = (int)$pdo->lastInsertId();
        }
        if ($taskId > 0) $taskIds[] = $taskId;
    }
    return $taskIds;
}

function gz_bridge_dispatch_next_tasks(PDO $pdo, int $stagingId, int $leadId, array $payload): array
{
    if (!gz_bridge_table_exists($pdo, 'dispatch_next_tasks')) return [];
    $assigneeIds = gz_bridge_user_ids($pdo, gz_bridge_assignee_names($payload));
    if (!$assigneeIds) return [];
    $creatorId = gz_bridge_system_user_id($pdo);
    if (!$creatorId) return [];
    $helperIds = array_values(array_unique(array_filter(array_merge($assigneeIds, [$creatorId]), static fn($v): bool => (int)$v > 0)));
    $helperJson = json_encode(array_map('strval', $helperIds), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '[]';
    $hkId = (int)($payload['local_inquiry_id'] ?? 0);
    $product = gz_bridge_clip($payload['product'] ?? $payload['project'] ?? '', 180);
    $name = gz_bridge_clip($payload['name'] ?? '', 80);
    $company = gz_bridge_clip($payload['company'] ?? '', 120);
    $titleParts = array_values(array_filter(['官网询盘', $product, $name], static fn($v): bool => trim((string)$v) !== ''));
    $title = gz_bridge_clip(implode('｜', $titleParts), 240) ?: '官网询盘';
    $project = gz_bridge_clip($product !== '' ? $product : (($payload['page_title'] ?? '') ?: '香港官网询盘'), 180);
    $message = trim((string)($payload['message'] ?? $payload['description'] ?? ''));
    $detail = trim((string)($payload['inquiry_detail'] ?? $payload['full_inquiry_detail'] ?? ''));
    $description = trim(implode("\n", array_filter([
        '来源：香港官网询盘',
        '香港询盘ID：' . $hkId,
        '广州暂存ID：' . $stagingId,
        'CRM暂存池ID：' . $leadId,
        '客户：' . $name,
        '公司：' . $company,
        '国家：' . (string)($payload['country'] ?? ''),
        '邮箱：' . (string)($payload['email'] ?? ''),
        '电话：' . (string)($payload['phone'] ?? ''),
        'WhatsApp：' . (string)($payload['whatsapp'] ?? ''),
        '产品：' . $product,
        '链接：' . (string)($payload['product_link'] ?? ''),
        $message !== '' ? '留言：' . $message : '',
        $detail !== '' ? '详情：' . $detail : '',
    ], static fn($v): bool => !str_ends_with(trim((string)$v), '：'))));
    $priority = gz_bridge_clip($payload['route_priority'] ?? 'normal', 30);
    if (!in_array($priority, ['normal', 'important', 'urgent', 'today'], true)) $priority = 'normal';
    $dueDays = max(0, min(30, (int)($payload['route_due_days'] ?? 0)));
    $linkedJson = json_encode([
        'source' => 'website_inquiry_staging_bridge',
        'staging_id' => $stagingId,
        'lead_pool_id' => $leadId,
        'hk_inquiry_id' => $hkId,
        'email' => (string)($payload['email'] ?? ''),
        'product_link' => (string)($payload['product_link'] ?? ''),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
    $extraJson = json_encode(['crm_task_type' => 'dispatch_confirm'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
    $ids = [];
    foreach ($assigneeIds as $userId) {
        $linkedId = 'staging:' . $stagingId . ':user:' . $userId;
        $find = $pdo->prepare("SELECT id FROM dispatch_next_tasks
            WHERE is_deleted=0 AND linked_system='website_inquiry' AND linked_table='website_inquiry_staging' AND linked_id=?
            LIMIT 1");
        $find->execute([$linkedId]);
        $taskId = (int)$find->fetchColumn();
        if ($taskId > 0) {
            $stmt = $pdo->prepare("UPDATE dispatch_next_tasks
                SET title=?, project=?, description=?, priority=?, status=IF(status IN ('done','cancelled'), status, 'pending_accept'),
                    created_by=COALESCE(NULLIF(created_by,0), ?), assigned_to=?, helper_ids_json=?,
                    task_date=CURDATE(), due_at=DATE_ADD(NOW(), INTERVAL ? DAY), linked_title=?, linked_json=?, extra_json=?, updated_at=NOW()
                WHERE id=?");
            $stmt->execute([$title, $project, $description, $priority, $creatorId, $userId, $helperJson, $dueDays, $title, $linkedJson, $extraJson, $taskId]);
        } else {
            $stmt = $pdo->prepare("INSERT INTO dispatch_next_tasks
                (task_no,task_type,dispatch_mode,parent_group_id,title,project,description,priority,status,created_by,assigned_to,helper_ids_json,task_date,due_at,progress,is_read,linked_system,linked_table,linked_id,linked_title,linked_json,extra_json,created_at,updated_at)
                VALUES (?,'dispatch','single',NULL,?,?,?,?, 'pending_accept',?,?,?,CURDATE(),DATE_ADD(NOW(), INTERVAL ? DAY),0,0,'website_inquiry','website_inquiry_staging',?,?,?, ?,NOW(),NOW())");
            $stmt->execute([gz_bridge_dispatch_no('DN'), $title, $project, $description, $priority, $creatorId, $userId, $helperJson, $dueDays, $linkedId, $title, $linkedJson, $extraJson]);
            $taskId = (int)$pdo->lastInsertId();
            if ($taskId > 0 && gz_bridge_table_exists($pdo, 'dispatch_next_notifications')) {
                $notify = $pdo->prepare("INSERT INTO dispatch_next_notifications(recipient_id,sender_id,task_id,type,title,message,created_at) VALUES(?,?,?,?,?,?,NOW())");
                $notify->execute([$userId, $creatorId, $taskId, 'new_dispatch', '新官网询盘派工', $title]);
            }
            if ($taskId > 0 && gz_bridge_table_exists($pdo, 'dispatch_next_logs')) {
                $log = $pdo->prepare("INSERT INTO dispatch_next_logs(task_id,user_id,action_type,field_name,old_value,new_value,note,ip,user_agent,created_at) VALUES(?,?,?,?,?,?,?,?,?,NOW())");
                $log->execute([$taskId, $creatorId, 'create', null, '', '', '官网询盘桥接自动创建派工', '', 'website_inquiry_staging_bridge']);
            }
        }
        if ($taskId > 0) $ids[] = $taskId;
    }
    return $ids;
}

function gz_bridge_notification_task_id(PDO $pdo, int $stagingId, int $userId): int
{
    if (!gz_bridge_table_exists($pdo, 'crm_tasks')) return 0;
    $stmt = $pdo->prepare("SELECT id FROM crm_tasks
        WHERE deleted_at IS NULL
          AND task_type='dispatch_confirm'
          AND source_type='website_inquiry'
          AND source_id=?
        ORDER BY id DESC LIMIT 1");
    $stmt->execute(['staging:' . $stagingId . ':user:' . $userId]);
    return (int)$stmt->fetchColumn();
}

function gz_bridge_create_notifications(PDO $pdo, int $stagingId, int $leadId, array $payload): array
{
    if (!gz_bridge_table_exists($pdo, 'sys_notifications')) return [];
    $assigneeIds = gz_bridge_user_ids($pdo, gz_bridge_assignee_names($payload));
    $creatorId = gz_bridge_system_user_id($pdo);
    $recipientIds = array_values(array_unique(array_filter(array_merge($assigneeIds, [$creatorId]), static fn($v): bool => (int)$v > 0)));
    if (!$recipientIds) return [];
    $hkId = (int)($payload['local_inquiry_id'] ?? 0);
    $product = gz_bridge_clip($payload['product'] ?? $payload['project'] ?? '', 180);
    $name = gz_bridge_clip($payload['name'] ?? '', 80);
    $company = gz_bridge_clip($payload['company'] ?? '', 120);
    $title = '新官网询盘';
    $content = gz_bridge_clip(trim(implode(' · ', array_filter([
        $company !== '' ? $company : $name,
        (string)($payload['country'] ?? ''),
        (string)($payload['email'] ?? ''),
        $product,
    ], static fn($v): bool => trim((string)$v) !== ''))), 800);
    if ($content === '') $content = '香港官网询盘已进入 CRM，请及时处理。';
    $created = [];
    foreach ($recipientIds as $userId) {
        $taskId = gz_bridge_notification_task_id($pdo, $stagingId, (int)$userId);
        $payloadJson = json_encode([
            'module' => 'customers',
            'category' => 'customer',
            'source_module' => 'website_inquiry',
            'source_id' => (string)$stagingId,
            'target_module' => 'tasks',
            'target_id' => $taskId > 0 ? (string)$taskId : (string)$stagingId,
            'target_url' => 'crm.php#tasks',
            'related_task_id' => $taskId ?: null,
            'lead_pool_id' => $leadId,
            'staging_id' => $stagingId,
            'hk_inquiry_id' => $hkId,
            'severity' => 'warning',
            'dedupe_key' => 'website_inquiry:new:' . $stagingId . ':' . $userId,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
        $dedupe = 'website_inquiry:new:' . $stagingId . ':' . $userId;
        $find = $pdo->prepare("SELECT id FROM sys_notifications WHERE dedupe_key=? AND user_id=? AND deleted_at IS NULL LIMIT 1");
        $find->execute([$dedupe, $userId]);
        $notificationId = (int)$find->fetchColumn();
        if ($notificationId > 0) {
            $stmt = $pdo->prepare("UPDATE sys_notifications
                SET type='website_inquiry_new', notification_type='website_inquiry_new', category='customer',
                    title=?, content=?, payload_json=?, source_module='website_inquiry', source_id=?,
                    target_module='tasks', target_id=?, target_url='crm.php#tasks', related_task_id=?,
                    severity='warning', is_archived=0, updated_at=NOW()
                WHERE id=?");
            $stmt->execute([$title, $content, $payloadJson, (string)$stagingId, $taskId > 0 ? (string)$taskId : (string)$stagingId, $taskId ?: null, $notificationId]);
        } else {
            $stmt = $pdo->prepare("INSERT INTO sys_notifications
                (user_id,type,notification_type,category,title,content,payload_json,source_module,source_id,target_module,target_id,target_url,related_task_id,severity,dedupe_key,created_by,created_at,updated_at)
                VALUES (?,'website_inquiry_new','website_inquiry_new','customer',?,?,?,?,?,'tasks',?,'crm.php#tasks',?,'warning',?,?,NOW(),NOW())");
            $stmt->execute([$userId, $title, $content, $payloadJson, 'website_inquiry', (string)$stagingId, $taskId > 0 ? (string)$taskId : (string)$stagingId, $taskId ?: null, $dedupe, $creatorId]);
            $notificationId = (int)$pdo->lastInsertId();
        }
        if ($notificationId > 0) $created[] = $notificationId;
    }
    return $created;
}

function gz_bridge_revoke_inquiry(PDO $pdo, array $payload): array
{
    $stagingId = (int)($payload['staging_id'] ?? $payload['bridge_inquiry_id'] ?? 0);
    $hkId = (int)($payload['local_inquiry_id'] ?? $payload['hk_inquiry_id'] ?? 0);
    if ($stagingId <= 0 && $hkId <= 0) {
        throw new InvalidArgumentException('Missing staging_id or local_inquiry_id for revoke.');
    }
    if ($stagingId <= 0 && $hkId > 0) {
        $find = $pdo->prepare('SELECT id FROM website_inquiry_staging WHERE hk_inquiry_id=? ORDER BY id DESC LIMIT 1');
        $find->execute([$hkId]);
        $stagingId = (int)$find->fetchColumn();
    }
    if ($stagingId <= 0) {
        return ['staging_id'=>0,'staging'=>0,'website_tasks'=>0,'crm_tasks'=>0,'dispatch_tasks'=>0,'notifications'=>0,'lead_pool'=>0];
    }

    $reason = gz_bridge_clip($payload['reason'] ?? 'Hong Kong website inquiry revoked.', 255);
    $summary = ['staging_id'=>$stagingId,'staging'=>0,'website_tasks'=>0,'crm_tasks'=>0,'dispatch_tasks'=>0,'notifications'=>0,'lead_pool'=>0];

    $stmt = $pdo->prepare("UPDATE website_inquiry_staging SET status='ignored', inquiry_detail=CONCAT(COALESCE(inquiry_detail,''), '\n\n[撤回] ', ?), updated_at=NOW() WHERE id=?");
    $stmt->execute([$reason, $stagingId]);
    $summary['staging'] = $stmt->rowCount();

    $stmt = $pdo->prepare("UPDATE website_inquiry_tasks SET status='cancelled', message=CONCAT(COALESCE(message,''), '\n\n[撤回] ', ?), updated_at=NOW() WHERE staging_id=?");
    $stmt->execute([$reason, $stagingId]);
    $summary['website_tasks'] = $stmt->rowCount();

    if (gz_bridge_table_exists($pdo, 'crm_tasks')) {
        $stmt = $pdo->prepare("UPDATE crm_tasks SET status='cancelled', deleted_at=COALESCE(deleted_at,NOW()), updated_at=NOW()
            WHERE source_type='website_inquiry'
              AND (source_id LIKE ? OR source_id=?)
              AND deleted_at IS NULL");
        $stmt->execute(['staging:' . $stagingId . ':%', (string)$stagingId]);
        $summary['crm_tasks'] = $stmt->rowCount();
    }

    if (gz_bridge_table_exists($pdo, 'dispatch_next_tasks')) {
        $stmt = $pdo->prepare("UPDATE dispatch_next_tasks
            SET status='cancelled', is_deleted=1, deleted_at=COALESCE(deleted_at,NOW()), updated_at=NOW()
            WHERE is_deleted=0
              AND linked_system='website_inquiry'
              AND linked_table='website_inquiry_staging'
              AND (linked_id LIKE ? OR linked_id=? OR linked_json LIKE ?)");
        $stmt->execute(['staging:' . $stagingId . ':%', (string)$stagingId, '%\"staging_id\":' . $stagingId . '%']);
        $summary['dispatch_tasks'] = $stmt->rowCount();
    }

    if (gz_bridge_table_exists($pdo, 'sys_notifications') && gz_bridge_column_exists($pdo, 'sys_notifications', 'deleted_at')) {
        $stmt = $pdo->prepare("UPDATE sys_notifications SET deleted_at=COALESCE(deleted_at,NOW()), updated_at=NOW()
            WHERE deleted_at IS NULL
              AND source_module='website_inquiry'
              AND source_id=?");
        $stmt->execute([(string)$stagingId]);
        $summary['notifications'] = $stmt->rowCount();
    }

    if (gz_bridge_table_exists($pdo, 'crm_lead_pool')) {
        $stmt = $pdo->prepare("UPDATE crm_lead_pool SET status='rejected', updated_at=NOW()
            WHERE status='pending' AND payload_json LIKE ?");
        $stmt->execute(['%"website_inquiry_staging_id":' . $stagingId . '%']);
        $summary['lead_pool'] = $stmt->rowCount();
    }

    return $summary;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    gz_bridge_json(['ok' => false, 'message' => 'POST required.'], 405);
}

try {
    $config = gz_bridge_load_config();
    $body = file_get_contents('php://input') ?: '';
    gz_bridge_verify($config, $body);
    $envelope = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($envelope)) throw new RuntimeException('Invalid envelope.');
    if ((string)($envelope['event_type'] ?? '') === 'system.ping') {
        gz_bridge_json(['ok' => true, 'reference' => 'gz-staging-pong-' . time(), 'message' => 'Guangzhou inquiry staging bridge is available.']);
    }
    if ((string)($envelope['event_type'] ?? '') === 'inquiry.revoke') {
        $payload = $envelope['payload'] ?? [];
        if (!is_array($payload)) throw new InvalidArgumentException('Invalid payload.');
        $pdo = gz_bridge_db($config);
        gz_bridge_migrate($pdo);
        $pdo->beginTransaction();
        $summary = gz_bridge_revoke_inquiry($pdo, $payload);
        $pdo->commit();
        gz_bridge_json([
            'ok' => true,
            'reference' => 'revoke-staging-' . (int)($summary['staging_id'] ?? 0),
            'process_status' => 'revoked',
            'data' => $summary,
            'message' => 'Website inquiry records revoked.',
        ]);
    }
    if ((string)($envelope['event_type'] ?? '') !== 'inquiry.created') {
        throw new InvalidArgumentException('Unsupported event_type.');
    }
    $payload = $envelope['payload'] ?? [];
    if (!is_array($payload)) throw new InvalidArgumentException('Invalid payload.');
    $pdo = gz_bridge_db($config);
    gz_bridge_migrate($pdo);
    $pdo->beginTransaction();
    $stagingId = gz_bridge_staging($pdo, $payload, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}');
    $taskId = gz_bridge_task($pdo, $stagingId, $payload);
    $leadPoolId = gz_bridge_lead_pool($pdo, $stagingId, $payload);
    $crmTaskIds = gz_bridge_crm_tasks($pdo, $stagingId, $leadPoolId, $payload);
    $dispatchTaskIds = gz_bridge_dispatch_next_tasks($pdo, $stagingId, $leadPoolId, $payload);
    $notificationIds = gz_bridge_create_notifications($pdo, $stagingId, $leadPoolId, $payload);
    $pdo->commit();
    gz_bridge_json([
        'ok' => true,
        'reference' => 'staging-' . $stagingId,
        'process_status' => 'crm_linked',
        'staging_id' => $stagingId,
        'bridge_inquiry_id' => $stagingId,
        'task_id' => $taskId,
        'task_table' => 'website_inquiry_tasks',
        'lead_pool_id' => $leadPoolId,
        'crm_task_ids' => $crmTaskIds,
        'dispatch_task_ids' => $dispatchTaskIds,
        'notification_ids' => $notificationIds,
        'message' => 'Website inquiry stored in staging pool, CRM lead pool, and CRM task center.',
    ]);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) $pdo->rollBack();
    gz_bridge_json(['ok' => false, 'message' => $e->getMessage()], $e instanceof InvalidArgumentException ? 422 : 500);
}
