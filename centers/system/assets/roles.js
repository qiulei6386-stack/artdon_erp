/* 角色权限矩阵：搜索、只看已勾选、按模块全选、计数、未保存提示。 */
(function () {
  'use strict';

  document.addEventListener('DOMContentLoaded', function () {
    var form = document.querySelector('[data-matrix]');
    if (!form) return;
    var filter = form.querySelector('[data-perm-filter]');
    var onlyChecked = form.querySelector('[data-perm-only-checked]');
    var summary = form.querySelector('[data-perm-summary]');
    var dirtyBadge = form.querySelector('[data-perm-dirty]');
    var empty = form.querySelector('[data-perm-empty]');
    var boxes = Array.prototype.slice.call(form.querySelectorAll('input[type=checkbox][name="permissions[]"]'));
    var initial = boxes.map(function (b) { return b.checked; });
    var dirty = false;

    function refresh() {
      var q = (filter.value || '').trim().toLowerCase();
      var only = onlyChecked.checked;
      var total = 0;
      var checked = 0;
      var visibleAny = false;
      form.querySelectorAll('[data-perm-group]').forEach(function (group) {
        var items = group.querySelectorAll('[data-perm-item]');
        var groupChecked = 0;
        var groupVisible = 0;
        var enabled = 0;
        var enabledChecked = 0;
        items.forEach(function (item) {
          var box = item.querySelector('input[type=checkbox]');
          total++;
          if (box.checked) { checked++; groupChecked++; }
          if (!box.disabled) { enabled++; if (box.checked) enabledChecked++; }
          var show = (!q || item.getAttribute('data-search').indexOf(q) !== -1) && (!only || box.checked);
          item.hidden = !show;
          if (show) groupVisible++;
        });
        group.hidden = groupVisible === 0;
        if (groupVisible) visibleAny = true;
        var count = group.querySelector('[data-perm-group-count]');
        if (count) count.textContent = groupChecked + ' / ' + items.length;
        var all = group.querySelector('[data-perm-all]');
        if (all) {
          all.checked = enabled > 0 && enabledChecked === enabled;
          all.indeterminate = enabledChecked > 0 && enabledChecked < enabled;
          all.disabled = enabled === 0;
        }
      });
      summary.textContent = '已选 ' + checked + ' / ' + total + ' 项';
      if (empty) empty.hidden = visibleAny;
      dirty = boxes.some(function (b, i) { return b.checked !== initial[i]; });
      if (dirtyBadge) dirtyBadge.hidden = !dirty;
    }

    filter.addEventListener('input', refresh);
    onlyChecked.addEventListener('change', refresh);
    form.addEventListener('change', function (event) {
      var all = event.target.closest('[data-perm-all]');
      if (all) {
        var group = all.closest('[data-perm-group]');
        group.querySelectorAll('[data-perm-item]:not([hidden]) input[type=checkbox]:not(:disabled)').forEach(function (b) {
          b.checked = all.checked;
        });
      }
      refresh();
    });
    form.addEventListener('submit', function () { dirty = false; });
    window.addEventListener('beforeunload', function (event) {
      if (dirty) { event.preventDefault(); event.returnValue = ''; }
    });
    refresh();
  });
})();
