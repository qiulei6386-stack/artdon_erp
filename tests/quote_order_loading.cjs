const fs=require('node:fs'),vm=require('node:vm'),assert=require('node:assert/strict');
const source=fs.readFileSync(__dirname+'/../quotation.php','utf8');
const helper=source.slice(source.indexOf('let ORDER_VIEW_REQUEST=0;'),source.indexOf('function orderItems('));
const view=source.split('\n').filter(l=>l.startsWith('async function viewOrder(')).at(-1);
async function run(){
 const button={textContent:'合并出货',disabled:false},calls=[],rendered=[];
 let active=0,max=0,fail=false,wrong=false;
 const ctx={console,document:{querySelectorAll:()=>[button]},orderApi:async(action,d)=>{
   calls.push([action,d]);if(action==='prepare_combined_shipment')return {order:{id:1},orders:[{id:1},{id:2}],items:[]};
   active++;max=Math.max(max,active);await Promise.resolve();active--;
   if(fail&&d.order_id===2)throw Error('读取失败');
   return {order:{id:wrong?99:d.order_id},items:[{order_id:d.order_id,qty:1}]};
 },rememberOpenedContext(){},renderOrderCommission(){},renderOrderDetail(o){rendered.push(o.id);},alert(){},CURRENT_ORDER:null};
 vm.createContext(ctx);vm.runInContext(helper+'\n'+view,ctx);
 let result=await vm.runInContext('loadCombinedShipmentOrders(1)',ctx);
 assert.equal(max,1);assert.equal(result.items.length,2);assert.equal(calls[0][1].summary_only,true);
 assert.deepEqual(calls.map(c=>c[0]),['prepare_combined_shipment','prepare_shipment','prepare_shipment']);
 assert.equal(button.disabled,false);assert.equal(button.textContent,'合并出货');
 fail=true;await assert.rejects(vm.runInContext('loadCombinedShipmentOrders(1)',ctx),/未提交出货/);assert.equal(button.disabled,false);
 fail=false;wrong=true;await assert.rejects(vm.runInContext('loadCombinedShipmentOrders(1)',ctx),/不一致/);
 wrong=false;await vm.runInContext('loadCombinedShipmentOrders(1)',ctx);
 const pending=[];ctx.orderApi=(action,d)=>{assert.equal(action,'detail');assert.equal(d.light,true);return new Promise(resolve=>pending.push({resolve,id:d.id}));};
 ctx.document.querySelectorAll=()=>[];
 const a=vm.runInContext('viewOrder(1)',ctx),b=vm.runInContext('viewOrder(2)',ctx);
 pending[1].resolve({order:{id:2}});await b;pending[0].resolve({order:{id:1}});await a;
 assert.deepEqual(rendered,[2]);assert.equal(ctx.CURRENT_ORDER.id,2);
 assert(source.includes('loading="lazy" decoding="async"'));
 assert(!source.match(/editShipment=async function[^\n]*orderApi\('shipment_edit_data'/));
 console.log('Order loading: serial requests, progress restoration, partial failure, retry, identity, lazy images, latest detail wins and no duplicate edit fetch passed');
}
run().catch(e=>{console.error(e);process.exit(1);});
