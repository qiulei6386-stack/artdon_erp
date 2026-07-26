# Artdon 商务中心 V1：Step 2 现有报价系统审计

> 状态：Step 2 完成审计
>
> 审计日期：2026-07-26
>
> 适用目录：`artdon_erp/` 与 `commercial_center_v1/`
>
> 依据文件：`docs/Artdon_商务中心_报价逻辑十步实施说明(1).md`
>
> 本步骤只进行只读检查和记录，不修改报价业务逻辑、数据库结构、路由、接口或历史数据，不进入 Step 3。

## 1. 审计结论

当前存在两套数据外形，但只有旧报价模型承载正式数据：

1. 根目录旧报价中心以 `quote_orders` 为报价主表，配合 `quote_*` 客户、产品、价格、权限、日志、订单、出货与单证表运行。现有保存、审核、导出和转订单能力集中在根目录旧接口中。
2. `cc_quotes`、`cc_quote_versions`、`cc_quote_items`、`cc_quote_item_snapshots` 已存在，但四张表均为 0 行，尚未形成正式报价闭环。
3. 商务中心当前报价中心主要是正式 UI 和只读展示入口，尚未接入统一的正式保存 API；部分草稿仍由浏览器本地状态承载。
4. 正式报价/PI 输出应复用根目录 `crm_quote_pdf.php`、`crm_quote_excel.php`；正式 CI/PL 应复用 `quote_order_doc.php`、`quote_order_excel.php` 及其桥接入口。
5. `commercial_center_v1/modules/documents/Templates/shared/legacy_v1.php` 明确标记为演示数据，不是现行正式单证模板，Step 10 不得将其当作正式模板替换旧输出。

## 2. 报价相关表清单与字段清单

以下结果来自服务器正式数据库的只读元数据与行数检查。行数仅用于记录审计时数据规模，不作为后续迁移断言。

### 2.1 新商务中心预建报价表

| 表 | 审计行数 | 字段 |
|---|---:|---|
| `cc_quotes` | 0 | `id`, `quote_no`, `legacy_customer_id`, `customer_snapshot`, `quote_type`, `currency`, `language`, `current_version`, `status`, `total_amount`, `total_cost`, `is_test`, `created_by_legacy_user_id`, `created_at`, `updated_at` |
| `cc_quote_versions` | 0 | `id`, `quote_id`, `version_no`, `customer_snapshot`, `terms_snapshot`, `pricing_snapshot`, `cost_snapshot`, `exchange_rate`, `template_version`, `status`, `created_by_legacy_user_id`, `created_at` |
| `cc_quote_items` | 0 | `id`, `quote_version_id`, `item_type`, `legacy_product_id`, `inventory_sku_id`, `description`, `configuration_snapshot`, `quantity`, `unit_price`, `cost_amount`, `discount_rate`, `line_amount`, `sort_order`, `created_at`, `updated_at` |
| `cc_quote_item_snapshots` | 0 | `id`, `quote_item_id`, `product_snapshot`, `configuration_snapshot`, `price_snapshot`, `cost_snapshot`, `snapshot_hash`, `created_at` |

审计判断：上述结构具备报价、版本、明细和明细快照雏形，但缺少文件、明细文件、独立审批、完整操作日志等 Step 3/4 所需结构，且没有正式数据。后续应先制定兼容/迁移方案，不得直接新建第三套报价模型或删除旧表。

### 2.2 旧报价主数据、配置与审计表

