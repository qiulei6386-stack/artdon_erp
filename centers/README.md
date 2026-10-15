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
  system/              系统中心：登录、个人账号；接着做账号管理、权限中心、设置中心
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
