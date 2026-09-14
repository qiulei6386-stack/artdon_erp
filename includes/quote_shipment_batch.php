<?php
// Shipment plans reserve goods without inserting historical shipment/commission rows.
function qb_json($value): string {
    $json=json_encode($value,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
    if(strlen($json)>2097152)throw new RuntimeException('本批次内容过大，请减少明细或附件；计划不接受内嵌图片');
    return $json;
}
function qb_schema(PDO $pdo): void {
    if($pdo->inTransaction())throw new RuntimeException('出货初始化不能在事务内执行');
    $pdo->exec("CREATE TABLE IF NOT EXISTS quote_shipment_plans (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, base_order_id INT NOT NULL,
        state VARCHAR(24) NOT NULL DEFAULT 'planning', version INT NOT NULL DEFAULT 1,
        shipment_id INT NULL, data_json LONGTEXT NOT NULL, created_by VARCHAR(120) NOT NULL,
        created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL,
        KEY base_order(base_order_id), KEY state_updated(state,updated_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS quote_shipment_plan_items (
        plan_id BIGINT UNSIGNED NOT NULL, order_id INT NOT NULL, order_item_id INT NOT NULL,
        qty DECIMAL(18,4) NOT NULL, PRIMARY KEY(plan_id,order_item_id),
        KEY item_reservation(order_item_id,plan_id), KEY order_plan(order_id,plan_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS quote_shipment_plan_events (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, plan_id BIGINT UNSIGNED NOT NULL,
        version INT NOT NULL, action VARCHAR(24) NOT NULL, actor VARCHAR(120) NOT NULL,
        reason VARCHAR(1000) NOT NULL, request_id VARCHAR(80) NOT NULL,
        request_hash CHAR(64) NOT NULL, data_json LONGTEXT NOT NULL, created_at DATETIME NOT NULL,
        UNIQUE KEY idempotency(request_id), UNIQUE KEY plan_version(plan_id,version)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS quote_shipment_plan_documents (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, plan_id BIGINT UNSIGNED NOT NULL,
        version INT NOT NULL, data_json LONGTEXT NOT NULL, actor VARCHAR(120) NOT NULL,
        created_at DATETIME NOT NULL, UNIQUE KEY plan_version(plan_id,version)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec('CREATE TABLE IF NOT EXISTS quote_shipment_reversed_items LIKE quote_shipment_items');
    $pdo->exec('CREATE TABLE IF NOT EXISTS quote_shipment_reversed_cartons LIKE quote_shipment_cartons');
}
function qb_reserved(PDO $pdo,int $itemId,int $exclude=0): float {
    static $exists=[];$key=spl_object_id($pdo);
    if(!array_key_exists($key,$exists))$exists[$key]=qo_table_exists($pdo,'quote_shipment_plan_items');
    if(!$exists[$key])return 0;
    return (float)(qo_row($pdo,"SELECT COALESCE(SUM(i.qty),0) qty FROM quote_shipment_plan_items i JOIN quote_shipment_plans p ON p.id=i.plan_id WHERE i.order_item_id=? AND p.state='planning' AND p.id<>?",[$itemId,$exclude])['qty']??0);
}
function qb_quantity($value,string $label): float {
    if(!is_numeric($value)||!is_finite((float)$value)||(float)$value<0||(float)$value>9999999999)throw new RuntimeException($label.'须为有效非负数字');
    return round((float)$value,4);
}
function qb_customer(array $order): string {
    $id=trim((string)($order['customer_id']??''));
    return $id!==''&&$id!=='0'?'id:'.$id:'legacy:'.strtolower(trim((string)($order['customer_name']??'')));
}
function qb_compatible(array $base,array $order): string {
    if(qb_customer($base)!==qb_customer($order))return '不是同一客户，不能合并';
    if(strtoupper(trim($base['currency']??''))!==strtoupper(trim($order['currency']??'')))return '币种不同，请另建批次';
    $baseHeader=json_decode((string)($base['header_json']??'{}'),true)?:[];$orderHeader=json_decode((string)($order['header_json']??'{}'),true)?:[];
    if(!empty($baseHeader['company'])&&!empty($orderHeader['company'])&&trim($baseHeader['company'])!==trim($orderHeader['company']))return '发货公司抬头不同，请另建批次';
    if(in_array($order['status']??'',['取消','已作废'],true))return '订单已取消或作废';
    return '';
}
function qb_items(PDO $pdo,int $orderId,int $exclude=0): array {
    $order=qr_order($pdo,$orderId);if(!$order)throw new RuntimeException('订单不存在');
    $items=qo_rows($pdo,'SELECT '.qr_item_columns($pdo).' FROM quote_sales_order_items WHERE order_id=? ORDER BY item_index,id',[$orderId]);
    if(!$items)throw new RuntimeException('订单明细缺失，请核对；未自动重建');
    $shipped=array_column(qo_rows($pdo,'SELECT order_item_id,SUM(qty) qty FROM quote_shipment_items WHERE order_id=? GROUP BY order_item_id',[$orderId]),'qty','order_item_id');
    $reserved=array_column(qo_rows($pdo,"SELECT i.order_item_id,SUM(i.qty) qty FROM quote_shipment_plan_items i JOIN quote_shipment_plans p ON p.id=i.plan_id WHERE i.order_id=? AND p.state='planning' AND p.id<>? GROUP BY i.order_item_id",[$orderId,$exclude]),'qty','order_item_id');
    foreach($items as &$item){
        $item['order_no']=qo_order_ref($order);$item['order_id']=$orderId;
        $item['ordered_qty']=(float)$item['qty'];
        $item['legacy_or_shipped_qty']=(float)($shipped[$item['id']]??0);
        $item['reserved_qty']=(float)($reserved[$item['id']]??0);
        $item['available_qty']=qo_is_virtual_item($item)?0:max(0,(float)$item['qty']-$item['legacy_or_shipped_qty']-$item['reserved_qty']);
        $item['is_virtual']=qo_is_virtual_item($item)?1:0;
        $item['source_hash']=hash('sha256',json_encode([$item['id'],$item['order_id'],$item['qty'],$item['unit_price'],$item['product_code']??'',$item['product_name']??'',$item['customer_code']??'',$item['specification']??'',$item['color']??'',qb_customer($order),$order['currency']??'',$order['header_json']??'',$order['bank_json']??'',$order['customer_json']??''],JSON_THROW_ON_ERROR));
        unset($item['image'],$item['item_json']);
    }unset($item);
    return ['order'=>$order,'items'=>$items];
}
function qb_candidates(PDO $pdo,array $input): array {
    $base=qr_order($pdo,(int)($input['order_id']??0));if(!$base)throw new RuntimeException('订单不存在');
    $page=max(1,(int)($input['page']??1));$kw='%'.qo_s($input['search']??'',100).'%';
    $id=trim((string)($base['customer_id']??''));
    $where=$id!==''&&$id!=='0'?'o.customer_id=?':"COALESCE(o.customer_id,'') IN ('','0') AND o.customer_name=?";
    $args=[$id!==''&&$id!=='0'?$id:($base['customer_name']??''),$kw,$kw];
    $where.=" AND COALESCE(o.status,'') NOT IN ('取消','已作废') AND (o.order_no LIKE ? OR o.quote_no LIKE ?)";
    $where.=" AND EXISTS(SELECT 1 FROM quote_sales_order_items si WHERE si.order_id=o.id AND si.qty>COALESCE((SELECT SUM(s.qty) FROM quote_shipment_items s WHERE s.order_item_id=si.id),0))";
    // Summary only: never fetch images/snapshot_json for a list.
    $sql=" FROM quote_sales_orders o WHERE $where";
    $total=(int)(qo_row($pdo,'SELECT COUNT(*) n'.$sql,$args)['n']??0);
    $orders=qo_rows($pdo,"SELECT o.id,o.order_no,o.quote_no,o.customer_id,o.customer_name,o.currency,o.status,JSON_OBJECT('company',JSON_EXTRACT(IF(JSON_VALID(o.header_json),o.header_json,'{}'),'$.company')) header_json,COALESCE(JSON_UNQUOTE(JSON_EXTRACT(IF(JSON_VALID(o.customer_json),o.customer_json,'{}'),'$.delivery_address')),JSON_UNQUOTE(JSON_EXTRACT(IF(JSON_VALID(o.customer_json),o.customer_json,'{}'),'$.address')),'') delivery_address".$sql.' ORDER BY o.id DESC LIMIT 25 OFFSET '.(($page-1)*25),$args);
    foreach($orders as &$o){$o['incompatible']=qb_compatible($base,$o);}
    unset($o);
    $planWhere=$id!==''&&$id!=='0'?'o.customer_id=?':"COALESCE(o.customer_id,'') IN ('','0') AND o.customer_name=?";
    $plans=qo_rows($pdo,"SELECT p.id,p.base_order_id,p.state,p.version,p.shipment_id,p.updated_at FROM quote_shipment_plans p JOIN quote_sales_orders o ON o.id=p.base_order_id WHERE $planWhere ORDER BY p.id DESC LIMIT 100",[$id!==''&&$id!=='0'?$id:($base['customer_name']??'')]);
    return ['base'=>$base,'orders'=>$orders,'page'=>$page,'total'=>$total,'plans'=>$plans];
}
function qb_get(PDO $pdo,int $id): array {
    $plan=qo_row($pdo,'SELECT * FROM quote_shipment_plans WHERE id=?',[$id]);if(!$plan)throw new RuntimeException('出货计划不存在');
    $plan['data']=qo_json($plan['data_json']);unset($plan['data_json']);
    $plan['history']=qo_rows($pdo,'SELECT version,action,actor,reason,created_at FROM quote_shipment_plan_events WHERE plan_id=? ORDER BY version DESC LIMIT 100',[$id]);
    $plan['documents']=qo_rows($pdo,'SELECT id,version,actor,created_at FROM quote_shipment_plan_documents WHERE plan_id=? ORDER BY version DESC',[$id]);
    return $plan;
}
function qb_cartons(array $cartons,array $items,bool $complete=false): array {
    $selected=[];foreach($items as $item)$selected[(int)$item['order_item_id']]=(float)$item['qty'];
    $allocated=[];$clean=[];$names=[];$ranges=[];$tot=['qty'=>array_sum($selected),'cartons'=>0,'nw'=>0,'gw'=>0,'cbm'=>0];
    foreach($cartons as $carton){
        $name=qo_s($carton['carton_no']??'',80);if($name===''||isset($names[$name]))throw new RuntimeException('每箱须有唯一箱号');$names[$name]=true;
        $count=qb_quantity($carton['carton_count']??1,'箱数');
        if($count<1||$count!==floor($count))throw new RuntimeException('箱数须为正整数');
        if(preg_match('/^(\d+)\s*[-–~至]\s*(\d+)$/uD',$name,$match)){
            $start=(int)$match[1];$end=(int)$match[2];
            if($end<$start||$end-$start+1!=$count)throw new RuntimeException('箱号范围与本组箱数不一致');
            foreach($ranges as $range)if($start<=$range[1]&&$end>=$range[0])throw new RuntimeException('箱号范围重叠');
            $ranges[]=[$start,$end];
        }elseif(preg_match('/^\d+$/D',$name)&&$count==1){
            $number=(int)$name;foreach($ranges as $range)if($number>=$range[0]&&$number<=$range[1])throw new RuntimeException('箱号重复或范围重叠');$ranges[]=[$number,$number];
        }
        $row=['carton_no'=>$name,'carton_size'=>qo_s($carton['carton_size']??'',100),'note'=>qo_s($carton['note']??'',500),'items'=>[],'qty'=>0,'carton_count'=>$count];
        foreach(['nw','gw','cbm'] as $field)$row[$field]=qb_quantity($carton[$field]??0,'箱重/体积');
        if($row['gw']<$row['nw'])throw new RuntimeException('毛重不能小于净重');
        foreach(($carton['items']??[]) as $part){
            $iid=(int)($part['order_item_id']??0);$qty=qb_quantity($part['qty']??0,'箱内数量');if($qty<=0)continue;
            if(!isset($selected[$iid]))throw new RuntimeException('箱内产品不在本次出货选择中');
            $allocated[$iid]=($allocated[$iid]??0)+$qty;$row['qty']+=$qty;
            $row['items'][]=['order_item_id'=>$iid,'qty'=>$qty];
        }
        if(!$row['items'])throw new RuntimeException('空箱请移除，或填写箱内产品');
        $clean[]=$row;$tot['cartons']+=$count;foreach(['nw','gw','cbm'] as $field)$tot[$field]+=$row[$field];
    }
    foreach($selected as $iid=>$qty){
        if(($allocated[$iid]??0)>$qty+0.00001)throw new RuntimeException('产品装箱数量超过本次计划，请先调整箱内分配');
        if($complete&&abs(($allocated[$iid]??0)-$qty)>0.00001)throw new RuntimeException('仍有产品未装箱，不能签发单证或确认出货');
    }
    $tot['unpacked_qty']=round($tot['qty']-array_sum($allocated),4);
    return ['cartons'=>$clean,'totals'=>$tot];
}
function qb_validate(PDO $pdo,array $data,int $planId): array {
    $base=qr_order($pdo,(int)($data['base_order_id']??0));if(!$base)throw new RuntimeException('基础订单不存在');
    $items=[];$orders=[];$cache=[];$seen=[];
    foreach(($data['items']??[]) as $input){
        $iid=(int)($input['order_item_id']??0);$oid=(int)($input['order_id']??0);$qty=qb_quantity($input['qty']??0,'出货数量');if($qty<=0)continue;
        if(isset($seen[$iid]))throw new RuntimeException('产品选择重复，请通过装箱分配拆箱');$seen[$iid]=true;
        if(!isset($cache[$oid])){
            $bundle=qb_items($pdo,$oid,$planId);$reason=qb_compatible($base,$bundle['order']);if($reason!=='')throw new RuntimeException($reason);
            $cache[$oid]=array_column($bundle['items'],null,'id');$orders[$oid]=$bundle['order'];
        }
        $item=$cache[$oid][$iid]??null;if(!$item||$item['is_virtual'])throw new RuntimeException('产品不属于此订单或为不可出货费用项');
        if(!hash_equals($item['source_hash'],(string)($input['source_hash']??'')))throw new RuntimeException('订单产品或价格已变化，请重新加载核对，未覆盖当前选择');
        if($qty>$item['available_qty']+0.00001)throw new RuntimeException($item['order_no'].' / '.($item['product_code']??'').' 可出不足，最多 '.$item['available_qty']);
        $item['order_item_id']=$iid;$item['qty']=$qty;$item['amount']=round($qty*(float)$item['unit_price'],2);$items[]=$item;
    }
    if(!$items)throw new RuntimeException('请至少选择一个产品并填写数量');
    $packed=qb_cartons($data['cartons']??[],$items);
    $meta=[];foreach(['ship_date','shipping_mark','ship_method','port_loading','port_destination','consignee','note'] as $field)$meta[$field]=qo_s($data[$field]??'',1000);
    if(!preg_match('/^\d{4}-\d{2}-\d{2}$/D',$meta['ship_date'])||date('Y-m-d',strtotime($meta['ship_date']))!==$meta['ship_date'])throw new RuntimeException('请填写有效出货日期');
    if($meta['consignee']==='')throw new RuntimeException('请核对并填写本批统一收货信息');
    $orderSnapshots=[];foreach($orders as $order)$orderSnapshots[]=['id'=>(int)$order['id'],'order_no'=>qo_order_ref($order),'customer_name'=>$order['customer_name']??'','currency'=>$order['currency']??''];
    $header=qo_json($base['header_json']??'');$bank=qo_json($base['bank_json']??'');
    return array_merge($meta,['base_order_id'=>(int)$base['id'],'customer_name'=>$base['customer_name']??'','currency'=>$base['currency']??'','seller_name'=>qo_s($header['company']??'',255),'seller_text'=>qo_s($header['from_text']??'',3000),'bank_text'=>qo_s($bank['text']??'',3000),'orders'=>$orderSnapshots,'items'=>$items,'cartons'=>$packed['cartons'],'totals'=>$packed['totals']]);
}
function qb_mutate(PDO $pdo,string $action,array $input): array {
    qb_schema($pdo);qo_ensure_schema($pdo);qo_commission_schema($pdo);
    $id=(int)($input['id']??0);$request=qo_s($input['request_id']??'',80);
    if(!preg_match('/^[A-Za-z0-9_-]{16,80}$/D',$request))throw new RuntimeException('缺少有效请求编号，请刷新页面');
    $hash=hash('sha256',qb_json([$action,qo_actor(),$input]));
    $lock='quote-batch:'.($id>0?'id:'.$id:'request:'.substr(hash('sha256',$request),0,32));
    if((int)($pdo->query('SELECT GET_LOCK('.$pdo->quote($lock).',5)')->fetchColumn())!==1)throw new RuntimeException('批次正在保存，请稍后重试');
    try{
        $prior=qo_row($pdo,'SELECT plan_id,request_hash FROM quote_shipment_plan_events WHERE request_id=?',[$request]);
        if($prior){if(!hash_equals($prior['request_hash'],$hash))throw new RuntimeException('请求编号重复但内容不同');return qb_get($pdo,(int)$prior['plan_id']);}
        $old=$id>0?qb_get($pdo,$id):null;
        if($old&&(int)($input['version']??0)!==(int)$old['version'])throw new RuntimeException('此批次已被修改，请保留输入并重新加载对比后再保存');
        if($old&&$old['state']!=='planning'&&!($action==='reverse'&&$old['state']==='shipped'))throw new RuntimeException('已出货或已取消批次不能覆盖修改');
        if(!$old&&$action!=='save')throw new RuntimeException('请先保存出货计划');
        $reason=qo_s($input['reason']??'',1000);
        if($old&&$reason==='')throw new RuntimeException('请填写本次修改/操作原因');
        $data=$action==='save'?($input['data']??[]):$old['data'];
        if($old&&(int)($data['base_order_id']??0)!==(int)$old['base_order_id'])throw new RuntimeException('不能把已有批次移动到另一基础订单，请新建批次');
        $ids=array_map('intval',array_column($data['items']??[],'order_id'));
        if($old)$ids=array_merge($ids,array_map('intval',array_column($old['data']['items'],'order_id')));
        $ids[]=(int)($data['base_order_id']??0);
        return qr_with_order_locks($pdo,$ids,function()use($pdo,$action,$input,$id,$request,$hash,$old,$reason,$data){
            $pdo->beginTransaction();
            try{
                $validated=in_array($action,['cancel','reverse'],true)?$old['data']:qb_validate($pdo,$data,$id);
                if(in_array($action,['issue','dispatch'],true))qb_cartons($validated['cartons'],$validated['items'],true);
                $version=$old?(int)$old['version']+1:1;$state=$action==='cancel'?'cancelled':($action==='dispatch'?'shipped':'planning');
                $planId=$id;$shipmentId=$old['shipment_id']??null;
                if(!$old){$pdo->prepare('INSERT INTO quote_shipment_plans(base_order_id,state,version,data_json,created_by,created_at,updated_at) VALUES(?,?,?,?,?,NOW(),NOW())')->execute([$validated['base_order_id'],$state,$version,qb_json($validated),qo_actor()]);$planId=(int)$pdo->lastInsertId();}
                if($action==='dispatch'){
                    $validated['_commission_before']=[];
                    if(qo_table_exists($pdo,'quote_commission_snapshots'))foreach($validated['orders'] as $order){
                        $validated['_commission_before']=array_merge($validated['_commission_before'],qo_rows($pdo,"SELECT id,order_id,settle_status,settled_amount FROM quote_commission_snapshots WHERE order_id=? AND settle_node='shipped'",[$order['id']]));
                    }
                    // Remove own reservation only inside the same atomic transaction.
                    $pdo->prepare('DELETE FROM quote_shipment_plan_items WHERE plan_id=?')->execute([$planId]);
                    $legacy=array_merge($validated,['order_id'=>$validated['base_order_id'],'order_ids'=>array_column($validated['orders'],'id'),'status'=>'已出货','force_shipment'=>!empty($input['force_shipment'])?1:0]);
                    $legacy['shipment_no']='QB-'.$planId.'-S'.$version;
                    $legacy['packing_list_no']='QB-'.$planId.'-PL-R'.$version;
                    $legacy['commercial_invoice_no']='QB-'.$planId.'-CI-R'.$version;
                    $legacy['items']=array_map(function($item){return ['order_id'=>$item['order_id'],'order_item_id'=>$item['order_item_id'],'qty'=>$item['qty'],'cartons'=>0,'nw'=>0,'gw'=>0,'cbm'=>0];},$validated['items']);
                    foreach($legacy['cartons'] as &$carton){$texts=[];foreach($carton['items'] as $part){foreach($validated['items'] as $item)if($item['order_item_id']==$part['order_item_id'])$texts[]=$item['order_no'].' / '.$item['product_code'].' '.$part['qty'].'PCS';}$carton['items_text']=implode(' + ',$texts);}unset($carton);
                    $result=qo_create_shipment_locked($pdo,$legacy,false,true);$shipmentId=(int)$result['shipment_id'];
                }
                if($action==='reverse'){
                    if(!$shipmentId)throw new RuntimeException('缺少原出货记录，不能更正');
                    foreach(($validated['_commission_before']??[]) as $before){
                        $now=qo_row($pdo,'SELECT settle_status,settled_amount FROM quote_commission_snapshots WHERE id=? FOR UPDATE',[$before['id']]);
                        if(!$now||(float)$now['settled_amount']!==(float)$before['settled_amount']||!in_array($now['settle_status'],[$before['settle_status'],'pending'],true))throw new RuntimeException('关联佣金已有后续结算，请先由财务核对，未撤销出货');
                    }
                    $pdo->prepare('INSERT INTO quote_shipment_reversed_items SELECT * FROM quote_shipment_items WHERE shipment_id=?')->execute([$shipmentId]);
                    $pdo->prepare('INSERT INTO quote_shipment_reversed_cartons SELECT * FROM quote_shipment_cartons WHERE shipment_id=?')->execute([$shipmentId]);
                    $pdo->prepare('DELETE FROM quote_shipment_items WHERE shipment_id=?')->execute([$shipmentId]);
                    $pdo->prepare('DELETE FROM quote_shipment_cartons WHERE shipment_id=?')->execute([$shipmentId]);
                    $pdo->prepare("UPDATE quote_shipments SET status='已冲销',total_qty=0,total_cartons=0,total_nw=0,total_gw=0,total_cbm=0,updated_at=NOW() WHERE id=?")->execute([$shipmentId]);
                    qo_recalc_orders($pdo,array_column($validated['orders'],'id'));
                    foreach(($validated['_commission_before']??[]) as $before){
                        $order=qr_order($pdo,(int)$before['order_id']);
                        if(($order['shipment_status']??'')==='已出货'&&$before['settle_status']==='unsettled')continue;
                        $pdo->prepare('UPDATE quote_commission_snapshots SET settle_status=? WHERE id=?')->execute([$before['settle_status'],$before['id']]);
                    }
                    $validated['_reversed_shipment_ids'][]=$shipmentId;$shipmentId=null;
                    unset($validated['_commission_before']);
                }
                $pdo->prepare('UPDATE quote_shipment_plans SET state=?,version=?,shipment_id=?,data_json=?,updated_at=NOW() WHERE id=?')->execute([$state,$version,$shipmentId,qb_json($validated),$planId]);
                $pdo->prepare('DELETE FROM quote_shipment_plan_items WHERE plan_id=?')->execute([$planId]);
                if($state!=='cancelled')foreach($validated['items'] as $item)$pdo->prepare('INSERT INTO quote_shipment_plan_items(plan_id,order_id,order_item_id,qty) VALUES(?,?,?,?)')->execute([$planId,$item['order_id'],$item['order_item_id'],$item['qty']]);
                $pdo->prepare('INSERT INTO quote_shipment_plan_events(plan_id,version,action,actor,reason,request_id,request_hash,data_json,created_at) VALUES(?,?,?,?,?,?,?,?,NOW())')->execute([$planId,$version,$action,qo_actor(),$reason,$request,$hash,qb_json($validated)]);
                if(in_array($action,['issue','dispatch'],true))$pdo->prepare('INSERT INTO quote_shipment_plan_documents(plan_id,version,data_json,actor,created_at) VALUES(?,?,?,?,NOW())')->execute([$planId,$version,qb_json($validated),qo_actor()]);
                $pdo->commit();return qb_get($pdo,$planId);
            }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
        });
    }finally{$pdo->query('SELECT RELEASE_LOCK('.$pdo->quote($lock).')');}
}