| 表 | 审计行数 | 主要字段 |
|---|---:|---|
| `quote_orders` | 35 | `id`, `quote_no`, `quote_date`, `user_name`, `customer_id`, `customer_name`, `customer_json`, `header_id`, `bank_id`, `template_id`, `header_json`, `bank_json`, `template_json`, `product_type`, `product_id`, `product_json`, `parts_json`, `items_json`, `qty`, `price`, `amount`, `currency`, `exchange_rate`, `moq`, `color`, `cct`, `cri`, `ip`, `extra_spec`, `status`, `quote_status`, `version_no`, `price_level_id`, `price_level_name`, `price_multiplier`, `converted_order_id`, `converted_order_no`, `approval_status`, `submitted_by`, `submitted_at`, `approved_by`, `approved_at`, `rejected_by`, `rejected_at`, `approval_note`, `approval_items_json`, `approval_log_json`, `approved_snapshot_json`, `locked_at`, `reject_reason_category`, `reject_reason_custom`, `reject_reason_detail`, `commission_json`, `created_at`, `updated_at` |
| `quote_customers` | 2 | `id`, `code`, `company`, `contact`, `email`, `phone`, `country`, `note`, `source`, `crm_customer_id`, `owner`, `website`, `address1`, `address2`, `addresses_json`, `primary_contact`, `primary_contact_phone`, `primary_contact_email`, `created_at`, `updated_at` |
| `quote_products` | 13 | 产品 ID、型号/编码、名称、系列、类别、安装方式、供应商、标签、尺寸、功率、IP、颜色、MOQ、价格、成本、BOM、图片、PLM、状态及审计字段 |
| `quote_headers` | 6 | `id`, `name`, `company`, `from_text`, `stamp`, `show_stamp`, `created_at` |
| `quote_banks` | 5 | `id`, `name`, `text`, `extra_terms`, `extra_terms_font_size`, `created_at` |
| `quote_templates` | 6 | 模板标识、名称、模板内容/配置、状态、排序及时间字段 |
| `quote_options` | 6 | `id`, `group_key`, `value`, `label`, `note`, `is_active`, `sort_order`, `created_at` |
| `quote_system_settings` | 1 | 系统设置键值/JSON 与更新时间字段 |
| `quote_document_settings` | 0 | `id`, `settings_json`, `updated_by`, `updated_at` |
| `quote_logs` | 7705 | `id`, `created_at`, `level`, `module`, `action`, `event`, `quote_id`, `quote_no`, `customer_id`, `customer_name`, `user_name`, `ip`, `user_agent`, `request_method`, `request_uri`, `summary`, `detail_json`, `before_json`, `after_json` |

### 2.3 价格、库存、BOM 与佣金表

| 表 | 审计行数 | 主要字段/用途 |
|---|---:|---|
| `quote_price_policies` | 224 | 产品/命名/型号/名称/系列/灯具/分类/图片、库存、MOQ、交期、币种、价格模式、等级、BOM 成本、基础成本/价格、状态和审计字段 |
| `quote_price_policy_levels` | 5 | 价格等级、倍率/折扣、启用、排序与审计字段 |
| `quote_price_policy_options` | 47 | 策略配置分组、值、标签、启用、排序与审计字段 |
| `quote_price_levels` | 7 | 客户价格等级、倍率/折扣、启用与排序字段 |
| `quote_price_tiers` | 0 | `id`, `policy_id`, `min_qty`, `manual_price`, `auto_price`, `final_price`, `currency`, `source`, `note`, `sort_order`, `created_at`, `updated_at` |
| `quote_price_stock_logs` | 0 | 价格/库存调整前后值、原因、操作人和时间字段 |
| `quote_commission_options` | 33 | 佣金配置分组、值、标签、启用与排序 |
| `quote_commission_reminder_rules` | 5 | 提醒条件、阈值、对象、消息、启用与审计字段 |
| `quote_commission_rules` | 0 | 规则对象、范围、模式、数值、币种、优先级、启用与审计字段 |
| `quote_commission_lines` | 0 | 报价/订单、规则、目标、计算基数、佣金金额、币种、结算状态与审计字段 |
| `quote_commission_item_snapshots` | 0 | 报价/订单明细级佣金计算快照与审计字段 |
| `quote_commission_snapshots` | 0 | 报价/订单级佣金快照、JSON、哈希及审计字段 |

### 2.4 权限、订单、收款、出货和单证表

