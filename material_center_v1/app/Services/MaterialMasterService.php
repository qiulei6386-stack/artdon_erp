<?php
declare(strict_types=1);
namespace Artdon\MaterialCenter\Services;
use PDO;use RuntimeException;
final class MaterialMasterService{
 public function __construct(private ?PDO$db=null){$this->db??=\db();}
 public function page(string$q='',string$category='',string$status='',int$page=1,int$pageSize=50):array{
  $page=max(1,$page);$pageSize=max(10,min(100,$pageSize));[$where,$params]=$this->where($q,$category,$status);
  $count=$this->db->prepare("SELECT COUNT(*) FROM mc_materials m JOIN mc_material_categories c ON c.id=m.category_id LEFT JOIN mc_material_metadata md ON md.material_id=m.id $where");$count->execute($params);$total=(int)$count->fetchColumn();$pages=max(1,(int)ceil($total/$pageSize));$page=min($page,$pages);$offset=($page-1)*$pageSize;
  $sql="SELECT m.*,c.code category_code,c.name category_name,md.spec_summary,md.supplier_text,md.remark,md.lock_version,p.supplier_warranty_years,(SELECT MIN(sm.source_record_id) FROM mc_source_mappings sm WHERE sm.material_id=m.id) source_record_id,(SELECT COUNT(*) FROM mc_legacy_links l WHERE l.material_id=m.id) legacy_links FROM mc_materials m JOIN mc_material_categories c ON c.id=m.category_id LEFT JOIN mc_material_metadata md ON md.material_id=m.id LEFT JOIN mc_power_supply_specs p ON p.material_id=m.id $where ORDER BY m.updated_at DESC,m.id DESC LIMIT $pageSize OFFSET $offset";$statement=$this->db->prepare($sql);$statement->execute($params);
  return['rows'=>$statement->fetchAll(PDO::FETCH_ASSOC),'total'=>$total,'page'=>$page,'page_size'=>$pageSize,'pages'=>$pages];
 }
 public function rows(string$q='',string$category='',string$status=''):array{return$this->page($q,$category,$status,1,100)['rows'];}
 private function where(string$q,string$category,string$status):array{$sql='WHERE m.deleted_at IS NULL';$p=[];if($q!==''){$sql.=" AND (m.material_code LIKE ? OR m.name LIKE ? OR m.brand LIKE ? OR m.model LIKE ? OR md.spec_summary LIKE ?)";$v='%'.$q.'%';$p=[$v,$v,$v,$v,$v];}if($category!==''){$sql.=' AND c.code=?';$p[]=$category;}if($status!==''){$sql.=' AND m.status=?';$p[]=$status;}return[$sql,$p];}
 public function categories():array{return$this->db->query("SELECT id,code,name FROM mc_material_categories WHERE status='active' ORDER BY sort_order,id")->fetchAll(PDO::FETCH_ASSOC);}
 public function save(array$d,int$userId):int{
  $own=!$this->db->inTransaction();if($own)$this->db->beginTransaction();try{
  foreach(['category_id','name','unit']as$k)if(trim((string)($d[$k]??''))==='')throw new RuntimeException('请完整填写分类、名称和单位。');
  $id=(int)($d['id']??0);$category=(int)$d['category_id'];$s=$this->db->prepare("SELECT code FROM mc_material_categories WHERE id=? AND status='active'");$s->execute([$category]);$categoryCode=$s->fetchColumn();if(!$categoryCode)throw new RuntimeException('物料分类无效。');
  if($id){$lock=$this->db->prepare('SELECT md.lock_version,m.status FROM mc_materials m JOIN mc_material_metadata md ON md.material_id=m.id WHERE m.id=? AND m.deleted_at IS NULL FOR UPDATE');$lock->execute([$id]);$locked=$lock->fetch(PDO::FETCH_ASSOC);if(!$locked)throw new RuntimeException('物料不存在。',404);$current=(int)$locked['lock_version'];$expected=(int)($d['lock_version']??$current);if($expected!==$current)throw new RuntimeException('物料已被其他用户更新，请刷新后重试。',409);if($locked['status']!=='draft')throw new RuntimeException('只有草稿物料可以直接编辑。');$s=$this->db->prepare("UPDATE mc_materials SET category_id=?,brand=?,model=?,name=?,unit=?,updated_by=?,updated_at=NOW() WHERE id=? AND status='draft' AND deleted_at IS NULL");$s->execute([$category,$this->null($d['brand']??''),$this->null($d['model']??''),trim((string)$d['name']),trim((string)$d['unit']),$userId,$id]);(new CategoryFieldService($this->db))->save($id,(string)$categoryCode,(array)($d['fields']??[]));$this->db->prepare('UPDATE mc_material_metadata SET spec_summary=?,supplier_text=?,remark=?,lock_version=lock_version+1 WHERE material_id=? AND lock_version=?')->execute([$this->null($d['spec_summary']??''),$this->null($d['supplier_text']??''),$this->longText($d['remark']??''),$id,$current]);$this->log($id,'edit',['lock_version'=>$current+1,'fields'=>array_merge(['category_id','brand','model','name','unit','spec_summary','supplier_text','remark'],array_keys((array)($d['fields']??[])))],$userId);if($own)$this->db->commit();return$id;}
  $code=$this->nextCode((string)$categoryCode);$uuid=$this->uuid();$s=$this->db->prepare("INSERT INTO mc_materials(material_uuid,material_code,category_id,brand,model,name,unit,status,source,is_official,allow_bom,allow_quote,allow_customer_display,is_pilot,created_by,updated_by,created_at,updated_at)VALUES(?,?,?,?,?,?,?,'draft','material_center_manual',0,0,0,0,0,?,?,NOW(),NOW())");$s->execute([$uuid,$code,$category,$this->null($d['brand']??''),$this->null($d['model']??''),trim((string)$d['name']),trim((string)$d['unit']),$userId,$userId]);$id=(int)$this->db->lastInsertId();
  $this->db->prepare("INSERT INTO mc_material_metadata(material_id,spec_summary,supplier_text,remark,source_type,owner_user_id)VALUES(?,?,?,?,'manual',?)")->execute([$id,$this->null($d['spec_summary']??''),$this->null($d['supplier_text']??''),$this->longText($d['remark']??''),$userId]);
  if($categoryCode==='power_supply')$this->db->prepare("INSERT INTO mc_power_supply_specs(material_id,installation_type,output_type,status,created_at,updated_at)VALUES(?,'unknown','unknown','draft',NOW(),NOW())")->execute([$id]);(new CategoryFieldService($this->db))->save($id,(string)$categoryCode,(array)($d['fields']??[]));
  $this->log($id,'create',['source'=>'material_center_manual'],$userId);if($own)$this->db->commit();return$id;
  }catch(\Throwable$e){if($own&&$this->db->inTransaction())$this->db->rollBack();throw$e;}
 }
 public function copy(int$id,int$userId):int{return$this->cloneDraft($id,$userId,'copy');}
 public function revisionDraft(int$id,int$userId):int{return$this->cloneDraft($id,$userId,'revision_draft');}
 private function cloneDraft(int$id,int$userId,string$mode):int{
  $s=$this->db->prepare('SELECT m.*,c.code category_code,md.spec_summary,md.supplier_text,md.remark FROM mc_materials m JOIN mc_material_categories c ON c.id=m.category_id LEFT JOIN mc_material_metadata md ON md.material_id=m.id WHERE m.id=? AND m.deleted_at IS NULL');
  $s->execute([$id]);$r=$s->fetch(PDO::FETCH_ASSOC);if(!$r)throw new RuntimeException('原物料不存在。');
  $isRevision=$mode==='revision_draft';
  if($isRevision&&$r['status']!=='official')throw new RuntimeException('只有正式物料可以生成修订草稿。');
  $fields=(new CategoryFieldService($this->db))->values($id,(string)$r['category_code']);
  $name=$isRevision?(string)$r['name']:(string)$r['name'].'（复制）';
  $note=$isRevision?'修订自正式物料 '.$r['material_code'].'；请修改后提交审核。':'';
  $remark=trim((string)($r['remark']??''));
  if($note!==''&&!str_contains($remark,$note))$remark=trim($remark.($remark!==''?PHP_EOL.PHP_EOL:'').$note);
  $own=!$this->db->inTransaction();if($own)$this->db->beginTransaction();
  try{
   $new=$this->save(['category_id'=>$r['category_id'],'brand'=>$r['brand'],'model'=>$r['model'],'name'=>$name,'unit'=>$r['unit'],'spec_summary'=>$r['spec_summary'],'supplier_text'=>$r['supplier_text'],'remark'=>$remark,'fields'=>$fields],$userId);
   if($isRevision){
    $snapshot=$this->snapshot($id);
    $this->db->prepare("UPDATE mc_materials SET source='material_revision',updated_by=?,updated_at=NOW() WHERE id=?")->execute([$userId,$new]);
    $this->db->prepare("UPDATE mc_material_metadata SET source_type='revision',source_id=?,source_table='mc_materials',source_snapshot_json=? WHERE material_id=?")->execute([$id,json_encode($snapshot,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),$new]);
   }
   if($r['category_code']==='chip'&&\mc_table_exists('mc_chip_spec_variants')){
    $this->db->prepare("INSERT INTO mc_chip_spec_variants(material_id,variant_code,spec_key,cct_k,cct_min_k,cct_max_k,cri,sdcm,r9,luminous_flux_lm,efficacy_lm_w,supplier_spec_code,purchase_price,currency,stock_quantity,lead_time_days,source_type,source_template_id,source_template_version_no,is_default,needs_confirmation,status,sort_order,created_by,updated_by,created_at,updated_at)SELECT ?,variant_code,spec_key,cct_k,cct_min_k,cct_max_k,cri,sdcm,r9,luminous_flux_lm,efficacy_lm_w,supplier_spec_code,purchase_price,currency,stock_quantity,lead_time_days,source_type,source_template_id,source_template_version_no,is_default,needs_confirmation,status,sort_order,?,?,NOW(),NOW() FROM mc_chip_spec_variants WHERE material_id=?")->execute([$new,$userId,$userId,$id]);
    $this->db->prepare("INSERT INTO mc_chip_material_templates(material_id,template_id,applied_version_no,applied_by,applied_at,synced_at)SELECT ?,template_id,applied_version_no,?,NOW(),NOW() FROM mc_chip_material_templates WHERE material_id=?")->execute([$new,$userId,$id]);
   }
   if($r['category_code']==='optical'&&\mc_table_exists('mc_lens_chip_angle_compatibilities')){
    $this->db->prepare("INSERT INTO mc_lens_chip_angle_compatibilities(lens_material_id,chip_material_id,chip_keyword,lens_beam_angle_deg,actual_beam_angle_deg,beam_angle_label,les_text,note,status,sort_order,created_by,updated_by,created_at,updated_at)SELECT ?,chip_material_id,chip_keyword,lens_beam_angle_deg,actual_beam_angle_deg,beam_angle_label,les_text,note,status,sort_order,?,?,NOW(),NOW() FROM mc_lens_chip_angle_compatibilities WHERE lens_material_id=? AND status='active'")->execute([$new,$userId,$userId,$id]);
   }
   $logAction=$isRevision?'revision_draft_created':'copy';
   $this->log($new,$logAction,['source_material_id'=>$id,'source_material_code'=>$r['material_code'],'fields'=>array_keys($fields),'chip_variants_copied'=>$r['category_code']==='chip','lens_angle_compatibility_copied'=>$r['category_code']==='optical'],$userId);
   if($isRevision)$this->log($id,'revision_draft_source',['draft_material_id'=>$new],$userId);
   if($own)$this->db->commit();
   return$new;
  }catch(\Throwable$e){if($own&&$this->db->inTransaction())$this->db->rollBack();throw$e;}
 }
 public function transition(int$id,string$action,int$userId,string$reason=''):void{(new MaterialLifecycleService($this->db))->transition($id,$action,$userId,$reason);$this->log($id,$action,['reason'=>$reason],$userId);}
 public function deleteDraft(int$id,int$userId):void{$s=$this->db->prepare("SELECT status FROM mc_materials WHERE id=? AND deleted_at IS NULL");$s->execute([$id]);if($s->fetchColumn()!=='draft')throw new RuntimeException('只有草稿物料可以删除。');$refs=$this->references($id);if(array_sum($refs)>0)throw new RuntimeException('物料已有引用，不能删除草稿。');$this->db->beginTransaction();try{if(\mc_table_exists('mc_lens_chip_angle_compatibilities'))$this->db->prepare('DELETE FROM mc_lens_chip_angle_compatibilities WHERE lens_material_id=?')->execute([$id]);$this->db->prepare('DELETE FROM mc_power_supply_current_options WHERE material_id=?')->execute([$id]);$this->db->prepare('DELETE FROM mc_power_supply_dimming_modes WHERE material_id=?')->execute([$id]);$this->db->prepare('DELETE FROM mc_power_supply_specs WHERE material_id=?')->execute([$id]);$this->db->prepare('DELETE FROM mc_materials WHERE id=?')->execute([$id]);$this->log($id,'delete_draft',['references'=>$refs],$userId);$this->db->commit();}catch(\Throwable$e){if($this->db->inTransaction())$this->db->rollBack();throw$e;}}
 public function references(int$id):array{$checks=['legacy_links'=>'SELECT COUNT(*) FROM mc_legacy_links WHERE material_id=?','supplier_materials'=>'SELECT COUNT(*) FROM mc_supplier_materials WHERE material_id=?','alternatives'=>'SELECT COUNT(*) FROM mc_material_alternatives WHERE material_id=? OR alternative_material_id=?','substitutions'=>'SELECT COUNT(*) FROM mc_material_substitutions WHERE material_id=? OR substitute_material_id=?','adaptation_options'=>'SELECT COUNT(*) FROM mc_adaptation_options WHERE material_id=?','product_rules'=>'SELECT COUNT(*) FROM mc_product_power_approved_alternatives WHERE material_id=?'];$out=[];foreach($checks as$k=>$sql){$s=$this->db->prepare($sql);$params=substr_count($sql,'?')===2?[$id,$id]:[$id];$s->execute($params);$out[$k]=(int)$s->fetchColumn();}return$out;}
 private function snapshot(int$id):array{$s=$this->db->prepare('SELECT m.*,c.code category_code,md.spec_summary,md.supplier_text,md.remark,md.lock_version FROM mc_materials m JOIN mc_material_categories c ON c.id=m.category_id LEFT JOIN mc_material_metadata md ON md.material_id=m.id WHERE m.id=?');$s->execute([$id]);$row=$s->fetch(PDO::FETCH_ASSOC)?:[];if($row){$row['fields']=(new CategoryFieldService($this->db))->values($id,(string)$row['category_code']);}return$row;}
 private function nextCode(string$category):string{$prefix=$category==='power_supply'?'PS':'MC';do{$code=$prefix.'-'.date('ymd').'-'.strtoupper(substr(bin2hex(random_bytes(4)),0,6));$s=$this->db->prepare('SELECT 1 FROM mc_materials WHERE material_code=?');$s->execute([$code]);}while($s->fetchColumn());return$code;}
 private function log(int$id,string$action,array$data,int$user):void{$json=json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);$this->db->prepare("INSERT INTO mc_activity_logs(entity_type,entity_id,action,after_json,actor_id,created_at)VALUES('material',?,?,?,?,NOW())")->execute([$id,$action,$json,$user]);if(\mc_table_exists('mc_operation_logs'))$this->db->prepare("INSERT INTO mc_operation_logs(module,object_type,object_id,action,new_value_json,actor_id,actor_ip,result,created_at)VALUES('material_center','material',?,?,?,?,?,'success',NOW())")->execute([$id,$action,$json,$user,(string)($_SERVER['REMOTE_ADDR']??'cli')]);}
 private function null(mixed$v):?string{$v=trim((string)$v);return$v===''?null:mb_substr($v,0,200);}
 private function longText(mixed$v):?string{$v=trim((string)$v);return$v===''?null:mb_substr($v,0,5000);}
 private function uuid():string{$d=random_bytes(16);$d[6]=chr((ord($d[6])&15)|64);$d[8]=chr((ord($d[8])&63)|128);return vsprintf('%s%s-%s-%s-%s-%s%s%s',str_split(bin2hex($d),4));}
}
