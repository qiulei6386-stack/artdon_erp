# 推广中心（独立模块）结构说明

范围：`promotion.php` 与 `promotion/` 目录（`api.php`、`api_live.php`、`cron.php`、`track.php`、`promo_lib.php`、`index.php`、`migrate_v2.php`、`migrate_must10.php`、`app/` 构建产物）。CRM 内的推广模块（`crm_marketing*.php`、`assets/crm/promotion-composer.js`）是另一套实现，仅在第 6 节说明边界。

本文依据 2026-10-13 的源码整理，未连接正式数据库核对实际表结构。标注“推断”的字段来自 SQL 读写语句，仓库内没有对应建表语句。

## 1. 组成与入口

| 部分 | 文件 | 说明 |
|---|---|---|
| 入口 | `promotion.php` | 需登录 + `promotion.view`。存在 `promotion/app/index.html` 时 302 到 SPA，否则到 `promotion/index.php` |
| 新前端 | `promotion/app/` | React/Vite 构建产物，仓库无源码。默认调用真实接口；URL 带 `?demo=1` 时改用内置演示数据并存 `localStorage`（键 `artdon-promo-preview-v1`） |
| 旧前端 | `promotion/index.php` | 单文件 Office 风格界面，11 个标签页，只调用 `api.php` |
| 读接口 | `promotion/api_live.php` | 9 个动作，供 SPA 读取当前用户、看板、客户、邮箱、筛选项及两项设置 |
| 主接口 | `promotion/api.php` | 64 个 `case`（含别名），活动、分组、模板、审批、额度、偏好等全部增删改 |
| 发送引擎 | `promotion/cron.php` | 仅 CLI；设计为每分钟执行；文件锁 `/tmp/artdon_promo_cron.lock` 防并发 |
| 追踪 | `promotion/track.php` | 公开访问，靠 `rid + sig` 校验；打开像素、点击跳转、退订 |
| 公共函数 | `promotion/promo_lib.php` | 设置读写、MIME 组装、DNS 检查、IMAP 回信同步、客户筛选、分组解析、偏好保存 |
| 迁移 | `migrate_v2.php`、`migrate_must10.php` | 建补充表/补字段/写默认设置；基础表的建表语句不在仓库中 |

### 1.1 统一请求与响应

- 请求：`?action=<名称>`；POST 时读取 JSON 请求体并与 `$_POST` 合并（JSON 优先），GET 时读取查询参数。
- 响应：`includes/response.php` 的 `api_response()`，固定为 `{"success":bool,"message":string,"data":object|array,"error_code":string}`；`data` 为空时输出 `{}`。
- `api.php` 内部异常统一返回 `success=false`，`message` 为“系统错误: ”加异常原文；`api_live.php` 附 `error_code=SERVER_ERROR`。
- 未知动作：`api.php` 返回“未知接口: x”；`api_live.php` 返回 `UNKNOWN_ACTION` 并提示“CRUD 请用 promotion/api.php”。

## 2. 角色与权限模型

### 2.1 后端判定

| 判定 | 规则 | 位置 |
|---|---|---|
| 进入模块 | `require_login()` + `require_permission('promotion.view')` | `api.php:10-11`、`index.php:3-4`、`promotion.php` |
| 主管（api.php） | `promo_is_manager()` = `has_permission('promotion.manage')` | `api.php:28-34` |
| 主管（api_live.php） | `is_super_admin() || has_permission('promotion.manage')` | `api_live.php:88-91` |
| 超级管理员 | `has_permission()` 对超管恒为 true，因此两处结果一致 | `includes/permission.php:8-16` |
| 细分动作 | 部分写接口再要求 `promotion.task_create` 或 `promotion.execute` | 见第 3 节 |

`includes/permission.php` 中 `promotion.task_create / execute / manage / analytics / create_project / edit_project / create_group / edit_group / move_customer` 均把 `promotion.view` 列为别名。`has_permission()` 命中别名即放行，所以实际效果是：拥有 `promotion.view` 就能通过上述所有细分权限校验，包括主管判定。下文“需 xxx 权限”描述的是代码意图，不代表当前能区分角色。

`api_live.php` 的 `me` 会返回用户拥有的推广权限键列表。列表里包括 `promotion.export / delete_group / delete_project / stop_promotion / restore_promotion`，但这几个键在本模块没有任何接口使用。

### 2.2 不同角色看到的数据范围

| 数据 | 主管 | 非主管 |
|---|---|---|
| 活动列表 `campaigns` | 全部（最近 100） | 仅 `created_by = 本人` |
| 活动详情 `campaign_detail` | 任意 | 仅本人创建，否则“无权查看” |
| 看板 `dashboard_stats` / `dashboard` | 全部活动统计 | 仅本人创建的活动；退订数始终为全局 |
| 线下任务 `offline_tasks` | `mine=0` 时全部 | 始终只看 `assigned_to = 本人` |
| 线下待办数（`dashboard`） | 全部未完成 | 分配给本人的未完成 |
| 高意向 `high_intent` | 全部 | 本人创建的活动 |
| 邮箱 `mail_accounts` / `settings_mailboxes` | 所有启用邮箱 | 本人邮箱 |
| 客户 `api_live.customers_search` | 全部 | 仅 `owner_user_id = 本人` |
| 客户 `api.customers` | 全部 | **全部**（未按负责人过滤） |
| 筛选项 `api_live.filter_options` | 全部国家/等级/负责人 | 仅本人客户的国家/等级，负责人只有本人 |
| 筛选项 `api.filter_options` | 全部 | 全部 |
| 分组 `groups`、模板、黑名单、动态人群、审批列表、操作日志、额度 | 全部 | 全部（无范围过滤） |
| 名单 `lists` | 本人或 `created_by=0` | 同左 |

