<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$api = (string)file_get_contents($root . '/quote_order_api.php');
$page = (string)file_get_contents($root . '/quotation.php');
$checks = [
    '编辑数据接口' => str_contains($api, 'function qo_shipment_edit_data'),
    '修改接口' => str_contains($api, 'function qo_update_shipment'),
    '删除接口' => str_contains($api, 'function qo_delete_shipment'),
    '草稿与单证保护' => str_contains($api, 'qo_shipment_can_change') && str_contains($api, 'pl_generated_at') && str_contains($api, 'ci_generated_at'),
    '修改数量上限校验' => str_contains($api, '出货数量超过订单剩余可出数量'),
    '删除二次确认' => str_contains($api, 'DELETE_SHIPMENT'),
    '前端修改入口' => str_contains($page, 'function editShipment') && str_contains($page, '修改批次'),
    '前端删除入口' => str_contains($page, 'function deleteShipment') && str_contains($page, '删除批次'),
    '编辑模式回填' => str_contains($page, 'SHIPMENT_EDIT_ID') && str_contains($page, 'shipment_edit_data'),
];

$failed = [];
foreach ($checks as $label => $ok) {
    echo ($ok ? 'PASS' : 'FAIL') . " {$label}\n";
    if (!$ok) $failed[] = $label;
}
exit($failed ? 1 : 0);
