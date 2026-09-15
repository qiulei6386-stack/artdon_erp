<?php
// Read projections only. Schema installation is an explicit CLI release step.
function op_virtual_expression(): string {
    $hay="LOWER(CONCAT_WS(' ',COALESCE(product_code,''),COALESCE(product_name,''),COALESCE(specification,''),COALESCE(item_json,'')))";
    $json="LOWER(COALESCE(item_json,''))";
    $likes=[];
    foreach(['shipping cost','shipping costs','freight','freight cost','freight charge','shipping fee','delivery fee','delivery cost','courier fee','运费','运输费','快递费','物流费','费用项'] as $needle)$likes[]=$hay." LIKE '%".$needle."%'";
    foreach(['"is_virtual_item":true','"item_type":"virtual"','"product_type":"virtual"','"shippable":false'] as $needle)$likes[]=$json." LIKE '%".$needle."%'";
    return '('.implode(' OR ',$likes).')';
}
function op_install(PDO $pdo): void {
    if(PHP_SAPI!=='cli')throw new RuntimeException('仅允许发布时初始化');
    $pdo->exec('SET SESSION lock_wait_timeout=10');
    $col=$pdo->query("SHOW COLUMNS FROM quote_sales_order_items LIKE 'qo_virtual_line_v1'")->fetch(PDO::FETCH_ASSOC);
    if($col){if(stripos($col['Extra'],'STORED GENERATED')===false)throw new RuntimeException('订单轻量标记结构不符');return;}
    // Exact legacy classification; generated values remain correct after every writer.
    $pdo->exec('ALTER TABLE quote_sales_order_items ADD COLUMN qo_virtual_line_v1 TINYINT GENERATED ALWAYS AS ('.op_virtual_expression().') STORED, ADD KEY idx_op_qty_v1(order_id,qo_virtual_line_v1,qty,shipped_qty)');
}
function op_query(PDO $pdo,string $sql,array $args=[]): array {
    $s=$pdo->prepare($sql);$s->execute($args);return $s->fetchAll(PDO::FETCH_ASSOC);
}
function op_source(): string {
    $base="SELECT o.id,CASE WHEN LEFT(TRIM(COALESCE(NULLIF(o.order_no,''),o.quote_no)),3)='SO-' THEN CONCAT('AT-',SUBSTRING(TRIM(COALESCE(NULLIF(o.order_no,''),o.quote_no)),4)) ELSE TRIM(COALESCE(NULLIF(o.order_no,''),o.quote_no)) END order_no,o.quote_no,o.source_quote_id,o.customer_id,TRIM(COALESCE(o.customer_name,'')) customer_name,
      o.amount,o.currency,o.exchange_rate,o.quote_date,o.order_date,o.created_at,o.updated_at,o.user_name,o.created_by,o.updated_by,
      TRIM(COALESCE(NULLIF(o.user_name,''),NULLIF(o.created_by,''),o.updated_by,'')) owner_name,
      TRIM(COALESCE(o.status,'')) stored_status,
      CASE WHEN COALESCE(p.paid,0)<=0 AND o.paid_amount>0 THEN o.paid_amount ELSE COALESCE(p.paid,0) END paid_amount,
      COALESCE(p.deduct,0) commission_deduct_amount,COALESCE(p.writeoff,0) writeoff_amount,
      CASE WHEN s.qty>0 THEN s.qty ELSE o.qty END qty,
      CASE WHEN s.shipped>0 AND s.shipped+0.00001<s.qty THEN '部分出货'
        WHEN s.qty>0 AND s.shipped+0.00001>=s.qty THEN '已出货'
        ELSE COALESCE(NULLIF(TRIM(o.shipment_status),''),'未出货') END shipment_status
      FROM quote_sales_orders o
      LEFT JOIN (SELECT order_id,SUM(amount) paid,SUM(COALESCE(commission_deduct_amount,0)) deduct,SUM(COALESCE(writeoff_amount,0)) writeoff FROM quote_order_payments GROUP BY order_id) p ON p.order_id=o.id
      LEFT JOIN (SELECT order_id,SUM(IF(qo_virtual_line_v1,0,qty)) qty,SUM(IF(qo_virtual_line_v1,0,shipped_qty)) shipped FROM quote_sales_order_items GROUP BY order_id) s ON s.order_id=o.id";
    $balance="SELECT b.*,paid_amount+commission_deduct_amount+writeoff_amount receivable_reduced,
      GREATEST(0,ROUND(amount-paid_amount-commission_deduct_amount-writeoff_amount,2)) balance_amount FROM ($base) b";
    $payment="SELECT c.*,CASE WHEN receivable_reduced<=0 THEN '未收款' WHEN balance_amount<=0.00001 THEN '已收齐' ELSE '部分收款' END payment_status FROM ($balance) c";
    return "SELECT d.*,CASE WHEN stored_status IN ('取消','已作废') THEN stored_status
      WHEN shipment_status='已出货' AND payment_status='已收齐' THEN '已完结'
      WHEN stored_status='已完结' THEN CASE WHEN shipment_status IN ('已出货','部分出货') THEN shipment_status ELSE '已确认' END
      ELSE COALESCE(NULLIF(stored_status,''),'待确认') END status FROM ($payment) d";
}
function op_where(array $in,array &$args): string {
    $w=['1=1'];$args=[];
    foreach(['customer'=>'customer_name','owner'=>'owner_name','currency'=>'currency'] as $key=>$col){
        $v=trim((string)($in[$key]??''));if($v!==''){$w[]="$col=?";$args[]=$v;}
    }
    foreach(['from'=>'>=','to'=>'<='] as $key=>$operator){$v=trim((string)($in[$key]??''));if($v!==''){
        $date=DateTimeImmutable::createFromFormat('!Y-m-d',$v);
        if(!$date||$date->format('Y-m-d')!==$v)throw new InvalidArgumentException('日期格式不正确');
        $w[]="DATE(COALESCE(order_date,created_at)) $operator ?";$args[]=$v;
    }}
    $status=trim((string)($in['status']??''));if($status!==''){
        $w[]="(status=? OR shipment_status=? OR IF(balance_amount<=0,'已收齐',payment_status)=?)";array_push($args,$status,$status,$status);
    }
    $kw=trim((string)($in['search']??''));if(strlen($kw)>600)throw new InvalidArgumentException('搜索词过长');
    $tokens=preg_split('/[\s,，;；]+/u',$kw,-1,PREG_SPLIT_NO_EMPTY);
    foreach($tokens as $token){$w[]="LOWER(CONCAT_WS(' ',order_no,quote_no,customer_name,owner_name,currency,status)) LIKE ? ESCAPE '!'";$args[]='%'.str_replace(['!','%','_'],['!!','!%','!_'],strtolower($token)).'%';}
    return implode(' AND ',$w);
}
function op_finance(PDO $pdo,string $source,string $where='1=1',array $args=[]): array {
    return op_query($pdo,"SELECT COALESCE(NULLIF(UPPER(TRIM(currency)),''),'USD') currency,COUNT(*) count,
      SUM(amount) amount,SUM(paid_amount) paid_amount,SUM(balance_amount) balance_amount,
      SUM(CASE WHEN LOWER(status) NOT REGEXP '取消|作废|void|cancel' AND amount>0 THEN amount ELSE 0 END) revenue,
      SUM(shipment_status NOT REGEXP '已出货|已完成') no_ship FROM ($source) r WHERE $where GROUP BY currency",$args);
}
function op_page(PDO $pdo,array $in): array {
    $source=op_source();$args=[];$where=op_where($in,$args);
    // A consistent snapshot keeps count, money and page rows together during concurrent changes.
    $own=!$pdo->inTransaction();if($own)$pdo->exec('START TRANSACTION READ ONLY');
    try{
        $finance=op_finance($pdo,$source,$where,$args);$total=array_sum(array_column($finance,'count'));
        $size=(int)($in['size']??20);if(!in_array($size,[20,50],true))$size=20;
        $pages=max(1,(int)ceil($total/$size));$page=max(1,min($pages,(int)($in['page']??1)));$offset=($page-1)*$size;
        $sort=['new'=>'COALESCE(order_date,created_at) DESC,id DESC','amountDesc'=>'amount DESC,id DESC','amountAsc'=>'amount ASC,id DESC','customer'=>'customer_name ASC,id DESC'][$in['sort']??'new']??'COALESCE(order_date,created_at) DESC,id DESC';
        $rows=op_query($pdo,"SELECT * FROM ($source) r WHERE $where ORDER BY $sort LIMIT $size OFFSET $offset",$args);
        foreach($rows as &$row){if(function_exists('qo_order_no_at'))$row['order_no']=qo_order_no_at($row['order_no'],$row['quote_no']);unset($row['stored_status']);}unset($row);
        $result=['orders'=>$rows,'page'=>$page,'size'=>$size,'pages'=>$pages,'total'=>$total,'finance'=>$finance];
        if(!empty($in['overview']))$result['overview']=op_overview($pdo);
        if($own)$pdo->commit();return $result;
    }catch(Throwable $e){if($own&&$pdo->inTransaction())$pdo->rollBack();throw $e;}
}
function op_overview(PDO $pdo): array {
    $finance=op_finance($pdo,op_source());
    $options=op_query($pdo,"SELECT DISTINCT TRIM(COALESCE(customer_name,'')) customer,TRIM(COALESCE(NULLIF(user_name,''),NULLIF(created_by,''),updated_by,'')) owner FROM quote_sales_orders");
    $customers=[];$owners=[];foreach($options as $o){if($o['customer']!=='')$customers[$o['customer']]=true;if($o['owner']!=='')$owners[$o['owner']]=true;}
    $nos=$pdo->query("SELECT DISTINCT TRIM(quote_no) FROM quote_sales_orders WHERE TRIM(COALESCE(quote_no,''))<>''")->fetchAll(PDO::FETCH_COLUMN);
    $noDocs=(int)$pdo->query("SELECT COUNT(*) FROM quote_sales_orders o WHERE NOT EXISTS(SELECT 1 FROM quote_shipments s WHERE s.order_id=o.id AND (s.pl_generated_at IS NOT NULL OR s.ci_generated_at IS NOT NULL))")->fetchColumn();
    return ['finance'=>$finance,'count'=>array_sum(array_column($finance,'count')),'quote_nos'=>$nos,'no_docs'=>$noDocs,'customers'=>array_keys($customers),'owners'=>array_keys($owners)];
}
