# 产品适配 V2 第 3 阶段模板中心和继承引擎

阶段时间：2026-08-01

## 边界

本阶段继续保持：

- 不修改旧版 `material_center_v1/adaptation/` 业务。
- 不修改旧 BOM。
- 不切换正式菜单。
- V2 只在 `material_center_v1/adaptation_v2/` 独立旁路开发。
- 新表继续使用 `mc_pa2_` 前缀。

## 完成目标

第 3 阶段完成通用、分类、系列和产品模板的基础能力：

- 模板列表；
- 模板编辑器；
- 模板配置组；
- 父模板继承；
- 子模板覆盖；
- 子模板禁用父模板配置组；
- 差异和最终有效配置组预览；
- 模板发布版本；
- 模板引用检查。

## 新增表

| 表名 | 用途 |
| --- | --- |
| `mc_pa2_templates` | 模板主表，支持系统、分类、系列、产品模板 |
| `mc_pa2_template_versions` | 已发布模板版本和快照 |
| `mc_pa2_template_groups` | 模板直接配置组，按 `group_code` 合并 |

## 首批模板

- `system_common`：系统通用模板；
- `track_light_base`：导轨灯模板；
- `recessed_base`：嵌入式模板；
- `magnetic_base`：磁吸式模板。

## 继承算法

继承链从父级到子级执行：

```text
系统通用模板
→ 分类模板
→ 系列模板
→ 产品模板
```

配置组合并键为：

```text
group_code
```

子模板可以：

- `add`：新增或使用配置组；
- `override`：覆盖父模板同名配置组；
- `disable`：禁用父模板同名配置组。

最终预览返回：

- 继承链；
- 有效配置组；
- 每个配置组来源模板；
- 新增 / 覆盖 / 禁用变化记录。

## 发布版本

点击发布模板时，系统会保存当前继承结果快照到：

```text
mc_pa2_template_versions.snapshot_json
```

并把 `mc_pa2_templates.active_version_id` 指向最新版本。

后续第 5-7 阶段产品配置和审批发布会引用该版本快照。

## 页面

本阶段开放：

- `?view=templates`：模板中心；
- `?view=template_editor&template_id=...`：模板编辑器。

第 4 阶段规则编辑器、第 5 阶段单产品配置工作台仍未开发。

## API

新增动作：

- `templates`
- `template_detail`
- `template_save`
- `template_group_save`
- `template_preview`
- `template_publish`
- `template_reference_check`

## 测试

新增：

```text
material_center_v1/tests/adaptation_v2_phase3_contract.php
```

检查范围：

- 第 3 阶段迁移表；
- 首批模板；
- 继承动作；
- 预览与发布函数；
- 页面不再是模板占位；
- API 动作；
- 不修改旧版适配业务。
