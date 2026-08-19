document.addEventListener('DOMContentLoaded', () => {
  const form = document.querySelector('#material-form');
  const url = 'api/v1/material-master.php';

  document.querySelector('[data-new-material]')?.addEventListener('click', () => {
    form.reset();
    form.elements.id.value = '';
  });

  form?.addEventListener('input', () => {
    document.querySelector('#material-modal').dataset.uiDirty = 'true';
  });

  form?.addEventListener('submit', event => {
    event.preventDefault();
    const body = new FormData(form);
    body.set('action', 'save');
    send(body, event.submitter, true);
  });

  document.addEventListener('click', event => {
    const actionButton = event.target.closest('[data-material-action]');
    if (!actionButton) return;

    const material = JSON.parse(actionButton.closest('tr').dataset.material);
    const action = actionButton.dataset.materialAction;

    if (action === 'edit') {
      if (material.status !== 'draft') return window.ArtdonUI.toast('只有草稿可直接编辑', 'warning');
      form.reset();
      ['id', 'category_id', 'name', 'brand', 'model', 'unit'].forEach(key => {
        form.elements[key].value = material[key] ?? '';
      });
      window.ArtdonUI.interactions.open(document.querySelector('#material-modal'), actionButton, 'modal');
      return;
    }

    if (action === 'revision_draft' && material.status !== 'official') {
      return window.ArtdonUI.toast('只有正式物料可以生成修订草稿', 'warning');
    }

    const body = new FormData();
    body.set('csrf_token', form.elements.csrf_token.value);
    body.set('action', action);
    body.set('material_id', material.id);

    if (action === 'references') return send(body, actionButton, false);

    const danger = action === 'delete_draft';
    const message = action === 'revision_draft'
      ? '旧正式物料不会被修改，会生成一份可编辑草稿。'
      : (danger ? '将先检查全部引用，正式物料不能删除。' : '状态变化将记录日志。');

    window.ArtdonUI.confirm({
      title: `确认${actionButton.textContent.trim()}？`,
      message,
      confirmLabel: '确认',
      danger,
      trigger: actionButton,
      onConfirm: () => send(body, actionButton, true),
    });
  });

  async function send(body, button, reload) {
    button.disabled = true;
    button.classList.add('is-loading');
    try {
      const response = await fetch(url, { method: 'POST', body });
      const result = await response.json();
      if (!response.ok || !result.ok) throw new Error(result.message);
      document.querySelector('#material-modal').dataset.uiDirty = 'false';
      window.ArtdonUI.interactions.close({ force: true });
      const message = body.get('action') === 'references'
        ? `引用：${Object.entries(result.data).map(([key, value]) => `${key} ${value}`).join('，')}`
        : result.message;
      window.ArtdonUI.toast(message, 'success', 6000);
      if (reload) setTimeout(() => location.reload(), 400);
    } catch (error) {
      window.ArtdonUI.toast(error.message, 'danger', 0);
    } finally {
      button.disabled = false;
      button.classList.remove('is-loading');
    }
  }
});
