/* Quotation-only display text. Never writes BOM components or material records. */
window.QuoteDisplayText=(()=>{
  const field='quote_spec_display_override';
  let context=null,modal=null,returnFocus=null;
  const locked=()=>!hasPerm('quote_edit')||S.currentApprovalStatus==='approved'||(S.currentQuoteId>0&&!!S.bomSentLocked);
  const same=()=>context&&S.product===context.product&&S.currentQuoteId===context.quoteId&&S.editingIndex===context.index;
  function close(){modal?.remove();modal=null;context=null;returnFocus?.focus?.();}
  function open(){
    if(!S.product)return alert('请先选择产品');
    if(S.product._bomLoading||S.product._bomBlocked)return alert('请先完成产品版本读取或选择');
    close();returnFocus=document.activeElement;
    context={product:S.product,quoteId:S.currentQuoteId,index:S.editingIndex};
    const item=currentEditorItem(),manual=typeof S.product[field]==='string';
    document.body.insertAdjacentHTML('beforeend',`<div id="quoteDisplayText" class="qdt-overlay" role="dialog" aria-modal="true" aria-labelledby="qdtTitle"><section class="qdt-dialog"><header><h3 id="qdtTitle">修改报价文字</h3><button type="button" data-qdt-close aria-label="关闭">关闭</button></header><p>${esc(S.product.code||S.product.name||'当前产品')} · 仅用于本报价的当前产品行</p><p class="qdt-help">可修改、翻译或删除下方文字，清空可隐藏整段。应用后请保存整张报价单。手动文字会固定保留；调整产品参数后，可恢复自动生成再编辑。</p>${locked()?'<p role="status">已审核或已发送报价请另存新版本后编辑；编辑需要报价修改权限。</p>':''}<label for="qdtText">Specification / 报价展示文字${manual?'（已手动修改）':''}</label><textarea id="qdtText" maxlength="20000" ${locked()?'readonly':''}></textarea><footer><button type="button" data-qdt-restore ${locked()?'disabled':''}>恢复自动生成</button><button type="button" data-qdt-close>取消</button><button type="button" class="blue" data-qdt-apply ${locked()?'disabled':''}>应用到当前产品</button></footer></section></div>`);
    modal=$('quoteDisplayText');$('qdtText').value=buildSpec(item);
    modal.addEventListener('click',e=>{const b=e.target.closest('button');if(!b)return;if(b.hasAttribute('data-qdt-close'))close();else if(b.hasAttribute('data-qdt-apply'))apply(false);else if(b.hasAttribute('data-qdt-restore'))apply(true);});
    modal.addEventListener('keydown',e=>{
      if(e.key==='Escape'){e.preventDefault();close();return;}
      if(e.key==='Tab'){const nodes=[...modal.querySelectorAll('button:not(:disabled),textarea')],first=nodes[0],last=nodes.at(-1);if(e.shiftKey&&document.activeElement===first){e.preventDefault();last.focus();}else if(!e.shiftKey&&document.activeElement===last){e.preventDefault();first.focus();}}
    });
    $('qdtText').focus();
  }
  function apply(restore){
    if(!same()){close();return alert('当前产品已变化，请重新打开文字编辑。');}
    if(locked()||S.product._bomLoading||S.product._bomBlocked)return;
    const text=$('qdtText').value.replace(/\r\n?/g,'\n').trim();
    if(text.length>20000)return alert('报价文字最多 20000 个字符');
    if(restore)delete S.product[field];else S.product[field]=text;
    // Change only the quoted display field, preserving the row's prices and source snapshot.
    const item=S.items?.[S.editingIndex];
    if(item){
      if(restore)delete item.product[field];else item.product[field]=text;
      item.specification=buildSpec(item);
    }else autoAddSelectedProductLine();
    close();updateProductLinkHint();renderQuoteItems();render();
  }
  return {open,close};
})();
