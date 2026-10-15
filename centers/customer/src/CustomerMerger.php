<?php
declare(strict_types=1);

namespace Artdon\Centers\Customer;

use Artdon\Centers\Base\Db;
use Artdon\Centers\Base\Permission;
use Artdon\Centers\Base\PermissionException;
use Artdon\Centers\Base\ValidationException;
use PDO;
use Throwable;

/**
 * 合并客户（customer.merge）。与旧系统相同的做法：保留一个主客户，其他客户的联系人、地址、跟进、文件、拜访、商机、
 * 推广、邮件、报价订单等关联记录全部转到主客户，其他客户软删除，删除原因写“合并到客户 #主客户编号：名称”。
 * 新系统另外做两件事：主客户空着的基本资料用被合并客户的补上；合并后只保留一个主联系人。
 */
final class CustomerMerger
{
    public const MAX = 10;

    /** 直接把 customer_id 改成主客户的表。 */
    private const MOVE_TABLES = ['crm_contacts', 'crm_customer_addresses', 'crm_customer_chat_groups', 'crm_customer_followups', 'crm_customer_files', 'crm_customer_events',
        'crm_customer_timeline', 'crm_logs', 'crm_tasks', 'crm_visit_records', 'crm_visit_files', 'crm_opportunities', 'crm_opportunity_logs', 'crm_opportunity_files',
        'crm_marketing_send_queue', 'crm_marketing_task_targets', 'crm_marketing_logs'];

    /** 每个客户只能有一条（或按键唯一）的表：复制到主客户（已有则跳过）后删掉原来的。 */
    private const UNIQUE_TABLES = ['crm_customer_source_tags', 'crm_customer_promotion_channels', 'crm_customer_group_relations', 'crm_customer_product_preferences',
        'crm_customer_communication_preferences', 'crm_customer_protection', 'crm_customer_promotion_status', 'crm_customer_scores', 'crm_marketing_group_customers'];

    /** 主客户为空时用被合并客户补上的字段。 */
    private const FILL_FIELDS = ['customer_name_en', 'city', 'address', 'website', 'email', 'phone', 'whatsapp', 'wechat', 'linkedin', 'remark'];

    /** @var PDO */
    private $pdo;

    /** @var array<string, mixed> */
    private $operator;

    public function __construct(array $operator, ?PDO $pdo = null)
    {
        $this->operator = $operator;
        $this->pdo = $pdo ?? Db::pdo();
    }

