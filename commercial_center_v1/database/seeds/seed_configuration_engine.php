<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'||($argv[1]??'')!=='--apply'){fwrite(STDERR,"Usage: php database/seeds/seed_configuration_engine.php --apply\n");exit(2);}
$root=dirname(__DIR__,3);$config=require $root.'/includes/config.php';$d=$config['db'];
if(($d['name']??'')!=='artdon_new_erp'){fwrite(STDERR,"Unexpected database.\n");exit(1);}
$pdo=new PDO('mysql:host='.$d['host'].';port='.($d['port']??3306).';dbname='.$d['name'].';charset=utf8mb4',$d['user'],$d['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$now=date('Y-m-d H:i:s');$uuid=static fn(string $key):string=>substr(hash('sha256','cc-config-v1:'.$key),0,8).'-'.substr(hash('sha256',$key),8,4).'-4'.substr(hash('sha256',$key),13,3).'-a'.substr(hash('sha256',$key),17,3).'-'.substr(hash('sha256',$key),20,12);
$groups=[
 'power'=>['功率','single',1,0,1,1,1,1,0],'cct'=>['色温','single',1,0,1,1,0,1,0],'cri'=>['显指','single',1,0,1,1,0,1,0],
 'beam_angle'=>['光束角','single',1,0,1,1,0,0,0],'chip'=>['芯片','single',1,1,1,1,0,1,0],'driver'=>['电源','single',1,1,1,1,0,1,0],
 'dimming'=>['调光','single',1,0,1,1,0,1,0],'optics'=>['光学','single',1,0,1,1,0,0,0],'honeycomb'=>['蜂巢网','single',0,0,1,1,0,0,0],
 'glass'=>['玻璃','single',0,0,1,1,0,0,0],'anti_glare'=>['防眩圈','single',0,1,1,1,0,0,0],'color'=>['颜色','single',1,0,1,1,1,1,1],
 'connector'=>['接头','single',0,1,1,1,0,1,1],'packaging'=>['包装','single',1,1,1,1,1,1,1],'label'=>['标签','text',0,1,1,0,1,1,1],
 'certification'=>['认证','multiple',0,1,1,1,1,1,1],
];
$options=[
 'power'=>[['10w','10W',0,0,1,0],['20w','20W',4,8,10,2],['30w','30W',8,15,20,4]],
 'cct'=>[['2700k','2700K',1,2,5,2],['3000k','3000K',0,0,1,0],['4000k','4000K',0,0,1,0],['5000k','5000K',1,2,5,2]],
 'cri'=>[['cri_80','CRI 80',0,0,1,0],['cri_90','CRI 90',2,5,10,3],['cri_95','CRI 95',5,12,50,7]],
 'beam_angle'=>[['15d','15°',0,0,1,0],['24d','24°',0,0,1,0],['36d','36°',0,0,1,0],['60d','60°',1,2,5,2]],
 'chip'=>[['standard_chip','标准芯片',0,0,1,0],['premium_chip','高配芯片',4,10,10,3]],
 'driver'=>[['standard_driver','标准电源',0,0,1,0],['dali_driver','DALI电源',12,25,10,5],['philips_driver','Philips电源',15,30,20,7]],
 'dimming'=>[['non_dim','不调光',0,0,1,0],['dali','DALI',2,5,10,3],['zero_ten','0-10V',2,5,10,3]],
 'optics'=>[['reflector','反光杯',0,0,1,0],['lens','透镜',2,5,5,2]],'honeycomb'=>[['none','无',0,0,1,0],['with_honeycomb','蜂巢网',2,5,5,2]],
 'glass'=>[['none','无',0,0,1,0],['clear','透明玻璃',1,3,5,2],['frosted','柔光玻璃',2,5,10,3]],'anti_glare'=>[['none','无',0,0,1,0],['black_ring','黑色防眩圈',1,3,5,2]],
 'color'=>[['black','黑色',0,0,1,0],['white','白色',0,0,1,0],['custom','客户定制色',8,20,100,10]],
 'connector'=>[['standard','标准接头',0,0,1,0],['custom','特殊接头',3,8,50,7]],'packaging'=>[['standard','标准包装',0,0,1,0],['export','出口加强包装',2,5,10,2]],
 'certification'=>[['none','无',0,0,1,0],['ce','CE',3,8,50,7],['ul','UL',8,20,100,14]],
];
try{$pdo->beginTransaction();
 $s=$pdo->prepare('INSERT IGNORE INTO cc_config_templates(permanent_id,template_code,name,product_type,status,current_version,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?)');
 $s->execute([$uuid('template-standard'),'factory-standard-v1','工厂标准配置模板','standard','active',1,$now,$now]);
 $templateId=(int)$pdo->query("SELECT id FROM cc_config_templates WHERE template_code='factory-standard-v1'")->fetchColumn();
 $schemaHash=hash('sha256',json_encode(array_keys($groups)));$s=$pdo->prepare('INSERT IGNORE INTO cc_config_template_versions(template_id,version_no,status,change_note,schema_hash,created_at) VALUES(?,?,?,?,?,?)');$s->execute([$templateId,1,'active','配置引擎V1基础版本',$schemaHash,$now]);
 $versionId=(int)$pdo->query('SELECT id FROM cc_config_template_versions WHERE template_id='.$templateId.' AND version_no=1')->fetchColumn();
 $groupIds=[];$sort=10;
 foreach($groups as $code=>$g){$s=$pdo->prepare('INSERT IGNORE INTO cc_config_groups(permanent_id,group_code,name,is_required,is_multiple,sort_order,status,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?)');$s->execute([$uuid('group-'.$code),$code,$g[0],$g[2],$g[1]==='multiple'?1:0,$sort,'active',$now,$now]);$gid=(int)$pdo->query("SELECT id FROM cc_config_groups WHERE group_code=".$pdo->quote($code))->fetchColumn();$groupIds[$code]=$gid;
   $allowCustom=in_array($code,['color','connector','packaging','label','certification'],true)?1:0;
   $s=$pdo->prepare('INSERT INTO cc_config_group_settings(group_id,input_type,is_advanced,customer_visible,affects_cost,affects_price,affects_moq,affects_lead_time,allow_custom,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE input_type=VALUES(input_type),is_advanced=VALUES(is_advanced),customer_visible=VALUES(customer_visible),affects_cost=VALUES(affects_cost),affects_price=VALUES(affects_price),affects_moq=VALUES(affects_moq),affects_lead_time=VALUES(affects_lead_time),allow_custom=VALUES(allow_custom),updated_at=VALUES(updated_at)');$s->execute([$gid,$g[1],$g[3],$g[4],$g[5],$g[6],$g[7],$g[8],$allowCustom,$now,$now]);
   $s=$pdo->prepare('INSERT IGNORE INTO cc_config_template_groups(template_version_id,group_id,sort_order,status,created_at,updated_at) VALUES(?,?,?,?,?,?)');$s->execute([$versionId,$gid,$sort,'active',$now,$now]);$sort+=10;
   foreach($options[$code]??[] as $o){$s=$pdo->prepare('INSERT IGNORE INTO cc_config_options(group_id,permanent_id,option_code,name,is_default,cost_delta,sales_delta,moq_delta,lead_time_days,status,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)');$s->execute([$gid,$uuid('option-'.$code.'-'.$o[0]),$o[0],$o[1],0,$o[2],$o[3],$o[4]-1,$o[5],'active',$now,$now]);}
 }
 $presets=['factory'=>['工厂标准预设','factory_standard',['power'=>'10w','cct'=>'3000k','cri'=>'cri_80','beam_angle'=>'24d','chip'=>'standard_chip','driver'=>'standard_driver','dimming'=>'non_dim','optics'=>'reflector','honeycomb'=>'none','glass'=>'none','anti_glare'=>'none','color'=>'black','connector'=>'standard','packaging'=>'standard']],
 'economy'=>['经济版','economy',['power'=>'10w','cct'=>'3000k','cri'=>'cri_80','beam_angle'=>'36d','chip'=>'standard_chip','driver'=>'standard_driver','dimming'=>'non_dim','optics'=>'reflector','color'=>'black','packaging'=>'standard']],
 'standard'=>['标准版','standard',['power'=>'20w','cct'=>'3000k','cri'=>'cri_90','beam_angle'=>'24d','chip'=>'standard_chip','driver'=>'standard_driver','dimming'=>'non_dim','optics'=>'reflector','color'=>'black','packaging'=>'standard']],
 'premium'=>['高配版','premium',['power'=>'20w','cct'=>'3000k','cri'=>'cri_95','beam_angle'=>'24d','chip'=>'premium_chip','driver'=>'dali_driver','dimming'=>'dali','optics'=>'lens','color'=>'black','packaging'=>'export']],
 'singapore'=>['新加坡渠道预设','channel',['power'=>'10w','cct'=>'3000k','cri'=>'cri_90','beam_angle'=>'24d','chip'=>'standard_chip','driver'=>'standard_driver','dimming'=>'non_dim','optics'=>'reflector','color'=>'black','packaging'=>'export']]];
 foreach($presets as $code=>$p){$s=$pdo->prepare('INSERT IGNORE INTO cc_config_presets(permanent_id,preset_code,name,preset_type,scope_type,template_id,channel_code,version_no,status,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,?)');$s->execute([$uuid('preset-'.$code),'preset-'.$code,$p[0],$p[1],$code==='singapore'?'channel':'global',$templateId,$code==='singapore'?'singapore_web':null,1,'active',$now,$now]);$pid=(int)$pdo->query("SELECT id FROM cc_config_presets WHERE preset_code=".$pdo->quote('preset-'.$code))->fetchColumn();foreach($p[2] as $g=>$v){if(!isset($groupIds[$g]))continue;$s=$pdo->prepare('INSERT INTO cc_config_preset_values(preset_id,group_id,value_json,is_locked,created_at,updated_at) VALUES(?,?,?,?,?,?) ON DUPLICATE KEY UPDATE value_json=VALUES(value_json),updated_at=VALUES(updated_at)');$s->execute([$pid,$groupIds[$g],json_encode($v),0,$now,$now]);}}
 $rules=[['dali_driver_required','DALI调光必须使用DALI电源','forbid',['dimming'=>'dali'],['driver'=>'dali_driver']],['cri95_approval','CRI95需要审批','approval',['cri'=>'cri_95'],[]],['custom_color_warning','定制颜色影响MOQ和交期','warning',['color'=>'custom'],[]]];
 foreach($rules as $r){$s=$pdo->prepare('INSERT IGNORE INTO cc_compatibility_rules(permanent_id,rule_code,name,rule_type,condition_json,effect_json,status,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?)');$s->execute([$uuid('rule-'.$r[0]),$r[0],$r[1],$r[2],json_encode($r[3]),json_encode($r[4]),'active',$now,$now]);}
 $materials=[['TEST-MAT-DRIVER-STD','电源','Demo','STD-DRIVER','标准电源测试物料',12],['TEST-MAT-DRIVER-DALI','电源','Demo','DALI-DRIVER','DALI电源测试物料',24],['TEST-MAT-OPTIC-LENS','光学','Demo','LENS-24','透镜测试物料',3]];
 foreach($materials as $m){$s=$pdo->prepare('INSERT IGNORE INTO cc_materials(permanent_id,material_code,category,brand,model,specification,unit,procurement_price,currency,status,quote_allowed,public_allowed,is_test,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');$s->execute([$uuid('material-'.$m[0]),$m[0],$m[1],$m[2],$m[3],$m[4],'PCS',$m[5],'CNY','active',1,0,1,$now,$now]);}
 $maps=[['standard_driver','TEST-MAT-DRIVER-STD','default'],['dali_driver','TEST-MAT-DRIVER-DALI','default'],['philips_driver','TEST-MAT-DRIVER-DALI','alternative'],['lens','TEST-MAT-OPTIC-LENS','default']];
 foreach($maps as $m){$s=$pdo->prepare('INSERT IGNORE INTO cc_option_material_mappings(option_id,material_id,mapping_type,cost_delta,priority,status,created_at,updated_at) SELECT o.id,x.id,?,0,100,?,?,? FROM cc_config_options o JOIN cc_materials x ON x.material_code=? WHERE o.option_code=?');$s->execute([$m[2],'active',$now,$now,$m[1],$m[0]]);}
 $legacyId=(int)$pdo->query('SELECT id FROM naming_models WHERE website_deleted=0 ORDER BY id LIMIT 1')->fetchColumn();
 if($legacyId>0){$snapshot=json_encode(['power'=>'10w','cct'=>'3000k','cri'=>'cri_80','beam_angle'=>'24d','chip'=>'standard_chip','driver'=>'standard_driver','dimming'=>'non_dim','optics'=>'reflector','color'=>'black','packaging'=>'standard']);$s=$pdo->prepare('INSERT IGNORE INTO cc_inventory_skus(permanent_id,legacy_product_id,sku_code,product_type,configuration_snapshot,actual_stock,reserved_stock,safety_stock,sellable_stock,publishable,status,is_test,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?)');$s->execute([$uuid('test-stock-sku'),$legacyId,'TEST-CONFIG-SKU-001','stock',$snapshot,20,0,2,18,0,'active',1,$now,$now]);
   $skuId=(int)$pdo->query("SELECT id FROM cc_inventory_skus WHERE sku_code='TEST-CONFIG-SKU-001'")->fetchColumn();
   $s=$pdo->prepare('INSERT IGNORE INTO cc_product_config_templates(legacy_product_id,template_id,product_type,is_default,status,created_at,updated_at) VALUES(?,?,?,?,?,?,?)');$s->execute([$legacyId,$templateId,'standard',1,'active',$now,$now]);
   $s=$pdo->prepare('INSERT IGNORE INTO cc_product_allowed_options(legacy_product_id,group_id,option_id,custom_allowed,status,created_at,updated_at) SELECT ?,o.group_id,o.id,0,?,?,? FROM cc_config_options o WHERE o.status=?');$s->execute([$legacyId,'active',$now,$now,'active']);
   foreach(array_keys(json_decode($snapshot,true)) as $code){$gid=$groupIds[$code]??0;if(!$gid)continue;$s=$pdo->prepare('INSERT IGNORE INTO cc_config_lock_rules(permanent_id,rule_code,name,lock_type,scope_type,inventory_sku_id,group_id,priority,status,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,?)');$s->execute([$uuid('stock-lock-'.$code),'stock-lock-'.$code,'库存SKU锁定：'.$groups[$code][0],'hard','inventory_sku',$skuId,$gid,10,'active',$now,$now]);}
 }
 $pdo->commit();echo "Configuration reference data applied; test SKU is_test=1.\n";
}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();fwrite(STDERR,$e->getMessage()."\n");exit(1);}
