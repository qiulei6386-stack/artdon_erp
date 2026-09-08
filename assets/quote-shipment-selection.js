/* Shipment scope is explicit; unchecked orders keep local edits, but are never submitted. */
(function () {
  'use strict';
  var selected = null;
  var renderRows = renderShipmentRows, collectItems = collectShipmentItems;
  function refreshSelection() {
    document.querySelectorAll('#shipItemRows tr[data-order-item]').forEach(function (row) {
      var included = !selected || selected.has(Number(row.dataset.orderId));
      row.hidden = !included;
      row.querySelectorAll('input,select,textarea,button').forEach(function (el) {el.disabled = !included;});
    });
    var count = document.querySelector('[data-shipment-selected-count]');
    if (count) count.textContent = '已选 ' + selected.size + ' 张订单；数量为 0 的产品不出货。';
    recalcShipmentTotals();
  }
  function installPicker() {
    document.getElementById('shipmentOrderPicker')?.remove();
    var orders = SHIPMENT_PREP && SHIPMENT_PREP.orders || [];
    selected = orders.length > 1 ? new Set((Number(SHIPMENT_EDIT_ID) > 0 ? orders.map(function (o) {return Number(o.id);}) : [Number(SHIPMENT_PREP.order.id)])) : null;
    var rows = document.getElementById('shipItemRows');if (!rows) return;
    if (selected) {
      var panel = document.createElement('fieldset');panel.id = 'shipmentOrderPicker';
      panel.innerHTML = '<legend>选择本次出货订单</legend><p data-shipment-selected-count role="status"></p><div class="shipment-order-options">'+orders.map(function (o) {
        return '<label><input type="checkbox" value="'+Number(o.id)+'" '+(selected.has(Number(o.id))?'checked':'')+'><span>'+esc(quoteOrderNoAtV68522(o.order_no,o.quote_no) || ('订单 #'+o.id))+'</span></label>';
      }).join('')+'</div><p>取消勾选不会丢失本次填写内容；未选订单不会进入本批次或 PL / CI。</p>';
      rows.closest('table').parentElement.before(panel);
      panel.addEventListener('change',function (e) {if(e.target.type!=='checkbox')return;var id=Number(e.target.value);if(e.target.checked)selected.add(id);else selected.delete(id);refreshSelection();});
    }
    refreshSelection();
  }
  renderShipmentRows = function (items) {renderRows(items);installPicker();};
  collectShipmentItems = function () {return collectItems().filter(function (item) {return !selected || selected.has(Number(item.order_id));});};
  var edit = editShipment;
  editShipment = async function (id) {await edit(id);if(Number(SHIPMENT_EDIT_ID)===Number(id))installPicker();};
  var open = openShipmentModal, combined = openCombinedShipmentModal;
  openShipmentModal = async function (id) {selected=null;await open(id);};
  openCombinedShipmentModal = async function (id) {selected=null;await combined(id);};
  // Carton contents are already included in product quantities. Packaging
  // values on product rows describe normal cartons only, excluding mixed ones.
  var addCarton = addCartonRow;
  addCartonRow = function (data) {
    addCarton(data);
    var box=document.getElementById('cartonRows');
    if(box && !box.querySelector('.shipment-packing-note')) {
      var note=document.createElement('p');note.className='shipment-packing-note';
      note.textContent='拼箱说明：产品行填写本次实际总数量（含拼箱），拼箱这里只登记箱内明细，不再增加出货数量。产品行的箱数、重量、体积只填常规箱；全部拼箱时这些常规箱值应为 0。';box.prepend(note);
    }
  };
}());
