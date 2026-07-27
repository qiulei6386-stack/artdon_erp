# Artdon ERP 自动测试与部署

## 固定流程

1. 在本地唯一工作目录修改代码。
2. 运行 `bash tools/ci_php_checks.sh` 和 `bash tools/ci_js_checks.sh`。
3. 只提交本次相关文件并推送 GitHub `main`。
4. GitHub Actions 先执行 PHP 8.0 语法、PHP 契约、JavaScript 语法和 JavaScript 静态测试。
5. 全部通过后，Actions 创建 Git bundle，通过 SSH 上传服务器。
6. 服务器只接受工作区干净、可快进且提交号与 GitHub 完全一致的 bundle。
7. 部署后使用服务器 PHP 8.0 再跑 PHP 检查，并验证公开登录路由。

任何检查失败都会阻止后续部署。自动部署不会使用服务器上的 GitHub 私钥，也不会直接覆盖工作区文件。

## GitHub Actions Secrets

仓库需要配置以下 Secrets：

- `SERVER_HOST`：`119.91.27.19`
- `SERVER_PORT`：`22`
- `SERVER_USER`：`ubuntu`
- `SERVER_SSH_KEY`：已获服务器授权的专用私钥

服务器 ED25519 主机公钥固定记录在 `.github/known_hosts`。服务器更换系统或 SSH 主机密钥后，必须在可信连接中重新核对并更新该文件，不能关闭严格主机校验。

## 测试边界

CI 只运行不依赖生产数据库、不会修改业务数据的语法、静态和契约测试。需要真实数据库的集成测试仍应在明确范围、可清理测试数据和事务保护下单独执行。
