<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');
header('X-Content-Type-Options: nosniff');

function mc_pp_response(bool $ok, string $message, array $data = [], int $status = 200): void
{
    http_response_code($status);
    echo json_encode(['ok' => $ok, 'message' => $message, 'data' => $data], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function mc_pp_can_edit(): bool
{
    return has_permission('adaptation_v2.configure_product')
        || has_permission('material_center.adaptation.manage')
        || has_permission('material_center.material.batch')
        || has_permission('material_center.material.edit');
}

function mc_pp_number(string $key, string $label, ?float $min = null, ?float $max = null): ?float
{
    $raw = trim((string)($_POST[$key] ?? ''));
    if ($raw === '') return null;
    if (!is_numeric($raw)) {
        throw new RuntimeException($label . ' 必须填写数字。');
    }
    $value = (float)$raw;
    if ($min !== null && $value < $min) {
        throw new RuntimeException($label . ' 不能小于 ' . $min . '。');
    }
    if ($max !== null && $value > $max) {
        throw new RuntimeException($label . ' 不能大于 ' . $max . '。');
    }
    return $value;
}

function mc_pp_text(string $key, int $maxLength = 200): string
{
    $value = trim((string)($_POST[$key] ?? ''));
    if ($value === '') return '';
    return mb_substr($value, 0, $maxLength);
}

function mc_pp_first_number(string $text, string $pattern): ?float
{
    if (preg_match($pattern, $text, $match)) {
        return (float)$match[1];
    }
    return null;
}

function mc_pp_first_int(string $text, string $pattern): ?int
{
    $value = mc_pp_first_number($text, $pattern);
    return $value === null ? null : (int)round($value);
}

function mc_pp_fill_number(array &$params, string $key, ?float $value): void
{
    if ($value === null) return;
    if (!array_key_exists($key, $params) || $params[$key] === null || $params[$key] === '') {
        $params[$key] = $value;
    }
}

function mc_pp_fill_int(array &$params, string $key, ?int $value): void
{
    if ($value === null) return;
    if (!array_key_exists($key, $params) || $params[$key] === null || $params[$key] === '') {
        $params[$key] = $value;
    }
}

function mc_pp_apply_spec_text_derivatives(array &$params): void
{
    $powerText = (string)($params['power_text'] ?? '');
    if ($powerText !== '') {
        $power = mc_pp_first_number($powerText, '/(\d+(?:\.\d+)?)\s*w\b/iu');
        mc_pp_fill_number($params, 'power_min_w', $power);
        mc_pp_fill_number($params, 'power_max_w', $power);
        $voltage = mc_pp_first_number($powerText, '/(\d+(?:\.\d+)?)\s*v\b/iu');
        mc_pp_fill_number($params, 'voltage_min_v', $voltage);
        mc_pp_fill_number($params, 'voltage_max_v', $voltage);
        $current = mc_pp_first_number($powerText, '/(\d+(?:\.\d+)?)\s*ma\b/iu');
        mc_pp_fill_number($params, 'current_min_ma', $current);
        mc_pp_fill_number($params, 'current_max_ma', $current);
    }

    $cutoutText = (string)($params['cutout_size_text'] ?? '');
    if ($cutoutText !== '') {
        mc_pp_fill_number($params, 'cutout_mm', mc_pp_first_number($cutoutText, '/(\d+(?:\.\d+)?)\s*mm\b/iu'));
    }

    $dimensionText = (string)($params['dimensions_text'] ?? '');
    if ($dimensionText !== '' && preg_match_all('/\d+(?:\.\d+)?/', $dimensionText, $matches) && count($matches[0]) >= 2) {
        mc_pp_fill_number($params, 'length_mm', (float)$matches[0][0]);
        mc_pp_fill_number($params, 'width_mm', (float)$matches[0][1]);
        if (isset($matches[0][2])) mc_pp_fill_number($params, 'height_mm', (float)$matches[0][2]);
    }

    $beamText = (string)($params['beam_angle_text'] ?? '');
    if ($beamText !== '') {
        mc_pp_fill_number($params, 'beam_angle', mc_pp_first_number($beamText, '/(\d+(?:\.\d+)?)\s*(?:°|度)?/u'));
    }

    $cctSource = trim((string)($params['cct_text'] ?? '') . ' ' . (string)($params['luminous_flux_text'] ?? ''));
    if ($cctSource !== '') {
        mc_pp_fill_int($params, 'cct_k', mc_pp_first_int($cctSource, '/(\d{4})\s*k\b/iu'));
    }

    $criText = (string)($params['cri_text'] ?? '');
    if ($criText !== '') {
        mc_pp_fill_number($params, 'cri_min', mc_pp_first_number($criText, '/(?:cri|ra)?\s*[≥>:：]?\s*(\d+(?:\.\d+)?)/iu'));
    }
}

function mc_pp_custom_fields(): array
{
    $labels = $_POST['custom_label'] ?? [];
    $values = $_POST['custom_value'] ?? [];
    $units = $_POST['custom_unit'] ?? [];
    $groups = $_POST['custom_group'] ?? [];
    if (!is_array($labels) || !is_array($values)) return [];
    $out = [];
    $max = min(80, count($labels));
    for ($i = 0; $i < $max; $i++) {
        $label = mb_substr(trim((string)($labels[$i] ?? '')), 0, 80);
        $value = mb_substr(trim((string)($values[$i] ?? '')), 0, 500);
        $unit = mb_substr(trim((string)(is_array($units) ? ($units[$i] ?? '') : '')), 0, 30);
        $group = mb_substr(trim((string)(is_array($groups) ? ($groups[$i] ?? '') : '')), 0, 80);
        if ($label === '' && $value === '') continue;
        if ($label === '') $label = '自定义参数';
        if ($value === '') continue;
        $out[] = [
            'label' => $label,
            'value' => $value,
            'unit' => $unit,
            'group' => $group !== '' ? $group : '自定义参数',
        ];
    }
    return $out;
}

function mc_pp_singapore_sync_after_save(array $product, array $user): array
{
    $legacyTable = (string)($product['legacy_table'] ?? '');
    $legacyId = (int)($product['legacy_id'] ?? 0);
    if ($legacyTable !== 'naming_models' || $legacyId <= 0) {
        return ['status' => 'skipped', 'message' => '不是命名中心产品，不触发新加坡同步。'];
    }
    if (!mc_table_exists('cc_channel_entity_links') || !mc_table_exists('cc_channel_outbox')) {
        return ['status' => 'skipped', 'message' => '商务中心发布队列表尚未就绪。'];
    }

    $link = db()->prepare(
        "SELECT sync_status,external_id,last_synced_at
         FROM cc_channel_entity_links
         WHERE channel_code='singapore' AND entity_type='published_product' AND entity_id=?
         ORDER BY id DESC LIMIT 1"
    );
    $link->execute([$legacyId]);
    $published = $link->fetch(PDO::FETCH_ASSOC);
    if (!$published || (string)($published['sync_status'] ?? '') !== 'published') {
        return ['status' => 'skipped', 'message' => '该产品尚未发布到新加坡网站，不自动同步。'];
    }

    $commercialBootstrap = dirname(__DIR__, 3) . '/commercial_center_v1/bootstrap.php';
    if (!is_file($commercialBootstrap)) {
        return ['status' => 'failed', 'message' => '商务中心同步入口不存在。'];
    }

    try {
        require_once $commercialBootstrap;
        $actor = [
            'id' => (int)($user['id'] ?? $user['user_id'] ?? 0),
            'username' => (string)($user['username'] ?? ''),
            'is_super_admin' => true,
            'source' => 'material_parameter_auto_sync',
        ];
        $service = new \Artdon\CommercialCenter\Services\SingaporeChannelService(db());
        $job = $service->queuePublishedProduct($legacyId, $actor);
        $sent = $service->send((int)($job['id'] ?? 0), $actor);
        return [
            'status' => (string)($sent['status'] ?? 'unknown'),
            'job_id' => (int)($sent['id'] ?? ($job['id'] ?? 0)),
            'external_reference' => (string)($sent['external_reference'] ?? ($published['external_id'] ?? '')),
            'message' => ((string)($sent['status'] ?? '') === 'sent') ? '新加坡网站参数已同步。' : '新加坡同步任务已更新。',
        ];
    } catch (Throwable $e) {
        return [
            'status' => 'failed',
            'message' => '新加坡网站同步失败：' . $e->getMessage(),
        ];
    }
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    mc_pp_response(false, '只允许 POST 保存产品参数。', [], 405);
}
if (!verify_csrf()) {
    mc_pp_response(false, '页面令牌已失效，请刷新后再保存。', [], 419);
}
if (!mc_pp_can_edit()) {
    mc_pp_response(false, '没有维护产品参数的权限。', [], 403);
}
if (!mc_table_exists('mc_products')) {
    mc_pp_response(false, '物料中心产品表尚未就绪。', [], 500);
}

$productId = (int)($_POST['product_id'] ?? 0);
if ($productId <= 0) {
    mc_pp_response(false, '缺少产品 ID。', [], 422);
}

try {
    $stmt = db()->prepare('SELECT id, legacy_table, legacy_id, product_code, product_name, snapshot_json FROM mc_products WHERE id=? LIMIT 1');
    $stmt->execute([$productId]);
    $product = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$product) {
        mc_pp_response(false, '没有找到这个产品。', [], 404);
    }

    $pairs = [
        ['power_min_w', 'power_max_w', '功率下限 W', '功率上限 W'],
        ['current_min_ma', 'current_max_ma', '电流下限 mA', '电流上限 mA'],
        ['voltage_min_v', 'voltage_max_v', '电压下限 V', '电压上限 V'],
    ];
    $params = [
        'product_type' => mc_pp_text('product_type', 80),
        'cutout_size_text' => mc_pp_text('cutout_size_text', 120),
        'dimensions_text' => mc_pp_text('dimensions_text', 160),
        'power_text' => mc_pp_text('power_text', 160),
        'luminous_flux_text' => mc_pp_text('luminous_flux_text', 160),
        'tilt_angle' => mc_pp_text('tilt_angle', 80),
        'rotation_angle' => mc_pp_text('rotation_angle', 80),
        'beam_angle_text' => mc_pp_text('beam_angle_text', 160),
        'cct_text' => mc_pp_text('cct_text', 160),
        'cri_text' => mc_pp_text('cri_text', 80),
        'ugr_text' => mc_pp_text('ugr_text', 80),
        'dimming_method_text' => mc_pp_text('dimming_method_text', 160),
        'protection_class' => mc_pp_text('protection_class', 80),
        'best_for' => mc_pp_text('best_for', 240),
        'power_min_w' => mc_pp_number('power_min_w', '功率下限 W', 0, 5000),
        'power_max_w' => mc_pp_number('power_max_w', '功率上限 W', 0, 5000),
        'current_min_ma' => mc_pp_number('current_min_ma', '电流下限 mA', 0, 50000),
        'current_max_ma' => mc_pp_number('current_max_ma', '电流上限 mA', 0, 50000),
        'voltage_min_v' => mc_pp_number('voltage_min_v', '电压下限 V', 0, 10000),
        'voltage_max_v' => mc_pp_number('voltage_max_v', '电压上限 V', 0, 10000),
        'cct_k' => mc_pp_number('cct_k', '色温 K', 1000, 20000),
        'cri_min' => mc_pp_number('cri_min', '最低显指 CRI', 0, 100),
        'beam_angle' => mc_pp_number('beam_angle', '光束角 °', 0, 180),
        'length_mm' => mc_pp_number('length_mm', '长度 mm', 0, 100000),
        'width_mm' => mc_pp_number('width_mm', '宽度 mm', 0, 100000),
        'height_mm' => mc_pp_number('height_mm', '高度 mm', 0, 100000),
        'cutout_mm' => mc_pp_number('cutout_mm', '开孔 mm', 0, 100000),
        'ip_rating' => mc_pp_text('ip_rating', 30),
        'installation_type' => mc_pp_text('installation_type', 80),
        'driver_type' => mc_pp_text('driver_type', 80),
        'dimming_mode' => mc_pp_text('dimming_mode', 120),
        'optical_size' => mc_pp_text('optical_size', 120),
        'notes' => mc_pp_text('notes', 800),
    ];
    if (($params['dimming_mode'] ?? '') === '' && ($params['dimming_method_text'] ?? '') !== '') {
        $params['dimming_mode'] = $params['dimming_method_text'];
    }
    mc_pp_apply_spec_text_derivatives($params);
    foreach ($pairs as [$minKey, $maxKey, $minLabel, $maxLabel]) {
        if ($params[$minKey] !== null && $params[$maxKey] !== null && $params[$minKey] > $params[$maxKey]) {
            throw new RuntimeException($minLabel . '不能大于' . $maxLabel . '。');
        }
    }

    $clean = [];
    foreach ($params as $key => $value) {
        if ($value === null || $value === '') continue;
        $clean[$key] = $value;
    }
    $customFields = mc_pp_custom_fields();
    if ($customFields) {
        $clean['custom_fields'] = $customFields;
    }
    $clean['updated_at'] = date('Y-m-d H:i:s');
    $user = mc_current_user();
    $clean['updated_by'] = (string)($user['real_name'] ?? $user['username'] ?? $user['id'] ?? 'system');

    $snapshot = json_decode((string)($product['snapshot_json'] ?? '{}'), true);
    if (!is_array($snapshot)) $snapshot = [];
    $snapshot['product_parameters'] = $clean;
    $snapshotJson = json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($snapshotJson === false) {
        throw new RuntimeException('产品参数 JSON 编码失败。');
    }
    $hash = hash('sha256', $snapshotJson);
    $update = db()->prepare('UPDATE mc_products SET snapshot_json=?, snapshot_hash=? WHERE id=?');
    $update->execute([$snapshotJson, $hash, $productId]);
    $singaporeSync = mc_pp_singapore_sync_after_save($product, $user);

    mc_pp_response(true, '产品参数已保存。', [
        'product_id' => $productId,
        'product_code' => (string)($product['product_code'] ?? ''),
        'product_parameters' => $clean,
        'singapore_sync' => $singaporeSync,
    ]);
} catch (Throwable $e) {
    mc_pp_response(false, $e->getMessage(), [], 422);
}
