<?php
declare(strict_types=1);

namespace Artdon\CommercialCenter\Controllers;

use Artdon\CommercialCenter\Adapters\LegacyAuthAdapter;
use Artdon\CommercialCenter\Adapters\LegacyPermissionAdapter;
use Artdon\CommercialCenter\Services\AdapterRegistry;
use Artdon\CommercialCenter\Services\DatabaseHealthService;
use Artdon\CommercialCenter\Services\GitStatusService;
use Artdon\CommercialCenter\Services\OperationsDashboardService;
use Artdon\CommercialCenter\Services\CatalogCenterService;

final class DashboardController
{
    public function status(): array
    {
        $auth = (new LegacyAuthAdapter())->currentUser();
        $permission = (new LegacyPermissionAdapter())->check('commercial_center.view');
        $database = (new DatabaseHealthService())->check();
        $git = (new GitStatusService())->summary();
        $adapters = (new AdapterRegistry())->statuses();
        $operations = (new OperationsDashboardService())->load($auth);
        $search = trim((string)($_GET['q'] ?? ''));
        $category = trim((string)($_GET['category'] ?? ''));
        $catalogService = new CatalogCenterService();
        $requestedView = (string)($_GET['view'] ?? 'dashboard');
        $emptyCatalog = ['status' => 'not_requested', 'rows' => [], 'categories' => [], 'permission' => ''];
        $products = $requestedView === 'products'
            ? $catalogService->products($auth, $search, $category)
            : $emptyCatalog;
        $materials = $requestedView === 'materials'
            ? $catalogService->materials($auth, $search, $category)
            : $emptyCatalog;
        $allAdaptersAvailable = count(array_filter(
            $adapters,
            static fn(array $status): bool => $status['status'] !== 'available'
        )) === 0;
        $userLabel = $auth['authenticated'] && $auth['user']
            ? $auth['user']['display_name'] . '（' . $auth['user']['username'] . '）'
            : '未登录';
        $permissionLabel = $auth['authenticated']
            ? ($permission['allowed'] ? '已通过旧权限只读判断' : '未配置细分权限')
            : '等待统一登录';
        return [
            'summary' => [
                ['label' => '当前登录用户', 'value' => $userLabel, 'detail' => $auth['status'], 'tone' => $auth['authenticated'] ? 'good' : 'neutral'],
                ['label' => '权限读取结果', 'value' => $permissionLabel, 'detail' => $permission['source'], 'tone' => $permission['allowed'] ? 'good' : 'neutral'],
                ['label' => '数据库连接', 'value' => $database['status'], 'detail' => $database['database'], 'tone' => $database['ok'] ? 'good' : 'bad'],
                ['label' => '适配器状态', 'value' => $allAdaptersAvailable ? '全部可检测' : '存在待接入项', 'detail' => '所有适配器均强制只读', 'tone' => $allAdaptersAvailable ? 'good' : 'neutral'],
                ['label' => '安全隔离', 'value' => '独立目录 / cc_ 表规划', 'detail' => '旧文件与旧表零写入', 'tone' => 'good'],
            ],
            'adapters' => $adapters,
            'database' => $database,
            'auth' => $auth,
            'permission' => $permission,
            'git' => $git,
            'operations' => $operations,
            'products' => $products,
            'materials' => $materials,
            'filters' => ['q' => $search, 'category' => $category],
            'isolation' => ['ok' => $database['ok'] && $allAdaptersAvailable],
            'request_path' => (string)($_SERVER['REQUEST_URI'] ?? '/artdon_erp/commercial_center_v1/'),
            'modules' => [
                '运营工作台', '产品与配置', '库存 SKU', '标准报价', '定制项目',
                '新加坡发布', '订单中心', '包装与单证', '价格与佣金', '系统集成',
            ],
        ];
    }
}
