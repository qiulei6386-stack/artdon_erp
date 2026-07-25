# Material Center V1 安全基线

- 模块根目录：`material_center_v1/`。
- 账号：复用广州 ERP 现有登录态，不建立第二套账号。
- 数据来源：旧 `bom_materials`。
- 数据访问：仅允许 `SELECT`；仓库层对非 `SELECT` 抛出异常。
- 写入开关：`write_enabled = false`。
- 禁止修改旧数据库表结构，禁止向旧 BOM 物料表写数据。
- 禁止修改或覆盖广州旧系统及 `commercial_center_v1`。
- 页面错误不得显示 SQL、凭据、服务器绝对路径。
