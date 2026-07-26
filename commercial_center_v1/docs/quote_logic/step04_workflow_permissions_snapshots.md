# Artdon 商务中心 V1：Step 4 状态流、权限、日志和版本快照

> 状态：Step 4 已完成并通过服务器验收
>
> 实施日期：2026-07-26

## 1. 统一状态

正式状态机：

`draft` → `pricing` → `pending_approval` → `approved` → `sent` → `customer_confirmed` → `converted`

分支状态：

- `pending_approval` 可驳回为 `rejected`
- `rejected` 可返回 `draft` 或重新提交
- 未结束的报价可按权限作废为 `voided`
- `converted`、`voided` 为终态

非法跨状态转换全部拒绝。驳回和作废必须填写原因。

## 2. 权限

`QuotePermissionService` 接入统一 ERP 权限 `commercial.quote.*`，同时兼容旧报价中心 `quote_user_permissions`：

- 查看、新建、编辑、删除
- 审核、驳回
- 导出、打印、发送、转订单
- 查看成本、查看利润
- 修改价格、修改锁定字段

超级管理员沿用现有权限；普通账号先检查统一 CRM 用户/角色授权，再兼容旧报价权限。测试执行器必须显式标记 `is_test_actor`，不能作为页面或 API 授权来源。

## 3. 日志与审批

迁移 `011_quote_workflow.sql` 新增：

- `cc_quote_approvals`：提交、批准和驳回记录
- `cc_quote_state_history`：状态前后值、原因、操作人和版本
- `cc_quote_audit_logs`：操作人、时间、IP/User-Agent 哈希、报价编号、报价类型、对象、修改前、修改后和原因

创建、编辑、状态变化和已审核修订均写详细审计。密码、Cookie 和原始 IP 不入库。

## 4. 版本和正式快照

- 提交审核、审核通过、发送客户、转订单前复制当前版本并生成快照。
- 已审核报价修改前生成 `pre_revision` 快照，修改结果保存为新的草稿版本。
- 状态变化额外生成 `state_*` 快照。
- 正式快照保存客户、产品、配置、数量、单价、折扣、费用、汇率、条款、金额、成本和毛利。
- 历史版本按版本号倒序读取，旧版本明细不被新编辑覆盖。

快照类型包括：`submitted`、`approved`、`sent`、`pre_conversion`、`pre_revision` 及 `state_*`。

## 5. 服务边界

- `QuoteWorkflowService`：授权创建/编辑、状态转换、审核、修订和历史版本入口
- `QuoteWorkflowRepository`：事务锁、版本复制、状态、审批、历史和审计持久化
- `QuotePermissionService`：统一权限与旧权限兼容
- Step 3 `QuoteService` / `QuoteRepository` 继续负责字段规范化、金额和基础版本保存

页面文件不包含状态机或权限业务逻辑。

## 6. Step 4 边界

本步不接 Step 5 标准品页面闭环，不开发 PDF/Excel/邮件，不执行真实转订单，也不修改旧报价权限和旧报价数据。

完成服务器验收后停止，不进入 Step 5。

## 7. 服务器验收结果

- `011_quote_workflow.sql` 创建三张工作流表，连续执行两次均通过。
- 迁移名称、SHA-256 和 `applied` 状态已记录到 `cc_schema_migrations`。
- 无权限测试账号执行核价状态变更被拒绝。
- 完整状态链、驳回/作废原因约束、审批记录、状态历史和详细审计通过。
- 提交、批准、发送、转订单前版本与正式快照生成通过。
- 已审核报价修改前快照及修改后新草稿版本通过。
- 历史版本和明细读取通过，批准快照冻结字段检查通过。
- 验收结束测试报价为 0，旧 `quote_orders` 保持 35 条。
- Step 3 报价模型、底座、产品目录、报价中心和安全扫描回归全部通过。
