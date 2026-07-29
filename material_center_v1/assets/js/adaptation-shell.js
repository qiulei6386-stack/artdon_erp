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
    candidateDiscoveryGroupId: 0,
    candidateDiscoveryRows: [],
    dirty: false,
    draggingGroupId: 0,
    batchSelected: new Set(),
    batchPreview: null,
    batchQuery: '',
    productSelected: new Set(),
    productStatusFilter: 'all',
    productTypeFilter: 'all',
    templateTargetIds: [],
    reuseSourceWorkspace: null,
    reusePreview: null,
    reuseTemplates: [],
    batchReuseTemplate: null,
    productSearchTimer: 0,
    view: 'overview',
    overviewProductQuery: '',
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
  const workspaceWidthStorageKey = 'artdon.material.adaptation.workspace-widths.v1';
  const drawerWidthStorageKey = 'artdon.material.adaptation.drawer-width.v1';
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
  const hasCandidateDiscoveryRules = group => {
    if (!group) return false;
    if (group.material_category_code !== 'power_supply') return quickRuleCount(group) > 0;
    const ignored = new Set(['id', 'legacy_product_id', 'rule_name', 'status', 'created_at', 'updated_at', 'created_by', 'updated_by']);
    return Object.entries(state.workspace?.power_rule || {}).some(([key, value]) => !ignored.has(key) && value !== '' && value !== null && value !== 'unknown' && (!Array.isArray(value) || value.length));
  };

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

  const setWorkspaceUrl = (productId, groupId = 0, mode = 'replace') => {
    const url = new URL(location.href);
    productId ? url.searchParams.set('product_id', productId) : url.searchParams.delete('product_id');
    groupId ? url.searchParams.set('group_id', groupId) : url.searchParams.delete('group_id');
    history[`${mode}State`]({ productId: integer(productId), groupId: integer(groupId) }, '', url);
  };

  const updateUrl = () => setWorkspaceUrl(selectedProductId(), integer(selectedGroup()?.id));

  const restoreDrawerWidth = () => {
    const stored = integer(localStorage.getItem(drawerWidthStorageKey));
    if (stored >= 560 && stored <= 720) page.style.setProperty('--workbench-drawer-width', `${stored}px`);
  };

  const bindDrawerResize = () => {
    const resizer = q('[data-workbench-drawer-resizer]');
    if (!resizer) return;
    resizer.addEventListener('pointerdown', event => {
      if (window.innerWidth <= 980) return;
      event.preventDefault();
      resizer.setPointerCapture?.(event.pointerId);
      document.body.classList.add('mc-is-resizing');
      const resize = move => {
        const width = Math.max(560, Math.min(720, Math.round(window.innerWidth - move.clientX)));
        page.style.setProperty('--workbench-drawer-width', `${width}px`);
        localStorage.setItem(drawerWidthStorageKey, String(width));
      };
      const done = () => {
        document.body.classList.remove('mc-is-resizing');
        window.removeEventListener('pointermove', resize);
        window.removeEventListener('pointerup', done);
      };
      window.addEventListener('pointermove', resize);
      window.addEventListener('pointerup', done, { once: true });
    });
  };

  const productMatchesStatus = product => {
    if (state.productStatusFilter === 'all') return true;
    if (state.productStatusFilter === 'configured') return integer(product.group_count) > 0;
    if (state.productStatusFilter === 'conflict') return integer(product.conflict_count) > 0;
    return product.configuration_state === state.productStatusFilter;
  };

  const productMatchesType = product => state.productTypeFilter === 'all'
    || String(product.product_type || '') === state.productTypeFilter;

  const visibleProducts = () => state.products.filter(product => productMatchesStatus(product) && productMatchesType(product));

  const focusSelectedProduct = (behavior = 'smooth') => {
    const activeId = selectedProductId();
    if (!activeId) return;
    requestAnimationFrame(() => {
      q(`[data-product-id="${activeId}"]`, q('[data-product-list]'))?.scrollIntoView({ block: 'nearest', behavior });
    });
  };

  const renderProductSelection = () => {
    const count = state.productSelected.size;
    q('[data-product-selection-bar]').hidden = !count;
    q('[data-product-selection-count]').textContent = `已选择 ${count} 个`;
    q('[data-selected-template]').disabled = !count;
    q('[data-selected-reuse-template]').disabled = !count;
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
    const typeSelect = q('[data-product-type-filter]');
    if (typeSelect) {
      const types = [...new Set(state.products.map(product => String(product.product_type || '').trim()).filter(Boolean))].sort((left, right) => left.localeCompare(right, 'zh-CN'));
      typeSelect.innerHTML = `<option value="all">全部类型（${state.products.length}）</option>${types.map(type => `<option value="${escapeHtml(type)}">${escapeHtml(type)}（${state.products.filter(product => String(product.product_type || '') === type).length}）</option>`).join('')}`;
      typeSelect.value = types.includes(state.productTypeFilter) ? state.productTypeFilter : 'all';
    }
  };

  const renderProducts = () => {
    renderProductFilter();
    const products = visibleProducts();
    const count = q('[data-product-count]');
    if (count) count.textContent = `显示 ${products.length} / 共 ${state.products.length} 个`;
    const activeId = selectedProductId();
    const list = q('[data-product-list]');
    const activeProduct = state.workspace?.product || state.products.find(product => integer(product.id) === activeId);
    const lockedProduct = activeProduct ? `<section class="mc-product-lock" data-product-lock>
        <div><span>当前锁定产品</span><strong>${escapeHtml(activeProduct.product_code || '未编号')} · ${escapeHtml(activeProduct.product_name || '未命名产品')}</strong><small>${escapeHtml(activeProduct.series_name || '未设置系列')} · 已选中，不会因生成配置组而切换</small></div>
        <button class="mc-button" type="button" data-product-locate>定位</button>
      </section>` : '';
    list.innerHTML = `${lockedProduct}${products.length
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
      : '<div class="mc-empty-state"><strong>没有符合筛选的产品</strong><span>调整配置状态或搜索条件。</span></div>'}`;
    qa('[data-product-image]', list).forEach(image => image.addEventListener('error', () => {
      const placeholder = document.createElement('span');
      placeholder.className = 'mc-product-thumb__placeholder';
      placeholder.textContent = '◇';
      image.replaceWith(placeholder);
    }, { once: true }));
    renderProductSelection();
    focusSelectedProduct('auto');
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
    q('[data-rule-subtitle]').textContent = `${product.product_code || '未编号产品'} · 配置组工作区`;
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

  const overviewGroupIcon = group => ({
    chip: '◉', power_supply: 'ϟ', optical: '◎', profile: '▧', connector: '⌁', accessory: '◇', packaging: '□',
  }[group?.material_category_code] || '◇');

  const overviewGroupAction = group => {
    const status = group?.display_status || group?.status || 'empty';
    if (status === 'empty') return '添加选项';
    if (status === 'no_default') return '设置默认';
    if (status === 'conflict') return '处理冲突';
    if (status === 'pending') return '检查审批';
    return '管理';
  };

  const productImageUrl = product => {
    const rawImage = String(product?.image_url || '');
    if (!rawImage) return '';
    return rawImage.startsWith('http://') || rawImage.startsWith('https://') || rawImage.startsWith('/')
      ? rawImage
      : `${state.baseUrl.replace('/material_center_v1', '')}/${rawImage.startsWith('./') ? rawImage.slice(2) : rawImage}`;
  };

  const renderOverviewProductList = () => {
    const target = q('[data-overview-product-list]');
    if (!target) return;
    const query = state.overviewProductQuery.trim().toLowerCase();
    const selectedId = selectedProductId();
    const products = state.catalogProducts.filter(product => !query || [product.product_code, product.product_name, product.series_name]
      .some(value => String(value || '').toLowerCase().includes(query))).slice(0, 80);
    target.innerHTML = products.length ? products.map(product => {
      const id = integer(product.id);
      return `<button type="button" class="${id === selectedId ? 'is-active' : ''}" data-overview-product-id="${id}">
        <span><strong>${escapeHtml(product.product_code || '未编号')}</strong><small>${escapeHtml(product.product_name || '未命名产品')} · ${escapeHtml(product.series_name || '未设置系列')}</small></span>
        <em>${escapeHtml(product.approval_label || '未配置')}</em>
      </button>`;
    }).join('') : '<div class="mc-empty-state mc-empty-state--compact"><strong>没有符合条件的产品</strong><span>请调整型号、名称或系列关键词。</span></div>';
  };

  const renderOverviewDashboard = () => {
    const target = q('[data-overview-dashboard]');
    const workspace = state.workspace;
    if (!target) return;
    if (!workspace) {
      target.hidden = false;
      target.innerHTML = `<section class="mc-workbench-welcome"><div><span>产品适配</span><h2>先选择一个产品，开始配置</h2><p>选定后，产品会锁定在顶部；核心物料、扩展可配、条件规则和发布检查都在同一个工作台完成。</p><button class="mc-button mc-button--primary" type="button" data-overview-switch-product>选择产品</button></div></section>`;
      return;
    }
    const product = workspace.product || {};
    const completion = workspace.completion || {};
    const groups = workspace.groups || [];
    const overviewById = new Map((workspace.configuration_overview || []).map(group => [integer(group.id), group]));
    const missing = groups.filter(group => ['empty', 'no_default', 'conflict'].includes(group.display_status || group.status));
    const pending = groups.filter(group => (group.display_status || group.status) === 'pending');
    const activeId = integer(workspace.active_group?.id);
    const nextGroup = groups.find(group => ['empty', 'no_default', 'conflict', 'pending'].includes(group.display_status || group.status)) || groups[0];
    const imageUrl = productImageUrl(product);
    const issueText = (completion.issues || [])[0] || (nextGroup ? `${nextGroup.group_name}：${overviewGroupAction(nextGroup)}` : '先建立完整标准配置组，再逐项补齐正式物料。');
    const coreCodes = new Set(['light_source', 'power_driver', 'optical', 'installation']);
    const coreGroups = groups.filter(group => coreCodes.has(group.group_code));
    const extensionGroups = groups.filter(group => !coreCodes.has(group.group_code));
    const renderWorkbenchCard = group => {
      const details = overviewById.get(integer(group.id)) || group;
      const status = group.display_status || details.status || 'empty';
      const action = overviewGroupAction(group);
      const availabilityLabels = { forbidden: '不适用', not_applicable: '不适用', not_offered: '暂不提供', later: '稍后处理' };
      const optionalState = !integer(group.is_required) && status === 'empty'
        ? '未添加（可稍后）'
        : (availabilityLabels[details.availability || group.availability] || (status === 'forbidden' ? '不适用' : ''));
      return `<article class="mc-workbench-group-card ${integer(group.id) === activeId ? 'is-active' : ''} is-${escapeHtml(status)}">
        <div class="mc-workbench-group-card__head"><span>${overviewGroupIcon(group)}</span><div><strong>${escapeHtml(group.group_name)}</strong><small>${integer(group.is_required) ? '核心必配' : '扩展可配'} · ${group.selection_mode === 'multi' ? '多选' : '单选'}</small></div><em>${escapeHtml(status === 'enabled' ? '已完成' : (optionalState || overviewStatusLabel(status)))}</em></div>
        <p><b>默认：</b>${escapeHtml(configuredMaterialText(details))}</p>
        <div class="mc-workbench-group-card__facts"><span>正式 ${integer(group.option_count)}</span><span>候选 ${integer(group.alternative_count)}</span><span>冲突 ${integer(group.conflict_count)}</span></div>
        <button type="button" data-select-group="${integer(group.id)}">${escapeHtml(action)}</button>
      </article>`;
    };
    target.hidden = false;
    target.innerHTML = `<section class="mc-overview-product-hero">
      <div class="mc-overview-product-image">${imageUrl ? `<img src="${escapeHtml(imageUrl)}" alt="" data-overview-product-image>` : '<span>◇</span>'}</div>
      <div class="mc-overview-product-info"><strong><b>${escapeHtml(product.product_code || '未编号')}</b>${escapeHtml(product.product_name || '未命名产品')}</strong><div class="mc-overview-product-meta"><span>系列：${escapeHtml(product.series_name || '未设置系列')}</span><span>类型：${escapeHtml(product.product_type || '未设置')}</span><span>状态：${escapeHtml(product.approval_label || '未配置')}</span></div><small>${groups.length ? `已建立 ${groups.length} 个配置组` : '尚未建立配置组'} · 最近更新：${escapeHtml(product.updated_at || product.synced_at || '待同步')}</small></div>
      <div class="mc-overview-completion"><b style="--completion:${Math.min(100, integer(completion.percent))}"><i>${integer(completion.percent)}%</i></b><span>配置完成度</span></div>
      <div class="mc-overview-hero-metrics"><span><b>${missing.length}</b>缺失项</span><span><b>${integer(workspace.conflicts?.length)}</b>冲突项</span><span><b>${pending.length}</b>待审批</span><span><b>${integer(product.option_count)}</b>正式选项</span></div>
    </section>
    <nav class="mc-workbench-steps" aria-label="产品配置步骤"><button type="button" class="is-done" data-workbench-step="1"><b>1</b>选择产品<small>已完成</small></button><i></i><button type="button" class="is-active" data-workbench-step="2"><b>2</b>核心必配<small>进行中</small></button><i></i><button type="button" data-workbench-step="3"><b>3</b>扩展可配<small>按需配置</small></button><i></i><button type="button" data-workbench-step="4"><b>4</b>条件规则<small>集中管理</small></button><i></i><button type="button" data-workbench-step="5"><b>5</b>检查发布<small>待进行</small></button></nav>
    <section class="mc-overview-next-step ${nextGroup ? '' : 'is-ready'}"><span class="mc-overview-next-step__icon">${nextGroup ? '!' : '✓'}</span><div><strong>${nextGroup ? '下一步建议' : '配置检查通过'}</strong><span>${escapeHtml(issueText)}</span></div>${nextGroup ? `<button type="button" class="mc-button mc-button--primary" data-select-group="${integer(nextGroup.id)}">${escapeHtml(overviewGroupAction(nextGroup))}</button>` : '<button type="button" class="mc-button" data-overview-submit>提交审批</button>'}</section>
    ${groups.length ? `<section class="mc-workbench-group-section"><div><h2>A. 核心必配 <small>必须完成的关键物料</small></h2><span>完成后才可进入检查发布</span></div><section class="mc-workbench-groups mc-workbench-groups--core">${coreGroups.map(renderWorkbenchCard).join('')}</section></section><section class="mc-workbench-group-section"><div><h2>B. 扩展可配 <small>按需选择的可选物料</small></h2><span>可配置、标为不适用或稍后处理</span></div><section class="mc-workbench-groups mc-workbench-groups--extension">${extensionGroups.map(renderWorkbenchCard).join('')}</section></section>` : `<article class="mc-overview-empty-groups"><strong>还没有配置组</strong><span>标准模板会一次建立芯片、电源、光学、安装方式等完整结构；随后可使用“配置模板”映射到多个产品。</span><button class="mc-button mc-button--primary" type="button" data-overview-template>建立标准配置组</button></article>`}`;
    qa('[data-overview-product-image]', target).forEach(image => image.addEventListener('error', () => {
      const fallback = document.createElement('span');
      fallback.textContent = '◇';
      image.replaceWith(fallback);
    }, { once: true }));
  };

  const renderPersistentConfiguration = () => {
    const target = q('[data-selected-configuration]');
    if (!target) return;
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

  const renderCandidateDiscovery = () => {
    const target = q('[data-candidate-discovery]');
    const group = selectedGroup();
    const sameGroup = group && integer(state.candidateDiscoveryGroupId) === integer(group.id);
    if (!target || state.tab !== 'options' || !sameGroup) {
      if (target) {
        target.hidden = true;
        target.innerHTML = '';
      }
      return;
    }
    const rows = state.candidateDiscoveryRows || [];
    const addable = rows.filter(row => row.status === 'official' && row.match_level !== 'incompatible');
    const exact = rows.filter(row => row.match_level === 'exact').length;
    const review = rows.filter(row => row.match_level === 'needs_approval').length;
    const conditional = rows.filter(row => row.match_level === 'conditional').length;
    target.hidden = false;
    target.innerHTML = `<div class="mc-candidate-discovery__head">
        <div><strong>候选物料</strong><span>已按“${escapeHtml(group.group_name)}”当前关键范围实时筛选；点击某项进入真实物料库勾选，不会直接写入产品。</span></div>
        <button class="mc-button mc-button--primary" type="button" data-candidate-discovery-open>从物料库添加</button>
      </div>
      <div class="mc-candidate-discovery__metrics">
        <span><b>${rows.length}</b>正式物料</span>
        <span><b>${addable.length}</b>可加入</span>
        <span><b>${exact}</b>完全适配</span>
        <span><b>${conditional}</b>条件适配</span>
        <span><b>${review}</b>需确认</span>
      </div>
      ${rows.length ? `<div class="mc-candidate-discovery__list">${rows.slice(0, 6).map(material => `<button type="button" class="mc-candidate-discovery__row mc-candidate-discovery__row--${escapeHtml(material.match_level)}" data-candidate-discovery-open>
          <span class="mc-candidate-discovery__radio" aria-hidden="true"></span><div><strong>${escapeHtml(material.material_code)} · ${escapeHtml(`${material.brand || ''} ${material.model || material.name || ''}`.trim())}</strong><span>${escapeHtml(material.key_specs || '暂无关键规格')}</span></div>
          <b>${escapeHtml(material.match_label)}</b>
          <small>${escapeHtml((material.conflict_reasons || []).join('；') || '符合当前关键范围')}</small>
        </button>`).join('')}</div>${rows.length > 6 ? `<p class="mc-candidate-discovery__more">还有 ${rows.length - 6} 项候选，请从物料库继续查看。</p>` : ''}` : `<div class="mc-empty-state mc-empty-state--compact"><strong>当前分类没有可检查的正式物料</strong><span>请确认对应分类是否已有已转正式物料，并补齐芯片、电源或光学的关键规格。</span></div>`}`;
  };

  const renderConfigurationOverview = () => {
    const root = q('[data-configuration-overview]');
    const overview = state.workspace?.configuration_overview || [];
    // 编辑单个配置组时，右侧已有“已选物料持续显示”。中间再重复一整块总览会
    // 挤压真正需要操作的配置组；只有尚未进入某个组时才在中间展示总览。
    if (!state.workspace || !overview.length || selectedGroup()) {
      root.hidden = true;
      root.innerHTML = '';
      return;
    }
    root.hidden = false;
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
    const templateButton = q('[data-template-open]');
    if (templateButton) templateButton.disabled = !workspace;
    q('[data-batch-open]').disabled = !workspace?.groups?.length;
    q('[data-reuse-open]').disabled = !workspace;
    q('[data-overview-submit]').disabled = !workspace;
    if (templateButton) templateButton.textContent = workspace?.groups?.length ? '补齐完整标准模板' : '套用完整标准模板';
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
    renderCandidateDiscovery();
    q('[data-option-tabs]').hidden = !group;
    q('[data-candidate-open]').disabled = !group;
    q('[data-open-quick-rules]').disabled = !group;
    q('[data-open-approval]').disabled = !group;
    q('[data-option-subtitle]').textContent = group?.group_name || '请选择配置组';
    q('[data-option-title]').textContent = group?.group_name || '选项详情';
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
          <span>这个产品如何处理“${escapeHtml(group.group_name)}”</span>
          <select name="availability" ${integer(group.is_required) ? 'disabled' : ''}>
            <option value="allowed" ${(rules.availability || 'allowed') === 'allowed' ? 'selected' : ''}>允许使用</option>
            <option value="forbidden" ${rules.availability === 'forbidden' ? 'selected' : ''}>不允许使用</option>
            <option value="not_applicable" ${rules.availability === 'not_applicable' ? 'selected' : ''}>不适用</option>
            <option value="not_offered" ${rules.availability === 'not_offered' ? 'selected' : ''}>暂不提供</option>
            <option value="later" ${rules.availability === 'later' ? 'selected' : ''}>稍后处理</option>
          </select>
          ${integer(group.is_required) ? '<small>这是核心必配组，必须完成；可选组可明确标记不适用、暂不提供或稍后处理。</small>' : '<small>明确标记后不会再算作缺失项，也不会伪造“已配置”。</small>'}
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
      const releases = workspace.published_versions || [];
      const nextVersion = integer(releases[0]?.version_no) + 1 || 1;
      detail.innerHTML = `<div class="mc-approval-readiness">
        <div class="mc-approval-score"><span>配置完成度</span><strong>${integer(completion.percent)}%</strong><small>${integer(completion.checks_passed)} / ${integer(completion.checks_total)} 项检查通过</small></div>
        ${completion.issues.length ? `<div class="mc-completion-issues"><strong>提交前还需处理</strong>${completion.issues.map(issue => `<p>${escapeHtml(issue)}</p>`).join('')}</div>` : '<div class="mc-completion-ready"><strong>审批检查已通过</strong><p>发布后会冻结为独立版本，商务中心只读取已发布版本。</p></div>'}
        ${exceptions ? `<label class="mc-exception-approval"><input type="checkbox" data-approve-exceptions><span>本次审批同时批准 ${exceptions} 个适配例外</span></label>` : ''}
        ${releases.length ? `<section class="mc-published-version-list"><strong>已发布版本</strong>${releases.map(release => `<div><b>V${integer(release.version_no)}</b><span>${escapeHtml(release.published_at || '')}</span><small>${escapeHtml(release.publisher_name || '系统管理员')}</small></div>`).join('')}</section>` : '<p class="mc-published-version-empty">尚未发布版本；商务中心暂不会读取此产品的草稿配置。</p>'}
        <button class="mc-button mc-button--primary" type="button" data-approve-product>检查并发布 V${nextVersion}</button>
      </div>`;
    }
  };

  const render = () => {
    state.view = 'overview';
    page.dataset.view = 'overview';
    page.dataset.stage = !state.workspace ? 'products' : (selectedGroup() ? 'options' : 'groups');
    page.classList.toggle('is-drawer-open', Boolean(selectedGroup()));
    qa('[data-adaptation-view]').forEach(button => button.classList.toggle('is-active', button.dataset.adaptationView === state.view));
    renderProducts();
    renderSummary();
    renderConfigurationOverview();
    renderGroups();
    renderOptionPanel();
    renderOverviewDashboard();
    renderOverviewProductList();
    updateUrl();
  };

  const loadWorkspace = async (productId, groupId = 0, historyMode = 'replace') => {
    if (historyMode === 'push') setWorkspaceUrl(productId, groupId, 'push');
    q('[data-option-detail]').innerHTML = '<div class="mc-empty-state"><strong>正在加载配置</strong><span>产品切换不会刷新整个页面。</span></div>';
    state.workspace = await get('workspace', { product_id: productId, group_id: groupId });
    const productIndex = state.products.findIndex(product => integer(product.id) === integer(productId));
    if (productIndex >= 0) state.products[productIndex] = { ...state.products[productIndex], ...state.workspace.product };
    const catalogIndex = state.catalogProducts.findIndex(product => integer(product.id) === integer(productId));
    if (catalogIndex >= 0) state.catalogProducts[catalogIndex] = { ...state.catalogProducts[catalogIndex], ...state.workspace.product };
    state.candidateDiscoveryGroupId = 0;
    state.candidateDiscoveryRows = [];
    render();
    if (hasCandidateDiscoveryRules(selectedGroup())) {
      try {
        await loadCandidateDiscovery();
      } catch (error) {
        notify('候选物料暂未加载', error.message);
      }
    }
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

  const loadCandidateDiscovery = async () => {
    const group = selectedGroup();
    if (!group) return;
    state.candidateDiscoveryGroupId = integer(group.id);
    state.candidateDiscoveryRows = [];
    renderCandidateDiscovery();
    const rows = await get('candidates', { group_id: group.id, status: 'official' });
    if (integer(selectedGroup()?.id) !== integer(group.id)) return;
    state.candidateDiscoveryRows = rows;
    renderCandidateDiscovery();
  };

  const clamp = (value, min, max) => Math.min(Math.max(value, min), max);

  const applyWorkspaceWidths = widths => {
    const productWidth = integer(widths?.products);
    const groupWidth = integer(widths?.groups);
    if (productWidth) page.style.setProperty('--mc-adaptation-products-width', `${productWidth}px`);
    if (groupWidth) page.style.setProperty('--mc-adaptation-groups-width', `${groupWidth}px`);
  };

  const saveWorkspaceWidths = widths => {
    try {
      localStorage.setItem(workspaceWidthStorageKey, JSON.stringify(widths));
    } catch {
      // 宽度只影响当前浏览器体验，无法保存时仍可正常拖动。
    }
  };

  const setupWorkspaceResizers = () => {
    const workspace = q('.mc-adaptation-workspace');
    if (!workspace) return;
    try {
      applyWorkspaceWidths(JSON.parse(localStorage.getItem(workspaceWidthStorageKey) || '{}'));
    } catch {
      // 旧格式或异常存储直接使用页面默认宽度。
    }
    qa('[data-adaptation-resize]', workspace).forEach(handle => {
      handle.addEventListener('pointerdown', event => {
        if (window.matchMedia('(max-width: 900px)').matches) return;
        event.preventDefault();
        const bounds = workspace.getBoundingClientRect();
        const style = getComputedStyle(page);
        const startProducts = Number.parseFloat(style.getPropertyValue('--mc-adaptation-products-width')) || 310;
        const startGroups = Number.parseFloat(style.getPropertyValue('--mc-adaptation-groups-width')) || 350;
        const startX = event.clientX;
        const minimumMiddle = 430;
        const gutters = 20;
        page.classList.add('is-resizing-workspace');
        const move = moveEvent => {
          const delta = moveEvent.clientX - startX;
          const next = { products: startProducts, groups: startGroups };
          if (handle.dataset.adaptationResize === 'products') {
            next.products = clamp(startProducts + delta, 240, Math.max(240, bounds.width - startGroups - minimumMiddle - gutters));
          } else {
            next.groups = clamp(startGroups - delta, 280, Math.max(280, bounds.width - startProducts - minimumMiddle - gutters));
          }
          applyWorkspaceWidths(next);
        };
        const stop = () => {
          document.removeEventListener('pointermove', move);
          document.removeEventListener('pointerup', stop);
          page.classList.remove('is-resizing-workspace');
          const styleAfter = getComputedStyle(page);
          saveWorkspaceWidths({
            products: Math.round(Number.parseFloat(styleAfter.getPropertyValue('--mc-adaptation-products-width')) || startProducts),
            groups: Math.round(Number.parseFloat(styleAfter.getPropertyValue('--mc-adaptation-groups-width')) || startGroups),
          });
        };
        document.addEventListener('pointermove', move);
        document.addEventListener('pointerup', stop, { once: true });
      });
      handle.addEventListener('keydown', event => {
        const direction = event.key === 'ArrowLeft' ? -1 : event.key === 'ArrowRight' ? 1 : 0;
        if (!direction || window.matchMedia('(max-width: 900px)').matches) return;
        event.preventDefault();
        const style = getComputedStyle(page);
        const products = Number.parseFloat(style.getPropertyValue('--mc-adaptation-products-width')) || 310;
        const groups = Number.parseFloat(style.getPropertyValue('--mc-adaptation-groups-width')) || 350;
        const step = event.shiftKey ? 40 : 16;
        const next = handle.dataset.adaptationResize === 'products'
          ? { products: clamp(products + direction * step, 240, 520), groups }
          : { products, groups: clamp(groups - direction * step, 280, 520) };
        applyWorkspaceWidths(next);
        saveWorkspaceWidths(next);
      });
    });
  };

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

  const renderReuseTemplateCreate = () => {
    const root = q('[data-reuse-template-create]');
    const product = state.workspace?.product;
    const groups = state.workspace?.groups || [];
    if (!product || !groups.length) {
      root.innerHTML = `<div class="mc-empty-state mc-empty-state--compact"><strong>先选择一个已配置的来源产品</strong><span>模板会从当前产品保存所选配置组；例如只勾选“电源 / 驱动”，即可建立电源模板。</span></div>`;
      q('[data-reuse-template-save]').disabled = true;
      return;
    }
    const defaultChecked = group => integer(group.option_count) > 0 ? 'checked' : '';
    root.innerHTML = `<div class="mc-reuse-template-source"><span>当前模板来源</span><strong>${escapeHtml(product.product_code || '未编号')} · ${escapeHtml(product.product_name || '未命名')}</strong><small>可任意组合配置组；未勾选的组不会被模板覆盖。</small></div>
      <div class="mc-form-grid">
        <label class="mc-field"><span>模板名称 *</span><input name="template_name" maxlength="160" required placeholder="例如：35mm 轨道灯外置电源 + 芯片"></label>
        <label class="mc-field"><span>模板说明</span><input name="description" maxlength="500" placeholder="适用产品、尺寸或安装方式"></label>
      </div>
      <div class="mc-reuse-template-groups"><div><strong>选择模板内容</strong><span>只会复制勾选组及其物料、关键范围、默认项、条件和冲突关系。</span></div>
        <div>${groups.map(group => `<label><input type="checkbox" name="reuse_template_group" value="${integer(group.id)}" ${defaultChecked(group)}><span><strong>${escapeHtml(group.group_name)}</strong><small>${integer(group.option_count)} 个候选 · ${integer(group.is_required) ? '必选' : '可选'} · ${group.selection_mode === 'multi' ? '多选' : '单选'}</small></span></label>`).join('')}</div>
        <label class="mc-reuse-template-power"><input type="checkbox" name="include_power_rule" value="1" ${state.workspace?.power_rule ? 'checked' : ''}><span><strong>同时保存电源关键范围</strong><small>内置 / 外置、功率、电流、电压、空间、调光、质保和认证随模板一起映射。</small></span></label>
      </div>`;
    q('[data-reuse-template-save]').disabled = false;
  };

  const renderReuseTemplateList = () => {
    const root = q('[data-reuse-template-list]');
    const templates = state.reuseTemplates || [];
    root.innerHTML = templates.length ? templates.map(template => {
      const groups = (template.group_names || []).map(escapeHtml).join('、') || '仅电源关键范围';
      const stale = Boolean(template.is_stale);
      return `<article class="mc-reuse-template-card">
        <div><strong>${escapeHtml(template.template_name)}</strong><span>${escapeHtml(template.product_code || '未编号')} · ${escapeHtml(template.product_name || '未命名')}</span><small>${groups}${template.include_power_rule ? ' · 含电源范围' : ''}</small>${stale ? '<em class="mc-reuse-template-warning">来源配置组已变更，请停用后重新建立模板。</em>' : ''}${template.description ? `<em>${escapeHtml(template.description)}</em>` : ''}</div>
        <div><b>${integer(template.group_count)} 组</b><button class="mc-button mc-button--primary" type="button" data-use-reuse-template="${integer(template.id)}" ${stale ? 'disabled' : ''}>映射到产品</button><button class="mc-button mc-button--danger" type="button" data-disable-reuse-template="${integer(template.id)}">停用</button></div>
      </article>`;
    }).join('') : '<div class="mc-empty-state mc-empty-state--compact"><strong>还没有保存模板</strong><span>从当前产品勾选配置组后保存；以后可反复映射到任意产品。</span></div>';
  };

  const loadReuseTemplates = async () => {
    state.reuseTemplates = await get('reuse_templates');
    renderReuseTemplateList();
  };

  const openReuseTemplateLibrary = async () => {
    renderReuseTemplateCreate();
    renderReuseTemplateList();
    state.dirty = false;
    openModal('reuse-template-modal');
    try {
      await loadReuseTemplates();
    } catch (error) {
      notify('模板读取失败', error.message);
    }
  };

  const visibleBatchProducts = () => {
    const query = state.batchQuery.trim().toLowerCase();
    const sourceProductId = integer(state.batchReuseTemplate?.source_product_id || selectedProductId());
    return state.catalogProducts.filter(product => {
      if (integer(product.id) === sourceProductId) return false;
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

  const openBatch = (preselected = [], reuseTemplate = null) => {
    const source = reuseTemplate || state.workspace?.product;
    if (!source || (!reuseTemplate && !state.workspace?.groups?.length)) {
      notify('暂不能批量套用', '请先选择并设置好一个来源产品。');
      return;
    }
    const sourceProductId = integer(reuseTemplate?.source_product_id || selectedProductId());
    state.batchReuseTemplate = reuseTemplate;
    state.batchSelected = new Set(preselected.map(integer).filter(id => id && id !== sourceProductId).slice(0, 1000));
    state.batchPreview = null;
    state.batchQuery = '';
    const form = q('[data-batch-form]');
    form.reset();
    q('[data-batch-search]').value = '';
    q('[data-batch-source]').innerHTML = reuseTemplate
      ? `<span>正在套用配置模板</span><strong>${escapeHtml(reuseTemplate.template_name || '未命名模板')}</strong><small>来源：${escapeHtml(reuseTemplate.product_code || '未编号')} · ${escapeHtml(reuseTemplate.product_name || '未命名')}；${integer(reuseTemplate.group_count)} 个配置组${reuseTemplate.include_power_rule ? '，含电源范围' : ''}。</small>`
      : `<span>当前来源产品</span><strong>${escapeHtml(source.product_code || '未编号')} · ${escapeHtml(source.product_name || '未命名')}</strong><small>${state.workspace.groups.length} 个配置组；目标产品执行后统一进入待重审。</small>`;
    q('[data-batch-form] .mc-batch-power').hidden = !!reuseTemplate;
    renderBatchProducts();
    updateBatchSelection(false);
    q('[data-batch-preview]').innerHTML = '<strong>3. 先预览，再执行</strong><span>选择目标产品后点击“预览影响”，系统会先计算新增、覆盖和跳过数量。</span>';
    q('[data-batch-apply]').disabled = true;
    state.dirty = false;
    openModal('batch-modal');
  };

  const batchRequestValues = () => {
    const form = q('[data-batch-form]');
    if (state.batchReuseTemplate) {
      return {
        reuse_template_id: integer(state.batchReuseTemplate.id),
        target_product_ids: [...state.batchSelected],
        mode: new FormData(form).get('mode') || 'fill_missing',
      };
    }
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
      state.batchPreview = await post(state.batchReuseTemplate ? 'preview_reuse_template' : 'preview_batch', batchRequestValues());
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
    q('[data-candidate-summary]').textContent = `共 ${state.candidates.length} 个正式候选物料；不适配项可强制加入，但必须说明原因并进入审批。`;
    list.innerHTML = state.candidates.length ? state.candidates.map(material => {
      const blocked = material.status !== 'official' || material.already_added;
      return `<label class="mc-candidate-card mc-candidate-card--${escapeHtml(material.match_level)} ${blocked ? 'is-disabled' : ''}">
        <input type="checkbox" name="material_choice" value="${integer(material.id)}" ${material.match_level === 'incompatible' ? 'data-force-exception="1"' : ''} ${blocked ? 'disabled' : ''}>
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
      <input name="expected" value="${escapeHtml(Array.isArray(condition.expected) ? condition.expected.join(',') : condition.expected ?? '')}" placeholder="介于/属于用逗号分隔；有值/无值可留空">
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
    const viewButton = event.target.closest('[data-adaptation-view]');
    if (viewButton) {
      const view = viewButton.dataset.adaptationView;
      if (view === 'batch') {
        if (!state.workspace?.groups?.length) {
          notify('请先选择已配置产品', '批量矩阵以当前产品的真实配置为来源。');
        } else {
          openBatch();
        }
        return;
      }
      state.view = view;
      render();
      return;
    }
    const workbenchStep = event.target.closest('[data-workbench-step]');
    if (workbenchStep) {
      const step = integer(workbenchStep.dataset.workbenchStep);
      if (step === 1) {
        q('[data-overview-switch-product]')?.click();
        return;
      }
      const groups = state.workspace?.groups || [];
      const coreCodes = new Set(['light_source', 'power_driver', 'optical', 'installation']);
      const targetGroup = step === 2
        ? groups.find(group => coreCodes.has(group.group_code))
        : step === 3
          ? groups.find(group => !coreCodes.has(group.group_code))
          : groups[0];
      if (!targetGroup) {
        notify('请先建立配置组', '先套用标准模板，系统会建立本产品需要的配置组。');
        populateTemplate([selectedProductId()]);
        openModal('template-modal');
        return;
      }
      if (step === 5) {
        q('[data-overview-submit]')?.click();
        return;
      }
      try {
        state.tab = step === 4 ? 'conditions' : 'options';
        await loadWorkspace(selectedProductId(), integer(targetGroup.id), 'push');
      } catch (error) {
        notify('步骤打开失败', error.message);
      }
      return;
    }
    if (event.target.closest('[data-overview-switch-product]')) {
      state.overviewProductQuery = '';
      const input = q('[data-overview-product-search]');
      if (input) input.value = '';
      renderOverviewProductList();
      openModal('overview-product-modal');
      input?.focus();
      return;
    }
    if (event.target.closest('[data-workbench-drawer-close]')) {
      if (!selectedProductId()) return;
      try {
        await loadWorkspace(selectedProductId());
      } catch (error) {
        notify('关闭配置抽屉失败', error.message);
      }
      return;
    }
    const overviewProduct = event.target.closest('[data-overview-product-id]');
    if (overviewProduct) {
      try {
        state.tab = 'options';
        state.view = 'overview';
        await loadWorkspace(integer(overviewProduct.dataset.overviewProductId), 0, 'push');
        closeModal(q('#overview-product-modal'));
      } catch (error) {
        notify('产品加载失败', error.message);
      }
      return;
    }
    if (event.target.closest('[data-overview-template]')) {
      populateTemplate([selectedProductId()]);
      openModal('template-modal');
      return;
    }
    if (event.target.closest('[data-overview-submit]')) {
      if (!state.workspace) {
        notify('请先选择产品', '先选定产品后才能检查配置。');
        return;
      }
      if (!state.workspace.groups?.length) {
        populateTemplate([selectedProductId()]);
        openModal('template-modal');
        return;
      }
      try {
        state.tab = 'approval';
        const groupId = integer(selectedGroup()?.id) || integer(state.workspace.groups[0]?.id);
        if (groupId && integer(selectedGroup()?.id) !== groupId) await loadWorkspace(selectedProductId(), groupId);
        else renderOptionPanel();
      } catch (error) {
        notify('配置检查暂不可用', error.message);
      }
      return;
    }
    if (event.target.closest('[data-product-locate]')) {
      if (q(`[data-product-id="${selectedProductId()}"]`, q('[data-product-list]'))) focusSelectedProduct();
      else notify('当前产品不在搜索结果中', '搜索条件保持不变；清空搜索后即可在列表中定位。');
      return;
    }
    const productButton = event.target.closest('[data-product-id]');
    if (productButton) {
      try {
        state.tab = 'options';
        await loadWorkspace(integer(productButton.dataset.productId), 0, 'push');
        closeModal(q('#overview-product-modal'));
      } catch (error) {
        notify('产品加载失败', error.message);
      }
      return;
    }
    const groupButton = event.target.closest('[data-select-group]');
    if (groupButton) {
      try {
        await loadWorkspace(selectedProductId(), integer(groupButton.dataset.selectGroup), 'push');
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
    if (event.target.closest('[data-open-approval]')) {
      state.tab = 'approval';
      renderOptionPanel();
      q('[data-option-tabs]')?.scrollTo({ left: q('[data-option-tabs]').scrollWidth, behavior: 'smooth' });
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
    if (event.target.closest('[data-reuse-template-open]')) {
      await openReuseTemplateLibrary();
      return;
    }
    if (event.target.closest('[data-selected-reuse-template]')) {
      await openReuseTemplateLibrary();
      return;
    }
    if (event.target.closest('[data-reuse-template-refresh]')) {
      await loadReuseTemplates();
      return;
    }
    const useReuseTemplate = event.target.closest('[data-use-reuse-template]');
    if (useReuseTemplate) {
      const template = state.reuseTemplates.find(row => integer(row.id) === integer(useReuseTemplate.dataset.useReuseTemplate));
      if (!template) {
        notify('模板已变化', '请刷新模板列表后重试。');
        return;
      }
      if (template.is_stale) {
        notify('模板需要重建', '来源配置组已变更，请停用旧模板后从来源产品重新保存。');
        return;
      }
      closeModal(q('#reuse-template-modal'));
      openBatch([...state.productSelected], template);
      return;
    }
    const disableReuseTemplate = event.target.closest('[data-disable-reuse-template]');
    if (disableReuseTemplate) {
      const template = state.reuseTemplates.find(row => integer(row.id) === integer(disableReuseTemplate.dataset.disableReuseTemplate));
      if (!template || !confirm(`停用模板“${template.template_name}”？已映射的产品不会受到影响。`)) return;
      try {
        await post('disable_reuse_template', { reuse_template_id: integer(template.id) });
        await loadReuseTemplates();
        notify('模板已停用', '该模板不会再显示，历史产品配置保持不变。');
      } catch (error) {
        notify('停用失败', error.message);
      }
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
    if (event.target.closest('[data-candidate-open], [data-empty-candidate], [data-candidate-discovery-open]')) {
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
    if (event.target.matches('[data-product-type-filter]')) {
      state.productTypeFilter = event.target.value;
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

  const searchProducts = async query => {
    try {
      const products = await get('products', { q: query });
      const input = q('[data-product-search] input[name="q"]');
      if (input && input.value.trim() !== query) return;
      state.products = products;
      renderProducts();
    } catch (error) {
      notify('搜索失败', error.message);
    }
  };

  q('[data-product-search]').addEventListener('submit', async event => {
    event.preventDefault();
    await searchProducts(String(new FormData(event.currentTarget).get('q') || '').trim());
  });

  q('[data-product-search] input[name="q"]').addEventListener('input', event => {
    const query = event.currentTarget.value.trim();
    clearTimeout(state.productSearchTimer);
    state.productSearchTimer = window.setTimeout(() => searchProducts(query), 220);
  });

  q('[data-overview-product-search]')?.addEventListener('input', event => {
    state.overviewProductQuery = event.currentTarget.value;
    renderOverviewProductList();
  });

  window.addEventListener('popstate', async () => {
    const url = new URL(location.href);
    const productId = integer(url.searchParams.get('product_id'));
    const groupId = integer(url.searchParams.get('group_id'));
    if (!productId || productId === selectedProductId() && groupId === integer(selectedGroup()?.id)) return;
    try {
      await loadWorkspace(productId, groupId);
    } catch (error) {
      notify('无法恢复产品配置', error.message);
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

  q('[data-reuse-template-form]').addEventListener('submit', async event => {
    event.preventDefault();
    const form = event.currentTarget;
    const sourceProductId = selectedProductId();
    const groupIds = qa('[name="reuse_template_group"]:checked', form).map(input => integer(input.value));
    const includePowerRule = !!form.elements.include_power_rule?.checked;
    if (!sourceProductId || (!groupIds.length && !includePowerRule)) {
      notify('模板内容为空', '请至少选择一个配置组，或勾选电源关键范围。');
      return;
    }
    const button = event.submitter || q('[data-reuse-template-save]', form);
    button.disabled = true;
    button.textContent = '正在保存…';
    try {
      const values = new FormData(form);
      await post('save_reuse_template', {
        source_product_id: sourceProductId,
        template_name: values.get('template_name') || '',
        description: values.get('description') || '',
        source_group_ids: groupIds,
        include_power_rule: includePowerRule ? 1 : 0,
      });
      form.reset();
      renderReuseTemplateCreate();
      await loadReuseTemplates();
      notify('配置模板已保存', `已保存 ${groupIds.length} 个配置组；现在可点击“映射到产品”批量套用。`);
    } catch (error) {
      notify('模板保存失败', error.message);
    } finally {
      button.disabled = false;
      button.textContent = '保存为模板';
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
        const part = await post(state.batchReuseTemplate ? 'apply_reuse_template' : 'batch_apply', { ...values, target_product_ids: targetIds });
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
      state.batchReuseTemplate = null;
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
    const forceException = qa('[name="material_choice"][data-force-exception]:checked', form).length > 0;
    const forceReason = String(form.elements.force_exception_reason?.value || '').trim();
    if (forceException && !forceReason) {
      notify('请填写强制添加说明', '不适配物料需要明确原因，才会作为待审批例外加入。');
      return;
    }
    const button = event.submitter || q('button[type="submit"]', form);
    if (button) button.disabled = true;
    try {
      const result = await post('add_options', { group_id: selectedGroup().id, material_ids: ids, force_exception_reason: forceReason });
      closeModal(modal);
      await refreshWorkspace();
      state.tab = 'options';
      renderOptionPanel();
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
      if (operator === 'in' || operator === 'not_in' || operator === 'between') expected = raw.split(',').map(value => value.trim()).filter(Boolean).map(value => Number.isFinite(Number(value)) ? Number(value) : value);
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
        state.tab = 'options';
        renderOptionPanel();
        const followup = integer(result.incompatible)
          ? `；有 ${integer(result.incompatible)} 个已有电源不再适配，请处理后再审批`
          : integer(result.needs_review) ? `；有 ${integer(result.needs_review)} 个电源资料不足，需要审批确认` : '';
        notify('电源关键范围已保存', `筛选结果已显示在中间区域；请选择需要加入的候选电源${followup}。`);
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
        state.tab = 'options';
        renderOptionPanel();
        const followup = integer(result.incompatible)
          ? `；有 ${integer(result.incompatible)} 个已有选项明确不适配，请更换后再审批`
          : integer(result.needs_review) ? `；有 ${integer(result.needs_review)} 个选项资料不足，需要审批确认` : '';
        notify('关键范围已保存', `筛选结果已显示在中间区域；请选择需要加入的候选物料${followup}。`);
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
  restoreDrawerWidth();
  bindDrawerResize();
  setupWorkspaceResizers();
  render();
  if (hasCandidateDiscoveryRules(selectedGroup())) {
    loadCandidateDiscovery().catch(error => notify('候选物料暂未加载', error.message));
  }
})();
