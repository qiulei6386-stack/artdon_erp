<?php
declare(strict_types=1);

/** Token-authenticated, read-only quotation calculator. No quotation is saved here. */
require_once __DIR__ . '/../../artdon_erp/includes/artdon_sso_core.php';
require_once __DIR__ . '/../../artdon_erp/includes/bom_workflow.php';
require_once __DIR__ . '/../../artdon_erp/includes/quote_money.php';
require_once __DIR__ . '/quotation_calculation.php';

class MqReadError extends RuntimeException {}

class MqImageError extends MqReadError {
    public string $imageCode;
    public function __construct(string $code, string $message) {
        parent::__construct($message);
        $this->imageCode = $code;
    }
}

function mq_api_text($value, string $label, int $max = 120): string {
    if (!is_scalar($value) || is_bool($value)) throw new MqReadError($label . '格式不正确');
    $text = trim((string)$value);
    if (mb_strlen($text, 'UTF-8') > $max) throw new MqReadError($label . '过长');
    return $text;
}

function mq_api_id($value, string $label, bool $allowNegative = false): int {
    if ((!is_int($value) && !is_string($value)) || !preg_match($allowNegative ? '/^-?[1-9][0-9]{0,17}$/D' : '/^[1-9][0-9]{0,17}$/D', (string)$value)) {
        throw new MqReadError($label . '无效');
    }
    return (int)$value;
}

function mq_api_numeric($value, string $label): float {
    try { return mq_number($value, $label); }
    catch (RuntimeException $e) { throw new MqReadError($e->getMessage()); }
}

function mq_api_json($raw, string $label): array {
    if (!is_string($raw) || strlen($raw) > 8388608) throw new MqReadError($label . '过大或缺失，请联系管理员核对');
    try { $data = json_decode($raw, true, 128, JSON_THROW_ON_ERROR); }
    catch (JsonException $e) { throw new MqReadError($label . '格式异常，请联系管理员核对'); }
    if (!is_array($data)) throw new MqReadError($label . '格式异常，请联系管理员核对');
    return $data;
}

function mq_api_columns(PDO $pdo, string $table): array {
    static $cache = [];
    if (isset($cache[$table])) return $cache[$table];
    $st = $pdo->prepare('SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
    $st->execute([$table]);
    return $cache[$table] = array_fill_keys($st->fetchAll(PDO::FETCH_COLUMN), true);
}

function mq_api_same_cost($a, $b, string $label): void {
    if (abs(mq_api_numeric($a, $label) - mq_api_numeric($b, $label)) > 0.000100001) throw new MqReadError($label . '不一致，请重新选择当前BOM版本');
}

function mq_api_rmb(array $data): void {
    if (!in_array(strtoupper(trim((string)($data['currency'] ?? ''))), ['RMB', 'CNY', '人民币'], true)) {
        throw new MqReadError('该BOM未提供人民币成本，暂不能用于配置报价');
    }
}

function mq_api_page(array $input): int {
    $page = $input['page'] ?? 1;
    if ((!is_int($page) && !is_string($page)) || !preg_match('/^[0-9]{1,4}$/D', (string)$page) || (int)$page < 1 || (int)$page > 1000) {
        throw new MqReadError('页码无效，请缩小搜索范围');
    }
    return (int)$page;
}

function mq_api_like(string $query): string {
    return '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $query) . '%';
}

function mq_api_meta(PDO $pdo): array {
    if (!mq_api_columns($pdo, 'quote_price_levels') || !mq_api_columns($pdo, 'quote_system_settings')) {
        throw new MqReadError('网站报价等级或汇率尚未配置，请先在网站设置');
    }
    $st = $pdo->query('SELECT id,name,multiplier,is_default FROM quote_price_levels WHERE is_active=1 ORDER BY is_default DESC,sort_order,id');
    $levels = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $multiplier = mq_api_numeric($row['multiplier'], '网站报价倍率');
        if ($multiplier <= 0) throw new MqReadError('网站存在无效报价倍率，请先核对设置');
        $levels[] = ['id'=>(int)$row['id'], 'name'=>(string)$row['name'], 'multiplier'=>$multiplier, 'is_default'=>(int)$row['is_default']];
    }
    if (!$levels) throw new MqReadError('网站尚无有效报价等级，请先在网站设置');
    $st = $pdo->prepare('SELECT setting_value FROM quote_system_settings WHERE setting_key=? LIMIT 1');
    $st->execute(['exchange_rate_usd']);
    $rate = mq_api_numeric($st->fetchColumn(), '网站美元汇率');
    if ($rate <= 0) throw new MqReadError('网站美元汇率未正确配置，请先在网站设置');
    return ['levels'=>$levels, 'exchange_rate_usd'=>$rate, 'currencies'=>['RMB', 'USD']];
}

function mq_api_publication_identity(array $pub, array $meta): array {
    $source = (string)$pub['source'];
    mq_api_rmb($meta);
    if (in_array($source, ['preliminary_reference', 'final_reference'], true)) {
        if (!in_array($meta['quote_eligible'] ?? null, [true, 1, '1'], true)) throw new MqReadError('该BOM尚未具备报价条件，请先在网站核对');
        $version = -mq_api_id($meta['reference_id'] ?? '', 'BOM参考版本');
        $stage = $source === 'final_reference' ? 'approved' : 'preliminary';
        if (($meta['stage'] ?? '') !== $stage) throw new MqReadError('BOM发布阶段不一致，请重新选择');
        $versionLabel = ($stage === 'approved' ? '终审参考成本 #' : '预审参考成本 #') . abs($version);
    } elseif ($source === 'approved_snapshot') {
        $version = mq_api_id((string)($pub['snapshot_id'] ?? ''), 'BOM审核快照');
        $versionLabel = '审核快照 #' . $version;
    } else {
        throw new MqReadError('该BOM已暂停、未发布或仍为草稿，暂不能报价');
    }
    $model = trim((string)($meta['model'] ?? ''));
    if ($model === '') throw new MqReadError('BOM型号缺失，请先核对');
    if (($meta['project_uid'] ?? '') !== (string)$pub['project_uid']) throw new MqReadError('BOM发布归属异常，请联系管理员核对');
    $suffix = trim((string)($meta['version_no'] ?? ''));
    if ($suffix !== '') $versionLabel = $suffix . ' · ' . $versionLabel;
    $sourceLabels = ['preliminary_reference'=>'预审参考成本', 'final_reference'=>'终审参考成本', 'approved_snapshot'=>'审核快照'];
    return ['project_uid'=>(string)$pub['project_uid'], 'model'=>$model, 'name'=>(string)($meta['name'] ?? ''),
        'version_id'=>$version, 'version_label'=>$versionLabel, 'variant_label'=>(string)($meta['variant_label'] ?? ''), 'source'=>$source, 'source_label'=>$sourceLabels[$source]];
}

