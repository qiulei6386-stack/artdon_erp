(() => {
  const q = (selector, root = document) => root.querySelector(selector);
  const qa = (selector, root = document) => [...root.querySelectorAll(selector)];
  const workspace = q('[data-workspace][data-category-code="power_supply"]');
  const drawer = q('[data-power-editor]');
  const batchDrawer = q('[data-power-batch]');
  if (!workspace || !drawer || !batchDrawer) return;

  const form = q('[data-power-form]', drawer);
  const overlay = q('[data-overlay]');
  let schema = null;
  let activeRecord = null;
  let selectedIds = [];
  let lastBatchRequest = null;
  let dirty = false;

  const labels = {
    nominal_power_w: '额定功率',
    max_output_power_w: '最大输出功率',
    power_band_id: '功率档',
    installation_type: '安装方式',
    output_type: '输出类型',
    currents: '输出电流',
    dimming_modes: '调光方式',
    supplier_warranty_years: '供应商质保',
    ip_rating: '防护等级',
    certification: '认证',
  };

  const toast = (title, message) => {
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
    setTimeout(() => item.remove(), 4200);
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
      throw new Error('服务器返回了非 JSON 错误，请查看服务端日志。');
    }
    if (!response.ok || !payload.ok) throw new Error(payload.message || '操作失败。');
    return payload.data;
  };

  const post = (action, values = {}) => {
    const body = new FormData();
    body.set('csrf_token', window.MC_CSRF || '');
    body.set('action', action);
    Object.entries(values).forEach(([key, value]) => body.set(key, value));
    return request(`${window.MC_BASE_URL}/api/v1/power-editor.php`, { method: 'POST', body });
  };

  const openDrawer = target => {
    qa('[data-drawer].is-open,[data-modal].is-open').forEach(layer => layer.classList.remove('is-open'));
    target.classList.add('is-open');
    overlay?.classList.add('is-visible');
    document.body.style.overflow = 'hidden';
  };

  const closeDrawer = target => {
    if (target === drawer && dirty && !confirm('电源资料尚未保存，确认关闭？')) return;
    target.classList.remove('is-open');
    if (!q('[data-drawer].is-open,[data-modal].is-open')) {
      overlay?.classList.remove('is-visible');
      document.body.style.overflow = '';
    }
    dirty = false;
  };

  const option = (value, label) => {
    const node = document.createElement('option');
    node.value = value;
    node.textContent = label;
    return node;
  };

  const ensureSchema = async () => {
    if (schema) return schema;
    schema = await request(`${window.MC_BASE_URL}/api/v1/power-editor.php?action=schema`);
    const band = q('[data-power-band]', form);
    band.replaceChildren(option('', '待确认'), ...schema.bands.map(item => option(item.id, `${item.name}（${item.min_power_w}–${item.max_power_w}W）`)));
    q('[data-installation-type]', form).replaceChildren(...schema.installation_types.map(item => option(item.value, item.label)));
    q('[data-output-type]', form).replaceChildren(...schema.output_types.map(item => option(item.value, item.label)));
    const choices = q('[data-dimming-choices]', form);
    choices.replaceChildren(...schema.dimming_modes.map(item => {
      const label = document.createElement('label');
      label.className = 'mc-choice';
      const input = document.createElement('input');
      input.type = 'checkbox';
      input.name = 'dimming_modes';
      input.value = item.value;
      const text = document.createElement('span');
      text.textContent = item.label;
      label.append(input, text);
      return label;
    }));
    return schema;
  };

  const setSelect = (name, value) => {
    const input = form.elements.namedItem(name);
    if (input) input.value = value == null ? '' : String(value);
  };

  const addCurrent = (value = '', isDefault = false) => {
    const row = document.createElement('div');
    row.className = 'mc-current-row';
    const radio = document.createElement('input');
    radio.type = 'radio';
    radio.name = 'default_current';
    radio.title = '设为默认电流';
    radio.checked = isDefault;
    const input = document.createElement('input');
    input.type = 'number';
    input.step = '0.01';
    input.min = '0.01';
    input.max = '100000';
    input.placeholder = '例如 350';
    input.value = value;
    input.dataset.currentValue = '';
    const unit = document.createElement('span');
    unit.textContent = 'mA';
    const remove = document.createElement('button');
    remove.type = 'button';
    remove.className = 'mc-icon-button';
    remove.textContent = '×';
    remove.addEventListener('click', () => {
      row.remove();
      dirty = true;
    });
    row.append(radio, input, unit, remove);
    q('[data-current-list]', form).append(row);
  };

  const syncPrimaryDimming = () => {
    const selected = qa('input[name="dimming_modes"]:checked', form).map(input => input.value);
    const primary = q('[data-primary-dimming]', form);
    const current = primary.value;
    primary.replaceChildren(option('', selected.length ? '选择主调光方式' : '暂无调光方式'), ...selected.map(value => {
      const item = schema.dimming_modes.find(candidate => candidate.value === value);
      return option(value, item?.label || value);
    }));
    primary.value = selected.includes(current) ? current : (selected[0] || '');
  };

  const setReadonly = readonly => {
    qa('input,select,textarea,button[data-add-current]', form).forEach(control => {
      if (control.type !== 'hidden') control.disabled = readonly;
    });
    qa('[data-current-list] button', form).forEach(button => button.disabled = readonly);
    q('[data-power-save]', drawer).hidden = readonly;
  };

  const fillDetail = data => {
    form.reset();
    q('[data-current-list]', form).replaceChildren();
    qa('input[name="dimming_modes"]', form).forEach(input => { input.checked = false; });
    const basic = ['material_id', 'lock_version', 'name', 'brand', 'model', 'unit', 'spec_summary'];
    basic.forEach(key => setSelect(key, data[key]));
    const scalar = [
      'nominal_power_w', 'max_output_power_w', 'power_band_id',
      'input_voltage_min_v', 'input_voltage_max_v', 'input_frequency_min_hz', 'input_frequency_max_hz',
      'power_factor', 'efficiency', 'output_type', 'output_voltage_min_v', 'output_voltage_max_v',
      'installation_type', 'length_mm', 'width_mm', 'height_mm', 'ip_rating',
      'certification', 'supplier_warranty_years', 'purchase_price', 'currency', 'moq', 'lead_time_days',
    ];
    scalar.forEach(key => setSelect(key, data[key]));
    (data.currents || []).forEach(item => addCurrent(item.current_ma, +item.is_default === 1));
    if (!(data.currents || []).length) addCurrent();
    (data.dimming_modes || []).forEach(item => {
      const input = q(`input[name="dimming_modes"][value="${CSS.escape(item.mode)}"]`, form);
      if (input) input.checked = true;
    });
    syncPrimaryDimming();
    const primary = (data.dimming_modes || []).find(item => +item.is_primary === 1);
    setSelect('primary_dimming', primary?.mode || '');
    q('[data-price-field]', form)?.toggleAttribute('hidden', !schema.can_view_price);
    qa('[data-price-field]', form).forEach(field => field.hidden = !schema.can_view_price);
    setReadonly(!data.editable);
    q('[data-power-editor-title]', drawer).textContent = data.name || '电源资料';
    q('[data-power-editor-subtitle]', drawer).textContent = `${data.material_code} · ${data.status === 'draft' ? '草稿可编辑' : '只读查看'}`;
    q('[data-power-source-note]', drawer).hidden = true;
    q('[data-power-stage-source]', drawer).hidden = true;
    q('[data-power-save-state]', drawer).textContent = data.editable ? '未修改' : '当前状态只读';
    q('[data-power-error]', drawer).hidden = true;
    dirty = false;
  };

  const openNew = async () => {
    activeRecord = null;
    await ensureSchema();
    form.reset();
    q('[data-current-list]', form).replaceChildren();
    qa('input[name="dimming_modes"]', form).forEach(input => { input.checked = false; });
    setSelect('material_id', '');
    setSelect('lock_version', '1');
    setSelect('unit', 'PCS');
    setSelect('installation_type', 'unknown');
    setSelect('output_type', 'unknown');
    addCurrent();
    syncPrimaryDimming();
    setReadonly(false);
    q('[data-power-editor-title]', drawer).textContent = '新建电源';
    q('[data-power-editor-subtitle]', drawer).textContent = '创建物料中心草稿';
    q('[data-power-source-note]', drawer).hidden = true;
    q('[data-power-stage-source]', drawer).hidden = true;
    qa('[data-price-field]', form).forEach(field => field.hidden = !schema.can_view_price);
    q('[data-power-save-state]', drawer).textContent = '尚未保存';
    q('[data-power-error]', drawer).hidden = true;
    dirty = false;
    openDrawer(drawer);
  };

  const openMaterial = async record => {
    activeRecord = record;
    openDrawer(drawer);
    q('[data-power-editor-title]', drawer).textContent = record.name || '电源资料';
    q('[data-power-editor-subtitle]', drawer).textContent = '正在读取完整资料…';
    try {
      await ensureSchema();
      const detail = await request(`${window.MC_BASE_URL}/api/v1/power-editor.php?action=detail&material_id=${encodeURIComponent(record.id)}`);
      fillDetail(detail);
    } catch (error) {
      q('[data-power-error]', drawer).textContent = error.message;
      q('[data-power-error]', drawer).hidden = false;
      q('[data-power-editor-subtitle]', drawer).textContent = '读取失败';
    }
  };

  const openSource = async record => {
    activeRecord = record;
    await ensureSchema();
    form.reset();
    q('[data-current-list]', form).replaceChildren();
    addCurrent();
    setSelect('name', record.name);
    setSelect('brand', record.brand);
    setSelect('model', record.model);
    setSelect('spec_summary', record.spec);
    setReadonly(true);
    q('[data-power-editor-title]', drawer).textContent = record.name || '旧 BOM 电源';
    q('[data-power-editor-subtitle]', drawer).textContent = `${record.code} · 待整理`;
    q('[data-power-source-note]', drawer).hidden = false;
    q('[data-power-stage-source]', drawer).hidden = false;
    q('[data-power-stage-source]', drawer).dataset.sourceRecordId = record.source_record_id;
    q('[data-power-save-state]', drawer).textContent = '来源只读';
    openDrawer(drawer);
  };

  const formPayload = () => {
    const payload = {};
    const names = [
      'lock_version', 'name', 'brand', 'model', 'unit', 'spec_summary',
      'nominal_power_w', 'max_output_power_w', 'power_band_id',
      'input_voltage_min_v', 'input_voltage_max_v', 'input_frequency_min_hz', 'input_frequency_max_hz',
      'power_factor', 'efficiency', 'output_type', 'output_voltage_min_v', 'output_voltage_max_v',
      'installation_type', 'length_mm', 'width_mm', 'height_mm', 'ip_rating',
      'certification', 'supplier_warranty_years', 'purchase_price', 'currency', 'moq', 'lead_time_days',
      'primary_dimming',
    ];
    names.forEach(name => {
      const field = form.elements.namedItem(name);
      if (field && !field.disabled) payload[name] = field.value;
    });
    payload.currents = qa('[data-current-value]', form)
      .filter(input => input.value !== '')
      .map(input => ({
        value: input.value,
        is_default: q('input[type="radio"]', input.closest('.mc-current-row'))?.checked || false,
      }));
    payload.dimming_modes = qa('input[name="dimming_modes"]:checked', form).map(input => input.value);
    return payload;
  };

  const save = async () => {
    const error = q('[data-power-error]', drawer);
    const button = q('[data-power-save]', drawer);
    error.hidden = true;
    if (!form.reportValidity()) return;
    button.disabled = true;
    q('[data-power-save-state]', drawer).textContent = '正在保存…';
    try {
      const detail = await post('save', {
        material_id: form.elements.material_id.value,
        payload: JSON.stringify(formPayload()),
      });
      fillDetail(detail);
      q('[data-power-save-state]', drawer).textContent = '已保存';
      toast('电源已保存', '全部字段、多电流和调光方式已写入。');
      setTimeout(() => location.reload(), 500);
    } catch (reason) {
      error.textContent = reason.message;
      error.hidden = false;
      q('[data-power-save-state]', drawer).textContent = '保存失败';
    } finally {
      button.disabled = false;
    }
  };

  const stageSource = async button => {
    button.disabled = true;
    try {
      const body = new FormData();
      body.set('csrf_token', window.MC_CSRF || '');
      body.set('action', 'stage_source');
      body.set('source_record_id', button.dataset.sourceRecordId || '');
      const data = await request(`${window.MC_BASE_URL}/api/v1/power-standardization.php`, { method: 'POST', body });
      location.href = `${window.MC_BASE_URL}/${data.review_url}`;
    } catch (error) {
      toast('整理失败', error.message);
      button.disabled = false;
    }
  };

  const batchDefinitions = () => [
    { key: 'power_band_id', label: '功率档', help: '批量归入统一功率边界', type: 'select', options: schema.bands.map(item => [item.id, item.name]) },
    { key: 'installation_type', label: '安装方式', help: '内置、外置、远置等', type: 'select', options: schema.installation_types.map(item => [item.value, item.label]) },
    { key: 'output_type', label: '输出类型', help: '恒流或恒压', type: 'select', options: schema.output_types.map(item => [item.value, item.label]) },
    { key: 'currents', label: '输出电流', help: '多个电流以逗号分隔，如 250,300,350', type: 'text', placeholder: '250, 300, 350' },
    { key: 'dimming_modes', label: '调光方式', help: '可多选；不调光不能与其他方式并存', type: 'choices', options: schema.dimming_modes.map(item => [item.value, item.label]) },
    { key: 'supplier_warranty_years', label: '供应商质保', help: '单位年；常用 3 或 5，也可填写其他年限', type: 'number' },
    { key: 'nominal_power_w', label: '额定功率', help: '单位 W', type: 'number' },
    { key: 'max_output_power_w', label: '最大输出功率', help: '单位 W', type: 'number' },
    { key: 'ip_rating', label: '防护等级', help: '如 IP20、IP65', type: 'text', placeholder: 'IP20' },
    { key: 'certification', label: '认证', help: '如 CE、ENEC、UL', type: 'text', placeholder: 'CE, ENEC' },
  ];

  const renderBatchCards = () => {
    const list = q('[data-power-batch-cards]', batchDrawer);
    list.replaceChildren(...batchDefinitions().map(definition => {
      const card = document.createElement('section');
      card.className = 'mc-batch-card';
      card.dataset.batchKey = definition.key;
      const head = document.createElement('label');
      head.className = 'mc-batch-card__head';
      const toggle = document.createElement('input');
      toggle.type = 'checkbox';
      toggle.dataset.batchEnable = '';
      const copy = document.createElement('span');
      const strong = document.createElement('strong');
      const small = document.createElement('small');
      strong.textContent = definition.label;
      small.textContent = definition.help;
      copy.append(strong, small);
      head.append(toggle, copy);
      const control = document.createElement('div');
      control.className = 'mc-batch-card__control';
      if (definition.type === 'select') {
        const select = document.createElement('select');
        select.dataset.batchValue = '';
        select.disabled = true;
        select.append(...definition.options.map(item => option(item[0], item[1])));
        control.append(select);
      } else if (definition.type === 'choices') {
        const choices = document.createElement('div');
        choices.className = 'mc-choice-grid';
        choices.dataset.batchValue = '';
        definition.options.forEach(item => {
          const label = document.createElement('label');
          label.className = 'mc-choice';
          const input = document.createElement('input');
          input.type = 'checkbox';
          input.value = item[0];
          input.disabled = true;
          const text = document.createElement('span');
          text.textContent = item[1];
          label.append(input, text);
          choices.append(label);
        });
        control.append(choices);
      } else {
        const input = document.createElement('input');
        input.type = definition.type;
        input.step = definition.type === 'number' ? '0.01' : '';
        input.min = definition.type === 'number' ? '0' : '';
        input.placeholder = definition.placeholder || '';
        input.dataset.batchValue = '';
        input.disabled = true;
        control.append(input);
      }
      toggle.addEventListener('change', () => {
        qa('input,select', control).forEach(input => { input.disabled = !toggle.checked; });
        card.classList.toggle('is-enabled', toggle.checked);
        q('[data-power-batch-execute]', batchDrawer).disabled = true;
        q('[data-power-batch-preview]', batchDrawer).hidden = true;
      });
      card.append(head, control);
      return card;
    }));
  };

  const collectBatchChanges = () => {
    const changes = {};
    qa('.mc-batch-card', batchDrawer).forEach(card => {
      if (!q('[data-batch-enable]', card).checked) return;
      const key = card.dataset.batchKey;
      const control = q('[data-batch-value]', card);
      if (control.classList.contains('mc-choice-grid')) {
        changes[key] = qa('input:checked', control).map(input => input.value);
      } else if (key === 'currents') {
        changes[key] = control.value.split(/[,，;；\s]+/).filter(Boolean);
      } else {
        changes[key] = control.value;
      }
    });
    return changes;
  };

  const previewBatch = async () => {
    const error = q('[data-power-batch-error]', batchDrawer);
    error.hidden = true;
    try {
      const changes = collectBatchChanges();
      const policy = q('input[name="power_batch_policy"]:checked', batchDrawer).value;
      lastBatchRequest = { ids: selectedIds, changes, policy };
      const data = await post('batch_preview', {
        ids: JSON.stringify(selectedIds),
        changes: JSON.stringify(changes),
        policy,
      });
      const preview = q('[data-power-batch-preview]', batchDrawer);
      preview.replaceChildren();
      const summary = document.createElement('div');
      summary.className = 'mc-preview-summary';
      summary.innerHTML = `<strong>预计影响 ${data.affected} 条</strong><span>已选 ${data.selected} 条 · 跳过 ${data.skipped} 条</span>`;
      const tags = document.createElement('div');
      tags.className = 'mc-preview-tags';
      Object.keys(data.changes).forEach(key => {
        const tag = document.createElement('span');
        tag.textContent = labels[key] || key;
        tags.append(tag);
      });
      preview.append(summary, tags);
      preview.hidden = false;
      q('[data-power-batch-execute]', batchDrawer).disabled = data.affected === 0;
      q('[data-power-batch-state]', batchDrawer).textContent = data.affected ? '预览完成，可以执行' : '没有记录会被修改';
    } catch (reason) {
      error.textContent = reason.message;
      error.hidden = false;
      q('[data-power-batch-execute]', batchDrawer).disabled = true;
    }
  };

  const executeBatch = async () => {
    if (!lastBatchRequest || !confirm('确认按预览结果批量修改电源？该操作会生成可回滚批次。')) return;
    const button = q('[data-power-batch-execute]', batchDrawer);
    const error = q('[data-power-batch-error]', batchDrawer);
    button.disabled = true;
    error.hidden = true;
    q('[data-power-batch-state]', batchDrawer).textContent = '正在执行…';
    try {
      const data = await post('batch_execute', {
        ids: JSON.stringify(lastBatchRequest.ids),
        changes: JSON.stringify(lastBatchRequest.changes),
        policy: lastBatchRequest.policy,
      });
      const preview = q('[data-power-batch-preview]', batchDrawer);
      const result = document.createElement('div');
      result.className = 'mc-batch-result';
      const copy = document.createElement('div');
      copy.innerHTML = `<strong>批量设置完成</strong><span>成功 ${data.success}，跳过 ${data.skipped}，批次 ${data.job_uuid}</span>`;
      const rollback = document.createElement('button');
      rollback.className = 'mc-button';
      rollback.type = 'button';
      rollback.textContent = '回滚本批次';
      rollback.addEventListener('click', async () => {
        if (!confirm('确认恢复本批次执行前的值？')) return;
        rollback.disabled = true;
        try {
          const restored = await post('rollback', { job_uuid: data.job_uuid });
          toast('批次已回滚', `已恢复 ${restored.restored} 条电源。`);
          setTimeout(() => location.reload(), 500);
        } catch (reason) {
          toast('回滚失败', reason.message);
          rollback.disabled = false;
        }
      });
      result.append(copy, rollback);
      preview.append(result);
      q('[data-power-batch-state]', batchDrawer).textContent = '执行完成';
      toast('批量设置完成', `成功 ${data.success} 条，跳过 ${data.skipped} 条。`);
    } catch (reason) {
      error.textContent = reason.message;
      error.hidden = false;
      q('[data-power-batch-state]', batchDrawer).textContent = '执行失败';
      button.disabled = false;
    }
  };

  const openBatch = async trigger => {
    selectedIds = qa('[data-row-select]:checked', workspace)
      .map(input => JSON.parse(input.closest('[data-row]').dataset.record || '{}'))
      .filter(record => !record.read_only && record.id)
      .map(record => record.id);
    if (!selectedIds.length) {
      toast('请选择电源', '批量设置只处理已经进入物料中心的草稿电源。');
      return;
    }
    await ensureSchema();
    renderBatchCards();
    q('[data-power-batch-count]', batchDrawer).textContent = `已选择 ${selectedIds.length} 项`;
    q('[data-power-batch-preview]', batchDrawer).hidden = true;
    q('[data-power-batch-error]', batchDrawer).hidden = true;
    q('[data-power-batch-execute]', batchDrawer).disabled = true;
    q('[data-power-batch-state]', batchDrawer).textContent = '等待预览';
    lastBatchRequest = null;
    openDrawer(batchDrawer);
    trigger.blur();
  };

  document.addEventListener('click', event => {
    const batchButton = event.target.closest('[data-open-batch]');
    const newButton = event.target.closest('[data-open-modal="new-modal"]');
    const detailButton = event.target.closest('[data-open-detail]');
    const row = event.target.closest('[data-row]');
    if (newButton && newButton.closest('[data-workspace]') === workspace) {
      event.preventDefault();
      event.stopImmediatePropagation();
      openNew().catch(error => toast('打开失败', error.message));
      return;
    }
    if (batchButton && batchButton.closest('[data-workspace]') === workspace) {
      event.preventDefault();
      event.stopImmediatePropagation();
      openBatch(batchButton).catch(error => toast('打开失败', error.message));
      return;
    }
    if (event.target.closest('[data-stage-power-source]')) return;
    if ((detailButton || row) && row?.closest('[data-workspace]') === workspace && !event.target.closest('input,a')) {
      event.preventDefault();
      event.stopImmediatePropagation();
      const record = JSON.parse(row.dataset.record || '{}');
      (record.read_only ? openSource(record) : openMaterial(record)).catch(error => toast('打开失败', error.message));
    }
  }, true);

  q('[data-add-current]', form).addEventListener('click', () => {
    addCurrent();
    dirty = true;
  });
  q('[data-dimming-choices]', form).addEventListener('change', event => {
    if (event.target.value === 'none' && event.target.checked) {
      qa('input[name="dimming_modes"]', form).forEach(input => {
        if (input.value !== 'none') input.checked = false;
      });
    } else if (event.target.checked) {
      const none = q('input[name="dimming_modes"][value="none"]', form);
      if (none) none.checked = false;
    }
    syncPrimaryDimming();
  });
  form.addEventListener('input', () => {
    dirty = true;
    q('[data-power-save-state]', drawer).textContent = '有未保存修改';
  });
  q('[data-power-save]', drawer).addEventListener('click', save);
  q('[data-power-stage-source]', drawer).addEventListener('click', event => stageSource(event.currentTarget));
  q('[data-power-batch-preview-button]', batchDrawer).addEventListener('click', previewBatch);
  q('[data-power-batch-execute]', batchDrawer).addEventListener('click', executeBatch);
  qa('[data-close-layer]', drawer).forEach(button => button.addEventListener('click', event => {
    event.preventDefault();
    event.stopImmediatePropagation();
    closeDrawer(drawer);
  }, true));
  qa('[data-close-layer]', batchDrawer).forEach(button => button.addEventListener('click', event => {
    event.preventDefault();
    event.stopImmediatePropagation();
    closeDrawer(batchDrawer);
  }, true));
})();
