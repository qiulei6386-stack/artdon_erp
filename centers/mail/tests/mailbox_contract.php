<?php
declare(strict_types=1);

/**
 * 邮箱系统第一批（一）：邮箱账号、加密、收件箱列表、读信、标记、删除恢复、关联客户、正文显示。
 * php centers/mail/tests/mailbox_contract.php
 */

require_once dirname(__DIR__, 2) . '/_base/tests/harness.php';
require_once dirname(__DIR__, 2) . '/customer/tests/schema.php';
require_once __DIR__ . '/schema.php';

use Artdon\Centers\Base\Auth;
use Artdon\Centers\Base\Config;
use Artdon\Centers\Base\NotFoundException;
use Artdon\Centers\Base\PermissionException;
use Artdon\Centers\Base\ValidationException;
use Artdon\Centers\Customer\CustomerService;
use Artdon\Centers\Customer\RuleConfig;
use Artdon\Centers\Mail\MailAccounts;
use Artdon\Centers\Mail\MailBody;
use Artdon\Centers\Mail\Mailbox;
use Artdon\Centers\Mail\MailCrypto;
use Artdon\Centers\Mail\MailProtocol;

$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$storage = sys_get_temp_dir() . '/artdon_mail_test_' . getmypid();
@mkdir($storage . '/mail_attachments/1/1', 0777, true);
Config::set(Config::all() + ['mail' => ['key_seed' => 'test-seed', 'storage_root' => $storage]]);
$pdo = centers_test_reset();
customer_test_schema($pdo);
customer_test_dictionary($pdo);
mail_test_schema($pdo);
RuleConfig::clearCache();
$pdo->exec("INSERT INTO crm_rule_configs (rule_key, config_json) VALUES ('customer_admission', '{\"mode\":\"direct\",\"enabled\":1}')");
$perm = $pdo->prepare("INSERT INTO crm_permissions (permission_key, module, action, description, risk_level) VALUES (?, ?, ?, '', 'medium')");
$salesPerms = ['customer.view', 'customer.create', 'contact.view', 'contact.create', 'mail.view', 'mail.account_bind_own', 'mail.delete', 'mail.link_customer', 'mail.attachment_download'];
$managerPerms = ['customer.view', 'mail.view', 'mail.account_manage_all'];
$auditorPerms = ['customer.view', 'mail.view', 'mail.view_all'];
foreach (array_unique(array_merge($salesPerms, $managerPerms, $auditorPerms, ['mail.send'])) as $k) {
    [$m, $a] = explode('.', $k);
    $perm->execute([$k, $m, $a]);
}
$pdo->exec("INSERT INTO crm_roles (id, role_key, role_name, status) VALUES (3, 'mailadmin', '邮箱管理员', 'active'), (4, 'auditor', '审计', 'active'), (5, 'nomail', '无邮箱', 'active')");
foreach ([[2, $salesPerms], [3, $managerPerms], [4, $auditorPerms], [5, ['customer.view', 'mail.view']]] as [$role, $keys]) {
    foreach ($keys as $k) {
        $pdo->prepare('INSERT INTO crm_role_permissions (role_id, permission_key) VALUES (?, ?)')->execute([$role, $k]);
    }
}
$s1 = centers_test_user(['username' => 's1', 'real_name' => '业务一', 'email' => 's1@x.com']);
$s2 = centers_test_user(['username' => 's2', 'real_name' => '业务二', 'email' => 's2@x.com']);
$admin = centers_test_user(['username' => 'madmin', 'real_name' => '邮箱管理员', 'email' => 'ma@x.com', 'role_id' => 3]);
$auditor = centers_test_user(['username' => 'aud', 'real_name' => '审计', 'email' => 'au@x.com', 'role_id' => 4]);
$plain = centers_test_user(['username' => 'plain', 'real_name' => '只看', 'email' => 'pl@x.com', 'role_id' => 5]);
$u = static function (int $id): array {
    return Auth::loadUser($id);
};

/** 假的邮箱服务器：预先写好服务器的回答。 */
$fakeServer = static function (string $replies): MailProtocol {
    return new MailProtocol(static function (string $target, int $port, int $timeout) use ($replies) {
        [$client, $server] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        fwrite($server, $replies);
        $GLOBALS['fake_server_ends'][] = $server;
        $GLOBALS['fake_server_target'] = $target . ':' . $port;
        return $client;
    });
};

