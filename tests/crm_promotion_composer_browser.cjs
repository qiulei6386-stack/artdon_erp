'use strict';
// Offline browser check: all requests fail closed; never visits the live CRM.
const fs = require('node:fs');
const path = require('node:path');
const os = require('node:os');
const assert = require('node:assert/strict');
const { chromium } = require(process.env.PLAYWRIGHT_MODULE || 'playwright');
const root = path.resolve(__dirname,'..');
const source = fs.readFileSync(path.join(root,'assets/crm/crm.js'),'utf8');
const start = source.indexOf('  var PromotionModule = {');
const end = source.indexOf('  if (window.CrmPromotionComposer)',start);
assert(start>0 && end>start);
(async()=>{
  const output=fs.mkdtempSync(path.join(os.tmpdir(),'crm-promotion-ui-'));
  const browser=await chromium.launch({headless:true,executablePath:process.env.CHROME_BIN || undefined});
  const page=await browser.newPage();
  const errors=[];page.on('pageerror',e=>errors.push(e.message));
  await page.route('**/*',route=>route.abort());
  await page.setContent('<!doctype html><html><head><meta name="viewport" content="width=device-width,initial-scale=1"></head><body class="is-promo-task-editor-open"><section class="crm-module active" data-crm-module="promotion"><div data-promo-wizard-host></div></section></body></html>');
  for(const name of ['crm.css','workspace.css','promotion-composer.css'])await page.addStyleTag({content:fs.readFileSync(path.join(root,'assets/crm',name),'utf8')});
  await page.addScriptTag({content:`
    var state={user:{id:1},csrf:'offline'},current='promotion';
    var readLocalJson=(key,fallback)=>fallback,hashParts=()=>({module:'promotion'});
    var esc=v=>String(v==null?'':v).replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    var cnChannel=x=>x,cnStatus=x=>x,renderActions=()=>{},toast=()=>{};
    var debounce=f=>f;
    var MailModule={prepareRichHtml:x=>String(x||''),cleanRichHtml:e=>e.innerHTML,fileSizeText:n=>n+' bytes',bindRichEditor:()=>{},bindRichToolbar:()=>{},execRichCommand:()=>{},fileKey:f=>f.name};
    ${source.slice(start,end)}
    window.fixturePromotion=PromotionModule;
  `});
  await page.addScriptTag({content:fs.readFileSync(path.join(root,'assets/crm/promotion-composer.js'),'utf8')});
  await page.evaluate(()=>{
    const p=window.fixturePromotion;
    p.data={pool:[{id:1,customer_name:'验收示例客户 · 很长的公司名称用于测试换行',country:'CN',owner_user_id:1}],contacts:[],groups:[],channels:[],templates:[],users:[],mail_accounts:[{id:1,email_address:'long-sender-address@example.invalid'}]};
    p.selectedCustomerIds.add(1);
    window.calls=[];
    window.api=CrmPromotionComposer.install(p,{esc,mail:MailModule,state,toast,post:async(action,payload)=>{calls.push({action,payload});if(action==='marketing_pool_view')return {success:true,data:{pool:[{id:2,customer_name:'搜索验收客户',country:'CN'}]}};if(action==='marketing_task_create')return {success:true,data:{task_id:123}};return {success:false,message:'离线模拟失败'};}});
    p.wizardDraft=Object.assign(p.defaultWizardDraft(),{task_name:'九月新品推广 · 标题很长也应清晰换行',mail_subject:'新品介绍给 {company_name}',mail_body_html:'<p>尊敬的客户：</p><p>这是一段用于测试显示的正文。</p>',customer_ids:[1],audience_customer_ids:[1],assets:[{id:'a'.repeat(32),name:'这是一个非常长的产品规格及附件文件名称_测试文档.pdf',size:1200}]});
    window.makePreview=()=>({token:'offline',manifest:{items:Array.from({length:52},(_,i)=>({mode:'email',customer_name:'测试公司名称很长 '+i,contact_name:'测试联系人',receiver_email:'very-long-recipient-address-'+i+'@example.invalid',sender_email:'sender@example.invalid',subject:'测试邮件主题',planned_at:'2026-09-08 09:00:00',body_html:'<p>你好，测试联系人。</p><p>这是最终邮件正文。</p><div>测试签名<br>sender@example.invalid</div>'})),excluded:[{customer_name:'排除测试',reason:'渠道未维护'}],attachments:[{name:'附件文件.pdf'}]}});
  });
  const timings=[];
  for(const width of [360,390,768,1024,1440]){
    await page.setViewportSize({width,height:900});
    for(let step=0;step<5;step++){
      const timing=await page.evaluate(step=>{api.state.step=step;api.state.error=step===1?'测试长报错：客户资料需要补充，请检查推广渠道和联系人邮箱。':'';api.state.preview=step===4?makePreview():null;const t=performance.now();fixturePromotion.renderWizard();return performance.now()-t;},step);
      timings.push(timing);
      const metrics=await page.evaluate(()=>{const r=document.querySelector('.pc-footer').getBoundingClientRect();return {width:innerWidth,doc:document.documentElement.scrollWidth,foot:r.bottom,buttons:[...document.querySelectorAll('.pc-footer button')].map(b=>({width:b.getBoundingClientRect().width,height:b.getBoundingClientRect().height})),main:document.querySelector('.pc-main').clientHeight};});
      assert(metrics.doc<=width+1,`overflow ${width}/${step}: ${JSON.stringify(metrics)}`);
      assert(metrics.foot<=901 && metrics.main>200,`footer/body ${width}/${step}: ${JSON.stringify(metrics)}`);
      assert(metrics.buttons.every(b=>b.width>=80 && b.height>=40),`compressed buttons ${width}/${step}`);
      if(step===2 || step===4){await page.waitForTimeout(170);await page.screenshot({path:path.join(output,`${width}-step${step+1}.png`)});}
    }
  }
  // Direct customer picking stays within the draft, not the unrelated pool selection.
  await page.evaluate(()=>{
    window.originalRefresh=fixturePromotion.refreshWizardAudience;
    fixturePromotion.refreshWizardAudience=function(){this.wizardDraft.audience_customer_ids=this.wizardDraft.customer_ids.slice();this.renderWizard();return Promise.resolve();};
    fixturePromotion.selectedCustomerIds=new Set([999]);
    api.state.step=1;api.state.error='';fixturePromotion.renderWizard();
  });
  assert.deepEqual(await page.evaluate(()=>fixturePromotion.collectWizard().customer_ids),[1]);
  await page.locator('[data-pc-customer-query]').fill('搜索验收');
  await page.locator('[data-pc-customer-search]').click();
  await page.locator('[data-pc-customer-add="2"]').click();
  assert.deepEqual(await page.evaluate(()=>fixturePromotion.wizardDraft.customer_ids),[1,2]);
  await page.locator('[data-pc-customer-remove="1"]').click();
  await page.locator('[data-pc-customer-remove="2"]').click();
  assert.deepEqual(await page.evaluate(()=>fixturePromotion.collectWizard().customer_ids),[]);
  assert.equal(await page.evaluate(()=>api.validation(1,fixturePromotion.wizardDraft)),'请先选择客户或客户分组。');
  await page.evaluate(()=>{fixturePromotion.refreshWizardAudience=originalRefresh;fixturePromotion.wizardDraft.customer_ids=[1];fixturePromotion.wizardDraft.audience_customer_ids=[1];});
  // Actual collection, failure recovery and double-click protection, with fake transport only.
  await page.evaluate(()=>{api.state.step=0;api.state.preview=null;fixturePromotion.renderWizard();});
  await page.locator('[data-wizard-field="task_name"]').fill('已修改的推广名称');
  assert.equal(await page.evaluate(()=>fixturePromotion.collectWizard().task_name),'已修改的推广名称');
  await page.evaluate(async()=>{await Promise.all([api.generatePreview(),api.generatePreview()]);});
  const saved=await page.evaluate(()=>({id:fixturePromotion.wizardDraft.task_id,calls,err:api.state.error}));
  assert.equal(saved.id,123);assert.equal(saved.calls.filter(c=>c.action==='marketing_task_create').length,1);assert.equal(saved.err,'离线模拟失败');
  await page.evaluate(async()=>{await api.generatePreview();});
  assert.equal(await page.evaluate(()=>calls.filter(c=>c.action==='marketing_task_create')[1].payload.task_id),123);
  await page.evaluate(()=>{
    const old=fixturePromotion.taskToWizardDraft({id:55,task_status:'draft',attachment_config_json:JSON.stringify({manual_attachments:[{name:'old.pdf'}]})});
    if(!old.legacyAttachmentWarning)throw new Error('Legacy attachment warning missing');
    const payload=fixturePromotion.wizardTaskPayload(old,{customers:[],contacts:[],chat_groups:[],skipped:[]},'draft');
    if(JSON.parse(payload.attachment_config).manual_attachments[0].name!=='old.pdf')throw new Error('Unacknowledged legacy attachments lost');
  });
  await page.emulateMedia({reducedMotion:'reduce'});
  assert.equal(await page.locator('.pc-content').evaluate(el=>getComputedStyle(el).animationName),'none');
  await page.setViewportSize({width:1024,height:720});
  await page.evaluate(()=>{document.documentElement.style.zoom='1.25';api.state.step=4;api.state.preview=makePreview();fixturePromotion.renderWizard();});
  await page.screenshot({path:path.join(output,'zoom125.png')});
  assert.equal(errors.length,0,errors.join('\n'));
  console.log(JSON.stringify({passed:true,layouts:25,renderMaxMs:Math.max(...timings),output,requests:'all simulated, no live network'}));
  await browser.close();
})().catch(e=>{console.error(e);process.exit(1);});
