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

    public function products(string $search = '', string $category = '', int $limit = 500): array
    {
        $where = ['website_deleted=0'];
        $params = [];
        if ($search !== '') {
            $where[] = '(model_no LIKE ? OR product_name LIKE ? OR series_name LIKE ?)';
            $term = '%' . $search . '%';
            array_push($params, $term, $term, $term);
        }
        if ($category !== '') {
            $where[] = 'category=?';
            $params[] = $category;
        }
        return $this->selectAll(
            'SELECT id,model_no,category,product_name,series_name,lamp_type,status,
                    COALESCE(NULLIF(web_image_url,\'\'),NULLIF(source_image_url,\'\'),NULLIF(image_path,\'\')) AS image_path,
                    COALESCE(NULLIF(web_dimension_url,\'\'),NULLIF(source_drawing_url,\'\'),NULLIF(drawing_path,\'\')) AS drawing_path,
                    dim_opening,dim_outer_d,dim_length,dim_width,dim_height,bom_allowed,updated_at
             FROM naming_models
             WHERE ' . implode(' AND ', $where) . '
             ORDER BY updated_at DESC,id DESC LIMIT ' . $this->limit($limit),
            $params
        );
    }

    public function productCategories(): array
    {
        return $this->selectAll(
            "SELECT category,COUNT(*) AS total FROM naming_models WHERE website_deleted=0 AND category<>'' GROUP BY category ORDER BY category"
        );
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

    private function limit(int $limit): int
    {
        return max(1, min(500, $limit));
    }
}
