# centers：新系统

CRM 拆分重构后的新系统都放在这里。旧系统写完替换后会整体删除，所以**新系统完全独立**：不引用旧代码，只读写数据库里的现有表。方案文档：《CRM 拆分重构方案》（claude.ai 文档）。

## 目录

```
centers/
  config.php           数据库连接等配置（不进 Git；示例见 config.example.php）
  _base/               公共底座（所有中心共用）
    autoload.php       类自动加载
    bootstrap.php      页面和接口入口：加载配置、启动会话、统一出错页
    src/               Config、Db、Session、Csrf、Auth、Permission、Password、Audit、
                       Url、Http、Validator、Form、Layout、Nav、Icon、Flash、ErrorCode
    assets/            base.css（界面样式变量和组件）、base.js（接口调用、提示）
    tools/             init_config.php（服务器上一次性生成 config.php）
    tests/harness.php  契约测试夹具（内存 SQLite，表结构取自 sql/*.sql）
  system/              系统中心：登录、个人账号、账号管理、权限中心（角色、部门、人员权限、申请与授权）；接着做设置中心
  customer/ mail/ promotion/ followup/   以后按顺序新建
```

命名空间与目录对应：`Artdon\Centers\Base\Db` → `_base/src/Db.php`，`Artdon\Centers\System\ProfileService` → `system/src/ProfileService.php`。

## 规则

1. **不引用旧代码。** 不 `require` 旧文件，不调用旧函数。契约测试会检查 `centers/` 里没有引用 `includes/`、没有调用 `current_user()`、`has_permission()` 等旧函数。
2. **只用数据库数据。** 沿用现有表（`crm_users`、`crm_roles`、`crm_departments`、`crm_role_permissions`、`crm_user_permissions`、`crm_permission_grants`、`crm_login_logs`、`crm_audit_logs` 等）。不在运行时建表或改表；确实需要新表或新字段时走迁移文件，只新增、不改旧字段。
3. **登录独立。** 新系统用自己的会话（`ARTDON_CENTERS`），与旧系统互不共用登录。登录日志写入 `crm_login_logs`，内容带 `[新系统]` 标记。
4. **权限只看数据**（见 `Permission`）：超级管理员 → 个人拒绝 → 有效临时授权 → 个人允许 → 角色权限。判断时不修改任何授权数据。
5. **页面统一外框。** `Layout::start()` / `Layout::end()`；表单用 `Form::input()` / `Form::select()`；颜色、字号、圆角只改 `_base/assets/base.css` 顶部的变量。
6. **入口先检查登录和权限。** 页面：`Auth::requirePage()`、`Auth::requirePermissionPage()`；接口：`Auth::requireApi()`、`Auth::requirePermissionApi()`、`Http::requireCsrf()`。
7. **接口格式** `{"success", "message", "data", "error_code"}`，并返回对应 HTTP 状态码（见 `ErrorCode`）。
8. **兼容 PHP 8.0 和 MySQL 5.7**（GitHub Actions 用 PHP 8.0，正式库为 MySQL 5.7）。时间由 PHP 生成（`Db::now()`），不依赖数据库函数。
9. 每个中心的契约测试放在自己的 `tests/` 下，并加入 `tools/ci_php_checks.sh`。

## 部署后的一次性配置

新系统读取 `centers/config.php`。首次部署后在服务器运行一次：

```
cd /www/wwwroot/Artdon/artdon_erp && php centers/_base/tools/init_config.php
```

脚本会复制数据库连接信息、测试能否连上，再写入 `centers/config.php`（权限 640，属主 www）。之后新旧系统的配置各自独立。

## 地址

- 登录：`/artdon_erp/centers/system/login.php`
- 个人账号：`/artdon_erp/centers/system/profile.php`
- 账号管理：`/artdon_erp/centers/system/accounts.php`（需要 users.view）
- 权限中心 · 角色与权限：`/artdon_erp/centers/system/roles.php`；部门：`/artdon_erp/centers/system/departments.php`；人员权限：`/artdon_erp/centers/system/people.php`（需要 users.view）
- 权限申请与临时授权：`/artdon_erp/centers/system/requests.php`（所有人可申请；审批与授权管理按权限显示）
- 设置中心：`/artdon_erp/centers/system/settings.php`（公司信息与 Logo、安全设置、系统信息；查看 `settings.view`，修改 `settings.edit`）
- 日志：`/artdon_erp/centers/system/logs.php`（登录日志、操作日志；`logs.view_all` 看全部，`logs.view_own` 只看自己）
- 通知：`/artdon_erp/centers/system/notifications.php`（顶栏铃铛进入；只显示新系统发出的通知）
- 客户中心：`/artdon_erp/centers/customer/index.php`（我的客户 / 全部客户 / 公海 / 已删除；详情 `view.php?id=`）
- 客户暂存池：`/artdon_erp/centers/customer/leads.php`（查看 `customer.lead_pool_view`，处理 `customer.lead_pool`）
- 客户分组：`/artdon_erp/centers/customer/groups.php`（管理需要 `customer.batch`）

## 底座公共件（给各业务中心用）

- `Notify`：站内通知，写正式库 `sys_notifications`，类型以 `centers.` 开头；发送失败不影响业务。
- `Dictionary`：只读业务字典（`crm_dictionary_items`），如客户等级、来源、生命周期、推广方式。
- `DataScope`：数据范围。权限（`{模块}.view_all` / `{模块}.view_department`）与权限中心设置的数据范围取较大者。
- `UserDirectory`：正常账号列表，按部门分组，用于选择负责人。
- `Pager`：分页条。
