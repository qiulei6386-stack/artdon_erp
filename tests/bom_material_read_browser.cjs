const fs=require('node:fs'),path=require('node:path'),assert=require('node:assert/strict'),vm=require('node:vm');
const root=path.resolve(__dirname,'..'),source=fs.readFileSync(path.join(root,'bom.php'),'utf8');new vm.Script(source.match(/<script>([\s\S]*?)<\/script>/)[1]);
if(process.env.BOM_BROWSER_TEST!=='1'){console.log('BOM material syntax passed; browser opt-in BOM_BROWSER_TEST=1');process.exit(0);}
const {chromium}=require('playwright');
(async()=>{
 const browser=await chromium.launch({headless:true,executablePath:process.env.CHROME_BIN});
 try{for(const width of [1280,768,390]){
  const page=await browser.newPage({viewport:{width,height:1000}});const errors=[],calls=[];let fail=false,delay=false,release;
  page.on('pageerror',e=>errors.push(e.message));page.on('dialog',d=>d.dismiss());
  const data=Array.from({length:53},(_,i)=>({id:i+1,category:'电源',brand:'Test',name:'Synthetic '+(i+1),model:'M-'+(i+1),spec:'test',price:7,unit:'PCS',supplier:'Test',keyword:'',has_image:true}));
  await page.route('**/*',async route=>{
   const u=new URL(route.request().url());if(u.pathname==='/bom.php')return route.fulfill({contentType:'text/html',body:source.replace(/<\?php[\s\S]*?\?>/g,'')});
   if(u.pathname==='/assets/bom-dashboard-read.js')return route.fulfill({contentType:'text/javascript',body:fs.readFileSync(path.join(root,u.pathname),'utf8')});
   if(u.pathname!=='/bom_api.php')return route.abort();const action=u.searchParams.get('action'),d=route.request().postDataJSON();calls.push({action,d});let r={ok:true};
   const can={dashboard:true,edit:true,materials:true,cost_view:true,supplier_view:true};
   if(action==='me')r={ok:true,login:true,user:{username:'test'},can};
   else if(action==='bootstrap')r={ok:true,user:{username:'test'},can,projects:[],lists:{categories:['电源'],brands:['Test'],suppliers:['Test']}};
   else if(action==='materials_list'){
    if(delay&&d.keyword==='old'){delay=false;await new Promise(resolve=>release=resolve);}
    if(fail){fail=false;return route.fulfill({status:500,body:''});}
    const arr=data.filter(m=>!d.keyword||m.name.includes(d.keyword)),size=d.page_size||50,pages=Math.max(1,Math.ceil(arr.length/size)),p=Math.max(1,Math.min(pages,d.page||1));r={ok:true,materials:arr.slice((p-1)*size,p*size),total:arr.length,page:p,pages,page_size:size};
   }else if(action==='material_image')r={ok:true,id:d.id,image:''};
   else if(action==='save_material'){assert(d.image_unchanged);assert(!Object.hasOwn(d,'image'));r={ok:true,id:d.id};}
   else throw Error('Unexpected '+action);
   return route.fulfill({contentType:'application/json',body:JSON.stringify(r)});
  });
  await page.goto('http://bom.test/bom.php');await page.waitForFunction(()=>!firstBoot);
  assert.equal(calls.filter(c=>c.action==='materials_list').length,0,'No catalog on homepage');
  await page.evaluate(()=>{materialPageSize=20;showPage('materials');});await page.waitForFunction(()=>materialPageRows.length===20);
  assert.equal(await page.locator('#materialsTbody tr').count(),20);assert(calls.filter(c=>c.action==='material_image').length<53);
  await page.evaluate(()=>setMaterialPage(2));await page.waitForFunction(()=>materialPage===2&&materialPageRows[0].id===21);
  await page.locator('#matSearch').fill('Synthetic 53');await page.waitForFunction(()=>materialPageRows.length===1&&materialPageRows[0].id===53);
  await page.evaluate(()=>openMaterialEditor(53));await page.locator('#matName').fill('Synthetic Edited');await page.evaluate(()=>saveMaterial());
  assert(calls.some(c=>c.action==='save_material'&&c.d.id===53));
  fail=true;await page.locator('#matSearch').fill('missing');await page.waitForFunction(()=>document.querySelector('#materialsTbody').textContent.includes('HTTP 500'));await page.locator('#materialsTbody button').click();await page.waitForFunction(()=>document.querySelector('#materialsTbody').textContent.includes('没有符合'));
  delay=true;await page.locator('#matSearch').fill('old');while(!release)await page.waitForTimeout(30);await page.locator('#matSearch').fill('Synthetic 1');await page.waitForFunction(()=>materialPageRows.length>0);release();await page.waitForTimeout(300);assert.equal(await page.locator('#matSearch').inputValue(),'Synthetic 1');assert(await page.evaluate(()=>materialPageRows.every(m=>m.name.includes('Synthetic 1'))));
  await page.evaluate(async()=>{await ensureMaterialsLoaded();});assert.equal(await page.evaluate(()=>materials.length),53,'Picker still covers entire metadata catalog');assert.equal(errors.length,0,errors.join('\n'));await page.close();console.log('BOM synthetic material page passed width='+width);
 }}finally{await browser.close();}
})().catch(e=>{console.error(e);process.exit(1);});
