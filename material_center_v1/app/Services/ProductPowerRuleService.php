<?php
declare(strict_types=1);
namespace Artdon\MaterialCenter\Services;
use Artdon\MaterialCenter\Adapters\LegacyProductAdapter;
use PDO;
use RuntimeException;

final class ProductPowerRuleService
{
    public function __construct(private ?PDO$db=null){$this->db??=\db();}
    public function rules():array{return$this->db->query("SELECT r.*,b.name power_band,(SELECT GROUP_CONCAT(mode ORDER BY mode) FROM mc_product_power_rule_dimming_modes d WHERE d.rule_id=r.id) dimming_modes FROM mc_product_power_rules r LEFT JOIN mc_power_bands b ON b.id=r.power_band_id ORDER BY r.updated_at DESC")->fetchAll(PDO::FETCH_ASSOC);}
    public function save(array$data,int$userId):int
    {
        $productId=(int)($data['legacy_product_id']??0);if(!$productId||(new LegacyProductAdapter($this->db))->find($productId)===null)throw new RuntimeException('旧产品记录不存在。');
        $install=(string)($data['installation_type']??'unknown');$output=(string)($data['output_type']??'unknown');
        if(!in_array($install,['internal','external','unknown'],true)||!in_array($output,['constant_current','constant_voltage','unknown'],true))throw new RuntimeException('规则字段无效。');
        $this->db->beginTransaction();try{
            $stmt=$this->db->prepare("INSERT INTO mc_product_power_rules(legacy_product_id,rule_name,installation_type,output_type,lamp_power_w,lamp_power_min_w,lamp_power_max_w,power_band_id,output_current_min_ma,output_current_max_ma,output_voltage_min_v,output_voltage_max_v,max_length_mm,max_width_mm,max_height_mm,minimum_warranty_years,certification_required,status,created_by,updated_by,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,'draft',?,?,NOW(),NOW()) ON DUPLICATE KEY UPDATE rule_name=VALUES(rule_name),installation_type=VALUES(installation_type),output_type=VALUES(output_type),lamp_power_w=VALUES(lamp_power_w),lamp_power_min_w=VALUES(lamp_power_min_w),lamp_power_max_w=VALUES(lamp_power_max_w),power_band_id=VALUES(power_band_id),output_current_min_ma=VALUES(output_current_min_ma),output_current_max_ma=VALUES(output_current_max_ma),output_voltage_min_v=VALUES(output_voltage_min_v),output_voltage_max_v=VALUES(output_voltage_max_v),max_length_mm=VALUES(max_length_mm),max_width_mm=VALUES(max_width_mm),max_height_mm=VALUES(max_height_mm),minimum_warranty_years=VALUES(minimum_warranty_years),certification_required=VALUES(certification_required),updated_by=VALUES(updated_by),updated_at=NOW()");
            $vals=[$productId,trim((string)($data['rule_name']??''))?:'产品电源规则',$install,$output,...array_map(static fn($k)=>($data[$k]??'')===''?null:$data[$k],['lamp_power_w','lamp_power_min_w','lamp_power_max_w','power_band_id','output_current_min_ma','output_current_max_ma','output_voltage_min_v','output_voltage_max_v','max_length_mm','max_width_mm','max_height_mm','minimum_warranty_years']),trim((string)($data['certification_required']??''))?:null,$userId,$userId];$stmt->execute($vals);
            $id=(int)$this->db->query('SELECT id FROM mc_product_power_rules WHERE legacy_product_id='.$productId)->fetchColumn();
            $this->db->prepare('DELETE FROM mc_product_power_rule_dimming_modes WHERE rule_id=?')->execute([$id]);
            foreach(array_unique((array)($data['dimming_modes']??[]))as$mode){$s=$this->db->prepare('INSERT INTO mc_product_power_rule_dimming_modes(rule_id,mode)VALUES(?,?)');$s->execute([$id,$mode]);}
            $this->db->prepare("INSERT INTO mc_activity_logs(entity_type,entity_id,action,after_json,actor_id,created_at)VALUES('product_power_rule',?,'save',?,?,NOW())")->execute([$id,json_encode($data,JSON_UNESCAPED_UNICODE),$userId]);
            $this->db->commit();return$id;
        }catch(\Throwable$e){if($this->db->inTransaction())$this->db->rollBack();throw$e;}
    }
    public function simulate(int$ruleId,int$userId):array
    {
        $stmt=$this->db->prepare('SELECT * FROM mc_product_power_rules WHERE id=?');$stmt->execute([$ruleId]);$r=$stmt->fetch(PDO::FETCH_ASSOC);if(!$r)throw new RuntimeException('规则不存在。');
        $sql="SELECT m.id,m.material_code,m.brand,m.model,p.*,GROUP_CONCAT(DISTINCT d.mode) dimming_modes FROM mc_materials m JOIN mc_power_supply_specs p ON p.material_id=m.id LEFT JOIN mc_power_supply_dimming_modes d ON d.material_id=m.id WHERE m.deleted_at IS NULL AND m.is_official=1 GROUP BY m.id";$items=[];
        foreach($this->db->query($sql)->fetchAll(PDO::FETCH_ASSOC)as$p){$reasons=[];$ok=true;
            foreach(['installation_type'=>'安装方式','output_type'=>'输出类型']as$k=>$label)if($r[$k]!=='unknown'&&$p[$k]!==$r[$k]){$ok=false;$reasons[]=$label.'不符';}
            $lampPowerMax=$r['lamp_power_max_w']??$r['lamp_power_w'];
            if($lampPowerMax!==null&&($p['max_output_power_w']===null||(float)$p['max_output_power_w']<(float)$lampPowerMax)){$ok=false;$reasons[]='最高功率不足';}
            if($r['minimum_warranty_years']!==null&&($p['supplier_warranty_years']===null||(float)$p['supplier_warranty_years']<(float)$r['minimum_warranty_years'])){$ok=false;$reasons[]='供应商质保不足';}
            foreach(['length','width','height']as$d)if($r['max_'.$d.'_mm']!==null&&($p[$d.'_mm']===null||(float)$p[$d.'_mm']>(float)$r['max_'.$d.'_mm'])){$ok=false;$reasons[]='尺寸超限';break;}
            $items[]=['material_id'=>(int)$p['id'],'material_code'=>$p['material_code'],'brand'=>$p['brand'],'model'=>$p['model'],'result'=>$ok?'auto_match':'no_match','reasons'=>$reasons?:['满足当前已确认规则']];
        }
        $result=['rule_id'=>$ruleId,'matches'=>$items,'summary'=>['auto_match'=>count(array_filter($items,fn($i)=>$i['result']==='auto_match')),'total'=>count($items)]];
        $this->db->prepare('INSERT INTO mc_power_match_simulations(rule_id,result_json,actor_id,created_at)VALUES(?,?,?,NOW())')->execute([$ruleId,json_encode($result,JSON_UNESCAPED_UNICODE),$userId]);return$result;
    }
}
