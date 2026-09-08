'use strict';
// Isolated DOM + fake HTTP only; never uses a live account or shipment.
const fs=require('node:fs'),path=require('node:path'),os=require('node:os'),assert=require('node:assert/strict');
const {chromium}=require(process.env.PLAYWRIGHT_MODULE || 'playwright');
const root=path.resolve(__dirname,'..');
const crm=fs.readFileSync(path.join(root,'assets/crm/crm.js'),'utf8');
const quote=fs.readFileSync(path.join(root,'quotation.php'),'utf8');
const previewStart=crm.indexOf('    previewAttachment: function (attachmentId, name) {');
const previewEnd=crm.indexOf('    previewLocalAttachmentFile:',previewStart);
assert(previewStart>0 && previewEnd>previewStart);
const esc="var esc=v=>String(v ?? '').replace(/[&<>\"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','\"':'&quot;',\"'\":'&#39;'}[c]));";
function pdfFixture(){
  const stream='BT /F1 18 Tf 30 200 Td (Acceptance PDF content) Tj ET';
  const objects=['<< /Type /Catalog /Pages 2 0 R >>','<< /Type /Pages /Kids [3 0 R] /Count 1 >>','<< /Type /Page /Parent 2 0 R /MediaBox [0 0 360 260] /Resources << /Font << /F1 5 0 R >> >> /Contents 4 0 R >>','<< /Length '+stream.length+' >>\nstream\n'+stream+'\nendstream','<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>'];
  let pdf='%PDF-1.4\n',offsets=[0];objects.forEach((s,i)=>{offsets.push(Buffer.byteLength(pdf));pdf+=(i+1)+' 0 obj\n'+s+'\nendobj\n';});
  const xref=Buffer.byteLength(pdf);pdf+='xref\n0 6\n0000000000 65535 f \n'+offsets.slice(1).map(n=>String(n).padStart(10,'0')+' 00000 n \n').join('')+'trailer\n<< /Size 6 /Root 1 0 R >>\nstartxref\n'+xref+'\n%%EOF';return pdf;
}
(async()=>{
  const output=fs.mkdtempSync(path.join(os.tmpdir(),'issues7-browser-'));
  const browser=await chromium.launch({headless:true,executablePath:process.env.CHROME_BIN || undefined});
  const page=await browser.newPage(),errors=[];page.on('pageerror',e=>errors.push(e.message));
  let mode='pdf';
  await page.route('**/*',async route=>{
    const url=route.request().url();
    if(url==='https://issues7.invalid/')return route.fulfill({contentType:'text/html',body:'<!doctype html><body></body>'});
    if(url.includes('mail_attachment_preview')){
      if(mode==='error')return route.fulfill({contentType:'application/json',body:JSON.stringify({success:false,message:'验收：附件不存在，请下载核对'})});
      if(mode==='unsafe')return route.fulfill({contentType:'text/html',body:'<script>window.unsafeExecuted=true</script>'});
      if(mode==='slow')await new Promise(r=>setTimeout(r,250));
      return route.fulfill({contentType:'application/pdf',body:pdfFixture()}).catch(()=>{});
    }
    return route.abort();
  });
  await page.goto('https://issues7.invalid/');
  await page.addStyleTag({content:fs.readFileSync(path.join(root,'assets/crm/crm.css'),'utf8')});
  await page.addScriptTag({content:esc+`var toast=()=>{};window.Mail={attachmentDownloadUrl:id=>'https://issues7.invalid/download/'+id,${crm.slice(previewStart,previewEnd)}};window.blobCreated=0;window.blobRevoked=0;var originalCreate=URL.createObjectURL.bind(URL),originalRevoke=URL.revokeObjectURL.bind(URL);URL.createObjectURL=b=>{blobCreated++;return originalCreate(b);};URL.revokeObjectURL=u=>{blobRevoked++;return originalRevoke(u);};`});
  for(const width of [390,1280]){
    await page.setViewportSize({width,height:850});
    await page.evaluate(()=>Mail.previewAttachment(1,'验收附件.pdf'));
    await page.waitForSelector('[data-mail-attachment-preview-layer] iframe');
    assert((await page.locator('[data-mail-attachment-preview-layer] iframe').getAttribute('src')).startsWith('blob:'));
    const frame=await page.locator('[data-mail-attachment-preview-layer] iframe').boundingBox();
    assert(frame.width>=width*0.7 && frame.height>400,'Preview fills available dialog area');
    assert(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1));
    await page.screenshot({path:path.join(output,'attachment-'+width+'.png')});
    await page.locator('[data-mail-attachment-preview-close]').click();
    assert.equal(await page.evaluate(()=>blobCreated-blobRevoked),0,'Close releases blob');
  }
  mode='error';await page.evaluate(()=>Mail.previewAttachment(2,'不存在.pdf'));
  await page.waitForFunction(()=>document.querySelector('.mail-local-preview-loading').textContent.includes('附件不存在'));
  mode='unsafe';await page.evaluate(()=>Mail.previewAttachment(3,'伪装.pdf'));
  await page.waitForFunction(()=>document.querySelector('.mail-local-preview-loading').textContent.includes('不能安全预览'));
  assert.equal(await page.evaluate(()=>!!window.unsafeExecuted),false);
  mode='slow';await page.evaluate(()=>Mail.previewAttachment(4,'延迟.pdf'));
  await page.locator('[data-mail-attachment-preview-close]').click();await page.waitForTimeout(300);
  assert.equal(await page.evaluate(()=>blobCreated-blobRevoked),0,'Closed pending request cannot leak a preview');
  const ship=await browser.newPage();ship.on('pageerror',e=>errors.push(e.message));await ship.route('**/*',r=>r.abort());
  await ship.setContent('<body><div id="shipmentModal"><div class="body"><div id="grid"><table><tbody id="shipItemRows"></tbody></table></div><div id="cartonRows"></div><p id="shipSummary"></p></div></div></body>');
  await ship.addStyleTag({content:fs.readFileSync(path.join(root,'assets/quote-shipment-selection.css'),'utf8')});
  const line=prefix=>{const found=quote.split('\n').find(l=>l.startsWith(prefix));assert(found,prefix);return found;};
  await ship.addScriptTag({content:esc+`
    var $=id=>document.getElementById(id),num=v=>Number(v)||0,fmtNum=v=>String(v),SHIPMENT_EDIT_ID=0;
    var SHIPMENT_PREP={order:{id:1},orders:[{id:1,order_no:'AT-验收订单1'},{id:2,order_no:'AT-验收订单2'}]};
    function quoteOrderNoAtV68522(no){return no;}
    function renderShipmentRows(items){$('shipItemRows').innerHTML=items.map(x=>'<tr data-order-id="'+x.order_id+'" data-order-item="'+x.order_item_id+'"><td>'+x.order_id+'</td><td><input class="ship-qty" value="'+x.qty+'"></td></tr>').join('');}
    function ensureShipmentSplitControls(){}function collectCartons(){return [{qty:12,carton_count:1}];}
    function collectShipmentItems(){}function editShipment(){}function openShipmentModal(){}function openCombinedShipmentModal(){}function addCartonRow(){}
    ${line('collectShipmentItems=function()')}
    ${line('function recalcShipmentTotals()')}
    var captured;async function orderApi(action,data){captured={action,data};return {shipment_no:'ACCEPTANCE'};}
    function alert(){}function closeShipmentModal(){}async function refreshQuoteRuntime(){}async function viewOrder(){}
    var saveShipment;
    ${line('saveShipment=async function(')}
    ['shipNo','shipDate','shipPlNo','shipCiNo','shipMark','shipMethod','shipPortLoad','shipPortDest'].forEach(id=>{var el=document.createElement('input');el.id=id;el.value='';el.hidden=true;document.body.appendChild(el);});
  `});
  await ship.addScriptTag({content:fs.readFileSync(path.join(root,'assets/quote-shipment-selection.js'),'utf8')});
  await ship.evaluate(()=>renderShipmentRows([{order_id:1,order_item_id:11,qty:4},{order_id:2,order_item_id:21,qty:8}]));
  assert.equal(await ship.evaluate(()=>collectShipmentItems().length),1,'Only starting order selected by default');
  await ship.locator('#shipmentOrderPicker input[value="2"]').check();
  assert.equal(await ship.evaluate(()=>collectShipmentItems().length),2);
  assert((await ship.locator('#shipSummary').innerText()).includes('12 PCS'),'Mixed contents do not double sum');
  await ship.locator('#shipmentOrderPicker input[value="1"]').uncheck();
  await ship.evaluate(()=>saveShipment());
  assert.deepEqual(await ship.evaluate(()=>captured.data.order_ids),[2]);
  assert.deepEqual(await ship.evaluate(()=>captured.data.items.map(x=>x.order_item_id)),[21]);
  await ship.locator('#shipmentOrderPicker input[value="1"]').check();
  assert.equal(await ship.locator('[data-order-item="11"] .ship-qty').inputValue(),'4','Deselection preserves local value');
  for(const width of [390,1280]){await ship.setViewportSize({width,height:850});assert(await ship.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1));await ship.screenshot({path:path.join(output,'shipment-'+width+'.png')});}
  assert.deepEqual(errors,[]);await browser.close();
  console.log(JSON.stringify({passed:true,output,checks:'attachment success/error/type/close; shipment select/exclude/preserve/save/totals; small and large layouts'}));
})().catch(e=>{console.error(e);process.exit(1);});
