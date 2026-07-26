# 电源工作台功能映射

更新时间：2026-07-25

| 原功能 | 原入口 | 新位置 | 保留方式 |
|---|---|---|---|
| 全部记录 | `?tab=all` | 全部 | 原服务 |
| 来源数据 | `?tab=source`、`power_supplies.php` | 全部 + 来源=BOM旧数据 | 参数兼容映射，旧页面保留 |
| 待整理 | `?tab=organize` | 待整理 | 原服务 |
| 待确认 | `?tab=confirm` | 待确认 | 原服务 |
| 正式电源 | `?tab=formal`、`formal_power_supplies.php` | 正式 | 原服务及旧页面保留 |
| 重复候选 | `?tab=duplicates` | 异常 + 重复候选 | 参数兼容映射 |
| 停用/归档 | `?tab=archived` | 异常 + 归档 | 参数兼容映射 |
| 电源设置 | 已删除 `power_standardization.php` | `material/power.php` 行内抽屉直接确认并保存 | 独立页面删除 |
| 功率档管理 | `power_bands.php` | 更多 → 功率档设置 | 原页面嵌入 |
| 字段设置 | `?panel=fields` | 更多 → 字段设置 | 原服务 |
| 批量导入 | 原更多菜单 | 更多 → 批量导入 | 保留未接入真实反馈 |
| 导出 | `?panel=export` | 更多 → 导出 | 当前权限结果 CSV |
| 映射记录 | `?panel=mappings` | 更多 → 映射记录 | 原服务 |
| 操作日志 | `?panel=logs` | 更多 → 操作日志 | 原服务 |
| 解析规则 | 原更多菜单 | 更多 → 解析规则 | 保留未接入真实反馈 |
| 新建电源 | `materials.php` | 标题区唯一主按钮 | 原物料入口 |

映射功能 16 项；失联功能 0 项。
