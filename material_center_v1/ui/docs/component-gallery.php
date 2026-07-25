<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/bootstrap.php';
header('Content-Type: text/html; charset=utf-8');
header('X-Content-Type-Options: nosniff');
?>
<!doctype html>
<html lang="zh-CN" data-theme="system">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Artdon UI 组件展厅</title>
  <link rel="stylesheet" href="../index.css">
  <style>
    .gallery{max-width:1100px;margin:auto;padding:24px}.gallery-head{display:flex;justify-content:space-between;gap:16px;align-items:center;margin-bottom:20px}
    .gallery-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px}.gallery .ui-card-body{display:grid;gap:14px}
    .demo-row{display:flex;flex-wrap:wrap;gap:8px;align-items:center}.demo-form{display:grid;grid-template-columns:1fr 1fr;gap:12px}
    @media(max-width:720px){.gallery-grid,.demo-form{grid-template-columns:1fr}}
  </style>
</head>
<body>
<main class="gallery">
  <header class="gallery-head"><div><span class="ui-eyebrow">ARTDON DESIGN SYSTEM</span><h1>物料中心 UI 组件展厅</h1><p class="ui-muted">统一组件的真实交互与状态基准。</p></div><a class="ui-btn ui-btn-secondary" href="../../">返回物料中心</a></header>
  <div class="gallery-grid">
    <section class="ui-card"><div class="ui-card-header"><strong>按钮与徽章</strong><span class="ui-badge">基础</span></div><div class="ui-card-body"><div class="demo-row"><button class="ui-btn">主要按钮</button><button class="ui-btn ui-btn-secondary">次要按钮</button><button class="ui-btn ui-btn-ghost">文字按钮</button><button class="ui-btn ui-btn-danger">危险按钮</button><button class="ui-btn" disabled>禁用</button></div><div class="demo-row"><span class="ui-badge">信息</span><span class="ui-badge ui-badge-success">已启用</span><button class="ui-btn ui-btn-icon ui-tooltip" data-tooltip="这是工具提示" aria-label="提示">?</button></div></div></section>
    <section class="ui-card"><div class="ui-card-header"><strong>表单控件</strong></div><div class="ui-card-body demo-form"><label class="ui-field"><span class="ui-label">物料名称</span><input class="ui-input" value="铝型材"><small class="ui-help">输入框帮助文字</small></label><label class="ui-field"><span class="ui-label">分类</span><select class="ui-select"><option>型材</option><option>灯珠</option></select></label><label class="ui-check"><input type="checkbox" checked>复选框</label><label class="ui-check"><input type="radio" name="demo" checked>单选框</label><label class="ui-switch"><input type="checkbox"><span class="ui-switch-track"></span><span>开关</span></label></div></section>
    <section class="ui-card"><div class="ui-card-header"><strong>浮层与反馈</strong></div><div class="ui-card-body"><div class="demo-row"><button class="ui-btn" data-ui-modal-open="#gallery-modal">打开弹窗</button><button class="ui-btn ui-btn-secondary" data-ui-drawer-open="#gallery-drawer">打开抽屉</button><button class="ui-btn ui-btn-secondary" onclick="ArtdonUI.toast('操作已完成','success')">成功提示</button><div class="ui-dropdown"><button class="ui-btn ui-btn-secondary" aria-expanded="false" aria-controls="gallery-menu" data-ui-dropdown-trigger>下拉菜单</button><div class="ui-menu" id="gallery-menu" aria-hidden="true"><button>查看详情</button><button>复制编号</button></div></div></div></div></section>
    <section class="ui-card"><div class="ui-card-header"><strong>页签与页面状态</strong></div><div class="ui-card-body"><div class="ui-tabs" role="tablist"><button class="ui-tab" aria-selected="true">基础信息</button><button class="ui-tab" aria-selected="false">属性</button><button class="ui-tab" aria-selected="false">历史</button></div><div class="ui-state"><div class="ui-state-inner"><div class="ui-state-icon">0</div><h2>暂无数据</h2><p>创建内容后会显示在这里。</p><button class="ui-btn">开始创建</button></div></div></div></section>
  </div>
</main>
<div class="ui-modal" id="gallery-modal" role="dialog" aria-modal="true" aria-hidden="true" tabindex="-1"><div class="ui-modal-header"><strong>确认操作</strong><button class="ui-btn ui-btn-secondary ui-btn-icon" data-ui-close aria-label="关闭">×</button></div><div class="ui-modal-body">这是统一弹窗组件，支持 Esc、遮罩关闭和焦点返回。</div><div class="ui-modal-footer"><button class="ui-btn ui-btn-secondary" data-ui-close>取消</button><button class="ui-btn" data-ui-close>确认</button></div></div>
<aside class="ui-drawer" id="gallery-drawer" role="dialog" aria-modal="true" aria-hidden="true" tabindex="-1"><div class="ui-drawer-header"><h2>统一抽屉</h2><button class="ui-btn ui-btn-secondary ui-btn-icon" data-ui-close aria-label="关闭">×</button></div><div class="ui-drawer-body"><p>抽屉与弹窗共享 InteractionManager。</p></div><div class="ui-drawer-footer">Artdon UI</div></aside>
<div class="ui-mask" data-ui-mask></div><div class="ui-toast-region" data-ui-toast-region role="status" aria-live="polite"></div>
<script src="../js/interaction-manager.js"></script><script src="../js/dropdown.js"></script><script src="../js/modal.js"></script><script src="../js/drawer.js"></script><script src="../js/toast.js"></script><script src="../js/app-shell.js"></script>
</body></html>