// ---------- 加密：与旧系统算法一致 ----------
$enc = MailCrypto::encrypt('Secret#123');
check($enc !== 'Secret#123' && MailCrypto::decrypt($enc) === 'Secret#123', '授权码加密后能解开');
$legacyKey = hash('sha256', '/www/wwwroot/Artdon/artdon_erp', true);
$iv = str_repeat("\1", 16);
$legacy = base64_encode($iv . openssl_encrypt('OldPass99', 'AES-256-CBC', $legacyKey, OPENSSL_RAW_DATA, $iv));
check(MailCrypto::decrypt($legacy, '/www/wwwroot/Artdon/artdon_erp') === 'OldPass99', '能解开旧系统按程序目录加密的授权码');
check(MailCrypto::decrypt($legacy) !== 'OldPass99', '种子不对时解不开');
$found = MailCrypto::detectSeed([$legacy, MailCrypto::encrypt('x', '/www/wwwroot/Artdon/artdon_erp')], ['artdon_new_erp', '/www/wwwroot/Artdon/artdon_erp', '']);
check($found !== null && $found['seed'] === '/www/wwwroot/Artdon/artdon_erp' && $found['ok'] === 2, '设置工具能从候选里找出正确的种子');
check(MailCrypto::detectSeed([$legacy], ['wrong']) === null, '全部解不开时不写配置');
check(MailCrypto::decrypt('not base64!!') === '' && MailCrypto::decrypt('') === '', '坏数据解不开时返回空');

// ---------- 邮箱账号 ----------
expect_throws(PermissionException::class, static function () use ($u, $plain): void {
    (new MailAccounts($u($plain)))->save($plain, ['email_address' => 'p@x.com']);
}, '没有 mail.account_bind_own 不能绑定自己的邮箱');
$acc1 = new MailAccounts($u($s1));
$e = expect_throws(ValidationException::class, static function () use ($acc1, $s1): void {
    $acc1->save($s1, ['email_address' => 'bad', 'imap_host' => 'bad host!', 'imap_port' => '0', 'smtp_host' => 'smtp.x.com', 'smtp_port' => '465', 'smtp_secure' => 'xx']);
}, '邮箱校验');
check(isset($e->fieldErrors()['email_address'], $e->fieldErrors()['mail_secret'], $e->fieldErrors()['imap_host'], $e->fieldErrors()['imap_port'], $e->fieldErrors()['smtp_secure']), '邮箱、授权码、服务器、端口、加密方式都检查');
$base = ['imap_host' => 'imap.exmail.qq.com', 'imap_port' => '993', 'imap_secure' => 'ssl', 'smtp_host' => 'smtp.exmail.qq.com', 'smtp_port' => '465', 'smtp_secure' => 'ssl'];
$a1 = $acc1->save($s1, $base + ['email_address' => 'Sales1@Artdon.com', 'mail_secret' => 'AuthCode1', 'sender_name' => 'Lei | Artdon', 'signature_html' => '<p>Best</p>']);
$row = $pdo->query("SELECT * FROM crm_user_mail_accounts WHERE id = {$a1}")->fetch();
check($row['email_address'] === 'sales1@artdon.com' && $row['email_username'] === 'sales1@artdon.com' && (int) $row['is_default'] === 1, '第一个邮箱自动设为默认，地址统一小写');
check($row['email_password_encrypted'] !== 'AuthCode1' && MailCrypto::decrypt($row['email_password_encrypted']) === 'AuthCode1', '授权码加密保存');
check(!array_key_exists('email_password_encrypted', $acc1->find($s1, $a1)) && (int) $acc1->forUser($s1)[0]['has_secret'] === 1, '读取邮箱时不带授权码');
expect_throws(ValidationException::class, static function () use ($acc1, $s1, $base): void {
    $acc1->save($s1, $base + ['email_address' => 'sales1@artdon.com', 'mail_secret' => 'x']);
}, '同一个邮箱不能绑定两次');
$a2 = $acc1->save($s1, $base + ['email_address' => 'info@artdon.com', 'mail_secret' => 'AuthCode2']);
check((int) $acc1->find($s1, $a2)['is_default'] === 0, '第二个邮箱不是默认');
$acc1->save($s1, $base + ['id' => $a1, 'email_address' => 'sales1@artdon.com', 'mail_secret' => '', 'sender_name' => 'Lei Qiu', 'delay_send_minutes' => '2']);
$row = $pdo->query("SELECT * FROM crm_user_mail_accounts WHERE id = {$a1}")->fetch();
check(MailCrypto::decrypt($row['email_password_encrypted']) === 'AuthCode1' && $row['sender_name'] === 'Lei Qiu' && (int) $row['delay_send_minutes'] === 2 && $row['signature_html'] === '<p>Best</p>', '修改时授权码留空表示不改，签名没提交时保持不变');
$acc1->save($s1, $base + ['id' => $a1, 'email_address' => 'sales1@artdon.com']);
check((int) $pdo->query("SELECT delay_send_minutes FROM crm_user_mail_accounts WHERE id = {$a1}")->fetchColumn() === 2, '没提交延迟发送时保持原值');
$acc1->setDefault($s1, $a2);
check((int) $acc1->find($s1, $a2)['is_default'] === 1 && (int) $acc1->find($s1, $a1)['is_default'] === 0, '设为默认只保留一个默认');
expect_throws(PermissionException::class, static function () use ($acc1, $s2, $base): void {
    $acc1->save($s2, $base + ['email_address' => 'z@x.com', 'mail_secret' => 'x']);
}, '不能给别人绑定邮箱');
expect_throws(NotFoundException::class, static function () use ($u, $s2, $a1): void {
    (new MailAccounts($u($s2)))->setDefault($s2, $a1);
}, '不能操作别人的邮箱编号');
$accAdmin = new MailAccounts($u($admin));
$b1 = $accAdmin->save($s2, $base + ['email_address' => 's2@artdon.com', 'mail_secret' => 'S2code']);
check((int) $pdo->query("SELECT user_id FROM crm_user_mail_accounts WHERE id = {$b1}")->fetchColumn() === $s2, 'mail.account_manage_all 可以帮同事绑定邮箱');
$names = $accAdmin->ownerNames();
ksort($names);
check($names === [$s1 => '业务一', $s2 => '业务二'], '列出有邮箱的人');
expect_throws(PermissionException::class, static function () use ($u, $admin, $s2): void {
    new Mailbox($u($admin), $s2);
}, '能维护邮箱账号不等于能看别人的邮件');
$acc1->setEnabled($s1, $a1, false);
check((int) $acc1->find($s1, $a1)['is_enabled'] === 0, '停用邮箱');
$acc1->setEnabled($s1, $a1, true);
$acc1->delete($s1, $a2);
check(count($acc1->forUser($s1)) === 1 && (int) $acc1->forUser($s1)[0]['is_default'] === 1 && $pdo->query("SELECT deleted_at FROM crm_user_mail_accounts WHERE id = {$a2}")->fetchColumn() !== null, '删除默认邮箱后，剩下的自动成为默认；只做标记');
check((int) $pdo->query("SELECT COUNT(*) FROM crm_audit_logs WHERE module = 'mail' AND action LIKE 'mail_account_%'")->fetchColumn() >= 6
    && (int) $pdo->query("SELECT COUNT(*) FROM crm_audit_logs WHERE after_json LIKE '%AuthCode%' OR before_json LIKE '%AuthCode%' OR after_json LIKE '%email_password%'")->fetchColumn() === 0, '日志里不出现授权码');

