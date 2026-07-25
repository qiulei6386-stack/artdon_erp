(() => {
  const endpoint = 'api/v1/power-standardization.php';
  const drawer = document.querySelector('#standardization-drawer');
  const form = document.querySelector('#standard-form');
  let activeTrigger;

  document.addEventListener('click', async event => {
    const review = event.target.closest('[data-review-id]');
    if (review) { activeTrigger = review; await openReview(Number(review.dataset.reviewId)); return; }
    const action = event.target.closest('[data-api-action]');
    if (action) await postAction(action.dataset.apiAction, new FormData(form || undefined), action, true);
    if (event.target.closest('[data-create-material]')) await saveMaterial(event.target.closest('[data-create-material]'));
    if (event.target.closest('[data-link-existing]')) await linkExisting(event.target.closest('[data-link-existing]'));
    if (event.target.closest('[data-defer]')) await postAction('reject', new FormData(form), event.target.closest('[data-defer]'), true);
    const duplicate = event.target.closest('[data-decide-duplicate]');
    if (duplicate) { const data=new FormData(form);data.set('candidate_id',duplicate.dataset.decideDuplicate);data.set('decision',duplicate.previousElementSibling.value);await postAction('decide_duplicate',data,duplicate,false);duplicate.closest('[data-duplicate-row]')?.remove(); }
  });
  form?.addEventListener('input', () => { drawer.dataset.uiDirty = 'true'; });

  async function openReview(id) {
    setDrawerLoading();
    window.ArtdonUI.drawer.open('#standardization-drawer', activeTrigger);
    try {
      const response = await fetch(`${endpoint}?action=detail&id=${id}`, {headers:{Accept:'application/json'}});
      const json = await response.json(); if (!response.ok || !json.ok) throw new Error(json.message || '加载失败');
      fill(json.data);
    } catch (error) { window.ArtdonUI.toast(error.message, 'danger', 0); }
  }
  function fill(data) {
    form.reset(); form.elements.staging_id.value = data.staging.id;
    document.querySelector('[data-original]').innerHTML = `<strong>原始资料</strong><p>${escapeHtml(data.staging.raw_brand || '—')} / ${escapeHtml(data.staging.raw_model || '—')} / ${escapeHtml(data.staging.raw_name || '—')}</p><p>${escapeHtml(data.staging.raw_spec || '—')}</p>`;
    const parsed = Object.fromEntries(data.parse_results.map(item => [item.field_key,item]));
    Object.entries(parsed).forEach(([key,item]) => {
      const field = form.elements[key]; if (field && !field.length) field.value = item.confirmed_value || item.candidate_value || '';
      const help = document.querySelector(`[data-confidence="${CSS.escape(key)}"]`); if (help) { help.textContent = `${item.confidence} · ${item.parse_rule}`; help.className = `ui-help confidence-${item.confidence}`; }
    });
    if (parsed.current_options_ma) { try { form.elements.current_options_text.value = JSON.parse(parsed.current_options_ma.candidate_value).join(','); } catch (_) {} }
    if (parsed.dimming_mode) { const item=[...form.querySelectorAll('[name="dimming_modes[]"]')].find(input=>input.value===parsed.dimming_mode.candidate_value);if(item)item.checked=true; }
    const box=document.querySelector('[data-duplicates]');box.innerHTML=data.duplicates.length?`<strong>重复候选</strong>${data.duplicates.map(d=>`<div data-duplicate-row><p>#${d.candidate_material_id} ${escapeHtml(d.material_code)} · ${escapeHtml(d.brand)} ${escapeHtml(d.model)} · 风险 ${d.score}</p><select class="ui-select"><option value="merge_existing">合并到已有正式物料</option><option value="different_supplier">供应商不同记录</option><option value="different_version">不同版本</option><option value="not_duplicate">标记非重复</option><option value="deferred">暂缓处理</option></select><button class="ui-btn ui-btn-secondary ui-btn-sm" type="button" data-decide-duplicate="${d.id}">记录决定</button></div>`).join('')}`:'<strong>重复候选</strong><p>当前未发现候选；这不等于自动判定无重复。</p>';
    drawer.dataset.uiDirty='false';
  }
  async function saveMaterial(button) {
    if (!form.reportValidity()) return;
    if (form.elements.installation_type.value === 'unknown' || form.elements.output_type.value === 'unknown') { window.ArtdonUI.toast('安装方式和输出类型仍待人工确认', 'warning', 5000); return; }
    const data=new FormData(form);data.set('action','create_material');const currents=form.elements.current_options_text.value.split(/[,，\s]+/).filter(Boolean);data.delete('current_options_text');currents.forEach(value=>data.append('current_options_ma[]',value));
    await request(data,button,true);
  }
  async function linkExisting(button) {
    if (!form.elements.existing_material_id.value) { window.ArtdonUI.toast('请输入已有正式物料 ID','warning');return; }
    const data=new FormData(form);data.set('action','link_existing');data.set('material_id',form.elements.existing_material_id.value);data.set('decision','existing_material');await request(data,button,true);
  }
  async function postAction(action,data,button,reload=false){data.set('action',action);await request(data,button,reload);}
  async function request(data,button,reload) {
    const label=button.textContent;button.disabled=true;button.classList.add('is-loading');button.textContent='保存中…';
    try { const response=await fetch(endpoint,{method:'POST',body:data,headers:{Accept:'application/json'}});const json=await response.json();if(!response.ok||!json.ok)throw new Error(json.message||'保存失败');drawer&&(drawer.dataset.uiDirty='false');window.ArtdonUI.toast(json.message||'保存成功','success');window.ArtdonUI.interactions.close({force:true});if(reload)setTimeout(()=>location.reload(),500); }
    catch(error){window.ArtdonUI.toast(error.message||'网络失败','danger',0);}
    finally{button.disabled=false;button.classList.remove('is-loading');button.textContent=label;}
  }
  function setDrawerLoading(){document.querySelector('[data-original]').innerHTML='<div class="ui-skeleton"></div><div class="ui-skeleton"></div>';}
  function escapeHtml(value){return String(value).replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c]));}
})();
