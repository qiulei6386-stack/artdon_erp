<?php
declare(strict_types=1);

namespace Artdon\Centers\Customer;

use Artdon\Centers\Base\Db;
use Artdon\Centers\Base\NotFoundException;
use Artdon\Centers\Base\Permission;
use Artdon\Centers\Base\PermissionException;
use Artdon\Centers\Base\ValidationException;
use PDO;

/**
 * 客户分组（crm_customer_groups，全公司共用；一个客户可以在多个分组）。
 * 管理分组、批量调整客户分组需要 customer.batch（与旧系统一致）；新建、编辑客户时可以勾选分组。
 * 删除分组为软删除，客户与分组的关联保留但不再显示、不再参与数据范围。
 */
final class GroupService
{
    public const COLORS = ['#2563eb', '#0ea5e9', '#14b8a6', '#059669', '#65a30d', '#d97706', '#dc2626', '#db2777', '#7c3aed', '#64748b'];
    public const MODES = ['add' => '加入分组（保留原有分组）', 'replace' => '只放在这个分组（去掉原有分组）', 'remove' => '移出这个分组'];

    /** @var PDO */
    private $pdo;

    /** @var array<string, mixed> */
    private $operator;

    public function __construct(array $operator, ?PDO $pdo = null)
    {
        $this->operator = $operator;
        $this->pdo = $pdo ?? Db::pdo();
    }

    public function canManage(): bool
    {
        return Permission::allows($this->operator, 'customer.batch');
    }

