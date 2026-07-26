(() => {
  const q = (selector, root = document) => root.querySelector(selector);
  const qa = (selector, root = document) => [...root.querySelectorAll(selector)];
  const page = q('[data-adaptation]');
  if (!page) return;

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

  const send = async body => {
    const response = await fetch(`${window.MC_BASE_URL}/api/v1/adaptation.php`, {
      method: 'POST',
      body,
      credentials: 'same-origin',
      headers: { Accept: 'application/json' },
    });
    const text = await response.text();
    let data;
    try {
      data = JSON.parse(text);
    } catch {
      throw new Error('服务器没有返回有效数据。');
    }
    if (!response.ok || !data.ok) throw new Error(data.message || '操作失败');
    return data;
  };

  qa('[data-adaptation-action]').forEach(form => form.addEventListener('submit', async event => {
    event.preventDefault();
    const button = event.submitter || q('button[type="submit"]', form);
    if (button) button.disabled = true;
    try {
      const body = new FormData(form);
      if (body.get('action') === 'save_option') {
        const fieldCode = String(body.get('condition_field_code') || '').trim();
        const operator = String(body.get('condition_operator') || '').trim();
        const rawExpected = String(body.get('condition_expected') || '').trim();
        let expected = rawExpected;
        if (operator === 'in') expected = rawExpected.split(',').map(value => value.trim()).filter(Boolean);
        else if (rawExpected !== '' && Number.isFinite(Number(rawExpected))) expected = Number(rawExpected);
        const conditions = fieldCode && operator ? [{
          field_code: fieldCode,
          operator,
          expected,
          failure_message: String(body.get('condition_failure_message') || '').trim() || '当前物料不满足适配条件',
          severity: body.get('condition_severity') === 'warn' ? 'warn' : 'block',
          sort_order: 10,
        }] : [];
        body.set('conditions_json', JSON.stringify(conditions));
      }
      const result = await send(body);
      const action = form.elements.action?.value || '';
      notify(
        action === 'approve' ? '适配版本已批准' : '保存成功',
        action === 'initialize_groups'
          ? `已新增 ${result.data?.created || 0} 个配置组`
          : '真实规则已经写入物料中心'
      );
      setTimeout(() => location.reload(), 350);
    } catch (error) {
      notify('操作失败', error instanceof Error ? error.message : '操作失败');
      if (button) button.disabled = false;
    }
  }));

  qa('[data-adaptation-tab]').forEach(button => button.addEventListener('click', () => {
    qa('[data-adaptation-tab]').forEach(item => item.classList.toggle('is-active', item === button));
    qa('[data-adaptation-panel]').forEach(panel => panel.classList.toggle('is-active', panel.dataset.adaptationPanel === button.dataset.adaptationTab));
  }));

  q('[data-adaptation-evaluate]')?.addEventListener('submit', async event => {
    event.preventDefault();
    const form = event.currentTarget;
    const body = new FormData(form);
    const output = q('[data-adaptation-result]', form);
    const optionIds = qa('[name="option_choice"]:checked', form).map(input => Number(input.value));
    body.set('option_ids', JSON.stringify(optionIds));
    try {
      JSON.parse(String(body.get('context_json') || '{}'));
    } catch {
      output.className = 'is-incompatible';
      output.textContent = '上下文必须是有效 JSON。';
      return;
    }
    output.className = '';
    output.textContent = '正在计算…';
    try {
      const result = await send(body);
      const data = result.data;
      output.className = data.compatible ? 'is-compatible' : 'is-incompatible';
      output.textContent = `${data.compatible ? '适配通过' : '不适配'} · 价格影响 ${data.price_impact} · 交期影响 ${data.lead_time_impact_days} 天${data.reasons.length ? ' · ' + data.reasons.map(reason => reason.reason).join('；') : ''}`;
    } catch (error) {
      output.className = 'is-incompatible';
      output.textContent = error instanceof Error ? error.message : '计算失败';
    }
  });
})();
