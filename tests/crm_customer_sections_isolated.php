<?php
declare(strict_types=1);
// Production section router; all record readers are spies. No app/config/database.
if (PHP_SAPI !== 'cli') exit(2);
function section_assert(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
$source=file_get_contents(dirname(__DIR__).'/crm_customer.php');
foreach (['crm_customer_deferred_summary','crm_customer_detail_section','crm_customer_quote_handoff'] as $name) {
    preg_match('/^function '.preg_quote($name,'/').'\(/m',$source,$match,PREG_OFFSET_CAPTURE);
    $start=$match[0][1];$end=strpos($source,"\nfunction ",$start+1);
    eval(substr($source,$start,$end-$start));
}
$calls=[];$permissions=true;
function section_read(string $name, $id): array { section_assert($id===71 || (is_array($id) && ($id['id']??0)===71),'Customer identity');$GLOBALS['calls'][]=$name;return ['total'=>2,'rows'=>[['id'=>2]]]; }
function crm_customer_quote_summary($c){return section_read('quote',$c);}
function crm_customer_bom_summary($c){return section_read('bom',$c);}
function crm_customer_order_summary($c){return section_read('orders',$c);}
function crm_customer_document_summary($c){return section_read('documents',$c);}
function crm_customer_shipment_summary($c){return section_read('shipments',$c);}
function crm_customer_receivable_summary($orders){section_assert($orders['total']===2,'Receivables need scoped orders');$GLOBALS['calls'][]='receivables';return ['balance_total'=>3];}
function crm_customer_chat_groups($id){return section_read('chat_groups',$id);}
function crm_followup_list($q){return section_read('followups',$q['customer_id']);}
function crm_visit_list($q){return section_read('visits',$q['customer_id']);}
function crm_opportunity_list($q){return section_read('opportunities',$q['customer_id']);}
function crm_customer_mail_rows($id){return section_read('mail',$id);}
function crm_customer_sample_shipments($id){return section_read('samples',$id);}
function crm_customer_timeline($id){return section_read('timeline',$id);}
function crm_customer_relations($id){return section_read('relations',$id);}
function crm_customer_events($id){return section_read('events',$id);}
function crm_customer_logs($id){return section_read('logs',$id);}
function crm_customer_preference_row($id,$table){return section_read($table,$id);}
function has_permission($key){return $GLOBALS['permissions'];}
$denied='';$quoteAllowed=true;
function crm_require($p){if($GLOBALS['denied']===$p)throw new RuntimeException('Denied');}
function crm_external_can($m,$c){return $GLOBALS['quoteAllowed'];}
function crm_opportunity_detail($id){if($id!==8)throw new RuntimeException('Unknown opportunity');return ['opportunity'=>['id'=>8,'customer_id'=>71,'opportunity_name'=>'Fixture project']];}
function crm_customer_get($id,$mode){section_assert($mode==='overview','Handoff must not load full linkage');if($id!==71)throw new RuntimeException('No customer scope');return ['customer'=>['id'=>71,'customer_name'=>'Fixture','email'=>'private@example.invalid','private_notes'=>'must not disclose']];}
$count=0;
foreach (['quote','bom','orders','documents','shipments','chat_groups','followups','visits','opportunities','mail','samples','timeline','relations','events','logs'] as $tab) {
    $calls=[];$result=crm_customer_detail_section(71,['id'=>71],$tab);
    section_assert($calls===[$tab],'Tab must not load unrelated sections: '.$tab);
    section_assert($result['customer']['id']===71 && $result['_loaded_tabs']===[$tab] && $result['_partial_detail']===1,'Partial identity/contract');$count++;
}
$calls=[];crm_customer_detail_section(71,['id'=>71],'receivables');section_assert($calls===['orders','receivables'],'Receivables only necessary dependencies');
$calls=[];$result=crm_customer_detail_section(71,['id'=>71],'communication_all');section_assert($calls===['followups','visits','mail'] && count($result['_loaded_tabs'])===4,'Communication composition');
$calls=[];$permissions=false;crm_customer_detail_section(71,['id'=>71],'visits');crm_customer_detail_section(71,['id'=>71],'opportunities');section_assert($calls===[],'Denied modules cannot load records');
$calls=[];foreach(['overview','customer_attribute','contacts','addresses','tags'] as $tab)crm_customer_detail_section(71,['id'=>71],$tab);section_assert($calls===[],'Already-loaded fields need no extra readers');
$thrown=false;try{crm_customer_detail_section(71,['id'=>71],'invalid');}catch(RuntimeException $e){$thrown=true;}section_assert($thrown,'Unknown section rejected');
$summary=crm_customer_deferred_summary(3);section_assert($summary['contacts']['value']==='3 个' && $summary['orders']['value']==='点击查看','Deferred totals must not pretend zero');
section_assert(strpos($source,"if (strpos(\$detailMode, 'tab:') === 0)")>strpos($source,"if (!\$customer) throw new RuntimeException('客户不存在或无权查看。');"),'Scope check precedes section');
section_assert(strpos($source,'$linkage = $light ? [] : crm_customer_linkage_summary($customer);')!==false,'Overview skips cross-system reads');
$handoff=crm_customer_quote_handoff(['opportunity_id'=>8]);section_assert($handoff['customer']['id']===71 && $handoff['opportunity_id']===8 && !isset($handoff['customer']['email']) && !isset($handoff['customer']['private_notes']),'Canonical minimal quote context');
foreach([['customer_id'=>72],['opportunity_id'=>8,'customer_id'=>72],['opportunity_id'=>9]] as $input){$thrown=false;try{crm_customer_quote_handoff($input);}catch(RuntimeException $e){$thrown=true;}section_assert($thrown,'Reject forged or inaccessible context');}
foreach(['customer.view','opportunity.view'] as $permission){$denied=$permission;$thrown=false;try{crm_customer_quote_handoff(['opportunity_id'=>8]);}catch(RuntimeException $e){$thrown=true;}section_assert($thrown,'Handoff permission boundary');}$denied='';
$quoteAllowed=false;$thrown=false;try{crm_customer_quote_handoff(['customer_id'=>71]);}catch(RuntimeException $e){$thrown=true;}section_assert($thrown,'Destination create permission required');
echo 'crm_customer_sections_isolated: '.($count+15).' checks passed'.PHP_EOL;
