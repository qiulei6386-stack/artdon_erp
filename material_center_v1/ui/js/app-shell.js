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
  });
  document.addEventListener('keydown', event => {
    if (event.key === 'Escape' && document.body.classList.contains('ui-presentation') && !window.ArtdonUI.interactions.current().element) {
      document.body.classList.remove('ui-presentation');
    }
  });
})();
