<?php
/**
 * Shared helpers for promotion must-haves 1–10.
 * Safe to require from api.php / api_live.php / cron.php.
 */

if (!function_exists('promo_setting_get')) {
function promo_setting_get(PDO $pdo, string $key, string $default = ''): string {
    try {
        $st = $pdo->prepare('SELECT setting_value FROM promo_settings WHERE setting_key=? LIMIT 1');
        $st->execute([$key]);
        $v = $st->fetchColumn();
        return $v === false || $v === null ? $default : (string)$v;
    } catch (Throwable $e) {
        return $default;
    }
}
}

if (!function_exists('promo_setting_set')) {
function promo_setting_set(PDO $pdo, string $key, string $value, int $userId = 0): void {
    $pdo->prepare('INSERT INTO promo_settings (setting_key, setting_value, updated_by) VALUES (?,?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value), updated_by=VALUES(updated_by), updated_at=NOW()')
        ->execute([$key, $value, $userId]);
}
}

if (!function_exists('promo_html_to_plain')) {
function promo_html_to_plain(string $html): string {
    $html = preg_replace('/<style\b[^>]*>.*?<\/style>/is', '', $html) ?? $html;
    $html = preg_replace('/<script\b[^>]*>.*?<\/script>/is', '', $html) ?? $html;
    $html = preg_replace('/<br\s*\/?>/i', "\n", $html) ?? $html;
    $html = preg_replace('/<\/p>/i', "\n\n", $html) ?? $html;
    $html = preg_replace('/<\/div>/i', "\n", $html) ?? $html;
    $html = preg_replace('/<\/tr>/i', "\n", $html) ?? $html;
    $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = preg_replace("/[ \t]+/", ' ', $text) ?? $text;
    $text = preg_replace("/\n{3,}/", "\n\n", $text) ?? $text;
    return trim($text);
}
}

if (!function_exists('promo_unsub_url')) {
function promo_unsub_url(int $rid, string $base, string $sig): string {
    return $base . '?' . http_build_query(['t' => 'unsub', 'rid' => $rid, 'sig' => $sig]);
}
}

if (!function_exists('promo_locked_unsub_footer')) {
/** Always append locked unsubscribe footer (idempotent). */
function promo_locked_unsub_footer(string $html, string $unsubUrl): string {
    if (strpos($html, 'data-promo-unsub="1"') !== false || strpos($html, 'promo-unsub-footer') !== false) {
        return $html;
    }
    return rtrim($html)
        . '<div class="promo-unsub-footer" data-promo-unsub="1" style="margin-top:28px;padding-top:14px;border-top:1px solid #e5e7eb;font-size:12px;line-height:1.6;color:#6b7280;text-align:center">'
        . 'If you no longer wish to receive these emails, '
        . '<a href="' . htmlspecialchars($unsubUrl, ENT_QUOTES, 'UTF-8') . '" style="color:#6b7280;text-decoration:underline">unsubscribe here</a>.'
        . '<br>如不想再收到此类邮件，请<a href="' . htmlspecialchars($unsubUrl, ENT_QUOTES, 'UTF-8') . '" style="color:#6b7280;text-decoration:underline">点击退订</a>。'
        . '</div>';
}
}

if (!function_exists('promo_dig_txt')) {
function promo_dig_txt(string $name): array {
    $name = trim($name);
    if ($name === '' || !preg_match('/^[a-z0-9._*-]+$/i', $name)) return [];
    $out = [];
    $cmd = 'dig +short TXT ' . escapeshellarg($name) . ' 2>/dev/null';
    $raw = (string)shell_exec($cmd);
    foreach (preg_split('/\r?\n/', trim($raw)) as $line) {
        $line = trim($line);
        if ($line === '') continue;
        // dig returns "v=spf1 ..." possibly split quoted
        $line = trim($line, '"');
        $line = str_replace('" "', '', $line);
        $out[] = $line;
    }
    return $out;
}
}

