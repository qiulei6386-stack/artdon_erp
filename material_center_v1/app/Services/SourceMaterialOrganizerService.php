<?php
declare(strict_types=1);

namespace Artdon\MaterialCenter\Services;

use Artdon\MaterialCenter\Domain\PowerSupply\PowerSpecParser;
use Artdon\MaterialCenter\Security\MaterialCenterUserContext;
use PDO;
use RuntimeException;
use Throwable;

final class SourceMaterialOrganizerService
{
    private const LEGACY_CATEGORIES = [
        'power_supply' => ['驱动', '电源'],
        'chip' => ['芯片'],
        'optical' => ['光学'],
        'profile' => ['型材', '外壳'],
        'connector' => ['接头'],
        'accessory' => ['附件'],
        'packaging' => ['包装'],
    ];

    public function __construct(private ?PDO $db = null)
    {
        $this->db ??= \db();
    }

    public function detail(int $sourceRecordId, string $category, MaterialCenterUserContext $user): array
    {
        $source = $this->source($sourceRecordId, $category);
        $snapshot = $this->snapshot($source);
        $parsed = $this->parse($category, $snapshot);
        $mapping = $this->mapping($sourceRecordId);
        $material = null;
        $values = [];

        if ($mapping) {
            $material = $this->material((int) $mapping['material_id'], $category);
            $values = (new CategoryFieldService($this->db))->values((int) $mapping['material_id'], $category);
        }

        return [
            'source' => [
                'id' => (int) $source['id'],
                'source_type' => (string) $source['source_system'],
                'source_system' => (string) $source['source_system'],
                'source_table' => (string) $source['source_table'],
                'source_id' => (string) $source['source_pk'],
                'raw_name' => (string) ($snapshot['name'] ?? ''),
                'raw_brand' => (string) ($snapshot['brand'] ?? ''),
                'raw_model' => (string) ($snapshot['model'] ?? ''),
                'raw_spec' => (string) ($snapshot['spec'] ?? ''),
                'raw_category' => (string) ($snapshot['category'] ?? ''),
                'snapshot' => $snapshot,
                'snapshot_hash' => (string) $source['snapshot_hash'],
                'read_at' => (string) $source['read_at'],
                'confidence_score' => $source['confidence_score'] !== null
                    ? (float) $source['confidence_score']
                    : $parsed['confidence_score'],
                'status' => (string) $source['status'],
                'changed' => (string) $source['status'] === 'changed'
                    || ($mapping && !hash_equals((string) ($mapping['source_snapshot_hash'] ?? ''), (string) $source['snapshot_hash'])),
            ],
            'defaults' => [
                'name' => (string) ($snapshot['name'] ?? ''),
                'brand' => (string) ($snapshot['brand'] ?? ''),
                'model' => (string) ($snapshot['model'] ?? ''),
                'unit' => trim((string) ($snapshot['unit'] ?? '')) ?: 'PCS',
                'spec_summary' => (string) ($snapshot['spec'] ?? ''),
                'supplier_text' => '',
                'remark' => '',
                'fields' => $parsed['fields'],
            ],
            'parse_result' => $parsed['log'],
            'material' => $material,
            'values' => $values,
            'can_approve' => $user->isSuperAdmin
                || (new \Artdon\MaterialCenter\Security\PermissionService($this->db))->allows($user, 'material_center.approve'),
        ];
    }

