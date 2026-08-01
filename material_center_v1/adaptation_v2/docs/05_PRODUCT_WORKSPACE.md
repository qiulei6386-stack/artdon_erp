# 产品适配 V2 第 5 阶段单产品配置工作台

阶段时间：2026-08-01

## 边界

本阶段继续保持：

- 不修改旧版 `material_center_v1/adaptation/`；
- 不修改旧 BOM；
- 不切换正式菜单；
- 只在 `material_center_v1/adaptation_v2/` 开发；
- 新增表继续使用 `mc_pa2_` 前缀。

## 完成目标

建立简单、可用、模板驱动的单产品配置页面。

默认流程：

```text
确认配置来源
→ 设置核心配置
→ 检查和保存
```

## 新增 V2 表

| 表 | 用途 |
| --- | --- |
| `mc_pa2_product_configs` | 单产品配置主表，记录产品、分类、来源模板和当前草稿/发布版本 |
| `mc_pa2_product_config_versions` | 产品配置版本，第 5 阶段先保存草稿版本 |
| `mc_pa2_product_group_configs` | 产品配置组，来自模板继承结果，可保存产品级差异 |
| `mc_pa2_product_selected_options` | 每个配置组的已选物料、属性、数值、文本或布尔值 |

## 工作台能力

页面入口：

```text
/material_center_v1/adaptation_v2/index.php?view=workspace
/material_center_v1/adaptation_v2/index.php?view=workspace&product_id=100
```

已实现：

- 产品摘要；
- 模板来源；
- 模板继承结果生成产品配置组；
- 核心配置卡片；
- 动态配置组；
- 需要补充数量；
- 宽版物料选择弹窗；
- 属性选项、数值、文本配置保存；
- 候选物料列表；
- 保存草稿；
- 配置检查摘要；
- 高级设置入口保留为后续阶段。

## 不属于本阶段

本阶段不做：

- 适配计算打分；
- 冲突引擎；
- 例外审批；
- 发布版本；
- 配置包；
- 商务中心读取；
- 新加坡网站接口。

这些属于第 6 阶段以后。

## 候选物料

候选物料从现有正式物料主数据只读获取：

- `mc_materials`
- `mc_material_categories`
- `mc_material_metadata`

第 5 阶段按第 4 阶段配置组行为中的 `material_category_code` 和 `material_filter_json` 做轻量过滤。

第 6 阶段再接入完整适配计算、匹配度、冲突和替代推荐。
