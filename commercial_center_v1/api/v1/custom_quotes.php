<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/bootstrap.php';

use Artdon\CommercialCenter\Adapters\LegacyAuthAdapter;
use Artdon\CommercialCenter\Services\CustomQuoteService;
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
    $reply(['ok'=>false,'status'=>'unauthenticated','message'=>'需要统一登录。'], 401);
}
$actor = $authentication['user'];
if (empty($_SESSION['cc_custom_quote_csrf'])) {
    $_SESSION['cc_custom_quote_csrf'] = bin2hex(random_bytes(32));
}
$service = new CustomQuoteService();

try {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
        $quoteId = max(0, (int)($_GET['quote_id'] ?? 0));
        $reply([
            'ok'=>true,
            'csrf'=>$_SESSION['cc_custom_quote_csrf'],
            'data'=>$service->bootstrap((int)$actor['id']),
            'quote'=>$quoteId > 0 ? $service->open($quoteId, $actor) : null,
        ]);
    }
    $multipart = str_starts_with((string)($_SERVER['CONTENT_TYPE'] ?? ''), 'multipart/form-data');
    $input = $multipart ? $_POST : json_decode((string)file_get_contents('php://input'), true);
    if (!is_array($input)) {
        $reply(['ok'=>false,'message'=>'请求数据无效。'], 400);
    }
    if (!hash_equals((string)$_SESSION['cc_custom_quote_csrf'], (string)($input['csrf'] ?? ''))) {
        $reply(['ok'=>false,'message'=>'请求校验失败，请刷新页面。'], 419);
    }
    $action = (string)($input['action'] ?? '');
    if ($action === 'save') {
        $quote = $service->save(is_array($input['quote'] ?? null) ? $input['quote'] : [], $actor);
        $reply(['ok'=>true,'quote'=>$quote,'message'=>'定制品报价草稿已保存。']);
    }
    if ($action === 'submit') {
        $reply(['ok'=>true,'quote'=>$service->submit((int)($input['quote_id'] ?? 0), $actor),'message'=>'已提交审核。']);
    }
    if ($action === 'approve') {
        $reply(['ok'=>true,'quote'=>$service->approve((int)($input['quote_id'] ?? 0), $actor, (string)($input['reason'] ?? '定制品报价审核通过'))]);
    }
    if ($action === 'handoff') {
        $reply(['ok'=>true,'handoff'=>$service->handoff((int)($input['quote_id'] ?? 0), (string)($input['type'] ?? ''), $actor)]);
    }
    if ($action === 'upload') {
        if (!isset($_FILES['file']) || !is_array($_FILES['file'])) {
            throw new InvalidArgumentException('请选择上传文件。');
        }
        $file = $service->upload(
            (int)($input['quote_id'] ?? 0),
            (int)($input['item_id'] ?? 0) ?: null,
            (string)($input['file_type'] ?? 'document'),
            $_FILES['file'],
            $actor
        );
        $reply(['ok'=>true,'file'=>$file,'quote'=>$service->open((int)$input['quote_id'], $actor),'message'=>'附件已上传。']);
    }
    if ($action === 'delete_file') {
        $service->deleteFile((int)$input['quote_id'], (int)$input['file_id'], !empty($input['item_file']), $actor);
        $reply(['ok'=>true,'message'=>'附件已删除。']);
    }
    if ($action === 'reorder_files') {
        $service->reorderFiles((int)$input['quote_id'], is_array($input['ids'] ?? null) ? $input['ids'] : [], !empty($input['item_file']), $actor);
        $reply(['ok'=>true,'message'=>'附件顺序已保存。']);
    }
    $reply(['ok'=>false,'message'=>'不支持的操作。'], 400);
} catch (InvalidArgumentException $error) {
    $reply(['ok'=>false,'message'=>$error->getMessage()], 422);
} catch (RuntimeException $error) {
    $reply(['ok'=>false,'message'=>$error->getMessage()], str_contains($error->getMessage(), '权限') ? 403 : 409);
} catch (Throwable $error) {
    Logger::error('Custom quote API failed', ['type'=>get_class($error),'message'=>$error->getMessage()]);
    $reply(['ok'=>false,'message'=>'定制品报价服务暂时不可用，错误已记录。'], 500);
}
