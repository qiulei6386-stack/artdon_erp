<?php
declare(strict_types=1);

namespace Artdon\Centers\Base;

use stdClass;

/**
 * 请求与响应。接口返回格式与旧系统 api_response() 相同：
 *   {"success": bool, "message": string, "data": object|array, "error_code": string}
 * 区别是同时设置正确的 HTTP 状态码。
 */
final class Http
{
    public static function method(): string
    {
        return strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    }

    public static function isPost(): bool
    {
        return self::method() === 'POST';
    }

    /**
     * 读取输入。POST 时合并 JSON 请求体和表单字段（JSON 优先）；GET 时读取查询参数。
     *
     * @return array<string, mixed>
     */
    public static function input(): array
    {
        if (!self::isPost()) {
            return $_GET;
        }
        $data = $_POST;
        $type = (string) ($_SERVER['CONTENT_TYPE'] ?? '');
        if (stripos($type, 'application/json') !== false) {
            $json = json_decode((string) file_get_contents('php://input'), true);
            if (is_array($json)) {
                $data = array_merge($data, $json);
            }
        }
        return $data;
    }

    /** 不是指定方法时返回 405。 */
    public static function requireMethod(string ...$methods): void
    {
        if (!in_array(self::method(), $methods, true)) {
            header('Allow: ' . implode(', ', $methods));
            self::fail(ErrorCode::METHOD_NOT_ALLOWED, '请求方式不正确');
        }
    }

    /** 校验 CSRF 令牌（表单字段 csrf_token 或请求头 X-CSRF-Token），沿用旧系统的 verify_csrf()。 */
    public static function csrfValid(): bool
    {
        return function_exists('verify_csrf') && verify_csrf();
    }

    public static function requireCsrf(): void
    {
        if (!self::csrfValid()) {
            self::fail(ErrorCode::CSRF_INVALID, '页面已过期，请刷新后重试');
        }
    }

    /** @param mixed $data */
    public static function ok($data = [], string $message = ''): void
    {
        self::json(true, $message, $data, '', 200);
    }

    /**
     * @param mixed $data
     */
    public static function fail(string $code, string $message, $data = [], ?int $status = null): void
    {
        self::json(false, $message, $data, $code, $status ?? ErrorCode::status($code));
    }

    /** @param mixed $data */
    public static function json(bool $success, string $message, $data, string $code, int $status): void
    {
        if (!headers_sent()) {
            http_response_code($status);
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store');
        }
        echo json_encode([
            'success' => $success,
            'message' => $message,
            'data' => $data === [] || $data === null ? new stdClass() : $data,
            'error_code' => $code,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    /** 跳转。表单提交后用 303，浏览器刷新不会重复提交。 */
    public static function redirect(string $url, int $status = 303): void
    {
        header('Location: ' . $url, true, $status);
        exit;
    }

    /** 当前请求是否是接口请求（脚本名为 api.php 或要求 JSON）。 */
    public static function wantsJson(): bool
    {
        if (basename((string) ($_SERVER['SCRIPT_NAME'] ?? '')) === 'api.php') {
            return true;
        }
        return stripos((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json') !== false;
    }
}
