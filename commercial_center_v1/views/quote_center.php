<?php
declare(strict_types=1);

$quoteMode = in_array((string)($_GET['quote_mode'] ?? ''), ['website','standard','custom'], true) ? (string)$_GET['quote_mode'] : '';
$quickMode = (string)($_GET['quick'] ?? '') === '1';
$quoteLabels = ['website'=>'网站订单报价单','standard'=>'标准品报价单','custom'=>'定制品报价单'];
$historyRows = $ops['delivery_queue'] ?? [];
if ($quoteMode !== '') {
    $catalog = (new Artdon\CommercialCenter\Services\CatalogCenterService())->products($view['auth'], '', '', 1, 8);
    $catalogRows = $catalog['rows'] ?? [];
    require __DIR__ . '/quote_' . $quoteMode . '.php';
    return;
}
if ($quoteMode !== ''):
    $catalog = (new Artdon\CommercialCenter\Services\CatalogCenterService())->products($view['auth'], '', '', 1, 8);
    $catalogRows = $catalog['rows'] ?? [];
?>
<section class="quote-page quote-editor" data-quote-editor data-quote-type="<?= cc_h($quoteMode) ?>">
  <header class="quote-editor-head">
    <div><a href="?page=quote_center" class="quote-back">← 返回报价单中心</a><h1><?= cc_h($quoteLabels[$quoteMode]) ?></h1><span class="quote-state"><?= $quickMode ? '快速创建' : '草稿' ?></span></div>
    <div class="quote-actions"><button type="button" data-draft-save>保存草稿</button><button type="button">预览</button><button type="button">打印</button><button type="button">导出PDF</button><button type="button">导出Excel</button><button type="button">提交审核</button><button type="button" class="primary">发送报价</button></div>
  </header>

  <?php if ($quickMode): ?><div class="quote-info-banner">快速创建模式：已减少基础字段，仅保留客户、币种、负责人和价格模板；保存后可补充完整资料。</div><?php endif; ?>
  <?php if ($quoteMode === 'website'): ?>
    <div class="quote-progress"><span class="done">✓ 网站传入</span><i></i><span class="active">2 待审核</span><i></i><span>3 待确认</span><i></i><span>4 已确认</span><i></i><span>5 转订单</span></div>
    <div class="quote-info-banner locked">网站订单快照已锁定：SKU、配置、原始数量、网站价格及客户原始要求不可覆盖；调整将形成审核版本。</div>
  <?php endif; ?>

  <div class="quote-editor-grid">
    <div class="quote-main">
      <section class="quote-card">
        <h2>报价信息</h2>
        <div class="quote-form-grid <?= $quickMode ? 'quick' : '' ?>">
          <label>报价单号<input value="保存后自动生成" readonly></label>
          <label>客户 <b>*</b><input placeholder="选择或输入客户"></label>
          <label>联系人<input placeholder="选择联系人"></label>
          <?php if (!$quickMode): ?><label>国家/地区 <b>*</b><select><option>中国</option><option>Singapore</option><option>United States</option></select></label><?php endif; ?>
          <label>币种 <b>*</b><select><option>USD 美元</option><option>CNY 人民币</option><option>EUR 欧元</option></select></label>
          <?php if (!$quickMode): ?><label>有效期 <b>*</b><select><option>30 天</option><option>15 天</option><option>60 天</option></select></label><?php endif; ?>
          <label>负责人 <b>*</b><input value="<?= cc_h($view['auth']['user']['display_name'] ?? '') ?>" placeholder="负责人"></label>
          <?php if (!$quickMode): ?><label>业务员<input placeholder="业务员"></label><label>贸易条款<select><option>FOB 深圳</option><option>EXW 广州</option><option>CIF</option></select></label><label>付款方式<select><option>30% 预付款，70% 发货前</option><option>T/T 30% 预付，70% 见提单副本</option></select></label><?php endif; ?>
          <?php if ($quoteMode === 'custom'): ?><label>项目名称 <b>*</b><input placeholder="项目名称"></label><label>项目类型<select><option>灯具定制</option><option>工程项目</option><option>样品开发</option></select></label><?php endif; ?>
          <?php if ($quoteMode === 'standard'): ?><label>价格模板<select><option>标准出口价格</option><option>A级客户价格</option></select></label><?php endif; ?>
          <?php if (!$quickMode): ?><label>关联项目<input placeholder="可选"></label><label class="span-2">备注<textarea placeholder="报价内部备注"></textarea></label><?php endif; ?>
        </div>
      </section>

      <?php if ($quoteMode === 'custom'): ?>
      <section class="quote-card quote-upload-card">
        <h2>客户需求 / 参考资料</h2>
        <div class="upload-grid">
          <?php foreach (['产品图片','参考图','尺寸图','结构图','客户手绘图','客户文件（PDF / Excel / Word / ZIP）'] as $upload): ?>
          <label class="upload-box"><input type="file" multiple hidden data-file-upload><strong>＋ 上传<?= cc_h($upload) ?></strong><span>拖拽文件到此上传</span><em data-file-count>尚未选择</em></label>
          <?php endforeach; ?>
        </div>
      </section>
      <?php endif; ?>

      <section class="quote-card quote-lines-card">
        <div class="quote-card-title"><h2>报价明细 <?= $quoteMode === 'standard' ? '（读取产品适配规则）' : '' ?></h2><div><button type="button" data-add-line>＋ 添加明细</button><?php if ($quoteMode === 'standard'): ?><button type="button" data-open-product>＋ 从报价产品库添加</button><?php endif; ?><button type="button">批量导入</button></div></div>
        <div class="quote-lines-scroll">
          <table class="quote-lines">
            <thead><tr><th>#</th><th>图片</th><th><?= $quoteMode === 'custom' ? '自定义产品名称 *' : '型号 / 产品名称' ?></th><th>规格 / 配置</th><th>数量</th><th>单位</th><th>单价</th><th>折扣</th><th>小计</th><th>交期</th><th>备注</th><th>操作</th></tr></thead>
            <tbody data-quote-lines>
            <?php $initialRows = $quoteMode === 'standard' ? array_slice($catalogRows, 0, 5) : [[]]; foreach ($initialRows as $i => $row): $price = isset($row['bom_cost']) && $row['bom_cost'] !== null ? round((float)$row['bom_cost'] * 1.65, 2) : 0; ?>
              <tr>
                <td><?= $i + 1 ?></td><td><div class="line-image"><?php $img=cc_legacy_asset_url($row['image_path'] ?? ''); if($img): ?><img src="<?= cc_h($img) ?>" alt=""><?php else: ?>图<?php endif; ?></div></td>
                <td><input value="<?= cc_h($row['model_no'] ?? '') ?>" placeholder="输入产品名称"></td>
                <td><textarea placeholder="<?= $quoteMode === 'custom' ? '自由输入规格、材质、工艺' : '选择芯片 / 电源 / 光学 / 调光 / 配件' ?>"><?= cc_h($row['category'] ?? '') ?></textarea><?php if($quoteMode==='standard'): ?><button class="line-config" type="button" data-configure>配置产品</button><?php endif; ?></td>
                <td><input type="number" min="1" value="1" data-qty></td><td><input value="pcs"></td><td><input type="number" min="0" step=".01" value="<?= cc_h((string)$price) ?>" data-price></td><td><input type="number" min="0" max="100" value="0" data-discount></td><td data-line-total>0.00</td><td><input value="15 天"></td><td><input placeholder="备注"></td><td><button type="button" class="text-danger" data-remove-line>删除</button></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <footer><button type="button" data-add-line>＋ 添加明细</button><span>支持 50 项以上，表头固定、明细区独立滚动</span></footer>
      </section>

      <?php if ($quoteMode === 'custom'): ?>
      <section class="quote-card"><div class="quote-card-title"><h2>自定义字段</h2><button type="button" data-add-field>＋ 增加自定义字段</button></div><div class="custom-fields" data-custom-fields><label>材质<input placeholder="铝合金"></label><label>颜色<input placeholder="RAL 色号"></label><label>安装方式<input placeholder="轨道 / 嵌入 / 吊装"></label></div></section>
      <?php endif; ?>
    </div>

    <aside class="quote-summary">
      <?php if ($quoteMode === 'custom'): ?><section class="quote-card"><h2>自由录入 / 自定义字段</h2><p>产品名称、规格、单位、数量和价格均可独立编辑；标准产品关联为可选参考。</p><button type="button">选择标准产品（可选）</button></section><?php endif; ?>
      <section class="quote-card quote-total-card"><h2>报价汇总 <small data-currency>USD</small></h2><dl><dt>产品金额</dt><dd data-subtotal>0.00</dd><dt>折扣金额</dt><dd><input type="number" value="0" min="0" step=".01" data-order-discount></dd><dt>运费</dt><dd><input type="number" value="0" min="0" step=".01" data-shipping></dd><dt>税费</dt><dd><input type="number" value="0" min="0" step=".01" data-tax></dd></dl><div class="grand-total"><span>总金额</span><strong data-grand-total>0.00</strong></div></section>
      <section class="quote-card"><h2>备注信息</h2><textarea rows="5" placeholder="对客户或内部备注"></textarea></section>
      <section class="quote-card quote-risk"><h2>风险提醒</h2><p>△ MOQ：保存后按价格策略检查</p><p>◷ 交期：提交审核前确认交期</p><p>◇ 利润：按权限显示利润信息</p></section>
    </aside>
  </div>

  <div class="quote-config-modal" data-config-modal aria-hidden="true"><div><header><div><b>添加产品 / 选择标准产品与配置</b><span>读取物料与配件中心的产品适配规则</span></div><button type="button" data-modal-close>×</button></header><div class="config-steps"><b>1 选择产品</b><span>2 选择配置</span><span>3 加入报价</span></div><input class="config-search" placeholder="搜索型号 / 产品名称"><div class="config-options"><?php foreach(['芯片/光源'=>'COB 18W 3000K','电源/驱动'=>'DALI-2','调光方式'=>'DALI-2','光学/角度'=>'36°','附件/配件'=>'蜂窝防眩格栅','外观颜色'=>'白色','安装方式'=>'嵌入式'] as $label=>$option): ?><label><b><?= cc_h($label) ?></b><span class="selected"><?= cc_h($option) ?></span><span>其他合法选项</span></label><?php endforeach; ?></div><footer><span>冲突规则与审批规则将在加入前校验</span><button type="button" data-modal-close>取消</button><button type="button" class="primary" data-apply-config>加入报价</button></footer></div></div>
