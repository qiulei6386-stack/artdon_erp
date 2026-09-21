/* Snapshot bodies stay immutable. Only an explicit workflow event revokes use. */
let bomVoidContext=null;
function renderBomSnapshotVoid(){
  document.getElementById('bomSnapshotVoidPanel')?.remove();bomVoidContext=null;
  const s=bomSnapshotCurrent,p=getCurrent();if(!s||!p||!bomSnapshotOpen||s.project_uid!==p.id)return;
  const current=Number(p.costPublication?.snapshot_id)===Number(s.id)&&p.costPublication?.source==='approved_snapshot';
  const box=document.createElement('section');box.id='bomSnapshotVoidPanel';box.className='bom-snapshot-note';
  if(s.voided){box.textContent=`已作废 · ${s.voided_by||''} · ${s.voided_at||''}。原因：${s.void_reason||''}。仅供追溯，不可加入新报价。`;}
  else if(hasPerm('unapprove_bom')&&currentCan.cost_view){
    bomVoidContext={project:p.id,revision:p.revision,snapshot:Number(s.id),current};
    const alternatives=bomSnapshots.filter(v=>!v.voided&&Number(v.id)!==Number(s.id)&&String(v.model)===String(s.model));
    box.innerHTML=`<details><summary>作废此快照（保留历史内容）</summary><p>已有报价/订单不改价。作废不可直接撤销，后续修正须重新审核生成新快照。</p>${current?`<label>当前有效版作废后怎么处理<select id="bomVoidReplacement"><option value="">请选择，不自动回退</option>${alternatives.map(v=>`<option value="${Number(v.id)}">替代：${esc(v.version_no)} · ${esc(v.snapshot_uid)} · ${esc(v.approved_at||v.created_at)}</option>`).join('')}<option value="pause">暂停此BOM报价取价，待新版本审核</option></select></label>`:'<p>这是历史快照，当前有效版不变。</p>'}<label>作废原因（必填）<textarea id="bomVoidReason" rows="2" maxlength="500" placeholder="例如：价格或物料错误，说明处理依据"></textarea></label><button id="bomVoidConfirm" type="button" class="danger" onclick="voidBomSnapshot()">确认作废此快照</button></details>`;
  }else return;
  $('bomSnapshotDetail').prepend(box);
}
async function voidBomSnapshot(){
  const c=bomVoidContext,p=getCurrent(),s=bomSnapshotCurrent;
  if(bomWriteBusy||!c||!p||!s||!bomSnapshotOpen||p.id!==c.project||p.revision!==c.revision||Number(s.id)!==c.snapshot)return;
  if(!hasPerm('unapprove_bom')||!currentCan.cost_view)return alert('需要BOM退审及成本查看权限');
  const reason=$('bomVoidReason').value.trim(),choice=$('bomVoidReplacement')?.value||'';
  if(!reason)return alert('请填写作废原因');if(c.current&&!choice)return alert('请选择替代版本或明确暂停取价');
  bomWriteBusy=true;$('bomVoidConfirm').disabled=true;
  try{
    const r=await bomWrite('void_snapshot',{project_uid:c.project,expected_revision:c.revision,snapshot_id:c.snapshot,review_note:reason,replacement_snapshot_id:choice&&choice!=='pause'?Number(choice):0,publication_mode:choice==='pause'?'pause':'replace'});
    if(!r.ok)throw new Error(r.error||'结果未确认，请保留原因并重试同一操作');
    if(r.project?.project_uid!==c.project)throw new Error('回执不匹配，请重新读取');
    bomApplySavedProject(r.project);alert(r.message||'快照已作废');
    bomWriteBusy=false;
    if(currentId===c.project){await loadProject(c.project);await openBomSnapshots();}
  }catch(e){alert(e.message);}finally{bomWriteBusy=false;if($('bomVoidConfirm'))$('bomVoidConfirm').disabled=false;updateBomWorkflowUI();}
}
const bomVoidOriginalShow=showBomSnapshot;
showBomSnapshot=async function(...args){document.getElementById('bomSnapshotVoidPanel')?.remove();bomVoidContext=null;await bomVoidOriginalShow(...args);renderBomSnapshotVoid();};
const bomVoidOriginalList=renderBomSnapshotList;
renderBomSnapshotList=function(){bomVoidOriginalList();const buttons=$('bomSnapshotList')?.querySelectorAll('button')||[];bomSnapshots.forEach((s,i)=>{if(s.voided&&buttons[i]){const mark=document.createElement('strong');mark.textContent='已作废 · ';mark.style.color='#b91c1c';buttons[i].prepend(mark);}});};
