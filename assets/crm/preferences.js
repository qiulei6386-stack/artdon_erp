(function () {
  window.CRM_APPLY_PREFERENCES = function (prefs) {
    if (!prefs) return;
    document.documentElement.dataset.crmTheme = prefs.theme_name || 'compact-light';
    document.documentElement.dataset.crmDensity = prefs.density_mode || 'compact';
    document.documentElement.dataset.crmAnimation = prefs.animation_mode || 'subtle';
    document.documentElement.dataset.crmTabLabel = prefs.tab_label_mode || 'icon_short';
    document.querySelectorAll('[data-crm-tab] strong').forEach(function (node) {
      node.textContent = (prefs.tab_label_mode === 'full') ? node.dataset.full : node.dataset.short;
    });
    var root = document.body;
    if (!root) return;
    var map = {
      '--crm-font-size-base': (prefs.font_scale || 12) + 'px',
      '--crm-font-size-table': (prefs.font_scale || 12) + 'px',
      '--crm-font-size-email-list': (prefs.email_list_font_size || 12) + 'px',
      '--crm-font-size-email-body': (prefs.email_body_font_size || 13) + 'px',
      '--crm-font-size-email-editor': (prefs.email_editor_font_size || 13) + 'px',
      '--crm-topbar-height': (prefs.topbar_height || 48) + 'px',
      '--crm-tabbar-height': (prefs.tabbar_height || 38) + 'px',
      '--crm-actionbar-width': (prefs.actionbar_width || 220) + 'px',
      '--crm-row-height': (prefs.table_row_height || 28) + 'px'
    };
    Object.keys(map).forEach(function (key) { root.style.setProperty(key, map[key]); });
  };
})();
