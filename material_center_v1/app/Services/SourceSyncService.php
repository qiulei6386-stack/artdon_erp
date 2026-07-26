<?php
declare(strict_types=1);
namespace Artdon\MaterialCenter\Services;

use Artdon\MaterialCenter\Adapters\LegacyBomMaterialAdapter;
use PDO;

final class SourceSyncService
{
    public function __construct(private ?PDO $db=null){$this->db??=\db();}

    public function syncBom(int $actorId): array
    {
        $adapter=new LegacyBomMaterialAdapter($this->db);$after=0;$seen=0;$created=0;$changed=0;
        do{
            $rows=$adapter->allAfter($after,500);
            if(!$rows)break;
            $this->db->beginTransaction();
            try{
                foreach($rows as$row){
                    $after=max($after,(int)$row['id']);$seen++;
                    $snapshot=json_encode($row,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE);
                    $hash=hash('sha256',$snapshot);
                    $check=$this->db->prepare("SELECT id,snapshot_hash FROM mc_source_records WHERE source_system='guangzhou_bom' AND source_table='bom_materials' AND source_pk=?");
                    $check->execute([(string)$row['id']]);$existing=$check->fetch(PDO::FETCH_ASSOC);
                    if(!$existing){
                        $stmt=$this->db->prepare("INSERT INTO mc_source_records(source_system,source_table,source_pk,raw_text,snapshot_json,snapshot_hash,read_at,status)VALUES('guangzhou_bom','bom_materials',?,?,?,?,NOW(),'pending')");
                        $stmt->execute([(string)$row['id'],trim(implode(' ',array_filter([$row['name'],$row['brand'],$row['model'],$row['spec']]))),$snapshot,$hash]);$created++;
                    }elseif(!hash_equals((string)$existing['snapshot_hash'],$hash)){
                        $stmt=$this->db->prepare("UPDATE mc_source_records SET raw_text=?,snapshot_json=?,snapshot_hash=?,read_at=NOW(),status=IF(matched_material_id IS NULL,'pending','changed') WHERE id=?");
                        $stmt->execute([trim(implode(' ',array_filter([$row['name'],$row['brand'],$row['model'],$row['spec']]))),$snapshot,$hash,$existing['id']]);$changed++;
                    }else{
                        $this->db->prepare('UPDATE mc_source_records SET read_at=NOW() WHERE id=?')->execute([$existing['id']]);
                    }
                }
                $this->db->commit();
            }catch(\Throwable$e){if($this->db->inTransaction())$this->db->rollBack();throw$e;}
        }while(count($rows)===500);
        $result=['seen'=>$seen,'created'=>$created,'changed'=>$changed,'unchanged'=>$seen-$created-$changed];
        $this->db->prepare("INSERT INTO mc_operation_logs(module,object_type,action,new_value_json,actor_id,actor_ip,result,created_at)VALUES('material_center','bom_sync','sync',?,?,?,'success',NOW())")
            ->execute([json_encode($result,JSON_UNESCAPED_UNICODE),$actorId,(string)($_SERVER['REMOTE_ADDR']??'cli')]);
        return$result;
    }

    public function overview(): array
    {
        $summary=['legacy_total'=>(int)$this->db->query('SELECT COUNT(*) FROM bom_materials')->fetchColumn()];
        foreach(['pending','changed','confirmed','ignored']as$status){
            $stmt=$this->db->prepare('SELECT COUNT(*) FROM mc_source_records WHERE status=?');$stmt->execute([$status]);$summary[$status]=(int)$stmt->fetchColumn();
        }
        $summary['snapshots']=(int)$this->db->query('SELECT COUNT(*) FROM mc_source_records')->fetchColumn();
        $summary['imports']=(int)$this->db->query('SELECT COUNT(*) FROM mc_import_tasks')->fetchColumn();
        $summary['errors']=(int)$this->db->query('SELECT COUNT(*) FROM mc_import_errors')->fetchColumn();
        return$summary;
    }

    public function materialRows(string $categoryCode='',int $limit=2000): array
    {
        $limit=max(1,min(5000,$limit));
        $categoryMap=[
            'power_supply'=>['驱动','电源'],
            'chip'=>['芯片'],
            'optical'=>['光学'],
            'profile'=>['型材','外壳'],
            'connector'=>['接头'],
            'accessory'=>['附件'],
            'packaging'=>['包装'],
        ];
        $where="WHERE source_system='guangzhou_bom' AND source_table='bom_materials' AND matched_material_id IS NULL";
        $params=[];
        if($categoryCode!==''){
            $categories=$categoryMap[$categoryCode]??[];
            if(!$categories)return[];
            $where.=' AND JSON_UNQUOTE(JSON_EXTRACT(snapshot_json,\'$.category\')) IN ('.implode(',',array_fill(0,count($categories),'?')).')';
            $params=$categories;
        }
        $sql="SELECT id source_record_id,source_pk,status,read_at,
            JSON_UNQUOTE(JSON_EXTRACT(snapshot_json,'$.name')) material_name,
            JSON_UNQUOTE(JSON_EXTRACT(snapshot_json,'$.brand')) brand,
            JSON_UNQUOTE(JSON_EXTRACT(snapshot_json,'$.model')) model,
            JSON_UNQUOTE(JSON_EXTRACT(snapshot_json,'$.spec')) spec,
            JSON_UNQUOTE(JSON_EXTRACT(snapshot_json,'$.unit')) unit_name,
            JSON_UNQUOTE(JSON_EXTRACT(snapshot_json,'$.category')) legacy_category
            FROM mc_source_records $where ORDER BY CAST(source_pk AS UNSIGNED) DESC,id DESC LIMIT $limit";
        $stmt=$this->db->prepare($sql);$stmt->execute($params);
        return$stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
