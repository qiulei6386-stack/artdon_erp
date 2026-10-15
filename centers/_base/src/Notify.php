<?php
declare(strict_types=1);

namespace Artdon\Centers\Base;

use PDO;
use Throwable;

/**
 * 站内通知。写入正式库已有的 sys_notifications 表，只用建表时就有的字段
 * （user_id、type、title、content、payload_json、read_at、created_at）。
 *
 * 新系统的通知 type 都以“centers.”开头，新系统只显示这一类，旧系统写的通知不在新系统里出现
 * （旧通知的链接指向旧页面）。payload_json 里存 {center, path}，显示时拼成新系统地址。
 *
 * 发送失败不影响业务：send() 不抛异常，只返回成功条数。
 */
final class Notify
{
    public const TYPE_PREFIX = 'centers.';
    public const PER_PAGE = 30;

    /** @var array<int, int> */
    private static $unread = [];

    /**
     * @param list<int> $userIds 接收人（自动去重、去掉 0）
     * @param string $type 不带前缀的类型，如 permission_request
     * @param array{center?: string, path?: string} $link 点开后去的页面
     */
    public static function send(array $userIds, string $type, string $title, string $content = '', array $link = []): int
    {
        $userIds = array_values(array_unique(array_filter(array_map('intval', $userIds))));
        if (!$userIds) {
            return 0;
        }
        try {
            if (!Db::tableExists('sys_notifications')) {
                return 0;
            }
            $payload = json_encode([
                'center' => (string) ($link['center'] ?? ''),
                'path' => (string) ($link['path'] ?? ''),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $stmt = Db::pdo()->prepare('INSERT INTO sys_notifications (user_id, type, title, content, payload_json, created_at) VALUES (?, ?, ?, ?, ?, ?)');
            $now = Db::now();
            $sent = 0;
            foreach ($userIds as $userId) {
                $stmt->execute([$userId, self::TYPE_PREFIX . $type, mb_substr($title, 0, 180, 'UTF-8'), mb_substr($content, 0, 800, 'UTF-8'), $payload, $now]);
                $sent++;
                unset(self::$unread[$userId]);
            }
            return $sent;
        } catch (Throwable $e) {
            return 0;
        }
    }

    public static function unreadCount(int $userId): int
    {
        if ($userId <= 0) {
            return 0;
        }
        if (!array_key_exists($userId, self::$unread)) {
            try {
                if (!Db::tableExists('sys_notifications')) {
                    return self::$unread[$userId] = 0;
                }
                $stmt = Db::pdo()->prepare('SELECT COUNT(*) FROM sys_notifications WHERE user_id = ? AND type LIKE ? AND read_at IS NULL');
                $stmt->execute([$userId, self::TYPE_PREFIX . '%']);
                self::$unread[$userId] = (int) $stmt->fetchColumn();
            } catch (Throwable $e) {
                self::$unread[$userId] = 0;
            }
        }
        return self::$unread[$userId];
    }

    /** @return array{rows: list<array<string, mixed>>, total: int, page: int, pages: int} */
    public static function list(int $userId, int $page = 1, bool $unreadOnly = false): array
    {
        $empty = ['rows' => [], 'total' => 0, 'page' => 1, 'pages' => 1];
        if ($userId <= 0 || !Db::tableExists('sys_notifications')) {
            return $empty;
        }
        $where = 'user_id = ? AND type LIKE ?' . ($unreadOnly ? ' AND read_at IS NULL' : '');
        $params = [$userId, self::TYPE_PREFIX . '%'];
        $pdo = Db::pdo();
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM sys_notifications WHERE ' . $where);
        $stmt->execute($params);
        $total = (int) $stmt->fetchColumn();
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $page = max(1, min($page, $pages));
        $stmt = $pdo->prepare('SELECT id, type, title, content, payload_json, read_at, created_at FROM sys_notifications WHERE ' . $where . ' ORDER BY id DESC LIMIT ' . self::PER_PAGE . ' OFFSET ' . (($page - 1) * self::PER_PAGE));
        $stmt->execute($params);
        $rows = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $row['url'] = self::url((string) ($row['payload_json'] ?? ''));
            $rows[] = $row;
        }
        return ['rows' => $rows, 'total' => $total, 'page' => $page, 'pages' => $pages];
    }

    /** 找到自己的一条通知（不存在或不是自己的返回 null）。 */
    public static function find(int $userId, int $id): ?array
    {
        if ($userId <= 0 || $id <= 0 || !Db::tableExists('sys_notifications')) {
            return null;
        }
        $stmt = Db::pdo()->prepare('SELECT id, type, title, content, payload_json, read_at, created_at FROM sys_notifications WHERE id = ? AND user_id = ? AND type LIKE ? LIMIT 1');
        $stmt->execute([$id, $userId, self::TYPE_PREFIX . '%']);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }
        $row['url'] = self::url((string) ($row['payload_json'] ?? ''));
        return $row;
    }

    /** @param list<int> $ids 只会标记自己的 */
    public static function markRead(int $userId, array $ids): int
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if ($userId <= 0 || !$ids || !Db::tableExists('sys_notifications')) {
            return 0;
        }
        $marks = implode(',', array_fill(0, count($ids), '?'));
        $stmt = Db::pdo()->prepare('UPDATE sys_notifications SET read_at = ? WHERE user_id = ? AND type LIKE ? AND read_at IS NULL AND id IN (' . $marks . ')');
        $stmt->execute(array_merge([Db::now(), $userId, self::TYPE_PREFIX . '%'], $ids));
        unset(self::$unread[$userId]);
        return $stmt->rowCount();
    }

    public static function markAllRead(int $userId): int
    {
        if ($userId <= 0 || !Db::tableExists('sys_notifications')) {
            return 0;
        }
        $stmt = Db::pdo()->prepare('UPDATE sys_notifications SET read_at = ? WHERE user_id = ? AND type LIKE ? AND read_at IS NULL');
        $stmt->execute([Db::now(), $userId, self::TYPE_PREFIX . '%']);
        unset(self::$unread[$userId]);
        return $stmt->rowCount();
    }

    public static function clearCache(): void
    {
        self::$unread = [];
    }

    /** 由 payload 拼出新系统地址；只接受新系统里的相对路径。 */
    private static function url(string $payload): string
    {
        $data = json_decode($payload, true);
        if (!is_array($data)) {
            return '';
        }
        $center = (string) ($data['center'] ?? '');
        $path = (string) ($data['path'] ?? '');
        if (!preg_match('/^[a-z_]+$/', $center) || $path === '' || preg_match('#(^/|//|\.\.|:|\\\\)#', $path)) {
            return '';
        }
        return Url::center($center, $path);
    }
}
