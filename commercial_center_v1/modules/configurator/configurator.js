(() => {
  const root = document.querySelector('[data-configurator]');
  if (!root || root.dataset.authenticated !== '1') return;
  const api = '../../api/v1/configurator.php';
  const $ = (selector) => root.querySelector(selector);
  const state = { csrf: '', catalog: null, productKey: '', presetId: 0, mode: 'quick', values: {}, currentPrice: 0, customerId: 0, vm: null, timer: 0 };
  const feedback = (message, error = false) => { $('[data-feedback]').textContent = message; $('[data-feedback]').style.color = error ? '#b42318' : ''; };
  const request = async (payload = null, query = '') => {
    const options = payload ? { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ ...payload, csrf: state.csrf }) } : {};
    const response = await fetch(api + query, options);
    const data = await response.json();
    if (!response.ok || !data.ok) throw Object.assign(new Error(data.message || '配置服务不可用'), { data });
    return data;
  };
  const load = async (customerId = 0) => {
    const data = await request(null, customerId ? `?customer_id=${customerId}` : '');
    state.csrf = data.csrf; state.catalog = data.catalog; state.customerId = customerId;
    renderCatalog();
  };
  const renderCatalog = () => {
    if (!state.presetId) state.presetId = Number(state.catalog.presets.find((p) => p.preset_type === 'factory_standard')?.id || state.catalog.presets[0]?.id || 0);
    const product = $('[data-product]');
    product.innerHTML = '<option value="">请选择产品</option>';
    const stock = document.createElement('optgroup'); stock.label = '库存 SKU（核心配置锁定）';
    state.catalog.stock_skus.forEach((row) => stock.insertAdjacentHTML('beforeend', `<option value="stock:${row.id}">${escapeHtml(row.sku_code)} · ${escapeHtml(row.model_no || '')}${Number(row.is_test) ? ' [测试]' : ''}</option>`));
    product.append(stock);
    const standard = document.createElement('optgroup'); standard.label = '无库存标准品';
    state.catalog.products.forEach((row) => standard.insertAdjacentHTML('beforeend', `<option value="standard:${row.id}">${escapeHtml(row.model_no)} · ${escapeHtml(row.product_name)}</option>`));
    product.append(standard);
    const custom = document.createElement('optgroup'); custom.label = '基于标准品定制';
    state.catalog.products.slice(0, 30).forEach((row) => custom.insertAdjacentHTML('beforeend', `<option value="custom:${row.id}">${escapeHtml(row.model_no)} · ${escapeHtml(row.product_name)}</option>`));
    renderPresets(); renderGroups(); renderComparison();
  };
  const renderPresets = () => {
    $('[data-presets]').innerHTML = state.catalog.presets.map((p) => `<button type="button" class="preset-card ${Number(p.id) === state.presetId ? 'active' : ''}" data-preset="${p.id}"><b>${escapeHtml(p.name)}</b><small>${escapeHtml(scopeLabel(p.scope_type))} · V${p.version_no}</small></button>`).join('');
    root.querySelectorAll('[data-preset]').forEach((button) => button.addEventListener('click', () => { state.presetId = Number(button.dataset.preset); state.values = {}; renderPresets(); evaluate(); }));
  };
  const renderGroups = () => {
    const key = $('[data-key-groups]'); const advanced = $('[data-advanced-groups]'); key.replaceChildren(); advanced.replaceChildren();
    state.catalog.groups.forEach((group) => {
      const box = document.createElement('label'); box.className = 'config-group'; box.dataset.group = group.group_code;
      const required = Number(group.is_required) ? ' *' : ''; let control;
      if (['number', 'text'].includes(group.input_type)) { control = document.createElement('input'); control.type = group.input_type === 'number' ? 'number' : 'text'; }
      else { control = document.createElement('select'); control.multiple = group.input_type === 'multiple'; control.innerHTML = `${control.multiple ? '' : '<option value="">请选择</option>'}${group.options.map((o) => `<option value="${escapeHtml(o.option_code)}">${escapeHtml(o.name)}</option>`).join('')}`; }
      control.dataset.configValue = group.group_code; setControlValue(control,state.values[group.group_code]); control.addEventListener('change', () => { state.values[group.group_code] = control.multiple ? Array.from(control.selectedOptions).map((o) => o.value) : control.value; scheduleEvaluate(); });
      box.insertAdjacentHTML('beforeend', `<span>${escapeHtml(group.name)}${required}</span>`); box.append(control);
      (Number(group.is_advanced) ? advanced : key).append(box);
    });
  };
  const payload = (action = 'evaluate') => ({ action, product_key: state.productKey, preset_id: state.presetId, mode: state.mode, values: state.values, current_price: state.currentPrice, customer_id: state.customerId, quantity: Number($('[data-quantity]').value || 1) });
  const scheduleEvaluate = () => { clearTimeout(state.timer); state.timer = setTimeout(evaluate, 180); };
  const evaluate = async () => {
    if (!state.productKey) return resetPassport();
    try {
      const data = await request(payload()); state.vm = data.configuration; state.values = { ...state.vm.values }; state.currentPrice = Number(state.vm.pricing.current_price);
      syncControls(); renderPassport();
    } catch (error) { feedback(error.message, true); if (error.data?.configuration) { state.vm = error.data.configuration; renderPassport(); } }
  };
  const syncControls = () => {
    root.querySelectorAll('[data-config-value]').forEach((control) => {
      const code = control.dataset.configValue; setControlValue(control,state.values[code]);
      const box = control.closest('.config-group'); const lock = state.vm?.locks?.[code]; control.disabled = Boolean(lock);
      box.classList.toggle('locked', Boolean(lock)); box.querySelector('.lock')?.remove();
      if (lock) box.insertAdjacentHTML('beforeend', `<span class="lock" title="${escapeHtml(lock.reason)}">🔒 ${escapeHtml(lock.type)}</span>`);
    });
  };
  const renderPassport = () => {
    const vm = state.vm; if (!vm) return;
    $('[data-passport-product]').textContent = `${vm.product.code} · ${vm.product.name}${vm.product.is_test ? ' [测试]' : ''}`;
    $('[data-passport-preset]').textContent = vm.preset_name; $('[data-passport-status]').textContent = statusLabel(vm.status);
    $('[data-cost]').textContent = `USD ${money(vm.pricing.cost)}`; $('[data-suggested]').textContent = `USD ${money(vm.pricing.suggested_price)}`;
    $('[data-current-price]').value = vm.pricing.current_price; $('[data-margin]').textContent = `${vm.pricing.margin_percent}%`;
    $('[data-moq]').textContent = vm.moq; $('[data-lead]').textContent = `${vm.lead_time_days} 天`;
    $('[data-approval]').textContent = vm.approval.required ? `需要审批：${vm.approval.reasons.join('、')}` : '无需审批';
    $('[data-summary]').textContent = vm.summary || '暂无配置摘要'; $('[data-hash]').textContent = vm.passport_hash;
    const light = $('[data-status-light]'); light.className = vm.status;
    $('[data-messages]').innerHTML = vm.messages.map((m) => `<div class="message ${m.type}">${escapeHtml(m.message)}</div>`).join('');
    feedback(vm.status === 'blocked' ? '当前配置存在禁止项，不能加入报价。' : '配置已由服务端检查。', vm.status === 'blocked');
  };
  const resetPassport = () => { state.vm = null; $('[data-passport-product]').textContent = '未选择'; $('[data-passport-status]').textContent = '等待配置'; $('[data-summary]').textContent = '请选择产品。'; };
  const renderComparison = async () => {
    const targets = ['economy', 'standard', 'premium'].map((type) => state.catalog.presets.find((p) => p.preset_type === type)).filter(Boolean);
    $('[data-comparison]').innerHTML = targets.map((p) => `<button type="button" class="compare-card" data-compare="${p.id}"><b>${escapeHtml(p.name)}</b><span>选择产品后计算</span></button>`).join('');
    root.querySelectorAll('[data-compare]').forEach((button) => button.addEventListener('click', () => { state.presetId = Number(button.dataset.compare); state.values = {}; renderPresets(); evaluate(); }));
    if (!state.productKey) return;
    await Promise.all(targets.map(async (p) => { try { const data = await request({ ...payload(), preset_id: Number(p.id), values: {} }); const card = root.querySelector(`[data-compare="${p.id}"] span`); if (card) card.textContent = `USD ${money(data.configuration.pricing.suggested_price)} · MOQ ${data.configuration.moq} · ${statusLabel(data.configuration.status)}`; } catch (_) {} }));
  };
  $('[data-product]').addEventListener('change', (event) => { state.productKey = event.target.value; state.values = {}; evaluate(); renderComparison(); });
  root.querySelectorAll('[data-mode]').forEach((button) => button.addEventListener('click', () => { state.mode = button.dataset.mode; root.querySelectorAll('[data-mode]').forEach((b) => b.classList.toggle('active', b === button)); evaluate(); }));
  $('[data-reset]').addEventListener('click', () => { state.values = {}; evaluate(); });
  $('[data-current-price]').addEventListener('change', (event) => { state.currentPrice = Number(event.target.value || 0); evaluate(); });
  $('[data-save-personal]').addEventListener('click', async () => savePreset('personal'));
  $('[data-save-customer]').addEventListener('click', async () => savePreset('customer'));
  $('[data-customer-last]').addEventListener('click', async () => { const id = Number(prompt('请输入现有客户ID：') || 0); if (!id) return; await load(id); const p = state.catalog.presets.find((x) => x.scope_type === 'customer'); if (!p) return feedback('该客户暂无已保存配置预设。', true); state.presetId = Number(p.id); state.values = {}; renderPresets(); evaluate(); });
  $('[data-copy]').addEventListener('click', async () => { if (!state.vm) return; try { await navigator.clipboard.writeText(JSON.stringify(state.vm, null, 2)); feedback('配置护照已复制。'); } catch (_) { feedback('浏览器未授权复制，请稍后重试。', true); } });
  $('[data-add-quote]').addEventListener('click', async () => { if (!state.vm) return feedback('请先完成配置。', true); try { const data = await request(payload('add_to_quote')); feedback(`${data.message} ${data.quote.quote_no}`); } catch (error) { feedback(error.message, true); } });
  async function savePreset(scope) { if (!state.vm) return feedback('请先完成配置。', true); let customerId = state.customerId; if (scope === 'customer' && !customerId) customerId = Number(prompt('请输入现有客户ID：') || 0); if (scope === 'customer' && !customerId) return; const name = prompt('预设名称：', scope === 'customer' ? '客户专属配置' : '我的配置') || ''; if (!name.trim()) return; try { const data = await request({ ...payload('save_preset'), scope, customer_id: customerId, name }); feedback(data.message); await load(customerId); } catch (error) { feedback(error.message, true); } }
  function statusLabel(status) { return ({ valid: '配置有效', warning: '存在警告', approval: '需要审批', blocked: '禁止配置' })[status] || status; }
  function scopeLabel(scope) { return ({ global: '全局', channel: '渠道', personal: '个人', customer: '客户' })[scope] || scope; }
  function money(value) { return Number(value || 0).toFixed(2); }
  function setControlValue(control,value) { if (control.multiple) { const selected = Array.isArray(value) ? value : (value ? [value] : []); Array.from(control.options).forEach((option) => { option.selected = selected.includes(option.value); }); } else control.value = value ?? ''; }
  function escapeHtml(value) { return String(value ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' })[c]); }
  load().catch((error) => feedback(error.message, true));
})();
