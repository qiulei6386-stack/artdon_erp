(() => {
  const root = document.querySelector('[data-product-config]');
  if (!root) return;
  let config = {};
  try { config = JSON.parse(root.dataset.config || '{}'); } catch (_) { return; }
  const groups = [...root.querySelectorAll('[data-pc-group]')];
  const money = (value) => `USD ${Number(value || 0).toFixed(2)}`;
  const selected = () => groups.map((group) => ({
    key: group.dataset.pcGroup,
    multiple: group.dataset.multiple === '1',
    items: [...group.querySelectorAll('[data-pc-option].selected')].map((button) => ({
      name: button.dataset.name, delta: Number(button.dataset.delta || 0)
    }))
  }));
  const render = () => {
    const choices = selected();
    const list = root.querySelector('[data-pc-selection-list]');
    list.innerHTML = choices.map((group, index) => {
      const name = config.options[index]?.name || group.key;
      return `<div><dt>${name}</dt><dd>${group.items.map((item) => item.name).join('、') || '—'}</dd></div>`;
    }).join('');
    const extras = choices.flatMap((group) => group.items).filter((item) => item.delta !== 0);
    const subtotal = Number(config.base || 0) + extras.reduce((sum, item) => sum + item.delta, 0);
    const discount = subtotal * .05;
    root.querySelector('[data-pc-pricing]').innerHTML =
      `<div><dt>基础价格</dt><dd>${money(config.base)}</dd></div>` +
      extras.map((item) => `<div><dt>${item.name}</dt><dd>+ ${money(item.delta)}</dd></div>`).join('') +
      `<div class="subtotal"><dt>小计</dt><dd>${money(subtotal)}</dd></div><div><dt>客户等级折扣（A级 5%）</dt><dd>− ${money(discount)}</dd></div>`;
    root.querySelector('[data-pc-total]').textContent = money(subtotal - discount);
    root.querySelector('[data-pc-lead]').textContent = choices.some((group) => group.items.some((item) => item.name.includes('定制'))) ? '15–20 天' : '7–10 天';
  };
  groups.forEach((group) => group.querySelectorAll('[data-pc-option]').forEach((button) => button.addEventListener('click', () => {
    if (group.dataset.multiple !== '1') group.querySelectorAll('[data-pc-option]').forEach((item) => item.classList.remove('selected'));
    button.classList.toggle('selected');
    if (group.dataset.multiple !== '1' && !button.classList.contains('selected')) button.classList.add('selected');
    render();
  })));
  const toast = (message) => {
    const el = root.querySelector('[data-pc-toast]'); el.textContent = message; el.classList.add('show');
    window.setTimeout(() => el.classList.remove('show'), 2200);
  };
  root.querySelector('[data-pc-change]')?.addEventListener('click', () => root.querySelector('[data-pc-picker]')?.classList.toggle('open'));
  root.querySelector('[data-pc-clear]')?.addEventListener('click', () => {
    groups.forEach((group) => {
      group.querySelectorAll('[data-pc-option]').forEach((item) => item.classList.remove('selected'));
      group.querySelector('[data-pc-option]')?.classList.add('selected');
    }); render(); toast('已恢复默认配置');
  });
  root.querySelector('[data-pc-template]')?.addEventListener('click', () => {
    localStorage.setItem(`cc_product_config_${config.product?.id || 0}`, JSON.stringify(selected()));
    toast('配置模板已保存');
  });
  root.querySelector('[data-pc-confirm]')?.addEventListener('click', () => {
    root.querySelectorAll('.pc-steps li')[1]?.classList.add('done');
    root.querySelectorAll('.pc-steps li')[2]?.classList.add('active');
    toast('配置已确认，可进入报价');
  });
  render();
})();
