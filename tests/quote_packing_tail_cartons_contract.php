<?php
$root=dirname(__DIR__);
$files=[
  'api'=>file_get_contents($root.'/quote_order_api.php'),
  'doc'=>file_get_contents($root.'/quote_order_doc.php'),
  'excel'=>file_get_contents($root.'/quote_order_excel.php'),
  'ui'=>file_get_contents($root.'/quotation.php'),
];
$checks=[
  'api helper adds carton detail totals'=>strpos($files['api'],'function qo_add_carton_detail_totals')!==false,
  'api create/update uses carton detail totals'=>substr_count($files['api'],'qo_add_carton_detail_totals($tot,$cartons)')>=2,
  'api carton detail totals include qty'=>strpos($files['api'],"foreach(['qty','cartons','nw','gw','cbm'] as \$k)")!==false,
  'doc converts carton detail into PL rows'=>strpos($files['doc'],'function qd_carton_pl_rows')!==false,
  'doc carton detail reuses shipment item descriptions'=>strpos($files['doc'],'qd_carton_pl_rows($cartons,$items')!==false,
  'doc carton package columns use rowspan'=>strpos($files['doc'],'_carton_skip_pack')!==false && strpos($files['doc'],'rowspan="<?=$span?>"')!==false,
  'doc has packing totals helper'=>strpos($files['doc'],'function qd_packing_total')!==false,
  'doc PL total row uses merged PL rows'=>strpos($files['doc'],"qd_total(\$plItems,'qty')")!==false && strpos($files['doc'],"qd_total(\$plItems,'cartons')")!==false,
  'excel PL uses merged PL rows'=>strpos($files['excel'],'$plItems=function_exists')!==false,
  'excel carton package columns are merged'=>strpos($files['excel'],'qoe_merge($merges,$mc,$r,$mc,$r+$span-1)')!==false,
  'PL output no longer renders carton detail box'=>strpos($files['doc'],'<b>Carton Detail</b>')===false && strpos($files['excel'],'Carton Detail')===false,
  'ui summary adds item and carton counts'=>strpos($files['ui'],'ctn+=num(x.carton_count)||1')!==false,
];
$failed=[];
foreach($checks as $name=>$ok){ if(!$ok) $failed[]=$name; }
if($failed){
  fwrite(STDERR,"Packing tail carton contract failed:\n- ".implode("\n- ",$failed)."\n");
  exit(1);
}
echo "Packing tail carton contract passed (".count($checks)." checks)\n";
