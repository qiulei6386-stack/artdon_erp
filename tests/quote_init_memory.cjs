const fs=require('node:fs'),vm=require('node:vm'),assert=require('node:assert/strict');
const src=fs.readFileSync(__dirname+'/../quotation.php','utf8');
const a=src.indexOf('async function quoteAppendMaterialItem('),b=src.indexOf('\nfunction clone(',a);assert(a>0&&b>a);
const source=src.slice(a,b),defer=()=>{let resolve,reject;const promise=new Promise((r,j)=>{resolve=r;reject=j});return {promise,resolve,reject}};
function setup(api){const c=vm.createContext({S:{items:[]},quoteMaterialPending:new Map(),cur:()=> 'RMB',rate:()=>7,api,renderQuoteItems:()=>{},render:()=>{},alert:()=>{}});vm.runInContext(source,c);return c;}
(async()=>{
 const d=defer();let calls=0;let c=setup(async()=>{calls++;return d.promise});const item={product:{}},mat={id:3,image_deferred:true};const one=c.quoteAppendMaterialItem(item,mat);await c.quoteAppendMaterialItem(item,mat);assert.equal(calls,1);assert.equal(c.S.items.length,0);d.resolve({id:'3',image:'data:image/png;base64,AA=='});await one;assert.equal(c.S.items[0].product.image,'data:image/png;base64,AA==');
 c=setup(async()=>({id:'wrong',image:'bad'}));await c.quoteAppendMaterialItem({product:{}},mat);assert.equal(c.S.items.length,0);
 const late=defer();c=setup(()=>late.promise);const adding=c.quoteAppendMaterialItem({product:{}},mat);c.S.items=[];late.resolve({id:'3',image:'old'});await adding;assert.equal(c.S.items.length,0);
 c=setup(async()=>{throw Error('failure')});await c.quoteAppendMaterialItem({product:{}},mat);assert.equal(c.S.items.length,0);assert.equal(c.quoteMaterialPending.size,0);
 c=setup(()=>{throw Error('unnecessary fetch')});await c.quoteAppendMaterialItem({product:{image:'existing'}},{id:4,image:'existing'});assert.equal(c.S.items[0].product.image,'existing');
 console.log('Quote image lazy-load: original embedded image preserved, duplicate click, stale quote, wrong ID, failure/retry and existing image OK');
})().catch(e=>{console.error(e);process.exitCode=1});
