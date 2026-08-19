(() => {
  const q = (selector, root = document) => root.querySelector(selector);
  const qa = (selector, root = document) => [...root.querySelectorAll(selector)];
  const drawer = q('[data-category-editor]');
  if (!drawer) return;

  const categoryCode = drawer.dataset.categoryCode || '';
  const categoryTitle = drawer.dataset.categoryTitle || '物料';
  const workspace = q(`[data-workspace][data-category-code="${CSS.escape(categoryCode)}"]`);
  const form = q('[data-category-editor-form]', drawer);
  const fieldsRoot = q('[data-category-editor-fields]', drawer);
  const overlay = q('[data-overlay]');
  let active = null;
  let sourceDetail = null;
  let dirty = false;
  let canApprove = false;

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
      throw new Error('服务器没有返回有效数据。');
    }
    if (!response.ok || !payload.ok) throw new Error(payload.message || '操作失败。');
    return payload;
  };

  const postMaterial = async values => {
    values.set('csrf_token', window.MC_CSRF || '');
    return request(`${window.MC_BASE_URL}/api/v1/material-master.php`, { method: 'POST', body: values });
  };

  const postSource = async mode => {
    const body = new FormData();
    body.set('csrf_token', window.MC_CSRF || '');
    body.set('source_record_id', sourceDetail.source.id);
    body.set('category', categoryCode);
    body.set('mode', mode);
    body.set('payload', JSON.stringify(collectPayload()));
    return request(`${window.MC_BASE_URL}/api/v1/source-material.php`, { method: 'POST', body });
  };

  const open = () => {
    qa('[data-drawer].is-open,[data-modal].is-open').forEach(layer => layer.classList.remove('is-open'));
    drawer.classList.add('is-open');
    overlay?.classList.add('is-visible');
    document.body.style.overflow = 'hidden';
  };

  const close = () => {
    if (dirty && !confirm(`${categoryTitle}资料尚未保存，确认关闭？`)) return;
    drawer.classList.remove('is-open');
    overlay?.classList.remove('is-visible');
    document.body.style.overflow = '';
    dirty = false;
  };

  const fieldControl = field => {
    const control = field.data_type === 'enum' || field.data_type === 'boolean'
      ? document.createElement('select')
      : document.createElement(field.data_type === 'textarea' ? 'textarea' : 'input');
    control.name = `fields[${field.field_code}]`;
    control.required = Number(field.is_required) === 1;
    if (field.data_type === 'enum') {
      const blank = document.createElement('option');
      blank.value = '';
      blank.textContent = '待确认';
      control.append(blank, ...(field.options || []).map(item => {
        const option = document.createElement('option');
        option.value = item.value;
        option.textContent = item.label;
        return option;
      }));
    } else if (field.data_type === 'boolean') {
      [['', '待确认'], ['1', '是'], ['0', '否']].forEach(item => {
        const option = document.createElement('option');
        option.value = item[0];
        option.textContent = item[1];
        control.append(option);
      });
    } else if (field.data_type === 'decimal' || field.data_type === 'integer') {
      control.type = 'number';
      control.step = field.data_type === 'integer' ? '1' : 'any';
      if (field.validation?.min !== undefined) control.min = field.validation.min;
      if (field.validation?.max !== undefined) control.max = field.validation.max;
    } else if (field.data_type === 'textarea') {
      control.rows = 3;
    } else {
      control.type = 'text';
      if (field.validation?.maxLength) control.maxLength = field.validation.maxLength;
    }
    return control;
  };

  const loadFields = async (materialId, initialValues = {}) => {
    fieldsRoot.replaceChildren();
    const payload = await request(`${window.MC_BASE_URL}/api/v1/category-fields.php?category=${encodeURIComponent(categoryCode)}&material_id=${encodeURIComponent(materialId || 0)}`);
    const fields = payload.data?.fields || [];
    const savedValues = payload.data?.values || {};
    canApprove = Boolean(payload.data?.can_approve);
    const values = materialId ? savedValues : initialValues;
    fields.forEach(field => {
      const label = document.createElement('label');
      label.className = 'mc-field';
      const title = document.createElement('span');
      title.textContent = `${field.field_name}${field.unit ? `（${field.unit}）` : ''}${Number(field.is_required) === 1 ? ' *' : ''}`;
      const control = fieldControl(field);
      const saved = values[field.field_code];
      if (saved !== undefined && saved !== null) control.value = String(saved);
      else if (field.default !== undefined && field.default !== null) control.value = String(field.default);
      label.append(title, control);
      fieldsRoot.append(label);
    });
    if (!fields.length) {
      const empty = document.createElement('div');
      empty.className = 'mc-empty-inline';
      empty.textContent = '当前分类尚未配置可编辑字段。';
      fieldsRoot.append(empty);
    }
  };

  const setValue = (name, value) => {
    const control = form.elements.namedItem(name);
    if (control) control.value = value == null ? '' : String(value);
  };

  const baseFrom = value => ({
    id: value?.id || '',
    lock_version: value?.lock_version || 1,
    category_id: value?.category_id || drawer.dataset.categoryId || '',
    name: value?.name || '',
    brand: value?.brand || '',
    model: value?.model || '',
    unit: value?.unit || 'PCS',
    spec_summary: value?.spec_summary ?? value?.spec ?? '',
    supplier_text: value?.supplier_text || '',
    remark: value?.remark || '',
  });

  const fillBase = values => {
    Object.entries(baseFrom(values)).forEach(([name, value]) => setValue(name, value));
  };

  const renderSource = detail => {
    const root = q('[data-category-source-fields]', drawer);
    const snapshot = q('[data-category-source-snapshot]', drawer);
    const log = q('[data-category-parse-log]', drawer);
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
      ['来源编号', source.source_id],
      ['来源系统', source.source_system],
      ['来源表', source.source_table],
      ['原始名称', source.raw_name],
      ['原始品牌', source.raw_brand],
      ['原始型号', source.raw_model],
      ['原始规格', source.raw_spec],
      ['同步时间', source.read_at],
      ['解析置信度', `${source.confidence_score}%`],
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
      warning.textContent = '来源快照已有变化。系统没有覆盖人工修正字段，请核对后重新保存。';
      root.prepend(warning);
    }
    snapshot.textContent = JSON.stringify(source.snapshot || {}, null, 2);
    const parsed = detail.parse_result || [];
    if (parsed.length) {
      const title = document.createElement('strong');
      title.textContent = '解析日志';
      log.append(title, ...parsed.map(item => {
        const row = document.createElement('div');
        row.textContent = `${item.field} → ${Array.isArray(item.candidate_value) ? item.candidate_value.join(', ') : item.candidate_value}（${item.confidence}%）`;
        return row;
      }));
    }
  };

  const switchTab = tab => {
    drawer.dataset.categoryActiveTab = tab;
    qa('[data-category-tab]', drawer).forEach(button => button.classList.toggle('is-active', button.dataset.categoryTab === tab));
    qa('[data-category-pane]', drawer).forEach(pane => { pane.hidden = pane.dataset.categoryPane !== tab; });
  };

  const setReadonly = (readonly, status = '', canApprove = false) => {
    qa('input,select,textarea', form).forEach(control => {
      if (control.type !== 'hidden') control.disabled = readonly;
    });
    q('[data-category-save]', drawer).hidden = readonly;
    q('[data-category-submit]', drawer).hidden = readonly || status !== 'draft';
    const canDirectApprove = sourceDetail
      ? ['', 'draft', 'pending_review'].includes(status)
      : status === 'pending_review';
    q('[data-category-approve]', drawer).hidden = !canApprove || !canDirectApprove;
    q('[data-category-copy]', drawer).hidden = !active?.id;
    const revisionButton = q('[data-category-revision]', drawer);
    if (revisionButton) revisionButton.hidden = !active?.id || status !== 'official';
    q('[data-category-reference]', drawer).hidden = !active?.id;
  };

  const fetchSource = sourceRecordId => request(
    `${window.MC_BASE_URL}/api/v1/source-material.php?source_record_id=${encodeURIComponent(sourceRecordId)}&category=${encodeURIComponent(categoryCode)}`
  ).then(payload => payload.data);

  const emitOpened = (materialId, status, editable) => {
    document.dispatchEvent(new CustomEvent('mc:category-editor-opened', {
      detail: {
        categoryCode,
        materialId: Number(materialId || 0),
        status: status || '',
        editable: Boolean(editable),
        record: active || null,
      },
    }));
  };

  const openEditor = async record => {
    active = record || null;
    sourceDetail = null;
    form.reset();
    fieldsRoot.replaceChildren();
    q('[data-category-editor-error]', drawer).hidden = true;
    switchTab('fields');
    renderSource(null);
    open();
    q('[data-category-editor-title]', drawer).textContent = record?.name || `新建${categoryTitle}`;
    q('[data-category-editor-subtitle]', drawer).textContent = record
      ? `${record.code || ''} · 正在读取完整资料`
      : '手工创建一条 BOM 中不存在的全新物料';
    q('[data-category-editor-state]', drawer).textContent = '正在读取';

    try {
      if (record?.source_record_id) {
        sourceDetail = await fetchSource(record.source_record_id);
        renderSource(sourceDetail);
      }
      const mapped = sourceDetail?.material || null;
      const current = mapped || (record?.read_only ? sourceDetail?.defaults : record);
      active = mapped
        ? { ...record, ...mapped, id: Number(mapped.id), raw_status: mapped.status, read_only: false }
        : record;
      fillBase(current);
      const materialId = Number(mapped?.id || (record?.read_only ? 0 : record?.id) || 0);
      await loadFields(materialId, sourceDetail?.defaults?.fields || {});
      const status = mapped?.status || (record?.read_only ? '' : record?.raw_status || '');
      const editable = !record || record.read_only || status === 'draft';
      setReadonly(!editable, status, Boolean(sourceDetail?.can_approve || canApprove) && Boolean(sourceDetail || active?.id));
      q('[data-category-editor-title]', drawer).textContent = current?.name || `整理${categoryTitle}`;
      q('[data-category-editor-subtitle]', drawer).textContent = record?.read_only
        ? `${record.code} · 来源记录整理`
        : `${record?.code || mapped?.material_code || ''} · ${status === 'draft' ? '草稿可编辑' : (status === 'pending_review' ? '等待确认' : '当前状态只读')}`;
      q('[data-category-editor-state]', drawer).textContent = editable ? (record?.read_only ? '确认解析字段后保存草稿' : '等待修改') : '当前状态只读';
      dirty = false;
      emitOpened(materialId, status, editable);
    } catch (error) {
      q('[data-category-editor-error]', drawer).textContent = error.message;
      q('[data-category-editor-error]', drawer).hidden = false;
      q('[data-category-editor-state]', drawer).textContent = '读取失败';
      setReadonly(true);
      emitOpened(0, '', false);
    }
  };

  const collectPayload = () => {
    const payload = { fields: {} };
    ['id', 'lock_version', 'category_id', 'name', 'brand', 'model', 'unit', 'spec_summary', 'supplier_text', 'remark'].forEach(name => {
      const control = form.elements.namedItem(name);
      if (control) payload[name] = control.value;
    });
    qa('[name^="fields["]', form).forEach(control => {
      const match = control.name.match(/^fields\[(.+)]$/);
      if (match) payload.fields[match[1]] = control.value;
    });
    return payload;
  };

  const save = async mode => {
    const error = q('[data-category-editor-error]', drawer);
    if (mode !== 'approve' && !form.reportValidity()) return;
    error.hidden = true;
    q('[data-category-editor-state]', drawer).textContent = '正在保存…';
    const buttons = qa('[data-category-save],[data-category-submit],[data-category-approve]', drawer);
    buttons.forEach(button => { button.disabled = true; });
    try {
      let result;
      if (sourceDetail) {
        result = await postSource(mode);
      } else if (mode === 'draft') {
        const body = new FormData(form);
        body.set('action', 'save');
        result = await postMaterial(body);
      } else {
        const body = new FormData();
        body.set('action', mode === 'submit' ? 'submit' : 'approve');
        body.set('material_id', active?.id || form.elements.id.value);
        result = await postMaterial(body);
      }
      dirty = false;
      q('[data-category-editor-state]', drawer).textContent = '已保存';
      notify(`${categoryTitle}操作成功`, result.message || '数据已写入');
      setTimeout(() => location.reload(), 450);
    } catch (reason) {
      error.textContent = reason.message;
      error.hidden = false;
      q('[data-category-editor-state]', drawer).textContent = '保存失败';
    } finally {
      buttons.forEach(button => { button.disabled = false; });
    }
  };

  const materialAction = async actionName => {
    if (!active?.id) return;
    const body = new FormData();
    body.set('action', actionName);
    body.set('material_id', active.id);
    const result = await postMaterial(body);
    notify('操作成功', result.message || '操作已完成');
    setTimeout(() => location.reload(), 400);
  };

  document.addEventListener('click', event => {
    const trigger = event.target.closest('[data-open-category-editor]');
    const row = event.target.closest('[data-row]');
    const isWorkspaceRow = row?.closest('[data-workspace]') === workspace;
    if (trigger && (!row || isWorkspaceRow)) {
      event.preventDefault();
      event.stopImmediatePropagation();
      let record = null;
      if (row?.dataset.record) {
        try {
          record = JSON.parse(row.dataset.record);
        } catch {
          notify('打开失败', '当前行资料无法读取，请刷新后重试。');
          return;
        }
      }
      openEditor(record).catch(error => notify('打开失败', error.message));
      return;
    }
    if (isWorkspaceRow && row.dataset.readOnly !== '1' && !event.target.closest('input,a,button')) {
      event.preventDefault();
      event.stopImmediatePropagation();
      try {
        openEditor(JSON.parse(row.dataset.record || '{}')).catch(error => notify('打开失败', error.message));
      } catch {
        notify('打开失败', '当前行资料无法读取，请刷新后重试。');
      }
    }
  }, true);

  form.addEventListener('input', () => {
    dirty = true;
    q('[data-category-editor-state]', drawer).textContent = '有未保存修改';
  });
  qa('[data-category-tab]', drawer).forEach(button => button.addEventListener('click', () => switchTab(button.dataset.categoryTab)));
  q('[data-category-save]', drawer)?.addEventListener('click', () => save('draft'));
  q('[data-category-submit]', drawer)?.addEventListener('click', () => save('submit'));
  q('[data-category-approve]', drawer)?.addEventListener('click', () => {
    if (confirm('确认字段无误并将物料转为正式？正式物料不能物理删除。')) save('approve');
  });
  q('[data-category-copy]', drawer)?.addEventListener('click', () => materialAction('copy').catch(error => notify('复制失败', error.message)));
  q('[data-category-revision]', drawer)?.addEventListener('click', () => {
    if (!active?.id) return;
    if (!confirm('从当前正式物料生成一份可编辑修订草稿？旧正式物料不会被修改。')) return;
    materialAction('revision_draft').catch(error => notify('生成失败', error.message));
  });
  q('[data-category-reference]', drawer)?.addEventListener('click', async () => {
    try {
      const body = new FormData();
      body.set('action', 'references');
      body.set('material_id', active.id);
      const result = await postMaterial(body);
      notify('引用检查', Object.entries(result.data || {}).map(([key, value]) => `${key} ${value}`).join('，'));
    } catch (error) {
      notify('检查失败', error.message);
    }
  });
  qa('[data-close-layer]', drawer).forEach(button => button.addEventListener('click', event => {
    event.preventDefault();
    event.stopImmediatePropagation();
    close();
  }, true));

  const requestedSource = Number(new URLSearchParams(location.search).get('organize_source') || 0);
  window.MC_CATEGORY_EDITOR = {
    active: () => active,
    switchTab,
  };
  if (requestedSource) {
    const row = qa('[data-row]', workspace).find(candidate => {
      try {
        return Number(JSON.parse(candidate.dataset.record || '{}').source_record_id) === requestedSource;
      } catch {
        return false;
      }
    });
    if (row) {
      openEditor(JSON.parse(row.dataset.record || '{}')).catch(error => notify('打开失败', error.message));
    }
  }
})();
