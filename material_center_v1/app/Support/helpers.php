<?php
declare(strict_types=1);

function mc_h(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function mc_current_user(): ?array
{
    try {
        $user = function_exists('current_user') ? current_user() : null;
        return is_array($user) && !empty($user['id']) ? $user : null;
    } catch (Throwable) {
        return null;
    }
}

function mc_table_exists(string $table): bool
{
    if (!preg_match('/^[A-Za-z0-9_]+$/', $table)) {
        return false;
    }
    try {
        $statement = db()->prepare(
            'SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? LIMIT 1'
        );
        $statement->execute([$table]);
        return (bool)$statement->fetchColumn();
    } catch (Throwable) {
        return false;
    }
}

function mc_asset_url(mixed $path): string
{
    $path = ltrim(trim((string)$path), '/');
    if ($path === '' || str_contains($path, '..') || !preg_match('#^(uploads|assets)/[A-Za-z0-9_./-]+$#', $path)) {
        return '';
    }
    return '../' . $path;
}
