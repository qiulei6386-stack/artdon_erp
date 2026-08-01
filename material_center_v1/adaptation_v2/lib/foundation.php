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
