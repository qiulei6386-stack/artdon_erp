<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    echo 'not found';
    exit;
}

require_once __DIR__ . '/lib.php';

date_default_timezone_set('Asia/Shanghai');

try {
    $pdo = yf_pdo();
    yf_install($pdo);
} catch (Throwable $e) {
    fwrite(STDERR, "建表失败：" . $e->getMessage() . "\n");
    exit(1);
}

echo "易飞镜像表已就绪：yf_invmb、yf_bommd、yf_cstmb、yf_sync_batches\n";
echo "已补上单位标准材料成本等会计栏位，以及 BOM 底数、损耗率。\n";
echo "再次执行是幂等的，不会清空已同步的数据。\n";
