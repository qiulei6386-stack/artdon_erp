(() => {
  const drawer = document.querySelector('[data-drawer]');
  const mask = document.querySelector('.mask');
  const content = document.querySelector('[data-detail-content]');
  const close = () => {
    drawer?.classList.remove('open');
    mask?.classList.remove('open');
    drawer?.setAttribute('aria-hidden', 'true');
  };
  document.addEventListener('click', (event) => {
    const trigger = event.target.closest('[data-detail]');
    if (trigger && drawer && content) {
      let detail = {};
      try { detail = JSON.parse(trigger.dataset.detail || '{}'); } catch (_) {}
      content.innerHTML = Object.entries(detail).map(([key, value]) =>
        `<div><dt>${escapeHtml(key)}</dt><dd>${escapeHtml(value ?? '—')}</dd></div>`
      ).join('');
      drawer.classList.add('open');
      mask?.classList.add('open');
      drawer.setAttribute('aria-hidden', 'false');
      return;
    }
    if (event.target.closest('[data-close]')) close();
  });
  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape') close();
  });
  function escapeHtml(value) {
    return String(value).replace(/[&<>"']/g, char => ({
      '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;'
    })[char]);
  }
})();
