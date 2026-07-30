<?php
$root = getenv('QUOTATION_CONTRACT_ROOT') ?: dirname(__DIR__);
$api = (string) file_get_contents($root . '/quote_api.php');
$page = (string) file_get_contents($root . '/quotation.php');

$checks = [
    '公共接口使用数据库版本门控结构检查'
        => str_contains($api, 'function quote_runtime_schema_ready(PDO $pdo): void')
            && str_contains($api, 'quote_runtime_schema_ready($pdo);'),
    '结构版本命中后直接返回'
        => str_contains($api, "SELECT schema_version FROM quote_runtime_schema_state WHERE module_code='quotation' LIMIT 1")
            && str_contains($api, "if((int)(\$state['schema_version']??0)>=\$version){\$ready=true;return;}"),
    '公共入口不再逐项重复运行四套结构检查'
        => !str_contains($api, "try{\n ensure_quote_core_schema(\$pdo);\n ensure_quote_settings(\$pdo);\n ensure_quote_price_policy_schema(\$pdo);"),
    '首页不再自动拉取全部历史报价详情'
        => !str_contains($page, "setTimeout(()=>hydrateQuoteDetails().catch(()=>{}),0)"),
    '打开历史报价按单读取详情'
        => str_contains($page, 'async function loadQuote(id)')
            && str_contains($page, 'await ensureQuoteDetail(id)'),
    '复制报价等待单条详情读取完成'
        => str_contains($page, 'async function copyQuote(id)')
            && str_contains($page, 'let q=await loadQuote(id)'),
    '普通打开报价首页不再恢复上次订单或上次报价'
        => str_contains($page, 'function quoteStartupRequestedPage()')
            && str_contains($page, "if(quoteStartupRequestedPage()){\n    restoreLastPage();\n  }else{")
            && str_contains($page, "localStorage.removeItem('artdon_quote_open_context')")
            && str_contains($page, "showPage('quote');"),
    '恢复页面只接受明确URL请求，不再读取上次页面localStorage'
        => str_contains($page, "let p=quoteStartupRequestedPage()||'quote';")
            && !str_contains($page, "let p=localStorage.getItem('artdon_quote_current_page')||'quote';"),
    '首页仪表盘不再首屏自动拉订单和单证接口'
        => str_contains($page, "订单数据进入订单/单证页后同步")
            && !str_contains($page, 'applyDashTemplate();updateDashMiniSummary();ensureDashOrderData();ensureDashDocData();'),
    '订单列表加载具备并发复用保护'
        => str_contains($page, 'let ORDERS_LOADING_PROMISE=null;')
            && str_contains($page, 'if(ORDERS_LOADING_PROMISE)return ORDERS_LOADING_PROMISE;'),
];

$failed = [];
foreach ($checks as $label => $passed) {
    echo ($passed ? '[PASS] ' : '[FAIL] ') . $label . PHP_EOL;
    if (!$passed) $failed[] = $label;
}
if ($failed) {
    file_put_contents('php://stderr', 'quotation runtime performance contract failed: ' . implode('；', $failed) . PHP_EOL);
    exit(1);
}
echo "quotation runtime performance contract passed.\n";
