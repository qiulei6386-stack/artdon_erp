<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
// Synthetic render only. No application database or network access.
$root=dirname(__DIR__);
$manual="1. Customer lamp\n2. Optic: Opal diffuser <img src=x onerror=alert(1)>";
$item=['product'=>['code'=>'SYNTHETIC','name'=>'Automatic lamp','quote_spec'=>['optic'=>['label'=>'Optic','value'=>'SOURCE_OPTIC']], 'quote_spec_display_override'=>$manual],'specification'=>'STALE_SAVED_SPEC','qty'=>2,'price'=>30,'amount'=>60];
$_POST['payload']=json_encode(['order_export'=>1,'quote_no'=>'QA-DISPLAY','currency'=>'USD','header'=>['company'=>'Acceptance'],'items'=>[$item],'total'=>['qty'=>2,'amount'=>60]],JSON_THROW_ON_ERROR);
ob_start();include $root.'/crm_quote_pdf.php';$html=ob_get_clean();
function qdt_check($ok,$msg){if(!$ok)throw new RuntimeException($msg);}
qdt_check(strpos($html,'Opal diffuser &lt;img')!==false,'PDF output escapes manually entered HTML');
qdt_check(strpos($html,'SOURCE_OPTIC')===false&&strpos($html,'STALE_SAVED_SPEC')===false,'PDF respects manual wording');
$excel=file_get_contents($root.'/crm_quote_excel.php');$start=strpos($excel,'function qe_json_decode(');$end=strpos($excel,'$payload=qe_apply_approved_snapshot(qe_get_payload());');
qdt_check($start!==false&&$end>$start,'Excel function boundaries');
eval(str_replace('function qe_zip_files(','function qdt_original_zip_files(',substr($excel,$start,$end-$start)));
function qe_zip_files($files){$GLOBALS['qdt_xml']=$files;return 'captured';}
foreach(json_decode(file_get_contents(__DIR__.'/fixtures/quote-display-parameters.json'),true,512,JSON_THROW_ON_ERROR) as $fixture){
    $before=json_encode($fixture['item']);
    qdt_check(build_spec($fixture['item'])===$fixture['expected'],'PDF live parameters: '.$fixture['name']);
    qdt_check(qe_item_spec($fixture['item'])===$fixture['expected'],'Excel live parameters: '.$fixture['name']);
    qdt_check(json_encode($fixture['item'])===$before,'export does not mutate saved source');
    qe_build_xlsx(['quote_no'=>'QA-LIVE','currency'=>'USD','items'=>[array_merge($fixture['item'],['qty'=>10,'price'=>15,'amount'=>150])],'total'=>['qty'=>10,'amount'=>150]]);
    foreach(explode("\n",$fixture['expected']) as $line)qdt_check(strpos($GLOBALS['qdt_xml']['xl/worksheets/sheet1.xml'],qe_xml($line))!==false,'actual Excel XML live parameter');
}
foreach([$manual,'','中文自定义说明','0'] as $text){
    $item['product']['quote_spec_display_override']=$text;
    $roundtrip=json_decode(json_encode($item,JSON_THROW_ON_ERROR),true,512,JSON_THROW_ON_ERROR);
    qdt_check(build_spec($roundtrip)===$text,'PDF preserves explicit text including empty');
    qdt_check(qe_item_spec($roundtrip)===$text,'Excel preserves explicit text including empty');
}
$item['product']['quote_spec_display_override']=$manual;
foreach(['Changed PI wording',''] as $orderText){
    $orderItem=$item;$orderItem['is_order_snapshot']=true;$orderItem['specification']=$orderText;
    qdt_check(build_spec($orderItem)===$orderText&&qe_item_spec($orderItem)===$orderText,'later order wording wins over source quotation override');
}
qe_build_xlsx(['quote_no'=>'QA-DISPLAY','currency'=>'USD','items'=>[$item],'total'=>['qty'=>2,'amount'=>60]]);
$sheet=$GLOBALS['qdt_xml']['xl/worksheets/sheet1.xml'];
qdt_check(strpos($sheet,'Opal diffuser &lt;img')!==false&&strpos($sheet,'SOURCE_OPTIC')===false,'actual XLSX XML uses escaped manual text');
unset($item['product']['quote_spec_display_override']);
qdt_check(strpos(build_spec($item),'STALE_SAVED_SPEC')!==false,'legacy PDF fallback unchanged');
qdt_check(strpos(qe_item_spec($item),'STALE_SAVED_SPEC')!==false,'legacy Excel fallback unchanged');
echo "Quote display exports: manual/empty/Chinese/zero, JSON reload, PDF HTML escaping and Excel XML, legacy fallback passed\n";
