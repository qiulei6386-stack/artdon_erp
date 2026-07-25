# 路由映射 V3

| 旧功能 | 旧 URL | V3 新入口 | 权限/API | 旧 URL |
|---|---|---|---|---|
| 电源源数据 | `power_supplies.php` | `power_workbench.php?tab=source` | `bom.view` / 只读 | 保留 |
| 电源标准化 | `power_standardization.php` | `power_workbench.php?tab=confirm` | standardize / 标准化 API | 保留 |
| 正式电源库 | `formal_power_supplies.php` | `power_workbench.php?tab=formal` | view、batch / materials API | 保留 |
| 功率档 | `power_bands.php` | `power_workbench.php?tab=bands` | standardize / 标准化 API | 保留 |
| BOM审计 | `bom_audit.php` | 电源工作台更多菜单 | view / 只读 | 保留 |
| 产品电源规则 | `product_power_rules.php` | `product_adaptation.php?tab=power` | rules权限/API | 保留 |
| 匹配模拟 | `power_match_simulator.php` | `product_adaptation.php?tab=overview` | simulate权限/API | 保留 |
| 全部物料 | `materials.php` | 原地址 | material权限/API | 保留 |
| 设置 | `settings.php` | 原地址 | settings权限/API | 保留 |
| 系统/组件 | 原地址 | 原地址 | view | 保留 |

电源工作台现为原生路由；旧页面不再依赖 iframe 才能展示来源数据。人工确认旧 URL 支持 `?review={staging_id}` 自动打开对应审核抽屉。

新类别入口：`category_workbench.php?category=chips|optics|accessories|packaging|profiles|mounting`。
