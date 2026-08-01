<?php
declare(strict_types=1);

function pa2_db(): PDO
{
    return db();
}

function pa2_table_exists(string $table): bool
{
    try {
        return db_table_exists($table);
    } catch (Throwable) {
        return false;
    }
}

function pa2_required_tables(): array
{
    return [
        'mc_pa2_product_categories',
        'mc_pa2_product_category_mappings',
        'mc_pa2_group_definitions',
        'mc_pa2_group_option_definitions',
    ];
}

function pa2_foundation_ready(): bool
{
    foreach (pa2_required_tables() as $table) {
        if (!pa2_table_exists($table)) return false;
    }
    return true;
}

function pa2_current_user_id(): ?int
{
    $user = function_exists('mc_current_user') ? mc_current_user() : (function_exists('current_user') ? current_user() : null);
    if (!$user || !is_array($user)) return null;
    $id = (int)($user['id'] ?? 0);
    return $id > 0 ? $id : null;
}

function pa2_can_any(array $permissions): bool
{
    foreach ($permissions as $permission) {
        if (function_exists('has_permission') && has_permission($permission)) return true;
    }
    return false;
}

function pa2_require_any(array $permissions, string $message = '权限不足'): void
{
    if (pa2_can_any($permissions)) return;
    pa2_json_response([], $message, false, ['PERMISSION_DENIED'], 403);
    exit;
}

function pa2_slug(string $value, string $prefix = 'custom'): string
{
    $value = trim($value);
    $slug = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '_', $value) ?? '');
    $slug = trim($slug, '_');
    if ($slug === '') $slug = $prefix . '_' . substr(sha1($value . microtime(true)), 0, 8);
    return substr($slug, 0, 80);
}

function pa2_json_decode_array($value): array
{
    if (is_array($value)) return $value;
    $decoded = json_decode((string)($value ?? ''), true);
    return is_array($decoded) ? $decoded : [];
}

function pa2_json_encode($value): string
{
    return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function pa2_log(string $action, string $objectType, ?int $objectId, array $before = [], array $after = []): void
{
    if (!pa2_table_exists('mc_operation_logs')) return;
    try {
        $stmt = pa2_db()->prepare(
            "INSERT INTO mc_operation_logs(module,object_type,object_id,action,old_value_json,new_value_json,actor_id,result,created_at)
             VALUES('adaptation_v2',?,?,?,?,?,?, 'success', NOW())"
        );
        $stmt->execute([
            $objectType,
            $objectId,
            $action,
            $before ? pa2_json_encode($before) : null,
            $after ? pa2_json_encode($after) : null,
            pa2_current_user_id(),
        ]);
    } catch (Throwable) {
        // 日志不能阻断业务保存；真实错误由 API 响应和 PHP 日志承担。
    }
}

function pa2_fetch_categories(): array
{
    if (!pa2_foundation_ready()) return [];
    $rows = pa2_db()->query(
        "SELECT c.*, p.category_name AS parent_name,
            (SELECT COUNT(*) FROM mc_pa2_product_category_mappings m WHERE m.category_id=c.id) AS mapped_product_count
         FROM mc_pa2_product_categories c
         LEFT JOIN mc_pa2_product_categories p ON p.id=c.parent_id
         ORDER BY COALESCE(p.sort_order,c.sort_order), c.parent_id IS NOT NULL, c.sort_order, c.id"
    )->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$row) {
        $row['id'] = (int)$row['id'];
        $row['parent_id'] = $row['parent_id'] === null ? null : (int)$row['parent_id'];
        $row['is_enabled'] = (int)$row['is_enabled'];
        $row['mapped_product_count'] = (int)$row['mapped_product_count'];
    }
    return $rows;
}

function pa2_fetch_groups(bool $withOptions = true): array
{
    if (!pa2_foundation_ready()) return [];
    $rows = pa2_db()->query(
        "SELECT * FROM mc_pa2_group_definitions ORDER BY is_system DESC, sort_order, id"
    )->fetchAll(PDO::FETCH_ASSOC);
    $optionsByGroup = [];
    if ($withOptions && $rows) {
        $opts = pa2_db()->query(
            "SELECT * FROM mc_pa2_group_option_definitions ORDER BY group_definition_id, sort_order, id"
        )->fetchAll(PDO::FETCH_ASSOC);
        foreach ($opts as $option) {
            $gid = (int)$option['group_definition_id'];
            $option['id'] = (int)$option['id'];
            $option['is_default'] = (int)$option['is_default'];
            $option['is_enabled'] = (int)$option['is_enabled'];
            $option['settings'] = pa2_json_decode_array($option['settings_json'] ?? '');
            $optionsByGroup[$gid][] = $option;
        }
    }
    $behaviorsByGroup = [];
    if (pa2_phase4_tables_ready()) {
        $behaviorRows = pa2_db()->query(
            "SELECT * FROM mc_pa2_group_behavior_settings ORDER BY group_code"
        )->fetchAll(PDO::FETCH_ASSOC);
        foreach ($behaviorRows as $behavior) {
            $gid = (int)$behavior['group_definition_id'];
            foreach (['id','group_definition_id','is_required_default','min_select_default','max_select_default'] as $key) {
                $behavior[$key] = (int)$behavior[$key];
            }
            foreach (['material_filter_json','attribute_source_json','default_rule_json','visibility_condition_json','validation_json'] as $key) {
                $behavior[str_replace('_json', '', $key)] = pa2_json_decode_array($behavior[$key] ?? '');
            }
            $behaviorsByGroup[$gid] = $behavior;
        }
    }
    foreach ($rows as &$row) {
        $row['id'] = (int)$row['id'];
        $row['is_system'] = (int)$row['is_system'];
        $row['is_enabled'] = (int)$row['is_enabled'];
        $row['options'] = $optionsByGroup[(int)$row['id']] ?? [];
        $row['behavior'] = $behaviorsByGroup[(int)$row['id']] ?? null;
    }
    return $rows;
}

function pa2_phase4_tables_ready(): bool
{
    foreach (['mc_pa2_group_behavior_settings','mc_pa2_rule_definitions'] as $table) {
        if (!pa2_table_exists($table)) return false;
    }
    return true;
}

function pa2_engine_tables_ready(): bool
{
    foreach (['mc_pa2_adaptation_result_cache','mc_pa2_adaptation_conflicts','mc_pa2_adaptation_recalc_jobs'] as $table) {
        if (!pa2_table_exists($table)) return false;
    }
    return true;
}

function pa2_phase7_tables_ready(): bool
{
    foreach (['mc_pa2_product_version_events','mc_pa2_product_version_snapshots','mc_pa2_product_version_diffs'] as $table) {
        if (!pa2_table_exists($table)) return false;
    }
    return true;
}

function pa2_phase8_tables_ready(): bool
{
    foreach (['mc_pa2_config_packages','mc_pa2_config_package_versions','mc_pa2_config_package_groups','mc_pa2_config_package_options'] as $table) {
        if (!pa2_table_exists($table)) return false;
    }
    return true;
}

function pa2_phase9_tables_ready(): bool
{
    foreach (['mc_pa2_channel_clients','mc_pa2_channel_package_snapshots','mc_pa2_channel_cache','mc_pa2_channel_access_logs','mc_pa2_channel_order_snapshots'] as $table) {
        if (!pa2_table_exists($table)) return false;
    }
    return true;
}

function pa2_foundation_summary(): array
{
    $summary = [
        'ready' => pa2_foundation_ready(),
        'tables' => [],
        'category_count' => 0,
        'group_count' => 0,
        'option_count' => 0,
        'mapping_count' => 0,
        'template_count' => 0,
        'template_version_count' => 0,
        'group_behavior_count' => 0,
        'rule_count' => 0,
        'rule_cycle_count' => 0,
        'product_config_count' => 0,
        'draft_config_count' => 0,
        'adaptation_result_count' => 0,
        'open_conflict_count' => 0,
        'published_version_count' => 0,
        'approval_event_count' => 0,
        'package_count' => 0,
        'package_version_count' => 0,
        'published_package_count' => 0,
        'channel_client_count' => 0,
        'channel_cache_count' => 0,
        'channel_snapshot_count' => 0,
        'channel_access_log_count' => 0,
        'channel_order_snapshot_count' => 0,
    ];
    foreach (pa2_required_tables() as $table) {
        $exists = pa2_table_exists($table);
        $count = null;
        if ($exists) {
            try {
                $count = (int)pa2_db()->query("SELECT COUNT(*) FROM `$table`")->fetchColumn();
            } catch (Throwable) {
                $count = null;
            }
        }
        $summary['tables'][] = ['table' => $table, 'exists' => $exists, 'rows' => $count];
    }
    if ($summary['ready']) {
        $summary['category_count'] = (int)pa2_db()->query('SELECT COUNT(*) FROM mc_pa2_product_categories')->fetchColumn();
        $summary['group_count'] = (int)pa2_db()->query('SELECT COUNT(*) FROM mc_pa2_group_definitions')->fetchColumn();
        $summary['option_count'] = (int)pa2_db()->query('SELECT COUNT(*) FROM mc_pa2_group_option_definitions')->fetchColumn();
        $summary['mapping_count'] = (int)pa2_db()->query('SELECT COUNT(*) FROM mc_pa2_product_category_mappings')->fetchColumn();
        if (pa2_template_tables_ready()) {
            $summary['template_count'] = (int)pa2_db()->query('SELECT COUNT(*) FROM mc_pa2_templates')->fetchColumn();
            $summary['template_version_count'] = (int)pa2_db()->query('SELECT COUNT(*) FROM mc_pa2_template_versions')->fetchColumn();
        }
        if (pa2_phase4_tables_ready()) {
            $summary['group_behavior_count'] = (int)pa2_db()->query('SELECT COUNT(*) FROM mc_pa2_group_behavior_settings')->fetchColumn();
            $summary['rule_count'] = (int)pa2_db()->query('SELECT COUNT(*) FROM mc_pa2_rule_definitions')->fetchColumn();
            $summary['rule_cycle_count'] = count(pa2_detect_rule_cycles(pa2_fetch_rules(false))['cycles']);
        }
        if (pa2_workspace_tables_ready()) {
            $summary['product_config_count'] = (int)pa2_db()->query('SELECT COUNT(*) FROM mc_pa2_product_configs')->fetchColumn();
            $summary['draft_config_count'] = (int)pa2_db()->query("SELECT COUNT(*) FROM mc_pa2_product_configs WHERE status='draft'")->fetchColumn();
        }
        if (pa2_engine_tables_ready()) {
            $summary['adaptation_result_count'] = (int)pa2_db()->query('SELECT COUNT(*) FROM mc_pa2_adaptation_result_cache')->fetchColumn();
            $summary['open_conflict_count'] = (int)pa2_db()->query('SELECT COUNT(*) FROM mc_pa2_adaptation_conflicts WHERE is_resolved=0')->fetchColumn();
        }
        if (pa2_phase7_tables_ready()) {
            $summary['published_version_count'] = (int)pa2_db()->query("SELECT COUNT(*) FROM mc_pa2_product_config_versions WHERE status='published'")->fetchColumn();
            $summary['approval_event_count'] = (int)pa2_db()->query('SELECT COUNT(*) FROM mc_pa2_product_version_events')->fetchColumn();
        }
        if (pa2_phase8_tables_ready()) {
            $summary['package_count'] = (int)pa2_db()->query('SELECT COUNT(*) FROM mc_pa2_config_packages')->fetchColumn();
            $summary['package_version_count'] = (int)pa2_db()->query('SELECT COUNT(*) FROM mc_pa2_config_package_versions')->fetchColumn();
            $summary['published_package_count'] = (int)pa2_db()->query("SELECT COUNT(*) FROM mc_pa2_config_packages WHERE status='published'")->fetchColumn();
        }
        if (pa2_phase9_tables_ready()) {
            $summary['channel_client_count'] = (int)pa2_db()->query('SELECT COUNT(*) FROM mc_pa2_channel_clients')->fetchColumn();
            $summary['channel_cache_count'] = (int)pa2_db()->query('SELECT COUNT(*) FROM mc_pa2_channel_cache')->fetchColumn();
            $summary['channel_snapshot_count'] = (int)pa2_db()->query('SELECT COUNT(*) FROM mc_pa2_channel_package_snapshots')->fetchColumn();
            $summary['channel_access_log_count'] = (int)pa2_db()->query('SELECT COUNT(*) FROM mc_pa2_channel_access_logs')->fetchColumn();
            $summary['channel_order_snapshot_count'] = (int)pa2_db()->query('SELECT COUNT(*) FROM mc_pa2_channel_order_snapshots')->fetchColumn();
        }
    }
    return $summary;
}

function pa2_upsert_category(array $input): array
{
    pa2_require_any(['adaptation_v2.manage_category', 'material_center.adaptation.manage'], '没有维护产品分类的权限。');
    if (!pa2_foundation_ready()) throw new RuntimeException('V2 基础表尚未迁移。');
    $id = (int)($input['id'] ?? 0);
    $name = trim((string)($input['category_name'] ?? ''));
    if ($name === '') throw new RuntimeException('分类名称不能为空。');
    $code = trim((string)($input['category_code'] ?? ''));
    if ($code === '') $code = pa2_slug($name, 'category');
    $parentId = (int)($input['parent_id'] ?? 0);
    $parentId = $parentId > 0 ? $parentId : null;
    $description = trim((string)($input['description'] ?? ''));
    $sort = (int)($input['sort_order'] ?? 100);
    $enabled = isset($input['is_enabled']) ? (int)$input['is_enabled'] : 1;
    $userId = pa2_current_user_id();
    if ($id > 0) {
        $beforeStmt = pa2_db()->prepare('SELECT * FROM mc_pa2_product_categories WHERE id=? LIMIT 1');
        $beforeStmt->execute([$id]);
        $before = $beforeStmt->fetch(PDO::FETCH_ASSOC);
        if (!$before) throw new RuntimeException('分类不存在。');
        if ($parentId === $id) throw new RuntimeException('父分类不能选择自己。');
        $stmt = pa2_db()->prepare(
            'UPDATE mc_pa2_product_categories
             SET category_code=?, category_name=?, parent_id=?, description=?, sort_order=?, is_enabled=?, updated_by=?, updated_at=NOW()
             WHERE id=?'
        );
        $stmt->execute([$code, $name, $parentId, $description, $sort, $enabled, $userId, $id]);
        $afterStmt = pa2_db()->prepare('SELECT * FROM mc_pa2_product_categories WHERE id=? LIMIT 1');
        $afterStmt->execute([$id]);
        $after = $afterStmt->fetch(PDO::FETCH_ASSOC) ?: [];
        pa2_log('category_update', 'product_category', $id, $before ?: [], $after);
    } else {
        $stmt = pa2_db()->prepare(
            'INSERT INTO mc_pa2_product_categories(category_code,category_name,parent_id,description,sort_order,is_enabled,created_by,updated_by,created_at,updated_at)
             VALUES(?,?,?,?,?,?,?,?,NOW(),NOW())'
        );
        $stmt->execute([$code, $name, $parentId, $description, $sort, $enabled, $userId, $userId]);
        $id = (int)pa2_db()->lastInsertId();
        pa2_log('category_create', 'product_category', $id, [], ['category_code' => $code, 'category_name' => $name]);
    }
    $stmt = pa2_db()->prepare('SELECT * FROM mc_pa2_product_categories WHERE id=? LIMIT 1');
    $stmt->execute([$id]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
}

function pa2_upsert_group(array $input): array
{
    pa2_require_any(['adaptation_v2.manage_group_definition', 'material_center.adaptation.manage'], '没有维护配置组定义的权限。');
    if (!pa2_foundation_ready()) throw new RuntimeException('V2 基础表尚未迁移。');
    $id = (int)($input['id'] ?? 0);
    $name = trim((string)($input['group_name'] ?? ''));
    if ($name === '') throw new RuntimeException('配置组名称不能为空。');
    $code = trim((string)($input['group_code'] ?? ''));
    if ($code === '') $code = pa2_slug($name, 'group');
    $type = trim((string)($input['group_type'] ?? 'material_select'));
    $allowedTypes = ['material_select','enum_select','hybrid_select','number_input','text_input','boolean'];
    if (!in_array($type, $allowedTypes, true)) $type = 'material_select';
    $icon = trim((string)($input['icon'] ?? ''));
    $description = trim((string)($input['description'] ?? ''));
    $sort = (int)($input['sort_order'] ?? 100);
    $enabled = isset($input['is_enabled']) ? (int)$input['is_enabled'] : 1;
    $userId = pa2_current_user_id();
    if ($id > 0) {
        $beforeStmt = pa2_db()->prepare('SELECT * FROM mc_pa2_group_definitions WHERE id=? LIMIT 1');
        $beforeStmt->execute([$id]);
        $before = $beforeStmt->fetch(PDO::FETCH_ASSOC);
        if (!$before) throw new RuntimeException('配置组不存在。');
        $stmt = pa2_db()->prepare(
            'UPDATE mc_pa2_group_definitions
             SET group_code=?, group_name=?, group_type=?, icon=?, description=?, sort_order=?, is_enabled=?, updated_by=?, updated_at=NOW()
             WHERE id=?'
        );
        $stmt->execute([$code, $name, $type, $icon, $description, $sort, $enabled, $userId, $id]);
        $afterStmt = pa2_db()->prepare('SELECT * FROM mc_pa2_group_definitions WHERE id=? LIMIT 1');
        $afterStmt->execute([$id]);
        $after = $afterStmt->fetch(PDO::FETCH_ASSOC) ?: [];
        pa2_log('group_update', 'group_definition', $id, $before ?: [], $after);
    } else {
        $stmt = pa2_db()->prepare(
            'INSERT INTO mc_pa2_group_definitions(group_code,group_name,group_type,icon,description,is_system,is_enabled,sort_order,created_by,updated_by,created_at,updated_at)
             VALUES(?,?,?,?,?,0,?,?,?, ?,NOW(),NOW())'
        );
        $stmt->execute([$code, $name, $type, $icon, $description, $enabled, $sort, $userId, $userId]);
        $id = (int)pa2_db()->lastInsertId();
        pa2_log('group_create', 'group_definition', $id, [], ['group_code' => $code, 'group_name' => $name]);
    }
    $stmt = pa2_db()->prepare('SELECT * FROM mc_pa2_group_definitions WHERE id=? LIMIT 1');
    $stmt->execute([$id]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
}

function pa2_add_group_option(array $input): array
{
    pa2_require_any(['adaptation_v2.manage_group_definition', 'material_center.adaptation.manage'], '没有维护配置组选项的权限。');
    if (!pa2_foundation_ready()) throw new RuntimeException('V2 基础表尚未迁移。');
    $groupId = (int)($input['group_definition_id'] ?? 0);
    $name = trim((string)($input['option_name'] ?? ''));
    if ($groupId <= 0 || $name === '') throw new RuntimeException('配置组和选项名称不能为空。');
    $code = trim((string)($input['option_code'] ?? ''));
    if ($code === '') $code = pa2_slug($name, 'option');
    $stmt = pa2_db()->prepare(
        'INSERT INTO mc_pa2_group_option_definitions(group_definition_id,option_code,option_name,description,is_default,is_enabled,sort_order,created_by,updated_by,created_at,updated_at)
         VALUES(?,?,?,?,?,?,?,?,?,NOW(),NOW())
         ON DUPLICATE KEY UPDATE option_name=VALUES(option_name),description=VALUES(description),is_default=VALUES(is_default),is_enabled=VALUES(is_enabled),sort_order=VALUES(sort_order),updated_by=VALUES(updated_by),updated_at=NOW()'
    );
    $stmt->execute([
        $groupId,
        $code,
        $name,
        trim((string)($input['description'] ?? '')),
        !empty($input['is_default']) ? 1 : 0,
        isset($input['is_enabled']) ? (int)$input['is_enabled'] : 1,
        (int)($input['sort_order'] ?? 100),
        pa2_current_user_id(),
        pa2_current_user_id(),
    ]);
    pa2_log('group_option_upsert', 'group_definition', $groupId, [], ['option_code' => $code, 'option_name' => $name]);
    return ['group_definition_id' => $groupId, 'option_code' => $code, 'option_name' => $name];
}

function pa2_group_type_to_selection_kind(string $groupType): string
{
    return match ($groupType) {
        'material_select' => 'material',
        'enum_select', 'boolean' => 'attribute',
        'hybrid_select' => 'hybrid',
        'number_input' => 'number',
        'text_input' => 'text',
        default => 'material',
    };
}

function pa2_upsert_group_behavior(array $input): array
{
    pa2_require_any(['adaptation_v2.manage_rule', 'adaptation_v2.manage_group_definition', 'material_center.adaptation.manage'], '没有维护配置组行为的权限。');
    if (!pa2_phase4_tables_ready()) throw new RuntimeException('V2 第 4 阶段规则表尚未迁移。');
    $groupId = (int)($input['group_definition_id'] ?? 0);
    if ($groupId <= 0) throw new RuntimeException('配置组不能为空。');
    $stmt = pa2_db()->prepare('SELECT * FROM mc_pa2_group_definitions WHERE id=? LIMIT 1');
    $stmt->execute([$groupId]);
    $group = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$group) throw new RuntimeException('配置组不存在。');
    $kind = trim((string)($input['selection_kind'] ?? ''));
    if ($kind === '') $kind = pa2_group_type_to_selection_kind((string)$group['group_type']);
    if (!in_array($kind, ['material','attribute','hybrid','number','text'], true)) $kind = 'material';
    $sourceMode = trim((string)($input['source_mode'] ?? ''));
    if ($sourceMode === '') {
        $sourceMode = in_array($kind, ['material','hybrid'], true) ? 'official_material' : ($kind === 'attribute' ? 'static_options' : 'manual_input');
    }
    $selectionMode = trim((string)($input['selection_mode_default'] ?? 'single'));
    if (!in_array($selectionMode, ['single','multiple'], true)) $selectionMode = 'single';
    $min = max(0, (int)($input['min_select_default'] ?? 0));
    $max = max($min, (int)($input['max_select_default'] ?? ($selectionMode === 'multiple' ? 99 : 1)));
    if ($selectionMode === 'single') $max = min($max, 1);
    $jsonFields = [];
    foreach (['material_filter','attribute_source','default_rule','visibility_condition','validation'] as $field) {
        $raw = trim((string)($input[$field . '_json'] ?? $input[$field] ?? ''));
        if ($raw === '') {
            $jsonFields[$field] = null;
            continue;
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) throw new RuntimeException($field . ' 必须是合法 JSON 对象。');
        $jsonFields[$field] = pa2_json_encode($decoded);
    }
    $beforeStmt = pa2_db()->prepare('SELECT * FROM mc_pa2_group_behavior_settings WHERE group_definition_id=? LIMIT 1');
    $beforeStmt->execute([$groupId]);
    $before = $beforeStmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $upsert = pa2_db()->prepare(
        'INSERT INTO mc_pa2_group_behavior_settings(group_definition_id,group_code,selection_kind,source_mode,material_category_code,material_filter_json,attribute_source_json,numeric_unit,text_format,is_required_default,selection_mode_default,min_select_default,max_select_default,default_rule_json,visibility_condition_json,validation_json,created_by,updated_by,created_at,updated_at)
         VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW(),NOW())
         ON DUPLICATE KEY UPDATE selection_kind=VALUES(selection_kind),source_mode=VALUES(source_mode),material_category_code=VALUES(material_category_code),material_filter_json=VALUES(material_filter_json),attribute_source_json=VALUES(attribute_source_json),numeric_unit=VALUES(numeric_unit),text_format=VALUES(text_format),is_required_default=VALUES(is_required_default),selection_mode_default=VALUES(selection_mode_default),min_select_default=VALUES(min_select_default),max_select_default=VALUES(max_select_default),default_rule_json=VALUES(default_rule_json),visibility_condition_json=VALUES(visibility_condition_json),validation_json=VALUES(validation_json),updated_by=VALUES(updated_by),updated_at=NOW()'
    );
    $userId = pa2_current_user_id();
    $upsert->execute([
        $groupId,
        (string)$group['group_code'],
        $kind,
        $sourceMode,
        trim((string)($input['material_category_code'] ?? '')) ?: null,
        $jsonFields['material_filter'],
        $jsonFields['attribute_source'],
        trim((string)($input['numeric_unit'] ?? '')) ?: null,
        trim((string)($input['text_format'] ?? '')) ?: null,
        !empty($input['is_required_default']) ? 1 : 0,
        $selectionMode,
        $min,
        $max,
        $jsonFields['default_rule'],
        $jsonFields['visibility_condition'],
        $jsonFields['validation'],
        $userId,
        $userId,
    ]);
    $afterStmt = pa2_db()->prepare('SELECT * FROM mc_pa2_group_behavior_settings WHERE group_definition_id=? LIMIT 1');
    $afterStmt->execute([$groupId]);
    $after = $afterStmt->fetch(PDO::FETCH_ASSOC) ?: [];
    pa2_log('group_behavior_upsert', 'group_definition', $groupId, $before, $after);
    return $after;
}

