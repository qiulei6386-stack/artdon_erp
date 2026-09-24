<?php
// Read projections only: no schema changes, image reads or business writes.
function qsl_closed(array $order): bool {
    return in_array(trim($order['status']??''),['已完结','已完成','完成','取消','已作废'],true)||trim($order['shipment_status']??'')==='已出货';
}
function qsl_eligible_sql(int $exclude=0): string {
    $shipped='COALESCE((SELECT SUM(s.qty) FROM quote_shipment_items s WHERE s.order_item_id=si.id),0)';
    $reserved="COALESCE((SELECT SUM(pi.qty) FROM quote_shipment_plan_items pi JOIN quote_shipment_plans pp ON pp.id=pi.plan_id WHERE pi.order_item_id=si.id AND pp.state='planning' AND pp.id<>".max(0,$exclude).'),0)';
    return "COALESCE(o.status,'') NOT IN ('已完结','已完成','完成','取消','已作废') AND COALESCE(o.shipment_status,'')<>'已出货' AND NOT EXISTS(SELECT 1 FROM quote_sales_order_items si WHERE si.order_id=o.id AND si.qo_virtual_line_v1=0 AND COALESCE(si.shipped_qty,0)>$shipped+0.00001) AND EXISTS(SELECT 1 FROM quote_sales_order_items si WHERE si.order_id=o.id AND si.qo_virtual_line_v1=0 AND si.qty>$shipped+$reserved+0.00001)";
}
function qsl_pack_options(PDO $pdo,array $item): array {
    $s=$pdo->prepare('SELECT id,product_code,customer_code,pcs_per_ctn,carton_size,carton_nw,carton_gw,unit_nw,unit_gw,carton_cbm,packing_method FROM quote_packaging_profiles WHERE product_code=? ORDER BY id DESC LIMIT 50');
    $s->execute([(string)($item['product_code']??'')]);$out=[];$key=trim((string)($item['customer_code']??''));
    foreach($s->fetchAll(PDO::FETCH_ASSOC) as $row){$code=trim((string)$row['customer_code']);$row['match_kind']=$code===$key?'exact':($code===''?'generic':'alternative');$out[]=$row;}
    usort($out,fn($a,$b)=>array_search($a['match_kind'],['exact','generic','alternative'])<=>array_search($b['match_kind'],['exact','generic','alternative']));return $out;
}
function qsl_batches(PDO $pdo,int $orderId): array {
    if(!qo_row($pdo,'SELECT id FROM quote_sales_orders WHERE id=?',[$orderId]))throw new RuntimeException('订单不存在');
    $plans=qo_rows($pdo,"SELECT p.id,p.base_order_id,p.state,p.version,p.shipment_id,p.updated_at,JSON_UNQUOTE(JSON_EXTRACT(p.data_json,'$.ship_date')) ship_date,(SELECT MAX(d.version) FROM quote_shipment_plan_documents d WHERE d.plan_id=p.id) issued_version,(SELECT SUM(i.qty) FROM quote_shipment_plan_items i WHERE i.plan_id=p.id AND i.order_id=?) order_qty FROM quote_shipment_plans p WHERE EXISTS(SELECT 1 FROM quote_shipment_plan_items i WHERE i.plan_id=p.id AND i.order_id=?) OR p.base_order_id=? ORDER BY p.id DESC",[$orderId,$orderId,$orderId]);
    $out=[];$linked=[];foreach($plans as $p){$p['kind']='plan';$p['label']='装运批次 #'.$p['id'];if($p['shipment_id'])$linked[(int)$p['shipment_id']]=true;$out[]=$p;}
    $ships=qo_rows($pdo,'SELECT s.id,s.shipment_no,s.status state,s.ship_date,s.updated_at,s.pl_status,s.ci_status,(SELECT SUM(si.qty) FROM quote_shipment_items si WHERE si.shipment_id=s.id AND si.order_id=?) order_qty FROM quote_shipments s WHERE EXISTS(SELECT 1 FROM quote_shipment_items si WHERE si.shipment_id=s.id AND si.order_id=? AND si.qty>0) OR (NOT EXISTS(SELECT 1 FROM quote_shipment_items si WHERE si.shipment_id=s.id) AND (s.order_id=? OR EXISTS(SELECT 1 FROM quote_shipment_orders so WHERE so.shipment_id=s.id AND so.order_id=?))) ORDER BY s.id DESC',[$orderId,$orderId,$orderId,$orderId]);
    foreach($ships as $s)if(!isset($linked[(int)$s['id']])){$s['kind']='legacy';$s['label']=$s['shipment_no'];$out[]=$s;}return ['batches'=>$out];
}
