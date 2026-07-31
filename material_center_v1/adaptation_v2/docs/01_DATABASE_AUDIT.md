# 产品适配 V2 第 1 阶段数据库审计

审计时间：2026-07-31 22:37  
数据库：`artdon_new_erp`  
本阶段数据库策略：只备份和审计旧适配相关表，不创建、不修改、不删除任何业务表。

## V2 表前缀

后续 V2 新表统一使用：

```text
mc_pa2_
```

第 1 阶段没有执行 `CREATE TABLE mc_pa2_*`，也没有写入 V2 业务数据。

## 旧适配相关表清单

| 表名 | 行数 |
| --- | ---: |
| `mc_adaptation_approvals` | 0 |
| `mc_adaptation_conditions` | 0 |
| `mc_adaptation_conflicts` | 0 |
| `mc_adaptation_defaults` | 0 |
| `mc_adaptation_groups` | 122 |
| `mc_adaptation_logs` | 70 |
| `mc_adaptation_options` | 6 |
| `mc_adaptation_option_chip_variants` | 2 |
| `mc_adaptation_product_profiles` | 0 |
| `mc_adaptation_published_versions` | 0 |
| `mc_adaptation_reuse_templates` | 0 |
| `mc_config_group_conditions` | 3 |
| `mc_config_group_definitions` | 18 |
| `mc_config_group_material_filters` | 18 |
| `mc_config_group_options` | 14 |
| `mc_config_templates` | 4 |
| `mc_config_template_groups` | 26 |
| `mc_config_template_logs` | 0 |
| `mc_config_template_versions` | 0 |
| `mc_product_power_approved_alternatives` | 0 |
| `mc_product_power_rules` | 4 |
| `mc_product_power_rule_brands` | 0 |
| `mc_product_power_rule_dimming_modes` | 1 |
| `mc_power_match_simulations` | 0 |

完整字段结构已导出到服务器：

```text
/www/wwwroot/Artdon/artdon_erp/material_center_v1/backups/adaptation_v2_phase1_20260731_223720/database_audit.json
```

## 旧表备份

SQL 备份：

```text
/www/wwwroot/Artdon/artdon_erp/material_center_v1/backups/adaptation_v2_phase1_20260731_223720/old_adaptation_tables.sql
```

备份 SHA-256：

```text
3f7b812caf311b1e2a0b2a7552cb02906d4171bec5986ff1bfa5b1605c4741c6
```

## 第 2 阶段建议建模输入

主说明中建议第 2 阶段开始创建基础表：

- `mc_pa2_product_categories`
- `mc_pa2_product_category_mappings`
- `mc_pa2_group_definitions`
- `mc_pa2_group_option_definitions`

第 1 阶段只建立迁移目录：

```text
material_center_v1/adaptation_v2/database/migrations/
```

该目录当前没有可执行迁移文件，防止误创建新业务表。
