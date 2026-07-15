<?php
/** Artdon Datasheet Center V2.0 Excel export */
declare(strict_types=1);
require_once __DIR__.'/datasheet_lib.php';
try{
    ds_init(); ds_require_perm('generate_excel','生成 Excel');
    $ids=[]; if(isset($_GET['ids'])){ foreach(explode(',',(string)$_GET['ids']) as $x){ $n=(int)$x; if($n>0)$ids[]=$n; } }
    if(!$ids && isset($_GET['id'])) $ids[]=(int)$_GET['id'];
    $models=[]; if(isset($_GET['model_no'])) $models[]=ds_s($_GET['model_no']);
    if(!$ids && !$models) throw new RuntimeException('缺少产品。');
    $products=[];
    foreach($ids as $id) $products[]=ds_product_detail($id,'');
    foreach($models as $m) $products[]=ds_product_detail(0,$m);
    $filename='Artdon_Datasheet_'.date('Ymd_His').'.xls';
    header('Content-Type: application/vnd.ms-excel; charset=utf-8');
    header('Content-Disposition: attachment; filename="'.$filename.'"');
    echo "\xEF\xBB\xBF";
}catch(Throwable $e){ http_response_code(500); echo ds_h($e->getMessage()); exit; }
?>
<html><head><meta charset="utf-8"><style>table{border-collapse:collapse}td,th{border:1px solid #999;padding:6px;font-family:Arial,"Microsoft YaHei";font-size:12px}th{background:#f1f5f9}img{max-width:120px;max-height:120px}</style></head><body>
<h2>Artdon Lighting Limited - Product Datasheet Export</h2>
<table><thead><tr><th>Source</th><th>Model</th><th>Series</th><th>Category</th><th>Type</th><th>Dimensions</th><th>Power</th><th>CCT</th><th>CRI</th><th>Beam Angle</th><th>IP</th><th>Finish</th><th>Mounting</th><th>Dimming</th><th>Product Image</th><th>Dimension Drawing</th><th>Files</th><th>Accessories</th><th>Remark</th></tr></thead><tbody>
<?php foreach($products as $p): $ov=$p['override']??array(); $files=array_map(fn($f)=>($f['file_type'].' - '.($f['file_title']?:$f['original_name'])), $p['files']??array()); $acc=array_map(fn($a)=>trim(($a['model_no']?($a['model_no'].' '):'').($a['name_en']?:$a['name_cn'])), $p['accessories']??array()); ?>
<tr>
<td><?=ds_h($p['source_label'])?></td><td><?=ds_h($p['model_no'])?></td><td><?=ds_h($p['series'])?></td><td><?=ds_h($p['category'])?></td><td><?=ds_h($p['type_name'])?></td><td><?=ds_h($p['dimension_text'])?></td><td><?=ds_h($ov['power']??'')?></td><td><?=ds_h($ov['cct']??'')?></td><td><?=ds_h($ov['cri']??'')?></td><td><?=ds_h($ov['beam_angle']??'')?></td><td><?=ds_h($ov['ip_rating']??'')?></td><td><?=ds_h($ov['finish']??'')?></td><td><?=ds_h($ov['mounting']??'')?></td><td><?=ds_h($ov['dimming']??'')?></td><td><?=ds_h($p['image_url'])?></td><td><?=ds_h($p['drawing_url'])?></td><td><?=ds_h(implode("\n",$files))?></td><td><?=ds_h(implode("\n",$acc))?></td><td><?=ds_h($ov['remark']??'')?></td>
</tr>
<?php endforeach; ?>
</tbody></table></body></html>
<?php
try{ foreach($products as $p){ ds_record_generated($p['model_no'],(int)$p['id'],'Excel','Excel导出','',$filename,'Excel downloaded'); ds_log('excel.export','导出Excel','success',$p['model_no'],(int)$p['id'],'excel','',$filename,array('count'=>count($products))); } }catch(Throwable $e){}
