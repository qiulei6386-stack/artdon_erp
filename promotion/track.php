<?php
/**
 * 推广邮件追踪端点（公开访问，无需登录）
 * t=open  打开追踪像素
 * t=click 点击追踪跳转
 * t=unsub 退订页面/确认
 */
require_once __DIR__ . '/../includes/bootstrap.php';

$pdo = db();
$type = $_GET['t'] ?? '';
$rid = (int)($_GET['rid'] ?? 0);
$sig = $_GET['sig'] ?? '';

function track_secret(): string {
    // 与 api.php 共用密钥
    return hash('sha256', 'artdon_promo_track_' . __DIR__);
}
function track_verify(int $rid, string $sig): bool {
    return hash_equals(hash_hmac('sha256', (string)$rid, track_secret()), $sig);
}
function track_sign(int $rid): string {
    return hash_hmac('sha256', (string)$rid, track_secret());
}
function track_ip(): string {
    return $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '';
}

if ($rid <= 0 || !track_verify($rid, $sig)) {
    http_response_code(400);
    exit('invalid');
}

$st = $pdo->prepare("SELECT r.*, c.id AS cid FROM promo_recipients r JOIN promo_campaigns c ON c.id=r.campaign_id WHERE r.id=?");
$st->execute([$rid]);
$rec = $st->fetch(PDO::FETCH_ASSOC);
if (!$rec) { http_response_code(404); exit('not found'); }

$ip = substr(track_ip(), 0, 45);
$ua = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255);

if ($type === 'open') {
    // 记录打开（同一收件人多次打开都记，open_count 累加）
    $pdo->prepare("INSERT INTO promo_opens (recipient_id, campaign_id, ip, user_agent) VALUES (?, ?, ?, ?)")
        ->execute([$rid, $rec['cid'], $ip, $ua]);
    $pdo->prepare("UPDATE promo_recipients SET open_count=open_count+1, first_opened_at=COALESCE(first_opened_at, NOW()) WHERE id=?")
        ->execute([$rid]);
    // 返回 1x1 透明 GIF
    header('Content-Type: image/gif');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    echo base64_decode('R0lGODlhAQABAIAAAP///////yH5BAEKAAEALAAAAAABAAEAAAICTAEAOw==');
    exit;
}

if ($type === 'click') {
    $url = base64_decode($_GET['u'] ?? '', true);
    if (!$url || !preg_match('#^https?://#i', $url)) { http_response_code(400); exit('bad url'); }
    $pdo->prepare("INSERT INTO promo_clicks (recipient_id, campaign_id, url, ip) VALUES (?, ?, ?, ?)")
        ->execute([$rid, $rec['cid'], substr($url, 0, 2000), $ip]);
    $pdo->prepare("UPDATE promo_recipients SET click_count=click_count+1, first_clicked_at=COALESCE(first_clicked_at, NOW()) WHERE id=?")
        ->execute([$rid]);
    header('Location: ' . $url, true, 302);
    exit;
}

if ($type === 'unsub') {
    if (($_GET['confirm'] ?? '') === '1') {
        // 确认退订
        $pdo->prepare("INSERT IGNORE INTO promo_unsubscribes (email, campaign_id, recipient_id) VALUES (?, ?, ?)")
            ->execute([$rec['email'], $rec['cid'], $rid]);
        $pdo->prepare("INSERT IGNORE INTO promo_blacklist (email, reason, created_at) VALUES (?, '客户主动退订', NOW())")
            ->execute([$rec['email']]);
        $pdo->prepare("UPDATE promo_recipients SET unsubscribed=1 WHERE id=?")->execute([$rid]);
        echo '<!doctype html><html lang="zh-CN"><head><meta charset="utf-8"><title>已退订</title></head>'
            . '<body style="font-family:sans-serif;text-align:center;padding:80px 20px;color:#333">'
            . '<h2>✓ 已成功退订</h2><p>您将不再收到我们的推广邮件。</p>'
            . '<p style="color:#888;font-size:13px">' . htmlspecialchars($rec['email']) . '</p></body></html>';
        exit;
    }
    // 退订确认页
    $qs = http_build_query(['t' => 'unsub', 'rid' => $rid, 'sig' => $sig, 'confirm' => '1']);
    echo '<!doctype html><html lang="zh-CN"><head><meta charset="utf-8"><title>退订确认</title></head>'
        . '<body style="font-family:sans-serif;text-align:center;padding:80px 20px;color:#333">'
        . '<h2>确认退订？</h2><p>点击确认后，您将不再收到我们的推广邮件。</p>'
        . '<p><a href="?' . $qs . '" style="display:inline-block;background:#dc2626;color:#fff;padding:12px 32px;border-radius:8px;text-decoration:none">确认退订</a></p>'
        . '<p style="color:#888;font-size:13px">' . htmlspecialchars($rec['email']) . '</p></body></html>';
    exit;
}

http_response_code(400);
exit('bad type');
