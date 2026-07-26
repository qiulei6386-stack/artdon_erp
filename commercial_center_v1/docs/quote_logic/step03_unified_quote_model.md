# Artdon 商务中心 V1：Step 3 统一报价公共数据模型

> 状态：Step 3 已完成并通过服务器验收
>
> 实施日期：2026-07-26
>
> 本步骤只建立统一报价数据模型、仓储和公共服务，不接入 Step 4 状态流、审批权限和正式版本规则。

## 1. 唯一新报价主链

不建立第三套报价主表。沿用既有空表：

- `cc_quotes`：报价主记录
- `cc_quote_versions`：每次草稿保存的版本
- `cc_quote_items`：版本明细
- `cc_quote_item_snapshots`：明细的产品、配置、价格和成本快照

旧 `quote_orders` 及其他 `quote_*` 表保持只读兼容，不覆盖、不迁移、不删除。

## 2. Step 3 扩展表

迁移：`database/migrations/010_unified_quote_model.sql`

| 表 | 用途 |
|---|---|
| `cc_quote_details` | 报价来源、编辑模式、联系人、国家、汇率、有效期、负责人、条款、项目、费用、佣金、毛利、备注及转订单映射 |
| `cc_quote_item_details` | SKU、型号、名称、图片、单位、交期、备注、锁定、来源行、自定义字段与参考产品 |
| `cc_quote_files` | 报价级图片和文档元数据 |
| `cc_quote_item_files` | 明细级图片和文档元数据 |
| `cc_quote_snapshots` | 报价版本级完整 JSON 快照和 SHA-256 |
| `cc_quote_legacy_links` | 新旧报价显式映射，不改旧数据 |

附件表本步只建立可追溯元数据模型，不开放上传接口；实际安全上传、访问权限和生命周期在后续步骤接入。

## 3. 三种报价统一规则

| 报价类型 | `quote_type` | `edit_mode` | Step 3 数据约束 |
|---|---|---|---|
| 网站订单 | `website_order` | `locked` | 必须保存来源订单号、订单快照和每条来源行快照；明细自动锁定 |
| 标准品 | `standard_product` | `semi_free` | 保存产品引用、配置快照、价格、成本、数量和备注 |
| 定制品 | `custom_product` | `free` | 支持自定义名称、规格、自定义字段、参考产品和图片元数据 |

三种类型共用同一个 `QuoteService`、`QuoteRepository`、编号服务和金额计算器。

## 4. 保存、重新打开和编辑

- 新报价生成唯一 `CQ-日期时间-随机码` 编号。
- 所有保存均使用数据库事务。
- 新建从版本 1 开始。
- 编辑仅允许当前 `draft` 报价；每次保存追加新版本和新明细，不覆盖旧版本。
- 报价头更新 `current_version`，重新打开只读取当前版本。
- 每条明细保存不可变产品/配置/价格/成本快照。
- 每次保存生成报价级草稿快照及 SHA-256。
- 每次保存写入 `cc_quotation_logs`。
- 金额统一计算：明细金额、整单折扣、运费、税费、其他费用、佣金、总成本、毛利和毛利率。

Step 3 的版本只用于保证保存和编辑不覆盖历史数据；提交审核、批准、发送、转订单等正式快照时机由 Step 4 实施。

## 5. 旧报价兼容读取

`QuoteRepository::findLegacy()` 只读 `quote_orders`：

- 保留旧报价编号、状态、币种、客户快照、明细 JSON、金额和批准快照。
- 旧 `items_json` 不存在时兼容读取 `product_json`。
- 返回结果明确标记 `storage=legacy`，避免误写旧表。
- 新保存服务只写 `cc_*` 表。

## 6. 服务层文件

- `app/Services/QuoteService.php`：类型约束、字段规范化和统一保存/打开入口
- `app/Services/QuoteAmountCalculator.php`：金额、成本、毛利计算
- `app/Services/QuoteNumberService.php`：唯一报价编号
- `app/Repositories/QuoteRepository.php`：事务写入、版本、快照、日志和新旧读取

业务逻辑未写入页面文件。

## 7. 迁移安全

- 迁移只允许创建白名单中的 `cc_*` 表。
- 不执行 `ALTER`、`DROP`、`TRUNCATE`、`RENAME`。
- 不修改任何旧表或旧数据。
- `database/apply.php` 验证目标数据库必须为 `artdon_new_erp`。
- 迁移文件 SHA-256 记录到 `cc_schema_migrations`。
- 同名迁移校验和不一致时拒绝执行。
- 同一迁移可重复执行；表使用 `CREATE TABLE IF NOT EXISTS`，迁移记录使用唯一键更新执行状态。

## 8. 验收

`tests/quote_model_smoke.php --write-test` 验证：

1. 三种报价分别保存。
2. 三种报价分别重新打开。
3. 标准品报价编辑后生成并读取版本 2。
4. 网站订单来源头和来源行快照保存且锁定。
5. 旧 `quote_orders` 报价仍可读取。
6. 测试结束清理所有 `is_test=1` 测试报价及关联 `cc_*` 数据。

服务器验收结果：

- 生产库历史上未执行 `007_commercial_foundation.sql`，先按原仓库白名单迁移补齐 `cc_quotation_logs` 等五张既有 `cc_*` 基础表；重复执行通过。
- `010_unified_quote_model.sql` 创建六张报价扩展表；重复执行通过。
- 两项迁移名称、SHA-256 和 `applied` 状态已写入 `cc_schema_migrations`。
- 三种报价均完成保存和重新打开；标准品第二次保存生成版本 2。
- 旧 `quote_orders` 兼容读取通过，旧报价审计前后均为 35 条。
- 验收结束 `cc_quotes WHERE is_test=1` 为 0，测试数据已清理。
- 底座、目录、报价中心和安全扫描全部通过。

## 9. Step 3 边界

本步未实施：

- Step 4 的完整状态机、审批权限、驳回与正式快照规则
- 页面保存 API 和页面按钮接线
- 正式附件上传
- PDF、Excel、打印或邮件发送
- 转订单
- PI、CI、Packing List 模板接入

完成验收后停止，不进入 Step 4。
