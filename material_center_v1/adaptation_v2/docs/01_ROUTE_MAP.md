# 产品适配 V2 第 1 阶段路由蓝图

本文件只记录 V2 旁路路由，不切换正式菜单。

## 正式菜单状态

当前物料中心左侧菜单仍指向旧版：

```text
material_center_v1/adaptation/index.php
```

第 1 阶段新增 V2 测试入口：

```text
material_center_v1/adaptation_v2/index.php
```

## V2 页面路由

| 视图 | URL | 当前阶段行为 |
| --- | --- | --- |
| 首页 | `/material_center_v1/adaptation_v2/index.php?view=home` | 显示第 1 阶段旁路状态和路由蓝图 |
| 全部产品 | `/material_center_v1/adaptation_v2/index.php?view=products` | 占位，不开发业务列表 |
| 产品分类中心 | `/material_center_v1/adaptation_v2/index.php?view=categories` | 占位，第 2 阶段开发 |
| 配置组定义中心 | `/material_center_v1/adaptation_v2/index.php?view=groups` | 占位，第 2 阶段开发 |
| 模板中心 | `/material_center_v1/adaptation_v2/index.php?view=templates` | 占位，第 3 阶段开发 |
| 模板编辑器 | `/material_center_v1/adaptation_v2/index.php?view=template_editor&template_id=123` | 占位，第 3 阶段开发 |
| 单产品配置工作台 | `/material_center_v1/adaptation_v2/index.php?view=workspace&product_id=100` | 占位，第 5 阶段开发 |
| 配置包中心 | `/material_center_v1/adaptation_v2/index.php?view=packages&product_id=100` | 占位，第 8 阶段开发 |
| 渠道发布 | `/material_center_v1/adaptation_v2/index.php?view=publish` | 占位，第 9 阶段开发 |
| 审批中心 | `/material_center_v1/adaptation_v2/index.php?view=approvals` | 占位，第 7 阶段开发 |
| 日志与版本 | `/material_center_v1/adaptation_v2/index.php?view=logs` | 显示阶段记录入口 |

## API 路由

第 1 阶段只开放状态接口：

```text
GET /material_center_v1/adaptation_v2/api/index.php?action=status
```

统一响应格式：

```json
{
  "success": true,
  "message": "操作成功",
  "data": {},
  "errors": [],
  "request_id": "pa2_xxxxxxxx"
}
```

除 `status` 外的动作返回 `404` 和 `PHASE_1_BLUEPRINT_ONLY`，防止第 1 阶段误写业务数据。

## Layout 接入

- V2 入口复用 `material_center_v1/components/layout_top.php`。
- V2 入口复用 `material_center_v1/components/layout_bottom.php`。
- `$activeMenu = 'adaptation'` 只用于左侧菜单高亮，不改变菜单链接。
- 本阶段未新增独立登录、账号或权限体系。
