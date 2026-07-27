(() => {
  const root = document.querySelector('[data-stock-quote]');
  if (!root) return;
  const $ = (selector, scope = root) => scope.querySelector(selector);
  const $$ = (selector, scope = root) => [...scope.querySelectorAll(selector)];
  const api = root.dataset.api;
  const body = $('[data-stock-lines]');
  let csrf = '';
  let quoteId = Number(root.dataset.quoteId || 0);
  let data = { customers: [], stock_skus: [] };
  const field = (name) => $(`[data-stock-field="${name}"]`);
  const tell = (text, error = false) => {
    const node = $('[data-stock-message]');
    node.textContent = text;
    node.classList.toggle('error', error);
  };
  const request = async (payload = null, query = '') => {
    const response = await fetch(`${api}${query}`, payload ? {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ ...payload, csrf }),
    } : {});
    const result = await response.json();
    if (!response.ok || !result.ok) throw new Error(result.message || '库存品报价请求失败。');
    return result;
  };
  const recalculate = () => {
    let subtotal = 0;
    $$('tr', body).forEach((row, index) => {
      row.firstElementChild.textContent = String(index + 1);
      const amount = Number($('[data-qty]', row).value || 0) * Number($('[data-price]', row).value || 0);
      $('[data-line-total]', row).textContent = amount.toFixed(2);
      subtotal += amount;
    });
    $('[data-stock-subtotal]').textContent = subtotal.toFixed(2);
    $('[data-stock-total]').textContent = Math.max(0, subtotal
      - Number($('[data-stock-discount]').value || 0)
      + Number($('[data-stock-shipping]').value || 0)
      + Number($('[data-stock-tax]').value || 0)).toFixed(2);
    $('[data-stock-line-count]').textContent = `共 ${$$('tr', body).length} 项`;
  };
  const addRow = (item) => {
    const result = item.configuration_snapshot?.result || {};
    const stock = item.configuration_snapshot?.stock_at_quote || {};
    const row = document.createElement('tr');
    row.dataset.productKey = item.configuration_snapshot?.request?.product_key || `stock:${item.inventory_sku_id}`;
    row.innerHTML = `<td></td><td><b data-sku></b></td><td><span data-name></span></td><td><small data-config></small></td>
      <td data-stock></td><td><input data-qty type="number" min=".001" step=".001"></td>
      <td><input data-price type="number" min="0" step=".01"></td><td data-line-total>0.00</td>
      <td><input data-note></td><td><button type="button" class="text-danger" data-remove>删除</button></td>`;
    $('[data-sku]', row).textContent = item.sku_code || result.product?.code || '';
    $('[data-name]', row).textContent = item.product_name || item.description || '';
    $('[data-config]', row).textContent = result.summary || '库存 SKU 固定配置';
    $('[data-stock]', row).textContent = stock.sellable_stock ?? item.custom_fields?.sellable_stock_at_quote ?? '—';
    $('[data-qty]', row).value = item.quantity || 1;
    $('[data-price]', row).value = item.unit_price || 0;
    $('[data-note]', row).value = item.customer_note || '';
    body.append(row);
    recalculate();
  };
  const renderBootstrap = () => {
    const customers = field('customer_id');
    customers.innerHTML = '<option value="">选择 CRM 客户</option>';
    (data.customers || []).forEach((customer) => {
      const option = document.createElement('option');
      option.value = customer.id;
      option.textContent = `${customer.customer_code || ''} ${customer.customer_name || customer.customer_name_en || ''}`.trim();
      option.dataset.customer = JSON.stringify(customer);
      customers.append(option);
    });
    renderSkuOptions();
  };
  const renderSkuOptions = () => {
    const singapore = field('sales_channel').value === 'singapore_web';
    const picker = $('[data-stock-picker]');
    picker.innerHTML = '<option value="">选择库存 SKU</option>';
    (data.stock_skus || []).filter((sku) => !singapore || sku.can_sell_singapore).forEach((sku) => {
      const option = document.createElement('option');
      option.value = `stock:${sku.id}`;
      option.textContent = `${sku.sku_code} · ${sku.model_no || ''} ${sku.product_name || ''} · 可售 ${sku.sellable_stock}`;
      picker.append(option);
    });
    $('[data-stock-picker-message]').textContent = singapore
      ? '新加坡渠道仅显示已完成模拟发布并允许下单的 SKU。'
      : '广州直接销售显示全部有效库存 SKU。';
  };
  const renderQuote = (quote) => {
    if (!quote) return;
    quoteId = Number(quote.id);
    root.dataset.quoteId = String(quoteId);
    field('quote_no').value = quote.quote_no;
    field('customer_id').value = quote.legacy_customer_id || '';
    field('contact_name').value = quote.contact_name || '';
    field('country').value = quote.country || '';
    field('sales_channel').value = quote.sales_channel || 'guangzhou_direct';
    field('currency').value = quote.currency || 'USD';
    field('valid_until').value = quote.valid_until || '';
    field('payment_terms').value = quote.payment_terms || '';
    field('trade_terms').value = quote.trade_terms || '';
    $('[data-stock-discount]').value = quote.discount_amount || 0;
    $('[data-stock-shipping]').value = quote.shipping_amount || 0;
    $('[data-stock-tax]').value = quote.tax_amount || 0;
    $('[data-stock-status]').textContent = `${quote.status} · ${quote.push_status || 'not_required'}`;
    body.innerHTML = '';
    (quote.items || []).forEach(addRow);
    renderSkuOptions();
    recalculate();
  };
  const linePayload = (row) => ({
    configuration_request: { product_key: row.dataset.productKey, mode: 'quick', values: {} },
    quantity: Number($('[data-qty]', row).value || 0),
    unit_price: Number($('[data-price]', row).value || 0),
    customer_note: $('[data-note]', row).value || '',
  });
  const quotePayload = () => ({
    id: quoteId,
    customer_id: Number(field('customer_id').value || 0),
    contact_name: field('contact_name').value,
    country: field('country').value,
    sales_channel: field('sales_channel').value,
    currency: field('currency').value,
    valid_until: field('valid_until').value || null,
    payment_terms: field('payment_terms').value,
    trade_terms: field('trade_terms').value,
    discount_amount: Number($('[data-stock-discount]').value || 0),
    shipping_amount: Number($('[data-stock-shipping]').value || 0),
    tax_amount: Number($('[data-stock-tax]').value || 0),
    items: $$('tr', body).map(linePayload),
  });
  const save = async () => {
    const result = await request({ action: 'save', quote: quotePayload() });
    renderQuote(result.quote);
    history.replaceState(null, '', `?page=quote_center&quote_mode=stock&quote_id=${quoteId}`);
    tell(result.message);
    return result.quote;
  };
  $('[data-stock-apply]').addEventListener('click', async () => {
    try {
      const productKey = $('[data-stock-picker]').value;
      if (!productKey) throw new Error('请选择库存 SKU。');
      const result = await request({
        action: 'prepare_item',
        customer_id: Number(field('customer_id').value || 0),
        sales_channel: field('sales_channel').value,
        item: {
          configuration_request: { product_key: productKey, mode: 'quick', values: {} },
          quantity: Number($('[data-stock-picker-qty]').value || 1),
        },
      });
      addRow(result.item);
      $('[data-stock-picker-message]').textContent = (result.item.warnings || []).join('；') || '库存与配置检查通过。';
    } catch (error) {
      $('[data-stock-picker-message]').textContent = error.message;
    }
  });
  $$('[data-stock-add]').forEach((button) => button.addEventListener('click', () => $('[data-stock-picker]').focus()));
  $('[data-stock-batch-qty]').addEventListener('click', () => {
    const value = prompt('输入统一数量');
    if (value !== null && Number(value) > 0) $$('[data-qty]', body).forEach((input) => { input.value = value; });
    recalculate();
  });
  $('[data-stock-save]').addEventListener('click', () => save().catch((error) => tell(error.message, true)));
  $('[data-stock-submit]').addEventListener('click', async () => {
    try {
      if (!quoteId) await save();
      renderQuote((await request({ action: 'submit', quote_id: quoteId })).quote);
      tell('库存品报价已提交审核。');
    } catch (error) { tell(error.message, true); }
  });
  $('[data-stock-queue-order]').addEventListener('click', async () => {
    try {
      if (!quoteId) throw new Error('请先保存报价。');
      const channelResponse = await fetch('api/v1/singapore_channel.php');
      const channelData = await channelResponse.json();
      if (!channelResponse.ok || !channelData.ok) throw new Error(channelData.message || '无法建立渠道会话。');
      const response = await fetch('api/v1/singapore_channel.php', {
        method: 'POST', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'queue_order', quote_id: quoteId, csrf: channelData.csrf }),
      });
      const result = await response.json();
      if (!response.ok || !result.ok) throw new Error(result.message || '加入待发送失败。');
      tell(result.message);
    } catch (error) { tell(error.message, true); }
  });
  root.addEventListener('input', (event) => {
    if (event.target.matches('[data-qty],[data-price],[data-stock-discount],[data-stock-shipping],[data-stock-tax]')) recalculate();
  });
  body.addEventListener('click', (event) => {
    const remove = event.target.closest('[data-remove]');
    if (remove) { remove.closest('tr').remove(); recalculate(); }
  });
  field('customer_id').addEventListener('change', (event) => {
    const customer = JSON.parse(event.target.selectedOptions[0]?.dataset.customer || '{}');
    field('contact_name').value = customer.contact_name || '';
    field('country').value = customer.country || '';
  });
  field('sales_channel').addEventListener('change', () => {
    if (field('sales_channel').value === 'singapore_web') field('currency').value = 'SGD';
    renderSkuOptions();
  });
  request(null, quoteId ? `?quote_id=${quoteId}` : '').then((result) => {
    csrf = result.csrf;
    data = result.data || data;
    renderBootstrap();
    if (result.quote) renderQuote(result.quote);
  }).catch((error) => tell(error.message, true));
})();
