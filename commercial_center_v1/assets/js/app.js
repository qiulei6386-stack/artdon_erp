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
document.addEventListener('DOMContentLoaded',function(){document.querySelectorAll('.product-library-grid .library-card p').forEach(function(el){var parts=el.textContent.split(' · BOM成本 ');if(parts.length<2)return;el.textContent='';el.append(document.createTextNode(parts[0]+' · '));var s=document.createElement('strong');s.className='library-bom-cost';s.textContent='BOM成本 '+parts.slice(1).join(' · BOM成本 ');el.append(s);});});
document.addEventListener('DOMContentLoaded',function(){var d=document.querySelector('[data-detail-drawer]'),c=document.querySelector('[data-detail-content]');document.querySelectorAll('.product-library-grid .library-card').forEach(function(card){card.addEventListener('click',function(e){if(e.target.closest('a,button'))return;if(!d||!c)return;var v=card.querySelector('h3')?.textContent||'';var s=card.querySelector('small')?.textContent||'';c.replaceChildren();[['型号',v],['系列/类别',s],['状态',card.querySelector('footer span')?.textContent||'']].forEach(function(x){var t=document.createElement('dt'),q=document.createElement('dd');t.textContent=x[0];q.textContent=x[1];c.append(t,q)});d.classList.add('open');d.setAttribute('aria-hidden','false');document.body.classList.add('drawer-open')})})});