/** Only server-stored image locations are accepted. This performs no network access. */
function mq_api_image_location(string $raw): ?array {
    $raw = trim(html_entity_decode($raw, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    if ($raw === '' || strlen($raw) > 2048 || preg_match('/[\x00-\x1f\x7f\\\\]/', $raw)) return null;
    $remote = false; $query = '';
    if (preg_match('#^[a-z][a-z0-9+.-]*:#i', $raw)) {
        $url = parse_url($raw);
        if (!is_array($url) || strtolower((string)($url['scheme'] ?? '')) !== 'https' || isset($url['user']) || isset($url['pass']) || isset($url['fragment']) || (isset($url['port']) && (int)$url['port'] !== 443)) return null;
        $host = strtolower((string)($url['host'] ?? ''));
        if ($host !== 'novlight.com' && $host !== 'artdonlighting.com') return null;
        $path = (string)($url['path'] ?? '');
        $remote = $host === 'artdonlighting.com';
        if (isset($url['query']) && $url['query'] !== '') {
            parse_str($url['query'], $params);
            if (!$params) return null;
            foreach ($params as $key=>$value) if (!in_array($key, ['v','t'], true) || !is_string($value) || !preg_match('/^[a-zA-Z0-9._-]{1,64}$/D', $value)) return null;
            $query = '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
        }
    } else {
        if (strpos($raw, '//') === 0 || strpos($raw, '?') !== false || strpos($raw, '#') !== false) return null;
        $path = $raw;
    }
    $path = rawurldecode($path);
    if ($path === '' || preg_match('/[\x00-\x1f\x7f\\\\]/', $path) || preg_match('/%[a-f0-9]{2}/i', $path)) return null;
    $parts = explode('/', $path);
    if (in_array('.', $parts, true) || in_array('..', $parts, true)) return null;
    if (!preg_match('/\.(jpe?g|png|webp|gif)$/iD', $path)) return null;
    if ($remote) {
        if (strpos($path, '/uploads/website/') !== 0) return null;
        $encoded = implode('/', array_map('rawurlencode', explode('/', $path)));
        return ['type'=>'remote', 'url'=>'https://artdonlighting.com' . $encoded . $query];
    }
    $root = '/www/wwwroot/Artdon';
    if (strpos($path, $root . '/') === 0) $path = substr($path, strlen($root));
    $path = '/' . ltrim($path, '/');
    // These legacy relative paths are naming/PLM uploads beneath the ERP application.
    if (strpos($path, '/uploads/naming/') === 0 || strpos($path, '/uploads/plm/') === 0) $path = '/artdon_erp' . $path;
    $allowed = false;
    foreach (['/artdon_erp/uploads/naming/', '/artdon_erp/uploads/plm/', '/uploads/website/', '/uploads/products/'] as $prefix) if (strpos($path, $prefix) === 0) $allowed = true;
    return $allowed ? ['type'=>'local', 'path'=>$root . $path] : null;
}

function mq_api_same_image_model(string $a, string $b): bool {
    // Pictures never use the fuzzy matching employed by catalogue search.
    return mb_strtoupper(trim($a), 'UTF-8') === mb_strtoupper(trim($b), 'UTF-8');
}

/** Lightweight version validation: no material rows, draft image or full reference body. */
function mq_api_bom_image_source(PDO $pdo, array $pub, array $meta, array $identity): ?array {
    $uid = $identity['project_uid']; $version = $identity['version_id'];
    $snapshotId = (int)($pub['snapshot_id'] ?? 0);
    if ($version < 0) {
        if (!bl_ready($pdo)) throw new MqReadError('BOM参考成本尚未就绪，请重新选择');
        $st = $pdo->prepare('SELECT project_uid,stage,workflow_version,approval_snapshot_id,cost,digest FROM bom_reference_versions WHERE id=? AND project_uid=? LIMIT 1');
        $st->execute([abs($version), $uid]); $ref = $st->fetch(PDO::FETCH_ASSOC);
        if (!$ref || $ref['stage'] !== ($meta['stage'] ?? '') || (int)$ref['workflow_version'] !== (int)($meta['workflow_version'] ?? -1) || (int)($ref['approval_snapshot_id'] ?? 0) !== $snapshotId ||
            !preg_match('/^[a-f0-9]{64}$/D', (string)($meta['reference_digest'] ?? '')) || !hash_equals((string)$meta['reference_digest'], (string)$ref['digest'])) {
            throw new MqReadError('BOM当前版本已变化，请重新选择灯具');
        }
        mq_api_same_cost($pub['cost'], $ref['cost'], 'BOM参考成本');
        if ($pub['source'] === 'final_reference' && $snapshotId <= 0) throw new MqReadError('BOM关联快照缺失，暂不能显示版本图片');
    } elseif ($snapshotId !== $version) throw new MqReadError('BOM当前版本已变化，请重新选择灯具');
    if ($snapshotId > 0) {
        $st = $pdo->prepare('SELECT model,product_image FROM bom_snapshots WHERE id=? AND project_uid=? LIMIT 1');
        $st->execute([$snapshotId, $uid]); $snapshot = $st->fetch(PDO::FETCH_ASSOC);
        if (!$snapshot || isset(bw_snapshot_voids($pdo, $uid)[$snapshotId])) throw new MqReadError('BOM关联快照不存在或已作废');
        if (mq_api_same_image_model((string)$snapshot['model'], $identity['model'])) {
            $location = mq_api_image_location((string)$snapshot['product_image']);
            if ($location !== null) return ['kind'=>'snapshot', 'label'=>'BOM版本图', 'location'=>$location];
        } elseif ($version > 0) throw new MqReadError('BOM审核快照型号不一致，请重新选择');
    }
    $system = strtoupper(trim((string)($meta['linked_system'] ?? '')));
    $namingId = (string)($meta['linked_id'] ?? '');
    if ($system !== 'NAMING' || !preg_match('/^[1-9][0-9]{0,17}$/D', $namingId)) return null;
    $columns = mq_api_columns($pdo, 'naming_models');
    if (!isset($columns['id'], $columns['model_no'])) return null;
    $imageColumns = [];
    foreach (['image_path','web_image_url','source_image_url'] as $column) if (isset($columns[$column])) $imageColumns[] = $column;
    if (!$imageColumns) return null;
    $st = $pdo->prepare('SELECT model_no,`' . implode('`,`', $imageColumns) . '` FROM naming_models WHERE id=? LIMIT 1');
    $st->execute([$namingId]); $naming = $st->fetch(PDO::FETCH_ASSOC);
    if (!$naming || !mq_api_same_image_model((string)$naming['model_no'], $identity['model'])) return null;
    foreach ($imageColumns as $column) {
        $location = mq_api_image_location((string)$naming[$column]);
        if ($location !== null) return ['kind'=>'reference', 'label'=>'型号参考图', 'location'=>$location];
    }
    return null;
}

function mq_api_bom_image_public(?array $source, array $identity): array {
    return ['image_available'=>$source !== null, 'image_kind'=>$source['kind'] ?? 'reference', 'image_label'=>$source['label'] ?? '型号参考图',
        'image_request'=>['project_uid'=>$identity['project_uid'], 'version_id'=>$identity['version_id']]];
}

function mq_api_bom_image_current(PDO $pdo, array $input): array {
    if (!bw_ready($pdo)) throw new MqReadError('BOM版本保护尚未初始化，请联系管理员核对');
    $uid = mq_api_text($input['project_uid'] ?? '', 'BOM项目', 100);
    $version = mq_api_id($input['version_id'] ?? '', 'BOM版本', true);
    $st = $pdo->prepare('SELECT c.project_uid,c.snapshot_id,c.source,c.cost,c.updated_at,c.payload_json FROM bom_cost_publications c JOIN bom_projects b ON b.project_uid=c.project_uid WHERE c.project_uid=? AND b.is_active=1 LIMIT 1');
    $st->execute([$uid]); $pub = $st->fetch(PDO::FETCH_ASSOC);
    if (!$pub) throw new MqReadError('BOM不存在或已暂停，请重新选择灯具');
    $meta = mq_api_json($pub['payload_json'], 'BOM发布资料');
    $identity = mq_api_publication_identity($pub, $meta);
    if ($identity['version_id'] !== $version) throw new MqReadError('BOM已更新，请重新搜索灯具');
    $source = mq_api_bom_image_source($pdo, $pub, $meta, $identity);
    if ($source === null) throw new MqReadError('该型号暂无可用图片，请按型号核对');
    return $source;
}

/** Historical drafts can use only their persisted version's exact naming identity. */
function mq_api_draft_naming_image(PDO $pdo, string $system, string $namingId, string $model): ?array {
    if (strtoupper(trim($system)) !== 'NAMING' || !preg_match('/^[1-9][0-9]{0,17}$/D', $namingId)) return null;
    $columns = mq_api_columns($pdo, 'naming_models');
    if (!isset($columns['id'], $columns['model_no'])) return null;
    $imageColumns = [];
    foreach (['image_path','web_image_url','source_image_url'] as $column) if (isset($columns[$column])) $imageColumns[] = $column;
    if (!$imageColumns) return null;
    $st = $pdo->prepare('SELECT model_no,`' . implode('`,`', $imageColumns) . '` FROM naming_models WHERE id=? LIMIT 1');
    $st->execute([$namingId]); $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row || !mq_api_same_image_model((string)$row['model_no'], $model)) throw new MqImageError('STALE_IMAGE', '草稿所绑定的命名型号已变化，暂不能核对参考图片');
    foreach ($imageColumns as $column) {
        $location = mq_api_image_location((string)$row[$column]);
        if ($location !== null) return ['kind'=>'reference', 'label'=>'型号参考图', 'location'=>$location];
    }
    return null;
}

/** Resolve a current or append-only historical version without changing any quote data. */
function mq_api_draft_bom_image_source(PDO $pdo, array $input): array {
    try {
        $uid = mq_api_text($input['project_uid'] ?? '', 'BOM项目', 100);
        $version = mq_api_id($input['version_id'] ?? '', 'BOM版本', true);
        $model = mq_api_text($input['model'] ?? '', '草稿灯具型号', 160);
    } catch (MqReadError $e) {
        throw new MqImageError('STALE_IMAGE', '草稿图片标识不完整，请按保存的型号核对');
    }
    if ($uid === '' || $model === '') throw new MqImageError('STALE_IMAGE', '草稿缺少可核对的灯具型号或BOM标识');
    $st = $pdo->prepare('SELECT project_uid FROM bom_projects WHERE project_uid=? AND is_active=1 LIMIT 1');
    $st->execute([$uid]);
    if (!$st->fetchColumn()) throw new MqImageError('STALE_IMAGE', '草稿对应BOM已停用或不存在，暂不能核对图片');
    $st = $pdo->prepare('SELECT project_uid,snapshot_id,source,cost,updated_at,payload_json FROM bom_cost_publications WHERE project_uid=? LIMIT 1');
    $st->execute([$uid]); $pub = $st->fetch(PDO::FETCH_ASSOC);
    if ($pub) {
        try { $meta = mq_api_json($pub['payload_json'], 'BOM发布资料'); $identity = mq_api_publication_identity($pub, $meta); }
        catch (MqReadError $e) { $identity = null; }
        if ($identity !== null && $identity['version_id'] === $version) {
            if (!mq_api_same_image_model($identity['model'], $model)) throw new MqImageError('STALE_IMAGE', '草稿型号与对应BOM版本不一致');
            try { $source = mq_api_bom_image_source($pdo, $pub, $meta, $identity); }
            catch (MqReadError $e) { throw new MqImageError('STALE_IMAGE', '草稿对应版本的图片归属暂时无法核对'); }
            if ($source === null) throw new MqImageError('NO_IMAGE', '该灯具版本暂无可靠图片');
            return $source;
        }
    }
    $snapshotId = $version > 0 ? $version : 0;
    $reference = null;
    if ($version < 0) {
        // Extract immutable identity only; never load the reference's large material body.
        $json = "IF(JSON_VALID(payload_json),payload_json,'{}')";
        $paths = ['project_uid','model','linked_system','linked_id','stage','approval_snapshot_id'];
        $fields = [];
        foreach ($paths as $field) $fields[] = "JSON_UNQUOTE(JSON_EXTRACT($json,'$." . $field . "')) AS `payload_" . $field . '`';
        $st = $pdo->prepare('SELECT project_uid,stage,approval_snapshot_id,digest,SHA2(payload_json,256) AS payload_digest,' . implode(',', $fields) . ' FROM bom_reference_versions WHERE id=? AND project_uid=? AND OCTET_LENGTH(payload_json)<=8388608 LIMIT 1');
        $st->execute([abs($version), $uid]); $reference = $st->fetch(PDO::FETCH_ASSOC);
        if (!$reference || !preg_match('/^[a-f0-9]{64}$/D', (string)$reference['digest']) || !hash_equals((string)$reference['digest'], (string)$reference['payload_digest']) ||
            $reference['payload_project_uid'] !== $uid || !mq_api_same_image_model((string)$reference['payload_model'], $model) ||
            !in_array($reference['stage'], ['preliminary','approved'], true) || $reference['stage'] !== $reference['payload_stage'] ||
            (int)($reference['approval_snapshot_id'] ?? 0) !== (int)($reference['payload_approval_snapshot_id'] ?? 0)) {
            throw new MqImageError('STALE_IMAGE', '无法核对草稿所保存的历史BOM版本或型号');
        }
        $snapshotId = (int)($reference['approval_snapshot_id'] ?? 0);
        if ($reference['stage'] === 'approved' && $snapshotId <= 0) throw new MqImageError('STALE_IMAGE', '历史终审BOM缺少可靠审核快照');
    }
    if ($snapshotId > 0) {
        $st = $pdo->prepare('SELECT model,product_image FROM bom_snapshots WHERE id=? AND project_uid=? LIMIT 1');
        $st->execute([$snapshotId, $uid]); $snapshot = $st->fetch(PDO::FETCH_ASSOC);
        if (!$snapshot || isset(bw_snapshot_voids($pdo, $uid)[$snapshotId])) throw new MqImageError('STALE_IMAGE', '草稿对应审核快照已作废或无法核对');
        if (mq_api_same_image_model((string)$snapshot['model'], $model)) {
            $location = mq_api_image_location((string)$snapshot['product_image']);
            if ($location !== null) return ['kind'=>'snapshot', 'label'=>'灯具图片', 'location'=>$location];
        } elseif ($version > 0) throw new MqImageError('STALE_IMAGE', '历史审核快照型号与草稿不一致');
    }
    if ($reference !== null) {
        $source = mq_api_draft_naming_image($pdo, (string)$reference['payload_linked_system'], (string)$reference['payload_linked_id'], $model);
        if ($source !== null) return $source;
    }
    throw new MqImageError('NO_IMAGE', '该历史灯具暂无可核对的版本图片或型号参考图');
}

function mq_api_image_public_ipv4(string $ip): bool {
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) return false;
    $number = unpack('N', inet_pton($ip))[1];
    foreach ([['0.0.0.0',8],['10.0.0.0',8],['100.64.0.0',10],['127.0.0.0',8],['169.254.0.0',16],['172.16.0.0',12],
        ['192.0.0.0',24],['192.0.2.0',24],['192.88.99.0',24],['192.168.0.0',16],['198.18.0.0',15],['198.51.100.0',24],['203.0.113.0',24],['224.0.0.0',4],['240.0.0.0',4]] as $range) {
        $mask = -1 << (32 - $range[1]); $network = unpack('N', inet_pton($range[0]))[1];
        if (($number & $mask) === ($network & $mask)) return false;
    }
    return true;
}

