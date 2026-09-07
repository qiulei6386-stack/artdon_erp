'use strict';
// Execute original methods with isolated DOM/transport, never live requests.
const assert=require('node:assert/strict'),fs=require('node:fs'),vm=require('node:vm'),path=require('node:path');
const source=fs.readFileSync(path.join(__dirname,'../assets/crm/crm.js'),'utf8');
function method(module,name){const base=source.indexOf('  var '+module+' = {'),start=source.indexOf('    '+name+': function',base),end=source.indexOf('\n    },',start)+7;assert(base>=0&&start>base&&end>start);return source.slice(start,end);}
function harness(){
 const calls=[],messages=[],reader={innerHTML:''},candidateList={innerHTML:''};
 const context={allowed:true,crmActionAllowed(){return context.allowed;},esc:v=>String(v??''),cnStatus:v=>v,countryLabel:v=>v,toast:v=>messages.push(v),renderActions(){},document:{querySelector(q){return q==='[data-mail-reader]'?reader:q==='[data-radar-candidate-rows]'?candidateList:null;}},post(action,data){return new Promise((resolve,reject)=>calls.push({action,data,resolve,reject}));}};
 context.radarPost=context.post;
 const extract=(module,names)=>vm.runInNewContext('({'+names.map(n=>method(module,n)).join('\n')+'})',context);
 return {context,extract,calls,messages,reader,candidateList};
}
(async()=>{
 let h=harness(),r=h.extract('RadarModule',['loadSearchTasks','loadCandidates','openTaskCandidates','taskStatusLabel']);
 Object.assign(r,{data:{},candidateSelected:new Set([99]),candidateFilters:{country:'old'},pruneSelectedTasks(){},renderSearchTaskRows(){},renderCandidateCountryFilters(){},renderCandidateTable(){},switchView(v){this.view=v;}});
 h.context.allowed=false;r.openTaskCandidates(42);assert.equal(r.view,undefined);assert.equal(r.candidateSelected.size,1);
 h.context.allowed=true;r.openTaskCandidates(42);assert.equal(r.view,'candidates');assert.equal(r.candidateFilters.task_id,42);assert.equal(r.candidateFilters.country,undefined);assert.equal(r.candidateSelected.size,0);
 assert.equal(r.taskStatusLabel('waiting_analysis'),'搜索完成，候选待核查');
 let first=r.loadSearchTasks({q:'old'}),second=r.loadSearchTasks({q:'new'});
 h.calls[1].resolve({success:true,data:{rows:[{id:2}]}});await second;h.calls[0].resolve({success:true,data:{rows:[{id:1}]}});await first;assert.equal(r.data.tasks.rows[0].id,2);
 first=r.loadCandidates();r.candidateFilters={task_id:43,page:1,page_size:20};second=r.loadCandidates();
 assert.equal(h.calls[2].data.task_id,42);assert.equal(h.calls[3].data.task_id,43);
 h.calls[3].resolve({success:true,data:{rows:[{id:43}],page:1}});await second;h.calls[2].reject(new Error('stale failure'));await first;assert.equal(r.data.candidates.rows[0].id,43);assert(!h.candidateList.innerHTML.includes('stale failure'));
 h=harness();let m=h.extract('MailModule',['openScheduledMail']);Object.assign(m,{account:{id:3},folder:'scheduled',mailOpenSerial:0});h.context.MailModule=m;
 first=m.openScheduledMail('old');second=m.openScheduledMail('new');assert.equal(h.calls[0].data.mail_account_id,3);
 h.calls[1].resolve({success:true,data:{status:'unknown',subject:'new'}});await second;assert(h.reader.innerHTML.includes('切勿直接重发'));assert(!h.reader.innerHTML.includes('到时间后会自动发送'));
 h.calls[0].resolve({success:true,data:{status:'success',subject:'old'}});await first;assert(!h.reader.innerHTML.includes('<h2>old</h2>'));
 first=m.openScheduledMail('switch');m.account={id:4};h.reader.innerHTML='new account';h.calls[2].reject(new Error('old account error'));await first;assert.equal(h.reader.innerHTML,'new account');
 h=harness();let c=h.extract('CustomerModule',['renderCustomerOverviewV2']);Object.assign(c,{ownerDisplayText(){return '未分配';},renderCustomerFulfillmentStatus(){return '';}});
 let html=c.renderCustomerOverviewV2({customer:{id:1},_lazy_detail:1,_loaded_tabs:['overview','contacts'],sales_actions:[]});
 assert.equal((html.match(/<b>暂无评估<\/b>/g)||[]).length,4);assert(!html.includes('null%'));assert(html.includes('data-summary-jump="chat_groups"'));assert(!html.includes('跟进风险 90'));
 html=c.renderCustomerOverviewV2({customer:{id:1},scores:{health_score:0,activity_score:0,deal_probability:0,followup_risk:0}});assert(!html.includes('<b>暂无评估</b>'));assert(html.includes('<strong>0%</strong>'));
 h=harness();const forwarded=[];h.context.TaskCenterModule={openTaskFollowup(row){forwarded.push(row);}};h.context.CustomerModule={currentId:77,currentDetail:{customer:{id:77}}};
 const v=h.extract('VisitModule',['createFollowupFromVisit']);v.createFollowupFromVisit({id:501,customer_id:101,contact_id:4});assert.equal(forwarded[0].customer_id,101);assert.equal(forwarded[0].id,undefined);assert.equal(h.context.CustomerModule.currentId,77);
 h=harness();h.context.CustomerModule={currentId:0};const visit=h.extract('VisitModule',['createDispatchPlaceholder']);visit.load=()=>{};
 first=visit.createDispatchPlaceholder({id:7});visit.createDispatchPlaceholder({id:7});assert.equal(h.calls.length,1);h.calls[0].resolve({success:false,message:'permission denied'});await first;assert(!h.messages.some(v=>v.includes('派工已生成')));assert.equal(visit.dispatchSubmitting,false);
 first=visit.createDispatchPlaceholder({id:7});h.calls[1].resolve({success:true,data:{dispatch_id:70}});await first;assert(h.messages.includes('派工已生成 #70'));
 h=harness();const dialogs=[];const mail={currentId:11,focusedMailId:11,currentMail:null,mailOpenSerial:0,account:{id:3},mailDetailCache:{},openLinkCustomerDialog(m){dialogs.push(m.id);}};h.context.MailModule=mail;
 const begin=source.indexOf('  function handleMailAction('),end=source.indexOf('  function handlePromotionAction(',begin);
 const action=vm.runInNewContext(source.slice(begin,end)+';handleMailAction',h.context);
 first=action('关联客户');mail.currentId=12;second=action('关联客户');h.calls[1].resolve({success:true,data:{mail:{id:12}}});await second;h.calls[0].resolve({success:true,data:{mail:{id:11}}});await first;assert.deepEqual(dialogs,[12]);assert.equal(mail.currentMail.id,12);
 mail.currentId=13;mail.currentMail=null;first=action('关联客户');h.calls[2].resolve({success:true,data:{mail:{id:99}}});await first;assert.deepEqual(dialogs,[12]);assert(h.messages.includes('邮件身份不一致，请重新选择。'));
 const availabilityContext={window:{},Set};vm.runInNewContext(fs.readFileSync(path.join(__dirname,'../assets/crm/workspace.js'),'utf8'),availabilityContext);
 const available=availabilityContext.window.CRMWorkspace.actionAvailable;
 assert(!available('mail','创建商机'));assert(available('opportunities','创建报价'));assert(available('customers','新建商机'));assert(!available('promotion','通知负责人'));assert(available('promotion','填写结果'));assert(available('promotion','上传截图'));assert(available('promotion','转人工执行'));
 // Reloaded task rows must not be overwritten by cached pre-completion details.
 h=harness();const task=h.extract('TaskCenterModule',['load','loadSelectedDetail','selected']);h.context.TaskCenterModule=task;
 h.context.CRMWorkspace={pager(){}};
 Object.assign(task,{view:'my',q:'',selectedType:'task',selectedId:4338,rows:[{id:4338,status:'pending'}],renderTasks(){},renderDetail(){},currentDetail:{task:{id:4338,status:'pending'}}});
 first=task.loadSelectedDetail();
 second=task.load();assert.equal(task.currentDetail,null);
 h.calls[0].resolve({success:true,data:{task:{id:4338,status:'pending'}}});await first;assert.equal(task.currentDetail,null);
 h.calls[1].resolve({success:true,data:{rows:[{id:4338,status:'done'}]}});await second;
 assert.equal(h.calls[2].action,'task_detail');assert.equal(h.calls[2].data.task_id,4338);
 h.calls[2].resolve({success:true,data:{task:{id:4338,status:'done'},logs:[{action:'task_status_update'}]}});await new Promise(resolve=>setImmediate(resolve));
 assert.equal(task.currentDetail.task.status,'done');assert.equal(task.currentDetail.logs.length,1);
 first=task.loadSelectedDetail();second=task.loadSelectedDetail();
 h.calls[4].resolve({success:true,data:{task:{id:4338,status:'done'}}});await second;
 h.calls[3].resolve({success:true,data:{task:{id:4338,status:'pending'}}});await first;assert.equal(task.currentDetail.task.status,'done');
 first=task.loadSelectedDetail();task.selectedId=99;task.rows=[{id:99,status:'pending'}];second=task.loadSelectedDetail();
 h.calls[6].resolve({success:true,data:{task:{id:99,status:'pending'}}});await second;
 h.calls[5].resolve({success:true,data:{task:{id:4338,status:'done'}}});await first;assert.equal(task.currentDetail.task.id,99);
 task.selectedType='sample';task.samples=[{id:99}];first=task.loadSelectedDetail();second=task.loadSelectedDetail();
 h.calls[8].resolve({success:true,data:{shipment:{id:99,status:'signed'}}});await second;
 h.calls[7].resolve({success:true,data:{shipment:{id:99,status:'shipped'}}});await first;assert.equal(task.currentDetail.shipment.status,'signed');
 // A completion form submits its captured task, never a later selection.
 h=harness();const complete=h.extract('TaskCenterModule',['openCompleteDialog']);h.context.TaskCenterModule=complete;
 let submit,formHtml='',submitted;
 const button={addEventListener(event,fn){submit=fn;}};
 h.context.CustomerModule={openBusinessDialog(title,html,help,bind){formHtml=html;bind({querySelector(q){return q==='[data-task-complete-save]'?button:q==='[name="result"]'?{value:'内部处理完成'}:q==='[name="result_note"]'?{value:'验收'}:null;}});},closeDialog(){}};
 complete.selectedType='task';complete.selectedId=4338;complete.selected=()=>({id:4338,title:'验收'});complete.runBusy=(button,text,work)=>{submitted=work();};complete.load=()=>{};
 complete.openCompleteDialog();assert(!formHtml.includes('name="create_dispatch"'));assert(!formHtml.includes('name="create_followup"'));assert(!formHtml.includes('name="next_followup_time"'));
 complete.selectedId=99;submit.call(button);assert.equal(h.calls[0].data.task_id,4338);
 h.calls[0].resolve({success:true});await submitted;
 console.log('crm_complete_workflow_runtime_test: radar/mail/visit identity, truthful metrics, task refresh and completion identity passed');
})().catch(e=>{console.error(e);process.exitCode=1;});
