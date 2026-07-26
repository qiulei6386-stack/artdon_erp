<?php
declare(strict_types=1);

namespace Artdon\CommercialCenter\Services;

final class QuoteOutputRenderer
{
    public function html(array $record,bool $print=false): string
    {
        $q=$record['snapshot'];$customer=$q['customer_snapshot']??[];$items=$q['items']??[];
        $currency=htmlspecialchars((string)($q['currency']??'USD'),ENT_QUOTES,'UTF-8');
        $watermark=trim((string)($record['watermark']??''));
        $rows='';
        foreach($items as $index=>$item){
            $name=$item['product_name']??$item['description']??'';
            $config=$this->configText($item['configuration_snapshot']??[]);
            $quantity=(float)($item['quantity']??0);$price=(float)($item['unit_price']??0);$amount=(float)($item['line_amount']??($quantity*$price));
            $rows.='<tr><td>'.($index+1).'</td><td>'.$this->e($item['model_no']??'').'</td><td>'.$this->e($name).'</td><td>'.$this->e($config).'</td><td class="n">'.$this->number($quantity).'</td><td>'.$this->e($item['unit']??'PCS').'</td><td class="n">'.$this->money($price).'</td><td class="n">'.$this->money($amount).'</td></tr>';
        }
        $customerName=$customer['customer_name']??$customer['customer_name_en']??'';
        $total=(float)($q['total_amount']??0);
        $autoPrint=$print?'<script>window.addEventListener("load",()=>window.print())</script>':'';
        return '<!doctype html><html lang="zh-CN"><head><meta charset="utf-8"><title>'.$this->e($q['quote_no']??'Quotation').'</title><style>
        @page{size:A4;margin:12mm}*{box-sizing:border-box}body{font:12px Arial,"Microsoft YaHei",sans-serif;color:#17213a;margin:0;background:#eef2f7}
        .page{width:210mm;min-height:277mm;margin:18px auto;background:white;padding:15mm;position:relative}.brand{color:#df2c27;font-weight:800;letter-spacing:2px}
        h1{font-size:24px;margin:8px 0 20px}.meta{display:grid;grid-template-columns:1fr 1fr;gap:7px 30px;margin-bottom:20px}.meta span{color:#667085}
        table{width:100%;border-collapse:collapse}th,td{border:1px solid #cfd6e2;padding:7px;vertical-align:top}th{background:#f3f5f8;text-align:left}.n{text-align:right}
        .totals{margin:18px 0 0 auto;width:280px}.totals div{display:flex;justify-content:space-between;padding:5px}.totals .grand{font-size:17px;font-weight:bold;border-top:2px solid #17213a}
        .terms{margin-top:25px;border-top:1px solid #ccd3dd;padding-top:12px;white-space:pre-wrap}.watermark{position:fixed;left:20%;top:40%;font-size:62px;color:rgba(210,20,20,.12);transform:rotate(-25deg);font-weight:800}
        .toolbar{position:fixed;right:20px;top:20px}.toolbar button{padding:8px 14px}@media print{body{background:#fff}.page{margin:0;padding:10mm;width:auto;min-height:auto}.toolbar{display:none}}
        </style></head><body>'.($print?'<div class="toolbar"><button onclick="window.print()">打印</button></div>':'').($watermark!==''?'<div class="watermark">'.$this->e($watermark).'</div>':'').'
        <main class="page"><div class="brand">ARTDON COMMERCIAL CENTER</div><h1>QUOTATION / 报价单</h1><section class="meta">
        <div><span>报价单号：</span><b>'.$this->e($q['quote_no']??'').'</b></div><div><span>报价日期：</span>'.$this->e($q['quote_date']??'').'</div>
        <div><span>客户：</span><b>'.$this->e($customerName).'</b></div><div><span>联系人：</span>'.$this->e($q['contact_name']??'').'</div>
        <div><span>国家：</span>'.$this->e($q['country']??'').'</div><div><span>有效期：</span>'.$this->e($q['valid_until']??'').'</div></section>
        <table><thead><tr><th>#</th><th>型号</th><th>产品</th><th>规格 / 配置</th><th>数量</th><th>单位</th><th>单价 '.$currency.'</th><th>金额 '.$currency.'</th></tr></thead><tbody>'.$rows.'</tbody></table>
        <section class="totals"><div><span>产品金额</span><b>'.$currency.' '.$this->money($q['subtotal_amount']??0).'</b></div>
        <div><span>折扣</span><b>- '.$this->money($q['discount_amount']??0).'</b></div><div><span>运费</span><b>'.$this->money($q['shipping_amount']??0).'</b></div>
        <div><span>税费</span><b>'.$this->money($q['tax_amount']??0).'</b></div><div class="grand"><span>总金额</span><b>'.$currency.' '.$this->money($total).'</b></div></section>
        <section class="terms"><b>Payment / 付款：</b> '.$this->e($q['payment_terms']??'')."\n".'<b>Trade Terms / 贸易条款：</b> '.$this->e($q['trade_terms']??'')."\n".'<b>Customer Note / 客户备注：</b> '.$this->e($q['customer_note']??'').'</section>
        </main>'.$autoPrint.'</body></html>';
    }

    public function excelXml(array $record): string
    {
        $q=$record['snapshot'];$customer=$q['customer_snapshot']??[];$items=$q['items']??[];
        $rows=$this->xmlRow(['QUOTATION / 报价单']);
        $rows.=$this->xmlRow(['Quote No',$q['quote_no']??'','Status',$q['status']??'','Version',$q['current_version']??'']);
        $rows.=$this->xmlRow(['Customer',$customer['customer_name']??$customer['customer_name_en']??'','Contact',$q['contact_name']??'','Currency',$q['currency']??'']);
        $rows.=$this->xmlRow(['#','Model','Product','Specification / Configuration','Quantity','Unit','Unit Price','Amount']);
        foreach($items as $i=>$item){
            $rows.=$this->xmlRow([$i+1,$item['model_no']??'',$item['product_name']??$item['description']??'',
                $this->configText($item['configuration_snapshot']??[]),(float)($item['quantity']??0),$item['unit']??'PCS',
                (float)($item['unit_price']??0),(float)($item['line_amount']??0)]);
        }
        $rows.=$this->xmlRow(['','','','','','','Total',(float)($q['total_amount']??0)]);
        $rows.=$this->xmlRow(['Payment',$q['payment_terms']??'','Trade Terms',$q['trade_terms']??'']);
        $rows.=$this->xmlRow(['Snapshot SHA-256',$record['snapshot_hash']??'']);
        return '<?xml version="1.0" encoding="UTF-8"?><?mso-application progid="Excel.Sheet"?>
        <Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet" xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet">
        <Worksheet ss:Name="Quotation"><Table>'.$rows.'</Table></Worksheet></Workbook>';
    }

    private function xmlRow(array $cells): string
    {
        $out='<Row>';foreach($cells as $cell){$type=is_int($cell)||is_float($cell)?'Number':'String';$out.='<Cell><Data ss:Type="'.$type.'">'.$this->e((string)$cell).'</Data></Cell>';}$out.='</Row>';return $out;
    }
    private function configText(mixed $value): string
    {
        if(!is_array($value))return trim((string)$value);
        $flat=[];array_walk_recursive($value,static function($v,$k)use(&$flat){if(is_scalar($v)&&$v!=='')$flat[]=(is_string($k)?$k.': ':'').$v;});
        return implode(' / ',$flat);
    }
    private function e(mixed $value): string{return htmlspecialchars((string)$value,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
    private function money(mixed $value): string{return number_format((float)$value,2,'.',',');}
    private function number(float $value): string{return rtrim(rtrim(number_format($value,3,'.',''),'0'),'.');}
}
