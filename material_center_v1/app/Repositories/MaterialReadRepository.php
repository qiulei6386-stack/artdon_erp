<?php
declare(strict_types=1);

namespace Artdon\MaterialCenter\Repositories;

use PDO;

final class MaterialReadRepository
{
    private PDO $connection;

    public function __construct()
    {
        $this->connection = db();
    }

    public function rows(string $search = '', string $category = '', int $limit = 120): array
    {
        $where = ['(is_active=1 OR is_active IS NULL)'];
        $parameters = [];
        if ($search !== '') {
            $where[] = '(name LIKE ? OR model LIKE ? OR brand LIKE ? OR spec LIKE ? OR material_grade LIKE ?)';
            $term = '%' . $search . '%';
            array_push($parameters, $term, $term, $term, $term, $term);
        }
        if ($category !== '') {
            $where[] = 'category=?';
            $parameters[] = $category;
        }
        return $this->selectAll(
            'SELECT id,category,brand,name,model,spec,unit,material_grade,image,updated_at
             FROM bom_materials
             WHERE ' . implode(' AND ', $where) . '
             ORDER BY updated_at DESC,id DESC
             LIMIT ' . max(1, min(200, $limit)),
            $parameters
        );
    }

    public function categories(): array
    {
        return $this->selectAll(
            "SELECT category,COUNT(*) AS total
             FROM bom_materials
             WHERE (is_active=1 OR is_active IS NULL) AND COALESCE(category,'')<>''
             GROUP BY category ORDER BY category"
        );
    }

    public function powerSupplyRows(string $search = '', int $limit = 200): array
    {
        $where = [
            '(is_active=1 OR is_active IS NULL)',
            "(category LIKE '%电源%' OR category LIKE '%驱动%' OR name LIKE '%电源%' OR name LIKE '%驱动%')",
        ];
        $parameters = [];
        if ($search !== '') {
            $where[] = '(name LIKE ? OR model LIKE ? OR brand LIKE ? OR spec LIKE ? OR material_grade LIKE ?)';
            $term = '%' . $search . '%';
            array_push($parameters, $term, $term, $term, $term, $term);
        }
        return $this->selectAll(
            'SELECT id,category,brand,name,model,spec,unit,material_grade,image,updated_at
             FROM bom_materials
             WHERE ' . implode(' AND ', $where) . '
             ORDER BY updated_at DESC,id DESC
             LIMIT ' . max(1, min(200, $limit)),
            $parameters
        );
    }

    public function summary(): array
    {
        $row = $this->selectOne(
            "SELECT
                COUNT(*) AS total,
                COUNT(DISTINCT NULLIF(category,'')) AS categories,
                SUM(CASE WHEN DATE(updated_at)=CURDATE() THEN 1 ELSE 0 END) AS updated_today,
                MAX(updated_at) AS last_updated_at
             FROM bom_materials
             WHERE is_active=1 OR is_active IS NULL"
        );
        return [
            'total' => (int)($row['total'] ?? 0),
            'categories' => (int)($row['categories'] ?? 0),
            'updated_today' => (int)($row['updated_today'] ?? 0),
            'last_updated_at' => (string)($row['last_updated_at'] ?? ''),
        ];
    }

    private function selectAll(string $sql, array $parameters = []): array
    {
        if (!preg_match('/^\s*SELECT\b/i', $sql)) {
            throw new \LogicException('Material center repository only permits SELECT.');
        }
        $statement = $this->connection->prepare($sql);
        $statement->execute($parameters);
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    private function selectOne(string $sql, array $parameters = []): array
    {
        return $this->selectAll($sql, $parameters)[0] ?? [];
    }
}
