# 物料中心权限联动扫描与修复报告

时间：2026-07-31

## 目标

重新扫描物料中心所有权限逻辑，确认是否与统一权限中心绑定，并修复“已有权限账号不可转正式物料”的问题。

## 结论

问题不是统一权限中心缺少权限项，而是代码里多处仍使用旧的或笼统的权限 key：

- 统一权限中心已有 `material_center.material.formalize`（物料转正式）。
- 服务器现有账号存在只授予正式化相关权限、但没有 `material_center.material.lifecycle` 的情况。
- 旧接口中，部分转正式路径要求 `material_center.material.lifecycle`、`material_center.approve` 或 `material_center.power.confirm`，没有统一识别 `material_center.material.formalize`。
- 因此会出现“权限中心看起来已有权限，但接口仍返回无权限”的现象。

## 服务器只读扫描

服务器 `crm_permissions` 中已登记 31 个 `material_center.*` 权限项，包括：

- `material_center.view`
- `material_center.material.view`
- `material_center.material.create`
- `material_center.material.copy`
- `material_center.material.edit`
- `material_center.material.batch`
- `material_center.material.lifecycle`
- `material_center.material.formalize`
- `material_center.material.reject`
- `material_center.material.disable`
- `material_center.material.archive`
- `material_center.material.delete_draft`
- `material_center.material.merge`
- `material_center.purchase_price.view`
- `material_center.purchase_price.edit`
- `material_center.supplier.manage`
- `material_center.adaptation.manage`
- `material_center.approve`
- `material_center.documents.manage`
- `material_center.permissions.manage`
- `material_center.settings.view`
- `material_center.settings.manage_self`
- `material_center.settings.manage_global`
- `material_center.power.standardize`
- `material_center.power.confirm`
- `material_center.power.rules.view`
- `material_center.power.rules.manage`
- `material_center.power.simulate`

旧 `mc_permission_grants` 中未发现物料中心权限残留；当前物料中心权限来源为统一权限中心的 `crm_permissions`、`crm_user_permissions` 和 `crm_role_permissions`。

现有直接授权账号检查到：

```text
11|sweet|sales|formalize=1|lifecycle=0|approve=1|power_confirm=1|canFormalize=1|approveAllowed=1
```

说明：该账号没有 `material_center.material.lifecycle`，旧通用生命周期接口会被挡住；新逻辑已能通过 `material_center.material.formalize` 放行转正式。

## 修复内容

1. `PermissionService`
   - 新增 `allowsAny(...)`
   - 新增 `requireAny(...)`
   - 新增 `canFormalize(...)`
   - 新增 `materialTransitionPermissions(...)`
   - 新增 `requireMaterialTransition(...)`

2. 生命周期权限统一映射

| 操作 | 新主权限 | 兼容旧权限 |
| --- | --- | --- |
| 转正式 `approve` | `material_center.material.formalize` | `material_center.approve`、`material_center.power.confirm`、`material_center.material.lifecycle` |
| 提交确认 `submit` | `material_center.material.lifecycle` | `material_center.material.edit` |
| 驳回 `reject` | `material_center.material.reject` | `material_center.approve`、`material_center.material.lifecycle` |
| 停用 / 恢复 | `material_center.material.disable` | `material_center.material.lifecycle` |
| 归档 | `material_center.material.archive` | `material_center.material.lifecycle` |
| 删除草稿 | `material_center.material.delete_draft` | `material_center.material.lifecycle` |

3. 已接入统一权限判断的路径
   - `api/v1/material-master.php`
   - `api/v1/materials.php`
   - `api/v1/source-material.php`
   - `api/v1/category-fields.php`
   - `app/Services/SourceMaterialOrganizerService.php`
   - `app/Services/PowerEditorService.php`
   - `assets/js/power-editor.js`

4. 电源待确认记录转正式
   - 待确认电源记录现在直接调用生命周期转正式，不再先强行保存草稿，从而避免被 `material_center.material.edit` 误挡。

## 测试

服务器已通过：

- PHP 语法检查：本次修改的 PHP 文件均通过。
- 本地 Node：`node --check material_center_v1/assets/js/power-editor.js` 通过。
- `php material_center_v1/tests/material_permission_contract.php`
- `php material_center_v1/tests/unified_permission_contract_test.php`
- `php material_center_v1/tests/source_material_organizer_contract.php`
- `php material_center_v1/tests/power_editor_contract_test.php`

## 数据安全

本轮未修改业务数据、未迁移数据库、未删除任何物料、供应商、价格、适配或 BOM 数据。服务器数据库检查均为只读查询。
