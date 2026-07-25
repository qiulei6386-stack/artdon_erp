(() => {
  const content = document.querySelector('[data-detail-content]');
  const search = document.querySelector('#material-search');
  const clear = document.querySelector('[data-search-clear]');

  document.addEventListener('click', event => {
    const row = event.target.closest('tr[data-detail]');
    if (!row || !content) return;
    showDetail(row);
  });
  document.addEventListener('keydown', event => {
    const row = event.target.closest?.('tr[data-detail]');
    if (row && (event.key === 'Enter' || event.key === ' ')) {
      event.preventDefault();
      showDetail(row);
    }
  });
  clear?.addEventListener('click', () => {
    search.value = '';
    search.focus();
  });
  let timer;
  search?.addEventListener('input', () => {
    window.clearTimeout(timer);
    timer = window.setTimeout(() => {
      if (search.value.trim().length >= 2 || search.value === '') search.form.requestSubmit();
    }, 300);
  });

  function showDetail(row) {
    let detail = {};
    try { detail = JSON.parse(row.dataset.detail || '{}'); } catch (_) {}
    content.innerHTML = Object.entries(detail).map(([key, value]) =>
      `<div><dt>${escapeHtml(key)}</dt><dd>${escapeHtml(value || '—')}</dd></div>`
    ).join('');
    window.ArtdonUI.drawer.open('#material-detail', row.querySelector('button') || row);
  }
  function escapeHtml(value) {
    return String(value).replace(/[&<>"']/g, char => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[char]));
  }
})();
