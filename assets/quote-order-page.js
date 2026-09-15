/* Order rows are a page, never the source of global accounting totals. */
(()=>{
  'use strict';
  const fields={search:'orderSearch',customer:'orderCustomer',owner:'orderOwner',currency:'orderCurrency',from:'orderDateFrom',to:'orderDateTo',status:'orderStatus',sort:'orderSort'};
  const state={page:1,size:20,data:null,key:'',revision:0,pending:null,timer:null,overviewPending:null};
  function filters(){return Object.fromEntries(Object.entries(fields).map(([key,id])=>[key,$(id)?.value||'']));}
  function key(){return JSON.stringify(filters());}
  function installOverview(data){if(!data)return;ORDER_OVERVIEW=data;DASH_ORDERS_LOADED=true;
    for(const [id,values,label] of [['orderCustomer',data.customers,'全部客户'],['orderOwner',data.owners,'全部负责人']]){
      const el=$(id);if(!el)continue;const old=el.value;el.replaceChildren(new Option(label,''));
      [...new Set([...(values||[]),...(old?[old]:[])])].sort((a,b)=>a.localeCompare(b,'zh')).forEach(v=>el.add(new Option(v,v)));el.value=old;
    }
    renderDash();
  }
  async function overview(force=false){
    if(!hasPerm('order_convert'))return;
    if(state.pending)return state.pending.promise;
    if(state.overviewPending)return state.overviewPending;
    if(ORDER_OVERVIEW&&!force)return ORDER_OVERVIEW;
    state.overviewPending=orderApi('order_overview').then(d=>{installOverview(d.overview);return d;}).finally(()=>{state.overviewPending=null;});
    return state.overviewPending;
  }
  function pager(){const d=state.data;if(!d)return '';return `<div class="order-page-controls" aria-label="订单分页">
    <button type="button" data-order-page="${d.page-1}" ${d.page<=1?'disabled':''}>上一页</button>
    <span>第 ${d.page} / ${d.pages} 页 · 共 ${d.total} 单</span>
    <button type="button" data-order-page="${d.page+1}" ${d.page>=d.pages?'disabled':''}>下一页</button>
    <label>每页 <select data-order-size aria-label="每页订单数"><option value="20" ${d.size===20?'selected':''}>20 单</option><option value="50" ${d.size===50?'selected':''}>50 单</option></select></label></div>`;}
  function paint(){
    const el=$('orderList'),d=state.data;if(!el||!d)return;
    const rows=DB.orders||[];
    if($('orderFinance'))$('orderFinance').innerHTML='<div class="hint">全部筛选结果合计（不限当前页）</div>'+orderFinanceStrip(d.finance||[]);
    el.innerHTML=pager()+
      (rows.length?rows.map(o=>{
        const pst=orderPaymentStatusText(o),cls=/已作废|取消/.test(o.status||'')?'voided':(/已完成|已完结|已出货/.test(o.status||'')?'done':'');
        return `<div class="order-card"><div><b>${esc(quoteOrderNoAtV68522(o.order_no,o.quote_no))}</b> <span class="order-status ${cls}">${esc(o.status)}</span><span class="order-status pay ${orderPaymentBadgeClass(pst)}">${esc(pst)}</span>
        <small>客户：${esc(o.customer_name||'未选客户')} ｜ 负责人：${esc(o.owner_name||'-')}</small>
        <small>来源报价：${esc(o.quote_no)} ｜ ${esc(o.order_date||'')} ｜ ${esc(o.currency||'USD')} ${money(o.amount)}</small>
        <small>数量 ${Number(o.qty||0)} PCS ｜ ${esc(o.shipment_status)} ｜ 未收 ${esc(o.currency||'USD')} ${money(o.balance_amount)}</small></div>
        <div class="order-list-actions"><button type="button" class="gray" data-order-detail="${Number(o.id)}">查看详情</button></div></div>`;
      }).join(''):'<div class="order-detail-empty">没有符合筛选条件的订单，可清空搜索或调整日期。</div>')+pager();
    el.setAttribute('aria-busy','false');
    if($('orderCount'))$('orderCount').textContent=`共 ${d.total} / ${ORDER_OVERVIEW?.count??d.total} 个订单 ｜ 本页 ${rows.length} 单 ｜ ${d.elapsed}ms`;
  }
  async function load(){
    if(!hasPerm('order_convert'))return;
    clearTimeout(state.timer);
    const nextKey=key();if(state.key!==nextKey){state.key=nextKey;state.page=1;state.revision++;}
    const requestKey=nextKey+':'+state.page+':'+state.size;
    if(state.pending?.key===requestKey&&state.pending.revision===state.revision)return state.pending.promise;
    const revision=++state.revision,start=Date.now();
    if($('orderList')){$('orderList').setAttribute('aria-busy','true');$('orderList').innerHTML='<div class="order-detail-empty">正在读取本页订单…</div>';}
    if($('orderFinance'))$('orderFinance').textContent='正在计算筛选结果合计…';
    if($('orderCount'))$('orderCount').textContent='正在读取本页…';
    const promise=(async()=>{try{
      // Share an already-running overview request; do not issue the same aggregates twice.
      const ongoing=state.overviewPending;
      const d=await orderApi('list_page',{...filters(),page:state.page,size:state.size,overview:!ongoing});
      if(ongoing)await ongoing.catch(()=>{});
      if(revision!==state.revision)return;
      installOverview(d.overview);state.page=d.page;state.size=d.size;state.data={...d,elapsed:Date.now()-start};DB.orders=d.orders||[];paint();return d;
    }catch(e){if(revision===state.revision&&$('orderList')){state.data=null;$('orderList').setAttribute('aria-busy','false');$('orderList').innerHTML='<div class="order-detail-empty">读取失败：'+esc(e.message)+'<br><button type="button" data-order-retry>重试</button></div>';if($('orderCount'))$('orderCount').textContent='读取失败，请重试';if($('orderFinance'))$('orderFinance').textContent='本次合计未读取';}return null;
    }finally{if(state.pending?.revision===revision)state.pending=null;}})();
    state.pending={key:requestKey,promise,revision};return promise;
  }
  function render(){
    const next=key();if(next!==state.key){state.key=next;state.page=1;state.revision++;clearTimeout(state.timer);state.timer=setTimeout(load,250);return;}
    if(state.data&&!state.pending)paint();
  }
  document.addEventListener('click',async e=>{const b=e.target.closest('[data-order-page],[data-order-detail],[data-order-retry]');if(!b)return;
    if(b.hasAttribute('data-order-detail')){await viewOrder(Number(b.dataset.orderDetail));if(window.innerWidth<=760)$('orderDetail')?.scrollIntoView({block:'start'});return;}
    if(b.hasAttribute('data-order-page'))state.page=Number(b.dataset.orderPage);load();
  });
  document.addEventListener('change',e=>{if(e.target.matches('[data-order-size]')){state.size=Number(e.target.value);state.page=1;load();}});
  window.OrderCenter={load,render,overview,refresh:async()=>{
    if($('page-orders')?.classList.contains('active'))return load();
    return overview(true);
  }};
  // The existing async page restore may finish before this deferred asset arrives.
  (window.__orderCenterWaiters||[]).splice(0).forEach(ready=>ready());
  if($('page-orders')?.classList.contains('active'))load();
})();
