'use strict';

// Real Chrome, synthetic DOM, original page CSS and original highlight module.
// No PHP/app/bootstrap/server/user profile. All contexts are offline with CSP
// and abort-all routing. At least 25 seconds use the real clock and real timers.
// Run: NODE_PATH=<bundled node_modules> <node> this-file.cjs
// Optional: DISPATCH_TEST_CHROME, DISPATCH_TEST_OUTPUT_DIR.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const crypto = require('node:crypto');
const { chromium } = require('playwright');

const root = path.resolve(__dirname, '..');
const pageSource = fs.readFileSync(path.join(root, 'dispatch_next.php'), 'utf8');
const css = [...pageSource.matchAll(/<style\b[^>]*>([\s\S]*?)<\/style>/gi)].map(match => match[1]).join('\n');
const helper = fs.readFileSync(path.join(root, 'assets/dispatch/recent-create.js'), 'utf8');
const richFunctions = ['normalizeInlineColor', 'sanitizeRichHtml', 'editableRichHtml',
  'handleEditableEnter', 'insertEditableNewline'].map(name => {
  const source = pageSource.split('\n').find(line => line.startsWith('function ' + name + '('));
  assert(source, 'Missing original rich-text function: ' + name);
  return source;
}).join('\n');
assert(richFunctions.includes('DispatchRecentCreate.readHTML(el)'), 'Real rich-text save accessor must use clean highlight serialization');
const sourceHashes = { page: crypto.createHash('sha256').update(pageSource).digest('hex'),
  helper: crypto.createHash('sha256').update(helper).digest('hex') };
assert(css.length > 10_000 && !css.includes('<?'), 'Expected original static CSS, without executing PHP');
assert(helper.includes('DispatchRecentCreate'), 'Original highlight helper is required');
const executablePath = process.env.DISPATCH_TEST_CHROME || (process.platform === 'darwin'
  ? '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome' : '/usr/bin/google-chrome');
assert(fs.existsSync(executablePath), 'Provide an installed Chrome via DISPATCH_TEST_CHROME');
const output = process.env.DISPATCH_TEST_OUTPUT_DIR
  ? path.resolve(process.env.DISPATCH_TEST_OUTPUT_DIR)
  : fs.mkdtempSync(path.join(os.tmpdir(), 'dispatch-recent-browser-'));
