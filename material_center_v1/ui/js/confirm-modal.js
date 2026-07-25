(() => {
  window.ArtdonUI = window.ArtdonUI || {};
  let modal;
  window.ArtdonUI.confirm = options => {
    modal?.remove();
    modal = document.createElement('section');
    modal.className = 'ui-modal';
    modal.id = 'ui-confirm-modal';
    modal.setAttribute('role', 'alertdialog');
    modal.setAttribute('aria-modal', 'true');
    modal.setAttribute('aria-hidden', 'true');
    modal.tabIndex = -1;
    modal.innerHTML = `<div class="ui-modal-header"><div><strong data-confirm-title></strong></div><button class="ui-btn ui-btn-secondary ui-btn-icon" type="button" data-confirm-cancel aria-label="关闭">×</button></div><div class="ui-modal-body"><p data-confirm-message></p></div><div class="ui-modal-footer"><button class="ui-btn ui-btn-secondary" type="button" data-confirm-cancel>继续编辑</button><button class="ui-btn" type="button" data-confirm-ok></button></div>`;
    modal.querySelector('[data-confirm-title]').textContent = options.title || '确认操作';
    modal.querySelector('[data-confirm-message]').textContent = options.message || '请确认是否继续。';
    const ok = modal.querySelector('[data-confirm-ok]');
    ok.textContent = options.confirmLabel || '确认';
    if (options.danger) ok.classList.add('ui-btn-danger');
    document.body.append(modal);
    const finish = confirmed => {
      window.ArtdonUI.interactions.close({ force: true, restoreFocus: !confirmed });
      modal.remove();
      modal = null;
      if (confirmed) options.onConfirm?.();
      else options.onCancel?.();
    };
    modal.querySelectorAll('[data-confirm-cancel]').forEach(button => button.addEventListener('click', () => finish(false)));
    ok.addEventListener('click', () => finish(true));
    window.ArtdonUI.interactions.open(modal, options.trigger || document.activeElement, 'modal');
  };
})();
