<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/bootstrap.php';

use Artdon\MaterialCenter\Adapters\LegacyAuthAdapter;
use Artdon\MaterialCenter\Security\PermissionService;
use Artdon\MaterialCenter\Services\ImportService;

header('Cache-Control: no-store');

try {
    $user = (new LegacyAuthAdapter())->current();
    (new PermissionService())->require($user, 'material_center.import');
    $service = new ImportService();

    if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['action'] ?? '') === 'errors_csv') {
        $rows = $service->errors((string)($_GET['task_uuid'] ?? ''));
        $safe = static function (mixed $value): string {
            $text = (string)$value;
            return preg_match('/^[=+\-@]/u', $text) ? "'" . $text : $text;
        };
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="material-import-errors.csv"');
        $output = fopen('php://output', 'wb');
        fwrite($output, "\xEF\xBB\xBF");
        fputcsv($output, ['行号', '字段', '错误', '原始数据']);
        foreach ($rows as $row) {
            fputcsv($output, [
                $safe($row['row_number'] ?? ''),
                $safe($row['field_code'] ?? ''),
                $safe($row['error_message'] ?? ''),
                $safe($row['raw_value'] ?? ''),
            ]);
        }
        fclose($output);
        exit;
    }

    header('Content-Type: application/json; charset=UTF-8');
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !verify_csrf((string)($_POST['csrf_token'] ?? ''))) {
        throw new RuntimeException('安全令牌已过期。', 419);
    }

    $action = (string)($_POST['action'] ?? 'upload');
    if ($action === 'upload') {
        $data = $service->upload($_FILES['file'] ?? [], $user->id);
    } elseif ($action === 'execute') {
        $data = $service->execute((string)($_POST['task_uuid'] ?? ''), $user->id);
    } elseif ($action === 'errors') {
        $data = $service->errors((string)($_POST['task_uuid'] ?? ''));
    } else {
        throw new RuntimeException('操作无效。');
    }
    echo json_encode(['ok' => true, 'data' => $data], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code($e->getCode() >= 400 && $e->getCode() < 600 ? $e->getCode() : 422);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(['ok' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
