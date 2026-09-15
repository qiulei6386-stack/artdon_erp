const fs=require('fs'),assert=require('node:assert/strict');
const {chromium}=require(process.env.PLAYWRIGHT_MODULE||'playwright');
const source=fs.readFileSync('quotation.php','utf8');
const section=source.match(/<section id="page-orders"[\s\S]*?<\/section>/)[0].replace('class="page"','class="page active"');
const styles=[...source.matchAll(/<style[^>]*>([\s\S]*?)<\/style>/g)].map(m=>m[1]).join('\n')+fs.readFileSync('assets/quote-order-page.css','utf8');
const funcs=['orderCurrencyKeyV68523','orderCurrencyMapsV68523','orderAddCurrencyAmountV68523','orderBalanceAmountV68523','orderCurrencyLineV68523','orderFinanceStrip','orderPaymentStatusText','orderPaymentBadgeClass'].map(n=>source.split('\n').find(l=>l.startsWith('function '+n+'('))).join('\n');
(async()=>{const browser=await chromium.launch({headless:true,executablePath:process.env.CHROME_BIN});try{
 for(const width of [390,768,1280]){
  const page=await browser.newPage({viewport:{width,height:900}});const errors=[];page.on('pageerror',e=>errors.push(e.message));
  await page.route('https://order-page.test/**',r=>r.fulfill({contentType:'text/html',body:'<!doctype html><meta charset="utf-8"><style>'+styles+'</style>'+section}));
  await page.goto('https://order-page.test/');
  await page.addScriptTag({content:`let ORDER_OVERVIEW=null,DASH_ORDERS_LOADED=false,DB={orders:[]};const $=id=>document.getElementById(id),num=n=>Number(n)||0,money=n=>num(n).toFixed(2),esc=s=>String(s??'').replaceAll('&','&amp;').replaceAll('<','&lt;').replaceAll('>','&gt;');function hasPerm(){return true}function renderDash(){}function quoteOrderNoAtV68522(s){return s}function viewOrder(id){$('orderDetail').textContent='详情 '+id}function loadOrders(){return OrderCenter.load()}function renderOrders(){return OrderCenter.render()}${funcs}
   const fixture=Array.from({length:107},(_,i)=>({id:107-i,order_no:'AT-'+(107-i),quote_no:'Q-'+(107-i),customer_name:'Acceptance customer with a long company name',owner_name:i%2?'Amy':'Ben',currency:'USD',amount:100,paid_amount:10,balance_amount:90,qty:10,status:'已确认',shipment_status:'未出货',payment_status:'部分收款'}));window.calls=[];window.failNext=false;
   async function orderApi(action,input){window.calls.push({action,input});await new Promise(r=>setTimeout(r,30));if(window.failNext){window.failNext=false;throw Error('模拟断网')};let all=fixture.filter(o=>!input?.search||o.order_no===input.search),p=input?.page||1,size=input?.size||20;const overview={count:107,customers:['Acceptance customer with a long company name'],owners:['Amy','Ben'],finance:[],quote_nos:[]};if(action==='order_overview')return {overview};return {page:p,size,pages:Math.max(1,Math.ceil(all.length/size)),total:all.length,orders:all.slice((p-1)*size,p*size),finance:[{currency:'USD',amount:all.length*100,paid_amount:all.length*10,balance_amount:all.length*90}],overview};}`});
  await page.addScriptTag({content:fs.readFileSync('assets/quote-order-page.js','utf8')});await page.evaluate(()=>OrderCenter.load());
  assert.equal(await page.locator('#orderList .order-card').count(),5);assert((await page.locator('.finance-strip').textContent()).includes('10700.00'));
  assert(await page.locator('[data-order-page="0"]').first().isDisabled());await page.locator('[data-order-page="2"]').first().click();await page.waitForFunction(()=>document.querySelector('#orderCount').textContent.includes('本页 5')&&document.querySelector('.order-page-controls')?.textContent.includes('第 2'));
  assert((await page.locator('.order-card').first().textContent()).includes('AT-102'));assert((await page.locator('.finance-strip').textContent()).includes('10700.00'));
  await page.fill('#orderSearch','AT-1');await page.waitForFunction(()=>document.querySelectorAll('.order-card').length===1);assert((await page.locator('.order-card').textContent()).includes('AT-1'));
  await page.click('[data-order-detail="1"]');assert.equal(await page.locator('#orderDetail').textContent(),'详情 1');
  await page.fill('#orderSearch','');await page.waitForFunction(()=>document.querySelectorAll('.order-card').length===5);
  for(const size of [10,20,5]){await page.locator('[data-order-size]').first().selectOption(String(size));await page.waitForFunction(n=>document.querySelectorAll('.order-card').length===n,size);assert.equal(await page.evaluate(()=>localStorage.getItem('quote-order-page-size-v2')),String(size));}
  await page.evaluate(()=>{failNext=true;return OrderCenter.load()});assert((await page.locator('#orderList').textContent()).includes('模拟断网'));await page.click('[data-order-retry]');await page.waitForFunction(()=>document.querySelectorAll('.order-card').length===5);
  const layout=await page.locator('#orderList').evaluate(el=>({max:getComputedStyle(el).maxHeight,overflow:getComputedStyle(el).overflowY,clipped:el.scrollHeight>el.clientHeight+2}));assert.deepEqual(layout,{max:'none',overflow:'visible',clipped:false});
  const overflow=await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth+2);assert(!overflow,'No horizontal overflow at '+width);
  if(process.env.ORDER_PAGE_SCREENSHOTS)await page.screenshot({path:process.env.ORDER_PAGE_SCREENSHOTS+'/order-page-'+width+'.png'});
  assert.deepEqual(errors,[]);await page.close();
 }
 console.log('Order page browser: 390/768/1280, 5/10/20 rows, remembered choice, no internal scroll, boundaries, totals, search and retry passed.');
}finally{await browser.close();}})().catch(e=>{console.error(e);process.exitCode=1});
