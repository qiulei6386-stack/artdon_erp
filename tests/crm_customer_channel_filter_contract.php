<?php
require dirname(__DIR__) . '/includes/crm_customer_channel_filter.php';
function cfc_assert($ok, $message) { if (!$ok) throw new RuntimeException($message); }
foreach (['wechat','whatsapp','wechat_group','whatsapp_group'] as $ch) {
    $params = [12];
    $sql = crm_customer_channel_condition($ch, $params);
    cfc_assert($params === [12,$ch,$ch], 'Bound channel parameters');
    cfc_assert(substr_count($sql,'EXISTS') === 2 && strpos($sql,'deleted_at IS NULL') !== false && strpos($sql,"cfp.status='active'") !== false, 'Explicit settings, no deleted contacts or duplicates');
    $summary = crm_customer_channel_summary($ch, ['channel_selected'=>1], [], []);
    cfc_assert($summary['sources'] === ['客户推广方式'] && str_contains(implode('', $summary['notes']), '缺'), 'Settings without data remain classified and explained');
}
cfc_assert(crm_customer_filter_channel('has_whatsapp_group') === 'whatsapp_group' && crm_customer_filter_channel('all') === '', 'Quick map');
$s = crm_customer_channel_summary('wechat', [], [['name'=>'A','channel_selected'=>1,'wechat'=>''],['name'=>'B','wechat'=>'other']], []);
cfc_assert(in_array('缺个人微信号',$s['notes'],true), 'Unrelated contact number cannot fill selected contact');
$s = crm_customer_channel_summary('whatsapp', ['channel_selected'=>1,'do_not_contact'=>1], [['name'=>'A','channel_selected'=>1,'whatsapp'=>'123','no_whatsapp'=>1]], []);
cfc_assert(in_array('客户禁止联系',$s['notes'],true) && str_contains(implode('', $s['notes']),'禁联'), 'Restricted contact warnings');
$s = crm_customer_channel_summary('wechat_group', ['channel_selected'=>1], [], [['status'=>'paused','use_for_promotion'=>1],['status'=>'invalid','use_for_promotion'=>0],['status'=>'active','use_for_promotion'=>0]]);
cfc_assert(in_array('1个暂停',$s['notes'],true) && in_array('1个失效',$s['notes'],true) && in_array('2个不用于推广',$s['notes'],true) && in_array('无启用且允许推广的群',$s['notes'],true), 'Separate group status and permission');
$source = file_get_contents(dirname(__DIR__).'/crm_customer.php');
cfc_assert(substr_count($source, 'crm_customer_channel_annotate(db(), $rows, crm_customer_filter_channel($quick))') === 2,'Both compact/full pages enriched');
echo "Customer channel rules and summaries passed\n";
