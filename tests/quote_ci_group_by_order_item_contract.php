<?php
$root=dirname(__DIR__);
$doc=file_get_contents($root.'/quote_order_doc.php');
$excel=file_get_contents($root.'/quote_order_excel.php');

$checks=[
  'CI grouping helper exists'=>strpos($doc,'function qd_build_ci_items($items)')!==false,
  'CI group key prefers order item id'=>strpos($doc,"return 'order_item:'.\$oid")!==false,
  'CI fallback keeps shipment batch item identity'=>strpos($doc,"'item_index:'.\$idx.'|customer:'.\$customer.'|product:'.\$product")!==false,
  'CI grouping sums current shipment quantity'=>strpos($doc,"\$groups[\$key]['qty']=qd_num(\$groups[\$key]['qty']??0)+\$qty")!==false,
  'CI grouping sums current shipment amount'=>strpos($doc,"\$groups[\$key]['amount']=qd_num(\$groups[\$key]['amount']??0)+\$amount")!==false,
  'CI rows clear packing-only fields'=>strpos($doc,"foreach(['pcs_per_ctn','cartons','carton_size','nw','gw','cbm'")!==false,
  'document builds separate CI rows'=>strpos($doc,'$ciItems=qd_build_ci_items($items);')!==false,
  'HTML CI renders grouped rows'=>strpos($doc,'foreach($ciItems as $i=>$it)')!==false,
  'HTML CI totals grouped rows'=>strpos($doc,"qd_total(\$ciItems,'qty')")!==false && strpos($doc,"qd_total(\$ciItems,'amount')")!==false,
  'Excel CI receives grouped rows'=>strpos($doc,"qoe_export_document_xlsx(\$type,\$order,\$ship,\$type==='ci'?\$ciItems:\$items")!==false,
  'PL still expands carton detail rows'=>strpos($doc,"\$plItems=\$type==='pl'?array_merge(\$items,qd_carton_pl_rows(\$cartons,\$items)):\$items")!==false,
  'Excel PL still merges carton detail rows'=>strpos($excel,'$plItems=function_exists(\'qd_carton_pl_rows\')?array_merge($items,qd_carton_pl_rows($cartons,$items)):$items;')!==false,
];

$failed=[];
foreach($checks as $label=>$ok){ if(!$ok)$failed[]=$label; }
if($failed){
  fwrite(STDERR,"Quote CI group-by-order-item contract failed:\n- ".implode("\n- ",$failed)."\n");
  exit(1);
}
echo "Quote CI group-by-order-item contract passed (".count($checks)." checks)\n";
