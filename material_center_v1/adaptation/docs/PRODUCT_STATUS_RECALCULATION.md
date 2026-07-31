# 产品适配状态重算报告

生成时间：2026-07-31 10:00 CST

## 本轮结论

- 本轮没有修改或删除 `mc_*` 业务数据。
- 状态不再直接沿用旧的 `approved_version / group_count` 粗判断。
- 245 个产品已按新规则在服务器只读重算：
  - `unconfigured / 未配置`：231
  - `configuring / 配置中`：14
  - `needs_check / 待检查`：0
  - `pending_approval / 待审批`：0
  - `published / 已发布`：0
  - `conflict / 存在冲突`：0
- 以前被误判为“待审批”的 14 个产品，本轮改为“配置中”；原因是只有草稿配置组，没有确认技术范围，也没有真实待审批记录。

## 新状态规则

1. `conflict / 存在冲突`
   - 存在 `mc_adaptation_conflicts.status='active'`。
2. `pending_approval / 待审批`
   - 存在 `mc_adaptation_approvals.status='pending'` 且关联 `mc_approvals.status='pending'`。
3. `published / 已发布`
   - 存在 `mc_adaptation_published_versions.status='published'`，且当前没有草稿组/草稿选项。
4. `unconfigured / 未配置`
   - 无有效配置组、无选项、无已确认技术范围。
5. `configuring / 配置中`
   - 已有草稿配置，但技术范围未确认，或核心物料未完整。
6. `needs_check / 待检查`
   - 技术范围和核心物料已完整，但尚未进入审批/发布。

## 完成度修正

完成度分段为：

- 技术范围：20
- 核心物料：50
- 扩展可选：10
- 条件规则：10
- 检查：10

本轮修正：没有确认技术范围且核心物料未完成时，扩展可选、条件规则、检查不再提前给分。因此空配置或只有空草稿组的产品不会再显示 20% 或 30% 的虚假完成度。

测试产品：

- `32.05315 BEAMX TRACK LIGHT`
- 配置组：4
- 旧判断：`pending_approval / 待审批`
- 新判断：`configuring / 配置中`
- 列表完成度：0
- 工作台完成度：0
- 分段：`technical=0, core=0, optional=0, rules=0, check=0`

## 被纠正的异常产品

| 产品型号 | 产品名称 | 旧判断 | 新判断 | 配置组 | 选项 | 技术范围确认 | 完成度 |
| --- | --- | --- | --- | ---: | ---: | ---: | ---: |
| 32.02511 | LANKY系列-一体式接头 | pending_approval | configuring | 10 | 1 | 0 | 0 |
| 32.03512 | LANKY系列-一体式接头 | pending_approval | configuring | 7 | 1 | 0 | 0 |
| 32.04515 | EMMA TRACK LIGHT | pending_approval | configuring | 10 | 0 | 0 | 0 |
| 32.04520 | ARTAX TRACK LIGHT | pending_approval | configuring | 9 | 0 | 0 | 0 |
| 32.04530 | ARMI TRACK LIGHT | pending_approval | configuring | 10 | 1 | 0 | 0 |
| 32.05315 | BEAMX TRACK LIGHT | pending_approval | configuring | 4 | 0 | 0 | 0 |
| 51.04523 | MINI PRO RECESSED DOWNLIGHT | pending_approval | configuring | 10 | 1 | 0 | 0 |
| 51.07518 | SLIM RECESSED DOWNLIGHT | pending_approval | configuring | 10 | 1 | 0 | 0 |
| 51.07519 | SLIM RECESSED DOWNLIGHT | pending_approval | configuring | 6 | 0 | 0 | 0 |
| 52.04535 | REDLINE RECESSED DOWNLIGHT | pending_approval | configuring | 10 | 0 | 0 | 0 |
| 52.07517 | SILO RECESSED DOWNLIGHT | pending_approval | configuring | 10 | 0 | 0 | 0 |
| 52.07523 | INTERO RECESSED DOWNLIGHT | pending_approval | configuring | 10 | 1 | 0 | 0 |
| 56.02311 | NOVAL RECESSED DOWNLIGHT | pending_approval | configuring | 8 | 0 | 0 | 0 |
| 56.02312 | NOVAL RECESSED DOWNLIGHT | pending_approval | configuring | 8 | 0 | 0 | 0 |

## 验证记录

- 服务器提交：`5a101fc70b454cffb83bee49503b3d897d63b6ee`
- PHP 语法：
  - `material_center_v1/adaptation/index.php`：通过
  - `material_center_v1/app/Services/AdaptationService.php`：通过
- JS 语法：
  - 本地 `node --check material_center_v1/assets/js/adaptation-v3.js`：通过
- URL：
  - `/material_center_v1/adaptation/index.php`：HTTP 200，约 0.51s
  - `/material_center_v1/adaptation/index.php?view=products`：HTTP 200，约 1.88s
  - `/material_center_v1/adaptation/index.php?view=workspace&product_id=82`：HTTP 200，约 0.90s
  - `/material_center_v1/adaptation/index.php?product_id=82`：HTTP 200，约 1.63s

