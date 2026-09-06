'use strict';

// Run with Node only. Evaluate a small whitelist of the real page functions in
// a fresh VM for each case; never load PHP, the application, or any live API.
// The animation module is a recording stub here. Its real DOM/CSS behavior has
// a separate test; this suite owns deadlines and the page-to-animation contract.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const source = fs.readFileSync(path.resolve(__dirname, '../dispatch_next.php'), 'utf8');
const duration = 20_000;
const origin = Date.parse('2026-09-06T10:00:00.000Z');
const functionNames = [
  'highlightKey', 'markRecentCreated', 'isRecentCreateFallback',
  'recentCreateUntil', 'rowHighlightClass', 'allVisibleRows',
  'escSelector', 'forceRecentCreateMarks', 'editableRichHtml',
];

function extractFunction(name) {
  const matches = [...source.matchAll(new RegExp('^function ' + name + '\\(', 'gm'))];
  assert.equal(matches.length, 1, 'one original function required: ' + name);
  const start = matches[0].index;
  const next = /^(?:async )?function /gm;
  next.lastIndex = start + matches[0][0].length;
  const boundary = next.exec(source)?.index ?? source.length;
  // Let the JS parser find the first complete function, including default
  // object arguments, template strings and nested braces. No candidate runs.
  for (let end = source.indexOf('}', start); end !== -1 && end < boundary; end = source.indexOf('}', end + 1)) {
    const candidate = source.slice(start, end + 1);
    try {
      new vm.Script('(' + candidate + ')');
      return candidate;
    } catch (error) {
      if (!(error instanceof SyntaxError)) throw error;
    }
  }
  throw new Error('cannot isolate original function: ' + name);
}

const originalFunctions = functionNames.map(extractFunction).join('\n');

