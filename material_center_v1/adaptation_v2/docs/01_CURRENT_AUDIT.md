# 产品适配 V2 第 1 阶段当前审计

审计时间：2026-07-31 22:37  
审计范围：旧版 `material_center_v1/adaptation/`、旧适配 API、旧适配服务、旧适配前端脚本、旧适配迁移和专项测试。  
执行边界：本阶段只做冻结、审计和 V2 蓝图落地；未修改旧版产品适配业务、未修改旧 BOM、未切换正式菜单。

## 旧版保留状态

- 正式菜单仍指向 `material_center_v1/adaptation/index.php`。
- 旧版页面目录仍为 `material_center_v1/adaptation/`，本阶段不改动。
- 旧版业务 API 仍为 `material_center_v1/api/v1/adaptation.php`，本阶段不改动。
- 旧 BOM 读取仍通过 `LegacyBomMaterialAdapter` 等既有只读适配器完成，本阶段不改动。
- V2 仅使用独立旁路目录 `material_center_v1/adaptation_v2/`。

## 服务器备份

备份目录：

```text
/www/wwwroot/Artdon/artdon_erp/material_center_v1/backups/adaptation_v2_phase1_20260731_223720/
```

备份文件：

| 文件 | 内容 | SHA-256 |
| --- | --- | --- |
| `adaptation_directory.tar.gz` | 旧 `adaptation/` 目录包，约 21MB | `98e5704abf4c68f638b0d77cda2209606e3cd156e55593d17934f010abdc8801` |
| `old_adaptation_tables.sql` | 旧适配相关 24 张表 SQL 备份，约 80KB | `3f7b812caf311b1e2a0b2a7552cb02906d4171bec5986ff1bfa5b1605c4741c6` |
| `database_audit.json` | 旧适配相关表结构和行数审计，约 70KB | `34ecf8712f425cb27f9b599a36eb667b9ffd6aae90378a7b880f9d4fe0d77701` |
| `table_list.txt` | 本次备份表清单 | `d3b9b86f6a875d361b897eb365daf2cdd80d9e7946195d7a5f4e64f516ce6218` |

## 旧版关联代码

| 类型 | 文件 |
| --- | --- |
| 页面入口 | `material_center_v1/adaptation/index.php` |
| 业务 API | `material_center_v1/api/v1/adaptation.php` |
| 核心服务 | `material_center_v1/app/Services/AdaptationService.php` |
| 前端脚本 | `material_center_v1/assets/js/adaptation-v3.js`、`material_center_v1/assets/js/adaptation-shell.js` |
| 旧适配文档 | `material_center_v1/adaptation/docs/PRODUCT_STATUS_RECALCULATION.md` |
| 旧适配迁移 | `20260726_015_adaptation_workflow_v2.php`、`20260727_017_adaptation_quick_rules_batch.php`、`20260728_019_adaptation_reuse_templates.php`、`20260728_020_adaptation_power_range.php`、`20260729_021_adaptation_published_versions.php`、`20260729_022_adaptation_product_profiles.php` |
| 旧适配测试 | `material_center_v1/tests/*adaptation*`、`material_center_v1/tests/product_adaptation_workflow_contract.php` |

## 旧功能清单

旧版当前承担以下能力：

- 产品适配首页、全部产品配置、单产品工作台、配置模板、批量工具。
- 产品选择、搜索、按产品状态筛选。
- 快速三步配置：配置来源、核心配置、检查并保存。
- 高级设置：技术范围、扩展选项、条件规则、替代、审批、版本。
- 配置组新增、排序、删除、默认设置、选项条件、冲突规则。
- 候选物料搜索、选择、强制例外说明、芯片规格变体选择。
- 复用现有产品配置、保存复用模板、批量套用到目标产品。
- 电源范围、调光、功率、电流、电压、尺寸、质保等快速规则。
- 配置发布快照、审批记录、操作日志和状态重算。

## V2 第 1 阶段落地内容

- 新增独立目录 `material_center_v1/adaptation_v2/`。
- 新增 V2 空页面入口 `adaptation_v2/index.php`。
- 新增 V2 统一 API 响应工具 `adaptation_v2/lib/response.php`。
- 新增 V2 状态 API `adaptation_v2/api/index.php`。
- 新增 V2 迁移目录 `adaptation_v2/database/migrations/`，当前只保留目录占位，不执行建表。
- 新增第 1 阶段审计、路由、数据库和执行日志文档。

## 2026-08-01 后续 UI 审计补充

- 配置组定义中心“新增配置组”已由页面内联表单调整为窄版弹窗。
- 调整范围仅限 `material_center_v1/adaptation_v2/index.php` 页面结构、样式和前端打开/关闭逻辑。
- 保存接口仍沿用 V2 `group_save`，没有新增或变更数据库结构。
- 配置组定义中心的类型、行为来源、物料分类、选择方式和过滤器摘要已做中文化展示；内部编码仍保留英文，确保模板、规则、配置包和 API 关联不变。
- 旧版产品适配目录 `material_center_v1/adaptation/`、旧 BOM、正式菜单均未改动。

## 风险和下一阶段输入

- 旧版已经多次迭代，页面、模板、批量套用、电源范围和发布快照揉在同一服务中，V2 后续不应继续在旧服务上叠加。
- V2 新表必须统一使用 `mc_pa2_` 前缀，避免与 `mc_adaptation_*`、`mc_config_*`、`mc_product_power_*` 混淆。
- 第 2 阶段开始前需要基于主说明创建 `mc_pa2_product_categories`、`mc_pa2_product_category_mappings`、`mc_pa2_group_definitions` 和 `mc_pa2_group_option_definitions` 等基础模型；本阶段没有创建这些表。
