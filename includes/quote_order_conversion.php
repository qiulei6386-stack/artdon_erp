<?php
function qo_detail_order(PDO $pdo, int $id): array {
  $buffered=$pdo->getAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY);
  $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY,false);
  $stmt=null;
  try {
    $stmt=$pdo->prepare('SELECT * FROM quote_sales_orders WHERE id=? LIMIT 1');
    $stmt->execute([$id]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
  } finally {
    if($stmt) $stmt->closeCursor();
    $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY,$buffered);
  }
}

/** Avoid buffering the entire image-heavy result a second time inside PDO. */
function qo_detail_items(PDO $pdo, int $id): array {
  $buffered=$pdo->getAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY);
  $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY,false);
  $stmt=null;
  try {
    $stmt=$pdo->prepare('SELECT * FROM quote_sales_order_items WHERE order_id=? ORDER BY item_index,id');
    $stmt->execute([$id]);$items=[];
    while($row=$stmt->fetch(PDO::FETCH_ASSOC)) $items[]=$row;
    return $items;
  } finally {
    if($stmt) $stmt->closeCursor();
    $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY,$buffered);
  }
}

/** Emit large detail responses without allocating a second full JSON document. */
function qo_write_json($value, ?callable $emit=null): void {
  if($emit===null) $emit=static function($part){echo $part;};
  $flags=JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES;
  if(is_array($value)){
    $list=$value===[] || array_keys($value)===range(0,count($value)-1);
    $emit($list?'[':'{');$first=true;
    foreach($value as $key=>$child){
      if(!$first) $emit(',');$first=false;
      if(!$list) $emit(json_encode((string)$key,$flags).':');
      qo_write_json($child,$emit);
    }
    $emit($list?']':'}');return;
  }
  if(is_string($value) && strlen($value)>262144){
    $emit('"');$length=strlen($value);
    for($offset=0;$offset<$length;){
      $end=min($offset+262144,$length);
      while($end<$length && (ord($value[$end]) & 0xC0)===0x80) $end--;
      if($end<=$offset) throw new RuntimeException('订单包含无效文本');
      $part=json_encode(substr($value,$offset,$end-$offset),$flags);
      if($part===false) throw new RuntimeException('订单包含无效文本');
      $emit(substr($part,1,-1));$offset=$end;
    }
    $emit('"');return;
  }
  $part=json_encode($value,$flags);
  if($part===false) throw new RuntimeException('订单包含无效数据');
  $emit($part);
}

/** Keep each client packet bounded; PDO MySQL buffers even stream/LOB parameters. */
function qo_insert_chunked(PDO $pdo, string $table, string $sql, array $params): int {
  $maps=[
    'quote_sales_orders'=>[5=>'customer_json',6=>'header_json',7=>'bank_json',8=>'template_json',9=>'items_json',10=>'snapshot_json'],
    'quote_sales_order_items'=>[11=>'image',12=>'item_json'],
  ];
  if(!isset($maps[$table]) || !$pdo->inTransaction()) throw new RuntimeException('订单写入必须在事务内执行');
  $large=[];
  foreach($maps[$table] as $index=>$column){
    if(is_string($params[$index]) && strlen($params[$index])>524288){
      $large[$column]=$params[$index];$params[$index]='';
    }
  }
  $pdo->prepare($sql)->execute($params);
  $id=(int)$pdo->lastInsertId();
  foreach($large as $column=>$value){
    $stmt=$pdo->prepare('UPDATE `'.$table.'` SET `'.$column.'`=CONCAT(COALESCE(`'.$column.'`,\'\'),?) WHERE id=?');
    $length=strlen($value);
    for($offset=0;$offset<$length;){
      $end=min($offset+4194304,$length);
      // Never split a UTF-8 code point across SQL string parameters.
      while($end<$length && (ord($value[$end]) & 0xC0)===0x80) $end--;
      if($end<=$offset) throw new RuntimeException('订单包含无效文本');
      $stmt->execute([substr($value,$offset,$end-$offset),$id]);
      $offset=$end;
    }
  }
  return $id;
}