function mq_api_image_bytes(array $location): string {
    $limit = 10485760;
    if ($location['type'] === 'local') {
        $verified = mq_api_image_location((string)($location['path'] ?? ''));
        if ($verified === null || $verified['type'] !== 'local' || $verified['path'] !== $location['path']) throw new MqReadError('图片路径不受支持');
        $root = realpath('/www/wwwroot/Artdon'); $file = realpath($verified['path']);
        if ($root === false || $file === false || strpos($file, $root . '/') !== 0 || !is_file($file)) throw new MqReadError('图片文件暂时无法读取');
        $allowed = false;
        foreach (['/artdon_erp/uploads/naming/', '/artdon_erp/uploads/plm/', '/uploads/website/', '/uploads/products/'] as $prefix) if (strpos($file, $root . $prefix) === 0) $allowed = true;
        if (!$allowed || !preg_match('/\.(jpe?g|png|webp|gif)$/iD', $file)) throw new MqReadError('图片路径不受支持');
        $size = filesize($file);
        if ($size === false || $size <= 0 || $size > $limit) throw new MqReadError('图片超过10MB或文件无效');
        $bytes = @file_get_contents($file, false, null, 0, $limit + 1);
        if (!is_string($bytes) || $bytes === '' || strlen($bytes) > $limit) throw new MqReadError('图片文件暂时无法读取');
        return $bytes;
    }
    if ($location['type'] !== 'remote' || !function_exists('curl_init')) throw new MqReadError('图片地址暂不受支持');
    $verified = mq_api_image_location((string)($location['url'] ?? ''));
    if ($verified === null || $verified['type'] !== 'remote' || $verified['url'] !== $location['url']) throw new MqReadError('图片地址暂不受支持');
    // Resolve only the single permitted public host, choose a public IPv4 and pin it.
    // IPv6 destinations, including mapped/private IPv6 addresses, are never connected.
    $records = @dns_get_record('artdonlighting.com', DNS_A); $ip = null;
    foreach (is_array($records) ? $records : [] as $record) if (isset($record['ip']) && mq_api_image_public_ipv4((string)$record['ip'])) { $ip = (string)$record['ip']; break; }
    if ($ip === null) throw new MqReadError('产品图片服务器暂时无法连接');
    $bytes = ''; $tooLarge = false;
    $ch = curl_init($verified['url']);
    curl_setopt_array($ch, [CURLOPT_FOLLOWLOCATION=>false, CURLOPT_CONNECTTIMEOUT=>3, CURLOPT_TIMEOUT=>8,
        CURLOPT_SSL_VERIFYPEER=>true, CURLOPT_SSL_VERIFYHOST=>2, CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,
        CURLOPT_RESOLVE=>['artdonlighting.com:443:' . $ip], CURLOPT_IPRESOLVE=>CURL_IPRESOLVE_V4,
        CURLOPT_PROXY=>'', CURLOPT_NOPROXY=>'*',
        CURLOPT_USERAGENT=>'ArtdonMiniappQuoteImage/1.0', CURLOPT_HTTPHEADER=>['Accept: image/jpeg,image/png,image/webp,image/gif'],
        CURLOPT_WRITEFUNCTION=>static function($handle, string $chunk) use (&$bytes, &$tooLarge, $limit): int {
            if (strlen($bytes) + strlen($chunk) > $limit) { $tooLarge = true; return 0; }
            $bytes .= $chunk; return strlen($chunk);
        }]);
    $ok = curl_exec($ch); $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    if ($tooLarge) throw new MqReadError('图片超过10MB，暂不能显示');
    if ($ok === false || $status !== 200 || $bytes === '') throw new MqReadError('产品图片暂时无法读取，请稍后再试');
    return $bytes;
}

