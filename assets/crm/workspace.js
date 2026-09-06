(function (global) {
  'use strict';
  function pager(box, data, onPage) {
    if (!box) return;
    var existing = box.querySelector('[data-workspace-pager]');
    if (existing) existing.remove();
    var page = Math.max(1, Number(data.page) || 1);
    var nav = document.createElement('nav');
    nav.className = 'crm-workspace-pager';
    nav.dataset.workspacePager = '1';
    nav.setAttribute('aria-label', '列表分页');
    function button(label, disabled, next) {
      var el = document.createElement('button');
      el.type = 'button'; el.textContent = label; el.disabled = disabled;
      el.addEventListener('click', function (event) {
        event.stopPropagation();
        nav.querySelectorAll('button').forEach(function (item) { item.disabled = true; });
        Promise.resolve().then(function () { return onPage(next); }).finally(function () {
          var buttons = nav.querySelectorAll('button');
          buttons[0].disabled = page <= 1;
          buttons[1].disabled = !data.has_more;
        }).catch(function () { /* The owning list renders its own request error. */ });
      });
      return el;
    }
    nav.appendChild(button('上一页', page <= 1, page - 1));
    var label = document.createElement('span');
    label.textContent = '第 ' + page + ' 页 · 本页 ' + ((data.rows || []).length) + ' 条';
    label.setAttribute('aria-live', 'polite');
    nav.appendChild(label);
    nav.appendChild(button('下一页', !data.has_more, page + 1));
    box.appendChild(nav);
  }
  // Only confirmed placeholder routes belong here, not low-frequency features.
  var pending = {
    mail: ['创建商机','创建报价需求','创建资料任务','查看发送状态','添加附件'],
    customers: ['推进阶段','关闭商机','发送报价','同步订单','生成单证','标记已收定金','创建收款提醒','登记收款','导出欠款','新建出货','更新物流','查看签收记录','查看单证','重新生成','查看样品','更新快递','上传资料','生成资料包','下载资料','发送资料给客户','查看 PLM','创建 PLM 项目','同步 PLM','查看 BOM','创建 BOM','同步 BOM','创建派工','查看派工','查看完成记录','新增关系','删除关系','查看关系图谱','导出日志','执行查重','忽略重复','查看重复详情','上传文件','删除文件','关联资料','导入联系人','设置不推广','加入黑名单','创建人工执行清单','创建提醒'],
    opportunities: ['创建派工','导入商机','导出商机'],
    visits: ['创建报价','生成资料','导出记录'],
    promotion: ['导出人工执行清单','项目筛选设置','立即发送','取消发送','创建跟进','归档项目','批量归档','批量改负责人','批量加标签','批量取消标签','批量导出','导出客户组','批量导入成员','导出本组客户','查看关联推广项目','批量导入客户组','改执行人','通知负责人']
  };
  var expanded = new Set();
  function actionAvailable(module, label) { return (pending[module] || []).indexOf(label) < 0; }
  function actionGroup(section, module, title, buttons) {
    var key = module + ':' + title;
    var primary = buttons.filter(function (button) { return !button.classList.contains('danger'); }).slice(0, 3);
    primary.forEach(function (button) { section.appendChild(button); });
    var rest = buttons.filter(function (button) { return primary.indexOf(button) < 0; });
    if (!rest.length) return;
    var details = document.createElement('details');
    details.className = 'crm-workspace-more';
    details.open = expanded.has(key);
    var summary = document.createElement('summary');
    summary.textContent = '更多操作（' + rest.length + '）';
    summary.addEventListener('click', function () { if (details.open) expanded.delete(key); else expanded.add(key); });
    details.appendChild(summary);
    rest.forEach(function (button) { details.appendChild(button); });
    details.addEventListener('toggle', function () { if (!details.isConnected) return; if (details.open) expanded.add(key); else expanded.delete(key); });
    section.appendChild(details);
  }
  global.CRMWorkspace = Object.freeze({ pager: pager, actionAvailable: actionAvailable, actionGroup: actionGroup });
})(window);
