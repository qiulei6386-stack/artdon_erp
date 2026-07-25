# F0 框架与 UI 审计

审计范围仅为 `material_center_v1`。现有统一令牌、Button、Form、Checkbox、Radio、Switch、Dropdown、Modal、ConfirmModal、Drawer、Toast、DataTable 和 InteractionManager 可复用，无需建立第二套组件。

发现的缺口：

- 侧栏为深色且菜单为平铺结构，不符合本轮默认白色专业框架及完整分组导航。
- 设置仅有浏览器主题开关，没有数据库作用域、校验、审计及个人覆盖。
- 登录虽复用旧系统，但缺少模块权限上下文和统一服务端授权入口。
- F9 产品规则和匹配模拟未实现。

处理：侧栏改为白色默认；导航改为分组框架；浮层继续由唯一 InteractionManager 控制；新增功能仍复用既有组件。
