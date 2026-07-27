<?php
/* ARTDON_SSO_GATE_V2_START */
require_once __DIR__ . '/includes/artdon_sso_core.php';
artdon_sso_require_page('quote');
/* ARTDON_SSO_GATE_V2_END */
?><!doctype html>
<html lang="zh-CN">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>人工修正报价关键元器件</title>
  <style>
    :root{--red:#d60000;--red-dark:#b00000;--ink:#111827;--muted:#667085;--line:#d8dee9;--bg:#f4f6fa;--card:#fff;--warn:#b45309;--warn-bg:#fff7ed}
    *{box-sizing:border-box}
    body{margin:0;background:var(--bg);color:var(--ink);font-family:-apple-system,BlinkMacSystemFont,"Segoe UI","Microsoft YaHei",Arial,sans-serif}
    button,input,textarea{font:inherit}
    .top{min-height:72px;padding:14px 24px;background:#111827;color:#fff;display:flex;gap:14px;align-items:center}
    .top h1{font-size:22px;margin:0}.top p{margin:4px 0 0;color:#cbd5e1;font-size:13px}
    .top-actions{margin-left:auto;display:flex;gap:8px}
    button,.link-btn{border:1px solid var(--line);border-radius:10px;background:#fff;color:var(--ink);padding:10px 15px;font-weight:800;cursor:pointer;text-decoration:none}
    button:hover,.link-btn:hover{border-color:#9ca3af}.primary{background:var(--red);border-color:var(--red);color:#fff}.primary:hover{background:var(--red-dark);border-color:var(--red-dark)}
    button:disabled{opacity:.55;cursor:not-allowed}
    main{width:min(1180px,calc(100% - 28px));margin:22px auto 40px}
    .notice{border:1px solid #fecaca;background:#fff1f2;color:#991b1b;border-radius:14px;padding:13px 16px;line-height:1.55;margin-bottom:14px}
    .notice b{display:block;margin-bottom:3px}.notice.warn{border-color:#fed7aa;background:var(--warn-bg);color:var(--warn)}
    .card{background:var(--card);border:1px solid var(--line);border-radius:16px;box-shadow:0 7px 25px rgba(15,23,42,.06);overflow:hidden}
    .card-head{padding:16px 18px;border-bottom:1px solid var(--line);display:flex;justify-content:space-between;gap:14px;align-items:center}
    .card-head h2{font-size:18px;margin:0}.meta{font-size:13px;color:var(--muted);margin-top:4px}
    .badge{display:inline-flex;align-items:center;border-radius:999px;padding:5px 9px;background:#f3f4f6;color:#475467;font-size:12px;font-weight:900}
    .badge.manual{background:#ecfdf5;color:#047857}.badge.auto{background:#eff6ff;color:#1d4ed8}
    .body{padding:18px}.grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:13px}
    .components{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:13px;margin-top:17px}
    label{display:block;font-size:13px;font-weight:800;color:#344054}
    input,textarea{width:100%;border:1px solid #cbd5e1;border-radius:10px;background:#fff;color:var(--ink);padding:10px 11px;margin-top:6px;outline:none}
    input:focus,textarea:focus{border-color:var(--red);box-shadow:0 0 0 3px rgba(214,0,0,.09)}
    input[readonly]{background:#f8fafc;color:#475467}
    textarea{min-height:82px;resize:vertical;line-height:1.5}
    .field-head{display:flex;justify-content:space-between;align-items:center;gap:8px}
    .clear{border:0;background:transparent;color:#b42318;padding:0;font-size:12px}
    .wide{grid-column:1/-1}.section-title{font-size:15px;margin:20px 0 0;padding-top:18px;border-top:1px solid #eef1f5}
    .hint{font-size:12px;color:var(--muted);line-height:1.5;margin-top:5px}
    .actions{display:flex;justify-content:flex-end;align-items:center;gap:9px;margin-top:20px;padding-top:16px;border-top:1px solid #eef1f5}
    #status{margin-right:auto;color:var(--muted);font-size:13px;font-weight:700}
    #status.ok{color:#047857}#status.err{color:#b42318}
    @media(max-width:820px){.grid,.components{grid-template-columns:1fr}.wide{grid-column:auto}.top{align-items:flex-start}.top-actions{margin-left:0}.top{flex-wrap:wrap}}
  </style>
</head>
<body>
  <header class="top">
    <div>
      <h1>人工修正报价关键元器件</h1>
      <p>修正后立即成为人工维护记录，系统后续不会自动覆盖。</p>
    </div>
    <div class="top-actions">
      <a class="link-btn" href="quotation.php">返回报价系统</a>
      <button type="button" onclick="window.close()">关闭本页</button>
    </div>
  </header>
  <main>
    <section class="notice">
      <b>填写规则</b>
      Accessories 只填写客户需要在报价单上看到或选择的附件，例如蜂巢网、玻璃、防眩罩；装配螺丝、螺母、垫圈等内部紧固件不应填写。
    </section>
    <section id="legacyWarning" class="notice warn" hidden>
      <b>发现旧版自动误判</b>
      当前 Accessories 是内部紧固件。新版报价已停止显示它；请核对后清空并保存，即可把本产品固定为人工正确记录。
    </section>
    <section class="card">
      <div class="card-head">
        <div><h2 id="title">正在读取产品...</h2><div id="meta" class="meta"></div></div>
        <span id="sourceBadge" class="badge">读取中</span>
      </div>
      <div class="body">
        <div class="grid">
          <label>产品型号<input id="product_model" readonly></label>
          <label>命名系统 ID<input id="naming_id" readonly></label>
          <label>产品名称<input id="product_name"></label>
          <label>功率<input id="power" placeholder="如 20W"></label>
          <label>尺寸<input id="size" placeholder="如 Φ100×H135mm"></label>
          <label>开孔<input id="cutout" placeholder="明装产品可留空"></label>
        </div>

        <h3 class="section-title">报价关键元器件</h3>
        <div class="hint">留空表示该项目不在报价单 Specification 中显示。每项只保留客户需要识别的品牌、型号或附件名称。</div>
        <div class="components">
          <label><span class="field-head"><span>LED / 芯片</span><button class="clear" type="button" data-clear="led">清空</button></span><textarea id="led"></textarea></label>
          <label><span class="field-head"><span>LED Driver / 电源</span><button class="clear" type="button" data-clear="driver">清空</button></span><textarea id="driver"></textarea></label>
          <label><span class="field-head"><span>Optic / 光学</span><button class="clear" type="button" data-clear="optic">清空</button></span><textarea id="optic"></textarea></label>
          <label><span class="field-head"><span>Adapter / 接头</span><button class="clear" type="button" data-clear="connector">清空</button></span><textarea id="connector"></textarea></label>
          <label><span class="field-head"><span>Accessories / 客户可选附件</span><button class="clear" type="button" data-clear="accessories">清空</button></span><textarea id="accessories"></textarea></label>
          <label><span class="field-head"><span>Other / 其他</span><button class="clear" type="button" data-clear="other">清空</button></span><textarea id="other"></textarea></label>
          <label class="wide">修正说明<textarea id="note" placeholder="例如：移除内部装配螺丝，仅保留客户可选附件。"></textarea></label>
        </div>
        <input id="product_image" type="hidden">
        <div class="actions">
          <span id="status">正在读取...</span>
          <button id="syncBtn" type="button">重新从 BOM 提取</button>
          <button id="saveBtn" class="primary" type="button">保存人工修正</button>
          <button id="saveCloseBtn" class="primary" type="button">保存并关闭</button>
        </div>
      </div>
    </section>
  </main>
  <script>
    const $=id=>document.getElementById(id);
    const params=new URLSearchParams(location.search);
    const model=(params.get('model')||'').trim();
    const namingId=(params.get('naming_id')||'').trim();
    const fields=['naming_id','product_model','product_name','product_image','power','size','cutout','led','driver','optic','accessories','connector','other','note'];
    let current={};

    function setStatus(text,kind=''){const el=$('status');el.textContent=text;el.className=kind}
    function setBusy(busy){['syncBtn','saveBtn','saveCloseBtn'].forEach(id=>$(id).disabled=busy)}
    function isInternalFastener(text){return /螺丝|螺钉|螺栓|螺母|机牙|介机牙|自攻|牙条|紧固件|平垫|弹垫|垫圈|\bscrews?\b|\bbolts?\b|\bnuts?\b|\bwashers?\b|\bfasteners?\b/i.test(String(text||''))}
    function redirectLogin(){const back=location.pathname+location.search;location.replace('login.php?redirect='+encodeURIComponent(back))}
    async function api(action,data=null){
      const res=await fetch('quote_api.php?action='+action,{method:data?'POST':'GET',headers:{'Content-Type':'application/json'},credentials:'same-origin',cache:'no-store',body:data?JSON.stringify(data):null});
      const text=await res.text();let json;
      try{json=JSON.parse(text)}catch(e){throw new Error('接口返回异常：'+text.slice(0,160))}
      if(res.status===401||json.auth_required||json.login_required||json.need_login){redirectLogin();throw new Error('请先登录')}
      if(!json.ok)throw new Error(json.msg||json.error||'请求失败');
      return json.data;
    }
    function fill(row){
      current=row||{};
      fields.forEach(key=>{const el=$(key);if(el)el.value=current[key]||((key==='product_model')?model:(key==='naming_id'?namingId:''))});
      const actualModel=$('product_model').value||model;
      $('title').textContent=actualModel?actualModel+' 报价关键件':'未指定产品';
      $('meta').textContent=(current.product_name||'未填写产品名称')+(current.updated_at?' ｜ 最近更新 '+current.updated_at:'');
      const manual=current.id&&Number(current.auto_generated||0)===0;
      $('sourceBadge').textContent=current.id?(manual?'人工维护':'BOM 自动提取'):'尚未建立';
      $('sourceBadge').className='badge '+(manual?'manual':'auto');
      $('legacyWarning').hidden=!isInternalFastener(current.accessories||'');
    }
    async function load(){
      if(!model&&!namingId){setStatus('缺少产品型号，无法读取。','err');setBusy(true);return}
      setBusy(true);setStatus('正在读取...');
      try{
        const data=await api('get_bom_quote_spec&product_model='+encodeURIComponent(model)+'&naming_id='+encodeURIComponent(namingId));
        fill(data.spec||{product_model:model,naming_id:namingId});
        setStatus(data.spec?'已读取当前关键件。':'暂无记录，可直接填写后保存。','ok');
      }catch(e){setStatus(e.message||'读取失败','err')}
      finally{setBusy(false)}
    }
    function formData(){
      const data={};fields.forEach(key=>data[key]=($(key)?.value||'').trim());
      data.product_model=data.product_model||model;
      data.naming_id=data.naming_id||namingId;
      return data;
    }
    function notifyOpener(spec){
      try{if(window.opener&&!window.opener.closed)window.opener.postMessage({type:'artdon:quote-spec-saved',spec},location.origin)}catch(e){}
    }
    async function save(closeAfter=false){
      const data=formData();if(!data.product_model){setStatus('产品型号不能为空。','err');return}
      setBusy(true);setStatus('正在保存...');
      try{
        const result=await api('save_bom_quote_spec',data);
        fill(result.row||data);notifyOpener(result.spec||null);
        setStatus('人工修正已保存，报价页面已同步。','ok');
        if(closeAfter)setTimeout(()=>window.close(),350);
      }catch(e){setStatus(e.message||'保存失败','err')}
      finally{setBusy(false)}
    }
    async function syncBom(){
      if(!confirm('重新提取会覆盖当前表单内容。确认按最新 BOM 重新识别吗？'))return;
      const data=formData();setBusy(true);setStatus('正在从 BOM 重新提取...');
      try{
        const result=await api('sync_bom_quote_spec',{force:1,product:{source:'naming',naming_id:data.naming_id,code:data.product_model,model:data.product_model,name:data.product_name,image:data.product_image,power:data.power,size:data.size,cutout:data.cutout}});
        await load();
        notifyOpener(result.spec||null);
        setStatus('已按新版规则重新提取；内部紧固件不会进入 Accessories。','ok');
      }catch(e){setStatus(e.message||'重新提取失败','err');setBusy(false)}
    }
    document.querySelectorAll('[data-clear]').forEach(btn=>btn.addEventListener('click',()=>{const el=$(btn.dataset.clear);if(el){el.value='';el.focus()}}));
    $('saveBtn').addEventListener('click',()=>save(false));
    $('saveCloseBtn').addEventListener('click',()=>save(true));
    $('syncBtn').addEventListener('click',syncBom);
    load();
  </script>
</body>
</html>
