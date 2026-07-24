<?php
declare(strict_types=1);

namespace Artdon\CommercialCenter\Support;

final class Logger
{
    public static function error(string $message, array $context = []): void
    {
        self::write('ERROR', $message, $context);
    }

    public static function info(string $message, array $context = []): void
    {
        self::write('INFO', $message, $context);
    }

    private static function write(string $level, string $message, array $context): void
    {
        $directory = defined('CC_STORAGE') ? CC_STORAGE . '/logs' : dirname(__DIR__, 2) . '/storage/logs';
        if (!is_dir($directory) || !is_writable($directory)) {
            error_log('[commercial_center_v1] ' . $level . ' ' . $message);
            return;
        }
        unset($context['password'], $context['session'], $context['sql']);
        $line = json_encode([
            'time' => date(DATE_ATOM),
            'level' => $level,
            'message' => $message,
            'context' => $context,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        file_put_contents($directory . '/app-' . date('Y-m-d') . '.log', $line . PHP_EOL, FILE_APPEND | LOCK_EX);
    }
}
