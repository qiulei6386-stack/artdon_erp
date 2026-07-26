(() => {
  const $ = (selector, root = document) => root.querySelector(selector);
  const $$ = (selector, root = document) => [...root.querySelectorAll(selector)];
  const openModal = (modal) => { if (!modal) return; modal.classList.add('open'); modal.setAttribute('aria-hidden', 'false'); document.body.classList.add('quote-modal-open'); };
  const closeModal = (modal) => { if (!modal) return; modal.classList.remove('open'); modal.setAttribute('aria-hidden', 'true'); if (!$('.quote-modal.open,.quote-config-modal.open')) document.body.classList.remove('quote-modal-open'); };

  const typeModal = $('[data-type-modal]');
  const detailModal = $('[data-detail-modal]');
  $('[data-new-quote]')?.addEventListener('click', () => openModal(typeModal));
  $$('[data-modal-close]').forEach((button) => button.addEventListener('click', () => closeModal(button.closest('.quote-modal,.quote-config-modal'))));
  $$('.quote-modal,.quote-config-modal').forEach((modal) => modal.addEventListener('click', (event) => { if (event.target === modal) closeModal(modal); }));
  $$('[data-quote-type]').forEach((button) => button.addEventListener('click', () => {
    const type = button.dataset.quoteType;
    closeModal(typeModal);
    if (!detailModal) return;
    $('[name="quote_mode"]', detailModal).value = type;
    $('[data-detail-title]', detailModal).textContent = `新建${button.querySelector('strong')?.textContent || '报价单'}`;
    $('[data-website-source]', detailModal).hidden = type !== 'website';
    $$('[data-standard-only]', detailModal).forEach((item) => item.hidden = type !== 'standard');
    $$('[data-custom-only]', detailModal).forEach((item) => item.hidden = type !== 'custom');
    $('[data-quick-create]', detailModal).hidden = type !== 'standard';
    openModal(detailModal);
  }));
  $('[data-create-form]')?.addEventListener('submit', (event) => {
    event.preventDefault();
    const form = event.currentTarget;
    const submitter = event.submitter;
    const type = $('[name="quote_mode"]', form).value;
    const quick = submitter?.name === 'quick' ? '&quick=1' : '';
    window.location.href = `?page=quote_center&quote_mode=${encodeURIComponent(type)}${quick}`;
  });

  const editor = $('[data-quote-editor]');
  if (!editor) return;
  if (editor.matches('[data-standard-quote]')) {
    const api = editor.dataset.api;
    const tbody = $('[data-quote-lines]', editor);
    let csrf = '';
    let bootstrap = { customers: [], configuration: { products: [], groups: [] }, commission_reminders: [] };
    let quoteId = Number(editor.dataset.quoteId || 0);
    let editingRow = null;
    const message = (text, error = false) => {
      const node = $('[data-save-message]', editor);
      if (!node) return;
      node.textContent = text;
      node.classList.toggle('error', error);
    };
    const request = async (payload = null, query = '') => {
      const options = payload ? {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ ...payload, csrf }),
      } : { method: 'GET' };
      const response = await fetch(`${api}${query}`, options);
      const data = await response.json();
      if (!response.ok || !data.ok) throw new Error(data.message || '标准品报价请求失败。');
      return data;
    };
    const field = (name) => $(`[data-field="${name}"]`, editor);
    const setField = (name, value) => { const input = field(name); if (input && value !== null && value !== undefined) input.value = value; };
    const defaultValues = () => {
      const values = {};
      (bootstrap.configuration.groups || []).forEach((group) => {
        const selected = (group.options || []).find((option) => Number(option.is_default) === 1) || group.options?.[0];
        if (selected) values[group.group_code] = selected.option_code;
      });
      return values;
    };
    const linePayload = (row) => {
      const price = Number($('[data-price]', row)?.value || 0);
      const payload = {
        configuration_request: {
          product_key: row.dataset.productKey,
          mode: 'professional',
          values: JSON.parse(row.dataset.configValues || '{}'),
        },
        quantity: Number($('[data-qty]', row)?.value || 0),
        discount_rate: Number($('[data-discount]', row)?.value || 0) / 100,
        customer_note: $('[data-line-note]', row)?.value || '',
      };
      if (price > 0) payload.unit_price = price;
      return payload;
    };
    const recalculate = () => {
      let subtotal = 0;
      $$('tr', tbody).forEach((row, index) => {
        const quantity = Number($('[data-qty]', row)?.value || 0);
        const price = Number($('[data-price]', row)?.value || 0);
        const discount = Number($('[data-discount]', row)?.value || 0);
        const total = Math.max(0, quantity * price * (1 - discount / 100));
        row.firstElementChild.textContent = String(index + 1);
        $('[data-line-total]', row).textContent = total.toFixed(2);
        subtotal += total;
      });
      $('[data-subtotal]', editor).textContent = subtotal.toFixed(2);
      const total = Math.max(0, subtotal
        - Number($('[data-order-discount]', editor)?.value || 0)
        + Number($('[data-shipping]', editor)?.value || 0)
        + Number($('[data-tax]', editor)?.value || 0)
        + Number($('[data-other]', editor)?.value || 0));
      $('[data-grand-total]', editor).textContent = total.toFixed(2);
      $('[data-line-count]', editor).textContent = `共 ${$$('tr', tbody).length} 项`;
    };
    const renderRow = (item, append = true) => {
      const configuration = item.configuration_snapshot?.result || item.configuration_snapshot?.result || {};
      const requestValues = item.configuration_snapshot?.request?.values || item.configuration_request?.values || {};
      const model = item.model_no || configuration.product?.code || '';
      const name = item.product_name || configuration.product?.name || item.description || '';
      const productId = item.legacy_product_id || configuration.product?.legacy_product_id || 0;
      const row = document.createElement('tr');
      row.draggable = true;
      row.dataset.productKey = item.configuration_snapshot?.request?.product_key || `standard:${productId}`;
      row.dataset.configValues = JSON.stringify(requestValues);
      row.innerHTML = `<td></td><td><div class="ref-thumb">图</div></td><td><b data-model></b></td>
        <td data-product-name></td><td data-config-summary><span></span><small></small></td>
        <td><input type="number" min=".001" step=".001" data-qty></td>
        <td><input type="number" min="0" step=".01" data-price></td>
        <td><input type="number" min="0" max="100" data-discount></td><td data-line-total>0.00</td>
        <td data-lead></td><td><input data-line-note></td><td><button type="button" data-configure>编辑</button>
        <button type="button" class="text-danger" data-remove-line>删除</button></td>`;
      $('[data-model]', row).textContent = model;
      $('[data-product-name]', row).textContent = name;
      $('span', $('[data-config-summary]', row)).textContent = configuration.summary || '工厂标准配置';
      $('small', $('[data-config-summary]', row)).textContent = item.pricing?.source || item.custom_fields?.price_source || '保存时重新校验';
      $('[data-qty]', row).value = item.quantity || 1;
      $('[data-price]', row).value = item.unit_price || item.pricing?.unit_price || 0;
      $('[data-discount]', row).value = Number(item.discount_rate || 0) * 100;
      $('[data-lead]', row).textContent = item.lead_time || `${item.pricing?.lead_time_days || 0} 天`;
      $('[data-line-note]', row).value = item.customer_note || '';
      if (append) tbody.append(row);
      return row;
    };
    const renderQuote = (quote) => {
      if (!quote) return;
      quoteId = Number(quote.id);
      editor.dataset.quoteId = String(quoteId);
      setField('quote_no', quote.quote_no);
      setField('customer_id', quote.legacy_customer_id);
      setField('contact_name', quote.contact_name);
      setField('country', quote.country);
      setField('currency', quote.currency);
      setField('valid_until', quote.valid_until);
      setField('payment_terms', quote.payment_terms);
      setField('trade_terms', quote.trade_terms);
      setField('quote_date', quote.quote_date);
      setField('project_ref', quote.project_ref);
      setField('exchange_rate', quote.exchange_rate_snapshot);
      setField('customer_note', quote.customer_note);
      setField('internal_note', quote.internal_note);
      $('[data-order-discount]', editor).value = quote.discount_amount || 0;
      $('[data-shipping]', editor).value = quote.shipping_amount || 0;
      $('[data-tax]', editor).value = quote.tax_amount || 0;
      $('[data-other]', editor).value = quote.other_amount || 0;
      $('[data-quote-status]', editor).textContent = quote.status;
      tbody.innerHTML = '';
      (quote.items || []).forEach((item) => renderRow(item));
      if (quote.total_cost !== undefined) $('[data-total-cost]', editor).textContent = Number(quote.total_cost).toFixed(2);
      if (quote.gross_profit !== undefined) $('[data-gross-profit]', editor).textContent = Number(quote.gross_profit).toFixed(2);
      if (quote.gross_margin !== undefined) $('[data-gross-margin]', editor).textContent = `${(Number(quote.gross_margin) * 100).toFixed(2)}%`;
      recalculate();
    };
    const renderBootstrap = () => {
      const customerSelect = field('customer_id');
      customerSelect.innerHTML = '<option value="">选择 CRM 客户</option>';
      bootstrap.customers.forEach((customer) => {
        const option = document.createElement('option');
        option.value = customer.id;
        option.textContent = `${customer.customer_code || ''} ${customer.customer_name || customer.customer_name_en || ''}`.trim();
        option.dataset.customer = JSON.stringify(customer);
        customerSelect.append(option);
      });
      const productSelect = $('[data-config-product]', editor);
      productSelect.innerHTML = '<option value="">选择真实产品</option>';
      (bootstrap.configuration.products || []).forEach((product) => {
        const option = document.createElement('option');
        option.value = `standard:${product.id}`;
        option.textContent = `${product.model_no} · ${product.product_name}`;
        option.dataset.product = JSON.stringify(product);
        productSelect.append(option);
      });
      const options = $('[data-config-options]', editor);
      options.innerHTML = '';
      (bootstrap.configuration.groups || []).forEach((group) => {
        const label = document.createElement('label');
        label.innerHTML = `<b>${group.name}</b><select data-config-group="${group.group_code}"></select>`;
        const select = $('select', label);
        (group.options || []).forEach((option) => {
          const node = document.createElement('option');
          node.value = option.option_code;
          node.textContent = option.name;
          node.selected = Number(option.is_default) === 1;
          select.append(node);
        });
        options.append(label);
      });
    };
    const quotePayload = () => ({
      id: quoteId,
      customer_id: Number(field('customer_id')?.value || 0),
      contact_name: field('contact_name')?.value || '',
      country: field('country')?.value || '',
      currency: field('currency')?.value || 'USD',
      valid_until: field('valid_until')?.value || null,
      payment_terms: field('payment_terms')?.value || '',
      trade_terms: field('trade_terms')?.value || '',
      quote_date: field('quote_date')?.value || '',
      project_ref: field('project_ref')?.value || '',
      exchange_rate: Number(field('exchange_rate')?.value || 1),
      customer_note: field('customer_note')?.value || '',
      internal_note: field('internal_note')?.value || '',
      discount_amount: Number($('[data-order-discount]', editor)?.value || 0),
      shipping_amount: Number($('[data-shipping]', editor)?.value || 0),
      tax_amount: Number($('[data-tax]', editor)?.value || 0),
      other_amount: Number($('[data-other]', editor)?.value || 0),
      items: $$('tr', tbody).map(linePayload),
    });
    const save = async () => {
      message('正在保存…');
      const data = await request({ action: 'save', quote: quotePayload() });
      renderQuote(data.quote);
      history.replaceState(null, '', `?page=quote_center&quote_mode=standard&quote_id=${quoteId}`);
      message(data.message);
      return data.quote;
    };
    request(null, `?quote_id=${quoteId || ''}`).then((data) => {
      csrf = data.csrf;
      bootstrap = data.data;
      renderBootstrap();
      if (data.quote) renderQuote(data.quote);
      else $$('tr', tbody).forEach((row) => {
        row.draggable = true;
        row.dataset.configValues = JSON.stringify(defaultValues());
      });
      recalculate();
    }).catch((error) => message(error.message, true));
    field('customer_id')?.addEventListener('change', (event) => {
      const selected = event.target.selectedOptions[0];
      const customer = selected?.dataset.customer ? JSON.parse(selected.dataset.customer) : {};
      setField('contact_name', customer.contact_name || '');
      setField('country', customer.country || '');
    });
    editor.addEventListener('input', (event) => {
      if (event.target.matches('[data-qty],[data-price],[data-discount],[data-order-discount],[data-shipping],[data-tax],[data-other]')) recalculate();
    });
    editor.addEventListener('click', async (event) => {
      if (event.target.closest('[data-open-product],[data-add-line]')) { editingRow = null; openModal($('[data-config-modal]', editor)); }
      const configure = event.target.closest('[data-configure]');
      if (configure) { editingRow = configure.closest('tr'); openModal($('[data-config-modal]', editor)); }
      const remove = event.target.closest('[data-remove-line]');
      if (remove) { remove.closest('tr').remove(); recalculate(); }
      if (event.target.closest('[data-batch-qty]')) {
        const value = prompt('输入统一数量');
        if (value !== null && Number(value) > 0) $$('[data-qty]', tbody).forEach((input) => input.value = value);
        recalculate();
      }
      if (event.target.closest('[data-batch-discount]')) {
        const value = prompt('输入统一折扣百分比（0-100）');
        if (value !== null && Number(value) >= 0) $$('[data-discount]', tbody).forEach((input) => input.value = value);
        recalculate();
      }
    });
    $('[data-apply-config]', editor)?.addEventListener('click', async () => {
      try {
        const productKey = $('[data-config-product]', editor).value || editingRow?.dataset.productKey;
        if (!productKey) throw new Error('请选择产品。');
        const values = {};
        $$('[data-config-group]', editor).forEach((select) => values[select.dataset.configGroup] = select.value);
        const data = await request({
          action: 'prepare_item',
          customer_id: Number(field('customer_id')?.value || 0),
          item: { configuration_request: { product_key: productKey, mode: 'professional', values }, quantity: 1 },
        });
        const row = renderRow(data.item, !editingRow);
        if (editingRow) { editingRow.replaceWith(row); editingRow = null; }
        closeModal($('[data-config-modal]', editor));
        const warnings = data.item.warnings || [];
        $('[data-moq-warning]', editor).textContent = warnings.find((item) => item.includes('MOQ')) || 'MOQ 检查通过';
        $('[data-lead-warning]', editor).textContent = data.item.lead_time;
        $('[data-commission-warning]', editor).textContent = data.item.commission?.required
          ? `预计佣金 ${data.item.commission.estimated_amount}` : '无适用佣金规则';
        recalculate();
      } catch (error) { $('[data-config-messages]', editor).textContent = error.message; }
    });
    $('[data-draft-save]', editor)?.addEventListener('click', () => save().catch((error) => message(error.message, true)));
    $('[data-submit-approval]', editor)?.addEventListener('click', async () => {
      try {
        if (!quoteId) await save();
        const data = await request({ action: 'submit', quote_id: quoteId, reason: '标准品报价提交审核' });
        renderQuote(data.quote);
        message(data.message);
      } catch (error) { message(error.message, true); }
    });
    let dragged = null;
    tbody.addEventListener('dragstart', (event) => { dragged = event.target.closest('tr'); });
    tbody.addEventListener('dragover', (event) => {
      event.preventDefault();
      const target = event.target.closest('tr');
      if (dragged && target && dragged !== target) tbody.insertBefore(dragged, target);
    });
    tbody.addEventListener('drop', () => { dragged = null; recalculate(); });
    return;
  }
  const tbody = $('[data-quote-lines]', editor);
  const recalculate = () => {
    let subtotal = 0;
    $$('tr', tbody).forEach((row, index) => {
      const qty = Number($('[data-qty]', row)?.value || 0);
      const price = Number($('[data-price]', row)?.value || 0);
      const discount = Number($('[data-discount]', row)?.value || 0);
      const total = Math.max(0, qty * price * (1 - discount / 100));
      row.firstElementChild.textContent = String(index + 1);
      $('[data-line-total]', row).textContent = total.toFixed(2);
      subtotal += total;
    });
    $('[data-subtotal]', editor).textContent = subtotal.toFixed(2);
    const orderDiscount = Number($('[data-order-discount]', editor)?.value || 0);
    const shipping = Number($('[data-shipping]', editor)?.value || 0);
    const tax = Number($('[data-tax]', editor)?.value || 0);
    $('[data-grand-total]', editor).textContent = Math.max(0, subtotal - orderDiscount + shipping + tax).toFixed(2);
  };
  const lineTemplate = () => {
    const row = document.createElement('tr');
    row.innerHTML = '<td></td><td><div class="line-image">图</div></td><td><input placeholder="输入产品名称"></td><td><textarea placeholder="输入规格或选择合法配置"></textarea></td><td><input type="number" min="1" value="1" data-qty></td><td><input value="pcs"></td><td><input type="number" min="0" step=".01" value="0" data-price></td><td><input type="number" min="0" max="100" value="0" data-discount></td><td data-line-total>0.00</td><td><input value="15 天"></td><td><input placeholder="备注"></td><td><button type="button" class="text-danger" data-remove-line>删除</button></td>';
    return row;
  };
  editor.addEventListener('input', (event) => {
    if (event.target.matches('[data-qty],[data-price],[data-discount],[data-order-discount],[data-shipping],[data-tax]')) recalculate();
  });
  editor.addEventListener('click', (event) => {
    if (event.target.closest('[data-add-line]')) { tbody.append(lineTemplate()); recalculate(); }
    const remove = event.target.closest('[data-remove-line]');
    if (remove && $$('tr', tbody).length > 1) { remove.closest('tr').remove(); recalculate(); }
    if (event.target.closest('[data-configure],[data-open-product]')) openModal($('[data-config-modal]'));
    if (event.target.closest('[data-add-field]')) {
      const label = document.createElement('label');
      label.innerHTML = '自定义字段<input placeholder="输入内容">';
      $('[data-custom-fields]')?.append(label);
    }
  });
  $('[data-apply-config]')?.addEventListener('click', () => closeModal($('[data-config-modal]')));
  $$('[data-file-upload]', editor).forEach((input) => input.addEventListener('change', () => {
    const count = input.files?.length || 0;
    $('[data-file-count]', input.closest('label')).textContent = count ? `已选择 ${count} 个文件` : '尚未选择';
  }));
  $('[data-draft-save]')?.addEventListener('click', () => {
    const snapshot = { type: editor.dataset.quoteType, savedAt: new Date().toISOString(), lineCount: $$('tr', tbody).length };
    window.localStorage.setItem(`cc_quote_draft_${editor.dataset.quoteType}`, JSON.stringify(snapshot));
    const button = $('[data-draft-save]');
    button.textContent = '已保存到本机草稿';
    window.setTimeout(() => button.textContent = '保存草稿', 1800);
  });
  recalculate();
})();
