function hasAmount(value) {
  if (value === '' || value === null || value === undefined) return false
  if (typeof value === 'number') return isFinite(value)
  if (typeof value === 'string' && value.trim() === '') return false
  var n = Number(value)
  return isFinite(n)
}

function formatAmount(value) {
  var n = Number(value)
  var neg = n < 0
  var abs = Math.abs(n)
  var rounded = Math.round(abs * 100) / 100
  var fixed = rounded.toFixed(2)
  var bits = fixed.split('.')
  var whole = bits[0].replace(/\B(?=(\d{3})+(?!\d))/g, ',')
  var text = whole
  if (bits[1] !== '00') text += '.' + bits[1].replace(/0$/, '')
  return (neg ? '-' : '') + text
}

function formatMoney(value, currency) {
  if (!hasAmount(value)) return ''
  var text = formatAmount(value)
  var cur = String(currency || 'RMB').trim().toUpperCase()
  if (cur === '' || cur === 'RMB' || cur === 'CNY' || cur === '¥' || cur === '￥') return '¥' + text
  return cur + ' ' + text
}

function summaryTotal(summary) {
  if (!summary || typeof summary !== 'object' || Array.isArray(summary)) return null
  if (!Object.prototype.hasOwnProperty.call(summary, 'total')) return null
  return summary.total
}

module.exports = {
  hasAmount: hasAmount,
  formatAmount: formatAmount,
  formatMoney: formatMoney,
  summaryTotal: summaryTotal
}
