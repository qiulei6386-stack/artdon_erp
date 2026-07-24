<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use Artdon\CommercialCenter\Repositories\ConfigurationRepository;
use Artdon\CommercialCenter\Services\ConfigurationEngineService;
function assert_true(bool $condition,string $message):void{if(!$condition){fwrite(STDERR,"FAIL: {$message}\n");exit(1);}}
$userId=(int)db()->query("SELECT id FROM crm_users WHERE status='active' ORDER BY id LIMIT 1")->fetchColumn();
$customerId=(int)db()->query("SELECT id FROM crm_customers ORDER BY id LIMIT 1")->fetchColumn();
$engine=new ConfigurationEngineService();$repo=new ConfigurationRepository();$catalog=$engine->catalog($userId,$customerId?:null);
assert_true(count($catalog['groups'])===16,'16 configuration groups expected');
$preset=[];foreach($catalog['presets'] as $p)$preset[$p['preset_type']]=$p;
foreach(['factory_standard','economy','standard','premium','channel'] as $type)assert_true(isset($preset[$type]),"preset {$type} missing");
assert_true(!empty($catalog['stock_skus']),'test stock SKU missing');assert_true(!empty($catalog['products']),'legacy standard product missing');
$stock=$catalog['stock_skus'][0];$stockVm=$engine->evaluate(['product_key'=>'stock:'.$stock['id'],'preset_id'=>$preset['factory_standard']['id'],'values'=>['power'=>'30w','cct'=>'5000k'],'mode'=>'quick'],$userId);
assert_true(($stockVm['values']['power']??'')==='10w','stock power must remain locked');assert_true(isset($stockVm['locks']['power']),'stock lock missing');
$product=$catalog['products'][0];$standardKey='standard:'.$product['id'];
$economy=$engine->evaluate(['product_key'=>$standardKey,'preset_id'=>$preset['economy']['id'],'values'=>[],'mode'=>'quick'],$userId);
$standard=$engine->evaluate(['product_key'=>$standardKey,'preset_id'=>$preset['standard']['id'],'values'=>['cct'=>'4000k','driver'=>'dali_driver','dimming'=>'dali','optics'=>'lens'],'mode'=>'professional'],$userId);
$multi=$engine->evaluate(['product_key'=>$standardKey,'preset_id'=>$preset['standard']['id'],'values'=>['certification'=>['ce','ul']],'mode'=>'professional'],$userId);
$premium=$engine->evaluate(['product_key'=>$standardKey,'preset_id'=>$preset['premium']['id'],'values'=>[],'mode'=>'professional'],$userId);
assert_true($economy['pricing']['suggested_price']<$premium['pricing']['suggested_price'],'A/B/C pricing order invalid');
assert_true(($standard['values']['cct']??'')==='4000k'&&($standard['values']['driver']??'')==='dali_driver'&&($standard['values']['optics']??'')==='lens','allowed edits not applied');
assert_true(($multi['values']['certification']??[])===['ce','ul']&&str_contains($multi['summary'],'CE、UL'),'multiple selection not applied');
assert_true($premium['status']==='approval','premium CRI95 should require approval');
$blocked=$engine->evaluate(['product_key'=>$standardKey,'preset_id'=>$preset['standard']['id'],'values'=>['dimming'=>'dali','driver'=>'standard_driver'],'mode'=>'professional'],$userId);
assert_true($blocked['status']==='blocked','incompatible DALI configuration must be blocked');
$warning=$engine->evaluate(['product_key'=>$standardKey,'preset_id'=>$preset['standard']['id'],'values'=>['color'=>'custom'],'mode'=>'custom'],$userId);
assert_true(in_array($warning['status'],['warning','approval'],true)&&$warning['moq']>=100,'custom color warning/MOQ missing');
assert_true(strlen($standard['passport_hash'])===64&&$standard['summary']!=='','configuration passport invalid');
$materialMappings=(int)db()->query('SELECT COUNT(*) FROM cc_option_material_mappings')->fetchColumn();assert_true($materialMappings>=4,'option/material mappings missing');
db()->beginTransaction();
try{
 $personalId=$repo->savePreset(['scope'=>'personal','name'=>'TEST 个人预设','legacy_product_id'=>$product['id'],'values'=>$standard['values']],$userId,null);
 assert_true($personalId>0,'personal preset save failed');
 if($customerId>0){$customerPreset=$repo->savePreset(['scope'=>'customer','name'=>'TEST 客户预设','legacy_product_id'=>$product['id'],'values'=>$standard['values']],$userId,$customerId);assert_true($customerPreset>0,'customer preset save failed');}
 $saved=$repo->saveConfiguration($standard,$userId);$quote=$repo->addToQuote($saved,2,$userId);
 assert_true($saved['snapshot_id']>0&&$quote['quote_id']>0,'configuration-to-quote persistence failed');
 $snapshot=(string)db()->query('SELECT configuration_snapshot FROM cc_quote_items WHERE id='.(int)$quote['quote_item_id'])->fetchColumn();
 assert_true(str_contains($snapshot,$standard['passport_hash']),'quote configuration snapshot mismatch');
 db()->rollBack();
}catch(Throwable $e){if(db()->inTransaction())db()->rollBack();throw $e;}
echo "PASS: configuration presets, locks, compatibility, passport, material mapping and quote snapshot verified.\n";
