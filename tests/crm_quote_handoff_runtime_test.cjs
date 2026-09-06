'use strict';
// Run the original standard-quote bootstrap/handoff block against fake controls.
const assert=require('node:assert/strict'),fs=require('node:fs'),vm=require('node:vm'),path=require('node:path');
const source=fs.readFileSync(path.join(__dirname,'../commercial_center_v1/assets/js/quote_center.js'),'utf8');
const start=source.indexOf('    const incomingCrmContext ='),end=source.indexOf("    field('customer_id')?.addEventListener",start);
assert(start>0&&end>start);
const original=source.slice(start,end).replace('    request(null,','    return request(null,');
async function run({quoteId=0,existing=false,search='?crm_customer_id=71&crm_opportunity_id=8',failure=false}={}) {
 const fields={customer_id:{value:'',options:[],append(option){this.options.push(option);}},country:{value:''},project_ref:{value:''}},controls=[{disabled:false},{disabled:true}],calls=[],messages=[];
 const ctx={quoteId,csrf:'',bootstrap:null,URLSearchParams,location:{search},editor:{querySelectorAll(){return controls;}},tbody:{},field:n=>fields[n],setField(n,v){fields[n].value=v;},message:(text,error)=>messages.push({text,error}),renderBootstrap(){},renderQuote(){ctx.quoteId=22;},recalculate(){},$$(){return [];},document:{createElement(){return {dataset:{}};}},request(){return Promise.resolve({csrf:'fixture',data:{},quote:existing?{id:22}:null});},fetch(url,options){calls.push({url,options,locked:controls[0].disabled});return Promise.resolve({json:async()=>failure?{success:false,message:'denied'}:{success:true,data:{customer:{id:71,customer_name:'Fixture',country:'Fixture country'},project_ref:'CRM #8',message:'补充产品并保存后生成报价'}}});}};
 await vm.runInNewContext('(async function(){'+original+'})()',ctx);return {fields,controls,calls,messages};
}
(async()=>{
 let h=await run();assert.equal(h.calls.length,1);assert(h.calls[0].locked);assert.equal(h.calls[0].options.credentials,'same-origin');assert.equal(h.fields.customer_id.value,71);assert.equal(h.fields.project_ref.value,'CRM #8');assert.equal(h.controls[0].disabled,false);assert.equal(h.controls[1].disabled,true);assert(h.messages[0].text.includes('保存后'));
 h=await run({quoteId:22});assert.equal(h.calls.length,0);assert.equal(h.fields.customer_id.value,'');
 h=await run({existing:true});assert.equal(h.calls.length,0);assert.equal(h.fields.project_ref.value,'');
 h=await run({search:'?page=quote_center'});assert.equal(h.calls.length,0);
 h=await run({failure:true});assert.equal(h.fields.customer_id.value,'');assert.equal(h.fields.project_ref.value,'');assert(h.messages[0].error);assert.equal(h.controls[0].disabled,false);assert.equal(h.controls[1].disabled,true);
 console.log('crm_quote_handoff_runtime_test: canonical prefill, locked loading, no existing-quote overwrite, permission failure, no auto-save passed');
})().catch(e=>{console.error(e);process.exitCode=1;});