/** Built-in signature checks remain available when the web PHP lacks fileinfo. */
function mq_api_image_mime(string $bytes): ?string {
    $length = strlen($bytes);
    if ($length >= 33 && substr($bytes, 0, 8) === "\x89PNG\r\n\x1a\n" && substr($bytes, 8, 8) === "\x00\x00\x00\x0dIHDR") return 'image/png';
    if ($length >= 12 && substr($bytes, 0, 3) === "\xff\xd8\xff" && strpos($bytes, "\xff\xd9", 3) !== false) return 'image/jpeg';
    if ($length >= 13 && in_array(substr($bytes, 0, 6), ['GIF87a','GIF89a'], true)) return 'image/gif';
    if ($length >= 20 && substr($bytes, 0, 4) === 'RIFF' && substr($bytes, 8, 4) === 'WEBP' && in_array(substr($bytes, 12, 4), ['VP8 ','VP8L','VP8X'], true)) {
        $riffSize = unpack('V', substr($bytes, 4, 4))[1];
        if ($riffSize + 8 === $length) return 'image/webp';
    }
    return null;
}

/** Called only after the read-only database transaction has been rolled back. */
function mq_api_emit_bom_image(array $source, string $size): void {
    $bytes = mq_api_image_bytes($source['location']);
    $info = @getimagesizefromstring($bytes);
    $types = [IMAGETYPE_JPEG=>'image/jpeg', IMAGETYPE_PNG=>'image/png', IMAGETYPE_WEBP=>'image/webp', IMAGETYPE_GIF=>'image/gif'];
    if (!is_array($info) || !isset($types[$info[2]]) || $info[0] <= 0 || $info[1] <= 0 || $info[0] * $info[1] > 20000000) throw new MqReadError('图片格式无效或分辨率超过2000万像素');
    $mime = $types[$info[2]];
    if (mq_api_image_mime($bytes) !== $mime) throw new MqReadError('图片真实格式与内容不一致');
    // fileinfo is an additional check where installed; BaoTa PHP 8.0 has GD but no fileinfo.
    if (function_exists('finfo_open')) {
        $detector = finfo_open(FILEINFO_MIME_TYPE);
        if ($detector === false) throw new MqReadError('图片格式校验暂不可用');
        $detected = finfo_buffer($detector, $bytes); finfo_close($detector);
        if ($detected !== $mime) throw new MqReadError('图片真实格式与内容不一致');
    }
    if ($size === 'thumb') {
        if (!function_exists('imagecreatefromstring') || !function_exists('imagecreatetruecolor')) throw new MqReadError('缩略图暂时不可用，请稍后重试');
        $image = @imagecreatefromstring($bytes);
        if (!$image) throw new MqReadError('图片暂时无法解码');
        $scale = min(1, 320 / max($info[0], $info[1]));
        $width = max(1, (int)round($info[0] * $scale)); $height = max(1, (int)round($info[1] * $scale));
        $thumb = imagecreatetruecolor($width, $height);
        imagealphablending($thumb, false); imagesavealpha($thumb, true);
        imagefill($thumb, 0, 0, imagecolorallocatealpha($thumb, 255, 255, 255, 127));
        imagecopyresampled($thumb, $image, 0, 0, 0, 0, $width, $height, $info[0], $info[1]);
        ob_start();
        if ($mime === 'image/jpeg') { imagejpeg($thumb, null, 82); $mime = 'image/jpeg'; }
        else { imagepng($thumb, null, 6); $mime = 'image/png'; }
        $rendered = ob_get_clean(); imagedestroy($thumb); imagedestroy($image);
        if (!is_string($rendered) || $rendered === '') throw new MqReadError('缩略图暂时无法生成');
        $bytes = $rendered;
    }
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
    @ini_set('zlib.output_compression', '0');
    while (ob_get_level() > 0) @ob_end_clean();
    foreach (['Set-Cookie','Expires','Pragma','Cache-Control','Content-Type','Content-Encoding'] as $header) header_remove($header);
    header('Content-Type: ' . $mime);
    header('Content-Length: ' . strlen($bytes));
    header('Cache-Control: private, no-store');
    header('X-Content-Type-Options: nosniff');
    echo $bytes;
    exit;
}

function mq_api_search_boms(PDO $pdo, array $input): array {
    if (!bw_ready($pdo)) throw new MqReadError('BOM版本保护尚未初始化，请联系管理员核对');
    $page = mq_api_page($input);
    $q = mq_api_text($input['q'] ?? '', '搜索内容');
    // Only lightweight immutable publication metadata is searched; never a draft or image.
    $json = "IF(JSON_VALID(c.payload_json),c.payload_json,'{}')";
    $field = static fn(string $name): string => "JSON_UNQUOTE(JSON_EXTRACT($json,'$." . $name . "'))";
    $where = ["b.is_active=1", "UPPER(TRIM(" . $field('currency') . ")) IN ('RMB','CNY','人民币')",
        "((c.source='approved_snapshot' AND c.snapshot_id>0) OR (c.source IN ('preliminary_reference','final_reference') AND " . $field('quote_eligible') . " IN ('true','1') AND " . $field('reference_id') . " REGEXP '^[1-9][0-9]*$'))"];
    $args = [];
    if ($q !== '') {
        $where[] = '(' . $field('model') . " LIKE ? ESCAPE '!' OR " . $field('name') . " LIKE ? ESCAPE '!' OR " . $field('variant_label') . " LIKE ? ESCAPE '!')";
        $args = [mq_api_like($q), mq_api_like($q), mq_api_like($q)];
    }
    $offset = ($page - 1) * 20;
    $st = $pdo->prepare('SELECT c.project_uid,c.snapshot_id,c.source,c.cost,c.updated_at,c.payload_json FROM bom_cost_publications c JOIN bom_projects b ON b.project_uid=c.project_uid WHERE ' . implode(' AND ', $where) . ' ORDER BY c.updated_at DESC,c.project_uid LIMIT 21 OFFSET ' . $offset);
    $st->execute($args);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    $hasMore = count($rows) > 20;
    if ($hasMore) array_pop($rows);
    $list = [];
    foreach ($rows as $pub) {
        $meta = mq_api_json($pub['payload_json'], 'BOM发布资料');
        $identity = mq_api_publication_identity($pub, $meta); $imageSource = null;
        try { $imageSource = mq_api_bom_image_source($pdo, $pub, $meta, $identity); }
        catch (Throwable $imageError) { /* A missing image must not prevent catalogue selection. */ }
        $list[] = $identity + mq_api_bom_image_public($imageSource, $identity);
    }
    return ['list'=>$list, 'page'=>$page, 'has_more'=>$hasMore];
}

