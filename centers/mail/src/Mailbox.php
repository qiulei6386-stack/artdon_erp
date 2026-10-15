<?php
declare(strict_types=1);

namespace Artdon\Centers\Mail;

use Artdon\Centers\Base\Audit;
use Artdon\Centers\Base\Config;
use Artdon\Centers\Base\Db;
use Artdon\Centers\Base\NotFoundException;
use Artdon\Centers\Base\Permission;
use Artdon\Centers\Base\PermissionException;
use Artdon\Centers\Customer\CustomerService;
use PDO;

/**
 * 一个人的邮箱（crm_mails）：文件夹列表、搜索、读信、标记、删除恢复、关联客户。
 *
 * - 默认只看自己的邮件；看别人的邮件需要 mail.view_all，只能看不能改，每次读信都记日志；
 * - mail.account_manage_all 只能维护别人的邮箱账号，不能读信；
 * - 删除只把 is_deleted 置 1（保留原文件夹），恢复置 0；旧系统删除时把 folder 改成 deleted，这类邮件也算在“已删除”里；
 * - “已发送”里隐藏重复：同一封信既有本系统发出时写的记录，又有从邮箱服务器同步回来的副本，只显示一份（规则与旧系统相同）。
 */
final class Mailbox
{
    public const FOLDERS = [
        'inbox' => '收件箱',
        'sent' => '已发送',
        'unread' => '未读',
        'starred' => '星标',
        'unreplied' => '未回复',
        'attachments' => '有附件',
        'linked' => '已关联客户',
        'unlinked' => '未关联客户',
        'deleted' => '已删除',
    ];

    public const PERIODS = ['' => '全部时间', 'today' => '今天', '7d' => '最近 7 天', '30d' => '最近 30 天'];

    public const PER_PAGE = 30;

    /** 未回复：发出多少天后还没收到对方回信。 */
    public const UNREPLIED_DAYS = 3;

    /** 公共邮箱域名，不按域名猜客户。 */
    private const PUBLIC_DOMAINS = ['gmail.com', 'yahoo.com', 'hotmail.com', 'outlook.com', 'live.com', 'qq.com', '163.com', '126.com', 'icloud.com', 'aol.com', 'msn.com', 'foxmail.com', 'sina.com', 'yandex.com', 'mail.ru', 'rediffmail.com', 'protonmail.com', 'gmx.de', 'web.de'];

    private const VISIBLE_ATTACHMENT = 'EXISTS (SELECT 1 FROM crm_mail_attachments a WHERE a.mail_id = m.id AND COALESCE(a.is_inline, 0) = 0 AND COALESCE(a.is_signature_image, 0) = 0)';

    /** @var PDO */
    private $pdo;

    /** @var array<string, mixed> */
    private $operator;

    /** @var int 邮箱主人 */
    private $ownerId;

    public function __construct(array $operator, int $ownerId = 0, ?PDO $pdo = null)
    {
        $this->operator = $operator;
        $this->ownerId = $ownerId > 0 ? $ownerId : (int) $operator['id'];
        $this->pdo = $pdo ?? Db::pdo();
        if (!Permission::allows($operator, 'mail.view')) {
            throw new PermissionException('没有查看邮件的权限（mail.view）');
        }
        if (!$this->isSelf() && !self::canViewAll($operator)) {
            throw new PermissionException('查看别人的邮件需要“查看全部邮件”权限（mail.view_all）');
        }
    }

    public static function canViewAll(array $user): bool
    {
        return Permission::allows($user, 'mail.view_all');
    }

    public function isSelf(): bool
    {
        return $this->ownerId === (int) $this->operator['id'];
    }

    public function ownerId(): int
    {
        return $this->ownerId;
    }

    public function can(string $permission): bool
    {
        return $this->isSelf() && Permission::allows($this->operator, $permission);
    }

