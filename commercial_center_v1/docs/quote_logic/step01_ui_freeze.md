# Artdon 商务中心 V1：Step 1 菜单与 UI 冻结基线

> 状态：Step 1 完成基线
>
> 基线日期：2026-07-26
>
> 适用目录：`commercial_center_v1/`
>
> 记录时代码提交：`da70161`
>
> 依据文件：`docs/Artdon_商务中心_报价逻辑十步实施说明(1).md`
> 本步骤只记录现状、冻结边界和回归标准；不修改报价业务逻辑、数据库、旧数据、路由或 UI，不进入 Step 2。

## 1. 当前完整菜单结构

菜单唯一配置源为 `config/menu.php`。下列分组、名称和顺序全部冻结。

| 顺序 | 分组 | 菜单项（按显示顺序） |
|---:|---|---|
| 1 | 工作台 | 运营工作台（`operations_dashboard`）、我的待办（`my_tasks`）、预警中心（`risk_center`）、数据看板（`data_dashboard`） |
| 2 | 产品报价 | 报价单中心（`quote_center`）、报价产品库（`commercial_product_library`）、报价模板（`quote_templates`）、价格策略（`price_strategy`）、阶梯价格（`tier_prices`）、报价审核（`quote_approval`） |
| 3 | 物料与配件 | 物料与配件（`materials`）、替代关系（`material_substitutes`）、配件组合（`accessory_sets`）、适配规则（`compatibility_rules`） |
| 4 | 库存与SKU | 库存SKU（`inventory_sku`）、Ready Stock（`ready_stock`）、库存锁定（`inventory_locks`）、交期管理（`delivery_dates`）、库存预警（`inventory_alerts`） |
| 5 | 订单与交付 | 订单中心（`order_center`）、PI确认（`pi_confirmation`）、包装中心（`packaging_center`）、单证中心（`document_center`）、出货管理（`shipment_center`）、收款管理（`payment_center`） |
| 6 | 项目业务 | 定制项目（`custom_projects`）、样品报价（`sample_quotes`）、工程报价（`engineering_quotes`）、项目报价（`project_quotes`） |
| 7 | 财务与佣金 | 价格与佣金（`price_commission`）、佣金规则（`commission_rules`）、汇率税率（`exchange_tax`）、收款节点（`payment_milestones`）、应收提醒（`receivable_alerts`）、利润分析（`profit_analysis`） |
| 8 | 系统设置 | 权限中心（`permission_center`）、系统设置（`system_settings`）、审批流程（`approval_flows`）、角色权限（`role_permissions`）、字段模板（`field_templates`）、邮件配置（`mail_settings`）、编号规则（`number_rules`）、日志中心（`activity_logs`）、数据备份（`data_backups`） |

冻结规则：

- 不改分组数量、名称、顺序。
- 不改菜单项名称、键名、顺序、图标生成规则。
- 不新增重复报价菜单，不把三种报价拆成三个左侧菜单。
- 不改变菜单双列排版、折叠行为和激活样式。

## 2. 当前产品报价菜单结构

产品报价菜单固定为 6 项、三行双列：

| 行 | 左列 | 右列 |
|---:|---|---|
| 1 | 报价单中心（`quote_center`） | 报价产品库（`commercial_product_library`） |
| 2 | 报价模板（`quote_templates`） | 价格策略（`price_strategy`） |
| 3 | 阶梯价格（`tier_prices`） | 报价审核（`quote_approval`） |

以上为唯一正式产品报价菜单。Step 2–Step 10 只能在现有入口背后接入业务，不得增加重复入口。

## 3. 页面与路由映射

### 3.1 专用页面

| URL / 条件 | 页面文件 | 当前用途 |
|---|---|---|
| `?page=operations_dashboard` | `index.php` 工作台分支 | 运营工作台 |
| `?page=quote_center` | `views/quote_center.php` → `views/quote_custom.php` | 报价单中心完整工作台 |
| `?page=quote_center&quote_mode=website` | `views/quote_website.php` | 网站订单报价 |
| `?page=quote_center&quote_mode=standard` | `views/quote_standard.php` | 标准品报价 |
| `?page=quote_center&quote_mode=standard&quick=1` | `views/quote_standard.php` | 标准品快速创建 |
| `?page=quote_center&quote_mode=custom` | `views/quote_custom.php` | 当前实际仍显示报价单中心工作台 |
| `?page=quote_center&quote_mode=custom&editor=1` | `views/quote_custom.php` | 当前仍显示工作台；待后续审计 |
| `?page=commercial_product_library` | `views/product_library_v2.php` | 报价产品库 |
| `?page=price_strategy` | `views/price_strategy.php` | 价格策略中心 |
| `?page=quote_approval` | `views/quote_approval.php` | 报价审核 |
| `?page=product_config` | 映射后加载 `views/compatibility_rules.php` | 旧产品配置入口兼容 |
| `?page=permission_center` | `index.php` 权限中心分支 | 权限中心 |
| `api/v1/health.php` | 同名文件 | 健康检查 |
| `api/v1/configurator.php` | 同名文件 | 配置器 API，不是正式报价保存 API |