    public function save(
        int $sourceRecordId,
        string $category,
        array $data,
        string $mode,
        MaterialCenterUserContext $user
    ): array {
        if (!in_array($mode, ['draft', 'submit', 'approve'], true)) {
            throw new RuntimeException('来源整理操作无效。');
        }
        if ($category === 'power_supply') {
            throw new RuntimeException('电源来源请使用电源专用整理接口。');
        }

        $this->db->beginTransaction();
        try {
            $source = $this->source($sourceRecordId, $category, true);
            $snapshot = $this->snapshot($source);
            $parsed = $this->parse($category, $snapshot);
            $mapping = $this->mapping($sourceRecordId, true);
            $categoryId = $this->categoryId($category);
            $materialId = $mapping ? (int) $mapping['material_id'] : 0;
            $status = $materialId ? (string) $this->material($materialId, $category)['status'] : '';

            if ($status === '' || $status === 'draft') {
                $payload = [
                    'id' => $materialId,
                    'lock_version' => (int) ($data['lock_version'] ?? 1),
                    'category_id' => $categoryId,
                    'name' => (string) ($data['name'] ?? ''),
                    'brand' => (string) ($data['brand'] ?? ''),
                    'model' => (string) ($data['model'] ?? ''),
                    'unit' => (string) ($data['unit'] ?? 'PCS'),
                    'spec_summary' => (string) ($data['spec_summary'] ?? ''),
                    'fields' => is_array($data['fields'] ?? null) ? $data['fields'] : [],
                ];
                $materialId = (new MaterialMasterService($this->db))->save($payload, $user->id);
                $status = 'draft';
                $this->db->prepare(
                    "UPDATE mc_materials SET source='legacy_bom',updated_by=?,updated_at=NOW() WHERE id=?"
                )->execute([$user->id, $materialId]);
                $this->db->prepare(
                    "UPDATE mc_material_metadata
                     SET supplier_text=?,remark=?,source_type='legacy_bom',source_id=?,source_table=?,
                         source_snapshot_json=?,confidence_score=?
                     WHERE material_id=?"
                )->execute([
                    $this->nullable($data['supplier_text'] ?? '', 200),
                    $this->nullable($data['remark'] ?? '', 5000),
                    (int) $source['source_pk'],
                    (string) $source['source_table'],
                    (string) $source['snapshot_json'],
                    $parsed['confidence_score'],
                    $materialId,
                ]);
            } elseif ($mode !== 'approve' || $status !== 'pending_review') {
                throw new RuntimeException('当前物料状态不能继续编辑来源字段。');
            }

            if (!$mapping) {
                $this->db->prepare(
                    "INSERT INTO mc_source_mappings
                     (source_record_id,material_id,mapping_type,confidence_score,status,source_snapshot_hash,last_reviewed_at,decided_by,decided_at)
                     VALUES(?,?,'new_material',?,'confirmed',?,NOW(),?,NOW())"
                )->execute([$sourceRecordId, $materialId, $parsed['confidence_score'], $source['snapshot_hash'], $user->id]);
            } else {
                $this->db->prepare(
                    "UPDATE mc_source_mappings
                     SET material_id=?,confidence_score=?,status='confirmed',source_snapshot_hash=?,
                         last_reviewed_at=NOW(),decided_by=?,decided_at=NOW()
                     WHERE source_record_id=?"
                )->execute([$materialId, $parsed['confidence_score'], $source['snapshot_hash'], $user->id, $sourceRecordId]);
            }

            $this->db->prepare(
                "UPDATE mc_source_records
                 SET parse_result_json=?,confidence_score=?,matched_material_id=?,confirmed_by=?,
                     confirmed_at=NOW(),status='confirmed'
                 WHERE id=?"
            )->execute([
                json_encode($parsed['log'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                $parsed['confidence_score'],
                $materialId,
                $user->id,
                $sourceRecordId,
            ]);

            if ($mode === 'submit' && $status === 'draft') {
                $this->transition($materialId, 'draft', 'pending_review', 'submit', $user->id);
                $status = 'pending_review';
            } elseif ($mode === 'approve') {
                if ($status === 'draft') {
                    $this->transition($materialId, 'draft', 'pending_review', 'submit', $user->id);
                    $status = 'pending_review';
                }
                if ($status !== 'pending_review') {
                    throw new RuntimeException('只有待确认物料可以转正式。');
                }
                $this->transition($materialId, 'pending_review', 'official', 'approve', $user->id);
                $status = 'official';
            }

            $this->operationLog($sourceRecordId, $materialId, 'organize_' . $mode, $parsed, $user->id);
            $this->db->commit();
            return $this->detail($sourceRecordId, $category, $user);
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    public function mappedMaterialId(int $sourceRecordId, string $category): int
    {
        $this->source($sourceRecordId, $category);
        $mapping = $this->mapping($sourceRecordId);
        return (int) ($mapping['material_id'] ?? 0);
    }

    public function markReviewed(int $sourceRecordId, int $materialId, float $confidence, int $userId): void
    {
        $this->db->beginTransaction();
        try {
            $source = $this->source($sourceRecordId, 'power_supply', true);
            $mapping = $this->mapping($sourceRecordId, true);
            if ($mapping && (int) $mapping['material_id'] !== $materialId) {
                throw new RuntimeException('该来源已经映射到另一条物料。');
            }
            $sql = "INSERT INTO mc_source_mappings
                (source_record_id,material_id,mapping_type,confidence_score,status,source_snapshot_hash,last_reviewed_at,decided_by,decided_at)
                VALUES(?,?,'new_material',?,'confirmed',?,NOW(),?,NOW())
                ON DUPLICATE KEY UPDATE material_id=VALUES(material_id),confidence_score=VALUES(confidence_score),
                status='confirmed',source_snapshot_hash=VALUES(source_snapshot_hash),last_reviewed_at=NOW(),
                decided_by=VALUES(decided_by),decided_at=NOW()";
            $this->db->prepare($sql)->execute([
                $sourceRecordId, $materialId, $confidence, $source['snapshot_hash'], $userId,
            ]);
            $parsed = $this->parse('power_supply', $this->snapshot($source));
            $this->db->prepare(
                "UPDATE mc_source_records SET parse_result_json=?,confidence_score=?,matched_material_id=?,
                 confirmed_by=?,confirmed_at=NOW(),status='confirmed' WHERE id=?"
            )->execute([
                json_encode($parsed['log'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                $confidence,
                $materialId,
                $userId,
                $sourceRecordId,
            ]);
            $this->db->commit();
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    private function source(int $id, string $category, bool $lock = false): array
    {
        if (!isset(self::LEGACY_CATEGORIES[$category])) {
            throw new RuntimeException('来源分类无效。');
        }
        $stmt = $this->db->prepare(
            "SELECT * FROM mc_source_records
             WHERE id=? AND source_system='guangzhou_bom' AND source_table='bom_materials'" . ($lock ? ' FOR UPDATE' : '')
        );
        $stmt->execute([$id]);
        $source = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$source) {
            throw new RuntimeException('来源记录不存在。', 404);
        }
        $snapshot = $this->snapshot($source);
        if (!in_array((string) ($snapshot['category'] ?? ''), self::LEGACY_CATEGORIES[$category], true)) {
            throw new RuntimeException('来源记录不属于当前物料分类。');
        }
        return $source;
    }

    private function snapshot(array $source): array
    {
        $snapshot = json_decode((string) $source['snapshot_json'], true);
        if (!is_array($snapshot)) {
            throw new RuntimeException('来源快照无法读取。');
        }
        return $snapshot;
    }

    private function mapping(int $sourceRecordId, bool $lock = false): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM mc_source_mappings WHERE source_record_id=? ORDER BY id LIMIT 1' . ($lock ? ' FOR UPDATE' : '')
        );
        $stmt->execute([$sourceRecordId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private function material(int $materialId, string $category): array
    {
        $stmt = $this->db->prepare(
            "SELECT m.id,m.material_code,m.category_id,m.name,m.brand,m.model,m.unit,m.status,
                    md.spec_summary,md.supplier_text,md.remark,md.lock_version
             FROM mc_materials m
             JOIN mc_material_categories c ON c.id=m.category_id AND c.code=?
             JOIN mc_material_metadata md ON md.material_id=m.id
             WHERE m.id=? AND m.deleted_at IS NULL"
        );
        $stmt->execute([$category, $materialId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new RuntimeException('来源映射的物料不存在或分类不一致。');
        }
        return $row;
    }

    private function categoryId(string $category): int
    {
        $stmt = $this->db->prepare("SELECT id FROM mc_material_categories WHERE code=? AND status='active'");
        $stmt->execute([$category]);
        $id = (int) $stmt->fetchColumn();
        if (!$id) {
            throw new RuntimeException('物料分类不存在。');
        }
        return $id;
    }

    private function parse(string $category, array $snapshot): array
    {
        $text = trim(implode(' ', array_filter([
            $snapshot['name'] ?? '', $snapshot['brand'] ?? '', $snapshot['model'] ?? '', $snapshot['spec'] ?? '',
        ])));
        $values = [];
        $log = [];
        $put = static function (string $key, mixed $value, int $confidence, string $rule) use (&$values, &$log, $text): void {
            if ($value === '' || $value === null) {
                return;
            }
            $values[$key] = $value;
            $log[] = [
                'field' => $key,
                'candidate_value' => $value,
                'confidence' => $confidence,
                'rule' => $rule,
                'original_text' => $text,
            ];
        };

        if ($category === 'power_supply') {
            foreach ((new PowerSpecParser())->parse($snapshot) as $key => $result) {
                $value = $result['candidate_value'] ?? null;
                if ($key === 'current_options_ma') {
                    $value = json_decode((string) $value, true) ?: [];
                }
                $put('power.' . $key, $value, ['high' => 95, 'medium' => 70, 'low' => 40][$result['confidence'] ?? 'low'], (string) ($result['parse_rule'] ?? 'power_parser'));
            }
        } elseif ($category === 'chip') {
            $this->matchNumber($text, '/(\d+(?:\.\d+)?)\s*W\b/iu', fn ($v) => $put('chip.rated_power_w', $v, 82, 'power_w'));
            $this->matchNumber($text, '/(\d+(?:\.\d+)?)\s*mA\b/iu', fn ($v) => $put('chip.current_ma', $v, 88, 'current_ma'));
            $this->matchNumber($text, '/(\d+(?:\.\d+)?)\s*V\b/iu', fn ($v) => $put('chip.voltage_v', $v, 76, 'voltage_v'));
            $this->matchNumber($text, '/(?:CRI|Ra)\s*[≥>:：]?\s*(\d+(?:\.\d+)?)/iu', fn ($v) => $put('chip.cri', $v, 92, 'cri'));
            $this->matchNumber($text, '/R9\s*[≥>:：]?\s*(\d+(?:\.\d+)?)/iu', fn ($v) => $put('chip.r9', $v, 92, 'r9'));
            $this->matchNumber($text, '/SDCM\s*[≤<:]?\s*(\d+(?:\.\d+)?)/iu', fn ($v) => $put('chip.sdcm', $v, 92, 'sdcm'));
            $this->matchNumber($text, '/(\d+(?:\.\d+)?)\s*lm\/W/iu', fn ($v) => $put('chip.efficacy_lm_w', $v, 90, 'efficacy'));
            $this->matchNumber($text, '/(\d+(?:\.\d+)?)\s*lm\b/iu', fn ($v) => $put('chip.luminous_flux_lm', $v, 84, 'flux'));
            if (preg_match('/(\d{4})\s*[-~至]\s*(\d{4})\s*K/iu', $text, $match)) {
                $put('chip.cct_min_k', $match[1], 92, 'cct_range');
                $put('chip.cct_max_k', $match[2], 92, 'cct_range');
            } elseif (preg_match('/(?<!\d)(\d{4})\s*K/iu', $text, $match)) {
                $put('chip.cct_min_k', $match[1], 86, 'cct_single');
                $put('chip.cct_max_k', $match[1], 86, 'cct_single');
            }
            if (preg_match('/\b(2835|3030|3535|5050|COB|SMD)\b/iu', $text, $match)) {
                $put('chip.package_type', strtoupper($match[1]), 80, 'package_token');
            }
            if (preg_match('/(\d+(?:\.\d+)?)\s*[x×*]\s*(\d+(?:\.\d+)?)\s*(?:mm)?/iu', $text, $match)) {
                $put('chip.size_text', $match[1] . '×' . $match[2] . ' mm', 76, 'dimensions');
            }
        } elseif ($category === 'optical') {
            $types = ['透镜' => '透镜', '反光杯' => '反光杯', '柔光片' => '柔光片', '导光板' => '导光板', '调焦' => '调焦模组', '玻璃' => '玻璃'];
            foreach ($types as $needle => $value) {
                if (mb_stripos($text, $needle) !== false) {
                    $put('optical.optical_type', $value, 94, 'optical_type_keyword');
                    break;
                }
            }
            if (preg_match('/(\d+(?:\.\d+)?)\s*[-~至]\s*(\d+(?:\.\d+)?)\s*[°度]/u', $text, $match)) {
                $put('optical.beam_angle_text', $match[1] . '–' . $match[2] . '°', 92, 'beam_angle_range');
                $put('optical.beam_angle_min', $match[1], 92, 'beam_angle_range');
                $put('optical.beam_angle_max', $match[2], 92, 'beam_angle_range');
            } elseif (preg_match('/(\d+(?:\.\d+)?)\s*[°度]/u', $text, $match)) {
                $put('optical.beam_angle_text', $match[1] . '°', 86, 'beam_angle_single');
                $put('optical.beam_angle_min', $match[1], 86, 'beam_angle_single');
                $put('optical.beam_angle_max', $match[1], 86, 'beam_angle_single');
            }
            if (preg_match('/(?:Φ|φ|直径)\s*(\d+(?:\.\d+)?)/u', $text, $match)) {
                $put('optical.diameter_mm', $match[1], 88, 'diameter');
            }
            if (mb_stripos($text, '调焦') !== false) {
                $put('optical.is_focusable', 1, 94, 'focus_keyword');
            }
        } else {
            if (preg_match('/(\d+(?:\.\d+)?)\s*[x×*]\s*(\d+(?:\.\d+)?)\s*[x×*]\s*(\d+(?:\.\d+)?)\s*(?:mm)?/iu', $text, $match)) {
                if ($category === 'profile') {
                    $put('profile.length_mm', $match[1], 78, 'dimensions_lwh');
                    $put('profile.width_mm', $match[2], 78, 'dimensions_lwh');
                    $put('profile.height_mm', $match[3], 78, 'dimensions_lwh');
                } elseif ($category === 'connector') {
                    $put('connector.size_text', $match[1] . '×' . $match[2] . '×' . $match[3] . ' mm', 78, 'dimensions_lwh');
                } elseif ($category === 'accessory') {
                    $put('accessory.size_text', $match[1] . '×' . $match[2] . '×' . $match[3] . ' mm', 78, 'dimensions_lwh');
                } elseif ($category === 'packaging') {
                    $put('packaging.outer_size', $match[1] . '×' . $match[2] . '×' . $match[3] . ' mm', 72, 'dimensions_lwh');
                }
            }
            if ($category === 'profile' && !empty($snapshot['material_grade'])) {
                $put('profile.material_grade', (string) $snapshot['material_grade'], 90, 'legacy_material_grade');
            }
        }

        $confidence = $log
            ? round(array_sum(array_column($log, 'confidence')) / count($log), 2)
            : 35.0;
        return ['fields' => $values, 'log' => $log, 'confidence_score' => $confidence];
    }

    private function matchNumber(string $text, string $pattern, callable $callback): void
    {
        if (preg_match($pattern, $text, $match)) {
            $callback($match[1]);
        }
    }

    private function transition(int $id, string $from, string $to, string $action, int $userId): void
    {
        $official = $to === 'official' ? 1 : 0;
        $stmt = $this->db->prepare(
            'UPDATE mc_materials SET status=?,is_official=?,allow_bom=?,allow_quote=?,updated_by=?,updated_at=NOW()
             WHERE id=? AND status=? AND deleted_at IS NULL'
        );
        $stmt->execute([$to, $official, $official, $official, $userId, $id, $from]);
        if (!$stmt->rowCount()) {
            throw new RuntimeException('物料状态已变化，请刷新后重试。', 409);
        }
        $this->db->prepare(
            'INSERT INTO mc_material_lifecycle_events(material_id,from_status,to_status,action,actor_id,created_at)
             VALUES(?,?,?,?,?,NOW())'
        )->execute([$id, $from, $to, $action, $userId]);
    }

    private function operationLog(int $sourceId, int $materialId, string $action, array $parsed, int $userId): void
    {
        $json = json_encode([
            'source_record_id' => $sourceId,
            'material_id' => $materialId,
            'parsed_fields' => array_keys($parsed['fields']),
            'confidence_score' => $parsed['confidence_score'],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $this->db->prepare(
            "INSERT INTO mc_operation_logs
             (module,object_type,object_id,action,new_value_json,actor_id,actor_ip,result,created_at)
             VALUES('material_center','source_record',?,?,?,?,?,'success',NOW())"
        )->execute([$sourceId, $action, $json, $userId, (string) ($_SERVER['REMOTE_ADDR'] ?? 'cli')]);
    }

    private function nullable(mixed $value, int $length): ?string
    {
        $value = trim((string) $value);
        return $value === '' ? null : mb_substr($value, 0, $length);
    }
}
