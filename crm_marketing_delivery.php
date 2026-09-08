<?php
/** Confirmed promotion delivery. No SMTP calls during draft save or preview. */
function crm_delivery_ensure(): void
{
    static $ready = false;
    if ($ready) return;
    db()->exec("CREATE TABLE IF NOT EXISTS crm_marketing_delivery_assets (
        id CHAR(32) PRIMARY KEY, user_id INT UNSIGNED NOT NULL, file_name VARCHAR(255) NOT NULL,
        mime_type VARCHAR(120) NOT NULL, file_size INT NOT NULL, sha256 CHAR(64) NOT NULL,
        content MEDIUMBLOB NOT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_delivery_asset_user (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    db()->exec("CREATE TABLE IF NOT EXISTS crm_marketing_delivery_previews (
        token CHAR(64) PRIMARY KEY, task_id BIGINT UNSIGNED NOT NULL, user_id INT UNSIGNED NOT NULL,
        digest CHAR(64) NOT NULL, manifest LONGTEXT NOT NULL, confirmed_at DATETIME NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, KEY idx_delivery_preview_task (task_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    db()->exec("CREATE TABLE IF NOT EXISTS crm_marketing_delivery_requests (
        request_id VARCHAR(80) NOT NULL, user_id INT UNSIGNED NOT NULL, task_id BIGINT UNSIGNED NULL,
        PRIMARY KEY (request_id,user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $ready = true;
}

function crm_delivery_version(array $task): bool
{
    return (int)(crm_marketing_json($task['send_rule_json'] ?? '')['delivery_version'] ?? 0) === 2;
}

function crm_delivery_assert_owner(array $task): void
{
    if ((int)($task['created_by'] ?? 0) !== (int)(current_user()['id'] ?? 0) && !is_super_admin()) {
        throw new RuntimeException('请由项目创建人核对并确认发送。');
    }
}

function crm_delivery_attachment_mime(string $content): string
{
    // Some deployed PHP builds omit fileinfo. Keep such files as opaque download
    // attachments; never trust the browser's MIME or place uploads in the webroot.
    if (!class_exists('finfo')) return 'application/octet-stream';
    return (new finfo(FILEINFO_MIME_TYPE))->buffer($content) ?: 'application/octet-stream';
}

function crm_delivery_upload(array $files): array
{
    crm_require('promotion.task_create');
    crm_delivery_ensure();
    $file = $files['file'] ?? [];
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'] ?? '')) {
        throw new RuntimeException('附件上传失败，请重新选择文件。');
    }
    $size = (int)filesize($file['tmp_name']);
    if ($size < 1 || $size > 8 * 1024 * 1024) throw new RuntimeException('单个附件须为 1 字节至 8MB。');
    $name = crm_mail_safe_file_name((string)$file['name']);
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    if (!in_array($ext, ['pdf','doc','docx','xls','xlsx','csv','txt','png','jpg','jpeg','gif','webp','zip'], true)) {
        throw new RuntimeException('请上传 PDF、办公文档、图片、文本或 ZIP 文件。');
    }
    $content = file_get_contents($file['tmp_name']);
    if ($content === false) throw new RuntimeException('附件读取失败。');
    $mime = crm_delivery_attachment_mime($content);
    $id = bin2hex(random_bytes(16));
    $sha = hash('sha256', $content);
    db()->prepare('INSERT INTO crm_marketing_delivery_assets (id,user_id,file_name,mime_type,file_size,sha256,content) VALUES (?,?,?,?,?,?,?)')
        ->execute([$id, (int)current_user()['id'], $name, $mime, $size, $sha, $content]);
    return ['id'=>$id, 'name'=>$name, 'size'=>$size, 'type'=>$mime, 'sha256'=>$sha];
}

function crm_delivery_assets(array $ids, int $userId, bool $withContent = false): array
{
    if (count($ids) > 10) throw new RuntimeException('最多添加 10 个附件。');
    $rows = []; $total = 0;
    foreach (array_unique($ids) as $id) {
        if (!preg_match('/^[a-f0-9]{32}$/', (string)$id)) throw new RuntimeException('附件标识无效，请重新上传。');
        $stmt = db()->prepare('SELECT id,file_name AS name,mime_type AS type,file_size AS size,sha256' . ($withContent ? ',content' : '') . ' FROM crm_marketing_delivery_assets WHERE id=? AND user_id=?');
        $stmt->execute([$id, $userId]);
        $row = $stmt->fetch();
        if (!$row) throw new RuntimeException('附件不存在或不属于当前创建人，请重新上传。');
        if ($withContent && !hash_equals($row['sha256'], hash('sha256', $row['content']))) throw new RuntimeException('附件校验失败，已阻止发送。');
        $total += (int)$row['size'];
        $rows[] = $row;
    }
    if ($total > 15 * 1024 * 1024) throw new RuntimeException('附件总大小不得超过 15MB。');
    return $rows;
}

/** Contact choices take precedence only when explicitly maintained; no guessed email fallback. */
function crm_delivery_channels(int $customerId, int $contactId = 0): array
{
    $channels = [];
    if ($contactId) {
        $stmt = db()->prepare("SELECT channel,status FROM crm_contact_promotions WHERE contact_id=? ORDER BY id");
        $stmt->execute([$contactId]);
        $rows = $stmt->fetchAll();
        if ($rows) return array_values(array_unique(array_map('crm_marketing_normalize_channel',array_column(array_filter($rows,static fn($r)=>$r['status']==='active'),'channel'))));
    }
    if (!$channels) {
        $stmt = db()->prepare('SELECT channel_key FROM crm_customer_promotion_channels WHERE customer_id=? ORDER BY id');
        $stmt->execute([$customerId]);
        $channels = $stmt->fetchAll(PDO::FETCH_COLUMN);
    }
    return array_values(array_unique(array_map('crm_marketing_normalize_channel', $channels)));
}

function crm_delivery_prime_channels(array $rows): array
{
    $customers = array_values(array_unique(array_map('intval',array_column($rows,'customer_id'))));
    $contacts = array_values(array_filter(array_unique(array_map('intval',array_column($rows,'contact_id')))));
    $map = ['customers'=>[],'contacts'=>[]];
    foreach ([['customers',$customers,'crm_customer_promotion_channels','customer_id','channel_key',''],['contacts',$contacts,'crm_contact_promotions','contact_id','channel',',status']] as [$kind,$ids,$table,$key,$field,$extra]) {
        if (!$ids) continue;
        $stmt = db()->prepare("SELECT {$key},{$field}{$extra} FROM {$table} WHERE {$key} IN (" . implode(',',array_fill(0,count($ids),'?')) . ") ORDER BY id");
        $stmt->execute($ids);
        foreach ($stmt->fetchAll() as $row) {
            $id = (int)$row[$key];
            if (!isset($map[$kind][$id])) $map[$kind][$id] = [];
            if ($kind==='contacts' && $row['status']!=='active') continue;
            $map[$kind][$id][] = crm_marketing_normalize_channel($row[$field]);
        }
    }
    foreach ($map as &$group) foreach ($group as &$values) $values = array_values(array_unique($values));
    unset($group,$values);
    return $map;
}

function crm_delivery_channel(string $requested, array $channels): string
{
    $supported = ['email','wechat','whatsapp','linkedin','phone','offline','wechat_group','whatsapp_group'];
    $channels = array_values(array_intersect($channels, $supported));
    if (in_array($requested, ['preference','customer_preference','auto_preference'], true)) return count($channels) === 1 ? $channels[0] : 'unresolved';
    return in_array($requested, $channels, true) ? $requested : 'unresolved';
}

/** Monotonic spacing plus rolling hourly/daily limits, separately per sender. */
function crm_delivery_next_time(array &$times, int $base, array $schedule): int
{
    $interval = max(1, min(240, (int)($schedule['send_interval_minutes'] ?? 3))) * 60;
    $hourly = max(1, min(500, (int)($schedule['hourly_limit'] ?? 50)));
    $daily = max(1, min(3000, (int)($schedule['daily_limit'] ?? 200)));
    $n = count($times);
    $next = $n ? max($base, $times[$n - 1] + $interval) : $base;
    if ($n >= $hourly) $next = max($next, $times[$n - $hourly] + 3600);
    if ($n >= $daily) $next = max($next, $times[$n - $daily] + 86400);
    $times[] = $next;
    return $next;
}

function crm_delivery_render(string $template, array $row, array $account, bool $html): string
{
    return crm_delivery_render_values($template, crm_delivery_variables($row, $account), $html);
}

function crm_delivery_variables(array $row, array $account): array
{
    $vars = [
        'customer_name'=>$row['contact_name'] ?: $row['customer_name'], 'contact_name'=>$row['contact_name'],
        'company_name'=>$row['customer_name'], 'country'=>$row['country'] ?? '',
        'mail_user_name'=>($account['sender_name'] ?? '') ?: ($account['owner_name'] ?? ''),
        'mail_user_mobile'=>$account['user_phone'] ?? '', 'mail_user_position'=>$account['user_position'] ?? '',
        'send_email'=>$account['email_address'] ?? '',
    ];
    foreach (['name'=>'customer_name','customer_full_name'=>'customer_name','user_name'=>'mail_user_name','position'=>'mail_user_position','email'=>'send_email','mobile'=>'mail_user_mobile','phone'=>'mail_user_mobile'] as $alias=>$key) $vars[$alias] = $vars[$key];
    return $vars;
}

function crm_delivery_signature_html(string $key, array $account, ?string &$company = null): string
{
    if ($key === 'none') return '';
    if ($key === 'personal') $html = (string)($account['signature_html'] ?? '');
    elseif ($key === 'company') {
        if ($company === null) $company = (string)db()->query('SELECT template_html FROM crm_mail_signature_templates WHERE is_default=1 ORDER BY id DESC LIMIT 1')->fetchColumn();
        $html = $company;
    } else throw new RuntimeException('签名类型无效，请重新选择。');
    $visible = preg_replace('/[\s\x{00a0}\x{200b}]+/u', '', html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    if ($visible === '' && stripos($html, '<img') === false) {
        throw new RuntimeException(($account['email_address'] ?? '') . '：未配置所选签名。请在邮箱设置中保存签名，或明确选择不使用签名。');
    }
    return $html;
}

/** Read-only, explicit mailbox check. Never substitute another sender or save a draft. */
function crm_delivery_signature_inspect(string $key, int $accountId): array
{
    if ($key === 'none') return ['preview_html'=>'','missing'=>[],'recipient_variables'=>[],'ready'=>true];
    if ($accountId <= 0) throw new RuntimeException('请先选择要检查签名的发件邮箱；这里的选择不会改变执行安排。');
    $stmt = db()->prepare("SELECT a.id,a.user_id,a.email_address,a.sender_name,a.signature_html,
        COALESCE(u.real_name,u.username,'') owner_name,u.phone user_phone,u.position user_position
        FROM crm_user_mail_accounts a JOIN crm_users u ON u.id=a.user_id
        WHERE a.id=? AND a.deleted_at IS NULL AND a.is_enabled=1 AND u.status='active'");
    $stmt->execute([$accountId]);
    $account = $stmt->fetch();
    if (!$account || ((int)$account['user_id'] !== (int)current_user()['id'] && !crm_can('mail.account_manage_all') && !is_super_admin())) {
        throw new RuntimeException('该邮箱不可用或你无权查看其签名，请选择自己的邮箱或联系管理员。');
    }
    $html = crm_delivery_signature_html($key, $account);
    return ['account_id'=>$accountId,'sender_email'=>$account['email_address']] + crm_delivery_signature_sample($html, $account);
}

function crm_delivery_signature_sample(string $html, array $account): array
{
    $vars = crm_delivery_variables(['contact_name'=>'','customer_name'=>''], $account);
    $recipientKeys = ['customer_name','contact_name','company_name','country','name','customer_full_name'];
    $labels = ['mail_user_name'=>'发件人姓名','user_name'=>'发件人姓名','mail_user_mobile'=>'手机号','mobile'=>'手机号','phone'=>'手机号','mail_user_position'=>'职位','position'=>'职位','send_email'=>'发件邮箱','email'=>'发件邮箱'];
    $missing = []; $recipients = [];
    $preview = preg_replace_callback('/\{([a-zA-Z_][a-zA-Z0-9_]*)\}/', static function ($m) use ($vars, $recipientKeys, $labels, &$missing, &$recipients) {
        $key = $m[1];
        if (in_array($key, $recipientKeys, true)) { $recipients[$key] = $m[0]; return htmlspecialchars($m[0], ENT_QUOTES, 'UTF-8'); }
        $value = trim((string)($vars[$key] ?? ''));
        if ($value === '') { $missing[$key] = ($labels[$key] ?? '不支持的变量') . ' ' . $m[0]; return htmlspecialchars($m[0], ENT_QUOTES, 'UTF-8'); }
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }, $html);
    if ($preview === null) throw new RuntimeException('签名解析失败，请检查签名格式。');
    return ['preview_html'=>$preview,'missing'=>array_values($missing),'recipient_variables'=>array_values($recipients),'ready'=>!$missing];
}

function crm_delivery_render_values(string $template, array $vars, bool $html): string
{
    return preg_replace_callback('/\{([a-zA-Z_][a-zA-Z0-9_]*)\}/', static function ($match) use ($vars, $html) {
        $value = trim((string)($vars[$match[1]] ?? ''));
        if ($value === '') throw new RuntimeException('变量 {' . $match[1] . '} 缺少资料，请补齐或从内容中移除。');
        return $html ? htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : str_replace(["\r", "\n"], ' ', $value);
    }, $template) ?? $template;
}

/** No credentials or mutable references: content and variable values are frozen. */
function crm_delivery_pack_content(array &$contents, string $body, string $signature, array $row, array $account): array
{
    $key = hash('sha256', $body);
    $signatureKey = hash('sha256', $signature);
    $contents[$key] = $body;
    $contents[$signatureKey] = $signature;
    $vars = crm_delivery_variables($row, $account);
    // Retain only values used by the template, avoiding unnecessary personal data.
    preg_match_all('/\{([a-zA-Z_][a-zA-Z0-9_]*)\}/', $body . $signature, $matches);
    $vars = array_intersect_key($vars, array_flip($matches[1]));
    $rendered = crm_delivery_render_values($body, $vars, true);
    try { crm_delivery_render_values($signature, $vars, true); }
    catch (RuntimeException $e) {
        throw new RuntimeException(($account['email_address'] ?? '') . ' 的签名无法使用：' . $e->getMessage() . ' 请在“内容与签名”检查签名，并在人员资料补齐手机号/职位，或在邮箱设置修改签名后重新预览。', 0, $e);
    }
    if (trim(html_entity_decode(strip_tags($rendered))) === '' && stripos($rendered, '<img') === false) throw new RuntimeException('邮件正文或人工话术不能为空。');
    return ['content_ref'=>$key, 'signature_ref'=>$signatureKey, 'content_vars'=>$vars];
}

function crm_delivery_expand_item(array $manifest, array $item): array
{
    if (!isset($item['content_ref'])) return $item; // Already-issued legacy snapshots.
    $body = $manifest['contents'][$item['content_ref']] ?? null;
    $signature = $manifest['contents'][$item['signature_ref']] ?? null;
    if ($body === null || $signature === null) throw new RuntimeException('预览内容不完整，请重新生成。');
    $vars = $item['content_vars'];
    $item['body_html'] = crm_delivery_render_values($body, $vars, true);
    $item['signature_html'] = crm_delivery_render_values($signature, $vars, true);
    if ($item['signature_html'] !== '') $item['body_html'] .= '<div data-promotion-signature="true">' . $item['signature_html'] . '</div>';
    unset($item['content_ref'], $item['signature_ref'], $item['content_vars']);
    return $item;
}

/** An explicit pool never expands to unselected mailboxes. Stable ordering makes rechecks deterministic. */
function crm_delivery_account_pool(array $accounts, array $rules): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $rules['mail_account_ids'] ?? []))));
    if (!$ids && !empty($rules['mail_account_id'])) $ids = [(int)$rules['mail_account_id']];
    $pool = [];
    foreach ($ids as $id) {
        $matches = array_values(array_filter($accounts, static fn($a)=>(int)$a['id']===$id));
        if (!$matches) throw new RuntimeException('所选发件邮箱已停用或不存在，请重新选择。');
        $account = $matches[0];
        if ((int)$account['user_id'] !== (int)current_user()['id'] && !crm_can('mail.account_manage_all') && !is_super_admin()) throw new RuntimeException('无权使用所选发件邮箱。');
        $pool[] = $account;
    }
    if (!$pool) throw new RuntimeException('请明确勾选发件邮箱池，不能自动使用全部邮箱。');
    return $pool;
}

function crm_delivery_pick_account(array $accounts, array $pool, string $rule, array $row, int &$cursor, array &$countries): ?array
{
    if ($rule === 'owner_mailbox') {
        foreach ($accounts as $account) if ((int)$account['user_id'] === (int)$row['owner_user_id']) return $account;
        return null;
    }
    if (!$pool) return null;
    if ($rule === 'group_by_country') {
        $country = strtoupper(trim((string)$row['country']));
        if ($country === '') throw new RuntimeException('按国家分配需要客户填写国家。');
        if (!isset($countries[$country])) $countries[$country] = count($countries) % count($pool);
        return $pool[$countries[$country]];
    }
    if (in_array($rule, ['balanced','selected_mailbox'], true)) return $pool[$cursor++ % count($pool)];
    throw new RuntimeException('原发件规则暂不支持，请明确重新选择，未自动更改规则。');
}

function crm_delivery_manifest(array $task, int $base): array
{
    if (strlen((string)($task['mail_body_html'] ?? '')) > 1024 * 1024) throw new RuntimeException('正文及内嵌图片超过 1MB，请压缩图片或改为附件。');
    $rules = crm_marketing_json($task['send_rule_json'] ?? '');
    $schedule = crm_marketing_json($task['schedule_config_json'] ?? '');
    if (($schedule['timezone_rule'] ?? 'company_time') !== 'company_time') throw new RuntimeException('原时区规则尚未确认，请在执行安排中明确选择北京时间；未自动修改。');
    $failure = crm_marketing_json($task['failure_policy_json'] ?? '');
    foreach ([[$schedule,'send_interval_minutes',1,240],[$schedule,'hourly_limit',1,500],[$schedule,'daily_limit',1,3000],[$failure,'retry_count',0,5],[$failure,'retry_interval_minutes',5,1440]] as [$config,$key,$min,$max]) {
        if (isset($config[$key]) && (filter_var($config[$key],FILTER_VALIDATE_INT)===false || (int)$config[$key]<$min || (int)$config[$key]>$max)) throw new RuntimeException('执行规则超出有效范围：'.$key.'（'.$min.'–'.$max.'）。');
    }
    $attachments = crm_marketing_json($task['attachment_config_json'] ?? '');
    if (!empty($attachments['manual_attachments']) || !empty($attachments['datasheet_attachments']) || !empty($attachments['material_package'])) {
        throw new RuntimeException('旧附件登记不是已上传文件，请移除旧登记并重新上传实际文件。');
    }
    $assets = crm_delivery_assets($attachments['asset_ids'] ?? [], (int)$task['created_by']);
    $sqlPolicy = crm_marketing_email_suppression_sql('mt.contact_id');
    $stmt = db()->prepare("SELECT mt.*, c.customer_name,c.country,c.owner_user_id,c.email AS customer_email,
        c.phone AS customer_phone,c.whatsapp AS customer_whatsapp,c.address,
        COALESCE(ct.name,'') contact_name,COALESCE(ct.email,'') contact_email,
        COALESCE(ct.phone,'') contact_phone,COALESCE(ct.whatsapp,'') contact_whatsapp,
        COALESCE(ct.wechat,'') contact_wechat,COALESCE(ct.linkedin,'') contact_linkedin,COALESCE(ct.do_not_contact,0) contact_do_not_contact,
        g.group_name,g.group_platform,g.status AS group_status,g.use_for_promotion,g.deleted_at AS group_deleted,
        COALESCE(executor.real_name,executor.username,'') executor_name,
        ({$sqlPolicy}) suppression_reason
        FROM crm_marketing_task_targets mt
        JOIN crm_customers c ON c.id=mt.customer_id
        LEFT JOIN crm_contacts ct ON ct.id=mt.contact_id
        LEFT JOIN crm_customer_chat_groups g ON g.id=mt.chat_group_id AND g.customer_id=c.id
        LEFT JOIN crm_users executor ON executor.id=c.owner_user_id
        LEFT JOIN crm_customer_promotion_status ps ON ps.customer_id=c.id WHERE mt.task_id=? ORDER BY mt.id");
    $stmt->execute([(int)$task['id']]);
    $targets = $stmt->fetchAll();
    if (count($targets) > 3000) throw new RuntimeException('单个项目最多核对 3000 个对象，请按分组拆分。');
    $channelMap = crm_delivery_prime_channels($targets);
    $accounts = db()->query("SELECT a.id,a.user_id,a.email_address,a.sender_name,a.signature_html,a.is_default,
        COALESCE(u.real_name,u.username,'') owner_name,u.phone user_phone,u.position user_position
        FROM crm_user_mail_accounts a LEFT JOIN crm_users u ON u.id=a.user_id
        WHERE a.deleted_at IS NULL AND a.is_enabled=1 ORDER BY a.is_default DESC,a.id DESC")->fetchAll();
    $rule = $rules['mail_account_rule'] ?? 'owner_mailbox';
    $pool = null;
    $activeUsers = db()->query("SELECT id,COALESCE(real_name,username,'') name FROM crm_users WHERE status='active' ORDER BY id")->fetchAll(PDO::FETCH_KEY_PAIR);
    $manualRule = $rules['offline_executor_rule'] ?? 'owner';
    $manualIds = array_values(array_unique(array_filter(array_map('intval', $rules['offline_owner_ids'] ?? []))));
    $companySignature = null; $times = []; $seen = []; $items = []; $excluded = []; $contents = []; $cursor = 0; $countries = []; $manualCursor = 0;
    $requested = crm_marketing_normalize_channel((string)$task['channel_key']);
    foreach ($targets as $row) {
        $cid = (int)$row['customer_id']; $ctid = (int)($row['contact_id'] ?? 0);
        $channel = crm_delivery_channel($requested, $channelMap['contacts'][$ctid] ?? $channelMap['customers'][$cid] ?? []);
        $reason = (string)$row['suppression_reason'];
        if ($channel!=='email' && $reason==='联系人禁止邮件推广' && !(int)$row['contact_do_not_contact']) $reason='';
        if ($channel === 'unresolved') $reason = $reason ?: '资料未维护所选渠道，或存在多个渠道需明确选择；未自动改为邮件';
        $item = ['target_id'=>(int)$row['id'], 'customer_id'=>$cid, 'contact_id'=>$ctid,
            'customer_name'=>$row['customer_name'], 'contact_name'=>$row['contact_name'], 'channel'=>$channel];
        if ($reason !== '') { $excluded[] = $item + ['reason'=>$reason]; continue; }
        if ($channel !== 'email') {
            if (!in_array($manualRule, ['owner','creator','manual_offline_executor'], true)) throw new RuntimeException('原人工执行人规则暂不支持，请明确重新选择。');
            $methodField = ['phone'=>'phone','whatsapp'=>'whatsapp','wechat'=>'wechat','linkedin'=>'linkedin'][$channel] ?? '';
            $contact = $methodField ? trim((string)($row[($ctid?'contact_':'customer_').$methodField] ?? '')) : '';
            if ($channel==='offline') $contact=trim((string)$row['address']);
            if (in_array($channel,['wechat_group','whatsapp_group'],true) && empty($row['group_deleted']) && $row['group_status']==='active' && (int)$row['use_for_promotion']===1 && crm_marketing_normalize_channel((string)$row['group_platform'])===$channel) $contact=trim((string)$row['group_name']);
            $executorId = $manualRule === 'creator' ? (int)current_user()['id'] : (int)($row['owner_user_id'] ?? 0);
            if ($manualRule === 'manual_offline_executor') {
                if (!$manualIds) throw new RuntimeException('请明确勾选人工执行人。');
                foreach ($manualIds as $uid) if (!isset($activeUsers[$uid])) throw new RuntimeException('所选人工执行人已停用，请重新选择。');
                $executorId = $manualIds[$manualCursor++ % count($manualIds)];
            }
            if ($contact === '' || !isset($activeUsers[$executorId])) { $excluded[] = $item + ['reason'=>'人工渠道缺少联系方式或有效执行人，请补齐资料']; continue; }
            $items[] = $item + ['mode'=>'manual','contact_method'=>$contact,'executor_id'=>$executorId,'executor_name'=>$activeUsers[$executorId],
                'planned_at'=>date('Y-m-d H:i:s', $base)] + crm_delivery_pack_content($contents, (string)$task['mail_body_html'], '', $row, []);
            continue;
        }
        // A selected contact with no address never falls back to the company address.
        $email = trim((string)($ctid ? $row['contact_email'] : $row['customer_email']));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) { $excluded[] = $item + ['reason'=>'当前对象没有有效邮箱；未替换为其他对象']; continue; }
        $key = strtolower($email);
        if (isset($seen[$key])) { $excluded[] = $item + ['reason'=>'重复邮箱，只保留首个对象：' . $email]; continue; }
        $seen[$key] = true;
        if (!in_array($rule, ['owner_mailbox','selected_mailbox','balanced','group_by_country'], true)) throw new RuntimeException('原发件规则暂不支持，请明确重新选择。');
        if ($pool === null) $pool = $rule === 'owner_mailbox' ? [] : crm_delivery_account_pool($accounts, $rules);
        $account = crm_delivery_pick_account($accounts, $pool, $rule, $row, $cursor, $countries);
        if (!$account) throw new RuntimeException($row['customer_name'] . '：缺少明确匹配的发件邮箱，不能自动改用其他邮箱。');
        if (!isset($activeUsers[(int)$account['user_id']])) throw new RuntimeException('发件账号所属人员已停用，请重新选择。');
        if ((int)$account['user_id'] !== (int)current_user()['id'] && !crm_can('mail.account_manage_all') && !is_super_admin()) throw new RuntimeException('无权使用所匹配的发件邮箱，请联系管理员。');
        $signatureKey = $task['signature_key'] ?? 'personal';
        $signature = crm_delivery_signature_html($signatureKey, $account, $companySignature);
        $subject = crm_delivery_render((string)$task['mail_subject'], $row, $account, false);
        if (trim($subject) === '') throw new RuntimeException('邮件主题不能为空。');
        $content = crm_delivery_pack_content($contents, (string)$task['mail_body_html'], $signature, $row, $account);
        $accountId = (int)$account['id'];
        if (!isset($times[$accountId])) $times[$accountId] = [];
        $sendAt = crm_delivery_next_time($times[$accountId], $base, $schedule);
        $items[] = $item + ['mode'=>'email','receiver_email'=>$email,'account_id'=>$accountId,
            'sender_user_id'=>(int)$account['user_id'],'sender_email'=>$account['email_address'],
            'sender_name'=>$account['sender_name'] ?: $account['owner_name'], 'subject'=>$subject,
            'planned_at'=>date('Y-m-d H:i:s',$sendAt)] + $content;
    }
    // Include saved inputs so any edit invalidates a previously displayed preview.
    $inputs = array_intersect_key($task, array_flip(['task_name','channel_key','mail_subject','mail_body_html','signature_key','attachment_config_json','audience_config_json','send_rule_json','schedule_config_json','failure_policy_json']));
    $audience=crm_marketing_json($task['audience_config_json'] ?? '');
    foreach ($audience['excluded_customers'] ?? [] as $blocked) $excluded[]=['target_id'=>0,'customer_id'=>$blocked['id'],'customer_name'=>$blocked['name'],'contact_name'=>'','reason'=>'客户资料标记禁止推广，已排除'];
    return ['version'=>2,'task_id'=>(int)$task['id'],'owner_id'=>(int)$task['created_by'],'base'=>$base,
        'timezone'=>'Asia/Shanghai','format'=>'shared-content-v1','contents'=>$contents,'items'=>$items,'excluded'=>$excluded,'attachments'=>$assets,'inputs'=>$inputs];
}

function crm_delivery_digest(array $manifest): string
{
    return hash('sha256', json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
}

/** Recheck the exact selected recipient at send time; never silently substitute. */
function crm_delivery_verify_recipient(array $row, array $meta): void
{
    $cid = (int)$row['customer_id']; $ctid = (int)($row['contact_id'] ?? 0);
    $stmt = db()->prepare($ctid ? 'SELECT email FROM crm_contacts WHERE id=? AND customer_id=? AND deleted_at IS NULL' : 'SELECT email FROM crm_customers WHERE id=? AND deleted_at IS NULL');
    $stmt->execute($ctid ? [$ctid,$cid] : [$cid]);
    $email = trim((string)$stmt->fetchColumn());
    if (strcasecmp($email,(string)$row['receiver_email']) !== 0 || crm_delivery_channel((string)$meta['requested_channel'],crm_delivery_channels($cid,$ctid)) !== 'email') {
        throw new RuntimeException('客户邮箱或推广渠道已变化，已阻止发送；请重新核对。');
    }
}

function crm_delivery_preview(array $input): array
{
    crm_require('promotion.task_create'); crm_delivery_ensure();
    return crm_marketing_with_task_lock((int)($input['task_id'] ?? 0), static function ($task) {
        crm_delivery_assert_owner($task);
        if (!crm_delivery_version($task) || $task['task_status'] !== 'draft') throw new RuntimeException('请先保存新版草稿，再生成最终预览。');
        $schedule = crm_marketing_json($task['schedule_config_json'] ?? '');
        $base = time() + 600;
        if (in_array(($schedule['schedule_type'] ?? 'manual'), ['scheduled','auto'], true)) {
            $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', substr(str_replace(' ', 'T', (string)($schedule['scheduled_at'] ?? '')),0,16), new DateTimeZone('Asia/Shanghai'));
            if (!$date || $date->getTimestamp() < time() + 120) throw new RuntimeException('预约时间至少应在两分钟以后（北京时间）。');
            $base = $date->getTimestamp();
        }
        $manifest = crm_delivery_manifest($task, $base);
        $token = bin2hex(random_bytes(32));
        db()->prepare('INSERT INTO crm_marketing_delivery_previews (token,task_id,user_id,digest,manifest) VALUES (?,?,?,?,?)')
            ->execute([$token,(int)$task['id'],(int)current_user()['id'],crm_delivery_digest($manifest),json_encode($manifest,JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)]);
        return crm_delivery_preview_page($manifest, $token, []);
    });
}

/** Only one expanded message and one page of metadata cross the network. */
function crm_delivery_preview_page(array $manifest, string $token, array $input): array
{
    $total = count($manifest['items']);
    $index = max(0, min(max(0,$total-1), (int)($input['index'] ?? 0)));
    $page = max(0, min(max(0,(int)ceil($total/20)-1), (int)($input['page'] ?? intdiv($index,20))));
    if ($index < $page*20 || $index >= ($page+1)*20) $index = $page*20;
    $excludedPage = max(0,min(max(0,(int)ceil(count($manifest['excluded'])/20)-1),(int)($input['excluded_page'] ?? 0)));
    $senders = []; $emailCount = 0; $first = null; $last = null;
    foreach ($manifest['items'] as $item) {
        $first = $first === null ? $item['planned_at'] : min($first,$item['planned_at']);
        $last = $last === null ? $item['planned_at'] : max($last,$item['planned_at']);
        $key = $item['mode'] === 'email' ? 'mail_'.$item['account_id'] : 'manual_'.$item['executor_id'];
        if (!isset($senders[$key])) $senders[$key] = ['name'=>$item['sender_email'] ?? $item['executor_name'],'mode'=>$item['mode'],'count'=>0,'first'=>$item['planned_at'],'last'=>$item['planned_at']];
        $senders[$key]['count']++;
        $senders[$key]['first'] = min($senders[$key]['first'],$item['planned_at']);
        $senders[$key]['last'] = max($senders[$key]['last'],$item['planned_at']);
        if ($item['mode'] === 'email') $emailCount++;
    }
    $rows = [];
    foreach (array_slice($manifest['items'],$page*20,20) as $offset=>$item) {
        unset($item['body_html'],$item['signature_html'],$item['content_ref'],$item['signature_ref'],$item['content_vars']);
        $item['index'] = $page*20+$offset; $rows[] = $item;
    }
    return ['token'=>$token,'manifest'=>[
        'items'=>$rows,'current_item'=>isset($manifest['items'][$index]) ? crm_delivery_expand_item($manifest,$manifest['items'][$index]) : null,
        'selected_index'=>$index,'page'=>$page,'total'=>$total,'email_count'=>$emailCount,'manual_count'=>$total-$emailCount,
        'senders'=>array_values($senders),'first_at'=>$first,'last_at'=>$last,
        'schedule'=>crm_marketing_json($manifest['inputs']['schedule_config_json'] ?? ''),
        'excluded'=>array_slice($manifest['excluded'],$excludedPage*20,20),'excluded_page'=>$excludedPage,'excluded_total'=>count($manifest['excluded']),
        'attachments'=>$manifest['attachments'],'timezone'=>$manifest['timezone'],
    ]];
}

function crm_delivery_preview_read(array $input): array
{
    crm_require('promotion.task_create'); crm_delivery_ensure();
    $preview = crm_delivery_load_preview($input);
    crm_delivery_assert_owner(crm_marketing_task_row((int)$preview['task_id']));
    $manifest = json_decode($preview['manifest'],true,512,JSON_THROW_ON_ERROR);
    return crm_delivery_preview_page($manifest,$preview['token'],$input);
}

function crm_delivery_load_preview(array $input, bool $lock = false): array
{
    $stmt = db()->prepare('SELECT * FROM crm_marketing_delivery_previews WHERE token=? AND user_id=?' . ($lock ? ' FOR UPDATE' : ''));
    $stmt->execute([(string)($input['token'] ?? ''),(int)current_user()['id']]);
    $preview = $stmt->fetch();
    if (!$preview) throw new RuntimeException('预览已失效，请重新生成。');
    return $preview;
}

function crm_delivery_status(array $input): array
{
    crm_require('promotion.execute');
    // Status reads no manifest/body and never creates a queue or changes data.
    $stmt = db()->prepare('SELECT task_id,confirmed_at FROM crm_marketing_delivery_previews WHERE token=? AND user_id=?');
    $stmt->execute([(string)($input['token'] ?? ''), (int)current_user()['id']]);
    $preview = $stmt->fetch();
    if (!$preview) throw new RuntimeException('找不到本账号的执行确认记录，请在项目中核对。');
    $task = crm_marketing_task_row((int)$preview['task_id']);
    crm_delivery_assert_owner($task);
    return ['task_id'=>(int)$task['id'], 'confirmed'=>!empty($preview['confirmed_at']),
        'confirmed_at'=>$preview['confirmed_at'], 'task_status'=>$task['task_status']];
}

function crm_delivery_confirm(array $input): array
{
    crm_require('promotion.execute'); crm_delivery_ensure();
    $preview = crm_delivery_load_preview($input);
    return crm_marketing_with_task_lock((int)$preview['task_id'], static function ($task) use ($input) {
        crm_delivery_assert_owner($task);
        $preview = crm_delivery_load_preview($input, true);
        if (!empty($preview['confirmed_at'])) return ['task_id'=>(int)$task['id'],'already_confirmed'=>true];
        if ($task['task_status'] !== 'draft') throw new RuntimeException('项目状态已变化，请刷新；本次没有重复发送。');
        $manifest = json_decode($preview['manifest'],true,512,JSON_THROW_ON_ERROR);
        if ((int)$manifest['base'] <= time()) throw new RuntimeException('预览发送时间已过，请重新生成最终预览。');
        $current = crm_delivery_manifest($task,(int)$manifest['base']);
        if (!hash_equals($preview['digest'],crm_delivery_digest($current))) throw new RuntimeException('客户、渠道、内容、签名或安排已变化，请重新预览确认。');
        if (!$current['items']) throw new RuntimeException('没有可执行对象，请先处理排除原因。');
        $failure = crm_marketing_json($task['failure_policy_json'] ?? '');
        $schedule = crm_marketing_json($task['schedule_config_json'] ?? '');
        $manualStmt = db()->prepare("UPDATE crm_marketing_task_targets SET planned_at=?,contact_method=?,executor_user_id=?,channel_key=?,target_status='pending' WHERE id=? AND task_id=?");
        $queueStmt = db()->prepare("INSERT INTO crm_marketing_send_queue (task_id,customer_id,contact_id,sender_user_id,sender_email,receiver_email,subject,body,attachment_json,planned_server_time,send_status,send_attempts,max_attempts,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,'scheduled',0,?,NOW(),NOW())");
        $mailCount = 0;
        foreach ($current['items'] as $item) {
            $item = crm_delivery_expand_item($current, $item);
            if ($item['mode'] !== 'email') {
                $manualStmt
                    ->execute([$item['planned_at'],$item['contact_method'],$item['executor_id'],$item['channel'],$item['target_id'],$task['id']]);
                continue;
            }
            $mailCount++;
            $meta = ['delivery_version'=>2,'owner_id'=>$current['owner_id'],'asset_ids'=>array_column($current['attachments'],'id'),
                'account_id'=>$item['account_id'],'sender_name'=>$item['sender_name'],'channel'=>$item['channel'],
                'requested_channel'=>$task['channel_key'],'retry_interval_minutes'=>max(5,min(1440,(int)($failure['retry_interval_minutes'] ?? 30)))];
            $meta['send_interval_minutes']=max(1,min(240,(int)($schedule['send_interval_minutes'] ?? 3)));
            $meta['hourly_limit']=max(1,min(500,(int)($schedule['hourly_limit'] ?? 50)));
            $meta['daily_limit']=max(1,min(3000,(int)($schedule['daily_limit'] ?? 200)));
            $queueStmt
                ->execute([$task['id'],$item['customer_id'],$item['contact_id'] ?: null,$item['sender_user_id'],$item['sender_email'],$item['receiver_email'],$item['subject'],$item['body_html'],json_encode($meta),$item['planned_at'],max(1,min(6,(int)($failure['retry_count'] ?? 1)+1))]);
        }
        $excludedStmt = db()->prepare("UPDATE crm_marketing_task_targets SET target_status='skipped',failure_reason=? WHERE id=? AND task_id=?");
        foreach ($current['excluded'] as $item) $excludedStmt->execute([$item['reason'],$item['target_id'],$task['id']]);
        db()->prepare('UPDATE crm_marketing_tasks SET task_status=?,updated_at=NOW() WHERE id=?')->execute([$mailCount ? 'scheduled' : 'manual_pending',$task['id']]);
        db()->prepare('UPDATE crm_marketing_delivery_previews SET confirmed_at=NOW() WHERE token=?')->execute([$preview['token']]);
        crm_log_event('promotion','confirmed_delivery','marketing_task',(string)$task['id'],null,['mail_count'=>$mailCount,'manual_count'=>count($current['items'])-$mailCount,'digest'=>$preview['digest']]);
        return ['task_id'=>(int)$task['id'],'mail_count'=>$mailCount,'manual_count'=>count($current['items'])-$mailCount];
    });
}

function crm_delivery_test(array $input): array
{
    crm_require('promotion.task_create'); crm_require('mail.send'); crm_delivery_ensure();
    $preview = crm_delivery_load_preview($input);
    $manifest = json_decode($preview['manifest'],true,512,JSON_THROW_ON_ERROR);
    $task = crm_marketing_task_row((int)$preview['task_id']);
    crm_delivery_assert_owner($task);
    if (!hash_equals($preview['digest'],crm_delivery_digest(crm_delivery_manifest($task,(int)$manifest['base'])))) throw new RuntimeException('资料已变化，请重新预览后测试。');
    $item = $manifest['items'][(int)($input['index'] ?? -1)] ?? null;
    if (!$item || $item['mode'] !== 'email') throw new RuntimeException('请选择一封邮件预览后测试。');
    $item = crm_delivery_expand_item($manifest, $item);
    $email = trim((string)($input['test_email'] ?? ''));
    if (!filter_var($email,FILTER_VALIDATE_EMAIL)) throw new RuntimeException('请填写单个有效的测试收件邮箱。');
    $account = crm_mail_current_account(true,(int)$item['account_id'],(int)$item['sender_user_id']);
    if (!$account || $account['email_address'] !== $item['sender_email']) throw new RuntimeException('发件邮箱已变化，请重新预览。');
    $account['sender_name'] = $item['sender_name'];
    $prepared = crm_marketing_prepare_mail_inline_images($item['body_html'],$account);
    try {
        $result = crm_mail_execute_send_job($account,['to_emails'=>$email,'subject'=>'[推广测试] ' . $item['subject'],'body_html'=>$prepared['body_html']],
            array_merge($prepared['attachments'],crm_delivery_assets(array_column($manifest['attachments'],'id'),(int)$manifest['owner_id'],true)),
            'promotion_test_' . bin2hex(random_bytes(16)),$prepared['body_original']);
    } finally { crm_mail_cleanup_generated_attachments($prepared['attachments']); }
    crm_log_event('promotion','confirmed_preview_test','marketing_task',(string)$task['id'],null,['sent_mail_id'=>$result['sent_mail_id'] ?? 0]);
    return ['sent_mail_id'=>$result['sent_mail_id'] ?? 0,'sent_to'=>$email];
}

/** A connection-scoped sender lease spans SMTP, not a database transaction. */
function crm_delivery_acquire_send(array $row, array $meta): bool
{
    $key='crm_promotion_sender_'.(int)$meta['account_id'];
    $stmt=db()->prepare('SELECT GET_LOCK(?,0)');$stmt->execute([$key]);
    $held=(int)$stmt->fetchColumn()===1;
    $next=time()+60;
    try {
        if ($held) {
            $stmt=db()->prepare("SELECT UNIX_TIMESTAMP(sent_at) AS stamp FROM crm_marketing_send_queue WHERE sender_user_id=? AND sender_email=? AND send_status='sent' AND sent_at>DATE_SUB(NOW(),INTERVAL 1 DAY) ORDER BY sent_at");
            $stmt->execute([$row['sender_user_id'],$row['sender_email']]);
            $times=array_map('intval',$stmt->fetchAll(PDO::FETCH_COLUMN));
            $planned=$times;
            $next=crm_delivery_next_time($planned,time(),$meta);
            if($next<=time()) return true;
        }
        db()->prepare("UPDATE crm_marketing_send_queue SET send_status='scheduled',send_attempts=GREATEST(0,send_attempts-1),planned_server_time=?,updated_at=NOW() WHERE id=? AND send_status='sending'")->execute([date('Y-m-d H:i:s',$next),$row['id']]);
    } catch(Throwable $e) {
        if($held)crm_delivery_release_send($meta);
        throw $e;
    }
    if($held)crm_delivery_release_send($meta);
    return false;
}

function crm_delivery_release_send(array $meta): void
{
    $stmt=db()->prepare('SELECT RELEASE_LOCK(?)');$stmt->execute(['crm_promotion_sender_'.(int)$meta['account_id']]);
}
