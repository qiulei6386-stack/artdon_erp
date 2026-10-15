# centers：拆分后的新中心

CRM 拆分重构的新代码都放在这里。方案文档：《CRM 拆分重构方案》（claude.ai 文档）。

## 目录

```
centers/
  _base/               公共底座（所有中心共用）
    autoload.php       类自动加载，不连库，测试可单独引入
    bootstrap.php      页面和接口入口：加载 autoload + 旧系统 includes/bootstrap.php
    src/               Url、Auth、Http、Validator、Form、Layout、Nav、Icon、Flash、ErrorCode
    assets/            base.css（界面样式变量和组件）、base.js（接口调用、提示）
    tests/harness.php  契约测试夹具（内存 SQLite，表结构取自 sql/*.sql）
  system/              系统中心：登录、个人账号；后续加入账号管理、权限中心、设置中心
  customer/ mail/ promotion/ followup/   以后按顺序新建
```

命名空间与目录对应：`Artdon\Centers\Base\Url` → `_base/src/Url.php`，`Artdon\Centers\System\ProfileService` → `system/src/ProfileService.php`。

## 规则

1. **新建期间不改旧代码。** 旧 CRM 和 `includes/` 一行不改；新代码只调用 `includes/` 里的现有函数（`current_user()`、`has_permission()`、`login_user()`、`logout_user()`、`verify_csrf()`、`audit_log()` 等），所以新旧页面对同一个人、同一个权限的判断完全一致，登录状态通用。
2. **沿用现有表。** 不在运行时建表或改表（契约测试会检查 `centers/` 下的代码里没有 `CREATE/ALTER/DROP TABLE`）。确实需要新表或新字段时，走迁移文件，并只新增、不修改旧字段。
3. **页面统一外框。** 页面用 `Layout::start()` / `Layout::end()`；表单控件用 `Form::input()` / `Form::select()`；颜色、字号、圆角只改 `_base/assets/base.css` 顶部的变量。
4. **入口先检查登录和权限。** 页面：`Auth::requirePage()`、`Auth::requirePermissionPage('xxx.view')`；接口：`Auth::requireApi()`、`Auth::requirePermissionApi()`、`Http::requireCsrf()`。
5. **接口格式**与旧系统 `api_response()` 相同：`{"success", "message", "data", "error_code"}`，同时返回正确的 HTTP 状态码（见 `ErrorCode`）。
6. **兼容 PHP 8.0 和 MySQL 5.7**（GitHub Actions 用 PHP 8.0 检查，正式库为 MySQL 5.7）。
7. 每个中心的契约测试放在自己的 `tests/` 下，并加入 `tools/ci_php_checks.sh`。

## 地址

页面地址从当前脚本路径推算，部署在 `novlight.com/artdon_erp/` 子目录或域名根目录都能用：

- 登录：`/artdon_erp/centers/system/login.php`
- 个人账号：`/artdon_erp/centers/system/profile.php`

旧的 `login.php`、`profile.php`、`users.php`、`permissions.php`、`settings.php` 照常可用。导航里还没有重做的模块指向旧页面，并标“旧版”。
