<?php
// Synthetic, offline regressions for the seven reported issues. No app bootstrap.
if (PHP_SAPI !== 'cli') exit(2);
function i7_check($ok,$message){if(!$ok)throw new RuntimeException($message);}
function i7_function($file,$name){
    $source=file_get_contents(dirname(__DIR__).'/'.$file);$start=strpos($source,'function '.$name.'(');
    if($start===false)throw new RuntimeException('Missing '.$name);
    $end=strpos($source,"\nfunction ",$start+1);
    if($end===false)throw new RuntimeException('Missing next function boundary: '.$name);
    eval(substr($source,$start,$end-$start));
}
require_once dirname(__DIR__).'/crm_mail.php';
$bodies=[
    '<p>OWN REPLY START</p><p>New packing details.</p><div>Signature</div><blockquote><meta http-equiv="Content-Type" content="text/html; charset=UTF-8">'."\n\n".'<p>OLD QUOTE ONLY</p></blockquote>',
    '<HTML><meta http-equiv="content-type" content="text/html;charset="UTF-8"" /><BODY><div>FIRST PARAGRAPH</div>'."\n\n".'<div>Second paragraph</div><div>Signature</div></BODY></HTML>',
    '<p>Content-Type: explained here</p><a href="https://example.invalid/?code=20&value=AB">Link =20 literal</a>',
];
foreach($bodies as $body){
    $fixed=crm_mail_repair_stored_body(['body_html'=>$body,'body_text'=>'Full cached body']);
    i7_check($fixed['body_html']===$body,'Decoded HTML must remain complete and unchanged');
    i7_check(!crm_mail_has_mime_header_block($body),'HTML is not MIME');
}
$raw="MIME-Version: 1.0\r\nContent-Type: text/html;\r\n charset=UTF-8\r\nContent-Transfer-Encoding: quoted-printable\r\n\r\n<p>Hello=20world =3D20</p>";
i7_check(crm_mail_has_mime_header_block($raw),'Actual MIME supported');
$fixed=crm_mail_repair_stored_body(['body_html'=>$raw]);
i7_check(strpos($fixed['body_html'],'Hello world =20')!==false,'Decode transfer encoding exactly once');
i7_check(!crm_mail_has_mime_header_block("Personal introduction\nContent-Type: text/html\n\nDetails"),'Prose headers cannot eat introduction');
$plain=crm_mail_repair_stored_body(['body_html'=>'','body_text'=>'Literal code=AB and value=20']);
i7_check($plain['body_text']==='Literal code=AB and value=20','Plain business text preserved');
$path=tempnam(sys_get_temp_dir(),'i7-pdf-');
try{
    file_put_contents($path,"%PDF-1.4\nSynthetic PDF header\n%%EOF");
    $attachment=['path'=>$path,'file_name'=>'example.pdf','mime_type'=>'application/octet-stream','file_size'=>filesize($path)];
    i7_check(crm_mail_preview_file($attachment,'offline')['mime_type']==='application/pdf','Generic MIME PDF displays inline');
    file_put_contents($path,'<html>Not a PDF</html>');
    try{crm_mail_preview_file($attachment,'offline');throw new LogicException('Invalid PDF accepted');}catch(RuntimeException $e){}
    $attachment['file_name']='example.html';$attachment['mime_type']='text/html';
    i7_check(crm_mail_preview_file($attachment,'offline')['mime_type']==='text/plain','HTML attachment not executable');
}finally{unlink($path);}
foreach(['qo_num','qo_carton_count_value','qo_has_carton_detail','qo_carton_detail_totals','qo_add_carton_detail_totals','qo_requested_order_ids'] as $fn)i7_function('quote_order_api.php',$fn);
foreach(['qd_s','qd_num','qd_total','qd_carton_has_detail','qd_carton_count','qd_carton_count_total','qd_packing_total','qd_carton_pl_rows'] as $fn)i7_function('quote_order_doc.php',$fn);
$items=[['product_code'=>'Chrome black A','qty'=>4],['product_code'=>'White trim B','qty'=>4],['product_code'=>'Chrome black C','qty'=>4]];
$cartons=[['qty'=>12,'carton_count'=>1,'items_text'=>'Chrome black A 4PCS + White trim B 4PCS + Chrome black C 4PCS','nw'=>0.4,'gw'=>0.6,'cbm'=>0.0069]];
$tot=qo_add_carton_detail_totals(['qty'=>12],$cartons);
i7_check($tot['qty']===12 && $tot['cartons']==1 && $tot['gw']==0.6,'12 products / one mixed carton, no double counting');
$packing=qd_carton_pl_rows($cartons,$items);$pl=array_merge($items,$packing);
i7_check(qd_total($pl,'qty')==12 && qd_total($pl,'cartons')==1,'PL/Excel totals agree with shipment');
i7_check($packing[0]['product_code']==='' && strpos($packing[0]['specification'],$cartons[0]['items_text'])!==false,'Do not guess Chrome as a model; retain complete carton description');
i7_check(qd_packing_total($items,$cartons,'qty')==12,'Legacy summary uses same quantity');
i7_check(qo_add_carton_detail_totals(['qty'=>24,'cartons'=>1],[['qty'=>12,'carton_count'=>1]])['cartons']==2,'Normal + mixed packing counts');
try{qo_add_carton_detail_totals(['qty'=>4],$cartons);throw new LogicException('Excess carton contents accepted');}catch(RuntimeException $e){}
i7_check(qo_requested_order_ids(['order_ids'=>[2,2]],1)===[2],'Explicit selection excludes fallback order');
i7_check(qo_requested_order_ids(['order_ids'=>[]],1)===[],'Empty selection does not silently include order');
$customer=file_get_contents(dirname(__DIR__).'/crm_customer.php');
$base=substr($customer,strpos($customer,"    \$base = [",strpos($customer,'function crm_customer_get(')));
i7_check(strpos($base,"'chat_groups' => crm_customer_chat_groups(\$id)")<strpos($base,'if ($light)'), 'Overview must load existing chat groups before archive rendering');
echo "Seven-issue offline checks passed: packing quantity/description, explicit order scope, chat groups, PDF MIME, both mail body shapes.\n";
