'use strict';
const fs=require('node:fs'),vm=require('node:vm'),assert=require('node:assert/strict');
const src=fs.readFileSync(require('node:path').join(__dirname,'../assets/crm/crm.js'),'utf8');
const start=src.indexOf("      document.querySelectorAll('[data-customer-filter]').forEach");
const end=src.indexOf("      document.querySelector('[data-page-prev]')",start);
const handlers={};
const buttons=['all','has_wechat_personal','has_whatsapp_personal','has_wechat_group','has_whatsapp_group'].map(key=>({getAttribute:()=>key,addEventListener:(_,fn)=>{handlers[key]=fn;}}));
const m={filterState:{quick:'all',keyword:'keep',advanced:{country:'China'}},ensureFilterState(){},updateFilterControls(){},loadList(){this.loaded=(this.loaded||0)+1;},clearAdvancedControls(){this.cleared=true;},page:5};
vm.runInNewContext(src.slice(start,end),{document:{querySelectorAll:()=>buttons},self:m});
for(const key of Object.keys(handlers).filter(k=>k!=='all')){
 handlers[key]();assert.equal(m.filterState.quick,key);assert.equal(m.page,1);assert.equal(m.filterState.keyword,'keep');assert.equal(m.filterState.advanced.country,'China');
 handlers[key]();assert.equal(m.filterState.quick,'all');assert.equal(m.filterState.keyword,'keep');assert.equal(m.filterState.advanced.country,'China');
}
handlers.all();assert.equal(m.filterState.keyword,'');assert.deepEqual(Object.keys(m.filterState.advanced),[]);
function method(name){const a=src.indexOf('    '+name+': function'),b=src.indexOf('\n    },',a);return src.slice(a,b+7);}
const body={innerHTML:'',querySelectorAll:()=>[]};
const esc=v=>String(v??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const render=vm.runInNewContext('({'+method('renderRows')+'})',{document:{querySelector:s=>s==='[data-customer-rows]'?body:null},esc}).renderRows;
const host={visibleColumns:()=>[{key:'customer_name'}],syncPageSelectionState(){},total:1};
render.call(host,[{id:1,customer_name:'客户',channel_match:{label:'微信个人',sources:['联系人：<img src=x onerror=alert(1)>'],notes:['缺个人微信号']}}]);
assert(body.innerHTML.includes('缺个人微信号'));assert(!body.innerHTML.includes('<img'));assert(body.innerHTML.includes('customer-channel-match'));
render.call(host,[{id:2,customer_name:'普通客户'}]);assert(!body.innerHTML.includes('customer-channel-match'));
console.log('Channel UI: selection, same-filter toggle, other conditions, paging reset, explanation and escaping passed');
