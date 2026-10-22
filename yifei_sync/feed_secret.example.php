<?php
/**
 * 新加坡店拉取广州易飞芯片/驱动镜像的 Feed 密钥示例。不要把真实密钥提交到 Git。
 *
 * 服务器上复制为本文件同一返回结构，放到仓库外：
 *   /www/server/artdon_secrets/yifei_feed.php
 * 本机联调可复制为 yifei_sync/feed.config.local.php（已在 .gitignore）。
 * 环境变量 YIFEI_FEED_TOKEN 若已设置，优先于上述文件。
 *
 * 与 ingest 的 yifei_sync.php / X-Yifei-Token 无关，请使用独立密钥。
 * 密钥至少 16 位，且不能使用占位文字 replace-with。
 */
return array(
    'token' => 'replace-with-a-long-random-feed-token',
);
