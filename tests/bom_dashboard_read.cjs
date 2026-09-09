const fs=require('node:fs'),path=require('node:path'),assert=require('node:assert/strict'),vm=require('node:vm');
const root=path.resolve(__dirname,'..'),pageSource=fs.readFileSync(path.join(root,'bom.php'),'utf8'),helper=fs.readFileSync(path.join(root,'assets/bom-dashboard-read.js'),'utf8');
const inline=pageSource.match(/<script>([\s\S]*?)<\/script>/)[1];
new vm.Script(inline);new vm.Script(helper);
assert(pageSource.includes('物料 ${bomProjectRowCount(p)}'));
assert(pageSource.includes("if(keepPage==='edit')loadProject(keepId)"));
assert(!pageSource.match(/function dashboardFilteredProjects[^\n]*JSON\.stringify\(p\.rows/));
console.log('BOM dashboard script syntax and lightweight contracts passed.');
if(process.env.BOM_BROWSER_TEST==='1')(async()=>{
  const {chromium}=require(process.env.PLAYWRIGHT_MODULE||'playwright');
  const browser=await chromium.launch({headless:true,executablePath:process.env.CHROME_BIN||undefined});
  try {
    const page=await browser.newPage({viewport:{width:1600,height:1000}}),requests=[],errors=[];
    page.on('pageerror',e=>errors.push(e.message));page.on('dialog',async d=>{errors.push(d.message());await d.dismiss();});
    const today=new Date(),now=`${today.getFullYear()}-${String(today.getMonth()+1).padStart(2,'0')}-02 10:00:00`;
    const rows=Array.from({length:45},(_,i)=>({project_uid:'TEST-'+i,name:'BOM Sample '+i,model:'MODEL-'+i,customer:i%2?'Test B':'Test A',product_type:'Test series',created_at:i===44?'2020-01-01 00:00:00':now,updated_at:i===44?'2020-01-01 00:00:00':now,row_count:8,totals_summary:{total:12,suggest:16},currency:'RMB'}));
    let failSearch=false,failImages=false;
    await page.addInitScript(()=>localStorage.setItem('bom_last_project_v762','TEST-0'));
    await page.route('**/*',async route=>{
      const u=new URL(route.request().url());
      if(u.pathname==='/bom.php')return route.fulfill({contentType:'text/html',body:pageSource.replace(/<\?php[\s\S]*?\?>/g,'')});
      if(u.pathname==='/assets/bom-dashboard-read.js')return route.fulfill({contentType:'application/javascript',body:helper});
      if(u.pathname.startsWith('/images/'))return route.fulfill({contentType:'image/svg+xml',body:'<svg xmlns="http://www.w3.org/2000/svg" width="100" height="100"><rect width="100" height="100" fill="#dbeafe"/></svg>'});
      if(u.pathname!=='/bom_api.php')return route.abort();
      const a=u.searchParams.get('action'),d=route.request().postDataJSON()||{};requests.push({a,d});let r={ok:true};
      if(a==='me')r={ok:true,login:true,user:{username:'test',role:'admin'},can:{dashboard:true,edit:true}};
      else if(a==='bootstrap')r={ok:true,projects:rows,lists:{},user:{username:'test',role:'admin'}};
      else if(a==='dashboard_images'){assert(d.project_ids.length<=18);r=failImages?{ok:false}: {ok:true,images:d.project_ids.map(id=>({project_uid:id,url:'/images/'+id+'.svg',status:'ready'}))};}
      else if(a==='dashboard_search'){
        if(d.keyword==='slow')await new Promise(resolve=>setTimeout(resolve,600));
        r=failSearch?{ok:false}:{ok:true,project_ids:d.keyword==='铝材'?['TEST-30']:d.keyword==='old'?['TEST-44']:d.keyword==='slow'?['TEST-10']:d.keyword==='fast'?['TEST-20']:rows.filter(p=>[p.name,p.model,p.customer].join(' ').toLowerCase().includes(d.keyword)).map(p=>p.project_uid)};
      }else throw new Error('Unexpected heavy/write request: '+a);
      return route.fulfill({contentType:'application/json',body:JSON.stringify(r)});
    });
    await page.goto('http://bom.test/bom.php');
    await page.waitForFunction(()=>document.querySelectorAll('.dash-bom-image img').length===18);
    assert((await page.locator('.dash-bom-meta').first().textContent()).includes('物料 8'));
    assert.equal(requests.filter(r=>r.a==='dashboard_images').length,1,'Only visible page enrichment');
    await page.getByRole('button',{name:'下一页',exact:true}).click();
    assert((await page.locator('#dashPagerTop').textContent()).includes('第 2/'));
    await page.locator('#dashKeyword').fill('铝材');
    await page.waitForFunction(()=>bomDashboardRead.state==='ready');
    assert.equal(await page.locator('.dash-bom-card').count(),1);assert((await page.locator('.dash-bom-title').textContent()).includes('Sample 30'));
    assert((await page.locator('#dashPagerTop').textContent()).includes('第 1/'));
    await page.locator('#dashKeyword').fill('old');await page.waitForFunction(()=>bomDashboardRead.state==='ready'&&bomDashboardRead.keyword==='old');
    assert.equal(await page.locator('.dash-bom-card').count(),0);await page.locator('#dashSearchAll').click();assert.equal(await page.locator('.dash-bom-card').count(),1);
    await page.locator('#dashKeyword').fill('slow');await page.waitForTimeout(300);await page.locator('#dashKeyword').fill('fast');
    await page.waitForFunction(()=>bomDashboardRead.state==='ready'&&bomDashboardRead.keyword==='fast');await page.waitForTimeout(600);
    assert((await page.locator('.dash-bom-title').textContent()).includes('Sample 20'),'Old response cannot replace new search');
    failSearch=true;await page.locator('#dashKeyword').fill('error');await page.waitForFunction(()=>bomDashboardRead.state==='error');assert(await page.locator('#dashSearchRetry').isVisible());
    failSearch=false;await page.locator('#dashSearchRetry').click();await page.waitForFunction(()=>bomDashboardRead.state==='ready');
    await page.getByRole('button',{name:'清空筛选',exact:true}).click();assert.equal(await page.locator('#dashKeyword').inputValue(),'');
    await page.waitForFunction(()=>!bomDashboardRead.imageBusy);
    failImages=true;await page.evaluate(()=>{bomDashboardResetRead();renderDashboard();});await page.waitForFunction(()=>!bomDashboardRead.imageBusy);
    assert((await page.locator('.dash-bom-image').first().textContent()).includes('图片加载失败'));
    const before=requests.length;await page.waitForTimeout(500);assert.equal(requests.length,before,'No endless image retry loop');
    failImages=false;await page.evaluate(()=>{bomDashboardResetRead();renderDashboard();});await page.waitForFunction(()=>!bomDashboardRead.imageBusy);
    const output=fs.mkdtempSync(path.join(require('node:os').tmpdir(),'bom-dashboard-'));
    for(const width of [390,768,1600]){await page.setViewportSize({width,height:1000});await page.waitForTimeout(350);assert((await page.locator('.dash-bom-card').count())<=18);assert(await page.locator('#dashKeyword').isVisible());if(width<=900){const box=await page.locator('#dashKeyword').boundingBox();assert(box.width>280&&box.x+box.width<=width,'Small-screen search fits viewport');}await page.screenshot({path:path.join(output,width+'.png')});}
    console.log('Browser screenshots: '+output);
    assert.deepEqual(errors,[]);console.log(JSON.stringify({passed:true,requests:requests.length,checks:'real page, lazy first page, remembered detail not fetched, material search, page reset, old date, stale response, retry, image failure and 3 widths'}));
  }finally{await browser.close();}
})().catch(e=>{console.error(e);process.exitCode=1;});
