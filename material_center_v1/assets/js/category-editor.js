(() => {
  const q = (selector, root = document) => root.querySelector(selector);
  const qa = (selector, root = document) => [...root.querySelectorAll(selector)];
  const drawer = q('[data-category-editor]');
  if (!drawer) return;

  const workspace = q(`[data-workspace][data-category-code="${CSS.escape(drawer.dataset.categoryCode || '')}"]`);
  const form = q('[data-category-editor-form]', drawer);
  const fieldsRoot = q('[data-category-editor-fields]', drawer);
  const overlay = q('[data-overlay]');
  const categoryCode = drawer.dataset.categoryCode || '';
  const categoryTitle = drawer.dataset.categoryTitle || '物料';
  let active = null;
  let dirty = false;

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

  const post = async values => {
    values.set('csrf_token', window.MC_CSRF || '');
    return request(`${window.MC_BASE_URL}/api/v1/material-master.php`, { method: 'POST', body: values });
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

  const loadFields = async materialId => {
    fieldsRoot.replaceChildren();
    const payload = await request(`${window.MC_BASE_URL}/api/v1/category-fields.php?category=${encodeURIComponent(categoryCode)}&material_id=${encodeURIComponent(materialId || 0)}`);
    const fields = payload.data?.fields || [];
    const values = payload.data?.values || {};
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

  const setReadonly = readonly => {
    qa('input,select,textarea', form).forEach(control => {
      if (control.type !== 'hidden') control.disabled = readonly;
    });
    q('[data-category-save]', drawer).hidden = readonly;
    q('[data-category-submit]', drawer).hidden = readonly;
    q('[data-category-copy]', drawer).hidden = !active?.id;
    q('[data-category-reference]', drawer).hidden = !active?.id;
  };

  const openEditor = async record => {
    active = record || null;
    form.reset();
    q('[data-category-editor-error]', drawer).hidden = true;
    setValue('id', record?.id || '');
    setValue('lock_version', record?.lock_version || 1);
    setValue('category_id', record?.category_id || drawer.dataset.categoryId || '');
    setValue('name', record?.name || '');
    setValue('brand', record?.brand || '');
    setValue('model', record?.model || '');
    setValue('unit', record?.unit || 'PCS');
    setValue('spec_summary', record?.spec || '');
    q('[data-category-editor-title]', drawer).textContent = record?.name || `新建${categoryTitle}`;
    q('[data-category-editor-subtitle]', drawer).textContent = record
      ? `${record.code || ''} · ${record.raw_status === 'draft' ? '草稿可编辑' : '当前状态只读'}`
      : '创建物料中心真实草稿';
    q('[data-category-editor-state]', drawer).textContent = record?.status === 'draft' ? '等待修改' : (record ? '只读查看' : '尚未保存');
    open();
    try {
      await loadFields(record?.id || 0);
      setReadonly(Boolean(record && record.raw_status !== 'draft'));
      dirty = false;
    } catch (error) {
      q('[data-category-editor-error]', drawer).textContent = error.message;
      q('[data-category-editor-error]', drawer).hidden = false;
      setReadonly(true);
    }
  };

  const action = async actionName => {
    if (!active?.id) return;
    const body = new FormData();
    body.set('action', actionName);
    body.set('material_id', active.id);
    const result = await post(body);
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
      const record = row ? JSON.parse(row.dataset.record || '{}') : null;
      openEditor(record).catch(error => notify('打开失败', error.message));
      return;
    }
    if (isWorkspaceRow && row.dataset.readOnly !== '1' && !event.target.closest('input,a,button')) {
      event.preventDefault();
      event.stopImmediatePropagation();
      openEditor(JSON.parse(row.dataset.record || '{}')).catch(error => notify('打开失败', error.message));
    }
  }, true);

  form.addEventListener('input', () => {
    dirty = true;
    q('[data-category-editor-state]', drawer).textContent = '有未保存修改';
  });

  q('[data-category-save]', drawer)?.addEventListener('click', async buttonEvent => {
    const button = buttonEvent.currentTarget;
    const error = q('[data-category-editor-error]', drawer);
    if (!form.reportValidity()) return;
    button.disabled = true;
    error.hidden = true;
    try {
      const body = new FormData(form);
      body.set('action', 'save');
      const result = await post(body);
      dirty = false;
      q('[data-category-editor-state]', drawer).textContent = '已保存';
      notify(`${categoryTitle}已保存`, result.message || '草稿已写入');
      setTimeout(() => location.reload(), 400);
    } catch (reason) {
      error.textContent = reason.message;
      error.hidden = false;
      q('[data-category-editor-state]', drawer).textContent = '保存失败';
    } finally {
      button.disabled = false;
    }
  });

  q('[data-category-copy]', drawer)?.addEventListener('click', () => action('copy').catch(error => notify('复制失败', error.message)));
  q('[data-category-reference]', drawer)?.addEventListener('click', async () => {
    try {
      const body = new FormData();
      body.set('action', 'references');
      body.set('material_id', active.id);
      const result = await post(body);
      notify('引用检查', Object.entries(result.data || {}).map(([key, value]) => `${key} ${value}`).join('，'));
    } catch (error) {
      notify('检查失败', error.message);
    }
  });
  q('[data-category-submit]', drawer)?.addEventListener('click', () => action('submit').catch(error => notify('提交失败', error.message)));
  qa('[data-close-layer]', drawer).forEach(button => button.addEventListener('click', event => {
    event.preventDefault();
    event.stopImmediatePropagation();
    close();
  }, true));
})();
