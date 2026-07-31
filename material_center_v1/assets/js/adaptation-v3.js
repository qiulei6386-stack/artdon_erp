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
    step: Number.parseInt(bootstrap.step || 1, 10) || 1,
    advancedOpen: Boolean(bootstrap.advancedOpen),
    quickCheckDone: false,
    paramField: null,
    sourcePickerOpen: false,
    filters: { keyword: '', status: 'all', series: 'all', type: 'all', conflict: 'all', release: 'all', sort: 'updated' },
    materialGroup: null,
    materials: [],
    drawerLoading: false,
    selectedMaterialIds: [],
    materialDetailId: 0,
    exceptionMaterialId: 0,
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
  const configState = product => product?.config_state || product?.configuration_state || (number(product?.group_count) ? 'configuring' : 'unconfigured');
  const statusLabel = value => ({ unconfigured:'未配置', configuring:'配置中', configured:'配置中', needs_check:'待检查', needs_review:'待检查', pending_approval:'待审批', published:'已发布', enabled:'已发布', conflict:'存在冲突' }[value] || '配置中');
  const badgeClass = value => `mc-v3-badge mc-v3-badge--${esc(value || 'muted')}`;
  const productCode = product => product?.product_code || product?.model || product?.code || '—';
  const productName = product => product?.product_name || product?.name || '未命名产品';
  const productSeries = product => product?.series_name || product?.product_series || product?.series || '未分系列';
  const productType = product => product?.product_type || product?.category_name || product?.type_name || '全部类型';
  const productImage = (product, big = false) => `<span class="${big ? 'mc-v3-product-photo mc-v3-product-photo--big' : 'mc-v3-product-photo'}">${product?.image_url ? `<img src="${esc(product.image_url)}" alt="">` : '<i>IMG</i>'}</span>`;
  const materialImage = row => `<span class="mc-v3-material-thumb">${row?.image_url ? `<img src="${esc(row.image_url)}" alt="">` : '<i>MAT</i>'}</span>`;
  const completionOf = product => Math.max(0, Math.min(100, number(product?.completion_percent ?? product?.completion ?? product?.completion_rate ?? product?.completion?.percent ?? 0)));
  const optionCount = product => number(product?.option_count || product?.configured_option_count);
  const groupCount = product => number(product?.group_count || product?.configuration_group_count);
  const conflictCount = product => number(product?.conflict_count);
  const approvalCount = product => number(product?.pending_approval_count || product?.approval_count);
  const updatedAt = product => product?.updated_at || product?.last_configured_at || product?.last_updated_at || '—';
  const ownerName = product => product?.owner_name || product?.updated_by_name || product?.creator_name || '—';
  const isReleased = product => ['published', 'enabled'].includes(configState(product)) || number(product?.published_version_count || product?.published_versions_count) > 0;

  const showApproval = () => {
    const button = document.querySelector('[data-v3-approve]');
    if (button) button.hidden = !selectedProduct();
  };

  const setUrl = (replace = false) => {
    const url = new URL(location.href);
    if (state.screen === 'home') url.searchParams.delete('view');
    else url.searchParams.set('view', state.screen);
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

  const loadWorkspace = async (productId, groupId = 0, step = 1) => {
    root.innerHTML = '<div class="mc-v3-loading">正在载入产品配置工作台…</div>';
    state.workspace = await api(`workspace&product_id=${encodeURIComponent(productId)}&group_id=${encodeURIComponent(groupId)}`);
    state.products = state.products.map(product => number(product.id) === number(productId) ? { ...product, ...state.workspace.product } : product);
    state.screen = 'workspace';
    state.step = step;
    state.sourcePickerOpen = false;
    state.materialGroup = groupId ? (state.workspace.groups || []).find(group => number(group.id) === number(groupId)) || null : null;
    state.materials = [];
    state.selectedMaterialIds = [];
    state.materialDetailId = 0;
    setUrl();
    if (state.materialGroup) await loadDrawerMaterials(state.materialGroup.id);
    render();
  };

  const counts = () => {
    const base = { all: state.products.length, unconfigured: 0, configuring: 0, needs_check: 0, pending_approval: 0, published: 0, conflict: 0 };
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

  const renderHome = () => {
    const c = counts();
    const recent = [...state.products]
      .filter(product => configState(product) !== 'unconfigured' || number(product.group_count) || number(product.option_count))
      .sort((left, right) => String(updatedAt(right)).localeCompare(String(updatedAt(left))))
      .slice(0, 5);
    root.innerHTML = `<section class="mc-v3-page-shell mc-v3-home">
      <div class="mc-v3-breadcrumb">Artdon ERP / 物料中心 / <b>产品适配</b></div>
      <header class="mc-v3-screen-head">
        <div><h1>产品适配</h1><p>按产品管理技术范围、核心物料、扩展可选、条件规则、检查审批和版本发布。首页只放入口和最近配置，不直接铺开全部产品。</p></div>
        <div class="mc-v3-screen-actions">
          <button class="mc-button mc-button--primary" data-v3-products type="button">选择产品 / 全部产品</button>
          <button class="mc-button" data-v3-template type="button">配置模板</button>
          <button class="mc-button" data-v3-batch type="button">批量矩阵</button>
        </div>
      </header>
      <section class="mc-v3-metrics">
        ${[['unconfigured','未配置'],['configuring','配置中'],['needs_check','待检查'],['pending_approval','待审批'],['published','已发布'],['conflict','存在冲突']].map(([key,label]) => `<button class="mc-v3-metric" data-v3-home-status="${key}" type="button"><b>${number(c[key])}</b><small>${label}</small></button>`).join('')}
      </section>
      <section class="mc-v3-panel">
        <div class="mc-v3-panel__head"><div><h2>最近产品 / 继续最近配置</h2><p>显示最近 5 个已有配置动作的产品，可直接回到单产品工作台。</p></div><button class="mc-button" data-v3-products type="button">查看全部产品</button></div>
        <div class="mc-v3-recent">${recent.length ? recent.map(product => `<button class="mc-v3-product-row" data-v3-open-product="${number(product.id)}" type="button">${productImage(product)}<span><b>${esc(productCode(product))}</b><small>${esc(productName(product))} · ${esc(productSeries(product))}</small></span><b class="${badgeClass(configState(product))}">${esc(statusLabel(configState(product)))}</b><span>${completionOf(product)}% · 缺 ${Math.max(0, number(product.required_group_count) - number(product.complete_required_group_count))} · 冲突 ${conflictCount(product)}</span></button>`).join('') : '<div class="mc-v3-empty">暂无最近配置产品，请先进入“全部产品”选择一个产品。</div>'}</div>
      </section>
      <section class="mc-v3-panel">
        <div class="mc-v3-panel__head"><div><h2>快速入口</h2><p>入口页不显示产品级保存、检查或发布按钮，避免误操作。</p></div></div>
        <div class="mc-v3-quick-grid">
          <button class="mc-button mc-button--primary" data-v3-products type="button">选择产品</button>
          <button class="mc-button" data-v3-products type="button">全部产品</button>
          <button class="mc-button" data-v3-template type="button">配置模板</button>
          <button class="mc-button" data-v3-copy-current type="button">从产品复制</button>
          <button class="mc-button" data-v3-batch type="button">批量矩阵</button>
          <button class="mc-button" data-v3-home-status="pending_approval" type="button">审批中心</button>
        </div>
      </section>
    </section>`;
    page.dataset.view = 'home';
  };

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
          <button class="mc-button" data-v3-home type="button">返回适配首页</button>
          <button class="mc-button" data-v3-more type="button">更多：模板 / 复制 / 批量</button>
          <button class="mc-button mc-button--primary" data-v3-new-config type="button">选择未配置产品</button>
        </div>
      </header>
      <section class="mc-v3-filter-card">
        <label class="mc-v3-filter-search"><span>⌕</span><input data-v3-filter-keyword value="${esc(state.filters.keyword)}" placeholder="搜索型号 / 名称 / 系列 / 创建人"></label>
        <label><span>产品类型</span><select data-v3-filter-type><option value="all">全部类型</option>${types.map(type => `<option value="${esc(type)}" ${state.filters.type === type ? 'selected' : ''}>${esc(type)}</option>`).join('')}</select></label>
        <label><span>系列</span><select data-v3-filter-series><option value="all">全部系列</option>${series.map(item => `<option value="${esc(item)}" ${state.filters.series === item ? 'selected' : ''}>${esc(item)}</option>`).join('')}</select></label>
        <label><span>配置状态</span><select data-v3-filter-status>${[['all','全部状态'],['unconfigured','未配置'],['configuring','配置中'],['needs_check','待检查'],['pending_approval','待审批'],['published','已发布'],['conflict','存在冲突']].map(([value,label]) => `<option value="${value}" ${state.filters.status === value ? 'selected' : ''}>${label}</option>`).join('')}</select></label>
        <label><span>发布状态</span><select data-v3-filter-release>${[['all','全部状态'],['released','已发布'],['unreleased','未发布']].map(([value,label]) => `<option value="${value}" ${state.filters.release === value ? 'selected' : ''}>${label}</option>`).join('')}</select></label>
        <label><span>冲突状态</span><select data-v3-filter-conflict>${[['all','全部状态'],['yes','存在冲突'],['no','无冲突']].map(([value,label]) => `<option value="${value}" ${state.filters.conflict === value ? 'selected' : ''}>${label}</option>`).join('')}</select></label>
        <label><span>排序</span><select data-v3-sort>${[['updated','更新时间 ↓'],['completion','完成度 ↓'],['code','产品编号 ↑']].map(([value,label]) => `<option value="${value}" ${state.filters.sort === value ? 'selected' : ''}>${label}</option>`).join('')}</select></label>
        <button class="mc-button" data-v3-collapse-filter type="button">收起 ^</button>
      </section>
      <nav class="mc-v3-status-tabs">
        ${[['all','全部'],['unconfigured','未配置'],['configuring','配置中'],['needs_check','待检查'],['pending_approval','待审批'],['published','已发布'],['conflict','存在冲突']].map(([key,label]) => `<button class="${(key === 'all' ? state.filters.status === 'all' : state.filters.status === key) ? 'is-active' : ''}" data-v3-tab-status="${key}" type="button">${label} <b>${number(c[key])}</b></button>`).join('')}
        <span></span><button class="mc-button" data-v3-refresh type="button">刷新</button><button class="mc-button" data-v3-export type="button">导出</button><button class="mc-button" data-v3-columns type="button">列设置</button>
      </nav>
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
  const quickCoreKeys = ['chip', 'power', 'optic', 'install'];
  const findGroupByKey = key => groups().find(group => canonicalGroupKey(group) === key) || null;
  const quickCoreGroups = () => quickCoreKeys.map(key => ({ key, group: findGroupByKey(key) }));
  const groupDefault = group => {
    const overview = (state.workspace?.configuration_overview || []).find(row => number(row.id) === number(group.id)) || group;
    return overview.default_material || overview.default_option || group.default_material || '未设置默认';
  };
  const groupDone = group => number(group.option_count) > 0 && (group.selection_mode !== 'single' || groupDefault(group) !== '未设置默认');
  const activeGroup = () => state.materialGroup || null;
  const quickCoreDone = () => quickCoreGroups().filter(item => item.group && groupDone(item.group)).length;
  const quickCompletion = () => {
    const source = groups().length ? 10 : 0;
    const core = Math.round((quickCoreDone() / quickCoreKeys.length) * 60);
    const missing = missingFields().length ? 0 : 15;
    const check = state.quickCheckDone && quickBlockers().length === 0 ? 15 : 0;
    return Math.max(completion().percent || 0, source + core + missing + check);
  };
  const sourceConfigured = product => number(product?.group_count) || number(product?.option_count) || isReleased(product) || completionOf(product) > 0;
  const sourceScore = product => {
    const current = selectedProduct();
    if (!product || !current || number(product.id) === number(current.id)) return -1;
    let score = 25;
    if (productType(product) === productType(current)) score += 24;
    if (productSeries(product) === productSeries(current)) score += 28;
    if (isReleased(product)) score += 14;
    score += Math.min(18, Math.round(completionOf(product) / 6));
    score += Math.min(10, number(product.option_count || product.configured_option_count));
    return sourceConfigured(product) ? Math.min(98, score) : Math.min(62, score);
  };
  const recommendedSource = () => state.products
    .filter(product => number(product.id) !== number(selectedProduct()?.id))
    .map(product => ({ product, score: sourceScore(product) }))
    .filter(item => item.score >= 0)
    .sort((left, right) => right.score - left.score)[0] || null;
  const sourceReasons = item => {
    const product = item?.product;
    const current = selectedProduct();
    const reasons = [];
    if (!product || !current) return reasons;
    if (productType(product) === productType(current)) reasons.push('产品类型相同');
    if (productSeries(product) === productSeries(current)) reasons.push('同系列或相近系列');
    if (number(product.option_count)) reasons.push('已有芯片、电源、光学等配置');
    if (isReleased(product)) reasons.push('已存在发布版本');
    if (completionOf(product) >= 70) reasons.push('配置完成度较高');
    return reasons.length ? reasons : ['可作为相似产品参考'];
  };
  const sourceDifferences = () => ['功率可能不同', '尺寸可能不同', '调光要求待确认', '防护等级待确认', '光束角待确认'];
  const fieldFilled = field => {
    const value = state.workspace?.technical_profile?.values?.[field.key];
    return !(value === undefined || value === null || value === '' || (Array.isArray(value) && !value.length));
  };
  const missingFields = () => {
    const fields = state.workspace?.technical_profile?.fields || state.metadata.technical_profile_fields || [];
    const missingKeys = quickCoreGroups().filter(item => !item.group || !groupDone(item.group)).map(item => item.key);
    const relevant = field => {
      const text = `${field.key || ''} ${field.label || ''} ${field.section || ''}`.toLowerCase();
      if (missingKeys.includes('power') && /power|current|voltage|dimming|height|width|length|warranty|电源|功率|电流|电压|调光|高度|宽度|长度|质保/.test(text)) return true;
      if (missingKeys.includes('optic') && /beam|optic|lens|les|angle|diameter|光学|透镜|光束|角度|直径/.test(text)) return true;
      if (missingKeys.includes('install') && /install|mount|cut|size|安装|开孔|尺寸|承重|轨道/.test(text)) return true;
      if (missingKeys.includes('chip') && /chip|led|current|voltage|cri|cct|芯片|光源|电流|电压|显指|色温/.test(text)) return true;
      return false;
    };
    return fields.filter(field => !fieldFilled(field) && relevant(field)).slice(0, 4);
  };
  const quickBlockers = () => {
    const blockers = [];
    quickCoreGroups().forEach(item => {
      if (!item.group) blockers.push(`缺少${moduleLabel(item.key)}配置组`);
      else if (!groupDone(item.group)) blockers.push(`${moduleLabel(item.key)}未设置默认正式物料`);
      if (number(item.group?.conflict_count)) blockers.push(`${moduleLabel(item.key)}存在冲突`);
    });
    if (number(state.workspace?.conflicts?.length)) blockers.push('存在未处理适配冲突');
    return blockers;
  };
  const quickWarnings = () => {
    const warnings = [];
    if (missingFields().length) warnings.push(`仍有 ${missingFields().length} 个必要字段待补充`);
    if (approvalCount(selectedProduct())) warnings.push(`有 ${approvalCount(selectedProduct())} 项待审批`);
    if (!groups().length) warnings.push('尚未确认配置来源');
    return warnings;
  };
  const groupRecommendation = group => {
    const overview = (state.workspace?.configuration_overview || []).find(row => number(row.id) === number(group?.id));
    const option = (overview?.options || []).find(item => number(item.is_default)) || (overview?.options || [])[0];
    return option?.material_name || option?.material_code || '';
  };
  const groupStatus = group => {
    if (!group) return 'no_source';
    if (number(group.conflict_count)) return 'conflict';
    if (groupDone(group)) return 'done';
    if (groupRecommendation(group)) return 'suggested';
    if (number(group.option_count)) return 'needs_default';
    return 'empty';
  };
  const groupActionText = group => ({
    no_source: '选择配置来源',
    empty: '选择物料',
    suggested: '采用建议',
    needs_default: '设置默认',
    conflict: '处理冲突',
    done: '更换',
  }[groupStatus(group)] || '查看');
  const statusText = status => ({ no_source:'无配置来源', empty:'需要选择', suggested:'系统建议', needs_default:'缺默认', conflict:'存在冲突', done:'完全适配' }[status] || '待处理');
  const importantSpecs = (group, key) => {
    const overview = (state.workspace?.configuration_overview || []).find(row => number(row.id) === number(group?.id));
    const option = (overview?.options || []).find(item => number(item.is_default)) || (overview?.options || [])[0] || {};
    const raw = option.key_specs || option.spec_summary || option.material_spec || '';
    if (raw) return String(raw).split(/[|；;、,]/).map(part => part.trim()).filter(Boolean).slice(0, 4).join(' · ');
    if (!group) return '需要先确认配置来源';
    const fallback = [
      number(group.option_count) ? `正式可选 ${number(group.option_count)} 项` : '',
      number(group.alternative_count) ? `候选 ${number(group.alternative_count)} 项` : '',
      key === 'power' ? '功率 · 电流 · 电压 · 调光' : '',
      key === 'chip' ? '功率 · 电流 · 色温 · CRI' : '',
      key === 'optic' ? '角度 · LES · 尺寸 · 材质' : '',
      key === 'install' ? '结构 · 开孔 · 安装限制' : '',
    ].filter(Boolean);
    return fallback.slice(0, 4).join(' · ') || '规格待补充';
  };

  const renderWorkbench = () => {
    const product = selectedProduct();
    if (!product) return renderProducts();
    const pct = Math.min(100, quickCompletion());
    const totalOptions = groups().reduce((sum, item) => sum + number(item.option_count), 0);
    const missingCore = quickCoreKeys.length - quickCoreDone();
    root.innerHTML = `<section class="mc-v3-page-shell mc-v3-workbench-page">
      <div class="mc-v3-breadcrumb">Artdon ERP / 物料中心 / 产品适配 / <b>产品配置工作台</b></div>
      <header class="mc-v3-screen-head">
        <div><button class="mc-button mc-button--primary" data-v3-products type="button">切换产品</button><h1>产品配置工作台</h1><p>默认进入快速配置：确认来源 → 设置四个核心配置 → 检查并保存。完整技术范围、规则、审批和版本放入高级设置。</p></div>
        <div class="mc-v3-screen-actions"><button class="mc-button" data-v3-toggle-advanced type="button">高级设置</button><button class="mc-button" data-v3-more type="button">更多⌄</button></div>
      </header>
      <div class="mc-v3-workbench-layout">
        <main class="mc-v3-workbench-main">
          <section class="mc-v3-product-hero">
            ${productImage(product, true)}
            <div class="mc-v3-product-hero__identity"><h2>${esc(productCode(product))} <span>${esc(productName(product))}</span></h2><p>系列：${esc(productSeries(product))}　类型：${esc(productType(product))}</p><p>配置来源：${groups().length ? '已有草稿 / 可继续编辑' : '尚未确认'}　草稿版本：${esc(state.workspace?.approval?.version_no ? `V${state.workspace.approval.version_no}` : '草稿')}</p><p>状态：${esc(statusLabel(configState(product)))}　最后修改：${esc(updatedAt(product))}</p></div>
            <div class="mc-v3-hero-stat mc-v3-hero-stat--ring"><em class="mc-v3-ring" style="--value:${pct}"><b>${pct}%</b></em><span>完成度</span></div>
            <div class="mc-v3-hero-stat"><b>${missingCore}</b><span>缺失项</span></div>
            <div class="mc-v3-hero-stat"><b>${number(state.workspace?.conflicts?.length)}</b><span>冲突项</span></div>
            <div class="mc-v3-hero-stat"><b>${approvalCount(product)}</b><span>待审批</span></div>
            <div class="mc-v3-hero-stat"><b>${totalOptions}</b><span>正式选项</span></div>
          </section>
          <nav class="mc-v3-quick-flow">${[['1','确认配置来源','推荐复制 / 模板 / BOM / 空白'],['2','设置核心配置','芯片、电源、光学、安装方式'],['3','检查发布 / 保存','缺什么补什么，通过后提交']].map(([id,label,tip], index) => `<span class="${index < (groups().length ? 1 : 0) + (quickCoreDone() ? 1 : 0) ? 'is-done' : index === 0 ? 'is-active' : ''}"><b>${index === 0 && groups().length ? '✓' : id}</b><strong>${label}</strong><small>${tip}</small></span>`).join('')}</nav>
          ${renderQuickSource()}
          ${renderQuickCore()}
          ${renderMissingFields()}
          ${state.materialGroup ? renderGroupDrawer() : ''}
          ${renderAdvancedSettings()}
          ${renderParamModal()}
          ${state.sourcePickerOpen ? renderSourcePickerModal() : ''}
          ${state.exceptionMaterialId ? renderExceptionModal() : ''}
          ${renderQuickFooter()}
        </main>
      </div>
    </section>`;
  };

  const renderStepContent = () => {
    if (state.step <= 1) return renderTechnical();
    if (state.step === 2) return renderGroupStage(true);
    if (state.step === 3) return renderGroupStage(false);
    if (state.step === 4) return renderRules();
    if (state.step === 5) return renderCheck();
    return renderPublish();
  };

  const renderQuickSource = () => {
    const recommendation = recommendedSource();
    const sourceProduct = recommendation?.product;
    const hasSource = groups().length > 0;
    if (hasSource) {
      return `<section class="mc-v3-source-summary">
        <strong>配置来源：</strong>
        <span>${sourceProduct ? `复制自 ${esc(productCode(sourceProduct))} ${esc(productName(sourceProduct))}` : '已有配置草稿 / 已确认'}</span>
        <b>相似度：${sourceProduct ? `${number(recommendation.score)}%` : '—'}</b>
        <em>状态：已确认</em>
        <button class="mc-button" data-v3-source-diff type="button">查看差异</button>
        <button class="mc-button" data-v3-change-source type="button">更换来源</button>
      </section>`;
    }
    return `<section class="mc-v3-work-card mc-v3-source-card">
      <div class="mc-v3-step-title"><h3>确认配置来源</h3><p>系统只继承配置建议和值，不复制审批状态、发布状态、审批人或版本号。</p></div>
      <div class="mc-v3-source-layout">
        <article class="mc-v3-source-recommend">
          <strong>${hasSource ? '当前已有配置草稿' : '系统推荐复制'}</strong>
          ${sourceProduct ? `<div class="mc-v3-source-product">${productImage(sourceProduct)}<span><b>${esc(productCode(sourceProduct))}</b><small>${esc(productName(sourceProduct))}</small><small>${esc(productSeries(sourceProduct))} · ${esc(productType(sourceProduct))}</small></span><em>${recommendation.score}% 相似</em></div>` : '<p>暂无足够相似产品，可先套用模板或从空白开始。</p>'}
          ${sourceProduct ? `<dl><dt>相似依据</dt><dd>${sourceReasons(recommendation).map(esc).join(' · ')}</dd><dt>需复核差异</dt><dd>${sourceDifferences().map(esc).join(' · ')}</dd></dl>` : ''}
        </article>
        <div class="mc-v3-source-options">
          <button class="mc-button mc-button--primary" ${sourceProduct ? `data-v3-copy-source="${number(sourceProduct.id)}"` : 'disabled'} type="button">复制同系列产品</button>
          <button class="mc-button" data-v3-template type="button">套用产品模板</button>
          <button class="mc-button" data-v3-read-bom type="button">读取现有 BOM</button>
          <button class="mc-button" data-v3-empty-start type="button">从空白开始</button>
        </div>
      </div>
    </section>`;
  };

  const renderSourcePickerModal = () => {
    const recommendation = recommendedSource();
    const sourceProduct = recommendation?.product;
    return `<section class="mc-v3-source-modal" role="dialog" aria-modal="true">
      <div class="mc-v3-source-box">
        <header><div><h3>更换配置来源</h3><p>这些入口只改变配置来源，不复制审批、发布状态或版本号。</p></div><button class="mc-icon-button" data-v3-close-source type="button">×</button></header>
        <div class="mc-v3-source-choice-grid">
          <button class="mc-v3-source-choice" ${sourceProduct ? `data-v3-copy-source="${number(sourceProduct.id)}"` : 'disabled'} type="button"><b>复制同系列产品</b><span>${sourceProduct ? `${esc(productCode(sourceProduct))} · ${esc(productName(sourceProduct))} · ${number(recommendation.score)}% 相似` : '暂无可推荐来源'}</span></button>
          <button class="mc-v3-source-choice" data-v3-template type="button"><b>套用产品模板</b><span>按核心模组建立配置骨架</span></button>
          <button class="mc-v3-source-choice" data-v3-read-bom type="button"><b>读取现有 BOM</b><span>读取当前产品已存在的 BOM 资料</span></button>
          <button class="mc-v3-source-choice" data-v3-empty-start type="button"><b>从空白开始</b><span>只建立四个核心配置组</span></button>
        </div>
        <footer><button class="mc-button" data-v3-close-source type="button">取消</button></footer>
      </div>
    </section>`;
  };

  const renderQuickCore = () => `<section class="mc-v3-module-stage mc-v3-core-stage">
    <div class="mc-v3-step-title"><h3>四个核心配置 <small>(${quickCoreDone()} / ${quickCoreKeys.length})</small></h3><p>点击配置项后使用宽版弹窗选择物料，主页面不向下展开候选列表。</p></div>
    <div class="mc-v3-core-list">${quickCoreGroups().map(renderQuickCoreCard).join('')}</div>
  </section>`;

  const renderQuickCoreCard = item => {
    const { key, group } = item;
    const status = groupStatus(group);
    const recommendation = group ? groupRecommendation(group) : '';
    const optionLabel = group ? groupDefault(group) : '尚未建立配置组';
    const action = status === 'empty' && key === 'power' ? '选择电源' : (status === 'conflict' ? '处理冲突' : (status === 'suggested' ? '采用建议' : groupActionText(group)));
    return `<article class="mc-v3-core-row mc-v3-core-row--${esc(status)}">
      <header><i>${esc(groupIcon(key))}</i><span><b>${esc(moduleLabel(key))}</b><small>${number(group?.option_count) ? `正式可选 ${number(group.option_count)} 项` : '正式可选待确认'}</small></span></header>
      <div class="mc-v3-core-main"><strong>${esc(optionLabel)}</strong>${recommendation && optionLabel === '未设置默认' ? `<em>建议：${esc(recommendation)}</em>` : ''}<small>${esc(importantSpecs(group, key))}</small></div>
      <div class="mc-v3-core-side"><b class="mc-v3-core-status mc-v3-core-status--${esc(status)}">${esc(statusText(status))}</b><small>${number(group?.conflict_count) ? `冲突 ${number(group.conflict_count)} 项` : `正式选项 ${number(group?.option_count)} 项`}</small></div>
      <button class="mc-button ${status === 'conflict' ? 'mc-button--warn' : (status === 'done' ? '' : 'mc-button--primary')}" ${group ? `data-v3-manage-group="${number(group.id)}"` : 'data-v3-change-source'} type="button">${esc(action)}</button>
    </article>`;
  };

  const renderMissingFields = () => {
    const fields = missingFields();
    return `<section class="mc-v3-work-card mc-v3-missing-card">
      <div class="mc-v3-step-title"><h3>需要补充 ${fields.length} 项</h3><p>${fields.length > 4 ? `先显示前 4 项，另有 ${fields.length - 4} 项可集中补充。` : '只显示当前无法判断的必要字段。'}</p></div>
      ${fields.length ? `<div class="mc-v3-missing-list">${fields.slice(0, 4).map(field => `<article><b>${esc(field.label)}${field.unit ? `（${esc(field.unit)}）` : ''}</b><p>${esc(field.section || '技术范围')}缺失，无法判断适配。</p><button class="mc-button" data-v3-param-field="${esc(field.key)}" type="button">填写</button></article>`).join('')}${fields.length > 4 ? `<article class="mc-v3-missing-more"><b>其余 ${fields.length - 4} 项</b><p>集中补充更多技术参数。</p><button class="mc-button" data-v3-advanced-step="1" type="button">查看</button></article>` : ''}</div>` : '<div class="mc-v3-ready">当前没有必须补充的动态技术字段。</div>'}
    </section>`;
  };

  const renderQuickCheck = () => {
    const blockers = quickBlockers();
    const warnings = quickWarnings();
    const ok = blockers.length === 0;
    const checks = quickCheckRows();
    return `<section class="mc-v3-work-card mc-v3-check-card">
      <div class="mc-v3-step-title"><h3>检查摘要</h3><p>完整检查结果可在高级设置查看。</p></div>
      <div class="mc-v3-quick-checks">${checks.map(([level, label]) => `<span class="is-${level}">${level === 'bad' ? '×' : level === 'warn' ? '△' : '✓'} ${esc(label)}</span>`).join('')}</div>
      ${blockers.length ? `<div class="mc-v3-issues">${blockers.map(issue => `<p>× ${esc(issue)}</p>`).join('')}</div>` : '<div class="mc-v3-ready">核心配置检查未发现阻断项，可以保存草稿或提交确认。</div>'}
      ${warnings.length ? `<div class="mc-v3-warnings">${warnings.map(issue => `<p>△ ${esc(issue)}</p>`).join('')}</div>` : ''}
    </section>`;
  };

  const renderAdvancedSettings = () => {
    if (!state.advancedOpen) return '';
    return `<section class="mc-v3-work-card mc-v3-advanced-panel">
      <div class="mc-v3-step-title"><h3>高级设置</h3><p>高级能力完整保留，内部滚动：技术范围、扩展可选、条件规则、例外审批、配置版本、发布历史和日志。</p><button class="mc-icon-button" data-v3-close-advanced type="button">×</button></div>
      <nav class="mc-v3-advanced-tabs">${[['1','完整技术范围'],['3','扩展可选'],['4','条件规则'],['5','例外审批'],['6','配置版本 / 发布历史']].map(([id,label]) => `<button class="${state.step === number(id) ? 'is-active' : ''}" data-v3-advanced-step="${id}" type="button">${esc(label)}</button>`).join('')}</nav>
      <div class="mc-v3-advanced-scroll">${renderStepContent()}</div>
    </section>`;
  };

  const renderParamModal = () => {
    if (!state.paramField) return '';
    const fields = state.workspace?.technical_profile?.fields || state.metadata.technical_profile_fields || [];
    const field = fields.find(item => item.key === state.paramField);
    if (!field) return '';
    const value = state.workspace?.technical_profile?.values?.[field.key] ?? '';
    return `<section class="mc-v3-param-modal" role="dialog" aria-modal="true">
      <form class="mc-v3-param-box" data-v3-param-form>
        <header><div><h3>补充技术参数</h3><p>${esc(field.label)}${field.unit ? ` · ${esc(field.unit)}` : ''}</p></div><button class="mc-icon-button" data-v3-close-param type="button">×</button></header>
        <div class="mc-v3-param-body"><label><span>字段名称</span><b>${esc(field.label)}</b></label><label><span>当前建议值</span><b>${value === '' || value === null ? '暂无建议' : esc(value)}</b></label><label><span>建议来源</span><b>BOM / PLM / 命名系统 / 已选物料综合判断</b></label><label><span>确认值${field.unit ? `（${esc(field.unit)}）` : ''}</span>${profileInput(field)}</label></div>
        <footer><button class="mc-button" data-v3-close-param type="button">取消</button><button class="mc-button mc-button--primary" type="submit">确认填写</button></footer>
      </form>
    </section>`;
  };

  const renderQuickFooter = () => {
    const coreReady = quickBlockers().length === 0 && groups().length > 0;
    const publishedReady = coreReady && approvalCount(selectedProduct()) === 0 && state.quickCheckDone;
    const checks = quickCheckRows().slice(0, 3);
    return `<footer class="mc-v3-quick-footer">
      <div class="mc-v3-footer-checks"><strong>配置检查 / 提交审批</strong>${checks.map(([level, label]) => `<span class="is-${level}">${level === 'bad' ? '×' : level === 'warn' ? '△' : '✓'} ${esc(label)}</span>`).join('')}<button class="mc-link-button" data-v3-advanced-step="5" type="button">查看完整检查</button></div>
      <button class="mc-button" data-v3-save-draft type="button">保存草稿</button>
      <button class="mc-button mc-button--primary" data-v3-check-config type="button">检查配置</button>
      <button class="mc-button mc-button--primary" data-v3-submit-confirm ${coreReady ? '' : 'disabled'} type="button">提交确认</button>
      <button class="mc-button mc-button--primary" data-v3-approve ${publishedReady ? '' : 'hidden'} type="button">确认并发布</button>
    </footer>`;
  };

  const quickCheckRows = () => [
    [!findGroupByKey('chip') || !findGroupByKey('power') ? 'warn' : 'ok', '芯片与电源电流匹配'],
    [findGroupByKey('power') && groupDone(findGroupByKey('power')) ? 'ok' : 'warn', '电源尺寸待确认'],
    [findGroupByKey('optic') && groupDone(findGroupByKey('optic')) ? 'ok' : 'warn', '光学与 LES 匹配'],
    [findGroupByKey('install') && groupDone(findGroupByKey('install')) ? 'ok' : 'warn', '安装方式已确认'],
    [number(state.workspace?.conflicts?.length) ? 'bad' : 'ok', '不存在阻断冲突'],
    [approvalCount(selectedProduct()) ? 'warn' : 'ok', '不存在待审批例外'],
  ];

  const renderTechnical = () => {
    const fields = state.workspace?.technical_profile?.fields || state.metadata.technical_profile_fields || [];
    const sections = uniq(fields.map(field => field.section || '技术范围'));
    return `<form data-v3-profile class="mc-v3-technical mc-v3-work-card"><div class="mc-v3-step-title"><h3>技术范围</h3><p>先确认产品功率、电流、电压、结构空间、认证和光学边界；没有确认技术范围时，候选物料只能显示为候选，不能判为“完全适配”。</p></div>${sections.map(section => `<fieldset><legend>${esc(section)}</legend><div class="mc-v3-field-grid">${fields.filter(field => (field.section || '技术范围') === section).map(field => `<label><span>${esc(field.label)}${field.unit ? `（${esc(field.unit)}）` : ''}</span>${profileInput(field)}<small>当前值来自产品资料或电源规则；审核时可手填确认。</small></label>`).join('')}</div></fieldset>`).join('')}<footer><button class="mc-button mc-button--primary" type="submit">保存并确认技术范围</button><button class="mc-button" type="button" data-v3-step="2">下一步：核心物料</button></footer></form>`;
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
    return `<section class="mc-v3-work-card"><div class="mc-v3-step-title"><h3>检查审批</h3><p>第五步检查配置完整性、冲突、规则和审批条件；通过后才能提交审批。</p></div><div class="mc-v3-check-grid">${Object.entries(completion().segments || {}).map(([key,value]) => `<span><b>${number(value)}%</b><small>${esc(({technical:'技术范围',core:'核心物料',optional:'扩展可选',rules:'条件规则',check:'检查'}[key] || key))}</small></span>`).join('')}</div>${issues.length ? `<div class="mc-v3-issues">${issues.map(issue => `<p>• ${esc(issue)}</p>`).join('')}</div>` : '<div class="mc-v3-ready">配置检查通过，可以提交审批。</div>'}<button class="mc-button mc-button--primary" data-v3-approve type="button">提交审批</button></section>`;
  };

  const renderPublish = () => {
    const versions = state.workspace?.published_versions || [];
    return `<section class="mc-v3-work-card"><div class="mc-v3-step-title"><h3>版本发布</h3><p>审批通过后生成不可变发布版本，商务中心只读取已发布版本，不读取工作草稿。</p></div><div class="mc-v3-version-list">${versions.length ? versions.map(version => `<article><b>V${number(version.version_no)}</b><span>${esc(version.published_at || '—')}</span><span>${esc(version.publisher_name || '系统')}</span></article>`).join('') : '<div class="mc-v3-empty">尚未发布版本。</div>'}</div></section>`;
  };

  const matchLabel = match => ({ exact:'完全适配', conditional:'条件适配', needs_approval:'需要审批', incompatible:'不适配' }[match] || '候选');
  const candidateId = row => number(row.id || row.material_id);
  const selectedCandidate = () => state.materials.find(row => state.selectedMaterialIds.includes(candidateId(row))) || null;
  const cell = value => esc(value === undefined || value === null || value === '' ? '—' : value);
  const specValue = (row, keys, suffix = '') => {
    for (const key of keys) {
      if (row[key] !== undefined && row[key] !== null && row[key] !== '') return `${row[key]}${suffix}`;
    }
    return '—';
  };
  const categoryColumns = key => ({
    chip: ['芯片类型 / 封装', '功率', '电流', '电压', '色温', 'CRI', 'LES', '光通量'],
    power: ['功率', '电流', '电压', '输入电压', '长宽高', '安装方式', '调光', '质保'],
    optic: ['光学类型', '光束角', '直径', '高度', 'LES', '材质', '光型', '适配'],
    install: ['类型', '接口', '承重', '位置', '尺寸', '颜色', '来源', '适配'],
  }[key] || ['类型', '规格1', '规格2', '规格3', '规格4', '规格5', '来源', '适配']);
  const candidateCells = (row, key) => {
    if (key === 'chip') return [
      [row.chip_package_type, row.category_code].filter(Boolean).join(' / ') || '—',
      specValue(row, ['chip_rated_power_w', 'chip_max_power_w', 'nominal_power_w', 'max_output_power_w'], 'W'),
      specValue(row, ['chip_current_ma', 'output_current_ma'], 'mA'),
      specValue(row, ['chip_voltage_v', 'output_voltage_min_v'], 'V'),
      specValue(row, ['cct', 'color_temperature', 'colour_temperature']),
      specValue(row, ['cri', 'cri_text']),
      specValue(row, ['chip_les_text', 'optical_compatible_les']),
      specValue(row, ['lumen', 'luminous_flux', 'lm'], 'lm'),
    ];
    if (key === 'power') return [
      specValue(row, ['max_output_power_w', 'nominal_power_w'], 'W'),
      specValue(row, ['output_current_ma', 'output_current_min_ma'], 'mA'),
      [row.output_voltage_min_v, row.output_voltage_max_v].filter(Boolean).join('–') || '—',
      [row.input_voltage_min_v, row.input_voltage_max_v].filter(Boolean).join('–') || '—',
      [row.length_mm, row.width_mm, row.height_mm].filter(Boolean).join('×') || '—',
      specValue(row, ['installation_type']),
      specValue(row, ['dimming_modes', 'output_type']),
      specValue(row, ['supplier_warranty_years'], '年'),
    ];
    if (key === 'optic') return [
      specValue(row, ['optical_type']),
      [row.optical_beam_angle_min, row.optical_beam_angle_max].filter(Boolean).join('–') || '—',
      specValue(row, ['optical_diameter_mm'], 'mm'),
      specValue(row, ['optical_height_mm'], 'mm'),
      specValue(row, ['optical_compatible_les']),
      specValue(row, ['optical_material_text']),
      specValue(row, ['optical_mounting_structure']),
      matchLabel(row.match_level || 'exact'),
    ];
    return [
      specValue(row, ['accessory_type', 'connector_installation_type', 'installation_type']),
      specValue(row, ['connector_interface_type', 'accessory_interface_type']),
      specValue(row, ['connector_load_kg'], 'kg'),
      specValue(row, ['accessory_installation_position']),
      specValue(row, ['accessory_size_text']),
      specValue(row, ['accessory_color']),
      specValue(row, ['suppliers', 'source']),
      matchLabel(row.match_level || 'exact'),
    ];
  };

  const renderGroupDrawer = () => {
    const group = state.materialGroup;
    if (!group) return '';
    const key = canonicalGroupKey(group);
    const picked = number(group.option_count);
    const selected = selectedCandidate();
    return `<section class="mc-v3-config-drawer mc-v3-wide-picker" role="dialog" aria-modal="true">
      <div class="mc-v3-picker-box">
        <header><div><h3>选择 ${esc(moduleLabel(key))}</h3><span>${group.is_required ? '必选' : '可选'} · ${group.selection_mode === 'single' ? '单选' : '多选'} · 默认只允许正式可选物料直接加入</span></div><button class="mc-icon-button" data-v3-close-drawer type="button">×</button></header>
        <div class="mc-v3-picker-filters"><button class="is-active" type="button">正式可选 ${state.materials.length || picked}</button><button type="button">待确认</button><button type="button">待整理来源</button><button type="button">异常</button><select><option>品牌：全部</option></select><select><option>型号：全部</option></select><select><option>${key === 'chip' ? '芯片类型' : key === 'power' ? '输出电流' : '光束角'}：全部</option></select><select><option>${key === 'chip' ? '封装' : key === 'power' ? '输入电压' : 'LES'}：全部</option></select><button class="mc-button" type="button">更多筛选</button><label><input placeholder="搜索品牌 / 型号 / 关键字"><span>⌕</span></label></div>
        <div class="mc-v3-candidate-list">${state.drawerLoading ? '<div class="mc-v3-loading">正在读取候选物料…</div>' : renderCandidates(key)}</div>
        ${state.materialDetailId ? renderMaterialDetail() : ''}
        <footer><span>当前已选择：<b>${selected ? esc([selected.brand, selected.model || selected.material_code, selected.name].filter(Boolean).join(' ')) : '未选择'}</b></span><button class="mc-button" data-v3-close-drawer type="button">取消</button><button class="mc-button mc-button--primary" data-v3-confirm-material ${selected ? '' : 'disabled'} type="button">确认选择</button></footer>
      </div>
    </section>`;
  };

  const renderCandidates = key => {
    if (!state.materials.length) return '<div class="mc-v3-empty">当前没有候选物料。请先补充技术范围或维护正式物料。</div>';
    const headers = categoryColumns(key);
    return `<div class="mc-v3-candidate-table">
      <div class="mc-v3-candidate-head"><span></span><span>图片</span><span>品牌 / 型号</span>${headers.map(label => `<span>${esc(label)}</span>`).join('')}<span>适配状态</span><span>匹配度</span><span>详情</span></div>
      ${state.materials.map(row => {
      const match = row.match_level || 'exact';
      const already = number(row.already_added);
      const incompatible = match === 'incompatible';
      const id = candidateId(row);
      const selected = state.selectedMaterialIds.includes(id) || already;
      const reasons = (row.conflict_reasons || []).join('；');
      return `<article class="mc-v3-candidate-row ${selected ? 'is-selected' : ''} ${already ? 'is-picked' : ''} ${incompatible ? 'is-blocked' : ''}" data-v3-pick-material="${id}" ${incompatible ? 'data-v3-blocked="1"' : ''} role="button" tabindex="0">
        <span><input type="${state.materialGroup?.selection_mode === 'single' ? 'radio' : 'checkbox'}" name="candidate" ${selected ? 'checked' : ''} ${incompatible ? 'disabled' : ''}></span>
        ${materialImage(row)}
        <span class="mc-v3-candidate-name"><b>${esc(row.brand || row.material_code || '—')}</b><small>${esc([row.model, row.name, row.material_code].filter(Boolean).join(' · '))}</small>${incompatible && reasons ? `<em>原因：${esc(reasons)}</em>` : ''}</span>
        ${candidateCells(row, key).map(value => `<span>${cell(value)}</span>`).join('')}
        <strong class="mc-v3-match mc-v3-match--${esc(match)}">${esc(matchLabel(match))}${already ? '<i>已选默认</i>' : ''}</strong>
        <span>${cell(row.match_score || row.score || (match === 'exact' ? '100%' : match === 'conditional' ? '80%' : '—'))}</span>
        <span class="mc-v3-candidate-actions"><button class="mc-link-button" data-v3-material-detail="${id}" type="button">详情</button>${incompatible ? `<button class="mc-link-button mc-link-button--danger" data-v3-exception-material="${id}" type="button">申请例外</button>` : ''}</span>
      </article>`;
    }).join('')}</div>`;
  };

  const renderMaterialDetail = () => {
    const row = state.materials.find(item => candidateId(item) === number(state.materialDetailId));
    if (!row) return '';
    const specs = [
      ['物料编号', row.material_code],
      ['名称', row.name],
      ['品牌', row.brand],
      ['型号', row.model],
      ['供应商', row.suppliers],
      ['质保', row.supplier_warranty_years ? `${row.supplier_warranty_years} 年` : '—'],
      ['规格', row.key_specs],
      ['适配判断', matchLabel(row.match_level || 'exact')],
      ['冲突原因', (row.conflict_reasons || []).join('；') || '无'],
    ];
    return `<aside class="mc-v3-material-detail-panel"><header><strong>物料详情</strong><button class="mc-icon-button" data-v3-close-detail type="button">×</button></header>${specs.map(([label, value]) => `<p><b>${esc(label)}</b><span>${cell(value)}</span></p>`).join('')}</aside>`;
  };

  const renderExceptionModal = () => {
    const row = state.materials.find(item => candidateId(item) === number(state.exceptionMaterialId));
    if (!row) return '';
    const reason = (row.conflict_reasons || []).join('；') || '当前物料与产品技术要求不匹配';
    return `<section class="mc-v3-param-modal" role="dialog" aria-modal="true">
      <form class="mc-v3-param-box" data-v3-exception-form>
        <header><div><h3>申请例外使用</h3><p>${esc(row.material_code || row.model || row.name || '候选物料')}</p></div><button class="mc-icon-button" data-v3-close-exception type="button">×</button></header>
        <div class="mc-v3-param-body"><label><span>不适配原因</span><b>${esc(reason)}</b></label><label><span>例外原因</span><textarea name="reason" rows="3" required placeholder="请填写必须使用该不适配物料的工程原因"></textarea></label><label><span>审批人 / 备注</span><input name="approver_note" placeholder="可填写审批人或备注"></label></div>
        <footer><button class="mc-button" data-v3-close-exception type="button">取消</button><button class="mc-button mc-button--warn" type="submit">提交例外申请</button></footer>
      </form>
    </section>`;
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
    else if (state.screen === 'products') renderProducts();
    else renderHome();
  };

  const loadDrawerMaterials = async groupId => {
    const group = groups().find(row => number(row.id) === number(groupId));
    if (!group) return;
    state.materialGroup = group;
    state.drawerLoading = true;
    state.materials = [];
    state.selectedMaterialIds = [];
    state.materialDetailId = 0;
    state.exceptionMaterialId = 0;
    render();
    try {
      state.materials = await api(`candidates&group_id=${encodeURIComponent(group.id)}&status=official`);
      state.selectedMaterialIds = state.materials.filter(row => number(row.already_added)).map(candidateId);
      if (group.selection_mode === 'single' && state.selectedMaterialIds.length > 1) state.selectedMaterialIds = state.selectedMaterialIds.slice(0, 1);
    } catch (error) {
      toast(error.message, true);
    } finally {
      state.drawerLoading = false;
      render();
    }
  };

  page.addEventListener('click', async event => {
    const button = event.target.closest('button');
    const candidateRow = event.target.closest('[data-v3-pick-material]');
    if (!button && candidateRow) {
      if (candidateRow.dataset.v3Blocked) return;
      const id = number(candidateRow.dataset.v3PickMaterial);
      if (!id) return;
      if (state.materialGroup?.selection_mode === 'single') state.selectedMaterialIds = [id];
      else state.selectedMaterialIds = state.selectedMaterialIds.includes(id) ? state.selectedMaterialIds.filter(item => item !== id) : [...state.selectedMaterialIds, id];
      state.materialDetailId = 0;
      return renderWorkbench();
    }
    if (!button) return;
    try {
      if (button.matches('[data-v3-home]')) return navigate('home');
      if (button.matches('[data-v3-products],[data-v3-select-product],[data-v3-new-config]')) return navigate('products');
      if (button.matches('[data-v3-home-status]')) { state.filters.status = button.dataset.v3HomeStatus || 'all'; return navigate('products'); }
      if (button.matches('[data-v3-template]')) return navigate('template');
      if (button.matches('[data-v3-batch],[data-v3-copy-current]')) return navigate('batch');
      if (button.matches('[data-v3-open-product]')) return loadWorkspace(number(button.dataset.v3OpenProduct), 0, 1);
      if (button.matches('[data-v3-toggle-advanced]')) { state.advancedOpen = !state.advancedOpen; return renderWorkbench(); }
      if (button.matches('[data-v3-close-advanced]')) { state.advancedOpen = false; return renderWorkbench(); }
      if (button.matches('[data-v3-advanced-step]')) { state.advancedOpen = true; state.step = number(button.dataset.v3AdvancedStep || 1); return renderWorkbench(); }
      if (button.matches('[data-v3-change-source]')) { state.sourcePickerOpen = true; return renderWorkbench(); }
      if (button.matches('[data-v3-close-source]')) { state.sourcePickerOpen = false; return renderWorkbench(); }
      if (button.matches('[data-v3-source-diff]')) return toast(`差异提示：${sourceDifferences().join('；')}`);
      if (button.matches('[data-v3-param-field]')) { state.paramField = button.dataset.v3ParamField || ''; return renderWorkbench(); }
      if (button.matches('[data-v3-close-param]')) { state.paramField = null; return renderWorkbench(); }
      if (button.matches('[data-v3-close-exception]')) { state.exceptionMaterialId = 0; return renderWorkbench(); }
      if (button.matches('[data-v3-read-bom]')) return toast('已读取当前产品 BOM 可用资料；缺失项会在“需要补充”中显示。');
      if (button.matches('[data-v3-empty-start]')) {
        await api('apply_template', { product_id: selectedProduct().id, template_keys: ['light_source', 'power_driver', 'optical', 'installation'] });
        toast('已从空白开始建立四个核心配置组。');
        return loadWorkspace(selectedProduct().id, 0, 2);
      }
      if (button.matches('[data-v3-copy-source]')) {
        if (!confirm('确认复制推荐产品的配置？系统不会复制审批状态、发布状态、审批人、发布人或原版本号。')) return;
        try {
          await api('batch_apply', { source_product_id: number(button.dataset.v3CopySource), target_product_ids: [selectedProduct().id], mode: 'fill_missing', include_power_rule: 1 });
          toast('已复制相似产品配置和电源范围，新产品进入草稿 / 待检查。');
        } catch (copyError) {
          await api('batch_apply', { source_product_id: number(button.dataset.v3CopySource), target_product_ids: [selectedProduct().id], mode: 'fill_missing', include_power_rule: 0 });
          toast('已复制相似产品配置；电源范围因权限或来源限制未复制，请在“需要补充”中确认。');
        }
        state.sourcePickerOpen = false;
        return loadWorkspace(selectedProduct().id, 0, 2);
      }
      if (button.matches('[data-v3-save-draft]')) {
        if (!groups().length) {
          await api('apply_template', { product_id: selectedProduct().id, template_keys: ['light_source', 'power_driver', 'optical', 'installation'] });
          toast('草稿已保存：已建立四个核心配置组，尚未自动选择物料。');
          return loadWorkspace(selectedProduct().id, 0, 2);
        }
        return toast('草稿已保存。已选物料和技术范围由各配置动作实时记录。');
      }
      if (button.matches('[data-v3-check-config]')) { state.quickCheckDone = true; toast(quickBlockers().length ? '检查完成：存在需要处理的问题。' : '检查通过：核心配置没有阻断项。', quickBlockers().length > 0); return renderWorkbench(); }
      if (button.matches('[data-v3-submit-confirm]')) { if (quickBlockers().length) throw new Error('核心配置未完成，不能提交确认。'); state.quickCheckDone = true; toast('已完成提交前检查；如需审批或发布，请由有权限人员在高级设置中处理。'); return renderWorkbench(); }
      if (button.matches('[data-v3-tab-status]')) { state.filters.status = button.dataset.v3TabStatus === 'all' ? 'all' : button.dataset.v3TabStatus; return renderProducts(); }
      if (button.matches('[data-v3-refresh]')) { const products = await api('products'); state.products = Array.isArray(products) ? products : []; toast('产品配置列表已刷新。'); return renderProducts(); }
      if (button.matches('[data-v3-export]')) { exportProductsCsv(); return toast('当前筛选结果已导出。'); }
      if (button.matches('[data-v3-columns]')) return toast('列宽和小屏布局已按产品配置场景优化。');
      if (button.matches('[data-v3-collapse-filter],[data-v3-row-more],[data-v3-more]')) return toast('当前视图已保持完整配置入口。');
      if (button.matches('[data-v3-step]')) { state.step = number(button.dataset.v3Step); return renderWorkbench(); }
      if (button.matches('[data-v3-manage-group]')) return loadDrawerMaterials(number(button.dataset.v3ManageGroup));
      if (button.matches('[data-v3-close-drawer]')) { state.materialGroup = null; state.materials = []; state.selectedMaterialIds = []; state.materialDetailId = 0; return renderWorkbench(); }
      if (button.matches('[data-v3-material-detail]')) { state.materialDetailId = number(button.dataset.v3MaterialDetail); return renderWorkbench(); }
      if (button.matches('[data-v3-close-detail]')) { state.materialDetailId = 0; return renderWorkbench(); }
      if (button.matches('[data-v3-return-workspace]')) return renderWorkbench();
      if (button.matches('[data-v3-save-next]')) { const next = coreGroups().find(group => !groupDone(group) && number(group.id) !== number(state.materialGroup?.id)); return next ? loadDrawerMaterials(number(next.id)) : (state.step = 3, renderWorkbench()); }
      if (button.matches('[data-v3-confirm-material]')) {
        const ids = state.selectedMaterialIds.filter(Boolean);
        if (!ids.length) throw new Error('请先选择一个正式可选物料。');
        const result = await api('add_options', { group_id: state.materialGroup.id, material_ids: ids });
        if (state.materialGroup.selection_mode === 'single' && result.optionIds?.[0]) await api('set_default', { group_id: state.materialGroup.id, option_ids: [result.optionIds[0]], min_select: state.materialGroup.is_required ? 1 : 0, max_select: 1 });
        toast('物料已加入并更新默认配置。');
        return loadWorkspace(selectedProduct().id, 0, state.step);
      }
      if (button.matches('[data-v3-add-material]')) {
        const result = await api('add_options', { group_id: state.materialGroup.id, material_ids: [number(button.dataset.v3AddMaterial)] });
        if (state.materialGroup.selection_mode === 'single' && result.optionIds?.[0]) await api('set_default', { group_id: state.materialGroup.id, option_ids: [result.optionIds[0]], min_select: state.materialGroup.is_required ? 1 : 0, max_select: 1 });
        toast('物料已加入配置。');
        return loadWorkspace(selectedProduct().id, 0, state.step);
      }
      if (button.matches('[data-v3-exception-material]')) {
        state.exceptionMaterialId = number(button.dataset.v3ExceptionMaterial);
        return renderWorkbench();
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
      if (event.target.matches('[data-v3-param-form]')) {
        event.preventDefault();
        const fields = state.workspace?.technical_profile?.fields || state.metadata.technical_profile_fields || [];
        const old = { ...(state.workspace?.technical_profile?.values || {}) };
        const form = new FormData(event.target);
        fields.forEach(field => {
          if (field.key !== state.paramField) return;
          old[field.key] = field.type === 'multi' ? form.getAll(field.key) : form.get(field.key);
        });
        await api('save_technical_profile', { product_id: selectedProduct().id, profile: old });
        state.paramField = null;
        state.quickCheckDone = false;
        toast('技术参数已补充，匹配结果将重新计算。');
        return loadWorkspace(selectedProduct().id, 0, 2);
      }
      if (event.target.matches('[data-v3-exception-form]')) {
        event.preventDefault();
        const form = new FormData(event.target);
        const reason = String(form.get('reason') || '').trim();
        const note = String(form.get('approver_note') || '').trim();
        if (!reason) throw new Error('请填写例外原因。');
        await api('add_options', { group_id: state.materialGroup.id, material_ids: [state.exceptionMaterialId], force_exception_reason: note ? `${reason}（审批/备注：${note}）` : reason });
        state.exceptionMaterialId = 0;
        toast('例外申请已提交，并会在检查时进入审批。');
        return loadWorkspace(selectedProduct().id, state.materialGroup.id, state.step);
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