“主管专属”操作：`approval_decide`、`quota_set`、修改 `settings_frequency` / `settings_approval`（含 `dns_block_mode`）、`manager_offline_stats`、`campaign_send` 带 `approve=1`、`campaign_cancel` 驳回审批、`test_send` 使用他人邮箱。

### 2.3 SPA 的前端角色（与后端不同）

SPA 不读取权限键，而是用 `me` 返回的角色名做字符串匹配（函数 `Zo`）：

| 角色名包含 | 前端角色 | 显示 |
|---|---|---|
| `admin` 或 `管理` | `admin` | 管理员 |
| `market` 或 `市场` | `marketing` | 市场 |
| 其他 | `sales` | 业务员（有区域时显示“业务员 · X区”） |

- 可见性：`Xi(user, ownerId)` 为 `role !== 'sales' || ownerId === user.id`，即业务员只看自己负责的对象，管理员/市场看全部；仅非业务员显示负责人筛选下拉框。
- 是否需要审批（函数 `na`，纯前端决定调用哪个接口）：
  1. 管理员：不需要，测试信后直接入队；
  2. 市场：始终需要；
  3. 业务员使用了他人或公司邮箱：需要；
  4. 业务员邮件数超过 `salesExempt`（默认 20）：需要；
  5. 其他：不需要。
- 只有前端 `admin` 能点“批准”“退回”，且批准前要求活动已发测试信。
- 前端角色与后端主管判定互不关联：角色名含“管理”但没有 `promotion.manage` 的用户在前端显示为管理员，后端仍按权限（受 2.1 别名影响）处理。

## 3. 接口清单

列说明：方法（实际 GET/POST 均可，此处标注调用方实际使用的方式）、权限（在 `promotion.view` 之上额外检查的条件）、调用方（S = SPA，O = 旧 Office UI，- = 无前端调用）。

### 3.1 `api_live.php`

| 动作 | 权限/范围 | 主要入参 | 返回 | 调用方 |
|---|---|---|---|---|
| `csrf_token` | 登录 | - | `csrf_token` | S |
| `me` | 登录 | - | 用户信息、`is_manager`、`permissions[]`、`csrf_token` | S |
| `dashboard` | 主管看全部 | - | 客户总数/有邮箱数/我的客户数/我的邮箱数、分组数、活动/发送/打开/点击/回复/退信/退订、线下待办、Top8 国家 | S |
| `customers_search` | 非主管仅本人客户 | `page`、`page_size`(10–5000，默认 20)、`with_contacts`(≤200 条时附最多 5 个在职联系人)、筛选字段（4.3） | `total/page/page_size/rows[]`，每行附 `channels`、`promotion_status`、`no_promotion` | S |
| `settings_mailboxes` | 非主管仅本人邮箱 | - | 邮箱配置（不含密码）、渲染后签名、SPF/DKIM/DMARC、`dns_ok`、当日额度 | S |
| `filter_options` | 非主管仅本人客户 | - | `countries/levels/owners/groups/regions` | S |
| `settings_approval` | 改值需主管 | `require_approval`、`require_test_send`、`block_bulk_on_dns_fail`、`dns_block_mode`(spf/strict) | 四项当前值 | 未见调用（SPA 调 `api.php` 同名接口） |
| `settings_frequency` | 改值需主管 | `frequency_days`(0–90)、`default_daily_limit`(10–2000)、`accounts[{mail_account_id,daily_limit}]` | 频率、默认上限、各邮箱上限与今日已发 | 未见调用（同上） |
| `dns_check` | 登录 | `mail_account_id` 或 `domain`、`force` | DNS 检查结果（6 小时缓存） | - |

### 3.2 `api.php`：客户、名单、偏好

| 动作 | 权限 | 主要入参 | 行为/返回 | 调用方 |
|---|---|---|---|---|
| `filter_options` | - | - | 全部国家/等级/负责人/分组/区域 | O |
| `customers` | 列表无；`op=save/preference_save/batch_save` 需 task_create | 列表：筛选字段、`group_id`、`list_id`、`page`、`page_size`(10–5000，默认 2000)；保存：`items[]` 或 `customer_id` / `customer_ids` + `channels/status/reason/no_promotion/blacklist` | 列表按 `id DESC`，每行附 `channels` 与一个合成联系人；保存调用 `promo_save_customer_prefs` | S（`{}` 无参数）、O |
| `lists` | - | - | 本人或公共名单 | O |
| `list_save` | task_create | `name`、`customer_ids[]` | 新建静态名单 | O |
| `list_delete` | task_create | `id` | 仅删除本人创建 | - |
| `preference_get` | - | `customer_id` | 渠道、推广状态、禁联、沟通偏好 | - |
| `preference_save` | task_create | `customer_id`、`channels/channel_key`、`status`、`no_promotion/blacklist`、`reason`、`blacklist_email` | 保存偏好；可同时把邮箱加入黑名单 | - |
| `preference_batch` | task_create | `items[]` 或 `customer_ids[]` + 统一字段 | 批量保存 | - |

