(function (global) {
  'use strict';

  var DURATION = 20000;
  var START = [153, 27, 27];
  var END = [255, 255, 255];
  var records = new WeakMap();
  var active = new Set();
  var textRoots = '.cell-edit,.displayCell,.lockedText,.dateDisplay,.userDisplay,.groupAssignees,.tableMethodText,.priorityText,.prioText,.mobileTaskTitle,.mobileTaskProject,.mobileTaskMeta,.mobileDue,td[data-field="priority"]';
  var widgets = '.statusDots,.statusDot,.statusIconBadge,.statusIcon,.pill,.pillCell,.methodPill,.tag,.ref-chip,.linkToken,.completeBtn,.groupCompleteBtn,.rowActions,.mobileOps,.mobilePrio,.btn,.row-drag,.batchSelect,svg,img';
  var controls = 'input,select,textarea';
  var terminal = '.done,.complete,.completed,.cancelled,.canceled';
  var editable = '.cell-edit[contenteditable="true"]';
  // Repeated class specificity beats the page's legacy theme !important rules.
  // Editing itself keeps the normal focused editor colours: native formatting
  // commands must not sample or copy a temporary highlight colour into HTML.
  var editableScope = '.recentCreate.recentCreate.recentCreate.recentCreate ' + editable + ':not(:focus)';
  var exclusions = widgets.split(',').concat(controls.split(','));
  var editableChildren = editableScope + ' *:not(' + exclusions.concat(exclusions.map(function (selector) { return selector + ' *'; })).join(',') + ')';
  var inkStyle = global.document.createElement('style');
  inkStyle.setAttribute('data-dispatch-recent-create', 'ink');
  inkStyle.textContent = editableScope + ',' + editableChildren + '{color:var(--dispatch-recent-ink,#000)!important;background-color:transparent!important}';
  (global.document.head || global.document.documentElement).appendChild(inkStyle);

  function channels(progress) {
    return START.map(function (value, index) { return value + (END[index] - value) * progress; });
  }

  function luminance(rgb) {
    return rgb.reduce(function (sum, value, index) {
      var channel = Math.max(0, Math.min(255, value)) / 255;
      var linear = channel <= 0.04045 ? channel / 12.92 : Math.pow((channel + 0.055) / 1.055, 2.4);
      return sum + [0.2126, 0.7152, 0.0722][index] * linear;
    }, 0);
  }

  // At this luminance, white and black offer the same contrast (about 4.58:1).
  var textThreshold = Math.sqrt(1.05 * 0.05) - 0.05;
  var low = 0, high = 1;
  for (var search = 0; search < 32; search++) {
    var middle = (low + high) / 2;
    if (luminance(channels(middle)) < textThreshold) low = middle;
    else high = middle;
  }
  var textSwitchProgress = high;

  function progressAt(record, now) {
    return Math.max(0, Math.min(1, (now - (record.until - DURATION)) / DURATION));
  }

  function rgbText(progress) {
    return 'rgb(' + channels(progress).map(Math.round).join(', ') + ')';
  }

  function sameValue(node, property, value, priority) {
    return node.style.getPropertyValue(property) === value && node.style.getPropertyPriority(property) === priority;
  }

  function write(record, node, property, value) {
    if (!node || !node.style) return;
    var properties = record.styles.get(node);
    if (!properties) { properties = new Map(); record.styles.set(node, properties); }
    var saved = properties.get(property);
    if (!saved) {
      saved = { value: node.style.getPropertyValue(property), priority: node.style.getPropertyPriority(property) };
      properties.set(property, saved);
    } else if (!sameValue(node, property, saved.appliedValue, saved.appliedPriority)) {
      // Preserve an inline edit made by another feature while the highlight ran.
      saved.value = node.style.getPropertyValue(property);
      saved.priority = node.style.getPropertyPriority(property);
    }
    node.style.setProperty(property, value, 'important');
    saved.appliedValue = node.style.getPropertyValue(property);
    saved.appliedPriority = node.style.getPropertyPriority(property);
  }

  function stopBackgroundTransition(record, node, computed) {
    if (record.backedTransitions.has(node)) return;
    record.backedTransitions.add(node);
    var properties = computed.transitionProperty.split(',').map(function (value) { return value.trim(); });
    if (!properties.some(function (value) { return /^(all|background|background-color)$/.test(value); })) return;
    var durations = computed.transitionDuration.split(',');
    var delays = computed.transitionDelay.split(',');
    // The final, property-specific entry wins over an existing background/all
    // transition. Other hover/focus transitions retain their original timing.
    write(record, node, 'transition-duration', properties.map(function (_, index) { return durations[index % durations.length].trim(); }).concat('0s').join(', '));
    write(record, node, 'transition-delay', properties.map(function (_, index) { return delays[index % delays.length].trim(); }).concat('0s').join(', '));
    write(record, node, 'transition-property', properties.concat('background-color').join(', '));
  }

  function paintControls(record) {
    record.el.querySelectorAll(controls).forEach(function (node) {
      if (node.closest(widgets) || /^(hidden|checkbox|radio|color|file|button|submit|reset)$/i.test(node.type || '')) return;
      // Keep the control's own priority/meaning color, with an opaque backing.
      // No control color, value, disabled state, border, or event is replaced.
      var computed = global.getComputedStyle(node);
      var color = computed.color;
      var components = color.match(/[\d.]+/g);
      if (!components || components.length < 3) return;
      var light = luminance(components.slice(0, 3).map(Number));
      stopBackgroundTransition(record, node, computed);
      write(record, node, 'background-color', light < textThreshold ? '#ffffff' : '#000000');
    });
    record.el.querySelectorAll('.rowActions button.btn,.mobileOps button.btn').forEach(function (node) {
      if (node.matches('.completeBtn,.groupCompleteBtn') || node.closest('.pill,.tag,.ref-chip,.statusDots,.statusIconBadge')) return;
      var computed = global.getComputedStyle(node);
      var properties = record.styles.get(node);
      var saved = properties && properties.get('background-color');
      var ourBacking = saved && sameValue(node, 'background-color', saved.appliedValue, saved.appliedPriority);
      var background = computed.backgroundColor.replace(/\s/g, '');
      var transparent = background === 'transparent' || /^rgba\([^,]+,[^,]+,[^,]+,0(?:\.0+)?\)$/.test(background);
      // Opaque semantic buttons, icons, borders and handlers remain unchanged.
      // Only transparent actions need a neutral backing on the dark-red fill.
      if ((!ourBacking && !transparent) || computed.backgroundImage !== 'none') return;
      var components = computed.color.match(/[\d.]+/g);
      if (!components || components.length < 3) return;
      var light = luminance(components.slice(0, 3).map(Number));
      stopBackgroundTransition(record, node, computed);
      write(record, node, 'background-color', light < textThreshold ? '#ffffff' : '#000000');
    });
  }

  function paintText(record, now) {
    var color = luminance(channels(progressAt(record, now))) < textThreshold ? '#ffffff' : '#000000';
    write(record, record.el, '--dispatch-recent-ink', color);
    var targets = new Set();
    record.el.querySelectorAll(textRoots).forEach(function (root) {
      targets.add(root);
      root.querySelectorAll('*').forEach(function (node) { targets.add(node); });
    });
    // Some configurable columns contain a plain text node without a wrapper.
    record.fillNodes.forEach(function (node) {
      if (Array.prototype.some.call(node.childNodes, function (child) { return child.nodeType === 3 && child.textContent.trim(); })) targets.add(node);
    });
    targets.forEach(function (node) {
      // Never put presentation attributes into editable rich text. Browser
      // formatting can split/clone descendants, losing a per-node style ledger.
      if (!node.style || node.closest(editable) || node.closest(widgets + ',' + controls)) return;
      write(record, node, 'color', color);
      write(record, node, 'caret-color', color);
      // Read-only text backings must not mask the gradient; editors use CSS above.
      write(record, node, 'background-color', 'transparent');
    });
    paintControls(record);
    record.textColor = color;
  }

  function clear(el) {
    if (!el || !el.classList) return;
    var record = records.get(el);
    el.classList.remove('recentCreate');
    if (!record) return;
    global.clearTimeout(record.expiryTimer);
    global.clearTimeout(record.textTimer);
    if (record.controlChanged) {
      el.removeEventListener('change', record.controlChanged, true);
      el.removeEventListener('focusin', record.controlChanged, true);
      el.removeEventListener('pointerover', record.controlChanged, true);
      el.removeEventListener('pointerout', record.controlChanged, true);
    }
    // Stop our transition before restoring the normal due/selection/theme layer.
    var ownedTransitions = [];
    record.styles.forEach(function (properties, node) {
      var saved = properties.get('transition-property');
      if (saved && sameValue(node, 'transition-property', saved.appliedValue, saved.appliedPriority)) {
        write(record, node, 'transition-property', 'none');
        ownedTransitions.push({ node: node, saved: saved });
      }
    });
    record.styles.forEach(function (properties, node) {
      properties.forEach(function (saved, property) {
        if (property === 'transition-property') return;
        if (!sameValue(node, property, saved.appliedValue, saved.appliedPriority)) return;
        if (saved.value) node.style.setProperty(property, saved.value, saved.priority);
        else node.style.removeProperty(property);
      });
    });
    if (ownedTransitions.length) {
      // Settle the restored fill before re-enabling a pre-existing transition:all.
      // Otherwise that transition could extend the highlight beyond its deadline.
      el.getBoundingClientRect();
      ownedTransitions.forEach(function (entry) {
        var saved = entry.saved;
        if (!sameValue(entry.node, 'transition-property', saved.appliedValue, saved.appliedPriority)) return;
        if (saved.value) entry.node.style.setProperty('transition-property', saved.value, saved.priority);
        else entry.node.style.removeProperty('transition-property');
      });
    }
    active.delete(record);
    records.delete(el);
  }

  function schedule(record, now) {
    var remaining = record.until - now;
    record.expiryTimer = global.setTimeout(function expire() {
      if (records.get(record.el) !== record) return;
      var current = Date.now();
      if (!record.el.isConnected || current >= record.until) { clear(record.el); return; }
      record.expiryTimer = global.setTimeout(expire, Math.max(1, record.until - current));
    }, Math.max(1, remaining));
    var switchAt = record.until - DURATION + textSwitchProgress * DURATION;
    if (now < switchAt) {
      record.textTimer = global.setTimeout(function changeText() {
        if (records.get(record.el) !== record) return;
        var current = Date.now();
        if (!record.el.isConnected || current >= record.until) { clear(record.el); return; }
        if (current < switchAt) { record.textTimer = global.setTimeout(changeText, Math.max(1, switchAt - current)); return; }
        paintText(record, current);
      }, Math.max(1, switchAt - now));
    }
  }

  function apply(el, until, now) {
    if (!el || !el.style || !el.classList) return;
    now = Number.isFinite(Number(now)) ? Number(now) : Date.now();
    until = Number(until);
    if (!Number.isFinite(until) || until <= now || until <= 0 || !el.isConnected || el.matches(terminal)) { clear(el); return; }
    var existing = records.get(el);
    if (existing && existing.requestedUntil === until) {
      if (now >= existing.until) clear(el);
      else paintText(existing, now); // Refresh newly edited text, never restart the fill.
      return;
    }
    if (existing) clear(el);
    var fillNodes = el.tagName === 'TR'
      ? Array.prototype.filter.call(el.children, function (node) { return node.tagName === 'TD'; }) : [el];
    if (!fillNodes.length) return;
    var record = { el: el, requestedUntil: until, until: Math.min(until, now + DURATION), fillNodes: fillNodes, styles: new Map(), backedTransitions: new WeakSet() };
    records.set(el, record);
    active.add(record);
    el.classList.add('recentCreate');
    var started = Date.now();
    var initial = rgbText(progressAt(record, now));
    fillNodes.forEach(function (node, index) {
      // Own longhands only: shorthand replacement could lose a pre-existing
      // animation-duration or transition-delay that was set independently.
      write(record, node, 'animation-name', 'none');
      write(record, node, 'transition-property', 'none');
      write(record, node, 'box-shadow', 'inset 0 0 0 9999px ' + initial);
      if (index === 0) write(record, node, 'border-left-color', initial);
    });
    paintText(record, now);
    // One style/layout flush per new DOM+deadline; no animation-frame JS loop.
    el.getBoundingClientRect();
    var targetNow = now + Math.max(0, Date.now() - started);
    var remaining = Math.max(0, record.until - targetNow);
    if (remaining <= 0) { clear(el); return; }
    fillNodes.forEach(function (node, index) {
      write(record, node, 'transition-duration', remaining + 'ms');
      write(record, node, 'transition-timing-function', 'linear');
      write(record, node, 'transition-delay', '0ms');
      write(record, node, 'transition-property', 'box-shadow, border-left-color');
      write(record, node, 'box-shadow', 'inset 0 0 0 9999px rgb(255, 255, 255)');
      if (index === 0) write(record, node, 'border-left-color', '#ffffff');
    });
    record.controlChanged = function () { if (records.get(el) === record) paintControls(record); };
    el.addEventListener('change', record.controlChanged, true);
    el.addEventListener('focusin', record.controlChanged, true);
    el.addEventListener('pointerover', record.controlChanged, true);
    el.addEventListener('pointerout', record.controlChanged, true);
    schedule(record, targetNow);
  }

  function sweep() {
    var now = Date.now();
    Array.from(active).forEach(function (record) {
      if (!record.el.isConnected || record.el.matches(terminal) || now >= record.until) clear(record.el);
      else paintText(record, now);
    });
  }

  function readHTML(el) {
    if (!el) return '';
    var container = el, record;
    while (container && !record) {
      record = records.get(container);
      container = container.parentElement;
    }
    if (!record) return el.innerHTML;
    // Editable rich text must never persist temporary presentation styles.
    // Clean a clone only, and retain any actual inline edit the user made.
    var clone = el.cloneNode(true);
    var originals = [el].concat(Array.prototype.slice.call(el.querySelectorAll('*')));
    var copies = [clone].concat(Array.prototype.slice.call(clone.querySelectorAll('*')));
    originals.forEach(function (node, index) {
      var properties = record.styles.get(node);
      if (!properties) return;
      properties.forEach(function (saved, property) {
        if (!sameValue(node, property, saved.appliedValue, saved.appliedPriority)) return;
        if (saved.value) copies[index].style.setProperty(property, saved.value, saved.priority);
        else copies[index].style.removeProperty(property);
      });
    });
    return clone.innerHTML;
  }

  global.document.addEventListener('visibilitychange', sweep);
  global.DispatchRecentCreate = Object.freeze({ apply: apply, clear: clear, sweep: sweep, readHTML: readHTML });
})(window);
