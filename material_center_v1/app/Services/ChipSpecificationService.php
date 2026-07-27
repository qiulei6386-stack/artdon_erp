<?php
declare(strict_types=1);

namespace Artdon\MaterialCenter\Services;

use PDO;
use RuntimeException;

final class ChipSpecificationService
{
    public function __construct(private ?PDO $db = null)
    {
        $this->db ??= \db();
    }

    public function catalog(): array
    {
        $templates = $this->templates();
        return [
            'templates' => $templates,
            'suggestions' => [
                'cct' => [2200, 2400, 2700, 3000, 3500, 4000, 5000, 5700, 6500],
                'cri' => [70, 80, 90, 95, 98],
                'sdcm' => [1, 2, 3, 5, 7],
            ],
        ];
    }

    public function templates(): array
    {
        $rows = $this->all("SELECT t.*,v.selection_json,v.combinations_json,v.change_note,v.created_at version_created_at,
            (SELECT COUNT(*) FROM mc_chip_material_templates mt WHERE mt.template_id=t.id) material_count,
            (SELECT COUNT(*) FROM mc_chip_material_templates mt WHERE mt.template_id=t.id AND mt.applied_version_no<t.current_version_no) stale_material_count
            FROM mc_chip_spec_templates t
            JOIN mc_chip_spec_template_versions v ON v.template_id=t.id AND v.version_no=t.current_version_no
            WHERE t.status='active'
            ORDER BY t.is_system_default DESC,t.template_name,t.id");
        foreach ($rows as &$row) {
            $row['selection'] = json_decode((string) $row['selection_json'], true) ?: ['cct' => [], 'cri' => [], 'sdcm' => []];
            $row['combinations'] = json_decode((string) $row['combinations_json'], true) ?: [];
            unset($row['selection_json'], $row['combinations_json']);
        }
        unset($row);
        return $rows;
    }

    public function saveTemplate(array $data, int $userId): array
    {
        $name = trim((string) ($data['template_name'] ?? ''));
        if ($name === '') throw new RuntimeException('请填写模板名称。');
        $templateId = (int) ($data['template_id'] ?? 0);
        $selection = $this->normalizeSelection((array) ($data['selection'] ?? []));
        $combinations = $this->normalizeCombinations((array) ($data['combinations'] ?? []));
        $description = $this->nullable($data['description'] ?? '', 500);
        $note = $this->nullable($data['change_note'] ?? '', 500) ?: '保存模板版本';
        $isDefault = !empty($data['is_system_default']) ? 1 : 0;

        $this->db->beginTransaction();
        try {
            if ($isDefault) {
                $this->db->exec("UPDATE mc_chip_spec_templates SET is_system_default=0 WHERE is_system_default=1");
            }
            if ($templateId) {
                $stmt = $this->db->prepare("SELECT * FROM mc_chip_spec_templates WHERE id=? AND status='active' FOR UPDATE");
                $stmt->execute([$templateId]);
                $template = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$template) throw new RuntimeException('规格模板不存在。');
                $version = (int) $template['current_version_no'] + 1;
                $this->db->prepare("UPDATE mc_chip_spec_templates
                    SET template_name=?,description=?,is_system_default=?,current_version_no=?,updated_by=?,updated_at=NOW()
                    WHERE id=?")
                    ->execute([mb_substr($name, 0, 160), $description, $isDefault, $version, $userId, $templateId]);
            } else {
                $version = 1;
                $code = 'CHIP-TPL-'.date('ymd').'-'.strtoupper(substr(bin2hex(random_bytes(5)), 0, 8));
                $this->db->prepare("INSERT INTO mc_chip_spec_templates
                    (template_code,template_name,description,is_system_default,status,current_version_no,created_by,updated_by,created_at,updated_at)
                    VALUES(?,?,?,?,'active',1,?,?,NOW(),NOW())")
                    ->execute([$code, mb_substr($name, 0, 160), $description, $isDefault, $userId, $userId]);
                $templateId = (int) $this->db->lastInsertId();
            }
            $this->db->prepare("INSERT INTO mc_chip_spec_template_versions
                (template_id,version_no,selection_json,combinations_json,change_note,created_by,created_at)
                VALUES(?,?,?,?,?,?,NOW())")
                ->execute([
                    $templateId,
                    $version,
                    $this->json($selection),
                    $this->json($combinations),
                    $note,
                    $userId,
                ]);
            $this->operation('chip_template', $templateId, 'save_version', [
                'version_no' => $version,
                'combination_count' => count($combinations),
            ], $userId);
            $this->db->commit();
            return ['template_id' => $templateId, 'version_no' => $version, 'combination_count' => count($combinations)];
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
    }

    public function material(int $materialId): array
    {
        $material = $this->chipMaterial($materialId);
        $variants = $this->variants($materialId, true);
        $applied = $this->all("SELECT mt.*,t.template_name,t.current_version_no,
            IF(mt.applied_version_no<t.current_version_no,1,0) is_stale
            FROM mc_chip_material_templates mt
            JOIN mc_chip_spec_templates t ON t.id=mt.template_id
            WHERE mt.material_id=? ORDER BY t.is_system_default DESC,t.template_name", [$materialId]);
        return ['material' => $material, 'variants' => $variants, 'applied_templates' => $applied];
    }

    public function variants(int $materialId, bool $includeDisabled = false): array
    {
        $sql = "SELECT v.*,t.template_name
            FROM mc_chip_spec_variants v
            LEFT JOIN mc_chip_spec_templates t ON t.id=v.source_template_id
            WHERE v.material_id=?";
        if (!$includeDisabled) $sql .= " AND v.status='active'";
        $sql .= ' ORDER BY v.status,v.is_default DESC,v.sort_order,v.cct_k,v.cri,v.sdcm,v.id';
        $rows = $this->all($sql, [$materialId]);
        foreach ($rows as &$row) {
            $row['label'] = $this->variantLabel($row);
            $row['snapshot'] = $this->variantSnapshot($row);
        }
        unset($row);
        return $rows;
    }

    public function previewApply(array $templateIds, array $materialIds, string $mode = 'fill_missing'): array
    {
        [$templateIds, $materialIds, $mode] = $this->validateApplyTargets($templateIds, $materialIds, $mode);
        $combinations = $this->templateCombinations($templateIds);
        if (!$combinations) throw new RuntimeException('所选模板还没有有效规格组合，请先维护色温、显指和色容差。');
        $summary = [
            'template_count' => count($templateIds),
            'material_count' => count($materialIds),
            'combination_count' => count($combinations),
            'create_count' => 0,
            'keep_count' => 0,
            'disable_count' => 0,
            'protected_count' => 0,
            'materials' => [],
        ];
        foreach ($materialIds as $materialId) {
            $existing = $this->variants($materialId, true);
            $byKey = [];
            foreach ($existing as $variant) $byKey[$variant['spec_key']] = $variant;
            $wanted = array_column($combinations, null, 'spec_key');
            $created = count(array_diff(array_keys($wanted), array_keys($byKey)));
            $kept = count(array_intersect(array_keys($wanted), array_keys($byKey)));
            $disable = 0;
            $protected = 0;
            if ($mode === 'replace') {
                foreach ($existing as $variant) {
                    if ($variant['status'] !== 'active' || isset($wanted[$variant['spec_key']])) continue;
                    if ($this->variantHasApprovedUse((int) $variant['id'])) $protected++;
                    else $disable++;
                }
            }
            $summary['create_count'] += $created;
            $summary['keep_count'] += $kept;
            $summary['disable_count'] += $disable;
            $summary['protected_count'] += $protected;
            $summary['materials'][] = [
                'material_id' => $materialId,
                'create_count' => $created,
                'keep_count' => $kept,
                'disable_count' => $disable,
                'protected_count' => $protected,
            ];
        }
        return $summary;
    }

    public function applyTemplates(array $templateIds, array $materialIds, string $mode, int $userId): array
    {
        [$templateIds, $materialIds, $mode] = $this->validateApplyTargets($templateIds, $materialIds, $mode);
        $preview = $this->previewApply($templateIds, $materialIds, $mode);
        $combinations = $this->templateCombinations($templateIds);
        $versions = [];
        foreach ($this->templates() as $template) {
            if (in_array((int) $template['id'], $templateIds, true)) $versions[(int) $template['id']] = (int) $template['current_version_no'];
        }
        $result = ['created' => 0, 'reactivated' => 0, 'disabled' => 0, 'protected' => 0, 'materials' => count($materialIds)];

        $this->db->beginTransaction();
        try {
            foreach ($materialIds as $materialId) {
                $existing = $this->all('SELECT * FROM mc_chip_spec_variants WHERE material_id=? FOR UPDATE', [$materialId]);
                $byKey = [];
                foreach ($existing as $variant) $byKey[$variant['spec_key']] = $variant;
                $wantedKeys = [];
                foreach ($combinations as $index => $combination) {
                    $key = $combination['spec_key'];
                    $wantedKeys[$key] = true;
                    $sourceTemplateId = (int) ($combination['_template_id'] ?? 0) ?: null;
                    $sourceVersion = $sourceTemplateId ? ($versions[$sourceTemplateId] ?? null) : null;
                    if (isset($byKey[$key])) {
                        if ($byKey[$key]['status'] !== 'active') {
                            $this->db->prepare("UPDATE mc_chip_spec_variants SET status='active',updated_by=?,updated_at=NOW() WHERE id=?")
                                ->execute([$userId, $byKey[$key]['id']]);
                            $result['reactivated']++;
                        }
                        continue;
                    }
                    $this->insertVariant($materialId, $combination, 'template', $sourceTemplateId, $sourceVersion, $userId, ($index + 1) * 10);
                    $result['created']++;
                }
                if ($mode === 'replace') {
                    foreach ($existing as $variant) {
                        if ($variant['status'] !== 'active' || isset($wantedKeys[$variant['spec_key']])) continue;
                        if ($this->variantHasApprovedUse((int) $variant['id'])) {
                            $result['protected']++;
                            continue;
                        }
                        $this->db->prepare("UPDATE mc_chip_spec_variants SET status='disabled',is_default=0,updated_by=?,updated_at=NOW() WHERE id=?")
                            ->execute([$userId, $variant['id']]);
                        $result['disabled']++;
                    }
                }
                foreach ($templateIds as $templateId) {
                    $this->db->prepare("INSERT INTO mc_chip_material_templates
                        (material_id,template_id,applied_version_no,applied_by,applied_at,synced_at)
                        VALUES(?,?,?,?,NOW(),NOW())
                        ON DUPLICATE KEY UPDATE applied_version_no=VALUES(applied_version_no),applied_by=VALUES(applied_by),applied_at=NOW(),synced_at=NOW()")
                        ->execute([$materialId, $templateId, $versions[$templateId], $userId]);
                }
                $this->ensureMaterialDefault($materialId, $userId);
                $this->operation('chip_material', $materialId, 'apply_templates', [
                    'template_ids' => $templateIds,
                    'versions' => $versions,
                    'mode' => $mode,
                ], $userId);
            }
            $this->db->prepare("INSERT INTO mc_chip_template_sync_logs
                (template_ids_json,target_material_ids_json,mode,preview_json,result_json,actor_id,created_at)
                VALUES(?,?,?,?,?,?,NOW())")
                ->execute([$this->json($templateIds), $this->json($materialIds), $mode, $this->json($preview), $this->json($result), $userId]);
            $this->db->commit();
            return $result + ['preview' => $preview];
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
    }

    public function saveMaterialSettings(int $materialId, array $data, int $userId): array
    {
        $this->chipMaterial($materialId);
        $activeIds = array_values(array_unique(array_filter(array_map('intval', (array) ($data['active_variant_ids'] ?? [])))));
        $defaultId = (int) ($data['default_variant_id'] ?? 0);
        $confirmIds = array_values(array_unique(array_filter(array_map('intval', (array) ($data['confirm_variant_ids'] ?? [])))));
        if (!$activeIds && $this->variants($materialId, true)) throw new RuntimeException('芯片至少需要保留一个启用规格；暂不供应的芯片请使用物料停用流程。');
        $this->assertVariantIds($materialId, array_values(array_unique(array_merge($activeIds, $confirmIds, [$defaultId]))), true);
        if ($defaultId && !in_array($defaultId, $activeIds, true)) throw new RuntimeException('默认出货规格必须是已启用规格。');
        foreach ($this->variants($materialId) as $variant) {
            if (!in_array((int) $variant['id'], $activeIds, true) && $this->variantHasApprovedUse((int) $variant['id'])) {
                throw new RuntimeException('规格“'.$variant['label'].'”已被审批产品使用，不能直接停用；请先在产品适配中建立新版本。');
            }
        }

        $this->db->beginTransaction();
        try {
            $this->db->prepare("UPDATE mc_chip_spec_variants SET status='disabled',is_default=0,updated_by=?,updated_at=NOW() WHERE material_id=?")
                ->execute([$userId, $materialId]);
            if ($activeIds) {
                $marks = implode(',', array_fill(0, count($activeIds), '?'));
                $this->db->prepare("UPDATE mc_chip_spec_variants SET status='active',updated_by=?,updated_at=NOW() WHERE material_id=? AND id IN($marks)")
                    ->execute(array_merge([$userId, $materialId], $activeIds));
            }
            if ($defaultId) {
                $this->db->prepare("UPDATE mc_chip_spec_variants SET is_default=1 WHERE material_id=? AND id=? AND status='active'")
                    ->execute([$materialId, $defaultId]);
            } else {
                $this->ensureMaterialDefault($materialId, $userId);
            }
            if ($confirmIds) {
                $marks = implode(',', array_fill(0, count($confirmIds), '?'));
                $this->db->prepare("UPDATE mc_chip_spec_variants SET needs_confirmation=0,updated_by=?,updated_at=NOW() WHERE material_id=? AND id IN($marks)")
                    ->execute(array_merge([$userId, $materialId], $confirmIds));
            }
            $this->operation('chip_material', $materialId, 'save_variant_settings', [
                'active_variant_ids' => $activeIds,
                'default_variant_id' => $defaultId,
                'confirmed_variant_ids' => $confirmIds,
            ], $userId);
            $this->db->commit();
            return $this->material($materialId);
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
    }

    public function addManualVariants(int $materialId, array $combinations, int $userId): array
    {
        $this->chipMaterial($materialId);
        $combinations = $this->normalizeCombinations($combinations);
        if (!$combinations) throw new RuntimeException('请至少填写一个完整规格组合。');
        $created = 0;
        $this->db->beginTransaction();
        try {
            foreach ($combinations as $index => $combination) {
                $stmt = $this->db->prepare('SELECT id,status FROM mc_chip_spec_variants WHERE material_id=? AND spec_key=? FOR UPDATE');
                $stmt->execute([$materialId, $combination['spec_key']]);
                $found = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($found) {
                    if ($found['status'] !== 'active') {
                        $this->db->prepare("UPDATE mc_chip_spec_variants SET status='active',updated_by=?,updated_at=NOW() WHERE id=?")
                            ->execute([$userId, $found['id']]);
                    }
                    continue;
                }
                $this->insertVariant($materialId, $combination, 'manual', null, null, $userId, ($index + 1) * 10);
                $created++;
            }
            $this->ensureMaterialDefault($materialId, $userId);
            $this->operation('chip_material', $materialId, 'add_manual_variants', ['created' => $created], $userId);
            $this->db->commit();
            return ['created' => $created, 'material' => $this->material($materialId)];
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
    }

    public function optionVariants(int $optionId): array
    {
        $option = $this->optionChipMaterial($optionId);
        $variants = $this->variants((int) $option['material_id'], true);
        $selected = $this->all("SELECT chip_variant_id,is_default,status
            FROM mc_adaptation_option_chip_variants WHERE option_id=?", [$optionId]);
        $byId = [];
        foreach ($selected as $row) $byId[(int) $row['chip_variant_id']] = $row;
        foreach ($variants as &$variant) {
            $link = $byId[(int) $variant['id']] ?? null;
            $variant['is_selected'] = $link && $link['status'] === 'active' ? 1 : 0;
            $variant['is_option_default'] = $variant['is_selected'] && (int) $link['is_default'] ? 1 : 0;
        }
        unset($variant);
        return ['option' => $option, 'variants' => $variants];
    }

    public function attachAllActiveToOption(int $optionId): void
    {
        $option = $this->optionChipMaterial($optionId, false);
        if (!$option) return;
        $variants = $this->variants((int) $option['material_id']);
        if (!$variants) return;
        $hasLinks = (int) $this->value('SELECT COUNT(*) FROM mc_adaptation_option_chip_variants WHERE option_id=?', [$optionId]);
        if ($hasLinks) return;
        $hasDefault = false;
        foreach ($variants as $index => $variant) {
            $isDefault = (int) $variant['is_default'];
            if (!$hasDefault && ($isDefault || $index === count($variants) - 1)) {
                $isDefault = 1;
                $hasDefault = true;
            }
            $this->db->prepare("INSERT INTO mc_adaptation_option_chip_variants
                (option_id,chip_variant_id,is_default,status,created_at,updated_at)
                VALUES(?,?,?,'active',NOW(),NOW())")
                ->execute([$optionId, $variant['id'], $isDefault]);
        }
    }

    public function saveOptionVariants(int $optionId, array $variantIds, int $defaultVariantId, int $userId): array
    {
        $option = $this->optionChipMaterial($optionId);
        $materialId = (int) $option['material_id'];
        $variantIds = array_values(array_unique(array_filter(array_map('intval', $variantIds))));
        if (!$variantIds) throw new RuntimeException('芯片选项至少需要保留一个具体规格。');
        $this->assertVariantIds($materialId, $variantIds, false);
        if (!$defaultVariantId || !in_array($defaultVariantId, $variantIds, true)) {
            throw new RuntimeException('请从允许的芯片规格中选择一个产品默认规格。');
        }
        $this->db->beginTransaction();
        try {
            $this->db->prepare('DELETE FROM mc_adaptation_option_chip_variants WHERE option_id=?')->execute([$optionId]);
            $insert = $this->db->prepare("INSERT INTO mc_adaptation_option_chip_variants
                (option_id,chip_variant_id,is_default,status,created_at,updated_at)
                VALUES(?,?,?,'active',NOW(),NOW())");
            foreach ($variantIds as $variantId) $insert->execute([$optionId, $variantId, $variantId === $defaultVariantId ? 1 : 0]);
            $productId = (int) $option['product_id'];
            $this->db->prepare("UPDATE mc_adaptation_groups SET status='draft',is_enabled=0,updated_by=?,updated_at=NOW() WHERE product_id=? AND status<>'disabled'")
                ->execute([$userId, $productId]);
            $this->db->prepare("UPDATE mc_adaptation_options o JOIN mc_adaptation_groups g ON g.id=o.group_id
                SET o.status='draft' WHERE g.product_id=?")->execute([$productId]);
            $this->operation('adaptation_option', $optionId, 'save_chip_variants', [
                'variant_ids' => $variantIds,
                'default_variant_id' => $defaultVariantId,
                'product_id' => $productId,
            ], $userId);
            $this->db->commit();
            return $this->optionVariants($optionId);
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
    }

    public function copyOptionVariants(int $sourceOptionId, int $targetOptionId): void
    {
        $rows = $this->all("SELECT chip_variant_id,is_default,status
            FROM mc_adaptation_option_chip_variants WHERE option_id=?", [$sourceOptionId]);
        if (!$rows) {
            $this->attachAllActiveToOption($targetOptionId);
            return;
        }
        $insert = $this->db->prepare("INSERT INTO mc_adaptation_option_chip_variants
            (option_id,chip_variant_id,is_default,status,created_at,updated_at)
            VALUES(?,?,?,?,NOW(),NOW())");
        foreach ($rows as $row) $insert->execute([$targetOptionId, $row['chip_variant_id'], $row['is_default'], $row['status']]);
    }

    public function variantSnapshot(array $variant): array
    {
        return [
            'variant_id' => (int) $variant['id'],
            'variant_code' => (string) $variant['variant_code'],
            'label' => $this->variantLabel($variant),
            'cct_k' => $variant['cct_k'] !== null ? (int) $variant['cct_k'] : null,
            'cct_min_k' => $variant['cct_min_k'] !== null ? (int) $variant['cct_min_k'] : null,
            'cct_max_k' => $variant['cct_max_k'] !== null ? (int) $variant['cct_max_k'] : null,
            'cri' => $variant['cri'] !== null ? (float) $variant['cri'] : null,
            'sdcm' => $variant['sdcm'] !== null ? (float) $variant['sdcm'] : null,
            'r9' => $variant['r9'] !== null ? (float) $variant['r9'] : null,
            'luminous_flux_lm' => $variant['luminous_flux_lm'] !== null ? (float) $variant['luminous_flux_lm'] : null,
            'efficacy_lm_w' => $variant['efficacy_lm_w'] !== null ? (float) $variant['efficacy_lm_w'] : null,
            'supplier_spec_code' => $variant['supplier_spec_code'] ?: null,
            'purchase_price' => $variant['purchase_price'] !== null ? (float) $variant['purchase_price'] : null,
            'currency' => (string) ($variant['currency'] ?: 'USD'),
            'stock_quantity' => $variant['stock_quantity'] !== null ? (float) $variant['stock_quantity'] : null,
            'lead_time_days' => $variant['lead_time_days'] !== null ? (int) $variant['lead_time_days'] : null,
            'source_type' => (string) $variant['source_type'],
            'source_template_id' => $variant['source_template_id'] !== null ? (int) $variant['source_template_id'] : null,
            'source_template_version_no' => $variant['source_template_version_no'] !== null ? (int) $variant['source_template_version_no'] : null,
            'needs_confirmation' => (int) $variant['needs_confirmation'],
        ];
    }

    public function variantLabel(array $variant): string
    {
        if ($variant['cct_k'] !== null) {
            $cct = (int) $variant['cct_k'].'K';
        } elseif ($variant['cct_min_k'] !== null || $variant['cct_max_k'] !== null) {
            $min = $variant['cct_min_k'] !== null ? (int) $variant['cct_min_k'] : '?';
            $max = $variant['cct_max_k'] !== null ? (int) $variant['cct_max_k'] : '?';
            $cct = $min.'–'.$max.'K';
        } else {
            $cct = '色温待确认';
        }
        $parts = [$cct];
        if ($variant['cri'] !== null) $parts[] = 'CRI'.$this->number($variant['cri']);
        if ($variant['sdcm'] !== null) $parts[] = 'SDCM≤'.$this->number($variant['sdcm']);
        if ($variant['r9'] !== null) $parts[] = 'R9 '.$this->number($variant['r9']);
        return implode(' / ', $parts);
    }

    private function validateApplyTargets(array $templateIds, array $materialIds, string $mode): array
    {
        $templateIds = array_values(array_unique(array_filter(array_map('intval', $templateIds))));
        $materialIds = array_values(array_unique(array_filter(array_map('intval', $materialIds))));
        $mode = $mode === 'replace' ? 'replace' : 'fill_missing';
        if (!$templateIds) throw new RuntimeException('请至少选择一个规格模板。');
        if (!$materialIds) throw new RuntimeException('请至少选择一个芯片物料。');
        if (count($materialIds) > 1000) throw new RuntimeException('一次最多处理 1000 个芯片，请分批执行。');
        $marks = implode(',', array_fill(0, count($templateIds), '?'));
        if ((int) $this->value("SELECT COUNT(*) FROM mc_chip_spec_templates WHERE id IN($marks) AND status='active'", $templateIds) !== count($templateIds)) {
            throw new RuntimeException('所选规格模板已变更，请刷新后重试。');
        }
        $marks = implode(',', array_fill(0, count($materialIds), '?'));
        if ((int) $this->value("SELECT COUNT(*) FROM mc_materials m JOIN mc_material_categories c ON c.id=m.category_id
            WHERE m.id IN($marks) AND c.code='chip' AND m.deleted_at IS NULL", $materialIds) !== count($materialIds)) {
            throw new RuntimeException('所选物料中包含非芯片物料或已删除物料。');
        }
        return [$templateIds, $materialIds, $mode];
    }

    private function templateCombinations(array $templateIds): array
    {
        $marks = implode(',', array_fill(0, count($templateIds), '?'));
        $rows = $this->all("SELECT t.id,t.current_version_no,v.combinations_json
            FROM mc_chip_spec_templates t
            JOIN mc_chip_spec_template_versions v ON v.template_id=t.id AND v.version_no=t.current_version_no
            WHERE t.id IN($marks) AND t.status='active'", $templateIds);
        $merged = [];
        foreach ($rows as $row) {
            foreach ($this->normalizeCombinations(json_decode((string) $row['combinations_json'], true) ?: []) as $combination) {
                $key = $combination['spec_key'];
                if (isset($merged[$key])) continue;
                $combination['_template_id'] = (int) $row['id'];
                $combination['_template_version_no'] = (int) $row['current_version_no'];
                $merged[$key] = $combination;
            }
        }
        return array_values($merged);
    }

    private function normalizeSelection(array $selection): array
    {
        $out = [];
        foreach (['cct', 'cri', 'sdcm'] as $key) {
            $values = array_values(array_unique(array_map(
                static fn(mixed $value): float|int => (float) $value == (int) $value ? (int) $value : round((float) $value, 2),
                array_filter((array) ($selection[$key] ?? []), static fn(mixed $value): bool => is_numeric($value))
            )));
            sort($values, SORT_NUMERIC);
            $out[$key] = $values;
        }
        return $out;
    }

    private function normalizeCombinations(array $combinations): array
    {
        $out = [];
        foreach ($combinations as $raw) {
            if (!is_array($raw)) continue;
            $cct = $this->requiredNumber($raw['cct_k'] ?? $raw['cct'] ?? null, '色温', 1000, 20000);
            $cri = $this->requiredNumber($raw['cri'] ?? null, '显指', 0, 100);
            $sdcm = $this->requiredNumber($raw['sdcm'] ?? null, '色容差', 0, 20);
            $combination = [
                'cct_k' => (int) $cct,
                'cri' => round($cri, 2),
                'sdcm' => round($sdcm, 2),
                'r9' => $this->optionalNumber($raw['r9'] ?? null, -100, 100),
                'luminous_flux_lm' => $this->optionalNumber($raw['luminous_flux_lm'] ?? null, 0, 1000000),
                'efficacy_lm_w' => $this->optionalNumber($raw['efficacy_lm_w'] ?? null, 0, 10000),
                'supplier_spec_code' => $this->nullable($raw['supplier_spec_code'] ?? '', 160),
                'purchase_price' => $this->optionalNumber($raw['purchase_price'] ?? null, 0, 100000000),
                'currency' => strtoupper(substr(trim((string) ($raw['currency'] ?? 'USD')), 0, 3)) ?: 'USD',
                'stock_quantity' => $this->optionalNumber($raw['stock_quantity'] ?? null, 0, 1000000000),
                'lead_time_days' => $this->optionalInt($raw['lead_time_days'] ?? null, 0, 3650),
            ];
            $combination['spec_key'] = hash('sha256', $this->json([
                $combination['cct_k'],
                $combination['cri'],
                $combination['sdcm'],
                $combination['r9'],
                $combination['supplier_spec_code'],
            ]));
            $out[$combination['spec_key']] = $combination;
        }
        return array_values($out);
    }

    private function insertVariant(
        int $materialId,
        array $combination,
        string $sourceType,
        ?int $templateId,
        ?int $templateVersion,
        int $userId,
        int $sortOrder
    ): int {
        $code = 'SPEC-'.substr(strtoupper($combination['spec_key']), 0, 12);
        $this->db->prepare("INSERT INTO mc_chip_spec_variants
            (material_id,variant_code,spec_key,cct_k,cct_min_k,cct_max_k,cri,sdcm,r9,luminous_flux_lm,efficacy_lm_w,
             supplier_spec_code,purchase_price,currency,stock_quantity,lead_time_days,source_type,source_template_id,
             source_template_version_no,is_default,needs_confirmation,status,sort_order,created_by,updated_by,created_at,updated_at)
            VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,0,0,'active',?,?,?,NOW(),NOW())")
            ->execute([
                $materialId, $code, $combination['spec_key'], $combination['cct_k'], $combination['cct_k'], $combination['cct_k'],
                $combination['cri'], $combination['sdcm'], $combination['r9'], $combination['luminous_flux_lm'],
                $combination['efficacy_lm_w'], $combination['supplier_spec_code'], $combination['purchase_price'],
                $combination['currency'], $combination['stock_quantity'], $combination['lead_time_days'], $sourceType,
                $templateId, $templateVersion, $sortOrder, $userId, $userId,
            ]);
        return (int) $this->db->lastInsertId();
    }

    private function ensureMaterialDefault(int $materialId, int $userId): void
    {
        $default = (int) $this->value("SELECT COUNT(*) FROM mc_chip_spec_variants WHERE material_id=? AND status='active' AND is_default=1", [$materialId]);
        if ($default) return;
        $id = (int) $this->value("SELECT id FROM mc_chip_spec_variants WHERE material_id=? AND status='active' ORDER BY needs_confirmation,is_default DESC,sort_order,id LIMIT 1", [$materialId]);
        if ($id) {
            $this->db->prepare("UPDATE mc_chip_spec_variants SET is_default=1,updated_by=?,updated_at=NOW() WHERE id=?")->execute([$userId, $id]);
        }
    }

    private function chipMaterial(int $materialId): array
    {
        $rows = $this->all("SELECT m.id,m.material_code,m.name,m.brand,m.model,m.status,m.is_official
            FROM mc_materials m JOIN mc_material_categories c ON c.id=m.category_id
            WHERE m.id=? AND c.code='chip' AND m.deleted_at IS NULL", [$materialId]);
        if (!$rows) throw new RuntimeException('芯片物料不存在。', 404);
        return $rows[0];
    }

    private function optionChipMaterial(int $optionId, bool $required = true): ?array
    {
        $rows = $this->all("SELECT o.id option_id,o.material_id,g.id group_id,g.product_id,m.material_code,m.name material_name
            FROM mc_adaptation_options o
            JOIN mc_adaptation_groups g ON g.id=o.group_id
            JOIN mc_materials m ON m.id=o.material_id
            JOIN mc_material_categories c ON c.id=m.category_id
            WHERE o.id=? AND c.code='chip'", [$optionId]);
        if (!$rows && $required) throw new RuntimeException('当前选项不是芯片物料。');
        return $rows[0] ?? null;
    }

    private function assertVariantIds(int $materialId, array $variantIds, bool $includeDisabled): void
    {
        $variantIds = array_values(array_unique(array_filter($variantIds)));
        if (!$variantIds) return;
        $marks = implode(',', array_fill(0, count($variantIds), '?'));
        $sql = "SELECT COUNT(*) FROM mc_chip_spec_variants WHERE material_id=? AND id IN($marks)";
        if (!$includeDisabled) $sql .= " AND status='active'";
        if ((int) $this->value($sql, array_merge([$materialId], $variantIds)) !== count($variantIds)) {
            throw new RuntimeException('所选规格不属于当前芯片，或规格已经停用。');
        }
    }

    private function variantHasApprovedUse(int $variantId): bool
    {
        return (bool) $this->value("SELECT 1
            FROM mc_adaptation_option_chip_variants cv
            JOIN mc_adaptation_options o ON o.id=cv.option_id
            JOIN mc_adaptation_groups g ON g.id=o.group_id
            WHERE cv.chip_variant_id=? AND cv.status='active' AND o.status='approved' AND g.status='approved'
            LIMIT 1", [$variantId]);
    }

    private function requiredNumber(mixed $value, string $label, float $min, float $max): float
    {
        if ($value === '' || $value === null || !is_numeric($value)) throw new RuntimeException($label.'必须填写数字。');
        $number = (float) $value;
        if ($number < $min || $number > $max) throw new RuntimeException($label.'超出允许范围。');
        return $number;
    }

    private function optionalNumber(mixed $value, float $min, float $max): ?float
    {
        if ($value === '' || $value === null) return null;
        if (!is_numeric($value)) throw new RuntimeException('规格数值格式不正确。');
        $number = (float) $value;
        if ($number < $min || $number > $max) throw new RuntimeException('规格数值超出允许范围。');
        return round($number, 4);
    }

    private function optionalInt(mixed $value, int $min, int $max): ?int
    {
        $number = $this->optionalNumber($value, $min, $max);
        return $number === null ? null : (int) round($number);
    }

    private function nullable(mixed $value, int $length): ?string
    {
        $value = trim((string) $value);
        return $value === '' ? null : mb_substr($value, 0, $length);
    }

    private function number(mixed $value): string
    {
        return rtrim(rtrim(number_format((float) $value, 2, '.', ''), '0'), '.');
    }

    private function operation(string $type, int $id, string $action, array $data, int $userId): void
    {
        if (!\mc_table_exists('mc_operation_logs')) return;
        $this->db->prepare("INSERT INTO mc_operation_logs
            (module,object_type,object_id,action,new_value_json,actor_id,actor_ip,result,created_at)
            VALUES('material_center',?,?,?,?,?,?,'success',NOW())")
            ->execute([$type, $id, $action, $this->json($data), $userId, (string) ($_SERVER['REMOTE_ADDR'] ?? 'cli')]);
    }

    private function all(string $sql, array $params = []): array
    {
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function value(string $sql, array $params = []): mixed
    {
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchColumn();
    }

    private function json(mixed $value): string
    {
        return (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
