<?php
declare(strict_types=1);

namespace Artdon\Centers\Customer;

use Artdon\Centers\Base\Db;
use Artdon\Centers\Base\Dictionary;
use Artdon\Centers\Base\NotFoundException;
use Artdon\Centers\Base\Permission;
use Artdon\Centers\Base\PermissionException;
use Artdon\Centers\Base\ValidationException;
use PDO;
use Throwable;

/**
 * 客户附属资料：多地址（crm_customer_addresses）、聊天群（crm_customer_chat_groups）、客户关系（crm_customer_relations）。
 * 地址、聊天群：需要 customer.edit，且客户在自己范围内（公海客户先领取）。
 * 客户关系：需要 customer.graph_manage，两边的客户都要看得到。
 */
final class CustomerDetails
{
    public const ADDRESS_TYPES = ['HQ' => '总部', 'Office' => '办公室', 'Factory' => '工厂', 'Warehouse' => '仓库', 'Store' => '门店', 'Project' => '项目地址', 'Shipping' => '收货地址', 'Billing' => '账单地址', 'Other' => '其他'];
    public const CHAT_PLATFORMS = ['wechat_group' => '微信群', 'whatsapp_group' => 'WhatsApp 群'];
    public const CHAT_STATUS = ['active' => '正常', 'paused' => '暂停', 'invalid' => '已失效'];
    public const RELATION_TYPES = ['parent' => '母公司', 'subsidiary' => '子公司', 'same_group' => '同集团', 'agent' => '代理商', 'distributor' => '经销商', 'designer' => '设计方',
        'contractor' => '工程方', 'owner' => '业主', 'partner' => '合作伙伴', 'competitor' => '竞争关系', 'other' => '其他'];
    /** 反过来看时的说法：A 是 B 的母公司 → B 是 A 的子公司。 */
    private const RELATION_REVERSE = ['parent' => 'subsidiary', 'subsidiary' => 'parent'];

    /** @var PDO */
    private $pdo;

    /** @var array<string, mixed> */
    private $operator;

    /** @var CustomerService */
    private $customers;

    public function __construct(array $operator, ?PDO $pdo = null)
    {
        $this->operator = $operator;
        $this->pdo = $pdo ?? Db::pdo();
        $this->customers = new CustomerService($operator, $this->pdo);
    }

    /** @return array<string, string> */
    public static function addressTypes(): array
    {
        $dict = Dictionary::options('address_type');
        return $dict ?: self::ADDRESS_TYPES;
    }

    /** @return array<string, string> */
    public static function relationTypes(): array
    {
        $dict = Dictionary::options('customer_relation_type');
        return $dict ?: self::RELATION_TYPES;
    }

    // ---------------------------------------------------------------- 地址

