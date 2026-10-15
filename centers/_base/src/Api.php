<?php
declare(strict_types=1);

namespace Artdon\Centers\Base;

use RuntimeException;
use Throwable;

/**
 * 接口路由。每个中心的 api.php 只需要列出动作：
 *
 *   Api::run([
 *       'account_create' => ['method' => 'POST', 'handler' => function (array $input, array $user) { ... return [...]; }],
 *   ]);
 *
 * 统一处理：必须登录；POST 必须带 CSRF 令牌；可选 permission 先检查权限；
 * 异常转成统一的错误响应（校验错误 422 并带 data.errors，没权限 403，不存在 404，业务错误 400）。
 * handler 返回数组作为 data；返回值里的 message 键作为提示文字。
 */
final class Api
{
    /** @param array<string, array{method?: string, permission?: string, handler: callable}> $routes */
    public static function run(array $routes): void
    {
        $action = (string) ($_GET['action'] ?? '');
        if (!isset($routes[$action])) {
            Http::fail(ErrorCode::UNKNOWN_ACTION, '未知操作：' . $action);
        }
        $route = $routes[$action];
        $method = strtoupper((string) ($route['method'] ?? 'GET'));
        Http::requireMethod($method);
        $user = Auth::requireApi();
        if ($method !== 'GET') {
            Http::requireCsrf();
        }
        if (!empty($route['permission'])) {
            Auth::requirePermissionApi((string) $route['permission']);
        }
        try {
            $result = ($route['handler'])(Http::input(), $user);
        } catch (ValidationException $e) {
            Http::fail(ErrorCode::VALIDATION_FAILED, $e->getMessage(), ['errors' => $e->fieldErrors()]);
        } catch (PermissionException $e) {
            Http::fail(ErrorCode::PERMISSION_DENIED, $e->getMessage());
        } catch (NotFoundException $e) {
            Http::fail(ErrorCode::NOT_FOUND, $e->getMessage());
        } catch (\PDOException $e) {
            error_log('centers api ' . $action . ': ' . $e->getMessage());
            Http::fail(ErrorCode::SERVER_ERROR, '数据库操作失败，请稍后重试');
        } catch (RuntimeException $e) {
            Http::fail(ErrorCode::BAD_REQUEST, $e->getMessage());
        } catch (Throwable $e) {
            error_log('centers api ' . $action . ': ' . get_class($e) . ': ' . $e->getMessage());
            Http::fail(ErrorCode::SERVER_ERROR, '系统出错了，请稍后重试');
        }
        $result = is_array($result) ? $result : [];
        $message = (string) ($result['message'] ?? '操作成功');
        unset($result['message']);
        Http::ok($result, $message);
    }
}
