<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$crmPage = file_get_contents($root . '/crm.php');
$crmJs = file_get_contents($root . '/assets/crm/crm.js');
$crmCss = file_get_contents($root . '/assets/crm/crm.css');
$customer = file_get_contents($root . '/crm_customer.php');

if ($crmPage === false || $crmJs === false || $crmCss === false || $customer === false) {
    fwrite(STDERR, "Cannot read CRM customer list sort/chat group files\n");
    exit(1);
}

$required = [
    '排序下拉支持群名' => '<option value="chat_group_names">按群名</option>',
    '排序下拉支持最后推广' => '<option value="last_promotion_at">按最后推广</option>',
    '列表列定义包含群名' => "{ key: 'chat_group_names', label: '群名'",
    '列表列定义包含最后推广' => "{ key: 'last_promotion_at', label: '最后推广'",
    '群名单元格渲染' => "if (key === 'chat_group_names') return '<td data-label=\"群名\"",
    '最后推广单元格渲染' => "if (key === 'last_promotion_at') return '<td data-label=\"最后推广\"",
    '表头排序字段属性' => 'data-customer-sort-col',
    '排序列映射函数' => 'sortableColumnKey: function (key)',
    '表头排序切换函数' => 'applyColumnSort: function (columnKey)',
    '下拉排序同步表头' => 'self.renderHeader();',
    '升降序图标' => 'customer-sort-indicator',
    '后端群名排序字段' => "'chat_group_names' => '(SELECT GROUP_CONCAT(cg_sort.group_name",
    '后端最后推广表达式' => 'function crm_customer_last_promotion_expr',
    '后端最后推广排序字段' => "'last_promotion_at' => \$lastPromotionExpr",
    '后端最后推广选择字段' => '{$lastPromotionExpr} AS last_promotion_at',
    '后端列表返回群名字段' => "'' AS chat_group_names",
    '后端读取客户群名' => 'FROM crm_customer_chat_groups WHERE customer_id IN',
    '微信群标签' => "THEN 'WhatsApp群' ELSE '微信群'",
    '接口写入群名字段' => "\$row['chat_group_names'] = \$chatGroupNames[\$customerId] ?? '';",
    '可排序表头样式' => '.customer-table th.is-sortable',
];

$haystack = $crmPage . "\n" . $crmJs . "\n" . $crmCss . "\n" . $customer;
foreach ($required as $label => $needle) {
    if (strpos($haystack, $needle) === false) {
        fwrite(STDERR, "Missing marker: {$label}\n");
        exit(1);
    }
}

echo "crm customer list sort/chat group contract ok\n";
