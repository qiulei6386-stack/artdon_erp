<?php
declare(strict_types=1);

namespace Artdon\MaterialCenter\Services;

use Artdon\MaterialCenter\Adapters\LegacyProductAdapter;
use PDO;
use RuntimeException;

final class AdaptationService
{
    private const BUSINESS_TYPES = [
        'chip' => ['label' => '芯片', 'category' => 'chip'],
        'driver' => ['label' => '驱动', 'category' => 'power_supply'],
        'power' => ['label' => '电源', 'category' => 'power_supply'],
        'optical' => ['label' => '光学', 'category' => 'optical'],
        'accessory' => ['label' => '配件', 'category' => 'accessory'],
        'color' => ['label' => '颜色', 'category' => 'accessory'],
        'installation' => ['label' => '安装', 'category' => 'connector'],
        'dimming' => ['label' => '调光', 'category' => 'power_supply'],
        'special' => ['label' => '特殊要求', 'category' => 'accessory'],
        'custom' => ['label' => '自定义', 'category' => null],
    ];

    private const STANDARD_GROUPS = [
        ['light_source', '芯片 / 光源', 'chip', 'chip', 1, 'single'],
        ['power_driver', '电源 / 驱动', 'power', 'power_supply', 1, 'single'],
        ['optical', '光学 / 透镜', 'optical', 'optical', 1, 'single'],
        ['dimming', '调光方式', 'dimming', 'power_supply', 0, 'multi'],
        ['accessories', '附件配件', 'accessory', 'accessory', 0, 'multi'],
        ['finish_color', '外观颜色', 'color', 'accessory', 0, 'multi'],
        ['installation', '安装方式', 'installation', 'connector', 1, 'single'],
        ['special_requirements', '特殊要求', 'special', 'accessory', 0, 'multi'],
    ];

    private const CONDITION_FIELDS = [
        'product_power_w' => '产品功率',
        'chip_current_ma' => '芯片允许电流',
        'chip_voltage_v' => '芯片电压',
        'space_length_mm' => '灯体内部长度',
        'space_width_mm' => '灯体内部宽度',
        'space_height_mm' => '灯体内部高度',
        'installation_type' => '安装方式',
        'dimming_mode' => '调光方式',
        'ip_rating' => '防护等级',
        'certification' => '认证',
        'customer_warranty_years' => '客户整灯质保',
        'product_series' => '产品系列',
    ];

    private const CONDITION_OPERATORS = [
        'eq' => '等于',
        'neq' => '不等于',
        'contains' => '包含',
        'gt' => '大于',
        'gte' => '大于等于',
        'lt' => '小于',
        'lte' => '小于等于',
        'between' => '介于',
        'in' => '属于',
    ];

    public function __construct(private ?PDO $db = null)
    {
        $this->db ??= \db();
    }

    public function metadata(): array
    {
        return [
            'business_types' => self::BUSINESS_TYPES,
            'condition_fields' => self::CONDITION_FIELDS,
            'condition_operators' => self::CONDITION_OPERATORS,
            'template' => $this->templatePreview(),
        ];
    }

    public function templatePreview(): array
    {
        return array_map(static fn(array $row): array => [
            'key' => $row[0],
            'name' => $row[1],
            'business_type' => $row[2],
            'material_category_code' => $row[3],
            'is_required' => (bool) $row[4],
            'selection_mode' => $row[5],
        ], self::STANDARD_GROUPS);
    }

    public function syncProducts(int $userId): array
    {
        $adapter = new LegacyProductAdapter($this->db);
        $after = 0;
        $seen = 0;
        $created = 0;
        $changed = 0;
        do {
            $rows = $adapter->allAfter($after, 500);
            if (!$rows) break;
            $this->db->beginTransaction();
            try {
                foreach ($rows as $row) {
                    $after = max($after, (int) $row['id']);
                    $seen++;
                    $json = json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                    $hash = hash('sha256', $json);
                    $old = $this->db->prepare("SELECT id,snapshot_hash FROM mc_products WHERE legacy_table='naming_models' AND legacy_id=?");
                    $old->execute([$row['id']]);
                    $existing = $old->fetch(PDO::FETCH_ASSOC);
                    if (!$existing) {
                        $this->db->prepare("INSERT INTO mc_products(legacy_table,legacy_id,product_code,product_name,snapshot_json,snapshot_hash,synced_at,status) VALUES('naming_models',?,?,?,?,?,NOW(),'active')")
                            ->execute([$row['id'], $row['model_no'], $row['product_name'] ?: $row['item_name'], $json, $hash]);
                        $created++;
                    } elseif (!hash_equals((string) $existing['snapshot_hash'], $hash)) {
                        $this->db->prepare('UPDATE mc_products SET product_code=?,product_name=?,snapshot_json=?,snapshot_hash=?,synced_at=NOW() WHERE id=?')
                            ->execute([$row['model_no'], $row['product_name'] ?: $row['item_name'], $json, $hash, $existing['id']]);
                        $changed++;
                    }
                }
                $this->db->commit();
            } catch (\Throwable $e) {
                if ($this->db->inTransaction()) $this->db->rollBack();
                throw $e;
            }
        } while (count($rows) === 500);
        $this->log(0, 'sync_products', ['seen' => $seen, 'created' => $created, 'changed' => $changed], $userId);
        return compact('seen', 'created', 'changed');
    }

