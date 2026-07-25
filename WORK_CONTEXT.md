# Artdon ERP 工作上下文

更新时间：2026-07-25

## 固定环境

- 本地工作目录（两台电脑）：
  - 家用电脑：`/Users/qiulei-home/Library/Mobile Documents/com~apple~CloudDocs/artdon/artdon_guangzhou/artdon_erp`
  - 办公电脑：`/Users/qiulei-office/Library/Mobile Documents/com~apple~CloudDocs/artdon/artdon_guangzhou/artdon_erp`
- 当前电脑：办公电脑（`qiulei-office`）
- GitHub：
  `git@github.com:qiulei6386-stack/artdon_erp.git`
- 主分支：`main`
- 服务器 SSH：`artdon`
- 唯一服务器运行目录：
  `/www/wwwroot/Artdon/artdon_erp/`

## 固定工作规则

- 先修改本地，再检查、部署服务器、服务器复检、提交并推送 GitHub。
- 本地、服务器和 GitHub 保持相同版本。
- 每次结束前更新本文件。

## 最近完成

- 新建 `material_center_v1` 只读基础版：位于广州 ERP 正确子目录，接入统一登录与旧 `bom_materials` 只读总览，包含搜索、分类筛选、统计、详情抽屉和健康检查；当前无写入 SQL、不展示价格与供应商。
- 已完成本地、GitHub、服务器三方同步审计：同步前已跟踪代码均为 `1b9436e`；服务器无已跟踪改动，GitHub 与本地一致。服务器缺少 GitHub SSH 凭据，后续由本地推送 GitHub并通过 Git bundle 快进服务器仓库。
- 已确认两个本地路径分别属于家用电脑 `qiulei-home` 和办公电脑 `qiulei-office`；每次使用当前电脑对应目录。
- 已确认服务器唯一运行目录为 `/www/wwwroot/Artdon/artdon_erp/`，后续不再同步外层 `/www/wwwroot/Artdon/`。
- 派工待办“完成 / 优先级 / 截止日期 / 负责人 / 方式 / 派工来自 / 操作”7 列已按设备视图锁定宽度：不再显示拖拽把手，也不参与窗口自适应缩放；标题、项目等列继续自适应。
- 派工待办表格整排表头文字已统一居中，所有设备视图同步生效，内容行原有对齐方式不变。
- 派工待办“优先级 / 负责人 / 方式”列已按文字压缩：桌面与横屏为 56 / 72 / 52px，手机竖屏为 50 / 72 / 50px；已有较宽个人设置会自动压到新宽度。
- 派工待办“优先级”和“方式”列已隐藏原生下拉箭头，文字区域仍可直接点击选择，新增行同步生效；另已修复方式列旧高优先级背景箭头样式覆盖问题。
- 派工待办“截止日期”列已收窄：桌面端与手机横屏统一为 70px，手机竖屏维持 48px；已有较宽的个人列宽设置会自动限制到新宽度。
- CRM 名片 OCR 图片保存：压缩到 500KB 内，关联客户，可在客户属性查看和删除。
- BOM 型号格式搜索不再错误命中其他 BOM 的物料明细。
- BOM 顶部抬头和总览工具区已压缩。
- BOM 总览的表格、图标、分类平铺已改为互斥视图并共用分页。
- BOM 图标宽屏布局为 9 列，每页 18 条；其余断点按列数乘两行分页。
- BOM 编辑成本单页面已压缩左侧列表、基础资料、工具栏和提示条，表格获得更多可视空间。

## 最近 Git 状态

- 最近业务提交：`565aeab`（新建物料中心只读基础版）。
- 协作规则与上下文文件已纳入 Git 管理；具体最新提交以 `git log -1` 为准。

## 本次检查与部署

- 修改文件：新增 `material_center_v1/` 下 11 个受 Git 管理的基础文件，并更新 `WORK_CONTEXT.md`。
- 本地检查：`git diff --check`、`node --check material_center_v1/assets/js/app.js`、写入 SQL 扫描通过；7 个 PHP 文件通过服务器 PHP CLI 标准输入语法检查。
- 服务器部署：仅部署到 `/www/wwwroot/Artdon/artdon_erp/material_center_v1/`；确认 `/www/wwwroot/Artdon/material_center_v1` 不存在。
- 服务器检查：全部 PHP 文件通过 `php -l`；未登录服务探测返回 `unauthenticated`；核心入口、CSS、JS 与本地 SHA-256 一致。
- Git：业务提交 `565aeab` 已推送到 `origin/main`；最终上下文提交完成后通过 Git bundle 快进服务器仓库。

## 待办

- 物料中心当前为只读基础版；下一阶段待确认分类与属性模板、永久编码、替代料、供应商/价格权限、BOM 引用和写入审计方案。
