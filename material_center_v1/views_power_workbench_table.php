<?php declare(strict_types=1); ?>
<section class="ui-card ui-table-panel power-table-panel">
  <div class="ui-table-wrap"><table class="ui-table power-table" id="power-workbench-table" data-ui-table>
    <thead><tr><th class="ui-select-col"><label class="ui-check"><input type="checkbox" data-ui-select-all aria-label="选择当前页"><span class="ui-check-box"></span></label></th><th data-sort>来源 / 编号</th><th data-sort>名称</th><th data-sort>品牌</th><th data-sort>型号</th><th>关键规格</th><th data-sort="number">功率</th><th data-sort>安装方式</th><th data-sort="number">质保</th><th data-sort>状态</th><th class="ui-action-col">操作</th></tr></thead>
    <tbody><?php foreach($rows as$r):
      $isSource=isset($r['source_id'])||(($r['_kind']??'')==='source');
      $id=(int)($isSource?($r['source_id']??$r['id']):($r['id']??$r['material_id']??0));
      $name=(string)($r['raw_name']??$r['name']??'');
      $brand=(string)($r['raw_brand']??$r['brand']??'');
      $model=(string)($r['raw_model']??$r['model']??'');
      $spec=(string)($r['raw_spec']??$r['spec']??'');
      $statusCode=(string)($r['mapping_status']??$r['status']??'pending');
      $statusLabel=['pending'=>'待整理','parsed'=>'待确认','needs_review'=>'待确认','duplicate_candidate'=>'异常','confirmed'=>'待确认','imported'=>'正式','official'=>'正式','draft'=>'待确认','pending_review'=>'待确认','disabled'=>'停用','archived'=>'归档','rejected'=>'异常'][$statusCode]??($isSource?'待整理':'正式');
      $power=$r['max_output_power_w']??$r['nominal_power_w']??'';
      $installation=['internal'=>'内置','external'=>'外置','remote'=>'远置','integrated'=>'一体式','unknown'=>'待确认'][$r['installation_type']??'unknown']??'待确认';
      $warranty=$r['supplier_warranty_years']??'';
      $code=$isSource?'BOM #'.$id:(string)($r['material_code']??'#'.$id);
      $reviewUrl='material/power.php';
    ?><tr tabindex="0" data-power-row data-record-kind="<?=$isSource?'source':'formal'?>" data-record-id="<?=$id?>" data-code="<?=mc_h($code)?>" data-name="<?=mc_h($name)?>" data-brand="<?=mc_h($brand)?>" data-model="<?=mc_h($model)?>" data-spec="<?=mc_h($spec)?>" data-status="<?=mc_h($statusLabel)?>" data-power="<?=mc_h((string)$power)?>" data-installation="<?=mc_h($installation)?>" data-warranty="<?=mc_h((string)$warranty)?>" data-review-url="<?=mc_h($reviewUrl)?>">
      <td class="ui-select-col"><label class="ui-check"><input type="checkbox" data-ui-row-select aria-label="选择 <?=mc_h($name)?>"><span class="ui-check-box"></span></label></td>
      <td><?=mc_h($code)?></td><td><?=mc_h($name)?></td><td><?=mc_h($brand?:'—')?></td><td><?=mc_h($model?:'—')?></td>
      <td class="ui-cell-spec" title="<?=mc_h($spec)?>"><span><?=mc_h($spec?:'—')?></span></td><td><?=$power!==''?mc_h((string)$power).' W':'—'?></td><td><?=mc_h($installation)?></td><td><?=$warranty!==''?mc_h((string)$warranty).' 年':'待确认'?></td>
      <td><span class="ui-badge ui-status-<?=mc_h($statusCode)?>"><?=mc_h($statusLabel)?></span></td><td class="ui-action-col"><button class="ui-link-button" type="button" data-power-view>查看</button></td>
    </tr><?php endforeach;?></tbody>
  </table></div>
</section>