if (!function_exists('promo_dns_check_domain')) {
/**
 * Real SPF / DKIM / DMARC DNS checks with DB cache (6h).
 * @return array{domain:string,spf:bool,dkim:bool,dmarc:bool,spf_raw:string,dkim_raw:string,dmarc_raw:string,cached:bool,error:?string}
 */
function promo_dns_check_domain(PDO $pdo, string $domain, bool $force = false): array {
    $domain = strtolower(trim($domain));
    $domain = preg_replace('/^www\./', '', $domain) ?? $domain;
    $empty = [
        'domain' => $domain, 'spf' => false, 'dkim' => false, 'dmarc' => false,
        'spf_raw' => '', 'dkim_raw' => '', 'dmarc_raw' => '', 'cached' => false, 'error' => null,
    ];
    if ($domain === '' || !preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/i', $domain)) {
        $empty['error'] = 'invalid domain';
        return $empty;
    }
    if (!$force) {
        try {
            $st = $pdo->prepare('SELECT * FROM promo_dns_auth_cache WHERE domain=? LIMIT 1');
            $st->execute([$domain]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if ($row && strtotime((string)$row['checked_at']) > time() - 6 * 3600) {
                return [
                    'domain' => $domain,
                    'spf' => (int)$row['spf_ok'] === 1,
                    'dkim' => (int)$row['dkim_ok'] === 1,
                    'dmarc' => (int)$row['dmarc_ok'] === 1,
                    'spf_raw' => (string)($row['spf_raw'] ?? ''),
                    'dkim_raw' => (string)($row['dkim_raw'] ?? ''),
                    'dmarc_raw' => (string)($row['dmarc_raw'] ?? ''),
                    'cached' => true,
                    'error' => $row['error_text'] ?: null,
                ];
            }
        } catch (Throwable $e) {}
    }

    $err = null;
    $spfRaw = '';
    $dkimRaw = '';
    $dmarcRaw = '';
    $spfOk = false;
    $dkimOk = false;
    $dmarcOk = false;
    try {
        $txts = promo_dig_txt($domain);
        foreach ($txts as $t) {
            if (stripos($t, 'v=spf1') === 0) {
                $spfOk = true;
                $spfRaw = $t;
                break;
            }
        }
        // Common selectors for Tencent / general
        $selectors = ['default', 's1', 's2', 'tencent', 'google', 'selector1', 'selector2', 'mail', 'dkim'];
        foreach ($selectors as $sel) {
            $recs = promo_dig_txt($sel . '._domainkey.' . $domain);
            foreach ($recs as $t) {
                if (stripos($t, 'v=DKIM1') !== false || stripos($t, 'p=') !== false) {
                    $dkimOk = true;
                    $dkimRaw = $sel . ': ' . $t;
                    break 2;
                }
            }
        }
        $dm = promo_dig_txt('_dmarc.' . $domain);
        foreach ($dm as $t) {
            if (stripos($t, 'v=DMARC1') === 0) {
                $dmarcOk = true;
                $dmarcRaw = $t;
                break;
            }
        }
    } catch (Throwable $e) {
        $err = $e->getMessage();
    }

    try {
        $pdo->prepare('INSERT INTO promo_dns_auth_cache (domain, spf_ok, dkim_ok, dmarc_ok, spf_raw, dkim_raw, dmarc_raw, error_text, checked_at)
            VALUES (?,?,?,?,?,?,?,?,NOW())
            ON DUPLICATE KEY UPDATE spf_ok=VALUES(spf_ok), dkim_ok=VALUES(dkim_ok), dmarc_ok=VALUES(dmarc_ok),
              spf_raw=VALUES(spf_raw), dkim_raw=VALUES(dkim_raw), dmarc_raw=VALUES(dmarc_raw),
              error_text=VALUES(error_text), checked_at=NOW()')
            ->execute([$domain, $spfOk ? 1 : 0, $dkimOk ? 1 : 0, $dmarcOk ? 1 : 0, $spfRaw, $dkimRaw, $dmarcRaw, $err]);
    } catch (Throwable $e) {}

    return [
        'domain' => $domain,
        'spf' => $spfOk,
        'dkim' => $dkimOk,
        'dmarc' => $dmarcOk,
        'spf_raw' => $spfRaw,
        'dkim_raw' => $dkimRaw,
        'dmarc_raw' => $dmarcRaw,
        'cached' => false,
        'error' => $err,
    ];
}
}

if (!function_exists('promo_mail_domain')) {
function promo_mail_domain(string $email): string {
    $parts = explode('@', strtolower(trim($email)));
    return count($parts) === 2 ? $parts[1] : '';
}
}

if (!function_exists('promo_account_dns_ok')) {
function promo_account_dns_ok(PDO $pdo, array $account, bool $force = false): array {
    $domain = promo_mail_domain((string)($account['email_address'] ?? ''));
    $check = promo_dns_check_domain($pdo, $domain, $force);
    // strict = all three; ok = SPF at minimum (used for bulk block by default)
    $check['strict_ok'] = $check['spf'] && $check['dkim'] && $check['dmarc'];
    $mode = promo_setting_get($pdo, 'dns_block_mode', 'spf'); // spf | strict
    $check['ok'] = ($mode === 'strict') ? $check['strict_ok'] : (bool)$check['spf'];
    $check['block_mode'] = $mode;
    return $check;
}
}

if (!function_exists('promo_daily_limit_for')) {
function promo_daily_limit_for(PDO $pdo, int $mailAccountId): int {
    try {
        $st = $pdo->prepare('SELECT daily_limit FROM promo_mail_account_settings WHERE mail_account_id=?');
        $st->execute([$mailAccountId]);
        $v = $st->fetchColumn();
        if ($v !== false) return max(10, min(2000, (int)$v));
    } catch (Throwable $e) {}
    return max(10, min(2000, (int)promo_setting_get($pdo, 'default_daily_limit', '200')));
}
}

if (!function_exists('promo_quota_remaining')) {
function promo_quota_remaining(PDO $pdo, int $mailAccountId): array {
    $lim = promo_daily_limit_for($pdo, $mailAccountId);
    $sent = 0;
    try {
        $st = $pdo->prepare('SELECT sent_count, daily_limit FROM promo_mail_quotas WHERE mail_account_id=? AND quota_date=CURDATE()');
        $st->execute([$mailAccountId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $sent = (int)$row['sent_count'];
            // prefer today's row limit if set, else settings
            if ((int)$row['daily_limit'] > 0) $lim = (int)$row['daily_limit'];
        }
    } catch (Throwable $e) {}
    return ['sent' => $sent, 'limit' => $lim, 'remaining' => max(0, $lim - $sent)];
}
}

if (!function_exists('promo_build_mime_message')) {
/**
 * Build MIME headers+body with multipart/alternative (+ optional mixed for attachments)
 * and List-Unsubscribe headers.
 * @return array{0:string,1:string} headers string (joined by \r\n), body string
 */
function promo_build_mime_message(
    array $account,
    string $to,
    string $subject,
    string $html,
    string $plain,
    array $attachmentPaths,
    ?string $listUnsubUrl = null
): array {
    $fromName = (string)($account['sender_name'] ?? '');
    $fromEmail = (string)$account['email_address'];
    $headers = [
        'Date: ' . date('r'),
        'From: ' . ($fromName !== '' ? '=?UTF-8?B?' . base64_encode($fromName) . '?= ' : '') . '<' . $fromEmail . '>',
        'To: <' . $to . '>',
        'Subject: ' . (preg_match('/^[\x20-\x7E]+$/', $subject) ? $subject : '=?UTF-8?B?' . base64_encode($subject) . '?='),
        'MIME-Version: 1.0',
        'X-Mailer: Artdon-Promo/2',
    ];
    if ($listUnsubUrl) {
        $headers[] = 'List-Unsubscribe: <' . $listUnsubUrl . '>';
        $headers[] = 'List-Unsubscribe-Post: List-Unsubscribe=One-Click';
    }
    if ($plain === '') $plain = promo_html_to_plain($html);

    $altBoundary = '=_alt_' . bin2hex(random_bytes(8));
    $altBody = "--{$altBoundary}\r\n"
        . "Content-Type: text/plain; charset=UTF-8\r\n"
        . "Content-Transfer-Encoding: base64\r\n\r\n"
        . chunk_split(base64_encode($plain))
        . "--{$altBoundary}\r\n"
        . "Content-Type: text/html; charset=UTF-8\r\n"
        . "Content-Transfer-Encoding: base64\r\n\r\n"
        . chunk_split(base64_encode($html))
        . "--{$altBoundary}--\r\n";

    $paths = [];
    foreach ($attachmentPaths as $p) {
        if (is_string($p) && $p !== '' && is_file($p)) $paths[] = $p;
    }

    if ($paths) {
        $mixedBoundary = '=_mix_' . bin2hex(random_bytes(8));
        $headers[] = 'Content-Type: multipart/mixed; boundary="' . $mixedBoundary . '"';
        $body = "\r\n--{$mixedBoundary}\r\n"
            . 'Content-Type: multipart/alternative; boundary="' . $altBoundary . "\"\r\n\r\n"
            . $altBody . "\r\n";
        foreach ($paths as $path) {
            $fn = basename($path);
            $enc = preg_match('/^[\x20-\x7E]+$/', $fn) ? $fn : '=?UTF-8?B?' . base64_encode($fn) . '?=';
            $body .= "--{$mixedBoundary}\r\n"
                . "Content-Type: application/octet-stream; name=\"{$enc}\"\r\n"
                . "Content-Transfer-Encoding: base64\r\n"
                . "Content-Disposition: attachment; filename=\"{$enc}\"\r\n\r\n"
                . chunk_split(base64_encode((string)file_get_contents($path))) . "\r\n";
        }
        $body .= "--{$mixedBoundary}--\r\n";
    } else {
        $headers[] = 'Content-Type: multipart/alternative; boundary="' . $altBoundary . '"';
        $body = "\r\n" . $altBody;
    }
    return [implode("\r\n", $headers), $body];
}
}

if (!function_exists('promo_imap_sync_account')) {
/**
 * Sync replies for one mailbox via IMAP. Marks promo_recipients.has_reply/reply_at
 * when From matches a recently sent recipient.
 * @return array{ok:bool,matched:int,checked:int,error:?string}
 */
function promo_imap_sync_account(PDO $pdo, array $account, callable $decrypt, int $lookbackDays = 14): array {
    if (!function_exists('imap_open')) {
        try {
            $pdo->prepare("UPDATE crm_user_mail_accounts SET sync_status='imap_ext_missing', sync_error=?, updated_at=NOW() WHERE id=?")
                ->execute(['PHP IMAP extension not loaded', (int)$account['id']]);
        } catch (Throwable $e) {}
        return ['ok' => false, 'matched' => 0, 'checked' => 0, 'error' => 'imap_ext_missing'];
    }
    $pwd = $decrypt((string)($account['email_password_encrypted'] ?? ''));
    if ($pwd === '') {
        return ['ok' => false, 'matched' => 0, 'checked' => 0, 'error' => 'password_empty'];
    }
    $host = (string)($account['imap_host'] ?: 'imap.exmail.qq.com');
    $port = (int)($account['imap_port'] ?: 993);
    $secure = strtolower((string)($account['imap_secure'] ?? 'ssl'));
    $flags = '/imap';
    if ($secure === 'ssl' || $port === 993) $flags .= '/ssl';
    if ($secure === 'tls') $flags .= '/tls';
    $flags .= '/novalidate-cert';
    $mailbox = '{' . $host . ':' . $port . $flags . '}INBOX';
    $user = (string)($account['email_username'] ?: $account['email_address']);

    $matched = 0;
    $checked = 0;
    $err = null;
    $inbox = @imap_open($mailbox, $user, $pwd, 0, 1);
    if (!$inbox) {
        $err = imap_last_error() ?: 'imap_open failed';
        try {
            $pdo->prepare("UPDATE crm_user_mail_accounts SET sync_status='error', sync_error=?, sync_fail_count=sync_fail_count+1, updated_at=NOW() WHERE id=?")
                ->execute([mb_substr($err, 0, 480), (int)$account['id']]);
        } catch (Throwable $e) {}
        return ['ok' => false, 'matched' => 0, 'checked' => 0, 'error' => $err];
    }
    try {
        $since = date('d-M-Y', time() - $lookbackDays * 86400);
        $uids = @imap_search($inbox, 'SINCE "' . $since . '"', SE_UID) ?: [];
        // Limit work
        if (count($uids) > 300) $uids = array_slice($uids, -300);
        foreach ($uids as $uid) {
            $checked++;
            $overview = @imap_fetch_overview($inbox, (string)$uid, FT_UID);
            if (!$overview || empty($overview[0])) continue;
            $from = (string)($overview[0]->from ?? '');
            if (!preg_match('/[a-z0-9._%+\-]+@[a-z0-9.\-]+\.[a-z]{2,}/i', $from, $m)) continue;
            $fromEmail = strtolower($m[0]);
            // Match against sent recipients without reply
            $st = $pdo->prepare("SELECT id FROM promo_recipients WHERE email=? AND status='sent' AND has_reply=0 AND sent_at >= DATE_SUB(NOW(), INTERVAL ? DAY) ORDER BY id DESC LIMIT 5");
            $st->execute([$fromEmail, $lookbackDays]);
            $ids = $st->fetchAll(PDO::FETCH_COLUMN);
            foreach ($ids as $rid) {
                $pdo->prepare("UPDATE promo_recipients SET has_reply=1, reply_at=NOW() WHERE id=? AND has_reply=0")->execute([(int)$rid]);
                if ($pdo) $matched++;
            }
        }
        $pdo->prepare("UPDATE crm_user_mail_accounts SET sync_status='success', sync_error=NULL, last_sync_time=NOW(), sync_fail_count=0, updated_at=NOW() WHERE id=?")
            ->execute([(int)$account['id']]);
    } catch (Throwable $e) {
        $err = $e->getMessage();
        try {
            $pdo->prepare("UPDATE crm_user_mail_accounts SET sync_status='error', sync_error=?, updated_at=NOW() WHERE id=?")
                ->execute([mb_substr($err, 0, 480), (int)$account['id']]);
        } catch (Throwable $e2) {}
    } finally {
        @imap_close($inbox);
    }
    return ['ok' => $err === null, 'matched' => $matched, 'checked' => $checked, 'error' => $err];
}
}

if (!function_exists('promo_customer_channels_all')) {
/** Return all channel_key values for a set of customer ids. */
function promo_customer_channels_all(PDO $pdo, array $ids): array {
    $map = [];
    if (!$ids) return $map;
    $ph = implode(',', array_fill(0, count($ids), '?'));
    try {
        $st = $pdo->prepare("SELECT customer_id, channel_key FROM crm_customer_promotion_channels WHERE customer_id IN ($ph)");
        $st->execute(array_values($ids));
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $cid = (int)$row['customer_id'];
            if (!isset($map[$cid])) $map[$cid] = [];
            $ck = (string)$row['channel_key'];
            if ($ck !== '' && !in_array($ck, $map[$cid], true)) $map[$cid][] = $ck;
        }
    } catch (Throwable $e) {}
    return $map;
}
}

if (!function_exists('promo_save_customer_prefs')) {
/**
 * Save channels + status/no_promotion for one customer.
 * $channels: list of channel_key; empty + no_promotion status clears.
 */
function promo_save_customer_prefs(PDO $pdo, int $customerId, array $channels, ?string $status, ?string $reason, int $userId): void {
    $allowed = ['email','whatsapp','wechat','wechat_group','whatsapp_group','linkedin','edm','ads','visit','manual_sales','automation','no_promotion'];
    $norm = [];
    foreach ($channels as $ch) {
        $ch = strtolower(trim((string)$ch));
        if (in_array($ch, $allowed, true) && !in_array($ch, $norm, true)) $norm[] = $ch;
    }
    $pdo->prepare('DELETE FROM crm_customer_promotion_channels WHERE customer_id=?')->execute([$customerId]);
    $ins = $pdo->prepare('INSERT INTO crm_customer_promotion_channels (customer_id, channel_key, created_by, created_at) VALUES (?,?,?,NOW())');
    foreach ($norm as $ch) {
        $ins->execute([$customerId, $ch, $userId]);
    }

    if ($status !== null && $status !== '') {
        $status = substr(trim($status), 0, 120);
        $pdo->prepare('INSERT INTO crm_customer_promotion_status (customer_id, status, updated_by, updated_at) VALUES (?,?,?,NOW())
            ON DUPLICATE KEY UPDATE status=VALUES(status), updated_by=VALUES(updated_by), updated_at=NOW()')
            ->execute([$customerId, $status, $userId]);
        if ($status === 'no_promotion' || in_array('no_promotion', $norm, true)) {
            try {
                $pdo->prepare('UPDATE crm_customers SET do_not_contact=1, no_promotion_reason=?, updated_at=NOW() WHERE id=?')
                    ->execute([substr((string)$reason, 0, 255), $customerId]);
            } catch (Throwable $e) {}
        } elseif ($status === 'promoting' || $status === 'promoted' || $status === 'not_promoted') {
            try {
                $pdo->prepare('UPDATE crm_customers SET do_not_contact=0, updated_at=NOW() WHERE id=?')->execute([$customerId]);
            } catch (Throwable $e) {}
        }
    }

    // Mirror preferred_channel into communication preferences when channels present
    $pref = $norm[0] ?? null;
    if ($pref && $pref !== 'no_promotion') {
        try {
            $pdo->prepare('INSERT INTO crm_customer_communication_preferences (customer_id, preferred_channel, accept_bulk, updated_by, updated_at)
                VALUES (?,?,1,?,NOW())
                ON DUPLICATE KEY UPDATE preferred_channel=VALUES(preferred_channel), updated_by=VALUES(updated_by), updated_at=NOW()')
                ->execute([$customerId, $pref, $userId]);
        } catch (Throwable $e) {}
    }
}
}

if (!function_exists('promo_build_audience_snapshot')) {
function promo_build_audience_snapshot(PDO $pdo, int $campaignId): string {
    $recs = $pdo->prepare('SELECT id, customer_id, customer_name, email, country, channel, status, mail_account_id, scheduled_at FROM promo_recipients WHERE campaign_id=? ORDER BY id');
    $recs->execute([$campaignId]);
    $recipients = $recs->fetchAll(PDO::FETCH_ASSOC);
    $off = $pdo->prepare('SELECT id, customer_id, customer_name, channel, assigned_to, assigned_name, status FROM promo_offline_tasks WHERE campaign_id=? ORDER BY id');
    $off->execute([$campaignId]);
    $offline = $off->fetchAll(PDO::FETCH_ASSOC);
    return json_encode([
        'captured_at' => date('c'),
        'email_count' => count($recipients),
        'offline_count' => count($offline),
        'recipients' => $recipients,
        'offline_tasks' => $offline,
    ], JSON_UNESCAPED_UNICODE);
}
}

/* ===== 分组筛选：代码 / 加入时间 / 国家区域 ===== */
if (!function_exists('promo_region_map')) {
function promo_region_map(): array {
    return [
        '中东' => ['阿联酋','UAE','AE','沙特阿拉伯','沙特','SA','卡塔尔','QA','阿曼','Oman','OM','科威特','KW','巴林','Bahrain','BH','以色列','IL','约旦','JO','黎巴嫩','LB','叙利亚','SY','伊朗','IR','伊拉克','IQ','土耳其','TR','也门','YE','塞浦路斯','CY','埃及','EG','巴勒斯坦','PS','中东/东南亚'],
        '印度/南亚' => ['印度','India','IN','巴基斯坦','PK','孟加拉国','孟加拉','BD','斯里兰卡','LK','尼泊尔','NP','不丹','BT','马尔代夫','MV','阿富汗','AF'],
        '东南亚' => ['印度尼西亚','印尼','ID','新加坡','SG','马来西亚','MY','菲律宾','PH','泰国','TH','越南','VN','缅甸','MM','柬埔寨','KH','老挝','LA','文莱','BN','东帝汶','TL','中东/东南亚'],
        '欧洲' => ['意大利','IT','德国','DE','西班牙','ES','英国','GB','UK','波兰','PL','荷兰','NL','葡萄牙','PT','法国','FR','罗马尼亚','RO','克罗地亚','HR','希腊','GR','乌克兰','UA','比利时','BE','奥地利','AT','丹麦','DK','挪威','NO','瑞士','CH','瑞典','SE','爱尔兰','IE','捷克共和国','捷克','CZ','保加利亚','BG','芬兰','FI','斯洛伐克','SK','斯洛文尼亚','SI','匈牙利','HU','塞尔维亚','RS','立陶宛','LT','拉脱维亚','LV','爱沙尼亚','EE','卢森堡','LU','马耳他','MT','冰岛','IS','白俄罗斯','BY','摩尔多瓦','MD','阿尔巴尼亚','AL','波黑','BA','北马其顿','MK','黑山','ME'],
        '非洲' => ['南非','ZA','摩洛哥','MA','突尼斯','TN','埃及','EG','肯尼亚','KE','尼日利亚','NG','苏丹','SD','赞比亚','ZM','毛里求斯','MU','阿尔及利亚','DZ','利比亚','LY','埃塞俄比亚','ET','加纳','GH','坦桑尼亚','TZ','乌干达','UG','安哥拉','AO','津巴布韦','ZW','塞内加尔','SN','科特迪瓦','CI','喀麦隆','CM'],
        '美洲' => ['美国','USA','US','加拿大','CA','墨西哥','MX','巴西','BR','阿根廷','AR','智利','Chile','CL','厄瓜多尔','EC','哥伦比亚','CO','秘鲁','PE','委内瑞拉','VE','乌拉圭','UY','巴拉圭','PY','玻利维亚','BO','巴拿马','PA','哥斯达黎加','CR','多米尼加','DO','古巴','CU','危地马拉','GT','牙买加','JM'],
        '东亚' => ['韩国','KR','日本','JP','蒙古','MN','朝鲜','KP','香港','HK','台湾','TW','澳门','MO'],
        '俄罗斯及中亚' => ['俄罗斯','RU','哈萨克斯坦','KZ','乌兹别克斯坦','UZ','吉尔吉斯斯坦','KG','塔吉克斯坦','TJ','土库曼斯坦','TM','亚美尼亚','AM','阿塞拜疆','AZ','格鲁吉亚','GE'],
        '大洋洲' => ['澳大利亚','澳洲','AU','新西兰','NZ','斐济','FJ','库克群岛','CK','巴布亚新几内亚','PG','萨摩亚','WS','汤加','TO'],
        '中国' => ['中国','China','CN','中国大陆','香港','HK','台湾','TW','澳门','MO'],
    ];
}
}

if (!function_exists('promo_customer_filter_where')) {
/** Build WHERE parts for crm_customers alias $a from filter array. */
function promo_customer_filter_where(array $f, string $a = 'c'): array {
    $w = []; $p = [];
    $code = trim((string)($f['code'] ?? ''));
    if ($code !== '') {
        $mode = (string)($f['code_mode'] ?? 'prefix');
        if ($mode === 'exact') { $w[] = "$a.customer_code = ?"; $p[] = $code; }
        else { $w[] = "$a.customer_code LIKE ?"; $p[] = addcslashes($code, '%_\\') . '%'; }
    }
    $cf = trim((string)($f['code_from'] ?? '')); $ct = trim((string)($f['code_to'] ?? ''));
    if ($cf !== '') { $w[] = "$a.customer_code >= ?"; $p[] = $cf; }
    if ($ct !== '') { $w[] = "$a.customer_code <= ?"; $p[] = $ct; }
    $codes = $f['codes'] ?? [];
    if (is_string($codes)) $codes = preg_split('/[\s,，;；]+/u', $codes);
    $codes = array_values(array_unique(array_filter(array_map('trim', (array)$codes), 'strlen')));
    if ($codes) { $w[] = "$a.customer_code IN (" . implode(',', array_fill(0, count($codes), '?')) . ")"; array_push($p, ...$codes); }
    $df = trim((string)($f['created_from'] ?? '')); $dt = trim((string)($f['created_to'] ?? ''));
    if ($df !== '' && strtotime($df)) { $w[] = "$a.created_at >= ?"; $p[] = date('Y-m-d 00:00:00', strtotime($df)); }
    if ($dt !== '' && strtotime($dt)) { $w[] = "$a.created_at <= ?"; $p[] = date('Y-m-d 23:59:59', strtotime($dt)); }
    $countries = $f['countries'] ?? [];
    if (is_string($countries)) $countries = preg_split('/[,，]+/u', $countries);
    $countries = array_values(array_filter(array_map('trim', (array)$countries), 'strlen'));
    $regions = $f['regions'] ?? (isset($f['region']) ? $f['region'] : []);
    if (is_string($regions)) $regions = preg_split('/[,，]+/u', $regions);
    $rmap = promo_region_map();
    foreach (array_filter(array_map('trim', (array)$regions), 'strlen') as $rg) {
        if (isset($rmap[$rg])) $countries = array_merge($countries, $rmap[$rg]);
        else $countries[] = '__no_region__';
    }
    $countries = array_values(array_unique($countries));
    if ($countries) { $w[] = "$a.country IN (" . implode(',', array_fill(0, count($countries), '?')) . ")"; array_push($p, ...$countries); }
    $country = trim((string)($f['country'] ?? ''));
    if ($country !== '') { $w[] = "$a.country = ?"; $p[] = $country; }
    $level = trim((string)($f['level'] ?? ''));
    if ($level !== '') { $w[] = "$a.level = ?"; $p[] = $level; }
    $owner = (int)($f['owner'] ?? 0);
    if ($owner > 0) { $w[] = "$a.owner_user_id = ?"; $p[] = $owner; }
    $kw = trim((string)($f['kw'] ?? $f['q'] ?? ''));
    if ($kw !== '') {
        $w[] = "($a.customer_name LIKE ? OR $a.customer_name_en LIKE ? OR $a.customer_code LIKE ? OR $a.email LIKE ? OR $a.country LIKE ?)";
        $l = '%' . $kw . '%'; array_push($p, $l, $l, $l, $l, $l);
    }
    if (!empty($f['has_email'])) { $w[] = "$a.email IS NOT NULL AND $a.email <> ''"; }
    return [$w, $p];
}
}

if (!function_exists('promo_ensure_group_cols')) {
function promo_ensure_group_cols(PDO $pdo): void {
    static $done = false; if ($done) return; $done = true;
    try {
        $cols = $pdo->query("SHOW COLUMNS FROM promo_groups")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('rule_json', $cols, true)) $pdo->exec("ALTER TABLE promo_groups ADD COLUMN rule_json TEXT NULL");
        if (!in_array('is_dynamic', $cols, true)) $pdo->exec("ALTER TABLE promo_groups ADD COLUMN is_dynamic TINYINT(1) NOT NULL DEFAULT 0");
        if (!in_array('exclude_json', $cols, true)) $pdo->exec("ALTER TABLE promo_groups ADD COLUMN exclude_json TEXT NULL");
        if (!in_array('sort_order', $cols, true)) $pdo->exec("ALTER TABLE promo_groups ADD COLUMN sort_order INT NOT NULL DEFAULT 0");
        if (!in_array('pinned', $cols, true)) $pdo->exec("ALTER TABLE promo_groups ADD COLUMN pinned TINYINT(1) NOT NULL DEFAULT 0");
        $cc = $pdo->query("SHOW COLUMNS FROM promo_campaigns")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('group_ids', $cc, true)) $pdo->exec("ALTER TABLE promo_campaigns ADD COLUMN group_ids VARCHAR(500) NULL");
    } catch (Throwable $e) {}
}
}

if (!function_exists('promo_group_resolve_ids')) {
/** Member customer ids: static members, plus rule matches for dynamic groups. */
function promo_group_resolve_ids(PDO $pdo, int $groupId): array {
    promo_ensure_group_cols($pdo);
    $st = $pdo->prepare("SELECT customer_id FROM promo_group_members WHERE group_id=?");
    $st->execute([$groupId]);
    $ids = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
    $g = $pdo->prepare("SELECT is_dynamic, rule_json, exclude_json FROM promo_groups WHERE id=? AND deleted_at IS NULL");
    $g->execute([$groupId]);
    $row = $g->fetch(PDO::FETCH_ASSOC);
    if ($row && (int)$row['is_dynamic'] === 1) {
        $rule = json_decode((string)$row['rule_json'], true);
        if (is_array($rule) && $rule) {
            [$w, $p] = promo_customer_filter_where($rule, 'c');
            if ($w) {
                $w[] = 'c.deleted_at IS NULL';
                $q = $pdo->prepare("SELECT c.id FROM crm_customers c WHERE " . implode(' AND ', $w));
                $q->execute($p);
                $ids = array_merge($ids, array_map('intval', $q->fetchAll(PDO::FETCH_COLUMN)));
            }
        }
    }
    $ids = array_values(array_unique($ids));
    $ex = $row ? array_map('intval', (array)(json_decode((string)($row['exclude_json'] ?? ''), true) ?: [])) : [];
    if ($ex) $ids = array_values(array_diff($ids, $ex));
    return $ids;
}
}

if (!function_exists('promo_group_add_ids')) {
/** Add customers (static row = manual inclusion); clears them from exclusions. */
function promo_group_add_ids(PDO $pdo, int $gid, array $ids, int $uid): int {
    promo_ensure_group_cols($pdo);
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
    if (!$ids) return 0;
    $st = $pdo->prepare("INSERT IGNORE INTO promo_group_members (group_id, customer_id, created_by, created_at) VALUES (?, ?, ?, NOW())");
    $n = 0; foreach ($ids as $c) { $st->execute([$gid, $c, $uid]); $n += $st->rowCount(); }
    $ex = $pdo->prepare("SELECT exclude_json FROM promo_groups WHERE id=?"); $ex->execute([$gid]);
    $cur = array_map('intval', (array)(json_decode((string)$ex->fetchColumn(), true) ?: []));
    if ($cur) {
        $new = array_values(array_diff($cur, $ids));
        if (count($new) !== count($cur)) $pdo->prepare("UPDATE promo_groups SET exclude_json=? WHERE id=?")->execute([$new ? json_encode($new) : null, $gid]);
    }
    return $n;
}
}
if (!function_exists('promo_group_remove_ids')) {
/** Remove customers; for dynamic groups also record exclusions so the rule won't re-add them. */
function promo_group_remove_ids(PDO $pdo, int $gid, array $ids): int {
    promo_ensure_group_cols($pdo);
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
    if (!$ids) return 0;
    $before = promo_group_resolve_ids($pdo, $gid);
    $pdo->prepare("DELETE FROM promo_group_members WHERE group_id=? AND customer_id IN (" . implode(',', $ids) . ")")->execute([$gid]);
    $g = $pdo->prepare("SELECT is_dynamic, exclude_json FROM promo_groups WHERE id=?"); $g->execute([$gid]);
    $row = $g->fetch(PDO::FETCH_ASSOC);
    if ($row && (int)$row['is_dynamic'] === 1) {
        $cur = array_map('intval', (array)(json_decode((string)$row['exclude_json'], true) ?: []));
        $new = array_values(array_unique(array_merge($cur, $ids)));
        $pdo->prepare("UPDATE promo_groups SET exclude_json=? WHERE id=?")->execute([json_encode($new), $gid]);
    }
    return count(array_intersect($before, $ids));
}
}