| 表 | 审计行数 | 主要字段/用途 |
|---|---:|---|
| `quote_user_permissions` | 13 | 用户标识及查看、新建、编辑、审核、删除、历史、客户、产品、物料、单证、汇率、设置、导出、订单、日志等权限 |
| `quote_permission_hidden_accounts` | 1 | 权限界面隐藏账号与审计字段 |
| `quote_user_permissions_pre_perm_v3_20260623` | 2 | 权限升级前备份；只读保留 |
| `quote_sales_orders` | 8 | 订单号、来源报价、客户/Header/Bank/Template/Items/Snapshot JSON、数量、金额、币种、汇率、日期、状态、出货/收款/余额、单证标题、备注、操作人与时间 |
| `quote_sales_order_items` | 25 | 订单、行号、客户型号、产品编码、规格、颜色、数量、单价、金额、已出货数、图片、`item_json` |
| `quote_order_payments` | 5 | `id`, `order_id`, `payment_type`, `payment_date`, `amount`, `currency`, `method`, `bank_ref`, `note`, `created_by`, `created_at`, `commission_deduct_amount`, `commission_deduct_snapshot_id`, `commission_deduct_note` |
| `quote_packaging_profiles` | 2 | 产品/客户代码、毛重、净重、每箱数量、箱尺寸、CBM、包装方式、备注与时间 |
| `quote_shipments` | 2 | 订单、出货号、PL/CI 编号、运输资料、汇总、单证生成时间与审计字段 |
| `quote_shipment_items` | 3 | 出货、订单明细、数量、包装/金额快照和审计字段 |
| `quote_shipment_cartons` | 0 | 出货箱号、尺寸、毛净重、体积、装箱内容与审计字段 |

### 2.5 数据模型缺口

- 没有现成的 `quotations`、`quotation_items`、`quotation_files`、`quotation_item_files`、`quotation_versions`、`quotation_snapshots`、`quotation_logs`、`quotation_approvals` 表。
- 旧报价明细主要保存在 `quote_orders.items_json`，审核记录和快照分别嵌入 `approval_log_json`、`approved_snapshot_json`，不是规范化的多版本结构。
- `cc_quote*` 预建表没有文件和审批表，也没有正式数据。
- 报价类型尚未统一为 `website_order`、`standard_product`、`custom_product`。
- 后续迁移必须保持 35 条旧报价、8 条订单和现有单证关联可读，且迁移脚本必须可重复安全执行。

## 3. 页面、路由和 API 清单

### 3.1 商务中心现有路由

| 入口 | 当前文件/映射 | 现状 |
|---|---|---|
| `?page=quote_center` | `views/quote_center.php` → `views/quote_custom.php` | 报价中心工作台 |
| `?page=quote_center&quote_mode=website` | `views/quote_website.php` | 网站订单报价 UI |
| `?page=quote_center&quote_mode=standard` | `views/quote_standard.php` | 标准品报价 UI |
| `?page=quote_center&quote_mode=standard&quick=1` | `views/quote_standard.php` | 快速标准品报价 UI |
| `?page=quote_center&quote_mode=custom` | `views/quote_custom.php` | 当前仍落到工作台 |
| `?page=quote_center&quote_mode=custom&editor=1` | `views/quote_custom.php` | 当前仍落到工作台，定制编辑入口未独立 |
| `?page=commercial_product_library` | `views/product_library_v2.php` | 报价产品库 |
| `?page=price_strategy` | `views/price_strategy.php` | 价格策略中心 |
| `?page=quote_approval` | `views/quote_approval.php` | 报价审核 UI |
| `?page=quote_templates` | 通用页面 | 报价模板占位入口 |
| `?page=tier_prices` | 通用页面 | 阶梯价格占位入口 |
| `?page=product_config` | 安全映射至 `compatibility_rules` | 旧产品配置入口 |
| `api/v1/configurator.php` | 配置器 API | 非正式报价保存 API |
| `api/v1/health.php` | 健康检查 | 只读健康入口 |

