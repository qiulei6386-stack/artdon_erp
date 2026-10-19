<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/yifei_sync/lib.php';
require_once dirname(__DIR__) . '/yifei_sync/page.php';

$fail = 0;

function yf_check($ok, string $message): void
{
    global $fail;
    if ($ok) {
        echo "OK {$message}\n";
        return;
    }
    $fail++;
    echo "FAIL {$message}\n";
}

$root = dirname(__DIR__);
$bom = (string)file_get_contents($root . '/bom.php');
$portal = (string)file_get_contents($root . '/index.php');
$agent = (string)file_get_contents($root . '/yifei_agent/sync.py');
$agentReadme = (string)file_get_contents($root . '/yifei_agent/README.md');
$syncReadme = (string)file_get_contents($root . '/yifei_sync/README.md');
$ignore = (string)file_get_contents($root . '/.gitignore');
$page = (string)file_get_contents($root . '/yifei_sync/index.php');
$example = json_decode((string)file_get_contents($root . '/yifei_agent/config.example.json'), true);
$examplePhp = (string)file_get_contents($root . '/yifei_sync/config.example.php');

yf_check(strpos($bom, "goModule('yifei_sync/index.php')") !== false && strpos($bom, '易飞数据') !== false, 'BOM 页有易飞数据入口');
yf_check(strpos($portal, 'yifei_sync/index.php') !== false && strpos($portal, '易飞数据') !== false, '首页 BOM 旁有易飞数据入口');
yf_check(strpos($page, "artdon_sso_require_page('bom')") !== false, '查询页沿用 BOM 登录');
yf_check(strpos((string)file_get_contents($root . '/yifei_sync/lib.php'), "getenv('YIFEI_SYNC_ALLOW_PREVIEW') !== '1'") !== false, '预览开关不能单靠配置文件打开');
yf_check(strpos($page, 'bom.cost_view') !== false, '成本沿用 bom.cost_view');
yf_check(strpos($ignore, 'yifei_sync/config.local.php') !== false && strpos($ignore, 'yifei_agent/config.json') !== false, '密钥和代理配置不入库');
yf_check(is_array($example) && ($example['sqlserver']['password'] ?? null) === '' && ($example['guangzhou']['token'] ?? null) === '', '示例配置没有真实密码或密钥');
yf_check(strpos($examplePhp, 'allow_preview_without_login') === false, '示例密钥文件没有预览开关');
yf_check(strpos($agent, 'RTRIM(MB001)') !== false && strpos($agent, 'RTRIM(MB007)') !== false && strpos($agent, 'RTRIM(MD001)') !== false, '代理 SQL 对品号、主件使用 RTRIM');
yf_check(strpos($agent, 'MB005') !== false && strpos($agent, 'RTRIM(MB002)') !== false && strpos($agent, "RTRIM(ISNULL(MB004, ''))") !== false, '成本取 MB005，品号是 MB007，MB004 只作单据号');
yf_check(!preg_match('/(?:FROM|JOIN)\s+CSTLA\b|(?:FROM|JOIN)\s+CSTMC\b/', $agent), '代理不读 CSTLA / CSTMC');
yf_check(strpos($agentReadme, 'RTRIM(MB001)') !== false && strpos($agentReadme, 'RTRIM(MB007)') !== false && strpos($agentReadme, 'db_datareader') !== false, '代理说明写了 RTRIM 和只读角色');
yf_check(strpos($agentReadme, '10') !== false && strpos($agentReadme, '不写易飞') !== false, '代理说明是约 10 分钟且不写回');
yf_check(strpos($syncReadme, 'migrate.php') !== false && strpos($syncReadme, '/www/server/artdon_secrets/yifei_sync.php') !== false, '广州说明包含建表和仓库外密钥');

$schema = yf_mysql_schema_sql();
foreach (array('yf_invmb', 'yf_bommd', 'yf_cstmb', 'yf_sync_batches', 'MB001', 'MB007', 'MD001') as $needle) {
    yf_check(strpos($schema, $needle) !== false, '建表脚本包含 ' . $needle);
}
yf_check(stripos($schema, 'CSTLA') === false && stripos($schema, 'DROP TABLE') === false, '建表脚本不删表、不建未核实成本表');

