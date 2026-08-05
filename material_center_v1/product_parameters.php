<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

$pageTitle = '产品参数';
$pageDescription = '集中维护产品功率、电流、电压、尺寸、光学和安装参数，供产品适配与配置包复用。';
$activeMenu = 'product_parameters';

function mc_pp_decode(mixed $json): array
{
    if (is_array($json)) return $json;
    $decoded = json_decode((string)$json, true);
    return is_array($decoded) ? $decoded : [];
}

function mc_pp_value(array $params, string $key): string
{
    $value = $params[$key] ?? '';
    if ($value === null) return '';
    return (string)$value;
}

function mc_pp_image(array $snapshot): string
{
    $image = trim((string)($snapshot['image_url'] ?? $snapshot['image'] ?? $snapshot['product_image'] ?? ''));
    if ($image === '') return '';
    if (preg_match('#^https?://#i', $image)) return $image;
    return mc_asset_url($image);
}

function mc_pp_complete_count(array $params): int
{
    $keys = ['power_min_w','power_max_w','current_min_ma','current_max_ma','voltage_min_v','voltage_max_v','beam_angle','cct_k','cri_min','length_mm','width_mm','height_mm','installation_type','driver_type','dimming_mode'];
    $count = 0;
    foreach ($keys as $key) {
        if (isset($params[$key]) && $params[$key] !== '' && $params[$key] !== null) $count++;
    }
    return $count;
}

$canEdit = has_permission('adaptation_v2.configure_product')
    || has_permission('material_center.adaptation.manage')
    || has_permission('material_center.material.batch')
    || has_permission('material_center.material.edit');
$q = trim((string)($_GET['q'] ?? ''));
$rows = [];
$stats = ['total' => 0, 'with_params' => 0, 'need_params' => 0];

if (mc_table_exists('mc_products')) {
    $hasMapping = mc_table_exists('mc_pa2_product_category_mappings');
    $select = "SELECT p.id, p.product_code, p.product_name, p.status, p.snapshot_json";
    $from = " FROM mc_products p";
    if ($hasMapping) {
        $select .= ", m.category_name, m.series_code";
        $from .= " LEFT JOIN mc_pa2_product_category_mappings m ON m.product_id=p.id";
    } else {
        $select .= ", '' AS category_name, '' AS series_code";
    }
    $where = " WHERE p.status='active'";
    $params = [];
    if ($q !== '') {
        $where .= " AND (p.product_code LIKE ? OR p.product_name LIKE ? OR CAST(p.snapshot_json AS CHAR) LIKE ?)";
        $like = '%' . $q . '%';
        $params = [$like, $like, $like];
    }
    $sql = $select . $from . $where . ' ORDER BY p.id DESC LIMIT 120';
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $stats['total'] = count($rows);
    foreach ($rows as $row) {
        $snapshot = mc_pp_decode($row['snapshot_json'] ?? '{}');
        $productParams = (array)($snapshot['product_parameters'] ?? []);
        if (mc_pp_complete_count($productParams) > 0) $stats['with_params']++;
    }
    $stats['need_params'] = max(0, $stats['total'] - $stats['with_params']);
}