</section>
<?php else: ?>
<section class="quote-page quote-center" data-quote-center>
  <header class="quote-center-head"><div><span class="eyebrow">QUOTATION CENTER</span><h1>报价单中心</h1><p>统一管理网站订单、标准品与定制品报价；默认显示历史报价。</p></div><button type="button" class="primary new-quote-button" data-new-quote>＋ 新建报价单</button></header>
  <section class="quote-stats"><div><span>全部报价</span><strong><?= count($historyRows) ?></strong></div><div><span>草稿</span><strong>0</strong></div><div><span>待审核</span><strong><?= (int)($ops['counts']['pending_approval'] ?? 0) ?></strong></div><div><span>待发送</span><strong><?= (int)($ops['counts']['pending_send'] ?? 0) ?></strong></div><div><span>待客户确认</span><strong><?= (int)($ops['counts']['pending_customer'] ?? 0) ?></strong></div></section>
  <section class="quote-card">
    <div class="quote-list-tools"><input type="search" placeholder="搜索报价单号、客户、联系人"><select><option>全部报价类型</option><option>网站订单报价单</option><option>标准品报价单</option><option>定制品报价单</option></select><select><option>全部状态</option><option>草稿</option><option>待审核</option><option>已发送</option></select><button type="button">筛选</button></div>
    <div class="quote-history-wrap"><table class="quote-history"><thead><tr><th>报价单号</th><th>报价类型</th><th>来源</th><th>客户</th><th>联系人</th><th>国家/地区</th><th>产品数</th><th>总金额</th><th>币种</th><th>状态</th><th>负责人</th><th>更新时间</th><th>操作</th></tr></thead><tbody>
    <?php if ($historyRows === []): ?><tr><td colspan="13"><div class="quote-empty"><strong>暂无历史报价</strong><span>旧报价数据未迁移或当前账号范围内没有记录，可点击“新建报价单”开始。</span></div></td></tr><?php else: foreach($historyRows as $row): ?>
      <tr><td><b><?= cc_h($row['quote_no'] ?? '') ?></b></td><td>标准品报价单</td><td>广州商务</td><td><?= cc_h($row['customer_name'] ?? '') ?></td><td>—</td><td>—</td><td>—</td><td><?= cc_h(number_format((float)($row['amount'] ?? 0),2)) ?></td><td><?= cc_h($row['currency'] ?? 'USD') ?></td><td><span class="quote-status"><?= cc_h($row['approval_status'] ?? '') ?></span></td><td>—</td><td><?= cc_h($row['updated_at'] ?? '') ?></td><td class="row-actions"><a href="?page=quote_center&quote_mode=standard">查看</a><a href="?page=quote_center&quote_mode=standard">编辑</a><button>复制</button><button>PDF</button><button>Excel</button><details><summary>更多⌄</summary><div><?php foreach(['打印','发送报价','提交审核','审核记录','版本记录','转订单','转项目','下载附件包','作废','恢复'] as $action): ?><button><?= cc_h($action) ?></button><?php endforeach; ?></div></details></td></tr>
    <?php endforeach; endif; ?></tbody></table></div>
  </section>

  <div class="quote-modal" data-type-modal aria-hidden="true"><div><header><div><h2>新建报价单</h2><p>第一步：选择报价类型</p></div><button type="button" data-modal-close>×</button></header><div class="quote-type-grid"><?php foreach(['website'=>['网站订单报价单','导入或代建新加坡网站订单，保存原始快照并锁定。'],'standard'=>['标准品报价单','从报价产品库选品，读取适配和价格规则。'],'custom'=>['定制品报价单','自由录入产品、规格、价格并上传完整需求资料。']] as $key=>$type): ?><button type="button" data-quote-type="<?= $key ?>"><i><?= $key==='website'?'网':($key==='standard'?'标':'定') ?></i><strong><?= cc_h($type[0]) ?></strong><span><?= cc_h($type[1]) ?></span><b>选择 →</b></button><?php endforeach; ?></div></div></div>
  <div class="quote-modal" data-detail-modal aria-hidden="true"><div><header><div><h2 data-detail-title>填写基础信息</h2><p>第二步：创建报价草稿</p></div><button type="button" data-modal-close>×</button></header><form class="quote-create-form" data-create-form><input type="hidden" name="quote_mode"><div data-website-source hidden><label class="source-choice"><input type="radio" name="website_source" value="import" checked><span><b>从新加坡网站待审核订单导入</b><small>选择待审核订单，原始数据自动锁定。</small></span></label><label class="source-choice"><input type="radio" name="website_source" value="proxy"><span><b>业务员代客户建立网站订单记录</b><small>从新加坡网站可售 SKU 中代客户选品。</small></span></label></div><div class="modal-form-grid"><label>客户 <b>*</b><input required placeholder="选择客户"></label><label>联系人<input placeholder="联系人"></label><label>国家/地区<select><option>中国</option><option>Singapore</option></select></label><label>币种<select><option>USD</option><option>CNY</option></select></label><label>有效期<select><option>30 天</option><option>15 天</option></select></label><label>负责人<input value="<?= cc_h($view['auth']['user']['display_name'] ?? '') ?>"></label><label>贸易条款<select><option>FOB 深圳</option><option>EXW 广州</option></select></label><label>付款方式<select><option>30% 预付，70% 发货前</option></select></label><label data-standard-only>价格模板<select><option>标准出口价格</option></select></label><label data-custom-only>项目名称<input placeholder="项目名称"></label><label class="span-2">备注<textarea></textarea></label></div><footer><button type="button" data-modal-close>取消</button><button type="submit" name="quick" value="1" data-quick-create>快速创建</button><button type="submit" class="primary">进入编辑页</button></footer></form></div></div>
</section>
<?php endif; ?>