### 3.3 `api.php`：分组与动态人群

| 动作 | 权限 | 主要入参 | 行为 | 调用方 |
|---|---|---|---|---|
| `groups` | - | - | 全部分组，按置顶→排序→ID 倒序；每组解析全部成员并返回成员详情、最近一次活动时间 | S、O |
| `group_create` | task_create | `group_name`、`group_color`、`rule/rule_json`、`is_dynamic`、`materialize` | 新建；静态分组带规则且 `materialize` 时立即写入匹配成员 | S、O |
| `group_update` | task_create | `id`、`group_name`、`group_color`、`rule`、`pinned`、`is_dynamic` | 部分更新 | S、O |
| `group_reorder` | task_create | `ids[]` | 按顺序写 `sort_order`（事务） | - |
| `group_delete` | task_create | `id` | 软删除 | S、O |
| `group_members` | - | `id/group_id` | 成员列表（最多 5000，按名称） | S、O |
| `group_add_members` | task_create | `id`、`customer_ids[]` 或 `filter` | 写静态成员，并从排除列表移除 | S、O |
| `group_remove_member(s)` | task_create | `id`、`customer_id(s)` | 删除静态成员；动态分组同时写入排除列表 | S、O |
| `group_move_members` / `group_copy_members` | task_create | `from_group_id`、`to_group_id`、`customer_ids[]` | 复制或移动（事务） | - |
| `segments` | - | - | 动态人群列表 | O |
| `segment_save` | task_create | `id`、`name`、`conditions{country,level,owner,no_follow_days,has_email}` | 新建/修改 | O |
| `segment_delete` | task_create | `id` | 软删除 | O |
| `segment_count` | - | `conditions` | 符合条件客户数 | O |

动态人群（`promo_segments`）只能计数，`campaign_create` 不接受人群 ID；活动目标只能来自 `customer_ids` 与 `group_ids`。

### 3.4 `api.php`：活动全生命周期

| 动作 | 权限 | 主要入参 | 行为 | 调用方 |
|---|---|---|---|---|
| `campaigns` | 非主管仅本人 | - | 最近 100 个活动 + 收件状态统计 + 线下完成数 + `is_manager` | S、O |
| `campaign_create` | task_create | 见 5.1 | 创建草稿并一次性生成收件人与线下任务（事务） | S（保存草稿）、O |
| `campaign_detail` | 主管或创建人 | `id` | 活动 + 最多 500 收件人 + 最多 500 线下任务 + 回信数 | S、O |
| `campaign_submit` | task_create；主管或创建人 | `id/campaign_id`、`body_text`、`note` | 草稿 → 提交审批（见 5.2） | S |
| `approval_request` | task_create；主管或创建人 | `campaign_id`、`note` | 与 `campaign_submit` 等价的旧接口 | - |
| `approval_list` | - | `status`(默认 pending；`all` 为最近 100) | 审批记录 + 活动试发信息 | O |
| `approval_decide` | 主管 | `id`、`decision`、`note`、`start_sending/send_now` | 批准或驳回；批准且带开始标志时草稿直接改为发送中 | O |
| `campaign_send` | execute；主管或创建人 | `id`、`approve`、`body_text`、`body_html`、`note` | 草稿/暂停 → 发送中（门槛见 5.3） | S、O |
| `campaign_pause` | execute；主管或创建人 | `id`、`scope`(all/sender)、`mail_account_id/sender_id` | 整体暂停，或按发件邮箱跳过剩余 | S |
| `campaign_resume` | execute；主管或创建人 | `id` | 暂停 → 发送中 | - |
| `campaign_stop` | execute；主管或创建人 | `id` | 发送中/暂停 → 已停止，剩余跳过 | - |
| `campaign_cancel` | execute | `id`、`reject/decision=rejected/as=reject/reject_approval`、`note` | 带驳回标志时为主管驳回审批；否则主管或创建人取消 | S、O |
| `campaign_stats` | 无范围检查 | `id` | 汇总计数 + 打开/点击 Top10 | O |
| `test_send` | task_create | `to`、`subject`、`body_html`、`mail_account_id`、`signature_html`、`campaign_id` | 同步 SMTP 直发一封（见 5.4） | S、O |
| `preview` | - | `body_html`、`customer_id`、`mail_account_id` | 渲染变量与签名后的 HTML | S、O |
| `schedule_preview` | - | `customer_ids[]`、`mode`、`scheduled_at` | 每个客户的时区、当地时间、北京时间 | O |
| `dedup_preview` | - | `customer_ids[]`、`frequency_days` | 总数/重复或无邮箱/黑名单或退订/频控/可发 | O |
| `spam_check` | - | `subject`、`body_html` | 命中敏感词、分数（每词 15，封顶 100）、等级（≥60 高，≥30 中） | O |

### 3.5 `api.php`：线下执行、回信、效果