function mq_api_current_bom(PDO $pdo, array $input): array {
    if (!bw_ready($pdo)) throw new MqReadError('BOM版本保护尚未初始化，请联系管理员核对');
    $uid = mq_api_text($input['project_uid'] ?? '', 'BOM项目', 100);
    if ($uid === '') throw new MqReadError('请选择灯具BOM');
    $version = mq_api_id($input['version_id'] ?? '', 'BOM版本', true);
    $st = $pdo->prepare('SELECT c.project_uid,c.snapshot_id,c.source,c.cost,c.updated_at,c.payload_json FROM bom_cost_publications c JOIN bom_projects b ON b.project_uid=c.project_uid WHERE c.project_uid=? AND b.is_active=1 LIMIT 1');
    $st->execute([$uid]);
    $pub = $st->fetch(PDO::FETCH_ASSOC);
    if (!$pub) throw new MqReadError('BOM不存在、已停用或尚未发布，请重新选择');
    $meta = mq_api_json($pub['payload_json'], 'BOM发布资料');
    $identity = mq_api_publication_identity($pub, $meta);
    if ($version !== $identity['version_id']) throw new MqReadError('BOM已产生新的参考版本，请重新选择当前版本');
    $publishedCost = mq_api_numeric($pub['cost'], 'BOM发布成本');
    if ($version < 0) {
        if (!bl_ready($pdo)) throw new MqReadError('BOM参考成本尚未就绪，请联系管理员核对');
        $st = $pdo->prepare('SELECT id,project_uid,stage,workflow_version,approval_snapshot_id,cost,digest,IF(OCTET_LENGTH(payload_json)<=8388608,payload_json,NULL) AS payload_json FROM bom_reference_versions WHERE id=? AND project_uid=? LIMIT 1');
        $st->execute([abs($version), $uid]);
        $ref = $st->fetch(PDO::FETCH_ASSOC);
        if (!$ref) throw new MqReadError('BOM当前参考版本缺失，请联系管理员核对');
        $payload = mq_api_json($ref['payload_json'], 'BOM参考版本明细');
        $referenceDigest = (string)($meta['reference_digest'] ?? '');
        if (!preg_match('/^[a-f0-9]{64}$/D', $referenceDigest) || !hash_equals($referenceDigest, (string)$ref['digest']) || !hash_equals($referenceDigest, hash('sha256', bw_json($payload)))) {
            throw new MqReadError('BOM参考版本校验失败，请联系管理员核对');
        }
        $stage = $pub['source'] === 'final_reference' ? 'approved' : 'preliminary';
        if ($ref['stage'] !== $stage || ($payload['stage'] ?? '') !== $stage ||
            (int)$ref['workflow_version'] !== (int)($meta['workflow_version'] ?? -1) ||
            (int)$ref['workflow_version'] !== (int)($payload['workflow_version'] ?? -1) ||
            ($payload['project_uid'] ?? '') !== $uid || trim((string)($payload['model'] ?? '')) !== $identity['model']) {
            throw new MqReadError('BOM参考版本归属或阶段发生变化，请重新选择');
        }
        mq_api_rmb($payload);
        mq_api_same_cost($publishedCost, $ref['cost'], 'BOM参考成本');
        mq_api_same_cost($publishedCost, $payload['cost'] ?? null, 'BOM参考成本');
        $approvalId = (int)($ref['approval_snapshot_id'] ?? 0);
        if ($approvalId !== (int)($payload['approval_snapshot_id'] ?? 0) || $approvalId !== (int)($pub['snapshot_id'] ?? 0)) {
            throw new MqReadError('BOM关联审核快照不一致，请联系管理员核对');
        }
        if ($stage === 'approved' && $approvalId <= 0) throw new MqReadError('终审BOM缺少审核快照，请先核对');
        if ($approvalId > 0) {
            $st = $pdo->prepare('SELECT id FROM bom_snapshots WHERE id=? AND project_uid=? LIMIT 1');
            $st->execute([$approvalId, $uid]);
            if (!$st->fetchColumn() || isset(bw_snapshot_voids($pdo, $uid)[$approvalId])) throw new MqReadError('BOM关联审核快照缺失或已作废，暂不能报价');
        }
        $bodyDigest = $referenceDigest;
    } else {
        $st = $pdo->prepare('SELECT id,project_uid,model,currency,labor,other,IF(OCTET_LENGTH(rows_json)<=8388608,rows_json,NULL) AS rows_json,totals_json FROM bom_snapshots WHERE id=? AND project_uid=? LIMIT 1');
        $st->execute([$version, $uid]);
        $snapshot = $st->fetch(PDO::FETCH_ASSOC);
        if (!$snapshot || trim((string)$snapshot['model']) !== $identity['model'] || isset(bw_snapshot_voids($pdo, $uid)[$version])) {
            throw new MqReadError('BOM当前审核快照已作废或归属异常，暂不能报价');
        }
        mq_api_rmb($snapshot);
        $rows = mq_api_json($snapshot['rows_json'], 'BOM审核快照物料');
        $totals = mq_api_json($snapshot['totals_json'], 'BOM审核快照成本');
        mq_api_same_cost($publishedCost, $totals['total'] ?? null, 'BOM审核快照成本');
        $payload = ['rows'=>$rows, 'currency'=>$snapshot['currency'], 'labor'=>$snapshot['labor'], 'other'=>$snapshot['other']];
        $bodyDigest = hash('sha256', $snapshot['rows_json'] . "\n" . $snapshot['totals_json'] . "\n" . $snapshot['labor'] . "\n" . $snapshot['other']);
    }
    try { $breakdown = mq_shell_breakdown($payload, $publishedCost); }
    catch (RuntimeException $e) { throw new MqReadError($e->getMessage()); }
    $notes = $breakdown['review_notes'];
    if ((int)($meta['pending_prices'] ?? 0) > 0) $notes[] = '当前BOM含待核对物料价格，本结果仅用于试算';
    $notes = array_values(array_unique($notes));
    $breakdown['review_notes'] = $notes;
    $breakdown['needs_review'] = count($notes) > 0;
    $digest = hash('sha256', bw_json([$uid, $version, $pub['source'], (string)$pub['cost'], $pub['updated_at'], $pub['payload_json'], $bodyDigest]));
    return ['bom'=>$identity + ['digest'=>$digest, 'cost_rmb'=>$publishedCost,
        'shell_cost_rmb'=>$breakdown['shell_cost_rmb'], 'removed_led_cost'=>$breakdown['removed_led_cost'], 'removed_driver_cost'=>$breakdown['removed_driver_cost'],
        'led_qty'=>$breakdown['led_qty'], 'driver_qty'=>$breakdown['driver_qty'], 'needs_review'=>$breakdown['needs_review'], 'review_notes'=>$notes, 'component_rows'=>$breakdown['component_rows']],
        'breakdown'=>$breakdown, 'publication_meta'=>$meta];
}

