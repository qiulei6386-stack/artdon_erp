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
    toast.textContent = message;
    region.append(toast);
    window.setTimeout(() => toast.remove(), duration);
  };
})();
