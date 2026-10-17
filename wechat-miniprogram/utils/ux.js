function haptic(type) {
  try {
    if (typeof wx !== 'undefined' && wx.vibrateShort) wx.vibrateShort({ type: type || 'light' })
  } catch (e) {}
}

module.exports = { haptic: haptic }
