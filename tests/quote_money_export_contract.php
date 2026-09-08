<?php
// Exercise the real Excel builder without file/network output; capture its XML package.
declare(strict_types=1);
$root=dirname(__DIR__);$excel=file_get_contents($root.'/crm_quote_excel.php');
$start=strpos($excel,'function qe_json_decode(');$end=strpos($excel,'$payload=qe_apply_approved_snapshot(qe_get_payload());');
if($start===false||$end===false)throw new RuntimeException('Excel function boundaries missing');
eval(str_replace('function qe_zip_files(','function mq_original_zip_files(',substr($excel,$start,$end-$start)));
function qe_zip_files($files){$GLOBALS['mq_xml']=$files;return 'captured';}
function mq_assert($ok,$message){if(!$ok)throw new RuntimeException($message);}
$p=['quote_no'=>'SYNTHETIC','currency'=>'RMB','quote_date'=>'2026-09-08','items'=>[['product'=>['code'=>'TEST','name'=>'Test'],'qty'=>3,'price'=>0.335,'amount'=>1.01]],'total'=>['qty'=>3,'amount'=>1.01],'subtotal_amount'=>1.01,'adjustment_amount'=>0];
qe_build_xlsx($p);$sheet=$GLOBALS['mq_xml']['xl/worksheets/sheet1.xml'];$styles=$GLOBALS['mq_xml']['xl/styles.xml'];
mq_assert(strpos($sheet,'<v>0.335</v>')!==false,'precise unit price');
mq_assert(preg_match('/<f>ROUND\(G[0-9]+\*H[0-9]+,2\)<\/f><v>1.01<\/v>/',$sheet)===1,'rounded formula and cached row agree');
mq_assert(strpos($styles,'formatCode="#,##0.00##"')!==false,'visible price precision');
$p['items'][0]['price']=100;$p['items'][0]['qty']=1;$p['items'][0]['amount']=100;$p['subtotal_amount']=100;$p['adjustment_amount']=-100;$p['quote_adjustment']=['type'=>'discount_amount','value'=>100];$p['total']=['qty'=>1,'amount'=>0];
qe_build_xlsx($p);$sheet=$GLOBALS['mq_xml']['xl/worksheets/sheet1.xml'];
mq_assert(preg_match('/<f>I[0-9]+\+I[0-9]+<\/f><v>0<\/v>/',$sheet)===1,'100% discount final zero retained');
$pdf=file_get_contents($root.'/crm_quote_pdf.php');$start=strpos($pdf,'$totalQty = 0; $totalAmount = 0;');$end=strpos($pdf,'$company = s_trim',$start);
function num($v){return (float)$v;}
function quote_pdf_item_qty_for_total($it){return (float)$it['qty'];}
function item_amount($it){return (float)$it['amount'];}
function quote_pdf_has_adjustment_item($items){return false;}
$payload=$p;$items=$p['items'];eval(substr($pdf,$start,$end-$start));
mq_assert($totalAmount===0.0 && $subtotalAmount===100.0,'PDF zero and subtotal retained');
mq_assert(strpos($pdf,'qm_validate_snapshot($snap)')!==false&&strpos($excel,'qm_validate_snapshot($snap)')!==false,'both exports validate snapshot');
echo "Quote export contract: actual Excel XML, price precision, row formula, full discount zero, PDF totals and snapshot guards passed\n";
