(()=>{
 const form=document.querySelector('[data-bom-sync]');if(!form)return;
 form.addEventListener('submit',async event=>{event.preventDefault();const button=form.querySelector('button');button.disabled=true;button.textContent='同步中…';
 try{const response=await fetch(`${window.MC_BASE_URL}/api/v1/source-sync.php`,{method:'POST',body:new FormData(form),credentials:'same-origin',headers:{Accept:'application/json'}});const data=await response.json();if(!response.ok||!data.ok)throw new Error(data.message||'同步失败');location.reload();}
 catch(error){alert(error instanceof Error?error.message:'同步失败');button.disabled=false;button.textContent='同步 BOM 快照';}});
})();