// 测试登录（假服务器）
$GLOBALS['fake_server_ends'] = [];
$okImap = new MailAccounts($u($s1), null, $fakeServer("* OK ready\r\nA001 OK LOGIN completed\r\nA002 OK bye\r\n"));
$r = $okImap->test($s1, $a1, 'imap');
check($r['ok'] === true && $GLOBALS['fake_server_target'] === 'ssl://imap.exmail.qq.com:993', '测试收信：SSL 连接 IMAP 并登录成功');
$badImap = new MailAccounts($u($s1), null, $fakeServer("* OK ready\r\nA001 NO [AUTHENTICATIONFAILED] Invalid credentials\r\n"));
$r = $badImap->test($s1, $a1, 'imap');
check($r['ok'] === false && strpos($r['message'], 'Invalid credentials') !== false, '测试收信失败时显示服务器的原因');
$okSmtp = new MailAccounts($u($s1), null, $fakeServer("220 hi\r\n250-smtp\r\n250 AUTH LOGIN\r\n334 VXNlcm5hbWU6\r\n334 UGFzc3dvcmQ6\r\n235 ok\r\n221 bye\r\n"));
check($okSmtp->test($s1, $a1, 'smtp')['ok'] === true, '测试发信：SMTP 登录成功');
$badSmtp = new MailAccounts($u($s1), null, $fakeServer("220 hi\r\n250 smtp\r\n334 VXNlcm5hbWU6\r\n334 UGFzc3dvcmQ6\r\n535 Error: authentication failed\r\n"));
$r = $badSmtp->test($s1, $a1, 'smtp');
check($r['ok'] === false && strpos($r['message'], '535') !== false, '测试发信失败时显示服务器的回答');
$pdo->exec("UPDATE crm_user_mail_accounts SET email_password_encrypted = 'garbage' WHERE id = {$b1}");
$r = $accAdmin->test($s2, $b1, 'imap');
check($r['ok'] === false && strpos($r['message'], '重新填写授权码') !== false, '授权码解不开时提示重新填写');

