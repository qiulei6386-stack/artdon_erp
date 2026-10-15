<?php
declare(strict_types=1);

namespace Artdon\Centers\Customer;

use Artdon\Centers\Base\Audit;
use Artdon\Centers\Base\Db;
use PDO;

/**
 * 客户日志。写到旧系统也在用的地方，两边看到的客户历史一致：
 *   crm_logs（客户日志，module = customer）、crm_customer_timeline（时间轴）、
 *   crm_sensitive_audit_logs（敏感操作）以及新系统的 crm_audit_logs。
 */
final class CustomerLog
{
    public const ACTION_LABELS = [
        'customer_create' => '新建客户',
        'customer_update' => '编辑客户',
        'customer_delete' => '删除客户',
        'customer_restore' => '恢复客户',
        'customer_force_delete' => '强制删除客户',
        'customer_batch_assign' => '分配负责人',
        'customer_assign' => '分配负责人',
        'customer_public_pool' => '转入公海',
        'customer_claim_public' => '领取公海客户',
        'customer_merge' => '合并客户',
        'lead_pool_create' => '进入暂存池',
        'lead_pool_confirm' => '暂存池确认入库',
        'lead_pool_attach' => '暂存客户关联到此客户',
        'lead_pool_reject' => '丢弃暂存客户',
        'contact_create' => '新增联系人',
        'contact_update' => '编辑联系人',
        'contact_delete' => '删除联系人',
        'followup_create' => '新增跟进',
        'followup_update' => '编辑跟进',
        'followup_delete' => '删除跟进',
        'address_save' => '保存地址',
        'tag_save' => '修改标签',
    ];

    private const SENSITIVE = ['customer_delete', 'customer_restore', 'customer_assign', 'customer_public_pool', 'customer_claim_public', 'customer_merge'];

    /**
     * @param array<string, mixed>|null $before
     * @param array<string, mixed>|null $after
     */
    public static function write(string $action, int $customerId, string $title, string $detail, ?array $before, ?array $after, array $operator, string $objectType = 'customer', string $objectId = ''): void
    {
        $pdo = Db::pdo();
        $userId = ((int) ($operator['id'] ?? 0)) ?: null;
        $now = Db::now();
        $objectId = mb_substr($objectId !== '' ? $objectId : (string) $customerId, 0, 120, 'UTF-8');
        $json = static function (?array $v): ?string {
            return $v === null ? null : (string) json_encode($v, JSON_UNESCAPED_UNICODE);
        };
        if (Db::tableExists('crm_logs')) {
            $pdo->prepare('INSERT INTO crm_logs (user_id, module, action, object_type, object_id, customer_id, before_json, after_json, message, ip_address, user_agent, success, error_message, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, \'\', ?)')
                ->execute([$userId, 'customer', $action, $objectType, $objectId, $customerId ?: null, $json($before), $json($after), mb_substr('[新系统] ' . $title . ($detail !== '' ? '：' . $detail : ''), 0, 500, 'UTF-8'), Audit::ip(), Audit::userAgent(), $now]);
        }
        if ($customerId > 0 && Db::tableExists('crm_customer_timeline')) {
            $pdo->prepare('INSERT INTO crm_customer_timeline (customer_id, event_type, title, detail, related_type, related_id, event_time, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')
                ->execute([$customerId, $action, mb_substr($title, 0, 190, 'UTF-8'), mb_substr($detail, 0, 1000, 'UTF-8'), $objectType, $objectId, $now, $userId, $now]);
        }
        if (in_array($action, self::SENSITIVE, true) && Db::tableExists('crm_sensitive_audit_logs')) {
            $pdo->prepare('INSERT INTO crm_sensitive_audit_logs (user_id, action_key, target_type, target_id, before_json, after_json, ip_address, user_agent, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')
                ->execute([$userId, $action, $objectType, $objectId, $json($before), $json($after), Audit::ip(), Audit::userAgent(), $now]);
        }
        Audit::log($action, 'customer', $objectType, $objectId, $before, $after, $operator);
    }

    /**
     * 客户的日志（新旧系统写的都有），新的在前。
     *
     * @return list<array<string, mixed>>
     */
    public static function forCustomer(int $customerId, int $limit = 200): array
    {
        if (!Db::tableExists('crm_logs')) {
            return [];
        }
        $stmt = Db::pdo()->prepare('SELECT l.id, l.action, l.object_type, l.object_id, l.message, l.before_json, l.after_json, l.created_at, COALESCE(NULLIF(u.real_name, \'\'), u.username) AS user_name
            FROM crm_logs l LEFT JOIN crm_users u ON u.id = l.user_id WHERE l.customer_id = ? ORDER BY l.id DESC LIMIT ' . max(1, min(500, $limit)));
        $stmt->execute([$customerId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function label(string $action): string
    {
        return self::ACTION_LABELS[$action] ?? $action;
    }
}