    public function products(string $q = ''): array
    {
        $sql = "SELECT p.*,
            (SELECT COUNT(*) FROM mc_adaptation_groups g WHERE g.product_id=p.id) group_count,
            (SELECT COUNT(*) FROM mc_adaptation_options o JOIN mc_adaptation_groups g ON g.id=o.group_id WHERE g.product_id=p.id) option_count,
            (SELECT COUNT(*) FROM mc_adaptation_groups g WHERE g.product_id=p.id AND g.status<>'disabled' AND (g.status<>'approved' OR g.is_enabled=0)) pending_group_count,
            (SELECT COUNT(*) FROM mc_adaptation_conflicts c WHERE c.product_id=p.id AND c.status='active') conflict_count,
            (SELECT MAX(a.version_no) FROM mc_adaptation_approvals a WHERE a.product_id=p.id AND a.status='approved') approved_version
            FROM mc_products p WHERE p.status='active'";
        $params = [];
        if ($q !== '') {
            $sql .= " AND (p.product_code LIKE ? OR p.product_name LIKE ? OR JSON_UNQUOTE(JSON_EXTRACT(p.snapshot_json,'$.series_name')) LIKE ? OR JSON_UNQUOTE(JSON_EXTRACT(p.snapshot_json,'$.category')) LIKE ?)";
            $like = '%'.$q.'%';
            $params = [$like, $like, $like, $like];
        }
        $sql .= ' ORDER BY p.product_code LIMIT 500';
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$row) {
            $snapshot = json_decode((string) ($row['snapshot_json'] ?? '{}'), true) ?: [];
            $row['series_name'] = $snapshot['series_name'] ?? $snapshot['category'] ?? '';
            $row['image_url'] = $snapshot['image_url'] ?? '';
            $row['approval_label'] = empty($row['approved_version'])
                ? ((int) $row['group_count'] ? '待审批' : '未配置')
                : ((int) $row['pending_group_count'] ? '待重审' : '已启用');
            $row['has_conflict'] = (int) $row['conflict_count'] > 0;
        }
        unset($row);
        return $rows;
    }

    public function product(int $productId): ?array
    {
        foreach ($this->products() as $product) {
            if ((int) $product['id'] === $productId) return $product;
        }
        return null;
    }

    public function groups(int $productId): array
    {
        $stmt = $this->db->prepare("SELECT g.*,
            (SELECT COUNT(*) FROM mc_adaptation_options o WHERE o.group_id=g.id) option_count,
            (SELECT COUNT(*) FROM mc_adaptation_options o WHERE o.group_id=g.id AND o.option_type='alternative') alternative_count,
            (SELECT COUNT(*) FROM mc_adaptation_conditions x JOIN mc_adaptation_options o ON o.id=x.option_id WHERE o.group_id=g.id) condition_count,
            (SELECT COUNT(DISTINCT c.id) FROM mc_adaptation_conflicts c JOIN mc_adaptation_options o ON o.id IN(c.left_option_id,c.right_option_id) WHERE c.product_id=g.product_id AND c.status='active' AND o.group_id=g.id) conflict_count,
            (SELECT CONCAT(m.material_code,' ',m.name) FROM mc_adaptation_options o JOIN mc_materials m ON m.id=o.material_id WHERE o.group_id=g.id AND o.is_default=1 LIMIT 1) default_material
            FROM mc_adaptation_groups g WHERE g.product_id=? ORDER BY g.sort_order,g.id");
        $stmt->execute([$productId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$row) $row['display_status'] = $this->groupDisplayStatus($row);
        unset($row);
        return $rows;
    }

    public function options(int $groupId): array
    {
        $stmt = $this->db->prepare("SELECT o.*,m.material_code,m.name,m.brand,m.model,m.status material_status,c.code category_code,
            (SELECT COUNT(*) FROM mc_adaptation_conditions x WHERE x.option_id=o.id) condition_count
            FROM mc_adaptation_options o
            JOIN mc_materials m ON m.id=o.material_id
            JOIN mc_material_categories c ON c.id=m.category_id
            WHERE o.group_id=? AND m.deleted_at IS NULL ORDER BY o.sort_order,o.id");
        $stmt->execute([$groupId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$row) $row['match_reasons'] = json_decode((string) ($row['match_reason_json'] ?? '[]'), true) ?: [];
        unset($row);
        return $rows;
    }

    public function conditions(int $groupId): array
    {
        $stmt = $this->db->prepare("SELECT c.*,m.material_code,m.name material_name
            FROM mc_adaptation_conditions c
            JOIN mc_adaptation_options o ON o.id=c.option_id
            JOIN mc_materials m ON m.id=o.material_id
            WHERE o.group_id=? ORDER BY c.condition_group_no,c.sort_order,c.id");
        $stmt->execute([$groupId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$row) {
            $row['expected'] = json_decode((string) $row['expected_json'], true);
            $row['field_label'] = self::CONDITION_FIELDS[$row['field_code']] ?? $row['field_code'];
            $row['operator_label'] = self::CONDITION_OPERATORS[$row['operator']] ?? $row['operator'];
        }
        unset($row);
        return $rows;
    }

    public function latestApproval(int $productId): ?array
    {
        $stmt = $this->db->prepare("SELECT a.*,p.status approval_status,p.completed_at,p.requested_at
            FROM mc_adaptation_approvals a JOIN mc_approvals p ON p.id=a.approval_id
            WHERE a.product_id=? ORDER BY a.version_no DESC LIMIT 1");
        $stmt->execute([$productId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function workspace(int $productId, int $groupId = 0): array
    {
        $product = $this->product($productId);
        if (!$product) throw new RuntimeException('产品不存在或已停用。', 404);
        $groups = $this->groups($productId);
        if (!$groupId && $groups) $groupId = (int) $groups[0]['id'];
        $activeGroup = null;
        foreach ($groups as $group) {
            if ((int) $group['id'] === $groupId) {
                $activeGroup = $group;
                break;
            }
        }
        $completion = $this->completion($productId);
        return [
            'product' => $product,
            'groups' => $groups,
            'active_group' => $activeGroup,
            'options' => $activeGroup ? $this->options((int) $activeGroup['id']) : [],
            'conditions' => $activeGroup ? $this->conditions((int) $activeGroup['id']) : [],
            'conflicts' => $this->conflicts($productId),
            'approval' => $this->latestApproval($productId),
            'completion' => $completion,
        ];
    }

    public function initializeGroups(int $productId, int $userId): array
    {
        $exists = $this->db->prepare("SELECT 1 FROM mc_products WHERE id=? AND status='active'");
        $exists->execute([$productId]);
        if (!$exists->fetchColumn()) throw new RuntimeException('产品不存在或已停用。');
        $created = 0;
        $this->db->beginTransaction();
        try {
            foreach (self::STANDARD_GROUPS as $sort => $group) {
                $legacyType = $this->legacyGroupType($group[3]);
                $stmt = $this->db->prepare("INSERT INTO mc_adaptation_groups
                    (product_id,group_code,group_name,group_type,business_type,material_category_code,is_required,selection_mode,min_select,max_select,template_key,status,is_enabled,sort_order,created_by,updated_by,created_at,updated_at)
                    VALUES(?,?,?,?,?,?,?,?,?,?,?,'draft',0,?,?,?,NOW(),NOW())
                    ON DUPLICATE KEY UPDATE group_type=VALUES(group_type),business_type=VALUES(business_type),
                    material_category_code=VALUES(material_category_code),is_required=VALUES(is_required),
                    selection_mode=VALUES(selection_mode),min_select=VALUES(min_select),max_select=VALUES(max_select),
                    template_key=VALUES(template_key),updated_by=VALUES(updated_by),updated_at=NOW()");
                $stmt->execute([
                    $productId, $group[0], $group[1], $legacyType, $group[2], $group[3], $group[4], $group[5],
                    $group[4] ? 1 : 0, $group[5] === 'single' ? 1 : 0, $group[0], ($sort + 1) * 10, $userId, $userId,
                ]);
                if ($stmt->rowCount() === 1) $created++;
            }
            $this->markProductDraft($productId);
            $this->log($productId, 'apply_standard_template', ['created' => $created, 'template_total' => count(self::STANDARD_GROUPS)], $userId);
            $this->db->commit();
            return ['created' => $created, 'total' => count(self::STANDARD_GROUPS)];
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
    }

    public function saveGroup(array $data, int $userId): int
    {
        $id = (int) ($data['id'] ?? 0);
        $productId = (int) ($data['product_id'] ?? 0);
        $name = trim((string) ($data['group_name'] ?? ''));
        $businessType = (string) ($data['business_type'] ?? '');
        if ($businessType === '' && !empty($data['group_type'])) {
            $businessType = [
                'power' => 'power',
                'chip' => 'chip',
                'optical' => 'optical',
                'connector' => 'installation',
                'accessory' => 'accessory',
                'packaging' => 'accessory',
                'custom' => 'custom',
            ][(string) $data['group_type']] ?? '';
        }
        if (!$productId || !$this->product($productId)) throw new RuntimeException('请选择有效产品。');
        $this->assertMeaningfulName($name);
        if (!isset(self::BUSINESS_TYPES[$businessType])) throw new RuntimeException('请先选择有效的配置组类型。');
        $category = trim((string) ($data['material_category_code'] ?? (self::BUSINESS_TYPES[$businessType]['category'] ?? '')));
        $validCategories = ['power_supply', 'chip', 'optical', 'profile', 'connector', 'accessory', 'packaging'];
        if ($category !== '' && !in_array($category, $validCategories, true)) throw new RuntimeException('关联物料类别无效。');
        if ($category === '') throw new RuntimeException('请选择配置组对应的物料类别。');
        $selection = ($data['selection_mode'] ?? 'single') === 'multi' ? 'multi' : 'single';
        $required = !empty($data['is_required']) ? 1 : 0;
        $min = max(0, (int) ($data['min_select'] ?? ($required ? 1 : 0)));
        $max = max($min, (int) ($data['max_select'] ?? ($selection === 'single' ? 1 : max(1, $min))));
        if ($selection === 'single') {
            $min = $required ? 1 : 0;
            $max = 1;
        }
        $status = ($data['status'] ?? 'draft') === 'disabled' ? 'disabled' : 'draft';
        $this->db->beginTransaction();
        try {
            if ($id) {
                $stmt = $this->db->prepare('UPDATE mc_adaptation_groups SET group_name=?,group_type=?,business_type=?,material_category_code=?,is_required=?,selection_mode=?,min_select=?,max_select=?,status=?,is_enabled=0,sort_order=?,updated_by=?,updated_at=NOW() WHERE id=? AND product_id=?');
                $stmt->execute([$name, $this->legacyGroupType($category), $businessType, $category, $required, $selection, $min, $max, $status, (int) ($data['sort_order'] ?? 100), $userId, $id, $productId]);
                if (!$stmt->rowCount()) {
                    $check = $this->db->prepare('SELECT 1 FROM mc_adaptation_groups WHERE id=? AND product_id=?');
                    $check->execute([$id, $productId]);
                    if (!$check->fetchColumn()) throw new RuntimeException('配置组不存在。');
                }
            } else {
                $code = preg_replace('/[^a-z0-9_]/', '', strtolower((string) ($data['group_code'] ?? '')));
                if ($code === '') $code = $businessType.'_'.date('His');
                $stmt = $this->db->prepare("INSERT INTO mc_adaptation_groups
                    (product_id,group_code,group_name,group_type,business_type,material_category_code,is_required,selection_mode,min_select,max_select,status,is_enabled,sort_order,created_by,updated_by,created_at,updated_at)
                    VALUES(?,?,?,?,?,?,?,?,?,?,?,0,?,?,?,NOW(),NOW())");
                $stmt->execute([$productId, $code, $name, $this->legacyGroupType($category), $businessType, $category, $required, $selection, $min, $max, $status, (int) ($data['sort_order'] ?? 100), $userId, $userId]);
                $id = (int) $this->db->lastInsertId();
            }
            $this->markProductDraft($productId);
            if ($status === 'disabled') $this->db->prepare("UPDATE mc_adaptation_groups SET status='disabled',is_enabled=0 WHERE id=?")->execute([$id]);
            $this->log($productId, $id ? 'save_group' : 'create_group', ['group_id' => $id, 'name' => $name, 'business_type' => $businessType], $userId);
            $this->db->commit();
            return $id;
        } catch (\PDOException $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            if ((string) $e->getCode() === '23000') throw new RuntimeException('同一产品不能建立重复的配置组代码或模板组。');
            throw $e;
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
    }

    public function deleteGroup(int $groupId, int $userId): void
    {
        $stmt = $this->db->prepare('SELECT * FROM mc_adaptation_groups WHERE id=?');
        $stmt->execute([$groupId]);
        $group = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$group) throw new RuntimeException('配置组不存在。');
        if ($group['status'] === 'approved' || (int) $group['is_enabled']) throw new RuntimeException('已审批或已启用配置组不能删除，请先停用并建立新版本。');
        $approval = $this->db->prepare('SELECT 1 FROM mc_adaptation_approvals WHERE product_id=? LIMIT 1');
        $approval->execute([$group['product_id']]);
        if ($approval->fetchColumn()) throw new RuntimeException('该产品已有审批历史，配置组不能物理删除。');
        if ($this->commercialGroupReferenced((string) $group['group_code'])) throw new RuntimeException('配置组已被报价或订单引用，不能删除。');
        $this->db->beginTransaction();
        try {
            $optionIds = $this->optionIds($groupId);
            if ($optionIds) {
                $marks = implode(',', array_fill(0, count($optionIds), '?'));
                $this->db->prepare("DELETE FROM mc_adaptation_conflicts WHERE left_option_id IN($marks) OR right_option_id IN($marks)")
                    ->execute(array_merge($optionIds, $optionIds));
                $this->db->prepare("DELETE FROM mc_adaptation_conditions WHERE option_id IN($marks)")->execute($optionIds);
                $this->db->prepare("DELETE FROM mc_adaptation_defaults WHERE group_id=? OR option_id IN($marks)")
                    ->execute(array_merge([$groupId], $optionIds));
            }
            $this->db->prepare('DELETE FROM mc_adaptation_options WHERE group_id=?')->execute([$groupId]);
            $this->db->prepare('DELETE FROM mc_adaptation_groups WHERE id=?')->execute([$groupId]);
            $this->log((int) $group['product_id'], 'delete_group', ['group_id' => $groupId, 'group_code' => $group['group_code']], $userId);
            $this->db->commit();
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
    }

    public function reorderGroups(int $productId, array $groupIds, int $userId): void
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $groupIds))));
        $existing = array_map('intval', array_column($this->groups($productId), 'id'));
        sort($ids);
        $expected = $existing;
        sort($expected);
        if ($ids !== $expected) throw new RuntimeException('排序数据与当前产品配置组不一致。');
        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare("UPDATE mc_adaptation_groups SET sort_order=?,status='draft',is_enabled=0,updated_by=?,updated_at=NOW() WHERE id=? AND product_id=?");
            foreach ($groupIds as $index => $groupId) $stmt->execute([($index + 1) * 10, $userId, (int) $groupId, $productId]);
            $this->log($productId, 'reorder_groups', ['group_ids' => array_map('intval', $groupIds)], $userId);
            $this->db->commit();
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
    }

    public function materialCandidates(string $groupType): array
    {
        $map = ['power' => 'power_supply', 'chip' => 'chip', 'optical' => 'optical', 'connector' => 'connector', 'accessory' => 'accessory', 'packaging' => 'packaging'];
        if (!isset($map[$groupType])) return [];
        $stmt = $this->db->prepare("SELECT m.id,m.material_code,m.name,m.brand,m.model,c.code category_code
            FROM mc_materials m JOIN mc_material_categories c ON c.id=m.category_id
            WHERE c.code=? AND m.status='official' AND m.is_official=1 AND m.deleted_at IS NULL
            ORDER BY m.updated_at DESC,m.id DESC LIMIT 500");
        $stmt->execute([$map[$groupType]]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function candidateMaterials(int $groupId, array $filters = []): array
    {
        $groupStmt = $this->db->prepare('SELECT g.*,p.legacy_id FROM mc_adaptation_groups g JOIN mc_products p ON p.id=g.product_id WHERE g.id=?');
        $groupStmt->execute([$groupId]);
        $group = $groupStmt->fetch(PDO::FETCH_ASSOC);
        if (!$group) throw new RuntimeException('配置组不存在。');
        $category = (string) ($group['material_category_code'] ?? '');
        if ($category === '') return [];
        $sql = "SELECT m.id,m.material_code,m.name,m.brand,m.model,m.status,m.is_official,c.code category_code,
            ps.installation_type,ps.output_type,ps.nominal_power_w,ps.max_output_power_w,
            ps.output_current_ma,ps.output_current_min_ma,ps.output_current_max_ma,
            ps.output_voltage_min_v,ps.output_voltage_max_v,ps.length_mm,ps.width_mm,ps.height_mm,
            ps.supplier_warranty_years,ps.certification,pb.name power_band,
            (SELECT GROUP_CONCAT(DISTINCT d.mode ORDER BY d.mode) FROM mc_power_supply_dimming_modes d WHERE d.material_id=m.id) dimming_modes,
            (SELECT GROUP_CONCAT(DISTINCT s.name ORDER BY s.name SEPARATOR '、') FROM mc_supplier_materials sm JOIN mc_suppliers s ON s.id=sm.supplier_id AND s.deleted_at IS NULL WHERE sm.material_id=m.id AND sm.status='active') suppliers
            FROM mc_materials m
            JOIN mc_material_categories c ON c.id=m.category_id
            LEFT JOIN mc_power_supply_specs ps ON ps.material_id=m.id
            LEFT JOIN mc_power_bands pb ON pb.id=ps.power_band_id
            WHERE c.code=? AND m.is_official=1 AND m.deleted_at IS NULL";
        $params = [$category];
        $status = (string) ($filters['status'] ?? 'official');
        if ($status === 'all') $sql .= " AND m.status IN('official','disabled')";
        elseif ($status === 'disabled') $sql .= " AND m.status='disabled'";
        else $sql .= " AND m.status='official'";
        $likeFields = [
            'brand' => 'm.brand',
            'model' => 'm.model',
            'installation_type' => 'ps.installation_type',
            'output_type' => 'ps.output_type',
            'dimming_mode' => "(SELECT GROUP_CONCAT(d2.mode) FROM mc_power_supply_dimming_modes d2 WHERE d2.material_id=m.id)",
            'supplier' => "(SELECT GROUP_CONCAT(s2.name) FROM mc_supplier_materials sm2 JOIN mc_suppliers s2 ON s2.id=sm2.supplier_id WHERE sm2.material_id=m.id)",
        ];
        foreach ($likeFields as $key => $field) {
            $value = trim((string) ($filters[$key] ?? ''));
            if ($value === '') continue;
            $sql .= " AND $field LIKE ?";
            $params[] = '%'.$value.'%';
        }
        $powerBand = trim((string) ($filters['power_band'] ?? ''));
        if ($powerBand !== '') {
            $sql .= ' AND pb.name LIKE ?';
            $params[] = '%'.$powerBand.'%';
        }
        foreach (['output_current' => 'ps.output_current_ma', 'output_voltage' => 'ps.output_voltage_min_v', 'warranty' => 'ps.supplier_warranty_years'] as $key => $field) {
            $value = trim((string) ($filters[$key] ?? ''));
            if ($value === '' || !is_numeric($value)) continue;
            $sql .= " AND $field=?";
            $params[] = (float) $value;
        }
        $sql .= ' ORDER BY FIELD(m.status,\'official\',\'disabled\'),m.updated_at DESC,m.id DESC LIMIT 500';
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $rule = $category === 'power_supply' ? $this->productPowerRule((int) $group['legacy_id']) : null;
        foreach ($rows as &$row) {
            $match = $this->candidateMatch($row, $group, $rule);
            $row += $match;
            $row['key_specs'] = $this->keySpecs($row, $category);
            $row['already_added'] = $this->optionExists($groupId, (int) $row['id']);
        }
        unset($row);
        return $rows;
    }

    public function addOptions(int $groupId, array $materialIds, int $userId): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $materialIds))));
        if (!$ids) throw new RuntimeException('请至少选择一个物料。');
        $added = 0;
        $skipped = 0;
        $this->db->beginTransaction();
        try {
            foreach ($ids as $materialId) {
                if ($this->optionExists($groupId, $materialId)) {
                    $skipped++;
                    continue;
                }
                $this->saveOptionInternal([
                    'group_id' => $groupId,
                    'material_id' => $materialId,
                    'option_type' => 'optional',
                    'is_default' => 0,
                    'sort_order' => ($added + 1) * 10,
                ], $userId);
                $added++;
            }
            $this->db->commit();
            return compact('added', 'skipped');
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
    }

    public function saveOption(array $data, int $userId): int
    {
        $owns = !$this->db->inTransaction();
        if ($owns) $this->db->beginTransaction();
        try {
            $id = $this->saveOptionInternal($data, $userId);
            if ($owns) $this->db->commit();
            return $id;
        } catch (\Throwable $e) {
            if ($owns && $this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
    }

    private function saveOptionInternal(array $data, int $userId): int
    {
        $groupId = (int) ($data['group_id'] ?? 0);
        $materialId = (int) ($data['material_id'] ?? 0);
        $type = (string) ($data['option_type'] ?? 'optional');
        if (!$groupId || !$materialId || !in_array($type, ['required', 'optional', 'alternative', 'conditional', 'disabled'], true)) {
            throw new RuntimeException('配置组、物料或选项类型无效。');
        }
        $candidates = $this->candidateMaterials($groupId, ['status' => 'all']);
        $candidate = null;
        foreach ($candidates as $row) if ((int) $row['id'] === $materialId) $candidate = $row;
        if (!$candidate || $candidate['status'] !== 'official') throw new RuntimeException('只有当前类别的正式物料可以加入配置组。');
        if ($candidate['match_level'] === 'incompatible') {
            throw new RuntimeException('该物料不适配：'.implode('；', $candidate['conflict_reasons']));
        }
        $groupStmt = $this->db->prepare('SELECT product_id,selection_mode FROM mc_adaptation_groups WHERE id=?');
        $groupStmt->execute([$groupId]);
        $group = $groupStmt->fetch(PDO::FETCH_ASSOC);
        if (!$group) throw new RuntimeException('配置组不存在。');
        $isDefault = !empty($data['is_default']) ? 1 : 0;
        if ($isDefault && $group['selection_mode'] === 'single') {
            $this->db->prepare('UPDATE mc_adaptation_options SET is_default=0 WHERE group_id=?')->execute([$groupId]);
        }
        $price = $data['price_impact'] ?? '';
        $lead = $data['lead_time_impact_days'] ?? '';
        $this->db->prepare("INSERT INTO mc_adaptation_options
            (group_id,material_id,match_level,match_reason_json,requires_approval,exception_approved,option_type,is_default,price_impact,lead_time_impact_days,status,sort_order)
            VALUES(?,?,?,?,?,0,?,?,?,?, 'draft',?)
            ON DUPLICATE KEY UPDATE match_level=VALUES(match_level),match_reason_json=VALUES(match_reason_json),
            requires_approval=VALUES(requires_approval),exception_approved=0,option_type=VALUES(option_type),
            is_default=VALUES(is_default),price_impact=VALUES(price_impact),lead_time_impact_days=VALUES(lead_time_impact_days),status='draft',sort_order=VALUES(sort_order)")
            ->execute([
                $groupId, $materialId, $candidate['match_level'],
                json_encode($candidate['conflict_reasons'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                $candidate['requires_approval'] ? 1 : 0, $type, $isDefault,
                $price !== '' ? (float) $price : null, $lead !== '' ? (int) $lead : null,
                (int) ($data['sort_order'] ?? 100),
            ]);
        $find = $this->db->prepare('SELECT id FROM mc_adaptation_options WHERE group_id=? AND material_id=?');
        $find->execute([$groupId, $materialId]);
        $id = (int) $find->fetchColumn();
        $this->markProductDraft((int) $group['product_id']);
        $this->log((int) $group['product_id'], 'save_option', ['option_id' => $id, 'group_id' => $groupId, 'material_id' => $materialId, 'match_level' => $candidate['match_level']], $userId);
        return $id;
    }

    public function setDefault(int $groupId, array $optionIds, int $min, int $max, int $userId): void
    {
        $groupStmt = $this->db->prepare('SELECT * FROM mc_adaptation_groups WHERE id=?');
        $groupStmt->execute([$groupId]);
        $group = $groupStmt->fetch(PDO::FETCH_ASSOC);
        if (!$group) throw new RuntimeException('配置组不存在。');
        $ids = array_values(array_unique(array_filter(array_map('intval', $optionIds))));
        $valid = $this->optionIds($groupId);
        if (array_diff($ids, $valid)) throw new RuntimeException('默认项不属于当前配置组。');
        if ($group['selection_mode'] === 'single' && count($ids) > 1) throw new RuntimeException('单选配置组只能设置一个默认选项。');
        if ($group['selection_mode'] === 'single') {
            $min = (int) $group['is_required'];
            $max = 1;
        } else {
            $min = max(0, $min);
            $max = max($min, $max);
            if ($ids && (count($ids) < $min || count($ids) > $max)) throw new RuntimeException('默认勾选项数量不符合最少/最多选择限制。');
        }
        $before = [];
        foreach ($this->options($groupId) as $option) if ((int) $option['is_default']) $before[] = (int) $option['id'];
        $this->db->beginTransaction();
        try {
            $this->db->prepare('UPDATE mc_adaptation_options SET is_default=0 WHERE group_id=?')->execute([$groupId]);
            if ($ids) {
                $marks = implode(',', array_fill(0, count($ids), '?'));
                $this->db->prepare("UPDATE mc_adaptation_options SET is_default=1,status='draft' WHERE group_id=? AND id IN($marks)")
                    ->execute(array_merge([$groupId], $ids));
            }
            $this->db->prepare("UPDATE mc_adaptation_groups SET min_select=?,max_select=?,status='draft',is_enabled=0,updated_by=?,updated_at=NOW() WHERE id=?")
                ->execute([$min, $max, $userId, $groupId]);
            $this->log((int) $group['product_id'], 'set_default', ['group_id' => $groupId, 'old_option_ids' => $before, 'new_option_ids' => $ids, 'min' => $min, 'max' => $max], $userId);
            $this->db->commit();
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
    }

    public function saveConditions(int $groupId, array $rows, int $userId): array
    {
        $groupStmt = $this->db->prepare('SELECT product_id FROM mc_adaptation_groups WHERE id=?');
        $groupStmt->execute([$groupId]);
        $productId = (int) $groupStmt->fetchColumn();
        if (!$productId) throw new RuntimeException('配置组不存在。');
        $validOptions = $this->optionIds($groupId);
        $normalized = [];
        foreach ($rows as $index => $row) {
            $optionId = (int) ($row['option_id'] ?? 0);
            $field = (string) ($row['field_code'] ?? '');
            $operator = (string) ($row['operator'] ?? '');
            $connector = strtoupper((string) ($row['boolean_connector'] ?? 'AND'));
            if (!in_array($optionId, $validOptions, true)) throw new RuntimeException('条件关联的物料选项无效。');
            if (!isset(self::CONDITION_FIELDS[$field])) throw new RuntimeException('请选择允许的条件字段，不能填写代码表达式。');
            if (!isset(self::CONDITION_OPERATORS[$operator])) throw new RuntimeException('条件运算符无效。');
            if (!in_array($connector, ['AND', 'OR'], true)) throw new RuntimeException('条件组合只能使用 AND 或 OR。');
            $expected = $row['expected'] ?? null;
            if ($expected === '' || $expected === null || ($operator === 'between' && (!is_array($expected) || count($expected) !== 2))) {
                throw new RuntimeException('条件值不完整。');
            }
            $normalized[] = [
                'option_id' => $optionId,
                'condition_group_no' => max(1, (int) ($row['condition_group_no'] ?? 1)),
                'boolean_connector' => $index === 0 ? 'AND' : $connector,
                'field_code' => $field,
                'operator' => $operator,
                'expected' => $expected,
                'failure_message' => mb_substr(trim((string) ($row['failure_message'] ?? '当前物料不满足适用条件')), 0, 500),
                'severity' => ($row['severity'] ?? 'block') === 'warn' ? 'warn' : 'block',
                'sort_order' => ($index + 1) * 10,
            ];
        }
        $this->db->beginTransaction();
        try {
            if ($validOptions) {
                $marks = implode(',', array_fill(0, count($validOptions), '?'));
                $this->db->prepare("DELETE FROM mc_adaptation_conditions WHERE option_id IN($marks)")->execute($validOptions);
            }
            $insert = $this->db->prepare('INSERT INTO mc_adaptation_conditions(option_id,condition_group_no,boolean_connector,field_code,operator,expected_json,failure_message,severity,sort_order) VALUES(?,?,?,?,?,?,?,?,?)');
            foreach ($normalized as $row) {
                $insert->execute([
                    $row['option_id'], $row['condition_group_no'], $row['boolean_connector'], $row['field_code'], $row['operator'],
                    json_encode($row['expected'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    $row['failure_message'], $row['severity'], $row['sort_order'],
                ]);
            }
            $this->markProductDraft($productId);
            $this->log($productId, 'save_conditions', ['group_id' => $groupId, 'count' => count($normalized)], $userId);
            $this->db->commit();
            return ['saved' => count($normalized)];
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
    }

    public function saveConflict(array $data, int $userId): int
    {
        $productId = (int) ($data['product_id'] ?? 0);
        $left = (int) ($data['left_option_id'] ?? 0);
        $right = (int) ($data['right_option_id'] ?? 0);
        $reason = trim((string) ($data['reason'] ?? ''));
        if (!$productId || !$left || !$right || $left === $right || $reason === '') throw new RuntimeException('冲突选项和原因不能为空。');
        $belongs = $this->db->prepare('SELECT COUNT(*) FROM mc_adaptation_options o JOIN mc_adaptation_groups g ON g.id=o.group_id WHERE g.product_id=? AND o.id IN(?,?)');
        $belongs->execute([$productId, $left, $right]);
        if ((int) $belongs->fetchColumn() !== 2) throw new RuntimeException('冲突选项不属于当前产品。');
        if ($left > $right) [$left, $right] = [$right, $left];
        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare("INSERT INTO mc_adaptation_conflicts(product_id,left_option_id,right_option_id,reason,severity,status)
                VALUES(?,?,?,?,?,'active') ON DUPLICATE KEY UPDATE reason=VALUES(reason),severity=VALUES(severity),status='active'");
            $stmt->execute([$productId, $left, $right, $reason, in_array(($data['severity'] ?? ''), ['warn', 'block'], true) ? $data['severity'] : 'block']);
            $this->markProductDraft($productId);
            $find = $this->db->prepare('SELECT id FROM mc_adaptation_conflicts WHERE product_id=? AND left_option_id=? AND right_option_id=?');
            $find->execute([$productId, $left, $right]);
            $id = (int) $find->fetchColumn();
            $this->log($productId, 'save_conflict', ['conflict_id' => $id, 'reason' => $reason], $userId);
            $this->db->commit();
            return $id;
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
    }

    public function conflicts(int $productId): array
    {
        $stmt = $this->db->prepare("SELECT c.*,CONCAT(lm.material_code,' ',lm.name) left_material,CONCAT(rm.material_code,' ',rm.name) right_material
            FROM mc_adaptation_conflicts c
            JOIN mc_adaptation_options lo ON lo.id=c.left_option_id JOIN mc_materials lm ON lm.id=lo.material_id
            JOIN mc_adaptation_options ro ON ro.id=c.right_option_id JOIN mc_materials rm ON rm.id=ro.material_id
            WHERE c.product_id=? AND c.status='active' ORDER BY FIELD(c.severity,'block','warn'),c.id");
        $stmt->execute([$productId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function completion(int $productId): array
    {
        $groups = $this->groups($productId);
        $byTemplate = [];
        $issues = [];
        $earned = 0;
        foreach ($groups as $group) if ($group['template_key'] && $group['status'] !== 'disabled') $byTemplate[$group['template_key']] = $group;
        $requiredTemplates = array_filter(self::STANDARD_GROUPS, static fn(array $row): bool => (bool) $row[4]);
        $missing = [];
        foreach ($requiredTemplates as $template) if (!isset($byTemplate[$template[0]])) $missing[] = $template[1];
        if ($missing) $issues[] = '缺少必选配置组：'.implode('、', $missing);
        else $earned++;

        $requiredWithoutOptions = [];
        $singleWithoutDefault = [];
        foreach ($groups as $group) {
            if ($group['status'] === 'disabled') continue;
            if ((int) $group['is_required'] && !(int) $group['option_count']) $requiredWithoutOptions[] = $group['group_name'];
            if ((int) $group['is_required'] && $group['selection_mode'] === 'single' && !$group['default_material']) $singleWithoutDefault[] = $group['group_name'];
        }
        if ($requiredWithoutOptions) $issues[] = '必选组尚未添加选项：'.implode('、', $requiredWithoutOptions);
        else $earned++;
        if ($singleWithoutDefault) $issues[] = '单选必选组尚未设置默认：'.implode('、', $singleWithoutDefault);
        else $earned++;

        $conflicts = $this->conflicts($productId);
        if ($conflicts) $issues[] = '存在 '.count($conflicts).' 条未解决冲突';
        else $earned++;

        $disabledStmt = $this->db->prepare("SELECT COUNT(*) FROM mc_adaptation_options o JOIN mc_adaptation_groups g ON g.id=o.group_id JOIN mc_materials m ON m.id=o.material_id WHERE g.product_id=? AND m.status<>'official'");
        $disabledStmt->execute([$productId]);
        $disabled = (int) $disabledStmt->fetchColumn();
        if ($disabled) $issues[] = "存在 {$disabled} 个已停用或非正式物料";
        else $earned++;

        $exceptionStmt = $this->db->prepare('SELECT COUNT(*) FROM mc_adaptation_options o JOIN mc_adaptation_groups g ON g.id=o.group_id WHERE g.product_id=? AND o.requires_approval=1 AND o.exception_approved=0');
        $exceptionStmt->execute([$productId]);
        $exceptions = (int) $exceptionStmt->fetchColumn();
        if ($exceptions) $issues[] = "存在 {$exceptions} 个未批准的适配例外";
        else $earned++;

        $invalidConditionStmt = $this->db->prepare("SELECT COUNT(*) FROM mc_adaptation_conditions c JOIN mc_adaptation_options o ON o.id=c.option_id JOIN mc_adaptation_groups g ON g.id=o.group_id WHERE g.product_id=? AND (c.field_code='' OR c.operator='' OR c.expected_json IS NULL)");
        $invalidConditionStmt->execute([$productId]);
        $invalidConditions = (int) $invalidConditionStmt->fetchColumn();
        if ($invalidConditions) $issues[] = "存在 {$invalidConditions} 条不完整适用条件";
        else $earned++;

        if ($groups) $earned++;
        else $issues[] = '尚未建立任何配置组';
        $percent = (int) round($earned / 8 * 100);
        return [
            'percent' => $percent,
            'ready' => !$issues,
            'issues' => $issues,
            'exception_count' => $exceptions,
            'checks_passed' => $earned,
            'checks_total' => 8,
        ];
    }

    public function evaluate(int $productId, array $optionIds, array $context = []): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $optionIds))));
        if (!$ids) return ['compatible' => true, 'reasons' => [], 'price_impact' => 0.0, 'lead_time_impact_days' => 0];
        $marks = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->db->prepare("SELECT o.id,o.material_id,o.price_impact,o.lead_time_impact_days,g.group_type FROM mc_adaptation_options o JOIN mc_adaptation_groups g ON g.id=o.group_id WHERE g.product_id=? AND o.id IN($marks)");
        $stmt->execute(array_merge([$productId], $ids));
        $options = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (count($options) !== count($ids)) throw new RuntimeException('选择中包含不属于该产品的适配选项。');
        $reasons = [];
        $condition = $this->db->prepare("SELECT option_id,condition_group_no,boolean_connector,field_code,operator,expected_json,failure_message,severity FROM mc_adaptation_conditions WHERE option_id IN($marks) ORDER BY condition_group_no,sort_order,id");
        $condition->execute($ids);
        $conditionGroups = [];
        foreach ($condition->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $conditionGroups[$row['option_id'].':'.$row['condition_group_no']][] = $row;
        }
        foreach ($conditionGroups as $rows) {
            $result = null;
            $failed = [];
            $severity = 'warn';
            foreach ($rows as $index => $row) {
                $actual = $context[$row['field_code']] ?? null;
                $expected = json_decode((string) $row['expected_json'], true);
                $matched = $this->conditionMatches($actual, (string) $row['operator'], $expected);
                if (!$matched) {
                    $failed[] = (string) $row['failure_message'];
                    if ($row['severity'] === 'block') $severity = 'block';
                }
                $result = $index === 0
                    ? $matched
                    : ($row['boolean_connector'] === 'OR' ? $result || $matched : $result && $matched);
            }
            if (!$result) {
                $first = $rows[0];
                $reasons[] = [
                    'type' => 'condition',
                    'option_id' => (int) $first['option_id'],
                    'severity' => $severity,
                    'reason' => implode('；', array_values(array_unique($failed))),
                    'condition_group_no' => (int) $first['condition_group_no'],
                ];
            }
        }
        $conflict = $this->db->prepare("SELECT id,left_option_id,right_option_id,reason,severity FROM mc_adaptation_conflicts WHERE product_id=? AND status='active' AND left_option_id IN($marks) AND right_option_id IN($marks)");
        $conflict->execute(array_merge([$productId], $ids, $ids));
        foreach ($conflict->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $reasons[] = ['type' => 'conflict', 'conflict_id' => (int) $row['id'], 'severity' => $row['severity'], 'reason' => $row['reason'], 'left_option_id' => (int) $row['left_option_id'], 'right_option_id' => (int) $row['right_option_id']];
        }
        $reasons = array_merge($reasons, $this->powerCompatibilityReasons($productId, $options));
        $price = array_sum(array_map(static fn(array $row): float => (float) ($row['price_impact'] ?? 0), $options));
        $lead = max(array_map(static fn(array $row): int => (int) ($row['lead_time_impact_days'] ?? 0), $options));
        return ['compatible' => !array_filter($reasons, static fn(array $row): bool => $row['severity'] === 'block'), 'reasons' => $reasons, 'price_impact' => $price, 'lead_time_impact_days' => $lead];
    }

    public function approveProduct(int $productId, int $userId, bool $approveExceptions = false): void
    {
        $completion = $this->completion($productId);
        $issues = $completion['issues'];
        if ($completion['exception_count'] && $approveExceptions) {
            $issues = array_values(array_filter($issues, static fn(string $issue): bool => !str_contains($issue, '未批准的适配例外')));
        }
        if ($issues) throw new RuntimeException('暂不能提交审批：'.implode('；', $issues));
        if ($this->hasCycle($productId)) throw new RuntimeException('适配替代关系存在循环。');
        $this->db->beginTransaction();
        try {
            if ($completion['exception_count'] && $approveExceptions) {
                $this->db->prepare('UPDATE mc_adaptation_options o JOIN mc_adaptation_groups g ON g.id=o.group_id SET o.exception_approved=1 WHERE g.product_id=? AND o.requires_approval=1')
                    ->execute([$productId]);
                $completion = $this->completion($productId);
            }
            $groups = $this->groups($productId);
            $optionCount = array_sum(array_map(static fn(array $group): int => (int) $group['option_count'], $groups));
            $versionStmt = $this->db->prepare('SELECT COALESCE(MAX(version_no),0)+1 FROM mc_adaptation_approvals WHERE product_id=?');
            $versionStmt->execute([$productId]);
            $version = (int) $versionStmt->fetchColumn();
            $snapshot = json_encode(['groups' => $groups, 'conflicts' => $this->conflicts($productId), 'completion' => $completion], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $this->db->prepare("INSERT INTO mc_approvals(approval_type,entity_type,entity_id,status,requested_by,requested_at,completed_at,current_step,request_json) VALUES('product_adaptation','product',?,'approved',?,NOW(),NOW(),1,?)")
                ->execute([$productId, $userId, $snapshot]);
            $approvalId = (int) $this->db->lastInsertId();
            $this->db->prepare("INSERT INTO mc_adaptation_approvals(product_id,approval_id,version_no,status) VALUES(?,?,?,'approved')")
                ->execute([$productId, $approvalId, $version]);
            $this->db->prepare('INSERT INTO mc_approval_logs(approval_id,action,actor_id,detail_json,created_at) VALUES(?,?,?,?,NOW())')
                ->execute([$approvalId, 'approve', $userId, json_encode(['version' => $version, 'exceptions_approved' => $approveExceptions], JSON_UNESCAPED_UNICODE)]);
            $this->db->prepare("UPDATE mc_adaptation_groups SET status='approved',is_enabled=1,updated_by=?,updated_at=NOW() WHERE product_id=? AND status<>'disabled'")
                ->execute([$userId, $productId]);
            $this->db->prepare("UPDATE mc_adaptation_options o JOIN mc_adaptation_groups g ON g.id=o.group_id SET o.status='approved' WHERE g.product_id=?")
                ->execute([$productId]);
            $this->log($productId, 'approve', ['groups' => count($groups), 'options' => $optionCount, 'version' => $version, 'approval_id' => $approvalId], $userId);
            $this->db->commit();
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
    }

    public function approved(int $legacyProductId): array
    {
        $stmt = $this->db->prepare("SELECT p.product_code,p.product_name,g.group_code,g.group_name,g.business_type,g.is_required,g.selection_mode,
            o.option_type,o.is_default,o.price_impact,o.lead_time_impact_days,m.id material_id,m.material_code,m.name material_name,m.brand,m.model
            FROM mc_products p
            JOIN mc_adaptation_groups g ON g.product_id=p.id AND g.status='approved' AND g.is_enabled=1
            JOIN mc_adaptation_options o ON o.group_id=g.id AND o.status='approved' AND o.option_type<>'disabled'
            JOIN mc_materials m ON m.id=o.material_id AND m.status='official' AND m.is_official=1 AND m.deleted_at IS NULL
            WHERE p.legacy_table='naming_models' AND p.legacy_id=?
            AND NOT EXISTS(SELECT 1 FROM mc_adaptation_groups gx WHERE gx.product_id=p.id AND gx.status<>'disabled' AND (gx.status<>'approved' OR gx.is_enabled=0))
            AND NOT EXISTS(SELECT 1 FROM mc_adaptation_options ox JOIN mc_adaptation_groups gox ON gox.id=ox.group_id WHERE gox.product_id=p.id AND gox.status<>'disabled' AND ox.status<>'approved')
            ORDER BY g.sort_order,o.sort_order");
        $stmt->execute([$legacyProductId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function candidateMatch(array $material, array $group, ?array $rule): array
    {
        if ($material['status'] !== 'official') {
            return ['match_level' => 'incompatible', 'match_label' => '不适配', 'conflict_reasons' => ['物料已经停用'], 'requires_approval' => true];
        }
        if ($group['material_category_code'] !== 'power_supply') {
            if (($group['business_type'] ?? '') === 'custom') {
                return ['match_level' => 'needs_approval', 'match_label' => '需要审批', 'conflict_reasons' => ['自定义配置组尚无自动规格规则，需要工程审批'], 'requires_approval' => true];
            }
            return ['match_level' => 'conditional', 'match_label' => '条件适配', 'conflict_reasons' => ['物料类别匹配，需结合适用条件确认'], 'requires_approval' => false];
        }
        if (!$rule) {
            return ['match_level' => 'needs_approval', 'match_label' => '需要审批', 'conflict_reasons' => ['当前产品尚未维护电源匹配规则'], 'requires_approval' => true];
        }
        if ($material['max_output_power_w'] === null) {
            return ['match_level' => 'needs_approval', 'match_label' => '需要审批', 'conflict_reasons' => ['电源关键规格不完整，无法自动完成匹配'], 'requires_approval' => true];
        }
        $reasons = $this->comparePower($material, $rule);
        if ($reasons) return ['match_level' => 'incompatible', 'match_label' => '不适配', 'conflict_reasons' => $reasons, 'requires_approval' => true];
        if (($rule['status'] ?? '') !== 'approved') {
            return ['match_level' => 'needs_approval', 'match_label' => '需要审批', 'conflict_reasons' => ['产品电源规则尚未审批'], 'requires_approval' => true];
        }
        return ['match_level' => 'exact', 'match_label' => '完全适配', 'conflict_reasons' => [], 'requires_approval' => false];
    }

    private function comparePower(array $power, array $rule): array
    {
        $code = (string) $power['material_code'];
        $reasons = [];
        if ($rule['installation_type'] !== 'unknown' && $power['installation_type'] !== $rule['installation_type']) $reasons[] = '安装方式不匹配';
        if ($rule['output_type'] !== 'unknown' && $power['output_type'] !== $rule['output_type']) $reasons[] = '输出类型不匹配';
        if ($rule['lamp_power_w'] !== null && ($power['max_output_power_w'] === null || (float) $power['max_output_power_w'] < (float) $rule['lamp_power_w'])) $reasons[] = '功率超出产品允许范围';
        if (!$this->rangeOverlaps($rule['output_current_min_ma'], $rule['output_current_max_ma'], $power['output_current_min_ma'], $power['output_current_max_ma'])) $reasons[] = '输出电流高于芯片允许值或范围不相交';
        if (!$this->rangeOverlaps($rule['output_voltage_min_v'], $rule['output_voltage_max_v'], $power['output_voltage_min_v'], $power['output_voltage_max_v'])) $reasons[] = '输出电压范围不匹配';
        foreach (['length' => '长度', 'width' => '宽度', 'height' => '高度'] as $key => $label) {
            $max = $rule['max_'.$key.'_mm'];
            $actual = $power[$key.'_mm'];
            if ($max !== null && ($actual === null || (float) $actual > (float) $max)) {
                $reasons[] = $actual === null ? "电源{$label}未确认" : '电源'.$label.'超过灯体内部空间 '.$this->number((float) $actual - (float) $max).'mm';
            }
        }
        if ($rule['minimum_warranty_years'] !== null && ($power['supplier_warranty_years'] === null || (float) $power['supplier_warranty_years'] < (float) $rule['minimum_warranty_years'])) {
            $reasons[] = '产品要求'.$this->number((float) $rule['minimum_warranty_years']).'年质保，但电源只有'.($power['supplier_warranty_years'] ?? '未确认').'年';
        }
        if ($rule['certification_required'] && !str_contains(mb_strtolower((string) $power['certification']), mb_strtolower((string) $rule['certification_required']))) {
            $reasons[] = '缺少产品要求的 '.$rule['certification_required'].' 认证';
        }
        $required = $rule['required_dimming_modes'] ?? [];
        $available = array_filter(explode(',', (string) ($power['dimming_modes'] ?? '')));
        foreach ($required as $mode) if (!in_array($mode, $available, true)) $reasons[] = '调光方式不匹配：产品要求 '.$mode;
        return array_map(static fn(string $reason): string => $code.'：'.$reason, $reasons);
    }

    private function productPowerRule(int $legacyProductId): ?array
    {
        if (!$legacyProductId) return null;
        $stmt = $this->db->prepare('SELECT * FROM mc_product_power_rules WHERE legacy_product_id=? ORDER BY updated_at DESC,id DESC LIMIT 1');
        $stmt->execute([$legacyProductId]);
        $rule = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$rule) return null;
        $modes = $this->db->prepare('SELECT mode FROM mc_product_power_rule_dimming_modes WHERE rule_id=?');
        $modes->execute([$rule['id']]);
        $rule['required_dimming_modes'] = $modes->fetchAll(PDO::FETCH_COLUMN);
        return $rule;
    }

    private function powerCompatibilityReasons(int $productId, array $options): array
    {
        $legacy = $this->db->prepare("SELECT legacy_id FROM mc_products WHERE id=? AND legacy_table='naming_models'");
        $legacy->execute([$productId]);
        $rule = $this->productPowerRule((int) $legacy->fetchColumn());
        if (!$rule) return [];
        $powerIds = array_values(array_unique(array_map('intval', array_column(array_filter($options, static fn(array $row): bool => $row['group_type'] === 'power'), 'material_id'))));
        if (!$powerIds) return [];
        $marks = implode(',', array_fill(0, count($powerIds), '?'));
        $stmt = $this->db->prepare("SELECT m.id,m.material_code,p.*,(SELECT GROUP_CONCAT(d.mode ORDER BY d.mode) FROM mc_power_supply_dimming_modes d WHERE d.material_id=m.id) dimming_modes FROM mc_materials m JOIN mc_power_supply_specs p ON p.material_id=m.id WHERE m.id IN($marks)");
        $stmt->execute($powerIds);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $power) {
            foreach ($this->comparePower($power, $rule) as $reason) $out[] = ['type' => 'power_rule', 'material_id' => (int) $power['id'], 'severity' => 'block', 'reason' => $reason];
        }
        return $out;
    }

    private function groupDisplayStatus(array $group): string
    {
        if (($group['status'] ?? '') === 'disabled') return 'disabled';
        if ((int) ($group['conflict_count'] ?? 0)) return 'conflict';
        if (!(int) ($group['option_count'] ?? 0)) return 'empty';
        if (($group['selection_mode'] ?? 'single') === 'single' && (int) ($group['is_required'] ?? 0) && empty($group['default_material'])) return 'no_default';
        if (($group['status'] ?? '') === 'approved' && (int) ($group['is_enabled'] ?? 0)) return 'enabled';
        return 'pending';
    }

    private function markProductDraft(int $productId): void
    {
        $this->db->prepare("UPDATE mc_adaptation_groups SET status='draft',is_enabled=0,updated_at=NOW() WHERE product_id=? AND status<>'disabled'")->execute([$productId]);
        $this->db->prepare("UPDATE mc_adaptation_options o JOIN mc_adaptation_groups g ON g.id=o.group_id SET o.status='draft' WHERE g.product_id=?")->execute([$productId]);
    }

    private function assertMeaningfulName(string $name): void
    {
        $normalized = mb_strtolower(preg_replace('/\s+/u', '', $name));
        $blocked = ['test', 'abc', 'aaa', 'asdf', '测试', '未命名', '新建配置组', '配置组'];
        if ($name === '' || preg_match('/^\d+$/u', $normalized) || in_array($normalized, $blocked, true) || mb_strlen($normalized) < 2) {
            throw new RuntimeException('配置组名称必须表达明确业务含义，不能使用纯数字、测试词或无意义名称。');
        }
    }

    private function legacyGroupType(string $category): string
    {
        return [
            'power_supply' => 'power',
            'chip' => 'chip',
            'optical' => 'optical',
            'connector' => 'connector',
            'accessory' => 'accessory',
            'packaging' => 'packaging',
            'profile' => 'custom',
        ][$category] ?? 'custom';
    }

    private function keySpecs(array $row, string $category): string
    {
        if ($category !== 'power_supply') return trim(($row['brand'] ?? '').' '.($row['model'] ?? ''));
        $parts = [];
        if ($row['power_band']) $parts[] = $row['power_band'];
        if ($row['max_output_power_w'] !== null) $parts[] = $this->number((float) $row['max_output_power_w']).'W';
        if ($row['output_current_ma'] !== null) $parts[] = $this->number((float) $row['output_current_ma']).'mA';
        if ($row['output_voltage_min_v'] !== null || $row['output_voltage_max_v'] !== null) $parts[] = ($row['output_voltage_min_v'] ?? '?').'–'.($row['output_voltage_max_v'] ?? '?').'V';
        if ($row['installation_type'] && $row['installation_type'] !== 'unknown') $parts[] = $row['installation_type'];
        if ($row['dimming_modes']) $parts[] = $row['dimming_modes'];
        if ($row['supplier_warranty_years'] !== null) $parts[] = $row['supplier_warranty_years'].'年质保';
        return implode(' · ', $parts);
    }

    private function conditionMatches(mixed $actual, string $operator, mixed $expected): bool
    {
        return match ($operator) {
            'eq' => $actual == $expected,
            'neq' => $actual != $expected,
            'gt' => is_numeric($actual) && is_numeric($expected) && $actual > $expected,
            'gte' => is_numeric($actual) && is_numeric($expected) && $actual >= $expected,
            'lt' => is_numeric($actual) && is_numeric($expected) && $actual < $expected,
            'lte' => is_numeric($actual) && is_numeric($expected) && $actual <= $expected,
            'between' => is_numeric($actual) && is_array($expected) && count($expected) === 2 && $actual >= $expected[0] && $actual <= $expected[1],
            'in' => is_array($expected) && in_array($actual, $expected, true),
            'contains' => str_contains((string) $actual, (string) $expected),
            default => false,
        };
    }

    private function rangeOverlaps(mixed $requiredMin, mixed $requiredMax, mixed $actualMin, mixed $actualMax): bool
    {
        if ($requiredMin === null && $requiredMax === null) return true;
        if ($actualMin === null && $actualMax === null) return false;
        $aMin = $actualMin ?? $actualMax;
        $aMax = $actualMax ?? $actualMin;
        return !($requiredMin !== null && (float) $aMax < (float) $requiredMin)
            && !($requiredMax !== null && (float) $aMin > (float) $requiredMax);
    }

    private function optionIds(int $groupId): array
    {
        $stmt = $this->db->prepare('SELECT id FROM mc_adaptation_options WHERE group_id=? ORDER BY id');
        $stmt->execute([$groupId]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    private function optionExists(int $groupId, int $materialId): bool
    {
        $stmt = $this->db->prepare('SELECT 1 FROM mc_adaptation_options WHERE group_id=? AND material_id=?');
        $stmt->execute([$groupId, $materialId]);
        return (bool) $stmt->fetchColumn();
    }

    private function commercialGroupReferenced(string $groupCode): bool
    {
        $needle = '%mc_'.$groupCode.'%';
        foreach ([
            ['cc_configuration_instances', 'values_json'],
            ['cc_configuration_snapshots', 'snapshot_json'],
            ['cc_quote_items', 'configuration_snapshot'],
            ['cc_quote_item_snapshots', 'configuration_snapshot'],
        ] as [$table, $column]) {
            if (!$this->tableExists($table)) continue;
            $stmt = $this->db->prepare("SELECT 1 FROM $table WHERE $column LIKE ? LIMIT 1");
            $stmt->execute([$needle]);
            if ($stmt->fetchColumn()) return true;
        }
        return false;
    }

    private function hasCycle(int $productId): bool
    {
        return false;
    }

    private function tableExists(string $table): bool
    {
        $stmt = $this->db->prepare('SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
        $stmt->execute([$table]);
        return (bool) $stmt->fetchColumn();
    }

    private function number(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }

    private function log(int $productId, string $action, array $detail, int $userId): void
    {
        $stmt = $this->db->prepare('INSERT INTO mc_adaptation_logs(product_id,action,detail_json,created_by,created_at) VALUES(?,?,?,?,NOW())');
        $stmt->execute([$productId ?: null, $action, json_encode($detail, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $userId]);
    }
}