| 动作 | 权限 | 主要入参 | 行为 | 调用方 |
|---|---|---|---|---|
| `offline_tasks` | 见 2.2 | `mine`(默认 1) | 最多 300 条，按状态、ID 倒序 | S、O |
| `offline_done` | 主管或被分配人 | `id`、`note`、`screenshot`(文件) 或 `screenshot_base64`/`screenshot_name` | 标记完成，截图存 `promotion/uploads/offline/YYYYMM/`（≤10MB） | S、O |
| `manager_offline_stats` | 主管 | `campaign_id` | 按人统计总数/完成/待办/有截图/完成率 + 最近 50 条完成 | - |
| `mark_reply` / `mail_mark_reply` | execute；主管或活动创建人 | `recipient_id/id` | 手动标记回信 | S（`mail_mark_reply`） |
| `imap_sync` | execute | `mail_account_id` | 立即同步回信；不指定邮箱时主管同步全部、其他人同步本人 | - |
| `dashboard_stats` | 见 2.2 | - | 发送/打开/点击/回复/退信/退订及比率、Top5 国家 | S、O |
| `high_intent` | 见 2.2 | - | 打开 ≥2 次或有点击的收件人，最多 50 | S、O |
| `audit_log` | - | - | 最近 100 条操作日志 | O |

### 3.6 `api.php`：模板、黑名单、邮箱、设置、附件

| 动作 | 权限 | 主要入参 | 行为 | 调用方 |
|---|---|---|---|---|
| `templates` | - | - | 最近 100 个模板，同时以 `templates/contents/list/rows` 四个键返回；`category` 映射为 new/expo/news/other | S、O |
| `template_get` | - | `id` | 单个模板 | O |
| `template_save` | task_create | `id`、`title`、`subject`、`body_html` | 新建/修改（不写 `category`） | S、O |
| `template_delete` | task_create | `id` | 物理删除，无创建人校验 | S、O |
| `blacklist` | - | - | 最近 200 条 | S、O |
| `blacklist_add` | execute | `email`、`reason` | 插入（重复忽略） | O |
| `blacklist_remove` | execute | `id` | 物理删除 | O |
| `mail_accounts` | 主管看全部 | - | 邮箱 + 渲染签名 | S、O |
| `quota_status` | - | - | 全部启用邮箱今日已发/上限/剩余 | O |
| `quota_set` | 主管 | `mail_account_id`、`daily_limit`(10–2000) | 写当日额度与长期设置 | O |
| `settings_frequency` | 改值需主管 | 同 3.1 | 同 3.1 | S |
| `settings_approval` | 改值需主管 | 同 3.1 | 同 3.1 | S |
| `dns_check` | - | 同 3.1 | 同 3.1 | - |
| `upload` | task_create | `file`（multipart，≤20MB） | 存 `promotion/uploads/YYYYMM/`，返回 `url`、`name` | O |

## 4. 数据结构

### 4.1 推广中心自有表

#### `promo_campaigns` 活动（推断 + 迁移补列）

| 字段 | 类型/取值 | 含义与来源 |
|---|---|---|
| `id` | INT | 主键 |
| `title` / `subject` | 文本 | 活动标题 / 邮件主题 |
| `body_html` / `body_plain` | MEDIUMTEXT | 正文 HTML / 纯文本（未传时由 HTML 转换） |
| `send_mode` | `now` / `scheduled` / `smart` | 其他值一律存为 `now` |
| `scheduled_at` | DATETIME | 仅 `scheduled` 时写入 |
| `status` | ENUM `draft, sending, paused, stopped, done, cancelled` | 默认 `draft`（`migrate_must10`） |
| `approval_status` | `none / pending / approved / rejected` | 默认 `none`（`migrate_v2`） |
| `require_approval` | TINYINT | 活动级审批标志 |
| `created_by` / `created_at` | INT / DATETIME | 创建人、时间 |
| `mail_account_id` | INT | 主发件邮箱（兼容字段） |
| `attachments` | JSON 文本 | 附件数组；cron 读取每项的 `url` |
| `batch_size` | INT 10–500，默认 50 | 每次 cron 每活动最多发送数 |
| `frequency_days` | INT 0–90，默认 14 | 创建时频控天数 |
| `track_opens` / `track_clicks` | TINYINT 默认 1 | 无接口可修改 |
| `audience_snapshot` | MEDIUMTEXT JSON | 提交审批/发送时的收件人与线下任务快照 |
| `has_test_send` / `test_send_at` / `test_send_to` | | 试发证据 |
| `paused_at` / `stopped_at` | DATETIME | |
| `group_ids` | VARCHAR(500) | 逗号分隔的来源分组 ID |

#### `promo_recipients` 邮件收件人（推断 + 迁移补列）

| 字段 | 含义 |
|---|---|
| `id`、`campaign_id`、`customer_id`、`customer_name`、`email`、`country` | 基本信息（客户信息在创建时复制） |
| `channel` | 固定 `email` |
| `status` | `pending / sending / sent / failed / bounced / skipped` |
| `scheduled_at` / `sent_at` | 计划时间 / 实际发送时间 |
| `mail_account_id` | 分配的发件邮箱，发送后改为实际使用邮箱 |
| `error_text` | 失败原因；按发件人暂停时写 `paused_sender` |
| `open_count` / `first_opened_at` | 打开次数 / 首次打开 |
| `click_count` / `first_clicked_at` | 点击次数 / 首次点击 |
| `has_reply` / `reply_at` | 回信标记 |
| `unsubscribed` | 退订标记 |

#### `promo_offline_tasks` 线下执行任务（推断 + 迁移补列）

