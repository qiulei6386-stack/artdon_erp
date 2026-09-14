/* Adjustable shipment batches. No write is performed by opening or selecting. */
(function () {
  'use strict';
  const H=v=>String(v??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  const N=v=>Number(v)||0, Q=v=>String(Math.round(N(v)*10000)/10000);
  const requestId=()=>[...crypto.getRandomValues(new Uint8Array(24))].map(x=>x.toString(16).padStart(2,'0')).join('');
  let state=null, root=null, epoch=0;
  const fields={ship_date:'计划出货日期',consignee:'本批统一收货人 / 地址',shipping_mark:'唛头',ship_method:'运输方式',port_loading:'起运地',port_destination:'目的地',note:'批次备注'};
  function newState(orderId){return {baseId:Number(orderId),id:0,version:0,status:'planning',meta:{ship_date:typeof today==='function'?today():new Date().toISOString().slice(0,10)},selected:new Map(),cache:new Map(),cartons:[],candidates:[],plans:[],page:1,total:0,search:'',tab:'select',dirty:false,busy:false,pending:null,history:[],documents:[]};}
  async function api(action,data={}){
    const response=await fetch('quote_order_api.php?action=batch_'+action,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','X-CSRF-Token':document.querySelector('meta[name=shipment-csrf]')?.content||''},body:JSON.stringify(data)});
    const text=await response.text();let result;try{result=JSON.parse(text);}catch(e){throw new Error('服务器未返回有效结果，请稍后重试。当前输入仍保留。');}
    if(!response.ok||!result.ok)throw new Error(result.msg||'请求失败');return result.data;
  }
  function error(message){if(!root)return;const el=root.querySelector('[data-qb-error]');el.textContent=message||'';el.hidden=!message;}
  function touch(){state.dirty=true;state.pending=null;summary();}
  function selectedItems(){return [...state.selected.values()].filter(x=>N(x.selectedQty)>0);}
  function allocations(cartons){const out={};for(const carton of cartons)for(const item of carton.items||[])out[item.order_item_id]=(out[item.order_item_id]||0)+N(item.qty);return out;}
  function unpacked(){const allocated=allocations(state.cartons);return selectedItems().reduce((sum,x)=>sum+Math.max(0,N(x.selectedQty)-N(allocated[x.id])),0);}
  function removeBox(index,withdraw){
    const box=state.cartons[index];if(!box)return;
    if(withdraw)for(const part of box.items){const item=state.selected.get(Number(part.order_item_id));if(item){item.selectedQty=Math.max(0,N(item.selectedQty)-N(part.qty));if(item.selectedQty===0)state.selected.delete(Number(part.order_item_id));}}
    state.cartons.splice(index,1);touch();render();
  }
  function summary(){if(!root)return;const items=selectedItems(),orders=new Set(items.map(x=>x.order_id));root.querySelector('[data-qb-summary]').textContent=`${orders.size} 张订单 · ${Q(items.reduce((n,x)=>n+N(x.selectedQty),0))} 件 · ${Q(state.cartons.reduce((n,x)=>n+(N(x.carton_count)||1),0))} 箱 · ${Q(state.cartons.reduce((n,x)=>n+N(x.cbm),0))} m³ · 待装 ${Q(unpacked())} 件`;
    root.querySelector('[data-qb-status]').textContent=(state.id?'批次 #'+state.id+' · v'+state.version:'新建批次')+' · '+({planning:'待装计划',shipped:'已实际出货',cancelled:'已取消'}[state.status]||state.status)+(state.dirty?' · 未保存':'');
  }
  function mount(){
    root=document.createElement('div');root.className='qb-overlay';root.innerHTML=`<section class="qb-shell" role="dialog" aria-modal="true" aria-labelledby="qb-title"><header class="qb-head"><div><h2 id="qb-title">合并出货 · 装运工作台</h2><small data-qb-status></small></div><button class="qb-close" data-action="close">关闭</button></header><main class="qb-body"><div class="qb-note qb-error" data-qb-error role="alert" hidden></div><div data-qb-content></div></main><footer class="qb-foot"><strong data-qb-summary></strong><button data-action="preview">预览 PL / CI</button><button data-action="save" class="qb-primary">保存待装计划</button></footer></section>`;
    document.body.appendChild(root);root.addEventListener('click',onClick);root.addEventListener('change',onChange);root.addEventListener('input',onInput);
  }
  function render(){if(!root)return;
    const readonly=state.status!=='planning';
    const html=`<div class="qb-note">先选订单和产品，再填写本次数量。保存只占用可出量，确认实际出货后才计入已出。旧版批次按原记录保留，不推断是否实际发走。</div>
      <div class="qb-tools"><b>${H(state.base?.customer_name||'')}</b><span>${H(state.base?.currency||state.meta.currency||'')}</span><button data-action="new">新建下一批</button><select data-field="open-plan"><option value="">打开已保存批次</option>${state.plans.map(p=>`<option value="${p.id}">#${p.id} · ${H(p.state)} · v${p.version}</option>`).join('')}</select></div>
      <div class="qb-fields ${readonly?'qb-readonly':''}">${Object.entries(fields).map(([key,label])=>`<label>${label}<input data-meta="${key}" type="${key==='ship_date'?'date':'text'}" value="${H(state.meta[key]||'')}" ${readonly?'disabled':''}></label>`).join('')}</div>
      <div class="qb-tabs"><button data-tab="select" class="${state.tab==='select'?'qb-primary':''}">1 选单与数量</button><button data-tab="pack" class="${state.tab==='pack'?'qb-primary':''}">2 装箱与调整</button><button data-tab="review" class="${state.tab==='review'?'qb-primary':''}">3 核对与记录</button></div>
      <div class="qb-panel" ${state.tab==='select'?'':'hidden'}>${selectionHtml(readonly)}</div>
      <div class="qb-panel" ${state.tab==='pack'?'':'hidden'}>${packingHtml(readonly)}</div>
      <div class="qb-panel" ${state.tab==='review'?'':'hidden'}>${reviewHtml(readonly)}</div>`;
    root.querySelector('[data-qb-content]').innerHTML=html;root.querySelector('[data-action=save]').hidden=readonly;
    for(const order of state.candidates){const button=root.querySelector(`[data-load="${Number(order.id)}"]`);if(button&&order.delivery_address&&order.delivery_address!=='null'){const hint=document.createElement('p');hint.className='qb-caption';hint.textContent='订单收货地址：'+order.delivery_address+'。本批按上方统一收货信息执行，请核对。';button.closest('.qb-tools').after(hint);}}
    root.querySelectorAll('.qb-box-fields').forEach((box,index)=>{const label=document.createElement('label');label.textContent='本组箱数（正整数）';const input=document.createElement('input');input.type='number';input.min='1';input.step='1';input.dataset.box=String(index);input.dataset.boxField='carton_count';input.value=String(state.cartons[index].carton_count||1);input.disabled=readonly;label.append(input);box.append(label);const note=document.createElement('p');note.className='qb-caption';note.textContent='可填写箱号范围，例如 1–20。产品数量、净重、毛重、体积均填写本组总计；拆出其中几箱时先分成两组。';box.after(note);});
    root.querySelectorAll('.qb-head button,.qb-foot button').forEach(b=>{b.disabled=state.busy;});
    if(state.busy)root.querySelectorAll('button,input,select,textarea').forEach(b=>{b.disabled=true;});summary();
  }
  function productHtml(item,readonly){const selected=state.selected.get(Number(item.id));const qty=selected?selected.selectedQty:0;
    return `<div class="qb-product ${selected?'qb-selected':''}" data-item="${item.id}"><input aria-label="选择 ${H(item.product_code)}" data-choose="${item.id}" type="checkbox" ${selected?'checked':''} ${readonly||item.is_virtual||N(item.available_qty)<=0?'disabled':''}><div><b>${H(item.product_code||item.product_name)}</b><small>${H(item.order_no)} · ${H(item.product_name||'')}</small><small>${H(item.specification||'')} ${H(item.color||'')}</small></div><div class="qb-quantities qb-caption">订购 ${Q(item.ordered_qty??item.qty)} · 已出/旧版占用 ${Q(item.legacy_or_shipped_qty)}<br>其他计划占用 ${Q(item.reserved_qty)} · 本批最多 ${Q(item.available_qty)}</div><label>本次出货<input data-qty="${item.id}" type="number" min="0" step="0.0001" value="${Q(qty)}" ${readonly?'disabled':''}></label></div>`;
  }
  function selectionHtml(readonly){
    const selected=selectedItems();const selectedHTML=selected.length?`<section class="qb-card"><h3>已选产品（跨页保留）</h3>${selected.map(x=>productHtml(x,readonly)).join('')}</section>`:'';
    if(readonly)return selectedHTML;
    return `${selectedHTML}<h3>同客户未出完订单</h3><div class="qb-tools"><input data-search value="${H(state.search)}" placeholder="搜索订单号，不限制历史月份"><button data-action="search">查找</button><button data-action="prev" ${state.page===1?'disabled':''}>上一页</button><span>${state.page} / ${Math.max(1,Math.ceil(state.total/25))} 页 · ${state.total} 张</span><button data-action="next" ${state.page*25>=state.total?'disabled':''}>下一页</button></div>
      <p class="qb-caption">每页25张摘要。只在展开时读取该单产品；“全选”仅作用于明确指定的这一张订单。已被其他计划占用的产品不能重复安排。</p>
      ${state.candidates.map(order=>{const bundle=state.cache.get(Number(order.id));return `<section class="qb-card"><div class="qb-tools"><b>${H(order.order_no||order.quote_no)}</b><span>${H(order.currency)}</span>${order.incompatible?`<span class="qb-caption">${H(order.incompatible)}</span>`:`<button data-load="${order.id}">${bundle?'刷新余量':'展开产品'}</button>${bundle?`<button data-all="${order.id}">本单全部可出</button><button data-clear="${order.id}">本单不出</button>`:''}`}</div>${bundle?bundle.items.filter(x=>!x.is_virtual&&(N(x.available_qty)>0||state.selected.has(Number(x.id)))).map(x=>productHtml(x,false)).join('')||'<p class="qb-caption">没有可安排的产品，可能已全部被其他批次占用。</p>':''}</section>`;}).join('')||'<div class="qb-empty">没有匹配的未出完订单</div>'}`;
  }
  function packingHtml(readonly){const items=selectedItems();
    return `<div class="qb-note">每箱按订单产品分配；重量、体积只按箱汇总一次。撤下整箱＝本次不出；拆箱重装＝保留计划数量。体积仅供参考，不代表一定装得下。</div>${readonly?'':`<button data-action="add-box">新增箱</button>`}
      ${state.cartons.map((box,index)=>`<section class="qb-card"><div class="qb-box-fields">${[['carton_no','箱号'],['carton_size','箱规 cm'],['nw','净重 kg'],['gw','毛重 kg'],['cbm','体积 m³']].map(([field,label])=>`<label>${label}<input data-box="${index}" data-box-field="${field}" value="${H(box[field]??'')}" ${['nw','gw','cbm'].includes(field)?'type="number" min="0" step="0.0001"':''} ${readonly?'disabled':''}></label>`).join('')}</div>${box.items.map((part,j)=>`<div class="qb-allocation"><select data-box="${index}" data-part="${j}" data-part-field="order_item_id" ${readonly?'disabled':''}><option value="0">选择产品</option>${items.map(x=>`<option value="${x.id}" ${Number(part.order_item_id)===Number(x.id)?'selected':''}>${H(x.order_no)} / ${H(x.product_code)} · 计划${Q(x.selectedQty)}</option>`).join('')}</select><input aria-label="箱内数量" data-box="${index}" data-part="${j}" data-part-field="qty" type="number" min="0" step="0.0001" value="${Q(part.qty)}" ${readonly?'disabled':''}>${readonly?'':`<button data-remove-part="${index}:${j}">移除</button>`}</div>`).join('')}${readonly?'':`<div class="qb-tools"><button data-add-part="${index}">加入产品 / 混箱</button><button data-unpack="${index}">拆箱重装</button><button data-withdraw="${index}" class="qb-danger">撤下整箱，本次不出</button></div>`}</section>`).join('')||'<div class="qb-empty">先选择产品，再分配箱内数量。未装完也可以保存计划。</div>'}`;
  }
  function reviewHtml(readonly){return `<div class="qb-note">签发单证要求逐项装箱一致，但不等于发货。装前改单须写原因；旧签发版本保留并标记为已替代。已实际出货不能直接覆盖。</div>
    <h3>本批产品</h3>${selectedItems().map(x=>`<div class="qb-history">${H(x.order_no)} / ${H(x.product_code)} · ${Q(x.selectedQty)} × ${N(x.unit_price).toFixed(2)} = ${(N(x.selectedQty)*N(x.unit_price)).toFixed(2)} ${H(state.meta.currency||state.base?.currency||'')}</div>`).join('')}
    ${readonly?(state.status==='shipped'?'<div class="qb-note">仅确认操作错误、货物实际未发走时，可由管理员冲销并恢复待装。真实退货不能使用此功能。</div><button data-action="reverse" class="qb-danger">管理员更正：恢复待装</button>':''):`<div class="qb-tools"><button data-action="issue">签发本版 PL / CI</button><button data-action="dispatch" class="qb-primary">确认实际出货</button><button data-action="cancel" class="qb-danger">取消本批计划</button></div>`}
    <h3>单证修订版本</h3>${state.documents.map(d=>`<div class="qb-history">v${d.version} · ${H(d.actor)} · ${H(d.created_at)} · ${Number(d.version)===Number(state.version)?'当前签发版':'历史版 / 已替代'} <button data-doc="${d.version}">查看 PL / CI</button></div>`).join('')||'<p class="qb-caption">尚未签发；可以先预览草稿。</p>'}
    <h3>修改记录</h3>${state.history.map(e=>`<div class="qb-history">v${e.version} · ${H(e.actor)} · ${H(e.created_at)}<br>${H(e.action)}：${H(e.reason||'首次保存')}</div>`).join('')||'<p class="qb-caption">保存后记录操作人、时间和每次版本。</p>'}`;}
  async function open(orderId){if(state?.dirty&&!confirm('当前有未保存修改，确认离开？'))return;epoch++;root?.remove();state=newState(orderId);mount();render();await candidates();}
  async function candidates(){const token=epoch;error('');try{const result=await api('candidates',{order_id:state.baseId,page:state.page,search:state.search});if(token!==epoch)return;state.base=result.base;state.candidates=result.orders;state.plans=result.plans;state.total=result.total;if(!state.meta.consignee){let customer={};try{customer=JSON.parse(result.base.customer_json||'{}');}catch(e){}state.meta.consignee=[result.base.customer_name,customer.delivery_address||customer.address||''].filter(Boolean).join(' / ');}render();}catch(e){if(token===epoch)error(e.message);}}
  async function loadOrder(id){const token=epoch;error('');try{const bundle=await api('items',{order_id:id,plan_id:state.id});if(token!==epoch)return;if(Number(bundle.order.id)!==Number(id))throw new Error('返回订单不一致，已停止');state.cache.set(Number(id),bundle);for(const item of bundle.items){const chosen=state.selected.get(Number(item.id));if(chosen)state.selected.set(Number(item.id),{...item,selectedQty:chosen.selectedQty});}render();}catch(e){if(token===epoch)error(e.message);}}
  function findItem(id){if(state.selected.has(id))return state.selected.get(id);for(const bundle of state.cache.values()){const item=bundle.items.find(x=>Number(x.id)===id);if(item)return item;}return null;}
  function choose(id,qty){const item=findItem(id);if(!item)return;const allocated=N(allocations(state.cartons)[id]);if(qty<allocated){error('该产品已经装箱 '+Q(allocated)+' 件，请先调整或撤下对应箱。');render();return;}
    if(qty>0)state.selected.set(id,{...item,selectedQty:qty});else state.selected.delete(id);
    root.querySelectorAll(`[data-choose="${id}"]`).forEach(el=>{el.checked=qty>0;});
    root.querySelectorAll(`[data-qty="${id}"]`).forEach(el=>{if(el!==document.activeElement)el.value=Q(qty);});
    touch();error('');}
  async function loadPlan(id){if(state.dirty&&!confirm('有未保存修改，确认重新加载已保存版本？'))return;const token=++epoch;try{const plan=await api('get',{id});if(token!==epoch)return;applyPlan(plan);render();}catch(e){if(token===epoch)error(e.message);}}
  function applyPlan(plan){state.id=Number(plan.id);state.version=Number(plan.version);state.status=plan.state;state.meta=plan.data;state.baseId=Number(plan.base_order_id);state.cartons=structuredClone(plan.data.cartons||[]);state.selected=new Map((plan.data.items||[]).map(x=>[Number(x.order_item_id),{...x,id:Number(x.order_item_id),selectedQty:N(x.qty)}]));state.history=plan.history||[];state.documents=plan.documents||[];state.dirty=false;state.pending=null;state.cache.clear();if(!state.plans.some(p=>Number(p.id)===state.id))state.plans.unshift(plan);}
  function payloadData(){const data={base_order_id:state.baseId,items:selectedItems().map(x=>({order_id:Number(x.order_id),order_item_id:Number(x.id),qty:N(x.selectedQty),source_hash:x.source_hash})),cartons:state.cartons};for(const field of Object.keys(fields))data[field]=state.meta[field]||'';return data;}
  async function mutate(action){
    if(state.busy)return;if(action!=='save'&&(!state.id||state.dirty)){error('请先保存本次计划修改，再进行此操作。');return;}
    if(action==='dispatch'&&!confirm('确认货物已按本批次装箱核对并实际发走？此操作会计入已出货，不能普通编辑撤回。'))return;
    if(action==='reverse'&&!confirm('仅限“误点确认，货物实际尚未发走”。会归档原出货明细、恢复待装占用并留下原因；真实退货请勿操作。确认？'))return;
    if(action==='cancel'&&!confirm('取消本批计划并释放占用？原记录会保留。'))return;
    let body;if(state.pending?.action===action)body=state.pending.body;
    else{let reason=state.id?prompt('请填写本次'+({save:'修改',issue:'签发',dispatch:'出货确认',cancel:'取消',reverse:'误确认冲销'}[action])+'原因：'):'';if(state.id&&!reason)return;body={id:state.id,version:state.version,request_id:requestId(),reason:reason||'',...(action==='save'?{data:payloadData()}:{})};state.pending={action,body};}
    const token=epoch;state.busy=true;render();error('');try{const plan=await api(action,body);if(token!==epoch)return;applyPlan(plan);state.tab=action==='save'?'pack':'review';error('');}
    catch(e){if(token!==epoch)return;error(e.message+'\n输入已保留。未改动内容时再次点击会使用同一请求编号重试。');if(action==='dispatch'&&e.message.includes('尾款未收齐')){const button=document.createElement('button');button.dataset.action='dispatch-force';button.textContent='管理员确认欠款放行';root.querySelector('[data-qb-error]').append(button);}}
    finally{if(token===epoch){state.busy=false;render();}}
  }
  async function preview(version=0){if(!state.id||state.dirty){error('请先保存计划后预览，确保看到服务器保存的同一份内容。');return;}const win=window.open('','_blank');if(!win){error('请允许弹出单证预览窗口');return;}win.document.title='读取单证…';try{const d=await api('document',{id:state.id,version});win.document.open();win.document.write(documentHtml(d));win.document.close();}catch(e){win.close();error(e.message);}}
  function documentHtml(doc){const d=doc.data,watermark=doc.state==='cancelled'?'已取消':!doc.issued?'草稿预览 · 未签发':Number(doc.version)!==Number(doc.current_version)?'历史版本 · 已替代':'已签发';
    const amounts=d.items.reduce((s,x)=>s+N(x.amount),0);const products=new Map(d.items.map(x=>[Number(x.order_item_id),x]));
    return `<!doctype html><html lang="zh"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>出货批次 ${doc.id} v${doc.version}</title><style>body{font:14px/1.5 Arial,"Microsoft YaHei",sans-serif;color:#172338;margin:24px}h1{font-size:22px}small{color:#667085}table{width:100%;border-collapse:collapse;margin:16px 0}th,td{border:1px solid #bfcada;padding:7px;text-align:left;overflow-wrap:anywhere}th{background:#f3f6fa}.note{white-space:pre-wrap}.status{padding:10px;border:1px solid #cfdaea;background:#f3f6fa}.page{max-width:1050px;margin:0 auto 40px}button{padding:10px 16px}@media print{button{display:none}.page+.page{break-before:page}body{margin:12mm}thead{display:table-header-group}tr{break-inside:avoid}}</style><button onclick="window.print()">打印 / 保存 PDF（含 PL 和 CI）</button><section class="page"><h1>${H(d.seller_name||'')} · COMMERCIAL INVOICE</h1><div class="status">${H(watermark)} · QB-${doc.id}-CI-R${doc.version} · ${H(d.ship_date)}</div><p class="note">${H(d.seller_text||'')}</p><p class="note"><b>Buyer / Consignee:</b> ${H(d.consignee)}</p><p>Shipping: ${H(d.ship_method)} / ${H(d.port_loading)} → ${H(d.port_destination)}</p><table><thead><tr><th>Order</th><th>Model / Specification</th><th>Qty</th><th>Unit Price ${H(d.currency)}</th><th>Amount</th></tr></thead><tbody>${d.items.map(x=>`<tr><td>${H(x.order_no)}</td><td>${H(x.product_code)}<br>${H(x.product_name)}<br>${H(x.specification)} ${H(x.color||'')}</td><td>${Q(x.qty)}</td><td>${N(x.unit_price).toFixed(2)}</td><td>${N(x.amount).toFixed(2)}</td></tr>`).join('')}</tbody></table><b>Total: ${Q(d.totals.qty)} PCS · ${H(d.currency)} ${amounts.toFixed(2)}</b><p class="note">${H(d.bank_text||'')}</p></section><section class="page"><h1>PACKING LIST</h1><div class="status">${H(watermark)} · QB-${doc.id}-PL-R${doc.version} · ${H(d.ship_date)}</div><p class="note">${H(d.consignee)}<br>Shipping mark: ${H(d.shipping_mark)}</p><table><thead><tr><th>Carton</th><th>Order / Product / Quantity</th><th>Size cm</th><th>N.W. kg</th><th>G.W. kg</th><th>CBM</th></tr></thead><tbody>${d.cartons.map(box=>`<tr><td>${H(box.carton_no)}<br>${Q(box.carton_count||1)} CTNS</td><td>${box.items.map(part=>{const x=products.get(Number(part.order_item_id))||{};return H(x.order_no)+' / '+H(x.product_code)+' · '+Q(part.qty)+' PCS';}).join('<br>')}</td><td>${H(box.carton_size)}</td><td>${Q(box.nw)}</td><td>${Q(box.gw)}</td><td>${Q(box.cbm)}</td></tr>`).join('')}</tbody></table><b>${Q(d.totals.cartons??d.cartons.reduce((n,x)=>n+(N(x.carton_count)||1),0))} CTNS · ${Q(d.totals.qty)} PCS · ${Q(d.totals.gw)} KG · ${Q(d.totals.cbm)} CBM</b><p class="note">${H(d.note)}</p><small>Version ${doc.version} · ${H(doc.actor)} · ${H(doc.created_at)}</small></section></html>`;
  }
  async function onClick(event){const b=event.target.closest('button');if(!b||state.busy)return;
    if(b.dataset.tab){state.tab=b.dataset.tab;render();return;}
    if(b.dataset.load){await loadOrder(Number(b.dataset.load));return;}
    if(b.dataset.all){const bundle=state.cache.get(Number(b.dataset.all));for(const item of bundle?.items||[])if(!item.is_virtual&&N(item.available_qty)>0)choose(Number(item.id),N(item.available_qty));render();return;}
    if(b.dataset.clear){for(const item of [...state.selected.values()])if(Number(item.order_id)===Number(b.dataset.clear))choose(Number(item.id),0);render();return;}
    if(b.dataset.addPart!==undefined){state.cartons[N(b.dataset.addPart)].items.push({order_item_id:0,qty:0});touch();render();return;}
    if(b.dataset.removePart){const [i,j]=b.dataset.removePart.split(':').map(Number);state.cartons[i].items.splice(j,1);touch();render();return;}
    if(b.dataset.unpack!==undefined){removeBox(N(b.dataset.unpack),false);return;}
    if(b.dataset.withdraw!==undefined){if(confirm('确认撤下此整箱，本次不出箱内这些数量？'))removeBox(N(b.dataset.withdraw),true);return;}
    if(b.dataset.doc){await preview(Number(b.dataset.doc));return;}
    switch(b.dataset.action){case 'close':if(!state.dirty||confirm('有未保存修改，确认关闭？')){epoch++;root.remove();root=null;state=null;}break;
      case 'dispatch-force':if(state.pending?.action==='dispatch'&&confirm('确认在尾款未收齐的情况下实际出货？仅管理员可放行，原因会保留。')){state.pending.body={...state.pending.body,force_shipment:1,request_id:requestId()};await mutate('dispatch');}break;
      case 'new':await open(state.baseId);break;case 'search':state.page=1;await candidates();break;
      case 'prev':if(state.page>1){state.page--;await candidates();}break;case 'next':if(state.page*25<state.total){state.page++;await candidates();}break;
      case 'add-box':{let n=state.cartons.length+1;while(state.cartons.some(c=>c.carton_no===String(n)))n++;state.cartons.push({carton_no:String(n),carton_size:'',nw:0,gw:0,cbm:0,items:[{order_item_id:0,qty:0}]});touch();render();break;}
      case 'preview':await preview();break;case 'save':case 'issue':case 'dispatch':case 'cancel':case 'reverse':await mutate(b.dataset.action);break;}
  }
  function onInput(event){const el=event.target;if(state.busy)return;if(el.hasAttribute('data-search')){state.search=el.value;return;}if(el.dataset.meta){state.meta[el.dataset.meta]=el.value;touch();return;}if(el.dataset.qty){const qty=Number(el.value);if(!Number.isFinite(qty)||qty<0){error('出货数量须为非负数字');return;}choose(Number(el.dataset.qty),qty);return;}if(el.dataset.boxField){state.cartons[N(el.dataset.box)][el.dataset.boxField]=el.value;touch();}if(el.dataset.partField==='qty'){state.cartons[N(el.dataset.box)].items[N(el.dataset.part)].qty=el.value;touch();}}
  function onChange(event){const el=event.target;if(el.dataset.choose){const item=findItem(Number(el.dataset.choose));choose(Number(el.dataset.choose),el.checked?N(item?.available_qty):0);render();}if(el.dataset.partField==='order_item_id'){state.cartons[N(el.dataset.box)].items[N(el.dataset.part)].order_item_id=N(el.value);touch();}if(el.dataset.field==='open-plan'&&el.value)loadPlan(N(el.value));}
  window.addEventListener('beforeunload',event=>{if(state?.dirty){event.preventDefault();event.returnValue='';}});
  openCombinedShipmentModal=open;
  openShipmentModal=open;
  window.quickGenerateOrderDoc=async function(orderId){await open(orderId);};
  window.QuoteShipmentBatch={open,allocations,documentHtml,newState};
}());
