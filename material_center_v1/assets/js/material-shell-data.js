(()=>{
  const form=document.querySelector('[data-material-create]');
  const category=form?.querySelector('[data-material-category]'),dynamic=form?.querySelector('[data-category-fields]');
  const loadFields=async()=>{
    if(!category||!dynamic)return;
    const code=category.selectedOptions[0]?.dataset.categoryCode||'';dynamic.replaceChildren();
    try{
      const response=await fetch(`${window.MC_BASE_URL}/api/v1/category-fields.php?category=${encodeURIComponent(code)}&material_id=${encodeURIComponent(form.dataset.materialId||'0')}`,{credentials:'same-origin',headers:{Accept:'application/json'}});
      const result=await response.json();if(!response.ok||!result.ok)throw new Error(result.message||'字段加载失败');
      result.data.fields.forEach(field=>{const label=document.createElement('label'),title=document.createElement('span'),input=field.data_type==='enum'?document.createElement('select'):document.createElement('input');label.className='mc-field';title.textContent=field.field_name+(field.unit?`（${field.unit}）`:'')+(+field.is_required?' *':'');input.name=`fields[${field.field_code}]`;input.required=+field.is_required===1;if(field.data_type==='enum'){const blank=document.createElement('option');blank.value='';blank.textContent='请选择';input.append(blank,...field.options.map(item=>{const option=document.createElement('option');option.value=item.value;option.textContent=item.label;return option}))}else if(field.data_type==='decimal'){input.type='number';input.step='any';if(field.validation?.min!==undefined)input.min=field.validation.min;if(field.validation?.max!==undefined)input.max=field.validation.max}else{input.type='text';if(field.validation?.maxLength)input.maxLength=field.validation.maxLength}const saved=result.data.values[field.field_code];if(saved!==undefined&&saved!==null)input.value=String(saved);else if(field.default!==null&&field.default!==undefined)input.value=String(field.default);label.append(title,input);dynamic.append(label)});
    }catch(error){const note=document.createElement('span');note.className='mc-form-error';note.textContent=error instanceof Error?error.message:'字段加载失败';dynamic.append(note)}
  };
  category?.addEventListener('change',loadFields);loadFields();
  document.querySelectorAll('[data-open-modal="new-modal"]').forEach(button=>button.addEventListener('click',()=>form?.reset()));
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
  form?.addEventListener('reset',()=>{delete form.dataset.materialId;setTimeout(loadFields,0)});
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
