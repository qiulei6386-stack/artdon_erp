<?php
declare(strict_types=1);

namespace Artdon\Centers\Base;

/**
 * 接口错误码。所有中心统一使用，前端按错误码判断，不按提示文字判断。
 */
final class ErrorCode
{
    public const AUTH_REQUIRED = 'AUTH_REQUIRED';
    public const ACCOUNT_INACTIVE = 'ACCOUNT_INACTIVE';
    public const PERMISSION_DENIED = 'PERMISSION_DENIED';
    public const CSRF_INVALID = 'CSRF_INVALID';
    public const METHOD_NOT_ALLOWED = 'METHOD_NOT_ALLOWED';
    public const VALIDATION_FAILED = 'VALIDATION_FAILED';
    public const NOT_FOUND = 'NOT_FOUND';
    public const CONFLICT = 'CONFLICT';
    public const BAD_REQUEST = 'BAD_REQUEST';
    public const UNKNOWN_ACTION = 'UNKNOWN_ACTION';
    public const SERVER_ERROR = 'SERVER_ERROR';

    /** 错误码对应的 HTTP 状态。 */
    public static function status(string $code): int
    {
        switch ($code) {
            case self::AUTH_REQUIRED:
                return 401;
            case self::ACCOUNT_INACTIVE:
            case self::PERMISSION_DENIED:
            case self::CSRF_INVALID:
                return 403;
            case self::NOT_FOUND:
            case self::UNKNOWN_ACTION:
                return 404;
            case self::METHOD_NOT_ALLOWED:
                return 405;
            case self::CONFLICT:
                return 409;
            case self::VALIDATION_FAILED:
                return 422;
            case self::SERVER_ERROR:
                return 500;
            default:
                return 400;
        }
    }
}
