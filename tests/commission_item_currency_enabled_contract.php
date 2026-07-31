<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$page = (string) file_get_contents($root . '/quotation.php');
$failed = [];

$stateMarker = "let enabled=configured&&(enabledValue===''||enabledValue===null||enabledValue===undefined)?true";
if (!str_contains($page, $stateMarker)) {
    $failed[] = 'configured product commission rows with empty enabled value must render as participating';
}

$stageStart = strrpos($page, 'function stageCommissionItem(id,f,v)');
$stageEnd = $stageStart === false ? false : strpos($page, 'const loadPiCommissionReminderBase', $stageStart);
$stageBody = ($stageStart !== false && $stageEnd !== false) ? substr($page, $stageStart, $stageEnd - $stageStart) : '';
if ($stageBody === '') {
    $failed[] = 'final stageCommissionItem override not found';
}
foreach ([
    "const configFields=['target_name','target_type','commission_mode','commission_value','calc_base','currency','receivable_effect','settle_status'];",
    "if(configFields.includes(f)&&!Object.prototype.hasOwnProperty.call(COMMISSION_ITEM_DRAFTS[id],'is_commission_enabled'))COMMISSION_ITEM_DRAFTS[id].is_commission_enabled=1;",
] as $marker) {
    if ($stageBody === '' || !str_contains($stageBody, $marker)) {
        $failed[] = 'changing product commission config fields must auto-enable the product row';
        break;
    }
}

if ($failed) {
    file_put_contents('php://stderr', 'commission item currency enabled contract failed: ' . implode('；', $failed) . PHP_EOL);
    exit(1);
}

echo "commission item currency enabled contract passed\n";
