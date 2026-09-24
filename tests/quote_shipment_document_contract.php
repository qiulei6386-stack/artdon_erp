<?php
if(PHP_SAPI!=='cli')exit(2);
require_once dirname(__DIR__).'/includes/quote_shipment_document.php';
function sd_check($ok,$label){if(!$ok)throw new RuntimeException($label);}
// Our exporter emits uncompressed ZIP entries; inspect without optional extensions.
function sd_entries(string $zip): array {
 $files=[];$at=0;while(substr($zip,$at,4)==="PK\x03\x04"){
  $h=unpack('vversion/vflag/vmethod/vtime/vdate/Vcrc/Vcompressed/Vsize/vname/vextra',substr($zip,$at+4,26));
  sd_check($h['method']===0,'Fixture ZIP method');$name=substr($zip,$at+30,$h['name']);$at+=30+$h['name']+$h['extra'];
  $files[$name]=substr($zip,$at,$h['compressed']);$at+=$h['compressed'];
 }return $files;
}
$data=['seller_name'=>'ARTDON · 验收测试','customer_name'=>'Acceptance customer','seller_text'=>'Synthetic fixture — not a business document','consignee'=>'验收测试客户 / Acceptance only','ship_date'=>'2026-09-24','currency'=>'USD','ship_method'=>'Sea','port_loading'=>'Guangzhou','port_destination'=>'Test port','shipping_mark'=>'TEST ONLY','note'=>'合成测试，不代表实际出货','bank_text'=>'BANK MUST NOT APPEAR','items'=>[],'cartons'=>[]];
for($i=1;$i<=28;$i++){$data['items'][]=['order_item_id'=>$i,'order_no'=>'AT-TEST-'.($i%3+1),'product_code'=>'MODEL-'.$i,'customer_code'=>'CLIENT-'.$i,'size'=>'55x140','hs_code'=>'940542','product_name'=>'Test luminaire','specification'=>'Acceptance lighting / 3000K / long specification / 合成规格','qty'=>3,'unit_price'=>2.5,'amount'=>7.5];$data['cartons'][]=['carton_no'=>(string)$i,'carton_count'=>1,'carton_size'=>'40x36x30','nw'=>2,'gw'=>3,'cbm'=>.0432,'items'=>[['order_item_id'=>$i,'qty'=>3]]];}
$doc=['id'=>999,'state'=>'planning','version'=>2,'current_version'=>3,'issued'=>true,'data'=>$data];
$ci=qsd_html($doc,'ci');$pl=qsd_html($doc,'pl');
sd_check(strpos($ci,'USD 210.00')!==false,'CI exact selected quantity and amount');
sd_check(strpos($pl,'28 CTNS')!==false,'PL carton total');
foreach(['Picture','Order No.','Manufacturer','Customer','HS Code','Size','940542','CLIENT-1'] as $label)sd_check(strpos($ci,$label)!==false,'Original CI field '.$label);
foreach(['PCS/','N.W.','G.W.','CBM','L<br>(cm)','W<br>(cm)','H<br>(cm)'] as $label)sd_check(strpos($pl,$label)!==false,'Original PL field '.$label);
sd_check(strpos($ci,'SUPERSEDED')!==false&&strpos($pl,'SUPERSEDED')!==false,'Historic revision labeled');
sd_check(strpos($ci,'BANK MUST NOT APPEAR')===false,'Original CI intentionally omits bank section');
sd_check(strpos($ci,'contenteditable')===false&&strpos($ci,'localStorage')===false,'Signed batch preview cannot diverge from exports through local edits');
sd_check(strpos($pl,qd_print_style())!==false,'HTML/PDF reuse exact original print stylesheet');
sd_check(strpos(qd_print_style(),'@page{size:A4 portrait;margin:20mm 15mm 17mm}')!==false,'Page margins preserved on every printed page');
$excel=qsd_excel($doc,'ci');$files=sd_entries($excel);$sheet=$files['xl/worksheets/sheet1.xml'];
sd_check($files['xl/styles.xml']===qoe_styles_xml(),'Excel reuses original style definitions');
sd_check(strpos($sheet,'width="38"')!==false&&strpos($sheet,'HS Code')!==false,'Original CI Excel columns retained');
sd_check(strpos($sheet,'<f>ROUND(H17*I17,2)</f><v>7.5</v>')!==false,'Rounded Excel formula with cached amount');
preg_match_all('/<row r="(\d+)"/',$sheet,$matches);sd_check(count($matches[1])===count(array_unique($matches[1])),'No duplicate Excel row index');
sd_check(strpos($sheet,'From:')!==false&&strpos($sheet,'COMMERCIAL INVOICE')!==false,'Seller and title both retained');
sd_check(strpos($sheet,'orientation="portrait"')!==false&&strpos($sheet,'paperSize="9"')!==false,'Original A4 print setup');
$mix=$doc;$mix['data']['items']=array_slice($data['items'],0,2);
$mix['data']['cartons']=[['carton_no'=>'1','carton_count'=>1,'carton_size'=>'40x36x30','nw'=>2,'gw'=>3,'cbm'=>.0432,'items'=>[['order_item_id'=>1,'qty'=>2],['order_item_id'=>2,'qty'=>3]]],['carton_no'=>'2','carton_count'=>1,'carton_size'=>'20x20x20','nw'=>1,'gw'=>2,'cbm'=>.008,'items'=>[['order_item_id'=>1,'qty'=>1]]]];
$ctx=qsd_template_context($mix,'pl');
sd_check(qd_total($ctx['plItems'],'qty')==6&&qd_total($ctx['plItems'],'cartons')==2&&qd_total($ctx['plItems'],'gw')==5,'Mixed/split cartons count every quantity and weight once');
sd_check($ctx['plItems'][0]['_carton_rowspan']===2&&!empty($ctx['plItems'][1]['_carton_skip_pack']),'Original merged carton cells used');
$mixSheet=sd_entries(qsd_excel($mix,'pl'))['xl/worksheets/sheet1.xml'];
sd_check(strpos($mixSheet,'H17:H18')!==false&&strpos($mixSheet,'O17:O18')!==false,'XLSX merges mixed carton packing cells');
$draft=$mix;$draft['issued']=false;$draft['data']['cartons']=[];
sd_check(strpos(qsd_html($draft,'pl'),'UNPACKED')!==false&&strpos(qsd_html($draft,'pl'),'6 PCS')!==false,'Unpacked draft still lists all selected products');
$cancelled=$mix;$cancelled['state']='cancelled';sd_check(strpos(qsd_html($cancelled,'pl'),'CANCELLED')!==false&&strpos(sd_entries(qsd_excel($cancelled,'ci'))['xl/worksheets/sheet1.xml'],'CANCELLED')!==false,'Cancelled state in both exports');
foreach(['https://127.0.0.1/uploads/website/products/2026/06/a.jpg','https://artdonlighting.com.evil.test/uploads/website/products/2026/06/a.jpg','https://artdonlighting.com:8443/uploads/website/products/2026/06/a.jpg','https://user:pass@artdonlighting.com/uploads/website/products/2026/06/a.jpg','https://artdonlighting.com/uploads/website/products/2026/06/a.jpg?token=x','https://artdonlighting.com/../../secret.jpg'] as $bad){$rejected=false;try{qsd_remote_bytes($bad,microtime(true)+5);}catch(RuntimeException $e){$rejected=true;}sd_check($rejected,'Unsafe remote image rejected before network');}
$evil=$doc;$evil['data']['consignee']='<script>unsafe()</script>';sd_check(strpos(qsd_html($evil,'ci'),'<script>unsafe()')===false,'HTML text escaped');
$evil['data']['consignee']='=HYPERLINK("x")';$evilSheet=sd_entries(qsd_excel($evil,'ci'))['xl/worksheets/sheet1.xml'];
sd_check(strpos($evilSheet,'=HYPERLINK')!==false&&strpos($evilSheet,'<f>=HYPERLINK')===false,'Untrusted Excel text not a formula');
$output=getenv('SHIPMENT_TEST_PDF_DIR');
if($output){
 if(!preg_match('#^/tmp/quote-shipping-links-pdf-[A-Za-z0-9]{8}$#D',$output)||!is_dir($output))throw new RuntimeException('Invalid fixture output directory');
 $im=imagecreatetruecolor(320,240);imagefill($im,0,0,imagecolorallocate($im,55,100,180));ob_start();imagepng($im);$png=ob_get_clean();imagedestroy($im);
 $large='data:image/png;base64,'.base64_encode($png.str_repeat("\0",6*1024*1024));for($i=0;$i<20;$i++)sd_check(strlen(qsd_thumbnail($large))<50000,'Large images bounded');unset($large);
 foreach($doc['data']['items'] as &$item)$item['image']='data:image/png;base64,'.base64_encode($png);unset($item);
 foreach($mix['data']['items'] as &$item)$item['image']='data:image/png;base64,'.base64_encode($png);unset($item);
 foreach(['long'=>$doc,'mixed'=>$mix,'draft'=>$draft] as $name=>$fixture)foreach(['pl','ci'] as $type){
  $html=qsd_html($fixture,$type);$xlsx=qsd_excel($fixture,$type);$parts=sd_entries($xlsx);foreach($parts as $path=>$xml)if(substr($path,-4)==='.xml')sd_check(simplexml_load_string($xml)!==false,'Valid OOXML '.$path);
  file_put_contents($output.'/'.$name.'-'.$type.'.html',$html);file_put_contents($output.'/'.$name.'-'.$type.'.xlsx',$xlsx);
  $start=microtime(true);$pdf=qsd_pdf($html);file_put_contents($output.'/'.$name.'-'.$type.'.pdf',$pdf);
  echo $name.' '.$type.' PDF '.strlen($pdf).' bytes / '.round(microtime(true)-$start,3)." seconds\n";
 }
 echo 'Image/export peak '.memory_get_peak_usage(true)." bytes\n";
}
echo "Original PL/CI formats: columns, styles, images, mixed-carton totals, frozen prices, revision labels and XLSX integrity passed\n";
