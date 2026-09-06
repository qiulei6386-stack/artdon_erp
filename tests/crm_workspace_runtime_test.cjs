'use strict';
// Original methods and fake transport: no application or business requests.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');
const source = fs.readFileSync(path.join(__dirname, '../assets/crm/crm.js'), 'utf8');
function method(module, name) {
  const offset = source.indexOf('  var ' + module + ' = {');
  const start = source.indexOf('    ' + name + ': function', offset);
  const end = source.indexOf('\n    },', start);
  assert(start > offset && end > start);
  return source.slice(start, end + 7);
}
function harness(module) {
  const requests = [], renders = [], pages = [];
  const box = {innerHTML:''};
  const context = vm.createContext({JSON, Set, Promise, Number, Date,
    document:{querySelector(){return box;}}, esc:String, toast(){}, renderActions(){},
    CRMWorkspace:{pager(box,data,next){pages.push({data,next});}},
    post(action,data){let resolve,reject;const promise=new Promise((yes,no)=>{resolve=yes;reject=no;});requests.push({action,data,resolve,reject});return promise;}});
  const target = vm.runInContext('({' + method(module,'load') + '})', context);
  Object.assign(target,{view:'my',q:'',keyword:'',filters(){return {keyword:this.keyword};},
    render(data){renders.push(data);},renderTasks(data){renders.push(data);},loadDetail(){},loadSamples(){}});
  return {target,requests,renders,pages,box};
}
(async()=>{
  for(const module of ['TaskCenterModule','VisitModule']) {
    const h=harness(module), m=h.target;
    const old=m.load(); m.q='new'; m.keyword='new'; const fresh=m.load();
    h.requests[1].resolve({success:true,data:{rows:[{id:202}],page:1,has_more:true}}); await fresh;
    h.requests[0].resolve({success:true,data:{rows:[{id:101}],page:1}}); await old;
    assert.equal(m.rows[0].id,202,module+': old result won');
    assert.equal(h.renders.length,1); assert.equal(h.requests[1].data.page_size,50);
    h.pages[0].next(2); assert.equal(h.requests[2].data.page,2);
    m.q='changed';m.keyword='changed';const changed=m.load();assert.equal(h.requests[3].data.page,1);
    h.requests[3].resolve({success:true,data:{rows:[],page:1}});await changed;
    h.requests[2].reject(new Error('stale error'));await Promise.resolve();await Promise.resolve();
    assert(!h.box.innerHTML.includes('stale error'),module+': old failure overwrote new results');
  }
  const requests=[], c=vm.createContext({Date:{now(){return c.now;}},now:100000,current:'customers',Promise,
    document:{hidden:false},post(action,data){requests.push(data);return Promise.resolve({});}});
  const start=source.indexOf('  var onlineHeartbeatAt =');
  const end=source.indexOf('  function sendOnlineLeave',start);
  vm.runInContext(source.slice(start,end),c);
  await c.sendOnlineHeartbeat();await c.sendOnlineHeartbeat();assert.equal(requests.length,1);
  c.now+=30001;await c.sendOnlineHeartbeat();assert.equal(requests.length,2);
  c.current='mail';await c.sendOnlineHeartbeat();assert.equal(requests.length,3);
  c.document.hidden=true;c.now+=60000;await c.sendOnlineHeartbeat();assert.equal(requests.length,3);
  console.log('CRM workspace original-method races, paging and heartbeat: OK');
})().catch(error=>{console.error(error);process.exitCode=1;});