### 3.2 菜单注册、当前由通用正式占位页渲染

- 工作台：`my_tasks`、`risk_center`、`data_dashboard`
- 产品报价：`quote_templates`、`tier_prices`
- 物料与配件：`materials`、`material_substitutes`、`accessory_sets`、直接访问的 `compatibility_rules`
- 库存与SKU：`inventory_sku`、`ready_stock`、`inventory_locks`、`delivery_dates`、`inventory_alerts`
- 订单与交付：`order_center`、`pi_confirmation`、`packaging_center`、`document_center`、`shipment_center`、`payment_center`
- 项目业务：`custom_projects`、`sample_quotes`、`engineering_quotes`、`project_quotes`
- 财务与佣金：`price_commission`、`commission_rules`、`exchange_tax`、`payment_milestones`、`receivable_alerts`、`profit_analysis`
- 系统设置：`system_settings`、`approval_flows`、`role_permissions`、`field_templates`、`mail_settings`、`number_rules`、`activity_logs`、`data_backups`

### 3.3 旧 `view` 兼容入口

`dashboard`、`products`、`materials`、`inventory`、`quotation`、`custom_project`、`publishing`、`orders`、`packaging`、`documents`、`commission`、`integrations`。

## 4. 旧入口与新入口映射

映射源为 `index.php` 的 `$legacyQuoteMap`，必须保留：

| 旧入口 | 当前映射 | 补充参数 |
|---|---|---|
| `?page=standard_quote` | `?page=quote_center&quote_mode=standard` | `quote_mode=standard` |
| `?page=quick_quote` | `?page=quote_center&quote_mode=standard&quick=1` | `quote_mode=standard`、`quick=1` |
| `?page=quote_history` | `?page=quote_center` | 无 |
| `?page=product_config` | `?page=compatibility_rules` | `legacy_notice=product_config` |

正式业务类型固定为：

- `website_order`：网站订单报价单，锁定型
- `standard_product`：标准品报价单，半自由型
- `custom_product`：定制品报价单，高自由型

当前 URL 仍用 `quote_mode=website|standard|custom`；以后可映射业务类型，但不得破坏现有 URL。

## 5. 当前冻结区域

### 外壳和导航

- 左侧品牌区、全部菜单、双列布局、折叠和激活状态。
- 顶部 Header：折叠菜单、面包屑、健康检查、隔离状态、用户和登录/退出。
- 白色正式版主工作区。

### 页面视觉

- 全局字体、字号层级、颜色变量、边框、圆角、间距、按钮和表格密度。
- Header 高度、侧栏宽度/折叠宽度、工作区满宽规则。
- 报价单中心的 6 个看板、筛选、4 个 Tab、列表、分页、快速开始、帮助与支持。
- 报价产品库卡片/列表、自适应分页。
- 价格策略中心表格和编辑抽屉。
- 阶梯价格、报价模板、报价审核当前页面外观。

### PI、CI、Packing List 预冻结记录

本步只记录、不提前执行 Step 10：

- 原报价中心正式 PI、Commercial Invoice、Packing List 的 PDF、打印和 Excel 模板是唯一正式模板。
- 版式、抬头、Logo、字体、字号、表格、字段、列顺序、列宽、行高、页眉页脚、分页、签章、银行资料、条款和输出效果全部冻结。
- Step 10 必须先定位原模板和生成逻辑，再用适配层复用；禁止复制、重绘、重排或新建 V1 模板。

## 6. 后续允许修改的业务区域

在不改变冻结 UI 的前提下，后续可按步骤修改：

- 报价 Repository、Service、金额计算、编号服务。
- 可重复安全执行的迁移和索引。
- 保存、读取、编辑、复制、状态流、审核、版本、快照、日志 API。
- CRM、产品、配置、价格、阶梯价、BOM、佣金联动。
- 网站订单导入/锁定、定制品字段/附件逻辑。
- 预览、导出、发送的数据适配层。
- Step 10 的旧模板数据适配器，只改数据来源，不改模板。
- 测试、文档、安全和性能检查。
- 不产生视觉变化的 `data-*`、表单字段和事件绑定。

