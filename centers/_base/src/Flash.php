<?php
declare(strict_types=1);

namespace Artdon\Centers\Base;

/**
 * 一次性提示。表单提交成功后先跳转再显示，刷新页面不会重复提交，也不会重复显示。
 * 类型：success、error、warning、info。
 */
final class Flash
{
    private const KEY = '_centers_flash';

    public static function set(string $type, string $message): void
    {
        $_SESSION[self::KEY][] = ['type' => $type, 'message' => $message];
    }

    /** @return list<array{type: string, message: string}> */
    public static function take(): array
    {
        $items = $_SESSION[self::KEY] ?? [];
        unset($_SESSION[self::KEY]);
        return is_array($items) ? array_values($items) : [];
    }
}