include MC_ROOT . '/components/layout_top.php';
?>
<style>
.mc-product-param-page{display:grid;gap:18px}
.mc-param-hero{display:flex;align-items:center;justify-content:space-between;gap:18px;padding:22px;border:1px solid #dbe7f3;border-radius:22px;background:linear-gradient(135deg,#f0fdfa,#fff)}
.mc-param-hero h1{margin:0 0 8px;font-size:28px}
.mc-param-hero p{margin:0;color:#64748b;line-height:1.7}
.mc-param-stats{display:flex;gap:12px;flex-wrap:wrap}
.mc-param-stat{min-width:128px;border:1px solid #dbe7f3;border-radius:18px;background:#fff;padding:14px 16px}
.mc-param-stat strong{display:block;font-size:24px;color:#0f172a}
.mc-param-stat span{color:#64748b}
.mc-param-toolbar{display:flex;justify-content:space-between;gap:12px;align-items:end;padding:16px;border:1px solid #dbe7f3;border-radius:18px;background:#fff}
.mc-param-toolbar form{display:grid;grid-template-columns:minmax(280px,1fr) auto;gap:10px;flex:1}
.mc-param-table-wrap{overflow:auto;border:1px solid #dbe7f3;border-radius:20px;background:#fff}
.mc-param-table{width:100%;border-collapse:separate;border-spacing:0;min-width:1080px}
.mc-param-table th,.mc-param-table td{padding:14px 16px;border-bottom:1px solid #edf2f7;text-align:left;vertical-align:middle}
.mc-param-table th{background:#f8fafc;color:#475569;font-weight:700}
.mc-param-product{display:grid;grid-template-columns:56px minmax(220px,1fr);gap:12px;align-items:center}
.mc-param-thumb{width:56px;height:56px;border:1px solid #dbe7f3;border-radius:14px;background:#f8fafc;display:flex;align-items:center;justify-content:center;overflow:hidden;color:#94a3b8;font-size:12px}
.mc-param-thumb img{max-width:100%;max-height:100%;object-fit:contain}
.mc-param-product b{display:block;color:#0f172a}
.mc-param-product small{display:block;color:#64748b;margin-top:4px}
.mc-param-chip-list{display:flex;flex-wrap:wrap;gap:6px;max-width:380px}
.mc-param-chip{display:inline-flex;align-items:center;border-radius:999px;padding:4px 9px;background:#eff6ff;color:#2563eb;font-size:12px;font-weight:700}
.mc-param-chip.is-empty{background:#f8fafc;color:#94a3b8}
.mc-param-complete{display:flex;flex-direction:column;gap:6px;min-width:130px}
.mc-param-meter{height:8px;border-radius:999px;background:#e2e8f0;overflow:hidden}
.mc-param-meter span{display:block;height:100%;background:#14b8a6}
.mc-param-modal{width:min(1280px,calc(100vw - 48px));max-width:none;max-height:calc(100dvh - 48px);display:flex;flex-direction:column;overflow:hidden;border-radius:22px;box-shadow:0 26px 90px rgba(15,23,42,.28)}
.mc-param-modal .mc-modal__header{min-height:92px;align-items:flex-start;padding:22px 30px;background:linear-gradient(135deg,#f0fdfa,#f8fbff 58%,#fff)}
.mc-param-modal .mc-modal__header strong{display:block;font-size:22px;line-height:1.35;color:#0f172a}
.mc-param-modal .mc-modal__header span{display:block;margin-top:6px;color:#667085;font-size:15px;line-height:1.5}
.mc-param-modal .mc-modal__body{flex:1;min-height:0;max-height:none;overflow:auto;padding:26px 30px;background:#fff}
.mc-param-modal .mc-modal__footer{min-height:82px;padding:16px 30px;background:#fbfdff}
.mc-param-modal .mc-modal__footer .mc-button{height:48px;min-width:104px;border-radius:12px;font-size:16px;font-weight:800}
.mc-param-modal .mc-modal__footer .mc-button--primary{min-width:178px;background:#d60000;border-color:#d60000}
.mc-param-close{width:auto;height:52px;padding:0 24px;border-radius:12px;font-size:18px;font-weight:800;background:#fff}
.mc-param-form-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:16px}
.mc-param-form-grid .mc-field--wide{grid-column:span 2}
.mc-param-form-grid .mc-field--full{grid-column:1/-1}
.mc-param-form-grid .mc-field span{font-size:15px;font-weight:800;color:#344054}
.mc-param-form-grid .mc-field input,.mc-param-form-grid .mc-field select,.mc-param-form-grid .mc-field textarea{height:52px;border:1px solid #cfd8e6;border-radius:13px;padding:0 16px;font-size:16px;background:#fff}
.mc-param-form-grid .mc-field textarea{height:118px;padding-top:14px;line-height:1.6}
.mc-param-section-title{grid-column:1/-1;margin-top:8px;padding-top:18px;border-top:1px dashed #dbe7f3;color:#0f766e;font-size:18px;font-weight:900}
.mc-param-section-title small{display:block;margin-top:6px;color:#667085;font-size:13px;font-weight:700;line-height:1.5}
.mc-param-guide{padding:18px 20px;border:1px dashed #99f6e4;border-radius:16px;background:#f0fdfa;color:#0f766e;line-height:1.8;margin-bottom:18px;font-size:18px;font-weight:800}
.mc-param-mode-note{grid-column:1/-1;display:flex;align-items:center;gap:12px;border:1px solid #e6edf5;background:#fbfdff;border-radius:14px;padding:13px 16px;color:#667085;font-size:15px;line-height:1.6}
.mc-param-mode-note b{display:inline-flex;align-items:center;border-radius:999px;background:#e6fffb;color:#0b7773;padding:5px 12px;white-space:nowrap}
@media (max-width:1100px){.mc-param-hero,.mc-param-toolbar{align-items:stretch;flex-direction:column}.mc-param-modal{width:calc(100vw - 28px);max-height:calc(100dvh - 28px)}.mc-param-form-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.mc-param-form-grid .mc-field--wide,.mc-param-section-title{grid-column:span 2}}
@media (max-width:700px){.mc-param-modal{width:100vw;height:100dvh;max-height:100dvh;border-radius:0}.mc-param-form-grid{grid-template-columns:1fr}.mc-param-form-grid .mc-field--wide,.mc-param-section-title{grid-column:1}.mc-param-modal .mc-modal__header,.mc-param-modal .mc-modal__body,.mc-param-modal .mc-modal__footer{padding-left:18px;padding-right:18px}}
</style>
<section class="mc-page mc-product-param-page">
  <div class="mc-param-hero">
    <div>
      <h1>产品参数</h1>
      <p>这里维护的是物料中心产品主数据，不是某个适配页面的临时字段。保存后，产品适配 V2 会优先使用这些参数计算芯片、电源和光学匹配。</p>
    </div>
    <div class="mc-param-stats">
      <div class="mc-param-stat"><strong><?=intval($stats['total'])?></strong><span>当前列表产品</span></div>
      <div class="mc-param-stat"><strong><?=intval($stats['with_params'])?></strong><span>已有参数</span></div>
      <div class="mc-param-stat"><strong><?=intval($stats['need_params'])?></strong><span>待补参数</span></div>
    </div>
  </div>
  <div class="mc-param-toolbar">
    <form method="get">
      <label class="mc-field"><span>搜索产品</span><input name="q" value="<?=mc_h($q)?>" placeholder="型号 / 名称 / 系列 / 已保存参数"></label>
      <button class="mc-button mc-button--primary" type="submit">搜索</button>
    </form>
    <div class="mc-empty-inline">参数保存于 <code>mc_products.snapshot_json.product_parameters</code>，不修改旧 BOM。</div>
  </div>
  <?php if (!mc_table_exists('mc_products')): ?>
    <div class="mc-empty-state"><strong>产品主数据表尚未就绪</strong><span>请先完成物料中心产品同步。</span></div>
  <?php else: ?>
    <div class="mc-param-table-wrap">
      <table class="mc-param-table">
        <thead><tr><th>产品</th><th>分类 / 系列</th><th>关键参数</th><th>完整度</th><th>更新时间</th><th>操作</th></tr></thead>
        <tbody>
        <?php if (!$rows): ?>
          <tr><td colspan="6">没有匹配的产品。</td></tr>
        <?php endif; ?>
        <?php foreach ($rows as $row):
          $snapshot = mc_pp_decode($row['snapshot_json'] ?? '{}');
          $productParams = (array)($snapshot['product_parameters'] ?? []);
          $image = mc_pp_image($snapshot);
          $complete = mc_pp_complete_count($productParams);
          $percent = (int)round($complete / 15 * 100);
          $summary = [
            '功率' => (mc_pp_value($productParams,'power_min_w') !== '' || mc_pp_value($productParams,'power_max_w') !== '') ? mc_pp_value($productParams,'power_min_w') . '–' . mc_pp_value($productParams,'power_max_w') . 'W' : '',
            '电流' => (mc_pp_value($productParams,'current_min_ma') !== '' || mc_pp_value($productParams,'current_max_ma') !== '') ? mc_pp_value($productParams,'current_min_ma') . '–' . mc_pp_value($productParams,'current_max_ma') . 'mA' : '',
            '电压' => (mc_pp_value($productParams,'voltage_min_v') !== '' || mc_pp_value($productParams,'voltage_max_v') !== '') ? mc_pp_value($productParams,'voltage_min_v') . '–' . mc_pp_value($productParams,'voltage_max_v') . 'V' : '',
            '光束角' => mc_pp_value($productParams,'beam_angle') !== '' ? mc_pp_value($productParams,'beam_angle') . '°' : '',
            '调光' => mc_pp_value($productParams,'dimming_mode'),
          ];
        ?>
          <tr>
            <td><div class="mc-param-product"><div class="mc-param-thumb"><?php if ($image !== ''): ?><img src="<?=mc_h($image)?>" alt=""><?php else: ?>无图<?php endif; ?></div><div><b><?=mc_h((string)($row['product_code'] ?? ''))?></b><small><?=mc_h((string)($row['product_name'] ?? ''))?></small></div></div></td>
            <td><b><?=mc_h((string)($row['category_name'] ?? '未映射'))?></b><br><small><?=mc_h((string)($row['series_code'] ?? ($snapshot['series_name'] ?? '')))?></small></td>
            <td><div class="mc-param-chip-list"><?php foreach ($summary as $label => $value): ?><span class="mc-param-chip <?=$value===''?'is-empty':''?>"><?=mc_h($label . '：' . ($value !== '' ? $value : '未填'))?></span><?php endforeach; ?></div></td>
            <td><div class="mc-param-complete"><strong><?=intval($percent)?>%</strong><div class="mc-param-meter"><span style="width:<?=intval($percent)?>%"></span></div><small><?=intval($complete)?> / 15 项</small></div></td>
            <td><?=mc_h((string)($productParams['updated_at'] ?? '—'))?></td>
            <td><button class="mc-button mc-button--primary" type="button" data-open-modal="product-param-modal" data-open-param-editor data-product-id="<?=intval($row['id'])?>" data-product-code="<?=mc_h((string)($row['product_code'] ?? ''))?>" data-product-name="<?=mc_h((string)($row['product_name'] ?? ''))?>" data-params="<?=mc_json_attr($productParams)?>" <?=$canEdit?'':'disabled'?>>维护参数</button></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</section>

<div class="mc-modal" id="product-param-modal" data-modal>
  <form class="mc-modal__panel mc-param-modal" data-product-param-form>
    <div class="mc-modal__header"><div><strong data-param-modal-title>维护产品参数</strong><span>保存到物料中心产品主数据；不修改旧 BOM。</span></div><button class="mc-icon-button mc-param-close" type="button" data-close-layer>关闭</button></div>
    <div class="mc-modal__body">
      <input type="hidden" name="csrf_token" value="<?=mc_h(csrf_token())?>">
      <input type="hidden" name="product_id">
      <div class="mc-param-guide">建议先填会影响适配判断的硬条件：功率、电流、电压、光束角、色温、显指、尺寸和安装/电源方式。空白字段不会写入，后续可以继续补。</div>
      <div class="mc-param-form-grid">
        <div class="mc-param-mode-note"><b>产品参数</b><span>这里维护的是产品主数据，供芯片、电源、光学适配共同使用；不是某个单产品适配草稿里的临时逻辑。</span></div>
        <div class="mc-param-section-title">电气参数<small>用于判断芯片电流/电压范围、电源输出范围、功率和调光方式。</small></div>
        <label class="mc-field"><span>功率下限 W</span><input type="number" step="0.01" min="0" name="power_min_w"></label>
        <label class="mc-field"><span>功率上限 W</span><input type="number" step="0.01" min="0" name="power_max_w"></label>
        <label class="mc-field"><span>调光方式</span><input name="dimming_mode" placeholder="如 DALI / 0-10V / TRIAC"></label>
        <label class="mc-field"><span>电流下限 mA</span><input type="number" step="0.01" min="0" name="current_min_ma"></label>
        <label class="mc-field"><span>电流上限 mA</span><input type="number" step="0.01" min="0" name="current_max_ma"></label>
        <label class="mc-field"><span>电源方式</span><select name="driver_type"><option value="">未设置</option><option value="internal">内置电源</option><option value="external">外置电源</option><option value="intrack">INTRACK 电源</option><option value="magnetic">磁吸系统电源</option><option value="none">无需电源</option></select></label>
        <label class="mc-field"><span>电压下限 V</span><input type="number" step="0.01" min="0" name="voltage_min_v"></label>
        <label class="mc-field"><span>电压上限 V</span><input type="number" step="0.01" min="0" name="voltage_max_v"></label>
        <label class="mc-field"><span>安装方式</span><input name="installation_type" placeholder="如 嵌入式 / 明装 / 导轨"></label>
        <div class="mc-param-section-title">光学与外观<small>用于判断透镜、反光杯、蜂窝网、四叶片、光学膜等光学件匹配条件。</small></div>
        <label class="mc-field"><span>色温 K</span><input type="number" step="1" min="1000" max="20000" name="cct_k"></label>
        <label class="mc-field"><span>最低显指 CRI</span><input type="number" step="0.1" min="0" max="100" name="cri_min"></label>
        <label class="mc-field"><span>光束角 °</span><input type="number" step="0.1" min="0" max="180" name="beam_angle"></label>
        <label class="mc-field"><span>光学尺寸</span><input name="optical_size" placeholder="如 Φ35 / LES 9mm"></label>
        <label class="mc-field"><span>IP 等级</span><input name="ip_rating" maxlength="30" placeholder="如 IP44"></label>
        <label class="mc-field"><span>开孔 mm</span><input type="number" step="0.01" min="0" name="cutout_mm"></label>
        <div class="mc-param-section-title">结构尺寸<small>用于判断安装空间、开孔、外形尺寸和包装/结构约束。</small></div>
        <label class="mc-field"><span>长度 mm</span><input type="number" step="0.01" min="0" name="length_mm"></label>
        <label class="mc-field"><span>宽度 mm</span><input type="number" step="0.01" min="0" name="width_mm"></label>
        <label class="mc-field"><span>高度 mm</span><input type="number" step="0.01" min="0" name="height_mm"></label>
        <label class="mc-field mc-field--full"><span>备注 / 判断依据</span><textarea name="notes" rows="3" placeholder="例如：按同系列 57.10511 资料确认；只适配外置电源。"></textarea></label>
      </div>
      <div class="mc-form-error" data-product-param-error hidden></div>
    </div>
    <div class="mc-modal__footer"><button class="mc-button" type="button" data-close-layer>取消</button><button class="mc-button mc-button--primary" type="submit">保存产品参数</button></div>
  </form>
</div>

<script>
(() => {
  const form = document.querySelector('[data-product-param-form]');
  if (!form) return;
  const fields = ['power_min_w','power_max_w','current_min_ma','current_max_ma','voltage_min_v','voltage_max_v','cct_k','cri_min','beam_angle','length_mm','width_mm','height_mm','cutout_mm','ip_rating','installation_type','driver_type','dimming_mode','optical_size','notes'];
  const errorBox = form.querySelector('[data-product-param-error]');
  const setError = (message) => {
    if (!errorBox) return;
    errorBox.hidden = !message;
    errorBox.textContent = message || '';
  };
  document.querySelectorAll('[data-open-param-editor]').forEach((button) => {
    button.addEventListener('click', () => {
      let params = {};
      try { params = JSON.parse(button.dataset.params || '{}') || {}; } catch {}
      form.reset();
      setError('');
      form.elements.product_id.value = button.dataset.productId || '';
      document.querySelector('[data-param-modal-title]').textContent = `维护产品参数：${button.dataset.productCode || ''} ${button.dataset.productName || ''}`.trim();
      fields.forEach((field) => {
        if (form.elements[field]) form.elements[field].value = params[field] ?? '';
      });
    });
  });
  form.addEventListener('submit', async (event) => {
    event.preventDefault();
    setError('');
    const submit = form.querySelector('button[type="submit"]');
    if (submit) submit.disabled = true;
    try {
      const response = await fetch(`${window.MC_BASE_URL}/api/v1/product-parameters.php`, {
        method: 'POST',
        body: new FormData(form),
        credentials: 'same-origin',
        headers: {Accept: 'application/json', 'X-CSRF-Token': window.MC_CSRF || ''}
      });
      const result = await response.json();
      if (!response.ok || !result.ok) throw new Error(result.message || '保存失败');
      if (window.ArtdonUI?.toast) window.ArtdonUI.toast(result.message || '已保存', 'success');
      setTimeout(() => window.location.reload(), 350);
    } catch (error) {
      setError(error.message || '保存失败');
      if (window.ArtdonUI?.toast) window.ArtdonUI.toast(error.message || '保存失败', 'danger', 0);
    } finally {
      if (submit) submit.disabled = false;
    }
  });
})();
</script>
<?php include MC_ROOT . '/components/layout_bottom.php'; ?>
