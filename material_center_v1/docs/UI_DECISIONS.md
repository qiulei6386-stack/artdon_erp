# UI 决策记录

1. UI 基础设施全部放在 `material_center_v1/ui/`，页面专属样式保留在模块自身 `assets/`。
2. 主题以 `data-theme` 控制并存入 `localStorage`，默认跟随系统。
3. 下拉、弹窗和抽屉统一由 InteractionManager 控制；同一时间仅允许一个浮层打开。
4. 当前系统保持只读，不新增写操作，不修改数据库结构。
5. 首页表格采用前端 20 条分页，后端既有读取边界不变。
6. 组件展厅位于 `ui/docs/component-gallery.php`，与业务页面使用同一套真实组件。