function pa2_fetch_rules(bool $withCycles = true): array
{
    if (!pa2_phase4_tables_ready()) return [];
    $rows = pa2_db()->query(
        "SELECT r.*, t.template_name, c.category_name
         FROM mc_pa2_rule_definitions r
         LEFT JOIN mc_pa2_templates t ON t.id=r.template_id
         LEFT JOIN mc_pa2_product_categories c ON c.id=r.product_category_id
         ORDER BY r.priority,r.id"
    )->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$row) {
        foreach (['id','template_id','product_category_id','priority','is_enabled'] as $key) {
            $row[$key] = $row[$key] === null ? null : (int)$row[$key];
        }
        $row['effect'] = pa2_json_decode_array($row['effect_json'] ?? '');
        $row['has_cycle'] = false;
    }
    if ($withCycles) {
        $cycleMap = [];
        foreach (pa2_detect_rule_cycles($rows)['cycles'] as $cycle) {
            foreach ($cycle as $code) $cycleMap[$code] = true;
        }
        foreach ($rows as &$row) {
            $row['has_cycle'] = isset($cycleMap[(string)$row['trigger_group_code']]) && isset($cycleMap[(string)$row['target_group_code']]);
        }
    }
    return $rows;
}

function pa2_detect_rule_cycles(array $rules): array
{
    $graph = [];
    foreach ($rules as $rule) {
        if ((int)($rule['is_enabled'] ?? 1) !== 1) continue;
        $from = trim((string)($rule['trigger_group_code'] ?? ''));
        $to = trim((string)($rule['target_group_code'] ?? ''));
        if ($from === '' || $to === '' || $from === $to) {
            if ($from !== '') $graph[$from][] = $to;
            continue;
        }
        $graph[$from][] = $to;
    }
    $visited = [];
    $stack = [];
    $cycles = [];
    $walk = function (string $node, array $path) use (&$walk, &$graph, &$visited, &$stack, &$cycles): void {
        $visited[$node] = true;
        $stack[$node] = true;
        $path[] = $node;
        foreach ($graph[$node] ?? [] as $next) {
            $next = (string)$next;
            if ($next === '') continue;
            if (!isset($visited[$next])) {
                $walk($next, $path);
            } elseif (isset($stack[$next])) {
                $pos = array_search($next, $path, true);
                $cycles[] = $pos === false ? [$node, $next] : array_slice($path, (int)$pos);
            }
        }
        unset($stack[$node]);
    };
    foreach (array_keys($graph) as $node) {
        if (!isset($visited[$node])) $walk((string)$node, []);
    }
    $unique = [];
    foreach ($cycles as $cycle) {
        $key = implode('>', $cycle);
        $unique[$key] = $cycle;
    }
    return ['cycles' => array_values($unique), 'edges' => $graph];
}

function pa2_upsert_rule(array $input): array
{
    pa2_require_any(['adaptation_v2.manage_rule', 'material_center.adaptation.manage'], '没有维护配置规则的权限。');
    if (!pa2_phase4_tables_ready()) throw new RuntimeException('V2 第 4 阶段规则表尚未迁移。');
    $id = (int)($input['id'] ?? 0);
    $name = trim((string)($input['rule_name'] ?? ''));
    if ($name === '') throw new RuntimeException('规则名称不能为空。');
    $code = trim((string)($input['rule_code'] ?? '')) ?: pa2_slug($name, 'rule');
    $trigger = trim((string)($input['trigger_group_code'] ?? ''));
    $target = trim((string)($input['target_group_code'] ?? ''));
    if ($trigger === '' || $target === '') throw new RuntimeException('触发配置组和目标配置组不能为空。');
    $operator = trim((string)($input['trigger_operator'] ?? 'eq'));
    if (!in_array($operator, ['eq','neq','in','not_in','exists','empty'], true)) $operator = 'eq';
    $action = trim((string)($input['effect_action'] ?? 'show'));
    if (!in_array($action, ['show','hide','require','optional','material_filter','set_default','limit_options'], true)) $action = 'show';
    $effectRaw = trim((string)($input['effect_json'] ?? ''));
    $effectJson = null;
    if ($effectRaw !== '') {
        $decoded = json_decode($effectRaw, true);
        if (!is_array($decoded)) throw new RuntimeException('规则效果必须是合法 JSON 对象。');
        $effectJson = pa2_json_encode($decoded);
    }
    $templateId = (int)($input['template_id'] ?? 0) ?: null;
    $categoryId = (int)($input['product_category_id'] ?? 0) ?: null;
    $scope = $templateId ? 'template' : ($categoryId ? 'category' : 'global');
    $userId = pa2_current_user_id();
    $before = [];
    if ($id > 0) {
        $beforeStmt = pa2_db()->prepare('SELECT * FROM mc_pa2_rule_definitions WHERE id=? LIMIT 1');
        $beforeStmt->execute([$id]);
        $before = $beforeStmt->fetch(PDO::FETCH_ASSOC) ?: [];
        if (!$before) throw new RuntimeException('规则不存在。');
        $stmt = pa2_db()->prepare(
            'UPDATE mc_pa2_rule_definitions SET rule_code=?,rule_name=?,rule_scope=?,template_id=?,product_category_id=?,trigger_group_code=?,trigger_operator=?,trigger_value=?,target_group_code=?,effect_action=?,effect_json=?,priority=?,is_enabled=?,description=?,updated_by=?,updated_at=NOW() WHERE id=?'
        );
        $stmt->execute([$code,$name,$scope,$templateId,$categoryId,$trigger,$operator,trim((string)($input['trigger_value'] ?? '')),$target,$action,$effectJson,(int)($input['priority'] ?? 100),isset($input['is_enabled']) ? (int)$input['is_enabled'] : 1,trim((string)($input['description'] ?? '')),$userId,$id]);
    } else {
        $stmt = pa2_db()->prepare(
            'INSERT INTO mc_pa2_rule_definitions(rule_code,rule_name,rule_scope,template_id,product_category_id,trigger_group_code,trigger_operator,trigger_value,target_group_code,effect_action,effect_json,priority,is_enabled,description,created_by,updated_by,created_at,updated_at)
             VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW(),NOW())
             ON DUPLICATE KEY UPDATE rule_name=VALUES(rule_name),rule_scope=VALUES(rule_scope),template_id=VALUES(template_id),product_category_id=VALUES(product_category_id),trigger_group_code=VALUES(trigger_group_code),trigger_operator=VALUES(trigger_operator),trigger_value=VALUES(trigger_value),target_group_code=VALUES(target_group_code),effect_action=VALUES(effect_action),effect_json=VALUES(effect_json),priority=VALUES(priority),is_enabled=VALUES(is_enabled),description=VALUES(description),updated_by=VALUES(updated_by),updated_at=NOW()'
        );
        $stmt->execute([$code,$name,$scope,$templateId,$categoryId,$trigger,$operator,trim((string)($input['trigger_value'] ?? '')),$target,$action,$effectJson,(int)($input['priority'] ?? 100),isset($input['is_enabled']) ? (int)$input['is_enabled'] : 1,trim((string)($input['description'] ?? '')),$userId,$userId]);
        $id = (int)pa2_db()->lastInsertId();
        if ($id === 0) {
            $lookup = pa2_db()->prepare('SELECT id FROM mc_pa2_rule_definitions WHERE rule_code=? LIMIT 1');
            $lookup->execute([$code]);
            $id = (int)$lookup->fetchColumn();
        }
    }
    $afterStmt = pa2_db()->prepare('SELECT * FROM mc_pa2_rule_definitions WHERE id=? LIMIT 1');
    $afterStmt->execute([$id]);
    $after = $afterStmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $cycles = pa2_detect_rule_cycles(pa2_fetch_rules(false))['cycles'];
    if ($cycles) {
        if ($before) {
            pa2_db()->prepare('UPDATE mc_pa2_rule_definitions SET rule_code=?,rule_name=?,rule_scope=?,template_id=?,product_category_id=?,trigger_group_code=?,trigger_operator=?,trigger_value=?,target_group_code=?,effect_action=?,effect_json=?,priority=?,is_enabled=?,description=?,updated_by=?,updated_at=NOW() WHERE id=?')
                ->execute([$before['rule_code'],$before['rule_name'],$before['rule_scope'],$before['template_id'],$before['product_category_id'],$before['trigger_group_code'],$before['trigger_operator'],$before['trigger_value'],$before['target_group_code'],$before['effect_action'],$before['effect_json'],$before['priority'],$before['is_enabled'],$before['description'],$userId,$id]);
        } else {
            pa2_db()->prepare('DELETE FROM mc_pa2_rule_definitions WHERE id=?')->execute([$id]);
        }
        throw new RuntimeException('规则存在循环依赖，已阻止保存：' . pa2_json_encode($cycles));
    }
    pa2_log($before ? 'rule_update' : 'rule_create', 'rule_definition', $id, $before, $after);
    return $after;
}

function pa2_search_products(string $keyword = '', int $limit = 30): array
{
    if (!pa2_table_exists('mc_products')) return [];
    $limit = max(1, min(100, $limit));
    $sql = "SELECT p.id,p.product_code,p.product_name,p.status,p.snapshot_json,
               m.category_id,m.category_name,m.series_code,m.source_type,m.confidence,m.confirmed_at
            FROM mc_products p
            LEFT JOIN mc_pa2_product_category_mappings m ON m.product_id=p.id
            WHERE p.status='active'";
    $params = [];
    $keyword = trim($keyword);
    if ($keyword !== '') {
        $sql .= ' AND (p.product_code LIKE ? OR p.product_name LIKE ? OR CAST(p.snapshot_json AS CHAR) LIKE ?)';
        $like = '%' . $keyword . '%';
        $params = [$like, $like, $like];
    }
    $sql .= " ORDER BY p.id DESC LIMIT {$limit}";
    $stmt = pa2_db()->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$row) {
        $snap = pa2_json_decode_array($row['snapshot_json'] ?? '');
        $row['id'] = (int)$row['id'];
        $row['image_url'] = (string)($snap['image_url'] ?? '');
        $row['legacy_category'] = (string)($snap['category'] ?? '');
        $row['series_name'] = (string)($snap['series_name'] ?? '');
        $row['category_id'] = $row['category_id'] === null ? null : (int)$row['category_id'];
        unset($row['snapshot_json']);
    }
    return $rows;
}

function pa2_map_product_category(array $input): array
{
    pa2_require_any(['adaptation_v2.manage_category', 'material_center.adaptation.manage'], '没有维护产品分类映射的权限。');
    if (!pa2_foundation_ready()) throw new RuntimeException('V2 基础表尚未迁移。');
    $productId = (int)($input['product_id'] ?? 0);
    $categoryId = (int)($input['category_id'] ?? 0);
    if ($productId <= 0 || $categoryId <= 0) throw new RuntimeException('产品和分类不能为空。');
    $categoryStmt = pa2_db()->prepare('SELECT category_code,category_name FROM mc_pa2_product_categories WHERE id=? LIMIT 1');
    $categoryStmt->execute([$categoryId]);
    $category = $categoryStmt->fetch(PDO::FETCH_ASSOC);
    if (!$category) throw new RuntimeException('分类不存在。');
    $seriesCode = trim((string)($input['series_code'] ?? ''));
    $userId = pa2_current_user_id();
    $beforeStmt = pa2_db()->prepare('SELECT * FROM mc_pa2_product_category_mappings WHERE product_id=? LIMIT 1');
    $beforeStmt->execute([$productId]);
    $before = $beforeStmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $stmt = pa2_db()->prepare(
        'INSERT INTO mc_pa2_product_category_mappings(product_id,category_id,category_code,category_name,series_code,source_type,confidence,confirmed_by,confirmed_at,created_at,updated_at)
         VALUES(?,?,?,?,?,"manual",100,?,NOW(),NOW(),NOW())
         ON DUPLICATE KEY UPDATE category_id=VALUES(category_id),category_code=VALUES(category_code),category_name=VALUES(category_name),series_code=VALUES(series_code),source_type="manual",confidence=100,confirmed_by=VALUES(confirmed_by),confirmed_at=NOW(),updated_at=NOW()'
    );
    $stmt->execute([$productId, $categoryId, $category['category_code'], $category['category_name'], $seriesCode, $userId]);
    $afterStmt = pa2_db()->prepare('SELECT * FROM mc_pa2_product_category_mappings WHERE product_id=? LIMIT 1');
    $afterStmt->execute([$productId]);
    $after = $afterStmt->fetch(PDO::FETCH_ASSOC) ?: [];
    pa2_log('product_category_map', 'product', $productId, $before, $after);
    return $after;
}

function pa2_template_tables_ready(): bool
{
    foreach (['mc_pa2_templates','mc_pa2_template_versions','mc_pa2_template_groups'] as $table) {
        if (!pa2_table_exists($table)) return false;
    }
    return true;
}

function pa2_fetch_templates(): array
{
    if (!pa2_template_tables_ready()) return [];
    $rows = pa2_db()->query(
        "SELECT t.*, c.category_name,
            p.template_name AS parent_template_name,
            v.version_no AS active_version_no,
            (SELECT COUNT(*) FROM mc_pa2_template_groups g WHERE g.template_id=t.id AND g.is_enabled=1 AND g.inheritance_action<>'disable') AS direct_group_count
         FROM mc_pa2_templates t
         LEFT JOIN mc_pa2_product_categories c ON c.id=t.product_category_id
         LEFT JOIN mc_pa2_templates p ON p.id=t.parent_template_id
         LEFT JOIN mc_pa2_template_versions v ON v.id=t.active_version_id
         ORDER BY FIELD(t.template_level,'system','category','series','product'), t.id"
    )->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$row) {
        $row['id'] = (int)$row['id'];
        $row['parent_template_id'] = $row['parent_template_id'] === null ? null : (int)$row['parent_template_id'];
        $row['product_category_id'] = $row['product_category_id'] === null ? null : (int)$row['product_category_id'];
        $row['direct_group_count'] = (int)$row['direct_group_count'];
        $row['is_enabled'] = (int)$row['is_enabled'];
    }
    return $rows;
}

function pa2_fetch_template(int $id): ?array
{
    if (!pa2_template_tables_ready() || $id <= 0) return null;
    $stmt = pa2_db()->prepare(
        "SELECT t.*, c.category_name, p.template_name AS parent_template_name, v.version_no AS active_version_no
         FROM mc_pa2_templates t
         LEFT JOIN mc_pa2_product_categories c ON c.id=t.product_category_id
         LEFT JOIN mc_pa2_templates p ON p.id=t.parent_template_id
         LEFT JOIN mc_pa2_template_versions v ON v.id=t.active_version_id
         WHERE t.id=? LIMIT 1"
    );
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) return null;
    $row['id'] = (int)$row['id'];
    $row['parent_template_id'] = $row['parent_template_id'] === null ? null : (int)$row['parent_template_id'];
    $row['product_category_id'] = $row['product_category_id'] === null ? null : (int)$row['product_category_id'];
    $row['is_enabled'] = (int)$row['is_enabled'];
    return $row;
}

