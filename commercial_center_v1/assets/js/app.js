document.documentElement.classList.add('cc-js-ready');

const shell = document.querySelector('[data-shell]');
const toggle = document.querySelector('[data-sidebar-toggle]');
const storageKey = 'cc_v1_sidebar_collapsed';

if (shell && window.localStorage.getItem(storageKey) === '1') {
  shell.classList.add('collapsed');
}
if (shell && toggle) {
  toggle.addEventListener('click', () => {
    if (window.matchMedia('(max-width: 760px)').matches) {
      shell.classList.toggle('mobile-open');
      return;
    }
    shell.classList.toggle('collapsed');
    window.localStorage.setItem(storageKey, shell.classList.contains('collapsed') ? '1' : '0');
  });
}

document.querySelectorAll('[data-nav-group]').forEach((button) => {
  button.addEventListener('click', () => button.closest('.nav-group')?.classList.toggle('closed'));
});
document.addEventListener('click', (event) => {
  if (!shell || !shell.classList.contains('mobile-open')) return;
  if (!event.target.closest('[data-sidebar]') && !event.target.closest('[data-sidebar-toggle]')) {
    shell.classList.remove('mobile-open');
  }
});

const drawer = document.querySelector('[data-detail-drawer]');
const detailContent = document.querySelector('[data-detail-content]');
document.querySelectorAll('[data-catalog-detail]').forEach((button) => {
  button.addEventListener('click', () => {
    if (!drawer || !detailContent) return;
    let detail = {};
    try { detail = JSON.parse(button.dataset.catalogDetail || '{}'); } catch (error) { detail = {}; }
    detailContent.replaceChildren();
    Object.entries(detail).forEach(([label, value]) => {
      const term = document.createElement('dt');
      const description = document.createElement('dd');
      term.textContent = label;
      description.textContent = String(value ?? '');
      detailContent.append(term, description);
    });
    drawer.classList.add('open');
    drawer.setAttribute('aria-hidden', 'false');
    document.body.classList.add('drawer-open');
  });
});
document.querySelectorAll('[data-detail-close]').forEach((button) => {
  button.addEventListener('click', () => {
    if (!drawer) return;
    drawer.classList.remove('open');
    drawer.setAttribute('aria-hidden', 'true');
    document.body.classList.remove('drawer-open');
  });
});

