# Step 10 原报价中心正式单据模板定位

## 模板及入口

| 单据 | 正式模板/入口 | Excel | 新商务中心调用 |
|---|---|---|---|
| PI / 人民币订购合同 | `quote_order_pi_export.php`、`crm_quote_pdf.php` | `quote_order_pi_export.php?type=excel`、`crm_quote_excel.php` | `legacy_document_bridge.php` 仅映射快照并 POST 原入口 |
| Commercial Invoice | `quote_order_doc.php?type=ci`，PDF兼容入口 `quote_order_pdf.php` | `quote_order_excel.php` | 直接传正式 `shipment_id` 调原入口 |
| Packing List | `quote_order_doc.php?type=pl`，PDF兼容入口 `quote_order_pdf.php` | `quote_order_excel.php` | 直接传正式 `shipment_id` 调原入口 |

依赖为统一登录、正式订单/出货快照、旧单据设置、`assets/fonts/ARSMaqLigTr.otf`、原 Logo/公司抬头/银行/条款设置及旧 OpenXML ZIP 生成函数。

## 冻结校验

- `crm_quote_pdf.php`: `8065b3e88745796f434d40a2095196e4bc9bd9010e563ae3a50db29076263161`
- `crm_quote_excel.php`: `c17f8239800e2a77de4c30a0772e9b797c7c0f47154f0f9efdab0bca0ab36c73`
- `quote_order_pi_export.php`: `eda088143fc728238c27355fdeae2a2b9d3f3eef59fd51c224de5ad4d3e10e50`
- `quote_order_doc.php`: `b7ef024ef555fd2a4914b705077cceea4306ec94ca6009744844db05ed00062f`
- `quote_order_pdf.php`: `bb1a76215e2ea54cde3d6b2a9e24a503a8e96d034973196822996c2deedcb501`
- `quote_order_excel.php`: `4e842a1083a6c5868174c18e230815c1afc227deb533406073ccc5f3cfd7098f`

## 不允许修改

公司抬头、Logo、ARS MaquetteTr 字体、字号、颜色、边框、字段位置、列名/顺序/宽度、行高、页眉页脚、签章、银行、条款、备注、金额/币种/日期格式、A4比例、分页、重复表头、图片规则、空字段规则、文件名及 Excel 工作表结构全部冻结。新模块只做字段映射、权限、日志、快照和错误处理。
