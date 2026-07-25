document.addEventListener('DOMContentLoaded',()=>{
  const form=document.querySelector('#settings-form');if(!form)return;
  const initial=JSON.parse(document.querySelector('#settings-values')?.textContent||'{}');
  const applyValues=(values)=>Object.entries(values).forEach(([key,value])=>{const el=form.elements.namedItem(key);if(!el)return;if(el.type==='checkbox')el.checked=Boolean(value);else el.value=String(value);});
  applyValues(initial);
  const submit=async(action='save')=>{
    const button=document.querySelector(action==='reset'?'[data-settings-reset]':'[type="submit"][form="settings-form"]');
    button?.setAttribute('aria-busy','true');if(button)button.disabled=true;
    const values={};new FormData(form).forEach((value,key)=>{if(key!=='csrf_token')values[key]=value;});
    form.querySelectorAll('input[type="checkbox"]').forEach(el=>values[el.name]=el.checked);
    const body=new FormData();body.set('csrf_token',form.elements.csrf_token.value);body.set('action',action);body.set('values',JSON.stringify(values));
    try{const response=await fetch('api/v1/settings.php',{method:'POST',body,credentials:'same-origin'});const data=await response.json();if(!response.ok||!data.ok)throw new Error(data.message||'保存失败');window.ArtdonUI.toast(data.message,'success');if(action==='save')setTimeout(()=>location.reload(),350);else{applyValues(data.data.values);window.ArtdonUI.toast('已恢复默认值，刷新后生效','info');}}
    catch(error){window.ArtdonUI.toast(error.message||'网络失败，请稍后重试','error');}
    finally{button?.removeAttribute('aria-busy');if(button)button.disabled=false;}
  };
  form.addEventListener('submit',event=>{event.preventDefault();submit('save');});
  document.querySelector('[data-settings-reset]')?.addEventListener('click',event=>window.ArtdonUI.confirm({title:'恢复个人默认设置？',message:'只清除当前账号的个人覆盖值，不影响全局设置。',confirmLabel:'恢复',trigger:event.currentTarget,onConfirm:()=>submit('reset')}));
});