// ---------- 收件箱 ----------
$sales1 = $u($s1);
$cs = new CustomerService($sales1);
$custA = $cs->create(['customer_name' => 'Acme Lighting', 'country' => 'IN', 'email' => 'info@acme.in', 'contact_name' => 'Raj', 'contact_email' => 'raj@acme.in'])['id'];
$pdo->exec("UPDATE crm_customers SET customer_domain = 'acme.in' WHERE id = {$custA}");
$custHidden = (new CustomerService($u($s2)))->create(['customer_name' => 'Hidden GmbH', 'country' => 'DE', 'email' => 'buyer@hidden.de'])['id'];
$now = time();
$t = static function (int $secondsAgo) use ($now): string {
    return date('Y-m-d H:i:s', $now - $secondsAgo);
};
$m1 = mail_test_insert($pdo, ['user_id' => $s1, 'mail_account_id' => $a1, 'subject' => 'Quotation 100% LED', 'from_email' => 'raj@acme.in', 'from_name' => 'Raj', 'to_emails' => 'sales1@artdon.com', 'received_at' => $t(3600), 'body_text' => 'Please quote', 'body_html' => '<p>Please quote <img src="cid:logo123"></p><script>alert(1)</script>']);
$m2 = mail_test_insert($pdo, ['user_id' => $s1, 'mail_account_id' => $a1, 'subject' => 'Hello', 'from_email' => 'buyer@hidden.de', 'received_at' => $t(7200), 'is_read' => 1, 'is_starred' => 1]);
$m3 = mail_test_insert($pdo, ['user_id' => $s1, 'mail_account_id' => $a1, 'subject' => 'Old deleted', 'folder' => 'deleted', 'is_deleted' => 1, 'received_at' => $t(9000)]);
$m4 = mail_test_insert($pdo, ['user_id' => $s1, 'mail_account_id' => $a1, 'subject' => 'Newsletter', 'from_email' => 'news@gmail.com', 'received_at' => $t(10000)]);
$mOther = mail_test_insert($pdo, ['user_id' => $s2, 'mail_account_id' => $b1, 'subject' => 'S2 private', 'from_email' => 'k@k.com', 'received_at' => $t(100)]);
// 已发送：本系统发出的 + 服务器同步回来的副本（应隐藏） + 同一个 Message-ID 的重复
$sent1 = mail_test_insert($pdo, ['user_id' => $s1, 'mail_account_id' => $a1, 'folder' => 'sent', 'message_uid' => 'send_abc', 'crm_send_id' => 'send_abc', 'mail_source' => 'crm_sent', 'subject' => 'Offer', 'to_emails' => 'raj@acme.in', 'sent_at' => $t(5 * 86400), 'message_id_header' => '<crm-20261010101010-aaaa@artdon.com>', 'is_read' => 1]);
$sentCopy = mail_test_insert($pdo, ['user_id' => $s1, 'mail_account_id' => $a1, 'folder' => 'sent', 'message_uid' => '901', 'subject' => 'Offer', 'to_emails' => 'raj@acme.in', 'sent_at' => $t(5 * 86400 - 60), 'is_read' => 1]);
$sent2 = mail_test_insert($pdo, ['user_id' => $s1, 'mail_account_id' => $a1, 'folder' => 'sent', 'message_uid' => '902', 'subject' => 'Catalog', 'to_emails' => 'lena@fresh.de', 'sent_at' => $t(4 * 86400), 'message_id_header' => '<abc@mail>', 'is_read' => 1]);
$sent2dup = mail_test_insert($pdo, ['user_id' => $s1, 'mail_account_id' => $a1, 'folder' => 'sent', 'message_uid' => '903', 'subject' => 'Catalog', 'to_emails' => 'lena@fresh.de', 'sent_at' => $t(4 * 86400), 'message_id_header' => 'ABC@mail', 'is_read' => 1]);
$sent3 = mail_test_insert($pdo, ['user_id' => $s1, 'mail_account_id' => $a1, 'folder' => 'sent', 'message_uid' => '904', 'subject' => 'Price list', 'to_emails' => 'tom@other.com', 'sent_at' => $t(4 * 86400), 'is_read' => 1]);
mail_test_insert($pdo, ['user_id' => $s1, 'mail_account_id' => $a1, 'subject' => 'Re: Price list', 'from_email' => 'tom@other.com', 'received_at' => $t(3 * 86400), 'is_read' => 1]);
$sentRecent = mail_test_insert($pdo, ['user_id' => $s1, 'mail_account_id' => $a1, 'folder' => 'sent', 'message_uid' => '905', 'subject' => 'Just sent', 'to_emails' => 'x@y.com', 'sent_at' => $t(3600), 'is_read' => 1]);

