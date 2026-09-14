const fs=require('fs'),assert=require('assert');
const {chromium}=require(process.env.PLAYWRIGHT_MODULE||'playwright');
(async()=>{
 const browser=await chromium.launch({headless:true,executablePath:process.env.CHROME_BIN});
 const info={quote_no:'QA-MAIL-001',revision:'abc123',customer_name:'Acceptance customer',sender:'sender@example.invalid',account_id:7,contacts:[{id:1,name:'First contact',email:'one@example.invalid'},{id:2,name:'Second contact',email:'two@example.invalid'}],sent_history:[]};
 try{for(const width of [390,768,1280]){
  const page=await browser.newPage({viewport:{width,height:900}});let requests=[],fail=true;
  await page.route('https://quote-mail.test/**',async route=>{
   const url=new URL(route.request().url());
   if(url.pathname==='/quote_mail_api.php'){
    if(url.searchParams.get('action')==='info')return route.fulfill({json:{success:true,data:info}});
    requests.push(route.request().postDataJSON());await new Promise(r=>setTimeout(r,100));
    if(fail)return route.fulfill({status:400,json:{success:false,message:'验收模拟失败，可重试'}});
    return route.fulfill({json:{success:true,data:{url:'crm.php?quote_mail='+requests.at(-1).token+'#mail'}}});
   }return route.fulfill({contentType:'text/html',body:'<html><head><meta name="shipment-csrf" content="fixture"></head><body>Acceptance only</body></html>'});
  });
  await page.goto('https://quote-mail.test/quotation.php');
  await page.addScriptTag({content:fs.readFileSync('assets/quote-mail.js','utf8')});
  await page.evaluate(()=>QuoteMail.open(1));await page.waitForSelector('[data-create]');
  assert(await page.locator('[name=pdf]').isChecked());assert(!(await page.locator('[name=excel]').isChecked()));
  await page.click('[data-create]');assert.equal(requests.length,0,'Explicit multiple contact required');
  await page.selectOption('[data-contact]','two@example.invalid');await page.check('[name=excel]');
  await page.click('[data-create]');await page.waitForFunction(()=>document.querySelector('[data-status]').textContent.includes('模拟失败'));
  assert.equal(requests.length,1);assert.deepEqual(requests[0].formats,['pdf','excel']);assert.equal(requests[0].email,'two@example.invalid');
  const box=await page.locator('dialog').boundingBox();assert(box.x>=0&&box.x+box.width<=width+1,'Dialog fits screen');
  const overflow=await page.locator('dialog').evaluate(el=>el.scrollWidth>el.clientWidth+1);assert(!overflow,'No internal horizontal clipping');
  if(process.env.QUOTE_MAIL_SCREENSHOTS)await page.screenshot({path:process.env.QUOTE_MAIL_SCREENSHOTS+'/modal-'+width+'.png'});
  fail=false;await page.click('[data-create]');await page.waitForURL('**/crm.php?quote_mail=*');assert.equal(requests[0].token,requests[1].token,'Retry uses same creation token');
  await page.close();
 }
 const page=await browser.newPage();await page.goto('about:blank');
 await page.setContent('<form data-mail-compose-form><input name="customer_id"><input name="contact_id"></form><span data-mail-compose-status></span>');
 await page.evaluate(()=>{window.MailModule={openCompose(){return true;},composeData(){return {draft_meta_json:JSON.stringify({auto_signature:true}),to_emails:'changed@example.invalid'};}};});
 await page.addScriptTag({content:fs.readFileSync('assets/crm/quote-mail.js','utf8')});
 const data=await page.evaluate(()=>{MailModule.openCompose('draft',{linked_customer_id:4,linked_contact_id:5,draft_meta_json:JSON.stringify({quote_mail_token:'a'.repeat(48),quote_no:'QA-MAIL-001',quote_revision:'abc',quote_files:['QA-MAIL-001.pdf','QA-MAIL-001.xlsx']})});return MailModule.composeData();});
 assert.equal(data.quote_mail_token,'a'.repeat(48));assert.equal(JSON.parse(data.draft_meta_json).quote_files.length,2);assert.equal(await page.locator('[data-quote-mail-preview] a').count(),2);
 assert.equal(await page.locator('[name=customer_id]').inputValue(),'4');
 await page.evaluate(()=>MailModule.openCompose('compose',{}));assert.equal(await page.locator('[data-quote-mail-preview]').count(),0,'Ordinary mail resets quote metadata');
 await page.close();console.log('Quote mail synthetic browser: 390/768/1280, contact choice, PDF/Excel choice, failed retry, route, draft metadata and preview passed.');
 }finally{await browser.close();}
})().catch(e=>{console.error(e);process.exitCode=1;});
