# Material Center V1 进度

## 已完成

- 只读基础服务与旧 BOM 物料总览。
- 统一 UI U0–U6 基础。
- U7 首页、电源列表与详情抽屉、BOM 源审计、系统状态页面。
- UI 组件展示页。
- 安全基线、来源审计、字段映射和当前领域模型文档。
- 统一 DataTable 排序、选择/半选、分页、列宽拖动、双击适配、列显示和行密度。
- 唯一 Dropdown、Modal、ConfirmModal、Drawer、Toast 与 InteractionManager。
- 全部 PHP/JavaScript/静态合同/只读安全检查通过，六个线上入口 HTTP 200。

## 当前边界

- 无数据库写入。
- 无旧表结构变化。
- 无正式电源编辑或迁移。
- 无供应商、采购价和成本展示。

## 下一阶段入口

先从 `docs/POWER_SUPPLY_DOMAIN_MODEL.md` 评审正式电源字段，再设计独立新表与迁移方案；未经批准不得实施数据库变更。
