var ui = require('../../utils/i18n.js').zh
var api = require('../../utils/api.js')
var money = require('../../utils/money.js')

function stages() {
  return [
    { key: '', label: ui.stage_all },
    { key: 'draft', label: ui.stage_draft },
    { key: 'preliminary', label: ui.stage_preliminary },
    { key: 'approved', label: ui.stage_approved }
  ]
}

function ranges() {
  return [
    { key: '', label: ui.time_all },
    { key: '7d', label: ui.time_7d },
    { key: 'month', label: ui.time_month }
  ]
}

Page({
  data: {
    ui: ui,
    stages: stages(),
    ranges: ranges(),
    keyword: '',
    stage: '',
    range: '',
    list: [],
    page: 1,
    hasMore: false,
    can: { view: false, cost_view: false, supplier_view: false },
    forbidden: false,
    needLogin: false,
    loaded: false,
    loading: false,
    error: ''
  },
  onLoad() {
    var app = getApp()
    if (!app.ensureLogin()) {
      this.setData({ needLogin: true })
      return
    }
    this.reload()
  },
  onShow() {
    api.logPage('bom_list')
  },
  onPullDownRefresh() {
    var self = this
    this.reload().then(function () {
      wx.stopPullDownRefresh()
    }, function () {
      wx.stopPullDownRefresh()
    })
  },
  onReachBottom() {
    if (this.data.loading || !this.data.hasMore || this.data.forbidden) return
    this.load(this.data.page + 1, true)
  },
  onSearch(e) {
    var value = (e.detail && e.detail.value) || ''
    this.setData({ keyword: value })
    if (this._timer) clearTimeout(this._timer)
    var self = this
    this._timer = setTimeout(function () {
      self.reload()
    }, 300)
  },
  setStage(e) {
    var key = e.currentTarget.dataset.key
    if (key === undefined) key = ''
    this.setData({ stage: key })
    this.reload()
  },
  setRange(e) {
    var key = e.currentTarget.dataset.key
    if (key === undefined) key = ''
    this.setData({ range: key })
    this.reload()
  },
  retry() {
    this.reload()
  },
  openDetail(e) {
    var id = e.currentTarget.dataset.id
    if (!id) return
    wx.navigateTo({ url: '/pages/bom/detail?id=' + encodeURIComponent(id) })
  },
  reload() {
    return this.load(1, false)
  },
  load(page, append) {
    var self = this
    var seq = (this._seq || 0) + 1
    this._seq = seq
    this.setData({ loading: true, error: '' })
    return api.getBomList({
      keyword: this.data.keyword,
      review_status: this.data.stage,
      date_range: this.data.range,
      page: page,
      page_size: 20
    }, { toast: false }).then(function (data) {
      if (seq !== self._seq) return
      var can = (data && data.can) || { view: true, cost_view: false, supplier_view: false }
      var rows = (data && data.list) || []
      var mapped = rows.map(function (item) {
        return decorate(item, can)
      })
      self.setData({
        can: can,
        forbidden: false,
        list: append ? self.data.list.concat(mapped) : mapped,
        page: data.page || page,
        hasMore: !!(data && data.has_more),
        loaded: true,
        loading: false,
        error: ''
      })
    }, function (err) {
      if (seq !== self._seq) return
      if (err && err.code === 'BOM_FORBIDDEN') {
        self.setData({ forbidden: true, loaded: true, loading: false, list: [], error: '' })
        return
      }
      if (err && err.code === 'NEED_LOGIN') {
        self.setData({ needLogin: true, loading: false })
        return
      }
      var message = (err && err.message) || '网络请求失败'
      if (!self.data.list.length) wx.showToast({ title: message, icon: 'none' })
      else wx.showToast({ title: message, icon: 'none' })
      self.setData({ loading: false, loaded: true, error: message })
    })
  }
})

function decorate(item, can) {
  var next = Object.assign({}, item)
  next.totalText = ''
  if (can && can.cost_view) {
    var total = money.summaryTotal(item.totals_summary)
    next.totalText = money.formatMoney(total, item.currency)
  } else {
    next.totals_summary = {}
  }
  return next
}