function model() {
  const clock = { now: origin };
  const timers = new Map();
  const elements = [];
  const calls = [];
  let nextTimer = 1;
  let renders = 0;
  let documentScans = 0;
  class ClockDate extends Date {
    constructor(...args) { super(...(args.length ? args : [clock.now])); }
    static now() { return clock.now; }
  }
  const state = {
    date: '2026-09-06', me: { id: 7 },
    data: { personal: [], dispatch: [], done: [] },
    recentCreated: {}, recentPinned: {}, recentCreateFallback: null,
    firstOpenMarked: {}, saveTimers: {}, pendingHighlightScroll: '',
  };
  const animation = {
    apply(el, until, now) {
      calls.push({ kind: 'apply', el, until, now });
      el.classList.add('recentCreate');
      el.until = until;
    },
    clear(el) {
      calls.push({ kind: 'clear', el });
      el.classList.remove('recentCreate');
      delete el.until;
    },
    sweep() { calls.push({ kind: 'sweep' }); },
    readHTML(el) {
      calls.push({ kind: 'readHTML', el });
      return el?.restoredHTML || '';
    },
  };
  const context = vm.createContext({
    state, Date: ClockDate, console,
    DispatchRecentCreate: animation,
    sanitizeRichHtml(value) {
      calls.push({ kind: 'sanitizeRichHtml', value });
      return value;
    },
    CSS: { escape: String }, window: { CSS: { escape: String } },
    document: {
      querySelectorAll(selector) {
        documentScans++;
        if (selector === '[data-task-row],[data-mobile-task]') return [...elements];
        const pairs = [...selector.matchAll(/\[data-(task-row|mobile-task)="([^"]+)"\]/g)];
        assert.ok(pairs.length, 'unexpected page query in the isolated model: ' + selector);
        return elements.filter(el => pairs.some(match => el.kind === match[1] && el.key === match[2]));
      },
    },
    setTimeout(callback, delay = 0) {
      const id = nextTimer++;
      timers.set(id, { callback, at: clock.now + Number(delay) });
      return id;
    },
    clearTimeout(id) { timers.delete(id); },
    renderRowsOnly() {
      renders++;
      // The real renderer applies marks after replacing table/card elements.
      context.forceRecentCreateMarks();
    },
  });
  vm.runInContext(originalFunctions, context, { timeout: 1_000 });
  function advance(ms) {
    const target = clock.now + ms;
    assert.ok(ms >= 0, 'clock must be monotonic');
    let guard = 0;
    while (true) {
      const due = [...timers].filter(([, timer]) => timer.at <= target).sort((a, b) => a[1].at - b[1].at)[0];
      if (!due) break;
      assert.ok(++guard < 100, 'unexpected timer loop');
      timers.delete(due[0]);
      clock.now = due[1].at;
      due[1].callback();
    }
    clock.now = target;
  }
  function mount(row) {
    const key = context.highlightKey(row);
    const result = ['task-row', 'mobile-task'].map(kind => {
      const classes = new Set();
      return {
        key, kind,
        dataset: kind === 'task-row' ? { taskRow: key } : { mobileTask: key },
        classList: {
          add(value) { classes.add(value); },
          remove(value) { classes.delete(value); },
          contains(value) { return classes.has(value); },
        },
        get recent() { return classes.has('recentCreate'); },
      };
    });
    elements.push(...result);
    return result;
  }
  function replaceElements(row) {
    elements.length = 0;
    return mount(row);
  }
  return {
    context, state, clock, calls, elements, advance, mount, replaceElements,
    get renders() { return renders; }, get documentScans() { return documentScans; },
  };
}

function row(id, extra = {}) {
  return { id, title: 'Synthetic task ' + id, created_by: 7, task_date: '2026-09-06', status: 'active', ...extra };
}
function at(time) { return new Date(time).toISOString(); }
function inactive(m, task, message) {
  assert.ok(m.context.recentCreateUntil(task) <= m.clock.now, message + ': expired deadline');
  assert.notEqual(m.context.rowHighlightClass(task), 'recentCreate', message + ': no recent-create class');
}

const tests = [];
function test(name, run) { tests.push({ name, run }); }

test('five-second repaint keeps the original deadline for desktop and mobile', () => {
  const m = model();
  const task = row(11, { highlight_recent_create: 1 });
  m.state.data.personal = [task];
  m.context.markRecentCreated({ id: 11 }, task);
  assert.equal(m.state.recentCreated['11'], origin + duration);
  m.mount(task);
  m.context.forceRecentCreateMarks();
  assert.equal(m.calls.filter(call => call.kind === 'apply').length, 2);
  m.advance(5_000);
  const replacements = m.replaceElements(task);
  m.context.forceRecentCreateMarks();
  assert.ok(replacements.every(el => el.until === origin + duration));
  assert.equal(m.context.recentCreateUntil(task) - m.clock.now, 15_000);
  m.advance(51); // Also exercise the actual markRecentCreated pin-expiry timer.
  assert.equal(m.renders, 1);
  assert.equal(m.state.recentCreated['11'], origin + duration);
});

test('at 20 seconds stale flags cannot replay or reveal another highlight', () => {
  const m = model();
  const task = row(12, { highlight_recent_create: 1, just_created: 1 });
  m.state.data.dispatch = [task];
  m.state.firstOpenMarked['12'] = origin + 60_000;
  const elements = m.mount(task);
  m.context.markRecentCreated({ id: 12 }, task);
  m.context.forceRecentCreateMarks();
  m.advance(duration - 1);
  assert.equal(m.context.rowHighlightClass(task), 'recentCreate');
  m.advance(1);
  assert.equal(m.context.rowHighlightClass(task), '');
  m.context.forceRecentCreateMarks();
  assert.ok(elements.every(el => !el.recent && el.until === undefined), 'expired marks must be cleared, not reapplied');
  assert.ok(m.calls.some(call => call.kind === 'sweep'), 'page must invoke animation cleanup');
  m.advance(40_000);
  m.state.recentCreateFallback = null;
  inactive(m, { ...task }, 'stale fresh response after the fallback disappeared');
  assert.equal(m.state.recentCreated['12'], origin + duration, 'retain the expired page-lifetime tombstone');
});

test('several creations have separate expiry times', () => {
  const m = model();
  const first = row(21), second = row(22);
  m.context.markRecentCreated({ id: 21 }, first);
  m.advance(10_000);
  m.context.markRecentCreated({ id: 22 }, second);
  assert.equal(m.context.recentCreateUntil(first), origin + 20_000);
  assert.equal(m.context.recentCreateUntil(second), origin + 30_000);
  m.advance(10_000);
  inactive(m, first, 'first task expires independently');
  assert.equal(m.context.rowHighlightClass(second), 'recentCreate');
  m.advance(10_000);
  inactive(m, second, 'second task expires on its own deadline');
});

test('server creation time contributes only its remaining lifetime', () => {
  const m = model();
  const task = row(31, { created_at: at(origin - 7_000) });
  assert.equal(m.context.recentCreateUntil(task), origin + 13_000);
  m.advance(5_000);
  assert.equal(m.context.recentCreateUntil({ ...task }), origin + 13_000);
  m.advance(8_000);
  inactive(m, { ...task, highlight_recent_create: 1 }, 'server-timed task at exact expiry');
});

test('future server clock tolerance is bounded and never renewed', () => {
  const m = model();
  const future = row(41, { created_at: at(origin + 15_000) });
  assert.equal(m.context.recentCreateUntil(future), origin + duration, 'small server skew is capped at 20 seconds locally');
  m.advance(5_000);
  assert.equal(m.context.recentCreateUntil(future), origin + duration);
  m.advance(15_000);
  inactive(m, future, 'future timestamp cannot grant a second lifetime');
  inactive(m, row(42, { created_at: at(m.clock.now + 30_001), highlight_recent_create: 1 }), 'beyond tolerated future skew');
});

test('all done/cancelled aliases suppress the mark and clear old elements', () => {
  for (const status of ['done', 'complete', 'completed', 'cancelled', 'canceled']) {
    const m = model();
    const task = row(51);
    m.context.markRecentCreated({ id: 51 }, task);
    const elements = m.mount(task);
    m.state.data.dispatch = [task];
    m.context.forceRecentCreateMarks();
    task.status = status;
    assert.equal(m.context.rowHighlightClass(task), '', status);
    m.context.forceRecentCreateMarks();
    assert.ok(elements.every(el => !el.recent), status + ' must clear both layouts');
  }
});

test('group keys cannot overwrite or light up an unrelated task with the same numeric id', () => {
  const m = model();
  const group = row(61, { is_group: 1, group_id: 61, title: 'Synthetic group' });
  const ordinary = row(61, { title: 'Synthetic ordinary task' });
  m.context.markRecentCreated({ group_id: 61 }, group);
  assert.equal(m.context.highlightKey(group), 'g61');
  assert.equal(m.context.highlightKey(ordinary), '61');
  assert.equal(m.context.rowHighlightClass(group), 'recentCreate');
  inactive(m, ordinary, 'group creation must not mark the ordinary task');
  m.advance(5_000);
  m.context.markRecentCreated({ id: 61 }, ordinary);
  assert.equal(m.context.recentCreateUntil(group), origin + 20_000);
  assert.equal(m.context.recentCreateUntil(ordinary), origin + 25_000);
});

test('fallback keeps its fixed deadline and checks creator, date, and title', () => {
  const m = model();
  const task = row(71, { title: 'Fallback task' });
  m.context.markRecentCreated({}, task);
  m.advance(5_000);
  assert.equal(m.context.recentCreateUntil(task), origin + duration, 'late-discovered id uses remaining fallback time');
  for (const extra of [{ created_by: 8 }, { task_date: '2026-09-07' }, { title: 'Unrelated task' }]) {
    inactive(m, row(72, { ...task, id: 72, ...extra }), 'mismatched fallback identity');
  }
  m.advance(15_000);
  inactive(m, row(73, { ...task, id: 73 }), 'an expired fallback must not acquire a new task');
  inactive(m, { ...task, just_created: 1 }, 'resolved fallback id must not replay');
});

test('old timestamps, missing hints, and other-user timestamps do not initiate a mark', () => {
  const m = model();
  for (const age of [20_000, 60_000, 86_400_000]) {
    inactive(m, row(80 + age, { created_at: at(origin - age), highlight_recent_create: 1 }), 'valid old timestamp wins over a stale hint');
  }
  inactive(m, row(81, { created_at: at(origin - 1_000), created_by: 8 }), 'other-user timestamp without a hint');
  inactive(m, row(82), 'no timestamp and no hint');
});

test('a hint without a valid timestamp receives one bounded local lifetime', () => {
  const m = model();
  for (const [id, flag] of [[91, 'highlight_recent_create'], [92, 'just_created']]) {
    const task = row(id, { [flag]: 1, created_at: 'invalid-time', created_by: 8 });
    assert.equal(m.context.recentCreateUntil(task), origin + duration);
  }
  m.advance(duration);
  inactive(m, row(91, { highlight_recent_create: 1 }), 'persistent server hint');
  inactive(m, row(92, { just_created: 1 }), 'persistent just-created hint');
});

test('1000 rows need one document scan and touch only active or stale highlighted elements', () => {
  const m = model();
  const tasks = Array.from({ length: 1_000 }, (_, index) => row(1_000 + index));
  m.state.data.dispatch = tasks;
  tasks.forEach(task => m.mount(task));
  m.context.markRecentCreated({ id: tasks[1].id }, tasks[1]);
  m.context.markRecentCreated({ id: tasks[2].id }, tasks[2]);
  // A stale mark can still be mounted after its row no longer qualifies.
  const stale = m.elements.filter(el => el.key === String(tasks[3].id));
  stale.forEach(el => el.classList.add('recentCreate'));
  m.context.forceRecentCreateMarks();
  assert.equal(m.documentScans, 1, 'one shared DOM scan, not one selector per task');
  assert.equal(m.calls.filter(call => call.kind === 'apply').length, 4, 'only two active rows in two layouts');
  assert.equal(m.calls.filter(call => call.kind === 'clear').length, 2, 'only mounted stale highlights need clearing');
  assert.equal(m.calls.filter(call => call.kind === 'sweep').length, 1);
  assert.ok(stale.every(el => !el.recent));
  // This is an operation-count regression guard, not a browser timing claim.
});

test('rich-text serialization sanitizes restored HTML, never temporary highlight colors', () => {
  const m = model();
  const clean = '<span style="color: #2563eb">Synthetic user-colored text</span>';
  const fixture = Object.freeze({
    innerHTML: '<span style="color: rgb(255, 255, 255) !important; background-color: transparent !important">Synthetic user-colored text</span>',
    restoredHTML: ' \u200B' + clean + '\u200B ',
  });
  const el = new Proxy(fixture, {
    set() { throw new Error('serialization must not modify the live editor'); },
    defineProperty() { throw new Error('serialization must not redefine the live editor'); },
    deleteProperty() { throw new Error('serialization must not delete live editor properties'); },
  });
  assert.equal(m.context.editableRichHtml(el), clean, 'use restored content, with existing trim and zero-width cleanup');
  assert.deepEqual(m.calls.map(call => call.kind), ['readHTML', 'sanitizeRichHtml'], 'restore before sanitizing');
  assert.equal(m.calls[0].el, el, 'readHTML receives the live editor');
  assert.equal(m.calls[1].value, fixture.restoredHTML, 'sanitizer must receive restored HTML, not el.innerHTML');
  assert.equal(el.innerHTML, fixture.innerHTML, 'serialization leaves the visible animated editor untouched');

  for (const empty of [null, undefined]) {
    m.calls.length = 0;
    assert.equal(m.context.editableRichHtml(empty), '', 'missing editor is an empty value');
    assert.deepEqual(m.calls.map(call => call.kind), ['readHTML', 'sanitizeRichHtml']);
    assert.equal(m.calls[0].el, empty);
    assert.equal(m.calls[1].value, '');
  }
});

for (const { name, run } of tests) {
  run();
  console.log('PASS ' + name);
}
console.log('dispatch_recent_create_runtime_test: OK (' + tests.length + ' isolated model cases)');
