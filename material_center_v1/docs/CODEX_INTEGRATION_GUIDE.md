# Codex 业务接入说明

1. 保留现有 UI、左侧菜单、页面结构和统一组件，不重新设计页面。
2. 将 `demo/materials.php` 替换为真实 Repository / Service 数据。
3. 列表数据保持相同 ViewModel：code、name、brand、model、spec、status、source、warranty。
4. 搜索、筛选、分页改为后端接口时，保留现有参数和交互。
5. 新建、详情、批量设置、导入、产品适配使用现有 Modal / Drawer。
6. 身份与权限读取广州统一账号，不建立第二套账号。
7. 旧 BOM 只读，正式业务数据写入 mc_* 表。
8. 所有写操作加入服务端权限、事务、日志和错误反馈。
9. 不修改广州旧系统文件和旧表结构。
