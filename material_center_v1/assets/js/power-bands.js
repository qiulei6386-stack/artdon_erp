(() => {
  const modal=document.querySelector('#band-modal'),form=document.querySelector('#band-form'),endpoint='api/v1/power-standardization.php';
  document.addEventListener('click',event=>{
    const add=event.target.closest('[data-new-band]');if(add){form.reset();form.elements.id.value='';form.elements.code.disabled=false;modal.dataset.uiDirty='false';window.ArtdonUI.interactions.open(modal,add,'modal');}
    const edit=event.target.closest('[data-edit-band]');if(edit){const data=JSON.parse(edit.dataset.editBand);form.reset();Object.entries(data).forEach(([key,value])=>{const field=form.elements[key];if(!field)return;if(field.type==='checkbox')field.checked=Number(value)===1;else field.value=value??'';});form.elements.code.disabled=true;modal.dataset.uiDirty='false';window.ArtdonUI.interactions.open(modal,edit,'modal');}
    const save=event.target.closest('[data-save-band]');if(save)submit(save);
  });
  form?.addEventListener('input',()=>modal.dataset.uiDirty='true');
  async function submit(button){if(!form.reportValidity())return;const data=new FormData(form);data.set('action','save_band');const label=button.textContent;button.disabled=true;button.classList.add('is-loading');button.textContent='保存中…';try{const response=await fetch(endpoint,{method:'POST',body:data,headers:{Accept:'application/json'}});const json=await response.json();if(!response.ok||!json.ok)throw new Error(json.message||'保存失败');modal.dataset.uiDirty='false';window.ArtdonUI.toast('功率档已保存','success');window.ArtdonUI.interactions.close({force:true});setTimeout(()=>location.reload(),450);}catch(error){window.ArtdonUI.toast(error.message,'danger',0);}finally{button.disabled=false;button.classList.remove('is-loading');button.textContent=label;}}
})();