    /**
     * @param list<int> $ids 要合并的客户（含主客户）
     * @return array{master_id: int, merged_ids: list<int>, moved: array<string, int>, filled: list<string>}
     */
    public function merge(array $ids, int $masterId): array
    {
        if (!Permission::allows($this->operator, 'customer.merge')) {
            throw new PermissionException('没有合并客户的权限（customer.merge）');
        }
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if (count($ids) < 2) {
            throw new ValidationException(['ids' => '至少选 2 个客户才能合并']);
        }
        if (count($ids) > self::MAX) {
            throw new ValidationException(['ids' => '一次最多合并 ' . self::MAX . ' 个客户']);
        }
        if (!in_array($masterId, $ids, true)) {
            throw new ValidationException(['master_id' => '请选择要保留的主客户']);
        }
        $service = new CustomerService($this->operator, $this->pdo);
        $rows = [];
        foreach ($ids as $id) {
            $rows[$id] = $service->find($id);
        }
        $master = $rows[$masterId];
        $dupIds = array_values(array_diff($ids, [$masterId]));
        $me = (int) $this->operator['id'];
        $now = Db::now();
        $moved = [];
        $filled = [];
        $this->pdo->beginTransaction();
        try {
            foreach ($dupIds as $fromId) {
                $from = $rows[$fromId];
                foreach (self::MOVE_TABLES as $table) {
                    $moved[$table] = ($moved[$table] ?? 0) + $this->moveColumn($table, 'customer_id', $fromId, $masterId);
                }
                foreach (self::UNIQUE_TABLES as $table) {
                    $moved[$table] = ($moved[$table] ?? 0) + $this->copyUnique($table, $fromId, $masterId);
                }
                $moved['crm_customer_owners'] = ($moved['crm_customer_owners'] ?? 0) + $this->copyOwners($fromId, $masterId);
                $moved['crm_customer_relations'] = ($moved['crm_customer_relations'] ?? 0) + $this->copyRelations($fromId, $masterId);
                foreach ($this->externalLinks($fromId, $masterId) as $table => $n) {
                    $moved[$table] = ($moved[$table] ?? 0) + $n;
                }
                foreach (self::FILL_FIELDS as $field) {
                    if (array_key_exists($field, $master) && trim((string) $master[$field]) === '' && trim((string) ($from[$field] ?? '')) !== '') {
                        $master[$field] = $from[$field];
                        $filled[] = $field;
                    }
                }
                $this->pdo->prepare('UPDATE crm_customers SET deleted_at = ?, deleted_by = ?, delete_reason = ?, updated_by = ?, updated_at = ? WHERE id = ? AND deleted_at IS NULL')
                    ->execute([$now, $me, mb_substr('合并到客户 #' . $masterId . '：' . $master['customer_name'], 0, 500, 'UTF-8'), $me, $now, $fromId]);
            }
            $filled = array_values(array_unique($filled));
            if ($filled) {
                $set = implode(', ', array_map(static function (string $f): string {
                    return $f . ' = ?';
                }, $filled));
                $params = array_map(static function (string $f) use ($master) {
                    return $master[$f];
                }, $filled);
                $params[] = $masterId;
                $this->pdo->prepare('UPDATE crm_customers SET ' . $set . ' WHERE id = ?')->execute($params);
            }
            $this->fixPrimaryContact($masterId);
            $this->fixPrimaryAddress($masterId);
            $this->fixOwners($masterId, (int) ($master['owner_user_id'] ?? 0));
            $this->pdo->prepare('UPDATE crm_customers SET updated_by = ?, updated_at = ? WHERE id = ?')->execute([$me, $now, $masterId]);
            if (Db::tableExists('crm_duplicate_merge_cases')) {
                foreach ($dupIds as $fromId) {
                    $this->pdo->prepare("UPDATE crm_duplicate_merge_cases SET status = 'merged', merged_by = ?, merged_at = ?, updated_at = ? WHERE master_customer_id = ? OR master_customer_id = ?")
                        ->execute([$me, $now, $now, $masterId, $fromId]);
                }
            }
            $this->pdo->commit();
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
        $names = array_map(static function (int $id) use ($rows): string {
            return '#' . $id . ' ' . $rows[$id]['customer_name'];
        }, $dupIds);
        $moved = array_filter($moved);
        CustomerLog::write('customer_merge', $masterId, '合并客户', '合并了 ' . implode('、', $names) . ($filled ? '；补上了 ' . implode('、', array_map([CustomerService::class, 'fieldLabel'], $filled)) : ''),
            ['customers' => array_values(array_map(static function (array $r): array {
                return ['id' => (int) $r['id'], 'customer_name' => $r['customer_name']];
            }, $rows))], ['master_customer_id' => $masterId, 'merged_customer_ids' => $dupIds, 'moved_counts' => $moved, 'filled' => $filled], $this->operator);
        return ['master_id' => $masterId, 'merged_ids' => $dupIds, 'moved' => $moved, 'filled' => $filled];
    }

    private function moveColumn(string $table, string $column, int $from, int $to): int
    {
        if (!in_array($column, Db::columns($table), true)) {
            return 0;
        }
        $stmt = $this->pdo->prepare('UPDATE `' . $table . '` SET `' . $column . '` = ? WHERE `' . $column . '` = ?');
        $stmt->execute([$to, $from]);
        return $stmt->rowCount();
    }

    private function copyUnique(string $table, int $from, int $to): int
    {
        $cols = Db::columns($table);
        if (!in_array('customer_id', $cols, true)) {
            return 0;
        }
        $copy = array_values(array_diff($cols, ['id', 'customer_id']));
        $target = '`customer_id`' . ($copy ? ', `' . implode('`, `', $copy) . '`' : '');
        $select = '?' . ($copy ? ', `' . implode('`, `', $copy) . '`' : '');
        $stmt = $this->pdo->prepare(Db::insertIgnore() . ' INTO `' . $table . '` (' . $target . ') SELECT ' . $select . ' FROM `' . $table . '` WHERE `customer_id` = ?');
        $stmt->execute([$to, $from]);
        $n = $stmt->rowCount();
        $this->pdo->prepare('DELETE FROM `' . $table . '` WHERE `customer_id` = ?')->execute([$from]);
        return $n;
    }

    /** 被合并客户的负责人都作为主客户的协助负责人。 */
    private function copyOwners(int $from, int $to): int
    {
        $stmt = $this->pdo->prepare(Db::insertIgnore() . " INTO crm_customer_owners (customer_id, user_id, role_type, is_primary, created_by, created_at)
            SELECT ?, user_id, CASE WHEN role_type = 'primary' THEN 'secondary' ELSE role_type END, 0, created_by, created_at FROM crm_customer_owners WHERE customer_id = ?");
        $stmt->execute([$to, $from]);
        $n = $stmt->rowCount();
        $this->pdo->prepare('DELETE FROM crm_customer_owners WHERE customer_id = ?')->execute([$from]);
        $stmt = $this->pdo->prepare('SELECT owner_user_id FROM crm_customers WHERE id = ?');
        $stmt->execute([$from]);
        $owner = (int) $stmt->fetchColumn();
        if ($owner > 0) {
            $this->pdo->prepare(Db::insertIgnore() . " INTO crm_customer_owners (customer_id, user_id, role_type, is_primary, created_by, created_at) VALUES (?, ?, 'secondary', 0, ?, ?)")
                ->execute([$to, $owner, (int) $this->operator['id'], Db::now()]);
        }
        return $n;
    }

    private function copyRelations(int $from, int $to): int
    {
        if (!Db::tableExists('crm_customer_relations')) {
            return 0;
        }
        $n = 0;
        $stmt = $this->pdo->prepare(Db::insertIgnore() . ' INTO crm_customer_relations (customer_id, related_customer_id, relation_type, remark, created_by, created_at, updated_at)
            SELECT ?, related_customer_id, relation_type, remark, created_by, created_at, updated_at FROM crm_customer_relations WHERE customer_id = ? AND related_customer_id <> ?');
        $stmt->execute([$to, $from, $to]);
        $n += $stmt->rowCount();
        $stmt = $this->pdo->prepare(Db::insertIgnore() . ' INTO crm_customer_relations (customer_id, related_customer_id, relation_type, remark, created_by, created_at, updated_at)
            SELECT customer_id, ?, relation_type, remark, created_by, created_at, updated_at FROM crm_customer_relations WHERE related_customer_id = ? AND customer_id <> ?');
        $stmt->execute([$to, $from, $to]);
        $n += $stmt->rowCount();
        $this->pdo->prepare('DELETE FROM crm_customer_relations WHERE customer_id = ? OR related_customer_id = ?')->execute([$from, $from]);
        return $n;
    }

    /** @return array<string, int> */
    private function externalLinks(int $from, int $to): array
    {
        $out = [];
        foreach (['crm_mails', 'crm_mail_drafts'] as $table) {
            $out[$table] = $this->moveColumn($table, 'linked_customer_id', $from, $to);
        }
        if (in_array('target_customer_id', Db::columns('crm_lead_pool'), true)) {
            $stmt = $this->pdo->prepare("UPDATE crm_lead_pool SET target_customer_id = ?, status = CASE WHEN status = 'pending' THEN 'merged' ELSE status END, updated_at = ? WHERE target_customer_id = ?");
            $stmt->execute([$to, Db::now(), $from]);
            $out['crm_lead_pool'] = $stmt->rowCount();
        }
        foreach (['quote_orders', 'quote_sales_orders'] as $table) {
            if (in_array('customer_id', Db::columns($table), true)) {
                $stmt = $this->pdo->prepare('UPDATE `' . $table . '` SET customer_id = ? WHERE customer_id = ? OR customer_id = ?');
                $stmt->execute([(string) $to, (string) $from, 'crm_' . $from]);
                $out[$table] = $stmt->rowCount();
            }
        }
        if (in_array('crm_customer_id', Db::columns('quote_customers'), true)) {
            $stmt = $this->pdo->prepare('UPDATE quote_customers SET crm_customer_id = ? WHERE crm_customer_id = ? OR crm_customer_id = ?');
            $stmt->execute([(string) $to, (string) $from, 'crm_' . $from]);
            $out['quote_customers'] = $stmt->rowCount();
        }
        return $out;
    }

    /** 合并后可能有多个主联系人：保留主客户原来的那个。 */
    private function fixPrimaryContact(int $masterId): void
    {
        $stmt = $this->pdo->prepare('SELECT id FROM crm_contacts WHERE customer_id = ? AND is_primary = 1 AND deleted_at IS NULL ORDER BY created_at ASC, id ASC');
        $stmt->execute([$masterId]);
        $ids = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
        if (count($ids) > 1) {
            $keep = array_shift($ids);
            $this->pdo->prepare('UPDATE crm_contacts SET is_primary = 0 WHERE customer_id = ? AND id <> ?')->execute([$masterId, $keep]);
        }
    }

    /** 合并后可能有多个主地址：保留最早的那个。 */
    private function fixPrimaryAddress(int $masterId): void
    {
        $stmt = $this->pdo->prepare('SELECT id FROM crm_customer_addresses WHERE customer_id = ? AND is_primary = 1 AND deleted_at IS NULL ORDER BY id ASC');
        $stmt->execute([$masterId]);
        $ids = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
        if (count($ids) > 1) {
            $this->pdo->prepare('UPDATE crm_customer_addresses SET is_primary = 0 WHERE customer_id = ? AND id <> ?')->execute([$masterId, $ids[0]]);
        }
    }

    /** 主客户本来在公海、合并来的客户有负责人时，第一个负责人成为第一负责人。 */
    private function fixOwners(int $masterId, int $masterOwner): void
    {
        if ($masterOwner > 0) {
            $this->pdo->prepare('UPDATE crm_customer_owners SET is_primary = 0, role_type = CASE WHEN role_type = \'primary\' THEN \'secondary\' ELSE role_type END WHERE customer_id = ? AND user_id <> ?')->execute([$masterId, $masterOwner]);
            return;
        }
        $stmt = $this->pdo->prepare('SELECT user_id FROM crm_customer_owners WHERE customer_id = ? ORDER BY id LIMIT 1');
        $stmt->execute([$masterId]);
        $first = (int) $stmt->fetchColumn();
        if ($first > 0) {
            $this->pdo->prepare("UPDATE crm_customer_owners SET is_primary = 1, role_type = 'primary' WHERE customer_id = ? AND user_id = ?")->execute([$masterId, $first]);
            $stmt = $this->pdo->prepare('SELECT d.name FROM crm_users u LEFT JOIN crm_departments d ON d.id = u.department_id WHERE u.id = ?');
            $stmt->execute([$first]);
            $this->pdo->prepare('UPDATE crm_customers SET owner_user_id = ?, owner_department = ? WHERE id = ?')->execute([$first, (string) ($stmt->fetchColumn() ?: ''), $masterId]);
        }
    }
}
