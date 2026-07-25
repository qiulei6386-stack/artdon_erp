(() => {
  document.addEventListener('click', event => {
    const trigger = event.target.closest('[data-ui-modal-open]');
    if (!trigger) return;
    window.ArtdonUI.interactions.open(document.querySelector(trigger.dataset.uiModalOpen), trigger, 'modal');
  });
})();
