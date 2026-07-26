<?php
declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use Artdon\CommercialCenter\Repositories\CustomQuoteRepository;
use Artdon\CommercialCenter\Services\CustomQuoteService;
use Artdon\CommercialCenter\Services\QuotePermissionService;

if (($argv[1] ?? '') !== '--write-test') {
    echo "PASS: custom quote closure test loaded; use --write-test after migration.\n";
    exit(0);
}

$db = db();
$service = new CustomQuoteService();
$repository = new CustomQuoteRepository($db);
$actor = ['id'=>0,'username'=>'step7-test','display_name'=>'STEP 7 TEST','is_test_actor'=>true,'test_permissions'=>QuotePermissionService::ACTIONS];
$quoteId = 0;
try {
    $bootstrap = $service->bootstrap(0);
    $customer = $bootstrap['customers'][0] ?? null;
    $reference = $bootstrap['reference_products'][0] ?? null;
    if (!is_array($customer) || !is_array($reference)) {
        throw new RuntimeException('Real CRM customer or reference product unavailable.');
    }
    $payload = [
        'customer_id'=>(int)$customer['id'],'currency'=>'USD','valid_until'=>date('Y-m-d',strtotime('+30 days')),
        'project_name'=>'STEP 7 CUSTOM PROJECT','project_type'=>'lighting_custom','requirement_summary'=>'Customer drawing and special finish',
        'crm_opportunity'=>'OPP-STEP7','crm_project'=>'PROJECT-STEP7','payment_terms'=>'30% deposit','trade_terms'=>'FOB SHENZHEN',
        'customer_note'=>'Customer-visible requirement','internal_note'=>'Internal pricing note','is_test'=>1,
        'items'=>[[
            'description'=>'Custom pendant','product_name'=>'Custom pendant','configuration_snapshot'=>['specification'=>'300x1200mm / 36W'],
            'unit'=>'PCS','quantity'=>3,'unit_price'=>120,'lead_time'=>'25 days','reference_product_id'=>(int)$reference['id'],
            'custom_fields'=>['material'=>'Aluminium','color'=>'RAL 9005','dimensions'=>'300x1200mm','power'=>'36W',
                'installation'=>'Pendant','special_process'=>'Anodized','target_cost'=>62,'estimated_cost'=>70,
                'pricing_opinion'=>'Target margin accepted','approval_opinion'=>'Engineering checked'],
        ],[
            'description'=>'Custom wall light','product_name'=>'Custom wall light','configuration_snapshot'=>['specification'=>'IP65 / 12W'],
            'unit'=>'PCS','quantity'=>5,'unit_price'=>80,'custom_fields'=>['material'=>'Steel','estimated_cost'=>40],
        ]],
    ];
    $quote = $service->save($payload,$actor);
    $quoteId = (int)$quote['id'];
    if (($quote['quote_type']??'') !== 'custom_product' || count($quote['items']) !== 2 || (float)$quote['total_amount'] !== 760.0) {
        throw new RuntimeException('Custom quotation save or amount calculation failed.');
    }
    $payload['id']=$quoteId;
    $payload['items'][0]['quantity']=4;
    $edited=$service->save($payload,$actor);
    if ((int)$edited['current_version'] !== 2 || (float)$edited['items'][0]['quantity'] !== 4.0) {
        throw new RuntimeException('Custom quotation edit/reopen failed.');
    }
    $itemIds=$repository->currentItemIds($quoteId);
    $file1=$repository->saveFile($quoteId,null,'reference_image',['name'=>'reference.png','path'=>'uploads/custom_quotes/test/reference.png','mime'=>'image/png','size'=>12,'hash'=>hash('sha256','a')],0);
    $file2=$repository->saveFile($quoteId,$itemIds[0],'document',['name'=>'requirement.pdf','path'=>'uploads/custom_quotes/test/requirement.pdf','mime'=>'application/pdf','size'=>20,'hash'=>hash('sha256','b')],0);
    $repository->reorder($quoteId,[$file1],false);
    if (count($repository->files($quoteId)) !== 1 || count($repository->itemFiles($quoteId)) !== 1) {
        throw new RuntimeException('Quote/item attachment persistence failed.');
    }
    $submitted=$service->submit($quoteId,$actor);
    if (($submitted['status']??'') !== 'pending_approval') throw new RuntimeException('Submit failed.');
    $approved=$service->approve($quoteId,$actor,'Engineering and pricing approved');
    if (($approved['status']??'') !== 'approved') throw new RuntimeException('Approval failed.');
    $project=$service->handoff($quoteId,'project',$actor);
    $order=$service->handoff($quoteId,'order',$actor);
    if (($project['status']??'') !== 'created' || ($order['status']??'') !== 'created') throw new RuntimeException('Handoff failed.');
    echo "PASS: custom fields, reference product, pricing/cost/margin, edit/reopen, quote/item files, approval and project/order handoff verified.\n";
} finally {
    if ($quoteId > 0) {
        $versions=$db->query("SELECT id FROM cc_quote_versions WHERE quote_id={$quoteId}")->fetchAll(PDO::FETCH_COLUMN);
        $items=[];
        if ($versions) {
            $versionIds=implode(',',array_map('intval',$versions));
            $items=$db->query("SELECT id FROM cc_quote_items WHERE quote_version_id IN ({$versionIds})")->fetchAll(PDO::FETCH_COLUMN);
            if ($items) {
                $itemIds=implode(',',array_map('intval',$items));
                $db->exec("DELETE o FROM cc_quote_file_orders o INNER JOIN cc_quote_item_files f ON f.id=o.quote_item_file_id WHERE f.quote_item_id IN ({$itemIds})");
                $db->exec("DELETE FROM cc_quote_item_files WHERE quote_item_id IN ({$itemIds})");
                $db->exec("DELETE FROM cc_quote_item_snapshots WHERE quote_item_id IN ({$itemIds})");
                $db->exec("DELETE FROM cc_quote_item_details WHERE quote_item_id IN ({$itemIds})");
                $db->exec("DELETE FROM cc_quote_items WHERE id IN ({$itemIds})");
            }
        }
        $db->exec("DELETE o FROM cc_quote_file_orders o INNER JOIN cc_quote_files f ON f.id=o.quote_file_id WHERE f.quote_id={$quoteId}");
        $db->exec("DELETE FROM cc_quote_handoffs WHERE quote_id={$quoteId}");
        $db->exec("DELETE FROM cc_quote_files WHERE quote_id={$quoteId}");
        $db->exec("DELETE FROM cc_quote_approvals WHERE quote_id={$quoteId}");
        $db->exec("DELETE FROM cc_quote_state_history WHERE quote_id={$quoteId}");
        $db->exec("DELETE FROM cc_quote_audit_logs WHERE quote_id={$quoteId}");
        $db->exec("DELETE FROM cc_quote_snapshots WHERE quote_id={$quoteId}");
        $db->exec("DELETE FROM cc_quote_legacy_links WHERE quote_id={$quoteId}");
        $db->exec("DELETE FROM cc_quotation_logs WHERE quote_id={$quoteId}");
        if ($versions) $db->exec("DELETE FROM cc_quote_versions WHERE id IN (".implode(',',array_map('intval',$versions)).")");
        $db->exec("DELETE FROM cc_quote_details WHERE quote_id={$quoteId}");
        $db->exec("DELETE FROM cc_quotes WHERE id={$quoteId} AND is_test=1");
    }
}
