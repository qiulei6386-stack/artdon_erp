<?php
declare(strict_types=1);
namespace Artdon\CommercialCenter\Repositories;
use PDO;
final class ConfigurationRepository
{
    private PDO $db;
    public function __construct(){ $this->db=db(); }
    public function catalog(int $userId=0,?int $customerId=null): array
    {
        $groups=$this->all("SELECT g.id,g.group_code,g.name,g.is_required,g.is_multiple,g.sort_order,
          s.input_type,s.is_advanced,s.customer_visible,s.affects_cost,s.affects_price,s.affects_moq,s.affects_lead_time,s.allow_custom
          FROM cc_config_groups g JOIN cc_config_group_settings s ON s.group_id=g.id WHERE g.status='active' ORDER BY g.sort_order,g.id");
        $options=$this->all("SELECT o.id,o.group_id,o.option_code,o.name,o.is_default,o.cost_delta,o.sales_delta,o.moq_delta,o.lead_time_days
          FROM cc_config_options o WHERE o.status='active' ORDER BY o.group_id,o.id");
        $byGroup=[];foreach($options as $option)$byGroup[(int)$option['group_id']][]=$option;
        foreach($groups as &$group)$group['options']=$byGroup[(int)$group['id']]??[];unset($group);
        $params=[$userId,$customerId??0];
        $presets=$this->all("SELECT id,preset_code,name,preset_type,scope_type,legacy_product_id,legacy_customer_id,owner_legacy_user_id,channel_code,version_no
          FROM cc_config_presets WHERE status='active' AND (scope_type IN ('global','channel') OR owner_legacy_user_id=? OR legacy_customer_id=?) ORDER BY FIELD(preset_type,'factory_standard','economy','standard','premium','channel','customer','personal'),id",$params);
        if($presets){$ids=array_column($presets,'id');$marks=implode(',',array_fill(0,count($ids),'?'));$values=$this->all("SELECT pv.preset_id,g.group_code,pv.value_json,pv.is_locked,pv.lock_type FROM cc_config_preset_values pv JOIN cc_config_groups g ON g.id=pv.group_id WHERE pv.preset_id IN ($marks)",$ids);$pv=[];foreach($values as $v){$pv[(int)$v['preset_id']][$v['group_code']]=['value'=>json_decode($v['value_json'],true)??trim($v['value_json'],'"'),'locked'=>(bool)$v['is_locked'],'lock_type'=>$v['lock_type']];}foreach($presets as &$p)$p['values']=$pv[(int)$p['id']]??[];unset($p);}
        return ['groups'=>$groups,'presets'=>$presets,'products'=>$this->products(),'stock_skus'=>$this->stockSkus(),'material_center'=>$this->materialCenterAdaptations()];
    }
    public function rules(): array{return $this->all("SELECT id,rule_code,name,rule_type,condition_json,effect_json FROM cc_compatibility_rules WHERE status='active' ORDER BY id");}
    public function allowedOptions(int $legacyProductId): array
    {
        $rows=$this->all("SELECT g.group_code,o.option_code FROM cc_product_allowed_options a JOIN cc_config_groups g ON g.id=a.group_id LEFT JOIN cc_config_options o ON o.id=a.option_id WHERE a.legacy_product_id=? AND a.status='active'",[$legacyProductId]);
        $out=[];foreach($rows as $row)if($row['option_code']!==null)$out[$row['group_code']][]=$row['option_code'];return $out;
    }
    public function products(): array{return $this->all("SELECT id,model_no,product_name,category FROM naming_models WHERE website_deleted=0 ORDER BY updated_at DESC,id DESC LIMIT 100");}
    public function stockSkus(): array{return $this->all("SELECT s.id,s.legacy_product_id,s.sku_code,s.configuration_snapshot,s.actual_stock,s.sellable_stock,s.status,s.is_test,n.model_no,n.product_name FROM cc_inventory_skus s LEFT JOIN naming_models n ON n.id=s.legacy_product_id WHERE s.status='active' ORDER BY s.is_test,s.id DESC LIMIT 100");}
    public function materialCenterAdaptations(): array
    {
        foreach(['mc_products','mc_adaptation_groups','mc_adaptation_options','mc_materials','mc_material_categories']as$table)if(!$this->tableExists($table))return[];
        $rows=$this->all("SELECT p.legacy_id,g.id group_id,g.group_code,g.group_name,g.group_type,g.business_type,g.is_required,g.selection_mode,
          o.id option_id,o.option_type,o.is_default,o.price_impact,o.lead_time_impact_days,
          m.id material_id,m.material_code,m.name material_name,m.brand,m.model,c.code category_code
          FROM mc_products p
          JOIN mc_adaptation_groups g ON g.product_id=p.id AND g.status='approved' AND g.is_enabled=1
          JOIN mc_adaptation_options o ON o.group_id=g.id AND o.status='approved' AND o.option_type<>'disabled'
          JOIN mc_materials m ON m.id=o.material_id AND m.status='official' AND m.is_official=1 AND m.allow_quote=1 AND m.deleted_at IS NULL
          JOIN mc_material_categories c ON c.id=m.category_id
          WHERE p.legacy_table='naming_models' AND p.status='active'
          AND NOT EXISTS(SELECT 1 FROM mc_adaptation_groups gx WHERE gx.product_id=p.id AND gx.status<>'disabled' AND (gx.status<>'approved' OR gx.is_enabled=0))
          AND NOT EXISTS(SELECT 1 FROM mc_adaptation_options ox JOIN mc_adaptation_groups gox ON gox.id=ox.group_id WHERE gox.product_id=p.id AND gox.status<>'disabled' AND ox.status<>'approved')
          ORDER BY p.legacy_id,g.sort_order,g.id,o.sort_order,o.id");
        $result=[];
        foreach($rows as$row){
            $product=(string)(int)$row['legacy_id'];$groupCode='mc_'.$row['group_code'];
            if(!isset($result[$product][$groupCode]))$result[$product][$groupCode]=[
                'id'=>'mc-'.(int)$row['group_id'],'group_code'=>$groupCode,'name'=>$row['group_name'],
                'is_required'=>(int)$row['is_required'],'is_multiple'=>$row['selection_mode']==='multi'?1:0,'sort_order'=>1000+(int)$row['group_id'],
                'input_type'=>'select','is_advanced'=>0,'customer_visible'=>1,'affects_cost'=>0,'affects_price'=>1,
                'affects_moq'=>0,'affects_lead_time'=>1,'allow_custom'=>0,'source'=>'material_center','options'=>[],
            ];
            $result[$product][$groupCode]['options'][]=[
                'id'=>'mc-'.(int)$row['option_id'],'group_id'=>'mc-'.(int)$row['group_id'],
                'option_code'=>'mc_material_'.(int)$row['material_id'],
                'name'=>trim($row['material_code'].' '.$row['material_name'].' '.($row['model']??'')),
                'is_default'=>(int)$row['is_default'],'cost_delta'=>0,
                'sales_delta'=>(float)($row['price_impact']??0),'moq_delta'=>0,
                'lead_time_days'=>(int)($row['lead_time_impact_days']??0),
                'material_id'=>(int)$row['material_id'],'material_code'=>$row['material_code'],
                'category_code'=>$row['category_code'],'option_type'=>$row['option_type'],'source'=>'material_center',
            ];
        }
        foreach($result as$product=>$groups)$result[$product]=array_values($groups);
        return$result;
    }
    public function savePreset(array $data,int $userId,?int $customerId): int
    {
        $scope=$data['scope']==='customer'?'customer':'personal';if($scope==='customer'&&!$customerId)throw new \InvalidArgumentException('保存客户预设需要客户ID。');
        $now=date('Y-m-d H:i:s');$pid=$this->uuid();$code='preset-'.$scope.'-'.$userId.'-'.date('YmdHis').'-'.random_int(100,999);
        $ownsTransaction=!$this->db->inTransaction();if($ownsTransaction)$this->db->beginTransaction();try{$s=$this->db->prepare('INSERT INTO cc_config_presets(permanent_id,preset_code,name,preset_type,scope_type,legacy_product_id,legacy_customer_id,owner_legacy_user_id,version_no,status,created_by_legacy_user_id,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?)');
          $s->execute([$pid,$code,mb_substr(trim((string)$data['name']),0,190),$scope,$scope,$data['legacy_product_id']?:null,$customerId,$scope==='personal'?$userId:null,1,'active',$userId,$now,$now]);$id=(int)$this->db->lastInsertId();
          $groups=$this->groupMap();$s=$this->db->prepare('INSERT INTO cc_config_preset_values(preset_id,group_id,value_json,is_locked,created_at,updated_at) VALUES(?,?,?,?,?,?)');
          foreach($data['values'] as $key=>$value)if(isset($groups[$key]))$s->execute([$id,$groups[$key],json_encode($value,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),0,$now,$now]);
          if($ownsTransaction)$this->db->commit();return $id;
        }catch(\Throwable $e){if($ownsTransaction&&$this->db->inTransaction())$this->db->rollBack();throw $e;}
    }
    public function saveConfiguration(array $vm,int $userId): array
    {
        $now=date('Y-m-d H:i:s');$pid=$this->uuid();$snapshot=$vm;$snapshot['saved_at']=$now;$json=json_encode($snapshot,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);$hash=hash('sha256',$json);
        $s=$this->db->prepare('INSERT INTO cc_configuration_instances(permanent_id,legacy_product_id,inventory_sku_id,preset_id,mode,product_type,values_json,differences_json,validation_status,approval_status,total_cost,suggested_price,current_price,moq,lead_time_days,status,created_by_legacy_user_id,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
        $s->execute([$pid,$vm['product']['legacy_product_id']?:null,$vm['product']['inventory_sku_id']?:null,$vm['preset_id']?:null,$vm['mode'],$vm['product']['type'],json_encode($vm['values']),json_encode($vm['differences']),$vm['status'],$vm['approval']['required']?'required':'not_required',$vm['pricing']['cost'],$vm['pricing']['suggested_price'],$vm['pricing']['current_price'],$vm['moq'],$vm['lead_time_days'],'draft',$userId,$now,$now]);$instanceId=(int)$this->db->lastInsertId();
        $s=$this->db->prepare('INSERT INTO cc_configuration_snapshots(permanent_id,configuration_instance_id,snapshot_type,snapshot_json,passport_hash,preset_id,created_by_legacy_user_id,created_at) VALUES(?,?,?,?,?,?,?,?)');
        $s->execute([$this->uuid(),$instanceId,'quote_draft',$json,$hash,$vm['preset_id']?:null,$userId,$now]);
        return ['instance_id'=>$instanceId,'snapshot_id'=>(int)$this->db->lastInsertId(),'snapshot_hash'=>$hash,'snapshot'=>$snapshot];
    }
    public function addToQuote(array $saved,float $quantity,int $userId): array
    {
        $vm=$saved['snapshot'];$now=date('Y-m-d H:i:s');$ownsTransaction=!$this->db->inTransaction();if($ownsTransaction)$this->db->beginTransaction();try{$no='CCQ-'.date('Ymd-His').'-'.random_int(100,999);
          $s=$this->db->prepare('INSERT INTO cc_quotes(quote_no,quote_type,currency,current_version,status,total_amount,total_cost,is_test,created_by_legacy_user_id,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,?)');$amount=$vm['pricing']['current_price']*$quantity;$cost=$vm['pricing']['cost']*$quantity;$s->execute([$no,$vm['product']['type'],'USD',1,'draft',$amount,$cost,0,$userId,$now,$now]);$quoteId=(int)$this->db->lastInsertId();
          $s=$this->db->prepare('INSERT INTO cc_quote_versions(quote_id,version_no,pricing_snapshot,cost_snapshot,exchange_rate,template_version,status,created_by_legacy_user_id,created_at) VALUES(?,?,?,?,?,?,?,?,?)');$s->execute([$quoteId,1,json_encode($vm['pricing']),json_encode(['cost'=>$vm['pricing']['cost']]),1,'legacy_v1','draft',$userId,$now]);$versionId=(int)$this->db->lastInsertId();
          $s=$this->db->prepare('INSERT INTO cc_quote_items(quote_version_id,item_type,legacy_product_id,inventory_sku_id,description,configuration_snapshot,quantity,unit_price,cost_amount,line_amount,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)');$s->execute([$versionId,$vm['product']['type'],$vm['product']['legacy_product_id']?:null,$vm['product']['inventory_sku_id']?:null,$vm['summary'],json_encode($vm,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),$quantity,$vm['pricing']['current_price'],$cost,$amount,$now,$now]);$itemId=(int)$this->db->lastInsertId();
          $s=$this->db->prepare('INSERT INTO cc_quote_item_snapshots(quote_item_id,product_snapshot,configuration_snapshot,price_snapshot,cost_snapshot,snapshot_hash,created_at) VALUES(?,?,?,?,?,?,?)');$s->execute([$itemId,json_encode($vm['product']),json_encode($vm),json_encode($vm['pricing']),json_encode(['cost'=>$vm['pricing']['cost']]),$saved['snapshot_hash'],$now]);if($ownsTransaction)$this->db->commit();return ['quote_id'=>$quoteId,'quote_no'=>$no,'quote_item_id'=>$itemId];
        }catch(\Throwable $e){if($ownsTransaction&&$this->db->inTransaction())$this->db->rollBack();throw $e;}
    }
    private function groupMap(): array{$rows=$this->all('SELECT id,group_code FROM cc_config_groups');$out=[];foreach($rows as $r)$out[$r['group_code']]=(int)$r['id'];return $out;}
    private function tableExists(string$table):bool{$s=$this->db->prepare('SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');$s->execute([$table]);return(bool)$s->fetchColumn();}
    private function all(string $sql,array $params=[]): array{$s=$this->db->prepare($sql);$s->execute($params);return $s->fetchAll(PDO::FETCH_ASSOC);}
    private function uuid(): string{$h=bin2hex(random_bytes(16));return substr($h,0,8).'-'.substr($h,8,4).'-4'.substr($h,13,3).'-a'.substr($h,17,3).'-'.substr($h,20,12);}
}
