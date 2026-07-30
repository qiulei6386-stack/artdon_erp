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
  let sourceDetail = null;

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

  const switchPowerTab = tab => {
    qa('[data-power-tab]', drawer).forEach(button => button.classList.toggle('is-active', button.dataset.powerTab === tab));
    qa('[data-power-pane]', drawer).forEach(pane => { pane.hidden = pane.dataset.powerPane !== tab; });
  };

  const renderSource = detail => {
    const root = q('[data-power-source-fields]', drawer);
    const snapshot = q('[data-power-source-snapshot]', drawer);
    const log = q('[data-power-parse-log]', drawer);
    root.replaceChildren();
    log.replaceChildren();
    if (!detail?.source) {
      const empty = document.createElement('div');
      empty.className = 'mc-empty-inline';
      empty.textContent = '当前物料没有旧 BOM 来源映射。';
      root.append(empty);
      snapshot.textContent = '—';
      return;
    }
    const source = detail.source;
    [
      ['来源编号', source.source_id], ['来源系统', source.source_system], ['来源表', source.source_table],
      ['原始名称', source.raw_name], ['原始品牌', source.raw_brand], ['原始型号', source.raw_model],
      ['原始规格', source.raw_spec], ['同步时间', source.read_at], ['解析置信度', `${source.confidence_score}%`],
    ].forEach(([label, value]) => {
      const item = document.createElement('div');
      const strong = document.createElement('strong');
      const span = document.createElement('span');
      strong.textContent = label;
      span.textContent = value || '—';
      item.append(strong, span);
      root.append(item);
    });
    if (source.changed) {
      const warning = document.createElement('div');
      warning.className = 'mc-source-change-warning';
      warning.textContent = '来源快照已有变化。人工修正字段没有被覆盖，请核对后重新保存。';
      root.prepend(warning);
    }
    snapshot.textContent = JSON.stringify(source.snapshot || {}, null, 2);
    if ((detail.parse_result || []).length) {
      const title = document.createElement('strong');
      title.textContent = '解析日志';
      log.append(title, ...detail.parse_result.map(item => {
        const row = document.createElement('div');
        row.textContent = `${item.field} → ${Array.isArray(item.candidate_value) ? item.candidate_value.join(', ') : item.candidate_value}（${item.confidence}%）`;
        return row;
      }));
    }
  };

  const fetchSource = sourceRecordId => request(
    `${window.MC_BASE_URL}/api/v1/source-material.php?source_record_id=${encodeURIComponent(sourceRecordId)}&category=power_supply`
  );

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
    q('[data-power-submit]', drawer).hidden = readonly;
  };

  const fillDetail = data => {
    if (activeRecord) activeRecord = { ...activeRecord, status: data.status, material_id: data.material_id || data.id };
    form.reset();
    q('[data-current-list]', form).replaceChildren();
    qa('input[name="dimming_modes"]', form).forEach(input => { input.checked = false; });
    const basic = ['material_id', 'lock_version', 'name', 'brand', 'model', 'unit', 'spec_summary', 'supplier_text', 'remark'];
    basic.forEach(key => setSelect(key, data[key]));
    const scalar = [
      'nominal_power_w', 'min_output_power_w', 'max_output_power_w', 'power_band_id',
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
    q('[data-power-save-state]', drawer).textContent = data.editable ? '未修改' : '当前状态只读';
    q('[data-power-submit]', drawer).hidden = data.status !== 'draft';
    q('[data-power-approve]', drawer).hidden = data.status !== 'pending_review' || !schema.can_approve;
    q('[data-power-error]', drawer).hidden = true;
    dirty = false;
  };

  const openNew = async () => {
    activeRecord = null;
    sourceDetail = null;
    await ensureSchema();
    switchPowerTab('fields');
    renderSource(null);
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
    qa('[data-price-field]', form).forEach(field => field.hidden = !schema.can_view_price);
    q('[data-power-save-state]', drawer).textContent = '尚未保存';
    q('[data-power-submit]', drawer).hidden = true;
    q('[data-power-approve]', drawer).hidden = true;
    q('[data-power-error]', drawer).hidden = true;
    dirty = false;
    openDrawer(drawer);
  };

  const openMaterial = async record => {
    activeRecord = record;
    sourceDetail = null;
    openDrawer(drawer);
    switchPowerTab('fields');
    renderSource(null);
    q('[data-power-editor-title]', drawer).textContent = record.name || '电源资料';
    q('[data-power-editor-subtitle]', drawer).textContent = '正在读取完整资料…';
    try {
      await ensureSchema();
      const detail = await request(`${window.MC_BASE_URL}/api/v1/power-editor.php?action=detail&material_id=${encodeURIComponent(record.id)}`);
      if (record.source_record_id || detail.source_record_id) {
        sourceDetail = await fetchSource(record.source_record_id || detail.source_record_id);
        renderSource(sourceDetail);
      }
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
    sourceDetail = await fetchSource(record.source_record_id);
    switchPowerTab('fields');
    renderSource(sourceDetail);
    form.reset();
    q('[data-current-list]', form).replaceChildren();
    const defaults = sourceDetail.defaults || {};
    const parsed = defaults.fields || {};
    const parsedValue = key => parsed[`power.${key}`] ?? '';
    const currents = parsedValue('current_options_ma');
    if (Array.isArray(currents) && currents.length) currents.forEach((value, index) => addCurrent(value, index === 0));
    else if (parsedValue('output_current_ma')) addCurrent(parsedValue('output_current_ma'), true);
    else addCurrent();
    setSelect('name', defaults.name || record.name);
    setSelect('brand', defaults.brand || record.brand);
    setSelect('model', defaults.model || record.model);
    setSelect('spec_summary', defaults.spec_summary || record.spec);
    setSelect('supplier_text', defaults.supplier_text || '');
    setSelect('remark', defaults.remark || '');
    setSelect('material_id', '');
    setSelect('lock_version', '1');
    setSelect('unit', 'PCS');
    setSelect('installation_type', 'unknown');
    setSelect('output_type', 'unknown');
    [
      'nominal_power_w', 'min_output_power_w', 'max_output_power_w', 'power_band_id',
      'input_voltage_min_v', 'input_voltage_max_v', 'input_frequency_min_hz', 'input_frequency_max_hz',
      'power_factor', 'efficiency', 'output_type', 'output_voltage_min_v', 'output_voltage_max_v',
      'installation_type', 'length_mm', 'width_mm', 'height_mm', 'supplier_warranty_years',
    ].forEach(key => {
      if (parsedValue(key) !== '') setSelect(key, parsedValue(key));
    });
    if (!parsedValue('power_band_id') && parsedValue('max_output_power_w')) {
      const power = Number(parsedValue('max_output_power_w'));
      const band = schema.bands.find(item => power >= Number(item.min_power_w)
        && (power < Number(item.max_power_w) || (Number(item.max_inclusive) === 1 && power <= Number(item.max_power_w))));
      if (band) setSelect('power_band_id', band.id);
    }
    const parsedDimming = parsedValue('dimming_mode');
    if (parsedDimming) {
      const dimming = q(`input[name="dimming_modes"][value="${CSS.escape(String(parsedDimming))}"]`, form);
      if (dimming) dimming.checked = true;
      syncPrimaryDimming();
      setSelect('primary_dimming', parsedDimming);
    }
    setReadonly(false);
    q('[data-power-editor-title]', drawer).textContent = record.name || '旧 BOM 电源';
    q('[data-power-editor-subtitle]', drawer).textContent = `${record.code} · 待整理`;
    q('[data-power-source-note]', drawer).hidden = false;
    q('[data-power-save-state]', drawer).textContent = '确认字段后保存为草稿';
    q('[data-power-submit]', drawer).hidden = false;
    q('[data-power-approve]', drawer).hidden = !schema.can_approve;
    openDrawer(drawer);
  };

  const formPayload = () => {
    const payload = {};
    const names = [
      'lock_version', 'name', 'brand', 'model', 'unit', 'spec_summary', 'supplier_text', 'remark',
      'nominal_power_w', 'min_output_power_w', 'max_output_power_w', 'power_band_id',
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

  const lifecycleRequest = async (materialId, action) => {
    const body = new FormData();
    body.set('csrf_token', window.MC_CSRF || '');
    body.set('action', action);
    body.set('material_id', materialId);
    return request(`${window.MC_BASE_URL}/api/v1/material-master.php`, { method: 'POST', body });
  };

  const save = async (mode = 'draft', trigger = null) => {
    const error = q('[data-power-error]', drawer);
    const button = trigger || q('[data-power-save]', drawer);
    const idleLabel = button.textContent;
    const pendingLabel = mode === 'approve' ? '正在转正式…' : (mode === 'submit' ? '正在提交…' : '正在保存…');
    error.hidden = true;
    if (mode === 'approve' && !activeRecord?.read_only && activeRecord?.status === 'pending_review') {
      button.disabled = true;
      button.setAttribute('aria-busy', 'true');
      button.textContent = pendingLabel;
      q('[data-power-save-state]', drawer).textContent = pendingLabel;
      try {
        await lifecycleRequest(activeRecord.material_id || form.elements.material_id.value, 'approve');
        toast('电源已转正式', '生命周期已更新。');
        setTimeout(() => location.reload(), 500);
        return true;
      } catch (reason) {
        error.textContent = reason instanceof Error ? reason.message : '保存失败。';
        error.hidden = false;
        q('[data-power-save-state]', drawer).textContent = '转正式失败';
        return false;
      } finally {
        button.disabled = false;
        button.removeAttribute('aria-busy');
        button.textContent = idleLabel;
      }
    }
    if (!form.reportValidity()) return;
    button.disabled = true;
    button.setAttribute('aria-busy', 'true');
    button.textContent = pendingLabel;
    q('[data-power-save-state]', drawer).textContent = pendingLabel;
    try {
      const values = {
        material_id: form.elements.material_id.value,
        payload: JSON.stringify(formPayload()),
      };
      const action = activeRecord?.read_only ? 'source_draft' : 'save';
      if (activeRecord?.read_only) values.source_record_id = activeRecord.source_record_id;
      const detail = await post(action, values);
      if (mode === 'submit' || mode === 'approve') {
        await lifecycleRequest(detail.material_id || detail.id, 'submit');
      }
      if (mode === 'approve') {
        await lifecycleRequest(detail.material_id || detail.id, 'approve');
      }
      fillDetail(detail);
      q('[data-power-save-state]', drawer).textContent = '已保存';
      toast(
        mode === 'approve' ? '电源已转正式' : (mode === 'submit' ? '电源已提交确认' : '电源已保存'),
        mode === 'draft' ? '全部字段、多电流和调光方式已写入。' : '来源映射和生命周期已更新。'
      );
      setTimeout(() => location.reload(), 500);
      return true;
    } catch (reason) {
      error.textContent = reason.message;
      error.hidden = false;
      q('[data-power-save-state]', drawer).textContent = mode === 'approve' ? '转正式失败' : (mode === 'submit' ? '提交失败' : '保存失败');
      toast(q('[data-power-save-state]', drawer).textContent, reason.message);
      return false;
    } finally {
      button.disabled = false;
      button.removeAttribute('aria-busy');
      button.textContent = idleLabel;
    }
  };

  const lifecycle = async (action, trigger) => {
    const materialId = form.elements.material_id.value;
    const error = q('[data-power-error]', drawer);
    const state = q('[data-power-save-state]', drawer);
    const idleLabel = trigger.textContent;
    const pendingLabel = action === 'approve' ? '正在转正式…' : '正在提交…';
    error.hidden = true;
    if (!materialId) {
      error.textContent = '当前电源尚未形成物料草稿，请先保存后再操作。';
      error.hidden = false;
      state.textContent = '状态操作失败';
      return false;
    }
    trigger.disabled = true;
    trigger.setAttribute('aria-busy', 'true');
    trigger.textContent = pendingLabel;
    state.textContent = pendingLabel;
    try {
      const response = await lifecycleRequest(materialId, action);
      dirty = false;
      state.textContent = action === 'approve' ? '已转正式' : '已提交确认';
      toast(action === 'approve' ? '电源已转正式' : '电源已提交确认', response?.message || '状态已更新');
      setTimeout(() => location.reload(), 400);
      return true;
    } catch (reason) {
      error.textContent = reason.message;
      error.hidden = false;
      state.textContent = action === 'approve' ? '转正式失败' : '提交失败';
      toast(state.textContent, reason.message);
      return false;
    } finally {
      trigger.disabled = false;
      trigger.removeAttribute('aria-busy');
      trigger.textContent = idleLabel;
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
    { key: 'min_output_power_w', label: '最低输出功率', help: '单位 W', type: 'number' },
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
  q('[data-power-save]', drawer).addEventListener('click', event => save('draft', event.currentTarget));
  q('[data-power-submit]', drawer).addEventListener('click', event => {
    event.preventDefault();
    event.stopPropagation();
    const button = event.currentTarget;
    if (button.disabled) return;
    if (activeRecord?.read_only) save('submit', button);
    else lifecycle('submit', button);
  });
  q('[data-power-approve]', drawer).addEventListener('click', event => {
    event.preventDefault();
    event.stopPropagation();
    const button = event.currentTarget;
    if (button.disabled) return;
    if (activeRecord?.read_only) save('approve', button);
    else lifecycle('approve', button);
  });
  q('[data-power-batch-preview-button]', batchDrawer).addEventListener('click', previewBatch);
  q('[data-power-batch-execute]', batchDrawer).addEventListener('click', executeBatch);
  qa('[data-power-tab]', drawer).forEach(button => button.addEventListener('click', () => switchPowerTab(button.dataset.powerTab)));
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

  const requestedSource = Number(new URLSearchParams(location.search).get('organize_source') || 0);
  if (requestedSource) {
    const row = qa('[data-row]', workspace).find(candidate => {
      try {
        return Number(JSON.parse(candidate.dataset.record || '{}').source_record_id) === requestedSource;
      } catch {
        return false;
      }
    });
    if (row) {
      openSource(JSON.parse(row.dataset.record || '{}')).catch(error => toast('打开失败', error.message));
    }
  }
})();
