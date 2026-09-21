/* BOM versions: read on demand; no automatic rewrite of an existing quote. */
window.QuoteBomVersions=(()=>{
  let modal=null,serial=0,context=null,returnFocus=null;
  const productInput=p=>({id:p.id,source:p.source,naming_id:p.naming_id,code:p.code,model:p.model,model_no:p.model_no,naming_model_no:p.naming_model_no});
  const locked=()=>S.currentApprovalStatus==='approved';
  const same=c=>c&&S.product===c.product&&S.currentQuoteId===c.quoteId&&S.editingIndex===c.index;
  const money4=n=>Number(n||0).toFixed(4).replace(/0{1,2}$/,'');
  function hint(){
    const el=$('productLinkHint'),p=S.product;if(!el||!p)return;
    const v=p.bom_version;
    if(v)el.querySelector('.quote-spec-actions')?.remove();
    const text=v?`采用 ${v.version_no} · ${v.snapshot_uid} ｜ 审核 ${v.approved_at}`:(p._bomMessage||'可查看审核版本、历史成本和更新时间');
    el.insertAdjacentHTML('beforeend',`<div class="qbv-inline"><span>${esc(text)}</span><button type="button" onclick="QuoteBomVersions.open()">BOM 版本 / 更新</button></div>`);
  }
  async function prepare(p){
    if(!p)return true;p._bomLoading=true;p._bomBlocked=true;
    const c={product:p,quoteId:S.currentQuoteId,index:S.editingIndex};
    try{
      const r=await api('bom_quote_version',{product:productInput(p)});if(!same(c))return false;
      if(r.choose){p._bomMessage=r.message;await open();return false;}
      if(r.patch){Object.assign(p,r.patch);p._bomMessage='';}
      else p._bomMessage=r.message||'暂无审核快照';
      p._bomBlocked=false;return true;
    }catch(e){if(same(c)){p._bomMessage='版本读取失败，请点击“BOM 版本 / 更新”重试：'+e.message;}return false;}
    finally{p._bomLoading=false;if(same(c))updateProductLinkHint();}
  }
  function close(){serial++;modal?.remove();modal=null;context=null;returnFocus?.focus?.();}
  async function open(page=1){
    if(!S.product)return alert('请先选择产品');
    if(!modal){
      returnFocus=document.activeElement;context={product:S.product,quoteId:S.currentQuoteId,index:S.editingIndex};
      document.body.insertAdjacentHTML('beforeend',`<div id="quoteBomVersions" class="qbv-overlay" role="dialog" aria-modal="true" aria-labelledby="qbvTitle"><section class="qbv-dialog"><header><div><h3 id="qbvTitle">BOM 版本与成本</h3><p>只查看不会改价。选择版本后，成本和关键件一起采用该快照。</p></div><button type="button" data-qbv-close>关闭</button></header><main id="qbvBody"></main><footer id="qbvFooter"></footer></section></div>`);
      modal=$('quoteBomVersions');modal.addEventListener('click',event=>{
        const b=event.target.closest('button');if(!b)return;
        if(b.hasAttribute('data-qbv-close'))close();
        else if(b.dataset.qbvPage)open(Number(b.dataset.qbvPage));
        else if(b.dataset.qbvPick)preview(Number(b.dataset.qbvPick));
        else if(b.hasAttribute('data-qbv-apply'))apply();
      });
      modal.addEventListener('keydown',e=>{if(e.key==='Escape'){e.preventDefault();close();}if(e.key==='Tab'){const focus=[...modal.querySelectorAll('button:not(:disabled)')];if(!focus.length)return;const first=focus[0],last=focus.at(-1);if(e.shiftKey&&document.activeElement===first){e.preventDefault();last.focus();}else if(!e.shiftKey&&document.activeElement===last){e.preventDefault();first.focus();}}});
      modal.querySelector('button').focus();
    }
    const c=context,request=++serial;$('qbvBody').innerHTML='<p role="status">正在读取版本摘要…</p>';$('qbvFooter').textContent='';
    try{
      const r=await api('bom_quote_versions',{product:productInput(c.product),page});
      if(request!==serial||!same(c)||!modal)return;
      c.catalog=r;c.preview=null;
      const current=c.product.bom_version;
      const note=locked()?'该报价已审核，只能查看；如需换版，请先另存新报价。':!r.total?'尚无正式审核快照；历史成本保持冻结，审核通过后才会出现可选版本。':r.projects>1?'有多份同型号BOM，请按客户/用途明确选择；不自动取最高价。':'新建报价默认采用这份BOM当前有效的审核版本。';
      $('qbvBody').innerHTML=`<p class="qbv-notice">${esc(note)}</p>${current?`<p>本行当前：<b>${esc(current.version_no)} · ${esc(current.snapshot_uid)}</b> · RMB ${money4(current.cost_rmb)}</p>`:'<p>本行尚未绑定具体审核版本，历史单据不会自动改价。</p>'}<div class="qbv-list">${r.versions.map(v=>`<article><div><b>${esc(v.name||v.model)}</b><span class="qbv-state">${current?.snapshot_id===v.snapshot_id?'本行采用 · ':''}${v.current?'当前有效':'历史快照'}</span></div><div>${esc(v.version_no)} · ${esc(v.variant_label)} · 客户 ${esc(v.customer||'通用/未填写')}</div><div class="qbv-cost"><strong>RMB ${money4(v.cost_rmb)}</strong><span>审核 ${esc(v.approved_at)}${v.published_at?'<br>发布 '+esc(v.published_at):''}</span></div><small>${esc(v.snapshot_uid)}</small><button type="button" data-qbv-pick="${v.snapshot_id}">${locked()?'查看内容':'核对并选择'}</button></article>`).join('')}</div>${r.legacy.map(l=>`<p class="qbv-notice">${esc(l.name)} · ${esc(l.status)} · RMB ${money4(l.cost_rmb)}<br>冻结时间 ${esc(l.updated_at)}。不是正式审核版本，不从未审核草稿更新关键件。</p>`).join('')}${!r.total&&!r.legacy.length?'<p>没有匹配的BOM审核版本，请核对完整型号与命名绑定。</p>':''}`;
      $('qbvFooter').innerHTML=`<button data-qbv-page="${Math.max(1,r.page-1)}" ${r.page<=1?'disabled':''}>上一页</button><span>第 ${r.page} / ${r.pages} 页 · ${r.total} 个版本</span><button data-qbv-page="${r.page+1}" ${r.page>=r.pages?'disabled':''}>下一页</button>`;
    }catch(e){if(request===serial&&modal){$('qbvBody').innerHTML=`<p role="alert">${esc(e.message)}</p><button data-qbv-page="${page}">重试</button>`;}}
  }
  async function preview(id){
    const c=context;if(!same(c))return close();const v=c.catalog.versions.find(v=>v.snapshot_id===id);if(!v)return;
    const request=++serial;$('qbvFooter').textContent='正在按此版本读取成本和关键件…';
    try{
      const r=await api('bom_quote_version',{product:productInput(c.product),snapshot_id:id,expected_publication:v.publication_snapshot_id});
      if(request!==serial||!same(c)||!modal)return;c.preview=r;
      const old=c.product.bom_version,from=Number(c.product.cost_rmb??c.product.price_rmb??0),to=r.version.cost_rmb;
      const keys=[...new Set([...Object.keys(c.product.quote_spec||{}),...Object.keys(r.patch.quote_spec||{})])];
      $('qbvBody').innerHTML=`<p><b>${esc(r.version.name)}</b> · ${esc(r.version.version_no)}<br>${esc(r.version.snapshot_uid)} · 审核 ${esc(r.version.approved_at)}</p><p class="qbv-notice">${r.version.current?'当前有效审核版本。':'注意：这是历史版本，不是当前发布版本。'} ${locked()?'已审核报价仅查看。':'确认只更新本行BOM基础成本及关键件；手工售价、另选部件、数量和备注保留。自动计算的售价可能改变，请核对。'}</p><div class="qbv-diff"><div>项目</div><div>当前报价</div><div>准备采用</div><div>版本</div><div>${esc(old?.snapshot_uid||'未绑定')}</div><div>${esc(r.version.snapshot_uid)}</div><div>基础成本 RMB</div><div>${money4(from)}</div><div>${money4(to)}</div>${keys.map(k=>`<div>${esc(r.patch.quote_spec[k]?.label||c.product.quote_spec?.[k]?.label||k)}</div><div>${esc(c.product.quote_spec?.[k]?.value||'—')}</div><div>${esc(r.patch.quote_spec[k]?.value||'—')}</div>`).join('')}</div>`;
      $('qbvFooter').innerHTML=`<button data-qbv-page="${c.catalog.page}">返回版本列表</button><button data-qbv-apply ${locked()?'disabled':''}>${r.version.current?'确认采用此版本':'确认使用历史版本'}</button>`;
    }catch(e){if(request===serial&&modal)$('qbvFooter').innerHTML=`<span role="alert">${esc(e.message)}</span><button data-qbv-page="${c.catalog.page}">重新读取</button>`;}
  }
  async function apply(){
    const c=context;if(!same(c)||locked()||!c.preview)return;
    const chosen=c.preview,button=modal.querySelector('[data-qbv-apply]');button.disabled=true;
    const request=++serial;c.product._bomLoading=true;
    try{
      // Recheck publication at confirmation; no hidden switch if another approval just finished.
      const r=await api('bom_quote_version',{product:productInput(c.product),snapshot_id:chosen.version.snapshot_id,expected_publication:chosen.version.publication_snapshot_id});
      if(request!==serial||!same(c)||!modal)return;
      await refreshQuotePricePolicy(false);
      if(request!==serial||!same(c)||!modal)return;
      Object.assign(c.product,r.patch);c.product._bomBlocked=false;c.product._bomMessage='';c.product._bomLoading=false;
      close();renderParts();updatePriceLevelHint();updateProductLinkHint();
      if(S.editingIndex>=0)syncEditingItemFromForm();else autoAddSelectedProductLine();
      renderQuoteItems();render();
    }catch(e){if(request===serial&&modal){button.disabled=false;alert(e.message);}}
    finally{c.product._bomLoading=false;}
  }
  async function check(){
    const p=S.product;if(!p)return;const c={product:p,quoteId:S.currentQuoteId,index:S.editingIndex};
    try{const r=await api('bom_quote_versions',{product:productInput(p),page:1});if(!same(c))return;
      const v=p.bom_version,newer=v&&(r.current_publications||[]).find(x=>x.project_uid===v.project_uid&&x.snapshot_id!==v.snapshot_id);
      if(newer)p._bomMessage='有新审核版本可核对，当前报价保持原版本';
      if(newer){const el=$('productLinkHint');el?.insertAdjacentHTML('beforeend','<p class="qbv-warning">有新审核版本，请点“BOM 版本 / 更新”核对；不会自动改价。</p>');}
    }catch(e){if(same(c))$('productLinkHint')?.insertAdjacentHTML('beforeend','<p class="qbv-warning">暂未检查到最新版本，可点击版本按钮重试。现有报价不变。</p>');}
  }
  return {open,close,prepare,check,hint,blocked:()=>!!(S.product?._bomLoading||S.product?._bomBlocked)};
})();
const qbvOldHint=updateProductLinkHint;updateProductLinkHint=function(){qbvOldHint();QuoteBomVersions.hint();};
const qbvOldLoad=loadItemToEditor;loadItemToEditor=function(...args){QuoteBomVersions.close();qbvOldLoad(...args);QuoteBomVersions.check();};
for(const name of ['saveQuote','addOrUpdateQuoteItem','syncEditingItemFromForm']){
  const old=window[name];if(typeof old!=='function')continue;
  window[name]=function(...args){if(QuoteBomVersions.blocked()){if(name!=='syncEditingItemFromForm')alert('请先完成BOM版本读取或选择，再保存报价。');return;}return old(...args);};
}