`id, campaign_id, customer_id, customer_name, channel(wechat/whatsapp), assigned_to(客户负责人), assigned_name, content_summary(标题：主题，≤200 字), status(pending/done), done_at, note, screenshot_path, screenshot_name`。

#### 其他自有表

| 表 | 字段 | 来源 |
|---|---|---|
| `promo_groups` | `id, group_name, group_color, created_by, created_at, deleted_at, rule_json, is_dynamic, exclude_json, sort_order, pinned` | 推断；后 5 列由 `promo_ensure_group_cols()` 运行时补加 |
| `promo_group_members` | `group_id, customer_id, created_by, created_at`（`INSERT IGNORE` 推断有唯一键） | 推断 |
| `promo_templates` | `id, title, subject, body_html, created_by, created_at, category(默认 general), is_shared, is_favorite` | 推断 + `migrate_v2` |
| `promo_lists` | `id, name, customer_ids(JSON), created_by, created_at` | 推断 |
| `promo_blacklist` | `id, email, reason, created_at` | 推断 |
| `promo_opens` | `id, recipient_id, campaign_id, opened_at, ip, user_agent` | `migrate_v2` |
| `promo_clicks` | `id, recipient_id, campaign_id, url, clicked_at, ip` | `migrate_v2` |
| `promo_unsubscribes` | `id, email(唯一), campaign_id, recipient_id, reason, unsubscribed_at` | `migrate_v2` |
| `promo_approvals` | `id, campaign_id, requested_by, approver_id, status(pending/approved/rejected), note, requested_at, decided_at` | `migrate_v2` |
| `promo_send_log` | `id, customer_id, email, campaign_id, sent_at` | `migrate_v2`，频控依据 |
| `promo_mail_quotas` | `id, mail_account_id, quota_date, sent_count, daily_limit(默认 200)`，唯一(邮箱, 日期) | `migrate_v2` |
| `promo_audit_log` | `id, user_id, action, target_type, target_id, detail(≤2000), created_at` | `migrate_v2` |
| `promo_segments` | `id, name, conditions(JSON), created_by, created_at, deleted_at` | `migrate_v2` |
| `promo_dns_auth_cache` | `domain(唯一), spf_ok, dkim_ok, dmarc_ok, spf_raw, dkim_raw, dmarc_raw, error_text, checked_at` | `migrate_must10` |
| `promo_mail_account_settings` | `mail_account_id(主键), daily_limit, updated_by, updated_at` | `migrate_must10` |
| `promo_settings` | `setting_key(主键), setting_value, updated_by, updated_at` | `migrate_must10` |

#### `promo_settings` 键

| 键 | 默认 | 作用 |
|---|---|---|
| `require_approval` | `1` | 全局要求审批（见 5.3） |
| `require_test_send` | `1` | 提交审批、批准、发送前必须有试发证据 |
| `frequency_days` | `14` | 设置页展示值；创建活动实际使用请求参数 |
| `default_daily_limit` | `200` | 邮箱未单独设置时的每日上限（钳制 10–2000） |
| `block_bulk_on_dns_fail` | `1` | 发件域名 DNS 不合格时拦截 `campaign_send` |
| `dns_block_mode` | `spf`（无种子，读取默认） | `spf` 只要求 SPF；`strict` 要求 SPF+DKIM+DMARC |

### 4.2 读写的 CRM 共享表

| 表 | 推广中心的用法 |
|---|---|
| `crm_customers` | 读取客户；偏好保存时写 `do_not_contact`、`no_promotion_reason` |
| `crm_contacts` | `customers_search` 读取在职联系人 |
| `crm_users` | 负责人、创建人姓名，签名变量 |
| `crm_user_mail_accounts` | 读取发件配置与加密密码；IMAP 同步写 `sync_status / sync_error / last_sync_time / sync_fail_count` |
| `crm_customer_promotion_channels` | 读取推广渠道；偏好保存时先 DELETE 再 INSERT |
| `crm_customer_promotion_status` | 读取/写入推广状态 |
| `crm_customer_communication_preferences` | 偏好保存时写首个渠道为 `preferred_channel`、`accept_bulk=1` |
| `crm_customer_followups` | 动态人群“N 天未跟进”条件 |

### 4.3 客户筛选条件（`promo_customer_filter_where`）

`code` + `code_mode`(prefix/exact)、`code_from` / `code_to`（字符串比较）、`codes`（列表或空白/逗号分隔）、`created_from` / `created_to`（按日补全时分秒）、`countries`、`regions` / `region`（展开为 `promo_region_map()` 的国家列表；未知区域匹配不到任何客户）、`country`、`level`、`owner`、`kw`/`q`（名称、英文名、编码、邮箱、国家模糊匹配）、`has_email`。

区域表共 9 个：中东、印度/南亚、东南亚、欧洲、非洲、美洲、东亚、俄罗斯及中亚、大洋洲、中国。部分国家同时属于两个区域（如埃及、香港、台湾、澳门）。

### 4.4 审批快照 `audience_snapshot`

```json
{"captured_at":"ISO8601","email_count":0,"offline_count":0,
 "recipients":[{"id":0,"customer_id":0,"customer_name":"","email":"","country":"","channel":"email","status":"pending","mail_account_id":0,"scheduled_at":null}],
 "offline_tasks":[{"id":0,"customer_id":0,"customer_name":"","channel":"wechat","assigned_to":0,"assigned_name":"","status":"pending"}]}
```

