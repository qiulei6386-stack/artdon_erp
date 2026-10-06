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
  function profileTable(profiles){
    if(!profiles?.length)return '';
    const headers=['币种','加工费','表面处理1 / 费','表面处理2 / 费','材料单价','单件合计'];
    const finish=(label,fee)=>`${esc(label||'未设置')}<br>${money(fee)}`;
    return `<div class="mat-bom-prices" role="table" aria-label="BOM单件费用"><div class="mat-bom-price-row mat-bom-price-head" role="row">${headers.map(h=>`<span role="columnheader">${h}</span>`).join('')}</div>${profiles.map(p=>`<div class="mat-bom-price-row" role="row"><span role="cell">${esc(p.currency||'RMB')}<br><small>${p.bom_count}份BOM</small></span><span role="cell">${money(p.process)}</span><span role="cell">${finish(p.finish,p.finishCost)}</span><span role="cell">${finish(p.finish2,p.finishCost2)}</span><span role="cell">${money(p.price)}<br><small>${p.price_source==='material_standard'?'生效标准价':'BOM行价格'}</small></span><strong role="cell">${money(p.unit_cost)}</strong></div>`).join('')}</div>`;
  }
  function materialCell(m){
    if(m.price==='')return '<small>无成本查看权限</small>';
    const c=m.bom_costs,profiles=c?.profiles||[];
    const note=profiles.length?`${c.profile_count} 种费用方案，单件合计含材料、加工及两次表面处理；用量另计。`:'暂无名称、型号及规格相符的BOM费用，加工/表面费用未配置。';
    return `${profileTable(profiles)}<div class="hint">${esc(note)}${c?.profile_count>3?` <button type="button" class="small ghost" onclick="BomLifecycle.used(${Number(m.id)})">查看全部费用方案</button>`:''}</div><div class="mat-master-price">库内材料单价（未含加工/表面）：<span ${hasPerm('materials')?'contenteditable="true"':''} data-field="price" onblur="saveMaterialCell(this)">${esc(money(m.price))}</span>${m.confirmed_price!=null?` · 生效标准价 ${money(m.confirmed_price)}`:''}</div>`;
  }
  async function used(id){
    const request=++serial;show('使用此物料的 BOM','<p>读取中…</p>');
    try{const r=await api('material_where_used',{material_id:id});if(request!==serial)return;if(!r.ok)throw new Error(r.error);
      const rows=r.usages||[],m=r.material||{},cost=rows.some(u=>u.price!==undefined);
      const fees=u=>`<td>${money(u.process)}</td><td>${esc(u.finish||'未设置')} / ${money(u.finishCost)}</td><td>${esc(u.finish2||'未设置')} / ${money(u.finishCost2)}</td><td>${money(u.price)}</td><td>${money(u.unit_cost)}</td><td>${money(u.subtotal)}</td>`;
      show('使用此物料的 BOM',`<p><b>${esc(m.name||'')}</b> · 型号 ${esc(m.model||'—')}<br>规格：${esc(m.spec||'未填写')} · 单位：${esc(m.unit||'待确认')}</p><p>名称、型号、规格核对相符：${r.total??rows.length} 行。${r.excluded_count?`已排除 ${r.excluded_count} 条规格不符或失效关联。`:''}同名不同规格不会列入结果。${(r.total||0)>rows.length?`当前显示前 ${rows.length} 行。`:''}</p>${profileTable(r.cost_profiles)}<div style="overflow-x:auto"><table style="min-width:${cost?1500:850}px"><thead><tr><th>型号 / BOM</th><th>阶段 / 版本</th><th>行</th><th>物料名称 / 型号</th><th>用料规格 / 单位</th><th>数量</th><th>币种</th>${cost?'<th>加工费</th><th>表面处理1 / 费</th><th>表面处理2 / 费</th><th>材料单价</th><th>单件合计</th><th>行小计</th>':''}<th>操作</th></tr></thead><tbody>${rows.map((u,i)=>`<tr><td>${esc(u.model)}<br>${esc(u.name)}</td><td>${esc(bomReviewLabel(u.review_status))} · ${esc(u.version_no)}</td><td>${u.row_no}</td><td>${esc(u.material_name)}<br>${esc(u.material_model||'')}</td><td>${esc(u.spec||'未填写')}<br>${esc(u.unit||'单位待确认')}</td><td>${esc(u.qty)}</td><td>${esc(u.currency||'RMB')}</td>${cost?fees(u):''}<td><button data-bom="${i}">打开BOM第${u.row_no}行</button></td></tr>`).join('')||'<tr><td colspan="14">暂无规格相符的使用记录。</td></tr>'}</tbody></table></div>`);
      dialog.querySelectorAll('[data-bom]').forEach(b=>b.onclick=async()=>{const u=rows[Number(b.dataset.bom)];close();showPage('edit');await loadProject(u.project_uid);if(getCurrent()?.id!==u.project_uid||!bomEditorReady())return;const tr=$('tbody')?.rows[Number(u.row_no)-1];if(tr){tr.scrollIntoView({behavior:'smooth',block:'center'});tr.classList.add('mat-row-focus');setTimeout(()=>tr.classList.remove('mat-row-focus'),4200);}});
    }catch(e){if(request===serial)show('物料使用记录',`<p role="alert">${esc(e.message)}</p>`);}
  }
  function costDetails(){
    const p=getCurrent(),c=p?.currentReference;if(!c)return;
    show('当前参考成本与原方案成本',`<p>当前 ${money(c.cost)}；终审快照 ${c.approval_cost===null?'—':money(c.approval_cost)}。待确认标准价时继续采用原BOM行价格。</p><table><thead><tr><th>行</th><th>物料</th><th>原行单价</th><th>当前单价</th><th>成本差额</th><th>来源 / 价格记录</th></tr></thead><tbody>${c.material_prices.map(v=>`<tr><td>${v.row_no+1}</td><td>${esc(p.rows[v.row_no]?.name||'')} · #${v.material_id}</td><td>${money(v.stored_price)}</td><td>${money(v.current_price)}</td><td>${money(v.delta)}</td><td>${v.source==='material_standard'?'已确认标准价 #'+v.price_version:'沿用BOM行价格'} ${v.material_id?`<button data-material="${v.material_id}">查看记录</button>`:''}</td></tr>`).join('')}</tbody></table>`);
    dialog.querySelectorAll('[data-material]').forEach(b=>b.onclick=()=>history(Number(b.dataset.material)));
  }
  return {history,used,costDetails,materialCell,close};
})();
