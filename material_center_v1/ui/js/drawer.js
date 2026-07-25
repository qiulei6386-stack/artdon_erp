(() => {
  window.ArtdonUI = window.ArtdonUI || {};
  window.ArtdonUI.drawer = {
    open(selector, trigger) { window.ArtdonUI.interactions.open(document.querySelector(selector), trigger, 'drawer'); },
    close() { window.ArtdonUI.interactions.close(); }
  };
  document.addEventListener('click', event => {
    const trigger = event.target.closest('[data-ui-drawer-open]');
    if (trigger) window.ArtdonUI.drawer.open(trigger.dataset.uiDrawerOpen, trigger);
  });
})();
