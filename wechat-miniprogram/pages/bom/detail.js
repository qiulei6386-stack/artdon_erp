var ui = require('../../utils/i18n.js').zh
var api = require('../../utils/api.js')
var money = require('../../utils/money.js')

Page({
  data: {
    ui: ui,
    id: '',
    project: null,
    rows: [],
    totals: [],
    profitText: '',
    profitOpen: false,
    noteOpen: false,
    can: { view: false, cost_view: false, supplier_view: false },
    forbidden: false,
    needLogin: false,
    error: ''
  },
  onLoad(options) {
    var id = ''
    try {
      id = decodeURIComponent((options && options.id) || '')
    } catch (e) {
      id = (options && options.id) || ''
    }
    this.setData({ id: id })
    var app = getApp()
    if (!app.ensureLogin()) {
      this.setData({ needLogin: true })
      return
    }
    if (!id) {
      wx.showToast({ title: ui.not_found, icon: 'none' })
      setTimeout(function () { wx.navigateBack({ delta: 1 }) }, 600)
      return
    }
    this.load()
  },
  onShow() {
    if (this.data.id) api.logPage('bom_detail', { target_name: this.data.id })
  },
  toggleNote() {
    this.setData({ noteOpen: !this.data.noteOpen })
  },
  toggleProfit() {
    this.setData({ profitOpen: !this.data.profitOpen })
  },
  retry() {
    this.load()
  },
  load() {
    var self = this
    this.setData({ error: '' })
    return api.getBomDetail(this.data.id, { toast: false }).then(function (data) {
      var can = (data && data.can) || { view: true, cost_view: false, supplier_view: false }
      var project = (data && data.project) || null
      if (!can.cost_view && project) project.totals_summary = {}
      self.setData({
        can: can,
        forbidden: false,
        project: project,
        rows: project ? mapRows(project, can) : [],
        totals: project && can.cost_view ? mapTotals(project, ui) : [],
        profitText: project && can.cost_view ? money.formatMoney(project.totals_summary && project.totals_summary.profit, project.currency) : '',
        error: ''
      })
    }, function (err) {
      if (err && err.code === 'BOM_FORBIDDEN') {
        self.setData({ forbidden: true, project: null, rows: [], totals: [], error: '' })
        return
      }
      if (err && err.code === 'NEED_LOGIN') {
        self.setData({ needLogin: true })
        return
      }
      if (err && err.code === 'BOM_NOT_FOUND') {
        wx.showToast({ title: ui.not_found, icon: 'none' })
        setTimeout(function () { wx.navigateBack({ delta: 1 }) }, 600)
        return
      }
      self.setData({ error: (err && err.message) || '网络请求失败' })
      wx.showToast({ title: (err && err.message) || '网络请求失败', icon: 'none' })
    })
  }
})

function mapRows(project, can) {
  var currency = project.currency
  var rows = project.rows || []
  return rows.map(function (row, index) {
    var costText = ''
    if (can && can.cost_view) {
      var parts = []
      var price = money.formatMoney(row.price, currency)
      var process = money.formatMoney(row.process, currency)
      var surface = surfaceText(row, currency)
      var subtotal = money.formatMoney(row.subtotal, currency)
      if (price) parts.push(ui.price_label + ' ' + price)
      if (process) parts.push(ui.process_label + ' ' + process)
      if (surface) parts.push(ui.surface_label + ' ' + surface)
      if (subtotal) parts.push(ui.subtotal_label + ' ' + subtotal)
      costText = parts.join(' · ')
    }
    var qty = row.qty
    return {
      no: index + 1,
      category: row.category || '',
      name: row.name || '',
      spec: row.spec || '',
      qtyText: money.hasAmount(qty) ? String(Number(qty)) : '',
      costText: costText
    }
  })
}

function surfaceText(row, currency) {
  var has1 = money.hasAmount(row.finishCost)
  var has2 = money.hasAmount(row.finishCost2)
  if (!has1 && !has2) return ''
  var sum = (has1 ? Number(row.finishCost) : 0) + (has2 ? Number(row.finishCost2) : 0)
  return money.formatMoney(sum, currency)
}

function mapTotals(project, labels) {
  var summary = project.totals_summary || {}
  var currency = project.currency
  var spec = [
    ['material', labels.material_label, false],
    ['labor', labels.labor_label, false],
    ['other', labels.other_label, false],
    ['total', labels.total_label, true],
    ['suggest', labels.suggest_label, false]
  ]
  var out = []
  spec.forEach(function (item) {
    if (!Object.prototype.hasOwnProperty.call(summary, item[0])) return
    var text = money.formatMoney(summary[item[0]], currency)
    if (!text) return
    out.push({ key: item[0], label: item[1], text: text, strong: item[2] })
  })
  return out
}