任何 UI 变化必须另获用户明确授权。

## 7. 不允许修改的文件或组件

| 文件 / 组件 | 冻结内容 |
|---|---|
| `config/menu.php` | 菜单分组、键名、名称、顺序 |
| `index.php` 的 `.app-shell`、`.sidebar`、`.topbar`、`.crumb`、`.top-actions` | 外壳、Header、菜单、面包屑 |
| `assets/css/app.css` 的侧栏、Header、双列菜单、字体/颜色/表格/按钮规则 | 正式视觉基线 |
| `assets/js/app.js` 的菜单折叠、移动端侧栏、导航分组逻辑 | 外壳交互 |
| `views/quote_custom.php` 的报价中心看板结构 | 正式看板视觉和操作位置 |
| `views/product_library_v2.php` | 报价产品库布局 |
| `views/price_strategy.php` | 价格策略布局 |
| `views/quote_approval.php` | 报价审核布局 |
| 报价模板、阶梯价格当前页面 | 视觉入口和结构 |
| 原系统 PI/CI/PL 模板、打印/PDF/Excel样式 | 永久版式保护 |

业务接入确需触及页面时，必须保持主要 DOM、视觉类名、字段和操作位置，先补测试，不删除旧入口，并执行第 10 节回归。

## 8. 当前报价相关页面清单

| 文件 / 页面 | 当前状态 |
|---|---|
| `views/quote_center.php` | 统一分流入口；保留旧兼容代码 |
| `views/quote_custom.php` | 当前报价中心完整看板；含展示记录 |
| `views/quote_website.php` | 网站订单报价 UI |
| `views/quote_standard.php` | 标准品报价 UI |
| `views/quote_approval.php` | 报价审核 UI |
| `views/product_library_v2.php` | 报价产品库，服务端分页和模糊搜索 |
| `views/price_strategy.php` | 价格策略中心 |
| `quote_templates` | 当前通用占位页 |
| `tier_prices` | 当前通用占位页 |
| `views/compatibility_rules.php` | 适配规则页面 |
| `views/product_config.php` | 文件存在，但当前旧映射不直接使用 |
| `assets/js/quote_center.js` | 报价交互；本机草稿不等同正式保存 |
| `tests/quote_center_regression.php` | 报价菜单和路由回归 |

## 9. 当前路由清单

### 报价正式入口

```text
?page=quote_center
?page=quote_center&quote_mode=website
?page=quote_center&quote_mode=standard
?page=quote_center&quote_mode=standard&quick=1
?page=quote_center&quote_mode=custom
?page=quote_center&quote_mode=custom&editor=1
?page=commercial_product_library
?page=quote_templates
?page=price_strategy
?page=tier_prices
?page=quote_approval
```

### 报价依赖入口

```text
?page=materials
?page=material_substitutes
?page=accessory_sets
?page=compatibility_rules
?page=permission_center
?page=activity_logs
api/v1/configurator.php
api/v1/health.php
```

### 必须兼容的旧入口

```text
?page=standard_quote
?page=quick_quote
?page=quote_history
?page=product_config
?view=quotation
?view=custom_project
?view=documents
?view=orders
?view=commission
```

最低基线：HTTP 200 或安全重定向、无 404、无 PHP Fatal/Parse/Warning、菜单和 Header 正常。

## 10. 回归测试清单

### 静态保护

- [ ] `config/menu.php` 与冻结基线一致，产品报价严格为 6 项且顺序一致。
- [ ] `index.php` 保留 4 项 `$legacyQuoteMap`。
- [ ] Header、侧栏、面包屑和双列菜单 DOM 未改变。
- [ ] `git diff --check`、修改 PHP 的 `php -l`、修改 JS 的 `node --check` 通过。
- [ ] `tests/quote_center_regression.php`、`tests/bootstrap_smoke.php`、`tests/safety_scan.php` 通过。

### 页面可访问性

- [ ] 报价单中心返回 200，包含 6 个看板、完整筛选、快速开始和帮助与支持。
- [ ] 报价产品库、报价模板、价格策略、阶梯价格、报价审核返回 200，无 PHP 错误。
- [ ] 网站订单、标准品、快速标准品、定制品 URL 返回 200。
- [ ] 4 个旧 `page` 入口返回 200 或安全重定向。
- [ ] `api/v1/health.php` 可访问。

### 视觉与数据保护

