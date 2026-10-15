/* 人员权限：搜索与筛选、改个人设置时实时显示最终结果和来源、未保存提示；数据范围按类型显示取值框。 */
(function () {
  'use strict';

  function choiceOf(item) {
    var checked = item.querySelector('input[type=radio]:checked');
    return checked ? checked.value : 'inherit';
  }

  /* 与服务端顺序一致：超管 → 个人拒绝 → 临时授权 → 个人允许 → 角色 */
  function evaluate(item) {
    var choice = choiceOf(item);
    if (item.getAttribute('data-super') === '1') return [true, '超级管理员', 'badge-brand'];
    if (choice === 'deny') return [false, '个人拒绝', 'badge-bad'];
    if (item.getAttribute('data-grant') === '1') return [true, '临时授权', 'badge-warn'];
    if (choice === 'allow') return [true, '个人允许', 'badge-good'];
    if (item.getAttribute('data-role') === '1') return [true, '角色', ''];
    return [false, '', ''];
  }

  document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('[data-scope-row]').forEach(function (row) {
      var select = row.querySelector('[data-scope-type]');
      var value = row.querySelector('[data-scope-value]');
      select.addEventListener('change', function () {
        value.hidden = select.value !== 'country' && select.value !== 'customer_group';
      });
    });

    var form = document.querySelector('[data-people]');
    if (!form) return;
    var filter = form.querySelector('[data-perm-filter]');
    var onlyOn = form.querySelector('[data-only-on]');
    var onlyOverride = form.querySelector('[data-only-override]');
    var summary = form.querySelector('[data-people-summary]');
    var dirtyBadge = form.querySelector('[data-perm-dirty]');
    var empty = form.querySelector('[data-perm-empty]');
    var items = Array.prototype.slice.call(form.querySelectorAll('[data-perm-item]'));
    var initial = items.map(choiceOf);
    var dirty = false;

    function refresh() {
      var q = (filter.value || '').trim().toLowerCase();
      var on = 0;
      var overrides = 0;
      var anyVisible = false;
      items.forEach(function (item) {
        var result = evaluate(item);
        var choice = choiceOf(item);
        if (result[0]) on++;
        if (choice !== 'inherit') overrides++;
        var state = item.querySelector('[data-state]');
        state.textContent = result[0] ? '✓' : '—';
        item.classList.toggle('is-on', result[0]);
        var source = item.querySelector('[data-source]');
        source.textContent = result[1];
        source.className = 'badge ' + result[2];
        source.hidden = result[1] === '';
        var show = (!q || item.getAttribute('data-search').indexOf(q) !== -1)
          && (!onlyOn.checked || result[0])
          && (!onlyOverride.checked || choice !== 'inherit');
        item.hidden = !show;
      });
      form.querySelectorAll('[data-perm-group]').forEach(function (group) {
        var groupItems = group.querySelectorAll('[data-perm-item]');
        var visible = 0;
        var groupOn = 0;
        groupItems.forEach(function (item) {
          if (!item.hidden) visible++;
          if (item.classList.contains('is-on')) groupOn++;
        });
        group.hidden = visible === 0;
        if (visible) anyVisible = true;
        group.querySelector('[data-group-on]').textContent = '生效 ' + groupOn + ' / ' + groupItems.length;
      });
      summary.textContent = '生效 ' + on + ' / ' + items.length + ' 项 · 个人设置 ' + overrides + ' 项';
      if (empty) empty.hidden = anyVisible;
      dirty = items.some(function (item, i) { return choiceOf(item) !== initial[i]; });
      if (dirtyBadge) dirtyBadge.hidden = !dirty;
    }

    filter.addEventListener('input', refresh);
    onlyOn.addEventListener('change', refresh);
    onlyOverride.addEventListener('change', refresh);
    form.addEventListener('change', refresh);
    form.addEventListener('submit', function () { dirty = false; });
    window.addEventListener('beforeunload', function (event) {
      if (dirty) { event.preventDefault(); event.returnValue = ''; }
    });
    refresh();
  });
})();
