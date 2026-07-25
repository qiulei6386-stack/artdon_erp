<?php
declare(strict_types=1);
namespace Artdon\MaterialCenter\Services;

use Artdon\MaterialCenter\Repositories\MaterialReadRepository;
use PDO;

final class PowerWorkbenchService
{
    public function __construct(private ?PDO $db=null){$this->db??=\db();}

    public function source(string $search=''):array
    {
        $legacy=(new MaterialReadRepository())->powerSupplyRows($search,200);
        if(!$legacy)return[];
        $ids=array_column($legacy,'id');$marks=implode(',',array_fill(0,count($ids),'?'));
        $stmt=$this->db->prepare("SELECT s.*,COALESCE(d.risk,0) duplicate_risk FROM mc_material_import_staging s LEFT JOIN (SELECT staging_id,COUNT(*) risk FROM mc_duplicate_candidates WHERE decision='pending' GROUP BY staging_id)d ON d.staging_id=s.id WHERE s.source_table='bom_materials' AND s.source_id IN($marks)");
        $stmt->execute($ids);$staged=[];foreach($stmt->fetchAll(PDO::FETCH_ASSOC)as$row)$staged[(int)$row['source_id']]=$row;
        return array_map(function(array$row)use($staged){$s=$staged[(int)$row['id']]??[];$hash=$s['raw_data_hash']??hash('sha256',json_encode([$row['category'],$row['name'],$row['brand'],$row['model'],$row['spec'],$row['unit'],$row['updated_at']],JSON_UNESCAPED_UNICODE));return[
            'record_key'=>'legacy:'.$row['id'],'source_system'=>'广州旧BOM','source_table'=>'bom_materials','source_id'=>(int)$row['id'],'category'=>$row['category'],'name'=>$row['name'],'brand'=>$row['brand'],'model'=>$row['model'],'spec'=>$row['spec'],'price'=>$s['raw_price']??null,'unit'=>$row['unit'],'updated_at'=>$row['updated_at'],'hash'=>$hash,'parse_status'=>$s?($s['mapping_status']==='pending'?'未解析':'已解析'):'未解析','mapping_status'=>$s['mapping_status']??'pending','duplicate_risk'=>(int)($s['duplicate_risk']??0),'staging_id'=>$s['id']??null,
        ];},$legacy);
    }

    public function staging(string $tab,string $search=''):array
    {
        $where=["s.is_pilot=1"];$params=[];
        if($tab==='organize')$where[]="s.mapping_status IN('pending','parsed','needs_review','rejected')";
        if($tab==='confirm')$where[]="s.mapping_status IN('parsed','needs_review','duplicate_candidate','confirmed')";
        if($tab==='duplicates')$where[]="EXISTS(SELECT 1 FROM mc_duplicate_candidates d WHERE d.staging_id=s.id AND d.decision='pending')";
        if($search!==''){$where[]='(s.raw_name LIKE ? OR s.raw_brand LIKE ? OR s.raw_model LIKE ? OR s.raw_spec LIKE ?)';$v='%'.$search.'%';$params=[$v,$v,$v,$v];}
        $sql="SELECT s.*,l.material_id,(SELECT COUNT(*) FROM mc_material_parse_results p WHERE p.staging_id=s.id) parsed_count,(SELECT COUNT(*) FROM mc_material_parse_results p WHERE p.staging_id=s.id AND p.confidence='low') low_count,(SELECT COUNT(*) FROM mc_duplicate_candidates d WHERE d.staging_id=s.id AND d.decision='pending') duplicate_risk FROM mc_material_import_staging s LEFT JOIN mc_legacy_links l ON l.source_table=s.source_table AND l.source_id=s.source_id WHERE ".implode(' AND ',$where).' ORDER BY s.updated_at DESC';
        $stmt=$this->db->prepare($sql);$stmt->execute($params);return$stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function formal(string $tab,string $search=''):array
    {
        $sql="SELECT m.id,m.material_code,m.name,m.brand,m.model,m.source,m.status,m.updated_at,p.installation_type,p.output_type,p.nominal_power_w,p.max_output_power_w,p.output_current_ma,p.output_voltage_min_v,p.output_voltage_max_v,p.supplier_warranty_years,b.name power_band,(SELECT GROUP_CONCAT(mode ORDER BY is_primary DESC) FROM mc_power_supply_dimming_modes d WHERE d.material_id=m.id) dimming_modes FROM mc_materials m JOIN mc_power_supply_specs p ON p.material_id=m.id LEFT JOIN mc_power_bands b ON b.id=p.power_band_id WHERE m.deleted_at IS NULL";
        $params=[];if($tab==='formal')$sql.=" AND m.status IN('official','draft','pending_review')";if($tab==='archived')$sql.=" AND m.status IN('disabled','archived')";if($search!==''){$sql.=" AND (m.material_code LIKE ? OR m.name LIKE ? OR m.brand LIKE ? OR m.model LIKE ?)";$v='%'.$search.'%';$params=[$v,$v,$v,$v];}$sql.=" ORDER BY m.updated_at DESC";
        $stmt=$this->db->prepare($sql);$stmt->execute($params);return$stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function activity(int $limit=100):array{return$this->db->query('SELECT * FROM mc_activity_logs ORDER BY created_at DESC LIMIT '.max(1,min(500,$limit)))->fetchAll(PDO::FETCH_ASSOC);}
    public function mappings(int $limit=200):array{return$this->db->query("SELECT l.*,s.raw_data_hash,s.mapping_status FROM mc_legacy_links l LEFT JOIN mc_material_import_staging s ON s.source_table=l.source_table AND s.source_id=l.source_id ORDER BY l.updated_at DESC LIMIT ".max(1,min(500,$limit)))->fetchAll(PDO::FETCH_ASSOC);}
    public function fields():array{return$this->db->query("SELECT * FROM mc_field_definitions WHERE category_code IN('all','power_supply') ORDER BY sort_order,id")->fetchAll(PDO::FETCH_ASSOC);}
}