- [ ] 菜单分组、名称、顺序、双列排版、Header 和白色工作区不变。
- [ ] 报价中心宽屏满宽；报价产品库、价格策略、阶梯价格、模板、审核不回退。
- [ ] 1920px、1366px、平板、手机断点不破版。
- [ ] 不修改数据库业务结构、旧报价数据、旧路由、旧接口、旧页面或其他 ERP 模块。
- [ ] Step 1 不生成报价、审核、导出或单证数据。

## 11. 冻结文件 SHA-256 基线

| 文件 | SHA-256 |
|---|---|
| `config/menu.php` | `7f851f70f4b8c81229be598dafcb3e488c62bae55595fe85b3938884fac4c8ad` |
| `index.php` | `a9b017f0707245974ee94aadec9e3e1c44e477ed22ef7a94d675f0b933c9f656` |
| `assets/css/app.css` | `a15b2367536592fe23e030e9e889e9f588c15252a1546c2f76e985d32d1b004d` |
| `assets/js/app.js` | `07e37a795ec650c8ed39344d5f0cb0c078ed4035ea79f2c00908ff12771fcff4` |
| `assets/js/quote_center.js` | `c8cee491be8b709d6f25fad42c258042105f5e4b401acbfb117b74d5613d18a8` |
| `views/quote_center.php` | `48fdb23cda6078e5bcbede9736bd860fe3f12b03f958ef36ac64c725167cb894` |
| `views/quote_custom.php` | `c720b930807133e9574f159d5049eb94ef71a6b20b97d0b714406c9688ddd76c` |
| `views/quote_website.php` | `a69a34f234a780f2df8f6116a508bfbd4f766e8c8e07bee3dd2f929ec737ef57` |
| `views/quote_standard.php` | `e6d6f2d8940be68464ec55c8349761b833b4bda59d3bae5607f91b624ffee8c1` |
| `views/quote_approval.php` | `6224596bbf33b0a8000250b27ce0b74574a30326d56be99b349897c8d3db24b5` |
| `views/product_library_v2.php` | `de11c93a2f685b110e9a7907b28e1a57d887bf0c27db9ec5b29d3de9adedb2a3` |
| `views/price_strategy.php` | `2ae988c87a086bb16944dfeae8bb4416e0d3dd2f88c47286a5102ff0b782df81` |

后续业务接入确需修改冻结文件时，必须说明原因、通过回归并更新受影响哈希，不能静默改变。

## 12. Step 1 发现的问题（本步不修复）

1. 用户指定的说明文件名不带后缀，工作区实际唯一文件带 `(1)`。
2. `quote_templates`、`tier_prices` 仍为通用占位页。
3. `compatibility_rules` 直接访问走占位页；旧 `product_config` 映射时才加载专用视图。
4. `views/product_config.php` 存在，但当前 `product_config` 被旧映射覆盖。
5. `quote_mode=custom&editor=1` 仍加载报价中心看板，不是独立定制编辑闭环。
6. `views/quote_custom.php` 含展示记录，未读取正式统一报价模型。
7. `assets/js/quote_center.js` 草稿使用 `localStorage`，不满足正式保存、审核和追溯。
8. 当前没有正式统一报价保存 API；配置器 API 不能替代。
9. PI、CI、Packing List 原模板尚未定位；必须留到 Step 10，本步不提前执行。

## 13. Step 2 开始前需要确认

- 用户明确下达“开始 Step 2”；未收到前必须停止。
- 确认以带 `(1)` 后缀的说明文件为依据，或提供无后缀版本。
- Step 2 只审计数据库、路由、旧报价功能和旧单证模板位置，不修改业务逻辑。
- Step 2 重点核对第 12 节问题，不在 Step 1 越界修复。

## 14. Step 1 实际验收结果

执行日期：2026-07-26。

- `git diff --check`：通过。
- 冻结 UI 文件差异检查：通过；本步骤未修改菜单、入口、CSS、JavaScript或报价页面。
- 文档 10 个必需章节检查：通过。
- 12 个冻结文件 SHA-256：与第 11 节记录一致。
- `tests/quote_center_regression.php`：通过。
- `tests/bootstrap_smoke.php`：通过，数据库健康及 10 项适配器检查完成。
- `tests/safety_scan.php`：通过，无旧系统写入、禁止迁移或硬编码密码。
- 六个产品报价菜单入口：全部 HTTP 200，无 Fatal/Parse/Warning。
- 网站订单、标准品、标准品快速创建、定制品入口：全部 HTTP 200，无 Fatal/Parse/Warning。
- `standard_quote`、`quick_quote`、`quote_history`、`product_config`：全部 HTTP 200，无 Fatal/Parse/Warning。
- 数据库业务结构变化：无。
- 报价数据变化：无。
- 路由变化：无。
- UI 变化：无。
- Step 2：未开始。
