<?php
declare(strict_types=1);

namespace Artdon\MaterialCenter\Services;

use Artdon\MaterialCenter\Security\MaterialCenterUserContext;
use PDO;
use RuntimeException;
use Throwable;

final class PowerEditorService
{
    private const INSTALLATION_TYPES = [
        'unknown', 'internal', 'external', 'remote', 'integrated', 'track_builtin', 'junction_box',
    ];
    private const OUTPUT_TYPES = ['unknown', 'constant_current', 'constant_voltage'];
    private const DIMMING_MODES = ['none', 'dali', 'dali_2', 'triac', '0_10v', '1_10v', 'push', 'dmx', 'nfc'];
    private const SCALAR_FIELDS = [
        'nominal_power_w', 'min_output_power_w', 'max_output_power_w', 'power_band_id',
        'input_voltage_min_v', 'input_voltage_max_v',
        'input_frequency_min_hz', 'input_frequency_max_hz',
        'output_type', 'output_voltage_min_v', 'output_voltage_max_v',
        'power_factor', 'efficiency', 'installation_type',
        'length_mm', 'width_mm', 'height_mm', 'ip_rating',
        'supplier_warranty_years', 'purchase_price', 'currency', 'moq',
        'lead_time_days', 'certification',
    ];

    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?: \db();
    }

    public function schema(MaterialCenterUserContext $user): array
    {
        $bands = $this->db->query(
            "SELECT id,code,name,min_power_w,max_power_w,max_inclusive
             FROM mc_power_bands WHERE status='active' ORDER BY sort_order,id"
        )->fetchAll(PDO::FETCH_ASSOC);

        return [
            'bands' => $bands,
            'installation_types' => [
                ['value' => 'unknown', 'label' => '待确认'],
                ['value' => 'internal', 'label' => '内置'],
                ['value' => 'external', 'label' => '外置'],
                ['value' => 'remote', 'label' => '远置'],
                ['value' => 'integrated', 'label' => '一体式'],
                ['value' => 'track_builtin', 'label' => '轨道内置'],
                ['value' => 'junction_box', 'label' => '接线盒'],
            ],
            'output_types' => [
                ['value' => 'unknown', 'label' => '待确认'],
                ['value' => 'constant_current', 'label' => '恒流'],
                ['value' => 'constant_voltage', 'label' => '恒压'],
            ],
            'dimming_modes' => [
                ['value' => 'none', 'label' => '不调光'],
                ['value' => 'dali', 'label' => 'DALI'],
                ['value' => 'dali_2', 'label' => 'DALI-2'],
                ['value' => 'triac', 'label' => 'Triac'],
                ['value' => '0_10v', 'label' => '0–10V'],
                ['value' => '1_10v', 'label' => '1–10V'],
                ['value' => 'push', 'label' => 'Push'],
                ['value' => 'dmx', 'label' => 'DMX'],
                ['value' => 'nfc', 'label' => 'NFC'],
            ],
            'can_view_price' => $user->isSuperAdmin || $this->canEditSensitive($user),
            'can_approve' => $user->isSuperAdmin
                || (new \Artdon\MaterialCenter\Security\PermissionService($this->db))->allows($user, 'material_center.approve'),
        ];
    }

    public function detail(int $materialId, MaterialCenterUserContext $user): array
    {
        $stmt = $this->db->prepare(
            "SELECT m.id,m.material_code,m.category_id,m.name,m.brand,m.model,m.unit,m.status,m.source,
                    m.is_official,m.updated_at,md.spec_summary,md.supplier_text,md.remark,md.lock_version,
                    (SELECT MIN(sm.source_record_id) FROM mc_source_mappings sm WHERE sm.material_id=m.id) source_record_id,
                    p.*,b.name AS power_band_name
             FROM mc_materials m
             JOIN mc_material_categories c ON c.id=m.category_id AND c.code='power_supply'
             JOIN mc_power_supply_specs p ON p.material_id=m.id
             LEFT JOIN mc_material_metadata md ON md.material_id=m.id
             LEFT JOIN mc_power_bands b ON b.id=p.power_band_id
             WHERE m.id=? AND m.deleted_at IS NULL"
        );
        $stmt->execute([$materialId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new RuntimeException('电源记录不存在。', 404);
        }
        if (!$user->isSuperAdmin && !$this->canEditSensitive($user)) {
            $row['purchase_price'] = null;
        }
        $row['currents'] = $this->currents($materialId);
        $row['dimming_modes'] = $this->dimming($materialId);
        $row['editable'] = $row['status'] === 'draft';
        return $row;
    }

    public function save(int $materialId, array $data, MaterialCenterUserContext $user): array
    {
        $this->db->beginTransaction();
        try {
            if ($materialId <= 0) {
                $categoryStmt = $this->db->query(
                    "SELECT id FROM mc_material_categories WHERE code='power_supply' AND status='active' LIMIT 1"
                );
                $categoryId = (int)$categoryStmt->fetchColumn();
                if (!$categoryId) {
                    throw new RuntimeException('电源分类不存在。');
                }
                $materialId = (new MaterialMasterService($this->db))->save([
                    'category_id' => $categoryId,
                    'name' => (string)($data['name'] ?? ''),
                    'brand' => (string)($data['brand'] ?? ''),
                    'model' => (string)($data['model'] ?? ''),
                    'unit' => (string)($data['unit'] ?? 'PCS'),
                    'spec_summary' => (string)($data['spec_summary'] ?? ''),
                ], $user->id);
                $data['lock_version'] = 1;
            }
            $current = $this->lockPower($materialId);
            if ($current['status'] !== 'draft') {
                throw new RuntimeException('只有草稿电源可以直接编辑；正式电源请复制新版本后修改。');
            }
            $expected = (int)($data['lock_version'] ?? $current['lock_version']);
            if ($expected !== (int)$current['lock_version']) {
                throw new RuntimeException('记录已被其他用户更新，请刷新后重试。', 409);
            }
            $name = trim((string)($data['name'] ?? ''));
            if ($name === '') {
                throw new RuntimeException('电源名称不能为空。');
            }
            $this->db->prepare(
                'UPDATE mc_materials SET name=?,brand=?,model=?,unit=?,updated_by=?,updated_at=NOW() WHERE id=?'
            )->execute([
                mb_substr($name, 0, 200),
                $this->nullable($data['brand'] ?? '', 120),
                $this->nullable($data['model'] ?? '', 160),
                mb_substr(trim((string)($data['unit'] ?? 'PCS')) ?: 'PCS', 0, 30),
                $user->id,
                $materialId,
            ]);
            $this->db->prepare(
                'UPDATE mc_material_metadata SET spec_summary=?,supplier_text=?,remark=?,lock_version=lock_version+1 WHERE material_id=?'
            )->execute([
                $this->nullable($data['spec_summary'] ?? '', 2000),
                $this->nullable($data['supplier_text'] ?? '', 200),
                $this->nullable($data['remark'] ?? '', 5000),
                $materialId,
            ]);

            $fields = $this->validatedScalarFields($data, $user);
            $this->updatePower($materialId, $fields);
            $currents = $this->validateCurrents($data['currents'] ?? []);
            $dimming = $this->validateDimming($data['dimming_modes'] ?? [], (string)($data['primary_dimming'] ?? ''));
            $this->replaceCurrents($materialId, $currents);
            $this->replaceDimming($materialId, $dimming);
            $this->log($materialId, 'power_edit', [
                'fields' => array_keys($fields),
                'currents' => $currents,
                'dimming_modes' => $dimming,
            ], $user->id);
            $this->db->commit();
            return $this->detail($materialId, $user);
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    public function createFromSource(int $sourceRecordId, array $data, MaterialCenterUserContext $user): array
    {
        if ($sourceRecordId <= 0) {
            throw new RuntimeException('电源来源记录无效。');
        }
        $organizer = new SourceMaterialOrganizerService($this->db);
        $mappedMaterialId = $organizer->mappedMaterialId($sourceRecordId, 'power_supply');
        if ($mappedMaterialId > 0) {
            $current = $this->detail($mappedMaterialId, $user);
            if ($current['status'] !== 'draft') {
                throw new RuntimeException('该来源已映射物料，当前状态不能直接编辑。');
            }
            $data['lock_version'] = (int) $current['lock_version'];
            return $this->save($mappedMaterialId, $data, $user);
        }
        $currentOptions = $this->validateCurrents($data['currents'] ?? []);
        if (!$currentOptions['values']) {
            throw new RuntimeException('请至少确认一个有效输出电流。');
        }
        $primaryCurrent = $currentOptions['default'] ?? $currentOptions['values'][0];
        $staged = (new PowerStandardizationService($this->db))->stageSourceRecord($sourceRecordId, $user->id);
        $confirmed = $data;
        $confirmed['current_options_ma'] = $currentOptions['values'];
        $confirmed['output_current_ma'] = $primaryCurrent;
        $confirmed['output_current_min_ma'] = min($currentOptions['values']);
        $confirmed['output_current_max_ma'] = max($currentOptions['values']);
        $materialId = (new PowerStandardizationService($this->db))->confirmAndCreate(
            (int)$staged['staging_id'],
            $confirmed,
            $user->id
        );
        $data['lock_version'] = 1;
        $detail = $this->save($materialId, $data, $user);
        $organizer->markReviewed($sourceRecordId, $materialId, 100.0, $user->id);
        return $detail;
    }

    public function batchPreview(array $ids, array $changes, string $policy, MaterialCenterUserContext $user): array
    {
        $ids = $this->validatedIds($ids);
        if (!in_array($policy, ['fill_empty', 'overwrite'], true)) {
            throw new RuntimeException('覆盖策略无效。');
        }
        $clean = $this->validatedBatchChanges($changes, $user);
        $rows = $this->batchRows($ids);
        $items = [];
        $affected = 0;
        foreach ($rows as $row) {
            $actual = [];
            foreach ($clean as $key => $value) {
                if ($policy === 'fill_empty' && !$this->isEmptyBatchValue($row, $key)) {
                    continue;
                }
                $actual[$key] = $value;
            }
            if ($actual) {
                $affected++;
            }
            $items[] = [
                'id' => (int)$row['target_material_id'],
                'code' => $row['material_code'],
                'name' => $row['name'],
                'changes' => $actual,
                'skipped' => !$actual,
                'skip_reason' => $actual ? '' : '现有值非空',
            ];
        }
        return [
            'selected' => count($ids),
            'found' => count($rows),
            'affected' => $affected,
            'skipped' => count($rows) - $affected,
            'changes' => $clean,
            'policy' => $policy,
            'items' => $items,
        ];
    }

    public function batchExecute(array $ids, array $changes, string $policy, MaterialCenterUserContext $user): array
    {
        $preview = $this->batchPreview($ids, $changes, $policy, $user);
        $uuid = $this->uuid();
        $this->db->beginTransaction();
        try {
            $this->db->prepare(
                "INSERT INTO mc_batch_jobs
                 (job_uuid,entity_type,selection_scope,selection_json,changes_json,overwrite_policy,status,total_count,created_by,created_at)
                 VALUES(?,'power_supply','selected',?,?,?,'running',?,?,NOW())"
            )->execute([
                $uuid,
                json_encode(array_values($ids)),
                json_encode($preview['changes'], JSON_UNESCAPED_UNICODE),
                $policy,
                $preview['found'],
                $user->id,
            ]);
            $jobId = (int)$this->db->lastInsertId();
            $success = 0;
            $skipped = 0;
            foreach ($preview['items'] as $item) {
                if (!$item['changes']) {
                    $skipped++;
                    $this->db->prepare(
                        "INSERT INTO mc_batch_job_items(batch_job_id,entity_id,result,error_message)
                         VALUES(?,?,'skipped',?)"
                    )->execute([$jobId, $item['id'], $item['skip_reason']]);
                    continue;
                }
                $before = $this->snapshot((int)$item['id']);
                $this->applyBatchChanges((int)$item['id'], $item['changes'], $user);
                $after = $this->snapshot((int)$item['id']);
                $this->db->prepare(
                    "INSERT INTO mc_batch_job_items(batch_job_id,entity_id,before_json,after_json,result)
                     VALUES(?,?,?,?,'success')"
                )->execute([
                    $jobId,
                    $item['id'],
                    json_encode($before, JSON_UNESCAPED_UNICODE),
                    json_encode($after, JSON_UNESCAPED_UNICODE),
                ]);
                $this->log((int)$item['id'], 'power_batch_edit', ['job_uuid' => $uuid, 'changes' => $item['changes']], $user->id);
                $success++;
            }
            $this->db->prepare(
                "UPDATE mc_batch_jobs SET status='completed',success_count=?,skipped_count=?,executed_at=NOW() WHERE id=?"
            )->execute([$success, $skipped, $jobId]);
            $this->db->commit();
            return ['job_uuid' => $uuid, 'success' => $success, 'skipped' => $skipped, 'failed' => 0];
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    public function rollback(string $jobUuid, MaterialCenterUserContext $user): array
    {
        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare(
                "SELECT * FROM mc_batch_jobs WHERE job_uuid=? AND entity_type='power_supply' FOR UPDATE"
            );
            $stmt->execute([$jobUuid]);
            $job = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$job || $job['status'] !== 'completed' || $job['rolled_back_at'] !== null) {
                throw new RuntimeException('批次不存在、未完成或已经回滚。');
            }
            $items = $this->db->prepare(
                "SELECT entity_id,before_json FROM mc_batch_job_items WHERE batch_job_id=? AND result='success'"
            );
            $items->execute([(int)$job['id']]);
            $count = 0;
            foreach ($items->fetchAll(PDO::FETCH_ASSOC) as $item) {
                $snapshot = json_decode((string)$item['before_json'], true);
                if (!is_array($snapshot)) {
                    throw new RuntimeException('批次快照损坏，已停止回滚。');
                }
                $this->restoreSnapshot((int)$item['entity_id'], $snapshot, $user);
                $this->log((int)$item['entity_id'], 'power_batch_rollback', ['job_uuid' => $jobUuid], $user->id);
                $count++;
            }
            $this->db->prepare(
                "UPDATE mc_batch_jobs SET status='rolled_back',rolled_back_at=NOW() WHERE id=?"
            )->execute([(int)$job['id']]);
            $this->db->commit();
            return ['job_uuid' => $jobUuid, 'restored' => $count];
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    private function validatedBatchChanges(array $changes, MaterialCenterUserContext $user): array
    {
        $clean = [];
        foreach ($changes as $key => $value) {
            if ($key === 'currents') {
                $clean[$key] = $this->validateCurrents($value);
            } elseif ($key === 'dimming_modes') {
                $clean[$key] = $this->validateDimming($value, (string)($changes['primary_dimming'] ?? ''));
            } elseif ($key !== 'primary_dimming' && in_array($key, self::SCALAR_FIELDS, true)) {
                $clean[$key] = $this->validatedScalarFields([$key => $value], $user)[$key];
            }
        }
        if (!$clean) {
            throw new RuntimeException('请至少启用一个批量设置项。');
        }
        return $clean;
    }

    private function validatedScalarFields(array $data, MaterialCenterUserContext $user): array
    {
        $fields = [];
        foreach (self::SCALAR_FIELDS as $key) {
            if (!array_key_exists($key, $data)) {
                continue;
            }
            $raw = is_string($data[$key]) ? trim($data[$key]) : $data[$key];
            if ($raw === '') {
                $fields[$key] = null;
                continue;
            }
            if ($key === 'installation_type') {
                if (!in_array($raw, self::INSTALLATION_TYPES, true)) {
                    throw new RuntimeException('安装方式无效。');
                }
                $fields[$key] = $raw;
            } elseif ($key === 'output_type') {
                if (!in_array($raw, self::OUTPUT_TYPES, true)) {
                    throw new RuntimeException('输出类型无效。');
                }
                $fields[$key] = $raw;
            } elseif (in_array($key, ['currency', 'ip_rating', 'certification'], true)) {
                $fields[$key] = mb_substr((string)$raw, 0, $key === 'certification' ? 500 : 30);
            } elseif ($key === 'power_band_id' || $key === 'lead_time_days') {
                if (!is_numeric($raw) || (int)$raw < 0) {
                    throw new RuntimeException($key === 'power_band_id' ? '功率档无效。' : '交期必须是非负整数。');
                }
                $fields[$key] = (int)$raw ?: null;
            } else {
                if (!is_numeric($raw) || (float)$raw < 0) {
                    throw new RuntimeException('数字字段必须是非负数。');
                }
                $fields[$key] = (float)$raw;
            }
        }
        if (array_key_exists('purchase_price', $fields) && !$user->isSuperAdmin && !$this->canEditSensitive($user)) {
            throw new RuntimeException('没有修改采购价的权限。', 403);
        }
        $minimum = $fields['min_output_power_w'] ?? null;
        $maximum = $fields['max_output_power_w'] ?? null;
        if ($minimum !== null && $maximum !== null && $minimum > $maximum) {
            throw new RuntimeException('电源最低输出功率不能高于最高输出功率。');
        }
        return $fields;
    }

    private function validateCurrents($raw): array
    {
        if (is_string($raw)) {
            $raw = preg_split('/[,，;；\\s]+/u', $raw, -1, PREG_SPLIT_NO_EMPTY);
        }
        if (!is_array($raw)) {
            throw new RuntimeException('输出电流格式无效。');
        }
        $values = [];
        $default = null;
        foreach ($raw as $item) {
            if (is_array($item)) {
                $value = $item['value'] ?? $item['current_ma'] ?? '';
                $isDefault = !empty($item['is_default']);
            } else {
                $value = $item;
                $isDefault = false;
            }
            if ($value === '' || $value === null) {
                continue;
            }
            if (!is_numeric($value) || (float)$value <= 0 || (float)$value > 100000) {
                throw new RuntimeException('输出电流必须在 0–100000mA 范围内。');
            }
            $number = round((float)$value, 2);
            $values[(string)$number] = $number;
            if ($isDefault) {
                $default = $number;
            }
        }
        $values = array_values($values);
        sort($values, SORT_NUMERIC);
        if ($values && ($default === null || !in_array($default, $values, true))) {
            $default = $values[0];
        }
        return ['values' => $values, 'default' => $default];
    }

    private function validateDimming($raw, string $primary): array
    {
        if (is_string($raw)) {
            $raw = array_filter(array_map('trim', explode(',', $raw)));
        }
        if (!is_array($raw)) {
            throw new RuntimeException('调光方式格式无效。');
        }
        $modes = array_values(array_unique(array_map('strval', $raw)));
        foreach ($modes as $mode) {
            if (!in_array($mode, self::DIMMING_MODES, true)) {
                throw new RuntimeException('调光方式选项无效。');
            }
        }
        if (in_array('none', $modes, true) && count($modes) > 1) {
            throw new RuntimeException('“不调光”不能与其他调光方式同时选择。');
        }
        if ($modes && !in_array($primary, $modes, true)) {
            $primary = $modes[0];
        }
        return ['values' => $modes, 'primary' => $primary];
    }

    private function validatedIds(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if (!$ids || count($ids) > 500) {
            throw new RuntimeException('请选择 1–500 条电源记录。');
        }
        return $ids;
    }

    private function batchRows(array $ids): array
    {
        $marks = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->db->prepare(
            "SELECT m.id AS target_material_id,m.material_code,m.name,m.status,p.*,
                    (SELECT COUNT(*) FROM mc_power_supply_current_options c WHERE c.material_id=m.id) current_count,
                    (SELECT COUNT(*) FROM mc_power_supply_dimming_modes d WHERE d.material_id=m.id) dimming_count
             FROM mc_materials m JOIN mc_power_supply_specs p ON p.material_id=m.id
             WHERE m.deleted_at IS NULL AND m.status='draft' AND m.id IN($marks)"
        );
        $stmt->execute($ids);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function isEmptyBatchValue(array $row, string $key): bool
    {
        if ($key === 'currents') {
            return (int)$row['current_count'] === 0;
        }
        if ($key === 'dimming_modes') {
            return (int)$row['dimming_count'] === 0;
        }
        return !isset($row[$key]) || $row[$key] === '' || $row[$key] === 'unknown';
    }

    private function applyBatchChanges(int $materialId, array $changes, MaterialCenterUserContext $user): void
    {
        $scalars = array_intersect_key($changes, array_flip(self::SCALAR_FIELDS));
        if ($scalars) {
            $this->updatePower($materialId, $scalars);
        }
        if (isset($changes['currents'])) {
            $this->replaceCurrents($materialId, $changes['currents']);
        }
        if (isset($changes['dimming_modes'])) {
            $this->replaceDimming($materialId, $changes['dimming_modes']);
        }
        $this->db->prepare(
            'UPDATE mc_materials SET updated_by=?,updated_at=NOW() WHERE id=?'
        )->execute([$user->id, $materialId]);
        $this->db->prepare(
            'UPDATE mc_material_metadata SET lock_version=lock_version+1 WHERE material_id=?'
        )->execute([$materialId]);
    }

    private function snapshot(int $materialId): array
    {
        $stmt = $this->db->prepare('SELECT * FROM mc_power_supply_specs WHERE material_id=?');
        $stmt->execute([$materialId]);
        return [
            'power' => $stmt->fetch(PDO::FETCH_ASSOC),
            'currents' => $this->currents($materialId),
            'dimming_modes' => $this->dimming($materialId),
        ];
    }

    private function restoreSnapshot(int $materialId, array $snapshot, MaterialCenterUserContext $user): void
    {
        $power = (array)($snapshot['power'] ?? []);
        $fields = array_intersect_key($power, array_flip(self::SCALAR_FIELDS));
        $this->updatePower($materialId, $fields);
        $this->replaceCurrents($materialId, $this->validateCurrents((array)($snapshot['currents'] ?? [])));
        $dimmingRows = (array)($snapshot['dimming_modes'] ?? []);
        $primary = '';
        $modes = [];
        foreach ($dimmingRows as $row) {
            $modes[] = (string)$row['mode'];
            if (!empty($row['is_primary'])) {
                $primary = (string)$row['mode'];
            }
        }
        $this->replaceDimming($materialId, $this->validateDimming($modes, $primary));
        $this->db->prepare(
            'UPDATE mc_materials SET updated_by=?,updated_at=NOW() WHERE id=?'
        )->execute([$user->id, $materialId]);
        $this->db->prepare(
            'UPDATE mc_material_metadata SET lock_version=lock_version+1 WHERE material_id=?'
        )->execute([$materialId]);
    }

    private function lockPower(int $materialId): array
    {
        $stmt = $this->db->prepare(
            'SELECT m.status,md.lock_version FROM mc_materials m JOIN mc_material_metadata md ON md.material_id=m.id
             JOIN mc_power_supply_specs p ON p.material_id=m.id WHERE m.id=? AND m.deleted_at IS NULL FOR UPDATE'
        );
        $stmt->execute([$materialId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new RuntimeException('电源记录不存在。', 404);
        }
        return $row;
    }

    private function updatePower(int $materialId, array $fields): void
    {
        if (!$fields) {
            return;
        }
        if (array_key_exists('min_output_power_w', $fields) || array_key_exists('max_output_power_w', $fields)) {
            $range = $this->db->prepare('SELECT min_output_power_w,max_output_power_w FROM mc_power_supply_specs WHERE material_id=?');
            $range->execute([$materialId]);
            $existing = $range->fetch(PDO::FETCH_ASSOC) ?: [];
            $minimum = $fields['min_output_power_w'] ?? $existing['min_output_power_w'] ?? null;
            $maximum = $fields['max_output_power_w'] ?? $existing['max_output_power_w'] ?? null;
            if ($minimum !== null && $maximum !== null && (float)$minimum > (float)$maximum) {
                throw new RuntimeException('电源最低输出功率不能高于最高输出功率。');
            }
        }
        $sets = [];
        $values = [];
        foreach ($fields as $key => $value) {
            if (!in_array($key, self::SCALAR_FIELDS, true)) {
                continue;
            }
            $sets[] = "`$key`=?";
            $values[] = $value;
        }
        if (!$sets) {
            return;
        }
        $sets[] = 'updated_at=NOW()';
        $values[] = $materialId;
        $stmt = $this->db->prepare(
            'UPDATE mc_power_supply_specs SET '.implode(',', $sets).' WHERE material_id=?'
        );
        $stmt->execute($values);
    }

    private function replaceCurrents(int $materialId, array $currents): void
    {
        $this->db->prepare('DELETE FROM mc_power_supply_current_options WHERE material_id=?')->execute([$materialId]);
        $insert = $this->db->prepare(
            "INSERT INTO mc_power_supply_current_options(material_id,current_ma,is_default,source)
             VALUES(?,?,?,'manual')"
        );
        foreach ($currents['values'] as $value) {
            $insert->execute([$materialId, $value, $value === $currents['default'] ? 1 : 0]);
        }
        $this->db->prepare(
            'UPDATE mc_power_supply_specs SET output_current_ma=?,output_current_min_ma=?,output_current_max_ma=? WHERE material_id=?'
        )->execute([
            $currents['default'],
            $currents['values'] ? min($currents['values']) : null,
            $currents['values'] ? max($currents['values']) : null,
            $materialId,
        ]);
    }

    private function replaceDimming(int $materialId, array $dimming): void
    {
        $this->db->prepare('DELETE FROM mc_power_supply_dimming_modes WHERE material_id=?')->execute([$materialId]);
        $insert = $this->db->prepare(
            'INSERT INTO mc_power_supply_dimming_modes(material_id,mode,is_primary) VALUES(?,?,?)'
        );
        foreach ($dimming['values'] as $mode) {
            $insert->execute([$materialId, $mode, $mode === $dimming['primary'] ? 1 : 0]);
        }
    }

    private function currents(int $materialId): array
    {
        $stmt = $this->db->prepare(
            'SELECT current_ma,is_default FROM mc_power_supply_current_options WHERE material_id=? ORDER BY current_ma'
        );
        $stmt->execute([$materialId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function dimming(int $materialId): array
    {
        $stmt = $this->db->prepare(
            'SELECT mode,is_primary FROM mc_power_supply_dimming_modes WHERE material_id=? ORDER BY is_primary DESC,id'
        );
        $stmt->execute([$materialId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function canEditSensitive(MaterialCenterUserContext $user): bool
    {
        $stmt = $this->db->prepare(
            "SELECT 1 FROM mc_permission_grants
             WHERE permission_key='material_center.field.sensitive'
             AND ((subject_type='user' AND subject_id=?) OR (subject_type='role' AND subject_id=?))
             AND effect='allow' LIMIT 1"
        );
        $stmt->execute([(string)$user->id, $user->roleKey]);
        return (bool)$stmt->fetchColumn();
    }

    private function nullable($value, int $length): ?string
    {
        $value = trim((string)$value);
        return $value === '' ? null : mb_substr($value, 0, $length);
    }

    private function log(int $materialId, string $action, array $data, int $userId): void
    {
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $this->db->prepare(
            "INSERT INTO mc_activity_logs(entity_type,entity_id,action,after_json,actor_id,created_at)
             VALUES('material',?,?,?,?,NOW())"
        )->execute([$materialId, $action, $json, $userId]);
        if (\mc_table_exists('mc_operation_logs')) {
            $this->db->prepare(
                "INSERT INTO mc_operation_logs
                 (module,object_type,object_id,action,new_value_json,actor_id,actor_ip,result,created_at)
                 VALUES('material_center','material',?,?,?,?,?,'success',NOW())"
            )->execute([
                $materialId,
                $action,
                $json,
                $userId,
                (string)($_SERVER['REMOTE_ADDR'] ?? 'cli'),
            ]);
        }
    }

    private function uuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 15) | 64);
        $data[8] = chr((ord($data[8]) & 63) | 128);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