快照只做留存，发送时不比对快照，仍按 `promo_recipients` 当前内容发送。

## 5. 业务规则

### 5.1 创建活动（`campaign_create`）

1. 必填：`title`、`subject`、至少一个客户；`send_mode=scheduled` 时必须有 `scheduled_at`。
2. 目标客户 = `customer_ids` ∪ 各 `group_ids` 当前成员（动态规则当场展开，之后分组变化不影响活动）。
3. 逐个客户判断，顺序如下：
   - 客户不存在或已删除 → 忽略，不计数；
   - `crm_customer_promotion_status.status = no_promotion` → 跳过；
   - `crm_customers.do_not_contact = 1` → 跳过；
   - 渠道 = 该客户**最新一条**推广渠道映射：`whatsapp / whatsapp_group` → WhatsApp，`wechat / wechat_group` → 微信，其他一切（包括 `linkedin`、`visit`、`no_promotion`、无记录）→ 邮件；
   - 邮件渠道：邮箱为空或无效、在黑名单或退订表、本活动内邮箱重复 → 跳过；`frequency_days` 天内 `promo_send_log` 有记录 → 频控跳过；
   - 微信/WhatsApp → 生成线下任务，分配给客户负责人。
4. 计划时间：`scheduled` 用指定时间；`smart` 用 `promo_smart_send_time()`；`now` 为空（立即可发）。
5. 发件邮箱分配（`promo_assign_sender_accounts`，只在启用且未删除的所选邮箱内）：
   - `even`（默认；`company` 视为 even）：按顺序轮流；
   - `manual`：按 `sender_allocations[{mail_account_id,count}]` 依次填充，余数回到轮流；分配无效时整体按 even；
   - `owner`：客户负责人在所选邮箱中的邮箱（同一人有多个时优先默认邮箱），找不到用 `fallback_mail_account_id`，再找不到用第一个所选邮箱。
6. `require_approval=1` 时活动 `approval_status=pending`，但不生成 `promo_approvals` 记录（审批列表看不到），需再调用 `campaign_submit`。
7. 返回提示包含邮件数、线下数、跳过数、频控数。

### 5.2 提交审批与审批

- `campaign_submit` / `approval_request`：仅草稿；`require_test_send=1` 时必须 `has_test_send=1`；写快照，新增一条 `promo_approvals(pending)`，活动置 `approval_status=pending, require_approval=1`。重复提交会产生多条待审批记录。
- `approval_decide`（主管）：只能处理 `pending` 记录；批准同样受试发证据门槛约束；更新审批记录与活动 `approval_status`；批准且 `start_sending` 时草稿直接改为发送中，**不经过** `campaign_send` 的 DNS 检查。
- 驳回有两种入口：`approval_decide(decision=rejected)` 或 `campaign_cancel` 带驳回标志；驳回只改 `approval_status=rejected`，活动保持草稿。
- 驳回后没有“重新提交”的专门流程；再次调用 `campaign_submit` 会把状态改回 pending。

### 5.3 活动状态机

```
draft ──campaign_send──▶ sending ──cron 发完──▶ done
  │                       │  ▲
  │                       │  └─campaign_resume / campaign_send── paused
  │                       └──campaign_pause──▶ paused
  │                       └──campaign_stop───▶ stopped（剩余 skipped）
  └──campaign_cancel（draft/sending/paused）──▶ cancelled（pending → skipped）
draft ──approval_decide(批准+start_sending)──▶ sending
```

`campaign_send` 放行条件（按顺序）：
1. 状态为 `draft` 或 `paused`；
2. `approve=1` 时必须是主管，且满足试发门槛，然后自动批准；
3. 可选地用请求中的 `body_text` / `body_html` 覆盖正文（审批后仍可改正文）；
4. 活动 `require_approval=1` **或全局** `require_approval=1`（默认）时，`approval_status` 必须为 `approved`；
5. `require_test_send=1` 时必须有试发证据；
6. `block_bulk_on_dns_fail=1` 时，收件人分配到的每个邮箱都要通过 DNS 检查（模式见 4.1）；
7. 写快照，状态改为 `sending`。

`campaign_resume` 只检查活动级 `require_approval`，不检查全局设置、试发与 DNS。

暂停的两种范围：
- `scope=all`：活动 `paused`，`sending` 收件人退回 `pending`，继续后可接着发；
- `scope=sender`：该邮箱 `pending/sending` 收件人改为 `skipped`（`error_text=paused_sender`），活动保持发送中；**不可恢复**，没有对应的继续接口。

### 5.4 试发（`test_send`）

- 发件邮箱必须属于本人；主管可用任意启用邮箱。
- 变量用演示数据渲染（张三 / 演示公司 / 美国），附加签名和退订页脚。
- 直接同步 SMTP 发送，不入队、不计额度、不写频控日志。
- 只有请求带 `campaign_id` 时才会给该活动写试发证据。

### 5.5 发送引擎（`cron.php`）

