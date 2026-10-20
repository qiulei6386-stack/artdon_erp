/* Synthetic text only; no sessions, network, database, or production fixtures. */
const fs = require('node:fs'), path = require('node:path'), vm = require('node:vm'), assert = require('node:assert/strict')
const root = path.resolve(__dirname, '..')
const quoteOrderSpec = require(path.join(root, 'assets/quote-spec-order.js'))
assert.equal(quoteOrderSpec.quoteOrderSpec, quoteOrderSpec, 'CommonJS supports named and direct use')
const browser = {}
vm.createContext(browser)
vm.runInContext(fs.readFileSync(path.join(root, 'assets/quote-spec-order.js'), 'utf8'), browser)
assert.equal(typeof browser.quoteOrderSpec, 'function', 'browser global is available')
const fixtures = JSON.parse(fs.readFileSync(path.join(__dirname, 'fixtures/quote-spec-order.json'), 'utf8'))
const freeze = (value) => { if (value && typeof value === 'object') { Object.freeze(value); Object.values(value).forEach(freeze) } return value }
let checks = 0
for (const fixture of fixtures) {
  const item = freeze(fixture.item), before = JSON.stringify(item)
  const actual = quoteOrderSpec(fixture.text, item)
  assert.equal(actual, fixture.expected, fixture.name)
  assert.equal(browser.quoteOrderSpec(fixture.text, item), fixture.expected, fixture.name + ': browser parity')
  assert.equal(quoteOrderSpec(actual, item), actual, fixture.name + ': idempotence')
  assert.equal(JSON.stringify(item), before, fixture.name + ': saved item is unchanged')
  checks += 4
}
// Random insertion order must not affect the visible attribute order or values.
const fields = ['Power: 1.35W', 'Size: 52.05522×120', 'Cut out: Φ55', 'Beam Angle: 24°', 'Optic: OPAL', 'LED: BXRE-40G20FD-C-8X 3000K CRI90', 'Adapter: 48V', 'LED Driver: LF-GDE020', 'Accessories: Clip', 'CCT: 3000K', 'CRI90', 'IP20']
const numbered = (rows) => rows.map((row, index) => `${index + 1}. ${row}`).join('\n')
const expected = numbered(['SPECTRUM E'].concat(fields))
let seed = 20261020
for (let n = 0; n < 128; n++) {
  const shuffled = fields.slice()
  for (let i = shuffled.length - 1; i > 0; i--) {
    seed = (Math.imul(seed, 1664525) + 1013904223) >>> 0
    const j = seed % (i + 1)
    ;[shuffled[i], shuffled[j]] = [shuffled[j], shuffled[i]]
  }
  const actual = quoteOrderSpec(numbered(['SPECTRUM E'].concat(shuffled)))
  assert.equal(actual, expected, 'random insertion order ' + n)
  assert.equal(quoteOrderSpec(actual), actual, 'random insertion idempotence ' + n)
  checks += 2
}
assert.equal(quoteOrderSpec(null), '')
console.log(`Quote specification ordering: ${fixtures.length} shared fixtures, 128 insertion permutations, ${checks + 1} checks passed.`)
