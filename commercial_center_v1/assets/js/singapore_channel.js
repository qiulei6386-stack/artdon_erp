(() => {
  const root = document.querySelector('[data-singapore-channel]');
  if (!root) return;
  const $ = (selector, scope = root) => scope.querySelector(selector);
  const api = root.dataset.api;
  const form = $('[data-sg-package-form]');
  let csrf = '';
  let state = { stock_skus: [], published_products: [], packages: [], outbox: [], counts: {} };
  const tell = (text, error = false) => {
    const node = $('[data-sg-message]');
    node.textContent = text;
    node.classList.toggle('error', error);
  };
  const request = async (payload = null) => {
    const response = await fetch(api, payload ? {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ ...payload, csrf }),
    } : {});
    const data = await response.json();
    if (!response.ok || !data.ok) throw new Error(data.message || '新加坡渠道请求失败。');
    return data;
  };
  const parseParameters = (text) => Object.fromEntries(String(text || '').split('\n').map((line) => line.split('='))
    .filter((pair) => pair.length >= 2 && pair[0].trim())
    .map((pair) => [pair.shift().trim(), pair.join('=').trim()]));
  const parameterText = (value) => Object.entries(typeof value === 'object' && value ? value : {})
    .map(([key, item]) => `${key}=${item}`).join('\n');
  const render = () => {
    $('[data-sg-adapter]').textContent = state.adapter?.status || 'not_configured';
    Object.entries(state.counts || {}).forEach(([key, value]) => {
      const node = $(`[data-sg-count="${key}"]`);
      if (node) node.textContent = value;
    });
    const skuSelect = form.elements.inventory_sku_id;
    skuSelect.innerHTML = '<option value="">选择库存 SKU</option>';
    (state.stock_skus || []).forEach((sku) => {
      const option = document.createElement('option');
      option.value = sku.id;
      option.textContent = `${sku.sku_code} · ${sku.model_no || ''} ${sku.product_name || ''} · 可售 ${sku.sellable_stock}`;
      skuSelect.append(option);
    });
    const published = $('[data-sg-published-products]');
    published.innerHTML = '';
    if (!(state.published_products || []).length) published.innerHTML = '<tr><td colspan="6">暂无物料中心已发布产品。</td></tr>';
    (state.published_products || []).forEach((item) => {
      const row = document.createElement('tr');
      const schemes = item.commercial_configuration?.schemes || [];
      row.dataset.publishedProductId = item.id;
      const publication = item.singapore_publication || {};
      const published = publication.sync_status === 'published';
      row.innerHTML = `<td><b></b></td><td></td><td></td><td></td><td></td><td><span class="quote-status"></span> <button type="button" ${published ? 'data-sg-unpublish-product' : 'data-sg-publish-product'}></button></td>`;
      row.children[0].firstElementChild.textContent = item.model_no;
      row.children[1].textContent = item.product_name || item.model_no;
      row.children[2].textContent = `${item.category || '—'} / ${item.series_name || '—'}`;
      row.children[3].textContent = item.commercial_version_no || '—';
      row.children[4].textContent = schemes.length ? schemes.map((scheme) => scheme.code || scheme.name).join(' / ') : '—';
      row.children[5].querySelector('span').textContent = publication.sync_status || '未发布';
      row.children[5].querySelector('button').textContent = published ? '下架' : (publication.sync_status === 'withdrawn' ? '重新上架' : '生成发布任务');
      published.append(row);
    });
    const packages = $('[data-sg-packages]');
    packages.innerHTML = '';
    if (!(state.packages || []).length) packages.innerHTML = '<tr><td colspan="7">暂无套餐。请从左侧选择库存 SKU 建立第一条公开套餐。</td></tr>';
    (state.packages || []).forEach((item) => {
      const row = document.createElement('tr');
      row.dataset.package = JSON.stringify(item);
      row.innerHTML = `<td><b></b><small></small></td><td></td><td></td><td></td><td></td><td><span class="quote-status"></span></td>
        <td><button type="button" data-sg-edit>编辑</button><button type="button" data-sg-queue>进入待发送</button></td>`;
      row.children[0].querySelector('b').textContent = item.package_code;
      row.children[0].querySelector('small').textContent = item.sku_code;
      row.children[1].textContent = item.english_name;
      row.children[2].textContent = `SGD ${Number(item.public_price).toFixed(2)}`;
      row.children[3].textContent = item.sellable_stock;
      row.children[4].textContent = Number(item.allow_order) ? '允许下单' : '仅询价';
      row.children[5].firstElementChild.textContent = item.status;
      packages.append(row);
    });
    const outbox = $('[data-sg-outbox]');
    outbox.innerHTML = '';
    if (!(state.outbox || []).length) outbox.innerHTML = '<tr><td colspan="9">暂无待发送记录。</td></tr>';
    (state.outbox || []).forEach((item) => {
      const row = document.createElement('tr');
      row.dataset.outboxId = item.id;
      row.innerHTML = `<td></td><td></td><td></td><td><span class="quote-status"></span></td><td></td><td></td><td></td><td></td>
        <td><button type="button" data-sg-send>真实发送</button><button type="button" data-sg-simulate>模拟发送</button><button type="button" data-sg-retry>重试</button></td>`;
      row.children[0].textContent = item.id;
      row.children[1].textContent = item.operation_type === 'product_publish' ? '产品发布' : '代客订单';
      row.children[2].textContent = `${item.entity_type} #${item.entity_id}`;
      row.children[3].firstElementChild.textContent = item.status;
      row.children[4].textContent = `${item.attempts}/${item.max_attempts}`;
      row.children[5].textContent = item.external_reference || '—';
      row.children[6].textContent = item.last_error || '—';
      row.children[7].textContent = item.updated_at || item.created_at;
      row.querySelector('[data-sg-simulate]').hidden = !['pending', 'failed'].includes(item.status);
      row.querySelector('[data-sg-send]').hidden = !['pending', 'failed'].includes(item.status);
      row.querySelector('[data-sg-retry]').hidden = item.status !== 'failed';
      outbox.append(row);
    });
  };
  $('[data-sg-published-products]').addEventListener('click', async (event) => {
    const row = event.target.closest('tr[data-published-product-id]');
    if (!row) return;
    const unpublish = event.target.closest('[data-sg-unpublish-product]');
    const publish = event.target.closest('[data-sg-publish-product]');
    if (!unpublish && !publish) return;
    try {
      let payload = { action: 'queue_published_product', legacy_product_id: Number(row.dataset.publishedProductId) };
      if (unpublish) {
        const reason = window.prompt('请输入下架原因', '停止销售');
        if (reason === null) return;
        payload = { action: 'queue_unpublish_product', legacy_product_id: Number(row.dataset.publishedProductId), reason };
      }
      const result = await request(payload);
      tell(result.message);
      await reload();
    } catch (error) { tell(error.message, true); }
  });
  const reload = async () => {
    const result = await request();
    csrf = result.csrf;
    state = result.data || state;
    render();
  };
  form.addEventListener('submit', async (event) => {
    event.preventDefault();
    const values = Object.fromEntries(new FormData(form));
    values.id = Number(values.id || 0);
    values.inventory_sku_id = Number(values.inventory_sku_id || 0);
    values.public_price = Number(values.public_price || 0);
    values.moq = Number(values.moq || 1);
    values.lead_time_days = Number(values.lead_time_days || 0);
    values.publishable = form.elements.publishable.checked;
    values.allow_order = form.elements.allow_order.checked;
    values.public_parameters = parseParameters(values.public_parameters);
    try {
      const result = await request({ action: 'save_package', package: values });
      tell(result.message);
      form.reset();
      form.elements.id.value = '';
      await reload();
    } catch (error) { tell(error.message, true); }
  });
  $('[data-sg-packages]').addEventListener('click', async (event) => {
    const row = event.target.closest('tr[data-package]');
    if (!row) return;
    const item = JSON.parse(row.dataset.package);
    if (event.target.closest('[data-sg-edit]')) {
      ['id','inventory_sku_id','package_code','public_title','english_name','public_price','moq','lead_time_days']
        .forEach((name) => { form.elements[name].value = item[name] ?? ''; });
      form.elements.public_parameters.value = parameterText(JSON.parse(item.public_parameters || '{}'));
      form.elements.publishable.checked = Number(item.publishable) === 1;
      form.elements.allow_order.checked = Number(item.allow_order) === 1;
      form.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }
    if (event.target.closest('[data-sg-queue]')) {
      try {
        const result = await request({ action: 'queue_product', package_id: Number(item.id) });
        tell(result.message);
        await reload();
      } catch (error) { tell(error.message, true); }
    }
  });
  $('[data-sg-outbox]').addEventListener('click', async (event) => {
    const row = event.target.closest('tr[data-outbox-id]');
    if (!row) return;
    const action = event.target.closest('[data-sg-send]') ? 'send'
      : (event.target.closest('[data-sg-retry]') ? 'retry'
        : (event.target.closest('[data-sg-simulate]') ? 'simulate' : ''));
    if (!action) return;
    try {
      const result = await request({ action, outbox_id: Number(row.dataset.outboxId) });
      tell(result.message);
      await reload();
    } catch (error) { tell(error.message, true); }
  });
  form.addEventListener('reset', () => { setTimeout(() => { form.elements.id.value = ''; }, 0); });
  reload().catch((error) => tell(error.message, true));
})();
