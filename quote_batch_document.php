<?php
require_once __DIR__.'/includes/artdon_sso_core.php';
artdon_sso_require_page('quote');
if(!artdon_sso_can_feature('quote','export'))artdon_sso_forbidden_page('quote');
require_once __DIR__.'/includes/bootstrap.php';
require_once __DIR__.'/includes/quote_shipment_document.php';
header('Cache-Control: private, no-store');
$pdo=db();$id=(int)($_GET['id']??0);$version=(int)($_GET['version']??0);
$stmt=$pdo->prepare('SELECT id,state,version,data_json FROM quote_shipment_plans WHERE id=?');$stmt->execute([$id]);$plan=$stmt->fetch(PDO::FETCH_ASSOC);
if(!$plan){http_response_code(404);exit('出货批次不存在');}
$issued=null;
if($version>0){$stmt=$pdo->prepare('SELECT version,data_json,actor,created_at FROM quote_shipment_plan_documents WHERE plan_id=? AND version=?');$stmt->execute([$id,$version]);$issued=$stmt->fetch(PDO::FETCH_ASSOC);if(!$issued){http_response_code(404);exit('单证版本不存在');}}
$doc=['id'=>$id,'state'=>$plan['state'],'current_version'=>(int)$plan['version'],'version'=>$version?:$plan['version'],'issued'=>(bool)$issued,'actor'=>$issued['actor']??'','created_at'=>$issued['created_at']??'','data'=>json_decode($issued['data_json']??$plan['data_json'],true)];
$type=($_GET['type']??'pl')==='ci'?'ci':'pl';$format=strtolower((string)($_GET['format']??'html'));
if(session_status()===PHP_SESSION_ACTIVE)session_write_close();
try{
    if(in_array($format,['xls','xlsx','excel'],true)){header('Content-Type: application/vnd.ms-excel; charset=utf-8');header('Content-Disposition: attachment; filename="'.qsd_title($doc,$type).'.xls"');echo qsd_excel($doc,$type);exit;}
    $html=qsd_html($doc,$type);
    if($format==='pdf'){qsd_download_pdf($pdo,$html,qsd_title($doc,$type));exit;}
    $base='quote_batch_document.php?id='.$id.'&version='.$version.'&type='.$type;
    $other='quote_batch_document.php?id='.$id.'&version='.$version.'&type='.($type==='pl'?'ci':'pl');
    $bar='<div class="toolbar"><a href="'.qsd_h($other).'">切换 '.($type==='pl'?'CI 商业发票':'PL 装箱单').'</a><a href="'.qsd_h($base.'&format=pdf').'">下载 PDF</a><a href="'.qsd_h($base.'&format=xls').'">下载 Excel</a><button onclick="window.print()">打印 / 保存PDF</button></div>';
    $bar=preg_replace('/<a href="([^\"]*&amp;format=(?:pdf|xls))">/','<a download href="$1">',$bar);
    echo str_replace('<body>','<body>'.$bar,$html);
}catch(Throwable $e){http_response_code(503);header('Content-Type: text/html; charset=utf-8');echo '<meta charset="utf-8"><p>单证生成失败：'.qsd_h($e->getMessage()).'</p><button onclick="history.back()">返回重试</button>';}
