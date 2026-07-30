const assert = require('assert');
const fs = require('fs');
const path = require('path');
const vm = require('vm');

const source = fs.readFileSync(path.join(__dirname, '..', 'quotation.php'), 'utf8');
const start = source.indexOf('function quotePartStopWords');
const end = source.indexOf('function quoteBrandModelFromProduct');
assert(start >= 0 && end > start, '找不到报价物料名称处理函数');

const context = {
  cleanParam(value) {
    return String(value ?? '').trim();
  },
};
vm.createContext(context);
vm.runInContext(source.slice(start, end), context);

assert.strictEqual(
  context.quoteBrandModelOnly({ brand: '', name: '2 Wire I-connector', model: '2 Wire I-connector' }),
  '2 Wire I-connector'
);
assert.strictEqual(
  context.quoteBrandModelOnly({ brand: '2', model: '2 Wire I-connector' }),
  '2 Wire I-connector'
);
assert.strictEqual(context.quoteBrandModelOnly('2 Wire I-connector'), '2 Wire I-connector');
assert.strictEqual(context.quoteBrandModelOnly({ brand: '3M', model: 'X100' }), '3M X100');
assert.strictEqual(
  context.quoteBrandModelOnly({ brand: 'LIFUD', model: 'LIFUD LF-GIR020YS' }),
  'LIFUD LF-GIR020YS'
);
assert.strictEqual(
  context.quoteUniqueDisplayParts(['2 Wire I-connector', '2 Wire I-connector']),
  '2 Wire I-connector'
);

console.log('quotation material name dedup test passed');
