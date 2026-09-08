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
  await page.route('**/*',route=>route.request().url()==='https://crm-fixture.invalid/' ? route.fulfill({contentType:'text/html',body:'<!doctype html><html></html>'}) : route.abort());
  await page.goto('https://crm-fixture.invalid/');
  await page.setContent('<!doctype html><html><head><meta name="viewport" content="width=device-width,initial-scale=1"></head><body class="is-promo-task-editor-open"><section class="crm-module active" data-crm-module="promotion"><div data-promo-wizard-host></div></section></body></html>');
  for(const name of ['crm.css','workspace.css','promotion-composer.css'])await page.addStyleTag({content:fs.readFileSync(path.join(root,'assets/crm',name),'utf8')});
  await page.addScriptTag({content:`
    var state={user:{id:1},csrf:'offline'},current='promotion';
    var readLocalJson=(key,fallback)=>fallback,writeLocalJson=()=>{},hashParts=()=>({module:'promotion'});
    var esc=v=>String(v==null?'':v).replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    var cnChannel=x=>x,cnStatus=x=>x,renderActions=()=>{},toast=()=>{};
    var debounce=f=>f;
    ${source.slice(source.indexOf('  var MailModule = {'),start)}
    ${source.slice(start,end)}
    window.fixturePromotion=PromotionModule;
  `});
  await page.addScriptTag({content:fs.readFileSync(path.join(root,'assets/crm/promotion-composer.js'),'utf8')});
  await page.evaluate(()=>{
    const p=window.fixturePromotion;
    p.data={pool:[{id:1,customer_name:'验收示例客户 · 很长的公司名称用于测试换行',country:'CN',owner_user_id:1}],contacts:[],groups:[],channels:[],templates:[],users:[],mail_accounts:[{id:1,email_address:'long-sender-address@example.invalid'}]};
    p.selectedCustomerIds.add(1);
    window.calls=[];
    window.api=CrmPromotionComposer.install(p,{esc,mail:MailModule,state,toast,post:async(action,payload)=>{calls.push({action,payload});if(window.signatureReply && action==='marketing_signature_content')return signatureReply(payload);if(window.pagedReply && action==='marketing_delivery_preview_read')return {success:true,data:pagedReply(payload)};if(action==='marketing_pool_view')return {success:true,data:{pool:[{id:2,customer_name:'搜索验收客户',country:'CN'}]}};if(action==='marketing_task_create')return {success:true,data:{task_id:123}};return {success:false,message:'离线模拟失败'};}});
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
      assert.equal(await page.locator('[data-pc-step]:visible').count(),5,`All five steps remain visible at ${width}/${step}`);
      assert((await page.locator('[data-pc-guidance]').innerText()).length>0,'Every step has its own filling checklist');
      await page.locator('.pc-step-help summary').click();
      assert(await page.locator('.pc-step-help').evaluate(el=>el.open),'Step explanation opens inline');
      await page.locator('.pc-step-help summary').click();
      if(step===2 || step===4 || width===390 || width===1440){await page.waitForTimeout(170);await page.screenshot({path:path.join(output,`${width}-step${step+1}.png`)});}
    }
  }
  // Signature inspection is lazy/read-only, preserves the draft, and drops stale responses.
  await page.evaluate(()=>{api.state.step=2;api.state.preview=null;fixturePromotion.renderWizard();window.signatureDraft=structuredClone(fixturePromotion.wizardDraft);});
  assert.equal(await page.evaluate(()=>calls.length),0,'Opening composer never loads large signature HTML');
  await page.locator('[data-pc-signature-check]').click();
  assert((await page.locator('[data-pc-signature-result]').innerText()).includes('请先选择'));
  await page.locator('[data-pc-signature-account]').selectOption('1');
  await page.evaluate(()=>{window.signatureReply=async()=>({success:true,data:{ready:true,missing:[],recipient_variables:['{contact_name}'],preview_html:'<p>Sender &amp; Team / {contact_name}</p>'}});});
  await page.locator('[data-pc-signature-check]').click();
  await page.locator('.pc-signature-frame').waitFor();
  assert((await page.frameLocator('.pc-signature-frame').locator('body').innerText()).includes('Sender & Team'));
  assert.equal(await page.locator('.pc-signature-frame').getAttribute('sandbox'),'');
  assert.equal(await page.evaluate(()=>fixturePromotion.wizardDraft.mail_body_html),await page.evaluate(()=>signatureDraft.mail_body_html),'Checking never inserts or duplicates signature into body');
  assert.deepEqual(await page.evaluate(()=>fixturePromotion.wizardDraft.mail_account_ids),await page.evaluate(()=>signatureDraft.mail_account_ids),'Inspection mailbox does not change send allocation');
  await page.evaluate(()=>{window.signatureReply=()=>new Promise(resolve=>window.resolveSignature=resolve);});
  await page.locator('[data-pc-signature-check]').click();
  await page.locator('[data-wizard-field="signature_key"]').selectOption('none');
  await page.evaluate(()=>resolveSignature({success:true,data:{ready:true,missing:[],preview_html:'STALE'}}));
  assert.equal(await page.locator('.pc-signature-frame').count(),0,'Late response cannot restore disabled signature');
  assert((await page.locator('[data-pc-signature-tools]').innerText()).includes('不会自动追加'));
  await page.locator('[data-wizard-field="signature_key"]').selectOption('company');
  await page.locator('[data-pc-signature-account]').selectOption('1');
  await page.evaluate(()=>{window.signatureReply=async()=>({success:true,data:{ready:false,missing:['手机号 {mobile}','职位 {position}'],recipient_variables:[],preview_html:'<p>{position}</p>'}});});
  await page.locator('[data-pc-signature-check]').click();
  await page.locator('.pc-signature-frame').waitFor();
  assert((await page.locator('[data-pc-signature-result]').innerText()).includes('签名尚不能发送'));
  await page.frameLocator('.pc-signature-frame').locator('body').filter({hasText:'{position}'}).waitFor();
  assert(await page.evaluate(()=>calls.every(c=>c.action==='marketing_signature_content' && c.payload.preview===1)),'Inspect never saves or sends');
  await page.screenshot({path:path.join(output,'signature-check.png')});
  await page.evaluate(()=>{fixturePromotion.wizardDraft=signatureDraft;window.calls=[];window.signatureReply=null;});
  // Missing-field guidance is live, local and never rerenders the active input.
  await page.evaluate(()=>{window.guidanceDraft=structuredClone(fixturePromotion.wizardDraft);api.state.step=0;fixturePromotion.wizardDraft.task_name='';fixturePromotion.renderWizard();});
  await page.locator('[data-pc-action="next"]').click();
  assert.equal(await page.evaluate(()=>api.state.step),0);
  assert.equal(await page.locator('[data-wizard-field="task_name"]').getAttribute('aria-invalid'),'true');
  assert((await page.locator('.pc-field-error').innerText()).includes('推广名称'));
  assert(await page.locator('[data-wizard-field="task_name"]').evaluate(el=>document.activeElement===el));
  await page.locator('[data-wizard-field="task_name"]').evaluate(el=>window.guidanceInput=el);
  await page.locator('[data-wizard-field="task_name"]').fill('提示验收任务');
  assert(await page.evaluate(()=>guidanceInput.isConnected && document.activeElement===guidanceInput));
  assert.equal(await page.locator('.pc-field-error').count(),0);
  assert.equal(await page.locator('[data-pc-guidance] .is-pending').count(),0);
  assert.equal(await page.evaluate(()=>calls.length),0,'Filling guidance does not create requests or save drafts');
  await page.evaluate(()=>{fixturePromotion.wizardDraft=guidanceDraft;api.state.step=3;fixturePromotion.wizardDraft.hourly_limit=0;fixturePromotion.renderWizard();});
  await page.locator('[data-pc-action="next"]').click();
  assert.equal(await page.evaluate(()=>api.state.step),3);
  assert.equal(await page.locator('[data-wizard-field="hourly_limit"]').getAttribute('aria-invalid'),'true');
  assert(await page.locator('[data-wizard-field="hourly_limit"]').evaluate(el=>document.activeElement===el));
  assert.equal(await page.evaluate(()=>calls.length),0,'Invalid schedule never starts final-preview generation');
  await page.locator('[data-wizard-field="hourly_limit"]').fill('50');
  assert.equal(await page.locator('.pc-field-error').count(),0);
  assert.equal(await page.locator('[data-pc-guidance] .is-pending').count(),0);
  assert.equal(await page.evaluate(()=>api.validation(2,{...fixturePromotion.wizardDraft,signature_key:'obsolete'})),'请选择邮件签名方式。');
  for(const size of [{width:360,height:640},{width:1024,height:600}]){
    await page.setViewportSize(size);
    for(let step=0;step<5;step++){
      await page.evaluate(step=>{api.state.step=step;api.state.preview=step===4?makePreview():null;fixturePromotion.renderWizard();},step);
      assert(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1 && document.querySelector('.pc-footer').getBoundingClientRect().bottom<=innerHeight+1 && document.querySelector('.pc-main').clientHeight>180),'Short screens retain navigation, body and footer');
    }
  }
  await page.setViewportSize({width:1440,height:900});
  await page.evaluate(()=>{api.state.step=2;api.state.error='';api.state.preview=null;fixturePromotion.renderWizard();});
  // Real editor/selection handlers, not toolbar mocks: click, keyboard, save/reopen.
  await page.evaluate(()=>{
    window.editorDraft=structuredClone(fixturePromotion.wizardDraft);
    fixturePromotion.wizardDraft.mail_body_html='<p>Hello HERE!</p>';
    api.state.step=2;api.state.dirty=false;api.state.preview=makePreview();fixturePromotion.renderWizard();
  });
  const editor=page.locator('[data-promo-wizard-editor]');
  const subject=page.locator('[data-wizard-field="mail_subject"]');
  const variable=key=>page.locator(`[data-promo-rich-var="{${key}}"]`);
  await editor.click();
  await editor.evaluate(el=>{
    const range=document.createRange();range.setStart(el.firstChild.firstChild,6);range.setEnd(el.firstChild.firstChild,10);
    const selection=getSelection();selection.removeAllRanges();selection.addRange(range);
    el.dispatchEvent(new MouseEvent('mouseup',{bubbles:true}));
  });
  await variable('contact_name').click();
  assert.equal(await editor.innerText(),'Hello {contact_name}!','Click must replace the selected text at the real body caret');
  assert(await page.evaluate(()=>api.state.dirty && api.state.preview===null),'Variable edits invalidate the previous preview');
  // Keyboard activation after focus leaves the editor must use the remembered caret.
  await editor.evaluate(el=>{
    const range=document.createRange();range.setStart(el.firstChild.firstChild,6);range.collapse(true);
    const selection=getSelection();selection.removeAllRanges();selection.addRange(range);
    el.dispatchEvent(new KeyboardEvent('keyup',{bubbles:true}));
  });
  await variable('company_name').focus();await page.keyboard.press('Enter');
  assert.equal(await editor.innerText(),'Hello {company_name}{contact_name}!');
  await variable('mail_user_name').click();
  assert.equal(await editor.innerText(),'Hello {company_name}{mail_user_name}{contact_name}!');
  const body=await editor.innerHTML();
  await subject.fill('For HERE now');
  await subject.evaluate(el=>el.setSelectionRange(4,8));
  await variable('contact_name').click();
  assert.equal(await subject.inputValue(),'For {contact_name} now');
  await variable('company_name').focus();await page.keyboard.press('Space');
  assert.equal(await subject.inputValue(),'For {contact_name}{company_name} now');
  assert.equal(await editor.innerHTML(),body,'Subject variables must not change the body');
  assert.equal(await page.locator('[data-promo-variable-target]').innerText(),'插入到主题');
  const draftRoundTrip=await page.evaluate(()=>{
    const p=fixturePromotion,d=p.collectWizard(),payload=p.wizardTaskPayload(d,{customers:[],contacts:[],chat_groups:[],skipped:[]},'draft');
    p.wizardDraft=p.taskToWizardDraft({...payload,id:75,send_rule_json:payload.send_rule,attachment_config_json:payload.attachment_config});
    p.renderWizard();return {subject:p.wizardDraft.mail_subject,body:p.wizardDraft.mail_body_html};
  });
  assert.equal(draftRoundTrip.subject,'For {contact_name}{company_name} now');
  assert.equal(draftRoundTrip.body,body);
  await page.evaluate(()=>{
    const p=fixturePromotion;
    const reopened=p.taskToWizardDraft({id:75,task_status:'draft',audience_config_json:JSON.stringify({group_mode:'selected',contact_filter:'selected',selection:{customer_ids:[9,1],contact_ids:[8]}})});
    if(JSON.stringify(reopened.customer_ids)!=='[9,1]' || JSON.stringify(reopened.contact_ids)!=='[8]' || reopened.contact_filter!=='selected')throw new Error('Explicit draft selection lost');
    const old=p.taskToWizardDraft({id:74,task_status:'draft',audience_config_json:JSON.stringify({group_mode:'selected',excluded_customers:[{id:9}]})});
    if(!old.customer_ids.includes(9))throw new Error('Legacy excluded selection lost');
  });
  // Formatting buttons still execute the real rich command and persist the change.
  await editor.click();await page.keyboard.press('Meta+a');
  await page.locator('[data-promo-rich-cmd="bold"]').click();
  assert(await editor.locator('b,strong').count()>0,'Bold button must operate on the real editor');
  await page.evaluate(()=>{fixturePromotion.wizardDraft=editorDraft;api.state.preview=null;});
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
  // Group selection spans every page, using the real audience refresh/collector.
  await page.evaluate(()=>{
    window.beforeGroupDraft=structuredClone(fixturePromotion.wizardDraft);
    window.groupCalls=[];
    window.post=async(action,payload)=>{
      if(action!=='marketing_target_preview')throw new Error('Unexpected group test request: '+action);
      groupCalls.push(payload);
      const keys=JSON.parse(payload.group_keys);
      return {success:true,data:{audience_customer_ids:keys.length?[1]:[],audience_customer_count:keys.length?1:0}};
    };
    fixturePromotion.data.groups=Array.from({length:31},(_,i)=>({id:i+1,group_name:(i<17?'东区':'西区')+'分组 '+(i+1)+' · 较长的客户分类名称',customer_count:10,contact_count:12,promotable_contact_count:9}));
    Object.assign(fixturePromotion.wizardDraft,{group_mode:'group',group_keys:[],group_key:'',audience_customer_ids:[]});
    api.state.step=1;fixturePromotion.renderWizard();
  });
  const allGroups=Array.from({length:31},(_,i)=>i+1);
  const selectedGroups=()=>page.evaluate(()=>fixturePromotion.wizardGroupKeys(fixturePromotion.collectWizard()).sort((a,b)=>a-b));
  const settleGroups=()=>page.waitForFunction(()=>!fixturePromotion.wizardAudienceLoading);
  assert.equal(await page.locator('[data-wizard-group-check]').count(),12);
  await page.locator('[data-pc-group-select="all"]').click();await settleGroups();
  assert.deepEqual(await selectedGroups(),allGroups,'All means all pages, not only twelve visible groups');
  assert.equal(await page.evaluate(()=>groupCalls.length),1,'Bulk selection resolves the audience only once');
  await page.locator('[data-pc-group-page="1"]').click();
  assert.equal(await page.locator('[data-wizard-group-check]:checked').count(),12);
  assert.equal(await page.evaluate(()=>groupCalls.length),1,'Paging does not fetch or mutate the audience');
  await page.locator('[data-wizard-group-check][value="13"]').uncheck();await settleGroups();
  assert.deepEqual(await selectedGroups(),allGroups.filter(id=>id!==13),'Single uncheck retains off-page selections');
  await page.locator('[data-pc-group-query]').fill('东区');
  assert.equal(await page.locator('[data-wizard-group-check]').count(),12);
  assert.deepEqual(await selectedGroups(),allGroups.filter(id=>id!==13),'Search must not deselect hidden groups');
  await page.locator('[data-pc-group-select="clear"]').click();await settleGroups();
  assert.deepEqual(await selectedGroups(),[]);
  assert.equal(await page.evaluate(()=>api.validation(1,fixturePromotion.wizardDraft)),'请先选择客户分组。','Old direct-customer IDs cannot bypass an empty group selection');
  await page.locator('[data-pc-group-select="all"]').click();await settleGroups();
  assert.deepEqual(await selectedGroups(),allGroups.slice(0,17),'Filtered select-all spans all matching pages only');
  await page.locator('[data-pc-group-query]').fill('无匹配分组');
  assert(await page.locator('[data-pc-group-select="all"]').isDisabled());
  assert.deepEqual(await selectedGroups(),allGroups.slice(0,17));
  await page.locator('[data-pc-group-query]').fill('西区');
  await page.locator('[data-pc-group-select="all"]').click();await settleGroups();
  assert.deepEqual(await selectedGroups(),allGroups,'Filtered selection preserves already selected nonmatches');
  const persistedGroups=await page.evaluate(()=>{
    const p=fixturePromotion,d=p.collectWizard();
    const payload=p.wizardTaskPayload(d,{customers:[],contacts:[],chat_groups:[],skipped:[]},'draft');
    return p.taskToWizardDraft({id:81,audience_config_json:payload.audience_config}).group_keys;
  });
  assert.deepEqual(persistedGroups.sort((a,b)=>a-b),allGroups,'Save/reopen preserves all pages');
  await page.locator('[data-pc-group-query]').fill('');
  // Chinese input composition must not replace the composing input mid-word.
  await page.locator('[data-pc-group-query]').evaluate(el=>{window.composingGroupInput=el;el.value='东';el.dispatchEvent(new InputEvent('input',{bubbles:true,isComposing:true}));});
  assert(await page.evaluate(()=>composingGroupInput.isConnected));
  await page.locator('[data-pc-group-query]').evaluate(el=>el.dispatchEvent(new CompositionEvent('compositionend',{bubbles:true})));
  assert.equal(await page.locator('[data-pc-group-query]').inputValue(),'东');
  await page.locator('[data-pc-group-query]').fill('');
  for(const width of [360,390,768,1024,1440]){
    await page.setViewportSize({width,height:900});
    await page.evaluate(()=>{fixturePromotion.renderWizard();document.querySelector('.promo-step-details').open=true;});
    const scrolls=await page.evaluate(()=>[...document.querySelectorAll('.pc-main *')].filter(el=>{const s=getComputedStyle(el);return ((/auto|scroll/.test(s.overflowY)&&el.scrollHeight>el.clientHeight+1)||(/auto|scroll/.test(s.overflowX)&&el.scrollWidth>el.clientWidth+1));}).map(el=>el.className));
    assert.deepEqual(scrolls,[],`Audience has no nested scrollbars at ${width}`);
    assert(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),`Audience fits width ${width}`);
    await page.evaluate(()=>document.querySelector('.pc-main').scrollTop=0);
    if(width===390 || width===1440){await page.waitForTimeout(170);await page.screenshot({path:path.join(output,`${width}-audience-groups.png`)});}
  }
  await page.locator('[data-wizard-field="group_mode"]').selectOption('selected');await settleGroups();
  await page.locator('[data-wizard-field="group_mode"]').selectOption('group');await settleGroups();
  assert.deepEqual(await selectedGroups(),[],'Changing source clears the old group selection');
  await page.evaluate(()=>{fixturePromotion.data.groups=[];fixturePromotion.renderWizard();});
  assert(await page.locator('[data-pc-group-select="all"]').isDisabled(),'Empty available groups cannot be selected');
  assert.equal(await page.evaluate(()=>{
    const close=fixturePromotion.closeWizard;let count=0;fixturePromotion.closeWizard=()=>count++;
    document.querySelector('[data-pc-group-query]').dispatchEvent(new KeyboardEvent('keydown',{key:'Escape',bubbles:true}));
    fixturePromotion.closeWizard=close;return count;
  }),1,'Repeated search/page renders must not stack close handlers');
  await page.evaluate(()=>{fixturePromotion.wizardDraft=beforeGroupDraft;fixturePromotion.wizardAudienceLoading=false;fixturePromotion.wizardAudienceError='';});
  // Actual collection, failure recovery and double-click protection, with fake transport only.
  await page.evaluate(()=>{api.state.step=0;api.state.preview=null;fixturePromotion.renderWizard();});
  await page.locator('[data-wizard-field="task_name"]').fill('已修改的推广名称');
  assert.equal(await page.evaluate(()=>fixturePromotion.collectWizard().task_name),'已修改的推广名称');
  await page.evaluate(async()=>{await Promise.all([api.generatePreview(),api.generatePreview()]);});
  const saved=await page.evaluate(()=>({id:fixturePromotion.wizardDraft.task_id,calls,err:api.state.error}));
  assert.equal(saved.id,123);assert.equal(saved.calls.filter(c=>c.action==='marketing_task_create').length,1);assert.equal(saved.err,'离线模拟失败');
  assert.equal(saved.calls.find(c=>c.action==='marketing_delivery_preview').payload.preview_format,'paged-v1');
  await page.evaluate(async()=>{await api.generatePreview();});
  assert.equal(await page.evaluate(()=>calls.filter(c=>c.action==='marketing_task_create')[1].payload.task_id),123);
  await page.evaluate(()=>{
    const old=fixturePromotion.taskToWizardDraft({id:55,task_status:'draft',attachment_config_json:JSON.stringify({manual_attachments:[{name:'old.pdf'}]})});
    if(!old.legacyAttachmentWarning)throw new Error('Legacy attachment warning missing');
    const payload=fixturePromotion.wizardTaskPayload(old,{customers:[],contacts:[],chat_groups:[],skipped:[]},'draft');
    if(JSON.parse(payload.attachment_config).manual_attachments[0].name!=='old.pdf')throw new Error('Unacknowledged legacy attachments lost');
  });
  // Old rules and mailbox selections survive opening and collection on every step.
  await page.evaluate(()=>{
    window.savedDraft=fixturePromotion.wizardDraft;
    for(const rule of ['balanced','group_by_country','selected_mailbox']){
      const old=fixturePromotion.taskToWizardDraft({id:55,task_status:'draft',send_rule_json:JSON.stringify({mail_account_rule:rule,mail_account_ids:[1,2],timezone_rule:'business_hours'})});
      fixturePromotion.wizardDraft=old;api.state.step=0;fixturePromotion.renderWizard();
      const d=fixturePromotion.collectWizard();
      if(d.mail_account_rule!==rule || JSON.stringify(d.mail_account_ids)!=='[1,2]' || d.timezone_rule!=='business_hours')throw new Error('Legacy rule silently changed');
      if(!api.validation(3,d).includes('时区'))throw new Error('Legacy timezone must need explicit confirmation');
    }
    fixturePromotion.wizardDraft=savedDraft;
    fixturePromotion.data.mail_accounts=[{id:1,email_address:'one@example.invalid',user_id:1},{id:2,email_address:'two@example.invalid',user_id:1}];
    fixturePromotion.wizardDraft.mail_account_rule='balanced';fixturePromotion.wizardDraft.mail_account_ids=[1,2];
    api.state.step=3;fixturePromotion.renderWizard();
  });
  assert.equal(await page.locator('[data-pc-account][value="2"]').count(),1);
  await page.locator('[data-pc-account][value="2"]').uncheck();
  assert.deepEqual(await page.evaluate(()=>fixturePromotion.collectWizard().mail_account_ids),[1]);
  await page.locator('[data-pc-account][value="2"]').check();
  assert.deepEqual(await page.evaluate(()=>fixturePromotion.collectWizard().mail_account_ids),[1,2]);
  const density=await page.locator('[data-wizard-field="hourly_limit"]').evaluate(el=>el.getBoundingClientRect().height);
  assert(density<=42,`Desktop field too tall: ${density}`);
  await page.evaluate(()=>{
    window.pagedReply=({page=0,index=page*20,excluded_page=0}={})=>{
      const item=i=>({...makePreview().manifest.items[0],index:i,customer_name:'Customer '+i,body_html:'<p>Frozen customer '+i+'</p>'});
      return {token:'paged',manifest:{total:3000,email_count:3000,manual_count:0,page,selected_index:index,items:Array.from({length:20},(_,n)=>{const r=item(page*20+n);delete r.body_html;return r;}),current_item:item(index),excluded:[],excluded_total:0,excluded_page,senders:[{name:'one@example.invalid',mode:'email',count:1500,first:'2026-09-08 09:00:00',last:'2026-09-20 09:00:00'},{name:'two@example.invalid',mode:'email',count:1500,first:'2026-09-08 09:00:00',last:'2026-09-20 09:00:00'}],schedule:{send_interval_minutes:3,hourly_limit:50,daily_limit:200},attachments:[]}};
    };
    api.state.step=4;api.state.selected=0;api.state.page=0;api.state.preview=pagedReply();fixturePromotion.renderWizard();
  });
  for(const width of [360,390,768,1024,1440]){
    await page.setViewportSize({width,height:900});
    await page.locator('.pc-distribution-details summary').click();
    assert(await page.locator('.pc-distribution').isVisible(),'Detailed schedule is available on demand');
    assert(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),`Paged layout overflow ${width}`);
    await page.locator('.pc-distribution-details summary').click();
    await page.evaluate(()=>document.querySelector('.pc-main').scrollTop=0);
    await page.waitForTimeout(170);
    await page.screenshot({path:path.join(output,`${width}-paged-preview.png`)});
  }
  await page.locator('[data-pc-page="1"]').click();
  await page.waitForFunction(()=>api.state.page===1 && !api.state.busy);
  assert.equal(await page.locator('[data-pc-recipient]').count(),20);
  await page.locator('[data-pc-recipient="23"]').click();
  await page.waitForFunction(()=>api.state.selected===23 && !api.state.busy);
  assert((await page.locator('iframe').getAttribute('srcdoc')).includes('Frozen customer 23'));
  await page.evaluate(async()=>{window.confirm=message=>{window.confirmText=message;return false;};api.state.preview.fingerprint=JSON.stringify(fixturePromotion.collectWizard());await api.confirmDelivery();});
  assert((await page.evaluate(()=>window.confirmText)).includes('3000 封邮件'),'Confirm must use total, not current page size');
  await page.locator('[data-pc-consent]').check();
  assert.equal(await page.locator('[data-pc-guidance] .is-pending').count(),0,'Last-step guidance reflects explicit review consent');
  const callsBeforeStale=await page.evaluate(()=>calls.length);
  await page.evaluate(async()=>{api.state.preview.fingerprint=JSON.stringify(fixturePromotion.wizardDraft);fixturePromotion.wizardDraft.delivery_version=1;await api.confirmDelivery();});
  assert.equal(await page.evaluate(()=>api.state.preview),null,'Collection invalidation cannot execute a stale preview');
  assert((await page.locator('[data-pc-error]').innerText()).includes('重新生成预览'));
  assert.equal(await page.evaluate(()=>calls.length),callsBeforeStale,'Stale preview sends no confirm request');
  await page.evaluate(()=>{api.state.preview=makePreview();api.state.error='';fixturePromotion.renderWizard();});
  await page.emulateMedia({reducedMotion:'reduce'});
  assert.equal(await page.locator('.pc-content').evaluate(el=>getComputedStyle(el).animationName),'none');
  await page.setViewportSize({width:1024,height:720});
  await page.evaluate(()=>{document.documentElement.style.zoom='1.25';api.state.step=4;api.state.preview=makePreview();fixturePromotion.renderWizard();});
  await page.screenshot({path:path.join(output,'zoom125.png')});
  await page.evaluate(()=>{
    const p=fixturePromotion,render=p.renderTasks;let rendered=0;
    p.renderTasks=function(){rendered++;return render.call(this);};
    p.data.tasks=[{id:123,task_status:'draft'}];p.wizardDraft.task_id=123;
    p.closeWizard(true);
    if(!rendered || p.selectedTaskId!==123)throw new Error('Closing a saved draft must redraw and select the saved row');
  });
  // The shared action hint must not survive entering the promotion workspace.
  const hints=await browser.newPage({viewport:{width:390,height:720}});
  await hints.bringToFront();
  hints.on('pageerror',e=>errors.push(e.message));
  await hints.route('**/*',route=>route.abort());
  await hints.setContent('<style>.off{display:none}</style><div id="actions" style="position:fixed;right:8px;bottom:8px"><button data-tooltip="创建一个新的推广项目草稿并进入创建向导"><span>新建推广项目</span></button></div><input aria-label="表单输入">');
  await hints.addStyleTag({content:fs.readFileSync(path.join(root,'assets/crm/crm.css'),'utf8')});
  const hintStart=source.indexOf('  var ActionTooltipEngine = {');
  const hintEnd=source.indexOf('  function setActionbarCollapsed(',hintStart);
  assert(hintStart>0 && hintEnd>hintStart);
  await hints.addScriptTag({content:source.slice(hintStart,hintEnd)});
  const trigger=hints.locator('[data-tooltip]');
  const hintCount=()=>hints.locator('.action_tooltip.is-visible').count();
  const hoverHint=async()=>{
    await hints.mouse.move(1,1);await trigger.hover();await hints.waitForTimeout(360);
    assert.equal(await hintCount(),1,'Hover still explains the button');
  };
  await hoverHint();
  const hintBox=await hints.locator('.action_tooltip').boundingBox();
  assert(hintBox.x>=0 && hintBox.x+hintBox.width<=390 && hintBox.y+hintBox.height<=720,'Hint stays in small-screen bounds');
  await hints.evaluate(()=>document.querySelector('button').addEventListener('click',()=>document.querySelector('#actions').hidden=true,{once:true}));
  await trigger.click();await hints.waitForTimeout(180);
  assert.equal(await hintCount(),0,'Click opening a form hides the hint before its button disappears');
  await hints.evaluate(()=>document.querySelector('#actions').hidden=false);
  // Fast clicks must cancel the 300 ms pending show, too.
  await hints.mouse.move(1,1);await trigger.hover();await trigger.click();await hints.waitForTimeout(360);
  assert.equal(await hintCount(),0,'Pending hint cannot reappear after click');
  for(const action of ['remove','hide','scroll','escape','blur','hash','focus','keyboardClick']){
    await hoverHint();
    await hints.evaluate(action=>{
      const button=document.querySelector('[data-tooltip]'),parent=document.querySelector('#actions');
      if(action==='remove'){window.removedHintButton=button;button.remove();}
      if(action==='hide')parent.classList.add('off');
      if(action==='scroll')parent.dispatchEvent(new Event('scroll'));
      if(action==='escape')document.dispatchEvent(new KeyboardEvent('keydown',{key:'Escape',bubbles:true}));
      if(action==='blur')window.dispatchEvent(new Event('blur'));
      if(action==='hash')window.dispatchEvent(new HashChangeEvent('hashchange'));
      if(action==='focus')document.querySelector('input').focus();
      if(action==='keyboardClick')button.click();
    },action);
    await hints.waitForTimeout(180);
    assert.equal(await hintCount(),0,`Hint dismissed on ${action}`);
    assert(await hints.evaluate(()=>ActionTooltipEngine.target===null && ActionTooltipEngine.observer===null),'Observer released when idle');
    await hints.evaluate(()=>{document.querySelector('#actions').classList.remove('off');if(window.removedHintButton){document.querySelector('#actions').appendChild(removedHintButton);window.removedHintButton=null;}});
  }
  await hints.mouse.move(1,1);await trigger.hover();
  await hints.evaluate(()=>document.querySelector('#actions').hidden=true);
  await hints.waitForTimeout(360);
  assert.equal(await hintCount(),0,'Hidden trigger cannot finish delayed show');
  await hints.close();
  assert.equal(errors.length,0,errors.join('\n'));
  console.log(JSON.stringify({passed:true,layouts:25,renderMaxMs:Math.max(...timings),output,requests:'all simulated, no live network'}));
  await browser.close();
})().catch(e=>{console.error(e);process.exit(1);});
