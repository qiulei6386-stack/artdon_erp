<?php
/**
 * 推广中心 - 后端接口
 * 独立新模块，不依赖老推广代码
 */
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/response.php';
require_once __DIR__ . '/promo_lib.php';

require_login();
require_permission('promotion.view');

$pdo = db();
$user = current_user();
$userId = (int)($user['id'] ?? 0);

$action = $_GET['action'] ?? $_POST['action'] ?? '';
$input = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $raw = file_get_contents('php://input');
    $json = json_decode($raw, true);
    if (is_array($json)) $input = $json;
    $input = array_merge($_POST, $input);
} else {
    $input = $_GET;
}

function promo_is_manager(PDO $pdo, int $userId): bool {
    try {
        return has_permission('promotion.manage');
    } catch (Throwable $e) {
        return false;
    }
}

function promo_channel_map(string $key): string {
    $key = strtolower(trim($key));
    if (in_array($key, ['whatsapp', 'whatsapp_group'], true)) return 'whatsapp';
    if (in_array($key, ['wechat', 'wechat_group'], true)) return 'wechat';
    return 'email';
}

function promo_customer_channel(PDO $pdo, int $customerId): string {
    try {
        $st = $pdo->prepare("SELECT channel_key FROM crm_customer_promotion_channels WHERE customer_id=? ORDER BY id DESC LIMIT 1");
        $st->execute([$customerId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if ($row && !empty($row['channel_key'])) return promo_channel_map($row['channel_key']);
    } catch (Throwable $e) {}
    return 'email';
}

function promo_country_timezone(string $country): string {
    static $map = null;
    if ($map === null) {
        $map = [
            '美国' => 'America/New_York', 'USA' => 'America/New_York', 'United States' => 'America/New_York',
            '加拿大' => 'America/Toronto', 'Canada' => 'America/Toronto',
            '墨西哥' => 'America/Mexico_City', 'Mexico' => 'America/Mexico_City',
            '巴西' => 'America/Sao_Paulo', 'Brazil' => 'America/Sao_Paulo',
            '英国' => 'Europe/London', 'UK' => 'Europe/London',
            '德国' => 'Europe/Berlin', 'Germany' => 'Europe/Berlin',
            '法国' => 'Europe/Paris', 'France' => 'Europe/Paris',
            '意大利' => 'Europe/Rome', 'Italy' => 'Europe/Rome',
            '西班牙' => 'Europe/Madrid', 'Spain' => 'Europe/Madrid',
            '俄罗斯' => 'Europe/Moscow', 'Russia' => 'Europe/Moscow',
            '阿联酋' => 'Asia/Dubai', 'UAE' => 'Asia/Dubai',
            '沙特' => 'Asia/Riyadh', 'Saudi Arabia' => 'Asia/Riyadh',
            '印度' => 'Asia/Kolkata', 'India' => 'Asia/Kolkata',
            '日本' => 'Asia/Tokyo', 'Japan' => 'Asia/Tokyo',
            '韩国' => 'Asia/Seoul', 'Korea' => 'Asia/Seoul',
            '新加坡' => 'Asia/Singapore', 'Singapore' => 'Asia/Singapore',
            '马来西亚' => 'Asia/Kuala_Lumpur', 'Malaysia' => 'Asia/Kuala_Lumpur',
            '泰国' => 'Asia/Bangkok', 'Thailand' => 'Asia/Bangkok',
            '越南' => 'Asia/Ho_Chi_Minh', 'Vietnam' => 'Asia/Ho_Chi_Minh',
            '澳大利亚' => 'Australia/Sydney', 'Australia' => 'Australia/Sydney',
            '新西兰' => 'Pacific/Auckland', 'New Zealand' => 'Pacific/Auckland',
        ];
    }
    return $map[$country] ?? $map[trim($country)] ?? 'Asia/Shanghai';
}

function promo_smart_send_time(string $country): string {
    $tz = promo_country_timezone($country);
    try {
        $now = new DateTime('now', new DateTimeZone($tz));
        $hour = (int)$now->format('H');
        // 工作时间 9:00-18:00，如果当前不在工作时间，推到下一个工作日 9:00
        if ($hour >= 9 && $hour < 18) {
            return $now->format('Y-m-d H:i:s');
        }
        if ($hour >= 18) $now->modify('+1 day');
        $now->setTime(9, 0, 0);
        // 跳过周末
        $dow = (int)$now->format('N');
        if ($dow >= 6) $now->modify('next monday');
        return $now->format('Y-m-d H:i:s');
    } catch (Throwable $e) {
        return date('Y-m-d H:i:s');
    }
}

function promo_render_vars(string $html, array $customer): string {
    $vars = [
        '姓名' => $customer['contact_name'] ?? '', 'name' => $customer['contact_name'] ?? '',
        '公司' => $customer['customer_name'] ?? '', '公司名' => $customer['customer_name'] ?? '',
        'company' => $customer['customer_name'] ?? '',
        '国家' => $customer['country'] ?? '', 'country' => $customer['country'] ?? '',
    ];
    foreach ($vars as $k => $v) {
        $html = str_replace(['{{'.$k.'}}', '{'.$k.'}'], htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'), $html);
    }
    return $html;
}

function promo_track_secret(): string {
    return hash('sha256', 'artdon_promo_track_' . __DIR__);
}
function promo_track_sign(int $rid): string {
    return hash_hmac('sha256', (string)$rid, promo_track_secret());
}
function promo_track_base(): string {
    // 追踪域名：用当前站点的 promotion/track.php
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? '119.91.27.19';
    $dir = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/artdon_erp/promotion/api.php'), '/');
    return "$https://$host$dir/track.php";
}
/** 给单个收件人生成最终发送的 HTML（含追踪像素、链接重写、退订链接） */
function promo_build_tracked_html(string $html, int $rid, array $camp): string {
    $base = promo_track_base();
    $sig = promo_track_sign($rid);
    // 1. 链接重写（点击追踪）
    if (!empty($camp['track_clicks'])) {
        $html = preg_replace_callback(
            '#<a\s[^>]*href=["\'](https?://[^"\']+)["\']#i',
            function ($m) use ($base, $rid, $sig) {
                $trackUrl = $base . '?' . http_build_query(['t' => 'click', 'rid' => $rid, 'sig' => $sig, 'u' => base64_encode($m[1])]);
                return str_replace($m[1], $trackUrl, $m[0]);
            },
            $html
        );
    }
    // 2. 打开追踪像素
    if (!empty($camp['track_opens'])) {
        $px = $base . '?' . http_build_query(['t' => 'open', 'rid' => $rid, 'sig' => $sig]);
        $html .= '<img src="' . htmlspecialchars($px) . '" width="1" height="1" style="display:none" alt="">';
    }
    // 3. 锁定退订页脚（始终附加）
    $unsub = promo_unsub_url($rid, $base, $sig);
    $html = promo_locked_unsub_footer($html, $unsub);
    return $html;
}
/** 反垃圾预检：返回命中的敏感词列表 */
function promo_spam_check(string $subject, string $html): array {
    $words = ['免费', '赚钱', '暴富', '点击这里', '立即行动', '保证', '无风险', '100%', '$$$',
              'free', 'winner', 'congratulations', 'click here', 'act now', 'guarantee', 'no risk'];
    $text = $subject . ' ' . strip_tags($html);
    $hits = [];
    foreach ($words as $w) {
        if (stripos($text, $w) !== false) $hits[] = $w;
    }
    return array_values(array_unique($hits));
}
/** 写操作日志 */
function promo_audit(PDO $pdo, int $userId, string $action, string $targetType = '', int $targetId = 0, string $detail = ''): void {
    try {
        $pdo->prepare("INSERT INTO promo_audit_log (user_id, action, target_type, target_id, detail) VALUES (?, ?, ?, ?, ?)")
            ->execute([$userId, $action, $targetType, $targetId, substr($detail, 0, 2000)]);
    } catch (Throwable $e) {}
}
/** 频率控制：该邮箱最近 N 天内是否收到过营销邮件 */

function promo_signature_plain(string $html): string {
    return trim(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
}

/** Decode accidental entity-escaped HTML; keep real HTML intact. */
function promo_normalize_signature_html(string $html): string {
    $html = trim($html);
    if ($html === '') return '';
    // UTF-8 safety
    if (!mb_check_encoding($html, 'UTF-8')) {
        $html = mb_convert_encoding($html, 'UTF-8', 'UTF-8,GBK,GB2312,ISO-8859-1');
    }
    // If stored as &lt;div&gt;...&lt;/div&gt;, decode once so it can render as HTML
    if (strpos($html, '<') === false && (strpos($html, '&lt;') !== false || strpos($html, '&#') !== false)) {
        $decoded = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if (strpos($decoded, '<') !== false) {
            $html = $decoded;
        }
    }
    return $html;
}

/** Substitute CRM mailbox signature placeholders ({user_name}, {email}, ...). */
function promo_signature_variables(?array $account = null): array {
    $user = [];
    try {
        if (!empty($account['user_id']) && function_exists('crm_mail_user_context')) {
            $user = crm_mail_user_context((int)$account['user_id']);
        } elseif (function_exists('current_user')) {
            $user = current_user() ?: [];
        }
    } catch (Throwable $e) {
        $user = function_exists('current_user') ? (current_user() ?: []) : [];
    }
    if (!$user && !empty($account['user_id'])) {
        try {
            $st = db()->prepare('SELECT id, username, real_name, email, phone, position FROM crm_users WHERE id=? LIMIT 1');
            $st->execute([(int)$account['user_id']]);
            $user = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {}
    }
    $mailUserName = trim((string)($account['sender_name'] ?? ''));
    if ($mailUserName === '') {
        $mailUserName = (string)($user['real_name'] ?? ($user['username'] ?? ''));
    }
    $mailUserMobile = (string)($user['phone'] ?? '');
    $sendEmail = (string)($account['email_address'] ?? ($user['email'] ?? ''));
    $mailUserPosition = trim((string)($user['position'] ?? ''));
    return [
        '{customer_name}' => '',
        '{mail_user_name}' => $mailUserName,
        '{mail_user_mobile}' => $mailUserMobile,
        '{send_email}' => $sendEmail,
        '{mail_user_position}' => $mailUserPosition,
        '{user_name}' => $mailUserName,
        '{position}' => $mailUserPosition,
        '{email}' => $sendEmail,
        '{mobile}' => $mailUserMobile,
    ];
}

function promo_render_signature_html(string $html, ?array $account = null): string {
    $html = promo_normalize_signature_html($html);
    if ($html === '') return '';
    return strtr($html, promo_signature_variables($account));
}

function promo_append_signature(string $bodyHtml, string $signatureHtml, ?array $account = null): string {
    $sig = promo_render_signature_html($signatureHtml, $account);
    if ($sig === '') return $bodyHtml;
    // Already embedded by SPA (mail-signature) or previous append
    if (preg_match('/class=["\'][^"\']*(?:promo-mail-signature|mail-signature)[^"\']*["\']/', $bodyHtml)) {
        return $bodyHtml;
    }
    $needle = preg_replace('/\s+/', '', mb_substr(strip_tags($sig), 0, 80, 'UTF-8'));
    if ($needle !== '' && strpos(preg_replace('/\s+/', '', strip_tags($bodyHtml)), $needle) !== false) {
        return $bodyHtml;
    }
    return rtrim($bodyHtml) . '<div style="margin-top:24px;padding-top:12px;border-top:1px solid #e5e7eb" class="promo-mail-signature">' . $sig . '</div>';
}

/**
 * Assign mail_account_id for each email recipient index.
 * Modes: even | owner | manual (legacy: company => even)
 * Returns parallel array of account ids (0 = unset).
 */
function promo_assign_sender_accounts(PDO $pdo, array $emailRecipients, string $mode, array $mailAccountIds, array $allocations, int $fallbackMailAccountId): array {
    $n = count($emailRecipients);
    $assigned = array_fill(0, $n, 0);
    if ($n === 0) return $assigned;

    $mode = strtolower(trim($mode));
    if ($mode === 'company') $mode = 'even';
    if (!in_array($mode, ['even', 'owner', 'manual'], true)) $mode = 'even';

    $mailAccountIds = array_values(array_unique(array_filter(array_map('intval', $mailAccountIds))));
    if (!$mailAccountIds && $fallbackMailAccountId > 0) $mailAccountIds = [$fallbackMailAccountId];

    // Load enabled accounts among selected (or all selected ids if empty after filter)
    $accountsById = [];
    if ($mailAccountIds) {
        $ph = implode(',', array_fill(0, count($mailAccountIds), '?'));
        $st = $pdo->prepare("SELECT id, user_id, email_address, is_default FROM crm_user_mail_accounts WHERE id IN ($ph) AND is_enabled=1 AND deleted_at IS NULL");
        $st->execute($mailAccountIds);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $a) $accountsById[(int)$a['id']] = $a;
        $mailAccountIds = array_values(array_filter($mailAccountIds, fn($id) => isset($accountsById[$id])));
    }
    if (!$mailAccountIds) return $assigned;

    if ($fallbackMailAccountId <= 0 || !isset($accountsById[$fallbackMailAccountId])) {
        $fallbackMailAccountId = $mailAccountIds[0];
    }

    if ($mode === 'even') {
        for ($i = 0; $i < $n; $i++) {
            $assigned[$i] = $mailAccountIds[$i % count($mailAccountIds)];
        }
        return $assigned;
    }

    if ($mode === 'manual') {
        // Build queue of account ids repeated by allocation count
        $queue = [];
        $norm = [];
        foreach ($allocations as $row) {
            if (!is_array($row)) continue;
            $mid = (int)($row['mail_account_id'] ?? $row['id'] ?? 0);
            $cnt = (int)($row['count'] ?? 0);
            if ($mid > 0 && $cnt > 0 && isset($accountsById[$mid])) $norm[] = [$mid, $cnt];
        }
        if (!$norm) {
            // fallback to even if allocations missing
            for ($i = 0; $i < $n; $i++) $assigned[$i] = $mailAccountIds[$i % count($mailAccountIds)];
            return $assigned;
        }
        $totalAlloc = array_sum(array_column($norm, 1));
        // If allocations don't cover all, extend last / fall back even for remainder
        $idx = 0;
        foreach ($norm as [$mid, $cnt]) {
            for ($k = 0; $k < $cnt && $idx < $n; $k++, $idx++) {
                $assigned[$idx] = $mid;
            }
        }
        while ($idx < $n) {
            $assigned[$idx] = $mailAccountIds[$idx % count($mailAccountIds)];
            $idx++;
        }
        return $assigned;
    }

    // owner: map customer owner_user_id -> that user's mailbox among selected
    $byUser = [];
    foreach ($mailAccountIds as $mid) {
        $uid = (int)$accountsById[$mid]['user_id'];
        if ($uid <= 0) continue;
        // prefer default if multiple for same user
        if (!isset($byUser[$uid]) || (int)$accountsById[$mid]['is_default'] === 1) {
            $byUser[$uid] = $mid;
        }
    }
    for ($i = 0; $i < $n; $i++) {
        $ownerId = (int)($emailRecipients[$i]['owner_user_id'] ?? 0);
        $assigned[$i] = $byUser[$ownerId] ?? $fallbackMailAccountId;
    }
    return $assigned;
}

function promo_freq_blocked(PDO $pdo, string $email, int $days): bool {
    if ($days <= 0) return false;
    $st = $pdo->prepare("SELECT COUNT(*) FROM promo_send_log WHERE email=? AND sent_at >= DATE_SUB(NOW(), INTERVAL ? DAY)");
    $st->execute([$email, $days]);
    return (int)$st->fetchColumn() > 0;
}
/** 动态人群条件 → SQL WHERE */
function promo_segment_where(PDO $pdo, array $cond): array {
    $where = ''; $params = [];
    if (!empty($cond['country'])) { $where .= " AND c.country=?"; $params[] = $cond['country']; }
    if (!empty($cond['level'])) { $where .= " AND c.level=?"; $params[] = $cond['level']; }
    if (!empty($cond['owner'])) { $where .= " AND c.owner_user_id=?"; $params[] = (int)$cond['owner']; }
    if (!empty($cond['no_follow_days'])) {
        $d = (int)$cond['no_follow_days'];
        $where .= " AND NOT EXISTS(SELECT 1 FROM crm_customer_followups f WHERE f.customer_id=c.id AND f.created_at >= DATE_SUB(NOW(), INTERVAL ? DAY))";
        $params[] = $d;
    }
    if (!empty($cond['has_email'])) { $where .= " AND c.email<>''"; }
    return [$where, $params];
}
function promo_mail_decrypt(string $value): string {
    if ($value === '') return '';
    $raw = base64_decode($value, true);
    if ($raw === false || strlen($raw) <= 16) return '';
    $iv = substr($raw, 0, 16);
    $cipher = substr($raw, 16);
    try {
        $config = function_exists('db_config') ? db_config() : [];
        $seed = (string)($config['app_key'] ?? $config['db_name'] ?? '');
        if ($seed === '') $seed = '/www/wwwroot/Artdon/artdon_erp'; // 与 crm_mail.php 的 __DIR__ 一致
        $key = hash('sha256', $seed, true);
        $plain = openssl_decrypt($cipher, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
        return is_string($plain) ? $plain : '';
    } catch (Throwable $e) {
        return '';
    }
}

function promo_send_smtp(array $account, string $password, string $to, string $subject, string $html, array $attachments = [], ?string $listUnsubUrl = null, string $plain = ''): void {
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('收件邮箱无效');
    $host = $account['smtp_host'] ?: 'smtp.exmail.qq.com';
    $port = (int)($account['smtp_port'] ?: 465);
    $ssl = ($account['smtp_secure'] ?? 'ssl') === 'ssl';
    $fp = @fsockopen(($ssl ? 'ssl://' : '') . $host, $port, $errno, $errstr, 20);
    if (!$fp) throw new RuntimeException('SMTP连接失败: ' . $errstr);
    stream_set_timeout($fp, 20);
    $read = function() use ($fp) {
        $data = '';
        while (!feof($fp)) {
            $line = fgets($fp, 515);
            if ($line === false) break;
            $data .= $line;
            if (strlen($line) >= 4 && $line[3] === ' ') break;
        }
        return $data;
    };
    $cmd = function($c, $expect = []) use ($fp, $read) {
        if ($c !== null) fwrite($fp, $c . "\r\n");
        $res = $read();
        $code = (int)substr($res, 0, 3);
        if ($expect && !in_array($code, $expect, true)) throw new RuntimeException('SMTP错误: ' . trim($res));
    };
    try {
        $cmd(null, [220]);
        $cmd('EHLO artdon-promo', [250]);
        $cmd('AUTH LOGIN', [334]);
        $cmd(base64_encode($account['email_username'] ?: $account['email_address']), [334]);
        $cmd(base64_encode($password), [235]);
        $cmd('MAIL FROM:<' . $account['email_address'] . '>', [250]);
        $cmd('RCPT TO:<' . $to . '>', [250, 251]);
        $cmd('DATA', [354]);
        $paths = [];
        foreach ($attachments as $path) {
            if (is_string($path) && is_file($path)) $paths[] = $path;
            elseif (is_string($path) && $path !== '') {
                $abs = '/www/wwwroot/Artdon/artdon_erp/' . ltrim($path, '/');
                if (is_file($abs)) $paths[] = $abs;
            }
        }
        [$hdr, $body] = promo_build_mime_message($account, $to, $subject, $html, $plain, $paths, $listUnsubUrl);
        fwrite($fp, $hdr . "\r\n" . $body . "\r\n.\r\n");
        $cmd(null, [250]);
        fwrite($fp, "QUIT\r\n");
        fclose($fp);
    } catch (Throwable $e) {
        @fclose($fp);
        throw $e;
    }
}

try {
    switch ($action) {
        case 'filter_options': {
            $countries = $pdo->query("SELECT DISTINCT country FROM crm_customers WHERE deleted_at IS NULL AND country<>'' ORDER BY country")->fetchAll(PDO::FETCH_COLUMN);
            $levels = $pdo->query("SELECT DISTINCT level FROM crm_customers WHERE deleted_at IS NULL AND level<>'' ORDER BY level")->fetchAll(PDO::FETCH_COLUMN);
            $owners = $pdo->query("SELECT DISTINCT u.id, u.real_name FROM crm_customers c LEFT JOIN crm_users u ON u.id=c.owner_user_id WHERE c.deleted_at IS NULL AND u.id IS NOT NULL ORDER BY u.real_name")->fetchAll(PDO::FETCH_ASSOC);
            $groups = $pdo->query("SELECT id, group_name FROM promo_groups WHERE deleted_at IS NULL ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
            $__rmap = promo_region_map(); $__regions = []; $__cset = array_flip(array_map('strval', (array)$countries));
            foreach ($__rmap as $__rn => $__rc) { $__regions[] = ['name' => $__rn, 'countries' => $__rc, 'matched' => array_values(array_filter($__rc, static fn($x) => isset($__cset[$x])))]; }
            api_response(true, '', ['countries' => $countries, 'levels' => $levels, 'owners' => $owners, 'groups' => $groups, 'regions' => $__regions]);
            break;
        }

        case 'customers': {
            // SPA: customers + op=save → preference batch / single save
            $op = strtolower(trim((string)($input['op'] ?? $input['operation'] ?? '')));
            if ($op === 'save' || $op === 'preference_save' || $op === 'batch_save') {
                require_permission('promotion.task_create');
                $items = (array)($input['items'] ?? []);
                if (!$items) {
                    // single
                    if (!empty($input['customer_id'])) {
                        $items = [[
                            'customer_id' => (int)$input['customer_id'],
                            'channels' => (array)($input['channels'] ?? $input['channel_keys'] ?? []),
                            'status' => $input['status'] ?? null,
                            'reason' => $input['reason'] ?? ($input['no_promotion_reason'] ?? ''),
                            'no_promotion' => $input['no_promotion'] ?? null,
                            'blacklist' => $input['blacklist'] ?? null,
                        ]];
                    } elseif (!empty($input['customer_ids'])) {
                        $channels = (array)($input['channels'] ?? $input['channel_keys'] ?? []);
                        $status = $input['status'] ?? null;
                        if (!empty($input['no_promotion'])) $status = 'no_promotion';
                        foreach ((array)$input['customer_ids'] as $cid) {
                            $items[] = [
                                'customer_id' => (int)$cid,
                                'channels' => $channels,
                                'status' => $status,
                                'reason' => $input['reason'] ?? '',
                            ];
                        }
                    }
                }
                $n = 0;
                foreach ($items as $it) {
                    if (!is_array($it)) continue;
                    $cid = (int)($it['customer_id'] ?? 0);
                    if ($cid <= 0) continue;
                    $channels = (array)($it['channels'] ?? $it['channel_keys'] ?? []);
                    if (isset($it['channel_key']) && $it['channel_key'] !== '') $channels[] = $it['channel_key'];
                    $status = isset($it['status']) ? (string)$it['status'] : null;
                    if (!empty($it['no_promotion']) || !empty($it['blacklist'])) $status = 'no_promotion';
                    promo_save_customer_prefs($pdo, $cid, $channels, $status, (string)($it['reason'] ?? ''), $userId);
                    $n++;
                }
                promo_audit($pdo, $userId, 'customers_save', 'customer', 0, "count=$n");
                api_response(true, "已更新 {$n} 个客户偏好", ['updated' => $n]);
                break;
            }
            $kw = trim($input['kw'] ?? '');
            $country = $input['country'] ?? '';
            $level = $input['level'] ?? '';
            $owner = (int)($input['owner'] ?? 0);
            $listId = (int)($input['list_id'] ?? 0);
            $groupId = (int)($input['group_id'] ?? 0);
            $page = max(1, (int)($input['page'] ?? 1));
            $pageSize = min(5000, max(10, (int)($input['page_size'] ?? 2000)));
            $where = ["c.deleted_at IS NULL"];
            $params = [];
            [$__fw, $__fp] = promo_customer_filter_where(is_array($input['filter'] ?? null) ? array_merge($input, $input['filter']) : $input, 'c');
            $where = array_merge($where, $__fw); $params = array_merge($params, $__fp);
            if ($groupId > 0) { $__gids = promo_group_resolve_ids($pdo, $groupId); $where[] = $__gids ? "c.id IN (" . implode(',', $__gids) . ")" : "1=0"; }
            if ($listId > 0) {
                $st = $pdo->prepare("SELECT customer_ids FROM promo_lists WHERE id=?");
                $st->execute([$listId]);
                $ids = json_decode($st->fetchColumn() ?: '[]', true) ?: [];
                $ids = array_map('intval', (array)$ids);
                if ($ids) { $where[] = "c.id IN (" . implode(',', $ids) . ")"; }
                else { $where[] = "1=0"; }
            }
            $ws = implode(' AND ', $where);
            $total = $pdo->prepare("SELECT COUNT(*) FROM crm_customers c WHERE $ws");
            $total->execute($params);
            $total = (int)$total->fetchColumn();
            $offset = ($page - 1) * $pageSize;
            $st = $pdo->prepare("SELECT c.id, c.customer_code, c.customer_code AS code, c.customer_name, c.customer_name AS name, c.country, c.created_at, c.email, c.level, c.owner_user_id, u.real_name AS owner_name, u.real_name AS owner FROM crm_customers c LEFT JOIN crm_users u ON u.id=c.owner_user_id WHERE $ws ORDER BY c.id DESC LIMIT $offset, $pageSize");
            $st->execute($params);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
            if ($rows) {
                $ids = array_map(static fn($r) => (int)$r['id'], $rows);
                $chMap = promo_customer_channels_all($pdo, $ids);
                foreach ($rows as &$r) {
                    $cid = (int)$r['id'];
                    $chs = $chMap[$cid] ?? [];
                    if (!$chs) $chs = ['email'];
                    $r['channel'] = $chs[0];
                    $r['channel_key'] = $chs[0];
                    $r['channels'] = $chs;
                    $r['channel_keys'] = $chs;
                    $r['company'] = $r['customer_name'];
                    $r['owner_id'] = $r['owner_user_id'] !== null ? (string)$r['owner_user_id'] : null;
                    $r['contacts'] = [[
                        'id' => $cid . '-c',
                        'name' => $r['customer_name'],
                        'email' => $r['email'],
                        'is_primary' => 1,
                        'channels' => $chs,
                        'status' => 'ok',
                        'language' => 'en',
                    ]];
                }
                unset($r);
            }
            api_response(true, '', ['total' => $total, 'page' => $page, 'rows' => $rows]);
            break;
        }

        case 'lists': {
            $st = $pdo->prepare("SELECT id, name, created_at FROM promo_lists WHERE created_by=? OR created_by=0 ORDER BY id DESC");
            $st->execute([$userId]);
            api_response(true, '', ['rows' => $st->fetchAll(PDO::FETCH_ASSOC)]);
            break;
        }

        case 'list_save': {
            require_permission('promotion.task_create');
            $name = trim($input['name'] ?? '');
            $ids = array_values(array_unique(array_map('intval', (array)($input['customer_ids'] ?? []))));
            if ($name === '' || !$ids) api_response(false, '名单名称和客户不能为空');
            $st = $pdo->prepare("INSERT INTO promo_lists(name, customer_ids, created_by) VALUES(?,?,?)");
            $st->execute([$name, json_encode($ids, JSON_UNESCAPED_UNICODE), $userId]);
            api_response(true, '名单已保存', ['id' => (int)$pdo->lastInsertId()]);
            break;
        }

        case 'list_delete': {
            require_permission('promotion.task_create');
            $pdo->prepare("DELETE FROM promo_lists WHERE id=? AND created_by=?")->execute([(int)($input['id'] ?? 0), $userId]);
            api_response(true, '已删除');
            break;
        }

        case 'templates': {
            $st = $pdo->query("SELECT id, title, subject, body_html, category, created_at, created_by, is_shared, is_favorite FROM promo_templates ORDER BY id DESC LIMIT 100");
            $raw = $st->fetchAll(PDO::FETCH_ASSOC);
            $rows = [];
            foreach ($raw as $r) {
                $cat = strtolower((string)($r['category'] ?? 'general'));
                $typeMap = [
                    'new' => 'new', '新品' => 'new', 'product' => 'new',
                    'expo' => 'expo', '展会' => 'expo', 'fair' => 'expo',
                    'news' => 'news', '动态' => 'news', 'company' => 'news',
                    'other' => 'other', 'general' => 'other',
                ];
                $type = $typeMap[$cat] ?? 'other';
                $html = (string)($r['body_html'] ?? '');
                $rows[] = [
                    'id' => (int)$r['id'],
                    'title' => $r['title'],
                    'name' => $r['title'],
                    'subject' => $r['subject'],
                    'html' => $html,
                    'body' => $html,
                    'body_html' => $html,
                    'type' => $type,
                    'category' => $r['category'],
                    'summary' => mb_substr(trim(strip_tags($html)), 0, 80),
                    'updated_at' => $r['created_at'],
                    'created_at' => $r['created_at'],
                ];
            }
            // SPA mapper looks for templates|contents|list (not rows alone)
            api_response(true, '', [
                'templates' => $rows,
                'contents' => $rows,
                'list' => $rows,
                'rows' => $rows,
            ]);
            break;
        }

        case 'template_get': {
            $st = $pdo->prepare("SELECT * FROM promo_templates WHERE id=?");
            $st->execute([(int)($input['id'] ?? 0)]);
            api_response(true, '', $st->fetch(PDO::FETCH_ASSOC) ?: []);
            break;
        }

        case 'template_save': {
            require_permission('promotion.task_create');
            $title = trim($input['title'] ?? '');
            $subject = trim($input['subject'] ?? '');
            $body = (string)($input['body_html'] ?? '');
            if ($title === '') api_response(false, '模板标题不能为空');
            $id = (int)($input['id'] ?? 0);
            if ($id > 0) {
                $pdo->prepare("UPDATE promo_templates SET title=?, subject=?, body_html=? WHERE id=?")->execute([$title, $subject, $body, $id]);
            } else {
                $pdo->prepare("INSERT INTO promo_templates(title, subject, body_html, created_by) VALUES(?,?,?,?)")->execute([$title, $subject, $body, $userId]);
                $id = (int)$pdo->lastInsertId();
            }
            api_response(true, '模板已保存', ['id' => $id]);
            break;
        }

        case 'template_delete': {
            require_permission('promotion.task_create');
            $pdo->prepare("DELETE FROM promo_templates WHERE id=?")->execute([(int)($input['id'] ?? 0)]);
            api_response(true, '已删除');
            break;
        }

        case 'mail_accounts': {
            // Managers can see all enabled mailboxes for multi-sender rules; others only own.
            $isMgr = promo_is_manager($pdo, $userId);
            if ($isMgr) {
                $st = $pdo->query("SELECT id, user_id, email_address, sender_name, is_default, signature_html FROM crm_user_mail_accounts WHERE is_enabled=1 AND deleted_at IS NULL ORDER BY is_default DESC, id ASC");
                $rows = $st->fetchAll(PDO::FETCH_ASSOC);
            } else {
                $st = $pdo->prepare("SELECT id, user_id, email_address, sender_name, is_default, signature_html FROM crm_user_mail_accounts WHERE user_id=? AND is_enabled=1 AND deleted_at IS NULL ORDER BY is_default DESC, id ASC");
                $st->execute([$userId]);
                $rows = $st->fetchAll(PDO::FETCH_ASSOC);
            }
            foreach ($rows as &$r) {
                $raw = promo_normalize_signature_html((string)($r['signature_html'] ?? ''));
                $preview = promo_render_signature_html($raw, $r);
                // SPA must receive renderable HTML (with vars substituted), not raw tags in a text box
                $r['signature_html'] = $preview;
                $r['signature_preview_html'] = $preview;
                $r['signature_plain'] = promo_signature_plain($preview);
                $r['has_signature'] = $raw !== '';
                $r['mail_account_id'] = (int)$r['id'];
                $r['email'] = $r['email_address'];
                $r['name'] = $r['sender_name'] ?: $r['email_address'];
            }
            unset($r);
            api_response(true, '', ['rows' => $rows, 'accounts' => $rows]);
            break;
        }

        case 'campaigns': {
            $isManager = promo_is_manager($pdo, $userId);
            $where = $isManager ? "1=1" : "created_by=" . (int)$userId;
            $st = $pdo->query("SELECT c.*, u.real_name AS creator_name FROM promo_campaigns c LEFT JOIN crm_users u ON u.id=c.created_by WHERE $where ORDER BY c.id DESC LIMIT 100");
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as &$c) {
                $cid = (int)$c['id'];
                $s = $pdo->prepare("SELECT status, COUNT(*) n FROM promo_recipients WHERE campaign_id=? GROUP BY status");
                $s->execute([$cid]);
                $stats = ['pending' => 0, 'sent' => 0, 'failed' => 0, 'bounced' => 0];
                foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) $stats[$r['status']] = (int)$r['n'];
                $c['stats'] = $stats;
                $t = $pdo->prepare("SELECT COUNT(*) FROM promo_offline_tasks WHERE campaign_id=?");
                $t->execute([$cid]);
                $c['offline_total'] = (int)$t->fetchColumn();
                $t = $pdo->prepare("SELECT COUNT(*) FROM promo_offline_tasks WHERE campaign_id=? AND status='done'");
                $t->execute([$cid]);
                $c['offline_done'] = (int)$t->fetchColumn();
            }
            api_response(true, '', ['rows' => $rows, 'is_manager' => $isManager]);
            break;
        }

        case 'campaign_create': {
            require_permission('promotion.task_create');
            $title = trim($input['title'] ?? '');
            $subject = trim($input['subject'] ?? '');
            $body = (string)($input['body_html'] ?? '');
            $bodyText = trim((string)($input['body_text'] ?? $input['body_plain'] ?? ''));
            if ($bodyText === '' && $body !== '') $bodyText = promo_html_to_plain($body);
            $sendMode = in_array($input['send_mode'] ?? '', ['now', 'scheduled', 'smart'], true) ? $input['send_mode'] : 'now';
            $scheduledAt = trim($input['scheduled_at'] ?? '');
            $customerIds = array_map('intval', (array)($input['customer_ids'] ?? []));
            foreach (array_filter(array_map('intval', (array)($input['group_ids'] ?? (isset($input['group_id']) ? [$input['group_id']] : [])))) as $__g) {
                $customerIds = array_merge($customerIds, promo_group_resolve_ids($pdo, $__g)); // snapshot current members (incl. dynamic rule)
            }
            $customerIds = array_values(array_unique(array_filter($customerIds)));
            $__campGroupIds = implode(',', array_unique(array_filter(array_map('intval', (array)($input['group_ids'] ?? (isset($input['group_id']) ? [$input['group_id']] : []))))));
            $mailAccountId = (int)($input['mail_account_id'] ?? $input['fallback_mail_account_id'] ?? 0);
            $fallbackMailAccountId = (int)($input['fallback_mail_account_id'] ?? $mailAccountId);
            $senderMode = strtolower(trim((string)($input['sender_mode'] ?? 'even')));
            if ($senderMode === 'company') $senderMode = 'even';
            if (!in_array($senderMode, ['even', 'owner', 'manual'], true)) $senderMode = 'even';
            $mailAccountIds = array_values(array_unique(array_filter(array_map('intval', (array)($input['mail_account_ids'] ?? [])))));
            if (!$mailAccountIds && $mailAccountId > 0) $mailAccountIds = [$mailAccountId];
            $senderAllocations = (array)($input['sender_allocations'] ?? $input['allocations'] ?? []);
            $attachments = (array)($input['attachments'] ?? []);
            $freqDays = max(0, min(90, (int)($input['frequency_days'] ?? 14)));
            $batchSize = max(10, min(500, (int)($input['batch_size'] ?? 50)));
            $needApproval = !empty($input['require_approval']) ? 1 : 0;
            if ($title === '' || $subject === '' || !$customerIds) api_response(false, '标题、主题、客户不能为空');
            if ($sendMode === 'scheduled' && $scheduledAt === '') api_response(false, '请指定发送时间');

            // 黑名单 + 退订
            $bl = $pdo->query("SELECT email FROM promo_blacklist")->fetchAll(PDO::FETCH_COLUMN);
            $un = $pdo->query("SELECT email FROM promo_unsubscribes")->fetchAll(PDO::FETCH_COLUMN);
            $blSet = array_flip(array_map('strtolower', array_merge($bl, $un)));

            $pdo->beginTransaction();
            $st = $pdo->prepare("INSERT INTO promo_campaigns(title, subject, body_html, send_mode, scheduled_at, status, created_by, batch_size, frequency_days, require_approval, approval_status) VALUES(?,?,?,?,?,'draft',?,?,?,?,?)");
            $st->execute([$title, $subject, $body, $sendMode, $sendMode === 'scheduled' ? $scheduledAt : null, $userId, $batchSize, $freqDays, $needApproval, $needApproval ? 'pending' : 'none']);
            $cid = (int)$pdo->lastInsertId();
            if ($__campGroupIds !== '') { try { promo_ensure_group_cols($pdo); $pdo->prepare("UPDATE promo_campaigns SET group_ids=? WHERE id=?")->execute([$__campGroupIds, $cid]); } catch (Throwable $e) {} }

            $emailCount = 0; $offlineCount = 0; $skipped = 0; $freqSkipped = 0;
            $seenEmail = [];
            $emailRecipients = []; // buffered for sender assignment
            $insT = $pdo->prepare("INSERT INTO promo_offline_tasks(campaign_id, customer_id, customer_name, channel, assigned_to, assigned_name, content_summary) VALUES(?,?,?,?,?,?,?)");
            foreach ($customerIds as $custId) {
                $c = $pdo->prepare("SELECT id, customer_name, email, country, owner_user_id, do_not_contact FROM crm_customers WHERE id=? AND deleted_at IS NULL");
                $c->execute([$custId]);
                $cust = $c->fetch(PDO::FETCH_ASSOC);
                if (!$cust) continue;
                // skip no_promotion / do_not_contact
                try {
                    $ps = $pdo->prepare("SELECT status FROM crm_customer_promotion_status WHERE customer_id=?");
                    $ps->execute([$custId]);
                    $pst = (string)($ps->fetchColumn() ?: '');
                    if ($pst === 'no_promotion') { $skipped++; continue; }
                } catch (Throwable $e) {}
                if (!empty($cust['do_not_contact'])) { $skipped++; continue; }
                $channel = promo_customer_channel($pdo, $custId);
                if ($channel === 'no_promotion') { $skipped++; continue; }
                $email = strtolower(trim($cust['email'] ?? ''));
                if ($channel === 'email') {
                    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || isset($blSet[$email]) || isset($seenEmail[$email])) { $skipped++; continue; }
                    $seenEmail[$email] = 1;
                    if (promo_freq_blocked($pdo, $email, $freqDays)) { $freqSkipped++; continue; }
                    $sched = null;
                    if ($sendMode === 'scheduled') $sched = $scheduledAt;
                    elseif ($sendMode === 'smart') $sched = promo_smart_send_time($cust['country'] ?? '');
                    $emailRecipients[] = [
                        'customer_id' => $custId,
                        'customer_name' => $cust['customer_name'],
                        'email' => $email,
                        'country' => $cust['country'] ?? '',
                        'owner_user_id' => (int)($cust['owner_user_id'] ?? 0),
                        'scheduled_at' => $sched,
                    ];
                    $emailCount++;
                } else {
                    $ownerId = (int)($cust['owner_user_id'] ?? 0);
                    $ownerName = '';
                    if ($ownerId > 0) {
                        $u = $pdo->prepare("SELECT real_name FROM crm_users WHERE id=?");
                        $u->execute([$ownerId]);
                        $ownerName = (string)$u->fetchColumn();
                    }
                    $insT->execute([$cid, $custId, $cust['customer_name'], $channel, $ownerId, $ownerName, mb_substr($title . '：' . $subject, 0, 200, 'UTF-8')]);
                    $offlineCount++;
                }
            }

            $assignedIds = promo_assign_sender_accounts($pdo, $emailRecipients, $senderMode, $mailAccountIds, $senderAllocations, $fallbackMailAccountId);
            $insR = $pdo->prepare("INSERT INTO promo_recipients(campaign_id, customer_id, customer_name, email, country, channel, status, scheduled_at, mail_account_id) VALUES(?,?,?,?,?,?,?,?,?)");
            foreach ($emailRecipients as $i => $er) {
                $mid = (int)($assignedIds[$i] ?? 0);
                $insR->execute([$cid, $er['customer_id'], $er['customer_name'], $er['email'], $er['country'], 'email', 'pending', $er['scheduled_at'], $mid > 0 ? $mid : null]);
            }

            // Primary mail_account_id on campaign = fallback / first selected (legacy field)
            if ($mailAccountId <= 0 && !empty($assignedIds)) {
                foreach ($assignedIds as $mid) { if ((int)$mid > 0) { $mailAccountId = (int)$mid; break; } }
            }
            $pdo->prepare("UPDATE promo_campaigns SET mail_account_id=?, attachments=?, body_plain=? WHERE id=?")->execute([$mailAccountId, json_encode(array_values($attachments), JSON_UNESCAPED_UNICODE), $bodyText !== '' ? $bodyText : null, $cid]);
            $pdo->commit();
            promo_audit($pdo, $userId, 'campaign_create', 'campaign', $cid, "mode={$senderMode} 邮件{$emailCount} 线下{$offlineCount}");
            $msg = "活动已创建：邮件 {$emailCount} 个，线下 {$offlineCount} 个（发件规则：{$senderMode}）";
            if ($skipped) $msg .= "，跳过 {$skipped} 个（无邮箱/黑名单/重复）";
            if ($freqSkipped) $msg .= "，{$freqSkipped} 个因频率控制跳过（{$freqDays}天内已发过）";
            if ($needApproval) $msg .= "，待主管审批后发送";
            api_response(true, $msg, [
                'id' => $cid,
                'need_approval' => $needApproval,
                'sender_mode' => $senderMode,
                'email_count' => $emailCount,
                'offline_count' => $offlineCount,
            ]);
            break;
        }

        case 'campaign_detail': {
            $cid = (int)($input['id'] ?? 0);
            $st = $pdo->prepare("SELECT c.*, u.real_name AS creator_name FROM promo_campaigns c LEFT JOIN crm_users u ON u.id=c.created_by WHERE c.id=?");
            $st->execute([$cid]);
            $camp = $st->fetch(PDO::FETCH_ASSOC);
            if (!$camp) api_response(false, '活动不存在');
            if (!promo_is_manager($pdo, $userId) && (int)$camp['created_by'] !== $userId) api_response(false, '无权查看');
            $st = $pdo->prepare("SELECT * FROM promo_recipients WHERE campaign_id=? ORDER BY id ASC LIMIT 500");
            $st->execute([$cid]);
            $recipients = $st->fetchAll(PDO::FETCH_ASSOC);
            $st = $pdo->prepare("SELECT t.*, u.real_name AS assignee FROM promo_offline_tasks t LEFT JOIN crm_users u ON u.id=t.assigned_to WHERE t.campaign_id=? ORDER BY t.id ASC LIMIT 500");
            $st->execute([$cid]);
            $tasks = $st->fetchAll(PDO::FETCH_ASSOC);
            // 回信统计
            $replies = 0;
            foreach ($recipients as $r) if ((int)$r['has_reply']) $replies++;
            api_response(true, '', ['campaign' => $camp, 'recipients' => $recipients, 'offline_tasks' => $tasks, 'reply_count' => $replies]);
            break;
        }

        case 'campaign_send': {
            require_permission('promotion.execute');
            $cid = (int)($input['id'] ?? $input['campaign_id'] ?? 0);
            $st = $pdo->prepare("SELECT * FROM promo_campaigns WHERE id=?");
            $st->execute([$cid]);
            $camp = $st->fetch(PDO::FETCH_ASSOC);
            if (!$camp) api_response(false, '活动不存在');
            if (!promo_is_manager($pdo, $userId) && (int)$camp['created_by'] !== $userId) api_response(false, '无权操作');
            if (!in_array($camp['status'], ['draft', 'paused'], true)) api_response(false, '当前状态不可发送：' . $camp['status']);
            // SPA: approve:1 → manager approves pending approval then sends
            $approveFlag = !empty($input['approve']) || (string)($input['approve'] ?? '') === '1';
            if ($approveFlag) {
                if (!promo_is_manager($pdo, $userId)) api_response(false, '只有主管可以用 approve 直接发送');
                if (promo_setting_get($pdo, 'require_test_send', '1') === '1' && (int)$camp['has_test_send'] !== 1) {
                    api_response(false, '请先完成试发并保留证据后再批准发送');
                }
                $pdo->prepare("UPDATE promo_approvals SET status='approved', approver_id=?, decided_at=NOW(), note=? WHERE campaign_id=? AND status='pending'")
                    ->execute([$userId, substr((string)($input['note'] ?? 'approve:1'), 0, 500), $cid]);
                $pdo->prepare("UPDATE promo_campaigns SET approval_status='approved', require_approval=1 WHERE id=?")->execute([$cid]);
                $camp['approval_status'] = 'approved';
                $camp['require_approval'] = 1;
            }
            // optional body_text update at send time
            if (isset($input['body_text']) || isset($input['body_plain'])) {
                $bt = trim((string)($input['body_text'] ?? $input['body_plain'] ?? ''));
                $pdo->prepare("UPDATE promo_campaigns SET body_plain=? WHERE id=?")->execute([$bt !== '' ? $bt : null, $cid]);
            }
            if (isset($input['body_html']) && trim((string)$input['body_html']) !== '') {
                $pdo->prepare("UPDATE promo_campaigns SET body_html=? WHERE id=?")->execute([(string)$input['body_html'], $cid]);
            }
            $reqApproval = (int)$camp['require_approval'] === 1 || promo_setting_get($pdo, 'require_approval', '1') === '1';
            if ($reqApproval && $camp['approval_status'] !== 'approved') api_response(false, '该活动需要主管审批后才能发送');
            if (promo_setting_get($pdo, 'require_test_send', '1') === '1' && (int)$camp['has_test_send'] !== 1) {
                api_response(false, '请先完成试发并保留证据后再正式发送');
            }
            // Block bulk if selected mailboxes fail DNS auth
            if (promo_setting_get($pdo, 'block_bulk_on_dns_fail', '1') === '1') {
                $mids = $pdo->prepare("SELECT DISTINCT mail_account_id FROM promo_recipients WHERE campaign_id=? AND mail_account_id>0");
                $mids->execute([$cid]);
                $bad = [];
                foreach ($mids->fetchAll(PDO::FETCH_COLUMN) as $mid) {
                    $acc = $pdo->prepare("SELECT * FROM crm_user_mail_accounts WHERE id=?");
                    $acc->execute([(int)$mid]);
                    $a = $acc->fetch(PDO::FETCH_ASSOC);
                    if (!$a) continue;
                    $dns = promo_account_dns_ok($pdo, $a);
                    if (!$dns['ok']) $bad[] = $a['email_address'] . ' (SPF/DKIM/DMARC incomplete)';
                }
                if ($bad) api_response(false, '发件域名认证未通过，已拦截群发：' . implode('; ', $bad), ['dns_failures' => $bad]);
            }
            $snap = promo_build_audience_snapshot($pdo, $cid);
            $pdo->prepare("UPDATE promo_campaigns SET status='sending', audience_snapshot=?, paused_at=NULL WHERE id=?")->execute([$snap, $cid]);
            promo_audit($pdo, $userId, 'campaign_send', 'campaign', $cid, 'snapshot stored');
            api_response(true, '已加入发送队列，系统将自动发送');
            break;
        }

        case 'campaign_cancel': {
            require_permission('promotion.execute');
            $cid = (int)($input['id'] ?? $input['campaign_id'] ?? 0);
            $asReject = !empty($input['reject']) || ($input['decision'] ?? '') === 'rejected' || ($input['as'] ?? '') === 'reject';
            $st = $pdo->prepare("SELECT * FROM promo_campaigns WHERE id=?");
            $st->execute([$cid]);
            $camp = $st->fetch(PDO::FETCH_ASSOC);
            if (!$camp) api_response(false, '活动不存在');
            if ($asReject || ($camp['approval_status'] === 'pending' && !empty($input['reject_approval']))) {
                if (!promo_is_manager($pdo, $userId)) api_response(false, '只有主管可以驳回审批');
                $pdo->prepare("UPDATE promo_approvals SET status='rejected', approver_id=?, decided_at=NOW(), note=? WHERE campaign_id=? AND status='pending'")
                    ->execute([$userId, substr((string)($input['note'] ?? 'rejected via campaign_cancel'), 0, 500), $cid]);
                $pdo->prepare("UPDATE promo_campaigns SET approval_status='rejected' WHERE id=?")->execute([$cid]);
                promo_audit($pdo, $userId, 'approval_rejected', 'campaign', $cid);
                api_response(true, '已驳回审批');
                break;
            }
            if (!promo_is_manager($pdo, $userId) && (int)$camp['created_by'] !== $userId) api_response(false, '无权操作');
            $pdo->prepare("UPDATE promo_campaigns SET status='cancelled' WHERE id=? AND status IN ('draft','sending','paused')")->execute([$cid]);
            $pdo->prepare("UPDATE promo_recipients SET status='skipped' WHERE campaign_id=? AND status='pending'")->execute([$cid]);
            promo_audit($pdo, $userId, 'campaign_cancel', 'campaign', $cid);
            api_response(true, '已取消，未发送的不再发送');
            break;
        }

        case 'campaign_pause': {
            require_permission('promotion.execute');
            $cid = (int)($input['id'] ?? $input['campaign_id'] ?? 0);
            $st = $pdo->prepare("SELECT * FROM promo_campaigns WHERE id=?");
            $st->execute([$cid]);
            $camp = $st->fetch(PDO::FETCH_ASSOC);
            if (!$camp) api_response(false, '活动不存在');
            if (!promo_is_manager($pdo, $userId) && (int)$camp['created_by'] !== $userId) api_response(false, '无权操作');
            if ($camp['status'] !== 'sending') api_response(false, '只有发送中的活动可以暂停');
            $scope = strtolower(trim((string)($input['scope'] ?? 'all')));
            $senderId = (int)($input['mail_account_id'] ?? $input['sender_id'] ?? 0);
            if ($scope === 'sender' && $senderId > 0) {
                // Pause only one sender: skip its pending recipients; keep campaign sending for others
                $pdo->prepare("UPDATE promo_recipients SET status='skipped', error_text='paused_sender' WHERE campaign_id=? AND mail_account_id=? AND status IN ('pending','sending')")
                    ->execute([$cid, $senderId]);
                // store paused sender ids in snapshot meta via settings-like JSON on audience? use audit + optional column via attachments abuse — use paused note in audit
                promo_audit($pdo, $userId, 'campaign_pause_sender', 'campaign', $cid, "mail_account_id=$senderId");
                api_response(true, "已暂停发件账号 #$senderId 的剩余发送", ['scope' => 'sender', 'mail_account_id' => $senderId]);
                break;
            }
            $pdo->prepare("UPDATE promo_campaigns SET status='paused', paused_at=NOW() WHERE id=?")->execute([$cid]);
            $pdo->prepare("UPDATE promo_recipients SET status='pending' WHERE campaign_id=? AND status='sending'")->execute([$cid]);
            promo_audit($pdo, $userId, 'campaign_pause', 'campaign', $cid);
            api_response(true, '已暂停，cron 将不再发送');
            break;
        }

        case 'campaign_resume': {
            require_permission('promotion.execute');
            $cid = (int)($input['id'] ?? 0);
            $st = $pdo->prepare("SELECT * FROM promo_campaigns WHERE id=?");
            $st->execute([$cid]);
            $camp = $st->fetch(PDO::FETCH_ASSOC);
            if (!$camp) api_response(false, '活动不存在');
            if (!promo_is_manager($pdo, $userId) && (int)$camp['created_by'] !== $userId) api_response(false, '无权操作');
            if ($camp['status'] !== 'paused') api_response(false, '只有已暂停的活动可以继续');
            if ((int)$camp['require_approval'] === 1 && $camp['approval_status'] !== 'approved') {
                api_response(false, '审批未通过，无法继续发送');
            }
            $pdo->prepare("UPDATE promo_campaigns SET status='sending', paused_at=NULL WHERE id=?")->execute([$cid]);
            promo_audit($pdo, $userId, 'campaign_resume', 'campaign', $cid);
            api_response(true, '已继续发送');
            break;
        }

        case 'campaign_stop': {
            require_permission('promotion.execute');
            $cid = (int)($input['id'] ?? 0);
            $st = $pdo->prepare("SELECT * FROM promo_campaigns WHERE id=?");
            $st->execute([$cid]);
            $camp = $st->fetch(PDO::FETCH_ASSOC);
            if (!$camp) api_response(false, '活动不存在');
            if (!promo_is_manager($pdo, $userId) && (int)$camp['created_by'] !== $userId) api_response(false, '无权操作');
            if (!in_array($camp['status'], ['sending', 'paused'], true)) api_response(false, '当前状态不可停止');
            $pdo->prepare("UPDATE promo_campaigns SET status='stopped', stopped_at=NOW() WHERE id=?")->execute([$cid]);
            $pdo->prepare("UPDATE promo_recipients SET status='skipped' WHERE campaign_id=? AND status IN ('pending','sending')")->execute([$cid]);
            promo_audit($pdo, $userId, 'campaign_stop', 'campaign', $cid);
            api_response(true, '已停止，剩余未发送全部跳过');
            break;
        }

        case 'test_send': {
            require_permission('promotion.task_create');
            $to = trim($input['to'] ?? '');
            $subject = trim($input['subject'] ?? '');
            $body = (string)($input['body_html'] ?? '');
            $mailAccountId = (int)($input['mail_account_id'] ?? 0);
            if (!filter_var($to, FILTER_VALIDATE_EMAIL)) api_response(false, '试发邮箱无效');
            $acc = $pdo->prepare("SELECT * FROM crm_user_mail_accounts WHERE id=? AND user_id=? AND is_enabled=1 AND deleted_at IS NULL");
            $acc->execute([$mailAccountId, $userId]);
            $account = $acc->fetch(PDO::FETCH_ASSOC);
            if (!$account) {
                // managers may test-send from any enabled mailbox
                if (promo_is_manager($pdo, $userId)) {
                    $acc = $pdo->prepare("SELECT * FROM crm_user_mail_accounts WHERE id=? AND is_enabled=1 AND deleted_at IS NULL");
                    $acc->execute([$mailAccountId]);
                    $account = $acc->fetch(PDO::FETCH_ASSOC);
                }
            }
            if (!$account) api_response(false, '发件邮箱不存在');
            $pwd = promo_mail_decrypt($account['email_password_encrypted'] ?? '');
            if ($pwd === '') api_response(false, '邮箱授权码无效，请到邮箱中心重新绑定');
            // 用第一个客户做变量渲染演示
            $demo = ['contact_name' => '张三', 'customer_name' => '演示公司', 'country' => '美国'];
            $html = promo_render_vars($body, $demo);
            // Prefer client override only if it looks like HTML; always render vars / normalize
            $sigOverride = trim((string)($input['signature_html'] ?? ''));
            $sigSrc = $sigOverride !== '' ? $sigOverride : (string)($account['signature_html'] ?? '');
            $html = promo_append_signature($html, $sigSrc, $account);
            // Append locked unsub footer for test as well (rid=0 placeholder uses signed 0)
            $base = promo_track_base();
            $sig = promo_track_sign(0);
            $html = promo_locked_unsub_footer($html, promo_unsub_url(0, $base, $sig));
            $plain = promo_html_to_plain($html);
            $unsubHdr = promo_unsub_url(0, $base, $sig);
            promo_send_smtp($account, $pwd, $to, $subject, $html, [], $unsubHdr, $plain);
            $cid = (int)($input['campaign_id'] ?? $input['id'] ?? 0);
            if ($cid > 0) {
                $pdo->prepare("UPDATE promo_campaigns SET has_test_send=1, test_send_at=NOW(), test_send_to=? WHERE id=?")
                    ->execute([$to, $cid]);
            }
            promo_audit($pdo, $userId, 'test_send', 'campaign', $cid, $to);
            api_response(true, '试发已发送（已附签名+退订），请查收 ' . $to, ['has_test_send' => 1, 'campaign_id' => $cid]);
            break;
        }

        case 'preview': {
            $body = (string)($input['body_html'] ?? '');
            $customerId = (int)($input['customer_id'] ?? 0);
            $mailAccountId = (int)($input['mail_account_id'] ?? 0);
            $cust = ['contact_name' => '张三', 'customer_name' => '演示公司', 'country' => '美国'];
            if ($customerId > 0) {
                $st = $pdo->prepare("SELECT customer_name, country FROM crm_customers WHERE id=?");
                $st->execute([$customerId]);
                if ($row = $st->fetch(PDO::FETCH_ASSOC)) {
                    $cust = ['contact_name' => '', 'customer_name' => $row['customer_name'], 'country' => $row['country']];
                }
            }
            $html = promo_render_vars($body, $cust);
            if ($mailAccountId > 0) {
                $acc = $pdo->prepare("SELECT * FROM crm_user_mail_accounts WHERE id=? AND is_enabled=1 AND deleted_at IS NULL");
                $acc->execute([$mailAccountId]);
                if ($account = $acc->fetch(PDO::FETCH_ASSOC)) {
                    $html = promo_append_signature($html, (string)($account['signature_html'] ?? ''), $account);
                }
            }
            api_response(true, '', ['html' => $html]);
            break;
        }

        case 'offline_tasks': {
            $isManager = promo_is_manager($pdo, $userId);
            $mine = ($input['mine'] ?? '1') === '1';
            if ($isManager && !$mine) {
                $st = $pdo->query("SELECT t.*, c.title AS campaign_title FROM promo_offline_tasks t LEFT JOIN promo_campaigns c ON c.id=t.campaign_id ORDER BY t.status, t.id DESC LIMIT 300");
                $rows = $st->fetchAll(PDO::FETCH_ASSOC);
            } else {
                $st = $pdo->prepare("SELECT t.*, c.title AS campaign_title FROM promo_offline_tasks t LEFT JOIN promo_campaigns c ON c.id=t.campaign_id WHERE t.assigned_to=? ORDER BY t.status, t.id DESC LIMIT 300");
                $st->execute([$userId]);
                $rows = $st->fetchAll(PDO::FETCH_ASSOC);
            }
            api_response(true, '', ['rows' => $rows, 'is_manager' => $isManager]);
            break;
        }

        // ===== 发送时间预览 =====
        case 'schedule_preview': {
            $cids = array_filter(array_map('intval', $input['customer_ids'] ?? []));
            $mode = $input['mode'] ?? 'smart';
            $scheduledAt = $input['scheduled_at'] ?? '';
            if (empty($cids)) api_response(true, '', []);
            $ph = implode(',', array_fill(0, count($cids), '?'));
            $st = $pdo->prepare("SELECT id, customer_name, country FROM crm_customers WHERE id IN ($ph)");
            $st->execute($cids);
            $out = [];
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $c) {
                $tz = promo_country_timezone($c['country'] ?? '');
                if ($mode === 'smart') {
                    $sendAt = promo_smart_send_time($c['country'] ?? '');
                } elseif ($mode === 'scheduled' && $scheduledAt) {
                    $sendAt = $scheduledAt;
                } else {
                    $sendAt = date('Y-m-d H:i:s');
                }
                // 换算成北京时间显示
                try {
                    $dt = new DateTime($sendAt, new DateTimeZone($tz));
                    $dt->setTimezone(new DateTimeZone('Asia/Shanghai'));
                    $bjTime = $dt->format('Y-m-d H:i');
                } catch (Throwable $e) { $bjTime = $sendAt; }
                $out[] = [
                    'id' => $c['id'],
                    'customer_name' => $c['customer_name'],
                    'country' => $c['country'],
                    'timezone' => $tz,
                    'local_time' => substr($sendAt, 0, 16),
                    'beijing_time' => $bjTime,
                ];
            }
            api_response(true, '', $out);
            break;
        }
        // ===== 分组管理（推广模块独立分组，不与客户中心共用）=====
        case 'groups': {
            promo_ensure_group_cols($pdo);
            $raw = $pdo->query("SELECT g.id, g.group_name, g.group_color, g.created_by, g.rule_json, g.is_dynamic, g.pinned, g.sort_order, (SELECT MAX(pc.created_at) FROM promo_campaigns pc WHERE FIND_IN_SET(g.id, pc.group_ids)) AS last_campaign_at FROM promo_groups g WHERE g.deleted_at IS NULL ORDER BY g.pinned DESC, g.sort_order ASC, g.id DESC")->fetchAll(PDO::FETCH_ASSOC);
            $rows = [];
            foreach ($raw as $g) {
                $__ids = promo_group_resolve_ids($pdo, (int)$g['id']);
                $members = [];
                if ($__ids) {
                    $members = $pdo->query("SELECT c.id, c.customer_code, c.customer_name, c.country, c.created_at, c.email, c.level, c.owner_user_id, u.real_name AS owner_name FROM crm_customers c LEFT JOIN crm_users u ON u.id=c.owner_user_id WHERE c.deleted_at IS NULL AND c.id IN (" . implode(',', $__ids) . ") ORDER BY c.id")->fetchAll(PDO::FETCH_ASSOC);
                }
                $g['member_count'] = count($members);
                $memberIds = [];
                $memberCustomers = [];
                foreach ($members as $c) {
                    $cid = (int)$c['id'];
                    $memberIds[] = (string)$cid;
                    $channel = promo_customer_channel($pdo, $cid);
                    $memberCustomers[] = [
                        'id' => $cid,
                        'customer_id' => $cid,
                        'customer_name' => $c['customer_name'],
                        'customer_code' => $c['customer_code'],
                        'created_at' => $c['created_at'],
                        'company' => $c['customer_name'],
                        'name' => $c['customer_name'],
                        'country' => $c['country'],
                        'country_id' => $c['country'],
                        'email' => $c['email'],
                        'level' => $c['level'],
                        'owner_user_id' => $c['owner_user_id'],
                        'owner_id' => $c['owner_user_id'] !== null ? (string)$c['owner_user_id'] : null,
                        'owner_name' => $c['owner_name'],
                        'channel' => $channel,
                        'channels' => [$channel ?: 'email'],
                        'contacts' => [[
                            'id' => $cid . '-c',
                            'name' => $c['customer_name'],
                            'email' => $c['email'],
                            'is_primary' => 1,
                            'channels' => [$channel ?: 'email'],
                            'status' => 'ok',
                            'language' => 'en',
                        ]],
                    ];
                }
                $rows[] = [
                    'id' => (int)$g['id'],
                    'group_id' => (int)$g['id'],
                    'name' => $g['group_name'],
                    'title' => $g['group_name'],
                    'group_name' => $g['group_name'],
                    'group_color' => $g['group_color'],
                    'member_count' => (int)$g['member_count'],
                    'is_dynamic' => (int)($g['is_dynamic'] ?? 0),
                    'pinned' => (int)($g['pinned'] ?? 0),
                    'sort_order' => (int)($g['sort_order'] ?? 0),
                    'last_campaign_at' => $g['last_campaign_at'] ?: null,
                    'rule' => json_decode((string)($g['rule_json'] ?? ''), true) ?: null,
                    'member_ids' => $memberIds,
                    'memberIds' => $memberIds,
                    'member_customers' => $memberCustomers,
                    'customers' => $memberIds,
                    'owner_id' => (string)($g['created_by'] ?? ''),
                    'kind' => 'public',
                    'disabled' => false,
                ];
            }
            api_response(true, '', [
                'groups' => $rows,
                'list' => $rows,
                'rows' => $rows,
            ]);
            break;
        }

        case 'group_create': {
            require_permission('promotion.task_create');
            promo_ensure_group_cols($pdo);
            $name = trim($input['group_name'] ?? $input['name'] ?? '');
            $color = $input['group_color'] ?? '#3b82f6';
            if ($name === '') api_response(false, '分组名称不能为空');
            $rule = $input['rule_json'] ?? $input['rule'] ?? null;
            if (is_array($rule)) $rule = json_encode($rule, JSON_UNESCAPED_UNICODE);
            $rule = ($rule === null || $rule === '') ? null : (string)$rule;
            $dyn = !empty($input['is_dynamic']) ? 1 : 0;
            $st = $pdo->prepare("INSERT INTO promo_groups (group_name, group_color, created_by, created_at, rule_json, is_dynamic) VALUES (?, ?, ?, NOW(), ?, ?)");
            $ok = $st->execute([$name, $color, $userId, $rule, $dyn]);
            if (!$ok) api_response(false, '创建失败，请重试');
            $gid = (int)$pdo->lastInsertId();
            $added = 0;
            // static group created from a rule: materialize members now
            if (!$dyn && $rule && !empty($input['materialize'])) {
                [$fw, $fp] = promo_customer_filter_where(json_decode($rule, true) ?: [], 'c');
                if ($fw) {
                    $ins = $pdo->prepare("INSERT IGNORE INTO promo_group_members (group_id, customer_id, created_by, created_at) SELECT ?, c.id, ?, NOW() FROM crm_customers c WHERE c.deleted_at IS NULL AND " . implode(' AND ', $fw));
                    $ins->execute(array_merge([$gid, $userId], $fp));
                    $added = $ins->rowCount();
                }
            }
            api_response(true, '分组已创建', ['id' => $gid, 'added' => $added, 'member_count' => count(promo_group_resolve_ids($pdo, $gid))]);
            break;
        }
        case 'group_update': {
            require_permission('promotion.task_create');
            promo_ensure_group_cols($pdo);
            $id = (int)($input['id'] ?? 0);
            $name = trim($input['group_name'] ?? $input['name'] ?? '');
            if ($id <= 0) api_response(false, '参数错误');
            if ($name !== '') {
                $pdo->prepare("UPDATE promo_groups SET group_name=? WHERE id=?")->execute([$name, $id]);
            } elseif (!array_key_exists('pinned', $input) && !array_key_exists('rule', $input) && !array_key_exists('rule_json', $input) && !array_key_exists('is_dynamic', $input)) {
                api_response(false, '参数错误');
            }
            if (isset($input['group_color']) && $input['group_color'] !== '') $pdo->prepare("UPDATE promo_groups SET group_color=? WHERE id=?")->execute([$input['group_color'], $id]);
            if (array_key_exists('rule_json', $input) || array_key_exists('rule', $input)) {
                $rule = $input['rule_json'] ?? $input['rule'];
                if (is_array($rule)) $rule = json_encode($rule, JSON_UNESCAPED_UNICODE);
                $pdo->prepare("UPDATE promo_groups SET rule_json=? WHERE id=?")->execute([($rule === null || $rule === '') ? null : (string)$rule, $id]);
            }
            if (array_key_exists('pinned', $input)) {
                $pdo->prepare("UPDATE promo_groups SET pinned=? WHERE id=?")->execute([!empty($input['pinned']) && $input['pinned'] !== 'false' ? 1 : 0, $id]);
            }
            if (array_key_exists('is_dynamic', $input)) {
                $pdo->prepare("UPDATE promo_groups SET is_dynamic=? WHERE id=?")->execute([!empty($input['is_dynamic']) ? 1 : 0, $id]);
            }
            api_response(true, '已更新', ['member_count' => count(promo_group_resolve_ids($pdo, $id))]);
            break;
        }
        case 'group_reorder': {
            require_permission('promotion.task_create');
            promo_ensure_group_cols($pdo);
            $ids = array_values(array_unique(array_filter(array_map('intval', (array)($input['ids'] ?? [])))));
            if (!$ids) api_response(false, '请提供 ids');
            $st = $pdo->prepare("UPDATE promo_groups SET sort_order=? WHERE id=? AND deleted_at IS NULL");
            $pdo->beginTransaction();
            foreach ($ids as $i => $gid) $st->execute([$i + 1, $gid]);
            $pdo->commit();
            api_response(true, '排序已保存', ['count' => count($ids)]);
            break;
        }
        case 'group_delete': {
            require_permission('promotion.task_create');
            $id = (int)($input['id'] ?? 0);
            if ($id <= 0) api_response(false, '参数错误');
            $pdo->prepare("UPDATE promo_groups SET deleted_at=NOW() WHERE id=?")->execute([$id]);
            api_response(true, '分组已删除');
            break;
        }
        case 'group_members': {
            $id = (int)($input['id'] ?? $input['group_id'] ?? 0);
            $ids = promo_group_resolve_ids($pdo, $id);
            $rows = [];
            if ($ids) {
                $rows = $pdo->query("SELECT c.id, c.customer_code, c.customer_code AS code, c.customer_name, c.country, c.created_at, c.email, u.real_name AS owner_name FROM crm_customers c LEFT JOIN crm_users u ON u.id=c.owner_user_id WHERE c.deleted_at IS NULL AND c.id IN (" . implode(',', $ids) . ") ORDER BY c.customer_name LIMIT 5000")->fetchAll(PDO::FETCH_ASSOC);
            }
            api_response(true, '', $rows);
            break;
        }
        case 'group_add_members': {
            require_permission('promotion.task_create');
            $id = (int)($input['id'] ?? $input['group_id'] ?? 0);
            if ($id <= 0) api_response(false, '参数错误');
            $uid = $userId;
            $cids = array_filter(array_map('intval', (array)($input['customer_ids'] ?? [])));
            $filter = $input['filter'] ?? null;
            if (is_string($filter)) $filter = json_decode($filter, true);
            $n = 0;
            if ($cids) {
                $n = promo_group_add_ids($pdo, $id, $cids, $uid);
            } elseif (is_array($filter) && $filter) {
                [$fw, $fp] = promo_customer_filter_where($filter, 'c');
                if (!$fw) api_response(false, '筛选条件不能为空');
                $q = $pdo->prepare("SELECT c.id FROM crm_customers c WHERE c.deleted_at IS NULL AND " . implode(' AND ', $fw));
                $q->execute($fp);
                $n = promo_group_add_ids($pdo, $id, $q->fetchAll(PDO::FETCH_COLUMN), $uid);
            } else {
                api_response(false, '请提供 customer_ids 或 filter');
            }
            api_response(true, "已加入 $n 个客户", ['added' => $n]);
            break;
        }
        case 'group_remove_member':
        case 'group_remove_members': {
            require_permission('promotion.task_create');
            $id = (int)($input['id'] ?? $input['group_id'] ?? 0);
            $cids = (array)($input['customer_ids'] ?? (isset($input['customer_id']) ? [$input['customer_id']] : []));
            if ($id <= 0 || !$cids) api_response(false, '参数错误');
            $n = promo_group_remove_ids($pdo, $id, $cids);
            api_response(true, "已移除 $n 个客户", ['removed' => $n, 'member_count' => count(promo_group_resolve_ids($pdo, $id))]);
            break;
        }
        case 'group_move_members':
        case 'group_copy_members': {
            require_permission('promotion.task_create');
            $from = (int)($input['from_group_id'] ?? 0);
            $to = (int)($input['to_group_id'] ?? 0);
            $cids = array_values(array_filter(array_map('intval', (array)($input['customer_ids'] ?? []))));
            if ($to <= 0 || !$cids || ($action === 'group_move_members' && ($from <= 0 || $from === $to))) api_response(false, '参数错误');
            $chk = $pdo->prepare("SELECT COUNT(*) FROM promo_groups WHERE id=? AND deleted_at IS NULL"); $chk->execute([$to]);
            if (!(int)$chk->fetchColumn()) api_response(false, '目标分组不存在');
            $pdo->beginTransaction();
            $added = promo_group_add_ids($pdo, $to, $cids, $userId);
            $removed = 0;
            if ($action === 'group_move_members') $removed = promo_group_remove_ids($pdo, $from, $cids);
            $pdo->commit();
            api_response(true, $action === 'group_move_members' ? "已移动 " . count($cids) . " 个客户" : "已复制 " . count($cids) . " 个客户", ['added' => $added, 'removed' => $removed, 'to_count' => count(promo_group_resolve_ids($pdo, $to)), 'from_count' => $from > 0 ? count(promo_group_resolve_ids($pdo, $from)) : null]);
            break;
        }
        case 'offline_done': {
            $taskId = (int)($input['id'] ?? 0);
            $note = trim($input['note'] ?? '');
            $st = $pdo->prepare("SELECT * FROM promo_offline_tasks WHERE id=?");
            $st->execute([$taskId]);
            $task = $st->fetch(PDO::FETCH_ASSOC);
            if (!$task) api_response(false, '任务不存在');
            if (!promo_is_manager($pdo, $userId) && (int)$task['assigned_to'] !== $userId) api_response(false, '无权操作');
            $shotPath = null; $shotName = null;
            // multipart file
            if (!empty($_FILES['screenshot']) && $_FILES['screenshot']['error'] === UPLOAD_ERR_OK) {
                $f = $_FILES['screenshot'];
                if ($f['size'] > 10 * 1024 * 1024) api_response(false, '截图不能超过10MB');
                $dir = __DIR__ . '/uploads/offline/' . date('Ym');
                if (!is_dir($dir)) mkdir($dir, 0755, true);
                $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
                if (!in_array($ext, ['jpg','jpeg','png','gif','webp','pdf'], true)) $ext = 'jpg';
                $name = 'off_' . $taskId . '_' . date('YmdHis') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
                if (!move_uploaded_file($f['tmp_name'], $dir . '/' . $name)) api_response(false, '截图保存失败');
                $shotPath = 'promotion/uploads/offline/' . date('Ym') . '/' . $name;
                $shotName = $f['name'];
            } elseif (!empty($input['screenshot_base64'])) {
                $raw = (string)$input['screenshot_base64'];
                if (preg_match('#^data:image/(\w+);base64,#', $raw, $m)) {
                    $ext = strtolower($m[1]) === 'jpeg' ? 'jpg' : strtolower($m[1]);
                    $raw = substr($raw, strlen($m[0]));
                } else {
                    $ext = 'png';
                }
                $bin = base64_decode($raw, true);
                if ($bin === false || strlen($bin) < 32) api_response(false, '截图数据无效');
                if (strlen($bin) > 10 * 1024 * 1024) api_response(false, '截图不能超过10MB');
                $dir = __DIR__ . '/uploads/offline/' . date('Ym');
                if (!is_dir($dir)) mkdir($dir, 0755, true);
                $name = 'off_' . $taskId . '_' . date('YmdHis') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
                if (file_put_contents($dir . '/' . $name, $bin) === false) api_response(false, '截图保存失败');
                $shotPath = 'promotion/uploads/offline/' . date('Ym') . '/' . $name;
                $shotName = trim((string)($input['screenshot_name'] ?? $name));
            }
            $pdo->prepare("UPDATE promo_offline_tasks SET status='done', done_at=NOW(), note=?, screenshot_path=COALESCE(?, screenshot_path), screenshot_name=COALESCE(?, screenshot_name) WHERE id=?")
                ->execute([$note, $shotPath, $shotName, $taskId]);
            promo_audit($pdo, $userId, 'offline_done', 'offline_task', $taskId, $shotPath ?: '');
            api_response(true, '已标记完成', ['screenshot_path' => $shotPath, 'screenshot_name' => $shotName]);
            break;
        }

        case 'blacklist': {
            $rows = $pdo->query("SELECT id, email, reason, created_at FROM promo_blacklist ORDER BY id DESC LIMIT 200")->fetchAll(PDO::FETCH_ASSOC);
            api_response(true, '', ['rows' => $rows]);
            break;
        }

        case 'blacklist_add': {
            require_permission('promotion.execute');
            $email = strtolower(trim($input['email'] ?? ''));
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) api_response(false, '邮箱无效');
            $pdo->prepare("INSERT IGNORE INTO promo_blacklist(email, reason) VALUES(?,?)")->execute([$email, trim($input['reason'] ?? '手动添加')]);
            api_response(true, '已加入黑名单');
            break;
        }

        case 'blacklist_remove': {
            require_permission('promotion.execute');
            $pdo->prepare("DELETE FROM promo_blacklist WHERE id=?")->execute([(int)($input['id'] ?? 0)]);
            api_response(true, '已移除');
            break;
        }

        case 'upload': {
            require_permission('promotion.task_create');
            if (empty($_FILES['file'])) api_response(false, '没有文件');
            $f = $_FILES['file'];
            if ($f['error'] !== UPLOAD_ERR_OK) api_response(false, '上传失败');
            if ($f['size'] > 20 * 1024 * 1024) api_response(false, '文件不能超过20MB');
            $dir = __DIR__ . '/uploads/' . date('Ym');
            if (!is_dir($dir)) mkdir($dir, 0755, true);
            $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
            $name = date('YmdHis') . '_' . bin2hex(random_bytes(6)) . ($ext ? '.' . $ext : '');
            if (!move_uploaded_file($f['tmp_name'], $dir . '/' . $name)) api_response(false, '保存失败');
            api_response(true, '上传成功', ['url' => 'promotion/uploads/' . date('Ym') . '/' . $name, 'name' => $f['name']]);
            break;
        }

        // ===== v2: 数据看板 =====
        case 'dashboard_stats': {
            $isManager = promo_is_manager($pdo, $userId);
            $uidFilter = $isManager ? '' : 'AND c.created_by=' . $userId;
            $stats = [];
            $stats['campaigns'] = (int)$pdo->query("SELECT COUNT(*) FROM promo_campaigns c WHERE 1=1 $uidFilter")->fetchColumn();
            $stats['sent'] = (int)$pdo->query("SELECT COUNT(*) FROM promo_recipients r JOIN promo_campaigns c ON c.id=r.campaign_id WHERE r.status='sent' $uidFilter")->fetchColumn();
            $stats['opened'] = (int)$pdo->query("SELECT COUNT(*) FROM promo_recipients r JOIN promo_campaigns c ON c.id=r.campaign_id WHERE r.open_count>0 $uidFilter")->fetchColumn();
            $stats['clicked'] = (int)$pdo->query("SELECT COUNT(*) FROM promo_recipients r JOIN promo_campaigns c ON c.id=r.campaign_id WHERE r.click_count>0 $uidFilter")->fetchColumn();
            $stats['replied'] = (int)$pdo->query("SELECT COUNT(*) FROM promo_recipients r JOIN promo_campaigns c ON c.id=r.campaign_id WHERE r.has_reply=1 $uidFilter")->fetchColumn();
            $stats['bounced'] = (int)$pdo->query("SELECT COUNT(*) FROM promo_recipients r JOIN promo_campaigns c ON c.id=r.campaign_id WHERE r.status IN ('bounced','failed') $uidFilter")->fetchColumn();
            $stats['unsubscribed'] = (int)$pdo->query("SELECT COUNT(*) FROM promo_unsubscribes")->fetchColumn();
            $stats['open_rate'] = $stats['sent'] ? round($stats['opened'] / $stats['sent'] * 100, 1) : 0;
            $stats['click_rate'] = $stats['sent'] ? round($stats['clicked'] / $stats['sent'] * 100, 1) : 0;
            $stats['reply_rate'] = $stats['sent'] ? round($stats['replied'] / $stats['sent'] * 100, 1) : 0;
            // 按国家 top5
            $stats['by_country'] = $pdo->query("SELECT r.country, COUNT(*) AS sent, SUM(r.open_count>0) AS opened, SUM(r.click_count>0) AS clicked FROM promo_recipients r JOIN promo_campaigns c ON c.id=r.campaign_id WHERE r.status='sent' $uidFilter GROUP BY r.country ORDER BY sent DESC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
            api_response(true, '', $stats);
            break;
        }
        case 'campaign_stats': {
            $id = (int)($input['id'] ?? 0);
            $st = $pdo->prepare("SELECT COUNT(*) AS total, SUM(status='sent') AS sent, SUM(status='failed') AS failed, SUM(status='bounced') AS bounced, SUM(status='pending') AS pending, SUM(open_count>0) AS opened, SUM(click_count>0) AS clicked, SUM(has_reply=1) AS replied FROM promo_recipients WHERE campaign_id=?");
            $st->execute([$id]);
            $s = $st->fetch(PDO::FETCH_ASSOC);
            // 打开/点击明细 top
            $s['top_opens'] = $pdo->prepare("SELECT customer_name, email, open_count, first_opened_at FROM promo_recipients WHERE campaign_id=? AND open_count>0 ORDER BY open_count DESC LIMIT 10");
            $s['top_opens']->execute([$id]); $s['top_opens'] = $s['top_opens']->fetchAll(PDO::FETCH_ASSOC);
            $s['top_clicks'] = $pdo->prepare("SELECT customer_name, email, click_count, first_clicked_at FROM promo_recipients WHERE campaign_id=? AND click_count>0 ORDER BY click_count DESC LIMIT 10");
            $s['top_clicks']->execute([$id]); $s['top_clicks'] = $s['top_clicks']->fetchAll(PDO::FETCH_ASSOC);
            api_response(true, '', $s);
            break;
        }
        case 'high_intent': {
            $isManager = promo_is_manager($pdo, $userId);
            $uidFilter = $isManager ? '' : 'AND c.created_by=' . $userId;
            $rows = $pdo->query("SELECT r.customer_name, r.email, r.country, r.open_count, r.click_count, r.first_opened_at, c.title AS campaign, u.real_name AS owner FROM promo_recipients r JOIN promo_campaigns c ON c.id=r.campaign_id LEFT JOIN crm_users u ON u.id=c.created_by WHERE (r.open_count>=2 OR r.click_count>0) $uidFilter ORDER BY r.click_count DESC, r.open_count DESC LIMIT 50")->fetchAll(PDO::FETCH_ASSOC);
            api_response(true, '', $rows);
            break;
        }

        // ===== v2: 反垃圾预检 =====
        case 'spam_check': {
            $hits = promo_spam_check($input['subject'] ?? '', $input['body_html'] ?? '');
            $score = min(100, count($hits) * 15);
            api_response(true, '', ['hits' => $hits, 'score' => $score, 'level' => $score >= 60 ? '高' : ($score >= 30 ? '中' : '低')]);
            break;
        }

        // ===== v2: 审批 =====
        case 'approval_request': {
            require_permission('promotion.task_create');
            $cid = (int)($input['campaign_id'] ?? 0);
            if ($cid <= 0) api_response(false, '参数错误');
            $st = $pdo->prepare("SELECT * FROM promo_campaigns WHERE id=?");
            $st->execute([$cid]);
            $camp = $st->fetch(PDO::FETCH_ASSOC);
            if (!$camp) api_response(false, '活动不存在');
            if (!promo_is_manager($pdo, $userId) && (int)$camp['created_by'] !== $userId) api_response(false, '无权操作');
            if ($camp['status'] !== 'draft') api_response(false, '只有草稿可以提交审批');
            if (promo_setting_get($pdo, 'require_test_send', '1') === '1' && (int)$camp['has_test_send'] !== 1) {
                api_response(false, '请先试发成功后再提交审批（需有试发证据）');
            }
            // store audience snapshot at submit time
            $snap = promo_build_audience_snapshot($pdo, $cid);
            $pdo->prepare("INSERT INTO promo_approvals (campaign_id, requested_by, note) VALUES (?, ?, ?)")
                ->execute([$cid, $userId, substr($input['note'] ?? '', 0, 500)]);
            $pdo->prepare("UPDATE promo_campaigns SET approval_status='pending', require_approval=1, audience_snapshot=? WHERE id=?")
                ->execute([$snap, $cid]);
            promo_audit($pdo, $userId, 'approval_request', 'campaign', $cid, 'with snapshot+test_send');
            api_response(true, '已提交审批', ['has_test_send' => (int)$camp['has_test_send'], 'snapshot' => true]);
            break;
        }
        case 'approval_list': {
            $status = trim((string)($input['status'] ?? 'pending'));
            if ($status === 'all') {
                $rows = $pdo->query("SELECT a.*, c.title, c.has_test_send, c.test_send_to, c.test_send_at, c.status AS campaign_status, c.approval_status, u.real_name AS requester FROM promo_approvals a JOIN promo_campaigns c ON c.id=a.campaign_id LEFT JOIN crm_users u ON u.id=a.requested_by ORDER BY a.requested_at DESC LIMIT 100")->fetchAll(PDO::FETCH_ASSOC);
            } else {
                $st = $pdo->prepare("SELECT a.*, c.title, c.has_test_send, c.test_send_to, c.test_send_at, c.status AS campaign_status, c.approval_status, u.real_name AS requester FROM promo_approvals a JOIN promo_campaigns c ON c.id=a.campaign_id LEFT JOIN crm_users u ON u.id=a.requested_by WHERE a.status=? ORDER BY a.requested_at DESC");
                $st->execute([$status]);
                $rows = $st->fetchAll(PDO::FETCH_ASSOC);
            }
            api_response(true, '', ['rows' => $rows, 'list' => $rows]);
            break;
        }
        case 'approval_decide': {
            if (!promo_is_manager($pdo, $userId)) api_response(false, '只有主管可以审批');
            $id = (int)($input['id'] ?? 0);
            $ok = ($input['decision'] ?? '') === 'approved' ? 'approved' : 'rejected';
            $st = $pdo->prepare("SELECT * FROM promo_approvals WHERE id=?");
            $st->execute([$id]);
            $ap = $st->fetch(PDO::FETCH_ASSOC);
            if (!$ap) api_response(false, '审批记录不存在');
            if ($ap['status'] !== 'pending') api_response(false, '该审批已处理');
            $cid = (int)$ap['campaign_id'];
            $camp = $pdo->prepare("SELECT * FROM promo_campaigns WHERE id=?");
            $camp->execute([$cid]);
            $camp = $camp->fetch(PDO::FETCH_ASSOC);
            if ($ok === 'approved' && promo_setting_get($pdo, 'require_test_send', '1') === '1' && $camp && (int)$camp['has_test_send'] !== 1) {
                api_response(false, '该活动尚无试发证据，不能批准');
            }
            $pdo->prepare("UPDATE promo_approvals SET status=?, approver_id=?, decided_at=NOW(), note=? WHERE id=?")
                ->execute([$ok, $userId, substr($input['note'] ?? '', 0, 500), $id]);
            $pdo->prepare("UPDATE promo_campaigns SET approval_status=? WHERE id=?")->execute([$ok, $cid]);
            $startNow = !empty($input['start_sending']) || !empty($input['send_now']);
            if ($ok === 'approved' && $startNow && $camp && $camp['status'] === 'draft') {
                if (empty($camp['audience_snapshot'])) {
                    $snap = promo_build_audience_snapshot($pdo, $cid);
                    $pdo->prepare("UPDATE promo_campaigns SET audience_snapshot=? WHERE id=?")->execute([$snap, $cid]);
                }
                $pdo->prepare("UPDATE promo_campaigns SET status='sending' WHERE id=?")->execute([$cid]);
            }
            promo_audit($pdo, $userId, 'approval_' . $ok, 'approval', $id, $startNow ? 'start_sending' : '');
            api_response(true, $ok === 'approved' ? ($startNow ? '已批准并开始发送' : '已批准，可正式发送') : '已驳回');
            break;
        }

        // ===== v2: 邮箱额度 =====
        case 'quota_status': {
            $today = date('Y-m-d');
            $rows = $pdo->query("SELECT m.id, m.email_address, m.sender_name, COALESCE(q.sent_count,0) AS sent,
                COALESCE(q.daily_limit, s.daily_limit, 200) AS lim
                FROM crm_user_mail_accounts m
                LEFT JOIN promo_mail_quotas q ON q.mail_account_id=m.id AND q.quota_date='$today'
                LEFT JOIN promo_mail_account_settings s ON s.mail_account_id=m.id
                WHERE m.is_enabled=1 AND m.deleted_at IS NULL ORDER BY m.id")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as &$r) {
                $r['remaining'] = max(0, (int)$r['lim'] - (int)$r['sent']);
            }
            unset($r);
            api_response(true, '', $rows);
            break;
        }
        case 'quota_set': {
            if (!promo_is_manager($pdo, $userId)) api_response(false, '只有主管可以设置额度');
            $mid = (int)($input['mail_account_id'] ?? 0);
            $lim = max(10, min(2000, (int)($input['daily_limit'] ?? 200)));
            if ($mid <= 0) api_response(false, 'mail_account_id 无效');
            $pdo->prepare("INSERT INTO promo_mail_quotas (mail_account_id, quota_date, daily_limit) VALUES (?, CURDATE(), ?) ON DUPLICATE KEY UPDATE daily_limit=?")
                ->execute([$mid, $lim, $lim]);
            $pdo->prepare("INSERT INTO promo_mail_account_settings (mail_account_id, daily_limit, updated_by) VALUES (?,?,?) ON DUPLICATE KEY UPDATE daily_limit=VALUES(daily_limit), updated_by=VALUES(updated_by), updated_at=NOW()")
                ->execute([$mid, $lim, $userId]);
            promo_audit($pdo, $userId, 'quota_set', 'mail_account', $mid, "daily_limit=$lim");
            api_response(true, '已设置', ['mail_account_id' => $mid, 'daily_limit' => $lim]);
            break;
        }

        case 'settings_frequency': {
            if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' || isset($input['frequency_days']) || isset($input['default_daily_limit']) || isset($input['accounts'])) {
                if (!promo_is_manager($pdo, $userId)) api_response(false, '只有主管可以修改频率设置');
                if (isset($input['frequency_days'])) {
                    $fd = max(0, min(90, (int)$input['frequency_days']));
                    promo_setting_set($pdo, 'frequency_days', (string)$fd, $userId);
                }
                if (isset($input['default_daily_limit'])) {
                    $dl = max(10, min(2000, (int)$input['default_daily_limit']));
                    promo_setting_set($pdo, 'default_daily_limit', (string)$dl, $userId);
                }
                foreach ((array)($input['accounts'] ?? []) as $row) {
                    if (!is_array($row)) continue;
                    $mid = (int)($row['mail_account_id'] ?? $row['id'] ?? 0);
                    $lim = max(10, min(2000, (int)($row['daily_limit'] ?? 200)));
                    if ($mid <= 0) continue;
                    $pdo->prepare("INSERT INTO promo_mail_account_settings (mail_account_id, daily_limit, updated_by) VALUES (?,?,?) ON DUPLICATE KEY UPDATE daily_limit=VALUES(daily_limit), updated_by=VALUES(updated_by), updated_at=NOW()")
                        ->execute([$mid, $lim, $userId]);
                    $pdo->prepare("INSERT INTO promo_mail_quotas (mail_account_id, quota_date, daily_limit) VALUES (?, CURDATE(), ?) ON DUPLICATE KEY UPDATE daily_limit=?")
                        ->execute([$mid, $lim, $lim]);
                }
                promo_audit($pdo, $userId, 'settings_frequency', 'settings', 0, json_encode($input, JSON_UNESCAPED_UNICODE));
            }
            $today = date('Y-m-d');
            $accounts = $pdo->query("SELECT m.id, m.email_address, m.sender_name,
                COALESCE(s.daily_limit, CAST((SELECT setting_value FROM promo_settings WHERE setting_key='default_daily_limit') AS UNSIGNED), 200) AS daily_limit,
                COALESCE(q.sent_count,0) AS sent_today
                FROM crm_user_mail_accounts m
                LEFT JOIN promo_mail_account_settings s ON s.mail_account_id=m.id
                LEFT JOIN promo_mail_quotas q ON q.mail_account_id=m.id AND q.quota_date='$today'
                WHERE m.is_enabled=1 AND m.deleted_at IS NULL
                ORDER BY m.id")->fetchAll(PDO::FETCH_ASSOC);
            api_response(true, '', [
                'frequency_days' => (int)promo_setting_get($pdo, 'frequency_days', '14'),
                'default_daily_limit' => (int)promo_setting_get($pdo, 'default_daily_limit', '200'),
                'accounts' => $accounts,
            ]);
            break;
        }

        case 'settings_approval': {
            if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' || isset($input['require_approval']) || isset($input['require_test_send']) || isset($input['block_bulk_on_dns_fail'])) {
                if (!promo_is_manager($pdo, $userId)) api_response(false, '只有主管可以修改审批设置');
                foreach (['require_approval','require_test_send','block_bulk_on_dns_fail'] as $k) {
                    if (isset($input[$k])) {
                        $v = !empty($input[$k]) && $input[$k] !== '0' && $input[$k] !== false ? '1' : '0';
                        promo_setting_set($pdo, $k, $v, $userId);
                    }
                }
                promo_audit($pdo, $userId, 'settings_approval', 'settings', 0);
            }
            if (isset($input['dns_block_mode']) && in_array((string)$input['dns_block_mode'], ['spf','strict'], true)) {
                if (!promo_is_manager($pdo, $userId)) api_response(false, '只有主管可以修改 DNS 拦截模式');
                promo_setting_set($pdo, 'dns_block_mode', (string)$input['dns_block_mode'], $userId);
            }
            api_response(true, '', [
                'require_approval' => promo_setting_get($pdo, 'require_approval', '1') === '1',
                'require_test_send' => promo_setting_get($pdo, 'require_test_send', '1') === '1',
                'block_bulk_on_dns_fail' => promo_setting_get($pdo, 'block_bulk_on_dns_fail', '1') === '1',
                'dns_block_mode' => promo_setting_get($pdo, 'dns_block_mode', 'spf'),
            ]);
            break;
        }

        // ===== v2: 动态人群 =====
        case 'segments': {
            $rows = $pdo->query("SELECT s.*, u.real_name AS creator FROM promo_segments s LEFT JOIN crm_users u ON u.id=s.created_by WHERE s.deleted_at IS NULL ORDER BY s.id DESC")->fetchAll(PDO::FETCH_ASSOC);
            api_response(true, '', $rows);
            break;
        }
        case 'segment_save': {
            require_permission('promotion.task_create');
            $name = trim($input['name'] ?? '');
            if ($name === '') api_response(false, '名称不能为空');
            $cond = json_encode($input['conditions'] ?? [], JSON_UNESCAPED_UNICODE);
            $id = (int)($input['id'] ?? 0);
            if ($id > 0) {
                $pdo->prepare("UPDATE promo_segments SET name=?, conditions=? WHERE id=?")->execute([$name, $cond, $id]);
            } else {
                $pdo->prepare("INSERT INTO promo_segments (name, conditions, created_by) VALUES (?, ?, ?)")->execute([$name, $cond, $userId]);
                $id = (int)$pdo->lastInsertId();
            }
            promo_audit($pdo, $userId, 'segment_save', 'segment', $id, $name);
            api_response(true, '已保存', ['id' => $id]);
            break;
        }
        case 'segment_delete': {
            require_permission('promotion.task_create');
            $pdo->prepare("UPDATE promo_segments SET deleted_at=NOW() WHERE id=?")->execute([(int)($input['id'] ?? 0)]);
            api_response(true, '已删除');
            break;
        }
        case 'segment_count': {
            $cond = $input['conditions'] ?? [];
            [$where, $params] = promo_segment_where($pdo, $cond);
            $st = $pdo->prepare("SELECT COUNT(*) FROM crm_customers c WHERE c.deleted_at IS NULL $where");
            $st->execute($params);
            api_response(true, '', ['count' => (int)$st->fetchColumn()]);
            break;
        }

        // ===== v2: 去重预览 =====
        case 'dedup_preview': {
            $cids = array_filter(array_map('intval', $input['customer_ids'] ?? []));
            if (empty($cids)) api_response(true, '', ['total' => 0, 'dup_email' => 0, 'unsub' => 0, 'freq_blocked' => 0, 'ok' => 0]);
            $freqDays = (int)($input['frequency_days'] ?? 14);
            $ph = implode(',', array_fill(0, count($cids), '?'));
            $st = $pdo->prepare("SELECT id, email FROM crm_customers WHERE id IN ($ph) AND deleted_at IS NULL");
            $st->execute($cids);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
            $seen = []; $dup = 0; $unsub = 0; $freq = 0; $ok = 0;
            $unsubs = $pdo->query("SELECT email FROM promo_unsubscribes")->fetchAll(PDO::FETCH_COLUMN);
            $unsubSet = array_flip(array_map('strtolower', $unsubs));
            $blacks = $pdo->query("SELECT email FROM promo_blacklist")->fetchAll(PDO::FETCH_COLUMN);
            $blackSet = array_flip(array_map('strtolower', $blacks));
            foreach ($rows as $r) {
                $em = strtolower(trim($r['email'] ?? ''));
                if ($em === '' || isset($seen[$em])) { $dup++; continue; }
                $seen[$em] = 1;
                if (isset($unsubSet[$em]) || isset($blackSet[$em])) { $unsub++; continue; }
                if (promo_freq_blocked($pdo, $em, $freqDays)) { $freq++; continue; }
                $ok++;
            }
            api_response(true, '', ['total' => count($rows), 'dup_email' => $dup, 'unsub' => $unsub, 'freq_blocked' => $freq, 'ok' => $ok]);
            break;
        }

        // ===== v2: 操作日志 =====
        case 'audit_log': {
            $rows = $pdo->query("SELECT a.*, u.real_name FROM promo_audit_log a LEFT JOIN crm_users u ON u.id=a.user_id ORDER BY a.id DESC LIMIT 100")->fetchAll(PDO::FETCH_ASSOC);
            api_response(true, '', $rows);
            break;
        }

        // ===== must10: 客户偏好 =====
        case 'preference_get': {
            $cid = (int)($input['customer_id'] ?? 0);
            if ($cid <= 0) api_response(false, 'customer_id 无效');
            $ch = $pdo->prepare("SELECT channel_key FROM crm_customer_promotion_channels WHERE customer_id=?");
            $ch->execute([$cid]);
            $channels = $ch->fetchAll(PDO::FETCH_COLUMN);
            $st = $pdo->prepare("SELECT status, updated_by, updated_at FROM crm_customer_promotion_status WHERE customer_id=?");
            $st->execute([$cid]);
            $status = $st->fetch(PDO::FETCH_ASSOC) ?: null;
            $cust = $pdo->prepare("SELECT id, customer_name, email, do_not_contact, no_promotion_reason FROM crm_customers WHERE id=?");
            $cust->execute([$cid]);
            $c = $cust->fetch(PDO::FETCH_ASSOC);
            $pref = null;
            try {
                $p = $pdo->prepare("SELECT * FROM crm_customer_communication_preferences WHERE customer_id=?");
                $p->execute([$cid]);
                $pref = $p->fetch(PDO::FETCH_ASSOC) ?: null;
            } catch (Throwable $e) {}
            api_response(true, '', ['customer' => $c, 'channels' => $channels, 'status' => $status, 'comm_pref' => $pref]);
            break;
        }

        case 'preference_save': {
            require_permission('promotion.task_create');
            $cid = (int)($input['customer_id'] ?? 0);
            if ($cid <= 0) api_response(false, 'customer_id 无效');
            $channels = (array)($input['channels'] ?? $input['channel_keys'] ?? []);
            if (isset($input['channel_key']) && $input['channel_key'] !== '') $channels[] = $input['channel_key'];
            $status = isset($input['status']) ? (string)$input['status'] : null;
            if (!empty($input['no_promotion']) || !empty($input['blacklist'])) $status = 'no_promotion';
            $reason = (string)($input['reason'] ?? $input['no_promotion_reason'] ?? '');
            promo_save_customer_prefs($pdo, $cid, $channels, $status, $reason, $userId);
            if (!empty($input['blacklist_email'])) {
                $em = strtolower(trim((string)$input['blacklist_email']));
                if (filter_var($em, FILTER_VALIDATE_EMAIL)) {
                    $pdo->prepare("INSERT IGNORE INTO promo_blacklist(email, reason) VALUES(?,?)")->execute([$em, $reason !== '' ? $reason : 'preference blacklist']);
                }
            }
            promo_audit($pdo, $userId, 'preference_save', 'customer', $cid, json_encode(['channels'=>$channels,'status'=>$status], JSON_UNESCAPED_UNICODE));
            api_response(true, '偏好已保存');
            break;
        }

        case 'preference_batch': {
            require_permission('promotion.task_create');
            $items = (array)($input['items'] ?? []);
            if (!$items && !empty($input['customer_ids'])) {
                $channels = (array)($input['channels'] ?? []);
                $status = isset($input['status']) ? (string)$input['status'] : null;
                if (!empty($input['no_promotion'])) $status = 'no_promotion';
                $reason = (string)($input['reason'] ?? '');
                foreach ((array)$input['customer_ids'] as $cid) {
                    $items[] = ['customer_id' => (int)$cid, 'channels' => $channels, 'status' => $status, 'reason' => $reason];
                }
            }
            $n = 0;
            foreach ($items as $it) {
                if (!is_array($it)) continue;
                $cid = (int)($it['customer_id'] ?? 0);
                if ($cid <= 0) continue;
                $channels = (array)($it['channels'] ?? $it['channel_keys'] ?? []);
                $status = isset($it['status']) ? (string)$it['status'] : null;
                if (!empty($it['no_promotion']) || !empty($it['blacklist'])) $status = 'no_promotion';
                promo_save_customer_prefs($pdo, $cid, $channels, $status, (string)($it['reason'] ?? ''), $userId);
                $n++;
            }
            promo_audit($pdo, $userId, 'preference_batch', 'customer', 0, "count=$n");
            api_response(true, "已更新 {$n} 个客户偏好", ['updated' => $n]);
            break;
        }

        case 'mark_reply': {
            require_permission('promotion.execute');
            $rid = (int)($input['recipient_id'] ?? $input['id'] ?? 0);
            if ($rid <= 0) api_response(false, 'recipient_id 无效');
            $st = $pdo->prepare("SELECT r.*, c.created_by FROM promo_recipients r JOIN promo_campaigns c ON c.id=r.campaign_id WHERE r.id=?");
            $st->execute([$rid]);
            $r = $st->fetch(PDO::FETCH_ASSOC);
            if (!$r) api_response(false, '收件人不存在');
            if (!promo_is_manager($pdo, $userId) && (int)$r['created_by'] !== $userId) api_response(false, '无权操作');
            $pdo->prepare("UPDATE promo_recipients SET has_reply=1, reply_at=COALESCE(reply_at, NOW()) WHERE id=?")->execute([$rid]);
            promo_audit($pdo, $userId, 'mark_reply', 'recipient', $rid);
            api_response(true, '已标记回信');
            break;
        }

        case 'imap_sync': {
            require_permission('promotion.execute');
            $mid = (int)($input['mail_account_id'] ?? 0);
            $accounts = [];
            if ($mid > 0) {
                $st = $pdo->prepare("SELECT * FROM crm_user_mail_accounts WHERE id=? AND is_enabled=1 AND deleted_at IS NULL");
                $st->execute([$mid]);
                if ($a = $st->fetch(PDO::FETCH_ASSOC)) $accounts[] = $a;
            } else {
                if (promo_is_manager($pdo, $userId)) {
                    $accounts = $pdo->query("SELECT * FROM crm_user_mail_accounts WHERE is_enabled=1 AND deleted_at IS NULL ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
                } else {
                    $st = $pdo->prepare("SELECT * FROM crm_user_mail_accounts WHERE user_id=? AND is_enabled=1 AND deleted_at IS NULL");
                    $st->execute([$userId]);
                    $accounts = $st->fetchAll(PDO::FETCH_ASSOC);
                }
            }
            $results = [];
            foreach ($accounts as $a) {
                $results[] = array_merge(['mail_account_id' => (int)$a['id'], 'email' => $a['email_address']], promo_imap_sync_account($pdo, $a, 'promo_mail_decrypt'));
            }
            api_response(true, '同步完成', ['results' => $results]);
            break;
        }

        case 'dns_check': {
            $mid = (int)($input['mail_account_id'] ?? 0);
            $domain = trim((string)($input['domain'] ?? ''));
            $force = !empty($input['force']);
            if ($mid > 0) {
                $st = $pdo->prepare("SELECT * FROM crm_user_mail_accounts WHERE id=?");
                $st->execute([$mid]);
                $a = $st->fetch(PDO::FETCH_ASSOC);
                if (!$a) api_response(false, '邮箱不存在');
                api_response(true, '', promo_account_dns_ok($pdo, $a, $force));
            }
            if ($domain === '') api_response(false, '请提供 domain 或 mail_account_id');
            api_response(true, '', promo_dns_check_domain($pdo, $domain, $force));
            break;
        }

        case 'campaign_submit': {
            require_permission('promotion.task_create');
            $cid = (int)($input['id'] ?? $input['campaign_id'] ?? 0);
            if ($cid <= 0) api_response(false, '参数错误');
            $st = $pdo->prepare("SELECT * FROM promo_campaigns WHERE id=?");
            $st->execute([$cid]);
            $camp = $st->fetch(PDO::FETCH_ASSOC);
            if (!$camp) api_response(false, '活动不存在');
            if (!promo_is_manager($pdo, $userId) && (int)$camp['created_by'] !== $userId) api_response(false, '无权操作');
            if ($camp['status'] !== 'draft') api_response(false, '只有草稿可以提交审批');
            if (promo_setting_get($pdo, 'require_test_send', '1') === '1' && (int)$camp['has_test_send'] !== 1) {
                api_response(false, '请先试发成功后再提交（需有试发证据）');
            }
            if (isset($input['body_text']) || isset($input['body_plain'])) {
                $bt = trim((string)($input['body_text'] ?? $input['body_plain'] ?? ''));
                $pdo->prepare("UPDATE promo_campaigns SET body_plain=? WHERE id=?")->execute([$bt !== '' ? $bt : null, $cid]);
            }
            $snap = promo_build_audience_snapshot($pdo, $cid);
            $pdo->prepare("INSERT INTO promo_approvals (campaign_id, requested_by, note) VALUES (?, ?, ?)")
                ->execute([$cid, $userId, substr((string)($input['note'] ?? ''), 0, 500)]);
            $pdo->prepare("UPDATE promo_campaigns SET approval_status='pending', require_approval=1, audience_snapshot=? WHERE id=?")
                ->execute([$snap, $cid]);
            promo_audit($pdo, $userId, 'campaign_submit', 'campaign', $cid, 'snapshot+test_send');
            api_response(true, '已提交审批', [
                'id' => $cid,
                'has_test_send' => (int)$camp['has_test_send'],
                'approval_status' => 'pending',
                'snapshot' => true,
            ]);
            break;
        }

        case 'mail_mark_reply': {
            // SPA alias → same as mark_reply
            $input['recipient_id'] = (int)($input['recipient_id'] ?? $input['id'] ?? 0);
            require_permission('promotion.execute');
            $rid = (int)$input['recipient_id'];
            if ($rid <= 0) api_response(false, 'recipient_id 无效');
            $st = $pdo->prepare("SELECT r.*, c.created_by FROM promo_recipients r JOIN promo_campaigns c ON c.id=r.campaign_id WHERE r.id=?");
            $st->execute([$rid]);
            $r = $st->fetch(PDO::FETCH_ASSOC);
            if (!$r) api_response(false, '收件人不存在');
            if (!promo_is_manager($pdo, $userId) && (int)$r['created_by'] !== $userId) api_response(false, '无权操作');
            $pdo->prepare("UPDATE promo_recipients SET has_reply=1, reply_at=COALESCE(reply_at, NOW()) WHERE id=?")->execute([$rid]);
            promo_audit($pdo, $userId, 'mail_mark_reply', 'recipient', $rid);
            api_response(true, '已标记回信', ['recipient_id' => $rid, 'has_reply' => 1]);
            break;
        }

        case 'manager_offline_stats': {
            if (!promo_is_manager($pdo, $userId)) api_response(false, '只有主管可查看');
            $cid = (int)($input['campaign_id'] ?? 0);
            $sql = "SELECT u.id AS user_id, u.real_name,
                        COUNT(t.id) AS total,
                        SUM(t.status='done') AS done,
                        SUM(t.status='pending') AS pending,
                        SUM(t.screenshot_path IS NOT NULL AND t.screenshot_path<>'') AS with_screenshot
                    FROM promo_offline_tasks t
                    LEFT JOIN crm_users u ON u.id=t.assigned_to";
            if ($cid > 0) {
                $st = $pdo->prepare($sql . " WHERE t.campaign_id=? GROUP BY u.id, u.real_name ORDER BY total DESC");
                $st->execute([$cid]);
            } else {
                $st = $pdo->query($sql . " GROUP BY u.id, u.real_name ORDER BY total DESC");
            }
            $byUser = $st->fetchAll(PDO::FETCH_ASSOC);
            foreach ($byUser as &$r) {
                $r['total'] = (int)$r['total'];
                $r['done'] = (int)$r['done'];
                $r['pending'] = (int)$r['pending'];
                $r['with_screenshot'] = (int)$r['with_screenshot'];
                $r['completion_rate'] = $r['total'] ? round($r['done'] / $r['total'] * 100, 1) : 0;
            }
            unset($r);
            $recent = $pdo->query("SELECT t.id, t.customer_name, t.channel, t.status, t.done_at, t.screenshot_path, t.screenshot_name, t.note, u.real_name AS assignee, c.title AS campaign_title
                FROM promo_offline_tasks t
                LEFT JOIN crm_users u ON u.id=t.assigned_to
                LEFT JOIN promo_campaigns c ON c.id=t.campaign_id
                WHERE t.status='done'
                ORDER BY t.done_at DESC LIMIT 50")->fetchAll(PDO::FETCH_ASSOC);
            api_response(true, '', ['by_user' => $byUser, 'recent_done' => $recent, 'campaign_id' => $cid]);
            break;
        }

        default:
            api_response(false, '未知接口: ' . $action);
    }
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    api_response(false, '系统错误: ' . $e->getMessage());
}
