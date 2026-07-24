# 数据库隔离方案

- 与旧广州系统共用实际数据库 `artdon_new_erp`，不建立第二数据库。
- 新表统一使用 `cc_` 前缀；当前库开发前 `cc_*` 表数量为 0。
- 旧表在第一阶段只读，不建触发器、不改结构、不写测试数据。
- 迁移只位于 `database/migrations/`，回滚只位于 `database/rollback/`。
- 迁移必须可重复执行并使用 `CREATE TABLE IF NOT EXISTS`。
- 本轮迁移执行关闭，只能运行 `database/dry_run.php` 查看计划。
- 测试数据只能进入 `cc_*`，并用 `is_test=1` 标记。
- 正式执行迁移前重新记录表、字段、索引、触发器基线并做数据库快照。
- 金额采用 `DECIMAL(18,4)`、汇率 `DECIMAL(18,8)`、数量 `DECIMAL(18,3)`；本轮基础日志/映射表暂无金额字段。
- 核心对象使用永久 ID；`cc_entity_links` 映射新 ID 与旧 `source_table/source_id`。
- 不向旧表建立外键和级联删除，因为旧 ID 类型、软删除和生命周期不统一，级联会扩大故障范围。

拟建但未执行：`cc_schema_migrations`、`cc_entity_links`、`cc_integration_logs`、`cc_activity_logs`。
