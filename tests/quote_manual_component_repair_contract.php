<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$api = file_get_contents($root . '/quote_api.php');
$quote = file_get_contents($root . '/quotation.php');
$editor = file_get_contents($root . '/bom_quote_spec.php');

if ($api === false || $quote === false || $editor === false) {
    throw new RuntimeException('quote component repair files are not readable');
}

$requiredApiMarkers = [
    'function qspec_is_internal_fastener_text',
    "if(\$key==='accessories' && qspec_is_internal_fastener_text(\$s)) return true;",
    "if(qspec_is_internal_fastener_text(\$txt)) return '';",
    'qspec-classifier-v3|',
    "'get_bom_quote_spec'=>'product_view'",
    "if(\$action==='get_bom_quote_spec')",
    "SET auto_generated=0,source_hash='',last_sync_at=NULL WHERE id=?",
    "'spec'=>\$saved?qspec_row_to_payload_rec(\$saved):null",
];
foreach ($requiredApiMarkers as $marker) {
    if (!str_contains($api, $marker)) {
        throw new RuntimeException('missing quote API repair contract: ' . $marker);
    }
}

$classifyStart = strpos($api, 'function qspec_classify_component');
$classifyEnd = strpos($api, 'function qspec_guess_components_from_bom', $classifyStart === false ? 0 : $classifyStart);
if ($classifyStart === false || $classifyEnd === false) {
    throw new RuntimeException('component classifier boundaries are missing');
}
$classifier = substr($api, $classifyStart, $classifyEnd - $classifyStart);
$fastenerGuard = strpos($classifier, 'qspec_is_internal_fastener_text');
$accessoryMatch = strpos($classifier, "return 'accessories'");
if ($fastenerGuard === false || $accessoryMatch === false || $fastenerGuard > $accessoryMatch) {
    throw new RuntimeException('internal fasteners must be rejected before accessory classification');
}

$requiredEditorMarkers = [
    "artdon_sso_require_page('quote')",
    '人工修正报价关键元器件',
    'Accessories / 客户可选附件',
    "api('get_bom_quote_spec&product_model='",
    "api('save_bom_quote_spec',data)",
    "type:'artdon:quote-spec-saved'",
    '保存人工修正',
];
foreach ($requiredEditorMarkers as $marker) {
    if (!str_contains($editor, $marker)) {
        throw new RuntimeException('missing manual editor contract: ' . $marker);
    }
}

$requiredQuoteMarkers = [
    "let u='bom_quote_spec.php'",
    '手动修正关键件',
    "event.data.type!=='artdon:quote-spec-saved'",
    'applySavedQuoteSpec(event.data.spec||null)',
];
foreach ($requiredQuoteMarkers as $marker) {
    if (!str_contains($quote, $marker)) {
        throw new RuntimeException('missing quotation repair integration: ' . $marker);
    }
}

echo "Quote manual component repair contract: OK\n";
