<?php
declare(strict_types=1);

namespace Artdon\Centers\Mail;

use Artdon\Centers\Base\Audit;
use Artdon\Centers\Base\Db;
use Artdon\Centers\Base\NotFoundException;
use Artdon\Centers\Base\Permission;
use Artdon\Centers\Base\PermissionException;
use Artdon\Centers\Base\UserDirectory;
use Artdon\Centers\Base\ValidationException;
use PDO;

/**
 * 邮箱账号（crm_user_mail_accounts）。一个人可以绑定多个邮箱，其中一个为默认。
 * 维护自己的邮箱：mail.account_bind_own；帮别人维护：mail.account_manage_all（只能维护账号，不能读信）。
 * 授权码加密保存，页面上永远不显示；修改时留空表示不改。
 * 删除邮箱只停用账号（不删除已收的邮件），与旧系统不同：旧系统会把邮件一起删掉。
 */
final class MailAccounts
{
    public const SECURE = ['ssl' => 'SSL', 'tls' => 'STARTTLS', 'none' => '不加密'];

    /** 常用邮箱的服务器设置。 */
    public const PRESETS = [
        'exmail' => ['腾讯企业邮', 'imap.exmail.qq.com', 993, 'ssl', 'smtp.exmail.qq.com', 465, 'ssl'],
        'qq' => ['QQ 邮箱', 'imap.qq.com', 993, 'ssl', 'smtp.qq.com', 465, 'ssl'],
        '163' => ['网易 163', 'imap.163.com', 993, 'ssl', 'smtp.163.com', 465, 'ssl'],
        'aliyun' => ['阿里企业邮', 'imap.qiye.aliyun.com', 993, 'ssl', 'smtp.qiye.aliyun.com', 465, 'ssl'],
        'gmail' => ['Gmail', 'imap.gmail.com', 993, 'ssl', 'smtp.gmail.com', 465, 'ssl'],
        'outlook' => ['Outlook / Office 365', 'outlook.office365.com', 993, 'ssl', 'smtp.office365.com', 587, 'tls'],
    ];

    private const COLUMNS = 'id, user_id, email_address, email_username, imap_host, imap_port, imap_secure, smtp_host, smtp_port, smtp_secure, sender_name, signature_html, delay_send_minutes, is_enabled, is_default, last_sync_time, sync_status, sync_error, created_at, updated_at';

    /** @var PDO */
    private $pdo;

    /** @var array<string, mixed> */
    private $operator;

    /** @var MailProtocol */
    private $protocol;

    public function __construct(array $operator, ?PDO $pdo = null, ?MailProtocol $protocol = null)
    {
        $this->operator = $operator;
        $this->pdo = $pdo ?? Db::pdo();
        $this->protocol = $protocol ?? new MailProtocol();
    }

    public function canManage(int $userId): bool
    {
        if ($userId === (int) $this->operator['id']) {
            return Permission::allows($this->operator, 'mail.account_bind_own') || Permission::allows($this->operator, 'mail.account_manage_all');
        }
        return Permission::allows($this->operator, 'mail.account_manage_all');
    }

    public function canManageOthers(): bool
    {
        return Permission::allows($this->operator, 'mail.account_manage_all');
    }

