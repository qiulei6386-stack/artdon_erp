# 电源工作台功能地图

## 二级 Tab

| Tab | 真实来源 | 当前操作 |
|---|---|---|
| 全部 | 旧 BOM 电源 + `mc_materials` 电源 | 搜索、排序、分页、列设置、进入标准化/管理 |
| 来源数据 | `bom_materials` SELECT，关联 `mc_material_import_staging` | 原始资料、解析/映射/重复状态、进入标准化 |
| 待整理 | 暂存状态 pending/parsed/needs_review/rejected | 完整度依据、进入人工确认 |
| 待确认 | parsed/needs_review/duplicate_candidate/confirmed | 人工确认、关联已有、建立正式电源 |
| 正式 | `mc_materials` + `mc_power_supply_specs` | 管理、复制、生命周期、批量设置 |
| 重复候选 | `mc_duplicate_candidates` pending | 查看风险、进入标准化决定 |
| 停用/归档 | material status disabled/archived | 查看、引用检查、恢复 |

## 三级功能

- 功率档：原有真实维护页在工作台内部承载。
- 字段设置：读取字段注册中心真实定义。
- 导出：导出当前可见表格 CSV。
- 映射记录：读取 `mc_legacy_links`。
- 操作日志：读取 `mc_activity_logs`。
- 重复检查：进入重复候选 Tab。
- 适配产品：进入物料中心产品适配。
- 批量导入、解析规则、完整度规则：明确显示“尚未接入”，不伪造成功。

旧 URL 均保留。
