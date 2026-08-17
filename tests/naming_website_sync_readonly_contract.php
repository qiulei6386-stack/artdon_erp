<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$src = file_get_contents($root . '/naming.php');
if ($src === false) {
    throw new RuntimeException('Cannot read naming.php');
}

$checks = [
    'version identifies readonly release' => str_contains($src, "const NAMING_VERSION = '3.0.8.35';"),
    'save rejects website models' => str_contains($src, "if (nm_is_website_row(\$old)) throw new RuntimeException('官网同步型号已锁定，请在香港官网修改；命名中心会自动同步更新。');"),
    'disable and enable reject website models' => str_contains($src, "if (nm_is_website_row(\$row)) throw new RuntimeException('官网同步型号已锁定，不能在命名中心停用或恢复；请在香港官网修改。');"),
    'delete rejects website models' => str_contains($src, "if (\$row && nm_is_website_row(\$row)) throw new RuntimeException('官网同步型号已锁定，不能在命名中心删除；请在香港官网修改。');"),
    'website rows show lock action' => substr_count($src, '官网锁定') >= 3,
    'audit explains official-site editing' => str_contains($src, '这是官网同步型号，已在命名中心锁定；请在香港官网修改，保存后会自动同步回来。'),
    'stale edit entry is blocked client-side' => str_contains($src, "if(r.is_website_sync){ closeModal('modelModal'); alert('官网同步型号已锁定，请在香港官网修改；命名中心会自动同步更新。'); return; }"),
    'legacy delete prompt no longer recommends disable' => !str_contains($src, '官网同步型号不建议本地硬删除；如果官网还存在，下次同步会重新回来。请使用“停用”。'),
];

$failed = [];
foreach ($checks as $name => $ok) {
    if (!$ok) $failed[] = $name;
}
if ($failed) {
    fwrite(STDERR, "Naming website readonly contract failed:\n- " . implode("\n- ", $failed) . "\n");
    exit(1);
}

echo "Naming website readonly contract passed (" . count($checks) . " checks)\n";
