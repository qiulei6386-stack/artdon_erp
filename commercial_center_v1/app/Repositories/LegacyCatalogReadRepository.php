<?php
declare(strict_types=1);

namespace Artdon\CommercialCenter\Repositories;

use PDO;

final class LegacyCatalogReadRepository
{
    private PDO $connection;

    public function __construct()
    {
        $this->connection = db();
    }

    public function products(string $search = '', string $category = '', int $limit = 0, int $offset = 0): array
    {
        // Naming-center deployments have different generations of image columns.
        // Resolve the actual read-only schema first so the catalog remains compatible.
        $columns = $this->tableColumns('naming_models');
        $imageColumns = array_values(array_intersect(['web_image_url', 'source_image_url', 'image_path', 'image_url'], $columns));
        $drawingColumns = array_values(array_intersect(['web_dimension_url', 'source_drawing_url', 'drawing_path', 'dimension_image'], $columns));
        $imageExpr = $imageColumns ? 'COALESCE(' . implode(',', array_map(static fn(string $c): string => "NULLIF(`{$c}`, '')", $imageColumns)) . ') AS image_path' : 'NULL AS image_path';
        $drawingExpr = $drawingColumns ? 'COALESCE(' . implode(',', array_map(static fn(string $c): string => "NULLIF(`{$c}`, '')", $drawingColumns)) . ') AS drawing_path' : 'NULL AS drawing_path';
        [$where, $params] = $this->productWhere($search, $category);
        $sql = "SELECT n.id,n.model_no,n.category,n.product_name,n.series_name,n.lamp_type,
                    CASE
                        WHEN pc.status='published' AND pv.status='published' THEN '可报价'
                        ELSE n.status
                    END AS status,
                    {$imageExpr},
                    {$drawingExpr},
                    n.dim_opening,n.dim_outer_d,n.dim_length,n.dim_width,n.dim_height,n.bom_allowed,n.updated_at,
                    pv.published_at AS commercial_published_at
             FROM naming_models n
             LEFT JOIN mc_products mp
               ON mp.legacy_table='naming_models' AND mp.legacy_id=n.id
             LEFT JOIN mc_pa2_product_configs pc
               ON pc.product_id=mp.id
             LEFT JOIN mc_pa2_product_config_versions pv
               ON pv.id=pc.active_published_version_id
             WHERE " . implode(' AND ', $where) . '
             ORDER BY (pv.published_at IS NULL),pv.published_at DESC,n.updated_at DESC,n.id DESC';
        $sql .= $limit > 0 ? ' LIMIT ' . $this->limit($limit) . ' OFFSET ' . max(0, $offset) : '';
        return $this->selectAll(
            $sql,
            $params
        );
    }

    public function productCount(string $search = '', string $category = ''): int
    {
        [$where, $params] = $this->productWhere($search, $category);
        return (int)$this->selectValue(
            'SELECT COUNT(*) FROM naming_models n WHERE ' . implode(' AND ', $where),
            $params
        );
    }

    public function productStatusCounts(string $search = '', string $category = ''): array
    {
        [$where, $params] = $this->productWhere($search, $category);
        $rows = $this->selectAll(
            "SELECT CASE
                        WHEN pc.status='published' AND pv.status='published' THEN '可报价'
                        ELSE n.status
                    END AS status,
                    COUNT(*) AS total
             FROM naming_models n
             LEFT JOIN mc_products mp
               ON mp.legacy_table='naming_models' AND mp.legacy_id=n.id
             LEFT JOIN mc_pa2_product_configs pc
               ON pc.product_id=mp.id
             LEFT JOIN mc_pa2_product_config_versions pv
               ON pv.id=pc.active_published_version_id
             WHERE " . implode(' AND ', $where) . '
             GROUP BY status',
            $params
        );
        $counts = ['可报价'=>0, '开发中'=>0, '停售'=>0];
        foreach ($rows as $row) {
            $status = (string)($row['status'] ?? '');
            if (array_key_exists($status, $counts)) $counts[$status] = (int)$row['total'];
        }
        return $counts;
    }

    public function productCategories(): array
    {
        return $this->selectAll(
            "SELECT category,COUNT(*) AS total FROM naming_models WHERE website_deleted=0 AND category<>'' GROUP BY category ORDER BY category"
        );
    }

