# 旧数据库清单（商务中心相关）

实际数据库：`artdon_new_erp`。本阶段所有表均可按权限只读，禁止 `INSERT`、`UPDATE`、`DELETE`、结构修改和触发器。

| 表 | 用途 | 主键 | 重要关联字段 | 风险提示 |
|---|---|---|---|---|
| `crm_users` | 账号与当前用户 | `id` | `role_id`、`department_id`、`status` | 含密码哈希与登录状态，不得返回敏感字段 |
| `crm_roles` | 角色 | `id` | `role_key` | 系统角色不可改 |
| `crm_permissions` | 权限定义 | `id` | `permission_key`、`module` | 只读 |
| `crm_role_permissions` | 角色授权 | `id` | `role_id`、`permission_key` | 只读 |
| `crm_user_permissions` | 用户允许/拒绝 | `id` | `user_id`、`permission_key`、`effect` | 只读 |
| `crm_customers` | CRM 客户 | `id` | `customer_code`、`owner_user_id`、`deleted_at` | 正式客户数据，存在软删除记录 |
| `naming_models` | 型号命名产品 | `id` | `model_no`、`source_id`、`naming`/BOM 字段 | 表宽、同步来源复杂，不以型号作永久关联键 |
| `bom_projects` | BOM 成本项目 | `id` | `project_uid`、`naming_id`、`naming_model_no` | 金额字段为 DECIMAL；关联优先使用 ID/UID |
| `bom_materials` | BOM 物料库 | `id` | `category`、`model`、`name` | 正式成本数据 |
| `plm_projects` | PLM 项目 | `id` | `crm_customer_id`、`bom_project_id`、`quote_id`、`naming_id` | 历史关联字段类型不完全统一 |
| `plm_models` | PLM 产品/样品 | `id` | `project_id`、`quote_product_id`、`naming_id` | 字段多、存在软删除与版本快照 |
| `quote_products` | 报价产品库 | `id` | `code`、`bom_project_uid`、`plm_project_id` | 价格/成本为 DECIMAL |
| `quote_orders` | 历史报价单 | `id` | `quote_no`、`customer_id`、`product_id`、`converted_order_id` | `customer_id` 为字符串，存在 JSON 快照 |
| `quote_sales_orders` | 销售订单 | `id` | `source_quote_id`、`quote_no`、`customer_id` | 正式订单和金额数据 |
| `quote_sales_order_items` | 销售订单明细 | `id` | `order_id` | 正式业务数据 |
| `quote_commission_rules` | 佣金规则 | `id` | `customer_id`、`product_model` | 不以名称/型号作为新系统唯一键 |
| `quote_packaging_profiles` | 包装配置 | `id` | `product_code`、`customer_code` | 正式包装数据 |
| `quote_shipments` | 出货记录 | `id` | `order_id` | 正式出货/单证数据 |
| `dispatch_next_tasks` | 派工待办 | `id` | `task_no`、`created_by`、`assigned_to`、`linked_*` | 正式任务数据，含软删除 |
| `crm_mails` | 邮件 | `id` | `user_id`、`linked_customer_id` | 高敏感正文与地址；V1 不读取正文 |
| `crm_mail_attachments` | 邮件附件 | `id` | 邮件关联字段 | 高敏感文件信息；只检测表存在 |

## 历史数据异常风险

- 同一业务对象的 ID 类型在旧模块间可能为整数、字符串或 UID。
- 多个旧表保存 JSON 快照，不能假定实时一致。
- 软删除字段命名不统一。
- 型号、客户名和物料名可能重复或改变，不能作为新系统唯一关联键。
- `cc_entity_links` 只保存普通索引映射，不向旧表建立外键或级联删除。
