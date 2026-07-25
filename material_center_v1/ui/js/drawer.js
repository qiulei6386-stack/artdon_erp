(() => {
  window.ArtdonUI = window.ArtdonUI || {};
  const saved=Math.max(460,Math.min(620,Number(localStorage.getItem('artdon-ui-drawer-width'))||520));
  document.documentElement.style.setProperty('--ui-drawer-width',`${saved}px`);
  document.querySelectorAll('.ui-drawer').forEach(drawer=>{
    const grip=document.createElement('span');grip.className='ui-drawer-resizer';grip.setAttribute('aria-hidden','true');drawer.prepend(grip);
    grip.addEventListener('pointerdown',event=>{event.preventDefault();drawer.classList.add('is-resizing');grip.setPointerCapture(event.pointerId);
      const move=e=>{const width=Math.max(460,Math.min(620,innerWidth-e.clientX));document.documentElement.style.setProperty('--ui-drawer-width',`${width}px`);localStorage.setItem('artdon-ui-drawer-width',String(width));};
      const up=()=>{drawer.classList.remove('is-resizing');grip.removeEventListener('pointermove',move);grip.removeEventListener('pointerup',up);};
      grip.addEventListener('pointermove',move);grip.addEventListener('pointerup',up);
    });
  });
  window.ArtdonUI.drawer = {
    open(selector, trigger) { window.ArtdonUI.interactions.open(document.querySelector(selector), trigger, 'drawer'); },
    close() { window.ArtdonUI.interactions.close(); }
  };
  document.addEventListener('click', event => {
    const trigger = event.target.closest('[data-ui-drawer-open]');
    if (trigger) window.ArtdonUI.drawer.open(trigger.dataset.uiDrawerOpen, trigger);
  });
})();
