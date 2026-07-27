(() => {
  const q = (selector, root = document) => root.querySelector(selector);
  const qa = (selector, root = document) => [...root.querySelectorAll(selector)];
  const drawer = q('[data-category-editor][data-category-code="chip"]');
  if (!drawer) return;

  const state = {
    materialId: 0,
    material: null,
    catalog: { templates: [], suggestions: { cct: [], cri: [], sdcm: [] } },
    activeTemplateId: 0,
    enabledCombinations: new Set(),
    applyMaterialIds: [],
    applyPreview: null,
  };

  const escapeHtml = value => String(value ?? '')
    .replaceAll('&', '&amp;')
    .replaceAll('<', '&lt;')
    .replaceAll('>', '&gt;')
    .replaceAll('"', '&quot;')
    .replaceAll("'", '&#039;');
  const number = value => Number.parseFloat(value || 0);
  const integer = value => Number.parseInt(value || 0, 10) || 0;
  const comboKey = combo => `${number(combo.cct_k)}|${number(combo.cri)}|${number(combo.sdcm)}`;

  const notify = (title, message) => {
    const region = q('[data-toast-region]');
    if (!region) return;
    const item = document.createElement('div');
    item.className = 'mc-toast';
    const strong = document.createElement('strong');
    const span = document.createElement('span');
    strong.textContent = title;
    span.textContent = message;
    item.append(strong, span);
    region.append(item);
    setTimeout(() => item.remove(), 4500);
  };

  const request = async (url, options = {}) => {
    const response = await fetch(url, {
      credentials: 'same-origin',
      headers: { Accept: 'application/json', ...(options.headers || {}) },
      ...options,
    });
    const text = await response.text();
    let payload;
    try {
      payload = JSON.parse(text);
    } catch {
      throw new Error('服务器没有返回有效数据。');
    }
    if (!response.ok || !payload.ok) throw new Error(payload.message || '操作失败。');
    return payload.data;
  };

  const get = (action, params = {}) => {
    const url = new URL(`${window.MC_BASE_URL}/api/v1/chip-specifications.php`, location.origin);
    url.searchParams.set('action', action);
    Object.entries(params).forEach(([key, value]) => url.searchParams.set(key, String(value)));
    return request(url);
  };

  const post = (action, values = {}) => {
    const body = new FormData();
    body.set('action', action);
    body.set('csrf_token', window.MC_CSRF || '');
    Object.entries(values).forEach(([key, value]) => {
      body.set(key, Array.isArray(value) || (value && typeof value === 'object') ? JSON.stringify(value) : String(value ?? ''));
    });
    return request(`${window.MC_BASE_URL}/api/v1/chip-specifications.php`, { method: 'POST', body });
  };

  const openModal = modal => {
    modal.classList.add('is-open');
    q('[data-overlay]')?.classList.add('is-visible');
    document.body.style.overflow = 'hidden';
  };

  const closeModal = modal => {
    modal.classList.remove('is-open');
    if (!q('[data-modal].is-open') && !q('[data-drawer].is-open')) {
      q('[data-overlay]')?.classList.remove('is-visible');
      document.body.style.overflow = '';
    }
  };

  const loadCatalog = async () => {
    state.catalog = await get('catalog');
    renderTemplateList();
    if (!state.activeTemplateId && state.catalog.templates.length) {
      const preferred = state.catalog.templates.find(template => integer(template.is_system_default)) || state.catalog.templates[0];
      selectTemplate(integer(preferred.id));
    } else {
      renderApplyTemplates();
    }
  };

  const loadMaterial = async materialId => {
    state.materialId = integer(materialId);
    if (!state.materialId) {
      state.material = null;
      q('[data-chip-applied-templates]').innerHTML = '';
      q('[data-chip-variant-list]').innerHTML = '<div class="mc-empty-inline">请先保存芯片草稿，再维护规格组合。</div>';
      qa('[data-chip-apply-open],[data-chip-manual-open],[data-chip-variant-save]', drawer).forEach(button => { button.disabled = true; });
      return;
    }
    qa('[data-chip-apply-open],[data-chip-manual-open],[data-chip-variant-save]', drawer).forEach(button => { button.disabled = false; });
    state.material = await get('material', { material_id: state.materialId });
    renderMaterial();
  };

  const renderMaterial = () => {
    const applied = state.material?.applied_templates || [];
    q('[data-chip-applied-templates]').innerHTML = applied.length
      ? `<div class="mc-chip-applied-list">${applied.map(template => `<span class="${integer(template.is_stale) ? 'is-stale' : ''}">
        <b>${escapeHtml(template.template_name)}</b>
        <small>已用 v${integer(template.applied_version_no)}${integer(template.is_stale) ? ` · 可同步到 v${integer(template.current_version_no)}` : ' · 已同步'}</small>
      </span>`).join('')}</div>`
      : '<div class="mc-empty-inline">尚未套用规格模板；可套用多个模板，系统会自动去重。</div>';
    const variants = state.material?.variants || [];
    q('[data-chip-variant-list]').innerHTML = variants.length
      ? `<div class="mc-chip-variant-table">
        <div class="mc-chip-variant-row mc-chip-variant-row--head"><span>启用</span><span>默认</span><span>具体规格</span><span>来源</span><span>价格 / 库存 / 交期</span></div>
        ${variants.map(variant => `<label class="mc-chip-variant-row ${variant.status !== 'active' ? 'is-disabled' : ''} ${integer(variant.needs_confirmation) ? 'needs-confirmation' : ''}">
          <span><input type="checkbox" data-chip-variant-active value="${integer(variant.id)}" ${variant.status === 'active' ? 'checked' : ''}></span>
          <span><input type="radio" name="chip_default_variant" value="${integer(variant.id)}" ${integer(variant.is_default) ? 'checked' : ''}></span>
          <span><strong>${escapeHtml(variant.label)}</strong><small>${escapeHtml(variant.variant_code)}${variant.supplier_spec_code ? ` · ${escapeHtml(variant.supplier_spec_code)}` : ''}</small>${integer(variant.needs_confirmation) ? `<em>历史范围待确认 <input type="checkbox" data-chip-variant-confirm value="${integer(variant.id)}"> 确认为有效规格</em>` : ''}</span>
          <span><b>${escapeHtml(variant.template_name || (variant.source_type === 'legacy' ? '原始资料' : '手工'))}</b><small>${variant.source_template_version_no ? `模板 v${integer(variant.source_template_version_no)}` : ''}</small></span>
          <span><b>${variant.purchase_price === null ? '—' : `${escapeHtml(variant.currency)} ${escapeHtml(variant.purchase_price)}`}</b><small>库存 ${variant.stock_quantity ?? '—'} · ${variant.lead_time_days === null ? '交期待确认' : `${integer(variant.lead_time_days)} 天`}</small></span>
        </label>`).join('')}
      </div>`
      : '<div class="mc-empty-inline">暂无具体规格。建议先套用模板，也可以手工添加一个组合。</div>';
  };

  const renderTemplateList = () => {
    const root = q('[data-chip-template-list]');
    const templates = state.catalog.templates || [];
    root.innerHTML = templates.length
      ? templates.map(template => `<button type="button" class="${integer(template.id) === state.activeTemplateId ? 'is-active' : ''}" data-chip-template-id="${integer(template.id)}">
        <strong>${escapeHtml(template.template_name)}${integer(template.is_system_default) ? '<em>默认</em>' : ''}</strong>
        <span>v${integer(template.current_version_no)} · ${integer(template.combinations?.length)} 个组合 · ${integer(template.material_count)} 个芯片</span>
        ${integer(template.stale_material_count) ? `<small>${integer(template.stale_material_count)} 个芯片可同步新版</small>` : ''}
      </button>`).join('')
      : '<div class="mc-empty-inline">尚无规格模板。</div>';
    renderApplyTemplates();
  };

  const renderValueChoices = (key, selectedValues) => {
    const suggestions = [...new Set([...(state.catalog.suggestions?.[key] || []), ...selectedValues.map(number)])].sort((a, b) => a - b);
    q(`[data-chip-template-values="${key}"]`).innerHTML = suggestions.map(value => `<label>
      <input type="checkbox" data-chip-template-value="${key}" value="${escapeHtml(value)}" ${selectedValues.map(number).includes(number(value)) ? 'checked' : ''}>
      <span>${escapeHtml(value)}${key === 'cct' ? 'K' : ''}</span>
    </label>`).join('');
  };

  const selectTemplate = templateId => {
    const template = state.catalog.templates.find(row => integer(row.id) === integer(templateId));
    if (!template) return;
    state.activeTemplateId = integer(template.id);
    state.enabledCombinations = new Set((template.combinations || []).map(comboKey));
    const form = q('[data-chip-template-form]');
    form.elements.template_id.value = template.id;
    form.elements.template_name.value = template.template_name || '';
    form.elements.description.value = template.description || '';
    form.elements.is_system_default.checked = Boolean(integer(template.is_system_default));
    form.elements.change_note.value = '';
    ['cct', 'cri', 'sdcm'].forEach(key => renderValueChoices(key, template.selection?.[key] || []));
    renderCombinations(false);
    renderTemplateList();
  };

  const newTemplate = () => {
    state.activeTemplateId = 0;
    state.enabledCombinations = new Set();
    const form = q('[data-chip-template-form]');
    form.reset();
    form.elements.template_id.value = '';
    ['cct', 'cri', 'sdcm'].forEach(key => renderValueChoices(key, []));
    renderCombinations(true);
    renderTemplateList();
  };

  const selectedValues = key => qa(`[data-chip-template-value="${key}"]:checked`).map(input => number(input.value));

  const generatedCombinations = () => {
    const cct = selectedValues('cct');
    const cri = selectedValues('cri');
    const sdcm = selectedValues('sdcm');
    const combinations = [];
    cct.forEach(cctValue => cri.forEach(criValue => sdcm.forEach(sdcmValue => combinations.push({
      cct_k: cctValue,
      cri: criValue,
      sdcm: sdcmValue,
    }))));
    return combinations;
  };

  const renderCombinations = resetEnabled => {
    const combinations = generatedCombinations();
    if (resetEnabled) state.enabledCombinations = new Set(combinations.map(comboKey));
    const root = q('[data-chip-combination-list]');
    root.innerHTML = combinations.length
      ? combinations.map(combo => `<label>
        <input type="checkbox" data-chip-combination value="${escapeHtml(comboKey(combo))}" ${state.enabledCombinations.has(comboKey(combo)) ? 'checked' : ''}>
        <span>${integer(combo.cct_k)}K / CRI${escapeHtml(combo.cri)} / SDCM≤${escapeHtml(combo.sdcm)}</span>
      </label>`).join('')
      : '<div class="mc-empty-inline">至少各勾选一个色温、显指和色容差，系统才会生成组合。</div>';
    q('[data-chip-combination-count]').textContent = `${state.enabledCombinations.size} / ${combinations.length} 个已启用`;
  };

  const renderApplyTemplates = () => {
    const root = q('[data-chip-apply-template-list]');
    if (!root) return;
    root.innerHTML = (state.catalog.templates || []).map(template => `<label>
      <input type="checkbox" name="chip_apply_template" value="${integer(template.id)}" ${integer(template.is_system_default) ? 'checked' : ''}>
      <span><strong>${escapeHtml(template.template_name)}</strong><small>v${integer(template.current_version_no)} · ${integer(template.combinations?.length)} 个有效组合</small></span>
    </label>`).join('') || '<div class="mc-empty-inline">请先在“模板管理”中建立规格模板。</div>';
  };

  const selectedChipMaterialIds = () => qa('[data-row-select]:checked', q('[data-workspace][data-category-code="chip"]'))
    .map(input => {
      try {
        return integer(JSON.parse(input.closest('[data-row]').dataset.record || '{}').id);
      } catch {
        return 0;
      }
    }).filter(Boolean);

  const openApply = materialIds => {
    state.applyMaterialIds = [...new Set(materialIds.map(integer).filter(Boolean))];
    state.applyPreview = null;
    const names = state.applyMaterialIds.length === 1 && state.material?.material
      ? `${state.material.material.material_code} ${state.material.material.name}`
      : `${state.applyMaterialIds.length} 个芯片物料`;
    q('[data-chip-apply-target]').innerHTML = `<strong>本次目标：${escapeHtml(names)}</strong><span>最多可一次处理 1000 个芯片。</span>`;
    q('[data-chip-apply-preview]').textContent = '选择模板后点击“预览影响”。';
    const form = q('[data-chip-apply-form]');
    form.querySelector('button[type="submit"]').disabled = true;
    renderApplyTemplates();
    openModal(q('#chip-template-apply-modal'));
  };

  const applyRequest = () => ({
    template_ids: qa('[name="chip_apply_template"]:checked', q('[data-chip-apply-form]')).map(input => integer(input.value)),
    material_ids: state.applyMaterialIds,
    mode: q('[name="mode"]:checked', q('[data-chip-apply-form]'))?.value || 'fill_missing',
  });

  document.addEventListener('mc:category-editor-opened', event => {
    if (event.detail?.categoryCode !== 'chip') return;
    Promise.all([
      state.catalog.templates.length ? Promise.resolve() : loadCatalog(),
      loadMaterial(integer(event.detail.materialId)),
    ]).catch(error => notify('芯片规格读取失败', error.message));
  });

  document.addEventListener('click', async event => {
    const templateButton = event.target.closest('[data-chip-template-id]');
    if (templateButton) {
      selectTemplate(integer(templateButton.dataset.chipTemplateId));
      return;
    }
    if (event.target.closest('[data-chip-template-new]')) {
      newTemplate();
      return;
    }
    const addValue = event.target.closest('[data-chip-template-add]');
    if (addValue) {
      const key = addValue.dataset.chipTemplateAdd;
      const input = q(`[data-chip-template-custom="${key}"]`);
      const value = number(input.value);
      if (!value) return;
      const selected = selectedValues(key);
      if (!selected.includes(value)) selected.push(value);
      renderValueChoices(key, selected);
      input.value = '';
      renderCombinations(true);
      return;
    }
    if (event.target.closest('[data-chip-apply-open]')) {
      if (!state.materialId) {
        notify('请先保存芯片', '保存草稿后即可套用规格模板。');
        return;
      }
      openApply([state.materialId]);
      return;
    }
    if (event.target.closest('[data-chip-template-batch]')) {
      const ids = selectedChipMaterialIds();
      if (!ids.length) {
        notify('请先选择芯片', '勾选需要套用模板的芯片物料。');
        return;
      }
      openApply(ids);
      return;
    }
    if (event.target.closest('[data-chip-apply-preview-button]')) {
      const values = applyRequest();
      if (!values.template_ids.length) {
        notify('请选择模板', '可同时选择多个模板，重复组合会自动去重。');
        return;
      }
      try {
        state.applyPreview = await post('preview_apply', values);
        const preview = state.applyPreview;
        q('[data-chip-apply-preview]').innerHTML = `<strong>预览结果</strong>
          <span>目标 ${integer(preview.material_count)} 个芯片 · 合并后 ${integer(preview.combination_count)} 个组合</span>
          <div><b>新增 ${integer(preview.create_count)}</b><b>保留 ${integer(preview.keep_count)}</b><b>停用 ${integer(preview.disable_count)}</b><b>受审批保护 ${integer(preview.protected_count)}</b></div>`;
        q('[data-chip-apply-form] button[type="submit"]').disabled = false;
      } catch (error) {
        state.applyPreview = null;
        notify('预览失败', error.message);
      }
      return;
    }
    if (event.target.closest('[data-chip-manual-open]')) {
      if (!state.materialId) return;
      q('[data-chip-manual-form]').reset();
      openModal(q('#chip-manual-variant-modal'));
      return;
    }
    if (event.target.closest('[data-chip-variant-save]')) {
      if (!state.materialId) return;
      const activeIds = qa('[data-chip-variant-active]:checked').map(input => integer(input.value));
      const defaultId = integer(q('[name="chip_default_variant"]:checked')?.value);
      const confirmIds = qa('[data-chip-variant-confirm]:checked').map(input => integer(input.value));
      try {
        state.material = await post('save_material_settings', {
          material_id: state.materialId,
          active_variant_ids: activeIds,
          default_variant_id: defaultId,
          confirm_variant_ids: confirmIds,
        });
        renderMaterial();
        notify('芯片规格已保存', '启用范围和默认出货规格已经更新。');
      } catch (error) {
        notify('保存失败', error.message);
      }
      return;
    }
    if (event.target.closest('[data-chip-modal-close]')) {
      closeModal(event.target.closest('[data-modal]'));
    }
  });

  document.addEventListener('change', event => {
    if (event.target.matches('[data-chip-template-value]')) {
      renderCombinations(true);
      return;
    }
    if (event.target.matches('[data-chip-combination]')) {
      event.target.checked ? state.enabledCombinations.add(event.target.value) : state.enabledCombinations.delete(event.target.value);
      q('[data-chip-combination-count]').textContent = `${state.enabledCombinations.size} / ${generatedCombinations().length} 个已启用`;
      return;
    }
    if (event.target.matches('[name="chip_apply_template"], [data-chip-apply-form] [name="mode"]')) {
      state.applyPreview = null;
      q('[data-chip-apply-preview]').textContent = '选择发生变化，请重新预览影响。';
      q('[data-chip-apply-form] button[type="submit"]').disabled = true;
      return;
    }
    if (event.target.matches('[name="chip_default_variant"]')) {
      const active = q(`[data-chip-variant-active][value="${CSS.escape(event.target.value)}"]`);
      if (active) active.checked = true;
    }
  });

  q('[data-chip-template-form]').addEventListener('submit', async event => {
    event.preventDefault();
    const form = event.currentTarget;
    const combinations = generatedCombinations().filter(combo => state.enabledCombinations.has(comboKey(combo)));
    const values = {
      template_id: integer(form.elements.template_id.value),
      template_name: form.elements.template_name.value,
      description: form.elements.description.value,
      change_note: form.elements.change_note.value,
      is_system_default: form.elements.is_system_default.checked ? 1 : 0,
      selection: {
        cct: selectedValues('cct'),
        cri: selectedValues('cri'),
        sdcm: selectedValues('sdcm'),
      },
      combinations,
    };
    if (!values.template_name.trim()) {
      notify('请填写模板名称', '模板名称用于批量套用时识别。');
      return;
    }
    const button = event.submitter;
    button.disabled = true;
    try {
      const result = await post('save_template', values);
      await loadCatalog();
      selectTemplate(integer(result.template_id));
      notify('模板版本已保存', `已保存 v${integer(result.version_no)}，包含 ${integer(result.combination_count)} 个有效组合；现有芯片不会自动变化。`);
    } catch (error) {
      notify('模板保存失败', error.message);
    } finally {
      button.disabled = false;
    }
  });

  q('[data-chip-apply-form]').addEventListener('submit', async event => {
    event.preventDefault();
    if (!state.applyPreview) {
      notify('请先预览影响', '确认目标、模板和套用方式后才能执行。');
      return;
    }
    const button = event.submitter;
    button.disabled = true;
    try {
      const result = await post('apply_templates', applyRequest());
      closeModal(event.currentTarget.closest('[data-modal]'));
      if (state.materialId && state.applyMaterialIds.includes(state.materialId)) await loadMaterial(state.materialId);
      await loadCatalog();
      notify('规格模板已套用', `新增 ${integer(result.created)} 个，恢复 ${integer(result.reactivated)} 个，停用 ${integer(result.disabled)} 个；${integer(result.protected)} 个已审批规格受到保护。`);
    } catch (error) {
      notify('套用失败', error.message);
      button.disabled = false;
    }
  });

  q('[data-chip-manual-form]').addEventListener('submit', async event => {
    event.preventDefault();
    const form = event.currentTarget;
    const combo = Object.fromEntries(new FormData(form));
    const button = event.submitter;
    button.disabled = true;
    try {
      const result = await post('add_manual_variants', {
        material_id: state.materialId,
        combinations: [combo],
      });
      state.material = result.material;
      renderMaterial();
      closeModal(form.closest('[data-modal]'));
      notify('规格已添加', result.created ? '新的芯片规格已加入并启用。' : '相同规格已存在，未重复添加。');
    } catch (error) {
      notify('添加失败', error.message);
    } finally {
      button.disabled = false;
    }
  });

  loadCatalog().catch(error => notify('规格模板读取失败', error.message));
})();