旧入口继续由 `index.php` 接收：`standard_quote` → 标准品报价，`quick_quote` → 快速标准品报价，`quote_history` → 报价中心，`product_config` → 适配规则。不得删除这些兼容映射。

### 3.2 原报价中心与单证入口

| 文件/入口 | 用途 |
|---|---|
| `quotation.php` | 旧报价中心正式 UI，保存、编辑、复制、预览、审核、导出、转订单等交互入口 |
| `quote_api.php?action=...` | 报价、客户、产品、价格、审核、权限、日志、BOM、佣金、备份的旧统一 API |
| `crm_quote_pdf.php` | 正式报价 PDF/打印输出；外币使用 Proforma Invoice，人民币使用订购合同 |
| `crm_quote_excel.php` | 与正式报价版式/数据对应的 Excel 输出 |
| `quote_order_api.php?action=...` | 报价转订单、订单、收款、出货、单证、佣金 API |
| `quotation_order_api.php` | `quote_order_api.php` 的兼容包装入口 |
| `quote_order_doc.php` | Commercial Invoice / Packing List 的 HTML、打印与 PDF 生成入口 |
| `quote_order_excel.php` | Commercial Invoice / Packing List 的 XLSX 生成入口 |
| `quote_order_pdf.php` | 设置 PDF 格式后调用 `quote_order_doc.php` 的兼容入口 |
| `quote_order_export_bridge.php` | 旧链接至当前订单单证输出的兼容桥 |
| `quote_order_pi_export.php` | 较早的 PI/订单导出入口；保留兼容，不作为新正式模板来源 |
| `quote_order_config.php` | 订单单证依赖配置 |

## 4. API 动作清单

### 4.1 `quote_api.php`

主要动作按业务归类如下：

- 报价：`save_quote`, `get_quote_detail`, `list_quote_details`, `delete_quote`, `submit`, `get_approved_quote_snapshot`
- 审核：`list_pending_quotes`, `approve_quote`, `reject_quote`, `unapprove_quote`
- 客户：`save_customer`, `delete_customer`, `batch_delete_customers`, `align_crm_customers`, `sync_crm_customers`, `clean_stale_crm_customers`
- 产品/BOM：`save_product`, `delete_product`, `list_bom_quote_specs`, `ensure_bom_quote_spec`, `save_bom_quote_spec`, `delete_bom_quote_spec`, `sync_bom_quote_spec`, `bom_debug`, `sync_naming_products`
- 价格：`price_policy_list`, `price_policy_match`, `price_policy_save`, `price_policy_batch_save`, `price_policy_delete`, `price_policy_import_excel`, `price_policy_export_excel`, `price_policy_sync_bom_costs`, `price_tier_list`, `price_tier_save`, `price_tier_delete`, `price_level_save`, `price_level_delete`, `price_stock_adjust`, `price_stock_log_list`
- 佣金：规则、选项、报价行、订单、提醒的 `list/save/batch_save/delete/toggle/import/export/calc_preview/customer_check` 系列动作
- 模板与基础资料：`save_header`, `delete_header`, `save_bank`, `delete_bank`, `save_template`, `delete_template`, `save_option`, `delete_option`, `save_exchange_rate`
- 权限与登录：`login`, `logout`, `auth_status`, `permission_feature_defs`, `list_permission_users`, `save_user_permission`, `reset_user_permission`
- 日志与备份：`list_logs`, `log_event`, `log_health`, `list_backups`, `create_backup`, `download_backup`, `restore_backup`
- 订单兼容：`push_order_crm_notice`, `void_sales_order`, `delete_test_order`

### 4.2 `quote_order_api.php`

