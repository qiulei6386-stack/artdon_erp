# Artdon ERP 工作上下文

更新时间：2026-07-24

## 固定环境

- 本地工作目录：
  `/Users/qiulei-office/Library/Mobile Documents/com~apple~CloudDocs/artdon/artdon_guangzhou/artdon_erp`
- GitHub：
  `git@github.com:qiulei6386-stack/artdon_erp.git`
- 主分支：`main`
- 服务器 SSH：`artdon`
- 服务器运行目录：
  - `/www/wwwroot/Artdon/`
  - `/www/wwwroot/Artdon/artdon_erp/`

## 固定工作规则

- 先修改本地，再检查、部署服务器、服务器复检、提交并推送 GitHub。
- 本地、服务器和 GitHub 保持相同版本。
- 每次结束前更新本文件。

## 最近完成

- 派工待办“截止日期”列已收窄：桌面端与手机横屏统一为 70px，手机竖屏维持 48px；已有较宽的个人列宽设置会自动限制到新宽度。
- CRM 名片 OCR 图片保存：压缩到 500KB 内，关联客户，可在客户属性查看和删除。
- BOM 型号格式搜索不再错误命中其他 BOM 的物料明细。
- BOM 顶部抬头和总览工具区已压缩。
- BOM 总览的表格、图标、分类平铺已改为互斥视图并共用分页。
- BOM 图标宽屏布局为 9 列，每页 18 条；其余断点按列数乘两行分页。
- BOM 编辑成本单页面已压缩左侧列表、基础资料、工具栏和提示条，表格获得更多可视空间。

## 最近 Git 状态

- 本次派工截止日期列宽调整待提交；完成后以 `git log -1` 为准。
- 协作规则与上下文文件已纳入 Git 管理；具体最新提交以 `git log -1` 为准。

## 本次检查与部署

- 修改文件：`dispatch_next.php`、`dispatch_next_api.php`、`WORK_CONTEXT.md`。
- 本地检查：`git diff --check` 通过；本机未安装 PHP CLI，未能执行本地 `php -l`。
- 服务器部署：已同步到 `/www/wwwroot/Artdon/` 和 `/www/wwwroot/Artdon/artdon_erp/`。
- 服务器检查：两个目录内的 `dispatch_next.php`、`dispatch_next_api.php` 均通过 `php -l`，对应文件 SHA-256 一致。
- Git：待提交并推送 `origin/main`。

## 待办

- 当前没有用户指定但尚未完成的开发任务。