    /** 某人的邮箱（不含授权码）。 @return list<array<string, mixed>> */
    public function forUser(int $userId): array
    {
        $stmt = $this->pdo->prepare('SELECT ' . self::COLUMNS . ', CASE WHEN email_password_encrypted IS NULL OR email_password_encrypted = \'\' THEN 0 ELSE 1 END AS has_secret
            FROM crm_user_mail_accounts WHERE user_id = ? AND deleted_at IS NULL ORDER BY is_default DESC, id ASC');
        $stmt->execute([$userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** 有邮箱的人（管理员选人用）：编号 => 邮箱数。 @return array<int, int> */
    public function owners(): array
    {
        $rows = $this->pdo->query('SELECT user_id, COUNT(*) AS n FROM crm_user_mail_accounts WHERE deleted_at IS NULL GROUP BY user_id')->fetchAll(PDO::FETCH_ASSOC);
        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r['user_id']] = (int) $r['n'];
        }
        return $out;
    }

    /** 邮箱状态：[文字, 样式 good/warn/bad/muted]。 @return array{0: string, 1: string} */
    public static function state(array $a): array
    {
        $s = (string) ($a['sync_status'] ?? '');
        if ((int) ($a['is_enabled'] ?? 1) !== 1) {
            return ['已停用', 'muted'];
        }
        if ($s === 'failed' || $s === 'error' || trim((string) ($a['sync_error'] ?? '')) !== '') {
            return ['收信出错', 'bad'];
        }
        if (!empty($a['last_sync_time'])) {
            return ['正常', 'good'];
        }
        return ['等待第一次收信', 'warn'];
    }

    /**
     * 每个邮箱最近 30 天收、发数量和未读数（只是数量，不含邮件内容，维护邮箱的人也能看）。
     *
     * @return array<int, array{received: int, sent: int, unread: int, total: int}>
     */
    public function stats(int $userId): array
    {
        $stmt = $this->pdo->prepare("SELECT mail_account_id,
                SUM(CASE WHEN folder = 'inbox' AND COALESCE(received_at, created_at) >= ? THEN 1 ELSE 0 END) AS received,
                SUM(CASE WHEN folder = 'sent' AND COALESCE(sent_at, created_at) >= ? THEN 1 ELSE 0 END) AS sent,
                SUM(CASE WHEN folder = 'inbox' AND is_read = 0 THEN 1 ELSE 0 END) AS unread,
                COUNT(*) AS total
            FROM crm_mails WHERE user_id = ? AND is_deleted = 0 GROUP BY mail_account_id");
        $since = date('Y-m-d H:i:s', time() - 30 * 86400);
        $stmt->execute([$since, $since, $userId]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[(int) $r['mail_account_id']] = ['received' => (int) $r['received'], 'sent' => (int) $r['sent'], 'unread' => (int) $r['unread'], 'total' => (int) $r['total']];
        }
        return $out;
    }

    /** 有邮箱的人：编号 => 姓名（已停用的标出来），按姓名排序。 @return array<int, string> */
    public function ownerNames(): array
    {
        $rows = $this->pdo->query("SELECT u.id, COALESCE(NULLIF(u.real_name, ''), u.username) AS name, u.status FROM crm_users u
            WHERE u.id IN (SELECT user_id FROM crm_user_mail_accounts WHERE deleted_at IS NULL) ORDER BY u.status, name")->fetchAll(PDO::FETCH_ASSOC);
        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r['id']] = (string) $r['name'] . ((string) $r['status'] !== 'active' ? '（已停用）' : '');
        }
        return $out;
    }

    /** 一个邮箱（不含授权码），必须属于 $userId。 @return array<string, mixed> */
    public function find(int $userId, int $accountId): array
    {
        $stmt = $this->pdo->prepare('SELECT ' . self::COLUMNS . ' FROM crm_user_mail_accounts WHERE id = ? AND user_id = ? AND deleted_at IS NULL');
        $stmt->execute([$accountId, $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new NotFoundException('邮箱不存在');
        }
        return $row;
    }

    /** 带解密后授权码的邮箱（发信、测试用，不要传到页面）。 @return array<string, mixed> */
    public function withSecret(int $userId, int $accountId): array
    {
        $row = $this->find($userId, $accountId);
        $stmt = $this->pdo->prepare('SELECT email_password_encrypted FROM crm_user_mail_accounts WHERE id = ?');
        $stmt->execute([$accountId]);
        $row['mail_secret'] = MailCrypto::decrypt((string) $stmt->fetchColumn());
        if ($row['mail_secret'] === '') {
            throw new \RuntimeException('这个邮箱的授权码读不出来，请在“邮箱设置”里重新填写授权码');
        }
        return $row;
    }

    /** 新增或修改邮箱（$input 有 id 为修改）。 */
    public function save(int $userId, array $input): int
    {
        $this->requireManage($userId);
        if (!UserDirectory::isActive($userId)) {
            throw new ValidationException(['user_id' => '这个员工账号不存在或已停用']);
        }
        $id = (int) ($input['id'] ?? 0);
        $before = $id > 0 ? $this->find($userId, $id) : null;
        $str = static function (string $k, int $max) use ($input): string {
            $v = $input[$k] ?? '';
            return is_scalar($v) ? mb_substr(trim((string) $v), 0, $max, 'UTF-8') : '';
        };
        $errors = [];
        $email = strtolower($str('email_address', 190));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors['email_address'] = '邮箱地址格式不正确';
        }
        $login = $str('email_username', 190) ?: $email;
        if ($login !== '' && !filter_var($login, FILTER_VALIDATE_EMAIL)) {
            $errors['email_username'] = '登录账号应为邮箱地址';
        }
        $secret = is_scalar($input['mail_secret'] ?? null) ? (string) $input['mail_secret'] : '';
        if ($secret === '' && $before === null) {
            $errors['mail_secret'] = '请填写授权码（或邮箱密码）';
        }
        $server = [];
        foreach (['imap' => 'IMAP', 'smtp' => 'SMTP'] as $p => $label) {
            $host = strtolower($str($p . '_host', 190));
            if ($host === '' || !preg_match('/^[a-z0-9.-]+$/', $host)) {
                $errors[$p . '_host'] = '请填写 ' . $label . ' 服务器地址';
            }
            $port = (int) $str($p . '_port', 6);
            if ($port < 1 || $port > 65535) {
                $errors[$p . '_port'] = '端口不正确';
            }
            $secure = $str($p . '_secure', 10) ?: 'ssl';
            if (!isset(self::SECURE[$secure])) {
                $errors[$p . '_secure'] = '加密方式不正确';
            }
            $server[$p] = [$host, $port, $secure];
        }
        $delay = array_key_exists('delay_send_minutes', $input) ? (int) $str('delay_send_minutes', 3) : (int) ($before['delay_send_minutes'] ?? 0);
        if ($delay < 0 || $delay > 10) {
            $errors['delay_send_minutes'] = '延迟发送为 0 到 10 分钟';
        }
        if (!$errors) {
            $stmt = $this->pdo->prepare('SELECT id FROM crm_user_mail_accounts WHERE user_id = ? AND LOWER(email_address) = ? AND deleted_at IS NULL AND id <> ?');
            $stmt->execute([$userId, $email, $id]);
            if ($stmt->fetchColumn()) {
                $errors['email_address'] = '这个邮箱已经绑定过了';
            }
        }
        if ($errors) {
            throw new ValidationException($errors);
        }
        $senderName = $str('sender_name', 190);
        $signature = is_string($input['signature_html'] ?? null) ? mb_substr((string) $input['signature_html'], 0, 60000, 'UTF-8') : (string) ($before['signature_html'] ?? '');
        $now = Db::now();
        if ($before) {
            $sql = 'UPDATE crm_user_mail_accounts SET email_address = ?, email_username = ?, imap_host = ?, imap_port = ?, imap_secure = ?, smtp_host = ?, smtp_port = ?, smtp_secure = ?, sender_name = ?, signature_html = ?, delay_send_minutes = ?, updated_at = ?';
            $params = [$email, $login, $server['imap'][0], $server['imap'][1], $server['imap'][2], $server['smtp'][0], $server['smtp'][1], $server['smtp'][2], $senderName, $signature, $delay, $now];
            if ($secret !== '') {
                $sql .= ', email_password_encrypted = ?, sync_fail_count = 0, sync_backoff_until = NULL';
                $params[] = MailCrypto::encrypt($secret);
            }
            $params[] = $id;
            $this->pdo->prepare($sql . ' WHERE id = ?')->execute($params);
        } else {
            $first = !$this->forUser($userId);
            $this->pdo->prepare('INSERT INTO crm_user_mail_accounts (user_id, email_address, email_username, email_password_encrypted, imap_host, imap_port, imap_secure, smtp_host, smtp_port, smtp_secure, sender_name, signature_html, delay_send_minutes, is_enabled, is_default, sync_status, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, \'never\', ?, ?)')
                ->execute([$userId, $email, $login, MailCrypto::encrypt($secret), $server['imap'][0], $server['imap'][1], $server['imap'][2], $server['smtp'][0], $server['smtp'][1], $server['smtp'][2], $senderName, $signature, $delay, $first ? 1 : 0, $now, $now]);
            $id = (int) $this->pdo->lastInsertId();
        }
        if (!empty($input['is_default'])) {
            $this->setDefault($userId, $id);
        }
        $after = $this->find($userId, $id);
        Audit::log($before ? 'mail_account_update' : 'mail_account_create', 'mail', 'mail_account', $id, $before ? self::safe($before) : null, self::safe($after) + ['secret_changed' => $secret !== '', 'owner_user_id' => $userId], $this->operator);
        return $id;
    }

    public function setDefault(int $userId, int $accountId): void
    {
        $this->requireManage($userId);
        $this->find($userId, $accountId);
        $this->pdo->prepare('UPDATE crm_user_mail_accounts SET is_default = CASE WHEN id = ? THEN 1 ELSE 0 END WHERE user_id = ? AND deleted_at IS NULL')->execute([$accountId, $userId]);
    }

    public function setEnabled(int $userId, int $accountId, bool $enabled): void
    {
        $this->requireManage($userId);
        $before = $this->find($userId, $accountId);
        $this->pdo->prepare('UPDATE crm_user_mail_accounts SET is_enabled = ?, updated_at = ? WHERE id = ?')->execute([$enabled ? 1 : 0, Db::now(), $accountId]);
        Audit::log($enabled ? 'mail_account_enable' : 'mail_account_disable', 'mail', 'mail_account', $accountId, ['is_enabled' => (int) $before['is_enabled']], ['is_enabled' => $enabled ? 1 : 0, 'owner_user_id' => $userId], $this->operator);
    }

    /** 删除（停用并隐藏）邮箱，已收的邮件保留。 */
    public function delete(int $userId, int $accountId): void
    {
        $this->requireManage($userId);
        $before = $this->find($userId, $accountId);
        $this->pdo->prepare('UPDATE crm_user_mail_accounts SET deleted_at = ?, is_enabled = 0, is_default = 0, updated_at = ? WHERE id = ?')->execute([Db::now(), Db::now(), $accountId]);
        if ((int) $before['is_default'] === 1) {
            $rest = $this->forUser($userId);
            if ($rest) {
                $this->pdo->prepare('UPDATE crm_user_mail_accounts SET is_default = 1 WHERE id = ?')->execute([(int) $rest[0]['id']]);
            }
        }
        Audit::log('mail_account_delete', 'mail', 'mail_account', $accountId, self::safe($before), ['deleted' => true, 'owner_user_id' => $userId], $this->operator);
    }

    /** 测试登录。返回 [成功, 说明, 用时毫秒]。 @return array{ok: bool, message: string, ms: int} */
    public function test(int $userId, int $accountId, string $type): array
    {
        $this->requireManage($userId);
        $type = $type === 'smtp' ? 'smtp' : 'imap';
        $start = microtime(true);
        try {
            $account = $this->withSecret($userId, $accountId);
            $type === 'smtp' ? $this->protocol->testSmtp($account) : $this->protocol->testImap($account);
            $result = ['ok' => true, 'message' => ($type === 'smtp' ? '发信（SMTP）' : '收信（IMAP）') . '登录成功'];
        } catch (\RuntimeException $e) {
            $result = ['ok' => false, 'message' => $e->getMessage()];
        }
        $result['ms'] = (int) round((microtime(true) - $start) * 1000);
        Audit::log('mail_account_test_' . $type, 'mail', 'mail_account', $accountId, null, $result + ['owner_user_id' => $userId], $this->operator);
        return $result;
    }

    /** @return array<string, mixed> 去掉签名大字段，日志用 */
    private static function safe(array $row): array
    {
        unset($row['signature_html'], $row['mail_secret'], $row['email_password_encrypted']);
        return $row;
    }

    private function requireManage(int $userId): void
    {
        if (!$this->canManage($userId)) {
            throw new PermissionException($userId === (int) $this->operator['id'] ? '没有绑定邮箱的权限（mail.account_bind_own）' : '没有维护员工邮箱的权限（mail.account_manage_all）');
        }
    }
}
