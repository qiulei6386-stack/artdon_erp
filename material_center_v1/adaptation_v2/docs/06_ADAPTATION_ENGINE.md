# 产品适配 V2 第 6 阶段：适配计算和冲突引擎

## 范围

本阶段只增强 V2 独立目录：

- `/material_center_v1/adaptation_v2/`
- 新增表统一使用 `mc_pa2_` 前缀
- 不修改旧版产品适配业务
- 不修改旧 BOM
- 不切换正式菜单

## 新增数据表

| 表 | 用途 |
| --- | --- |
| `mc_pa2_adaptation_result_cache` | 缓存每个产品配置组/候选物料的适配结论、匹配度、原因和规则轨迹 |
| `mc_pa2_adaptation_conflicts` | 保存条件适配、需要审批、不适配对应的冲突明细 |
| `mc_pa2_adaptation_recalc_jobs` | 记录手动或保存触发的重新计算任务 |

## 适配结论

每条候选物料必须落到以下四类之一：

1. `full_match`：完全适配；
2. `conditional_match`：条件适配，需要人工确认缺失或边界资料；
3. `approval_required`：需要审批，常见于非正式物料或例外选择；
4. `incompatible`：不适配，存在明确阻断原因。

页面显示中文：

- 完全适配
- 条件适配
- 需要审批
- 不适配

## 当前计算逻辑

第 6 阶段优先读取现有正式物料资料：

- `mc_materials`
- `mc_material_categories`
- `mc_material_metadata`
- `mc_power_supply_specs`
- `mc_power_supply_current_options`
- `mc_power_supply_dimming_modes`
- `mc_material_chip`
- `mc_material_optical`
- `mc_material_connector`
- `mc_material_accessory`

产品侧技术范围来自 `mc_products.snapshot_json`、型号、名称、V2 分类和系列文本的保守解析：

- 功率 W；
- 输出电流 mA；
- 光束角；
- CCT；
- CRI；
- IP；
- INTRACK 标记。

资料不足时，不直接判定完全适配，而是返回“条件适配”并说明缺少哪些字段。

## API

新增/增强：

- `action=workspace_recalculate`
- `action=adaptation_results`
- `action=material_candidates&product_id=...&product_group_config_id=...`

保存配置组后，如果第 6 阶段表已迁移，系统会自动触发一次重新计算。

## 验收点

1. 工作台卡片显示已缓存的适配结论。
2. 宽版候选弹窗中每条候选显示适配结论、匹配度和原因。
3. 底部栏显示完全适配、条件适配、需要审批、不适配数量。
4. 点击“重新计算”会刷新 V2 结果缓存。
5. 适配结果只写入 `mc_pa2_*` 表，不写旧 BOM 和旧适配表。