/** Material query is derived only from server-owned metadata and a fixed table allowlist. */
function mq_api_material_source(PDO $pdo): array {
    static $cache;
    if ($cache !== null) return $cache;
    $aliases = ['id'=>['id','mid'], 'category'=>['category','type'], 'brand'=>['brand'], 'name'=>['name','material_name'], 'model'=>['model','code'],
        'spec'=>['spec','remark'], 'unit'=>['unit'], 'price'=>['price','unit_price'], 'keyword'=>['keyword']];
    foreach (['bom_materials', 'materials'] as $table) {
        $columns = mq_api_columns($pdo, $table);
        if (!$columns) continue;
        $map = [];
        foreach ($aliases as $field=>$candidates) foreach ($candidates as $candidate) if (isset($columns[$candidate])) { $map[$field] = $candidate; break; }
        if (!isset($map['id'], $map['name'])) throw new MqReadError('物料库字段不完整，请联系管理员核对');
        $stateColumns = $table === 'bom_materials' ? mq_api_columns($pdo, 'bom_material_state') : [];
        $hasState = isset($stateColumns['material_id'], $stateColumns['identity_status'], $stateColumns['confirmed_price'], $stateColumns['price_status']);
        $select = [];
        foreach ($aliases as $field=>$unused) {
            if ($field === 'price') {
                $library = isset($map['price']) ? 'm.`' . $map['price'] . '`' : 'NULL';
                $expression = $hasState ? "CASE WHEN s.identity_status='confirmed' AND s.confirmed_price IS NOT NULL THEN s.confirmed_price ELSE $library END" : $library;
            } else $expression = isset($map[$field]) ? 'm.`' . $map[$field] . '`' : "''";
            $select[] = $expression . ' AS `' . $field . '`';
        }
        $select[] = $hasState ? "CASE WHEN s.identity_status='confirmed' AND s.confirmed_price IS NOT NULL THEN 'confirmed' ELSE COALESCE(s.price_status,'historical') END AS price_status" : "'historical' AS price_status";
        $select[] = $hasState ? "CASE WHEN s.identity_status='confirmed' AND s.confirmed_price IS NOT NULL THEN 'standard' ELSE 'library' END AS price_source" : "'library' AS price_source";
        return $cache = ['table'=>$table, 'map'=>$map, 'select'=>implode(',', $select), 'from'=>'`' . $table . '` m' . ($hasState ? ' LEFT JOIN bom_material_state s ON s.material_id=m.`' . $map['id'] . '`' : ''),
            'active'=>isset($columns['is_active']) ? 'm.is_active=1' : '1=1'];
    }
    throw new MqReadError('尚未找到可用物料库，请联系管理员核对');
}

function mq_api_material_public(array $row): array {
    $price = null;
    if ($row['price'] !== null && $row['price'] !== '' && is_numeric($row['price']) && is_finite((float)$row['price']) && (float)$row['price'] >= 0) $price = (float)$row['price'];
    $needsReview = $price !== null && $row['price_status'] !== 'confirmed';
    return ['id'=>(int)$row['id'], 'name'=>(string)$row['name'], 'model'=>(string)$row['model'], 'brand'=>(string)$row['brand'], 'spec'=>(string)$row['spec'],
        'unit'=>(string)$row['unit'], 'price'=>$price, 'price_status'=>$price === null ? 'missing' : (string)$row['price_status'], 'price_source'=>(string)$row['price_source'],
        'needs_review'=>$needsReview, 'price_warning'=>$needsReview ? '待核对/历史价格，仅用于试算' : ''];
}

function mq_api_search_materials(PDO $pdo, array $input): array {
    $kind = mq_api_text($input['kind'] ?? '', '物料种类', 10);
    if (!in_array($kind, ['led', 'driver'], true)) throw new MqReadError('请选择芯片或电源');
    $q = mq_api_text($input['q'] ?? '', '搜索内容');
    $page = mq_api_page($input);
    $source = mq_api_material_source($pdo);
    $where = [$source['active']]; $args = [];
    if ($q !== '') {
        $searches = [];
        foreach (['name','model','spec','brand','keyword','category'] as $field) if (isset($source['map'][$field])) {
            $searches[] = 'm.`' . $source['map'][$field] . "` LIKE ? ESCAPE '!'";
            $args[] = mq_api_like($q);
        }
        $where[] = '(' . implode(' OR ', $searches) . ')';
    }
    // Stream lightweight rows with a keyset. Classification precedes logical pagination,
    // so uncommon category names do not silently disappear after a fixed library cutoff.
    $idColumn = 'm.`' . $source['map']['id'] . '`';
    $cursor = null; $skip = ($page - 1) * 20; $matched = 0; $list = []; $scanned = 0;
    while (count($list) < 21) {
        $conditions = $where; $params = $args;
        if ($cursor !== null) { $conditions[] = $idColumn . '<?'; $params[] = $cursor; }
        $st = $pdo->prepare('SELECT ' . $source['select'] . ' FROM ' . $source['from'] . ' WHERE ' . implode(' AND ', $conditions) . ' ORDER BY ' . $idColumn . ' DESC LIMIT 200');
        $st->execute($params);
        $batch = $st->fetchAll(PDO::FETCH_ASSOC);
        if (!$batch) break;
        foreach ($batch as $row) {
            $cursor = mq_api_id((string)$row['id'], '物料编号');
            $scanned++;
            if (mq_classify($row) !== $kind) continue;
            if ($matched++ < $skip) continue;
            $list[] = mq_api_material_public($row);
            if (count($list) >= 21) break;
        }
        if (count($batch) < 200) break;
        if ($scanned > 200000) throw new MqReadError('搜索范围过大，请输入更完整的型号或名称');
    }
    $hasMore = count($list) > 20;
    if ($hasMore) array_pop($list);
    return ['list'=>$list, 'page'=>$page, 'has_more'=>$hasMore];
}

