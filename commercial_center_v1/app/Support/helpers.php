<?php
declare(strict_types=1);

function cc_h(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function cc_public_path(string $path): string
{
    $path = '/' . ltrim($path, '/');
    return '/artdon_erp/commercial_center_v1' . $path;
}

function cc_legacy_asset_url(mixed $path): string
{
    $path = ltrim(trim((string)$path), '/');
    if ($path === '' || str_contains($path, '..') || !preg_match('#^(uploads|assets)/[A-Za-z0-9_./-]+$#', $path)) {
        return '';
    }
    return '../' . $path;
}
