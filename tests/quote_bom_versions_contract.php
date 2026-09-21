<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/includes/quote_bom_versions.php';
// Static PHP runs without extensions; fixtures use ASCII brand names and uncased Chinese.
if(!function_exists('mb_strtolower')){function mb_strtolower($s,$encoding='UTF-8'){return strtolower($s);}}
function qbvCheck($ok,$msg){if(!$ok)throw new RuntimeException($msg);}
$api=file_get_contents(dirname(__DIR__).'/quote_api.php');
$start=strpos($api,'function qspec_blank(');$end=strpos($api,'function qspec_guess_components_from_bom(',$start);
eval(substr($api,$start,$end-$start));
$parts=qbv_components(array(array('name'=>'LIFUD LED Driver LF-TEST','brand'=>'LIFUD','model'=>'LF-TEST'),array('name'=>'Packing carton'),array('name'=>'M3 screw')));
qbvCheck(isset($parts['driver'])&&!isset($parts['accessories']),'Snapshot component classifier excludes packaging/fasteners');
qbvCheck(qbv_matches(array('code'=>'52.07523'),array('model'=>'52.07523')),'exact model');
qbvCheck(!qbv_matches(array('code'=>'52.07523'),array('model'=>'52.075231')),'not a fuzzy match');
$s=array('id'=>8,'project_uid'=>'SYNTHETIC','snapshot_uid'=>'SNAPSHOT-8','snapshot_name'=>'Test','model'=>'52.07523','customer'=>'Acceptance','version_no'=>'V2','variant_label'=>'Test','totals_json'=>'{"total":0}','approved_at'=>'2026-01-02 10:00:00','created_at'=>'2026-01-02 09:00:00');
$m=qbv_meta($s,array('snapshot_id'=>8,'source'=>'approved_snapshot','updated_at'=>'2026-01-02 10:01:00'));
qbvCheck($m['cost_rmb']===0.0&&$m['current']&&$m['approved_at']!==$m['published_at'],'Zero is valid, separate audit/publication time');
$m=qbv_meta($s,array('snapshot_id'=>9,'source'=>'approved_snapshot','updated_at'=>'2026-01-03'));
qbvCheck(!$m['current']&&$m['published_at']==='','Historical label');
$module=file_get_contents(dirname(__DIR__).'/includes/quote_bom_versions.php');
qbvCheck(strpos($module,'SELECT *')===false,'No full row/image queries');
qbvCheck(strpos($module,'LIMIT $size OFFSET $offset')!==false,'Paged metadata');
foreach(array("'bom_quote_versions'=>'product_view'","'bom_quote_version'=>'product_view'",'qbv_validate_save($pdo,$d,$before)') as $marker)qbvCheck(strpos($api,$marker)!==false,'API integration '.$marker);
$bomApi=file_get_contents(dirname(__DIR__).'/bom_api.php');
qbvCheck(substr_count($bomApi,"['void_snapshot']='unapprove_bom'")===2,'Void permission enforced in both route gates');
$saveStart=strpos($api,"if(\$action==='save_quote')");$save=substr($api,$saveStart);
qbvCheck(strpos($save,'if($bomWorkflowReady)')<strpos($save,'qbv_validate_save($pdo,$d,$before)'),'Publication lock before save validation');
$workflow=file_get_contents(dirname(__DIR__).'/includes/bom_workflow.php');
qbvCheck(strpos($workflow,'DELETE FROM bom_snapshots')===false&&strpos($workflow,'UPDATE bom_snapshots')===false,'Snapshot bodies remain immutable');
echo "Quote BOM versions: exact model, classifier, bounded reads, void permission, publication lock and immutable history passed\n";
