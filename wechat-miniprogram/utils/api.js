var inflight = {}

function apiBase() {
  var app = getApp()
  var base = app && app.globalData && app.globalData.apiBase
  return base || 'https://novlight.com/miniapp/api/'
}

function token() {
  var app = getApp()
  var saved = app && app.globalData && app.globalData.token
  if (saved) return saved
  try {
    return wx.getStorageSync('token') || ''
  } catch (e) {
    return ''
  }
}

function request(action, opts) {
  opts = opts || {}
  var data = Object.assign({}, opts.data || {})
  var key = opts.dedupeKey || ''
  if (key && inflight[key]) return inflight[key]
  var task = new Promise(function (resolve, reject) {
    wx.request({
      url: apiBase() + '?action=' + encodeURIComponent(action),
      method: opts.method || 'GET',
      data: data,
      header: { 'X-Token': token() },
      success: function (res) {
        var body = res.data || {}
        if (body.success === false) {
          var err = new Error(body.message || '请求失败')
          err.code = body.data && body.data.code
          err.data = body.data || null
          if (err.code === 'NEED_LOGIN') {
            try {
              var app = getApp()
              if (app && app.globalData) app.globalData.token = ''
              wx.removeStorageSync('token')
              wx.reLaunch({ url: '/pages/login/login' })
            } catch (e) {}
          } else if (opts.toast !== false) {
            wx.showToast({ title: body.message || '请求失败', icon: 'none' })
          }
          reject(err)
          return
        }
        resolve(body.data)
      },
      fail: function () {
        if (opts.toast !== false) wx.showToast({ title: '网络请求失败', icon: 'none' })
        reject(new Error('网络请求失败'))
      },
      complete: function () {
        if (key) delete inflight[key]
      }
    })
  })
  if (key) inflight[key] = task
  return task
}

function getBomMeta(opts) {
  return request('bom_meta', Object.assign({ dedupeKey: 'bom_meta' }, opts || {}))
}

function getBomList(params, opts) {
  params = params || {}
  return request('bom_list', Object.assign({ data: params, dedupeKey: 'bom_list:' + JSON.stringify(params) }, opts || {}))
}

function getBomDetail(id, opts) {
  return request('bom_detail', Object.assign({ data: { id: id }, dedupeKey: 'bom_detail:' + id }, opts || {}))
}

function logPage(page, extra) {
  return request('audit_page', {
    data: Object.assign({ page: page }, extra || {}),
    toast: false,
    dedupeKey: 'audit:' + page + ':' + ((extra && extra.target_name) || '')
  }).catch(function () {})
}

module.exports = {
  request: request,
  getBomMeta: getBomMeta,
  getBomList: getBomList,
  getBomDetail: getBomDetail,
  logPage: logPage
}
