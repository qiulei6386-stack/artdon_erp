const fs=require('node:fs'),vm=require('node:vm'),assert=require('node:assert/strict');
const code=fs.readFileSync(__dirname+'/../assets/quote-order-page.js','utf8');
const source=fs.readFileSync(__dirname+'/../quotation.php','utf8');
function deferred(){let resolve,reject;let promise=new Promise((a,b)=>{resolve=a;reject=b;});return {resolve,reject,promise};}
(async()=>{
 const ids=['orderSearch','orderCustomer','orderOwner','orderCurrency','orderDateFrom','orderDateTo','orderStatus','orderSort','orderList','orderFinance','orderCount','page-orders'];
 const els=Object.fromEntries(ids.map(id=>[id,{value:'',innerHTML:'',textContent:'',setAttribute(){},replaceChildren(){},add(){},classList:{contains:()=>true}}]));
 const requests=[],handlers={},ctx={console,setTimeout,clearTimeout,Date,Option:function(t,v){this.value=v;},ORDER_OVERVIEW:null,DASH_ORDERS_LOADED:false,DB:{orders:[]},$:(id)=>els[id],hasPerm:()=>true,renderDash(){},orderFinanceStrip:a=>JSON.stringify(a),esc:String,money:n=>Number(n).toFixed(2),quoteOrderNoAtV68522:x=>x,orderPaymentStatusText:o=>o.payment_status,orderPaymentBadgeClass:()=>'',viewOrder(){},document:{addEventListener:(t,f)=>handlers[t]=f},orderApi:(a,d)=>{let wait=deferred();requests.push({a,d,wait});return wait.promise;}};
 ctx.window=ctx;vm.createContext(ctx);vm.runInContext(code,ctx);
 const result=(n)=>({page:1,size:20,pages:6,total:107,orders:[{id:n,order_no:'AT-'+n,status:'已确认'}],finance:[{amount:10700}],overview:{count:107,customers:['A'],owners:['Amy'],finance:[]}});
 const one=ctx.OrderCenter.load(),two=ctx.OrderCenter.load();assert.equal(requests.length,1,'Duplicate clicks share one request');requests[0].wait.resolve(result(1));await Promise.all([one,two]);assert(els.orderFinance.innerHTML.includes('10700'),'Whole-filter finance not page amount');
 const slow=ctx.OrderCenter.load();els.orderSearch.value='older-order';ctx.OrderCenter.render();const fast=ctx.OrderCenter.load();assert.equal(requests.length,3);requests[2].wait.resolve(result(3));await fast;requests[1].wait.resolve(result(2));await slow;assert.equal(ctx.DB.orders[0].id,3,'Stale response cannot replace newer filters');
 const failure=ctx.OrderCenter.load();requests[3].wait.reject(Error('network timeout'));await failure;assert(els.orderList.innerHTML.includes('重试'));const retry=ctx.OrderCenter.load();requests[4].wait.resolve(result(4));await retry;assert.equal(ctx.DB.orders[0].id,4);
 assert(!source.includes("orderApi('list')"),'Page and dashboard do not fetch legacy unpaginated list');
 assert(source.includes('ORDER_OVERVIEW?.quote_nos'));assert(source.includes('if(no)await loadOrders();'));
 console.log('Order page: coalescing, stale response isolation, all-filter totals, retry and complete deep-link lookup passed.');
})().catch(e=>{console.error(e);process.exitCode=1;});
