const fs=require('node:fs'),assert=require('node:assert/strict'),vm=require('node:vm');
const js=fs.readFileSync('assets/quote-bom-versions.js','utf8'),quote=fs.readFileSync('quotation.php','utf8');
new vm.Script(js);
for(const mark of ['expected_publication','S.product===c.product','S.currentQuoteId===c.quoteId','S.editingIndex===c.index','locked()','c.product._bomBlocked=false','bom_quote_versions','returnFocus?.focus'])assert(js.includes(mark),mark);
assert(quote.includes('const selection=S.product,quoteId=S.currentQuoteId'));
assert(quote.includes('if(p.bom_version)return false;'));
assert(quote.includes('if(S.product?.bom_version)return fresh;'));
console.log('Quote BOM version JS integration, stale selection guards, frozen references and zero-cost contracts passed');
const ctx={S:{product:{bom_version:{snapshot_id:1}},items:[{cost_price_rmb:99}],editingIndex:0},isVirtualQuoteItem:()=>false,isMaterialSaleItem:()=>false,productPrice:()=>0,partsPrice:()=>0,cur:()=> 'RMB',quoteMoneyFromRmb:x=>x,quoteConvertMoney:x=>x,itemProductPrice:()=>0,itemPartsPrice:()=>0};vm.createContext(ctx);
for(const name of ['currentEditorBaseCost','itemBaseCost']){const start=quote.indexOf('function '+name+'('),end=quote.slice(start+1).search(/\n(?:async )?function /);vm.runInContext(quote.slice(start,start+1+end),ctx);}
assert.equal(ctx.currentEditorBaseCost(),0,'new zero snapshot must not revive old cost');
assert.equal(ctx.itemBaseCost({product:{bom_version:{snapshot_id:1}},cost_price_rmb:99}),0,'persisted zero snapshot remains zero');