- 订单：`convert`, `list`, `detail`, `update_status`
- 收款：`save_payment`, `delete_payment`
- 包装：`packaging_list`, `save_packaging`, `delete_packaging`
- 出货：`prepare_shipment`, `create_shipment`, `shipment_detail`
- 单证：`next_doc_numbers`, `quick_document`, `list_documents`, `mark_document_generated`, `get_document_settings`, `save_document_settings`
- 佣金：`commission_history`, `commission_line_save`, `commission_line_batch_save`, `commission_order_list`, `commission_order_save`, `commission_order_batch_save`, `commission_snapshot_list`, `commission_payment_info`, `commission_settle`, `commission_unsettle`
- 测试数据：`clear_quote_order_test_data`

风险边界：`delete_quote` 为硬删除动作，另有删除客户/产品/模板、恢复备份和清测试数据等高风险动作。Step 2 不调用这些动作；后续必须加权限、审计、软删除或明确的安全边界，不能静默沿用到新商务中心。

## 5. 现有功能清单

| 功能 | 现有实现 | 审计结论 |
|---|---|---|
| 保存与编辑 | `quote_api.php?action=save_quote`，`get_quote_detail` | 正式旧能力，需兼容读取并迁移至统一服务 |
| 列表与历史 | `list_quote_details`、旧 UI | 有正式数据；商务中心当前列表尚未真正接入 |
| 复制 | `quotation.php` 前端读取旧详情后另存 | 保留行为，后续改由统一报价服务完成 |
| 预览/打印 | `crm_quote_pdf.php`、`quote_order_doc.php` HTML | 正式版式来源，必须保护 |
| PDF/Excel | `crm_quote_pdf.php`, `crm_quote_excel.php`, `quote_order_doc.php`, `quote_order_excel.php` | Step 10 原样复用 |
| 审核 | `submit`, `approve_quote`, `reject_quote`, `unapprove_quote` | 审核快照与锁定已存在，但模型嵌入主表 |
| 审核快照 | `approved_snapshot_json`, `locked_at` | 正式输出已要求读取已审核快照 |
| 客户联动 | `quote_customers` 与 CRM 对齐/同步动作 | 应改为 CRM 主数据只读关联，避免重复维护 |
| 产品联动 | `quote_products`、命名产品同步及产品目录 | 有旧产品能力，商务中心另有只读正式目录 |
| 价格/阶梯价 | 价格策略、等级、选项、tiers API | 有模型和 API；阶梯表审计时为空 |
| BOM 成本 | BOM spec、成本同步与调试动作 | 有现成计算来源，后续接统一服务 |
| 佣金提醒 | 佣金选项、规则、提醒、快照、结算动作 | 结构较完整，但正式业务数据较少/为空 |
| 转订单 | `quote_order_api.php?action=convert` | 生成 `quote_sales_orders/items` 并回写报价转换编号 |
| PI | 报价正式导出模板兼作订单 PI | 外币标题 Proforma Invoice，人民币标题订购合同 |
| CI/PL | 订单出货和 `quote_order_doc/excel` | 使用同一出货数据源生成 |
| 日志追溯 | `quote_logs`、审批 JSON | 已有大量日志，需纳入统一日志映射 |
| 权限 | `quote_user_permissions` | 已覆盖报价、审核、导出、订单、日志等细粒度权限 |
| 发送 | 未发现稳定、独立的正式报价发送服务契约 | 后续需核对邮件模块并补齐，不能伪造“已发送” |

## 6. 正式 PI、CI、Packing List 模板初步定位

本节只定位并冻结，不提前实施 Step 10 的字段重接和模板复用。

### 6.1 PI / 人民币订购合同

- UI 调用源：`quotation.php`
- PDF/打印入口：`crm_quote_pdf.php`
- Excel 入口：`crm_quote_excel.php`
- 数据入口：`quote_api.php?action=get_approved_quote_snapshot`
- 业务规则：外币标题使用 `PROFORMA INVOICE`；人民币标题使用 `订购合同`；未审核报价不得生成正式输出；订单导出继续复用正式报价模板以保持相同版式。
- 模板保护项：公司抬头、Logo、字体、字号、表格字段/顺序/列宽/行高、客户区、银行资料、条款、签章、备注、页眉页脚、分页、打印 CSS、金额/币种/日期格式及文件名。

