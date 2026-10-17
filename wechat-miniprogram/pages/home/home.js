var ui = require('../../utils/i18n.js').zh
var ux = require('../../utils/ux.js')

Page({
  data: { ui: ui },
  goBom() {
    ux.haptic('light')
    wx.navigateTo({ url: '/pages/bom/list' })
  }
})
