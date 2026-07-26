# API 参考

接口位于 `api/v1/`，JSON 响应统一为 `{ok,message,data}`。写接口要求广州 ERP 登录态、对应服务端权限和 CSRF。

| 接口 | 主要能力 |
|---|---|
| `material-master.php` | 草稿新增/编辑、复制、引用检查、生命周期、草稿删除 |
| `materials.php` | 字段读取、批量预览/执行、生命周期 |
| `source-sync.php` | 旧 BOM 只读快照同步 |
| `imports.php` | CSV/XLSX 上传、预检、错误、执行 |
| `suppliers.php` | 供应商、联系人、价格、阶梯价、MOQ、交期 |
| `adaptation.php` | 产品同步、配置组、选项、条件、批准、只读结果 |
| `substitutions.php` | 替代、影响分析、引用迁移、回滚 |
| `documents.php` | 文档版本上传、下载和删除 |
| `settings.php` | 个人/角色/全局设置、导入导出、恢复 |
| `permissions.php` | 授权维护和审计 |

输入错误返回 4xx；服务端异常不会输出 HTML/PHP warning 作为 JSON。
