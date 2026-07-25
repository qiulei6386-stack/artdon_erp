(() => {
  document.querySelectorAll('[data-ui-table]').forEach(table => {
    const rows = [...table.tBodies[0]?.rows || []];
    const size = Number(table.dataset.pageSize || 20);
    if (rows.length <= size) return;
    let page = 0;
    const pages = Math.ceil(rows.length / size);
    const bar = document.createElement('nav');
    bar.className = 'ui-pagination';
    bar.setAttribute('aria-label', '表格分页');
    bar.innerHTML = '<span data-page-info></span><button class="ui-btn ui-btn-secondary ui-btn-sm" type="button" data-page-prev>上一页</button><button class="ui-btn ui-btn-secondary ui-btn-sm" type="button" data-page-next>下一页</button>';
    table.closest('.ui-table-panel')?.append(bar);
    const render = () => {
      rows.forEach((row, i) => { row.hidden = i < page * size || i >= (page + 1) * size; });
      bar.querySelector('[data-page-info]').textContent = `${page + 1} / ${pages} 页 · 共 ${rows.length} 条`;
      bar.querySelector('[data-page-prev]').disabled = page === 0;
      bar.querySelector('[data-page-next]').disabled = page === pages - 1;
    };
    bar.addEventListener('click', event => {
      if (event.target.closest('[data-page-prev]') && page > 0) page--;
      if (event.target.closest('[data-page-next]') && page < pages - 1) page++;
      render();
    });
    render();
  });
})();
