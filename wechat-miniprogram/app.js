App({
  globalData: {
    token: '',
    apiBase: 'https://novlight.com/miniapp/api/'
  },
  onLaunch() {
    try {
      this.globalData.token = wx.getStorageSync('token') || ''
    } catch (e) {}
  },
  ensureLogin() {
    var token = this.globalData.token || ''
    try {
      if (!token) token = wx.getStorageSync('token') || ''
    } catch (e) {}
    if (!token) {
      wx.reLaunch({ url: '/pages/login/login' })
      return false
    }
    this.globalData.token = token
    return true
  }
})