function pa2_fetch_template_direct_groups(int $templateId): array
{
    if (!pa2_template_tables_ready() || $templateId <= 0) return [];
    $stmt = pa2_db()->prepare(
        "SELECT tg.*, gd.group_name, gd.group_type, gd.icon, gd.description AS group_description
         FROM mc_pa2_template_groups tg
         JOIN mc_pa2_group_definitions gd ON gd.id=tg.group_definition_id
         WHERE tg.template_id=?
         ORDER BY tg.sort_order,tg.id"
    );
    $stmt->execute([$templateId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$row) {
        foreach (['id','template_id','group_definition_id','is_required','allow_empty','min_select','max_select','allow_default','customer_selectable','affects_price','affects_lead_time','requires_approval','sort_order','is_enabled'] as $key) {
            $row[$key] = (int)$row[$key];
        }
        $row['display_name'] = trim((string)($row['group_name_override'] ?? '')) ?: (string)$row['group_name'];
        $row['display_type'] = trim((string)($row['group_type_override'] ?? '')) ?: (string)$row['group_type'];
    }
    return $rows;
}

function pa2_template_ancestry(int $templateId): array
{
    $chain = [];
    $seen = [];
    $current = pa2_fetch_template($templateId);
    while ($current) {
        if (isset($seen[$current['id']])) throw new RuntimeException('模板继承存在循环。');
        $seen[$current['id']] = true;
        array_unshift($chain, $current);
        $parentId = (int)($current['parent_template_id'] ?? 0);
        $current = $parentId > 0 ? pa2_fetch_template($parentId) : null;
    }
    return $chain;
}

function pa2_template_effective_groups(int $templateId): array
{
    $chain = pa2_template_ancestry($templateId);
    $effective = [];
    $changes = [];
    foreach ($chain as $template) {
        foreach (pa2_fetch_template_direct_groups((int)$template['id']) as $group) {
            $code = (string)$group['group_code'];
            $action = (string)$group['inheritance_action'];
            if ($action === 'disable') {
                if (isset($effective[$code])) {
                    $changes[] = ['group_code' => $code, 'type' => 'disable', 'template_name' => $template['template_name']];
                }
                unset($effective[$code]);
                continue;
            }
            $type = isset($effective[$code]) ? 'override' : 'add';
            $group['source_template_id'] = (int)$template['id'];
            $group['source_template_name'] = (string)$template['template_name'];
            $group['effective_change_type'] = $type;
            $effective[$code] = $group;
            $changes[] = ['group_code' => $code, 'type' => $type, 'template_name' => $template['template_name']];
        }
    }
    uasort($effective, static fn($a, $b) => ((int)$a['sort_order'] <=> (int)$b['sort_order']) ?: ((int)$a['id'] <=> (int)$b['id']));
    return ['chain' => $chain, 'groups' => array_values($effective), 'changes' => $changes];
}

function pa2_template_next_version_no(int $templateId): string
{
    $stmt = pa2_db()->prepare("SELECT COUNT(*) FROM mc_pa2_template_versions WHERE template_id=?");
    $stmt->execute([$templateId]);
    return 'v' . ((int)$stmt->fetchColumn() + 1);
}

function pa2_upsert_template(array $input): array
{
    pa2_require_any(['adaptation_v2.manage_template', 'material_center.adaptation.manage'], '没有维护模板的权限。');
    if (!pa2_template_tables_ready()) throw new RuntimeException('V2 模板表尚未迁移。');
    $id = (int)($input['id'] ?? 0);
    $name = trim((string)($input['template_name'] ?? ''));
    if ($name === '') throw new RuntimeException('模板名称不能为空。');
    $code = trim((string)($input['template_code'] ?? '')) ?: pa2_slug($name, 'template');
    $level = trim((string)($input['template_level'] ?? 'category'));
    if (!in_array($level, ['system','category','series','product'], true)) $level = 'category';
    $scope = trim((string)($input['scope_type'] ?? $level));
    $categoryId = (int)($input['product_category_id'] ?? 0) ?: null;
    $parentId = (int)($input['parent_template_id'] ?? 0) ?: null;
    if ($id > 0 && $parentId === $id) throw new RuntimeException('父模板不能选择自己。');
    $seriesCode = trim((string)($input['series_code'] ?? ''));
    $productId = (int)($input['product_id'] ?? 0) ?: null;
    $enabled = isset($input['is_enabled']) ? (int)$input['is_enabled'] : 1;
    $description = trim((string)($input['description'] ?? ''));
    $userId = pa2_current_user_id();
    if ($id > 0) {
        $before = pa2_fetch_template($id) ?: [];
        if (!$before) throw new RuntimeException('模板不存在。');
        $stmt = pa2_db()->prepare(
            'UPDATE mc_pa2_templates SET template_code=?,template_name=?,template_level=?,scope_type=?,product_category_id=?,series_code=?,product_id=?,parent_template_id=?,is_enabled=?,description=?,updated_by=?,updated_at=NOW() WHERE id=?'
        );
        $stmt->execute([$code,$name,$level,$scope,$categoryId,$seriesCode,$productId,$parentId,$enabled,$description,$userId,$id]);
        $after = pa2_fetch_template($id) ?: [];
        pa2_log('template_update', 'template', $id, $before, $after);
    } else {
        $stmt = pa2_db()->prepare(
            'INSERT INTO mc_pa2_templates(template_code,template_name,template_level,scope_type,product_category_id,series_code,product_id,parent_template_id,status,is_enabled,description,created_by,updated_by,created_at,updated_at)
             VALUES(?,?,?,?,?,?,?,?,\'draft\',?,?,?, ?,NOW(),NOW())'
        );
        $stmt->execute([$code,$name,$level,$scope,$categoryId,$seriesCode,$productId,$parentId,$enabled,$description,$userId,$userId]);
        $id = (int)pa2_db()->lastInsertId();
        pa2_log('template_create', 'template', $id, [], ['template_code' => $code, 'template_name' => $name]);
    }
    return pa2_fetch_template($id) ?: [];
}

function pa2_upsert_template_group(array $input): array
{
    pa2_require_any(['adaptation_v2.manage_template', 'material_center.adaptation.manage'], '没有维护模板配置组的权限。');
    if (!pa2_template_tables_ready()) throw new RuntimeException('V2 模板表尚未迁移。');
    $templateId = (int)($input['template_id'] ?? 0);
    $groupId = (int)($input['group_definition_id'] ?? 0);
    if ($templateId <= 0 || $groupId <= 0) throw new RuntimeException('模板和配置组不能为空。');
    $groupStmt = pa2_db()->prepare('SELECT group_code FROM mc_pa2_group_definitions WHERE id=? LIMIT 1');
    $groupStmt->execute([$groupId]);
    $groupCode = (string)$groupStmt->fetchColumn();
    if ($groupCode === '') throw new RuntimeException('配置组不存在。');
    $action = trim((string)($input['inheritance_action'] ?? 'add'));
    if (!in_array($action, ['add','override','disable'], true)) $action = 'add';
    $userId = pa2_current_user_id();
    $beforeStmt = pa2_db()->prepare('SELECT * FROM mc_pa2_template_groups WHERE template_id=? AND group_code=? LIMIT 1');
    $beforeStmt->execute([$templateId,$groupCode]);
    $before = $beforeStmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $stmt = pa2_db()->prepare(
        'INSERT INTO mc_pa2_template_groups(template_id,group_definition_id,group_code,group_name_override,group_type_override,is_required,selection_mode,allow_empty,min_select,max_select,allow_default,customer_selectable,affects_price,affects_lead_time,requires_approval,sort_order,is_enabled,inheritance_action,created_by,updated_by,created_at,updated_at)
         VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW(),NOW())
         ON DUPLICATE KEY UPDATE group_definition_id=VALUES(group_definition_id),group_name_override=VALUES(group_name_override),group_type_override=VALUES(group_type_override),is_required=VALUES(is_required),selection_mode=VALUES(selection_mode),allow_empty=VALUES(allow_empty),min_select=VALUES(min_select),max_select=VALUES(max_select),allow_default=VALUES(allow_default),customer_selectable=VALUES(customer_selectable),affects_price=VALUES(affects_price),affects_lead_time=VALUES(affects_lead_time),requires_approval=VALUES(requires_approval),sort_order=VALUES(sort_order),is_enabled=VALUES(is_enabled),inheritance_action=VALUES(inheritance_action),updated_by=VALUES(updated_by),updated_at=NOW()'
    );
    $stmt->execute([
        $templateId,
        $groupId,
        $groupCode,
        trim((string)($input['group_name_override'] ?? '')) ?: null,
        trim((string)($input['group_type_override'] ?? '')) ?: null,
        !empty($input['is_required']) ? 1 : 0,
        trim((string)($input['selection_mode'] ?? 'single')) ?: 'single',
        isset($input['allow_empty']) ? (int)$input['allow_empty'] : 1,
        (int)($input['min_select'] ?? 0),
        (int)($input['max_select'] ?? 1),
        !empty($input['allow_default']) ? 1 : 0,
        !empty($input['customer_selectable']) ? 1 : 0,
        !empty($input['affects_price']) ? 1 : 0,
        !empty($input['affects_lead_time']) ? 1 : 0,
        !empty($input['requires_approval']) ? 1 : 0,
        (int)($input['sort_order'] ?? 100),
        isset($input['is_enabled']) ? (int)$input['is_enabled'] : 1,
        $action,
        $userId,
        $userId,
    ]);
    $afterStmt = pa2_db()->prepare('SELECT * FROM mc_pa2_template_groups WHERE template_id=? AND group_code=? LIMIT 1');
    $afterStmt->execute([$templateId,$groupCode]);
    $after = $afterStmt->fetch(PDO::FETCH_ASSOC) ?: [];
    pa2_log($before ? 'template_group_update' : 'template_group_create', 'template', $templateId, $before, $after);
    return $after;
}

function pa2_publish_template(int $templateId): array
{
    pa2_require_any(['adaptation_v2.publish_template', 'material_center.adaptation.manage'], '没有发布模板的权限。');
    $template = pa2_fetch_template($templateId);
    if (!$template) throw new RuntimeException('模板不存在。');
    $preview = pa2_template_effective_groups($templateId);
    if (!$preview['groups']) throw new RuntimeException('模板没有有效配置组，不能发布。');
    $versionNo = pa2_template_next_version_no($templateId);
    $snapshot = [
        'template' => $template,
        'chain' => $preview['chain'],
        'groups' => $preview['groups'],
        'changes' => $preview['changes'],
        'published_at' => date('Y-m-d H:i:s'),
    ];
    $userId = pa2_current_user_id();
    $stmt = pa2_db()->prepare(
        'INSERT INTO mc_pa2_template_versions(template_id,version_no,status,snapshot_json,created_by,published_by,created_at,published_at)
         VALUES(?,?,\'published\',?,?,?,NOW(),NOW())'
    );
    $stmt->execute([$templateId,$versionNo,pa2_json_encode($snapshot),$userId,$userId]);
    $versionId = (int)pa2_db()->lastInsertId();
    pa2_db()->prepare("UPDATE mc_pa2_templates SET active_version_id=?,status='published',updated_by=?,updated_at=NOW() WHERE id=?")->execute([$versionId,$userId,$templateId]);
    pa2_log('template_publish', 'template', $templateId, [], ['version_id' => $versionId, 'version_no' => $versionNo]);
    return ['version_id' => $versionId, 'version_no' => $versionNo, 'snapshot' => $snapshot];
}

function pa2_template_reference_check(int $templateId): array
{
    $template = pa2_fetch_template($templateId);
    if (!$template) throw new RuntimeException('模板不存在。');
    $childStmt = pa2_db()->prepare('SELECT COUNT(*) FROM mc_pa2_templates WHERE parent_template_id=?');
    $childStmt->execute([$templateId]);
    $versionStmt = pa2_db()->prepare('SELECT COUNT(*) FROM mc_pa2_template_versions WHERE template_id=?');
    $versionStmt->execute([$templateId]);
    return [
        'child_templates' => (int)$childStmt->fetchColumn(),
        'published_versions' => (int)$versionStmt->fetchColumn(),
        'product_configs' => 0,
        'safe_to_disable' => true,
        'note' => '第 3 阶段只检查模板继承和版本引用；产品配置引用将在第 5-7 阶段接入。',
    ];
}

function pa2_workspace_tables_ready(): bool
{
    foreach (['mc_pa2_product_configs','mc_pa2_product_config_versions','mc_pa2_product_group_configs','mc_pa2_product_selected_options'] as $table) {
        if (!pa2_table_exists($table)) return false;
    }
    return true;
}

function pa2_fetch_product(int $productId): ?array
{
    if (!pa2_table_exists('mc_products') || $productId <= 0) return null;
    $stmt = pa2_db()->prepare(
        "SELECT p.id,p.product_code,p.product_name,p.status,p.snapshot_json,
                m.category_id,m.category_code,m.category_name,m.series_code,m.source_type,m.confidence
         FROM mc_products p
         LEFT JOIN mc_pa2_product_category_mappings m ON m.product_id=p.id
         WHERE p.id=? LIMIT 1"
    );
    $stmt->execute([$productId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) return null;
    $snap = pa2_json_decode_array($row['snapshot_json'] ?? '');
    $row['id'] = (int)$row['id'];
    $row['category_id'] = $row['category_id'] === null ? null : (int)$row['category_id'];
    $row['snapshot'] = $snap;
    $row['image_url'] = (string)($snap['image_url'] ?? $snap['image'] ?? '');
    $row['legacy_category'] = (string)($snap['category'] ?? '');
    $row['series_name'] = (string)($snap['series_name'] ?? '');
    unset($row['snapshot_json']);
    return $row;
}

function pa2_template_for_product(array $product): ?array
{
    if (!pa2_template_tables_ready()) return null;
    $productId = (int)($product['id'] ?? 0);
    $categoryId = (int)($product['category_id'] ?? 0);
    $seriesCode = trim((string)($product['series_code'] ?? ''));
    $candidates = [];
    if ($productId > 0) {
        $stmt = pa2_db()->prepare("SELECT * FROM mc_pa2_templates WHERE product_id=? AND is_enabled=1 ORDER BY id DESC LIMIT 1");
        $stmt->execute([$productId]);
        $candidates[] = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    if ($categoryId > 0 && $seriesCode !== '') {
        $stmt = pa2_db()->prepare("SELECT * FROM mc_pa2_templates WHERE product_category_id=? AND series_code=? AND is_enabled=1 ORDER BY id DESC LIMIT 1");
        $stmt->execute([$categoryId,$seriesCode]);
        $candidates[] = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    if ($categoryId > 0) {
        $stmt = pa2_db()->prepare("SELECT * FROM mc_pa2_templates WHERE product_category_id=? AND template_level='category' AND is_enabled=1 ORDER BY id DESC LIMIT 1");
        $stmt->execute([$categoryId]);
        $candidates[] = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    $stmt = pa2_db()->query("SELECT * FROM mc_pa2_templates WHERE template_code='system_common' AND is_enabled=1 LIMIT 1");
    $candidates[] = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    foreach ($candidates as $candidate) {
        if ($candidate) {
            $candidate['id'] = (int)$candidate['id'];
            return $candidate;
        }
    }
    return null;
}

function pa2_workspace_check_summary(array $groups): array
{
    $summary = ['required_total' => 0, 'completed_required' => 0, 'missing_required' => 0, 'optional_total' => 0, 'selected_total' => 0, 'items' => []];
    foreach ($groups as $group) {
        $settings = $group['effective_settings'] ?? [];
        $required = !empty($settings['is_required']) || !empty($settings['is_required_default']);
        $selected = count($group['selected_options'] ?? []);
        if ($required) {
            $summary['required_total']++;
            if ($selected > 0) $summary['completed_required']++;
            else $summary['missing_required']++;
        } else {
            $summary['optional_total']++;
        }
        if ($selected > 0) $summary['selected_total']++;
        $summary['items'][] = [
            'group_code' => $group['group_code'],
            'display_name' => $group['display_name'],
            'required' => $required,
            'selected' => $selected,
            'status' => $selected > 0 ? 'completed' : ($required ? 'missing' : 'optional'),
        ];
    }
    return $summary;
}

function pa2_prepare_workspace(int $productId): array
{
    pa2_require_any(['adaptation_v2.configure_product', 'adaptation_v2.view', 'material_center.adaptation.manage'], '没有配置产品的权限。');
    if (!pa2_workspace_tables_ready()) throw new RuntimeException('V2 第 5 阶段工作台表尚未迁移。');
    $product = pa2_fetch_product($productId);
    if (!$product) throw new RuntimeException('产品不存在。');
    $template = pa2_template_for_product($product);
    if (!$template) throw new RuntimeException('没有可用模板。');
    $preview = pa2_template_effective_groups((int)$template['id']);
    $userId = pa2_current_user_id();
    $categoryId = (int)($product['category_id'] ?? 0) ?: null;
    $configStmt = pa2_db()->prepare('SELECT * FROM mc_pa2_product_configs WHERE product_id=? LIMIT 1');
    $configStmt->execute([$productId]);
    $config = $configStmt->fetch(PDO::FETCH_ASSOC);
    if (!$config) {
        $insert = pa2_db()->prepare(
            'INSERT INTO mc_pa2_product_configs(product_id,product_category_id,source_template_id,status,owner_user_id,created_by,updated_by,created_at,updated_at)
             VALUES(?,?,?,"draft",?,?,?,NOW(),NOW())'
        );
        $insert->execute([$productId,$categoryId,(int)$template['id'],$userId,$userId,$userId]);
        $configId = (int)pa2_db()->lastInsertId();
        $version = 'draft-1';
        $versionInsert = pa2_db()->prepare(
            'INSERT INTO mc_pa2_product_config_versions(product_config_id,version_no,source_template_id,source_template_version_id,status,configuration_snapshot_json,created_by,created_at)
             VALUES(?,?,?,?, "draft", ?, ?, NOW())'
        );
        $versionInsert->execute([$configId,$version,(int)$template['id'],$template['active_version_id'] ?? null,pa2_json_encode(['source' => 'template', 'template_id' => (int)$template['id']]),$userId]);
        $versionId = (int)pa2_db()->lastInsertId();
        pa2_db()->prepare('UPDATE mc_pa2_product_configs SET active_draft_version_id=? WHERE id=?')->execute([$versionId,$configId]);
        $config = ['id'=>$configId,'product_id'=>$productId,'active_draft_version_id'=>$versionId,'source_template_id'=>(int)$template['id'],'status'=>'draft'];
        pa2_log('workspace_create', 'product_config', $configId, [], ['product_id' => $productId, 'template_id' => (int)$template['id']]);
    } else {
        $config['id'] = (int)$config['id'];
        $versionId = (int)($config['active_draft_version_id'] ?? 0);
        $draftVersion = $versionId > 0 ? pa2_fetch_version($versionId) : null;
        if ($versionId > 0 && $draftVersion && !in_array((string)$draftVersion['status'], ['draft','rejected'], true)) {
            $versionId = 0;
        }
        if ($versionId <= 0 && (int)($config['active_published_version_id'] ?? 0) > 0 && pa2_phase7_tables_ready()) {
            $versionId = pa2_clone_version_as_draft((int)$config['active_published_version_id'], (int)$config['id'], 'edit_after_publish');
            $config['active_draft_version_id'] = $versionId;
        }
        if ($versionId <= 0) {
            $versionInsert = pa2_db()->prepare(
                'INSERT INTO mc_pa2_product_config_versions(product_config_id,version_no,source_template_id,source_template_version_id,status,configuration_snapshot_json,created_by,created_at)
                 VALUES(?,?,?,?, "draft", ?, ?, NOW())'
            );
            $versionInsert->execute([(int)$config['id'],'draft-1',(int)$template['id'],$template['active_version_id'] ?? null,pa2_json_encode(['source' => 'template', 'template_id' => (int)$template['id']]),$userId]);
            $versionId = (int)pa2_db()->lastInsertId();
            pa2_db()->prepare('UPDATE mc_pa2_product_configs SET active_draft_version_id=?,source_template_id=?,product_category_id=?,updated_by=?,updated_at=NOW() WHERE id=?')->execute([$versionId,(int)$template['id'],$categoryId,$userId,(int)$config['id']]);
            $config['active_draft_version_id'] = $versionId;
        }
    }
    $versionId = (int)$config['active_draft_version_id'];
    foreach ($preview['groups'] as $group) {
        $settings = [
            'template_group' => $group,
            'behavior' => $group['behavior'] ?? null,
            'is_required' => (int)($group['is_required'] ?? 0),
            'selection_mode' => (string)($group['selection_mode'] ?? 'single'),
            'min_select' => (int)($group['min_select'] ?? 0),
            'max_select' => (int)($group['max_select'] ?? 1),
        ];
        $stmt = pa2_db()->prepare(
            'INSERT INTO mc_pa2_product_group_configs(product_config_version_id,group_code,group_definition_id,display_name,group_type,effective_settings_json,status,is_overridden,override_source,sort_order,created_by,updated_by,created_at,updated_at)
             VALUES(?,?,?,?,? ,?,"missing",0,"template",?,?,?,NOW(),NOW())
             ON DUPLICATE KEY UPDATE group_definition_id=VALUES(group_definition_id),display_name=VALUES(display_name),group_type=VALUES(group_type),effective_settings_json=VALUES(effective_settings_json),sort_order=VALUES(sort_order),updated_by=VALUES(updated_by),updated_at=NOW()'
        );
        $stmt->execute([
            $versionId,
            (string)$group['group_code'],
            (int)$group['group_definition_id'],
            (string)$group['display_name'],
            (string)$group['display_type'],
            pa2_json_encode($settings),
            (int)$group['sort_order'],
            $userId,
            $userId,
        ]);
    }
    return pa2_workspace_detail($productId);
}

function pa2_workspace_detail(int $productId): array
{
    if (!pa2_workspace_tables_ready()) throw new RuntimeException('V2 第 5 阶段工作台表尚未迁移。');
    $product = pa2_fetch_product($productId);
    if (!$product) throw new RuntimeException('产品不存在。');
    $configStmt = pa2_db()->prepare(
        "SELECT pc.*, t.template_name, t.template_code, t.active_version_id, tv.version_no AS active_template_version_no
         FROM mc_pa2_product_configs pc
         LEFT JOIN mc_pa2_templates t ON t.id=pc.source_template_id
         LEFT JOIN mc_pa2_template_versions tv ON tv.id=t.active_version_id
         WHERE pc.product_id=? LIMIT 1"
    );
    $configStmt->execute([$productId]);
    $config = $configStmt->fetch(PDO::FETCH_ASSOC);
    if (!$config) return ['product'=>$product,'config'=>null,'version'=>null,'template'=>pa2_template_for_product($product),'template_preview'=>[],'groups'=>[],'check_summary'=>['missing_required'=>0]];
    $activeDraftVersionId = (int)($config['active_draft_version_id'] ?? 0);
    $activePublishedVersionId = (int)($config['active_published_version_id'] ?? 0);
    $versionId = $activeDraftVersionId > 0 ? $activeDraftVersionId : $activePublishedVersionId;
    $versionStmt = pa2_db()->prepare('SELECT * FROM mc_pa2_product_config_versions WHERE id=? LIMIT 1');
    $versionStmt->execute([$versionId]);
    $version = $versionStmt->fetch(PDO::FETCH_ASSOC) ?: null;
    $hasEditableDraft = $activeDraftVersionId > 0 && $version && in_array((string)$version['status'], ['draft','rejected','submitted','approved'], true);
    $groupsStmt = pa2_db()->prepare(
        "SELECT pg.*, gd.group_name, gd.group_type AS definition_type, gd.icon, bs.selection_kind, bs.source_mode, bs.material_category_code
         FROM mc_pa2_product_group_configs pg
         JOIN mc_pa2_group_definitions gd ON gd.id=pg.group_definition_id
         LEFT JOIN mc_pa2_group_behavior_settings bs ON bs.group_definition_id=pg.group_definition_id
         WHERE pg.product_config_version_id=?
         ORDER BY pg.sort_order,pg.id"
    );
    $groupsStmt->execute([$versionId]);
    $groups = $groupsStmt->fetchAll(PDO::FETCH_ASSOC);
    $selectedByGroup = [];
    if ($groups) {
        $ids = array_map(static fn($g) => (int)$g['id'], $groups);
        $marks = implode(',', array_fill(0, count($ids), '?'));
        $selectedStmt = pa2_db()->prepare(
            "SELECT so.*, m.material_code,m.name AS material_name,m.brand,m.model,o.option_code,o.option_name
             FROM mc_pa2_product_selected_options so
             LEFT JOIN mc_materials m ON m.id=so.material_id
             LEFT JOIN mc_pa2_group_option_definitions o ON o.id=so.option_definition_id
             WHERE so.product_group_config_id IN ($marks)
             ORDER BY so.product_group_config_id,so.sort_order,so.id"
        );
        $selectedStmt->execute($ids);
        foreach ($selectedStmt->fetchAll(PDO::FETCH_ASSOC) as $selected) {
            $gid = (int)$selected['product_group_config_id'];
            $selected['id'] = (int)$selected['id'];
            $selected['material_id'] = $selected['material_id'] === null ? null : (int)$selected['material_id'];
            $selected['option_definition_id'] = $selected['option_definition_id'] === null ? null : (int)$selected['option_definition_id'];
            $selectedByGroup[$gid][] = $selected;
        }
    }
    foreach ($groups as &$group) {
        $group['id'] = (int)$group['id'];
        $group['group_definition_id'] = (int)$group['group_definition_id'];
        $group['sort_order'] = (int)$group['sort_order'];
        $group['effective_settings'] = pa2_json_decode_array($group['effective_settings_json'] ?? '');
        $group['display_name'] = trim((string)($group['display_name'] ?? '')) ?: (string)$group['group_name'];
        $group['selected_options'] = $selectedByGroup[(int)$group['id']] ?? [];
    }
    unset($group);
    $cachedByGroup = [];
    $cachedFlat = [];
    if ($version && pa2_engine_tables_ready()) {
        $cachedByGroup = pa2_cached_results_for_version($versionId);
        $cachedFlat = pa2_flat_cached_results($cachedByGroup);
        foreach ($groups as &$group) {
            $gid = (int)$group['id'];
            $group['adaptation_results'] = $cachedByGroup[$gid] ?? [];
            foreach ($group['selected_options'] as &$selected) {
                $selected['adaptation_result'] = null;
                foreach ($group['adaptation_results'] as $result) {
                    $sameMaterial = $selected['material_id'] !== null && $result['material_id'] !== null && (int)$selected['material_id'] === (int)$result['material_id'];
                    $sameOption = $selected['option_definition_id'] !== null && $result['option_definition_id'] !== null && (int)$selected['option_definition_id'] === (int)$result['option_definition_id'];
                    if ($sameMaterial || $sameOption) {
                        $selected['adaptation_result'] = $result;
                        break;
                    }
                }
            }
            unset($selected);
        }
        unset($group);
    }
    $summary = pa2_workspace_check_summary($groups);
    $existingSummary = $version ? pa2_json_decode_array($version['check_summary_json'] ?? '') : [];
    if ($cachedFlat) {
        $summary['engine'] = pa2_engine_summary_from_results($cachedFlat);
    } elseif (isset($existingSummary['engine']) && is_array($existingSummary['engine'])) {
        $summary['engine'] = $existingSummary['engine'];
    }
    if (isset($existingSummary['technical_range']) && is_array($existingSummary['technical_range'])) {
        $summary['technical_range'] = $existingSummary['technical_range'];
    }
    if ($version) {
        pa2_db()->prepare('UPDATE mc_pa2_product_config_versions SET check_summary_json=? WHERE id=?')->execute([pa2_json_encode($summary),$versionId]);
    }
    return [
        'product' => $product,
        'config' => $config,
        'version' => $version,
        'has_editable_draft' => $hasEditableDraft,
        'template' => $config['source_template_id'] ? pa2_fetch_template((int)$config['source_template_id']) : pa2_template_for_product($product),
        'template_preview' => $config['source_template_id'] ? pa2_template_effective_groups((int)$config['source_template_id']) : [],
        'groups' => $groups,
        'check_summary' => $summary,
        'adaptation_results' => $cachedFlat,
    ];
}

function pa2_material_candidates(string $groupCode, string $keyword = '', int $limit = 30, int $productId = 0, int $productGroupConfigId = 0): array
{
    if (!pa2_table_exists('mc_materials')) return [];
    $limit = max(1, min(80, $limit));
    $behaviorStmt = pa2_db()->prepare(
        "SELECT bs.*, gd.group_name
         FROM mc_pa2_group_behavior_settings bs
         JOIN mc_pa2_group_definitions gd ON gd.id=bs.group_definition_id
         WHERE bs.group_code=? LIMIT 1"
    );
    $behaviorStmt->execute([$groupCode]);
    $behavior = $behaviorStmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $category = trim((string)($behavior['material_category_code'] ?? ''));
    $filter = pa2_json_decode_array($behavior['material_filter_json'] ?? '');
    $sql = "SELECT m.id,m.material_code,m.name,m.brand,m.model,m.status,m.is_official,c.code AS category_code,c.name AS category_name,md.spec_summary
            FROM mc_materials m
            JOIN mc_material_categories c ON c.id=m.category_id
            LEFT JOIN mc_material_metadata md ON md.material_id=m.id
            WHERE m.deleted_at IS NULL";
    $params = [];
    if ($category !== '') {
        $sql .= ' AND c.code=?';
        $params[] = $category;
    }
    if (($filter['formal_status'] ?? '') === 'official') {
        $sql .= " AND (m.is_official=1 OR m.status='official')";
    }
    $keyword = trim($keyword);
    $filterText = trim((string)($filter['keyword'] ?? ''));
    $searchText = $keyword !== '' ? $keyword : $filterText;
    if ($searchText !== '') {
        $sql .= ' AND (m.material_code LIKE ? OR m.name LIKE ? OR m.brand LIKE ? OR m.model LIKE ? OR md.spec_summary LIKE ?)';
        $like = '%' . $searchText . '%';
        array_push($params, $like, $like, $like, $like, $like);
    }
    $sql .= " ORDER BY m.is_official DESC,m.updated_at DESC,m.id DESC LIMIT {$limit}";
    $stmt = pa2_db()->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$row) {
        $row['id'] = (int)$row['id'];
        $row['is_official'] = (int)$row['is_official'];
        if ($productId > 0 && $productGroupConfigId > 0) {
            $row['adaptation_result'] = pa2_evaluate_material_candidate_for_group($productId, $productGroupConfigId, $row);
        }
    }
    return $rows;
}

function pa2_evaluate_material_candidate_for_group(int $productId, int $productGroupConfigId, array $candidate): array
{
    $product = pa2_fetch_product($productId);
    if (!$product) return pa2_result('incompatible', 0, ['产品不存在，无法计算候选适配。'], ['product_id']);
    $stmt = pa2_db()->prepare(
        "SELECT pg.*, gd.group_name, gd.group_type AS definition_type, gd.icon, bs.selection_kind, bs.source_mode, bs.material_category_code, bs.material_filter_json, bs.validation_json
         FROM mc_pa2_product_group_configs pg
         JOIN mc_pa2_group_definitions gd ON gd.id=pg.group_definition_id
         LEFT JOIN mc_pa2_group_behavior_settings bs ON bs.group_definition_id=pg.group_definition_id
         WHERE pg.id=? LIMIT 1"
    );
    $stmt->execute([$productGroupConfigId]);
    $group = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$group) return pa2_result('incompatible', 0, ['产品配置组不存在，无法计算候选适配。'], ['product_group_config_id']);
    $group['id'] = (int)$group['id'];
    $group['effective_settings'] = pa2_json_decode_array($group['effective_settings_json'] ?? '');
    $group['display_name'] = trim((string)($group['display_name'] ?? '')) ?: (string)$group['group_name'];
    $material = pa2_material_detail((int)($candidate['id'] ?? 0));
    return pa2_candidate_status_for_group($group, $material ?: $candidate, [
        'product' => $product,
        'technical_range' => pa2_extract_product_technical_range($product),
        'selection' => ['option_type' => 'material', 'material_id' => (int)($candidate['id'] ?? 0)],
    ]);
}

function pa2_save_product_group_selection(array $input): array
{
    pa2_require_any(['adaptation_v2.configure_product', 'material_center.adaptation.manage'], '没有保存产品配置的权限。');
    if (!pa2_workspace_tables_ready()) throw new RuntimeException('V2 第 5 阶段工作台表尚未迁移。');
    $groupConfigId = (int)($input['product_group_config_id'] ?? 0);
    if ($groupConfigId <= 0) throw new RuntimeException('配置组不能为空。');
    $groupStmt = pa2_db()->prepare('SELECT * FROM mc_pa2_product_group_configs WHERE id=? LIMIT 1');
    $groupStmt->execute([$groupConfigId]);
    $group = $groupStmt->fetch(PDO::FETCH_ASSOC);
    if (!$group) throw new RuntimeException('产品配置组不存在。');
    $version = pa2_fetch_version((int)$group['product_config_version_id']);
    if (!$version || !in_array((string)$version['status'], ['draft','rejected'], true)) {
        throw new RuntimeException('当前版本已提交、审批或发布，请先生成新的草稿再修改。');
    }
    $optionType = trim((string)($input['option_type'] ?? 'attribute'));
    if (!in_array($optionType, ['material','attribute','number','text','boolean'], true)) $optionType = 'attribute';
    $replace = isset($input['replace']) ? (int)$input['replace'] : 1;
    $userId = pa2_current_user_id();
    if ($replace === 1) {
        pa2_db()->prepare('DELETE FROM mc_pa2_product_selected_options WHERE product_group_config_id=?')->execute([$groupConfigId]);
    }
    $materialId = (int)($input['material_id'] ?? 0) ?: null;
    $optionDefinitionId = (int)($input['option_definition_id'] ?? 0) ?: null;
    $numeric = trim((string)($input['numeric_value'] ?? ''));
    $text = trim((string)($input['text_value'] ?? ''));
    $boolean = isset($input['boolean_value']) && $input['boolean_value'] !== '' ? (int)$input['boolean_value'] : null;
    if ($optionType === 'material' && !$materialId) throw new RuntimeException('请选择正式物料。');
    if ($optionType === 'attribute' && !$optionDefinitionId) throw new RuntimeException('请选择属性选项。');
    if ($optionType === 'number' && ($numeric === '' || !is_numeric($numeric))) throw new RuntimeException('请输入数值。');
    if ($optionType === 'text' && $text === '') throw new RuntimeException('请输入文本。');
    $stmt = pa2_db()->prepare(
        'INSERT INTO mc_pa2_product_selected_options(product_group_config_id,option_type,material_id,option_definition_id,numeric_value,text_value,boolean_value,value_json,is_default,is_alternative,sort_order,created_by,updated_by,created_at,updated_at)
         VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,NOW(),NOW())'
    );
    $stmt->execute([
        $groupConfigId,
        $optionType,
        $materialId,
        $optionDefinitionId,
        $numeric !== '' ? (float)$numeric : null,
        $text !== '' ? $text : null,
        $boolean,
        pa2_json_encode(['source' => 'workspace', 'saved_at' => date('Y-m-d H:i:s')]),
        !empty($input['is_default']) ? 1 : 0,
        !empty($input['is_alternative']) ? 1 : 0,
        (int)($input['sort_order'] ?? 100),
        $userId,
        $userId,
    ]);
    pa2_db()->prepare('UPDATE mc_pa2_product_group_configs SET status="configured",updated_by=?,updated_at=NOW() WHERE id=?')->execute([$userId,$groupConfigId]);
    pa2_log('workspace_group_save', 'product_group_config', $groupConfigId, [], ['option_type' => $optionType, 'material_id' => $materialId, 'option_definition_id' => $optionDefinitionId]);
    $savedOptionId = (int)pa2_db()->lastInsertId();
    if (pa2_engine_tables_ready()) {
        try {
            $productStmt = pa2_db()->prepare(
                'SELECT pc.product_id
                 FROM mc_pa2_product_group_configs pg
                 JOIN mc_pa2_product_config_versions v ON v.id=pg.product_config_version_id
                 JOIN mc_pa2_product_configs pc ON pc.id=v.product_config_id
                 WHERE pg.id=? LIMIT 1'
            );
            $productStmt->execute([$groupConfigId]);
            $productId = (int)$productStmt->fetchColumn();
            if ($productId > 0) pa2_calculate_workspace($productId, true);
        } catch (Throwable $e) {
            pa2_log('workspace_recalculate_after_save_failed', 'product_group_config', $groupConfigId, [], ['error' => $e->getMessage()]);
        }
    }
    return ['product_group_config_id' => $groupConfigId, 'saved_option_id' => $savedOptionId];
}

function pa2_material_detail(int $materialId): ?array
{
    if ($materialId <= 0 || !pa2_table_exists('mc_materials')) return null;
    $stmt = pa2_db()->prepare(
        "SELECT m.*, c.code AS category_code, c.name AS category_name, md.spec_summary, md.source_snapshot_json, md.confidence_score
         FROM mc_materials m
         JOIN mc_material_categories c ON c.id=m.category_id
         LEFT JOIN mc_material_metadata md ON md.material_id=m.id
         WHERE m.id=? AND m.deleted_at IS NULL LIMIT 1"
    );
    $stmt->execute([$materialId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) return null;
    $row['id'] = (int)$row['id'];
    $row['is_official'] = (int)($row['is_official'] ?? 0);
    $row['category_id'] = (int)($row['category_id'] ?? 0);
    $row['source_snapshot'] = pa2_json_decode_array($row['source_snapshot_json'] ?? '');
    unset($row['source_snapshot_json']);
    $category = (string)($row['category_code'] ?? '');
    $specTable = [
        'power_supply' => 'mc_power_supply_specs',
        'chip' => 'mc_material_chip',
        'optical' => 'mc_material_optical',
        'connector' => 'mc_material_connector',
        'accessory' => 'mc_material_accessory',
    ][$category] ?? '';
    $row['domain_spec'] = [];
    if ($specTable !== '' && pa2_table_exists($specTable)) {
        $specStmt = pa2_db()->prepare("SELECT * FROM `$specTable` WHERE material_id=? LIMIT 1");
        $specStmt->execute([$materialId]);
        $row['domain_spec'] = $specStmt->fetch(PDO::FETCH_ASSOC) ?: [];
    }
    if ($category === 'power_supply') {
        if (pa2_table_exists('mc_power_supply_current_options')) {
            $currentStmt = pa2_db()->prepare('SELECT current_ma,is_default FROM mc_power_supply_current_options WHERE material_id=? ORDER BY is_default DESC,current_ma');
            $currentStmt->execute([$materialId]);
            $row['current_options'] = $currentStmt->fetchAll(PDO::FETCH_ASSOC);
        } else {
            $row['current_options'] = [];
        }
        if (pa2_table_exists('mc_power_supply_dimming_modes')) {
            $dimmingStmt = pa2_db()->prepare('SELECT mode,is_primary FROM mc_power_supply_dimming_modes WHERE material_id=? ORDER BY is_primary DESC,mode');
            $dimmingStmt->execute([$materialId]);
            $row['dimming_modes'] = $dimmingStmt->fetchAll(PDO::FETCH_ASSOC);
        } else {
            $row['dimming_modes'] = [];
        }
    }
    return $row;
}

function pa2_flatten_text($value): string
{
    if (is_array($value)) {
        $parts = [];
        foreach ($value as $item) $parts[] = pa2_flatten_text($item);
        return implode(' ', array_filter($parts, static fn($part) => trim((string)$part) !== ''));
    }
    if (is_scalar($value)) return (string)$value;
    return '';
}

function pa2_extract_product_technical_range(array $product): array
{
    $snapshot = (array)($product['snapshot'] ?? []);
    $text = trim(implode(' ', [
        (string)($product['product_code'] ?? ''),
        (string)($product['product_name'] ?? ''),
        (string)($product['category_name'] ?? ''),
        (string)($product['series_code'] ?? ''),
        (string)($product['series_name'] ?? ''),
        pa2_flatten_text($snapshot),
    ]));
    $range = [
        'source' => 'product_snapshot',
        'power_values_w' => [],
        'power_min_w' => null,
        'power_max_w' => null,
        'current_values_ma' => [],
        'current_min_ma' => null,
        'current_max_ma' => null,
        'beam_angle_values' => [],
        'beam_angle_min' => null,
        'beam_angle_max' => null,
        'cct_values_k' => [],
        'cri_min' => null,
        'ip_rating' => null,
        'track_system' => stripos($text, 'INTRACK') !== false ? 'intrack' : null,
        'raw_text_hash' => hash('sha256', mb_substr($text, 0, 4000)),
    ];
    if (preg_match_all('/(?<![\d.])(\d+(?:\.\d+)?)\s*W\b/iu', $text, $matches)) {
        foreach ($matches[1] as $value) {
            $float = (float)$value;
            if ($float > 0 && $float <= 1000) $range['power_values_w'][] = $float;
        }
    }
    if ($range['power_values_w']) {
        $range['power_min_w'] = min($range['power_values_w']);
        $range['power_max_w'] = max($range['power_values_w']);
    }
    if (preg_match_all('/(?<![\d.])(\d+(?:\.\d+)?)\s*mA\b/iu', $text, $matches)) {
        foreach ($matches[1] as $value) {
            $float = (float)$value;
            if ($float > 0 && $float <= 10000) $range['current_values_ma'][] = $float;
        }
    }
    if ($range['current_values_ma']) {
        $range['current_min_ma'] = min($range['current_values_ma']);
        $range['current_max_ma'] = max($range['current_values_ma']);
    }
    if (preg_match_all('/(?<![\d.])(\d+(?:\.\d+)?)\s*(?:°|度)/u', $text, $matches)) {
        foreach ($matches[1] as $value) {
            $float = (float)$value;
            if ($float > 0 && $float <= 180) $range['beam_angle_values'][] = $float;
        }
    }
    if ($range['beam_angle_values']) {
        $range['beam_angle_min'] = min($range['beam_angle_values']);
        $range['beam_angle_max'] = max($range['beam_angle_values']);
    }
    if (preg_match_all('/(?<!\d)(\d{4})\s*K\b/iu', $text, $matches)) {
        foreach ($matches[1] as $value) {
            $int = (int)$value;
            if ($int >= 1800 && $int <= 8000) $range['cct_values_k'][] = $int;
        }
    }
    if (preg_match('/(?:CRI|Ra)\s*[≥>:：]?\s*(\d+(?:\.\d+)?)/iu', $text, $match)) {
        $range['cri_min'] = (float)$match[1];
    }
    if (preg_match('/\bIP\s*([0-9]{2})\b/iu', $text, $match)) {
        $range['ip_rating'] = 'IP' . $match[1];
    }
    return $range;
}

function pa2_result(string $status, float $score, array $reasons, array $fields = [], array $trace = []): array
{
    $labels = [
        'full_match' => '完全适配',
        'conditional_match' => '条件适配',
        'approval_required' => '需要审批',
        'incompatible' => '不适配',
    ];
    if (!isset($labels[$status])) $status = 'conditional_match';
    return [
        'result_status' => $status,
        'status_label' => $labels[$status],
        'match_score' => max(0, min(100, round($score, 2))),
        'reasons' => array_values(array_filter($reasons, static fn($reason) => trim((string)$reason) !== '')),
        'conflict_fields' => array_values(array_unique(array_filter($fields))),
        'rule_trace' => $trace,
    ];
}

function pa2_worst_result_status(array $results): string
{
    $rank = ['full_match' => 1, 'conditional_match' => 2, 'approval_required' => 3, 'incompatible' => 4];
    $worst = 'full_match';
    foreach ($results as $result) {
        $status = (string)($result['result_status'] ?? 'conditional_match');
        if (($rank[$status] ?? 2) > ($rank[$worst] ?? 1)) $worst = $status;
    }
    return $worst;
}

function pa2_candidate_status_for_group(array $group, ?array $candidate, array $context = []): array
{
    $settings = (array)($group['effective_settings'] ?? []);
    $behavior = [
        'selection_kind' => (string)($group['selection_kind'] ?? ($settings['behavior']['selection_kind'] ?? '')),
        'source_mode' => (string)($group['source_mode'] ?? ($settings['behavior']['source_mode'] ?? '')),
        'material_category_code' => (string)($group['material_category_code'] ?? ($settings['behavior']['material_category_code'] ?? '')),
        'material_filter' => pa2_json_decode_array($group['material_filter_json'] ?? ($settings['behavior']['material_filter_json'] ?? '')),
        'validation' => pa2_json_decode_array($group['validation_json'] ?? ($settings['behavior']['validation_json'] ?? '')),
    ];
    if (isset($settings['behavior']) && is_array($settings['behavior'])) {
        $behavior = array_merge($behavior, $settings['behavior']);
        if (isset($settings['behavior']['material_filter'])) $behavior['material_filter'] = (array)$settings['behavior']['material_filter'];
        if (isset($settings['behavior']['validation'])) $behavior['validation'] = (array)$settings['behavior']['validation'];
    }
    $required = !empty($settings['is_required']) || !empty($settings['is_required_default']) || !empty($behavior['is_required_default']);
    $groupCode = (string)($group['group_code'] ?? '');
    $technical = (array)($context['technical_range'] ?? []);
    $selection = (array)($context['selection'] ?? []);
    $type = (string)($selection['option_type'] ?? ($candidate ? 'material' : ''));

    if (!$candidate && $type === 'attribute') {
        return pa2_result('full_match', 100, ['属性选项已选择，符合当前配置组要求。'], [], ['engine' => 'phase6_attribute']);
    }
    if (!$candidate && in_array($type, ['number','text','boolean'], true)) {
        return pa2_result('conditional_match', 82, ['人工输入项已保存，后续审批阶段可确认取值。'], ['manual_value'], ['engine' => 'phase6_manual']);
    }
    if (!$candidate) {
        return pa2_result($required ? 'incompatible' : 'conditional_match', $required ? 0 : 60, [$required ? '必选配置组尚未选择候选，无法完成适配。' : '可选配置组未选择候选，不阻断草稿保存。'], ['selection'], ['engine' => 'phase6_missing']);
    }

    $material = array_key_exists('domain_spec', $candidate) ? $candidate : pa2_material_detail((int)($candidate['id'] ?? 0));
    if (!$material) {
        return pa2_result('incompatible', 0, ['候选物料不存在或已删除。'], ['material_id'], ['engine' => 'phase6_missing_material']);
    }

    $category = (string)($material['category_code'] ?? '');
    $expectedCategory = trim((string)($behavior['material_category_code'] ?? ''));
    $spec = (array)($material['domain_spec'] ?? []);
    $filter = (array)($behavior['material_filter'] ?? []);
    $text = mb_strtolower(pa2_flatten_text([
        $material['material_code'] ?? '',
        $material['name'] ?? '',
        $material['brand'] ?? '',
        $material['model'] ?? '',
        $material['spec_summary'] ?? '',
        $spec,
        $material['source_snapshot'] ?? [],
    ]));
    $score = 100.0;
    $status = 'full_match';
    $reasons = [];
    $fields = [];
    $trace = ['engine' => 'phase6_material', 'group_code' => $groupCode, 'checks' => []];

    if ($expectedCategory !== '' && $category !== $expectedCategory) {
        return pa2_result('incompatible', 20, ["物料分类为 {$category}，配置组要求 {$expectedCategory}。"], ['material_category_code'], $trace + ['failed' => 'category']);
    }
    $reasons[] = $expectedCategory !== '' ? "物料分类 {$category} 符合配置组要求。" : '配置组未限制物料分类。';

    if (($filter['formal_status'] ?? '') === 'official' && (int)($material['is_official'] ?? 0) !== 1 && (string)($material['status'] ?? '') !== 'official') {
        $status = 'approval_required';
        $score = min($score, 68);
        $fields[] = 'is_official';
        $reasons[] = '候选不是正式物料，需要例外审批后才能进入正式配置。';
    }

    $keyword = trim((string)($filter['keyword'] ?? ''));
    if ($keyword !== '' && mb_stripos($text, mb_strtolower($keyword)) === false) {
        $status = $status === 'full_match' ? 'conditional_match' : $status;
        $score = min($score, 72);
        $fields[] = 'material_filter.keyword';
        $reasons[] = "未命中规则关键词“{$keyword}”，需要人工确认是否适用。";
    }

    if ($category === 'power_supply') {
        $minPower = isset($spec['min_output_power_w']) && $spec['min_output_power_w'] !== null ? (float)$spec['min_output_power_w'] : 0.0;
        $maxPower = null;
        foreach (['max_output_power_w','nominal_power_w'] as $key) {
            if (isset($spec[$key]) && $spec[$key] !== null && (float)$spec[$key] > 0) {
                $maxPower = (float)$spec[$key];
                break;
            }
        }
        $productMax = $technical['power_max_w'] ?? null;
        $productMin = $technical['power_min_w'] ?? null;
        if ($productMax !== null && $maxPower !== null) {
            if ((float)$productMax > $maxPower) {
                return pa2_result('incompatible', 25, ["产品最大功率 {$productMax}W 高于电源可输出 {$maxPower}W。"], ['power_max_w','max_output_power_w'], $trace + ['failed' => 'power_range']);
            }
            if ($productMin !== null && $minPower > 0 && (float)$productMin < $minPower) {
                $status = $status === 'full_match' ? 'conditional_match' : $status;
                $score = min($score, 78);
                $fields[] = 'min_output_power_w';
                $reasons[] = "产品功率下限 {$productMin}W 低于电源最低输出 {$minPower}W，需确认是否可稳定工作。";
            } else {
                $reasons[] = "电源输出功率覆盖产品需求（产品最高 {$productMax}W，电源最高 {$maxPower}W）。";
            }
        } else {
            $status = $status === 'full_match' ? 'conditional_match' : $status;
            $score = min($score, 76);
            $fields[] = $productMax === null ? 'product.power' : 'power.max_output_power_w';
            $reasons[] = $productMax === null ? '产品缺少功率范围，暂按条件适配处理。' : '电源缺少最大输出功率，暂按条件适配处理。';
        }
        $productCurrent = $technical['current_max_ma'] ?? null;
        $currentMin = isset($spec['output_current_min_ma']) && $spec['output_current_min_ma'] !== null ? (float)$spec['output_current_min_ma'] : null;
        $currentMax = isset($spec['output_current_max_ma']) && $spec['output_current_max_ma'] !== null ? (float)$spec['output_current_max_ma'] : null;
        if ($productCurrent !== null && $currentMin !== null && $currentMax !== null) {
            if ((float)$productCurrent < $currentMin || (float)$productCurrent > $currentMax) {
                return pa2_result('incompatible', 30, ["产品电流 {$productCurrent}mA 不在电源输出 {$currentMin}-{$currentMax}mA 范围内。"], ['current_ma','output_current_range'], $trace + ['failed' => 'current_range']);
            }
            $reasons[] = "输出电流范围覆盖产品需求（{$currentMin}-{$currentMax}mA）。";
        }
        $driverType = (string)($filter['driver_type'] ?? '');
        if ($driverType === 'intrack' && mb_stripos($text, 'intrack') === false) {
            $status = $status === 'full_match' ? 'conditional_match' : $status;
            $score = min($score, 74);
            $fields[] = 'driver_type';
            $reasons[] = '规则要求 INTRACK 电源，但候选规格未明确标注 INTRACK。';
        }
        if ($driverType === 'external' && !preg_match('/external|外置/u', $text)) {
            $status = $status === 'full_match' ? 'conditional_match' : $status;
            $score = min($score, 80);
            $fields[] = 'installation_type';
            $reasons[] = '规则要求外置电源，候选安装方式未明确。';
        }
    } elseif ($category === 'chip') {
        $productPower = $technical['power_max_w'] ?? null;
        $chipMax = null;
        foreach (['max_power_w','rated_power_w'] as $key) {
            if (isset($spec[$key]) && $spec[$key] !== null && (float)$spec[$key] > 0) {
                $chipMax = (float)$spec[$key];
                break;
            }
        }
        if ($productPower !== null && $chipMax !== null) {
            if ((float)$productPower > $chipMax * 1.15) {
                return pa2_result('incompatible', 35, ["产品功率 {$productPower}W 明显高于芯片额定/最大 {$chipMax}W。"], ['power_max_w','chip.max_power_w'], $trace + ['failed' => 'chip_power']);
            }
            $reasons[] = "芯片功率与产品功率区间基本匹配（产品 {$productPower}W，芯片 {$chipMax}W）。";
        } else {
            $status = $status === 'full_match' ? 'conditional_match' : $status;
            $score = min($score, 78);
            $fields[] = $productPower === null ? 'product.power' : 'chip.power';
            $reasons[] = '产品或芯片功率资料不完整，需按条件适配确认。';
        }
        if (($technical['cct_values_k'] ?? []) && isset($spec['cct_min_k'],$spec['cct_max_k']) && $spec['cct_min_k'] !== null && $spec['cct_max_k'] !== null) {
            foreach ((array)$technical['cct_values_k'] as $cct) {
                if ($cct < (int)$spec['cct_min_k'] || $cct > (int)$spec['cct_max_k']) {
                    return pa2_result('incompatible', 45, ["产品色温 {$cct}K 不在芯片色温范围 {$spec['cct_min_k']}-{$spec['cct_max_k']}K。"], ['cct'], $trace + ['failed' => 'cct']);
                }
            }
            $reasons[] = '色温范围匹配。';
        }
    } elseif ($category === 'optical') {
        $beam = $technical['beam_angle_max'] ?? null;
        if ($beam !== null && isset($spec['beam_angle_min'],$spec['beam_angle_max']) && $spec['beam_angle_min'] !== null && $spec['beam_angle_max'] !== null) {
            if ((float)$beam < (float)$spec['beam_angle_min'] || (float)$beam > (float)$spec['beam_angle_max']) {
                return pa2_result('incompatible', 38, ["产品光束角 {$beam}° 不在光学范围 {$spec['beam_angle_min']}-{$spec['beam_angle_max']}°。"], ['beam_angle'], $trace + ['failed' => 'beam_angle']);
            }
            $reasons[] = "光束角范围匹配（{$spec['beam_angle_min']}-{$spec['beam_angle_max']}°）。";
        } else {
            $status = $status === 'full_match' ? 'conditional_match' : $status;
            $score = min($score, 80);
            $fields[] = 'beam_angle';
            $reasons[] = '产品或光学物料缺少光束角范围，需人工确认。';
        }
    } elseif (in_array($category, ['connector','accessory'], true)) {
        $trackSystem = (string)($filter['track_system'] ?? ($filter['system'] ?? ''));
        if ($trackSystem !== '' && mb_stripos($text, mb_strtolower($trackSystem)) === false) {
            $status = $status === 'full_match' ? 'conditional_match' : $status;
            $score = min($score, 76);
            $fields[] = 'system_type';
            $reasons[] = "规则要求系统 {$trackSystem}，候选规格未明确标注。";
        } else {
            $reasons[] = '接头/配件系统字段与当前规则没有明显冲突。';
        }
    } else {
        $status = $status === 'full_match' ? 'conditional_match' : $status;
        $score = min($score, 82);
        $reasons[] = '该类别暂无专用适配器，按通用分类和正式状态检查。';
    }

    if ($status === 'full_match' && $score < 90) $status = 'conditional_match';
    return pa2_result($status, $score, $reasons, $fields, $trace);
}

function pa2_engine_summary_from_results(array $results): array
{
    $summary = [
        'full_match' => 0,
        'conditional_match' => 0,
        'approval_required' => 0,
        'incompatible' => 0,
        'candidate_total' => 0,
        'average_score' => 0,
        'last_calculated_at' => null,
    ];
    $scoreSum = 0.0;
    foreach ($results as $result) {
        $status = (string)($result['result_status'] ?? 'conditional_match');
        if (!isset($summary[$status])) $status = 'conditional_match';
        $summary[$status]++;
        $summary['candidate_total']++;
        $scoreSum += (float)($result['match_score'] ?? 0);
        $calculatedAt = (string)($result['calculated_at'] ?? '');
        if ($calculatedAt !== '' && ($summary['last_calculated_at'] === null || $calculatedAt > $summary['last_calculated_at'])) {
            $summary['last_calculated_at'] = $calculatedAt;
        }
    }
    if ($summary['candidate_total'] > 0) {
        $summary['average_score'] = round($scoreSum / $summary['candidate_total'], 2);
    }
    return $summary;
}

function pa2_cached_results_for_version(int $versionId): array
{
    if ($versionId <= 0 || !pa2_engine_tables_ready()) return [];
    $stmt = pa2_db()->prepare('SELECT * FROM mc_pa2_adaptation_result_cache WHERE product_config_version_id=? ORDER BY product_group_config_id,id');
    $stmt->execute([$versionId]);
    $byGroup = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $row['id'] = (int)$row['id'];
        $row['product_group_config_id'] = (int)$row['product_group_config_id'];
        $row['material_id'] = $row['material_id'] === null ? null : (int)$row['material_id'];
        $row['option_definition_id'] = $row['option_definition_id'] === null ? null : (int)$row['option_definition_id'];
        $row['match_score'] = (float)$row['match_score'];
        $row['reasons'] = pa2_json_decode_array($row['reason_json'] ?? '');
        $row['conflict_fields'] = pa2_json_decode_array($row['conflict_fields_json'] ?? '');
        $row['rule_trace'] = pa2_json_decode_array($row['rule_trace_json'] ?? '');
        $byGroup[(int)$row['product_group_config_id']][] = $row;
    }
    return $byGroup;
}

function pa2_flat_cached_results(array $byGroup): array
{
    $rows = [];
    foreach ($byGroup as $groupRows) {
        foreach ($groupRows as $row) $rows[] = $row;
    }
    return $rows;
}

function pa2_persist_adaptation_result(int $versionId, array $group, ?array $selection, array $result): void
{
    $candidateType = (string)($selection['option_type'] ?? 'group');
    $materialId = isset($selection['material_id']) && $selection['material_id'] !== null ? (int)$selection['material_id'] : null;
    $optionDefinitionId = isset($selection['option_definition_id']) && $selection['option_definition_id'] !== null ? (int)$selection['option_definition_id'] : null;
    $hash = hash('sha256', implode('|', [
        $versionId,
        (int)$group['id'],
        $candidateType,
        $materialId ?: 0,
        $optionDefinitionId ?: 0,
        (string)($selection['numeric_value'] ?? ''),
        (string)($selection['text_value'] ?? ''),
        (string)($selection['boolean_value'] ?? ''),
    ]));
    $stmt = pa2_db()->prepare(
        'INSERT INTO mc_pa2_adaptation_result_cache(product_config_version_id,product_group_config_id,group_code,candidate_type,material_id,option_definition_id,result_status,match_score,reason_json,conflict_fields_json,rule_trace_json,calculated_hash,calculated_at,created_at,updated_at)
         VALUES(?,?,?,?,?,?,?,?,?,?,?,?,NOW(),NOW(),NOW())
         ON DUPLICATE KEY UPDATE result_status=VALUES(result_status),match_score=VALUES(match_score),reason_json=VALUES(reason_json),conflict_fields_json=VALUES(conflict_fields_json),rule_trace_json=VALUES(rule_trace_json),calculated_at=NOW(),updated_at=NOW()'
    );
    $stmt->execute([
        $versionId,
        (int)$group['id'],
        (string)$group['group_code'],
        $candidateType,
        $materialId,
        $optionDefinitionId,
        (string)$result['result_status'],
        (float)$result['match_score'],
        pa2_json_encode($result['reasons'] ?? []),
        pa2_json_encode($result['conflict_fields'] ?? []),
        pa2_json_encode($result['rule_trace'] ?? []),
        $hash,
    ]);
    if (($result['result_status'] ?? '') !== 'full_match') {
        $level = ['conditional_match' => 'warning', 'approval_required' => 'approval_required', 'incompatible' => 'block'][(string)$result['result_status']] ?? 'warning';
        $reason = implode('；', array_slice((array)($result['reasons'] ?? []), 0, 3));
        $conflictCode = ((string)$group['group_code']) . '_' . ((string)$result['result_status']);
        $conflict = pa2_db()->prepare(
            'INSERT INTO mc_pa2_adaptation_conflicts(product_config_version_id,product_group_config_id,group_code,conflict_code,conflict_level,result_status,material_id,option_definition_id,conflict_fields_json,reason_text,is_resolved,created_at,updated_at)
             VALUES(?,?,?,?,?,?,?,?,?,?,0,NOW(),NOW())'
        );
        $conflict->execute([
            $versionId,
            (int)$group['id'],
            (string)$group['group_code'],
            $conflictCode,
            $level,
            (string)$result['result_status'],
            $materialId,
            $optionDefinitionId,
            pa2_json_encode($result['conflict_fields'] ?? []),
            mb_substr($reason, 0, 650),
        ]);
    }
}

function pa2_calculate_workspace(int $productId, bool $persist = true): array
{
    if (!pa2_workspace_tables_ready()) throw new RuntimeException('V2 第 5 阶段工作台表尚未迁移。');
    if ($persist && !pa2_engine_tables_ready()) throw new RuntimeException('V2 第 6 阶段适配计算表尚未迁移。');
    $detail = pa2_workspace_detail($productId);
    $version = $detail['version'] ?? null;
    $versionId = (int)($version['id'] ?? 0);
    if (!$versionId) throw new RuntimeException('产品尚未生成 V2 配置草稿。');
    $product = (array)($detail['product'] ?? []);
    $technical = pa2_extract_product_technical_range($product);
    if ($persist) {
        pa2_db()->prepare('DELETE FROM mc_pa2_adaptation_result_cache WHERE product_config_version_id=?')->execute([$versionId]);
        pa2_db()->prepare('DELETE FROM mc_pa2_adaptation_conflicts WHERE product_config_version_id=? AND is_resolved=0')->execute([$versionId]);
    }
    $results = [];
    foreach ((array)($detail['groups'] ?? []) as $group) {
        $groupResults = [];
        $selected = (array)($group['selected_options'] ?? []);
        if (!$selected) {
            $result = pa2_candidate_status_for_group($group, null, ['product' => $product, 'technical_range' => $technical]);
            $groupResults[] = $result;
            if ($persist) pa2_persist_adaptation_result($versionId, $group, null, $result);
        } else {
            foreach ($selected as $selection) {
                $material = ((string)($selection['option_type'] ?? '') === 'material' && (int)($selection['material_id'] ?? 0) > 0)
                    ? pa2_material_detail((int)$selection['material_id'])
                    : null;
                $result = pa2_candidate_status_for_group($group, $material, [
                    'product' => $product,
                    'technical_range' => $technical,
                    'selection' => $selection,
                ]);
                $groupResults[] = $result;
                if ($persist) pa2_persist_adaptation_result($versionId, $group, $selection, $result);
            }
        }
        $status = pa2_worst_result_status($groupResults);
        if ($persist) {
            $groupStatus = ['full_match' => 'configured', 'conditional_match' => 'conditional', 'approval_required' => 'approval_required', 'incompatible' => 'blocked'][$status] ?? 'conditional';
            pa2_db()->prepare('UPDATE mc_pa2_product_group_configs SET status=?,updated_at=NOW() WHERE id=?')->execute([$groupStatus,(int)$group['id']]);
        }
        foreach ($groupResults as $result) {
            $result['group_code'] = (string)$group['group_code'];
            $result['group_name'] = (string)$group['display_name'];
            $results[] = $result;
        }
    }
    $engine = pa2_engine_summary_from_results($results);
    $summary = pa2_workspace_check_summary((array)($detail['groups'] ?? []));
    $summary['engine'] = $engine;
    $summary['technical_range'] = $technical;
    if ($persist) {
        pa2_db()->prepare('UPDATE mc_pa2_product_config_versions SET technical_range_json=?,check_summary_json=? WHERE id=?')->execute([pa2_json_encode($technical),pa2_json_encode($summary),$versionId]);
        pa2_log('workspace_recalculate', 'product_config_version', $versionId, [], ['product_id' => $productId, 'engine' => $engine]);
    }
    return [
        'product_id' => $productId,
        'product_config_version_id' => $versionId,
        'technical_range' => $technical,
        'summary' => $summary,
        'results' => $results,
    ];
}

function pa2_recalculate_workspace(int $productId, string $reason = 'manual'): array
{
    pa2_require_any(['adaptation_v2.configure_product', 'material_center.adaptation.manage'], '没有重新计算产品适配的权限。');
    if (!pa2_engine_tables_ready()) throw new RuntimeException('V2 第 6 阶段适配计算表尚未迁移。');
    $detail = pa2_workspace_detail($productId);
    $versionId = (int)($detail['version']['id'] ?? 0);
    if ($versionId <= 0) throw new RuntimeException('产品尚未生成 V2 配置草稿。');
    $userId = pa2_current_user_id();
    $jobStmt = pa2_db()->prepare(
        'INSERT INTO mc_pa2_adaptation_recalc_jobs(product_config_version_id,status,request_reason,requested_by,created_at,updated_at)
         VALUES(?,"running",?,?,NOW(),NOW())'
    );
    $jobStmt->execute([$versionId, mb_substr($reason, 0, 220), $userId]);
    $jobId = (int)pa2_db()->lastInsertId();
    try {
        pa2_db()->prepare('UPDATE mc_pa2_adaptation_recalc_jobs SET started_at=NOW(),updated_at=NOW() WHERE id=?')->execute([$jobId]);
        $result = pa2_calculate_workspace($productId, true);
        pa2_db()->prepare('UPDATE mc_pa2_adaptation_recalc_jobs SET status="done",summary_json=?,finished_at=NOW(),updated_at=NOW() WHERE id=?')->execute([pa2_json_encode($result['summary'] ?? []),$jobId]);
        $result['job_id'] = $jobId;
        return $result;
    } catch (Throwable $e) {
        pa2_db()->prepare('UPDATE mc_pa2_adaptation_recalc_jobs SET status="failed",summary_json=?,finished_at=NOW(),updated_at=NOW() WHERE id=?')->execute([pa2_json_encode(['error' => $e->getMessage()]),$jobId]);
        throw $e;
    }
}

function pa2_version_event(int $configId, int $versionId, string $eventType, ?string $fromStatus, ?string $toStatus, string $note = '', array $payload = []): void
{
    if (!pa2_phase7_tables_ready()) return;
    $stmt = pa2_db()->prepare(
        'INSERT INTO mc_pa2_product_version_events(product_config_id,product_config_version_id,event_type,from_status,to_status,actor_user_id,note,payload_json,created_at)
         VALUES(?,?,?,?,?,?,?,?,NOW())'
    );
    $stmt->execute([$configId,$versionId,$eventType,$fromStatus,$toStatus,pa2_current_user_id(),mb_substr($note, 0, 680),pa2_json_encode($payload)]);
}

function pa2_fetch_config_by_product(int $productId): ?array
{
    $stmt = pa2_db()->prepare('SELECT * FROM mc_pa2_product_configs WHERE product_id=? LIMIT 1');
    $stmt->execute([$productId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) return null;
    foreach (['id','product_id','active_draft_version_id','active_published_version_id','source_template_id'] as $key) {
        if (array_key_exists($key, $row)) $row[$key] = $row[$key] === null ? null : (int)$row[$key];
    }
    return $row;
}

function pa2_fetch_version(int $versionId): ?array
{
    if ($versionId <= 0) return null;
    $stmt = pa2_db()->prepare('SELECT * FROM mc_pa2_product_config_versions WHERE id=? LIMIT 1');
    $stmt->execute([$versionId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) return null;
    foreach (['id','product_config_id','source_template_id','source_template_version_id'] as $key) {
        if (array_key_exists($key, $row)) $row[$key] = $row[$key] === null ? null : (int)$row[$key];
    }
    return $row;
}

function pa2_product_versions(int $productId): array
{
    $config = pa2_fetch_config_by_product($productId);
    if (!$config) return ['config' => null, 'versions' => [], 'events' => []];
    $stmt = pa2_db()->prepare('SELECT * FROM mc_pa2_product_config_versions WHERE product_config_id=? ORDER BY id DESC');
    $stmt->execute([(int)$config['id']]);
    $versions = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($versions as &$version) {
        $version['id'] = (int)$version['id'];
        $version['product_config_id'] = (int)$version['product_config_id'];
        $version['is_active_draft'] = (int)($config['active_draft_version_id'] ?? 0) === (int)$version['id'];
        $version['is_active_published'] = (int)($config['active_published_version_id'] ?? 0) === (int)$version['id'];
    }
    unset($version);
    $events = [];
    if (pa2_phase7_tables_ready()) {
        $eventStmt = pa2_db()->prepare('SELECT * FROM mc_pa2_product_version_events WHERE product_config_id=? ORDER BY id DESC LIMIT 80');
        $eventStmt->execute([(int)$config['id']]);
        $events = $eventStmt->fetchAll(PDO::FETCH_ASSOC);
    }
    return ['config' => $config, 'versions' => $versions, 'events' => $events];
}

function pa2_build_version_snapshot(int $versionId): array
{
    $version = pa2_fetch_version($versionId);
    if (!$version) throw new RuntimeException('产品配置版本不存在。');
    $configStmt = pa2_db()->prepare('SELECT pc.*, p.product_code, p.product_name FROM mc_pa2_product_configs pc JOIN mc_products p ON p.id=pc.product_id WHERE pc.id=? LIMIT 1');
    $configStmt->execute([(int)$version['product_config_id']]);
    $config = $configStmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $groupsStmt = pa2_db()->prepare(
        "SELECT pg.*, gd.group_name, gd.group_type AS definition_type
         FROM mc_pa2_product_group_configs pg
         LEFT JOIN mc_pa2_group_definitions gd ON gd.id=pg.group_definition_id
         WHERE pg.product_config_version_id=?
         ORDER BY pg.sort_order,pg.id"
    );
    $groupsStmt->execute([$versionId]);
    $groups = $groupsStmt->fetchAll(PDO::FETCH_ASSOC);
    $selectedByGroup = [];
    if ($groups) {
        $ids = array_map(static fn($g) => (int)$g['id'], $groups);
        $marks = implode(',', array_fill(0, count($ids), '?'));
        $selectedStmt = pa2_db()->prepare(
            "SELECT so.*, m.material_code,m.name AS material_name,o.option_code,o.option_name
             FROM mc_pa2_product_selected_options so
             LEFT JOIN mc_materials m ON m.id=so.material_id
             LEFT JOIN mc_pa2_group_option_definitions o ON o.id=so.option_definition_id
             WHERE so.product_group_config_id IN ($marks)
             ORDER BY so.product_group_config_id,so.sort_order,so.id"
        );
        $selectedStmt->execute($ids);
        foreach ($selectedStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $selectedByGroup[(int)$row['product_group_config_id']][] = [
                'option_type' => (string)$row['option_type'],
                'material_id' => $row['material_id'] === null ? null : (int)$row['material_id'],
                'material_code' => $row['material_code'] ?? null,
                'material_name' => $row['material_name'] ?? null,
                'option_definition_id' => $row['option_definition_id'] === null ? null : (int)$row['option_definition_id'],
                'option_code' => $row['option_code'] ?? null,
                'option_name' => $row['option_name'] ?? null,
                'numeric_value' => $row['numeric_value'] === null ? null : (float)$row['numeric_value'],
                'text_value' => $row['text_value'] ?? null,
                'boolean_value' => $row['boolean_value'] === null ? null : (int)$row['boolean_value'],
                'is_default' => (int)$row['is_default'],
                'is_alternative' => (int)$row['is_alternative'],
            ];
        }
    }
    $snapshotGroups = [];
    foreach ($groups as $group) {
        $snapshotGroups[] = [
            'group_code' => (string)$group['group_code'],
            'display_name' => (string)($group['display_name'] ?: $group['group_name']),
            'group_type' => (string)($group['group_type'] ?: $group['definition_type']),
            'status' => (string)$group['status'],
            'settings' => pa2_json_decode_array($group['effective_settings_json'] ?? ''),
            'selected_options' => $selectedByGroup[(int)$group['id']] ?? [],
        ];
    }
    return [
        'product_config_id' => (int)$version['product_config_id'],
        'product_id' => (int)($config['product_id'] ?? 0),
        'product_code' => (string)($config['product_code'] ?? ''),
        'product_name' => (string)($config['product_name'] ?? ''),
        'version_id' => (int)$version['id'],
        'version_no' => (string)$version['version_no'],
        'status' => (string)$version['status'],
        'source_template_id' => $version['source_template_id'],
        'configuration_snapshot' => pa2_json_decode_array($version['configuration_snapshot_json'] ?? ''),
        'technical_range' => pa2_json_decode_array($version['technical_range_json'] ?? ''),
        'check_summary' => pa2_json_decode_array($version['check_summary_json'] ?? ''),
        'groups' => $snapshotGroups,
    ];
}

function pa2_store_version_snapshot(int $versionId, string $snapshotType): array
{
    if (!pa2_phase7_tables_ready()) throw new RuntimeException('V2 第 7 阶段版本表尚未迁移。');
    $snapshot = pa2_build_version_snapshot($versionId);
    $hash = hash('sha256', pa2_json_encode($snapshot));
    $stmt = pa2_db()->prepare(
        'INSERT INTO mc_pa2_product_version_snapshots(product_config_id,product_config_version_id,snapshot_type,snapshot_json,snapshot_hash,created_by,created_at)
         VALUES(?,?,?,?,?,?,NOW())
         ON DUPLICATE KEY UPDATE created_at=created_at'
    );
    $stmt->execute([(int)$snapshot['product_config_id'],$versionId,$snapshotType,pa2_json_encode($snapshot),$hash,pa2_current_user_id()]);
    return ['snapshot' => $snapshot, 'snapshot_hash' => $hash];
}

function pa2_compare_version_snapshots(?array $base, array $compare): array
{
    $baseGroups = [];
    foreach ((array)($base['groups'] ?? []) as $group) $baseGroups[(string)$group['group_code']] = $group;
    $compareGroups = [];
    foreach ((array)($compare['groups'] ?? []) as $group) $compareGroups[(string)$group['group_code']] = $group;
    $changes = [];
    foreach ($compareGroups as $code => $group) {
        if (!isset($baseGroups[$code])) {
            $changes[] = ['type' => 'group_added', 'group_code' => $code, 'label' => $group['display_name'] ?? $code];
            continue;
        }
        $old = pa2_json_encode($baseGroups[$code]['selected_options'] ?? []);
        $new = pa2_json_encode($group['selected_options'] ?? []);
        if ($old !== $new) {
            $changes[] = ['type' => 'selection_changed', 'group_code' => $code, 'label' => $group['display_name'] ?? $code, 'before' => $baseGroups[$code]['selected_options'] ?? [], 'after' => $group['selected_options'] ?? []];
        }
    }
    foreach ($baseGroups as $code => $group) {
        if (!isset($compareGroups[$code])) $changes[] = ['type' => 'group_removed', 'group_code' => $code, 'label' => $group['display_name'] ?? $code];
    }
    return [
        'base_version_id' => $base['version_id'] ?? null,
        'compare_version_id' => $compare['version_id'] ?? null,
        'change_count' => count($changes),
        'changes' => $changes,
    ];
}

function pa2_store_version_diff(int $configId, ?int $baseVersionId, int $compareVersionId): array
{
    if (!pa2_phase7_tables_ready()) throw new RuntimeException('V2 第 7 阶段版本表尚未迁移。');
    $base = $baseVersionId ? pa2_build_version_snapshot($baseVersionId) : null;
    $compare = pa2_build_version_snapshot($compareVersionId);
    $diff = pa2_compare_version_snapshots($base, $compare);
    $hash = hash('sha256', pa2_json_encode($diff));
    $stmt = pa2_db()->prepare(
        'INSERT INTO mc_pa2_product_version_diffs(product_config_id,base_version_id,compare_version_id,diff_json,diff_hash,created_by,created_at)
         VALUES(?,?,?,?,?,?,NOW())
         ON DUPLICATE KEY UPDATE diff_json=VALUES(diff_json),created_at=NOW()'
    );
    $stmt->execute([$configId,$baseVersionId,$compareVersionId,pa2_json_encode($diff),$hash,pa2_current_user_id()]);
    return $diff;
}

function pa2_next_product_version_no(int $configId): string
{
    $stmt = pa2_db()->prepare("SELECT version_no FROM mc_pa2_product_config_versions WHERE product_config_id=? AND version_no REGEXP '^V[0-9]+$' ORDER BY CAST(SUBSTRING(version_no,2) AS UNSIGNED) DESC LIMIT 1");
    $stmt->execute([$configId]);
    $last = (string)($stmt->fetchColumn() ?: '');
    $next = $last !== '' ? ((int)substr($last, 1) + 1) : 1;
    return 'V' . $next;
}

function pa2_next_draft_version_no(int $configId): string
{
    $stmt = pa2_db()->prepare("SELECT COUNT(*) FROM mc_pa2_product_config_versions WHERE product_config_id=? AND status='draft'");
    $stmt->execute([$configId]);
    return 'draft-' . ((int)$stmt->fetchColumn() + 1) . '-' . date('YmdHis');
}

function pa2_clone_version_as_draft(int $sourceVersionId, int $configId, string $reason = 'edit_after_publish'): int
{
    $source = pa2_fetch_version($sourceVersionId);
    if (!$source) throw new RuntimeException('来源版本不存在，无法生成新草稿。');
    $userId = pa2_current_user_id();
    $versionNo = pa2_next_draft_version_no($configId);
    $snapshot = pa2_build_version_snapshot($sourceVersionId);
    $insert = pa2_db()->prepare(
        'INSERT INTO mc_pa2_product_config_versions(product_config_id,version_no,source_template_id,source_template_version_id,status,configuration_snapshot_json,technical_range_json,check_summary_json,created_by,created_at)
         VALUES(?,?,?,?, "draft", ?, ?, ?, ?, NOW())'
    );
    $insert->execute([
        $configId,
        $versionNo,
        $source['source_template_id'] ?? null,
        $source['source_template_version_id'] ?? null,
        pa2_json_encode(['source' => 'clone', 'source_version_id' => $sourceVersionId, 'reason' => $reason, 'cloned_at' => date('Y-m-d H:i:s')]),
        $source['technical_range_json'] ?? null,
        $source['check_summary_json'] ?? null,
        $userId,
    ]);
    $newVersionId = (int)pa2_db()->lastInsertId();
    $groupIdMap = [];
    $groupsStmt = pa2_db()->prepare('SELECT * FROM mc_pa2_product_group_configs WHERE product_config_version_id=? ORDER BY sort_order,id');
    $groupsStmt->execute([$sourceVersionId]);
    foreach ($groupsStmt->fetchAll(PDO::FETCH_ASSOC) as $group) {
        $stmt = pa2_db()->prepare(
            'INSERT INTO mc_pa2_product_group_configs(product_config_version_id,group_code,group_definition_id,display_name,group_type,effective_settings_json,status,is_overridden,override_source,sort_order,created_by,updated_by,created_at,updated_at)
             VALUES(?,?,?,?,?,?,?,?,?,?,?,?,NOW(),NOW())'
        );
        $stmt->execute([
            $newVersionId,
            $group['group_code'],
            $group['group_definition_id'],
            $group['display_name'],
            $group['group_type'],
            $group['effective_settings_json'],
            'missing',
            $group['is_overridden'],
            'version_clone',
            $group['sort_order'],
            $userId,
            $userId,
        ]);
        $groupIdMap[(int)$group['id']] = (int)pa2_db()->lastInsertId();
    }
    if ($groupIdMap) {
        $ids = array_keys($groupIdMap);
        $marks = implode(',', array_fill(0, count($ids), '?'));
        $selectedStmt = pa2_db()->prepare("SELECT * FROM mc_pa2_product_selected_options WHERE product_group_config_id IN ($marks) ORDER BY product_group_config_id,sort_order,id");
        $selectedStmt->execute($ids);
        $insertSelected = pa2_db()->prepare(
            'INSERT INTO mc_pa2_product_selected_options(product_group_config_id,option_type,material_id,option_definition_id,numeric_value,text_value,boolean_value,value_json,is_default,is_alternative,sort_order,created_by,updated_by,created_at,updated_at)
             VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,NOW(),NOW())'
        );
        foreach ($selectedStmt->fetchAll(PDO::FETCH_ASSOC) as $selected) {
            $insertSelected->execute([
                $groupIdMap[(int)$selected['product_group_config_id']],
                $selected['option_type'],
                $selected['material_id'],
                $selected['option_definition_id'],
                $selected['numeric_value'],
                $selected['text_value'],
                $selected['boolean_value'],
                $selected['value_json'],
                $selected['is_default'],
                $selected['is_alternative'],
                $selected['sort_order'],
                $userId,
                $userId,
            ]);
        }
    }
    pa2_db()->prepare('UPDATE mc_pa2_product_configs SET active_draft_version_id=?,status="draft",updated_by=?,updated_at=NOW() WHERE id=?')->execute([$newVersionId,$userId,$configId]);
    pa2_version_event($configId, $newVersionId, 'draft_created', null, 'draft', '从已发布版本生成新草稿', ['source_version_id' => $sourceVersionId, 'snapshot' => $snapshot['version_no'] ?? '']);
    return $newVersionId;
}

function pa2_product_version_submit(int $productId, string $note = ''): array
{
    pa2_require_any(['adaptation_v2.configure_product', 'material_center.adaptation.manage'], '没有提交产品配置的权限。');
    $detail = pa2_workspace_detail($productId);
    $config = (array)($detail['config'] ?? []);
    $version = (array)($detail['version'] ?? []);
    $configId = (int)($config['id'] ?? 0);
    $versionId = (int)($version['id'] ?? 0);
    if (!$configId || !$versionId) throw new RuntimeException('请先生成产品配置草稿。');
    if (!in_array((string)$version['status'], ['draft','rejected'], true)) throw new RuntimeException('只有草稿或驳回版本可以提交。');
    if (($detail['check_summary']['missing_required'] ?? 0) > 0) throw new RuntimeException('仍有必选配置未完成，不能提交审批。');
    if (pa2_engine_tables_ready()) {
        $calc = pa2_calculate_workspace($productId, true);
        if (($calc['summary']['engine']['incompatible'] ?? 0) > 0) throw new RuntimeException('存在不适配项，请处理后再提交审批。');
    }
    $before = (string)$version['status'];
    pa2_store_version_snapshot($versionId, 'submitted');
    pa2_store_version_diff($configId, (int)($config['active_published_version_id'] ?? 0) ?: null, $versionId);
    pa2_db()->prepare('UPDATE mc_pa2_product_config_versions SET status="submitted",submitted_by=?,submitted_at=NOW() WHERE id=?')->execute([pa2_current_user_id(),$versionId]);
    pa2_db()->prepare('UPDATE mc_pa2_product_configs SET status="submitted",updated_by=?,updated_at=NOW() WHERE id=?')->execute([pa2_current_user_id(),$configId]);
    pa2_version_event($configId, $versionId, 'submitted', $before, 'submitted', $note);
    return ['product_id' => $productId, 'version_id' => $versionId, 'status' => 'submitted'];
}

function pa2_product_version_approve(int $productId, string $note = ''): array
{
    pa2_require_any(['adaptation_v2.approve_product', 'material_center.adaptation.manage'], '没有审批产品配置的权限。');
    $detail = pa2_workspace_detail($productId);
    $config = (array)($detail['config'] ?? []);
    $version = (array)($detail['version'] ?? []);
    $configId = (int)($config['id'] ?? 0);
    $versionId = (int)($version['id'] ?? 0);
    if (!$versionId || (string)($version['status'] ?? '') !== 'submitted') throw new RuntimeException('只有已提交版本可以审批通过。');
    pa2_store_version_snapshot($versionId, 'approved');
    pa2_db()->prepare('UPDATE mc_pa2_product_config_versions SET status="approved",approved_by=?,approved_at=NOW() WHERE id=?')->execute([pa2_current_user_id(),$versionId]);
    pa2_db()->prepare('UPDATE mc_pa2_product_configs SET status="approved",updated_by=?,updated_at=NOW() WHERE id=?')->execute([pa2_current_user_id(),$configId]);
    pa2_version_event($configId, $versionId, 'approved', 'submitted', 'approved', $note);
    return ['product_id' => $productId, 'version_id' => $versionId, 'status' => 'approved'];
}

function pa2_product_version_reject(int $productId, string $note = ''): array
{
    pa2_require_any(['adaptation_v2.approve_product', 'material_center.adaptation.manage'], '没有驳回产品配置的权限。');
    $detail = pa2_workspace_detail($productId);
    $config = (array)($detail['config'] ?? []);
    $version = (array)($detail['version'] ?? []);
    $configId = (int)($config['id'] ?? 0);
    $versionId = (int)($version['id'] ?? 0);
    if (!$versionId || !in_array((string)($version['status'] ?? ''), ['submitted','approved'], true)) throw new RuntimeException('只有已提交或已审批版本可以驳回。');
    $from = (string)$version['status'];
    pa2_db()->prepare('UPDATE mc_pa2_product_config_versions SET status="rejected" WHERE id=?')->execute([$versionId]);
    pa2_db()->prepare('UPDATE mc_pa2_product_configs SET status="draft",updated_by=?,updated_at=NOW() WHERE id=?')->execute([pa2_current_user_id(),$configId]);
    pa2_version_event($configId, $versionId, 'rejected', $from, 'rejected', $note);
    return ['product_id' => $productId, 'version_id' => $versionId, 'status' => 'rejected'];
}

function pa2_product_version_publish(int $productId, string $note = ''): array
{
    pa2_require_any(['adaptation_v2.publish_product', 'material_center.adaptation.manage'], '没有发布产品配置的权限。');
    $detail = pa2_workspace_detail($productId);
    $config = (array)($detail['config'] ?? []);
    $version = (array)($detail['version'] ?? []);
    $configId = (int)($config['id'] ?? 0);
    $versionId = (int)($version['id'] ?? 0);
    if (!$versionId || (string)($version['status'] ?? '') !== 'approved') throw new RuntimeException('只有已审批版本可以发布。');
    $versionNo = pa2_next_product_version_no($configId);
    pa2_store_version_snapshot($versionId, 'published');
    pa2_db()->prepare('UPDATE mc_pa2_product_config_versions SET status="published",version_no=?,published_by=?,published_at=NOW() WHERE id=?')->execute([$versionNo,pa2_current_user_id(),$versionId]);
    pa2_db()->prepare('UPDATE mc_pa2_product_configs SET active_published_version_id=?,active_draft_version_id=NULL,status="published",updated_by=?,updated_at=NOW() WHERE id=?')->execute([$versionId,pa2_current_user_id(),$configId]);
    pa2_version_event($configId, $versionId, 'published', 'approved', 'published', $note, ['version_no' => $versionNo, 'previous_published_version_id' => $config['active_published_version_id'] ?? null]);
    return ['product_id' => $productId, 'version_id' => $versionId, 'version_no' => $versionNo, 'status' => 'published'];
}

function pa2_product_version_rollback(int $productId, int $targetVersionId, string $note = ''): array
{
    pa2_require_any(['adaptation_v2.publish_product', 'material_center.adaptation.manage'], '没有回滚产品配置的权限。');
    $config = pa2_fetch_config_by_product($productId);
    if (!$config) throw new RuntimeException('产品配置不存在。');
    $target = pa2_fetch_version($targetVersionId);
    if (!$target || (int)$target['product_config_id'] !== (int)$config['id'] || (string)$target['status'] !== 'published') throw new RuntimeException('只能回滚到同产品的已发布版本。');
    pa2_store_version_snapshot($targetVersionId, 'rollback');
    pa2_db()->prepare('UPDATE mc_pa2_product_configs SET active_published_version_id=?,active_draft_version_id=NULL,status="published",updated_by=?,updated_at=NOW() WHERE id=?')->execute([$targetVersionId,pa2_current_user_id(),(int)$config['id']]);
    pa2_version_event((int)$config['id'], $targetVersionId, 'rollback', 'published', 'published', $note, ['previous_published_version_id' => $config['active_published_version_id'] ?? null]);
    return ['product_id' => $productId, 'version_id' => $targetVersionId, 'status' => 'published'];
}

function pa2_package_rule_labels(): array
{
    return [
        'open' => '开放选择',
        'locked' => '锁定',
        'range_limited' => '范围限定',
        'default_locked' => '锁默认项',
    ];
}

function pa2_fetch_packages(): array
{
    if (!pa2_phase8_tables_ready()) return [];
    $sql = "SELECT p.*,
                v.version_no active_version_no,
                v.status active_version_status,
                COUNT(DISTINCT g.id) group_count,
                SUM(CASE WHEN g.lock_mode='locked' THEN 1 ELSE 0 END) locked_group_count,
                SUM(CASE WHEN g.lock_mode='range_limited' THEN 1 ELSE 0 END) limited_group_count,
                SUM(CASE WHEN g.lock_mode='default_locked' THEN 1 ELSE 0 END) default_locked_group_count,
                COUNT(DISTINCT o.id) option_count
            FROM mc_pa2_config_packages p
            LEFT JOIN mc_pa2_config_package_versions v ON v.id=p.active_version_id
            LEFT JOIN mc_pa2_config_package_groups g ON g.package_version_id=v.id
            LEFT JOIN mc_pa2_config_package_options o ON o.package_group_id=g.id
            GROUP BY p.id
            ORDER BY FIELD(p.package_code,'commercial_flexible','singapore_standard','singapore_dali','singapore_ready_stock'),p.id";
    return pa2_db()->query($sql)->fetchAll(PDO::FETCH_ASSOC);
}

function pa2_fetch_package(int $packageId): ?array
{
    if (!pa2_phase8_tables_ready() || $packageId <= 0) return null;
    $stmt = pa2_db()->prepare("SELECT p.*,v.version_no active_version_no,v.status active_version_status,v.snapshot_json,v.package_rules_json,v.published_at
        FROM mc_pa2_config_packages p
        LEFT JOIN mc_pa2_config_package_versions v ON v.id=p.active_version_id
        WHERE p.id=? LIMIT 1");
    $stmt->execute([$packageId]);
    $package = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$package) return null;
    foreach (['snapshot_json','package_rules_json'] as $field) $package[$field] = pa2_json_decode_array($package[$field] ?? null);

    $stmt = pa2_db()->prepare("SELECT g.*,d.group_name,d.group_type,d.icon
        FROM mc_pa2_config_package_groups g
        LEFT JOIN mc_pa2_group_definitions d ON d.id=g.group_definition_id
        WHERE g.package_version_id=?
        ORDER BY g.sort_order,g.id");
    $stmt->execute([(int)($package['active_version_id'] ?? 0)]);
    $groups = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($groups as &$group) {
        foreach (['allowed_scope_json','default_selection_json','price_rule_json','inventory_rule_json','moq_rule_json','lead_time_rule_json'] as $field) {
            $group[$field] = pa2_json_decode_array($group[$field] ?? null);
        }
        $optStmt = pa2_db()->prepare('SELECT * FROM mc_pa2_config_package_options WHERE package_group_id=? ORDER BY sort_order,id');
        $optStmt->execute([(int)$group['id']]);
        $options = $optStmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($options as &$option) {
            $option['rule_json'] = pa2_json_decode_array($option['rule_json'] ?? null);
        }
        unset($option);
        $group['options'] = $options;
    }
    unset($group);
    $package['groups'] = $groups;
    return $package;
}

function pa2_upsert_package(array $input): array
{
    pa2_require_any(['adaptation_v2.manage_package', 'material_center.adaptation.manage'], '没有维护配置包的权限。');
    if (!pa2_phase8_tables_ready()) throw new RuntimeException('V2 第 8 阶段配置包表尚未迁移。');
    $id = (int)($input['id'] ?? 0);
    $name = trim((string)($input['package_name'] ?? ''));
    if ($name === '') throw new RuntimeException('配置包名称不能为空。');
    $code = trim((string)($input['package_code'] ?? ''));
    if ($code === '') $code = pa2_slug($name, 'package');
    $channel = trim((string)($input['channel_code'] ?? 'commercial')) ?: 'commercial';
    $type = trim((string)($input['package_type'] ?? $code)) ?: $code;
    $status = in_array((string)($input['status'] ?? 'draft'), ['draft','published','disabled'], true) ? (string)$input['status'] : 'draft';
    $description = trim((string)($input['description'] ?? ''));
    if ($id > 0) {
        $before = pa2_fetch_package($id) ?: [];
        $stmt = pa2_db()->prepare('UPDATE mc_pa2_config_packages SET package_code=?,package_name=?,channel_code=?,package_type=?,description=?,status=?,updated_by=?,updated_at=NOW() WHERE id=?');
        $stmt->execute([$code,$name,$channel,$type,$description,$status,pa2_current_user_id(),$id]);
    } else {
        $before = [];
        $stmt = pa2_db()->prepare('INSERT INTO mc_pa2_config_packages(package_code,package_name,channel_code,package_type,description,status,created_by,updated_by,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,NOW(),NOW())');
        $stmt->execute([$code,$name,$channel,$type,$description,$status,pa2_current_user_id(),pa2_current_user_id()]);
        $id = (int)pa2_db()->lastInsertId();
        pa2_prepare_package_version($id);
    }
    $after = pa2_fetch_package($id) ?: [];
    pa2_log($before ? 'update_package' : 'create_package', 'pa2_config_package', $id, $before, $after);
    return $after;
}

function pa2_next_package_version_no(int $packageId): string
{
    $stmt = pa2_db()->prepare("SELECT COUNT(*) FROM mc_pa2_config_package_versions WHERE package_id=? AND version_no LIKE 'V%'");
    $stmt->execute([$packageId]);
    return 'V' . ((int)$stmt->fetchColumn() + 1);
}

function pa2_next_package_draft_no(int $packageId): string
{
    $stmt = pa2_db()->prepare("SELECT COUNT(*) FROM mc_pa2_config_package_versions WHERE package_id=? AND status='draft'");
    $stmt->execute([$packageId]);
    return 'draft-' . ((int)$stmt->fetchColumn() + 1);
}

function pa2_prepare_package_version(int $packageId, int $sourceProductConfigVersionId = 0): array
{
    pa2_require_any(['adaptation_v2.manage_package', 'material_center.adaptation.manage'], '没有维护配置包版本的权限。');
    if (!pa2_phase8_tables_ready()) throw new RuntimeException('V2 第 8 阶段配置包表尚未迁移。');
    $package = pa2_fetch_package($packageId);
    if (!$package) throw new RuntimeException('配置包不存在。');
    $versionNo = pa2_next_package_draft_no($packageId);
    $snapshot = [
        'package_code' => $package['package_code'],
        'package_type' => $package['package_type'],
        'source_product_config_version_id' => $sourceProductConfigVersionId ?: null,
        'created_reason' => 'manual_draft',
    ];
    $rules = [
        'lock_policy' => 'channel_package',
        'price' => 'package_group_or_option_rule',
        'moq' => 'package_group_or_option_rule',
        'inventory' => 'package_group_or_option_rule',
        'lead_time' => 'package_group_or_option_rule',
    ];
    $stmt = pa2_db()->prepare('INSERT INTO mc_pa2_config_package_versions(package_id,version_no,status,source_product_config_version_id,snapshot_json,package_rules_json,created_by,created_at) VALUES(?,?,?,?,?,?,?,NOW())');
    $stmt->execute([$packageId,$versionNo,'draft',$sourceProductConfigVersionId ?: null,pa2_json_encode($snapshot),pa2_json_encode($rules),pa2_current_user_id()]);
    $versionId = (int)pa2_db()->lastInsertId();
    pa2_db()->prepare('UPDATE mc_pa2_config_packages SET active_version_id=?,status="draft",updated_by=?,updated_at=NOW() WHERE id=?')->execute([$versionId,pa2_current_user_id(),$packageId]);
    foreach ($package['groups'] ?? [] as $group) {
        $stmt = pa2_db()->prepare('INSERT INTO mc_pa2_config_package_groups(package_version_id,group_code,group_definition_id,display_name,lock_mode,is_required,allow_empty,min_select,max_select,allowed_scope_json,default_selection_json,price_rule_json,inventory_rule_json,moq_rule_json,lead_time_rule_json,sort_order,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW(),NOW())');
        $stmt->execute([$versionId,$group['group_code'],$group['group_definition_id'] ?: null,$group['display_name'],$group['lock_mode'],(int)$group['is_required'],(int)$group['allow_empty'],(int)$group['min_select'],(int)$group['max_select'],pa2_json_encode($group['allowed_scope_json'] ?? []),pa2_json_encode($group['default_selection_json'] ?? []),pa2_json_encode($group['price_rule_json'] ?? []),pa2_json_encode($group['inventory_rule_json'] ?? []),pa2_json_encode($group['moq_rule_json'] ?? []),pa2_json_encode($group['lead_time_rule_json'] ?? []),(int)$group['sort_order']]);
        $newGroupId = (int)pa2_db()->lastInsertId();
        foreach ($group['options'] ?? [] as $option) {
            $stmt = pa2_db()->prepare('INSERT INTO mc_pa2_config_package_options(package_group_id,option_key,option_type,material_id,option_definition_id,option_code,option_label,is_default,is_locked,price_delta,currency,moq,stock_qty,lead_time_days,valid_from,valid_to,rule_json,sort_order,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW(),NOW())');
            $stmt->execute([$newGroupId,$option['option_key'],$option['option_type'],$option['material_id'] ?: null,$option['option_definition_id'] ?: null,$option['option_code'],$option['option_label'],(int)$option['is_default'],(int)$option['is_locked'],$option['price_delta'],$option['currency'],$option['moq'],$option['stock_qty'],$option['lead_time_days'],$option['valid_from'],$option['valid_to'],pa2_json_encode($option['rule_json'] ?? []),(int)$option['sort_order']]);
        }
    }
    $after = pa2_fetch_package($packageId) ?: [];
    pa2_log('prepare_package_version', 'pa2_config_package', $packageId, $package, $after);
    return $after;
}

function pa2_save_package_group(array $input): array
{
    pa2_require_any(['adaptation_v2.manage_package', 'material_center.adaptation.manage'], '没有维护配置包组规则的权限。');
    if (!pa2_phase8_tables_ready()) throw new RuntimeException('V2 第 8 阶段配置包表尚未迁移。');
    $packageId = (int)($input['package_id'] ?? 0);
    $groupId = (int)($input['group_id'] ?? 0);
    $package = pa2_fetch_package($packageId);
    if (!$package || (int)($package['active_version_id'] ?? 0) <= 0) throw new RuntimeException('配置包或版本不存在。');
    if ((string)($package['active_version_status'] ?? '') !== 'draft') throw new RuntimeException('只有草稿配置包版本可以修改。');
    $groupDefId = (int)($input['group_definition_id'] ?? 0);
    $groupDef = null;
    if ($groupDefId > 0) {
        $stmt = pa2_db()->prepare('SELECT * FROM mc_pa2_group_definitions WHERE id=? LIMIT 1');
        $stmt->execute([$groupDefId]);
        $groupDef = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    $groupCode = trim((string)($input['group_code'] ?? ($groupDef['group_code'] ?? '')));
    if ($groupCode === '') throw new RuntimeException('配置组不能为空。');
    $displayName = trim((string)($input['display_name'] ?? ($groupDef['group_name'] ?? $groupCode)));
    $lockMode = (string)($input['lock_mode'] ?? 'open');
    if (!isset(pa2_package_rule_labels()[$lockMode])) $lockMode = 'open';
    $jsonFields = [];
    foreach (['allowed_scope_json','default_selection_json','price_rule_json','inventory_rule_json','moq_rule_json','lead_time_rule_json'] as $field) {
        $value = trim((string)($input[$field] ?? ''));
        $jsonFields[$field] = $value === '' ? [] : pa2_json_decode_array($value);
    }
    if ($groupId > 0) {
        $before = pa2_fetch_package($packageId);
        $stmt = pa2_db()->prepare('UPDATE mc_pa2_config_package_groups SET group_code=?,group_definition_id=?,display_name=?,lock_mode=?,is_required=?,allow_empty=?,min_select=?,max_select=?,allowed_scope_json=?,default_selection_json=?,price_rule_json=?,inventory_rule_json=?,moq_rule_json=?,lead_time_rule_json=?,sort_order=?,updated_at=NOW() WHERE id=? AND package_version_id=?');
        $stmt->execute([$groupCode,$groupDefId ?: null,$displayName,$lockMode,(int)($input['is_required'] ?? 0),(int)($input['allow_empty'] ?? 1),(int)($input['min_select'] ?? 0),(int)($input['max_select'] ?? 1),pa2_json_encode($jsonFields['allowed_scope_json']),pa2_json_encode($jsonFields['default_selection_json']),pa2_json_encode($jsonFields['price_rule_json']),pa2_json_encode($jsonFields['inventory_rule_json']),pa2_json_encode($jsonFields['moq_rule_json']),pa2_json_encode($jsonFields['lead_time_rule_json']),(int)($input['sort_order'] ?? 100),$groupId,(int)$package['active_version_id']]);
    } else {
        $before = pa2_fetch_package($packageId);
        $stmt = pa2_db()->prepare('INSERT INTO mc_pa2_config_package_groups(package_version_id,group_code,group_definition_id,display_name,lock_mode,is_required,allow_empty,min_select,max_select,allowed_scope_json,default_selection_json,price_rule_json,inventory_rule_json,moq_rule_json,lead_time_rule_json,sort_order,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW(),NOW()) ON DUPLICATE KEY UPDATE group_definition_id=VALUES(group_definition_id),display_name=VALUES(display_name),lock_mode=VALUES(lock_mode),is_required=VALUES(is_required),allow_empty=VALUES(allow_empty),min_select=VALUES(min_select),max_select=VALUES(max_select),allowed_scope_json=VALUES(allowed_scope_json),default_selection_json=VALUES(default_selection_json),price_rule_json=VALUES(price_rule_json),inventory_rule_json=VALUES(inventory_rule_json),moq_rule_json=VALUES(moq_rule_json),lead_time_rule_json=VALUES(lead_time_rule_json),sort_order=VALUES(sort_order),updated_at=NOW()');
        $stmt->execute([(int)$package['active_version_id'],$groupCode,$groupDefId ?: null,$displayName,$lockMode,(int)($input['is_required'] ?? 0),(int)($input['allow_empty'] ?? 1),(int)($input['min_select'] ?? 0),(int)($input['max_select'] ?? 1),pa2_json_encode($jsonFields['allowed_scope_json']),pa2_json_encode($jsonFields['default_selection_json']),pa2_json_encode($jsonFields['price_rule_json']),pa2_json_encode($jsonFields['inventory_rule_json']),pa2_json_encode($jsonFields['moq_rule_json']),pa2_json_encode($jsonFields['lead_time_rule_json']),(int)($input['sort_order'] ?? 100)]);
    }
    $after = pa2_fetch_package($packageId) ?: [];
    pa2_log('save_package_group', 'pa2_config_package', $packageId, $before ?: [], $after);
    return $after;
}

function pa2_save_package_option(array $input): array
{
    pa2_require_any(['adaptation_v2.manage_package', 'material_center.adaptation.manage'], '没有维护配置包选项的权限。');
    if (!pa2_phase8_tables_ready()) throw new RuntimeException('V2 第 8 阶段配置包表尚未迁移。');
    $packageGroupId = (int)($input['package_group_id'] ?? 0);
    if ($packageGroupId <= 0) throw new RuntimeException('配置包组不能为空。');
    $stmt = pa2_db()->prepare('SELECT g.*,v.package_id FROM mc_pa2_config_package_groups g JOIN mc_pa2_config_package_versions v ON v.id=g.package_version_id WHERE g.id=? LIMIT 1');
    $stmt->execute([$packageGroupId]);
    $group = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$group) throw new RuntimeException('配置包组不存在。');
    $package = pa2_fetch_package((int)$group['package_id']);
    if (!$package || (string)($package['active_version_status'] ?? '') !== 'draft') throw new RuntimeException('只有草稿配置包版本可以修改选项。');
    $optionKey = trim((string)($input['option_key'] ?? ''));
    $label = trim((string)($input['option_label'] ?? ''));
    if ($label === '') throw new RuntimeException('选项名称不能为空。');
    if ($optionKey === '') $optionKey = pa2_slug($label, 'option');
    $ruleJson = trim((string)($input['rule_json'] ?? ''));
    $rule = $ruleJson === '' ? [] : pa2_json_decode_array($ruleJson);
    $stmt = pa2_db()->prepare('INSERT INTO mc_pa2_config_package_options(package_group_id,option_key,option_type,material_id,option_definition_id,option_code,option_label,is_default,is_locked,price_delta,currency,moq,stock_qty,lead_time_days,valid_from,valid_to,rule_json,sort_order,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW(),NOW()) ON DUPLICATE KEY UPDATE option_type=VALUES(option_type),material_id=VALUES(material_id),option_definition_id=VALUES(option_definition_id),option_code=VALUES(option_code),option_label=VALUES(option_label),is_default=VALUES(is_default),is_locked=VALUES(is_locked),price_delta=VALUES(price_delta),currency=VALUES(currency),moq=VALUES(moq),stock_qty=VALUES(stock_qty),lead_time_days=VALUES(lead_time_days),valid_from=VALUES(valid_from),valid_to=VALUES(valid_to),rule_json=VALUES(rule_json),sort_order=VALUES(sort_order),updated_at=NOW()');
    $stmt->execute([$packageGroupId,$optionKey,(string)($input['option_type'] ?? 'attribute'),(int)($input['material_id'] ?? 0) ?: null,(int)($input['option_definition_id'] ?? 0) ?: null,trim((string)($input['option_code'] ?? '')) ?: null,$label,(int)($input['is_default'] ?? 0),(int)($input['is_locked'] ?? 0),($input['price_delta'] ?? null) === '' ? null : $input['price_delta'],trim((string)($input['currency'] ?? '')) ?: null,($input['moq'] ?? null) === '' ? null : (int)$input['moq'],($input['stock_qty'] ?? null) === '' ? null : (int)$input['stock_qty'],($input['lead_time_days'] ?? null) === '' ? null : (int)$input['lead_time_days'],trim((string)($input['valid_from'] ?? '')) ?: null,trim((string)($input['valid_to'] ?? '')) ?: null,pa2_json_encode($rule),(int)($input['sort_order'] ?? 100)]);
    $after = pa2_fetch_package((int)$group['package_id']) ?: [];
    pa2_log('save_package_option', 'pa2_config_package', (int)$group['package_id'], $package ?: [], $after);
    return $after;
}

function pa2_package_preview(int $packageId): array
{
    $package = pa2_fetch_package($packageId);
    if (!$package) throw new RuntimeException('配置包不存在。');
    $groups = $package['groups'] ?? [];
    $byCode = [];
    foreach ($groups as $group) $byCode[(string)$group['group_code']] = $group;
    $keyCodes = ['chip','driver','optical','finish_color'];
    $readyStockLocked = true;
    foreach ($keyCodes as $code) {
        if (($byCode[$code]['lock_mode'] ?? '') !== 'locked') $readyStockLocked = false;
    }
    $standardLimited = (($byCode['optical']['lock_mode'] ?? '') === 'range_limited') && (($byCode['finish_color']['lock_mode'] ?? '') === 'range_limited');
    $daliFixed = (($byCode['dimming']['lock_mode'] ?? '') === 'locked') || (($byCode['driver']['default_selection_json']['option_code'] ?? '') === 'dali_driver') || str_contains(strtolower(pa2_json_encode($byCode['driver']['allowed_scope_json'] ?? [])), 'dali');
    $traceable = (int)($package['active_version_id'] ?? 0) > 0 && (string)($package['active_version_no'] ?? '') !== '';
    $totalOptions = 0;
    foreach ($groups as $group) $totalOptions += count($group['options'] ?? []);
    $checks = [
        'ready_stock_locked' => ['passed' => (string)$package['package_code'] !== 'singapore_ready_stock' || $readyStockLocked, 'label' => 'Ready Stock 关键物料全部锁定'],
        'standard_limited' => ['passed' => (string)$package['package_code'] !== 'singapore_standard' || $standardLimited, 'label' => 'Standard 只开放指定光学/颜色范围'],
        'dali_fixed' => ['passed' => (string)$package['package_code'] !== 'singapore_dali' || $daliFixed, 'label' => 'DALI 固定 DALI 电源/调光'],
        'traceable' => ['passed' => $traceable, 'label' => '配置包版本可追溯'],
    ];
    return [
        'package' => $package,
        'summary' => [
            'group_count' => count($groups),
            'option_count' => $totalOptions,
            'locked_group_count' => count(array_filter($groups, fn($g) => ($g['lock_mode'] ?? '') === 'locked')),
            'limited_group_count' => count(array_filter($groups, fn($g) => ($g['lock_mode'] ?? '') === 'range_limited')),
            'default_locked_group_count' => count(array_filter($groups, fn($g) => ($g['lock_mode'] ?? '') === 'default_locked')),
        ],
        'checks' => $checks,
    ];
}

function pa2_publish_package(int $packageId): array
{
    pa2_require_any(['adaptation_v2.manage_package', 'adaptation_v2.publish', 'material_center.adaptation.manage'], '没有发布配置包的权限。');
    $preview = pa2_package_preview($packageId);
    foreach ($preview['checks'] as $check) {
        if (empty($check['passed'])) throw new RuntimeException('配置包预览未通过：' . $check['label']);
    }
    $package = $preview['package'];
    $versionId = (int)($package['active_version_id'] ?? 0);
    if ($versionId <= 0) throw new RuntimeException('配置包版本不存在。');
    if ((string)($package['active_version_status'] ?? '') !== 'draft') throw new RuntimeException('只有草稿配置包版本可以发布。');
    $versionNo = pa2_next_package_version_no($packageId);
    $snapshot = [
        'package' => [
            'package_code' => $package['package_code'],
            'package_name' => $package['package_name'],
            'channel_code' => $package['channel_code'],
            'package_type' => $package['package_type'],
        ],
        'summary' => $preview['summary'],
        'checks' => $preview['checks'],
        'groups' => $package['groups'],
    ];
    pa2_db()->prepare('UPDATE mc_pa2_config_package_versions SET status="published",version_no=?,snapshot_json=?,published_by=?,published_at=NOW() WHERE id=?')->execute([$versionNo,pa2_json_encode($snapshot),pa2_current_user_id(),$versionId]);
    pa2_db()->prepare('UPDATE mc_pa2_config_packages SET status="published",updated_by=?,updated_at=NOW() WHERE id=?')->execute([pa2_current_user_id(),$packageId]);
    $after = pa2_fetch_package($packageId) ?: [];
    pa2_log('publish_package', 'pa2_config_package', $packageId, $package, $after);
    return ['package' => $after, 'version_no' => $versionNo, 'checks' => $preview['checks']];
}

function pa2_channel_clients(): array
{
    if (!pa2_phase9_tables_ready()) return [];
    $rows = pa2_db()->query('SELECT * FROM mc_pa2_channel_clients ORDER BY channel_code,client_code')->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$row) $row['allowed_scope_json'] = pa2_json_decode_array($row['allowed_scope_json'] ?? null);
    unset($row);
    return $rows;
}

function pa2_channel_client(string $clientCode): ?array
{
    if (!pa2_phase9_tables_ready() || trim($clientCode) === '') return null;
    $stmt = pa2_db()->prepare('SELECT * FROM mc_pa2_channel_clients WHERE client_code=? AND is_enabled=1 LIMIT 1');
    $stmt->execute([trim($clientCode)]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) return null;
    $row['allowed_scope_json'] = pa2_json_decode_array($row['allowed_scope_json'] ?? null);
    return $row;
}

function pa2_channel_secret_for_client(array $client): string
{
    $scope = pa2_json_decode_array($client['allowed_scope_json'] ?? null);
    $envName = (string)($scope['env_secret'] ?? '');
    $secret = $envName !== '' ? (string)getenv($envName) : '';
    if ($secret === '') throw new RuntimeException('渠道客户端签名密钥未配置：' . ($envName ?: (string)($client['client_code'] ?? 'unknown')));
    return $secret;
}

function pa2_channel_request_headers(): array
{
    return [
        'client_code' => (string)($_SERVER['HTTP_X_PA2_CLIENT'] ?? $_GET['client_code'] ?? ''),
        'timestamp' => (string)($_SERVER['HTTP_X_PA2_TIMESTAMP'] ?? $_GET['timestamp'] ?? ''),
        'signature' => (string)($_SERVER['HTTP_X_PA2_SIGNATURE'] ?? $_GET['signature'] ?? ''),
        'ip_address' => (string)($_SERVER['REMOTE_ADDR'] ?? ''),
        'user_agent' => (string)($_SERVER['HTTP_USER_AGENT'] ?? ''),
    ];
}

function pa2_channel_auth_context(string $rawBody = ''): array
{
    if (!pa2_phase9_tables_ready()) throw new RuntimeException('V2 第 9 阶段渠道接口表尚未迁移。');
    $headers = pa2_channel_request_headers();
    $client = pa2_channel_client($headers['client_code']);
    if (!$client) throw new RuntimeException('渠道客户端不存在或已停用。');
    if ((int)($client['signature_required'] ?? 1) === 1) {
        if ($headers['timestamp'] === '' || $headers['signature'] === '') throw new RuntimeException('缺少渠道签名。');
        if (abs(time() - (int)$headers['timestamp']) > 300) throw new RuntimeException('渠道签名已过期。');
        $base = $headers['timestamp'] . "\n" . $headers['client_code'] . "\n" . $rawBody;
        $expected = hash_hmac('sha256', $base, pa2_channel_secret_for_client($client));
        if (!hash_equals($expected, $headers['signature'])) throw new RuntimeException('渠道签名无效。');
    }
    pa2_db()->prepare('UPDATE mc_pa2_channel_clients SET last_used_at=NOW() WHERE id=?')->execute([(int)$client['id']]);
    return ['client' => $client, 'headers' => $headers];
}

function pa2_channel_log(string $action, ?array $context, int $statusCode, string $message, array $request = [], array $response = []): void
{
    if (!pa2_phase9_tables_ready()) return;
    try {
        $client = (array)($context['client'] ?? []);
        $headers = (array)($context['headers'] ?? pa2_channel_request_headers());
        $stmt = pa2_db()->prepare('INSERT INTO mc_pa2_channel_access_logs(client_code,channel_code,action,request_hash,response_hash,status_code,message,ip_address,user_agent,created_at) VALUES(?,?,?,?,?,?,?,?,?,NOW())');
        $stmt->execute([
            $client['client_code'] ?? ($headers['client_code'] ?? null),
            $client['channel_code'] ?? null,
            $action,
            $request ? hash('sha256', pa2_json_encode($request)) : null,
            $response ? hash('sha256', pa2_json_encode($response)) : null,
            $statusCode,
            mb_substr($message, 0, 500),
            $headers['ip_address'] ?? null,
            mb_substr((string)($headers['user_agent'] ?? ''), 0, 300),
        ]);
    } catch (Throwable) {
    }
}

function pa2_channel_package_payload(array $package): array
{
    $groups = [];
    foreach ($package['groups'] ?? [] as $group) {
        $options = [];
        foreach ($group['options'] ?? [] as $option) {
            $options[] = [
                'option_key' => $option['option_key'],
                'option_type' => $option['option_type'],
                'material_id' => $option['material_id'],
                'option_code' => $option['option_code'],
                'option_label' => $option['option_label'],
                'is_default' => (int)$option['is_default'] === 1,
                'is_locked' => (int)$option['is_locked'] === 1,
                'price_delta' => $option['price_delta'],
                'currency' => $option['currency'],
                'moq' => $option['moq'],
                'stock_qty' => $option['stock_qty'],
                'lead_time_days' => $option['lead_time_days'],
                'rule' => $option['rule_json'] ?? [],
            ];
        }
        $groups[] = [
            'group_code' => $group['group_code'],
            'display_name' => $group['display_name'],
            'lock_mode' => $group['lock_mode'],
            'is_required' => (int)$group['is_required'] === 1,
            'allow_empty' => (int)$group['allow_empty'] === 1,
            'min_select' => (int)$group['min_select'],
            'max_select' => (int)$group['max_select'],
            'allowed_scope' => $group['allowed_scope_json'] ?? [],
            'default_selection' => $group['default_selection_json'] ?? [],
            'price_rule' => $group['price_rule_json'] ?? [],
            'inventory_rule' => $group['inventory_rule_json'] ?? [],
            'moq_rule' => $group['moq_rule_json'] ?? [],
            'lead_time_rule' => $group['lead_time_rule_json'] ?? [],
            'options' => $options,
        ];
    }
    return [
        'package_code' => $package['package_code'],
        'package_name' => $package['package_name'],
        'channel_code' => $package['channel_code'],
        'package_type' => $package['package_type'],
        'status' => 'published',
        'version_id' => (int)$package['active_version_id'],
        'version_no' => $package['active_version_no'],
        'published_at' => $package['published_at'] ?? null,
        'groups' => $groups,
    ];
}

function pa2_channel_cache_get(string $cacheKey): ?array
{
    if (!pa2_phase9_tables_ready()) return null;
    $stmt = pa2_db()->prepare('SELECT payload_json FROM mc_pa2_channel_cache WHERE cache_key=? AND expires_at>NOW() LIMIT 1');
    $stmt->execute([$cacheKey]);
    $payload = $stmt->fetchColumn();
    return $payload ? pa2_json_decode_array($payload) : null;
}

function pa2_channel_cache_put(string $cacheKey, string $channelCode, ?int $packageId, ?int $versionId, array $payload, int $ttlSeconds = 300): void
{
    if (!pa2_phase9_tables_ready()) return;
    $json = pa2_json_encode($payload);
    $hash = hash('sha256', $json);
    $stmt = pa2_db()->prepare('INSERT INTO mc_pa2_channel_cache(cache_key,channel_code,package_id,package_version_id,payload_json,payload_hash,expires_at,created_at,updated_at) VALUES(?,?,?,?,?,?,DATE_ADD(NOW(), INTERVAL ? SECOND),NOW(),NOW()) ON DUPLICATE KEY UPDATE payload_json=VALUES(payload_json),payload_hash=VALUES(payload_hash),expires_at=VALUES(expires_at),updated_at=NOW()');
    $stmt->execute([$cacheKey,$channelCode,$packageId,$versionId,$json,$hash,$ttlSeconds]);
}

function pa2_channel_published_packages(string $channelCode, bool $useCache = true): array
{
    if (!pa2_phase9_tables_ready()) return ['packages' => [], 'cached' => false];
    $channelCode = trim($channelCode);
    if ($channelCode === '') throw new RuntimeException('渠道不能为空。');
    $cacheKey = 'packages:' . $channelCode;
    if ($useCache) {
        $cached = pa2_channel_cache_get($cacheKey);
        if ($cached) return ['packages' => $cached['packages'] ?? [], 'cached' => true];
    }
    $stmt = pa2_db()->prepare("SELECT p.id
        FROM mc_pa2_config_packages p
        JOIN mc_pa2_config_package_versions v ON v.id=p.active_version_id
        WHERE p.channel_code=? AND p.status='published' AND v.status='published'
        ORDER BY p.package_code");
    $stmt->execute([$channelCode]);
    $packages = [];
    while ($id = $stmt->fetchColumn()) {
        $package = pa2_fetch_package((int)$id);
        if ($package) $packages[] = pa2_channel_package_payload($package);
    }
    pa2_channel_cache_put($cacheKey, $channelCode, null, null, ['packages' => $packages]);
    return ['packages' => $packages, 'cached' => false];
}

function pa2_channel_published_package_detail(string $channelCode, string $packageCode, bool $useCache = true): array
{
    if (!pa2_phase9_tables_ready()) throw new RuntimeException('V2 第 9 阶段渠道接口表尚未迁移。');
    $cacheKey = 'package:' . trim($channelCode) . ':' . trim($packageCode);
    if ($useCache) {
        $cached = pa2_channel_cache_get($cacheKey);
        if ($cached) return ['package' => $cached['package'] ?? null, 'cached' => true];
    }
    $stmt = pa2_db()->prepare("SELECT p.id
        FROM mc_pa2_config_packages p
        JOIN mc_pa2_config_package_versions v ON v.id=p.active_version_id
        WHERE p.channel_code=? AND p.package_code=? AND p.status='published' AND v.status='published'
        LIMIT 1");
    $stmt->execute([trim($channelCode),trim($packageCode)]);
    $id = (int)$stmt->fetchColumn();
    if ($id <= 0) throw new RuntimeException('未找到已发布配置包，草稿不会暴露给下游。');
    $package = pa2_fetch_package($id);
    $payload = pa2_channel_package_payload($package ?: []);
    pa2_channel_cache_put($cacheKey, trim($channelCode), $id, (int)($package['active_version_id'] ?? 0), ['package' => $payload]);
    return ['package' => $payload, 'cached' => false];
}

function pa2_store_channel_package_snapshot(int $packageId): array
{
    if (!pa2_phase9_tables_ready()) throw new RuntimeException('V2 第 9 阶段渠道接口表尚未迁移。');
    $package = pa2_fetch_package($packageId);
    if (!$package || (string)($package['status'] ?? '') !== 'published' || (string)($package['active_version_status'] ?? '') !== 'published') throw new RuntimeException('只能为已发布配置包生成下游快照。');
    $payload = pa2_channel_package_payload($package);
    $json = pa2_json_encode($payload);
    $hash = hash('sha256', $json);
    $stmt = pa2_db()->prepare('INSERT INTO mc_pa2_channel_package_snapshots(channel_code,package_id,package_version_id,snapshot_type,payload_json,payload_hash,created_by,created_at) VALUES(?,?,?,?,?,?,?,NOW()) ON DUPLICATE KEY UPDATE created_at=created_at');
    $stmt->execute([$package['channel_code'],(int)$package['id'],(int)$package['active_version_id'],'published_payload',$json,$hash,pa2_current_user_id()]);
    return ['payload_hash' => $hash, 'package' => $payload];
}

function pa2_channel_order_snapshot(array $input, array $context): array
{
    if (!pa2_phase9_tables_ready()) throw new RuntimeException('V2 第 9 阶段渠道接口表尚未迁移。');
    $client = (array)($context['client'] ?? []);
    $externalOrderNo = trim((string)($input['external_order_no'] ?? ''));
    $packageCode = trim((string)($input['package_code'] ?? ''));
    if ($externalOrderNo === '') throw new RuntimeException('下游订单号不能为空。');
    if ($packageCode === '') throw new RuntimeException('配置包编码不能为空。');
    $detail = pa2_channel_published_package_detail((string)$client['channel_code'], $packageCode, false);
    $package = $detail['package'];
    $payload = [
        'external_order_no' => $externalOrderNo,
        'source_system' => (string)($input['source_system'] ?? $client['client_code']),
        'package_code' => $packageCode,
        'package_version_id' => (int)$package['version_id'],
        'order_payload' => $input['order_payload'] ?? [],
        'captured_package' => $package,
    ];
    $json = pa2_json_encode($payload);
    $hash = hash('sha256', $json);
    $stmt = pa2_db()->prepare('INSERT INTO mc_pa2_channel_order_snapshots(channel_code,client_code,external_order_no,package_id,package_version_id,payload_json,payload_hash,source_system,created_at) SELECT ?,?,?,p.id,?,?,?,?,NOW() FROM mc_pa2_config_packages p WHERE p.package_code=? LIMIT 1 ON DUPLICATE KEY UPDATE created_at=created_at');
    $stmt->execute([(string)$client['channel_code'],(string)$client['client_code'],$externalOrderNo,(int)$package['version_id'],$json,$hash,(string)($payload['source_system'] ?? ''),$packageCode]);
    return ['external_order_no' => $externalOrderNo, 'package_code' => $packageCode, 'package_version_id' => (int)$package['version_id'], 'payload_hash' => $hash];
}
