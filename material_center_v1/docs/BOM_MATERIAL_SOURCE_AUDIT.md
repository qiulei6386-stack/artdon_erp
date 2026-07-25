# 旧 BOM 物料源审计

## 已确认事实

- 来源表：`bom_materials`。
- 新中心仓库：`app/Repositories/MaterialReadRepository.php`。
- 查询条件默认排除明确停用记录：`is_active=1 OR is_active IS NULL`。
- 列表上限：单次最多 200 条，首页当前请求 120 条。
- 排序：`updated_at DESC, id DESC`。
- 所有仓库 SQL 必须以 `SELECT` 开头。
- MM4 使用独立 `LegacyBomMaterialAdapter` 读取 `price` 等原始字段；适配器同样拒绝非 SELECT。
- 2026-07-25 试点执行前后 `bom_materials` 行数一致。

## 电源识别边界

电源列表暂按真实旧源字段匹配：

- `category` 包含“电源”或“驱动”；或
- `name` 包含“电源”或“驱动”。

这只是只读发现规则，不代表正式电源领域迁移或分类治理已经完成。