$sqlite = new PDO('sqlite::memory:');
yf_install($sqlite);
$item = yf_normalize_payload(array(
    'type' => 'invmb',
    'batch_id' => 't-item',
    'rows' => array(array(
        'item_code' => 'A4-2-01-4286   ',
        'item_name' => 'DL0067-10W双色温散热器+25*10mm紧打块(紧打)-粗坯',
        'spec' => '外径53mm, 高60mm',
    )),
));
yf_check($item['rows'][0]['item_code'] === 'A4-2-01-4286', '品号按 RTRIM 去掉尾部空格');
$first = yf_apply($sqlite, $item, '127.0.0.1');
$second = yf_apply($sqlite, $item, '127.0.0.1');
yf_check($first['upserted'] === 1 && $second['upserted'] === 1, '品号重复推送仍是一行');
$count = (int)$sqlite->query('SELECT COUNT(*) FROM yf_invmb')->fetchColumn();
yf_check($count === 1, '尾部空格没有变成第二个品号');

$bomPayload = yf_normalize_payload(array(
    'type' => 'bommd',
    'batch_id' => 't-bom',
    'rows' => array(
        array('parent_code' => 'A4-2-01-4286', 'component_code' => 'A4-2-01-0211', 'qty' => 1),
        array('parent_code' => 'A4-2-01-4286', 'component_code' => 'A4-2-01-0967', 'qty' => 1),
        array('parent_code' => 'A4-2-01-4286', 'component_code' => 'A4-2-01-0211', 'qty' => '1'),
    ),
));
$bomResult = yf_apply($sqlite, $bomPayload, '127.0.0.1');
$qty = $sqlite->query("SELECT qty FROM yf_bommd WHERE parent_code = 'A4-2-01-4286' AND component_code = 'A4-2-01-0211'")->fetchColumn();
yf_check($bomResult['upserted'] === 2 && $bomResult['deduped'] === 1 && yf_format_decimal($qty) === '2', '相同主件元件合并用量');

yf_apply($sqlite, yf_normalize_payload(array(
    'type' => 'cstmb',
    'batch_id' => 't-cost-old',
    'rows' => array(array('item_code' => 'A4-2-01-4286', 'period' => '20260101', 'doc_no' => 'OLD', 'cost' => '1.000000')),
)), '127.0.0.1');
$latest = yf_apply($sqlite, yf_normalize_payload(array(
    'type' => 'cstmb',
    'batch_id' => 't-cost-new',
    'rows' => array(array('item_code' => 'A4-2-01-4286', 'period' => '20261001', 'doc_no' => 'SAMPLE-DOC', 'cost' => '27.564722')),
)), '127.0.0.1');
$older = yf_apply($sqlite, yf_normalize_payload(array(
    'type' => 'cstmb',
    'batch_id' => 't-cost-back',
    'rows' => array(array('item_code' => 'A4-2-01-4286', 'period' => '20251201', 'doc_no' => 'OLDER', 'cost' => '99')),
)), '127.0.0.1');
$cost = $sqlite->query("SELECT cost, period, doc_no FROM yf_cstmb WHERE item_code = 'A4-2-01-4286'")->fetch(PDO::FETCH_ASSOC);
yf_check($latest['upserted'] === 1 && $older['skipped'] === 1, '较旧期间不覆盖较新成本');
yf_check(yf_format_decimal($cost['cost']) === '27.564722' && $cost['period'] === '20261001' && $cost['doc_no'] === 'SAMPLE-DOC', '保留最新期间的 MB005');

