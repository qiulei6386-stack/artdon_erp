(function () {
  'use strict';
  const escape = value => String(value ?? '').replace(/[&<>"']/g, x => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[x]));
  const requestToken = () => Array.from(crypto.getRandomValues(new Uint8Array(24)), x => x.toString(16).padStart(2,'0')).join('');
  async function api(action, data) {
    const query = new URLSearchParams({action});
    const write = action === 'create' || action === 'preview';
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
      dialog.querySelector('main').innerHTML=`
        <p class="quote-mail-summary"><b>${escape(info.quote_no)}</b> · ${escape(info.customer_name)}<br><small>审核版本 ${escape(info.revision)} · 发件账号 ${escape(info.sender)}</small></p>
        <fieldset><legend>邮件用途</legend><label><input type="radio" name="mail-kind" value="formal" checked> 正式邮件</label><label><input type="radio" name="mail-kind" value="test"> 测试邮件</label></fieldset>
        <label class="quote-mail-field" data-formal-field>收件联系人<select data-contact><option value="">${info.contacts.length?'请选择联系人':'客户没有可用邮箱，到CRM补填'}</option>${info.contacts.map(c=>`<option value="${escape(c.email)}">${escape(c.name)} · ${escape(c.email)}</option>`).join('')}</select></label>
        <label class="quote-mail-field" data-test-field hidden>指定测试邮箱<input data-test-email type="email" maxlength="254" value="${escape(info.test_recipient||info.sender)}" autocomplete="off"><small>只发这个地址，不带客户收件人、抄送或密送；主题自动标为[测试]。</small></label>
        <label class="quote-mail-field">简短正文<textarea data-body rows="3" maxlength="2000" placeholder="写一两句话即可，签名自动带入。">${escape('Please find our quotation '+info.quote_no+' attached for your review.')}</textarea><small>写一两句话即可；签名自动带入，进入CRM后仍可修改。</small></label>
        <fieldset><legend>附件格式</legend><label><input type="checkbox" name="pdf" checked> PDF（默认）</label><label><input type="checkbox" name="excel"> Excel</label></fieldset>
        <p class="quote-mail-hint">同一审核版附件，合计上限20MB。下一步仅准备邮件；在CRM核对附件、正文、签名后，手动点击发送。换发件账号请先在CRM切换。</p>
        <small>发送预览会先生成并保存草稿及真实附件，不会发送；返回修改后可重新预览。</small><p role="status" aria-live="polite" data-status></p><button type="button" data-preview>发送预览</button><button type="button" class="quote-mail-primary" data-create>准备正式邮件 → CRM 确认发送</button>
        <details class="quote-mail-history"><summary>发送记录（我的账号）</summary><small>测试和正式分别标记。成功表示邮件服务器已接收，不等于对方已阅读。旧记录缺失字段不补造。</small><div data-history-list></div><div class="quote-mail-history-actions"><button type="button" data-refresh>刷新记录</button><button type="button" data-more hidden>更早记录</button></div><p data-history-status role="status"></p></details>`;
      if(info.contacts.length===1)dialog.querySelector('[data-contact]').value=info.contacts[0].email;
      const kind=()=>dialog.querySelector('[name="mail-kind"]:checked').value;
      let token=requestToken();dialog.querySelector('main').addEventListener('input',()=>{token=requestToken();});
      dialog.querySelector('main').addEventListener('change',()=>{
        token=requestToken();const test=kind()==='test';
        dialog.querySelector('[data-formal-field]').hidden=test;dialog.querySelector('[data-test-field]').hidden=!test;
        dialog.querySelector('[data-create]').textContent=test?'准备测试邮件 → CRM 确认发送':'准备正式邮件 → CRM 确认发送';dialog.querySelector('[data-status]').textContent='';
      });
      let historyOffset=0,historyBusy=false;
      const renderHistory=(data,append=false)=>{
        const states={scheduled:'待发送',running:'发送中',success:'发送成功（服务器已接收）',failed:'发送失败',unknown:'结果待核对，请勿重复发送',cancelled:'已取消'};
        const html=data.rows.map(h=>`<article><b>${h.mail_kind==='test'?'测试':h.mail_kind==='formal'?'正式':'旧记录（未分类）'} · ${escape(states[h.status]||'结果待核对')}</b><p>收件人：${escape(h.to_emails||'未记录')}${h.cc_emails?'<br>抄送：'+escape(h.cc_emails):''}${h.bcc_emails?'<br>密送：'+escape(h.bcc_emails):''}<br>发件人：${escape(h.sender_name||'')} ${escape(h.sender_email||'旧记录未记录发件账号')}<br>提交：${escape(h.queued_at||'未记录')}${h.sent_at?'<br>发送：'+escape(h.sent_at):h.scheduled_at?'<br>计划发送：'+escape(h.scheduled_at):''}${h.status!=='success'&&h.finished_at?'<br>处理时间：'+escape(h.finished_at):''}</p><small>${escape(h.subject||'')} · 版本 ${escape(h.snapshot_hash.slice(0,12))}${h.error_message?'<br>'+escape(h.error_message):''}</small></article>`).join('');
        const list=dialog.querySelector('[data-history-list]');if(append)list.insertAdjacentHTML('beforeend',html);else list.innerHTML=html||'<p>暂无发送记录；只生成草稿不会记为已发送。</p>';
        historyOffset=data.offset+data.rows.length;dialog.querySelector('[data-more]').hidden=!data.has_more;
      };
      renderHistory(info.history||{rows:[],offset:0,has_more:false});
      const loadHistory=async more=>{if(historyBusy)return;historyBusy=true;const status=dialog.querySelector('[data-history-status]');status.textContent='读取中…';
        try{const result=await api('history',{id,offset:more?historyOffset:0});if(active===dialog){renderHistory(result,more);status.textContent='';}}
        catch(e){status.textContent=e.message;}finally{historyBusy=false;}};
      dialog.querySelector('[data-refresh]').onclick=()=>loadHistory(false);dialog.querySelector('[data-more]').onclick=()=>loadHistory(true);
      const prepare=async preview=>{
        const formats=['pdf','excel'].filter(f=>dialog.querySelector(`[name="${f}"]`).checked),mail_kind=kind();
        const email=mail_kind==='test'?dialog.querySelector('[data-test-email]').value.trim():dialog.querySelector('[data-contact]').value,body_text=dialog.querySelector('[data-body]').value.trim();
        const status=dialog.querySelector('[data-status]');
        if(!formats.length){status.textContent='至少选择一种附件格式。';return;}
        if(mail_kind==='formal'&&info.contacts.length>1&&!email){status.textContent='该客户有多位联系人，请明确选择收件人。';return;}
        if(mail_kind==='test'&&(!email||!dialog.querySelector('[data-test-email]').checkValidity())){status.textContent='请填写一个有效的测试邮箱。';return;}
        dialog.dataset.busy='1';dialog.querySelectorAll('button,input,select,textarea').forEach(x=>x.disabled=true);status.textContent='正在生成附件，请稍候（通常数秒，复杂报价最长约一分钟）…';
        try {const result=await api('create',{id,account_id:info.account_id,token,formats,email,mail_kind,body_text});
          if(!preview){location.assign(result.url);return;}
          const data=await api('preview',{token:result.token||token});
          dialog.dataset.busy='0';dialog.querySelectorAll('button,input,select,textarea').forEach(x=>x.disabled=false);status.textContent='预览草稿已保存，尚未发送。修改内容后请重新预览。';
          QuoteMailPreview.open(data,{continueLabel:'继续到 CRM 核对发送',onContinue:()=>location.assign(result.url),note:'预览已保存为草稿；继续只打开CRM写信窗口，不会发送。重复预览相同内容复用本草稿，改动内容会生成新的草稿。'});
        }
        catch(e){status.textContent=e.message;dialog.dataset.busy='0';dialog.querySelectorAll('button,input,select,textarea').forEach(x=>x.disabled=false);}
      };
      dialog.querySelector('[data-create]').onclick=()=>prepare(false);dialog.querySelector('[data-preview]').onclick=()=>prepare(true);
    } catch(e){dialog.querySelector('main').innerHTML='<p role="alert">'+escape(e.message)+'</p>';}
  }
  const style=document.createElement('style');style.textContent='.quote-mail-dialog{width:min(540px,calc(100vw - 24px));max-height:90vh;padding:0;border:1px solid #dce3ed;border-radius:14px;color:#182234;box-shadow:0 20px 80px #15233d40}.quote-mail-dialog::backdrop{background:#17233866}.quote-mail-dialog header{display:flex;align-items:center;justify-content:space-between;padding:16px 20px;border-bottom:1px solid #e3e8f0;gap:12px}.quote-mail-dialog h2{font-size:19px;margin:0 0 4px}.quote-mail-dialog main{padding:16px 20px;overflow:auto}.quote-mail-dialog p{line-height:1.6;overflow-wrap:anywhere}.quote-mail-dialog small,.quote-mail-hint{color:#60718c}.quote-mail-dialog fieldset{border:1px solid #dce3ed;border-radius:8px;padding:12px;display:flex;gap:22px}.quote-mail-field{display:grid;gap:6px;margin:16px 0}.quote-mail-field select{max-width:100%;padding:9px;border:1px solid #ccd6e4;border-radius:8px}.quote-mail-dialog input{width:auto}.quote-mail-dialog button{padding:9px 14px;border:1px solid #dce3ed;border-radius:8px;cursor:pointer}.quote-mail-dialog .quote-mail-primary{background:#2563eb;color:white;width:100%}.quote-mail-dialog [data-status]{color:#a13426}.quote-mail-dialog button:disabled{opacity:.6;cursor:wait}';document.head.append(style);
  style.textContent+='.quote-mail-dialog{box-sizing:border-box;font-size:14px;overflow:hidden}.quote-mail-dialog[open]{display:flex;flex-direction:column}.quote-mail-dialog header{flex:none;padding:12px 16px}.quote-mail-dialog main{min-height:0;padding:12px 16px}.quote-mail-dialog p{margin:8px 0;line-height:1.5}.quote-mail-dialog fieldset{padding:8px 10px;gap:16px;margin:10px 0;flex-wrap:wrap}.quote-mail-dialog fieldset label{white-space:nowrap}.quote-mail-dialog .quote-mail-field{margin:10px 0;gap:5px}.quote-mail-dialog [hidden]{display:none!important}.quote-mail-dialog .quote-mail-field select,.quote-mail-dialog .quote-mail-field input,.quote-mail-dialog textarea{font:inherit;width:100%;min-width:0;box-sizing:border-box;border:1px solid #ccd6e4;border-radius:7px;padding:7px 9px;background:white;color:#182234}.quote-mail-dialog textarea{height:76px;min-height:76px;max-height:200px;resize:vertical;line-height:1.4}.quote-mail-dialog .quote-mail-hint{font-size:12px}.quote-mail-history{margin-top:14px;border-top:1px solid #e3e8f0;padding-top:10px}.quote-mail-history summary{cursor:pointer;font-weight:600;padding:3px 0 8px}.quote-mail-history article{padding:10px 0;border-bottom:1px solid #e3e8f0;overflow-wrap:anywhere}.quote-mail-history article p{font-size:12px}.quote-mail-history-actions{display:flex;flex-wrap:wrap;gap:8px;margin-top:8px}.quote-mail-dialog button:focus-visible,.quote-mail-dialog input:focus-visible,.quote-mail-dialog textarea:focus-visible,.quote-mail-dialog select:focus-visible{outline:2px solid #2563eb;outline-offset:2px}';
  style.textContent+='.quote-mail-dialog [data-preview]{width:100%;margin-bottom:8px;border-color:#2563eb;color:#245dda;background:#f3f7ff}';
  const originalActions=window.quoteApprovalActionsHtml;
  if(typeof originalActions==='function')window.quoteApprovalActionsHtml=function(id,approved,label){return originalActions(id,approved,label)+(approved&&hasPerm('export_pdf_excel')?`<button class="blue" onclick="event.stopPropagation();QuoteMail.open(${Number(id)})">发送报价邮件</button>`:'');};
  const originalHistory=window.historyCardHtml;
  if(typeof originalHistory==='function')window.historyCardHtml=function(q,...args){
    const html=originalHistory.call(this,q,...args);
    return quoteApprovalStatus(q)==='approved'&&hasPerm('export_pdf_excel')?html.replace('<div class="history-actions">',`<div class="history-actions"><button class="blue" onclick="event.stopPropagation();QuoteMail.open(${Number(q.id)})">发送报价邮件</button>`):html;
  };
  window.QuoteMail={open};
})();
