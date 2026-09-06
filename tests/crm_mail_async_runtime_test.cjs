'use strict';
// Original methods, fake DOM/transport/timers only.
const assert=require('node:assert/strict'),fs=require('node:fs'),vm=require('node:vm');
const source=fs.readFileSync(require('node:path').join(__dirname,'../assets/crm/crm.js'),'utf8');
function extract(name){const start=source.indexOf('    '+name+': function');const end=source.indexOf('\n    },',start);assert(start>=0&&end>start);return source.slice(start,end+7);}
function harness(){
 const requests=[],toasts=[],timers=[];const button={disabled:false,textContent:'发送'};
 const form={dataset:{composeGeneration:'first'}};
 const status={textContent:'',classList:{add(){},remove(){}}};
 const doc={querySelector(q){if(q==='[data-mail-compose-form]')return form;if(q==='[data-mail-compose-form] button[type="submit"]')return button;if(q==='[data-mail-compose-status]')return status;return null;}};
 const context={document:doc,window:{crypto:{randomUUID(){return 'fixture-submission-token-0001';}},setTimeout(fn){timers.push(fn);}},current:'mail',toast(v){toasts.push(v);},post(action,data){return new Promise((resolve,reject)=>requests.push({action,data,resolve,reject}));},postForm(action,data){return new Promise((resolve,reject)=>requests.push({action,data,resolve,reject}));}};
 const methods=vm.runInNewContext('({'+['sendMail','watchSendProgress'].map(extract).join('\n')+'})',context);
 const m={...methods,account:{id:3},pendingQuoteTaskBinding:null,closed:0,saved:0,loads:0,syncComposeUploadFiles(){},composeData(){return {to_emails:'recipient@example.invalid',subject:'Fixture',body_html:'fixture'};},composeUploadFiles(){return [];},closeCompose(){this.closed++;},saveDraft(){this.saved++;return Promise.resolve();},loadList(){this.loads++;return Promise.resolve();}};
 context.MailModule=m;
 return {m,form,button,status,requests,toasts,timers};
}
async function flush(){for(let i=0;i<12;i++)await Promise.resolve();}
(async()=>{
 let h=harness();let p=h.m.sendMail();assert.equal(h.m.sendMail(),undefined);assert.equal(h.requests.length,1);
 const token=h.requests[0].data.request_token;assert.equal(h.requests[0].data.mail_account_id,3);
 h.requests[0].resolve({success:true,data:{job_id:'j1',status:'scheduled',message:'queued'}});await flush();
 assert.equal(h.m.closed,1);assert(!h.toasts.includes('邮件已发送'));assert.equal(h.requests[1].action,'mail_send_progress');
 h.requests[1].resolve({success:true,data:{status:'success',sent_mail_id:8}});await p;assert(h.toasts.includes('邮件已发送'));assert.equal(h.m.saved,0);
 h=harness();p=h.m.sendMail();h.requests[0].reject(new Error('lost response'));await p;assert.equal(h.m.saved,1);const retry=h.m.sendMail();assert.equal(h.requests[1].data.request_token,token);h.requests[1].resolve({success:false,message:'fixture stop'});await retry;
 h=harness();p=h.m.sendMail();h.form.dataset.composeGeneration='second';h.form.dataset.submitting='';h.m.pendingQuoteTaskBinding={task_id:22};
 h.requests[0].resolve({success:true,data:{job_id:'old',status:'scheduled'}});await flush();assert.equal(h.m.closed,0);assert.equal(h.m.pendingQuoteTaskBinding.task_id,22);
 h.requests[1].resolve({success:true,data:{status:'unknown',error_message:'receipt uncertain'}});await p;assert.equal(h.m.saved,0);assert(!h.toasts.includes('邮件已发送'));assert.equal(h.m.pendingQuoteTaskBinding.task_id,22);
 for(const status of ['failed','unknown','cancelled']){
  h=harness();p=h.m.watchSendProgress('terminal',null,null,3);h.requests[0].resolve({success:true,data:{status}});await assert.rejects(p);assert.equal(h.timers.length,0);
 }
 h=harness();p=h.m.watchSendProgress('bounded',null,null,3);
 for(let i=0;i<100;i++){h.requests[i].resolve({success:true,data:{status:'scheduled',remaining_seconds:0}});await flush();if(i<99){assert.equal(h.timers.length,1);h.timers.shift()();}}
 await assert.rejects(p,/停止前台轮询/);
 console.log('crm_mail_async_runtime_test: acceptance, duplicate-click, retry token, stale form, terminal states and bounded polling passed');
})().catch(e=>{console.error(e);process.exitCode=1;});