function qo_convert_order(PDO $pdo, array &$d): array {
  $orderNo=qo_order_no_at(qo_s($d['order_no']??($d['quote_no']??''),120),qo_s($d['quote_no']??'',120));
  if($orderNo==='') throw new RuntimeException('缺少订单号');
  $items=qo_order_items_from_payload($d['items_json']??'[]');
  if(!$items) throw new RuntimeException('订单没有产品明细');
  $customerJson=(string)($d['customer_json']??'{}');
  $custName=qo_s($d['customer_name']??qo_customer_name($customerJson),255);
  $qty=0; foreach($items as $it) $qty+=qo_item_qty_for_product_total($it);
  if($qty<=0) $qty=qo_num($d['qty']??0);
  $amount=qo_num($d['amount']??0);
  if($amount<=0) foreach($items as $it) $amount+=qo_num($it['amount']??(qo_num($it['qty']??0)*qo_num($it['price']??$it['unit_price']??0)));
  unset($it);
  // Schema setup must precede the transaction: MySQL DDL implicitly commits.
  qo_ensure_schema($pdo);
  qo_commission_schema($pdo);
  $hasQuotes=qo_table_exists($pdo,'quote_orders');
  if($hasQuotes){
    qo_ensure_col($pdo,'quote_orders','converted_order_id','INT DEFAULT 0');
    qo_ensure_col($pdo,'quote_orders','converted_order_no',"VARCHAR(120) DEFAULT ''");
  }
  $lock='quote-convert:'.substr(hash('sha256',function_exists('mb_strtolower')?mb_strtolower($orderNo,'UTF-8'):strtolower($orderNo)),0,48);
  if((int)qo_row($pdo,'SELECT GET_LOCK(?,5) AS acquired',[$lock])['acquired']!==1) throw new RuntimeException('此订单正在生成，请稍后到订单中心核对');
  try {
    $pdo->beginTransaction();
    $exist=qo_row($pdo,"SELECT id FROM quote_sales_orders WHERE order_no=? AND COALESCE(status,'') NOT IN ('已作废','取消') LIMIT 1 FOR UPDATE",[$orderNo]);
    if($exist) throw new RuntimeException('订单号已存在，请在订单中心核对；未覆盖原订单：'.$orderNo);
    $sql='INSERT INTO quote_sales_orders(order_no,quote_no,source_quote_id,customer_id,customer_name,customer_json,header_json,bank_json,template_json,items_json,snapshot_json,qty,amount,currency,exchange_rate,quote_date,order_date,status,shipment_status,payment_status,paid_amount,balance_amount,order_doc_title,contract_title,note,user_name,created_by,created_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())';
    unset($d['items_json']);
    $encoded=json_encode($items,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    if($encoded===false) throw new RuntimeException('产品明细格式无效');
    $id=qo_insert_chunked($pdo,'quote_sales_orders',$sql,[$orderNo,qo_s($d['quote_no']??'',120),(int)($d['quote_id']??$d['source_quote_id']??0),qo_s($d['customer_id']??'',120),$custName,$customerJson,(string)($d['header_json']??''),(string)($d['bank_json']??''),(string)($d['template_json']??''),$encoded,(string)($d['snapshot_json']??''),$qty,$amount,qo_s($d['currency']??'USD',20),qo_num($d['exchange_rate']??1),qo_s($d['quote_date']??'',20)?:null,qo_s($d['order_date']??qo_today(),20)?:qo_today(),qo_s($d['status']??'待确认',80),'未出货','未收款',0,$amount,qo_s($d['order_doc_title']??$d['quote_status']??'',120),qo_s($d['contract_title']??$d['quote_status']??'',120),qo_s($d['note']??'',5000),qo_actor(),qo_actor()]);
    unset($encoded,$d['snapshot_json']);
    $itemSql='INSERT INTO quote_sales_order_items(order_id,item_index,customer_code,product_code,product_name,specification,color,qty,unit_price,amount,shipped_qty,image,item_json) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?)';
    foreach($items as $i=>$it){
      $row=qo_item_row($it,$i+1);
      if($row['item_json']===false) throw new RuntimeException('产品明细格式无效');
      qo_insert_chunked($pdo,'quote_sales_order_items',$itemSql,[$id,$row['item_index'],$row['customer_code'],$row['product_code'],$row['product_name'],$row['specification'],$row['color'],$row['qty'],$row['unit_price'],$row['amount'],0,$row['image'],$row['item_json']]);
    }
    unset($row,$it);
    qo_recalc_payment($pdo,$id);
    if(($d['commission_choice']??'')!=='none'){
      if(($d['commission_choice']??'')==='apply'){
        if(!qo_apply_conversion_commission($pdo,$id,array_merge($d,['order_no'=>$orderNo,'customer_name'=>$custName]),$amount,$qty)) throw new RuntimeException('佣金信息无效，请重新确认');
      }else qo_freeze_commission($pdo,$id,array_merge($d,['order_no'=>$orderNo,'customer_name'=>$custName]),$items,$amount,$qty);
    }
    unset($items);
    if($hasQuotes && !empty($d['quote_no'])) $pdo->prepare('UPDATE quote_orders SET converted_order_id=?,converted_order_no=? WHERE quote_no=?')->execute([$id,$orderNo,qo_s($d['quote_no'],120)]);
    $pdo->commit();
  } catch(Throwable $e){
    if($pdo->inTransaction()) $pdo->rollBack();
    throw $e;
  } finally {
    try { qo_row($pdo,'SELECT RELEASE_LOCK(?) AS released',[$lock]); } catch(Throwable $ignored) {}
  }
  // Auxiliary notifications cannot make a committed order appear to have failed.
  try {
    $orderRow=qo_row($pdo,'SELECT id,order_no,quote_no,source_quote_id,customer_id,customer_name,user_name,created_by,updated_by FROM quote_sales_orders WHERE id=?',[$id]) ?: [];
    qo_push_quote_sys_notification($pdo,'quote_order_converted','报价已转订单：'.$orderNo,trim('客户：'.$custName."\n订单号：".$orderNo."\n来源报价：".qo_s($d['quote_no']??'',120)."\n金额：".qo_s($d['currency']??'USD',20).' '.number_format($amount,2)."\n数量：".$qty),[
      'source_module'=>'quote_sales_orders','source_id'=>(string)$id,'target_id'=>(string)$id,'target_url'=>'quotation.php?order_id='.$id,
      'related_quote_id'=>(string)(qo_s($d['quote_no']??'',120) ?: ($d['quote_id']??'')),'related_customer_id'=>(int)($d['customer_id']??0),
      'order_id'=>$id,'order_no'=>$orderNo,'quote_no'=>qo_s($d['quote_no']??'',120),'customer_name'=>$custName,'currency'=>qo_s($d['currency']??'USD',20),'amount'=>$amount,'dedupe_key'=>'quote:quote_order_converted:'.$id,
    ],$orderRow);
    qo_complete_quote_followup_tasks($pdo,$orderRow);
  } catch(Throwable $e){ error_log('quote conversion auxiliary notification failed; order_id='.$id); }
  return ['id'=>$id,'order_no'=>$orderNo];
}
