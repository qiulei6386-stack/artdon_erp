'use strict';
// Original frontend methods, fake DOM and transport. No business API calls.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const source = fs.readFileSync(path.join(__dirname, '../assets/crm/crm.js'), 'utf8');
function extract(module, method) {
  const moduleStart = source.indexOf('  var ' + module + ' = {');
  const start = source.indexOf('    ' + method + ': function', moduleStart);
  let end = source.indexOf('\n    },', start);
  const moduleEnd = source.indexOf('\n  };', start);
  if (end < 0 || moduleEnd < end) end = moduleEnd;
  else end += '\n    },'.length;
  assert(start >= moduleStart && end > start, 'Missing original method ' + method);
  return source.slice(start, end);
}
function deferred() { let resolve; const promise=new Promise(r=>{resolve=r;}); return {promise,resolve}; }
function harness() {
  const messages=[],dialogs=[],requests=[];
  const state={action_permissions:{'编辑任务':false,'标记完成':false},action_contracts:{tasks:{
    '编辑任务':{id:'tasks.edit',allowed:true},'标记完成':{id:'tasks.complete',allowed:true},
    '创建派工':{id:'tasks.create_dispatch',allowed:true},'生成派工':{id:'tasks.create_dispatch',allowed:true},
    '新建跟进':{id:'tasks.create_followup',allowed:true}
  }}};
  const customer={currentId:0,currentDetail:null,openBusinessDialog(title,html,hint,bind){dialogs.push({title,html,hint,bind});},closeDialog(){},openFollowupDialog(){messages.push('followup-opened');},loadDetail(){return Promise.resolve();}};
  const context=vm.createContext({state,CustomerModule:customer,window:{crypto:{randomUUID(){return String(++context.nonce);}}},nonce:0,Promise,Set,Date,Number,Array,JSON,
    document:{querySelector(){return null;}},esc:v=>String(v==null?'':v),toast:v=>messages.push(v),
    post(action,data){requests.push({action,data});return context.transport(action,data);},transport(){return Promise.resolve({success:true,data:{}});}});
  const helperStart=source.indexOf('  function crmActionContract(');
  const helperEnd=source.indexOf('  function hashParts(',helperStart);
  vm.runInContext(source.slice(helperStart,helperEnd),context);
  const taskNames=['requestToken','runBusy','actionPending','actionButton','openSampleDialog','saveSample','openTaskFollowup','openTaskDispatchDialog','handleAction'];
  const task=vm.runInContext('({' + taskNames.map(n=>extract('TaskCenterModule',n)).join('\n') + '})',context);
  Object.assign(task,{selected(){return task.row||null;},row:null,view:'my',selectedType:'task',isViewAction(){return false;},quoteFlowFilterKeyFromLabel(){return '';},userOptions(){return '';},courierOptions(){return '';},statusOptions(){return '';},sampleFilesHtml(){return '';},validateSampleUploadInputs(){},collect(form){return Object.fromEntries(Object.entries(form.fields).map(([k,v])=>[k,v.value]));},load(){},loadSelectedDetail(){},clearSampleUploadInputs(){},refreshSampleFiles(){return Promise.resolve([]);}});
  context.TaskCenterModule=task;
  const promotionNames=['canStartTask','canPauseTask','executeTask','setTaskStatus'];
  const promotion=vm.runInContext('({' + promotionNames.map(n=>extract('PromotionModule',n)).join('\n') + '})',context);
  Object.assign(promotion,{data:{tasks:[]},closeDialog(){},render(){},load(){return Promise.resolve();},showError(v){messages.push('ERROR: '+v);},openDialog(options){dialogs.push(options);}});
  return {context,state,customer,task,promotion,messages,dialogs,requests};
}
function sampleDialog() {
  const fields={shipment_id:{value:''},request_token:{value:'sample-fixed-token'},customer_id:{value:'101'},sample_name:{value:'Fixture'}};
  const form={fields,querySelector(selector){const match=selector.match(/name="([^"]+)"/);return match?fields[match[1]]:null;}};
  const button={disabled:false},upload={disabled:false},error={textContent:''};
  return {dataset:{},form,button,error,querySelector(selector){return {'[data-sample-form]':form,'[data-sample-save]':button,'[data-sample-upload-now]':upload,'[data-sample-error]':error}[selector]||null;}};
}
function followupHarness() {
  const h=harness(),details=[],selections=[],observers=new Set();
  function eventNode() {
    const listeners=new Map();
    return {dataset:{},isConnected:true,disabled:false,textContent:'',value:'Fixture',focus(){},
      addEventListener(type,fn){if(!listeners.has(type))listeners.set(type,new Set());listeners.get(type).add(fn);},
      removeEventListener(type,fn){listeners.get(type)?.delete(fn);},
      emit(type,event={}){let result;for(const fn of [...(listeners.get(type)||[])])result=fn.call(this,event);return result;}};
  }
  function mutation(target,type) {
    for(const observer of observers)if(observer.targets.get(target)?.[type]) {
      Promise.resolve().then(()=>{if(observer.active)observer.callback([{target,type}]);});
    }
  }
  h.context.MutationObserver=class {
    constructor(callback){this.callback=callback;this.targets=new Map();this.active=true;observers.add(this);}
    observe(target,options){this.targets.set(target,options);}
    disconnect(){this.active=false;this.targets.clear();observers.delete(this);}
  };
  const dialog=eventNode(),body={firstChild:{}};
  let picker=null,fields=null;
  dialog.open=false;
  dialog.querySelector=selector=>selector==='[data-dialog-body]'?body:selector==='[data-task-followup-picker]'?picker:(fields&&fields[selector])||null;
  h.context.document.querySelector=selector=>selector==='[data-customer-dialog]'?dialog:null;
  const replace=()=>{
    if(picker)picker.isConnected=false;
    picker=null;fields=null;body.firstChild={};dialog.open=true;
    mutation(body,'childList');mutation(dialog,'attributes');
  };
  h.customer.openBusinessDialog=(title,html,hint,bind)=>{
    replace();picker=eventNode();fields={};
    for(const key of ['[data-task-followup-search]','[data-task-followup-results]','[data-task-followup-search-button]','[data-business-cancel]'])fields[key]=eventNode();
    picker.querySelector=selector=>fields[selector]||null;
    h.dialogs.push({title,html,hint,bind});bind(dialog);
  };
  h.customer.closeDialog=()=>{dialog.open=false;mutation(dialog,'attributes');dialog.emit('close');};
  h.customer.loadDetail=id=>{
    const d=deferred();details.push({id,...d});
    return d.promise.then(()=>{h.customer.currentId=id;h.customer.currentDetail={customer:{id}};return h.customer.currentDetail;});
  };
  const runBusy=h.task.runBusy;
  h.task.runBusy=function(...args){const pending=runBusy.apply(this,args);selections.push(pending);return pending;};
  return Object.assign(h,{dialog,body,details,replace,
    picker(){return picker;},fields(){return fields;},
    async pick(id){
      const button=eventNode();button.getAttribute=()=>String(id);
      fields['[data-task-followup-results]'].emit('click',{target:{closest(){return button;}}});
      await Promise.resolve();
      return {request:details.at(-1),pending:selections.at(-1)};
    },
    close(kind){
      if(kind==='button')fields['[data-business-cancel]'].emit('click');
      else {if(kind==='escape')dialog.emit('cancel');h.customer.closeDialog();}
    },
    closeAndReopenBeforeEvents(){dialog.open=false;mutation(dialog,'attributes');dialog.open=true;mutation(dialog,'attributes');},
    observerCount(){return observers.size;}
  });
}
let count=0;
async function test(name,fn){await fn();count++;console.log('PASS '+name);}
(async()=>{
  await test('module-scoped permission overrides unrelated global label',()=>{
    const h=harness();assert.equal(vm.runInContext("crmActionAllowed('tasks','编辑任务')",h.context),true);
    h.state.action_contracts.tasks['编辑任务'].allowed=false;h.state.action_permissions['编辑任务']=true;
    assert.equal(vm.runInContext("crmActionAllowed('tasks','编辑任务')",h.context),false);
    assert.equal(h.task.actionButton('编辑任务'),'');
  });
  await test('dispatch aliases open the same real handler; permission denial blocks it',()=>{
    const h=harness();let calls=0;h.task.openTaskDispatchDialog=()=>calls++;
    h.task.handleAction('创建派工');h.task.handleAction('生成派工');assert.equal(calls,2);assert.equal(h.requests.length,0);
    h.state.action_contracts.tasks['创建派工'].allowed=false;h.task.handleAction('创建派工');assert.equal(calls,2);
    assert.equal(h.task.actionPending('创建派工'),false);assert.equal(h.task.actionPending('查询物流'),true);
  });
  await test('new follow-up routes even when no task is selected',()=>{
    const h=harness();let row='not-called';h.task.openTaskFollowup=value=>{row=value;};h.task.handleAction('新建跟进');assert.equal(row,null);
  });
  await test('external sample/quote IDs never masquerade as CRM task IDs',()=>{
    const h=harness();for(const type of ['sample','quote_flow']){h.task.selectedType=type;h.task.openTaskDispatchDialog({id:991});assert.equal(h.dialogs.length,0);}
    h.task.openTaskDispatchDialog({id:991,task_id:42});assert.match(h.dialogs[0].html,/name="task_id" value="42"/);
  });
  await test('follow-up opens only after the requested customer is actually loaded',async()=>{
    const h=harness();h.customer.currentId=101;h.customer.currentDetail={customer:{id:202}};
    await h.task.openTaskFollowup({customer_id:101});assert.equal(h.messages.length,0);
    h.customer.currentDetail={customer:{id:101}};await h.task.openTaskFollowup({customer_id:101});assert.equal(h.messages[0],'followup-opened');
  });
  await test('picker selection opens once and releases dialog observers',async()=>{
    const h=followupHarness();h.task.openTaskFollowup(null);const selection=await h.pick(101);
    selection.request.resolve();await selection.pending;
    assert.deepEqual(h.messages,['followup-opened']);assert.equal(h.observerCount(),0);assert.equal(h.task.followupIntentCleanup,null);
  });
  for(const kind of ['button','right-close','escape'])await test('follow-up '+kind+' cancellation discards a delayed customer response',async()=>{
    const h=followupHarness();h.task.openTaskFollowup(null);const selection=await h.pick(101);
    h.close(kind);selection.request.resolve();await selection.pending;
    assert.equal(h.dialog.open,false);assert.equal(h.messages.length,0);assert.equal(h.observerCount(),0);
  });
  await test('replacement of the shared dialog cannot be overwritten by an old picker',async()=>{
    const h=followupHarness();h.task.openTaskFollowup(null);const selection=await h.pick(101);
    h.replace();const replacement=h.body.firstChild;selection.request.resolve();await selection.pending;
    assert.equal(h.messages.length,0);assert.equal(h.body.firstChild,replacement);
  });
  await test('direct task follow-up cannot replace a dialog opened during its request',async()=>{
    const h=followupHarness(),pending=h.task.openTaskFollowup({customer_id:101});
    h.replace();h.details[0].resolve();await pending;assert.equal(h.messages.length,0);
  });
  for(const order of ['old-first','new-first'])await test('same-customer repeated entry honors the latest intent ('+order+')',async()=>{
    const h=followupHarness(),first=h.task.openTaskFollowup({customer_id:101}),second=h.task.openTaskFollowup({customer_id:101});
    if(order==='old-first'){
      h.details[0].resolve();await first;assert.equal(h.messages.length,0);h.details[1].resolve();await second;
    }else{
      h.details[1].resolve();await second;assert.equal(h.messages.length,1);h.details[0].resolve();await first;
    }
    assert.deepEqual(h.messages,['followup-opened']);assert.equal(h.observerCount(),0);
  });
  await test('later picker selection wins even when both selections use the same customer',async()=>{
    const h=followupHarness();h.task.openTaskFollowup(null);
    const first=await h.pick(101),second=await h.pick(101);
    first.request.resolve();await first.pending;assert.equal(h.messages.length,0);
    second.request.resolve();await second.pending;assert.deepEqual(h.messages,['followup-opened']);
  });
  await test('closing and reopening the same dialog content still invalidates the old intent',async()=>{
    const h=followupHarness();h.task.openTaskFollowup(null);const selection=await h.pick(101);
    h.closeAndReopenBeforeEvents();selection.request.resolve();await selection.pending;
    assert.equal(h.dialog.open,true);assert.equal(h.messages.length,0);
  });
  await test('cancelled picker ignores delayed customer search results',async()=>{
    const h=followupHarness(),d=deferred();h.context.transport=()=>d.promise;h.task.openTaskFollowup(null);
    const results=h.fields()['[data-task-followup-results]'];
    const pending=h.fields()['[data-task-followup-search-button]'].emit('click');h.close('button');
    d.resolve({success:true,data:{rows:[{id:101,customer_name:'Fixture'}]}});await pending;
    assert.equal(results.innerHTML,undefined);assert.equal(h.messages.length,0);
  });
  await test('each sample form receives a distinct persistent token; edits have none',()=>{
    const h=harness();h.task.openSampleDialog({});h.task.openSampleDialog({});h.task.openSampleDialog({id:8});
    const tokens=h.dialogs.map(d=>d.html.match(/name="request_token" value="([^"]*)"/)[1]);
    assert.notEqual(tokens[0],tokens[1]);assert.equal(tokens[2],'');
  });
  await test('upload failure retains saved shipment ID before retry',async()=>{
    const h=harness(),dialog=sampleDialog();h.context.transport=()=>Promise.resolve({success:true,data:{shipment:{id:51}}});
    h.task.uploadQueuedFiles=()=>Promise.reject(new Error('fake upload failure'));
    await h.task.saveSample(dialog);assert.equal(dialog.form.fields.shipment_id.value,51);assert.match(dialog.error.textContent,/upload failure/);
    h.task.uploadQueuedFiles=()=>Promise.resolve();await h.task.saveSample(dialog);
    assert.equal(h.requests[1].data.shipment_id,51);assert.equal(h.requests[1].data.request_token,'sample-fixed-token');assert.equal(dialog.button.disabled,false);
  });
  await test('sample double click is single-flight; failures preserve form and token',async()=>{
    const h=harness(),dialog=sampleDialog(),d=deferred();h.context.transport=()=>d.promise;
    const pending=h.task.saveSample(dialog);await h.task.saveSample(dialog);assert.equal(h.requests.length,1);
    d.resolve({success:false,message:'保存被拒绝'});await pending;
    assert.equal(dialog.form.fields.shipment_id.value,'');assert.equal(dialog.form.fields.request_token.value,'sample-fixed-token');assert.equal(dialog.error.textContent,'保存被拒绝');assert.equal(dialog.button.disabled,false);
  });
  await test('promotion start/pause predicates cover scheduled and manual states consistently',()=>{
    const h=harness();for(const task_status of ['pending','scheduled','running','manual_pending','partial_failed','failed'])assert(h.promotion.canStartTask({task_status}));
    for(const task_status of ['draft','paused','cancelled','completed'])assert(!h.promotion.canStartTask({task_status}));
    assert(h.promotion.canPauseTask({task_status:'scheduled'}));assert(h.promotion.canPauseTask({task_status:'manual_pending'}));
  });
  await test('legacy fake success payload cannot produce a completed UI',async()=>{
    const h=harness();h.context.transport=()=>Promise.resolve({success:true,data:{success_count:9,failed_count:0}});
    await h.promotion.executeTask(7);assert.equal(h.dialogs.length,0);assert.match(h.messages[0],/^ERROR:/);
  });
  await test('queued acceptance shows actual queue entry, never success counts',async()=>{
    const h=harness();h.context.transport=()=>Promise.resolve({success:true,data:{task_id:7,accepted:true,execution_mode:'queued',queue_count:3,manual_target_count:0,message:'已入队'}});
    await h.promotion.executeTask(7);assert.equal(h.dialogs[0].title,'推广执行状态');assert.match(h.dialogs[0].actions,/data-promo-accepted-queue/);assert.doesNotMatch(h.dialogs[0].description,/成功/);assert.equal(h.messages[0],'已入队');
  });
  await test('manual acceptance exposes manual checklist rather than mail results',async()=>{
    const h=harness();h.context.transport=()=>Promise.resolve({success:true,data:{task_id:7,accepted:true,execution_mode:'manual',queue_count:0,manual_target_count:2,message:'人工待处理'}});
    await h.promotion.executeTask(7);assert.match(h.dialogs[0].actions,/data-promo-accepted-manual/);assert.doesNotMatch(h.dialogs[0].actions,/data-promo-accepted-queue/);
  });
  await test('promotion repeated click reuses request and wrong task ID is rejected',async()=>{
    const h=harness(),d=deferred();h.context.transport=()=>d.promise;
    const first=h.promotion.executeTask(7),second=h.promotion.executeTask(7);assert.equal(first,second);assert.equal(h.requests.length,1);
    d.resolve({success:true,data:{task_id:8,accepted:true,execution_mode:'queued'}});await first;
    assert.equal(h.dialogs.length,0);assert.match(h.messages[0],/^ERROR:/);assert.equal(h.promotion.executionPending[7],undefined);
  });
  await test('pause/cancel UI preserves server explanation of in-flight mail',async()=>{
    const h=harness();h.context.transport=()=>Promise.resolve({success:true,data:{message:'已暂停；1 封邮件仍在发送中'}});
    await h.promotion.setTaskStatus(7,'paused');assert.equal(h.messages[0],'已暂停；1 封邮件仍在发送中');
  });
  assert(source.includes("if (name === 'tasks' && TaskCenterModule.actionPending(label)) return false;"));
  assert(!source.includes("post('task_dispatch_placeholder'"));
  console.log('CRM phase1 action runtime: '+count+' passed; fake transport only.');
})().catch(error=>{console.error(error);process.exitCode=1;});
