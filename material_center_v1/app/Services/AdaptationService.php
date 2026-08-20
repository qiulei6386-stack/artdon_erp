<?php
declare(strict_types=1);

namespace Artdon\MaterialCenter\Services;

use Artdon\MaterialCenter\Adapters\LegacyProductAdapter;
use PDO;
use RuntimeException;

final class AdaptationService
{
    private const BUSINESS_TYPES = [
        'chip' => ['label' => '芯片 / 光源', 'category' => 'chip', 'default_name' => '芯片 / 光源'],
        'driver' => ['label' => '驱动', 'category' => 'power_supply', 'default_name' => '驱动'],
        'power' => ['label' => '电源 / 驱动', 'category' => 'power_supply', 'default_name' => '电源 / 驱动'],
        'optical' => ['label' => '光学 / 透镜', 'category' => 'optical', 'default_name' => '光学 / 透镜'],
        'honeycomb' => ['label' => '蜂巢网', 'category' => 'accessory', 'default_name' => '蜂巢网'],
        'glass' => ['label' => '玻璃', 'category' => 'optical', 'default_name' => '玻璃'],
        'reflector' => ['label' => '反光杯 / 格栅', 'category' => 'optical', 'default_name' => '反光杯 / 格栅'],
        'accessory' => ['label' => '附件配件', 'category' => 'accessory', 'default_name' => '附件配件'],
        'color' => ['label' => '外观颜色', 'category' => 'accessory', 'default_name' => '外观颜色'],
        'installation' => ['label' => '安装方式', 'category' => 'connector', 'default_name' => '安装方式'],
        'dimming' => ['label' => '调光方式', 'category' => 'power_supply', 'default_name' => '调光方式'],
        'special' => ['label' => '特殊要求', 'category' => 'accessory', 'default_name' => '特殊要求'],
        'custom' => ['label' => '自定义用途', 'category' => null, 'default_name' => '自定义配置'],
    ];

    private const STANDARD_GROUPS = [
        ['light_source', '芯片 / 光源', 'chip', 'chip', 1, 'single'],
        ['power_driver', '电源 / 驱动', 'power', 'power_supply', 1, 'single'],
        ['optical', '光学 / 透镜', 'optical', 'optical', 1, 'single'],
        ['dimming', '调光方式', 'dimming', 'power_supply', 0, 'multi'],
        ['honeycomb', '蜂巢网', 'honeycomb', 'accessory', 0, 'single'],
        ['protective_glass', '玻璃', 'glass', 'optical', 0, 'single'],
        ['accessories', '附件配件', 'accessory', 'accessory', 0, 'multi'],
        ['finish_color', '外观颜色', 'color', 'accessory', 0, 'multi'],
        ['installation', '安装方式', 'installation', 'connector', 1, 'single'],
        ['special_requirements', '特殊要求', 'special', 'accessory', 0, 'multi'],
    ];

    private const CONDITION_FIELDS = [
        'product_power_w' => '产品功率',
        'chip_current_min_ma' => '芯片最小电流',
        'chip_current_max_ma' => '芯片最大电流',
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
        'not_contains' => '不包含',
        'gt' => '大于',
        'gte' => '大于等于',
        'lt' => '小于',
        'lte' => '小于等于',
        'between' => '介于',
        'in' => '属于',
        'not_in' => '不属于',
        'has_value' => '有值',
        'no_value' => '无值',
    ];

    private const QUICK_RULE_FIELDS = [
        'chip' => [
            ['key' => 'power_min_w', 'label' => '芯片功率下限', 'type' => 'number', 'unit' => 'W'],
            ['key' => 'power_max_w', 'label' => '芯片功率上限', 'type' => 'number', 'unit' => 'W'],
            ['key' => 'current_min_ma', 'label' => '电流下限', 'type' => 'number', 'unit' => 'mA'],
            ['key' => 'current_max_ma', 'label' => '电流上限', 'type' => 'number', 'unit' => 'mA'],
            ['key' => 'voltage_min_v', 'label' => '电压下限', 'type' => 'number', 'unit' => 'V'],
            ['key' => 'voltage_max_v', 'label' => '电压上限', 'type' => 'number', 'unit' => 'V'],
            ['key' => 'package_contains', 'label' => '封装包含', 'type' => 'text', 'placeholder' => '例如 COB'],
            ['key' => 'les_contains', 'label' => 'LES / 尺寸包含', 'type' => 'text', 'placeholder' => '例如 9mm'],
        ],
        'optical' => [
            ['key' => 'diameter_min_mm', 'label' => '直径下限', 'type' => 'number', 'unit' => 'mm'],
            ['key' => 'diameter_max_mm', 'label' => '直径上限', 'type' => 'number', 'unit' => 'mm'],
            ['key' => 'height_max_mm', 'label' => '最大高度', 'type' => 'number', 'unit' => 'mm'],
            ['key' => 'beam_min_deg', 'label' => '光束角下限', 'type' => 'number', 'unit' => '°'],
            ['key' => 'beam_max_deg', 'label' => '光束角上限', 'type' => 'number', 'unit' => '°'],
            ['key' => 'les_contains', 'label' => '适配 LES 包含', 'type' => 'text', 'placeholder' => '例如 9mm'],
            ['key' => 'mounting_contains', 'label' => '固定方式包含', 'type' => 'text', 'placeholder' => '例如 卡扣'],
        ],
        'glass' => [
            ['key' => 'diameter_min_mm', 'label' => '玻璃直径下限', 'type' => 'number', 'unit' => 'mm'],
            ['key' => 'diameter_max_mm', 'label' => '玻璃直径上限', 'type' => 'number', 'unit' => 'mm'],
            ['key' => 'height_max_mm', 'label' => '最大厚度', 'type' => 'number', 'unit' => 'mm'],
            ['key' => 'material_contains', 'label' => '材质包含', 'type' => 'text', 'placeholder' => '例如 钢化'],
            ['key' => 'allow_with_honeycomb', 'label' => '允许与蜂巢网同时安装', 'type' => 'select', 'options' => ['yes' => '允许', 'no' => '不允许']],
        ],
        'reflector' => [
            ['key' => 'diameter_min_mm', 'label' => '直径下限', 'type' => 'number', 'unit' => 'mm'],
            ['key' => 'diameter_max_mm', 'label' => '直径上限', 'type' => 'number', 'unit' => 'mm'],
            ['key' => 'height_max_mm', 'label' => '最大高度', 'type' => 'number', 'unit' => 'mm'],
            ['key' => 'mounting_contains', 'label' => '固定方式包含', 'type' => 'text', 'placeholder' => '例如 卡扣'],
        ],
        'honeycomb' => [
            ['key' => 'diameter_min_mm', 'label' => '蜂巢网直径下限', 'type' => 'number', 'unit' => 'mm'],
            ['key' => 'diameter_max_mm', 'label' => '蜂巢网直径上限', 'type' => 'number', 'unit' => 'mm'],
            ['key' => 'thickness_max_mm', 'label' => '最大叠加高度', 'type' => 'number', 'unit' => 'mm'],
            ['key' => 'interface_contains', 'label' => '接口包含', 'type' => 'text', 'placeholder' => '例如 卡扣'],
            ['key' => 'position_contains', 'label' => '安装位置包含', 'type' => 'text', 'placeholder' => '例如 透镜前'],
            ['key' => 'allow_with_glass', 'label' => '允许与玻璃同时安装', 'type' => 'select', 'options' => ['yes' => '允许', 'no' => '不允许']],
        ],
        'accessory' => [
            ['key' => 'type_contains', 'label' => '配件类别包含', 'type' => 'text', 'placeholder' => '例如 防眩罩'],
            ['key' => 'diameter_min_mm', 'label' => '直径下限', 'type' => 'number', 'unit' => 'mm'],
            ['key' => 'diameter_max_mm', 'label' => '直径上限', 'type' => 'number', 'unit' => 'mm'],
            ['key' => 'thickness_max_mm', 'label' => '最大厚度 / 高度', 'type' => 'number', 'unit' => 'mm'],
            ['key' => 'interface_contains', 'label' => '接口包含', 'type' => 'text', 'placeholder' => '例如 卡扣'],
            ['key' => 'position_contains', 'label' => '安装位置包含', 'type' => 'text', 'placeholder' => '例如 灯体前端'],
        ],
        'installation' => [
            ['key' => 'interface_contains', 'label' => '接口包含', 'type' => 'text', 'placeholder' => '例如 三线轨道'],
            ['key' => 'installation_contains', 'label' => '安装方式包含', 'type' => 'text', 'placeholder' => '例如 吊装'],
            ['key' => 'load_min_kg', 'label' => '最低承重', 'type' => 'number', 'unit' => 'kg'],
        ],
        'color' => [
            ['key' => 'color_contains', 'label' => '颜色包含', 'type' => 'text', 'placeholder' => '例如 黑色'],
        ],
    ];

    private const POWER_RULE_FIELDS = [
        ['key' => 'installation_type', 'label' => '安装方式', 'type' => 'select', 'options' => ['unknown' => '待确认', 'internal' => '内置', 'external' => '外置']],
        ['key' => 'output_type', 'label' => '输出类型', 'type' => 'select', 'options' => ['unknown' => '待确认', 'constant_current' => '恒流', 'constant_voltage' => '恒压']],
        ['key' => 'lamp_power_min_w', 'label' => '灯具最低功率', 'type' => 'number', 'unit' => 'W'],
        ['key' => 'lamp_power_max_w', 'label' => '灯具最高功率', 'type' => 'number', 'unit' => 'W'],
        ['key' => 'output_current_min_ma', 'label' => '输出电流下限', 'type' => 'number', 'unit' => 'mA'],
        ['key' => 'output_current_max_ma', 'label' => '输出电流上限', 'type' => 'number', 'unit' => 'mA'],
        ['key' => 'output_voltage_min_v', 'label' => '输出电压下限', 'type' => 'number', 'unit' => 'V'],
        ['key' => 'output_voltage_max_v', 'label' => '输出电压上限', 'type' => 'number', 'unit' => 'V'],
        ['key' => 'max_length_mm', 'label' => '最大长度', 'type' => 'number', 'unit' => 'mm'],
        ['key' => 'max_width_mm', 'label' => '最大宽度', 'type' => 'number', 'unit' => 'mm'],
        ['key' => 'max_height_mm', 'label' => '最大高度', 'type' => 'number', 'unit' => 'mm'],
        ['key' => 'minimum_warranty_years', 'label' => '最低供应商质保', 'type' => 'number', 'unit' => '年'],
        ['key' => 'certification_required', 'label' => '必须认证', 'type' => 'text', 'placeholder' => '例如 CE / ENEC'],
    ];

    /* Product-level technical facts are deliberately independent from a configuration group.
       A group can be copied or disabled; the engineering envelope of the lamp must not vanish. */
    private const TECHNICAL_PROFILE_FIELDS = [
        ['key' => 'lamp_power_min_w', 'label' => '灯具最低功率', 'type' => 'number', 'unit' => 'W', 'section' => '电气范围'],
        ['key' => 'lamp_power_max_w', 'label' => '灯具最高功率', 'type' => 'number', 'unit' => 'W', 'section' => '电气范围'],
        ['key' => 'output_current_min_ma', 'label' => '输出电流下限', 'type' => 'number', 'unit' => 'mA', 'section' => '电气范围'],
        ['key' => 'output_current_max_ma', 'label' => '输出电流上限', 'type' => 'number', 'unit' => 'mA', 'section' => '电气范围'],
        ['key' => 'output_voltage_min_v', 'label' => '输出电压下限', 'type' => 'number', 'unit' => 'V', 'section' => '电气范围'],
        ['key' => 'output_voltage_max_v', 'label' => '输出电压上限', 'type' => 'number', 'unit' => 'V', 'section' => '电气范围'],
        ['key' => 'installation_type', 'label' => '电源安装方式', 'type' => 'select', 'section' => '结构与环境', 'options' => ['unknown' => '待确认', 'internal' => '内置', 'external' => '外置']],
        ['key' => 'max_length_mm', 'label' => '最大长度', 'type' => 'number', 'unit' => 'mm', 'section' => '结构与环境'],
        ['key' => 'max_width_mm', 'label' => '最大宽度', 'type' => 'number', 'unit' => 'mm', 'section' => '结构与环境'],
        ['key' => 'max_height_mm', 'label' => '最大高度', 'type' => 'number', 'unit' => 'mm', 'section' => '结构与环境'],
        ['key' => 'ip_rating', 'label' => '防护等级', 'type' => 'text', 'section' => '结构与环境', 'placeholder' => '例如 IP65'],
        ['key' => 'certification_required', 'label' => '必须认证', 'type' => 'text', 'section' => '结构与环境', 'placeholder' => '例如 CE / ENEC'],
        ['key' => 'minimum_warranty_years', 'label' => '最低供应商质保', 'type' => 'number', 'unit' => '年', 'section' => '结构与环境'],
        ['key' => 'optical_les_mm', 'label' => 'LES 尺寸', 'type' => 'number', 'unit' => 'mm', 'section' => '光学范围'],
        ['key' => 'optical_diameter_mm', 'label' => '光学直径', 'type' => 'number', 'unit' => 'mm', 'section' => '光学范围'],
        ['key' => 'optical_height_mm', 'label' => '光学最大高度', 'type' => 'number', 'unit' => 'mm', 'section' => '光学范围'],
        ['key' => 'beam_angle_min_deg', 'label' => '光束角下限', 'type' => 'number', 'unit' => '°', 'section' => '光学范围'],
        ['key' => 'beam_angle_max_deg', 'label' => '光束角上限', 'type' => 'number', 'unit' => '°', 'section' => '光学范围'],
        ['key' => 'dimming_modes', 'label' => '调光方式', 'type' => 'multi', 'section' => '结构与环境', 'options' => ['0-10V' => '0-10V', 'DALI' => 'DALI', 'TRIAC' => 'TRIAC', 'PWM' => 'PWM', 'On/Off' => 'On/Off']],
        ['key' => 'engineering_note', 'label' => '工程备注', 'type' => 'textarea', 'section' => '补充说明'],
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
            'quick_rule_fields' => self::QUICK_RULE_FIELDS,
            'power_rule_fields' => self::POWER_RULE_FIELDS,
            'technical_profile_fields' => self::TECHNICAL_PROFILE_FIELDS,
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
        return $this->productRows($q);
    }

