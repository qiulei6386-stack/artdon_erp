<?php
$root=dirname(__DIR__);
$api=file_get_contents($root.'/quote_order_api.php');
$checks=[
  'tail carton allocation helper exists'=>strpos($api,'function qo_tail_carton_allocations_for_order')!==false,
  'tail carton parses split pcs expression'=>strpos($api,"preg_split('/\\s*\\+\\s*/")!==false,
  'item shipped includes tail allocation'=>strpos($api,'$sh+=qo_num($tailAlloc[(int)$it[\'id\']]??0);')!==false,
  'order list reads shipped_qty from order items'=>strpos($api,'SUM(shipped_qty) AS shipped_qty FROM quote_sales_order_items')!==false,
];
$failed=[];
foreach($checks as $name=>$ok){ if(!$ok) $failed[]=$name; }
if($failed){
  fwrite(STDERR,"Tail carton allocation contract failed:\n- ".implode("\n- ",$failed)."\n");
  exit(1);
}
echo "Tail carton allocation contract passed (".count($checks)." checks)\n";
