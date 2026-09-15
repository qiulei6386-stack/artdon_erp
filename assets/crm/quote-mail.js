(function(){
  'use strict';
  const mail=window.MailModule;if(!mail)return;
  const originalOpen=mail.openCompose,originalData=mail.composeData;
  mail.openCompose=function(mode,message){
    let meta={};try{meta=JSON.parse(message?.draft_meta_json||'{}');}catch(e){}
    const result=originalOpen.call(this,mode,message);this.quoteMailToken=meta.quote_mail_token||'';this.quoteMailMeta=meta;
    document.querySelector('[data-quote-mail-preview]')?.remove();
    if(this.quoteMailToken){
      const form=document.querySelector('[data-mail-compose-form]');
      if(form?.elements.customer_id)form.elements.customer_id.value=message.linked_customer_id||'';
      if(form?.elements.contact_id)form.elements.contact_id.value=message.linked_contact_id||'';
      const hint=document.querySelector('[data-mail-compose-status]');if(hint)hint.textContent=meta.quote_mail_kind==='test'?'测试邮件：仅允许发送至 '+meta.quote_test_recipient+'，不可添加抄送/密送。核对后手动点击发送。':'已审核报价附件已带入。请核对收件人、正文和签名，再点击发送；删除或替换报价附件将阻止发送。';
      if(form){
        const bar=document.createElement('div');bar.dataset.quoteMailPreview='1';bar.style.cssText='padding:10px 14px;border:1px solid #dce5f4;border-radius:8px;background:#f3f7ff;display:flex;flex-wrap:wrap;align-items:center;gap:8px;margin:8px 0;overflow-wrap:anywhere';
        const text=document.createElement('span');text.textContent=(meta.quote_mail_kind==='test'?'测试邮件 · ':meta.quote_mail_kind==='formal'?'正式邮件 · ':'')+(meta.quote_no||'已审核报价')+' · 版本 '+(meta.quote_revision||'已锁定');bar.append(text);
        (meta.quote_files||[]).forEach((name,index)=>{const link=document.createElement('a');link.href='quote_mail_api.php?action=file&token='+encodeURIComponent(this.quoteMailToken)+'&index='+index;link.target='_blank';link.rel='noopener';link.textContent=/\.pdf$/i.test(name)?'预览 PDF':'下载核对 Excel';bar.append(link);});
        const preview=document.createElement('button');preview.type='button';preview.textContent='发送预览';preview.dataset.quoteComposePreview='1';
        preview.onclick=async()=>{preview.disabled=true;const token=this.quoteMailToken,current=this.composeData();
          try{
            const response=await fetch('quote_mail_api.php?action=preview',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','X-CSRF-Token':window.CRM_BOOTSTRAP?.csrf||''},body:JSON.stringify({token,current})});
            const json=await response.json();if(!response.ok||!json.success)throw new Error(json.message||'预览读取失败');
            if(!bar.isConnected||this.quoteMailToken!==token)return;
            const latest=this.composeData();if(['subject','to_emails','cc_emails','bcc_emails','body_html','attachments_json'].some(key=>latest[key]!==current[key]))throw new Error('邮件内容已修改，请重新点击发送预览。');
            const extra=this.composeUploadFiles?.(true)||[];
            for(const file of extra)json.data.files.push({name:file.name+'（待上传）',size:file.size});
            QuoteMailPreview.open(json.data,{note:extra.length?'另有待上传附件，仅列出名称；请先保存草稿后再次预览核对文件。':'当前编辑内容只读预览，没有保存或发送。核对完成后回到写信窗口手动发送。'});
          }catch(e){if(hint)hint.textContent=e.message;}finally{preview.disabled=false;}
        };bar.append(preview);
        form.prepend(bar);
      }
    }return result;
  };
  mail.composeData=function(){const data=originalData.call(this);if(this.quoteMailToken){data.quote_mail_token=this.quoteMailToken;const meta=JSON.parse(data.draft_meta_json||'{}');for(const key of ['quote_mail_token','quote_no','quote_revision','quote_files','quote_mail_kind','quote_test_recipient','quote_contact_ids'])meta[key]=this.quoteMailMeta[key];data.draft_meta_json=JSON.stringify(meta);}return data;};
  const token=new URLSearchParams(location.search).get('quote_mail');if(!/^[a-f0-9]{48}$/.test(token||''))return;
  let attempts=0;const timer=setInterval(async()=>{
    if(!mail.account){if(++attempts<100)return;clearInterval(timer);window.crmOpenFallbackDialog?.('报价邮件待打开','请先绑定并选择发件邮箱，然后刷新本页；草稿已保留。');return;}
    clearInterval(timer);
    try {
      const response=await fetch('quote_mail_api.php?action=get&token='+encodeURIComponent(token),{credentials:'same-origin'});const json=await response.json();
      if(!json.success)throw new Error(json.message||'报价草稿读取失败');
      if(document.querySelector('[data-mail-compose-dialog]')?.open)throw new Error('已有邮件正在编辑，请先关闭当前邮件再刷新打开报价草稿。');
      mail.openCompose('draft',json.data.draft);
      const url=new URL(location.href);url.searchParams.delete('quote_mail');history.replaceState(null,'',url.pathname+url.search+url.hash);
    }catch(e){window.crmOpenFallbackDialog?.('报价邮件未打开',e.message+' 草稿保留在创建时的发件邮箱中。');}
  },300);
})();
