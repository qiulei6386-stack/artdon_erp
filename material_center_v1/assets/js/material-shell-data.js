(()=>{
  const form=document.querySelector('[data-material-create]');
  const notify=(title,message)=>{
    const region=document.querySelector('[data-toast-region]');
    if(!region)return;
    const item=document.createElement('div');
    item.className='mc-toast';
    const strong=document.createElement('strong');strong.textContent=title;
    const span=document.createElement('span');span.textContent=message;
    item.append(strong,span);region.appendChild(item);setTimeout(()=>item.remove(),3600);
  };
  form?.addEventListener('submit',async event=>{
    event.preventDefault();
    const button=form.querySelector('[type="submit"]');
    const error=form.querySelector('[data-material-form-error]');
    button.disabled=true;error.hidden=true;
    try{
      const body=new FormData(form);body.set('action','save');
      const response=await fetch(`${window.MC_BASE_URL}/api/v1/material-master.php`,{method:'POST',body,credentials:'same-origin',headers:{Accept:'application/json'}});
      const payload=await response.json();
      if(!response.ok||!payload.ok)throw new Error(payload.message||'保存失败');
      notify('保存成功',payload.message||'草稿物料已保存');
      setTimeout(()=>location.reload(),350);
    }catch(reason){
      error.textContent=reason instanceof Error?reason.message:'保存失败';
      error.hidden=false;notify('保存失败',error.textContent);
    }finally{button.disabled=false;}
  });
  const search=document.querySelector('[data-table-search]');
  if(search){
    search.value=new URLSearchParams(location.search).get('q')||'';
    let timer;
    search.addEventListener('input',()=>{
      clearTimeout(timer);
      timer=setTimeout(()=>{
        const url=new URL(location.href);
        const value=search.value.trim();
        value?url.searchParams.set('q',value):url.searchParams.delete('q');
        location.href=url.toString();
      },450);
    });
  }
})();
