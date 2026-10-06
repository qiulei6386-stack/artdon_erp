// Real HTML and handlers, entirely intercepted synthetic transport. No live browser/session.
const fs=require('node:fs'),path=require('node:path'),assert=require('node:assert/strict');
if(process.env.BOM_BROWSER_TEST!=='1'){console.log('Opt-in synthetic browser test: BOM_BROWSER_TEST=1');process.exit(0);}
const {chromium}=require(process.env.PLAYWRIGHT_MODULE||'playwright');
const root=path.resolve(__dirname,'..');
(async()=>{
 const browser=await chromium.launch({headless:true,executablePath:process.env.CHROME_BIN||undefined});
 try{
  const page=await browser.newPage({viewport:{width:1440,height:1000}}),errors=[],requests=[];
  let withdrawReason=null;
  page.on('pageerror',e=>errors.push(e.message));page.on('dialog',async d=>{if(d.type()==='prompt'&&withdrawReason!==null)await d.accept(withdrawReason);else await d.dismiss()});
  const base={product_type:'',customer:'',currency:'RMB',product_image:'',labor:0,other:0,profit_rate:0,exchange_rate:1,quote_mode:'markup',version_no:'V1',variant_label:'通用版',review_status:'draft',revision:'v1',created_at:'2026-09-09 00:00:00',updated_at:'2026-09-09 00:00:00'};
  const data={A:{...base,project_uid:'A',name:'Synthetic A',model:'TEST-A',rows:[{name:'Synthetic LED',spec:'A',qty:2,price:12,priceStatus:'confirmed'}]},B:{...base,project_uid:'B',name:'Synthetic B',model:'TEST-B',rows:[{name:'Synthetic B item',qty:1,price:7}]}};
  let delayB=false,saveDelay=false,releaseB,releaseSave;
  const can={dashboard:true,edit:true,cost_view:true,approve_bom:true,reject_bom:true,unapprove_bom:true};
  await page.route('**/*',async route=>{
   const url=new URL(route.request().url());
   if(url.pathname==='/bom.php')return route.fulfill({contentType:'text/html',body:fs.readFileSync(path.join(root,'bom.php'),'utf8').replace(/<\?php[\s\S]*?\?>/g,'')});
   if(['/assets/bom-dashboard-read.js','/assets/bom-lifecycle.js'].includes(url.pathname))return route.fulfill({contentType:'text/javascript',body:fs.readFileSync(path.join(root,url.pathname),'utf8')});
   if(url.pathname!=='/bom_api.php')return route.abort();
   const action=url.searchParams.get('action'),d=route.request().postDataJSON();requests.push({action,d});let r={ok:true};
   if(action==='me')r={ok:true,login:true,user:{username:'synthetic'},can};
   else if(action==='bootstrap')r={ok:true,user:{username:'synthetic'},can,projects:Object.values(data).map(({rows,...p})=>({...p,row_count:rows.length})),lists:{}};
   else if(action==='dashboard_images')r={ok:true,images:[]};
   else if(action==='project_detail'){if(d.project_uid==='B'&&delayB)await new Promise(resolve=>releaseB=resolve);r={ok:true,project:structuredClone(data[d.project_uid])};}
   else if(action==='save_project'){
    assert(d.request_id);assert.equal(d.expected_revision,data[d.project_uid].revision);
    if(saveDelay)await new Promise(resolve=>releaseSave=resolve);
    data[d.project_uid]={...data[d.project_uid],...d,revision:data[d.project_uid].revision+'x'};
    r={ok:true,project:structuredClone(data[d.project_uid])};
   }else if(action==='withdraw_review'){
    assert.equal(d.review_note,'Correct quantity');assert.equal(d.expected_revision,data[d.project_uid].revision);
    data[d.project_uid]={...data[d.project_uid],review_status:'draft',can_withdraw_review:false,revision:data[d.project_uid].revision+'w'};r={ok:true,project:structuredClone(data[d.project_uid])};
   }else if(action==='price_history')r={ok:true,total:1,page:1,pages:1,events:[{created_at:'2026-10-06 10:00:00',actor:'Operator',actor_account:'users:701:alice',field_name:'price',old_price:7,new_price:9,delta:2,reason:'Supplier adjustment <img src=x>',source:'save_material',batch_id:'test',row_no:null}]};
   else if(action==='material_where_used')r={ok:true,usages:[{project_uid:'B',name:'Synthetic B',model:'TEST-B',review_status:'preliminary',version_no:'V1',row_no:1,qty:1,price:9,binding_status:'exact'}],text_candidates:[{project_uid:'A',name:'Text candidate',model:'TEST-A',review_status:'draft',version_no:'V1',row_no:1,binding_status:'text_candidate'}]};
   else throw Error('Unexpected request: '+action);
   await route.fulfill({contentType:'application/json',body:JSON.stringify(r)});
  });
  await page.goto('http://bom.test/bom.php');await page.waitForFunction(()=>projects.length===2);
  await page.evaluate(async()=>{showPage('edit');await loadProject('A')});await page.waitForFunction(()=>bomEditorReady());
  assert.equal(await page.locator('#profitRate').inputValue(),'0');assert.equal(await page.locator('#grandTotal').textContent(),'24.00');
  const before=requests.length;await page.evaluate(()=>submitBomReview());assert.equal(requests.length,before,'cancel review sends no save or submission');
  delayB=true;await page.evaluate(()=>{loadProject('B')});await page.waitForFunction(()=>currentId==='B'&&!bomEditorReady());assert(await page.locator('#bomSaveBtn').isDisabled());
  const writes=requests.filter(x=>x.action==='save_project').length;await page.evaluate(()=>saveCurrent());assert.equal(requests.filter(x=>x.action==='save_project').length,writes);
  releaseB();await page.waitForFunction(()=>bomEditorReady());assert.equal(await page.locator('#projectName').inputValue(),'Synthetic B');
  await page.evaluate(()=>loadProject('A'));await page.locator('#projectName').fill('Synthetic A saved');
  saveDelay=true;await page.evaluate(()=>{saveCurrent()});await page.waitForFunction(()=>bomWriteBusy);assert(await page.locator('#projectName').isDisabled());
  await page.evaluate(()=>{loadProject('B');newProject();showPage('dashboard')});assert.equal(await page.evaluate(()=>currentId),'A');
  releaseSave();await page.waitForFunction(()=>!bomWriteBusy&&getCurrent().revision==='v1x');assert.equal(data.A.name,'Synthetic A saved');assert.equal(data.B.name,'Synthetic B');
  await page.evaluate(()=>removeRow(0));assert.equal(await page.locator('#grandTotal').textContent(),'0.00');
  for(const status of ['pending','approved']){
   await page.evaluate(status=>{getCurrent().reviewStatus=status;updateBomWorkflowUI()},status);
   assert(await page.locator('#bomSaveBtn').isDisabled());assert(await page.locator('#projectName').isDisabled());
   assert(await page.locator('#search').isEnabled(),'review lock must not disable navigation search');
   await page.locator('#search').fill('Synthetic B');assert.equal(await page.locator('#search').inputValue(),'Synthetic B');
  }
  await page.evaluate(()=>loadProject('B'));assert((await page.locator('#status').textContent()).includes('BOM 明细已读取'));
  delayB=false;data.B.review_status='pending';data.B.can_withdraw_review=true;
  await page.evaluate(async()=>{projects.find(p=>p.id==='B').rowsLoaded=false;await loadProject('B')});
  assert(await page.locator('#bomWithdrawBtn').isEnabled());assert(await page.locator('#bomSaveBtn').isDisabled());
  await page.locator('#bomWithdrawBtn').click();assert.equal(requests.filter(x=>x.action==='withdraw_review').length,0,'cancel is read-only');
  withdrawReason='Correct quantity';await page.locator('#bomWithdrawBtn').click();await page.waitForFunction(()=>!bomWriteBusy&&getCurrent().reviewStatus==='draft');
  assert(await page.locator('#bomSaveBtn').isEnabled());assert(await page.locator('#bomSubmitBtn').isEnabled());assert(await page.locator('#bomWithdrawBtn').isHidden());
  for(const width of [390,768,1440]){await page.setViewportSize({width,height:900});await page.evaluate(()=>{getCurrent().reviewStatus='pending';getCurrent().canWithdrawReview=true;updateBomWorkflowUI()});await page.locator('#bomWithdrawBtn').scrollIntoViewIfNeeded();assert(await page.locator('#bomWithdrawBtn').isVisible());}
  saveDelay=false;withdrawReason='Supplier adjustment';data.B.review_status='preliminary';data.B.lifecycle_v2=true;
  data.B.current_reference={cost:9,stored_cost:7,approval_cost:null,approval_delta:null,pending_prices:1,material_prices:[{row_no:0,material_id:1,stored_price:7,current_price:9,delta:2,source:'material_standard',price_version:1}]};
  await page.evaluate(async()=>{projects.find(p=>p.id==='B').rowsLoaded=false;await loadProject('B')});
  assert(await page.locator('#bomSaveBtn').isEnabled());assert(await page.locator('#bomApproveBtn').isEnabled());assert(await page.locator('#bomSubmitBtn').isDisabled());
  assert((await page.locator('#bomReviewBar').textContent()).includes('当前参考成本'));
  await page.locator('#tbody .price').fill('9');await page.evaluate(()=>saveCurrent());await page.waitForFunction(()=>!bomWriteBusy);
  assert.equal(requests.filter(r=>r.action==='save_project').at(-1).d.price_reason,'Supplier adjustment','Price edits carry a mandatory reason despite collect() on input');
  await page.evaluate(()=>BomLifecycle.history(1));await page.waitForFunction(()=>document.querySelector('dialog')?.textContent.includes('alice'));
  assert.equal(await page.locator('dialog img').count(),0,'History text escaped');await page.locator('dialog [data-close]').click();
  await page.evaluate(()=>BomLifecycle.used(1));await page.waitForFunction(()=>document.querySelector('dialog')?.textContent.includes('文字候选'));
  assert.equal(await page.locator('dialog [data-bom]').count(),2);await page.locator('dialog [data-close]').click();
  await page.evaluate(()=>{getCurrent().reviewStatus='approved';updateBomWorkflowUI()});assert(await page.locator('#tbody .price').isDisabled());assert(await page.locator('#bomUnapproveBtn').isEnabled());
  assert.deepEqual(errors,[]);console.log('Real-page BOM workflow: zero profit, correct total, cancel, loading/save lock, cross-BOM guard, success reload, delete-all total OK');
 }finally{await browser.close()}
})().catch(e=>{console.error(e);process.exitCode=1});