    /** 主人的邮箱账号（含已停用，不含已删除），给筛选用。 @return list<array<string, mixed>> */
    public function accounts(): array
    {
        $stmt = $this->pdo->prepare('SELECT id, email_address, sender_name, is_enabled, is_default, last_sync_time, sync_status, sync_error FROM crm_user_mail_accounts WHERE user_id = ? AND deleted_at IS NULL ORDER BY is_default DESC, id ASC');
        $stmt->execute([$this->ownerId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * 列表。$f：q 关键词、account 邮箱编号、read（read/unread）、period（today/7d/30d）。
     *
     * @return array{rows: list<array<string, mixed>>, total: int, page: int, pages: int}
     */
    public function list(string $folder, array $f, int $page = 1): array
    {
        $folder = isset(self::FOLDERS[$folder]) ? $folder : 'inbox';
        [$where, $params] = $this->where($folder, $f);
        $sql = ' FROM crm_mails m WHERE ' . implode(' AND ', $where);
        $stmt = $this->pdo->prepare('SELECT COUNT(*)' . $sql);
        $stmt->execute($params);
        $total = (int) $stmt->fetchColumn();
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $page = max(1, min($page, $pages));
        $stmt = $this->pdo->prepare('SELECT m.id, m.mail_account_id, m.folder, m.subject, m.from_email, m.from_name, m.to_emails, m.received_at, m.sent_at, m.created_at,
                m.has_attachment, m.attachment_count, m.is_read, m.is_replied, m.is_starred, m.is_deleted, m.linked_customer_id, m.send_status, m.mail_source, m.message_uid,
                SUBSTR(COALESCE(m.body_text, \'\'), 1, 200) AS snippet,
                (SELECT COUNT(*) FROM crm_mail_attachments a WHERE a.mail_id = m.id AND COALESCE(a.is_inline, 0) = 0 AND COALESCE(a.is_signature_image, 0) = 0) AS visible_attachments,
                c.customer_name AS linked_customer_name' . str_replace(' FROM crm_mails m ', ' FROM crm_mails m LEFT JOIN crm_customers c ON c.id = m.linked_customer_id ', $sql)
            . ' ORDER BY COALESCE(m.received_at, m.sent_at, m.created_at) DESC, m.id DESC LIMIT ' . self::PER_PAGE . ' OFFSET ' . (($page - 1) * self::PER_PAGE));
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$row) {
            $row['is_outgoing'] = self::isOutgoing($row);
            $row['time'] = (string) ($row['is_outgoing'] ? ($row['sent_at'] ?: $row['created_at']) : ($row['received_at'] ?: $row['created_at']));
            $row['snippet'] = trim((string) preg_replace('/\s+/u', ' ', (string) $row['snippet']));
        }
        unset($row);
        return ['rows' => $rows, 'total' => $total, 'page' => $page, 'pages' => $pages];
    }

    /** 侧栏数字：未读、已删除。 @return array<string, int> */
    public function counts(int $accountId = 0): array
    {
        $params = [$this->ownerId];
        $acc = '';
        if ($accountId > 0) {
            $acc = ' AND mail_account_id = ?';
            $params[] = $accountId;
        }
        $stmt = $this->pdo->prepare("SELECT
                SUM(CASE WHEN is_deleted = 0 AND folder = 'inbox' AND is_read = 0 THEN 1 ELSE 0 END) AS inbox,
                SUM(CASE WHEN is_deleted = 0 AND folder <> 'deleted' AND is_read = 0 THEN 1 ELSE 0 END) AS unread,
                SUM(CASE WHEN is_deleted = 0 AND folder <> 'deleted' AND is_starred = 1 THEN 1 ELSE 0 END) AS starred
            FROM crm_mails WHERE user_id = ?" . $acc);
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        return array_map('intval', array_map(static function ($v) {
            return $v ?? 0;
        }, $row));
    }

    /**
     * 读一封信：邮件、附件、关联客户、建议关联的客户。自己读未读邮件时标为已读；读别人的邮件记日志。
     *
     * @return array<string, mixed>
     */
    public function read(int $id): array
    {
        $mail = $this->row($id);
        if ($this->isSelf()) {
            if ((int) $mail['is_read'] === 0) {
                $this->pdo->prepare('UPDATE crm_mails SET is_read = 1, updated_at = ? WHERE id = ?')->execute([Db::now(), $id]);
                $mail['is_read'] = 1;
            }
        } else {
            Audit::log('mail_view_other', 'mail', 'mail', $id, null, ['owner_user_id' => $this->ownerId, 'subject' => mb_substr((string) $mail['subject'], 0, 190, 'UTF-8')], $this->operator);
        }
        $mail['is_outgoing'] = self::isOutgoing($mail);
        $mail['attachments'] = $this->attachments($id, false);
        $mail['account_email'] = (string) ($this->pdo->query('SELECT email_address FROM crm_user_mail_accounts WHERE id = ' . (int) $mail['mail_account_id'])->fetchColumn() ?: '');
        $mail['customer'] = null;
        if (!empty($mail['linked_customer_id'])) {
            $stmt = $this->pdo->prepare('SELECT id, customer_name, country, deleted_at FROM crm_customers WHERE id = ?');
            $stmt->execute([(int) $mail['linked_customer_id']]);
            $c = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($c) {
                $mail['customer'] = $c + ['visible' => $this->customerVisible((int) $c['id'])];
            }
        }
        $mail['suggestions'] = empty($mail['linked_customer_id']) && $this->isSelf() ? $this->suggestions($mail) : [];
        return $mail;
    }

    /** 邮件原始行（不标已读、不记日志），正文页用。 @return array<string, mixed> */
    public function row(int $id): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM crm_mails WHERE id = ? AND user_id = ?');
        $stmt->execute([$id, $this->ownerId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new NotFoundException('邮件不存在或无权查看');
        }
        return $row;
    }

    /**
     * 附件。$all 为 false 时只列正常附件（不含内嵌图片和签名图片）。
     *
     * @return list<array<string, mixed>>
     */
    public function attachments(int $mailId, bool $all): array
    {
        $stmt = $this->pdo->prepare('SELECT id, mail_id, file_name, original_filename, file_path, file_size, mime_type, content_id, is_inline, is_signature_image FROM crm_mail_attachments WHERE mail_id = ?'
            . ($all ? '' : ' AND COALESCE(is_inline, 0) = 0 AND COALESCE(is_signature_image, 0) = 0') . ' ORDER BY id');
        $stmt->execute([$mailId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$r) {
            $r['name'] = self::attachmentName($r);
            unset($r['file_path']);
        }
        unset($r);
        return $rows;
    }

    /** 正文图片替换用：全部附件的编号、content_id、文件路径。 @return list<array<string, mixed>> */
    public function bodyAttachments(int $mailId): array
    {
        $this->row($mailId);
        $stmt = $this->pdo->prepare('SELECT id, content_id, file_path FROM crm_mail_attachments WHERE mail_id = ? ORDER BY id');
        $stmt->execute([$mailId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * 取一个附件准备下载。正常附件要 mail.attachment_download；内嵌图片（正文里显示）只要能看这封信。
     *
     * @return array{name: string, path: string, mime: string, size: int, inline: bool}
     */
    public function attachmentFile(int $attachmentId, bool $inline): array
    {
        $stmt = $this->pdo->prepare('SELECT a.*, m.user_id AS owner_id FROM crm_mail_attachments a JOIN crm_mails m ON m.id = a.mail_id WHERE a.id = ?');
        $stmt->execute([$attachmentId]);
        $a = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$a || (int) $a['owner_id'] !== $this->ownerId) {
            throw new NotFoundException('附件不存在或无权查看');
        }
        $mime = strtolower((string) ($a['mime_type'] ?: 'application/octet-stream'));
        $isImage = strpos($mime, 'image/') === 0;
        $embedded = (int) $a['is_inline'] === 1 || (int) $a['is_signature_image'] === 1;
        $inline = $inline && $isImage;
        if (!($inline && ($embedded || $isImage)) && !Permission::allows($this->operator, 'mail.attachment_download')) {
            throw new PermissionException('没有下载邮件附件的权限（mail.attachment_download）');
        }
        $path = self::safePath((string) $a['file_path']);
        if ($path === null) {
            throw new NotFoundException('附件文件已不在服务器上');
        }
        if (!$this->isSelf() && !$inline) {
            Audit::log('mail_attachment_view_other', 'mail', 'mail_attachment', $attachmentId, null, ['owner_user_id' => $this->ownerId, 'mail_id' => (int) $a['mail_id']], $this->operator);
        }
        return ['name' => self::attachmentName($a), 'path' => $path, 'mime' => $mime, 'size' => (int) filesize($path), 'inline' => $inline];
    }

    /** @param list<mixed> $ids */
    public function setRead(array $ids, bool $read): int
    {
        return $this->update($ids, 'is_read = ?', [$read ? 1 : 0], 'mail.view', '标记');
    }

    /** @param list<mixed> $ids */
    public function setStarred(array $ids, bool $starred): int
    {
        return $this->update($ids, 'is_starred = ?', [$starred ? 1 : 0], 'mail.view', '加星标');
    }

    /** @param list<mixed> $ids */
    public function delete(array $ids): int
    {
        $n = $this->update($ids, 'is_deleted = 1', [], 'mail.delete', '删除邮件');
        Audit::log('mail_delete', 'mail', 'mail', self::ids($ids)[0] ?? null, null, ['count' => $n, 'ids' => array_slice(self::ids($ids), 0, 200)], $this->operator);
        return $n;
    }

    /** 恢复。旧系统删除时把文件夹改成了 deleted，这类按原来的来源恢复到收件箱或已发送。 @param list<mixed> $ids */
    public function restore(array $ids): int
    {
        $n = $this->update($ids, "is_deleted = 0, folder = CASE WHEN folder = 'deleted' THEN (CASE WHEN mail_source = 'crm_sent' OR SUBSTR(message_uid, 1, 5) = 'send_' OR crm_send_id IS NOT NULL THEN 'sent' ELSE 'inbox' END) ELSE folder END", [], 'mail.delete', '恢复邮件');
        Audit::log('mail_restore', 'mail', 'mail', self::ids($ids)[0] ?? null, null, ['count' => $n, 'ids' => array_slice(self::ids($ids), 0, 200)], $this->operator);
        return $n;
    }

    /** 关联客户（$customerId 为 0 表示取消关联）。客户必须是自己能看到的。 */
    public function linkCustomer(int $mailId, int $customerId): void
    {
        if (!$this->can('mail.link_customer')) {
            throw new PermissionException($this->isSelf() ? '没有关联客户的权限（mail.link_customer）' : '只能查看别人的邮件，不能修改');
        }
        $mail = $this->row($mailId);
        $contactId = null;
        if ($customerId > 0) {
            (new CustomerService($this->operator, $this->pdo))->find($customerId);
            $emails = self::addresses((string) $mail['from_email'] . ',' . (string) $mail['to_emails'] . ',' . (string) $mail['cc_emails']);
            if ($emails) {
                $stmt = $this->pdo->prepare('SELECT id FROM crm_contacts WHERE customer_id = ? AND deleted_at IS NULL AND LOWER(email) IN (' . implode(',', array_fill(0, count($emails), '?')) . ') ORDER BY id LIMIT 1');
                $stmt->execute(array_merge([$customerId], $emails));
                $contactId = ($v = $stmt->fetchColumn()) ? (int) $v : null;
            }
        }
        $this->pdo->prepare('UPDATE crm_mails SET linked_customer_id = ?, linked_contact_id = ?, updated_at = ? WHERE id = ?')
            ->execute([$customerId > 0 ? $customerId : null, $contactId, Db::now(), $mailId]);
        Audit::log($customerId > 0 ? 'mail_link_customer' : 'mail_unlink_customer', 'mail', 'mail', $mailId, ['linked_customer_id' => $mail['linked_customer_id']], ['linked_customer_id' => $customerId ?: null, 'linked_contact_id' => $contactId], $this->operator);
    }

    /**
     * 建议关联的客户：邮件里的地址等于客户邮箱或联系人邮箱（精确），或者对方域名等于客户域名（公共邮箱除外）。
     * 只返回操作人能看到的客户，最多 5 个。
     *
     * @return list<array{id: int, name: string, reason: string}>
     */
    public function suggestions(array $mail): array
    {
        $own = array_map('strtolower', array_column($this->accounts(), 'email_address'));
        $emails = array_values(array_diff(self::addresses((string) $mail['from_email'] . ',' . (string) $mail['to_emails'] . ',' . (string) ($mail['cc_emails'] ?? '')), $own));
        if (!$emails) {
            return [];
        }
        $in = implode(',', array_fill(0, count($emails), '?'));
        $found = [];
        $stmt = $this->pdo->prepare("SELECT id, customer_name FROM crm_customers WHERE deleted_at IS NULL AND (LOWER(email) IN ({$in}) OR LOWER(backup_email) IN ({$in})) LIMIT 10");
        $stmt->execute(array_merge($emails, $emails));
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $found[(int) $r['id']] = ['id' => (int) $r['id'], 'name' => (string) $r['customer_name'], 'reason' => '客户邮箱相同'];
        }
        $stmt = $this->pdo->prepare("SELECT c.id, c.customer_name, ct.name, ct.email FROM crm_contacts ct JOIN crm_customers c ON c.id = ct.customer_id WHERE ct.deleted_at IS NULL AND c.deleted_at IS NULL AND LOWER(ct.email) IN ({$in}) LIMIT 10");
        $stmt->execute($emails);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $found[(int) $r['id']] = $found[(int) $r['id']] ?? ['id' => (int) $r['id'], 'name' => (string) $r['customer_name'], 'reason' => '联系人 ' . $r['name'] . ' 的邮箱'];
        }
        $domains = array_values(array_diff(array_unique(array_map(static function (string $e): string {
            return substr($e, strpos($e, '@') + 1);
        }, $emails)), self::PUBLIC_DOMAINS, array_map(static function (string $e): string {
            return substr($e, strpos($e, '@') + 1);
        }, $own)));
        if ($domains && count($found) < 5) {
            $din = implode(',', array_fill(0, count($domains), '?'));
            $stmt = $this->pdo->prepare("SELECT id, customer_name FROM crm_customers WHERE deleted_at IS NULL AND LOWER(customer_domain) IN ({$din}) LIMIT 10");
            $stmt->execute($domains);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $found[(int) $r['id']] = $found[(int) $r['id']] ?? ['id' => (int) $r['id'], 'name' => (string) $r['customer_name'], 'reason' => '邮箱域名相同'];
            }
        }
        $out = [];
        foreach ($found as $id => $s) {
            if (count($out) < 5 && $this->customerVisible($id)) {
                $out[] = $s;
            }
        }
        return $out;
    }

    /** 是否是发出的邮件。 */
    public static function isOutgoing(array $row): bool
    {
        return (string) $row['folder'] === 'sent' || (string) ($row['mail_source'] ?? '') === 'crm_sent' || strpos((string) ($row['message_uid'] ?? ''), 'send_') === 0;
    }

    /** 从地址串里取出所有邮箱（小写、去重）。 @return list<string> */
    public static function addresses(string $text): array
    {
        preg_match_all('/[A-Za-z0-9._%+\'-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}/', $text, $m);
        return array_values(array_unique(array_map('strtolower', $m[0])));
    }

    /** 地址串里的显示名或邮箱，列表用。 */
    public static function displayAddresses(string $text, int $max = 2): string
    {
        $parts = [];
        foreach (preg_split('/[,;]\s*(?=(?:[^"]*"[^"]*")*[^"]*$)/', $text) ?: [] as $p) {
            $p = trim($p);
            if ($p === '') {
                continue;
            }
            if (preg_match('/^"?([^"<]*)"?\s*<([^>]+)>$/', $p, $m)) {
                $p = trim($m[1]) !== '' ? trim($m[1]) : trim($m[2]);
            }
            $parts[] = $p;
        }
        $more = count($parts) - $max;
        return implode('、', array_slice($parts, 0, $max)) . ($more > 0 ? ' 等 ' . count($parts) . ' 人' : '');
    }

    /** 附件文件名。 */
    public static function attachmentName(array $a): string
    {
        foreach (['original_filename', 'file_name', 'filename'] as $k) {
            $v = trim((string) ($a[$k] ?? ''));
            if ($v !== '') {
                return $v;
            }
        }
        return '附件-' . (int) $a['id'];
    }

    /** 附件文件必须在存放目录里（旧系统存的是完整路径）。 */
    public static function safePath(string $path): ?string
    {
        if ($path === '') {
            return null;
        }
        $root = realpath((string) Config::get('mail.storage_root', ARTDON_APP_ROOT . '/storage'));
        $real = realpath($path);
        if ($root === false || $real === false || !is_file($real) || strpos($real, $root . DIRECTORY_SEPARATOR) !== 0) {
            return null;
        }
        return $real;
    }

    /** @return array{0: list<string>, 1: list<mixed>} */
    private function where(string $folder, array $f): array
    {
        $where = ['m.user_id = ?'];
        $params = [$this->ownerId];
        $account = (int) ($f['account'] ?? 0);
        if ($account > 0) {
            $where[] = 'm.mail_account_id = ?';
            $params[] = $account;
        }
        if ($folder === 'deleted') {
            $where[] = "(m.is_deleted = 1 OR m.folder = 'deleted')";
        } else {
            $where[] = "m.is_deleted = 0 AND m.folder <> 'deleted'";
        }
        switch ($folder) {
            case 'inbox':
                $where[] = "m.folder = 'inbox'";
                break;
            case 'sent':
                $where[] = "m.folder = 'sent'";
                $hidden = $this->hiddenSentIds($account);
                if ($hidden) {
                    $where[] = 'm.id NOT IN (' . implode(',', $hidden) . ')';
                }
                break;
            case 'unread':
                $where[] = 'm.is_read = 0';
                break;
            case 'starred':
                $where[] = 'm.is_starred = 1';
                break;
            case 'attachments':
                $where[] = self::VISIBLE_ATTACHMENT;
                break;
            case 'linked':
                $where[] = 'm.linked_customer_id IS NOT NULL';
                break;
            case 'unlinked':
                $where[] = 'm.linked_customer_id IS NULL';
                break;
            case 'unreplied':
                $where[] = "m.folder = 'sent' AND COALESCE(m.followup_status, 'open') NOT IN ('replied', 'done', 'no_follow')
                    AND COALESCE(m.sent_at, m.created_at) <= ? AND COALESCE(m.sent_at, m.created_at) >= ?
                    AND NOT EXISTS (SELECT 1 FROM crm_mails r WHERE r.user_id = m.user_id AND r.mail_account_id = m.mail_account_id AND r.folder = 'inbox' AND r.is_deleted = 0
                        AND COALESCE(r.received_at, r.created_at) > COALESCE(m.sent_at, m.created_at)
                        AND ((m.linked_customer_id IS NOT NULL AND r.linked_customer_id = m.linked_customer_id)
                            OR (r.from_email <> '' AND INSTR(LOWER(COALESCE(m.to_emails, '')), LOWER(r.from_email)) > 0)))";
                $params[] = date('Y-m-d H:i:s', time() - self::UNREPLIED_DAYS * 86400);
                $params[] = date('Y-m-d H:i:s', time() - 60 * 86400);
                $hidden = $this->hiddenSentIds($account);
                if ($hidden) {
                    $where[] = 'm.id NOT IN (' . implode(',', $hidden) . ')';
                }
                break;
        }
        $q = trim((string) ($f['q'] ?? ''));
        if ($q !== '') {
            $like = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_substr($q, 0, 100, 'UTF-8')) . '%';
            $where[] = "(m.subject LIKE ? ESCAPE '!' OR m.from_email LIKE ? ESCAPE '!' OR m.from_name LIKE ? ESCAPE '!' OR m.to_emails LIKE ? ESCAPE '!' OR m.body_text LIKE ? ESCAPE '!'
                OR EXISTS (SELECT 1 FROM crm_mail_attachments a WHERE a.mail_id = m.id AND a.file_name LIKE ? ESCAPE '!'))";
            array_push($params, $like, $like, $like, $like, $like, $like);
        }
        $read = (string) ($f['read'] ?? '');
        if ($read === 'read' || $read === 'unread') {
            $where[] = 'm.is_read = ' . ($read === 'read' ? 1 : 0);
        }
        $days = ['today' => 0, '7d' => 7, '30d' => 30][(string) ($f['period'] ?? '')] ?? null;
        if ($days !== null) {
            $where[] = 'COALESCE(m.received_at, m.sent_at, m.created_at) >= ?';
            $params[] = $days === 0 ? date('Y-m-d 00:00:00') : date('Y-m-d H:i:s', time() - $days * 86400);
        }
        return [$where, $params];
    }

    /**
     * 已发送里要隐藏的重复记录（与旧系统 crm_mail_sent_duplicate_query 相同的规则，改在 PHP 里算）：
     * 1. Message-ID 相同的多条只留编号最小的一条；
     * 2. 从服务器同步回来的副本，如果有本系统发出的记录与它主题相同、且收件人相同或正文相同、时间相差 10 分钟内，或 Message-ID 对得上，隐藏副本。
     *
     * @return list<int>
     */
    public function hiddenSentIds(int $accountId = 0): array
    {
        $sql = "SELECT id, mail_account_id, message_uid, message_id_header, crm_send_id, mail_source, subject, to_emails, body_hash, COALESCE(sent_at, created_at) AS t
            FROM crm_mails WHERE user_id = ? AND folder = 'sent' AND is_deleted = 0";
        $params = [$this->ownerId];
        if ($accountId > 0) {
            $sql .= ' AND mail_account_id = ?';
            $params[] = $accountId;
        }
        $stmt = $this->pdo->prepare($sql . ' ORDER BY id');
        $stmt->execute($params);
        $byAccount = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $r['key'] = strtolower(str_replace(['<', '>'], '', trim((string) $r['message_id_header'])));
            $r['ts'] = strtotime((string) $r['t']) ?: 0;
            $r['crm'] = strpos((string) $r['message_uid'], 'send_') === 0 || $r['crm_send_id'] !== null || (string) $r['mail_source'] === 'crm_sent';
            $byAccount[(int) $r['mail_account_id']][] = $r;
        }
        $hidden = [];
        foreach ($byAccount as $rows) {
            $seen = [];
            $senders = ['subject' => [], 'key' => []];
            foreach ($rows as $r) {
                if ($r['crm']) {
                    $senders['subject'][(string) $r['subject']][] = $r;
                    if ($r['key'] !== '') {
                        $senders['key'][$r['key']][] = $r;
                    }
                }
            }
            foreach ($rows as $r) {
                if ($r['key'] !== '') {
                    if (isset($seen[$r['key']])) {
                        $hidden[] = (int) $r['id'];
                        continue;
                    }
                    $seen[$r['key']] = true;
                }
                if (strpos((string) $r['message_uid'], 'send_') === 0) {
                    continue;
                }
                $dup = false;
                foreach ($senders['subject'][(string) $r['subject']] ?? [] as $s) {
                    if ($s['id'] !== $r['id'] && abs($s['ts'] - $r['ts']) <= 600
                        && ((string) $s['to_emails'] === (string) $r['to_emails'] || ((string) $r['body_hash'] !== '' && (string) $s['body_hash'] === (string) $r['body_hash']))) {
                        $dup = true;
                        break;
                    }
                }
                if (!$dup && $r['key'] !== '') {
                    $candidates = $senders['key'][$r['key']] ?? [];
                    if (!$candidates && preg_match('/crm-\d{14}-[0-9a-f]+@[a-z0-9.-]+/', $r['key'], $m)) {
                        $candidates = $senders['key'][$m[0]] ?? [];
                    }
                    foreach ($candidates as $s) {
                        if ($s['id'] !== $r['id']) {
                            $dup = true;
                            break;
                        }
                    }
                }
                if ($dup) {
                    $hidden[] = (int) $r['id'];
                }
            }
        }
        return $hidden;
    }

    private function customerVisible(int $customerId): bool
    {
        try {
            (new CustomerService($this->operator, $this->pdo))->find($customerId);
            return true;
        } catch (NotFoundException $e) {
            return false;
        }
    }

    /** @param list<mixed> $ids @param list<mixed> $params */
    private function update(array $ids, string $set, array $params, string $permission, string $label): int
    {
        if (!$this->isSelf()) {
            throw new PermissionException('只能查看别人的邮件，不能修改');
        }
        if (!Permission::allows($this->operator, $permission)) {
            throw new PermissionException('没有' . $label . '的权限（' . $permission . '）');
        }
        $ids = self::ids($ids);
        if (!$ids) {
            throw new \RuntimeException('请先选择邮件');
        }
        $stmt = $this->pdo->prepare('UPDATE crm_mails SET ' . $set . ', updated_at = ? WHERE user_id = ? AND id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')');
        $stmt->execute(array_merge($params, [Db::now(), $this->ownerId], $ids));
        return $stmt->rowCount();
    }

    /** @param list<mixed> $ids @return list<int> */
    private static function ids(array $ids): array
    {
        return array_values(array_unique(array_filter(array_map('intval', array_filter($ids, 'is_scalar')), static function (int $v): bool {
            return $v > 0;
        })));
    }
}
