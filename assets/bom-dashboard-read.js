'use strict';
const bomDashboardRead = {keyword:null, ids:null, state:'idle', sequence:0, timer:null, controller:null, filterKey:'', epoch:0, images:new Map(), imageBusy:false};
async function bomDashboardRequest(action, data, controller=new AbortController()) {
  const timer=setTimeout(()=>controller.abort(),8000);
  try {
    const response=await fetch(API+'?action='+encodeURIComponent(action),{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(data),credentials:'same-origin',cache:'no-store',signal:controller.signal});
    const r=await response.json();
    if(response.status===401 || r.need_login || r.login_required || r.auth_required){bomSsoRedirect();throw new Error('登录已失效');}
    if(!response.ok || !r.ok)throw new Error('读取失败');
    return r;
  } finally {clearTimeout(timer);}
}
function bomDashboardResetRead() {
  clearTimeout(bomDashboardRead.timer);bomDashboardRead.controller?.abort();
  Object.assign(bomDashboardRead,{keyword:null,ids:null,state:'idle',sequence:bomDashboardRead.sequence+1,filterKey:'',epoch:bomDashboardRead.epoch+1,images:new Map()});
}
function bomDashboardSyncSearch() {
  const s=bomDashboardRead, kw=String($('dashKeyword')?.value||'').trim().toLowerCase();
  const key=JSON.stringify([kw,dashboardRange,...['dashCustomer','dashType','dashTimeField','dashSort','dashStart','dashEnd'].map(id=>$(id)?.value)]);
  if(key!==s.filterKey){s.filterKey=key;dashboardPage=1;}
  if(kw===s.keyword)return;
  clearTimeout(s.timer);s.controller?.abort();s.sequence++;s.keyword=kw;s.ids=null;s.state=kw?'loading':'idle';
  if(!kw)return;
  const sequence=s.sequence;
  s.timer=setTimeout(async()=>{
    const controller=new AbortController();s.controller=controller;
    try {
      const r=await bomDashboardRequest('dashboard_search',{keyword:kw},controller);
      if(sequence!==s.sequence)return;
      if(!Array.isArray(r.project_ids))throw new Error('搜索结果格式异常');
      s.ids=new Set(r.project_ids.map(String));s.state='ready';
    } catch(e) {if(sequence!==s.sequence)return;s.state='error';}
    if(currentPage==='dashboard')renderDashboard();
  },250);
}
function bomDashboardKeywordMatch(p, kw) {
  if(!kw)return true;
  if(bomDashboardRead.state==='ready' && bomDashboardRead.keyword===kw)return bomDashboardRead.ids.has(String(p.id));
  return [p.name,p.customer,p.model,p.namingType,p.productType].join(' ').toLowerCase().includes(kw);
}
function bomDashboardSearchHint() {
  const s=bomDashboardRead;
  const message=s.state==='loading'?'正在搜索物料，当前先显示名称/型号匹配…':s.state==='error'?'物料搜索失败，当前仅显示名称/型号匹配，请重试。':s.state==='ready'?`搜索完成：全部时间匹配 ${s.ids.size} 项，当前范围 ${lastDashboardRows.length} 项。`:'';
  if($('dashSearchStatus'))$('dashSearchStatus').textContent=message;
  if($('dashSearchRetry'))$('dashSearchRetry').hidden=s.state!=='error';
  if($('dashSearchAll'))$('dashSearchAll').hidden=!s.keyword || dashboardRange==='all';
}
function bomDashboardRetrySearch(){bomDashboardRead.keyword=null;renderDashboard();}
function bomDashboardImageHtml(p) {
  const item=bomDashboardRead.images.get(String(p.id));
  const src=item?.url || String(p.productImage||'').trim();
  if(src)return `<img src="${esc(src)}" alt="${esc(p.model||p.name||'BOM')}" loading="lazy" decoding="async" onerror="this.replaceWith(Object.assign(document.createElement('span'),{className:'empty',textContent:'图片暂不可用'}))">`;
  const label=!item?'图片加载中…':item.status==='error'?'图片加载失败，刷新重试':item.status==='oversized'?'图片过大，请打开详情查看':'暂无图片';
  return `<div class="empty">${label}</div>`;
}
async function bomDashboardLoadImages(pageRows) {
  const s=bomDashboardRead;
  if(dashboardView==='list' || s.imageBusy)return;
  const ids=pageRows.filter(p=>!s.images.has(String(p.id))).map(p=>String(p.id)).slice(0,18);
  if(!ids.length)return;
  s.imageBusy=true;const epoch=s.epoch;
  try {
    const r=await bomDashboardRequest('dashboard_images',{project_ids:ids});
    if(epoch!==s.epoch)return;
    if(!Array.isArray(r.images))throw new Error('图片信息格式异常');
    ids.forEach(id=>s.images.set(id,{url:'',status:'missing'}));
    r.images.forEach(item=>{if(ids.includes(String(item.project_uid)))s.images.set(String(item.project_uid),item);});
  } catch(e) {if(epoch===s.epoch)ids.forEach(id=>s.images.set(id,{url:'',status:'error'}));}
  finally {s.imageBusy=false;if(currentPage==='dashboard')renderDashboard();}
}
