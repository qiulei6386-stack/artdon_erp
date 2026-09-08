/* Five-step promotion composer. Rendering never starts delivery. */
(function (root) {
  'use strict';
  function install(p, env) {
    var esc = env.esc, mail = env.mail;
    var original = {};
    ['defaultWizardDraft','openWizard','closeWizard','collectWizard','renderWizardStep','taskToWizardDraft','wizardTaskPayload','applyWizardTemplate'].forEach(function (key) { original[key] = p[key]; });
    var steps = ['推广方式','接收对象','内容与签名','执行安排','最终预览'];
    var descriptions = ['给任务命名，并确定本次联系客户的方式。','先选客户范围，再确认需要联系哪些人。','编辑内容；签名由实际发件账号自动追加。','确定由谁执行、何时开始，以及发送速度。','逐封核对真实接收人、内容和签名，再确认执行。'];
    var stepHelp = [
      '邮件渠道自动发送；电话、微信等渠道只生成需要人工处理的待办。客户资料中的渠道必须匹配，保存草稿不会发送。',
      '全选分组覆盖所有匹配页。重复客户会去重，禁止推广或渠道不符的对象会被排除；最终人数以服务器预览为准。',
      '先把光标放在主题或正文，再点变量插入。联系人姓名需要真实联系人记录，不能用公司名代替。附件须上传成功；签名不要重复粘贴到正文。',
      '均分和轮流发送只使用你勾选且有权限的邮箱。默认预览十分钟后开始，未经确认不发送；发送负载或重试可能使完成时间顺延。',
      '此处展示服务器冻结的发送内容。修改资料、正文或规则后必须重新预览；测试邮件仅发往指定测试邮箱，确认执行才创建正式队列。'
    ];
    var ui = { step: 0, busy: false, dirty: false, preview: null, selected: 0, error: '', epoch: 0, page: 0, viewport: 'desktop' };
    var customerSearchSerial=0, customerSearchRows=[], customerNames={};
    var groupQuery='', groupPage=0, groupPageSize=12;
    function availableGroups() { return (p.data && p.data.groups || []).filter(function(row){return Number(row.id)>0;}); }
    function matchingGroups() {
      var query=groupQuery.trim().toLocaleLowerCase();
      return availableGroups().filter(function(row){return !query || String(row.group_name || '').toLocaleLowerCase().indexOf(query)>=0;});
    }
    p.wizardGroupCheckboxes=function(d) {
      var all=availableGroups(),rows=matchingGroups(),selected=new Set(p.wizardGroupKeys(d));
      var pages=Math.max(1,Math.ceil(rows.length/groupPageSize));groupPage=Math.min(groupPage,pages-1);
      var allSelected=rows.length && rows.every(function(row){return selected.has(Number(row.id));});
      return '<section class="pc-group-picker"><div class="pc-group-toolbar"><strong>客户分组</strong><span role="status">已选 '+selected.size+' 组 · 共 '+all.length+' 组</span><button type="button" data-pc-group-select="all" '+(!rows.length || allSelected?'disabled':'')+'>'+(groupQuery.trim()?'全选匹配分组':'全选全部分组')+'（'+rows.length+'）</button><button type="button" data-pc-group-select="clear" '+(!selected.size?'disabled':'')+'>清空已选</button></div><label class="pc-field"><span>搜索分组</span><input type="search" data-pc-group-query value="'+esc(groupQuery)+'" placeholder="输入分组名称"></label><p class="pc-group-help">全选包含所有匹配页，翻页不会丢失勾选。客户会去重，禁止推广对象仍会排除；最终预览后才能确认执行。</p><div class="pc-group-list">'+rows.slice(groupPage*groupPageSize,(groupPage+1)*groupPageSize).map(function(row){var id=Number(row.id);return '<label class="pc-check pc-group-row"><input type="checkbox" data-wizard-group-check value="'+id+'" '+(selected.has(id)?'checked':'')+'><span><strong>'+esc(row.group_name || ('分组 #'+id))+'</strong><small>客户 '+Number(row.customer_count || 0)+' · 联系人 '+Number(row.contact_count || 0)+' · 可推广 '+Number(row.promotable_contact_count || 0)+'</small></span></label>';}).join('')+(!rows.length?'<p>'+(all.length?'没有匹配分组，请更换关键词。':'暂无可用分组。')+'</p>':'')+'</div><div class="pc-group-pages"><span>第 '+(groupPage+1)+' / '+pages+' 页 · 匹配 '+rows.length+' 组</span><button type="button" data-pc-group-page="-1" '+(!groupPage?'disabled':'')+'>上一页</button><button type="button" data-pc-group-page="1" '+(groupPage+1>=pages?'disabled':'')+'>下一页</button></div></section>';
    };
    function selectGroups(action) {
      if(ui.busy)return;
      var d=p.collectWizard(),ids=new Set(p.wizardGroupKeys(d));
      if(action==='clear')ids.clear();else matchingGroups().forEach(function(row){ids.add(Number(row.id));});
      d.group_keys=Array.from(ids);d.group_key=d.group_keys[0]?String(d.group_keys[0]):'';
      // Sync visible checks before the legacy collector runs during refresh.
      document.querySelectorAll('.pc-composer [data-wizard-group-check]').forEach(function(el){el.checked=ids.has(Number(el.value));});
      invalidate();p.refreshWizardAudience();
    }
    function resetGroupSource(e) {
      if(e.target.dataset.wizardField!=='group_mode')return;
      p.wizardDraft.group_key='';p.wizardDraft.group_keys=[];groupQuery='';groupPage=0;
      e.currentTarget.querySelectorAll('[data-wizard-group-check]').forEach(function(el){el.checked=false;});
    }
    function composerKeydown(e) {
      if(e.key==='Escape'){e.preventDefault();p.closeWizard();}
      if(e.key==='Tab'){
        var controls=Array.from(e.currentTarget.querySelectorAll('button:not(:disabled),input:not(:disabled),select:not(:disabled),textarea:not(:disabled),[contenteditable="true"],summary')).filter(function(el){return el.getClientRects().length;});
        var first=controls[0],last=controls[controls.length-1];
        if(e.shiftKey && document.activeElement===first){e.preventDefault();last?.focus();}
        else if(!e.shiftKey && document.activeElement===last){e.preventDefault();first?.focus();}
      }
    }
    function customerPicker(d) {
      if(d.group_mode!=='selected')return '';
      var known=(p.data && p.data.pool || []);
      known.forEach(function(r){customerNames[Number(r.id)]=r.customer_name;});
      return '<section class="pc-customer-picker"><h3>直接选择客户</h3><p>无需退出向导。搜索后逐个添加；这里只改变本次推广名单，不修改客户资料。</p><div class="pc-customer-search"><label class="pc-field"><span>客户名称、代码或邮箱</span><input data-pc-customer-query placeholder="输入关键词后搜索"></label><button type="button" data-pc-customer-search>搜索客户</button></div><div data-pc-customer-results aria-live="polite"></div><ul class="pc-selected-customers">'+(d.customer_ids || []).map(function(id){return '<li><span>'+esc(customerNames[Number(id)] || ('客户 #'+id))+'</span><button type="button" data-pc-customer-remove="'+Number(id)+'">移除</button></li>';}).join('')+'</ul></section>';
    }
    async function searchCustomers() {
      var box=document.querySelector('[data-pc-customer-results]'),input=document.querySelector('[data-pc-customer-query]');
      if(!box || !input || ui.busy)return;
      var q=input.value.trim(),serial=++customerSearchSerial,epoch=ui.epoch;
      if(q.length<2){box.textContent='请输入至少两个字符。';return;}
      box.textContent='正在搜索客户…';
      try {
        var data=await request('marketing_pool_view',{q:q,page:1,page_size:20,skip_count:1});
        if(serial!==customerSearchSerial || epoch!==ui.epoch || !box.isConnected)return;
        customerSearchRows=data.pool || [];customerSearchRows.forEach(function(r){customerNames[Number(r.id)]=r.customer_name;});
        var selected=(p.wizardDraft.customer_ids || []).map(Number);
        box.innerHTML=customerSearchRows.map(function(r){var picked=selected.indexOf(Number(r.id))>=0;return '<button type="button" data-pc-customer-add="'+Number(r.id)+'" '+(picked?'disabled':'')+'><strong>'+esc(r.customer_name || ('客户 #'+r.id))+'</strong><span>'+esc([r.customer_code,r.country,r.owner_name].filter(Boolean).join(' · '))+'</span><span>'+(picked?'已添加':'添加到本次推广')+'</span></button>';}).join('') || '<p>没有匹配客户，请更换关键词。</p>';
        if(customerSearchRows.length===20)box.insertAdjacentHTML('beforeend','<p>最多显示 20 个结果，请输入更具体的关键词。</p>');
        box.querySelectorAll('[data-pc-customer-add]').forEach(function(el){el.onclick=function(){changeCustomer(Number(el.dataset.pcCustomerAdd),true);};});
      }catch(e){if(serial===customerSearchSerial && epoch===ui.epoch && box.isConnected)box.textContent=e.message;}
    }
    function changeCustomer(id,add) {
      if(ui.busy)return;
      var d=p.collectWizard(),ids=new Set((d.customer_ids || []).map(Number));
      if(add)ids.add(id);else ids.delete(id);
      d.customer_ids=Array.from(ids);d.contact_ids=[];invalidate();customerSearchSerial++;
      p.wizardAudienceRequestSerial++;p.refreshWizardAudience();
    }
    function fitViewport(){if(root.visualViewport)document.documentElement.style.setProperty('--pc-viewport-height',root.visualViewport.height+'px');}
    if(root.visualViewport)root.visualViewport.addEventListener('resize',fitViewport);
    fitViewport();
    function id() { return 'promotion_' + (root.crypto && root.crypto.randomUUID ? root.crypto.randomUUID() : Date.now() + '_' + Math.random().toString(36).slice(2)); }
    function request(action, payload) { return env.post(action,payload,{timeoutMs:60000}).then(function (json) { if (!json || !json.success) throw new Error(json && json.message || '操作失败，请重试'); return json.data || {}; }); }
    function field(name,label,value,type,hint) { return '<label class="pc-field"><span>' + esc(label) + '</span><input data-wizard-field="' + name + '" type="' + (type || 'text') + '" value="' + esc(value == null ? '' : value) + '">' + (hint ? '<small>' + esc(hint) + '</small>' : '') + '</label>'; }
    function select(name,label,value,options,hint) { if(value && !options.some(function(o){return String(o[0])===String(value);}))options=[[value,'原设置：'+value+'（请明确重新选择）']].concat(options); return '<label class="pc-field"><span>' + esc(label) + '</span><select data-wizard-field="' + name + '">' + options.map(function (o) { return '<option value="' + esc(o[0]) + '"' + (String(value) === String(o[0]) ? ' selected' : '') + '>' + esc(o[1]) + '</option>'; }).join('') + '</select>' + (hint ? '<small>' + esc(hint) + '</small>' : '') + '</label>'; }
    function invalidate() { ui.dirty = true; ui.preview = null; ui.error = ''; updateGuidance(); }
    function panel(title,subtitle,body,className) {
      return '<section class="pc-panel '+(className || '')+'"><header class="pc-panel-head"><h4>'+title+'</h4>'+(subtitle?'<p>'+subtitle+'</p>':'')+'</header>'+body+'</section>';
    }
    function stepChecks(d) {
      var email=d.channel_key==='email'||d.channel_key==='preference';
      var source=d.group_mode==='group'?p.wizardGroupKeys(d).length:d.group_mode==='country'?String(d.group_key || '').trim():d.group_mode==='all_pool'?true:(d.customer_ids || []).length || (d.contact_ids || []).length;
      var body=String(d.mail_body_html || '');
      var check=function(label,done,selector){return {label:label,done:!!done,selector:selector};};
      if(ui.step===0)return [check('推广名称',String(d.task_name || '').trim(),'[data-wizard-field="task_name"]'),check('推广方式',d.channel_key,'[data-wizard-field="channel_key"]')];
      if(ui.step===1)return [check('客户范围',source,'[data-wizard-field="group_mode"]'),check('名单已读取',!p.wizardAudienceLoading && !p.wizardAudienceError && (d.audience_customer_ids || []).length,'[data-wizard-field="group_mode"]'),check('联系人范围',d.contact_filter,'[data-wizard-field="contact_filter"]')];
      if(ui.step===2)return (email?[check('邮件主题',String(d.mail_subject || '').trim(),'[data-wizard-field="mail_subject"]')]:[]).concat([check('正文内容',body.replace(/<[^>]*>/g,'').trim() || /<img\b/i.test(body),'[data-promo-wizard-editor]')],email?[check('签名方式',['personal','company','none'].indexOf(d.signature_key)>=0,'[data-wizard-field="signature_key"]')]:[],d.legacyAttachmentWarning?[check('处理旧附件',false,'[data-pc-legacy-attachments]')]:[]);
      if(ui.step===3)return (email?[check('发件邮箱',d.mail_account_rule==='owner_mailbox' || (['balanced','selected_mailbox','group_by_country'].indexOf(d.mail_account_rule)>=0 && (d.mail_account_ids || []).length),'[data-wizard-field="mail_account_rule"]')]:[]).concat([check('开始时间',d.schedule_type==='manual' || (['scheduled','auto'].indexOf(d.schedule_type)>=0 && d.scheduled_at),'[data-wizard-field="schedule_type"]'),check('安排已检查',!validation(3,d),'[data-wizard-field="timezone_rule"]')]);
      var total=ui.preview && (ui.preview.manifest.total ?? ui.preview.manifest.items.length);
      return [check('服务器预览',ui.preview,'[data-pc-action="preview"]'),check('可执行对象',total>0,'.pc-exclusions summary'),check('勾选最后核对',document.querySelector('[data-pc-consent]')?.checked,'[data-pc-consent]')];
    }
    function guidanceHtml(d) {
      var checks=stepChecks(d),missing=checks.filter(function(c){return !c.done;});
      return '<div class="pc-guide-status"><strong>'+(ui.busy?'正在处理…':missing.length?'本步待办':'本步已填写')+'</strong><div class="pc-checkpoints">'+checks.map(function(c){return '<span class="'+(c.done?'is-ready':'is-pending')+'"><i aria-hidden="true">'+(c.done?'✓':'○')+'</i>'+c.label+'</span>';}).join('')+'</div></div>';
    }
    function updateGuidance() {
      var host=document.querySelector('.pc-composer');if(!host || !p.wizardDraft)return;
      var guide=host.querySelector('[data-pc-guidance]');if(guide)guide.innerHTML=guidanceHtml(p.wizardDraft);
      var badge=host.querySelector('.pc-save-state');if(badge){badge.textContent=ui.dirty?'未保存':p.wizardDraft.task_id?'已保存':'草稿';badge.classList.toggle('is-dirty',ui.dirty);}
      var error=host.querySelector('[data-pc-error]');if(error){error.hidden=!ui.error;error.textContent=ui.error;}
      if(!ui.error){host.querySelectorAll('[aria-invalid="true"]').forEach(function(el){el.removeAttribute('aria-invalid');el.removeAttribute('aria-errormessage');});host.querySelectorAll('.pc-field-error').forEach(function(el){el.remove();});}
    }
    function collectChecks(nodes, previous) {
      if(!nodes.length)return previous;
      var checked=Array.from(nodes).filter(function(el){return el.checked;}).map(function(el){return Number(el.value);});
      return previous.map(Number).filter(function(id){return checked.indexOf(id)>=0;}).concat(checked.filter(function(id){return previous.map(Number).indexOf(id)<0;}));
    }
    function fingerprint() { return JSON.stringify(p.wizardDraft); }
    p.defaultWizardDraft = function () { return Object.assign(original.defaultWizardDraft.call(this), { client_request_id:id(), delivery_version:2, channel_key:'email', timezone_rule:'company_time', assets:[] }); };
    p.taskToWizardDraft = function (task) {
      var d = original.taskToWizardDraft.call(this,task), attach = {}, audience = {};
      try { attach = JSON.parse(task.attachment_config_json || '{}'); } catch (_) {}
      try { audience = JSON.parse(task.audience_config_json || '{}'); } catch (_) {}
      if(audience.selection && Array.isArray(audience.selection.customer_ids) && Array.isArray(audience.selection.contact_ids)) {
        d.customer_ids=audience.selection.customer_ids.map(Number).filter(Boolean);
        d.contact_ids=audience.selection.contact_ids.map(Number).filter(Boolean);
      } else if(d.group_mode==='selected' && (audience.excluded_customers || []).length) {
        d.customer_ids=Array.from(new Set((d.customer_ids || []).concat(audience.excluded_customers.map(function(r){return Number(r.id);})).filter(Boolean)));
      }
      d.assets = attach.assets || []; d.asset_ids = attach.asset_ids || []; d.delivery_version = 2;
      d.legacyAttachmentWarning = Boolean((attach.manual_attachments || []).length || (attach.datasheet_attachments || []).length || attach.material_package);
      return d;
    };
    p.openWizard = function () {
      if (ui.busy) return;
      ui.step = 0; ui.error = ''; ui.preview = null; ui.dirty = false; ui.epoch++; ui.page = 0;
      groupQuery='';groupPage=0;
      customerSearchSerial++; p.wizardAudienceRequestSerial++;
      this.wizardAttachmentFiles = []; original.openWizard.call(this);
    };
    p.closeWizard = function (force) {
      if (ui.busy) return;
      if (!force && ui.dirty && !root.confirm('还有未保存的修改。关闭会丢弃这些修改，确定关闭？')) return;
      var savedTaskId = Number(this.wizardDraft && this.wizardDraft.task_id || 0);
      ui.epoch++; customerSearchSerial++; this.wizardAudienceRequestSerial++; ui.preview = null; ui.dirty = false; this.wizardAttachmentFiles = []; original.closeWizard.call(this);
      if(savedTaskId && (this.data && this.data.tasks || []).some(function(task){return Number(task.id)===savedTaskId;}))this.selectTask(savedTaskId);
    };
    root.addEventListener('beforeunload',function (e) { if (p.wizardDraft && (ui.dirty || ui.busy)) { e.preventDefault(); e.returnValue = ''; } });
    p.applyWizardTemplate = function () {
      var draft = this.wizardDraft, channel = draft && draft.channel_key;
      original.applyWizardTemplate.call(this);
      if (draft) { draft.channel_key = channel; draft.campaign_type = channel === 'preference' ? 'mixed' : channel; }
    };
    p.collectWizard = function () {
      var before = JSON.stringify(this.wizardDraft);
      var customerIds=this.wizardDraft && this.wizardDraft.customer_ids,contactIds=this.wizardDraft && this.wizardDraft.contact_ids;
      var groupIds=this.wizardGroupKeys(this.wizardDraft);
      var accountIds=(this.wizardDraft.mail_account_ids || []).slice(),manualIds=(this.wizardDraft.offline_owner_ids || []).slice();
      var d = original.collectWizard.call(this);
      // A saved/editing draft must not silently inherit a stale pool selection.
      if(Array.isArray(customerIds))d.customer_ids=customerIds;
      if(Array.isArray(contactIds))d.contact_ids=contactIds;
      document.querySelectorAll('.pc-composer [data-wizard-field]').forEach(function(el) { d[el.dataset.wizardField] = el.value; });
      // The old collector sees only the current page; retain off-page selections.
      if(d.group_mode==='group') {
        var groupSet=new Set(groupIds);
        document.querySelectorAll('.pc-composer [data-wizard-group-check]').forEach(function(el){var key=Number(el.value);if(el.checked)groupSet.add(key);else groupSet.delete(key);});
        d.group_keys=Array.from(groupSet);d.group_key=d.group_keys[0]?String(d.group_keys[0]):'';
      }
      var accountChecks=document.querySelectorAll('[data-pc-account]'),manualChecks=document.querySelectorAll('[data-pc-executor]');
      d.mail_account_ids=collectChecks(accountChecks,accountIds);
      d.offline_owner_ids=collectChecks(manualChecks,manualIds);
      d.client_request_id = d.client_request_id || id(); d.delivery_version = 2;
      if (before !== JSON.stringify(d)) invalidate();
      return d;
    };
    p.wizardTaskPayload = function (draft,targets,status) {
      var payload = original.wizardTaskPayload.call(this,draft,targets,'draft');
      payload.client_request_id = draft.client_request_id;
      var rules = JSON.parse(payload.send_rule); rules.delivery_version = 2;
      rules.mail_account_id = draft.mail_account_id || (draft.mail_account_ids || [])[0] || '';
      payload.send_rule = JSON.stringify(rules);
      payload.contact_ids = JSON.stringify(draft.contact_filter === 'selected' ? draft.contact_ids || [] : []);
      payload.attachment_config = JSON.stringify(Object.assign({ assets:draft.assets || [],asset_ids:(draft.assets || []).map(function (a) {return a.id;}) },draft.legacyAttachmentWarning ? {manual_attachments:draft.manual_attachments || [],datasheet_attachments:draft.datasheet_attachments || [],material_package:draft.material_package || ''}:{}));
      return payload;
    };
    // Legacy sidebar/ACTIONS entry points must also go through final preview.
    p.createTaskFromWizard = function () { ui.step = 4; this.renderWizard(); return generatePreview(); };
    p.saveDraftFromWizard = function () { return saveDraft(); };
    p.wizardNext = function () { return move(ui.step+1); };
    p.wizardPrev = function () { return move(ui.step-1); };
    p.updateWizardPreview = function () { updateGuidance(); /* Local checks only; no audience fetch on typing. */ };
    function validation(step,d) {
      if (step === 0 && !String(d.task_name || '').trim()) return '请填写推广名称。';
      if (step === 1) {
        if(d.group_mode==='group' && !p.wizardGroupKeys(d).length)return '请先选择客户分组。';
        if (p.wizardAudienceLoading) return '客户范围还在读取中，请稍候。';
        if (p.wizardAudienceError) return p.wizardAudienceError;
        if (!(d.audience_customer_ids || []).length && !(d.customer_ids || []).length && !(d.contact_ids || []).length) return '请先选择客户或客户分组。';
      }
      if (step === 2 && !String(d.mail_body_html || '').replace(/<[^>]*>/g,'').trim() && !/<img\b/i.test(d.mail_body_html || '')) return '请填写邮件正文或人工执行话术。';
      if (step === 2 && (d.channel_key === 'email'||d.channel_key === 'preference') && !String(d.mail_subject || '').trim()) return '请填写邮件主题。';
      if (step === 2 && (d.channel_key === 'email'||d.channel_key === 'preference') && ['personal','company','none'].indexOf(d.signature_key)<0) return '请选择邮件签名方式。';
      if (step === 2 && d.legacyAttachmentWarning) return '旧草稿包含附件登记，请重新上传真实文件并确认旧登记的处理方式。';
      if (step === 3 && ['scheduled','auto'].indexOf(d.schedule_type)>=0 && !d.scheduled_at) return '请选择预约开始时间。';
      if (step === 3) {
        if(d.timezone_rule!=='company_time')return '原时区规则未修改，请明确选择北京时间后重新核对安排。';
        if(['manual','scheduled','auto'].indexOf(d.schedule_type)<0)return '请明确选择开始方式，原设置未自动替换。';
        if((d.channel_key==='email'||d.channel_key==='preference') && ['owner_mailbox','selected_mailbox','balanced','group_by_country'].indexOf(d.mail_account_rule)<0)return '请选择当前支持的发件规则，原规则未自动替换。';
        if((d.channel_key==='email'||d.channel_key==='preference') && d.mail_account_rule!=='owner_mailbox' && !(d.mail_account_ids || []).length)return '请勾选至少一个发件邮箱。';
        if(d.channel_key!=='email' && d.offline_executor_rule==='manual_offline_executor' && !(d.offline_owner_ids || []).length)return '请勾选人工执行人。';
        var limits=[['send_interval_minutes','发送间隔',1,240],['hourly_limit','每小时上限',1,500],['daily_limit','每日上限',1,3000],['retry_count','重试次数',0,5],['retry_interval_minutes','重试间隔',5,1440]];
        for(var j=0;j<limits.length;j++){var spec=limits[j],value=Number(d[spec[0]]);if(!Number.isInteger(value)||value<spec[2]||value>spec[3])return spec[1]+'须为 '+spec[2]+'–'+spec[3]+' 的整数。';}
      }
      return '';
    }
    function move(next) {
      if (ui.busy) return;
      var d = p.collectWizard();
      if (next > ui.step) { for (var i=0;i<next;i++) {var error = validation(i,d); if (error) {ui.step=i;ui.error=error;p.renderWizard();return;}} }
      ui.step = Math.max(0,Math.min(4,next)); ui.error = ''; p.renderWizard();
      if (ui.step === 4 && !ui.preview) return generatePreview();
    }
    function lock(busy) { ui.busy = busy; var host=document.querySelector('.pc-composer'); if (host) {host.setAttribute('aria-busy',String(busy));var status=host.querySelector('.pc-progress');if(status)status.textContent=busy?'处理中，请稍候…':'';host.querySelectorAll('button,input,select,textarea,[contenteditable]').forEach(function (el) { if (el.hasAttribute('contenteditable')) el.contentEditable=busy?'false':'true'; else el.disabled=busy; });} }
    async function saveCore() {
      var d = p.collectWizard(); p.applyWizardTemplate();
      var data = await request('marketing_task_create',p.wizardTaskPayload(d,{customers:[],contacts:[],chat_groups:[],skipped:[]},'draft'));
      if(Array.isArray(data.tasks)){p.data=p.data || {};p.data.tasks=data.tasks;}
      d.task_id = Number(data.task_id); p.wizardDraft = d; p.selectedTaskId=d.task_id; ui.dirty=false;
      return d;
    }
    async function saveDraft() {
      if (ui.busy) return; lock(true);
      try {await saveCore(); ui.error=''; env.toast('草稿已保存，不会发送。');}
      catch(e) {ui.error=e.message;}
      finally {lock(false);p.renderWizard();}
    }
    async function generatePreview() {
      if (ui.busy) return;
      var d=p.collectWizard(); for(var i=0;i<4;i++) {var message=validation(i,d);if(message){ui.step=i;ui.error=message;p.renderWizard();return;}}
      ui.preview=null;ui.error='';ui.step=4;p.renderWizard();lock(true);
      try { d=await saveCore(); var result=await request('marketing_delivery_preview',{task_id:d.task_id,preview_format:'paged-v1'}); ui.preview=result;ui.preview.fingerprint=fingerprint();ui.selected=0;ui.page=0; }
      catch(e){ui.error=e.message;}
      finally{lock(false);p.renderWizard();}
    }
    async function confirmDelivery() {
      if(ui.busy || !ui.preview) return;
      var checkedPreview=ui.preview;
      p.collectWizard();
      if(!ui.preview || checkedPreview.fingerprint!==fingerprint()){ui.preview=null;ui.error='内容已变化，请重新生成预览。';p.renderWizard();return;}
      var manifest=ui.preview.manifest, emails=manifest.email_count ?? manifest.items.filter(function(x){return x.mode==='email';}).length;
      if(!root.confirm('确认执行：'+emails+' 封邮件、'+(manifest.manual_count ?? manifest.items.length-emails)+' 条人工待办。邮件将在预览时间到达后自动发送；排除 '+(manifest.excluded_total ?? manifest.excluded.length)+' 个对象。'))return;
      lock(true);
      try {var result=await request('marketing_delivery_confirm',{token:ui.preview.token});p.selectedTaskId=result.task_id;ui.dirty=false;lock(false);p.closeWizard(true);p.switchView('campaigns');await p.load();env.toast('已确认执行，请在项目中查看实际发送进度；入队不代表发送成功。');}
      catch(e){ui.error=e.message;lock(false);p.renderWizard();}
    }
    async function upload(file) {
      if(ui.busy)return;
      if(file.size<1 || file.size>8*1024*1024){ui.error='单个附件须为 1 字节至 8MB。';p.renderWizard();return;}
      var d=p.collectWizard();if((d.assets || []).length>=10){ui.error='最多添加 10 个附件。';p.renderWizard();return;}
      lock(true);
      var controller = new AbortController(), timer = root.setTimeout(function(){controller.abort();},60000);
      try {var form=new FormData();form.set('action','marketing_delivery_upload');form.set('csrf_token',env.state.csrf || '');form.set('file',file);
        var response=await fetch('crm_api.php',{method:'POST',body:form,credentials:'same-origin',signal:controller.signal});var json=await response.json();if(!json.success)throw new Error(json.message || '附件上传失败');
        d.assets=(d.assets || []).concat([json.data]);p.wizardDraft=d;invalidate();
      }catch(e){ui.error=e.name==='AbortError'?'附件上传超时，请重试；未确认上传的文件不会发送。':e.message;}finally{root.clearTimeout(timer);lock(false);p.renderWizard();}
    }
    async function testSend() {
      if(ui.busy || !ui.preview)return;
      var box=document.querySelector('[data-pc-test-email]'), email=(box && box.value || '').trim();
      if(!email){ui.error='请填写测试收件邮箱。';p.renderWizard();return;}
      if(!root.confirm('只向 '+email+' 发送当前预览邮件（含附件），不启动正式推广。'))return;
      p.previewTestEmail=email;lock(true);
      try{await request('marketing_delivery_test',{token:ui.preview.token,index:ui.selected,test_email:email});env.toast('测试邮件已发送，请核对收件内容和附件。');ui.error='';}
      catch(e){ui.error=e.message;}finally{lock(false);p.renderWizard();}
    }
    function renderBasics(d) {
      return panel('任务信息','必填项标有 *，其他内容可稍后补充。',
        '<div class="pc-grid">'+field('task_name','推广名称 *',d.task_name,'text','例如：9月新品介绍 · 印度客户')+
        select('channel_key','推广方式 *',d.channel_key,[['email','邮件 · 自动发送'],['phone','电话 · 人工执行'],['wechat','微信 · 人工执行'],['whatsapp','WhatsApp · 人工执行'],['linkedin','LinkedIn · 人工执行'],['wechat_group','微信群 · 人工执行'],['whatsapp_group','WhatsApp群 · 人工执行'],['offline','线下跟进 · 人工执行'],['preference','按资料唯一渠道 · 混合执行']],'必须与客户或联系人已维护的渠道匹配。')+
        '<label class="pc-field"><span>使用模板 <small>可选</small></span><select data-wizard-field="template_key">'+p.templateOptions(d)+'</select><small>只填充空白内容，不覆盖已填写的渠道。</small></label>'+field('remark','内部备注 · 可选',d.remark)+'</div><div class="pc-inline-note"><strong>草稿不会发送</strong><span>选择对象 → 编辑内容 → 安排时间 → 最终核对，确认后才会执行。</span></div>','pc-basic-panel');
    }
    function renderAudience(d) {
      var customers=p.resolveWizardAudienceCustomers(d),count=Number(d.audience_customer_count || 0) || customers.length;
      var source=select('group_mode','客户来源 *',d.group_mode,[['selected','直接选择客户'],['group','按客户分组'],['all_pool','当前推广池筛选结果'],['country','按国家']]);
      var contacts=select('contact_filter','联系人范围',d.contact_filter,[['all_valid','全部符合条件的联系人'],['primary','仅主联系人']].concat((d.contact_ids || []).length?[['selected','仅当前选中的联系人']]:[]));
      var picker=d.group_mode==='group'?p.wizardGroupCheckboxes(d):d.group_mode==='selected'?customerPicker(d):d.group_mode==='country'?field('group_key','国家 *',d.group_key,'text','输入国家，例如 China / India'):'<p class="pc-inline-note">使用进入向导时推广池的筛选条件，最终名单仍会由服务器复核。</p>';
      var status=p.wizardAudienceLoading?'正在读取客户范围…':p.wizardAudienceError?'读取失败：'+p.wizardAudienceError:'已读取 '+count+' 个客户；可执行数量以最终预览为准。';
      return panel('选择接收范围','', '<div class="pc-grid">'+source+contacts+'</div>'+picker+'<p class="pc-audience-status" role="status">'+esc(status)+'</p>')+
        '<section class="promo-step-customers pc-audience-details"><details class="pc-advanced"><summary>查看范围统计</summary><div class="promo-step-dist-grid">'+p.renderWizardDistribution('国家',p.wizardTopCounts(customers,function(r){return r.country;}))+p.renderWizardDistribution('负责人',p.wizardTopCounts(customers,function(r){return r.owner_name || r.primary_owner;}))+p.renderWizardDistribution('来源',p.wizardTopCounts(customers,function(r){return r.source_tags || r.source;}))+'</div></details>'+p.renderWizardCustomerRows(customers)+'</section>';
    }
    function renderContent(d) {
      var email=d.channel_key==='email'||d.channel_key==='preference';
      var body=(email ? field('mail_subject','邮件主题 *',d.mail_subject,'text','可插入联系人、公司或发件人变量。') : '') +
        '<label class="pc-field"><span>'+(email?'邮件正文 *':'执行话术 / 资料说明 *')+'</span></label><div class="mail-rich-toolbar" data-promo-rich-toolbar><button type="button" data-promo-rich-cmd="bold" title="加粗">加粗</button><button type="button" data-promo-rich-cmd="italic">斜体</button><button type="button" data-promo-rich-cmd="insertUnorderedList">列表</button><button type="button" data-promo-rich-link>链接</button><button type="button" data-promo-rich-image>图片</button></div><div class="pc-editor mail-rich-editor" role="textbox" aria-label="推广正文" aria-multiline="true" contenteditable="true" data-promo-wizard-editor>'+mail.prepareRichHtml(d.mail_body_html || '<p><br></p>')+'</div>'+
        '<div class="pc-variables"><span data-promo-variable-target aria-live="polite">插入到正文</span>'+[['contact_name','联系人姓名'],['company_name','公司名称'],['mail_user_name','发件人姓名']].map(function(v){return '<button type="button" data-promo-rich-var="{'+v[0]+'}" title="'+esc('{'+v[0]+'}')+'">'+v[1]+'</button>';}).join('')+'</div><details class="pc-field-help"><summary>变量怎样使用？</summary><p>先点主题或正文的插入位置，再点变量。{变量名} 会在最终预览替换为真实资料；联系人姓名必须有联系人记录。</p></details>';
      var signature=email?panel('发件签名','自动追加在正文末尾，不需要点击插入。',select('signature_key','签名方式',d.signature_key,[['personal','实际发件账号签名'],['company','公司统一签名'],['none','不使用签名']])+'<div data-pc-signature-tools>'+signatureTools(d)+'</div>'):'';
      var attachments=panel('附件 <span class="pc-count">'+(d.assets || []).length+'/10</span>','单个 8MB，合计 15MB。',
        '<label class="pc-upload"><span>＋ 上传附件</span><input aria-label="上传推广附件" type="file" data-pc-upload accept=".pdf,.doc,.docx,.xls,.xlsx,.csv,.txt,.png,.jpg,.jpeg,.gif,.webp,.zip"></label><ul>'+(d.assets || []).map(function(a,i){return '<li><span><strong>'+esc(a.name)+'</strong><small>'+esc(mail.fileSizeText(a.size))+' · 已上传</small></span><button type="button" aria-label="移除 '+esc(a.name)+'" data-pc-remove="'+i+'">移除</button></li>';}).join('')+'</ul><p class="pc-muted">资料包、报价文件请先导出，再上传实际文件。</p>','pc-attachments');
      return '<div class="pc-compose-grid">'+panel(email?'邮件内容':'执行内容','',body,'pc-message-panel')+'<aside class="pc-content-side">'+signature+attachments+'</aside></div>';
    }
    function signatureTools(d) {
      if(d.signature_key==='none')return '<p class="pc-muted">本次邮件不会自动追加签名；正文保持原样。</p>';
      var ids=(d.mail_account_ids || []).map(Number),chosen=ids.length===1?ids[0]:0;
      return '<label class="pc-field"><span>检查哪个邮箱的签名</span><select data-pc-signature-account><option value="">请选择邮箱</option>'+(p.data && p.data.mail_accounts || []).map(function(a){return '<option value="'+Number(a.id)+'" '+(chosen===Number(a.id)?'selected':'')+'>'+esc(a.email_address || '')+'</option>';}).join('')+'</select></label><button type="button" data-pc-signature-check>查看签名并检查资料</button><p class="pc-muted">这里只检查效果，不改变发件安排。多邮箱发送请逐个检查；最终预览按实际账号追加签名。</p><div data-pc-signature-result role="status" aria-live="polite"></div>';
    }
    function bindSignatureTools(host) {
      var tools=host.querySelector('[data-pc-signature-tools]');
      if(!tools)return;
      var version=0;
      function clear(){version++;var box=tools.querySelector('[data-pc-signature-result]');if(box)box.replaceChildren();var button=tools.querySelector('[data-pc-signature-check]');if(button){button.disabled=false;button.textContent='查看签名并检查资料';}}
      function bind(){
        tools.querySelector('[data-pc-signature-account]')?.addEventListener('change',clear);
        tools.querySelector('[data-pc-signature-check]')?.addEventListener('click',async function(){
          var button=this,box=tools.querySelector('[data-pc-signature-result]'),id=Number(tools.querySelector('[data-pc-signature-account]').value),key=p.collectWizard().signature_key;
          clear();if(!id){box.textContent='请先选择要检查的邮箱。';return;}
          var serial=++version,epoch=ui.epoch;button.disabled=true;button.textContent='正在读取签名…';box.textContent='正在检查最新签名及人员资料…';
          try{
            var data=await request('marketing_signature_content',{signature_key:key,mail_account_id:id,preview:1});
            if(serial!==version || epoch!==ui.epoch || !box.isConnected)return;
            box.innerHTML='<p class="'+(data.ready?'pc-muted':'pc-error')+'">'+esc(data.ready?'签名资料检查通过。':'签名尚不能发送：'+(data.missing || []).join('、')+' 缺失。请在人员资料补齐，或在邮箱设置修改签名后重新检查。')+'</p>'+
              ((data.recipient_variables || []).length?'<p class="pc-muted">'+esc(data.recipient_variables.join('、'))+' 将在最终预览按收件对象替换。</p>':'')+'<iframe class="pc-signature-frame" title="当前邮箱签名检查预览" sandbox="" referrerpolicy="no-referrer"></iframe>';
            box.querySelector('iframe').srcdoc=previewDocument(data.preview_html || '');
          }catch(e){if(serial===version && epoch===ui.epoch && box.isConnected)box.textContent=e.message;}
          finally{if(serial===version && box.isConnected){button.disabled=false;button.textContent='重新检查签名';}}
        });
      }
      host.querySelector('[data-wizard-field="signature_key"]')?.addEventListener('change',function(){clear();tools.innerHTML=signatureTools(p.collectWizard());bind();});
      bind();
    }
    function previewDocument(html) {
      return '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta http-equiv="Content-Security-Policy" content="default-src \'none\'; img-src https: data:; style-src \'unsafe-inline\';"><style>body{margin:12px;font:14px/1.6 Arial,sans-serif;overflow-wrap:anywhere}img{max-width:100%;height:auto}table{max-width:100%}</style></head><body>'+mail.prepareRichHtml(html)+'</body></html>';
    }
    function renderSchedule(d) {
      var email=d.channel_key==='email'||d.channel_key==='preference';
      var accounts=p.data && p.data.mail_accounts || [],users=p.data && p.data.users || [];
      var ids=(d.mail_account_ids || []).map(Number),manualIds=(d.offline_owner_ids || []).map(Number);
      var pool=accounts.map(function(a){return '<label class="pc-check"><input type="checkbox" data-pc-account value="'+Number(a.id)+'" '+(ids.indexOf(Number(a.id))>=0?'checked':'')+'><span>'+esc(a.email_address || a.email)+'<small>'+esc(a.owner_name || '')+' · 今日已发 '+Number(a.today_sent || 0)+'</small></span></label>';}).join('');
      var missing=ids.filter(function(id){return !accounts.some(function(a){return Number(a.id)===id;});});
      missing.forEach(function(id){pool+='<label class="pc-check"><input type="checkbox" data-pc-account value="'+id+'" checked><span>原邮箱 #'+id+' 当前不可用，请明确取消选择</span></label>';});
      var executorChecks=users.map(function(u){return '<label class="pc-check"><input type="checkbox" data-pc-executor value="'+Number(u.id)+'" '+(manualIds.indexOf(Number(u.id))>=0?'checked':'')+'><span>'+esc(u.display_name || u.real_name || u.username || ('#'+u.id))+'</span></label>';}).join('');
      manualIds.filter(function(id){return !users.some(function(u){return Number(u.id)===id;});}).forEach(function(id){executorChecks+='<label class="pc-check"><input type="checkbox" data-pc-executor value="'+id+'" checked><span>原执行人 #'+id+' 当前不可用，请取消选择</span></label>';});
      var who=(email?select('mail_account_rule','发件邮箱分配 *',d.mail_account_rule,[['owner_mailbox','按客户负责人邮箱'],['balanced','多邮箱均分发送'],['selected_mailbox','按勾选邮箱轮流发送'],['group_by_country','按国家固定分配邮箱']]):'')+
        (email && d.mail_account_rule!=='owner_mailbox'?'<div class="pc-subheading"><strong>勾选发件邮箱</strong><span>已选 '+ids.length+' 个</span></div><div class="pc-check-grid">'+(pool || '<p>没有可用邮箱，请先配置。</p>')+'</div><p class="pc-muted">仅在所选授权邮箱内分配，各封邮件使用实际账号签名。今日已发为加载时数据。</p>':email?'<div class="pc-inline-note"><span>按每个客户的负责人查找授权邮箱。缺少邮箱时列出原因，不擅自换账号。</span></div>':'')+
        (d.channel_key!=='email'?select('offline_executor_rule','人工执行人',d.offline_executor_rule,[['owner','客户负责人'],['creator','当前创建人'],['manual_offline_executor','勾选人员轮流分配']],'生成待办，不自动代发微信等消息。')+(d.offline_executor_rule==='manual_offline_executor'?'<div class="pc-check-grid">'+executorChecks+'</div>':''):'');
      var when='<div class="pc-grid">'+select('schedule_type','开始方式',d.schedule_type,[['manual','预览后 10 分钟起 · 须先确认'],['scheduled','预约开始时间'],['auto','原自动执行 · 按预约时间']])+
        select('timezone_rule','时间口径',d.timezone_rule,[['company_time','北京时间（UTC+8）']])+'</div>'+
        (['scheduled','auto'].indexOf(d.schedule_type)>=0?field('scheduled_at','预约开始时间 *',d.scheduled_at,'datetime-local','至少在两分钟以后。'):'')+
        (email?'<div class="pc-subheading"><strong>发送节奏</strong><span>每个邮箱独立计算</span></div><div class="pc-grid pc-grid-numbers">'+field('send_interval_minutes','间隔 / 分钟',d.send_interval_minutes,'number')+field('hourly_limit','每小时上限',d.hourly_limit,'number')+field('daily_limit','24 小时上限',d.daily_limit,'number')+'</div><p class="pc-muted">最终预览展示预计完成时间；负载、重试或故障可能使实际时间顺延。</p><details class="pc-field-help"><summary>失败重试设置</summary><div class="pc-grid">'+field('retry_count','最多重试次数',d.retry_count,'number')+field('retry_interval_minutes','重试间隔 / 分钟',d.retry_interval_minutes,'number')+'</div><p>禁止推广、离职或无邮箱对象不发送，失败不自动换账号。</p></details>':'<p class="pc-muted">预约时间用于人工待办的计划安排，不代表已经联系客户。</p>');
      return '<div class="pc-schedule-grid">'+panel('01 · 谁来执行','选择分配方式，明确执行范围。',who)+panel('02 · 时间与节奏','确认后才按此安排进入执行。',when)+'</div>';
    }
    async function readPreviewPage(values) {
      if(ui.busy || !ui.preview)return;
      var preview=ui.preview,epoch=ui.epoch;
      lock(true);
      try {
        var result=await request('marketing_delivery_preview_read',Object.assign({token:preview.token,page:preview.manifest.page,index:ui.selected,excluded_page:preview.manifest.excluded_page},values));
        if(epoch!==ui.epoch || ui.preview!==preview)return;
        result.fingerprint=preview.fingerprint;ui.preview=result;ui.selected=result.manifest.selected_index;ui.page=result.manifest.page;ui.error='';
      }catch(e){ui.error=e.message;}finally{lock(false);p.renderWizard();}
    }
    function renderPagedPreview(m) {
      var item=m.current_item;
      var distribution='<details class="pc-advanced pc-distribution-details"><summary>查看执行分配与预计完成时间 · '+m.senders.length+' 个发件账号 / 执行人</summary><div class="pc-table-wrap"><table class="pc-distribution"><thead><tr><th>发件邮箱 / 人工执行人</th><th>数量</th><th>预计开始</th><th>预计完成</th></tr></thead><tbody>'+m.senders.map(function(s){return '<tr><td>'+esc(s.name)+'<small>'+esc(s.mode==='email'?'自动邮件':'人工待办')+'</small></td><td>'+s.count+'</td><td>'+esc(s.first)+'</td><td>'+esc(s.last)+'</td></tr>';}).join('')+'</tbody></table></div><p>北京时间；每邮箱间隔 '+Number(m.schedule.send_interval_minutes || 3)+' 分钟，一小时上限 '+Number(m.schedule.hourly_limit || 50)+'，24 小时上限 '+Number(m.schedule.daily_limit || 200)+'。实际发送可能因已有负载或重试顺延。</p></details>';
      var body=item?'<dl><div><dt>接收对象</dt><dd>'+esc(item.receiver_email || item.contact_method)+'</dd></div><div><dt>发件账号 / 执行人</dt><dd>'+esc(item.sender_email || item.executor_name)+'</dd></div><div><dt>预计时间</dt><dd>'+esc(item.planned_at)+'</dd></div><div><dt>主题</dt><dd>'+esc(item.subject || '人工执行话术')+'</dd></div></dl><div class="pc-preview-switch"><button type="button" data-pc-viewport="desktop">电脑预览</button><button type="button" data-pc-viewport="mobile">手机预览</button></div><iframe title="最终正文与签名预览" sandbox="" referrerpolicy="no-referrer" class="pc-preview-frame '+(ui.viewport==='mobile'?'is-mobile':'')+'"></iframe><p>附件：'+esc(m.attachments.map(function(a){return a.name;}).join('、') || '无')+'</p>'+(item.mode==='email'?'<div class="pc-test"><label>测试收件邮箱<input type="email" data-pc-test-email value="'+esc(p.previewTestEmail || '')+'" placeholder="输入自己的测试邮箱"></label><button type="button" data-pc-action="test">仅发送当前测试邮件</button></div>':''):'<p>没有可执行对象，请查看排除原因。</p>';
      return '<div class="pc-preview-summary"><strong>'+m.email_count+' 封邮件</strong><span>'+m.manual_count+' 条人工待办</span><span>'+m.excluded_total+' 个排除对象</span><button type="button" data-pc-action="preview">重新核对</button></div>'+distribution+
        '<div class="pc-preview-layout"><section class="pc-recipient-list" aria-label="执行对象">'+m.items.map(function(r){return '<button type="button" data-pc-recipient="'+r.index+'" class="'+(r.index===ui.selected?'is-selected':'')+'"><strong>'+esc(r.customer_name)+'</strong><span>'+esc(r.receiver_email || r.contact_method || '')+'</span><small>'+esc(r.sender_email || r.executor_name)+'</small></button>';}).join('')+'<div class="pc-pager"><button type="button" data-pc-page="-1" '+(!m.page?'disabled':'')+'>上一页</button><span>'+(m.page+1)+' / '+Math.max(1,Math.ceil(m.total/20))+'</span><button type="button" data-pc-page="1" '+((m.page+1)*20>=m.total?'disabled':'')+'>下一页</button></div></section><section class="pc-mail-preview">'+body+'</section></div>'+
        '<details class="pc-exclusions" '+(!m.total || m.excluded_page?'open':'')+'><summary>排除对象与原因（'+m.excluded_total+'）</summary>'+m.excluded.map(function(r){return '<p><strong>'+esc(r.customer_name)+' / '+esc(r.contact_name || '客户')+'</strong>：'+esc(r.reason)+'</p>';}).join('')+'<div class="pc-pager"><button type="button" data-pc-excluded-page="-1" '+(!m.excluded_page?'disabled':'')+'>上一页</button><span>'+(m.excluded_page+1)+' / '+Math.max(1,Math.ceil(m.excluded_total/20))+'</span><button type="button" data-pc-excluded-page="1" '+((m.excluded_page+1)*20>=m.excluded_total?'disabled':'')+'>下一页</button></div></details><label class="pc-consent"><input type="checkbox" data-pc-consent>已核对对象、渠道、邮箱分配、内容、签名、附件和时间；确认后将自动发送。</label>';
    }
    function renderPreview() {
      if(!ui.preview)return '<section class="pc-empty"><h3>发送前最后核对</h3><p>生成预览会先保存草稿，不发送邮件。请核对每个接收对象、发件账号、正文、签名和附件。</p><button type="button" data-pc-action="preview">'+(ui.busy?'正在生成预览…':'生成最终预览')+'</button></section>';
      if(typeof ui.preview.manifest.total==='number')return renderPagedPreview(ui.preview.manifest);
      var m=ui.preview.manifest, rows=m.items || [], item=rows[ui.selected], start=ui.page*20;
      return '<div class="pc-preview-summary"><strong>'+rows.filter(function(x){return x.mode==='email';}).length+' 封邮件</strong><span>'+rows.filter(function(x){return x.mode==='manual';}).length+' 条人工待办</span><span>'+m.excluded.length+' 个排除对象</span><button type="button" data-pc-action="preview">重新核对</button></div>'+
        '<p class="pc-note">时间均为北京时间。修改资料或内容后须重新预览；以下名单中的邮件将在确认后按时间自动发送。</p><div class="pc-preview-layout"><section class="pc-recipient-list" aria-label="执行对象">'+rows.slice(start,start+20).map(function(r,i){return '<button type="button" data-pc-recipient="'+(start+i)+'" class="'+(start+i===ui.selected?'is-selected':'')+'"><strong>'+esc(r.customer_name)+'</strong><span>'+esc(r.contact_name || '客户公共联系方式')+'</span><span>'+esc(r.receiver_email || r.contact_method || '')+'</span><small>'+esc(r.mode==='email'?'自动邮件':r.channel+' · 人工执行')+'</small></button>';}).join('')+'<div class="pc-pager"><button type="button" data-pc-page="-1" '+(ui.page<=0?'disabled':'')+'>上一页</button><span>'+(ui.page+1)+' / '+Math.max(1,Math.ceil(rows.length/20))+'</span><button type="button" data-pc-page="1" '+(start+20>=rows.length?'disabled':'')+'>下一页</button></div></section><section class="pc-mail-preview">'+(item ? '<dl><div><dt>接收对象</dt><dd>'+esc(item.receiver_email || item.contact_method)+'</dd></div><div><dt>发件账号 / 执行人</dt><dd>'+esc(item.sender_email || ('负责人 #'+item.executor_id))+'</dd></div><div><dt>开始时间</dt><dd>'+esc(item.planned_at)+'</dd></div><div><dt>主题</dt><dd>'+esc(item.subject || '人工执行话术')+'</dd></div></dl><div class="pc-preview-switch"><button type="button" data-pc-viewport="desktop" aria-pressed="'+(ui.viewport==='desktop')+'">电脑预览</button><button type="button" data-pc-viewport="mobile" aria-pressed="'+(ui.viewport==='mobile')+'">手机预览</button></div><iframe title="最终正文与签名预览" sandbox="" referrerpolicy="no-referrer" class="pc-preview-frame '+(ui.viewport==='mobile'?'is-mobile':'')+'"></iframe><p>附件：'+esc(m.attachments.map(function(a){return a.name;}).join('、') || '无')+'</p>'+(item.mode==='email'?'<div class="pc-test"><label>测试收件邮箱<input type="email" data-pc-test-email value="'+esc(p.previewTestEmail || '')+'" placeholder="输入自己的测试邮箱"></label><button type="button" data-pc-action="test">仅发送当前测试邮件</button></div>':'') : '<p>暂无可执行对象，请处理以下排除原因。</p>')+'</section></div>'+
        '<details class="pc-exclusions"'+(!rows.length?' open':'')+'><summary>排除对象与原因（'+m.excluded.length+'）</summary>'+m.excluded.slice(0,200).map(function(r){return '<p><strong>'+esc(r.customer_name)+' / '+esc(r.contact_name || '客户')+'</strong>：'+esc(r.reason)+'</p>';}).join('')+(m.excluded.length>200?'<p>页面展示前 200 条；请缩小分组逐批核对。</p>':'')+'</details><label class="pc-consent"><input type="checkbox" data-pc-consent>我已核对接收对象、渠道、内容、签名、附件和时间，了解确认后邮件会自动发送。</label>';
    }
    p.renderWizard = function () {
      var host=document.querySelector('[data-promo-wizard-host]');if(!host)return;
      var oldScroll = ui.renderedStep === ui.step ? (host.querySelector('.pc-main')?.scrollTop || 0) : 0;
      ui.renderedStep = ui.step;
      var d=this.wizardDraft || this.defaultWizardDraft();this.wizardDraft=d;
      this.wizardStep=[0,1,4,6,8][ui.step];
      var content='';
      if(ui.step===0)content=renderBasics(d);
      if(ui.step===1)content=renderAudience(d);
      if(ui.step===2)content=renderContent(d);
      if(ui.step===2 && d.legacyAttachmentWarning)content='<div class="pc-error"><p>原草稿包含旧附件登记，不等于已上传文件。请重新上传需要携带的实际文件。</p><button type="button" data-pc-legacy-attachments>确认已重传，或本次不携带旧附件</button></div>'+content;
      if(ui.step===3)content=renderSchedule(d);
      if(ui.step===4)content=renderPreview();
      host.innerHTML='<section class="pc-composer" data-step="'+ui.step+'" role="region" aria-label="新建推广任务"><header class="pc-header"><div class="pc-brand-mark" aria-hidden="true">↗</div><div class="pc-title"><span class="pc-eyebrow">客户推广 / 新建任务</span><h2 title="'+esc(d.task_name || '新建推广任务')+'">'+esc(d.task_name || '新建推广任务')+'</h2></div><span class="pc-save-state"></span><button type="button" data-pc-action="close" aria-label="关闭推广创建">关闭</button></header><div class="pc-workspace"><nav class="pc-steps" aria-label="创建步骤">'+steps.map(function(name,i){var done=i<ui.step&&!validation(i,d);return '<button type="button" data-pc-step="'+i+'" aria-label="第 '+(i+1)+' 步：'+name+(done?'，已填写':'')+'" aria-current="'+(i===ui.step?'step':'false')+'" class="'+(done?'is-complete':'')+'"><b aria-hidden="true">'+(done?'✓':i+1)+'</b><span class="pc-step-label">'+name+'</span><span class="pc-step-short">'+['方式','对象','内容','安排','核对'][i]+'</span></button>';}).join('')+'</nav><main class="pc-main"><div class="pc-inner"><div class="pc-heading"><h3>'+steps[ui.step]+'</h3><p>'+descriptions[ui.step]+'</p></div><section class="pc-guide" aria-label="本步操作提示"><div data-pc-guidance aria-live="polite"></div><details class="pc-step-help"><summary>填写说明</summary><p>'+stepHelp[ui.step]+'</p></details></section><div class="pc-error" data-pc-error role="alert" '+(!ui.error?'hidden':'')+'>'+esc(ui.error)+'</div><div class="pc-content">'+content+'</div></div></main></div><footer class="pc-footer"><button type="button" data-pc-action="save">保存草稿</button><span class="pc-progress" role="status">'+(ui.busy?'处理中，请稍候':ui.step<4?'保存不发送 · 下一步：'+steps[ui.step+1]:'确认执行才会创建正式队列')+'</span><div><button type="button" data-pc-action="prev" '+(ui.step===0?'disabled':'')+'>上一步</button>'+(ui.step<4?'<button type="button" class="primary" data-pc-action="next">'+(ui.step===3?'生成最终预览':'下一步 <span aria-hidden="true">→</span>')+'</button>':'<button type="button" class="primary" data-pc-action="confirm" disabled>确认执行</button>')+'</div></footer></section>';
      host.querySelectorAll('.promo-step-customers .promo-step-table').forEach(function(table){
        var labels=Array.from(table.querySelectorAll('thead th')).map(function(th){return th.textContent;});
        table.querySelectorAll('tbody tr').forEach(function(row){Array.from(row.cells).forEach(function(cell,i){if(cell.colSpan===1)cell.dataset.label=labels[i] || '';});});
      });
      host.querySelectorAll('[data-wizard-field]').forEach(function(el){el.addEventListener('input',function(){p.collectWizard();invalidate();});el.addEventListener('change',function(){p.collectWizard();invalidate();var key=el.dataset.wizardField;if(key==='template_key')p.applyWizardTemplate();if(key==='group_mode'||key==='group_key'||key==='contact_filter'){p.wizardAudienceRequestSerial++;p.refreshWizardAudience();return;}if(['channel_key','template_key','schedule_type','mail_account_rule','offline_executor_rule'].indexOf(key)>=0)p.renderWizard();});});
      host.querySelector('[data-pc-customer-search]')?.addEventListener('click',searchCustomers);
      host.querySelector('[data-pc-customer-query]')?.addEventListener('keydown',function(e){if(e.key==='Enter'){e.preventDefault();searchCustomers();}});
      host.querySelectorAll('[data-pc-customer-remove]').forEach(function(el){el.onclick=function(){changeCustomer(Number(el.dataset.pcCustomerRemove),false);};});
      host.querySelectorAll('[data-wizard-group-check]').forEach(function(el){el.addEventListener('change',function(){p.collectWizard();invalidate();p.refreshWizardAudience();});});
      host.querySelectorAll('[data-pc-group-select]').forEach(function(el){el.onclick=function(){selectGroups(el.dataset.pcGroupSelect);};});
      function filterGroups(e) {
        if(e.isComposing)return;
        var start=e.target.selectionStart,end=e.target.selectionEnd;
        p.collectWizard();groupQuery=e.target.value;groupPage=0;p.renderWizard();
        var input=host.querySelector('[data-pc-group-query]');
        if(input){input.focus({preventScroll:true});input.setSelectionRange(start,end);}
      }
      host.querySelector('[data-pc-group-query]')?.addEventListener('input',filterGroups);
      host.querySelector('[data-pc-group-query]')?.addEventListener('compositionend',filterGroups);
      host.querySelectorAll('[data-pc-group-page]').forEach(function(el){el.onclick=function(){
        p.collectWizard();groupPage+=Number(el.dataset.pcGroupPage);p.renderWizard();
        host.querySelector('.pc-group-picker')?.scrollIntoView({block:'start'});
        host.querySelector('[data-pc-group-query]')?.focus({preventScroll:true});
      };});
      host.removeEventListener('change',resetGroupSource,true);host.addEventListener('change',resetGroupSource,true);
      host.removeEventListener('keydown',composerKeydown);host.addEventListener('keydown',composerKeydown);
      host.querySelectorAll('[data-pc-step]').forEach(function(el){el.onclick=function(){move(Number(el.dataset.pcStep));};});
      var actions={close:function(){p.closeWizard();},save:saveDraft,prev:function(){move(ui.step-1);},next:function(){move(ui.step+1);},preview:generatePreview,confirm:confirmDelivery,test:testSend};
      host.querySelectorAll('[data-pc-action]').forEach(function(el){el.onclick=function(){actions[el.dataset.pcAction]();};});
      host.querySelectorAll('[data-pc-account],[data-pc-executor]').forEach(function(el){el.addEventListener('change',function(){p.collectWizard();invalidate();});});
      host.querySelector('[data-pc-consent]')?.addEventListener('change',function(e){host.querySelector('[data-pc-action="confirm"]').disabled=!e.target.checked || !(ui.preview.manifest.total ?? ui.preview.manifest.items.length);updateGuidance();});
      host.querySelectorAll('[data-pc-recipient]').forEach(function(el){el.onclick=function(){if(typeof ui.preview.manifest.total==='number')return readPreviewPage({index:Number(el.dataset.pcRecipient)});ui.selected=Number(el.dataset.pcRecipient);p.renderWizard();};});
      host.querySelectorAll('[data-pc-page]').forEach(function(el){el.onclick=function(){var page=ui.page+Number(el.dataset.pcPage);if(typeof ui.preview.manifest.total==='number')return readPreviewPage({page:page,index:page*20});ui.page=page;p.renderWizard();};});
      host.querySelectorAll('[data-pc-excluded-page]').forEach(function(el){el.onclick=function(){return readPreviewPage({excluded_page:ui.preview.manifest.excluded_page+Number(el.dataset.pcExcludedPage)});};});
      host.querySelectorAll('[data-pc-viewport]').forEach(function(el){el.setAttribute('aria-pressed',String(el.dataset.pcViewport===ui.viewport));el.onclick=function(){ui.viewport=el.dataset.pcViewport;p.renderWizard();};});
      host.querySelector('[data-pc-upload]')?.addEventListener('change',function(e){if(e.target.files[0])upload(e.target.files[0]);});
      host.querySelector('[data-pc-legacy-attachments]')?.addEventListener('click',function(){if(root.confirm('旧附件登记将不参与本次发送，仅携带当前显示已上传的文件。确定？')){p.collectWizard();p.wizardDraft.legacyAttachmentWarning=false;invalidate();p.renderWizard();}});
      host.querySelectorAll('[data-pc-remove]').forEach(function(el){el.onclick=function(){p.collectWizard();p.wizardDraft.assets.splice(Number(el.dataset.pcRemove),1);invalidate();p.renderWizard();};});
      if(ui.step===2){this.bindWizardContentEditor();bindSignatureTools(host);host.querySelector('[data-promo-wizard-editor]')?.addEventListener('input',invalidate);}
      var frame=host.querySelector('.pc-preview-frame');
      if(frame && ui.preview){var item=ui.preview.manifest.current_item || ui.preview.manifest.items[ui.selected];frame.srcdoc='<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta http-equiv="Content-Security-Policy" content="default-src \'none\'; img-src https: data:; style-src \'unsafe-inline\';"><style>body{margin:16px;font:15px/1.65 Arial,sans-serif;overflow-wrap:anywhere}img{max-width:100%;height:auto}table{max-width:100%}</style></head><body>'+mail.prepareRichHtml(item.body_html || '')+'</body></html>';}
      if(ui.busy)lock(true);
      host.querySelector('.pc-main').scrollTop = oldScroll;
      var heading=host.querySelector('.pc-heading h3');heading.tabIndex=-1;
      if(document.activeElement===document.body)heading.focus({preventScroll:true});
      updateGuidance();
      if(ui.error && ui.error===validation(ui.step,d)) {
        var issue=stepChecks(d).find(function(c){return !c.done;}),selector=issue && issue.selector;
        [['发送间隔','send_interval_minutes'],['每小时上限','hourly_limit'],['每日上限','daily_limit'],['重试次数','retry_count'],['重试间隔','retry_interval_minutes'],['预约开始','scheduled_at'],['邮件主题','mail_subject'],['正文','data-promo-wizard-editor']].forEach(function(pair){if(ui.error.indexOf(pair[0])>=0)selector=pair[1]==='data-promo-wizard-editor'?'[data-promo-wizard-editor]':'[data-wizard-field="'+pair[1]+'"]';});
        var control=selector && host.querySelector(selector);
        if(control){control.setAttribute('aria-invalid','true');control.setAttribute('aria-errormessage','pc-field-error');control.insertAdjacentHTML('afterend','<small class="pc-field-error" id="pc-field-error">'+esc(ui.error)+'</small>');var detail=control.closest('details');if(detail)detail.open=true;control.focus({preventScroll:true});control.scrollIntoView({block:'center'});}
      }
    };
    return { state:ui, validation:validation, generatePreview:generatePreview, confirmDelivery:confirmDelivery, move:move };
  }
  root.CrmPromotionComposer={install:install};
  if(typeof module!=='undefined' && module.exports)module.exports=root.CrmPromotionComposer;
})(typeof window==='undefined'?globalThis:window);
