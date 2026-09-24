/* Document actions are read-only and distinct from creating a shipment. */
window.QuoteShipmentLinks=(()=>{
  const H=v=>String(v??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  let request=0,dialog=null;
  async function read(id){const r=await orderApi('batch_order_batches',{order_id:Number(id)});return r.batches||[];}
  function status(b){return b.kind==='plan'?({planning:Number(b.issued_version)===Number(b.version)?'已签发 · 待装未出':'待装计划',shipped:'已实际出货',cancelled:'已取消'}[b.state]||b.state):'历史批次 · '+b.state;}
  function url(b,type,format){return b.kind==='plan'?'quote_batch_document.php?id='+Number(b.id)+'&version='+Number(b.issued_version||0)+'&type='+type+'&format='+format:'quote_order_doc.php?shipment_id='+Number(b.id)+'&type='+type+'&format='+format+'&read_only=1';}
  function card(b,type=''){
    const types=type?[type]:['pl','ci'];return `<article class="qsl-card"><b>${H(b.label)}</b><span>${H(status(b))} · ${H(b.ship_date||'')} · 本单 ${Number(b.order_qty||0)} 件</span>${b.kind==='plan'&&Number(b.issued_version)>0&&Number(b.issued_version)!==Number(b.version)?'<p>当前计划已修改，下方下载为上次签发版本；请核对后重新签发。</p>':''}<div class="qsl-actions">${types.map(t=>b[t+'_status']==='deleted'?`<span>${t.toUpperCase()} 已删除</span>`:`<a target="_blank" rel="noopener" href="${url(b,t,'html')}">${t.toUpperCase()} 预览</a><a target="_blank" rel="noopener" href="${url(b,t,'pdf')}">${t.toUpperCase()} 下载 PDF</a><a target="_blank" rel="noopener" href="${url(b,t,'xls')}">${t.toUpperCase()} 下载 Excel</a>`).join('')}${b.kind==='plan'?`<button data-qsl-plan="${Number(b.id)}" data-qsl-base="${Number(b.base_order_id)}">打开批次</button>`:''}</div>${!type&&b.kind==='legacy'?`<div class="qsl-actions"><button onclick="viewShipmentDetail(${Number(b.id)})">查看批次</button><button onclick="openOrderDocManage('pl',${Number(b.id)})">管理 PL</button><button onclick="openOrderDocManage('ci',${Number(b.id)})">管理 CI</button>${b.state==='草稿'?`<button onclick="editShipment(${Number(b.id)})">修改批次</button><button onclick="deleteShipment(${Number(b.id)})">删除草稿批次</button>`:''}</div><div id="shipmentDetail${Number(b.id)}"></div>`:''}</article>`;
  }
  function bind(root){root.addEventListener('click',async e=>{const b=e.target.closest('[data-qsl-plan]');if(!b)return;dialog?.remove();dialog=null;await QuoteShipmentBatch.open(Number(b.dataset.qslBase),Number(b.dataset.qslPlan));});}
  async function open(id,type='pl'){
    const serial=++request;dialog?.remove();dialog=document.createElement('div');dialog.className='qsl-mask';dialog.innerHTML=`<section role="dialog" aria-modal="true" aria-label="出货单证"><header><b>${type==='ci'?'CI 商业发票':'PL 装箱单'} · 选择出货批次</b><button data-qsl-close>关闭</button></header><p>下载不改变出货状态。待装未签发单证会标明草稿。</p><main>正在读取批次…</main></section>`;document.body.append(dialog);const own=dialog;own.querySelector('[data-qsl-close]').onclick=()=>{request++;own.remove();if(dialog===own)dialog=null;};bind(own);
    try{const rows=await read(id);if(serial!==request||dialog!==own)return;own.querySelector('main').innerHTML=rows.length?rows.map(b=>card(b,type)).join(''):'尚无已保存的待装计划或历史出货批次。请先保存装运计划，不能仅勾选订单就生成出货单证。';}catch(e){if(dialog===own)own.querySelector('main').textContent='读取失败：'+e.message+'。请关闭后重试。';}
  }
  function attach(id){const box=document.getElementById('orderDetail');if(!box)return;const title=[...box.querySelectorAll('.order-subtitle')].find(x=>x.textContent.includes('Shipments'));if(!title)return;
    const slot=document.createElement('div');slot.className='qsl-list';slot.textContent='正在读取待装与历史出货批次…';title.nextElementSibling?.replaceWith(slot);bind(slot);
    read(id).then(rows=>{if(!slot.isConnected)return;slot.innerHTML=rows.length?rows.map(b=>card(b)).join(''):'暂无已保存的待装计划或出货批次。';}).catch(e=>{if(slot.isConnected)slot.textContent='批次读取失败：'+e.message;});
  }
  const original=renderOrderDetail;renderOrderDetail=function(...args){original(...args);if(args[0]?.id)attach(args[0].id);};
  window.quickGenerateOrderDoc=(id,type)=>open(id,type==='ci'?'ci':'pl');return {open,card,url,status};
})();
