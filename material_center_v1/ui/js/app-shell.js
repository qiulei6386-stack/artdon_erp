(() => {
  const root = document.documentElement;
  const stored = localStorage.getItem('artdon-ui-theme');
  root.dataset.theme = ['light', 'dark', 'system'].includes(stored) ? stored : 'system';
  document.addEventListener('click', event => {
    const theme = event.target.closest('[data-ui-theme]')?.dataset.uiTheme;
    if (theme) {
      root.dataset.theme = theme;
      localStorage.setItem('artdon-ui-theme', theme);
      window.ArtdonUI.interactions.close({ restoreFocus: false });
      window.ArtdonUI.toast(`主题已切换为${{light:'浅色',dark:'深色',system:'跟随系统'}[theme]}`, 'success');
    }
    if (event.target.closest('[data-ui-presentation]')) {
      document.body.classList.toggle('ui-presentation');
      window.ArtdonUI.toast(document.body.classList.contains('ui-presentation') ? '已进入客户展示模式，按 Esc 退出' : '已退出客户展示模式');
    }
    const shell = document.querySelector('.ui-shell');
    if (event.target.closest('[data-ui-sidebar-toggle]')) {
      shell?.classList.toggle('is-collapsed');
      localStorage.setItem('artdon-ui-sidebar', shell?.classList.contains('is-collapsed') ? 'collapsed' : 'expanded');
    }
    if (event.target.closest('[data-ui-mobile-nav]')) document.querySelector('.ui-sidebar')?.classList.toggle('is-mobile-open');
    const pending = event.target.closest('[data-ui-not-connected]');
    if (pending) window.ArtdonUI.toast('该功能尚未接入', 'warning', 5000);
    const tab = event.target.closest('.ui-tab');
    if (tab) {
      tab.closest('.ui-tabs')?.querySelectorAll('.ui-tab').forEach(item => item.setAttribute('aria-selected', String(item === tab)));
      window.ArtdonUI.toast(`已切换到${tab.textContent.trim()}`, 'info', 1800);
    }
  });
  if (localStorage.getItem('artdon-ui-sidebar') === 'collapsed') document.querySelector('.ui-shell')?.classList.add('is-collapsed');
  document.addEventListener('keydown', event => {
    if (event.key === 'Escape' && document.body.classList.contains('ui-presentation') && !window.ArtdonUI.interactions.current().element) {
      document.body.classList.remove('ui-presentation');
    }
    if (event.key === 'Escape') document.querySelector('.ui-sidebar')?.classList.remove('is-mobile-open');
  });
  document.addEventListener('pointerdown', event => {
    const sidebar = document.querySelector('.ui-sidebar');
    if (sidebar?.classList.contains('is-mobile-open') && !sidebar.contains(event.target) && !event.target.closest('[data-ui-mobile-nav]')) sidebar.classList.remove('is-mobile-open');
  });
})();