fs.mkdirSync(output, { recursive: true });
const observations = [];
const failures = [];
const requests = [];
const wait = ms => new Promise(resolve => setTimeout(resolve, Math.max(0, ms)));
const start = [153, 27, 27], end = [255, 255, 255];
const parseRgb = value => {
  const match = String(value).match(/rgba?\(([\d.]+)[,\s]+([\d.]+)[,\s]+([\d.]+)/);
  assert(match, 'Expected an RGB colour, got ' + value);
  return match.slice(1, 4).map(Number);
};
const luminance = rgb => rgb.reduce((sum, value, i) => {
  const c = value / 255;
  return sum + (c <= 0.04045 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4) * [0.2126, 0.7152, 0.0722][i];
}, 0);
const contrast = (one, two) => {
  const a = luminance(one), b = luminance(two);
  return (Math.max(a, b) + 0.05) / (Math.min(a, b) + 0.05);
};

function tableRow(id, due) {
  return '<tr id="' + id + '" data-task-row="' + id + '" class="' + due + '">'
    + '<td data-field="complete"><button class="completeBtn" type="button">✓</button></td>'
    + '<td data-field="priority"><span class="priorityText">普通</span></td>'
    + '<td data-field="title"><div class="titleCell"><div class="cell-edit" contenteditable="true">'
    + '核对样品工作明细 <span class="ref-chip ref-chip-bom" data-semantic="chip"><span class="ref-chip-type">@BOM</span><span class="ref-chip-code">TEST-001</span></span>'
    + '</div><span class="statusDots"><span class="statusDot ' + (due === 'due-overdue' ? 'overdue' : due === 'due-soon' ? 'due_soon' : 'normal') + '" data-semantic="dot"></span></span></div></td>'
    + '<td data-field="project"><div class="cell-edit multiline">合成验收数据，不访问真实客户。<br>保存后定位新建任务。</div></td>'
    + '<td><span class="rowActions"><button class="btn action-detail" data-semantic="button" type="button">详情</button></span></td></tr>';
}

function mobileCard(id, due) {
  return '<article id="' + id + '" class="mobileTaskCard ' + due + '" data-mobile-task="' + id + '">'
    + '<div class="mobileDone"><button class="completeBtn" type="button">✓</button></div><span class="mobilePrio normal">普通</span>'
    + '<div class="mobileMain"><div class="mobileTaskTitle cell-edit" contenteditable="true">核对样品工作明细</div>'
    + '<div class="mobileTitleIcons"><span class="statusDots"><span class="statusDot ' + (due === 'due-overdue' ? 'overdue' : due === 'due-soon' ? 'due_soon' : 'normal') + '" data-semantic="dot"></span></span></div>'
    + '<div class="mobileTaskProject cell-edit multiline">合成验收数据，不访问真实客户。<span class="ref-chip ref-chip-bom" data-semantic="chip"><span class="ref-chip-type">@BOM</span><span class="ref-chip-code">TEST-001</span></span></div>'
    + '<div class="mobileTaskMeta"><span>测试负责人</span><span>派工</span></div></div>'
    + '<div class="mobileDue ' + due + '"><span>截止</span>09/08</div>'
    + '<div class="mobileOps"><button class="btn mini" data-semantic="button" type="button">⋯</button></div></article>';
}

function html(kind) {
  const render = kind === 'desktop' ? tableRow : mobileCard;
  const rows = [['normal', 'due-normal'], ['soon', 'due-soon'], ['overdue', 'due-overdue'],
    ['late', 'due-soon'], ['done-check', 'due-overdue']].map(([id, due]) => render(id, due)).join('');
  const content = kind === 'desktop'
    ? '<div class="tableWrap"><table class="tbl"><colgroup><col style="width:70px"><col style="width:85px"><col style="width:360px"><col style="width:405px"><col style="width:100px"></colgroup><thead><tr><th>完成</th><th>优先级</th><th>任务标题</th><th>项目</th><th>操作</th></tr></thead><tbody>' + rows + '</tbody></table></div>'
    : '<div class="mobileTaskCards">' + rows + '</div>';
  // Harness styles affect only the surrounding canvas/header, not task colours.
  return '<!doctype html><html><head><meta charset="utf-8">'
    + '<meta http-equiv="Content-Security-Policy" content="default-src \'none\'; script-src \'nonce-dispatch-test\'; style-src \'unsafe-inline\'; img-src data:; connect-src \'none\'; font-src \'none\'; base-uri \'none\'; form-action \'none\'">'
    + '<meta name="viewport" content="width=device-width,initial-scale=1"><style>' + css + '</style>'
    + '<style>.testHarness{padding:16px}.testHeading{font:700 18px/1.4 sans-serif;margin:0 0 12px}.testNote{font:13px/1.5 sans-serif;margin:0 0 16px}@media(max-width:760px){.testHarness{padding:8px}}</style>'
    + '</head><body class="due-row-fill-critical"><main class="testHarness"><h1 class="testHeading">新建提醒 · ' + (kind === 'desktop' ? '桌面' : '手机')
    + '</h1><p class="testNote">深红 → 白 · 20 秒线性渐变 · 合成界面验证</p>' + content + '</main>'
    + '<script nonce="dispatch-test">' + (helper + '\n' + richFunctions).replace(/<\/script/gi, '<\\/script') + '</script></body></html>';
}

async function assertRichSerialization(page, kind) {
  const result = await page.evaluate(() => {
    const el = document.createElement('article');
    el.className = 'mobileTaskCard';
    const original = '<span data-rich="original" style="color:rgb(172, 21, 35);background-color:rgb(240, 220, 90);font-weight:700">原始红字</span> '
      + '<span data-rich="plain">未设色文字</span><strong>加粗内容</strong>';
    el.innerHTML = '<div class="cell-edit mobileTaskTitle" contenteditable="true">' + original + '</div>';
    document.body.append(el);
    const editor = el.firstElementChild;
    const expected = document.createElement('div');
    expected.innerHTML = original;
    const until = Date.now() + 20_000;
    DispatchRecentCreate.apply(el, until);
    const cleanBeforeEdits = DispatchRecentCreate.readHTML(editor);
    const savedBeforeEdits = editableRichHtml(editor);
    for (const target of [editor, expected]) {
      const span = target.querySelector('[data-rich="original"]');
      span.textContent = '用户修改了文字';
      span.style.setProperty('color', 'rgb(12, 120, 34)', 'important');
      span.style.backgroundColor = 'rgb(200, 210, 240)';
      span.style.fontWeight = '900';
      span.style.textDecoration = 'underline';
      const added = document.createElement('span');
      added.dataset.rich = 'added';
      added.style.color = 'rgb(150, 80, 10)';
      added.style.fontWeight = '700';
      added.textContent = ' 新增内容';
      target.append(added);
    }
    // Repainting the same DOM must retain actual user styles in the record.
    DispatchRecentCreate.apply(el, until);
    editor.focus();
    const textNode = editor.querySelector('[data-rich="original"]').firstChild;
    const range = document.createRange();
    range.setStart(textNode, 1); range.setEnd(textNode, 3);
    const selection = getSelection(); selection.removeAllRanges(); selection.addRange(range);
    const liveBefore = editor.innerHTML;
    const clean = DispatchRecentCreate.readHTML(editor);
    const saved = editableRichHtml(editor);
    const liveUnchanged = editor.innerHTML === liveBefore && document.activeElement === editor
      && selection.anchorNode === textNode && selection.anchorOffset === 1
      && selection.focusNode === textNode && selection.focusOffset === 3;
    // A focused editor may use its own readable editing colours; the row's
    // timed fill must stay active while serialization remains non-mutating.
    const highlit = el.classList.contains('recentCreate') && getComputedStyle(el).boxShadow.includes('inset');
    const inspect = value => {
      const clone = document.createElement('div'); clone.innerHTML = value;
      return [...clone.querySelectorAll('*')].map(node => ({ tag: node.tagName, text: node.textContent,
        color: node.style.color, colorPriority: node.style.getPropertyPriority('color'), background: node.style.backgroundColor,
        weight: node.style.fontWeight, decoration: node.style.textDecoration,
        caret: node.style.caretColor, transition: node.style.transition, shadow: node.style.boxShadow }));
    };
    DispatchRecentCreate.clear(el);
    const afterClear = editableRichHtml(editor);
    const result = { originalStyles: inspect(original), beforeStyles: inspect(cleanBeforeEdits),
      expectedOriginalSaved: sanitizeRichHtml(original), savedBeforeEdits,
      expectedStyles: inspect(expected.innerHTML), cleanStyles: inspect(clean),
      expectedSaved: sanitizeRichHtml(expected.innerHTML), saved, afterClear, liveUnchanged, highlit };
    el.remove(); DispatchRecentCreate.sweep();
    return result;
  });
  assert.deepEqual(result.beforeStyles, result.originalStyles, kind + ': serialization leaked highlight into original rich-text styles');
  assert.equal(result.savedBeforeEdits, result.expectedOriginalSaved, kind + ': actual save accessor changed original rich text');
  assert.deepEqual(result.cleanStyles, result.expectedStyles, kind + ': serialization lost real inline/content edits or leaked temporary styles');
  assert.equal(result.saved, result.expectedSaved, kind + ': actual save accessor persisted temporary highlight colours');
  assert.equal(result.afterClear, result.saved, kind + ': rich-text save value depends on whether the highlight has expired');
  assert(result.liveUnchanged && result.highlit, kind + ': clean serialization modified the live DOM, focus, selection, or highlight');
  observations.push({ label: kind + '-rich-text-clean-serialization', ...result });
}

async function assertKeyboardRichSerialization(page, kind) {
  const results = [];
  for (const highlighted of [false, true]) {
    await page.evaluate(highlighted => {
      const el = document.createElement('article');
      el.id = 'keyboard-rich'; el.className = 'mobileTaskCard';
      el.innerHTML = '<div class="cell-edit mobileTaskTitle" contenteditable="true"><span style="color:rgb(172,21,35);font-weight:700">ABCD</span> tail</div>';
      document.body.append(el);
      const editor = el.firstElementChild;
      editor.addEventListener('keydown', handleEditableEnter);
      if (highlighted) DispatchRecentCreate.apply(el, Date.now() + 20_000);
      editor.focus();
      // Match applyTextStyle's native editing mode rather than relying on
      // Chrome's default <b> vs styled-span serialization choice.
      document.execCommand('styleWithCSS', false, true);
      const range = document.createRange(); range.setStart(editor.firstChild.firstChild, 2); range.collapse(true);
      getSelection().removeAllRanges(); getSelection().addRange(range);
    }, highlighted);
    await page.keyboard.press('Enter');
    await page.keyboard.type('typed');
    await page.keyboard.press('ControlOrMeta+b');
    await page.keyboard.type('bold');
    const result = await page.evaluate(() => {
      const el = document.getElementById('keyboard-rich'), editor = el.firstElementChild;
      const firstText = document.createTreeWalker(editor, NodeFilter.SHOW_TEXT).nextNode();
      const range = document.createRange(); range.setStart(firstText, 0); range.setEnd(firstText, 1);
      getSelection().removeAllRanges(); getSelection().addRange(range);
      document.execCommand('styleWithCSS', false, true);
      document.execCommand('bold', false, null);
      document.execCommand('foreColor', false, '#2163c8');
      const raw = editor.innerHTML, clean = DispatchRecentCreate.readHTML(editor), saved = editableRichHtml(editor);
      DispatchRecentCreate.clear(el);
      const afterClear = editableRichHtml(editor);
      el.remove(); DispatchRecentCreate.sweep();
      return { raw, clean, saved, afterClear };
    });
    results.push({ highlighted, ...result });
  }
  observations.push({ label: kind + '-real-keyboard-enter-type-bold', results });
  assert(results[0].saved.includes('typed') && results[0].saved.includes('bold'), kind + ': keyboard fixture did not edit rich text');
  assert.equal(results[1].saved, results[0].saved, kind + ': actual Enter/type/bold copied temporary highlight into saved rich text');
  assert.equal(results[1].afterClear, results[0].afterClear, kind + ': keyboard editing left permanent temporary highlight styles');
}

async function assertFocusBlur(page, kind) {
  const result = await page.evaluate(() => {
    const records = [];
    const priorClass = document.body.className;
    for (const dark of [false, true]) {
      document.body.className = (dark ? 'dark ' : '') + 'due-row-fill-critical';
      const el = document.createElement('article'); el.className = 'mobileTaskCard due-soon';
      const canonical = sanitizeRichHtml('<span style="color:#ac1523!important">原有彩色</span><span>普通文字</span>');
      el.innerHTML = '<div class="cell-edit mobileTaskTitle" contenteditable="true">' + canonical + '</div><input class="cell-input" value="原生输入框">';
      document.body.append(el);
      const editor = el.firstElementChild, input = el.lastElementChild;
      const colours = () => ({ rootColor: getComputedStyle(editor).color, background: getComputedStyle(editor).backgroundColor,
        original: getComputedStyle(editor.firstChild).color, normal: getComputedStyle(editor).color });
      editor.focus(); const baseline = colours(); editor.blur();
      DispatchRecentCreate.apply(el, Date.now() + 20_000);
      const blurred = { ...colours(), fill: getComputedStyle(el).boxShadow };
      editor.focus(); const focused = colours();
      const savedFocused = editableRichHtml(editor);
      editor.blur(); const reblurred = { ...colours(), fill: getComputedStyle(el).boxShadow };
      input.focus(); const nativeFocused = { color: getComputedStyle(input).color, background: getComputedStyle(input).backgroundColor };
      input.blur(); const nativeBlurred = { color: getComputedStyle(input).color, background: getComputedStyle(input).backgroundColor };
      DispatchRecentCreate.clear(el);
      records.push({ dark, canonical, baseline, focused, blurred, reblurred, savedFocused, savedAfterClear: editableRichHtml(editor), nativeFocused, nativeBlurred });
      el.remove();
    }
    document.body.className = priorClass; DispatchRecentCreate.sweep();
    return records;
  });
  for (const sample of result) {
    assert(!sample.canonical.includes('!important'), 'Actual rich-text sanitizer must strip inline !important');
    assert.deepEqual(sample.focused, sample.baseline, kind + ': highlight changed native focused editor colours');
    assert.equal(sample.savedFocused, sample.canonical, kind + ': focus caused rich-text colour persistence');
    assert.equal(sample.savedAfterClear, sample.canonical, kind + ': focus/blur altered original rich text');
    assert(contrast(parseRgb(sample.focused.normal), parseRgb(sample.focused.background)) >= 4.4, kind + ': normal focused text is unreadable');
    for (const state of [sample.blurred, sample.reblurred]) {
      for (const key of ['normal', 'original']) assert(contrast(parseRgb(state[key]), parseRgb(state.fill)) >= 4.4, kind + ': blurred editor text is unreadable');
    }
    for (const state of [sample.nativeFocused, sample.nativeBlurred]) assert(contrast(parseRgb(state.color), parseRgb(state.background)) >= 4.4, kind + ': native input is unreadable');
    // Arbitrary existing rich-text colours can already be low-contrast in a
    // theme. Record them explicitly; focus must not change their colour or make
    // the pre-existing editor state worse merely to satisfy this animation.
    sample.existingColourFocusedContrast = contrast(parseRgb(sample.focused.original), parseRgb(sample.focused.background));
  }
  observations.push({ label: kind + '-focus-blur-readability-and-save', results: result });
}

async function capture(page, label) {
  const sample = await page.evaluate(() => {
    const colour = node => {
      const style = getComputedStyle(node);
      return { color: style.color, textFill: style.webkitTextFillColor, background: style.backgroundColor };
    };
    const result = { now: Date.now(), bodyClass: document.body.className, rows: {} };
    for (const id of ['normal', 'soon', 'overdue', 'late']) {
      const el = document.getElementById(id);
      const fill = el.tagName === 'TR' ? [...el.children] : [el];
      const text = el.querySelector('.cell-edit');
      result.rows[id] = {
        recent: el.classList.contains('recentCreate'),
        expiry: window.deadlines[id] || 0,
        fill: fill.map(node => ({ shadow: getComputedStyle(node).boxShadow,
          background: getComputedStyle(node).backgroundColor,
          border: getComputedStyle(node).borderLeftColor,
          transition: getComputedStyle(node).transitionTimingFunction })),
        text: colour(text),
        buttonDiagnostics: [...el.querySelectorAll('[data-semantic="button"]')].map(node => ({
          className: node.className, inline: node.getAttribute('style'),
          backgroundImage: getComputedStyle(node).backgroundImage,
          transitionProperty: getComputedStyle(node).transitionProperty,
          transitionDuration: getComputedStyle(node).transitionDuration,
        })),
        semantics: [...el.querySelectorAll('[data-semantic]')].map(node => ({ kind: node.dataset.semantic, ...colour(node),
          children: [...node.children].map(colour) })),
      };
    }
    return result;
  });
  observations.push({ label, ...sample });
  return sample;
}

function assertProgress(sample, ids = ['normal', 'soon', 'overdue']) {
  for (const id of ids) {
    const row = sample.rows[id];
    assert(row.recent, id + ': highlight should still be active');
    const progress = Math.min(1, Math.max(0, (sample.now - (row.expiry - 20_000)) / 20_000));
    const expected = start.map((value, index) => value + (end[index] - value) * progress);
    for (const [index, cell] of row.fill.entries()) {
      const actual = parseRgb(cell.shadow);
      actual.forEach((value, channel) => assert(Math.abs(value - expected[channel]) <= 5,
        id + ' cell ' + index + ': expected linear colour ' + expected + ', got ' + actual));
      assert(cell.shadow.includes('inset'), id + ': fill must paint over actual deadline backgrounds');
      assert(cell.transition.split(',').every(value => value.trim() === 'linear'), id + ': transition must remain linear');
      if (index === 0) parseRgb(cell.border).forEach((value, channel) => assert(Math.abs(value - expected[channel]) <= 5, id + ': first-cell left border is not synchronized'));
    }
    assert(contrast(parseRgb(row.text.textFill), parseRgb(row.fill[0].shadow)) >= 4.4,
      id + ': task text loses readable contrast');
    for (const semantic of row.semantics.filter(item => item.kind === 'button')) {
      const backing = semantic.background === 'rgba(0, 0, 0, 0)' ? parseRgb(row.fill[0].shadow) : parseRgb(semantic.background);
      assert(contrast(parseRgb(semantic.textFill), backing) >= 4.4, id + ': action button text loses readable contrast');
    }
  }
}

async function assertMatrix(page, kind, label) {
  for (const theme of ['light', 'dark']) {
    for (const fill of ['off', 'critical']) {
      await page.evaluate(({ theme, fill }) => {
        document.body.className = (theme === 'dark' ? 'dark ' : '') + 'due-row-fill-' + fill;
        DispatchRecentCreate.sweep();
      }, { theme, fill });
      const sample = await capture(page, kind + '-' + label + '-' + theme + '-' + fill);
      assertProgress(sample);
      for (const id of ['normal', 'soon', 'overdue']) {
        const expected = await page.evaluate(({ id, theme, fill }) => window.semanticBaseline[theme + '-' + fill][id], { id, theme, fill });
        const actual = sample.rows[id].semantics;
        assert.equal(actual.length, expected.length);
        actual.forEach((item, index) => {
          const original = expected[index];
          // A formerly transparent text button may receive neutral opaque
          // backing for contrast; semantic text and existing coloured fills stay.
          if (item.kind === 'button' && original.background === 'rgba(0, 0, 0, 0)') {
            assert.deepEqual({ ...item, background: original.background }, original, kind + '/' + id + ': button semantic text colour changed');
          } else assert.deepEqual(item, original, kind + '/' + id + ': dot/reference/opaque-button semantic colours changed');
        });
      }
    }
  }
  await page.evaluate(() => { document.body.className = 'due-row-fill-critical'; DispatchRecentCreate.sweep(); });
}

(async () => {
  let browser;
  try {
    browser = await chromium.launch({ executablePath, headless: true, chromiumSandbox: true,
      args: ['--disable-background-networking', '--disable-component-update', '--disable-sync', '--no-first-run',
        '--disable-default-apps', '--disable-extensions', '--host-resolver-rules=MAP * ~NOTFOUND'] });
    const scenes = [];
    for (const kind of ['desktop', 'mobile']) {
      const context = await browser.newContext({ viewport: kind === 'desktop' ? { width: 1240, height: 780 } : { width: 390, height: 844 },
        deviceScaleFactor: 1, isMobile: kind === 'mobile', hasTouch: kind === 'mobile', offline: true, serviceWorkers: 'block' });
      await context.route('**/*', route => { requests.push(route.request().url()); return route.abort('blockedbyclient'); });
      const page = await context.newPage();
      page.on('pageerror', error => failures.push(kind + ': ' + error.message));
      page.on('console', message => { if (message.type() === 'error') failures.push(kind + ' console: ' + message.text()); });
      await page.setContent(html(kind), { waitUntil: 'load' });
      await page.evaluate(() => {
        if (!window.DispatchRecentCreate) throw new Error('Original helper did not load');
        window.templates = {};
        window.deadlines = {};
        window.semanticBaseline = {};
        for (const el of document.querySelectorAll('[data-task-row],[data-mobile-task]')) window.templates[el.id] = el.outerHTML;
      });
      for (const theme of ['light', 'dark']) {
        for (const fill of ['off', 'critical']) {
          await page.evaluate(({ theme, fill }) => { document.body.className = (theme === 'dark' ? 'dark ' : '') + 'due-row-fill-' + fill; }, { theme, fill });
          const sample = await capture(page, kind + '-baseline-' + theme + '-' + fill);
          await page.evaluate(({ key, rows }) => {
            window.semanticBaseline[key] = Object.fromEntries(Object.entries(rows).map(([id, row]) => [id, row.semantics]));
          }, { key: theme + '-' + fill, rows: sample.rows });
        }
      }
      await page.evaluate(() => { document.body.className = 'due-row-fill-critical'; });
      await assertRichSerialization(page, kind);
      await assertKeyboardRichSerialization(page, kind);
      await assertFocusBlur(page, kind);
      scenes.push({ kind, page, context });
    }
    const wallStarted = Date.now();
    await Promise.all(scenes.map(({ page }) => page.evaluate(() => {
      window.startedAt = Date.now();
      for (const id of ['normal', 'soon', 'overdue']) {
        window.deadlines[id] = window.startedAt + 20_000;
        DispatchRecentCreate.apply(document.getElementById(id), window.deadlines[id]);
      }
    })));
    for (const { kind, page } of scenes) {
      const initial = await capture(page, kind + '-initial');
      try { assertProgress(initial); } catch (error) {
        // Preserve the strict first-frame assertion while providing evidence
        // whether a pre-existing short transition delayed the neutral backing.
        await wait(180);
        await capture(page, kind + '-initial-failure-after-180ms');
        throw error;
      }
      await assertMatrix(page, kind, 'start');
      await page.screenshot({ path: path.join(output, kind + '-start.png'), fullPage: true });
      await page.evaluate(() => { document.body.classList.add('dark'); DispatchRecentCreate.sweep(); });
      await page.screenshot({ path: path.join(output, kind + '-dark-start.png'), fullPage: true });
      await page.evaluate(() => { document.body.classList.remove('dark'); DispatchRecentCreate.sweep(); });
    }
    await wait(wallStarted + 5_000 - Date.now());
    for (const { kind, page } of scenes) {
      const repeated = await page.evaluate(() => {
        const el = document.getElementById('soon');
        const node = el.tagName === 'TR' ? el.firstElementChild : el;
        const before = node.getAnimations().find(animation => animation.transitionProperty === 'box-shadow');
        const beforeTime = before && before.startTime;
        DispatchRecentCreate.apply(el, window.deadlines.soon);
        const after = node.getAnimations().find(animation => animation.transitionProperty === 'box-shadow');
        return { same: before === after, beforeTime, afterTime: after && after.startTime };
      });
      assert(repeated.same && repeated.beforeTime === repeated.afterTime, kind + ': reapplying the same DOM/deadline restarted transition');
      await page.evaluate(() => {
        document.getElementById('normal').outerHTML = window.templates.normal;
        DispatchRecentCreate.apply(document.getElementById('normal'), window.deadlines.normal);
        window.deadlines.late = Date.now() + 20_000;
        DispatchRecentCreate.apply(document.getElementById('late'), window.deadlines.late);
        DispatchRecentCreate.sweep();
      });
      assertProgress(await capture(page, kind + '-redraw-at-5s'));
      const clearResult = await page.evaluate(() => {
        const el = document.getElementById('done-check');
        const node = el.tagName === 'TR' ? el.firstElementChild : el;
        node.style.setProperty('font-weight', '650');
        DispatchRecentCreate.apply(el, Date.now() + 20_000);
        node.style.setProperty('outline', '2px solid rgb(23, 61, 99)');
        node.style.setProperty('box-shadow', 'inset 1px 0 0 rgb(12, 34, 56)', 'important');
        el.classList.add('done');
        DispatchRecentCreate.clear(el);
        return { className: el.className, outline: node.style.outline, shadow: node.style.boxShadow,
          weight: node.style.fontWeight, textStyles: el.querySelector('.cell-edit').getAttribute('style'),
          transition: node.style.transition, animation: node.style.animation };
      });
      assert(!clearResult.className.includes('recentCreate') && clearResult.className.includes('done'), kind + ': done clear failed');
      assert.equal(clearResult.weight, '650');
      assert(clearResult.outline.includes('23, 61, 99') && clearResult.shadow.includes('12, 34, 56'), kind + ': clear removed later unrelated/externally-owned inline edits');
      assert(!clearResult.transition && !clearResult.animation && !clearResult.textStyles, kind + ': clear left its own inline residue');
      const restore = await page.evaluate(() => {
        const el = document.createElement('div');
        el.className = 'mobileTaskCard';
        el.innerHTML = '<div class="mobileTaskTitle">原始过渡恢复测试</div>';
        el.style.boxShadow = 'inset 0 0 0 9999px rgb(31, 64, 97)';
        document.body.append(el);
        el.getBoundingClientRect();
        // Longhands only: clearing must not invent an inline shorthand/property
        // or start a second two-second transition after the highlight ends.
        el.style.setProperty('transition-duration', '2s');
        el.style.setProperty('transition-delay', '100ms');
        const before = el.style.cssText;
        DispatchRecentCreate.apply(el, Date.now() + 20_000);
        DispatchRecentCreate.clear(el);
        const result = { before, after: el.style.cssText, shadow: getComputedStyle(el).boxShadow };
        el.remove();
        DispatchRecentCreate.sweep();
        return result;
      });
      assert.equal(restore.after, restore.before, kind + ': prior transition longhands were not exactly restored');
      assert.deepEqual(parseRgb(restore.shadow), [31, 64, 97], kind + ': clear started an unwanted second transition');
    }
    await wait(wallStarted + 10_000 - Date.now());
    for (const { kind, page } of scenes) {
      await assertMatrix(page, kind, 'mid');
      await page.screenshot({ path: path.join(output, kind + '-mid.png'), fullPage: true });
    }
    await wait(wallStarted + 19_750 - Date.now());
    for (const { kind, page } of scenes) {
      const sample = await capture(page, kind + '-near-end');
      assertProgress(sample);
      parseRgb(sample.rows.normal.fill[0].shadow).forEach(channel => assert(channel >= 249, kind + ': gradient did not approach white'));
    }
    await wait(wallStarted + 20_350 - Date.now());
    for (const { kind, page } of scenes) {
      // No sweep here: the real expiry timer must clean the styles by itself.
      const state = await page.evaluate(() => Object.fromEntries(['normal','soon','overdue','late'].map(id => {
        const el = document.getElementById(id);
        return [id, { recent: el.classList.contains('recentCreate'),
          owned: [...el.querySelectorAll('*'), el].filter(node => (node.getAttribute('style') || '').trim()).length }];
      })));
      for (const id of ['normal', 'soon', 'overdue']) assert(!state[id].recent && state[id].owned === 0, kind + ': 20-second expiry did not clean ' + id);
      assert(state.late.recent, kind + ': independently later deadline was cleared too early');
      await capture(page, kind + '-expired-at-20s');
      await page.screenshot({ path: path.join(output, kind + '-end.png'), fullPage: true });
    }
    await wait(wallStarted + 25_750 - Date.now());
    for (const { kind, page } of scenes) {
      assert.equal(await page.locator('.recentCreate').count(), 0, kind + ': later independent expiry did not clean');
      await page.evaluate(() => DispatchRecentCreate.sweep());
      await capture(page, kind + '-all-expired');
    }
    assert(Date.now() - wallStarted >= 25_000, 'Real timer lifecycle was not exercised');
    assert.deepEqual(failures, [], 'Browser emitted runtime/CSP errors');
    assert.deepEqual(requests, [], 'Synthetic fixture attempted an external request (all were blocked)');
    fs.writeFileSync(path.join(output, 'observations.json'), JSON.stringify({ sourceHashes, realElapsedMs: Date.now() - wallStarted, observations }, null, 2));
    console.log('PASS: real Chrome linear colour/contrast, desktop/mobile CSS matrix, 5-second redraw, same-DOM identity, semantic colours, independent real expiries, ownership cleanup.');
    console.log('Screenshots and observations: ' + output);
  } catch (error) {
    fs.writeFileSync(path.join(output, 'failure.json'), JSON.stringify({ sourceHashes, error: error.stack, failures, requests, observations }, null, 2));
    console.error('Browser artifacts: ' + output);
    throw error;
  } finally {
    if (browser) await browser.close();
  }
})().catch(error => { console.error(error.stack); process.exitCode = 1; });
