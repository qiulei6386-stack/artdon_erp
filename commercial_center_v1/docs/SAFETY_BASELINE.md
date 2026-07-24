# Artdon 商务运营中心 V1 安全基线

更新时间：2026-07-24（Asia/Shanghai）

## 实际环境

- Git 项目根目录：`/www/wwwroot/Artdon/artdon_erp`
- Nginx Web 根目录：`/www/wwwroot/Artdon`
- 本机工作目录：`/Users/qiulei-home/Library/Mobile Documents/com~apple~CloudDocs/artdon/artdon_guangzhou/artdon_erp`
- Git 分支及基线提交：`main` / `74ec52e`
- 开发前本地状态：仅有用户原有未跟踪文件 `CODEX.command`
- 开发前服务器状态：仅有用户原有未跟踪目录 `_migration_backups/`
- PHP：`8.1.2-1ubuntu2.24`
- 数据库服务：MySQL `5.7.43-log`
- 数据库连接：旧系统 `includes/db.php` 读取 `includes/config.php`，PDO MySQL、原生预处理、`utf8mb4`
- 实际数据库：`artdon_new_erp`
- 名称说明：任务最初称 `artdon_erp`，环境核验后用户确认以实际生产库 `artdon_new_erp` 为准
- 统一登录：旧 `includes/bootstrap.php` 启动 Session；`includes/auth.php::current_user()` 通过 `$_SESSION['user_id']` 只读查询 `crm_users`
- 权限：`crm_user_permissions` 显式拒绝/允许，继而读取 `crm_role_permissions`；超级管理员标志来自 `crm_users.is_super_admin`
- 公共页面组件：`includes/layout.php`
- 旧公共资源：`assets/`、`assets/crm/`
- 生产域名：`novlight.com`；同一 Nginx 配置还包含服务器 IP

## 开发前数据库快照

| 项目 | 数量 |
|---|---:|
| 表 | 240 |
| 字段 | 4079 |
| `information_schema.statistics` 索引记录 | 1157 |
| 触发器 | 0 |
| `cc_*` 表 | 0 |

用户于 2026-07-24 明确批准基础建表。迁移前按上表复核基线，随后只创建 4 张 `cc_*` 表。

## 建表后数据库快照

| 项目 | 数量 |
|---|---:|
| 旧表 | 240 |
| 旧表字段 | 4079 |
| 旧表 `information_schema.statistics` 索引记录 | 1157 |
| 旧表触发器 | 0 |
| `cc_*` 表 | 4 |

新增表为 `cc_schema_migrations`、`cc_entity_links`、`cc_integration_logs`、`cc_activity_logs`。均为 InnoDB、`utf8mb4_general_ci`。旧表结构、旧索引、旧触发器和旧业务数据变化均为 0。

## 当前风险

- 这是正式生产库，旧表含客户、邮件、报价、订单和派工数据。
- 旧报价相关代码存在运行时建表/补字段逻辑；新模块禁止调用这些 schema ensure 路径。
- 旧认证函数中登录失败、登录成功及临时权限过期处理可能写旧表；新模块只调用 `current_user()`，不调用登录、注销或临时权限过期函数。
- 服务器存在 `_migration_backups/`，归属旧系统，禁止改动。
- 本机没有 PHP CLI；PHP 检查必须在服务器部署后执行。

## 隔离结果

- 所有新增文件均位于 `commercial_center_v1/`。
- 未修改旧菜单、路由、登录、权限、主题、公共组件或业务文件。
- 新资源仅位于 `commercial_center_v1/assets/`。
- 日志、缓存、PDF、上传及临时文件均有新模块独立目录。
- Legacy 适配器仅暴露参数化 `SELECT`。
- Web 自动迁移开关保持关闭；正式迁移只能由 CLI 执行器显式 `--apply`，且执行器只接受白名单 `CREATE cc_*` 语句。
- M1 运营数据和 M2 产品/物料数据均通过新模块参数化只读 Repository；未登录或无对应旧权限时不读取业务数据。
- 完成后旧系统基线仍为 240 张旧表、4079 个旧字段、1157 条旧索引记录和 0 个旧触发器；另新增 4 张隔离的 `cc_*` 表。