function mq_api_material(PDO $pdo, $id, string $kind, $qty): array {
    $id = mq_api_id($id, $kind === 'led' ? '芯片物料' : '电源物料');
    $source = mq_api_material_source($pdo);
    $st = $pdo->prepare('SELECT ' . $source['select'] . ' FROM ' . $source['from'] . ' WHERE ' . $source['active'] . ' AND m.`' . $source['map']['id'] . '`=? LIMIT 1');
    $st->execute([$id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row || mq_classify($row) !== $kind) throw new MqReadError('所选物料已停用或不属于当前种类，请重新选择');
    $material = mq_api_material_public($row);
    if ($material['price'] === null) throw new MqReadError('所选物料缺少有效价格，不能自动按零计算');
    if (trim($material['unit']) === '') throw new MqReadError('所选物料缺少计价单位，请先在网站核对');
    $material['qty'] = mq_api_numeric($qty, $kind === 'led' ? '芯片每灯用量' : '电源每灯用量');
    return $material;
}

/** A complete match is proved from server-owned version rows and freshly loaded materials. */
function mq_api_original_configuration(array $breakdown, array $result): bool {
    if (!empty($breakdown['needs_review']) || abs((float)$breakdown['published_cost_rmb'] - (float)$result['cost_rmb']) > 0.000100001) return false;
    $original = ['led'=>[], 'driver'=>[]]; $originalCosts = ['led'=>0.0, 'driver'=>0.0];
    foreach ($breakdown['component_rows'] as $row) {
        $kind = $row['kind'] ?? '';
        if (!isset($original[$kind]) || !preg_match('/^[1-9][0-9]{0,17}$/D', (string)($row['material_id'] ?? ''))) return false;
        foreach (['process','finishCost','finishCost2'] as $fee) if (!isset($row[$fee]) || (float)$row[$fee] !== 0.0) return false;
        $id = (int)$row['material_id'];
        $original[$kind][$id] = ($original[$kind][$id] ?? 0.0) + (float)$row['qty'];
        $originalCosts[$kind] += (float)$row['cost'];
    }
    foreach (['led','driver'] as $kind) {
        $selected = $result['components'][$kind] ?? null;
        $expected = array_filter($original[$kind], static fn(float $qty): bool => $qty > 0.0);
        if (!$expected) { if ($selected !== null) return false; continue; }
        if ($selected === null || count($expected) !== 1) return false;
        $id = (int)$selected['id'];
        if (!isset($expected[$id]) || abs($expected[$id] - (float)$selected['qty']) > 0.000000001) return false;
        if (abs($originalCosts[$kind] - (float)$selected['cost_rmb']) > 0.000100001) return false;
    }
    return true;
}

/** Optional read-only website tier lookup; unavailable legacy schemas keep configuration pricing. */
function mq_api_original_tier(PDO $pdo, array $loaded, array $result): ?array {
    if (!mq_api_original_configuration($loaded['breakdown'], $result)) return null;
    try {
        $policyColumns = mq_api_columns($pdo, 'quote_price_policies');
        $tierColumns = mq_api_columns($pdo, 'quote_price_tiers');
        foreach (['id','status','product_model','currency'] as $field) if (!isset($policyColumns[$field])) return null;
        foreach (['id','policy_id','min_qty','final_price','source'] as $field) if (!isset($tierColumns[$field])) return null;
        $meta = $loaded['publication_meta'];
        $system = (string)($meta['linked_system'] ?? '');
        $namingId = null;
        if (stripos($system, 'NAMING') !== false || strpos($system, '命名') !== false) {
            if (!preg_match('/^[1-9][0-9]{0,17}$/D', (string)($meta['linked_id'] ?? '')) || !isset($policyColumns['naming_id'])) return null;
            $namingId = (string)$meta['linked_id'];
        }
        $fields = ['id','product_model','currency'];
        foreach (['moq','has_stock','stock_qty','allow_below_moq','need_approval_below_moq','lead_time'] as $field) if (isset($policyColumns[$field])) $fields[] = $field;
        $where = ["status='active'", 'UPPER(TRIM(product_model))=UPPER(?)'];
        $params = [$loaded['bom']['model']];
        if ($namingId !== null) { $where[] = 'naming_id=?'; $params[] = $namingId; }
        $st = $pdo->prepare('SELECT `' . implode('`,`', $fields) . '` FROM quote_price_policies WHERE ' . implode(' AND ', $where) . ' ORDER BY id ASC LIMIT 1');
        $st->execute($params); $policy = $st->fetch(PDO::FETCH_ASSOC);
        if (!$policy) return null;
        $currency = strtoupper(trim((string)$policy['currency']));
        if (!in_array($currency, ['RMB','USD'], true)) return null;
        $st = $pdo->prepare("SELECT id,min_qty,final_price,source FROM quote_price_tiers WHERE policy_id=? AND min_qty<=? AND final_price IS NOT NULL ORDER BY min_qty DESC,FIELD(source,'approval','manual','manual_override','level_auto') ASC,id DESC LIMIT 1");
        $st->execute([(int)$policy['id'], $result['qty']]); $tier = $st->fetch(PDO::FETCH_ASSOC);
        if (!$tier || !is_numeric($tier['final_price']) || !is_finite((float)$tier['final_price']) || (float)$tier['final_price'] < 0 || (float)$tier['final_price'] > 1000000000) return null;
        $sourcePrice = (float)$tier['final_price'];
        $price = $sourcePrice;
        if ($currency !== $result['currency']) $price = $currency === 'RMB' ? $price / $result['exchange_rate'] : $price * $result['exchange_rate'];
        $money = qm_calculate([['qty'=>$result['qty'], 'price'=>$price, 'currency'=>$result['currency']]], $result['currency']);
        $warning = [];
        if (isset($policy['has_stock'], $policy['stock_qty']) && (int)$policy['has_stock'] && $result['qty'] > (float)$policy['stock_qty']) $warning[] = '报价数量超出现有库存，交期需按网站价格策略核对';
        if (isset($policy['has_stock'], $policy['moq']) && !(int)$policy['has_stock'] && $result['qty'] < (float)$policy['moq']) {
            if (empty($policy['allow_below_moq'])) $warning[] = '报价数量低于网站MOQ，请核对';
            if (!empty($policy['need_approval_below_moq'])) $warning[] = '低于MOQ，需要特批';
        }
        $formula = '原BOM配置匹配网站数量阶梯价：' . $currency . ' ' . number_format($sourcePrice, 4, '.', '');
        if ($currency !== $result['currency']) $formula .= ($currency === 'RMB' ? ' ÷ ' : ' × ') . number_format($result['exchange_rate'], 6, '.', '') . ' = ' . $result['currency'] . ' ' . number_format($money['items'][0]['price'], 4, '.', '');
        return ['money'=>$money, 'formula'=>$formula, 'warning'=>implode('；', $warning),
            'tier'=>['policy_id'=>(int)$policy['id'], 'tier_id'=>(int)$tier['id'], 'min_qty'=>(float)$tier['min_qty'], 'currency'=>$currency, 'source_price'=>$sourcePrice]];
    } catch (Throwable $e) {
        // Missing optional strategy schemas never trigger migrations or guessed tier prices.
        return null;
    }
}

/** Customer lookup follows the existing miniapp customer scope, without CRM writes. */
function mq_api_quote_customer(PDO $pdo, array $input): array {
    $id = $input['customer_id'] ?? null;
    if ($id !== null && $id !== '') {
        $id = mq_api_id($id, '报价客户');
        $st = $pdo->prepare('SELECT id,customer_name,customer_code FROM crm_customers WHERE id=? AND deleted_at IS NULL LIMIT 1');
        $st->execute([$id]); $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) throw new MqReadError('报价客户已不存在，请重新选择或手填');
        return ['id'=>$id, 'name'=>mb_substr((string)$row['customer_name'],0,200), 'code'=>mb_substr((string)$row['customer_code'],0,80), 'manual'=>false];
    }
    return ['id'=>null, 'name'=>mq_api_text($input['customer_name'] ?? '', '报价对象', 200), 'code'=>'', 'manual'=>true];
}

function mq_api_price_level(array $meta, array $input): array {
    $hasLevelKey = array_key_exists('level_id', $input);
    $levelId = $input['level_id'] ?? null;
    if ($hasLevelKey && $levelId === null) {
        $custom = $input['custom_multiplier'] ?? null;
        if ($custom === null || $custom === '') throw new MqReadError('请填写自定义报价倍率');
        $multiplier = mq_api_numeric($custom, '自定义报价倍率');
        if ($multiplier <= 0) throw new MqReadError('自定义报价倍率必须大于零');
        return ['id' => null, 'name' => '自定义倍率', 'multiplier' => $multiplier, 'is_default' => 0];
    }
    if ($levelId !== null && $levelId !== '') {
        $id = mq_api_id($levelId, '报价等级');
        foreach ($meta['levels'] as $candidate) if ($candidate['id'] === $id) return $candidate;
        throw new MqReadError('网站报价等级已停用或变化，请重新选择');
    }
    foreach ($meta['levels'] as $candidate) if ($candidate['is_default']) return $candidate;
    return $meta['levels'][0];
}

function mq_api_commission_input(array $input): ?array {
    if (!array_key_exists('commission', $input) || $input['commission'] === null) return null;
    if (!is_array($input['commission'])) throw new MqReadError('佣金参数格式不正确');
    return $input['commission'];
}

function mq_api_calculation_audit(array $result, array $customer): string {
    $clean = static fn($value): string => trim(preg_replace('/[\x00-\x1F\x7F]/u', ' ', (string)$value));
    $parts = ['客户：' . ($clean($customer['name']) ?: '未填写') . ($customer['manual'] ? '（手填）' : ' #' . $customer['id'] . ($customer['code'] !== '' ? ' / ' . $clean($customer['code']) : ''))];
    $parts[] = '配置：' . (['shell'=>'空壳','led'=>'灯具+芯片','full'=>'灯具+芯片+电源'][$result['mode']] ?? '');
    $parts[] = '数量：' . $result['qty'];
    $levelLabel = $clean($result['price_level']['name'] ?? '');
    if (($result['price_level']['id'] ?? null) === null && $levelLabel === '自定义倍率') $levelLabel = '自定义倍率';
    $parts[] = '等级：' . $levelLabel . ' ×' . ($result['multiplier'] ?? '');
    $parts[] = '单价：' . $result['currency'] . ' ' . number_format((float)$result['unit_price'],4,'.','');
    $parts[] = '金额：' . $result['currency'] . ' ' . number_format((float)$result['amount'],2,'.','');
    if ((float)($result['commission_amount'] ?? 0) > 0) {
        $parts[] = '佣金：' . $result['currency'] . ' ' . number_format((float)$result['commission_amount'],2,'.','');
        $parts[] = '净额：' . $result['currency'] . ' ' . number_format((float)$result['net_amount'],2,'.','');
    }
    $parts[] = $clean($result['version_label']);
    if (($result['price_source'] ?? '') === 'original_tier') $parts[] = '按网站阶梯价';
    return mb_substr(implode('；', $parts),0,500);
}

