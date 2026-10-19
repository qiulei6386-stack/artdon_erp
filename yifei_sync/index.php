<?php
declare(strict_types=1);

require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/page.php';

date_default_timezone_set('Asia/Shanghai');

$preview = yf_local_preview();
$canCost = false;
$userLabel = '';
if ($preview) {
    $canCost = true;
    $userLabel = '本地预览';
} else {
    require_once __DIR__ . '/bootstrap.php';
    $user = yf_require_bom_user();
    $canCost = function_exists('has_permission') && has_permission('bom.cost_view');
    $userLabel = (string)($user['display_name'] ?? ($user['real_name'] ?? ($user['username'] ?? '')));
}

$notice = '';
$q = '';
$code = '';
try {
    $q = yf_text((string)($_GET['q'] ?? ''), 80, '关键词', false);
} catch (InvalidArgumentException $e) {
    $notice = $e->getMessage();
}
try {
    $code = yf_text((string)($_GET['code'] ?? ''), YF_CODE_MAX, '品号', false);
} catch (InvalidArgumentException $e) {
    $notice = $e->getMessage();
}

$ready = false;
$rows = array();
$detail = null;
$mirror = '';
try {
    $pdo = yf_pdo();
    $found = yf_search($pdo, $q);
    $ready = !empty($found['ready']);
    $rows = $found['rows'];
    if ($ready && $code !== '') {
        $detail = yf_detail($pdo, $code);
        if (!$detail && $notice === '') {
            $notice = '没有这个品号的同步数据。';
        }
    } elseif ($ready && $q !== '' && count($rows) === 1) {
        $code = (string)$rows[0]['item_code'];
        $detail = yf_detail($pdo, $code);
    }
    if ($ready) {
        $mirror = yf_mirror_synced_at($pdo);
    }
} catch (Throwable $e) {
    $ready = false;
    $rows = array();
    if ($notice === '') {
        $notice = '暂时读不到镜像库。请确认数据库可连接，并已执行 php yifei_sync/migrate.php。';
    }
    error_log('yifei_sync page: ' . $e->getMessage());
}

header('Content-Type: text/html; charset=utf-8');
echo yf_render_page(array(
    'q' => $q,
    'code' => $code,
    'rows' => $rows,
    'detail' => $detail,
    'can_cost' => $canCost,
    'ready' => $ready,
    'mirror_synced_at' => $mirror,
    'user_label' => $userLabel,
    'notice' => $notice,
    'recent' => $q === '',
));
