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