expect_throws(PermissionException::class, static function () use ($u, $plain, $s1): void {
    new Mailbox($u($plain), $s1);
}, '没有 mail.view_all 不能看别人的邮件');
$box = new Mailbox($sales1);
$ids = static function (array $r): array {
    return array_map('intval', array_column($r['rows'], 'id'));
};
$inbox = $box->list('inbox', []);
check(in_array($m1, $ids($inbox), true) && !in_array($m3, $ids($inbox), true) && !in_array($mOther, $ids($inbox), true) && $ids($inbox)[0] === $m1, '收件箱：只有自己的、未删除的，最新的在前');
check($ids($box->list('unread', [])) === [$m1, $m4], '未读');
check($ids($box->list('starred', [])) === [$m2], '星标');
check($ids($box->list('inbox', ['q' => '100%'])) === [$m1] && $ids($box->list('inbox', ['q' => '_'])) === [], '搜索：% 和 _ 按普通字符处理');
check($ids($box->list('inbox', ['q' => 'acme'])) === [$m1], '按发件人搜索');
check($ids($box->list('deleted', [])) === [$m3], '已删除包括旧系统删除的（folder=deleted）');
$sent = $ids($box->list('sent', []));
check(in_array($sent1, $sent, true) && !in_array($sentCopy, $sent, true), '已发送：服务器同步回来的副本隐藏，只显示一份');
check(in_array($sent2, $sent, true) && !in_array($sent2dup, $sent, true), '已发送：Message-ID 相同的只显示一条');
check(in_array($sentRecent, $sent, true) && count($sent) === 4, '已发送其他邮件正常显示');
$unreplied = $ids($box->list('unreplied', []));
check($unreplied === [$sent2], '未回复：超过 3 天没收到对方回信的；对方之后来过信的（Raj、Tom）、刚发的、重复副本都不算');
$pdo->exec("UPDATE crm_mails SET followup_status = 'done' WHERE id = {$sent2}");
check($ids($box->list('unreplied', [])) === [], '标为已处理的不算未回复');
check($box->counts()['inbox'] === 2 && $box->counts()['unread'] === 2, '侧栏未读数量');
check($ids($box->list('inbox', ['account' => $b1])) === [], '按邮箱筛选只在自己的邮件里');

