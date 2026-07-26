<?php
declare(strict_types=1);

require dirname(__DIR__).'/bootstrap.php';

use Artdon\CommercialCenter\Repositories\QuoteOutputRepository;
use Artdon\CommercialCenter\Services\CustomQuoteService;
use Artdon\CommercialCenter\Services\QuoteOutputService;
use Artdon\CommercialCenter\Services\QuotePermissionService;

if(($argv[1]??'')!=='--write-test'){echo "PASS: quote output closure test loaded; use --write-test after migration.\n";exit(0);}
$db=db();$actor=['id'=>0,'username'=>'step8-test','display_name'=>'STEP 8 TEST','is_test_actor'=>true,'test_permissions'=>QuotePermissionService::ACTIONS];
$quoteId=0;$paths=[];
try{
    $custom=new CustomQuoteService();$bootstrap=$custom->bootstrap(0);$customer=$bootstrap['customers'][0]??null;
    if(!is_array($customer))throw new RuntimeException('Real CRM customer unavailable.');
    $quote=$custom->save([
        'customer_id'=>(int)$customer['id'],'currency'=>'USD','valid_until'=>date('Y-m-d',strtotime('+30 days')),
        'project_name'=>'STEP 8 OUTPUT','payment_terms'=>'30% deposit','trade_terms'=>'FOB SHENZHEN','is_test'=>1,
        'items'=>[['description'=>'Output snapshot product','product_name'=>'Output snapshot product','quantity'=>2,'unit'=>'PCS',
            'unit_price'=>123.45,'configuration_snapshot'=>['finish'=>'Black'],'custom_fields'=>['estimated_cost'=>60]]],
    ],$actor);
    $quoteId=(int)$quote['id'];$repository=new QuoteOutputRepository($db);
    $outputs=new QuoteOutputService($repository,static fn():bool=>false);
    $draft=$outputs->snapshotForQuote($quoteId,$actor);
    if($draft['watermark']!=='DRAFT / 草稿'||!str_contains($outputs->html((int)$draft['id'],$actor),'DRAFT / 草稿'))throw new RuntimeException('Draft watermark failed.');
    $custom->submit($quoteId,$actor);$custom->approve($quoteId,$actor,'Output approved');
    $approved=$outputs->snapshotForQuote($quoteId,$actor);
    $same=$outputs->snapshotForQuote($quoteId,$actor);
    if((int)$approved['id']!==(int)$same['id']||$approved['watermark']!==null)throw new RuntimeException('Approved snapshot reuse/watermark failed.');
    $html=$outputs->html((int)$approved['id'],$actor);
    $pdf=$outputs->artifact((int)$approved['id'],'pdf',$actor);$excel=$outputs->artifact((int)$approved['id'],'excel',$actor);
    $paths=[dirname(__DIR__).'/'.$pdf['storage_path'],dirname(__DIR__).'/'.$excel['storage_path']];
    if(!str_contains($html,'246.90')||!str_contains((string)file_get_contents($paths[1]),'246.9')
        ||!is_file($paths[0])||filesize($paths[0])<1000)throw new RuntimeException('HTML/PDF/Excel output mismatch.');
    try{$outputs->send((int)$approved['id'],'test@example.com','','Step 8 test','Snapshot attachment test',$actor);}
    catch(RuntimeException){}
    $delivery=$db->query("SELECT * FROM cc_quote_deliveries WHERE quote_id={$quoteId} ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if(!is_array($delivery)||$delivery['delivery_status']!=='failed'||(int)$delivery['output_snapshot_id']!==(int)$approved['id'])throw new RuntimeException('Failed delivery audit was not persisted.');
    echo "PASS: one immutable snapshot drives watermark, preview/print HTML, PDF, Excel and delivery attachment/audit.\n";
}finally{
    if($quoteId>0){
        $snapshots=$db->query("SELECT id FROM cc_quote_output_snapshots WHERE quote_id={$quoteId}")->fetchAll(PDO::FETCH_COLUMN);
        $db->exec("DELETE FROM cc_quote_deliveries WHERE quote_id={$quoteId}");
        if($snapshots)$db->exec("DELETE FROM cc_quote_output_artifacts WHERE output_snapshot_id IN (".implode(',',array_map('intval',$snapshots)).")");
        $db->exec("DELETE FROM cc_quote_output_snapshots WHERE quote_id={$quoteId}");
        $versions=$db->query("SELECT id FROM cc_quote_versions WHERE quote_id={$quoteId}")->fetchAll(PDO::FETCH_COLUMN);
        if($versions){$v=implode(',',array_map('intval',$versions));$items=$db->query("SELECT id FROM cc_quote_items WHERE quote_version_id IN ({$v})")->fetchAll(PDO::FETCH_COLUMN);
            if($items){$i=implode(',',array_map('intval',$items));$db->exec("DELETE FROM cc_quote_item_snapshots WHERE quote_item_id IN ({$i})");$db->exec("DELETE FROM cc_quote_item_details WHERE quote_item_id IN ({$i})");$db->exec("DELETE FROM cc_quote_items WHERE id IN ({$i})");}}
        $db->exec("DELETE FROM cc_quote_approvals WHERE quote_id={$quoteId}");$db->exec("DELETE FROM cc_quote_state_history WHERE quote_id={$quoteId}");
        $db->exec("DELETE FROM cc_quote_audit_logs WHERE quote_id={$quoteId}");$db->exec("DELETE FROM cc_quote_snapshots WHERE quote_id={$quoteId}");
        $db->exec("DELETE FROM cc_quotation_logs WHERE quote_id={$quoteId}");if($versions)$db->exec("DELETE FROM cc_quote_versions WHERE id IN (".implode(',',array_map('intval',$versions)).")");
        $db->exec("DELETE FROM cc_quote_details WHERE quote_id={$quoteId}");$db->exec("DELETE FROM cc_quotes WHERE id={$quoteId} AND is_test=1");
    }
    foreach($paths as $path)if(is_file($path))unlink($path);
}
