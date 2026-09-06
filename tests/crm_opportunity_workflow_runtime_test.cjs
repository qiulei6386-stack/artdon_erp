'use strict';
// Original module methods against deterministic DOM/transport doubles; no API calls.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const source = fs.readFileSync(path.join(__dirname, '../assets/crm/crm.js'), 'utf8');
function method(module, name) {
  const moduleStart = source.indexOf('  var ' + module + ' = {');
  const start = source.indexOf('    ' + name + ': function', moduleStart);
  const end = source.indexOf('\n    },', start) + 7;
  assert(start >= moduleStart && end > start, name);
  return source.slice(start, end);
}
function deferred() { let resolve, reject; const promise = new Promise((a,b) => {resolve=a;reject=b;}); return {promise,resolve,reject}; }
function fixture() {
  const requests=[], messages=[], calls=[], board={innerHTML:''};
  const context=vm.createContext({Promise, Date, Number, Array, Error, state:{},
    esc:v=>String(v??''), toast:m=>messages.push(m), renderActions(){},
    document:{querySelector(){return board;}},
    window:{crypto:{randomUUID(){return '12345678-1234-1234-1234-123456789012';}}},
    crmActionAllowed(){return context.allowed!==false;},
    post(action,data){const req=deferred();requests.push({action,data,...req});return req.promise;},
    CustomerModule:{currentId:0,currentDetail:null,closeDialog(){calls.push('close');},loadDetail(){},openBusinessDialog(...args){calls.push(args);}},
    FormData:class {set(){} append(){}}, fetch(){return context.response.promise;}
  });
  const names=['load','selected','contactOptions','collect','ownsDialogForm','save','uploadQueuedFiles','uploadFileInput','loadOpportunityFiles','handleAction','openLinkedTask','saveLinkedTask','userOptions','priorityOptions'];
  const op=vm.runInContext('({' + names.map(n=>method('OpportunityModule',n)).join('\n') + '})',context);
  const task=vm.runInContext('({' + ['runBusy','requestToken'].map(n=>method('TaskCenterModule',n)).join('\n') + '})',context);
  task.openTaskFollowup=row=>calls.push(['followup',row]);
  context.TaskCenterModule=task; context.OpportunityModule=op;
  Object.assign(op,{users:[],rows:[],render(data){calls.push(['render',data]);}});
  return {op,task,context,requests,messages,calls,board};
}
function formDialog(kind='opportunity') {
  const fields={opportunity_id:{name:'opportunity_id',value:''}, create_followup:{name:'create_followup',type:'checkbox',checked:true},request_token:{name:'request_token',value:'op-task-fixed-token'}};
  const error={textContent:''};
  const form={isConnected:true,fields,querySelector(s){if(s.includes('error')) return error; const name=s.match(/name="([^"]+)"/); return name?fields[name[1]]:null;},querySelectorAll(){return Object.values(fields);}};
  const button={dataset:{},disabled:false,textContent:'保存'};
  const selector=kind==='opportunity'?'[data-opportunity-form]':'[data-opportunity-task-form]';
  const dialog={open:true,form,querySelector(s){if(s===selector)return this.form;return s.includes('save')?button:null;}};
  return {dialog,form,fields,error,button};
}
async function tick() {await Promise.resolve();await Promise.resolve();}
async function main() {
  let count=0;
  {
    const h=fixture();h.op.rows=[{id:2}];h.op.selectedId=2;
    const a=h.op.load(),b=h.op.load();
    h.requests[1].resolve({success:true,data:{rows:[{id:3}],stats:{total:1}}});await b;
    h.requests[0].resolve({success:true,data:{rows:[{id:2}]}});await a;
    assert.equal(h.op.rows[0].id,3);assert.equal(h.op.selectedId,0);assert.equal(h.calls.length,1);count++;
    const c=h.op.load(),d=h.op.load();h.requests[3].resolve({success:true,data:{rows:[{id:4}]}});await d;
    h.board.innerHTML='latest';h.requests[2].reject(Error('old failure'));await c;
    assert.equal(h.board.innerHTML,'latest');count++;
  }
  {
    const h=fixture();h.op.rows=[{id:8,customer_id:10}];h.op.selectedId=8;
    h.context.CustomerModule.currentId=20;
    h.op.handleAction('创建跟进');assert.equal(h.calls[0][0],'followup');assert.equal(h.calls[0][1].customer_id,10);assert.equal(h.context.CustomerModule.currentId,20);count++;
    h.context.allowed=false;h.op.handleAction('创建跟进');assert.equal(h.calls.length,1);count++;
    h.context.CustomerModule.currentDetail={customer:{id:20},contacts:[{id:201,name:'WRONG'}]};
    assert(!h.op.contactOptions(101,10).includes('WRONG'));assert(h.op.contactOptions(101,10).includes('101'));assert(h.op.contactOptions(201,20).includes('WRONG'));count++;
    h.context.allowed=true;h.op.handleAction('创建样品任务');
    const opened=h.calls.at(-1);assert(opened[1].includes('name="opportunity_id" value="8"'));assert(opened[1].includes('value="sample_task"'));assert(!opened[1].includes('name="task_id"'));assert(opened[2].includes('不会自动寄出'));count++;
  }
  {
    const h=fixture(),f=formDialog();h.op.load=()=>h.calls.push('reload');
    let uploads=0;h.op.uploadQueuedFiles=async(id,root)=>{assert.equal(id,71);assert.equal(root,f.form);if(++uploads===1)throw Error('upload failed');};
    const a=h.op.save(f.dialog),double=h.op.save(f.dialog);await tick();assert.equal(h.requests.length,1);await double;
    h.requests[0].resolve({success:true,data:{opportunity:{id:71}}});await a;
    assert.equal(f.fields.opportunity_id.value,71);assert.equal(f.fields.create_followup.checked,false);assert(!h.calls.includes('close'));assert.match(f.error.textContent,/商机已保存/);assert(!f.button.disabled);count++;
    const retry=h.op.save(f.dialog);await tick();assert.equal(h.requests[1].data.opportunity_id,71);assert.equal(h.requests[1].data.create_followup,'');
    h.requests[1].resolve({success:true,data:{opportunity:{id:71}}});await retry;assert(h.calls.includes('close'));count++;
  }
  {
    const h=fixture(),f=formDialog();h.op.load=()=>{};let uploaded=false;h.op.uploadQueuedFiles=async()=>{uploaded=true;};
    const a=h.op.save(f.dialog);await tick();f.dialog.form={};f.form.isConnected=false;
    h.requests[0].resolve({success:true,data:{opportunity:{id:80}}});await a;
    assert(!uploaded);assert(!h.calls.includes('close'));count++;
  }
  {
    const h=fixture(),f=formDialog();h.op.load=()=>{};const upload=deferred();h.op.uploadQueuedFiles=()=>upload.promise;
    const a=h.op.save(f.dialog);await tick();h.requests[0].resolve({success:true,data:{opportunity:{id:80}}});await tick();
    f.dialog.form={};upload.resolve();await a;assert(!h.calls.includes('close'));count++;
  }
  {
    const h=fixture(),f=formDialog();let uploads=0;h.op.uploadQueuedFiles=async()=>uploads++;
    const a=h.op.save(f.dialog);await tick();h.requests[0].resolve({success:true,data:{}});await a;
    assert.equal(uploads,0);assert(!h.calls.includes('close'));assert(!f.button.disabled);count++;
  }
  {
    const h=fixture();const image={files:[{}]},attachment={files:[{}]},a=deferred(),b=deferred();
    h.op.uploadFileInput=(id,type)=>type==='image'?a.promise:b.promise;
    let done=false;const job=h.op.uploadQueuedFiles(3,{querySelector:s=>s.includes('image')?image:attachment}).catch(()=>{done=true;});
    a.reject(Error('image failed'));await tick();assert(!done);b.resolve();await job;assert(done);count++;
  }
  {
    const h=fixture(),input={files:[{}],value:'selected'};h.context.response=deferred();
    const a=h.op.uploadFileInput(3,'image',input);h.context.response.resolve({json:async()=>({success:true})});await a;assert.equal(input.value,'');count++;
    input.value='new selection';input.files=[{}];h.context.response=deferred();const b=h.op.uploadFileInput(3,'image',input);input.files=[{}];h.context.response.resolve({json:async()=>({success:true})});await b;assert.equal(input.value,'new selection');count++;
  }
  {
    const h=fixture(),input={files:[{},{}],value:'selected'};let calls=0;
    h.context.fetch=async()=>({json:async()=>({success:++calls!==2,message:'second file failed'})});
    await assert.rejects(h.op.uploadFileInput(3,'image',input),/second file/);assert.equal(calls,2);assert.equal(input.value,'selected');
    await h.op.uploadFileInput(3,'image',input);assert.equal(calls,3);assert.equal(input.value,'');count++;
  }
  {
    const h=fixture(),f=formDialog();f.fields.opportunity_id.value=3;
    const a=h.op.loadOpportunityFiles(3,f.dialog);f.dialog.form={};h.requests[0].resolve({success:true,data:{files:[{id:99}]}});await a;assert(!h.calls.length);count++;
    f.fields.create_dispatch={name:'create_dispatch',type:'checkbox',checked:true,disabled:true};assert(!('create_dispatch' in h.op.collect(f.form)));count++;
  }
  {
    const h=fixture(),f=formDialog('task');const a=h.op.saveLinkedTask(f.dialog),b=h.op.saveLinkedTask(f.dialog);await tick();assert.equal(h.requests.length,1);await b;
    assert.equal(h.requests[0].action,'opportunity_create_task');h.requests[0].resolve({success:false,message:'try again'});await a;
    assert.equal(f.error.textContent,'try again');assert(!f.button.disabled);assert(!h.calls.includes('close'));count++;
    const c=h.op.saveLinkedTask(f.dialog);await tick();assert.equal(h.requests[0].data.request_token,h.requests[1].data.request_token);
    f.dialog.form={};h.requests[1].resolve({success:true,data:{task:{id:55},reused:true}});await c;
    assert(!h.calls.includes('close'));assert(h.messages.at(-1).includes('#55'));assert(h.messages.at(-1).includes('未重复'));count++;
  }
  console.log(`crm_opportunity_workflow_runtime_test: ${count} passed`);
}
main().catch(error=>{console.error(error);process.exitCode=1;});
