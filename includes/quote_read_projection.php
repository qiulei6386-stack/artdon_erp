<?php
/** SQL projections: large historical payloads never enter overview/preparation memory. */
function qr_columns(PDO $pdo, string $table, array $exclude=[], string $alias=''): string {
  if(!in_array($table,['quote_sales_orders','quote_sales_order_items','quote_shipment_items'],true)) throw new InvalidArgumentException('Invalid projection table');
  if($alias!=='' && !preg_match('/^[a-z][a-z0-9_]*$/iD',$alias)) throw new InvalidArgumentException('Invalid projection alias');
  static $cache=[];
  $key=spl_object_id($pdo).':'.$table;
  if(!isset($cache[$key])) $cache[$key]=$pdo->query('SHOW COLUMNS FROM `'.$table.'`')->fetchAll(PDO::FETCH_COLUMN);
  $prefix=$alias!==''?'`'.$alias.'`.':'';
  return implode(',',array_map(static function($c)use($prefix){return $prefix.'`'.$c.'`';},array_values(array_diff($cache[$key],$exclude))));
}
function qr_order_columns(PDO $pdo, string $alias=''): string {
  return qr_columns($pdo,'quote_sales_orders',['items_json','snapshot_json'],$alias);
}
function qr_with_order_locks(PDO $pdo, array $ids, callable $operation) {
  $ids=array_values(array_unique(array_filter(array_map('intval',$ids),static function($id){return $id>0;})));sort($ids,SORT_NUMERIC);
  $locked=[];
  try{
    foreach($ids as $id){
      $name='quote-shipment-order:'.$id;
      $st=$pdo->prepare('SELECT GET_LOCK(?,5)');$st->execute([$name]);
      if((int)$st->fetchColumn()!==1)throw new RuntimeException('该订单正在处理出货，请稍后核对后重试');
      $locked[]=$name;
    }
    return $operation();
  }finally{
    foreach(array_reverse($locked) as $name){$st=$pdo->prepare('SELECT RELEASE_LOCK(?)');$st->execute([$name]);}
  }
}
function qr_item_columns(PDO $pdo, string $table='quote_sales_order_items', string $alias='', bool $document=false, bool $documentImages=true): string {
  $columns=qr_columns($pdo,$table,['image','item_json'],$alias);
  $p=$alias!==''?'`'.$alias.'`.':'';
  // Keep the exact flags and text used by virtual-line validation, not product images/BOMs.
  $json="IF(JSON_VALID({$p}item_json),{$p}item_json,'{}')";
  $fields=['is_virtual_item','item_type','product_type','virtual_type','shippable','count_in_qty','unit','name','title','description','extra_spec'];
  if($document)$fields=array_merge($fields,['size','dimension','dimensions','quote_display_size','drawing_size','dim_size','product_size','manufacturer_code','factory_model','factoryModel','model','code','customer_model','customerModel','client_model','clientModel','hs_code']);
  $pairs=[];
  foreach($fields as $f) $pairs[]="'{$f}',JSON_EXTRACT({$json},'$.{$f}')";
  $product=[];
  $productFields=['code','model','model_no','product_code','name','product_name','title','specification','description'];
  if($document)$productFields=array_merge($productFields,['quote_display_size','size','dimension','dimensions','dim_size','product_size','factory_model','customer_code','customer_model','client_model','color','hs_code']);
  foreach($productFields as $f) $product[]="'{$f}',JSON_EXTRACT({$json},'$.product.{$f}')";
  $pairs[]="'product',JSON_OBJECT(".implode(',',$product).")";
  $result=$columns.',JSON_OBJECT('.implode(',',$pairs).') AS item_json';
  $result.=",(COALESCE(NULLIF({$p}image,''),NULLIF(JSON_UNQUOTE(JSON_EXTRACT({$json},'$.product.image')),'null'),'')<>'') AS has_image";
  if($document&&$documentImages){
    $images=["NULLIF({$p}image,'')"];
    foreach(['image','product_image','image_url','product.image','product.image_display','product.product_image','product.main_image','product.image_url'] as $path)$images[]="NULLIF(NULLIF(JSON_UNQUOTE(JSON_EXTRACT({$json},'$.{$path}')),'null'),'')";
    $result.=',COALESCE('.implode(',',$images).",'') AS image";
  }
  return $result;
}
function qr_document_order(PDO $pdo, int $id): array {
  $order=qr_order($pdo,$id);if(!$order)return [];
  $json="IF(JSON_VALID(snapshot_json),snapshot_json,'{}')";$pairs=[];
  foreach(['customer','header','bank','template'] as $f)$pairs[]="'{$f}',JSON_EXTRACT({$json},'$.{$f}')";
  $st=$pdo->prepare('SELECT JSON_OBJECT('.implode(',',$pairs).') FROM quote_sales_orders WHERE id=?');$st->execute([$id]);$order['snapshot_json']=$st->fetchColumn();
  return $order;
}
function qr_order(PDO $pdo, int $id): array {
  $st=$pdo->prepare('SELECT '.qr_order_columns($pdo).' FROM quote_sales_orders WHERE id=? LIMIT 1');
  $st->execute([$id]);return $st->fetch(PDO::FETCH_ASSOC)?:[];
}
function qr_payment(PDO $pdo, array $order): array {
  $st=$pdo->prepare('SELECT COALESCE(SUM(amount),0) paid,COALESCE(SUM(commission_deduct_amount),0) deduct,COALESCE(SUM(writeoff_amount),0) writeoff FROM quote_order_payments WHERE order_id=?');
  $st->execute([(int)$order['id']]);$r=$st->fetch(PDO::FETCH_ASSOC);
  $reduced=(float)$r['paid']+(float)$r['deduct']+(float)$r['writeoff'];
  $balance=max(0,round((float)$order['amount']-$reduced,2));
  return ['order_amount'=>(float)$order['amount'],'paid_amount'=>(float)$r['paid'],'commission_deduct_amount'=>(float)$r['deduct'],'writeoff_amount'=>(float)$r['writeoff'],'receivable_reduced'=>$reduced,'balance_amount'=>$balance,'payment_status'=>$reduced<=0?'未收款':($balance<=0.00001?'已收齐':'部分收款'),'currency'=>$order['currency']??'USD'];
}
/** Copy originals inside MySQL, without PHP buffering or re-encoding historical images. */
function qr_copy_shipment_payload(PDO $pdo, int $shipmentItemId, int $orderItemId): void {
  if(!$pdo->inTransaction()) throw new RuntimeException('出货快照复制必须在事务内执行');
  $st=$pdo->prepare('UPDATE quote_shipment_items s JOIN quote_sales_order_items i ON i.id=s.order_item_id AND i.order_id=s.order_id SET s.image=i.image,s.item_json=i.item_json WHERE s.id=? AND i.id=?');
  $st->execute([$shipmentItemId,$orderItemId]);
}
/** Auth is performed by the order API before calling this; no filesystem/network fetch. */
function qr_send_item_image(PDO $pdo, int $id): void {
  $expr="COALESCE(NULLIF(image,''),JSON_UNQUOTE(JSON_EXTRACT(IF(JSON_VALID(item_json),item_json,'{}'),'$.product.image')),'')";
  $st=$pdo->prepare('SELECT LEFT('.$expr.',512) prefix,OCTET_LENGTH('.$expr.') bytes FROM quote_sales_order_items WHERE id=?');
  $st->execute([$id]);$row=$st->fetch(PDO::FETCH_ASSOC);$st->closeCursor();
  $prefix=$row['prefix']??'';
  header('X-Content-Type-Options: nosniff');header('Cache-Control: private, max-age=60');
  if(preg_match('#^data:image/(png|jpeg|jpg|gif|webp);base64,#i',$prefix,$m)){
    header('Content-Type: image/'.(strtolower($m[1])==='jpg'?'jpeg':strtolower($m[1])));
    $offset=strlen($m[0])+1;$length=(int)$row['bytes'];
    $chunk=$pdo->prepare('SELECT SUBSTRING('.$expr.',?,65536) FROM quote_sales_order_items WHERE id=?');
    while($offset<=$length){$chunk->execute([$offset,$id]);$encoded=$chunk->fetchColumn();$chunk->closeCursor();$decoded=base64_decode($encoded,true);if($decoded===false)break;echo $decoded;$offset+=65536;}
    return;
  }
  // Only a short, whole URL may be redirected; never a truncated data URI or executable scheme.
  if(strlen($prefix)===(int)($row['bytes']??0) && !preg_match('/[\r\n]/',$prefix) && preg_match('#^(https?://|/(?!/)|(?:\.\./)*(?:uploads?|assets|images|files)/)#i',$prefix)){
    header('Location: '.$prefix, true,302);return;
  }
  http_response_code(404);header('Content-Type: text/plain; charset=utf-8');echo '图片暂不可用';
}
