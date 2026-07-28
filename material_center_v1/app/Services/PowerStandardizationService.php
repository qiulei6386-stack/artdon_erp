<?php
declare(strict_types=1);
namespace Artdon\MaterialCenter\Services;

use Artdon\MaterialCenter\Adapters\LegacyBomMaterialAdapter;
use Artdon\MaterialCenter\Domain\PowerSupply\PowerSpecParser;
use PDO;
use RuntimeException;

final class PowerStandardizationService
{
    public function __construct(private ?PDO $db=null){$this->db??=\db();}

    public function stagePilot(): array
    {
        $adapter=new LegacyBomMaterialAdapter($this->db);$count=0;
        foreach($adapter->powerSupplies() as $row){
            $parsed=(new PowerSpecParser())->parse($row);$power=(float)($parsed['max_output_power_w']['candidate_value']??0);
            if($power<20||$power>=25)continue;
            $this->stageRow($row,true);$count++;
        }
        return ['pilot_staged'=>$count];
    }

    public function stageSourceRecord(int $sourceRecordId,int $userId): array
    {
        $stmt=$this->db->prepare("SELECT * FROM mc_source_records WHERE id=? AND source_system='guangzhou_bom' AND source_table='bom_materials'");
        $stmt->execute([$sourceRecordId]);$source=$stmt->fetch(PDO::FETCH_ASSOC);
        if(!$source)throw new RuntimeException('电源来源记录不存在。',404);
        if($source['matched_material_id'])throw new RuntimeException('该来源已映射正式物料。');
        $row=json_decode((string)$source['snapshot_json'],true);
        if(!is_array($row)||!in_array((string)($row['category']??''),['驱动','电源'],true))throw new RuntimeException('该来源不是电源或驱动类别。');
        $this->db->beginTransaction();try{
            $stagingId=$this->stageRow($row,false);
            $parse=$this->db->prepare('SELECT field_key,candidate_value,confidence,parse_rule FROM mc_material_parse_results WHERE staging_id=?');$parse->execute([$stagingId]);
            $results=$parse->fetchAll(PDO::FETCH_ASSOC);
            $confidence=$results?array_sum(array_map(static fn(array$item):int=>['high'=>100,'medium'=>70,'low'=>40][$item['confidence']]??0,$results))/count($results):0;
            $this->db->prepare("UPDATE mc_source_records SET parse_result_json=?,confidence_score=?,status='pending' WHERE id=?")->execute([json_encode($results,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),$confidence,$sourceRecordId]);
            $this->db->prepare("INSERT INTO mc_operation_logs(module,object_type,object_id,action,new_value_json,actor_id,actor_ip,result,created_at)VALUES('material_center','source_record',?,'stage_power',?,?,?,'success',NOW())")->execute([$sourceRecordId,json_encode(['staging_id'=>$stagingId],JSON_UNESCAPED_UNICODE),$userId,(string)($_SERVER['REMOTE_ADDR']??'cli')]);
            $this->db->commit();return['staging_id'=>$stagingId,'source_record_id'=>$sourceRecordId];
        }catch(\Throwable$e){if($this->db->inTransaction())$this->db->rollBack();throw$e;}
    }

    public function workbenchRows(): array
    {
        return $this->db->query("SELECT s.*,l.material_id,(SELECT candidate_value FROM mc_material_parse_results p WHERE p.staging_id=s.id AND p.field_key='max_output_power_w') max_power,(SELECT candidate_value FROM mc_material_parse_results p WHERE p.staging_id=s.id AND p.field_key='installation_type') installation_candidate,(SELECT candidate_value FROM mc_material_parse_results p WHERE p.staging_id=s.id AND p.field_key='supplier_warranty_years') warranty_candidate,(SELECT COUNT(*) FROM mc_material_parse_results p WHERE p.staging_id=s.id) parsed_fields,(SELECT COUNT(*) FROM mc_duplicate_candidates d WHERE d.staging_id=s.id AND d.decision='pending') duplicate_risk FROM mc_material_import_staging s LEFT JOIN mc_legacy_links l ON l.source_table=s.source_table AND l.source_id=s.source_id ORDER BY s.updated_at DESC")->fetchAll(PDO::FETCH_ASSOC);
    }

