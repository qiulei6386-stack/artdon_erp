<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/bootstrap.php';

use Artdon\CommercialCenter\Adapters\LegacyAuthAdapter;
use Artdon\CommercialCenter\Services\StockQuoteService;
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
if (empty($_SESSION['cc_stock_quote_csrf'])) {
    $_SESSION['cc_stock_quote_csrf'] = bin2hex(random_bytes(32));
}
$service = new StockQuoteService();

try {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
        $quoteId = max(0, (int)($_GET['quote_id'] ?? 0));
        $reply([
            'ok' => true,
            'csrf' => $_SESSION['cc_stock_quote_csrf'],
            'data' => $service->bootstrap((int)$actor['id'], (int)($_GET['customer_id'] ?? 0)),
            'quote' => $quoteId > 0 ? $service->open($quoteId, $actor) : null,
        ]);
    }
    $input = json_decode((string)file_get_contents('php://input'), true);
    if (!is_array($input)) {
        $reply(['ok' => false, 'message' => 'JSON 请求无效。'], 400);
    }
    if (!hash_equals((string)$_SESSION['cc_stock_quote_csrf'], (string)($input['csrf'] ?? ''))) {
        $reply(['ok' => false, 'message' => '请求校验失败，请刷新页面。'], 419);
    }
    $action = (string)($input['action'] ?? '');
    if ($action === 'prepare_item') {
        $item = $service->prepareItem(
            is_array($input['item'] ?? null) ? $input['item'] : [],
            (int)$actor['id'],
            (int)($input['customer_id'] ?? 0),
            (string)($input['sales_channel'] ?? 'guangzhou_direct')
        );
        $reply(['ok' => true, 'item' => $item]);
    }
    if ($action === 'save') {
        $quote = $service->save(is_array($input['quote'] ?? null) ? $input['quote'] : [], $actor);
        $reply(['ok' => true, 'quote' => $quote, 'message' => '库存品报价草稿已保存。']);
    }
    if ($action === 'submit') {
        $quote = $service->submit((int)($input['quote_id'] ?? 0), $actor);
        $reply(['ok' => true, 'quote' => $quote, 'message' => '库存品报价已提交审核。']);
    }
    $reply(['ok' => false, 'message' => '不支持的操作。'], 400);
} catch (InvalidArgumentException $error) {
    $reply(['ok' => false, 'message' => $error->getMessage()], 422);
} catch (RuntimeException $error) {
    $reply(['ok' => false, 'message' => $error->getMessage()], str_contains($error->getMessage(), '权限') ? 403 : 409);
} catch (Throwable $error) {
    Logger::error('Stock quote API failed', ['type' => get_class($error), 'message' => $error->getMessage()]);
    $reply(['ok' => false, 'message' => '库存品报价服务暂时不可用，错误已记录。'], 500);
}
