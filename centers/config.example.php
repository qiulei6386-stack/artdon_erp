<?php
/**
 * 新系统配置示例。复制为 centers/config.php 后填写（centers/config.php 不进 Git）。
 * 服务器上可运行：php centers/_base/tools/init_config.php 自动生成。
 */
return [
    'db' => [
        'host' => '127.0.0.1',
        'port' => 3306,
        'name' => 'artdon_new_erp',
        'user' => '',
        'password' => '',
        'charset' => 'utf8mb4',
    ],
    'app' => [
        // 新系统的登录会话名，与旧系统不同，两边登录互不影响
        'session_name' => 'ARTDON_CENTERS',
        // 登录失败多少次后锁定，锁定多少分钟
        'login_max_failures' => 5,
        'login_lock_minutes' => 15,
        'timezone' => 'Asia/Shanghai',
    ],
];
