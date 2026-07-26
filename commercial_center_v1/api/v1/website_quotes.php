<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/bootstrap.php';

use Artdon\CommercialCenter\Adapters\LegacyAuthAdapter;
use Artdon\CommercialCenter\Services\WebsiteQuoteService;
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
    $reply(['ok' => false, 'status' => 'unauthenticated', 'message' => '需要统一登录。'], 401);
}
$actor = $authentication['user'];
if (empty($_SESSION['cc_website_quote_csrf'])) {
    $_SESSION['cc_website_quote_csrf'] = bin2hex(random_bytes(32));
}
$service = new WebsiteQuoteService();

try {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
        $quoteId = max(0, (int)($_GET['quote_id'] ?? 0));
        $reply([
            'ok' => true,
            'csrf' => $_SESSION['cc_website_quote_csrf'],
            'data' => $service->bootstrap((int)$actor['id']),
            'quote' => $quoteId > 0 ? $service->open($quoteId, $actor) : null,
        ]);
    }
    $input = json_decode((string)file_get_contents('php://input'), true);
    if (!is_array($input)) {
        $reply(['ok' => false, 'message' => 'JSON 请求无效。'], 400);
    }
    if (!hash_equals((string)$_SESSION['cc_website_quote_csrf'], (string)($input['csrf'] ?? ''))) {
        $reply(['ok' => false, 'message' => '请求校验失败，请刷新页面。'], 419);
    }
    $action = (string)($input['action'] ?? '');
    if ($action === 'import' || $action === 'sales_proxy') {
        $result = $service->import(
            is_array($input['order'] ?? null) ? $input['order'] : [],
            $actor,
            $action === 'sales_proxy' ? 'sales_proxy' : 'website_import'
        );
        $reply(['ok' => true, 'result' => $result, 'message' => $result['duplicate'] ? '该网站订单已导入。' : '网站订单已导入并进入待审核。']);
    }
    if ($action === 'review') {
        $quote = $service->review(
            (int)($input['quote_id'] ?? 0),
            is_array($input['changes'] ?? null) ? $input['changes'] : [],
            $actor,
            trim((string)($input['reason'] ?? '网站订单审核调整'))
        );
        $reply(['ok' => true, 'quote' => $quote, 'message' => '审核调整已保存。']);
    }
    if ($action === 'request_unlock') {
        $id = $service->requestUnlock(
            (int)($input['quote_id'] ?? 0),
            (int)($input['item_id'] ?? 0),
            (string)($input['field'] ?? ''),
            $input['requested_value'] ?? null,
            (string)($input['reason'] ?? ''),
            $actor
        );
        $reply(['ok' => true, 'request_id' => $id, 'message' => '解锁申请已提交。']);
    }
    if ($action === 'review_unlock') {
        $service->reviewUnlock(
            (int)($input['request_id'] ?? 0),
            !empty($input['approved']),
            (string)($input['note'] ?? ''),
            $actor
        );
        $reply(['ok' => true, 'message' => '解锁申请已处理。']);
    }
    if ($action === 'approve') {
        $reply(['ok' => true, 'quote' => $service->approve((int)$input['quote_id'], $actor, (string)($input['reason'] ?? ''))]);
    }
    if ($action === 'reject') {
        $reply(['ok' => true, 'quote' => $service->reject((int)$input['quote_id'], $actor, (string)($input['reason'] ?? ''))]);
    }
    if ($action === 'open') {
        $reply(['ok' => true, 'quote' => $service->open((int)$input['quote_id'], $actor)]);
    }
    $reply(['ok' => false, 'message' => '不支持的操作。'], 400);
} catch (InvalidArgumentException $error) {
    $reply(['ok' => false, 'message' => $error->getMessage()], 422);
} catch (RuntimeException $error) {
    $reply(['ok' => false, 'message' => $error->getMessage()], str_contains($error->getMessage(), '权限') ? 403 : 409);
} catch (Throwable $error) {
    Logger::error('Website quote API failed', ['type' => get_class($error), 'message' => $error->getMessage()]);
    $reply(['ok' => false, 'message' => '网站报价服务暂时不可用，错误已记录。'], 500);
}
