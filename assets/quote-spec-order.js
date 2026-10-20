/* Pure quotation display ordering. No source items or saved snapshots are changed. */
(function (root, factory) {
  const order = factory()
  if (typeof module === 'object' && module.exports) {
    module.exports = order
    module.exports.quoteOrderSpec = order
  } else root.quoteOrderSpec = order
})(typeof globalThis !== 'undefined' ? globalThis : this, function () {
  'use strict'
  const FREE = 130
  const ranks = {
    series: 0, seriesname: 0, productname: 0, product: 0, '系列': 0, '系列名称': 0, '产品名称': 0, '品名': 0,
    power: 10, ratedpower: 10, wattage: 10, '功率': 10, '额定功率': 10,
    size: 20, dimension: 20, dimensions: 20, overallsize: 20, '尺寸': 20, '外形尺寸': 20, '灯具尺寸': 20,
    cutout: 30, cutoutsize: 30, '开孔': 30, '开孔尺寸': 30,
    beamangle: 40, beam: 40, '角度': 40, '光束角': 40, '光束角度': 40,
    optic: 50, optics: 50, lens: 50, reflector: 50, '光学': 50, '透镜': 50, '反光杯': 50,
    led: 60, cob: 60, ledchip: 60, ledchips: 60, chip: 60, '芯片': 60, '灯珠': 60, '光源': 60,
    adapter: 70, connector: 70, '接头': 70, '连接头': 70, '连接器': 70, '适配器': 70,
    leddriver: 80, driver: 80, powersupply: 80, '电源': 80, '驱动': 80, '驱动电源': 80,
    accessories: 90, accessory: 90, extra: 90, '附件': 90, '配件': 90, '客户可选附件': 90,
    cct: 100, colourtemperature: 100, colortemperature: 100, '色温': 100,
    cri: 110, ra: 110, '显指': 110, '显色指数': 110,
    ip: 120, iprating: 120, ingressprotection: 120, '防护等级': 120, '防水等级': 120
  }
  const trim = (value) => value.replace(/^[\s\u3000]+|[\s\u3000]+$/g, '')
  const flag = (value) => value !== undefined && value !== null && value !== false && value !== 0 && value !== '' && value !== '0' && (typeof value !== 'object' || Object.keys(value).length > 0)
  function excluded(item) {
    const p = item.product && typeof item.product === 'object' ? item.product : {}
    return flag(item.is_order_snapshot) || flag(item.is_material_sale) || flag(item.is_virtual_item) ||
      item.item_type === 'virtual' || item.product_type === 'virtual' || item.product_type === 'material' ||
      flag(item.virtual_type) || String(p.id || '').indexOf('mat-') === 0 ||
      [false, 0, '0', 'false'].indexOf(item.shippable) !== -1
  }
  function rank(line) {
    const text = trim(line)
    const label = text.match(/^([^:：]+)[:：]/)
    if (label) {
      const key = trim(label[1]).toLowerCase().replace(/[ \t\u3000_-]+/g, '')
      return Object.prototype.hasOwnProperty.call(ranks, key) ? ranks[key] : FREE
    }
    if (/^IP[ \t]*[0-9][A-Z0-9]*[ \t]*$/i.test(text)) return 120
    if (/^(?:CRI|Ra)[ \t]*[0-9]{1,3}[ \t]*$/i.test(text)) return 110
    if (/^(?:CCT[ \t]*)?[0-9]{3,5}[ \t]*K[ \t]*$/i.test(text)) return 100
    return FREE
  }
  function remarks(item, source) {
    let out = Array.isArray(item.quote_remarks) ? item.quote_remarks.filter((x) => typeof x === 'string').map((x) => trim(x.replace(/\r\n?/g, '\n'))).filter(Boolean) : []
    if (!out.length && typeof item.extra_spec === 'string' && trim(item.extra_spec) !== trim(source)) {
      out = item.extra_spec.replace(/\r\n?/g, '\n').split(/\n+/).map(trim).filter(Boolean)
    }
    return out
  }
  return function quoteOrderSpec(text, item) {
    const source = text == null ? '' : String(text)
    item = item && typeof item === 'object' ? item : {}
    if (!source || excluded(item)) return source
    const endings = source.match(/\r\n|\r|\n/g) || []
    // Mixed line endings are left alone rather than changing arbitrary prose.
    if (endings.some((x) => x !== endings[0])) return source
    const newline = endings[0] || '\n'
    const lines = source.split(newline)
    let first = 0, last = lines.length - 1
    while (first <= last && !trim(lines[first])) first++
    while (last >= first && !trim(lines[last])) last--
    const blocks = []
    for (let i = first; i <= last; i++) {
      const match = lines[i].match(/^([ \t\u3000]*)([0-9]+)\.([ \t\u3000]+)(.*)$/)
      if (match) {
        if (match[2] !== String(blocks.length + 1) || !trim(match[4])) return source
        blocks.push({ index: blocks.length, indent: match[1], space: match[3], lines: [match[4]], rank: rank(match[4]), remark: false })
      } else {
        if (!blocks.length || /^[ \t\u3000]*[0-9]+\.[^0-9\s\u3000]/.test(lines[i])) return source
        blocks[blocks.length - 1].lines.push(lines[i])
      }
    }
    if (blocks.length < 2) return source
    const tail = remarks(item, source)
    let r = tail.length - 1
    for (let b = blocks.length - 1; b >= 0 && r >= 0; b--, r--) {
      if (trim(blocks[b].lines.join('\n')) !== tail[r]) break
      blocks[b].remark = true
      blocks[b].rank = FREE
    }
    if (!blocks[0].remark && blocks[0].rank === FREE && !/[:：]/.test(blocks[0].lines[0])) blocks[0].rank = 0
    const ordered = blocks.slice().sort((a, b) => a.rank - b.rank || a.index - b.index)
    if (ordered.every((block, index) => block.index === index)) return source
    const body = ordered.map((block, index) => block.indent + (index + 1) + '.' + block.space + block.lines.join(newline))
    return lines.slice(0, first).concat(body, lines.slice(last + 1)).join(newline)
  }
})
