<?php
declare(strict_types=1);
require_once dirname(__DIR__,2).'/bootstrap.php';
use Artdon\CommercialCenter\Adapters\LegacyAuthAdapter;
$auth=(new LegacyAuthAdapter())->currentUser();
header('Content-Type: text/html; charset=utf-8');header('Cache-Control: no-store');header('X-Content-Type-Options: nosniff');header('X-Frame-Options: SAMEORIGIN');
?><!doctype html><html lang="zh-CN"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>极简产品配置器</title><link rel="stylesheet" href="<?=cc_public_path('/modules/configurator/configurator.css')?>"></head><body>
<main class="configurator" data-configurator data-authenticated="<?=$auth['authenticated']?'1':'0'?>">
 <header><div><span>CONFIGURATION ENGINE V1</span><h1>极简产品配置器</h1><p>选择产品 → 套用预设 → 调整少量配置 → 服务端检查 → 加入报价</p></div><div class="mode-switch" role="group"><button data-mode="quick" class="active">快速模式</button><button data-mode="professional">专业模式</button><button data-mode="custom">定制模式</button></div></header>
 <?php if(!$auth['authenticated']):?><section class="notice">需要现有 Artdon 统一登录。当前未读取产品、成本或客户配置。</section><?php else:?>
 <div class="layout">
  <section class="editor">
   <div class="step"><b>1</b><label>选择产品<select data-product><option value="">请选择产品</option></select></label><label>数量<input data-quantity type="number" min=".001" step=".001" value="1"></label></div>
   <div class="step presets"><b>2</b><div><label>配置预设</label><div data-presets class="preset-grid"></div></div></div>
   <div class="step"><b>3</b><div class="grow"><div class="section-title"><strong>关键配置</strong><button type="button" data-reset>恢复产品标准配置</button></div><div data-key-groups class="group-grid"></div><details><summary>高级配置</summary><div data-advanced-groups class="group-grid advanced"></div></details></div></div>
   <div class="actions"><button type="button" data-customer-last>套用客户上一单</button><button type="button" data-save-personal>保存个人预设</button><button type="button" data-save-customer>保存客户预设</button><button type="button" data-copy>复制当前配置</button><button type="button" class="primary" data-add-quote>加入报价</button></div>
   <section class="compare"><div class="section-title"><strong>A / B / C 方案比较</strong><small>经济版 · 标准版 · 高配版</small></div><div data-comparison class="comparison-grid"></div></section>
  </section>
  <aside class="passport"><div class="passport-head"><div><span>CONFIGURATION PASSPORT</span><h2>配置护照</h2></div><i data-status-light></i></div><dl><div><dt>产品</dt><dd data-passport-product>未选择</dd></div><div><dt>预设</dt><dd data-passport-preset>—</dd></div><div><dt>配置状态</dt><dd data-passport-status>等待配置</dd></div><div><dt>成本</dt><dd data-cost>—</dd></div><div><dt>建议售价</dt><dd data-suggested>—</dd></div><div><dt>当前售价</dt><dd><input data-current-price type="number" min="0" step=".0001"></dd></div><div><dt>毛利率</dt><dd data-margin>—</dd></div><div><dt>MOQ</dt><dd data-moq>—</dd></div><div><dt>交期</dt><dd data-lead>—</dd></div><div><dt>审批</dt><dd data-approval>—</dd></div></dl><div data-messages class="messages"></div><div class="summary"><b>配置摘要</b><p data-summary>请选择产品。</p><code data-hash></code></div><div data-feedback class="feedback"></div></aside>
 </div><?php endif;?>
</main><?php if($auth['authenticated']):?><script src="<?=cc_public_path('/modules/configurator/configurator.js')?>" defer></script><?php endif;?></body></html>
