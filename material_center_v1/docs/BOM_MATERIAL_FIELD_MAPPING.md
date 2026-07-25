# BOM 物料字段映射

| 旧源字段 | 当前用途 | 访问 |
|---|---|---|
| `id` | 永久源 ID | 只读 |
| `category` | 分类及电源识别 | 只读 |
| `brand` | 品牌 | 只读 |
| `name` | 物料名称及电源识别 | 只读 |
| `model` | 型号 | 只读 |
| `spec` | 规格 | 只读 |
| `unit` | 单位 | 只读 |
| `material_grade` | 材料牌号 | 只读 |
| `image` | 旧源图片路径 | 只读 |
| `is_active` | 有效记录筛选 | 只读 |
| `updated_at` | 更新时间与排序 | 只读 |

## MM4 暂存映射

以上旧字段复制到 `mc_material_import_staging` 的 `raw_*` 字段，并计算 SHA-256 `raw_data_hash`。原始规格永远保留。

候选解析逐字段进入 `mc_material_parse_results`，记录候选值、原文、置信度、规则和人工确认状态。人工建立或关联正式物料后，才生成 `mc_legacy_links`。

未读取的旧表字段不在本映射中；不猜测其含义。

F9 产品规则不扩展旧 BOM 映射，也不向暂存记录追加推测字段；旧 BOM 与旧产品读取保持两条独立只读适配边界。
