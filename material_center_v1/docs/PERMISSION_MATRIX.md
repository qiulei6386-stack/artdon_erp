# Permission Matrix

统一账号由 `LegacyAuthAdapter` 提供，服务端由 `PermissionService` 验证。当前覆盖模块、页面和操作权限；授权表已预留字段范围、数据范围和有效期。

采购价、供应商、内部备注、审核意见、解析置信度和原始 BOM 字段必须在 A8 后续按 hide/mask/read-only 处理，并在查询与写 API 同时验证。

A6 已建立 `mc_field_permission_rules`。非超级管理员的敏感字段默认无权编辑；普通字段默认 edit，显式规则可降为 read、mask 或 none。
