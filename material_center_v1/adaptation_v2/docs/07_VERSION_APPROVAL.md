# 产品适配 V2 第 7 阶段：产品差异、审批和版本

## 范围

本阶段只增强 V2 独立产品配置生命周期：

- 不修改旧版产品适配业务；
- 不修改旧 BOM；
- 不切换正式菜单；
- 不修改模板定义来保存产品级覆盖；
- 仅写入 `mc_pa2_` 前缀表。

## 新增数据表

| 表 | 用途 |
| --- | --- |
| `mc_pa2_product_version_events` | 记录草稿创建、提交、审批、驳回、发布、回滚事件 |
| `mc_pa2_product_version_snapshots` | 记录提交、审批、发布、回滚时的完整版本快照 |
| `mc_pa2_product_version_diffs` | 保存发布版本与草稿/候选版本之间的差异 |

## 生命周期

```text
draft / rejected
  -> submitted
  -> approved
  -> published
```

补充动作：

- `rejected`：审批人驳回后回到可编辑状态；
- `rollback`：把当前发布指针切回某个旧发布版本；
- `edit_after_publish`：发布后再次点击生成草稿，会从当前发布版本克隆出新草稿，不修改旧版本。

## 旧发布版本保护

发布后：

- `active_published_version_id` 指向当前发布版本；
- `active_draft_version_id` 清空；
- 历史版本行、快照和事件记录保留；
- 再次编辑时克隆新草稿，旧 V1 不被覆盖。

## API

新增：

- `product_versions`
- `product_version_diff`
- `product_version_submit`
- `product_version_approve`
- `product_version_reject`
- `product_version_publish`
- `product_version_rollback`

## 验收点

1. 发布 V1 后，再编辑会生成下一版草稿。
2. 产品级覆盖保存在产品版本表，不修改模板。
3. 提交、审批、驳回、发布、回滚均写事件日志。
4. 提交、审批、发布、回滚均可生成快照。
5. 发布版本可追溯，历史发布版本不被删除。
6. 旧 BOM 和旧产品适配业务不发生写入。
