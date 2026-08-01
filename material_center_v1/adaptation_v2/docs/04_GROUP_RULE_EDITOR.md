# 产品适配 V2 第 4 阶段配置组选项、物料来源和规则编辑器

阶段时间：2026-08-01

## 边界

本阶段继续保持：

- 不修改旧版 `material_center_v1/adaptation/`；
- 不修改旧 BOM；
- 不切换正式菜单；
- 只使用 `material_center_v1/adaptation_v2/`；
- 新增表继续使用 `mc_pa2_` 前缀。

## 完成目标

第 4 阶段让配置组真正可配置，不再只是名称清单。

新增能力：

- 物料选择组；
- 属性选择组；
- 混合选择组；
- 数值组；
- 文本组；
- 物料过滤器；
- 属性选项管理；
- 显示条件；
- 必选 / 可选；
- 单选 / 多选；
- 选择数量限制；
- 默认项规则；
- 可视化条件编辑器；
- 规则循环检测。

## 新增 V2 表

| 表 | 用途 |
| --- | --- |
| `mc_pa2_group_behavior_settings` | 配置组行为设置：来源、过滤器、默认规则、数量限制、显示条件、校验 |
| `mc_pa2_rule_definitions` | 配置组之间的显示、隐藏、过滤、默认项和选项限制规则 |

没有修改旧 `mc_adaptation_*` 表，没有修改旧 BOM 表。

## 配置组行为模型

配置组定义仍保存在 `mc_pa2_group_definitions`，第 4 阶段新增一张一对一行为表：

- `selection_kind`：`material / attribute / hybrid / number / text`
- `source_mode`：`official_material / static_options / manual_input / mixed`
- `material_category_code`：正式物料筛选入口；
- `material_filter_json`：物料过滤器；
- `attribute_source_json`：属性选项来源；
- `is_required_default`：默认必选/可选；
- `selection_mode_default`：默认单选/多选；
- `min_select_default / max_select_default`：选择数量限制；
- `default_rule_json`：默认项规则；
- `visibility_condition_json`：显示条件；
- `validation_json`：校验规则。

## 规则模型

规则采用“触发组 → 目标组”的可视化结构：

```text
当 trigger_group_code operator trigger_value
对 target_group_code 执行 effect_action
```

支持动作：

- `show`
- `hide`
- `require`
- `optional`
- `material_filter`
- `set_default`
- `limit_options`

保存规则时会扫描有向图，如果出现循环依赖，系统会拒绝保存并回滚本次写入。

## 首批验收规则

### 导轨灯选择 INTRACK

第 4 阶段新增 `track_system` 属性组：

- `standard_track`：普通导轨；
- `intrack`：INTRACK。

内置规则：

- 选择 `intrack` 后显示 `intrack_connector`；
- 选择 `intrack` 后显示 `intrack_driver`；
- 选择 `intrack` 后隐藏 `track_connector`；
- 选择 `intrack` 后隐藏 `driver`；
- 选择 `standard_track` 后反向显示普通接头和普通电源，隐藏 INTRACK 接头和电源。

### 磁吸灯选择短款

内置规则：

- `body_length = short` 时，对 `magnetic_head` 执行 `material_filter`；
- 过滤条件保存在 `effect_json`，后续第 6 阶段适配计算会读取。

## 页面

本阶段开放：

- `?view=groups`：配置组定义中心，显示和编辑行为设置；
- `?view=rules`：规则编辑器，显示规则卡片、规则表单和循环检测结果。

仍不开放：

- 单产品配置工作台；
- 审批和发布；
- 配置包；
- 商务中心 / 新加坡网站接口。

这些留给后续阶段。
