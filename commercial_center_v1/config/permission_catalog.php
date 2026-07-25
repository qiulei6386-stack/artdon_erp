<?php
declare(strict_types=1);

return [
    'roles' => [
        ['code'=>'super_admin','label'=>'超级管理员','scope'=>'all'],
        ['code'=>'commercial_manager','label'=>'商务负责人','scope'=>'department'],
        ['code'=>'salesperson','label'=>'业务员','scope'=>'self'],
        ['code'=>'order_coordinator','label'=>'跟单员','scope'=>'department'],
        ['code'=>'finance','label'=>'财务','scope'=>'all'],
        ['code'=>'viewer','label'=>'查看人员','scope'=>'none'],
    ],
    'actions' => ['view'=>'查看','create'=>'新增','edit'=>'编辑','delete'=>'删除','approve'=>'审核','export'=>'导出'],
    'data_scopes' => ['self'=>'本人数据','department'=>'部门数据','all'=>'全部数据','none'=>'无业务数据'],
    'fields' => ['product'=>'产品','quantity'=>'数量','sales_price'=>'销售价格','cost_price'=>'成本价格','margin_rate'=>'利润率','supplier'=>'供应商'],
    'modules' => ['product_quote'=>'产品报价','order_center'=>'订单中心','finance_center'=>'财务中心','system_settings'=>'系统设置'],
];
