# 产品适配 V2 第 2 阶段基础数据模型

阶段时间：2026-08-01
执行边界：不修改旧版 `adaptation/` 业务，不修改旧 BOM，不切换正式菜单。V2 继续使用独立目录 `material_center_v1/adaptation_v2/`。

## 本阶段目标

完成产品分类、产品分类映射、配置组定义和配置组选项定义的基础能力。

本阶段只建立 V2 基础数据中心，不开发模板继承、规则引擎、单产品配置工作台、审批发布、配置包或下游接口。

## 新增 V2 迁移

迁移文件：

```text
material_center_v1/adaptation_v2/database/migrations/20260801_001_phase2_foundation.php
```

迁移工具：

```text
php material_center_v1/adaptation_v2/tools/migrate.php up
```

迁移账本表：

```text
mc_pa2_schema_migrations
```

## 新增基础表

全部使用 `mc_pa2_` 前缀：

| 表名 | 用途 |
| --- | --- |
| `mc_pa2_product_categories` | 产品业务分类，可维护父子分类、启停、排序和默认模板引用 |
| `mc_pa2_product_category_mappings` | 具体产品到 V2 分类和系列的映射 |
| `mc_pa2_group_definitions` | 配置组定义，支持物料选择、属性选择、混合选择、数值、文本和布尔组 |
| `mc_pa2_group_option_definitions` | 属性类配置组选项字典 |

## 首批分类种子

- 导轨灯
- 嵌入式
- 磁吸式
- 明装式
- 线性
- 灯带
- 户外
- 柜体灯
- 电源
- 配件

## 首批配置组种子

- 芯片 / 光源
- 电源 / 驱动
- 外置电源
- INTRACK 电源
- 光学 / 透镜
- 普通导轨接头
- INTRACK 接头
- 磁吸头
- 灯体长度
- 型材
- 灯带
- 扩散罩
- 吊线
- 端盖
- 安装方式
- 外观颜色
- 调光方式
- 特殊要求

## 统一权限

本阶段在统一权限中心写入 `adaptation_v2.*` 权限，不建立第二套账号或授权表。

首批权限：

```text
adaptation_v2.view
adaptation_v2.manage_category
adaptation_v2.manage_group_definition
adaptation_v2.manage_template
adaptation_v2.publish_template
adaptation_v2.configure_product
adaptation_v2.override_product
adaptation_v2.select_material
adaptation_v2.override_conflict
adaptation_v2.manage_rule
adaptation_v2.manage_package
adaptation_v2.approve
adaptation_v2.publish
adaptation_v2.view_price
adaptation_v2.manage_channel
adaptation_v2.view_log
```

服务端 API 使用统一权限检查：

- 查看：`adaptation_v2.view` 或既有 `material_center.view`
- 分类维护：`adaptation_v2.manage_category` 或既有 `material_center.adaptation.manage`
- 配置组维护：`adaptation_v2.manage_group_definition` 或既有 `material_center.adaptation.manage`

## 页面

V2 入口：

```text
material_center_v1/adaptation_v2/index.php
```

本阶段可用页面：

- `?view=home`：阶段状态和基础数据统计
- `?view=categories`：产品分类新增、编辑、父子分类、启停、排序
- `?view=groups`：配置组新增、编辑、启停、排序、属性选项维护
- `?view=products`：产品分类映射
- `?view=logs`：阶段记录入口

模板中心、单产品配置工作台、配置包、审批和发布仍为后续阶段占位，不在本阶段开发。

## API

入口：

```text
material_center_v1/adaptation_v2/api/index.php
```

支持动作：

- `status`
- `categories`
- `category_save`
- `groups`
- `group_save`
- `group_option_save`
- `products`
- `product_map_save`

统一返回格式仍为：

```json
{
  "success": true,
  "message": "操作成功",
  "data": {},
  "errors": [],
  "request_id": "pa2_xxxxxxxx"
}
```

## 日志

分类、配置组、配置组选项和产品分类映射的写入动作会写入现有 `mc_operation_logs`：

```text
module = adaptation_v2
```

日志失败不阻断保存。

## 未做事项

以下内容不属于第 2 阶段：

- 模板主表和版本表
- 模板继承引擎
- 规则编辑器
- 单产品配置工作台
- 适配计算和冲突引擎
- 产品配置版本
- 配置包
- 商务中心接口
- 新加坡网站接口
- 正式菜单切换