每次运行：
1. 取 `status=sending` 且 `approval_status ∈ (none, approved)` 的前 10 个活动（按 ID）；处理每个活动前重读状态，期间被暂停/停止则跳过。
2. 邮箱池 = 活动主邮箱 + 创建人全部启用邮箱 + 待发收件人分配到的邮箱；剔除今日已达上限的邮箱，全部用完则本活动顺延到下次。
3. 取 `pending` 且计划时间已到的收件人，最多 `batch_size` 条；逐条原子领取（`pending → sending`）。
4. 选邮箱：有分配邮箱且有额度 → 用它；分配邮箱额度已满 → 退回 pending 顺延；未分配 → 在可用池中轮流；密码解密失败 → 退回 pending。
5. 组装正文：变量替换 → 追加签名（正文已含签名或签名前 80 字时不重复）→ 链接改写为点击追踪（`track_clicks`）→ 末尾加打开像素（`track_opens`）→ 固定加中英文退订页脚；纯文本优先用 `body_plain`。
6. 成功：收件人 `sent`，额度 +1，写 `promo_send_log`。失败：命中“user unknown / mailbox unavailable / no such user / recipient address rejected / invalid recipient / mailbox not found” 视为硬退信 → `bounced` 并自动加入黑名单；其他 → `failed`（不重试）。
7. 每封间隔 2 秒；活动无 `pending/sending` 收件人时置 `done`。
8. 最后挑一个超过 15 分钟未同步的邮箱做 IMAP 回信同步。

额度只在每个活动开始时判断一次，同一批次内不再复核，因此一批最多可超出上限 `batch_size − 1` 封。

### 5.6 变量与签名

| 位置 | 变量 | 取值 |
|---|---|---|
| 正文（`{x}` 或 `{{x}}`） | `姓名 / name` | 试发/预览为“张三”或空；**正式发送恒为空** |
| | `公司 / 公司名 / company` | 客户名称 |
| | `国家 / country` | 客户国家 |
| 签名 | `{mail_user_name}` `{user_name}` | 发件人名，空则用户真实姓名/用户名 |
| | `{mail_user_mobile}` `{mobile}` | 用户手机号 |
| | `{send_email}` `{email}` | 发件邮箱 |
| | `{mail_user_position}` `{position}` | 用户职位 |
| | `{customer_name}` | 恒为空 |

正文变量值经 HTML 转义；签名变量不转义。签名若以实体形式存储（`&lt;div&gt;`）会先解码一次再渲染。

### 5.7 追踪、退订、回信

- `t=open`：每次打开都写 `promo_opens` 并累加 `open_count`，返回 1×1 GIF。
- `t=click`：记录 `promo_clicks`、累加 `click_count`，然后 302 跳转到原链接。
- `t=unsub`：先显示确认页；用户点确认（`confirm=1`）后写 `promo_unsubscribes`、`promo_blacklist`（原因“客户主动退订”），并标记收件人 `unsubscribed=1`。
- 邮件头带 `List-Unsubscribe` 与 `List-Unsubscribe-Post: One-Click`，但 `track.php` 对 POST 请求不做退订，只显示确认页。
- 试发邮件的退订链接使用 `rid=0`，`track.php` 对 `rid<=0` 直接返回 400。
- 回信：IMAP 读取近 14 天收件箱，最多 300 封；发件人邮箱与近 14 天已发收件人匹配时，最多标记 5 条为已回信。也可手动 `mark_reply`。
- 高意向：打开 ≥2 次或点击 >0。

### 5.8 智能发送时间

- 国家 → 时区：内置约 22 个国家（中英文名），未命中按 `Asia/Shanghai`。
- 规则：当地 9:00–18:00 内立即；18 点后顺延到次日 9:00；早于 9 点为当日 9:00；落在周末则移到下周一 9:00。
- `schedule_preview` 把这个当地时间换算成北京时间展示；但 `campaign_create` 把**当地时间字符串直接写入** `scheduled_at`，cron 用服务器时间（北京时间）比较，实际发送时间与预览不一致。

### 5.9 客户偏好保存（`promo_save_customer_prefs`）

- 允许的渠道键：`email, whatsapp, wechat, wechat_group, whatsapp_group, linkedin, edm, ads, visit, manual_sales, automation, no_promotion`；其他值丢弃。
- 每次保存先删除该客户全部渠道再插入，属于全量覆盖。
- 状态为 `no_promotion` 或渠道含 `no_promotion` → `do_not_contact=1` 并写原因；状态为 `promoting / promoted / not_promoted` → `do_not_contact=0`。其他状态不改禁联字段。
- 首个非 `no_promotion` 渠道写入沟通偏好表的 `preferred_channel`。

### 5.10 动态分组

- 成员 = 静态成员 ∪（动态分组时）规则匹配客户 − 排除列表。
- 手动加入会把客户从排除列表移除；从动态分组移除会写入排除列表，避免被规则重新加入。
- `groups` 接口每次都为每个分组完整展开成员，并为每个成员单独查一次渠道。

### 5.11 DNS 认证检查

- 通过 `dig` 查询 TXT：根域 SPF、9 个常见 DKIM selector（`default, s1, s2, tencent, google, selector1, selector2, mail, dkim`）、`_dmarc` 记录。
- 结果缓存 6 小时；`force=1` 强制刷新。
- 只检查这 9 个 selector，使用其他 selector 的邮箱会被判定为 DKIM 缺失。

### 5.12 导出

本模块没有任何导出接口：`api.php`、`api_live.php`、旧界面和 SPA 构建产物中均无 CSV/Excel/下载逻辑。`promotion.export` 权限只出现在 `me` 返回的权限列表中，没有被使用。

