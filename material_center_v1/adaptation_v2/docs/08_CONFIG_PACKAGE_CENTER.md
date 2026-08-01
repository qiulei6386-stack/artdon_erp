# 产品适配 V2 第 8 阶段：配置包中心

日期：2026-08-01

## 边界

- 不修改旧版产品适配业务。
- 不修改旧 BOM。
- 不切换正式菜单。
- 只在 `material_center_v1/adaptation_v2/` 继续开发。
- 新增数据表全部使用 `mc_pa2_` 前缀。

## 新增数据表

- `mc_pa2_config_packages`：配置包主表，记录渠道、类型、状态和活动版本。
- `mc_pa2_config_package_versions`：配置包版本表，记录草稿、发布版本、来源产品版本和发布快照。
- `mc_pa2_config_package_groups`：配置包内配置组规则，记录锁定模式、允许范围、默认项、价格、MOQ、库存和交期规则。
- `mc_pa2_config_package_options`：配置包内选项，记录默认、锁定、价格差异、MOQ、库存、交期和规则 JSON。

## 首批配置包

1. `commercial_flexible` / Commercial Flexible
   - 商务中心灵活配置包。
   - 核心组开放选择，颜色和调光提供范围。
2. `singapore_standard` / Singapore Standard
   - 新加坡标准品配置包。
   - 核心芯片和电源使用默认锁定。
   - 只开放指定光学角度和颜色范围。
3. `singapore_dali` / Singapore DALI
   - 新加坡 DALI 配置包。
   - DALI 电源和 DALI 调光固定。
4. `singapore_ready_stock` / Singapore Ready Stock
   - 新加坡现货配置包。
   - 芯片、电源、光学、外观颜色等关键物料全部锁定。
   - 选项携带 MOQ、库存和交期规则。

## 功能

- 配置包列表。
- 配置包详情。
- 配置包基本信息维护。
- 配置包草稿版本生成。
- 配置包配置组新增和编辑。
- 配置组选项新增和编辑。
- 配置包预览。
- 配置包发布。
- 配置包版本快照。
- 发布前验收检查。

## 预览验收点

- Ready Stock：关键物料组必须全部 `locked`。
- Standard：光学和外观颜色必须 `range_limited`。
- DALI：DALI 调光或 DALI 电源规则必须固定。
- Traceable：配置包必须有活动版本号和活动版本 ID。

## API

新增动作：

- `packages`
- `package_detail`
- `package_save`
- `package_version_prepare`
- `package_group_save`
- `package_option_save`
- `package_preview`
- `package_publish`

## 页面

入口：

`/material_center_v1/adaptation_v2/index.php?view=packages`

页面显示：

- 左侧配置包列表。
- 当前包版本、组数、锁定组、范围限定统计。
- 发布前检查结果。
- 组规则详情。
- 选项默认、锁定、MOQ、库存和交期摘要。
- 草稿版本下可编辑组规则和新增选项。

## 未进入本阶段

- 不向商务中心和新加坡网站提供正式下游接口。
- 不把配置包暴露到旧版产品适配。
- 不切换正式菜单。
- 不改旧 BOM。

下游接口、签名、缓存和订单快照属于第 9 阶段。
