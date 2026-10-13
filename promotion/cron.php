<?php
/**
 * 推广中心 - 发送引擎
 * 仅允许 CLI 执行，宝塔计划任务每分钟执行一次
 * php /www/wwwroot/Artdon/artdon_erp/promotion/cron.php
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); echo "CLI only\n"; exit(1); }

@ini_set('display_errors', '0');
@set_time_limit(120);
error_reporting(E_ALL & ~E_NOTICE & ~E_WARNING & ~E_DEPRECATED);
date_default_timezone_set('Asia/Shanghai');

$config = require __DIR__ . '/../includes/config.php';
$db = $config['db'];
$pdo = new PDO(
    sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $db['host'], (int)$db['port'], $db['name'], $db['charset']),
    $db['user'], $db['password'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);

$lockFp = @fopen('/tmp/artdon_promo_cron.lock', 'c');
if (!$lockFp || !@flock($lockFp, LOCK_EX | LOCK_NB)) { echo "another running\n"; exit(0); }

require_once __DIR__ . '/promo_lib.php';

function mail_decrypt(string $value, string $seed): string {
    if ($value === '') return '';
    $raw = base64_decode($value, true);
    if ($raw === false || strlen($raw) <= 16) return '';
    if ($seed === '') $seed = '/www/wwwroot/Artdon/artdon_erp'; // 与 crm_mail.php 的 __DIR__ 一致
    $key = hash('sha256', $seed, true);
    $plain = openssl_decrypt(substr($raw, 16), 'AES-256-CBC', $key, OPENSSL_RAW_DATA, substr($raw, 0, 16));
    return is_string($plain) ? $plain : '';
}

function send_smtp(array $account, string $password, string $to, string $subject, string $html, array $attachments, ?string $listUnsubUrl = null, string $plain = ''): void {
    $host = $account['smtp_host'] ?: 'smtp.exmail.qq.com';
    $port = (int)($account['smtp_port'] ?: 465);
    $fp = @fsockopen('ssl://' . $host, $port, $errno, $errstr, 20);
    if (!$fp) throw new RuntimeException('SMTP连接失败: ' . $errstr);
    stream_set_timeout($fp, 20);
    $read = function() use ($fp) {
        $data = '';
        while (!feof($fp)) { $line = fgets($fp, 515); if ($line === false) break; $data .= $line; if (strlen($line) >= 4 && $line[3] === ' ') break; }
        return $data;
    };
    $cmd = function($c, $expect = []) use ($fp, $read) {
        if ($c !== null) fwrite($fp, $c . "\r\n");
        $res = $read(); $code = (int)substr($res, 0, 3);
        if ($expect && !in_array($code, $expect, true)) throw new RuntimeException('SMTP错误: ' . trim($res));
    };
    try {
        $cmd(null, [220]); $cmd('EHLO artdon-promo', [250]); $cmd('AUTH LOGIN', [334]);
        $cmd(base64_encode($account['email_username'] ?: $account['email_address']), [334]);
        $cmd(base64_encode($password), [235]);
        $cmd('MAIL FROM:<' . $account['email_address'] . '>', [250]);
        $cmd('RCPT TO:<' . $to . '>', [250, 251]);
        $cmd('DATA', [354]);
        $paths = [];
        foreach ($attachments as $rel) {
            if (!is_string($rel) || $rel === '') continue;
            if (is_file($rel)) { $paths[] = $rel; continue; }
            $path = '/www/wwwroot/Artdon/artdon_erp/' . ltrim($rel, '/');
            if (is_file($path)) $paths[] = $path;
        }
        [$hdr, $body] = promo_build_mime_message($account, $to, $subject, $html, $plain, $paths, $listUnsubUrl);
        fwrite($fp, $hdr . "\r\n" . $body . "\r\n.\r\n");
        $cmd(null, [250]); fwrite($fp, "QUIT\r\n"); fclose($fp);
    } catch (Throwable $e) { @fclose($fp); throw $e; }
}

function render_vars(string $html, array $r): string {
    $vars = ['姓名' => '', 'name' => '', '公司' => $r['customer_name'] ?? '', '公司名' => $r['customer_name'] ?? '', 'company' => $r['customer_name'] ?? '', '国家' => $r['country'] ?? '', 'country' => $r['country'] ?? ''];
    foreach ($vars as $k => $v) $html = str_replace(['{{'.$k.'}}', '{'.$k.'}'], htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'), $html);
    return $html;
}

function normalize_signature_html(string $html): string {
    $html = trim($html);
    if ($html === '') return '';
    if (!mb_check_encoding($html, 'UTF-8')) {
        $html = mb_convert_encoding($html, 'UTF-8', 'UTF-8,GBK,GB2312,ISO-8859-1');
    }
    if (strpos($html, '<') === false && (strpos($html, '&lt;') !== false || strpos($html, '&#') !== false)) {
        $decoded = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if (strpos($decoded, '<') !== false) $html = $decoded;
    }
    return $html;
}

function render_signature_html(string $html, array $account = []): string {
    $html = normalize_signature_html($html);
    if ($html === '') return '';
    $mailUserName = trim((string)($account['sender_name'] ?? ''));
    $sendEmail = (string)($account['email_address'] ?? '');
    $user = [];
    if (!empty($account['user_id'])) {
        try {
            global $pdo;
            $st = $pdo->prepare('SELECT real_name, username, email, phone, position FROM crm_users WHERE id=? LIMIT 1');
            $st->execute([(int)$account['user_id']]);
            $user = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {}
    }
    if ($mailUserName === '') $mailUserName = (string)($user['real_name'] ?? ($user['username'] ?? ''));
    if ($sendEmail === '') $sendEmail = (string)($user['email'] ?? '');
    $mailUserMobile = (string)($user['phone'] ?? '');
    $mailUserPosition = trim((string)($user['position'] ?? ''));
    $vars = [
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
    return strtr($html, $vars);
}

function append_signature(string $bodyHtml, string $signatureHtml, array $account = []): string {
    $sig = render_signature_html($signatureHtml, $account);
    if ($sig === '') return $bodyHtml;
    if (preg_match('/class=["\'][^"\']*(?:promo-mail-signature|mail-signature)[^"\']*["\']/', $bodyHtml)) {
        return $bodyHtml;
    }
    $needle = preg_replace('/\s+/', '', mb_substr(strip_tags($sig), 0, 80, 'UTF-8'));
    if ($needle !== '' && strpos(preg_replace('/\s+/', '', strip_tags($bodyHtml)), $needle) !== false) {
        return $bodyHtml;
    }
    return rtrim($bodyHtml) . '<div style="margin-top:24px;padding-top:12px;border-top:1px solid #e5e7eb" class="promo-mail-signature">' . $sig . '</div>';
}


function is_hard_bounce(string $err): bool {
    $s = strtolower($err);
    return (bool)preg_match('/user unknown|mailbox unavailable|no such user|recipient address rejected|invalid recipient|mailbox not found/', $s);
}

function build_tracked_html(string $html, int $rid, array $camp, string $secret): string {
    $sig = hash_hmac('sha256', (string)$rid, $secret);
    // 追踪域名写死为当前站点（与 track.php 同目录）
    $base = 'https://novlight.com/artdon_erp/promotion/track.php';
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
    if (!empty($camp['track_opens'])) {
        $px = $base . '?' . http_build_query(['t' => 'open', 'rid' => $rid, 'sig' => $sig]);
        $html .= '<img src="' . htmlspecialchars($px) . '" width="1" height="1" style="display:none" alt="">';
    }
    $unsub = $base . '?' . http_build_query(['t' => 'unsub', 'rid' => $rid, 'sig' => $sig]);
    $html = promo_locked_unsub_footer($html, $unsub);
    return $html;
}

function build_tracked_unsub_url(int $rid, string $secret): string {
    $sig = hash_hmac('sha256', (string)$rid, $secret);
    $base = 'https://novlight.com/artdon_erp/promotion/track.php';
    return $base . '?' . http_build_query(['t' => 'unsub', 'rid' => $rid, 'sig' => $sig]);
}

try {
    $seed = (string)($config['app_key'] ?? $config['db_name'] ?? '');
    if ($seed === '') $seed = '/www/wwwroot/Artdon/artdon_erp';
    $trackSecret = hash('sha256', 'artdon_promo_track_' . __DIR__);
    // 审批中的活动不发送
    $campaigns = $pdo->query("SELECT * FROM promo_campaigns WHERE status='sending' AND approval_status IN ('none','approved') ORDER BY id ASC LIMIT 10")->fetchAll();
    $totalSent = 0; $totalFail = 0;
    foreach ($campaigns as $camp) {
        $cid = (int)$camp['id'];
        // Re-read status so pause/stop mid-run is respected
        $stLive = $pdo->prepare("SELECT status, approval_status FROM promo_campaigns WHERE id=?");
        $stLive->execute([$cid]);
        $live = $stLive->fetch(PDO::FETCH_ASSOC);
        if (!$live || $live['status'] !== 'sending' || !in_array($live['approval_status'], ['none','approved'], true)) {
            echo "campaign $cid skipped (status={$live['status']} approval={$live['approval_status']})\n";
            continue;
        }
        $batchSize = max(10, min(500, (int)($camp['batch_size'] ?? 50)));

        // 发件账号池：活动默认账号 + 创建人账号；实际发送优先用 recipient.mail_account_id
        $pool = [];
        $poolById = [];
        if ((int)$camp['mail_account_id'] > 0) {
            $acc = $pdo->prepare("SELECT * FROM crm_user_mail_accounts WHERE id=? AND is_enabled=1 AND deleted_at IS NULL");
            $acc->execute([(int)$camp['mail_account_id']]);
            if ($a = $acc->fetch()) { $pool[] = $a; $poolById[(int)$a['id']] = $a; }
        }
        $acc = $pdo->prepare("SELECT * FROM crm_user_mail_accounts WHERE user_id=? AND is_enabled=1 AND deleted_at IS NULL ORDER BY is_default DESC");
        $acc->execute([(int)$camp['created_by']]);
        foreach ($acc->fetchAll() as $a) {
            $aid = (int)$a['id'];
            if (!isset($poolById[$aid])) { $pool[] = $a; $poolById[$aid] = $a; }
        }
        // Also preload any recipient-assigned accounts for this batch
        $stPre = $pdo->prepare("SELECT DISTINCT mail_account_id FROM promo_recipients WHERE campaign_id=? AND status='pending' AND mail_account_id IS NOT NULL AND mail_account_id>0");
        $stPre->execute([$cid]);
        foreach ($stPre->fetchAll(PDO::FETCH_COLUMN) as $mid) {
            $mid = (int)$mid;
            if ($mid <= 0 || isset($poolById[$mid])) continue;
            $acc = $pdo->prepare("SELECT * FROM crm_user_mail_accounts WHERE id=? AND is_enabled=1 AND deleted_at IS NULL");
            $acc->execute([$mid]);
            if ($a = $acc->fetch()) { $pool[] = $a; $poolById[$mid] = $a; }
        }
        if (!$pool) { echo "campaign $cid: no mail account\n"; continue; }
        // 过滤掉今日额度用完的账号
        $today = date('Y-m-d');
        $avail = [];
        $availById = [];
        foreach ($pool as $a) {
            $q = $pdo->prepare("SELECT sent_count, daily_limit FROM promo_mail_quotas WHERE mail_account_id=? AND quota_date=?");
            $q->execute([$a['id'], $today]);
            $qr = $q->fetch();
            $lim = promo_daily_limit_for($pdo, (int)$a['id']);
            if ($qr && (int)$qr['daily_limit'] > 0) $lim = (int)$qr['daily_limit'];
            $sent = $qr ? (int)$qr['sent_count'] : 0;
            if ($sent < $lim) { $avail[] = $a; $availById[(int)$a['id']] = $a; }
        }
        if (!$avail) { echo "campaign $cid: all accounts over daily quota — defer\n"; continue; }

        $attachments = json_decode($camp['attachments'] ?? '[]', true) ?: [];
        $urls = [];
        foreach ((array)$attachments as $a) if (!empty($a['url'])) $urls[] = $a['url'];

        $st = $pdo->prepare("SELECT * FROM promo_recipients WHERE campaign_id=? AND status='pending' AND (scheduled_at IS NULL OR scheduled_at<=NOW()) ORDER BY id ASC LIMIT $batchSize");
        $st->execute([$cid]);
        $recipients = $st->fetchAll();
        $rotIdx = 0;
        foreach ($recipients as $r) {
            $rid = (int)$r['id'];
            $claim = $pdo->prepare("UPDATE promo_recipients SET status='sending' WHERE id=? AND status='pending'");
            $claim->execute([$rid]);
            if ($claim->rowCount() === 0) continue;
            // Prefer per-recipient mail_account_id; else rotate avail pool
            $prefId = (int)($r['mail_account_id'] ?? 0);
            if ($prefId > 0 && isset($availById[$prefId])) {
                $account = $availById[$prefId];
            } elseif ($prefId > 0 && isset($poolById[$prefId])) {
                // assigned account over quota — skip this recipient for now
                $pdo->prepare("UPDATE promo_recipients SET status='pending' WHERE id=?")->execute([$rid]);
                echo "campaign $cid rid $rid: account $prefId over quota, defer\n";
                continue;
            } else {
                $account = $avail[$rotIdx % count($avail)];
                $rotIdx++;
            }
            $pwd = mail_decrypt($account['email_password_encrypted'] ?? '', $seed);
            if ($pwd === '') {
                $pdo->prepare("UPDATE promo_recipients SET status='pending' WHERE id=?")->execute([$rid]);
                continue;
            }
            try {
                $html = render_vars($camp['body_html'], $r);
                $html = append_signature($html, (string)($account['signature_html'] ?? ''), $account);
                // 追踪：链接重写 + 打开像素 + 锁定退订页脚
                $html = build_tracked_html($html, $rid, $camp, $trackSecret);
                $plainSrc = (string)($camp['body_plain'] ?? '');
                $plain = $plainSrc !== '' ? render_vars($plainSrc, $r) : promo_html_to_plain($html);
                $unsubUrl = build_tracked_unsub_url($rid, $trackSecret);
                send_smtp($account, $pwd, $r['email'], $camp['subject'], $html, $urls, $unsubUrl, $plain);
                $pdo->prepare("UPDATE promo_recipients SET status='sent', sent_at=NOW(), mail_account_id=? WHERE id=?")->execute([$account['id'], $rid]);
                // 额度+1
                $pdo->prepare("INSERT INTO promo_mail_quotas (mail_account_id, quota_date, sent_count) VALUES (?, CURDATE(), 1) ON DUPLICATE KEY UPDATE sent_count=sent_count+1")->execute([$account['id']]);
                // 发送日志（频率控制用）
                $pdo->prepare("INSERT INTO promo_send_log (customer_id, email, campaign_id) VALUES (?, ?, ?)")->execute([$r['customer_id'], strtolower($r['email']), $cid]);
                $totalSent++;
            } catch (Throwable $e) {
                $err = mb_substr($e->getMessage(), 0, 400, 'UTF-8');
                if (is_hard_bounce($err)) {
                    $pdo->prepare("UPDATE promo_recipients SET status='bounced', error_text=? WHERE id=?")->execute([$err, $rid]);
                    $pdo->prepare("INSERT IGNORE INTO promo_blacklist(email, reason) VALUES(?,?)")->execute([strtolower($r['email']), '硬退信自动加入']);
                } else {
                    $pdo->prepare("UPDATE promo_recipients SET status='failed', error_text=? WHERE id=?")->execute([$err, $rid]);
                }
                $totalFail++;
            }
            sleep(2);
        }
        $left = $pdo->prepare("SELECT COUNT(*) FROM promo_recipients WHERE campaign_id=? AND status IN ('pending','sending')");
        $left->execute([$cid]);
        if ((int)$left->fetchColumn() === 0) {
            $pdo->prepare("UPDATE promo_campaigns SET status='done' WHERE id=?")->execute([$cid]);
            echo "campaign $cid done\n";
        }
    }
    // Light IMAP reply sync (one account per cron run to stay under time limit)
    try {
        $acc = $pdo->query("SELECT * FROM crm_user_mail_accounts WHERE is_enabled=1 AND deleted_at IS NULL AND (last_sync_time IS NULL OR last_sync_time < DATE_SUB(NOW(), INTERVAL 15 MINUTE)) ORDER BY COALESCE(last_sync_time,'2000-01-01') ASC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        if ($acc) {
            $seedLocal = $seed;
            $res = promo_imap_sync_account($pdo, $acc, function($v) use ($seedLocal) { return mail_decrypt($v, $seedLocal); });
            echo "imap_sync account={$acc['id']} matched={$res['matched']} checked={$res['checked']} err=" . ($res['error'] ?? '') . "\n";
        }
    } catch (Throwable $e) {
        echo "imap_sync error: " . $e->getMessage() . "\n";
    }
    echo "sent=$totalSent failed=$totalFail\n";
} catch (Throwable $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    exit(1);
} finally {
    if ($lockFp) { @flock($lockFp, LOCK_UN); @fclose($lockFp); }
}
