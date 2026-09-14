(function () {
  'use strict';
  const escape = value => String(value ?? '').replace(/[&<>"']/g, x => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[x]));
  const requestToken = () => Array.from(crypto.getRandomValues(new Uint8Array(24)), x => x.toString(16).padStart(2,'0')).join('');
  async function api(action, data) {
    const query = new URLSearchParams({action});
    const write = action === 'create';
    if (!write) Object.entries(data || {}).forEach(([k,v]) => query.set(k,v));
    const response = await fetch('quote_mail_api.php?' + query, write ? {method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','X-CSRF-Token':document.querySelector('meta[name="shipment-csrf"]')?.content || ''},body:JSON.stringify(data)} : {credentials:'same-origin'});
    let json;try {json=await response.json();} catch(e){throw new Error('附件服务返回异常，请确认登录后重试。');}
    if (!response.ok || !json.success) throw new Error(json.message || '报价附件准备失败');
    return json.data;
  }
  let active;
  async function open(id) {
    if (active) {active.focus();return;}
    const dialog=document.createElement('dialog');active=dialog;dialog.className='quote-mail-dialog';
    dialog.innerHTML='<header><div><h2>发送报价邮件</h2><small>仅生成草稿，不会自动发送</small></div><button type="button" data-close>关闭</button></header><main><p role="status">正在核对审核版本、发件账号和联系人…</p></main>';
    document.body.append(dialog);dialog.showModal();
    const close=()=>{if(dialog.dataset.busy==='1')return;dialog.close();dialog.remove();active=null;};
    dialog.querySelector('[data-close]').onclick=close;dialog.addEventListener('cancel',e=>{e.preventDefault();close();});
    try {
      const info=await api('info',{id});if(active!==dialog)return;
      dialog.querySelector('main').innerHTML=`<p><b>${escape(info.quote_no)}</b><br><small>已审核版本 ${escape(info.revision)} · ${escape(info.customer_name)}</small></p><p>发件账号：<b>${escape(info.sender)}</b><br><small>如需换账号，请先在 CRM 邮箱切换后重新打开本窗口。</small></p><fieldset><legend>附件格式</legend><label><input type="checkbox" name="pdf" checked> PDF（默认）</label><label><input type="checkbox" name="excel"> Excel</label></fieldset><label class="quote-mail-field">收件联系人<select data-contact><option value="">${info.contacts.length?'请选择联系人':'客户没有可用邮箱，到邮件窗口补填'}</option>${info.contacts.map(c=>`<option value="${escape(c.email)}">${escape(c.name)} · ${escape(c.email)}</option>`).join('')}</select></label><p class="quote-mail-hint">系统按同一审核快照生成文件。进入邮件后可编辑正文、检查签名和收件人，再手动发送。附件合计上限20MB；生成失败不会留下不完整草稿。</p><p role="status" data-status></p><button type="button" class="quote-mail-primary" data-create>生成附件并进入 CRM 邮件</button>${info.sent_history.length?`<details><summary>最近发送记录</summary>${info.sent_history.map(h=>`<p>${escape(h.sent_at)} · ${escape(h.sent_to)}<br><small>版本 ${escape(h.snapshot_hash.slice(0,12))}</small></p>`).join('')}</details>`:''}`;
      if(info.contacts.length===1)dialog.querySelector('[data-contact]').value=info.contacts[0].email;
      let token=requestToken();dialog.querySelector('main').addEventListener('change',()=>{token=requestToken();});
      dialog.querySelector('[data-create]').onclick=async()=>{
        const formats=['pdf','excel'].filter(f=>dialog.querySelector(`[name="${f}"]`).checked),email=dialog.querySelector('[data-contact]').value;
        const status=dialog.querySelector('[data-status]');
        if(!formats.length){status.textContent='至少选择一种附件格式。';return;}
        if(info.contacts.length>1&&!email){status.textContent='该客户有多位联系人，请明确选择收件人。';return;}
        dialog.dataset.busy='1';dialog.querySelectorAll('button,input,select').forEach(x=>x.disabled=true);status.textContent='正在生成附件，请稍候（通常数秒，复杂报价最长约一分钟）…';
        try {const result=await api('create',{id,account_id:info.account_id,token,formats,email});location.assign(result.url);}
        catch(e){status.textContent=e.message;dialog.dataset.busy='0';dialog.querySelectorAll('button,input,select').forEach(x=>x.disabled=false);}
      };
    } catch(e){dialog.querySelector('main').innerHTML='<p role="alert">'+escape(e.message)+'</p>';}
  }
  const style=document.createElement('style');style.textContent='.quote-mail-dialog{width:min(540px,calc(100vw - 24px));max-height:90vh;padding:0;border:1px solid #dce3ed;border-radius:14px;color:#182234;box-shadow:0 20px 80px #15233d40}.quote-mail-dialog::backdrop{background:#17233866}.quote-mail-dialog header{display:flex;align-items:center;justify-content:space-between;padding:16px 20px;border-bottom:1px solid #e3e8f0;gap:12px}.quote-mail-dialog h2{font-size:19px;margin:0 0 4px}.quote-mail-dialog main{padding:16px 20px;overflow:auto}.quote-mail-dialog p{line-height:1.6;overflow-wrap:anywhere}.quote-mail-dialog small,.quote-mail-hint{color:#60718c}.quote-mail-dialog fieldset{border:1px solid #dce3ed;border-radius:8px;padding:12px;display:flex;gap:22px}.quote-mail-field{display:grid;gap:6px;margin:16px 0}.quote-mail-field select{max-width:100%;padding:9px;border:1px solid #ccd6e4;border-radius:8px}.quote-mail-dialog input{width:auto}.quote-mail-dialog button{padding:9px 14px;border:1px solid #dce3ed;border-radius:8px;cursor:pointer}.quote-mail-dialog .quote-mail-primary{background:#2563eb;color:white;width:100%}.quote-mail-dialog [data-status]{color:#a13426}.quote-mail-dialog button:disabled{opacity:.6;cursor:wait}';document.head.append(style);
  const originalActions=window.quoteApprovalActionsHtml;
  if(typeof originalActions==='function')window.quoteApprovalActionsHtml=function(id,approved,label){return originalActions(id,approved,label)+(approved&&hasPerm('export_pdf_excel')?`<button class="blue" onclick="event.stopPropagation();QuoteMail.open(${Number(id)})">发送报价邮件</button>`:'');};
  const originalHistory=window.historyCardHtml;
  if(typeof originalHistory==='function')window.historyCardHtml=function(q,...args){
    const html=originalHistory.call(this,q,...args);
    return quoteApprovalStatus(q)==='approved'&&hasPerm('export_pdf_excel')?html.replace('<div class="history-actions">',`<div class="history-actions"><button class="blue" onclick="event.stopPropagation();QuoteMail.open(${Number(q.id)})">发送报价邮件</button>`):html;
  };
  window.QuoteMail={open};
})();
