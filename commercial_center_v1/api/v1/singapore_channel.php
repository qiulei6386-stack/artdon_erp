<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/bootstrap.php';

use Artdon\CommercialCenter\Adapters\LegacyAuthAdapter;
use Artdon\CommercialCenter\Services\SingaporeChannelService;
use Artdon\CommercialCenter\Support\Logger;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');
header('X-Content-Type-Options: nosniff');

$reply = static function (array $payload, int $status = 200): never {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
};
$authentication = (new LegacyAuthAdapter())->currentUser();
if (!$authentication['authenticated'] || !is_array($authentication['user'])) {
    $reply(['ok' => false, 'message' => '需要统一登录。'], 401);
}
$actor = $authentication['user'];
if (empty($_SESSION['cc_singapore_channel_csrf'])) {
    $_SESSION['cc_singapore_channel_csrf'] = bin2hex(random_bytes(32));
}
$service = new SingaporeChannelService();

try {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
        $reply([
            'ok' => true,
            'csrf' => $_SESSION['cc_singapore_channel_csrf'],
            'data' => $service->dashboard($actor),
        ]);
    }
    $input = json_decode((string)file_get_contents('php://input'), true);
    if (!is_array($input)) $reply(['ok' => false, 'message' => 'JSON 请求无效。'], 400);
    if (!hash_equals((string)$_SESSION['cc_singapore_channel_csrf'], (string)($input['csrf'] ?? ''))) {
        $reply(['ok' => false, 'message' => '请求校验失败，请刷新页面。'], 419);
    }
    $action = (string)($input['action'] ?? '');
    if ($action === 'save_package') {
        $package = $service->savePackage(is_array($input['package'] ?? null) ? $input['package'] : [], $actor);
        $reply(['ok' => true, 'package' => $package, 'message' => '新加坡公开套餐草稿已保存。']);
    }
    if ($action === 'queue_product') {
        $job = $service->queueProduct((int)($input['package_id'] ?? 0), $actor);
        $reply(['ok' => true, 'job' => $job, 'message' => '产品已进入待发送队列。']);
    }
    if ($action === 'queue_order') {
        $job = $service->queueAssistedOrder((int)($input['quote_id'] ?? 0), $actor);
        $reply(['ok' => true, 'job' => $job, 'message' => '代客订单已进入待发送队列。']);
    }
    if ($action === 'simulate') {
        $job = $service->simulate((int)($input['outbox_id'] ?? 0), $actor);
        $reply(['ok' => true, 'job' => $job, 'message' => '模拟发送完成；没有连接真实网站。']);
    }
    if ($action === 'retry') {
        $job = $service->retry((int)($input['outbox_id'] ?? 0), $actor);
        $reply(['ok' => true, 'job' => $job, 'message' => '失败记录已重新进入待发送队列。']);
    }
    $reply(['ok' => false, 'message' => '不支持的操作。'], 400);
} catch (InvalidArgumentException $error) {
    $reply(['ok' => false, 'message' => $error->getMessage()], 422);
} catch (RuntimeException $error) {
    $reply(['ok' => false, 'message' => $error->getMessage()], str_contains($error->getMessage(), '权限') ? 403 : 409);
} catch (Throwable $error) {
    Logger::error('Singapore channel API failed', ['type' => get_class($error), 'message' => $error->getMessage()]);
    $reply(['ok' => false, 'message' => '新加坡渠道服务暂时不可用，错误已记录。'], 500);
}