### 6.2 Commercial Invoice / Packing List

- HTML/打印/PDF 入口：`quote_order_doc.php`
- Excel 入口：`quote_order_excel.php`
- 兼容入口：`quote_order_pdf.php`, `quote_order_export_bridge.php`
- 数据入口：`quote_order_api.php` 的订单、出货与单证动作及 `quote_sales_orders/items`, `quote_shipments/items/cartons`
- 两类单证共享订单/出货快照；现有生成编号、生成记录、箱规、金额、客户型号、制造商编码和出货数量逻辑必须保留。
- 模板保护项同上，并额外保护 PL 的箱数、毛重、净重、尺寸、体积、`PI No.`、多页表头重复和空字段显示规则。

### 6.3 字体、样式与版式核对边界

正式模板的 HTML/CSS、内联样式、字体引用、页眉页脚、签章、银行与备注区均直接位于上述生成文件及其依赖中。Step 10 必须逐项建立字段和版式对照表后原样调用，不能以商务中心页面视觉重新绘制。

`commercial_center_v1/modules/documents/` 下的 `DocumentTemplateRegistry.php`、`DocumentRenderService.php`、`Fixtures/legacy_v1_fixture.php` 和 `Templates/shared/legacy_v1.php` 只用于当前演示预览；页面明确显示“演示数据 · 不可用于正式业务”，Excel 也标记待接入。该模块可以保留作预览/测试参考，但不是正式 PI、CI、PL 模板。

## 7. 必须保留的旧逻辑

- 所有旧报价、客户快照、产品快照、价格、审核、日志、订单、收款、出货、佣金与单证数据的读取能力。
- 旧 URL、旧 API 动作及安全兼容桥；替换实现时只能安全重定向或适配，不得造成 404。
- 报价审核前提交、审核通过锁定、驳回、取消审核、审核快照和正式输出只读已审核快照的规则。
- CRM 客户关联、产品目录、合法配置、BOM 成本、价格等级/策略/阶梯价和佣金提醒。
- 报价转订单后保存来源报价与快照、回写订单编号且不反向覆盖原报价。
- 正式 PI/订购合同、CI、PL 的 PDF、打印、Excel 版式和字段顺序。
- 细粒度用户权限及 `quote_logs` 的前后值、操作者、IP、User-Agent 和请求信息。
- Header、Bank、Template、Terms、Stamp 及单证编号等已使用配置。

## 8. 需要迁移或统一的旧逻辑

- 将 `quote_orders` 中报价头、`items_json`、审核 JSON 和批准快照映射到统一报价、明细、版本、快照、审批和日志服务；旧表继续可读。
- 在 `cc_quote*` 与 Step 3 目标表之间作出唯一模型选择或安全扩展，不重复建第三套互不兼容模型。
- 将三种报价类型统一为 `website_order`、`standard_product`、`custom_product`，并保存来源、编辑模式与锁定规则。
- 把页面内、浏览器本地或静态展示数据迁移为正式服务端保存、重新打开和编辑能力。
- 把旧 API 中混合的数据库访问、业务计算、权限与响应拆到仓储/服务层；保留兼容 API 适配器。
- 将运行时 `ensure_*` / `ALTER` 型结构补丁迁移为可重复、可审计的正式 migration。
- 将硬删除动作改为受权限和日志保护的安全策略；旧数据不可因新系统接入被物理删除。
- 增加规范化报价附件与报价明细附件能力。
- 统一状态、权限、日志、版本和快照，避免只依赖主表 JSON。
- 为“发送客户/邮件附件”建立真实服务契约、发送记录和失败重试，不能只改状态或保留空按钮。
- 商务中心报价列表、审核页、标准品/网站订单/定制品编辑页接入同一数据与服务层。

## 9. 不允许删除或破坏的文件

### 9.1 商务中心冻结文件