$byCode = yf_search($sqlite, 'A4-2-01-4286');
$byName = yf_search($sqlite, '散热器');
yf_check(count($byCode['rows']) === 1 && count($byName['rows']) === 1, '可按品号和品名查到样例');
$detail = yf_detail($sqlite, 'A4-2-01-4286');
yf_check(is_array($detail) && count($detail['children']) === 2 && $detail['synced_at'] !== '', '详情含 BOM 和同步时间');
$html = yf_render_page(array(
    'q' => 'A4-2-01-4286',
    'code' => 'A4-2-01-4286',
    'rows' => $byCode['rows'],
    'detail' => $detail,
    'can_cost' => true,
    'ready' => true,
    'mirror_synced_at' => $detail['synced_at'],
    'user_label' => '测试',
    'notice' => '',
    'recent' => false,
));
yf_check(strpos($html, '易飞只读数据') !== false && strpos($html, '27.564722') !== false && strpos($html, 'A4-2-01-0211') !== false && strpos($html, '单据号') !== false, '页面展示品名路径、成本、BOM 和同步字段');
$hidden = yf_render_page(array(
    'q' => 'A4-2-01-4286',
    'code' => 'A4-2-01-4286',
    'rows' => $byCode['rows'],
    'detail' => $detail,
    'can_cost' => false,
    'ready' => true,
    'mirror_synced_at' => $detail['synced_at'],
    'user_label' => '测试',
    'notice' => '',
    'recent' => false,
));
yf_check(strpos($hidden, '27.564722') === false && strpos($hidden, 'bom.cost_view') !== false && strpos($hidden, 'A4-2-01-0967') !== false, '没有成本权限时不输出成本数字');

$bad = false;
try {
    yf_normalize_payload(array('type' => 'cstla', 'rows' => array()));
} catch (InvalidArgumentException $e) {
    $bad = true;
}
yf_check($bad, '拒绝未核实的 type');

putenv('YIFEI_SYNC_TOKEN');
putenv('YIFEI_SYNC_SECRET_FILE=/tmp/yifei-sync-missing-secret.php');
$missing = false;
try {
    yf_authorize('anything-at-all-123456');
} catch (RuntimeException $e) {
    $missing = $e->getMessage() === 'TOKEN_NOT_CONFIGURED';
}
yf_check($missing, '没配置密钥时拒绝访问');
putenv('YIFEI_SYNC_TOKEN=replace-with-a-long-random-token');
$placeholder = false;
try {
    yf_authorize('replace-with-a-long-random-token');
} catch (RuntimeException $e) {
    $placeholder = $e->getMessage() === 'TOKEN_NOT_CONFIGURED';
}
yf_check($placeholder, '示例占位密钥不能用');
putenv('YIFEI_SYNC_TOKEN=contract-token-value');
$denied = false;
try {
    yf_authorize('wrong-token-value-xx');
} catch (RuntimeException $e) {
    $denied = $e->getMessage() === 'UNAUTHORIZED';
}
yf_check($denied, '错误密钥被拒绝');
$allowed = true;
try {
    yf_authorize('contract-token-value');
} catch (RuntimeException $e) {
    $allowed = false;
}
yf_check($allowed, '正确密钥通过');
yf_check(yf_request_token(array('HTTP_X_YIFEI_TOKEN' => 'abc')) === 'abc', '读取 X-Yifei-Token');

$python = trim((string)shell_exec('command -v python3'));
if ($python !== '') {
    $out = array();
    $code = 1;
    exec(escapeshellarg($python) . ' ' . escapeshellarg($root . '/yifei_agent/sync.py') . ' --self-check', $out, $code);
    yf_check($code === 0 && strpos(implode("\n", $out), 'self-check ok') !== false, '代理自检通过');
    $sqlOut = array();
    $sqlCode = 1;
    exec(escapeshellarg($python) . ' ' . escapeshellarg($root . '/yifei_agent/sync.py') . ' --print-sql', $sqlOut, $sqlCode);
    $printed = implode("\n", $sqlOut);
    yf_check($sqlCode === 0 && strpos($printed, 'RTRIM(MB007)') !== false && strpos($printed, 'UPDATE') === false, '打印的 SQL 只读且含 RTRIM');
} else {
    yf_check(false, '找不到 python3');
}

yf_mysql_smoke($root);

if ($fail > 0) {
    fwrite(STDERR, "yifei sync contract failed: {$fail}\n");
    exit(1);
}
echo "yifei sync contract passed\n";

