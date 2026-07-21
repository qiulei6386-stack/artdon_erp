<?php
/* ARTDON_SSO_GATE_V2_START */
require_once __DIR__.'/includes/artdon_sso_core.php';
artdon_sso_require_page('quote');
/* ARTDON_SSO_GATE_V2_END */

require_once __DIR__ . '/includes/bootstrap.php';

/**
 * Quotation V6.8.4.12 统一权限中心联动版（基于 V6.8.4.11）
 * 目的：旧 quote_order_api.php 可能会 SELECT o.customer_name，
 * 但历史 quote_orders 表不一定有 customer_name 字段。
 * 这里在页面加载时自动补字段并从 customer_json 回填，避免单证中心 SQLSTATE[42S22]。
 */
function qdoc_v640_table_exists(PDO $pdo, string $t): bool {
  try { $s=$pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1"); $s->execute([$t]); return (bool)$s->fetchColumn(); }
  catch(Throwable $e){ return false; }
}
function qdoc_v640_columns(PDO $pdo, string $t): array {
  try { $s=$pdo->prepare("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION"); $s->execute([$t]); return array_map(fn($r)=>$r['COLUMN_NAME'],$s->fetchAll(PDO::FETCH_ASSOC)); }
  catch(Throwable $e){ return []; }
}
function qdoc_v640_pick_customer_name($json): string {
  $a=json_decode((string)$json,true);
  if(!is_array($a)) return '';
  foreach(['company','name','customer_name','client_name','company_name','customer','buyer_name','contact'] as $k){
    if(isset($a[$k]) && trim((string)$a[$k])!=='') return trim((string)$a[$k]);
  }
  return '';
}
function qdoc_v640_schema_fix(): void {
  try{
    if(!function_exists('db')) return;
    $pdo=db(); if(!$pdo instanceof PDO) return;
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    if(!qdoc_v640_table_exists($pdo,'quote_orders')) return;
    $cols=qdoc_v640_columns($pdo,'quote_orders');
    $addedCustomerName=false;
    if(!in_array('customer_name',$cols,true)){
      $pdo->exec("ALTER TABLE `quote_orders` ADD COLUMN `customer_name` VARCHAR(255) DEFAULT ''");
      try{ $pdo->exec("ALTER TABLE `quote_orders` ADD KEY `idx_customer_name` (`customer_name`)"); }catch(Throwable $e){}
      $cols[]='customer_name';
      $addedCustomerName=true;
    }
    if($addedCustomerName && in_array('customer_json',$cols,true)){
      $st=$pdo->query("SELECT id, customer_json FROM `quote_orders` WHERE (customer_name IS NULL OR customer_name='') ORDER BY id DESC LIMIT 3000");
      $up=$pdo->prepare("UPDATE `quote_orders` SET customer_name=? WHERE id=?");
      foreach($st->fetchAll(PDO::FETCH_ASSOC) as $r){
        $name=qdoc_v640_pick_customer_name($r['customer_json'] ?? '');
        if($name!=='') $up->execute([mb_substr($name,0,255,'UTF-8'), (int)$r['id']]);
      }
    }
  }catch(Throwable $e){
    // 不影响页面打开，接口错误仍可继续反馈。
  }
}
qdoc_v640_schema_fix();
?><!doctype html><html lang="zh-CN"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Artdon Quotation ERP PHP</title><script>try{document.documentElement.dataset.startPage=localStorage.getItem('artdon_quote_current_page')||'quote'}catch(e){document.documentElement.dataset.startPage='quote'}
</script>
<style>
@font-face{font-family:"ARS MaquetteTr";src:url("assets/fonts/ARSMaqLigTr.otf") format("opentype");font-weight:300 900;font-style:normal;font-display:swap}
html,body,.paper,.paper *,.quote-table,.quote-table *,.terms,.terms *,.bank,.bank *,.bank-terms,.bank-terms *,.final-summary,.final-summary *,.final-sign,.final-sign *,.doc-table,.doc-table *,.box,.box *{font-family:"ARS MaquetteTr","Microsoft YaHei",Arial,sans-serif!important;}
:root{--bg:#f4f6fa;--card:#fff;--line:#d8dee9;--blue:#2563eb;--green:#059669;--red:#dc2626;--muted:#667085;--text:#111827}*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--text);font-family:-apple-system,BlinkMacSystemFont,"Segoe UI","Microsoft YaHei",Arial,sans-serif}.top{min-height:76px;background:linear-gradient(90deg,#0f172a,#111827);color:#fff;display:flex;align-items:center;gap:18px;padding:10px 18px}.brand b{font-size:28px}.brand small{display:block;color:#cbd5e1;font-size:14px;margin-top:4px}.clock{margin-left:auto;color:#dbeafe}.top button{background:#fff;color:#111827}.dash{display:block;padding:8px 16px 12px;border-bottom:1px solid var(--line)}.dash-togglebar{display:flex;align-items:center;gap:10px;justify-content:space-between;min-height:30px}.dash-togglebar b{font-size:13px;font-weight:1000;color:#334155}.dash-mini{flex:1;color:#667085;font-size:12px;font-weight:800;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.dash-cards{display:flex;gap:12px;flex-wrap:wrap;margin-top:8px}.dash-toggle-btn{height:28px!important;padding:0 10px!important;border-radius:999px!important;font-size:12px!important;font-weight:900!important}.dash.is-collapsed{padding:6px 16px;border-bottom:1px solid var(--line)}.dash.is-collapsed .dash-cards{display:none}.dash.is-collapsed .dash-togglebar{min-height:28px}.dash-card{width:160px;height:132px;background:white;border:1px solid var(--line);border-radius:16px;padding:14px;display:flex;flex-direction:column;justify-content:space-between;box-shadow:0 2px 10px #0001;position:relative;overflow:hidden}.dash-card b{font-size:25px}.dash-card span{font-size:13px;color:var(--muted);font-weight:700}.dash-card small{color:var(--muted)}.dash-card:before{content:"";position:absolute;right:-34px;top:-42px;width:112px;height:112px;border-radius:999px;background:var(--kpi-accent,#2563eb);opacity:.12;pointer-events:none}.dash-card>*{position:relative;z-index:1}.dash-card .kpi-num,.dash-card b{color:var(--kpi-ink,#111827)}.dash-card .kpi-line{border-top-color:var(--kpi-line,#e5e7eb)}body[data-dash-color="soft"] .dash-card{background:linear-gradient(145deg,#fff,var(--kpi-bg,#f8fafc));border-color:var(--kpi-border,#d8dee9);box-shadow:0 10px 26px rgba(15,23,42,.07)}body[data-dash-color="soft"] .dash-card[data-dash-widget="quote_count"]{--kpi-bg:#eff6ff;--kpi-border:#bfdbfe;--kpi-accent:#2563eb;--kpi-ink:#1d4ed8;--kpi-line:#bfdbfe}body[data-dash-color="soft"] .dash-card[data-dash-widget="month_amount"]{--kpi-bg:#ecfdf5;--kpi-border:#bbf7d0;--kpi-accent:#059669;--kpi-ink:#047857;--kpi-line:#bbf7d0}body[data-dash-color="soft"] .dash-card[data-dash-widget="top_customer"]{--kpi-bg:#f5f3ff;--kpi-border:#ddd6fe;--kpi-accent:#7c3aed;--kpi-ink:#5b21b6;--kpi-line:#ddd6fe}body[data-dash-color="soft"] .dash-card[data-dash-widget="currency"]{--kpi-bg:#f8fafc;--kpi-border:#cbd5e1;--kpi-accent:#475569;--kpi-ink:#0f172a;--kpi-line:#cbd5e1}body[data-dash-color="soft"] .dash-card[data-dash-widget="top_sales"]{--kpi-bg:#fff7ed;--kpi-border:#fed7aa;--kpi-accent:#ea580c;--kpi-ink:#c2410c;--kpi-line:#fed7aa}body[data-dash-color="soft"] .dash-card[data-dash-widget="approval"]{--kpi-bg:#fefce8;--kpi-border:#fde68a;--kpi-accent:#ca8a04;--kpi-ink:#854d0e;--kpi-line:#fde68a}body[data-dash-color="soft"] .dash-card[data-dash-widget="convert"]{--kpi-bg:#ecfeff;--kpi-border:#a5f3fc;--kpi-accent:#0891b2;--kpi-ink:#0e7490;--kpi-line:#a5f3fc}body[data-dash-color="soft"] .dash-card[data-dash-widget="order_revenue"]{--kpi-bg:#ecfdf5;--kpi-border:#86efac;--kpi-accent:#16a34a;--kpi-ink:#15803d;--kpi-line:#bbf7d0}body[data-dash-color="soft"] .dash-card[data-dash-widget="docs_ship"]{--kpi-bg:#eef2ff;--kpi-border:#c7d2fe;--kpi-accent:#4f46e5;--kpi-ink:#3730a3;--kpi-line:#c7d2fe}body[data-dash-color="soft"] .dash-card[data-dash-widget="receivable"]{--kpi-bg:#fff1f2;--kpi-border:#fecdd3;--kpi-accent:#e11d48;--kpi-ink:#be123c;--kpi-line:#fecdd3}body[data-dash-color="business"] .dash-card{background:linear-gradient(145deg,#ffffff,#f1f5ff);border-color:#c7d2fe;box-shadow:0 12px 28px rgba(37,99,235,.10);--kpi-bg:#eff6ff;--kpi-border:#bfdbfe;--kpi-accent:#2563eb;--kpi-ink:#1e3a8a;--kpi-line:#dbeafe}body[data-dash-color="business"] .dash-card[data-dash-widget="month_amount"],body[data-dash-color="business"] .dash-card[data-dash-widget="convert"]{--kpi-accent:#0f766e;--kpi-ink:#0f766e}body[data-dash-color="business"] .dash-card[data-dash-widget="approval"],body[data-dash-color="business"] .dash-card[data-dash-widget="top_sales"]{--kpi-accent:#ea580c;--kpi-ink:#9a3412}body[data-dash-color="business"] .dash-card[data-dash-widget="receivable"]{--kpi-accent:#dc2626;--kpi-ink:#991b1b}body[data-dash-color="strong"] .dash-card{background:linear-gradient(145deg,var(--kpi-accent,#2563eb),#0f172a);border-color:rgba(255,255,255,.08);box-shadow:0 14px 34px rgba(15,23,42,.18);color:#fff}.dash-color-dot{display:inline-block;width:12px;height:12px;border-radius:999px;background:var(--dot,#2563eb);margin-right:6px;box-shadow:0 0 0 3px rgba(37,99,235,.08)}body[data-dash-color="strong"] .dash-card span,body[data-dash-color="strong"] .dash-card small,body[data-dash-color="strong"] .dash-card .kpi-line{color:rgba(255,255,255,.82)}body[data-dash-color="strong"] .dash-card b,body[data-dash-color="strong"] .dash-card .kpi-num{color:#fff}body[data-dash-color="strong"] .dash-card .kpi-line{border-top-color:rgba(255,255,255,.18)}body[data-dash-color="strong"] .dash-card:before{background:#fff;opacity:.10}body[data-dash-color="strong"] .dash-card[data-dash-widget="quote_count"]{--kpi-accent:#2563eb}body[data-dash-color="strong"] .dash-card[data-dash-widget="month_amount"]{--kpi-accent:#059669}body[data-dash-color="strong"] .dash-card[data-dash-widget="top_customer"]{--kpi-accent:#7c3aed}body[data-dash-color="strong"] .dash-card[data-dash-widget="currency"]{--kpi-accent:#334155}body[data-dash-color="strong"] .dash-card[data-dash-widget="top_sales"]{--kpi-accent:#ea580c}body[data-dash-color="strong"] .dash-card[data-dash-widget="approval"]{--kpi-accent:#ca8a04}body[data-dash-color="strong"] .dash-card[data-dash-widget="convert"]{--kpi-accent:#0891b2}body[data-dash-color="strong"] .dash-card[data-dash-widget="order_revenue"]{--kpi-accent:#16a34a}body[data-dash-color="strong"] .dash-card[data-dash-widget="docs_ship"]{--kpi-accent:#4f46e5}body[data-dash-color="strong"] .dash-card[data-dash-widget="receivable"]{--kpi-accent:#e11d48}body[data-dash-color="none"] .dash-card{background:#fff!important;border-color:#d8dee9!important;box-shadow:0 2px 10px rgba(15,23,42,.05)!important;--kpi-accent:#cbd5e1;--kpi-ink:#111827;--kpi-line:#e5e7eb}body[data-dash-color="none"] .dash-card:before{display:none!important}body[data-dash-color="none"] .dash-card .kpi-num,body[data-dash-color="none"] .dash-card b{color:#111827!important}body[data-dash-color="none"] .dash-card span,body[data-dash-color="none"] .dash-card small{color:#667085!important}body[data-dash-color="glass"] .dash-card{background:linear-gradient(145deg,rgba(255,255,255,.78),rgba(245,249,255,.48));border-color:rgba(191,219,254,.72);box-shadow:0 18px 45px rgba(15,23,42,.10);backdrop-filter:blur(14px);-webkit-backdrop-filter:blur(14px);--kpi-bg:rgba(239,246,255,.68);--kpi-accent:#2563eb;--kpi-ink:#1e3a8a;--kpi-line:rgba(147,197,253,.55)}body[data-dash-color="glass"] .dash-card:before{opacity:.16;filter:blur(.2px)}body[data-dash-color="glass"] .dash-card:after{content:"";position:absolute;left:12px;right:12px;top:9px;height:1px;background:linear-gradient(90deg,transparent,rgba(255,255,255,.9),transparent);pointer-events:none}body[data-dash-color="glass"] .dash-card[data-dash-widget="month_amount"]{--kpi-accent:#059669;--kpi-ink:#047857}body[data-dash-color="glass"] .dash-card[data-dash-widget="top_customer"]{--kpi-accent:#7c3aed;--kpi-ink:#5b21b6}body[data-dash-color="glass"] .dash-card[data-dash-widget="top_sales"]{--kpi-accent:#ea580c;--kpi-ink:#9a3412}body[data-dash-color="glass"] .dash-card[data-dash-widget="approval"]{--kpi-accent:#ca8a04;--kpi-ink:#854d0e}body[data-dash-color="glass"] .dash-card[data-dash-widget="convert"]{--kpi-accent:#0891b2;--kpi-ink:#0e7490}body[data-dash-color="glass"] .dash-card[data-dash-widget="order_revenue"]{--kpi-accent:#16a34a;--kpi-ink:#15803d}body[data-dash-color="glass"] .dash-card[data-dash-widget="docs_ship"]{--kpi-accent:#4f46e5;--kpi-ink:#3730a3}body[data-dash-color="glass"] .dash-card[data-dash-widget="receivable"]{--kpi-accent:#e11d48;--kpi-ink:#be123c}body[data-dash-color="warm"] .dash-card{background:linear-gradient(145deg,#fff,#fff7ed);border-color:#fed7aa;box-shadow:0 12px 28px rgba(234,88,12,.08);--kpi-accent:#f97316;--kpi-ink:#9a3412;--kpi-line:#fed7aa}body[data-dash-color="warm"] .dash-card[data-dash-widget="month_amount"],body[data-dash-color="warm"] .dash-card[data-dash-widget="convert"]{--kpi-accent:#059669;--kpi-ink:#047857}body[data-dash-color="warm"] .dash-card[data-dash-widget="receivable"]{--kpi-accent:#dc2626;--kpi-ink:#991b1b}body[data-dash-color="green"] .dash-card{background:linear-gradient(145deg,#fff,#ecfdf5);border-color:#bbf7d0;box-shadow:0 12px 28px rgba(5,150,105,.08);--kpi-accent:#059669;--kpi-ink:#047857;--kpi-line:#bbf7d0}body[data-dash-color="green"] .dash-card[data-dash-widget="approval"],body[data-dash-color="green"] .dash-card[data-dash-widget="top_sales"]{--kpi-accent:#d97706;--kpi-ink:#92400e}body[data-dash-color="green"] .dash-card[data-dash-widget="receivable"]{--kpi-accent:#dc2626;--kpi-ink:#991b1b}body[data-dash-color="purple"] .dash-card{background:linear-gradient(145deg,#fff,#f5f3ff);border-color:#ddd6fe;box-shadow:0 12px 28px rgba(124,58,237,.09);--kpi-accent:#7c3aed;--kpi-ink:#5b21b6;--kpi-line:#ddd6fe}body[data-dash-color="purple"] .dash-card[data-dash-widget="month_amount"],body[data-dash-color="purple"] .dash-card[data-dash-widget="convert"]{--kpi-accent:#0891b2;--kpi-ink:#0e7490}body[data-dash-color="purple"] .dash-card[data-dash-widget="receivable"]{--kpi-accent:#e11d48;--kpi-ink:#be123c}.dash-color-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:8px;margin:8px 0 14px}.dash-color-choice{border:1px solid #dbe3ef;border-radius:12px;background:#fbfdff;padding:10px;font-size:13px;font-weight:900;display:flex;align-items:center;gap:8px;cursor:pointer}.dash-color-choice input{width:auto;accent-color:#2563eb}.dash-color-choice:has(input:checked){border-color:#93c5fd;background:#eff6ff}.dash-color-sample{display:flex;gap:4px;margin-left:auto}.dash-color-sample i{width:14px;height:14px;border-radius:5px;background:var(--c)}@media(max-width:820px){.dash-color-grid{grid-template-columns:1fr}}.dash-card.kpi-wide{width:230px}.dash-card.kpi-xl{width:270px}.dash-card .kpi-main{display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap}.dash-card .kpi-main b{font-size:24px}.dash-card .kpi-grid{display:grid;grid-template-columns:1fr 1fr;gap:5px;margin-top:6px}.dash-card .kpi-line{display:flex;justify-content:space-between;gap:8px;border-top:1px dashed #e5e7eb;padding-top:5px;font-size:12px;color:#475467;font-weight:850}.dash-card .kpi-num{font-weight:1000;color:#111827}.dash-card.is-hidden-by-template{display:none!important}.dash-template-mask{position:fixed;inset:0;background:#0006;z-index:120;display:flex;align-items:center;justify-content:center;padding:20px}.dash-template-box{width:min(760px,96vw);max-height:86vh;overflow:auto;background:#fff;border-radius:18px;border:1px solid var(--line);box-shadow:0 24px 80px rgba(15,23,42,.28)}.dash-template-head{display:flex;justify-content:space-between;align-items:center;gap:10px;padding:14px 16px;border-bottom:1px solid var(--line)}.dash-template-body{padding:14px 16px}.dash-template-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px}.dash-template-item{border:1px solid #dbe3ef;border-radius:12px;background:#fbfdff;padding:10px;font-size:13px;font-weight:900;display:flex;gap:8px;align-items:center}.dash-template-item input{width:auto;accent-color:#2563eb}.dash-template-actions{display:flex;gap:8px;flex-wrap:wrap;justify-content:flex-end;margin-top:14px}.dash-refresh-note{font-size:11px;color:#667085;font-weight:800;margin-left:8px}@media(max-width:820px){.dash-template-grid{grid-template-columns:1fr}.dash-card.kpi-wide,.dash-card.kpi-xl{width:100%}}.shell{display:grid;grid-template-columns:210px 1fr}.side{background:#fff;border-right:1px solid var(--line);padding:14px;min-height:calc(100vh - 76px)}.nav{display:grid;gap:8px}.nav button{background:#fff;color:#111827;border:1px solid var(--line);text-align:left}.nav button.active{background:var(--blue);color:#fff}.main{padding:16px}.page{display:none}.page.active{display:block}html[data-start-page="quote"] #page-quote,html[data-start-page="products"] #page-products,html[data-start-page="customers"] #page-customers,html[data-start-page="materials"] #page-materials,html[data-start-page="history"] #page-history,html[data-start-page="settings"] #page-settings{display:block}html[data-start-page="quote"] .nav button:nth-child(1),html[data-start-page="products"] .nav button:nth-child(2),html[data-start-page="customers"] .nav button:nth-child(3),html[data-start-page="materials"] .nav button:nth-child(4),html[data-start-page="history"] .nav button:nth-child(5),html[data-start-page="settings"] .nav button:nth-child(6){background:var(--blue);color:#fff}.card{background:#fff;border:1px solid var(--line);border-radius:14px;box-shadow:0 2px 12px #0001;overflow:hidden}.card-head{padding:13px 15px;border-bottom:1px solid var(--line);display:flex;justify-content:space-between;gap:10px}.card-body{padding:14px}.split{display:grid;grid-template-columns:430px 1fr;gap:14px}.grid2{display:grid;grid-template-columns:430px 1fr;gap:14px}.row2{display:grid;grid-template-columns:1fr 1fr;gap:10px}.row3{display:grid;grid-template-columns:repeat(3,1fr);gap:10px}.row4{display:grid;grid-template-columns:repeat(4,1fr);gap:10px}label{display:block;font-size:12px;color:#475467;font-weight:700;margin:9px 0 5px}input,select,textarea{width:100%;border:1px solid #cfd6e3;border-radius:9px;padding:8px 9px;font-size:13px;background:#fff}textarea{min-height:70px}.btns,.toolbar{display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-top:10px}button{border:0;background:#111827;color:#fff;border-radius:9px;padding:8px 12px;cursor:pointer}button.blue{background:var(--blue)}button.green{background:var(--green)}button.red{background:var(--red)}button.gray{background:#eef2f7;color:#111827;border:1px solid #d4dbe7}.hint{font-size:12px;color:var(--muted)}.part{border:1px solid var(--line);border-radius:12px;padding:10px;margin-bottom:9px}.part .title{display:flex;justify-content:space-between;font-weight:800;margin-bottom:8px}.part-shell{background:#eaf3ff}.part-connector{background:#eef9ee}.part-led{background:#fff8e5}.part-driver{background:#f4f0ff}.part-optic{background:#eaf7fb}.part-extra{background:#fff0f0}.mat-list{overflow-y:auto;background:#fff;border:1px solid #e5e7eb;border-radius:10px;margin-top:8px;overscroll-behavior:contain}.mat-list.is-short{overflow-y:visible}.mat-row{display:grid;grid-template-columns:1fr auto;gap:8px;padding:7px 9px;border-bottom:1px solid #eef1f5;cursor:pointer}.mat-row:hover{background:#eff6ff}.mat-row b{font-size:13px}.mat-row small{display:block;color:#667085;font-size:11px}.price{font-weight:800;color:#b42318}.preview{overflow:auto;max-height:calc(100vh - 120px);padding:10px}.paper{width:210mm;height:297mm;min-height:297mm;background:#fff;margin:auto;border:1px solid #cfd6e3;padding:20mm 15mm 17mm;overflow:hidden;font-family:"ARS MaquetteTr","Microsoft YaHei",Arial,sans-serif;color:#000}.paper-top{display:grid;grid-template-columns:96mm 72mm;gap:12mm}.from h1{font-size:16pt;margin:0 0 5mm;font-weight:400}.block,.to{font-size:9.2pt;line-height:1.25;white-space:pre-line}.to{margin-top:7mm}.brandstamp{text-align:center;color:#0000a0;font-weight:700;font-size:8.6pt;white-space:pre-line}.qt-title{text-align:center;font-size:15pt;font-weight:700;margin:2mm 0 4mm}.terms{width:72mm;border-collapse:collapse;font-size:8.2pt}.terms td{border:1.25px solid #000;padding:1.1mm 2mm;height:4.9mm}.terms td:first-child{font-weight:700;text-align:right;width:34mm}.quote-table{width:100%;border-collapse:collapse;table-layout:fixed;margin-top:10mm;font-size:7.0pt;line-height:1.18}.quote-table th,.quote-table td{border:1.25px solid #000;padding:1mm;text-align:center;vertical-align:middle;overflow-wrap:anywhere}.quote-table th{height:10mm}.quote-table .spec{text-align:left;white-space:pre-line;line-height:1.18;padding:1.4mm;vertical-align:middle!important}.prod-img{max-width:100%;max-height:18mm;object-fit:contain}.remark{font-size:7.8pt;margin-top:2mm}.signature{text-align:center;font-size:9pt;margin-top:18mm}.bank{background:#f2f2f2;border:0;outline:0;box-shadow:none;padding:2.2mm;font-size:7.8pt;line-height:1.23;margin-top:8mm;white-space:pre-line}.bank-terms{border:0;outline:0;box-shadow:none;min-height:38mm;padding:2.5mm;font-size:var(--quote-bank-terms-font-size,7.9pt);line-height:1.28;margin-top:4mm;white-space:pre-line;background:#fff}.bank-terms:empty{display:none}.list{display:grid;gap:8px;max-height:650px;overflow:auto}.item{border:1px solid var(--line);border-radius:11px;padding:10px;background:#fff;cursor:pointer}.item:hover{border-color:#2563eb}.item small{display:block;color:#667085;margin-top:4px}.badge{display:inline-block;border-radius:999px;padding:2px 6px;font-size:11px;margin-right:5px;border:1px solid #d4dbe7;background:#f8fafc;color:#334155}.badge.crm{background:#ecfdf3;border-color:#a6f4c5;color:#027a48}.badge.local{background:#eff6ff;border-color:#bfdbfe;color:#1d4ed8}.product-gallery{display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:12px}.prod-card{border:1px solid var(--line);border-radius:14px;overflow:hidden;background:#fff;cursor:pointer}.prod-card .img{height:120px;background:#f8fafc;display:flex;align-items:center;justify-content:center}.prod-card img{max-width:100%;max-height:100%;object-fit:contain}.prod-card div.body{padding:10px}.table{width:100%;border-collapse:collapse}.table th,.table td{border:1px solid var(--line);padding:8px;font-size:13px;text-align:left}.table th{background:#f8fafc}.modal{position:fixed;inset:0;background:#0006;z-index:99;display:none;align-items:center;justify-content:center;padding:20px}.modal.show{display:flex}.modal-box{background:#fff;border-radius:18px;width:min(1180px,96vw);max-height:88vh;overflow:auto;padding:14px}.modal-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(210px,1fr));gap:12px}.source-tag{display:inline-block;border-radius:999px;padding:1px 6px;font-size:10px;margin-right:4px;border:1px solid #cbd5e1;background:#f8fafc;color:#475467}.source-tag.naming{background:#f0fdf4;border-color:#86efac;color:#166534}.source-tag.bom{background:#fff7ed;border-color:#fdba74;color:#9a3412}.source-tag.warn{background:#fff1f2;border-color:#fda4af;color:#be123c}.product-link-hint{padding:7px 9px;border:1px dashed #cbd5e1;border-radius:9px;background:#f8fafc;line-height:1.45}.product-link-hint b{color:#111827}.quote-spec-badge{display:inline-block;border-radius:999px;background:#eef2ff;color:#3730a3;border:1px solid #c7d2fe;padding:2px 7px;font-size:11px;margin-left:4px}.quote-spec-actions{display:flex;gap:6px;flex-wrap:wrap;margin-top:7px}.quote-spec-actions button{font-size:11px;padding:4px 8px;border-radius:999px;line-height:1.2}.quote-spec-actions .mini-link{background:#fff;color:#1d4ed8;border:1px solid #bfdbfe}.quote-spec-actions .mini-sync{background:#eff6ff;color:#1e40af;border:1px solid #bfdbfe}
/* V6.6.8 紧凑参数 + 备注扩展 */
.quote-work .card-body{padding:10px 12px!important}.quote-current-title{margin-bottom:6px}.q-compact-grid{display:grid;grid-template-columns:70px 86px 76px 76px;gap:7px;align-items:end}.q-compact-grid-2{display:grid;grid-template-columns:80px 116px 1fr;gap:7px;align-items:end}.q-mini-grid{display:grid;grid-template-columns:88px 76px 86px 86px 82px 72px;gap:7px;align-items:end}.q-remark-grid{display:grid;grid-template-columns:1fr 1fr;gap:7px;margin-top:4px}.q-compact-grid label,.q-compact-grid-2 label,.q-mini-grid label,.q-remark-grid label{font-size:11px;margin:5px 0 3px}.quote-work input,.quote-work select,.quote-work textarea{font-size:12px;padding:6px 8px;border-radius:8px}.quote-work textarea{min-height:42px}.q-small-input{text-align:center}.q-level-box{display:grid;grid-template-columns:64px 32px 32px;gap:5px;align-items:center}.q-level-box button{padding:5px 0;border-radius:7px;font-weight:900}.q-inline-note{font-size:11px;color:#667085;line-height:1.35;margin-top:5px}.quote-add-bar{display:grid;grid-template-columns:1fr 1fr 1fr;gap:8px;margin-top:8px}.quote-add-bar button{padding:9px 10px}.quote-items{margin-top:8px}@media(max-width:1500px){.q-compact-grid{grid-template-columns:repeat(4,1fr)}.q-compact-grid-2{grid-template-columns:1fr 1.3fr 1fr}.q-mini-grid{grid-template-columns:repeat(5,1fr)}}

/* V6.6.9 紧凑参数区排版修正：固定列宽，修复倍率 +/- 错位与右边不齐 */
.quote-work .card-body{padding:10px 12px!important;overflow:hidden;}
.quote-current-title{display:flex;justify-content:space-between;align-items:center;margin-bottom:6px;gap:8px;}
.q-compact-grid,.q-compact-grid-2,.q-mini-grid,.q-remark-grid{width:100%;}
.q-compact-grid{display:grid!important;grid-template-columns:74px 92px 74px 82px!important;gap:8px!important;align-items:end!important;}
.q-compact-grid-2{display:grid!important;grid-template-columns:76px minmax(126px,1fr) 142px!important;gap:8px!important;align-items:end!important;}
.q-mini-grid{display:grid!important;grid-template-columns:84px 94px 84px 72px!important;gap:8px!important;align-items:end!important;}
.q-remark-grid{display:grid!important;grid-template-columns:1fr 1fr!important;gap:8px!important;margin-top:4px!important;}
.q-compact-grid label,.q-compact-grid-2 label,.q-mini-grid label,.q-remark-grid label{font-size:11px!important;line-height:1.1!important;margin:5px 0 4px!important;white-space:nowrap;}
.quote-work input,.quote-work select,.quote-work textarea{height:33px!important;font-size:13px!important;line-height:1.15!important;padding:5px 8px!important;border-radius:9px!important;}
.quote-work textarea{height:54px!important;min-height:54px!important;}
.q-small-input{text-align:center!important;}
.q-level-box{display:grid!important;grid-template-columns:64px 32px 32px!important;gap:5px!important;align-items:center!important;}
.q-level-box input{height:33px!important;text-align:center!important;padding:4px 5px!important;}
.q-level-box button{height:33px!important;min-width:0!important;width:32px!important;padding:0!important;border-radius:9px!important;font-size:18px!important;font-weight:900!important;line-height:1!important;display:flex!important;align-items:center!important;justify-content:center!important;}
#priceLevel{font-size:12px!important;padding-left:7px!important;padding-right:22px!important;white-space:nowrap;}
#currency,#color{font-size:13px!important;}
#cct,#cri,#ip{font-size:13px!important;text-align:center!important;}
.q-inline-note{font-size:11px!important;line-height:1.25!important;margin-top:5px!important;}
.quote-add-bar{display:grid!important;grid-template-columns:1fr 1fr 1fr!important;gap:8px!important;margin-top:8px!important;}
.quote-add-bar button{height:42px!important;padding:8px 10px!important;font-size:14px!important;border-radius:10px!important;}
@media(max-width:1500px){
  .q-compact-grid{grid-template-columns:74px 92px 74px 82px!important;}
  .q-compact-grid-2{grid-template-columns:76px minmax(126px,1fr) 142px!important;}
  .q-mini-grid{grid-template-columns:84px 94px 84px 72px!important;}
}
@media(max-width:1280px){
  .q-compact-grid{grid-template-columns:repeat(4,minmax(0,1fr))!important;}
  .q-compact-grid-2{grid-template-columns:78px minmax(118px,1fr) 138px!important;}
  .q-mini-grid{grid-template-columns:repeat(4,minmax(0,1fr))!important;}
}
@media(max-width:1200px){.shell,.split,.grid2{grid-template-columns:1fr}.side{min-height:auto}.paper{transform-origin:top left}}@media print{.top,.dash,.side,.no-print{display:none!important}.shell{display:block}.main{padding:0}.split{display:block}.split>.card:first-child{display:none}.card{border:0;box-shadow:none}.paper{border:0;box-shadow:none}@page{size:A4 portrait;margin:0}}

.quote-page-split{display:grid;grid-template-columns:430px 430px minmax(680px,1fr);gap:14px;align-items:start;transition:grid-template-columns .18s ease}.quote-page-split.config-side-collapsed{grid-template-columns:56px minmax(520px,560px) minmax(720px,1fr)}.quote-work .card-body{padding:14px}.quote-current-title{display:flex;justify-content:space-between;align-items:center;margin-bottom:6px}.quote-current-title b{font-size:15px}.quote-page-split .preview{max-height:calc(100vh - 120px)}@media(max-width:1500px){.quote-page-split{grid-template-columns:410px 410px minmax(620px,1fr)}.quote-page-split.config-side-collapsed{grid-template-columns:54px minmax(500px,560px) minmax(620px,1fr)}}
/* V6.6.8 紧凑参数 + 备注扩展 */
.quote-work .card-body{padding:10px 12px!important}.quote-current-title{margin-bottom:6px}.q-compact-grid{display:grid;grid-template-columns:70px 86px 76px 76px;gap:7px;align-items:end}.q-compact-grid-2{display:grid;grid-template-columns:80px 116px 1fr;gap:7px;align-items:end}.q-mini-grid{display:grid;grid-template-columns:88px 76px 86px 86px 82px 72px;gap:7px;align-items:end}.q-remark-grid{display:grid;grid-template-columns:1fr 1fr;gap:7px;margin-top:4px}.q-compact-grid label,.q-compact-grid-2 label,.q-mini-grid label,.q-remark-grid label{font-size:11px;margin:5px 0 3px}.quote-work input,.quote-work select,.quote-work textarea{font-size:12px;padding:6px 8px;border-radius:8px}.quote-work textarea{min-height:42px}.q-small-input{text-align:center}.q-level-box{display:grid;grid-template-columns:64px 32px 32px;gap:5px;align-items:center}.q-level-box button{padding:5px 0;border-radius:7px;font-weight:900}.q-inline-note{font-size:11px;color:#667085;line-height:1.35;margin-top:5px}.quote-add-bar{display:grid;grid-template-columns:1fr 1fr 1fr;gap:8px;margin-top:8px}.quote-add-bar button{padding:9px 10px}.quote-items{margin-top:8px}@media(max-width:1500px){.q-compact-grid{grid-template-columns:repeat(4,1fr)}.q-compact-grid-2{grid-template-columns:1fr 1.3fr 1fr}.q-mini-grid{grid-template-columns:repeat(5,1fr)}}
@media(max-width:1200px){.quote-page-split,.quote-page-split.config-side-collapsed{grid-template-columns:1fr}.quote-page-split .preview{max-height:none}}@media print{.quote-page-split{display:block}.quote-page-split>.no-print{display:none!important}}
/* V2 多产品报价：一个报价单可无限增加产品 */
.quote-items{margin-top:12px;border:1px solid #e5e7eb;border-radius:12px;background:#fff;overflow:hidden}
.quote-items-head{display:flex;justify-content:space-between;align-items:center;padding:8px 10px;background:#f8fafc;border-bottom:1px solid #e5e7eb}
.quote-item-row{display:grid;grid-template-columns:auto 1fr auto;gap:8px;align-items:center;padding:8px 10px;border-bottom:1px solid #eef1f5;background:#fff;position:relative}
.quote-item-row:last-child{border-bottom:0}.quote-item-row.active{background:#eff6ff}.quote-item-main{min-width:0}
.quote-item-main b{display:block;font-size:13px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.quote-item-main small{display:block;color:#667085;font-size:12px;margin-top:3px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.quote-drag-handle{width:28px;height:34px;border:1px solid #d4dbe7;border-radius:8px;background:#f8fafc;color:#667085;display:flex;align-items:center;justify-content:center;cursor:grab;font-size:18px;line-height:1;user-select:none;touch-action:none}.quote-drag-handle:active{cursor:grabbing}.quote-item-row.dragging{opacity:.45}.quote-item-row.drag-over-before::before,.quote-item-row.drag-over-after::after{content:'';position:absolute;left:8px;right:8px;height:3px;background:#2563eb;border-radius:99px;z-index:3}.quote-item-row.drag-over-before::before{top:0}.quote-item-row.drag-over-after::after{bottom:0}.quote-item-actions{display:flex;gap:5px;flex-wrap:nowrap}.quote-item-actions button{padding:5px 7px!important;font-size:12px!important;border-radius:7px!important}
.quote-add-bar{display:grid;grid-template-columns:1fr 1fr 1fr;gap:8px;margin-top:10px}.quote-table tbody tr.quote-product-row{height:auto;page-break-inside:avoid;break-inside:avoid}.quote-table tbody tr.quote-product-row td{height:auto;vertical-align:middle}.quote-table tbody tr.quote-product-row .spec{vertical-align:middle!important}.quote-table tbody tr.quote-material-row td{padding:.55mm .8mm;line-height:1.08}.quote-table tbody tr.quote-material-row .spec{line-height:1.08;padding:.55mm .8mm}.quote-table tfoot td{font-size:8.8pt;padding:1.2mm}.final-summary .box b{font-size:10pt}
@media(max-width:700px){.quote-add-bar{grid-template-columns:1fr}.quote-item-row{grid-template-columns:auto 1fr}.quote-item-actions{grid-column:2;justify-content:flex-start}.quote-drag-handle{grid-row:1 / span 2}}

/* Quote V2 Enterprise：增强筛选，不改变主界面结构 */
.filter-bar{display:grid;grid-template-columns:repeat(auto-fit,minmax(135px,1fr));gap:8px;align-items:end;margin-top:8px}
.filter-bar input,.filter-bar select{min-width:0}
.filter-line{display:flex;gap:8px;flex-wrap:wrap;align-items:center}
.filter-pill{font-size:12px;color:#475467;background:#f8fafc;border:1px solid #e5e7eb;border-radius:999px;padding:5px 9px}
.prod-card.selectable{cursor:pointer}
.prod-card.selectable:hover{border-color:#2563eb;box-shadow:0 0 0 2px rgba(37,99,235,.12)}
.result-count{font-size:12px;color:#667085;font-weight:700}
.history-group{margin:10px 0 6px;font-weight:800;color:#334155}

.history-view-toolbar{display:flex;gap:8px;align-items:center;justify-content:flex-end;margin:10px 0 0;flex-wrap:wrap}.history-view-toolbar .hint{margin-right:2px}.history-view-btn{background:#eef2f7;color:#111827;border:1px solid #d4dbe7;border-radius:9px;padding:7px 10px;font-weight:700}.history-view-btn.active{background:#2563eb;color:#fff;border-color:#2563eb}.history-list{max-height:none;overflow:visible}.history-page-size{width:auto;min-width:92px;padding:7px 9px}.history-pager{display:flex;gap:8px;align-items:center;justify-content:flex-end;flex-wrap:wrap;margin-top:14px}.history-pager button{background:#eef2f7;color:#111827;border:1px solid #d4dbe7;border-radius:9px;padding:7px 10px;font-weight:700}.history-pager button.active{background:#2563eb;color:#fff;border-color:#2563eb}.history-pager button:disabled{opacity:.45;cursor:not-allowed}.history-view-list{display:grid;gap:8px}.history-view-list .history-quote-card{border:1px solid var(--line);border-radius:11px;padding:10px;background:#fff;cursor:pointer}.history-view-list .history-quote-card:hover{border-color:#2563eb}.history-view-list .history-thumb{display:none}.history-view-list .history-card-title{font-weight:800}.history-view-list .history-meta{display:block;color:#667085;margin-top:4px;font-size:13px}.history-view-list .history-actions{display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-top:10px}.history-view-grid-large,.history-view-grid-medium,.history-view-grid-small{display:grid;gap:12px;align-items:start}.history-view-grid-large{grid-template-columns:repeat(auto-fill,minmax(260px,1fr))}.history-view-grid-medium{grid-template-columns:repeat(auto-fill,minmax(200px,1fr))}.history-view-grid-small{grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:10px}.history-view-grid-large .history-group,.history-view-grid-medium .history-group,.history-view-grid-small .history-group{grid-column:1/-1}.history-view-grid-large .history-quote-card,.history-view-grid-medium .history-quote-card,.history-view-grid-small .history-quote-card{border:1px solid var(--line);border-radius:14px;background:#fff;overflow:hidden;cursor:pointer;box-shadow:0 2px 8px #0000000a}.history-view-grid-large .history-quote-card:hover,.history-view-grid-medium .history-quote-card:hover,.history-view-grid-small .history-quote-card:hover{border-color:#2563eb;box-shadow:0 0 0 2px rgba(37,99,235,.10)}.history-thumb{background:#f8fafc;border-bottom:1px solid #e5e7eb;display:flex;align-items:center;justify-content:center;color:#94a3b8;font-weight:800;overflow:hidden}.history-thumb img{max-width:100%;max-height:100%;object-fit:contain}.history-view-grid-large .history-thumb{height:150px}.history-view-grid-medium .history-thumb{height:110px}.history-view-grid-small .history-thumb{height:78px}.history-card-body{padding:10px}.history-card-title{font-weight:900;color:#111827;line-height:1.25;overflow:hidden;text-overflow:ellipsis}.history-view-grid-large .history-card-title,.history-view-grid-medium .history-card-title{display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical}.history-view-grid-small .history-card-title{font-size:13px;white-space:nowrap}.history-meta{display:block;color:#667085;margin-top:5px;font-size:12px;line-height:1.45}.history-view-grid-small .history-meta{font-size:11px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.history-actions{display:flex;gap:7px;flex-wrap:wrap;align-items:center;margin-top:10px}.history-view-grid-small .history-actions button{padding:6px 8px;font-size:12px}.history-view-grid-small .history-actions .hide-small{display:none}.history-empty-thumb{width:100%;height:100%;display:flex;align-items:center;justify-content:center;background:linear-gradient(135deg,#f8fafc,#eef2ff);font-size:22px;color:#64748b}.history-view-grid-small .history-empty-thumb{font-size:16px}

/* Quote V5：报价配置列独立滚动，不影响报价单工作区与PDF预览 */
#page-quote .quote-page-split > .card.no-print:first-child{
  max-height:calc(100vh - 235px);
  display:flex;
  flex-direction:column;
}
#page-quote .quote-page-split > .card.no-print:first-child .card-head{
  flex:0 0 auto;
}
#page-quote .quote-page-split > .card.no-print:first-child .card-body{
  flex:1 1 auto;
  overflow-y:auto;
  overflow-x:hidden;
  padding-right:10px;
}
#page-quote .quote-page-split > .card.no-print:first-child .card-body::-webkit-scrollbar{width:8px}
#page-quote .quote-page-split > .card.no-print:first-child .card-body::-webkit-scrollbar-thumb{background:#cbd5e1;border-radius:999px}

/* V6.6.8 紧凑参数 + 备注扩展 */
.quote-work .card-body{padding:10px 12px!important}.quote-current-title{margin-bottom:6px}.q-compact-grid{display:grid;grid-template-columns:70px 86px 76px 76px;gap:7px;align-items:end}.q-compact-grid-2{display:grid;grid-template-columns:80px 116px 1fr;gap:7px;align-items:end}.q-mini-grid{display:grid;grid-template-columns:88px 76px 86px 86px 82px 72px;gap:7px;align-items:end}.q-remark-grid{display:grid;grid-template-columns:1fr 1fr;gap:7px;margin-top:4px}.q-compact-grid label,.q-compact-grid-2 label,.q-mini-grid label,.q-remark-grid label{font-size:11px;margin:5px 0 3px}.quote-work input,.quote-work select,.quote-work textarea{font-size:12px;padding:6px 8px;border-radius:8px}.quote-work textarea{min-height:42px}.q-small-input{text-align:center}.q-level-box{display:grid;grid-template-columns:64px 32px 32px;gap:5px;align-items:center}.q-level-box button{padding:5px 0;border-radius:7px;font-weight:900}.q-inline-note{font-size:11px;color:#667085;line-height:1.35;margin-top:5px}.quote-add-bar{display:grid;grid-template-columns:1fr 1fr 1fr;gap:8px;margin-top:8px}.quote-add-bar button{padding:9px 10px}.quote-items{margin-top:8px}@media(max-width:1500px){.q-compact-grid{grid-template-columns:repeat(4,1fr)}.q-compact-grid-2{grid-template-columns:1fr 1.3fr 1fr}.q-mini-grid{grid-template-columns:repeat(5,1fr)}}
@media(max-width:1200px){
  #page-quote .quote-page-split > .card.no-print:first-child{max-height:none;display:block}
  #page-quote .quote-page-split > .card.no-print:first-child .card-body{overflow:visible;padding-right:14px}
}

/* Quote V4.2 Professional PDF / Print Engine */
#paper .paper{position:relative;page-break-after:always;margin:0 auto 16px;height:297mm;min-height:297mm;padding-bottom:24mm;overflow:hidden;}
#paper .paper:last-child{page-break-after:auto;}
.page-mini-head{display:grid;grid-template-columns:1fr auto;align-items:start;border-bottom:1.25px solid #000;padding-bottom:3mm;margin-bottom:4mm;font-size:8.8pt;line-height:1.25}
.page-mini-head b{font-size:13pt;font-weight:400}
.page-footer{position:absolute;left:15mm;right:15mm;bottom:6mm;border-top:1px solid #999;padding-top:2mm;font-size:8.2pt;display:flex;justify-content:space-between;color:#333}
.final-summary{margin-top:4mm;font-size:9.5pt;display:grid;grid-template-columns:1fr 1fr;gap:8mm}
.final-summary .box{border:1.25px solid #000;padding:2.2mm;line-height:1.35}
.final-sign{margin-top:7mm;display:grid;grid-template-columns:1fr 1fr 1fr;gap:8mm;font-size:8.8pt;text-align:center}
.final-sign div{border-top:1.25px solid #000;padding-top:2mm}
@media print{#paper .paper{margin:0;box-shadow:none;border:0;page-break-after:always;break-after:page;width:210mm;height:297mm;min-height:297mm;overflow:hidden}#paper .paper:last-child{page-break-after:auto;break-after:auto}.quote-table thead{display:table-header-group}.quote-table tfoot{display:table-footer-group}.quote-table tr{page-break-inside:avoid;break-inside:avoid}.page-footer{display:flex!important}}


/* Quote V6.1.3：物料区每类最多显示5条，超过5条内部滚动 */
#page-quote .quote-page-split > .card.no-print:first-child{max-height:none!important;display:block!important}
#page-quote .quote-page-split > .card.no-print:first-child .card-body{overflow:visible!important;padding-right:14px!important}
.mat-list{overflow-y:auto!important;overscroll-behavior:contain}
.part .title{align-items:center}
.part-actions{display:flex;gap:8px;align-items:center;flex-wrap:nowrap}
.part-actions button{padding:7px 11px}
.part-modal-box{width:min(1250px,96vw)}
.part-modal-list{display:grid;gap:8px;margin-top:10px}
.part-modal-row{display:grid;grid-template-columns:1fr auto;gap:10px;align-items:center;border:1px solid #e5e7eb;border-radius:12px;background:#fff;padding:10px 12px;cursor:pointer}
.part-modal-row:hover{border-color:#2563eb;background:#eff6ff}
.part-modal-row b{font-size:14px}
.part-modal-row small{display:block;color:#667085;font-size:12px;margin-top:3px;line-height:1.35}
.part-modal-actions{display:flex;gap:8px;align-items:center;white-space:nowrap}


/* V6.3.5 保底方案：连续流式报价单。取消手工分页，交给浏览器/PDF打印引擎自然分页，避免压页脚、重叠和漏行。 */
.paper{height:auto!important;min-height:297mm!important;overflow:visible!important;padding-bottom:18mm!important}
.paper.flow-paper{height:auto!important;min-height:297mm!important;overflow:visible!important}
.flow-paper .quote-table{page-break-inside:auto;break-inside:auto}
.flow-paper .quote-table tr{page-break-inside:avoid;break-inside:avoid}
.flow-paper .page-footer{display:none!important}
.flow-paper .final-summary,.flow-paper .final-sign,.flow-paper .bank{page-break-inside:avoid;break-inside:avoid}
@media print{.paper,.paper.flow-paper{height:auto!important;min-height:auto!important;overflow:visible!important;page-break-after:auto!important;break-after:auto!important;border:0!important;padding:12mm 10mm 12mm!important}.flow-paper .page-footer{display:none!important}.quote-table thead{display:table-header-group}.quote-table tr{page-break-inside:avoid!important;break-inside:avoid!important}}

/* V6.3.6：连续表格 + 打印页续页抬头。总计不再用 tfoot，避免每页重复。 */
.quote-table thead .repeat-mini-head th{border:0!important;border-bottom:1.25px solid #000!important;text-align:left!important;padding:6mm 0 3mm 0!important;height:auto!important;font-weight:400!important}
.repeat-mini-wrap{display:flex;justify-content:space-between;gap:8mm;align-items:flex-start;font-size:8.8pt;line-height:1.18}
.repeat-mini-wrap b{font-size:11.5pt;font-weight:400}
.repeat-mini-wrap .right{text-align:right;white-space:nowrap}
.quote-total-row td{font-size:8.8pt!important;padding:1.2mm!important;font-weight:700}
@media screen{.quote-table thead .repeat-mini-head{display:none!important}}
@media print{.quote-table thead{display:table-header-group!important}.quote-table tfoot{display:none!important}.quote-table thead .repeat-mini-head{display:none!important}}

/* V6.4.2: first page already has full header; do not render mini header inside first page table. */
.repeat-mini-head{display:none!important}
@media print{.repeat-mini-head{display:none!important}}

/* V6.4.5: first page real A4 split, no mini header on first page; continuation pages show mini header. */
.paper-top{grid-template-columns:1fr 72mm!important;gap:10mm!important;align-items:start!important}
.paper-top>div:last-child{justify-self:end!important;width:72mm!important}
.paper.first-page{height:297mm!important;min-height:297mm!important;overflow:hidden!important;page-break-after:always!important;break-after:page!important;padding:20mm 15mm 17mm!important}
.paper.continue-page{height:auto!important;min-height:297mm!important;overflow:visible!important;page-break-before:always!important;break-before:page!important;padding:18mm 15mm 17mm!important}
.first-page .page-mini-head{display:none!important}
.continue-page .page-mini-head{display:flex!important;margin-top:0!important;margin-bottom:5mm!important;padding-bottom:3mm!important}
@media print{.paper.first-page{height:297mm!important;min-height:297mm!important;overflow:hidden!important;page-break-after:always!important;break-after:page!important}.paper.continue-page{height:auto!important;min-height:297mm!important;overflow:visible!important;page-break-before:always!important;break-before:page!important}.first-page .page-mini-head{display:none!important}.continue-page .page-mini-head{display:grid!important}}
/* V6.4.5: preview continuation mini header: keep date at far right, not after company name. */
.continue-page .page-mini-head{display:grid!important;grid-template-columns:1fr auto!important;align-items:start!important;justify-content:normal!important;width:100%!important;column-gap:8mm!important}
.continue-page .page-mini-head>div:last-child{text-align:right!important;white-space:nowrap!important;justify-self:end!important}
.log-row{border:1px solid var(--line);border-radius:12px;background:#fff;padding:10px;margin-bottom:8px}.log-row b{font-size:13px}.log-meta{display:flex;gap:8px;flex-wrap:wrap;color:#667085;font-size:12px;margin:4px 0}.log-row details{margin-top:6px}.log-row pre{white-space:pre-wrap;word-break:break-word;background:#f8fafc;border:1px solid #e5e7eb;border-radius:8px;padding:8px;max-height:260px;overflow:auto;font-size:12px}.log-level{display:inline-block;border-radius:999px;padding:2px 7px;font-size:11px;font-weight:700;border:1px solid #d4dbe7}.log-level.ERROR{background:#fef3f2;color:#b42318;border-color:#fecdca}.log-level.WARN{background:#fffaeb;color:#b54708;border-color:#fedf89}.log-level.INFO{background:#eff6ff;color:#1d4ed8;border-color:#bfdbfe}
/* V6.5.0 报价配置左右折叠：保存后向左收起，释放空间给报价单工作区 */
.quote-config-card .card-head{align-items:center}
.quote-config-head-actions{display:flex;align-items:center;gap:8px;margin-left:auto}
.quote-config-toggle{padding:6px 10px;font-size:12px;border-radius:8px}
.quote-config-card.is-collapsed .card-body{display:none!important}
.quote-config-card.is-collapsed .card-head{border-bottom:0}
.quote-config-card.is-collapsed{box-shadow:0 2px 12px #0000000d}
.quote-config-card.is-collapsed .quote-config-toggle{background:#2563eb;color:#fff;border-color:#2563eb}
.quote-page-split.config-side-collapsed .quote-config-card{min-height:calc(100vh - 130px);position:sticky;top:14px;align-self:start}
.quote-page-split.config-side-collapsed .quote-config-card .card-head{height:100%;min-height:260px;padding:10px 6px;display:flex;flex-direction:column;justify-content:flex-start;align-items:center;gap:10px}
.quote-page-split.config-side-collapsed .quote-config-card .card-head>b{writing-mode:vertical-rl;text-orientation:mixed;font-size:14px;letter-spacing:2px;line-height:1.1;white-space:nowrap}
.quote-page-split.config-side-collapsed .quote-config-head-actions{margin:0;display:flex;flex-direction:column;align-items:center;gap:8px}
.quote-page-split.config-side-collapsed .quote-config-head-actions .hint{display:none!important}
.quote-page-split.config-side-collapsed .quote-config-toggle{writing-mode:vertical-rl;padding:10px 6px;min-height:56px}

/* V6.6.8 紧凑参数 + 备注扩展 */
.quote-work .card-body{padding:10px 12px!important}.quote-current-title{margin-bottom:6px}.q-compact-grid{display:grid;grid-template-columns:70px 86px 76px 76px;gap:7px;align-items:end}.q-compact-grid-2{display:grid;grid-template-columns:80px 116px 1fr;gap:7px;align-items:end}.q-mini-grid{display:grid;grid-template-columns:88px 76px 86px 86px 82px 72px;gap:7px;align-items:end}.q-remark-grid{display:grid;grid-template-columns:1fr 1fr;gap:7px;margin-top:4px}.q-compact-grid label,.q-compact-grid-2 label,.q-mini-grid label,.q-remark-grid label{font-size:11px;margin:5px 0 3px}.quote-work input,.quote-work select,.quote-work textarea{font-size:12px;padding:6px 8px;border-radius:8px}.quote-work textarea{min-height:42px}.q-small-input{text-align:center}.q-level-box{display:grid;grid-template-columns:64px 32px 32px;gap:5px;align-items:center}.q-level-box button{padding:5px 0;border-radius:7px;font-weight:900}.q-inline-note{font-size:11px;color:#667085;line-height:1.35;margin-top:5px}.quote-add-bar{display:grid;grid-template-columns:1fr 1fr 1fr;gap:8px;margin-top:8px}.quote-add-bar button{padding:9px 10px}.quote-items{margin-top:8px}@media(max-width:1500px){.q-compact-grid{grid-template-columns:repeat(4,1fr)}.q-compact-grid-2{grid-template-columns:1fr 1.3fr 1fr}.q-mini-grid{grid-template-columns:repeat(5,1fr)}}
@media(max-width:1200px){.quote-page-split.config-side-collapsed .quote-config-card{position:static;min-height:0}.quote-page-split.config-side-collapsed .quote-config-card .card-head{min-height:0;height:auto;flex-direction:row;padding:13px 15px}.quote-page-split.config-side-collapsed .quote-config-card .card-head>b{writing-mode:horizontal-tb;letter-spacing:0}.quote-page-split.config-side-collapsed .quote-config-head-actions{flex-direction:row;margin-left:auto}.quote-page-split.config-side-collapsed .quote-config-toggle{writing-mode:horizontal-tb;padding:6px 10px;min-height:0}}

/* V6.5.4 历史报价图标视图美化：固定一排5个、卡片等高、按钮缩小并排整齐 */
.history-view-toolbar{align-items:center!important;gap:6px!important}
.history-view-btn{padding:6px 9px!important;font-size:12px!important;border-radius:8px!important}
.history-page-size{min-width:82px!important;padding:6px 8px!important;font-size:12px!important}
.history-view-grid-large,.history-view-grid-medium,.history-view-grid-small{
  display:grid!important;
  grid-template-columns:repeat(5,minmax(0,1fr))!important;
  gap:14px!important;
  align-items:stretch!important;
}
.history-view-grid-large .history-quote-card,.history-view-grid-medium .history-quote-card,.history-view-grid-small .history-quote-card{
  border:1px solid #d8dee9!important;
  border-radius:16px!important;
  overflow:hidden!important;
  background:#fff!important;
  box-shadow:0 2px 10px rgba(15,23,42,.05)!important;
  display:flex!important;
  flex-direction:column!important;
  min-height:310px!important;
}
.history-view-grid-large .history-quote-card:hover,.history-view-grid-medium .history-quote-card:hover,.history-view-grid-small .history-quote-card:hover{
  transform:translateY(-1px);
  border-color:#2563eb!important;
  box-shadow:0 8px 20px rgba(37,99,235,.10)!important;
}
.history-thumb{
  background:linear-gradient(90deg,#f8fafc 0,#fff 16%,#fff 84%,#f8fafc 100%)!important;
  height:138px!important;
  border-bottom:1px solid #e8edf5!important;
}
.history-thumb img{max-width:86%!important;max-height:116px!important;object-fit:contain!important}
.history-card-body{padding:10px 12px 12px!important;display:flex!important;flex-direction:column!important;flex:1 1 auto!important}
.history-card-title{font-size:15px!important;line-height:1.22!important;min-height:37px!important;max-height:38px!important;display:-webkit-box!important;-webkit-line-clamp:2!important;-webkit-box-orient:vertical!important;overflow:hidden!important;text-overflow:ellipsis!important}
.history-meta{font-size:12px!important;line-height:1.35!important;min-height:32px!important;max-height:34px!important;overflow:hidden!important;margin-top:6px!important;color:#667085!important}
.history-actions{
  display:grid!important;
  grid-template-columns:repeat(5,minmax(0,1fr))!important;
  gap:6px!important;
  margin-top:auto!important;
  align-items:center!important;
}
.history-actions button{
  padding:5px 0!important;
  min-width:0!important;
  width:100%!important;
  border-radius:8px!important;
  font-size:12px!important;
  line-height:1.1!important;
  white-space:nowrap!important;
  text-align:center!important;
}
.history-view-grid-small .history-actions .hide-small{display:block!important}
.history-view-grid-small .history-card-title{font-size:14px!important;white-space:normal!important}
.history-view-grid-small .history-meta{font-size:11.5px!important;white-space:normal!important}
.history-view-grid-small .history-thumb{height:118px!important}
.history-view-list .history-actions{display:flex!important;gap:8px!important;margin-top:10px!important}
.history-view-list .history-actions button{width:auto!important;padding:7px 12px!important;font-size:13px!important}
.history-view-list .history-quote-card{min-height:auto!important}
.history-view-list .history-card-body{display:block!important;padding:0!important}
@media(max-width:1680px){.history-view-grid-large,.history-view-grid-medium,.history-view-grid-small{grid-template-columns:repeat(4,minmax(0,1fr))!important}}
@media(max-width:1360px){.history-view-grid-large,.history-view-grid-medium,.history-view-grid-small{grid-template-columns:repeat(3,minmax(0,1fr))!important}}
@media(max-width:960px){.history-view-grid-large,.history-view-grid-medium,.history-view-grid-small{grid-template-columns:repeat(2,minmax(0,1fr))!important}}
@media(max-width:640px){.history-view-grid-large,.history-view-grid-medium,.history-view-grid-small{grid-template-columns:1fr!important}.history-actions{grid-template-columns:repeat(5,minmax(0,1fr))!important}}



/* Quotation V6.6.3：命名中心产品卡片美化，避免大红字和拥挤排版 */
.product-gallery{grid-template-columns:repeat(auto-fill,minmax(230px,1fr));gap:14px;align-items:start}
.prod-card{border:1px solid #dde5f0;border-radius:18px;background:linear-gradient(180deg,#fff,#fbfcff);box-shadow:0 6px 18px rgba(15,23,42,.06);overflow:hidden;transition:.16s ease;min-height:270px}
.prod-card:hover{transform:translateY(-2px);border-color:#93c5fd;box-shadow:0 12px 26px rgba(37,99,235,.12)}
.prod-card .img{height:132px;background:linear-gradient(135deg,#f8fafc,#f1f5f9);border-bottom:1px solid #eef2f7;position:relative}
.prod-card .img img{max-width:82%;max-height:108px;object-fit:contain;filter:drop-shadow(0 8px 14px rgba(15,23,42,.10))}
.prod-card .img span{font-size:18px;color:#94a3b8;font-weight:800;letter-spacing:.05em}
.prod-card div.body{padding:12px 14px 13px;display:flex;flex-direction:column;gap:7px;min-height:132px}
.prod-card .source-line{display:flex;gap:6px;align-items:center;flex-wrap:wrap;min-height:22px}
.prod-card .source-tag{font-size:11px;line-height:18px;padding:1px 7px;margin:0;border-radius:999px;font-weight:800}
.prod-card .prod-title{font-size:17px;line-height:1.25;font-weight:900;color:#0f172a;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;word-break:break-word}
.prod-card .prod-meta{font-size:12px;color:#64748b;line-height:1.35;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}
.prod-card .cost-line{margin-top:auto;display:flex;align-items:center;justify-content:space-between;gap:8px;border-top:1px dashed #e2e8f0;padding-top:8px}
.prod-card .cost-line .cost-ok{color:#065f46;background:#ecfdf3;border:1px solid #a7f3d0;border-radius:10px;padding:4px 8px;font-size:12px;font-weight:900;white-space:nowrap}
.prod-card .cost-line .cost-missing{color:#b42318;background:#fff1f2;border:1px solid #fecdd3;border-radius:10px;padding:4px 8px;font-size:12px;font-weight:900;white-space:nowrap}
.prod-card .cost-line .cost-time{color:#94a3b8;font-size:11px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
@media(max-width:1500px){.product-gallery{grid-template-columns:repeat(auto-fill,minmax(210px,1fr))}}
@media(max-width:900px){.product-gallery{grid-template-columns:repeat(auto-fill,minmax(170px,1fr))}.prod-card{min-height:245px}.prod-card .img{height:110px}.prod-card .prod-title{font-size:15px}}



/* V6.7.0 报价单预览缩放工具栏：只影响网页预览，不影响 PDF / Excel / 打印 */

/* V6.8.4.4 报价单工作区快捷设置 */
.quote-doc-quick{margin-top:14px;border:1px solid #dbe5f3;border-radius:14px;background:#f8fbff;overflow:hidden}
.quote-doc-quick-h{display:flex;align-items:center;justify-content:space-between;gap:10px;padding:10px 12px;border-bottom:1px solid #dbe5f3;background:#eef6ff}
.quote-doc-quick-h b{font-size:15px;color:#0f172a}.quote-doc-quick-b{padding:10px 12px}.qdoc-tabs{display:flex;gap:6px;flex-wrap:wrap;margin-bottom:9px}.qdoc-tabs button{padding:6px 10px;border-radius:999px;background:#fff;color:#1e293b;border:1px solid #cbd5e1;font-weight:900}.qdoc-tabs button.active{background:#2563eb;color:#fff;border-color:#2563eb}.qdoc-pane{display:none}.qdoc-pane.active{display:block}.qdoc-select-grid{display:grid;grid-template-columns:1fr 1fr 1fr;gap:8px;margin-bottom:9px}.qdoc-form-grid{display:grid;grid-template-columns:1fr 1fr;gap:8px}.qdoc-form-grid label,.qdoc-select-grid label{font-size:11px;margin:4px 0 3px}.qdoc-wide textarea#qbExtraTerms{min-height:130px!important;height:130px!important;background:#fff}.qdoc-preview.terms-box{border:1px dashed #cbd5e1;background:#fff;border-radius:10px;padding:8px;min-height:60px}.quote-doc-quick input,.quote-doc-quick select,.quote-doc-quick textarea{font-size:12px!important;padding:6px 8px!important;border-radius:8px!important}.quote-doc-quick textarea{min-height:72px!important;height:72px!important;resize:vertical}.qdoc-wide{grid-column:1/-1}.qdoc-actions{display:flex;gap:7px;flex-wrap:wrap;margin-top:9px}.qdoc-actions button{padding:7px 10px}.qdoc-mini-note{font-size:11px;color:#667085;line-height:1.45;margin-top:6px}.qdoc-preview{border:1px dashed #cbd5e1;background:#fff;border-radius:10px;padding:8px;font-size:11px;color:#475467;line-height:1.45;white-space:pre-line;max-height:118px;overflow:auto}@media(max-width:1500px){.qdoc-select-grid{grid-template-columns:1fr}.qdoc-form-grid{grid-template-columns:1fr}}

.preview-card{min-width:0;}
.preview-card .preview-head{align-items:center;padding:9px 12px;background:#fbfdff;position:sticky;top:0;z-index:12;}
.preview-title{display:flex;align-items:center;gap:8px;font-size:13px;font-weight:900;color:#111827;white-space:nowrap;}
.preview-toolbar{display:flex;align-items:center;gap:6px;flex-wrap:wrap;justify-content:flex-end;}
.preview-toolbar button{height:28px;min-width:32px;padding:0 9px;border-radius:8px;font-size:12px;font-weight:900;line-height:1;}
.preview-toolbar .zoom-label{height:28px;min-width:54px;display:inline-flex;align-items:center;justify-content:center;border:1px solid #d4dbe7;border-radius:8px;background:#fff;color:#334155;font-size:12px;font-weight:1000;font-variant-numeric:tabular-nums;}
.preview-toolbar .fit-btn{min-width:54px;}
.preview{background:#f8fafc;}
#quotePreviewBody #paper{zoom:var(--quote-preview-scale,1);transition:zoom .12s ease;}
@supports not (zoom:1){
  #quotePreviewBody #paper{transform:scale(var(--quote-preview-scale,1));transform-origin:top center;transition:transform .12s ease;}
}
@media(max-width:1200px){.preview-card .preview-head{position:static}.preview-toolbar{justify-content:flex-start}}
@media print{.preview-card .preview-head{display:none!important}#quotePreviewBody #paper{zoom:1!important;transform:none!important}}


/* V6.7.3：登录、权限页、顶部导航 */
body.need-login .top,body.need-login .system-nav,body.need-login .quote-func-nav,body.need-login .dash,body.need-login .shell{filter:blur(2px);pointer-events:none;user-select:none}.login-mask{position:fixed;inset:0;z-index:9999;background:linear-gradient(135deg,rgba(15,23,42,.78),rgba(37,99,235,.34));display:none;align-items:center;justify-content:center;padding:18px;backdrop-filter:blur(8px)}body.need-login .login-mask{display:flex}.login-box{width:min(430px,96vw);background:#fff;border:1px solid #dbeafe;border-radius:24px;box-shadow:0 30px 88px rgba(15,23,42,.34);padding:24px}.login-logo{width:44px;height:44px;border-radius:15px;background:#111827;color:#fff;display:grid;place-items:center;font-weight:1000;margin-bottom:10px}.login-box h2{margin:0;font-size:21px}.login-box p{margin:6px 0 12px;color:#64748b;font-size:13px;font-weight:800;line-height:1.55}.login-error{min-height:20px;color:#dc2626;font-weight:900;font-size:13px}.top-user{display:inline-flex;align-items:center;gap:7px;border:1px solid rgba(255,255,255,.22);background:rgba(255,255,255,.10);border-radius:999px;padding:6px 10px;color:#fff;font-size:12px;font-weight:900}.system-nav{position:sticky;top:0;z-index:65;display:flex;gap:7px;align-items:center;overflow:auto;padding:8px 16px;background:#fff;border-bottom:1px solid var(--line);box-shadow:0 4px 14px rgba(15,23,42,.04)}.system-nav a{flex:0 0 auto;border:1px solid #dbe3ef;background:#f8fafc;color:#334155;border-radius:999px;padding:6px 10px;font-size:12px;font-weight:950}.system-nav a.active{background:#111827;color:#fff;border-color:#111827}.quote-func-nav{position:sticky;top:45px;z-index:60;display:flex;gap:7px;align-items:center;overflow:auto;padding:8px 16px;background:#f8fafc;border-bottom:1px solid var(--line)}.quote-func-nav button{height:30px;border-radius:999px;background:#fff;color:#334155;border:1px solid #dbe3ef;font-size:12px;font-weight:950;padding:0 12px;white-space:nowrap}.quote-func-nav button.active{background:#2563eb;color:#fff;border-color:#2563eb}.quote-func-nav button.no-perm{display:none}.side{display:none!important}.shell{display:block!important}.main{padding:14px 16px}.perm-matrix{overflow:auto;border:1px solid var(--line);border-radius:14px;background:#fff}.perm-table{width:100%;min-width:1180px;border-collapse:collapse}.perm-table th,.perm-table td{border-bottom:1px solid var(--line);border-right:1px solid var(--line);padding:8px;text-align:center;font-size:12px}.perm-table th{position:sticky;top:0;background:#f8fafc;z-index:2;color:#334155}.perm-table td:first-child,.perm-table th:first-child{text-align:left;min-width:180px}.perm-user-main{font-weight:1000;color:#111827}.perm-user-sub{font-size:11px;color:#64748b;margin-top:3px}.perm-table input[type=checkbox]{width:16px;height:16px;accent-color:#2563eb}.perm-row-admin{background:#f0fdf4}.perm-row-admin td:first-child{box-shadow:inset 3px 0 0 #16a34a}.perm-toolbar{display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-bottom:10px}.perm-toolbar input,.perm-toolbar select{width:auto;min-width:160px}.perm-save-btn{padding:6px 10px!important;font-size:12px!important}.perm-reset-btn{padding:6px 10px!important;font-size:12px!important}.perm-delete-btn{padding:6px 10px!important;font-size:12px!important;background:#fee2e2!important;color:#991b1b!important;border:1px solid #fecaca!important}.perm-help{border:1px dashed #bfdbfe;background:#eff6ff;color:#1e3a8a;border-radius:12px;padding:10px;margin-bottom:10px;font-size:13px;line-height:1.55}.is-disabled-by-perm{display:none!important}@media(max-width:900px){.top{flex-wrap:wrap}.clock{margin-left:0}.system-nav,.quote-func-nav{top:0}.perm-toolbar input,.perm-toolbar select{width:100%;min-width:0}.main{padding:10px}}


/* V6.8.0 订单中心基础版 */
.order-grid{display:grid;grid-template-columns:minmax(520px,1.05fr) minmax(520px,1fr);gap:14px;align-items:start}.order-toolbar{display:grid;grid-template-columns:220px 150px 150px 1fr auto;gap:8px;align-items:center;margin-bottom:12px}.order-list{display:grid;gap:9px;max-height:calc(100vh - 310px);overflow:auto}.order-card{border:1px solid var(--line);border-radius:14px;background:#fff;padding:11px;display:grid;grid-template-columns:1fr auto;gap:10px;cursor:pointer}.order-card:hover{border-color:#2563eb;background:#fbfdff}.order-card.active{border-color:#2563eb;box-shadow:0 0 0 3px #dbeafe}.order-card b{font-size:15px}.order-card small{display:block;color:#667085;line-height:1.55;margin-top:4px}.order-status{display:inline-flex;align-items:center;height:24px;border-radius:999px;background:#eef2ff;color:#3730a3;border:1px solid #c7d2fe;padding:0 8px;font-size:12px;font-weight:900}.order-status.done{background:#ecfdf3;color:#027a48;border-color:#a6f4c5}.order-status.voided{background:#fef2f2;color:#991b1b;border-color:#fecaca}.order-status.warn{background:#fff7ed;color:#c2410c;border-color:#fed7aa}.order-detail-empty{height:220px;border:1px dashed #cbd5e1;border-radius:14px;display:grid;place-items:center;color:#667085;background:#f8fafc;font-weight:900}.order-kv{display:grid;grid-template-columns:repeat(4,1fr);gap:8px;margin-bottom:12px}.order-kv div{border:1px solid var(--line);border-radius:12px;background:#fbfdff;padding:9px}.order-kv b{display:block;font-size:11px;color:#667085;margin-bottom:3px}.order-kv span{font-size:14px;font-weight:900}.order-detail-actions{display:flex;gap:8px;flex-wrap:wrap;margin:10px 0}.order-note{padding:10px;border:1px dashed #cbd5e1;border-radius:12px;background:#f8fafc;color:#475467;line-height:1.55}.order-item-img{width:52px;height:42px;object-fit:contain;background:#f8fafc;border:1px solid #e5e7eb;border-radius:8px}@media(max-width:1200px){.order-grid{grid-template-columns:1fr}.order-toolbar{grid-template-columns:1fr 1fr}.order-kv{grid-template-columns:1fr 1fr}}

/* V6.8.1 包装资料库 + 出货批次基础版 */
.packaging-layout{display:grid;grid-template-columns:minmax(420px,.9fr) minmax(520px,1.1fr);gap:14px;align-items:start}.packaging-search{display:grid;grid-template-columns:1fr auto;gap:8px;margin-bottom:10px}.pack-profile-card{border:1px solid var(--line);border-radius:13px;background:#fff;padding:10px;cursor:pointer}.pack-profile-card:hover{border-color:#2563eb;background:#fbfdff}.pack-profile-card b{font-size:14px}.pack-profile-card small{display:block;color:#667085;line-height:1.5;margin-top:3px}.pack-calc-hint{border:1px dashed #bfdbfe;background:#eff6ff;color:#1e3a8a;border-radius:12px;padding:10px;line-height:1.55;font-size:13px;margin-bottom:10px}.ship-list{display:grid;gap:8px;margin-top:12px}.ship-card{border:1px solid #dbe3ef;border-radius:13px;background:#fbfdff;padding:10px}.ship-card b{font-size:14px}.ship-card small{display:block;color:#667085;line-height:1.5;margin-top:3px}.ship-totals{display:flex;gap:8px;flex-wrap:wrap;margin-top:8px}.ship-totals span{border:1px solid #dbe3ef;border-radius:999px;background:#fff;padding:3px 8px;font-size:12px;font-weight:900;color:#334155}.ship-modal-table{width:100%;min-width:1180px;border-collapse:collapse}.ship-modal-table th,.ship-modal-table td{border:1px solid var(--line);padding:6px;font-size:12px;text-align:left;vertical-align:middle}.ship-modal-table th{background:#f8fafc;color:#334155}.ship-modal-table input{padding:5px 6px;font-size:12px;border-radius:7px}.ship-modal-scroll{overflow:auto;border:1px solid var(--line);border-radius:12px;margin-top:8px}.ship-top-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:8px}.carton-grid{display:grid;grid-template-columns:110px 120px 1fr 90px 120px 80px 80px 80px 140px 36px;gap:6px;align-items:end;border:1px solid var(--line);border-radius:12px;padding:8px;margin-top:8px;background:#fbfdff}.carton-grid label{font-size:10px;margin:0 0 3px}.carton-grid input{font-size:12px;padding:6px}.ship-summary-box{margin-top:10px;border:1px dashed #cbd5e1;background:#f8fafc;border-radius:12px;padding:10px;font-weight:900;color:#334155}.order-subtitle{font-size:14px;font-weight:1000;margin:16px 0 8px}.order-table-wrap{overflow:auto}.shipment-detail-box{border:1px solid var(--line);border-radius:14px;background:#fff;margin-top:10px;padding:10px}.shipment-detail-box h4{margin:0 0 8px}.order-action-row{display:flex;gap:8px;flex-wrap:wrap;margin:10px 0}.doc-list{display:grid;gap:9px}.doc-card{border:1px solid var(--line);border-radius:13px;background:#fff;padding:10px}.doc-card b{font-size:14px}.doc-card small{display:block;color:#667085;line-height:1.5;margin-top:3px}.doc-tags{display:flex;gap:6px;flex-wrap:wrap;margin:8px 0}.doc-tags span{border:1px solid #dbe3ef;background:#f8fafc;border-radius:999px;padding:3px 8px;font-size:12px;font-weight:900;color:#334155}.doc-modal-box{width:min(1180px,96vw)!important;height:90vh;display:flex;flex-direction:column}.doc-frame{width:100%;height:100%;border:1px solid var(--line);border-radius:12px;background:#fff}.doc-modal-body{flex:1;min-height:0;padding:10px!important}.doc-modal-actions{display:flex;gap:8px;flex-wrap:wrap;align-items:center}.doc-setting-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:10px}.doc-checks{display:grid;grid-template-columns:repeat(2,1fr);gap:6px;margin-top:6px}.doc-checks label{display:flex;align-items:center;gap:7px;margin:0;font-size:12px;color:#334155}.doc-checks input{width:auto}.doc-template-box{border:1px dashed #bfdbfe;background:#eff6ff;border-radius:12px;padding:10px;margin-top:10px}.doc-template-box b{color:#1e3a8a}
.payment-panel{border:1px solid #dbe3ef;border-radius:14px;background:#fbfdff;padding:10px;margin:12px 0}.payment-panel-head{display:flex;align-items:center;justify-content:space-between;gap:8px;flex-wrap:wrap}.payment-mini-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:8px;margin-top:9px}.payment-mini-grid div{border:1px solid #dbe3ef;background:#fff;border-radius:12px;padding:8px}.payment-mini-grid b{display:block;font-size:11px;color:#64748b}.payment-mini-grid span{display:block;font-size:16px;font-weight:1000;margin-top:4px}.payment-status-paid{color:#059669}.payment-status-warn{color:#ea580c}.payment-status-danger{color:#dc2626}.payment-list{margin-top:10px}.payment-list table{width:100%;border-collapse:collapse}.payment-list th,.payment-list td{border:1px solid var(--line);padding:6px;font-size:12px}.payment-list th{background:#f8fafc}.payment-alert{border:1px dashed #fb923c;background:#fff7ed;color:#9a3412;border-radius:12px;padding:8px 10px;font-size:12px;font-weight:900;margin:8px 0}.finance-strip{display:flex;gap:8px;flex-wrap:wrap;margin:0 0 10px}.finance-strip span{border:1px solid #dbe3ef;background:#fff;border-radius:999px;padding:5px 10px;font-size:12px;font-weight:900;color:#334155}.finance-strip b{color:#111827}
@media(max-width:1200px){.packaging-layout{grid-template-columns:1fr}.ship-top-grid{grid-template-columns:1fr 1fr}.carton-grid{grid-template-columns:1fr 1fr}.carton-grid .carton-wide{grid-column:1/-1}}


/* V6.8.4.2 系统设置 PRO */
.settings-pro{display:grid;gap:14px}.settings-hero{background:linear-gradient(135deg,#eef6ff,#fff);border:1px solid #cfe3ff;border-radius:18px;padding:16px 18px;box-shadow:0 8px 24px #0f172a0d}.settings-hero h2{margin:0 0 6px;font-size:22px}.settings-hero p{margin:0;color:#64748b;font-weight:800;line-height:1.55}.settings-tabs{position:sticky;top:49px;z-index:20;background:rgba(244,246,250,.92);backdrop-filter:blur(10px);display:flex;gap:8px;flex-wrap:wrap;padding:10px 0}.settings-tabs button{background:#fff;color:#111827;border:1px solid var(--line);font-weight:900}.settings-tabs button.active{background:var(--blue);color:#fff;border-color:var(--blue)}.settings-panel{display:none}.settings-panel.active{display:block}.settings-grid{display:grid;grid-template-columns:minmax(360px,520px) 1fr;gap:14px}.settings-grid-wide{display:grid;grid-template-columns:1fr;gap:14px}.settings-card{background:#fff;border:1px solid var(--line);border-radius:16px;box-shadow:0 2px 14px #0001;overflow:hidden}.settings-card-h{padding:13px 15px;border-bottom:1px solid var(--line);display:flex;align-items:center;justify-content:space-between;gap:10px}.settings-card-h b{font-size:16px}.settings-card-b{padding:14px}.settings-actions{display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-top:12px}.settings-actions button{font-weight:900}.settings-list{display:grid;gap:8px;max-height:560px;overflow:auto;padding-right:4px}.settings-row{border:1px solid #e2e8f0;border-radius:13px;background:#fff;padding:10px 12px;display:grid;grid-template-columns:1fr auto;gap:10px;align-items:center}.settings-row:hover{border-color:#93c5fd;background:#f8fbff}.settings-row-main b{font-size:14px}.settings-row-main small{display:block;margin-top:4px;color:#64748b;line-height:1.5}.settings-row-actions{display:flex;gap:6px;align-items:center;white-space:nowrap}.settings-row-actions button{font-size:12px;padding:6px 9px;border-radius:999px}.settings-empty{padding:18px;border:1px dashed #cbd5e1;border-radius:14px;color:#64748b;background:#f8fafc}.template-preview-box{border:1px dashed #cbd5e1;border-radius:12px;padding:10px;background:#f8fafc;margin-top:10px;color:#475569;line-height:1.55;font-size:12px}.settings-quick{display:grid;grid-template-columns:repeat(3,minmax(220px,1fr));gap:12px}.settings-quick .settings-card-b{min-height:118px}.default-badge{display:inline-flex;align-items:center;border-radius:999px;padding:2px 7px;background:#ecfdf3;border:1px solid #86efac;color:#047857;font-size:11px;font-weight:900;margin-left:6px}.danger-note{background:#fff7ed;border:1px solid #fdba74;color:#9a3412;padding:9px 11px;border-radius:12px;font-size:12px;font-weight:800;line-height:1.5}.settings-form-title{font-weight:1000;margin:0 0 8px;color:#111827}.settings-inline-hint{font-size:12px;color:#64748b;font-weight:800;margin-top:8px}.settings-two{display:grid;grid-template-columns:1fr 1fr;gap:10px}.settings-three{display:grid;grid-template-columns:repeat(3,1fr);gap:10px}@media(max-width:980px){.settings-grid{grid-template-columns:1fr}.settings-quick{grid-template-columns:1fr}.settings-tabs{top:0}.settings-two,.settings-three{grid-template-columns:1fr}}


/* V6.8.4.7：报价配置全产品模糊查找。搜索不再被当前产品分类限制。 */
.product-fuzzy-shell{position:relative;min-width:0}
.product-fuzzy-input{height:38px!important;font-size:14px!important;padding:8px 38px 8px 11px!important;background:#fff!important}
.product-fuzzy-input.has-selection{font-weight:900;color:#0f172a;border-color:#93c5fd;background:#f8fbff!important}
.product-fuzzy-results{position:absolute;left:0;right:0;top:calc(100% + 6px);z-index:260;display:none;max-height:380px;overflow:auto;background:#fff;border:1px solid #cbd5e1;border-radius:13px;box-shadow:0 18px 50px rgba(15,23,42,.18);padding:6px}
.product-fuzzy-results.show{display:block}
.product-fuzzy-row{width:100%;display:grid;grid-template-columns:minmax(0,1fr) auto;gap:10px;align-items:center;text-align:left;border:1px solid transparent;background:#fff;color:#111827;border-radius:10px;padding:9px 10px;margin:0 0 4px;cursor:pointer}
.product-fuzzy-row:last-child{margin-bottom:0}
.product-fuzzy-row:hover,.product-fuzzy-row.active{background:#eff6ff;border-color:#93c5fd}
.product-fuzzy-main{min-width:0}
.product-fuzzy-title{display:block;font-size:13px;font-weight:950;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.product-fuzzy-meta{display:block;margin-top:3px;color:#64748b;font-size:11px;line-height:1.35;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.product-fuzzy-cost{font-size:11px;font-weight:900;color:#065f46;background:#ecfdf3;border:1px solid #a7f3d0;border-radius:999px;padding:3px 7px;white-space:nowrap}
.product-fuzzy-cost.missing{color:#9a3412;background:#fff7ed;border-color:#fed7aa}
.product-fuzzy-empty{padding:16px 12px;text-align:center;color:#94a3b8;font-size:12px;font-weight:900}
.product-fuzzy-count{padding:4px 8px 7px;color:#64748b;font-size:11px;font-weight:800}
@media(max-width:700px){.product-fuzzy-results{position:fixed;left:12px;right:12px;top:120px;max-height:65vh}.product-fuzzy-row{grid-template-columns:1fr}.product-fuzzy-cost{justify-self:start}}

/* V6.8.4.14 PI转订单编辑 + 合同条款大备注 */
.qdoc-wide textarea#qbExtraTerms{min-height:230px!important;height:230px!important;font-size:13px!important;line-height:1.55!important;}
#bExtraTerms{min-height:260px!important;height:260px!important;font-size:13px!important;line-height:1.55!important;}
.contract-terms-hint{border:1px solid #bfdbfe;background:#eff6ff;color:#1e3a8a;border-radius:12px;padding:9px 10px;margin:8px 0;font-size:12px;font-weight:850;line-height:1.6}
.pi-order-modal .modal-box{width:min(1320px,98vw)!important;}
.pi-order-grid{display:grid;grid-template-columns:1fr 1fr 1fr 1fr;gap:10px;margin-bottom:12px}
.pi-order-table-wrap{max-height:50vh;overflow:auto;border:1px solid #e5e7eb;border-radius:12px;background:#fff}
.pi-order-table{width:100%;border-collapse:collapse;min-width:1120px}
.pi-order-table th,.pi-order-table td{border-bottom:1px solid #edf2f7;padding:7px;text-align:left;vertical-align:top;font-size:12px}
.pi-order-table th{position:sticky;top:0;background:#f8fafc;z-index:2;color:#475467;font-weight:1000}
.pi-order-table input,.pi-order-table textarea{font-size:12px;padding:5px 6px;border-radius:7px}
.pi-order-table textarea{min-height:42px;resize:vertical}
.pi-order-table .qty{width:86px}.pi-order-table .price{width:98px}.pi-order-table .amount{width:110px;font-weight:1000;color:#111827;background:#f8fafc}
.pi-order-summary{display:flex;justify-content:space-between;gap:12px;align-items:center;flex-wrap:wrap;border:1px solid #bfdbfe;background:#eff6ff;color:#1e3a8a;border-radius:12px;padding:10px 12px;margin:10px 0;font-size:13px;font-weight:900}
@media(max-width:900px){.pi-order-grid{grid-template-columns:1fr 1fr}.pi-order-table{min-width:980px}}

/* V6.8.4.23：CRM有效客户过滤 + 报价客户模糊查找显示200 */
.customer-sync-panel{border:1px solid #bfdbfe;background:#eff6ff;border-radius:12px;padding:9px 10px;margin:10px 0;color:#1e3a8a;font-size:12px;font-weight:850;line-height:1.55}
.customer-batch-tools{display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin:10px 0}
.customer-batch-tools .hint{font-weight:800}
.customer-row{position:relative}
.customer-row.batch-on{display:grid;grid-template-columns:28px minmax(0,1fr);gap:8px;align-items:start}
.customer-row-check{width:18px;height:18px;margin-top:3px;accent-color:#111827}
.customer-row-check:disabled{opacity:.35}
.customer-live-note{display:inline-flex;border-radius:999px;padding:2px 7px;font-size:10px;font-weight:1000;border:1px solid #bbf7d0;background:#ecfdf5;color:#047857;margin-left:6px}
.customer-stale-note{display:inline-flex;border-radius:999px;padding:2px 7px;font-size:10px;font-weight:1000;border:1px solid #fecaca;background:#fef2f2;color:#991b1b;margin-left:6px}

/* V6.8.4.23：报价单客户选择模糊查找，最多显示200条 */
.customer-fuzzy-shell{position:relative;width:100%;z-index:35}
.customer-fuzzy-input{width:100%;border:1px solid #cfd6e3;border-radius:9px;padding:8px 9px;font-size:13px;background:#fff;font-weight:850}
.customer-fuzzy-input:focus{border-color:#93c5fd;box-shadow:0 0 0 3px #dbeafe;outline:none}
.customer-fuzzy-results{position:absolute;left:0;right:0;top:calc(100% + 4px);background:#fff;border:1px solid #dbe3ef;border-radius:12px;box-shadow:0 18px 50px rgba(15,23,42,.18);padding:7px;max-height:360px;overflow:auto;display:none;z-index:120}
.customer-fuzzy-results.show{display:block}
.customer-fuzzy-row{width:100%;display:grid;grid-template-columns:minmax(0,1fr) auto;gap:10px;align-items:center;text-align:left;border:1px solid transparent;background:#fff;color:#111827;border-radius:10px;padding:9px 10px;margin:0 0 4px;cursor:pointer}
.customer-fuzzy-row:last-child{margin-bottom:0}
.customer-fuzzy-row:hover,.customer-fuzzy-row.active{background:#eff6ff;border-color:#93c5fd}
.customer-fuzzy-title{display:block;font-size:13px;font-weight:1000;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.customer-fuzzy-meta{display:block;margin-top:3px;color:#64748b;font-size:11px;line-height:1.35;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.customer-fuzzy-tag{font-size:11px;font-weight:1000;border-radius:999px;padding:3px 8px;white-space:nowrap;border:1px solid #bfdbfe;background:#eff6ff;color:#1d4ed8}
.customer-fuzzy-tag.crm{background:#ecfdf5;border-color:#bbf7d0;color:#047857}
.customer-fuzzy-empty{padding:16px 12px;text-align:center;color:#94a3b8;font-size:12px;font-weight:900}
.customer-fuzzy-count{padding:4px 8px 7px;color:#64748b;font-size:11px;font-weight:800}
.customer-search-hint{margin-top:5px;font-size:11px;color:#64748b;font-weight:850;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
@media(max-width:700px){.customer-fuzzy-results{position:fixed;left:12px;right:12px;top:110px;max-height:65vh}.customer-fuzzy-row{grid-template-columns:1fr}.customer-fuzzy-tag{justify-self:start}}




/* V6.8.4.25：附件进入明细 + 光学只显示品牌与系列 */
/* V6.8.4.27：客户库完整同步CRM邮箱/地址/联系人 */
/* V6.8.4.29：未选客户时 TO 区固定显示 Please select customer；新报价/清空搜索不再沿用上一个客户。 */
/* V6.8.4.30：删除固定 Remark；配件区选择后自动跳到下一个选项卡。 */
/* V6.8.4.35：报价审核权限并入统一权限中心；未授权账号不能进入审核列表或通过/驳回。 */
.parts-tabs-shell{border:1px solid var(--line);border-radius:14px;background:#fff;overflow:hidden;box-shadow:0 2px 10px #00000008;margin-top:10px}
.parts-tabs-head{display:flex;align-items:center;justify-content:space-between;gap:8px;padding:9px 10px;background:#f8fafc;border-bottom:1px solid var(--line)}
.parts-tabs-title{font-weight:1000;color:#111827;font-size:13px}
.parts-tabs-summary{font-size:11px;color:#667085;font-weight:800;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:220px}
.parts-tab-buttons{display:flex;gap:6px;flex-wrap:wrap;padding:9px 10px;border-bottom:1px solid #eef1f5;background:#fff}
.parts-tab-btn{height:30px;border-radius:999px;border:1px solid #d4dbe7;background:#fff;color:#334155;padding:0 10px;font-size:12px;font-weight:1000;display:inline-flex;align-items:center;gap:5px}
.parts-tab-btn.active{background:#111827;color:#fff;border-color:#111827}
.parts-tab-dot{width:7px;height:7px;border-radius:999px;background:#cbd5e1;display:inline-block}.parts-tab-btn.active .parts-tab-dot{background:#22c55e}.parts-tab-btn.has-part:not(.active){border-color:#86efac;background:#f0fdf4;color:#166534}.parts-tab-btn.no-part:not(.active){border-color:#e5e7eb;background:#f8fafc;color:#64748b}
.parts-tab-panel{padding:10px;background:#fbfdff}.parts-tab-panel .part{margin:0;background:#fff!important}.parts-chosen-strip{display:flex;gap:6px;flex-wrap:wrap;padding:8px 10px;background:#fff;border-top:1px solid #eef1f5}.parts-chip{display:inline-flex;align-items:center;gap:5px;border:1px solid #dbeafe;background:#eff6ff;color:#1e40af;border-radius:999px;padding:3px 8px;font-size:11px;font-weight:900;max-width:100%;white-space:nowrap}.parts-chip.none{background:#f8fafc;border-color:#e5e7eb;color:#64748b}.parts-chip b{max-width:145px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.quote-item-actions .preview-btn{background:#eff6ff!important;color:#1d4ed8!important;border:1px solid #bfdbfe!important}.quote-item-actions .preview-btn:hover{background:#dbeafe!important}
.price-preview-modal .modal-box{width:min(980px,96vw)}.price-preview-head{display:flex;align-items:center;justify-content:space-between;gap:10px;margin-bottom:10px}.price-preview-head h3{margin:0;font-size:20px}.price-preview-grid{display:grid;grid-template-columns:1.05fr .95fr;gap:12px}.price-card{border:1px solid var(--line);border-radius:14px;background:#fff;overflow:hidden}.price-card-h{padding:9px 11px;border-bottom:1px solid var(--line);background:#f8fafc;font-weight:1000}.price-card-b{padding:10px}.price-line{display:grid;grid-template-columns:1fr auto;gap:8px;border-bottom:1px dashed #e5e7eb;padding:7px 0;font-size:13px}.price-line:last-child{border-bottom:0}.price-line small{display:block;color:#667085;font-size:11px;font-weight:800;margin-top:2px}.price-line b{font-size:13px}.price-line.total b,.price-line.total span{font-size:16px;color:#111827}.price-line.final{background:#eff6ff;border:1px solid #bfdbfe;border-radius:12px;padding:10px;margin-top:8px}.price-formula{background:#0f172a;color:#dbeafe;border-radius:12px;padding:10px;font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,monospace;font-size:12px;white-space:pre-wrap;line-height:1.5}.price-spec-preview{white-space:pre-wrap;line-height:1.45;font-size:12px;max-height:360px;overflow:auto;background:#fbfdff;border:1px solid #eef2f7;border-radius:12px;padding:10px}.price-warning{border:1px solid #fed7aa;background:#fff7ed;color:#9a3412;border-radius:12px;padding:8px 10px;font-size:12px;font-weight:900;margin-top:8px}
@media(max-width:900px){.price-preview-grid{grid-template-columns:1fr}.parts-tabs-summary{display:none}}

/* V6.8.4.35 报价审核：统一权限控制 */
/* V6.8.4.42：审核预览增加倍率修改、产品图片，压缩数量/单价/金额列。 */
/* V6.8.4.44：审核预览产品名只显示灯具/空壳名称，不再把芯片型号拼到产品标题。 */
/* V6.8.4.45：增加已审核列表、反审、驳回CRM提醒、功率自动补W并同步PDF。 */
.approval-toolbar{display:grid;grid-template-columns:1fr 130px 130px auto;gap:8px;align-items:center;margin-bottom:10px}
.approval-list{display:grid;gap:9px}.approval-row{border:1px solid var(--line);border-radius:14px;background:#fff;padding:11px;display:grid;grid-template-columns:1fr auto;gap:10px;align-items:center}.approval-row b{font-size:15px}.approval-row small{display:block;color:#667085;line-height:1.55;margin-top:3px}.approval-actions{display:flex;gap:6px;flex-wrap:wrap}.approval-badge{display:inline-flex;align-items:center;border-radius:999px;padding:2px 8px;font-size:11px;font-weight:1000;border:1px solid #d4dbe7;background:#f8fafc;color:#334155;margin-right:5px}.approval-badge.pending{background:#fff7ed;color:#c2410c;border-color:#fed7aa}.approval-badge.approved{background:#ecfdf3;color:#027a48;border-color:#a6f4c5}.approval-badge.rejected{background:#fef2f2;color:#b91c1c;border-color:#fecaca}.approval-badge.draft{background:#f1f5f9;color:#475569;border-color:#cbd5e1}.quote-approval-strip{margin:8px 0;padding:8px 10px;border:1px solid #fed7aa;background:#fff7ed;color:#9a3412;border-radius:12px;font-size:12px;font-weight:900;line-height:1.45}.quote-approval-strip.approved{background:#ecfdf3;color:#027a48;border-color:#a6f4c5}.quote-approval-strip.rejected{background:#fef2f2;color:#b91c1c;border-color:#fecaca}.review-modal .modal-box{width:min(1280px,98vw);max-height:92vh}.review-head{display:flex;justify-content:space-between;gap:10px;align-items:flex-start;margin-bottom:10px}.review-meta{color:#667085;font-size:12px;font-weight:850;line-height:1.55}.review-table{width:100%;border-collapse:collapse;min-width:1120px}.review-table th,.review-table td{border:1px solid var(--line);padding:7px;font-size:12px;vertical-align:top}.review-table th{background:#f8fafc;color:#334155}.review-table textarea{min-height:64px;font-size:12px}.review-price-detail{white-space:pre-wrap;font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,monospace;background:#0f172a;color:#dbeafe;border-radius:10px;padding:8px;line-height:1.45;font-size:11px;max-height:150px;overflow:auto}.review-cost-btn{height:32px;border-radius:10px;font-size:12px;font-weight:1000}.review-cost-modal .modal-box{width:min(760px,94vw);max-height:86vh}.review-cost-body{display:grid;gap:10px;max-height:70vh;overflow:auto}.review-price-detail-pop{max-height:none!important;font-size:12px!important;line-height:1.55!important}.review-detail-title{font-size:13px;font-weight:1000;color:#334155;margin:2px 0}.review-spec{white-space:pre-wrap;background:#fbfdff;border:1px solid #eef2f7;border-radius:10px;padding:8px;max-height:160px;overflow:auto}.review-total{font-size:15px;font-weight:1000;color:#111827}.review-note{width:100%;min-height:52px}.review-table th{text-align:center!important;vertical-align:middle!important}.review-table td.review-num-cell,.review-table td.review-action-cell{text-align:center!important;vertical-align:middle!important}.review-table td.review-num-cell input,.review-table input.review-qty,.review-table input.review-price,.review-table input.review-amount{text-align:center!important;font-weight:900;font-variant-numeric:tabular-nums}.review-audit-box{border:1px solid #dbeafe;background:#f8fbff;border-radius:14px;padding:10px 12px;margin:10px 0;display:grid;gap:7px}.review-audit-title{font-size:13px;font-weight:1000;color:#1e3a8a}.review-audit-row{border-top:1px dashed #cbd5e1;padding-top:7px;font-size:12px;line-height:1.55;color:#334155}.review-audit-row:first-of-type{border-top:0;padding-top:0}.review-audit-row b{color:#111827}.review-audit-changes{margin-top:4px;color:#64748b}.review-audit-changes span{display:inline-flex;margin:2px 4px 2px 0;border:1px solid #d4dbe7;border-radius:999px;background:#fff;padding:1px 7px;font-size:11px;font-weight:900}.review-doc-box{border:1px solid #dbeafe;background:#fbfdff;border-radius:14px;padding:10px 12px;margin:10px 0;display:grid;grid-template-columns:1fr 1.1fr 1.2fr;gap:10px}.review-doc-card{border:1px solid #e4eaf3;background:#fff;border-radius:12px;padding:9px 10px;min-width:0}.review-doc-card b{display:block;font-size:12px;color:#1e3a8a;margin-bottom:6px}.review-doc-card .line{font-size:12px;line-height:1.55;color:#334155;white-space:pre-wrap;word-break:break-word}.review-doc-terms{width:100%;border-collapse:collapse;font-size:11px}.review-doc-terms td{border:1px solid #e4eaf3;padding:5px 6px;vertical-align:top}.review-doc-terms td:first-child{font-weight:1000;color:#475569;width:42%;white-space:nowrap}.review-table th.review-cost-th{width:104px!important;min-width:104px!important;white-space:nowrap!important}.review-table td.review-action-cell{width:104px!important;white-space:nowrap!important}.review-cost-btn{white-space:nowrap!important;min-width:76px!important;padding-left:10px!important;padding-right:10px!important}.is-disabled-by-approval{opacity:.55;filter:grayscale(.2)}.review-table th:nth-child(3),.review-table td:nth-child(3){width:68px!important;min-width:68px!important;max-width:68px!important}.review-table th:nth-child(4),.review-table td:nth-child(4){width:92px!important;min-width:92px!important;max-width:92px!important}.review-table th:nth-child(5),.review-table td:nth-child(5){width:92px!important;min-width:92px!important;max-width:92px!important}.review-table input.review-qty{width:54px!important;min-width:54px!important;padding-left:4px!important;padding-right:4px!important}.review-table input.review-price,.review-table input.review-amount{width:78px!important;min-width:78px!important;padding-left:4px!important;padding-right:4px!important}.review-table th.review-cost-th{width:118px!important;min-width:118px!important}.review-table td.review-action-cell{width:118px!important;min-width:118px!important}.review-prod-cell{display:grid;grid-template-columns:56px minmax(0,1fr);gap:9px;align-items:center}.review-prod-img{width:54px;height:54px;border:1px solid #e4eaf3;border-radius:10px;background:#f8fafc;display:grid;place-items:center;overflow:hidden;color:#94a3b8;font-size:11px;font-weight:900}.review-prod-img img{width:100%;height:100%;object-fit:contain;background:#fff}.review-cost-tools{display:grid;grid-template-columns:1fr;gap:6px;align-items:center;justify-items:center}.review-cost-tools label{margin:0!important;font-size:10px!important;color:#64748b;font-weight:1000;display:grid;grid-template-columns:auto 62px;gap:4px;align-items:center;white-space:nowrap}.review-multiplier{width:62px!important;min-width:62px!important;text-align:center!important;padding-left:4px!important;padding-right:4px!important;font-weight:1000!important}.review-cost-btn{height:28px!important;min-width:72px!important;padding-left:8px!important;padding-right:8px!important;font-size:11px!important}.review-cost-modal .review-cost-product-top{display:grid;grid-template-columns:88px minmax(0,1fr);gap:12px;align-items:center;margin-bottom:10px}.review-cost-modal .review-cost-product-top .review-prod-img{width:86px;height:86px;border-radius:14px}.is-disabled-by-approval{opacity:.55;filter:grayscale(.2)}@media(max-width:900px){.approval-toolbar{grid-template-columns:1fr}.approval-row{grid-template-columns:1fr}.review-table{min-width:900px}.review-doc-box{grid-template-columns:1fr}}



/* V6.8.4.44：审核预览表格美化 + 产品标题清理。只影响审核弹窗，不影响正式报价/PDF。 */
.review-modal .modal-box{width:min(1380px,98vw)!important;}
.review-modal .review-head{align-items:center!important;border-bottom:1px solid #eef2f7;padding-bottom:10px;margin-bottom:10px;}
.review-modal .review-meta{line-height:1.65!important;}
.review-modal .review-table{
  min-width:1180px!important;
  table-layout:fixed!important;
  border-collapse:separate!important;
  border-spacing:0!important;
  border:1px solid #dbe3ef!important;
  border-radius:14px!important;
  overflow:hidden!important;
  background:#fff!important;
}
.review-modal .review-table th,
.review-modal .review-table td{
  border:0!important;
  border-right:1px solid #e3e9f3!important;
  border-bottom:1px solid #e3e9f3!important;
  padding:8px 10px!important;
  vertical-align:middle!important;
}
.review-modal .review-table th:last-child,
.review-modal .review-table td:last-child{border-right:0!important;}
.review-modal .review-table tbody tr:last-child td{border-bottom:0!important;}
.review-modal .review-table th{
  height:42px!important;
  background:linear-gradient(180deg,#f8fafc,#f3f6fb)!important;
  color:#334155!important;
  font-size:13px!important;
  font-weight:1000!important;
  text-align:center!important;
  white-space:nowrap!important;
}
.review-modal .review-table th:nth-child(1){width:38px!important;min-width:38px!important;max-width:38px!important;}
.review-modal .review-table th:nth-child(2){width:41%!important;}
.review-modal .review-table th:nth-child(3),.review-modal .review-table td:nth-child(3){width:72px!important;min-width:72px!important;max-width:72px!important;}
.review-modal .review-table th:nth-child(4),.review-modal .review-table td:nth-child(4){width:98px!important;min-width:98px!important;max-width:98px!important;}
.review-modal .review-table th:nth-child(5),.review-modal .review-table td:nth-child(5){width:104px!important;min-width:104px!important;max-width:104px!important;}
.review-modal .review-table th.review-cost-th,.review-modal .review-table td.review-action-cell{width:118px!important;min-width:118px!important;max-width:118px!important;}
.review-modal .review-table th:nth-child(7){width:34%!important;}
.review-modal .review-index-cell{font-size:13px!important;color:#475569!important;font-weight:1000!important;background:#fbfdff!important;}
.review-modal .review-product-td{background:#fff!important;}
.review-modal .review-prod-cell{
  display:grid!important;
  grid-template-columns:64px minmax(0,1fr)!important;
  gap:12px!important;
  align-items:center!important;
  min-height:78px!important;
}
.review-modal .review-prod-img{
  width:60px!important;
  height:60px!important;
  border-radius:14px!important;
  border:1px solid #dbe3ef!important;
  background:linear-gradient(180deg,#fff,#f8fafc)!important;
  box-shadow:0 6px 16px rgba(15,23,42,.08)!important;
}
.review-modal .review-prod-img img{padding:3px!important;object-fit:contain!important;}
.review-modal .review-prod-info{min-width:0!important;}
.review-modal .review-prod-title{
  display:block!important;
  font-size:14px!important;
  line-height:1.35!important;
  color:#111827!important;
  font-weight:1000!important;
  margin-bottom:5px!important;
  word-break:break-word!important;
}
.review-modal .review-prod-sub{
  color:#64748b!important;
  font-size:12px!important;
  font-weight:850!important;
  white-space:nowrap!important;
  overflow:hidden!important;
  text-overflow:ellipsis!important;
}
.review-modal .review-table input.review-qty,
.review-modal .review-table input.review-price,
.review-modal .review-table input.review-amount{
  height:38px!important;
  border:1px solid #cfd8e6!important;
  border-radius:12px!important;
  background:#fff!important;
  color:#111827!important;
  font-size:14px!important;
  font-weight:1000!important;
  text-align:center!important;
  font-variant-numeric:tabular-nums!important;
  box-shadow:inset 0 1px 2px rgba(15,23,42,.03)!important;
}
.review-modal .review-table input.review-qty{width:54px!important;min-width:54px!important;}
.review-modal .review-table input.review-price{width:78px!important;min-width:78px!important;}
.review-modal .review-table input.review-amount{width:84px!important;min-width:84px!important;background:#f8fafc!important;color:#0f172a!important;}
.review-modal .review-cost-tools{
  display:flex!important;
  flex-direction:column!important;
  align-items:center!important;
  justify-content:center!important;
  gap:8px!important;
  width:100%!important;
}
.review-modal .review-cost-btn{
  height:34px!important;
  min-width:82px!important;
  padding:0 12px!important;
  border-radius:12px!important;
  background:#eef2ff!important;
  border-color:#c7d2fe!important;
  color:#3730a3!important;
  font-size:12px!important;
  font-weight:1000!important;
  box-shadow:0 4px 12px rgba(79,70,229,.08)!important;
}
.review-modal .review-cost-btn:hover{background:#e0e7ff!important;border-color:#a5b4fc!important;}
.review-modal .review-multiplier-wrap{
  display:inline-flex!important;
  align-items:center!important;
  justify-content:center!important;
  gap:4px!important;
  height:34px!important;
  border:1px solid #e2e8f0!important;
  border-radius:999px!important;
  background:#f8fafc!important;
  padding:0 6px 0 9px!important;
  white-space:nowrap!important;
}
.review-modal .review-multiplier-wrap span{
  color:#64748b!important;
  font-size:11px!important;
  font-weight:1000!important;
}
.review-modal .review-multiplier{
  width:56px!important;
  min-width:56px!important;
  height:28px!important;
  border:0!important;
  border-left:1px solid #e2e8f0!important;
  border-radius:0!important;
  background:transparent!important;
  padding:0 0 0 6px!important;
  text-align:center!important;
  font-size:13px!important;
  font-weight:1000!important;
  color:#111827!important;
  box-shadow:none!important;
}
.review-modal .review-spec-td{background:#fbfdff!important;}
.review-modal .review-spec{
  min-height:96px!important;
  max-height:124px!important;
  overflow:auto!important;
  border:1px solid #e3e9f3!important;
  border-radius:14px!important;
  background:#fff!important;
  padding:10px 12px!important;
  font-size:13px!important;
  line-height:1.42!important;
  font-weight:760!important;
  color:#1f2937!important;
  white-space:pre-wrap!important;
  box-shadow:inset 0 1px 2px rgba(15,23,42,.02)!important;
}
.review-modal .review-total{
  border:1px solid #dbeafe!important;
  background:#eff6ff!important;
  color:#1e3a8a!important;
  border-radius:12px!important;
  padding:9px 12px!important;
  min-width:210px!important;
  text-align:center!important;
}
.review-modal .review-note{border-radius:12px!important;border-color:#dbe3ef!important;}
@media(max-width:900px){.review-modal .review-table{min-width:1040px!important}.review-modal .review-table th:nth-child(2){width:36%!important}.review-modal .review-table th:nth-child(7){width:32%!important}}


/* V6.8.5.5：报价看板金额双币种显示，避免 RMB 金额被标成 USD */
#dMonthAmt{font-size:22px!important;white-space:nowrap;}
#dOrderRevenue{font-size:22px!important;white-space:nowrap;}
#dTopSalesSub,#dTopCustomerSub,#curLabel,#dBalanceSub{line-height:1.35!important;white-space:normal!important;}
.dash-card.kpi-xl{width:300px!important;}

/* V6.8.5.8：报价数量卡片改成近7日柱形图；不是图片，纯前端 HTML/CSS */
.quote-week-card{width:260px!important;height:132px!important;padding:12px 14px!important;justify-content:flex-start!important;gap:6px!important;}
.quote-week-top{display:grid;grid-template-columns:auto auto 1fr;gap:7px;align-items:end;min-height:26px;}
.quote-week-top b{font-size:26px!important;line-height:1!important;}
.quote-week-top small{font-size:11px!important;font-weight:900!important;color:#64748b!important;white-space:nowrap;}
.quote-week-top small:last-child{text-align:right;overflow:hidden;text-overflow:ellipsis;}
.quote-week-bars{display:grid;grid-template-columns:repeat(7,1fr);gap:6px;align-items:end;height:58px;margin-top:2px;padding:0 2px;}
.quote-week-bar{display:grid;grid-template-rows:1fr auto;gap:3px;align-items:end;min-width:0;height:58px;}
.quote-week-bar i{display:block;width:100%;min-height:5px;border-radius:8px 8px 4px 4px;background:linear-gradient(180deg,#2563eb,#93c5fd);box-shadow:0 4px 10px rgba(37,99,235,.16);}
.quote-week-bar.today i{background:linear-gradient(180deg,#dc2626,#fecaca);box-shadow:0 4px 10px rgba(220,38,38,.14);}
.quote-week-bar span{display:block;text-align:center;font-size:9px!important;line-height:1!important;color:#64748b!important;font-weight:900!important;white-space:nowrap;}
.quote-week-count{position:absolute;right:14px;top:34px;font-size:11px!important;color:#475569!important;font-weight:1000!important;}
@media(max-width:900px){.quote-week-card{width:100%!important;}}


/* V6.8.5.23：订单中心金额按原币种分开统计，不再把 USD 与 RMB 相加 */
.finance-strip.currency-split-v68523 span{display:inline-flex;align-items:center;gap:6px;flex-wrap:wrap;min-height:30px}
.finance-strip.currency-split-v68523 b{font-variant-numeric:tabular-nums;white-space:nowrap}

/* V6.8.5.38: PI one product balanced full-page preview/print. */
.paper.quote-one-item{padding:20mm 13mm 10mm!important;height:297mm!important;min-height:297mm!important;max-height:297mm!important;overflow:hidden!important}
.paper.quote-one-item .paper-top{grid-template-columns:96mm 72mm!important;gap:12mm!important;min-height:58mm!important}
.paper.quote-one-item .from h1{font-size:16pt!important;margin:0 0 5mm!important}
.paper.quote-one-item .block,.paper.quote-one-item .to{font-size:9.2pt!important;line-height:1.25!important}
.paper.quote-one-item .to{margin-top:7mm!important}
.paper.quote-one-item .brandstamp{font-size:8.2pt!important;line-height:1.22!important}
.paper.quote-one-item .qt-title{font-size:15pt!important;margin:2mm 0 4mm!important}
.paper.quote-one-item .terms{font-size:8.2pt!important}
.paper.quote-one-item .terms td{height:4.9mm!important;padding:1.0mm 1.8mm!important}
.paper.quote-one-item .quote-table{margin-top:10mm!important;font-size:7.25pt!important;line-height:1.18!important}
.paper.quote-one-item .quote-table th,.paper.quote-one-item .quote-table td{padding:.75mm .8mm!important}
.paper.quote-one-item .quote-table th{height:9.8mm!important}
.paper.quote-one-item .quote-product-row td{height:36mm!important}
.paper.quote-one-item .quote-total-row td{height:6mm!important}
.paper.quote-one-item .quote-table .spec{padding:.8mm 1mm!important;line-height:1.18!important}
.paper.quote-one-item .prod-img{max-height:18mm!important}
.paper.quote-one-item .final-summary{margin-top:7mm!important;gap:10mm!important}
.paper.quote-one-item .final-summary .box{font-size:8.7pt!important;line-height:1.38!important;padding:2.3mm!important;min-height:22mm!important}
.paper.quote-one-item .final-summary .box b{font-size:10pt!important}
.paper.quote-one-item .final-sign{margin-top:15mm!important;font-size:8.8pt!important;gap:10mm!important}
.paper.quote-one-item .final-sign div{padding-top:3mm!important}
.paper.quote-one-item .bank{margin-top:8mm!important;padding:2.3mm!important;font-size:7.8pt!important;line-height:1.23!important;min-height:20mm!important}
.paper.quote-one-item .bank-terms{margin-top:4.5mm!important;padding:1.4mm 1.6mm!important;font-size:7.25pt!important;line-height:1.28!important;min-height:0!important}
@media print{.paper.quote-one-item,.paper.quote-one-item.flow-paper{height:297mm!important;min-height:297mm!important;max-height:297mm!important;overflow:hidden!important;padding:20mm 13mm 10mm!important;page-break-after:auto!important;break-after:auto!important}}

/* V6.8.5.39 历史报价列表：显示前4个产品图片 + 型号 */
.history-product-strip{display:none}
.history-card-badge{padding:8px 10px 0}
.history-view-list .history-quote-card{padding:12px 14px!important;border-radius:14px!important;cursor:default!important;min-height:128px!important}
.history-view-list .history-card-badge{padding:0 0 7px 0!important}
.history-view-list .history-card-body{display:grid!important;grid-template-columns:minmax(370px,.92fr) minmax(520px,1.08fr)!important;gap:16px!important;align-items:center!important;padding:0!important}
.history-view-list .history-list-main{min-width:0}
.history-view-list .history-product-strip{display:grid!important;grid-template-columns:repeat(4,minmax(118px,1fr));gap:10px;align-items:stretch}
.history-product-mini{border:1px solid #dbe3ef;background:linear-gradient(180deg,#fff,#f8fafc);border-radius:14px;padding:8px 8px 7px;min-height:118px;display:flex;flex-direction:column;align-items:center;justify-content:flex-start;gap:5px;box-shadow:0 2px 8px rgba(15,23,42,.04);cursor:pointer;min-width:0}
.history-product-mini:hover{border-color:#93c5fd;background:#eff6ff}
.history-product-pic{width:100%;height:58px;display:flex;align-items:center;justify-content:center;background:#fff;border:1px solid #eef2f7;border-radius:10px;overflow:hidden;color:#94a3b8;font-size:18px;font-weight:1000}
.history-product-pic img{max-width:100%;max-height:54px;object-fit:contain;display:block}
.history-product-model{font-size:13px;font-weight:1000;color:#111827;line-height:1.2;text-align:center;max-width:100%;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.history-product-sub{font-size:11px;font-weight:800;color:#64748b;line-height:1.2;text-align:center;max-width:100%;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.history-product-empty{display:flex;align-items:center;justify-content:center;border:1px dashed #cbd5e1;border-radius:14px;background:#f8fafc;color:#94a3b8;font-weight:900;min-height:96px;grid-column:1/-1}
.history-view-grid-large .history-product-strip,.history-view-grid-medium .history-product-strip,.history-view-grid-small .history-product-strip{display:none!important}
@media(max-width:1350px){.history-view-list .history-card-body{grid-template-columns:1fr!important}.history-view-list .history-product-strip{grid-template-columns:repeat(4,minmax(100px,1fr))}}
@media(max-width:720px){.history-view-list .history-product-strip{grid-template-columns:repeat(2,minmax(0,1fr))}.history-product-mini{min-height:104px}.history-product-pic{height:50px}}



/* V6.8.5.43：订单中心列表只保留“详情”，删除右侧快速按钮，并扩大详情工作区 */
.order-grid{grid-template-columns:minmax(330px,.58fr) minmax(780px,1.42fr)!important;gap:18px!important}
.order-card{grid-template-columns:minmax(0,1fr) auto!important}
.order-card .order-list-actions{display:flex!important;align-items:center!important;justify-content:flex-end!important;white-space:nowrap!important}
.order-card .order-list-actions button{min-width:72px!important}
@media(max-width:1400px){.order-grid{grid-template-columns:minmax(300px,.65fr) minmax(650px,1.35fr)!important}}
@media(max-width:1100px){.order-grid{grid-template-columns:1fr!important}}

</style></head><body>
<div class="login-mask" id="loginMask"><div class="login-box"><div class="login-logo">Q</div><h2>报价系统登录</h2><p>登录账号共用 PLM / Artdon Office 统一账号。老板、管理员默认拥有全部权限。</p><label>账号</label><input id="loginUser" autocomplete="username" placeholder="PLM账号 / 统一账号"><label>密码</label><input id="loginPass" type="password" autocomplete="current-password" placeholder="密码" onkeydown="if(event.key==='Enter')doLogin()"><div class="btns"><button class="blue" onclick="doLogin()">登录</button></div><div id="loginErr" class="login-error"></div></div></div>
<div class="top"><div class="brand"><b>Artdon Lighting Limited</b><small>Quotation ERP · PHP + MySQL · V6.8.5.45 首页链接修正版</small></div><div id="clock" class="clock"></div><span id="meBadge" class="top-user">未登录</span><button onclick="location.href='index.php'">返回首页</button><button id="topPdfBtn" onclick="exportPDF()">导出 PDF</button><button id="topExcelBtn" onclick="exportExcel()">导出 Excel</button><button onclick="doLogout()">退出</button></div>
<div class="system-nav no-print"><a href="index.php">首页</a><a href="crm.php">CRM</a><a href="mail.php">邮箱</a><a href="promotion.php">推广</a><a class="active" href="quotation.php">报价</a><a href="datasheet.php">资料</a><a href="bom.php">BOM</a><a href="dispatch_next.php">派工</a><a href="naming.php">命名系统</a><a href="plm.php">PLM</a><a href="crm.php#linkage">材料重量</a></div>
<div class="quote-func-nav no-print" id="quoteFuncNav"><button data-page="quote" onclick="showPage('quote')">报价单</button><button data-page="products" data-perm="product_view" onclick="showPage('products')">产品库</button><button data-page="customers" data-perm="customer_view" onclick="showPage('customers')">客户库</button><button data-page="materials" data-perm="material_view" onclick="showPage('materials')">BOM物料源</button><button data-page="history" data-perm="history_view" onclick="showPage('history')">历史报价</button><button data-page="approval" data-perm="quote_review_view" onclick="showPage('approval');setApprovalStatus('pending')">未审核列表</button><button data-page="approval" data-perm="quote_review_view" onclick="showPage('approval');setApprovalStatus('approved')">已审核列表</button><button data-page="orders" data-perm="order_convert" onclick="showPage('orders')">订单中心</button><button data-page="packaging" data-perm="order_convert" onclick="showPage('packaging')">包装资料库</button><button data-page="documents" data-perm="order_convert" onclick="showPage('documents')">单证中心</button><button data-page="settings" data-perm="settings_access" onclick="showPage('settings')">系统设置</button><button data-page="permissions" data-perm="permission_manage" onclick="showPage('permissions')">权限管理</button><button data-page="logs" data-perm="log_view" onclick="showPage('logs')">日志中心</button></div>
<div class="dash no-print" id="topDash"><div class="dash-togglebar"><b>报价数据看板</b><span class="dash-mini" id="dashMiniSummary">报价 0 ｜ 待审核 0 ｜ 已转订单 0 ｜ 欠款 0</span><span class="dash-refresh-note" id="dashOrderNote">订单数据自动读取</span><button type="button" class="gray dash-toggle-btn" onclick="openDashTemplateModal()">显示设置</button><button type="button" class="gray dash-toggle-btn" id="topDashToggle" onclick="toggleTopDash()">折叠</button></div><div class="dash-cards" id="topDashCards"><div class="dash-card kpi-xl quote-week-card" data-dash-widget="quote_count"><span>近7日报价</span><div class="quote-week-top"><b id="dTotal">0</b><small>总报价</small><small id="dWeekSummary">今日 0 ｜ 本月 0</small></div><div id="dWeekBars" class="quote-week-bars"></div><small id="dMonthSub">近7天柱形图</small></div><div class="dash-card" data-dash-widget="month_amount"><span>本月金额</span><b id="dMonthAmt">0</b><small id="curLabel">USD</small></div><div class="dash-card kpi-wide" data-dash-widget="top_customer"><span>最多客户</span><b id="dTopCustomer">-</b><small id="dTopCustomerSub">0 份</small></div><div class="dash-card" data-dash-widget="currency"><span>币种/汇率</span><select id="topCurrency" onchange="syncCurrency()"><option value="USD">USD 美金</option><option value="RMB">RMB 人民币</option></select><input id="topRate" type="number" step="0.0001" value="7" oninput="syncCurrency()"></div><div class="dash-card kpi-xl" data-dash-widget="top_sales"><span>报价最多员工</span><b id="dTopSales">-</b><small id="dTopSalesSub">0 份 ｜ 0.00</small></div><div class="dash-card kpi-wide" data-dash-widget="approval"><span>审核状态</span><div class="kpi-grid"><div class="kpi-line"><span>总份数</span><span class="kpi-num" id="dQuoteAll">0</span></div><div class="kpi-line"><span>待审核</span><span class="kpi-num" id="dQuotePending">0</span></div><div class="kpi-line"><span>已审核</span><span class="kpi-num" id="dQuoteApproved">0</span></div><div class="kpi-line"><span>已驳回</span><span class="kpi-num" id="dQuoteRejected">0</span></div></div></div><div class="dash-card kpi-wide" data-dash-widget="convert"><span>转订单</span><div class="kpi-grid"><div class="kpi-line"><span>未转订单</span><span class="kpi-num" id="dNotConverted">0</span></div><div class="kpi-line"><span>已转订单</span><span class="kpi-num" id="dConverted">0</span></div></div><small id="dOrderCountSub">订单 0 个</small></div><div class="dash-card kpi-wide" data-dash-widget="order_revenue"><span>订单成交</span><b id="dOrderRevenue">RMB 0.00</b><small id="dOrderRevenueSub">USD 0.00 ｜ 只统计订单</small></div><div class="dash-card kpi-wide" data-dash-widget="docs_ship"><span>单证 / 出货</span><div class="kpi-grid"><div class="kpi-line"><span>未出单证</span><span class="kpi-num" id="dNoDocs">0</span></div><div class="kpi-line"><span>未出货</span><span class="kpi-num" id="dNoShip">0</span></div></div><small id="dDocsShipSub">按订单统计</small></div><div class="dash-card" data-dash-widget="receivable"><span>欠款</span><b id="dBalance">0</b><small id="dBalanceSub">USD 未收</small></div></div></div>
<div class="shell"><aside class="side no-print"><div class="nav"><button onclick="showPage('quote')">报价单</button><button onclick="showPage('products')">产品库</button><button onclick="showPage('customers')">客户库</button><button onclick="showPage('materials')">BOM物料源</button><button onclick="showPage('history')">历史报价</button><button onclick="showPage('approval');setApprovalStatus('pending')">未审核列表</button><button onclick="showPage('approval');setApprovalStatus('approved')">已审核列表</button><button onclick="showPage('orders')">订单中心</button><button onclick="showPage('packaging')">包装资料库</button><button onclick="showPage('documents')">单证中心</button><button onclick="showPage('settings')">系统设置</button><button onclick="showPage('logs')">日志中心</button></div></aside><main class="main">
<section id="page-quote" class="page"><div class="quote-page-split" id="quotePageSplit"><div class="card no-print quote-config-card" id="quoteConfigCard"><div class="card-head"><b>报价配置</b><div class="quote-config-head-actions"><span class="hint">拉取 BOM 物料</span><button type="button" class="gray quote-config-toggle" id="quoteConfigToggle" onclick="toggleQuoteConfig()">折叠</button></div></div><div class="card-body" id="quoteConfigBody">
<div class="row3"><div><label>报价编号</label><input id="quoteNo"></div><div><label>报价日期</label><input id="quoteDate" type="date"></div><div><label>报价状态</label><select id="quoteStatus"><option value="Quotation sheet">Quotation sheet</option><option value="PROFORMA INVOICE">PROFORMA INVOICE</option></select></div></div><div class="hint" style="margin:8px 0 4px">公司抬头、银行信息、条款模板已移到「系统设置」，报价单自动使用默认配置。</div><div id="quoteApprovalStrip" class="quote-approval-strip">审核状态：新报价未提交</div><label>客户</label><div class="customer-fuzzy-shell" id="customerFuzzyShell"><input id="customerSearchInput" class="customer-fuzzy-input" type="text" autocomplete="off" placeholder="输入客户代码 / 公司 / 联系人 / 国家 / 邮箱，模糊查找" onfocus="openCustomerSearch()" oninput="onCustomerSearchInput()" onkeydown="handleCustomerSearchKey(event)"><select id="customerSelect" style="display:none"></select><div id="customerSearchResults" class="customer-fuzzy-results"></div></div><div id="customerSearchHint" class="customer-search-hint">输入关键词快速找客户，不用下拉一直滑。</div><label>产品分类</label><select id="productType" onchange="renderProductSelect();renderParts();render()"><option value="track">导轨灯</option><option value="recessed">嵌入式</option><option value="surface">明装式</option><option value="pendant">吊线式</option><option value="magnetic">磁吸式</option><option value="linear">线性灯</option><option value="outdoor">户外灯</option><option value="custom">定制产品</option><option value="other">其它</option></select>
<label>产品库产品 / 命名中心产品</label><div class="row2" style="grid-template-columns:minmax(0,1fr) auto"><div class="product-fuzzy-shell" id="productFuzzyShell"><input id="productSearchInput" class="product-fuzzy-input" type="text" autocomplete="off" placeholder="输入型号或关键词，全产品库模糊查找（不受当前分类限制）" onfocus="openProductSearch()" oninput="onProductSearchInput()" onkeydown="handleProductSearchKey(event)"><select id="productSelect" style="display:none" onchange="selectProduct()"></select><div id="productSearchResults" class="product-fuzzy-results"></div></div><button class="gray" onclick="openProductModal()">更多选择</button></div><div id="productLinkHint" class="hint" style="margin-top:6px"></div><div id="partsBox" style="margin-top:10px"></div>
</div></div><div class="card no-print quote-work"><div class="card-head"><b>报价单工作区</b><span class="hint">当前只编辑一个产品，下方列表可放30+产品</span></div><div class="card-body"><div class="quote-current-title"><b>当前产品配置</b><span class="hint">紧凑参数区：左侧改动会同步右侧预览</span></div><div class="q-compact-grid"><div><label>数量</label><input id="qty" class="q-small-input" type="number" min="0" max="99999" step="1" value="1" inputmode="numeric"></div><div><label>手动单价</label><input id="manualPrice" class="q-small-input" type="number" min="0" max="9999" step="0.01" placeholder="自动"></div><div><label>MOQ</label><input id="moq" class="q-small-input" type="number" min="0" max="99999" step="1" value="200" inputmode="numeric"></div><div><label>币种</label><select id="currency"><option value="USD">USD</option><option value="RMB">RMB</option><option value="EUR">EUR</option></select></div></div><div class="q-compact-grid-2"><div><label>汇率</label><input id="rate" class="q-small-input" type="number" min="0" max="8.88" step="0.01" value="7"></div><div><label>报价等级</label><select id="priceLevel"></select></div><div><label>倍率</label><div class="q-level-box"><input id="priceMultiplierCustom" class="q-small-input" type="number" min="0" max="99.99" step="0.01" placeholder="1.35"><button type="button" class="gray" onclick="adjustPriceMultiplier(-0.01)">−</button><button type="button" class="gray" onclick="adjustPriceMultiplier(0.01)">+</button></div></div></div><div class="q-mini-grid"><div><label>Customer Code</label><input id="customerCode" maxlength="24" placeholder="EX003"></div><div><label>颜色</label><select id="color"></select></div><div><label>Beam Angle</label><input id="beamAngle" maxlength="12" placeholder="24" inputmode="decimal"></div><div><label>Power</label><input id="power" maxlength="16" placeholder="10W"></div><div><label>色温 CCT</label><input id="cct" maxlength="8" placeholder="3000K"></div><div><label>显指 CRI</label><input id="cri" maxlength="8" placeholder="CRI90"></div><div><label>IP</label><input id="ip" maxlength="6" placeholder="IP44"></div></div><div class="q-remark-grid"><div><label>备注1</label><input id="remark1" placeholder="特殊参数/说明"></div><div><label>备注2</label><input id="remark2" placeholder="特殊参数/说明"></div><div><label>备注3</label><input id="remark3" placeholder="特殊参数/说明"></div><div><label>备注4</label><input id="remark4" placeholder="特殊参数/说明"></div></div><textarea id="extraSpec" style="display:none"></textarea><div class="q-inline-note">备注1-4会自动接到 Specification 后面，并按编号继续往后排；空备注自动跳过。</div><div class="quote-add-bar"><button class="blue" onclick="addOrUpdateQuoteItem()">添加/更新当前产品到报价单</button><button class="gray" onclick="previewCurrentEditor()">预览当前配置</button><button class="gray" onclick="clearCurrentEditor()">清空当前产品配置</button></div><div class="quote-items"><div class="quote-items-head"><b>本报价单产品明细</b><span class="hint"><span id="quoteItemsCount">0 个产品</span> ｜ 拖动左侧 ≡ 调整顺序</span></div><div id="quoteItemsList"></div></div><div class="btns"><button class="green" onclick="saveQuote()">保存整张报价单</button><button class="blue" onclick="saveAsNewVersion()">另存V版本</button><button class="blue" onclick="convertToOrder()">一键转订单</button><button class="gray" onclick="newQuote()">新报价</button></div><div class="quote-doc-quick no-print" id="quoteDocQuick"><div class="quote-doc-quick-h"><b>报价单快捷设置</b><span class="hint">公司抬头 / 付款条款 / 银行信息 / 附加条款</span></div><div class="quote-doc-quick-b"><div class="qdoc-select-grid"><div><label>公司抬头</label><select id="qdocHeaderSelect" onchange="qdocSelectChanged('header')"></select></div><div><label>条款模板 / 付款方式</label><select id="qdocTemplateSelect" onchange="qdocSelectChanged('template')"></select></div><div><label>银行信息</label><select id="qdocBankSelect" onchange="qdocSelectChanged('bank')"></select></div></div><div class="qdoc-tabs"><button type="button" data-qdoc-tab="header" onclick="showQdocTab('header')">公司抬头</button><button type="button" data-qdoc-tab="terms" onclick="showQdocTab('terms')">付款条款</button><button type="button" data-qdoc-tab="bank" onclick="showQdocTab('bank')">银行信息</button></div><div id="qdocPaneHeader" class="qdoc-pane"><div class="qdoc-form-grid"><input type="hidden" id="qhId"><div><label>抬头名称</label><input id="qhName" placeholder="如 Gallin / Artdon默认抬头"></div><div><label>公司名称</label><input id="qhCompany" placeholder="报价单左上角公司名"></div><div class="qdoc-wide"><label>From 地址 / 电话 / 联系人</label><textarea id="qhFrom" placeholder="From信息，会显示在报价单左上角"></textarea></div><div class="qdoc-wide"><label>右上角蓝色抬头 / 印章文字</label><textarea id="qhStamp" placeholder="右上角公司英文/中文名"></textarea></div><div><label><input id="qhShowStamp" type="checkbox" style="width:auto;margin-right:6px">显示右上角蓝色抬头</label></div></div><div class="qdoc-actions"><button class="blue" onclick="saveQdocHeader()">保存抬头并应用</button><button class="green" onclick="saveQdocHeader(true)">保存并设默认</button><button class="gray" onclick="newQdocHeader()">新增抬头</button></div></div><div id="qdocPaneTerms" class="qdoc-pane"><div class="qdoc-form-grid"><input type="hidden" id="qtId"><div><label>模板名称</label><input id="qtName" placeholder="如 默认报价条款 / FOB条款"></div><div><label>Price Terms</label><input id="qtPriceTerms" placeholder="FOB XIAOLAN / EXWORK"></div><div><label>Payment 1</label><input id="qtPayment1" placeholder="100% Deposit before production"></div><div><label>Payment 2</label><input id="qtPayment2" placeholder="可留空"></div><div><label>Delivery Date</label><input id="qtDeliveryDate" placeholder="25-35Days After Confirmed"></div><div><label>Quoted Valid</label><input id="qtQuotedValid" placeholder="Within 10 days"></div><div class="qdoc-wide"><div id="qdocTermsPreview" class="qdoc-preview"></div></div></div><div class="qdoc-actions"><button class="blue" onclick="saveQdocTemplate()">保存条款并应用</button><button class="green" onclick="saveQdocTemplate(true)">保存并设默认</button><button class="gray" onclick="newQdocTemplate()">新增条款模板</button></div></div><div id="qdocPaneBank" class="qdoc-pane"><div class="qdoc-form-grid"><input type="hidden" id="qbId"><div><label>银行名称</label><input id="qbName" placeholder="如 默认USD账户"></div><div><label>附加条款字体</label><select id="qbTermsFontSize"><option value="6.5">6.5pt</option><option value="7">7pt</option><option value="7.5">7.5pt</option><option value="8">8pt</option><option value="8.5">8.5pt</option><option value="9">9pt</option><option value="10">10pt</option><option value="11">11pt</option><option value="12">12pt</option></select></div><div class="qdoc-wide"><label>银行信息</label><textarea id="qbText" placeholder="Beneficiary / Bank / Account / Swift..."></textarea></div><div class="qdoc-wide"><label>银行信息下方合同条款 / 大备注（应用当前报价）</label><div class="contract-terms-hint">这里内容会显示在银行信息下方，可写合同条款、质保、付款约定、违约说明等。保存并设为默认后，后续所有新报价自动使用。</div><textarea id="qbExtraTerms" placeholder="例如：
1. Warranty: 3 years.
2. Lead time: subject to final confirmation.
3. The above price is valid for 10 days.
这里填写后会显示在报价单银行信息下面的独立方框里。"></textarea></div></div><div class="qdoc-actions"><button class="blue" onclick="saveQdocBank()">保存银行并应用</button><button class="green" onclick="saveQdocBank(true)">保存并设默认</button><button class="gray" onclick="newQdocBank()">新增银行</button></div></div><div class="qdoc-mini-note">说明：这里保存的是报价系统设置资料。选择后会立即同步右侧预览；保存并设默认后，新报价自动套用。</div></div></div></div></div><div class="card preview-card"><div class="card-head preview-head"><div class="preview-title">报价单预览</div><div class="preview-toolbar no-print"><button type="button" class="gray" title="缩小预览" onclick="adjustPreviewZoom(-0.1)">－</button><span id="previewZoomLabel" class="zoom-label">100%</span><button type="button" class="gray" title="放大预览" onclick="adjustPreviewZoom(0.1)">＋</button><button type="button" class="gray fit-btn" title="自动适应右侧宽度" onclick="fitPreviewWidth()">适宽</button><button type="button" class="gray" title="恢复 100%" onclick="resetPreviewZoom()">100%</button></div></div><div id="quotePreviewBody" class="preview"><div id="paper"></div></div></div></div></section>
<section id="page-products" class="page"><div class="grid2"><div class="card"><div class="card-head"><b>产品库 · 新建/编辑</b></div><div class="card-body"><input type="hidden" id="pId"><div class="row3"><div><label>分类</label><select id="pType"><option value="track">导轨灯</option><option value="recessed">嵌入式</option><option value="surface">明装式</option><option value="pendant">吊线式</option><option value="magnetic">磁吸式</option><option value="linear">线性灯</option><option value="outdoor">户外灯</option><option value="custom">定制产品</option><option value="other">其它</option></select></div><div><label>需要接头</label><select id="pNeed"><option value="auto">按分类</option><option value="yes">需要</option><option value="no">不需要</option></select></div><div><label>制造商代码</label><input id="pCode"></div></div><label>产品名称</label><input id="pName"><div class="row3"><div><label>来源</label><input id="pSource"></div><div><label>大类</label><input id="pCategory"></div><div><label>系列</label><input id="pSeries"></div></div><div class="row3"><div><label>尺寸</label><input id="pSize"></div><div><label>开孔</label><input id="pCutout"></div><div><label>功率</label><input id="pPower"></div></div><div class="row3"><div><label>IP</label><input id="pIp"></div><div><label>颜色</label><select id="pColor"></select></div><div><label>MOQ</label><input id="pMoq" type="number" value="200"></div></div><div class="row3"><div><label>RMB价格</label><input id="pRmb" type="number" step="0.01"></div><div><label>USD价格</label><input id="pUsd" type="number" step="0.01"></div><div><label>价格备注</label><input id="pPriceNote"></div></div><label>标签/备注</label><textarea id="pNote"></textarea><label>产品图片</label><input id="pImage" type="file" accept="image/*"><div class="btns"><button class="blue" onclick="saveProduct()">保存产品</button><button class="gray" onclick="clearProduct()">清空</button><button class="red" onclick="deleteProduct()">删除</button></div></div></div><div class="card"><div class="card-head"><b>产品列表</b><div class="toolbar"><button class="gray" onclick="productView='gallery';renderProducts()">图片模式</button><button class="gray" onclick="productView='list';renderProducts()">列表模式</button><span class="result-count" id="prodCount"></span></div></div><div class="card-body"><div class="filter-bar"><input id="prodSearch" placeholder="综合搜索：名称/型号/系列/尺寸" oninput="renderProducts()"><select id="prodFilterType" onchange="renderProducts()"><option value="">全部分类</option></select><input id="prodFilterSeries" placeholder="系列，如 REDLINE" oninput="renderProducts()"><input id="prodFilterPower" placeholder="功率，如 10W" oninput="renderProducts()"><input id="prodFilterIp" placeholder="IP，如 IP44" oninput="renderProducts()"><input id="prodFilterSize" placeholder="尺寸，如 65*70" oninput="renderProducts()"><input id="prodFilterCutout" placeholder="开孔，如 55" oninput="renderProducts()"><select id="prodFilterSource" onchange="renderProducts()"><option value="">全部来源</option><option value="PLM">PLM</option><option value="manual">自建</option><option value="outsource">外购</option></select><input id="prodPriceMin" type="number" step="0.01" placeholder="最低价" oninput="renderProducts()"><input id="prodPriceMax" type="number" step="0.01" placeholder="最高价" oninput="renderProducts()"></div><div id="productList" class="product-gallery" style="margin-top:10px"></div></div></div></div></section>
<section id="page-customers" class="page"><div class="grid2"><div class="card"><div class="card-head"><b>客户库</b><span class="hint">已联动 CRM 客户中心</span></div><div class="card-body"><input type="hidden" id="cId"><input type="hidden" id="cSource"><input type="hidden" id="cCrmCustomerId"><div class="customer-sync-panel"><b>CRM客户同步：</b>报价系统实时读取 CRM 客户；客户邮箱、主要联系人邮箱/电话、网站、地址一、地址二、更多地址都会一起读取。CRM 删除后，点“一键同步CRM客户”会同步清理失效客户。</div><div class="row2"><div><label>代码</label><input id="cCode"></div><div><label>公司</label><input id="cCompany"></div></div><div class="row2"><div><label>联系人 / 主要联系人</label><input id="cContact"></div><div><label>邮箱 / 主要联系人邮箱</label><input id="cEmail"></div></div><div class="row2"><div><label>电话 / WhatsApp</label><input id="cPhone"></div><div><label>国家</label><input id="cCountry"></div></div><div class="row2"><div><label>网站</label><input id="cWebsite"></div><div><label>CRM客户ID</label><input id="cCrmIdView" readonly placeholder="CRM实时客户自动显示"></div></div><label>地址一 / 办公室地址</label><input id="cAddress1" placeholder="办公室地址 / Billing Address"><label>地址二 / 工厂地址</label><input id="cAddress2" placeholder="工厂地址 / Shipping Address"><label>更多地址</label><textarea id="cAddressesJson" placeholder="CRM里的多个地址会显示在这里；本地客户可手填。"></textarea><label>备注</label><textarea id="cNote"></textarea><div class="btns"><button class="blue" onclick="saveCustomer()">保存本地客户</button><button class="green" onclick="syncCrmCustomers()">一键同步CRM客户</button><button class="red" onclick="alignCrmCustomers()">强制对齐CRM客户</button><button class="gray" onclick="toggleCustomerBatch()" id="custBatchBtn">批量管理</button><button class="red" onclick="deleteCustomer()">删除本地客户</button></div><div class="hint" id="custSyncStatus" style="margin-top:8px">CRM客户只读；邮箱/地址/主要联系人资料直接从CRM读取。如旧客户资料不完整，点“一键同步CRM客户”或“强制对齐CRM客户”。</div></div></div><div class="card"><div class="card-head"><b>客户列表</b><span class="result-count" id="custCount"></span></div><div class="card-body"><div class="filter-bar"><input id="custSearch" oninput="renderCustomers()" placeholder="搜索：代码/公司/联系人/邮箱/地址/网站"><select id="custCountryFilter" onchange="renderCustomers()"><option value="">全部国家</option></select><input id="custCodeFilter" oninput="renderCustomers()" placeholder="客户代码"><select id="custSourceFilter" onchange="renderCustomers()"><option value="">全部来源</option><option value="crm">CRM客户</option><option value="quote">报价本地</option></select><select id="custLetterFilter" onchange="renderCustomers()"><option value="">全部首字母</option></select></div><div class="customer-batch-tools" id="custBatchTools" style="display:none"><button class="gray" onclick="selectVisibleCustomers()">全选当前显示</button><button class="gray" onclick="clearVisibleCustomerChecks()">取消选择</button><button class="red" onclick="batchDeleteSelectedCustomers()">删除选中本地客户</button><button class="green" onclick="syncCrmCustomers()">同步并清理CRM删除</button><button class="red" onclick="alignCrmCustomers()">强制对齐CRM</button><span class="hint">强制对齐会清掉报价端旧客户库，只保留当前CRM客户。</span></div><div id="customerList" class="list" style="margin-top:10px"></div></div></div></div></section>
<section id="page-materials" class="page"><div class="card"><div class="card-head"><b>BOM物料源</b><span class="result-count" id="matCount"></span></div><div class="card-body"><div class="filter-bar"><input id="matSearch" oninput="renderMaterials()" placeholder="综合搜索：品牌/名称/型号/规格/供应商"><select id="matCat" onchange="renderMaterials()"></select><select id="matBrand" onchange="renderMaterials()"><option value="">全部品牌</option></select><select id="matSupplier" onchange="renderMaterials()"><option value="">全部供应商</option></select><input id="matPriceMin" type="number" step="0.01" oninput="renderMaterials()" placeholder="最低单价"><input id="matPriceMax" type="number" step="0.01" oninput="renderMaterials()" placeholder="最高单价"><select id="matUnit" onchange="renderMaterials()"><option value="">全部单位</option></select><select id="matSort" onchange="renderMaterials()"><option value="category">按分类</option><option value="brand">按品牌</option><option value="priceAsc">价格低到高</option><option value="priceDesc">价格高到低</option><option value="name">按名称</option></select><select id="matPageSize" onchange="renderMaterials()"><option value="50">50条</option><option value="100">100条</option><option value="200">200条</option><option value="500">500条</option></select></div><p class="hint">只读：来自 BOM 的 bom_materials / materials / bom_kv materials。</p><div id="materialsTable"></div></div></div></section>
<section id="page-history" class="page"><div class="card"><div class="card-head"><b>历史报价</b><span class="result-count" id="histCount"></span></div><div class="card-body"><div class="filter-bar"><input id="histSearch" oninput="renderHistory()" placeholder="客户公司 / 客户名 / 订单号"><select id="histRange" onchange="renderHistory()"><option value="all">全部时间</option><option value="today">今天</option><option value="3">3天内</option><option value="7">7天内</option><option value="month">本月</option><option value="lastMonth">上月</option><option value="3m">近3个月</option><option value="year">本年度</option></select><input id="histMonth" type="month" onchange="renderHistory()" placeholder="按月份"><select id="histCustomer" onchange="renderHistory()"><option value="">全部客户</option></select><select id="histOwner" onchange="renderHistory()"><option value="">全部负责人</option></select><select id="histCountry" onchange="renderHistory()"><option value="">全部国家</option></select><select id="histCurrency" onchange="renderHistory()"><option value="">全部币种</option><option value="USD">USD</option><option value="RMB">RMB</option></select><input id="histAmountMin" type="number" step="0.01" oninput="renderHistory()" placeholder="最低金额"><input id="histAmountMax" type="number" step="0.01" oninput="renderHistory()" placeholder="最高金额"><select id="histSort" onchange="renderHistory()"><option value="dateDesc">最新报价</option><option value="amountDesc">金额高到低</option><option value="amountAsc">金额低到高</option><option value="customerAsc">客户A-Z</option></select></div><div class="history-view-toolbar"><span class="hint">每页</span><select id="histPageSize" class="history-page-size" onchange="setHistoryPageSize(this.value)"><option value="50">50条</option><option value="100">100条</option><option value="200">200条</option></select><span class="hint" style="margin-left:10px">视图</span><button class="history-view-btn" data-history-view="list" onclick="setHistoryView('list')">列表</button><button class="history-view-btn" data-history-view="grid-large" onclick="setHistoryView('grid-large')">图标大</button><button class="history-view-btn" data-history-view="grid-medium" onclick="setHistoryView('grid-medium')">图标中</button><button class="history-view-btn" data-history-view="grid-small" onclick="setHistoryView('grid-small')">图标小</button></div><div id="historyList" class="history-list history-view-list" style="margin-top:10px"></div><div id="historyPager" class="history-pager"></div></div></div></section>
<section id="page-approval" class="page"><div class="card"><div class="card-head"><div><b>报价审核列表</b><div class="hint">待审核报价必须审核通过后才能导出 PDF / Excel 或转订单；已审核报价可反审回待审核。</div></div><div class="btns" style="margin-top:0"><button class="blue" onclick="loadApprovalList()">刷新</button><button class="gray" onclick="setApprovalStatus('pending')">未审核</button><button class="green" onclick="setApprovalStatus('approved')">已审核</button><button class="gray" onclick="showPage('history')">历史报价</button></div></div><div class="card-body"><div class="approval-toolbar"><input id="approvalSearch" oninput="renderApproval()" placeholder="多条件搜索：报价号 / 客户 / 负责人 / 产品"><select id="approvalCustomer" onchange="renderApproval()"><option value="">全部客户</option></select><select id="approvalOwner" onchange="renderApproval()"><option value="">全部负责人</option></select><select id="approvalStatus" onchange="renderApproval()"><option value="pending">待审核</option><option value="approved">已审核</option><option value="rejected">已驳回</option><option value="all">全部状态</option></select><select id="approvalSort" onchange="renderApproval()"><option value="new">最新提交</option><option value="amountDesc">金额高到低</option><option value="customer">客户A-Z</option></select><span id="approvalCount" class="hint">0 条</span></div><div class="pack-calc-hint">审核日志在“审核预览 / 查看日志”弹窗顶部；会记录提交、通过、驳回、反审、审核人、时间、备注，以及数量/倍率/单价/金额修改。</div><div id="approvalList" class="approval-list"></div></div></div></section>
<section id="page-orders" class="page"><div class="card"><div class="card-head"><div><b>订单中心</b><div class="hint">报价单转订单后在这里冻结订单快照，可直接生成 Proforma Invoice / 订单 PDF 与 Excel；出货后再生成 Packing List 和 Commercial Invoice。</div></div><div class="btns" style="margin-top:0"><button class="blue" onclick="loadOrders()">刷新订单</button><button class="red" onclick="clearQuoteOrderTestData()">一键清空报价/订单</button><button class="gray" onclick="showPage('quote')">返回报价单</button></div></div><div class="card-body"><div class="order-toolbar"><input id="orderSearch" oninput="renderOrders()" placeholder="多条件搜索：订单号/报价号/客户/负责人"><select id="orderCustomer" onchange="renderOrders()"><option value="">全部客户</option></select><select id="orderOwner" onchange="renderOrders()"><option value="">全部负责人</option></select><select id="orderCurrency" onchange="renderOrders()"><option value="">全部币种</option><option value="RMB">RMB</option><option value="USD">USD</option><option value="EUR">EUR</option></select><input id="orderDateFrom" type="date" onchange="renderOrders()" title="订单开始日期"><input id="orderDateTo" type="date" onchange="renderOrders()" title="订单结束日期"><select id="orderStatus" onchange="renderOrders()"><option value="">全部状态</option><option value="待确认">待确认</option><option value="已确认">已确认</option><option value="生产中">生产中</option><option value="待出货">待出货</option><option value="部分出货">部分出货</option><option value="已出货">已出货</option><option value="已完成">已完成</option><option value="取消">取消</option><option value="已作废">已作废</option></select><select id="orderSort" onchange="renderOrders()"><option value="new">最新订单</option><option value="amountDesc">金额高到低</option><option value="amountAsc">金额低到高</option><option value="customer">客户A-Z</option></select><span class="hint" id="orderCount">暂无订单</span><button class="gray" onclick="loadOrders()">刷新</button></div><div class="order-grid"><div><div id="orderList" class="order-list"></div></div><div><div id="orderDetail" class="order-detail-empty">选择左侧订单查看详情</div></div></div></div></div></section>

<section id="page-packaging" class="page"><div class="card"><div class="card-head"><div><b>包装资料库</b><div class="hint">按产品型号维护默认箱规、每箱数量、净重、毛重、CBM。订单生成出货批次时会自动带出，实际出货仍可手动修改。</div></div><div class="btns" style="margin-top:0"><button class="blue" onclick="loadPackaging()">刷新包装资料</button><button class="gray" onclick="clearPackagingForm()">新建包装资料</button></div></div><div class="card-body"><div class="pack-calc-hint">优先级：出货批次手动填写 ＞ 包装资料库默认值 ＞ 系统理论计算。拼箱尺寸会在出货批次里按箱号另外填写。</div><div class="packaging-layout"><div><div class="packaging-search"><input id="packSearch" placeholder="搜索型号 / Customer Code / 产品 / 包装方式" oninput="debouncedLoadPackaging()"><button class="gray" onclick="loadPackaging()">搜索</button></div><div id="packagingList" class="list"></div></div><div class="card" style="box-shadow:none"><div class="card-head"><b>包装资料编辑</b><span class="hint">单位建议：尺寸 cm，重量 KG</span></div><div class="card-body"><input type="hidden" id="packId"><div class="row3"><div><label>产品型号 / Product Code</label><input id="packProductCode" placeholder="如 95.01012"></div><div><label>产品名称 / Series</label><input id="packProductName" placeholder="如 LUMI Series"></div><div><label>Customer Code</label><input id="packCustomerCode" placeholder="可为空，按型号通用"></div></div><div class="row3"><div><label>单个净重 KG</label><input id="packUnitNw" type="number" step="0.001"></div><div><label>单个毛重 KG</label><input id="packUnitGw" type="number" step="0.001"></div><div><label>PCS/CTN</label><input id="packPcsCtn" type="number" step="0.01"></div></div><div class="row4"><div><label>外箱 L cm</label><input id="packL" type="number" step="0.01" oninput="packAutoSize()"></div><div><label>外箱 W cm</label><input id="packW" type="number" step="0.01" oninput="packAutoSize()"></div><div><label>外箱 H cm</label><input id="packH" type="number" step="0.01" oninput="packAutoSize()"></div><div><label>外箱尺寸</label><input id="packSize" placeholder="45*35*28cm" oninput="packAutoCbm()"></div></div><div class="row3"><div><label>单箱净重 KG</label><input id="packCtnNw" type="number" step="0.001"></div><div><label>单箱毛重 KG</label><input id="packCtnGw" type="number" step="0.001"></div><div><label>单箱 CBM</label><input id="packCbm" type="number" step="0.0001"></div></div><label>包装方式</label><input id="packMethod" placeholder="如 1PC/inner box, 12PCS/CTN"><label>备注</label><textarea id="packNote" placeholder="特殊包装要求、客户包装、标签等"></textarea><div class="btns"><button class="blue" onclick="savePackaging()">保存包装资料</button><button class="gray" onclick="clearPackagingForm()">清空</button><button class="red" onclick="deletePackaging()">删除</button></div></div></div></div></div></div></section>


<section id="page-settings" class="page">
  <div class="settings-pro">
    <div class="settings-hero">
      <h2>系统设置 PRO</h2>
      <p>V6.8.5：新增报价系统备份与恢复，可备份报价、客户、产品、模板、银行、单证、权限、日志等报价相关数据；恢复前会自动生成安全备份。</p>
    </div>

    <div class="settings-tabs no-print" id="settingsTabs">
      <button class="active" type="button" onclick="showSettingsTab('defaults')">默认配置</button>
      <button type="button" onclick="showSettingsTab('rates')">汇率设置</button>
      <button type="button" onclick="showSettingsTab('templates')">条款模板</button>
      <button type="button" onclick="showSettingsTab('headers')">公司抬头</button>
      <button type="button" onclick="showSettingsTab('banks')">银行信息</button>
      <button type="button" onclick="showSettingsTab('levels')">报价等级</button>
      <button type="button" onclick="showSettingsTab('options')">下拉选项</button>
      <button type="button" onclick="showSettingsTab('docs')">单证模板</button>
      <button type="button" onclick="showSettingsTab('backup');loadQuoteBackups()">备份恢复</button>
    </div>


    <div class="settings-panel" data-settings-panel="rates">
      <div class="settings-grid">
        <div class="settings-card"><div class="settings-card-h"><b>汇率设置</b><span class="hint">老板/管理员设置，普通业务只读取</span></div><div class="settings-card-b"><div class="settings-two"><div><label>USD / RMB 默认汇率</label><input id="sysUsdRate" type="number" min="1" max="20" step="0.0001" placeholder="7.0000"></div><div><label>说明</label><input value="报价页币种切换统一使用这个汇率" readonly></div></div><div class="settings-actions"><button class="green" onclick="saveExchangeRateSettings()">保存汇率</button><button class="gray" onclick="applySystemExchangeRate();alert('已重新应用系统汇率')">重新应用</button></div><div class="settings-inline-hint">说明：权限以 users.php 统一权限中心为准；“修改”可维护表头/银行/付款条款，“管理/设置”才可维护汇率。</div></div></div>
        <div class="settings-card"><div class="settings-card-h"><b>权限说明</b><span class="hint">避免冲突</span></div><div class="settings-card-b"><div class="danger-note">普通业务可以被授权维护公司抬头、银行信息和付款条款，但不能改汇率。汇率建议只给老板/管理员。</div></div></div>
      </div>
    </div>

    <div class="settings-panel active" data-settings-panel="defaults">
      <div class="settings-quick">
        <div class="settings-card"><div class="settings-card-h"><b>默认公司抬头</b><span class="hint">新报价自动使用</span></div><div class="settings-card-b"><select id="defaultHeaderSelect"></select><div class="settings-actions"><button class="green" onclick="saveQuoteDefaults()">保存默认</button><button class="gray" onclick="showSettingsTab('headers')">管理抬头</button></div></div></div>
        <div class="settings-card"><div class="settings-card-h"><b>默认银行信息</b><span class="hint">导出自动使用</span></div><div class="settings-card-b"><select id="defaultBankSelect"></select><div class="settings-actions"><button class="green" onclick="saveQuoteDefaults()">保存默认</button><button class="gray" onclick="showSettingsTab('banks')">管理银行</button></div></div></div>
        <div class="settings-card"><div class="settings-card-h"><b>默认条款模板</b><span class="hint">Payment / Terms</span></div><div class="settings-card-b"><select id="defaultTemplateSelect"></select><div class="settings-actions"><button class="green" onclick="saveQuoteDefaults()">保存默认</button><button class="gray" onclick="showSettingsTab('templates')">管理模板</button></div></div></div>
      </div>
      <div class="settings-card" style="margin-top:14px"><div class="settings-card-h"><b>快速操作</b><span class="hint">保存后新报价自动套用</span></div><div class="settings-card-b"><div class="settings-actions"><button class="green" onclick="saveQuoteDefaults()">保存全部默认配置</button><button class="blue" onclick="applyQuoteDefaults();render();alert('已重新应用默认配置')">立即应用到当前报价</button><button class="gray" onclick="showSettingsTab('templates');clearTemplateForm();setTimeout(()=>$('tName')&&$('tName').focus(),50)">新增条款模板</button></div><div class="settings-inline-hint">说明：默认配置保存在当前浏览器，常用条款、抬头、银行记录保存在数据库。不同电脑如果要同样默认项，需要各自点一次“保存默认”。</div></div></div>
    </div>

    <div class="settings-panel" data-settings-panel="templates">
      <div class="settings-grid">
        <div class="settings-card"><div class="settings-card-h"><b id="templateFormTitle">新增 / 编辑条款模板</b><span class="hint">一格一格填</span></div><div class="settings-card-b"><input type="hidden" id="tId"><label>模板名称</label><input id="tName" placeholder="如 默认报价条款 / EXWORK条款 / PI条款"><div class="settings-two"><div><label>Payment 第一行</label><input id="tPayment1" placeholder="40% Deposit before production"></div><div><label>Payment 第二行</label><input id="tPayment2" placeholder="60% payment before shipment"></div></div><div class="settings-three"><div><label>Price Terms</label><input id="tPriceTerms" placeholder="EXWORK"></div><div><label>Delivery Date</label><input id="tDeliveryDate" placeholder="25-35Days After Confirmed"></div><div><label>Quoted Valid</label><input id="tQuotedValid" placeholder="Within 10 days"></div></div><div class="template-preview-box" id="templatePreview">PI Number 和 Quoted Date 自动取报价编号 / 报价日期。</div><div class="settings-actions"><button class="gray" onclick="clearTemplateForm();setTimeout(()=>$('tName')&&$('tName').focus(),30)">新增模板</button><button class="blue" onclick="saveTemplate()">保存模板</button><button class="green" onclick="saveTemplateAndDefault()">保存并设为默认</button><button class="red" onclick="deleteTemplate()">删除当前模板</button></div><div class="settings-inline-hint">保存后，右侧列表会立即刷新；点右侧“编辑”可回填，点“设默认”可直接作为新报价默认条款。</div></div></div>
        <div class="settings-card"><div class="settings-card-h"><b>条款模板列表</b><div class="settings-actions" style="margin:0"><button class="gray" onclick="clearTemplateForm()">新建</button></div></div><div class="settings-card-b"><div id="templateList" class="settings-list"></div></div></div>
      </div>
    </div>

    <div class="settings-panel" data-settings-panel="headers">
      <div class="settings-grid"><div class="settings-card"><div class="settings-card-h"><b>公司抬头</b><span class="hint">报价 From 区域</span></div><div class="settings-card-b"><input type="hidden" id="hId"><label>抬头名称</label><input id="hName" placeholder="如 Artdon 默认抬头"><label>公司名</label><input id="hCompany" placeholder="Artdon Lighting Limited"><label>From 内容</label><textarea id="hFrom" placeholder="公司地址、电话、邮箱等"></textarea><label>蓝色章内容</label><textarea id="hStamp" placeholder="默认不显示；勾选下方开关后才显示"></textarea><label style="display:flex;align-items:center;gap:8px;margin-top:8px"><input id="hShowStamp" type="checkbox" style="width:auto"> 显示蓝色公司章</label><div class="settings-actions"><button class="gray" onclick="clearHeaderForm()">新增抬头</button><button class="blue" onclick="saveHeader()">保存抬头</button><button class="green" onclick="saveHeaderAndDefault()">保存并设为默认</button><button class="red" onclick="deleteHeader()">删除当前</button></div></div></div><div class="settings-card"><div class="settings-card-h"><b>抬头列表</b><span class="hint">编辑 / 设默认 / 删除</span></div><div class="settings-card-b"><div id="headerList" class="settings-list"></div></div></div></div>
    </div>

    <div class="settings-panel" data-settings-panel="banks">
      <div class="settings-grid"><div class="settings-card"><div class="settings-card-h"><b>银行信息</b><span class="hint">报价页脚 / CI 银行资料</span></div><div class="settings-card-b"><input type="hidden" id="bId"><label>银行名称</label><input id="bName" placeholder="如 默认银行 / USD账户"><label>银行信息</label><textarea id="bText" placeholder="Beneficiary / Bank / Account / Swift..."></textarea><label>银行信息下方合同条款 / 大备注（保存并设为默认后应用所有新报价）</label><div class="contract-terms-hint">用于放合同条款、付款约定、质保、交期、免责条款等。保存并设为默认银行后，所有新报价自动带出；旧报价重新选择该默认银行后也会应用。</div><textarea id="bExtraTerms" placeholder="Quotation Terms / Additional Terms..."></textarea><label>附加条款字体大小</label><select id="bTermsFontSize"><option value="6.5">6.5pt</option><option value="7">7pt</option><option value="7.5">7.5pt</option><option value="8">8pt</option><option value="8.5">8.5pt</option><option value="9">9pt</option><option value="10">10pt</option><option value="11">11pt</option><option value="12">12pt</option></select><div class="settings-actions"><button class="gray" onclick="clearBankForm()">新增银行</button><button class="blue" onclick="saveBank()">保存银行</button><button class="green" onclick="saveBankAndDefault()">保存并设为默认</button><button class="red" onclick="deleteBank()">删除当前</button></div></div></div><div class="settings-card"><div class="settings-card-h"><b>银行列表</b><span class="hint">编辑 / 设默认 / 删除</span></div><div class="settings-card-b"><div id="bankList" class="settings-list"></div></div></div></div>
    </div>

    <div class="settings-panel" data-settings-panel="levels">
      <div class="settings-grid"><div class="settings-card"><div class="settings-card-h"><b>报价等级设置</b><span class="hint">A/B/C 等级与倍率</span></div><div class="settings-card-b"><input type="hidden" id="plId"><div class="settings-three"><div><label>等级名称</label><input id="plName" placeholder="如 A级 / B级"></div><div><label>倍率</label><input id="plMultiplier" type="number" step="0.0001" placeholder="如 1.35"></div><div><label>排序</label><input id="plSort" type="number" value="0"></div></div><label>说明</label><input id="plNote" placeholder="如 常规客户 / 大客户"><div class="settings-two"><label style="display:flex;gap:8px;align-items:center;margin-top:10px"><input id="plDefault" type="checkbox" style="width:auto"> 设为默认报价等级</label><label style="display:flex;gap:8px;align-items:center;margin-top:10px"><input id="plActive" type="checkbox" checked style="width:auto"> 启用</label></div><div class="settings-actions"><button class="gray" onclick="clearPriceLevelForm()">新增等级</button><button class="blue" onclick="savePriceLevel()">保存等级</button><button class="red" onclick="deletePriceLevel()">停用/删除</button></div></div></div><div class="settings-card"><div class="settings-card-h"><b>等级列表</b><span class="hint">点击编辑</span></div><div class="settings-card-b"><div id="priceLevelList" class="settings-list"></div></div></div></div>
    </div>

    <div class="settings-panel" data-settings-panel="options">
      <div class="settings-grid"><div class="settings-card"><div class="settings-card-h"><b>下拉数据设置</b><span class="hint">颜色等选项</span></div><div class="settings-card-b"><input type="hidden" id="optId"><div class="settings-three"><div><label>类型</label><select id="optGroup" onchange="renderOptions()"><option value="color">颜色</option></select></div><div><label>选项值</label><input id="optValue" placeholder="如 White / Black"></div><div><label>显示名称</label><input id="optLabel" placeholder="不填则同选项值"></div></div><div class="settings-two"><div><label>排序</label><input id="optSort" type="number" value="0"></div><div><label>说明</label><input id="optNote" placeholder="备注，可不填"></div></div><div class="settings-actions"><button class="gray" onclick="clearOptionForm()">新增选项</button><button class="blue" onclick="saveOptionItem()">保存选项</button><button class="red" onclick="deleteOptionItem()">停用/删除</button></div></div></div><div class="settings-card"><div class="settings-card-h"><b>选项列表</b><span class="hint">颜色 / 后续可扩展</span></div><div class="settings-card-b"><div id="optionList" class="settings-list"></div></div></div></div>
    </div>

    <div class="settings-panel" data-settings-panel="docs">
      <div class="settings-grid-wide"><div class="settings-card"><div class="settings-card-h"><b>单证模板 / 编号规则</b><span class="hint">Packing List / Commercial Invoice 正式单证设置</span></div><div class="settings-card-b"><div class="doc-template-box"><b>编号规则</b><div class="doc-setting-grid"><div><label>Shipment 前缀</label><input id="docShipmentPrefix" placeholder="SHP"></div><div><label>Packing List 前缀</label><input id="docPlPrefix" placeholder="PL"></div><div><label>Commercial Invoice 前缀</label><input id="docCiPrefix" placeholder="CI"></div><div><label>日期格式</label><select id="docDateFormat"><option value="ymd">260613</option><option value="Ymd">20260613</option><option value="ym">2606</option></select></div><div><label>默认 Port of Loading</label><input id="docPortLoading" placeholder="Zhongshan"></div><div><label>默认 Country of Origin</label><input id="docOrigin" placeholder="China"></div></div></div><div class="doc-template-box"><b>抬头 / 收货方 / 签名</b><label>Seller 公司名</label><input id="docSellerName" placeholder="Artdon Lighting Limited"><label>Seller 信息</label><textarea id="docSellerText" placeholder="公司地址、电话、邮箱等"></textarea><div class="settings-two"><div><label>Buyer 标题</label><input id="docBuyerLabel" placeholder="Buyer / Consignee"></div><div><label>Signature 公司名</label><input id="docSignatureCompany" placeholder="Artdon Lighting Limited"></div></div><label style="display:flex;align-items:center;gap:8px;margin-top:8px"><input id="docShowNotify" type="checkbox" style="width:auto"> 显示 Notify Party</label><label>Notify Party</label><textarea id="docNotifyParty" placeholder="可空"></textarea></div><div class="doc-template-box"><b>PL 列显示</b><div id="docPlColumns" class="doc-checks"></div></div><div class="doc-template-box"><b>CI 列显示</b><div id="docCiColumns" class="doc-checks"></div></div><div class="doc-template-box"><b>页脚 / 银行</b><label style="display:flex;align-items:center;gap:8px;margin-top:8px"><input id="docShowBankCi" type="checkbox" style="width:auto"> CI 显示银行资料</label><label>页脚备注</label><textarea id="docFooterNote"></textarea></div><div class="settings-actions"><button class="blue" onclick="saveDocumentSettings()">保存单证模板</button><button class="gray" onclick="loadDocumentSettings()">重新读取</button></div></div></div></div>
    </div>

    <div class="settings-panel" data-settings-panel="backup">
      <div class="settings-grid">
        <div class="settings-card">
          <div class="settings-card-h"><b>生成备份</b><span class="hint">备份报价系统自己的数据表</span></div>
          <div class="settings-card-b">
            <div class="settings-inline-hint">备份范围：quote_* 数据表、bom_quote_specs 报价关键件设置、报价系统核心文件校验信息。不会覆盖 CRM / BOM / PLM 原始数据。</div>
            <div class="settings-actions">
              <button class="green" onclick="createQuoteBackup()">立即生成备份</button>
              <button class="gray" onclick="loadQuoteBackups()">刷新备份列表</button>
            </div>
            <div id="quoteBackupStatus" class="settings-inline-hint">建议：每次大改报价系统前，先点一次“立即生成备份”。</div>
          </div>
        </div>
        <div class="settings-card">
          <div class="settings-card-h"><b>备份列表</b><span class="hint">点击下载保存到本地</span></div>
          <div class="settings-card-b"><div id="quoteBackupList" class="settings-list"><div class="settings-empty">点击刷新备份列表。</div></div></div>
        </div>
      </div>
      <div class="settings-card" style="margin-top:14px">
        <div class="settings-card-h"><b>恢复备份</b><span class="hint">危险操作，恢复前会自动再备份一次</span></div>
        <div class="settings-card-b">
          <div class="settings-inline-hint">恢复会用备份文件里的报价系统数据覆盖当前 quote_* 表。恢复前系统会自动生成 before_restore 安全备份；CRM、BOM、PLM 原始表不会被恢复覆盖。</div>
          <div class="settings-two">
            <div><label>选择备份 JSON 文件</label><input id="quoteRestoreFile" type="file" accept="application/json,.json"></div>
            <div><label>确认码</label><input id="quoteRestoreConfirm" placeholder="输入 RESTORE_QUOTE 才能恢复"></div>
          </div>
          <div class="settings-actions"><button class="red" onclick="restoreQuoteBackup()">恢复备份</button><button class="gray" onclick="$('quoteRestoreFile').value='';$('quoteRestoreConfirm').value=''">清空选择</button></div>
          <div id="quoteRestoreStatus" class="settings-inline-hint">请只恢复本报价系统导出的 quote_backup_*.json 文件。</div>
        </div>
      </div>
    </div>

  </div>
</section>

<section id="page-documents" class="page"><div class="card"><div class="card-head"><div><b>单证中心</b><div class="hint">按出货批次生成 Packing List 和 Commercial Invoice。PL 与 CI 共用同一个批次数量，避免数量不一致。</div></div><div class="btns" style="margin-top:0"><button class="blue" onclick="loadDocuments()">刷新单证</button><button class="gray" onclick="showPage('orders')">订单中心</button></div></div><div class="card-body"><div class="order-toolbar"><input id="docSearch" oninput="renderDocuments()" placeholder="搜索订单号/客户/PL号/CI号/批次号"><select id="docTypeFilter" onchange="renderDocuments()"><option value="">全部单证</option><option value="pl">Packing List</option><option value="ci">Commercial Invoice</option></select><span class="hint" id="docCount">暂无单证</span></div><div class="pack-calc-hint">规则：先在订单中心创建出货批次，再在这里预览、打印 PDF 或下载 Excel。Packing List 使用箱规/拼箱数据；Commercial Invoice 使用订单单价和本次出货数量。</div><div id="documentList" class="doc-list"></div></div></div></section>
<section id="page-logs" class="page"><div class="card"><div class="card-head"><b>报价日志中心</b><span class="hint">记录保存、删除、导出、客户联动、系统设置、拖拽排序等关键操作</span></div><div class="card-body"><div class="filter-bar"><input id="logKw" placeholder="搜索：报价号/客户/操作/IP/摘要" oninput="debouncedLoadLogs()"><select id="logLevel" onchange="loadLogs()"><option value="">全部级别</option><option value="INFO">INFO</option><option value="WARN">WARN</option><option value="ERROR">ERROR</option></select><input id="logAction" placeholder="操作名，如 save_quote" oninput="debouncedLoadLogs()"><input id="logDateFrom" type="date" onchange="loadLogs()"><input id="logDateTo" type="date" onchange="loadLogs()"><select id="logLimit" onchange="loadLogs()"><option value="100">100条</option><option value="200" selected>200条</option><option value="500">500条</option></select></div><div class="toolbar"><button class="blue" onclick="loadLogs()">刷新日志</button><button class="blue" onclick="testQuoteLogs()">日志自检</button><button class="gray" onclick="exportLogsCsv()">导出CSV</button><button class="gray" onclick="deleteOldLogs(90)">清理90天前</button><button class="red" onclick="clearAllLogs()">清空全部日志</button><span class="result-count" id="logCount"></span></div><div class="hint" style="margin:8px 0">说明：日志会记录操作时间、操作类型、报价号、客户、IP、浏览器、请求摘要、详细JSON、修改前/修改后。导出 PDF/Excel、打开历史报价、拖拽排序也会写入前端操作日志。</div><div id="logList" style="margin-top:10px"></div></div></div></section>

<section id="page-permissions" class="page"><div class="card"><div class="card-head"><b>报价系统权限管理</b><span class="hint">账号来自 PLM / Artdon Office 统一账号，本页只分配报价系统功能权限。</span></div><div class="card-body"><div class="perm-help">说明：账号只读取 PLM / Artdon Office 统一登录账号，不读取邮箱账号；删除只是从报价权限页隐藏，不会删除 PLM/CRM/BOM 原始账号。qiulei / boss / admin / administrator 以及管理员角色默认全权限，避免误锁。</div><div class="perm-toolbar"><input id="permSearch" placeholder="搜索账号/姓名/角色" oninput="renderPermissions()"><select id="permRoleFilter" onchange="renderPermissions()"><option value="">全部角色</option><option value="admin">管理员/老板</option><option value="staff">普通账号</option></select><button class="blue" onclick="loadPermissions()">刷新账号</button><span id="permCount" class="hint"></span></div><div id="permissionTable" class="perm-matrix"><div class="hint" style="padding:16px">请点击刷新账号。</div></div></div></div></section>
</main></div>
<div id="productModal" class="modal"><div class="modal-box"><div class="card-head"><b>更多选择产品/空壳</b><button class="gray" onclick="closeProductModal()">关闭</button></div><div class="filter-bar" style="margin:10px 0"><input id="modalSearch" oninput="renderProductModal()" placeholder="关键词"><select id="modalType" onchange="renderProductModal()"></select><input id="modalSeries" oninput="renderProductModal()" placeholder="系列"><input id="modalPower" oninput="renderProductModal()" placeholder="功率"><input id="modalIp" oninput="renderProductModal()" placeholder="IP"><input id="modalSize" oninput="renderProductModal()" placeholder="尺寸"><input id="modalCutout" oninput="renderProductModal()" placeholder="开孔"><input id="modalPriceMin" type="number" step="0.01" oninput="renderProductModal()" placeholder="最低价"><input id="modalPriceMax" type="number" step="0.01" oninput="renderProductModal()" placeholder="最高价"></div><div class="result-count" id="modalCount"></div><div id="modalProducts" class="modal-grid"></div></div></div>
<div id="partModal" class="modal"><div class="modal-box part-modal-box"><div class="card-head"><b id="partModalTitle">更多选择物料</b><button class="gray" onclick="closePartModal()">关闭</button></div><div class="filter-bar" style="margin:10px 0"><input id="partModalSearch" oninput="renderPartModal()" placeholder="综合搜索：品牌/名称/型号/规格/供应商"><select id="partModalCategory" onchange="renderPartModal()"><option value="">全部分类</option></select><select id="partModalBrand" onchange="renderPartModal()"><option value="">全部品牌</option></select><select id="partModalSupplier" onchange="renderPartModal()"><option value="">全部供应商</option></select><select id="partModalUnit" onchange="renderPartModal()"><option value="">全部单位</option></select><input id="partModalPriceMin" type="number" step="0.01" oninput="renderPartModal()" placeholder="最低单价"><input id="partModalPriceMax" type="number" step="0.01" oninput="renderPartModal()" placeholder="最高单价"><select id="partModalSort" onchange="renderPartModal()"><option value="match">默认匹配</option><option value="priceAsc">价格低到高</option><option value="priceDesc">价格高到低</option><option value="brand">按品牌</option><option value="name">按名称</option><option value="category">按分类</option></select></div><div class="toolbar"><span class="result-count" id="partModalCount"></span><button class="gray" onclick="clearPartModalFilters()">清空筛选</button></div><div id="partModalMaterials" class="part-modal-list"></div></div></div>
<div id="shipmentModal" class="modal"><div class="modal-box" style="width:min(1380px,98vw)"><div class="card-head"><div><b>新增出货批次</b><div class="hint" id="shipmentModalHint">从订单未出货数量生成，箱规可从包装资料库带出后再手动修正。</div></div><button class="gray" onclick="closeShipmentModal()">关闭</button></div><div class="card-body"><div class="ship-top-grid"><div><label>出货批次号</label><input id="shipNo" placeholder="不填自动生成"></div><div><label>出货日期</label><input id="shipDate" type="date"></div><div><label>Packing List No.</label><input id="shipPlNo" placeholder="可后续生成"></div><div><label>Commercial Invoice No.</label><input id="shipCiNo" placeholder="可后续生成"></div><div><label>Shipping Mark</label><input id="shipMark" placeholder="唛头"></div><div><label>Shipping Method</label><input id="shipMethod" placeholder="By sea / By air"></div><div><label>Port of Loading</label><input id="shipPortLoad" placeholder="Zhongshan / Shenzhen"></div><div><label>Port of Destination</label><input id="shipPortDest" placeholder="Destination"></div></div><div class="ship-modal-scroll"><table class="ship-modal-table"><thead><tr><th>型号</th><th>Customer Code</th><th>产品</th><th>订单数量</th><th>已出</th><th>未出</th><th>本次出货</th><th>PCS/CTN</th><th>箱数</th><th>箱规</th><th>N.W.</th><th>G.W.</th><th>CBM</th><th>备注</th></tr></thead><tbody id="shipItemRows"></tbody></table></div><div class="order-subtitle">拼箱 / 箱号明细 <span class="hint">可选。实际拼箱时按箱填写，后续 Packing List 优先使用这里。</span></div><div id="cartonRows"></div><div class="btns"><button class="gray" onclick="addCartonRow()">新增拼箱箱号</button><button class="gray" onclick="recalcShipmentTotals()">重新计算</button></div><div class="ship-summary-box" id="shipSummary">合计：0 CTNS ｜ N.W. 0 KG ｜ G.W. 0 KG ｜ CBM 0</div><div class="modal-actions"><button class="blue" onclick="saveShipment()">保存出货批次</button><button class="gray" onclick="closeShipmentModal()">取消</button></div></div></div></div>

<div id="paymentModal" class="modal"><div class="modal-box" style="width:min(720px,96vw)"><div class="card-head"><div><b>新增收款记录</b><div class="hint" id="payOrderInfo">订单收款</div></div><button class="gray" onclick="closePaymentModal()">关闭</button></div><div class="card-body"><input type="hidden" id="payOrderId"><div class="row3"><div><label>收款类型</label><select id="payType"><option>订金</option><option>尾款</option><option>其它款项</option><option>退款</option></select></div><div><label>收款日期</label><input id="payDate" type="date"></div><div><label>币种</label><input id="payCurrency" placeholder="USD"></div></div><div class="row2"><div><label>收款金额</label><input id="payAmount" type="number" step="0.01" placeholder="0.00"></div><div><label>付款方式</label><input id="payMethod" placeholder="TT / Bank / Cash"></div></div><label>银行水单 / 参考号</label><input id="payBankRef" placeholder="Bank Ref / Receipt No."><label>备注</label><textarea id="payNote" placeholder="订金、尾款、客户付款说明等"></textarea><div class="btns"><button class="blue" onclick="saveOrderPayment()">保存收款</button><button class="gray" onclick="closePaymentModal()">取消</button></div></div></div></div>
<div id="orderDocModal" class="modal"><div class="modal-box doc-modal-box"><div class="card-head"><div><b id="orderDocTitle">单证预览</b><div class="hint" id="orderDocHint">Packing List / Commercial Invoice</div></div><div class="doc-modal-actions"><a id="orderDocExcel" class="btn-link" target="_blank" style="background:#059669;color:#fff;border-radius:9px;padding:8px 12px;text-decoration:none">下载 Excel</a><a id="orderDocPdf" class="btn-link" target="_blank" style="background:#2563eb;color:#fff;border-radius:9px;padding:8px 12px;text-decoration:none">PDF 打印版</a><button class="blue" onclick="printOrderDoc()">打印 / 另存为 PDF</button><button class="gray" onclick="closeOrderDoc()">关闭</button></div></div><div class="card-body doc-modal-body"><iframe id="orderDocFrame" class="doc-frame"></iframe></div></div></div>

<div id="piOrderModal" class="modal pi-order-modal"><div class="modal-box"><div class="card-head"><div><b>PROFORMA INVOICE / 转订单前确认</b><div class="hint">报价转订单前可先改客户、增减项目、修改数量和单价。生成后订单独立保存，不影响原报价。</div></div><button class="gray" onclick="closePiOrderModal()">关闭</button></div><div class="card-body"><div class="contract-terms-hint"><b>注意：</b>点击“一键转订单”会自动把抬头切换为 <b>PROFORMA INVOICE</b>。这里修改的是即将生成的订单明细；客户临时减少、增加项目或改数量，都先在这里处理。</div><div class="pi-order-grid"><div><label>订单号</label><input id="piOrderNo"></div><div><label>订单日期</label><input id="piOrderDate" type="date"></div><div><label>客户名称</label><input id="piOrderCustomer"></div><div><label>币种</label><input id="piOrderCurrency" readonly></div></div><div class="pi-order-table-wrap"><table class="pi-order-table"><thead><tr><th style="width:46px">#</th><th style="width:110px">Customer Code</th><th style="width:135px">型号</th><th style="width:210px">产品/项目</th><th>Specification / 说明</th><th style="width:82px">颜色</th><th style="width:95px">数量</th><th style="width:105px">单价</th><th style="width:120px">金额</th><th style="width:70px">操作</th></tr></thead><tbody id="piOrderRows"></tbody></table></div><div class="btns"><button class="gray" onclick="piOrderAddBlankItem()">增加一行</button><button class="gray" onclick="piOrderRecalc()">重新计算</button></div><div class="pi-order-summary"><span id="piOrderSummary">合计：0 PCS ｜ 0.00</span><span>确认后生成订单，标题/单据状态为 PROFORMA INVOICE</span></div><label>订单备注 / 合同补充说明</label><textarea id="piOrderNote" style="min-height:90px" placeholder="例如客户临时调整、特殊包装、交货条款等"></textarea><div class="btns" style="justify-content:flex-end"><button class="gray" onclick="closePiOrderModal()">取消</button><button class="blue" onclick="confirmPiOrderConvert()">确认生成订单</button></div></div></div></div>

<script>

// V6.7.0：报价单预览缩放，只改变页面预览，不影响 PDF / Excel。
let PREVIEW_ZOOM = Number(localStorage.getItem('artdon_quote_preview_zoom') || '1');
function clampPreviewZoom(v){v=Number(v||1); if(!isFinite(v)) v=1; return Math.max(0.35, Math.min(2.2, v));}
function applyPreviewZoom(){
  PREVIEW_ZOOM = clampPreviewZoom(PREVIEW_ZOOM);
  let box = $('quotePreviewBody');
  if(box) box.style.setProperty('--quote-preview-scale', PREVIEW_ZOOM);
  let label = $('previewZoomLabel');
  if(label) label.textContent = Math.round(PREVIEW_ZOOM * 100) + '%';
}
function setPreviewZoom(v){PREVIEW_ZOOM = clampPreviewZoom(v); localStorage.setItem('artdon_quote_preview_zoom', String(PREVIEW_ZOOM)); applyPreviewZoom();}
function adjustPreviewZoom(delta){setPreviewZoom(PREVIEW_ZOOM + Number(delta||0));}
function resetPreviewZoom(){setPreviewZoom(1);}
function fitPreviewWidth(){
  let box = $('quotePreviewBody');
  if(!box) return;
  let paper = box.querySelector('.paper');
  if(!paper){setPreviewZoom(1); return;}
  let old = PREVIEW_ZOOM;
  box.style.setProperty('--quote-preview-scale', 1);
  let w = paper.getBoundingClientRect().width || 794;
  PREVIEW_ZOOM = old;
  let target = (box.clientWidth - 28) / w;
  setPreviewZoom(target);
}
window.addEventListener('resize', () => { if(localStorage.getItem('artdon_quote_preview_auto_fit')==='1') fitPreviewWidth(); });

let AUTH={user:null,permissions:{},logged_in:0},PERM_USERS=[];
let QUOTE_LAST_CURRENCY='USD';
let DB={customers:[],products:[],headers:[],banks:[],templates:[],quotes:[],materials:[],orders:[]}, S={customer:null,product:null,header:null,bank:null,template:null,parts:{},items:[],editingIndex:-1,partTab:'led',currentQuoteId:0,currentApprovalStatus:'new'};let CUSTOMER_BATCH=false;let CRM_CUSTOMER_AUTO_SYNC_TIMER=null;let DASH_LOADING_ORDERS=false,DASH_ORDERS_LOADED=false,DASH_LOADING_DOCS=false,DASH_DOCS_LOADED=false;let DOCUMENTS=[];let DOC_SETTINGS=null;let productView='gallery', partModalState={k:'',name:'',cats:''};const $=id=>document.getElementById(id), money=n=>(Number(n)||0).toFixed(2), esc=s=>String(s??'').replaceAll('&','&amp;').replaceAll('<','&lt;').replaceAll('>','&gt;'), jsq=s=>String(s??'').replaceAll('\\','\\\\').replaceAll("'","\\'").replaceAll('<','\\x3C');
function customerDbId(c){let id=String(c?.id||'');return /^\d+$/.test(id)?Number(id):null}
function quoteTermsFontSizeValue(v){v=Number(v||7.5);if(!isFinite(v))v=7.5;if(v<6)v=6;if(v>12)v=12;return Math.round(v*10)/10}

let historyView='list';try{historyView=localStorage.getItem('artdon_quote_history_view')||'list'}catch(e){}
let historyPage=1, historyPageSize=50;try{historyPageSize=Number(localStorage.getItem('artdon_quote_history_page_size')||50)||50}catch(e){}if(![50,100,200].includes(historyPageSize))historyPageSize=50;
function setHistoryView(v){historyView=v||'list';try{localStorage.setItem('artdon_quote_history_view',historyView)}catch(e){}renderHistory(true)}
function setHistoryPageSize(v){historyPageSize=Number(v)||50;if(![50,100,200].includes(historyPageSize))historyPageSize=50;historyPage=1;try{localStorage.setItem('artdon_quote_history_page_size',historyPageSize)}catch(e){}renderHistory(true)}
function setHistoryPage(p){historyPage=Math.max(1,Number(p)||1);renderHistory(true)}
function updateHistoryViewButtons(){document.querySelectorAll('[data-history-view]').forEach(b=>b.classList.toggle('active',b.dataset.historyView===historyView));if($('histPageSize'))$('histPageSize').value=String(historyPageSize)}
function renderHistoryPager(total, shown){let totalPages=Math.max(1,Math.ceil(total/historyPageSize));if(historyPage>totalPages)historyPage=totalPages;let start=total?((historyPage-1)*historyPageSize+1):0,end=Math.min(total,historyPage*historyPageSize);if($('histCount'))$('histCount').textContent='共 '+total+' / '+DB.quotes.length+' 份报价 ｜ 第 '+historyPage+'/'+totalPages+' 页 ｜ 本页 '+shown+' 条';let nums=[];let a=Math.max(1,historyPage-2),b=Math.min(totalPages,historyPage+2);if(a>1)nums.push(1);if(a>2)nums.push('...');for(let i=a;i<=b;i++)nums.push(i);if(b<totalPages-1)nums.push('...');if(b<totalPages)nums.push(totalPages);let html='<button '+(historyPage<=1?'disabled':'')+' onclick="setHistoryPage(1)">首页</button><button '+(historyPage<=1?'disabled':'')+' onclick="setHistoryPage('+(historyPage-1)+')">上一页</button>';html+=nums.map(n=>n==='...'?'<span class="hint">...</span>':'<button class="'+(n===historyPage?'active':'')+'" onclick="setHistoryPage('+n+')">'+n+'</button>').join('');html+='<button '+(historyPage>=totalPages?'disabled':'')+' onclick="setHistoryPage('+(historyPage+1)+')">下一页</button><button '+(historyPage>=totalPages?'disabled':'')+' onclick="setHistoryPage('+totalPages+')">末页</button><span class="hint">显示 '+start+'-'+end+' / '+total+'</span>';if($('historyPager'))$('historyPager').innerHTML=total?html:''}
function firstQuoteImage(items,p){let arr=Array.isArray(items)?items:[];for(let it of arr){let img=quoteDirectImageUrl(it?.product?.image_display||it?.product?.image||it?.image||'');if(img)return img}return quoteDirectImageUrl(p?.image_display||p?.image||'')}
function historyProductImage(it){let p=(it&&it.product)||it||{};return quoteDirectImageUrl(p.image_display||p.web_image_url||p.cover_image_url||p.source_image_url||p.product_image||p.main_image||p.image_path||p.image||it?.image||'')}
function historyProductModel(it){let p=(it&&it.product)||it||{};let v=p.code||p.model||p.model_no||p.manufacturer_code||p.factory_model||it?.manufacturer_code||it?.factory_model||it?.product_code||p.name||it?.customer_code||'';return String(v||'').trim()||'未填型号'}
function historyProductSub(it){let p=(it&&it.product)||it||{};let arr=[p.name||p.series||'',it?.customer_code?('客户 '+it.customer_code):'',it?.color||p.color||''].filter(x=>String(x||'').trim());return arr.join(' ｜ ')}
function historyProductMiniListHtml(items,p,qid){let arr=Array.isArray(items)&&items.length?items.slice(0,4):((p&&Object.keys(p).length)?[{product:p}]:[]);if(!arr.length)return '<div class="history-product-empty">暂无产品图片/型号</div>';return arr.map((it,i)=>{let img=historyProductImage(it), model=historyProductModel(it), sub=historyProductSub(it);let pic=img?`<img src="${esc(img)}" loading="lazy" decoding="async" referrerpolicy="no-referrer">`:`<span>${esc(String(model||'P').slice(0,1).toUpperCase())}</span>`;return `<div class="history-product-mini" title="${esc(model+(sub?' ｜ '+sub:''))}" onclick="loadQuote(${qid})"><div class="history-product-pic">${pic}</div><div class="history-product-model">${esc(model)}</div>${sub?`<div class="history-product-sub">${esc(sub)}</div>`:''}</div>`}).join('')}
function historyCardHtml(q,c,p,items,itemText){let img=firstQuoteImage(items,p), title=`${esc(q.quote_no)} ｜ ${esc(c.company||'未选客户')}`, meta=`${esc(q.quote_date)} ｜ ${esc(c.country||'')} ｜ ${esc(itemText)} ｜ ${esc(q.user_name||'boss')} ｜ ${esc(q.currency)} ${money(q.amount)}`;let thumb=img?`<img src="${esc(img)}" alt="" loading="lazy" decoding="async">`:`<div class="history-empty-thumb">${esc(String(c.company||q.quote_no||'Q').slice(0,1).toUpperCase())}</div>`;let products=historyProductMiniListHtml(items,p,q.id);return `<div class="history-quote-card"><div class="history-card-badge">${quoteApprovalBadge(q)}</div><div class="history-thumb" onclick="loadQuote(${q.id})">${thumb}</div><div class="history-card-body"><div class="history-list-main"><div class="history-card-title" onclick="loadQuote(${q.id})">${title}</div><small class="history-meta">${meta}</small><div class="history-actions"><button class="blue" onclick="loadQuote(${q.id})">打开</button><button class="gray" onclick="openHistoryExport(${q.id},'pdf')">PDF</button><button class="gray" onclick="openHistoryExport(${q.id},'excel')">Excel</button><button class="gray hide-small" onclick="copyQuote(${q.id})">复制</button><button class="red hide-small" onclick="deleteQuote(${q.id})">删除</button></div></div><div class="history-product-strip">${products}</div></div></div>`}
function customerSourceText(c){return c?.source==='crm'?'CRM':'报价'}
function quoteSsoRedirect(){const back=location.pathname+location.search+location.hash;location.replace('login.php?redirect='+encodeURIComponent(back))}
async function api(action,data){let r=await fetch('quote_api.php?action='+action,{method:data?'POST':'GET',headers:{'Content-Type':'application/json'},credentials:'same-origin',cache:'no-store',body:data?JSON.stringify(data):null});let tx=await r.text(),j;try{j=JSON.parse(tx)}catch(e){if(r.status===401){quoteSsoRedirect();throw new Error('AUTH_REDIRECT')}throw new Error('接口返回不是JSON：'+tx.slice(0,240))}if(r.status===401||j.auth_required||j.login_required||j.need_login){quoteSsoRedirect();throw new Error('AUTH_REDIRECT')}if(!j.ok){alert(j.msg||j.error||'请求失败');throw new Error(j.msg||j.error||'请求失败')}return j.data}
async function clientLog(log_action,summary,detail={},level='INFO'){try{let payload=Object.assign({log_action,event:summary,summary,level,quote_no:($('quoteNo')?.value||''),customer_name:(S.customer?.company||''),page:localStorage.getItem('artdon_quote_current_page')||''},detail||{});await fetch('quote_api.php?action=log_event',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(payload)});}catch(e){}}

function hasPerm(k){if(!k)return true;let p=AUTH.permissions||{};if(k==='settings_access')return !!(p.settings_manage||p.doc_settings_manage||p.rate_manage);if(k==='doc_settings_manage')return !!(p.doc_settings_manage||p.settings_manage);if(k==='rate_manage')return !!(p.rate_manage||p.settings_manage);return !!p[k]}
function showLogin(msg=''){document.body.classList.add('need-login');if($('loginErr'))$('loginErr').textContent=msg||'';setTimeout(()=>{$('loginUser')?.focus()},80)}
function hideLogin(){document.body.classList.remove('need-login');if($('loginErr'))$('loginErr').textContent=''}
function doLogin(){quoteSsoRedirect()}
function doLogout(){location.href='logout.php'}
async function checkAuth(){let d=await api('auth_status');AUTH.logged_in=!!d.logged_in;AUTH.user=d.user||null;AUTH.permissions=d.permissions||(AUTH.user?AUTH.user.permissions:{})||{};if(!AUTH.logged_in){quoteSsoRedirect();return false;}hideLogin();updateAuthUi();return true;}
function updateAuthUi(){let u=AUTH.user||{};if($('meBadge'))$('meBadge').textContent=(u.display_name||u.username||'已登录')+(u.role?' · '+u.role:'');['topPdfBtn','topExcelBtn'].forEach(id=>{if($(id))$(id).classList.toggle('is-disabled-by-perm',!hasPerm('export_pdf_excel'))});document.querySelectorAll('[data-perm]').forEach(el=>el.classList.toggle('no-perm',!hasPerm(el.dataset.perm)));document.querySelectorAll('[data-require-perm]').forEach(el=>el.classList.toggle('is-disabled-by-perm',!hasPerm(el.dataset.requirePerm)));applyRateLock();}
function pagePerm(p){return {quote:'can_access',products:'product_view',customers:'customer_view',materials:'material_view',history:'history_view',approval:'quote_review_view',orders:'order_convert',packaging:'order_convert',documents:'order_convert',settings:'settings_access',permissions:'permission_manage',logs:'log_view'}[p]||'can_access'}

function today(){return new Date().toISOString().slice(0,10)}
function quoteDateCompact(){let d=($('quoteDate')?.value||today()).replaceAll('-','');return d.length>=8?d.slice(2):today().replaceAll('-','').slice(2)}
function customerCode(){return String(S.customer?.code||'').trim().replace(/[^A-Za-z0-9]/g,'').toUpperCase()}
function escReg(s){return String(s).replace(/[.*+?^${}()|[\]\\]/g,'\\$&')}
function quoteNoPrefix(){return 'AT-'+quoteDateCompact()+customerCode()}
function normalizeQuoteNoNoNested(no){
  no=String(no||'').trim().replace(/-V\d+$/i,'');
  let m=no.match(/^(AT-\d{6}[A-Z0-9]+)((?:-\d{2})+)$/i);
  if(m){let parts=[...m[2].matchAll(/-(\d{2})/g)].map(x=>x[1]);return m[1]+(parts.length?'-'+parts[0]:'');}
  return no;
}
function quoteNoSerial(no,base){
  no=normalizeQuoteNoNoNested(no);
  if(no===base)return 0;
  let m=no.match(new RegExp('^'+escReg(base)+'-(\\d{2})$'));
  return m?Number(m[1]||0):-1;
}
function qno(){
  let base=quoteNoPrefix();
  let has=false,max=-1;
  (DB.quotes||[]).forEach(q=>{let n=quoteNoSerial(q.quote_no,base);if(n>=0){has=true;max=Math.max(max,n);}});
  if(!has)return base;
  return base+'-'+String(max+1).padStart(2,'0');
}
function quoteNoForOrder(no){return normalizeQuoteNoNoNested(no||qno())}
function updateQuoteNo(){if($('quoteNo')){$('quoteNo').value=qno();render()}}
function now(){return new Date().toLocaleString('zh-CN',{hour12:false})}
async function load(){if(!(await checkAuth()))return;DB=await api('init');if(DB.me){AUTH.user=DB.me;AUTH.permissions=DB.permissions||DB.me.permissions||AUTH.permissions||{};}updateAuthUi();try{await loadDocumentSettings()}catch(e){}initDefaults();bind();newQuote();renderCore();restoreLastPage();restoreQuoteConfigCollapsed();restoreTopDashCollapsed();startCrmCustomerAutoSync();startQuoteRuntimeAutoSync()}
function initDefaults(){applySystemExchangeRate();applyQuoteDefaults()}

function systemExchangeRate(){let v=Number(DB?.system_settings?.exchange_rate_usd||DB?.system_settings?.usd_rate||7);if(!isFinite(v)||v<=0)v=7;return Math.round(v*10000)/10000}
function applySystemExchangeRate(){let v=systemExchangeRate();if($('topRate'))$('topRate').value=v.toFixed(4);if($('rate') && (cur()!=='RMB'||!Number($('rate').value||0)))$('rate').value=v.toFixed(4);if($('sysUsdRate'))$('sysUsdRate').value=v.toFixed(4);applyRateLock()}
function applyRateLock(){let locked=!hasPerm('rate_manage');['rate','topRate','sysUsdRate'].forEach(id=>{let el=$(id);if(!el)return;el.readOnly=locked;el.disabled=(id==='sysUsdRate')?locked:false;el.title=locked?'汇率由老板/管理员在系统设置里维护':'可维护系统汇率';});}
async function saveExchangeRateSettings(){if(!hasPerm('rate_manage')){alert('当前账号没有权限：rate_manage');return;}let v=Number($('sysUsdRate')?.value||0);if(!isFinite(v)||v<=0){alert('请填写正确汇率');return;}await api('save_exchange_rate',{exchange_rate_usd:v});DB=await api('init');applySystemExchangeRate();handleRateChange();alert('汇率已保存并应用：'+v.toFixed(4));}
function canDocSettings(){return hasPerm('doc_settings_manage')||hasPerm('settings_manage')||hasPerm('quote_edit')}
function getQuoteDefaults(){try{return JSON.parse(localStorage.getItem('artdon_quote_defaults')||'{}')}catch(e){return {}}}
function applyQuoteDefaults(){let d=getQuoteDefaults();S.header=DB.headers.find(x=>String(x.id)===String(d.header_id))||DB.headers[0]||null;S.bank=DB.banks.find(x=>String(x.id)===String(d.bank_id))||DB.banks[0]||null;S.template=DB.templates.find(x=>String(x.id)===String(d.template_id))||DB.templates[0]||null;syncDefaultSettingSelects()}
function saveQuoteDefaults(){let d={header_id:$('defaultHeaderSelect')?.value||'',bank_id:$('defaultBankSelect')?.value||'',template_id:$('defaultTemplateSelect')?.value||''};localStorage.setItem('artdon_quote_defaults',JSON.stringify(d));applyQuoteDefaults();render();alert('默认配置已保存')}
function syncDefaultSettingSelects(){if(!$('defaultHeaderSelect'))return; $('defaultHeaderSelect').value=S.header?.id||'';$('defaultBankSelect').value=S.bank?.id||'';$('defaultTemplateSelect').value=S.template?.id||'';}
function syncQdocSelects(){if(!$('qdocHeaderSelect'))return;$('qdocHeaderSelect').value=S.header?.id||'';$('qdocTemplateSelect').value=S.template?.id||'';$('qdocBankSelect').value=S.bank?.id||'';}
let qdocActiveTab='header';
function showQdocTab(tab){qdocActiveTab=tab||'header';document.querySelectorAll('[data-qdoc-tab]').forEach(b=>b.classList.toggle('active',b.dataset.qdocTab===qdocActiveTab));document.querySelectorAll('.qdoc-pane').forEach(p=>p.classList.remove('active'));let id=qdocActiveTab==='terms'?'qdocPaneTerms':qdocActiveTab==='bank'?'qdocPaneBank':'qdocPaneHeader';$(id)?.classList.add('active');}
function renderQdocQuickSettings(){if(!$('quoteDocQuick'))return;syncQdocSelects();fillQdocForms();showQdocTab(qdocActiveTab||'header');}
function fillQdocForms(){let h=S.header||{},b=S.bank||{},t=S.template||{};if($('qhId')){$('qhId').value=h.id||'';$('qhName').value=h.name||'';$('qhCompany').value=h.company||'';$('qhFrom').value=h.from_text||'';$('qhStamp').value=h.stamp||'';$('qhShowStamp').checked=!!Number(h.show_stamp||0);}if($('qbId')){$('qbId').value=b.id||'';$('qbName').value=b.name||'';$('qbText').value=b.text||'';if($('qbExtraTerms'))$('qbExtraTerms').value=b.extra_terms||b.terms_text||'';if($('qbTermsFontSize'))$('qbTermsFontSize').value=String(quoteTermsFontSizeValue(b.extra_terms_font_size||b.terms_font_size||7.5));}if($('qtId')){$('qtId').value=t.id||'';$('qtName').value=t.name||'';let f=parseTemplateTerms(t.terms_json||'');$('qtPayment1').value=f.payment1||'';$('qtPayment2').value=f.payment2||'';$('qtPriceTerms').value=f.price_terms||'';$('qtDeliveryDate').value=f.delivery_date||'';$('qtQuotedValid').value=f.quoted_valid||'';updateQdocTermsPreview();}}
function qdocSelectChanged(type){if(type==='header'){S.header=(DB.headers||[]).find(x=>String(x.id)===String($('qdocHeaderSelect').value))||null;showQdocTab('header');}if(type==='template'){S.template=(DB.templates||[]).find(x=>String(x.id)===String($('qdocTemplateSelect').value))||null;showQdocTab('terms');}if(type==='bank'){S.bank=(DB.banks||[]).find(x=>String(x.id)===String($('qdocBankSelect').value))||null;showQdocTab('bank');}fillQdocForms();render();}
function qdocTemplateTermsJsonFromForm(){let arr=[['PI Number:','QTNO'],['Payment:',$('qtPayment1')?.value||''],['',$('qtPayment2')?.value||''],['Price Terms:',$('qtPriceTerms')?.value||''],['Quoted Date:','DATE'],['Delivery Date:',$('qtDeliveryDate')?.value||''],['Quoted Valid',$('qtQuotedValid')?.value||'']];return JSON.stringify(arr.filter(r=>r[0]||r[1]));}
function updateQdocTermsPreview(){if(!$('qdocTermsPreview'))return;$('qdocTermsPreview').innerHTML=`Payment: ${esc($('qtPayment1')?.value||'-')}<br>${$('qtPayment2')?.value?esc($('qtPayment2').value)+'<br>':''}Price Terms: ${esc($('qtPriceTerms')?.value||'-')}<br>Delivery Date: ${esc($('qtDeliveryDate')?.value||'-')}<br>Quoted Valid: ${esc($('qtQuotedValid')?.value||'-')}`;}
function newQdocHeader(){S.header={};if($('qdocHeaderSelect'))$('qdocHeaderSelect').value='';fillQdocForms();$('qhName')?.focus();}
function newQdocBank(){S.bank={};if($('qdocBankSelect'))$('qdocBankSelect').value='';fillQdocForms();$('qbName')?.focus();}
function newQdocTemplate(){S.template={};if($('qdocTemplateSelect'))$('qdocTemplateSelect').value='';if($('qtId')){$('qtId').value='';$('qtName').value='';let f=defaultTermsForm();$('qtPayment1').value=f.payment1;$('qtPayment2').value=f.payment2;$('qtPriceTerms').value=f.price_terms;$('qtDeliveryDate').value=f.delivery_date;$('qtQuotedValid').value=f.quoted_valid;updateQdocTermsPreview();}$('qtName')?.focus();}
async function saveQdocHeader(setDefault=false){if(!canDocSettings()){alert('当前账号没有权限：doc_settings_manage');return;}let name=($('qhName')?.value||'').trim();if(!name){alert('请填写抬头名称');return;}let res=await api('save_header',{id:$('qhId').value,name,company:$('qhCompany').value,from_text:$('qhFrom').value,stamp:$('qhStamp').value,show_stamp:$('qhShowStamp')?.checked?1:0});DB=await api('init');S.header=(DB.headers||[]).find(x=>String(x.id)===String(res.id))||(DB.headers||[]).find(x=>x.name===name)||S.header;if(setDefault&&S.header?.id)setQuoteDefaultId('header',S.header.id);renderAll();alert(setDefault?'抬头已保存并设为默认':'抬头已保存并应用');}
async function saveQdocBank(setDefault=false){if(!canDocSettings()){alert('当前账号没有权限：doc_settings_manage');return;}let name=($('qbName')?.value||'').trim();if(!name){alert('请填写银行名称');return;}let res=await api('save_bank',{id:$('qbId').value,name,text:$('qbText').value,extra_terms:$('qbExtraTerms')?.value||'',extra_terms_font_size:quoteTermsFontSizeValue($('qbTermsFontSize')?.value||7.5)});DB=await api('init');S.bank=(DB.banks||[]).find(x=>String(x.id)===String(res.id))||(DB.banks||[]).find(x=>x.name===name)||S.bank;if(setDefault&&S.bank?.id)setQuoteDefaultId('bank',S.bank.id);renderAll();alert(setDefault?'银行信息已保存并设为默认':'银行信息已保存并应用');}
async function saveQdocTemplate(setDefault=false){if(!canDocSettings()){alert('当前账号没有权限：doc_settings_manage');return;}let name=($('qtName')?.value||'').trim();if(!name){alert('请填写条款模板名称');return;}let res=await api('save_template',{id:$('qtId').value,name,terms_json:qdocTemplateTermsJsonFromForm()});DB=await api('init');S.template=(DB.templates||[]).find(x=>String(x.id)===String(res.id))||(DB.templates||[]).find(x=>x.name===name)||S.template;if(setDefault&&S.template?.id)setQuoteDefaultId('template',S.template.id);renderAll();alert(setDefault?'条款模板已保存并设为默认':'条款模板已保存并应用');}
['qtPayment1','qtPayment2','qtPriceTerms','qtDeliveryDate','qtQuotedValid'].forEach(id=>setTimeout(()=>$(id)?.addEventListener('input',()=>{updateQdocTermsPreview()}),0));
['qbText','qbExtraTerms','qbTermsFontSize'].forEach(id=>setTimeout(()=>$(id)?.addEventListener(id==='qbTermsFontSize'?'change':'input',()=>{if(!S.bank)S.bank={}; if(id==='qbText')S.bank.text=$(id)?.value||''; if(id==='qbExtraTerms')S.bank.extra_terms=$(id)?.value||''; if(id==='qbTermsFontSize')S.bank.extra_terms_font_size=quoteTermsFontSizeValue($(id)?.value||7.5); render();}),0));
function bind(){
  setTimeout(()=>{$('optGroup')?.addEventListener('change',renderOptions)},0);
  ['quoteNo','quoteStatus'].forEach(id=>$(id)?.addEventListener('input',()=>{render()}));
  ['qty','manualPrice','moq','customerCode','color','beamAngle','cct','cri','ip','extraSpec','remark1','remark2','remark3','remark4','priceMultiplierCustom'].forEach(id=>$(id)?.addEventListener('input',()=>{clampCompactInputs();updatePriceLevelHint();syncEditingItemFromForm();render()}));
  $('priceLevel')?.addEventListener('change',()=>{syncPriceMultiplierFromLevel(true);if(S.editingIndex>=0){$('manualPrice').value='';}updatePriceLevelHint();syncEditingItemFromForm();render()});
  $('priceLevel')?.addEventListener('input',()=>{syncPriceMultiplierFromLevel(true);if(S.editingIndex>=0){$('manualPrice').value='';}updatePriceLevelHint();syncEditingItemFromForm();render()});
  $('currency')?.addEventListener('change',()=>{handleCurrencyChange($('currency').value)});
  $('beamAngle')?.addEventListener('blur',()=>{normalizeBeamAngleField();syncEditingItemFromForm();render()});
  $('rate')?.addEventListener('input',()=>{handleRateChange()});
  $('quoteDate')?.addEventListener('input',updateQuoteNo);
}
function showPage(p){
  if(!$('page-'+p)) p='quote';
  let need=pagePerm(p); if(!hasPerm(need)){alert('当前账号没有权限打开：'+p); p='quote';}
  localStorage.setItem('artdon_quote_current_page', p);
  try{document.documentElement.dataset.startPage=p}catch(e){}
  document.querySelectorAll('.page').forEach(x=>x.classList.remove('active'));
  $('page-'+p).classList.add('active');
  let pageNames={quote:'报价单',products:'产品库',customers:'客户库',materials:'物料源',history:'历史报价',approval:'报价审核',orders:'订单中心',packaging:'包装资料库',documents:'单证中心',settings:'系统设置',permissions:'权限管理',logs:'日志中心'};
  document.querySelectorAll('.nav button').forEach(b=>b.classList.toggle('active',b.textContent.includes(pageNames[p])));
  document.querySelectorAll('#quoteFuncNav [data-page]').forEach(b=>b.classList.toggle('active',b.dataset.page===p));
  renderCurrentPage(p);
  if(p==='orders')loadOrders();
  if(p==='packaging')loadPackaging();
  if(p==='documents')loadDocuments();
  if(p==='logs')loadLogs();
  if(p==='permissions')loadPermissions();
  clientLog('open_page','打开页面：'+p,{target_page:p});
}
function restoreLastPage(){
  let p=localStorage.getItem('artdon_quote_current_page')||'quote';
  if(!$('page-'+p)) p='quote';
  showPage(p);
}

function setSelectOptions(id, values, firstText='全部'){
  let el=$(id); if(!el) return;
  let old=el.value;
  let arr=[...new Set(values.filter(v=>String(v||'').trim()).map(v=>String(v).trim()))].sort((a,b)=>a.localeCompare(b,'zh'));
  el.innerHTML='<option value="">'+firstText+'</option>'+arr.map(v=>`<option value="${esc(v)}">${esc(v)}</option>`).join('');
  if(arr.includes(old)) el.value=old;
}
function optionItems(group){let arr=(DB.options||[]).filter(o=>String(o.group_key||'')===group);return arr.length?arr:[]}
function colorItems(){let arr=optionItems('color');return arr.length?arr:[{id:'White',value:'White',label:'White'},{id:'Black',value:'Black',label:'Black'}]}
function fillOptionSelect(id,items,def=''){
  let el=$(id); if(!el)return; let old=el.value||def||'';
  el.innerHTML=items.map(o=>`<option value="${esc(o.value||o.label||'')}">${esc(o.label||o.value||'')}</option>`).join('');
  let vals=items.map(o=>String(o.value||o.label||''));
  if(vals.includes(old)) el.value=old; else if(def&&vals.includes(def)) el.value=def; else if(vals.length) el.value=vals[0];
}
function quoteLevels(){let arr=DB.price_levels||[];return arr.length?arr:[{id:'default',name:'默认',multiplier:1,is_default:1,note:'未设置'}]}
function selectedPriceLevel(){let id=$('priceLevel')?.value||'';let arr=quoteLevels();return arr.find(x=>String(x.id)===String(id))||arr.find(x=>Number(x.is_default||0))||arr[0]||{name:'默认',multiplier:1}}
function priceMultiplier(){let custom=Number($('priceMultiplierCustom')?.value||0);if(custom>0)return custom;let m=Number(selectedPriceLevel().multiplier||1);return m>0?m:1}
function syncPriceMultiplierFromLevel(force=false){let el=$('priceMultiplierCustom');if(!el)return;let m=Number(selectedPriceLevel().multiplier||1)||1;if(force||!Number(el.value||0))el.value=m.toFixed(2)}
function adjustPriceMultiplier(delta){let el=$('priceMultiplierCustom');if(!el)return;let v=Number(el.value||priceMultiplier()||1)+Number(delta||0);if(v<0)v=0;el.value=v.toFixed(2);if(S.editingIndex>=0){$('manualPrice').value='';}updatePriceLevelHint();syncEditingItemFromForm();render()}
function updatePriceLevelHint(){let el=$('priceLevelHint');if(el){let base=currentEditorBaseCost();el.value='自动：'+cur()+' '+money(base)+' × '+Number(priceMultiplier()).toFixed(2)+' = '+cur()+' '+money(base*priceMultiplier())}}
function productText(p){return [p.name,p.code,p.category,p.series,p.size,p.cutout,p.power,p.ip,p.color,p.source,p.source_label,p.naming_table,p.bom_cost_source,p.tags,p.note].join(' ').toLowerCase()}
function productPriceForFilter(p){return Number(p.cost_usd||0)||Number(p.price_usd||0)||Number(p.cost_rmb||0)||Number(p.price_rmb||0)||0}
function productSourceLabel(p){let src=String(p?.source||'quote'); if(src==='naming')return '命名中心'; if(src==='crm')return 'CRM'; if(src==='bom')return 'BOM'; return '报价本地'}
function productSourceBadge(p){let src=String(p?.source||'quote');let cls=src==='naming'?'naming':(Number(p?.bom_match||0)?'bom':'');let bom=Number(p?.bom_match||0)?'<span class="source-tag bom">BOM成本</span>':(src==='naming'?'<span class="source-tag warn">未匹配BOM</span>':'');return `<span class="source-tag ${cls}">${esc(productSourceLabel(p))}</span>${bom}`}
// V6.8.4.3：产品弹窗/产品库筛选的“分类”改为显示命名系统的一级产品分类，
// 不再直接显示“xxx型号命名”这类规则名称。
function cleanQuoteCategoryLabel(v,type=''){
  let raw=String(v||'').trim();
  let s=raw.replace(/型号命名|灯具命名|命名规则|规则|命名/g,'').replace(/[\s　]+/g,' ').trim();
  let both=(raw+' '+s).toLowerCase();
  if((both.includes('外购')||both.includes('outsour')) && (both.includes('嵌入')||both.includes('recess')||both.includes('downlight'))) return '外购嵌入式灯具';
  if(both.includes('磁吸')||both.includes('magnetic')) return '磁吸灯具';
  if(both.includes('导轨')||both.includes('track')) return '导轨灯';
  if(both.includes('嵌入')||both.includes('筒')||both.includes('recess')||both.includes('downlight')) return '嵌入式灯具';
  if(both.includes('明装')||both.includes('surface')) return '明装式';
  if(both.includes('吊线')||both.includes('吊装')||both.includes('pendant')) return '吊线式';
  if(both.includes('线性')||both.includes('linear')) return '线性灯';
  if(both.includes('户外')||both.includes('outdoor')) return '户外灯';
  if(s) return s;
  let map={track:'导轨灯',recessed:'嵌入式灯具',surface:'明装式',pendant:'吊线式',magnetic:'磁吸灯具',linear:'线性灯',outdoor:'户外灯',custom:'定制产品',other:'其它'};
  return map[String(type||'')]||String(type||'');
}
function productCategoryLabel(p){
  p=p||{};
  let raw=String(p.quote_category||p.category||p.product_category||p.type_label||'').trim();
  return cleanQuoteCategoryLabel(raw,p.type);
}
function productCategoryOptions(){
  let arr=(DB.products||[]).map(productCategoryLabel).filter(Boolean);
  return [...new Set(arr)].sort((a,b)=>a.localeCompare(b,'zh'));
}
function productCategoryMatch(p,v){
  if(!v) return true;
  v=String(v||'').trim();
  let clean=productCategoryLabel(p);
  return String(clean||'')===v || cleanQuoteCategoryLabel(p.category||'',p.type)===v || String(p.category||'')===v || String(p.type||'')===v;
}
function setProductCategorySelect(id,firstText='全部分类'){
  let el=$(id); if(!el) return;
  let old=el.value;
  let arr=productCategoryOptions();
  el.innerHTML='<option value="">'+firstText+'</option>'+arr.map(v=>`<option value="${esc(v)}">${esc(v)}</option>`).join('');
  if(arr.includes(old)) el.value=old;
}
function productBaseCostRmb(p){return Number(p?.cost_rmb||0)||Number(p?.price_rmb||0)||Number(p?.price_usd||0)*7||0}
function productCostText(p){let c=productBaseCostRmb(p); if(!c)return '未找到成本'; return 'BOM/基础成本 RMB '+money(c)+(p?.cost_updated_at?' ｜ '+p.cost_updated_at:'')}
function qspecModelOfProduct(p=null){p=p||S.product||{};return String(p.code||p.model||p.naming_model_no||p.name||'').trim()}
function bomQuoteSpecUrl(p=null){p=p||S.product||{};let u='bom_quote_spec.php';let qs=[];let model=qspecModelOfProduct(p);if(model)qs.push('model='+encodeURIComponent(model));if(p.naming_id)qs.push('naming_id='+encodeURIComponent(p.naming_id));return u+(qs.length?'?'+qs.join('&'):'')}
function openBomQuoteSpec(p=null){let u=bomQuoteSpecUrl(p);window.open(u,'_blank')}
async function forceSyncBomQuoteSpecForSelectedProduct(){let p=S.product;if(!p){alert('请先选择命名系统产品');return;}let el=$('productLinkHint');if(el)el.innerHTML=productSourceBadge(p)+' <b>'+esc(p.code||p.name||'')+'</b><br>正在从 BOM 手动重新拉取关键件...';try{let res=await api('sync_bom_quote_spec',{force:1,product:{id:p.id,source:p.source,naming_id:p.naming_id,code:p.code,model:p.model,name:p.name,image:p.image,power:p.power,size:p.size,cutout:p.cutout,bom_match:p.bom_match,bom_match_key:p.bom_match_key}});if(res&&res.spec){let spec=res.spec;Object.assign(S.product,{bom_quote_spec_id:spec.id,quote_spec:spec.quote_spec||{},quote_spec_json:JSON.stringify(spec.quote_spec||{}),quote_spec_source:'bom_quote_specs',quote_spec_updated_at:spec.updated_at||'',quote_spec_auto_generated:spec.auto_generated||1});['power','size','cutout'].forEach(k=>{if(spec[k])S.product[k]=spec[k]});let idx=(DB.products||[]).findIndex(x=>String(x.id)===String(S.product.id));if(idx>=0)DB.products[idx]=Object.assign(DB.products[idx],S.product);renderParts();render();updatePriceLevelHint();updateProductLinkHint();alert('已从 BOM 重新拉取并保存关键件。若文字还需调整，请点“手动修正关键件”。')}else{updateProductLinkHint();alert('未从 BOM 提取到关键件，可点“手动修正关键件”自行填写。')}}catch(e){updateProductLinkHint();alert('重新拉取失败：'+e.message)}}
function updateProductLinkHint(){let el=$('productLinkHint'); if(!el)return;let p=S.product;if(!p){el.innerHTML='产品主数据优先来自命名中心；成本价优先匹配 BOM。';return;}let src=productSourceLabel(p), bom=Number(p.bom_match||0), qn=productQuoteSpec(p).length;let auto=Number(p.quote_spec_auto_generated||0)?'自动同步':'人工维护';let actions=p.source==='naming'?`<div class="quote-spec-actions"><button type="button" class="mini-sync" onclick="forceSyncBomQuoteSpecForSelectedProduct()">重新从BOM拉取关键件</button><button type="button" class="mini-link" onclick="openBomQuoteSpec()">手动修正关键件</button></div>`:'';el.className='hint product-link-hint';el.innerHTML=`${productSourceBadge(p)} <b>${esc(p.code||p.name||'')}</b>${qn?'<span class="quote-spec-badge">已设报价关键件 '+qn+' 项</span>':''}<br>来源：${esc(src)} ｜ ${bom?'已匹配 BOM 成本':'未匹配 BOM，可先按空壳/手动价报价'} ｜ ${esc(productCostText(p))}${p.bom_cost_source?' ｜ 成本源：'+esc(p.bom_cost_source):''}${qn?' ｜ 关键件来自 BOM 报价设置（'+auto+'）':''}${actions}`}
function passText(v,kw){return !kw || String(v||'').toLowerCase().includes(String(kw).toLowerCase())}
function firstLetter(s){s=String(s||'').trim(); return s? s[0].toUpperCase() : '#'}
function quoteCustomer(q){try{return JSON.parse(q.customer_json||'{}')}catch(e){return {}}}
function quoteHistorySearchTextV68540(q,c=null){c=c||quoteCustomer(q);return [q.quote_no,q.order_no,q.customer_name,c.company,c.name,c.customer_name,c.client_name,c.contact,c.primary_contact,c.main_contact,c.person,c.linkman].join(' ')}
function quoteProductsText(q){let txt=q.product_json||'';try{let items=JSON.parse(q.items_json||'[]'); if(Array.isArray(items)) txt += ' '+items.map(it=>[it.product?.name,it.product?.code,it.product?.series,it.product?.power,it.product?.size].join(' ')).join(' ')}catch(e){} return txt}


function setQuoteConfigCollapsed(collapsed, remember=false){
  let card=$('quoteConfigCard'), btn=$('quoteConfigToggle'), split=$('quotePageSplit');
  if(!card)return;
  card.classList.toggle('is-collapsed', !!collapsed);
  if(split)split.classList.toggle('config-side-collapsed', !!collapsed);
  if(btn)btn.textContent=collapsed?'展开':'左折';
  if(remember){try{localStorage.setItem('artdon_quote_config_collapsed', collapsed?'1':'0')}catch(e){}}
}
function toggleQuoteConfig(){
  let card=$('quoteConfigCard');
  let next=!(card&&card.classList.contains('is-collapsed'));
  setQuoteConfigCollapsed(next,true);
  try{clientLog('toggle_quote_config', next?'手动左折报价配置':'手动展开报价配置',{collapsed:next,quote_no:$('quoteNo')?.value||''})}catch(e){}
}
function restoreQuoteConfigCollapsed(){
  let v='0';
  try{v=localStorage.getItem('artdon_quote_config_collapsed')||'0'}catch(e){}
  setQuoteConfigCollapsed(v==='1',false);
}
function autoCollapseQuoteConfigAfterSave(){
  setQuoteConfigCollapsed(true,true);
}

function setTopDashCollapsed(collapsed, remember=false){
  let dash=$('topDash'), btn=$('topDashToggle');
  if(!dash)return;
  dash.classList.toggle('is-collapsed', !!collapsed);
  if(btn)btn.textContent=collapsed?'展开数据':'折叠';
  if(remember){try{localStorage.setItem('artdon_quote_top_dash_collapsed', collapsed?'1':'0')}catch(e){}}
}
function toggleTopDash(){
  let dash=$('topDash');
  let next=!(dash&&dash.classList.contains('is-collapsed'));
  setTopDashCollapsed(next,true);
  try{clientLog('toggle_quote_dashboard', next?'折叠报价数据看板':'展开报价数据看板',{collapsed:next,quote_no:$('quoteNo')?.value||''})}catch(e){}
}
function restoreTopDashCollapsed(){
  let v='0';
  try{v=localStorage.getItem('artdon_quote_top_dash_collapsed')||'0'}catch(e){}
  setTopDashCollapsed(v==='1',false);
}
const DASH_WIDGETS=[
  ['quote_count','近7日报价柱形图'],['month_amount','本月金额'],['top_customer','最多客户'],['currency','币种/汇率'],
  ['top_sales','报价最多员工'],['approval','审核状态：总/待审/已审/驳回'],['convert','转订单：未转/已转'],['order_revenue','订单成交金额'],['docs_ship','单证/出货'],['receivable','欠款']
];
function dashTemplate(){try{let v=JSON.parse(localStorage.getItem('artdon_quote_dash_template_v68438')||'null');if(v&&typeof v==='object')return v;}catch(e){}let o={};DASH_WIDGETS.forEach(([k])=>o[k]=1);return o;}
function saveDashTemplate(o){try{localStorage.setItem('artdon_quote_dash_template_v68438',JSON.stringify(o||{}))}catch(e){}}
const DASH_COLOR_TEMPLATES=[
  ['none','无颜色',['#ffffff','#ffffff','#ffffff','#ffffff']],
  ['soft','清爽浅色',['#eff6ff','#ecfdf5','#fff7ed','#fff1f2']],
  ['business','商务蓝',['#dbeafe','#bfdbfe','#0f766e','#ea580c']],
  ['glass','玻璃质感',['#ffffff','#dbeafe','#bfdbfe','#93c5fd']],
  ['warm','暖橙',['#fff7ed','#fed7aa','#f97316','#dc2626']],
  ['green','绿松石',['#ecfdf5','#bbf7d0','#059669','#0f766e']],
  ['purple','紫色',['#f5f3ff','#ddd6fe','#7c3aed','#e11d48']],
  ['strong','深色强调',['#2563eb','#059669','#7c3aed','#e11d48']]
];
function dashColorTemplate(){try{return localStorage.getItem('artdon_quote_dash_color_v68440')||localStorage.getItem('artdon_quote_dash_color_v68439')||'soft'}catch(e){return 'soft'}}
function saveDashColorTemplate(v){try{localStorage.setItem('artdon_quote_dash_color_v68440',v||'soft')}catch(e){}}
function applyDashColorTemplate(){try{document.body.setAttribute('data-dash-color',dashColorTemplate())}catch(e){}}
function applyDashTemplate(){applyDashColorTemplate();let t=dashTemplate();document.querySelectorAll('[data-dash-widget]').forEach(card=>{let k=card.getAttribute('data-dash-widget');card.classList.toggle('is-hidden-by-template',t[k]===0);});}
function dashColorSampleHtml(k){let tpl=DASH_COLOR_TEMPLATES.find(x=>x[0]===k);let arr=(tpl&&tpl[2])?tpl[2]:['#eff6ff','#ecfdf5','#fff7ed','#fff1f2'];return arr.map(c=>`<i style="--c:${esc(c)}"></i>`).join('')}
function openDashTemplateModal(){let t=dashTemplate(), color=dashColorTemplate();let colorHtml=DASH_COLOR_TEMPLATES.map(([k,label])=>`<label class="dash-color-choice"><input type="radio" name="dashColorTemplate" value="${esc(k)}" ${color===k?'checked':''}>${esc(label)}<span class="dash-color-sample">${dashColorSampleHtml(k)}</span></label>`).join('');let html=`<div class="dash-template-mask" id="dashTemplateMask"><div class="dash-template-box"><div class="dash-template-head"><b>报价数据看板显示设置</b><button class="gray" onclick="closeDashTemplateModal()">关闭</button></div><div class="dash-template-body"><div class="hint" style="margin-bottom:8px">先选颜色模板，再勾选需要显示的看板卡片。设置会保存在当前浏览器。新增“无颜色”和“玻璃质感”，也可以继续用商务蓝、暖橙、绿松石、紫色等模板。</div><b style="display:block;margin:4px 0 6px">颜色模板</b><div class="dash-color-grid">${colorHtml}</div><b style="display:block;margin:4px 0 8px">显示 / 隐藏卡片</b><div class="dash-template-grid">${DASH_WIDGETS.map(([k,label])=>`<label class="dash-template-item"><input type="checkbox" data-dash-template="${k}" ${t[k]===0?'':'checked'}> ${esc(label)}</label>`).join('')}</div><div class="dash-template-actions"><button class="gray" onclick="dashTemplateSelectAll(false)">全部隐藏</button><button class="gray" onclick="dashTemplateSelectAll(true)">全部显示</button><button class="gray" onclick="dashColorReset()">恢复默认颜色</button><button class="blue" onclick="saveDashTemplateFromModal()">保存设置</button></div></div></div></div>`;let old=$('dashTemplateMask');if(old)old.remove();document.body.insertAdjacentHTML('beforeend',html);}
function closeDashTemplateModal(){let m=$('dashTemplateMask');if(m)m.remove();}
function dashTemplateSelectAll(v){document.querySelectorAll('[data-dash-template]').forEach(x=>x.checked=!!v);}
function dashColorReset(){let r=document.querySelector('input[name="dashColorTemplate"][value="soft"]');if(r)r.checked=true;saveDashColorTemplate('soft');applyDashColorTemplate();}
function saveDashTemplateFromModal(){let o={};document.querySelectorAll('[data-dash-template]').forEach(x=>o[x.dataset.dashTemplate]=x.checked?1:0);let c=document.querySelector('input[name="dashColorTemplate"]:checked');saveDashColorTemplate(c?c.value:'soft');saveDashTemplate(o);applyDashTemplate();closeDashTemplateModal();}
function updateDashMiniSummary(){
  let el=$('dashMiniSummary'); if(!el)return;
  let total=$('dTotal')?.textContent||'0', pending=$('dQuotePending')?.textContent||'0', approved=$('dQuoteApproved')?.textContent||'0', converted=$('dConverted')?.textContent||'0', revenue=$('dOrderRevenue')?.textContent||'RMB 0.00', revenueUsd=$('dOrderRevenueSub')?.textContent||'USD 0.00 ｜ 只统计订单', balance=$('dBalance')?.textContent||'RMB 0.00', balanceUsd=$('dBalanceSub')?.textContent||'USD 0.00 未收';
  el.textContent='报价 '+total+' ｜ 待审核 '+pending+' ｜ 已审核 '+approved+' ｜ 已转订单 '+converted+' ｜ 订单成交 '+revenue+' / '+String(revenueUsd).replace(' ｜ 只统计订单','')+' ｜ 欠款 '+balance+' / '+String(balanceUsd).replace(' 未收','');
}
function quoteApprovalStatus(q){let s=String(q.approval_status||q.review_status||q.audit_status||'').toLowerCase();if(!s){return 'pending'}if(['approved','pass','passed','done','已审核','审核通过'].includes(s))return 'approved';if(['rejected','reject','驳回','已驳回'].includes(s))return 'rejected';return 'pending';}
function quoteOwnerName(q){return String(q.user_name||q.created_by||q.owner||q.sales_name||q.sales||'未填写').trim()||'未填写';}
function quoteCustomerName(q){let c='';try{let j=JSON.parse(q.customer_json||'{}');c=j.company||j.name||j.contact||''}catch(e){}return c||q.customer_name||'';}
// V6.8.5.6：报价看板金额按“原报价币种”分开统计。RMB 报价只进 RMB，USD 报价只进 USD，不再互相折算。
function quoteRecordCurrency(q){let c=String(q?.currency||q?.quote_currency||'USD').trim().toUpperCase();if(c==='CNY'||c==='RMB¥'||c==='￥')return 'RMB';if(c==='US$'||c==='$')return 'USD';return c||'USD'}
function quoteAmountByOriginalCurrency(q){return Number(q?.amount||0)||0}
// V6.8.5.7：订单成交金额只统计订单，不统计普通报价；取消/作废订单不计营收。
function quoteOrderRecordCurrency(o){let c=String(o?.currency||o?.order_currency||o?.quote_currency||'USD').trim().toUpperCase();if(c==='CNY'||c==='RMB¥'||c==='￥')return 'RMB';if(c==='US$'||c==='$')return 'USD';return c||'USD'}
function quoteOrderAmountByOriginalCurrency(o){return Number(o?.amount||o?.order_amount||o?.total_amount||o?.grand_total||0)||0}
function quoteOrderIsRevenue(o){let s=String((o&&((o.status||'')+' '+(o.order_status||'')+' '+(o.is_void||'')+' '+(o.voided_at||'')))||'').toLowerCase();if(/取消|作废|void|cancel|cancelled|canceled/.test(s))return false;return quoteOrderAmountByOriginalCurrency(o)>0}
function quoteAddOrderToCurrencyBucket(bucket,o){bucket=bucket||quoteCurrencyBucket();if(!quoteOrderIsRevenue(o))return bucket;let c=quoteOrderRecordCurrency(o),amt=quoteOrderAmountByOriginalCurrency(o);if(!bucket[c])bucket[c]=0;bucket[c]+=amt;return bucket}
function quoteSumOrdersByOriginalCurrency(list){let b=quoteCurrencyBucket();(Array.isArray(list)?list:[]).forEach(o=>quoteAddOrderToCurrencyBucket(b,o));return b}
function quoteCurrencyBucket(){return {RMB:0,USD:0}}
function quoteAddToCurrencyBucket(bucket,q){bucket=bucket||quoteCurrencyBucket();let c=quoteRecordCurrency(q),amt=quoteAmountByOriginalCurrency(q);if(!isFinite(amt))amt=0;if(!bucket[c])bucket[c]=0;bucket[c]+=amt;return bucket}
function quoteSumByOriginalCurrency(list){let b=quoteCurrencyBucket();(Array.isArray(list)?list:[]).forEach(q=>quoteAddToCurrencyBucket(b,q));return b}
function dashCurrencyLine(bucket){bucket=bucket||quoteCurrencyBucket();let parts=['RMB '+money(bucket.RMB||0),'USD '+money(bucket.USD||0)];Object.keys(bucket).sort().forEach(k=>{if(!['RMB','USD'].includes(k)&&Number(bucket[k]||0)!==0)parts.push(k+' '+money(bucket[k]||0));});return parts.join(' ｜ ')}
function dashPrimaryCurrencyText(bucket){bucket=bucket||quoteCurrencyBucket();if(Number(bucket.RMB||0)>0)return 'RMB '+money(bucket.RMB||0);if(Number(bucket.USD||0)>0)return 'USD '+money(bucket.USD||0);let other=Object.keys(bucket).find(k=>!['RMB','USD'].includes(k)&&Number(bucket[k]||0)>0);return other?(other+' '+money(bucket[other])):'RMB 0.00'}
function dashSecondaryCurrencyText(bucket){bucket=bucket||quoteCurrencyBucket();let primary=dashPrimaryCurrencyText(bucket).split(' ')[0];let parts=[];if(primary!=='RMB')parts.push('RMB '+money(bucket.RMB||0));if(primary!=='USD')parts.push('USD '+money(bucket.USD||0));Object.keys(bucket).sort().forEach(k=>{if(!['RMB','USD',primary].includes(k)&&Number(bucket[k]||0)!==0)parts.push(k+' '+money(bucket[k]||0));});return (parts.length?parts.join(' ｜ '):'USD 0.00')+' ｜ 原币种统计'}
function quoteToBaseCurrencyAmount(q){return quoteAmountByOriginalCurrency(q)}
async function ensureDashOrderData(){if(DASH_ORDERS_LOADED||DASH_LOADING_ORDERS||!hasPerm('order_convert'))return;DASH_LOADING_ORDERS=true;let note=$('dashOrderNote');if(note)note.textContent='读取订单/单证...';try{let d=await orderApi('list');DB.orders=d.orders||[];DASH_ORDERS_LOADED=true;}catch(e){if(note)note.textContent='订单数据未读取';}finally{DASH_LOADING_ORDERS=false;renderDash();}}
async function ensureDashDocData(){if(DASH_DOCS_LOADED||DASH_LOADING_DOCS||!hasPerm('order_convert'))return;DASH_LOADING_DOCS=true;try{let d=await orderApi('list_documents');DOCUMENTS=d.documents||[];DASH_DOCS_LOADED=true;}catch(e){}finally{DASH_LOADING_DOCS=false;renderDash();}}

/* V6.8.5.9：报价/订单数据变动后自动同步，不再依赖手动刷新 */
let QUOTE_RUNTIME_REFRESHING=false,QUOTE_RUNTIME_REFRESH_TIMER=null,QUOTE_RUNTIME_SYNC_STARTED=false;
function quoteSyncTimeText(){try{return new Date().toLocaleTimeString('zh-CN',{hour12:false});}catch(e){return ''}}
async function refreshQuoteRuntime(reason='change',opts={}){
  if(QUOTE_RUNTIME_REFRESHING){scheduleQuoteRuntimeRefresh(reason,500,opts);return;}
  QUOTE_RUNTIME_REFRESHING=true;
  try{
    const keepQuoteId=S.currentQuoteId||0;
    const keepApproval=S.currentApprovalStatus||'';
    const data=await api('init');
    DB=Object.assign(DB||{},data||{});
    if(keepQuoteId)S.currentQuoteId=keepQuoteId;
    if(!S.currentApprovalStatus&&keepApproval)S.currentApprovalStatus=keepApproval;
    if(opts.orders!==false&&hasPerm('order_convert')){
      try{let od=await orderApi('list');DB.orders=od.orders||[];DASH_ORDERS_LOADED=true;}catch(e){}
    }
    if(opts.documents!==false&&hasPerm('order_convert')){
      try{let dd=await orderApi('list_documents');DOCUMENTS=dd.documents||[];DASH_DOCS_LOADED=true;}catch(e){}
    }
    try{refreshCustomerFilters();refreshHistoryFilters();refreshMaterialFilters();refreshProductFilters();}catch(e){}
    try{renderHistory(true);}catch(e){}
    try{renderApproval();}catch(e){}
    try{renderOrders();}catch(e){}
    try{renderDocuments();}catch(e){}
    try{renderDash();}catch(e){}
    try{updateQuoteApprovalStrip(currentSavedQuote());}catch(e){}
    const note=$('dashOrderNote');
    if(note)note.textContent='数据已自动同步 '+quoteSyncTimeText();
  }catch(e){
    const note=$('dashOrderNote');
    if(note)note.textContent='自动同步失败，可手动刷新';
    console.warn('quote runtime refresh failed',reason,e);
  }finally{QUOTE_RUNTIME_REFRESHING=false;}
}
function scheduleQuoteRuntimeRefresh(reason='change',delay=300,opts={}){
  if(QUOTE_RUNTIME_REFRESH_TIMER)clearTimeout(QUOTE_RUNTIME_REFRESH_TIMER);
  QUOTE_RUNTIME_REFRESH_TIMER=setTimeout(()=>{QUOTE_RUNTIME_REFRESH_TIMER=null;refreshQuoteRuntime(reason,opts);},Math.max(0,delay||0));
}
function markQuoteDataChanged(reason='change',opts={}){scheduleQuoteRuntimeRefresh(reason,260,Object.assign({orders:true,documents:true},opts||{}));}
function startQuoteRuntimeAutoSync(){
  if(QUOTE_RUNTIME_SYNC_STARTED)return;
  QUOTE_RUNTIME_SYNC_STARTED=true;
  window.addEventListener('focus',()=>markQuoteDataChanged('window_focus',{orders:true,documents:true}));
  document.addEventListener('visibilitychange',()=>{if(!document.hidden)markQuoteDataChanged('visible',{orders:true,documents:true});});
  setInterval(()=>{if(!document.hidden)markQuoteDataChanged('interval',{orders:true,documents:true});},30000);
}

function currentQuotePage(){return localStorage.getItem('artdon_quote_current_page')||'quote'}
function renderCore(){renderSelects();renderParts();refreshProductFilters();refreshCustomerFilters();refreshHistoryFilters();refreshMaterialFilters();renderDash();renderQdocQuickSettings();render();}
function renderSettingsPage(){try{restoreSettingsTab()}catch(e){} renderHeaders();renderBanks();renderTemplates();renderPriceLevels();renderOptions();}
function renderCurrentPage(p){
  p=p||currentQuotePage();
  if(p==='quote'){renderCore();return;}
  if(p==='products'){renderProducts();return;}
  if(p==='customers'){renderCustomers();return;}
  if(p==='materials'){renderMaterials();return;}
  if(p==='history'){renderHistory();return;}
  if(p==='approval'){renderApproval();return;}
  if(p==='settings'){renderSettingsPage();return;}
}
function renderAll(){renderCore();renderCurrentPage(currentQuotePage());}
let CUSTOMER_SEARCH_ACTIVE=-1;
const CUSTOMER_SEARCH_LIMIT=200;
function customerLabel(c){return `${c.source==='crm'?'[CRM]':'[报价]'} ${(c.code?c.code+' ':'')}${c.company||c.contact||''}`.trim()}
function customerSearchText(c){return [c.code,c.company,c.contact,c.primary_contact,c.email,c.primary_contact_email,c.phone,c.primary_contact_phone,c.country,c.owner,c.website,c.address1,c.address2,c.addresses_json,c.crm_customer_id,customerSourceText(c)].join(' ').toLowerCase()}
function customerDisplayText(c){return `${c.code?c.code+' ｜ ':''}${c.company||c.contact||''}${c.country?' ｜ '+c.country:''}`.trim()}
function customerSearchAllMatches(){let kw=String($('customerSearchInput')?.value||'').toLowerCase().trim();let arr=(DB.customers||[]).slice();if(kw){let parts=kw.split(/\s+/).filter(Boolean);arr=arr.filter(c=>parts.every(p=>customerSearchText(c).includes(p)));}arr.sort((a,b)=>{let ac=(a.source==='crm'?0:1),bc=(b.source==='crm'?0:1);if(ac!==bc)return ac-bc;return String(a.code||a.company||'').localeCompare(String(b.code||b.company||''),'zh')});return arr}
function customerSearchMatches(){return customerSearchAllMatches().slice(0,CUSTOMER_SEARCH_LIMIT)}
function renderCustomerSearchResults(show=true){let box=$('customerSearchResults');if(!box)return;let all=customerSearchAllMatches();let arr=all.slice(0,CUSTOMER_SEARCH_LIMIT);CUSTOMER_SEARCH_ACTIVE=Math.min(Math.max(CUSTOMER_SEARCH_ACTIVE,-1),arr.length-1);let countText=all.length>CUSTOMER_SEARCH_LIMIT?`找到 ${all.length} 个客户，显示前 ${CUSTOMER_SEARCH_LIMIT} 个；继续输入可缩小范围。按 Enter 选择当前高亮项。`:`找到 ${all.length} 个客户，已全部显示。按 Enter 选择当前高亮项。`;box.innerHTML=(arr.length?`<div class="customer-fuzzy-count">${countText}</div>`+arr.map((c,i)=>`<button type="button" class="customer-fuzzy-row ${i===CUSTOMER_SEARCH_ACTIVE?'active':''}" data-customer-pick="${esc(c.id)}" onclick="selectCustomerById('${jsq(c.id)}')"><span><span class="customer-fuzzy-title">${esc(customerDisplayText(c)||'未命名客户')}</span><span class="customer-fuzzy-meta">${esc([c.contact||c.primary_contact,c.email||c.primary_contact_email,c.phone||c.primary_contact_phone,c.country,c.owner].filter(Boolean).join(' ｜ '))}</span></span><span class="customer-fuzzy-tag ${c.source==='crm'?'crm':''}">${esc(customerSourceText(c))}</span></button>`).join(''):`<div class="customer-fuzzy-empty">没有找到客户。可换客户代码、公司名、联系人、国家或邮箱搜索。</div>`);box.classList.toggle('show',!!show)}
function openCustomerSearch(){CUSTOMER_SEARCH_ACTIVE=-1;renderCustomerSearchResults(true)}
function closeCustomerSearch(){let box=$('customerSearchResults');if(box)box.classList.remove('show')}
function clearQuoteCustomerSelection(keepInput=false){
  S.customer=null;
  if($('customerSelect')) $('customerSelect').value='';
  if(!keepInput && $('customerSearchInput')) $('customerSearchInput').value='';
  if($('customerSearchHint')) $('customerSearchHint').textContent='Please select customer';
}
function onCustomerSearchInput(){
  const typed=String($('customerSearchInput')?.value||'').trim();
  const currentText=S.customer?String(customerDisplayText(S.customer)||'').trim():'';
  // 输入框只用于搜索。只要用户重新输入、清空或内容不等于已选客户，就取消旧客户绑定，避免报价单 TO 区继续显示上一个客户。
  if(!typed || (S.customer && typed!==currentText)){
    clearQuoteCustomerSelection(true);
    try{ updateQuoteNo(); }catch(e){ try{ render(); }catch(_e){} }
  }
  CUSTOMER_SEARCH_ACTIVE=0;renderCustomerSearchResults(true)
}
function handleCustomerSearchKey(e){let arr=customerSearchMatches();if(e.key==='ArrowDown'){e.preventDefault();CUSTOMER_SEARCH_ACTIVE=Math.min(arr.length-1,CUSTOMER_SEARCH_ACTIVE+1);renderCustomerSearchResults(true)}else if(e.key==='ArrowUp'){e.preventDefault();CUSTOMER_SEARCH_ACTIVE=Math.max(0,CUSTOMER_SEARCH_ACTIVE-1);renderCustomerSearchResults(true)}else if(e.key==='Enter'){e.preventDefault();let c=arr[Math.max(0,CUSTOMER_SEARCH_ACTIVE)];if(c)selectCustomerById(c.id)}else if(e.key==='Escape'){closeCustomerSearch()}}
function syncCustomerSearchFromSelect(){let c=S.customer;if(c && !(DB.customers||[]).some(x=>String(x.id)===String(c.id)))c=null;if(!c){let val=$('customerSelect')?.value||'';if(val)c=(DB.customers||[]).find(x=>String(x.id)===String(val))||null;}S.customer=c||null;if($('customerSelect'))$('customerSelect').value=c?c.id:'';if($('customerSearchInput'))$('customerSearchInput').value=c?customerDisplayText(c):'';if($('customerSearchHint'))$('customerSearchHint').textContent=c?`${customerSourceText(c)} ｜ ${customerDisplayText(c)}${c.contact?' ｜ '+c.contact:''}${c.email?' ｜ '+c.email:''}`:'Please select customer'}
function selectCustomerById(id){let c=(DB.customers||[]).find(x=>String(x.id)===String(id));if(!c)return;S.customer=c;if($('customerSelect'))$('customerSelect').value=c.id;if($('customerSearchInput'))$('customerSearchInput').value=customerDisplayText(c);syncCustomerSearchFromSelect();closeCustomerSearch();updateQuoteNo();render()}
function renderSelects(){let opt=(arr,txt)=>arr.map(x=>`<option value="${esc(x.id)}">${esc(txt(x))}</option>`).join('');if($('customerSelect')){$('customerSelect').innerHTML='<option value="">请选择客户</option>'+opt(DB.customers,c=>customerLabel(c));$('customerSelect').onchange=()=>{let v=$('customerSelect').value;if(v)selectCustomerById(v);else{clearQuoteCustomerSelection(false);updateQuoteNo();render();}};syncCustomerSearchFromSelect();renderCustomerSearchResults(false);}if($('defaultHeaderSelect')){$('defaultHeaderSelect').innerHTML=opt(DB.headers,h=>h.name);$('defaultBankSelect').innerHTML=opt(DB.banks,b=>b.name);$('defaultTemplateSelect').innerHTML=opt(DB.templates,t=>t.name);syncDefaultSettingSelects();}if($('qdocHeaderSelect')){$('qdocHeaderSelect').innerHTML=opt(DB.headers,h=>h.name||h.company||'未命名抬头');$('qdocTemplateSelect').innerHTML=opt(DB.templates,t=>t.name||'未命名条款');$('qdocBankSelect').innerHTML=opt(DB.banks,b=>b.name||'未命名银行');syncQdocSelects();}let colors=colorItems();fillOptionSelect('color',colors,$('color')?.value||'White');fillOptionSelect('pColor',colors,$('pColor')?.value||'White');if($('priceLevel')){let old=$('priceLevel').value;let pls=quoteLevels();$('priceLevel').innerHTML=pls.map(l=>`<option value="${esc(l.id)}">${esc(l.name)} ×${Number(l.multiplier||1).toFixed(2)}</option>`).join('');let def=pls.find(l=>Number(l.is_default||0))||pls[0];$('priceLevel').value=old&&pls.some(l=>String(l.id)===String(old))?old:(def?.id||'');syncPriceMultiplierFromLevel(false);updatePriceLevelHint();}renderProductSelect();}
let productSearchActiveIndex=-1;
function productSelectLabel(p){
  if(!p)return '';
  let prefix=p.source==='naming'?'[命名] ':(p.source==='quote'?'[报价] ':'');
  let bom=Number(p.bom_match||0)?' [BOM]':(p.source==='naming'?' [未建BOM]':'');
  return prefix+(p.code?(p.code+' '):'')+(p.name||'')+bom;
}
function productSearchNormalize(v){
  let s=String(v??'');
  try{s=s.normalize('NFKC')}catch(e){}
  return s.toLowerCase().replace(/[．。]/g,'.').replace(/[×✕✖]/g,'x').replace(/\s+/g,' ').trim();
}
function productSearchCompact(v){
  return productSearchNormalize(v).replace(/[^a-z0-9\u4e00-\u9fff]+/g,'');
}
function productSearchHaystack(p){
  let parts=[
    productText(p),p.id,p.code,p.model,p.model_no,p.naming_model_no,p.product_name,p.name,p.item_name,
    p.customer_code,p.category,p.type,p.series,p.size,p.cutout,p.power,p.ip,p.color,p.source,p.source_label,
    p.naming_table,p.bom_cost_source,p.tags,p.note,productCategoryLabel(p),quoteDisplaySize(p),quoteDisplayCutout(p)
  ];
  let raw=productSearchNormalize(parts.join(' '));
  return raw+' '+productSearchCompact(raw);
}
function productSearchPool(){
  // 搜索必须覆盖整个产品库；当前“产品分类”只用于报价配置和选择后的自动联动，不再限制搜索结果。
  return Array.isArray(DB.products)?DB.products:[];
}
function productSearchScore(p,q){
  q=productSearchNormalize(q);
  if(!q){
    let currentType=String($('productType')?.value||'');
    return String(p.type||'')===currentType?20:50;
  }
  let qc=productSearchCompact(q);
  let code=productSearchNormalize(p.code||p.model||p.model_no||p.naming_model_no||'');
  let codeCompact=productSearchCompact(code);
  let name=productSearchNormalize(p.name||p.product_name||p.item_name||'');
  if(code===q||codeCompact===qc)return 0;
  if(code.startsWith(q)||codeCompact.startsWith(qc))return 1;
  if(name.startsWith(q))return 2;
  if(code.includes(q)||codeCompact.includes(qc))return 3;
  if(name.includes(q))return 4;
  let currentType=String($('productType')?.value||'');
  return String(p.type||'')===currentType?10:12;
}
function productSearchMatches(p,q){
  q=productSearchNormalize(q);
  if(!q)return true;
  let hay=productSearchHaystack(p);
  let compactHay=productSearchCompact(hay);
  return q.split(/\s+/).filter(Boolean).every(k=>{
    let nk=productSearchNormalize(k),ck=productSearchCompact(k);
    return hay.includes(nk)||(ck&&compactHay.includes(ck));
  });
}
function productSearchMeta(p){
  return [productCategoryLabel(p),p.series,quoteDisplaySize(p),quoteDisplayCutout(p)?('开孔 '+quoteDisplayCutout(p)):'',p.power,p.ip].filter(Boolean).join(' ｜ ');
}
function renderProductSearchResults(forceOpen=true){
  let input=$('productSearchInput'),box=$('productSearchResults');
  if(!input||!box)return;
  let q=String(input.value||'').trim().toLowerCase();
  let arr=productSearchPool().filter(p=>productSearchMatches(p,q));
  arr.sort((a,b)=>productSearchScore(a,q)-productSearchScore(b,q)||String(a.code||a.name||'').localeCompare(String(b.code||b.name||''),'zh-CN'));
  let total=arr.length;
  arr=arr.slice(0,80);
  productSearchActiveIndex=arr.length?0:-1;
  if(!arr.length){
    box.innerHTML='<div class="product-fuzzy-empty">没有找到匹配产品。可输入完整或部分型号，例如 52.07、5207、产品名称。</div>';
  }else{
    box.innerHTML='<div class="product-fuzzy-count">找到 '+total+' 个产品'+(total>80?'，先显示前 80 个':'')+'</div>'+arr.map((p,i)=>{
      let cost=productBaseCostRmb(p);
      return `<button type="button" class="product-fuzzy-row ${i===0?'active':''}" data-product-id="${esc(p.id)}" onclick="selectProductSearchResult('${jsq(p.id)}')"><span class="product-fuzzy-main"><span class="product-fuzzy-title">${esc(productSelectLabel(p))}</span><span class="product-fuzzy-meta">${esc(productSearchMeta(p)||'暂无规格信息')}</span></span><span class="product-fuzzy-cost ${cost?'':'missing'}">${cost?'RMB '+money(cost):'未找到成本'}</span></button>`;
    }).join('');
  }
  if(forceOpen)box.classList.add('show');
}
function openProductSearch(){
  let input=$('productSearchInput');
  if(input){try{input.select()}catch(e){}}
  renderProductSearchResults(true);
}
function closeProductSearch(restore=false){
  let box=$('productSearchResults'); if(box)box.classList.remove('show');
  productSearchActiveIndex=-1;
  if(restore)syncProductSearchFromSelect();
}
function onProductSearchInput(){
  let sel=$('productSelect'),input=$('productSearchInput');
  if(sel&&sel.value){
    sel.value='';
    if(input){input.dataset.selectedId='';input.classList.remove('has-selection')}
    S.product=null;S.parts={};S.editingIndex=-1;
    updateProductLinkHint();renderParts();render();
  }
  renderProductSearchResults(true);
}
function syncProductSearchFromSelect(){
  let sel=$('productSelect'),input=$('productSearchInput'); if(!sel||!input)return;
  let p=(DB.products||[]).find(x=>String(x.id)===String(sel.value||''))||null;
  input.value=p?productSelectLabel(p):'';
  input.dataset.selectedId=p?String(p.id):'';
  input.classList.toggle('has-selection',!!p);
}
function selectProductSearchResult(id){
  let sel=$('productSelect'); if(!sel)return;
  let p=(DB.products||[]).find(x=>String(x.id)===String(id||''))||null;
  if(!p)return;
  // 跨分类搜索到产品时，自动切换到该产品真实分类，避免选中后被当前分类清掉。
  if($('productType')&&p.type)$('productType').value=p.type;
  renderProductSelect();
  sel.value=String(p.id||'');
  syncProductSearchFromSelect();
  closeProductSearch(false);
  selectProduct();
}
function handleProductSearchKey(e){
  let box=$('productSearchResults'); if(!box)return;
  if(!box.classList.contains('show')&&(e.key==='ArrowDown'||e.key==='ArrowUp'))renderProductSearchResults(true);
  let rows=[...box.querySelectorAll('.product-fuzzy-row')];
  if(e.key==='ArrowDown'||e.key==='ArrowUp'){
    e.preventDefault();
    if(!rows.length)return;
    productSearchActiveIndex=e.key==='ArrowDown'?Math.min(rows.length-1,productSearchActiveIndex+1):Math.max(0,productSearchActiveIndex-1);
    rows.forEach((r,i)=>r.classList.toggle('active',i===productSearchActiveIndex));
    rows[productSearchActiveIndex]?.scrollIntoView({block:'nearest'});
  }else if(e.key==='Enter'){
    if(box.classList.contains('show')&&rows.length){e.preventDefault();(rows[Math.max(0,productSearchActiveIndex)]||rows[0]).click()}
  }else if(e.key==='Escape'){
    e.preventDefault();closeProductSearch(true);
  }
}
document.addEventListener('click',function(e){
  let shell=$('productFuzzyShell');
  if(shell&&!shell.contains(e.target))closeProductSearch(false);
});
document.addEventListener('click',e=>{if(!e.target.closest || !e.target.closest('#customerFuzzyShell')) closeCustomerSearch();});
function renderProductSelect(){
  let sel=$('productSelect'); if(!sel)return;
  let old=String(sel.value||S.product?.id||'');
  let arr=Array.isArray(DB.products)?DB.products:[];
  sel.innerHTML='<option value="">请选择产品</option>'+arr.map(p=>`<option value="${esc(p.id)}">${esc(productSelectLabel(p))}</option>`).join('');
  if(old&&arr.some(p=>String(p.id)===old))sel.value=old;else sel.value='';
  sel.onchange=selectProduct;
  syncProductSearchFromSelect();
  closeProductSearch(false);
  updateProductLinkHint();
}
async function ensureBomQuoteSpecForSelectedProduct(){
  let p=S.product;
  if(!p || p.source!=='naming') return;
  if(productQuoteSpec(p).length || p.bom_quote_spec_id) return;
  let selectedId=String(p.id||'');
  let el=$('productLinkHint');
  if(el) el.innerHTML=productSourceBadge(p)+' <b>'+esc(p.code||p.name||'')+'</b><br>正在自动从 BOM 同步整灯报价关键件...';
  try{
    let res=await api('ensure_bom_quote_spec',{product:{id:p.id,source:p.source,naming_id:p.naming_id,code:p.code,model:p.model,name:p.name,image:p.image,power:p.power,size:p.size,cutout:p.cutout,bom_match:p.bom_match,bom_match_key:p.bom_match_key}});
    if(String(S.product?.id||'')!==selectedId) return;
    if(res&&res.spec){
      let spec=res.spec;
      Object.assign(S.product,{
        bom_quote_spec_id:spec.id,
        quote_spec:spec.quote_spec||{},
        quote_spec_json:JSON.stringify(spec.quote_spec||{}),
        quote_spec_source:'bom_quote_specs',
        quote_spec_updated_at:spec.updated_at||'',
        quote_spec_auto_generated:spec.auto_generated||1
      });
      ['power','size','cutout'].forEach(k=>{if(spec[k])S.product[k]=spec[k]});
      let idx=(DB.products||[]).findIndex(x=>String(x.id)===selectedId);
      if(idx>=0) DB.products[idx]=Object.assign(DB.products[idx],S.product);
    }
  }catch(e){
    console.warn('ensure_bom_quote_spec failed',e);
    if(el) el.innerHTML=productSourceBadge(p)+' <b>'+esc(p.code||p.name||'')+'</b><br>未能自动同步 BOM 关键件，可先报价或到 bom_quote_spec.php 手动维护。';
  }
}
async function selectProduct(){
  let selectedId=String($('productSelect').value||'');
  let wasEditing=S.editingIndex>=0;
  syncProductSearchFromSelect();
  S.product=DB.products.find(x=>String(x.id)===selectedId)||null;
  if(S.product){
    S.parts={};
    $('manualPrice').value='';
    $('moq').value=S.product.moq||200;
    fillOptionSelect('color',colorItems(),S.product.color||'White');
    if($('power')) $('power').value=S.product.power||'';
    /* CCT/CRI/IP 由报价单手填，不从命名系统强制带入 */
  }
  updateProductLinkHint();
  renderParts();
  updatePriceLevelHint();
  render();
  await ensureBomQuoteSpecForSelectedProduct();
  updateProductLinkHint();
  renderParts();
  updatePriceLevelHint();
  if(S.product&&S.product.id){
    if(wasEditing){
      syncEditingItemFromForm();
      renderQuoteItems();
    }else{
      autoAddSelectedProductLine();
    }
  }
  render();
}
function autoAddSelectedProductLine(){
  if(!S.product||!S.product.id)return;
  let oldEdit=S.editingIndex;
  S.editingIndex=-1;
  let it=currentEditorItem();
  if(!it.product||!it.product.id){S.editingIndex=oldEdit;return;}
  it=normalizeQuoteItemCurrency(it,cur(),cur());
  it.amount=Number(it.qty||0)*Number(it.price||0);
  S.items.push(it);
  S.editingIndex=S.items.length-1;
  clientLog('quote_item_auto_add','点选产品自动加入报价单',{item:it,item_count:S.items.length});
  renderQuoteItems();
}
function needConnector(){if(S.product&&S.product.need_connector==='yes')return true;if(S.product&&S.product.need_connector==='no')return false;return ['track','magnetic'].includes($('productType').value)}
function partDefsByType(t,p){let need=p?(p.need_connector==='yes'||(p.need_connector==='auto'&&(p.type==='track'||p.type==='magnetic'))):(t==='track'||t==='magnetic');let a=[['led','芯片','芯片,COB,LED Chip,灯珠,Bridgelux,普瑞,VHD,Gen8,Cree,Osram,Citizen'],['driver','电源','电源,Driver,驱动,LED Driver,Mean Well,Lifud,Eaglerise'],['optic','光学','光学,透镜,反光杯,Reflector,Lens,蜂窝,格栅,防眩']];if(need)a.splice(1,0,['connector','接头','接头,连接器,Adapter,导轨头']);a.push(['extra','附件','附件,附加,配件,面环,线材,安装件,弹簧']);return a}
function partDefs(){return partDefsByType($('productType').value,S.product)}
function quotePartStopWords(){return /^(led|cob|driver|optic|optics|lens|reflector|adapter|connector|accessory|accessories|extra|chip|power|light|光学|透镜|反光杯|电源|驱动|芯片|灯珠|接头|连接器|附件|配件|物料|材料)$/i}
function quoteDropMaterialNameTokens(raw){
  return String(raw||'').split(/\s+/).map(x=>x.trim()).filter(Boolean).filter(t=>!/[\u4e00-\u9fff]/.test(t)&&!quotePartStopWords().test(t));
}
function quoteModelFromAsciiText(raw,modelHint=''){
  let hint=cleanParam(modelHint); if(hint)return hint;
  let toks=quoteDropMaterialNameTokens(raw); if(!toks.length)return '';
  let s=toks.join(' ');
  let m=s.match(/([A-Za-z0-9._-]+\s+Series\s*[A-Za-z0-9@._-]+(?:\s*[A-Za-z0-9@._-]+){0,2})/i); if(m)return m[1].replace(/\s+/g,' ').trim();
  m=s.match(/([A-Z]{1,8}[-_]?\d[A-Z0-9._-]*(?:\s+[A-Z0-9]{1,6}){0,3})$/i); if(m)return m[1].replace(/\s+/g,' ').trim();
  m=s.match(/(\d+(?:\.\d+)?\s*MM(?:\s+[A-Z0-9._-]+){0,3})$/i); if(m)return m[1].replace(/\s+/g,' ').trim();
  if(toks.length>4)return toks.slice(-3).join(' ');
  if(toks.length>1)return toks.slice(1).join(' ');
  return toks[0]||'';
}
function quoteBrandFromAsciiText(raw,brandHint=''){
  let hint=cleanParam(brandHint); if(hint)return hint;
  let toks=quoteDropMaterialNameTokens(raw); if(!toks.length)return '';
  return toks[0]||'';
}
function quoteBrandModelOnly(input,label=''){
  if(input&&typeof input==='object'){
    let brand=cleanParam(input.brand||input.manufacturer||input.factory||'');
    let model=cleanParam(input.model||input.model_no||input.product_model||input.code||input.sku||input.series||input.series_name||'');
    if(!model){ model=quoteModelFromAsciiText([input.name,input.spec,input.description].filter(Boolean).join(' ')); }
    if(!brand){ brand=quoteBrandFromAsciiText([input.brand,input.name,input.spec,input.description].filter(Boolean).join(' ')); }
    let out=[brand,model].filter(Boolean).join(' ').trim();
    return out||model||brand||cleanParam(input.spec||input.name||'');
  }
  let raw=cleanParam(input); if(!raw)return '';
  let brand=quoteBrandFromAsciiText(raw), model=quoteModelFromAsciiText(raw);
  if(model&&brand&&model.toLowerCase().startsWith(brand.toLowerCase()))return model;
  let out=[brand,model].filter(Boolean).join(' ').trim();
  return out||quoteDropMaterialNameTokens(raw).join(' ')||raw;
}
function quoteBrandModelFromProduct(p){p=p||{};let name=quoteBrandModelOnly({brand:p.brand||'',model:p.model||p.code||p.product_model||'',name:p.name||p.product_name||'',spec:p.spec||p.size||''});return name||p.code||p.model||p.name||'Material'}
function matText(m){return [m.category,m.brand,m.name,m.model,m.spec,m.keyword,m.supplier,m.unit].join(' ').toLowerCase()}
function quoteNormCat(v){return String(v||'').trim().toLowerCase().replace(/\s+/g,' ')}
function quotePartCategoryAliases(k){return {led:['芯片','灯珠','光源','cob','led chip','led芯片'],driver:['电源','驱动','led driver','driver'],optic:['光学','透镜','反光杯','lens','reflector','optic','optics'],connector:['接头','连接器','导轨头','adapter','connector','track head'],extra:['附件','附加','配件','面环','线材','安装件']}[k]||[]}
function quoteCategoryMatchesPart(k,m){let cat=quoteNormCat(m&&m.category);if(!cat)return false;return quotePartCategoryAliases(k).some(a=>{a=quoteNormCat(a);return cat===a||cat.includes(a)||a.includes(cat)})}
function materialBase(cats){let arr=String(cats||'').toLowerCase().split(',').map(x=>x.trim()).filter(Boolean);return DB.materials.filter(m=>arr.some(c=>matText(m).includes(c)))}
function quotePartIsPackagingText(t){return /纸卡|纸箱|纸盒|内盒|外盒|外箱|彩盒|包装|包材|吸塑|珍珠棉|泡棉|护角|标签|贴纸|说明书|吊牌|卡纸|opp\s*袋|pe\s*袋|poly\s*bag|carton|inner\s*box|outer\s*box|gift\s*box|color\s*box|packing|packaging|label|manual|k\s*=\s*a|k6a|牛皮色/i.test(String(t||''))}
function quoteMaterialKind(m){let t=matText(m);if(!t||quotePartIsPackagingText(t))return '';if(/\bled\s*driver\b|\bdriver\b|power\s*supply|constant\s*current|eaglerise|lifud|tridonic|mean\s*well|电源|驱动|伊戈尔|恒流/i.test(t))return 'driver';if(/connector|adapter|track\s*head|接头|转接|导轨头|连接器/i.test(t))return 'connector';if(/optic|optics|lens|reflector|dark\s*series|herculux|透镜|反光杯|反光|光学|恒坤|honeycomb|蜂窝|格栅|防眩/i.test(t))return 'optic';if(/\b(cob|cree|osram|bridgelux|citizen|xpg|xhp|cxb|cxa|vhd|gen8)\b|\bled\s*(chip|module|cob)\b|\b(chip|cob)\s*led\b|芯片|灯珠|普瑞/i.test(t))return 'led';if(/accessor|附件|配件|面环|线材|吊绳|弹簧|安装件|螺丝|螺钉/i.test(t))return 'extra';return ''}
function filterMatForPart(k,cats,kw=''){kw=String(kw||'').toLowerCase();let base=materialBase(cats);let byCat=DB.materials.filter(m=>quoteCategoryMatchesPart(k,m));let seen=new Set(),arr=[...byCat,...base].filter(m=>{let id=String(m.id||m.model||m.name||'');if(id&&seen.has(id))return false;if(id)seen.add(id);return quoteCategoryMatchesPart(k,m)||quoteMaterialKind(m)===k});return arr.filter(m=>!kw||matText(m).includes(kw))}
function filterMat(cats,kw=''){kw=String(kw||'').toLowerCase();return materialBase(cats).filter(m=>!kw||matText(m).includes(kw))}
function matName(m){return quoteBrandModelOnly(m)||'物料'}
function partChosenName(k){let m=S.parts&&S.parts[k];if(!m)return '未选';if(m.none)return '选无';return matName(m)}
function setPartTab(k){S.partTab=k;renderParts()}
function renderParts(){
  let box=$('partsBox'); if(!box)return;
  let defs=partDefs();
  if(!defs.some(d=>d[0]===S.partTab)) S.partTab=(defs[0]&&defs[0][0])||'led';
  let active=defs.find(d=>d[0]===S.partTab)||defs[0];
  let chosenCount=defs.filter(([k])=>S.parts&&S.parts[k]&&!S.parts[k].none).length;
  let summary=chosenCount?('已选 '+chosenCount+' 项'):'未选关键件';
  let tabs=defs.map(([k,n])=>{
    let has=!!(S.parts&&S.parts[k]&&!S.parts[k].none), none=!!(S.parts&&S.parts[k]&&S.parts[k].none);
    return `<button type="button" class="parts-tab-btn ${S.partTab===k?'active':''} ${has?'has-part':(none?'no-part':'')}" onclick="setPartTab('${k}')"><span class="parts-tab-dot"></span>${esc(n)}</button>`;
  }).join('');
  let chips=defs.map(([k,n])=>{
    let m=S.parts&&S.parts[k], cls=(!m||m.none)?'none':'';
    return `<span class="parts-chip ${cls}"><span>${esc(n)}</span><b>${esc(partChosenName(k))}</b></span>`;
  }).join('');
  let [k,n,c]=active||['led','芯片','芯片,COB,LED'];
  box.innerHTML=`<div class="parts-tabs-shell">
    <div class="parts-tabs-head"><span class="parts-tabs-title">配件区 / 关键件选项卡</span><span class="parts-tabs-summary">${esc(summary)}</span></div>
    <div class="parts-tab-buttons">${tabs}</div>
    <div class="parts-tab-panel"><div class="part part-${k}"><div class="title"><span>${esc(n)}</span><div class="part-actions"><button class="gray" onclick="clearPart('${k}')">选无</button><button class="blue" onclick='openPartModal("${k}",${JSON.stringify(n)},${JSON.stringify(c)})'>更多</button></div></div><input id="s-${k}" oninput="renderMatOptions('${k}','${c}')" placeholder="搜索品牌/名称/型号/规格"><div class="hint" id="chosen-${k}">当前：${esc(partChosenName(k))}</div><div class="mat-list" id="m-${k}"></div></div></div>
    <div class="parts-chosen-strip">${chips}</div>
  </div>`;
  renderMatOptions(k,c);
}
function capMatListHeight(el,maxRows=5){if(!el)return;requestAnimationFrame(()=>{let rows=[...el.querySelectorAll('.mat-row')];if(!rows.length){el.style.removeProperty('max-height');el.classList.add('is-short');return;}let count=Math.min(rows.length,maxRows),h=0;for(let i=0;i<count;i++){h+=rows[i].offsetHeight||74;}el.style.setProperty('max-height',Math.ceil(h+2)+'px','important');el.classList.toggle('is-short',rows.length<=maxRows);el.style.setProperty('overflow-y',rows.length>maxRows?'auto':'visible','important');});}
function renderMatOptions(k,c){let el=$('m-'+k),kw=$('s-'+k)?.value||'';let arr=filterMatForPart(k,c,kw);el.innerHTML=arr.length?arr.map((m,i)=>{let js=JSON.stringify(m).replaceAll("'","&#39;");return `<div class="mat-row" onclick='choosePart("${k}",${js})'><div><b>${esc(matName(m))}</b><small>${esc(m.category)} ｜ ${esc(m.spec)} ｜ ${esc(m.supplier)}</small></div><div style="display:flex;gap:6px;align-items:center"><div class="price">${money(m.price)}</div><button class="gray" style="padding:5px 7px" onclick='event.stopPropagation();addMaterialToQuote("${k}",${js})'>做PI</button></div></div>`}).join(''):'<div class="hint" style="padding:10px">无数据</div>';capMatListHeight(el,5)}

function materialSort(arr,sort){arr=[...arr];arr.sort((a,b)=>{if(sort==='priceAsc')return Number(a.price||0)-Number(b.price||0);if(sort==='priceDesc')return Number(b.price||0)-Number(a.price||0);if(sort==='brand')return String(a.brand||'').localeCompare(String(b.brand||''),'zh');if(sort==='name')return String(a.name||'').localeCompare(String(b.name||''),'zh');if(sort==='category')return String(a.category||'').localeCompare(String(b.category||''),'zh');return 0});return arr}
function partModalBase(){return filterMatForPart(partModalState.k,partModalState.cats)}
function refreshPartModalFilters(){let base=partModalBase();setSelectOptions('partModalCategory',base.map(m=>m.category),'全部分类');setSelectOptions('partModalBrand',base.map(m=>m.brand),'全部品牌');setSelectOptions('partModalSupplier',base.map(m=>m.supplier),'全部供应商');setSelectOptions('partModalUnit',base.map(m=>m.unit),'全部单位')}
function openPartModal(k,name,cats){partModalState={k:k,name:name,cats:cats};$('partModalTitle').textContent='更多选择'+name;['partModalSearch','partModalPriceMin','partModalPriceMax'].forEach(id=>{if($(id))$(id).value=''});if($('partModalSort'))$('partModalSort').value='match';refreshPartModalFilters();$('partModal').classList.add('show');renderPartModal()}
function closePartModal(){$('partModal').classList.remove('show')}
function clearPartModalFilters(){['partModalSearch','partModalPriceMin','partModalPriceMax'].forEach(id=>$(id).value='');['partModalCategory','partModalBrand','partModalSupplier','partModalUnit'].forEach(id=>$(id).value='');$('partModalSort').value='match';renderPartModal()}
function renderPartModal(){let kw=($('partModalSearch')?.value||'').toLowerCase(),cat=$('partModalCategory')?.value||'',brand=$('partModalBrand')?.value||'',supplier=$('partModalSupplier')?.value||'',unit=$('partModalUnit')?.value||'',min=Number($('partModalPriceMin')?.value||''),max=Number($('partModalPriceMax')?.value||''),sort=$('partModalSort')?.value||'match';let arr=partModalBase().filter(m=>(!kw||matText(m).includes(kw))&&(!cat||m.category===cat)&&(!brand||m.brand===brand)&&(!supplier||m.supplier===supplier)&&(!unit||m.unit===unit)&&(!$('partModalPriceMin')?.value||Number(m.price||0)>=min)&&(!$('partModalPriceMax')?.value||Number(m.price||0)<=max));arr=materialSort(arr,sort);if($('partModalCount'))$('partModalCount').textContent='找到 '+arr.length+' 个物料，点击一行选择；点“做PI”可直接作为报价产品。';$('partModalMaterials').innerHTML=arr.length?arr.map(m=>{let js=JSON.stringify(m).replaceAll("'","&#39;");return `<div class="part-modal-row" onclick='choosePartFromModal(${js})'><div><b>${esc(matName(m))}</b><small>${esc(m.category)} ｜ ${esc(m.spec)} ｜ ${esc(m.supplier)} ｜ ${esc(m.unit||'')}</small></div><div class="part-modal-actions"><div class="price">${money(m.price)}</div><button class="gray" onclick='event.stopPropagation();addMaterialFromPartModal(${js})'>做PI</button></div></div>`}).join(''):'<div class="hint" style="padding:12px">没有匹配物料</div>'}
function choosePartFromModal(m){choosePart(partModalState.k,m);closePartModal()}
function addMaterialFromPartModal(m){addMaterialToQuote(partModalState.k,m);closePartModal()}
function nextPartTabKey(k){let defs=partDefs();let idx=defs.findIndex(d=>d[0]===k);if(idx>=0&&idx<defs.length-1)return defs[idx+1][0];return k}
function focusActivePartSearch(){setTimeout(()=>{let el=$('s-'+S.partTab);if(el){try{el.focus({preventScroll:true});}catch(e){try{el.focus()}catch(_){}}}},80)}
function choosePart(k,m){S.parts[k]=m;S.partTab=nextPartTabKey(k);renderParts();focusActivePartSearch();updatePriceLevelHint();syncEditingItemFromForm();renderQuoteItems();render()}function clearPart(k){S.parts[k]={none:true};S.partTab=nextPartTabKey(k);renderParts();focusActivePartSearch();updatePriceLevelHint();syncEditingItemFromForm();renderQuoteItems();render()}function addMaterialToQuote(k,m){let qty=Number($('qty')?.value||1), moq=$('moq')?.value||'', color=$('color')?.value||'', price=Number(m.price||0);if(cur()==='USD'&&rate())price=price/rate();let name=(k==='led'?quotePartName(m):materialSaleName(k,m));let it={is_material_sale:true,product:{id:'mat-'+(m.id||m.model||m.name||Date.now()),name:name,brand:m.brand||'',model:m.model||'',code:m.model||'',category:m.category||'',size:m.spec||'',image:m.image||'',price_rmb:Number(m.price||0),price_usd:rate()?Number(m.price||0)/rate():Number(m.price||0)},parts:{},qty:qty,price:price*priceMultiplier(),amount:qty*price*priceMultiplier(),moq:moq,customer_code:String($('customerCode')?.value||'').trim(),beam_angle:cleanBeamAngleValue($('beamAngle')?.value||''),power:String($('power')?.value||'').trim(),color:color,extra_spec:quoteRemarksFromForm().join('\n'),quote_remarks:quoteRemarksFromForm(),cct:k==='led'?normCct($('cct')?.value||''):'',cri:k==='led'?normCri($('cri')?.value||''):'',product_type:'material',price_level_id:selectedPriceLevel().id||'',price_level_name:selectedPriceLevel().name||'',price_multiplier:priceMultiplier(),cost_price:price,cost_price_currency:cur(),cost_price_rmb:quoteMoneyToRmb(price,cur()),currency:cur(),manual_price:false};S.items.push(it);renderQuoteItems();render();}
function clone(o){return JSON.parse(JSON.stringify(o||{}))}
function normCct(v){v=String(v||'').trim().toUpperCase().replace(/\s+/g,'');if(!v)return '';return /K$/i.test(v)?v:(v+'K')}
function normCri(v){v=String(v||'').trim().toUpperCase().replace(/^CRI\s*[:：-]?\s*/i,'').replace(/\s+/g,'');if(!v)return '';return 'CRI'+v}
function cleanBeamAngleValue(v){
  v=String(v||'').trim().replace(/度/g,'').replace(/[°º˚]/g,'').replace(/\s+/g,'');
  if(!v)return '';
  // 输入框只保留数字；兼容 15x30 / 10-20 这类特殊角度，不在输入框显示 °。
  v=v.replace(/[^0-9.\-xX\/]/g,'');
  v=v.replace(/x/g,'X');
  return v;
}
function formatBeamAngleForSpec(v){
  v=cleanBeamAngleValue(v);
  if(!v)return '';
  if(/^\d+(?:\.\d+)?(?:[X\/\-]\d+(?:\.\d+)?)*$/.test(v))return v+'°';
  return v;
}
function cleanPowerValue(v){
  v=String(v||'').trim().replace(/瓦/gi,'W').replace(/ｗ/gi,'W').replace(/\s+/g,'');
  if(!v)return '';
  v=v.replace(/w$/i,'');
  v=v.replace(/[^0-9.\-+xX\/]/g,'');
  return v;
}
function formatPowerForSpec(v){
  let raw=String(v||'').trim();
  if(!raw)return '';
  let core=cleanPowerValue(raw);
  if(core&&/^\d+(?:\.\d+)?(?:[Xx\/\-]\d+(?:\.\d+)?)*$/.test(core))return core.toUpperCase()+'W';
  if(/w$/i.test(raw))return raw.replace(/w$/i,'W');
  return raw;
}
function normBeamAngle(v){return formatBeamAngleForSpec(v)}
function normalizeBeamAngleField(){let el=$('beamAngle');if(el)el.value=cleanBeamAngleValue(el.value)}
function quotePartName(m){return quoteBrandModelOnly(m)}
function quoteTextAsciiSeries(v){v=String(v||'').trim();if(!v)return '';let m=v.match(/([A-Za-z][A-Za-z0-9\-]*(?:\s+[A-Za-z][A-Za-z0-9\-]*)*\s+(?:Series|SERIES|series)\s*\d*[A-Za-z0-9\-]*)/);if(m)return m[1].replace(/\s+/g,' ').trim();let ascii=v.replace(/[\u4e00-\u9fff]/g,' ').replace(/[|/，,、]+/g,' ').replace(/\s+/g,' ').trim();ascii=ascii.replace(/^(optic|optics|光学|透镜|反光杯)\s*/i,'').trim();return ascii;}
function quoteOpticSpecText(input){return quoteBrandModelOnly(input,'Optic')}
function quotePartSpecName(k,m,item=null){if(k==='led')return ledSpecText(m,item);return quoteBrandModelOnly(m)}
function ledSpecText(m,item=null){let base=quotePartName(m);let cct=item?(item.cct||''):normCct($('cct')?.value||'');let cri=item?(item.cri||''):normCri($('cri')?.value||'');return [base,cct,cri].filter(Boolean).join(' ').trim()}
function materialSaleName(k,m){return k==='led'?ledSpecText(m):quoteBrandModelOnly(m)}
function isMaterialSaleItem(it){return !!(it&&(it.is_material_sale||it.product_type==='material'||String(it.product?.id||'').startsWith('mat-')))}
function materialSaleSpec(item){let p=item.product||{}, base=quoteBrandModelFromProduct(p), lines=[];let first=[base,item?.cct||'',item?.cri||''].filter(Boolean).join(' ').trim();if(first)lines.push(first);if(item?.ip)lines.push(normIp(item.ip));quoteRemarksOfItem(item).forEach(x=>lines.push(x));return lines.filter(Boolean).join('\n')}
function itemDisplayName(it){if(it?.is_material_sale)return it.product?.name||'物料';let led=it?.parts?.led;let chip=led&&!led.none?ledSpecText(led,it):'';return [it?.product?.name,chip].filter(Boolean).join(' ｜ ')||'未命名产品'}
function reviewProductTitleOnly(it){
  if(it?.is_material_sale) return it.product?.name || '物料';
  let name = String(it?.product?.name || '').trim();
  if(!name) return '未命名产品';
  // 审核预览的“产品”列只放产品/空壳名称；芯片、电源、光学、配件等配置只放到 Specification / 公式预览里。
  // 防止出现：SHOWLITE... | Bridgelux BXRH... 这种芯片型号被拼进产品标题。
  return name;
}
function currentEditorBaseCost(old=null){
  old=old||(S.editingIndex>=0&&Array.isArray(S.items)?S.items[S.editingIndex]:null);
  if(old && isMaterialSaleItem(old)){
    if(Number(old.cost_price_rmb||0)>0) return quoteMoneyFromRmb(Number(old.cost_price_rmb||0),cur());
    if(Number(old.cost_price||0)>0) return quoteConvertMoney(Number(old.cost_price||0),old.cost_price_currency||old.currency||cur(),cur());
  }
  let fresh=productPrice()+partsPrice(S.parts,cur());
  if(fresh>0) return fresh;
  if(old && Number(old.cost_price_rmb||0)>0) return quoteMoneyFromRmb(Number(old.cost_price_rmb||0),cur());
  if(old && Number(old.cost_price||0)>0) return quoteConvertMoney(Number(old.cost_price||0),old.cost_price_currency||old.currency||cur(),cur());
  return 0;
}
function currentAutoUnit(old=null){return currentEditorBaseCost(old)*priceMultiplier()}
function currentEditorItem(){
  clampCompactInputs();
  let qty=Number($('qty').value||1), old=(S.editingIndex>=0&&Array.isArray(S.items))?S.items[S.editingIndex]:null;
  let remarks=quoteRemarksFromForm();
  let manual=$('manualPrice').value!=='';
  let currency=cur();
  let base=currentEditorBaseCost(old);
  let price=manual?Number($('manualPrice').value||0):base*priceMultiplier();
  let common={
    qty,price,amount:qty*price,moq:$('moq').value,
    customer_code:String($('customerCode')?.value||'').trim(),
    beam_angle:cleanBeamAngleValue($('beamAngle')?.value||''),
    power:String($('power')?.value||'').trim(),
    color:$('color').value,
    extra_spec:remarks.join('\n'),
    quote_remarks:remarks,
    cct:normCct($('cct')?.value||''),
    cri:normCri($('cri')?.value||''),
    ip:normIp($('ip')?.value||''),
    price_level_id:selectedPriceLevel().id||'',
    price_level_name:selectedPriceLevel().name||'',
    price_multiplier:priceMultiplier(),
    cost_price:base,
    cost_price_currency:currency,
    cost_price_rmb:quoteMoneyToRmb(base,currency),
    manual_price:manual,
    currency
  };
  if(isMaterialSaleItem(old)){
    let it=clone(old);
    Object.assign(it,common);
    it.is_material_sale=true;
    it.product_type='material';
    it.parts={};
    if(!it.product)it.product=clone(S.product);
    return it;
  }
  return Object.assign({
    product:clone(S.product),
    parts:clone(S.parts),
    product_type:$('productType').value
  },common);
}
function itemProductPrice(item,c=cur()){let p=item&&item.product?item.product:{};return productPriceByCurrency(p,c)}
function itemPartsPrice(item,c=cur()){return partsPrice((item&&item.parts)||{},c)}
function itemBaseCost(item,c=cur()){
  if(!item) return 0;
  if(isMaterialSaleItem(item)){
    if(Number(item.cost_price_rmb||0)>0) return quoteMoneyFromRmb(Number(item.cost_price_rmb||0),c);
    if(Number(item.cost_price||0)>0) return quoteConvertMoney(Number(item.cost_price||0),item.cost_price_currency||item.currency||c,c);
    return Number(item.price||0);
  }
  let fresh=itemProductPrice(item,c)+itemPartsPrice(item,c);
  if(fresh>0) return fresh;
  if(Number(item.cost_price_rmb||0)>0) return quoteMoneyFromRmb(Number(item.cost_price_rmb||0),c);
  if(Number(item.cost_price||0)>0) return quoteConvertMoney(Number(item.cost_price||0),item.cost_price_currency||item.currency||c,c);
  return 0;
}
function itemAutoPrice(item,c=cur()){return itemBaseCost(item,c)*Number(item?.price_multiplier||priceMultiplier()||1)}
function itemUnitPrice(item,c=cur()){
  let manual=item&&(item.manual_price===true||item.manual_price===1||item.manual_price==='1');
  if(manual) return quoteConvertMoney(Number(item.price||0),item.currency||c,c);
  return itemAutoPrice(item,c);
}

function quoteItemsForPreview(){
  let arr=Array.isArray(S.items)?S.items:[];
  if(arr.length){
    return arr.map(it=>{
      let cp=normalizeQuoteItemCurrency(it,cur(),it.currency||cur());
      cp.price=itemUnitPrice(cp,cur());
      cp.amount=Number(cp.qty||0)*Number(cp.price||0);
      return cp;
    });
  }
  let it=currentEditorItem();
  if((it.product&&it.product.id)||Object.keys(it.parts||{}).length){
    it.price=itemUnitPrice(it,cur());
    it.amount=Number(it.qty||0)*Number(it.price||0);
    return [it];
  }
  return [];
}
function quoteTotal(){
  let arr=quoteItemsForPreview();
  return {
    qty:arr.reduce((s,it)=>s+Number(it.qty||0),0),
    amount:arr.reduce((s,it)=>s+Number(it.amount||Number(it.qty||0)*itemUnitPrice(it,cur())),0)
  };
}
function quotePartLabelByKey(k){return {led:'芯片 / LED',driver:'电源 / LED Driver',optic:'光学 / Optic',connector:'接头 / Adapter',extra:'配件 / Accessories'}[k]||k}
function quoteMoneySourceTextProduct(p,c=cur()){
  p=p||{}; c=String(c||cur()).toUpperCase();
  let rmb=Number(p.cost_rmb||0)||Number(p.price_rmb||0), usd=Number(p.cost_usd||0)||Number(p.price_usd||0);
  if(rmb>0 && c!=='RMB')return 'RMB '+money(rmb)+' ÷ 汇率 '+Number(rate()).toFixed(4);
  if(rmb>0 && c==='RMB')return 'RMB '+money(rmb);
  if(usd>0 && c==='RMB')return 'USD '+money(usd)+' × 汇率 '+Number(rate()).toFixed(4);
  if(usd>0)return 'USD '+money(usd);
  return '无成本';
}
function quoteMoneySourceTextPart(m,c=cur()){
  let rmb=Number(m?.price||0); c=String(c||cur()).toUpperCase();
  if(c==='RMB')return 'RMB '+money(rmb);
  return 'RMB '+money(rmb)+' ÷ 汇率 '+Number(rate()).toFixed(4);
}
function quoteItemBreakdown(item=null){
  let isCurrent=!item;
  let it=item?clone(item):currentEditorItem();
  let c=cur(), p=it.product||{}, parts=it.parts||{}, lines=[];
  let productCost=itemProductPrice(it,c);
  if(productCost>0 || p.id){lines.push({type:'product',label:'产品/空壳',name:[p.code,p.name].filter(Boolean).join(' - ')||p.name||p.code||'产品',amount:productCost,source:quoteMoneySourceTextProduct(p,c)});}
  if(!isMaterialSaleItem(it)){
    let defs=partDefsByType(it.product_type||$('productType')?.value||'track',p);
    defs.forEach(([k,n])=>{
      let m=parts&&parts[k];
      if(m&&m.none){lines.push({type:k,label:quotePartLabelByKey(k),name:'选无',amount:0,source:'不计成本'});return;}
      if(m){lines.push({type:k,label:quotePartLabelByKey(k),name:matName(m),amount:partPriceByCurrency(m,c),source:quoteMoneySourceTextPart(m,c)});}
    });
  }
  let subtotal=lines.reduce((sum,x)=>sum+Number(x.amount||0),0);
  let mult=Number(it.price_multiplier||priceMultiplier()||1);
  let auto=subtotal*mult;
  let manual=!!(it.manual_price===true||it.manual_price===1||it.manual_price==='1'||(isCurrent&&$('manualPrice')&&$('manualPrice').value!==''));
  let final=manual?Number(it.price||0):auto;
  let qty=Number(it.qty||0)||0;
  return {item:it,currency:c,lines,subtotal,multiplier:mult,auto,manual,final,qty,amount:qty*final,spec:buildSpec(it)};
}
function quoteBreakdownLineHtml(x){return `<div class="price-line"><div><b>${esc(x.label)}</b><small>${esc(x.name||'')}</small><small>${esc(x.source||'')}</small></div><span>${cur()} ${money(x.amount||0)}</span></div>`}
function showQuotePricePreview(item=null,index=-1){
  let b=quoteItemBreakdown(item);
  let formula=`产品/空壳 + 配件成本 = ${b.currency} ${money(b.subtotal)}\n`+
    `报价等级倍率 = × ${Number(b.multiplier).toFixed(4)}\n`+
    `自动单价 = ${b.currency} ${money(b.subtotal)} × ${Number(b.multiplier).toFixed(4)} = ${b.currency} ${money(b.auto)}\n`+
    (b.manual?`当前使用手动单价 = ${b.currency} ${money(b.final)}\n`:`当前使用自动单价 = ${b.currency} ${money(b.final)}\n`)+
    `金额 = 单价 ${b.currency} ${money(b.final)} × 数量 ${b.qty} = ${b.currency} ${money(b.amount)}`;
  let title=index>=0?`报价明细预览 #${index+1}`:'当前配置预览';
  let old=$('pricePreviewModal'); if(old)old.remove();
  document.body.insertAdjacentHTML('beforeend',`<div id="pricePreviewModal" class="modal show price-preview-modal"><div class="modal-box"><div class="price-preview-head"><h3>${esc(title)}</h3><button class="gray" onclick="closeQuotePricePreview()">关闭</button></div><div class="price-preview-grid"><div class="price-card"><div class="price-card-h">它是什么 + 什么</div><div class="price-card-b">${b.lines.length?b.lines.map(quoteBreakdownLineHtml).join(''):'<div class="hint">没有读取到成本项目</div>'}<div class="price-line total"><b>成本小计</b><span>${b.currency} ${money(b.subtotal)}</span></div><div class="price-line"><b>报价倍率</b><span>× ${Number(b.multiplier).toFixed(4)}</span></div><div class="price-line"><b>自动单价</b><span>${b.currency} ${money(b.auto)}</span></div>${b.manual?`<div class="price-warning">当前使用的是“手动单价”，所以最终单价不会按自动公式变化。</div>`:''}<div class="price-line final"><b>最终单价 / 金额</b><span>${b.currency} ${money(b.final)} ｜ ${b.qty} pcs = ${b.currency} ${money(b.amount)}</span></div></div></div><div class="price-card"><div class="price-card-h">价格公式 / Specification</div><div class="price-card-b"><div class="price-formula">${esc(formula)}</div><label>Specification 预览</label><div class="price-spec-preview">${esc(b.spec||'')}</div></div></div></div></div></div>`);
}
function closeQuotePricePreview(){let m=$('pricePreviewModal');if(m)m.remove()}
function openQuoteItemPreview(i){let it=(S.items||[])[i];if(!it){alert('找不到这行产品');return;}showQuotePricePreview(it,i)}
function previewCurrentEditor(){let it=currentEditorItem();if(!it.product||!it.product.id){alert('请先选择产品/空壳');return;}showQuotePricePreview(it,-1)}
function approvalLabel(v){v=String(v||'').toLowerCase();return {pending:'待审核',approved:'已审核',rejected:'已驳回',draft:'草稿',new:'新报价'}[v]||v||'待审核'}
function approvalClass(v){v=String(v||'pending').toLowerCase();return ['pending','approved','rejected','draft'].includes(v)?v:'pending'}
function updateQuoteApprovalStrip(q=null){let el=$('quoteApprovalStrip');if(!el)return;let st=(q?quoteApprovalStatus(q):S.currentApprovalStatus)||'new';el.className='quote-approval-strip '+approvalClass(st);let text='审核状态：'+approvalLabel(st);if(q&&q.approved_at)text+=' ｜ 审核时间：'+q.approved_at;if(st==='pending')text+=' ｜ 未审核不能导出/转订单';if(st==='approved')text+=' ｜ 可导出';if(st==='rejected')text+=' ｜ 请修改后重新保存提交审核';el.textContent=text;}
function quoteApprovalStatus(q){return String(q?.approval_status||q?.review_status||'pending').toLowerCase()}
function quoteIsApproved(q){return quoteApprovalStatus(q)==='approved'}
function quoteApprovalBadge(q){let st=quoteApprovalStatus(q);return `<span class="approval-badge ${approvalClass(st)}">${esc(approvalLabel(st))}</span>`}
function currentSavedQuote(){let no=String($('quoteNo')?.value||'').trim();if(S.currentQuoteId){let q=(DB.quotes||[]).find(x=>String(x.id)===String(S.currentQuoteId));if(q)return q;}if(!no)return null;return (DB.quotes||[]).find(x=>String(x.quote_no||'')===no)||null;}
function quoteCurrentOwnerForSave(){let q=currentSavedQuote();let old=(q&&q.user_name)?String(q.user_name).trim():(S.currentQuoteOwner?String(S.currentQuoteOwner).trim():'');if(old)return old;return (AUTH.user?.username||AUTH.user?.display_name||'boss');}
function quoteApplySavedDocConfig(q){if(!q)return;let header=parseMaybeJson(q.header_json,{}),bank=parseMaybeJson(q.bank_json,{}),template=parseMaybeJson(q.template_json,{});if(quoteNonEmptyObject(header))S.header=header;else{let h=quoteConfigById(DB.headers,q.header_id);if(quoteNonEmptyObject(h))S.header=clone(h);}if(quoteNonEmptyObject(bank))S.bank=bank;else{let b=quoteConfigById(DB.banks,q.bank_id);if(quoteNonEmptyObject(b))S.bank=clone(b);}if(quoteNonEmptyObject(template))S.template=template;else{let t=quoteConfigById(DB.templates,q.template_id);if(quoteNonEmptyObject(t))S.template=clone(t);}syncDefaultSettingSelects();syncQdocSelects();fillQdocForms();renderQdocQuickSettings();}
function currentApprovedQuote(){let q=currentSavedQuote();return q&&quoteIsApproved(q)?q:null;}
function requireApprovedForOutput(kind='导出'){let q=currentApprovedQuote();if(q)return q;let saved=currentSavedQuote();let st=saved?approvalLabel(quoteApprovalStatus(saved)):'未保存';alert(kind+'前必须先审核通过。当前状态：'+st+'。请先保存报价，再到“未审核列表”审核。');return null;}
async function loadApprovalList(){DB=await api('init');renderApproval();renderHistory();updateQuoteApprovalStrip(currentSavedQuote());}
function setApprovalStatus(st){let el=$('approvalStatus');if(el)el.value=st||'pending';renderApproval();}
function renderApproval(){
  let box=$('approvalList');if(!box)return;
  let kw=String($('approvalSearch')?.value||'').toLowerCase().trim();
  let st=$('approvalStatus')?.value||'pending';
  let sort=$('approvalSort')?.value||'new';
  let arr=(DB.quotes||[]).filter(q=>{
    let s=quoteApprovalStatus(q);
    let ok=st==='all'?true:s===st;
    let hay=[q.quote_no,q.customer_name,q.user_name,q.customer_json,q.items_json,q.amount,q.currency,q.approval_note,q.approved_by,q.rejected_by].join(' ').toLowerCase();
    return ok&&(!kw||hay.includes(kw));
  });
  arr.sort((a,b)=>{if(sort==='amountDesc')return Number(b.amount||0)-Number(a.amount||0);if(sort==='customer')return String(a.customer_name||'').localeCompare(String(b.customer_name||''),'zh');return String(b.submitted_at||b.updated_at||b.created_at||'').localeCompare(String(a.submitted_at||a.updated_at||a.created_at||''));});
  if($('approvalCount'))$('approvalCount').textContent='共 '+arr.length+' 条';
  box.innerHTML=arr.map(q=>{
    let c=quoteCustomer(q), stNow=quoteApprovalStatus(q), approved=stNow==='approved';
    let who=approved?('审核：'+(q.approved_by||'-')+' ｜ '+(q.approved_at||'')):(stNow==='rejected'?('驳回：'+(q.rejected_by||'-')+' ｜ '+(q.rejected_at||'')):('提交：'+(q.submitted_at||q.updated_at||q.created_at||'')));
    let primaryBtn=approved?'查看日志':'审核预览';
    let actions=hasPerm('quote_approve')?`<button class="blue" onclick="openQuoteReview(${Number(q.id)})">${primaryBtn}</button>${approved?`<button class="orange" onclick="reverseApproveQuoteQuick(${Number(q.id)})">反审</button>`:`<button class="red" onclick="rejectQuoteQuick(${Number(q.id)})">驳回</button>`}`:`<button class="gray" onclick="openQuoteReview(${Number(q.id)})">查看日志</button><span class="hint">无审核权限</span>`;
    return `<div class="approval-row"><div><b>${quoteApprovalBadge(q)}${esc(q.quote_no||'')}</b><small>${esc(c.company||q.customer_name||'未选客户')} ｜ ${esc(q.quote_date||'')} ｜ ${esc(q.user_name||'')} ｜ ${esc(q.currency||'USD')} ${money(q.amount||0)} ｜ ${esc(who)}</small>${q.approval_note?`<small>审核备注：${esc(q.approval_note)}</small>`:''}</div><div class="approval-actions">${actions}<button class="gray" onclick="openQuoteReview(${Number(q.id)})">日志</button><button class="gray" onclick="loadQuote(${Number(q.id)})">打开</button></div></div>`
  }).join('')||'<div class="hint" style="padding:16px">暂无报价。</div>';
}
function quoteReviewImage(it){let p=(it&&it.product)||{};return String((it&&(it.image||it.product_image))||p.image||p.product_image||p.main_image||p.image_path||p.cover||p.cover_url||'').trim()}
function reviewRowMultiplier(tr,it){let raw=tr?tr.querySelector('.review-multiplier')?.value:'';let v=Number(raw||it?.price_multiplier||it?.approved_multiplier||it?.multiplier||priceMultiplier()||1);return v>0?v:1}
function reviewMultiplierChanged(el){let tr=el?el.closest('tr[data-review-row]'):null;if(!tr)return;let subtotal=Number(el.dataset.subtotal||0);let mult=Number(el.value||0);if(mult>0&&subtotal>0){let price=tr.querySelector('.review-price');if(price)price.value=(subtotal*mult).toFixed(2);}reviewRecalc()}
function reviewItemDetailText(it,idx=null){
  it=clone(it||{});
  let qty=Number(it?.qty||1), finalPrice=Number(it?.price||0), multiplier=Number(it?.price_multiplier||it?.approved_multiplier||priceMultiplier()||1);
  if(idx!==null){
    let tr=document.querySelector(`#quoteReviewRows tr[data-review-row="${idx}"]`);
    if(tr){
      qty=Number(tr.querySelector('.review-qty')?.value||qty);
      finalPrice=Number(tr.querySelector('.review-price')?.value||finalPrice);
      multiplier=reviewRowMultiplier(tr,it);
    }
  }
  it.qty=qty;it.price=finalPrice;it.amount=qty*finalPrice;it.price_multiplier=multiplier;it.approved_multiplier=multiplier;it.manual_price=true;
  let b=quoteItemBreakdown(it||{});
  let detail=(b.lines||[]).map(x=>`${x.label}: ${x.name||''}\n${x.source||''} = ${b.currency} ${money(x.amount||0)}`).join('\n\n');
  detail+=`\n\n成本小计 = ${b.currency} ${money(b.subtotal)}\n审核倍率 = × ${Number(multiplier).toFixed(4)}\n按倍率单价 = ${b.currency} ${money(b.subtotal)} × ${Number(multiplier).toFixed(4)} = ${b.currency} ${money(b.subtotal*multiplier)}\n审核单价 = ${b.currency} ${money(finalPrice)}\n审核数量 = ${fmtNum(qty)} PCS\n审核金额 = ${b.currency} ${money(qty*finalPrice)}`;
  let spec=buildSpec(Object.assign({},it,{qty,price:finalPrice,amount:qty*finalPrice,price_multiplier:multiplier}))||'';
  if(spec) detail+=`\n\nSpecification\n${spec}`;
  return detail;
}
function reviewItemRows(items){S.quoteReviewItems=clone(items||[]);return (items||[]).map((it,i)=>{let b=quoteItemBreakdown(it);let qty=Number(it.qty||1),price=Number(b.final||it.price||0),amount=qty*price,mult=Number(b.multiplier||it.price_multiplier||1)||1,img=quoteReviewImage(it);return `<tr data-review-row="${i}"><td class="review-num-cell review-index-cell">${i+1}</td><td class="review-product-td"><div class="review-prod-cell"><div class="review-prod-img">${img?`<img src="${esc(quoteDirectImageUrl(img))}" loading="lazy" decoding="async">`:'无图'}</div><div class="review-prod-info"><b class="review-prod-title">${esc(reviewProductTitleOnly(it))}</b><div class="review-prod-sub">${esc(it.product?.code||'')} ｜ ${esc(it.color||'')}</div></div></div></td><td class="review-num-cell"><input class="review-qty" type="number" step="1" min="0" value="${esc(qty)}" oninput="reviewRecalc()"></td><td class="review-num-cell"><input class="review-price" type="number" step="0.01" min="0" value="${esc(price.toFixed(2))}" oninput="reviewRecalc()"></td><td class="review-num-cell"><input class="review-amount" readonly value="${esc(amount.toFixed(2))}"></td><td class="review-action-cell"><div class="review-cost-tools"><button class="gray review-cost-btn" onclick="openReviewCostDetail(${i})">公式</button><div class="review-multiplier-wrap"><span>倍率</span><input class="review-multiplier" type="number" step="0.01" min="0" value="${esc(mult.toFixed(2))}" data-subtotal="${esc(b.subtotal||0)}" oninput="reviewMultiplierChanged(this)"></div></div></td><td class="review-spec-td"><div class="review-spec">${esc(buildSpec(it)||'')}</div></td></tr>`}).join('')}
function openReviewCostDetail(i){
  let items=S.quoteReviewItems||[];
  let it=clone(items[i]||{});
  if(!it||!it.product){alert('找不到该产品配置');return;}
  let tr=document.querySelector(`#quoteReviewRows tr[data-review-row="${i}"]`);
  if(tr){let qty=Number(tr.querySelector('.review-qty')?.value||it.qty||1),price=Number(tr.querySelector('.review-price')?.value||it.price||0),mult=reviewRowMultiplier(tr,it);it.qty=qty;it.price=price;it.amount=qty*price;it.price_multiplier=mult;it.approved_multiplier=mult;it.manual_price=true;}
  let img=quoteReviewImage(it);
  let old=$('reviewCostDetailModal');if(old)old.remove();
  document.body.insertAdjacentHTML('beforeend',`<div id="reviewCostDetailModal" class="modal show review-cost-modal"><div class="modal-box"><div class="review-head"><div><h2 style="margin:0">成本 / 配置预览</h2><div class="review-meta">${esc(reviewProductTitleOnly(it))} ｜ ${esc(it.product?.code||'')} ｜ ${esc(it.color||'')}</div></div><button class="gray" onclick="closeReviewCostDetail()">关闭</button></div><div class="review-cost-product-top"><div class="review-prod-img">${img?`<img src="${esc(quoteDirectImageUrl(img))}" loading="lazy" decoding="async">`:'无图'}</div><div><b>${esc(reviewProductTitleOnly(it))}</b><div class="hint">${esc(it.product?.code||'')} ｜ ${esc(it.color||'')}</div></div></div><div class="review-cost-body"><div class="review-detail-title">成本组成 / 计算公式</div><pre class="review-price-detail review-price-detail-pop">${esc(reviewItemDetailText(it,i))}</pre></div></div></div>`);
}
function closeReviewCostDetail(){let m=$('reviewCostDetailModal');if(m)m.remove();}
function quoteApprovalLogs(q){let arr=[];try{arr=JSON.parse(q?.approval_log_json||'[]')}catch(e){arr=[]}return Array.isArray(arr)?arr:[]}
function approvalLogActionText(a){return {submit:'提交审核',approve:'审核通过',reject:'驳回',unapprove:'反审退回',resubmit:'重新提交'}[a]||a||'记录'}
function approvalLogChangeHtml(changes){if(!Array.isArray(changes)||!changes.length)return '<div class="review-audit-changes">无数量/单价/倍率修改</div>';return '<div class="review-audit-changes">'+changes.map(c=>{let name=c.name||c.product||('第'+(Number(c.index||0)+1)+'项');let parts=[];if(c.qty_changed)parts.push('数量 '+c.old_qty+' → '+c.new_qty);if(c.multiplier_changed)parts.push('倍率 ×'+c.old_multiplier+' → ×'+c.new_multiplier);if(c.price_changed)parts.push('单价 '+c.old_price+' → '+c.new_price);if(c.amount_changed)parts.push('金额 '+c.old_amount+' → '+c.new_amount);return '<span>'+esc(name+'：'+(parts.join('，')||'无变化'))+'</span>';}).join('')+'</div>'}
function renderApprovalAuditBox(q){let logs=quoteApprovalLogs(q);let body=logs.length?logs.slice().reverse().map(l=>`<div class="review-audit-row"><b>${esc(approvalLogActionText(l.action))}</b> ｜ ${esc(l.user||'-')} ｜ ${esc(l.time||'')} ${l.note?`<div>备注：${esc(l.note)}</div>`:''}${approvalLogChangeHtml(l.changes||[])}</div>`).join(''):'<div class="review-audit-row">暂无审核日志；本次审核后会自动记录审核人、时间、数量/单价修改。</div>';return `<div class="review-audit-box"><div class="review-audit-title">审核日志</div>${body}</div>`}

function reviewDocTermsRows(template,q,payload){
  let terms=parseMaybeJson((template||{}).terms_json,[]);
  if(!Array.isArray(terms)||!terms.length) terms=defaultQuoteTerms();
  let qno=String((payload&&payload.quote_no)||q?.quote_no||$('quoteNo')?.value||'');
  let qdate=String((payload&&payload.quote_date)||q?.quote_date||$('quoteDate')?.value||'');
  terms=terms.map(r=>[String((r&&r[0])||'').replace(/QTNO/g,qno).replace(/DATE/g,qdate),String((r&&r[1])||'').replace(/QTNO/g,qno).replace(/DATE/g,qdate)]);
  if(terms[0]) terms[0][1]=qno;
  let qd=terms.find(r=>/Quoted Date/i.test(r[0])); if(qd) qd[1]=qdate;
  return terms;
}
function renderReviewDocInfo(payload,q){
  payload=payload||{};
  let h=payload.header||{}, b=payload.bank||{}, t=payload.template||{};
  let terms=reviewDocTermsRows(t,q,payload);
  let headerLines=[];
  if(h.name) headerLines.push('抬头：'+h.name);
  if(h.company) headerLines.push('公司：'+h.company);
  if(h.from_text) headerLines.push(String(h.from_text).trim());
  if(!headerLines.length) headerLines.push('未读取到公司抬头，导出时会使用系统默认抬头。');
  let bankLines=[];
  if(b.name) bankLines.push('银行：'+b.name);
  if(b.text) bankLines.push(String(b.text).trim());
  if(!bankLines.length) bankLines.push('未读取到银行信息。');
  let extra=String(b.extra_terms||b.terms_text||'').trim();
  let termsHtml=terms.length?`<table class="review-doc-terms">${terms.map(r=>`<tr><td>${esc(r[0]||'')}</td><td>${esc(r[1]||'')}</td></tr>`).join('')}</table>`:'<div class="line">未读取到付款/条款模板。</div>';
  return `<div class="review-doc-box"><div class="review-doc-card"><b>公司抬头</b><div class="line">${esc(headerLines.join('\n'))}</div></div><div class="review-doc-card"><b>付款条件 / 报价条款</b>${termsHtml}</div><div class="review-doc-card"><b>银行信息</b><div class="line">${esc(bankLines.join('\n'))}</div></div></div>`;
}
function openQuoteReview(id){if(!hasPerm('quote_approve')&&!hasPerm('quote_review_view')){alert('当前账号没有查看审核列表权限');return;}let q=(DB.quotes||[]).find(x=>String(x.id)===String(id));if(!q){alert('找不到报价');return;}let payload=payloadFromSavedQuote(q);let items=payload.items||[];let old=$('quoteReviewModal');if(old)old.remove();document.body.insertAdjacentHTML('beforeend',`<div id="quoteReviewModal" class="modal show review-modal" data-quote-id="${Number(q.id)}"><div class="modal-box"><div class="review-head"><div><h2 style="margin:0">报价审核预览</h2><div class="review-meta">${quoteApprovalBadge(q)}${esc(q.quote_no||'')} ｜ ${esc((payload.customer||{}).company||q.customer_name||'')} ｜ ${esc(q.currency||payload.currency||cur())} ${money(q.amount||0)}<br>审核前可修改数量、倍率和最终单价；成本/公式点“公式预览”弹窗查看。</div></div><button class="gray" onclick="closeQuoteReview()">关闭</button></div>${renderApprovalAuditBox(q)}${renderReviewDocInfo(payload,q)}<div style="overflow:auto"><table class="review-table"><thead><tr><th>#</th><th>产品</th><th>数量</th><th>审核单价</th><th>金额</th><th class="review-cost-th">成本公式</th><th>Specification</th></tr></thead><tbody id="quoteReviewRows">${reviewItemRows(items)}</tbody></table></div><div class="btns" style="justify-content:space-between"><div class="review-total" id="quoteReviewTotal">合计：0</div><div style="flex:1;max-width:520px"><textarea id="quoteReviewNote" class="review-note" placeholder="审核备注，可空；反审/驳回原因也写这里"></textarea></div>${!hasPerm('quote_approve')?'':(quoteApprovalStatus(q)==='approved'?`<button class="orange" onclick="reverseApproveFromModal()">反审退回</button>`:`<button class="green" onclick="approveQuoteFromModal()">审核通过</button><button class="red" onclick="rejectQuoteFromModal()">驳回</button>`) }</div></div></div>`);reviewRecalc();}
function closeQuoteReview(){let m=$('quoteReviewModal');if(m)m.remove();}
function reviewRecalc(){let rows=[...document.querySelectorAll('#quoteReviewRows tr[data-review-row]')];let qty=0,amount=0;rows.forEach(tr=>{let q=Number(tr.querySelector('.review-qty')?.value||0),p=Number(tr.querySelector('.review-price')?.value||0),a=q*p;qty+=q;amount+=a;let am=tr.querySelector('.review-amount');if(am)am.value=a.toFixed(2);});if($('quoteReviewTotal'))$('quoteReviewTotal').textContent='合计：'+fmtNum(qty)+' PCS ｜ '+cur()+' '+money(amount);}
function collectReviewItems(baseItems){let rows=[...document.querySelectorAll('#quoteReviewRows tr[data-review-row]')];return rows.map((tr,i)=>{let it=clone((baseItems||[])[i]||{});let qty=Number(tr.querySelector('.review-qty')?.value||0),price=Number(tr.querySelector('.review-price')?.value||0),mult=reviewRowMultiplier(tr,it);it.qty=qty;it.price=price;it.unit_price=price;it.amount=qty*price;it.manual_price=true;it.approved_price=price;it.approved_qty=qty;it.price_multiplier=mult;it.approved_multiplier=mult;it.specification=buildSpec(it);return it;});}
async function approveQuoteFromModal(){if(!hasPerm('quote_approve')){alert('当前账号没有审核权限');return;}let modal=$('quoteReviewModal');if(!modal)return;let id=Number(modal.dataset.quoteId||0);let q=(DB.quotes||[]).find(x=>String(x.id)===String(id));if(!q)return;let payload=payloadFromSavedQuote(q);let items=collectReviewItems(payload.items||[]);if(!items.length){alert('没有可审核产品');return;}let note=$('quoteReviewNote')?.value||'';if(!confirm('确认审核通过？审核通过后才允许导出。'))return;let r=await api('approve_quote',{id,items,note});closeQuoteReview();S.currentQuoteId=id;S.currentApprovalStatus='approved';await refreshQuoteRuntime('approve_quote',{orders:true,documents:true});updateQuoteApprovalStrip((DB.quotes||[]).find(x=>String(x.id)===String(id)));alert('审核通过，已同步 CRM 提醒。');}
async function rejectQuoteFromModal(){if(!hasPerm('quote_approve')){alert('当前账号没有审核权限');return;}let modal=$('quoteReviewModal');if(!modal)return;await rejectQuoteQuick(Number(modal.dataset.quoteId||0),$('quoteReviewNote')?.value||'');closeQuoteReview();}
async function rejectQuoteQuick(id,note=''){if(!hasPerm('quote_approve')){alert('当前账号没有审核权限');return;}if(!id)return;if(note==='')note=prompt('请输入驳回原因，可空：','')||'';if(!confirm('确认驳回这张报价？'))return;await api('reject_quote',{id,note});if(String(S.currentQuoteId)===String(id))S.currentApprovalStatus='rejected';await refreshQuoteRuntime('reject_quote',{orders:true,documents:true});if(String(S.currentQuoteId)===String(id)){updateQuoteApprovalStrip((DB.quotes||[]).find(x=>String(x.id)===String(id)));}alert('已驳回，并已同步 CRM 提醒。');}
async function reverseApproveFromModal(){let modal=$('quoteReviewModal');if(!modal)return;await reverseApproveQuoteQuick(Number(modal.dataset.quoteId||0),$('quoteReviewNote')?.value||'');closeQuoteReview();}
async function reverseApproveQuoteQuick(id,note=''){if(!hasPerm('quote_approve')){alert('当前账号没有审核权限');return;}if(!id)return;if(note==='')note=prompt('请输入反审原因，可空：','')||'';if(!confirm('确认反审？反审后此报价会回到待审核，不能导出和转订单。'))return;await api('unapprove_quote',{id,note});if($('approvalStatus'))$('approvalStatus').value='pending';if(String(S.currentQuoteId)===String(id))S.currentApprovalStatus='pending';await refreshQuoteRuntime('unapprove_quote',{orders:true,documents:true});if(String(S.currentQuoteId)===String(id)){updateQuoteApprovalStrip((DB.quotes||[]).find(x=>String(x.id)===String(id)));}alert('已反审，报价已退回待审核。');}


function quoteRemarksFromForm(){return [1,2,3,4].map(i=>String($('remark'+i)?.value||'').trim()).filter(Boolean)}
function quoteRemarksOfItem(it){let arr=it&&Array.isArray(it.quote_remarks)?it.quote_remarks:[];if((!arr||!arr.length)&&it&&it.extra_spec){arr=String(it.extra_spec||'').split(/\n+/).map(x=>x.trim()).filter(Boolean)}return (arr||[]).map(x=>String(x||'').trim()).filter(Boolean).slice(0,4)}
function setQuoteRemarksToForm(it){let arr=quoteRemarksOfItem(it||{});for(let i=1;i<=4;i++){if($('remark'+i))$('remark'+i).value=arr[i-1]||''}}
function clearQuoteRemarksForm(){for(let i=1;i<=4;i++){if($('remark'+i))$('remark'+i).value=''}}
function clampCompactInputs(){let lim={qty:99999,manualPrice:9999,moq:99999,rate:8.88};Object.entries(lim).forEach(([id,max])=>{let el=$(id);if(!el||el.value==='')return;let v=Number(el.value);if(v>max)el.value=max;if(v<0)el.value=0});['cct','cri','ip'].forEach(id=>{let el=$(id);if(el)el.value=String(el.value||'').trim().toUpperCase()});normalizeBeamAngleField()}
function loadItemToEditor(it,idx=-1){S.product=clone(it.product);S.parts=clone(it.parts||{});S.editingIndex=idx;$('productType').value=it.product_type||S.product?.type||'track';renderProductSelect();$('productSelect').value=S.product?.id||'';syncProductSearchFromSelect();$('qty').value=it.qty||1;$('moq').value=it.moq||S.product?.moq||200;fillOptionSelect('color',colorItems(),it.color||S.product?.color||'White');if($('beamAngle'))$('beamAngle').value=cleanBeamAngleValue(it.beam_angle||it.beamAngle||'');if($('power'))$('power').value=it.power||S.product?.power||'';if($('cct'))$('cct').value=it.cct||'';if($('cri'))$('cri').value=it.cri||'';if($('ip'))$('ip').value=it.ip||'';if($('customerCode'))$('customerCode').value=it.customer_code||'';$('extraSpec').value=it.extra_spec||'';setQuoteRemarksToForm(it);if($('priceLevel')&&it.price_level_id){$('priceLevel').value=it.price_level_id;}if($('priceMultiplierCustom'))$('priceMultiplierCustom').value=Number(it.price_multiplier||selectedPriceLevel().multiplier||1).toFixed(2);let isManual=it.manual_price===true || it.manual_price===1 || it.manual_price==='1';$('manualPrice').value=isManual?(it.price!==undefined?money(it.price):''):'';updatePriceLevelHint();renderParts();updateProductLinkHint();render()}
function syncEditingItemFromForm(){if(S.editingIndex<0||!Array.isArray(S.items)||!S.items[S.editingIndex])return;let it=currentEditorItem();if(!it.product||!it.product.id)return;S.items[S.editingIndex]=it;}
function addOrUpdateQuoteItem(){let it=currentEditorItem();if(!it.product||!it.product.id){alert('请先选择产品/空壳');return;}it.amount=Number(it.qty||0)*Number(it.price||0);let mode=S.editingIndex>=0?'更新产品行':'新增产品行';if(S.editingIndex>=0){S.items[S.editingIndex]=it;}else{S.items.push(it);}clientLog('quote_item_upsert',mode,{item:it,item_count:S.items.length});S.editingIndex=-1;renderQuoteItems();render();}
function editQuoteItem(i){let it=S.items[i];if(!it)return;loadItemToEditor(it,i)}
function removeQuoteItem(i){let old=(S.items||[])[i];S.items.splice(i,1);clientLog('quote_item_remove','删除报价产品行',{index:i,item:old,item_count:S.items.length});if(S.editingIndex===i)S.editingIndex=-1;renderQuoteItems();render()}
let quoteDragFrom=-1;
function quoteClearDragMarks(){document.querySelectorAll('.quote-item-row.dragging,.quote-item-row.drag-over-before,.quote-item-row.drag-over-after').forEach(el=>el.classList.remove('dragging','drag-over-before','drag-over-after'))}
function quoteDragStart(e,i){quoteDragFrom=i;if(e.dataTransfer){e.dataTransfer.effectAllowed='move';e.dataTransfer.setData('text/plain',String(i));}let row=e.target.closest('.quote-item-row');if(row)row.classList.add('dragging')}
function quoteDragOver(e,i){if(quoteDragFrom<0||i===quoteDragFrom)return;e.preventDefault();let row=e.currentTarget, r=row.getBoundingClientRect(), after=e.clientY>r.top+r.height/2;document.querySelectorAll('.quote-item-row.drag-over-before,.quote-item-row.drag-over-after').forEach(el=>el.classList.remove('drag-over-before','drag-over-after'));row.classList.add(after?'drag-over-after':'drag-over-before')}
function quoteDragLeave(e){if(!e.currentTarget.contains(e.relatedTarget)){e.currentTarget.classList.remove('drag-over-before','drag-over-after')}}
function quoteDrop(e,to){e.preventDefault();let from=Number((e.dataTransfer&&e.dataTransfer.getData('text/plain'))||quoteDragFrom);let r=e.currentTarget.getBoundingClientRect(), after=e.clientY>r.top+r.height/2;quoteClearDragMarks();quoteDragFrom=-1;if(!Array.isArray(S.items)||from<0||from>=S.items.length)return;let insert=to+(after?1:0);if(from<insert)insert--;if(insert<0)insert=0;if(insert>S.items.length-1)insert=S.items.length-1;if(from===insert)return;let editingItem=S.editingIndex>=0?S.items[S.editingIndex]:null;let moved=S.items.splice(from,1)[0];S.items.splice(insert,0,moved);clientLog('quote_item_reorder','拖拽调整报价产品顺序',{from,insert,item:moved,order:S.items.map((x,i)=>({i:i+1,name:itemDisplayName(x),code:x.product?.code||''}))});S.editingIndex=editingItem?S.items.indexOf(editingItem):-1;renderQuoteItems();render()}
function quoteDragEnd(){quoteClearDragMarks();quoteDragFrom=-1}

function clearCurrentEditor(){S.product=null;S.parts={};S.editingIndex=-1;$('productSelect').value='';syncProductSearchFromSelect();$('qty').value=1;$('manualPrice').value='';$('moq').value=200;fillOptionSelect('color',colorItems(),'White');if($('beamAngle'))$('beamAngle').value='';if($('power'))$('power').value='';if($('cct'))$('cct').value='';if($('cri'))$('cri').value='';if($('ip'))$('ip').value='';if($('customerCode'))$('customerCode').value='';$('extraSpec').value='';clearQuoteRemarksForm();syncPriceMultiplierFromLevel(false);renderParts();render()}
function renderQuoteItems(){
  let box=$('quoteItemsList');if(!box)return;
  let arr=S.items||[];
  $('quoteItemsCount').textContent=arr.length+' 个产品';
  box.innerHTML=arr.length?arr.map((it,i)=>{
    let unit=itemUnitPrice(it,cur()), amount=Number(it.qty||0)*Number(unit||0);
    return `<div class="quote-item-row ${S.editingIndex===i?'active':''}" data-index="${i}" ondragover="quoteDragOver(event,${i})" ondragleave="quoteDragLeave(event)" ondrop="quoteDrop(event,${i})"><div class="quote-drag-handle" draggable="true" title="拖动调整顺序" ondragstart="quoteDragStart(event,${i})" ondragend="quoteDragEnd(event)">≡</div><div class="quote-item-main"><b>${i+1}. ${esc(itemDisplayName(it))}</b><small>${esc(it.product?.code||'')} ｜ Qty ${it.qty||0} ｜ ${cur()} ${money(amount)} ｜ 单价 ${money(unit)}</small></div><div class="quote-item-actions"><button class="preview-btn" onclick="openQuoteItemPreview(${i})">预览</button><button class="blue" onclick="editQuoteItem(${i})">编辑</button><button class="gray" onclick="loadItemToEditor(S.items[${i}],-1)">复制</button><button class="red" onclick="removeQuoteItem(${i})">删除</button></div></div>`;
  }).join(''):'<div class="hint" style="padding:10px">还没有产品。点选产品后会自动加入报价单；再逐条编辑参数。</div>'
}

function cur(){return $('currency')?.value||'USD'}
function rate(){let r=Number($('rate')?.value||$('topRate')?.value||0);return r>0?r:7}
function quoteConvertMoney(v,fromCur,toCur){
  v=Number(v||0);fromCur=String(fromCur||cur()).toUpperCase();toCur=String(toCur||cur()).toUpperCase();
  if(fromCur===toCur) return v;
  let r=rate()||7;
  if(fromCur==='RMB' && (toCur==='USD'||toCur==='EUR')) return v/r;
  if((fromCur==='USD'||fromCur==='EUR') && toCur==='RMB') return v*r;
  return v;
}
function quoteMoneyFromRmb(v,c=cur()){return quoteConvertMoney(v,'RMB',c)}
function quoteMoneyToRmb(v,c=cur()){return quoteConvertMoney(v,c,'RMB')}
function productPriceByCurrency(p,c=cur()){
  if(!p)return 0;
  c=String(c||cur()).toUpperCase();
  let rmb=Number(p.cost_rmb||0)||Number(p.price_rmb||0);
  let usd=Number(p.cost_usd||0)||Number(p.price_usd||0);
  // V6.8.4.24：报价成本优先按 RMB 源价 ÷ 当前汇率实时换算。
  // 之前如果产品里同时存了旧 USD 成本，会导致换汇后单价偏低，例如应该 30.22 却显示 29.87。
  if(c==='RMB')return rmb||quoteConvertMoney(usd,'USD','RMB');
  return rmb>0?quoteConvertMoney(rmb,'RMB',c):usd;
}
function partPriceByCurrency(m,c=cur()){return quoteConvertMoney(Number(m?.price||0),'RMB',c)}
function productPrice(){return productPriceByCurrency(S.product,cur())}
function partsPrice(parts=S.parts,c=cur()){return Object.values(parts||{}).reduce((s,m)=>s+((m&&!m.none)?partPriceByCurrency(m,c):0),0)}
function autoPrice(){return currentAutoUnit()}
function unitPrice(){return $('manualPrice').value!==''?Number($('manualPrice').value):autoPrice()}
function normIp(v){v=String(v||'').trim();if(!v)return '';if(/^ip/i.test(v))return v.toUpperCase().replace(/\s+/g,'');return 'IP'+v.replace(/[^0-9A-Za-z]/g,'')}
function isEmptyParam(v){v=String(v??'').trim();return !v||/^(0|-|\/|n\/?a|none|null|无|没有|不适用)$/i.test(v)}
function cleanParam(v){return isEmptyParam(v)?'':String(v).trim()}

// V6.4.5：报价显示尺寸按命名中心规则输出。
// 只有圆形显示 Φ；方形/方圆/线性/长条不显示 Φ。只有嵌入式显示 Cut out。
function quoteTextBlob(p){p=p||{};return [p.dimension_type,p.category,p.item_name,p.name,p.product_name,p.series,p.type,p.code,p.model].filter(Boolean).join(' ')}
function quoteIsNotRoundText(txt){return /方形|方圆|长方|线性|线条|长条|条形|K条|LUMI|linear|square|rect/i.test(String(txt||''))}
function quoteIsEmbeddedProduct(p){p=p||{};let t=String(p.dimension_type||'').toLowerCase();if(['embedded_round','embedded_square','opening','recessed'].includes(t))return true;if(Number(p.is_embedded||0)===1)return true;return /嵌入|无边|有边|开孔|recessed/i.test(quoteTextBlob(p))}
function quoteIsRoundProduct(p){p=p||{};let t=String(p.dimension_type||'').toLowerCase();let txt=quoteTextBlob(p);if(quoteIsNotRoundText(txt))return false;if(Number(p.is_round_dimension||0)===1)return true;if(['embedded_round','diameter','round','circle','circular'].includes(t))return true;if(['embedded_square','box','square','rectangle'].includes(t))return false;return /圆形|圆筒|筒灯|downlight|cylinder|round/i.test(txt)}
function quoteDisplaySize(p){p=p||{};let L=cleanParam(p.dim_length), W=cleanParam(p.dim_width), H=cleanParam(p.dim_height), D=cleanParam(p.dim_outer_d);let round=quoteIsRoundProduct(p);if(L&&W&&H)return `${L}*${W}*${H}`;if(L&&W)return `${L}*${W}${H?'*'+H:''}`;if(D&&H)return `${round?'Φ':''}${D}*${H}`;if(D)return `${round?'Φ':''}${D}${H?'*'+H:''}`;let v=cleanParam(p.quote_display_size||p.size||p.dimension||p.dimensions||'');if(v&&!round)v=v.replace(/^\s*[ΦφØø]\s*/,'');return v}
function quoteFormatCutout(v){v=cleanParam(v);if(!v)return '';v=v.replace(/^\s*(cut\s*out|cutout|开孔)\s*[:：]?\s*/i,'').trim();v=v.replace(/^\s*[ΦφØø]\s*/,'').replace(/\s*mm\s*$/i,'').trim();return v?'Φ'+v+'mm':''}
function quoteDisplayCutout(p){p=p||{};if(!quoteIsEmbeddedProduct(p))return '';return quoteFormatCutout(p.quote_display_cutout||p.cutout||p.dim_opening||p.hole||p.opening||'')}

function productSeriesLine(p){
  let v=cleanParam(p?.series||p?.series_name||p?.family||'');
  if(!v){let name=cleanParam(p?.name||p?.product_name||p?.title||''); if(name) v=name;}
  return v;
}
function specLabel(label){
  let k=String(label||'').trim().toLowerCase();
  if(['connector','接头','连接头','adapter'].includes(k))return 'Adapter';
  if(k==='driver'||k==='led driver'||k==='电源'||k==='驱动')return 'LED Driver';
  if(k==='optic'||k==='光学'||k==='透镜'||k==='反光杯')return 'Optic';
  if(k==='accessories'||k==='accessory'||k==='extra'||k==='附件'||k==='配件')return 'Accessories';
  if(k==='led'||k==='芯片'||k==='cob')return 'LED';
  return label;
}
function productQuoteSpec(p){let raw=p?.quote_spec||p?.quoteSpec||null;if(!raw&&p?.quote_spec_json){try{raw=JSON.parse(p.quote_spec_json)}catch(e){raw=null}}let out=[];if(raw&&typeof raw==='object'){for(let [k,v] of Object.entries(raw)){let label=k,val='';if(v&&typeof v==='object'){label=v.label||k;val=v.value||v.text||''}else{val=v}label=specLabel(label);if(['LED','LED Driver','Optic','Adapter','Accessories'].includes(label))val=quoteBrandModelOnly(val,label);if(cleanParam(val))out.push([label,cleanParam(val)])}}return out}
function ledLineValue(val,item=null){let cct=item?(item.cct||''):normCct($('cct')?.value||'');let cri=item?(item.cri||''):normCri($('cri')?.value||'');return [cleanParam(val),cct,cri].filter(Boolean).join(' ').trim()}
function buildSpec(item=null){if(isMaterialSaleItem(item))return materialSaleSpec(item);let p=(item?item.product:S.product)||{}, parts=(item?item.parts:S.parts)||{}, lines=[], n=1, usedLabels=new Set();let add=(label,val)=>{label=specLabel(label);val=cleanParam(val);if(val){lines.push((n++)+'. '+label+': '+val);usedLabels.add(label)}};
let seriesLine=productSeriesLine(p);if(seriesLine)lines.push((n++)+'. '+seriesLine);
add('Power', formatPowerForSpec(item?(item.power||p.power):(($('power')?.value||p.power)||'')));
add('Size', quoteDisplaySize(p));
add('Cut out', quoteDisplayCutout(p));
let ba=item?(item.beam_angle||item.beamAngle||''):($('beamAngle')?.value||'');add('Beam Angle', formatBeamAngleForSpec(ba));
let defs=partDefsByType(item?item.product_type:$('productType').value,p);
let labelMap={led:'LED',driver:'LED Driver',optic:'Optic',connector:'Adapter',extra:'Accessories'};
let noneMap={led:'LED: Without LED chip',driver:'LED Driver: Without LED driver',optic:'Optic: Without optic',connector:'Adapter: Without adapter',extra:'Accessories: Without accessories'};
let qspec=productQuoteSpec(p);
if(qspec.length){qspec.forEach(([label,val])=>{label=specLabel(label); if(label==='LED')add('LED',ledLineValue(val,item)); else add(label,val);});}
for(let [k,label] of defs){let m=parts[k], outLabel=labelMap[k]||label;if(!m)continue;if(m.none){if(!qspec.length)lines.push((n++)+'. '+noneMap[k]);continue;}let val=quotePartSpecName(k,m,item);if(!val)continue;if(k==='extra'||k==='connector'){add(outLabel,val);continue;}if(!usedLabels.has(outLabel))add(outLabel,val);}
let ip=item?(item.ip||''):normIp($('ip')?.value||'');if(cleanParam(ip))lines.push((n++)+'. '+ip);let remarks=item?quoteRemarksOfItem(item):quoteRemarksFromForm();remarks.forEach(x=>{x=cleanParam(x);if(x)lines.push((n++)+'. '+x)});return lines.join('\n')||'请选择产品和配件'}
function quoteDocTitle(){let v=String($('quoteStatus')?.value||'Quotation sheet');if(/订购合同|purchase|contract/i.test(v))return '订购合同';return /proforma|invoice/i.test(v)?'PROFORMA INVOICE':'Quotation sheet'}
function quoteOrderDocTitle(currency){let c=String(currency||cur()||'USD').trim().toUpperCase();return (c==='RMB'||c==='CNY'||c==='人民币')?'订购合同':'PROFORMA INVOICE'}
function defaultQuoteTerms(){return [['PI Number:','QTNO'],['Payment:','40% Deposit before production'],['','60% payment before shipment'],['Price Terms:','EXWORK'],['Quoted Date:','DATE'],['Delivery Date:','25-35Days After Confirmed'],['Quoted Valid','Within 10 days']]}
function quoteRoundMoney(n){return Math.round((Number(n)||0)*100)/100}
function quotePaymentAmountLines(totalAmount, terms){
  let arr=[]; totalAmount=Number(totalAmount)||0;
  (terms||[]).forEach(r=>{
    let txt=String((r&&r[1])||'').trim();
    let m=txt.match(/(\d+(?:\.\d+)?)\s*%/);
    if(!m) return;
    let pct=Number(m[1]); if(!isFinite(pct)||pct<=0||pct>100) return;
    let label=txt.replace(/\s*before\s+.*/i,'').replace(/\s*after\s+.*/i,'').trim();
    if(!label) label=pct+'% Payment';
    arr.push({pct,label,amount:quoteRoundMoney(totalAmount*pct/100)});
  });
  if(arr.length>=2){
    let sumPct=arr.reduce((a,x)=>a+x.pct,0);
    if(Math.abs(sumPct-100)<0.01){
      let totalRounded=quoteRoundMoney(totalAmount);
      let prev=0;
      for(let i=0;i<arr.length;i++){
        if(i===arr.length-1) arr[i].amount=quoteRoundMoney(totalRounded-prev);
        else {arr[i].amount=quoteRoundMoney(totalAmount*arr[i].pct/100); prev=quoteRoundMoney(prev+arr[i].amount);}
      }
    }
  }
  return arr;
}
function quotePaymentSummaryHtml(totalAmount, terms){
  let lines=quotePaymentAmountLines(totalAmount, terms);
  if(!lines.length) return '';
  return lines.map(x=>`<br><b>${esc(x.label)}:</b> ${cur()} ${money(x.amount)}`).join('');
}

function quoteCustomerAddressLines(c){
  c=c||{};
  const seen=new Set(), out=[];
  const norm=(v)=>String(v||'').replace(/\s+/g,' ').replace(/^[^:：]{1,24}[:：]\s*/,'').trim().toLowerCase();
  const push=(v,keyText)=>{v=String(v||'').replace(/\s+/g,' ').trim(); if(!v) return; const k=norm(keyText||v); if(seen.has(k)) return; seen.add(k); out.push(v);};
  push(c.address1||c.office_address||c.address||c.company_address||'');
  push(c.address2||c.factory_address||c.delivery_address||'');
  let raw=c.addresses_json||c.addresses||'';
  try{
    if(typeof raw==='string' && raw.trim()) raw=JSON.parse(raw);
    if(Array.isArray(raw)){
      raw.forEach(a=>{
        if(!a) return;
        if(typeof a==='string'){ push(a); return; }
        let label=String(a.label||a.name||a.type||'').trim();
        let addr=String(a.address||a.addr||a.value||a.text||'').trim();
        let note=String(a.note||'').trim();
        if(addr){ push((label?label+': ':'')+addr+(note?' '+note:''), addr); }
      });
    }
  }catch(e){}
  return out.slice(0,4);
}

function formatCustomerAddressesForEdit(c){
  c=c||{};
  let raw=c.addresses_json||c.addresses||'';
  if(Array.isArray(raw)) return raw.map(a=>typeof a==='string'?a:[a.label,a.address,a.note].filter(Boolean).join('：')).join('\n');
  if(typeof raw==='string'){
    let t=raw.trim();
    if(!t) return '';
    try{
      let arr=JSON.parse(t);
      if(Array.isArray(arr)) return arr.map(a=>typeof a==='string'?a:[a.label,a.address,a.note].filter(Boolean).join('：')).join('\n');
    }catch(e){}
    return t;
  }
  return '';
}
function quoteCustomerToText(c){
  if(!c) return 'Please select customer';
  const first=(arr)=>{for(const v of arr){let s=String(v||'').trim(); if(s) return s;} return '';};
  const contact=first([c.primary_contact,c.main_contact,c.contact_name,c.contact,c.person,c.linkman]);
  const phone=first([c.primary_contact_phone,c.contact_phone,c.contact_mobile,c.whatsapp,c.mobile,c.phone,c.tel]);
  const email=first([c.primary_contact_email,c.contact_email,c.email,c.customer_email,c.mail]);
  const company=first([c.company,c.name,c.customer_name,c.client_name]);
  const lines=[];
  if(contact || phone) lines.push('Contact: '+[contact, phone].filter(Boolean).join('  '));
  if(company) lines.push(company);
  quoteCustomerAddressLines(c).forEach((a,i)=>lines.push((i===0?'Address: ':'')+a));
  if(email) lines.push('Email: '+email);
  if(!lines.length) return 'Please select customer';
  return lines.join('\n');
}

function render(){let h=S.header||{},b=S.bank||{},c=S.customer;let terms=[];try{terms=JSON.parse((S.template||{}).terms_json||'[]')}catch(e){}if(!Array.isArray(terms)||!terms.length)terms=defaultQuoteTerms();terms=terms.map(r=>[String(r[0]).replace('QTNO',$('quoteNo').value).replace('DATE',$('quoteDate').value),String(r[1]).replace('QTNO',$('quoteNo').value).replace('DATE',$('quoteDate').value)]);if(terms[0])terms[0][1]=$('quoteNo').value;let qd=terms.find(r=>/Quoted Date/i.test(r[0]));if(qd)qd[1]=$('quoteDate').value;let items=quoteItemsForPreview();let total=quoteTotal();
  const tableCols=`<colgroup><col style="width:10%"><col style="width:11%"><col style="width:8%"><col style="width:9%"><col style="width:29%"><col style="width:7%"><col style="width:6%"><col style="width:7%"><col style="width:7%"><col style="width:6%"></colgroup>`;
  const repeatHead='';
  const tableHead=`<thead>${repeatHead}<tr><th>Picture</th><th>Size or<br>Drawing(mm)</th><th>Customer<br>Code</th><th>Manufacturer<br>Code</th><th>Specification</th><th>Color</th><th>QTY<br>(pcs)</th><th>Unit<br>Price(${cur()})</th><th>Amount<br>(${cur()})</th><th>MOQ<br>(pcs)</th></tr></thead>`;
  function rowHtml(it){let p=it.product||{}, qty=Number(it.qty||0), price=itemUnitPrice(it), amt=Number(it.amount||qty*price), isMat=isMaterialSaleItem(it);let sizeText=isMat?'':quoteDisplaySize(p);return `<tr class="quote-product-row ${isMat?'quote-material-row':''}"><td>${p.image?`<img class="prod-img" src="${p.image}">`:''}</td><td>${esc(sizeText)}</td><td>${esc(it.customer_code||'')}</td><td>${esc(p.code||'')}</td><td class="spec">${esc(buildSpec(it))}</td><td>${esc(it.color||'')}</td><td>${qty}</td><td>${money(price)}</td><td>${money(amt)}</td><td>${esc(it.moq||p.moq||'')}</td></tr>`}
  function emptyRow(){return `<tr><td colspan="10" style="height:35mm;color:#667085">请在左侧点选产品，系统会自动加入报价单。</td></tr>`}
  function fullHeader(){return `<div class="paper-top"><div class="from"><h1>${esc(h.company||'Gallin Industrial (HK) Limited')}</h1><div class="block">${esc(h.from_text||'')}</div><div class="to"><b>To:</b>\n${esc(quoteCustomerToText(c))}</div></div><div><div class="brandstamp">${Number(h.show_stamp||0)?esc(h.stamp||''):''}</div><div class="qt-title">${esc(quoteDocTitle())}</div><table class="terms">${terms.map(r=>`<tr><td>${esc(r[0])}</td><td>${esc(r[1])}</td></tr>`).join('')}</table></div></div>`}
  const totalRowHtml=`<tr class="quote-total-row"><td colspan="5"></td><td><b>Total:</b></td><td><b>${total.qty}</b></td><td><b>${cur()==='RMB'?'¥':'$'}</b></td><td><b>${money(total.amount)}</b></td><td></td></tr>`;
  const paymentSummaryHtml=quotePaymentSummaryHtml(total.amount, terms);
  const bankTextHtml=String(b.text||'').trim()?`<div class="bank">${esc(b.text||'')}</div>`:'';
  const bankTermsFont=quoteTermsFontSizeValue(b.extra_terms_font_size||b.terms_font_size||7.5);
  const bankTermsHtml=String(b.extra_terms||b.terms_text||'').trim()?`<div class="bank-terms" style="--quote-bank-terms-font-size:${bankTermsFont}pt;font-size:${bankTermsFont}pt">${esc(b.extra_terms||b.terms_text||'')}</div>`:'';
  const finalTailHtml=`<div class="final-summary"><div class="box"><b>Total Qty:</b> ${total.qty} PCS${paymentSummaryHtml}<br><b>Total Amount:</b> ${cur()} ${money(total.amount)}<br><b>Currency:</b> ${cur()}</div><div class="box"><b>Quotation No:</b> ${esc($('quoteNo').value||'')}<br><b>Status:</b> ${esc(quoteDocTitle())}<br><b>Date:</b> ${esc($('quoteDate').value||'')}</div></div><div class="final-sign"><div>Prepared By</div><div>Approved By</div><div>Customer Signature</div></div>${bankTextHtml}${bankTermsHtml}`;
  let rows=items.length?items.map(rowHtml).join(''):emptyRow();
  const onePageClass=(items.length<=1)?' quote-one-item':'';
  $('paper').innerHTML=`<div class="paper flow-paper${onePageClass}">${fullHeader()}<table class="quote-table">${tableCols}${tableHead}<tbody>${rows}${totalRowHtml}</tbody></table>${finalTailHtml}</div>`;
  prepareQuotePreviewPages($('paper'));
  renderQuoteItems()}

function quoteMiniInfoFromPaper(paper){
  let company=(paper.querySelector('.from h1')?.textContent||'Gallin Industrial (HK) Limited').trim();
  let quoteNo=''; let date='';
  paper.querySelectorAll('.terms tr').forEach(tr=>{let t=[...tr.children].map(td=>td.textContent.trim()); if(/PI\s*Number/i.test(t[0]||'')) quoteNo=t[1]||quoteNo; if(/Quoted\s*Date/i.test(t[0]||'')) date=t[1]||date;});
  if(!quoteNo) quoteNo=($('quoteNo')?.value||''); if(!date) date=($('quoteDate')?.value||'');
  let to=(paper.querySelector('.to')?.innerText||'').replace(/^\s*To:\s*/i,'').trim().split(/\n/).filter(Boolean).slice(0,2).join('<br>');
  return {company,quoteNo,date,to};
}
function buildQuoteMiniHead(info){return `<div class="page-mini-head"><div><b>${esc(info.company||'')}</b><br>${esc(quoteDocTitle())} · ${esc(info.quoteNo||'')}<br>${info.to||''}</div><div>${esc(info.date||'')}</div></div>`}
function prepareQuotePreviewPages(root){
  root=root||$('paper'); if(!root) return;
  let src=root.querySelector('.paper.flow-paper');
  if(!src || src.dataset.splitDone==='1') return;
  let table=src.querySelector('table.quote-table'); if(!table) return;
  let fullHeader=src.querySelector('.paper-top')?.cloneNode(true);
  let colgroup=table.querySelector('colgroup')?.cloneNode(true);
  let thead=table.querySelector('thead')?.cloneNode(true);
  if(thead) thead.querySelectorAll('.repeat-mini-head').forEach(x=>x.remove());
  let tbody=table.querySelector('tbody'); if(!tbody) return;
  let dataRows=[...tbody.children].filter(tr=>tr.tagName==='TR'&&!tr.classList.contains('quote-total-row')).map(tr=>tr.cloneNode(true));
  let totalRow=tbody.querySelector('.quote-total-row')?.cloneNode(true);
  let tail=[]; let node=table.nextSibling;
  while(node){let next=node.nextSibling; if(node.nodeType===1 && !node.classList.contains('page-footer')) tail.push(node.cloneNode(true)); node=next;}
  let info=quoteMiniInfoFromPaper(src);
  const isOnePage=src.classList.contains('quote-one-item');
  function makeTable(){let t=document.createElement('table'); t.className='quote-table'; if(colgroup)t.appendChild(colgroup.cloneNode(true)); if(thead)t.appendChild(thead.cloneNode(true)); let b=document.createElement('tbody'); t.appendChild(b); return {t,tbody:b};}
  function makeFirst(){let p=document.createElement('div'); p.className='paper flow-paper first-page'+(isOnePage?' quote-one-item':''); if(fullHeader)p.appendChild(fullHeader.cloneNode(true)); let tb=makeTable(); p.appendChild(tb.t); return {p,tbody:tb.tbody};}
  function makeContinue(){let p=document.createElement('div'); p.className='paper flow-paper continue-page'+(isOnePage?' quote-one-item':''); p.insertAdjacentHTML('beforeend',buildQuoteMiniHead(info)); let tb=makeTable(); p.appendChild(tb.t); return {p,tbody:tb.tbody};}
  root.innerHTML='';
  let first=makeFirst(); root.appendChild(first.p);
  let rest=[];
  for(let i=0;i<dataRows.length;i++){
    first.tbody.appendChild(dataRows[i].cloneNode(true));
    if(first.p.scrollHeight>first.p.clientHeight && first.tbody.children.length>1){
      first.tbody.lastElementChild.remove(); rest=dataRows.slice(i); break;
    }
  }
  function appendFinal(target){ if(totalRow)target.tbody.appendChild(totalRow.cloneNode(true)); tail.forEach(n=>target.p.appendChild(n.cloneNode(true))); }
  if(!rest.length){
    appendFinal(first);
    if(first.p.scrollHeight>first.p.clientHeight){
      // final area does not fit on first page; move it to a continuation page.
      [...first.tbody.querySelectorAll('.quote-total-row')].forEach(x=>x.remove());
      tail.forEach(n=>{let cls=n.className; [...first.p.children].forEach(ch=>{if(ch.className===cls) ch.remove();});});
      let cont=makeContinue(); root.appendChild(cont.p); appendFinal(cont);
    }
  }else{
    let cont=makeContinue(); rest.forEach(r=>cont.tbody.appendChild(r.cloneNode(true))); appendFinal(cont); root.appendChild(cont.p);
  }
  root.querySelectorAll('.paper').forEach(p=>p.dataset.splitDone='1');
}

function normalizeQuoteItemCurrency(it,toCur,fromCur=''){
  if(!it)return it;
  toCur=String(toCur||cur()).toUpperCase();
  fromCur=String(fromCur||it.currency||QUOTE_LAST_CURRENCY||toCur).toUpperCase();
  let cp=clone(it);
  let manual=cp.manual_price===true||cp.manual_price===1||cp.manual_price==='1';
  cp.currency=toCur;
  if(manual){
    cp.price=quoteConvertMoney(Number(cp.price||0),fromCur,toCur);
  }else{
    cp.price=itemAutoPrice(cp,toCur);
  }
  cp.cost_price=itemBaseCost(cp,toCur);
  cp.cost_price_currency=toCur;
  cp.cost_price_rmb=quoteMoneyToRmb(cp.cost_price,toCur);
  cp.amount=Number(cp.qty||0)*Number(cp.price||0);
  return cp;
}
function normalizeAllQuoteItemsForCurrency(toCur,fromCur=''){
  if(Array.isArray(S.items)&&S.items.length){
    S.items=S.items.map(it=>normalizeQuoteItemCurrency(it,toCur,fromCur));
  }
}
function convertEditorManualPrice(fromCur,toCur){
  if($('manualPrice')&&$('manualPrice').value!==''){
    $('manualPrice').value=money(quoteConvertMoney(Number($('manualPrice').value||0),fromCur,toCur));
  }
}
function handleCurrencyChange(newCur){
  newCur=String(newCur||cur()).toUpperCase();
  let fromCur=QUOTE_LAST_CURRENCY||newCur;
  if(!Number($('rate')?.value||0))$('rate').value=$('topRate')?.value||systemExchangeRate();
  if($('topCurrency'))$('topCurrency').value=newCur;
  convertEditorManualPrice(fromCur,newCur);
  normalizeAllQuoteItemsForCurrency(newCur,fromCur);
  QUOTE_LAST_CURRENCY=newCur;
  if(S.editingIndex>=0&&S.items[S.editingIndex])loadItemToEditor(S.items[S.editingIndex],S.editingIndex);
  updatePriceLevelHint();renderQuoteItems();renderDash();render();
}
function handleRateChange(){
  let c=cur();
  if(c!=='RMB'&&Array.isArray(S.items))normalizeAllQuoteItemsForCurrency(c,c);
  if($('topRate'))$('topRate').value=$('rate').value||systemExchangeRate();
  updatePriceLevelHint();syncEditingItemFromForm();renderQuoteItems();renderDash();render();
}
function syncCurrency(){let c=$('topCurrency').value||cur();if($('currency'))$('currency').value=c;if($('rate'))$('rate').value=$('topRate')?.value||systemExchangeRate();handleCurrencyChange(c)}
function dashYmdFromDate(d){
  let y=d.getFullYear(),m=String(d.getMonth()+1).padStart(2,'0'),day=String(d.getDate()).padStart(2,'0');
  return y+'-'+m+'-'+day;
}
function dashQuoteDateKey(q){
  let raw=String(q?.quote_date||q?.created_at||'').trim().replaceAll('/','-');
  if(/^\d{4}-\d{2}-\d{2}/.test(raw)) return raw.slice(0,10);
  return '';
}
function renderQuoteWeekBars(qs){
  let wrap=$('dWeekBars'); if(!wrap) return;
  let base=new Date(today()+'T00:00:00');
  let map={};
  (Array.isArray(qs)?qs:[]).forEach(q=>{let k=dashQuoteDateKey(q); if(k) map[k]=(map[k]||0)+1;});
  let days=[];
  for(let i=6;i>=0;i--){let d=new Date(base); d.setDate(base.getDate()-i); let key=dashYmdFromDate(d); days.push({key,label:String(d.getMonth()+1)+'/'+String(d.getDate()),count:map[key]||0,today:i===0});}
  let max=Math.max(1,...days.map(x=>x.count));
  wrap.innerHTML=days.map(x=>{
    let h=x.count>0?Math.max(7,Math.round(x.count/max*46)):5;
    return `<div class="quote-week-bar ${x.today?'today':''}" title="${esc(x.key)}：${x.count} 份报价"><i style="height:${h}px"></i><span>${esc(x.label)}</span></div>`;
  }).join('');
}
function renderDash(){
  let qs=Array.isArray(DB.quotes)?DB.quotes:[], orders=Array.isArray(DB.orders)?DB.orders:[], docs=Array.isArray(DOCUMENTS)?DOCUMENTS:[];
  let todayStr=today(), ym=todayStr.slice(0,7), month=qs.filter(q=>String(q.quote_date||q.created_at||'').slice(0,7)===ym);
  let todayCount=qs.filter(q=>String(q.quote_date||q.created_at||'').slice(0,10)===todayStr).length;
  if($('dTotal'))$('dTotal').textContent=qs.length;
  if($('dToday'))$('dToday').textContent=todayCount;
  if($('dMonth'))$('dMonth').textContent=month.length;
  if($('dWeekSummary'))$('dWeekSummary').textContent='今日 '+todayCount+' ｜ 本月 '+month.length;
  renderQuoteWeekBars(qs);
  if($('dMonthSub'))$('dMonthSub').textContent='近7天柱形图';
  let monthBucket=quoteSumByOriginalCurrency(month);
  if($('dMonthAmt'))$('dMonthAmt').textContent=dashPrimaryCurrencyText(monthBucket);
  if($('curLabel'))$('curLabel').textContent=dashSecondaryCurrencyText(monthBucket);
  let cmap={};qs.forEach(q=>{let c=quoteCustomerName(q);if(!c)return;if(!cmap[c])cmap[c]={count:0,amounts:quoteCurrencyBucket()};cmap[c].count++;quoteAddToCurrencyBucket(cmap[c].amounts,q);});let top=Object.entries(cmap).sort((a,b)=>b[1].count-a[1].count||(Number(b[1].amounts.RMB||0)+Number(b[1].amounts.USD||0))-(Number(a[1].amounts.RMB||0)+Number(a[1].amounts.USD||0)))[0];
  if($('dTopCustomer'))$('dTopCustomer').textContent=top?top[0]:'-';
  if($('dTopCustomerSub'))$('dTopCustomerSub').textContent=top?(top[1].count+' 份 ｜ '+dashCurrencyLine(top[1].amounts)):'0 份 ｜ RMB 0.00 ｜ USD 0.00';
  let smap={};qs.forEach(q=>{let n=quoteOwnerName(q);if(!smap[n])smap[n]={count:0,amounts:quoteCurrencyBucket()};smap[n].count++;quoteAddToCurrencyBucket(smap[n].amounts,q);});let topSales=Object.entries(smap).sort((a,b)=>b[1].count-a[1].count||(Number(b[1].amounts.RMB||0)+Number(b[1].amounts.USD||0))-(Number(a[1].amounts.RMB||0)+Number(a[1].amounts.USD||0)))[0];
  if($('dTopSales'))$('dTopSales').textContent=topSales?topSales[0]:'-';
  if($('dTopSalesSub'))$('dTopSalesSub').textContent=topSales?(topSales[1].count+' 份 ｜ '+dashCurrencyLine(topSales[1].amounts)):'0 份 ｜ RMB 0.00 ｜ USD 0.00';
  let pending=0,approved=0,rejected=0;qs.forEach(q=>{let st=quoteApprovalStatus(q);if(st==='approved')approved++;else if(st==='rejected')rejected++;else pending++;});
  if($('dQuoteAll'))$('dQuoteAll').textContent=qs.length;if($('dQuotePending'))$('dQuotePending').textContent=pending;if($('dQuoteApproved'))$('dQuoteApproved').textContent=approved;if($('dQuoteRejected'))$('dQuoteRejected').textContent=rejected;
  let orderQuoteSet=new Set(orders.map(o=>String(o.quote_no||'').trim()).filter(Boolean));let converted=qs.filter(q=>orderQuoteSet.has(String(q.quote_no||'').trim())).length; if(!orders.length&&qs.length)converted=qs.filter(q=>String(q.quote_status||'').toUpperCase().includes('PROFORMA')).length;
  if($('dConverted'))$('dConverted').textContent=converted;if($('dNotConverted'))$('dNotConverted').textContent=Math.max(0,qs.length-converted);if($('dOrderCountSub'))$('dOrderCountSub').textContent='订单 '+orders.length+' 个';
  let revenueOrders=orders.filter(quoteOrderIsRevenue), revenueBucket=quoteSumOrdersByOriginalCurrency(revenueOrders);
  if($('dOrderRevenue'))$('dOrderRevenue').textContent=dashPrimaryCurrencyText(revenueBucket);
  if($('dOrderRevenueSub'))$('dOrderRevenueSub').textContent=dashSecondaryCurrencyText(revenueBucket).replace(' ｜ 原币种统计','')+' ｜ 只统计订单';
  let noShip=orders.filter(o=>!String(o.shipment_status||'').match(/已出货|已完成/)).length;
  let docReadyIds=new Set(docs.filter(d=>d.pl_generated_at||d.ci_generated_at).map(d=>String(d.order_id||'')));let noDocs=orders.length?orders.filter(o=>!docReadyIds.has(String(o.id||''))).length:0;
  if($('dNoShip'))$('dNoShip').textContent=noShip;if($('dNoDocs'))$('dNoDocs').textContent=noDocs;if($('dDocsShipSub'))$('dDocsShipSub').textContent=DASH_DOCS_LOADED?'订单 '+orders.length+' ｜ 批次 '+docs.length:'按订单统计';
  let balanceBucket=quoteCurrencyBucket();orders.forEach(o=>quoteAddToCurrencyBucket(balanceBucket,{amount:o.balance_amount||0,currency:o.currency||o.order_currency||cur()}));if($('dBalance'))$('dBalance').textContent=dashPrimaryCurrencyText(balanceBucket);if($('dBalanceSub'))$('dBalanceSub').textContent=dashSecondaryCurrencyText(balanceBucket).replace(' ｜ 原币种统计','')+' 未收';
  let note=$('dashOrderNote');if(note)note.textContent=DASH_ORDERS_LOADED?'订单数据已同步':'订单数据自动读取';
  applyDashTemplate();updateDashMiniSummary();ensureDashOrderData();ensureDashDocData();
}
function newQuote(){S.currentQuoteId=0;S.currentQuoteOwner='';S.currentApprovalStatus='new';updateQuoteApprovalStrip();clearQuoteCustomerSelection(false);$('quoteDate').value=today();$('quoteNo').value=qno();if($('quoteStatus'))$('quoteStatus').value='Quotation sheet';$('qty').value=1;$('manualPrice').value='';$('moq').value=200;fillOptionSelect('color',colorItems(),'White');if($('beamAngle'))$('beamAngle').value='';if($('power'))$('power').value='';if($('cct'))$('cct').value='';if($('cri'))$('cri').value='';if($('ip'))$('ip').value='';if($('customerCode'))$('customerCode').value='';$('extraSpec').value='';clearQuoteRemarksForm();syncPriceMultiplierFromLevel(false);S.parts={};S.product=null;S.items=[];S.editingIndex=-1;if($('productSelect')){$('productSelect').value='';syncProductSearchFromSelect();}renderParts();render()}
function baseQuoteNo(no){return normalizeQuoteNoNoNested(String(no||'').replace(/-V\d+$/i,''))}
function nextQuoteVersionNo(){let base=baseQuoteNo($('quoteNo')?.value||'');let max=1;(DB.quotes||[]).forEach(q=>{let no=String(q.quote_no||'');if(no===base)max=Math.max(max,1);let m=no.match(new RegExp('^'+escReg(base)+'-V(\d+)$'));if(m)max=Math.max(max,Number(m[1]||1));});return max;}
function makeNewVersionNo(){let base=baseQuoteNo($('quoteNo')?.value||qno());let max=1;(DB.quotes||[]).forEach(q=>{let no=String(q.quote_no||'');if(no===base)max=Math.max(max,1);let m=no.match(new RegExp('^'+escReg(base)+'-V(\d+)$'));if(m)max=Math.max(max,Number(m[1]||1));});return base+'-V'+(max+1)}
function quoteDbIntId(v){if(v===undefined||v===null||v==='')return null;let s=String(v).trim();let m=s.match(/(\d+)$/);return m?Number(m[1]):0}
function saveAsNewVersion(){if(!$('quoteNo').value){$('quoteNo').value=qno()}$('quoteNo').value=makeNewVersionNo();saveQuote()}
async function saveQuote(){if(!hasPerm('quote_edit')){alert('当前账号没有权限：quote_edit');return;}if(!$('quoteNo').value||$('quoteNo').value.startsWith('QT-'))$('quoteNo').value=qno();$('quoteNo').value=normalizeQuoteNoNoNested($('quoteNo').value);let items=(S.items&&S.items.length)?S.items:[currentEditorItem()].filter(it=>it.product&&it.product.id);if(!items.length){alert('请至少添加一个产品到报价单');return;}items=items.map(it=>normalizeQuoteItemCurrency(clone(it),cur(),it.currency||cur()));let amount=items.reduce((s,it)=>s+Number(it.amount||0),0), qty=items.reduce((s,it)=>s+Number(it.qty||0),0);let first=items[0]||{};let data={id:S.currentQuoteId||'',quote_no:$('quoteNo').value,quote_date:$('quoteDate').value,user_name:quoteCurrentOwnerForSave(),customer_id:customerDbId(S.customer),customer_name:S.customer?.company||S.customer?.name||'',customer_json:JSON.stringify(S.customer||{}),header_id:S.header?.id||null,bank_id:S.bank?.id||null,template_id:S.template?.id||null,header_json:JSON.stringify(S.header||{}),bank_json:JSON.stringify(S.bank||{}),template_json:JSON.stringify(S.template||{}),product_type:first.product_type||'',product_id:quoteDbIntId(first.product?.naming_id||first.product?.product_id||first.product?.id||null),product_json:JSON.stringify(first.product||{}),parts_json:JSON.stringify(first.parts||{}),items_json:JSON.stringify(items),qty,price:items.length===1?Number(items[0].price||0):0,amount,currency:cur(),exchange_rate:rate(),moq:first.moq||'',color:first.color||'',cct:first.cct||'',cri:first.cri||'',ip:first.ip||'',extra_spec:first.extra_spec||'',quote_status:($('quoteStatus')?.value||'Quotation sheet'),version_no:nextQuoteVersionNo(),price_level_id:selectedPriceLevel().id||'',price_level_name:selectedPriceLevel().name||'',price_multiplier:priceMultiplier()};let r=await api('save_quote',data);S.currentQuoteId=Number(r.id||S.currentQuoteId||0);S.currentApprovalStatus=String(r.approval_status||'pending');await refreshQuoteRuntime('save_quote',{orders:true,documents:true});clientLog('save_quote_front','前端保存报价并提交审核',{quote_no:data.quote_no,item_count:items.length,qty,amount,currency:cur(),customer:S.customer});autoCollapseQuoteConfigAfterSave();updateQuoteApprovalStrip(currentSavedQuote());alert('已保存并提交审核，共 '+items.length+' 个产品。审核通过后才能导出 PDF / Excel 或转订单。')}
function dataURL(file){return new Promise(res=>{let r=new FileReader();r.onload=()=>res(r.result);r.readAsDataURL(file)})}
function quoteDirectImageUrl(src){
  src=String(src||'').trim();
  if(!src)return '';
  if(src.startsWith('data:'))return src;
  try{src=decodeURIComponent(src)}catch(e){}
  src=src.replace(/\\/g,'/').replace(/^http:\/\/43\.132\.210\.162/i,'https://artdonlighting.com').replace(/^https:\/\/43\.132\.210\.162/i,'https://artdonlighting.com').replace(/^http:\/\/gallin\.cn/i,'https://artdonlighting.com').replace(/^https:\/\/www\.gallin\.cn/i,'https://artdonlighting.com');
  if(/^https?:\/\//i.test(src)){
    try{let u=new URL(src); if((u.hostname==='43.132.210.162'||u.hostname==='gallin.cn'||u.hostname==='www.gallin.cn')&&u.pathname.indexOf('/uploads/website/')===0){return 'https://artdonlighting.com'+u.pathname+u.search;} }catch(e){}
    return src;
  }
  let clean=src.replace(/[?#].*$/,'').replace(/^\/+/, '');
  let q=src.includes('?')?src.slice(src.indexOf('?')):'';
  let pos=clean.indexOf('uploads/website/');
  if(pos>=0)return 'https://artdonlighting.com/'+clean.slice(pos)+q;
  pos=clean.indexOf('website/products/');
  if(pos>=0)return 'https://artdonlighting.com/uploads/'+clean.slice(pos)+q;
  return src;
}
function quoteProductImageSrc(p){return quoteDirectImageUrl((p&&(p.image_display||p.web_image_url||p.cover_image_url||p.source_image_url||p.image||p.product_image||p.main_image||p.image_path))||'')}
function productImgHtml(p){let im=quoteProductImageSrc(p);return im?`<img src="${esc(im)}" loading="lazy" decoding="async" referrerpolicy="no-referrer" onerror="this.replaceWith(Object.assign(document.createElement('span'),{textContent:'无图'}))">`:'<span>无图</span>'}
async function saveProduct(){if(!hasPerm('product_manage')){alert('当前账号没有权限：product_manage');return;}let img=$('pImage').files[0]?await dataURL($('pImage').files[0]):(window.editProductImage||'');let d={id:$('pId').value,type:$('pType').value,need_connector:$('pNeed').value,code:$('pCode').value,name:$('pName').value,source:$('pSource').value,category:$('pCategory').value,series:$('pSeries').value,size:$('pSize').value,cutout:$('pCutout').value,power:$('pPower').value,ip:$('pIp').value,color:$('pColor').value,moq:($('pMoq').value===''?0:$('pMoq').value),price_rmb:($('pRmb').value===''?0:$('pRmb').value),price_usd:($('pUsd').value===''?0:$('pUsd').value),price_note:$('pPriceNote').value,tags:$('pNote').value,note:$('pNote').value,image:img};await api('save_product',d);DB=await api('init');clearProduct();renderAll()}function editProduct(id){let p=DB.products.find(x=>String(x.id)===String(id));if(!p)return;if(p.source==='naming'){alert('这是命名中心产品，报价系统只读取使用。如需修改型号/图片/尺寸，请到命名系统修改。');return;}['Id','Type','Need','Code','Name','Source','Category','Series','Size','Cutout','Power','Ip','Color','Moq','Rmb','Usd','PriceNote','Note'].forEach(k=>{let id2='p'+k, key={Id:'id',Type:'type',Need:'need_connector',Code:'code',Name:'name',Source:'source',Category:'category',Series:'series',Size:'size',Cutout:'cutout',Power:'power',Ip:'ip',Color:'color',Moq:'moq',Rmb:'price_rmb',Usd:'price_usd',PriceNote:'price_note',Note:'note'}[k];if($(id2))$(id2).value=p[key]||''});window.editProductImage=p.image||'';showPage('products')}function clearProduct(){['pId','pCode','pName','pSource','pCategory','pSeries','pSize','pCutout','pPower','pIp','pRmb','pUsd','pPriceNote','pNote'].forEach(id=>$(id).value='');$('pMoq').value=200;fillOptionSelect('pColor',colorItems(),'White');window.editProductImage=''}async function deleteProduct(){if(!hasPerm('product_manage')){alert('当前账号没有权限：product_manage');return;}if(!$('pId').value)return;await api('delete_product',{id:$('pId').value});DB=await api('init');clearProduct();renderProducts();renderProductSelect()}
function productCard(p){let click=p.source==='naming'?`chooseProductFromModal('${jsq(p.id)}')`:`editProduct('${jsq(p.id)}')`;let cost=productBaseCostRmb(p);let title=esc(p.name||p.code||'未命名产品');let meta=[p.code,quoteDisplaySize(p),p.power,quoteDisplayCutout(p)?('开孔 '+quoteDisplayCutout(p)):'',productCategoryLabel(p)].filter(Boolean).map(esc).join(' ｜ ');let costHtml=cost?`<span class="cost-ok">RMB ${money(cost)}</span><span class="cost-time">${esc(p.cost_updated_at||'BOM成本')}</span>`:`<span class="cost-missing">未找到成本</span><span class="cost-time">可先手动报价</span>`;return `<div class="prod-card" onclick="${click}"><div class="img">${productImgHtml(p)}</div><div class="body"><div class="source-line">${productSourceBadge(p)}</div><div class="prod-title">${title}</div><div class="prod-meta">${meta||'暂无规格信息'}</div><div class="cost-line">${costHtml}</div></div></div>`}function refreshProductFilters(){
  setProductCategorySelect('prodFilterType','全部分类');
}
function renderProducts(){
  refreshProductFilters();
  let kw=($('prodSearch')?.value||'').toLowerCase(), tp=$('prodFilterType')?.value||'', series=($('prodFilterSeries')?.value||'').toLowerCase(), power=($('prodFilterPower')?.value||'').toLowerCase(), ip=($('prodFilterIp')?.value||'').toLowerCase(), size=($('prodFilterSize')?.value||'').toLowerCase(), cutout=($('prodFilterCutout')?.value||'').toLowerCase(), source=($('prodFilterSource')?.value||'').toLowerCase(), min=Number($('prodPriceMin')?.value||''), max=Number($('prodPriceMax')?.value||'');
  let arr=DB.products.filter(p=>
    (!kw||productText(p).includes(kw)) &&
    (!tp||productCategoryMatch(p,tp)) &&
    (!series||String(p.series||'').toLowerCase().includes(series)) &&
    (!power||String(p.power||'').toLowerCase().includes(power)) &&
    (!ip||String(p.ip||'').toLowerCase().includes(ip)) &&
    (!size||String(p.size||'').toLowerCase().includes(size)) &&
    (!cutout||String(p.cutout||'').toLowerCase().includes(cutout)) &&
    (!source||String(p.source||'').toLowerCase().includes(source)) &&
    (!$('prodPriceMin')?.value || productPriceForFilter(p)>=min) &&
    (!$('prodPriceMax')?.value || productPriceForFilter(p)<=max)
  );
  let total=arr.length, limit=60, shown=arr.slice(0,limit);
  if($('prodCount')) $('prodCount').textContent='共 '+total+' / '+DB.products.length+' 个产品'+(total>limit?'，先显示前 '+limit+' 个，请搜索缩小范围':'');
  let el=$('productList'); if(!el) return;
  el.className=productView==='gallery'?'product-gallery':'list';
  el.innerHTML=shown.map(p=>productView==='gallery'?productCard(p):`<div class="item" onclick="editProduct('${jsq(p.id)}')"><b>${productSourceBadge(p)} ${esc(p.name||p.code)}</b><small>${esc(p.code)} ｜ ${esc(p.type)} ｜ ${esc(p.series||'')} ｜ ${esc(p.power||'')} ｜ ${esc(p.ip||'')} ｜ ${esc(quoteDisplaySize(p)||'')} ｜ ${esc(productCostText(p))}</small></div>`).join('');
}
function openProductModal(){
  setProductCategorySelect('modalType','全部分类');
  $('productModal').classList.add('show');
  renderProductModal();
}function closeProductModal(){$('productModal').classList.remove('show')}function productSelectCard(p){return `<div class="prod-card selectable" onclick="chooseProductFromModal('${jsq(p.id)}')"><div class="img">${productImgHtml(p)}</div><div class="body"><b>${productSourceBadge(p)}${esc(p.name||p.code)}</b><small>${esc(p.code||'')} ｜ ${esc(productCategoryLabel(p)||'')} ｜ ${esc(p.series||'')} ｜ ${esc(quoteDisplaySize(p)||'')} ｜ ${esc(p.power||'')} ｜ ${esc(p.ip||'')}</small><div class="price">${esc(productCostText(p))}</div></div></div>`}
async function chooseProductFromModal(id){
  let p=DB.products.find(x=>String(x.id)===String(id))||null;
  if(!p) return;
  if(!$('page-quote')?.classList.contains('active')) showPage('quote');
  $('productType').value=p.type||$('productType').value;
  renderProductSelect();
  $('productSelect').value=p.id;
  closeProductModal();
  await selectProduct();
}
function renderProductModal(){
  let kw=($('modalSearch')?.value||'').toLowerCase(),tp=$('modalType')?.value||'',series=($('modalSeries')?.value||'').toLowerCase(),pw=($('modalPower')?.value||'').toLowerCase(),ip=($('modalIp')?.value||'').toLowerCase(),sz=($('modalSize')?.value||'').toLowerCase(),cut=($('modalCutout')?.value||'').toLowerCase(),min=Number($('modalPriceMin')?.value||''),max=Number($('modalPriceMax')?.value||'');
  let arr=DB.products.filter(p=>
    (!tp||productCategoryMatch(p,tp)) &&
    (!kw||productText(p).includes(kw)) &&
    (!series||String(p.series||'').toLowerCase().includes(series)) &&
    (!pw||String(p.power||'').toLowerCase().includes(pw)) &&
    (!ip||String(p.ip||'').toLowerCase().includes(ip)) &&
    (!sz||String(p.size||'').toLowerCase().includes(sz)) &&
    (!cut||String(p.cutout||'').toLowerCase().includes(cut)) &&
    (!$('modalPriceMin')?.value || productPriceForFilter(p)>=min) &&
    (!$('modalPriceMax')?.value || productPriceForFilter(p)<=max)
  );
  let total=arr.length, limit=60, shown=arr.slice(0,limit);
  if($('modalCount')) $('modalCount').textContent='找到 '+total+' 个产品'+(total>limit?'，先显示前 '+limit+' 个，请输入型号/名称缩小范围':'')+'，点击产品直接加入当前报价配置，不跳转产品库。';
  $('modalProducts').innerHTML=shown.map(productSelectCard).join('');
}
async function syncCrmCustomers(silent=false){
  let r=await api('sync_crm_customers');
  DB=await api('init');
  if(S.customer && !(DB.customers||[]).some(x=>String(x.id)===String(S.customer.id))) S.customer=null;
  renderAll();
  let msg='CRM同步完成：当前CRM客户 '+(r.count||0)+' 个';
  if(Number(r.deleted_stale||0)>0) msg+='；已清理报价端失效CRM客户 '+r.deleted_stale+' 个';
  if(Number(r.duplicate_local_deleted||0)>0) msg+='；已清理重复本地客户 '+r.duplicate_local_deleted+' 个';
  if(Number(r.cached_crm_rows||0)>0) msg+='；CRM缓存 '+r.cached_crm_rows+' 条';
  if($('custSyncStatus')) $('custSyncStatus').textContent=msg;
  if(!silent) alert(msg);
}
async function alignCrmCustomers(){
  if(!hasPerm('customer_manage')){alert('当前账号没有权限：customer_manage');return;}
  let v=prompt('这会清空报价系统客户库里的旧本地/旧CRM缓存，只保留当前CRM实时客户。历史报价和订单不会删除。\n当前你的目标是CRM只有89个客户，报价端也同步为CRM现有客户。\n请输入确认词：ALIGN_CRM');
  if(v!=='ALIGN_CRM') return;
  let r=await api('align_crm_customers',{confirm:'ALIGN_CRM'});
  DB=await api('init');
  if(S.customer && !(DB.customers||[]).some(x=>String(x.id)===String(S.customer.id))) S.customer=null;
  renderAll();
  let msg='已强制对齐CRM：当前CRM客户 '+(r.count||0)+' 个；删除报价端旧客户 '+(r.deleted_local||0)+' 个；删除失效CRM缓存 '+(r.deleted_stale||0)+' 个。';
  if($('custSyncStatus')) $('custSyncStatus').textContent=msg;
  alert(msg);
}
function startCrmCustomerAutoSync(){
  if(CRM_CUSTOMER_AUTO_SYNC_TIMER) clearInterval(CRM_CUSTOMER_AUTO_SYNC_TIMER);
  CRM_CUSTOMER_AUTO_SYNC_TIMER=setInterval(async()=>{
    try{
      if(document.hidden) return;
      let page=(localStorage.getItem('artdon_quote_current_page')||'quote');
      if(page==='customers') await syncCrmCustomers(true);
    }catch(e){}
  },60000);
}
async function saveCustomer(){
  if(!hasPerm('customer_manage')){alert('当前账号没有权限：customer_manage');return;}
  if(String($('cId').value||'').startsWith('crm_')){alert('这是CRM客户，报价系统只读取使用。如需修改，请到 CRM 客户中心修改。');return;}
  let d={
    id:$('cId').value,
    code:$('cCode').value,
    company:$('cCompany').value,
    contact:$('cContact').value,
    email:$('cEmail').value,
    phone:$('cPhone').value,
    country:$('cCountry').value,
    website:$('cWebsite')?.value||'',
    address1:$('cAddress1')?.value||'',
    address2:$('cAddress2')?.value||'',
    addresses_json:$('cAddressesJson')?.value||'',
    primary_contact:$('cContact').value,
    primary_contact_phone:$('cPhone').value,
    primary_contact_email:$('cEmail').value,
    note:$('cNote').value
  };
  await api('save_customer',d);
  DB=await api('init');renderAll();
}function refreshCustomerFilters(){
  setSelectOptions('custCountryFilter', DB.customers.map(c=>c.country),'全部国家');
  setSelectOptions('custLetterFilter', DB.customers.map(c=>firstLetter(c.company||c.code)),'全部首字母');
}
function toggleCustomerBatch(){CUSTOMER_BATCH=!CUSTOMER_BATCH;if($('custBatchTools'))$('custBatchTools').style.display=CUSTOMER_BATCH?'flex':'none';if($('custBatchBtn'))$('custBatchBtn').textContent=CUSTOMER_BATCH?'退出批量':'批量管理';renderCustomers()}
function selectedCustomerIds(){return Array.from(document.querySelectorAll('.custBatchCheck:checked')).map(x=>x.value).filter(Boolean)}
function selectVisibleCustomers(){document.querySelectorAll('.custBatchCheck:not(:disabled)').forEach(x=>x.checked=true)}
function clearVisibleCustomerChecks(){document.querySelectorAll('.custBatchCheck').forEach(x=>x.checked=false)}
async function batchDeleteSelectedCustomers(){
  if(!hasPerm('customer_manage')){alert('当前账号没有权限：customer_manage');return;}
  let ids=selectedCustomerIds();
  if(!ids.length){alert('请先勾选要删除的报价本地客户');return;}
  let local=ids.filter(id=>!String(id).startsWith('crm_'));
  let crm=ids.length-local.length;
  if(!local.length){alert('你选中的都是CRM实时客户，不能在报价系统删除。请在CRM删除后点“一键同步CRM客户”。');return;}
  let msg='确定删除选中的报价本地客户 '+local.length+' 个？';
  if(crm>0) msg+='\n已选的 '+crm+' 个CRM实时客户会自动跳过。';
  if(!confirm(msg)) return;
  let r=await api('batch_delete_customers',{ids:local});
  DB=await api('init');
  renderAll();
  alert('已删除本地客户 '+(r.deleted||0)+' 个；跳过 '+(r.skipped||0)+' 个。');
}
function renderCustomers(){
  refreshCustomerFilters();
  if($('custBatchTools'))$('custBatchTools').style.display=CUSTOMER_BATCH?'flex':'none';
  if($('custBatchBtn'))$('custBatchBtn').textContent=CUSTOMER_BATCH?'退出批量':'批量管理';
  let kw=($('custSearch')?.value||'').toLowerCase(), country=$('custCountryFilter')?.value||'', code=($('custCodeFilter')?.value||'').toLowerCase(), source=$('custSourceFilter')?.value||'', letter=$('custLetterFilter')?.value||'';
  let arr=DB.customers.filter(c=>
    (!kw||[c.code,c.company,c.contact,c.primary_contact,c.email,c.primary_contact_email,c.phone,c.primary_contact_phone,c.country,c.owner,c.website,c.address1,c.address2,c.addresses_json,customerSourceText(c)].join(' ').toLowerCase().includes(kw)) &&
    (!country||c.country===country) &&
    (!code||String(c.code||'').toLowerCase().includes(code)) &&
    (!source||String(c.source||'quote')===source) &&
    (!letter||firstLetter(c.company||c.code)===letter)
  ).sort((a,b)=>String(a.code||a.company||'').localeCompare(String(b.code||b.company||''),'zh'));
  let crmCount=DB.customers.filter(c=>c.source==='crm').length, localCount=DB.customers.filter(c=>c.source!=='crm').length;
  if($('custCount')) $('custCount').textContent='共 '+arr.length+' / '+DB.customers.length+' 个客户｜CRM '+crmCount+' 个｜本地 '+localCount+' 个';
  $('customerList').innerHTML=arr.map(c=>{
    const isCrm=c.source==='crm';
    const mail=c.email||c.primary_contact_email||'';
    const contact=c.contact||c.primary_contact||'';
    const phone=c.phone||c.primary_contact_phone||'';
    const addr=quoteCustomerAddressLines(c).join(' / ');
    const row=`<b><span class="badge ${isCrm?'crm':'local'}">${customerSourceText(c)}</span> ${esc(c.code||'')} ${esc(c.company||contact||'')}${isCrm?'<span class="customer-live-note">实时CRM</span>':''}</b><small>${esc(c.country||'')} ｜ ${esc(contact)} ｜ ${esc(mail)} ${phone?'｜电话 '+esc(phone):''}${c.website?'｜网站 '+esc(c.website):''}${addr?'｜地址 '+esc(addr):''}${c.owner?'｜负责人 '+esc(c.owner):''}</small>`;
    const check=CUSTOMER_BATCH?`<input class="customer-row-check custBatchCheck" type="checkbox" value="${esc(c.id)}" ${isCrm?'disabled title="CRM实时客户不能在报价删除"':''} onclick="event.stopPropagation()">`:'';
    return `<div class="item customer-row ${CUSTOMER_BATCH?'batch-on':''}" onclick="editCustomer('${jsq(c.id)}')">${check}<div>${row}</div></div>`;
  }).join('') || '<div class="hint">暂无客户</div>';
}
function editCustomer(id){
  let c=DB.customers.find(x=>String(x.id)==String(id));if(!c)return;
  const set=(id,v)=>{let el=$(id); if(el) el.value=v||'';};
  set('cId',c.id); set('cCode',c.code); set('cCompany',c.company||c.name||'');
  set('cContact',c.contact||c.primary_contact||''); set('cEmail',c.email||c.primary_contact_email||''); set('cPhone',c.phone||c.primary_contact_phone||''); set('cCountry',c.country);
  set('cWebsite',c.website); set('cAddress1',c.address1); set('cAddress2',c.address2); set('cAddressesJson',formatCustomerAddressesForEdit(c)); set('cNote',c.note);
  set('cSource',c.source||'quote'); set('cCrmCustomerId',c.crm_customer_id||''); set('cCrmIdView',c.crm_customer_id||'');
}async function deleteCustomer(){if(!hasPerm('customer_manage')){alert('当前账号没有权限：customer_manage');return;}if(!$('cId').value)return;if(String($('cId').value).startsWith('crm_')){alert('这是CRM客户，不能在报价系统删除。请在 CRM 客户中心删除，然后点“一键同步CRM客户”。');return;}await api('delete_customer',{id:$('cId').value});DB=await api('init');renderCustomers();renderSelects()}
function refreshMaterialFilters(){
  setSelectOptions('matCat', DB.materials.map(m=>m.category),'全部分类');
  setSelectOptions('matBrand', DB.materials.map(m=>m.brand),'全部品牌');
  setSelectOptions('matSupplier', DB.materials.map(m=>m.supplier),'全部供应商');
  setSelectOptions('matUnit', DB.materials.map(m=>m.unit),'全部单位');
}
function renderMaterials(){
  refreshMaterialFilters();
  let kw=($('matSearch')?.value||'').toLowerCase(), cv=$('matCat')?.value||'', brand=$('matBrand')?.value||'', supplier=$('matSupplier')?.value||'', unit=$('matUnit')?.value||'', min=Number($('matPriceMin')?.value||''), max=Number($('matPriceMax')?.value||''), sort=$('matSort')?.value||'category', pageSize=Number($('matPageSize')?.value||500);
  let arr=DB.materials.filter(m=>
    (!cv||m.category===cv) &&
    (!brand||m.brand===brand) &&
    (!supplier||m.supplier===supplier) &&
    (!unit||m.unit===unit) &&
    (!kw||matText(m).includes(kw)) &&
    (!$('matPriceMin')?.value || Number(m.price||0)>=min) &&
    (!$('matPriceMax')?.value || Number(m.price||0)<=max)
  );
  arr.sort((a,b)=>{
    if(sort==='priceAsc') return Number(a.price||0)-Number(b.price||0);
    if(sort==='priceDesc') return Number(b.price||0)-Number(a.price||0);
    if(sort==='brand') return String(a.brand||'').localeCompare(String(b.brand||''),'zh');
    if(sort==='name') return String(a.name||'').localeCompare(String(b.name||''),'zh');
    return (String(a.category||'')+String(a.name||'')).localeCompare(String(b.category||'')+String(b.name||''),'zh');
  });
  if($('matCount')) $('matCount').textContent='显示 '+Math.min(arr.length,pageSize)+' / '+arr.length+' 条，共 '+DB.materials.length+' 条物料';
  arr=arr.slice(0,pageSize);
  $('materialsTable').innerHTML=`<table class="table"><tr><th>分类</th><th>品牌</th><th>名称</th><th>型号</th><th>规格</th><th>单价</th><th>单位</th><th>供应商</th></tr>${arr.map(m=>`<tr><td>${esc(m.category)}</td><td>${esc(m.brand)}</td><td>${esc(m.name)}</td><td>${esc(m.model)}</td><td>${esc(m.spec)}</td><td>${money(m.price)}</td><td>${esc(m.unit)}</td><td>${esc(m.supplier)}</td></tr>`).join('')}</table>`;
}
function refreshHistoryFilters(){
  setSelectOptions('histCustomer', DB.quotes.map(q=>quoteCustomer(q).company),'全部客户');
  setSelectOptions('histCountry', DB.quotes.map(q=>quoteCustomer(q).country),'全部国家');
}
function inRangeDate(q,rg){
  let d=new Date(q.quote_date), nowd=new Date();
  if(!q.quote_date) return rg==='all';
  if(rg==='today') return q.quote_date===today();
  if(rg==='3') return nowd-d<=3*864e5;
  if(rg==='7') return nowd-d<=7*864e5;
  if(rg==='month') return String(q.quote_date).slice(0,7)===today().slice(0,7);
  if(rg==='lastMonth'){let x=new Date();x.setMonth(x.getMonth()-1);let ym=x.toISOString().slice(0,7);return String(q.quote_date).slice(0,7)===ym}
  if(rg==='3m') return nowd-d<=92*864e5;
  if(rg==='year') return String(q.quote_date).slice(0,4)===today().slice(0,4);
  return true;
}
function renderHistory(keepPage=false){
  if(!keepPage) historyPage=1;
  refreshHistoryFilters();
  updateHistoryViewButtons();
  let kw=($('histSearch')?.value||'').toLowerCase(), rg=$('histRange')?.value||'all', mon=$('histMonth')?.value||'', cust=$('histCustomer')?.value||'', country=$('histCountry')?.value||'', currency=$('histCurrency')?.value||'', min=Number($('histAmountMin')?.value||''), max=Number($('histAmountMax')?.value||''), sort=$('histSort')?.value||'dateDesc';
  let arr=DB.quotes.filter(q=>{
    let c=quoteCustomer(q);
    let text=quoteHistorySearchTextV68540(q,c).toLowerCase();
    return (!kw||text.includes(kw)) &&
      inRangeDate(q,rg) &&
      (!mon||String(q.quote_date).slice(0,7)===mon) &&
      (!cust||c.company===cust) &&
      (!country||c.country===country) &&
      (!currency||q.currency===currency) &&
      (!$('histAmountMin')?.value || Number(q.amount||0)>=min) &&
      (!$('histAmountMax')?.value || Number(q.amount||0)<=max);
  });
  arr.sort((a,b)=>{
    if(sort==='amountDesc') return Number(b.amount||0)-Number(a.amount||0);
    if(sort==='amountAsc') return Number(a.amount||0)-Number(b.amount||0);
    if(sort==='customerAsc') return String(quoteCustomer(a).company||'').localeCompare(String(quoteCustomer(b).company||''),'zh');
    return String(b.quote_date||'').localeCompare(String(a.quote_date||''));
  });
  let totalFiltered=arr.length;
  let totalPages=Math.max(1,Math.ceil(totalFiltered/historyPageSize));
  if(historyPage>totalPages) historyPage=totalPages;
  let startIdx=(historyPage-1)*historyPageSize;
  let pageArr=arr.slice(startIdx,startIdx+historyPageSize);
  if($('historyList')) $('historyList').className='history-list history-view-'+(historyView||'list');
  let html='', last='';
  pageArr.forEach(q=>{
    let c={},p={},items=[];try{c=JSON.parse(q.customer_json||'{}');p=JSON.parse(q.product_json||'{}');items=JSON.parse(q.items_json||'[]')}catch(e){}
    let ym=String(q.quote_date||'').slice(0,7)||'未分组';
    if(ym!==last){html+=`<div class="history-group">${esc(ym)}</div>`;last=ym}
    let itemText=items.length?items.length+' 个产品':(p.name||'');
    html+=historyCardHtml(q,c,p,items,itemText);
  });
  $('historyList').innerHTML=html||'<div class="hint">没有匹配的报价。</div>';
  renderHistoryPager(totalFiltered,pageArr.length);
}
async function deleteQuote(id){if(confirm('删除报价？')){await api('delete_quote',{id});await refreshQuoteRuntime('delete_quote',{orders:true,documents:true});}}
function loadQuote(id){let q=DB.quotes.find(x=>x.id==id);if(!q)return;S.currentQuoteId=Number(q.id||0);S.currentApprovalStatus=quoteApprovalStatus(q);try{S.customer=JSON.parse(q.customer_json||'{}');S.items=JSON.parse(q.items_json||'[]');if(!Array.isArray(S.items)||!S.items.length){S.product=JSON.parse(q.product_json||'{}');S.parts=JSON.parse(q.parts_json||'{}');S.items=[{product:S.product,parts:S.parts,qty:q.qty,price:q.price,amount:q.amount,moq:q.moq,color:q.color,beam_angle:q.beam_angle||'',cct:q.cct||'',cri:q.cri||'',ip:q.ip||'',extra_spec:q.extra_spec,product_type:q.product_type}]} }catch(e){S.items=[]}$('quoteNo').value=q.quote_no;$('quoteDate').value=q.quote_date;$('currency').value=q.currency||'USD';$('rate').value=q.exchange_rate||7;QUOTE_LAST_CURRENCY=$('currency').value;if($('topCurrency'))$('topCurrency').value=$('currency').value;if($('topRate'))$('topRate').value=$('rate').value||systemExchangeRate();S.items=(S.items||[]).map(it=>normalizeQuoteItemCurrency(it,$('currency').value,it.currency||$('currency').value));if($('quoteStatus')){let qs=q.quote_status||q.status||'Quotation sheet';$('quoteStatus').value=/proforma|invoice/i.test(qs)?'PROFORMA INVOICE':'Quotation sheet';}try{S.currentQuoteOwner=q.user_name||S.currentQuoteOwner||'';quoteApplySavedDocConfig(q);}catch(e){}$('manualPrice').value='';S.editingIndex=-1;if(S.items.length)loadItemToEditor(S.items[0],0);showPage('quote');clientLog('open_history_quote','打开历史报价',{quote_id:id,quote_no:q.quote_no,amount:q.amount,customer:S.customer,approval_status:S.currentApprovalStatus});renderProductSelect();renderParts();updateQuoteApprovalStrip(q);render()}function copyQuote(id){loadQuote(id);clientLog('copy_quote','复制历史报价',{source_quote_id:id,source_quote_no:(DB.quotes.find(x=>x.id==id)||{}).quote_no});S.currentQuoteId=0;S.currentQuoteOwner='';S.currentApprovalStatus='new';$('quoteDate').value=today();$('quoteNo').value=qno();S.editingIndex=-1;updateQuoteApprovalStrip();render()}
function showSettingsTab(tab){
  document.querySelectorAll('#settingsTabs button').forEach(b=>b.classList.remove('active'));
  document.querySelectorAll('.settings-panel').forEach(p=>p.classList.remove('active'));
  let btn=[...document.querySelectorAll('#settingsTabs button')].find(b=>String(b.getAttribute('onclick')||'').includes("'"+tab+"'"));
  if(btn) btn.classList.add('active');
  let panel=document.querySelector('[data-settings-panel="'+tab+'"]');
  if(panel) panel.classList.add('active');
  try{localStorage.setItem('artdon_quote_settings_tab',tab)}catch(e){}
  if(tab==='backup')setTimeout(loadQuoteBackups,50);
}
function restoreSettingsTab(){try{let tab=localStorage.getItem('artdon_quote_settings_tab')||'defaults'; if(document.querySelector('[data-settings-panel="'+tab+'"]')) showSettingsTab(tab);}catch(e){}}
function quoteDefaultIds(){return getQuoteDefaults()||{}}
function isDefaultId(type,id){let d=quoteDefaultIds();return String(d[type+'_id']||'')===String(id||'')}
function setQuoteDefaultId(type,id,opt={}){let d=Object.assign({},getQuoteDefaults());d[type+'_id']=String(id||'');localStorage.setItem('artdon_quote_defaults',JSON.stringify(d));applyQuoteDefaults();renderSelects();renderHeaders();renderBanks();renderTemplates();if(!opt.silent)alert('已设为默认');}
function settingsRow(title,sub,actions,extra=''){return `<div class="settings-row"><div class="settings-row-main"><b>${title}</b>${extra}<small>${sub||''}</small></div><div class="settings-row-actions">${actions||''}</div></div>`}
function btn(cls,txt,js){return `<button class="${cls}" type="button" onclick="event.stopPropagation();${js}">${txt}</button>`}
async function savePriceLevel(){if(!hasPerm('settings_manage')){alert('当前账号没有权限：settings_manage');return;}let d={id:$('plId').value,name:$('plName').value,multiplier:$('plMultiplier').value,note:$('plNote').value,is_default:$('plDefault').checked?1:0,is_active:$('plActive').checked?1:0,sort_order:$('plSort').value||0};if(!d.name||!Number(d.multiplier)){alert('请填写等级名称和倍率');return;}await api('save_price_level',d);DB=await api('init');clearPriceLevelForm();renderAll();alert('报价等级已保存')}async function deletePriceLevel(){if(!hasPerm('settings_manage')){alert('当前账号没有权限：settings_manage');return;}if(!$('plId').value){alert('请先选择一个报价等级');return;}if(!confirm('停用/删除当前报价等级？'))return;await api('delete_price_level',{id:$('plId').value});DB=await api('init');clearPriceLevelForm();renderAll();alert('已停用/删除')}function clearPriceLevelForm(){['plId','plName','plMultiplier','plNote'].forEach(id=>{$(id).value=''});$('plSort').value=0;$('plDefault').checked=false;$('plActive').checked=true}function renderPriceLevels(){if(!$('priceLevelList'))return;let arr=quoteLevels();$('priceLevelList').innerHTML=arr.length?arr.map(l=>settingsRow(esc(l.name)+' ×'+Number(l.multiplier||1).toFixed(2),esc(l.note||'')+' ｜ 排序 '+esc(l.sort_order||0)+(Number(l.is_active||1)?' ｜ 启用':' ｜ 已停用'),btn('blue','编辑',`editPriceLevel('${jsq(l.id)}')`)+btn('red','停用',`editPriceLevel('${jsq(l.id)}');deletePriceLevel()`),Number(l.is_default||0)?'<span class="default-badge">默认</span>':'')).join(''):'<div class="settings-empty">暂无报价等级。</div>'}function editPriceLevel(id){let l=(DB.price_levels||[]).find(x=>String(x.id)===String(id));if(!l)return;showSettingsTab('levels');$('plId').value=l.id;$('plName').value=l.name||'';$('plMultiplier').value=l.multiplier||1;$('plNote').value=l.note||'';$('plSort').value=l.sort_order||0;$('plDefault').checked=!!Number(l.is_default||0);$('plActive').checked=!!Number(l.is_active??1)}
async function saveOptionItem(){if(!hasPerm('settings_manage')){alert('当前账号没有权限：settings_manage');return;}let d={id:$('optId').value,group_key:$('optGroup').value,value:$('optValue').value,label:$('optLabel').value||$('optValue').value,note:$('optNote').value,is_active:1,sort_order:$('optSort').value||0};if(!d.value){alert('请填写选项值');return;}await api('save_option',d);DB=await api('init');clearOptionForm();renderAll();alert('选项已保存')}async function deleteOptionItem(){if(!hasPerm('settings_manage')){alert('当前账号没有权限：settings_manage');return;}if(!$('optId').value){alert('请先选择一个选项');return;}if(!confirm('停用/删除当前选项？'))return;await api('delete_option',{id:$('optId').value});DB=await api('init');clearOptionForm();renderAll();alert('已停用/删除')}function clearOptionForm(){['optId','optValue','optLabel','optNote'].forEach(id=>{$(id).value=''});$('optGroup').value='color';$('optSort').value=0}function renderOptions(){if(!$('optionList'))return;let arr=(DB.options||[]).filter(o=>String(o.group_key||'')===($('optGroup')?.value||'color'));$('optionList').innerHTML=arr.length?arr.map(o=>settingsRow(esc(o.label||o.value),esc(o.group_key)+' ｜ 值：'+esc(o.value)+' ｜ 排序 '+esc(o.sort_order||0)+(o.note?' ｜ '+esc(o.note):''),btn('blue','编辑',`editOptionItem('${jsq(o.id)}')`)+btn('red','停用',`editOptionItem('${jsq(o.id)}');deleteOptionItem()`))).join(''):'<div class="settings-empty">暂无选项。</div>'}function editOptionItem(id){let o=(DB.options||[]).find(x=>String(x.id)===String(id));if(!o)return;showSettingsTab('options');$('optId').value=o.id;$('optGroup').value=o.group_key||'color';$('optValue').value=o.value||'';$('optLabel').value=o.label||'';$('optNote').value=o.note||'';$('optSort').value=o.sort_order||0}
function clearHeaderForm(){['hId','hName','hCompany','hFrom','hStamp'].forEach(id=>{if($(id))$(id).value=''});if($('hShowStamp'))$('hShowStamp').checked=false}async function saveHeader(){if(!canDocSettings()){alert('当前账号没有权限：doc_settings_manage');return;}let name=($('hName')?.value||'').trim();if(!name){alert('请填写抬头名称');return;}await api('save_header',{id:$('hId').value,name,company:$('hCompany').value,from_text:$('hFrom').value,stamp:$('hStamp').value,show_stamp:$('hShowStamp')?.checked?1:0});DB=await api('init');renderAll();alert('公司抬头已保存')}async function saveHeaderAndDefault(){await saveHeader();let h=(DB.headers||[]).find(x=>x.name===($('hName')?.value||''))||DB.headers?.[0];if(h)setQuoteDefaultId('header',h.id)}async function deleteHeader(){if(!canDocSettings()){alert('当前账号没有权限：doc_settings_manage');return;}if(!$('hId').value){alert('请先选择一个公司抬头');return;}if(!confirm('删除当前公司抬头？'))return;await api('delete_header',{id:$('hId').value});clearHeaderForm();DB=await api('init');renderAll();alert('已删除')}function renderHeaders(){if(!$('headerList'))return;$('headerList').innerHTML=(DB.headers||[]).length?DB.headers.map(h=>settingsRow(esc(h.name||'未命名'),esc(h.company||'')+(isDefaultId('header',h.id)?' ｜ 当前默认':''),btn('blue','编辑',`editHeader(${Number(h.id)})`)+btn('green','设默认',`setQuoteDefaultId('header',${Number(h.id)})`)+btn('red','删除',`editHeader(${Number(h.id)});deleteHeader()`),isDefaultId('header',h.id)?'<span class="default-badge">默认</span>':'')).join(''):'<div class="settings-empty">暂无抬头。</div>'}function editHeader(id){let h=DB.headers.find(x=>x.id==id);if(!h)return;showSettingsTab('headers');$('hId').value=h.id;$('hName').value=h.name||'';$('hCompany').value=h.company||'';$('hFrom').value=h.from_text||'';$('hStamp').value=h.stamp||'';$('hShowStamp').checked=!!Number(h.show_stamp||0)}
function clearBankForm(){['bId','bName','bText','bExtraTerms'].forEach(id=>{if($(id))$(id).value=''});if($('bTermsFontSize'))$('bTermsFontSize').value='7.5'}
async function persistBankFromSettings(setDefault=false){
  if(!canDocSettings()){alert('当前账号没有权限：doc_settings_manage');return null;}
  let name=($('bName')?.value||'').trim();
  if(!name){alert('请填写银行名称');return null;}
  let payload={id:$('bId')?.value||'',name,text:$('bText')?.value||'',extra_terms:$('bExtraTerms')?.value||'',extra_terms_font_size:quoteTermsFontSizeValue($('bTermsFontSize')?.value||7.5)};
  let res=await api('save_bank',payload);
  DB=await api('init');
  let saved=(DB.banks||[]).find(x=>String(x.id)===String(res.id))||(DB.banks||[]).find(x=>String(x.name||'')===name);
  if(saved){
    S.bank=saved;
    $('bId').value=saved.id||'';
    $('bName').value=saved.name||'';
    $('bText').value=saved.text||'';
    if($('bExtraTerms'))$('bExtraTerms').value=saved.extra_terms||saved.terms_text||'';
    if($('bTermsFontSize'))$('bTermsFontSize').value=String(quoteTermsFontSizeValue(saved.extra_terms_font_size||saved.terms_font_size||7.5));
    if(setDefault)setQuoteDefaultId('bank',saved.id,{silent:true});
  }
  renderSelects();renderBanks();renderQdocQuickSettings();render();showSettingsTab('banks');
  alert(setDefault?'银行信息已保存并设为默认':'银行信息已保存');
  return saved;
}
async function saveBank(){await persistBankFromSettings(false)}
async function saveBankAndDefault(){await persistBankFromSettings(true)}
async function deleteBank(){if(!canDocSettings()){alert('当前账号没有权限：doc_settings_manage');return;}if(!$('bId').value){alert('请先选择一个银行信息');return;}if(!confirm('删除当前银行信息？'))return;await api('delete_bank',{id:$('bId').value});clearBankForm();DB=await api('init');renderAll();alert('已删除')}
function renderBanks(){if(!$('bankList'))return;$('bankList').innerHTML=(DB.banks||[]).length?DB.banks.map(b=>settingsRow(esc(b.name||'未命名'),(esc(String(b.text||'').slice(0,80))||'无银行内容')+(b.extra_terms?' ｜ 有附加条款':'')+' ｜ 条款字体 '+quoteTermsFontSizeValue(b.extra_terms_font_size||7.5)+'pt'+(isDefaultId('bank',b.id)?' ｜ 当前默认':''),btn('blue','编辑',`editBank(${Number(b.id)})`)+btn('green','设默认',`setQuoteDefaultId('bank',${Number(b.id)})`)+btn('red','删除',`editBank(${Number(b.id)});deleteBank()`),isDefaultId('bank',b.id)?'<span class="default-badge">默认</span>':'')).join(''):'<div class="settings-empty">暂无银行信息。</div>'}
function editBank(id){let b=DB.banks.find(x=>x.id==id);if(!b)return;showSettingsTab('banks');$('bId').value=b.id;$('bName').value=b.name||'';$('bText').value=b.text||'';if($('bExtraTerms'))$('bExtraTerms').value=b.extra_terms||b.terms_text||'';if($('bTermsFontSize'))$('bTermsFontSize').value=String(quoteTermsFontSizeValue(b.extra_terms_font_size||b.terms_font_size||7.5))}
function defaultTermsForm(){return {payment1:'40% Deposit before production',payment2:'',price_terms:'EXWORK',delivery_date:'25-35Days After Confirmed',quoted_valid:'Within 10 days'}}
function parseTemplateTerms(raw){
  let f=defaultTermsForm(), arr=[];
  try{arr=JSON.parse(raw||'[]')}catch(e){arr=[]}
  if(!Array.isArray(arr)) return f;
  let hasAny=false, hasPay2=false;
  arr.forEach(r=>{let k=String((r&&r[0])||'').trim().toLowerCase(), v=String((r&&r[1])||'').trim();hasAny=true;if(/payment/.test(k)) f.payment1=v||f.payment1;else if(k==='' && !hasPay2){f.payment2=v;hasPay2=true;}else if(/price\s*terms/.test(k)) f.price_terms=v||f.price_terms;else if(/delivery\s*date/.test(k)) f.delivery_date=v||f.delivery_date;else if(/quoted\s*valid/.test(k)) f.quoted_valid=v||f.quoted_valid;});
  if(hasAny && !hasPay2) f.payment2='';
  return f;
}
function templateTermsJsonFromForm(){let p1=($('tPayment1')?.value||'').trim(), p2=($('tPayment2')?.value||'').trim(), pt=($('tPriceTerms')?.value||'').trim(), dd=($('tDeliveryDate')?.value||'').trim(), qv=($('tQuotedValid')?.value||'').trim();let arr=[['PI Number:','QTNO']];arr.push(['Payment:',p1||'40% Deposit before production']);if(p2) arr.push(['',p2]);arr.push(['Price Terms:',pt||'EXWORK']);arr.push(['Quoted Date:','DATE']);arr.push(['Delivery Date:',dd||'25-35Days After Confirmed']);arr.push(['Quoted Valid',qv||'Within 10 days']);return JSON.stringify(arr);}
function fillTemplateFormFromTerms(raw){let f=parseTemplateTerms(raw);if($('tPayment1')) $('tPayment1').value=f.payment1||'';if($('tPayment2')) $('tPayment2').value=f.payment2||'';if($('tPriceTerms')) $('tPriceTerms').value=f.price_terms||'';if($('tDeliveryDate')) $('tDeliveryDate').value=f.delivery_date||'';if($('tQuotedValid')) $('tQuotedValid').value=f.quoted_valid||'';updateTemplatePreview();}
function updateTemplatePreview(){if(!$('templatePreview'))return;let f={payment1:$('tPayment1')?.value||'',payment2:$('tPayment2')?.value||'',price_terms:$('tPriceTerms')?.value||'',delivery_date:$('tDeliveryDate')?.value||'',quoted_valid:$('tQuotedValid')?.value||''};$('templatePreview').innerHTML=`<b>预览：</b><br>Payment: ${esc(f.payment1||'-')}<br>${f.payment2?esc(f.payment2)+'<br>':''}Price Terms: ${esc(f.price_terms||'-')} ｜ Delivery Date: ${esc(f.delivery_date||'-')} ｜ Quoted Valid: ${esc(f.quoted_valid||'-')}`;}
function clearTemplateForm(){if($('tId')) $('tId').value='';if($('tName')) $('tName').value='';fillTemplateFormFromTerms('');if($('templateFormTitle'))$('templateFormTitle').textContent='新增条款模板';}
async function saveTemplate(){if(!canDocSettings()){alert('当前账号没有权限：doc_settings_manage');return;}let name=($('tName')?.value||'').trim();if(!name){alert('请先填写条款模板名');return;}let res=await api('save_template',{id:$('tId').value,name,terms_json:templateTermsJsonFromForm()});DB=await api('init');let newest=(DB.templates||[]).find(t=>String(t.id)===String(res.id))||(DB.templates||[]).find(t=>t.name===name);if(newest){$('tId').value=newest.id;S.template=newest;}renderAll();alert('条款模板已保存');}
async function saveTemplateAndDefault(){await saveTemplate();let id=$('tId')?.value||'';if(id)setQuoteDefaultId('template',id)}
async function deleteTemplate(){if(!canDocSettings()){alert('当前账号没有权限：doc_settings_manage');return;}if(!$('tId').value){alert('请先选择一个模板');return;}if(!confirm('删除这个条款模板？'))return;await api('delete_template',{id:$('tId').value});clearTemplateForm();DB=await api('init');renderAll();alert('已删除模板');}
function renderTemplates(){if(!$('templateList'))return;let arr=DB.templates||[];$('templateList').innerHTML=arr.length?arr.map(t=>{let f=parseTemplateTerms(t.terms_json);let sub=`${esc(f.payment1||'')} ｜ ${esc(f.price_terms||'')} ｜ ${esc(f.delivery_date||'')} ｜ ${esc(f.quoted_valid||'')}`;return settingsRow(esc(t.name||'未命名'),sub+(isDefaultId('template',t.id)?' ｜ 当前默认':''),btn('blue','编辑',`editTemplate(${Number(t.id)})`)+btn('green','设默认',`setQuoteDefaultId('template',${Number(t.id)})`)+btn('red','删除',`editTemplate(${Number(t.id)});deleteTemplate()`),isDefaultId('template',t.id)?'<span class="default-badge">默认</span>':'')}).join(''):'<div class="settings-empty">暂无条款模板，点击左侧“保存模板”即可新增。</div>'}
function editTemplate(id){let t=DB.templates.find(x=>x.id==id);if(!t)return;showSettingsTab('templates');$('tId').value=t.id;$('tName').value=t.name;fillTemplateFormFromTerms(t.terms_json);if($('templateFormTitle'))$('templateFormTitle').textContent='编辑条款模板：'+(t.name||'');}
['tPayment1','tPayment2','tPriceTerms','tDeliveryDate','tQuotedValid'].forEach(id=>{document.addEventListener('input',e=>{if(e.target&&e.target.id===id)updateTemplatePreview();});});

async function loadQuoteBackups(){
  if(!$('quoteBackupList'))return;
  if(!hasPerm('settings_manage')){ $('quoteBackupList').innerHTML='<div class="settings-empty">当前账号没有系统设置权限。</div>'; return; }
  try{let r=await api('list_backups');renderQuoteBackups(r.backups||[]);}catch(e){$('quoteBackupList').innerHTML='<div class="settings-empty">读取备份失败：'+esc(e.message||e)+'</div>';}
}
function quoteBackupSize(n){n=Number(n||0); if(n>1024*1024)return (n/1024/1024).toFixed(2)+' MB'; if(n>1024)return (n/1024).toFixed(1)+' KB'; return n+' B';}
function renderQuoteBackups(arr){
  if(!$('quoteBackupList'))return;
  $('quoteBackupList').innerHTML=arr.length?arr.map(b=>settingsRow(esc(b.file),esc(b.mtime||'')+' ｜ '+quoteBackupSize(b.size),btn('blue','下载',`downloadQuoteBackup('${jsq(b.file)}')`))).join(''):'<div class="settings-empty">暂无备份。点击左侧“立即生成备份”。</div>';
}
function downloadQuoteBackup(file){window.open('quote_api.php?action=download_backup&file='+encodeURIComponent(file),'_blank')}
async function createQuoteBackup(){
  if(!hasPerm('settings_manage')){alert('当前账号没有权限：settings_manage');return;}
  if(!confirm('现在生成报价系统备份？'))return;
  if($('quoteBackupStatus'))$('quoteBackupStatus').textContent='正在备份，请稍候...';
  try{let r=await api('create_backup',{include_files:1});if($('quoteBackupStatus'))$('quoteBackupStatus').innerHTML='备份完成：<b>'+esc(r.file||'')+'</b> ｜ 数据表 '+Number(r.table_count||0)+' 个 ｜ 行数 '+Number(r.row_count||0)+' ｜ <a href="quote_api.php?action=download_backup&file='+encodeURIComponent(r.file||'')+'" target="_blank">下载备份</a>';await loadQuoteBackups();}
  catch(e){if($('quoteBackupStatus'))$('quoteBackupStatus').textContent='备份失败：'+(e.message||e);}
}
async function restoreQuoteBackup(){
  if(!hasPerm('settings_manage')){alert('当前账号没有权限：settings_manage');return;}
  let f=$('quoteRestoreFile')?.files?.[0]; if(!f){alert('请先选择备份 JSON 文件');return;}
  let code=($('quoteRestoreConfirm')?.value||'').trim(); if(code!=='RESTORE_QUOTE'){alert('请在确认码输入 RESTORE_QUOTE');return;}
  if(!confirm('确定恢复这个备份？当前报价系统数据会被覆盖。系统会先自动生成恢复前安全备份。'))return;
  let fd=new FormData(); fd.append('backup_file',f); fd.append('confirm',code);
  if($('quoteRestoreStatus'))$('quoteRestoreStatus').textContent='正在恢复，请不要刷新页面...';
  try{
    let r=await fetch('quote_api.php?action=restore_backup',{method:'POST',body:fd});
    let tx=await r.text(),j;
    try{j=JSON.parse(tx)}catch(parseErr){
      let clean=tx.replace(/<br\s*\/?>/gi,'\n').replace(/<[^>]*>/g,'').replace(/&nbsp;/g,' ').trim();
      throw new Error(clean.slice(0,500)||'恢复接口没有返回 JSON');
    }
    if(!j.ok)throw new Error(j.msg||'恢复失败');
    let data=j.data||{}; if($('quoteRestoreStatus'))$('quoteRestoreStatus').innerHTML='恢复完成。恢复前安全备份：<b>'+esc(data.pre_backup?.file||'')+'</b>';
    alert('恢复完成。系统已自动生成恢复前安全备份：'+(data.pre_backup?.file||''));
    DB=await api('init');renderAll();await loadQuoteBackups();
  }catch(e){if($('quoteRestoreStatus'))$('quoteRestoreStatus').textContent='恢复失败：'+(e.message||e);alert('恢复失败：'+(e.message||e));}
}

let LOG_CACHE=[], logTimer=null;
function debouncedLoadLogs(){clearTimeout(logTimer);logTimer=setTimeout(loadLogs,350)}
function logPretty(s){if(!s)return '';try{return JSON.stringify(JSON.parse(s),null,2)}catch(e){return String(s)}}
function logCsvCell(v){v=String(v??'');return '"'+v.replaceAll('"','""')+'"'}
async function loadLogs(){
  if(!$('logList'))return;
  let q=new URLSearchParams();
  ['kw','level'].forEach(k=>{let id='log'+k.charAt(0).toUpperCase()+k.slice(1); if($(id)?.value)q.set(k,$(id).value)});
  if($('logAction')?.value)q.set('op',$('logAction').value);
  if($('logDateFrom')?.value)q.set('date_from',$('logDateFrom').value);
  if($('logDateTo')?.value)q.set('date_to',$('logDateTo').value);
  q.set('limit',$('logLimit')?.value||200);
  let r=await fetch('quote_api.php?action=list_logs&'+q.toString());let j=await r.json();if(!j.ok){$('logList').innerHTML='<div class="hint">日志读取失败：'+esc(j.msg||'')+'</div>';return;}
  LOG_CACHE=j.data.logs||[];$('logCount').textContent='共 '+j.data.total+' 条，当前显示 '+LOG_CACHE.length+' 条';
  $('logList').innerHTML=LOG_CACHE.map(l=>`<div class="log-row"><div><span class="log-level ${esc(l.level||'INFO')}">${esc(l.level||'INFO')}</span> <b>${esc(l.event||l.action)}</b></div><div class="log-meta"><span>${esc(l.created_at)}</span><span>操作：${esc(l.action)}</span><span>报价：${esc(l.quote_no||'-')}</span><span>客户：${esc(l.customer_name||'-')}</span><span>用户：${esc(l.user_name||'-')}</span><span>IP：${esc(l.ip||'-')}</span></div><div>${esc(l.summary||'')}</div><details><summary>查看详细JSON / 修改前后</summary><pre>请求：\n${esc(logPretty(l.detail_json))}\n\n修改前：\n${esc(logPretty(l.before_json))}\n\n修改后：\n${esc(logPretty(l.after_json))}\n\n浏览器：${esc(l.user_agent||'')}\n请求地址：${esc(l.request_uri||'')}</pre></details></div>`).join('')||'<div class="hint">暂无日志。</div>';
}
async function testQuoteLogs(){try{let r=await api('log_health',{from:'quotation_ui',quote_no:($('quoteNo')?.value||''),customer_name:(S.customer?.company||''),time:new Date().toISOString()});alert('日志自检成功：当前日志 '+(r.count||0)+' 条');await loadLogs();}catch(e){alert('日志自检失败：'+(e.message||e));}}
function exportLogsCsv(){
  if(!LOG_CACHE.length){alert('没有可导出的日志，请先刷新日志。');return;}
  let head=['时间','级别','操作','事件','报价号','客户','用户','IP','摘要','详情JSON'];
  let lines=[head.map(logCsvCell).join(',')].concat(LOG_CACHE.map(l=>[l.created_at,l.level,l.action,l.event,l.quote_no,l.customer_name,l.user_name,l.ip,l.summary,l.detail_json].map(logCsvCell).join(',')));
  let blob=new Blob(['\ufeff'+lines.join('\n')],{type:'text/csv;charset=utf-8'});let a=document.createElement('a');a.href=URL.createObjectURL(blob);a.download='quote_logs_'+today()+'.csv';a.click();setTimeout(()=>URL.revokeObjectURL(a.href),1000);
}
async function deleteOldLogs(days){if(!confirm('确定清理 '+days+' 天以前的报价日志？'))return;await api('delete_logs',{mode:'older',days});await loadLogs();alert('已清理旧日志')}
async function clearAllLogs(){let v=prompt('此操作会清空全部报价日志。请输入 DELETE_ALL_LOGS 确认');if(v!=='DELETE_ALL_LOGS')return;await api('delete_logs',{confirm:'DELETE_ALL_LOGS'});await loadLogs();alert('已清空全部日志')}

function exportQuotePayload(){
  let items=quoteItemsForPreview().map(it=>{let cp=clone(it);cp.price=itemUnitPrice(cp);cp.amount=Number(cp.qty||0)*Number(cp.price||0);return cp});
  let total={qty:items.reduce((s,it)=>s+Number(it.qty||0),0),amount:items.reduce((s,it)=>s+Number(it.amount||0),0)};
  return {
    quote_no:$('quoteNo')?.value||qno(),
    quote_date:$('quoteDate')?.value||today(),
    quote_status:$('quoteStatus')?.value||'Draft',
    currency:cur(),
    exchange_rate:rate(),
    customer:S.customer||{},
    header:S.header||{},
    bank:S.bank||{},
    template:S.template||{},
    items:items,
    total:total,
    approval_status:(currentSavedQuote()?.approval_status||S.currentApprovalStatus||'new'),
    quote_id:(currentSavedQuote()?.id||S.currentQuoteId||0)
  };
}
function postExportPayload(url,p){
  if(!p || !Array.isArray(p.items) || !p.items.length){alert('请先添加产品，再导出。');return;}
  let form=document.createElement('form');
  form.method='POST';
  form.action=url;
  form.target='_blank';
  form.style.display='none';
  let input=document.createElement('input');
  input.type='hidden';
  input.name='payload';
  input.value=JSON.stringify(p);
  form.appendChild(input);
  document.body.appendChild(form);
  form.submit();
  setTimeout(()=>form.remove(),1500);
}

/* V6.8.5.21：订单PDF/Excel与报价PDF/Excel完全同版式；订单参数优先继承原报价完整快照。 */
function orderTitleByCurrency(currency){
  let c=quoteOrderRecordCurrency({currency});
  return c==='RMB'?'订购合同':'PROFORMA INVOICE';
}
function orderItemCodeOf(it){
  it=it||{}; let p=it.product||{};
  return String(it.product_code||it.customer_code||p.code||p.model||p.product_model||p.name||it.product_name||'').trim().toLowerCase();
}
function orderItemModelOf(it){
  it=it||{}; let p=it.product||{};
  return String(it.product_code||p.code||p.model||p.product_model||it.product_name||p.name||'').trim().toLowerCase();
}
function orderSpecLooksComplete(spec){
  spec=String(spec||'').trim();
  if(!spec) return false;
  let lines=spec.split(/\r?\n/).map(x=>x.trim()).filter(Boolean);
  return lines.length>=5 || /LED|Driver|Optic|Adapter|Accessories|Beam|CRI|CCT|IP\d+/i.test(spec);
}
function orderSourceQuoteItems(order,snap){
  let src=[];
  if(snap && Array.isArray(snap.items) && snap.items.length) src=clone(snap.items);
  if(!src.length && snap && Array.isArray(snap.quote_items) && snap.quote_items.length) src=clone(snap.quote_items);
  if(!src.length){
    let q=null;
    if(order&&order.source_quote_id) q=(DB.quotes||[]).find(x=>String(x.id)===String(order.source_quote_id));
    if(!q&&order&&order.quote_id) q=(DB.quotes||[]).find(x=>String(x.id)===String(order.quote_id));
    if(!q&&order&&order.quote_no) q=(DB.quotes||[]).find(x=>String(x.quote_no||'')===String(order.quote_no));
    if(q){
      src=parseMaybeJson(q.items_json,[]);
      if(!Array.isArray(src)) src=[];
    }
  }
  return Array.isArray(src)?src:[];
}
function orderExportItemFromRow(row){
  row=row||{};
  let item=parseMaybeJson(row.item_json,{});
  if(!item || typeof item!=='object') item={};
  if(!item.product || typeof item.product!=='object') item.product={};
  item.product.code=item.product.code||item.product.model||row.product_code||item.product_code||'';
  item.product.name=item.product.name||row.product_name||item.product_name||'';
  item.product.image=item.product.image||row.image||item.image||'';
  item.customer_code=row.customer_code||item.customer_code||'';
  item.product_code=row.product_code||item.product_code||item.product.code||'';
  item.product_name=row.product_name||item.product_name||item.product.name||'';
  item.color=row.color||item.color||'';
  item.qty=Number(row.qty||item.qty||0);
  item.price=Number(row.unit_price||row.price||item.price||item.unit_price||0);
  item.unit_price=item.price;
  item.amount=Number(row.amount||item.amount||(item.qty*item.price)||0);
  item.specification=row.specification||item.specification||'';
  item.is_order_snapshot=true;
  return item;
}
function mergeOrderItemWithQuoteSnapshot(orderItem,baseItem,index){
  let base=clone(baseItem||{}), flat=clone(orderItem||{});
  if(!base || typeof base!=='object') base={};
  if(!base.product || typeof base.product!=='object') base.product={};
  if(!flat.product || typeof flat.product!=='object') flat.product={};
  let out=Object.assign({},base,flat);
  out.product=Object.assign({},base.product||{},flat.product||{});
  // 保留原报价的 parts / quote_spec / cct / cri / beam / ip；只覆盖订单数量、单价、金额、颜色、客户型号。
  if(base.parts && typeof base.parts==='object') out.parts=clone(base.parts);
  if(!out.product.image && (base.product&&base.product.image)) out.product.image=base.product.image;
  out.customer_code=flat.customer_code||base.customer_code||'';
  out.product_code=flat.product_code||base.product_code||out.product.code||'';
  out.product_name=flat.product_name||base.product_name||out.product.name||'';
  if(out.product_code && !out.product.code) out.product.code=out.product_code;
  if(out.product_name && !out.product.name) out.product.name=out.product_name;
  out.color=flat.color||base.color||'';
  out.qty=Number(flat.qty||base.qty||0);
  out.price=Number(flat.price||flat.unit_price||base.price||base.unit_price||0);
  out.unit_price=out.price;
  out.amount=Number(flat.amount||(out.qty*out.price)||base.amount||0);
  let rowSpec=String(flat.specification||'').trim();
  let baseSpec=String(base.specification||'').trim();
  if(orderSpecLooksComplete(rowSpec)) out.specification=rowSpec;
  else if(orderSpecLooksComplete(baseSpec)) out.specification=baseSpec;
  else { try{ out.specification=buildSpec(out); }catch(e){ out.specification=rowSpec||baseSpec||''; } }
  // 不把完整 specification 塞进 extra_spec，避免导出时重复或截断成备注。
  if(String(out.extra_spec||'').trim()===String(out.specification||'').trim()) out.extra_spec='';
  out.is_order_snapshot=true;
  out.order_line_index=index;
  return out;
}
function pickBaseQuoteItemForOrder(flat,sourceItems,used,index){
  if(sourceItems[index] && !used.has(index)){used.add(index);return sourceItems[index];}
  let code=orderItemModelOf(flat), cc=String(flat.customer_code||'').trim().toLowerCase();
  let best=-1;
  for(let i=0;i<sourceItems.length;i++){
    if(used.has(i)) continue;
    let b=sourceItems[i]||{}, bcode=orderItemModelOf(b), bcc=String(b.customer_code||'').trim().toLowerCase();
    if(code && bcode && code===bcode){best=i;break;}
    if(cc && bcc && cc===bcc){best=i;break;}
  }
  if(best>=0){used.add(best);return sourceItems[best];}
  return null;
}

// V6.8.5.22：订单号显示与报价号统一使用 AT，不再显示 SO 前缀。
function quoteOrderNoAtV68522(no,quoteNo=''){
  let v=String(no||quoteNo||'').trim();
  if(/^SO-/i.test(v)) return 'AT-'+v.replace(/^SO-/i,'');
  return v;
}
function orderPayloadFromDetail(order,rows){
  let o=order||{};
  let snap=parseMaybeJson(o.snapshot_json,{});
  let customer=parseMaybeJson(o.customer_json,{}) || {};
  let header=parseMaybeJson(o.header_json,{}) || {};
  let bank=parseMaybeJson(o.bank_json,{}) || {};
  let template=parseMaybeJson(o.template_json,{}) || {};
  if(!quoteNonEmptyObject(customer) && quoteNonEmptyObject(snap.customer)) customer=clone(snap.customer);
  if(!quoteNonEmptyObject(header) && quoteNonEmptyObject(snap.header)) header=clone(snap.header);
  if(!quoteNonEmptyObject(bank) && quoteNonEmptyObject(snap.bank)) bank=clone(snap.bank);
  if(!quoteNonEmptyObject(template) && quoteNonEmptyObject(snap.template)) template=clone(snap.template);
  let rawRows=Array.isArray(rows)?rows:[];
  if(!rawRows.length && Array.isArray(snap.order_items)) rawRows=snap.order_items;
  let flatItems=rawRows.map(orderExportItemFromRow).filter(it=>it.qty>0 || it.product?.code || it.product?.name || it.product_code || it.product_name);
  let sourceItems=orderSourceQuoteItems(o,snap);
  let used=new Set();
  let items=flatItems.map((it,i)=>mergeOrderItemWithQuoteSnapshot(it,pickBaseQuoteItemForOrder(it,sourceItems,used,i),i));
  if(!items.length && sourceItems.length) items=sourceItems.map((it,i)=>mergeOrderItemWithQuoteSnapshot(it,it,i));
  items=items.map(it=>{let cp=clone(it);cp.price=Number(cp.price||cp.unit_price||0);cp.unit_price=cp.price;cp.qty=Number(cp.qty||0);cp.amount=Number(cp.amount||(cp.qty*cp.price)||0);try{if(!cp.specification) cp.specification=buildSpec(cp);}catch(e){}return cp;});
  let total={qty:items.reduce((s,it)=>s+Number(it.qty||0),0),amount:items.reduce((s,it)=>s+Number(it.amount||0),0)};
  let currency=o.currency||snap.currency||cur();
  let displayOrderNo=quoteOrderNoAtV68522(o.order_no,o.quote_no||snap.quote_no||'');
  return {
    quote_no:displayOrderNo||o.quote_no||'',
    quote_date:o.order_date||o.quote_date||today(),
    quote_status:orderTitleByCurrency(currency),
    currency:currency,
    exchange_rate:Number(o.exchange_rate||snap.exchange_rate||rate()||1),
    customer:customer,
    header:header,
    bank:bank,
    template:template,
    items:items,
    total:total,
    is_order:1,
    order_id:o.id||0,
    order_no:displayOrderNo||o.order_no||'',
    source_quote_no:o.quote_no||'',
    approval_status:'approved',
    order_export:1,
    quote_id:o.quote_id||o.source_quote_id||snap.quote_id||0
  };
}
async function exportOrderDoc(orderId,type){
  orderId=Number(orderId||0); if(!orderId){alert('订单ID无效');return;}
  if(typeof hasPerm==='function' && !hasPerm('export_pdf_excel')){alert('当前账号没有导出权限。');return;}
  try{
    let d=await orderApi('detail',{id:orderId});
    let payload=orderPayloadFromDetail(d.order||{},d.items||[]);
    if(!payload.items || !payload.items.length){alert('订单没有产品明细，不能导出。');return;}
    try{clientLog(type==='excel'?'export_order_excel':'export_order_pdf','导出订单'+(type==='excel'?'Excel':'PDF'),{order_id:orderId,order_no:payload.order_no,quote_no:payload.source_quote_no});}catch(e){}
    // V6.8.5.13：订单 PDF / Excel 不再走单独订单模板，直接走报价系统正式导出模板，保证版式与报价单完全一致。
    // 订单只改变单据标题与单据号：外币为 PROFORMA INVOICE，RMB 为订购合同；其它抬头、表格、分页、银行、签名全部沿用报价导出。
    payload.approval_status='approved';
    payload.order_export=1;
    payload.quote_id=payload.quote_id||0;
    postExportPayload(orderQuoteFormatExportUrl(type),payload);
  }catch(e){alert('订单导出失败：'+e.message);}
}
function exportOrderPDF(orderId){return exportOrderDoc(orderId,'pdf')}
function exportOrderExcel(orderId){return exportOrderDoc(orderId,'excel')}

function postExportFile(url){
  postExportPayload(url, exportQuotePayload());
}
function quoteExportUrl(type){
  return type==='excel' ? 'crm_quote_excel.php' : 'crm_quote_pdf.php';
}
function orderQuoteFormatExportUrl(type){
  // V6.8.5.22：已转订单直接走报价正式导出文件，订单号统一 AT，不再走订单桥接模板，PDF/Excel版式与报价单完全一致。
  return quoteExportUrl(type);
}
function parseMaybeJson(v,def){
  if(v===null || v===undefined || v==='') return def;
  if(typeof v==='object') return v;
  try{return JSON.parse(v)}catch(e){return def}
}
function quoteNonEmptyObject(o){return !!(o&&typeof o==='object'&&!Array.isArray(o)&&Object.keys(o).length)}
function quoteConfigById(arr,id){return (arr||[]).find(x=>String(x.id)===String(id))||{};}
function payloadFromSavedQuote(q){
  let customer=parseMaybeJson(q.customer_json,{});
  let header=parseMaybeJson(q.header_json,{});
  let bank=parseMaybeJson(q.bank_json,{});
  let template=parseMaybeJson(q.template_json,{});
  if(!quoteNonEmptyObject(header)) header=clone(quoteConfigById(DB.headers,q.header_id)||{});
  if(!quoteNonEmptyObject(bank)) bank=clone(quoteConfigById(DB.banks,q.bank_id)||{});
  if(!quoteNonEmptyObject(template)) template=clone(quoteConfigById(DB.templates,q.template_id)||{});
  if(!quoteNonEmptyObject(header) && S.header) header=clone(S.header);
  if(!quoteNonEmptyObject(bank) && S.bank) bank=clone(S.bank);
  if(!quoteNonEmptyObject(template) && S.template) template=clone(S.template);
  let items=parseMaybeJson(q.items_json,[]);
  if(!Array.isArray(items)) items=[];
  if(!items.length){
    let product=parseMaybeJson(q.product_json,{});
    let parts=parseMaybeJson(q.parts_json,{});
    if(product && (product.id || product.model || product.model_no || product.name)){
      items=[{product,parts,qty:q.qty,price:q.price,amount:q.amount,moq:q.moq,color:q.color,beam_angle:q.beam_angle||'',cct:q.cct||'',cri:q.cri||'',ip:q.ip||'',extra_spec:q.extra_spec,product_type:q.product_type}];
    }
  }
  items=items.map(it=>{
    let cp=clone(it||{});
    cp.qty=Number(cp.qty||1);
    cp.price=Number(cp.price||0);
    if(!cp.price && typeof itemUnitPrice==='function'){
      try{cp.price=itemUnitPrice(cp)}catch(e){}
    }
    cp.amount=Number(cp.amount||0) || cp.qty*Number(cp.price||0);
    if(!cp.specification && typeof buildSpec==='function'){
      try{cp.specification=buildSpec(cp)}catch(e){}
    }
    return cp;
  });
  let total={qty:items.reduce((s,it)=>s+Number(it.qty||0),0),amount:items.reduce((s,it)=>s+Number(it.amount||0),0)};
  return {quote_no:q.quote_no||qno(),quote_date:q.quote_date||today(),quote_status:q.quote_status||q.status||'Quotation sheet',currency:q.currency||cur(),exchange_rate:Number(q.exchange_rate||1),customer,header,bank,template,items,total};
}
function exportPDF(){
  if(typeof hasPerm==='function' && !hasPerm('export_pdf_excel')){alert('当前账号没有导出权限。');return;}
  let q=requireApprovedForOutput('导出PDF'); if(!q)return;
  try{clientLog('export_pdf_click','导出PDF',{quote_no:q.quote_no,quote_id:q.id});}catch(e){}
  postExportPayload(quoteExportUrl('pdf'), payloadFromSavedQuote(q));
}
function exportExcel(){
  if(typeof hasPerm==='function' && !hasPerm('export_pdf_excel')){alert('当前账号没有导出权限。');return;}
  let q=requireApprovedForOutput('导出Excel'); if(!q)return;
  try{clientLog('export_excel_click','导出Excel',{quote_no:q.quote_no,quote_id:q.id});}catch(e){}
  postExportPayload(quoteExportUrl('excel'), payloadFromSavedQuote(q));
}
function openHistoryExport(id,type){
  if(typeof hasPerm==='function' && !hasPerm('export_pdf_excel')){alert('当前账号没有导出权限。');return;}
  let q=(DB.quotes||[]).find(x=>String(x.id)===String(id));
  if(!q){alert('找不到这张历史报价。');return;}
  if(!quoteIsApproved(q)){alert('这张报价未审核通过，不能导出。当前状态：'+approvalLabel(quoteApprovalStatus(q)));return;}
  let payload=payloadFromSavedQuote(q);
  try{clientLog(type==='excel'?'export_history_excel':'导出历史报价',{quote_id:id,quote_no:q.quote_no,approval_status:q.approval_status});}catch(e){}
  postExportPayload(quoteExportUrl(type==='excel'?'excel':'pdf'),payload);
}

function permKeys(){return [
 ['can_access','访问'],['quote_create','新报价'],['quote_edit','保存/编辑'],['quote_delete','删除报价'],['history_view','历史'],['customer_view','看客户'],['customer_manage','改客户'],['product_view','看产品'],['product_manage','改产品'],['material_view','BOM物料'],['doc_settings_manage','报价资料'],['rate_manage','汇率设置'],['settings_manage','系统设置'],['permission_manage','权限'],['export_pdf_excel','导出'],['order_convert','转订单'],['log_view','看日志'],['log_manage','清日志']
]}
async function loadPermissions(){if(!hasPerm('permission_manage'))return;try{let d=await api('list_permission_users');PERM_USERS=d.users||[];renderPermissions();}catch(e){if(String(e.message)!=='AUTH_REQUIRED')$('permissionTable').innerHTML='<div class="hint" style="padding:16px">读取权限失败：'+esc(e.message)+'</div>';}}
function renderPermissions(){
  let q=($('permSearch')?.value||'').toLowerCase(), rf=$('permRoleFilter')?.value||'';
  let users=(PERM_USERS||[]).filter(u=>{let txt=[u.username,u.display_name,u.role,u.user_table,(u.sources||[]).join(' ')].join(' ').toLowerCase();if(q && !txt.includes(q))return false;if(rf==='admin'&&!u.is_admin)return false;if(rf==='staff'&&u.is_admin)return false;return true;});
  if($('permCount'))$('permCount').textContent='共 '+users.length+' / '+(PERM_USERS||[]).length+' 个账号（邮箱账号已过滤，已按账号名去重）';
  let keys=permKeys();
  let html='<table class="perm-table"><thead><tr><th>账号</th>'+keys.map(k=>'<th>'+k[1]+'</th>').join('')+'<th>操作</th></tr></thead><tbody>';
  html+=users.map((u)=>{
    let idx=(PERM_USERS||[]).indexOf(u);
    let p=u.permissions||{};
    let disabled=u.is_admin?' disabled title="管理员默认全权限"':'';
    let checks=keys.map(k=>'<td><input type="checkbox" data-pi="'+idx+'" data-key="'+k[0]+'" '+(p[k[0]]?'checked':'')+disabled+'></td>').join('');
    let sourceText=esc(u.user_table||'')+(Number(u.source_count||1)>1?' ｜ 合并'+Number(u.source_count||1)+'个来源':'');
    return '<tr class="'+(u.is_admin?'perm-row-admin':'')+'"><td><div class="perm-user-main">'+esc(u.display_name||u.username)+'</div><div class="perm-user-sub">'+esc(u.username)+' ｜ '+esc(u.role||'')+' ｜ '+sourceText+(u.saved?' ｜ 已设置':' ｜ 默认')+'</div></td>'+checks+'<td><button class="blue perm-save-btn" onclick="savePermRow('+idx+')" '+(u.is_admin?'disabled':'')+'>保存</button> <button class="gray perm-reset-btn" onclick="resetPermRow('+idx+')" '+(u.is_admin?'disabled':'')+'>默认</button> <button class="red perm-delete-btn" onclick="deletePermRow('+idx+')">删除</button></td></tr>'
  }).join('');
  html+='</tbody></table>';
  if($('permissionTable'))$('permissionTable').innerHTML=html;
}
async function savePermRow(i){let u=PERM_USERS[i];if(!u)return;let payload={user_table:u.user_table,user_id:u.user_id,username:u.username,display_name:u.display_name,role:u.role};document.querySelectorAll('input[data-pi="'+i+'"]').forEach(ch=>payload[ch.dataset.key]=ch.checked?1:0);let d=await api('save_user_permission',payload);PERM_USERS=d.users||PERM_USERS;renderPermissions();alert('权限已保存：'+(u.display_name||u.username));}
async function resetPermRow(i){let u=PERM_USERS[i];if(!u||!confirm('确定恢复默认权限？\n'+u.username))return;let d=await api('reset_user_permission',{user_table:u.user_table,user_id:u.user_id,username:u.username});PERM_USERS=d.users||PERM_USERS;renderPermissions();}
async function deletePermRow(i){
  let u=PERM_USERS[i]; if(!u)return;
  if(AUTH.user && String(AUTH.user.username||'').toLowerCase()===String(u.username||'').toLowerCase()){alert('不能删除当前登录账号');return;}
  if(!confirm('确定从报价权限页删除/隐藏这个账号？\n\n'+(u.display_name||u.username)+'\n'+u.username+' ｜ '+(u.user_table||'')+'\n\n注意：这里只清理报价权限页，不会删除 PLM/CRM/BOM 原始账号。'))return;
  let d=await api('delete_permission_user',{user_table:u.user_table,user_id:u.user_id,username:u.username,display_name:u.display_name,role:u.role});
  PERM_USERS=d.users||[];
  renderPermissions();
}




const DOC_PL_LABELS={carton_no:'Carton No.',customer_code:'Customer Code',model:'Model',description:'Description',qty:'Qty',pcs_per_ctn:'PCS/CTN',cartons:'CTNS',carton_size:'Carton Size',nw:'N.W.',gw:'G.W.',cbm:'CBM',remark:'Remark'};
const DOC_CI_LABELS={customer_code:'Customer Code',model:'Model',description:'Description',qty:'Qty',unit_price:'Unit Price',amount:'Amount',hs_code:'HS Code',origin:'Country of Origin'};
function defaultDocSettings(){return {seller_name:'Artdon Lighting Limited',seller_text:'Artdon Lighting Limited\nZhongshan, Guangdong, China',buyer_label:'Buyer / Consignee',notify_party:'',signature_company:'Artdon Lighting Limited',footer_note:'All information is generated from the confirmed shipment batch. Packing List and Commercial Invoice use the same shipment quantity.',country_origin:'China',port_loading:'Zhongshan',ship_method:'',pl_prefix:'PL',ci_prefix:'CI',shipment_prefix:'SHP',date_format:'ymd',show_bank_on_ci:1,show_notify_party:0,pl_columns:{carton_no:1,customer_code:1,model:1,description:1,qty:1,pcs_per_ctn:1,cartons:1,carton_size:1,nw:1,gw:1,cbm:1,remark:0},ci_columns:{customer_code:1,model:1,description:1,qty:1,unit_price:1,amount:1,hs_code:0,origin:0}}}
function renderDocColumnChecks(){let pl=$('docPlColumns'),ci=$('docCiColumns');if(pl)pl.innerHTML=Object.entries(DOC_PL_LABELS).map(([k,v])=>`<label><input type="checkbox" data-doc-pl="${k}"> ${v}</label>`).join('');if(ci)ci.innerHTML=Object.entries(DOC_CI_LABELS).map(([k,v])=>`<label><input type="checkbox" data-doc-ci="${k}"> ${v}</label>`).join('')}
function fillDocumentSettingsForm(d){d=Object.assign(defaultDocSettings(),d||{});DOC_SETTINGS=d;renderDocColumnChecks();if($('docSellerName'))$('docSellerName').value=d.seller_name||'';if($('docSellerText'))$('docSellerText').value=d.seller_text||'';if($('docBuyerLabel'))$('docBuyerLabel').value=d.buyer_label||'Buyer / Consignee';if($('docNotifyParty'))$('docNotifyParty').value=d.notify_party||'';if($('docSignatureCompany'))$('docSignatureCompany').value=d.signature_company||'Artdon Lighting Limited';if($('docFooterNote'))$('docFooterNote').value=d.footer_note||'';if($('docOrigin'))$('docOrigin').value=d.country_origin||'China';if($('docPortLoading'))$('docPortLoading').value=d.port_loading||'Zhongshan';if($('docShipmentPrefix'))$('docShipmentPrefix').value=d.shipment_prefix||'SHP';if($('docPlPrefix'))$('docPlPrefix').value=d.pl_prefix||'PL';if($('docCiPrefix'))$('docCiPrefix').value=d.ci_prefix||'CI';if($('docDateFormat'))$('docDateFormat').value=d.date_format||'ymd';if($('docShowBankCi'))$('docShowBankCi').checked=!!Number(d.show_bank_on_ci??1);if($('docShowNotify'))$('docShowNotify').checked=!!Number(d.show_notify_party||0);document.querySelectorAll('[data-doc-pl]').forEach(x=>x.checked=!!Number((d.pl_columns||{})[x.dataset.docPl]??1));document.querySelectorAll('[data-doc-ci]').forEach(x=>x.checked=!!Number((d.ci_columns||{})[x.dataset.docCi]??1));}
function collectDocumentSettings(){let d=defaultDocSettings();d.seller_name=$('docSellerName')?.value||d.seller_name;d.seller_text=$('docSellerText')?.value||d.seller_text;d.buyer_label=$('docBuyerLabel')?.value||d.buyer_label;d.notify_party=$('docNotifyParty')?.value||'';d.signature_company=$('docSignatureCompany')?.value||d.signature_company;d.footer_note=$('docFooterNote')?.value||d.footer_note;d.country_origin=$('docOrigin')?.value||d.country_origin;d.port_loading=$('docPortLoading')?.value||d.port_loading;d.shipment_prefix=$('docShipmentPrefix')?.value||d.shipment_prefix;d.pl_prefix=$('docPlPrefix')?.value||d.pl_prefix;d.ci_prefix=$('docCiPrefix')?.value||d.ci_prefix;d.date_format=$('docDateFormat')?.value||'ymd';d.show_bank_on_ci=$('docShowBankCi')?.checked?1:0;d.show_notify_party=$('docShowNotify')?.checked?1:0;d.pl_columns={};document.querySelectorAll('[data-doc-pl]').forEach(x=>d.pl_columns[x.dataset.docPl]=x.checked?1:0);d.ci_columns={};document.querySelectorAll('[data-doc-ci]').forEach(x=>d.ci_columns[x.dataset.docCi]=x.checked?1:0);return d;}
async function loadDocumentSettings(){try{let r=await orderApi('get_document_settings');fillDocumentSettingsForm(r.settings||{});return DOC_SETTINGS}catch(e){fillDocumentSettingsForm(defaultDocSettings());return DOC_SETTINGS}}
async function saveDocumentSettings(){if(!hasPerm('settings_manage')){alert('当前账号没有权限：settings_manage');return;}let d=collectDocumentSettings();let r=await orderApi('save_document_settings',d);fillDocumentSettingsForm(r.settings||d);alert('单证模板已保存');}
async function orderApi(action,data){let url='quote_order_api.php?action='+encodeURIComponent(action)+'&_t='+Date.now();let r=await fetch(url,{method:data?'POST':'GET',credentials:'same-origin',cache:'no-store',headers:{'Content-Type':'application/json','Cache-Control':'no-cache'},body:data?JSON.stringify(data):null});let txt=await r.text();let j;try{j=JSON.parse(txt)}catch(e){if(r.status===401){quoteSsoRedirect();throw new Error('AUTH_REDIRECT')}throw new Error('订单接口不是JSON：'+txt.slice(0,500))}if(r.status===401||j.auth_required||j.login_required||j.need_login){quoteSsoRedirect();throw new Error('AUTH_REDIRECT')}if(!j.ok)throw new Error(j.msg||j.error||'订单接口错误');return j.data||{};}
async function clearQuoteOrderTestData(){
  if(!hasPerm('settings_manage')){alert('当前账号没有权限：settings_manage；清空报价/订单属于高危操作。');return;}
  let quoteCount=(DB.quotes||[]).length, orderCount=(DB.orders||[]).length;
  let msg='此操作会永久清空：\n1. 所有历史报价\n2. 所有订单\n3. 订单明细、收款记录、出货批次、PL/CI记录\n\n不会删除客户库、产品库、BOM物料、公司抬头、银行、条款模板、报价等级。\n\n当前前端看到：报价 '+quoteCount+' 份，订单 '+orderCount+' 个。\n\n请输入 CLEAR_TEST_DATA 确认：';
  let confirmText=prompt(msg,'');
  if(confirmText!=='CLEAR_TEST_DATA')return;
  if(!confirm('最后确认：真的清空所有报价和订单测试数据？\n此操作不能撤回，建议已先做备份。'))return;
  try{
    let r=await orderApi('clear_quote_order_test_data',{confirm:'CLEAR_TEST_DATA'});
    CURRENT_ORDER=null;CURRENT_ORDER_ITEMS=[];CURRENT_ORDER_SHIPMENTS=[];CURRENT_ORDER_PAYMENTS=[];CURRENT_ORDER_PAYMENT_SUMMARY={};DOCUMENTS=[];
    await refreshQuoteRuntime('clear_quote_order_test_data',{orders:true,documents:true});
    if($('orderDetail'))$('orderDetail').className='order-detail-empty',$('orderDetail').innerHTML='已清空报价和订单测试数据';
    alert('已清空完成。\n历史报价：'+(r.deleted&&r.deleted.quote_orders!==undefined?r.deleted.quote_orders:0)+' 份\n订单：'+(r.deleted&&r.deleted.quote_sales_orders!==undefined?r.deleted.quote_sales_orders:0)+' 个\n订单明细/出货/收款也已同步清空。');
  }catch(e){alert('清空失败：'+e.message);}
}
function orderCustomer(o){try{return JSON.parse(o.customer_json||'{}')}catch(e){return {}}}
function orderItems(o){try{let arr=JSON.parse(o.items_json||'[]');return Array.isArray(arr)?arr:[]}catch(e){return []}}
function orderText(o){let c=orderCustomer(o),items=orderItems(o);return [o.order_no,o.quote_no,o.customer_name,c.company,c.contact,c.country,o.currency,o.amount,o.status,items.map(it=>[it.product?.code,it.product?.name,it.customer_code,it.color,it.qty].join(' ')).join(' ')].join(' ').toLowerCase()}

function quoteOrderExtraButtons(id, compact=false){
  id=Number(id||0);
  if(!id) return '';
  let html=`<button class="blue" onclick="event.stopPropagation();exportOrderPDF(${id})">PDF</button><button class="gray" onclick="event.stopPropagation();exportOrderExcel(${id})">Excel</button>`;
  if(hasPerm('settings_manage')) html+=`<button class="gray" onclick="event.stopPropagation();quoteVoidSalesOrder(${id})">作废${compact?'':'订单'}</button><button class="red" onclick="event.stopPropagation();quoteDeleteTestSalesOrder(${id})">删除${compact?'':'测试订单'}</button>`;
  return html;
}
async function quoteVoidSalesOrder(id){
  id=Number(id||0); if(!id){alert('订单ID无效');return;}
  let o=(DB.orders||[]).find(x=>Number(x.id)===id)||{};
  let reason=prompt('请输入作废原因：\n\n订单：'+(o.order_no||id)+'\n说明：作废会保留记录，但订单/出货批次/PL/CI 不再按正常订单处理。','手动作废订单');
  if(reason===null) return;
  if(!confirm('确认作废这个订单？\n\n'+(quoteOrderNoAtV68522(o.order_no,o.quote_no)||id)+'\n\n作废后：关联出货批次、PL、CI 会同步标记作废；原报价可重新转订单。')) return;
  try{
    let r=await api('void_sales_order',{id,reason});
    await refreshQuoteRuntime('void_sales_order',{orders:true,documents:true});
    if($('orderDetail')) $('orderDetail').className='order-detail-empty', $('orderDetail').innerHTML='订单已作废：'+esc(r.order_no||id)+'<br>归档文件：'+esc(r.archive_file||'');
    alert(r.message||'订单已作废');
  }catch(e){alert('作废失败：'+e.message)}
}
async function quoteDeleteTestSalesOrder(id){
  id=Number(id||0); if(!id){alert('订单ID无效');return;}
  let o=(DB.orders||[]).find(x=>Number(x.id)===id)||{};
  let reason=prompt('请输入删除原因：\n\n订单：'+(o.order_no||id)+'\n说明：只用于误建/测试订单，会先归档备份，再删除订单、出货批次、PL/CI、收款和明细。','删除测试订单');
  if(reason===null) return;
  let confirmText=prompt('高危确认：彻底删除测试订单\n\n订单：'+(quoteOrderNoAtV68522(o.order_no,o.quote_no)||id)+'\n客户：'+(o.customer_name||'')+'\n\n请输入 DELETE_ORDER 确认：','');
  if(confirmText!=='DELETE_ORDER') return;
  if(!confirm('最后确认：真的彻底删除这个测试订单及关联 PL/CI/出货/收款？\n\n删除前会自动生成归档 JSON。')) return;
  try{
    let r=await api('delete_test_order',{id,reason,confirm:'DELETE_ORDER'});
    DB.orders=(DB.orders||[]).filter(x=>Number(x.id)!==id);
    renderOrders();
    await refreshQuoteRuntime('delete_test_order',{orders:true,documents:true});
    if($('orderDetail')) $('orderDetail').className='order-detail-empty', $('orderDetail').innerHTML='测试订单已删除。<br>归档文件：'+esc(r.archive_file||'');
    alert((r.message||'测试订单已删除')+'\n归档文件：'+(r.archive_file||''));
  }catch(e){alert('删除失败：'+e.message)}
}

async function loadOrders(){if(!hasPerm('order_convert'))return;try{if($('orderList'))$('orderList').innerHTML='<div class="order-detail-empty">正在读取订单摘要...</div>';let t0=Date.now();let d=await orderApi('list');DB.orders=d.orders||[];DASH_ORDERS_LOADED=true;renderOrders();renderDash();if($('orderCount'))$('orderCount').textContent=($('orderCount').textContent||'')+' ｜ '+(Date.now()-t0)+'ms';}catch(e){if($('orderList'))$('orderList').innerHTML='<div class="order-detail-empty">读取订单失败：'+esc(e.message)+'</div>';}}
function renderOrders(){let kw=($('orderSearch')?.value||'').toLowerCase(), st=$('orderStatus')?.value||'', sort=$('orderSort')?.value||'new';let arr=(DB.orders||[]).filter(o=>(!kw||orderText(o).includes(kw))&&(!st||String(o.status||'')===st));arr.sort((a,b)=>{if(sort==='amountDesc')return Number(b.amount||0)-Number(a.amount||0);if(sort==='amountAsc')return Number(a.amount||0)-Number(b.amount||0);if(sort==='customer')return String(a.customer_name||'').localeCompare(String(b.customer_name||''),'zh');return String(b.created_at||'').localeCompare(String(a.created_at||''));});if($('orderCount'))$('orderCount').textContent='共 '+arr.length+' / '+(DB.orders||[]).length+' 个订单';if(!$('orderList'))return;$('orderList').innerHTML=orderFinanceStrip(arr)+(arr.length?arr.map(o=>{let cls=/已作废|取消/.test(o.status||'')?'voided':(/已完成|已出货/.test(o.status||'')?'done':(/待出货|部分出货|生产中/.test(o.status||'')?'warn':''));let pst=o.payment_status||'未收款';return `<div class="order-card" onclick="viewOrder(${Number(o.id)})"><div><b>${esc(quoteOrderNoAtV68522(o.order_no,o.quote_no)||'')}</b> <span class="order-status ${cls}">${esc(o.status||'待确认')}</span><small>来源报价：${esc(o.quote_no||'')} ｜ 客户：${esc(o.customer_name||'未选客户')} ｜ ${esc(o.order_date||'')} ｜ ${esc(o.currency||'USD')} ${money(o.amount)}</small><small>数量 ${Number(o.qty||0)} PCS ｜ 出货：${esc(o.shipment_status||'未出货')} ｜ 收款：${esc(pst)} ｜ 未收 ${esc(o.currency||'USD')} ${money(o.balance_amount)}</small></div><div class="order-list-actions"><button class="gray" onclick="event.stopPropagation();viewOrder(${Number(o.id)})">详情</button></div></div>`}).join(''):'<div class="order-detail-empty">暂无订单。先在报价单页面点“一键转订单”。</div>');}
async function viewOrder(id){try{let d=await orderApi('detail',{id});renderOrderDetail(d.order,d.items||[]);document.querySelectorAll('.order-card').forEach(x=>x.classList.remove('active'));}catch(e){alert(e.message)}}
function renderOrderDetail(o,items){if(!o||!$('orderDetail'))return;let c=orderCustomer(o);let rows=(items||[]).map((it,i)=>{let item={};try{item=JSON.parse(it.item_json||'{}')}catch(e){}let img=it.image||item.product?.image||'';return `<tr><td>${img?`<img class="order-item-img" src="${img}">`:''}</td><td>${i+1}</td><td>${esc(it.customer_code||item.customer_code||'')}</td><td>${esc(it.product_code||item.product?.code||'')}</td><td>${esc(it.product_name||item.product?.name||'')}</td><td>${esc(it.color||item.color||'')}</td><td>${Number(it.qty||0)}</td><td>${money(it.unit_price)}</td><td>${money(it.amount)}</td><td>${Number(it.shipped_qty||0)}</td></tr>`}).join('');$('orderDetail').className='card';$('orderDetail').innerHTML=`<div class="card-head"><div><b>${esc(quoteOrderNoAtV68522(o.order_no,o.quote_no)||'')}</b><div class="hint">来源报价：${esc(o.quote_no||'')} ｜ 创建：${esc(o.created_at||'')}</div></div><div class="btns" style="margin-top:0"><button class="gray" onclick="showPage('quote');">返回报价</button><button class="blue" onclick="alert('下一步开发：出货批次 + Packing List + Commercial Invoice')">生成单证</button></div></div><div class="card-body"><div class="order-kv"><div><b>客户</b><span>${esc(o.customer_name||c.company||'')}</span></div><div><b>订单状态</b><span>${esc(o.status||'待确认')}</span></div><div><b>订单金额</b><span>${esc(o.currency||'USD')} ${money(o.amount)}</span></div><div><b>数量</b><span>${Number(o.qty||0)} PCS</span></div><div><b>报价日期</b><span>${esc(o.quote_date||'')}</span></div><div><b>订单日期</b><span>${esc(o.order_date||'')}</span></div><div><b>出货状态</b><span>${esc(o.shipment_status||'未出货')}</span></div><div><b>付款状态</b><span>${esc(o.payment_status||'未收款')}</span></div></div><div class="order-detail-actions"><button class="blue" onclick="updateOrderStatus(${Number(o.id)},'已确认')">确认订单</button><button class="gray" onclick="updateOrderStatus(${Number(o.id)},'生产中')">生产中</button><button class="gray" onclick="updateOrderStatus(${Number(o.id)},'待出货')">待出货</button><button class="green" onclick="updateOrderStatus(${Number(o.id)},'已完成')">完成</button><button class="red" onclick="updateOrderStatus(${Number(o.id)},'取消')">取消</button>${quoteOrderExtraButtons(o.id,false)}</div><table class="table"><tr><th>图</th><th>#</th><th>Customer Code</th><th>型号</th><th>产品</th><th>颜色</th><th>订单数量</th><th>单价</th><th>金额</th><th>已出货</th></tr>${rows||'<tr><td colspan="10">无产品明细</td></tr>'}</table><div class="order-note" style="margin-top:12px"><b>订单快照：</b> 订单已独立保存产品、价格、客户、Specification JSON。后续修改报价单，不会影响这个订单。下一步会在这里继续加出货批次、Packing List 和 CI。</div></div>`;}
async function updateOrderStatus(id,status){
  if(!id){alert('订单ID无效，请刷新订单中心后再试');return;}
  if(!confirm('确定把订单状态改为：'+status+'？'))return;
  try{
    let r=await orderApi('update_status',{id:Number(id),status});
    let updated=r.order||{id:Number(id),status:r.status||status};
    DB.orders=(DB.orders||[]).map(o=>Number(o.id)===Number(id)?Object.assign({},o,updated):o);
    if(CURRENT_ORDER&&Number(CURRENT_ORDER.id)===Number(id))CURRENT_ORDER=Object.assign({},CURRENT_ORDER,updated);
    renderOrders();
    if(CURRENT_ORDER&&Number(CURRENT_ORDER.id)===Number(id))renderOrderDetail(CURRENT_ORDER,CURRENT_ORDER_ITEMS||[],CURRENT_ORDER_SHIPMENTS||[]);
    await refreshQuoteRuntime('update_order_status',{orders:true,documents:true});
    await viewOrder(id);
    alert('订单状态已改为：'+(updated.status||status));
  }catch(e){alert('修改订单状态失败：'+e.message);}
}


let CURRENT_ORDER=null, CURRENT_ORDER_ITEMS=[], CURRENT_ORDER_SHIPMENTS=[], PACKAGING=[], SHIPMENT_PREP=null, packTimer=null;
function debouncedLoadPackaging(){clearTimeout(packTimer);packTimer=setTimeout(loadPackaging,260)}
function num(v){v=String(v??'').replace(/,/g,'').trim();let n=Number(v);return isFinite(n)?n:0}
function fmtNum(v,d=2){let n=num(v);return n.toFixed(d).replace(/\.0+$/,'').replace(/(\.\d*?)0+$/,'$1')}
function dimCbm(size,ctns=1){size=String(size||'').toLowerCase().replace(/×/g,'*').replace(/x/g,'*').replace(/cm/g,'').replace(/\s/g,'');let a=size.split('*').map(num).filter(x=>x>0);return a.length>=3?Number((a[0]*a[1]*a[2]*num(ctns)/1000000).toFixed(4)):0}
async function loadPackaging(){if(!hasPerm('order_convert'))return;try{let d=await orderApi('packaging_list',{kw:$('packSearch')?.value||''});PACKAGING=d.profiles||[];renderPackaging();}catch(e){if($('packagingList'))$('packagingList').innerHTML='<div class="order-detail-empty">读取包装资料失败：'+esc(e.message)+'</div>';}}
function renderPackaging(){let box=$('packagingList');if(!box)return;box.innerHTML=(PACKAGING||[]).length?(PACKAGING||[]).map(p=>`<div class="pack-profile-card" onclick="editPackaging(${Number(p.id)})"><b>${esc(p.product_code||'')}</b> ${p.customer_code?'<span class="badge local">'+esc(p.customer_code)+'</span>':''}<small>${esc(p.product_name||'')} ｜ PCS/CTN ${fmtNum(p.pcs_per_ctn)} ｜ ${esc(p.carton_size||'')} ｜ N.W. ${fmtNum(p.carton_nw,3)}KG ｜ G.W. ${fmtNum(p.carton_gw,3)}KG ｜ CBM ${fmtNum(p.carton_cbm,4)}</small><small>${esc(p.packing_method||'')}</small></div>`).join(''):'<div class="order-detail-empty">暂无包装资料，右侧新建。</div>';}
function clearPackagingForm(){['packId','packProductCode','packProductName','packCustomerCode','packUnitNw','packUnitGw','packPcsCtn','packL','packW','packH','packSize','packCtnNw','packCtnGw','packCbm','packMethod','packNote'].forEach(id=>{if($(id))$(id).value=''})}
function editPackaging(id){let p=(PACKAGING||[]).find(x=>Number(x.id)===Number(id));if(!p)return;$('packId').value=p.id||'';$('packProductCode').value=p.product_code||'';$('packProductName').value=p.product_name||'';$('packCustomerCode').value=p.customer_code||'';$('packUnitNw').value=fmtNum(p.unit_nw,3);$('packUnitGw').value=fmtNum(p.unit_gw,3);$('packPcsCtn').value=fmtNum(p.pcs_per_ctn);$('packL').value=fmtNum(p.carton_l);$('packW').value=fmtNum(p.carton_w);$('packH').value=fmtNum(p.carton_h);$('packSize').value=p.carton_size||'';$('packCtnNw').value=fmtNum(p.carton_nw,3);$('packCtnGw').value=fmtNum(p.carton_gw,3);$('packCbm').value=fmtNum(p.carton_cbm,4);$('packMethod').value=p.packing_method||'';$('packNote').value=p.note||'';}
function packAutoSize(){let l=$('packL')?.value,w=$('packW')?.value,h=$('packH')?.value;if(num(l)>0&&num(w)>0&&num(h)>0){$('packSize').value=fmtNum(l)+'*'+fmtNum(w)+'*'+fmtNum(h)+'cm';packAutoCbm();}}
function packAutoCbm(){let c=dimCbm($('packSize')?.value,1);if(c>0 && $('packCbm'))$('packCbm').value=fmtNum(c,4)}
async function savePackaging(){let d={id:$('packId').value,product_code:$('packProductCode').value,product_name:$('packProductName').value,customer_code:$('packCustomerCode').value,unit_nw:$('packUnitNw').value,unit_gw:$('packUnitGw').value,pcs_per_ctn:$('packPcsCtn').value,carton_l:$('packL').value,carton_w:$('packW').value,carton_h:$('packH').value,carton_size:$('packSize').value,carton_nw:$('packCtnNw').value,carton_gw:$('packCtnGw').value,carton_cbm:$('packCbm').value,packing_method:$('packMethod').value,note:$('packNote').value};if(!d.product_code){alert('请填写产品型号');return;}let r=await orderApi('save_packaging',d);PACKAGING=r.profiles||[];renderPackaging();if(r.profile)editPackaging(r.profile.id);alert('包装资料已保存');}
async function deletePackaging(){let id=$('packId')?.value;if(!id)return;if(!confirm('确定删除这条包装资料？'))return;let r=await orderApi('delete_packaging',{id});PACKAGING=r.profiles||[];clearPackagingForm();renderPackaging();}

function paymentCls(status){status=String(status||'');if(/已收齐|超收/.test(status))return 'payment-status-paid';if(/尾款|订金/.test(status))return 'payment-status-warn';return 'payment-status-danger'}
function paymentSummaryHtml(o,summary,payments){summary=summary||{};payments=payments||[];let cur=summary.currency||o.currency||'USD';let paid=num(summary.paid_amount),bal=num(summary.balance_amount),amount=num(summary.order_amount||o.amount);let status=summary.payment_status||o.payment_status||'未收款';let rows=payments.map(p=>`<tr><td>${esc(p.payment_date||'')}</td><td>${esc(p.payment_type||'')}</td><td>${esc(p.currency||cur)} ${money(p.amount)}</td><td>${esc(p.method||'')}</td><td>${esc(p.bank_ref||'')}</td><td>${esc(p.note||'')}</td><td><button class="red" onclick="deleteOrderPayment(${Number(p.id)},${Number(o.id)})">删除</button></td></tr>`).join('');return `<div class="payment-panel"><div class="payment-panel-head"><div><b>收款记录 / Payment</b><div class="hint">记录订金、尾款、其它款项；系统自动计算尾款和付款状态。</div></div><button class="blue" onclick="openPaymentModal(${Number(o.id)})">新增收款</button></div><div class="payment-mini-grid"><div><b>订单金额</b><span>${esc(cur)} ${money(amount)}</span></div><div><b>已收款</b><span class="payment-status-paid">${esc(cur)} ${money(paid)}</span></div><div><b>未收款</b><span class="${bal>0?'payment-status-warn':'payment-status-paid'}">${esc(cur)} ${money(bal)}</span></div><div><b>付款状态</b><span class="${paymentCls(status)}">${esc(status)}</span></div></div>${bal>0?'<div class="payment-alert">如条款包含 before shipment，出货前会提醒尾款未收齐；老板/管理员可确认后强制继续。</div>':''}<div class="payment-list"><table><tr><th>日期</th><th>类型</th><th>金额</th><th>方式</th><th>水单/参考号</th><th>备注</th><th>操作</th></tr>${rows||'<tr><td colspan="7">暂无收款记录</td></tr>'}</table></div></div>`}
function orderCurrencyKeyV68523(o){let c=String(o?.currency||'USD').trim().toUpperCase();if(c==='CNY'||c==='RMB¥'||c==='￥')return 'RMB';if(c==='US$'||c==='$')return 'USD';return c||'USD'}
function orderCurrencyMapsV68523(){return {total:{},paid:{},balance:{},tail:{}}}
function orderAddCurrencyAmountV68523(map,cur,amt){cur=String(cur||'USD').toUpperCase();amt=num(amt);if(!map[cur])map[cur]=0;map[cur]+=amt}
function orderBalanceAmountV68523(o){if(o&&o.balance_amount!==undefined&&o.balance_amount!==null&&String(o.balance_amount)!=='')return num(o.balance_amount);return Math.max(0,num(o?.amount)-num(o?.paid_amount))}
function orderCurrencyLineV68523(map,currencyOrder){let keys=[...(currencyOrder||[]),...Object.keys(map||{})].map(x=>String(x||'').toUpperCase()).filter(Boolean);keys=[...new Set(keys)];if(!keys.length)keys=['USD','RMB'];return keys.map(k=>`${esc(k)} ${money(map[k]||0)}`).join(' ｜ ')}
function orderFinanceStrip(orders){orders=orders||[];let m=orderCurrencyMapsV68523(),curOrder=[];orders.forEach(o=>{let c=orderCurrencyKeyV68523(o);if(!curOrder.includes(c))curOrder.push(c);let bal=orderBalanceAmountV68523(o);orderAddCurrencyAmountV68523(m.total,c,o.amount);orderAddCurrencyAmountV68523(m.paid,c,o.paid_amount);orderAddCurrencyAmountV68523(m.balance,c,bal);if(String(o.payment_status||'')==='尾款待收')orderAddCurrencyAmountV68523(m.tail,c,bal)});if(!curOrder.length)curOrder=['USD','RMB'];curOrder=[...new Set(['USD','RMB',...curOrder])].filter(k=>k==='USD'||k==='RMB'||(m.total[k]||m.paid[k]||m.balance[k]||m.tail[k]));return `<div class="finance-strip currency-split-v68523"><span>订单总额 <b>${orderCurrencyLineV68523(m.total,curOrder)}</b></span><span>已收款 <b>${orderCurrencyLineV68523(m.paid,curOrder)}</b></span><span>未收款 <b>${orderCurrencyLineV68523(m.balance,curOrder)}</b></span><span>尾款待收 <b>${orderCurrencyLineV68523(m.tail,curOrder)}</b></span></div>`}
function openPaymentModal(orderId){let o=CURRENT_ORDER&&Number(CURRENT_ORDER.id)===Number(orderId)?CURRENT_ORDER:(DB.orders||[]).find(x=>Number(x.id)===Number(orderId));if(!o){alert('请先选择订单');return;}$('payOrderId').value=orderId;$('payOrderInfo').textContent='订单：'+(quoteOrderNoAtV68522(o.order_no,o.quote_no)||'')+' ｜ 客户：'+(o.customer_name||'')+' ｜ 金额：'+(o.currency||'USD')+' '+money(o.amount);$('payType').value='订金';$('payDate').value=today();$('payAmount').value='';$('payCurrency').value=o.currency||'USD';$('payMethod').value='';$('payBankRef').value='';$('payNote').value='';$('paymentModal').classList.add('show');}
function closePaymentModal(){$('paymentModal')?.classList.remove('show')}
async function saveOrderPayment(){let orderId=Number($('payOrderId')?.value||0);let amount=num($('payAmount')?.value);if(!orderId){alert('缺少订单ID');return;}if(amount<=0){alert('请填写收款金额');return;}try{let r=await orderApi('save_payment',{order_id:orderId,payment_type:$('payType').value,payment_date:$('payDate').value,amount:amount,currency:$('payCurrency').value,method:$('payMethod').value,bank_ref:$('payBankRef').value,note:$('payNote').value});closePaymentModal();await refreshQuoteRuntime('save_order_payment',{orders:true,documents:true});await viewOrder(orderId);alert('收款已保存，付款状态：'+(r.payment_status||''));}catch(e){alert('保存收款失败：'+e.message)}}
async function deleteOrderPayment(id,orderId){if(!confirm('确定删除这条收款记录？'))return;try{await orderApi('delete_payment',{id});await refreshQuoteRuntime('delete_order_payment',{orders:true,documents:true});await viewOrder(orderId);}catch(e){alert('删除失败：'+e.message)}}

async function viewOrder(id){try{let d=await orderApi('detail',{id});CURRENT_ORDER=d.order;CURRENT_ORDER_ITEMS=d.items||[];CURRENT_ORDER_SHIPMENTS=d.shipments||[];CURRENT_ORDER_PAYMENTS=d.payments||[];CURRENT_ORDER_PAYMENT_SUMMARY=d.payment_summary||{};renderOrderDetail(d.order,d.items||[],d.shipments||[],d.payments||[],d.payment_summary||{});document.querySelectorAll('.order-card').forEach(x=>x.classList.remove('active'));}catch(e){alert(e.message)}}
function renderOrderDetail(o,items,shipments,payments,paymentSummary){if(!o||!$('orderDetail'))return;let c=orderCustomer(o);payments=payments||CURRENT_ORDER_PAYMENTS||[];paymentSummary=paymentSummary||CURRENT_ORDER_PAYMENT_SUMMARY||{};let rows=(items||[]).map((it,i)=>{let item={};try{item=JSON.parse(it.item_json||'{}')}catch(e){}let img=it.image||item.product?.image||'';let remain=Math.max(0,num(it.qty)-num(it.shipped_qty));return `<tr><td>${img?`<img class="order-item-img" src="${img}">`:''}</td><td>${i+1}</td><td>${esc(it.customer_code||item.customer_code||'')}</td><td>${esc(it.product_code||item.product?.code||'')}</td><td>${esc(it.product_name||item.product?.name||'')}</td><td>${esc(it.color||item.color||'')}</td><td>${fmtNum(it.qty)}</td><td>${money(it.unit_price)}</td><td>${money(it.amount)}</td><td>${fmtNum(it.shipped_qty)}</td><td>${fmtNum(remain)}</td><td><button class="gray" onclick="event.stopPropagation();prefillPackagingFromOrderItem(${Number(it.id)})">建包装</button></td></tr>`}).join('');let shipHtml=(shipments||[]).length?`<div class="ship-list">${shipments.map(sh=>`<div class="ship-card"><b>${esc(sh.shipment_no||'')}</b> <span class="order-status ${/完成|已/.test(sh.status||'')?'done':'warn'}">${esc(sh.status||'草稿')}</span><small>日期：${esc(sh.ship_date||'')} ｜ PL：${esc(sh.packing_list_no||'')} ｜ CI：${esc(sh.commercial_invoice_no||'')}</small><div class="ship-totals"><span>${fmtNum(sh.total_cartons)} CTNS</span><span>N.W. ${fmtNum(sh.total_nw,3)} KG</span><span>G.W. ${fmtNum(sh.total_gw,3)} KG</span><span>CBM ${fmtNum(sh.total_cbm,4)}</span></div><div class="btns"><button class="gray" onclick="viewShipmentDetail(${Number(sh.id)})">查看批次</button><button class="blue" onclick="openOrderDoc('pl',${Number(sh.id)})">Packing List</button><button class="green" onclick="openOrderDoc('ci',${Number(sh.id)})">Commercial Invoice</button></div><div id="shipmentDetail${Number(sh.id)}"></div></div>`).join('')}</div>`:'<div class="order-note">暂无出货批次。点击“新增出货批次”，按未出货数量生成。</div>';$('orderDetail').className='card';$('orderDetail').innerHTML=`<div class="card-head"><div><b>${esc(quoteOrderNoAtV68522(o.order_no,o.quote_no)||'')}</b><div class="hint">来源报价：${esc(o.quote_no||'')} ｜ 创建：${esc(o.created_at||'')}</div></div><div class="btns" style="margin-top:0"><button class="gray" onclick="showPage('quote');">返回报价</button><button class="blue" onclick="openShipmentModal(${Number(o.id)})">新增出货批次</button><button class="green" onclick="openPaymentModal(${Number(o.id)})">新增收款</button><button class="blue" onclick="exportOrderPDF(${Number(o.id)})">PDF</button><button class="gray" onclick="exportOrderExcel(${Number(o.id)})">Excel</button><button class="gray" onclick="showPage('packaging')">包装资料库</button></div></div><div class="card-body"><div class="order-kv"><div><b>客户</b><span>${esc(o.customer_name||c.company||'')}</span></div><div><b>订单状态</b><span>${esc(o.status||'待确认')}</span></div><div><b>订单金额</b><span>${esc(o.currency||'USD')} ${money(o.amount)}</span></div><div><b>数量</b><span>${fmtNum(o.qty)} PCS</span></div><div><b>报价日期</b><span>${esc(o.quote_date||'')}</span></div><div><b>订单日期</b><span>${esc(o.order_date||'')}</span></div><div><b>出货状态</b><span>${esc(o.shipment_status||'未出货')}</span></div><div><b>付款状态</b><span class="${paymentCls(o.payment_status)}">${esc(o.payment_status||'未收款')}</span></div></div><div class="order-detail-actions"><button class="blue" onclick="updateOrderStatus(${Number(o.id)},'已确认')">确认订单</button><button class="gray" onclick="updateOrderStatus(${Number(o.id)},'生产中')">生产中</button><button class="gray" onclick="updateOrderStatus(${Number(o.id)},'待出货')">待出货</button><button class="green" onclick="updateOrderStatus(${Number(o.id)},'已完成')">完成</button><button class="red" onclick="updateOrderStatus(${Number(o.id)},'取消')">取消</button>${quoteOrderExtraButtons(o.id,false)}</div>${paymentSummaryHtml(o,paymentSummary,payments)}<div class="order-table-wrap"><table class="table"><tr><th>图</th><th>#</th><th>Customer Code</th><th>型号</th><th>产品</th><th>颜色</th><th>订单数量</th><th>单价</th><th>金额</th><th>已出货</th><th>未出货</th><th>包装</th></tr>${rows||'<tr><td colspan="12">无产品明细</td></tr>'}</table></div><div class="order-subtitle">出货批次 / Shipments</div>${shipHtml}<div class="order-note" style="margin-top:12px"><b>规则：</b> PL / CI 会从同一个出货批次生成，数量保持一致；收款未齐时会在出货前提示。</div></div>`;}
function prefillPackagingFromOrderItem(id){let it=(CURRENT_ORDER_ITEMS||[]).find(x=>Number(x.id)===Number(id));if(!it)return;showPage('packaging');setTimeout(()=>{clearPackagingForm();$('packProductCode').value=it.product_code||'';$('packProductName').value=it.product_name||'';$('packCustomerCode').value=it.customer_code||'';$('packPcsCtn').focus();},80)}
async function openShipmentModal(orderId){try{SHIPMENT_PREP=await orderApi('prepare_shipment',{order_id:orderId});let o=SHIPMENT_PREP.order||{};let nums={};try{nums=await orderApi('next_doc_numbers',{order_id:orderId,ship_date:today()});if(nums.settings)DOC_SETTINGS=nums.settings}catch(e){}let ds=DOC_SETTINGS||{};let ps=SHIPMENT_PREP.payment_summary||{};$('shipmentModalHint').textContent='订单：'+(quoteOrderNoAtV68522(o.order_no,o.quote_no)||'')+' ｜ 客户：'+(o.customer_name||'')+' ｜ 收款：'+(ps.payment_status||o.payment_status||'未收款')+' ｜ 未收 '+(ps.currency||o.currency||'USD')+' '+money(ps.balance_amount||0)+' ｜ 未出货产品会显示在下方';$('shipNo').value=nums.shipment_no||'';$('shipDate').value=today();$('shipPlNo').value=nums.packing_list_no||('PL-'+String(quoteOrderNoAtV68522(o.order_no,o.quote_no)||'').replace(/^AT-/,''));$('shipCiNo').value=nums.commercial_invoice_no||('CI-'+String(quoteOrderNoAtV68522(o.order_no,o.quote_no)||'').replace(/^AT-/,''));$('shipMark').value=o.customer_name||'';$('shipMethod').value=ds.ship_method||'';$('shipPortLoad').value=ds.port_loading||'Zhongshan';$('shipPortDest').value='';$('cartonRows').innerHTML='';renderShipmentRows(SHIPMENT_PREP.items||[]);$('shipmentModal').classList.add('show');recalcShipmentTotals();}catch(e){alert(e.message)}}
function closeShipmentModal(){$('shipmentModal')?.classList.remove('show')}
function renderShipmentRows(items){let box=$('shipItemRows');if(!box)return;box.innerHTML=(items||[]).map((it,i)=>{let remain=num(it.remain_qty);let p=it.packaging_profile||{};let pcs=num(p.pcs_per_ctn)||'';let ctns=pcs?Math.ceil(remain/num(pcs)):'';let size=p.carton_size||'';let nw=num(p.carton_nw)?num(p.carton_nw)*num(ctns):(num(p.unit_nw)*remain);let gw=num(p.carton_gw)?num(p.carton_gw)*num(ctns):(num(p.unit_gw)*remain);let cbm=num(p.carton_cbm)?num(p.carton_cbm)*num(ctns):dimCbm(size,ctns);return `<tr data-order-item="${Number(it.id)}"><td>${esc(it.product_code||'')}</td><td>${esc(it.customer_code||'')}</td><td>${esc(it.product_name||'')}</td><td>${fmtNum(it.qty)}</td><td>${fmtNum(it.shipped_qty)}</td><td>${fmtNum(remain)}</td><td><input class="ship-qty" type="number" step="0.01" max="${remain}" value="${remain>0?fmtNum(remain):''}" oninput="recalcShipRow(this)"></td><td><input class="ship-pcs" type="number" step="0.01" value="${esc(pcs)}" oninput="recalcShipRow(this)"></td><td><input class="ship-ctns" type="number" step="0.01" value="${esc(ctns)}" oninput="recalcShipmentTotals()"></td><td><input class="ship-size" value="${esc(size)}" placeholder="45*35*28cm" oninput="recalcShipRow(this)"></td><td><input class="ship-nw" type="number" step="0.001" value="${nw?fmtNum(nw,3):''}" oninput="recalcShipmentTotals()"></td><td><input class="ship-gw" type="number" step="0.001" value="${gw?fmtNum(gw,3):''}" oninput="recalcShipmentTotals()"></td><td><input class="ship-cbm" type="number" step="0.0001" value="${cbm?fmtNum(cbm,4):''}" oninput="recalcShipmentTotals()"></td><td><input class="ship-note" placeholder="备注"></td></tr>`}).join('')||'<tr><td colspan="14">没有未出货产品</td></tr>';}
function recalcShipRow(el){let tr=el.closest('tr');if(!tr)return;let qty=num(tr.querySelector('.ship-qty')?.value), pcs=num(tr.querySelector('.ship-pcs')?.value);let ctns=pcs>0?Math.ceil(qty/pcs):num(tr.querySelector('.ship-ctns')?.value);if(ctns>0)tr.querySelector('.ship-ctns').value=fmtNum(ctns);let size=tr.querySelector('.ship-size')?.value||'';let cbm=dimCbm(size,ctns);if(cbm>0)tr.querySelector('.ship-cbm').value=fmtNum(cbm,4);recalcShipmentTotals()}
function addCartonRow(data={}){let div=document.createElement('div');div.className='carton-grid';div.innerHTML=`<div><label>箱号</label><input class="carton-no" value="${esc(data.carton_no||'')}"></div><div><label>箱号范围</label><input class="carton-range" value="${esc(data.carton_range||'')}"></div><div class="carton-wide"><label>拼箱产品/数量</label><input class="carton-items" placeholder="95.01012 10PCS + 95.01013 5PCS" value="${esc(data.items_text||'')}"></div><div><label>总数量</label><input class="carton-qty" type="number" step="0.01" value="${esc(data.qty||'')}" oninput="recalcShipmentTotals()"></div><div><label>箱规</label><input class="carton-size" placeholder="45*35*28cm" value="${esc(data.carton_size||'')}" oninput="recalcCartonRow(this)"></div><div><label>N.W.</label><input class="carton-nw" type="number" step="0.001" value="${esc(data.nw||'')}" oninput="recalcShipmentTotals()"></div><div><label>G.W.</label><input class="carton-gw" type="number" step="0.001" value="${esc(data.gw||'')}" oninput="recalcShipmentTotals()"></div><div><label>CBM</label><input class="carton-cbm" type="number" step="0.0001" value="${esc(data.cbm||'')}" oninput="recalcShipmentTotals()"></div><div><label>唛头/备注</label><input class="carton-note" value="${esc(data.note||'')}"></div><button class="red" onclick="this.closest('.carton-grid').remove();recalcShipmentTotals()">×</button>`;$('cartonRows').appendChild(div);recalcShipmentTotals();}
function recalcCartonRow(el){let row=el.closest('.carton-grid'),cbm=dimCbm(row.querySelector('.carton-size')?.value,1);if(cbm>0)row.querySelector('.carton-cbm').value=fmtNum(cbm,4);recalcShipmentTotals()}
function collectShipmentItems(){return Array.from(document.querySelectorAll('#shipItemRows tr[data-order-item]')).map(tr=>({order_item_id:Number(tr.dataset.orderItem),qty:num(tr.querySelector('.ship-qty')?.value),pcs_per_ctn:num(tr.querySelector('.ship-pcs')?.value),cartons:num(tr.querySelector('.ship-ctns')?.value),carton_size:tr.querySelector('.ship-size')?.value||'',nw:num(tr.querySelector('.ship-nw')?.value),gw:num(tr.querySelector('.ship-gw')?.value),cbm:num(tr.querySelector('.ship-cbm')?.value),mark:$('shipMark')?.value||'',note:tr.querySelector('.ship-note')?.value||''})).filter(x=>x.qty>0)}
function collectCartons(){return Array.from(document.querySelectorAll('#cartonRows .carton-grid')).map(row=>({carton_no:row.querySelector('.carton-no')?.value||'',carton_range:row.querySelector('.carton-range')?.value||'',items_text:row.querySelector('.carton-items')?.value||'',items:[{text:row.querySelector('.carton-items')?.value||''}],qty:num(row.querySelector('.carton-qty')?.value),carton_size:row.querySelector('.carton-size')?.value||'',nw:num(row.querySelector('.carton-nw')?.value),gw:num(row.querySelector('.carton-gw')?.value),cbm:num(row.querySelector('.carton-cbm')?.value),mark:$('shipMark')?.value||'',note:row.querySelector('.carton-note')?.value||'',carton_count:1})).filter(x=>x.carton_no||x.carton_range||x.items_text||x.qty||x.carton_size)}
function recalcShipmentTotals(){let cartons=collectCartons();let items=collectShipmentItems();let ctn=0,nw=0,gw=0,cbm=0;if(cartons.length){cartons.forEach(x=>{ctn+=1;nw+=num(x.nw);gw+=num(x.gw);cbm+=num(x.cbm)})}else{items.forEach(x=>{ctn+=num(x.cartons);nw+=num(x.nw);gw+=num(x.gw);cbm+=num(x.cbm)})}if($('shipSummary'))$('shipSummary').textContent=`合计：${fmtNum(ctn)} CTNS ｜ N.W. ${fmtNum(nw,3)} KG ｜ G.W. ${fmtNum(gw,3)} KG ｜ CBM ${fmtNum(cbm,4)}`;}
async function saveShipment(force=false){if(!SHIPMENT_PREP?.order){alert('没有订单数据');return;}let items=collectShipmentItems();if(!items.length){alert('请填写本次出货数量');return;}let payload={order_id:Number(SHIPMENT_PREP.order.id),shipment_no:$('shipNo').value,ship_date:$('shipDate').value,packing_list_no:$('shipPlNo').value,commercial_invoice_no:$('shipCiNo').value,shipping_mark:$('shipMark').value,ship_method:$('shipMethod').value,port_loading:$('shipPortLoad').value,port_destination:$('shipPortDest').value,country_origin:'China',items,cartons:collectCartons(),status:'草稿'};if(force)payload.force_shipment=1;try{let r=await orderApi('create_shipment',payload);alert('已保存出货批次：'+r.shipment_no);closeShipmentModal();await refreshQuoteRuntime('create_shipment',{orders:true,documents:true});await viewOrder(payload.order_id);}catch(e){let msg=e.message||'';if(!force && msg.includes('尾款未收齐')){if(confirm(msg+'\n\n确认仍然继续保存出货批次？'))return saveShipment(true);}alert('保存出货批次失败：'+msg)}}
async function viewShipmentDetail(id){try{let d=await orderApi('shipment_detail',{id});let box=$('shipmentDetail'+id);if(!box)return;let rows=(d.items||[]).map((it,i)=>`<tr><td>${i+1}</td><td>${esc(it.product_code||'')}</td><td>${esc(it.customer_code||'')}</td><td>${esc(it.product_name||'')}</td><td>${fmtNum(it.qty)}</td><td>${fmtNum(it.pcs_per_ctn)}</td><td>${fmtNum(it.cartons)}</td><td>${esc(it.carton_size||'')}</td><td>${fmtNum(it.nw,3)}</td><td>${fmtNum(it.gw,3)}</td><td>${fmtNum(it.cbm,4)}</td></tr>`).join('');let cartonRows=(d.cartons||[]).map((c,i)=>`<tr><td>${i+1}</td><td>${esc(c.carton_no||c.carton_range||'')}</td><td>${esc(c.carton_size||'')}</td><td>${fmtNum(c.qty)}</td><td>${fmtNum(c.nw,3)}</td><td>${fmtNum(c.gw,3)}</td><td>${fmtNum(c.cbm,4)}</td><td>${esc(c.note||'')}</td></tr>`).join('');box.innerHTML=`<div class="shipment-detail-box"><h4>批次明细：${esc(d.shipment?.shipment_no||'')}</h4><div class="order-table-wrap"><table class="table"><tr><th>#</th><th>型号</th><th>Customer Code</th><th>产品</th><th>出货数量</th><th>PCS/CTN</th><th>箱数</th><th>箱规</th><th>N.W.</th><th>G.W.</th><th>CBM</th></tr>${rows||'<tr><td colspan="11">无明细</td></tr>'}</table></div>${cartonRows?'<h4 style="margin-top:12px">拼箱/箱号</h4><table class="table"><tr><th>#</th><th>箱号</th><th>箱规</th><th>数量</th><th>N.W.</th><th>G.W.</th><th>CBM</th><th>备注</th></tr>'+cartonRows+'</table>':''}</div>`;}catch(e){alert(e.message)}}

function orderDocUrl(type,id,format='html'){return 'quote_order_doc.php?shipment_id='+encodeURIComponent(id)+'&type='+encodeURIComponent(type)+'&format='+encodeURIComponent(format)}
function openOrderDoc(type,id){type=(type==='ci')?'ci':'pl';if(!id){alert('缺少出货批次ID');return;}let title=type==='ci'?'Commercial Invoice':'Packing List';if($('orderDocTitle'))$('orderDocTitle').textContent=title;if($('orderDocHint'))$('orderDocHint').textContent='出货批次 ID：'+id+' ｜ PL 与 CI 共用同一个出货批次数量';if($('orderDocExcel'))$('orderDocExcel').href=orderDocUrl(type,id,'xls');if($('orderDocPdf'))$('orderDocPdf').href=orderDocUrl(type,id,'pdf');if($('orderDocFrame'))$('orderDocFrame').src=orderDocUrl(type,id,'html');$('orderDocModal')?.classList.add('show');try{orderApi('mark_document_generated',{shipment_id:id,type})}catch(e){}clientLog('open_order_document','打开订单单证：'+title,{shipment_id:id,doc_type:type});}
function closeOrderDoc(){$('orderDocModal')?.classList.remove('show');if($('orderDocFrame'))$('orderDocFrame').src='about:blank'}
function printOrderDoc(){let f=$('orderDocFrame');try{f?.contentWindow?.focus();f?.contentWindow?.print();}catch(e){alert('浏览器阻止了打印，请点开预览页后打印。')}}
async function loadDocuments(){try{let d=await orderApi('list_documents');DOCUMENTS=d.documents||[];renderDocuments();}catch(e){if($('documentList'))$('documentList').innerHTML='<div class="hint">加载单证失败：'+esc(e.message)+'</div>';}}
function renderDocuments(){let kw=String($('docSearch')?.value||'').toLowerCase().trim();let type=$('docTypeFilter')?.value||'';let arr=(DOCUMENTS||[]).filter(x=>{let hay=[x.shipment_no,x.order_no,x.quote_no,x.customer_name,x.packing_list_no,x.commercial_invoice_no,x.ship_date].join(' ').toLowerCase();return !kw||hay.includes(kw)});if($('docCount'))$('docCount').textContent=arr.length+' 个出货批次';let box=$('documentList');if(!box)return;box.innerHTML=arr.map(x=>{let id=Number(x.id),plDone=x.pl_generated_at?'已生成PL':'PL未生成',ciDone=x.ci_generated_at?'已生成CI':'CI未生成';return `<div class="doc-card"><b>${esc(x.customer_name||'未命名客户')} ｜ ${esc(x.shipment_no||'')}</b><small>订单：${esc(x.order_no||'')} ｜ 报价：${esc(x.quote_no||'')} ｜ 出货日期：${esc(x.ship_date||'')} ｜ 状态：${esc(x.status||'草稿')}</small><div class="doc-tags"><span>PL: ${esc(x.packing_list_no||'未填')}</span><span>CI: ${esc(x.commercial_invoice_no||'未填')}</span><span>${esc(plDone)}</span><span>${esc(ciDone)}</span><span>${fmtNum(x.total_cartons)} CTNS</span><span>N.W. ${fmtNum(x.total_nw,3)} KG</span><span>G.W. ${fmtNum(x.total_gw,3)} KG</span><span>CBM ${fmtNum(x.total_cbm,4)}</span></div><div class="btns"><button class="blue" onclick="openOrderDoc('pl',${id})">Packing List</button><button class="green" onclick="openOrderDoc('ci',${id})">Commercial Invoice</button><a class="btn-link" style="background:#2563eb;color:#fff;border-radius:9px;padding:8px 12px;text-decoration:none" target="_blank" href="${orderDocUrl('pl',id,'pdf')}">PL PDF</a><a class="btn-link" style="background:#2563eb;color:#fff;border-radius:9px;padding:8px 12px;text-decoration:none" target="_blank" href="${orderDocUrl('ci',id,'pdf')}">CI PDF</a><a class="btn-link" style="background:#eef2f7;color:#111827;border:1px solid #d4dbe7;border-radius:9px;padding:8px 12px;text-decoration:none" target="_blank" href="${orderDocUrl('pl',id,'xls')}">PL Excel</a><a class="btn-link" style="background:#eef2f7;color:#111827;border:1px solid #d4dbe7;border-radius:9px;padding:8px 12px;text-decoration:none" target="_blank" href="${orderDocUrl('ci',id,'xls')}">CI Excel</a>${quoteOrderExtraButtons(x.order_id||0,true)}</div></div>`}).join('')||'<div class="hint" style="padding:16px">暂无出货批次。请先到订单中心新增出货批次。</div>'; if(type){/* 预留：后续可按类型过滤 */}}



let PI_ORDER_DRAFT=null;
function piOrderCustomerName(){let c=S.customer||{};return c.company||c.name||c.customer_name||c.contact||''}
function piOrderItemFromQuote(it){it=clone(it||{});let p=it.product||{};let qty=Number(it.qty||0), price=Number(it.price||it.unit_price||0);let amount=Number(it.amount||qty*price||0);return {customer_code:it.customer_code||p.customer_code||'',product_code:it.product_code||p.code||p.model||'',product_name:it.product_name||p.name||p.title||'',specification:it.specification||buildSpec(it)||it.extra_spec||'',color:it.color||'',qty:qty,unit_price:price,amount:amount,item_json:JSON.stringify(it),image:p.image||it.image||''};}
function openPiOrderModal(){if(!hasPerm('order_convert')){alert('当前账号没有转订单权限');return;}let approved=requireApprovedForOutput('转订单');if(!approved)return;loadQuote(approved.id);let payload=currentQuotePayload();if(!payload.items_json||payload.items_json==='[]'){alert('请先添加产品');return;}if($('quoteStatus')){$('quoteStatus').value='PROFORMA INVOICE';render();}
  payload=currentQuotePayload();payload.quote_status='PROFORMA INVOICE';payload.note='Converted from PROFORMA INVOICE';
  let items=[];try{items=JSON.parse(payload.items_json||'[]')}catch(e){items=[]}items=items.map(piOrderItemFromQuote);
  PI_ORDER_DRAFT={payload,items};
  $('piOrderNo').value=payload.order_no||'';$('piOrderDate').value=payload.order_date||today();$('piOrderCustomer').value=piOrderCustomerName();$('piOrderCurrency').value=payload.currency||cur();$('piOrderNote').value=payload.note||'';renderPiOrderRows();$('piOrderModal').classList.add('show');
}
function closePiOrderModal(){$('piOrderModal')?.classList.remove('show')}
function renderPiOrderRows(){let box=$('piOrderRows');if(!box||!PI_ORDER_DRAFT)return;box.innerHTML=(PI_ORDER_DRAFT.items||[]).map((it,i)=>`<tr data-pi-row="${i}"><td>${i+1}</td><td><input class="pi-customer-code" value="${esc(it.customer_code||'')}"></td><td><input class="pi-product-code" value="${esc(it.product_code||'')}"></td><td><input class="pi-product-name" value="${esc(it.product_name||'')}"></td><td><textarea class="pi-spec">${esc(it.specification||'')}</textarea></td><td><input class="pi-color" value="${esc(it.color||'')}"></td><td><input class="pi-qty qty" type="number" step="1" value="${esc(it.qty||0)}" oninput="piOrderRecalc()"></td><td><input class="pi-price price" type="number" step="0.01" value="${esc(it.unit_price||0)}" oninput="piOrderRecalc()"></td><td><input class="pi-amount amount" readonly value="${esc(Number(it.amount||0).toFixed(2))}"></td><td><button class="red" onclick="piOrderRemoveItem(${i})">删</button></td></tr>`).join('');piOrderRecalc();}
function piOrderAddBlankItem(){if(!PI_ORDER_DRAFT)return;PI_ORDER_DRAFT.items.push({customer_code:'',product_code:'',product_name:'',specification:'',color:'',qty:1,unit_price:0,amount:0,item_json:'{}',image:''});renderPiOrderRows();}
function piOrderRemoveItem(i){if(!PI_ORDER_DRAFT)return;PI_ORDER_DRAFT.items.splice(i,1);renderPiOrderRows();}
function piOrderCollectItems(){let rows=[...document.querySelectorAll('#piOrderRows tr[data-pi-row]')];return rows.map((tr,i)=>{let qty=Number(tr.querySelector('.pi-qty')?.value||0), price=Number(tr.querySelector('.pi-price')?.value||0), amount=qty*price;let old=(PI_ORDER_DRAFT.items||[])[i]||{};let itemJson={};try{itemJson=JSON.parse(old.item_json||'{}')}catch(e){itemJson={}}if(!itemJson.product||typeof itemJson.product!=='object')itemJson.product={};itemJson.customer_code=tr.querySelector('.pi-customer-code')?.value||'';itemJson.product_code=tr.querySelector('.pi-product-code')?.value||'';itemJson.product_name=tr.querySelector('.pi-product-name')?.value||'';itemJson.product.code=itemJson.product.code||itemJson.product_code;itemJson.product.name=itemJson.product.name||itemJson.product_name;itemJson.specification=tr.querySelector('.pi-spec')?.value||'';itemJson.color=tr.querySelector('.pi-color')?.value||'';itemJson.qty=qty;itemJson.price=price;itemJson.unit_price=price;itemJson.amount=amount;itemJson.is_order_snapshot=true;return {customer_code:itemJson.customer_code,product_code:itemJson.product_code,product_name:itemJson.product_name,specification:itemJson.specification,color:itemJson.color,qty,unit_price:price,price,amount,item_json:JSON.stringify(itemJson),image:old.image||itemJson.product?.image||''};}).filter(x=>x.qty>0 || x.product_code || x.product_name);}
function piOrderRecalc(){if(!PI_ORDER_DRAFT)return;let rows=[...document.querySelectorAll('#piOrderRows tr[data-pi-row]')];let qty=0,amount=0;rows.forEach(tr=>{let q=Number(tr.querySelector('.pi-qty')?.value||0),p=Number(tr.querySelector('.pi-price')?.value||0),a=q*p;qty+=q;amount+=a;let am=tr.querySelector('.pi-amount');if(am)am.value=a.toFixed(2);});if($('piOrderSummary'))$('piOrderSummary').textContent='合计：'+fmtNum(qty)+' PCS ｜ '+($('piOrderCurrency')?.value||cur())+' '+money(amount);}
async function confirmPiOrderConvert(){if(!PI_ORDER_DRAFT)return;let items=piOrderCollectItems();if(!items.length){alert('订单至少需要一行产品');return;}let amount=items.reduce((s,it)=>s+Number(it.amount||0),0),qty=items.reduce((s,it)=>s+Number(it.qty||0),0);let payload=Object.assign({},PI_ORDER_DRAFT.payload);payload.order_no=$('piOrderNo').value||payload.order_no;payload.order_date=$('piOrderDate').value||today();payload.customer_name=$('piOrderCustomer').value||piOrderCustomerName();payload.items_json=JSON.stringify(items);payload.qty=qty;payload.amount=amount;payload.currency=$('piOrderCurrency').value||cur();payload.quote_status='PROFORMA INVOICE';payload.status='待确认';payload.note=$('piOrderNote').value||'Converted from PROFORMA INVOICE';payload.snapshot_json=JSON.stringify(Object.assign(exportQuotePayload(),{quote_status:'PROFORMA INVOICE',order_items:items}));if(!confirm('确认生成 PROFORMA INVOICE 订单？\n'+payload.order_no+'\n合计：'+payload.currency+' '+money(amount)))return;try{let j=await orderApi('convert',payload);clientLog('convert_to_order','PI转订单成功',{payload,response:j});try{await api('push_order_crm_notice',{order_id:j.id||0,order_no:j.order_no||payload.order_no,quote_no:payload.quote_no,customer_name:payload.customer_name,amount:amount,currency:payload.currency});}catch(notifyErr){console.warn('CRM订单通知失败',notifyErr)}closePiOrderModal();alert('已生成订单 / Proforma Invoice：'+j.order_no);await refreshQuoteRuntime('convert_to_order',{orders:true,documents:true});showPage('orders');if(j.id) viewOrder(j.id);}catch(e){alert('转订单失败：'+e.message);}}

function currentQuotePayload(){let items=(S.items&&S.items.length)?S.items:[currentEditorItem()].filter(it=>it.product&&it.product.id);items=items.map(it=>{let cp=normalizeQuoteItemCurrency(clone(it),cur(),it.currency||cur());cp.specification=buildSpec(cp);return cp});let amount=items.reduce((s,it)=>s+Number(it.amount||0),0), qty=items.reduce((s,it)=>s+Number(it.qty||0),0);let qn=quoteNoForOrder($('quoteNo').value||qno());return {order_no:qn,quote_no:qn,quote_date:$('quoteDate').value,order_date:today(),quote_status:quoteDocTitle(),customer_id:customerDbId(S.customer),customer_json:JSON.stringify(S.customer||{}),items_json:JSON.stringify(items),qty,amount,currency:cur(),status:'待确认',shipment_status:'未出货',payment_status:'未收款',header_json:JSON.stringify(S.header||{}),bank_json:JSON.stringify(S.bank||{}),template_json:JSON.stringify(S.template||{}),snapshot_json:JSON.stringify(exportQuotePayload()),note:'Converted from quotation'};}
async function convertToOrder(){openPiOrderModal();}
setInterval(()=>{$('clock').textContent='实时：'+now()},1000);applyPreviewZoom();load().catch(e=>alert(e.message));


/* V6.8.4.46：驳回原因分类 + 自填 + CRM通知 */
const QUOTE_REJECT_REASON_PRESETS_V68446 = [
  '价格过高',
  '价格过低',
  '利润率过低',
  '产品图片错误',
  '产品配置错误',
  '型号/尺寸错误',
  '数量/MOQ错误',
  '配件/光学/电源选择错误',
  '客户资料错误',
  '付款条款/银行信息错误',
  '备注/规格描述错误',
  '其它（自填）'
];
function quoteRejectReasonModalHtmlV68446(id,defaultNote=''){
  const q=(DB.quotes||[]).find(x=>String(x.id)===String(id))||{};
  const opts=QUOTE_REJECT_REASON_PRESETS_V68446.map(x=>`<option value="${esc(x)}">${esc(x)}</option>`).join('');
  return `<div class="modal show quoteRejectReasonModalV68446" id="quoteRejectReasonModalV68446">
    <div class="modal-box">
      <div class="review-head"><div><h3>驳回报价</h3><div class="review-meta">报价：${esc(q.quote_no||'')} ｜ 客户：${esc(q.customer_name||'')} ｜ 金额：${esc(q.currency||'USD')} ${money(q.amount||0)}</div></div><button class="gray" onclick="closeQuoteRejectReasonModalV68446()">关闭</button></div>
      <div class="quoteRejectTipV68446">驳回后会同步发送到 CRM 提醒中心；CRM 里会显示驳回分类和驳回原因，方便业务修改后重新提交。</div>
      <div class="form2 quoteRejectFormV68446">
        <div><label>驳回原因分类</label><select id="quoteRejectCategoryV68446">${opts}</select></div>
        <div><label>自定义分类</label><input id="quoteRejectCustomV68446" placeholder="例如：客户价异常 / 报价策略需确认"></div>
        <div style="grid-column:1/-1"><label>驳回原因说明</label><textarea id="quoteRejectDetailV68446" placeholder="请写清楚业务需要怎么改，例如：预览价过高，需重新确认倍率；或产品图片错误，需更换成客户确认图片。">${esc(defaultNote||'')}</textarea></div>
      </div>
      <div class="quoteRejectPresetsV68446"><b>常用原因：</b>${QUOTE_REJECT_REASON_PRESETS_V68446.filter(x=>x.indexOf('其它')<0).map(x=>`<button onclick="quoteRejectPickPresetV68446('${esc(x)}')">${esc(x)}</button>`).join('')}</div>
      <div class="btns" style="justify-content:flex-end;margin-top:12px"><button class="gray" onclick="closeQuoteRejectReasonModalV68446()">取消</button><button class="red" onclick="confirmRejectQuoteV68446(${Number(id)})">确认驳回并通知 CRM</button></div>
    </div>
  </div>`;
}
function quoteRejectPickPresetV68446(v){let s=$('quoteRejectCategoryV68446');if(s)s.value=v;}
function closeQuoteRejectReasonModalV68446(){let m=$('quoteRejectReasonModalV68446');if(m)m.remove();}
async function openQuoteRejectReasonModalV68446(id,defaultNote=''){
  if(!hasPerm('quote_approve')){alert('当前账号没有审核权限');return;}
  if(!id)return;
  closeQuoteRejectReasonModalV68446();
  document.body.insertAdjacentHTML('beforeend',quoteRejectReasonModalHtmlV68446(id,defaultNote));
}
async function confirmRejectQuoteV68446(id){
  if(!hasPerm('quote_approve')){alert('当前账号没有审核权限');return;}
  let cat=$('quoteRejectCategoryV68446')?.value||'';
  let custom=$('quoteRejectCustomV68446')?.value||'';
  let detail=$('quoteRejectDetailV68446')?.value||'';
  if(cat==='其它（自填）' && !custom.trim()){alert('选择“其它（自填）”时，请填写自定义分类');return;}
  if(!detail.trim() && !confirm('还没有填写驳回原因说明，仍然驳回吗？'))return;
  if(!confirm('确认驳回这张报价，并通知 CRM？'))return;
  await api('reject_quote',{id,reason_category:cat,reason_custom:custom,reason_detail:detail,note:detail});
  closeQuoteRejectReasonModalV68446();
  if(String(S.currentQuoteId)===String(id))S.currentApprovalStatus='rejected';
  await refreshQuoteRuntime('reject_quote_reason',{orders:true,documents:true});
  if(String(S.currentQuoteId)===String(id)){updateQuoteApprovalStrip((DB.quotes||[]).find(x=>String(x.id)===String(id)));}
  alert('已驳回，并已同步 CRM 提醒。');
}
rejectQuoteQuick = async function(id,note=''){
  return openQuoteRejectReasonModalV68446(Number(id),note||'');
};
rejectQuoteFromModal = async function(){
  if(!hasPerm('quote_approve')){alert('当前账号没有审核权限');return;}
  let modal=$('quoteReviewModal');if(!modal)return;
  await openQuoteRejectReasonModalV68446(Number(modal.dataset.quoteId||0),$('quoteReviewNote')?.value||'');
};

</script>
<style>
/* V6.8.4.46 驳回原因分类 */
.quoteRejectReasonModalV68446{z-index:180!important}.quoteRejectReasonModalV68446 .modal-box{width:min(760px,94vw)}
.quoteRejectTipV68446{border:1px solid #fecaca;background:#fff7f7;color:#991b1b;border-radius:14px;padding:10px 12px;font-size:13px;font-weight:900;line-height:1.55;margin:8px 0 12px}
.quoteRejectFormV68446 textarea{min-height:120px}.quoteRejectPresetsV68446{display:flex;gap:7px;align-items:center;flex-wrap:wrap;border:1px dashed #fecaca;background:#fffafa;border-radius:14px;padding:10px;margin-top:10px}.quoteRejectPresetsV68446 b{color:#991b1b}.quoteRejectPresetsV68446 button{height:30px;border-radius:999px;border:1px solid #fecaca;background:#fff;color:#991b1b;font-weight:900;padding:0 10px}
</style>


<script>
/* ===== Quotation V6.8.5.15 CRM订单直达/自动打开订单 ===== */
(function(){
  function qParam(k){ try{return new URLSearchParams(location.search).get(k)||'';}catch(e){return '';} }
  function qHashPage(){ const h=String(location.hash||'').replace(/^#/,''); return h==='orders'?'orders':''; }
  window.quoteRequestedOrderIdV68515 = function(){ return Number(qParam('order_id') || qParam('id') || 0); };
  window.quoteRequestedOrderNoV68515 = function(){ return qParam('order_no') || ''; };
  window.quoteRequestedPageV68515 = function(){ return qParam('page') || qHashPage() || ''; };
  window.quoteAutoOpenOrderFromUrlV68515 = async function(force){
    if(window.__QUOTE_ORDER_URL_OPENED_V68515 && !force) return;
    const id = quoteRequestedOrderIdV68515();
    const no = quoteRequestedOrderNoV68515();
    if(!id && !no) return;
    window.__QUOTE_ORDER_URL_OPENED_V68515 = true;
    try{
      if(typeof showPage === 'function') showPage('orders');
      if(typeof loadOrders === 'function' && (!window.DB || !(DB.orders||[]).length)) await loadOrders();
      if(no && document.getElementById('orderSearch')) document.getElementById('orderSearch').value = no;
      if(typeof renderOrders === 'function') renderOrders();
      if(id && typeof viewOrder === 'function') await viewOrder(id);
      else if(no && window.DB && Array.isArray(DB.orders)){
        const o = DB.orders.find(x=>String(x.order_no||'')===String(no));
        if(o && typeof viewOrder === 'function') await viewOrder(Number(o.id||0));
      }
    }catch(e){ console.warn('auto open order failed', e); }
  };
  const oldRestore = window.restoreLastPage;
  window.restoreLastPage = function(){
    const p = quoteRequestedPageV68515();
    if(p==='orders'){
      try{ localStorage.setItem('artdon_quote_current_page','orders'); }catch(e){}
      if(typeof showPage === 'function') showPage('orders');
      setTimeout(()=>quoteAutoOpenOrderFromUrlV68515(true),180);
      return;
    }
    return typeof oldRestore==='function' ? oldRestore.apply(this,arguments) : (typeof showPage==='function' && showPage('quote'));
  };
  const oldLoadOrders = window.loadOrders;
  if(typeof oldLoadOrders === 'function' && !oldLoadOrders.__v68515Wrapped){
    const wrapped = async function(){ const r = await oldLoadOrders.apply(this,arguments); setTimeout(()=>quoteAutoOpenOrderFromUrlV68515(false),80); return r; };
    wrapped.__v68515Wrapped = true;
    window.loadOrders = wrapped;
  }
})();
</script>



<style>
/* V6.8.5.16：历史/审核/订单中心全功能筛选 */
.approval-toolbar{grid-template-columns:minmax(220px,1fr) 145px 145px 135px 135px auto!important}
.order-toolbar{grid-template-columns:minmax(220px,1fr) 150px 150px 110px 130px 130px 140px 140px auto!important;align-items:center!important}
@media(max-width:1350px){.approval-toolbar,.order-toolbar{grid-template-columns:1fr 1fr!important}.order-toolbar .hint,.approval-toolbar .hint{grid-column:1/-1}}
@media(max-width:760px){.approval-toolbar,.order-toolbar{grid-template-columns:1fr!important}}
</style>
<script>
/* ===== Quotation V6.8.5.16 PI保存 / 搜索 / 订单标题补丁 ===== */
(function(){
  function qTextTokens(v){return String(v||'').toLowerCase().split(/[\s,，;；]+/).map(x=>x.trim()).filter(Boolean)}
  window.quoteMultiMatchV68516=function(hay,kw){let h=String(hay||'').toLowerCase();let ts=qTextTokens(kw);return !ts.length||ts.every(t=>h.includes(t));};
  window.quoteOwnerOfQuoteV68516=function(q){return String(q?.user_name||q?.owner||q?.sales||q?.created_by||q?.approved_by||q?.submitted_by||'').trim();};
  window.quoteOwnerOfOrderV68516=function(o){return String(o?.user_name||o?.owner||o?.sales||o?.sales_name||o?.created_by||o?.updated_by||o?.responsible||'').trim();};
  window.quoteCustomerNameOfOrderV68516=function(o){let c={};try{c=JSON.parse(o?.customer_json||'{}')}catch(e){}return String(o?.customer_name||c.company||c.name||c.customer_name||'').trim();};
  window.quoteSetOptionsV68516=function(id,values,first){let el=document.getElementById(id);if(!el)return;let old=el.value;let arr=[...new Set((values||[]).map(x=>String(x||'').trim()).filter(Boolean))].sort((a,b)=>a.localeCompare(b,'zh'));el.innerHTML='<option value="">'+(first||'全部')+'</option>'+arr.map(v=>'<option value="'+esc(v)+'">'+esc(v)+'</option>').join('');if(arr.includes(old))el.value=old;};
  const oldRefreshHistoryFilters=window.refreshHistoryFilters;
  window.refreshHistoryFilters=function(){if(typeof oldRefreshHistoryFilters==='function')oldRefreshHistoryFilters.apply(this,arguments);quoteSetOptionsV68516('histOwner',(DB.quotes||[]).map(quoteOwnerOfQuoteV68516),'全部负责人');};
  window.refreshApprovalFiltersV68516=function(){quoteSetOptionsV68516('approvalCustomer',(DB.quotes||[]).map(q=>{let c={};try{c=JSON.parse(q.customer_json||'{}')}catch(e){}return c.company||q.customer_name||''}),'全部客户');quoteSetOptionsV68516('approvalOwner',(DB.quotes||[]).map(quoteOwnerOfQuoteV68516),'全部负责人');};
  window.refreshOrderFiltersV68516=function(){quoteSetOptionsV68516('orderCustomer',(DB.orders||[]).map(quoteCustomerNameOfOrderV68516),'全部客户');quoteSetOptionsV68516('orderOwner',(DB.orders||[]).map(quoteOwnerOfOrderV68516),'全部负责人');};
  window.renderHistory=function(keepPage=false){
    if(!keepPage) historyPage=1;refreshHistoryFilters();updateHistoryViewButtons();
    let kw=($('histSearch')?.value||''), rg=$('histRange')?.value||'all', mon=$('histMonth')?.value||'', cust=$('histCustomer')?.value||'', owner=$('histOwner')?.value||'', country=$('histCountry')?.value||'', currency=$('histCurrency')?.value||'', min=Number($('histAmountMin')?.value||''), max=Number($('histAmountMax')?.value||''), sort=$('histSort')?.value||'dateDesc';
    let arr=(DB.quotes||[]).filter(q=>{let c=quoteCustomer(q);let own=quoteOwnerOfQuoteV68516(q);let text=quoteHistorySearchTextV68540(q,c);return quoteMultiMatchV68516(text,kw)&&inRangeDate(q,rg)&&(!mon||String(q.quote_date).slice(0,7)===mon)&&(!cust||c.company===cust||q.customer_name===cust)&&(!owner||own===owner)&&(!country||c.country===country)&&(!currency||q.currency===currency)&&(!$('histAmountMin')?.value||Number(q.amount||0)>=min)&&(!$('histAmountMax')?.value||Number(q.amount||0)<=max);});
    arr.sort((a,b)=>{if(sort==='amountDesc')return Number(b.amount||0)-Number(a.amount||0);if(sort==='amountAsc')return Number(a.amount||0)-Number(b.amount||0);if(sort==='customerAsc')return String(quoteCustomer(a).company||a.customer_name||'').localeCompare(String(quoteCustomer(b).company||b.customer_name||''),'zh');return String(b.quote_date||b.created_at||'').localeCompare(String(a.quote_date||a.created_at||''));});
    let totalFiltered=arr.length,totalPages=Math.max(1,Math.ceil(totalFiltered/historyPageSize));if(historyPage>totalPages)historyPage=totalPages;let startIdx=(historyPage-1)*historyPageSize,pageArr=arr.slice(startIdx,startIdx+historyPageSize);if($('historyList'))$('historyList').className='history-list history-view-'+(historyView||'list');let html='',last='';pageArr.forEach(q=>{let c={},p={},items=[];try{c=JSON.parse(q.customer_json||'{}');p=JSON.parse(q.product_json||'{}');items=JSON.parse(q.items_json||'[]')}catch(e){}let ym=String(q.quote_date||'').slice(0,7)||'未分组';if(ym!==last){html+='<div class="history-group">'+esc(ym)+'</div>';last=ym}let itemText=items.length?items.length+' 个产品':(p.name||'');html+=historyCardHtml(q,c,p,items,itemText);});$('historyList').innerHTML=html||'<div class="hint">没有匹配的报价。</div>';renderHistoryPager(totalFiltered,pageArr.length);
  };
  window.renderApproval=function(){let box=$('approvalList');if(!box)return;refreshApprovalFiltersV68516();let kw=String($('approvalSearch')?.value||'').trim(), st=$('approvalStatus')?.value||'pending', sort=$('approvalSort')?.value||'new', cust=$('approvalCustomer')?.value||'', owner=$('approvalOwner')?.value||'';let arr=(DB.quotes||[]).filter(q=>{let s=quoteApprovalStatus(q);let c=quoteCustomer(q);let own=quoteOwnerOfQuoteV68516(q);let hay=[q.quote_no,q.customer_name,c.company,c.contact,c.email,c.country,own,q.user_name,q.customer_json,q.product_json,q.items_json,q.amount,q.currency,q.approval_note,q.approved_by,q.rejected_by,q.submitted_by].join(' ');return (st==='all'||s===st)&&(!cust||c.company===cust||q.customer_name===cust)&&(!owner||own===owner)&&quoteMultiMatchV68516(hay,kw);});arr.sort((a,b)=>{if(sort==='amountDesc')return Number(b.amount||0)-Number(a.amount||0);if(sort==='customer')return String(a.customer_name||quoteCustomer(a).company||'').localeCompare(String(b.customer_name||quoteCustomer(b).company||''),'zh');return String(b.submitted_at||b.updated_at||b.created_at||'').localeCompare(String(a.submitted_at||a.updated_at||a.created_at||''));});if($('approvalCount'))$('approvalCount').textContent='共 '+arr.length+' 条';box.innerHTML=arr.map(q=>{let c=quoteCustomer(q),stNow=quoteApprovalStatus(q),approved=stNow==='approved';let who=approved?('审核：'+(q.approved_by||'-')+' ｜ '+(q.approved_at||'')):(stNow==='rejected'?('驳回：'+(q.rejected_by||'-')+' ｜ '+(q.rejected_at||'')):('提交：'+(q.submitted_at||q.updated_at||q.created_at||'')));let primaryBtn=approved?'查看日志':'审核预览';let actions=hasPerm('quote_approve')?`<button class="blue" onclick="openQuoteReview(${Number(q.id)})">${primaryBtn}</button>${approved?`<button class="orange" onclick="reverseApproveQuoteQuick(${Number(q.id)})">反审</button>`:`<button class="red" onclick="rejectQuoteQuick(${Number(q.id)})">驳回</button>`}`:`<button class="gray" onclick="openQuoteReview(${Number(q.id)})">查看日志</button><span class="hint">无审核权限</span>`;return `<div class="approval-row"><div><b>${quoteApprovalBadge(q)}${esc(q.quote_no||'')}</b><small>${esc(c.company||q.customer_name||'未选客户')} ｜ 负责人：${esc(quoteOwnerOfQuoteV68516(q)||'-')} ｜ ${esc(q.quote_date||'')} ｜ ${esc(q.currency||'USD')} ${money(q.amount||0)} ｜ ${esc(who)}</small>${q.approval_note?`<small>审核备注：${esc(q.approval_note)}</small>`:''}</div><div class="approval-actions">${actions}<button class="gray" onclick="openQuoteReview(${Number(q.id)})">日志</button><button class="gray" onclick="loadQuote(${Number(q.id)})">打开</button></div></div>`;}).join('')||'<div class="hint" style="padding:16px">暂无报价。</div>';};
  const oldOrderText=window.orderText;
  window.orderText=function(o){
    // V6.8.5.44：订单中心列表搜索改为轻量字段，避免每次搜索/打开都解析 items_json 大快照。
    return [o?.order_no,o?.quote_no,o?.customer_name,quoteCustomerNameOfOrderV68516(o),o?.currency,o?.amount,o?.status,o?.shipment_status,o?.payment_status,quoteOwnerOfOrderV68516(o),o?.user_name,o?.owner,o?.sales,o?.created_by].join(' ').toLowerCase();
  };
  window.renderOrders=function(){refreshOrderFiltersV68516();let kw=String($('orderSearch')?.value||'').trim(), st=$('orderStatus')?.value||'', sort=$('orderSort')?.value||'new', cust=$('orderCustomer')?.value||'', owner=$('orderOwner')?.value||'', currency=$('orderCurrency')?.value||'', df=$('orderDateFrom')?.value||'', dtv=$('orderDateTo')?.value||'';let arr=(DB.orders||[]).filter(o=>{let od=String(o.order_date||o.created_at||'').slice(0,10);return quoteMultiMatchV68516(orderText(o),kw)&&(!st||String(o.status||'')===st)&&(!cust||quoteCustomerNameOfOrderV68516(o)===cust)&&(!owner||quoteOwnerOfOrderV68516(o)===owner)&&(!currency||String(o.currency||'').toUpperCase()===currency)&&(!df||od>=df)&&(!dtv||od<=dtv);});arr.sort((a,b)=>{if(sort==='amountDesc')return Number(b.amount||0)-Number(a.amount||0);if(sort==='amountAsc')return Number(a.amount||0)-Number(b.amount||0);if(sort==='customer')return String(a.customer_name||'').localeCompare(String(b.customer_name||''),'zh');return String(b.created_at||b.order_date||'').localeCompare(String(a.created_at||a.order_date||''));});if($('orderCount'))$('orderCount').textContent='共 '+arr.length+' / '+(DB.orders||[]).length+' 个订单';if(!$('orderList'))return;$('orderList').innerHTML=orderFinanceStrip(arr)+(arr.length?arr.map(o=>{let cls=/已作废|取消/.test(o.status||'')?'voided':(/已完成|已出货/.test(o.status||'')?'done':(/待出货|部分出货|生产中/.test(o.status||'')?'warn':''));let pst=o.payment_status||'未收款';return `<div class="order-card" onclick="viewOrder(${Number(o.id)})"><div><b>${esc(quoteOrderNoAtV68522(o.order_no,o.quote_no)||'')}</b> <span class="order-status ${cls}">${esc(o.status||'待确认')}</span><small>来源报价：${esc(o.quote_no||'')} ｜ 客户：${esc(o.customer_name||'未选客户')} ｜ 负责人：${esc(quoteOwnerOfOrderV68516(o)||'-')} ｜ ${esc(o.order_date||'')} ｜ ${esc(o.currency||'USD')} ${money(o.amount)}</small><small>数量 ${Number(o.qty||0)} PCS ｜ 出货：${esc(o.shipment_status||'未出货')} ｜ 收款：${esc(pst)} ｜ 未收 ${esc(o.currency||'USD')} ${money(o.balance_amount)}</small></div><div class="order-list-actions"><button class="gray" onclick="event.stopPropagation();viewOrder(${Number(o.id)})">详情</button></div></div>`}).join(''):'<div class="order-detail-empty">暂无订单。先在报价单页面点“一键转订单”。</div>');};
  const oldConfirm=window.confirmPiOrderConvert;
  window.confirmPiOrderConvert=async function(){if(!PI_ORDER_DRAFT)return;let items=piOrderCollectItems();if(!items.length){alert('订单至少需要一行产品');return;}let amount=items.reduce((s,it)=>s+Number(it.amount||0),0),qty=items.reduce((s,it)=>s+Number(it.qty||0),0);let payload=Object.assign({},PI_ORDER_DRAFT.payload);payload.order_no=$('piOrderNo').value||payload.order_no;payload.order_date=$('piOrderDate').value||today();payload.customer_name=$('piOrderCustomer').value||piOrderCustomerName();payload.items_json=JSON.stringify(items);payload.qty=qty;payload.amount=amount;payload.currency=$('piOrderCurrency').value||cur();payload.quote_status=quoteOrderDocTitle(payload.currency);payload.order_doc_title=payload.quote_status;payload.contract_title=payload.quote_status;payload.status='待确认';payload.note=$('piOrderNote').value||('Converted to '+payload.quote_status);payload.snapshot_json=JSON.stringify(Object.assign(exportQuotePayload(),{quote_status:payload.quote_status,order_doc_title:payload.quote_status,contract_title:payload.quote_status,order_items:items}));if(!confirm('确认生成 '+payload.quote_status+' 订单？\n'+payload.order_no+'\n合计：'+payload.currency+' '+money(amount)))return;try{let j=await orderApi('convert',payload);clientLog('convert_to_order','转订单成功',{payload,response:j});try{await api('push_order_crm_notice',{order_id:j.id||0,order_no:j.order_no||payload.order_no,quote_no:payload.quote_no,customer_name:payload.customer_name,amount:amount,currency:payload.currency});}catch(notifyErr){console.warn('CRM订单通知失败',notifyErr)}closePiOrderModal();alert('已生成订单 / '+payload.quote_status+'：'+j.order_no);await refreshQuoteRuntime('convert_to_order',{orders:true,documents:true});showPage('orders');if(j.id) viewOrder(j.id);}catch(e){alert('转订单失败：'+e.message);}};
})();


/* ===== Quotation V6.8.5.25：订单中心一键生成 Packing List / Commercial Invoice ===== */
(function(){
  window.quickGenerateOrderDoc = async function(orderId,type){
    orderId=Number(orderId||0); type=(type==='ci')?'ci':'pl';
    if(!orderId){alert('订单ID无效');return;}
    if(typeof hasPerm==='function' && !hasPerm('order_convert')){alert('当前账号没有订单/单证权限');return;}
    let title=type==='ci'?'Commercial Invoice':'Packing List';
    try{
      let r=await orderApi('quick_document',{order_id:orderId,type:type,force_shipment:1});
      let sid=Number(r.shipment_id || (r.shipment&&r.shipment.id) || 0);
      if(!sid){alert('未生成出货批次，不能打开 '+title);return;}
      try{await refreshQuoteRuntime('quick_generate_'+type,{orders:true,documents:true});}catch(e){}
      openOrderDoc(type,sid);
    }catch(e){alert('生成 '+title+' 失败：'+(e.message||e));}
  };
  window.quoteOrderExtraButtons = function(id, compact=false){
    id=Number(id||0); if(!id) return '';
    let html='';
    html += `<button class="blue" onclick="event.stopPropagation();exportOrderPDF(${id})">PDF</button>`;
    html += `<button class="gray" onclick="event.stopPropagation();exportOrderExcel(${id})">Excel</button>`;
    html += `<button class="green" onclick="event.stopPropagation();quickGenerateOrderDoc(${id},'pl')">${compact?'PL':'Packing List'}</button>`;
    html += `<button class="blue" onclick="event.stopPropagation();quickGenerateOrderDoc(${id},'ci')">${compact?'CI':'Commercial Invoice'}</button>`;
    if(typeof hasPerm==='function' && hasPerm('settings_manage')){
      html += `<button class="gray" onclick="event.stopPropagation();quoteVoidSalesOrder(${id})">作废${compact?'':'订单'}</button>`;
      html += `<button class="red" onclick="event.stopPropagation();quoteDeleteTestSalesOrder(${id})">删除${compact?'':'测试订单'}</button>`;
    }
    return html;
  };
})();

</script>


<!-- V6.8.5.40：历史报价关键词只搜索客户公司 / 客户联系人 / 报价订单号，不再匹配产品/业务员/国家/金额。 -->
</body></html>
