const fs=require('node:fs'),vm=require('node:vm'),assert=require('node:assert/strict');
const source=fs.readFileSync(__dirname+'/../quotation.php','utf8');
function extract(name){const start=source.indexOf('function '+name+'(');assert(start>=0,name);const next=source.slice(start+1).search(/\n(?:async )?function /);assert(next>=0,name+' end');return source.slice(start,start+1+next);}
const ctx={S:{},DB:{},clone:x=>JSON.parse(JSON.stringify(x)),cur:()=> 'RMB',rate:()=>6.7,quoteContextRate:()=>9,
 itemBaseCost:()=>3900,itemAutoPrice:()=>{throw Error('Unexpected repricing');},
 quoteItemBreakdown:()=>({final:999,multiplier:1,subtotal:3900}),quoteReviewImage:()=>'',isVirtualQuoteItem:x=>x.item_type==='virtual',
 esc:x=>String(x),reviewProductTitleOnly:()=> 'Synthetic item',buildSpec:()=>'',quoteDirectImageUrl:x=>x,
 quoteItemQtyForTotal:x=>Number(x.qty),$:()=>null,normalizeVirtualQuoteItemSign:x=>x};
vm.createContext(ctx);
for(const name of ['reviewSavedMultiplier','reviewRowMultiplier'])vm.runInContext(extract(name),ctx);
for(const name of ['quoteMoneyRound','quoteMoneyRow','quoteConvertMoney','quoteMoneyToRmb','normalizeQuoteItemCurrency','reviewItemRows','quoteDefaultAdjustment','quoteNormalizeAdjustment','quoteAdjustmentFromControls','quoteEffectiveAdjustment','quoteAdjustmentAmount','quoteTotalsForItems'])vm.runInContext(extract(name),ctx);
function displayPrice(item){return Number(ctx.reviewItemRows([item]).match(/class="review-price"[^>]*value="([^"]+)"/)[1]);}
for(const alias of ['unit_price','approved_price']){
 const old={qty:1,price:600,amount:600,currency:'USD',manual_price:true,product:{},[alias]:600};
 const current=ctx.normalizeQuoteItemCurrency(old,'RMB','USD');
 assert.equal(current.price,4020);assert.equal(current.amount,4020);assert.equal(current.unit_price,4020);assert.equal(current.approved_price,undefined);assert.equal(displayPrice(current),4020);
 assert.equal(displayPrice({...current,unit_price:600,approved_price:600}),4020);
}
for(const qty of [0,1,1.125])for(const price of [0,2.1234,4355]){
 const it={qty,price,currency:'RMB',manual_price:false,product:{}};
 assert.equal(ctx.normalizeQuoteItemCurrency(it,'RMB').price,price);assert.equal(displayPrice(it),price);
 assert.equal(ctx.quoteTotalsForItems([{...it,amount:99999}],false).amount,ctx.quoteMoneyRow(qty,price));
}
vm.runInContext(extract('quoteItemsForPreview'),ctx);
ctx.S.items=[{qty:3,price:0.335,currency:'RMB',manual_price:false,product:{}}];
assert.equal(ctx.quoteItemsForPreview()[0].price,0.335);
assert.equal(ctx.quoteItemsForPreview()[0].amount,1.01);
vm.runInContext(extract('quoteUnitMoney'),ctx);
assert.equal(ctx.quoteUnitMoney(2.1234),'2.1234');assert.equal(ctx.quoteUnitMoney(2.1),'2.10');
assert.equal(ctx.quoteMoneyRow(3,0.335),1.01);assert.equal(ctx.quoteMoneyRow(1,-0.005),-0.01);
ctx.S.quoteAdjustment={type:'discount_percent',value:12.5};assert.equal(ctx.quoteTotalsForItems([{qty:1,price:100}],false).amount,87.5);
// Syntax check all inline JS, replacing PHP-generated literal expressions only.
let scripts=0;for(const match of source.matchAll(/<script\b[^>]*>([\s\S]*?)<\/script>/gi)){let js=match[1].replace(/<\?(?:php|=)[\s\S]*?\?>/g,'null');if(js.trim()){new vm.Script(js);scripts++;}}
assert(source.includes('source.revision')&&source.includes('S.currentMoneyRevision=q.money_revision'));
assert(source.includes('确认后锁定本次明细、币种及汇率'));
console.log('Quote money runtime: aliases, exchange context, zero, saved price freeze, precision, totals, revision wiring; '+scripts+' inline scripts passed');
vm.runInContext(extract('collectReviewItems'),ctx);
for(const virtual of [false,true])for(const mult of [undefined,0,1.35]){
 const item={product:{id:'synthetic'},qty:1,price:80,currency:'RMB',...(virtual?{item_type:'virtual',virtual_type:'fuel'}:{}),...(mult===undefined?{}:{price_multiplier:mult})};
 const html=ctx.reviewItemRows([item]);
 assert.equal(html.includes('class="review-multiplier"'),!virtual);
 const tr={querySelector:sel=>({value:sel==='.review-qty'?'1':sel==='.review-price'?'80':sel==='.review-moq'?'':mult===undefined?'':String(mult)})};
 ctx.document={querySelectorAll:()=>[tr]};
 const out=ctx.collectReviewItems([item])[0];
 assert.equal(out.price_multiplier,mult,'absent/zero/explicit multiplier preserved');
 assert.equal(out.price,80);
}
assert.equal(ctx.reviewRowMultiplier({querySelector:()=>({value:'0'})},{price_multiplier:1.35}),0);
assert.throws(()=>ctx.reviewRowMultiplier({querySelector:()=>({value:'-1'})},{}));
assert.throws(()=>ctx.reviewRowMultiplier({querySelector:()=>({value:'Infinity'})},{}));
(async()=>{
 const base=[{qty:1,price:100,price_multiplier:1,moq:''}],source={id:7,revision:'original-opened-revision',amount:100,items:base,adjustment:{type:'none',value:0}};
 const modal={dataset:{quoteId:'7',currency:'RMB'},_moneySource:source},note={value:'',focus(){}};
 let open=true,calls=[],alerts=[],confirmations=[];
 Object.assign(ctx,{$:id=>id==='quoteReviewModal'?(open?modal:null):id==='quoteReviewNote'?note:null,
  hasPerm:()=>true,money:v=>Number(v).toFixed(2),alert:m=>alerts.push(m),confirm:m=>(confirmations.push(m),true),
  collectReviewItems:input=>{assert.equal(input,base);return [{...base[0],price:101}];},
  closeQuoteReview:()=>{open=false;},applyQuoteMutationResult:q=>q,updateQuoteApprovalStrip:()=>{},
  api:async(action,d)=>{calls.push(d);throw Error('stale revision');}});
 vm.runInContext('async '+extract('approveQuoteFromModal'),ctx);
 await ctx.approveQuoteFromModal();assert.equal(calls.length,0);assert(alerts.at(-1).includes('原因'));
 note.value='验收测试改价';await ctx.approveQuoteFromModal();assert.equal(open,true);assert.equal(modal._moneyBusy,false);
 assert.equal(calls[0].money_revision,'original-opened-revision');assert.equal(calls[0].amount,101);assert.equal(calls[0].confirm_money_change,true);
 assert(confirmations[0].includes('100.00 → 审核金额 RMB 101.00'));
 ctx.api=async(a,d)=>({quote:{id:7,amount:d.amount}});await ctx.approveQuoteFromModal();assert.equal(open,false);
 console.log('Actual approval handler: reason required, original revision, explicit delta, failed request preserves modal, success closes passed');
})().catch(e=>{console.error(e);process.exitCode=1;});
