<?php
declare(strict_types=1);

namespace Artdon\Centers\Base;

use Throwable;

/**
 * 写操作日志（crm_audit_logs）和登录日志（crm_login_logs）。
 * 日志写入失败不影响业务操作，只记到 PHP 错误日志。
 */
final class Audit
{
    /**
     * @param array<string, mixed>|null $before
     * @param array<string, mixed>|null $after
     * @param array<string, mixed>|null $operator 默认当前登录用户
     */
    public static function log(string $action, string $module, string $targetType = '', $targetId = null, ?array $before = null, ?array $after = null, ?array $operator = null): void
    {
        try {
            $operator = $operator ?? Auth::user();
            Db::pdo()->prepare(
                'INSERT INTO crm_audit_logs (user_id, operator_name, action, module, target_type, target_id, before_json, after_json, ip, user_agent, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            )->execute([
                $operator['id'] ?? null,
                $operator['username'] ?? 'system',
                $action,
                $module,
                $targetType,
                $targetId === null ? null : (string) $targetId,
                $before === null ? null : json_encode($before, JSON_UNESCAPED_UNICODE),
                $after === null ? null : json_encode($after, JSON_UNESCAPED_UNICODE),
                self::ip(),
                self::userAgent(),
                Db::now(),
            ]);
        } catch (Throwable $e) {
            error_log('centers audit log failed: ' . $e->getMessage());
        }
    }

    /** status：success、fail、logout */
    public static function login(string $username, string $status, string $message = '', ?int $userId = null): void
    {
        try {
            Db::pdo()->prepare('INSERT INTO crm_login_logs (user_id, username, status, ip, user_agent, message, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)')
                ->execute([$userId, mb_substr($username, 0, 80, 'UTF-8'), $status, self::ip(), self::userAgent(), mb_substr('[新系统] ' . $message, 0, 500, 'UTF-8'), Db::now()]);
        } catch (Throwable $e) {
            error_log('centers login log failed: ' . $e->getMessage());
        }
    }

    public static function ip(): string
    {
        return substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 64);
    }

    public static function userAgent(): string
    {
        return substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500);
    }
}
