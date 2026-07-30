<?php
declare(strict_types=1);

$root = getenv('QUOTATION_CONTRACT_ROOT') ?: dirname(__DIR__);
$api = (string) file_get_contents($root . '/quote_api.php');
$page = (string) file_get_contents($root . '/quotation.php');

$checks = [
    'frontend version suffix is preserved during quote number normalization'
        => strpos($page, "let version='';let vm=no.match(/-V\\d+$/i)") !== false
            && strpos($page, "return no+version;") !== false,
    'backend version suffix is preserved during quote number normalization'
        => strpos($api, "\$version='';") !== false
            && strpos($api, "return \$no.\$version;") !== false,
    'save as new version forces insert instead of updating current id'
        => strpos($page, "saveQuote({saveMode:'new_version',sourceQuoteId:oldId,forceInsert:true})") !== false
            && strpos($page, "id:(opts.forceInsert||opts.saveMode==='new_version')?'':(S.currentQuoteId||'')") !== false,
    'saving is blocked while a historical quote detail is still loading'
        => strpos($page, "if(S.loadingQuoteId){alert('报价完整明细还在读取中，请等打开完成后再保存。');return;}") !== false
            && strpos($page, "S.loadingQuoteId=Number(q.id||0)") !== false,
    'stale historical quote load response cannot override a newer open action'
        => strpos($page, "let token=++QUOTE_OPEN_TOKEN;") !== false
            && strpos($page, "if(token!==QUOTE_OPEN_TOKEN)return null;") !== false,
    'backend rejects id and quote number mismatch before update'
        => strpos($api, "save_quote_identity_blocked") !== false
            && strpos($api, "当前保存ID属于") !== false
            && strpos($api, "\$oldNo!=='' && \$newNo!=='' && \$oldNo!==\$newNo") !== false,
    'backend rejects id and customer mismatch before update'
        => strpos($api, "阻止报价跨客户覆盖") !== false
            && strpos($api, "\$oldCustomer!=='' && \$newCustomer!=='' && \$oldCustomer!==\$newCustomer") !== false,
];

foreach ($checks as $label => $passed) {
    if (!$passed) {
        fwrite(STDERR, "missing contract: {$label}\n");
        exit(1);
    }
}

if (strpos($page, "normalizeQuoteNoNoNested(no){\n  no=String(no||'').trim().replace(/-V\\d+$/i,'');") !== false) {
    fwrite(STDERR, "legacy quote number normalizer still strips version suffix\n");
    exit(1);
}

echo "quote save identity guard contract passed\n";
