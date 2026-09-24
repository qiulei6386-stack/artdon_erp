<?php
// The included fixture refuses production and exercises actual historical functions first.
require __DIR__.'/issues7_shipment_mysql.php';
require dirname(__DIR__).'/includes/quote_shipment_batch.php';
if(!function_exists('qo_table_exists'))i7_function('quote_order_api.php','qo_table_exists');
$GLOBALS['i7FailRecalc']=false;
qb_schema($pdo);
$pdo->exec('ALTER TABLE quote_sales_orders ADD customer_json TEXT,ADD header_json TEXT,ADD bank_json TEXT');
$pdo->exec("ALTER TABLE quote_sales_orders ADD shipment_status VARCHAR(40) DEFAULT '未出货'");
$pdo->exec("ALTER TABLE quote_shipments ADD pl_status VARCHAR(30) DEFAULT 'active',ADD ci_status VARCHAR(30) DEFAULT 'active'");
$pdo->exec('CREATE TABLE quote_packaging_profiles(id INT PRIMARY KEY,product_code VARCHAR(100),customer_code VARCHAR(100),pcs_per_ctn DECIMAL(12,4),carton_size VARCHAR(100),carton_nw DECIMAL(12,4),carton_gw DECIMAL(12,4),unit_nw DECIMAL(12,4),unit_gw DECIMAL(12,4),carton_cbm DECIMAL(12,4),packing_method VARCHAR(100))');
$pdo->exec("INSERT INTO quote_sales_orders(id,order_no,quote_no,customer_id,customer_name,currency,status,amount) VALUES(4,'AT-TEST-C','Q-C','T','验收测试改名','USD','已确认',100),(5,'AT-TEST-D','Q-D','T','验收测试','USD','已确认',100)");
$pdo->exec("INSERT INTO quote_sales_order_items(id,order_id,item_index,qty,unit_price,product_code,product_name,item_json) VALUES(41,4,1,100,2.50,'SAME','C test','{}'),(51,5,1,100,3.50,'SAME','D test','{}')");
$pdo->exec("UPDATE quote_sales_orders SET status='已确认'");
$pdo->exec("UPDATE quote_sales_order_items SET image=CONCAT('data:image/png;base64,',REPEAT('a',8*1024*1024)) WHERE id IN (41,51)");
$listing=qb_candidates($pdo,['order_id'=>4]);
i7_check(count($listing['orders'])>=2&&strlen(json_encode($listing))<20000,'Candidate list uses lightweight summary without snapshot/image payloads');
$search=qb_candidates($pdo,['order_id'=>4,'search'=>'TEST-C']);i7_check(count($search['orders'])===1&&$search['orders'][0]['id']==4,'Candidate search finds historical order');
function btest_item($pdo,$oid,$iid,$qty,$exclude=0){$bundle=qb_items($pdo,$oid,$exclude);$item=array_column($bundle['items'],null,'id')[$iid];return ['order_id'=>$oid,'order_item_id'=>$iid,'qty'=>$qty,'source_hash'=>$item['source_hash']];}
function btest_request($id=0,$version=0){return ['id'=>$id,'version'=>$version,'request_id'=>bin2hex(random_bytes(16)),'reason'=>'验收测试改装'];}
function btest_reject(callable $fn,$label){$rejected=false;try{$fn();}catch(RuntimeException $e){$rejected=true;}i7_check($rejected,$label);}
$beforeShip=(float)$pdo->query('SELECT SUM(qty) FROM quote_shipment_items')->fetchColumn();
$data=['base_order_id'=>4,'ship_date'=>'2026-09-14','consignee'=>'验收客户 / 地址','items'=>[btest_item($pdo,4,41,30),btest_item($pdo,5,51,20)],'cartons'=>[]];
$input=btest_request()+['data'=>$data];$plan=qb_mutate($pdo,'save',$input);$id=(int)$plan['id'];
i7_check(isset($plan['data']['document_order'],$plan['data']['document_settings']),'Original document metadata frozen with plan, without full product snapshots');
i7_check(strpos(json_encode($plan['data']),'data:image')===false,'Original format metadata never embeds source image blobs');
i7_check($plan['state']==='planning'&&qb_reserved($pdo,41)==30,'Plan reserves but is not actual shipment');
i7_check((float)$pdo->query('SELECT SUM(qty) FROM quote_shipment_items')->fetchColumn()===$beforeShip,'Save does not change actual shipment ledger');
$replay=qb_mutate($pdo,'save',$input);i7_check($replay['id']==$id&&$replay['version']==1,'Same request is idempotent');
$changed=$input;$changed['data']['items'][0]['qty']=31;btest_reject(fn()=>qb_mutate($pdo,'save',$changed),'Request token rejects different content');
btest_reject(fn()=>qb_mutate($pdo,'save',btest_request($id,0)+['data'=>$data]),'Stale browser cannot overwrite');
$boxes=[['carton_no'=>'1','nw'=>1,'gw'=>2,'cbm'=>.01,'items'=>[['order_item_id'=>41,'qty'=>10],['order_item_id'=>51,'qty'=>20]]],['carton_no'=>'2','nw'=>1,'gw'=>2,'cbm'=>.01,'items'=>[['order_item_id'=>41,'qty'=>20]]]];
$data['cartons']=$boxes;$plan=qb_mutate($pdo,'save',btest_request($id,1)+['data'=>$data]);
i7_check($plan['data']['totals']['qty']==50&&$plan['data']['totals']['cartons']==2&&$plan['data']['totals']['gw']==4,'Mixed cartons count products and weights exactly once');
$issued=qb_mutate($pdo,'issue',btest_request($id,2));i7_check(count($issued['documents'])===1&&$issued['state']==='planning','Issue does not count as shipped');
$snapshot=$pdo->query('SELECT data_json FROM quote_shipment_plan_documents WHERE plan_id='.$id)->fetchColumn();
// Withdraw second carton, remove D entirely, then re-add D to same plan.
$data['items']=[btest_item($pdo,4,41,10,$id)];$data['cartons']=[['carton_no'=>'1','nw'=>1,'gw'=>2,'cbm'=>.01,'items'=>[['order_item_id'=>41,'qty'=>10]]]];
$plan=qb_mutate($pdo,'save',btest_request($id,3)+['data'=>$data]);
i7_check(qb_reserved($pdo,41)==10&&qb_reserved($pdo,51)==0,'Withdraw/removal releases reservations');
$data['items'][]=btest_item($pdo,5,51,12,$id);$data['cartons'][0]['items'][]=['order_item_id'=>51,'qty'=>12];
$plan=qb_mutate($pdo,'save',btest_request($id,4)+['data'=>$data]);
i7_check(count($plan['data']['orders'])===2,'Add removed order back without new batch');
i7_check($snapshot===$pdo->query('SELECT data_json FROM quote_shipment_plan_documents WHERE plan_id='.$id)->fetchColumn(),'Signed revision remains byte-identical after amendment');
$bad=$data;$bad['cartons'][0]['items'][0]['qty']=11;btest_reject(fn()=>qb_mutate($pdo,'save',btest_request($id,5)+['data'=>$bad]),'Per-product carton over-allocation rejected');
$bad=$data;$bad['items'][0]['qty']=101;btest_reject(fn()=>qb_mutate($pdo,'save',btest_request($id,5)+['data'=>$bad]),'Over-order quantity rejected');
$other=['base_order_id'=>4,'ship_date'=>'2026-09-14','consignee'=>'验收客户','items'=>[btest_item($pdo,4,41,95)],'cartons'=>[]];
btest_reject(fn()=>qb_mutate($pdo,'save',btest_request()+['data'=>$other]),'Other plan cannot consume reserved goods');
btest_reject(fn()=>qo_shipment_validate_multi_items($pdo,[4],0,[['order_item_id'=>41,'qty'=>95]]),'Legacy shipment path also respects plan reservations');
$GLOBALS['i7FailRecalc']=true;btest_reject(fn()=>qb_mutate($pdo,'dispatch',btest_request($id,5)),'Injected finalization failure rolls back');$GLOBALS['i7FailRecalc']=false;
i7_check(qb_get($pdo,$id)['version']==5&&qb_reserved($pdo,41)==10,'Failed dispatch preserves plan reservation and version');
$dispatchRequest=btest_request($id,5);$done=qb_mutate($pdo,'dispatch',$dispatchRequest);
i7_check($done['state']==='shipped'&&qb_reserved($pdo,41)==0,'Dispatch transfers reservation to actual shipment atomically');
i7_check(qb_mutate($pdo,'dispatch',$dispatchRequest)['shipment_id']===$done['shipment_id'],'Retry dispatch does not duplicate shipment');
$shipment=(int)$done['shipment_id'];$totals=$pdo->query('SELECT SUM(qty) q,SUM(amount) a FROM quote_shipment_items WHERE shipment_id='.$shipment)->fetch();
foreach([4,5] as $member){$linked=qsl_batches($pdo,$member)['batches'];i7_check(count(array_filter($linked,fn($r)=>$r['kind']==='plan'&&(int)$r['id']===$id))===1,'Every participating order sees the merged plan once');i7_check(count(array_filter($linked,fn($r)=>$r['kind']==='legacy'&&(int)$r['id']===$shipment))===0,'Dispatched plan not duplicated as legacy batch');}
i7_check((float)$totals['q']==22&&(float)$totals['a']==67,'Actual quantities and original distinct prices retained');
i7_check((int)$pdo->query('SELECT COUNT(*) FROM quote_shipment_items s JOIN quote_sales_order_items i ON i.id=s.order_item_id WHERE s.shipment_id='.$shipment.' AND BINARY s.image=BINARY i.image')->fetchColumn()===2,'New dispatch preserves original large images byte-for-byte');
btest_reject(fn()=>qb_mutate($pdo,'save',btest_request($id,6)+['data'=>$data]),'Shipped batch cannot be overwritten');
$reversed=qb_mutate($pdo,'reverse',btest_request($id,6));
i7_check($reversed['state']==='planning'&&qb_reserved($pdo,41)==10,'Mistaken confirmation restored as reservation, not silently deleted');
i7_check((int)$pdo->query('SELECT COUNT(*) FROM quote_shipment_reversed_items WHERE shipment_id='.$shipment)->fetchColumn()===2,'Reversal archives exact original item records');
i7_check((int)$pdo->query('SELECT COUNT(*) FROM quote_shipment_items WHERE shipment_id='.$shipment)->fetchColumn()===0,'Reversal removes actual quantities from live accounting');
$cancelReversed=qb_mutate($pdo,'cancel',btest_request($id,7));
i7_check(qb_reserved($pdo,41)==0,'Reversed plan can cancel and release goods');
$next=$other;$next['items']=[btest_item($pdo,4,41,5)];$nextPlan=qb_mutate($pdo,'save',btest_request()+['data'=>$next]);
$cancel=qb_mutate($pdo,'cancel',btest_request((int)$nextPlan['id'],1));i7_check($cancel['state']==='cancelled'&&qb_reserved($pdo,41)==0,'Cancel retains audit and releases quantity');
$triple=$data;$triple['cartons']=[];$triple['items']=[btest_item($pdo,1,11,1),btest_item($pdo,4,41,2)];
$three=qb_mutate($pdo,'save',btest_request()+['data'=>$triple]);
$triple['items'][]=btest_item($pdo,5,51,3,(int)$three['id']);
$three=qb_mutate($pdo,'save',btest_request((int)$three['id'],1)+['data'=>$triple]);
i7_check(count($three['data']['orders'])===3&&$three['data']['totals']['qty']==6,'Append third A/B/C order to saved batch');
qb_mutate($pdo,'cancel',btest_request((int)$three['id'],2));
$peer=new PDO('mysql:unix_socket='.$socket.';dbname='.$schema.';charset=utf8mb4','root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
qr_with_order_locks($pdo,[4,5],function()use($peer){i7_check((int)$peer->query("SELECT GET_LOCK('quote-shipment-order:4',0)")->fetchColumn()===0,'Competing connection blocked on same orders');});
echo 'Shipment batch MySQL: reservations, add/remove/re-add, mixed cartons, per-item checks, revisions, optimistic versions, idempotency, legacy guards, rollback, dispatch and cancellation passed. Peak '.memory_get_peak_usage(true)." bytes\n";
require __DIR__.'/quote_shipment_links_mysql.inc.php';
// Renderer reads only the selected revision's quantities/prices; order lookups
// supply missing historical metadata/images, not today's product catalogue.
$pdo->exec("UPDATE quote_sales_order_items SET image='',item_json='{}' WHERE id IN (41,51)");
$testDoc=['id'=>$id,'state'=>'planning','version'=>3,'current_version'=>8,'issued'=>true,'data'=>json_decode($snapshot,true)];
$docHash=hash('sha256',$snapshot);$enriched=qsd_enrich($pdo,$testDoc);$context=qsd_template_context($enriched,'pl');
i7_check(qd_total($context['plItems'],'qty')==50&&qd_total($context['plItems'],'cartons')==2,'Original template uses historic issued allocation, not changed draft');
i7_check(qd_total($context['ciItems'],'amount')==145,'Issued CI retains source-order prices after later changes');
i7_check($docHash===hash('sha256',$pdo->query('SELECT data_json FROM quote_shipment_plan_documents WHERE plan_id='.$id.' ORDER BY version LIMIT 1')->fetchColumn()),'Preview enrichment never modifies signed source');
unset($testDoc['data']['document_order'],$testDoc['data']['document_settings']);
i7_check(qsd_template_context(qsd_enrich($pdo,$testDoc),'ci')['order']['currency']==='USD','Pre-upgrade batches retain original template compatibility');
echo "Original document adapter: real projected SQL, frozen revisions, historical metadata fallback and read-only preview passed\n";
