<?php
declare(strict_types=1);

/**
 * 阶段 1 本地开关。
 * demo 默认关闭。只有改成 true 时，页面才跳到静态原型，便于本机没有数据库时看界面。
 * 正式环境保持 false。打开后也不会放行未登录接口。
 */
return [
    'demo' => false,
];
