<?php
declare(strict_types=1);
namespace Artdon\MaterialCenter\Adapters;

use PDO;
use LogicException;

final class LegacyBomMaterialAdapter
{
    public function __construct(private ?PDO $db = null) { $this->db ??= \db(); }

    public function powerSupplies(int $limit = 200): array
    {
        return $this->select(
            "SELECT id,category,name,brand,model,spec,price,unit,updated_at
             FROM bom_materials
             WHERE (is_active=1 OR is_active IS NULL)
               AND (category LIKE '%电源%' OR category LIKE '%驱动%' OR name LIKE '%电源%' OR name LIKE '%驱动%')
             ORDER BY updated_at DESC,id DESC LIMIT " . max(1,min(200,$limit))
        );
    }

    public function find(int $id): ?array
    {
        $rows=$this->select('SELECT id,category,name,brand,model,spec,price,unit,updated_at FROM bom_materials WHERE id=? LIMIT 1',[$id]);
        return $rows[0]??null;
    }

    private function select(string $sql,array $params=[]): array
    {
        if(!preg_match('/^\s*SELECT\b/i',$sql))throw new LogicException('Legacy adapter is read-only.');
        $stmt=$this->db->prepare($sql);$stmt->execute($params);return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
