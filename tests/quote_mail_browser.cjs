const fs=require('fs'),assert=require('assert');
const {chromium}=require(process.env.PLAYWRIGHT_MODULE||'playwright');
(async()=>{
 const browser=await chromium.launch({headless:true,executablePath:process.env.CHROME_BIN});
 const record={job_id:'fixture',mail_kind:'test',status:'success',to_emails:'qa@example.invalid',cc_emails:'',bcc_emails:'',sender_email:'sender@example.invalid',sender_name:'Test sender',subject:'[测试] QA',queued_at:'2026-09-14 12:00:00',sent_at:'2026-09-14 12:00:05',snapshot_hash:'abc123'};
 const info={quote_no:'QA-MAIL-001',revision:'abc123',customer_name:'Acceptance customer',sender:'sender@example.invalid',test_recipient:'qa@example.invalid',account_id:7,contacts:[{id:1,name:'First contact',email:'one@example.invalid'},{id:2,name:'Second contact',email:'two@example.invalid'}],history:{rows:[record],offset:0,has_more:true}};
 try{for(const width of [390,768,1280]){
  const page=await browser.newPage({viewport:{width,height:900}});let requests=[],fail=true;
  await page.route('https://quote-mail.test/**',async route=>{
   const url=new URL(route.request().url());
   if(url.pathname==='/quote_mail_api.php'){
    if(url.searchParams.get('action')==='info')return route.fulfill({json:{success:true,data:info}});
    if(url.searchParams.get('action')==='history')return route.fulfill({json:{success:true,data:{rows:[{...record,status:'failed',sent_at:null,error_message:'Synthetic failure'}],offset:Number(url.searchParams.get('offset')),has_more:false}}});
    if(url.searchParams.get('action')==='preview')return route.fulfill({json:{success:true,data:{sender:info.sender,to_emails:requests.at(-1).email,subject:'[测试] QA-MAIL-001',mail_kind:'test',revision:'abc123',body_html:'<p>'+requests.at(-1).body_text+'</p><p>Acceptance signature</p><script>parent.previewUnsafe=true</script>',files:[{name:'QA-MAIL-001.pdf',size:2000,url:'quote_mail_api.php?action=file&token='+requests.at(-1).token+'&index=0'},{name:'QA-MAIL-001.xlsx',size:3000,url:'quote_mail_api.php?action=file&token='+requests.at(-1).token+'&index=1'}]}}});
    requests.push(route.request().postDataJSON());await new Promise(r=>setTimeout(r,100));
    if(fail)return route.fulfill({status:400,json:{success:false,message:'验收模拟失败，可重试'}});
    return route.fulfill({json:{success:true,data:{url:'crm.php?quote_mail='+requests.at(-1).token+'#mail'}}});
   }return route.fulfill({contentType:'text/html',body:'<html><head><meta name="shipment-csrf" content="fixture"></head><body>Acceptance only</body></html>'});
  });
  await page.goto('https://quote-mail.test/quotation.php');
  await page.addScriptTag({content:fs.readFileSync('assets/quote-mail-preview.js','utf8')});
  await page.addScriptTag({content:fs.readFileSync('assets/quote-mail.js','utf8')});
  await page.evaluate(()=>QuoteMail.open(1));await page.waitForSelector('[data-create]');
  assert(await page.locator('[name=pdf]').isChecked());assert(!(await page.locator('[name=excel]').isChecked()));
  await page.click('[data-create]');assert.equal(requests.length,0,'Explicit multiple contact required');
  await page.selectOption('[data-contact]','two@example.invalid');await page.check('[name=excel]');
  await page.click('[data-create]');await page.waitForFunction(()=>document.querySelector('[data-status]').textContent.includes('模拟失败'));
  assert.equal(requests.length,1);assert.deepEqual(requests[0].formats,['pdf','excel']);assert.equal(requests[0].email,'two@example.invalid');
  assert.equal(requests[0].mail_kind,'formal');assert(requests[0].body_text.includes('quotation'));
  assert((await page.locator('[data-body]').boundingBox()).height<=80,'Body stays compact');
  await page.check('[name="mail-kind"][value="test"]');assert(await page.locator('[data-contact]').isHidden());
  await page.fill('[data-test-email]','bad-address');await page.click('[data-create]');assert.equal(requests.length,1,'Invalid test address never submitted');
  await page.fill('[data-test-email]','custom@example.invalid');await page.fill('[data-body]','A small note.');await page.click('[data-create]');await page.waitForFunction(()=>document.querySelector('[data-status]').textContent.includes('模拟失败'));
  assert.equal(requests[1].email,'custom@example.invalid');assert.equal(requests[1].mail_kind,'test');assert.equal(requests[1].body_text,'A small note.');assert.notEqual(requests[0].token,requests[1].token,'Changed purpose/text uses a fresh draft token');
  await page.click('summary');assert((await page.locator('[data-history-list]').textContent()).includes('12:00:05'));await page.click('[data-more]');await page.waitForFunction(()=>document.querySelector('[data-history-list]').textContent.includes('Synthetic failure'));
  assert.equal(await page.locator('[data-history-list] article').count(),2,'Earlier records remain visible');await page.click('summary');
  const box=await page.locator('dialog').boundingBox();assert(box.x>=0&&box.x+box.width<=width+1,'Dialog fits screen');
  const overflow=await page.locator('dialog').evaluate(el=>el.scrollWidth>el.clientWidth+1);assert(!overflow,'No internal horizontal clipping');
  if(process.env.QUOTE_MAIL_SCREENSHOTS)await page.screenshot({path:process.env.QUOTE_MAIL_SCREENSHOTS+'/modal-'+width+'.png'});
  fail=false;await page.click('[data-preview]');await page.waitForSelector('.qmp-dialog[open]');assert(page.url().includes('quotation.php'),'Preview does not navigate or send');assert.equal(requests[1].token,requests[2].token,'Retry uses same creation token');
  assert((await page.locator('.qmp-dialog dl').textContent()).includes('custom@example.invalid'));assert.equal(await page.locator('.qmp-dialog a').count(),2);
  assert.equal(await page.locator('.qmp-dialog iframe').getAttribute('sandbox'),'');assert.equal(await page.frameLocator('.qmp-dialog iframe').locator('script').count(),0);assert((await page.frameLocator('.qmp-dialog iframe').locator('body').textContent()).includes('Acceptance signature'));assert(!(await page.evaluate(()=>window.previewUnsafe)));
  const previewBox=await page.locator('.qmp-dialog').boundingBox();assert(previewBox.x>=0&&previewBox.x+previewBox.width<=width+1,'Preview fits small screen');
  if(process.env.QUOTE_MAIL_SCREENSHOTS)await page.screenshot({path:process.env.QUOTE_MAIL_SCREENSHOTS+'/preview-'+width+'.png'});
  await page.click('[data-qmp-back]');await page.click('[data-preview]');await page.waitForSelector('.qmp-dialog[open]');assert.equal(requests[2].token,requests[3].token,'Unchanged preview reuses draft request');
  await page.click('[data-qmp-next]');await page.waitForURL('**/crm.php?quote_mail=*');
  await page.close();
 }
 const page=await browser.newPage();let currentPreview,imageRequests=0;
 await page.route('https://quote-mail.test/**',async route=>{
  if(route.request().url().endsWith('/preview-pixel')){imageRequests++;return route.fulfill({contentType:'image/png',body:Buffer.from('fixture')});}
  if(route.request().url().includes('action=preview')){currentPreview=route.request().postDataJSON();await new Promise(r=>setTimeout(r,150));return route.fulfill({json:{success:true,data:{...currentPreview.current,sender:info.sender,mail_kind:'test',revision:'abc',files:[]}}});}
  return route.fulfill({contentType:'text/html',body:'<html><head></head><body></body></html>'});
 });await page.goto('https://quote-mail.test/crm.php');
 await page.setContent('<form data-mail-compose-form><input name="customer_id"><input name="contact_id"></form><span data-mail-compose-status></span>');
 await page.evaluate(()=>{window.CRM_BOOTSTRAP={csrf:'fixture'};window.fixtureNote='Current edited CRM note';window.MailModule={openCompose(){return true;},composeData(){return {draft_meta_json:JSON.stringify({auto_signature:true}),to_emails:'changed@example.invalid',subject:'[测试] Current subject',body_html:'<p>'+window.fixtureNote+'</p>'};}};});
 await page.addScriptTag({content:fs.readFileSync('assets/quote-mail-preview.js','utf8')});
 await page.addScriptTag({content:fs.readFileSync('assets/crm/quote-mail.js','utf8')});
 const data=await page.evaluate(()=>{MailModule.openCompose('draft',{linked_customer_id:4,linked_contact_id:5,draft_meta_json:JSON.stringify({quote_mail_token:'a'.repeat(48),quote_no:'QA-MAIL-001',quote_revision:'abc',quote_files:['QA-MAIL-001.pdf','QA-MAIL-001.xlsx'],quote_mail_kind:'test',quote_test_recipient:'qa@example.invalid'})});return MailModule.composeData();});
 assert.equal(data.quote_mail_token,'a'.repeat(48));assert.equal(JSON.parse(data.draft_meta_json).quote_files.length,2);assert.equal(await page.locator('[data-quote-mail-preview] a').count(),2);
 assert.equal(await page.locator('[name=customer_id]').inputValue(),'4');
 assert.equal(JSON.parse(data.draft_meta_json).quote_mail_kind,'test');assert((await page.locator('[data-mail-compose-status]').textContent()).includes('qa@example.invalid'));
 await page.click('[data-quote-compose-preview]');await page.waitForSelector('.qmp-dialog[open]');assert.equal(currentPreview.current.subject,'[测试] Current subject');assert((await page.frameLocator('.qmp-dialog iframe').locator('body').textContent()).includes('Current edited CRM note'));await page.click('[data-qmp-back]');
 await page.click('[data-quote-compose-preview]');await page.evaluate(()=>{window.fixtureNote='Changed during preview';});await page.waitForFunction(()=>document.querySelector('[data-mail-compose-status]').textContent.includes('重新点击'));assert.equal(await page.locator('.qmp-dialog').count(),0,'Stale preview never opens');
 await page.evaluate(()=>MailModule.openCompose('compose',{}));assert.equal(await page.locator('[data-quote-mail-preview]').count(),0,'Ordinary mail resets quote metadata');
 await page.evaluate(()=>QuoteMailPreview.open({subject:'Image safety',files:[],body_html:'<div contenteditable="true">Read only signature</div><img src="https://quote-mail.test/preview-pixel" onerror="parent.previewUnsafe=true">'}));await page.frameLocator('.qmp-dialog iframe').locator('img').waitFor();assert.equal(imageRequests,0,'Remote images do not load automatically');assert.equal(await page.frameLocator('.qmp-dialog iframe').locator('[contenteditable]').count(),0,'Signature editor flags removed from preview');
 await Promise.all([page.waitForResponse('**/preview-pixel'),page.click('[data-qmp-images]')]);assert.equal(imageRequests,1,'Explicit action permits external image');assert(!(await page.evaluate(()=>window.previewUnsafe)));await page.click('[data-qmp-close]');
 await page.close();console.log('Quote mail synthetic browser: 390/768/1280, contact choice, PDF/Excel choice, failed retry, route, draft metadata and preview passed.');
 }finally{await browser.close();}
})().catch(e=>{console.error(e);process.exitCode=1;});
