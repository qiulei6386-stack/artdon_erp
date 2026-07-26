# Step 10 转订单与原版单据联动

仅 `approved` / `customer_confirmed` 可转换。转换事务复制正式报价版本的客户、联系人、产品、图片、Customer Model、Manufacturer Code、配置、数量、价格、折扣、费用、佣金、条款、金额、币种、汇率、交期和备注；创建正式旧订单及明细、单证草稿出货批次、PI/CI/PL单证快照和新旧关联，随后报价进入 `converted`。

PI 经适配层 POST 原 `quote_order_pi_export.php`；CI/PL直接调用原 `quote_order_doc.php` 与 `quote_order_excel.php`。模板代码未复制、未修改。订单后续修改不反写报价或转换快照。

## 验收

- 正式订单、明细、单证草稿出货、PI/CI/PL三份单证快照、转换关联及报价 `converted` 状态在同一事务完成。
- Customer Model、Manufacturer Code、客户、配置、价格、折扣、金额和条款映射通过。
- 原六个正式模板文件 SHA-256 与冻结基线完全一致。
- 测试订单及相关数据已清理；旧报价保持35条、旧订单保持8个。
