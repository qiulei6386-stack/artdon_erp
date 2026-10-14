# 推广中心 V1 · 阶段 1

状态：2026-10-14。已登录 PHP 模块，只读客户中心，并读写本模块自己的客户分组。本阶段不发送邮件，不建 `crm_mail_send_jobs`，不改 `crm_marketing_*`、CRM 内推广界面和现网 `promotion/` 发送引擎。

入口：`/promotion_center_v1/index.php`（须登录且有 `promotion.view`）。接口：`/promotion_center_v1/api.php`。静态原型仍在 `prototype/`，演示身份切换只留在原型里。

## 1. 本阶段假设

- 客户时区经常为空。阶段 1 列表不依赖时区，也不建国家默认时区表。时区留到阶段 2。
- 无负责人客户要出现在范围内，列表和详情显示「无负责人」。无归属弹窗是阶段 2。
- 可见范围取 `get_user_scope('customer')` 与客户中心规则的交集，不把范围放大。`self` / `assigned` 只含主负责人或 `crm_customer_owners` 共同负责人，不含「只是创建人」。`all` 且超管或有 `customer.view_all` 时与客户中心全部可见一致；只有 `view_all`、范围行仍是 `self` 时不放大。`department` / `team` 再按部门名过滤。`country` 在客户中心范围内按 `scope_value` 过滤，值为空时退回本人负责。`customer_group` 按本人负责，不猜测 CRM 分组。
- 公司名搜索只匹配 `customer_code` / `customer_name` / `customer_name_en`。客户中心列表还会搜邮箱、电话、联系人和备注；阶段 1 不跟那部分。通配符不转义，与客户中心一致，搜索词最长 80 字。
- 国家筛选复用 `crm_customer_country_aliases()`。精确别名（如「德国」）匹配客户国家和地址国家，不匹配城市。不调用会写字典表的 `crm_customer_ensure_tables()`。
- `promotion.create_group` / `edit_group` / `move_customer` 仍别名到 `promotion.view`，本阶段不删全局别名。`promotion.delete_group` 没有别名，必须显式授权。
- `config.php` 的 `demo` 默认 `false`。为 `true` 时页面跳到静态原型，接口返回 403「演示开关不能代替登录」。`?demo=1` 在默认关闭时无效。

## 2. 表

迁移：`promotion_center_v1/database/migrations/20261014_001_pm_groups.sql`。仓库根 `.gitignore` 忽略 `*.sql`，该文件需 `git add -f`。应用：

```bash
php promotion_center_v1/migrate.php
```

| 表 | 用途 |
|---|---|
| `pm_groups` | 名称、颜色、`team`/`private`、创建人、软删。未删除名称全局唯一（生成列 `active_name`） |
| `pm_group_members` | `group_id` + `customer_id` 唯一，记录操作人 |
| `pm_audit_logs` | 分组操作审计 |
| `pm_undo` | 10 秒撤销凭证。过期用 SQL `expires_at >= NOW()` |

没有 campaigns、deliveries，也没有邮件队列表。

## 3. 接口

响应与现网 `api_response` 相同：`success`、`message`、`data`、`error_code`。POST 必须带 CSRF（`X-CSRF-Token` 或表单字段）。客户只经 `app/LegacyCustomerAdapter.php`。

| 方法 | action | 说明 |
|---|---|---|
| GET | `session` | 当前用户、中文角色、五项分组权限、customer/promotion 范围 |
| GET | `customers` | `q`、`country`、`owner_user_id`、`activity`（`7d`/`30d`/`inactive_30d`）、`sort`、`dir`、`page`、`page_size` |
| GET | `customer` | `id`。联系人、多邮箱、离职/退订/禁联、全部渠道、可推广群 |
| GET | `customer_filters` | 范围内国家与负责人选项 |
| GET | `groups` / `group` | 分组列表；详情 `id`、`page`、`page_size` |
| POST | `group_create` | `name`、`visibility`（`team`/`private`）、`color` |
| POST | `group_rename` | `id`、`name` |
| POST | `group_delete` | `id`。软删，返回 10 秒 `undo_token` |
| POST | `group_members_add` / `group_members_remove` | `group_id`、`customer_ids`（最多 200）。越权整批拒绝，不返回对方客户名 |
| POST | `group_members_move` / `group_members_copy` | `from_group_id`、`to_group_id`、`customer_ids` |
| POST | `undo` | `token`。只撤销自己的凭证 |
| GET | `history` | 只读。来源标签「CRM 历史」「旧中心历史」 |
| POST | `history` | 405 |