function mq_api_calculate(PDO $pdo, array $input): array {
    $loaded = mq_api_current_bom($pdo, $input);
    $bom = $loaded['bom'];
    $digest = mq_api_text($input['digest'] ?? '', 'BOM版本校验', 64);
    if (!preg_match('/^[a-f0-9]{64}$/D', $digest) || !hash_equals($bom['digest'], $digest)) throw new MqReadError('BOM参考成本已更新，请重新打开灯具明细核对后试算');
    $mode = mq_api_text($input['mode'] ?? '', '组合方式', 10);
    if (!in_array($mode, ['shell', 'led', 'full'], true)) throw new MqReadError('请选择空壳、灯具加芯片或灯具加芯片电源');
    $meta = mq_api_meta($pdo);
    $level = mq_api_price_level($meta, $input);
    $config = ['mode'=>$mode, 'level'=>$level, 'currency'=>mq_api_text($input['currency'] ?? 'RMB', '币种', 3),
        'exchange_rate'=>$meta['exchange_rate_usd'], 'qty'=>$input['qty'] ?? null, 'review_confirmed'=>($input['review_confirmed'] ?? null) === true,
        'commission'=>mq_api_commission_input($input)];
    if ($mode !== 'shell') $config['led'] = mq_api_material($pdo, $input['led_id'] ?? '', 'led', $input['led_qty'] ?? null);
    if ($mode === 'full') $config['driver'] = mq_api_material($pdo, $input['driver_id'] ?? '', 'driver', $input['driver_qty'] ?? null);
    try { $result = mq_calculate($loaded['breakdown'], $config); }
    catch (RuntimeException $e) { throw new MqReadError($e->getMessage()); }
    $tier = mq_api_original_tier($pdo, $loaded, $result);
    $result['price_source_label'] = '配置成本 × 网站报价倍率';
    $result['multiplier_applied'] = true;
    if ($tier !== null) {
        $result['unit_price'] = $tier['money']['items'][0]['price'];
        foreach (['qty','amount','subtotal_amount'] as $field) $result[$field] = $tier['money'][$field];
        $result['money'] = $tier['money'];
        $result['price_source'] = 'original_tier';
        $result['price_source_label'] = '原BOM配置 · 网站数量阶梯价';
        $result['multiplier_applied'] = false;
        $result['formula'] = $tier['formula'];
        $result['original_tier'] = $tier['tier'];
    }
    $warnings = [];
    if ($result['warning'] !== '') $warnings[] = $result['warning'];
    foreach (['led'=>'芯片', 'driver'=>'电源'] as $key=>$label) if (isset($config[$key]) && $config[$key]['price_status'] !== 'confirmed') {
        $warnings[] = $label . '使用物料库待核对或历史价格，本结果仅用于试算';
    }
    if ($tier !== null && $tier['warning'] !== '') $warnings[] = $tier['warning'];
    $result['warning'] = implode('；', array_unique($warnings));
    $result = array_merge($result, mq_commission_money([
        'qty'=>$result['qty'], 'unit_price'=>$result['unit_price'], 'amount'=>$result['amount'], 'currency'=>$result['currency'],
    ], $config['commission'] ?? null));
    $result['version_label'] = $bom['version_label'];
    $result['model'] = $bom['model'];
    $result['led_name'] = isset($config['led']) ? trim($config['led']['brand'] . ' ' . $config['led']['name'] . ' ' . $config['led']['model']) : '';
    $result['driver_name'] = isset($config['driver']) ? trim($config['driver']['brand'] . ' ' . $config['driver']['name'] . ' ' . $config['driver']['model']) : '';
    $result['calculated_at'] = (new DateTimeImmutable('now', new DateTimeZone('Asia/Shanghai')))->format(DATE_ATOM);
    return ['result'=>$result];
}

try {
    if (!isset($input, $quotationAuth) || !is_array($input) || !is_array($quotationAuth)) throw new MqReadError('缺少登录校验，请重新进入报价');
    $quotationPdo = db();
    if ($quotationPdo->inTransaction()) throw new MqReadError('当前请求状态异常，请重试');
    $quotationPdo->exec('SET TRANSACTION READ ONLY');
    $quotationPdo->beginTransaction();
    $quotationUserId = mq_api_id((string)($quotationAuth['user_id'] ?? ''), '登录用户');
    $quotationUser = artdon_sso_user_by_id($quotationUserId);
    if (!$quotationUser || ($quotationUser['status'] ?? '') !== 'active') throw new MqReadError('登录已失效，请重新登录');
    if (!artdon_sso_user_has_permission($quotationUser, 'quote.view')) throw new MqReadError('当前账号没有报价系统访问权限');
    $quotationOp = mq_api_text($input['op'] ?? '', '报价操作', 30);
    switch ($quotationOp) {
        case 'meta': $quotationResult = mq_api_meta($quotationPdo); break;
        case 'search_boms': $quotationResult = mq_api_search_boms($quotationPdo, $input); break;
        case 'bom_image':
            $quotationImageSize = mq_api_text($input['size'] ?? 'thumb', '图片尺寸', 10);
            if (!in_array($quotationImageSize, ['thumb','full'], true)) throw new MqReadError('图片尺寸无效');
            $quotationImageSource = mq_api_bom_image_current($quotationPdo, $input);
            $quotationPdo->rollBack();
            mq_api_emit_bom_image($quotationImageSource, $quotationImageSize);
            exit;
        case 'draft_bom_image':
            $quotationImageSize = mq_api_text($input['size'] ?? 'thumb', '图片尺寸', 10);
            if (!in_array($quotationImageSize, ['thumb','full'], true)) throw new MqReadError('图片尺寸无效');
            $quotationImageSource = mq_api_draft_bom_image_source($quotationPdo, $input);
            $quotationPdo->rollBack();
            mq_api_emit_bom_image($quotationImageSource, $quotationImageSize);
            exit;
        case 'bom_detail': $quotationLoaded = mq_api_current_bom($quotationPdo, $input); $quotationResult = ['bom'=>$quotationLoaded['bom']]; break;
        case 'search_materials': $quotationResult = mq_api_search_materials($quotationPdo, $input); break;
        case 'calculate':
            $quotationCustomer = mq_api_quote_customer($quotationPdo, $input);
            $quotationResult = mq_api_calculate($quotationPdo, $input);
            break;
        default: throw new MqReadError('不支持的报价操作');
    }
    $quotationPdo->rollBack();
    if ($quotationOp === 'calculate') {
        // Business data remains read-only; audit is written after the read transaction closes.
        $quotationResult['audit_recorded'] = mx_audit_log('quote_calculate', '报价试算', 'quote', 0, $quotationResult['result']['model'], mq_api_calculation_audit($quotationResult['result'], $quotationCustomer), $quotationUserId);
        if (!$quotationResult['audit_recorded']) $quotationResult['audit_warning'] = '试算已完成，操作日志暂未写入，请稍后重新试算';
    }
    mapi_out(true, '', $quotationResult);
} catch (Throwable $quotationError) {
    if (isset($quotationPdo) && $quotationPdo instanceof PDO && $quotationPdo->inTransaction()) $quotationPdo->rollBack();
    $quotationMessage = $quotationError instanceof MqReadError ? $quotationError->getMessage() : '报价资料暂时无法读取，请稍后重试或联系管理员核对配置';
    if ($quotationError instanceof MqImageError) mapi_out(false, $quotationMessage, ['code'=>$quotationError->imageCode]);
    mapi_out(false, $quotationMessage);
}
