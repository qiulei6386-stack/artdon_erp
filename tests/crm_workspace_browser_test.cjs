'use strict';
// Offline component check using shipped CSS/JS, never a customer session.
const fs = require('node:fs'), path = require('node:path'), os = require('node:os');
const assert = require('node:assert/strict');
const {chromium} = require(process.env.CRM_PLAYWRIGHT_MODULE || path.join(os.homedir(), '.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright'));
const root = path.join(__dirname, '..');
(async () => {
  const browser = await chromium.launch({headless:true, executablePath:process.env.CRM_CHROME_PATH || '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome', args:['--disable-background-networking','--disable-sync']});
  try {
    const context = await browser.newContext({serviceWorkers:'block'});
    const requests=[]; await context.route('**/*', r=>{requests.push(r.request().url());return r.abort();});await context.setOffline(true);
    const page=await context.newPage(), errors=[];page.on('pageerror', e=>errors.push(e.message));
    await page.setContent('<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta http-equiv="Content-Security-Policy" content="default-src \'none\'; style-src \'unsafe-inline\'; script-src \'unsafe-inline\'"><style>:root{--crm-text:#152238;--crm-card-bg:#fff;--crm-border:#bcc5d1;--crm-muted-text:#59687d;--crm-primary:#a71927}body{margin:16px;font:16px system-ui}#actions{width:220px}button{padding:8px;display:block}</style><h1>CRM 操作与分页离线验收</h1><section id="actions"></section><div id="list"></div>');
    await page.addStyleTag({content:fs.readFileSync(path.join(root,'assets/crm/workspace.css'),'utf8')});
    await page.addScriptTag({content:fs.readFileSync(path.join(root,'assets/crm/workspace.js'),'utf8')});
    await page.evaluate(()=>{
      window.draw=()=>{const box=document.querySelector('#actions');box.innerHTML='';const buttons=['新建','编辑','刷新','导出','删除'].map((s,i)=>{const b=document.createElement('button');b.textContent=s;if(i===4)b.className='danger';return b;});CRMWorkspace.actionGroup(box,'test','操作',buttons);}; draw();
      window.calls=[];CRMWorkspace.pager(document.querySelector('#list'),{page:2,rows:[{id:1}],has_more:true}, n=>{calls.push(n);return new Promise((resolve,reject)=>{window.resolvePage=resolve;window.rejectPage=reject;});});
    });
    assert.equal(await page.locator('#actions > button').count(),3);
    assert.equal(await page.getByRole('button',{name:'删除',exact:true}).isVisible(),false);
    await page.locator('summary').focus();await page.keyboard.press('Enter');
    assert.equal(await page.getByRole('button',{name:'删除',exact:true}).isVisible(),true);
    await page.evaluate(()=>draw());assert.equal(await page.locator('details').getAttribute('open'),'');
    await page.getByRole('button',{name:'下一页',exact:true}).click();
    assert.equal(await page.getByRole('button',{name:'上一页',exact:true}).isDisabled(),true);
    assert.deepEqual(await page.evaluate(()=>calls),[3]);
    await page.evaluate(()=>rejectPage(new Error('offline fixture')));
    await page.waitForFunction(()=>!document.querySelector('[data-workspace-pager] button').disabled);
    await page.evaluate(()=>CRMWorkspace.pager(document.querySelector('#list'),{page:1,rows:[],has_more:false},()=>{}));
    assert.equal(await page.locator('[data-workspace-pager]').count(),1);
    assert.equal(await page.getByRole('button',{name:'下一页',exact:true}).isDisabled(),true);
    assert.equal(await page.evaluate(()=>CRMWorkspace.actionAvailable('customers','登记收款')),false);
    assert.equal(await page.evaluate(()=>CRMWorkspace.actionAvailable('tasks','创建派工')),true);
    const artifacts=fs.mkdtempSync(path.join(os.tmpdir(),'crm-workspace-browser-'));
    for(const width of [390,1200]) {
      await page.setViewportSize({width,height:850});
      assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),true,'Horizontal overflow');
      await page.screenshot({path:path.join(artifacts,'workspace-'+width+'.png')});
    }
    assert.deepEqual(errors,[]);assert.deepEqual(requests,[]);
    console.log(JSON.stringify({result:'PASS',checks:['three primary buttons','danger under More','keyboard open','open state retained','paging pending lock','failure unlock','one pager','empty page','placeholder hidden','real route retained','mobile/desktop no overflow'],artifacts,network_attempts:requests.length},null,2));
  } finally {await browser.close();}
})().catch(e=>{console.error(e);process.exitCode=1;});
