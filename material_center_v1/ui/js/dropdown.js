(() => {
  document.addEventListener('click', event => {
    const trigger = event.target.closest('[data-ui-dropdown-trigger]');
    if (!trigger) return;
    const menu = document.getElementById(trigger.getAttribute('aria-controls'));
    if (!menu) return;
    const current = window.ArtdonUI.interactions.current();
    if (current.element === menu) { window.ArtdonUI.interactions.close(); return; }
    menu.classList.toggle('ui-menu-up', window.innerHeight - trigger.getBoundingClientRect().bottom < Math.min(320, menu.scrollHeight || 220));
    window.ArtdonUI.interactions.open(menu, trigger, 'dropdown');
  });
  document.addEventListener('click', event => {
    if (event.target.closest('.ui-menu [role="menuitem"],.ui-menu a,.ui-menu button') && !event.target.closest('[data-ui-dropdown-trigger]')) {
      window.ArtdonUI.interactions.close({ restoreFocus: false });
    }
  });
})();