    public function detail(int $stagingId): array
    {
        $stmt=$this->db->prepare('SELECT * FROM mc_material_import_staging WHERE id=?');$stmt->execute([$stagingId]);$staging=$stmt->fetch(PDO::FETCH_ASSOC);
        if(!$staging)throw new RuntimeException('Staging record not found.');
        $stmt=$this->db->prepare('SELECT * FROM mc_material_parse_results WHERE staging_id=? ORDER BY field_key');$stmt->execute([$stagingId]);
        $dup=$this->db->prepare('SELECT d.*,m.material_code,m.brand,m.model,m.name FROM mc_duplicate_candidates d JOIN mc_materials m ON m.id=d.candidate_material_id WHERE d.staging_id=?');$dup->execute([$stagingId]);
        return ['staging'=>$staging,'parse_results'=>$stmt->fetchAll(PDO::FETCH_ASSOC),'duplicates'=>$dup->fetchAll(PDO::FETCH_ASSOC)];
    }

    public function confirmAndCreate(int $stagingId,array $values,int $userId): int
    {
        foreach(['installation_type','output_type','power_band_id'] as $required)if(empty($values[$required]))throw new RuntimeException("请人工确认 {$required}");
        $allowedInstall=['internal','external','remote','integrated','track_builtin','junction_box','unknown'];$allowedOutput=['constant_current','constant_voltage','unknown'];
        if(!in_array($values['installation_type'],$allowedInstall,true)||!in_array($values['output_type'],$allowedOutput,true)||$values['installation_type']==='unknown'||$values['output_type']==='unknown')throw new RuntimeException('安装方式和输出类型必须人工确认。');
        $band=$this->db->prepare("SELECT 1 FROM mc_power_bands WHERE id=? AND status='active'");$band->execute([(int)$values['power_band_id']]);if(!$band->fetchColumn())throw new RuntimeException('功率档无效。');
        $currents=array_values(array_unique(array_filter((array)($values['current_options_ma']??[]),static fn($value)=>is_numeric($value)&&(float)$value>0)));
        if(!$currents)throw new RuntimeException('请至少确认一个有效输出电流。');
        $allowedDimming=['none','dali','dali_2','triac','0_10v','1_10v','push','dmx','nfc','multi'];foreach((array)($values['dimming_modes']??[])as$mode)if(!in_array($mode,$allowedDimming,true))throw new RuntimeException('调光方式无效。');
        $detail=$this->detail($stagingId);$s=$detail['staging'];$this->db->beginTransaction();
        try{
            $existing=$this->db->prepare("SELECT material_id FROM mc_legacy_links WHERE source_table='bom_materials' AND source_id=?");$existing->execute([$s['source_id']]);if($id=$existing->fetchColumn()){ $this->db->rollBack();return(int)$id; }
            $category=(int)$this->db->query("SELECT id FROM mc_material_categories WHERE code='power_supply'")->fetchColumn();
            $uuid=$this->uuid();$code='PS-BOM-'.str_pad((string)$s['source_id'],6,'0',STR_PAD_LEFT);
            $stmt=$this->db->prepare("INSERT INTO mc_materials(material_uuid,material_code,category_id,brand,model,name,unit,status,source,is_official,allow_bom,allow_quote,allow_customer_display,is_pilot,created_by,updated_by,created_at,updated_at) VALUES(?,?,?,?,?,?,?,'draft','legacy_bom',0,0,0,0,0,?,?,NOW(),NOW())");
            $stmt->execute([$uuid,$code,$category,$s['raw_brand'],$s['raw_model'],$s['raw_name'],$s['raw_unit'],$userId,$userId]);$mid=(int)$this->db->lastInsertId();
            $this->db->prepare("INSERT INTO mc_material_metadata(material_id,spec_summary,source_type,source_id,source_table,source_snapshot_json,owner_user_id)VALUES(?,?,'legacy_bom',?,'bom_materials',?,?)")->execute([$mid,$s['raw_spec'],$s['source_id'],json_encode(['category'=>$s['raw_category'],'name'=>$s['raw_name'],'brand'=>$s['raw_brand'],'model'=>$s['raw_model'],'spec'=>$s['raw_spec'],'unit'=>$s['raw_unit']],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),$userId]);
            $fields=['installation_type','output_type','supplier_warranty_years','nominal_power_w','min_output_power_w','max_output_power_w','power_band_id','input_voltage_min_v','input_voltage_max_v','input_frequency_min_hz','input_frequency_max_hz','output_current_ma','output_current_min_ma','output_current_max_ma','is_dip_switch','is_programmable','output_voltage_min_v','output_voltage_max_v','length_mm','width_mm','height_mm','shape','mounting_hole_distance_mm','wiring_type','ip_rating','power_factor','efficiency','thd','flicker_grade','standby_power','purchase_price','currency','moq','lead_time_days','certification','stock_status'];
            $insert=['material_id'=>$mid,'status'=>'draft','created_at'=>date('Y-m-d H:i:s'),'updated_at'=>date('Y-m-d H:i:s')];foreach($fields as $f)if(array_key_exists($f,$values)&&$values[$f]!=='')$insert[$f]=$values[$f];
            $cols=array_keys($insert);$stmt=$this->db->prepare('INSERT INTO mc_power_supply_specs(`'.implode('`,`',$cols).'`) VALUES('.implode(',',array_fill(0,count($cols),'?')).')');$stmt->execute(array_values($insert));
            foreach((array)($values['dimming_modes']??[]) as $i=>$mode){$stmt=$this->db->prepare('INSERT INTO mc_power_supply_dimming_modes(material_id,mode,is_primary) VALUES(?,?,?)');$stmt->execute([$mid,$mode,$i===0?1:0]);}
            foreach($currents as $current){$isDefault=abs((float)$current-(float)($values['output_current_ma']??0))<.001?1:0;$stmt=$this->db->prepare("INSERT IGNORE INTO mc_power_supply_current_options(material_id,current_ma,is_default,source) VALUES(?,?,?,'confirmed_parse')");$stmt->execute([$mid,$current,$isDefault]);}
            foreach($values as$field=>$value){if(is_array($value)||$value==='')continue;$stmt=$this->db->prepare('UPDATE mc_material_parse_results SET is_human_confirmed=1,confirmed_value=?,confirmed_by=?,confirmed_at=NOW(),updated_at=NOW() WHERE staging_id=? AND field_key=?');$stmt->execute([(string)$value,$userId,$stagingId,$field]);}
            $stmt=$this->db->prepare("INSERT INTO mc_legacy_links(source_table,source_id,material_id,link_type,decision,created_by,created_at,updated_at) VALUES('bom_materials',?,?,'imported','new_material',?,NOW(),NOW())");$stmt->execute([$s['source_id'],$mid,$userId]);
            $source=$this->db->prepare("SELECT id FROM mc_source_records WHERE source_system='guangzhou_bom' AND source_table='bom_materials' AND source_pk=? FOR UPDATE");$source->execute([(string)$s['source_id']]);$sourceRecordId=(int)$source->fetchColumn();
            if($sourceRecordId){$this->db->prepare("UPDATE mc_source_records SET matched_material_id=?,confirmed_by=?,confirmed_at=NOW(),status='confirmed' WHERE id=?")->execute([$mid,$userId,$sourceRecordId]);$this->db->prepare("INSERT INTO mc_source_mappings(source_record_id,material_id,mapping_type,confidence_score,status,decided_by,decided_at)VALUES(?,?,'new_material',100,'confirmed',?,NOW()) ON DUPLICATE KEY UPDATE mapping_type='new_material',confidence_score=100,status='confirmed',decided_by=VALUES(decided_by),decided_at=NOW()")->execute([$sourceRecordId,$mid,$userId]);}
            $stmt=$this->db->prepare("UPDATE mc_material_import_staging SET mapping_status='imported',updated_at=NOW() WHERE id=?");$stmt->execute([$stagingId]);
            $stmt=$this->db->prepare("INSERT INTO mc_activity_logs(entity_type,entity_id,action,after_json,actor_id,created_at) VALUES('material',?,'pilot_import',?,?,NOW())");$stmt->execute([$mid,json_encode(['staging_id'=>$stagingId,'source_id'=>$s['source_id']],JSON_UNESCAPED_UNICODE),$userId]);
            $this->db->commit();return$mid;
        }catch(\Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw$e;}
    }

