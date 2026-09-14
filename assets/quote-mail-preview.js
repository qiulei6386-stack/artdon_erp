(function(){
  'use strict';
  let active;
  const style=document.createElement('style');
  style.textContent='.qmp-dialog{box-sizing:border-box;width:min(760px,calc(100vw - 24px));max-height:92vh;padding:0;border:1px solid #dce3ed;border-radius:12px;color:#182234;background:white;font:14px/1.5 Arial,sans-serif;overflow:hidden}.qmp-dialog[open]{display:flex;flex-direction:column}.qmp-dialog::backdrop{background:#17233880}.qmp-dialog header,.qmp-dialog footer{display:flex;gap:12px;align-items:center;justify-content:space-between;padding:12px 16px;flex:none}.qmp-dialog header{border-bottom:1px solid #e3e8f0}.qmp-dialog h2{font-size:18px;margin:0}.qmp-dialog main{padding:12px 16px;min-height:0;overflow:auto}.qmp-dialog dl{display:grid;grid-template-columns:72px minmax(0,1fr);gap:6px;margin:0 0 12px}.qmp-dialog dt{color:#60718c}.qmp-dialog dd{margin:0;overflow-wrap:anywhere}.qmp-dialog iframe{display:block;width:100%;height:300px;border:1px solid #dce3ed;border-radius:8px;box-sizing:border-box;background:white}.qmp-dialog small{display:block;color:#60718c;margin:6px 0}.qmp-dialog button{font:inherit;padding:7px 12px;border:1px solid #ccd6e4;border-radius:7px;background:#fff;cursor:pointer}.qmp-dialog footer{border-top:1px solid #e3e8f0;flex-wrap:wrap}.qmp-dialog footer button:last-child{background:#2563eb;color:white}.qmp-dialog ul{padding-left:20px;overflow-wrap:anywhere}.qmp-dialog li{margin:6px 0}.qmp-dialog a{color:#245dda}.qmp-dialog [hidden]{display:none!important}.qmp-dialog button:focus-visible{outline:2px solid #2563eb;outline-offset:2px}';
  document.head.append(style);
  function open(data,options={}){
    if(active){active.focus();return;}
    const previous=document.activeElement,d=document.createElement('dialog');d.className='qmp-dialog';active=d;
    d.innerHTML='<header><h2>发送预览</h2><button type="button" data-qmp-close>关闭</button></header><main><dl></dl><iframe title="邮件正文与签名预览" sandbox="" referrerpolicy="no-referrer"></iframe><small>这是当前内容的预览，不会发送。预览中修改被禁用；回到编辑页修改后请重新预览。</small><button type="button" data-qmp-images hidden>加载外链签名／正文图片</button><small data-qmp-image-note hidden>外链图片默认不加载，避免自动访问外部网站；不影响邮件原有图片内容。</small><h3>附件核对</h3><ul></ul><small data-qmp-note></small></main><footer><button type="button" data-qmp-back>返回修改</button><button type="button" data-qmp-next></button></footer>';
    const add=(label,value)=>{const dt=document.createElement('dt'),dd=document.createElement('dd');dt.textContent=label;dd.textContent=value||'未填写';d.querySelector('dl').append(dt,dd);};
    add('用途',data.mail_kind==='test'?'测试邮件':data.mail_kind==='formal'?'正式邮件':'旧草稿（未分类）');add('发件人',data.sender);add('收件人',data.to_emails);if(data.cc_emails)add('抄送',data.cc_emails);if(data.bcc_emails)add('密送',data.bcc_emails);add('主题',data.subject);add('报价版本',data.revision);
    // Inert parsing + an opaque-origin sandbox: email markup cannot operate the application.
    const template=document.createElement('template');template.innerHTML=data.body_html||'';const doc=template.content;
    doc.querySelectorAll('script,iframe,object,embed,base,meta,link,form,input,button,textarea,select,svg,math').forEach(el=>el.remove());
    doc.querySelectorAll('*').forEach(el=>{for(const attr of Array.from(el.attributes)){if(/^on/i.test(attr.name)||['srcdoc','srcset','formaction','contenteditable','autofocus'].includes(attr.name))el.removeAttribute(attr.name);}if(el.tagName==='A')el.removeAttribute('href');});
    const remote=Array.from(doc.querySelectorAll('img')).some(el=>!/^data:/i.test(el.getAttribute('src')||''));
    const frame=d.querySelector('iframe'),render=allow=>{frame.srcdoc='<!doctype html><html><head><meta http-equiv="Content-Security-Policy" content="default-src \'none\'; img-src data: '+(allow?'https: http:':'')+'; style-src \'unsafe-inline\'; base-uri \'none\'; form-action \'none\';"><meta name="referrer" content="no-referrer"><style>body{font:14px/1.6 Arial,sans-serif;margin:14px;overflow-wrap:anywhere}img{max-width:100%;height:auto}table{max-width:100%}</style></head><body>'+template.innerHTML+'</body></html>';};render(false);
    const rows=Math.ceil((doc.textContent||'').length/45)+doc.querySelectorAll('p,br,div,tr').length;
    frame.style.height=Math.min(360,Math.max(140,rows*20+(doc.querySelector('img')?140:30)))+'px';
    d.querySelector('[data-qmp-images]').hidden=!remote;d.querySelector('[data-qmp-image-note]').hidden=!remote;
    d.querySelector('[data-qmp-images]').onclick=()=>{render(true);d.querySelector('[data-qmp-images]').hidden=true;};
    for(const f of data.files||[]){const li=document.createElement('li');li.textContent=f.name+' · '+Math.ceil(Number(f.size||0)/1024)+' KB ';
      if(f.url){const url=new URL(f.url,location.href);if(url.origin===location.origin&&url.pathname.endsWith('/quote_mail_api.php')&&url.searchParams.get('action')==='file'){const a=document.createElement('a');a.href=url.href;a.target='_blank';a.rel='noopener';a.textContent=/\.pdf$/i.test(f.name)?'打开 PDF 预览':'下载 Excel 核对';li.append(a);}}
      d.querySelector('ul').append(li);
    }
    d.querySelector('[data-qmp-note]').textContent=options.note||'这里只预览，不入发送队列、不新增发送记录。';
    const close=()=>{d.close();d.remove();active=null;if(previous?.isConnected)previous.focus();};
    d.querySelector('[data-qmp-close]').onclick=close;d.querySelector('[data-qmp-back]').onclick=close;d.addEventListener('cancel',e=>{e.preventDefault();close();});
    d.querySelector('[data-qmp-next]').textContent=options.continueLabel||'核对完成，返回写信';d.querySelector('[data-qmp-next]').onclick=()=>{close();options.onContinue?.();};
    document.body.append(d);d.showModal();
  }
  window.QuoteMailPreview={open};
})();
