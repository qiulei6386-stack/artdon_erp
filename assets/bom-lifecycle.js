/* Read-only history and where-used dialogs. All business text is escaped. */
window.BomLifecycle=(()=>{
  let dialog=null,serial=0;
  const money=n=>Number(n||0).toFixed(4);
  function close(){serial++;dialog?.remove();dialog=null;}
  function show(title,body){
    dialog?.remove();dialog=document.createElement('dialog');dialog.style.cssText='width:min(1100px,94vw);max-height:88vh;border:1px solid #cbd5e1;border-radius:12px;padding:20px;overflow:auto';
    dialog.innerHTML=`<div style="display:flex;justify-content:space-between"><h3>${esc(title)}</h3><button data-close>关闭</button></div>${body}`;
    dialog.querySelector('[data-close]').onclick=close;dialog.addEventListener('close',close);document.body.appendChild(dialog);dialog.showModal();
  }
  async function history(materialId,projectUid='',page=1){
    const request=++serial;show('价格变更记录','<p>读取中…</p>');
    try{const r=await api('price_history',{material_id:materialId||0,project_uid:projectUid,page});if(request!==serial)return;if(!r.ok)throw new Error(r.error);
      show('价格变更记录',`<p>共 ${r.total} 条；保留原记录，修正通过新记录追加。</p><table><thead><tr><th>时间</th><th>修改人 / 账号</th><th>项目</th><th>原价</th><th>新价</th><th>差额</th><th>原因 / 来源</th></tr></thead><tbody>${r.events.map(e=>`<tr><td>${esc(e.created_at)}</td><td>${esc(e.actor)}<br><small>${esc(e.actor_account)}</small></td><td>${esc(e.field_name)}${e.row_no===null?'':' · 第'+(Number(e.row_no)+1)+'行'}</td><td>${e.old_price===null?'—':money(e.old_price)}</td><td>${e.new_price===null?'—':money(e.new_price)}</td><td>${e.delta===null?'—':money(e.delta)}</td><td>${esc(e.reason)}<br><small>${esc(e.source)} · ${esc(e.batch_id)}</small></td></tr>`).join('')||'<tr><td colspan="7">暂无记录</td></tr>'}</tbody></table><div><button data-prev ${page<=1?'disabled':''}>上一页</button> ${r.page}/${r.pages} <button data-next ${page>=r.pages?'disabled':''}>下一页</button></div>`);
      dialog.querySelector('[data-prev]').onclick=()=>history(materialId,projectUid,page-1);dialog.querySelector('[data-next]').onclick=()=>history(materialId,projectUid,page+1);
    }catch(e){if(request===serial)show('价格记录',`<p role="alert">${esc(e.message)}</p>`);}
  }
  async function used(id){
    const request=++serial;show('使用此物料的 BOM','<p>读取中…</p>');
    try{const r=await api('material_where_used',{material_id:id});if(request!==serial)return;if(!r.ok)throw new Error(r.error);
      const allRows=[...r.usages,...(r.text_candidates||[])];
      show('使用此物料的 BOM',`<p>物料ID关联 ${r.usages.length} 行；另有 ${(r.text_candidates||[]).length} 行文字候选（最多100条）。文字候选与身份待核对的关联不参与标准价自动更新。</p><table><thead><tr><th>型号 / BOM</th><th>阶段 / 版本</th><th>明细行</th><th>数量</th><th>原行单价</th><th>关联</th><th>操作</th></tr></thead><tbody>${allRows.map((u,i)=>`<tr><td>${esc(u.model)}<br>${esc(u.name)}</td><td>${esc(bomReviewLabel(u.review_status))} · ${esc(u.version_no)}</td><td>${u.row_no}</td><td>${esc(u.qty)}</td><td>${u.price===undefined?'—':money(u.price)}</td><td>${u.binding_status==='text_candidate'?'文字候选 · 未确认':u.binding_status==='needs_identity'?'身份待核对':'物料ID关联'}</td><td><button data-bom="${i}">打开BOM</button></td></tr>`).join('')||'<tr><td colspan="7">暂无使用记录</td></tr>'}</tbody></table>`);
      dialog.querySelectorAll('[data-bom]').forEach(b=>b.onclick=()=>{const u=allRows[Number(b.dataset.bom)];close();showPage('edit');loadProject(u.project_uid);});
    }catch(e){if(request===serial)show('物料使用记录',`<p role="alert">${esc(e.message)}</p>`);}
  }
  function costDetails(){
    const p=getCurrent(),c=p?.currentReference;if(!c)return;
    show('当前参考成本与原方案成本',`<p>当前 ${money(c.cost)}；终审快照 ${c.approval_cost===null?'—':money(c.approval_cost)}。待确认标准价时继续采用原BOM行价格。</p><table><thead><tr><th>行</th><th>物料</th><th>原行单价</th><th>当前单价</th><th>成本差额</th><th>来源 / 价格记录</th></tr></thead><tbody>${c.material_prices.map(v=>`<tr><td>${v.row_no+1}</td><td>${esc(p.rows[v.row_no]?.name||'')} · #${v.material_id}</td><td>${money(v.stored_price)}</td><td>${money(v.current_price)}</td><td>${money(v.delta)}</td><td>${v.source==='material_standard'?'已确认标准价 #'+v.price_version:'沿用BOM行价格'} ${v.material_id?`<button data-material="${v.material_id}">查看记录</button>`:''}</td></tr>`).join('')}</tbody></table>`);
    dialog.querySelectorAll('[data-material]').forEach(b=>b.onclick=()=>history(Number(b.dataset.material)));
  }
  return {history,used,costDetails,close};
})();