    public function formalRows(string $search=''): array
    {
        $sql="SELECT m.*,c.name category_name,p.*,b.name power_band,GROUP_CONCAT(DISTINCT d.mode ORDER BY d.is_primary DESC SEPARATOR ', ') dimming_modes,GROUP_CONCAT(DISTINCT CONCAT(CAST(o.current_ma AS CHAR),IF(o.is_default=1,'*','')) ORDER BY o.current_ma SEPARATOR ', ') current_options FROM mc_materials m JOIN mc_material_categories c ON c.id=m.category_id JOIN mc_power_supply_specs p ON p.material_id=m.id LEFT JOIN mc_power_bands b ON b.id=p.power_band_id LEFT JOIN mc_power_supply_dimming_modes d ON d.material_id=m.id LEFT JOIN mc_power_supply_current_options o ON o.material_id=m.id WHERE m.deleted_at IS NULL";$params=[];
        if($search!==''){$sql.=" AND (m.material_code LIKE ? OR m.brand LIKE ? OR m.model LIKE ? OR m.name LIKE ?)";$term='%'.$search.'%';$params=[$term,$term,$term,$term];}
        $sql.=" GROUP BY m.id ORDER BY m.updated_at DESC";$stmt=$this->db->prepare($sql);$stmt->execute($params);return$stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    public function bands(): array{return$this->db->query("SELECT * FROM mc_power_bands ORDER BY sort_order,id")->fetchAll(PDO::FETCH_ASSOC);}
    public function linkExisting(int $stagingId,int $materialId,int $userId,string $decision='existing_material'): void
    {
        if(!in_array($decision,['existing_material','different_supplier','different_version'],true))throw new RuntimeException('重复处理决定无效。');
        $detail=$this->detail($stagingId);$s=$detail['staging'];
        $stmt=$this->db->prepare('SELECT 1 FROM mc_materials WHERE id=? AND deleted_at IS NULL');$stmt->execute([$materialId]);if(!$stmt->fetchColumn())throw new RuntimeException('正式物料不存在。');
        $this->db->beginTransaction();try{
            $stmt=$this->db->prepare("INSERT INTO mc_legacy_links(source_table,source_id,material_id,link_type,decision,created_by,created_at,updated_at) VALUES('bom_materials',?,?,'linked',?,?,NOW(),NOW()) ON DUPLICATE KEY UPDATE material_id=VALUES(material_id),link_type='linked',decision=VALUES(decision),created_by=VALUES(created_by),updated_at=NOW()");$stmt->execute([$s['source_id'],$materialId,$decision,$userId]);
            $source=$this->db->prepare("SELECT id FROM mc_source_records WHERE source_system='guangzhou_bom' AND source_table='bom_materials' AND source_pk=? FOR UPDATE");$source->execute([(string)$s['source_id']]);$sourceRecordId=(int)$source->fetchColumn();
            if($sourceRecordId){$this->db->prepare("UPDATE mc_source_records SET matched_material_id=?,confirmed_by=?,confirmed_at=NOW(),status='confirmed' WHERE id=?")->execute([$materialId,$userId,$sourceRecordId]);$this->db->prepare("INSERT INTO mc_source_mappings(source_record_id,material_id,mapping_type,confidence_score,status,decided_by,decided_at)VALUES(?,?,'existing_material',100,'confirmed',?,NOW()) ON DUPLICATE KEY UPDATE material_id=VALUES(material_id),mapping_type='existing_material',confidence_score=100,status='confirmed',decided_by=VALUES(decided_by),decided_at=NOW()")->execute([$sourceRecordId,$materialId,$userId]);}
            $stmt=$this->db->prepare("UPDATE mc_material_import_staging SET mapping_status='confirmed',updated_at=NOW() WHERE id=?");$stmt->execute([$stagingId]);
            $stmt=$this->db->prepare("INSERT INTO mc_activity_logs(entity_type,entity_id,action,after_json,actor_id,created_at) VALUES('legacy_link',?,'link_existing',?,?,NOW())");$stmt->execute([$stagingId,json_encode(['material_id'=>$materialId,'decision'=>$decision]),$userId]);$this->db->commit();
        }catch(\Throwable$e){if($this->db->inTransaction())$this->db->rollBack();throw$e;}
    }
    public function decideDuplicate(int $candidateId,string $decision,int $userId):void
    {
        if(!in_array($decision,['merge_existing','different_supplier','different_version','not_duplicate','deferred'],true))throw new RuntimeException('重复处理决定无效。');
        $stmt=$this->db->prepare('UPDATE mc_duplicate_candidates SET decision=?,decided_by=?,decided_at=NOW() WHERE id=?');$stmt->execute([$decision,$userId,$candidateId]);if(!$stmt->rowCount())throw new RuntimeException('重复候选不存在。');
        $stmt=$this->db->prepare("INSERT INTO mc_activity_logs(entity_type,entity_id,action,after_json,actor_id,created_at) VALUES('duplicate_candidate',?,'decide',?,?,NOW())");$stmt->execute([$candidateId,json_encode(['decision'=>$decision]),$userId]);
    }
    public function reject(int $stagingId,int $userId):void
    {
        $stmt=$this->db->prepare("UPDATE mc_material_import_staging SET mapping_status='rejected',updated_at=NOW() WHERE id=?");$stmt->execute([$stagingId]);
        $stmt=$this->db->prepare("INSERT INTO mc_activity_logs(entity_type,entity_id,action,actor_id,created_at) VALUES('staging',?,'defer',?,NOW())");$stmt->execute([$stagingId,$userId]);
    }
    public function saveBand(array $data,int $userId):int
    {
        $min=(float)($data['min_power_w']??0);$max=(float)($data['max_power_w']??0);if($min<0||$max<=$min)throw new RuntimeException('功率边界无效。');
        $id=(int)($data['id']??0);if($id){$stmt=$this->db->prepare('UPDATE mc_power_bands SET name=?,min_power_w=?,max_power_w=?,max_inclusive=?,status=?,updated_at=NOW() WHERE id=?');$stmt->execute([$data['name'],$min,$max,!empty($data['max_inclusive'])?1:0,$data['status']??'active',$id]);}
        else{$code=preg_replace('/[^a-z0-9_]/','',strtolower((string)($data['code']??'')));if($code==='')throw new RuntimeException('功率档代码无效。');$stmt=$this->db->prepare('INSERT INTO mc_power_bands(code,name,min_power_w,max_power_w,max_inclusive,status,sort_order,created_at,updated_at) VALUES(?,?,?,?,?,?,?,NOW(),NOW())');$stmt->execute([$code,$data['name'],$min,$max,!empty($data['max_inclusive'])?1:0,$data['status']??'active',(int)($data['sort_order']??0)]);$id=(int)$this->db->lastInsertId();}
        $stmt=$this->db->prepare("INSERT INTO mc_activity_logs(entity_type,entity_id,action,after_json,actor_id,created_at) VALUES('power_band',?,'save',?,?,NOW())");$stmt->execute([$id,json_encode($data,JSON_UNESCAPED_UNICODE),$userId]);return$id;
    }
    private function suggestDuplicates(int $sid,array $legacy,array $parsed):void
    {
        if(!\mc_table_exists('mc_materials'))return;$stmt=$this->db->prepare("SELECT m.id,m.brand,m.model,p.output_current_ma,p.output_voltage_min_v,p.output_voltage_max_v,p.max_output_power_w FROM mc_materials m JOIN mc_power_supply_specs p ON p.material_id=m.id WHERE m.deleted_at IS NULL AND ((m.brand<>'' AND m.brand=?) OR (m.model<>'' AND m.model=?)) LIMIT 20");$stmt->execute([$legacy['brand'],$legacy['model']]);
        foreach($stmt->fetchAll(PDO::FETCH_ASSOC) as $m){$matched=[];$score=0;foreach(['brand'=>20,'model'=>30] as $f=>$w)if(($legacy[$f]??'')!==''&&strcasecmp((string)$legacy[$f],(string)$m[$f])===0){$score+=$w;$matched[]=$f;}foreach(['output_current_ma'=>20,'max_output_power_w'=>15] as $f=>$w)if(isset($parsed[$f])&&abs((float)$parsed[$f]['candidate_value']-(float)$m[$f])<.01){$score+=$w;$matched[]=$f;}if($score<30)continue;$q=$this->db->prepare("INSERT IGNORE INTO mc_duplicate_candidates(staging_id,candidate_material_id,score,matched_fields_json,decision,created_at) VALUES(?,?,?,?,'pending',NOW())");$q->execute([$sid,$m['id'],$score,json_encode($matched)]);}
    }
    private function stageRow(array$row,bool$isPilot):int
    {
        $parsed=(new PowerSpecParser())->parse($row);$power=(float)($parsed['max_output_power_w']['candidate_value']??0);$band=$this->suggestBand($power);if($band)$parsed['power_band_id']=['candidate_value'=>(string)$band['id'],'original_text'=>(string)$power.'W','confidence'=>'high','parse_rule'=>'database_power_band_boundary','is_human_confirmed'=>0];
        $payload=[$row['category']??'',$row['name']??'',$row['brand']??'',$row['model']??'',$row['spec']??'',$row['price']??'',$row['unit']??'',$row['updated_at']??''];$hash=hash('sha256',json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
        $sql="INSERT INTO mc_material_import_staging(source_table,source_id,raw_category,raw_name,raw_brand,raw_model,raw_spec,raw_price,raw_unit,raw_updated_at,raw_data_hash,mapping_status,is_pilot,staged_at,updated_at)
              VALUES('bom_materials',?,?,?,?,?,?,?,?,?,?,'parsed',?,NOW(),NOW())
              ON DUPLICATE KEY UPDATE raw_category=VALUES(raw_category),raw_name=VALUES(raw_name),raw_brand=VALUES(raw_brand),raw_model=VALUES(raw_model),raw_spec=VALUES(raw_spec),raw_price=VALUES(raw_price),raw_unit=VALUES(raw_unit),raw_updated_at=VALUES(raw_updated_at),raw_data_hash=VALUES(raw_data_hash),mapping_status=IF(mapping_status='imported','imported','parsed'),is_pilot=GREATEST(is_pilot,VALUES(is_pilot)),updated_at=NOW()";
        $stmt=$this->db->prepare($sql);$stmt->execute([(int)$row['id'],(string)($row['category']??''),(string)($row['name']??''),(string)($row['brand']??''),(string)($row['model']??''),(string)($row['spec']??''),$row['price']??null,(string)($row['unit']??''),$row['updated_at']??null,$hash,$isPilot?1:0]);
        $find=$this->db->prepare("SELECT id FROM mc_material_import_staging WHERE source_table='bom_materials' AND source_id=?");$find->execute([(int)$row['id']]);$sid=(int)$find->fetchColumn();
        foreach($parsed as$key=>$result){$stmt=$this->db->prepare("INSERT INTO mc_material_parse_results(staging_id,field_key,candidate_value,original_text,confidence,parse_rule,is_human_confirmed,created_at,updated_at)VALUES(?,?,?,?,?,?,0,NOW(),NOW()) ON DUPLICATE KEY UPDATE candidate_value=IF(is_human_confirmed=1,candidate_value,VALUES(candidate_value)),original_text=IF(is_human_confirmed=1,original_text,VALUES(original_text)),confidence=IF(is_human_confirmed=1,confidence,VALUES(confidence)),parse_rule=IF(is_human_confirmed=1,parse_rule,VALUES(parse_rule)),updated_at=NOW()");$stmt->execute([$sid,$key,$result['candidate_value'],$result['original_text'],$result['confidence'],$result['parse_rule']]);}
        $this->suggestDuplicates($sid,$row,$parsed);return$sid;
    }
    private function suggestBand(float $power):?array
    {
        $stmt=$this->db->prepare("SELECT * FROM mc_power_bands WHERE status='active' AND ? >= min_power_w AND (? < max_power_w OR (max_inclusive=1 AND ? <= max_power_w)) ORDER BY sort_order LIMIT 1");$stmt->execute([$power,$power,$power]);return$stmt->fetch(PDO::FETCH_ASSOC)?:null;
    }
    private function uuid():string{$d=random_bytes(16);$d[6]=chr((ord($d[6])&0x0f)|0x40);$d[8]=chr((ord($d[8])&0x3f)|0x80);return vsprintf('%s%s-%s-%s-%s-%s%s%s',str_split(bin2hex($d),4));}
}
