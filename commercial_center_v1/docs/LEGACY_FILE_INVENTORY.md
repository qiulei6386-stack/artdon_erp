# 旧系统文件清单

所有下列文件均来自实际仓库扫描。本阶段默认只允许读取，禁止修改。

| 系统 | 入口/主要文件 | 依赖或接口 | 本阶段 |
|---|---|---|---|
| 统一启动 | `includes/bootstrap.php` | `includes/db.php`、`includes/auth.php`、`includes/permission.php` | 只读加载 |
| 登录 | `login.php` | `includes/auth.php` | 禁止修改 |
| 权限 | `permissions.php` | `includes/permission.php`、`crm_auth.php` | 禁止修改 |
| 公共布局 | `includes/layout.php` | 旧主题与公共组件 | 禁止修改 |
| 公共资源 | `assets/`、`assets/crm/` | CRM 与门户 CSS/JS | 禁止修改 |
| 型号命名 | `naming.php` | `naming_models`、`naming_rules`、`naming_inbox` | 仅适配器读取 |
| BOM | `bom.php`、`bom_api.php` | `bom_projects`、`bom_materials` | 仅适配器读取 |
| PLM | `plm.php`、`plm_api.php`、`plm_auth.php` | `plm_projects`、`plm_models` | 仅适配器读取 |
| CRM | `crm.php`、`crm_api.php`、`crm_customer.php` | `crm_customers` 及 CRM 关联表 | 仅适配器读取 |
| 报价 | `quotation.php`、`quote_api.php` | `quote_orders`、`quote_products` | 仅适配器读取 |
| 订单 | `quote_order_api.php`、`quotation_order_api.php` | `quote_sales_orders`、`quote_sales_order_items` | 仅适配器读取 |
| 佣金 | `quote_api.php` | `quote_commission_*` | 仅适配器读取 |
| 包装/出货 | `quote_order_doc.php`、`quote_order_pdf.php`、`quote_order_excel.php` | `quote_packaging_profiles`、`quote_shipments` | 仅适配器读取 |
| 单证 | `quote_order_doc.php`、`quote_order_pi_export.php` | 订单快照与出货表 | 仅适配器读取 |
| 邮件 | `mail.php`、`crm_mail.php`、`crm_mail_cron.php` | `crm_mails`、`crm_mail_attachments` | 只做状态检测 |
| 派工待办 | `dispatch_next.php`、`dispatch_next_api.php`、`dispatch_next_schema.php` | `dispatch_next_tasks` 及关联表 | 仅适配器读取 |

新模块不会 include 旧业务页面或旧 API，只加载统一 `includes/bootstrap.php`，并通过独立适配器读取。
