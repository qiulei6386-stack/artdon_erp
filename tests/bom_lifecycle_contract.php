<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/includes/bom_workflow.php';
function lifecycle_check(bool $ok,string $why): void {if(!$ok)throw new RuntimeException($why);}
function lifecycle_publication(string $uid,float $cost,string $stage='preliminary',string $binding=''): array {
    return ['project_uid'=>$uid,'snapshot_id'=>null,'source'=>$stage==='approved'?'final_reference':'preliminary_reference','cost'=>$cost,'updated_at'=>'2026-01-01 00:00:00','payload_json'=>bw_json(['model'=>'57.99999','reference_id'=>1,'stage'=>$stage,'quote_eligible'=>true,'linked_system'=>'NAMING','linked_id'=>$binding])];
}
$map=[];bcp_add_publication($map,lifecycle_publication('A',90));bcp_add_publication($map,lifecycle_publication('B',10,'approved'));
$match=bcp_find(['57.99999'],$map)[1];lifecycle_check($match['ambiguous']&&$match['cost_rmb']===0.0&&count($match['candidates'])===2,'Multiple BOMs require selection instead of maximum/final preference');
$map=[];bcp_add_publication($map,lifecycle_publication('A',10,'preliminary','123'));bcp_add_publication($map,lifecycle_publication('B',90,'approved','456'));
$match=bcp_find(['NID123','57.99999'],$map)[1];lifecycle_check(!$match['ambiguous']&&$match['cost_rmb']===10.0,'Unique explicit naming binding has priority over ambiguous model');
$map=[];bcp_add_publication($map,lifecycle_publication('A',10));bcp_add_publication($map,lifecycle_publication('A',5));
lifecycle_check(count($map['57.99999']['candidates'])===1&&$map['57.99999']['cost_rmb']===5.0,'Same project latest reference can decrease');
$p=lifecycle_publication('A',0);$map=[];bcp_add_publication($map,$p);lifecycle_check(bcp_find(['57.99999'],$map)[1]['cost_rmb']===0.0,'Explicit published zero retained');
$p['source']='draft_unpublished';$map=[];bcp_add_publication($map,$p);lifecycle_check($map===[],'Draft publication excluded');
$p=lifecycle_publication('A',10);$m=json_decode($p['payload_json'],true);$m['quote_eligible']=false;$p['payload_json']=bw_json($m);$map=[];bcp_add_publication($map,$p);lifecycle_check($map===[],'Unconverted foreign currency cannot masquerade as RMB');
echo "Lifecycle cost mapping: explicit choice, exact binding, lower revision, zero, draft and currency exclusion passed\n";

if(!function_exists('mb_strtolower')){function mb_strtolower($v,$encoding='UTF-8'){return strtolower($v);}}
$m=['name'=>'Driver','brand'=>'Synthetic','model'=>'M10','spec'=>'24V 30W','unit'=>'PCS'];
lifecycle_check(bl_identity_matches(['name'=>'Synthetic / Driver','spec'=>'M10 / 24V 30W'],$m),'Existing picker display aliases recognized for the same ID');
lifecycle_check(!bl_identity_matches(['name'=>'Synthetic / Driver','spec'=>'M10 / 24V 20W'],$m),'Different electrical specification is never a display alias');
lifecycle_check(!bl_identity_matches(['name'=>'Driver','spec'=>'M10'],$m),'Missing detailed specification is not accepted');
lifecycle_check(!bl_identity_matches(['name'=>'Driver','spec'=>'24V 30W','unit'=>'KG'],$m),'Conflicting units require verification');
echo "Material display identity: exact picker format recognized; differing spec and units held\n";
