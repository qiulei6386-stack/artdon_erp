(() => {
  const registry = new Map();
  document.querySelectorAll('[data-ui-table]').forEach(enhance);

  function enhance(table) {
    const body = table.tBodies[0];
    if (!body) return;
    const state = { table, body, rows: [...body.rows], page: 0, size: Number(table.dataset.pageSize || 20), sortIndex: -1, direction: 1 };
    registry.set(`#${table.id}`, state);
    setupSelection(state);
    setupSorting(state);
    setupResize(state);
    renderPagination(state);
  }

  function setupSelection({ table, body }) {
    const all = table.querySelector('[data-ui-select-all]');
    const boxes = [...table.querySelectorAll('[data-ui-row-select]')];
    const sync = () => {
      const selected = boxes.filter(box => box.checked).length;
      if (all) { all.checked = selected === boxes.length && boxes.length > 0; all.indeterminate = selected > 0 && selected < boxes.length; }
      boxes.forEach(box => box.closest('tr')?.classList.toggle('is-selected', box.checked));
    };
    all?.addEventListener('change', () => { boxes.forEach(box => { if (!box.closest('tr').hidden) box.checked = all.checked; }); sync(); });
    body.addEventListener('change', event => { if (event.target.matches('[data-ui-row-select]')) sync(); });
  }

  function setupSorting(state) {
    state.table.querySelectorAll('th[data-sort]').forEach((th, index) => {
      const realIndex = th.cellIndex;
      th.tabIndex = 0;
      const sort = () => {
        state.direction = state.sortIndex === realIndex ? -state.direction : 1;
        state.sortIndex = realIndex;
        state.table.querySelectorAll('th[data-sort]').forEach(item => item.classList.remove('is-asc','is-desc'));
        th.classList.add(state.direction === 1 ? 'is-asc' : 'is-desc');
        state.rows.sort((a,b) => compare(a.cells[realIndex]?.textContent, b.cells[realIndex]?.textContent, th.dataset.sort === 'number') * state.direction);
        state.rows.forEach(row => state.body.append(row));
        state.page = 0; renderRows(state);
      };
      th.addEventListener('click', event => { if (!event.target.closest('.ui-resizer')) sort(); });
      th.addEventListener('keydown', event => { if (event.key === 'Enter') sort(); });
    });
  }

  function setupResize(state) {
    [...state.table.tHead.rows[0].cells].forEach(th => {
      if (th.classList.contains('ui-select-col')) return;
      const grip = document.createElement('span');
      grip.className = 'ui-resizer';
      grip.addEventListener('pointerdown', event => {
        event.preventDefault();
        const start = event.clientX, width = th.offsetWidth;
        state.table.classList.add('is-resizing');
        const move = e => { th.style.width = `${Math.max(56, width + e.clientX - start)}px`; th.style.minWidth = th.style.width; };
        const up = () => { state.table.classList.remove('is-resizing'); document.removeEventListener('pointermove', move); document.removeEventListener('pointerup', up); };
        document.addEventListener('pointermove', move); document.addEventListener('pointerup', up);
      });
      grip.addEventListener('dblclick', event => { event.stopPropagation(); th.style.width = ''; th.style.minWidth = ''; });
      th.append(grip);
    });
  }

  function renderPagination(state) {
    state.bar?.remove();
    state.pages = Math.max(1, Math.ceil(state.rows.length / state.size));
    const bar = document.createElement('nav');
    bar.className = 'ui-pagination'; bar.setAttribute('aria-label','表格分页'); state.bar = bar;
    bar.innerHTML = `<span>共 ${state.rows.length} 条</span><label>每页 <select class="ui-select ui-page-size" aria-label="每页条数"><option>10</option><option>20</option><option>50</option></select></label><button class="ui-btn ui-btn-secondary ui-btn-sm" type="button" data-page-prev>上一页</button><span data-page-numbers></span><button class="ui-btn ui-btn-secondary ui-btn-sm" type="button" data-page-next>下一页</button><label>跳至 <input class="ui-input ui-page-jump" type="number" min="1" value="1" aria-label="跳转页码"> 页</label>`;
    bar.querySelector('.ui-page-size').value = String(state.size);
    bar.addEventListener('change', event => {
      if (event.target.matches('.ui-page-size')) { state.size = Number(event.target.value); state.page = 0; renderPagination(state); }
      if (event.target.matches('.ui-page-jump')) { state.page = Math.max(0,Math.min(state.pages - 1,Number(event.target.value) - 1)); renderRows(state); }
    });
    bar.addEventListener('click', event => {
      if (event.target.closest('[data-page-prev]')) state.page = Math.max(0,state.page - 1);
      if (event.target.closest('[data-page-next]')) state.page = Math.min(state.pages - 1,state.page + 1);
      const page = event.target.closest('[data-page]');
      if (page) state.page = Number(page.dataset.page);
      renderRows(state);
    });
    state.table.closest('.ui-table-panel')?.append(bar);
    renderRows(state);
  }

  function renderRows(state) {
    state.rows.forEach((row,i) => { row.hidden = i < state.page * state.size || i >= (state.page + 1) * state.size; });
    const numbers = state.bar.querySelector('[data-page-numbers]'); numbers.innerHTML = '';
    const start = Math.max(0,state.page - 2), end = Math.min(state.pages,start + 5);
    for (let i=start;i<end;i++) { const b=document.createElement('button'); b.className='ui-btn ui-btn-secondary ui-btn-sm ui-page-number'; b.type='button'; b.dataset.page=String(i); b.textContent=String(i+1); if(i===state.page)b.setAttribute('aria-current','page'); numbers.append(b); }
    state.bar.querySelector('[data-page-prev]').disabled = state.page === 0;
    state.bar.querySelector('[data-page-next]').disabled = state.page >= state.pages - 1;
    state.bar.querySelector('.ui-page-jump').value = String(state.page + 1);
    state.table.closest('.ui-table-wrap')?.scrollTo({top:0,behavior:'smooth'});
  }

  document.addEventListener('click', event => {
    const trigger = event.target.closest('[data-ui-table-settings]');
    if (!trigger) return;
    const state = registry.get(trigger.dataset.uiTableSettings);
    if (!state) { window.ArtdonUI.toast('该表格尚未接入列设置','warning'); return; }
    document.querySelector('.ui-table-settings')?.remove();
    const panel = document.createElement('section'); panel.className='ui-table-settings';
    const rect=trigger.getBoundingClientRect(); panel.style.top=`${Math.min(innerHeight-430,rect.bottom+6)}px`; panel.style.right=`${Math.max(12,innerWidth-rect.right)}px`;
    panel.innerHTML='<h3>列显示与行密度</h3><div class="ui-table-settings-list"></div><label class="ui-field"><span class="ui-label">行密度</span><select class="ui-select" data-density><option value="compact">紧凑</option><option value="standard" selected>标准</option><option value="comfortable">舒适</option></select></label><button class="ui-btn ui-btn-secondary ui-btn-sm" type="button" data-settings-close>关闭</button>';
    const list=panel.querySelector('.ui-table-settings-list');
    [...state.table.tHead.rows[0].cells].forEach((th,i)=>{ if(th.classList.contains('ui-select-col')||th.classList.contains('ui-action-col'))return; const label=document.createElement('label');label.className='ui-check';label.innerHTML=`<input type="checkbox" checked data-column="${i}"><span class="ui-check-box"></span><span></span>`;label.lastElementChild.textContent=th.childNodes[0]?.textContent.trim()||`第${i+1}列`;list.append(label);});
    panel.addEventListener('change', e=>{ if(e.target.matches('[data-column]')){const i=Number(e.target.dataset.column);[...state.table.rows].forEach(row=>row.cells[i]&&(row.cells[i].hidden=!e.target.checked));} if(e.target.matches('[data-density]')){state.table.classList.remove('ui-table-density-compact','ui-table-density-comfortable');if(e.target.value!=='standard')state.table.classList.add(`ui-table-density-${e.target.value}`);}});
    panel.addEventListener('click',e=>{if(e.target.closest('[data-settings-close]'))panel.remove();});
    document.body.append(panel);
  });
  document.addEventListener('pointerdown',event=>{const panel=document.querySelector('.ui-table-settings');if(panel&&!panel.contains(event.target)&&!event.target.closest('[data-ui-table-settings]'))panel.remove();});
  function compare(a='',b='',numeric=false){const av=a.trim(),bv=b.trim();if(numeric)return(Number(av)||0)-(Number(bv)||0);return av.localeCompare(bv,'zh-CN',{numeric:true});}
})();