function yf_mysql_smoke(string $root): void
{
    $mysqld = trim((string)shell_exec('command -v mysqld || true'));
    if ($mysqld === '') {
        echo "SKIP mysql curl smoke：这台机器没有 mysqld\n";
        return;
    }
    $base = '/tmp/yifei-mysql';
    $socket = $base . '/mysql.sock';
    $ping = 'mysqladmin --socket=' . escapeshellarg($socket) . ' --user=root --password= ping --silent';
    $unused = array();
    exec($ping, $unused, $pingCode);
    if ($pingCode !== 0) {
        if (!is_dir($base . '/data/mysql')) {
            @mkdir($base . '/data', 0777, true);
            $init = $mysqld . ' --initialize-insecure --datadir=' . escapeshellarg($base . '/data')
                . ' --basedir=/usr --log-error=' . escapeshellarg($base . '/init.err');
            exec($init, $initOut, $initCode);
            yf_check($initCode === 0, '初始化本地 MySQL');
            if ($initCode !== 0) {
                return;
            }
        }
        $cmd = $mysqld
            . ' --datadir=' . escapeshellarg($base . '/data')
            . ' --socket=' . escapeshellarg($socket)
            . ' --pid-file=' . escapeshellarg($base . '/mysql.pid')
            . ' --log-error=' . escapeshellarg($base . '/mysql.err')
            . ' --port=3307 --bind-address=127.0.0.1 --mysqlx=0'
            . ' --character-set-server=utf8mb4';
        $proc = proc_open($cmd, array(array('file', '/dev/null', 'r'), array('file', '/dev/null', 'w'), array('file', '/dev/null', 'w')), $pipes);
        yf_check(is_resource($proc), '启动本地 MySQL');
        $ready = false;
        for ($i = 0; $i < 40; $i++) {
            exec($ping, $unused, $pingCode);
            if ($pingCode === 0) {
                $ready = true;
                break;
            }
            usleep(250000);
        }
        yf_check($ready, '本地 MySQL 可连接');
        if (!$ready) {
            return;
        }
    } else {
        echo "OK 复用已启动的本地 MySQL\n";
    }

    $serverDsn = 'mysql:unix_socket=' . $socket . ';charset=utf8mb4';
    try {
        $server = new PDO($serverDsn, 'root', '', array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));
        $server->exec('CREATE DATABASE IF NOT EXISTS yifei_p1 CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        $pdo = new PDO('mysql:unix_socket=' . $socket . ';dbname=yifei_p1;charset=utf8mb4', 'root', '', array(
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ));
        $pdo->exec('DROP TABLE IF EXISTS yf_sync_batches');
        $pdo->exec('DROP TABLE IF EXISTS yf_cstmb');
        $pdo->exec('DROP TABLE IF EXISTS yf_bommd');
        $pdo->exec('DROP TABLE IF EXISTS yf_invmb');
    } catch (Throwable $e) {
        yf_check(false, '连接 MySQL 失败：' . $e->getMessage());
        return;
    }

    putenv('YIFEI_SYNC_DSN=mysql:unix_socket=' . $socket . ';dbname=yifei_p1;charset=utf8mb4');
    putenv('YIFEI_SYNC_DB_USER=root');
    putenv('YIFEI_SYNC_DB_PASS=');
    putenv('YIFEI_SYNC_TOKEN=contract-token-value');
    $migrateOut = array();
    $migrateCode = 1;
    exec('php ' . escapeshellarg($root . '/yifei_sync/migrate.php'), $migrateOut, $migrateCode);
    yf_check($migrateCode === 0 && strpos(implode("\n", $migrateOut), 'yf_invmb') !== false, 'migrate.php 在 MySQL 上可重复准备');
    exec('php ' . escapeshellarg($root . '/yifei_sync/migrate.php'), $migrateOut2, $migrateCode2);
    yf_check($migrateCode2 === 0, 'migrate.php 第二次仍成功');
    yf_check(yf_tables_ready($pdo), '四张镜像表已存在');

    $port = 8765;
    $serverProc = proc_open(
        array('php', '-S', '127.0.0.1:' . $port, '-t', $root),
        array(array('file', '/dev/null', 'r'), array('file', $base . '/php-access.log', 'w'), array('file', $base . '/php-error.log', 'w')),
        $serverPipes,
        $root
    );
    $up = false;
    for ($i = 0; $i < 20; $i++) {
        if (yf_http_status('http://127.0.0.1:' . $port . '/yifei_sync/api.php') === 405) {
            $up = true;
            break;
        }
        usleep(100000);
    }
    yf_check($up && is_resource($serverProc), '接收接口已监听');
    if (!$up) {
        if (is_resource($serverProc)) {
            proc_terminate($serverProc);
            proc_close($serverProc);
        }
        return;
    }

    $url = 'http://127.0.0.1:' . $port . '/yifei_sync/api.php';
    $fixture = $root . '/yifei_agent/fixtures/sample_batches.json';
    $batches = json_decode((string)file_get_contents($fixture), true);
    $status = 0;
    foreach ($batches as $batch) {
        $status = yf_curl($url, 'contract-token-value', json_encode($batch, JSON_UNESCAPED_UNICODE));
        if ($status !== 200) {
            break;
        }
    }
    yf_check($status === 200, '假数据 curl 写入三个批次');
    $again = yf_curl($url, 'contract-token-value', json_encode($batches[0], JSON_UNESCAPED_UNICODE));
    yf_check($again === 200, '同一品号再次 curl 仍成功');
    $deniedStatus = yf_curl($url, 'wrong-token-value-xx', json_encode($batches[0], JSON_UNESCAPED_UNICODE));
    yf_check($deniedStatus === 401, '错误密钥 curl 返回 401');

    $configPath = $base . '/agent-config.json';
    file_put_contents($configPath, json_encode(array(
        'guangzhou' => array('url' => $url, 'token' => 'contract-token-value', 'timeout_seconds' => 10, 'verify_tls' => true),
        'batch_size' => 200,
        'log_file' => $base . '/agent.log',
        'state_file' => $base . '/watermark.json',
    ), JSON_UNESCAPED_UNICODE));
    $pushOut = array();
    $pushCode = 1;
    exec('python3 ' . escapeshellarg($root . '/yifei_agent/sync.py') . ' --push-file ' . escapeshellarg($fixture) . ' --config ' . escapeshellarg($configPath), $pushOut, $pushCode);
    yf_check($pushCode === 0, '代理 --push-file 把样例推到接收接口');

    $found = yf_search($pdo, '散热器');
    $detail = yf_detail($pdo, 'A4-2-01-4286');
    yf_check(count($found['rows']) === 1 && is_array($detail) && count($detail['children']) === 2 && yf_format_decimal($detail['cost']['cost']) === '27.564722', 'MySQL 里能按品名查到 BOM 和最新成本');

    $stale = yf_curl($url, 'contract-token-value', json_encode(array(
        'type' => 'cstmb',
        'batch_id' => 'mysql-old-period',
        'rows' => array(array('item_code' => 'A4-2-01-4286', 'period' => '20200101', 'doc_no' => 'OLD', 'cost' => '1')),
    ), JSON_UNESCAPED_UNICODE));
    $still = $pdo->query("SELECT cost FROM yf_cstmb WHERE item_code = 'A4-2-01-4286'")->fetchColumn();
    yf_check($stale === 200 && yf_format_decimal($still) === '27.564722', 'MySQL 上较旧期间没有覆盖样例成本');

    if (is_resource($serverProc)) {
        proc_terminate($serverProc);
        proc_close($serverProc);
    }
}

function yf_http_status(string $url): int
{
    $ch = curl_init($url);
    if ($ch === false) {
        return 0;
    }
    curl_setopt_array($ch, array(
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 2,
    ));
    curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return $status;
}

function yf_curl(string $url, string $token, string $body): int
{
    $ch = curl_init($url);
    curl_setopt_array($ch, array(
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => array('Content-Type: application/json', 'X-Yifei-Token: ' . $token),
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_TIMEOUT => 10,
    ));
    $response = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($status !== 200 && $status !== 401) {
        fwrite(STDERR, "curl {$status}: " . substr((string)$response, 0, 400) . "\n");
    }
    return $status;
}
