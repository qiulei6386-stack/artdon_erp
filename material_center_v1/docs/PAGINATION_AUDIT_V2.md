# Pagination Audit V2

旧分页固定条数且视觉混搭。V2 使用唯一分页组件：

- 默认“自动”，另有10/20/30/50/100。
- 自动值按表格正文高度除以当前行高计算，限制8–100。
- 监听 ResizeObserver 和 window resize，经 debounce + requestAnimationFrame 重算。
- 页码采用紧凑白底按钮、当前页青色、长页码省略、跳页和上下页。
- 翻页保持排序和列视图并滚动回表格顶部。
