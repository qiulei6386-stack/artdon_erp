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

  const approvalCenter = $('[data-approval-center]');
  if (approvalCenter) {
    const api = approvalCenter.dataset.api, modal = $('[data-approval-modal]', approvalCenter);
    let csrf = '', currentId = 0;
    const call = async (payload = null, query = '') => {
      const response = await fetch(api + query, payload ? { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ ...payload, csrf }) } : {});
      const data = await response.json(); if (!response.ok || !data.ok) throw new Error(data.message || '审核请求失败'); return data;
    };
    const renderRows = (rows) => {
      const body = $('[data-approval-rows]', approvalCenter); body.innerHTML = '';
      rows.forEach((row) => { const tr = document.createElement('tr'), customer = row.customer?.customer_name || row.customer?.customer_name_en || '';
        tr.innerHTML = `<td><b></b></td><td></td><td></td><td></td><td></td><td><span class="quote-status"></span></td><td></td><td></td><td></td><td><button data-review-open>审核</button></td>`;
        const cells = $$('td', tr); cells[0].firstElementChild.textContent = row.quote_no; cells[1].textContent = row.quote_type; cells[2].textContent = customer;
        cells[3].textContent = `${row.currency} ${Number(row.total_amount).toFixed(2)}`; cells[4].textContent = `${(Number(row.gross_margin || 0) * 100).toFixed(1)}%`;
        cells[5].firstElementChild.textContent = row.risk.level; cells[6].textContent = (row.risk.reasons || []).join('、') || '无';
        cells[7].textContent = row.status; cells[8].textContent = row.owner_name || ''; tr.dataset.quoteId = row.id; body.append(tr);
      });
    };
    const load = async () => { const params = new URLSearchParams(); $$('[data-af]', approvalCenter).forEach((x) => { if (x.value) params.set(x.dataset.af, x.value); });
      const data = await call(null, `?${params}`); csrf = data.csrf; renderRows(data.rows); };
    $('[data-approval-search]', approvalCenter).addEventListener('click', () => load().catch((e) => alert(e.message)));
    approvalCenter.addEventListener('click', async (event) => {
      const open = event.target.closest('[data-review-open]');
      if (open) { try { currentId = Number(open.closest('tr').dataset.quoteId); const data = await call(null, `?quote_id=${currentId}`); csrf = data.csrf;
        const q = data.quote, target = $('[data-approval-detail]', modal); target.innerHTML = `<h3>${q.quote_no} · ${q.status}</h3><p>风险：${q.risk.level} ${(q.risk.reasons || []).join('、')}</p><p>客户：${q.customer_snapshot?.customer_name || q.customer_snapshot?.customer_name_en || ''}</p><p>金额：${q.currency} ${Number(q.total_amount).toFixed(2)}　毛利率：${(Number(q.gross_margin || 0) * 100).toFixed(1)}%</p><table><thead><tr><th>产品</th><th>配置</th><th>数量</th><th>单价</th><th>成本</th><th>折扣</th><th>交期</th></tr></thead><tbody>${(q.items || []).map((i) => `<tr><td>${i.product_name || i.description}</td><td>${JSON.stringify(i.configuration_snapshot || {})}</td><td>${i.quantity}</td><td>${i.unit_price}</td><td>${i.cost_amount || ''}</td><td>${Number(i.discount_rate || 0) * 100}%</td><td>${i.lead_time || ''}</td></tr>`).join('')}</tbody></table><h4>历史版本与修改记录</h4><pre>${JSON.stringify(q.review_actions || [], null, 2)}</pre>`;
        openModal(modal); } catch (e) { alert(e.message); } return; }
      const decision = event.target.closest('[data-decision]');
      if (decision) { const action = decision.dataset.decision, opinion = prompt('审核意见（驳回/要求修改必填）') || '', target = action === 'escalate' ? (prompt('上级审核人') || '') : '';
        try { await call({ action: 'review', quote_id: currentId, decision: action, opinion, target }); closeModal(modal); await load(); } catch (e) { alert(e.message); } }
      if (event.target.closest('[data-convert-order]')) { try { const data = await call({ action: 'convert', quote_id: currentId }); const links = data.order.documents;
        $('[data-approval-detail]', modal).insertAdjacentHTML('beforeend', `<h4>正式订单 ${data.order.order_no}</h4><p><a target="_blank" href="${links.pi}">PI</a>　<a target="_blank" href="${links.pi_excel}">PI Excel</a>　<a target="_blank" href="${links.ci}">CI</a>　<a target="_blank" href="${links.ci_excel}">CI Excel</a>　<a target="_blank" href="${links.pl}">Packing List</a>　<a target="_blank" href="${links.pl_excel}">PL Excel</a></p>`); } catch (e) { alert(e.message); } }
    });
    load().catch((e) => { $('[data-approval-message]', approvalCenter).textContent = e.message; });
  }
  const editor = $('[data-quote-editor]');
  if (!editor) return;
  const outputButtons = $$('[data-quote-output]', editor);
  if (outputButtons.length) {
    let outputCsrf = '';
    const outputApi = 'api/v1/quote_outputs.php';
    const outputRequest = async (payload) => {
      if (!outputCsrf) {
        const tokenResponse = await fetch(`${outputApi}?action=csrf`);
        const token = await tokenResponse.json();
        if (!tokenResponse.ok || !token.ok) throw new Error(token.message || '无法建立输出会话。');
        outputCsrf = token.csrf;
      }
      const response = await fetch(outputApi, {
        method: 'POST', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ ...payload, csrf: outputCsrf }),
      });
      const data = await response.json();
      if (!response.ok || !data.ok) throw new Error(data.message || '报价输出失败。');
      return data;
    };
    outputButtons.forEach((button) => button.addEventListener('click', async () => {
      try {
        const quoteId = Number(editor.dataset.quoteId || 0);
        if (!quoteId) throw new Error('请先保存报价。');
        const snapshot = (await outputRequest({ action: 'snapshot', quote_id: quoteId })).snapshot;
        const action = button.dataset.quoteOutput;
        if (action === 'send') {
          const to = prompt('收件人邮箱');
          if (!to) return;
          const cc = prompt('抄送邮箱（可留空）') || '';
          const subject = prompt('邮件主题', 'Artdon Quotation') || 'Artdon Quotation';
          const body = prompt('邮件正文', 'Please find the quotation attached.') || '';
          await outputRequest({ action: 'send', snapshot_id: snapshot.id, to, cc, subject, body });
          alert('报价邮件已发送并记录。');
          return;
        }
        const url = `${outputApi}?action=${encodeURIComponent(action)}&snapshot_id=${snapshot.id}`;
        window.open(url, '_blank', 'noopener');
      } catch (error) {
        alert(error.message);
      }
    }));
  }
  if (editor.matches('[data-custom-quote]')) {
    const api = editor.dataset.api;
    const tbody = $('[data-quote-lines]', editor);
    let csrf = '';
    let quoteId = Number(editor.dataset.quoteId || 0);
    let bootstrap = {};
    const field = (name) => $(`[data-custom-field="${name}"]`, editor);
    const message = (text, error = false) => {
      const node = $('[data-custom-message]', editor);
      node.textContent = text;
      node.classList.toggle('error', error);
    };
    const request = async (payload = null, query = '') => {
      const response = await fetch(`${api}${query}`, payload ? {
        method: 'POST', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ ...payload, csrf }),
      } : {});
      const data = await response.json();
      if (!response.ok || !data.ok) throw new Error(data.message || '定制品报价请求失败。');
      return data;
    };
    const recalculate = () => {
      let subtotal = 0;
      $$('tr', tbody).forEach((row, index) => {
        row.firstElementChild.textContent = index + 1;
        const amount = Number($('[data-qty]', row).value || 0) * Number($('[data-price]', row).value || 0);
        const cost = Number($('[data-estimated-cost]', row).value || 0) * Number($('[data-qty]', row).value || 0);
        $('[data-margin]', row).textContent = amount > 0 ? `${(((amount - cost) / amount) * 100).toFixed(1)}%` : '0%';
        subtotal += amount;
      });
      $('[data-subtotal]', editor).textContent = subtotal.toFixed(2);
      $('[data-grand-total]', editor).textContent = Math.max(0, subtotal
        - Number($('[data-order-discount]', editor).value || 0)
        + Number($('[data-shipping]', editor).value || 0)
        + Number($('[data-tax]', editor).value || 0)).toFixed(2);
    };
    const addRow = (item = {}) => {
      const custom = item.custom_fields || {};
      const row = document.createElement('tr');
      row.dataset.itemId = item.id || '';
      row.innerHTML = `<td></td><td><input data-name><small><input data-material placeholder="材质"><input data-color placeholder="颜色"></small></td>
        <td><textarea data-spec placeholder="规格 / 尺寸 / 功率 / 安装 / 工艺"></textarea></td><td><input data-unit value="PCS"></td>
        <td><input data-qty type="number" min=".001" step=".001" value="1"></td><td><input data-price type="number" min="0" step=".01" value="0"></td>
        <td><input data-target-cost type="number" min="0" step=".01" value="0"></td><td><input data-estimated-cost type="number" min="0" step=".01" value="0"></td>
        <td data-margin>0%</td><td><input data-lead></td><td><label class="line-upload">上传<input type="file" hidden data-item-upload></label></td>
        <td><button type="button" data-custom-fields>详细字段</button><button type="button" class="text-danger" data-custom-remove>删除</button></td>`;
      $('[data-name]', row).value = item.product_name || item.description || '';
      $('[data-material]', row).value = custom.material || '';
      $('[data-color]', row).value = custom.color || '';
      $('[data-spec]', row).value = item.configuration_snapshot?.specification || '';
      $('[data-unit]', row).value = item.unit || 'PCS';
      $('[data-qty]', row).value = item.quantity || 1;
      $('[data-price]', row).value = item.unit_price || 0;
      $('[data-target-cost]', row).value = custom.target_cost || 0;
      $('[data-estimated-cost]', row).value = custom.estimated_cost || 0;
      $('[data-lead]', row).value = item.lead_time || '';
      row.dataset.custom = JSON.stringify(custom);
      row.dataset.referenceProductId = item.reference_product_id || '';
      tbody.append(row);
      recalculate();
    };
    const renderFiles = (quote) => {
      const target = $('[data-custom-files]', editor);
      target.innerHTML = '';
      (quote.files || []).forEach((file) => {
        const node = document.createElement('span');
        node.draggable = true;
        node.dataset.fileId = file.id;
        node.innerHTML = `<a target="_blank"></a><button type="button" data-delete-file>×</button>`;
        $('a', node).href = file.storage_path;
        $('a', node).textContent = file.original_name;
        target.append(node);
      });
    };
    const renderQuote = (quote) => {
      if (!quote) return;
      quoteId = Number(quote.id);
      editor.dataset.quoteId = quoteId;
      field('quote_no').value = quote.quote_no;
      field('customer_id').value = quote.legacy_customer_id || '';
      ['contact_name','country','currency','valid_until','owner_name','payment_terms','trade_terms','customer_note','internal_note']
        .forEach((name) => { if (field(name)) field(name).value = quote[name] || ''; });
      const source = quote.source_snapshot || {};
      ['project_name','project_type','requirement_summary','crm_opportunity','crm_project']
        .forEach((name) => { field(name).value = source[name] || ''; });
      $('[data-order-discount]', editor).value = quote.discount_amount || 0;
      $('[data-shipping]', editor).value = quote.shipping_amount || 0;
      $('[data-tax]', editor).value = quote.tax_amount || 0;
      $('[data-custom-status]', editor).textContent = quote.status;
      tbody.innerHTML = '';
      (quote.items || []).forEach(addRow);
      (quote.item_files || []).forEach((file) => {
        const row = $$('tr', tbody).find((candidate) => Number(candidate.dataset.itemId) === Number(file.quote_item_id));
        const label = row ? $('.line-upload', row) : null;
        if (label) {
          const link = document.createElement('a');
          link.href = file.storage_path; link.target = '_blank'; link.textContent = file.original_name;
          label.before(link);
        }
      });
      renderFiles(quote);
      recalculate();
      message(`报价 ${quote.quote_no} 已保存`);
    };
    const payload = () => ({
      id: quoteId, customer_id: Number(field('customer_id').value || 0),
      contact_name: field('contact_name').value, country: field('country').value, currency: field('currency').value,
      valid_until: field('valid_until').value || null, payment_terms: field('payment_terms').value,
      trade_terms: field('trade_terms').value, project_name: field('project_name').value,
      project_type: field('project_type').value, requirement_summary: field('requirement_summary').value,
      crm_opportunity: field('crm_opportunity').value, crm_project: field('crm_project').value,
      customer_note: field('customer_note').value, internal_note: field('internal_note').value,
      discount_amount: Number($('[data-order-discount]', editor).value || 0),
      shipping_amount: Number($('[data-shipping]', editor).value || 0),
      tax_amount: Number($('[data-tax]', editor).value || 0),
      items: $$('tr', tbody).map((row, index) => {
        const custom = JSON.parse(row.dataset.custom || '{}');
        custom.material = $('[data-material]', row).value;
        custom.color = $('[data-color]', row).value;
        custom.target_cost = Number($('[data-target-cost]', row).value || 0);
        custom.estimated_cost = Number($('[data-estimated-cost]', row).value || 0);
        return {
          product_name: $('[data-name]', row).value, description: $('[data-name]', row).value,
          configuration_snapshot: { specification: $('[data-spec]', row).value },
          unit: $('[data-unit]', row).value, quantity: Number($('[data-qty]', row).value || 0),
          unit_price: Number($('[data-price]', row).value || 0), lead_time: $('[data-lead]', row).value,
          reference_product_id: Number(row.dataset.referenceProductId || 0) || null, custom_fields: custom, sort_order: index,
        };
      }),
    });
    const save = async () => {
      const data = await request({ action: 'save', quote: payload() });
      renderQuote(data.quote);
      history.replaceState(null, '', `?page=quote_center&quote_mode=custom&editor=1&quote_id=${quoteId}`);
      return data.quote;
    };
    const upload = async (input, itemId = '') => {
      const selectedFile = input.files[0];
      if (!quoteId) await save();
      if (itemId === '') {
        const currentRow = input.closest('tr');
        const rowIndex = currentRow ? $$('tr', tbody).indexOf(currentRow) : -1;
        itemId = currentRow?.dataset.itemId || '';
        if (!itemId && rowIndex >= 0) {
          await save();
          itemId = $$('tr', tbody)[rowIndex]?.dataset.itemId || '';
        }
      }
      const form = new FormData();
      form.append('action', 'upload'); form.append('csrf', csrf); form.append('quote_id', quoteId);
      form.append('item_id', itemId); form.append('file_type', input.dataset.customUpload || 'document');
      form.append('file', selectedFile);
      const response = await fetch(api, { method: 'POST', body: form });
      const data = await response.json();
      if (!response.ok || !data.ok) throw new Error(data.message || '上传失败。');
      renderQuote(data.quote);
    };
    $('[data-custom-add]', editor).addEventListener('click', () => addRow());
    $('[data-custom-save]', editor).addEventListener('click', () => save().catch((error) => message(error.message, true)));
    $('[data-custom-submit]', editor).addEventListener('click', async () => {
      try { if (!quoteId) await save(); renderQuote((await request({ action: 'submit', quote_id: quoteId })).quote); }
      catch (error) { message(error.message, true); }
    });
    $('[data-custom-approve]', editor).addEventListener('click', async () => {
      try { renderQuote((await request({ action: 'approve', quote_id: quoteId, reason: '定制品核价审核通过' })).quote); }
      catch (error) { message(error.message, true); }
    });
    $$('[data-custom-handoff]', editor).forEach((button) => button.addEventListener('click', async () => {
      try {
        const data = await request({ action: 'handoff', quote_id: quoteId, type: button.dataset.customHandoff });
        message(`已建立转${data.handoff.type === 'project' ? '项目' : '订单'}快照。`);
      } catch (error) { message(error.message, true); }
    }));
    editor.addEventListener('input', (event) => {
      if (event.target.matches('[data-qty],[data-price],[data-estimated-cost],[data-order-discount],[data-shipping],[data-tax]')) recalculate();
    });
    editor.addEventListener('change', (event) => {
      if (event.target.matches('[data-custom-upload],[data-item-upload]') && event.target.files?.[0]) {
        upload(event.target).catch((error) => message(error.message, true));
      }
      if (event.target === field('customer_id')) {
        const customer = JSON.parse(event.target.selectedOptions[0]?.dataset.customer || '{}');
        field('contact_name').value = customer.contact_name || '';
        field('country').value = customer.country || '';
      }
    });
    editor.addEventListener('click', async (event) => {
      const remove = event.target.closest('[data-custom-remove]');
      if (remove) { remove.closest('tr').remove(); if (!$('tr', tbody)) addRow(); recalculate(); }
      const details = event.target.closest('[data-custom-fields]');
      if (details) {
        const row = details.closest('tr');
        const custom = JSON.parse(row.dataset.custom || '{}');
        custom.dimensions = prompt('尺寸', custom.dimensions || '') ?? custom.dimensions;
        custom.power = prompt('功率', custom.power || '') ?? custom.power;
        custom.installation = prompt('安装方式', custom.installation || '') ?? custom.installation;
        custom.special_process = prompt('特殊工艺', custom.special_process || '') ?? custom.special_process;
        custom.pricing_opinion = prompt('核价意见', custom.pricing_opinion || '') ?? custom.pricing_opinion;
        custom.approval_opinion = prompt('审核意见', custom.approval_opinion || '') ?? custom.approval_opinion;
        const reference = prompt('关联标准产品 ID（可留空）', row.dataset.referenceProductId || '');
        row.dataset.referenceProductId = reference || '';
        row.dataset.custom = JSON.stringify(custom);
      }
      const deletion = event.target.closest('[data-delete-file]');
      if (deletion) {
        try {
          await request({ action: 'delete_file', quote_id: quoteId, file_id: Number(deletion.parentElement.dataset.fileId), item_file: false });
          deletion.parentElement.remove();
        } catch (error) { message(error.message, true); }
      }
    });
    request(null, quoteId ? `?quote_id=${quoteId}` : '').then((data) => {
      csrf = data.csrf; bootstrap = data.data || {};
      const customer = field('customer_id');
      customer.innerHTML = '<option value="">选择 CRM 客户</option>';
      (bootstrap.customers || []).forEach((item) => {
        const option = document.createElement('option');
        option.value = item.id; option.textContent = `${item.customer_code || ''} ${item.customer_name || item.customer_name_en || ''}`;
        option.dataset.customer = JSON.stringify(item); customer.append(option);
      });
      if (data.quote) renderQuote(data.quote); else addRow();
    }).catch((error) => message(error.message, true));
    return;
  }
  if (editor.matches('[data-website-quote]')) {
    const api = editor.dataset.api;
    const tbody = $('[data-quote-lines]', editor);
    const modal = $('[data-web-import-modal]', editor);
    let csrf = '';
    let quoteId = Number(editor.dataset.quoteId || 0);
    let bootstrap = {};
    const message = (text, error = false) => {
      const node = $('[data-web-message]', editor);
      node.textContent = text;
      node.classList.toggle('error', error);
    };
    const request = async (payload = null, query = '') => {
      const response = await fetch(`${api}${query}`, payload ? {
        method: 'POST', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ ...payload, csrf }),
      } : {});
      const data = await response.json();
      if (!response.ok || !data.ok) throw new Error(data.message || '网站报价请求失败。');
      return data;
    };
    const display = (name, value) => $$(`[data-web-field="${name}"]`, editor)
      .forEach((node) => { node.textContent = value || '—'; });
    const recalculate = () => {
      let subtotal = 0;
      $$('tr', tbody).forEach((row) => {
        const total = Number($('[data-qty]', row).value) * Number($('[data-price]', row).value)
          * (1 - Number($('[data-discount]', row).value) / 100);
        $('[data-line-total]', row).textContent = Math.max(0, total).toFixed(2);
        subtotal += Math.max(0, total);
      });
      $('[data-subtotal]', editor).textContent = subtotal.toFixed(2);
      $('[data-grand-total]', editor).textContent = Math.max(0, subtotal
        - Number($('[data-order-discount]', editor).value || 0)
        + Number($('[data-shipping]', editor).value || 0)
        + Number($('[data-tax]', editor).value || 0)).toFixed(2);
    };
    const renderQuote = (quote) => {
      if (!quote) return;
      quoteId = Number(quote.id);
      editor.dataset.quoteId = String(quoteId);
      const source = quote.source_snapshot || {};
      display('source_order_no', quote.source_order_no);
      display('placed_at', source.placed_at);
      display('quote_no', quote.quote_no);
      display('owner_name', quote.owner_name);
      display('customer_name', quote.customer_snapshot?.customer_name || quote.customer_snapshot?.customer_name_en);
      display('country', quote.country);
      display('contact_name', quote.contact_name);
      display('currency', quote.currency);
      $('[data-web-customer-note]', editor).value = quote.customer_note || '';
      $('[data-web-internal-note]', editor).value = quote.internal_note || '';
      $('[data-web-payment]', editor).value = quote.payment_terms || '';
      $('[data-web-trade]', editor).value = quote.trade_terms || '';
      $('[data-shipping]', editor).value = quote.shipping_amount || 0;
      $('[data-order-discount]', editor).value = quote.discount_amount || 0;
      $('[data-tax]', editor).value = quote.tax_amount || 0;
      tbody.innerHTML = '';
      (quote.items || []).forEach((item, index) => {
        const sourceLine = item.source_line_snapshot || {};
        const row = document.createElement('tr');
        row.dataset.itemId = item.id;
        row.innerHTML = `<td>${index + 1}</td><td><b data-model></b><small data-sku></small></td>
          <td data-name></td><td data-config></td><td><input data-qty type="number" step=".001" readonly></td>
          <td data-site-price></td><td><input data-price type="number" min="0" step=".01"></td>
          <td><input data-discount type="number" min="0" max="100"></td><td data-line-total></td>
          <td><input data-lead></td><td><button type="button" data-request-qty>申请改数量</button></td>`;
        $('[data-model]', row).textContent = item.model_no || '—';
        $('[data-sku]', row).textContent = item.sku_code || '—';
        $('[data-name]', row).textContent = item.product_name || item.description || '—';
        $('[data-config]', row).textContent = Object.values(sourceLine.configuration || {}).join(' / ') || '标准配置';
        $('[data-qty]', row).value = item.quantity;
        $('[data-site-price]', row).textContent = Number(sourceLine.website_unit_price || 0).toFixed(2);
        $('[data-price]', row).value = item.unit_price;
        $('[data-discount]', row).value = Number(item.discount_rate || 0) * 100;
        $('[data-lead]', row).value = item.lead_time || '';
        tbody.append(row);
      });
      $('[data-line-count]', editor).textContent = `共 ${(quote.items || []).length} 项`;
      recalculate();
      message(`报价 ${quote.quote_no} · ${quote.status}`);
    };
    const importField = (name) => $(`[data-import-field="${name}"]`, modal);
    const renderBootstrap = () => {
      const customers = importField('customer_id');
      customers.innerHTML = '<option value="">选择真实 CRM 客户</option>';
      (bootstrap.customers || []).forEach((customer) => {
        const option = document.createElement('option');
        option.value = customer.id;
        option.textContent = `${customer.customer_code || ''} ${customer.customer_name || customer.customer_name_en || ''}`.trim();
        customers.append(option);
      });
      const products = importField('product');
      products.innerHTML = '<option value="">选择网站销售产品</option>';
      (bootstrap.configuration?.products || []).forEach((product) => {
        const option = document.createElement('option');
        option.value = product.id;
        option.dataset.product = JSON.stringify(product);
        option.textContent = `${product.model_no} · ${product.product_name}`;
        products.append(option);
      });
      $('[data-channel-status]', modal).textContent = bootstrap.channel?.live_api_status === 'not_configured'
        ? '实时渠道未配置；当前使用鉴权载荷导入' : '实时渠道已连接';
    };
    const reviewPayload = () => ({
      action: 'review', quote_id: quoteId, reason: '网站订单审核调整',
      changes: {
        shipping_amount: Number($('[data-shipping]', editor).value || 0),
        discount_amount: Number($('[data-order-discount]', editor).value || 0),
        tax_amount: Number($('[data-tax]', editor).value || 0),
        internal_note: $('[data-web-internal-note]', editor).value,
        payment_terms: $('[data-web-payment]', editor).value,
        trade_terms: $('[data-web-trade]', editor).value,
        items: $$('tr', tbody).map((row) => ({
          unit_price: Number($('[data-price]', row).value || 0),
          discount_rate: Number($('[data-discount]', row).value || 0) / 100,
          lead_time: $('[data-lead]', row).value,
        })),
      },
    });
    $('[data-new-website]', editor)?.addEventListener('click', () => openModal(modal));
    importField('product')?.addEventListener('change', (event) => {
      const value = event.target.selectedOptions[0]?.dataset.product;
      if (!value) return;
      const product = JSON.parse(value);
      importField('sku_code').value = product.sku_code || product.model_no || '';
    });
    $('[data-import-submit]', modal)?.addEventListener('click', async () => {
      try {
        const product = JSON.parse(importField('product').selectedOptions[0]?.dataset.product || '{}');
        const order = {
          external_order_no: importField('external_order_no').value,
          customer_id: Number(importField('customer_id').value || 0), currency: 'USD',
          shipping_amount: Number(importField('shipping').value || 0),
          placed_at: importField('placed_at').value, customer_note: importField('customer_note').value,
          attachments: [], shipping: {}, items: [{
            legacy_product_id: Number(product.id || 0), sku_code: importField('sku_code').value,
            model_no: product.model_no || '', product_name: product.product_name || '',
            configuration: { summary: importField('configuration').value },
            quantity: Number(importField('quantity').value || 0),
            website_unit_price: Number(importField('price').value || 0),
            customer_requirement: importField('requirement').value,
          }],
        };
        const data = await request({ action: importField('action').value, order });
        renderQuote(data.result.quote);
        closeModal(modal);
        history.replaceState(null, '', `?page=quote_center&quote_mode=website&quote_id=${quoteId}`);
      } catch (error) { message(error.message, true); }
    });
    $('[data-web-save]', editor)?.addEventListener('click', async () => {
      try {
        if (!quoteId) throw new Error('请先导入网站订单。');
        renderQuote((await request(reviewPayload())).quote);
      } catch (error) { message(error.message, true); }
    });
    $('[data-web-approve]', editor)?.addEventListener('click', async () => {
      try {
        if (!quoteId) throw new Error('请先导入网站订单。');
        renderQuote((await request({ action: 'approve', quote_id: quoteId, reason: '网站订单审核通过' })).quote);
      } catch (error) { message(error.message, true); }
    });
    $('[data-web-reject]', editor)?.addEventListener('click', async () => {
      const reason = prompt('请输入驳回原因');
      if (!reason) return;
      try { renderQuote((await request({ action: 'reject', quote_id: quoteId, reason })).quote); }
      catch (error) { message(error.message, true); }
    });
    tbody.addEventListener('click', async (event) => {
      const button = event.target.closest('[data-request-qty]');
      if (!button) return;
      const value = prompt('申请修改后的数量');
      const reason = value === null ? '' : prompt('请输入解锁原因');
      if (!reason) return;
      try {
        await request({ action: 'request_unlock', quote_id: quoteId,
          item_id: Number(button.closest('tr').dataset.itemId), field: 'quantity',
          requested_value: Number(value), reason });
        message('数量解锁申请已提交，须由有权限的审核人批准。');
      } catch (error) { message(error.message, true); }
    });
    editor.addEventListener('input', (event) => {
      if (event.target.matches('[data-price],[data-discount],[data-shipping],[data-order-discount],[data-tax]')) recalculate();
    });
    request(null, quoteId ? `?quote_id=${quoteId}` : '').then((data) => {
      csrf = data.csrf;
      bootstrap = data.data || {};
      renderBootstrap();
      renderQuote(data.quote);
      if (!quoteId) message('请选择导入或业务员代客户下单。');
    }).catch((error) => message(error.message, true));
    return;
  }
  if (editor.matches('[data-standard-quote]')) {
    const api = editor.dataset.api;
    const tbody = $('[data-quote-lines]', editor);
    let csrf = '';
    let bootstrap = { customers: [], configuration: { products: [], groups: [] }, commission_reminders: [] };
    let quoteId = Number(editor.dataset.quoteId || 0);
    let editingRow = null;
    const summaryPanel = $('[data-summary-panel]', editor);
    const configPanel = $('[data-config-panel]', editor);
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
    const showConfiguration = (row = null) => {
      editingRow = row;
      const productSelect = $('[data-config-product]', editor);
      const values = row ? JSON.parse(row.dataset.configValues || '{}') : defaultValues();
      productSelect.value = row?.dataset.productKey || '';
      productSelect.disabled = Boolean(row);
      $$('[data-config-group]', editor).forEach((select) => {
        select.value = values[select.dataset.configGroup] || select.value;
      });
      $('[data-config-messages]', editor).textContent = '';
      summaryPanel.hidden = true;
      configPanel.hidden = false;
    };
    const hideConfiguration = () => {
      configPanel.hidden = true;
      summaryPanel.hidden = false;
      $('[data-config-product]', editor).disabled = false;
      $('[data-config-messages]', editor).textContent = '';
      editingRow = null;
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
      if (event.target.closest('[data-open-product],[data-add-line]')) showConfiguration();
      const configure = event.target.closest('[data-configure]');
      if (configure) showConfiguration(configure.closest('tr'));
      if (event.target.closest('[data-config-close]')) hideConfiguration();
      const remove = event.target.closest('[data-remove-line]');
      if (remove) {
        if (editingRow === remove.closest('tr')) hideConfiguration();
        remove.closest('tr').remove();
        recalculate();
      }
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
        hideConfiguration();
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
