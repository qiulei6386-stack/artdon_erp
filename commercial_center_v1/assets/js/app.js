document.documentElement.classList.add('cc-js-ready');

const shell = document.querySelector('[data-shell]');
const toggle = document.querySelector('[data-sidebar-toggle]');
const storageKey = 'cc_v1_sidebar_collapsed';

if (shell && window.localStorage.getItem(storageKey) === '1') {
  shell.classList.add('collapsed');
}
if (shell && toggle) {
  toggle.addEventListener('click', () => {
    shell.classList.toggle('collapsed');
    window.localStorage.setItem(storageKey, shell.classList.contains('collapsed') ? '1' : '0');
  });
}

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
