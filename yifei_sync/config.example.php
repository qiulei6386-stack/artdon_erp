<?php
/**
 * 易飞只读同步的共享密钥示例。不要把真实密钥提交到 Git。
 *
 * 服务器上复制为本文件同一返回结构，放到仓库外：
 *   /www/server/artdon_secrets/yifei_sync.php
 * 本机联调可复制为 yifei_sync/config.local.php（已在 .gitignore）。
 * 环境变量 YIFEI_SYNC_TOKEN 若已设置，优先于上述文件。
 *
 * 密钥至少 16 位，且不能使用本示例里的占位文字。
 */
return array(
    'token' => 'replace-with-a-long-random-token',
);
