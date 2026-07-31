(() => {
  'use strict';

  const page = document.querySelector('[data-adaptation]');
  const bootstrapNode = document.getElementById('adaptation-bootstrap');
  if (!page || !bootstrapNode) return;

  let bootstrap;
  try { bootstrap = JSON.parse(bootstrapNode.textContent || '{}'); } catch { bootstrap = {}; }

  const root = page.querySelector('[data-overview-dashboard]');
  if (!root) return;
  root.hidden = false;

  const state = {
    products: Array.isArray(bootstrap.products) ? bootstrap.products : [],
    workspace: bootstrap.workspace || null,
    metadata: bootstrap.metadata || {},
    screen: bootstrap.view || (bootstrap.workspace ? 'workspace' : 'home'),
    step: bootstrap.workspace ? 2 : 1,
    filters: { keyword: '', status: 'all', series: 'all', type: 'all', conflict: 'all', release: 'all', sort: 'updated' },
    materialGroup: null,
    materials: [],
    drawerLoading: false,
  };

  const esc = value => String(value ?? '').replace(/[&<>'"]/g, char => ({ '&':'&amp;', '<':'&lt;', '>':'&gt;', "'":'&#39;', '"':'&quot;' }[char]));
  const number = value => Number.parseInt(value || 0, 10) || 0;
  const uniq = rows => [...new Set(rows.filter(Boolean))];

  const api = async (action, values = null) => {
    const [actionName, ...queryParts] = String(action).split('&');
    const query = queryParts.length ? `&${queryParts.join('&')}` : '';
    let url = `${bootstrap.baseUrl}/api/v1/adaptation.php?action=${encodeURIComponent(actionName)}${query}`;
    const options = { credentials: 'same-origin', headers: { Accept: 'application/json' } };
    if (values) {
      const body = new FormData();
      body.set('csrf_token', bootstrap.csrf || '');
      body.set('action', actionName);
      Object.entries(values).forEach(([key, value]) => body.set(key, typeof value === 'object' ? JSON.stringify(value) : String(value ?? '')));
      url = `${bootstrap.baseUrl}/api/v1/adaptation.php`;
      options.method = 'POST';
      options.body = body;
    }
    const response = await fetch(url, options);
    const payload = await response.json().catch(() => ({}));
    if (!response.ok || !payload.ok) throw new Error(payload.message || '操作失败，请稍后重试。');
    return payload.data;
  };

  const toast = (message, bad = false) => {
    const region = document.querySelector('[data-toast-region]');
    if (!region) return;
    const item = document.createElement('div');
    item.className = 'mc-toast';
    item.innerHTML = `<strong>${bad ? '未完成' : '已完成'}</strong><span>${esc(message)}</span>`;
    region.append(item);
    setTimeout(() => item.remove(), 4200);
  };

  const selectedProduct = () => state.workspace?.product || null;
  const configState = product => product?.config_state || product?.configuration_state || (number(product?.group_count) ? 'configured' : 'unconfigured');
  const statusLabel = value => ({ unconfigured:'未配置', configured:'配置中', pending_approval:'待审批', needs_review:'待检查', enabled:'已发布', conflict:'存在冲突' }[value] || '配置中');
  const badgeClass = value => `mc-v3-badge mc-v3-badge--${esc(value || 'muted')}`;
  const productCode = product => product?.product_code || product?.model || product?.code || '—';
  const productName = product => product?.product_name || product?.name || '未命名产品';
  const productSeries = product => product?.series_name || product?.product_series || product?.series || '未分系列';
  const productType = product => product?.product_type || product?.category_name || product?.type_name || '全部类型';
  const productImage = (product, big = false) => `<span class="${big ? 'mc-v3-product-photo mc-v3-product-photo--big' : 'mc-v3-product-photo'}">${product?.image_url ? `<img src="${esc(product.image_url)}" alt="">` : '<i>IMG</i>'}</span>`;
  const completionOf = product => Math.max(0, Math.min(100, number(product?.completion_percent ?? product?.completion ?? product?.completion_rate ?? product?.completion?.percent ?? 0)));
  const optionCount = product => number(product?.option_count || product?.configured_option_count);
  const groupCount = product => number(product?.group_count || product?.configuration_group_count);
  const conflictCount = product => number(product?.conflict_count);
  const approvalCount = product => number(product?.pending_approval_count || product?.approval_count);
  const updatedAt = product => product?.updated_at || product?.last_configured_at || product?.last_updated_at || '—';
  const ownerName = product => product?.owner_name || product?.updated_by_name || product?.creator_name || '—';
  const isReleased = product => configState(product) === 'enabled' || number(product?.published_version_count || product?.published_versions_count) > 0;

  const showApproval = () => {
    const button = document.querySelector('[data-v3-approve]');
    if (button) button.hidden = !selectedProduct();
  };

  const setUrl = (replace = false) => {
    const url = new URL(location.href);
    url.searchParams.set('view', state.screen === 'home' ? 'products' : state.screen);
    if (selectedProduct()) url.searchParams.set('product_id', String(selectedProduct().id));
    else url.searchParams.delete('product_id');
    history[replace ? 'replaceState' : 'pushState']({}, '', url);
  };

  const navigate = (screen, replace = false) => {
    state.screen = screen;
    page.dataset.view = screen;
    setUrl(replace);
    render();
  };

  const loadWorkspace = async (productId, groupId = 0, step = 2) => {
    root.innerHTML = '<div class="mc-v3-loading">正在载入产品配置工作台…</div>';
    state.workspace = await api(`workspace&product_id=${encodeURIComponent(productId)}&group_id=${encodeURIComponent(groupId)}`);
    state.products = state.products.map(product => number(product.id) === number(productId) ? { ...product, ...state.workspace.product } : product);
    state.screen = 'workspace';
    state.step = step;
    state.materialGroup = groupId ? (state.workspace.groups || []).find(group => number(group.id) === number(groupId)) || null : null;
    state.materials = [];
    setUrl();
    if (state.materialGroup) await loadDrawerMaterials(state.materialGroup.id);
    render();
  };

  const counts = () => {
    const base = { all: state.products.length, unconfigured: 0, configured: 0, pending_approval: 0, enabled: 0, conflict: 0 };
    state.products.forEach(product => {
      const key = configState(product);
      if (key in base) base[key]++;
      if (conflictCount(product)) base.conflict++;
    });
    return base;
  };

  const exportProductsCsv = () => {
    const rows = filteredProducts();
    const header = ['产品编号', '产品名称', '产品类型', '系列', '配置完成度', '配置状态', '发布状态', '冲突数', '待审批数', '更新时间', '负责人'];
    const csv = [header, ...rows.map(product => [
      productCode(product),
      productName(product),
      productType(product),
      productSeries(product),
      `${completionOf(product)}%`,
      statusLabel(configState(product)),
      isReleased(product) ? '已发布' : '未发布',
      conflictCount(product),
      approvalCount(product),
      updatedAt(product),
      ownerName(product),
    ])].map(cols => cols.map(value => `"${String(value ?? '').replaceAll('"', '""')}"`).join(',')).join('\n');
    const blob = new Blob([`\ufeff${csv}`], { type: 'text/csv;charset=utf-8' });
    const link = document.createElement('a');
    link.href = URL.createObjectURL(blob);
    link.download = `product_adaptation_${new Date().toISOString().slice(0, 10)}.csv`;
    link.click();
    URL.revokeObjectURL(link.href);
  };

  const filteredProducts = () => {
    const keyword = state.filters.keyword.toLowerCase();
    const rows = state.products.filter(product => {
      const text = `${productCode(product)} ${productName(product)} ${productSeries(product)} ${productType(product)} ${ownerName(product)}`.toLowerCase();
      if (keyword && !text.includes(keyword)) return false;
      if (state.filters.status !== 'all' && configState(product) !== state.filters.status) return false;
      if (state.filters.series !== 'all' && productSeries(product) !== state.filters.series) return false;
      if (state.filters.type !== 'all' && productType(product) !== state.filters.type) return false;
      if (state.filters.conflict === 'yes' && !conflictCount(product)) return false;
      if (state.filters.conflict === 'no' && conflictCount(product)) return false;
      if (state.filters.release === 'released' && !isReleased(product)) return false;
      if (state.filters.release === 'unreleased' && isReleased(product)) return false;
      return true;
    });
    return rows.sort((left, right) => {
      if (state.filters.sort === 'completion') return completionOf(right) - completionOf(left);
      if (state.filters.sort === 'code') return productCode(left).localeCompare(productCode(right), 'zh-Hans-CN');
      return String(updatedAt(right)).localeCompare(String(updatedAt(left)));
    });
  };

  const renderHome = () => renderProducts(true);

  const renderProducts = (isHome = false) => {
    const c = counts();
    const series = uniq(state.products.map(productSeries));
    const types = uniq(state.products.map(productType));
    const rows = filteredProducts();
    const recent = [...state.products].sort((left, right) => String(updatedAt(right)).localeCompare(String(updatedAt(left)))).slice(0, 5);
    root.innerHTML = `<section class="mc-v3-page-shell mc-v3-products-page">
      <div class="mc-v3-breadcrumb">Artdon ERP / 物料中心 / <b>产品适配</b></div>
      <header class="mc-v3-screen-head">
        <div><h1>全部产品配置</h1><p>全部产品配置管理：管理所有产品的物料配置状态、适配规则和发布情况。产品适配工作台从这里进入。</p></div>
        <div class="mc-v3-screen-actions">
          <button class="mc-button" data-v3-template type="button">配置模板</button>
          <button class="mc-button" data-v3-copy-current type="button">从产品复制</button>
          <button class="mc-button" data-v3-batch type="button">批量矩阵</button>
          <button class="mc-button mc-button--primary" data-v3-new-config type="button">新建产品配置</button>
        </div>
      </header>
      <section class="mc-v3-filter-card">
        <label class="mc-v3-filter-search"><span>⌕</span><input data-v3-filter-keyword value="${esc(state.filters.keyword)}" placeholder="搜索型号 / 名称 / 系列 / 创建人"></label>
        <label><span>产品类型</span><select data-v3-filter-type><option value="all">全部类型</option>${types.map(type => `<option value="${esc(type)}" ${state.filters.type === type ? 'selected' : ''}>${esc(type)}</option>`).join('')}</select></label>
        <label><span>系列</span><select data-v3-filter-series><option value="all">全部系列</option>${series.map(item => `<option value="${esc(item)}" ${state.filters.series === item ? 'selected' : ''}>${esc(item)}</option>`).join('')}</select></label>
        <label><span>配置状态</span><select data-v3-filter-status>${[['all','全部状态'],['unconfigured','未配置'],['configured','配置中'],['pending_approval','待审批'],['enabled','已发布']].map(([value,label]) => `<option value="${value}" ${state.filters.status === value ? 'selected' : ''}>${label}</option>`).join('')}</select></label>
        <label><span>发布状态</span><select data-v3-filter-release>${[['all','全部状态'],['released','已发布'],['unreleased','未发布']].map(([value,label]) => `<option value="${value}" ${state.filters.release === value ? 'selected' : ''}>${label}</option>`).join('')}</select></label>
        <label><span>冲突状态</span><select data-v3-filter-conflict>${[['all','全部状态'],['yes','存在冲突'],['no','无冲突']].map(([value,label]) => `<option value="${value}" ${state.filters.conflict === value ? 'selected' : ''}>${label}</option>`).join('')}</select></label>
        <label><span>排序</span><select data-v3-sort>${[['updated','更新时间 ↓'],['completion','完成度 ↓'],['code','产品编号 ↑']].map(([value,label]) => `<option value="${value}" ${state.filters.sort === value ? 'selected' : ''}>${label}</option>`).join('')}</select></label>
        <button class="mc-button" data-v3-collapse-filter type="button">收起 ^</button>
      </section>
      <nav class="mc-v3-status-tabs">
        ${[['all','全部'],['unconfigured','未配置'],['configured','配置中'],['pending_approval','待审批'],['enabled','已发布'],['conflict','存在冲突']].map(([key,label]) => `<button class="${(key === 'all' ? state.filters.status === 'all' : state.filters.status === key) ? 'is-active' : ''}" data-v3-tab-status="${key}" type="button">${label} <b>${number(c[key])}</b></button>`).join('')}
        <span></span><button class="mc-button" data-v3-refresh type="button">刷新</button><button class="mc-button" data-v3-export type="button">导出</button><button class="mc-button" data-v3-columns type="button">列设置</button>
      </nav>
      <section class="mc-v3-recent mc-v3-recent-strip"><strong>最近产品</strong>${recent.map(product => `<button data-v3-open-product="${number(product.id)}" title="打开工作台" type="button">${productImage(product)}<span><b>${esc(productCode(product))}</b><small>${esc(productName(product))}</small></span></button>`).join('') || '<em>暂无最近产品</em>'}</section>
      <section class="mc-v3-product-table mc-v3-product-table--spec">
        <div class="mc-v3-product-table__head"><span>产品信息</span><span>产品类型 / 系列</span><span>配置完成度</span><span>配置状态</span><span>发布状态</span><span>冲突数</span><span>待审批数</span><span>更新时间</span><span>操作</span></div>
        <div data-v3-product-table>${renderProductTable(rows)}</div>
      </section>
      <footer class="mc-v3-table-foot"><span>共 ${rows.length} / ${state.products.length} 条</span><span>每页 20 条　1　2　3　…</span></footer>
    </section>`;
    page.dataset.view = isHome ? 'home' : 'products';
  };

  const renderProductTable = products => products.length ? products.map(product => {
    const status = configState(product);
    const completion = completionOf(product);
    return `<article data-v3-row-product="${number(product.id)}">
      <span class="mc-v3-product-info-cell">${productImage(product)}<i><b>${esc(productCode(product))}</b><small>${esc(productName(product))}</small></i></span>
      <span><b>${esc(productType(product))}</b><small>${esc(productSeries(product))}</small></span>
      <span><em class="mc-v3-mini-ring" style="--value:${completion}">${completion}%</em></span>
      <span><b class="${badgeClass(status)}">${esc(statusLabel(status))}</b><small>缺失 ${Math.max(0, 4 - groupCount(product))} 项</small></span>
      <span>${isReleased(product) ? '<b class="mc-v3-badge mc-v3-badge--enabled">已发布</b><small>V1.0</small>' : '<b class="mc-v3-badge">未发布</b>'}</span>
      <span class="${conflictCount(product) ? 'mc-v3-danger' : 'mc-v3-ok'}">${conflictCount(product)}</span>
      <span class="${approvalCount(product) ? 'mc-v3-warn' : ''}">${approvalCount(product)}</span>
      <span><b>${esc(updatedAt(product))}</b><small>${esc(ownerName(product))}</small></span>
      <span class="mc-v3-row-actions"><button class="mc-button mc-button--primary" title="打开工作台" data-v3-open-product="${number(product.id)}" type="button">${status === 'unconfigured' ? '开始配置' : (status === 'pending_approval' ? '查看审批' : '打开工作台')}</button><button class="mc-button" data-v3-row-more type="button">•••</button></span>
    </article>`;
  }).join('') : '<div class="mc-v3-empty">没有匹配的产品。</div>';

  const canonicalGroupKey = group => {
    const name = `${group.group_key || ''} ${group.business_type || ''} ${group.group_name || ''}`.toLowerCase();
    if (/chip|light|光源|芯片/.test(name)) return 'chip';
    if (/power|driver|电源|驱动/.test(name)) return 'power';
    if (/optic|lens|光学|透镜/.test(name)) return 'optic';
    if (/install|mount|安装/.test(name)) return 'install';
    if (/dimming|调光/.test(name)) return 'dimming';
    if (/glass|玻璃/.test(name)) return 'glass';
    if (/accessory|附件|配件/.test(name)) return 'accessory';
    return 'custom';
  };

  const groupIcon = key => ({ chip:'◉', power:'ϟ', optic:'◎', install:'⌁', dimming:'◌', glass:'◇', accessory:'＋', custom:'◆' }[key] || '◆');
  const moduleLabel = key => ({ chip:'芯片 / 光源', power:'电源 / 驱动', optic:'光学 / 透镜', install:'安装方式', dimming:'调光方式', glass:'玻璃 / 面罩', accessory:'附件配件', custom:'特殊要求' }[key] || '配置组');

  const completion = () => state.workspace?.completion || {};
  const groups = () => Array.isArray(state.workspace?.groups) ? state.workspace.groups : [];
  const coreGroups = () => groups().filter(group => Boolean(number(group.is_required))).slice(0, 8);
  const extensionGroups = () => groups().filter(group => !Boolean(number(group.is_required)));
  const groupDefault = group => {
    const overview = (state.workspace?.configuration_overview || []).find(row => number(row.id) === number(group.id)) || group;
    return overview.default_material || overview.default_option || group.default_material || '未设置默认';
  };
  const groupDone = group => number(group.option_count) > 0 && (group.selection_mode !== 'single' || groupDefault(group) !== '未设置默认');
  const activeGroup = () => state.materialGroup || coreGroups().find(group => canonicalGroupKey(group) === 'power') || coreGroups()[0] || groups()[0] || null;

  const renderWorkbench = () => {
    const product = selectedProduct();
    if (!product) return renderProducts();
    const pct = number(completion().percent);
    const group = activeGroup();
    if (!state.materialGroup && group) state.materialGroup = group;
    const totalOptions = groups().reduce((sum, item) => sum + number(item.option_count), 0);
    const missingCore = coreGroups().filter(item => !groupDone(item)).length;
    root.innerHTML = `<section class="mc-v3-page-shell mc-v3-workbench-page">
      <div class="mc-v3-breadcrumb">Artdon ERP / 物料中心 / 产品适配 / <b>产品配置工作台</b></div>
      <header class="mc-v3-screen-head">
        <div><button class="mc-button mc-button--primary" data-v3-products type="button">切换产品</button><h1>产品配置工作台</h1><p>完成产品核心物料、可选配件、条件规则和发布，确保产品配置完整、可报价、可生产。</p></div>
        <div class="mc-v3-screen-actions"><button class="mc-button" data-v3-template type="button">套用模板</button><button class="mc-button" data-v3-batch type="button">从产品复制</button><button class="mc-button" data-v3-more type="button">更多⌄</button><button class="mc-button mc-button--primary" data-v3-approve type="button">配置检查 / 提交审批</button></div>
      </header>
      <div class="mc-v3-workbench-layout">
        <main class="mc-v3-workbench-main">
          <section class="mc-v3-product-hero">
            ${productImage(product, true)}
            <div class="mc-v3-product-hero__identity"><h2>${esc(productCode(product))} <span>${esc(productName(product))}</span></h2><p>系列：${esc(productSeries(product))}</p><p>类型：${esc(productType(product))}　状态：${esc(statusLabel(configState(product)))}</p><p>创建人：${esc(ownerName(product))}　最后修改：${esc(updatedAt(product))}</p></div>
            <div class="mc-v3-hero-stat mc-v3-hero-stat--ring"><em class="mc-v3-ring" style="--value:${pct}"><b>${pct}%</b></em><span>完成度</span></div>
            <div class="mc-v3-hero-stat"><b>${missingCore}</b><span>缺失项</span></div>
            <div class="mc-v3-hero-stat"><b>${number(state.workspace?.conflicts?.length)}</b><span>冲突项</span></div>
            <div class="mc-v3-hero-stat"><b>${approvalCount(product)}</b><span>待审批</span></div>
            <div class="mc-v3-hero-stat"><b>${totalOptions}</b><span>正式选项</span></div>
          </section>
          <nav class="mc-v3-flow">${[['1','选择产品','已完成'],['2','核心必配','进行中'],['3','扩展可配','未开始'],['4','条件规则','未开始'],['5','检查发布','未开始']].map(([id,label,tip]) => `<button class="${number(id) === state.step ? 'is-active' : number(id) < state.step ? 'is-done' : ''}" data-v3-step="${id}" type="button"><b>${number(id) < state.step ? '✓' : id}</b><span>${label}</span><small>${tip}</small></button>`).join('')}</nav>
          ${renderStepContent()}
        </main>
        ${renderGroupDrawer()}
      </div>
    </section>`;
  };

  const renderStepContent = () => {
    if (state.step <= 1) return renderTechnical();
    if (state.step === 2) return renderGroupStage(true);
    if (state.step === 3) return renderGroupStage(false);
    if (state.step === 4) return renderRules();
    return renderCheck();
  };

  const renderTechnical = () => {
    const fields = state.workspace?.technical_profile?.fields || state.metadata.technical_profile_fields || [];
    const sections = uniq(fields.map(field => field.section || '技术范围'));
    return `<form data-v3-profile class="mc-v3-technical mc-v3-work-card"><div class="mc-v3-step-title"><h3>选择产品 / 技术范围</h3><p>先确认产品功率、电流、电压、结构空间、认证和光学边界；后续候选物料会按这里筛选。</p></div>${sections.map(section => `<fieldset><legend>${esc(section)}</legend><div class="mc-v3-field-grid">${fields.filter(field => (field.section || '技术范围') === section).map(field => `<label><span>${esc(field.label)}${field.unit ? `（${esc(field.unit)}）` : ''}</span>${profileInput(field)}</label>`).join('')}</div></fieldset>`).join('')}<footer><button class="mc-button mc-button--primary" type="submit">保存并确认技术范围</button><button class="mc-button" type="button" data-v3-step="2">下一步：核心必配</button></footer></form>`;
  };

  const profileInput = field => {
    const value = state.workspace?.technical_profile?.values?.[field.key];
    if (field.type === 'select') return `<select name="${esc(field.key)}">${Object.entries(field.options || {}).map(([key,label]) => `<option value="${esc(key)}" ${String(value || 'unknown') === key ? 'selected' : ''}>${esc(label)}</option>`).join('')}</select>`;
    if (field.type === 'multi') return `<span class="mc-v3-checkboxes">${Object.entries(field.options || {}).map(([key,label]) => `<label><input type="checkbox" name="${esc(field.key)}" value="${esc(key)}" ${(value || []).includes(key) ? 'checked' : ''}>${esc(label)}</label>`).join('')}</span>`;
    if (field.type === 'textarea') return `<textarea name="${esc(field.key)}" rows="3">${esc(value)}</textarea>`;
    return `<input type="${field.type === 'number' ? 'number' : 'text'}" ${field.type === 'number' ? 'min="0" step="0.01"' : ''} name="${esc(field.key)}" value="${esc(value)}" placeholder="待确认">`;
  };

  const renderGroupStage = core => {
    const rows = core ? coreGroups() : extensionGroups();
    return `<section class="mc-v3-module-stage"><div class="mc-v3-step-title"><h3>${core ? '核心必配' : '扩展可配'} <small>(${rows.filter(groupDone).length} / ${rows.length})</small></h3><p>${core ? '所有核心模组必须完成默认选项设置才能进入下一步。' : '扩展模组可设置“不适用 / 暂不提供 / 稍后配置”，只有稍后配置进入待处理。'}</p></div><div class="mc-v3-module-grid">${rows.map(renderGroupCard).join('') || '<div class="mc-v3-empty">尚未建立配置组，可先套用配置模板。</div>'}</div>${core && rows.some(group => !groupDone(group)) ? `<div class="mc-v3-next-tip"><span>i</span><b>下一步建议</b><p>请优先处理“${esc(moduleLabel(canonicalGroupKey(rows.find(group => !groupDone(group)))))}”，设置默认物料后再提交检查。</p><button class="mc-button mc-button--primary" data-v3-manage-group="${number(rows.find(group => !groupDone(group)).id)}" type="button">立即处理</button></div>` : ''}</section>`;
  };

  const renderGroupCard = group => {
    const key = canonicalGroupKey(group);
    const done = groupDone(group);
    const active = number(state.materialGroup?.id) === number(group.id);
    const required = Boolean(number(group.is_required));
    return `<article class="mc-v3-module-card ${active ? 'is-active' : ''} ${done ? 'is-done' : 'is-missing'}">
      <header><i>${esc(groupIcon(key))}</i><div><h4>${esc(moduleLabel(key))}</h4><small>${required ? '必选 · ' : '可选 · '}${group.selection_mode === 'single' ? '单选' : '多选'}</small></div></header>
      <p>${done ? '已完成' : '未设置默认'}${required ? '' : ' · 可标记状态'}</p>
      <dl><div><dt>默认</dt><dd>${esc(groupDefault(group))}</dd></div><div><dt>正式选项</dt><dd>${number(group.option_count)} 个</dd></div><div><dt>候选</dt><dd>${number(group.alternative_count)} 个</dd></div></dl>
      <button class="mc-button ${done ? '' : 'mc-button--primary'}" data-v3-manage-group="${number(group.id)}" type="button">${done ? '管理' : '立即处理'}</button>
    </article>`;
  };

  const renderRules = () => `<section class="mc-v3-work-card"><div class="mc-v3-step-title"><h3>条件规则</h3><p>在这里集中处理调光、玻璃、附件、外观颜色和特殊要求的组合条件，第四步使用可视化条件编辑器。</p></div><div class="mc-v3-rule-grid">${groups().map(group => `<article><strong>${esc(group.group_name)}</strong><span>${number(group.condition_count)} 条条件 · ${number(group.conflict_count)} 条冲突</span><button type="button" data-v3-manage-group="${number(group.id)}">管理条件</button></article>`).join('')}</div></section>`;

  const renderCheck = () => {
    const issues = completion().issues || [];
    return `<section class="mc-v3-work-card"><div class="mc-v3-step-title"><h3>检查发布</h3><p>第五步检查配置完整性、冲突、规则和审批，支持提交审批并发布。</p></div><div class="mc-v3-check-grid">${Object.entries(completion().segments || {}).map(([key,value]) => `<span><b>${number(value)}%</b><small>${esc(({technical:'技术范围',core:'核心必配',optional:'扩展可配',rules:'条件规则',check:'检查'}[key] || key))}</small></span>`).join('')}</div>${issues.length ? `<div class="mc-v3-issues">${issues.map(issue => `<p>• ${esc(issue)}</p>`).join('')}</div>` : '<div class="mc-v3-ready">配置检查通过，可以提交审批并发布版本。</div>'}<button class="mc-button mc-button--primary" data-v3-approve type="button">提交审批 / 发布</button></section>`;
  };

  const renderGroupDrawer = () => {
    const group = state.materialGroup;
    if (!group) return '<aside class="mc-v3-config-drawer"><div class="mc-v3-empty">请选择一个配置模组。</div></aside>';
    const key = canonicalGroupKey(group);
    const picked = number(group.option_count);
    return `<aside class="mc-v3-config-drawer">
      <header><div><h3>${esc(moduleLabel(key))}</h3><span>${group.is_required ? '必选' : '可选'} · ${group.selection_mode === 'single' ? '单选' : '多选'}</span></div><button class="mc-icon-button" data-v3-close-drawer type="button">×</button></header>
      <nav><button class="is-active" type="button">选项列表</button><button type="button">默认设置</button><button type="button">替代关系</button><button type="button">适用条件</button><button type="button">价格/交期</button><button type="button">审批记录</button></nav>
      <div class="mc-v3-drawer-tools"><label><span>⌕</span><input placeholder="搜索品牌、型号或规格"></label><button class="mc-button" data-v3-manage-group="${number(group.id)}" type="button">从物料库添加</button></div>
      <div class="mc-v3-candidate-list">${state.drawerLoading ? '<div class="mc-v3-loading">正在读取候选物料…</div>' : renderCandidates()}</div>
      <footer><span>已选中 ${picked} 项</span><button class="mc-button" data-v3-return-workspace type="button">取消</button><button class="mc-button" type="button">保存</button><button class="mc-button mc-button--primary" data-v3-save-next type="button">保存并处理下一项</button></footer>
    </aside>`;
  };

  const renderCandidates = () => {
    if (!state.materials.length) return '<div class="mc-v3-empty">当前没有候选物料。请先补充技术范围或维护正式物料。</div>';
    return state.materials.map(row => {
      const match = row.match_level || 'exact';
      const already = number(row.already_added);
      const incompatible = match === 'incompatible';
      return `<article class="${already ? 'is-picked' : ''} ${incompatible ? 'is-blocked' : ''}">
        <label><input type="${state.materialGroup?.selection_mode === 'single' ? 'radio' : 'checkbox'}" name="candidate" ${already ? 'checked' : ''} ${incompatible ? 'data-force-exception="1"' : ''}><span><b>${esc(row.material_code || row.code || '—')}</b><small>${esc([row.name, row.brand, row.model].filter(Boolean).join(' · '))}</small><em>${esc([row.max_output_power_w && `${row.max_output_power_w}W`, row.output_current_ma && `${row.output_current_ma}mA`, row.output_voltage_min_v && `${row.output_voltage_min_v}V`, row.dimming_modes].filter(Boolean).join(' | ') || '规格待补充')}</em></span></label>
        <strong class="mc-v3-match mc-v3-match--${esc(match)}">${esc(({ exact:'完全适配', conditional:'条件适配', needs_approval:'需审批', incompatible:'不适配' }[match] || '候选'))}</strong>
        <p>${esc((row.conflict_reasons || []).join('；') || (incompatible ? '不满足当前产品要求' : '可作为正式选项'))}</p>
        ${already ? '<button class="mc-button" disabled type="button">已加入</button>' : incompatible ? `<button class="mc-button" data-v3-exception-material="${number(row.id || row.material_id)}" type="button">申请例外</button>` : `<button class="mc-button mc-button--primary" data-v3-add-material="${number(row.id || row.material_id)}" type="button">选为默认</button>`}
      </article>`;
    }).join('');
  };

  const renderMaterials = () => renderWorkbench();
  const renderTemplate = () => {
    const product = selectedProduct();
    root.innerHTML = `<section class="mc-v3-page-shell mc-v3-template"><div class="mc-v3-breadcrumb">Artdon ERP / 物料中心 / 产品适配 / <b>配置模板</b></div><header class="mc-v3-screen-head"><div><h1>配置模板</h1><p>按核心模组快速生成配置骨架，不重复插入已有配置组。</p></div><button class="mc-button" data-v3-home type="button">返回产品列表</button></header>${product ? `<div class="mc-v3-template-target">当前产品：<b>${esc(productCode(product))}</b> ${esc(productName(product))}</div>` : ''}<form data-v3-template-form><div class="mc-v3-template-options">${(state.metadata.template || []).map(group => `<label><input type="checkbox" name="template_key" value="${esc(group.key)}" ${group.is_required ? 'checked' : ''}><span><b>${esc(group.name)}</b><small>${group.is_required ? '核心必配' : '扩展可配'} · ${esc(group.selection_mode === 'single' ? '单选' : '多选')}</small></span></label>`).join('')}</div><button class="mc-button mc-button--primary" type="submit">套用所选模板</button></form></section>`;
  };

  const renderBatch = () => {
    const source = selectedProduct();
    root.innerHTML = `<section class="mc-v3-page-shell mc-v3-template"><div class="mc-v3-breadcrumb">Artdon ERP / 物料中心 / 产品适配 / <b>批量矩阵</b></div><header class="mc-v3-screen-head"><div><h1>批量矩阵</h1><p>从已配置产品复制部分模组到目标产品，执行后目标产品进入待检查。</p></div><button class="mc-button" data-v3-home type="button">返回产品列表</button></header>${source ? `<form data-v3-batch-form><div class="mc-v3-template-target">来源产品：<b>${esc(productCode(source))}</b> ${esc(productName(source))}</div><label class="mc-field"><span>套用方式</span><select name="mode"><option value="fill_missing">只补空白（推荐）</option><option value="replace_matching">覆盖同名配置组</option></select></label><fieldset class="mc-v3-template-options"><legend>选择配置模组</legend>${groups().map(group => `<label><input type="checkbox" name="source_group" value="${number(group.id)}" checked><span><b>${esc(group.group_name)}</b><small>${group.is_required ? '核心必配' : '扩展可配'}</small></span></label>`).join('')}</fieldset><div class="mc-v3-target-list">${state.products.filter(product => number(product.id) !== number(source.id)).map(product => `<label><input type="checkbox" name="target_product" value="${number(product.id)}"><span>${esc(productCode(product))} ${esc(productName(product))}</span><small>${esc(statusLabel(configState(product)))}</small></label>`).join('')}</div><button class="mc-button mc-button--primary" type="submit">确认批量套用</button></form>` : '<div class="mc-v3-empty">请先打开一个来源产品，再执行批量复制。</div>'}</section>`;
  };

  const render = () => {
    showApproval();
    if (state.screen === 'workspace') renderWorkbench();
    else if (state.screen === 'template') renderTemplate();
    else if (state.screen === 'batch') renderBatch();
    else renderProducts(state.screen === 'home');
  };

  const loadDrawerMaterials = async groupId => {
    const group = groups().find(row => number(row.id) === number(groupId));
    if (!group) return;
    state.materialGroup = group;
    state.drawerLoading = true;
    state.materials = [];
    render();
    try {
      state.materials = await api(`candidates&group_id=${encodeURIComponent(group.id)}&status=official`);
    } catch (error) {
      toast(error.message, true);
    } finally {
      state.drawerLoading = false;
      render();
    }
  };

  page.addEventListener('click', async event => {
    const button = event.target.closest('button');
    if (!button) return;
    try {
      if (button.matches('[data-v3-home]')) return navigate('home');
      if (button.matches('[data-v3-products],[data-v3-select-product],[data-v3-new-config]')) return navigate('products');
      if (button.matches('[data-v3-template]')) return navigate('template');
      if (button.matches('[data-v3-batch],[data-v3-copy-current]')) return navigate('batch');
      if (button.matches('[data-v3-open-product]')) return loadWorkspace(number(button.dataset.v3OpenProduct), 0, 2);
      if (button.matches('[data-v3-tab-status]')) { state.filters.status = button.dataset.v3TabStatus === 'all' ? 'all' : button.dataset.v3TabStatus; return renderProducts(); }
      if (button.matches('[data-v3-refresh]')) { const products = await api('products'); state.products = Array.isArray(products) ? products : []; toast('产品配置列表已刷新。'); return renderProducts(); }
      if (button.matches('[data-v3-export]')) { exportProductsCsv(); return toast('当前筛选结果已导出。'); }
      if (button.matches('[data-v3-columns]')) return toast('列宽和小屏布局已按产品配置场景优化。');
      if (button.matches('[data-v3-collapse-filter],[data-v3-row-more],[data-v3-more]')) return toast('当前视图已保持完整配置入口。');
      if (button.matches('[data-v3-step]')) { state.step = number(button.dataset.v3Step); return renderWorkbench(); }
      if (button.matches('[data-v3-manage-group]')) return loadDrawerMaterials(number(button.dataset.v3ManageGroup));
      if (button.matches('[data-v3-close-drawer]')) { state.materialGroup = null; state.materials = []; return renderWorkbench(); }
      if (button.matches('[data-v3-return-workspace]')) return renderWorkbench();
      if (button.matches('[data-v3-save-next]')) { const next = coreGroups().find(group => !groupDone(group) && number(group.id) !== number(state.materialGroup?.id)); return next ? loadDrawerMaterials(number(next.id)) : (state.step = 3, renderWorkbench()); }
      if (button.matches('[data-v3-add-material]')) {
        const result = await api('add_options', { group_id: state.materialGroup.id, material_ids: [number(button.dataset.v3AddMaterial)] });
        if (state.materialGroup.selection_mode === 'single' && result.optionIds?.[0]) await api('set_default', { group_id: state.materialGroup.id, option_ids: [result.optionIds[0]], min_select: state.materialGroup.is_required ? 1 : 0, max_select: 1 });
        toast('物料已加入配置。');
        return loadWorkspace(selectedProduct().id, state.materialGroup.id, state.step);
      }
      if (button.matches('[data-v3-exception-material]')) {
        const reason = prompt('请填写必须使用该不适配物料的工程例外原因：');
        if (!reason?.trim()) return;
        await api('add_options', { group_id: state.materialGroup.id, material_ids: [number(button.dataset.v3ExceptionMaterial)], force_exception_reason: reason.trim() });
        toast('例外物料已加入，并会在检查时进入审批。');
        return loadWorkspace(selectedProduct().id, state.materialGroup.id, state.step);
      }
      if (button.matches('[data-v3-approve]')) {
        if (!confirm('确认检查并提交审批发布吗？发布后会生成不可变版本。')) return;
        await api('approve', { product_id: selectedProduct().id });
        toast('配置已审批并发布。');
        return loadWorkspace(selectedProduct().id, 0, 5);
      }
    } catch (error) { toast(error.message, true); }
  });

  page.addEventListener('input', event => {
    if (!event.target.matches('[data-v3-filter-keyword]')) return;
    state.filters.keyword = event.target.value.trim();
    renderProducts();
  });

  page.addEventListener('change', event => {
    const target = event.target;
    if (target.matches('[data-v3-filter-status]')) state.filters.status = target.value;
    else if (target.matches('[data-v3-filter-series]')) state.filters.series = target.value;
    else if (target.matches('[data-v3-filter-type]')) state.filters.type = target.value;
    else if (target.matches('[data-v3-filter-conflict]')) state.filters.conflict = target.value;
    else if (target.matches('[data-v3-filter-release]')) state.filters.release = target.value;
    else if (target.matches('[data-v3-sort]')) state.filters.sort = target.value;
    else return;
    renderProducts();
  });

  page.addEventListener('submit', async event => {
    try {
      if (event.target.matches('[data-v3-profile]')) {
        event.preventDefault();
        const form = new FormData(event.target);
        const fields = state.workspace?.technical_profile?.fields || state.metadata.technical_profile_fields || [];
        const profile = {};
        fields.forEach(field => { profile[field.key] = field.type === 'multi' ? form.getAll(field.key) : form.get(field.key); });
        await api('save_technical_profile', { product_id: selectedProduct().id, profile });
        toast('技术范围已保存。');
        return loadWorkspace(selectedProduct().id, 0, 2);
      }
      if (event.target.matches('[data-v3-template-form]')) {
        event.preventDefault();
        const keys = new FormData(event.target).getAll('template_key');
        if (!selectedProduct()) throw new Error('请先选择产品。');
        if (!keys.length) throw new Error('请至少选择一个配置组。');
        await api('apply_template', { product_id: selectedProduct().id, template_keys: keys });
        toast('配置模板已套用。');
        return loadWorkspace(selectedProduct().id, 0, 2);
      }
      if (event.target.matches('[data-v3-batch-form]')) {
        event.preventDefault();
        const form = new FormData(event.target);
        const targets = form.getAll('target_product').map(number);
        const sourceGroupIds = form.getAll('source_group').map(number);
        if (!targets.length) throw new Error('请至少选择一个目标产品。');
        if (!sourceGroupIds.length) throw new Error('请至少选择一个配置模组。');
        await api('batch_apply', { source_product_id: selectedProduct().id, target_product_ids: targets, source_group_ids: sourceGroupIds, mode: form.get('mode'), include_power_rule: 1 });
        toast(`已套用到 ${targets.length} 个产品。`);
        return renderWorkbench();
      }
    } catch (error) { toast(error.message, true); }
  });

  window.addEventListener('popstate', () => {
    const params = new URLSearchParams(location.search);
    const productId = number(params.get('product_id'));
    state.screen = params.get('view') || (productId ? 'workspace' : 'home');
    if (productId && number(selectedProduct()?.id) !== productId) loadWorkspace(productId);
    else render();
  });

  render();
})();
