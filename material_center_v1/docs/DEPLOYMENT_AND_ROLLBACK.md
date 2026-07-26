# 部署与回滚

1. 在本地完成修改及检查并提交。
2. 推送 `origin/main`。
3. 将 GitHub 同一提交快进到 `/www/wwwroot/Artdon/artdon_erp/`。
4. 在服务器执行 `php material_center_v1/tools/migrate.php up`。
5. 执行 PHP/JS/合同/功能测试，核对本地、GitHub、服务器 HEAD。

回滚先停止写操作，备份数据库和 `storage/`；按迁移版本倒序运行 `tools/migrate.php down <version>`，再把服务器 Git 快进/回退到 GitHub 已存在的目标提交。禁止直接修改服务器代码。包含业务数据的迁移回滚前必须人工确认备份，不对旧 BOM 执行任何 DDL/DML。
