// Real HTML and handlers, entirely intercepted synthetic transport. No live browser/session.
const fs=require('node:fs'),path=require('node:path'),assert=require('node:assert/strict');
if(process.env.BOM_BROWSER_TEST!=='1'){console.log('Opt-in synthetic browser test: BOM_BROWSER_TEST=1');process.exit(0);}
const {chromium}=require(process.env.PLAYWRIGHT_MODULE||'playwright');
const root=path.resolve(__dirname,'..');
(async()=>{
 const browser=await chromium.launch({headless:true,executablePath:process.env.CHROME_BIN||undefined});
 try{
  const page=await browser.newPage({viewport:{width:1440,height:1000}}),errors=[],requests=[];
  page.on('pageerror',e=>errors.push(e.message));page.on('dialog',async d=>{await d.dismiss()});
  const base={product_type:'',customer:'',currency:'RMB',product_image:'',labor:0,other:0,profit_rate:0,exchange_rate:1,quote_mode:'markup',version_no:'V1',variant_label:'通用版',review_status:'draft',revision:'v1',created_at:'2026-09-09 00:00:00',updated_at:'2026-09-09 00:00:00'};
  const data={A:{...base,project_uid:'A',name:'Synthetic A',model:'TEST-A',rows:[{name:'Synthetic LED',spec:'A',qty:2,price:12,priceStatus:'confirmed'}]},B:{...base,project_uid:'B',name:'Synthetic B',model:'TEST-B',rows:[{name:'Synthetic B item',qty:1,price:7}]}};
  let delayB=false,saveDelay=false,releaseB,releaseSave;
  const can={dashboard:true,edit:true,cost_view:true,approve_bom:true,reject_bom:true,unapprove_bom:true};
  await page.route('**/*',async route=>{
   const url=new URL(route.request().url());
   if(url.pathname==='/bom.php')return route.fulfill({contentType:'text/html',body:fs.readFileSync(path.join(root,'bom.php'),'utf8').replace(/<\?php[\s\S]*?\?>/g,'')});
   if(url.pathname==='/assets/bom-dashboard-read.js')return route.fulfill({contentType:'text/javascript',body:fs.readFileSync(path.join(root,'assets/bom-dashboard-read.js'),'utf8')});
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
   }else throw Error('Unexpected request: '+action);
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
  assert.deepEqual(errors,[]);console.log('Real-page BOM workflow: zero profit, correct total, cancel, loading/save lock, cross-BOM guard, success reload, delete-all total OK');
 }finally{await browser.close()}
})().catch(e=>{console.error(e);process.exitCode=1});
