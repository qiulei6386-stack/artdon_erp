document.addEventListener('DOMContentLoaded',()=>{
  const root=document.querySelector('[data-power-workbench]');
  if(!root)return;
  const toast=(message,type='info')=>window.ArtdonUI.toast(message,type);
  const form=root.querySelector('[data-power-search-form]');
  const search=form?.querySelector('[name=q]');
  let searchTimer;
  search?.addEventListener('input',()=>{
    root.querySelector('[data-power-search-clear]')?.toggleAttribute('hidden',search.value==='');
    clearTimeout(searchTimer);
    searchTimer=setTimeout(()=>form.requestSubmit(),300);
  });
  search?.addEventListener('keydown',event=>{if(event.key==='Enter'){event.preventDefault();clearTimeout(searchTimer);form.requestSubmit();}});
  root.querySelector('[data-power-search-clear]')?.addEventListener('click',()=>{search.value='';form.requestSubmit();});

  const filterPanel=root.querySelector('[data-power-filter-panel]');
  root.querySelector('[data-power-filter-toggle]')?.addEventListener('click',event=>{
    const open=filterPanel.hasAttribute('hidden');
    filterPanel.toggleAttribute('hidden',!open);
    event.currentTarget.setAttribute('aria-expanded',String(open));
    if(open)filterPanel.querySelector('select')?.focus();
  });
  root.querySelector('[data-power-refresh]')?.addEventListener('click',event=>{
    event.currentTarget.classList.add('is-loading');
    toast('正在刷新电源数据');
    location.reload();
  });
  root.querySelector('[data-reset-power-view]')?.addEventListener('click',()=>{
    Object.keys(localStorage).filter(key=>key.startsWith('mc-table:')&&key.includes('power_workbench')).forEach(key=>localStorage.removeItem(key));
    toast('电源表格视图已恢复默认','success');
    setTimeout(()=>location.reload(),300);
  });

  const table=root.querySelector('#power-workbench-table');
  const batchBar=root.querySelector('[data-power-batch-bar]');
  table?.addEventListener('ui:selection',event=>{
    const count=event.detail.rows.length;
    batchBar?.toggleAttribute('hidden',count===0);
    const label=batchBar?.querySelector('[data-power-selected-count]');
    if(label)label.textContent=String(count);
  });
  root.querySelector('[data-cancel-selection]')?.addEventListener('click',()=>{
    table?.querySelectorAll('[data-ui-row-select]').forEach(input=>{input.checked=false;input.dispatchEvent(new Event('change',{bubbles:true}));});
  });
  root.querySelector('[data-export-selected]')?.addEventListener('click',()=>exportRows([...table.querySelectorAll('tbody tr.is-selected')],'power-selected.csv'));
  root.querySelector('[data-export-power]')?.addEventListener('click',()=>exportRows([...table?.tBodies[0]?.rows||[]].filter(row=>!row.hidden),'power-current.csv'));

  const drawer=document.querySelector('#power-record-drawer');
  const openRow=row=>{
    if(!drawer||!row)return;
    const data=row.dataset;
    drawer.querySelector('[data-drawer-title]').textContent=data.name||'电源详情';
    drawer.querySelector('[data-drawer-code]').textContent=data.code||'';
    drawer.querySelector('[data-drawer-status]').textContent=data.status||'';
    drawer.querySelector('[data-drawer-body]').innerHTML='<div class="power-drawer-skeleton"><span></span><span></span><span></span></div>';
    const primary=drawer.querySelector('[data-drawer-primary]');
    const secondary=drawer.querySelector('[data-drawer-secondary]');
    if(data.recordKind==='source'){
      primary.textContent='在电源页设置';
      primary.href=data.reviewUrl||'material/power.php';
      secondary.hidden=false;
    }else{
      primary.textContent='管理正式物料';
      primary.href='materials.php?material_id='+encodeURIComponent(data.recordId);
      secondary.hidden=true;
    }
    window.ArtdonUI.drawer.open('#power-record-drawer',row);
    requestAnimationFrame(()=>{drawer.querySelector('[data-drawer-body]').innerHTML=drawerContent(data);});
  };
  table?.addEventListener('click',event=>{
    if(event.target.closest('input,label.ui-check'))return;
    const row=event.target.closest('[data-power-row]');
    if(row)openRow(row);
  });
  table?.addEventListener('keydown',event=>{if((event.key==='Enter'||event.key===' ')&&event.target.matches('[data-power-row]')){event.preventDefault();openRow(event.target);}});

  function drawerContent(data){
    const safe=value=>String(value||'—').replace(/[&<>"']/g,char=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[char]));
    return `<section class="power-drawer-section"><h3>原始资料</h3><dl class="power-detail-grid"><div><dt>来源编号</dt><dd>${safe(data.code)}</dd></div><div><dt>名称</dt><dd>${safe(data.name)}</dd></div><div><dt>品牌</dt><dd>${safe(data.brand)}</dd></div><div><dt>型号</dt><dd>${safe(data.model)}</dd></div><div class="is-wide"><dt>原始规格</dt><dd>${safe(data.spec)}</dd></div></dl></section>
      <section class="power-drawer-section"><h3>标准字段</h3><dl class="power-detail-grid"><div><dt>功率</dt><dd>${data.power?safe(data.power)+' W':'待确认'}</dd></div><div><dt>安装方式</dt><dd>${safe(data.installation)}</dd></div><div><dt>供应商质保</dt><dd>${data.warranty?safe(data.warranty)+' 年':'待确认'}</dd></div><div><dt>当前状态</dt><dd>${safe(data.status)}</dd></div></dl></section>
      <section class="power-drawer-section"><h3>缺失字段与重复候选</h3><p class="ui-help">未确认字段保持为空或“待确认”；重复判断继续使用现有标准化服务，不自动合并。</p></section>`;
  }
  function exportRows(rows,name){
    if(!rows.length){toast('当前没有可导出的真实数据','warning');return;}
    const header=[...table.tHead.rows[0].cells].slice(1,-1);
    const lines=[[...header].map(cell=>csv(cell.textContent)),...rows.map(row=>[...row.cells].slice(1,-1).map(cell=>csv(cell.textContent)))];
    const blob=new Blob(['\ufeff'+lines.map(line=>line.join(',')).join('\n')],{type:'text/csv;charset=utf-8'});
    const link=document.createElement('a');link.href=URL.createObjectURL(blob);link.download=name;link.click();URL.revokeObjectURL(link.href);
    toast(`已导出 ${rows.length} 条当前权限范围内记录`,'success');
  }
  function csv(value){return `"${String(value).trim().replaceAll('"','""')}"`;}
});
