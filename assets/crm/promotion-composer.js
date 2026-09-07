/* Five-step promotion composer. Rendering never starts delivery. */
(function (root) {
  'use strict';
  function install(p, env) {
    var esc = env.esc, mail = env.mail;
    var original = {};
    ['defaultWizardDraft','openWizard','closeWizard','collectWizard','renderWizardStep','taskToWizardDraft','wizardTaskPayload','applyWizardTemplate'].forEach(function (key) { original[key] = p[key]; });
    var steps = ['推广方式','接收对象','内容与签名','执行安排','最终预览'];
    var descriptions = ['先选择这次如何触达客户。渠道必须已在客户或联系人资料中维护。','选择客户范围。最终预览会按最新资料列出真实接收对象和排除原因。','邮件正文与账号签名分开维护，附件上传成功后才能加入发送。','只展示可执行的设置。邮件自动发送；其他渠道由负责人手动完成。','此处由服务器生成。正式执行使用这一份内容；资料变化后必须重新核对。'];
    var ui = { step: 0, busy: false, dirty: false, preview: null, selected: 0, error: '', epoch: 0, page: 0, viewport: 'desktop' };
    var customerSearchSerial=0, customerSearchRows=[], customerNames={};
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
    function invalidate() { ui.dirty = true; ui.preview = null; ui.error = ''; }
    function collectChecks(nodes, previous) {
      if(!nodes.length)return previous;
      var checked=Array.from(nodes).filter(function(el){return el.checked;}).map(function(el){return Number(el.value);});
      return previous.map(Number).filter(function(id){return checked.indexOf(id)>=0;}).concat(checked.filter(function(id){return previous.map(Number).indexOf(id)<0;}));
    }
    function fingerprint() { return JSON.stringify(p.wizardDraft); }
    p.defaultWizardDraft = function () { return Object.assign(original.defaultWizardDraft.call(this), { client_request_id:id(), delivery_version:2, channel_key:'email', timezone_rule:'company_time', assets:[] }); };
    p.taskToWizardDraft = function (task) {
      var d = original.taskToWizardDraft.call(this,task), attach = {};
      try { attach = JSON.parse(task.attachment_config_json || '{}'); } catch (_) {}
      d.assets = attach.assets || []; d.asset_ids = attach.asset_ids || []; d.delivery_version = 2;
      d.legacyAttachmentWarning = Boolean((attach.manual_attachments || []).length || (attach.datasheet_attachments || []).length || attach.material_package);
      return d;
    };
    p.openWizard = function () {
      if (ui.busy) return;
      ui.step = 0; ui.error = ''; ui.preview = null; ui.dirty = false; ui.epoch++; ui.page = 0;
      customerSearchSerial++; p.wizardAudienceRequestSerial++;
      this.wizardAttachmentFiles = []; original.openWizard.call(this);
    };
    p.closeWizard = function (force) {
      if (ui.busy) return;
      if (!force && ui.dirty && !root.confirm('还有未保存的修改。关闭会丢弃这些修改，确定关闭？')) return;
      ui.epoch++; customerSearchSerial++; this.wizardAudienceRequestSerial++; ui.preview = null; ui.dirty = false; this.wizardAttachmentFiles = []; original.closeWizard.call(this);
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
      var accountIds=(this.wizardDraft.mail_account_ids || []).slice(),manualIds=(this.wizardDraft.offline_owner_ids || []).slice();
      var d = original.collectWizard.call(this);
      // A saved/editing draft must not silently inherit a stale pool selection.
      if(Array.isArray(customerIds))d.customer_ids=customerIds;
      if(Array.isArray(contactIds))d.contact_ids=contactIds;
      document.querySelectorAll('.pc-composer [data-wizard-field]').forEach(function(el) { d[el.dataset.wizardField] = el.value; });
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
    p.updateWizardPreview = function () { /* No full-list calculations on each keystroke. */ };
    function validation(step,d) {
      if (step === 0 && !String(d.task_name || '').trim()) return '请填写推广名称。';
      if (step === 1) {
        if (p.wizardAudienceLoading) return '客户范围还在读取中，请稍候。';
        if (p.wizardAudienceError) return p.wizardAudienceError;
        if (!(d.audience_customer_ids || []).length && !(d.customer_ids || []).length && !(d.contact_ids || []).length) return '请先选择客户或客户分组。';
      }
      if (step === 2 && !String(d.mail_body_html || '').replace(/<[^>]*>/g,'').trim() && !/<img\b/i.test(d.mail_body_html || '')) return '请填写邮件正文或人工执行话术。';
      if (step === 2 && d.channel_key === 'email' && !String(d.mail_subject || '').trim()) return '请填写邮件主题。';
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
      p.collectWizard();
      if(ui.preview.fingerprint!==fingerprint()){ui.preview=null;ui.error='内容已变化，请重新生成预览。';p.renderWizard();return;}
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
    function renderContent(d) {
      var email=d.channel_key==='email'||d.channel_key==='preference';
      return (email ? field('mail_subject','邮件主题 *',d.mail_subject,'text','可使用下方变量；缺少对应资料时会阻止发送。') : '') +
        '<label class="pc-field"><span>'+(email?'邮件正文 *':'执行话术 / 资料说明 *')+'</span></label><div class="mail-rich-toolbar" data-promo-rich-toolbar><button type="button" data-promo-rich-cmd="bold" title="加粗">加粗</button><button type="button" data-promo-rich-cmd="italic">斜体</button><button type="button" data-promo-rich-cmd="insertUnorderedList">列表</button><button type="button" data-promo-rich-link>链接</button><button type="button" data-promo-rich-image>图片</button></div><div class="pc-editor mail-rich-editor" role="textbox" aria-label="推广正文" aria-multiline="true" contenteditable="true" data-promo-wizard-editor>'+mail.prepareRichHtml(d.mail_body_html || '<p><br></p>')+'</div>'+
        '<div class="pc-variables"><span>插入变量</span>'+[['contact_name','联系人姓名'],['company_name','公司名称'],['mail_user_name','发件人姓名']].map(function(v){return '<button type="button" data-promo-rich-var="{'+v[0]+'}">'+v[1]+'</button>';}).join('')+'</div>'+
        (email ? select('signature_key','邮件签名',d.signature_key,[['personal','使用实际发件账号的签名'],['company','公司统一签名（按实际发件人替换）'],['none','明确不使用签名']],'签名在最终预览自动追加，请勿在正文重复插入。') : '')+
        '<section class="pc-attachments"><h3>附件</h3><p>先上传真实文件；最多 10 个，单个 8MB、合计 15MB。资料包和报价文件请先导出后上传。</p><label class="pc-upload">选择一个附件<input type="file" data-pc-upload accept=".pdf,.doc,.docx,.xls,.xlsx,.csv,.txt,.png,.jpg,.jpeg,.gif,.webp,.zip"></label><ul>'+(d.assets || []).map(function(a,i){return '<li><span>'+esc(a.name)+' · '+esc(mail.fileSizeText(a.size))+' · 已上传</span><button type="button" data-pc-remove="'+i+'">移除</button></li>';}).join('')+'</ul></section>';
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
      return '<div class="pc-grid">'+(email?select('mail_account_rule','发件邮箱分配',d.mail_account_rule,[['owner_mailbox','按客户负责人邮箱'],['balanced','多邮箱均分发送'],['selected_mailbox','按勾选邮箱轮流发送'],['group_by_country','按国家固定分配邮箱']],'仅使用授权邮箱；均分按邮件数量，国家分配保持同一国家使用同一邮箱。'):'')+
        select('schedule_type','开始方式',d.schedule_type,[['manual','确认后自动发送（预览后 10 分钟起）'],['scheduled','预约开始时间'],['auto','原自动执行：按预约时间开始']])+
        (['scheduled','auto'].indexOf(d.schedule_type)>=0?field('scheduled_at','预约开始时间 *',d.scheduled_at,'datetime-local','至少在两分钟以后。'):'')+
        select('timezone_rule','时间口径',d.timezone_rule,[['company_time','北京时间（UTC+8）']],'旧时区规则不会自动替换，须明确确认。')+'</div>'+
        (email && d.mail_account_rule!=='owner_mailbox'?'<section class="pc-advanced"><h3>发件邮箱池（可多选）</h3><div class="pc-check-grid">'+(pool || '<p>没有可用邮箱，请先配置。</p>')+'</div><p class="pc-note">均分不改变客户渠道。按每个实际发件账号生成签名；最终预览列出分配数量和预计完成时间。今日已发为页面加载时数据，执行时会再次限速。</p></section>':'')+
        (d.channel_key!=='email'?'<section class="pc-advanced">'+select('offline_executor_rule','人工执行人分配',d.offline_executor_rule,[['owner','客户负责人'],['creator','当前创建人'],['manual_offline_executor','勾选人员轮流分配']],'仅生成待办，不自动代发微信、WhatsApp 等。')+(d.offline_executor_rule==='manual_offline_executor'?'<div class="pc-check-grid">'+executorChecks+'</div>':'')+'</section>':'')+
        (email?'<section class="pc-advanced"><h3>发送节奏（每个邮箱独立计算）</h3><div class="pc-grid pc-grid-numbers">'+field('send_interval_minutes','每封间隔（分钟）',d.send_interval_minutes,'number')+field('hourly_limit','滚动一小时上限',d.hourly_limit,'number')+field('daily_limit','滚动 24 小时上限',d.daily_limit,'number')+'</div><p>预计结束时间在最终预览生成；已有发件负载、重试或故障可能使实际时间顺延。</p></section><details class="pc-advanced"><summary>失败重试设置</summary><div class="pc-grid">'+field('retry_count','最多重试次数',d.retry_count,'number')+field('retry_interval_minutes','重试间隔（分钟）',d.retry_interval_minutes,'number')+'</div><p>无邮箱、禁用渠道、离职和重复对象不发送；失败不会自动改用其他邮箱。</p></details>':'');
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
      var distribution='<section class="pc-advanced"><h3>执行分配与预计完成时间</h3><div class="pc-table-wrap"><table class="pc-distribution"><thead><tr><th>发件邮箱 / 人工执行人</th><th>数量</th><th>预计开始</th><th>预计完成</th></tr></thead><tbody>'+m.senders.map(function(s){return '<tr><td>'+esc(s.name)+'<small>'+esc(s.mode==='email'?'自动邮件':'人工待办')+'</small></td><td>'+s.count+'</td><td>'+esc(s.first)+'</td><td>'+esc(s.last)+'</td></tr>';}).join('')+'</tbody></table></div><p>北京时间；每邮箱间隔 '+Number(m.schedule.send_interval_minutes || 3)+' 分钟，一小时上限 '+Number(m.schedule.hourly_limit || 50)+'，24 小时上限 '+Number(m.schedule.daily_limit || 200)+'。实际发送可能因已有负载或重试顺延。</p></section>';
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
      if(ui.step===0)content='<div class="pc-grid">'+field('task_name','推广名称 *',d.task_name,'text','例如：9月新品介绍 · 印度客户')+select('channel_key','本次推广方式',d.channel_key,[['email','邮件推广 · 自动发送'],['phone','电话跟进 · 人工执行'],['wechat','微信 · 人工执行'],['whatsapp','WhatsApp · 人工执行'],['linkedin','LinkedIn · 人工执行'],['wechat_group','微信群 · 人工执行'],['whatsapp_group','WhatsApp群 · 人工执行'],['offline','线下跟进 · 人工执行'],['preference','按资料唯一渠道 · 混合执行']],'不会擅自改变客户资料中的渠道；多渠道不明确时会列为待处理。')+'</div><label class="pc-field"><span>从已有模板开始（可选）</span><select data-wizard-field="template_key">'+this.templateOptions(d)+'</select><small>模板只填充空白内容，不改变客户渠道。</small></label>'+field('remark','内部备注（可选）',d.remark);
      if(ui.step===1)content=customerPicker(d)+original.renderWizardStep.call(this,1,d)+select('contact_filter','联系人范围',d.contact_filter,[['all_valid','全部符合条件的联系人'],['primary','仅主联系人']].concat((d.contact_ids || []).length?[['selected','仅当前选中的联系人']]:[]),'最终名单以服务器根据最新资料核对的结果为准。');
      if(ui.step===2)content=renderContent(d);
      if(ui.step===2 && d.legacyAttachmentWarning)content='<div class="pc-error"><p>原草稿包含旧附件登记，不等于已上传文件。请重新上传需要携带的实际文件。</p><button type="button" data-pc-legacy-attachments>确认已重传，或本次不携带旧附件</button></div>'+content;
      if(ui.step===3)content=renderSchedule(d);
      if(ui.step===4)content=renderPreview();
      host.innerHTML='<section class="pc-composer" role="region" aria-label="新建推广任务"><header class="pc-header"><div><span class="pc-eyebrow">推广工作区</span><h2>'+esc(d.task_name || '新建推广任务')+'</h2></div><span class="pc-save-state">'+(ui.dirty?'有未保存修改':d.task_id?'草稿已保存':'未保存')+'</span><button type="button" data-pc-action="close" aria-label="关闭推广创建">关闭</button></header><div class="pc-workspace"><nav class="pc-steps" aria-label="创建步骤">'+steps.map(function(name,i){return '<button type="button" data-pc-step="'+i+'" aria-current="'+(i===ui.step?'step':'false')+'"><b>'+(i+1)+'</b><span>'+name+'</span></button>';}).join('')+'</nav><main class="pc-main"><div class="pc-heading"><span>第 '+(ui.step+1)+' 步 / 5</span><h3>'+steps[ui.step]+'</h3><p>'+descriptions[ui.step]+'</p></div><div class="pc-error" role="alert" '+(!ui.error?'hidden':'')+'>'+esc(ui.error)+'</div><div class="pc-content">'+content+'</div></main></div><footer class="pc-footer"><button type="button" data-pc-action="save">保存草稿 · 不发送</button><span class="pc-progress" role="status">'+(ui.busy?'处理中，请稍候':'')+'</span><div><button type="button" data-pc-action="prev" '+(ui.step===0?'disabled':'')+'>上一步</button>'+(ui.step<4?'<button type="button" class="primary" data-pc-action="next">'+(ui.step===3?'生成最终预览':'下一步')+'</button>':'<button type="button" class="primary" data-pc-action="confirm" disabled>确认执行</button>')+'</div></footer></section>';
      host.querySelectorAll('[data-wizard-field]').forEach(function(el){el.addEventListener('input',function(){p.collectWizard();invalidate();});el.addEventListener('change',function(){p.collectWizard();invalidate();var key=el.dataset.wizardField;if(key==='template_key')p.applyWizardTemplate();if(key==='group_mode'||key==='group_key'||key==='contact_filter'){p.wizardAudienceRequestSerial++;p.refreshWizardAudience();return;}if(['channel_key','template_key','schedule_type','mail_account_rule','offline_executor_rule'].indexOf(key)>=0)p.renderWizard();});});
      host.querySelector('[data-pc-customer-search]')?.addEventListener('click',searchCustomers);
      host.querySelector('[data-pc-customer-query]')?.addEventListener('keydown',function(e){if(e.key==='Enter'){e.preventDefault();searchCustomers();}});
      host.querySelectorAll('[data-pc-customer-remove]').forEach(function(el){el.onclick=function(){changeCustomer(Number(el.dataset.pcCustomerRemove),false);};});
      host.querySelectorAll('[data-wizard-group-check]').forEach(function(el){el.addEventListener('change',function(){p.collectWizard();invalidate();p.refreshWizardAudience();});});
      host.addEventListener('change',function(e){if(e.target.dataset.wizardField==='group_mode'){p.wizardDraft.group_key='';p.wizardDraft.group_keys=[];}},true);
      host.addEventListener('keydown',function(e){
        if(e.key==='Escape'){e.preventDefault();p.closeWizard();}
        if(e.key==='Tab'){
          var controls=Array.from(host.querySelectorAll('button:not(:disabled),input:not(:disabled),select:not(:disabled),textarea:not(:disabled),[contenteditable="true"],summary')).filter(function(el){return el.getClientRects().length;});
          var first=controls[0],last=controls[controls.length-1];
          if(e.shiftKey && document.activeElement===first){e.preventDefault();last?.focus();}
          else if(!e.shiftKey && document.activeElement===last){e.preventDefault();first?.focus();}
        }
      });
      host.querySelectorAll('[data-pc-step]').forEach(function(el){el.onclick=function(){move(Number(el.dataset.pcStep));};});
      var actions={close:function(){p.closeWizard();},save:saveDraft,prev:function(){move(ui.step-1);},next:function(){move(ui.step+1);},preview:generatePreview,confirm:confirmDelivery,test:testSend};
      host.querySelectorAll('[data-pc-action]').forEach(function(el){el.onclick=function(){actions[el.dataset.pcAction]();};});
      host.querySelectorAll('[data-pc-account],[data-pc-executor]').forEach(function(el){el.addEventListener('change',function(){p.collectWizard();invalidate();});});
      host.querySelector('[data-pc-consent]')?.addEventListener('change',function(e){host.querySelector('[data-pc-action="confirm"]').disabled=!e.target.checked || !(ui.preview.manifest.total ?? ui.preview.manifest.items.length);});
      host.querySelectorAll('[data-pc-recipient]').forEach(function(el){el.onclick=function(){if(typeof ui.preview.manifest.total==='number')return readPreviewPage({index:Number(el.dataset.pcRecipient)});ui.selected=Number(el.dataset.pcRecipient);p.renderWizard();};});
      host.querySelectorAll('[data-pc-page]').forEach(function(el){el.onclick=function(){var page=ui.page+Number(el.dataset.pcPage);if(typeof ui.preview.manifest.total==='number')return readPreviewPage({page:page,index:page*20});ui.page=page;p.renderWizard();};});
      host.querySelectorAll('[data-pc-excluded-page]').forEach(function(el){el.onclick=function(){return readPreviewPage({excluded_page:ui.preview.manifest.excluded_page+Number(el.dataset.pcExcludedPage)});};});
      host.querySelectorAll('[data-pc-viewport]').forEach(function(el){el.onclick=function(){ui.viewport=el.dataset.pcViewport;p.renderWizard();};});
      host.querySelector('[data-pc-upload]')?.addEventListener('change',function(e){if(e.target.files[0])upload(e.target.files[0]);});
      host.querySelector('[data-pc-legacy-attachments]')?.addEventListener('click',function(){if(root.confirm('旧附件登记将不参与本次发送，仅携带当前显示已上传的文件。确定？')){p.collectWizard();p.wizardDraft.legacyAttachmentWarning=false;invalidate();p.renderWizard();}});
      host.querySelectorAll('[data-pc-remove]').forEach(function(el){el.onclick=function(){p.collectWizard();p.wizardDraft.assets.splice(Number(el.dataset.pcRemove),1);invalidate();p.renderWizard();};});
      if(ui.step===2){this.bindWizardContentEditor();host.querySelector('[data-promo-wizard-editor]')?.addEventListener('input',invalidate);}
      var frame=host.querySelector('.pc-preview-frame');
      if(frame && ui.preview){var item=ui.preview.manifest.current_item || ui.preview.manifest.items[ui.selected];frame.srcdoc='<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta http-equiv="Content-Security-Policy" content="default-src \'none\'; img-src https: data:; style-src \'unsafe-inline\';"><style>body{margin:16px;font:15px/1.65 Arial,sans-serif;overflow-wrap:anywhere}img{max-width:100%;height:auto}table{max-width:100%}</style></head><body>'+mail.prepareRichHtml(item.body_html || '')+'</body></html>';}
      if(ui.busy)lock(true);
      host.querySelector('.pc-main').scrollTop = oldScroll;
      var heading=host.querySelector('.pc-heading h3');heading.tabIndex=-1;
      if(document.activeElement===document.body)heading.focus({preventScroll:true});
    };
    return { state:ui, validation:validation, generatePreview:generatePreview, confirmDelivery:confirmDelivery, move:move };
  }
  root.CrmPromotionComposer={install:install};
  if(typeof module!=='undefined' && module.exports)module.exports=root.CrmPromotionComposer;
})(typeof window==='undefined'?globalThis:window);
