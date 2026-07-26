# 物料中心执行基线（2026-07-26）

## 固定边界

- 正式入口：`/artdon_erp/material_center_v1/`
- 当前菜单唯一来源：`components/sidebar.php`，执行期间不得改变菜单分组、名称、顺序或入口。
- 当前外壳：`components/layout_top.php`、`components/layout_bottom.php`、`assets/css/app.css`、`assets/js/app.js`。
- 账号、Session、CSRF 和数据库：复用广州 ERP `includes/bootstrap.php`。
- 旧 BOM：`bom_materials`，仅允许 `SELECT`；所有新增及修改数据写入 `mc_*`。

## 执行前状态

- Git 基线：`da701610c69a717cb9e244025eab64b24418bfb6`。
- 本地 Git bundle：`/tmp/material_center_pre_long_run_da70161.bundle`。
- 服务器目录备份：`/tmp/material_center_v1_20260726_long_run_pre.tar.gz`。
- 数据库完整 PDO 备份：`/tmp/artdon_erp_20260726_long_run_pre.json.gz`，315 张表，193138552 字节。
- 数据库备份格式保存每张表的 `SHOW CREATE TABLE` 和全部行，可用于逐表恢复。

## 已有能力

- 4 份可回滚迁移、20–25W 电源暂存与解析、正式物料基础、字段注册、批量任务、生命周期、设置、字段权限、电源产品规则。
- 现有物料外壳另有七类页面、产品适配、供应商、替代、数据、文档、设置入口，但部分仍为静态或占位实现。

## 审计发现

1. 当前外壳合并时覆盖了旧 `bootstrap.php`，命名空间自动加载、数据库和统一账号引导缺失，迁移工具报类不存在。
2. 首页包含静态数量和活动记录，不得作为完成功能。
3. 多个类别和业务页面仍为空壳或演示页面。
4. 旧 UI 测试锁定旧资源入口，需要调整为兼容并锁定当前外壳，不能反向重做 UI。
5. 后续迁移必须只创建或调整 `mc_*`；测试必须在变更前后核对旧 BOM 行数与列定义。
