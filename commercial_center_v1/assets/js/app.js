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
if (productLibrary && !productLibrary.classList.contains('is-list')) {
  const fitProductGrid = () => {
    const count = productLibrary.querySelectorAll('.library-card').length;
    if (!count) return;
    const gap = 12;
    const minCardWidth = window.innerWidth <= 760 ? 142 : 168;
    let columns = Math.max(1, Math.min(count, Math.floor((productLibrary.clientWidth + gap) / (minCardWidth + gap))));
    while (columns > 2 && count > columns && count % columns === 1) columns -= 1;
    productLibrary.style.setProperty('--product-grid-columns', String(columns));
  };
  fitProductGrid();
  if ('ResizeObserver' in window) new ResizeObserver(fitProductGrid).observe(productLibrary);
  else window.addEventListener('resize', fitProductGrid);
}
document.addEventListener('DOMContentLoaded',function(){document.querySelectorAll('.product-library-grid .library-card p').forEach(function(el){var parts=el.textContent.split(' · BOM成本 ');if(parts.length<2)return;el.textContent='';el.append(document.createTextNode(parts[0]+' · '));var s=document.createElement('strong');s.className='library-bom-cost';s.textContent='BOM成本 '+parts.slice(1).join(' · BOM成本 ');el.append(s);});});
document.addEventListener('DOMContentLoaded',function(){var d=document.querySelector('[data-detail-drawer]'),c=document.querySelector('[data-detail-content]');document.querySelectorAll('.product-library-grid .library-card').forEach(function(card){card.addEventListener('click',function(e){if(e.target.closest('a,button'))return;if(!d||!c)return;var v=card.querySelector('h3')?.textContent||'';var s=card.querySelector('small')?.textContent||'';c.replaceChildren();[['型号',v],['系列/类别',s],['状态',card.querySelector('footer span')?.textContent||'']].forEach(function(x){var t=document.createElement('dt'),q=document.createElement('dd');t.textContent=x[0];q.textContent=x[1];c.append(t,q)});d.classList.add('open');d.setAttribute('aria-hidden','false');document.body.classList.add('drawer-open')})})});
document.addEventListener('DOMContentLoaded',function(){var d=document.querySelector('[data-detail-drawer]'),c=document.querySelector('[data-detail-content]');document.querySelectorAll('[data-product-detail]').forEach(function(card){card.addEventListener('click',function(){if(!d||!c)return;c.innerHTML='<dt>型号</dt><dd>'+card.dataset.model+'</dd><dt>基础资料</dt><dd>系列、类别、尺寸和参数</dd><dt>配置</dt><dd>光源、电源、安装方式、附件</dd><dt>资料</dt><dd>产品图、尺寸图、IES、测试报告</dd>';d.classList.add('open');d.setAttribute('aria-hidden','false');document.body.classList.add('drawer-open')})})});
document.addEventListener('DOMContentLoaded',function(){var d=document.querySelector('[data-detail-drawer]'),c=document.querySelector('[data-detail-content]');document.querySelectorAll('[data-product-detail]').forEach(function(card){card.addEventListener('click',function(){if(!d||!c)return;var img=card.querySelector('img')?.getAttribute('src')||'';var model=card.dataset.model||'';var meta=card.querySelector('small')?.textContent||'';var price=card.querySelector('.product-price')?.textContent||'参考报价：—';c.innerHTML='<div class="drawer-product-head">'+(img?'<img src="'+img+'" alt="">':'')+'<div><h3>'+model+'</h3><p>'+meta+'</p><span class="tag">可报价</span></div></div><section class="drawer-section"><h4><i>1</i>基础资料</h4><dl><dt>型号</dt><dd>'+model+'</dd><dt>系列</dt><dd>'+meta.split(' · ')[0]+'</dd><dt>类别</dt><dd>'+meta.split(' · ')[1]+'</dd><dt>状态</dt><dd class="drawer-ok">可报价</dd></dl></section><section class="drawer-section"><h4><i>2</i>尺寸与参数</h4><dl><dt>尺寸</dt><dd>'+((card.querySelector('.product-size')||{}).textContent||'—')+'</dd><dt>功率</dt><dd>—</dd><dt>光束角</dt><dd>—</dd><dt>输入电压</dt><dd>—</dd><dt>材质</dt><dd>—</dd></dl></section><section class="drawer-section"><h4><i>3</i>报价信息</h4><dl><dt>参考报价</dt><dd class="drawer-price">'+price+'</dd><dt>币种</dt><dd>USD</dd><dt>MOQ</dt><dd>—</dd><dt>交期</dt><dd>—</dd></dl></section><section class="drawer-section"><h4><i>4</i>配置选项</h4><div class="drawer-tags"><span>光源：—</span><span>驱动：—</span><span>电源：—</span><span>安装方式：—</span><span>附件：—</span></div></section><section class="drawer-section"><h4><i>5</i>资料文件</h4><div class="drawer-files"><span>产品图</span><span>尺寸图</span><span>IES 文件</span><span>测试报告</span></div></section><footer class="drawer-actions"><button class="primary">加入报价</button><button type="button" data-detail-close>关闭</button></footer>';d.classList.add('open');d.setAttribute('aria-hidden','false');document.body.classList.add('drawer-open')})})});
document.addEventListener('click',function(e){var card=e.target.closest('[data-product-detail]');if(!card||e.target.closest('button,a'))return;var d=document.querySelector('[data-detail-drawer]');if(d){d.classList.add('open');d.setAttribute('aria-hidden','false');document.body.classList.add('drawer-open')}});
document.addEventListener('click',function(e){var button=e.target.closest('.library-card .text-button');if(!button)return;var card=button.closest('[data-product-detail]');var d=document.querySelector('[data-detail-drawer]');if(card&&d){e.preventDefault();d.classList.add('open');d.setAttribute('aria-hidden','false');document.body.classList.add('drawer-open')}});
