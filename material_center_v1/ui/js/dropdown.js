(() => {
  document.addEventListener('click', event => {
    const trigger = event.target.closest('[data-ui-dropdown-trigger]');
    if (!trigger) return;
    const menu = document.getElementById(trigger.getAttribute('aria-controls'));
    if (!menu) return;
    const current = window.ArtdonUI.interactions.current();
    current.element === menu ? window.ArtdonUI.interactions.close() : window.ArtdonUI.interactions.open(menu, trigger, 'dropdown');
  });
})();