## 6. 与 CRM 推广模块的边界

| 维度 | 推广中心（本文） | CRM 推广 |
|---|---|---|
| 活动/队列表 | `promo_*` | `crm_marketing_*` |
| 发送程序 | `promotion/cron.php` | `marketing_queue_worker.php` |
| 分组 | `promo_groups` | `crm_marketing_groups` |
| 权限键 | `promotion.*` | 同一组 `promotion.*` |
| 共享写入 | `crm_customer_promotion_channels`、`crm_customer_promotion_status`、`crm_customers.do_not_contact`、`crm_customer_communication_preferences`、`crm_user_mail_accounts` 同步状态 | 同左 |
| 退订/黑名单 | `promo_unsubscribes`、`promo_blacklist` | 不读取上述两表 |

两套系统发送队列互不相交，但会覆盖彼此写入的客户推广渠道与状态；推广中心记录的退订不会阻止 CRM 推广发信。

## 7. 前后端不一致清单

以下均为源码比对结论，未在运行环境中验证。

| # | 前端行为 | 后端实际 | 结果 |
|---|---|---|---|
| 1 | SPA“继续发送”调用 `campaign_pause` 并带 `resume=1` | `campaign_pause` 忽略 `resume`，且要求状态为 `sending` | 暂停后无法从 SPA 继续；后端 `campaign_resume` 未被调用 |
| 2 | SPA“继续某发件人”同样带 `resume=1` | 再次把该邮箱剩余收件人设为 `skipped` | 无法恢复 |
| 3 | SPA“重试失败邮件”调用 `campaign_send` 并带 `retry=1, mail_ids` | 忽略这两个参数；状态必须是 draft/paused | 失败邮件无法重新排队 |
| 4 | SPA“退回”调用 `campaign_cancel`，只带 `reason` | 没有驳回标志 → 走取消分支 | 活动被取消而不是退回审批 |
| 5 | SPA“发送测试信”请求体由 `Ho()` 生成 | 请求体里没有 `to` 和 `campaign_id` | 后端返回“试发邮箱无效”，也不会记录试发证据 |
| 6 | SPA“提交”调用 `campaign_submit` 或 `campaign_send`，请求体同样无活动 ID | 两个接口都要求已有活动 ID | 后端返回“参数错误”或“活动不存在” |
| 7 | SPA 定时模式传 `send_mode=fixed` / `local` | 只接受 now/scheduled/smart | 存为 `now`，定时被忽略 |
| 8 | SPA 设置里的 `salesExempt`、`approvalThreshold`、`freqCount`、`holidays`、发送时间窗 | 后端不认这些键 | 不保存，刷新后恢复默认 |
| 9 | SPA `updateUser` 调用 `mail_accounts` 并带 `id` 和字段 | `mail_accounts` 只做列表查询 | 修改不生效 |
| 10 | SPA 线下完成提交 `result`（已触达/已读未回/有意向/无兴趣/号码无效） | `offline_done` 不保存 `result` | 结果丢失，只保留备注和截图 |
| 11 | SPA 高意向跟进调用 `high_intent` 并带 `followup=1` | 只返回列表 | 跟进动作不落库 |
| 12 | SPA 状态映射含 `pending`（待审批）、`rejected`（已退回） | 审批状态在 `approval_status`，`status` 从不出现这两个值；SPA 不读 `approval_status` | 待审批、已驳回的活动在 SPA 中都显示为“草稿” |
| 13 | SPA 客户列表调用 `api.php?action=customers` 不带参数 | 默认只返回最近 2000 个客户，且不按负责人过滤 | 客户多于 2000 时看不到更早客户；业务员能看到全部客户 |
| 14 | 旧界面分组页把 `groups` 返回值当数组读取 | 返回 `{groups,list,rows}` 对象 | 分组列表恒为空 |
| 15 | 旧界面分组编辑的全选传入 `this.checked`（布尔值） | 函数按元素读取 `.checked` | 全选无效 |
| 16 | 旧界面试发不带 `campaign_id` | 默认 `require_test_send=1` | 旧界面创建的活动永远没有试发证据，无法提交审批或发送，除非关闭该设置 |
| 17 | 旧界面创建后在 `need_approval=0` 时直接调 `campaign_send` | 全局 `require_approval=1`（默认）仍要求已批准 | 不勾审批的活动也发不出去 |
| 18 | 旧界面状态徽标只认 draft/sending/done/cancelled | 还有 paused、stopped | 这两个状态直接显示英文原值 |
| 19 | `campaign_create` 中渠道为 `no_promotion` 的判断 | 渠道映射函数永远不返回 `no_promotion` | 该分支是死代码；仅设置 `no_promotion` 渠道而未改状态的客户会被当作邮件客户发送 |

## 8. 维护注意

- SPA 只有构建产物（约 556KB），改动前端必须先找到源码工程；仓库内无法重新构建。
- 基础表没有建表语句；新环境部署前需要从正式库导出这些表的结构。
- `tools/ci_php_checks.sh` 与 `tools/ci_js_checks.sh` 不覆盖本模块任何接口；修改后需要另行补测。
- 安全相关问题（权限范围、跨站请求、追踪跳转、附件路径、迁移脚本访问控制）另有扫描报告，未写入本公开文档。