const productLibrary = document.querySelector('.product-library-grid');
if (productLibrary && new URLSearchParams(window.location.search).get('mode') === 'list') {
  productLibrary.classList.add('is-list');
}
const productSearchForm = document.querySelector('[data-product-search-form]');
if (productSearchForm) {
  const productSearchInput = productSearchForm.querySelector('input[name="q"]');
  let productSearchTimer;
  productSearchInput?.addEventListener('input', () => {
    window.clearTimeout(productSearchTimer);
    productSearchTimer = window.setTimeout(() => productSearchForm.requestSubmit(), 450);
  });
}
if (productLibrary && productSearchForm && !productLibrary.classList.contains('is-list')) {
  const autoPageSizeInput = productSearchForm.querySelector('[data-auto-page-size]');
  let autoPageSizeTimer;
  const syncProductPageSize = () => {
    const tracks = window.getComputedStyle(productLibrary).gridTemplateColumns
      .split(/\s+/).filter(Boolean).length;
    if (!tracks || !autoPageSizeInput) return;
    const desiredSize = Math.max(2, Math.min(100, tracks * 2));
    if (Number(autoPageSizeInput.value) === desiredSize) return;
    const url = new URL(window.location.href);
    const previousSize = Math.max(1, Number(autoPageSizeInput.value) || desiredSize);
    const previousPage = Math.max(1, Number(url.searchParams.get('p')) || 1);
    const firstVisibleOffset = (previousPage - 1) * previousSize;
    url.searchParams.set('size', String(desiredSize));
    url.searchParams.set('p', String(Math.floor(firstVisibleOffset / desiredSize) + 1));
    window.location.replace(url.toString());
  };
  const scheduleProductPageSizeSync = () => {
    window.clearTimeout(autoPageSizeTimer);
    autoPageSizeTimer = window.setTimeout(syncProductPageSize, 250);
  };
  if ('ResizeObserver' in window) {
    new ResizeObserver(scheduleProductPageSizeSync).observe(productLibrary);
  } else {
    window.addEventListener('resize', scheduleProductPageSizeSync);
    window.requestAnimationFrame(syncProductPageSize);
  }
}
document.addEventListener('DOMContentLoaded',function(){document.querySelectorAll('.product-library-grid .library-card p').forEach(function(el){var parts=el.textContent.split(' · BOM成本 ');if(parts.length<2)return;el.textContent='';el.append(document.createTextNode(parts[0]+' · '));var s=document.createElement('strong');s.className='library-bom-cost';s.textContent='BOM成本 '+parts.slice(1).join(' · BOM成本 ');el.append(s);});});
document.addEventListener('click',function(e){var card=e.target.closest('[data-product-detail]');if(!card||e.target.closest('button,a'))return;var d=document.querySelector('[data-detail-drawer]');if(d){d.classList.add('open');d.setAttribute('aria-hidden','false');document.body.classList.add('drawer-open')}});
document.addEventListener('click',function(e){var button=e.target.closest('.library-card .text-button');if(!button)return;var card=button.closest('[data-product-detail]');var d=document.querySelector('[data-detail-drawer]');if(card&&d){e.preventDefault();d.classList.add('open');d.setAttribute('aria-hidden','false');document.body.classList.add('drawer-open')}});
document.addEventListener('click',function(e){
  var card=e.target.closest('[data-product-detail]');
  if(!card)return;
  var d=document.querySelector('[data-detail-drawer]'),c=document.querySelector('[data-detail-content]');
  if(!d||!c)return;
  var esc=function(value){return String(value??'').replace(/[&<>"']/g,function(ch){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[ch]})};
  var config={};
  try{config=JSON.parse(card.dataset.productConfig||'{}')}catch(error){config={}};
  var technical=config.technical||{},groups=Array.isArray(config.groups)?config.groups:[];
  var groupHtml=groups.map(function(group){
    var values=Array.isArray(group.values)&&group.values.length?group.values.join('；'):'—';
    return '<span><b>'+esc(group.name||group.code||'配置')+'：</b>'+esc(values)+'</span>';
  }).join('');
  if(!groupHtml)groupHtml='<span>尚无已发布配置</span>';
  var image=card.querySelector('img')?.getAttribute('src')||'',model=card.dataset.model||'';
  var meta=card.querySelector('small')?.textContent||'',metaParts=meta.split(' · ');
  var price=card.querySelector('.product-price')?.textContent||'参考报价：—';
  var status=(card.querySelector('footer span')?.textContent||'状态：—').replace(/^状态：/,'');
  c.innerHTML='<div class="drawer-product-head">'+(image?'<img src="'+esc(image)+'" alt="">':'')+'<div><h3>'+esc(model)+'</h3><p>'+esc(meta)+'</p><span class="tag">'+esc(status)+'</span></div></div>'+
    '<section class="drawer-section"><h4><i>1</i>基础资料</h4><dl><dt>型号</dt><dd>'+esc(model)+'</dd><dt>系列</dt><dd>'+esc(metaParts[0]||'—')+'</dd><dt>类别</dt><dd>'+esc(metaParts[1]||'—')+'</dd><dt>状态</dt><dd class="drawer-ok">'+esc(status)+'</dd></dl></section>'+
    '<section class="drawer-section"><h4><i>2</i>尺寸与参数</h4><dl><dt>尺寸</dt><dd>'+esc((card.querySelector('.product-size')||{}).textContent||'—')+'</dd><dt>功率</dt><dd>'+esc(technical.power||'—')+'</dd><dt>光束角</dt><dd>'+esc(technical.beam_angle||'—')+'</dd><dt>色温</dt><dd>'+esc(technical.cct||'—')+'</dd><dt>显色指数</dt><dd>'+esc(technical.cri||'—')+'</dd><dt>输出电流</dt><dd>'+esc(technical.current||'—')+'</dd><dt>防护等级</dt><dd>'+esc(technical.ip_rating||'—')+'</dd></dl></section>'+
    '<section class="drawer-section"><h4><i>3</i>报价信息</h4><dl><dt>参考报价</dt><dd class="drawer-price">'+esc(price)+'</dd><dt>币种</dt><dd>USD</dd><dt>MOQ</dt><dd>—</dd><dt>交期</dt><dd>—</dd></dl></section>'+
    '<section class="drawer-section"><h4><i>4</i>已发布配置'+(config.version?' · '+esc(config.version):'')+'</h4><div class="drawer-tags">'+groupHtml+'</div></section>'+
    '<section class="drawer-section"><h4><i>5</i>资料文件</h4><div class="drawer-files"><span>产品图</span><span>尺寸图</span><span>IES 文件</span><span>测试报告</span></div></section><footer class="drawer-actions"><button class="primary">加入报价</button><button type="button" data-detail-close>关闭</button></footer>';
  d.classList.add('open');d.setAttribute('aria-hidden','false');document.body.classList.add('drawer-open');
});
document.addEventListener('click',function(e){
  if(!e.target.closest('[data-detail-close]'))return;
  var d=document.querySelector('[data-detail-drawer]');
  if(!d)return;
  d.classList.remove('open');d.setAttribute('aria-hidden','true');document.body.classList.remove('drawer-open');
});
