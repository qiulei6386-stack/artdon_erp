const fs=require('node:fs'),vm=require('node:vm'),assert=require('node:assert/strict');
const source=fs.readFileSync('quotation.php','utf8'),js=fs.readFileSync('assets/quote-display-text.js','utf8');
new vm.Script(js);
function original(name){const a=source.indexOf('function '+name+'('),end=source.slice(a+1).search(/\n(?:async )?function /);assert(a>=0&&end>0,name);return source.slice(a,a+1+end);}
const functions=['quoteDisplayCanEdit','quoteDisplayDescriptionLines','quoteDisplayDescription','quotePrepareDisplayItem','quoteDisplayManualSpec','normCct','normCri','normIp','cleanPowerValue','formatPowerForSpec','cleanBeamAngleValue','formatBeamAngleForSpec','normalizeBeamAngleField','quoteRemarksOfItem','quoteRemarksFromForm','setQuoteRemarksToForm','bind','isEmptyParam','cleanParam','specLabel','quotePartStopWords','quoteDropMaterialNameTokens','quoteDisplayNorm','quoteBrandCandidate','quoteJoinBrandModel','quoteModelFromAsciiText','quoteBrandFromAsciiText','quoteBrandModelOnly','productQuoteSpec','ledLineValue','partDefsByType','buildSpec','currentEditorItem','loadItemToEditor','syncEditingItemFromForm'];
const originals=functions.map(original).join('\n');new vm.Script(originals);
const pure={quoteOrderSpec:require('../assets/quote-spec-order.js'),isMaterialSaleItem:()=>false,isVirtualQuoteItem:()=>false,clone:x=>JSON.parse(JSON.stringify(x)),quoteMoneyRow:(q,p)=>q*p};vm.createContext(pure);vm.runInContext(originals+'\n'+original('piOrderItemFromQuote')+'\n'+original('mergeOrderItemWithQuoteSnapshot'),pure);
assert.equal(pure.quoteDisplayDescription('1. 52.98765 Test lamp',{}),'52.98765 Test lamp','numbering cleanup preserves decimal product model');
for(const fixture of JSON.parse(fs.readFileSync('tests/fixtures/quote-display-parameters.json','utf8'))){const before=JSON.stringify(fixture.item);assert.equal(pure.buildSpec(fixture.item),fixture.expected,fixture.name);assert.equal(JSON.stringify(fixture.item),before,'render does not mutate saved source');}
// Exercise the real automatic branch with deliberately shuffled component insertion order.
pure.productSeriesLine=p=>p.series||p.name||'';pure.quoteDisplaySize=p=>p.size||'';pure.quoteDisplayCutout=()=>'';
const automatic={product:{series:'Fixture lamp',power:'10W',size:'220*22',quote_spec:{driver:{label:'LED Driver',value:'LIFUD LF-GDE020'},led:{label:'LED',value:'TEST-2835'},optic:{label:'Optic',value:'OPAL'}}},parts:{},product_type:'track',beam_angle:'90',cct:'3000K',cri:'CRI90',ip:'IP20'};
const autoBefore=JSON.stringify(automatic);
assert.equal(pure.buildSpec(automatic),'1. Fixture lamp\n2. Power: 10W\n3. Size: 220*22\n4. Beam Angle: 90°\n5. Optic: OPAL\n6. LED: TEST-2835 3000K CRI90\n7. LED Driver: LIFUD GDE020\n8. IP20');
assert.equal(JSON.stringify(automatic),autoBefore,'automatic rendering does not mutate item or component order');
// A historical string can contain manual values which differ from old structured fields.
const savedText={product:{series:'Old series',power:'999W',size:'999*99'},power:'999W',specification:'1. Customer supplied lamp\n2. Size: 220*22\n3. IP20\n4. Power: 14W',qty:2,price:30};
const savedBefore=JSON.stringify(savedText);
assert.equal(pure.buildSpec(savedText),'1. Customer supplied lamp\n2. Power: 14W\n3. Size: 220*22\n4. IP20');
assert.equal(JSON.stringify(savedText),savedBefore,'historical manual values and snapshot remain unchanged');
const freshParameters={product:{series:'Old series',size:'220*22'},parts:{},product_type:'track',power:'25W'};
assert(pure.buildSpec(freshParameters).includes('Power: 25W'),'fresh editor parameters without saved specification keep updating live');
// Run the real restore handler directly with non-empty manual/historical wording.
const applyStart=js.indexOf('  function apply(restore){'),applyEnd=js.indexOf('\n  return {open,close};',applyStart);
assert(applyStart>=0&&applyEnd>applyStart,'restore handler boundaries');
for(const hasOverride of [true,false]){
 const item=JSON.parse(JSON.stringify(automatic));item.specification='1. Customer manual wording\n2. Power: 14W';item.qty=2;item.price=30;item.amount=60;
 if(hasOverride)item.product.quote_spec_display_override=item.specification;
 const ctx={S:{product:JSON.parse(JSON.stringify(item.product)),items:[item],editingIndex:0},field:'quote_spec_display_override',same:()=>true,locked:()=>false,$:()=>({value:'Customer manual wording'}),currentEditorItem:()=>({}),quoteDisplayDescription:()=>'',buildSpec:pure.buildSpec,close:()=>{},updateProductLinkHint:()=>{},renderQuoteItems:()=>{},render:()=>{},alert:m=>{throw Error(m)}};
 vm.createContext(ctx);vm.runInContext(js.slice(applyStart,applyEnd),ctx);ctx.apply(true);
 assert(!item.specification.includes('Customer manual wording'),'direct restore replaces non-empty manual text');
 assert(item.specification.includes('Power: 10W'),'direct restore rebuilds automatic parameters');
 assert.equal(item.price,30);assert.equal(item.qty,2);assert.equal(item.amount,60);
 assert.equal(Object.hasOwn(item.product,'quote_spec_display_override'),false);
}
const sourceItem={product:{quote_spec_display_override:''},specification:'STALE',extra_spec:'OLD',qty:1,price:2};
assert.equal(pure.buildSpec(sourceItem),'');assert.equal(pure.piOrderItemFromQuote(sourceItem).specification,'','empty text stays empty at conversion');
for(const text of ['New order wording','']){const item=pure.mergeOrderItemWithQuoteSnapshot({specification:text},sourceItem,0);assert.equal(pure.buildSpec(item),text,'order edits have priority over source quote text');}
assert(source.includes('onclick="QuoteDisplayText.open()"'));
assert(source.includes('items_json:JSON.stringify(items)'));
assert(original('selectProduct').includes('delete S.product.quote_spec_display_override;delete S.product.quote_spec_display_mode;'),'fresh product selection discards any cached quotation wording');
assert(!/\b(fetch|api)\s*\(/.test(js),'text editor never writes master-data APIs');
if(process.env.QUOTE_DISPLAY_BROWSER_TEST!=='1'){console.log('Quote display text syntax/integration passed; browser opt-in available.');process.exit(0);}
const {chromium}=require(process.env.PLAYWRIGHT_MODULE||'playwright');
(async()=>{const browser=await chromium.launch({headless:true,executablePath:process.env.CHROME_BIN});try{
 for(const width of [390,768,1440]){
  const page=await browser.newPage({viewport:{width,height:850}}),errors=[];page.on('pageerror',e=>errors.push(e.message));
  const ids=['qty','manualPrice','moq','customerCode','beamAngle','power','color','cct','cri','ip','extraSpec','priceMultiplierCustom','productType','productSelect','priceLevel','remark1','remark2','remark3','remark4'];
  await page.route('**/*',r=>r.request().url()==='https://quote-display.test/'?r.fulfill({contentType:'text/html',body:'<!doctype html><meta charset="utf-8"><style>'+fs.readFileSync('assets/quote-display-text.css','utf8')+'</style><div id="productLinkHint"><button id="edit" onclick="QuoteDisplayText.open()">修改报价文字</button></div>'+ids.map(id=>`<input id="${id}">`).join('')+'<pre id="preview"></pre>'}):r.abort());
  await page.goto('https://quote-display.test/');
  await page.addScriptTag({content:fs.readFileSync('assets/quote-spec-order.js','utf8')});
  await page.addScriptTag({content:`const $=id=>document.getElementById(id),clone=x=>JSON.parse(JSON.stringify(x)),esc=x=>String(x??'').replaceAll('&','&amp;').replaceAll('<','&lt;').replaceAll('>','&gt;').replaceAll('"','&quot;');
  const master={id:'TEST',code:'52.98765',name:'Test lamp',cost_rmb:50,quote_spec:{optic:{label:'Optic',value:'反光纸内部规格'}},bom_version:{snapshot_id:-1,digest:'immutable'}};
  let S={product:null,items:[{product:clone(master),qty:2,price:30,amount:60,parts:{},product_type:'track'},{product:clone(master),qty:1,price:30,parts:{}}],editingIndex:0,currentQuoteId:0,currentApprovalStatus:'new'},allowed=true,QUOTE_PRICE_POLICY_MATCH=null;
  function scheduleQuotePricePolicyRefresh(){}function hasPerm(){return allowed}function isMaterialSaleItem(x){return !!x?.is_material_sale}function isVirtualQuoteItem(){return false}function materialSaleSpec(it){return it.product.name}function productSeriesLine(p){return p.name}function quoteDisplaySize(p){return p.size||''}function quoteDisplayCutout(){return ''}function formatPowerForSpec(x){return x}function formatBeamAngleForSpec(x){return x}function normIp(x){return x}function normCct(x){return x}function normCri(x){return x}function quoteRemarksOfItem(it){return it.quote_remarks||[]}function quoteRemarksFromForm(){return []}function quotePartSpecName(k,m){return m.name}function cleanBeamAngleValue(x){return x}function cur(){return 'USD'}function currentEditorBaseCost(){return 50}function priceMultiplier(){return 1.3}function quotePolicyPriceInCurrentCurrency(){return null}function quotePricePolicySnapshot(){return null}function selectedPriceLevel(){return {multiplier:1.3}}function quoteMoneyToRmb(x){return x}function clampCompactInputs(){}function renderProductSelect(){}function syncProductSearchFromSelect(){}function fillOptionSelect(id,a,v){$(id).value=v}function colorItems(){return []}function setQuoteRemarksToForm(){}function updatePriceLevelHint(){}function renderParts(){}function renderQuoteItems(){}function updateProductLinkHint(){}function money(x){return Number(x).toFixed(2)}function render(){$('preview').textContent=buildSpec(S.items[S.editingIndex]||currentEditorItem())}function autoAddSelectedProductLine(){S.items.push(currentEditorItem());S.editingIndex=S.items.length-1}
  `+originals});
  await page.addScriptTag({content:js});await page.evaluate(()=>{loadItemToEditor(S.items[0],0);bind()});
  const originalSource=await page.evaluate(()=>JSON.stringify({product:S.product,other:S.items[1],master}));
  await page.click('#edit');assert((await page.inputValue('#qdtText')).includes('反光纸'));
  const text='1. Customer lamp\n2. Optic: Opal diffuser <img src=x onerror=alert(1)>';
  await page.fill('#qdtText',text);await page.click('[data-qdt-apply]');assert.equal(await page.textContent('#preview'),text);
  assert.equal(await page.evaluate(()=>{const product=clone(S.product);delete product.quote_spec_display_override;delete product.quote_spec_display_mode;return JSON.stringify({product,other:S.items[1],master});}),originalSource);
  assert.equal(await page.evaluate(()=>S.items[0].price),30,'display edits do not recalculate prices');
  await page.evaluate(()=>{$('qty').value=3;syncEditingItemFromForm();S.items=JSON.parse(JSON.stringify(S.items));loadItemToEditor(S.items[0],0)});
  assert.equal(await page.textContent('#preview'),text,'form update + saved JSON reload preserves manual text');
  await page.click('#edit');assert.equal(await page.inputValue('#qdtText'),text.replace(/^\d+\. /gm,''));await page.fill('#qdtText','');await page.click('[data-qdt-apply]');
  assert.equal(await page.textContent('#preview'),'','empty text must not fall back');
  await page.evaluate(()=>{S.items=JSON.parse(JSON.stringify(S.items));loadItemToEditor(S.items[0],0)});assert.equal(await page.textContent('#preview'),'');
  await page.click('#edit');await page.click('[data-qdt-restore]');assert((await page.textContent('#preview')).includes('反光纸'));assert.equal(await page.evaluate(()=>Object.hasOwn(S.items[0].product,'quote_spec_display_override')),false);
  await page.click('#edit');await page.fill('#qdtText','Cancelled');await page.click('[data-qdt-close]');assert((await page.textContent('#preview')).includes('反光纸'));
  await page.evaluate(()=>S.currentApprovalStatus='approved');await page.click('#edit');assert(await page.locator('#qdtText').getAttribute('readonly')!==null);assert(await page.locator('[data-qdt-apply]').isDisabled());await page.click('[data-qdt-close]');
  await page.evaluate(()=>{S.currentApprovalStatus='pending';S.currentQuoteId=10;S.bomSentLocked=true});await page.click('#edit');assert(await page.locator('[data-qdt-apply]').isDisabled());await page.click('[data-qdt-close]');
  await page.evaluate(()=>{S.bomSentLocked=false;allowed=false});await page.click('#edit');assert(await page.locator('[data-qdt-restore]').isDisabled());await page.click('[data-qdt-close]');
  await page.evaluate(()=>allowed=true);await page.click('#edit');await page.fill('#qdtText','Stale edit');await page.evaluate(()=>{S.product=clone(master);S.editingIndex=1});page.once('dialog',d=>d.dismiss());await page.click('[data-qdt-apply]');assert.equal(await page.evaluate(()=>Object.hasOwn(S.items[1].product,'quote_spec_display_override')),false);
  await page.evaluate(()=>{S.items[1].is_material_sale=true;loadItemToEditor(S.items[1],1)});await page.click('#edit');await page.fill('#qdtText','Quoted material description');await page.click('[data-qdt-apply]');await page.evaluate(()=>syncEditingItemFromForm());assert.equal(await page.evaluate(()=>buildSpec(S.items[1])),'1. Quoted material description');
  // Reproduce the reported edit-then-fill sequence through the page's real input listeners.
  await page.evaluate(()=>{S.currentApprovalStatus='pending';S.bomSentLocked=false;S.currentQuoteId=20;S.items[0]={product:{...clone(master),power:'10W',quote_spec_display_override:'1. Test lamp\n2. LED: TEST-2835 3000K CRI80\n3. Power: 10W\n4. Beam Angle: 24°\n5. IP44\n6. Old note'},parts:{},qty:10,price:15,manual_price:true,power:'10W',beam_angle:'24',cct:'3000K',cri:'CRI80',ip:'IP44',quote_remarks:['Old note']};loadItemToEditor(S.items[0],0)});
  for(const [id,value] of Object.entries({power:'25W',beamAngle:'120',cct:'5000K',cri:'CRI90',ip:'IP65',remark1:'New note'}))await page.fill('#'+id,value);
  let preview=await page.textContent('#preview');for(const text of ['Power: 25W','Beam Angle: 120°','LED: TEST-2835 5000K CRI90','IP65','New note'])assert(preview.includes(text),text);
  for(const text of ['10W','24°','3000K','CRI80','IP44','Old note','反光纸'])assert(!preview.includes(text),'stale text '+text);
  assert.equal(await page.evaluate(()=>S.items[0].price),15);assert.equal(await page.evaluate(()=>S.items[0].product.cost_rmb),50);
  await page.evaluate(()=>{S.items=JSON.parse(JSON.stringify(S.items));loadItemToEditor(S.items[0],0)});assert.equal(await page.textContent('#preview'),preview,'edited parameters survive saved JSON reload');
  await page.click('#edit');assert(!(await page.inputValue('#qdtText')).includes('5000K'),'editor separates automatic parameters');await page.click('[data-qdt-close]');
  for(const id of ['power','beamAngle','cct','cri','ip','remark1'])await page.fill('#'+id,'');
  assert.equal(await page.textContent('#preview'),'1. Test lamp\n2. LED: TEST-2835','clearing form removes old values');
  await page.evaluate(()=>{S.items=JSON.parse(JSON.stringify(S.items));loadItemToEditor(S.items[0],0)});assert.equal(await page.inputValue('#power'),'','blank power does not revive product default');
  const frozen=await page.evaluate(()=>{S.currentApprovalStatus='approved';S.items[0].product.quote_spec_display_override='1. Frozen text';delete S.items[0].product.quote_spec_display_mode;loadItemToEditor(S.items[0],0);return S.items[0].product});assert(!frozen.quote_spec_display_mode);assert.equal(await page.textContent('#preview'),'1. Frozen text');
  await page.click('#edit');assert(!(await page.locator('.qdt-dialog').evaluate(e=>e.scrollWidth>e.clientWidth+2)),'responsive width '+width);
  if(process.env.QUOTE_DISPLAY_SCREENSHOTS)await page.screenshot({path:process.env.QUOTE_DISPLAY_SCREENSHOTS+'/display-'+width+'.png'});
  await page.keyboard.press('Escape');assert.equal(await page.locator('#quoteDisplayText').count(),0);assert.deepEqual(errors,[]);await page.close();
 }
 console.log('Quote display browser passed: edit/delete/restore, JSON reload, row/master isolation, price preservation, material quote row, locks, stale editor, XSS text and responsive layouts.');
}finally{await browser.close();}})().catch(e=>{console.error(e);process.exitCode=1});
