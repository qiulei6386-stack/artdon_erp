(() => {
  window.ArtdonUI = window.ArtdonUI || {};
  window.ArtdonUI.toast = (message, type = 'info', duration = 3200) => {
    let region = document.querySelector('[data-ui-toast-region]');
    if (!region) {
      region = document.createElement('div');
      region.className = 'ui-toast-region';
      region.dataset.uiToastRegion = '';
      region.setAttribute('role', 'status');
      region.setAttribute('aria-live', 'polite');
      document.body.append(region);
    }
    const toast = document.createElement('div');
    toast.className = `ui-toast ui-toast-${type}`;
    const text = document.createElement('span');
    text.className = 'ui-toast-message';
    text.textContent = message;
    const close = document.createElement('button');
    close.className = 'ui-toast-close';
    close.type = 'button';
    close.setAttribute('aria-label', '关闭通知');
    close.textContent = '×';
    close.addEventListener('click', () => toast.remove());
    toast.append(text, close);
    region.append(toast);
    if (duration > 0 && type !== 'danger') window.setTimeout(() => toast.remove(), duration);
    return toast;
  };
})();