    /** Returns read-only BOM cost totals keyed by product model. */
    public function bomCostsForModels(array $models): array
    {
        $models = array_values(array_unique(array_filter(array_map('strval', $models))));
        if ($models === []) return [];
        $rows = $this->selectAll(
            'SELECT model,naming_model_no,labor,other,rows_json FROM bom_projects WHERE is_active=1'
        );
        $wanted = array_fill_keys(array_map(static fn(string $v): string => trim($v), $models), true);
        $costs = [];
        foreach ($rows as $row) {
            $modelKey = trim((string)($row['model'] ?: $row['naming_model_no']));
            if ($modelKey === '' || !isset($wanted[$modelKey])) continue;
            $cost = (float)$row['labor'] + (float)$row['other'];
            $lines = json_decode((string)$row['rows_json'], true);
            if (is_array($lines)) foreach ($lines as $line) {
                $price = (float)($line['price'] ?? 0);
                $qty = (float)($line['qty'] ?? 1);
                $subtotal = array_key_exists('subtotal', $line) ? (float)$line['subtotal'] : $price * $qty;
                $cost += $subtotal > 0 ? $subtotal : ($price * $qty);
            }
            $costs[$modelKey] = round($cost, 4);
        }
        return $costs;
    }

    public function materials(string $search = '', string $category = '', int $limit = 100): array
    {
        $where = ['is_active=1'];
        $params = [];
        if ($search !== '') {
            $where[] = '(name LIKE ? OR model LIKE ? OR brand LIKE ? OR spec LIKE ?)';
            $term = '%' . $search . '%';
            array_push($params, $term, $term, $term, $term);
        }
        if ($category !== '') {
            $where[] = 'category=?';
            $params[] = $category;
        }
        return $this->selectAll(
            'SELECT id,category,brand,name,model,spec,unit,material_grade,updated_at
             FROM bom_materials
             WHERE ' . implode(' AND ', $where) . '
             ORDER BY updated_at DESC,id DESC LIMIT ' . $this->limit($limit),
            $params
        );
    }

    public function materialCategories(): array
    {
        return $this->selectAll(
            "SELECT category,COUNT(*) AS total FROM bom_materials WHERE is_active=1 AND category<>'' GROUP BY category ORDER BY category"
        );
    }

    private function selectAll(string $sql, array $parameters = []): array
    {
        if (!preg_match('/^\s*SELECT\b/i', $sql)) {
            throw new \LogicException('Catalog repository only permits SELECT.');
        }
        $statement = $this->connection->prepare($sql);
        $statement->execute($parameters);
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    private function selectValue(string $sql, array $parameters = [])
    {
        if (!preg_match('/^\s*SELECT\b/i', $sql)) {
            throw new \LogicException('Catalog repository only permits SELECT.');
        }
        $statement = $this->connection->prepare($sql);
        $statement->execute($parameters);
        return $statement->fetchColumn();
    }

    private function productWhere(string $search, string $category): array
    {
        $where = ['n.website_deleted=0'];
        $params = [];
        $search = trim($search);
        if ($search !== '') {
            $keywords = preg_split('/[\s,，;；]+/u', $search, -1, PREG_SPLIT_NO_EMPTY) ?: [];
            $haystack = "CONCAT_WS(' ',n.model_no,n.product_name,n.item_name,n.series_name,n.web_series,n.category,n.lamp_type,n.status,n.customer,n.remark,n.size_code,n.web_size_name,n.web_dimensions,n.dim_opening,n.dim_outer_d,n.dim_length,n.dim_width,n.dim_height)";
            foreach (array_slice($keywords, 0, 8) as $keyword) {
                $where[] = $haystack . ' LIKE ?';
                $params[] = '%' . $keyword . '%';
            }
        }
        if ($category !== '') {
            $where[] = 'n.category=?';
            $params[] = $category;
        }
        return [$where, $params];
    }

    private function tableColumns(string $table): array
    {
        $statement = $this->connection->prepare(
            'SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
        );
        $statement->execute([$table]);
        return array_map('strval', $statement->fetchAll(PDO::FETCH_COLUMN));
    }

    private function limit(int $limit): int
    {
        return max(1, $limit);
    }
}
