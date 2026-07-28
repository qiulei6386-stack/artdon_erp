(() => {
  const q = (selector, root = document) => root.querySelector(selector);
  const qa = (selector, root = document) => [...root.querySelectorAll(selector)];
  const page = q('[data-adaptation]');
  const bootstrapNode = q('#adaptation-bootstrap');
  if (!page || !bootstrapNode) return;

  const bootstrap = JSON.parse(bootstrapNode.textContent || '{}');
  const state = {
    products: bootstrap.products || [],
    catalogProducts: bootstrap.products || [],
    workspace: bootstrap.workspace || null,
    metadata: bootstrap.metadata || {},
    csrf: bootstrap.csrf || '',
    baseUrl: bootstrap.baseUrl || '/artdon_erp/material_center_v1',
    tab: 'options',
    candidates: [],
    dirty: false,
    draggingGroupId: 0,
    batchSelected: new Set(),
    batchPreview: null,
    batchQuery: '',
    productSelected: new Set(),
    productStatusFilter: 'all',
    templateTargetIds: [],
    reuseSourceWorkspace: null,
    reusePreview: null,
  };

  const escapeHtml = value => String(value ?? '')
    .replaceAll('&', '&amp;')
    .replaceAll('<', '&lt;')
    .replaceAll('>', '&gt;')
    .replaceAll('"', '&quot;')
    .replaceAll("'", '&#039;');
  const integer = value => Number.parseInt(value || 0, 10) || 0;
  const selectedProductId = () => integer(state.workspace?.product?.id);
  const selectedGroup = () => state.workspace?.active_group || null;
  const materialCategoryLabels = {
    power_supply: '电源类正式物料',
    chip: '芯片类正式物料',
    optical: '光学类正式物料',
    profile: '型材 / 散热件类正式物料',
    connector: '接头 / 安装件类正式物料',
    accessory: '配件类正式物料',
    packaging: '包装类正式物料',
  };
  const quickRuleCount = group => Object.entries(group?.quick_rules || {})
    .filter(([key, value]) => key !== 'availability' && value !== '' && value !== null).length;

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
    if (!response.ok || !payload.ok) throw new Error(payload.message || '操作失败');
    return payload.data;
  };

  const get = (action, params = {}) => {
    const url = new URL(`${state.baseUrl}/api/v1/adaptation.php`, location.origin);
    url.searchParams.set('action', action);
    Object.entries(params).forEach(([key, value]) => {
      if (value !== '' && value !== null && value !== undefined) url.searchParams.set(key, String(value));
    });
    return request(url);
  };

  const post = (action, values = {}) => {
    const body = new FormData();
    body.set('csrf_token', state.csrf);
    body.set('action', action);
    Object.entries(values).forEach(([key, value]) => {
      body.set(key, typeof value === 'object' ? JSON.stringify(value) : String(value ?? ''));
    });
    return request(`${state.baseUrl}/api/v1/adaptation.php`, { method: 'POST', body });
  };

  const openModal = id => {
    const modal = q(`#${id}`);
    if (!modal) return;
    modal.classList.add('is-open');
    q('.mc-overlay')?.classList.add('is-visible');
  };

  const closeModal = modal => {
    modal?.classList.remove('is-open');
    if (!q('[data-adaptation-modal].is-open')) q('.mc-overlay')?.classList.remove('is-visible');
    state.dirty = false;
  };

  const updateUrl = () => {
    const url = new URL(location.href);
    const productId = selectedProductId();
    const groupId = integer(selectedGroup()?.id);
    productId ? url.searchParams.set('product_id', productId) : url.searchParams.delete('product_id');
    groupId ? url.searchParams.set('group_id', groupId) : url.searchParams.delete('group_id');
    history.replaceState({}, '', url);
  };

  const productMatchesStatus = product => {
    if (state.productStatusFilter === 'all') return true;
    if (state.productStatusFilter === 'configured') return integer(product.group_count) > 0;
    if (state.productStatusFilter === 'conflict') return integer(product.conflict_count) > 0;
    return product.configuration_state === state.productStatusFilter;
  };

  const visibleProducts = () => state.products.filter(productMatchesStatus);

  const renderProductSelection = () => {
    const count = state.productSelected.size;
    q('[data-product-selection-bar]').hidden = !count;
    q('[data-product-selection-count]').textContent = `已选择 ${count} 个`;
    q('[data-selected-template]').disabled = !count;
    q('[data-selected-batch]').disabled = !count || !state.workspace?.groups?.length;
  };

  const renderProductFilter = () => {
    const counts = {
      all: state.products.length,
      unconfigured: state.products.filter(product => product.configuration_state === 'unconfigured').length,
      configured: state.products.filter(product => integer(product.group_count) > 0).length,
      pending_approval: state.products.filter(product => product.configuration_state === 'pending_approval').length,
      needs_review: state.products.filter(product => product.configuration_state === 'needs_review').length,
      enabled: state.products.filter(product => product.configuration_state === 'enabled').length,
      conflict: state.products.filter(product => integer(product.conflict_count) > 0).length,
    };
    const labels = {
      all: '全部状态',
      unconfigured: '未配置',
      configured: '已配置',
      pending_approval: '待审批',
      needs_review: '待重审',
      enabled: '已启用',
      conflict: '有冲突',
    };
    qa('option', q('[data-product-status-filter]')).forEach(option => {
      option.textContent = `${labels[option.value]}（${counts[option.value] || 0}）`;
    });
    q('[data-product-status-filter]').value = state.productStatusFilter;
  };

  const renderProducts = () => {
    renderProductFilter();
    const products = visibleProducts();
    q('[data-product-count]').textContent = `显示 ${products.length} / 共 ${state.products.length} 个`;
    const activeId = selectedProductId();
    const list = q('[data-product-list]');
    list.innerHTML = products.length
      ? products.map(product => {
        const rawImage = String(product.image_url || '');
        const imageUrl = !rawImage || rawImage.startsWith('http://') || rawImage.startsWith('https://') || rawImage.startsWith('/')
          ? rawImage
          : `${state.baseUrl.replace('/material_center_v1', '')}/${rawImage.startsWith('./') ? rawImage.slice(2) : rawImage}`;
        const image = imageUrl
          ? `<img src="${escapeHtml(imageUrl)}" alt="" loading="lazy" data-product-image>`
          : '<span class="mc-product-thumb__placeholder">◇</span>';
        const approvalClass = product.approval_label === '已启用' ? 'success' : 'warning';
        const productId = integer(product.id);
        return `<article class="mc-product-row ${productId === activeId ? 'is-active' : ''} ${state.productSelected.has(productId) ? 'is-selected' : ''}">
          <label class="mc-product-check" title="选择产品">
            <input type="checkbox" data-product-check value="${productId}" ${state.productSelected.has(productId) ? 'checked' : ''}>
          </label>
          <button type="button" class="mc-product-card" data-product-id="${productId}">
            <span class="mc-product-thumb">${image}</span>
            <span class="mc-product-card__main">
              <strong>${escapeHtml(product.product_code || '未编号')}</strong>
              <small>${escapeHtml(product.product_name || '未命名产品')}</small>
              <em>${escapeHtml(product.series_name || '未设置系列')}</em>
              <span class="mc-product-card__stats">${integer(product.group_count)} 组 · ${integer(product.option_count)} 选项</span>
            </span>
            <span class="mc-product-card__state">
              <b class="mc-badge mc-badge--${approvalClass}">${escapeHtml(product.approval_label)}</b>
              ${integer(product.conflict_count) ? '<i>存在冲突</i>' : '<i class="is-clear">无冲突</i>'}
            </span>
          </button>
        </article>`;
      }).join('')
      : '<div class="mc-empty-state"><strong>没有符合筛选的产品</strong><span>调整配置状态或搜索条件。</span></div>';
    qa('[data-product-image]', list).forEach(image => image.addEventListener('error', () => {
      const placeholder = document.createElement('span');
      placeholder.className = 'mc-product-thumb__placeholder';
      placeholder.textContent = '◇';
      image.replaceWith(placeholder);
    }, { once: true }));
    renderProductSelection();
  };

  const statusLabels = {
    empty: ['未添加选项', 'muted'],
    no_default: ['未设置默认', 'warning'],
    conflict: ['存在冲突', 'danger'],
    pending: ['待审批', 'warning'],
    enabled: ['已启用', 'success'],
    forbidden: ['不允许选配', 'muted'],
    disabled: ['已停用', 'muted'],
  };

  const renderSummary = () => {
    const target = q('[data-product-summary]');
    const workspace = state.workspace;
    if (!workspace) {
      target.innerHTML = '';
      q('[data-rule-subtitle]').textContent = '请选择产品';
      return;
    }
    const product = workspace.product;
    const completion = workspace.completion || { percent: 0 };
    q('[data-rule-subtitle]').textContent = product.product_code || '未编号产品';
    target.innerHTML = `<div class="mc-adaptation-product-identity">
        <div><span>产品型号</span><strong>${escapeHtml(product.product_code || '—')}</strong></div>
        <div><span>产品名称</span><strong>${escapeHtml(product.product_name || '—')}</strong></div>
        <div><span>产品系列</span><strong>${escapeHtml(product.series_name || '—')}</strong></div>
      </div>
      <div class="mc-adaptation-progress-card">
        <div><span>配置完成度</span><strong>${integer(completion.percent)}%</strong></div>
        <div class="mc-completion-track"><i style="width:${integer(completion.percent)}%"></i></div>
        <div class="mc-adaptation-summary-metrics">
          <span><b>${workspace.groups.length}</b> 配置组</span>
          <span><b>${integer(product.option_count)}</b> 总选项</span>
          <span><b>${workspace.conflicts.length}</b> 冲突</span>
          <span><b>${escapeHtml(product.approval_label)}</b> 审批状态</span>
        </div>
      </div>`;
  };

  const overviewStatusLabel = status => ({
    enabled: '已启用',
    pending: '待审批',
    empty: '未添加',
    no_default: '缺默认',
    forbidden: '禁止选配',
    conflict: '有冲突',
    disabled: '已停用',
  }[status] || '待完善');

  const renderOverviewGroup = (group, full = false) => {
    const options = group.options || [];
    const defaultOptions = options.filter(option => integer(option.is_default));
    const optionLines = options.map(option => {
      const variants = option.chip_variants || [];
      return `<li>
        <span><b>${escapeHtml(option.material_code)}</b> ${escapeHtml(option.material_name)}${integer(option.is_default) ? '<em>默认物料</em>' : ''}</span>
        ${variants.length ? `<small>${variants.map(variant => `${escapeHtml(variant.label)}${integer(variant.is_default) ? '（默认规格）' : ''}`).join('、')}</small>` : ''}
      </li>`;
    }).join('');
    return `<article class="mc-overview-group ${group.availability === 'forbidden' ? 'is-forbidden' : ''}">
      <header><div><strong>${escapeHtml(group.name)}</strong><span>${integer(group.is_required) ? '必选' : '可选'} · ${group.selection_mode === 'multi' ? '多选' : '单选'}</span></div><b>${escapeHtml(overviewStatusLabel(group.status))}</b></header>
      <p>标准默认：${defaultOptions.length ? defaultOptions.map(option => escapeHtml(option.material_code)).join('、') : '未设置'} · 可选 ${options.length} 项</p>
      ${full ? `<ul>${optionLines || '<li><span>暂无物料选项</span></li>'}</ul>
        <details><summary>查看关键范围</summary><pre>${escapeHtml(JSON.stringify(group.quick_rules || {}, null, 2))}</pre></details>` : ''}
    </article>`;
  };

  const configuredMaterialText = group => {
    const defaults = (group.options || []).filter(option => integer(option.is_default));
    const visible = defaults.length ? defaults : (group.options || []);
    return visible.length
      ? visible.map(option => `${option.material_code}${option.chip_variants?.length ? `（${option.chip_variants.map(variant => variant.label).join('、')}）` : ''}`).join('、')
      : '未添加物料';
  };

  const renderPersistentConfiguration = () => {
    const target = q('[data-selected-configuration]');
    const workspace = state.workspace;
    if (!workspace?.configuration_overview?.length) {
      target.hidden = true;
      target.innerHTML = '';
      return;
    }
    const activeId = integer(workspace.active_group?.id);
    target.hidden = false;
    target.innerHTML = `<div class="mc-selected-configuration__head"><div><strong>已选物料持续显示</strong><span>切换配置组不会丢失；点击任一项可直接返回编辑。</span></div><button type="button" data-configuration-overview-open>完整清单</button></div>
      <div class="mc-selected-configuration__list">${workspace.configuration_overview.map(group => `<button type="button" class="${integer(group.id) === activeId ? 'is-active' : ''}" data-select-group="${integer(group.id)}">
        <span><strong>${escapeHtml(group.name)}</strong><small>${escapeHtml(configuredMaterialText(group))}</small></span>
        <em>${escapeHtml(overviewStatusLabel(group.status))}</em>
      </button>`).join('')}</div>`;
  };

  const renderConfigurationOverview = () => {
    const root = q('[data-configuration-overview]');
    const overview = state.workspace?.configuration_overview || [];
    if (!state.workspace || !overview.length) {
      root.innerHTML = '';
      return;
    }
    const configured = overview.filter(group => (group.options || []).length).length;
    const forbidden = overview.filter(group => group.availability === 'forbidden').length;
    root.innerHTML = `<button type="button" data-configuration-overview-open>
      <span><strong>当前配置一览</strong><small>${configured} / ${overview.length} 组已有物料${forbidden ? ` · ${forbidden} 组禁止选配` : ''}</small></span>
      <b>查看完整配置 ›</b>
    </button>
    <div>${overview.slice(0, 4).map(group => renderOverviewGroup(group)).join('')}</div>`;
  };

  const openConfigurationOverview = () => {
    const overview = state.workspace?.configuration_overview || [];
    q('[data-configuration-overview-full]').innerHTML = overview.length
      ? `<div class="mc-overview-full-head"><strong>${escapeHtml(state.workspace.product.product_code)} ${escapeHtml(state.workspace.product.product_name)}</strong><span>${escapeHtml(state.workspace.product.approval_label)} · 配置完成度 ${integer(state.workspace.completion?.percent)}%</span></div>
        <div class="mc-overview-full-grid">${overview.map(group => renderOverviewGroup(group, true)).join('')}</div>`
      : '<div class="mc-empty-state"><strong>当前产品尚无配置</strong><span>先生成或建立配置组。</span></div>';
    openModal('configuration-overview-modal');
  };

  const renderGroups = () => {
    const list = q('[data-group-list]');
    const workspace = state.workspace;
    q('[data-group-create]').disabled = !workspace;
    q('[data-template-open]').disabled = !workspace;
    q('[data-batch-open]').disabled = !workspace?.groups?.length;
    q('[data-reuse-open]').disabled = !workspace;
    q('[data-template-open]').textContent = workspace?.groups?.length ? '补齐完整标准模板' : '套用完整标准模板';
    renderProductSelection();
    if (!workspace) {
      list.innerHTML = '<div class="mc-empty-state"><strong>请选择产品</strong><span>从左侧产品列表开始。</span></div>';
      return;
    }
    if (!workspace.groups.length) {
      list.innerHTML = `<div class="mc-empty-state mc-empty-state--action">
        <strong>当前产品尚未建立完整选配结构</strong>
        <span>一键建立全部 10 个标准配置组，再逐组添加正式物料、默认项和适用条件。</span>
        <button class="mc-button mc-button--primary" type="button" data-empty-template>套用完整标准模板</button>
      </div>`;
      return;
    }
    const activeId = integer(workspace.active_group?.id);
    list.innerHTML = workspace.groups.map(group => {
      const status = statusLabels[group.display_status] || statusLabels.pending;
      const powerRule = group.material_category_code === 'power_supply' ? (workspace.power_rule || {}) : null;
      const ruleCount = powerRule ? Object.entries(powerRule).filter(([key, value]) => !['id', 'legacy_product_id', 'rule_name', 'status', 'created_at', 'updated_at', 'created_by', 'updated_by', 'required_dimming_modes'].includes(key) && value !== '' && value !== null && value !== 'unknown').length + integer(powerRule.required_dimming_modes?.length) : quickRuleCount(group);
      const ruleLabel = group.quick_rules?.availability === 'forbidden'
        ? '不允许选配'
        : (ruleCount ? `已填写 ${ruleCount} 项` : '待填写');
      return `<article class="mc-adaptation-group-card ${integer(group.id) === activeId ? 'is-active' : ''}" draggable="true" data-group-id="${integer(group.id)}">
        <button class="mc-adaptation-group-card__main" type="button" data-select-group="${integer(group.id)}">
          <span class="mc-drag-handle" title="拖动排序">⋮⋮</span>
          <span class="mc-rule-group__icon">◇</span>
          <span class="mc-adaptation-group-card__name">
            <strong>${escapeHtml(group.group_name)}</strong>
            <small>${integer(group.is_required) ? '必选' : '可选'} · ${group.selection_mode === 'multi' ? '多选' : '单选'} · 来源：${escapeHtml(materialCategoryLabels[group.material_category_code] || group.material_category_code)}</small>
            <em class="${ruleCount ? 'is-complete' : ''}">关键范围：${escapeHtml(ruleLabel)}</em>
            <em>默认：${escapeHtml(group.default_material || '未设置')}</em>
          </span>
          <span class="mc-adaptation-group-counts">
            <b>${integer(group.option_count)} 选项</b>
            <small>${integer(group.alternative_count)} 替代 · ${integer(group.condition_count)} 条件</small>
            <small>${integer(group.conflict_count)} 冲突</small>
          </span>
          <span class="mc-badge mc-badge--${status[1]}">${status[0]}</span>
        </button>
        <button class="mc-icon-button mc-group-edit" type="button" data-edit-group="${integer(group.id)}" title="编辑配置组">•••</button>
      </article>`;
    }).join('');
  };

  const optionMatchLabel = option => ({
    exact: '完全适配',
    conditional: '条件适配',
    needs_approval: '需要审批',
    incompatible: '不适配',
  }[option.match_level] || '需要审批');

  const renderOptionPanel = () => {
    const workspace = state.workspace;
    const group = selectedGroup();
    const detail = q('[data-option-detail]');
    renderPersistentConfiguration();
    q('[data-option-tabs]').hidden = !group;
    q('[data-candidate-open]').disabled = !group;
    q('[data-open-quick-rules]').disabled = !group;
    q('[data-option-subtitle]').textContent = group?.group_name || '请选择配置组';
    if (!group) {
      detail.innerHTML = '<div class="mc-empty-state"><strong>请选择配置组</strong><span>配置组切换后，这里会立即显示选项、默认、条件和审批信息。</span></div>';
      return;
    }
    qa('[data-adaptation-tab]').forEach(button => button.classList.toggle('is-active', button.dataset.adaptationTab === state.tab));
    const options = workspace.options || [];
    if (state.tab === 'options') {
      detail.innerHTML = options.length
        ? `<div class="mc-adaptation-option-list">${options.map(option => `<article>
          <div>
            <strong>${escapeHtml(`${option.material_code} ${option.name}`)}</strong>
            <span>${escapeHtml(`${option.brand || ''} ${option.model || ''}`.trim() || '未设置品牌 / 型号')}</span>
            ${(option.match_reasons || []).map(reason => `<small class="mc-option-reason">${escapeHtml(reason)}</small>`).join('')}
            ${option.category_code === 'chip' ? `<div class="mc-option-chip-summary">
              <span>${integer(option.selected_chip_variant_count)} 个产品可用规格${option.default_chip_variant ? ` · 默认 ${escapeHtml(option.default_chip_variant)}` : ' · 未设默认规格'}</span>
              <button class="mc-button" type="button" data-chip-option-edit="${integer(option.id)}">设置色温 / 显指 / SDCM</button>
            </div>` : ''}
          </div>
          <div>
            <em class="mc-match mc-match--${escapeHtml(option.match_level)}">${optionMatchLabel(option)}</em>
            ${integer(option.is_default) ? '<b>默认</b>' : ''}
            ${option.material_status !== 'official' ? '<small class="mc-badge--danger">已停用</small>' : ''}
            ${integer(option.requires_approval) && !integer(option.exception_approved) ? '<small>需审批</small>' : ''}
          </div>
        </article>`).join('')}</div>`
        : `<div class="mc-empty-state mc-empty-state--action">
          <strong>当前配置组尚未添加物料选项</strong>
          <span>建议先填写关键范围，系统再从对应类别的正式物料库筛选候选项。</span>
          <div class="mc-empty-state__actions">
            <button class="mc-button mc-button--soft" type="button" data-open-quick-rules>先填关键范围（快速规则）</button>
            <button class="mc-button mc-button--primary" type="button" data-empty-candidate>直接添加候选</button>
          </div>
        </div>`;
    } else if (state.tab === 'quick_rules') {
      const fields = state.metadata.quick_rule_fields?.[group.business_type] || [];
      const rules = group.quick_rules || {};
      const isPower = group.material_category_code === 'power_supply';
      if (isPower) {
        const powerRule = workspace.power_rule || {};
        const fields = state.metadata.power_rule_fields || [];
        const dimmingModes = powerRule.required_dimming_modes || [];
        detail.innerHTML = `<form class="mc-quick-rule-panel" data-power-rule-form>
          <div class="mc-quick-rule-intro"><div><strong>电源 / 驱动关键范围</strong><span>此处就是该产品的电源筛选规则：保存后，候选电源会立即按功率、电流、电压、空间、调光和认证重新判断。</span></div></div>
          <div class="mc-quick-rule-grid">${fields.map(field => `<label class="mc-field"><span>${escapeHtml(field.label)}${field.unit ? `（${escapeHtml(field.unit)}）` : ''}</span>${field.type === 'select'
            ? `<select name="${escapeHtml(field.key)}">${Object.entries(field.options || {}).map(([value, label]) => `<option value="${escapeHtml(value)}" ${String(powerRule[field.key] ?? (value === 'unknown' ? 'unknown' : '')) === value ? 'selected' : ''}>${escapeHtml(label)}</option>`).join('')}</select>`
            : `<input type="${field.type === 'number' ? 'number' : 'text'}" ${field.type === 'number' ? 'min="0" step="0.01"' : ''} name="${escapeHtml(field.key)}" value="${escapeHtml(powerRule[field.key] ?? '')}" placeholder="${escapeHtml(field.placeholder || '留空表示待确认')}">`}</label>`).join('')}</div>
          <fieldset class="mc-power-dimming"><legend>要求支持的调光方式（可多选）</legend>${['0-10V', 'DALI', 'TRIAC', 'PWM', 'On/Off'].map(mode => `<label><input type="checkbox" name="dimming_mode" value="${mode}" ${dimmingModes.includes(mode) ? 'checked' : ''}>${mode}</label>`).join('')}</fieldset>
          <button class="mc-button mc-button--primary" type="submit">保存电源关键范围</button>
        </form>`;
        return;
      }
      detail.innerHTML = `<form class="mc-quick-rule-panel" data-quick-rule-form>
        <div class="mc-quick-rule-intro">
          <div><strong>填写关键范围，自动筛选候选物料</strong><span>空着表示待确认；资料不完整时系统只会标为“需要审批”，不会假装适配。</span></div>
        </div>
        <label class="mc-field mc-quick-rule-availability">
          <span>这个产品是否允许使用“${escapeHtml(group.group_name)}”</span>
          <select name="availability" ${integer(group.is_required) ? 'disabled' : ''}>
            <option value="allowed" ${(rules.availability || 'allowed') === 'allowed' ? 'selected' : ''}>允许使用</option>
            <option value="forbidden" ${rules.availability === 'forbidden' ? 'selected' : ''}>不允许使用</option>
          </select>
          ${integer(group.is_required) ? '<small>这是必选组；如需禁止，请先把配置组改为可选。</small>' : ''}
        </label>
        ${fields.length ? `<div class="mc-quick-rule-grid">${fields.map(field => `<label class="mc-field">
          <span>${escapeHtml(field.label)}${field.unit ? `（${escapeHtml(field.unit)}）` : ''}</span>
          ${field.type === 'select'
            ? `<select name="${escapeHtml(field.key)}"><option value="">待确认</option>${Object.entries(field.options || {}).map(([value, label]) => `<option value="${escapeHtml(value)}" ${rules[field.key] === value ? 'selected' : ''}>${escapeHtml(label)}</option>`).join('')}</select>`
            : `<input type="${field.type === 'number' ? 'number' : 'text'}" ${field.type === 'number' ? 'min="0" step="0.01"' : ''} name="${escapeHtml(field.key)}" value="${escapeHtml(rules[field.key] ?? '')}" placeholder="${escapeHtml(field.placeholder || '留空表示待确认')}">`}
        </label>`).join('')}</div>` : '<div class="mc-empty-state mc-empty-state--compact"><strong>此组只需设置是否允许</strong><span>更细的组合限制可继续使用“适用条件”和冲突设置。</span></div>'}
        <button class="mc-button mc-button--primary" type="submit">保存关键范围</button>
      </form>`;
    } else if (state.tab === 'default') {
      const inputType = group.selection_mode === 'single' ? 'radio' : 'checkbox';
      detail.innerHTML = `<form class="mc-default-panel" data-default-form>
        <div class="mc-adaptation-fact">
          <span>${group.selection_mode === 'single' ? '单选默认项' : '多选默认勾选项'}</span>
          <strong>${escapeHtml(group.default_material || '未设置')}</strong>
          <p>${group.selection_mode === 'single' ? '单选配置组只能设置一个默认选项。' : '多选配置组可设置最少、最多选择数量及多个默认勾选项。'} 切换默认项会记录操作日志。</p>
        </div>
        <div class="mc-default-options">${options.length ? options.map(option => `<label>
          <input type="${inputType}" name="default_option" value="${integer(option.id)}" ${integer(option.is_default) ? 'checked' : ''}>
          <span><strong>${escapeHtml(option.material_code)}</strong>${escapeHtml(`${option.brand || ''} ${option.model || option.name || ''}`.trim())}</span>
        </label>`).join('') : '<p>请先添加物料选项。</p>'}</div>
        ${group.selection_mode === 'multi' ? `<div class="mc-form-grid mc-default-limits">
          <label class="mc-field"><span>最少选择数量</span><input type="number" name="min_select" min="0" value="${integer(group.min_select)}"></label>
          <label class="mc-field"><span>最多选择数量</span><input type="number" name="max_select" min="1" value="${integer(group.max_select)}"></label>
        </div>` : ''}
        <button class="mc-button mc-button--primary" type="submit" ${options.length ? '' : 'disabled'}>保存默认设置</button>
      </form>`;
    } else if (state.tab === 'alternative') {
      const alternatives = options.filter(option => option.option_type === 'alternative');
      detail.innerHTML = alternatives.length
        ? `<div class="mc-adaptation-option-list">${alternatives.map(option => `<article><div><strong>${escapeHtml(`${option.material_code} ${option.name}`)}</strong><span>${escapeHtml(option.model || '')}</span></div><div><em>替代物料</em></div></article>`).join('')}</div>`
        : '<div class="mc-empty-state"><strong>暂无替代关系</strong><span>替代项需要在物料选项中标记，并在审批前检查循环关系。</span></div>';
    } else if (state.tab === 'conditions') {
      const conditions = workspace.conditions || [];
      detail.innerHTML = `<div class="mc-panel-action"><p>通过字段、运算符和值建立 AND / OR 适用条件，不向普通用户暴露代码表达式。</p><button class="mc-button mc-button--primary" type="button" data-condition-open>${conditions.length ? '编辑适用条件' : '添加适用条件'}</button></div>
        ${conditions.length ? `<div class="mc-condition-list">${conditions.map((condition, index) => `<article>
          <strong>${index ? escapeHtml(condition.boolean_connector) + ' ' : ''}${escapeHtml(condition.material_code)} · ${escapeHtml(condition.field_label)}</strong>
          <span>${escapeHtml(condition.operator_label)} ${escapeHtml(formatExpected(condition.expected))}</span>
          <p>${escapeHtml(condition.failure_message)}</p>
        </article>`).join('')}</div>` : '<div class="mc-empty-state mc-empty-state--compact"><strong>暂无适用条件</strong><span>添加后会参与适配检查并返回明确原因。</span></div>'}`;
    } else if (state.tab === 'impact') {
      detail.innerHTML = options.length
        ? `<form class="mc-impact-list" data-impact-form>${options.map(option => `<label>
          <span><strong>${escapeHtml(option.material_code)}</strong>${escapeHtml(option.name)}</span>
          <span class="mc-field"><small>价格影响</small><input type="number" step="0.0001" name="price_${integer(option.id)}" value="${escapeHtml(option.price_impact || 0)}"></span>
          <span class="mc-field"><small>交期影响（天）</small><input type="number" name="lead_${integer(option.id)}" value="${integer(option.lead_time_impact_days)}"></span>
        </label>`).join('')}<button class="mc-button mc-button--primary" type="submit">保存价格 / 交期</button></form>`
        : '<div class="mc-empty-state"><strong>暂无价格或交期影响</strong><span>请先添加物料选项。</span></div>';
    } else if (state.tab === 'approval') {
      const completion = workspace.completion || { issues: [], percent: 0, ready: false };
      const exceptions = integer(completion.exception_count);
      detail.innerHTML = `<div class="mc-approval-readiness">
        <div class="mc-approval-score"><span>配置完成度</span><strong>${integer(completion.percent)}%</strong><small>${integer(completion.checks_passed)} / ${integer(completion.checks_total)} 项检查通过</small></div>
        ${completion.issues.length ? `<div class="mc-completion-issues"><strong>提交前还需处理</strong>${completion.issues.map(issue => `<p>${escapeHtml(issue)}</p>`).join('')}</div>` : '<div class="mc-completion-ready"><strong>审批检查已通过</strong><p>通过后配置状态将变为“已启用”，商务中心才可读取。</p></div>'}
        ${exceptions ? `<label class="mc-exception-approval"><input type="checkbox" data-approve-exceptions><span>本次审批同时批准 ${exceptions} 个适配例外</span></label>` : ''}
        <button class="mc-button mc-button--primary" type="button" data-approve-product>提交审批</button>
      </div>`;
    }
  };

  const render = () => {
    page.dataset.stage = !state.workspace ? 'products' : (selectedGroup() ? 'options' : 'groups');
    renderProducts();
    renderSummary();
    renderConfigurationOverview();
    renderGroups();
    renderOptionPanel();
    updateUrl();
  };

  const loadWorkspace = async (productId, groupId = 0) => {
    q('[data-option-detail]').innerHTML = '<div class="mc-empty-state"><strong>正在加载配置</strong><span>产品切换不会刷新整个页面。</span></div>';
    state.workspace = await get('workspace', { product_id: productId, group_id: groupId });
    const productIndex = state.products.findIndex(product => integer(product.id) === integer(productId));
    if (productIndex >= 0) state.products[productIndex] = { ...state.products[productIndex], ...state.workspace.product };
    const catalogIndex = state.catalogProducts.findIndex(product => integer(product.id) === integer(productId));
    if (catalogIndex >= 0) state.catalogProducts[catalogIndex] = { ...state.catalogProducts[catalogIndex], ...state.workspace.product };
    render();
  };

  const updateChipOptionCount = () => {
    const selected = qa('[name="chip_variant"]:checked', q('[data-chip-variant-form]'));
    q('[data-chip-option-count]').textContent = `已选择 ${selected.length} 个规格`;
    const selectedIds = new Set(selected.map(input => input.value));
    qa('[name="chip_default_variant"]', q('[data-chip-variant-form]')).forEach(input => {
      input.disabled = !selectedIds.has(input.value);
      if (input.disabled) input.checked = false;
    });
  };

  const openChipOption = optionId => {
    const option = (state.workspace?.options || []).find(row => integer(row.id) === integer(optionId));
    if (!option) return;
    const form = q('[data-chip-variant-form]');
    form.elements.option_id.value = option.id;
    q('[data-chip-option-material]').innerHTML = `<strong>${escapeHtml(option.material_code)} ${escapeHtml(option.name)}</strong><span>芯片能力 ${option.chip_variants?.filter(variant => variant.status === 'active').length || 0} 个；仅勾选当前产品真正允许销售的规格。</span>`;
    q('[data-chip-option-variants]').innerHTML = (option.chip_variants || []).length
      ? option.chip_variants.map(variant => `<label class="${variant.status !== 'active' ? 'is-disabled' : ''} ${integer(variant.needs_confirmation) ? 'needs-confirmation' : ''}">
        <input type="checkbox" name="chip_variant" value="${integer(variant.id)}" ${integer(variant.is_selected) ? 'checked' : ''} ${variant.status !== 'active' ? 'disabled' : ''}>
        <span><strong>${escapeHtml(variant.label)}</strong><small>${escapeHtml(variant.variant_code)} · ${escapeHtml(variant.template_name || (variant.source_type === 'legacy' ? '原始资料' : '手工规格'))}${integer(variant.needs_confirmation) ? ' · 待物料中心确认' : ''}</small></span>
        <span><input type="radio" name="chip_default_variant" value="${integer(variant.id)}" ${integer(variant.is_option_default) ? 'checked' : ''} ${!integer(variant.is_selected) || variant.status !== 'active' ? 'disabled' : ''}><b>产品默认</b></span>
      </label>`).join('')
      : '<div class="mc-empty-state mc-empty-state--compact"><strong>这个芯片还没有具体规格</strong><span>请先到物料中心 → 芯片 → 规格组合，套用模板或手工添加。</span></div>';
    updateChipOptionCount();
    state.dirty = false;
    openModal('chip-variant-modal');
  };

  const refreshWorkspace = () => loadWorkspace(selectedProductId(), integer(selectedGroup()?.id));

  const formatExpected = value => Array.isArray(value) ? value.join(' ～ ') : String(value ?? '');

  const updateTemplateSelection = () => {
    const count = qa('[name="template_group"]:checked', q('[data-template-form]')).length;
    q('[data-template-selection]').textContent = `已选择 ${count} 组`;
    q('[data-template-form] button[type="submit"]').disabled = !count;
  };

  const populateTemplate = (productIds = [selectedProductId()]) => {
    state.templateTargetIds = [...new Set(productIds.map(integer).filter(Boolean))];
    const targetProducts = state.catalogProducts.filter(product => state.templateTargetIds.includes(integer(product.id)));
    q('[data-template-target]').innerHTML = state.templateTargetIds.length > 1
      ? `<strong>批量生成到 ${state.templateTargetIds.length} 个产品</strong><span>每个产品只补齐所选配置组，不重复插入。</span>`
      : `<strong>当前产品：${escapeHtml(targetProducts[0]?.product_code || state.workspace?.product?.product_code || '未编号')}</strong><span>重新生成只会补齐或校正所选配置组。</span>`;
    q('[data-template-preview]').innerHTML = (state.metadata.template || []).map((group, index) => `<label>
      <input type="checkbox" name="template_group" value="${escapeHtml(group.key)}" checked>
      <b>${index + 1}</b><span><strong>${escapeHtml(group.name)}</strong><small>${group.is_required ? '必选' : '可选'} · ${group.selection_mode === 'multi' ? '多选' : '单选'}</small></span>
    </label>`).join('');
    updateTemplateSelection();
    state.dirty = false;
  };

  const visibleBatchProducts = () => {
    const query = state.batchQuery.trim().toLowerCase();
    return state.catalogProducts.filter(product => {
      if (integer(product.id) === selectedProductId()) return false;
      if (!query) return true;
      return [product.product_code, product.product_name, product.series_name]
        .some(value => String(value || '').toLowerCase().includes(query));
    });
  };

  const invalidateBatchPreview = () => {
    state.batchPreview = null;
    q('[data-batch-apply]').disabled = true;
    q('[data-batch-preview]').innerHTML = '<strong>3. 先预览，再执行</strong><span>目标或套用方式已变化，请重新点击“预览影响”。</span>';
  };

  const updateBatchSelection = (invalidate = true) => {
    const count = state.batchSelected.size;
    q('[data-batch-selection]').textContent = `已选择 ${count} 个`;
    q('[data-batch-footer-selection]').textContent = `已选择 ${count} 个产品`;
    if (invalidate) invalidateBatchPreview();
  };

  const renderBatchProducts = () => {
    const products = visibleBatchProducts();
    q('[data-batch-product-list]').innerHTML = products.length ? products.map(product => `<label class="mc-batch-product">
      <input type="checkbox" name="batch_product" value="${integer(product.id)}" ${state.batchSelected.has(integer(product.id)) ? 'checked' : ''}>
      <span><strong>${escapeHtml(product.product_code || '未编号')}</strong><small>${escapeHtml(product.product_name || '未命名')}</small></span>
      <em>${escapeHtml(product.series_name || '未设置系列')}</em>
      <b class="mc-badge mc-badge--${product.approval_label === '已启用' ? 'success' : 'warning'}">${escapeHtml(product.approval_label)}</b>
    </label>`).join('') : '<div class="mc-empty-state mc-empty-state--compact"><strong>没有符合条件的目标产品</strong><span>请调整搜索词，或先同步产品。</span></div>';
  };

  const openBatch = (preselected = []) => {
    const source = state.workspace?.product;
    if (!source || !state.workspace?.groups?.length) {
      notify('暂不能批量套用', '请先选择并设置好一个来源产品。');
      return;
    }
    state.batchSelected = new Set(preselected.map(integer).filter(id => id && id !== selectedProductId()).slice(0, 1000));
    state.batchPreview = null;
    state.batchQuery = '';
    const form = q('[data-batch-form]');
    form.reset();
    q('[data-batch-search]').value = '';
    q('[data-batch-source]').innerHTML = `<span>当前来源产品</span><strong>${escapeHtml(source.product_code || '未编号')} · ${escapeHtml(source.product_name || '未命名')}</strong><small>${state.workspace.groups.length} 个配置组；目标产品执行后统一进入待重审。</small>`;
    renderBatchProducts();
    updateBatchSelection(false);
    q('[data-batch-preview]').innerHTML = '<strong>3. 先预览，再执行</strong><span>选择目标产品后点击“预览影响”，系统会先计算新增、覆盖和跳过数量。</span>';
    q('[data-batch-apply]').disabled = true;
    state.dirty = false;
    openModal('batch-modal');
  };

  const batchRequestValues = () => {
    const form = q('[data-batch-form]');
    return {
      source_product_id: selectedProductId(),
      target_product_ids: [...state.batchSelected],
      mode: new FormData(form).get('mode') || 'fill_missing',
      include_power_rule: form.elements.include_power_rule.checked ? 1 : 0,
    };
  };

  const previewBatch = async () => {
    if (!state.batchSelected.size) {
      notify('尚未选择产品', '请先选择至少一个目标产品。');
      return;
    }
    const button = q('[data-batch-preview-button]');
    button.disabled = true;
    try {
      state.batchPreview = await post('preview_batch', batchRequestValues());
      const preview = state.batchPreview;
      q('[data-batch-preview]').innerHTML = `<strong>预览完成：${integer(preview.targets)} 个目标产品</strong>
        <div class="mc-batch-preview-metrics">
          <span><b>${integer(preview.groups.created)}</b> 新增配置组</span>
          <span><b>${integer(preview.groups.overwritten)}</b> 覆盖同名组</span>
          <span><b>${integer(preview.groups.skipped)}</b> 保留并跳过</span>
          <span><b>${integer(preview.approved_targets)}</b> 个已有审批</span>
          <span><b>${integer(preview.power_rule.created) + integer(preview.power_rule.overwritten)}</b> 条电源范围</span>
        </div>
        <small>${preview.mode === 'replace_matching' ? '覆盖模式会让目标产品重新进入审批；未命中的其他配置组保持不变。' : '只补空白模式不会改目标产品已有的同名配置组。'}</small>`;
      q('[data-batch-apply]').disabled = false;
    } catch (error) {
      notify('预览失败', error.message);
      q('[data-batch-apply]').disabled = true;
    } finally {
      button.disabled = false;
    }
  };

  const invalidateReusePreview = () => {
    state.reusePreview = null;
    q('[data-reuse-apply]').disabled = true;
    q('[data-reuse-preview]').innerHTML = '<strong>4. 先预览，再执行</strong><span>来源、配置组或套用方式已变化，请重新预览。</span>';
  };

  const renderReuseGroups = () => {
    const groups = state.reuseSourceWorkspace?.groups || [];
    q('[data-reuse-group-list]').innerHTML = groups.length
      ? groups.map(group => `<label>
        <input type="checkbox" name="reuse_group" value="${integer(group.id)}" checked>
        <span><strong>${escapeHtml(group.group_name)}</strong><small>${integer(group.option_count)} 个选项 · ${integer(group.is_required) ? '必选' : '可选'} · ${group.selection_mode === 'multi' ? '多选' : '单选'}</small></span>
      </label>`).join('')
      : '<div class="mc-empty-state mc-empty-state--compact"><strong>来源产品没有配置组</strong><span>请换一个来源产品。</span></div>';
    invalidateReusePreview();
  };

  const loadReuseSource = async sourceProductId => {
    q('[data-reuse-group-list]').innerHTML = '<div class="mc-empty-state mc-empty-state--compact"><strong>正在读取来源配置</strong></div>';
    state.reuseSourceWorkspace = await get('workspace', { product_id: sourceProductId });
    renderReuseGroups();
  };

  const openReuse = async () => {
    const target = state.workspace?.product;
    if (!target) {
      notify('请先选择产品', '先选择需要接收配置的当前产品。');
      return;
    }
    const sources = state.catalogProducts.filter(product => integer(product.id) !== selectedProductId() && integer(product.group_count) > 0);
    if (!sources.length) {
      notify('没有可用来源', '请先为至少一个其他产品建立配置组。');
      return;
    }
    const sameSeries = sources.find(product => String(product.series_name || '') === String(target.series_name || ''));
    const source = sameSeries || sources[0];
    const form = q('[data-reuse-form]');
    form.reset();
    q('[data-reuse-target]').innerHTML = `<span>接收配置的当前产品</span><strong>${escapeHtml(target.product_code || '未编号')} · ${escapeHtml(target.product_name || '未命名')}</strong>`;
    q('[data-reuse-source]').innerHTML = sources.map(product => `<option value="${integer(product.id)}" ${integer(product.id) === integer(source.id) ? 'selected' : ''}>${escapeHtml(product.product_code || '未编号')} · ${escapeHtml(product.product_name || '未命名')}（${integer(product.group_count)} 组）</option>`).join('');
    state.reuseSourceWorkspace = null;
    state.reusePreview = null;
    state.dirty = false;
    openModal('reuse-modal');
    try {
      await loadReuseSource(integer(source.id));
      state.dirty = false;
    } catch (error) {
      notify('来源配置读取失败', error.message);
    }
  };

  const reuseRequestValues = () => {
    const form = q('[data-reuse-form]');
    return {
      source_product_id: integer(q('[data-reuse-source]').value),
      target_product_ids: [selectedProductId()],
      source_group_ids: qa('[name="reuse_group"]:checked', form).map(input => integer(input.value)),
      mode: new FormData(form).get('mode') || 'fill_missing',
      include_power_rule: form.elements.include_power_rule.checked ? 1 : 0,
    };
  };

  const previewReuse = async () => {
    const values = reuseRequestValues();
    if (!values.source_group_ids.length && !values.include_power_rule) {
      notify('尚未选择配置', '请至少选择一个配置组，或勾选电源范围。');
      return;
    }
    const button = q('[data-reuse-preview-button]');
    button.disabled = true;
    try {
      state.reusePreview = await post('preview_batch', values);
      const preview = state.reusePreview;
      q('[data-reuse-preview]').innerHTML = `<strong>预览完成：将套用 ${integer(preview.groups.source)} 个配置组</strong>
        <div class="mc-batch-preview-metrics">
          <span><b>${integer(preview.groups.created)}</b> 新增配置组</span>
          <span><b>${integer(preview.groups.overwritten)}</b> 覆盖同名组</span>
          <span><b>${integer(preview.groups.skipped)}</b> 保留并跳过</span>
          <span><b>${integer(preview.power_rule.created) + integer(preview.power_rule.overwritten)}</b> 条电源范围</span>
        </div>`;
      q('[data-reuse-apply]').disabled = false;
    } catch (error) {
      notify('预览失败', error.message);
      q('[data-reuse-apply]').disabled = true;
    } finally {
      button.disabled = false;
    }
  };

  const populateGroupFormTypes = () => {
    q('[data-business-type]').innerHTML = '<option value="">请选择配置用途</option>'
      + Object.entries(state.metadata.business_types || {}).map(([key, row]) => `<option value="${escapeHtml(key)}">${escapeHtml(row.label)}</option>`).join('');
  };

  const syncGroupFormType = (suggestName = false) => {
    const form = q('[data-group-form]');
    const type = form.elements.business_type.value;
    const definition = state.metadata.business_types?.[type] || {};
    const previousSuggestion = form.dataset.suggestedGroupName || '';
    const currentName = form.elements.group_name.value.trim();
    const categoryInput = q('[data-material-category]', form);
    const categoryLabel = q('[data-material-category-label]', form);
    const customCategory = q('[data-custom-material-category]', form);
    const categoryHelp = q('[data-material-category-help]', form);
    const isCustom = type === 'custom';
    customCategory.hidden = !isCustom;
    categoryLabel.hidden = isCustom;
    if (isCustom) {
      customCategory.value = categoryInput.value || customCategory.value || '';
      categoryInput.value = customCategory.value;
      categoryHelp.textContent = '自定义用途需要选择候选物料来自哪个正式物料库。';
    } else {
      categoryInput.value = definition.category || '';
      categoryLabel.textContent = definition.category
        ? (materialCategoryLabels[definition.category] || definition.category)
        : '选择配置用途后自动确定';
      categoryHelp.textContent = '无需重复选择，系统会自动关联正式物料库。';
    }
    const nextSuggestion = definition.default_name || definition.label || '';
    if (suggestName && (!currentName || currentName === previousSuggestion)) {
      form.elements.group_name.value = nextSuggestion;
    }
    form.dataset.suggestedGroupName = suggestName ? nextSuggestion : '';
  };

  const openGroupForm = group => {
    const form = q('[data-group-form]');
    form.reset();
    form.dataset.suggestedGroupName = '';
    form.elements.id.value = group?.id || '';
    q('[data-group-form-title]').textContent = group ? '编辑配置组' : '新建配置组';
    q('[data-group-delete]').hidden = !group;
    if (group) {
      ['business_type', 'material_category_code', 'group_name', 'is_required', 'selection_mode', 'min_select', 'max_select', 'sort_order', 'status'].forEach(key => {
        if (form.elements[key]) form.elements[key].value = group[key] ?? '';
      });
    } else {
      form.elements.business_type.value = '';
      form.elements.is_required.value = '0';
      form.elements.selection_mode.value = 'single';
      form.elements.min_select.value = '0';
      form.elements.max_select.value = '1';
      form.elements.sort_order.value = String((state.workspace?.groups?.length || 0) * 10 + 10);
      form.elements.status.value = 'draft';
    }
    syncGroupFormType(false);
    state.dirty = false;
    openModal('group-modal');
  };

  const loadCandidates = async () => {
    const form = q('[data-candidate-form]');
    const params = { group_id: selectedGroup()?.id };
    new FormData(form).forEach((value, key) => {
      if (String(value).trim()) params[key] = String(value).trim();
    });
    q('[data-candidate-list]').innerHTML = '<div class="mc-empty-state"><strong>正在检查候选物料</strong><span>正在计算匹配程度和冲突原因。</span></div>';
    state.candidates = await get('candidates', params);
    renderCandidates();
  };

  const renderCandidates = () => {
    const list = q('[data-candidate-list]');
    q('[data-candidate-summary]').textContent = `共 ${state.candidates.length} 个候选物料；不适配和已停用物料不能勾选。`;
    list.innerHTML = state.candidates.length ? state.candidates.map(material => {
      const blocked = material.match_level === 'incompatible' || material.status !== 'official' || material.already_added;
      return `<label class="mc-candidate-card mc-candidate-card--${escapeHtml(material.match_level)} ${blocked ? 'is-disabled' : ''}">
        <input type="checkbox" name="material_choice" value="${integer(material.id)}" ${blocked ? 'disabled' : ''}>
        <span class="mc-candidate-card__main">
          <strong>${escapeHtml(material.material_code)} · ${escapeHtml(`${material.brand || ''} ${material.model || material.name || ''}`.trim())}</strong>
          <small>${escapeHtml(material.key_specs || '暂无关键规格')}</small>
          <em>${escapeHtml(material.suppliers || '未关联供应商')}</em>
        </span>
        <span class="mc-candidate-card__match">
          <b>${escapeHtml(material.match_label)}</b>
          ${material.status !== 'official' ? '<i>已停用</i>' : ''}
          ${material.requires_approval ? '<i>需要审批</i>' : ''}
          ${material.already_added ? '<i>已添加</i>' : ''}
        </span>
        <span class="mc-candidate-card__reasons">${(material.conflict_reasons || []).length ? material.conflict_reasons.map(reason => `<small>${escapeHtml(reason)}</small>`).join('') : '<small class="is-pass">未发现冲突</small>'}</span>
      </label>`;
    }).join('') : '<div class="mc-empty-state"><strong>没有符合条件的正式物料</strong><span>请调整筛选，或先在对应分类将物料转正式。</span></div>';
    updateCandidateSelection();
  };

  const updateCandidateSelection = () => {
    q('[data-candidate-selection]').textContent = `已选择 ${qa('[name="material_choice"]:checked', q('[data-candidate-form]')).length} 项`;
  };

  const conditionRow = (condition = {}, index = 0) => {
    const options = state.workspace?.options || [];
    const fields = state.metadata.condition_fields || {};
    const operators = state.metadata.condition_operators || {};
    return `<div class="mc-condition-editor-row">
      <select name="boolean_connector" ${index === 0 ? 'disabled' : ''}><option value="AND" ${condition.boolean_connector !== 'OR' ? 'selected' : ''}>AND</option><option value="OR" ${condition.boolean_connector === 'OR' ? 'selected' : ''}>OR</option></select>
      <select name="option_id" required>${options.map(option => `<option value="${integer(option.id)}" ${integer(condition.option_id) === integer(option.id) ? 'selected' : ''}>${escapeHtml(option.material_code)} ${escapeHtml(option.name)}</option>`).join('')}</select>
      <select name="field_code" required><option value="">选择字段</option>${Object.entries(fields).map(([key, label]) => `<option value="${escapeHtml(key)}" ${condition.field_code === key ? 'selected' : ''}>${escapeHtml(label)}</option>`).join('')}</select>
      <select name="operator" required>${Object.entries(operators).map(([key, label]) => `<option value="${escapeHtml(key)}" ${condition.operator === key ? 'selected' : ''}>${escapeHtml(label)}</option>`).join('')}</select>
      <input name="expected" required value="${escapeHtml(Array.isArray(condition.expected) ? condition.expected.join(',') : condition.expected ?? '')}" placeholder="介于/属于用逗号分隔">
      <input name="failure_message" maxlength="500" value="${escapeHtml(condition.failure_message || '')}" placeholder="例如：输出电流高于芯片允许值">
      <button class="mc-icon-button" type="button" data-condition-remove>×</button>
    </div>`;
  };

  const openConditionEditor = () => {
    if (!state.workspace?.options?.length) {
      notify('无法添加条件', '请先从物料库添加选项。');
      return;
    }
    const rows = state.workspace.conditions || [];
    q('[data-condition-rows]').innerHTML = rows.length
      ? rows.map((row, index) => conditionRow(row, index)).join('')
      : conditionRow({}, 0);
    state.dirty = false;
    openModal('condition-modal');
  };

  page.addEventListener('click', async event => {
    const productButton = event.target.closest('[data-product-id]');
    if (productButton) {
      try {
        state.tab = 'options';
        await loadWorkspace(integer(productButton.dataset.productId));
      } catch (error) {
        notify('产品加载失败', error.message);
      }
      return;
    }
    const groupButton = event.target.closest('[data-select-group]');
    if (groupButton) {
      try {
        await loadWorkspace(selectedProductId(), integer(groupButton.dataset.selectGroup));
      } catch (error) {
        notify('配置组加载失败', error.message);
      }
      return;
    }
    const tab = event.target.closest('[data-adaptation-tab]');
    if (tab) {
      state.tab = tab.dataset.adaptationTab;
      renderOptionPanel();
      return;
    }
    if (event.target.closest('[data-configuration-overview-open]')) {
      openConfigurationOverview();
      return;
    }
    const chipOptionEdit = event.target.closest('[data-chip-option-edit]');
    if (chipOptionEdit) {
      openChipOption(integer(chipOptionEdit.dataset.chipOptionEdit));
      return;
    }
    if (event.target.closest('[data-chip-option-select-all]')) {
      qa('[name="chip_variant"]:not(:disabled)', q('[data-chip-variant-form]')).forEach(input => { input.checked = true; });
      updateChipOptionCount();
      state.dirty = true;
      return;
    }
    if (event.target.closest('[data-chip-option-clear]')) {
      qa('[name="chip_variant"],[name="chip_default_variant"]', q('[data-chip-variant-form]')).forEach(input => { input.checked = false; });
      updateChipOptionCount();
      state.dirty = true;
      return;
    }
    if (event.target.closest('[data-open-quick-rules]')) {
      state.tab = 'quick_rules';
      renderOptionPanel();
      return;
    }
    if (event.target.closest('[data-template-open], [data-empty-template]')) {
      populateTemplate([selectedProductId()]);
      openModal('template-modal');
      return;
    }
    if (event.target.closest('[data-template-select-all]')) {
      qa('[name="template_group"]', q('[data-template-form]')).forEach(input => { input.checked = true; });
      updateTemplateSelection();
      return;
    }
    if (event.target.closest('[data-template-select-core]')) {
      const requiredKeys = new Set((state.metadata.template || []).filter(group => group.is_required).map(group => group.key));
      qa('[name="template_group"]', q('[data-template-form]')).forEach(input => { input.checked = requiredKeys.has(input.value); });
      updateTemplateSelection();
      return;
    }
    if (event.target.closest('[data-template-clear]')) {
      qa('[name="template_group"]', q('[data-template-form]')).forEach(input => { input.checked = false; });
      updateTemplateSelection();
      return;
    }
    if (event.target.closest('[data-reuse-open]')) {
      await openReuse();
      return;
    }
    if (event.target.closest('[data-reuse-select-all]')) {
      qa('[name="reuse_group"]', q('[data-reuse-form]')).forEach(input => { input.checked = true; });
      invalidateReusePreview();
      return;
    }
    if (event.target.closest('[data-reuse-preview-button]')) {
      await previewReuse();
      return;
    }
    if (event.target.closest('[data-batch-open]')) {
      openBatch();
      return;
    }
    if (event.target.closest('[data-product-select-visible]')) {
      const next = new Set(state.productSelected);
      visibleProducts().forEach(product => {
        if (next.size < 1000) next.add(integer(product.id));
      });
      state.productSelected = next;
      renderProducts();
      return;
    }
    if (event.target.closest('[data-product-selection-clear]')) {
      state.productSelected = new Set();
      renderProducts();
      return;
    }
    if (event.target.closest('[data-selected-template]')) {
      populateTemplate([...state.productSelected]);
      openModal('template-modal');
      return;
    }
    if (event.target.closest('[data-selected-batch]')) {
      openBatch([...state.productSelected]);
      return;
    }
    if (event.target.closest('[data-batch-same-series]')) {
      const series = String(state.workspace?.product?.series_name || '').trim();
      if (!series) {
        notify('当前产品没有系列', '请使用搜索或手工勾选目标产品。');
        return;
      }
      state.batchSelected = new Set(state.catalogProducts
        .filter(product => integer(product.id) !== selectedProductId() && String(product.series_name || '').trim() === series)
        .slice(0, 1000)
        .map(product => integer(product.id)));
      renderBatchProducts();
      updateBatchSelection();
      return;
    }
    if (event.target.closest('[data-batch-select-visible]')) {
      const next = new Set(state.batchSelected);
      visibleBatchProducts().forEach(product => {
        if (next.size < 1000) next.add(integer(product.id));
      });
      state.batchSelected = next;
      renderBatchProducts();
      updateBatchSelection();
      if (visibleBatchProducts().length > 1000) notify('已选择前 1000 个产品', '一次最多处理 1000 个，请分批执行剩余产品。');
      return;
    }
    if (event.target.closest('[data-batch-clear]')) {
      state.batchSelected = new Set();
      renderBatchProducts();
      updateBatchSelection();
      return;
    }
    if (event.target.closest('[data-batch-preview-button]')) {
      await previewBatch();
      return;
    }
    if (event.target.closest('[data-group-create]')) {
      openGroupForm(null);
      return;
    }
    const edit = event.target.closest('[data-edit-group]');
    if (edit) {
      const group = state.workspace.groups.find(row => integer(row.id) === integer(edit.dataset.editGroup));
      openGroupForm(group);
      return;
    }
    if (event.target.closest('[data-candidate-open], [data-empty-candidate]')) {
      q('[data-candidate-form]').reset();
      openModal('candidate-modal');
      try {
        await loadCandidates();
      } catch (error) {
        notify('候选物料加载失败', error.message);
      }
      return;
    }
    if (event.target.closest('[data-condition-open]')) {
      openConditionEditor();
      return;
    }
    if (event.target.closest('[data-condition-add]')) {
      const container = q('[data-condition-rows]');
      container.insertAdjacentHTML('beforeend', conditionRow({}, container.children.length));
      state.dirty = true;
      return;
    }
    const removeCondition = event.target.closest('[data-condition-remove]');
    if (removeCondition) {
      removeCondition.closest('.mc-condition-editor-row').remove();
      qa('.mc-condition-editor-row', q('[data-condition-rows]')).forEach((row, index) => {
        row.querySelector('[name="boolean_connector"]').disabled = index === 0;
      });
      state.dirty = true;
      return;
    }
    if (event.target.closest('[data-candidate-filter]')) {
      try {
        await loadCandidates();
      } catch (error) {
        notify('筛选失败', error.message);
      }
      return;
    }
    if (event.target.closest('[data-sync-products]')) {
      const button = event.target.closest('button');
      button.disabled = true;
      try {
        const result = await post('sync');
        state.products = await get('products');
        state.catalogProducts = state.products;
        renderProducts();
        notify('产品同步完成', `读取 ${result.seen} 条，新增 ${result.created} 条，更新 ${result.changed} 条。`);
      } catch (error) {
        notify('同步失败', error.message);
      } finally {
        button.disabled = false;
      }
      return;
    }
    if (event.target.closest('[data-approve-product]')) {
      const button = event.target.closest('button');
      button.disabled = true;
      try {
        const approveExceptions = q('[data-approve-exceptions]')?.checked ? 1 : 0;
        await post('approve', { product_id: selectedProductId(), approve_exceptions: approveExceptions });
        await refreshWorkspace();
        notify('审批通过', '当前适配版本已启用，商务中心可以读取。');
      } catch (error) {
        notify('暂不能提交审批', error.message);
        button.disabled = false;
      }
      return;
    }
    if (event.target.closest('[data-modal-close]')) {
      if (state.dirty && !window.confirm('当前修改尚未保存，确定离开吗？')) return;
      closeModal(event.target.closest('[data-adaptation-modal]'));
    }
  });

  page.addEventListener('change', async event => {
    if (event.target.matches('[data-product-check]')) {
      const id = integer(event.target.value);
      event.target.checked ? state.productSelected.add(id) : state.productSelected.delete(id);
      renderProducts();
      return;
    }
    if (event.target.matches('[name="chip_variant"]')) {
      updateChipOptionCount();
      state.dirty = true;
      return;
    }
    if (event.target.matches('[data-product-status-filter]')) {
      state.productStatusFilter = event.target.value;
      renderProducts();
      return;
    }
    if (event.target.matches('[name="template_group"]')) updateTemplateSelection();
    if (event.target.matches('[name="material_choice"]')) updateCandidateSelection();
    if (event.target.matches('[name="batch_product"]')) {
      const id = integer(event.target.value);
      if (event.target.checked) {
        if (state.batchSelected.size >= 1000) {
          event.target.checked = false;
          notify('一次最多选择 1000 个产品', '请先执行这一批，再处理剩余产品。');
        } else {
          state.batchSelected.add(id);
        }
      } else {
        state.batchSelected.delete(id);
      }
      updateBatchSelection();
    }
    if (event.target.matches('[data-batch-form] [name="mode"], [data-batch-form] [name="include_power_rule"]')) invalidateBatchPreview();
    if (event.target.matches('[data-reuse-source]')) {
      try {
        await loadReuseSource(integer(event.target.value));
      } catch (error) {
        notify('来源配置读取失败', error.message);
      }
    }
    if (event.target.matches('[data-reuse-form] [name="mode"], [data-reuse-form] [name="include_power_rule"], [name="reuse_group"]')) invalidateReusePreview();
    if (event.target.closest('form')) state.dirty = true;
    if (event.target.matches('[data-business-type]')) {
      syncGroupFormType(true);
    }
    if (event.target.matches('[data-custom-material-category]')) {
      q('[data-material-category]', event.target.form).value = event.target.value;
    }
    if (event.target.matches('[name="selection_mode"]')) {
      const form = event.target.form;
      if (event.target.value === 'single') {
        form.elements.max_select.value = '1';
        form.elements.min_select.value = form.elements.is_required.value === '1' ? '1' : '0';
      }
    }
  });

  q('[data-batch-search]').addEventListener('input', event => {
    state.batchQuery = event.currentTarget.value;
    renderBatchProducts();
  });

  q('[data-product-search]').addEventListener('submit', async event => {
    event.preventDefault();
    try {
      state.products = await get('products', { q: new FormData(event.currentTarget).get('q') });
      renderProducts();
    } catch (error) {
      notify('搜索失败', error.message);
    }
  });

  q('[data-template-form]').addEventListener('submit', async event => {
    event.preventDefault();
    const form = event.currentTarget;
    const modal = form.closest('[data-adaptation-modal]');
    const button = event.submitter || q('button[type="submit"]', form);
    const templateKeys = qa('[name="template_group"]:checked', form).map(input => input.value);
    if (!templateKeys.length || !state.templateTargetIds.length) {
      notify('尚未选择配置组', '请至少勾选一个要生成的配置组。');
      return;
    }
    if (button) button.disabled = true;
    try {
      const result = { succeeded: 0, failed: 0, created: 0, failures: [] };
      if (state.templateTargetIds.length === 1) {
        const part = await post('apply_template', {
          product_id: state.templateTargetIds[0],
          template_keys: templateKeys,
        });
        result.succeeded = 1;
        result.created = integer(part.created);
      } else {
        for (let index = 0; index < state.templateTargetIds.length; index += 100) {
          const part = await post('batch_initialize_groups', {
            product_ids: state.templateTargetIds.slice(index, index + 100),
            template_keys: templateKeys,
          });
          result.succeeded += integer(part.succeeded);
          result.failed += integer(part.failed);
          result.created += integer(part.created);
          result.failures.push(...(part.failures || []));
        }
      }
      closeModal(modal);
      state.tab = 'quick_rules';
      state.products = await get('products');
      state.catalogProducts = state.products;
      if (selectedProductId()) {
        await loadWorkspace(selectedProductId());
        const firstGroupId = integer(state.workspace?.groups?.[0]?.id);
        if (firstGroupId) await loadWorkspace(selectedProductId(), firstGroupId);
      }
      else renderProducts();
      const failureText = result.failed ? `；${result.failed} 个失败` : '';
      notify(state.templateTargetIds.length > 1 ? '批量标准配置已生成' : '标准配置已生成',
        state.templateTargetIds.length > 1
          ? `成功处理 ${result.succeeded} 个产品，新增 ${result.created} 个配置组${failureText}。`
          : `新增 ${result.created} 个配置组，已为你打开第一个组的关键范围。`);
    } catch (error) {
      notify('生成失败', error.message);
      if (button) button.disabled = false;
    }
  });

  q('[data-reuse-form]').addEventListener('submit', async event => {
    event.preventDefault();
    if (!state.reusePreview) {
      notify('请先预览影响', '来源、配置组或套用方式变化后，需要重新预览。');
      return;
    }
    const form = event.currentTarget;
    const button = event.submitter || q('[data-reuse-apply]', form);
    button.disabled = true;
    button.textContent = '正在套用…';
    try {
      const result = await post('batch_apply', reuseRequestValues());
      state.dirty = false;
      closeModal(form.closest('[data-adaptation-modal]'));
      state.products = await get('products');
      state.catalogProducts = state.products;
      await loadWorkspace(selectedProductId());
      notify('现有配置已套用', `新增 ${integer(result.groups_created)} 组，覆盖 ${integer(result.groups_overwritten)} 组，保留 ${integer(result.groups_skipped)} 组。`);
    } catch (error) {
      state.reusePreview = null;
      notify('套用失败', error.message);
    } finally {
      button.disabled = !state.reusePreview;
      button.textContent = '确认套用';
    }
  });

  q('[data-batch-form]').addEventListener('submit', async event => {
    event.preventDefault();
    if (!state.batchPreview) {
      notify('请先预览影响', '目标或套用方式变化后，需要重新预览。');
      return;
    }
    const form = event.currentTarget;
    const button = event.submitter || q('[data-batch-apply]', form);
    button.disabled = true;
    button.textContent = '正在批量套用…';
    let completedBatches = 0;
    let totalBatches = 0;
    try {
      const values = batchRequestValues();
      const chunks = [];
      for (let index = 0; index < values.target_product_ids.length; index += 100) {
        chunks.push(values.target_product_ids.slice(index, index + 100));
      }
      totalBatches = chunks.length;
      const result = {
        targets: values.target_product_ids.length,
        succeeded: 0,
        failed: 0,
        groups_created: 0,
        groups_overwritten: 0,
        groups_skipped: 0,
        options_copied: 0,
        power_rules_copied: 0,
        failures: [],
      };
      for (const [index, targetIds] of chunks.entries()) {
        button.textContent = `正在处理第 ${index + 1} / ${chunks.length} 批…`;
        const part = await post('batch_apply', { ...values, target_product_ids: targetIds });
        ['succeeded', 'failed', 'groups_created', 'groups_overwritten', 'groups_skipped', 'options_copied', 'power_rules_copied']
          .forEach(key => { result[key] += integer(part[key]); });
        result.failures.push(...(part.failures || []));
        completedBatches++;
      }
      state.dirty = false;
      state.products = await get('products');
      state.catalogProducts = state.products;
      await loadWorkspace(selectedProductId(), integer(selectedGroup()?.id));
      const failureText = integer(result.failed) ? `；失败 ${integer(result.failed)} 个，可根据失败原因修正后重试` : '';
      notify('批量套用完成', `成功 ${integer(result.succeeded)} 个产品，复制 ${integer(result.groups_created) + integer(result.groups_overwritten)} 个配置组和 ${integer(result.options_copied)} 个物料选项${failureText}。`);
      if (integer(result.failed) && result.failures?.length) {
        state.batchPreview = null;
        q('[data-batch-preview]').innerHTML = `<strong>以下产品未套用，其他产品已经完成</strong>${result.failures.map(row => `<p>${escapeHtml(row.product_code)}：${escapeHtml(row.reason)}</p>`).join('')}`;
      } else {
        closeModal(form.closest('[data-adaptation-modal]'));
      }
    } catch (error) {
      state.batchPreview = null;
      const progress = completedBatches ? `前 ${completedBatches} / ${totalBatches} 批已经完成；` : '';
      notify('批量套用中断', `${progress}${error.message}`);
    } finally {
      button.disabled = !state.batchPreview;
      button.textContent = '确认批量套用';
    }
  });

  q('[data-group-form]').addEventListener('submit', async event => {
    event.preventDefault();
    const form = event.currentTarget;
    const values = Object.fromEntries(new FormData(form));
    values.product_id = selectedProductId();
    const button = event.submitter;
    button.disabled = true;
    try {
      await post('save_group', values);
      closeModal(form.closest('[data-adaptation-modal]'));
      await loadWorkspace(selectedProductId(), values.id || 0);
      notify('配置组已保存', '类型、选择规则和排序已经写入操作日志。');
    } catch (error) {
      notify('保存失败', error.message);
      button.disabled = false;
    }
  });

  q('[data-group-delete]').addEventListener('click', async () => {
    const groupId = integer(q('[data-group-form]').elements.id.value);
    if (!groupId || !window.confirm('确定删除这个未引用的草稿配置组吗？')) return;
    try {
      await post('delete_group', { group_id: groupId });
      closeModal(q('#group-modal'));
      await loadWorkspace(selectedProductId());
      notify('配置组已删除', '删除前已完成审批历史、报价和订单引用检查。');
    } catch (error) {
      notify('无法删除', error.message);
    }
  });

  q('[data-candidate-form]').addEventListener('submit', async event => {
    event.preventDefault();
    const form = event.currentTarget;
    const modal = form.closest('[data-adaptation-modal]');
    const ids = qa('[name="material_choice"]:checked', form).map(input => integer(input.value));
    if (!ids.length) {
      notify('尚未选择物料', '请勾选至少一个可添加的候选项。');
      return;
    }
    const button = event.submitter || q('button[type="submit"]', form);
    if (button) button.disabled = true;
    try {
      const result = await post('add_options', { group_id: selectedGroup().id, material_ids: ids });
      closeModal(modal);
      await refreshWorkspace();
      notify('物料选项已添加', `成功 ${result.added} 项，跳过 ${result.skipped} 项。`);
    } catch (error) {
      notify('添加失败', error.message);
      if (button) button.disabled = false;
    }
  });

  q('[data-condition-form]').addEventListener('submit', async event => {
    event.preventDefault();
    const form = event.currentTarget;
    const modal = form.closest('[data-adaptation-modal]');
    const conditions = qa('.mc-condition-editor-row', form).map((row, index) => {
      const operator = q('[name="operator"]', row).value;
      const raw = q('[name="expected"]', row).value.trim();
      let expected = raw;
      if (operator === 'in' || operator === 'between') expected = raw.split(',').map(value => value.trim()).filter(Boolean).map(value => Number.isFinite(Number(value)) ? Number(value) : value);
      else if (raw !== '' && Number.isFinite(Number(raw))) expected = Number(raw);
      return {
        boolean_connector: index ? q('[name="boolean_connector"]', row).value : 'AND',
        condition_group_no: 1,
        option_id: integer(q('[name="option_id"]', row).value),
        field_code: q('[name="field_code"]', row).value,
        operator,
        expected,
        failure_message: q('[name="failure_message"]', row).value.trim(),
        severity: 'block',
      };
    });
    const button = event.submitter || q('button[type="submit"]', form);
    if (button) button.disabled = true;
    try {
      const result = await post('save_conditions', { group_id: selectedGroup().id, conditions });
      closeModal(modal);
      await refreshWorkspace();
      state.tab = 'conditions';
      renderOptionPanel();
      notify('适用条件已保存', `共保存 ${result.saved} 条可视化条件。`);
    } catch (error) {
      notify('条件保存失败', error.message);
      if (button) button.disabled = false;
    }
  });

  q('[data-chip-variant-form]').addEventListener('submit', async event => {
    event.preventDefault();
    const form = event.currentTarget;
    const variantIds = qa('[name="chip_variant"]:checked', form).map(input => integer(input.value));
    const defaultVariantId = integer(q('[name="chip_default_variant"]:checked', form)?.value);
    if (!variantIds.length) {
      notify('请至少保留一个规格', '产品芯片选项必须有具体色温、显指和色容差。');
      return;
    }
    if (!defaultVariantId) {
      notify('请选择产品默认规格', '默认规格用于标准报价和默认出货。');
      return;
    }
    const button = event.submitter;
    button.disabled = true;
    try {
      await post('save_option_chip_variants', {
        option_id: integer(form.elements.option_id.value),
        variant_ids: variantIds,
        default_variant_id: defaultVariantId,
      });
      state.dirty = false;
      closeModal(form.closest('[data-adaptation-modal]'));
      await refreshWorkspace();
      notify('芯片规格范围已保存', '商务中心只会读取勾选的具体规格，默认规格已同步。');
    } catch (error) {
      notify('保存失败', error.message);
      button.disabled = false;
    }
  });

  page.addEventListener('submit', async event => {
    const powerRuleForm = event.target.closest('[data-power-rule-form]');
    if (powerRuleForm) {
      event.preventDefault();
      const button = event.submitter;
      button.disabled = true;
      const rules = Object.fromEntries(new FormData(powerRuleForm));
      rules.dimming_modes = qa('[name="dimming_mode"]:checked', powerRuleForm).map(input => input.value);
      try {
        const result = await post('save_power_rules', { group_id: selectedGroup().id, rules });
        await refreshWorkspace();
        state.tab = 'quick_rules';
        renderOptionPanel();
        const followup = integer(result.incompatible)
          ? `；有 ${integer(result.incompatible)} 个已有电源不再适配，请处理后再审批`
          : integer(result.needs_review) ? `；有 ${integer(result.needs_review)} 个电源资料不足，需要审批确认` : '';
        notify('电源关键范围已保存', `候选电源会立即按新范围筛选${followup}。`);
      } catch (error) {
        notify('保存失败', error.message);
        button.disabled = false;
      }
      return;
    }
    const quickRuleForm = event.target.closest('[data-quick-rule-form]');
    if (quickRuleForm) {
      event.preventDefault();
      const button = event.submitter;
      button.disabled = true;
      const rules = Object.fromEntries(new FormData(quickRuleForm));
      if (!('availability' in rules)) rules.availability = 'allowed';
      try {
        const result = await post('save_quick_rules', { group_id: selectedGroup().id, rules });
        await refreshWorkspace();
        state.tab = 'quick_rules';
        renderOptionPanel();
        const followup = integer(result.incompatible)
          ? `；有 ${integer(result.incompatible)} 个已有选项明确不适配，请更换后再审批`
          : integer(result.needs_review) ? `；有 ${integer(result.needs_review)} 个选项资料不足，需要审批确认` : '';
        notify('关键范围已保存', `候选物料会立即按新范围筛选${followup}。`);
      } catch (error) {
        notify('保存失败', error.message);
        button.disabled = false;
      }
      return;
    }
    const defaultForm = event.target.closest('[data-default-form]');
    if (defaultForm) {
      event.preventDefault();
      const ids = qa('[name="default_option"]:checked', defaultForm).map(input => integer(input.value));
      try {
        await post('set_default', {
          group_id: selectedGroup().id,
          option_ids: ids,
          min_select: defaultForm.elements.min_select?.value ?? selectedGroup().min_select,
          max_select: defaultForm.elements.max_select?.value ?? selectedGroup().max_select,
        });
        await refreshWorkspace();
        state.tab = 'default';
        renderOptionPanel();
        notify('默认设置已保存', '默认项变更已写入日志。');
      } catch (error) {
        notify('保存失败', error.message);
      }
      return;
    }
    const impactForm = event.target.closest('[data-impact-form]');
    if (impactForm) {
      event.preventDefault();
      const button = event.submitter;
      button.disabled = true;
      try {
        for (const option of state.workspace.options) {
          await post('save_option', {
            group_id: selectedGroup().id,
            material_id: option.material_id,
            option_type: option.option_type,
            is_default: option.is_default,
            price_impact: impactForm.elements[`price_${option.id}`].value,
            lead_time_impact_days: impactForm.elements[`lead_${option.id}`].value,
            sort_order: option.sort_order,
          });
        }
        await refreshWorkspace();
        state.tab = 'impact';
        renderOptionPanel();
        notify('价格 / 交期已保存', '所有影响值已保存并进入待审批状态。');
      } catch (error) {
        notify('保存失败', error.message);
        button.disabled = false;
      }
    }
  });

  q('[data-group-list]').addEventListener('dragstart', event => {
    const card = event.target.closest('[data-group-id]');
    if (!card) return;
    state.draggingGroupId = integer(card.dataset.groupId);
    card.classList.add('is-dragging');
    event.dataTransfer.effectAllowed = 'move';
  });

  q('[data-group-list]').addEventListener('dragover', event => {
    const target = event.target.closest('[data-group-id]');
    const dragging = q(`[data-group-id="${state.draggingGroupId}"]`);
    if (!target || !dragging || target === dragging) return;
    event.preventDefault();
    const rect = target.getBoundingClientRect();
    target.parentNode.insertBefore(dragging, event.clientY < rect.top + rect.height / 2 ? target : target.nextSibling);
  });

  q('[data-group-list]').addEventListener('dragend', async event => {
    event.target.closest('[data-group-id]')?.classList.remove('is-dragging');
    const ids = qa('[data-group-id]', q('[data-group-list]')).map(card => integer(card.dataset.groupId));
    state.draggingGroupId = 0;
    try {
      await post('reorder_groups', { product_id: selectedProductId(), group_ids: ids });
      await refreshWorkspace();
      notify('排序已保存', '配置组顺序已更新。');
    } catch (error) {
      notify('排序保存失败', error.message);
      await refreshWorkspace();
    }
  });

  qa('[data-adaptation-modal] form').forEach(form => {
    form.addEventListener('input', () => { state.dirty = true; });
  });
  window.addEventListener('beforeunload', event => {
    if (!state.dirty) return;
    event.preventDefault();
    event.returnValue = '';
  });
  document.addEventListener('keydown', event => {
    if (event.key !== 'Escape') return;
    const modal = q('[data-adaptation-modal].is-open');
    if (!modal) return;
    if (state.dirty && !window.confirm('当前修改尚未保存，确定离开吗？')) return;
    closeModal(modal);
  });

  populateGroupFormTypes();
  render();
})();
