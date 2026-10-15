<?php
declare(strict_types=1);

/** 导出客户：按列表当前标签和筛选下载 CSV。 */

require_once __DIR__ . '/bootstrap.php';

use Artdon\Centers\Base\Auth;
use Artdon\Centers\Base\Flash;
use Artdon\Centers\Base\Http;
use Artdon\Centers\Base\Layout;
use Artdon\Centers\Base\Url;
use Artdon\Centers\Customer\CustomerExport;

$user = Auth::requirePage();
Auth::requirePermissionPage('customer.export');
$str = static function (string $key): string {
    $v = $_GET[$key] ?? '';
    return is_string($v) ? trim($v) : '';
};
$tab = $str('tab') !== '' ? $str('tab') : 'mine';
$filters = [
    'q' => $str('q'), 'country' => $str('country'), 'level' => $str('level'), 'lifecycle' => $str('lifecycle'), 'owner_id' => (int) $str('owner_id'),
    'group_id' => (int) $str('group_id'), 'follow' => $str('follow'), 'created' => $str('created'), 'no_contact' => $str('no_contact') === '1' ? '1' : '', 'sort' => $str('sort'),
];
try {
    $rows = (new CustomerExport($user))->rows($tab, $filters);
} catch (Throwable $e) {
    Flash::set('error', $e instanceof RuntimeException || $e instanceof \Artdon\Centers\Base\PermissionException ? $e->getMessage() : '导出失败');
    Http::redirect(Url::center('customer', 'index.php?' . http_build_query(array_filter(['tab' => $tab] + $filters))));
}
CustomerExport::send($rows, '客户_' . date('Ymd_His') . '.csv');
