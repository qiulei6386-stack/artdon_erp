<?php
/* ARTDON_SSO_GATE_V2_START */
require_once __DIR__.'/includes/artdon_sso_core.php';
artdon_sso_require_page('bom');
/* ARTDON_SSO_GATE_V2_END */
 /* Artdon BOM V2 PHP/MySQL no password */ ?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>中山雅大光电有限公司 - BOM 成本管理系统 V78.2 HTTPS图片显示修复版</title>
<style>
:root{--bg:#f5f7fb;--card:#fff;--text:#1f2937;--muted:#6b7280;--line:#e5e7eb;--main:#2563eb;--danger:#dc2626;--ok:#16a34a}
*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--text);font-family:-apple-system,BlinkMacSystemFont,"PingFang SC","Microsoft YaHei",Arial,sans-serif;font-size:14px}
header{position:sticky;top:0;z-index:20;background:rgba(255,255,255,.96);backdrop-filter:blur(8px);border-bottom:1px solid var(--line)}
.wrap{width:min(1760px,calc(100% - 64px));max-width:1760px;margin:0 auto;padding:14px 0}.brand-cn{font-size:22px;font-weight:800}.brand-en{font-size:15px;color:#475569;margin-top:3px}.brand-sub{font-size:13px;color:#64748b;margin-top:5px}
button{border:0;border-radius:10px;padding:9px 13px;background:var(--main);color:#fff;cursor:pointer}button.ghost{background:#fff;color:#111827;border:1px solid var(--line)}button.ok{background:var(--ok)}button.danger{background:var(--danger)}button.small{padding:6px 9px;font-size:12px}
input,select,textarea{border:1px solid var(--line);border-radius:10px;padding:8px 10px;background:#fff;font:inherit}textarea{resize:vertical}
.topbar,.actions,.filters{display:flex;gap:8px;flex-wrap:wrap;align-items:center}.tabs{display:flex;gap:8px;margin-top:10px}.tab{background:#fff;color:#111827;border:1px solid var(--line)}.tab.active{background:var(--main);color:#fff}
.grid{display:grid;grid-template-columns:340px minmax(0,1fr);gap:16px;margin-top:14px;align-items:start}.editor-card{min-width:0}.editor-head{display:grid;grid-template-columns:repeat(4,minmax(180px,1fr));gap:12px 14px;margin-bottom:12px}.editor-head label{display:flex;flex-direction:column;gap:5px;font-size:12px;color:var(--muted)}.editor-head input,.editor-head select{width:100%;color:var(--text)}.card{background:var(--card);border:1px solid var(--line);border-radius:16px;padding:14px;box-shadow:0 4px 16px rgba(0,0,0,.04)}
.side-title{display:flex;justify-content:space-between;align-items:center;margin-bottom:10px}.project-list{display:flex;flex-direction:column;gap:8px;max-height:65vh;overflow:auto}
.project{padding:10px;border:1px solid var(--line);border-radius:12px;background:#fff;cursor:pointer}.project.active{border-color:var(--main);box-shadow:0 0 0 2px rgba(37,99,235,.12)}.project b{display:block}.project small{color:var(--muted);line-height:1.5}
.meta{display:grid;grid-template-columns:repeat(4,minmax(150px,1fr));gap:10px;margin-bottom:12px}.meta label{display:flex;flex-direction:column;gap:5px;font-size:12px;color:var(--muted)}.meta input,.meta select{color:var(--text)}
.table-wrap{width:100%;overflow:auto;border:1px solid var(--line);border-radius:12px;background:#fff}.table-wrap table{border:0;border-radius:0;min-width:1280px;table-layout:fixed}
table{width:100%;border-collapse:separate;border-spacing:0;border:1px solid var(--line);border-radius:12px;overflow:hidden;background:#fff}th,td{border-bottom:1px solid var(--line);border-right:1px solid var(--line);padding:6px;text-align:left;vertical-align:middle}th:last-child,td:last-child{border-right:0}tr:last-child td{border-bottom:0}th{background:#f9fafb;font-size:13px;color:#374151;white-space:nowrap}
#bomTable thead th{position:sticky;top:0;z-index:8;background:#f9fafb}#bomTable th[data-col="no"],#bomTable td.col-no{position:sticky;left:0;z-index:9;width:56px!important;min-width:56px!important;background:#fff}#bomTable th[data-col="category"],#bomTable td.col-category{position:sticky;left:56px;z-index:9;width:130px!important;min-width:130px!important;background:#fff}#bomTable th[data-col="name"],#bomTable td.col-name{position:sticky;left:186px;z-index:9;width:300px!important;min-width:300px!important;background:#fff}#bomTable td.col-spec{min-width:560px}
td input,td select{width:100%;border:0;padding:5px;background:transparent}td textarea.cell-text{width:100%;border:0;background:transparent;resize:none;min-height:30px;padding:5px;line-height:1.3;overflow:hidden}.num{text-align:right}
.drag-handle{cursor:grab;color:#64748b;font-weight:800}.dragging{opacity:.45}.drag-over{outline:2px solid #2563eb;outline-offset:-2px}.row-actions{display:flex;gap:3px;justify-content:center;align-items:center}.row-act{min-width:24px;height:24px;padding:0!important;border-radius:6px!important;font-size:13px!important;background:#f3f4f6!important;color:#111827!important;border:1px solid #d1d5db!important}.row-act.danger{background:#fee2e2!important;color:#991b1b!important;border-color:#fecaca!important}
.summary{display:grid;grid-template-columns:repeat(6,1fr);gap:10px;margin-top:12px}.sum-box{background:#f9fafb;border:1px solid var(--line);border-radius:12px;padding:12px}.sum-box span{display:block;color:var(--muted);font-size:12px}.sum-box b{font-size:20px}.note{width:100%;min-height:72px;margin-top:10px}.status,.hint{font-size:13px;color:var(--muted)}.hide{display:none!important}
.library{margin-top:14px}.lib-toolbar{display:grid;grid-template-columns:1.3fr .9fr .9fr .9fr .9fr .9fr;gap:8px;margin-bottom:12px}.group{border:1px solid var(--line);border-radius:14px;background:#fff;margin-bottom:12px;overflow:hidden}.group-head{display:flex;justify-content:space-between;align-items:center;background:#f9fafb;padding:12px 14px;cursor:pointer}.cost-card{border-top:1px solid var(--line);padding:12px 14px}.cost-main{display:grid;grid-template-columns:1.2fr .8fr .7fr .7fr .8fr .8fr auto;gap:10px;align-items:center}.price{font-weight:700;color:#dc2626}
.material-grid{display:grid;grid-template-columns:repeat(7,minmax(110px,1fr));gap:8px;margin-bottom:10px}.material-list{max-height:none;overflow-x:auto;overflow-y:visible;border:1px solid var(--line);border-radius:12px}.material-search-row{display:flex;gap:8px;flex-wrap:wrap;margin:10px 0}.mat-pagebar{display:flex;gap:8px;align-items:center;justify-content:space-between;flex-wrap:wrap;margin:8px 0;color:#64748b;font-size:13px}.mat-pagebar-left,.mat-pagebar-right{display:flex;gap:6px;align-items:center;flex-wrap:wrap}.mat-pagebar button{padding:6px 10px;border-radius:9px}.mat-pagebar select{padding:6px 8px;border-radius:9px}.mat-pagebar input{width:68px;padding:6px 8px;border-radius:9px}.mini-thumb{width:38px;height:38px;object-fit:cover;border:1px solid #d1d5db;border-radius:6px;background:#f3f4f6}
.mat-suggest-float{position:fixed;z-index:999999;background:#fff;border:1px solid #bfdbfe;border-radius:10px;box-shadow:0 16px 38px rgba(15,23,42,.22);max-height:280px;overflow:auto;display:none;min-width:420px}.mat-option{display:grid;grid-template-columns:1fr auto;gap:8px;align-items:center;padding:8px;border-bottom:1px solid #eef2f7;cursor:pointer}.mat-option:hover{background:#eff6ff}.mat-option-title{font-weight:700;font-size:13px}.mat-option-sub{font-size:12px;color:#6b7280;line-height:1.35}.mat-option-price{font-weight:700;color:#dc2626;white-space:nowrap}
.product-image-box{display:flex;gap:10px;align-items:center;border:1px solid var(--line);border-radius:12px;padding:8px;background:#f9fafb;min-height:82px}.product-image-preview{width:72px;height:72px;object-fit:cover;border:1px solid #d1d5db;border-radius:10px;background:#fff;display:none}
@media(max-width:900px){.grid{grid-template-columns:1fr}.meta,.editor-head{grid-template-columns:1fr 1fr}.summary{grid-template-columns:1fr 1fr}.lib-toolbar,.material-grid{grid-template-columns:1fr 1fr}.cost-main{grid-template-columns:1fr}}@media(max-width:640px){.meta,.editor-head{grid-template-columns:1fr}.wrap{width:calc(100% - 20px);padding:10px 0}.grid{gap:10px}}


/* V66：进入页改为 BOM 总览，支持本月 / 7天 / 3天 / 全功能筛选 */
.dashboard{margin-top:14px}.dashboard-hero{display:grid;grid-template-columns:1.2fr .8fr;gap:14px;margin-bottom:14px}.dashboard-title{font-size:22px;font-weight:900;margin:0 0 6px}.dashboard-sub{color:#64748b;line-height:1.6}.dash-actions{display:flex;gap:8px;flex-wrap:wrap;justify-content:flex-end;align-items:flex-start}.dash-view-pills{display:flex;gap:6px;flex-wrap:wrap}.dash-view-pills button{background:#fff;color:#334155;border:1px solid var(--line)}.dash-view-pills button.active{background:var(--main);color:#fff;border-color:var(--main)}.range-pills{display:flex;gap:8px;flex-wrap:wrap;margin:10px 0}.range-pills button{background:#fff;color:#111827;border:1px solid var(--line)}.range-pills button.active{background:var(--main);color:#fff;border-color:var(--main)}.dashboard-filters{display:grid;grid-template-columns:1.3fr .8fr .8fr .8fr .8fr .7fr .7fr auto;gap:8px;align-items:center;margin:12px 0}.dash-stats{display:grid;grid-template-columns:repeat(4,minmax(120px,1fr));gap:10px;margin:12px 0}.dash-stat{background:#f9fafb;border:1px solid var(--line);border-radius:12px;padding:12px}.dash-stat span{display:block;color:#64748b;font-size:12px}.dash-stat b{font-size:20px}.dash-pager{display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap;margin:10px 0;color:#64748b;font-size:12px;font-weight:900}.dash-pager-actions{display:flex;gap:8px}.dash-pager button:disabled{opacity:.42;cursor:not-allowed}.dashboard-table-wrap{overflow:auto;border:1px solid var(--line);border-radius:14px;background:#fff}.dashboard-table{min-width:1180px;border:0;border-radius:0}.dashboard-table th{position:sticky;top:0;z-index:2}.bom-title-cell b{font-size:14px}.bom-title-cell small{display:block;color:#64748b;line-height:1.5;margin-top:3px}.dash-group-row td{background:#f1f5f9!important;color:#0f172a;font-weight:1000;border-top:1px solid #dbe3ef}.dash-group-title{display:flex;align-items:center;justify-content:space-between;gap:10px}.dash-group-title small{color:#64748b;font-weight:900}.dash-icon-groups{display:grid;gap:16px}.dash-icon-group{display:grid;gap:9px}.dash-icon-group-head{display:flex;align-items:center;justify-content:space-between;border:1px solid #dbe3ef;background:#f8fafc;border-radius:10px;padding:8px 10px;font-weight:1000;color:#0f172a}.dash-icon-group-head small{color:#64748b;font-size:12px}.dash-icon-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:12px}.dash-bom-card{border:1px solid var(--line);border-radius:12px;background:#fff;overflow:hidden;display:flex;flex-direction:column;min-height:304px;box-shadow:0 8px 20px rgba(15,23,42,.04)}.dash-bom-image{height:150px;background:#f8fafc;display:flex;align-items:center;justify-content:center;border-bottom:1px solid #eef2f7}.dash-bom-image img{width:100%;height:100%;object-fit:contain;display:block}.dash-bom-image .empty{width:100%;height:100%;display:flex;align-items:center;justify-content:center;color:#94a3b8;font-weight:1000;background:#f8fafc}.dash-bom-body{padding:10px 11px;display:grid;gap:6px;flex:1}.dash-bom-title{font-size:13.5px;font-weight:1000;color:#111827;line-height:1.35;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}.dash-bom-model{font-size:12px;color:#475569;font-weight:900;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.dash-bom-meta{display:grid;grid-template-columns:1fr 1fr;gap:6px;font-size:11px;color:#64748b}.dash-bom-meta span{background:#f8fafc;border:1px solid #eef2f7;border-radius:8px;padding:5px 6px;min-width:0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.dash-bom-cost{display:flex;justify-content:space-between;gap:8px;align-items:center;font-size:12px;font-weight:950}.dash-bom-cost b{color:#b91c1c}.dash-bom-actions{display:flex;gap:6px;padding:0 11px 11px}.dash-bom-actions button{flex:1}.dash-empty{padding:28px;text-align:center;color:#64748b}.dashboard .price{font-weight:900;color:#b91c1c}@media(max-width:1100px){.dashboard-hero{grid-template-columns:1fr}.dash-actions{justify-content:flex-start}.dashboard-filters{grid-template-columns:1fr 1fr 1fr}.dash-stats{grid-template-columns:1fr 1fr}}@media(max-width:640px){.dashboard-filters{grid-template-columns:1fr}.dash-stats{grid-template-columns:1fr 1fr}.dashboard-title{font-size:18px}.dash-icon-grid{grid-template-columns:repeat(2,minmax(0,1fr));gap:9px}.dash-bom-card{min-height:260px}.dash-bom-image{height:118px}.dash-bom-meta{grid-template-columns:1fr}.dash-bom-actions{flex-direction:column}}

@media print{header,aside,.actions,.tabs,.lib-toolbar{display:none!important}body{background:#fff}.grid{display:block}.card{box-shadow:none;border:0}}

/* V64：恢复成本单左右布局，接近用户截图格式 */
#editPage>.card:first-child{position:sticky;top:132px;max-height:calc(100vh - 150px);overflow:auto}
#editPage>.editor-card{padding:16px 18px}
.project-list{max-height:calc(100vh - 330px)}
.project b{font-size:14px;line-height:1.35}.project small{font-size:12px}
.table-wrap{margin-top:8px}
#bomTable{font-size:13px}
#bomTable th{padding:8px 7px}
#bomTable td{padding:5px 6px}
#bomTable td textarea.cell-text,#bomTable td input{font-size:13px;min-height:30px}
#bomTable td.col-name textarea{font-weight:700}.subtotal{font-weight:700;color:#334155}.price-cell input,.price-input{font-weight:900;color:#b91c1c;font-size:16px!important}
.finish-combo{display:grid;grid-template-columns:1fr 88px;gap:6px;align-items:center}.finish-combo .finishCost{background:#f8fafc;border:1px solid #dbe3ef;border-radius:8px;text-align:right}.finish-combo .finish{background:#fff}.sum-box b{font-weight:900;color:#111827}.summary{grid-template-columns:repeat(6,minmax(120px,1fr))}.product-image-box{min-height:64px}.product-image-preview{width:58px;height:58px}
.meta{grid-template-columns:repeat(4,minmax(150px,1fr))}
@media(max-width:1200px){#editPage{grid-template-columns:300px minmax(0,1fr)}.editor-head,.meta{grid-template-columns:repeat(3,1fr)}}
@media(max-width:900px){#editPage>.card:first-child{position:static;max-height:none}.project-list{max-height:40vh}.summary{grid-template-columns:repeat(2,1fr)}}



/* V65：修复BOM表面处理显示、列宽拖动、共享物料库单击编辑 */
#bomTable th,#materialTable th{position:relative;user-select:none}
.col-resizer{position:absolute;right:-3px;top:0;width:7px;height:100%;cursor:col-resize;z-index:30}
.col-resizer:hover{background:rgba(37,99,235,.28)}
#bomTable th,#bomTable td,#materialTable th,#materialTable td{overflow:hidden;word-break:break-word;overflow-wrap:anywhere}
.finish-combo{display:flex!important;flex-direction:column!important;gap:4px!important;align-items:stretch!important}
.finish-combo .finish{width:100%!important;background:#fff!important;border:0!important;padding:4px 5px!important;min-height:28px!important}
.finish-combo .finishCost{width:96px!important;align-self:flex-end!important;background:#f8fafc!important;border:1px solid #dbe3ef!important;border-radius:8px!important;text-align:right!important;padding:4px 6px!important;min-height:28px!important;font-weight:700!important}
.finish-combo .finishHint{font-size:11px;color:#64748b;line-height:1.1}
.material-list table{min-width:1180px;table-layout:fixed;border-collapse:separate;border-spacing:0}
.material-list th{background:#f9fafb;position:sticky;top:0;z-index:5}
.material-list td[contenteditable="true"]{cursor:text;background:#fff}
.material-list td[contenteditable="true"]:focus{outline:2px solid rgba(37,99,235,.25);background:#eff6ff;border-radius:6px}
.material-list tr:hover td{background:#fbfdff}
.material-status{font-size:12px;color:#16a34a;margin-left:8px;font-weight:800}
.plm-link-notice{display:none;margin:10px 0 12px 0;border:1px solid #fde68a;background:#fffbeb;color:#92400e;border-radius:12px;padding:10px 12px;font-size:13px;line-height:1.55}.plm-link-notice b{color:#78350f}.plm-link-notice .okmsg{color:#166534}.plm-link-notice .badmsg{color:#b91c1c}.plm-link-notice button{margin-left:8px}.plm-missing td{background:#fff7ed!important}.plm-missing .price-input{background:#fee2e2!important;border-color:#fca5a5!important}

/* V67：登录 / 用户权限 */
.login-mask{position:fixed;inset:0;z-index:1000000;background:linear-gradient(135deg,rgba(15,23,42,.76),rgba(37,99,235,.38));display:flex;align-items:center;justify-content:center;padding:18px;backdrop-filter:blur(8px)}
.login-box{width:min(430px,96vw);background:#fff;border:1px solid #dbeafe;border-radius:22px;box-shadow:0 30px 80px rgba(15,23,42,.32);padding:24px}.login-box h2{margin:8px 0 12px;font-size:20px}.login-box input{width:100%;margin:7px 0}.login-brand-cn{font-size:22px;font-weight:900;color:#1d4ed8}.login-brand-en{color:#475569;font-weight:800;margin-top:3px}.login-error{min-height:20px;color:#dc2626;font-weight:800}.user-chip{display:inline-flex;align-items:center;gap:6px;border:1px solid #bfdbfe;background:#eff6ff;color:#1d4ed8;border-radius:999px;padding:6px 10px;font-size:12px;font-weight:900}.user-table th,.user-table td{font-size:13px}.user-form{display:grid;grid-template-columns:1fr 1fr 1fr 1fr 1.4fr auto;gap:8px;align-items:center;margin-bottom:12px}.perm-help{background:#f8fafc;border:1px solid var(--line);border-radius:12px;padding:10px;color:#64748b;line-height:1.7;font-size:13px;margin:10px 0}.disabled-row{opacity:.55;background:#f8fafc}@media(max-width:1100px){.user-form{grid-template-columns:1fr 1fr}}@media(max-width:640px){.user-form{grid-template-columns:1fr}}



/* V68：命名系统联动 BOM */
.naming-bom-modal{position:fixed;inset:0;z-index:1000001;background:rgba(15,23,42,.46);display:none;align-items:center;justify-content:center;padding:18px;backdrop-filter:blur(6px)}
.naming-bom-box{width:min(1120px,96vw);max-height:92vh;background:#fff;border:1px solid #dbeafe;border-radius:22px;box-shadow:0 28px 80px rgba(15,23,42,.28);overflow:hidden;display:flex;flex-direction:column}
.naming-bom-head{padding:14px 16px;border-bottom:1px solid var(--line);display:flex;justify-content:space-between;align-items:flex-start;gap:12px;background:linear-gradient(180deg,#fff,#f8fbff)}
.naming-bom-head h3{margin:0 0 4px;font-size:18px}.naming-bom-head p{margin:0;color:#64748b;font-size:12px;font-weight:800;line-height:1.5}
.naming-bom-body{padding:13px 16px;overflow:auto}.naming-bom-filters{display:grid;grid-template-columns:1.4fr repeat(5,minmax(100px,1fr)) auto;gap:8px;margin-bottom:12px;align-items:end}.naming-bom-filters label{display:grid;gap:4px;font-size:11px;color:#64748b;font-weight:900}.naming-bom-filters input,.naming-bom-filters select{height:34px;border-radius:10px;padding:7px 9px;min-width:0}
.naming-bom-list{border:1px solid var(--line);border-radius:16px;background:#fff;overflow:hidden}.naming-bom-row{display:grid;grid-template-columns:64px minmax(0,1fr) 170px 116px;gap:12px;align-items:center;padding:10px 12px;border-bottom:1px solid #edf2f7}.naming-bom-row:last-child{border-bottom:0}.naming-bom-row:hover{background:#f8fbff}.naming-bom-thumb{width:58px;height:58px;border-radius:14px;background:#eff6ff;color:#1d4ed8;border:1px solid #bfdbfe;display:flex;align-items:center;justify-content:center;font-weight:1000;overflow:hidden}.naming-bom-thumb img{width:100%;height:100%;object-fit:cover}.naming-bom-title{font-weight:1000;font-size:15px;color:#0f172a}.naming-bom-sub{font-size:12px;color:#64748b;font-weight:850;line-height:1.45;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.naming-bom-dim{font-size:12px;font-weight:1000;color:#9a3412;background:#fff7ed;border:1px solid #fed7aa;border-radius:999px;padding:5px 9px;display:inline-flex;max-width:100%;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.naming-bom-empty{padding:28px;text-align:center;color:#64748b;font-weight:900}.naming-bom-create{white-space:nowrap}.bom-source-chip{display:inline-flex;align-items:center;gap:5px;height:24px;border-radius:999px;background:#f0fdf4;color:#166534;border:1px solid #bbf7d0;font-size:11px;font-weight:1000;padding:0 8px;margin-left:6px}
@media(max-width:900px){.naming-bom-filters{grid-template-columns:1fr 1fr}.naming-bom-row{grid-template-columns:50px minmax(0,1fr);}.naming-bom-row .naming-bom-dim,.naming-bom-row .naming-bom-create{grid-column:2/3}.naming-bom-thumb{width:48px;height:48px}}
/* V78：命名系统基础资料同步提示 */
.naming-sync-diff{margin-top:8px;border:1px solid #fed7aa;background:#fff7ed;color:#9a3412;border-radius:12px;padding:8px 10px;font-size:12px;font-weight:900;line-height:1.55}
.naming-sync-diff b{color:#7c2d12}.naming-sync-diff ul{margin:6px 0 0 18px;padding:0}.naming-sync-diff li{margin:2px 0}.naming-sync-ok{color:#166534;font-weight:1000}.naming-sync-warn{color:#b91c1c;font-weight:1000}




/* V69：BOM表格紧凑 + 双表面处理 + 关键物料检查 */
#bomTable{font-size:12px!important}
#bomTable th{padding:5px 5px!important;font-size:12px!important;line-height:1.15!important}
#bomTable td{padding:3px 4px!important;line-height:1.18!important}
#bomTable td textarea.cell-text{min-height:23px!important;padding:2px 4px!important;line-height:1.18!important;font-size:12px!important}
#bomTable td input,#bomTable td select{min-height:23px!important;padding:2px 4px!important;font-size:12px!important}
#bomTable .row-act{min-width:22px!important;height:22px!important;font-size:12px!important;border-radius:5px!important}
#bomTable .drag-handle{font-size:12px!important}
#bomTable .finish-combo{display:grid!important;grid-template-rows:auto auto!important;gap:2px!important;align-items:stretch!important}
#bomTable .finish-line{display:grid!important;grid-template-columns:minmax(78px,1fr) 62px!important;gap:3px!important;align-items:center!important}
#bomTable .finish-line input{height:22px!important;min-height:22px!important;border-radius:6px!important;background:#fff!important;border:1px solid #edf2f7!important}
#bomTable .finish-line .finishCost,#bomTable .finish-line .finishCost2{width:62px!important;text-align:right!important;font-weight:800!important;background:#f8fafc!important;color:#334155!important}
#bomTable .subtotal{font-size:12px!important;font-weight:800!important}
#bomTable th[data-col="finish"],#bomTable td.col-finish{min-width:185px!important;width:185px}
#bomTable th[data-col="spec"],#bomTable td.col-spec{min-width:420px!important}
#bomTable th[data-col="name"],#bomTable td.col-name{width:260px!important;min-width:260px!important}
#bomTable th[data-col="category"],#bomTable td.col-category{width:110px!important;min-width:110px!important}
#bomTable th[data-col="qty"],#bomTable th[data-col="process"],#bomTable th[data-col="price"],#bomTable th[data-col="subtotal"]{width:82px!important;min-width:82px!important}


/* V70：表面处理改为单行 + 表格属性弹窗，避免全部行被第二次表面处理拉高 */
#bomTable td{padding:3px 4px!important}
#bomTable th{padding:6px 5px!important}
#bomTable td textarea.cell-text{min-height:22px!important;padding:2px 4px!important;line-height:1.18!important;font-size:12.5px!important}
#bomTable td input{height:22px!important;min-height:22px!important;padding:2px 4px!important;font-size:12.5px!important;border-radius:6px!important}
#bomTable .finish-cell{display:grid!important;grid-template-columns:minmax(88px,1fr) 28px!important;gap:3px!important;align-items:center!important}
#bomTable .finish-stack{display:grid!important;grid-template-rows:auto auto!important;gap:2px!important;min-width:0!important}
#bomTable .finish-row{display:grid!important;grid-template-columns:minmax(62px,1fr) 42px!important;gap:3px!important;align-items:center!important}
#bomTable .finish-one .finish2-row{display:none!important}
#bomTable .finish-cell input{height:21px!important;min-height:21px!important;border-radius:5px!important;padding:1px 4px!important;font-size:12px!important;line-height:18px!important;background:#fff!important;border:1px solid #edf2f7!important}
#bomTable .finish-cell .finishCost,#bomTable .finish-cell .finishCost2{width:42px!important;text-align:right!important;font-weight:800!important;background:#f8fafc!important;color:#334155!important}
#bomTable th[data-col="finish"],#bomTable td.col-finish{min-width:150px!important;width:150px!important}
.finish-toggle-btn{height:24px!important;width:28px!important;min-width:28px!important;padding:0!important;border-radius:7px!important;border:1px solid #cbd5e1!important;background:#fff!important;color:#334155!important;font-size:12px!important;font-weight:900!important;white-space:nowrap!important;line-height:22px!important}
.finish-toggle-btn.has2{background:#eff6ff!important;border-color:#93c5fd!important;color:#1d4ed8!important}
.finish-attr-mask{position:fixed;inset:0;background:rgba(15,23,42,.35);z-index:1000001;display:none;align-items:center;justify-content:center;padding:18px}
.finish-attr-box{width:min(460px,96vw);background:#fff;border:1px solid #dbeafe;border-radius:18px;box-shadow:0 24px 70px rgba(15,23,42,.28);padding:16px}
.finish-attr-box h3{margin:0 0 8px;font-size:17px}.finish-attr-box p{margin:0 0 12px;color:#64748b;font-size:12px;line-height:1.5}
.finish-attr-grid{display:grid;grid-template-columns:1fr 92px;gap:8px;margin-bottom:8px}.finish-attr-grid label{display:flex;flex-direction:column;gap:5px;font-size:12px;color:#64748b}.finish-attr-grid input{width:100%}
.finish-attr-actions{display:flex;justify-content:flex-end;gap:8px;margin-top:12px}



/* V72：数字列压缩 + 表格列属性 + 拖动列宽真正生效 */
#editPage.list-collapsed{grid-template-columns:42px minmax(0,1fr)!important;gap:10px!important}
#editPage.list-collapsed>aside.card{padding:8px 6px!important;overflow:hidden!important}
#editPage.list-collapsed>aside.card .filters,#editPage.list-collapsed>aside.card .project-list,#editPage.list-collapsed>aside.card #listCount{display:none!important}
#editPage.list-collapsed>aside.card .side-title{writing-mode:vertical-rl;gap:8px;justify-content:flex-start;align-items:center;margin:0 auto!important}
#editPage.list-collapsed>aside.card .side-title b{font-size:12px!important;letter-spacing:1px}
#editPage.list-collapsed>aside.card .side-title button:not(.list-toggle-btn){display:none!important}
.list-toggle-btn{height:28px!important;min-width:28px!important;padding:0 8px!important;border-radius:8px!important;background:#fff!important;color:#334155!important;border:1px solid #cbd5e1!important;font-weight:900!important}
.table-tool-btn{height:28px!important;min-width:34px!important;padding:0 9px!important;border-radius:8px!important;background:#fff!important;color:#334155!important;border:1px solid #cbd5e1!important;font-size:12px!important;font-weight:900!important}
#bomTable{table-layout:fixed!important;min-width:980px!important}
#bomTable th[data-col="no"],#bomTable td.col-no{width:46px!important;min-width:46px!important}
#bomTable th[data-col="qty"],#bomTable td.col-qty{width:54px!important;min-width:54px!important}
#bomTable th[data-col="process"],#bomTable td.col-process{width:62px!important;min-width:62px!important}
#bomTable th[data-col="price"],#bomTable td.col-price{width:64px!important;min-width:64px!important}
#bomTable th[data-col="subtotal"],#bomTable td.col-subtotal{width:68px!important;min-width:68px!important}
#bomTable th[data-col="action"],#bomTable td.col-action{width:58px!important;min-width:58px!important}
#bomTable th[data-col="category"],#bomTable td.col-category{width:90px!important;min-width:90px!important}
#bomTable th[data-col="finish"],#bomTable td.col-finish{width:142px!important;min-width:142px!important}
#bomTable td.col-qty input,#bomTable td.col-process input,#bomTable td.col-price input,#bomTable td.col-subtotal{font-size:12px!important;padding-left:2px!important;padding-right:2px!important;text-align:right!important}
#bomTable th[data-col="qty"],#bomTable th[data-col="price"],#bomTable th[data-col="process"],#bomTable th[data-col="subtotal"]{text-align:center!important}
#bomTable td.col-action .row-actions{gap:2px!important}.row-act{min-width:21px!important;width:21px!important}
#bomTable .finish-cell{grid-template-columns:minmax(86px,1fr) 24px!important}.finish-toggle-btn{width:24px!important;min-width:24px!important}
#bomTable .finish-row{grid-template-columns:minmax(54px,1fr) 36px!important}.finishCost,.finishCost2{width:36px!important}
.col-hidden{display:none!important}
.col-panel-mask{position:fixed;inset:0;background:rgba(15,23,42,.28);z-index:1000002;display:none;align-items:flex-start;justify-content:flex-end;padding:88px 22px 22px}
.col-panel{width:260px;background:#fff;border:1px solid #dbeafe;border-radius:16px;box-shadow:0 24px 70px rgba(15,23,42,.25);padding:13px}
.col-panel h3{margin:0 0 10px;font-size:16px}.col-panel p{margin:0 0 8px;color:#64748b;font-size:12px;line-height:1.45}
.col-checks{display:grid;grid-template-columns:1fr 1fr;gap:6px 8px;margin:10px 0}.col-checks label{display:flex;align-items:center;gap:5px;font-size:12px;font-weight:800;color:#334155;white-space:nowrap}
.col-panel-actions{display:flex;gap:8px;justify-content:flex-end;margin-top:10px}.col-panel-actions button{height:30px;padding:0 10px!important;border-radius:8px!important;font-size:12px!important}


/* V73：导航模块 + 登录保持一天 + 表面处理数字不遮挡 */
.module-nav{display:flex;gap:7px;flex-wrap:wrap;align-items:center;margin-top:8px;padding:7px 0 0;border-top:1px dashed #e2e8f0}
.module-nav button{height:28px;padding:0 10px!important;border-radius:999px!important;background:#f8fafc!important;color:#334155!important;border:1px solid #dbe3ef!important;font-size:12px!important;font-weight:900!important}
.module-nav button.home{background:#0f172a!important;color:#fff!important;border-color:#0f172a!important}
.module-nav button.active{background:#2563eb!important;color:#fff!important;border-color:#2563eb!important}
#bomTable{font-size:12px!important}
#bomTable th,#bomTable td{padding:3px 4px!important}
#bomTable th[data-col="qty"],#bomTable td.col-qty{width:48px!important;min-width:48px!important}
#bomTable th[data-col="process"],#bomTable td.col-process{width:54px!important;min-width:54px!important}
#bomTable th[data-col="price"],#bomTable td.col-price{width:58px!important;min-width:58px!important}
#bomTable th[data-col="subtotal"],#bomTable td.col-subtotal{width:62px!important;min-width:62px!important}
#bomTable td.col-qty input,#bomTable td.col-process input,#bomTable td.col-price input{height:24px!important;min-height:24px!important;font-size:13px!important;font-weight:800!important;padding:1px 2px!important}
#bomTable th[data-col="finish"],#bomTable td.col-finish{width:166px!important;min-width:166px!important}
#bomTable .finish-cell{grid-template-columns:minmax(112px,1fr) 25px!important;gap:3px!important}
#bomTable .finish-stack{gap:2px!important}
#bomTable .finish-row{grid-template-columns:minmax(60px,1fr) 50px!important;gap:3px!important}
#bomTable .finish-cell input{height:22px!important;min-height:22px!important;font-size:12px!important;line-height:18px!important;padding:1px 4px!important;overflow:visible!important}
#bomTable .finish-cell .finishCost,#bomTable .finish-cell .finishCost2{width:50px!important;min-width:50px!important;max-width:50px!important;text-align:right!important;padding-left:2px!important;padding-right:3px!important}
.finish-toggle-btn{width:25px!important;min-width:25px!important;height:25px!important;line-height:23px!important}
@media(max-width:900px){.module-nav{gap:5px}.module-nav button{font-size:11px!important;padding:0 8px!important}}



/* V74：成本总表/明细库 增加列表/大图/中图/小图与全功能筛选 */
.lib-head{display:flex;justify-content:space-between;align-items:flex-start;gap:12px;flex-wrap:wrap;margin-bottom:10px}.lib-head h2{margin:0}.lib-view-pills{display:flex;gap:6px;flex-wrap:wrap}.lib-view-pills button{height:30px;padding:0 11px!important;border-radius:999px!important;background:#fff!important;color:#334155!important;border:1px solid #dbe3ef!important;font-size:12px!important;font-weight:900!important}.lib-view-pills button.active{background:#2563eb!important;color:#fff!important;border-color:#2563eb!important}
.lib-toolbar.v74{display:grid!important;grid-template-columns:1.4fr .7fr .7fr .7fr .7fr .7fr .7fr .7fr .7fr .7fr .7fr auto!important;gap:8px!important;margin-bottom:10px!important}.lib-toolbar.v74 input,.lib-toolbar.v74 select{height:38px;min-width:0}.lib-filter-more{display:none;grid-template-columns:repeat(8,minmax(110px,1fr));gap:8px;margin:0 0 10px}.lib-filter-more.show{display:grid}.lib-tools{display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin:8px 0 10px}.lib-tools .hint{margin-left:auto}.lib-stats{display:grid;grid-template-columns:repeat(5,minmax(120px,1fr));gap:8px;margin:8px 0 10px}.lib-stat{border:1px solid #e5e7eb;background:#f8fafc;border-radius:12px;padding:9px 10px}.lib-stat span{display:block;color:#64748b;font-size:11px}.lib-stat b{font-size:17px;color:#111827}.lib-list-table-wrap{overflow:auto;border:1px solid #e5e7eb;border-radius:14px;background:#fff}.lib-list-table{border:0;border-radius:0;min-width:1120px}.lib-list-table th{position:sticky;top:0;z-index:2}.lib-imgcell{display:flex;gap:10px;align-items:center}.lib-thumb{width:54px;height:54px;border-radius:12px;border:1px solid #dbe3ef;background:#f8fafc;object-fit:cover;flex:0 0 auto}.lib-thumb.empty{display:flex;align-items:center;justify-content:center;font-weight:900;color:#64748b;background:linear-gradient(135deg,#f8fafc,#eaf2ff)}.lib-title b{font-size:14px}.lib-title small{display:block;color:#64748b;margin-top:3px;line-height:1.35}.lib-cost{font-weight:900;color:#b91c1c}.lib-group-title{display:flex;justify-content:space-between;align-items:center;margin:14px 0 8px;padding:9px 12px;border:1px solid #e5e7eb;background:#f8fafc;border-radius:12px}.lib-card-grid{display:grid;gap:12px}.lib-card-grid.large{grid-template-columns:repeat(auto-fill,minmax(250px,1fr))}.lib-card-grid.medium{grid-template-columns:repeat(auto-fill,minmax(190px,1fr))}.lib-card-grid.small{grid-template-columns:repeat(auto-fill,minmax(135px,1fr));gap:8px}.lib-card{border:1px solid #e5e7eb;background:#fff;border-radius:16px;overflow:hidden;box-shadow:0 2px 10px rgba(15,23,42,.04)}.lib-card-img{width:100%;aspect-ratio:1.15/1;background:#f8fafc;border-bottom:1px solid #e5e7eb;display:flex;align-items:center;justify-content:center;overflow:hidden}.lib-card-img img{width:100%;height:100%;object-fit:cover}.lib-card-img.empty{font-size:32px;font-weight:900;color:#64748b;background:linear-gradient(135deg,#f8fafc,#eaf2ff)}.lib-card-body{padding:10px 11px}.lib-card-name{font-size:15px;font-weight:900;line-height:1.25;color:#0f172a}.lib-card-meta{font-size:12px;color:#64748b;line-height:1.45;margin-top:5px}.lib-card-foot{display:flex;justify-content:space-between;align-items:center;gap:6px;margin-top:8px}.lib-card.small-card .lib-card-body{padding:8px}.lib-card.small-card .lib-card-name{font-size:12px}.lib-card.small-card .lib-card-meta{font-size:10px}.lib-card.small-card .lib-cost{font-size:12px}.lib-card.small-card button{height:26px;padding:0 8px!important;font-size:11px!important}.lib-empty{padding:28px;text-align:center;color:#64748b;border:1px dashed #cbd5e1;border-radius:14px;background:#f8fafc}.lib-badge{display:inline-flex;align-items:center;border:1px solid #e2e8f0;background:#f8fafc;border-radius:999px;padding:2px 7px;font-size:11px;color:#475569;margin-right:4px;white-space:nowrap}
@media(max-width:1300px){.lib-toolbar.v74{grid-template-columns:1.4fr 1fr 1fr 1fr 1fr 1fr!important}.lib-filter-more{grid-template-columns:repeat(4,1fr)}}@media(max-width:760px){.lib-toolbar.v74{grid-template-columns:1fr 1fr!important}.lib-filter-more{grid-template-columns:1fr 1fr}.lib-stats{grid-template-columns:1fr 1fr}.lib-card-grid.large,.lib-card-grid.medium{grid-template-columns:repeat(auto-fill,minmax(160px,1fr))}.lib-card-grid.small{grid-template-columns:repeat(auto-fill,minmax(115px,1fr))}}



/* V74.3：顶部导航可折叠，只改前端显示，不影响数据 */
.header-title-row{display:flex;justify-content:space-between;align-items:flex-start;gap:12px}
.header-title-main{min-width:0}.header-fold-btn{height:30px!important;padding:0 12px!important;border-radius:999px!important;background:#fff!important;color:#334155!important;border:1px solid #dbe3ef!important;font-size:12px!important;font-weight:900!important;white-space:nowrap}
body.bom-header-collapsed header .brand-en,body.bom-header-collapsed header .brand-sub,body.bom-header-collapsed header .topbar,body.bom-header-collapsed header .module-nav,body.bom-header-collapsed header .tabs{display:none!important}
body.bom-header-collapsed header .wrap{padding-top:6px!important;padding-bottom:6px!important}
body.bom-header-collapsed header .brand-cn{font-size:16px!important;line-height:1.2!important;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
body.bom-header-collapsed .header-title-row{align-items:center!important}
body.bom-header-collapsed .header-fold-btn{height:26px!important;padding:0 10px!important}
body.bom-header-collapsed main.wrap{padding-top:10px!important}
@media(max-width:640px){.header-title-row{align-items:center}.brand-cn{font-size:18px}.header-fold-btn{padding:0 9px!important}}



/* V75：共享物料选料弹窗，物料名称后面三点打开；只改前端选料体验 */
.name-pick-wrap{display:grid!important;grid-template-columns:minmax(0,1fr) 24px!important;gap:3px!important;align-items:stretch!important}
.name-pick-wrap textarea.name{min-width:0!important;width:100%!important}
.mat-pick-btn{width:24px!important;min-width:24px!important;height:24px!important;padding:0!important;border-radius:7px!important;background:#fff!important;color:#334155!important;border:1px solid #cbd5e1!important;font-size:16px!important;font-weight:900!important;line-height:20px!important}
.mat-pick-btn:hover{background:#eff6ff!important;border-color:#93c5fd!important;color:#1d4ed8!important}
.mat-pick-mask{position:fixed;inset:0;background:rgba(15,23,42,.34);z-index:1000004;display:none;align-items:center;justify-content:center;padding:18px}
.mat-pick-box{width:min(1080px,96vw);max-height:92vh;background:#fff;border:1px solid #dbeafe;border-radius:20px;box-shadow:0 28px 80px rgba(15,23,42,.28);display:flex;flex-direction:column;overflow:hidden}
.mat-pick-head{display:flex;justify-content:space-between;align-items:flex-start;gap:12px;padding:14px 16px;border-bottom:1px solid #e5e7eb;background:#f8fbff}
.mat-pick-head h3{margin:0;font-size:17px}.mat-pick-head p{margin:4px 0 0;color:#64748b;font-size:12px;line-height:1.45}.mat-pick-head .rowhint{font-weight:900;color:#1d4ed8}
.mat-pick-body{padding:12px 14px;overflow:auto}.mat-pick-filters{display:grid;grid-template-columns:1.5fr .8fr .8fr .8fr .8fr auto;gap:8px;align-items:end;margin-bottom:10px}.mat-pick-filters label{display:grid;gap:4px;font-size:11px;color:#64748b;font-weight:900}.mat-pick-filters input,.mat-pick-filters select{height:34px;border-radius:9px;padding:6px 8px;min-width:0}
.mat-pick-tools{display:flex;gap:7px;align-items:center;flex-wrap:wrap;margin:0 0 10px}.mat-pick-chip{height:27px;padding:0 9px!important;border-radius:999px!important;border:1px solid #dbe3ef!important;background:#fff!important;color:#334155!important;font-size:12px!important;font-weight:900!important}.mat-pick-chip.active{background:#2563eb!important;color:#fff!important;border-color:#2563eb!important}.mat-pick-summary{margin-left:auto;color:#64748b;font-size:12px;font-weight:800}
.mat-pick-list{border:1px solid #e5e7eb;border-radius:14px;overflow:hidden;background:#fff}.mat-pick-row{display:grid;grid-template-columns:54px minmax(0,1fr) 130px 88px;gap:10px;align-items:center;padding:8px 10px;border-bottom:1px solid #edf2f7;cursor:pointer}.mat-pick-row:last-child{border-bottom:0}.mat-pick-row:hover{background:#eff6ff}.mat-pick-thumb{width:46px;height:46px;border-radius:10px;background:#f1f5f9;border:1px solid #dbe3ef;display:flex;align-items:center;justify-content:center;font-weight:1000;color:#64748b;overflow:hidden}.mat-pick-thumb img{width:100%;height:100%;object-fit:cover}.mat-pick-title{font-weight:1000;color:#0f172a;font-size:13.5px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.mat-pick-sub{font-size:12px;color:#64748b;line-height:1.4;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.mat-pick-price{font-weight:1000;color:#b91c1c;text-align:right}.mat-pick-empty{padding:26px;text-align:center;color:#64748b;font-weight:900}.mat-pick-tag{display:inline-flex;border:1px solid #e2e8f0;background:#f8fafc;border-radius:999px;padding:2px 7px;margin-right:4px;font-size:11px;color:#475569;font-weight:900}
@media(max-width:820px){.mat-pick-filters{grid-template-columns:1fr 1fr}.mat-pick-row{grid-template-columns:44px minmax(0,1fr);}.mat-pick-price,.mat-pick-row button{grid-column:2/3;text-align:left}.mat-pick-summary{margin-left:0;width:100%}}


/* V76：共享物料库新增/编辑改为弹窗，增加按创建时间筛选与新增后定位 */
.material-add-bar{display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin:10px 0 12px}
.material-add-bar .spacer{flex:1}.mat-date-filter{min-width:126px!important}
.mat-editor-mask{position:fixed;inset:0;background:rgba(15,23,42,.38);z-index:1000005;display:none;align-items:center;justify-content:center;padding:18px}
.mat-editor-box{width:min(920px,96vw);max-height:92vh;background:#fff;border:1px solid #dbeafe;border-radius:20px;box-shadow:0 28px 80px rgba(15,23,42,.28);overflow:hidden;display:flex;flex-direction:column}
.mat-editor-head{display:flex;justify-content:space-between;align-items:center;gap:12px;padding:14px 16px;border-bottom:1px solid #e5e7eb;background:#f8fbff}.mat-editor-head h3{margin:0;font-size:17px}.mat-editor-head p{margin:4px 0 0;color:#64748b;font-size:12px}.mat-editor-body{padding:14px 16px;overflow:auto}
.mat-editor-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px}.mat-editor-grid label{display:grid;gap:5px;font-size:12px;color:#64748b;font-weight:900}.mat-editor-grid input,.mat-editor-grid select,.mat-editor-grid textarea{width:100%;min-width:0}.mat-editor-grid textarea{min-height:74px}.mat-editor-grid .wide2{grid-column:span 2}.mat-editor-grid .wide3{grid-column:1/-1}.mat-editor-foot{display:flex;justify-content:flex-end;gap:8px;align-items:center;padding:12px 16px;border-top:1px solid #e5e7eb;background:#fff}.mat-row-focus td{animation:matFlash 1.8s ease-in-out 0s 2;background:#fff7ed!important}@keyframes matFlash{0%{background:#dbeafe}50%{background:#fff7ed}100%{background:#fff}}
@media(max-width:820px){.mat-editor-grid{grid-template-columns:1fr 1fr}.mat-editor-grid .wide2,.mat-editor-grid .wide3{grid-column:1/-1}}@media(max-width:560px){.mat-editor-grid{grid-template-columns:1fr}.material-add-bar .spacer{display:none}}

/* V78.1：共享物料三字段重复拦截 + 类似件提醒弹窗 */
.mat-similar-mask{position:fixed;inset:0;background:rgba(15,23,42,.46);z-index:1000008;display:none;align-items:center;justify-content:center;padding:18px;backdrop-filter:blur(6px)}
.mat-similar-box{width:min(820px,96vw);max-height:92vh;background:#fff;border:1px solid #fed7aa;border-radius:20px;box-shadow:0 28px 80px rgba(15,23,42,.30);overflow:hidden;display:flex;flex-direction:column}
.mat-similar-head{padding:14px 16px;border-bottom:1px solid #fed7aa;background:linear-gradient(180deg,#fff7ed,#fff);display:flex;justify-content:space-between;gap:12px;align-items:flex-start}.mat-similar-head h3{margin:0;color:#9a3412;font-size:18px}.mat-similar-head p{margin:5px 0 0;color:#92400e;font-size:12px;font-weight:900;line-height:1.55}
.mat-similar-body{padding:13px 16px;overflow:auto}.mat-similar-lead{border:1px solid #fed7aa;background:#fffbeb;color:#92400e;border-radius:13px;padding:10px 12px;font-size:13px;font-weight:900;line-height:1.55;margin-bottom:10px}.mat-similar-list{border:1px solid #e5e7eb;border-radius:14px;background:#fff;overflow:hidden}.mat-similar-row{display:grid;grid-template-columns:minmax(0,1fr) 110px;gap:10px;align-items:center;padding:10px 12px;border-bottom:1px solid #edf2f7}.mat-similar-row:last-child{border-bottom:0}.mat-similar-title{font-weight:1000;color:#111827;font-size:14px;line-height:1.35}.mat-similar-sub{font-size:12px;color:#64748b;line-height:1.55;margin-top:4px}.mat-similar-score{font-weight:1000;color:#b91c1c;text-align:right;white-space:nowrap}.mat-similar-empty{padding:20px;text-align:center;color:#64748b;font-weight:900}.mat-similar-foot{padding:12px 16px;border-top:1px solid #e5e7eb;background:#fff;display:flex;justify-content:flex-end;gap:8px;align-items:center}.mat-similar-foot .hint{margin-right:auto}.mat-similar-danger{border-color:#fecaca!important}.mat-similar-danger .mat-similar-head{background:linear-gradient(180deg,#fef2f2,#fff);border-bottom-color:#fecaca}.mat-similar-danger .mat-similar-head h3{color:#b91c1c}.mat-similar-danger .mat-similar-lead{border-color:#fecaca;background:#fef2f2;color:#991b1b}
@media(max-width:720px){.mat-similar-row{grid-template-columns:1fr}.mat-similar-score{text-align:left}.mat-similar-foot{flex-wrap:wrap}.mat-similar-foot .hint{width:100%}}



/* V77：Excel 强导出/导入，支持 BOM、成本总表、物料库；导入带预览与模式选择 */
.excel-modal-mask{position:fixed;inset:0;z-index:1000005;background:rgba(15,23,42,.48);display:none;align-items:center;justify-content:center;padding:18px;backdrop-filter:blur(6px)}
.excel-modal-box{width:min(980px,96vw);max-height:92vh;background:#fff;border:1px solid #dbeafe;border-radius:18px;box-shadow:0 28px 80px rgba(15,23,42,.28);display:flex;flex-direction:column;overflow:hidden}
.excel-modal-head{display:flex;justify-content:space-between;gap:12px;align-items:flex-start;padding:14px 16px;border-bottom:1px solid #e5e7eb;background:#f8fbff}.excel-modal-head h3{margin:0;font-size:18px}.excel-modal-head p{margin:5px 0 0;color:#64748b;font-size:12px}
.excel-modal-body{padding:14px 16px;overflow:auto}.excel-import-grid{display:grid;grid-template-columns:1.1fr .8fr .8fr auto;gap:8px;align-items:end;margin-bottom:10px}.excel-import-grid label{display:grid;gap:5px;font-size:12px;color:#64748b;font-weight:900}.excel-import-grid input,.excel-import-grid select{width:100%;min-width:0}.excel-paste{width:100%;min-height:96px;border:1px dashed #cbd5e1;border-radius:12px;padding:10px;margin:8px 0 10px;font-size:12px}.excel-preview-wrap{border:1px solid #e5e7eb;border-radius:14px;overflow:auto;max-height:300px;background:#fff}.excel-preview-table{border:0;border-radius:0;min-width:760px;font-size:12px}.excel-preview-table th{position:sticky;top:0;background:#f8fafc;z-index:2}.excel-modal-foot{display:flex;justify-content:flex-end;gap:8px;align-items:center;padding:12px 16px;border-top:1px solid #e5e7eb;background:#fff}.excel-status{margin-right:auto;color:#64748b;font-size:12px;font-weight:800}.excel-export-menu{display:inline-flex;gap:6px;flex-wrap:wrap}
@media(max-width:760px){.excel-import-grid{grid-template-columns:1fr 1fr}.excel-modal-box{max-height:95vh}}

/* V76.4：共享物料库分页取消内部竖向滚动条；每页选多少就直接展开多少行，使用页面滚动条 */
#materialsPage .material-list{max-height:none!important;overflow-y:visible!important;overflow-x:auto!important;}
#materialsPage .material-list table{margin-bottom:0;}


/* V77.2：BOM表格多行内容自动撑开行高，避免规格/物料名称被裁掉 */
#bomTable tbody tr{height:auto!important;}
#bomTable tbody td{height:auto!important;min-height:0!important;vertical-align:top!important;overflow:visible!important;}
#bomTable tbody td.col-name,#bomTable tbody td.col-spec,#bomTable tbody td.col-category{white-space:normal!important;}
#bomTable tbody td textarea.cell-text{display:block!important;width:100%!important;height:auto;min-height:28px!important;max-height:none!important;line-height:1.38!important;white-space:pre-wrap!important;word-break:break-word!important;overflow-wrap:anywhere!important;overflow:hidden!important;box-sizing:border-box!important;}
#bomTable tbody td.col-name textarea.cell-text,#bomTable tbody td.col-spec textarea.cell-text{font-size:13px!important;}
#bomTable tbody .name-pick-wrap{align-items:start!important;}
#bomTable tbody .mat-pick-btn{margin-top:2px!important;align-self:start!important;}
#bomTable tbody td.col-no,#bomTable tbody td.col-qty,#bomTable tbody td.col-process,#bomTable tbody td.col-finish,#bomTable tbody td.col-price,#bomTable tbody td.col-subtotal,#bomTable tbody td.col-action{vertical-align:top!important;padding-top:7px!important;}


/* V77.3：修复窗口收窄后冻结列断层/重叠；类别、物料名称、规格列保持同一张表连续显示 */
#bomTable{border-collapse:separate!important;border-spacing:0!important;}
#bomTable thead th{overflow:hidden!important;background:#f9fafb!important;background-clip:padding-box!important;}
#bomTable tbody td{background:#fff;background-clip:padding-box!important;}
#bomTable tbody tr.plm-missing td{background:#fff7ed!important;}
#bomTable th[data-col="no"],#bomTable td.col-no,
#bomTable th[data-col="category"],#bomTable td.col-category,
#bomTable th[data-col="name"],#bomTable td.col-name{
  position:sticky!important;
  background-clip:padding-box!important;
  box-shadow:1px 0 0 var(--line)!important;
}
#bomTable th[data-col="no"],#bomTable td.col-no{left:0!important;z-index:34!important;}
#bomTable th[data-col="category"],#bomTable td.col-category{z-index:33!important;}
#bomTable th[data-col="name"],#bomTable td.col-name{z-index:32!important;}
#bomTable thead th[data-col="no"]{z-index:44!important;}
#bomTable thead th[data-col="category"]{z-index:43!important;}
#bomTable thead th[data-col="name"]{z-index:42!important;}
#bomTable td.col-category,#bomTable td.col-name,#bomTable td.col-spec{overflow:hidden!important;}
#bomTable td.col-category textarea.cell-text,#bomTable td.col-name textarea.cell-text,#bomTable td.col-spec textarea.cell-text{overflow:hidden!important;}
#bomTable td.col-category .cell-text:empty::before{content:'';}
.table-wrap{contain:layout paint;}

</style>
</head>
<body>
<div id="loginMask" class="login-mask" style="display:none">
  <div class="login-box">
    <div class="login-brand-cn">中山雅大光电有限公司</div>
    <div class="login-brand-en">Artdon Lighting Limited</div>
    <h2>BOM 成本管理系统登录</h2>
    <p class="hint">账号由管理员在“用户管理”授权。未授权用户不能访问 BOM。</p>
    <input id="loginUser" autocomplete="username" placeholder="用户名，例如 qiulei / admin">
    <input id="loginPass" autocomplete="current-password" type="password" placeholder="密码" onkeydown="if(event.key==='Enter')login()">
    <button class="ok" style="width:100%;margin-top:8px" onclick="login()">登录</button>
    <p id="loginError" class="login-error"></p>
    <p class="hint">请使用 PLM / 用户管理里的统一账号登录。BOM 权限在 users.php 中授权。</p>
  </div>
</div>

<header id="appHeader"><div class="wrap app-header-wrap">
  <div class="header-title-row">
    <div class="header-title-main">
      <div class="brand-cn">中山雅大光电有限公司</div>
      <div class="brand-en">Artdon Lighting Limited</div>
      <div class="brand-sub">BOM Cost Management System · 物料成本管理系统 V78.2 · HTTPS图片显示修复版</div>
    </div>
    <button type="button" id="headerFoldBtn" class="ghost header-fold-btn" onclick="toggleBomHeader()">折叠顶部</button>
  </div>
  <div class="topbar" style="margin-top:10px">
    <button class="ok" onclick="saveCurrent()">保存当前</button>
    <button class="ghost" onclick="loadAll()">刷新数据</button>
    <button class="ghost" onclick="window.print()">打印/PDF</button>
    <button class="ghost" onclick="goModule('bom_backup_restore.php')">备份/恢复</button>
    <span class="status" id="status">准备就绪</span>
    <span class="user-chip" id="meText">未登录</span>
    <button class="ghost" onclick="logout()">退出</button>
  </div>
  <div class="module-nav">
    <button class="home" onclick="goModule('index.php')">首页</button>
    <button onclick="goModule('crm.php')">CRM</button>
    <button onclick="goModule('mail.php')">邮箱</button>
    <button onclick="goModule('promotion.php')">推广</button>
    <button onclick="goModule('quotation.php')">报价</button>
    <button onclick="goModule('datasheet.php')">资料</button>
    <button onclick="goModule('dispatch_next.php')">派工</button>
    <button onclick="goModule('plm.php')">PLM</button>
    <button class="active" onclick="goModule('bom.php')">BOM</button>
    <button onclick="goModule('naming.php')">命名</button>
    <button onclick="goModule('crm.php#linkage')">重量</button>
  </div>
  <div class="tabs">
    <button id="tabDashboard" class="tab active" onclick="showPage('dashboard')">BOM总览</button>
    <button id="tabEdit" class="tab" onclick="showPage('edit')">编辑成本单</button>
    <button id="tabLibrary" class="tab" onclick="showPage('library')">成本总表 / 明细库</button>
    <button id="tabMaterials" class="tab" onclick="showPage('materials')">共享物料库</button>
    <button id="tabUsers" class="tab" onclick="showPage('users')">用户管理</button>
  </div>
</div></header>

<main class="wrap">
<section id="dashboardPage" class="dashboard">
  <div class="card">
    <div class="dashboard-hero">
      <div>
        <h2 class="dashboard-title">BOM 总览</h2>
        <div class="dashboard-sub">进入系统先查看近期 BOM。默认显示本月，也可以快速切换近 7 天、近 3 天，或用完整筛选查客户、型号、分类、时间范围。</div>
      </div>
      <div class="dash-actions">
        <div class="dash-view-pills">
          <button id="dashViewTable" class="active" onclick="setDashboardView('table')">表格</button>
          <button id="dashViewIcon" onclick="setDashboardView('icon')">图标</button>
          <button id="dashGroupToggle" onclick="toggleDashboardGroup()">分类平铺</button>
        </div>
        <button class="ok" onclick="newProjectFromDashboard()">新建 BOM</button>
        <button class="ghost" onclick="openNamingBomModal('create')">从命名型号新建</button>
        <button class="ghost" onclick="loadAll()">刷新</button>
        <button class="ghost" onclick="exportDashboardCSV()">导出当前筛选 CSV</button>
      </div>
    </div>
    <div class="range-pills">
      <button id="dashRangeMonth" class="active" onclick="setDashboardRange('month')">本月</button>
      <button id="dashRange7" onclick="setDashboardRange('7')">近 7 天</button>
      <button id="dashRange3" onclick="setDashboardRange('3')">近 3 天</button>
      <button id="dashRangeAll" onclick="setDashboardRange('all')">全部</button>
      <button id="dashRangeCustom" onclick="setDashboardRange('custom')">自定义时间</button>
    </div>
    <div class="dashboard-filters">
      <input id="dashKeyword" placeholder="搜索 BOM名称 / 客户 / 型号 / 物料" oninput="renderDashboard()">
      <select id="dashCustomer" onchange="renderDashboard()"></select>
      <select id="dashType" onchange="renderDashboard()"></select>
      <select id="dashTimeField" onchange="renderDashboard()"><option value="updatedAt">按最后保存</option><option value="createdAt">按创建时间</option></select>
      <select id="dashSort" onchange="renderDashboard()"><option value="updatedDesc">最后保存：新到旧</option><option value="createdDesc">创建时间：新到旧</option><option value="costDesc">总成本：高到低</option><option value="costAsc">总成本：低到高</option><option value="customerAsc">客户 A-Z</option><option value="modelAsc">型号 A-Z</option></select>
      <input id="dashStart" type="date" onchange="setDashboardRange('custom')">
      <input id="dashEnd" type="date" onchange="setDashboardRange('custom')">
      <button class="ghost" onclick="clearDashboardFilters()">清空筛选</button>
    </div>
    <div class="dash-stats">
      <div class="dash-stat"><span>当前筛选 BOM</span><b id="dashCount">0</b></div>
      <div class="dash-stat"><span>总成本合计</span><b id="dashTotalCost">0.00</b></div>
      <div class="dash-stat"><span>建议报价合计</span><b id="dashTotalQuote">0.00</b></div>
      <div class="dash-stat"><span>平均成本</span><b id="dashAvgCost">0.00</b></div>
    </div>
    <p class="hint" id="dashHint"></p>
    <div class="dash-pager" id="dashPagerTop"></div>
    <div class="dashboard-table-wrap" id="dashboardTableWrap">
      <table class="dashboard-table">
        <thead><tr><th>成本单</th><th>客户</th><th>型号</th><th>系列</th><th>物料行</th><th>总成本</th><th>建议报价</th><th>创建时间</th><th>最后保存</th><th>操作</th></tr></thead>
        <tbody id="dashboardTbody"></tbody>
      </table>
    </div>
    <div class="dash-icon-grid" id="dashboardIconGrid" hidden></div>
    <div class="dash-pager" id="dashPagerBottom"></div>
  </div>
</section>

<section id="editPage" class="grid hide">
<aside class="card">
<div class="side-title"><button class="list-toggle-btn" type="button" onclick="toggleBomList()" title="收起/展开左侧列表">⇔</button><b>成本单列表</b><button onclick="newProject()">新建</button></div>
<div class="filters">
<input id="search" placeholder="综合搜索" oninput="renderProjectList()" style="width:100%">
<input id="modelSearch" placeholder="按型号搜索" oninput="renderProjectList()" style="width:100%">
<select id="customerFilter" onchange="renderProjectList()" style="width:100%"></select>
<select id="sortMode" onchange="renderProjectList()" style="width:100%">
<option value="updatedDesc">最后保存时间：新到旧</option><option value="createdDesc">创建时间：新到旧</option><option value="createdAsc">创建时间：旧到新</option><option value="customerAsc">客户 A-Z</option><option value="modelAsc">型号 A-Z</option>
</select>
</div><p class="hint" id="listCount"></p><div class="project-list" id="projectList"></div>
</aside>
<section class="card editor-card">
<div class="editor-head">
<label>成本单名称 <input id="projectName" oninput="touch()" placeholder="如 AT-260601 BOM"></label>
<label>客户/项目 <input id="customer" oninput="touch()" placeholder="客户名或项目名"></label>
<label>产品型号 <input id="model" oninput="touch()" placeholder="型号"></label>
<label>成品图片 <div class="product-image-box"><img id="productImagePreview" class="product-image-preview"><div><button type="button" class="ghost" onclick="uploadProductImage()">上传成品图</button><button type="button" class="ghost" onclick="removeProductImage()">移除图片</button><input type="file" id="productImageInput" accept="image/*" style="display:none" onchange="readProductImage(event)"></div></div></label>
<label>产品分类 <div style="display:grid;grid-template-columns:1fr auto;gap:6px"><select id="productType" onchange="touch()"></select><button type="button" class="ghost" onclick="addProductType()">新增</button></div></label>
<label>币种 <select id="currency" onchange="touch()"><option value="RMB">RMB</option><option value="USD">USD</option><option value="AED">AED</option></select></label>
<label>创建时间 <input id="createdAt" readonly></label><label>最后保存时间 <input id="updatedAt" readonly></label><label>创建人 <input id="createdBy" readonly></label><label>最后修改人 <input id="updatedBy" readonly></label><label>人工费 <input id="labor" type="number" step="0.01" oninput="touch();calc()" value="0"></label><label>包装/其它 <input id="other" type="number" step="0.01" oninput="touch();calc()" value="0"></label>
</div>
<div class="actions"><button onclick="addRow()">添加材料</button><button class="ghost" onclick="openNamingBomModal('create')">从命名型号新建</button><button class="ghost" onclick="openNamingBomModal('bind')">绑定/更换型号</button><button class="ok" onclick="saveCurrent()">保存</button><button class="ghost" onclick="duplicateProject()">复制当前</button><button class="table-tool-btn" type="button" onclick="openColumnPanel()">列</button><button class="table-tool-btn" type="button" onclick="resetBomColumnWidths()">重宽</button><button class="ghost" onclick="exportCurrentBomExcel()">导Excel</button><button class="ghost" onclick="openExcelImport('bom')">导入</button><button class="danger" onclick="deleteProject()">删除</button><span class="hint">物料名称后点 … 选择共享物料；也可直接输入关键词搜索。</span></div>
<div class="table-wrap"><table id="bomTable"><thead><tr><th data-col="no">序号</th><th data-col="category">类别</th><th data-col="name">物料名称</th><th data-col="spec">规格/备注</th><th data-col="qty">数量</th><th data-col="process">加工费</th><th data-col="finish">表面处理 / 处理费</th><th data-col="price">单价</th><th data-col="subtotal">小计</th><th data-col="action">操作</th></tr></thead><tbody id="tbody"></tbody></table></div>
<textarea id="note" class="note" oninput="touch()" placeholder="备注"></textarea>
<div class="summary"><div class="sum-box"><span>材料成本</span><b id="matTotal">0.00</b></div><div class="sum-box"><span>人工费</span><b id="laborTotal">0.00</b></div><div class="sum-box"><span>包装/其它</span><b id="otherTotal">0.00</b></div><div class="sum-box"><span>总成本</span><b id="grandTotal">0.00</b></div><div class="sum-box"><span>建议报价</span><b id="suggestPrice">0.00</b></div><div class="sum-box"><span>利润金额</span><b id="profitAmount">0.00</b></div></div>
<div class="meta" style="margin-top:12px"><label>利润率/加价率 % <input id="profitRate" type="number" step="0.1" oninput="touch();calc()" value="30"></label><label>报价模式 <select id="quoteMode" onchange="touch();calc()"><option value="markup">加价率</option><option value="margin">毛利率</option></select></label><label>汇率/备用 <input id="exchange" type="number" step="0.0001" oninput="touch()" value="1"></label></div>
</section>
</section>

<section id="libraryPage" class="library hide"><div class="card">
  <div class="lib-head">
    <div><h2>成本总表 / 明细库</h2><p class="hint" style="margin:6px 0 0">支持列表、大图、中图、小图；可按客户、分类、型号、金额、日期、图片、物料行数筛选。</p></div>
    <div class="lib-view-pills">
      <button id="libViewList" onclick="setLibraryView('list')">列表</button>
      <button id="libViewLarge" onclick="setLibraryView('large')">大图</button>
      <button id="libViewMedium" onclick="setLibraryView('medium')">中图</button>
      <button id="libViewSmall" onclick="setLibraryView('small')">小图</button>
    </div>
  </div>
  <div class="lib-toolbar v74">
    <input id="libKeyword" placeholder="搜索：客户/型号/名称/物料/规格" oninput="renderLibrary()">
    <select id="libGroup" onchange="renderLibrary()"><option value="none">不分组</option><option value="customer" selected>按客户</option><option value="productType">按分类</option><option value="month">按月份</option><option value="modelPrefix">按型号前缀</option><option value="currency">按币种</option><option value="hasImage">按图片</option></select>
    <select id="libCustomer" onchange="renderLibrary()"></select>
    <select id="libType" onchange="renderLibrary()"></select>
    <select id="libCurrency" onchange="renderLibrary()"><option value="">全部币种</option><option value="RMB">RMB</option><option value="USD">USD</option><option value="AED">AED</option></select>
    <select id="libTimeField" onchange="renderLibrary()"><option value="updatedAt">按最后保存</option><option value="createdAt">按创建时间</option></select>
    <select id="libQuickRange" onchange="applyLibraryQuickRange()"><option value="all">全部时间</option><option value="month">本月</option><option value="7">近7天</option><option value="3">近3天</option><option value="custom">自定义</option></select>
    <select id="libImageFilter" onchange="renderLibrary()"><option value="">全部图片</option><option value="with">有成品图</option><option value="without">无成品图</option></select>
    <select id="libSort" onchange="renderLibrary()"><option value="updatedDesc">最后保存：新到旧</option><option value="createdDesc">创建时间：新到旧</option><option value="createdAsc">创建时间：旧到新</option><option value="costDesc">总成本：高到低</option><option value="costAsc">总成本：低到高</option><option value="customerAsc">客户 A-Z</option><option value="modelAsc">型号 A-Z</option><option value="rowsDesc">物料行：多到少</option></select>
    <button class="ghost" onclick="toggleLibraryMoreFilters()">更多筛选</button>
    <button class="ghost" onclick="exportLibraryExcel()">导Excel</button><button class="ghost" onclick="exportLibraryCSV()">CSV</button>
  </div>
  <div id="libFilterMore" class="lib-filter-more">
    <input id="libStart" type="date" onchange="$('libQuickRange').value='custom';renderLibrary()">
    <input id="libEnd" type="date" onchange="$('libQuickRange').value='custom';renderLibrary()">
    <input id="libCostMin" type="number" step="0.01" placeholder="最低成本" oninput="renderLibrary()">
    <input id="libCostMax" type="number" step="0.01" placeholder="最高成本" oninput="renderLibrary()">
    <input id="libRowsMin" type="number" step="1" placeholder="最少物料行" oninput="renderLibrary()">
    <input id="libRowsMax" type="number" step="1" placeholder="最多物料行" oninput="renderLibrary()">
    <input id="libModelPrefix" placeholder="型号前缀" oninput="renderLibrary()">
    <input id="libMaterialKeyword" placeholder="只搜物料明细" oninput="renderLibrary()">
  </div>
  <div class="lib-tools">
    <button class="ghost" onclick="clearLibraryFilters()">清空筛选</button>
    <button class="ghost" onclick="renderLibrary()">刷新当前</button>
    <span class="hint" id="libCount"></span>
  </div>
  <div class="lib-stats">
    <div class="lib-stat"><span>当前成本单</span><b id="libStatCount">0</b></div>
    <div class="lib-stat"><span>总成本合计</span><b id="libStatCost">0.00</b></div>
    <div class="lib-stat"><span>建议报价合计</span><b id="libStatQuote">0.00</b></div>
    <div class="lib-stat"><span>平均成本</span><b id="libStatAvg">0.00</b></div>
    <div class="lib-stat"><span>物料行合计</span><b id="libStatRows">0</b></div>
  </div>
  <div id="libraryList"></div>
</div></section>

<section id="materialsPage" class="library hide"><div class="card"><h2 style="margin-top:0">共享物料库</h2><p class="hint">这里建立芯片、电源、透镜、外壳、包装等常用物料。报价系统也会读取这个 MySQL 物料库。</p>
<div class="material-add-bar"><button class="ok" onclick="openMaterialEditor()">新增物料</button><button class="ghost" onclick="syncWeightProfilesToBom()">同步重量型材</button><button class="ghost" onclick="exportMaterialsExcel()">导Excel</button><button class="ghost" onclick="openExcelImport('materials')">导入</button><button class="ghost" onclick="downloadMaterialTemplateExcel()">模板</button><span class="spacer"></span><span class="hint">Excel导入导出，保存后定位。</span></div>
<div class="material-search-row"><input id="matSearch" placeholder="搜索物料名称/型号/规格/供应商" oninput="materialPage=1;renderMaterials()"><select id="matDateFilter" class="mat-date-filter" onchange="materialPage=1;renderMaterials()"><option value="">全部时间</option><option value="today">今天新增</option><option value="yesterday">昨天新增</option><option value="3">近 3 天新增</option><option value="7">近 7 天新增</option></select><select id="matFilterCategory" onchange="materialPage=1;renderMaterials()"></select><select id="matFilterBrand" onchange="materialPage=1;renderMaterials()"></select><select id="matFilterSupplier" onchange="materialPage=1;renderMaterials()"></select><input id="newMatCategory" placeholder="新增分类"><input id="newMatBrand" placeholder="新增品牌"><input id="newMatSupplier" placeholder="新增供应商"><button class="ghost" onclick="addMaterialCategory()">新增分类</button><button class="ghost" onclick="addMaterialBrand()">新增品牌</button><button class="ghost" onclick="addMaterialSupplier()">新增供应商</button><span class="hint" id="matCount"></span></div>
<div class="mat-pagebar"><div class="mat-pagebar-left"><span>每页</span><select id="matPageSize" onchange="setMaterialPageSize(this.value)"><option value="20">20</option><option value="50">50</option><option value="100">100</option><option value="200">200</option><option value="500">500</option></select><span id="matPageInfo">第 1 / 1 页</span></div><div class="mat-pagebar-right"><button class="ghost small" onclick="setMaterialPage(1)">首页</button><button class="ghost small" onclick="setMaterialPage(materialPage-1)">上页</button><input id="matPageInput" type="number" min="1" value="1" onchange="setMaterialPage(this.value)"><button class="ghost small" onclick="setMaterialPage(materialPage+1)">下页</button><button class="ghost small" onclick="setMaterialPage(materialTotalPages)">末页</button></div></div>
<div class="material-list"><table id="materialTable"><thead><tr><th data-col="image">图片</th><th data-col="category">分类</th><th data-col="brand">品牌</th><th data-col="name">物料名称</th><th data-col="model">型号</th><th data-col="spec">规格</th><th data-col="price">单价</th><th data-col="unit">单位</th><th data-col="supplier">供应商</th><th data-col="action">操作</th></tr></thead><tbody id="materialsTbody"></tbody></table></div>
</div></section>
<datalist id="supplierOptions"></datalist>
<datalist id="finishOptions"><option value="无处理"><option value="雾银"><option value="喷油黑"><option value="沙黑"><option value="沙白"><option value="砂白粉1026"><option value="砂黑粉3003"><option value="氧化银"><option value="阳极黑"><option value="阳极银"><option value="电镀铬"><option value="电镀枪色"><option value="拉丝银"><option value="拉丝黑"><option value="电泳黑"><option value="哑铬+光银油"><option value="红粉"><option value="定制"></datalist>

<div id="colPanelMask" class="col-panel-mask" onclick="if(event.target===this)closeColumnPanel()">
  <div class="col-panel">
    <h3>列</h3>
    <p>勾选显示；列宽直接拖表头右边。</p>
    <div class="col-checks" id="colChecks"></div>
    <div class="col-panel-actions">
      <button class="ghost" type="button" onclick="resetBomColumnVisibility()">全显</button>
      <button class="ghost" type="button" onclick="closeColumnPanel()">关闭</button>
    </div>
  </div>
</div>

<div id="finishAttrMask" class="finish-attr-mask" onclick="if(event.target===this)closeFinishAttr()">
  <div class="finish-attr-box">
    <h3>表格属性 · 表面处理</h3>
    <p>普通物料只在表格里显示第 1 次表面处理；需要两次处理的物料，在这里设置第 2 次，不再把所有行拉高。</p>
    <input id="finishAttrRow" type="hidden">
    <div class="finish-attr-grid">
      <label>第 1 次表面处理 <input id="finishAttrFinish1" list="finishOptions" placeholder="如 沙黑 / 电镀铬"></label>
      <label>费 1 <input id="finishAttrCost1" type="number" step="0.01" value="0"></label>
      <label>第 2 次表面处理 <input id="finishAttrFinish2" list="finishOptions" placeholder="没有就留空"></label>
      <label>费 2 <input id="finishAttrCost2" type="number" step="0.01" value="0"></label>
    </div>
    <div class="finish-attr-actions">
      <button class="ghost" type="button" onclick="closeFinishAttr()">取消</button>
      <button class="ok" type="button" onclick="saveFinishAttr()">保存表面处理</button>
    </div>
  </div>
</div>


<section id="usersPage" class="library hide"><div class="card"><h2 style="margin-top:0">统一用户管理 / 权限授权</h2><p class="hint">这里使用同一套 Office / PLM / CRM 账号：一个账号可以登录多个系统。账号新增、停用、权限调整请优先到统一 users.php 用户管理页面完成。</p>
  <div class="perm-help"><b>权限字段：</b><code>bom</code> 代表普通 BOM 权限；<code>bom_dashboard,bom_edit,bom_library,bom_materials</code> 可细分；<code>users</code> 可管理用户；<code>all</code> 拥有全部权限。工程同事建议：<code>bom</code> 或 <code>plm,bom</code>。</div>
  <div class="user-form">
    <input id="userId" type="hidden">
    <input id="userName" placeholder="用户名，如 engineer01">
    <input id="userDisplay" placeholder="显示名，如 工程-张三">
    <select id="userRole"><option value="engineer">engineer 工程</option><option value="sales">sales 业务</option><option value="manager">manager 经理</option><option value="boss">boss 管理员</option></select>
    <input id="userPerms" placeholder="权限，如 dashboard,edit,library,materials">
    <input id="userPass" type="password" placeholder="密码；编辑时留空=不改密码">
    <select id="userActive"><option value="1">启用</option><option value="0">停用</option></select>
    <button class="ok" onclick="saveUser()">保存用户</button>
    <button class="ghost" onclick="clearUserForm()">清空</button>
    <button class="ghost" onclick="loadUsers()">刷新</button>
  </div>
  <div class="table-wrap"><table class="user-table"><thead><tr><th>ID</th><th>用户名</th><th>显示名</th><th>角色</th><th>权限</th><th>状态</th><th>最后登录</th><th>操作</th></tr></thead><tbody id="usersTbody"></tbody></table></div>
</div></section>

</main>
<div id="namingBomModal" class="naming-bom-modal">
  <div class="naming-bom-box">
    <div class="naming-bom-head">
      <div>
        <h3>从命名型号生成 BOM</h3>
        <p>从型号命名系统读取型号、尺寸、图片、BOM 模板、灯头数量和默认物料模块，生成 BOM 草稿。</p>
      </div>
      <button class="ghost" onclick="closeNamingBomModal()">关闭</button>
    </div>
    <div class="naming-bom-body">
      <div class="naming-bom-filters">
        <label>关键词<input id="nb_kw" placeholder="型号 / 产品名 / 客户 / 备注"></label>
        <label>类别<input id="nb_category" placeholder="嵌入式 / 导轨"></label>
        <label>类型<input id="nb_item" placeholder="有边固定"></label>
        <label>前缀<input id="nb_prefix" placeholder="D1 / 51"></label>
        <label>尺寸<input id="nb_size" placeholder="075 / 200"></label>
        <label>状态<select id="nb_status"><option value="">全部</option><option>开发中</option><option>已确认</option><option>已量产</option><option>草稿</option></select></label>
        <button class="ok" onclick="searchNamingBom()">搜索</button>
      </div>
      <div class="topbar" style="position:static;border:0;padding:0;margin-bottom:10px">
        <label style="display:inline-flex;align-items:center;gap:6px;color:#475569;font-size:12px;font-weight:900"><input id="nb_bom_only" type="checkbox" checked> 只看允许生成 BOM</label>
        <select id="nb_limit" style="width:120px"><option value="50">50条</option><option value="100" selected>100条</option><option value="200">200条</option><option value="500">500条</option></select>
        <span class="hint" id="nb_summary">等待搜索</span>
      </div>
      <div id="namingBomList" class="naming-bom-list"><div class="naming-bom-empty">输入条件后搜索命名型号</div></div>
    </div>
  </div>
</div>


<div id="matPickMask" class="mat-pick-mask" onclick="if(event.target===this)closeMaterialPicker()">
  <div class="mat-pick-box">
    <div class="mat-pick-head">
      <div>
        <h3>选择共享物料</h3>
        <p>从共享物料库选择，自动带入名称、规格、单价。当前行：<span id="matPickRowHint" class="rowhint">-</span></p>
      </div>
      <button class="ghost" onclick="closeMaterialPicker()">关闭</button>
    </div>
    <div class="mat-pick-body">
      <div class="mat-pick-filters">
        <label>关键词<input id="mp_kw" placeholder="名称 / 型号 / 规格 / 品牌 / 供应商" oninput="renderMaterialPicker()"></label>
        <label>标准分类<select id="mp_group" onchange="renderMaterialPicker()"></select></label>
        <label>品牌<select id="mp_brand" onchange="renderMaterialPicker()"></select></label>
        <label>供应商<select id="mp_supplier" onchange="renderMaterialPicker()"></select></label>
        <label>排序<select id="mp_sort" onchange="renderMaterialPicker()"><option value="recent">最近/匹配优先</option><option value="priceAsc">单价低到高</option><option value="priceDesc">单价高到低</option><option value="nameAsc">名称 A-Z</option></select></label>
        <button class="ok" onclick="renderMaterialPicker()">搜索</button>
      </div>
      <div class="mat-pick-tools">
        <button class="mat-pick-chip" id="mp_chip_all" onclick="setMaterialPickerQuick('all')">全部</button>
        <button class="mat-pick-chip" id="mp_chip_recent" onclick="setMaterialPickerQuick('recent')">最近</button>
        <button class="mat-pick-chip" id="mp_chip_same" onclick="setMaterialPickerQuick('same')">同类</button>
        <span class="mat-pick-summary" id="mp_summary">等待选择</span>
      </div>
      <div id="matPickList" class="mat-pick-list"><div class="mat-pick-empty">点物料名称后面的 … 打开选料。</div></div>
    </div>
  </div>
</div>


<div id="excelImportMask" class="excel-modal-mask" onclick="if(event.target===this)closeExcelImport()">
  <div class="excel-modal-box">
    <div class="excel-modal-head">
      <div><h3 id="excelImportTitle">Excel 导入</h3><p id="excelImportSub">支持本系统导出的 .xls，也支持 Excel 另存的 CSV / 制表符文本。</p></div>
      <button class="ghost" onclick="closeExcelImport()">关闭</button>
    </div>
    <div class="excel-modal-body">
      <div class="excel-import-grid">
        <label>文件<input id="excelImportFile" type="file" accept=".csv,.txt,.xls,.xml,.xlsx" onchange="handleExcelImportFile(event)"></label>
        <label>导入方式<select id="excelImportMode"></select></label>
        <label>标题行<select id="excelHeaderMode"><option value="auto">自动识别</option><option value="first">第一行为标题</option></select></label>
        <button class="ok" onclick="previewExcelImport()">预览</button>
      </div>
      <textarea id="excelImportPaste" class="excel-paste" placeholder="也可以从 Excel 复制后直接粘贴到这里"></textarea>
      <div class="excel-status" id="excelImportStatus">等待文件或粘贴内容。</div>
      <div class="excel-preview-wrap"><table class="excel-preview-table"><thead id="excelPreviewHead"></thead><tbody id="excelPreviewBody"></tbody></table></div>
    </div>
    <div class="excel-modal-foot">
      <span class="excel-status" id="excelImportFoot">先预览，再导入。</span>
      <button class="ghost" onclick="closeExcelImport()">取消</button>
      <button class="ok" onclick="executeExcelImport()">确认导入</button>
    </div>
  </div>
</div>

<div id="matSuggestFloat" class="mat-suggest-float"></div>

<div id="matEditorMask" class="mat-editor-mask" onclick="if(event.target===this)closeMaterialEditor()">
  <div class="mat-editor-box">
    <div class="mat-editor-head">
      <div>
        <h3 id="matEditorTitle">新增物料</h3>
        <p id="matEditorSub">保存后定位。</p>
      </div>
      <button class="ghost" onclick="closeMaterialEditor()">关闭</button>
    </div>
    <div class="mat-editor-body">
      <div class="mat-editor-grid">
        <label>分类<select id="matCategory"></select></label>
        <label>品牌<select id="matBrand"></select></label>
        <label>供应商<input id="matSupplier" list="supplierOptions" placeholder="供应商"></label>
        <label class="wide2">物料名称<input id="matName" placeholder="物料名称，如 CREE 3030"></label>
        <label>型号/编码<input id="matModel" placeholder="型号/编码"></label>
        <label>单价<input id="matPrice" type="number" step="0.0001" placeholder="单价"></label>
        <label>单位<input id="matUnit" placeholder="单位" value="PCS"></label>
        <label>关键词<input id="matKeyword" placeholder="关键词"></label>
        <label class="wide2">规格/备注<textarea id="matSpec" placeholder="规格/备注"></textarea></label>
        <label>图片<input type="file" id="matImageFile" accept="image/*" onchange="readMaterialImage(event)"></label>
      </div>
    </div>
    <div class="mat-editor-foot">
      <span class="hint" id="matEditorHint">可连续新增。</span>
      <button class="ghost" onclick="clearMaterialForm(true)">清空</button>
      <button class="ghost" onclick="saveMaterial(true)">保存继续</button>
      <button class="ok" onclick="saveMaterial(false)">保存</button>
    </div>
  </div>
</div>

<div id="matSimilarMask" class="mat-similar-mask" onclick="if(event.target===this)closeMaterialSimilarModal()">
  <div id="matSimilarBox" class="mat-similar-box">
    <div class="mat-similar-head">
      <div>
        <h3 id="matSimilarTitle">发现类似物料</h3>
        <p id="matSimilarSub">新建物料前请确认，避免共享物料库重复。</p>
      </div>
      <button class="ghost" onclick="closeMaterialSimilarModal()">关闭</button>
    </div>
    <div class="mat-similar-body">
      <div id="matSimilarLead" class="mat-similar-lead"></div>
      <div id="matSimilarList" class="mat-similar-list"></div>
    </div>
    <div class="mat-similar-foot">
      <span class="hint" id="matSimilarHint">重复件不能保存；类似件确认不是同一个后可以继续保存。</span>
      <button class="ghost" onclick="closeMaterialSimilarModal()">返回修改</button>
      <button id="matSimilarSaveBtn" class="ok" onclick="confirmSaveMaterialAfterSimilar()">确认不是同一个，仍然保存</button>
    </div>
  </div>
</div>

<script>
const API='bom_api.php';
let projects=[],materials=[],lists={categories:[],brands:[],suppliers:[],productTypes:[],namingProductTypes:[]},currentId=null,editingMaterialId=null,currentMaterialImage='',currentPage='dashboard',firstBoot=true,dashboardRange='month',dashboardView='icon',dashboardGroup=false,dashboardPage=1,dashboardPageSize=24,lastDashboardRows=[],currentUser=null,currentCan={},userRows=[],libraryView=localStorage.getItem('bom_library_view_v74')||'list',lastLibraryRows=[],materialPickRowIndex=-1,materialPickMode='same',materialFocusId=null,materialPageSize=Number(localStorage.getItem('bom_material_page_size_v763')||50),materialPage=Number(localStorage.getItem('bom_material_page_v763')||1),materialTotalPages=1,materialPendingPayload=null,materialPendingContinue=false;
const BOM_PLACE_PAGE_KEY='bom_last_page_v762', BOM_PLACE_PROJECT_KEY='bom_last_project_v762';
function bomValidPage(p){return ['dashboard','edit','library','materials','users'].includes(String(p||''))}
function bomRememberPlace(){try{localStorage.setItem(BOM_PLACE_PAGE_KEY,bomValidPage(currentPage)?currentPage:'dashboard');if(currentId)localStorage.setItem(BOM_PLACE_PROJECT_KEY,currentId)}catch(e){}}
function bomRememberedPage(){return 'dashboard'}
function bomRememberedProject(){try{return localStorage.getItem(BOM_PLACE_PROJECT_KEY)||''}catch(e){return ''}}
function bomUrlProjectUid(){
  try{
    const qs=new URLSearchParams(location.search), hs=new URLSearchParams(String(location.hash||'').replace(/^#/,''));
    return qs.get('project_uid')||hs.get('project_uid')||'';
  }catch(e){return ''}
}
window.addEventListener('beforeunload',bomRememberPlace);
const $=id=>document.getElementById(id), uid=()=> 'BOM-'+Date.now().toString(36)+'-'+Math.random().toString(36).slice(2,7), money=n=>Number(n||0).toFixed(2), esc=s=>String(s??'').replaceAll('&','&amp;').replaceAll('<','&lt;').replaceAll('>','&gt;'), nowText=()=>new Date().toLocaleString('zh-CN',{hour12:false});

/* ARTDON_BOM_V77_7_IMAGE_DISPLAY_ONLY_START
 * 只修前端图片显示：按 naming_clean.php 验证方案显示官网同步图。
 * 不主动保存、不同步、不改 BOM 物料/成本/rows_json。
 */
function bomMediaEncodePath(path){
  path=String(path||'').replace(/\\/g,'/');
  return path.split('/').map(p=>{if(p==='')return '';try{return encodeURIComponent(decodeURIComponent(p));}catch(e){return encodeURIComponent(p);}}).join('/').replace(/%2F/ig,'/');
}
// V78.2：HTTPS 域名后图片基址修复。
// 官网同步图统一走 artdonlighting.com；命名系统本地图走当前访问域名 novlight.com，避免 https 页面加载 IP/http 图片失败。
const BOM_WEBSITE_IMAGE_BASE='https://artdonlighting.com';
function bomCurrentOrigin(){
  try{return (location.origin || (location.protocol+'//'+location.host)).replace(/\/+$/,'');}catch(e){return 'https://novlight.com';}
}
function bomWebsiteImageBase(){return BOM_WEBSITE_IMAGE_BASE.replace(/\/+$/,'');}
function bomPathWithBase(base,path,query=''){
  base=String(base||'').replace(/\/+$/,'');
  path=String(path||'').replace(/\\/g,'/');
  if(!path.startsWith('/'))path='/'+path.replace(/^\/+/, '');
  return base+bomMediaEncodePath(path)+(query||'');
}
function bomNormalizeMediaUrl(raw,imgBase=null){
  raw=String(raw||'').trim();
  if(!raw)return '';
  if(/^data:image\//i.test(raw)||/^blob:/i.test(raw))return raw;
  raw=raw.replace(/&amp;/g,'&');
  if(raw.includes('naming_media')||raw.includes('media_proxy')){
    try{const u=new URL(raw,location.href);const real=u.searchParams.get('u')||u.searchParams.get('url')||u.searchParams.get('src');if(real)raw=decodeURIComponent(real);}catch(e){}
  }
  const websiteBase=String(imgBase||bomWebsiteImageBase()).replace(/\/+$/,'') || bomWebsiteImageBase();
  const localBase=bomCurrentOrigin();
  if(/^https?:\/\//i.test(raw)){
    try{
      const u=new URL(raw,location.href), path=u.pathname||'', query=u.search||'';
      if(path.indexOf('/uploads/website/')===0)return bomPathWithBase(websiteBase,path,query);
      if(path.indexOf('/uploads/naming/')===0)return bomPathWithBase(localBase,path,query);
      // https 页面下，不再使用 http/IP 图片；只保留其它合法 https 外链。
      if(location.protocol==='https:' && u.protocol==='http:')return '';
      return raw;
    }catch(e){return raw;}
  }
  let clean=raw.replace(/[?#].*$/,'').replace(/\\/g,'/').replace(/^\/+/, '');
  let p=clean.indexOf('uploads/website/'); if(p>0)clean=clean.slice(p);
  p=clean.indexOf('uploads/naming/'); if(p>0)clean=clean.slice(p);
  if(clean.indexOf('uploads/website/')===0)return bomPathWithBase(websiteBase,clean);
  if(clean.indexOf('uploads/naming/')===0)return bomPathWithBase(localBase,clean);
  if(clean.indexOf('website/products/')===0)return bomPathWithBase(websiteBase,'uploads/'+clean);
  if(clean.indexOf('uploads/')===0)return bomPathWithBase(localBase,clean);
  return bomMediaEncodePath(raw);
}
function bomSafeJson(raw){try{return raw&&typeof raw==='string'?JSON.parse(raw):(raw&&typeof raw==='object'?raw:null)}catch(e){return null}}
function bomIsWebsiteObj(o){
  if(!o||typeof o!=='object')return false;
  const src=String(o.source_system||'').toLowerCase();
  if(['website','web','hongkong_web','hk_web','official_website','artdon_website'].includes(src))return true;
  return ['source_id','source_url','source_synced_at','web_series','web_size_name','web_dimensions','web_image_url','web_dimension_url','cover_image_url','dimension_image_url'].some(k=>String(o[k]||'').trim()!=='') || (String(o.remark||'').includes('官网')&&(String(o.remark||'').includes('同步')||String(o.remark||'').includes('自动读取')));
}
function bomPickObjectMedia(o,kind='image'){
  if(!o||typeof o!=='object')return '';
  const isWeb=bomIsWebsiteObj(o);
  const keys=kind==='drawing'
    ? (isWeb?['web_dimension_url','dimension_image_url','source_drawing_url','source_dimension_url','drawing_url','dimension_url','size_image_url','web_drawing_url','drawing_path']:['drawing_path','web_dimension_url','dimension_image_url','source_drawing_url','dimension_url'])
    : (isWeb?['web_image_url','cover_image_url','source_image_url','product_image','image_url','main_image','cover_image','cover_url','image_path']:['image_path','web_image_url','cover_image_url','source_image_url','image_url','main_image','cover_image']);
  for(const k of keys){const v=String(o[k]||'').trim();if(v)return bomNormalizeMediaUrl(v);}
  return '';
}
function bomProjectDisplayImage(p){
  if(!p)return '';
  const raw=String(p.product_image||'').trim();
  if(/^data:image\//i.test(raw))return raw;
  const linked=bomSafeJson(p.linked_json), snap=bomSafeJson(p.naming_snapshot_json);
  const src=String(p.product_image_display||'').trim() || String(p.image_display_url||'').trim() || bomPickObjectMedia(linked,'image') || bomPickObjectMedia(snap,'image') || raw;
  return bomNormalizeMediaUrl(src);
}
function bomNamingTypeFromObject(o){
  if(!o||typeof o!=='object')return '';
  const direct=String(o.web_series||o.series_name||o.product_name||o.website_display_name||o.series||'').trim();
  if(direct)return direct;
  const remark=String(o.remark||'');
  const m=remark.match(/系列[:：]\s*([^;；\n\r]+)/);
  if(m&&String(m[1]||'').trim())return String(m[1]).trim();
  return String(o.category||o.product_category||o.item_name||o.product_type||o.type_name||'').trim();
}
function bomProjectNamingTypeRaw(p){
  const snap=bomSafeJson(p?.naming_snapshot_json||p?.namingSnapshotJson);
  const linked=bomSafeJson(p?.linked_json||p?.linkedJson);
  return bomNamingTypeFromObject(snap)||bomNamingTypeFromObject(linked)||String(p?.product_type||p?.productType||'').trim()||'未分类';
}
function bomProjectNamingType(p){return bomProjectNamingTypeRaw(p)||'未分类'}
function bomAltFromPath(src,base){
  try{const u=new URL(src,location.href);return bomPathWithBase(base,u.pathname,u.search||'');}catch(e){return '';}
}
function bomImgTag(src,cls,emptyText){
  src=bomNormalizeMediaUrl(src);
  if(!src)return `<div class="${cls} empty">${esc(emptyText||'BOM')}</div>`;
  const alts=[];
  if(src.includes('/uploads/website/')){
    alts.push(bomAltFromPath(src,bomCurrentOrigin()));
    alts.push(bomAltFromPath(src,'https://novlight.com'));
  }
  if(src.includes('/uploads/naming/')){
    alts.push(bomAltFromPath(src,bomCurrentOrigin()));
  }
  const uniq=[...new Set(alts.filter(x=>x&&x!==src&&!/^http:\/\//i.test(x)))].slice(0,4);
  const data=uniq.map((x,i)=>` data-alt${i+1}="${esc(x)}"`).join('');
  return `<img class="${cls}" src="${esc(src)}"${data} onerror="bomImageFallback(this,'${esc(cls)}','${esc(emptyText||'BOM')}')">`;
}
function bomImageFallback(img,cls,txt){
  if(!img)return;
  let tried=Number(img.dataset.tried||0);
  for(let i=tried+1;i<=4;i++){
    const next=img.dataset['alt'+i];
    if(next && next!==img.src){img.dataset.tried=String(i);img.src=next;return;}
  }
  const d=document.createElement('div');d.className=(cls||'lib-thumb')+' empty';d.textContent=txt||'BOM';img.replaceWith(d);
}

function goModule(page){ if(!page)return; location.href='./'+page; }
function applyBomHeaderCollapse(){
  const collapsed = localStorage.getItem('bom_header_collapsed_v743') === '1';
  document.body.classList.toggle('bom-header-collapsed', collapsed);
  const btn = document.getElementById('headerFoldBtn');
  if(btn) btn.textContent = collapsed ? '展开顶部' : '折叠顶部';
}
function toggleBomHeader(){
  const next = !document.body.classList.contains('bom-header-collapsed');
  localStorage.setItem('bom_header_collapsed_v743', next ? '1' : '0');
  applyBomHeaderCollapse();
}

function bomSsoRedirect(){const back=location.pathname+location.search+location.hash;location.replace('login.php?redirect='+encodeURIComponent(back))}
async function api(action,data={}){try{const res=await fetch(API+'?action='+encodeURIComponent(action),{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(data),credentials:'same-origin',cache:'no-store'});const text=await res.text();let r;try{r=JSON.parse(text)}catch(e){if(res.status===401){bomSsoRedirect();return{ok:false,need_login:true,error:'登录已失效'}}return{ok:false,error:'接口返回不是JSON：'+text.slice(0,180)}}if(res.status===401||r.need_login||r.login_required||r.auth_required){bomSsoRedirect();return{ok:false,need_login:true,error:r.error||r.msg||'登录已失效'}}return r}catch(e){return{ok:false,error:e.message}}}
function hasPerm(p){return !!(currentCan&&currentCan[p]) || (currentUser&&['boss','admin'].includes(String(currentUser.role||'').toLowerCase()))}
function showLogin(on){const m=$('loginMask');if(m)m.style.display=on?'flex':'none'}

function normalizeBomRows(rows, rawJson){
  let out = rows;
  if((out==null || (Array.isArray(out) && out.length===0)) && rawJson){
    try{ out = JSON.parse(rawJson || '[]'); }catch(e){ out = []; }
  }
  if(out && !Array.isArray(out) && typeof out==='object') out = Object.values(out);
  if(!Array.isArray(out)) return [];
  return out.filter(r=>r && typeof r==='object').map(r=>({
    category: r.category ?? r.type ?? '',
    name: r.name ?? r.material_name ?? r.materialName ?? '',
    spec: r.spec ?? r.remark ?? r.note ?? '',
    qty: Number(r.qty ?? r.quantity ?? 0) || 0,
    process: Number(r.process ?? r.processCost ?? r.process_cost ?? 0) || 0,
    finish: r.finish ?? r.finish1 ?? r.surface ?? r.surface1 ?? '',
    finishCost: Number(r.finishCost ?? r.finish_cost ?? r.surfaceCost ?? r.surface_cost ?? 0) || 0,
    finish2: r.finish2 ?? r.surface2 ?? '',
    finishCost2: Number(r.finishCost2 ?? r.finish_cost2 ?? r.surfaceCost2 ?? r.surface_cost2 ?? 0) || 0,
    finishMode2: !!(r.finishMode2 || r.finish2 || r.surface2 || Number(r.finishCost2 ?? r.finish_cost2 ?? r.surfaceCost2 ?? 0) > 0),
    price: Number(r.price ?? r.unit_price ?? 0) || 0,
    materialId: r.materialId ?? r.material_id ?? ''
  }));
}
function updateAuthUI(){const name=currentUser?(currentUser.display_name||currentUser.username||'已登录'):'未登录';if($('meText'))$('meText').textContent=name+(currentUser&&currentUser.role?' ｜ '+currentUser.role:'');[['tabEdit','edit'],['tabLibrary','library'],['tabMaterials','materials'],['tabUsers','users']].forEach(([id,perm])=>{if($(id))$(id).style.display=hasPerm(perm)?'':'none'});document.querySelectorAll('button[onclick="saveCurrent()"],button[onclick="newProjectFromDashboard()"],button[onclick="newProject()"],button[onclick="addRow()"],button[onclick="duplicateProject()"],button[onclick="deleteProject()"]')?.forEach(b=>{b.disabled=!hasPerm('edit');b.style.opacity=hasPerm('edit')?'':'0.55'});}
async function checkAuth(){const r=await api('me');if(r.ok&&r.login){currentUser=r.user||null;currentCan=r.can||{};showLogin(false);updateAuthUI();await loadAll();return}bomSsoRedirect()}
function login(){bomSsoRedirect()}
function logout(){location.href='logout.php'}
async function loadAll(){
  try{
    setStatus('正在读取数据库...');
    const r=await api('bootstrap');
    if(!r.ok){ if(!r.need_login) alert(r.error||'读取数据库失败'); setStatus('读取失败：'+(r.error||'')); return; }
    currentUser=r.user||currentUser; currentCan=r.can||currentCan; updateAuthUI();
    projects=(r.projects||[]).map(p=>{const o={project_uid:p.project_uid,id:p.project_uid,name:p.name,customer:p.customer,model:p.model,productType:p.product_type,currency:p.currency,productImage:bomProjectDisplayImage(p),productImageDb:p.product_image||'',labor:+p.labor,other:+p.other,profitRate:+p.profit_rate,quoteMode:p.quote_mode,exchange:+p.exchange_rate,note:p.note,rows:normalizeBomRows(p.rows,p.rows_json),createdAt:p.created_at,updatedAt:p.updated_at,createdBy:p.created_by,updatedBy:p.updated_by,linkedSystem:p.linked_system||'',linkedId:p.linked_id||'',linkedTitle:p.linked_title||'',linkedJson:p.linked_json||'',namingSnapshotJson:p.naming_snapshot_json||'',namingSync:p.naming_sync||null};o.namingType=bomProjectNamingType(o);return o});
    materials=r.materials||[]; lists=r.lists||{}; ['categories','brands','suppliers','productTypes','namingProductTypes'].forEach(k=>{if(!Array.isArray(lists[k]))lists[k]=[]});
    renderBaseOptions();
    initResizableTable('bomTable','bom_col_widths_v65'); applyBomColumnVisibility(); updateBomStickyOffsets(); initResizableTable('materialTable','material_col_widths_v65');
    if(firstBoot){
      firstBoot=false;
      const urlId=bomUrlProjectUid();
      const keepPage=bomRememberedPage();
      const keepId=bomRememberedProject();
      if(urlId && projects.some(p=>p.id===urlId)){currentId=urlId;loadProject(urlId);showPage('edit')}
      else if(keepId && projects.some(p=>p.id===keepId)){currentId=keepId;loadProject(keepId)}
      else if(keepPage==='edit' && projects.length){currentId=projects[0].id;loadProject(currentId)}
      if(!urlId){
        if(keepPage==='dashboard'){setDashboardRange(dashboardRange||'month');showPage('dashboard')}
        else{showPage(keepPage)}
      }
    }else{
      if(currentId && projects.some(p=>p.id===currentId)){
        if(currentPage==='edit')loadProject(currentId); else renderProjectList();
      }
      if(currentPage==='dashboard')renderDashboard();
      if(currentPage==='library')renderLibrary();
      if(currentPage==='materials')renderMaterials();
      if(currentPage==='users')loadUsers();
    }
    setStatus('已读取数据库 '+nowText());
  }catch(e){ console.error(e); setStatus('前端读取失败：'+e.message); alert('BOM 前端脚本错误：'+e.message+'\n请先不要保存，上传最新修复版。'); }
}
function setStatus(t){$('status').textContent=t}
function showPage(p){
  const need={edit:'edit',library:'library',materials:'materials',users:'users'}[p]||'dashboard';
  if(need!=='dashboard'&&!hasPerm(need)){alert('当前账号没有权限访问：'+p);p='dashboard'}
  currentPage=bomValidPage(p)?p:'dashboard';
  $('dashboardPage').classList.toggle('hide',currentPage!=='dashboard');
  $('editPage').classList.toggle('hide',currentPage!=='edit');
  $('libraryPage').classList.toggle('hide',currentPage!=='library');
  $('materialsPage').classList.toggle('hide',currentPage!=='materials');
  if($('usersPage'))$('usersPage').classList.toggle('hide',currentPage!=='users');
  $('tabDashboard').classList.toggle('active',currentPage==='dashboard');
  $('tabEdit').classList.toggle('active',currentPage==='edit');
  $('tabLibrary').classList.toggle('active',currentPage==='library');
  $('tabMaterials').classList.toggle('active',currentPage==='materials');
  if($('tabUsers'))$('tabUsers').classList.toggle('active',currentPage==='users');
  if(currentPage==='dashboard')renderDashboard();
  if(currentPage==='materials')renderMaterials();
  if(currentPage==='library')renderLibrary();
  if(currentPage==='users')loadUsers();
  if(currentPage==='edit'&&!currentId&&projects.length)loadProject(projects[0].id);
  bomRememberPlace();
}
function rowSub(r){return Number(r.qty||0)*(Number(r.price||0)+Number(r.process||0)+Number(r.finishCost||0)+Number(r.finishCost2||0))}
function totals(p){const mat=(p.rows||[]).reduce((s,r)=>s+rowSub(r),0),labor=+p.labor||0,other=+p.other||0,total=mat+labor+other,rate=+p.profitRate||0,suggest=p.quoteMode==='margin'?(rate>=100?0:total/(1-rate/100)):total*(1+rate/100);return{mat,labor,other,total,suggest,profit:suggest-total}}
function collect(){
  const p=getCurrent(); if(!p) return;
  const oldRows=Array.isArray(p.rows)?p.rows:[];
  p.name=$('projectName').value||'未命名BOM';p.customer=$('customer').value;p.model=$('model').value;p.productType=$('productType').value;p.currency=$('currency').value;p.productImage=$('productImagePreview').dataset.src||'';p.labor=+$('labor').value||0;p.other=+$('other').value||0;p.profitRate=+$('profitRate').value||0;p.quoteMode=$('quoteMode').value;p.exchange=+$('exchange').value||1;p.note=$('note').value;
  const trs=[...document.querySelectorAll('#tbody tr')];
  // 防止在表格还没渲染完成、或页面隐藏时把已有 BOM 行误保存成空。
  if(trs.length===0 && oldRows.length>0) return;
  p.rows=trs.map((tr,i)=>{
    return {category:tr.querySelector('.category')?.value||'',name:tr.querySelector('.name')?.value||'',spec:tr.querySelector('.spec')?.value||'',qty:+(tr.querySelector('.qty')?.value||0)||0,process:+(tr.querySelector('.process')?.value||0)||0,finish:tr.querySelector('.finish')?.value||'',finishCost:+(tr.querySelector('.finishCost')?.value||0)||0,finish2:tr.querySelector('.finish2')?.value||'',finishCost2:+(tr.querySelector('.finishCost2')?.value||0)||0,finishMode2:!!tr.querySelector('.finish-cell.finish-two'),price:+(tr.querySelector('.price')?.value||0)||0,materialId:tr.dataset.materialId||''};
  });
}
function getCurrent(){return projects.find(p=>p.id===currentId)}
function touch(){calc()}
async function saveCurrent(){
  if(!currentId){alert('请先从 BOM 总览打开一个成本单，或新建 BOM。');return}
  collect();const p=getCurrent();if(!p)return;
  p.updatedAt=nowText();
  const keepPage=currentPage, keepId=p.id;
  bomRememberPlace();
  const r=await api('save_project',{project_uid:p.id,name:p.name,customer:p.customer,model:p.model,product_type:p.productType,currency:p.currency,product_image:p.productImage,labor:p.labor,other:p.other,profit_rate:p.profitRate,quote_mode:p.quoteMode,exchange_rate:p.exchange,note:p.note,rows:p.rows,created_by:p.createdBy||'',updated_by:(currentUser?.display_name||currentUser?.username||'')});
  if(!r.ok){alert(r.error);return}
  setStatus('已保存 '+nowText());
  await loadAll();
  currentId=keepId;
  if(projects.some(x=>x.id===keepId))loadProject(keepId);
  showPage(keepPage);
}
function newProject(load=true){const t=nowText();const p={id:uid(),name:'新 BOM 成本单',customer:'',model:'',productType:'',currency:'RMB',productImage:'',labor:0,other:0,profitRate:30,quoteMode:'markup',exchange:1,note:'',rows:[],createdAt:t,updatedAt:t,createdBy:(currentUser?.display_name||currentUser?.username||''),updatedBy:(currentUser?.display_name||currentUser?.username||'')};projects.unshift(p);currentId=p.id;if(load)loadProject(p.id);renderProjectList();addRow()}
function duplicateProject(){
  collect();const p=getCurrent();if(!p)return;
  const c=JSON.parse(JSON.stringify(p));
  c.id=uid();c.name+=' - 副本';c.createdAt=nowText();c.updatedAt=nowText();
  // V77.1：复制 BOM 时清空来源绑定，避免副本仍挂在旧型号；物料明细完全保留，后续可重新“绑定/更换型号”。
  c.linkedSystem='';c.linkedId='';c.linkedTitle='';c.linkedJson='';
  projects.unshift(c);currentId=c.id;loadProject(c.id)
}
async function deleteProject(){if(!currentId||!confirm('确定删除当前BOM？'))return;await api('delete_project',{project_uid:currentId});currentId=null;try{localStorage.removeItem(BOM_PLACE_PROJECT_KEY)}catch(e){};await loadAll()}
function renderProjectList(){const kw=($('search').value||'').toLowerCase(),ms=($('modelSearch').value||'').toLowerCase(),cf=$('customerFilter').value;const customers=[...new Set(projects.map(p=>p.customer).filter(Boolean))];$('customerFilter').innerHTML='<option value="">全部客户</option>'+customers.map(c=>`<option ${c===cf?'selected':''}>${esc(c)}</option>`).join('');let arr=projects.filter(p=>(!kw||[p.name,p.customer,p.model,p.productType].join(' ').toLowerCase().includes(kw))&&(!ms||(p.model||'').toLowerCase().includes(ms))&&(!cf||p.customer===cf));const sm=$('sortMode').value;arr.sort((a,b)=>sm==='createdAsc'?String(a.createdAt).localeCompare(String(b.createdAt)):sm==='customerAsc'?String(a.customer).localeCompare(String(b.customer)):sm==='modelAsc'?String(a.model).localeCompare(String(b.model)):String(b.updatedAt).localeCompare(String(a.updatedAt)));$('listCount').textContent=`共 ${arr.length}/${projects.length} 个成本单`;$('projectList').innerHTML=arr.map(p=>`<div class="project ${p.id===currentId?'active':''}" onclick="loadProject('${p.id}')"><b>${esc(p.name)}</b><small>${esc(p.customer||'-')} ｜ ${esc(p.model||'-')}<br>最后保存：${esc(p.updatedAt||'')}</small></div>`).join('')}
function loadProject(id){
  currentId=id; const p=getCurrent(); if(!p)return;
  renderBaseOptions(); $('projectName').value=p.name||''; $('customer').value=p.customer||''; $('model').value=p.model||''; $('productType').value=p.productType||''; $('currency').value=p.currency||'RMB'; setProductImagePreview(p.productImage||''); $('createdAt').value=p.createdAt||''; $('updatedAt').value=p.updatedAt||''; if($('createdBy'))$('createdBy').value=p.createdBy||''; if($('updatedBy'))$('updatedBy').value=p.updatedBy||''; $('labor').value=p.labor||0; $('other').value=p.other||0; $('profitRate').value=p.profitRate??30; $('quoteMode').value=p.quoteMode||'markup'; $('exchange').value=p.exchange??1; $('note').value=p.note||'';
  let linkInfo={fixed:[],missing:[],zeroPrice:[]}; try{ linkInfo=resolvePlmLinkedRows(p); }catch(e){ console.warn('关键物料检查跳过',e); }
  renderRows(); calc(); renderProjectList(); try{showPlmLinkNotice(linkInfo)}catch(e){} try{renderBomSourceNotice(p)}catch(e){} bomRememberPlace();
}
function addRow(row={}){const p=getCurrent();if(!p)return;p.rows.push({category:row.category||'',name:row.name||'',spec:row.spec||'',qty:row.qty||1,process:row.process||0,finish:row.finish||'',finishCost:row.finishCost||0,finish2:row.finish2||'',finishCost2:row.finishCost2||0,finishMode2:!!row.finishMode2,price:row.price||0,materialId:row.materialId||''});renderRows();calc()}
function removeRow(i){const p=getCurrent();if(!p)return;p.rows.splice(i,1);renderRows();calc()}
function insertRowAfter(i){const p=getCurrent();if(!p)return;p.rows.splice(i+1,0,{category:'',name:'',spec:'',qty:1,process:0,finish:'',finishCost:0,finish2:'',finishCost2:0,finishMode2:false,price:0});renderRows();calc()}
function renderRows(){
  const p=getCurrent(); if(!p) return;
  $('tbody').innerHTML=(p.rows||[]).map((r,i)=>{
    const has2=!!r.finishMode2 || String(r.finish2||'').trim()!=='' || Number(r.finishCost2||0)>0;
    const finishTitle=has2 ? '当前：2次表面。点击切回1次' : '当前：1次表面。点击切到2次';
    return `<tr class="${r.__plmMissing?'plm-missing':''}" data-material-id="${esc(r.materialId||'')}" draggable="true" data-row-index="${i}"><td class="col-no"><span class="drag-handle">☰</span> ${i+1}</td><td class="col-category"><textarea class="category cell-text" oninput="touch();autoGrow(this)">${esc(r.category)}</textarea></td><td class="col-name"><div class="name-pick-wrap"><textarea class="name cell-text" data-row-index="${i}" oninput="touch();autoGrow(this);showMaterialSuggest(this,${i})" onfocus="showMaterialSuggest(this,${i})" title="${r.__plmMissing?'共享物料库没有匹配到，请先新增或手动选择':''}">${esc(r.name)}</textarea><button type="button" class="mat-pick-btn" onclick="openMaterialPicker(${i})" title="选择共享物料">…</button></div></td><td class="col-spec"><textarea class="spec cell-text" oninput="touch();autoGrow(this)">${esc(r.spec)}</textarea></td><td class="col-qty"><input class="qty num" type="number" step="0.0001" value="${r.qty}" oninput="touch();calc()"></td><td class="col-process"><input class="process num" type="number" step="0.01" value="${r.process||0}" oninput="touch();calc()"></td><td class="col-finish"><div class="finish-cell ${has2?'finish-two':'finish-one'}"><div class="finish-stack"><div class="finish-row"><input class="finish" list="finishOptions" value="${esc(r.finish||'')}" oninput="touch();calc()" placeholder="面1"><input class="finishCost num" type="number" step="0.01" value="${money(r.finishCost||0)}" oninput="touch();calc()" placeholder="费"></div><div class="finish-row finish2-row"><input class="finish2" list="finishOptions" value="${esc(r.finish2||'')}" oninput="touch();calc()" placeholder="面2"><input class="finishCost2 num" type="number" step="0.01" value="${money(r.finishCost2||0)}" oninput="touch();calc()" placeholder="费"></div></div><button type="button" class="finish-toggle-btn ${has2?'has2':''}" onclick="toggleFinishMode(${i})" title="${esc(finishTitle)}">${has2?'2':'1'}</button></div></td><td class="price-cell col-price"><input class="price num price-input" type="number" step="0.0001" value="${r.price}" oninput="touch();calc()"></td><td class="num subtotal col-subtotal">${money(rowSub(r))}</td><td class="col-action"><div class="row-actions"><button class="row-act" onclick="insertRowAfter(${i})">＋</button><button class="row-act danger" onclick="removeRow(${i})">删</button></div></td></tr>`;
  }).join('');
  initRowDrag();initResizableTable('bomTable','bom_col_widths_v65');applyBomColumnVisibility();updateBomStickyOffsets();autoGrowAll();setTimeout(scheduleBomTableLayout,80);setTimeout(scheduleBomTableLayout,260);
}

function toggleFinishMode(i){
  collect();
  const p=getCurrent(); if(!p || !p.rows[i]) return;
  const has2=!!p.rows[i].finishMode2 || String(p.rows[i].finish2||'').trim()!=='' || Number(p.rows[i].finishCost2||0)>0;
  if(has2){
    p.rows[i].finishMode2=false;
    p.rows[i].finish2='';
    p.rows[i].finishCost2=0;
  }else{
    p.rows[i].finishMode2=true;
  }
  renderRows();calc();touch();
}

function openFinishAttr(i){
  collect();
  const p=getCurrent(); if(!p) return;
  const r=p.rows[i]||{};
  $('finishAttrRow').value=i;
  $('finishAttrFinish1').value=r.finish||'';
  $('finishAttrCost1').value=Number(r.finishCost||0);
  $('finishAttrFinish2').value=r.finish2||'';
  $('finishAttrCost2').value=Number(r.finishCost2||0);
  $('finishAttrMask').style.display='flex';
  setTimeout(()=>$('finishAttrFinish2').focus(),30);
}
function closeFinishAttr(){ if($('finishAttrMask')) $('finishAttrMask').style.display='none'; }
function saveFinishAttr(){
  const p=getCurrent(); if(!p) return;
  const i=Number($('finishAttrRow').value);
  if(!Number.isFinite(i) || !p.rows[i]) return closeFinishAttr();
  p.rows[i].finish=$('finishAttrFinish1').value||'';
  p.rows[i].finishCost=+$('finishAttrCost1').value||0;
  p.rows[i].finish2=$('finishAttrFinish2').value||'';
  p.rows[i].finishCost2=+$('finishAttrCost2').value||0;
  closeFinishAttr();renderRows();calc();touch();
}

function initRowDrag(){
  document.querySelectorAll('#tbody tr').forEach(tr=>{
    tr.ondragstart=e=>{tr.classList.add('dragging');e.dataTransfer.effectAllowed='move';};
    tr.ondragend=()=>{tr.classList.remove('dragging');document.querySelectorAll('#tbody tr').forEach(x=>x.classList.remove('drag-over'));};
    tr.ondragover=e=>{e.preventDefault();tr.classList.add('drag-over');};
    tr.ondragleave=()=>tr.classList.remove('drag-over');
    tr.ondrop=e=>{e.preventDefault();const from=document.querySelector('#tbody tr.dragging');if(!from||from===tr)return;collect();const p=getCurrent();const fromIndex=Number(from.dataset.rowIndex),toIndex=Number(tr.dataset.rowIndex);const row=p.rows.splice(fromIndex,1)[0];p.rows.splice(toIndex,0,row);renderRows();calc();touch();};
  });
}

function calc(){collect();const p=getCurrent();if(!p)return;document.querySelectorAll('#tbody tr').forEach((tr,i)=>tr.querySelector('.subtotal').textContent=money(rowSub(p.rows[i]||{})));const t=totals(p);$('matTotal').textContent=money(t.mat);$('laborTotal').textContent=money(t.labor);$('otherTotal').textContent=money(t.other);$('grandTotal').textContent=money(t.total);$('suggestPrice').textContent=money(t.suggest);$('profitAmount').textContent=money(t.profit)}
function autoGrow(el){
  if(!el) return;
  // 先清零再读取 scrollHeight，避免列宽变化/换行后高度计算不准。
  el.style.height='0px';
  const h=Math.max(28, el.scrollHeight + 3);
  el.style.height=h+'px';
  const tr=el.closest('tr');
  if(tr){ tr.style.height='auto'; tr.style.minHeight='0'; }
}
function autoGrowAll(){
  requestAnimationFrame(()=>{
    document.querySelectorAll('#bomTable textarea.cell-text').forEach(autoGrow);
    updateBomStickyOffsets();
  });
}
let __bomAutoGrowTimer=null;
function scheduleBomAutoGrow(){
  clearTimeout(__bomAutoGrowTimer);
  __bomAutoGrowTimer=setTimeout(()=>{ autoGrowAll(); updateBomStickyOffsets(); },30);
}
function scheduleBomTableLayout(){ scheduleBomAutoGrow(); }
window.addEventListener('resize', scheduleBomTableLayout);
window.addEventListener('orientationchange', scheduleBomTableLayout);
function uploadProductImage(){$('productImageInput').click()}function readProductImage(e){const f=e.target.files[0];if(!f)return;const r=new FileReader();r.onload=()=>setProductImagePreview(r.result);r.readAsDataURL(f)}function setProductImagePreview(src){src=bomNormalizeMediaUrl(src);const img=$('productImagePreview');img.dataset.src=src||'';img.src=src||'';img.style.display=src?'block':'none'}function removeProductImage(){setProductImagePreview('');touch()}
function dashboardTypeOptions(){
  return [...new Set([...(lists.namingProductTypes||[]),...projects.map(p=>p.namingType||bomProjectNamingType(p)),...(lists.productTypes||[])].map(x=>String(x||'').trim()).filter(Boolean))].sort((a,b)=>String(a).localeCompare(String(b),'zh-CN'));
}
function renderBaseOptions(){const opt=a=>a.map(x=>`<option value="${esc(x)}">${esc(x)}</option>`).join('');const dashCustomerVal=$('dashCustomer')?.value||'',dashTypeVal=$('dashType')?.value||'',libCustomerVal=$('libCustomer')?.value||'',libTypeVal=$('libType')?.value||'',libCurrencyVal=$('libCurrency')?.value||'';$('productType').innerHTML='<option value="">未分类</option>'+opt(lists.productTypes.filter(x=>x!=='未分类'));$('matCategory').innerHTML=opt(lists.categories);$('matBrand').innerHTML=opt(lists.brands);$('matFilterCategory').innerHTML='<option value="">全部分类</option>'+opt(lists.categories);$('matFilterBrand').innerHTML='<option value="">全部品牌</option>'+opt(lists.brands);$('matFilterSupplier').innerHTML='<option value="">全部供应商</option>'+opt(lists.suppliers);$('supplierOptions').innerHTML=lists.suppliers.map(s=>`<option value="${esc(s)}">`).join('');if($('libType')){$('libType').innerHTML='<option value="">全部产品分类</option>'+opt(lists.productTypes);$('libType').value=libTypeVal}if($('libCustomer')){$('libCustomer').innerHTML='<option value="">全部客户</option>'+[...new Set(projects.map(p=>p.customer).filter(Boolean))].sort((a,b)=>String(a).localeCompare(String(b),'zh-CN')).map(c=>`<option value="${esc(c)}">${esc(c)}</option>`).join('');$('libCustomer').value=libCustomerVal}if($('libCurrency'))$('libCurrency').value=libCurrencyVal;if($('dashCustomer')){$('dashCustomer').innerHTML='<option value="">全部客户</option>'+[...new Set(projects.map(p=>p.customer).filter(Boolean))].map(c=>`<option>${esc(c)}</option>`).join('');$('dashCustomer').value=dashCustomerVal};if($('dashType')){$('dashType').innerHTML='<option value="">全部系列</option>'+opt(dashboardTypeOptions());$('dashType').value=dashTypeVal}}
async function saveList(key,list){lists[key]=[...new Set(list.filter(Boolean))];await api('save_list',{key,list:lists[key]});renderBaseOptions()}
function addProductType(){const v=prompt('新增产品分类');if(v)saveList('productTypes',[...lists.productTypes,v])}function addMaterialCategory(){const v=$('newMatCategory').value.trim();if(v)saveList('categories',[...lists.categories,v]);$('newMatCategory').value=''}function addMaterialBrand(){const v=$('newMatBrand').value.trim();if(v)saveList('brands',[...lists.brands,v]);$('newMatBrand').value=''}function addMaterialSupplier(){const v=$('newMatSupplier').value.trim();if(v)saveList('suppliers',[...lists.suppliers,v]);$('newMatSupplier').value=''}
function readMaterialImage(e){const f=e.target.files[0];if(!f)return;const r=new FileReader();r.onload=()=>currentMaterialImage=r.result;r.readAsDataURL(f)}
function openMaterialEditor(id=null){
  editingMaterialId=id?Number(id):null;
  const m=editingMaterialId?materials.find(x=>+x.id===+editingMaterialId):null;
  if($('matEditorTitle'))$('matEditorTitle').textContent=m?'编辑物料':'新增物料';
  if($('matEditorSub'))$('matEditorSub').textContent=m?'保存后定位。':'可连续新增。';
  if(m){
    $('matCategory').value=m.category||'';$('matBrand').value=m.brand||'';$('matName').value=m.name||'';$('matModel').value=m.model||'';$('matSpec').value=m.spec||'';$('matPrice').value=m.price||0;$('matUnit').value=m.unit||'PCS';$('matSupplier').value=m.supplier||'';$('matKeyword').value=m.keyword||'';currentMaterialImage=m.image||'';
  }else{
    clearMaterialForm(false);
  }
  const mask=$('matEditorMask'); if(mask)mask.style.display='flex'; setTimeout(()=>{$('matName')&&$('matName').focus()},60);
}
function closeMaterialEditor(){const mask=$('matEditorMask'); if(mask)mask.style.display='none'; if(materialFocusId)setTimeout(()=>focusMaterialRow(materialFocusId),120)}
function materialTripleText(d){return `物料名称：${d?.name||'-'} ｜ 型号：${d?.model||'-'} ｜ 规格：${d?.spec||'-'}`}
function materialSimilarRowHtml(m,duplicate=false){
  const title=[m.brand,m.name,m.model].filter(Boolean).join(' / ') || ('ID '+(m.id||''));
  const sub=[`分类：${m.category||'-'}`,`规格：${m.spec||'-'}`,`供应商：${m.supplier||'-'}`,`单位：${m.unit||'-'}`,`ID：${m.id||'-'}`,m.similar_reason?`原因：${m.similar_reason}`:''].filter(Boolean).join(' ｜ ');
  const score=duplicate?'重复不可保存':(m.similar_score?`相似度 ${Math.round(Number(m.similar_score)||0)}`:'类似件');
  return `<div class="mat-similar-row"><div><div class="mat-similar-title">${esc(title)}</div><div class="mat-similar-sub">${esc(sub)}</div></div><div class="mat-similar-score">${esc(score)}<br>${money(m.price||0)}</div></div>`;
}
function openMaterialSimilarModal(kind, rows, payload, continueAdd, msg){
  materialPendingPayload=payload||null; materialPendingContinue=!!continueAdd;
  const duplicate=kind==='duplicate';
  const mask=$('matSimilarMask'), box=$('matSimilarBox'); if(!mask||!box)return;
  box.classList.toggle('mat-similar-danger', duplicate);
  $('matSimilarTitle').textContent=duplicate?'三字段重复，不能保存':'发现类似物料';
  $('matSimilarSub').textContent=duplicate?'物料名称 + 型号 + 规格 三个字段组合已经存在。':'新建物料前请确认，避免共享物料库重复。';
  $('matSimilarLead').innerHTML=duplicate
    ? `${esc(msg||'物料名称 + 型号 + 规格 已存在，不能重复保存。')}<br>${payload?esc(materialTripleText(payload)):''}`
    : `${esc(msg||'发现类似物料，请确认是否已经存在。')}<br>${esc(materialTripleText(payload||{}))}`;
  $('matSimilarList').innerHTML=(rows&&rows.length)?rows.map(m=>materialSimilarRowHtml(m,duplicate)).join(''):'<div class="mat-similar-empty">没有可显示的物料。</div>';
  $('matSimilarSaveBtn').style.display=duplicate?'none':'';
  $('matSimilarHint').textContent=duplicate?'重复件不能保存，请返回修改名称、型号或规格。':'确认不是同一个物料后，可以继续保存；重复件后台仍会拦截。';
  mask.style.display='flex';
}
function closeMaterialSimilarModal(){const m=$('matSimilarMask');if(m)m.style.display='none'}
function confirmSaveMaterialAfterSimilar(){
  if(!materialPendingPayload)return closeMaterialSimilarModal();
  const d={...materialPendingPayload,confirm_similar:1};
  closeMaterialSimilarModal();
  saveMaterial(materialPendingContinue,d);
}
async function saveMaterial(continueAdd=false, forcedPayload=null){
  const d=forcedPayload||{id:editingMaterialId,category:$('matCategory').value,brand:$('matBrand').value,name:$('matName').value,model:$('matModel').value,spec:$('matSpec').value,price:Number($('matPrice').value||0)||0,unit:$('matUnit').value,supplier:$('matSupplier').value,keyword:$('matKeyword').value,image:currentMaterialImage};
  if(!d.name){alert('请输入物料名称');return}
  const keep={category:d.category,brand:d.brand,supplier:d.supplier,unit:d.unit||'PCS'};
  const r=await api('save_material',d);
  if(!r.ok){
    if(r.duplicate_material){openMaterialSimilarModal('duplicate',r.duplicates||[],d,false,r.error);return}
    if(r.need_confirm_similar){openMaterialSimilarModal('similar',r.similars||[],d,continueAdd,r.error);return}
    alert(r.error);return
  }
  materialPendingPayload=null; materialPendingContinue=false; closeMaterialSimilarModal();
  materialFocusId=String(r.id||d.id||'');
  if($('matSearch'))$('matSearch').value=d.name||d.model||'';
  if($('matDateFilter'))$('matDateFilter').value='';
  await loadAll(); showPage('materials');
  if(continueAdd){
    editingMaterialId=null; currentMaterialImage='';
    if($('matCategory'))$('matCategory').value=keep.category||'';
    if($('matBrand'))$('matBrand').value=keep.brand||'';
    if($('matSupplier'))$('matSupplier').value=keep.supplier||'';
    if($('matUnit'))$('matUnit').value=keep.unit||'PCS';
    ['matName','matModel','matPrice','matSpec','matKeyword'].forEach(id=>{if($(id))$(id).value=''});
    if($('matImageFile'))$('matImageFile').value='';
    const mask=$('matEditorMask'); if(mask)mask.style.display='flex';
    if($('matEditorTitle'))$('matEditorTitle').textContent='新增物料';
    if($('matEditorHint'))$('matEditorHint').textContent='已保存，继续填下一条';
    setTimeout(()=>{$('matName')&&$('matName').focus()},80);
  }else{
    closeMaterialEditor(); clearMaterialForm(false); setTimeout(()=>focusMaterialRow(materialFocusId),220);
  }
}
function clearMaterialForm(resetId=true){if(resetId)editingMaterialId=null;currentMaterialImage='';['matName','matModel','matPrice','matSupplier','matSpec','matKeyword'].forEach(id=>{if($(id))$(id).value=''});if($('matUnit'))$('matUnit').value='PCS';if($('matImageFile'))$('matImageFile').value=''}
async function deleteMaterial(id){if(!confirm('删除这个物料？'))return;await api('delete_material',{id});await loadAll();showPage('materials')}
async function syncWeightProfilesToBom(){const r=await api('sync_weight_profiles_to_bom',{});if(!r.ok){alert('同步失败：'+(r.message||r.error||'未知错误'));return}await loadAll();showPage('materials');if($('matFilterCategory'))$('matFilterCategory').value='型材';if($('matSearch'))$('matSearch').value='';renderMaterials();alert('已同步重量型材到 BOM 物料库：新增 '+(r.inserted||0)+'，更新 '+(r.updated||0)+'，跳过 '+(r.skipped||0));}

function editMaterial(id){openMaterialEditor(id)}
function focusMaterialRow(id){
  if(!id)return; const sid=String(id).replaceAll('\"','');
  const tr=document.querySelector(`#materialsTbody tr[data-id="${sid}" ]`)||document.querySelector(`#materialsTbody tr[data-id="${sid}"]`);
  if(!tr){
    const ix=materials.findIndex(m=>String(m.id)===sid);
    if(ix>=0){
      if($('matSearch'))$('matSearch').value='';
      materialPage=Math.floor(ix/Number(materialPageSize||50))+1;
      renderMaterials();
    }
    return;
  }
  tr.classList.add('mat-row-focus'); tr.scrollIntoView({behavior:'smooth',block:'center'}); setTimeout(()=>tr.classList.remove('mat-row-focus'),4200);
}
async function saveMaterialCell(td){
  const tr=td.closest('tr'); if(!tr) return;
  const id=Number(tr.dataset.id||0), field=td.dataset.field; if(!id||!field) return;
  const m=materials.find(x=>Number(x.id)===id); if(!m) return;
  let val=td.textContent.trim(); if(field==='price') val=Number(val||0)||0;
  if(String(m[field]??'')===String(val??'')) return;
  m[field]=val;
  try{
    const r=await api('save_material',m);
    if(!r.ok){
      if(r.duplicate_material){openMaterialSimilarModal('duplicate',r.duplicates||[],m,false,r.error);renderMaterials();return;}
      alert(r.error||'保存失败');return;
    }
    setStatus('物料已保存 '+nowText());
    const msg=document.createElement('span'); msg.className='material-status'; msg.textContent='已保存'; td.appendChild(msg); setTimeout(()=>msg.remove(),900);
    renderBaseOptions();
  }catch(e){alert('保存失败：'+e.message)}
}
function initResizableTable(tableId, storageKey){
  const table=$(tableId); if(!table || table.dataset.resizableReady==='1') return;
  table.dataset.resizableReady='1';
  const saved=JSON.parse(localStorage.getItem(storageKey)||'{}');
  table.querySelectorAll('th').forEach((th,i)=>{
    const key=th.dataset.col || String(i);
    if(saved[key]){ th.style.setProperty('width',saved[key]+'px','important'); th.style.setProperty('min-width',saved[key]+'px','important'); }
    const r=document.createElement('span'); r.className='col-resizer'; th.appendChild(r);
    r.addEventListener('mousedown',e=>{
      e.preventDefault(); e.stopPropagation();
      const startX=e.clientX, startW=th.offsetWidth;
      function move(ev){
        const minW = ['qty','price','no'].includes(key) ? 40 : (key==='process'?48:(key==='finish'?150:(key==='action'?48:50)));
        const w=Math.max(minW,startW+ev.clientX-startX);
        th.style.setProperty('width',w+'px','important'); th.style.setProperty('min-width',w+'px','important'); th.style.setProperty('max-width','none','important');
        saved[key]=w; localStorage.setItem(storageKey,JSON.stringify(saved));
        updateBomStickyOffsets();
        scheduleBomAutoGrow();
      }
      function up(){scheduleBomAutoGrow();document.removeEventListener('mousemove',move);document.removeEventListener('mouseup',up)}
      document.addEventListener('mousemove',move);document.addEventListener('mouseup',up);
    });
  });
}
function resetResizableTable(tableId, storageKey){localStorage.removeItem(storageKey); const table=$(tableId); if(table){table.dataset.resizableReady=''; table.querySelectorAll('.col-resizer').forEach(x=>x.remove()); table.querySelectorAll('th').forEach(th=>{th.style.removeProperty('width');th.style.removeProperty('min-width');th.style.removeProperty('max-width')}); initResizableTable(tableId,storageKey); updateBomStickyOffsets(); scheduleBomAutoGrow();}}


const BOM_COLS=[
  ['no','序'],['category','类'],['name','名称'],['spec','规格'],['qty','量'],['process','加工'],['finish','表面'],['price','价'],['subtotal','计'],['action','操作']
];
function getBomColVisible(){try{return JSON.parse(localStorage.getItem('bom_col_visible_v72')||'{}')}catch(e){return{}}}
function setBomColVisible(v){localStorage.setItem('bom_col_visible_v72',JSON.stringify(v||{}))}
function openColumnPanel(){
  const mask=$('colPanelMask'), box=$('colChecks'); if(!mask||!box)return;
  const visible=getBomColVisible();
  box.innerHTML=BOM_COLS.map(([k,label])=>`<label><input type="checkbox" ${visible[k]===false?'':'checked'} onchange="changeBomCol('${k}',this.checked)" ${k==='name'?'disabled':''}>${label}</label>`).join('');
  mask.style.display='flex';
}
function closeColumnPanel(){if($('colPanelMask'))$('colPanelMask').style.display='none'}
function changeBomCol(k,on){const v=getBomColVisible();v[k]=!!on; if(k==='name')v[k]=true; setBomColVisible(v); applyBomColumnVisibility(); updateBomStickyOffsets();}
function resetBomColumnVisibility(){localStorage.removeItem('bom_col_visible_v72'); applyBomColumnVisibility(); openColumnPanel(); updateBomStickyOffsets();}
function resetBomColumnWidths(){resetResizableTable('bomTable','bom_col_widths_v65'); setStatus('列宽已重置')}
function applyBomColumnVisibility(){
  const table=$('bomTable'); if(!table)return;
  const visible=getBomColVisible();
  const ths=[...table.querySelectorAll('thead th')];
  ths.forEach((th,idx)=>{
    const k=th.dataset.col||'';
    const hide=visible[k]===false && k!=='name';
    th.classList.toggle('col-hidden',hide);
    table.querySelectorAll('tbody tr').forEach(tr=>{ if(tr.cells[idx]) tr.cells[idx].classList.toggle('col-hidden',hide); });
  });
  scheduleBomAutoGrow();
}
function updateBomStickyOffsets(){
  const table=$('bomTable'); if(!table)return;
  const sticky=['no','category','name'];
  let left=0;
  sticky.forEach((k,idx)=>{
    const th=table.querySelector(`thead th[data-col="${k}"]`);
    const cells=[...table.querySelectorAll(`td.col-${k}`)];
    if(!th)return;
    const hidden=th.classList.contains('col-hidden') || getComputedStyle(th).display==='none';
    if(hidden){
      th.style.left='0px';
      cells.forEach(td=>{td.style.left='0px';});
      return;
    }
    const leftPx=Math.round(left);
    th.style.position='sticky';
    th.style.left=leftPx+'px';
    th.style.zIndex=String(44-idx);
    th.style.background='#f9fafb';
    th.style.backgroundClip='padding-box';
    cells.forEach(td=>{
      td.style.position='sticky';
      td.style.left=leftPx+'px';
      td.style.zIndex=String(34-idx);
      td.style.background=td.closest('tr')?.classList.contains('plm-missing') ? '#fff7ed' : '#fff';
      td.style.backgroundClip='padding-box';
    });
    const rectW=th.getBoundingClientRect().width;
    const cssW=parseFloat(getComputedStyle(th).width)||0;
    left += Math.max(1, rectW || th.offsetWidth || cssW);
  });
}
function toggleBomList(){const ep=$('editPage'); if(!ep)return; ep.classList.toggle('list-collapsed'); localStorage.setItem('bom_list_collapsed_v72',ep.classList.contains('list-collapsed')?'1':'0'); setTimeout(updateBomStickyOffsets,50)}
function restoreBomListState(){const ep=$('editPage'); if(ep && localStorage.getItem('bom_list_collapsed_v72')==='1')ep.classList.add('list-collapsed')}
setTimeout(restoreBomListState,0);


function normMaterialText(v){return String(v??'').toLowerCase().replace(/[¥￥]\s*\d+(?:\.\d+)?/g,' ').replace(/\b(?:rmb|usd)\s*\d+(?:\.\d+)?/gi,' ').replace(/\b\d+(?:\.\d+)?\s*(?:rmb|usd|元)\b/gi,' ').replace(/pcs/gi,' ').replace(/[^\p{L}\p{N}]+/gu,'')}
function joinUnique(parts,sep=' / '){const out=[];for(const raw of parts){const x=String(raw??'').trim();if(!x)continue;const nx=normMaterialText(x);let dup=false;for(const y of out){const ny=normMaterialText(y);if(nx===ny||(ny&&nx.includes(ny))||(nx&&ny.includes(nx))){dup=true;break}}if(!dup)out.push(x)}return out.join(sep)}
function materialDisplayName(m){const b=String(m.brand??'').trim(),n=String(m.name??'').trim(),model=String(m.model??'').trim();if(!n)return b||model;if(b&&normMaterialText(n).includes(normMaterialText(b)))return n;return b?`${b} / ${n}`:n}
function materialDisplaySpec(m){const model=String(m.model??'').trim(),spec=String(m.spec??'').trim();if(!spec)return model;if(model&&normMaterialText(spec).includes(normMaterialText(model)))return spec;return model?`${model} / ${spec}`:spec}
function badPlmText(r){const name=String(r.name??''),spec=String(r.spec??''),both=name+' '+spec;if(name&&spec&&normMaterialText(name)===normMaterialText(spec))return true;if(/[¥￥]/.test(both))return true;if((name.match(/\//g)||[]).length>=2||(spec.match(/\//g)||[]).length>=2)return true;return false}
function rowMatchText(r){return joinUnique([r.name,r.spec,r.model,r.material_name,r.materialName,r.brand,r.supplier],' ')}
function bestMaterialMatch(row){
  const rid=Number(row.materialId||row.material_id||0);if(rid){const byId=materials.find(m=>Number(m.id)===rid);if(byId)return byId}
  const text=normMaterialText(rowMatchText(row));if(!text)return null;
  let best=null,bestScore=0;
  for(const m of materials){
    let score=0;
    const model=normMaterialText(m.model),name=normMaterialText(m.name),brand=normMaterialText(m.brand),spec=normMaterialText(m.spec),keyword=normMaterialText(m.keyword);
    if(model&&text.includes(model))score+=90+Math.min(30,model.length);
    if(name&&text.includes(name))score+=70;
    if(brand&&text.includes(brand))score+=20;
    if(spec&&text.includes(spec))score+=12;
    if(keyword&&text.includes(keyword))score+=10;
    if(name&&name.includes(text))score+=40;
    if(model&&model.includes(text))score+=50;
    if(score>bestScore){bestScore=score;best=m}
  }
  return bestScore>=70?best:null;
}

function keyMaterialCheckTerms(){return ['电源','驱动','芯片','光源','cob','smd','led','光学','透镜','反光','光杯','镜片','配件','附件','接头','端子'];}
function shouldCheckKeyMaterialRow(r){
  const txt=[r&&r.category,r&&r.name,r&&r.spec].join(' ').toLowerCase();
  if(!txt.trim())return false;
  return keyMaterialCheckTerms().some(t=>txt.includes(String(t).toLowerCase()));
}

function resolvePlmLinkedRows(p){
  const info={fixed:[],missing:[],zeroPrice:[],changed:false};
  if(!p||!Array.isArray(p.rows)||!materials.length)return info;
  p.rows.forEach((r,i)=>{
    if(!r||typeof r!=='object')return;
    delete r.__plmMissing;
    if(!shouldCheckKeyMaterialRow(r))return;
    const before=JSON.stringify({category:r.category,name:r.name,spec:r.spec,price:r.price,materialId:r.materialId});
    const need=Number(r.price||0)<=0||!r.materialId||badPlmText(r);
    const m=bestMaterialMatch(r);
    if(m){
      const bad=badPlmText(r);
      r.materialId=String(m.id||'');
      if(!String(r.category||'').trim())r.category=m.category||'';
      if((Number(r.price||0)<=0)&&Number(m.price||0)>0)r.price=Number(m.price)||0;
      if(bad||!String(r.name||'').trim())r.name=materialDisplayName(m);
      if(bad||!String(r.spec||'').trim())r.spec=materialDisplaySpec(m);
      if(!r.qty||Number(r.qty)<=0)r.qty=1;
      if(JSON.stringify({category:r.category,name:r.name,spec:r.spec,price:r.price,materialId:r.materialId})!==before){info.fixed.push({index:i+1,row:r,material:m});info.changed=true}
      if(Number(m.price||0)<=0)info.zeroPrice.push({index:i+1,row:r,material:m});
    }else if(need&&String(rowMatchText(r)).trim()){
      r.__plmMissing=true;info.missing.push({index:i+1,row:r});
    }
  });
  return info;
}
function showPlmLinkNotice(info){
  let el=$('plmLinkNotice');
  if(!el){el=document.createElement('div');el.id='plmLinkNotice';el.className='plm-link-notice';const actions=document.querySelector('.editor-card .actions');if(actions)actions.insertAdjacentElement('afterend',el)}
  if(!el||(!info.fixed.length&&!info.missing.length&&!info.zeroPrice.length)){if(el)el.style.display='none';return}
  const miss=info.missing.slice(0,8).map(x=>`第${x.index}行：${esc(joinUnique([x.row.name,x.row.spec],' / ').slice(0,90))}`).join('<br>');
  const zero=info.zeroPrice.slice(0,5).map(x=>`第${x.index}行：${esc(materialDisplayName(x.material))}`).join('<br>');
  el.innerHTML=`<b>关键物料检查</b>：${info.fixed.length?`<span class="okmsg">已自动匹配并修正 ${info.fixed.length} 行的名称/规格/单价。</span>`:''}${info.missing.length?`<br><span class="badmsg">有 ${info.missing.length} 行共享物料库没有找到，已用浅橙色标出：</span><br>${miss}`:''}${info.zeroPrice.length?`<br><span class="badmsg">有 ${info.zeroPrice.length} 行匹配到了物料，但物料库单价为 0：</span><br>${zero}`:''}<br><span class="hint">只检查：电源、芯片/光源、光学、配件、接头；其它行不再检查。</span><br><button class="small ok" onclick="saveCurrent()">保存本次修正</button><button class="small ghost" onclick="recheckPlmLink()">重新检查关键物料</button>`;
  el.style.display='block';
  setStatus(`关键物料检查：修正 ${info.fixed.length} 行，未找到 ${info.missing.length} 行`);
}
function recheckPlmLink(){const p=getCurrent();if(!p)return;const info=resolvePlmLinkedRows(p);renderRows();calc();showPlmLinkNotice(info)}


function matCreatedTime(m){
  const raw=m.created_at||m.createdAt||m.updated_at||m.updatedAt||''; if(!raw)return null;
  const d=new Date(String(raw).replace(' ','T')); return isNaN(d.getTime())?null:d;
}
function materialDatePass(m,mode){
  if(!mode)return true; const d=matCreatedTime(m); if(!d)return false;
  const now=new Date(), today=new Date(now.getFullYear(),now.getMonth(),now.getDate());
  const t=new Date(d.getFullYear(),d.getMonth(),d.getDate()).getTime();
  if(mode==='today')return t===today.getTime();
  if(mode==='yesterday'){const y=new Date(today);y.setDate(y.getDate()-1);return t===y.getTime()}
  const days=Number(mode||0); if(days>0){const start=new Date(today);start.setDate(start.getDate()-(days-1));return t>=start.getTime()}
  return true;
}
function renderMaterials(){
  const kw=($('matSearch').value||'').toLowerCase(),cat=$('matFilterCategory').value,brand=$('matFilterBrand').value,sup=$('matFilterSupplier').value,dateMode=$('matDateFilter')?.value||'';
  const arr=materials.filter(m=>(!cat||m.category===cat)&&(!brand||m.brand===brand)&&(!sup||m.supplier===sup)&&materialDatePass(m,dateMode)&&(!kw||[m.category,m.brand,m.name,m.model,m.spec,m.supplier,m.keyword].join(' ').toLowerCase().includes(kw)));
  materialPageSize=Number(materialPageSize||50); if(![20,50,100,200,500].includes(materialPageSize))materialPageSize=50;
  materialTotalPages=Math.max(1,Math.ceil(arr.length/materialPageSize));
  if(materialFocusId){const ix=arr.findIndex(m=>String(m.id)===String(materialFocusId));if(ix>=0)materialPage=Math.floor(ix/materialPageSize)+1;}
  materialPage=Math.max(1,Math.min(materialTotalPages,Number(materialPage||1)));
  localStorage.setItem('bom_material_page_size_v763',String(materialPageSize));
  localStorage.setItem('bom_material_page_v763',String(materialPage));
  const start=(materialPage-1)*materialPageSize, pageRows=arr.slice(start,start+materialPageSize);
  if($('matPageSize'))$('matPageSize').value=String(materialPageSize);
  if($('matPageInfo'))$('matPageInfo').textContent=`第 ${materialPage} / ${materialTotalPages} 页｜显示 ${arr.length?start+1:0}-${Math.min(start+pageRows.length,arr.length)} / ${arr.length}`;
  if($('matPageInput')){$('matPageInput').max=String(materialTotalPages);$('matPageInput').value=String(materialPage)}
  $('matCount').textContent=`共 ${arr.length}/${materials.length} 个物料`;
  $('materialsTbody').innerHTML=pageRows.map(m=>`<tr data-id="${m.id}"><td>${m.image?`<img class="mini-thumb" src="${m.image}">`:''}</td><td contenteditable="true" data-field="category" onblur="saveMaterialCell(this)">${esc(m.category)}</td><td contenteditable="true" data-field="brand" onblur="saveMaterialCell(this)">${esc(m.brand)}</td><td contenteditable="true" data-field="name" onblur="saveMaterialCell(this)"><b>${esc(m.name)}</b></td><td contenteditable="true" data-field="model" onblur="saveMaterialCell(this)">${esc(m.model)}</td><td contenteditable="true" data-field="spec" onblur="saveMaterialCell(this)">${esc(m.spec)}</td><td contenteditable="true" data-field="price" class="num" onblur="saveMaterialCell(this)">${money(m.price)}</td><td contenteditable="true" data-field="unit" onblur="saveMaterialCell(this)">${esc(m.unit)}</td><td contenteditable="true" data-field="supplier" onblur="saveMaterialCell(this)">${esc(m.supplier)}</td><td><button class="small ghost" onclick="editMaterial(${m.id})">编辑</button> <button class="small danger" onclick="deleteMaterial(${m.id})">删</button></td></tr>`).join('');
  initResizableTable('materialTable','material_col_widths_v65');
  if(materialFocusId)setTimeout(()=>focusMaterialRow(materialFocusId),80);
}
function setMaterialPageSize(v){materialPageSize=Number(v||50);materialPage=1;renderMaterials();}
function setMaterialPage(v){materialPage=Number(v||1);renderMaterials();}
function showMaterialSuggest(input,rowIndex){
  const kw=input.value.toLowerCase().trim();
  const box=$('matSuggestFloat');
  if(!box)return;
  if(!kw){box.innerHTML='';box.style.display='none';return}
  const p=getCurrent();const row=p&&p.rows?p.rows[rowIndex]:null;const rowCat=row?row.category:'';
  const nkw=normMaterialText(kw);
  let arr=materials.filter(m=>materialMatchesCategory(m,rowCat)&&([m.category,m.brand,m.name,m.model,m.spec,m.supplier,m.keyword].join(' ').toLowerCase().includes(kw)||normMaterialText([m.category,m.brand,m.name,m.model,m.spec,m.supplier,m.keyword].join(' ')).includes(nkw))).slice(0,30);
  if(!arr.length){
    // V77.1：未找到物料时不再弹出遮挡下一行的提示框；需要全库选择时点右侧“…”打开物料选择器。
    box.innerHTML='';
    box.style.display='none';
    return;
  }
  const rect=input.getBoundingClientRect();
  box.style.left=rect.left+'px';box.style.top=(rect.bottom+4)+'px';box.style.width=Math.max(rect.width,420)+'px';
  box.innerHTML=arr.map(m=>`<div class="mat-option" onclick="applyMaterial(${rowIndex},${m.id})"><div><div class="mat-option-title">${esc(materialDisplayName(m))}</div><div class="mat-option-sub">${esc(m.category)} ｜ ${esc(materialDisplaySpec(m))} ｜ ${esc(m.supplier)}</div></div><div class="mat-option-price">${money(m.price)}</div></div>`).join('');
  box.style.display='block';
}
document.addEventListener('click',e=>{if(!e.target.closest('.col-name')&&!e.target.closest('#matSuggestFloat')&&!e.target.closest('.mat-pick-btn'))$('matSuggestFloat').style.display='none'})
function recentMaterialIds(){try{return JSON.parse(localStorage.getItem('bom_recent_material_ids_v75')||'[]').map(x=>String(x))}catch(e){return []}}
function pushRecentMaterial(id){id=String(id||'');if(!id)return;let arr=recentMaterialIds().filter(x=>x!==id);arr.unshift(id);localStorage.setItem('bom_recent_material_ids_v75',JSON.stringify(arr.slice(0,30)))}
function materialThumb(m){return m.image?`<div class="mat-pick-thumb"><img src="${esc(m.image)}" onerror="this.parentNode.textContent='料'"></div>`:`<div class="mat-pick-thumb">料</div>`}
function standardMaterialGroups(){return [
  {key:'',label:'全部',terms:[]},
  {key:'light',label:'芯片/光源',terms:['芯片','光源','cob','smd','led','灯珠','普瑞','cree','osram','bridgelux']},
  {key:'driver',label:'电源/驱动',terms:['电源','驱动','driver','power']},
  {key:'optic',label:'光学',terms:['光学','透镜','反光杯','光杯','镜片','lens','reflector']},
  {key:'housing',label:'型材/外壳',terms:['型材','外壳','灯体','散热','铝型材','端盖']},
  {key:'accessory',label:'五金/配件',terms:['配件','附件','五金','弹片','螺丝','防水圈','胶圈','支架']},
  {key:'connector',label:'接头/端子',terms:['接头','端子','线材','接线','插头','connector','terminal']},
  {key:'finish',label:'表面处理',terms:['表面','喷粉','喷油','电镀','氧化','沙黑','砂白','拉丝','阳极']},
  {key:'pack',label:'包装',terms:['包装','纸箱','彩盒','说明书','标签','泡棉']},
  {key:'process',label:'加工',terms:['加工','cnc','车床','铣','冲压','开模']},
  {key:'other',label:'其它',terms:[]}
]}
function materialText(m){return [m.category,m.brand,m.name,m.model,m.spec,m.supplier,m.keyword].join(' ').toLowerCase()}
function materialGroupKey(m){const txt=materialText(m);for(const g of standardMaterialGroups()){if(g.key&&g.key!=='other'&&g.terms.some(t=>txt.includes(String(t).toLowerCase())))return g.key}return 'other'}
function inferGroupFromRow(row){const txt=[row?.category,row?.name,row?.spec].join(' ').toLowerCase();for(const g of standardMaterialGroups()){if(g.key&&g.key!=='other'&&g.terms.some(t=>txt.includes(String(t).toLowerCase())))return g.key}return ''}
function fillMaterialPickerOptions(row){
  const gsel=$('mp_group'), bsel=$('mp_brand'), ssel=$('mp_supplier'); if(!gsel)return;
  const oldg=gsel.value, oldb=bsel.value, olds=ssel.value;
  gsel.innerHTML=standardMaterialGroups().map(g=>`<option value="${esc(g.key)}">${esc(g.label)}</option>`).join('');
  const brands=[...new Set(materials.map(m=>m.brand).filter(Boolean))].sort();
  const sups=[...new Set(materials.map(m=>m.supplier).filter(Boolean))].sort();
  bsel.innerHTML='<option value="">全部品牌</option>'+brands.map(x=>`<option>${esc(x)}</option>`).join('');
  ssel.innerHTML='<option value="">全部供应商</option>'+sups.map(x=>`<option>${esc(x)}</option>`).join('');
  if([...gsel.options].some(o=>o.value===oldg))gsel.value=oldg; else gsel.value=inferGroupFromRow(row);
  if(brands.includes(oldb))bsel.value=oldb; if(sups.includes(olds))ssel.value=olds;
}
function openMaterialPicker(rowIndex){
  collect(); materialPickRowIndex=rowIndex; materialPickMode='same';
  const p=getCurrent(), row=p&&p.rows?p.rows[rowIndex]:null; if(!p||!row)return;
  const mask=$('matPickMask'); if(!mask)return;
  if($('matPickRowHint'))$('matPickRowHint').textContent=`第 ${rowIndex+1} 行｜${row.category||'未分类'}｜${row.name||'未命名'}`;
  if($('mp_kw'))$('mp_kw').value=row.name||row.spec||'';
  fillMaterialPickerOptions(row);
  const g=inferGroupFromRow(row); if(g&&$('mp_group'))$('mp_group').value=g;
  mask.style.display='flex'; renderMaterialPicker(); setTimeout(()=>{$('mp_kw')&&$('mp_kw').focus()},50);
}
function closeMaterialPicker(){const m=$('matPickMask'); if(m)m.style.display='none'}
function setMaterialPickerQuick(mode){materialPickMode=mode; if(mode==='all'&&$('mp_group'))$('mp_group').value=''; if(mode==='same'){const p=getCurrent(),row=p&&p.rows?p.rows[materialPickRowIndex]:null;const g=inferGroupFromRow(row);if(g&&$('mp_group'))$('mp_group').value=g} renderMaterialPicker()}
function renderMaterialPicker(){
  const p=getCurrent(), row=p&&p.rows?p.rows[materialPickRowIndex]:null; const box=$('matPickList'); if(!box)return;
  ['all','recent','same'].forEach(x=>{const el=$('mp_chip_'+x);if(el)el.classList.toggle('active',materialPickMode===x)});
  const kw=($('mp_kw')?.value||'').trim().toLowerCase(), nkw=normMaterialText(kw), group=$('mp_group')?.value||'', brand=$('mp_brand')?.value||'', sup=$('mp_supplier')?.value||'', sort=$('mp_sort')?.value||'recent', recent=recentMaterialIds();
  let arr=materials.filter(m=>{
    if(materialPickMode==='recent'&&!recent.includes(String(m.id)))return false;
    const gkey=materialGroupKey(m); if(group&&group!=='other'&&gkey!==group)return false; if(group==='other'&&gkey!=='other')return false;
    if(brand&&m.brand!==brand)return false; if(sup&&m.supplier!==sup)return false;
    const txt=[m.category,m.brand,m.name,m.model,m.spec,m.supplier,m.keyword].join(' ').toLowerCase();
    if(kw&&!(txt.includes(kw)||normMaterialText(txt).includes(nkw)))return false;
    return true;
  });
  if(materialPickMode==='same' && !group){const rg=inferGroupFromRow(row); if(rg) arr=arr.filter(m=>materialGroupKey(m)===rg)}
  arr.sort((a,b)=>{
    if(sort==='priceAsc')return Number(a.price||0)-Number(b.price||0); if(sort==='priceDesc')return Number(b.price||0)-Number(a.price||0); if(sort==='nameAsc')return String(materialDisplayName(a)).localeCompare(String(materialDisplayName(b)));
    const ai=recent.indexOf(String(a.id)), bi=recent.indexOf(String(b.id)); if(ai>=0||bi>=0)return (ai<0?999:ai)-(bi<0?999:bi);
    const ag=materialGroupKey(a)===inferGroupFromRow(row)?0:1, bg=materialGroupKey(b)===inferGroupFromRow(row)?0:1; if(ag!==bg)return ag-bg;
    return String(b.id||'').localeCompare(String(a.id||''));
  });
  arr=arr.slice(0,120); if($('mp_summary'))$('mp_summary').textContent=`${arr.length} 个 / 共 ${materials.length} 个物料`;
  if(!arr.length){box.innerHTML='<div class="mat-pick-empty">没有找到物料。可切到“全部”、清空关键词，或去共享物料库新增。</div>';return;}
  box.innerHTML=arr.map(m=>`<div class="mat-pick-row" onclick="applyMaterial(${materialPickRowIndex},${Number(m.id||0)},true)">${materialThumb(m)}<div><div class="mat-pick-title">${esc(materialDisplayName(m))}</div><div class="mat-pick-sub"><span class="mat-pick-tag">${esc(m.category||'未分类')}</span>${esc(materialDisplaySpec(m)||'无规格')} ｜ ${esc(m.brand||'-')} ｜ ${esc(m.supplier||'-')}</div></div><div class="mat-pick-price">${money(m.price)}</div><button class="small ok" type="button">选</button></div>`).join('')
}
function applyMaterial(rowIndex,id,fromModal=false){
  const p=getCurrent(); const m=materials.find(x=>+x.id===+id); if(!p||!m||!p.rows[rowIndex])return;
  const oldPrice=Number(p.rows[rowIndex].price||0), newPrice=Number(m.price||0);
  if(fromModal && oldPrice>0 && newPrice>0){const high=Math.max(oldPrice,newPrice), low=Math.min(oldPrice,newPrice); if(low>0 && high/low>=3){if(!confirm(`价格变化较大：原 ${money(oldPrice)}，新 ${money(newPrice)}。确定带入？`))return;}}
  p.rows[rowIndex]={...p.rows[rowIndex],category:p.rows[rowIndex]?.category||m.category,name:materialDisplayName(m),spec:materialDisplaySpec(m),price:newPrice,materialId:String(m.id||'')};
  pushRecentMaterial(m.id); $('matSuggestFloat').style.display='none'; if(fromModal)closeMaterialPicker(); renderRows(); calc();
  showPlmLinkNotice({fixed:[{index:rowIndex+1,row:p.rows[rowIndex],material:m}],missing:[],zeroPrice:Number(m.price||0)<=0?[{index:rowIndex+1,row:p.rows[rowIndex],material:m}]:[],changed:true})
}


/* V68：命名系统联动 BOM */
let namingBomMode='create';
function openNamingBomModal(mode='create'){
  if(!hasPerm('edit')){alert('当前账号没有新建/编辑 BOM 权限');return;}
  namingBomMode = mode==='bind' ? 'bind' : 'create';
  const m=$('namingBomModal');
  if(m){
    const h=m.querySelector('.naming-bom-head h3');
    const p=m.querySelector('.naming-bom-head p');
    if(h)h.textContent = namingBomMode==='bind' ? '绑定 / 更换命名型号' : '从命名型号生成 BOM';
    if(p)p.textContent = namingBomMode==='bind'
      ? '给当前 BOM 绑定命名系统型号，只同步型号、图片、尺寸、分类等基础资料；不会清空或覆盖现有物料明细。'
      : '从型号命名系统读取型号、尺寸、图片、BOM 模板、灯头数量和默认物料模块，生成 BOM 草稿。';
    m.style.display='flex';
    setTimeout(()=>{ if($('nb_kw')) $('nb_kw').focus(); },60);
  }
  if($('namingBomList')) $('namingBomList').innerHTML='<div class="naming-bom-empty">输入条件后搜索命名型号</div>';
}
function closeNamingBomModal(){const m=$('namingBomModal'); if(m)m.style.display='none'}
function namingBomThumb(m){const img=bomNormalizeMediaUrl(m.image_display_url||m.image_path||bomPickObjectMedia(m,'image'));return img?`<div class="naming-bom-thumb"><img src="${esc(img)}" onerror="bomImageFallback(this,'naming-bom-thumb','${esc((m.model_no||'N').slice(0,1))}')"></div>`:`<div class="naming-bom-thumb">${esc((m.model_no||'N').slice(0,1))}</div>`}
function namingBomSafe(v){return String(v??'')}
async function searchNamingBom(){
  const d={kw:($('nb_kw')?.value||'').trim(),category:($('nb_category')?.value||'').trim(),item_name:($('nb_item')?.value||'').trim(),prefix:($('nb_prefix')?.value||'').trim().toUpperCase(),size_code:($('nb_size')?.value||'').trim(),status:($('nb_status')?.value||''),bom_only:$('nb_bom_only')?.checked?1:0,limit:Number($('nb_limit')?.value||100)};
  const box=$('namingBomList'); if(box)box.innerHTML='<div class="naming-bom-empty">正在读取命名系统...</div>';
  const r=await api('naming_models',d);
  if(!r.ok||!r.available){if(box)box.innerHTML=`<div class="naming-bom-empty">${esc(r.error||'命名系统不可用')}</div>`;return;}
  const list=r.models||[]; if($('nb_summary'))$('nb_summary').textContent=`找到 ${list.length} 个型号`;
  if(!list.length){box.innerHTML='<div class="naming-bom-empty">没有匹配型号。可放宽筛选或到命名系统新建。</div>';return;}
  box.innerHTML=list.map(m=>{
    const meta=[m.category,m.item_name,m.customer,m.status,m.bom_template_type?('模板 '+m.bom_template_type):'',m.bom_head_count?('灯头 '+m.bom_head_count):''].filter(Boolean).join(' ｜ ');
    return `<div class="naming-bom-row">
      ${namingBomThumb(m)}
      <div><div class="naming-bom-title">${esc(m.model_no||'-')} ${m.product_name?`· ${esc(m.product_name)}`:''}</div><div class="naming-bom-sub">${esc(meta)}</div><div class="naming-bom-sub">${esc((m.remark||'').slice(0,80))}</div></div>
      <div><span class="naming-bom-dim">${esc(m.dimension_text||m.size_code_parsed||'-')}</span></div>
      <div class="naming-bom-create"><button class="small ok" onclick="selectNamingForBom(${Number(m.id)})">${namingBomMode==='bind'?'绑定型号':'生成 BOM'}</button></div>
    </div>`;
  }).join('');
}
function selectNamingForBom(id){
  if(namingBomMode==='bind') return bindCurrentBomToNaming(id);
  return createBomFromNaming(id);
}
async function bindCurrentBomToNaming(id){
  if(!id)return;
  const p=getCurrent();
  if(!p||!currentId){alert('请先打开要绑定的 BOM。');return;}
  if(!confirm('确定绑定这个命名型号？\n\n只同步型号、图片、尺寸、分类等基础资料；不会改动当前 BOM 的物料明细、数量、价格。'))return;
  const r=await api('bind_naming_to_project',{project_uid:currentId,naming_id:id,sync_basic:1});
  if(!r.ok){alert(r.error||'绑定失败');return;}
  closeNamingBomModal();
  setStatus('已绑定命名型号：'+(r.model?.model_no||''));
  await loadAll();
  currentId=r.project_uid||currentId;
  showPage('edit');
  loadProject(currentId);
}
async function unbindNamingFromCurrent(){
  const p=getCurrent(); if(!p||!currentId)return;
  if(!confirm('确定解除当前 BOM 与命名型号的绑定？\n\n只解除来源关系，不删除 BOM，不清空物料。'))return;
  const r=await api('unbind_naming_from_project',{project_uid:currentId});
  if(!r.ok){alert(r.error||'解除失败');return;}
  setStatus('已解除命名型号绑定');
  await loadAll();
  currentId=r.project_uid||currentId;
  loadProject(currentId);
}
async function createBomFromNaming(id){
  if(!id)return;
  const r=await api('create_from_naming',{naming_id:id});
  if(!r.ok){alert(r.error||'生成 BOM 失败');return;}
  closeNamingBomModal();
  setStatus('已从命名型号生成 BOM 草稿');
  await loadAll();
  currentId=r.project_uid;
  showPage('edit');
  loadProject(r.project_uid);
}
function bomNamingDiffHtml(sync){
  sync=sync||{};
  const diffs=sync.diffs||[];
  if(sync.missing)return `<div class="naming-sync-diff"><b>命名型号已不存在或无法读取。</b>请先到命名系统确认。</div>`;
  if(!sync.has_update)return `<span class="naming-sync-ok">已同步</span>${sync.synced_at?` <span class="hint">同步：${esc(sync.synced_at)}</span>`:''}`;
  return `<div class="naming-sync-diff"><b>命名资料有更新</b>：${diffs.length} 项基础字段变化。<ul>${diffs.slice(0,8).map(d=>`<li>${esc(d.label)}：${esc(d.old||'-')} → <b>${esc(d.new||'-')}</b></li>`).join('')}${diffs.length>8?`<li>还有 ${diffs.length-8} 项...</li>`:''}</ul></div>`;
}
async function checkCurrentBomNamingSync(){
  const p=getCurrent(); if(!p||!currentId)return;
  const r=await api('naming_sync_check',{project_uid:currentId});
  if(!r.ok){alert(r.error||'检查失败');return;}
  p.namingSync=r;
  renderBomSourceNotice(p);
  if(r.has_update){
    const lines=(r.diffs||[]).map(d=>`${d.label}: ${d.old||'-'} → ${d.new||'-'}`).join('\n');
    if(confirm('命名系统有更新：\n\n'+lines+'\n\n是否同步到 BOM 基础资料？\n不会改物料、数量、价格、加工费。')) await applyCurrentBomNamingSync();
  }else alert(r.message||'命名基础资料已同步');
}
async function applyCurrentBomNamingSync(){
  const p=getCurrent(); if(!p||!currentId)return;
  if(!confirm('确认同步命名系统基础资料到当前 BOM？\n\n只同步：型号、名称、分类、尺寸、图片、客户等基础资料。\n不会覆盖 BOM 物料行、价格、加工费、表面处理费。'))return;
  const r=await api('naming_sync_apply',{project_uid:currentId});
  if(!r.ok){alert(r.error||'同步失败');return;}
  setStatus(r.message||'已同步命名基础资料');
  await loadAll(); currentId=r.project_uid||currentId; showPage('edit'); loadProject(currentId);
}
function renderBomSourceNotice(p){
  let el=$('bomSourceNotice');
  if(!el){el=document.createElement('div');el.id='bomSourceNotice';el.className='plm-link-notice';const actions=document.querySelector('.editor-card .actions');if(actions)actions.insertAdjacentElement('afterend',el)}
  if(!el)return;
  if(!p){el.style.display='none';return;}
  if(p.linkedSystem){
    const sys=p.linkedSystem==='NAMING'?'型号命名系统':p.linkedSystem;
    const sync=p.namingSync||{};
    const syncHtml=p.linkedSystem==='NAMING'?bomNamingDiffHtml(sync):'';
    const btns=p.linkedSystem==='NAMING'
      ? `<button class="small ghost" onclick="checkCurrentBomNamingSync()">查看变化</button> ${sync.has_update?'<button class="small ok" onclick="applyCurrentBomNamingSync()">同步基础资料</button>':''}`
      : '';
    el.innerHTML=`<b>来源联动</b>：<span class="okmsg">${esc(sys)} · ${esc(p.linkedTitle||p.model||'')}</span> ${syncHtml} ${btns} <button class="small ghost" onclick="openNamingBomModal('bind')">更换/绑定型号</button> <button class="small ghost" onclick="window.open('naming.php','_blank')">打开命名系统</button> <button class="small danger" onclick="unbindNamingFromCurrent()">解除绑定</button>`;
  }else{
    el.innerHTML=`<b>来源联动</b>：<span class="hint">当前 BOM 未绑定命名型号。复制 BOM 后可先保留物料，再绑定新型号。</span> <button class="small ghost" onclick="openNamingBomModal('bind')">绑定型号</button>`;
  }
  el.style.display='block';
}
function materialCategoryTerms(cat){
  cat=String(cat||'').toLowerCase();
  if(/芯片|光源|cob|smd|led/.test(cat))return ['芯片','光源','cob','smd','led'];
  if(/光学|透镜|反光|光杯|镜片/.test(cat))return ['光学','透镜','反光','光杯','镜片'];
  if(/电源|驱动/.test(cat))return ['电源','驱动'];
  if(/型材|外壳|散热/.test(cat))return ['型材','外壳','散热'];
  if(/包装|纸箱/.test(cat))return ['包装','纸箱'];
  if(/配件|附件|端子|接头|防水|螺丝|线材/.test(cat))return ['配件','附件','端子','接头','防水','螺丝','线材'];
  return [];
}
function materialMatchesCategory(m,cat){const terms=materialCategoryTerms(cat); if(!terms.length)return true; const txt=[m.category,m.name,m.model,m.spec,m.keyword].join(' ').toLowerCase(); return terms.some(t=>txt.includes(t.toLowerCase()));}

function dateFromText(v){if(!v)return null;const d=new Date(String(v).replace(/-/g,'/'));return isNaN(d.getTime())?null:d}
function startOfToday(){const d=new Date();d.setHours(0,0,0,0);return d}
function startOfMonth(){const d=new Date();return new Date(d.getFullYear(),d.getMonth(),1)}
function dateInputValue(d){const y=d.getFullYear(),m=String(d.getMonth()+1).padStart(2,'0'),day=String(d.getDate()).padStart(2,'0');return `${y}-${m}-${day}`}
function dashboardRangeStart(){const now=startOfToday();if(dashboardRange==='month')return startOfMonth();if(dashboardRange==='7'){const d=new Date(now);d.setDate(d.getDate()-6);return d}if(dashboardRange==='3'){const d=new Date(now);d.setDate(d.getDate()-2);return d}if(dashboardRange==='custom'&&$('dashStart')?.value)return new Date($('dashStart').value+'T00:00:00');return null}
function dashboardRangeEnd(){if(dashboardRange==='custom'&&$('dashEnd')?.value)return new Date($('dashEnd').value+'T23:59:59');return null}
function setDashboardRange(r){dashboardRange=r;dashboardPage=1;['Month','7','3','All','Custom'].forEach(x=>{const el=$('dashRange'+x);if(el)el.classList.remove('active')});const map={month:'Month','7':'7','3':'3',all:'All',custom:'Custom'};const el=$('dashRange'+map[r]);if(el)el.classList.add('active');if(r==='month'){$('dashStart').value=dateInputValue(startOfMonth());$('dashEnd').value=dateInputValue(new Date())}else if(r==='7'){const d=startOfToday();d.setDate(d.getDate()-6);$('dashStart').value=dateInputValue(d);$('dashEnd').value=dateInputValue(new Date())}else if(r==='3'){const d=startOfToday();d.setDate(d.getDate()-2);$('dashStart').value=dateInputValue(d);$('dashEnd').value=dateInputValue(new Date())}else if(r==='all'){$('dashStart').value='';$('dashEnd').value=''}renderDashboard()}
function dashboardFilteredProjects(){const kw=($('dashKeyword')?.value||'').toLowerCase(),customer=$('dashCustomer')?.value||'',type=$('dashType')?.value||'',timeField=$('dashTimeField')?.value||'updatedAt';let arr=projects.filter(p=>{const dt=dateFromText(p[timeField]);const st=dashboardRangeStart(),ed=dashboardRangeEnd(),pt=p.namingType||bomProjectNamingType(p);if(st&&(!dt||dt<st))return false;if(ed&&(!dt||dt>ed))return false;if(customer&&p.customer!==customer)return false;if(type&&pt!==type)return false;if(kw&&![p.name,p.customer,p.model,pt,p.productType,JSON.stringify(p.rows||[])].join(' ').toLowerCase().includes(kw))return false;return true});const sort=$('dashSort')?.value||'updatedDesc';arr.sort((a,b)=>sort==='costDesc'?totals(b).total-totals(a).total:sort==='costAsc'?totals(a).total-totals(b).total:sort==='createdDesc'?String(b.createdAt).localeCompare(String(a.createdAt)):sort==='customerAsc'?String(a.customer).localeCompare(String(b.customer)):sort==='modelAsc'?String(a.model).localeCompare(String(b.model)):String(b.updatedAt).localeCompare(String(a.updatedAt)));return arr}
function setDashboardView(v){dashboardView=v==='icon'?'icon':'table';dashboardPage=1;localStorage.setItem('bom_dashboard_view_v79',dashboardView);renderDashboard()}
function toggleDashboardGroup(){dashboardGroup=!dashboardGroup;dashboardPage=1;localStorage.setItem('bom_dashboard_group_v79',dashboardGroup?'1':'0');renderDashboard()}
function updateDashboardViewButtons(){if($('dashViewTable'))$('dashViewTable').classList.toggle('active',dashboardView!=='icon');if($('dashViewIcon'))$('dashViewIcon').classList.toggle('active',dashboardView==='icon');if($('dashGroupToggle'))$('dashGroupToggle').classList.toggle('active',dashboardGroup);if($('dashboardTableWrap'))$('dashboardTableWrap').hidden=dashboardView==='icon';if($('dashboardIconGrid'))$('dashboardIconGrid').hidden=dashboardView!=='icon'}
function dashboardEffectivePageSize(){
  if(dashboardView!=='icon')return 2;
  const box=$('dashboardIconGrid'), w=box?.clientWidth||document.querySelector('.dashboard')?.clientWidth||window.innerWidth||1100;
  const cols=Math.max(2,Math.floor(w/232));
  return Math.max(4,cols*2);
}
function dashboardTotalPages(){const size=dashboardEffectivePageSize();return Math.max(1,Math.ceil((lastDashboardRows.length||0)/size))}
function dashboardPageRows(){const size=dashboardEffectivePageSize(),total=dashboardTotalPages();dashboardPage=Math.max(1,Math.min(total,Number(dashboardPage)||1));const start=(dashboardPage-1)*size;return lastDashboardRows.slice(start,start+size)}
function gotoDashboardPage(delta){dashboardPage=Math.max(1,Math.min(dashboardTotalPages(),dashboardPage+delta));renderDashboard()}
function renderDashboardPagers(rows){
  const size=dashboardEffectivePageSize(), total=dashboardTotalPages(), start=lastDashboardRows.length?((dashboardPage-1)*size+1):0, end=Math.min(lastDashboardRows.length,dashboardPage*size);
  const html=`<span>第 ${dashboardPage}/${total} 页 ｜ ${start}-${end} / ${lastDashboardRows.length}</span><div class="dash-pager-actions"><button class="ghost" onclick="gotoDashboardPage(-1)" ${dashboardPage<=1?'disabled':''}>上一页</button><button class="ghost" onclick="gotoDashboardPage(1)" ${dashboardPage>=total?'disabled':''}>下一页</button></div>`;
  if($('dashPagerTop'))$('dashPagerTop').innerHTML=html;
  if($('dashPagerBottom'))$('dashPagerBottom').innerHTML=html;
}
function dashboardGroups(arr){
  const map={};
  arr.forEach(p=>{const k=p.namingType||bomProjectNamingType(p);(map[k]=map[k]||[]).push(p)});
  return Object.keys(map).sort((a,b)=>String(a).localeCompare(String(b),'zh-CN')).map(k=>({name:k,rows:map[k]}));
}
function dashboardTableRowHtml(p){
  const t=totals(p), pt=p.namingType||bomProjectNamingType(p);
  return `<tr><td class="bom-title-cell"><b>${esc(p.name||'未命名BOM')}</b><small>${esc((p.rows||[]).slice(0,2).map(r=>r.name).filter(Boolean).join(' / '))}</small></td><td>${esc(p.customer||'-')}</td><td>${esc(p.model||'-')}</td><td>${esc(pt||'未分类')}</td><td class="num">${(p.rows||[]).length}</td><td class="price num">${money(t.total)}</td><td class="num">${money(t.suggest)}</td><td>${esc(p.createdAt||'')}</td><td>${esc(p.updatedAt||'')}</td><td><button class="small ok" onclick="openProjectFromDashboard('${p.id}')">打开编辑</button> <button class="small ghost" onclick="quickDuplicateFromDashboard('${p.id}')">复制</button></td></tr>`;
}
function dashboardImageHtml(p){
  const src=String(p.productImage||'').trim();
  const label=String(p.model||p.name||'BOM').trim().slice(0,1)||'B';
  return src?`<img src="${esc(src)}" onerror="bomImageFallback(this,'dash-bom-image','${esc(label)}')">`:`<div class="empty">${esc(label)}</div>`;
}
function dashboardCardHtml(p){
  const t=totals(p), rows=p.rows||[], material=rows.slice(0,2).map(r=>r.name).filter(Boolean).join(' / ');
  return `<article class="dash-bom-card">
    <div class="dash-bom-image">${dashboardImageHtml(p)}</div>
    <div class="dash-bom-body">
      <div class="dash-bom-title" title="${esc(p.name||'未命名BOM')}">${esc(p.name||'未命名BOM')}</div>
      <div class="dash-bom-model" title="${esc(p.model||'-')}">${esc(p.model||'-')}</div>
      <div class="dash-bom-meta"><span title="${esc(p.customer||'-')}">客户 ${esc(p.customer||'-')}</span><span title="${esc(p.namingType||bomProjectNamingType(p))}">${esc(p.namingType||bomProjectNamingType(p))}</span><span>物料 ${rows.length}</span><span title="${esc(p.updatedAt||'')}">更新 ${esc(String(p.updatedAt||'').slice(0,16)||'-')}</span></div>
      <div class="dash-bom-cost"><span>总成本</span><b>${money(t.total)}</b></div>
      <div class="dash-bom-model" title="${esc(material)}">${esc(material||'暂无物料摘要')}</div>
    </div>
    <div class="dash-bom-actions"><button class="small ok" onclick="openProjectFromDashboard('${p.id}')">打开编辑</button><button class="small ghost" onclick="quickDuplicateFromDashboard('${p.id}')">复制</button></div>
  </article>`;
}
function renderDashboard(){
  if(!$('dashboardTbody'))return;
  renderBaseOptions();updateDashboardViewButtons();
  lastDashboardRows=dashboardFilteredProjects();
  const totalCost=lastDashboardRows.reduce((s,p)=>s+totals(p).total,0),totalQuote=lastDashboardRows.reduce((s,p)=>s+totals(p).suggest,0);
  $('dashCount').textContent=lastDashboardRows.length+'/'+projects.length;$('dashTotalCost').textContent=money(totalCost);$('dashTotalQuote').textContent=money(totalQuote);$('dashAvgCost').textContent=money(lastDashboardRows.length?totalCost/lastDashboardRows.length:0);
  const label=dashboardRange==='month'?'本月':dashboardRange==='7'?'近 7 天':dashboardRange==='3'?'近 3 天':dashboardRange==='all'?'全部':'自定义时间';
  $('dashHint').textContent=`当前范围：${label} ｜ 时间字段：${$('dashTimeField').value==='createdAt'?'创建时间':'最后保存'} ｜ 共 ${lastDashboardRows.length} 个 BOM`;
  const iconBox=$('dashboardIconGrid');if(iconBox)iconBox.className=dashboardGroup?'dash-icon-groups':'dash-icon-grid';
  const pageRows=dashboardPageRows();renderDashboardPagers(pageRows);
  if(!lastDashboardRows.length){$('dashboardTbody').innerHTML=`<tr><td colspan="10"><div class="dash-empty">当前筛选没有 BOM。可以切换“全部”或清空筛选。</div></td></tr>`;if(iconBox)iconBox.innerHTML='<div class="dash-empty">当前筛选没有 BOM。可以切换“全部”或清空筛选。</div>';return}
  if(dashboardGroup){
    const groups=dashboardGroups(pageRows);
    $('dashboardTbody').innerHTML=groups.map(g=>`<tr class="dash-group-row"><td colspan="10"><div class="dash-group-title"><span>${esc(g.name)}</span><small>${g.rows.length} 个 BOM</small></div></td></tr>`+g.rows.map(dashboardTableRowHtml).join('')).join('');
    if(iconBox)iconBox.innerHTML=groups.map(g=>`<section class="dash-icon-group"><div class="dash-icon-group-head"><span>${esc(g.name)}</span><small>${g.rows.length} 个 BOM</small></div><div class="dash-icon-grid">${g.rows.map(dashboardCardHtml).join('')}</div></section>`).join('');
    return;
  }
  $('dashboardTbody').innerHTML=pageRows.map(dashboardTableRowHtml).join('');
  if(iconBox)iconBox.innerHTML=pageRows.map(dashboardCardHtml).join('');
}
function clearDashboardFilters(){$('dashKeyword').value='';$('dashCustomer').value='';$('dashType').value='';$('dashSort').value='updatedDesc';$('dashTimeField').value='updatedAt';setDashboardRange('month')}
function openProjectFromDashboard(id){showPage('edit');loadProject(id)}
function newProjectFromDashboard(){showPage('edit');newProject(true)}
function quickDuplicateFromDashboard(id){loadProject(id);duplicateProject();showPage('edit')}
function exportDashboardCSV(){const arr=lastDashboardRows.length?lastDashboardRows:dashboardFilteredProjects();let csv='名称,客户,型号,产品分类,物料行数,总成本,建议报价,创建时间,最后保存\n';arr.forEach(p=>{const t=totals(p);csv+=[p.name,p.customer,p.model,p.productType,(p.rows||[]).length,t.total,t.suggest,p.createdAt,p.updatedAt].map(x=>`"${String(x??'').replaceAll('"','""')}"`).join(',')+'\n'});download('bom_dashboard.csv',csv)}

async function loadUsers(){if(!hasPerm('users'))return;const r=await api('list_users');if(!r.ok){alert(r.error);return}userRows=r.users||[];renderUsers()}
function renderUsers(){if(!$('usersTbody'))return;$('usersTbody').innerHTML=(userRows||[]).map(u=>`<tr class="${+u.is_active?'':'disabled-row'}"><td>${u.id}</td><td><b>${esc(u.username)}</b></td><td>${esc(u.display_name||'')}</td><td>${esc(u.role||'')}</td><td>${esc(u.permissions||'')}</td><td>${+u.is_active?'启用':'停用'}</td><td>${esc(u.last_login||'')}</td><td><button class="small ghost" onclick='editUser(${JSON.stringify(u).replaceAll("'","&#39;")})'>编辑</button> <button class="small danger" onclick="disableUser(${u.id})">停用</button></td></tr>`).join('')||'<tr><td colspan="8" class="dash-empty">暂无用户</td></tr>'}
function clearUserForm(){['userId','userName','userDisplay','userPass'].forEach(id=>{$(id).value=''});$('userRole').value='engineer';$('userPerms').value='dashboard,edit,library,materials';$('userActive').value='1'}
function editUser(u){$('userId').value=u.id||'';$('userName').value=u.username||'';$('userDisplay').value=u.display_name||'';$('userRole').value=u.role||'engineer';$('userPerms').value=u.permissions||'dashboard,edit,library,materials';$('userPass').value='';$('userActive').value=String(u.is_active??1);showPage('users')}
async function saveUser(){if(!hasPerm('users'))return alert('没有用户管理权限');const d={id:+$('userId').value||0,username:$('userName').value.trim(),display_name:$('userDisplay').value.trim(),role:$('userRole').value,permissions:$('userPerms').value.trim(),password:$('userPass').value,is_active:+$('userActive').value};const r=await api('save_user',d);if(!r.ok){alert(r.error);return}clearUserForm();await loadUsers();setStatus('用户已保存 '+nowText())}
async function disableUser(id){if(!confirm('确定停用这个用户？'))return;const r=await api('disable_user',{id});if(!r.ok){alert(r.error);return}await loadUsers()}

function setLibraryView(v){libraryView=v;localStorage.setItem('bom_library_view_v74',v);renderLibrary()}
function updateLibraryViewButtons(){['list','large','medium','small'].forEach(v=>{const el=$('libView'+v.charAt(0).toUpperCase()+v.slice(1));if(el)el.classList.toggle('active',libraryView===v)})}
function toggleLibraryMoreFilters(){const el=$('libFilterMore');if(el)el.classList.toggle('show')}
function libDateText(p,field){return String((p&&p[field])||'')}
function libDateObj(p,field){return dateFromText(libDateText(p,field))}
function applyLibraryQuickRange(){const v=$('libQuickRange')?.value||'all';if(v==='all'){$('libStart').value='';$('libEnd').value=''}else if(v==='month'){$('libStart').value=dateInputValue(startOfMonth());$('libEnd').value=dateInputValue(new Date())}else if(v==='7'){const d=startOfToday();d.setDate(d.getDate()-6);$('libStart').value=dateInputValue(d);$('libEnd').value=dateInputValue(new Date())}else if(v==='3'){const d=startOfToday();d.setDate(d.getDate()-2);$('libStart').value=dateInputValue(d);$('libEnd').value=dateInputValue(new Date())}renderLibrary()}
function clearLibraryFilters(){['libKeyword','libStart','libEnd','libCostMin','libCostMax','libRowsMin','libRowsMax','libModelPrefix','libMaterialKeyword'].forEach(id=>{if($(id))$(id).value=''});['libCustomer','libType','libCurrency','libImageFilter'].forEach(id=>{if($(id))$(id).value=''});if($('libGroup'))$('libGroup').value='customer';if($('libSort'))$('libSort').value='updatedDesc';if($('libTimeField'))$('libTimeField').value='updatedAt';if($('libQuickRange'))$('libQuickRange').value='all';renderLibrary()}
function libraryProjectText(p){return [p.name,p.customer,p.model,p.productType,p.currency,p.createdAt,p.updatedAt,(p.rows||[]).map(r=>[r.category,r.name,r.spec,r.finish,r.finish2].join(' ')).join(' ')].join(' ').toLowerCase()}
function libraryRowText(p){return (p.rows||[]).map(r=>[r.category,r.name,r.spec,r.finish,r.finish2].join(' ')).join(' ').toLowerCase()}
function libraryFilteredProjects(){renderBaseOptions();const kw=($('libKeyword')?.value||'').toLowerCase().trim(),lc=$('libCustomer')?.value||'',lt=$('libType')?.value||'',cur=$('libCurrency')?.value||'',img=$('libImageFilter')?.value||'',timeField=$('libTimeField')?.value||'updatedAt',matkw=($('libMaterialKeyword')?.value||'').toLowerCase().trim(),mp=($('libModelPrefix')?.value||'').toLowerCase().trim();const start=$('libStart')?.value?new Date($('libStart').value+'T00:00:00'):null,end=$('libEnd')?.value?new Date($('libEnd').value+'T23:59:59'):null,cmin=$('libCostMin')?.value!==''?Number($('libCostMin').value):null,cmax=$('libCostMax')?.value!==''?Number($('libCostMax').value):null,rmin=$('libRowsMin')?.value!==''?Number($('libRowsMin').value):null,rmax=$('libRowsMax')?.value!==''?Number($('libRowsMax').value):null;let arr=projects.filter(p=>{const t=totals(p),rows=(p.rows||[]).length,dt=libDateObj(p,timeField);if(lc&&p.customer!==lc)return false;if(lt&&p.productType!==lt)return false;if(cur&&p.currency!==cur)return false;if(img==='with'&&!p.productImage)return false;if(img==='without'&&p.productImage)return false;if(start&&(!dt||dt<start))return false;if(end&&(!dt||dt>end))return false;if(cmin!==null&&t.total<cmin)return false;if(cmax!==null&&t.total>cmax)return false;if(rmin!==null&&rows<rmin)return false;if(rmax!==null&&rows>rmax)return false;if(mp&&!String(p.model||'').toLowerCase().startsWith(mp))return false;if(matkw&&!libraryRowText(p).includes(matkw))return false;if(kw&&!libraryProjectText(p).includes(kw))return false;return true});const sort=$('libSort')?.value||'updatedDesc';arr.sort((a,b)=>sort==='costDesc'?totals(b).total-totals(a).total:sort==='costAsc'?totals(a).total-totals(b).total:sort==='createdDesc'?String(b.createdAt).localeCompare(String(a.createdAt)):sort==='createdAsc'?String(a.createdAt).localeCompare(String(b.createdAt)):sort==='customerAsc'?String(a.customer).localeCompare(String(b.customer),'zh-CN'):sort==='modelAsc'?String(a.model).localeCompare(String(b.model),'zh-CN'):sort==='rowsDesc'?(b.rows||[]).length-(a.rows||[]).length:String(b.updatedAt).localeCompare(String(a.updatedAt)));return arr}
function libGroupKey(p){const group=$('libGroup')?.value||'customer';return group==='none'?'全部成本单':group==='productType'?(p.productType||'未分类'):group==='month'?(String(p.createdAt||p.updatedAt||'').slice(0,7)||'未指定月份'):group==='modelPrefix'?(String(p.model||'').slice(0,3)||'其它'):group==='currency'?(p.currency||'未指定币种'):group==='hasImage'?(p.productImage?'有成品图':'无成品图'):(p.customer||'未指定客户')}
function libThumbHtml(p,cls='lib-thumb'){return bomImgTag(p.productImage,cls,esc(String(p.model||p.name||'BOM').slice(0,3).toUpperCase()||'BOM'))}
function libBadges(p){return `<span class="lib-badge">${esc(p.productType||'未分类')}</span><span class="lib-badge">${esc(p.currency||'RMB')}</span><span class="lib-badge">${(p.rows||[]).length}行</span>`}
function renderLibraryList(groups){return Object.entries(groups).map(([k,items])=>`<div class="lib-group-title"><b>${esc(k)}</b><small>${items.length} 个</small></div><div class="lib-list-table-wrap"><table class="lib-list-table"><thead><tr><th>成本单</th><th>客户</th><th>型号</th><th>分类</th><th>行数</th><th>总成本</th><th>建议报价</th><th>创建</th><th>最后保存</th><th>操作</th></tr></thead><tbody>${items.map(p=>{const t=totals(p);return `<tr><td><div class="lib-imgcell">${libThumbHtml(p)}<div class="lib-title"><b>${esc(p.name||'未命名BOM')}</b><small>${esc((p.rows||[]).slice(0,2).map(r=>r.name).filter(Boolean).join(' / '))}</small></div></div></td><td>${esc(p.customer||'-')}</td><td><b>${esc(p.model||'-')}</b></td><td>${esc(p.productType||'未分类')}</td><td class="num">${(p.rows||[]).length}</td><td class="lib-cost num">${money(t.total)}</td><td class="num">${money(t.suggest)}</td><td>${esc(p.createdAt||'')}</td><td>${esc(p.updatedAt||'')}</td><td><button class="small ghost" onclick="showPage('edit');loadProject('${p.id}')">打开</button></td></tr>`}).join('')}</tbody></table></div>`).join('')}
function renderLibraryCards(groups){const cls=libraryView==='large'?'large':libraryView==='medium'?'medium':'small';return Object.entries(groups).map(([k,items])=>`<div class="lib-group-title"><b>${esc(k)}</b><small>${items.length} 个</small></div><div class="lib-card-grid ${cls}">${items.map(p=>{const t=totals(p),small=libraryView==='small';return `<div class="lib-card ${small?'small-card':''}"><div class="lib-card-img ${p.productImage?'':'empty'}">${p.productImage?bomImgTag(p.productImage,'','BOM'):'BOM'}</div><div class="lib-card-body"><div class="lib-card-name">${esc(p.model||p.name||'未命名')}</div><div class="lib-card-meta">${esc(p.name||'')}<br>${esc(p.customer||'未指定客户')}</div><div class="lib-card-meta">${libBadges(p)}</div><div class="lib-card-foot"><span class="lib-cost">${money(t.total)}</span><button class="small ghost" onclick="showPage('edit');loadProject('${p.id}')">打开</button></div></div></div>`}).join('')}</div>`).join('')}
function renderLibrary(){if(!$('libraryList'))return;updateLibraryViewButtons();lastLibraryRows=libraryFilteredProjects();const totalCost=lastLibraryRows.reduce((s,p)=>s+totals(p).total,0),totalQuote=lastLibraryRows.reduce((s,p)=>s+totals(p).suggest,0),rowCount=lastLibraryRows.reduce((s,p)=>s+(p.rows||[]).length,0);$('libCount').textContent=`共 ${lastLibraryRows.length}/${projects.length} 个成本单`;if($('libStatCount'))$('libStatCount').textContent=lastLibraryRows.length+'/'+projects.length;if($('libStatCost'))$('libStatCost').textContent=money(totalCost);if($('libStatQuote'))$('libStatQuote').textContent=money(totalQuote);if($('libStatAvg'))$('libStatAvg').textContent=money(lastLibraryRows.length?totalCost/lastLibraryRows.length:0);if($('libStatRows'))$('libStatRows').textContent=rowCount;if(!lastLibraryRows.length){$('libraryList').innerHTML='<div class="lib-empty">当前筛选没有成本单。可以清空筛选或切换到全部时间。</div>';return}const groups={};lastLibraryRows.forEach(p=>{const k=libGroupKey(p);(groups[k]=groups[k]||[]).push(p)});$('libraryList').innerHTML=libraryView==='list'?renderLibraryList(groups):renderLibraryCards(groups)}
/* V77.2 Excel import/export: 修复 Excel XML 空列 ss:Index 导致关键词跑到供应商 */
function cleanFileName(s){return String(s||'文件').replace(/[\\/:*?"<>|]+/g,'_').slice(0,80)}
function csvEscape(v){return '"'+String(v??'').replaceAll('"','""')+'"'}
function downloadRaw(name,content,mime='text/plain;charset=utf-8',bom=true){const a=document.createElement('a');a.href=URL.createObjectURL(new Blob([bom?'\ufeff':'',content],{type:mime}));a.download=name;a.click();setTimeout(()=>URL.revokeObjectURL(a.href),1500)}
function download(name,content){downloadRaw(name,content,'text/csv;charset=utf-8',true)}
function xesc(v){return String(v??'').replaceAll('&','&amp;').replaceAll('<','&lt;').replaceAll('>','&gt;').replaceAll('"','&quot;')}
function xcell(v){const n=Number(v);const isNum=String(v??'').trim()!=='' && isFinite(n) && !/^0\d+/.test(String(v).trim());return `<Cell><Data ss:Type="${isNum?'Number':'String'}">${xesc(isNum?n:v)}</Data></Cell>`}
function xrow(arr){return '<Row>'+arr.map(xcell).join('')+'</Row>'}
function xsheet(name,rows){return `<Worksheet ss:Name="${xesc(String(name).slice(0,28))}"><Table>${rows.map(xrow).join('')}</Table></Worksheet>`}
function downloadExcel(name,sheets){const xml='<'+ '?xml version="1.0" encoding="UTF-8"?>'+'<'+ '?mso-application progid="Excel.Sheet"?>'+`<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet" xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel" xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet">${sheets.map(s=>xsheet(s.name,s.rows)).join('')}</Workbook>`;downloadRaw(name,xml,'application/vnd.ms-excel;charset=utf-8',false)}
function currentMaterialFilteredRows(){const kw=($('matSearch')?.value||'').toLowerCase(),cat=$('matFilterCategory')?.value||'',brand=$('matFilterBrand')?.value||'',sup=$('matFilterSupplier')?.value||'',dateMode=$('matDateFilter')?.value||'';return materials.filter(m=>(!cat||m.category===cat)&&(!brand||m.brand===brand)&&(!sup||m.supplier===sup)&&materialDatePass(m,dateMode)&&(!kw||[m.category,m.brand,m.name,m.model,m.spec,m.supplier,m.keyword].join(' ').toLowerCase().includes(kw)))}
function bomRowsForExcel(p){return [['序号','类别','物料名称','规格/备注','数量','加工费','表面处理1','处理费1','表面处理2','处理费2','单价','小计','物料ID']].concat((p.rows||[]).map((r,i)=>[i+1,r.category,r.name,r.spec,r.qty,r.process,r.finish,r.finishCost,r.finish2||'',r.finishCost2||0,r.price,rowSub(r),r.materialId||'']))}
function exportCurrentBomExcel(){collect();const p=getCurrent();if(!p){alert('没有当前 BOM');return}const t=totals(p);const info=[['项目','内容'],['成本单名称',p.name],['客户/项目',p.customer],['产品型号',p.model],['产品分类',p.productType],['币种',p.currency],['材料成本',t.mat],['人工费',t.labor],['包装/其它',t.other],['总成本',t.total],['建议报价',t.suggest],['利润金额',t.profit],['利润率/加价率',p.profitRate],['报价模式',p.quoteMode],['创建时间',p.createdAt],['最后保存',p.updatedAt],['备注',p.note]];downloadExcel(cleanFileName(p.name||p.model||'BOM')+'_BOM.xls',[{name:'BOM明细',rows:bomRowsForExcel(p)},{name:'汇总',rows:info}])}
function exportLibraryExcel(){const arr=lastLibraryRows&&lastLibraryRows.length?lastLibraryRows:libraryFilteredProjects();const list=[['名称','客户','型号','产品分类','币种','物料行数','材料成本','总成本','建议报价','创建时间','最后保存']];const detail=[['成本单','客户','型号','序号','类别','物料名称','规格/备注','数量','加工费','表面处理1','处理费1','表面处理2','处理费2','单价','小计']];arr.forEach(p=>{const t=totals(p);list.push([p.name,p.customer,p.model,p.productType,p.currency,(p.rows||[]).length,t.mat,t.total,t.suggest,p.createdAt,p.updatedAt]);(p.rows||[]).forEach((r,i)=>detail.push([p.name,p.customer,p.model,i+1,r.category,r.name,r.spec,r.qty,r.process,r.finish,r.finishCost,r.finish2||'',r.finishCost2||0,r.price,rowSub(r)]))});downloadExcel('BOM成本总表_'+dateInputValue(new Date())+'.xls',[{name:'成本单列表',rows:list},{name:'物料明细',rows:detail}])}
function exportMaterialsExcel(){const arr=currentMaterialFilteredRows();const rows=[['ID','分类','品牌','物料名称','型号/编码','规格/备注','单价','单位','供应商','关键词','创建时间','最后更新','图片']].concat(arr.map(m=>[m.id,m.category,m.brand,m.name,m.model,m.spec,m.price,m.unit,m.supplier,m.keyword,m.created_at||'',m.updated_at||'',m.image||'']));downloadExcel('共享物料库_'+dateInputValue(new Date())+'.xls',[{name:'物料库',rows}])}
function downloadMaterialTemplateExcel(){const rows=[['分类','品牌','物料名称','型号/编码','规格/备注','单价','单位','供应商','关键词'],['芯片','CREE','CXA1820','CXA1820','3000K CRI90',11.3,'PCS','未指定','CREE CXA'],['电源','Eaglerise','伊戈尔圆形内置','CS-15-250 SI','15W 250mA',8.2,'PCS','伊戈尔','驱动 电源']];downloadExcel('共享物料导入模板.xls',[{name:'物料导入模板',rows}])}
function exportCSV(){collect();const p=getCurrent();if(!p)return;let csv='类别,物料名称,规格,数量,加工费,表面处理1,处理费1,表面处理2,处理费2,单价,小计\n';(p.rows||[]).forEach(r=>csv+=[r.category,r.name,r.spec,r.qty,r.process,r.finish,r.finishCost,r.finish2||'',r.finishCost2||0,r.price,rowSub(r)].map(csvEscape).join(',')+'\n');download((p.name||'bom')+'.csv',csv)}
function exportLibraryCSV(){const arr=lastLibraryRows&&lastLibraryRows.length?lastLibraryRows:libraryFilteredProjects();let csv='名称,客户,型号,产品分类,币种,物料行数,总成本,建议报价,创建时间,最后保存\n';arr.forEach(p=>{const t=totals(p);csv+=[p.name,p.customer,p.model,p.productType,p.currency,(p.rows||[]).length,t.total,t.suggest,p.createdAt,p.updatedAt].map(csvEscape).join(',')+'\n'});download('bom_library.csv',csv)}
function exportMaterialsCSV(){const arr=currentMaterialFilteredRows();let csv='分类,品牌,名称,型号,规格,单价,单位,供应商,关键词\n';arr.forEach(m=>csv+=[m.category,m.brand,m.name,m.model,m.spec,m.price,m.unit,m.supplier,m.keyword].map(csvEscape).join(',')+'\n');download('materials.csv',csv)}
function downloadMaterialTemplate(){download('materials_template.csv','分类,品牌,名称,型号,规格,单价,单位,供应商,关键词\n芯片,CREE,CXA1820,CXA1820,3000K CRI90,11.3,PCS,未指定,CREE CXA\n')}
let excelImportKind='materials',excelImportText='',excelImportObjects=[];
function closeExcelImport(){const m=$('excelImportMask');if(m)m.style.display='none'}
function openExcelImport(kind){excelImportKind=kind;excelImportText='';excelImportObjects=[];if($('excelImportFile'))$('excelImportFile').value='';if($('excelImportPaste'))$('excelImportPaste').value='';$('excelPreviewHead').innerHTML='';$('excelPreviewBody').innerHTML='';$('excelImportTitle').textContent=kind==='bom'?'导入 BOM 明细':'导入共享物料';$('excelImportSub').textContent=kind==='bom'?'导入到当前成本单；支持本系统导出的 .xls / CSV / 从Excel复制。':'批量导入物料库；可按名称+型号更新，或直接追加。';$('excelImportMode').innerHTML=kind==='bom'?'<option value="append">追加到当前BOM</option><option value="replace">覆盖当前BOM行</option>':'<option value="upsert">有同名型号则更新</option><option value="append">全部新增</option>';$('excelImportStatus').textContent='等待文件或粘贴内容。';$('excelImportFoot').textContent='先预览，再导入。';$('excelImportMask').style.display='flex'}
function handleExcelImportFile(e){const f=e.target.files&&e.target.files[0];if(!f)return;const name=f.name.toLowerCase();if(name.endsWith('.xlsx')){$('excelImportStatus').textContent='xlsx 需要先在 Excel 另存为 CSV，或用本系统导出的 .xls 再导入。';return}const rd=new FileReader();rd.onload=ev=>{excelImportText=String(ev.target.result||'');$('excelImportStatus').textContent='已读取：'+f.name+'，点预览。';previewExcelImport()};rd.readAsText(f,'UTF-8')}
function parseCSV(text,delim){const rows=[];let row=[],cell='',q=false;for(let i=0;i<text.length;i++){const c=text[i],n=text[i+1];if(q){if(c==='"'&&n==='"'){cell+='"';i++}else if(c==='"'){q=false}else cell+=c}else{if(c==='"')q=true;else if(c===delim){row.push(cell);cell=''}else if(c==='\n'){row.push(cell);rows.push(row);row=[];cell=''}else if(c==='\r'){}else cell+=c}}row.push(cell);if(row.some(x=>String(x).trim()!==''))rows.push(row);return rows}
function parseImportTables(text){
  text=String(text||'').trim();
  if(!text)return {'Sheet1':[]};
  if(text.includes('<Workbook')||text.includes('<Worksheet')){
    const doc=new DOMParser().parseFromString(text,'text/xml');
    const out={};
    [...doc.getElementsByTagName('Worksheet')].forEach((ws,idx)=>{
      const nm=ws.getAttribute('ss:Name')||ws.getAttribute('Name')||('Sheet'+(idx+1));
      const rows=[...ws.getElementsByTagName('Row')].map(r=>{
        const arr=[]; let cursor=1;
        [...r.children].forEach(c=>{
          if((c.localName||c.nodeName).toLowerCase().indexOf('cell')<0)return;
          const ixAttr=c.getAttribute('ss:Index')||c.getAttribute('Index');
          const ix=ixAttr?parseInt(ixAttr,10):cursor;
          while(arr.length<ix-1)arr.push('');
          const d=[...c.children].find(x=>(x.localName||x.nodeName).toLowerCase().indexOf('data')>=0);
          arr.push(d?d.textContent:'');
          cursor=ix+1;
        });
        return arr;
      });
      out[nm]=rows;
    });
    return out;
  }
  if(/<table[\s>]/i.test(text)){
    const doc=new DOMParser().parseFromString(text,'text/html'),out={};
    [...doc.querySelectorAll('table')].forEach((tb,i)=>{
      out['Table'+(i+1)]=[...tb.querySelectorAll('tr')].map(tr=>[...tr.children].map(td=>td.textContent.trim()))
    });
    return out;
  }
  return {'Sheet1':parseCSV(text,text.includes('\t')?'\t':',')};
}
function chooseImportRows(tables,kind){const names=Object.keys(tables);let key=names[0];if(kind==='bom')key=names.find(n=>/bom|明细/i.test(n))||key;if(kind==='materials')key=names.find(n=>/物料|material/i.test(n))||key;return tables[key]||[]}
function normHeader(h){return String(h||'').trim().replace(/[\s_\/\\-]/g,'').toLowerCase()}
function numberClean(v){const s=String(v??'').replace(/[￥¥, ]/g,'');const n=Number(s);return isFinite(n)?n:0}
function mapRowsToObjects(rows,kind){rows=(rows||[]).filter(r=>r&&r.some(c=>String(c||'').trim()!==''));if(!rows.length)return[];let header=rows[0].map(normHeader),start=1;const known=kind==='bom'?['类别','物料名称','规格','数量','单价'].map(normHeader):['分类','品牌','物料名称','名称','单价'].map(normHeader);if(!$('excelHeaderMode')||$('excelHeaderMode').value==='auto'){const score=header.filter(h=>known.includes(h)).length;if(score<2){start=0;header=[]}}const find=(names)=>{const ns=names.map(normHeader);for(let i=0;i<header.length;i++)if(ns.includes(header[i]))return i;return -1};const get=(r,names,defIx)=>{const ix=find(names);return String((ix>=0?r[ix]:r[defIx])??'').trim()};const out=[];for(let i=start;i<rows.length;i++){const r=rows[i];if(kind==='materials'){const o={category:get(r,['分类','类别','category'],0),brand:get(r,['品牌','brand'],1),name:get(r,['物料名称','名称','name','material'],2),model:get(r,['型号','型号编码','型号/编码','model','code'],3),spec:get(r,['规格','规格备注','规格/备注','备注','spec'],4),price:numberClean(get(r,['单价','价格','price'],5)),unit:get(r,['单位','unit'],6)||'PCS',supplier:get(r,['供应商','supplier'],7),keyword:get(r,['关键词','keyword'],8),image:get(r,['图片','image'],12)};if(o.name||o.model)out.push(o)}else{const o={category:get(r,['类别','分类','category'],0),name:get(r,['物料名称','名称','name'],1),spec:get(r,['规格','规格备注','规格/备注','备注','spec'],2),qty:numberClean(get(r,['数量','qty'],3))||1,process:numberClean(get(r,['加工费','process'],4)),finish:get(r,['表面处理1','表面处理','面1','finish1'],5),finishCost:numberClean(get(r,['处理费1','费1','finishcost1'],6)),finish2:get(r,['表面处理2','面2','finish2'],7),finishCost2:numberClean(get(r,['处理费2','费2','finishcost2'],8)),price:numberClean(get(r,['单价','price'],9)),materialId:get(r,['物料id','materialid'],12)};if(o.name||o.spec||o.category)out.push(o)}}return out}
function previewExcelImport(){const text=($('excelImportPaste')?.value||'').trim() || excelImportText;if(!text){$('excelImportStatus').textContent='没有内容。';return}const rows=chooseImportRows(parseImportTables(text),excelImportKind);excelImportObjects=mapRowsToObjects(rows,excelImportKind);$('excelImportStatus').textContent=`识别到 ${excelImportObjects.length} 行。`;const keys=excelImportKind==='materials'?['category','brand','name','model','spec','price','unit','supplier','keyword']:['category','name','spec','qty','process','finish','finishCost','finish2','finishCost2','price'];const title=excelImportKind==='materials'?['分类','品牌','名称','型号','规格','单价','单位','供应商','关键词']:['类别','物料名称','规格','数量','加工费','面1','费1','面2','费2','单价'];$('excelPreviewHead').innerHTML='<tr>'+title.map(h=>`<th>${esc(h)}</th>`).join('')+'</tr>';$('excelPreviewBody').innerHTML=excelImportObjects.slice(0,30).map(o=>'<tr>'+keys.map(k=>`<td>${esc(o[k])}</td>`).join('')+'</tr>').join('');$('excelImportFoot').textContent=excelImportObjects.length>30?`预览前30行，共${excelImportObjects.length}行。`:`共${excelImportObjects.length}行。`}
async function executeExcelImport(){if(!excelImportObjects.length)previewExcelImport();if(!excelImportObjects.length){alert('没有可导入数据');return}const mode=$('excelImportMode')?.value||'append';if(excelImportKind==='bom'){collect();const p=getCurrent();if(!p){alert('请先打开一个BOM');return}const rows=excelImportObjects.map(o=>({...o,finishMode2:!!(o.finish2||Number(o.finishCost2)>0)}));if(mode==='replace'){if(!confirm('确定覆盖当前 BOM 明细行？'))return;p.rows=rows}else p.rows=(p.rows||[]).concat(rows);renderRows();calc();touch();closeExcelImport();setStatus('已导入BOM明细，记得保存。');return}const r=await api('import_materials_bulk',{rows:excelImportObjects,mode});if(!r.ok){alert(r.error||'导入失败');return}materialFocusId=(r.last_id||'');await loadAll();showPage('materials');closeExcelImport();setStatus(`物料导入完成：新增 ${r.inserted||0}，更新 ${r.updated||0}，跳过 ${r.skipped||0}，重复 ${r.duplicates||0}`)}

window.onload=function(){ applyBomHeaderCollapse(); checkAuth(); };
</script>
</body>
</html>