- `commercial_center_v1/config/menu.php`
- `commercial_center_v1/index.php` 中既有页面和旧入口映射
- `commercial_center_v1/views/quote_center.php`
- `commercial_center_v1/views/quote_website.php`
- `commercial_center_v1/views/quote_standard.php`
- `commercial_center_v1/views/quote_custom.php`
- `commercial_center_v1/views/quote_approval.php`
- `commercial_center_v1/views/product_library_v2.php`
- `commercial_center_v1/views/price_strategy.php`
- `commercial_center_v1/views/compatibility_rules.php`
- `commercial_center_v1/assets/css/app.css`
- `commercial_center_v1/assets/js/quote_center.js`
- `commercial_center_v1/api/v1/configurator.php`
- `commercial_center_v1/modules/documents/` 全目录（保留但不得冒充正式模板）

### 9.2 原报价中心与正式单证文件

- `quotation.php`
- `quote_api.php`
- `crm_quote_pdf.php`
- `crm_quote_excel.php`
- `quote_order_api.php`
- `quotation_order_api.php`
- `quote_order_doc.php`
- `quote_order_excel.php`
- `quote_order_pdf.php`
- `quote_order_export_bridge.php`
- `quote_order_pi_export.php`
- `quote_order_config.php`

上述列表是最低保护清单；这些文件引用的认证、数据库、字体、Logo、图片和导出依赖同样不得删除。后续如需改动，只允许兼容性接入并必须通过旧数据、旧 URL 和正式版式回归。

## 10. 后续实施风险与约束

1. **双模型风险**：空的 `cc_quote*` 和有正式数据的 `quote_*` 并存。Step 3 必须先写清唯一模型和兼容迁移路径。
2. **历史数据风险**：旧报价使用 JSON 明细和单一审核快照，迁移必须保留原 JSON、哈希/来源和回读对照。
3. **模板误用风险**：商务中心 `legacy_v1` 是演示模板；正式输出只能定位和复用根目录原模板。
4. **破坏性动作风险**：旧 API 含硬删除、恢复备份、清测试数据动作，不能无条件暴露给新页面。
5. **运行时改表风险**：旧 API 存在自动补字段/建结构逻辑，后续必须迁移到幂等 migration，避免请求期间变更生产库。
6. **路由缺口**：定制品编辑路由目前仍显示工作台；报价模板和阶梯价格仍为通用页。
7. **发送缺口**：尚未确认可直接复用的正式报价邮件发送闭环。
8. **附件缺口**：尚无规范化报价/明细附件表。

## 11. Step 2 回归测试清单

- [x] 完整读取十步实施说明的 Step 2 范围。
- [x] 只读检查服务器报价相关表、字段和行数。
- [x] 核对商务中心报价路由、旧入口映射及两个 API 入口。
- [x] 核对旧报价保存、详情、审核、快照、日志、权限、价格、BOM、佣金和转订单动作。
- [x] 定位正式 PI/订购合同 PDF、打印、Excel 入口。
- [x] 定位正式 CI/PL HTML、打印、PDF、Excel 和兼容入口。
- [x] 区分正式模板与商务中心演示模板。
- [x] 未执行 INSERT、UPDATE、DELETE、ALTER、CREATE、DROP 或恢复动作。
- [x] 未修改菜单、Header、页面 UI、路由、API 或数据库。
- [x] 未删除旧文件、旧路由、旧接口或旧数据。

## 12. Step 3 开始前必须采用的审计基线

- 以旧 `quote_*` 正式数据可读、可追溯、可导出为不可破坏基线。
- 明确 `cc_quote*` 是扩展基础还是迁移过渡，禁止再建无兼容关系的第三套模型。
- 先提交可重复安全执行的 migration 和旧数据适配方案，再写保存逻辑。
- 将旧 URL/API 保留为兼容适配层。
- Step 3 只建立统一公共数据模型和服务，不提前重绘或替换 PI/CI/PL 模板。

本文件完成后，Step 2 停止。Step 3 尚未开始。
