(function(root){
  'use strict';
  const esc=v=>String(v??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  const titles={completed:'今日完成',pending:'应办未完成',overdue:'逾期未完成',changes:'今日变更',tomorrow:'明日待办',active:'其他进行中'};
  const status={pending_accept:'待接收',accepted:'已接收',in_progress:'进行中',paused:'暂停',submitted:'待确认',returned:'已退回',rejected:'已拒绝',done:'已完成',cancelled:'已取消'};
  const labels={normal:'普通',important:'重要',urgent:'紧急',today:'今日重点',personal:'个人',private:'私人',dispatch:'派工',single:'单人',multi:'多人',plan:'计划',recurring:'周期'};
  function install(env){
    const trigger=document.getElementById('dailyReportBtn');if(!trigger)return;
    const dialog=document.createElement('dialog');dialog.className='dd-dialog';dialog.setAttribute('aria-labelledby','dd-title');
    dialog.innerHTML='<header class="dd-head"><div><span class="dd-eyebrow">DAILY BRIEF · 待办日报</span><h2 id="dd-title">今日总结</h2></div><button type="button" data-dd-close aria-label="关闭今日总结">关闭</button></header><div class="dd-controls"><label>日期<input type="date" data-dd-date></label><label data-dd-person hidden>查看人员<select data-dd-user></select></label><button type="button" data-dd-refresh>刷新</button></div><p class="dd-status" role="status" aria-live="polite">准备读取总结</p><main class="dd-body"></main><footer class="dd-foot">北京时间 · 实际负责人归属 · 多人任务按个人实例计数</footer>';
    document.body.appendChild(dialog);
    const $=s=>dialog.querySelector(s);let report=null,serial=0,saving=false,draft=null;
    const key=r=>r.date+':'+r.user_id;
    const dirty=()=>draft && report && draft.key===key(report) && draft.text!==report.note.note;
    function leave(){return !saving && (!dirty() || root.confirm('补充说明还没保存，确定放弃本次输入？'));}
    function close(){if(!leave())return;serial++;draft=null;dialog.close();trigger.focus();}
    trigger.addEventListener('click',()=>{if(dialog.open)return;report=null;draft=null;dialog.showModal();$('.dd-body').replaceChildren();load({});});
    $('[data-dd-close]').onclick=close;
    dialog.addEventListener('cancel',e=>{e.preventDefault();close();});
    $('[data-dd-refresh]').onclick=()=>{if(saving)return;load(report?{date:report.date,user_id:report.user_id,section:report.section,page:report.page}:{});};
    $('[data-dd-date]').onchange=()=>changeScope({date:$('[data-dd-date]').value,user_id:report?.user_id});
    $('[data-dd-user]').onchange=()=>changeScope({date:report?.date,user_id:Number($('[data-dd-user]').value)});
    function changeScope(params){if(!leave()){if(report){$('[data-dd-date]').value=report.date;$('[data-dd-user]').value=report.user_id;}return;}draft=null;load(params);}
    async function load(params){
      if(saving)return;const request=++serial;$('.dd-status').textContent='正在读取当天记录…';dialog.setAttribute('aria-busy','true');
      try{const data=await env.api('daily_report',params);if(request!==serial || !dialog.open)return;report=data;render();}
      catch(e){if(request!==serial || !dialog.open)return;$('.dd-status').textContent=e.message || '读取失败，请重试';if(report){$('[data-dd-date]').value=report.date;$('[data-dd-user]').value=report.user_id;}}
      finally{if(request===serial)dialog.removeAttribute('aria-busy');}
    }
    function value(change,side){const v=change[side];if(change.field==='status')return status[v]||v;if(['priority','task_type','dispatch_mode'].includes(change.field))return labels[v]||v;if(change.field==='progress')return v+'%';if(change.field==='is_deleted')return v==='1'?'已删除':'正常';return v||'未设置';}
    function card(t){
      const changes=(t.changes||[]).map(c=>'<div class="dd-change"><span>'+esc(c.label)+'</span><del>'+esc(value(c,'before'))+'</del><span aria-hidden="true">→</span><ins>'+esc(value(c,'after'))+'</ins></div>').join('');
      return '<article class="dd-task"><div class="dd-task-top"><button type="button" data-dd-task="'+Number(t.id)+'">'+esc(t.title||'未命名任务')+'</button><span class="dd-badge '+(t.status==='done'?'is-done':'')+'">'+esc(status[t.status]||t.status)+'</span></div><p class="dd-meta">'+esc(t.owner_name)+' · '+esc(t.task_no||'')+(t.parent_group_id?' · 多人个人任务':' · '+esc(labels[t.task_type]||'待办'))+'</p>'+(changes?'<div class="dd-change-list">'+changes+'</div><p class="dd-meta">'+esc(t.time)+' · 操作人：'+esc(t.actor_name)+'</p>':'<p class="dd-meta">'+(t.overdue_days?'逾期 '+Number(t.overdue_days)+' 天 · ':'')+'截止 '+esc(t.due_at||t.task_date||'未设置')+(t.status==='done'?' · 完成 '+esc(t.completed_at||'时间未记录'):' · 进度 '+Number(t.progress||0)+'%')+'</p>')+'</article>';
    }
    function render(){
      const r=report;$('[data-dd-date]').value=r.date;$('[data-dd-date]').max=r.today;$('[data-dd-date]').min=r.started_at.slice(0,10);
      $('[data-dd-person]').hidden=!r.is_admin;
      $('[data-dd-user]').innerHTML='<option value="0">全员汇总</option>'+r.users.map(u=>'<option value="'+Number(u.id)+'">'+esc(u.name)+'</option>').join('');$('[data-dd-user]').value=r.user_id;
      $('#dd-title').textContent=r.user_name+' · '+(r.historical?'当日总结':'今日总结');
      $('.dd-status').textContent=r.partial?'首日记录：任务状态已纳入，变更明细从 '+r.started_at+' 开始，之前的修改不补造。':r.historical?'历史日终视图 · 按当时记录还原，不用当前任务覆盖历史。':'实时汇总 · 保存或完成任务后，可刷新查看最新结果。';
      const c=r.counts;
      const summary='<section class="dd-summary"><span class="dd-eyebrow">'+esc(r.date)+' / '+(r.historical?'DAY REVIEW':'TODAY')+'</span><h3>'+(c.pending+c.overdue?'还有 '+(c.pending+c.overdue)+' 项需要跟进':'今日应办已处理完毕')+'</h3><p>已完成 '+c.completed+' 项，今日应办未完成 '+c.pending+' 项；另外逾期 '+c.overdue+' 项。明日待办 '+c.tomorrow+' 项。</p></section>';
      const stats='<nav class="dd-stats" aria-label="日报分类">'+Object.keys(titles).map(k=>'<button type="button" data-dd-section="'+k+'" aria-pressed="'+(r.section===k)+'"><span>'+titles[k]+'</span><strong>'+Number(c[k]||0)+'</strong></button>').join('')+'</nav>';
      const team=r.user_id===0?'<details class="dd-team" open><summary>全员概览 · '+r.team.length+' 人</summary><div class="dd-team-head"><span>人员</span><span>完成</span><span>应办未完</span><span>逾期</span><span>变更</span></div>'+r.team.map(u=>'<button type="button" data-dd-person-id="'+Number(u.id)+'"><strong>'+esc(u.name)+'</strong>'+['completed','pending','overdue','changes'].map(k=>'<span>'+Number(u.counts[k]||0)+'</span>').join('')+'</button>').join('')+'</details>':'';
      const note=draft?.key===key(r)?draft.text:r.note.note;
      const supplement=r.user_id?'<section class="dd-note"><h3>本人补充 <small>未完成原因 / 需要协助</small></h3>'+(r.can_edit_note?'<textarea data-dd-note maxlength="1000" placeholder="例如：目录图片还差两张，需工程部明早提供。">'+esc(note)+'</textarea><div><span data-dd-note-status role="status">'+(dirty()?'尚未保存':'保存后管理员可查看；历史日期只读。')+'</span><button type="button" data-dd-save>保存补充</button></div>':'<p>'+esc(note||'本人未填写补充说明。')+'</p>')+'</section>':'';
      const body=$('.dd-body'),scroll=body.scrollTop;
      body.innerHTML=summary+stats+team+'<section class="dd-list"><div class="dd-section-title"><h3>'+titles[r.section]+'</h3><span>'+Number(c[r.section])+' '+(r.section==='changes'?'条记录':'项任务')+'</span></div>'+(r.items.length?r.items.map(card).join(''):'<div class="dd-empty"><span aria-hidden="true">✓</span><p>该分类暂无记录</p><small>切换上方分类查看其他事项</small></div>')+'<div class="dd-pages"><button type="button" data-dd-page="-1" '+(r.page<=1?'disabled':'')+'>上一页</button><span>'+r.page+' / '+r.pages+'</span><button type="button" data-dd-page="1" '+(r.page>=r.pages?'disabled':'')+'>下一页</button></div></section>'+supplement+'<details class="dd-method"><summary>统计说明</summary><p>完成以所选日期实际完成时间为准；应办未完成不包含更早的逾期任务。没有截止时间时按任务日期归类。暂停、待接收、待确认仍属未完成；取消不算完成。未分配给自己的他人任务不计入个人工作量。今日变更以真实入库变化为准，重复完成不会多算，读消息和拖动排序不算工作成果。</p><p>任务名称可打开详情；历史详情是当前状态，历史记录本身不会被修改。系统/外部联动表示未能取得操作账号，不猜测操作人。变更长文本只展示前400字。</p></details>';
      body.scrollTop=scroll;
      body.querySelectorAll('[data-dd-section]').forEach(b=>b.onclick=()=>load({date:r.date,user_id:r.user_id,section:b.dataset.ddSection}));
      body.querySelectorAll('[data-dd-page]').forEach(b=>b.onclick=()=>load({date:r.date,user_id:r.user_id,section:r.section,page:r.page+Number(b.dataset.ddPage)}));
      body.querySelectorAll('[data-dd-person-id]').forEach(b=>b.onclick=()=>changeScope({date:r.date,user_id:Number(b.dataset.ddPersonId)}));
      body.querySelectorAll('[data-dd-task]').forEach(b=>b.onclick=async()=>{if(saving)return;try{await env.openTask(Number(b.dataset.ddTask));}catch(e){$('.dd-status').textContent=e.message;}});
      $('[data-dd-note]')?.addEventListener('input',e=>{draft={key:key(r),text:e.target.value,version:draft?.key===key(r)?draft.version:r.note.version};$('[data-dd-note-status]').textContent='尚未保存';});
      $('[data-dd-save]')?.addEventListener('click',save);
    }
    async function save(){
      if(saving || !report?.can_edit_note)return;const r=report,button=$('[data-dd-save]'),textarea=$('[data-dd-note]'),text=textarea.value;
      const version=draft?.key===key(r)?draft.version:r.note.version;serial++;dialog.removeAttribute('aria-busy');saving=true;button.disabled=true;textarea.disabled=true;
      try{const saved=await env.api('daily_note_save',{date:r.date,user_id:r.user_id,note:text,version,csrf:r.csrf});r.note=saved;draft=null;$('[data-dd-note-status]').textContent='已保存';}
      catch(e){$('[data-dd-note-status]').textContent=e.message;}
      finally{saving=false;button.disabled=false;textarea.disabled=false;}
    }
    return {dialog,load};
  }
  root.DispatchDaily={install};
})(typeof window==='undefined'?globalThis:window);
