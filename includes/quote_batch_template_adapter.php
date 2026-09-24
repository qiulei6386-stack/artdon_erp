<?php
// Adapt immutable batch facts to the ORIGINAL PL/CI page and XLSX exporters.
require_once __DIR__.'/quote_document_template.php';
require_once dirname(__DIR__).'/quote_order_excel.php';

function qsd_enrich(PDO $pdo,array $doc): array {
    require_once __DIR__.'/quote_read_projection.php';
    $d=&$doc['data'];
    // Earlier batches did not freeze the template metadata. Read only the
    // originating order's saved metadata, never the live customer/product master.
    if(!isset($d['document_order']))$d['document_order']=qr_document_order($pdo,(int)($d['base_order_id']??0));
    if(!isset($d['document_settings']))$d['document_settings']=qd_settings($pdo);
    foreach($d['items'] as &$item){
        $iid=(int)($item['order_item_id']??0);$oid=(int)($item['order_id']??0);
        $st=$pdo->prepare('SELECT '.qr_item_columns($pdo,'quote_sales_order_items','',true,false).' FROM quote_sales_order_items WHERE id=? AND order_id=?');
        $st->execute([$iid,$oid]);$source=$st->fetch(PDO::FETCH_ASSOC);
        if(!$source)throw new RuntimeException('原订单明细缺失，无法核对单证图片与规格（明细 '.$iid.'）');
        $source=qd_payload_to_doc_row($source);
        foreach(['size','hs_code'] as $field)if(!array_key_exists($field,$item))$item[$field]=$source[$field]??'';
        $item['image']=qsd_item_thumbnail($pdo,$iid,'quote_sales_order_items');
    }unset($item);
    return $doc;
}

function qsd_template_context(array $doc,string $type): array {
    $d=$doc['data'];$order=$d['document_order']??[];$settings=$d['document_settings']??qd_default_doc_settings();
    $order['currency']=$d['currency']??'';
    $items=[];$map=[];
    foreach($d['items']??[] as $item){
        // Shipment quantities/prices come exclusively from this selected revision.
        $row=qd_payload_to_doc_row($item);$iid=(int)$row['order_item_id'];
        if($iid<=0||isset($map[$iid]))throw new RuntimeException('单证产品明细重复或缺少来源');
        $map[$iid]=$row;$items[]=$row;
    }
    $order['_shipment_order_refs']=qd_shipment_order_refs($items,$order);
    $customer=qd_customer_from_order($order);
    // A merged batch has one explicitly confirmed consignee; do not substitute
    // the old customer's address or another participating order's contact.
    $customer=['company'=>$d['customer_name']??($customer['company']??''),'address'=>$d['consignee']??''];
    $sellerName=trim((string)($d['seller_name']??''));$sellerText=(string)($d['seller_text']??'');
    if($sellerName==='')list($sellerName,$sellerText)=qd_header_seller($order,$settings);
    $docTitle=$type==='ci'?'COMMERCIAL INVOICE':'PACKING LIST';$docFileTitle=qsd_title($doc,$type);
    $documentState=$docFileTitle.' · '.qsd_state($doc);$order['_document_state']=$documentState;
    $ship=['id'=>$doc['id'],'ship_date'=>$d['ship_date']??'','_document_date'=>$d['ship_date']??'',
        'commercial_invoice_no'=>qsd_title($doc,'ci'),'packing_list_no'=>qsd_title($doc,'pl'),
        'shipping_mark'=>$d['shipping_mark']??'','country_origin'=>$d['country_origin']??($settings['country_origin']??'China'),
        'forwarder'=>$d['forwarder']??($settings['forwarder']??''),'_price_terms'=>qd_price_terms_from_order($order,'')];
    $plItems=[];$allocated=[];
    foreach($d['cartons']??[] as $carton){
        $parts=array_values(array_filter($carton['items']??[],static function($p){return (float)($p['qty']??0)>0;}));
        $count=(float)($carton['carton_count']??1);if($count<=0||!$parts)throw new RuntimeException('单证装箱信息不完整');
        $qty=array_sum(array_column($parts,'qty'));
        foreach($parts as $n=>$part){
            $iid=(int)$part['order_item_id'];if(!isset($map[$iid]))throw new RuntimeException('箱内产品不在本版出货明细中');
            $row=$map[$iid];$row['qty']=(float)$part['qty'];$allocated[$iid]=($allocated[$iid]??0)+$row['qty'];
            $row['specification']=qd_desc($row)."\nCarton: ".($carton['carton_no']??'');
            if(!empty($carton['note']))$row['specification'].="\n".$carton['note'];
            foreach(['cartons','nw','gw','cbm','pcs_per_ctn'] as $field)$row[$field]=0;
            $row['carton_size']='';
            if($n===0){
                $row['_carton_rowspan']=count($parts);$row['cartons']=$count;$row['pcs_per_ctn']=$qty/$count;
                $row['carton_size']=$carton['carton_size']??'';
                foreach(['nw','gw','cbm'] as $field)$row[$field]=(float)($carton[$field]??0);
            }else $row['_carton_skip_pack']=true;
            $plItems[]=$row;
        }
    }
    foreach($map as $iid=>$row){
        $remaining=(float)$row['qty']-($allocated[$iid]??0);
        if($remaining<-.00001)throw new RuntimeException('单证装箱数量超出本版出货数量');
        if($remaining>.00001){
            if(!empty($doc['issued']))throw new RuntimeException('签发版装箱明细不完整，请核对原版，不生成不完整单证');
            $row['qty']=$remaining;$row['specification']=qd_desc($row)."\nUNPACKED / 待装箱";
            foreach(['cartons','nw','gw','cbm','pcs_per_ctn'] as $field)$row[$field]=0;
            $row['carton_size']='';$plItems[]=$row;
        }
    }
    $ciItems=$items; // Keep original selected unit price AND rounded line amount.
    $cartons=[];$shipmentId=(int)$doc['id'];$format='html';$documentReadOnly=true;
    $docVoided=$doc['state']==='cancelled';$voidText=$documentState;
    $documentNote=trim('Shipping: '.($d['ship_method']??'').' / '.($d['port_loading']??'').' → '.($d['port_destination']??'')."\nShipping Mark: ".($d['shipping_mark']??'')."\n".($d['note']??''));
    $order['_document_note']=$documentNote;
    return compact('type','order','ship','items','ciItems','plItems','cartons','settings','customer','sellerName','sellerText','docTitle','docFileTitle','shipmentId','format','documentReadOnly','documentState','documentNote','docVoided','voidText');
}

function qsd_html(array $doc,string $type): string {
    extract(qsd_template_context($doc,$type),EXTR_SKIP);
    ob_start();try{require __DIR__.'/quote_document_page.php';return ob_get_contents();}finally{ob_end_clean();}
}

function qsd_excel(array $doc,string $type): string {
    extract(qsd_template_context($doc,$type),EXTR_SKIP);
    $rows=$type==='ci'?$ciItems:$plItems;$deadline=microtime(true)+15;$cache=[];
    foreach($rows as &$row){$src=qd_img_src($row);if($src==='')continue;$key=hash('sha256',$src);if(!isset($cache[$key]))$cache[$key]=qsd_image($src,$deadline);$row['image']=$cache[$key];}unset($row);
    return qoe_build_xlsx($type,$order,$ship,$rows,[],$settings,$customer,$sellerName,$sellerText,$docTitle,$docFileTitle);
}
