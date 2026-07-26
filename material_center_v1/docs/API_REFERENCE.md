# API 参考

接口位于 `api/v1/`。JSON 接口统一返回 `{ok,message,data}`；写操作要求广州 ERP 登录态、服务端权限和 CSRF。

| 接口 | 主要能力 |
|---|---|
| `material-master.php` | 新增/编辑、乐观锁、复制、引用检查、生命周期、草稿删除 |
| `materials.php` | 动态字段、批量预览/执行、批量生命周期 |
| `export.php` | 权限及字段权限过滤的物料 CSV |
| `source-sync.php` | 旧 BOM 只读快照、哈希和幂等同步 |
| `imports.php` | 物料 CSV/XLSX 预检、执行、JSON 错误和错误 CSV |
| `suppliers.php` | 供应商、价格/MOQ/交期、价格导入与审批、供应商附件预览/下载 |
| `adaptation.php` | 产品只读同步、配置组/选项/条件/冲突、评估、审批和只读结果 |
| `substitutions.php` | 替代、影响分析、引用迁移和回滚 |
| `documents.php` | 物料文档版本上传、受权限预览/下载和删除 |
| `settings.php` | 个人/角色/全局设置、导入、恢复和版本 |
| `permissions.php` | 角色/用户授权、字段权限、数据范围和审计 |

文件接口限制大小、扩展名和内容签名，并使用随机服务器文件名。CSV 输出会处理 UTF-8 BOM 与公式注入。输入错误返回 4xx；API 不把 PHP HTML 警告作为 JSON 成功响应。
