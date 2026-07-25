# 集成边界

- 账号：只读广州 ERP 当前登录态。
- 旧 BOM：仅经 `LegacyBomMaterialAdapter` SELECT。
- 旧产品：仅经 `LegacyProductAdapter` SELECT `naming_models`。
- 新数据：只写 `mc_` 表。
- F9 只建立规则和模拟结果，不写产品、不写 BOM、不生成报价、不进入产品电源适配执行。
- 旧系统、`commercial_center_v1` 及旧数据库结构均不在修改范围。
