<?php
if(PHP_SAPI!=='cli')exit(2);
require_once dirname(__DIR__).'/includes/quote_shipment_document.php';
function sd_check($ok,$label){if(!$ok)throw new RuntimeException($label);}
$data=['seller_name'=>'ARTDON · 验收测试','seller_text'=>'Synthetic fixture — not a business document','consignee'=>'验收测试客户 / Acceptance only','ship_date'=>'2026-09-24','currency'=>'USD','ship_method'=>'Sea','port_loading'=>'Guangzhou','port_destination'=>'Test port','shipping_mark'=>'TEST ONLY','note'=>'合成测试，不代表实际出货','items'=>[],'cartons'=>[],'totals'=>['qty'=>0,'cartons'=>0,'nw'=>0,'gw'=>0,'cbm'=>0]];
for($i=1;$i<=28;$i++){$data['items'][]=['order_item_id'=>$i,'order_no'=>'AT-TEST-'.($i%3+1),'product_code'=>'MODEL-'.$i,'product_name'=>'Test luminaire','specification'=>'Acceptance lighting / 3000K / long specification / 合成规格','qty'=>3,'unit_price'=>2.5,'amount'=>7.5];$data['cartons'][]=['carton_no'=>(string)$i,'carton_count'=>1,'carton_size'=>'40x36x30','nw'=>2,'gw'=>3,'cbm'=>.0432,'items'=>[['order_item_id'=>$i,'qty'=>3]]];}
$data['totals']=['qty'=>84,'cartons'=>28,'nw'=>56,'gw'=>84,'cbm'=>1.2096];
$doc=['id'=>999,'state'=>'planning','version'=>2,'current_version'=>3,'issued'=>true,'data'=>$data];
sd_check(strpos(qsd_html($doc,'ci'),'USD 210.00')!==false,'CI uses exact selected quantity and amount');
sd_check(strpos(qsd_html($doc,'pl'),'28 CTNS')!==false,'PL carton total');
sd_check(strpos(qsd_html($doc,'pl'),'SUPERSEDED')!==false,'Old issued revision labeled');
sd_check(strpos(qsd_excel($doc,'pl'),'Total N.W. kg')!==false,'Excel has packing totals');
foreach(['https://127.0.0.1/uploads/website/products/2026/06/a.jpg','https://artdonlighting.com.evil.test/uploads/website/products/2026/06/a.jpg','https://artdonlighting.com:8443/uploads/website/products/2026/06/a.jpg','https://user:pass@artdonlighting.com/uploads/website/products/2026/06/a.jpg','https://artdonlighting.com/uploads/website/products/2026/06/a.jpg?token=x','https://artdonlighting.com/../../secret.jpg'] as $bad){$rejected=false;try{qsd_remote_bytes($bad,microtime(true)+5);}catch(RuntimeException $e){$rejected=true;}sd_check($rejected,'Unsafe remote image rejected before network');}
$evil=$doc;$evil['data']['consignee']='<script>unsafe()</script>';
sd_check(strpos(qsd_html($evil,'ci'),'<script>unsafe()')===false,'HTML escapes customer text');
$evil['data']['consignee']='=HYPERLINK("x")';sd_check(strpos(qsd_excel($evil,'ci'),'ss:Type="String">=HYPERLINK')!==false,'Excel text not formula');
$output=getenv('SHIPMENT_TEST_PDF_DIR');
if($output){
 $im=imagecreatetruecolor(320,240);imagefill($im,0,0,imagecolorallocate($im,55,100,180));ob_start();imagepng($im);$png=ob_get_clean();imagedestroy($im);
 $large='data:image/png;base64,'.base64_encode($png.str_repeat("\0",6*1024*1024));$thumbs=[];
 for($i=0;$i<20;$i++)$thumbs[]=qsd_thumbnail($large);
 sd_check(strlen($thumbs[0])<50000,'Large images reduced to bounded export thumbnails');unset($large,$thumbs);
 echo '20 x 8MB image source thumbnail test; peak '.memory_get_peak_usage(true)." bytes\n";
}
if($output){if(!preg_match('#^/tmp/quote-shipping-links-pdf-[A-Za-z0-9]{8}$#D',$output)||!is_dir($output))throw new RuntimeException('Invalid fixture output directory');foreach(['pl','ci'] as $type){$html=qsd_html($doc,$type);$xml=qsd_excel($doc,$type);$parsed=simplexml_load_string($xml);sd_check($parsed!==false,'Excel XML valid');file_put_contents($output.'/'.$type.'.html',$html);file_put_contents($output.'/'.$type.'.xls',$xml);$start=microtime(true);$pdf=qsd_pdf($html);file_put_contents($output.'/'.$type.'.pdf',$pdf);echo $type.' PDF '.strlen($pdf).' bytes, '.round(microtime(true)-$start,3)." seconds\n";}}
echo "Shipment documents: selected quantity/price, multi-order rows, revision labels, packing totals and text escaping passed\n";
