# `legacy_v1` 单据格式对比

- 已建立 Quotation、USD PI、人民币订购合同、Packing List、Commercial Invoice 五个入口（四类单据）。
- 共用同一 ViewModel、模板注册中心和共享 A4 模板；Fixture 明确标记演示数据。
- Packing List 右侧使用 `PI No.`；Packing List 与 CI 均保留 `Customer Model`、`Manufacturer Code`。
- 空字段输出为空，不显示编辑占位文字；打印/浏览器保存 PDF 可真实使用。
- Excel 独立输出服务尚未完成，页面明确标注，不提供假下载按钮。
- 当前属于结构和字段高还原基础；Logo、长文本分页、多页图片、Excel/PDF像素级对比仍待拥有正式脱敏快照后复验。