    /** @return array<int,array<string,mixed>> */
    private function productRows(string $q = '', int $productId = 0): array
    {
        $publishedVersionSql = $this->tableExists('mc_adaptation_published_versions')
            ? "(SELECT MAX(v.version_no) FROM mc_adaptation_published_versions v WHERE v.product_id=p.id AND v.status='published') published_version,"
            : "0 published_version,";
        $publishedCountSql = $this->tableExists('mc_adaptation_published_versions')
            ? "(SELECT COUNT(*) FROM mc_adaptation_published_versions v WHERE v.product_id=p.id AND v.status='published') published_version_count,"
            : "0 published_version_count,";
        $profileSql = $this->tableExists('mc_adaptation_product_profiles')
            ? "(SELECT pp.confirmed_at FROM mc_adaptation_product_profiles pp WHERE pp.product_id=p.id LIMIT 1) profile_confirmed_at,
            (SELECT pp.updated_at FROM mc_adaptation_product_profiles pp WHERE pp.product_id=p.id LIMIT 1) profile_updated_at,"
            : "NULL profile_confirmed_at, NULL profile_updated_at,";
        $lastProfileUpdatedSql = $this->tableExists('mc_adaptation_product_profiles')
            ? "COALESCE((SELECT MAX(pp.updated_at) FROM mc_adaptation_product_profiles pp WHERE pp.product_id=p.id),'1970-01-01 00:00:00')"
            : "'1970-01-01 00:00:00'";

        $sql = "SELECT p.*,
            COALESCE(NULLIF(n.web_image_url,''),NULLIF(n.source_image_url,''),NULLIF(n.image_path,'')) source_image_url,
            (SELECT COUNT(*) FROM mc_adaptation_groups g WHERE g.product_id=p.id) group_count,
            (SELECT COUNT(*) FROM mc_adaptation_groups g WHERE g.product_id=p.id AND g.status<>'disabled') active_group_count,
            (SELECT COUNT(*) FROM mc_adaptation_groups g WHERE g.product_id=p.id AND g.status<>'disabled' AND g.is_required=1) required_group_count,
            (SELECT COUNT(*) FROM mc_adaptation_groups g WHERE g.product_id=p.id AND g.status<>'disabled' AND g.is_required=1 AND EXISTS(SELECT 1 FROM mc_adaptation_options o WHERE o.group_id=g.id) AND (g.selection_mode<>'single' OR EXISTS(SELECT 1 FROM mc_adaptation_options od WHERE od.group_id=g.id AND od.is_default=1))) complete_required_group_count,
            (SELECT COUNT(*) FROM mc_adaptation_options o JOIN mc_adaptation_groups g ON g.id=o.group_id WHERE g.product_id=p.id) option_count,
            (SELECT COUNT(*) FROM mc_adaptation_groups g WHERE g.product_id=p.id AND g.status<>'disabled' AND (g.status<>'approved' OR g.is_enabled=0)) pending_group_count,
            (SELECT COUNT(*) FROM mc_adaptation_groups g WHERE g.product_id=p.id AND g.status='draft') draft_group_count,
            (SELECT COUNT(*) FROM mc_adaptation_options o JOIN mc_adaptation_groups g ON g.id=o.group_id WHERE g.product_id=p.id AND o.status='draft') draft_option_count,
            (SELECT COUNT(*) FROM mc_adaptation_conflicts c WHERE c.product_id=p.id AND c.status='active') conflict_count,
            (SELECT COUNT(*) FROM mc_adaptation_approvals aa JOIN mc_approvals ap ON ap.id=aa.approval_id WHERE aa.product_id=p.id AND aa.status='pending' AND ap.status='pending') pending_approval_count,
            (SELECT MAX(a.version_no) FROM mc_adaptation_approvals a WHERE a.product_id=p.id AND a.status='approved') approved_version,
            {$publishedVersionSql}
            {$publishedCountSql}
            {$profileSql}
            GREATEST(
                COALESCE(p.synced_at,'1970-01-01 00:00:00'),
                COALESCE((SELECT MAX(g.updated_at) FROM mc_adaptation_groups g WHERE g.product_id=p.id),'1970-01-01 00:00:00'),
                {$lastProfileUpdatedSql}
            ) last_configured_at
            FROM mc_products p
            LEFT JOIN naming_models n ON p.legacy_table='naming_models' AND n.id=p.legacy_id
            WHERE p.status='active'";
        $params = [];
        if ($productId > 0) {
            $sql .= ' AND p.id=?';
            $params[] = $productId;
        }
        if ($q !== '') {
            $sql .= " AND (p.product_code LIKE ? OR p.product_name LIKE ? OR JSON_UNQUOTE(JSON_EXTRACT(p.snapshot_json,'$.series_name')) LIKE ? OR JSON_UNQUOTE(JSON_EXTRACT(p.snapshot_json,'$.category')) LIKE ?)";
            $like = '%'.$q.'%';
            array_push($params, $like, $like, $like, $like);
        }
        $sql .= ' ORDER BY p.product_code LIMIT 2000';
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$row) {
            $this->decorateProductRow($row);
        }
        unset($row);
        return $rows;
    }

    public function product(int $productId): ?array
    {
        return $this->productRows('', $productId)[0] ?? null;
    }

    /** @param array<string,mixed> $row */
    private function decorateProductRow(array &$row): void
    {
        $snapshot = json_decode((string) ($row['snapshot_json'] ?? '{}'), true) ?: [];
        $row['series_name'] = $snapshot['series_name'] ?? $snapshot['category'] ?? '';
        $row['product_type'] = $snapshot['product_type'] ?? $snapshot['product_type_name'] ?? $snapshot['category'] ?? '';
        $row['image_url'] = !empty($snapshot['image_url'])
            ? $snapshot['image_url']
            : ($row['source_image_url'] ?? '');

        $activeGroups = (int) ($row['active_group_count'] ?? $row['group_count'] ?? 0);
        $optionCount = (int) ($row['option_count'] ?? 0);
        $requiredGroups = (int) ($row['required_group_count'] ?? 0);
        $completeRequired = (int) ($row['complete_required_group_count'] ?? 0);
        $conflicts = (int) ($row['conflict_count'] ?? 0);
        $pendingApprovals = (int) ($row['pending_approval_count'] ?? 0);
        $publishedVersion = (int) ($row['published_version'] ?? 0);
        $draftCount = (int) ($row['draft_group_count'] ?? 0) + (int) ($row['draft_option_count'] ?? 0);
        $profileConfirmed = !empty($row['profile_confirmed_at']);
        $hasWorkingData = $activeGroups > 0 || $optionCount > 0 || $profileConfirmed;

        if ($conflicts > 0) {
            $state = 'conflict';
        } elseif ($pendingApprovals > 0) {
            $state = 'pending_approval';
        } elseif ($publishedVersion > 0 && $draftCount === 0) {
            $state = 'published';
        } elseif (!$hasWorkingData) {
            $state = 'unconfigured';
        } elseif (!$profileConfirmed || $requiredGroups === 0 || $completeRequired < $requiredGroups) {
            $state = 'configuring';
        } else {
            $state = 'needs_check';
        }

        $technicalScore = $profileConfirmed ? 20 : 0;
        $coreScore = $requiredGroups > 0 ? (int) round(50 * min($completeRequired, $requiredGroups) / $requiredGroups) : 0;
        $coreReady = $profileConfirmed && $requiredGroups > 0 && $completeRequired >= $requiredGroups;
        $optionalScore = $coreReady && $activeGroups > $requiredGroups ? 10 : 0;
        $rulesScore = $coreReady && $activeGroups > 0 ? 10 : 0;
        $checkScore = ($coreReady && !$conflicts && !$pendingApprovals) ? 10 : 0;
        if (!$hasWorkingData) {
            $technicalScore = $coreScore = $optionalScore = $rulesScore = $checkScore = 0;
        }

        $labels = [
            'unconfigured' => '未配置',
            'configuring' => '配置中',
            'needs_check' => '待检查',
            'pending_approval' => '待审批',
            'published' => '已发布',
            'conflict' => '存在冲突',
        ];
        $row['has_conflict'] = $conflicts > 0;
        $row['configuration_state'] = $state;
        $row['config_state'] = $state;
        $row['approval_label'] = $labels[$state] ?? '配置中';
        $row['completion_percent'] = min(100, $technicalScore + $coreScore + $optionalScore + $rulesScore + $checkScore);
        $row['current_step_label'] = $state === 'unconfigured'
            ? '技术范围'
            : ($state === 'published' ? '版本发布' : ($completeRequired < $requiredGroups ? '核心物料' : '检查审批'));
        $row['published_version'] = $publishedVersion ?: null;
        $row['updated_at'] = $row['last_configured_at'] ?: ($row['synced_at'] ?? null);
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
        foreach ($rows as &$row) {
            $row['quick_rules'] = json_decode((string) ($row['rule_json'] ?? '{}'), true) ?: [];
            $row['display_status'] = $this->groupDisplayStatus($row);
        }
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
        $chipService = new ChipSpecificationService($this->db);
        foreach ($rows as &$row) {
            $row['match_reasons'] = json_decode((string) ($row['match_reason_json'] ?? '[]'), true) ?: [];
            if ($row['category_code'] === 'chip') {
                $specification = $chipService->optionVariants((int) $row['id']);
                $row['chip_variants'] = $specification['variants'];
                $row['selected_chip_variant_count'] = count(array_filter(
                    $specification['variants'],
                    static fn(array $variant): bool => (bool) $variant['is_selected']
                ));
                $default = array_values(array_filter(
                    $specification['variants'],
                    static fn(array $variant): bool => (bool) $variant['is_option_default']
                ));
                $row['default_chip_variant'] = $default[0]['label'] ?? null;
            }
        }
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

    /** @return array<int,array<string,mixed>> */
    public function publishedVersions(int $productId): array
    {
        if (!$this->tableExists('mc_adaptation_published_versions')) return [];
        $stmt = $this->db->prepare("SELECT v.version_no,v.status,v.published_at,v.approval_id,
                COALESCE(u.real_name,u.username,'系统管理员') publisher_name
            FROM mc_adaptation_published_versions v
            LEFT JOIN crm_users u ON u.id=v.published_by
            WHERE v.product_id=? AND v.status='published'
            ORDER BY v.version_no DESC LIMIT 12");
        $stmt->execute([$productId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function workspace(int $productId, int $groupId = 0): array
    {
        $product = $this->product($productId);
        if (!$product) throw new RuntimeException('产品不存在或已停用。', 404);
        $groups = $this->groups($productId);
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
            'published_versions' => $this->publishedVersions($productId),
            'completion' => $completion,
            'configuration_overview' => $this->configurationOverview($productId),
            'power_rule' => $this->productPowerRule((int) ($product['legacy_id'] ?? 0)),
            'technical_profile' => $this->technicalProfile($productId),
        ];
    }

    /** @return array{fields:array<int,array<string,mixed>>,values:array<string,mixed>,confirmed_at:?string,updated_at:?string} */
    public function technicalProfile(int $productId): array
    {
        $values = [];
        $confirmedAt = null;
        $updatedAt = null;
        if ($this->tableExists('mc_adaptation_product_profiles')) {
            $stmt = $this->db->prepare('SELECT profile_json,confirmed_at,updated_at FROM mc_adaptation_product_profiles WHERE product_id=? LIMIT 1');
            $stmt->execute([$productId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
            $decoded = json_decode((string) ($row['profile_json'] ?? '{}'), true);
            $values = is_array($decoded) ? $decoded : [];
            $confirmedAt = $row['confirmed_at'] ?? null;
            $updatedAt = $row['updated_at'] ?? null;
        }
        // Existing power rules remain the source of truth for legacy readers. Surface them as
        // defaults in the new technical step until the user explicitly confirms the profile.
        $product = $this->product($productId);
        $power = $product ? $this->productPowerRule((int) ($product['legacy_id'] ?? 0)) : [];
        $power = is_array($power) ? $power : [];
        foreach (self::POWER_RULE_FIELDS as $field) {
            $key = (string) $field['key'];
            if (!array_key_exists($key, $values) && array_key_exists($key, $power)) $values[$key] = $power[$key];
        }
        if (!isset($values['dimming_modes']) && isset($power['required_dimming_modes'])) $values['dimming_modes'] = $power['required_dimming_modes'];
        return ['fields' => self::TECHNICAL_PROFILE_FIELDS, 'values' => $values, 'confirmed_at' => $confirmedAt, 'updated_at' => $updatedAt];
    }

    public function saveTechnicalProfile(int $productId, array $values, int $userId): array
    {
        $product = $this->product($productId);
        if (!$product) throw new RuntimeException('产品不存在或已停用。');
        if (!$this->tableExists('mc_adaptation_product_profiles')) throw new RuntimeException('技术范围表尚未升级，请联系管理员执行物料中心升级。');
        $normalized = [];
        foreach (self::TECHNICAL_PROFILE_FIELDS as $field) {
            $key = (string) $field['key'];
            $value = $values[$key] ?? null;
            if ($field['type'] === 'number') {
                if ($value === '' || $value === null) { $normalized[$key] = null; continue; }
                if (!is_numeric($value) || (float) $value < 0) throw new RuntimeException($field['label'].'必须是大于等于 0 的数字。');
                $normalized[$key] = (float) $value;
            } elseif ($field['type'] === 'multi') {
                $normalized[$key] = array_values(array_intersect(array_keys($field['options']), array_unique(array_filter(array_map('strval', (array) $value)))));
            } else {
                $normalized[$key] = mb_substr(trim((string) $value), 0, $field['type'] === 'textarea' ? 2000 : 160);
            }
        }
        foreach ([['lamp_power_min_w', 'lamp_power_max_w', '灯具功率'], ['output_current_min_ma', 'output_current_max_ma', '输出电流'], ['output_voltage_min_v', 'output_voltage_max_v', '输出电压'], ['beam_angle_min_deg', 'beam_angle_max_deg', '光束角']] as [$min, $max, $label]) {
            if ($normalized[$min] !== null && $normalized[$max] !== null && $normalized[$min] > $normalized[$max]) throw new RuntimeException($label.'下限不能大于上限。');
        }
        $stmt = $this->db->prepare("INSERT INTO mc_adaptation_product_profiles(product_id,profile_json,confirmed_by,confirmed_at,created_by,updated_by,created_at,updated_at)
            VALUES(?,?,?,NOW(),?,?,NOW(),NOW()) ON DUPLICATE KEY UPDATE profile_json=VALUES(profile_json),confirmed_by=VALUES(confirmed_by),confirmed_at=NOW(),updated_by=VALUES(updated_by),updated_at=NOW()");
        $stmt->execute([$productId, json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $userId, $userId, $userId]);
        // Synchronise the same electrical envelope into the established power-rule table so
        // candidate matching, quotations and historic API consumers keep seeing one value.
        $groupStmt = $this->db->prepare("SELECT id FROM mc_adaptation_groups WHERE product_id=? AND group_code='power_driver' LIMIT 1");
        $groupStmt->execute([$productId]);
        $powerGroupId = (int) $groupStmt->fetchColumn();
        if ($powerGroupId) $this->savePowerRules($powerGroupId, $normalized, $userId);
        $this->markProductDraft($productId);
        $this->log($productId, 'save_technical_profile', ['fields' => array_keys(array_filter($normalized, static fn(mixed $value): bool => $value !== null && $value !== '' && $value !== []))], $userId);
        return $this->technicalProfile($productId);
    }

    public function configurationOverview(int $productId): array
    {
        $overview = [];
        foreach ($this->groups($productId) as $group) {
            $options = $this->options((int) $group['id']);
            $overview[] = [
                'id' => (int) $group['id'],
                'name' => $group['group_name'],
                'business_type' => $group['business_type'],
                'is_required' => (int) $group['is_required'],
                'selection_mode' => $group['selection_mode'],
                'status' => $group['display_status'],
                'availability' => $group['quick_rules']['availability'] ?? 'allowed',
                'quick_rules' => $group['quick_rules'],
                'default_material' => $group['default_material'],
                'options' => array_map(static function (array $option): array {
                    $selectedVariants = array_values(array_filter(
                        $option['chip_variants'] ?? [],
                        static fn(array $variant): bool => (bool) ($variant['is_selected'] ?? false)
                    ));
                    return [
                        'id' => (int) $option['id'],
                        'material_id' => (int) $option['material_id'],
                        'material_code' => $option['material_code'],
                        'material_name' => $option['name'],
                        'option_type' => $option['option_type'],
                        'is_default' => (int) $option['is_default'],
                        'match_level' => $option['match_level'],
                        'chip_variants' => array_map(static fn(array $variant): array => [
                            'id' => (int) $variant['id'],
                            'label' => $variant['label'],
                            'is_default' => (int) ($variant['is_option_default'] ?? 0),
                            'needs_confirmation' => (int) $variant['needs_confirmation'],
                        ], $selectedVariants),
                    ];
                }, $options),
            ];
        }
        return $overview;
    }

    public function initializeGroups(int $productId, int $userId, ?array $templateKeys = null): array
    {
        $exists = $this->db->prepare("SELECT 1 FROM mc_products WHERE id=? AND status='active'");
        $exists->execute([$productId]);
        if (!$exists->fetchColumn()) throw new RuntimeException('产品不存在或已停用。');
        $templates = self::STANDARD_GROUPS;
        if ($templateKeys !== null) {
            $templateKeys = array_values(array_unique(array_filter(array_map(
                static fn(mixed $key): string => trim((string) $key),
                $templateKeys
            ))));
            if (!$templateKeys) throw new RuntimeException('请至少选择一个要生成的配置组。');
            $validKeys = array_column(self::STANDARD_GROUPS, 0);
            $invalidKeys = array_diff($templateKeys, $validKeys);
            if ($invalidKeys) throw new RuntimeException('所选标准配置组无效，请刷新页面后重试。');
            $templates = array_filter(
                self::STANDARD_GROUPS,
                static fn(array $group): bool => in_array($group[0], $templateKeys, true)
            );
        }
        $created = 0;
        $this->db->beginTransaction();
        try {
            foreach ($templates as $sort => $group) {
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
            $this->log($productId, 'apply_standard_template', [
                'created' => $created,
                'template_total' => count($templates),
                'template_keys' => array_column($templates, 0),
            ], $userId);
            $this->db->commit();
            return ['created' => $created, 'total' => count($templates)];
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
    }

    public function batchInitializeGroups(array $productIds, array $templateKeys, int $userId): array
    {
        $productIds = array_values(array_unique(array_filter(array_map('intval', $productIds))));
        if (!$productIds) throw new RuntimeException('请至少选择一个产品。');
        if (count($productIds) > 1000) throw new RuntimeException('一次最多处理 1000 个产品，请分批执行。');
        $result = ['targets' => count($productIds), 'succeeded' => 0, 'failed' => 0, 'created' => 0, 'failures' => []];
        foreach ($productIds as $productId) {
            try {
                $part = $this->initializeGroups($productId, $userId, $templateKeys);
                $result['succeeded']++;
                $result['created'] += (int) $part['created'];
            } catch (\Throwable $e) {
                $result['failed']++;
                $product = $this->productRow($productId);
                if (count($result['failures']) < 50) {
                    $result['failures'][] = [
                        'product_id' => $productId,
                        'product_code' => (string) ($product['product_code'] ?? '#'.$productId),
                        'reason' => $e->getMessage(),
                    ];
                }
            }
        }
        return $result;
    }

    public function previewBatchApply(
        int $sourceProductId,
        array $targetProductIds,
        string $mode,
        bool $includePowerRule,
        ?array $sourceGroupIds = null
    ): array
    {
        $mode = $mode === 'replace_matching' ? 'replace_matching' : 'fill_missing';
        $source = $this->productRow($sourceProductId);
        if (!$source) throw new RuntimeException('批量来源产品不存在。');
        $targets = $this->batchTargets($sourceProductId, $targetProductIds);
        if (!$targets) throw new RuntimeException('请至少选择一个目标产品。');
        $sourceGroups = $this->selectedSourceGroups($sourceProductId, $sourceGroupIds);
        if (!$sourceGroups && !$includePowerRule) throw new RuntimeException('来源产品还没有可套用的配置。');

        $groupCodes = array_column($sourceGroups, 'group_code');
        $created = 0;
        $overwritten = 0;
        $skipped = 0;
        foreach ($targets as $target) {
            $existing = [];
            $stmt = $this->db->prepare('SELECT group_code FROM mc_adaptation_groups WHERE product_id=?');
            $stmt->execute([$target['id']]);
            foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $code) $existing[(string) $code] = true;
            foreach ($groupCodes as $code) {
                if (!isset($existing[$code])) $created++;
                elseif ($mode === 'replace_matching') $overwritten++;
                else $skipped++;
            }
        }

        $power = ['source_exists' => false, 'created' => 0, 'overwritten' => 0, 'skipped' => 0];
        if ($includePowerRule) {
            $sourcePower = $this->productPowerRule((int) $source['legacy_id']);
            $power['source_exists'] = (bool) $sourcePower;
            if ($sourcePower) {
                $check = $this->db->prepare("SELECT 1 FROM mc_product_power_rules WHERE legacy_product_table='naming_models' AND legacy_product_id=?");
                foreach ($targets as $target) {
                    $check->execute([$target['legacy_id']]);
                    if (!$check->fetchColumn()) $power['created']++;
                    elseif ($mode === 'replace_matching') $power['overwritten']++;
                    else $power['skipped']++;
                }
            }
        }
        if (!$sourceGroups && !$power['source_exists']) throw new RuntimeException('来源产品还没有可套用的配置或电源范围。');
        return [
            'source' => ['id' => (int) $source['id'], 'code' => $source['product_code'], 'name' => $source['product_name']],
            'targets' => count($targets),
            'approved_targets' => count(array_filter($targets, static fn(array $row): bool => !empty($row['approved_version']))),
            'groups' => ['source' => count($sourceGroups), 'created' => $created, 'overwritten' => $overwritten, 'skipped' => $skipped],
            'power_rule' => $power,
            'mode' => $mode,
        ];
    }

    public function batchApply(
        int $sourceProductId,
        array $targetProductIds,
        string $mode,
        bool $includePowerRule,
        int $userId,
        ?array $sourceGroupIds = null
    ): array
    {
        $preview = $this->previewBatchApply($sourceProductId, $targetProductIds, $mode, $includePowerRule, $sourceGroupIds);
        $mode = $preview['mode'];
        $targets = $this->batchTargets($sourceProductId, $targetProductIds);
        $batchUuid = $this->uuid();
        $result = [
            'batch_uuid' => $batchUuid,
            'targets' => count($targets),
            'succeeded' => 0,
            'failed' => 0,
            'groups_created' => 0,
            'groups_overwritten' => 0,
            'groups_skipped' => 0,
            'options_copied' => 0,
            'power_rules_copied' => 0,
            'failures' => [],
        ];
        foreach ($targets as $target) {
            $this->db->beginTransaction();
            try {
                $powerResult = $includePowerRule
                    ? $this->copyPowerRule($sourceProductId, (int) $target['id'], $mode, $userId)
                    : 'not_requested';
                $copy = $this->copyProductConfiguration(
                    $sourceProductId,
                    (int) $target['id'],
                    $mode,
                    $batchUuid,
                    $userId,
                    $sourceGroupIds
                );
                $this->db->commit();
                $result['succeeded']++;
                $result['groups_created'] += $copy['groups_created'];
                $result['groups_overwritten'] += $copy['groups_overwritten'];
                $result['groups_skipped'] += $copy['groups_skipped'];
                $result['options_copied'] += $copy['options_copied'];
                if (in_array($powerResult, ['created', 'overwritten'], true)) $result['power_rules_copied']++;
            } catch (\Throwable $e) {
                if ($this->db->inTransaction()) $this->db->rollBack();
                $result['failed']++;
                if (count($result['failures']) < 50) {
                    $result['failures'][] = [
                        'product_id' => (int) $target['id'],
                        'product_code' => (string) ($target['product_code'] ?: '#'.$target['id']),
                        'reason' => $e->getMessage(),
                    ];
                }
            }
        }
        $this->log($sourceProductId, 'batch_apply_source', [
            'batch_uuid' => $batchUuid,
            'mode' => $mode,
            'include_power_rule' => $includePowerRule,
            'source_group_ids' => $sourceGroupIds,
            'result' => $result,
        ], $userId);
        return $result;
    }

    /**
     * Reusable mapping templates deliberately retain the selected source groups,
     * so a later correction to the common source product is available to every
     * product mapped from that template.  The actual copy still re-checks formal
     * materials and target compatibility at execution time.
     */
    public function reuseTemplates(): array
    {
        $rows = $this->db->query("SELECT t.*,p.product_code,p.product_name,p.series_name
            FROM mc_adaptation_reuse_templates t
            JOIN mc_products p ON p.id=t.source_product_id AND p.status='active'
            WHERE t.status='active'
            ORDER BY t.updated_at DESC,t.id DESC")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$row) {
            $groupIds = array_values(array_unique(array_filter(array_map('intval', json_decode((string) $row['source_group_ids_json'], true) ?: []))));
            $row['source_group_ids'] = $groupIds;
            $groups = array_values(array_filter($this->groups((int) $row['source_product_id']), static fn(array $group): bool => in_array((int) $group['id'], $groupIds, true)));
            $row['group_count'] = count($groups);
            $row['group_names'] = array_values(array_map(static fn(array $group): string => (string) $group['group_name'], $groups));
            $row['is_stale'] = count($groups) !== count($groupIds);
            $row['include_power_rule'] = (bool) $row['include_power_rule'];
            unset($row['source_group_ids_json']);
        }
        unset($row);
        return $rows;
    }

    public function saveReuseTemplate(array $data, int $userId): array
    {
        $sourceProductId = (int) ($data['source_product_id'] ?? 0);
        $name = trim((string) ($data['template_name'] ?? ''));
        $description = trim((string) ($data['description'] ?? ''));
        $includePowerRule = !empty($data['include_power_rule']);
        if ($name === '') throw new RuntimeException('请填写模板名称。');
        if (mb_strlen($name) > 160) throw new RuntimeException('模板名称不能超过 160 个字符。');
        if (mb_strlen($description) > 500) throw new RuntimeException('模板说明不能超过 500 个字符。');
        $source = $this->productRow($sourceProductId);
        if (!$source) throw new RuntimeException('模板来源产品不存在或已停用。');
        $requestedGroupIds = array_values(array_unique(array_filter(array_map('intval', $data['source_group_ids'] ?? []))));
        $groups = $this->selectedSourceGroups($sourceProductId, $requestedGroupIds);
        if (!$groups && !$includePowerRule) throw new RuntimeException('请至少选择一个配置组，或勾选电源范围。');
        if ($includePowerRule && !$this->productPowerRule((int) $source['legacy_id'])) {
            throw new RuntimeException('来源产品尚未设置电源范围，不能把电源范围放入模板。');
        }
        $code = 'APT-'.strtoupper(substr(str_replace('-', '', $this->uuid()), 0, 12));
        $this->db->prepare("INSERT INTO mc_adaptation_reuse_templates
            (template_code,template_name,description,source_product_id,source_group_ids_json,include_power_rule,status,created_by,updated_by,created_at,updated_at)
            VALUES(?,?,?,?,?,?,'active',?,?,NOW(),NOW())")
            ->execute([$code, $name, $description !== '' ? $description : null, $sourceProductId,
                json_encode(array_map('intval', array_column($groups, 'id')), JSON_UNESCAPED_UNICODE), $includePowerRule ? 1 : 0, $userId, $userId]);
        $id = (int) $this->db->lastInsertId();
        $this->log($sourceProductId, 'save_reuse_template', [
            'template_id' => $id,
            'template_name' => $name,
            'source_group_ids' => array_map('intval', array_column($groups, 'id')),
            'include_power_rule' => $includePowerRule,
        ], $userId);
        return ['id' => $id, 'template_code' => $code, 'group_count' => count($groups)];
    }

    public function previewReuseTemplate(int $templateId, array $targetProductIds, string $mode): array
    {
        $template = $this->reuseTemplateRow($templateId);
        $preview = $this->previewBatchApply(
            (int) $template['source_product_id'],
            $targetProductIds,
            $mode,
            (bool) $template['include_power_rule'],
            $template['source_group_ids']
        );
        $preview['template'] = $this->templateSummary($template);
        return $preview;
    }

    public function reuseTemplateIncludesPower(int $templateId): bool
    {
        return (bool) $this->reuseTemplateRow($templateId)['include_power_rule'];
    }

    public function applyReuseTemplate(int $templateId, array $targetProductIds, string $mode, int $userId): array
    {
        $template = $this->reuseTemplateRow($templateId);
        $result = $this->batchApply(
            (int) $template['source_product_id'],
            $targetProductIds,
            $mode,
            (bool) $template['include_power_rule'],
            $userId,
            $template['source_group_ids']
        );
        $this->db->prepare('UPDATE mc_adaptation_reuse_templates SET updated_by=?,updated_at=NOW() WHERE id=?')
            ->execute([$userId, $templateId]);
        return $result;
    }

    public function disableReuseTemplate(int $templateId, int $userId): void
    {
        $template = $this->reuseTemplateRow($templateId);
        $this->db->prepare("UPDATE mc_adaptation_reuse_templates SET status='disabled',updated_by=?,updated_at=NOW() WHERE id=?")
            ->execute([$userId, $templateId]);
        $this->log((int) $template['source_product_id'], 'disable_reuse_template', ['template_id' => $templateId], $userId);
    }

    private function reuseTemplateRow(int $templateId): array
    {
        $stmt = $this->db->prepare("SELECT t.*,p.product_code,p.product_name,p.series_name
            FROM mc_adaptation_reuse_templates t
            JOIN mc_products p ON p.id=t.source_product_id AND p.status='active'
            WHERE t.id=? AND t.status='active'");
        $stmt->execute([$templateId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) throw new RuntimeException('配置模板不存在、已停用或来源产品已停用。');
        $row['source_group_ids'] = array_values(array_unique(array_filter(array_map('intval', json_decode((string) $row['source_group_ids_json'], true) ?: []))));
        if ($row['source_group_ids']) $this->selectedSourceGroups((int) $row['source_product_id'], $row['source_group_ids']);
        if (!$row['source_group_ids'] && empty($row['include_power_rule'])) throw new RuntimeException('配置模板没有可套用内容。');
        return $row;
    }

    private function templateSummary(array $template): array
    {
        $groups = !empty($template['source_group_ids'])
            ? $this->selectedSourceGroups((int) $template['source_product_id'], $template['source_group_ids'])
            : [];
        return [
            'id' => (int) $template['id'],
            'template_code' => (string) $template['template_code'],
            'template_name' => (string) $template['template_name'],
            'source_product_id' => (int) $template['source_product_id'],
            'source_product_code' => (string) $template['product_code'],
            'group_names' => array_values(array_map(static fn(array $group): string => (string) $group['group_name'], $groups)),
            'include_power_rule' => (bool) $template['include_power_rule'],
        ];
    }

    public function configTemplateCenter(int $productId = 0): array
    {
        if (!$this->tableExists('mc_config_templates')) {
            return [
                'ready' => false,
                'product' => $productId ? $this->product($productId) : null,
                'templates' => [],
                'active_template' => null,
                'active_template_groups' => [],
                'group_definitions' => [],
                'message' => '配置模板中心数据表尚未升级。',
            ];
        }
        $product = $productId ? $this->product($productId) : null;
        $active = $product ? $this->resolveConfigTemplateForProduct($product) : ($this->configTemplates()[0] ?? null);
        $templates = $this->configTemplates();
        $groupsByTemplate = [];
        foreach ($templates as $template) $groupsByTemplate[(string) $template['id']] = $this->configTemplateGroups((int) $template['id']);
        return [
            'ready' => true,
            'product' => $product,
            'templates' => $templates,
            'active_template' => $active,
            'active_template_groups' => $active ? $this->configTemplateGroups((int) $active['id']) : [],
            'groups_by_template' => $groupsByTemplate,
            'group_definitions' => $this->configGroupDefinitions(),
            'scope_priority' => ['product', 'series', 'category', 'system'],
            'apply_strategies' => [
                ['key' => 'fill_missing', 'label' => '只补齐缺失配置组（推荐）'],
                ['key' => 'sync_rules_keep_options', 'label' => '同步模板规则，保留已选物料'],
                ['key' => 'new_draft_version', 'label' => '创建新草稿版本后套用'],
            ],
        ];
    }

    public function configTemplates(): array
    {
        if (!$this->tableExists('mc_config_templates')) return [];
        $rows = $this->db->query("SELECT t.*,
                (SELECT COUNT(*) FROM mc_config_template_groups g WHERE g.template_id=t.id AND g.is_enabled=1) group_count,
                (SELECT COUNT(DISTINCT p.id) FROM mc_products p WHERE
                    (t.scope_type='system')
                    OR (t.scope_type='category' AND JSON_UNQUOTE(JSON_EXTRACT(p.snapshot_json,'$.category'))=t.product_type)
                    OR (t.scope_type='series' AND JSON_UNQUOTE(JSON_EXTRACT(p.snapshot_json,'$.series_name'))=t.product_series)
                    OR (t.scope_type='product' AND p.id=t.product_id)
                ) referenced_product_count
            FROM mc_config_templates t
            ORDER BY FIELD(t.scope_type,'system','category','series','product','custom'),t.template_name,t.id")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$row) {
            $row['settings'] = json_decode((string) ($row['settings_json'] ?? '{}'), true) ?: [];
            $row['id'] = (int) $row['id'];
            $row['group_count'] = (int) ($row['group_count'] ?? 0);
            $row['referenced_product_count'] = (int) ($row['referenced_product_count'] ?? 0);
        }
        unset($row);
        return $rows;
    }

    public function configGroupDefinitions(): array
    {
        if (!$this->tableExists('mc_config_group_definitions')) return [];
        $rows = $this->db->query("SELECT d.*,
                (SELECT COUNT(*) FROM mc_config_template_groups tg WHERE tg.group_definition_id=d.id) template_usage_count,
                (SELECT COUNT(*) FROM mc_config_group_options o WHERE o.group_definition_id=d.id AND o.is_enabled=1) option_count
            FROM mc_config_group_definitions d
            ORDER BY d.is_system DESC,d.group_type,d.group_name")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$row) {
            $row['id'] = (int) $row['id'];
            $row['template_usage_count'] = (int) ($row['template_usage_count'] ?? 0);
            $row['option_count'] = (int) ($row['option_count'] ?? 0);
        }
        unset($row);
        return $rows;
    }

    public function configTemplateGroups(int $templateId): array
    {
        if (!$this->tableExists('mc_config_template_groups')) return [];
        $stmt = $this->db->prepare("SELECT tg.*,d.group_name,d.group_type,d.business_type,d.material_category_code,d.description,d.icon,d.is_system,
                (SELECT COUNT(*) FROM mc_config_group_conditions c WHERE c.template_group_id=tg.id AND c.is_enabled=1) condition_count,
                (SELECT COUNT(*) FROM mc_config_group_material_filters f WHERE f.template_group_id=tg.id) filter_count
            FROM mc_config_template_groups tg
            JOIN mc_config_group_definitions d ON d.id=tg.group_definition_id
            WHERE tg.template_id=?
            ORDER BY tg.sort_order,tg.id");
        $stmt->execute([$templateId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$row) {
            $row['id'] = (int) $row['id'];
            $row['template_id'] = (int) $row['template_id'];
            $row['group_definition_id'] = (int) $row['group_definition_id'];
            $row['effective_group_name'] = $row['group_name_override'] ?: $row['group_name'];
            $row['settings'] = json_decode((string) ($row['settings_json'] ?? '{}'), true) ?: [];
            $row['options'] = $this->configGroupOptions((int) $row['group_definition_id']);
            $row['conditions'] = $this->configGroupConditions((int) $row['id']);
            $row['material_filters'] = $this->configGroupMaterialFilters((int) $row['id']);
            $row['condition_count'] = (int) ($row['condition_count'] ?? 0);
            $row['filter_count'] = (int) ($row['filter_count'] ?? 0);
        }
        unset($row);
        return $rows;
    }

    public function saveConfigTemplate(array $data, int $userId): array
    {
        if (!$this->tableExists('mc_config_templates')) throw new RuntimeException('配置模板中心数据表尚未升级。');
        $id = (int) ($data['id'] ?? 0);
        $name = trim((string) ($data['template_name'] ?? ''));
        if ($name === '' || mb_strlen($name) < 2) throw new RuntimeException('请填写模板名称。');
        $scope = in_array(($data['scope_type'] ?? ''), ['system','category','series','product','custom'], true) ? (string) $data['scope_type'] : 'category';
        $code = trim((string) ($data['template_code'] ?? ''));
        if ($code === '') $code = 'tpl_'.substr(hash('sha1', $scope.'|'.$name.'|'.microtime(true)), 0, 12);
        if (!preg_match('/^[a-zA-Z0-9_\-]{2,80}$/', $code)) throw new RuntimeException('模板编码只能使用字母、数字、下划线或横线。');
        $status = in_array(($data['status'] ?? ''), ['draft','active','disabled'], true) ? (string) $data['status'] : 'draft';
        $enabled = empty($data['is_enabled']) && $status !== 'active' ? 0 : 1;
        if ($id > 0) {
            $before = $this->configTemplateRow($id);
            if (!$before) throw new RuntimeException('模板不存在。');
            $stmt = $this->db->prepare('UPDATE mc_config_templates SET template_code=?,template_name=?,scope_type=?,product_type=?,product_series=?,product_id=?,parent_template_id=?,version_no=?,status=?,is_enabled=?,description=?,settings_json=?,updated_by=?,updated_at=NOW() WHERE id=?');
            $stmt->execute([$code,$name,$scope,trim((string)($data['product_type'] ?? '')) ?: null,trim((string)($data['product_series'] ?? '')) ?: null,(int)($data['product_id'] ?? 0) ?: null,(int)($data['parent_template_id'] ?? 0) ?: null,trim((string)($data['version_no'] ?? 'v1.0')) ?: 'v1.0',$status,$enabled,mb_substr(trim((string)($data['description'] ?? '')),0,500) ?: null,json_encode(['scope_type'=>$scope], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),$userId,$id]);
            $this->configTemplateLog($id, 0, 'update_template', $before, $data, $userId);
        } else {
            $stmt = $this->db->prepare('INSERT INTO mc_config_templates(template_code,template_name,scope_type,product_type,product_series,product_id,parent_template_id,version_no,status,is_enabled,description,settings_json,created_by,updated_by,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW(),NOW())');
            $stmt->execute([$code,$name,$scope,trim((string)($data['product_type'] ?? '')) ?: null,trim((string)($data['product_series'] ?? '')) ?: null,(int)($data['product_id'] ?? 0) ?: null,(int)($data['parent_template_id'] ?? 0) ?: null,trim((string)($data['version_no'] ?? 'v1.0')) ?: 'v1.0',$status,$enabled,mb_substr(trim((string)($data['description'] ?? '')),0,500) ?: null,json_encode(['scope_type'=>$scope], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),$userId,$userId]);
            $id = (int) $this->db->lastInsertId();
            $this->configTemplateLog($id, 0, 'create_template', null, $data, $userId);
        }
        return ['template' => $this->configTemplateRow($id), 'groups' => $this->configTemplateGroups($id)];
    }

    public function copyConfigTemplate(int $templateId, int $userId): array
    {
        $template = $this->configTemplateRow($templateId);
        if (!$template) throw new RuntimeException('模板不存在。');
        $this->db->beginTransaction();
        try {
            $code = 'tpl_copy_'.substr(hash('sha1', $templateId.'|'.microtime(true)), 0, 12);
            $stmt = $this->db->prepare("INSERT INTO mc_config_templates(template_code,template_name,scope_type,product_type,product_series,product_id,parent_template_id,version_no,status,is_enabled,description,settings_json,created_by,updated_by,created_at,updated_at)
                VALUES(?,?,?,?,?,?,?,'v1.0','draft',0,?,?,?, ?,NOW(),NOW())");
            $stmt->execute([$code, $template['template_name'].' 副本', $template['scope_type'], $template['product_type'], $template['product_series'], $template['product_id'], $templateId, $template['description'], $template['settings_json'], $userId, $userId]);
            $newId = (int) $this->db->lastInsertId();
            foreach ($this->configTemplateGroups($templateId) as $group) {
                $insert = $this->db->prepare("INSERT INTO mc_config_template_groups(template_id,group_definition_id,group_code,group_name_override,is_required,selection_mode,allow_empty,min_select,max_select,allow_default,salesperson_editable,customer_selectable,affects_price,affects_lead_time,requires_approval,sort_order,is_enabled,settings_json,created_by,updated_by,created_at,updated_at)
                    VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW(),NOW())");
                $insert->execute([$newId,$group['group_definition_id'],$group['group_code'],$group['group_name_override'],$group['is_required'],$group['selection_mode'],$group['allow_empty'],$group['min_select'],$group['max_select'],$group['allow_default'],$group['salesperson_editable'],$group['customer_selectable'],$group['affects_price'],$group['affects_lead_time'],$group['requires_approval'],$group['sort_order'],$group['is_enabled'],$group['settings_json'],$userId,$userId]);
                $newGroupId = (int) $this->db->lastInsertId();
                foreach ($group['conditions'] as $condition) {
                    $this->db->prepare('INSERT INTO mc_config_group_conditions(template_group_id,source_field,operator,expected_value,action_type,action_target,condition_json,sort_order,is_enabled,created_by,updated_by,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,NOW(),NOW())')
                        ->execute([$newGroupId,$condition['source_field'],$condition['operator'],$condition['expected_value'],$condition['action_type'],$condition['action_target'],$condition['condition_json'],$condition['sort_order'],$condition['is_enabled'],$userId,$userId]);
                }
                foreach ($group['material_filters'] as $filter) {
                    $this->db->prepare('INSERT INTO mc_config_group_material_filters(template_group_id,material_category_code,material_subcategory_code,brand_limit,model_limit,installation_type,power_min,power_max,io_type,ip_rating,formal_status,approved_required,allow_pending,allow_alternative,filter_json,created_by,updated_by,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW(),NOW())')
                        ->execute([$newGroupId,$filter['material_category_code'],$filter['material_subcategory_code'],$filter['brand_limit'],$filter['model_limit'],$filter['installation_type'],$filter['power_min'],$filter['power_max'],$filter['io_type'],$filter['ip_rating'],$filter['formal_status'],$filter['approved_required'],$filter['allow_pending'],$filter['allow_alternative'],$filter['filter_json'],$userId,$userId]);
                }
            }
            $this->configTemplateLog($newId, 0, 'copy_template', ['source_template_id' => $templateId], ['template_id' => $newId], $userId);
            $this->db->commit();
            return ['template' => $this->configTemplateRow($newId), 'groups' => $this->configTemplateGroups($newId)];
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
    }

    public function disableConfigTemplate(int $templateId, int $userId): array
    {
        $before = $this->configTemplateRow($templateId);
        if (!$before) throw new RuntimeException('模板不存在。');
        $this->db->prepare("UPDATE mc_config_templates SET status='disabled',is_enabled=0,updated_by=?,updated_at=NOW() WHERE id=?")->execute([$userId, $templateId]);
        $this->configTemplateLog($templateId, 0, 'disable_template', $before, ['status' => 'disabled'], $userId);
        return ['template' => $this->configTemplateRow($templateId)];
    }

    public function deleteConfigTemplate(int $templateId, int $userId): array
    {
        $template = $this->configTemplateRow($templateId);
        if (!$template) throw new RuntimeException('模板不存在。');
        if (($template['status'] ?? '') !== 'draft' || (int) ($template['referenced_product_count'] ?? 0) > 0) {
            throw new RuntimeException('只能删除未使用的草稿模板；已启用或已有引用的模板请停用。');
        }
        foreach ($this->configTemplateGroups($templateId) as $group) {
            $this->db->prepare('DELETE FROM mc_config_group_material_filters WHERE template_group_id=?')->execute([$group['id']]);
            $this->db->prepare('DELETE FROM mc_config_group_conditions WHERE template_group_id=?')->execute([$group['id']]);
        }
        $this->db->prepare('DELETE FROM mc_config_template_groups WHERE template_id=?')->execute([$templateId]);
        $this->db->prepare('DELETE FROM mc_config_templates WHERE id=?')->execute([$templateId]);
        $this->configTemplateLog($templateId, 0, 'delete_template', $template, null, $userId);
        return [];
    }

    public function saveConfigGroupDefinition(array $data, int $userId): array
    {
        $id = (int) ($data['id'] ?? 0);
        $name = trim((string) ($data['group_name'] ?? ''));
        $this->assertMeaningfulName($name);
        $code = trim((string) ($data['group_code'] ?? ''));
        if ($code === '') $code = 'custom_'.substr(hash('sha1', $name.'|'.microtime(true)), 0, 12);
        if (!preg_match('/^[a-zA-Z0-9_\-]{2,80}$/', $code)) throw new RuntimeException('配置组编码只能使用字母、数字、下划线或横线。');
        $type = in_array(($data['group_type'] ?? ''), ['material','attribute','mixed'], true) ? (string) $data['group_type'] : 'material';
        $category = trim((string) ($data['material_category_code'] ?? '')) ?: null;
        $business = trim((string) ($data['business_type'] ?? '')) ?: ($category ? $this->legacyGroupType($category) : 'custom');
        if ($id > 0) {
            $before = $this->configGroupDefinitionRow($id);
            if (!$before) throw new RuntimeException('配置组定义不存在。');
            $stmt = $this->db->prepare('UPDATE mc_config_group_definitions SET group_code=?,group_name=?,group_type=?,business_type=?,material_category_code=?,description=?,icon=?,is_enabled=?,updated_by=?,updated_at=NOW() WHERE id=?');
            $stmt->execute([$code,$name,$type,$business,$category,mb_substr(trim((string)($data['description'] ?? '')),0,500) ?: null,mb_substr(trim((string)($data['icon'] ?? '◆')),0,40),empty($data['is_enabled']) ? 0 : 1,$userId,$id]);
            $this->configTemplateLog(0, 0, 'update_group_definition', $before, $data, $userId);
        } else {
            $stmt = $this->db->prepare('INSERT INTO mc_config_group_definitions(group_code,group_name,group_type,business_type,material_category_code,description,icon,is_system,is_enabled,created_by,updated_by,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,NOW(),NOW())');
            $stmt->execute([$code,$name,$type,$business,$category,mb_substr(trim((string)($data['description'] ?? '')),0,500) ?: null,mb_substr(trim((string)($data['icon'] ?? '◆')),0,40),0,empty($data['is_enabled']) ? 0 : 1,$userId,$userId]);
            $id = (int) $this->db->lastInsertId();
            $this->configTemplateLog(0, 0, 'create_group_definition', null, $data, $userId);
        }
        return ['definition' => $this->configGroupDefinitionRow($id)];
    }

    public function saveConfigTemplateGroup(array $data, int $userId): array
    {
        $templateId = (int) ($data['template_id'] ?? 0);
        $definitionId = (int) ($data['group_definition_id'] ?? 0);
        if (!$this->configTemplateRow($templateId)) throw new RuntimeException('模板不存在。');
        $definition = $this->configGroupDefinitionRow($definitionId);
        if (!$definition) throw new RuntimeException('请选择有效配置组类型。');
        $id = (int) ($data['id'] ?? 0);
        $required = !empty($data['is_required']) ? 1 : 0;
        $selection = ($data['selection_mode'] ?? 'single') === 'multi' ? 'multi' : 'single';
        $min = max(0, (int) ($data['min_select'] ?? ($required ? 1 : 0)));
        $max = max($selection === 'multi' ? max(1, $min) : 1, (int) ($data['max_select'] ?? ($selection === 'multi' ? 99 : 1)));
        $params = [
            $definitionId,
            $definition['group_code'],
            trim((string)($data['group_name_override'] ?? '')) ?: null,
            $required,
            $selection,
            !empty($data['allow_empty']) ? 1 : 0,
            $min,
            $max,
            !empty($data['allow_default']) ? 1 : 0,
            !empty($data['salesperson_editable']) ? 1 : 0,
            !empty($data['customer_selectable']) ? 1 : 0,
            !empty($data['affects_price']) ? 1 : 0,
            !empty($data['affects_lead_time']) ? 1 : 0,
            !empty($data['requires_approval']) ? 1 : 0,
            (int)($data['sort_order'] ?? 100),
            empty($data['is_enabled']) ? 0 : 1,
            json_encode(['dynamic_template_group' => true], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            $userId,
        ];
        if ($id > 0) {
            $before = $this->configTemplateGroupRow($id);
            if (!$before) throw new RuntimeException('模板配置组不存在。');
            $stmt = $this->db->prepare('UPDATE mc_config_template_groups SET group_definition_id=?,group_code=?,group_name_override=?,is_required=?,selection_mode=?,allow_empty=?,min_select=?,max_select=?,allow_default=?,salesperson_editable=?,customer_selectable=?,affects_price=?,affects_lead_time=?,requires_approval=?,sort_order=?,is_enabled=?,settings_json=?,updated_by=?,updated_at=NOW() WHERE id=? AND template_id=?');
            $stmt->execute([...$params, $id, $templateId]);
            $templateGroupId = $id;
            $this->configTemplateLog($templateId, 0, 'update_template_group', $before, $data, $userId);
        } else {
            $stmt = $this->db->prepare('INSERT INTO mc_config_template_groups(template_id,group_definition_id,group_code,group_name_override,is_required,selection_mode,allow_empty,min_select,max_select,allow_default,salesperson_editable,customer_selectable,affects_price,affects_lead_time,requires_approval,sort_order,is_enabled,settings_json,created_by,updated_by,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW(),NOW())');
            $stmt->execute([$templateId, ...$params, $userId]);
            $templateGroupId = (int) $this->db->lastInsertId();
            $this->configTemplateLog($templateId, 0, 'create_template_group', null, $data, $userId);
        }
        $this->saveConfigMaterialFilterFromPayload($templateGroupId, $data, $userId);
        return ['groups' => $this->configTemplateGroups($templateId), 'template_group_id' => $templateGroupId];
    }

    public function saveConfigGroupOption(array $data, int $userId): array
    {
        $definitionId = (int) ($data['group_definition_id'] ?? 0);
        if (!$this->configGroupDefinitionRow($definitionId)) throw new RuntimeException('配置组定义不存在。');
        $name = trim((string) ($data['option_name'] ?? ''));
        if ($name === '') throw new RuntimeException('请填写选项名称。');
        $code = trim((string) ($data['option_code'] ?? ''));
        if ($code === '') $code = 'opt_'.substr(hash('sha1', $definitionId.'|'.$name.'|'.microtime(true)), 0, 10);
        $stmt = $this->db->prepare('INSERT INTO mc_config_group_options(group_definition_id,option_code,option_name,option_image,is_default,is_enabled,sort_order,price_effect,lead_time_effect,settings_json,created_by,updated_by,created_at,updated_at)
            VALUES(?,?,?,?,?,?,?,?,?,?,?,?,NOW(),NOW())
            ON DUPLICATE KEY UPDATE option_name=VALUES(option_name),option_image=VALUES(option_image),is_default=VALUES(is_default),is_enabled=VALUES(is_enabled),sort_order=VALUES(sort_order),price_effect=VALUES(price_effect),lead_time_effect=VALUES(lead_time_effect),settings_json=VALUES(settings_json),updated_by=VALUES(updated_by),updated_at=NOW()');
        $stmt->execute([$definitionId,$code,$name,trim((string)($data['option_image'] ?? '')) ?: null,!empty($data['is_default']) ? 1 : 0,empty($data['is_enabled']) ? 0 : 1,(int)($data['sort_order'] ?? 100),(float)($data['price_effect'] ?? 0),(int)($data['lead_time_effect'] ?? 0),json_encode(['scope' => trim((string)($data['scope'] ?? '')), 'note' => trim((string)($data['note'] ?? ''))], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),$userId,$userId]);
        $this->configTemplateLog(0, 0, 'save_group_option', null, $data, $userId);
        return ['options' => $this->configGroupOptions($definitionId)];
    }

    public function saveConfigGroupCondition(array $data, int $userId): array
    {
        $templateGroupId = (int) ($data['template_group_id'] ?? 0);
        $group = $this->configTemplateGroupRow($templateGroupId);
        if (!$group) throw new RuntimeException('模板配置组不存在。');
        $condition = [
            'source_field' => trim((string) ($data['source_field'] ?? '')),
            'operator' => trim((string) ($data['operator'] ?? 'eq')) ?: 'eq',
            'expected_value' => trim((string) ($data['expected_value'] ?? '')),
            'action_type' => trim((string) ($data['action_type'] ?? 'show')) ?: 'show',
            'action_target' => trim((string) ($data['action_target'] ?? '')),
            'sort_order' => (int) ($data['sort_order'] ?? 100),
            'is_enabled' => empty($data['is_enabled']) ? 0 : 1,
        ];
        if ($condition['source_field'] === '') throw new RuntimeException('请选择条件字段或配置组。');
        $this->db->prepare('INSERT INTO mc_config_group_conditions(template_group_id,source_field,operator,expected_value,action_type,action_target,condition_json,sort_order,is_enabled,created_by,updated_by,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,NOW(),NOW())')
            ->execute([$templateGroupId,$condition['source_field'],$condition['operator'],$condition['expected_value'],$condition['action_type'],$condition['action_target'],json_encode($condition, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),$condition['sort_order'],$condition['is_enabled'],$userId,$userId]);
        $this->configTemplateLog((int) $group['template_id'], 0, 'save_group_condition', null, $condition, $userId);
        return ['groups' => $this->configTemplateGroups((int) $group['template_id'])];
    }

    public function previewConfigTemplateApply(int $productId, int $templateId, string $strategy = 'fill_missing'): array
    {
        $product = $this->product($productId);
        if (!$product) throw new RuntimeException('产品不存在或已停用。');
        $template = $this->configTemplateRow($templateId);
        if (!$template) throw new RuntimeException('模板不存在。');
        $templateGroups = $this->configTemplateGroups($templateId);
        $existing = [];
        foreach ($this->groups($productId) as $group) $existing[(string) $group['group_code']] = $group;
        $add = $update = $skip = [];
        foreach ($templateGroups as $group) {
            $code = (string) $group['group_code'];
            if (!isset($existing[$code])) $add[] = $group;
            elseif ($strategy === 'fill_missing') $skip[] = $group;
            else $update[] = $group;
        }
        return [
            'product' => $product,
            'template' => $template,
            'strategy' => $strategy,
            'will_add' => array_map(static fn(array $g): string => (string) $g['effective_group_name'], $add),
            'will_update' => array_map(static fn(array $g): string => (string) $g['effective_group_name'], $update),
            'will_skip' => array_map(static fn(array $g): string => (string) $g['effective_group_name'], $skip),
            'will_keep_selected_materials' => true,
            'published_version_locked' => (int) ($product['published_version_count'] ?? 0) > 0,
            'conflicts' => [],
            'recommended_strategy' => 'fill_missing',
        ];
    }

    public function applyConfigTemplateToProduct(int $productId, int $templateId, string $strategy, int $userId): array
    {
        $preview = $this->previewConfigTemplateApply($productId, $templateId, $strategy);
        $strategy = in_array($strategy, ['fill_missing','sync_rules_keep_options','new_draft_version'], true) ? $strategy : 'fill_missing';
        $groups = $this->configTemplateGroups($templateId);
        $created = $updated = $skipped = 0;
        $this->db->beginTransaction();
        try {
            foreach ($groups as $group) {
                if (!(int) ($group['is_enabled'] ?? 0)) continue;
                $existing = $this->db->prepare('SELECT * FROM mc_adaptation_groups WHERE product_id=? AND group_code=? LIMIT 1');
                $existing->execute([$productId, $group['group_code']]);
                $old = $existing->fetch(PDO::FETCH_ASSOC);
                $definitionType = (string) ($group['group_type'] ?? 'material');
                $category = (string) ($group['material_category_code'] ?? '');
                $ruleJson = json_encode([
                    'availability' => 'allowed',
                    'dynamic_template_group' => true,
                    'template_id' => $templateId,
                    'template_group_id' => (int) $group['id'],
                    'group_definition_id' => (int) $group['group_definition_id'],
                    'group_type' => $definitionType,
                    'attribute_options' => array_map(static fn(array $option): array => [
                        'code' => (string) $option['option_code'],
                        'name' => (string) $option['option_name'],
                        'is_default' => (int) $option['is_default'],
                        'is_enabled' => (int) $option['is_enabled'],
                        'sort_order' => (int) $option['sort_order'],
                    ], $group['options'] ?? []),
                    'display_conditions' => $group['conditions'] ?? [],
                    'material_filters' => $group['material_filters'] ?? [],
                    'allow_empty' => (int) ($group['allow_empty'] ?? 1),
                    'allow_default' => (int) ($group['allow_default'] ?? 1),
                    'customer_selectable' => (int) ($group['customer_selectable'] ?? 0),
                    'affects_price' => (int) ($group['affects_price'] ?? 0),
                    'affects_lead_time' => (int) ($group['affects_lead_time'] ?? 0),
                    'requires_approval' => (int) ($group['requires_approval'] ?? 0),
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                $name = (string) ($group['effective_group_name'] ?? $group['group_name'] ?? $group['group_code']);
                $legacyType = $definitionType === 'attribute' ? 'custom' : $this->legacyGroupType($category ?: 'custom');
                if ($old) {
                    if ($strategy === 'fill_missing') { $skipped++; continue; }
                    $this->db->prepare("UPDATE mc_adaptation_groups SET group_name=?,group_type=?,business_type=?,material_category_code=?,is_required=?,selection_mode=?,min_select=?,max_select=?,template_key=?,rule_json=?,status='draft',is_enabled=0,sort_order=?,updated_by=?,updated_at=NOW() WHERE id=?")
                        ->execute([$name,$legacyType,$group['business_type'] ?: 'custom',$category ?: null,(int)$group['is_required'],$group['selection_mode'],(int)$group['min_select'],(int)$group['max_select'],$group['group_code'],$ruleJson,(int)$group['sort_order'],$userId,(int)$old['id']]);
                    $updated++;
                } else {
                    $this->db->prepare("INSERT INTO mc_adaptation_groups(product_id,group_code,group_name,group_type,business_type,material_category_code,is_required,selection_mode,min_select,max_select,template_key,rule_json,status,is_enabled,sort_order,created_by,updated_by,created_at,updated_at)
                        VALUES(?,?,?,?,?,?,?,?,?,?,?,?, 'draft',0,?,?,?,NOW(),NOW())")
                        ->execute([$productId,$group['group_code'],$name,$legacyType,$group['business_type'] ?: 'custom',$category ?: null,(int)$group['is_required'],$group['selection_mode'],(int)$group['min_select'],(int)$group['max_select'],$group['group_code'],$ruleJson,(int)$group['sort_order'],$userId,$userId]);
                    $created++;
                }
            }
            $this->db->prepare('UPDATE mc_config_templates SET usage_count=usage_count+1,updated_by=?,updated_at=NOW() WHERE id=?')->execute([$userId,$templateId]);
            $this->markProductDraft($productId);
            $this->log($productId, 'apply_config_template', ['template_id' => $templateId, 'strategy' => $strategy, 'created' => $created, 'updated' => $updated, 'skipped' => $skipped], $userId);
            $this->configTemplateLog($templateId, $productId, 'apply_template', $preview, compact('created','updated','skipped','strategy'), $userId);
            $this->db->commit();
            return ['created' => $created, 'updated' => $updated, 'skipped' => $skipped, 'preview' => $preview];
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
    }

    public function saveTemplateCategory(array $data, int $userId): array
    {
        $productId = (int) ($data['product_id'] ?? 0);
        $id = (int) ($data['id'] ?? 0);
        $name = trim((string) ($data['group_name'] ?? $data['category_name'] ?? ''));
        $this->assertMeaningfulName($name);
        $productType = mb_substr(trim((string) ($data['product_type'] ?? '')), 0, 80);
        $fieldName = mb_substr(trim((string) ($data['field_name'] ?? '')), 0, 80);
        $selection = ($data['selection_mode'] ?? 'single') === 'multi' ? 'multi' : 'single';
        $optionValues = array_values(array_unique(array_filter(array_map(
            static fn(mixed $value): string => mb_substr(trim((string) $value), 0, 60),
            is_array($data['option_values'] ?? null) ? $data['option_values'] : []
        ))));
        if (!$productId || !$this->productRow($productId)) throw new RuntimeException('请选择有效产品。');
        if ($productType === '') throw new RuntimeException('请选择适用产品类型。');
        if ($fieldName === '') $fieldName = $name.'选项';
        if (count($optionValues) > 30) throw new RuntimeException('一个自定义分类最多保留 30 个选项。');
        $category = $this->templateMaterialCategory($name.' '.$productType.' '.$fieldName);
        $ruleJson = json_encode([
            'availability' => 'allowed',
            'template_custom_category' => true,
            'product_type' => $productType,
            'field_name' => $fieldName,
            'option_values' => $optionValues,
            'selection_mode' => $selection,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $this->db->beginTransaction();
        try {
            if ($id > 0) {
                $stmt = $this->db->prepare('SELECT * FROM mc_adaptation_groups WHERE id=? AND product_id=?');
                $stmt->execute([$id, $productId]);
                $existing = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$existing) throw new RuntimeException('自定义分类不存在。');
                $oldRules = json_decode((string) ($existing['rule_json'] ?? '{}'), true) ?: [];
                if (($existing['business_type'] ?? '') !== 'custom' && empty($oldRules['template_custom_category'])) {
                    throw new RuntimeException('标准配置组不能在模板分类面板中编辑。');
                }
                if (($existing['status'] ?? '') === 'approved' || (int) ($existing['is_enabled'] ?? 0)) {
                    throw new RuntimeException('已审批或已启用分类不能直接编辑，请建立新版本。');
                }
                $update = $this->db->prepare("UPDATE mc_adaptation_groups
                    SET group_name=?,group_type=?,business_type='custom',material_category_code=?,selection_mode=?,
                        min_select=0,max_select=?,rule_json=?,status='draft',is_enabled=0,updated_by=?,updated_at=NOW()
                    WHERE id=? AND product_id=?");
                $update->execute([$name, $this->legacyGroupType($category), $category, $selection, $selection === 'single' ? 1 : max(1, count($optionValues)), $ruleJson, $userId, $id, $productId]);
            } else {
                $code = 'custom_template_'.substr(hash('sha1', $productId.'|'.$name.'|'.microtime(true).'|'.random_int(1, PHP_INT_MAX)), 0, 12);
                $sortOrder = 500 + count($this->groups($productId)) * 10;
                $insert = $this->db->prepare("INSERT INTO mc_adaptation_groups
                    (product_id,group_code,group_name,group_type,business_type,material_category_code,is_required,selection_mode,min_select,max_select,template_key,rule_json,status,is_enabled,sort_order,created_by,updated_by,created_at,updated_at)
                    VALUES(?,?,?,?, 'custom', ?,0,?,0,?,?,?,'draft',0,?,?,?,NOW(),NOW())");
                $insert->execute([$productId, $code, $name, $this->legacyGroupType($category), $category, $selection, $selection === 'single' ? 1 : max(1, count($optionValues)), $code, $ruleJson, $sortOrder, $userId, $userId]);
                $id = (int) $this->db->lastInsertId();
            }
            $this->markProductDraft($productId);
            $this->log($productId, 'save_template_category', [
                'group_id' => $id,
                'name' => $name,
                'product_type' => $productType,
                'field_name' => $fieldName,
                'option_values' => $optionValues,
            ], $userId);
            $this->db->commit();
            $row = array_values(array_filter($this->groups($productId), static fn(array $group): bool => (int) $group['id'] === $id))[0] ?? [];
            return ['id' => $id, 'group' => $row];
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
    }

    public function deleteTemplateCategory(int $productId, int $groupId, int $userId): void
    {
        if (!$productId || !$groupId) throw new RuntimeException('请选择要删除的自定义分类。');
        $stmt = $this->db->prepare('SELECT * FROM mc_adaptation_groups WHERE id=? AND product_id=?');
        $stmt->execute([$groupId, $productId]);
        $group = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$group) throw new RuntimeException('自定义分类不存在。');
        $rules = json_decode((string) ($group['rule_json'] ?? '{}'), true) ?: [];
        if (($group['business_type'] ?? '') !== 'custom' && empty($rules['template_custom_category'])) {
            throw new RuntimeException('标准配置组不能在模板分类面板中删除。');
        }
        $this->deleteGroup($groupId, $userId);
    }

    private function templateMaterialCategory(string $text): string
    {
        if (preg_match('/电源|驱动|调光|dimming|driver|power/i', $text)) return 'power_supply';
        if (preg_match('/芯片|光源|chip|led/i', $text)) return 'chip';
        if (preg_match('/光学|透镜|玻璃|lens|optic|glass/i', $text)) return 'optical';
        if (preg_match('/接头|安装|导轨|轨道|connector|mount|install|track/i', $text)) return 'connector';
        if (preg_match('/包装|pack/i', $text)) return 'packaging';
        if (preg_match('/型材|散热|profile|heatsink/i', $text)) return 'profile';
        return 'accessory';
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
        $mappedCategory = self::BUSINESS_TYPES[$businessType]['category'] ?? null;
        $category = $mappedCategory !== null
            ? (string) $mappedCategory
            : trim((string) ($data['material_category_code'] ?? ''));
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

    public function saveQuickRules(int $groupId, array $rules, int $userId): array
    {
        $stmt = $this->db->prepare('SELECT * FROM mc_adaptation_groups WHERE id=?');
        $stmt->execute([$groupId]);
        $group = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$group) throw new RuntimeException('配置组不存在。');

        $availability = (string) ($rules['availability'] ?? 'allowed');
        if (!in_array($availability, ['allowed', 'forbidden', 'not_applicable', 'not_offered', 'later'], true)) $availability = 'allowed';
        if ((int) $group['is_required'] && $availability !== 'allowed') {
            throw new RuntimeException('核心必配组不能标记为“不适用、未提供或稍后处理”。');
        }
        $normalized = ['availability' => $availability];
        $fieldDefinitions = self::QUICK_RULE_FIELDS[(string) $group['business_type']] ?? [];
        foreach ($fieldDefinitions as $definition) {
            $key = (string) $definition['key'];
            $value = $rules[$key] ?? '';
            if ($value === '' || $value === null) continue;
            if ($definition['type'] === 'number') {
                if (!is_numeric($value) || (float) $value < 0) {
                    throw new RuntimeException($definition['label'].'必须是大于等于 0 的数字。');
                }
                $normalized[$key] = (float) $value;
            } elseif ($definition['type'] === 'select') {
                $options = $definition['options'] ?? [];
                if (!isset($options[(string) $value])) throw new RuntimeException($definition['label'].'选项无效。');
                $normalized[$key] = (string) $value;
            } else {
                $normalized[$key] = mb_substr(trim((string) $value), 0, 160);
            }
        }
        foreach ([
            ['power_min_w', 'power_max_w', '芯片功率'],
            ['current_min_ma', 'current_max_ma', '芯片电流'],
            ['voltage_min_v', 'voltage_max_v', '芯片电压'],
            ['diameter_min_mm', 'diameter_max_mm', '直径'],
            ['beam_min_deg', 'beam_max_deg', '光束角'],
        ] as [$minKey, $maxKey, $label]) {
            if (isset($normalized[$minKey], $normalized[$maxKey]) && $normalized[$minKey] > $normalized[$maxKey]) {
                throw new RuntimeException($label.'下限不能大于上限。');
            }
        }

        $this->db->beginTransaction();
        try {
            $this->db->prepare("UPDATE mc_adaptation_groups
                SET rule_json=?,status='draft',is_enabled=0,updated_by=?,updated_at=NOW()
                WHERE id=?")
                ->execute([json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $userId, $groupId]);
            $this->markProductDraft((int) $group['product_id']);
            $review = 0;
            $incompatible = 0;
            foreach ($this->options($groupId) as $option) {
                $candidate = $this->candidateMaterials($groupId, ['status' => 'all', 'material_id' => (int) $option['material_id']])[0] ?? null;
                if (!$candidate) continue;
                $this->db->prepare("UPDATE mc_adaptation_options
                    SET match_level=?,match_reason_json=?,requires_approval=?,exception_approved=0,status='draft'
                    WHERE id=?")
                    ->execute([
                        $candidate['match_level'],
                        json_encode($candidate['conflict_reasons'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                        $candidate['requires_approval'] ? 1 : 0,
                        $option['id'],
                    ]);
                if ($candidate['match_level'] === 'needs_approval') $review++;
                if ($candidate['match_level'] === 'incompatible') $incompatible++;
            }
            $this->log((int) $group['product_id'], 'save_quick_rules', [
                'group_id' => $groupId,
                'rules' => $normalized,
                'needs_review_options' => $review,
                'incompatible_options' => $incompatible,
            ], $userId);
            $this->db->commit();
            return ['saved' => count($normalized) - 1, 'needs_review' => $review, 'incompatible' => $incompatible];
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
    }

    /** Save the product-level power envelope from the power group itself. */
    public function savePowerRules(int $groupId, array $rules, int $userId): array
    {
        $stmt = $this->db->prepare('SELECT g.*,p.legacy_id FROM mc_adaptation_groups g JOIN mc_products p ON p.id=g.product_id WHERE g.id=?');
        $stmt->execute([$groupId]);
        $group = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$group) throw new RuntimeException('配置组不存在。');
        if (($group['material_category_code'] ?? '') !== 'power_supply') throw new RuntimeException('只有电源 / 驱动配置组可以保存电源关键范围。');

        $normalized = [
            'legacy_product_id' => (int) $group['legacy_id'],
            'rule_name' => trim((string) ($rules['rule_name'] ?? '')) ?: ((string) $group['group_name'].'关键范围'),
            'installation_type' => (string) ($rules['installation_type'] ?? 'unknown'),
            'output_type' => (string) ($rules['output_type'] ?? 'unknown'),
            'certification_required' => mb_substr(trim((string) ($rules['certification_required'] ?? '')), 0, 160),
            'dimming_modes' => array_values(array_unique(array_filter(array_map('trim', (array) ($rules['dimming_modes'] ?? []))))),
        ];
        foreach (self::POWER_RULE_FIELDS as $definition) {
            if (($definition['type'] ?? '') !== 'number') continue;
            $key = (string) $definition['key'];
            $value = $rules[$key] ?? '';
            if ($value === '' || $value === null) {
                $normalized[$key] = null;
                continue;
            }
            if (!is_numeric($value) || (float) $value < 0) throw new RuntimeException($definition['label'].'必须是大于等于 0 的数字。');
            $normalized[$key] = (float) $value;
        }
        // 保留旧字段是为了兼容此前已保存的单一功率规则；新页面一律写入范围。
        $normalized['lamp_power_w'] = $normalized['lamp_power_max_w'] ?? null;
        foreach ([
            ['lamp_power_min_w', 'lamp_power_max_w', '灯具功率'],
            ['output_current_min_ma', 'output_current_max_ma', '输出电流'],
            ['output_voltage_min_v', 'output_voltage_max_v', '输出电压'],
        ] as [$minKey, $maxKey, $label]) {
            if ($normalized[$minKey] !== null && $normalized[$maxKey] !== null && $normalized[$minKey] > $normalized[$maxKey]) {
                throw new RuntimeException($label.'下限不能大于上限。');
            }
        }

        $ruleId = (new ProductPowerRuleService($this->db))->save($normalized, $userId);
        $this->db->beginTransaction();
        try {
            $this->db->prepare("UPDATE mc_adaptation_groups SET status='draft',is_enabled=0,updated_by=?,updated_at=NOW() WHERE id=?")
                ->execute([$userId, $groupId]);
            $this->markProductDraft((int) $group['product_id']);
            $review = 0;
            $incompatible = 0;
            foreach ($this->options($groupId) as $option) {
                $candidate = $this->candidateMaterials($groupId, ['status' => 'all', 'material_id' => (int) $option['material_id']])[0] ?? null;
                if (!$candidate) continue;
                $this->db->prepare("UPDATE mc_adaptation_options SET match_level=?,match_reason_json=?,requires_approval=?,exception_approved=0,status='draft' WHERE id=?")
                    ->execute([$candidate['match_level'], json_encode($candidate['conflict_reasons'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $candidate['requires_approval'] ? 1 : 0, $option['id']]);
                if ($candidate['match_level'] === 'needs_approval') $review++;
                if ($candidate['match_level'] === 'incompatible') $incompatible++;
            }
            $this->log((int) $group['product_id'], 'save_power_rules_from_adaptation', ['group_id' => $groupId, 'rule_id' => $ruleId, 'rules' => $normalized], $userId);
            $this->db->commit();
            return ['saved' => $ruleId, 'needs_review' => $review, 'incompatible' => $incompatible];
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
            ps.installation_type,ps.output_type,ps.nominal_power_w,ps.min_output_power_w,ps.max_output_power_w,
            ps.output_current_ma,ps.output_current_min_ma,ps.output_current_max_ma,
            ps.output_voltage_min_v,ps.output_voltage_max_v,ps.length_mm,ps.width_mm,ps.height_mm,
            ps.supplier_warranty_years,ps.certification,pb.name power_band,
            chip.package_type chip_package_type,COALESCE(chip.min_power_w,chip.rated_power_w) chip_min_power_w,chip.rated_power_w chip_rated_power_w,
            chip.max_power_w chip_max_power_w,chip.voltage_v chip_voltage_v,
            COALESCE(chip.current_min_ma,chip.current_ma) chip_current_min_ma,
            COALESCE(chip.current_max_ma,chip.current_ma) chip_current_max_ma,
            chip.current_ma chip_current_ma,chip.pad_text chip_les_text,chip.size_text chip_size_text,
            optical.optical_type, optical.compatible_chip optical_compatible_chip,
            optical.compatible_les optical_compatible_les,optical.diameter_mm optical_diameter_mm,
            optical.height_mm optical_height_mm,optical.beam_angle_min optical_beam_angle_min,
            optical.beam_angle_max optical_beam_angle_max,optical.beam_angle_options optical_beam_angle_options,
            optical.material_text optical_material_text,
            optical.mounting_structure optical_mounting_structure,
            accessory.accessory_type,accessory.diameter_mm accessory_diameter_mm,
            accessory.thickness_mm accessory_thickness_mm,accessory.interface_type accessory_interface_type,
            accessory.installation_position accessory_installation_position,accessory.size_text accessory_size_text,
            accessory.color accessory_color,
            connector.interface_type connector_interface_type,connector.installation_type connector_installation_type,
            connector.load_kg connector_load_kg,
            (SELECT GROUP_CONCAT(DISTINCT d.mode ORDER BY d.mode) FROM mc_power_supply_dimming_modes d WHERE d.material_id=m.id) dimming_modes,
            (SELECT GROUP_CONCAT(DISTINCT o.current_ma ORDER BY o.current_ma) FROM mc_power_supply_current_options o WHERE o.material_id=m.id) current_options_ma,
            (SELECT GROUP_CONCAT(DISTINCT s.name ORDER BY s.name SEPARATOR '、') FROM mc_supplier_materials sm JOIN mc_suppliers s ON s.id=sm.supplier_id AND s.deleted_at IS NULL WHERE sm.material_id=m.id AND sm.status='active') suppliers
            FROM mc_materials m
            JOIN mc_material_categories c ON c.id=m.category_id
            LEFT JOIN mc_power_supply_specs ps ON ps.material_id=m.id
            LEFT JOIN mc_power_bands pb ON pb.id=ps.power_band_id
            LEFT JOIN mc_material_chip chip ON chip.material_id=m.id
            LEFT JOIN mc_material_optical optical ON optical.material_id=m.id
            LEFT JOIN mc_material_accessory accessory ON accessory.material_id=m.id
            LEFT JOIN mc_material_connector connector ON connector.material_id=m.id
            WHERE c.code=? AND m.is_official=1 AND m.deleted_at IS NULL";
        $params = [$category];
        $status = (string) ($filters['status'] ?? 'official');
        if ($status === 'all') $sql .= " AND m.status IN('official','disabled')";
        elseif ($status === 'disabled') $sql .= " AND m.status='disabled'";
        else $sql .= " AND m.status='official'";
        $materialId = (int) ($filters['material_id'] ?? 0);
        if ($materialId) {
            $sql .= ' AND m.id=?';
            $params[] = $materialId;
        }
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
        $profile = $this->technicalProfile((int) $group['product_id'])['values'];
        $quickRules = json_decode((string) ($group['rule_json'] ?? '{}'), true) ?: [];
        $quickRules = $this->mergeTechnicalProfileIntoQuickRules($quickRules, (string) ($group['business_type'] ?? ''), $profile);
        $group['rule_json'] = json_encode($quickRules, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $rule = $category === 'power_supply' ? $this->productPowerRule((int) $group['legacy_id']) : null;
        if ($category === 'power_supply' && !$rule && $profile) {
            $rule = $this->powerRuleFromTechnicalProfile($profile);
        }
        $selectedChip = $category === 'optical' ? $this->selectedChipForProduct((int) $group['product_id']) : null;
        foreach ($rows as &$row) {
            if ($selectedChip) {
                $row['selected_chip_material_id'] = $selectedChip['id'];
                $row['selected_chip_identity'] = $selectedChip['identity'];
                $row['selected_chip_label'] = $selectedChip['label'];
            }
            $match = $this->candidateMatch($row, $group, $rule);
            $row += $match;
            $row['key_specs'] = $this->keySpecs($row, $category);
            $row['already_added'] = $this->optionExists($groupId, (int) $row['id']);
        }
        unset($row);
        return $rows;
    }

    public function addOptions(int $groupId, array $materialIds, int $userId, string $forceExceptionReason = ''): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $materialIds))));
        if (!$ids) throw new RuntimeException('请至少选择一个物料。');
        $added = 0;
        $skipped = 0;
        $optionIds = [];
        $this->db->beginTransaction();
        try {
            foreach ($ids as $materialId) {
                if ($this->optionExists($groupId, $materialId)) {
                    $skipped++;
                    continue;
                }
                $optionIds[] = $this->saveOptionInternal([
                    'group_id' => $groupId,
                    'material_id' => $materialId,
                    'option_type' => 'optional',
                    'is_default' => 0,
                    'sort_order' => ($added + 1) * 10,
                    'force_exception_reason' => $forceExceptionReason,
                ], $userId);
                $added++;
            }
            $this->db->commit();
            return compact('added', 'skipped', 'optionIds');
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
        $forceExceptionReason = trim((string) ($data['force_exception_reason'] ?? ''));
        $exceptionApplied = false;
        if ($candidate['match_level'] === 'incompatible') {
            if ($forceExceptionReason === '') {
                throw new RuntimeException('该物料不适配：'.implode('；', $candidate['conflict_reasons']).'。如确需加入，请填写强制添加说明后提交审批。');
            }
            $candidate['requires_approval'] = true;
            $candidate['conflict_reasons'][] = '强制添加说明：'.$forceExceptionReason;
            $exceptionApplied = true;
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
        if ($candidate['category_code'] === 'chip') {
            (new ChipSpecificationService($this->db))->attachAllActiveToOption($id);
        }
        $this->markProductDraft((int) $group['product_id']);
        $this->log((int) $group['product_id'], 'save_option', ['option_id' => $id, 'group_id' => $groupId, 'material_id' => $materialId, 'match_level' => $candidate['match_level'], 'force_exception_reason' => $exceptionApplied ? $forceExceptionReason : null], $userId);
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
            $valueFreeOperator = in_array($operator, ['has_value', 'no_value'], true);
            if ((!$valueFreeOperator && ($expected === '' || $expected === null)) || ($operator === 'between' && (!is_array($expected) || count($expected) !== 2))) {
                throw new RuntimeException('条件值不完整。');
            }
            $normalized[] = [
                'option_id' => $optionId,
                'condition_group_no' => max(1, (int) ($row['condition_group_no'] ?? 1)),
                'boolean_connector' => $index === 0 ? 'AND' : $connector,
                'field_code' => $field,
                'operator' => $operator,
                'expected' => $valueFreeOperator ? null : $expected,
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
        $issues = [];
        $segments = ['technical' => 0, 'core' => 0, 'optional' => 0, 'rules' => 0, 'check' => 0];
        $profileData = $this->technicalProfile($productId);
        $profile = $profileData['values'];
        $technicalRequired = ['lamp_power_min_w', 'lamp_power_max_w', 'installation_type'];
        $technicalFilled = array_filter($technicalRequired, static fn(string $key): bool => ($profile[$key] ?? '') !== '' && ($profile[$key] ?? null) !== null && ($profile[$key] ?? 'unknown') !== 'unknown');
        if (!empty($profileData['confirmed_at'])) {
            $segments['technical'] = (int) round(20 * count($technicalFilled) / count($technicalRequired));
        }
        if (empty($profileData['confirmed_at']) || count($technicalFilled) !== count($technicalRequired)) $issues[] = '技术范围尚未完整确认（功率范围与安装方式为必填）。';

        $required = array_values(array_filter($groups, static fn(array $group): bool => $group['status'] !== 'disabled' && (int) $group['is_required']));
        if (!$required) {
            $issues[] = '尚未建立核心配置组。';
        } else {
            $completeCore = [];
            foreach ($required as $group) {
                if ((int) $group['option_count'] && ($group['selection_mode'] !== 'single' || $group['default_material'])) $completeCore[] = $group['group_name'];
                else $issues[] = '核心必配未完成：'.$group['group_name'];
            }
            $segments['core'] = (int) round(50 * count($completeCore) / count($required));
        }

        $later = [];
        foreach ($groups as $group) {
            if ((int) $group['is_required'] || $group['status'] === 'disabled') continue;
            $availability = $group['quick_rules']['availability'] ?? 'allowed';
            if ($availability === 'later') $later[] = $group['group_name'];
        }
        $technicalReady = !empty($profileData['confirmed_at']) && count($technicalFilled) === count($technicalRequired);
        $coreReady = $required && count($required) === count($completeCore ?? []);
        if ($later) { $segments['optional'] = 0; $issues[] = '可选配置仍标记为稍后处理：'.implode('、', $later); }
        elseif ($technicalReady && $coreReady && $groups) $segments['optional'] = 10;

        $invalidConditionStmt = $this->db->prepare("SELECT COUNT(*) FROM mc_adaptation_conditions c JOIN mc_adaptation_options o ON o.id=c.option_id JOIN mc_adaptation_groups g ON g.id=o.group_id WHERE g.product_id=? AND (c.field_code='' OR c.operator='' OR c.expected_json IS NULL)");
        $invalidConditionStmt->execute([$productId]);
        $invalidConditions = (int) $invalidConditionStmt->fetchColumn();
        if ($invalidConditions) { $segments['rules'] = 0; $issues[] = "存在 {$invalidConditions} 条不完整适用条件"; }
        elseif ($technicalReady && $coreReady && $groups) $segments['rules'] = 10;

        $conflicts = $this->conflicts($productId);
        $exceptionStmt = $this->db->prepare("SELECT COUNT(*) FROM mc_adaptation_options o JOIN mc_adaptation_groups g ON g.id=o.group_id WHERE g.product_id=? AND g.status<>'disabled' AND o.requires_approval=1 AND o.exception_approved=0");
        $exceptionStmt->execute([$productId]);
        $exceptions = (int) $exceptionStmt->fetchColumn();
        $invalidMaterialStmt = $this->db->prepare("SELECT COUNT(*) FROM mc_adaptation_options o JOIN mc_adaptation_groups g ON g.id=o.group_id JOIN mc_materials m ON m.id=o.material_id WHERE g.product_id=? AND g.status<>'disabled' AND (m.status<>'official' OR o.match_level='incompatible')");
        $invalidMaterialStmt->execute([$productId]);
        $invalidMaterials = (int) $invalidMaterialStmt->fetchColumn();
        if ($conflicts || $exceptions || $invalidMaterials) {
            $segments['check'] = 0;
            if ($conflicts) $issues[] = '存在 '.count($conflicts).' 条未解决冲突';
            if ($exceptions) $issues[] = "存在 {$exceptions} 个未批准的适配例外";
            if ($invalidMaterials) $issues[] = "存在 {$invalidMaterials} 个非正式或不适配物料";
        } elseif (!$issues) {
            $segments['check'] = 10;
        }
        $percent = array_sum($segments);
        return [
            'percent' => $percent,
            'ready' => !$issues,
            'issues' => $issues,
            'exception_count' => $exceptions,
            'checks_passed' => count(array_filter($segments, static fn(int $score): bool => $score > 0)),
            'checks_total' => 5,
            'segments' => $segments,
        ];
    }

    public function evaluate(int $productId, array $optionIds, array $context = []): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $optionIds))));
        if (!$ids) return ['compatible' => true, 'reasons' => [], 'price_impact' => 0.0, 'lead_time_impact_days' => 0];
        $marks = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->db->prepare("SELECT o.id,o.material_id,o.price_impact,o.lead_time_impact_days,
            g.group_type,g.business_type,g.group_name,g.rule_json
            FROM mc_adaptation_options o JOIN mc_adaptation_groups g ON g.id=o.group_id
            WHERE g.product_id=? AND o.id IN($marks)");
        $stmt->execute(array_merge([$productId], $ids));
        $options = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (count($options) !== count($ids)) throw new RuntimeException('选择中包含不属于该产品的适配选项。');
        $reasons = [];
        $businessTypes = array_column($options, null, 'business_type');
        if (isset($businessTypes['honeycomb'], $businessTypes['glass'])) {
            $honeycombRules = json_decode((string) ($businessTypes['honeycomb']['rule_json'] ?? '{}'), true) ?: [];
            $glassRules = json_decode((string) ($businessTypes['glass']['rule_json'] ?? '{}'), true) ?: [];
            if (($honeycombRules['allow_with_glass'] ?? 'yes') === 'no' || ($glassRules['allow_with_honeycomb'] ?? 'yes') === 'no') {
                $reasons[] = [
                    'type' => 'group_rule',
                    'severity' => 'block',
                    'reason' => '当前产品设置为蜂巢网与玻璃不能同时安装',
                ];
            }
        }
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
            // Build the commercial payload before the current working rows are changed to approved.
            // This is the immutable release that quotation/BOM readers use until the next release.
            $commercialRows = $this->commercialRowsForProduct($productId);
            $snapshot = json_encode([
                'groups' => $groups,
                'configuration_overview' => $this->configurationOverview($productId),
                'conflicts' => $this->conflicts($productId),
                'completion' => $completion,
                'commercial_rows' => $commercialRows,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $this->db->prepare("INSERT INTO mc_approvals(approval_type,entity_type,entity_id,status,requested_by,requested_at,completed_at,current_step,request_json) VALUES('product_adaptation','product',?,'approved',?,NOW(),NOW(),1,?)")
                ->execute([$productId, $userId, $snapshot]);
            $approvalId = (int) $this->db->lastInsertId();
            $this->db->prepare("INSERT INTO mc_adaptation_approvals(product_id,approval_id,version_no,status) VALUES(?,?,?,'approved')")
                ->execute([$productId, $approvalId, $version]);
            if ($this->tableExists('mc_adaptation_published_versions')) {
                $this->db->prepare("INSERT INTO mc_adaptation_published_versions(product_id,version_no,status,snapshot_json,approval_id,published_by,published_at)
                    VALUES(?,?, 'published', ?,?,?,NOW())")
                    ->execute([$productId, $version, $snapshot, $approvalId, $userId]);
            }
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
        if ($this->tableExists('mc_adaptation_published_versions')) {
            $published = $this->db->prepare("SELECT v.snapshot_json
                FROM mc_adaptation_published_versions v
                JOIN mc_products p ON p.id=v.product_id
                WHERE p.legacy_table='naming_models' AND p.legacy_id=? AND v.status='published'
                ORDER BY v.version_no DESC LIMIT 1");
            $published->execute([$legacyProductId]);
            $snapshot = $published->fetchColumn();
            if (is_string($snapshot) && $snapshot !== '') {
                $rows = json_decode($snapshot, true);
                if (is_array($rows) && is_array($rows['commercial_rows'] ?? null)) return $rows['commercial_rows'];
            }
        }
        // Compatibility fallback for historic releases created before the immutable-version table.
        // It deliberately reads only the old fully approved state, never a later working draft.
        return $this->commercialRowsForProduct($legacyProductId, true, true);
    }

    /** @return array<int,array<string,mixed>> */
    private function commercialRowsForProduct(int $productReference, bool $isLegacyId = false, bool $requireApproved = false): array
    {
        $productWhere = $isLegacyId ? "p.legacy_table='naming_models' AND p.legacy_id=?" : 'p.id=?';
        $groupState = $requireApproved ? " AND g.status='approved' AND g.is_enabled=1" : " AND g.status<>'disabled'";
        $optionState = $requireApproved ? " AND o.status='approved'" : '';
        $historicReleaseGuard = $requireApproved
            ? " AND NOT EXISTS(SELECT 1 FROM mc_adaptation_groups gx WHERE gx.product_id=p.id AND gx.status<>'disabled' AND (gx.status<>'approved' OR gx.is_enabled=0))
                AND NOT EXISTS(SELECT 1 FROM mc_adaptation_options ox JOIN mc_adaptation_groups gox ON gox.id=ox.group_id WHERE gox.product_id=p.id AND gox.status<>'disabled' AND ox.status<>'approved')"
            : '';
        $stmt = $this->db->prepare("SELECT p.product_code,p.product_name,g.group_code,g.group_name,g.business_type,g.is_required,g.selection_mode,
            o.option_type,o.is_default,o.price_impact,o.lead_time_impact_days,m.id material_id,m.material_code,m.name material_name,m.brand,m.model
            FROM mc_products p
            JOIN mc_adaptation_groups g ON g.product_id=p.id{$groupState}
            JOIN mc_adaptation_options o ON o.group_id=g.id AND o.option_type<>'disabled'{$optionState}
            JOIN mc_materials m ON m.id=o.material_id AND m.status='official' AND m.is_official=1 AND m.deleted_at IS NULL
            WHERE {$productWhere}{$historicReleaseGuard}
            ORDER BY g.sort_order,o.sort_order");
        $stmt->execute([$productReference]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function copyProductConfiguration(
        int $sourceProductId,
        int $targetProductId,
        string $mode,
        string $batchUuid,
        int $userId,
        ?array $sourceGroupIds = null
    ): array
    {
        $sourceGroups = $this->selectedSourceGroups($sourceProductId, $sourceGroupIds);
        $optionMap = [];
        $created = 0;
        $overwritten = 0;
        $skipped = 0;
        $optionsCopied = 0;
        $changed = false;

        foreach ($sourceGroups as $sourceGroup) {
            $targetStmt = $this->db->prepare('SELECT * FROM mc_adaptation_groups WHERE product_id=? AND group_code=?');
            $targetStmt->execute([$targetProductId, $sourceGroup['group_code']]);
            $targetGroup = $targetStmt->fetch(PDO::FETCH_ASSOC);
            $sourceOptions = $this->options((int) $sourceGroup['id']);

            if ($targetGroup && $mode === 'fill_missing') {
                $targetOptionsStmt = $this->db->prepare('SELECT id,material_id FROM mc_adaptation_options WHERE group_id=?');
                $targetOptionsStmt->execute([$targetGroup['id']]);
                $byMaterial = [];
                foreach ($targetOptionsStmt->fetchAll(PDO::FETCH_ASSOC) as $row) $byMaterial[(int) $row['material_id']] = (int) $row['id'];
                foreach ($sourceOptions as $option) {
                    if (isset($byMaterial[(int) $option['material_id']])) $optionMap[(int) $option['id']] = $byMaterial[(int) $option['material_id']];
                }
                $skipped++;
                continue;
            }

            if ($targetGroup) {
                $oldOptionIds = $this->optionIds((int) $targetGroup['id']);
                if ($oldOptionIds) {
                    $marks = implode(',', array_fill(0, count($oldOptionIds), '?'));
                    $this->db->prepare("DELETE FROM mc_adaptation_conflicts WHERE product_id=? AND (left_option_id IN($marks) OR right_option_id IN($marks))")
                        ->execute(array_merge([$targetProductId], $oldOptionIds, $oldOptionIds));
                    $this->db->prepare("DELETE FROM mc_adaptation_conditions WHERE option_id IN($marks)")->execute($oldOptionIds);
                    $this->db->prepare("DELETE FROM mc_adaptation_defaults WHERE group_id=? OR option_id IN($marks)")
                        ->execute(array_merge([$targetGroup['id']], $oldOptionIds));
                }
                $this->db->prepare('DELETE FROM mc_adaptation_options WHERE group_id=?')->execute([$targetGroup['id']]);
                $this->db->prepare("UPDATE mc_adaptation_groups SET
                    group_name=?,group_type=?,business_type=?,material_category_code=?,is_required=?,selection_mode=?,
                    min_select=?,max_select=?,template_key=?,rule_json=?,status=?,is_enabled=0,sort_order=?,
                    updated_by=?,updated_at=NOW() WHERE id=?")
                    ->execute([
                        $sourceGroup['group_name'], $sourceGroup['group_type'], $sourceGroup['business_type'],
                        $sourceGroup['material_category_code'], $sourceGroup['is_required'], $sourceGroup['selection_mode'],
                        $sourceGroup['min_select'], $sourceGroup['max_select'], $sourceGroup['template_key'],
                        $sourceGroup['rule_json'], $sourceGroup['status'] === 'disabled' ? 'disabled' : 'draft',
                        $sourceGroup['sort_order'], $userId, $targetGroup['id'],
                    ]);
                $targetGroupId = (int) $targetGroup['id'];
                $overwritten++;
            } else {
                $this->db->prepare("INSERT INTO mc_adaptation_groups
                    (product_id,group_code,group_name,group_type,business_type,material_category_code,is_required,
                    selection_mode,min_select,max_select,template_key,rule_json,status,is_enabled,sort_order,
                    created_by,updated_by,created_at,updated_at)
                    VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,0,?,?,?,NOW(),NOW())")
                    ->execute([
                        $targetProductId, $sourceGroup['group_code'], $sourceGroup['group_name'], $sourceGroup['group_type'],
                        $sourceGroup['business_type'], $sourceGroup['material_category_code'], $sourceGroup['is_required'],
                        $sourceGroup['selection_mode'], $sourceGroup['min_select'], $sourceGroup['max_select'],
                        $sourceGroup['template_key'], $sourceGroup['rule_json'],
                        $sourceGroup['status'] === 'disabled' ? 'disabled' : 'draft',
                        $sourceGroup['sort_order'], $userId, $userId,
                    ]);
                $targetGroupId = (int) $this->db->lastInsertId();
                $created++;
            }
            $changed = true;

            $conditionsByOption = [];
            foreach ($this->conditions((int) $sourceGroup['id']) as $condition) {
                $conditionsByOption[(int) $condition['option_id']][] = $condition;
            }
            foreach ($sourceOptions as $sourceOption) {
                $candidate = $this->candidateMaterials($targetGroupId, [
                    'status' => 'all',
                    'material_id' => (int) $sourceOption['material_id'],
                ])[0] ?? null;
                if (!$candidate || $candidate['status'] !== 'official') {
                    throw new RuntimeException($sourceGroup['group_name'].'中的物料 '.$sourceOption['material_code'].' 已不是正式物料。');
                }
                if ($candidate['match_level'] === 'incompatible') {
                    throw new RuntimeException($sourceGroup['group_name'].'中的物料 '.$sourceOption['material_code'].' 不适配：'.implode('；', $candidate['conflict_reasons']));
                }
                $this->db->prepare("INSERT INTO mc_adaptation_options
                    (group_id,material_id,match_level,match_reason_json,requires_approval,exception_approved,
                    option_type,is_default,price_impact,lead_time_impact_days,status,sort_order)
                    VALUES(?,?,?,?,?,0,?,?,?,?, 'draft',?)")
                    ->execute([
                        $targetGroupId, $sourceOption['material_id'], $candidate['match_level'],
                        json_encode($candidate['conflict_reasons'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                        $candidate['requires_approval'] ? 1 : 0, $sourceOption['option_type'], $sourceOption['is_default'],
                        $sourceOption['price_impact'], $sourceOption['lead_time_impact_days'], $sourceOption['sort_order'],
                    ]);
                $targetOptionId = (int) $this->db->lastInsertId();
                $optionMap[(int) $sourceOption['id']] = $targetOptionId;
                (new ChipSpecificationService($this->db))->copyOptionVariants((int) $sourceOption['id'], $targetOptionId);
                $optionsCopied++;
                foreach ($conditionsByOption[(int) $sourceOption['id']] ?? [] as $condition) {
                    $this->db->prepare('INSERT INTO mc_adaptation_conditions
                        (option_id,condition_group_no,boolean_connector,field_code,operator,expected_json,failure_message,severity,sort_order)
                        VALUES(?,?,?,?,?,?,?,?,?)')
                        ->execute([
                            $targetOptionId, $condition['condition_group_no'], $condition['boolean_connector'],
                            $condition['field_code'], $condition['operator'],
                            json_encode($condition['expected'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                            $condition['failure_message'], $condition['severity'], $condition['sort_order'],
                        ]);
                }
            }
        }

        if ($changed) {
            foreach ($this->conflicts($sourceProductId) as $conflict) {
                $left = $optionMap[(int) $conflict['left_option_id']] ?? 0;
                $right = $optionMap[(int) $conflict['right_option_id']] ?? 0;
                if (!$left || !$right || $left === $right) continue;
                if ($left > $right) [$left, $right] = [$right, $left];
                $this->db->prepare("INSERT INTO mc_adaptation_conflicts
                    (product_id,left_option_id,right_option_id,reason,severity,status)
                    VALUES(?,?,?,?,?,'active')
                    ON DUPLICATE KEY UPDATE reason=VALUES(reason),severity=VALUES(severity),status='active'")
                    ->execute([$targetProductId, $left, $right, $conflict['reason'], $conflict['severity']]);
            }
            $this->markProductDraft($targetProductId);
        }
        $detail = [
            'batch_uuid' => $batchUuid,
            'source_product_id' => $sourceProductId,
            'source_group_ids' => array_map('intval', array_column($sourceGroups, 'id')),
            'mode' => $mode,
            'groups_created' => $created,
            'groups_overwritten' => $overwritten,
            'groups_skipped' => $skipped,
            'options_copied' => $optionsCopied,
        ];
        $this->log($targetProductId, 'batch_apply_target', $detail, $userId);
        return $detail;
    }

    private function selectedSourceGroups(int $sourceProductId, ?array $sourceGroupIds): array
    {
        $groups = $this->groups($sourceProductId);
        if ($sourceGroupIds === null) return $groups;
        $requested = array_values(array_unique(array_filter(array_map('intval', $sourceGroupIds))));
        if (!$requested) return [];
        $selected = array_values(array_filter(
            $groups,
            static fn(array $group): bool => in_array((int) $group['id'], $requested, true)
        ));
        if (count($selected) !== count($requested)) {
            throw new RuntimeException('所选配置组不属于来源产品，请刷新页面后重试。');
        }
        return $selected;
    }

    private function copyPowerRule(int $sourceProductId, int $targetProductId, string $mode, int $userId): string
    {
        $source = $this->productRow($sourceProductId);
        $target = $this->productRow($targetProductId);
        if (!$source || !$target) throw new RuntimeException('批量电源规则的产品不存在。');
        $stmt = $this->db->prepare("SELECT * FROM mc_product_power_rules WHERE legacy_product_table='naming_models' AND legacy_product_id=?");
        $stmt->execute([$source['legacy_id']]);
        $sourceRule = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$sourceRule) return 'source_missing';

        $stmt->execute([$target['legacy_id']]);
        $targetRule = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($targetRule && $mode === 'fill_missing') return 'skipped';
        $values = [
            $sourceRule['rule_name'], $sourceRule['installation_type'], $sourceRule['output_type'],
            $sourceRule['lamp_power_w'], $sourceRule['lamp_power_min_w'], $sourceRule['lamp_power_max_w'], $sourceRule['power_band_id'], $sourceRule['output_current_min_ma'],
            $sourceRule['output_current_max_ma'], $sourceRule['output_voltage_min_v'], $sourceRule['output_voltage_max_v'],
            $sourceRule['max_length_mm'], $sourceRule['max_width_mm'], $sourceRule['max_height_mm'],
            $sourceRule['minimum_warranty_years'], $sourceRule['certification_required'],
        ];
        if ($targetRule) {
            $this->db->prepare("UPDATE mc_product_power_rules SET
                rule_name=?,installation_type=?,output_type=?,lamp_power_w=?,lamp_power_min_w=?,lamp_power_max_w=?,power_band_id=?,
                output_current_min_ma=?,output_current_max_ma=?,output_voltage_min_v=?,output_voltage_max_v=?,
                max_length_mm=?,max_width_mm=?,max_height_mm=?,minimum_warranty_years=?,
                certification_required=?,status='draft',updated_by=?,updated_at=NOW() WHERE id=?")
                ->execute(array_merge($values, [$userId, $targetRule['id']]));
            $targetRuleId = (int) $targetRule['id'];
            $action = 'overwritten';
        } else {
            $this->db->prepare("INSERT INTO mc_product_power_rules
                (legacy_product_table,legacy_product_id,rule_name,installation_type,output_type,lamp_power_w,
                lamp_power_min_w,lamp_power_max_w,power_band_id,output_current_min_ma,output_current_max_ma,output_voltage_min_v,output_voltage_max_v,
                max_length_mm,max_width_mm,max_height_mm,minimum_warranty_years,certification_required,status,
                created_by,updated_by,created_at,updated_at)
                VALUES('naming_models',?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,'draft',?,?,NOW(),NOW())")
                ->execute(array_merge([(int) $target['legacy_id']], $values, [$userId, $userId]));
            $targetRuleId = (int) $this->db->lastInsertId();
            $action = 'created';
        }
        $this->db->prepare('DELETE FROM mc_product_power_rule_dimming_modes WHERE rule_id=?')->execute([$targetRuleId]);
        $modeStmt = $this->db->prepare('SELECT mode FROM mc_product_power_rule_dimming_modes WHERE rule_id=?');
        $modeStmt->execute([$sourceRule['id']]);
        $insert = $this->db->prepare('INSERT INTO mc_product_power_rule_dimming_modes(rule_id,mode) VALUES(?,?)');
        foreach ($modeStmt->fetchAll(PDO::FETCH_COLUMN) as $dimmingMode) $insert->execute([$targetRuleId, $dimmingMode]);
        return $action;
    }

    private function batchTargets(int $sourceProductId, array $targetProductIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $targetProductIds), static fn(int $id): bool => $id > 0 && $id !== $sourceProductId)));
        if (count($ids) > 1000) throw new RuntimeException('一次最多处理 1000 个目标产品，请分批执行。');
        if (!$ids) return [];
        $marks = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->db->prepare("SELECT p.*,
            (SELECT MAX(a.version_no) FROM mc_adaptation_approvals a WHERE a.product_id=p.id AND a.status='approved') approved_version
            FROM mc_products p WHERE p.status='active' AND p.id IN($marks) ORDER BY p.product_code");
        $stmt->execute($ids);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (count($rows) !== count($ids)) throw new RuntimeException('目标产品中包含不存在或已停用的记录。');
        return $rows;
    }

    private function productRow(int $productId): ?array
    {
        $stmt = $this->db->prepare("SELECT p.*,
            (SELECT MAX(a.version_no) FROM mc_adaptation_approvals a WHERE a.product_id=p.id AND a.status='approved') approved_version
            FROM mc_products p WHERE p.id=? AND p.status='active'");
        $stmt->execute([$productId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private function candidateMatch(array $material, array $group, ?array $rule): array
    {
        if ($material['status'] !== 'official') {
            return ['match_level' => 'incompatible', 'match_label' => '不适配', 'conflict_reasons' => ['物料已经停用'], 'requires_approval' => true];
        }
        $quickRules = json_decode((string) ($group['rule_json'] ?? '{}'), true) ?: [];
        if (($quickRules['availability'] ?? 'allowed') !== 'allowed') {
            return [
                'match_level' => 'incompatible',
                'match_label' => '不适配',
                'conflict_reasons' => ['当前产品未启用'.$group['group_name'].'配置（'.$this->availabilityLabel((string) $quickRules['availability']).'）'],
                'requires_approval' => true,
            ];
        }
        if ($group['material_category_code'] !== 'power_supply') {
            if (($group['business_type'] ?? '') === 'custom') {
                return ['match_level' => 'needs_approval', 'match_label' => '需要审批', 'conflict_reasons' => ['自定义配置组尚无自动规格规则，需要工程审批'], 'requires_approval' => true];
            }
            return $this->componentCandidateMatch($material, $group, $quickRules);
        }
        if (!$rule) {
            return ['match_level' => 'needs_approval', 'match_label' => '需要审批', 'conflict_reasons' => ['当前产品尚未维护电源匹配规则'], 'requires_approval' => true];
        }
        if ($material['max_output_power_w'] === null) {
            return ['match_level' => 'needs_approval', 'match_label' => '需要审批', 'conflict_reasons' => ['电源关键规格不完整，无法自动完成匹配'], 'requires_approval' => true];
        }
        $lampPowerMin = $rule['lamp_power_min_w'] ?? null;
        if ($lampPowerMin !== null && $material['min_output_power_w'] === null) {
            return ['match_level' => 'needs_approval', 'match_label' => '需要审批', 'conflict_reasons' => ['电源最低输出功率未确认，无法核验产品最低功率范围'], 'requires_approval' => true];
        }
        $reasons = $this->comparePower($material, $rule);
        if ($reasons) return ['match_level' => 'incompatible', 'match_label' => '不适配', 'conflict_reasons' => $reasons, 'requires_approval' => true];
        if (($rule['status'] ?? '') !== 'approved') {
            return ['match_level' => 'needs_approval', 'match_label' => '需要审批', 'conflict_reasons' => ['产品电源规则尚未审批'], 'requires_approval' => true];
        }
        return ['match_level' => 'exact', 'match_label' => '完全适配', 'conflict_reasons' => [], 'requires_approval' => false];
    }

    /** Merge product-level engineering boundaries into group rules without erasing more precise group rules. */
    private function mergeTechnicalProfileIntoQuickRules(array $rules, string $businessType, array $profile): array
    {
        $maps = [
            'chip' => [
                'lamp_power_min_w' => 'power_min_w', 'lamp_power_max_w' => 'power_max_w',
                'output_current_min_ma' => 'current_min_ma', 'output_current_max_ma' => 'current_max_ma',
                'output_voltage_min_v' => 'voltage_min_v', 'output_voltage_max_v' => 'voltage_max_v',
                'optical_les_mm' => 'les_contains',
            ],
            'optical' => [
                'optical_diameter_mm' => 'diameter_min_mm', 'optical_diameter_mm' => 'diameter_max_mm',
                'optical_height_mm' => 'height_max_mm', 'beam_angle_min_deg' => 'beam_min_deg',
                'beam_angle_max_deg' => 'beam_max_deg', 'optical_les_mm' => 'les_contains',
            ],
            'glass' => ['optical_diameter_mm' => 'diameter_min_mm', 'optical_diameter_mm' => 'diameter_max_mm', 'optical_height_mm' => 'height_max_mm'],
            'reflector' => ['optical_diameter_mm' => 'diameter_min_mm', 'optical_diameter_mm' => 'diameter_max_mm', 'optical_height_mm' => 'height_max_mm'],
        ];
        // PHP arrays cannot repeat a source key, so map exact optical diameter separately.
        if (in_array($businessType, ['optical', 'glass', 'reflector'], true) && !array_key_exists('diameter_min_mm', $rules) && !empty($profile['optical_diameter_mm'])) {
            $rules['diameter_min_mm'] = $profile['optical_diameter_mm'];
        }
        foreach ($maps[$businessType] ?? [] as $source => $target) {
            if (array_key_exists($target, $rules) || !isset($profile[$source]) || $profile[$source] === '') continue;
            $rules[$target] = $source === 'optical_les_mm' ? (string) $profile[$source].'mm' : $profile[$source];
        }
        return $rules;
    }

    /** Keep candidate screening useful before an older power-rule record exists. */
    private function powerRuleFromTechnicalProfile(array $profile): array
    {
        return [
            'status' => 'draft',
            'installation_type' => (string) ($profile['installation_type'] ?? 'unknown'),
            'output_type' => 'unknown',
            'lamp_power_w' => $profile['lamp_power_max_w'] ?? null,
            'lamp_power_min_w' => $profile['lamp_power_min_w'] ?? null,
            'lamp_power_max_w' => $profile['lamp_power_max_w'] ?? null,
            'output_current_min_ma' => $profile['output_current_min_ma'] ?? null,
            'output_current_max_ma' => $profile['output_current_max_ma'] ?? null,
            'output_voltage_min_v' => $profile['output_voltage_min_v'] ?? null,
            'output_voltage_max_v' => $profile['output_voltage_max_v'] ?? null,
            'max_length_mm' => $profile['max_length_mm'] ?? null,
            'max_width_mm' => $profile['max_width_mm'] ?? null,
            'max_height_mm' => $profile['max_height_mm'] ?? null,
            'minimum_warranty_years' => $profile['minimum_warranty_years'] ?? null,
            'certification_required' => $profile['certification_required'] ?? '',
            'required_dimming_modes' => is_array($profile['dimming_modes'] ?? null) ? $profile['dimming_modes'] : [],
        ];
    }

    private function selectedChipForProduct(int $productId): ?array
    {
        if (!$productId || !$this->tableExists('mc_adaptation_options')) return null;
        $stmt = $this->db->prepare("SELECT m.id,m.material_code,m.name,m.brand,m.model,chip.pad_text
            FROM mc_adaptation_groups g
            JOIN mc_adaptation_options o ON o.group_id=g.id AND o.status<>'disabled'
            JOIN mc_materials m ON m.id=o.material_id AND m.deleted_at IS NULL
            JOIN mc_material_categories c ON c.id=m.category_id AND c.code='chip'
            LEFT JOIN mc_material_chip chip ON chip.material_id=m.id
            WHERE g.product_id=? AND COALESCE(g.is_enabled,1)=1
              AND (g.material_category_code='chip' OR g.business_type='chip')
            ORDER BY o.is_default DESC,o.id DESC
            LIMIT 1");
        $stmt->execute([$productId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) return null;
        $identity = mb_strtolower(trim(implode(' ', array_filter([
            $row['material_code'] ?? '',
            $row['brand'] ?? '',
            $row['model'] ?? '',
            $row['name'] ?? '',
            $row['pad_text'] ?? '',
        ]))));
        return [
            'id' => (int) $row['id'],
            'identity' => $identity,
            'label' => trim(implode(' · ', array_filter([$row['material_code'] ?? '', $row['brand'] ?? '', $row['model'] ?? '', $row['name'] ?? '']))),
        ];
    }

    private function opticalBeamEvidence(array $material): array
    {
        $fallbackMin = $material['optical_beam_angle_min'] ?? null;
        $fallbackMax = $material['optical_beam_angle_max'] ?? null;
        $lensId = (int) ($material['id'] ?? 0);
        if (!$lensId || !$this->tableExists('mc_lens_chip_angle_compatibilities')) {
            return ['min' => $fallbackMin, 'max' => $fallbackMax, 'label' => '透镜通用角度范围'];
        }

        $selectedChipId = (int) ($material['selected_chip_material_id'] ?? 0);
        $selectedIdentity = (string) ($material['selected_chip_identity'] ?? '');
        $rows = $this->lensAngleRowsForSelectedChip($lensId, $selectedChipId, $selectedIdentity);
        if ($rows) {
            $angles = array_map(static fn(array $row): float => (float) $row['actual_beam_angle_deg'], $rows);
            $labels = array_values(array_unique(array_filter(array_map(static fn(array $row): string => trim((string) ($row['beam_angle_label'] ?: $row['actual_beam_angle_deg'].'°')), $rows))));
            $label = '按芯片适配表'.(!empty($material['selected_chip_label']) ? '（'.$material['selected_chip_label'].'）' : '');
            if ($labels) $label .= '：'.implode('/', array_slice($labels, 0, 6));
            return ['min' => min($angles), 'max' => max($angles), 'label' => $label];
        }

        if ($selectedChipId && $this->lensHasAngleRows($lensId)) {
            return ['min' => null, 'max' => null, 'label' => '', 'missing' => '透镜已维护芯片角度适配，但未覆盖当前芯片'];
        }

        return ['min' => $fallbackMin, 'max' => $fallbackMax, 'label' => '透镜通用角度范围'];
    }

    private function lensAngleRowsForSelectedChip(int $lensId, int $chipId, string $chipIdentity): array
    {
        if ($chipId) {
            $stmt = $this->db->prepare("SELECT * FROM mc_lens_chip_angle_compatibilities WHERE lens_material_id=? AND status='active' AND chip_material_id=? ORDER BY sort_order,id");
            $stmt->execute([$lensId, $chipId]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            if ($rows) return $rows;
        }

        $stmt = $this->db->prepare("SELECT * FROM mc_lens_chip_angle_compatibilities WHERE lens_material_id=? AND status='active' ORDER BY sort_order,id");
        $stmt->execute([$lensId]);
        $generic = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $keyword = mb_strtolower(trim((string) ($row['chip_keyword'] ?? '')));
            if ($keyword === '' && !$row['chip_material_id']) {
                $generic[] = $row;
                continue;
            }
            if ($keyword !== '' && $chipIdentity !== '' && str_contains($chipIdentity, $keyword)) {
                $generic[] = $row;
            }
        }
        return $generic;
    }

    private function lensHasAngleRows(int $lensId): bool
    {
        $stmt = $this->db->prepare("SELECT 1 FROM mc_lens_chip_angle_compatibilities WHERE lens_material_id=? AND status='active' LIMIT 1");
        $stmt->execute([$lensId]);
        return (bool) $stmt->fetchColumn();
    }

    private function componentCandidateMatch(array $material, array $group, array $rules): array
    {
        $type = (string) ($group['business_type'] ?? '');
        $mismatches = [];
        $missing = [];
        $identity = mb_strtolower(trim(implode(' ', array_filter([
            $material['name'] ?? '',
            $material['model'] ?? '',
            $material['accessory_type'] ?? '',
            $material['optical_type'] ?? '',
        ]))));
        if ($type === 'honeycomb' && !str_contains($identity, '蜂巢') && !str_contains($identity, '蜂窝') && !str_contains($identity, 'honeycomb')) {
            if (!empty($material['accessory_type'])) $mismatches[] = '配件类别不是蜂巢网';
            else $missing[] = '配件类别未确认，无法判断是否为蜂巢网';
        }
        if ($type === 'glass' && !str_contains($identity, '玻璃') && !str_contains($identity, 'glass')) {
            if (!empty($material['optical_type'])) $mismatches[] = '光学类别不是玻璃';
            else $missing[] = '光学类别未确认，无法判断是否为玻璃';
        }
        if ($type === 'reflector' && !str_contains($identity, '反光杯') && !str_contains($identity, '格栅') && !str_contains($identity, 'reflector')) {
            if (!empty($material['optical_type'])) $mismatches[] = '光学类别不是反光杯 / 格栅';
            else $missing[] = '光学类别未确认，无法判断是否为反光杯 / 格栅';
        }
        if ($type === 'optical' && (str_contains($identity, '玻璃') || str_contains($identity, 'glass'))) {
            $mismatches[] = '玻璃应加入“玻璃”配置组，不应加入主光学组';
        }

        $numericRules = [
            'power_min_w' => ['chip_min_power_w', '芯片最小功率', 'min', 'W'],
            'power_max_w' => ['chip_max_power_w', '芯片最大功率', 'max', 'W'],
            'current_min_ma' => ['chip_current_min_ma', '芯片最小电流', 'min', 'mA'],
            'current_max_ma' => ['chip_current_max_ma', '芯片最大电流', 'max', 'mA'],
            'voltage_min_v' => ['chip_voltage_v', '芯片电压', 'min', 'V'],
            'voltage_max_v' => ['chip_voltage_v', '芯片电压', 'max', 'V'],
            'diameter_min_mm' => [$type === 'honeycomb' || $type === 'accessory' ? 'accessory_diameter_mm' : 'optical_diameter_mm', '直径', 'min', 'mm'],
            'diameter_max_mm' => [$type === 'honeycomb' || $type === 'accessory' ? 'accessory_diameter_mm' : 'optical_diameter_mm', '直径', 'max', 'mm'],
            'height_max_mm' => ['optical_height_mm', $type === 'glass' ? '玻璃厚度' : '高度', 'max', 'mm'],
            'thickness_max_mm' => ['accessory_thickness_mm', '厚度 / 叠加高度', 'max', 'mm'],
            'load_min_kg' => ['connector_load_kg', '承重', 'min', 'kg'],
        ];
        foreach ($numericRules as $ruleKey => [$actualKey, $label, $direction, $unit]) {
            if (!array_key_exists($ruleKey, $rules)) continue;
            $actual = $material[$actualKey] ?? null;
            if ($actual === null || $actual === '') {
                $missing[] = $label.'未确认';
                continue;
            }
            $expected = (float) $rules[$ruleKey];
            $number = (float) $actual;
            if ($direction === 'min' && $number < $expected) $mismatches[] = $label.$this->number($number).$unit.'，低于要求 '.$this->number($expected).$unit;
            if ($direction === 'max' && $number > $expected) $mismatches[] = $label.$this->number($number).$unit.'，超过上限 '.$this->number($expected).$unit;
        }
        if (array_key_exists('beam_min_deg', $rules) || array_key_exists('beam_max_deg', $rules)) {
            $beamEvidence = $this->opticalBeamEvidence($material);
            $actualMin = $beamEvidence['min'] ?? null;
            $actualMax = $beamEvidence['max'] ?? null;
            if (!empty($beamEvidence['missing'])) {
                $missing[] = $beamEvidence['missing'];
            } elseif ($actualMin === null && $actualMax === null) {
                $missing[] = '光束角未确认';
            } elseif (!$this->rangeOverlaps($rules['beam_min_deg'] ?? null, $rules['beam_max_deg'] ?? null, $actualMin, $actualMax)) {
                $mismatches[] = '光束角范围不匹配'.(!empty($beamEvidence['label']) ? '（'.$beamEvidence['label'].'）' : '');
            }
        }
        $textRules = [
            'package_contains' => ['chip_package_type', '封装'],
            'les_contains' => [$type === 'chip' ? 'chip_les_text' : 'optical_compatible_les', 'LES / 尺寸'],
            'mounting_contains' => ['optical_mounting_structure', '固定方式'],
            'material_contains' => ['optical_material_text', '材质'],
            'type_contains' => ['accessory_type', '配件类别'],
            'interface_contains' => [$type === 'installation' ? 'connector_interface_type' : 'accessory_interface_type', '接口'],
            'position_contains' => ['accessory_installation_position', '安装位置'],
            'installation_contains' => ['connector_installation_type', '安装方式'],
            'color_contains' => ['accessory_color', '颜色'],
        ];
        foreach ($textRules as $ruleKey => [$actualKey, $label]) {
            $expected = trim((string) ($rules[$ruleKey] ?? ''));
            if ($expected === '') continue;
            $actual = trim((string) ($material[$actualKey] ?? ''));
            if ($actual === '') $missing[] = $label.'未确认';
            elseif (!str_contains(mb_strtolower($actual), mb_strtolower($expected))) $mismatches[] = $label.'不包含“'.$expected.'”';
        }

        $mismatches = array_values(array_unique($mismatches));
        $missing = array_values(array_unique($missing));
        if ($mismatches) return ['match_level' => 'incompatible', 'match_label' => '不适配', 'conflict_reasons' => $mismatches, 'requires_approval' => true];
        if ($missing) return ['match_level' => 'needs_approval', 'match_label' => '需要审批', 'conflict_reasons' => $missing, 'requires_approval' => true];
        $candidateRules = array_diff_key($rules, array_flip(['availability', 'allow_with_glass', 'allow_with_honeycomb']));
        if (!$candidateRules) {
            return ['match_level' => 'conditional', 'match_label' => '条件适配', 'conflict_reasons' => ['物料类别匹配，尚未填写快速规格范围'], 'requires_approval' => false];
        }
        return ['match_level' => 'exact', 'match_label' => '完全适配', 'conflict_reasons' => [], 'requires_approval' => false];
    }

    private function comparePower(array $power, array $rule): array
    {
        $code = (string) $power['material_code'];
        $reasons = [];
        if ($rule['installation_type'] !== 'unknown' && $power['installation_type'] !== $rule['installation_type']) $reasons[] = '安装方式不匹配';
        if ($rule['output_type'] !== 'unknown' && $power['output_type'] !== $rule['output_type']) $reasons[] = '输出类型不匹配';
        $lampPowerMax = $rule['lamp_power_max_w'] ?? $rule['lamp_power_w'] ?? null;
        $lampPowerMin = $rule['lamp_power_min_w'] ?? null;
        if ($lampPowerMin !== null && ($power['min_output_power_w'] === null || (float) $power['min_output_power_w'] > (float) $lampPowerMin)) $reasons[] = '电源最低输出功率高于产品要求的 '. $this->number((float) $lampPowerMin).'W';
        if ($lampPowerMax !== null && ($power['max_output_power_w'] === null || (float) $power['max_output_power_w'] < (float) $lampPowerMax)) $reasons[] = '电源最高功率低于产品要求的 '. $this->number((float) $lampPowerMax).'W';
        if (!$this->currentRequirementMatches($power, $rule)) {
            $reasons[] = '输出电流高于芯片允许值或范围不相交：产品要求 '.$this->currentRequirementText($rule).'，电源可选 '.$this->currentOptionsText($power);
        }
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

    private function currentRequirementMatches(array $power, array $rule): bool
    {
        $needMin = $rule['output_current_min_ma'] ?? null;
        $needMax = $rule['output_current_max_ma'] ?? null;
        if ($needMin === null && $needMax === null) return true;
        if ($needMin === null) $needMin = $needMax;
        if ($needMax === null) $needMax = $needMin;
        $needMin = (float) $needMin;
        $needMax = (float) $needMax;
        $options = $this->discreteCurrentOptions($power);
        if ($options) {
            foreach ($options as $option) {
                if ($option >= $needMin && $option <= $needMax) return true;
            }
            return false;
        }
        return $this->rangeOverlaps($needMin, $needMax, $power['output_current_min_ma'] ?? null, $power['output_current_max_ma'] ?? null);
    }

    private function discreteCurrentOptions(array $power): array
    {
        $raw = $power['current_options_ma'] ?? $power['current_options'] ?? '';
        $values = [];
        if (is_array($raw)) {
            foreach ($raw as $row) {
                $value = is_array($row) ? ($row['current_ma'] ?? null) : $row;
                if (is_numeric($value) && (float) $value > 0) $values[] = (float) $value;
            }
        } else {
            foreach (preg_split('/[,，\\/\\s]+/u', (string) $raw) ?: [] as $value) {
                if (is_numeric($value) && (float) $value > 0) $values[] = (float) $value;
            }
        }
        $values = array_values(array_unique(array_map(static fn(float $value): string => (string) $value, $values)));
        $values = array_map('floatval', $values);
        sort($values, SORT_NUMERIC);
        return $values;
    }

    private function currentRequirementText(array $rule): string
    {
        $min = $rule['output_current_min_ma'] ?? null;
        $max = $rule['output_current_max_ma'] ?? null;
        if ($min === null && $max === null) return '未设置';
        if ($min === null || $max === null || (float) $min === (float) $max) return $this->number((float) ($max ?? $min)).'mA';
        return $this->number((float) $min).'-'.$this->number((float) $max).'mA';
    }

    private function currentOptionsText(array $power): string
    {
        $options = $this->discreteCurrentOptions($power);
        if ($options) return implode('/', array_map(fn(float $value): string => $this->number($value), $options)).'mA';
        $min = $power['output_current_min_ma'] ?? null;
        $max = $power['output_current_max_ma'] ?? null;
        if ($min === null && $max === null) return '未确认';
        if ($min === null || $max === null || (float) $min === (float) $max) return $this->number((float) ($max ?? $min)).'mA';
        return $this->number((float) $min).'-'.$this->number((float) $max).'mA';
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
        $stmt = $this->db->prepare("SELECT m.id,m.material_code,p.*,(SELECT GROUP_CONCAT(d.mode ORDER BY d.mode) FROM mc_power_supply_dimming_modes d WHERE d.material_id=m.id) dimming_modes,(SELECT GROUP_CONCAT(o.current_ma ORDER BY o.current_ma) FROM mc_power_supply_current_options o WHERE o.material_id=m.id) current_options_ma FROM mc_materials m JOIN mc_power_supply_specs p ON p.material_id=m.id WHERE m.id IN($marks)");
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
        $rules = $group['quick_rules'] ?? (json_decode((string) ($group['rule_json'] ?? '{}'), true) ?: []);
        if (($rules['availability'] ?? 'allowed') !== 'allowed') return 'forbidden';
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
        if ($category === 'chip') {
            return implode(' · ', array_filter([
                $row['chip_package_type'] ?? null,
                $this->numberRange($row['chip_min_power_w'] ?? null, $row['chip_max_power_w'] ?? null, 'W'),
                $this->numberRange($row['chip_current_min_ma'] ?? null, $row['chip_current_max_ma'] ?? null, 'mA'),
                $row['chip_voltage_v'] !== null ? $this->number((float) $row['chip_voltage_v']).'V' : null,
                $row['chip_les_text'] ?? null,
            ]));
        }
        if ($category === 'optical') {
            $angleText = trim((string) ($row['optical_beam_angle_options'] ?? ''));
            if ($angleText !== '') {
                $angleText = '角度 '.$angleText;
            } else {
                $angleRange = $this->numberRange($row['optical_beam_angle_min'] ?? null, $row['optical_beam_angle_max'] ?? null, '°');
                $angleText = $angleRange ? '角度 '.$angleRange : null;
            }
            return implode(' · ', array_filter([
                $row['optical_type'] ?? null,
                $row['optical_diameter_mm'] !== null ? 'Φ'.$this->number((float) $row['optical_diameter_mm']).'mm' : null,
                $row['optical_height_mm'] !== null ? '高/厚 '.$this->number((float) $row['optical_height_mm']).'mm' : null,
                $angleText,
                $row['optical_compatible_les'] ?? null,
                $row['optical_mounting_structure'] ?? null,
            ]));
        }
        if ($category === 'accessory') {
            return implode(' · ', array_filter([
                $row['accessory_type'] ?? null,
                $row['accessory_diameter_mm'] !== null ? 'Φ'.$this->number((float) $row['accessory_diameter_mm']).'mm' : null,
                $row['accessory_thickness_mm'] !== null ? '厚 '.$this->number((float) $row['accessory_thickness_mm']).'mm' : null,
                $row['accessory_interface_type'] ?? null,
                $row['accessory_installation_position'] ?? null,
            ]));
        }
        if ($category === 'connector') {
            return implode(' · ', array_filter([
                $row['connector_interface_type'] ?? null,
                $row['connector_installation_type'] ?? null,
                $row['connector_load_kg'] !== null ? '承重 '.$this->number((float) $row['connector_load_kg']).'kg' : null,
            ]));
        }
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
            'not_in' => is_array($expected) && !in_array($actual, $expected, true),
            'contains' => str_contains((string) $actual, (string) $expected),
            'not_contains' => !str_contains((string) $actual, (string) $expected),
            'has_value' => !($actual === null || $actual === '' || (is_array($actual) && !$actual)),
            'no_value' => $actual === null || $actual === '' || (is_array($actual) && !$actual),
            default => false,
        };
    }

    private function availabilityLabel(string $availability): string
    {
        return match ($availability) {
            'forbidden' => '不允许使用',
            'not_applicable' => '不适用',
            'not_offered' => '暂不提供',
            'later' => '稍后处理',
            default => '允许使用',
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

    private function configTemplateRow(int $id): ?array
    {
        if (!$id || !$this->tableExists('mc_config_templates')) return null;
        $stmt = $this->db->prepare("SELECT t.*,
                (SELECT COUNT(*) FROM mc_config_template_groups g WHERE g.template_id=t.id AND g.is_enabled=1) group_count,
                (SELECT COUNT(*) FROM mc_adaptation_groups g WHERE JSON_EXTRACT(g.rule_json,'$.template_id')=t.id) referenced_product_count
            FROM mc_config_templates t WHERE t.id=? LIMIT 1");
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        if ($row) {
            $row['id'] = (int) $row['id'];
            $row['group_count'] = (int) ($row['group_count'] ?? 0);
            $row['referenced_product_count'] = (int) ($row['referenced_product_count'] ?? 0);
            $row['settings'] = json_decode((string) ($row['settings_json'] ?? '{}'), true) ?: [];
        }
        return $row ?: null;
    }

    private function configGroupDefinitionRow(int $id): ?array
    {
        if (!$id || !$this->tableExists('mc_config_group_definitions')) return null;
        $stmt = $this->db->prepare('SELECT * FROM mc_config_group_definitions WHERE id=? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        if ($row) $row['id'] = (int) $row['id'];
        return $row ?: null;
    }

    private function configTemplateGroupRow(int $id): ?array
    {
        if (!$id || !$this->tableExists('mc_config_template_groups')) return null;
        $stmt = $this->db->prepare('SELECT * FROM mc_config_template_groups WHERE id=? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        if ($row) $row['id'] = (int) $row['id'];
        return $row ?: null;
    }

    private function configGroupOptions(int $definitionId): array
    {
        if (!$this->tableExists('mc_config_group_options')) return [];
        $stmt = $this->db->prepare('SELECT * FROM mc_config_group_options WHERE group_definition_id=? ORDER BY sort_order,id');
        $stmt->execute([$definitionId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$row) {
            $row['id'] = (int) $row['id'];
            $row['settings'] = json_decode((string) ($row['settings_json'] ?? '{}'), true) ?: [];
        }
        unset($row);
        return $rows;
    }

    private function configGroupConditions(int $templateGroupId): array
    {
        if (!$this->tableExists('mc_config_group_conditions')) return [];
        $stmt = $this->db->prepare('SELECT * FROM mc_config_group_conditions WHERE template_group_id=? ORDER BY sort_order,id');
        $stmt->execute([$templateGroupId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$row) {
            $row['id'] = (int) $row['id'];
            $row['condition'] = json_decode((string) ($row['condition_json'] ?? '{}'), true) ?: [];
        }
        unset($row);
        return $rows;
    }

    private function configGroupMaterialFilters(int $templateGroupId): array
    {
        if (!$this->tableExists('mc_config_group_material_filters')) return [];
        $stmt = $this->db->prepare('SELECT * FROM mc_config_group_material_filters WHERE template_group_id=? ORDER BY id');
        $stmt->execute([$templateGroupId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$row) {
            $row['id'] = (int) $row['id'];
            $row['filter'] = json_decode((string) ($row['filter_json'] ?? '{}'), true) ?: [];
        }
        unset($row);
        return $rows;
    }

    private function resolveConfigTemplateForProduct(array $product): ?array
    {
        $templates = $this->configTemplates();
        if (!$templates) return null;
        $productId = (int) ($product['id'] ?? 0);
        $series = (string) ($product['series_name'] ?? '');
        $type = (string) ($product['product_type'] ?? '');
        $normalized = $type.' '.$series.' '.($product['product_name'] ?? '');
        foreach ($templates as $template) {
            if (($template['status'] ?? '') === 'disabled' || !(int) ($template['is_enabled'] ?? 0)) continue;
            if (($template['scope_type'] ?? '') === 'product' && (int) ($template['product_id'] ?? 0) === $productId) return $template;
        }
        foreach ($templates as $template) {
            if (($template['status'] ?? '') === 'disabled' || !(int) ($template['is_enabled'] ?? 0)) continue;
            if (($template['scope_type'] ?? '') === 'series' && (string) ($template['product_series'] ?? '') !== '' && (string) $template['product_series'] === $series) return $template;
        }
        foreach ($templates as $template) {
            if (($template['status'] ?? '') === 'disabled' || !(int) ($template['is_enabled'] ?? 0) || ($template['scope_type'] ?? '') !== 'category') continue;
            $templateType = (string) ($template['product_type'] ?? '');
            if ($templateType !== '' && ($templateType === $type || str_contains($type, $templateType) || str_contains($templateType, $type))) return $template;
            if (preg_match('/导轨|轨道|track/i', $normalized) && str_contains($template['template_code'], 'track')) return $template;
            if (preg_match('/嵌入|筒灯|射灯|downlight|recess/i', $normalized) && str_contains($template['template_code'], 'recessed')) return $template;
            if (preg_match('/磁吸|magnetic/i', $normalized) && str_contains($template['template_code'], 'magnetic')) return $template;
        }
        foreach ($templates as $template) {
            if (($template['scope_type'] ?? '') === 'system' && ($template['status'] ?? '') !== 'disabled' && (int) ($template['is_enabled'] ?? 0)) return $template;
        }
        return $templates[0] ?? null;
    }

    private function saveConfigMaterialFilterFromPayload(int $templateGroupId, array $data, int $userId): void
    {
        if (!$this->tableExists('mc_config_group_material_filters')) return;
        $keys = ['material_category_code','material_subcategory_code','brand_limit','model_limit','installation_type','io_type','ip_rating'];
        $hasFilter = false;
        foreach ($keys as $key) if (trim((string) ($data[$key] ?? '')) !== '') $hasFilter = true;
        if (!$hasFilter && !isset($data['allow_pending']) && !isset($data['allow_alternative'])) return;
        $this->db->prepare('DELETE FROM mc_config_group_material_filters WHERE template_group_id=?')->execute([$templateGroupId]);
        $stmt = $this->db->prepare('INSERT INTO mc_config_group_material_filters(template_group_id,material_category_code,material_subcategory_code,brand_limit,model_limit,installation_type,power_min,power_max,io_type,ip_rating,formal_status,approved_required,allow_pending,allow_alternative,filter_json,created_by,updated_by,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW(),NOW())');
        $stmt->execute([
            $templateGroupId,
            trim((string) ($data['material_category_code'] ?? '')) ?: null,
            trim((string) ($data['material_subcategory_code'] ?? '')) ?: null,
            trim((string) ($data['brand_limit'] ?? '')) ?: null,
            trim((string) ($data['model_limit'] ?? '')) ?: null,
            trim((string) ($data['installation_type'] ?? '')) ?: null,
            ($data['power_min'] ?? '') === '' ? null : (float) $data['power_min'],
            ($data['power_max'] ?? '') === '' ? null : (float) $data['power_max'],
            trim((string) ($data['io_type'] ?? '')) ?: null,
            trim((string) ($data['ip_rating'] ?? '')) ?: null,
            trim((string) ($data['formal_status'] ?? 'official')) ?: 'official',
            array_key_exists('approved_required', $data) ? (empty($data['approved_required']) ? 0 : 1) : 1,
            empty($data['allow_pending']) ? 0 : 1,
            array_key_exists('allow_alternative', $data) ? (empty($data['allow_alternative']) ? 0 : 1) : 1,
            json_encode(['source' => 'template_group_form'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            $userId,
            $userId,
        ]);
    }

    private function configTemplateLog(int $templateId, int $productId, string $action, mixed $before, mixed $after, int $userId): void
    {
        if (!$this->tableExists('mc_config_template_logs')) return;
        $stmt = $this->db->prepare('INSERT INTO mc_config_template_logs(template_id,product_id,action,before_json,after_json,actor_id,created_at) VALUES(?,?,?,?,?,?,NOW())');
        $stmt->execute([
            $templateId ?: null,
            $productId ?: null,
            $action,
            $before === null ? null : json_encode($before, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            $after === null ? null : json_encode($after, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            $userId,
        ]);
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

    private function numberRange(mixed $min, mixed $max, string $unit): ?string
    {
        $hasMin = $min !== null && $min !== '';
        $hasMax = $max !== null && $max !== '';
        if (!$hasMin && !$hasMax) return null;
        if ($hasMin && $hasMax && abs((float) $min - (float) $max) < 0.0001) {
            return $this->number((float) $min).$unit;
        }
        if ($hasMin && $hasMax) {
            return $this->number((float) $min).'–'.$this->number((float) $max).$unit;
        }
        return $this->number((float) ($hasMin ? $min : $max)).$unit;
    }

    private function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }

    private function log(int $productId, string $action, array $detail, int $userId): void
    {
        $stmt = $this->db->prepare('INSERT INTO mc_adaptation_logs(product_id,action,detail_json,actor_id,created_at) VALUES(?,?,?,?,NOW())');
        $stmt->execute([$productId, $action, json_encode($detail, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $userId]);
    }
}
