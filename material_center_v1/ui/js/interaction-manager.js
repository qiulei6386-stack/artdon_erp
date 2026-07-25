(() => {
  const state = { element: null, trigger: null, type: null };
  const mask = () => document.querySelector('[data-ui-mask]');
  const focusable = el => [...el.querySelectorAll('a[href],button:not([disabled]),input:not([disabled]),select:not([disabled]),textarea:not([disabled]),[tabindex]:not([tabindex="-1"])')];

  function close(options = {}) {
    if (!state.element) return;
    if (state.element.dataset.uiDirty === 'true' && !options.force) {
      const dirtyElement = state.element;
      const dirtyTrigger = state.trigger;
      const dirtyType = state.type;
      dirtyElement.dataset.uiDirty = 'false';
      close({ force: true, restoreFocus: false });
      window.ArtdonUI.confirm?.({
        title: '放弃未保存修改？',
        message: '关闭后，本次尚未保存的修改将丢失。',
        confirmLabel: '放弃修改',
        danger: true,
        onConfirm: () => { dirtyElement.dataset.uiDirty = 'false'; },
        onCancel: () => {
          dirtyElement.dataset.uiDirty = 'true';
          open(dirtyElement, dirtyTrigger, dirtyType);
        }
      });
      return;
    }
    state.element.classList.remove('is-open');
    state.element.setAttribute('aria-hidden', 'true');
    state.trigger?.setAttribute('aria-expanded', 'false');
    mask()?.classList.remove('is-open');
    document.body.classList.remove('ui-scroll-locked');
    const prior = state.trigger;
    state.element = state.trigger = state.type = null;
    if (options.restoreFocus !== false) prior?.focus();
  }

  function open(element, trigger, type) {
    if (!element) return;
    if (state.element && state.element !== element) close({ restoreFocus: false });
    state.element = element; state.trigger = trigger || document.activeElement; state.type = type;
    element.classList.add('is-open');
    element.setAttribute('aria-hidden', 'false');
    trigger?.setAttribute('aria-expanded', 'true');
    if (type === 'drawer' || type === 'modal') {
      mask()?.classList.add('is-open');
      document.body.classList.add('ui-scroll-locked');
    }
    requestAnimationFrame(() => (focusable(element)[0] || element).focus?.());
  }

  document.addEventListener('keydown', event => {
    if (!state.element) return;
    if (event.key === 'Escape') { event.preventDefault(); close(); return; }
    if (event.key !== 'Tab' || (state.type !== 'drawer' && state.type !== 'modal')) return;
    const items = focusable(state.element);
    if (!items.length) return;
    const first = items[0], last = items[items.length - 1];
    if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last.focus(); }
    if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); }
  });
  document.addEventListener('pointerdown', event => {
    if (!state.element || state.type !== 'dropdown') return;
    if (!state.element.contains(event.target) && !state.trigger?.contains(event.target)) close({ restoreFocus: false });
  });
  document.addEventListener('click', event => {
    if (event.target.closest('[data-ui-close], [data-ui-mask]')) close();
  });
  window.addEventListener('pagehide', () => close({ restoreFocus: false, force: true }));
  window.addEventListener('popstate', () => close({ restoreFocus: false, force: true }));
  window.ArtdonUI = window.ArtdonUI || {};
  window.ArtdonUI.interactions = { open, close, current: () => ({ ...state }) };
})();
