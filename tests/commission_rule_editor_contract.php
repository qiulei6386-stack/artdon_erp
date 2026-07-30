<?php
declare(strict_types=1);

$source = file_get_contents(__DIR__ . '/../quotation.php');
if ($source === false) {
    fwrite(STDERR, "cannot read quotation.php\n");
    exit(1);
}

$required = [
    'formal editor shell' => 'commission-rule-modal',
    'rule identity' => 'commissionRuleName',
    'commission target' => 'commissionRuleTargetName',
    'target contact' => 'commissionRuleTargetContact',
    'scope selector' => 'commissionRuleScope',
    'customer matcher' => 'commissionRuleCustomerName',
    'product matcher' => 'commissionRuleProductModel',
    'category matcher' => 'commissionRuleCategory',
    'commission mode' => 'commissionRuleMode',
    'commission value' => 'commissionRuleValue',
    'calculation base' => 'commissionRuleCalcBase',
    'currency' => 'commissionRuleCurrency',
    'settlement node' => 'commissionRuleSettleNode',
    'settlement status' => 'commissionRuleSettleStatus',
    'settled amount' => 'commissionRuleSettledAmount',
    'active state' => 'commissionRuleActive',
    'internal note' => 'commissionRuleNote',
    'live preview' => 'previewCommissionRuleEditor',
    'linked scope fields' => 'toggleCommissionRuleScope',
    'save through real endpoint' => "api('commission_rule_save',d)",
    'existing rule editor' => 'openCommissionRuleEditor(${Number(r.id)})',
];

foreach ($required as $label => $needle) {
    if (strpos($source, $needle) === false) {
        fwrite(STDERR, "missing {$label}: {$needle}\n");
        exit(1);
    }
}

if (strpos($source, "prompt('佣金对象名称：'") !== false) {
    fwrite(STDERR, "legacy prompt-based rule creation still exists\n");
    exit(1);
}

echo "commission rule editor contract passed\n";
