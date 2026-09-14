<?php
require_once __DIR__.'/includes/artdon_sso_core.php';
artdon_sso_require_page('quote');
if(!artdon_sso_can_feature('quote','export'))artdon_sso_forbidden_page('quote');
require_once __DIR__.'/includes/bootstrap.php';
header('Cache-Control: private, no-store');
$pdo=db();$id=(int)($_GET['id']??0);$version=(int)($_GET['version']??0);
$stmt=$pdo->prepare('SELECT id,state,version,data_json FROM quote_shipment_plans WHERE id=?');$stmt->execute([$id]);$plan=$stmt->fetch(PDO::FETCH_ASSOC);
if(!$plan){http_response_code(404);exit('出货批次不存在');}
$issued=null;
if($version>0){$stmt=$pdo->prepare('SELECT version,data_json,actor,created_at FROM quote_shipment_plan_documents WHERE plan_id=? AND version=?');$stmt->execute([$id,$version]);$issued=$stmt->fetch(PDO::FETCH_ASSOC);if(!$issued){http_response_code(404);exit('单证版本不存在');}}
$doc=['id'=>$id,'state'=>$plan['state'],'current_version'=>(int)$plan['version'],'version'=>$version?:$plan['version'],'issued'=>(bool)$issued,'actor'=>$issued['actor']??'','created_at'=>$issued['created_at']??'','data'=>json_decode($issued['data_json']??$plan['data_json'],true)];
?><!doctype html><html lang="zh"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>出货单证</title></head><body>
<script>var openCombinedShipmentModal,openShipmentModal;</script>
<script src="assets/quote-shipment-batch.js?v=20260914-1"></script>
<script>document.open();document.write(QuoteShipmentBatch.documentHtml(<?=json_encode($doc,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)?>));document.close();</script>
</body></html>