    /** 未删除的分组及客户数（只数未删除的客户）。 @return list<array<string, mixed>> */
    public static function all(?PDO $pdo = null): array
    {
        $pdo = $pdo ?? Db::pdo();
        if (!Db::tableExists('crm_customer_groups')) {
            return [];
        }
        return $pdo->query('SELECT g.id, g.group_name, g.group_color, g.sort_order,
                (SELECT COUNT(*) FROM crm_customer_group_relations r JOIN crm_customers c ON c.id = r.customer_id AND c.deleted_at IS NULL WHERE r.group_id = g.id) AS customer_count
            FROM crm_customer_groups g WHERE g.deleted_at IS NULL ORDER BY g.sort_order, g.id')->fetchAll(PDO::FETCH_ASSOC);
    }

    /** 下拉 / 勾选用：编号 => 名称。 @return array<int, string> */
    public static function options(?PDO $pdo = null): array
    {
        $out = [];
        foreach (self::all($pdo) as $g) {
            $out[(int) $g['id']] = (string) $g['group_name'];
        }
        return $out;
    }

    public function create(array $input): int
    {
        $this->requireManage();
        [$name, $color, $sort] = $this->check($input);
        $stmt = $this->pdo->prepare('SELECT id, deleted_at FROM crm_customer_groups WHERE group_name = ?');
        $stmt->execute([$name]);
        $old = $stmt->fetch(PDO::FETCH_ASSOC);
        $now = Db::now();
        if ($old && $old['deleted_at'] === null) {
            throw new ValidationException(['group_name' => '已经有这个分组了']);
        }
        if ($old) {
            // 名称唯一（含已删除的）：重新启用原来的分组，原来的客户关联清掉，从空分组开始
            $id = (int) $old['id'];
            $this->pdo->prepare('DELETE FROM crm_customer_group_relations WHERE group_id = ?')->execute([$id]);
            $this->pdo->prepare('UPDATE crm_customer_groups SET group_color = ?, sort_order = ?, deleted_at = NULL, updated_at = ? WHERE id = ?')->execute([$color, $sort, $now, $id]);
        } else {
            $this->pdo->prepare('INSERT INTO crm_customer_groups (group_name, group_color, sort_order, created_by, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?)')
                ->execute([$name, $color, $sort, (int) $this->operator['id'], $now, $now]);
            $id = (int) $this->pdo->lastInsertId();
        }
        CustomerLog::write('group_create', 0, '新建客户分组', $name, null, ['group_id' => $id, 'group_name' => $name], $this->operator, 'group', (string) $id);
        return $id;
    }

    public function update(int $id, array $input): void
    {
        $this->requireManage();
        $before = $this->find($id);
        [$name, $color, $sort] = $this->check($input);
        $stmt = $this->pdo->prepare('SELECT id FROM crm_customer_groups WHERE group_name = ? AND id <> ?');
        $stmt->execute([$name, $id]);
        if ($stmt->fetchColumn()) {
            throw new ValidationException(['group_name' => '分组名称不能重复（包括已删除的分组）']);
        }
        $this->pdo->prepare('UPDATE crm_customer_groups SET group_name = ?, group_color = ?, sort_order = ?, updated_at = ? WHERE id = ?')->execute([$name, $color, $sort, Db::now(), $id]);
        CustomerLog::write('group_update', 0, '编辑客户分组', $name, ['group_name' => $before['group_name'], 'group_color' => $before['group_color'], 'sort_order' => $before['sort_order']],
            ['group_name' => $name, 'group_color' => $color, 'sort_order' => $sort], $this->operator, 'group', (string) $id);
    }

    public function delete(int $id): void
    {
        $this->requireManage();
        $before = $this->find($id);
        $this->pdo->prepare('UPDATE crm_customer_groups SET deleted_at = ?, updated_at = ? WHERE id = ?')->execute([Db::now(), Db::now(), $id]);
        CustomerLog::write('group_delete', 0, '删除客户分组', (string) $before['group_name'], ['group_name' => $before['group_name']], ['deleted' => true], $this->operator, 'group', (string) $id);
    }

    /**
     * 批量调整客户分组。
     *
     * @param list<int> $customerIds
     */
    public function apply(array $customerIds, int $groupId, string $mode): int
    {
        $this->requireManage();
        if (!isset(self::MODES[$mode])) {
            throw new ValidationException(['mode' => '请选择怎么调整']);
        }
        $group = $this->find($groupId);
        $ids = array_values(array_unique(array_filter(array_map('intval', $customerIds))));
        if (!$ids) {
            throw new ValidationException(['ids' => '请先选择客户']);
        }
        if (count($ids) > 500) {
            throw new ValidationException(['ids' => '一次最多处理 500 个客户']);
        }
        $customers = new CustomerService($this->operator, $this->pdo);
        foreach ($ids as $id) {
            $customers->find($id);
        }
        $me = (int) $this->operator['id'];
        $now = Db::now();
        $this->pdo->beginTransaction();
        try {
            foreach ($ids as $id) {
                if ($mode === 'replace') {
                    $this->pdo->prepare('DELETE FROM crm_customer_group_relations WHERE customer_id = ?')->execute([$id]);
                }
                if ($mode === 'remove') {
                    $this->pdo->prepare('DELETE FROM crm_customer_group_relations WHERE customer_id = ? AND group_id = ?')->execute([$id, $groupId]);
                } else {
                    $this->pdo->prepare(Db::insertIgnore() . ' INTO crm_customer_group_relations (customer_id, group_id, created_by, created_at) VALUES (?, ?, ?, ?)')->execute([$id, $groupId, $me, $now]);
                }
            }
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
        $label = ['add' => '加入分组', 'replace' => '移到分组', 'remove' => '移出分组'][$mode];
        foreach ($ids as $id) {
            CustomerLog::write('group_move_customers', $id, $label, (string) $group['group_name'], null, ['group_id' => $groupId, 'mode' => $mode], $this->operator, 'group', (string) $groupId);
        }
        return count($ids);
    }

    /**
     * 新建、编辑客户时保存勾选的分组（替换）。只接受未删除的分组；已删除分组的旧关联保留。
     *
     * @param list<int> $groupIds
     */
    public static function setForCustomer(PDO $pdo, int $customerId, array $groupIds, int $operatorId): void
    {
        $valid = array_keys(self::options($pdo));
        $groupIds = array_values(array_intersect(array_unique(array_map('intval', $groupIds)), $valid));
        if ($valid) {
            $pdo->prepare('DELETE FROM crm_customer_group_relations WHERE customer_id = ? AND group_id IN (' . implode(',', array_fill(0, count($valid), '?')) . ')')->execute(array_merge([$customerId], $valid));
        }
        $insert = $pdo->prepare(Db::insertIgnore() . ' INTO crm_customer_group_relations (customer_id, group_id, created_by, created_at) VALUES (?, ?, ?, ?)');
        foreach ($groupIds as $g) {
            $insert->execute([$customerId, $g, $operatorId, Db::now()]);
        }
    }

    /** @return list<int> */
    public static function forCustomer(PDO $pdo, int $customerId): array
    {
        $stmt = $pdo->prepare('SELECT r.group_id FROM crm_customer_group_relations r JOIN crm_customer_groups g ON g.id = r.group_id AND g.deleted_at IS NULL WHERE r.customer_id = ? ORDER BY g.sort_order, g.id');
        $stmt->execute([$customerId]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /** @return array{0: string, 1: string, 2: int} */
    private function check(array $input): array
    {
        $errors = [];
        $name = is_scalar($input['group_name'] ?? null) ? trim((string) $input['group_name']) : '';
        if ($name === '') {
            $errors['group_name'] = '请填写分组名称';
        } elseif (mb_strlen($name, 'UTF-8') > 120) {
            $errors['group_name'] = '分组名称不能超过 120 个字';
        }
        $color = is_scalar($input['group_color'] ?? null) ? trim((string) $input['group_color']) : '';
        if ($color === '' || !preg_match('/^#[0-9a-fA-F]{6}$/', $color)) {
            $color = self::COLORS[0];
        }
        $sortRaw = is_scalar($input['sort_order'] ?? null) ? trim((string) $input['sort_order']) : '';
        if ($sortRaw !== '' && !preg_match('/^\d{1,5}$/', $sortRaw)) {
            $errors['sort_order'] = '排序请填 0 到 99999 的数字';
        }
        if ($errors) {
            throw new ValidationException($errors);
        }
        return [$name, strtolower($color), $sortRaw === '' ? 100 : (int) $sortRaw];
    }

    /** @return array<string, mixed> */
    private function find(int $id): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM crm_customer_groups WHERE id = ? AND deleted_at IS NULL');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new NotFoundException('分组不存在');
        }
        return $row;
    }

    private function requireManage(): void
    {
        if (!$this->canManage()) {
            throw new PermissionException('没有管理客户分组的权限（customer.batch）');
        }
    }
}
