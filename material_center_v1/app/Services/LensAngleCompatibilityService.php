<?php
declare(strict_types=1);

namespace Artdon\MaterialCenter\Services;

use PDO;
use RuntimeException;

final class LensAngleCompatibilityService
{
    public function __construct(private ?PDO $db = null)
    {
        $this->db ??= \db();
    }

    public function detail(int $lensMaterialId): array
    {
        $lens = $this->lens($lensMaterialId);
        $rows = $this->tableExists()
            ? $this->rows($lensMaterialId)
            : [];

        return [
            'material' => $lens,
            'rows' => $rows,
            'chips' => $this->chipOptions(),
            'editable' => ($lens['status'] ?? '') === 'draft',
        ];
    }

    public function save(int $lensMaterialId, array $rows, int $userId): array
    {
        $lens = $this->lens($lensMaterialId);
        if (($lens['status'] ?? '') !== 'draft') {
            throw new RuntimeException('只有草稿光学物料可以编辑芯片角度适配；正式物料请先生成修订草稿。');
        }
        if (!$this->tableExists()) {
            throw new RuntimeException('芯片角度适配表尚未完成初始化，请先执行物料中心迁移。');
        }

        $clean = $this->normalizeRows($rows);
        if (count($clean) > 200) throw new RuntimeException('一次最多保存 200 行芯片角度适配。');

        $own = !$this->db->inTransaction();
        if ($own) $this->db->beginTransaction();
        try {
            $this->db->prepare("UPDATE mc_lens_chip_angle_compatibilities SET status='disabled',updated_by=?,updated_at=NOW() WHERE lens_material_id=? AND status='active'")
                ->execute([$userId, $lensMaterialId]);
            $stmt = $this->db->prepare("INSERT INTO mc_lens_chip_angle_compatibilities
                (lens_material_id,chip_material_id,chip_keyword,lens_beam_angle_deg,actual_beam_angle_deg,beam_angle_label,les_text,note,status,sort_order,created_by,updated_by,created_at,updated_at)
                VALUES(?,?,?,?,?,?,?,?, 'active',?,?,?,NOW(),NOW())");
            foreach ($clean as $index => $row) {
                $stmt->execute([
                    $lensMaterialId,
                    $row['chip_material_id'],
                    $row['chip_keyword'],
                    $row['lens_beam_angle_deg'],
                    $row['actual_beam_angle_deg'],
                    $row['beam_angle_label'],
                    $row['les_text'],
                    $row['note'],
                    ($index + 1) * 10,
                    $userId,
                    $userId,
                ]);
            }
            if (\mc_table_exists('mc_activity_logs')) {
                $this->db->prepare("INSERT INTO mc_activity_logs(entity_type,entity_id,action,after_json,actor_id,created_at) VALUES('material',?,?,?,?,NOW())")
                    ->execute([$lensMaterialId, 'lens_chip_angle_compatibility_saved', json_encode(['rows' => count($clean)], JSON_UNESCAPED_UNICODE), $userId]);
            }
            if ($own) $this->db->commit();
        } catch (\Throwable $e) {
            if ($own && $this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }

        return $this->detail($lensMaterialId);
    }

    private function lens(int $id): array
    {
        if ($id <= 0) throw new RuntimeException('请选择光学物料。');
        $stmt = $this->db->prepare("SELECT m.id,m.material_code,m.name,m.brand,m.model,m.status
            FROM mc_materials m
            JOIN mc_material_categories c ON c.id=m.category_id AND c.code='optical'
            WHERE m.id=? AND m.deleted_at IS NULL");
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) throw new RuntimeException('光学物料不存在。', 404);
        $row['id'] = (int) $row['id'];
        return $row;
    }

    private function rows(int $lensMaterialId): array
    {
        $stmt = $this->db->prepare("SELECT r.*,m.material_code chip_material_code,m.name chip_name,m.brand chip_brand,m.model chip_model
            FROM mc_lens_chip_angle_compatibilities r
            LEFT JOIN mc_materials m ON m.id=r.chip_material_id AND m.deleted_at IS NULL
            WHERE r.lens_material_id=? AND r.status='active'
            ORDER BY r.sort_order,r.id");
        $stmt->execute([$lensMaterialId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$row) {
            foreach (['id', 'lens_material_id', 'chip_material_id', 'sort_order'] as $key) {
                if (isset($row[$key])) $row[$key] = $row[$key] === null ? null : (int) $row[$key];
            }
        }
        unset($row);
        return $rows;
    }

    private function chipOptions(): array
    {
        $stmt = $this->db->query("SELECT m.id,m.material_code,m.name,m.brand,m.model,chip.pad_text
            FROM mc_materials m
            JOIN mc_material_categories c ON c.id=m.category_id AND c.code='chip'
            LEFT JOIN mc_material_chip chip ON chip.material_id=m.id
            WHERE m.deleted_at IS NULL AND m.status IN('draft','pending_review','official')
            ORDER BY FIELD(m.status,'official','pending_review','draft'),m.material_code,m.id
            LIMIT 800");
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$row) $row['id'] = (int) $row['id'];
        unset($row);
        return $rows;
    }

    private function normalizeRows(array $rows): array
    {
        $clean = [];
        foreach ($rows as $row) {
            if (!is_array($row)) continue;
            $chipId = $this->intOrNull($row['chip_material_id'] ?? null);
            $keyword = $this->text($row['chip_keyword'] ?? '', 200);
            $lensAngle = $this->angleOrNull($row['lens_beam_angle_deg'] ?? null, false);
            $actualAngle = $this->angleOrNull($row['actual_beam_angle_deg'] ?? null, true);
            $label = $this->text($row['beam_angle_label'] ?? '', 80);
            $les = $this->text($row['les_text'] ?? '', 120);
            $note = $this->text($row['note'] ?? '', 500);
            $hasMeaning = $chipId || $keyword !== null || $lensAngle !== null || $actualAngle !== null || $label !== null || $les !== null || $note !== null;
            if (!$hasMeaning) continue;
            if ($actualAngle === null) throw new RuntimeException('适配表每一行都必须填写实际光束角。');
            if ($chipId && !$this->isChip($chipId)) throw new RuntimeException('适配表中选择的芯片不存在或分类不正确。');
            $clean[] = [
                'chip_material_id' => $chipId,
                'chip_keyword' => $keyword,
                'lens_beam_angle_deg' => $lensAngle,
                'actual_beam_angle_deg' => $actualAngle,
                'beam_angle_label' => $label,
                'les_text' => $les,
                'note' => $note,
            ];
        }
        return $clean;
    }

    private function isChip(int $materialId): bool
    {
        $stmt = $this->db->prepare("SELECT 1 FROM mc_materials m JOIN mc_material_categories c ON c.id=m.category_id AND c.code='chip' WHERE m.id=? AND m.deleted_at IS NULL LIMIT 1");
        $stmt->execute([$materialId]);
        return (bool) $stmt->fetchColumn();
    }

    private function intOrNull(mixed $value): ?int
    {
        $value = trim((string) $value);
        if ($value === '') return null;
        $number = (int) $value;
        return $number > 0 ? $number : null;
    }

    private function angleOrNull(mixed $value, bool $required): ?float
    {
        $value = trim((string) $value);
        if ($value === '') return null;
        if (!is_numeric($value)) throw new RuntimeException('光束角必须是数字。');
        $number = (float) $value;
        if ($number <= 0 || $number > 180) throw new RuntimeException('光束角必须在 0 到 180° 之间。');
        return $number;
    }

    private function text(mixed $value, int $max): ?string
    {
        $value = trim((string) $value);
        if ($value === '') return null;
        return mb_substr($value, 0, $max);
    }

    private function tableExists(): bool
    {
        return \mc_table_exists('mc_lens_chip_angle_compatibilities');
    }
}