// 读信
$att1 = $storage . '/mail_attachments/1/1/quote.pdf';
file_put_contents($att1, '%PDF-1.4 test');
$img = $storage . '/mail_attachments/1/1/logo.png';
file_put_contents($img, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='));
$outside = sys_get_temp_dir() . '/artdon_outside_' . getmypid() . '.txt';
file_put_contents($outside, 'secret');
$ins = $pdo->prepare('INSERT INTO crm_mail_attachments (user_id, mail_id, file_name, original_filename, file_path, file_size, mime_type, content_id, is_inline) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
$ins->execute([$s1, $m1, 'quote.pdf', 'Quotation 报价.pdf', $att1, 13, 'application/pdf', null, 0]);
$attPdf = (int) $pdo->lastInsertId();
$ins->execute([$s1, $m1, 'logo.png', null, $img, 70, 'image/png', '<logo123>', 1]);
$attImg = (int) $pdo->lastInsertId();
$ins->execute([$s1, $m1, 'evil.txt', null, $storage . '/../' . basename($outside), 6, 'text/plain', null, 0]);
$attEvil = (int) $pdo->lastInsertId();
check($ids($box->list('attachments', [])) === [$m1], '有附件：不算内嵌图片');
$read = $box->read($m1);
check((int) $read['is_read'] === 1 && (int) $pdo->query("SELECT is_read FROM crm_mails WHERE id = {$m1}")->fetchColumn() === 1, '打开未读邮件后标为已读');
check(count($read['attachments']) === 2 && $read['attachments'][0]['name'] === 'Quotation 报价.pdf' && !isset($read['attachments'][0]['file_path']), '附件列表用原始文件名，不带服务器路径');
check($read['account_email'] === 'sales1@artdon.com' && $read['is_outgoing'] === false, '显示收信邮箱');
check(array_column($read['suggestions'], 'id') === [$custA], '建议关联：联系人邮箱相同的客户');
$f = $box->attachmentFile($attPdf, false);
check($f['name'] === 'Quotation 报价.pdf' && $f['path'] === realpath($att1) && $f['inline'] === false, '下载附件');
expect_throws(NotFoundException::class, static function () use ($box, $attEvil): void {
    $box->attachmentFile($attEvil, false);
}, '附件路径不在存放目录里时拒绝');
expect_throws(NotFoundException::class, static function () use ($u, $s2, $attPdf): void {
    (new Mailbox($u($s2)))->attachmentFile($attPdf, false);
}, '不能下载别人邮件的附件');
$pdo->exec("DELETE FROM crm_role_permissions WHERE role_id = 2 AND permission_key = 'mail.attachment_download'");
\Artdon\Centers\Base\Permission::clearCache();
$sales1b = $u($s1);
$box = new Mailbox($sales1b);
check($box->attachmentFile($attImg, true)['inline'] === true, '没有下载权限时，正文里的图片仍能显示');
expect_throws(PermissionException::class, static function () use ($box, $attPdf): void {
    $box->attachmentFile($attPdf, false);
}, '没有 mail.attachment_download 不能下载附件');
expect_throws(PermissionException::class, static function () use ($box, $attPdf): void {
    $box->attachmentFile($attPdf, true);
}, '非图片附件不能用内嵌方式绕过下载权限');

// 标记、删除、恢复
$box->setRead([$m1], false);
$box->setStarred([$m1, $m4, $mOther], true);
check((int) $pdo->query("SELECT is_read FROM crm_mails WHERE id = {$m1}")->fetchColumn() === 0 && (int) $pdo->query("SELECT is_starred FROM crm_mails WHERE id = {$mOther}")->fetchColumn() === 0, '标记未读、加星标；别人的邮件不受影响');
$box->delete([$m4]);
check((int) $pdo->query("SELECT is_deleted FROM crm_mails WHERE id = {$m4}")->fetchColumn() === 1 && $pdo->query("SELECT folder FROM crm_mails WHERE id = {$m4}")->fetchColumn() === 'inbox', '删除只做标记，保留原文件夹');
$box->restore([$m4, $m3]);
check($pdo->query("SELECT folder || is_deleted FROM crm_mails WHERE id = {$m3}")->fetchColumn() === 'inbox0' && (int) $pdo->query("SELECT is_deleted FROM crm_mails WHERE id = {$m4}")->fetchColumn() === 0, '恢复；旧系统删除的回到收件箱');
$box->delete([$sent2]);
$pdo->exec("UPDATE crm_mails SET folder = 'deleted', is_deleted = 1 WHERE id = {$sent1}");
$box->restore([$sent1]);
check($pdo->query("SELECT folder FROM crm_mails WHERE id = {$sent1}")->fetchColumn() === 'sent', '旧系统删除的已发送邮件恢复到已发送');
expect_throws(PermissionException::class, static function () use ($u, $plain): void {
    (new Mailbox($u($plain)))->delete([1]);
}, '没有 mail.delete 不能删除');
expect_throws(\RuntimeException::class, static function () use ($box): void {
    $box->setRead([], true);
}, '没选邮件时提示');

// 关联客户
expect_throws(NotFoundException::class, static function () use ($box, $m2, $custHidden): void {
    $box->linkCustomer($m2, $custHidden);
}, '只能关联自己看得到的客户');
$box->linkCustomer($m1, $custA);
$linked = $pdo->query("SELECT linked_customer_id, linked_contact_id FROM crm_mails WHERE id = {$m1}")->fetch();
check((int) $linked['linked_customer_id'] === $custA && (int) $linked['linked_contact_id'] === (int) $pdo->query("SELECT id FROM crm_contacts WHERE email = 'raj@acme.in'")->fetchColumn(), '关联客户时自动对上联系人');
check($ids($box->list('linked', [])) === [$m1] && !in_array($m1, $ids($box->list('unlinked', [])), true), '已关联、未关联');
check($box->read($m1)['customer']['customer_name'] === 'Acme Lighting' && $box->read($m1)['suggestions'] === [], '读信显示已关联的客户');
$box->linkCustomer($m1, 0);
check($pdo->query("SELECT linked_customer_id FROM crm_mails WHERE id = {$m1}")->fetchColumn() === null, '取消关联');
check($box->read($m2)['suggestions'] === [], '看不到的客户不出现在建议里');
check($box->read($m4)['suggestions'] === [], '公共邮箱域名不按域名猜');
$m5 = mail_test_insert($pdo, ['user_id' => $s1, 'mail_account_id' => $a1, 'subject' => 'From new person', 'from_email' => 'boss@acme.in', 'received_at' => $t(50)]);
check(array_column($box->read($m5)['suggestions'], 'reason') === ['邮箱域名相同'], '按客户域名建议');
expect_throws(PermissionException::class, static function () use ($u, $plain, $m1, $custA): void {
    (new Mailbox($u($plain)))->linkCustomer($m1, $custA);
}, '没有 mail.link_customer 不能关联');

// 查看全部邮件：只读、留日志
$audit = new Mailbox($u($auditor), $s1);
$pdo->exec("UPDATE crm_mails SET is_read = 0 WHERE id = {$m5}");
$before = (int) $pdo->query("SELECT COUNT(*) FROM crm_audit_logs WHERE action = 'mail_view_other'")->fetchColumn();
$r = $audit->read($m5);
check((int) $pdo->query("SELECT is_read FROM crm_mails WHERE id = {$m5}")->fetchColumn() === 0, '别人查看时不改变已读状态');
check((int) $pdo->query("SELECT COUNT(*) FROM crm_audit_logs WHERE action = 'mail_view_other'")->fetchColumn() === $before + 1, '查看别人的邮件记日志');
check($r['suggestions'] === [] && !$audit->can('mail.delete'), '查看别人邮件时没有修改操作');
expect_throws(PermissionException::class, static function () use ($audit, $m5): void {
    $audit->setStarred([$m5], true);
}, '查看别人邮件时不能标记');
expect_throws(NotFoundException::class, static function () use ($audit, $mOther): void {
    $audit->read($mOther);
}, '按主人查看，不能串到别人的邮件编号');
check(count($audit->list('inbox', [])['rows']) > 0, '有 mail.view_all 可以看别人的收件箱');

// ---------- 正文显示 ----------
$html = MailBody::html(['body_html' => '<html><head><meta http-equiv="refresh" content="0;url=http://evil"><style>p{color:red}</style></head><body onload="x()"><p onclick="steal()">Hi <a href="javascript:alert(1)">x</a></p><img src="cid:logo123"><img src="crm_api.php?action=mail_attachment_download&amp;attachment_id=' . $attPdf . '&amp;inline=1"><img src="/artdon_erp/storage/mail_attachments/1/1/logo.png"><img src="cid:missing"><img src="https://cdn.x.com/a.png"><script>alert(1)</script><iframe src="x"></iframe><form action="http://evil"><input name="p"></form></body></html>'],
    $box->bodyAttachments($m1), static function (int $id): string {
        return 'att.php?id=' . $id;
    });
check(strpos($html, '<script') === false && strpos($html, 'onclick') === false && strpos($html, 'onload') === false && stripos($html, 'javascript:') === false && strpos($html, '<iframe') === false && stripos($html, 'refresh') === false && strpos($html, '<form') === false && strpos($html, '<input') === false, '去掉脚本、事件、javascript 链接、iframe、meta 跳转、表单');
check(strpos($html, 'src="att.php?id=' . $attImg . '"') !== false && strpos($html, 'src="att.php?id=' . $attPdf . '"') !== false, '内嵌图片 cid 和旧系统附件地址都换成新地址');
check(substr_count($html, 'att.php?id=' . $attImg) === 2 && strpos($html, 'src=""') !== false && strpos($html, 'https://cdn.x.com/a.png') !== false && strpos($html, 'p{color:red}') !== false, '服务器路径图片换成新地址；找不到的内嵌图片清空；外部图片和样式保留');
$text = MailBody::html(['body_html' => '', 'body_text' => "Hello <b>\nSee https://artdon.com/a?b=1"], [], static function (int $id): string {
    return '';
});
check(strpos($text, '&lt;b&gt;') !== false && strpos($text, '<a href="https://artdon.com/a?b=1">') !== false, '纯文本正文转义并把网址变成链接');
check(strpos(MailBody::csp(), "default-src 'none'") === 0 && strpos(MailBody::csp(), 'script') === false, '正文页 CSP 不允许脚本');
check(Mailbox::addresses('"Raj, K" <Raj@Acme.in>, info@acme.in; x@y.co') === ['raj@acme.in', 'info@acme.in', 'x@y.co'], '解析地址串');
check(Mailbox::displayAddresses('"Raj, K" <raj@acme.in>, info@acme.in, c@c.com') === 'Raj, K、info@acme.in 等 3 人', '列表里显示收件人');

// ---------- 分类、标签筛选、工作台 ----------
check(Mailbox::category(['subject' => 'Re: Quotation for LED']) === 'quote' && Mailbox::category(['subject' => 'Newsletter: price drop']) === 'promotion'
    && Mailbox::category(['subject' => '寄样确认']) === 'sample' && Mailbox::category(['subject' => 'Warranty question']) === 'service'
    && Mailbox::category(['subject' => 'Hello']) === 'normal' && Mailbox::category(['subject' => 'Hello', 'mail_category' => 'service']) === 'service', '邮件分类：手动分类优先，否则按主题关键词（推广优先于报价）');
$box = new Mailbox($u($s1));
$all = $box->list('inbox', [])['rows'];
$byCat = [];
foreach ($all as $r) {
    $byCat[$r['category']][] = (int) $r['id'];
}
$cc = $box->categoryCounts('inbox', []);
$okCats = $cc['total'] === count($all);
foreach (array_keys(Mailbox::CATEGORIES) as $cat) {
    $listed = $ids($box->list('inbox', ['cat' => $cat]));
    sort($listed);
    $expect = $byCat[$cat] ?? [];
    sort($expect);
    $okCats = $okCats && $cc[$cat] === count($expect) && $listed === $expect;
}
check($okCats && $cc['quote'] >= 1, '分类数量、分类筛选与逐封判断一致（SQL 与 PHP 规则相同）');
$box->linkCustomer($m1, $custA);
check($ids($box->list('inbox', ['cust' => 'linked'])) === [$m1] && !in_array($m1, $ids($box->list('inbox', ['cust' => 'unlinked'])), true), '标签：客户邮件 / 未关联');
check($ids($box->list('inbox', ['customer' => $custA])) === [$m1], '按客户看邮件');
check($ids($box->list('inbox', ['att' => '1'])) === [$m1], '标签：有附件');
$pdo->prepare('UPDATE crm_mails SET is_read = 0, is_replied = 0, received_at = ? WHERE id = ?')->execute([$t(600), $m1]);
$dash = (new \Artdon\Centers\Mail\MailDashboard($u($s1)))->data();
check(count($dash['days']) === 7 && end($dash['days']) === date('Y-m-d') && count($dash['kpi']['in_daily']) === 7, '工作台：最近 7 天按天统计');
$todayIn = (int) $pdo->query("SELECT COUNT(*) FROM crm_mails WHERE user_id = {$s1} AND folder = 'inbox' AND is_deleted = 0 AND SUBSTR(COALESCE(received_at, created_at), 1, 10) = '" . date('Y-m-d') . "'")->fetchColumn();
check($dash['kpi']['today'] === $todayIn && $dash['kpi']['unread'] === $box->counts()['inbox'] && $dash['kpi']['unread_customer'] === 1, '工作台：今日收信、未读、未读客户来信');
check(array_map('intval', array_column($dash['customer_waiting'], 'id')) === [$m1], '工作台：客户来信还没回复');
check(array_map('intval', array_column($dash['top_customers'], 'id')) === [$custA] && $dash['top_customers'][0]['received'] === 1 && $dash['top_customers'][0]['percent'] === 100, '工作台：往来最多的客户');
check(array_map('intval', array_column($dash['maybe_customer'], 'id')) === [$m5] && $dash['maybe_customer'][0]['suggestion']['id'] === $custA, '工作台：可能是客户的来信（按域名）');
check(count($dash['accounts']) === 1 && $dash['accounts'][0]['state'][1] === 'warn' && $dash['accounts'][0]['received_30'] >= 1, '工作台：邮箱状态和 30 天收发');
$dashOther = (new \Artdon\Centers\Mail\MailDashboard($u($auditor), $s1))->data();
check($dashOther['kpi']['unread'] === $dash['kpi']['unread'] && $dashOther['maybe_customer'] !== null, '有 mail.view_all 可以看别人的工作台');
expect_throws(PermissionException::class, static function () use ($u, $plain, $s1): void {
    new \Artdon\Centers\Mail\MailDashboard($u($plain), $s1);
}, '没有 mail.view_all 不能看别人的工作台');
check(\Artdon\Centers\Mail\MailDashboard::bars([0, 5, 10]) === [0, 50, 100] && \Artdon\Centers\Mail\MailDashboard::bars([0, 0]) === [0, 0], '柱状图高度');
$st = (new MailAccounts($u($admin)))->stats($s1);
check(isset($st[$a1]) && $st[$a1]['received'] >= 1 && $st[$a1]['unread'] >= 1, '邮箱设置：每个邮箱的 30 天收发和未读');
check(MailAccounts::state(['is_enabled' => 0])[1] === 'muted' && MailAccounts::state(['is_enabled' => 1, 'sync_error' => 'x'])[1] === 'bad' && MailAccounts::state(['is_enabled' => 1, 'last_sync_time' => '2026-01-01'])[1] === 'good', '邮箱状态');

@unlink($att1);
@unlink($img);
@unlink($outside);
@rmdir($storage . '/mail_attachments/1/1');
@rmdir($storage . '/mail_attachments/1');
@rmdir($storage . '/mail_attachments');
@rmdir($storage);
centers_test_finish('mailbox_contract');
