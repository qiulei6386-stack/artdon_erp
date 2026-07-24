# 禁止触碰清单

## 旧目录与文件

- 项目根目录中除 `commercial_center_v1/` 外的全部目录和文件。
- `includes/`，尤其是 `config.php`、`bootstrap.php`、`db.php`、`auth.php`、`permission.php`、`layout.php`。
- `assets/`、`uploads/`、`storage/`、`backup/`、`backups/`、`_migration_backups/`、`_codex_backups/`。
- `index.php`、`login.php`、`permissions.php` 及所有旧模块入口/API。
- 旧菜单、导航、主题、路由、Session 写入和账号系统。

## 旧数据库

- 禁止修改全部非 `cc_` 表。
- 禁止向旧业务表写测试数据。
- 禁止 `ALTER`、`DROP`、`TRUNCATE`、`RENAME` 旧表。
- 禁止创建旧表触发器、删除或替换旧索引、增加字段或强制外键。
- 禁止调用旧系统中会自动补表、补字段或写日志的 schema ensure 路径。
- 禁止真实邮件发送、新加坡写入、真实报价/订单/佣金/出货/单证操作。

如后续功能只能通过修改上述对象实现，必须停止并重新取得明确批准。
