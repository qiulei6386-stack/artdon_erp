# F4 权限需求与实现

物料中心通过 `LegacyAuthAdapter` 读取广州 ERP 当前登录用户，转换为 `MaterialCenterUserContext`，不建立账号、密码、Session 或第二套登录页。

`PermissionService` 是模块服务端授权入口。超级管理员拥有模块权限；普通账号优先读取 `mc_permission_grants` 的 user/role allow/deny，未配置时仅兼容映射到旧 `bom.view` / `bom.edit`。页面隐藏不是授权依据，写 API 必须再次执行服务端检查和 CSRF 验证。

权限层级覆盖模块、页面和动作，并预留字段、数据范围及有效期。所有授权表均为 `mc_`，不改旧账号或权限表。
