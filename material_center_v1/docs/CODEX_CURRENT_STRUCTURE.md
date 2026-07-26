# 物料中心当前结构（2026-07-26）

## 固定边界

- 正式入口：`/artdon_erp/material_center_v1/`。
- 当前菜单唯一来源：`components/sidebar.php`；本次未改变分组、名称、顺序或入口。
- 当前外壳：`components/layout_top.php`、`components/layout_bottom.php`、`assets/css/app.css`、`assets/js/app.js`，未重新设计。
- 账号、Session、CSRF、数据库和旧权限回退均复用广州 ERP。
- 旧 BOM 来源为 `bom_materials`，适配器仅允许 SELECT；新业务只写 `mc_*`。
- 旧命名产品来源为 `naming_models`，只读同步至 `mc_products`。

## 基线与备份

- 执行基线：`da701610c69a717cb9e244025eab64b24418bfb6`。
- 本地 Git bundle：`/tmp/material_center_pre_long_run_da70161.bundle`。
- 服务器目录备份：`/tmp/material_center_v1_20260726_long_run_pre.tar.gz`。
- 全数据库 PDO 备份：`/tmp/artdon_erp_20260726_long_run_pre.json.gz`，包含逐表建表语句和数据。

## 当前实现

- 迁移 `001–012` 均提供 up/down；七类物料字段、生命周期、批量、来源、导入、供应商、价格审批、适配、替代、文档、设置和权限均落在 `mc_*`。
- 八个物料入口使用字段注册中心和真实数据库；支持新增、编辑、复制、状态流转、引用检查、批量和权限过滤导出。
- BOM 快照、CSV/XLSX 物料导入、错误 CSV、供应商价格导入均是真实任务。
- 供应商支持联系人、供应商物料、价格历史、阶梯价、MOQ、交期、首选供应商、审批和附件。
- 产品适配只读同步命名系统，支持配置组、选项、条件、冲突、价格/交期影响、审批和只读结果。
- 替代支持四类关系、循环拒绝、影响分析、引用迁移及精确回滚。
- 文档支持签名校验、版本、SHA-256、访问级别、预览和下载；设置支持版本、导入导出和恢复默认。
- 旧兼容页面保留；可达业务导航已指向现行真实页面，不再指向占位动作。

## 非阻断限制

自动化环境没有可连接的登录态浏览器实例；实际 Chrome/Edge/Safari 多分辨率截图需人工补验。HTTP、页面渲染、静态合同和响应式 CSS 已自动检查，详情见 `KNOWN_ISSUES.md`。