推广状态沿用现网字典：`not_promoted` 未推广、`promoting` 推广中、`no_promotion` 不推广、`paused` 已暂停、`promoted` 已推广。`do_not_contact` 显示禁止联系，不另造黑名单枚举。渠道读 `crm_customer_promotion_channels` 全表，不 `LIMIT 1`。聊天群只取未删除、`use_for_promotion=1` 且 `status=active`。

`private` 分组只有创建人可见可改；`promotion` 范围为 `all` 或超管除外。删除还要创建人、或推广范围 `all`、或超管，并且有 `promotion.delete_group`。本人范围看分组时，`visible_count` 是自己负责的成员，`total_count` 含全部成员。

## 4. 验收记录（2026-10-14）

自动化：`php promotion_center_v1/tests/phase1_isolated.php`，夹具在本机库 `artdon_new_erp`，客户编码前缀 `PM1-`。当时输出「阶段 1 验收全部通过」。`php -l` 覆盖本目录新增 PHP。`node --check promotion_center_v1/assets/app.js` 通过。

| # | 清单 | 结果 |
|---|---|---|
| 1 | 业务员甲只看范围内客户，看不到乙的 | 通过。甲看不到 `PM1-B-01`；共同负责人 `PM1-C-01` 可见；只是创建人的 `PM1-A-07` 不可见 |
| 2 | 甲建分组，加入自己的客户成功，加入乙的被拒绝 | 通过。拒绝码 `FORBIDDEN`，成员数不变，客户行未被改写 |
| 3 | 公司名/国家与客户中心一致，抽样 5 条对照 `crm_api` | 本机通过公司名/编码谓词对照，以及「德国」命中 Germany / 德国 / DE / 地址国家 4 条。正式库抽样见下方 |
| 4 | 多邮箱、离职、退订标记 | 通过。安娜两个邮箱、离职李、邮件已退订。浏览器打开北欧灯饰一致 |
| 5 | 多渠道全部展示 | 通过。邮件、微信、WhatsApp 三条；可推广群只留启用且允许推广的一条 |
| 6 | 未登录或无 `promotion.view` 不能进 | 通过。未登录 API 401「请先登录」，页面 302 到 `login.php`。无权限账号页面为「没有推广中心查看权限（promotion.view）」 |
| 7 | 现网 `promotion.php` 仍可点开 | 本目录未改该文件。本机未登录访问仍 302 到 `login.php?redirect=/promotion.php`。未在正式环境点击发送 |
| 8 | 阶段 1 操作不产生新的 `crm_mail_send_jobs` | 通过。模块源码不写该表、不连 SMTP；夹具前后该表行数为 0（测试也不建这张表） |

浏览器（本机 `php -S 127.0.0.1:8765`，账号 `pm_phase1_a`）：页头为「业务员甲 / 业务员」，没有身份切换。客户列表含北欧灯饰、莱茵照明、柏林轨道、巴黎灯具、大阪电工、地址灯饰、共同客户，不含华南灯饰。搜索「灯」只有地址灯饰、巴黎灯具、北欧灯饰。国家「德国」只剩地址灯饰、柏林轨道、莱茵照明、北欧灯饰。分组「阶段1甲组」成员为北欧灯饰；添加里搜「华南」为空。历史页只读，有「CRM 历史」和「旧中心历史」。`pm_phase1_c` 被拒绝进入。

正式环境核对清单第 3 项：用有客户权限的账号打开客户中心，记下 5 家公司的编码和国家，再在本模块用同一账号搜编码、选同一国家，名单应一致（本模块公司名搜索不含邮箱/电话/备注）。第 7 项：合并进 `main` 并由 Actions 快进后，再点开现网 `promotion.php` 确认仍进入原发送页。

## 5. 未做

九步向导真实提交、试发、审批、入队、执行看板、邮箱补丁、门户入口切换。这些仍只在 `prototype/`。云端没有正式目录部署密钥，本分支不覆盖 `/www/wwwroot/Artdon/artdon_erp/`。三方 HEAD 一致要等本变更进入 `main` 之后的快进。
