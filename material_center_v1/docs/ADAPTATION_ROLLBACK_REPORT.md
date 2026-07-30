# 产品适配页面回滚报告

生成时间：2026-07-31 07:43 CST

## 本轮目标

只恢复产品适配页面到最后一个仍可查看、可选择产品、可进入单产品工作台的可用版本；不继续重构业务，不回滚或删除数据库业务数据。

## 当前错误版本备份

- 服务器当前错误版本已完整备份。
- 备份路径：`/www/wwwroot/Artdon/artdon_erp/_codex_backups/adaptation_broken_backup_20260731_074053`
- 备份对象：`/www/wwwroot/Artdon/artdon_erp/material_center_v1/adaptation/`
- 备份说明：保留“基础页面修复中”的错误版本，用于后续比较；本轮不删除数据库数据。

## 错误版本来源与恢复点

- 扫描时本地 HEAD：`97936cfb5cf9e26ec793d2e5995860842bf3defd`
- 错误占位版本相关提交：
  - `5b85c5e fix(material): stabilize adaptation base views`
  - `996180f docs(material): record adaptation stop-loss verification`
- 选定恢复点：`a5eeb37 fix(material): handle products without technical profile`
- 选择原因：
  1. `a5eeb37` 是“基础页面修复中”占位页出现前的最近提交。
  2. 该版本的入口页仍包含状态统计、最近产品、全部产品入口、产品选择入口。
  3. 该版本支持 `view=products` 全部产品列表。
  4. 该版本支持 `view=workspace&product_id=...` 单产品工作台。
  5. 该版本继续使用 `adaptation-v3.js` 和统一 `app.css`，不是空白占位页。

## 当前错误版本修改文件清单

从 `a5eeb37` 到错误版本 HEAD 的适配相关差异：

| 状态 | 文件 |
| --- | --- |
| 新增 | `material_center_v1/adaptation/docs/ADAPTATION_REPAIR_LOG.md` |
| 修改 | `material_center_v1/adaptation/index.php` |
| 修改 | `material_center_v1/api/v1/adaptation.php` |
| 修改 | `material_center_v1/app/Services/AdaptationService.php` |
| 修改 | `material_center_v1/assets/css/app.css` |
| 修改 | `material_center_v1/assets/js/adaptation-v3.js` |

未发现本轮需要恢复整个物料中心；只处理产品适配及其直接相关文件。

## 占位和暂停逻辑扫描

重点搜索词：`基础页面修复中`、`暂未开放`、`现有配置数据不会被修改`、`adaptation repair`、`maintenance`、`feature flag`、`repair mode`、`repairMode`。

命中位置：

1. `material_center_v1/adaptation/index.php`
   - `repairMode => true`
   - “产品适配 · 基础页面修复中”
   - “核心物料、规则、审批与发布暂时暂停，现有配置数据不会被修改。”
   - “配置模板（暂未开放）”
   - “批量工具（暂未开放）”
   - 页面主体被替换为 `mc-page--adaptation-baseline` 占位结构。

2. `material_center_v1/assets/js/adaptation-v3.js`
   - “列设置（暂未开放）”
   - 步骤导航将后续步骤显示为“暂未开放”
   - `renderPausedStep(...)`
   - “当前处于产品适配基础页面止损修复阶段。本步骤暂未开放……”
   - 禁用按钮提示：“当前处于基础页面止损修复阶段，此功能暂未开放。”

3. `material_center_v1/assets/css/app.css`
   - 追加注释：`Product adaptation baseline repair (2026-07-29).`
   - 追加 `.mc-page--adaptation-baseline`、`.mc-adaptation-baseline__...` 等占位页样式。

4. `material_center_v1/app/Services/AdaptationService.php`
   - 保存技术范围时加入“Stop-loss repair”注释。
   - 新增草稿保存分支，停止同步到已建立的 power-rule 表。

5. `material_center_v1/api/v1/adaptation.php`
   - 新增 `save_technical_draft` 分支。
   - `save_technical_profile` 增加确认参数。

## 删除、路由、功能开关、数据库结构检查

- 被删除文件：本地 Git 差异中未发现产品适配相关文件被删除。
- 新增 view 分支：未发现新增独立 `view` 文件；占位逻辑在 `index.php` 内部替换主内容。
- maintenance / feature flag：未发现独立配置文件；发现 `repairMode => true` 作为页面级修复模式标记。
- false 条件隐藏：未发现把正常内容包进明显 `false` 条件；主要问题是入口模板直接被占位模板替换。
- 路由修改：`index.php` 仍接收 `view` 参数，但错误版本弱化为占位页按钮与暂停工作台。
- 数据库结构修改：本次适配相关文件差异未发现 SQL migration 或结构变更文件；本轮不执行数据库改动。

## 恢复范围

计划恢复到 `a5eeb37` 的文件：

- `material_center_v1/adaptation/index.php`
- `material_center_v1/api/v1/adaptation.php`
- `material_center_v1/app/Services/AdaptationService.php`
- `material_center_v1/assets/js/adaptation-v3.js`
- `material_center_v1/assets/css/app.css`

计划移除错误版本新增的占位修复文档：

- `material_center_v1/adaptation/docs/ADAPTATION_REPAIR_LOG.md`

## 测试记录

待恢复、部署后补充。
