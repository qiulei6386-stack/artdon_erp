# 广州四套正式单据只读审计

审计日期：2026-07-24。全部源文件仅只读，未复制其登录、SQL、自动补表或业务副作用。

| 单据 | 原入口 | 原模板/PDF | Excel | 依赖 | 状态 | 新模块目标 |
|---|---|---|---|---|---|---|
| Quotation | `quotation.php` | `crm_quote_pdf.php` | `crm_quote_excel.php` | 旧报价快照、浏览器打印、自建 XLSX ZIP | 正式使用 | `quotation/legacy_v1` |
| USD PI / CNY订购合同 | `quotation.php#orders` | `quote_order_pi_export.php`、`crm_quote_pdf.php` | `crm_quote_excel.php` | 订单/报价快照、币种分支 | 正式使用 | `order_usd` / `order_cny` |
| Packing List | `quote_order_doc.php?type=pl` | `quote_order_doc.php`、`quote_order_pdf.php` | `quote_order_excel.php` | 出货批次、订单与包装快照 | 正式使用 | `packing_list/legacy_v1` |
| Commercial Invoice | `quote_order_doc.php?type=ci` | `quote_order_doc.php`、`quote_order_pdf.php` | `quote_order_excel.php` | 出货批次、订单、银行快照 | 正式使用 | `commercial_invoice/legacy_v1` |

源文件 SHA-256：

- `crm_quote_pdf.php`: `8065b3e88745796f434d40a2095196e4bc9bd9010e563ae3a50db29076263161`
- `crm_quote_excel.php`: `c17f8239800e2a77de4c30a0772e9b797c7c0f47154f0f9efdab0bca0ab36c73`
- `quote_order_doc.php`: `b7ef024ef555fd2a4914b705077cceea4306ec94ca6009744844db05ed00062f`
- `quote_order_pdf.php`: `bb1a76215e2ea54cde3d6b2a9e24a503a8e96d034973196822996c2deedcb501`
- `quote_order_excel.php`: `4e842a1083a6c5868174c18e230815c1afc227deb533406073ccc5f3cfd7098f`
- `quote_order_pi_export.php`: `eda088143fc728238c27355fdeae2a2b9d3f3eef59fd51c224de5ad4d3e10e50`

旧 `quote_order_doc.php` 包含自动建表/ALTER逻辑，新模块明确未复制。旧 PDF 主要由浏览器打印生成；Excel 使用内部 OpenXML ZIP 逻辑，无外部 PhpSpreadsheet 依赖。
