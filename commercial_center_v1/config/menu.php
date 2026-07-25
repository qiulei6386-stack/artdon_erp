<?php
declare(strict_types=1);

return [
    '工作台' => [
        ['key'=>'operations_dashboard','label'=>'运营工作台'], ['key'=>'my_tasks','label'=>'我的待办'],
        ['key'=>'risk_center','label'=>'预警中心'], ['key'=>'data_dashboard','label'=>'数据看板'],
    ],
    '产品报价' => [
        ['key'=>'product_config','label'=>'产品与配置'], ['key'=>'standard_quote','label'=>'标准报价'],
        ['key'=>'quick_quote','label'=>'快速报价'], ['key'=>'quote_templates','label'=>'报价模板'],
        ['key'=>'price_strategy','label'=>'价格策略'], ['key'=>'tier_prices','label'=>'阶梯价格'],
        ['key'=>'quote_history','label'=>'历史报价'], ['key'=>'quote_approval','label'=>'报价审核'],
    ],
    '物料与配件' => [
        ['key'=>'materials','label'=>'物料与配件'], ['key'=>'material_substitutes','label'=>'替代关系'],
        ['key'=>'accessory_sets','label'=>'配件组合'], ['key'=>'compatibility_rules','label'=>'适配规则'],
    ],
    '库存与SKU' => [
        ['key'=>'inventory_sku','label'=>'库存SKU'], ['key'=>'ready_stock','label'=>'Ready Stock'],
        ['key'=>'inventory_locks','label'=>'库存锁定'], ['key'=>'delivery_dates','label'=>'交期管理'],
        ['key'=>'inventory_alerts','label'=>'库存预警'],
    ],
    '订单与交付' => [
        ['key'=>'order_center','label'=>'订单中心'], ['key'=>'pi_confirmation','label'=>'PI确认'],
        ['key'=>'packaging_center','label'=>'包装中心'], ['key'=>'document_center','label'=>'单证中心'],
        ['key'=>'shipment_center','label'=>'出货管理'], ['key'=>'payment_center','label'=>'收款管理'],
    ],
    '项目业务' => [
        ['key'=>'custom_projects','label'=>'定制项目'], ['key'=>'sample_quotes','label'=>'样品报价'],
        ['key'=>'engineering_quotes','label'=>'工程报价'], ['key'=>'project_quotes','label'=>'项目报价'],
    ],
    '财务与佣金' => [
        ['key'=>'price_commission','label'=>'价格与佣金'], ['key'=>'commission_rules','label'=>'佣金规则'],
        ['key'=>'exchange_tax','label'=>'汇率税率'], ['key'=>'payment_milestones','label'=>'收款节点'],
        ['key'=>'receivable_alerts','label'=>'应收提醒'], ['key'=>'profit_analysis','label'=>'利润分析'],
    ],
    '系统设置' => [
        ['key'=>'system_settings','label'=>'系统设置'], ['key'=>'approval_flows','label'=>'审批流程'],
        ['key'=>'role_permissions','label'=>'角色权限'], ['key'=>'field_templates','label'=>'字段模板'],
        ['key'=>'mail_settings','label'=>'邮件配置'], ['key'=>'number_rules','label'=>'编号规则'],
        ['key'=>'activity_logs','label'=>'日志中心'], ['key'=>'data_backups','label'=>'数据备份'],
    ],
];
