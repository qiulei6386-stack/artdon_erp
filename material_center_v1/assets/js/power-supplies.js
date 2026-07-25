(() => {
  const content = document.querySelector('[data-detail-content]');
  const search = document.querySelector('#power-search');
  const clear = document.querySelector('[data-search-clear]');
  const show = row => {
    let detail = {};
    try { detail = JSON.parse(row.dataset.detail || '{}'); } catch (_) {}
    content.innerHTML = Object.entries(detail).map(([key, value]) => `<div><dt>${escapeHtml(key)}</dt><dd>${escapeHtml(value || '—')}</dd></div>`).join('');
    window.ArtdonUI.drawer.open('#power-detail', row.querySelector('.ui-link-button') || row);
  };
  document.addEventListener('click', event => {
    if (event.target.closest('[data-ui-row-select]')) return;
    const row = event.target.closest('tr[data-detail]');
    if (row) show(row);
  });
  document.addEventListener('keydown', event => {
    const row = event.target.closest?.('tr[data-detail]');
    if (row && (event.key === 'Enter' || event.key === ' ')) { event.preventDefault(); show(row); }
  });
  clear?.addEventListener('click', () => { search.value = ''; search.focus(); });
  let timer;
  search?.addEventListener('input', () => {
    clearTimeout(timer);
    timer = setTimeout(() => { if (search.value === '' || search.value.trim().length >= 2) search.form.requestSubmit(); }, 300);
  });
  function escapeHtml(value) { return String(value).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c])); }
})();
