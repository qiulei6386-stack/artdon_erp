(() => {
  'use strict';

  const page = document.querySelector('[data-adaptation]');
  const source = document.getElementById('adaptation-bootstrap');
  const root = page?.querySelector('[data-overview-dashboard]');
  if (!page || !source || !root) return;

  let bootstrap = {};
  try { bootstrap = JSON.parse(source.textContent || '{}'); } catch (_) { bootstrap = { pageLoadError: '页面初始化资料格式错误，请刷新后重试。' }; }

  const state = {
    products: Array.isArray(bootstrap.products) ? bootstrap.products : [],
    metadata: bootstrap.metadata && typeof bootstrap.metadata === 'object' ? bootstrap.metadata : {},
    workspace: bootstrap.workspace && typeof bootstrap.workspace === 'object' ? bootstrap.workspace : null,
    view: ['home', 'products', 'workspace'].includes(bootstrap.view) ? bootstrap.view : 'home',
    step: Math.min(6, Math.max(1, Number(bootstrap.step) || 1)),
    query: '', status: 'all', sort: 'code', page: 1, pageSize: 50, busy: false,
  };
  const baseUrl = String(bootstrap.baseUrl || '').replace(/\/$/, '');

  const esc = (value) => String(value ?? '').replace(/[&<>'"]/g, (char) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#39;', '"': '&quot;' }[char]));
  const num = (value) => Number(value || 0);
  const text = (value, fallback = '—') => value === undefined || value === null || value === '' ? fallback : String(value);
  const image = (url) => url ? `<img src="${esc(url)}" alt="" loading="lazy">` : '<span class="mc-adaptation-image__fallback">无图</span>';
  const productName = (product) => text(product.product_name || product.name || product.product_code);
  const stage = (product) => ({ unconfigured: '未配置', pending_approval: '待审批', needs_review: '待检查', enabled: '已发布' }[product.configuration_state] || text(product.approval_label, '配置中'));

  const toast = (message, type = 'success') => {
    let node = document.querySelector('[data-adaptation-toast]');
    if (!node) { node = document.createElement('div'); node.dataset.adaptationToast = ''; document.body.appendChild(node); }
    node.className = `mc-adaptation-toast is-${type}`;
    node.textContent = message;
    node.hidden = false;
    window.clearTimeout(toast.timer);
    toast.timer = window.setTimeout(() => { node.hidden = true; }, 3600);
  };

  const api = async (action, payload = {}, method = 'GET') => {
    const query = new URLSearchParams({ action, ...(method === 'GET' ? payload : {}) });
    const url = `${baseUrl}/api/v1/adaptation.php?${query.toString()}`;
    const options = method === 'GET' ? { credentials: 'same-origin' } : {
      method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' },
      body: new URLSearchParams({ action, csrf_token: String(bootstrap.csrf || ''), ...payload }).toString(),
    };
    const response = await fetch(url, options);
    const body = await response.json().catch(() => ({}));
    if (!response.ok || !body.ok) throw new Error(body.error || body.message || `请求失败（${response.status}）`);
    return body.data;
  };

  const urlFor = (view, extras = {}) => {
    const query = new URLSearchParams();
    query.set('view', view);
    const productId = extras.productId ?? state.workspace?.product?.id;
    if (view === 'workspace' && productId) {
      query.set('product_id', String(productId));
      query.set('step', String(extras.step ?? state.step));
    }
    return `${window.location.pathname}?${query.toString()}`;
  };

  const navigate = (view, extras = {}, replace = false) => {
    state.view = view;
    if (extras.step) state.step = extras.step;
    const target = urlFor(view, extras);
    window.history[replace ? 'replaceState' : 'pushState']({}, '', target);
    render();
  };

  const openWorkspace = async (id, step = 1) => {
    if (!id) return;
    root.innerHTML = '<div class="mc-adaptation-loading">正在打开产品工作台…</div>';
    try {
      state.workspace = await api('workspace', { product_id: String(id) }, 'GET');
      state.step = step;
      state.view = 'workspace';
      window.history.pushState({}, '', urlFor('workspace', { productId: id, step }));
      render();
    } catch (error) {
      renderFailure(error);
    }
  };

  const filteredProducts = () => {
    const query = state.query.trim().toLowerCase();
    let rows = state.products.filter((product) => {
      if (state.status === 'unconfigured' && product.configuration_state !== 'unconfigured') return false;
      if (state.status === 'configured' && num(product.group_count) === 0) return false;
      if (state.status === 'needs_review' && product.configuration_state !== 'needs_review') return false;
      if (state.status === 'pending_approval' && product.configuration_state !== 'pending_approval') return false;
      if (state.status === 'enabled' && product.configuration_state !== 'enabled') return false;
      if (state.status === 'conflict' && !product.has_conflict) return false;
      if (!query) return true;
      return [product.product_code, product.product_name, product.series_name, product.product_type].join(' ').toLowerCase().includes(query);
    });
    rows = rows.sort((a, b) => {
      if (state.sort === 'name') return productName(a).localeCompare(productName(b));
      if (state.sort === 'updated') return String(b.updated_at || '').localeCompare(String(a.updated_at || ''));
      return String(a.product_code || '').localeCompare(String(b.product_code || ''));
    });
    return rows;
  };

  const statusCounts = () => ({
    all: state.products.length,
    unconfigured: state.products.filter((item) => item.configuration_state === 'unconfigured').length,
    configured: state.products.filter((item) => num(item.group_count) > 0).length,
    needs_review: state.products.filter((item) => item.configuration_state === 'needs_review').length,
    pending_approval: state.products.filter((item) => item.configuration_state === 'pending_approval').length,
    enabled: state.products.filter((item) => item.configuration_state === 'enabled').length,
    conflict: state.products.filter((item) => item.has_conflict).length,
  });

  const renderHome = () => {
    const counts = statusCounts();
    const labels = [['all','全部产品'], ['unconfigured','未配置'], ['configured','配置中'], ['needs_review','待检查'], ['pending_approval','待审批'], ['enabled','已发布'], ['conflict','存在冲突']];
    const recent = [...state.products].sort((a, b) => String(b.updated_at || '').localeCompare(String(a.updated_at || ''))).slice(0, 8);
    root.innerHTML = `
      <section class="mc-adaptation-home-intro">
        <div><h2>从一个产品开始</h2><p>基础页面已恢复为统一工作台。先完成技术范围，再继续核心物料等后续步骤。</p></div>
        <div class="mc-adaptation-home-intro__actions"><button class="mc-button mc-button--primary" type="button" data-v3-products>选择产品开始</button><button class="mc-button" type="button" data-v3-products>查看全部产品</button><button class="mc-button" type="button" data-v3-disabled>配置模板</button><button class="mc-button" type="button" data-v3-disabled>批量工具</button></div>
      </section>
      <section class="mc-adaptation-status-grid">${labels.map(([key, label]) => `<button class="mc-adaptation-status-card" data-v3-filter="${key}" type="button"><span>${label}</span><strong>${counts[key]}</strong><small>点击查看产品</small></button>`).join('')}</section>
      <section class="mc-adaptation-panel"><div class="mc-adaptation-panel__head"><div><h2>最近产品</h2><p>显示最近有更新的产品配置。</p></div><button class="mc-button" type="button" data-v3-products>全部产品</button></div><div class="mc-adaptation-recent">${recent.length ? recent.map(productRow).join('') : '<div class="mc-empty-state">还没有可显示的产品。</div>'}</div></section>`;
  };

  const productRow = (product) => `<button type="button" class="mc-adaptation-recent__row" data-v3-open-product="${num(product.id)}"><span class="mc-adaptation-image">${image(product.image_url)}</span><span><b>${esc(product.product_code)}</b><small>${esc(productName(product))}</small></span><span>${esc(stage(product))}</span><span>${num(product.group_count)} 组 · ${num(product.option_count)} 项</span><span>${num(product.conflict_count)} 冲突</span><span>进入工作台 →</span></button>`;

  const renderProducts = () => {
    const counts = statusCounts();
    const tabs = [['all','全部'],['unconfigured','未配置'],['configured','配置中'],['needs_review','待检查'],['pending_approval','待审批'],['enabled','已发布'],['conflict','存在冲突']];
    const rows = filteredProducts();
    const pages = Math.max(1, Math.ceil(rows.length / state.pageSize));
    state.page = Math.min(state.page, pages);
    const start = (state.page - 1) * state.pageSize;
    const visible = rows.slice(start, start + state.pageSize);
    root.innerHTML = `
      <section class="mc-adaptation-products-page">
        <div class="mc-adaptation-panel__head"><div><h2>全部产品配置</h2><p>搜索、筛选和分页仅影响产品列表，不会修改已有配置。</p></div><div><button class="mc-button" type="button" data-v3-home>返回适配首页</button><button class="mc-button" type="button" data-v3-refresh>刷新</button><button class="mc-button" type="button" data-v3-disabled>列设置（暂未开放）</button></div></div>
        <nav class="mc-adaptation-tabs">${tabs.map(([key, label]) => `<button type="button" class="${state.status === key ? 'is-active' : ''}" data-v3-filter="${key}">${label}<b>${counts[key]}</b></button>`).join('')}</nav>
        <div class="mc-adaptation-list-tools"><label>搜索<input type="search" data-v3-search value="${esc(state.query)}" placeholder="型号、名称、系列或类型"></label><label>排序<select data-v3-sort><option value="code">型号</option><option value="name">名称</option><option value="updated">最近更新</option></select></label><label>每页<select data-v3-page-size><option value="25">25</option><option value="50">50</option><option value="100">100</option></select></label><span>共 ${rows.length} 个产品</span></div>
        <div class="mc-adaptation-table-wrap"><table class="mc-adaptation-table"><thead><tr><th>产品</th><th>类型 / 系列</th><th>当前阶段</th><th>完成度</th><th>技术范围缺失</th><th>核心物料状态</th><th>冲突</th><th>待审批</th><th>版本</th><th>更新时间</th><th>操作</th></tr></thead><tbody>${visible.length ? visible.map((product) => {
          const stageText = stage(product);
          return `<tr><td><div class="mc-adaptation-product-cell"><span class="mc-adaptation-image">${image(product.image_url)}</span><span><b>${esc(product.product_code)}</b><small>${esc(productName(product))}</small></span></div></td><td>${esc(text(product.product_type))}<small>${esc(text(product.series_name))}</small></td><td><span class="mc-adaptation-stage">${esc(stageText)}</span></td><td>${num(product.group_count) ? '进行中' : '未开始'}</td><td>${num(product.group_count) ? '待确认' : '—'}</td><td>暂停</td><td>${num(product.conflict_count)}</td><td>${stageText === '待审批' ? 1 : 0}</td><td>草稿 ${num(product.group_count)} / 发布 ${text(product.approved_version, '—')}</td><td>${esc(text(product.updated_at))}</td><td><button type="button" class="mc-button mc-button--small ${product.configuration_state === 'unconfigured' ? 'mc-button--primary' : ''}" data-v3-open-product="${num(product.id)}">${product.configuration_state === 'unconfigured' ? '开始配置' : '继续配置'}</button></td></tr>`;
        }).join('') : '<tr><td colspan="11"><div class="mc-empty-state">没有符合条件的产品。</div></td></tr>'}</tbody></table></div>
        <footer class="mc-adaptation-pager"><span>第 ${state.page} / ${pages} 页 · 显示 ${visible.length} 条</span><div><button class="mc-button mc-button--small" type="button" data-v3-page="${state.page - 1}" ${state.page <= 1 ? 'disabled' : ''}>上一页</button><button class="mc-button mc-button--small" type="button" data-v3-page="${state.page + 1}" ${state.page >= pages ? 'disabled' : ''}>下一页</button></div></footer>
      </section>`;
    const sort = root.querySelector('[data-v3-sort]'); if (sort) sort.value = state.sort;
    const pageSize = root.querySelector('[data-v3-page-size]'); if (pageSize) pageSize.value = String(state.pageSize);
  };

  const completion = (workspace) => {
    const profile = workspace.technical_profile || {};
    const fields = Array.isArray(profile.fields) ? profile.fields : [];
    const values = profile.values || {};
    const filled = fields.filter((field) => { const value = values[field.key]; return Array.isArray(value) ? value.length : value !== null && value !== undefined && value !== ''; }).length;
    const total = fields.length || 1;
    const technicalConfirmed = Boolean(profile.confirmed_at);
    const techPercent = technicalConfirmed ? 20 : 0;
    // Stop-loss mode deliberately does not invent completion data for paused steps.
    const missing = { technical: technicalConfirmed ? 0 : Math.max(1, total - filled), core: null, optional: null, rules: null, approval: null, conflict: (workspace.conflicts || []).filter((item) => item.status === 'active').length };
    return { percent: techPercent, technicalConfirmed, filled, total, missing };
  };

  const steps = [{ id:1, label:'技术范围' }, { id:2, label:'核心物料' }, { id:3, label:'扩展可选' }, { id:4, label:'条件规则' }, { id:5, label:'检查审批' }, { id:6, label:'版本发布' }];

  const renderWorkspace = () => {
    const workspace = state.workspace;
    if (!workspace?.product) { renderProducts(); return; }
    const product = workspace.product; const result = completion(workspace);
    root.innerHTML = `
      <section class="mc-adaptation-product-summary"><div class="mc-adaptation-product-summary__identity"><span class="mc-adaptation-image mc-adaptation-image--large">${image(product.image_url)}</span><div><p>当前产品</p><h2>${esc(product.product_code)} <small>${esc(productName(product))}</small></h2><span>系列：${esc(text(product.series_name))}</span><span>类型：${esc(text(product.product_type))}</span><span>状态：${esc(stage(product))}</span></div></div><div class="mc-adaptation-product-summary__metrics"><div><b>${result.percent}%</b><small>基础完成度</small></div><div><b>${result.missing.technical}</b><small>技术范围缺失</small></div><div><b>—</b><small>核心物料（暂停）</small></div><div><b>${result.missing.conflict}</b><small>冲突</small></div><div><b>—</b><small>审批发布（暂停）</small></div></div></section>
      <nav class="mc-adaptation-steps" aria-label="产品配置步骤">${steps.map((step) => `<button type="button" class="${state.step === step.id ? 'is-active' : ''} ${step.id === 1 && result.technicalConfirmed ? 'is-done' : ''}" data-v3-step="${step.id}"><b>${step.id}</b><span>${step.label}</span><small>${step.id === 1 ? (result.technicalConfirmed ? '已确认' : '进行中') : '暂未开放'}</small></button>`).join('')}</nav>
      ${state.step === 1 ? renderTechnical(workspace, result) : renderPausedStep(steps[state.step - 1])}`;
  };

  const renderPausedStep = (step) => `<section class="mc-adaptation-paused"><h2>${esc(step.label)}</h2><p>当前处于产品适配基础页面止损修复阶段。本步骤暂未开放，不会生成核心物料、规则、审批或发布数据。</p><button class="mc-button mc-button--primary" type="button" data-v3-step="1">返回技术范围</button></section>`;

  const renderTechnical = (workspace, result) => {
    const profile = workspace.technical_profile || {}; const fields = Array.isArray(profile.fields) ? profile.fields : []; const values = profile.values || {};
    const sections = ['电气范围', '结构与空间', '环境与要求', '光学范围', '补充说明'];
    const formGroups = sections.map((section) => {
      const members = fields.filter((field) => field.section === section);
      if (!members.length) return '';
      return `<fieldset class="mc-technical-section"><legend>${section}</legend><p class="mc-technical-section__legend">标注单位、建议来源与确认状态；保存草稿不会进入后续物料和规则流程。</p><div class="mc-technical-grid">${members.map((field) => technicalField(field, values[field.key])).join('')}</div></fieldset>`;
    }).join('');
    const confirmed = profile.confirmed_at ? `已人工确认：${esc(profile.confirmed_at)}` : '待确认：保存并确认技术范围后生效';
    return `<section class="mc-technical-workspace"><div class="mc-adaptation-panel__head"><div><h2>步骤 1 · 技术范围</h2><p>已填写 ${result.filled} / ${result.total} 项。${confirmed}</p></div><button class="mc-button" type="button" data-v3-products>切换产品</button></div><form data-v3-technical-form novalidate>${formGroups}<footer class="mc-technical-footer"><div><strong>技术范围状态</strong><span>${confirmed}</span></div><div><button class="mc-button" type="submit" data-v3-save="draft">保存草稿</button><button class="mc-button mc-button--primary" type="submit" data-v3-save="confirm">保存并确认技术范围</button><button class="mc-button" type="submit" data-v3-save="enter-core">保存并进入核心物料</button></div></footer></form></section>`;
  };

  const technicalField = (field, value) => {
    const current = value === null || value === undefined ? '' : value;
    const label = `${esc(field.label)}${field.unit ? ` <em>${esc(field.unit)}</em>` : ''}`;
    const className = `mc-technical-field${field.required ? ' is-required' : ''}`;
    const meta = `<span class="mc-technical-field__meta">${field.required ? '必填' : '可补充'} · 建议：技术资料 · 状态：${current === '' || current === 'unknown' || (Array.isArray(current) && !current.length) ? '待确认' : '已填写'}</span>`;
    if (field.type === 'textarea') return `<label class="${className} mc-technical-field--wide"><span>${label}</span><textarea name="${esc(field.key)}" placeholder="${esc(field.placeholder || '填写说明')}">${esc(current)}</textarea>${meta}</label>`;
    if (field.type === 'select') return `<label class="${className}"><span>${label}</span><select name="${esc(field.key)}">${Object.entries(field.options || {}).map(([key, item]) => `<option value="${esc(key)}" ${String(current) === key ? 'selected' : ''}>${esc(item)}</option>`).join('')}</select>${meta}</label>`;
    if (field.type === 'multi') return `<fieldset class="${className} mc-technical-field--wide"><legend>${label}</legend><div class="mc-technical-checks">${Object.entries(field.options || {}).map(([key, item]) => `<label><input type="checkbox" name="${esc(field.key)}[]" value="${esc(key)}" ${Array.isArray(current) && current.includes(key) ? 'checked' : ''}>${esc(item)}</label>`).join('')}</div>${meta}</fieldset>`;
    return `<label class="${className}"><span>${label}</span><input name="${esc(field.key)}" type="${field.type === 'number' ? 'number' : 'text'}" ${field.type === 'number' ? 'min="0" step="0.01"' : ''} value="${esc(current)}" placeholder="${esc(field.placeholder || '待确认')}">${meta}</label>`;
  };

  const renderFailure = (error) => {
    const message = error instanceof Error ? error.message : String(error || '页面暂时无法显示。');
    root.innerHTML = `<section class="mc-empty-state mc-empty-state--error"><h2>产品适配页面未能加载</h2><p>${esc(message)}</p><button class="mc-button mc-button--primary" type="button" data-v3-retry>重新加载</button><button class="mc-button" type="button" data-v3-products>返回全部产品</button></section>`;
  };

  const render = () => {
    try {
      if (bootstrap.pageLoadError) { renderFailure(bootstrap.pageLoadError); return; }
      if (state.view === 'workspace') renderWorkspace(); else if (state.view === 'products') renderProducts(); else renderHome();
    } catch (error) { renderFailure(error); }
  };

  page.addEventListener('click', (event) => {
    const trigger = event.target.closest('button'); if (!trigger) return;
    if (trigger.dataset.v3Home !== undefined) { navigate('home'); return; }
    if (trigger.dataset.v3Products !== undefined) { navigate('products'); return; }
    if (trigger.dataset.v3Disabled !== undefined) { toast('当前处于基础页面止损修复阶段，此功能暂未开放。', 'info'); return; }
    if (trigger.dataset.v3Filter !== undefined) { state.status = trigger.dataset.v3Filter; state.page = 1; navigate('products', {}, true); return; }
    if (trigger.dataset.v3OpenProduct) { openWorkspace(Number(trigger.dataset.v3OpenProduct)); return; }
    if (trigger.dataset.v3Step) { state.step = Number(trigger.dataset.v3Step); navigate('workspace', { step: state.step }); return; }
    if (trigger.dataset.v3Page) { state.page = Math.max(1, Number(trigger.dataset.v3Page)); render(); return; }
    if (trigger.dataset.v3Refresh !== undefined || trigger.dataset.v3Retry !== undefined) { window.location.reload(); }
  });

  root.addEventListener('input', (event) => {
    if (event.target.matches('[data-v3-search]')) { state.query = event.target.value; state.page = 1; renderProducts(); }
  });
  root.addEventListener('change', (event) => {
    if (event.target.matches('[data-v3-sort]')) { state.sort = event.target.value; state.page = 1; renderProducts(); }
    if (event.target.matches('[data-v3-page-size]')) { state.pageSize = Number(event.target.value) || 50; state.page = 1; renderProducts(); }
  });
  root.addEventListener('submit', async (event) => {
    const form = event.target.closest('[data-v3-technical-form]'); if (!form) return;
    event.preventDefault();
    if (state.busy || !state.workspace?.product?.id) return;
    const submitter = event.submitter; const mode = submitter?.dataset.v3Save || 'draft';
    const profile = {};
    new FormData(form).forEach((value, key) => {
      if (key.endsWith('[]')) { const name = key.slice(0, -2); (profile[name] ||= []).push(value); }
      else profile[key] = value;
    });
    const fields = Array.isArray(state.workspace.technical_profile?.fields) ? state.workspace.technical_profile.fields : [];
    if (mode !== 'draft') {
      const missing = fields.filter((field) => field.required && (profile[field.key] === undefined || profile[field.key] === '' || profile[field.key] === 'unknown' || (Array.isArray(profile[field.key]) && !profile[field.key].length)));
      if (missing.length) {
        form.querySelectorAll('.mc-technical-field.is-error').forEach((node) => node.classList.remove('is-error'));
        missing.forEach((field) => form.querySelector(`[name="${CSS.escape(field.key)}"], [name="${CSS.escape(field.key)}[]"]`)?.closest('.mc-technical-field')?.classList.add('is-error'));
        form.querySelector(`[name="${CSS.escape(missing[0].key)}"], [name="${CSS.escape(missing[0].key)}[]"]`)?.focus();
        toast(`请先填写必填项目：${missing.map((field) => field.label).join('、')}`, 'error');
        return;
      }
    }
    state.busy = true; form.querySelectorAll('button').forEach((button) => { button.disabled = true; });
    try {
      const action = mode === 'draft' ? 'save_technical_draft' : 'save_technical_profile';
      const saved = await api(action, { product_id: String(state.workspace.product.id), profile: JSON.stringify(profile) }, 'POST');
      state.workspace.technical_profile = saved;
      if (mode === 'enter-core') { toast('技术范围已保存。核心物料步骤当前暂停开放。'); state.step = 2; navigate('workspace', { step: 2 }); }
      else { toast(mode === 'confirm' ? '技术范围已确认保存。' : '技术范围草稿已保存。'); render(); }
    } catch (error) {
      toast(error instanceof Error ? error.message : '保存失败。', 'error');
      form.querySelectorAll('button').forEach((button) => { button.disabled = false; });
    } finally { state.busy = false; }
  });

  window.addEventListener('popstate', () => {
    const query = new URLSearchParams(window.location.search); const view = query.get('view') || 'home'; const id = Number(query.get('product_id') || 0); const step = Math.min(6, Math.max(1, Number(query.get('step')) || 1));
    state.step = step;
    if (view === 'workspace' && id && Number(state.workspace?.product?.id) !== id) openWorkspace(id, step); else { state.view = ['home','products','workspace'].includes(view) ? view : 'home'; render(); }
  });

  window.addEventListener('error', (event) => { if (event.error) console.error('[adaptation baseline]', event.error); });
  render();
})();