    /** @return list<array<string, mixed>> */
    public function addresses(int $customerId): array
    {
        $stmt = $this->pdo->prepare('SELECT id, address_type, country, city, address, postal_code, contact_name, phone, is_primary, updated_at FROM crm_customer_addresses WHERE customer_id = ? AND deleted_at IS NULL ORDER BY is_primary DESC, id');
        $stmt->execute([$customerId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** 新增或修改地址（$input 里有 id 为修改）。设为主地址时同步客户资料里的国家、城市、地址。 */
    public function saveAddress(int $customerId, array $input): int
    {
        $this->customers->requireMaintain($customerId, 'customer.edit', '维护地址');
        $id = (int) ($input['id'] ?? 0);
        $before = null;
        if ($id > 0) {
            $before = $this->addressRow($customerId, $id);
        }
        $types = self::addressTypes();
        $errors = [];
        $type = $this->text($input, 'address_type', 120);
        if (!isset($types[$type]) && ($before === null || $type !== $before['address_type'])) {
            $errors['address_type'] = '请选择地址类型';
        }
        $country = $this->text($input, 'country', 120);
        if ($country === '') {
            $errors['country'] = '请选择国家';
        } else {
            $normalized = Country::normalize($country);
            if (Country::options() && !isset(Country::options()[$normalized]) && ($before === null || $country !== $before['country'])) {
                $errors['country'] = '请从列表里选择国家';
            }
            $country = isset(Country::options()[$normalized]) ? $normalized : $country;
        }
        $address = $this->text($input, 'address', 500);
        if ($address === '') {
            $errors['address'] = '请填写详细地址';
        }
        $data = [
            'address_type' => $type, 'country' => $country, 'city' => $this->text($input, 'city', 120), 'address' => $address,
            'postal_code' => $this->text($input, 'postal_code', 60), 'contact_name' => $this->text($input, 'contact_name', 120), 'phone' => $this->text($input, 'phone', 80),
        ];
        foreach (['city' => 120, 'address' => 500, 'postal_code' => 60, 'contact_name' => 120, 'phone' => 80] as $k => $max) {
            if (is_scalar($input[$k] ?? null) && mb_strlen(trim((string) $input[$k]), 'UTF-8') > $max) {
                $errors[$k] = '不能超过 ' . $max . ' 个字符';
            }
        }
        if ($errors) {
            throw new ValidationException($errors);
        }
        $hasAny = (bool) $this->addresses($customerId);
        $primary = !empty($input['is_primary']) || !$hasAny || ($before !== null && (int) $before['is_primary'] === 1);
        $now = Db::now();
        $me = (int) $this->operator['id'];
        $this->pdo->beginTransaction();
        try {
            if ($primary) {
                $this->pdo->prepare('UPDATE crm_customer_addresses SET is_primary = 0 WHERE customer_id = ? AND deleted_at IS NULL')->execute([$customerId]);
            }
            if ($id > 0) {
                $this->pdo->prepare('UPDATE crm_customer_addresses SET address_type = ?, country = ?, city = ?, address = ?, postal_code = ?, contact_name = ?, phone = ?, is_primary = ?, updated_by = ?, updated_at = ? WHERE id = ?')
                    ->execute([$data['address_type'], $data['country'], $data['city'], $data['address'], $data['postal_code'], $data['contact_name'], $data['phone'], $primary ? 1 : 0, $me, $now, $id]);
            } else {
                $this->pdo->prepare('INSERT INTO crm_customer_addresses (customer_id, address_type, country, city, address, postal_code, contact_name, phone, is_primary, created_by, updated_by, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
                    ->execute([$customerId, $data['address_type'], $data['country'], $data['city'], $data['address'], $data['postal_code'], $data['contact_name'], $data['phone'], $primary ? 1 : 0, $me, $me, $now, $now]);
                $id = (int) $this->pdo->lastInsertId();
            }
            if ($primary) {
                $this->syncMain($customerId, $data);
            }
            $this->touch($customerId);
            $this->pdo->commit();
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
        CustomerLog::write('address_save', $customerId, $before ? '编辑地址' : '新增地址', ($types[$type] ?? $type) . '：' . $address . ($primary ? '（主地址）' : ''), $before, $data + ['is_primary' => $primary ? 1 : 0], $this->operator, 'address', (string) $id);
        return $id;
    }

    public function setPrimaryAddress(int $customerId, int $id): void
    {
        $this->customers->requireMaintain($customerId, 'customer.edit', '维护地址');
        $row = $this->addressRow($customerId, $id);
        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare('UPDATE crm_customer_addresses SET is_primary = CASE WHEN id = ? THEN 1 ELSE 0 END WHERE customer_id = ? AND deleted_at IS NULL')->execute([$id, $customerId]);
            $this->syncMain($customerId, $row);
            $this->touch($customerId);
            $this->pdo->commit();
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
        CustomerLog::write('address_save', $customerId, '设为主地址', (string) $row['address'], null, ['address_id' => $id], $this->operator, 'address', (string) $id);
    }

    public function deleteAddress(int $customerId, int $id): void
    {
        $this->customers->requireMaintain($customerId, 'customer.edit', '维护地址');
        $row = $this->addressRow($customerId, $id);
        if ((int) $row['is_primary'] === 1) {
            throw new \RuntimeException('主地址不能删除，请先把别的地址设为主地址');
        }
        $this->pdo->prepare('UPDATE crm_customer_addresses SET deleted_at = ?, deleted_by = ?, updated_at = ? WHERE id = ?')->execute([Db::now(), (int) $this->operator['id'], Db::now(), $id]);
        $this->touch($customerId);
        CustomerLog::write('address_delete', $customerId, '删除地址', (string) $row['address'], $row, ['deleted' => true], $this->operator, 'address', (string) $id);
    }

    /** @return array<string, mixed> */
    private function addressRow(int $customerId, int $id): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM crm_customer_addresses WHERE id = ? AND customer_id = ? AND deleted_at IS NULL');
        $stmt->execute([$id, $customerId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new NotFoundException('地址不存在');
        }
        return $row;
    }

    /** 主地址同步到客户资料（旧系统显示也用主地址）。 */
    private function syncMain(int $customerId, array $address): void
    {
        $this->pdo->prepare('UPDATE crm_customers SET country = ?, city = ?, address = ? WHERE id = ?')
            ->execute([(string) $address['country'], (string) ($address['city'] ?? ''), (string) ($address['address'] ?? ''), $customerId]);
    }

    // ---------------------------------------------------------------- 聊天群

    /** @return list<array<string, mixed>> */
    public function chatGroups(int $customerId): array
    {
        $stmt = $this->pdo->prepare('SELECT id, group_name, group_platform, group_owner, linked_contact_ids_json, use_for_promotion, status, remark, last_promoted_at, updated_at
            FROM crm_customer_chat_groups WHERE customer_id = ? AND deleted_at IS NULL ORDER BY CASE status WHEN \'active\' THEN 0 WHEN \'paused\' THEN 1 ELSE 2 END, id DESC');
        $stmt->execute([$customerId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$r) {
            $ids = json_decode((string) ($r['linked_contact_ids_json'] ?? ''), true);
            $r['contact_ids'] = is_array($ids) ? array_values(array_map('intval', $ids)) : [];
        }
        unset($r);
        return $rows;
    }

    public function saveChatGroup(int $customerId, array $input): int
    {
        $this->customers->requireMaintain($customerId, 'customer.edit', '维护聊天群');
        $id = (int) ($input['id'] ?? 0);
        $before = null;
        if ($id > 0) {
            $before = $this->chatRow($customerId, $id);
        }
        $errors = [];
        $name = $this->text($input, 'group_name', 190);
        if ($name === '') {
            $errors['group_name'] = '请填写群名称';
        }
        $platform = $this->text($input, 'group_platform', 40);
        if (!isset(self::CHAT_PLATFORMS[$platform])) {
            $errors['group_platform'] = '请选择微信群或 WhatsApp 群';
        }
        $status = $this->text($input, 'status', 20) ?: 'active';
        if (!isset(self::CHAT_STATUS[$status])) {
            $errors['status'] = '状态不正确';
        }
        if ($errors) {
            throw new ValidationException($errors);
        }
        $raw = $input['contact_ids'] ?? [];
        $contactIds = array_values(array_unique(array_filter(array_map('intval', is_array($raw) ? $raw : []))));
        if ($contactIds) {
            $stmt = $this->pdo->prepare('SELECT id FROM crm_contacts WHERE customer_id = ? AND deleted_at IS NULL AND id IN (' . implode(',', array_fill(0, count($contactIds), '?')) . ')');
            $stmt->execute(array_merge([$customerId], $contactIds));
            $contactIds = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
        }
        $data = [
            'group_name' => $name, 'group_platform' => $platform, 'group_owner' => $this->text($input, 'group_owner', 120),
            'linked_contact_ids_json' => (string) json_encode($contactIds), 'use_for_promotion' => !empty($input['use_for_promotion']) ? 1 : 0, 'status' => $status, 'remark' => $this->text($input, 'remark', 500),
        ];
        $now = Db::now();
        $me = (int) $this->operator['id'];
        if ($id > 0) {
            $this->pdo->prepare('UPDATE crm_customer_chat_groups SET group_name = ?, group_platform = ?, group_owner = ?, linked_contact_ids_json = ?, use_for_promotion = ?, status = ?, remark = ?, updated_by = ?, updated_at = ? WHERE id = ?')
                ->execute([$data['group_name'], $data['group_platform'], $data['group_owner'], $data['linked_contact_ids_json'], $data['use_for_promotion'], $data['status'], $data['remark'], $me, $now, $id]);
        } else {
            $this->pdo->prepare('INSERT INTO crm_customer_chat_groups (customer_id, group_name, group_platform, group_owner, linked_contact_ids_json, use_for_promotion, status, remark, created_by, updated_by, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
                ->execute([$customerId, $data['group_name'], $data['group_platform'], $data['group_owner'], $data['linked_contact_ids_json'], $data['use_for_promotion'], $data['status'], $data['remark'], $me, $me, $now, $now]);
            $id = (int) $this->pdo->lastInsertId();
        }
        $this->touch($customerId);
        CustomerLog::write($before ? 'chat_group_update' : 'chat_group_create', $customerId, $before ? '编辑聊天群' : '新增聊天群', self::CHAT_PLATFORMS[$platform] . '：' . $name, $before, $data, $this->operator, 'chat_group', (string) $id);
        return $id;
    }

    public function deleteChatGroup(int $customerId, int $id): void
    {
        $this->customers->requireMaintain($customerId, 'customer.edit', '维护聊天群');
        $row = $this->chatRow($customerId, $id);
        $this->pdo->prepare('UPDATE crm_customer_chat_groups SET deleted_at = ?, deleted_by = ?, updated_by = ?, updated_at = ? WHERE id = ?')->execute([Db::now(), (int) $this->operator['id'], (int) $this->operator['id'], Db::now(), $id]);
        $this->touch($customerId);
        CustomerLog::write('chat_group_delete', $customerId, '删除聊天群', (string) $row['group_name'], $row, ['deleted' => true], $this->operator, 'chat_group', (string) $id);
    }

    /** @return array<string, mixed> */
    private function chatRow(int $customerId, int $id): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM crm_customer_chat_groups WHERE id = ? AND customer_id = ? AND deleted_at IS NULL');
        $stmt->execute([$id, $customerId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new NotFoundException('聊天群不存在');
        }
        return $row;
    }

    // ---------------------------------------------------------------- 客户关系

    /**
     * 两个方向的关系都列出（别人登记“X 是我的代理商”也显示在 X 这边）。看不到的客户只显示“无权查看的客户”。
     *
     * @return list<array{id: int, other_id: int, other_name: string, other_visible: bool, type: string, label: string, remark: string, direction: string}>
     */
    public function relations(int $customerId): array
    {
        if (!Db::tableExists('crm_customer_relations')) {
            return [];
        }
        $types = self::relationTypes();
        $stmt = $this->pdo->prepare('SELECT r.id, r.customer_id, r.related_customer_id, r.relation_type, r.remark, a.customer_name AS a_name, a.deleted_at AS a_deleted, b.customer_name AS b_name, b.deleted_at AS b_deleted
            FROM crm_customer_relations r LEFT JOIN crm_customers a ON a.id = r.customer_id LEFT JOIN crm_customers b ON b.id = r.related_customer_id
            WHERE r.customer_id = ? OR r.related_customer_id = ? ORDER BY r.id DESC');
        $stmt->execute([$customerId, $customerId]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $outgoing = (int) $r['customer_id'] === $customerId;
            $otherId = $outgoing ? (int) $r['related_customer_id'] : (int) $r['customer_id'];
            if (($outgoing ? $r['b_deleted'] : $r['a_deleted']) !== null) {
                continue;
            }
            $type = (string) $r['relation_type'];
            $shown = $outgoing ? $type : (self::RELATION_REVERSE[$type] ?? $type);
            $visible = true;
            try {
                $this->customers->find($otherId);
            } catch (NotFoundException $e) {
                $visible = false;
            }
            $label = $types[$shown] ?? $shown;
            $out[] = [
                'id' => (int) $r['id'], 'other_id' => $otherId, 'other_name' => $visible ? (string) ($outgoing ? $r['b_name'] : $r['a_name']) : '（无权查看的客户）', 'other_visible' => $visible,
                'type' => $type, 'label' => $outgoing ? '对方是：' . $label : ($shown !== $type ? '对方是：' . $label : '我是对方的：' . $label), 'remark' => (string) ($r['remark'] ?? ''), 'direction' => $outgoing ? 'out' : 'in',
            ];
        }
        return $out;
    }

    public function canManageRelations(): bool
    {
        return Permission::allows($this->operator, 'customer.graph_manage');
    }

    /** 登记“对方是我的 ××”。 */
    public function addRelation(int $customerId, array $input): int
    {
        $this->requireGraph();
        $this->customers->find($customerId);
        $errors = [];
        $otherId = (int) ($input['related_customer_id'] ?? 0);
        if ($otherId <= 0) {
            $errors['related_customer_id'] = '请选择对方客户';
        } elseif ($otherId === $customerId) {
            $errors['related_customer_id'] = '不能和自己建立关系';
        }
        $type = $this->text($input, 'relation_type', 120);
        if (!isset(self::relationTypes()[$type])) {
            $errors['relation_type'] = '请选择关系';
        }
        if ($errors) {
            throw new ValidationException($errors);
        }
        $other = $this->customers->find($otherId);
        $remark = $this->text($input, 'remark', 500);
        $stmt = $this->pdo->prepare('SELECT id FROM crm_customer_relations WHERE customer_id = ? AND related_customer_id = ? AND relation_type = ?');
        $stmt->execute([$customerId, $otherId, $type]);
        if ($stmt->fetchColumn()) {
            throw new ValidationException(['relation_type' => '这个关系已经登记过了']);
        }
        $now = Db::now();
        $this->pdo->prepare('INSERT INTO crm_customer_relations (customer_id, related_customer_id, relation_type, remark, created_by, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?)')
            ->execute([$customerId, $otherId, $type, $remark, (int) $this->operator['id'], $now, $now]);
        $id = (int) $this->pdo->lastInsertId();
        $label = self::relationTypes()[$type];
        CustomerLog::write('customer_relation', $customerId, '新增客户关系', $other['customer_name'] . ' 是 ' . $label, null, ['related_customer_id' => $otherId, 'relation_type' => $type], $this->operator, 'relation', (string) $id);
        return $id;
    }

    public function deleteRelation(int $customerId, int $id): void
    {
        $this->requireGraph();
        $this->customers->find($customerId);
        $stmt = $this->pdo->prepare('SELECT * FROM crm_customer_relations WHERE id = ? AND (customer_id = ? OR related_customer_id = ?)');
        $stmt->execute([$id, $customerId, $customerId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new NotFoundException('关系不存在');
        }
        $this->pdo->prepare('DELETE FROM crm_customer_relations WHERE id = ?')->execute([$id]);
        CustomerLog::write('customer_relation_delete', $customerId, '删除客户关系', (string) (self::relationTypes()[$row['relation_type']] ?? $row['relation_type']), $row, ['deleted' => true], $this->operator, 'relation', (string) $id);
    }

    private function requireGraph(): void
    {
        if (!$this->canManageRelations()) {
            throw new PermissionException('没有管理客户关系的权限（customer.graph_manage）');
        }
    }

    // ---------------------------------------------------------------- 内部

    private function text(array $input, string $key, int $max): string
    {
        $v = $input[$key] ?? '';
        return is_scalar($v) ? mb_substr(trim((string) $v), 0, $max, 'UTF-8') : '';
    }

    private function touch(int $customerId): void
    {
        $this->pdo->prepare('UPDATE crm_customers SET updated_at = ?, updated_by = ? WHERE id = ?')->execute([Db::now(), (int) $this->operator['id'], $customerId]);
    }
}
