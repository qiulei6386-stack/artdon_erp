<?php
use Artdon\CommercialCenter\Services\CatalogCenterService;

$pcCatalog = (new CatalogCenterService())->products($view['auth'], '', '', 1, 20);
$pcProducts = $pcCatalog['rows'] ?? [];
$requestedProductId = max(0, (int)($_GET['product_id'] ?? 0));
$pcProduct = $pcProducts[0] ?? [];
foreach ($pcProducts as $candidate) {
    if ((int)($candidate['id'] ?? 0) === $requestedProductId) {
        $pcProduct = $candidate;
        break;
    }
}
$pcImage = !empty($pcProduct['image_path']) ? cc_legacy_asset_url($pcProduct['image_path']) : '';
$pcBase = max(0, round((float)($pcProduct['bom_cost'] ?? 0) * 1.35, 2));
if ($pcBase <= 0) $pcBase = 36.80;
$pcOptions = [
    ['key'=>'chip','icon'=>'⌘','name'=>'芯片 / 光源','note'=>'影响亮度与显色性','required'=>true,'items'=>[
        ['code'=>'bridgelux','name'=>'Bridgelux','sub'=>'BXRV-1816','delta'=>0,'default'=>true],
        ['code'=>'citizen','name'=>'Citizen','sub'=>'CLU048-1212','delta'=>3.2],
        ['code'=>'cree','name'=>'CREE','sub'=>'CXB1304-30E','delta'=>4.6],
        ['code'=>'osram','name'=>'Osram','sub'=>'Duris S 8','delta'=>2.8],
    ]],
    ['key'=>'driver','icon'=>'▣','name'=>'电源 / 驱动','note'=>'影响驱动与质保','required'=>true,'items'=>[
        ['code'=>'lifud','name'=>'LIFUD','sub'=>'LF-GIR012YS','delta'=>0,'default'=>true],
        ['code'=>'boke','name'=>'BOKE','sub'=>'BK-D12025','delta'=>2.4],
        ['code'=>'tridonic','name'=>'TRIDONIC','sub'=>'LCA 12W 250-700','delta'=>7.8],
        ['code'=>'eaglerise','name'=>'EAGLERISE','sub'=>'LS-12-300','delta'=>3.5],
    ]],
    ['key'=>'dimming','icon'=>'◉','name'=>'调光方式','note'=>'可选调光控制方式','items'=>[
        ['code'=>'onoff','name'=>'ON/OFF','sub'=>'不调光','delta'=>0,'default'=>true],
        ['code'=>'triac','name'=>'TRIAC','sub'=>'前切调光','delta'=>3],
        ['code'=>'010v','name'=>'0–10V','sub'=>'模拟调光','delta'=>4.5],
        ['code'=>'dali','name'=>'DALI','sub'=>'数字调光','delta'=>8],
    ]],
    ['key'=>'optics','icon'=>'✂','name'=>'光学 / 透镜','note'=>'影响光束角与效果','required'=>true,'items'=>[
        ['code'=>'24d','name'=>'24°','sub'=>'窄光','delta'=>0,'default'=>true],
        ['code'=>'36d','name'=>'36°','sub'=>'中光','delta'=>0],
        ['code'=>'60d','name'=>'60°','sub'=>'宽光','delta'=>1.2],
        ['code'=>'15d','name'=>'15°','sub'=>'超窄光','delta'=>2.4],
    ]],
    ['key'=>'accessory','icon'=>'▧','name'=>'附件 / 配件','note'=>'可多选','multiple'=>true,'items'=>[
        ['code'=>'honeycomb','name'=>'蜂巢网','sub'=>'Honeycomb','delta'=>1.2,'default'=>true],
        ['code'=>'glass','name'=>'防眩玻璃','sub'=>'Anti-glare','delta'=>1.8],
        ['code'=>'barndoor','name'=>'四叶片','sub'=>'Barndoor','delta'=>2.4],
    ]],
    ['key'=>'color','icon'=>'◈','name'=>'外观颜色','note'=>'可选颜色','items'=>[
        ['code'=>'white','name'=>'白色','sub'=>'White','delta'=>0,'default'=>true],
        ['code'=>'black','name'=>'黑色','sub'=>'Black','delta'=>0],
        ['code'=>'custom','name'=>'定制色','sub'=>'Custom','delta'=>8],
    ]],
    ['key'=>'install','icon'=>'⬡','name'=>'安装方式','note'=>'默认安装方式','items'=>[
        ['code'=>'recessed','name'=>'嵌入式','sub'=>'Recessed','delta'=>0,'default'=>true],
        ['code'=>'surface','name'=>'明装','sub'=>'Surface','delta'=>5.5],
        ['code'=>'pendant','name'=>'吊装','sub'=>'Pendant','delta'=>7.2],
    ]],
];
$pcPayload = ['base'=>$pcBase,'product'=>[
    'id'=>(int)($pcProduct['id'] ?? 0),'model'=>(string)($pcProduct['model_no'] ?? '—'),
    'name'=>(string)($pcProduct['product_name'] ?? '请选择产品'),'series'=>(string)($pcProduct['series_name'] ?? '—'),
    'category'=>(string)($pcProduct['category'] ?? '—'),'lamp'=>(string)($pcProduct['lamp_type'] ?? '—'),
    'opening'=>(string)($pcProduct['dim_opening'] ?? '—'),'image'=>$pcImage,
], 'options'=>$pcOptions];
?>
<section class="product-config-page" data-product-config data-config='<?= cc_h(json_encode($pcPayload, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)) ?>'>
  <div class="pc-main">
    <header class="pc-heading"><span class="eyebrow">PRODUCT CONFIGURATION</span><h1>产品配置</h1><p>选择产品并配置芯片、电源、光学、附件等选项，系统将自动计算价格与交期。</p></header>
    <ol class="pc-steps"><li class="done"><b>✓</b><span>选择产品</span></li><li class="active"><b>2</b><span>配置选项</span></li><li><b>3</b><span>确认配置</span></li></ol>
    <article class="pc-product">
      <div class="pc-product-image"><?php if($pcImage):?><img src="<?=cc_h($pcImage)?>" alt=""><?php else:?><span>NO IMAGE</span><?php endif;?></div>
      <div><div class="pc-model-line"><h2><?=cc_h($pcProduct['model_no'] ?? '暂无产品')?></h2><em><?=cc_h($pcProduct['status'] ?? '可报价')?></em></div><strong><?=cc_h($pcProduct['product_name'] ?? '请先选择产品')?></strong><p><?=cc_h(($pcProduct['category'] ?? '—').' / '.($pcProduct['series_name'] ?? '—'))?></p><p>开孔：<?=cc_h(trim((string)($pcProduct['dim_opening'] ?? '')) !== '' ? $pcProduct['dim_opening'] : '—')?> mm　|　类型：<?=cc_h(trim((string)($pcProduct['lamp_type'] ?? '')) !== '' ? $pcProduct['lamp_type'] : '—')?></p><a href="?page=commercial_product_library">查看产品详情 ›</a></div>
      <button type="button" data-pc-change>更换产品</button>
      <div class="pc-product-picker" data-pc-picker><?php foreach($pcProducts as $product):?><a href="?page=product_config&product_id=<?=(int)$product['id']?>"><b><?=cc_h($product['model_no'])?></b><span><?=cc_h($product['series_name'].' · '.$product['category'])?></span></a><?php endforeach;?></div>
    </article>
    <h2 class="pc-section-title">配置选项</h2>
    <div class="pc-option-groups">
      <?php foreach($pcOptions as $group):?><section class="pc-option-group" data-pc-group="<?=cc_h($group['key'])?>" data-multiple="<?=!empty($group['multiple'])?'1':'0'?>">
        <header><i><?=cc_h($group['icon'])?></i><div><h3><?=cc_h($group['name'])?><?=!empty($group['required'])?'<sup>*</sup>':''?></h3><p><?=cc_h($group['note'])?></p></div></header>
        <div class="pc-options"><?php foreach($group['items'] as $item):?><button type="button" class="<?=!empty($item['default'])?'selected':''?>" data-pc-option data-code="<?=cc_h($item['code'])?>" data-name="<?=cc_h($item['name'])?>" data-delta="<?=cc_h((string)$item['delta'])?>"><span class="pc-check"><?=!empty($group['multiple'])?'□':'◆'?></span><b><?=cc_h($item['name'])?></b><small><?=cc_h($item['sub'])?></small><?php if((float)$item['delta']!==0.0):?><em>+ USD <?=number_format((float)$item['delta'],2)?></em><?php endif;?></button><?php endforeach;?></div>
        <button type="button" class="pc-expand">⌄</button>
      </section><?php endforeach;?>
    </div>
    <div class="pc-compatible">ⓘ　当前配置组合可用，所有选项兼容。</div>
    <footer class="pc-actions"><a href="?page=commercial_product_library">‹　上一步</a><button type="button" data-pc-confirm>确认配置并加入报价</button></footer>
  </div>
  <aside class="pc-summary">
    <h2>配置摘要</h2>
    <div class="pc-summary-product"><?php if($pcImage):?><img src="<?=cc_h($pcImage)?>" alt=""><?php endif;?><div><h3><?=cc_h($pcProduct['model_no'] ?? '—')?></h3><strong><?=cc_h($pcProduct['product_name'] ?? '—')?></strong><p><?=cc_h(($pcProduct['category'] ?? '—').' / '.($pcProduct['series_name'] ?? '—'))?></p></div></div>
    <dl class="pc-selection-list" data-pc-selection-list></dl>
    <section class="pc-pricing"><h3>价格明细 (USD)</h3><dl data-pc-pricing></dl><div class="pc-total"><span>预计单价</span><strong data-pc-total>USD <?=number_format($pcBase,2)?></strong></div><ul><li>MOQ：1 PCS</li><li>交期：<span data-pc-lead>7–10 天</span></li><li>库存：按下单时确认</li></ul></section>
    <div class="pc-rule">配置规则提示<br><span>选择的组合符合当前产品配置规则，可直接加入报价。</span></div>
    <div class="pc-summary-actions"><button type="button" data-pc-clear>清空配置</button><button type="button" data-pc-template>保存为模板</button></div>
  </aside>
  <div class="pc-toast" data-pc-toast></div>
</section>
