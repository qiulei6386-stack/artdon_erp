<?php
/**
 * Artdon 小程序后端 API
 * 部署：/www/wwwroot/Artdon/miniapp/api/index.php
 * 访问：https://novlight.com/miniapp/api/?action=xxx
 */
header('Content-Type: application/json; charset=utf-8');

require __DIR__ . '/../../artdon_erp/includes/bootstrap.php';
require __DIR__ . '/../../artdon_erp/crm_auth.php';
require __DIR__ . '/../../artdon_erp/crm_log.php';
require __DIR__ . '/../../artdon_erp/crm_visit.php';


/** Visit.country (general_ci) vs customer.country (unicode_ci) — unify to avoid Illegal mix of collations. */
function mx_country_coalesce_expr(string $vAlias = 'v', string $cAlias = 'c'): string {
    return "COALESCE("
        . "NULLIF(TRIM(CONVERT({$vAlias}.country USING utf8mb4) COLLATE utf8mb4_unicode_ci), ''), "
        . "NULLIF(TRIM(CONVERT({$cAlias}.country USING utf8mb4) COLLATE utf8mb4_unicode_ci), ''), "
        . "'')";
}

function mx_country_display(string $raw): string {
    $raw = trim($raw);
    if ($raw === '') return '';
    static $loaded = false;
    if (!$loaded) {
        $loaded = true;
        @require_once __DIR__ . '/../../artdon_erp/crm_customer.php';
    }
    if (function_exists('crm_customer_country_display_name')) {
        try {
            $d = trim((string)crm_customer_country_display_name($raw));
            if ($d !== '' && $d !== '未填') return $d;
        } catch (Throwable $e) {}
    }
    return $raw;
}

/** Merge raw country meta rows (ISO + 中文) into display-name buckets. */
function mx_country_merge_meta(array $rows): array {
    $merged = [];
    foreach ($rows as $r) {
        $raw = trim((string)($r['name'] ?? ''));
        if ($raw === '') continue;
        $disp = mx_country_display($raw);
        if ($disp === '') $disp = $raw;
        if (!isset($merged[$disp])) $merged[$disp] = ['name' => $disp, 'cnt' => 0];
        $merged[$disp]['cnt'] += (int)($r['cnt'] ?? 0);
    }
    $list = array_values($merged);
    usort($list, static function ($a, $b) {
        if ($a['cnt'] === $b['cnt']) return strcmp($a['name'], $b['name']);
        return $b['cnt'] <=> $a['cnt'];
    });
    return $list;
}

/** Expand a picked country label (中文 / ISO) into all DB-equivalent keys for filtering. */
function mx_expand_country_filter_keys(string $picked): array {
    $picked = trim($picked);
    if ($picked === '') return [];
    $keys = [$picked];
    @require_once __DIR__ . '/../../artdon_erp/crm_customer.php';
    if (!function_exists('crm_dictionary_items')) return array_values(array_unique($keys));
    try {
        $disp = mx_country_display($picked);
        if ($disp !== '' && $disp !== $picked) $keys[] = $disp;
        foreach ((array)crm_dictionary_items('country_region', false) as $item) {
            $extra = is_array($item['extra_config'] ?? null) ? $item['extra_config'] : [];
            $iso = strtoupper(trim((string)($extra['iso'] ?? '')));
            $itemKey = trim((string)($item['item_key'] ?? ''));
            $cn = trim((string)($item['name_cn'] ?? ''));
            $en = trim((string)($item['name_en'] ?? ''));
            $short = trim((string)($item['short_name'] ?? ''));
            $aliases = is_array($extra['aliases'] ?? null) ? $extra['aliases'] : [];
            $fields = array_merge([$iso, $itemKey, $cn, $en, $short], array_map('strval', $aliases));
            $hit = false;
            foreach ($fields as $f) {
                $f = trim((string)$f);
                if ($f === '') continue;
                if ($f === $picked || strcasecmp($f, $picked) === 0) { $hit = true; break; }
            }
            if (!$hit && $disp !== '' && ($cn === $disp || $short === $disp || $en === $disp)) $hit = true;
            if (!$hit) continue;
            foreach ([$iso, $itemKey, $cn, $en, $short] as $f) {
                $f = trim((string)$f);
                if ($f !== '') $keys[] = $f;
            }
        }
    } catch (Throwable $e) {}
    return array_values(array_unique($keys));
}


function mapi_out(bool $ok, string $msg = '', $data = null): void {
    echo json_encode(['success' => $ok, 'message' => $msg, 'data' => $data], JSON_UNESCAPED_UNICODE);
    exit;
}

/** Align with CRM crm_customer_quote_exists_condition (+ customer_id when present). */
function mx_customer_quote_exists_condition(): string {
    @require_once __DIR__ . '/../../artdon_erp/crm_customer.php';
    if (!function_exists('crm_table_exists_safe') || !crm_table_exists_safe('quote_orders')) {
        return '1 = 0';
    }
    $branches = [];
    $cols = function_exists('crm_table_columns_safe') ? crm_table_columns_safe('quote_orders') : [];
    if (in_array('customer_id', $cols, true)) {
        $branches[] = 'EXISTS (SELECT 1 FROM quote_orders q WHERE q.customer_id = c.id)';
    }
    if (function_exists('crm_customer_quote_exists_condition')) {
        $crm = crm_customer_quote_exists_condition();
        if ($crm && $crm !== '1 = 0') $branches[] = $crm;
    }
    if (!$branches) return '1 = 0';
    if (count($branches) === 1) return $branches[0];
    return '(' . implode(' OR ', $branches) . ')';
}

/** Align with CRM order summary matching on quote_sales_orders. */
function mx_customer_order_exists_condition(): string {
    @require_once __DIR__ . '/../../artdon_erp/crm_customer.php';
    if (!function_exists('crm_table_exists_safe') || !crm_table_exists_safe('quote_sales_orders')) {
        return '1 = 0';
    }
    $cols = function_exists('crm_table_columns_safe') ? crm_table_columns_safe('quote_sales_orders') : [];
    $parts = [];
    if (in_array('customer_id', $cols, true)) {
        $parts[] = 'o.customer_id = c.id';
    }
    if (in_array('customer_name', $cols, true)) {
        $parts[] = "LOCATE(CONVERT(c.customer_name USING utf8mb4) COLLATE utf8mb4_unicode_ci, CONVERT(o.customer_name USING utf8mb4) COLLATE utf8mb4_unicode_ci) > 0";
    }
    if (in_array('customer_json', $cols, true)) {
        $parts[] = "LOCATE(CONVERT(c.customer_name USING utf8mb4) COLLATE utf8mb4_unicode_ci, CONVERT(o.customer_json USING utf8mb4) COLLATE utf8mb4_unicode_ci) > 0";
        $parts[] = "(c.customer_code IS NOT NULL AND c.customer_code <> '' AND LOCATE(CONVERT(c.customer_code USING utf8mb4) COLLATE utf8mb4_unicode_ci, CONVERT(o.customer_json USING utf8mb4) COLLATE utf8mb4_unicode_ci) > 0)";
    }
    if (in_array('order_no', $cols, true)) {
        $parts[] = "(c.customer_code IS NOT NULL AND c.customer_code <> '' AND LOCATE(CONVERT(c.customer_code USING utf8mb4) COLLATE utf8mb4_unicode_ci, CONVERT(o.order_no USING utf8mb4) COLLATE utf8mb4_unicode_ci) > 0)";
    }
    if (in_array('quote_no', $cols, true)) {
        $parts[] = "(c.customer_code IS NOT NULL AND c.customer_code <> '' AND LOCATE(CONVERT(c.customer_code USING utf8mb4) COLLATE utf8mb4_unicode_ci, CONVERT(o.quote_no USING utf8mb4) COLLATE utf8mb4_unicode_ci) > 0)";
    }
    if (!$parts) return '1 = 0';
    return 'EXISTS (SELECT 1 FROM quote_sales_orders o WHERE ' . implode(' OR ', $parts) . ')';
}

function mapi_input(): array {
    $raw = file_get_contents('php://input');
    $j = json_decode($raw, true);
    if (is_array($j)) return $j;
    return $_POST + $_GET;
}
/** token 鉴权：成功则设置 $_SESSION['user_id']，复用现有 CRM 权限体系 */
function mapi_auth(array $input): array {
    $token = trim((string)($input['token'] ?? $_SERVER['HTTP_X_TOKEN'] ?? ''));
    if ($token === '') mapi_out(false, '请先登录', ['code' => 'NEED_LOGIN']);
    $st = db()->prepare("SELECT t.*, u.real_name, u.username, u.status FROM miniapp_tokens t JOIN crm_users u ON u.id=t.user_id WHERE t.token=? AND t.expires_at > NOW() LIMIT 1");
    $st->execute([$token]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row || $row['status'] !== 'active') mapi_out(false, '登录已过期，请重新登录', ['code' => 'NEED_LOGIN']);
    $_SESSION['user_id'] = (int)$row['user_id'];
    $GLOBALS['mx_request_token'] = $token;
    // Release session lock ASAP so parallel photo <image> loads + JSON APIs do not serialize/hang.
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
    return $row;
}

/** Mini-program audit: admin = 超级管理员/系统管理员/老板管理员/主管 */
function mx_is_audit_admin(?int $userId = null): bool {
    $uid = $userId ?? mx_uid();
    if ($uid <= 0) return false;
    try {
        $st = db()->prepare("SELECT u.is_super_admin, r.role_key FROM crm_users u LEFT JOIN crm_roles r ON r.id = u.role_id WHERE u.id = ? LIMIT 1");
        $st->execute([$uid]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) return false;
        if ((int)($row['is_super_admin'] ?? 0) === 1) return true;
        $key = (string)($row['role_key'] ?? '');
        return in_array($key, ['super_admin', 'admin', 'boss_admin', 'manager'], true);
    } catch (Throwable $e) {
        return false;
    }
}

function mx_user_public(array $u): array {
    $id = (int)($u['id'] ?? $u['user_id'] ?? 0);
    $name = (string)($u['real_name'] ?? '');
    if ($name === '') $name = (string)($u['username'] ?? '');
    return [
        'id' => $id,
        'real_name' => $name,
        'username' => (string)($u['username'] ?? ''),
        'is_admin' => mx_is_audit_admin($id),
    ];
}

/** Write miniapp audit row; keep 7 days. Never throws to callers. */
function mx_audit_log(string $actionKey, string $actionLabel, string $targetType = '', int $targetId = 0, string $targetName = '', string $detail = '', ?int $userId = null, string $userName = ''): void {
    try {
        static $cleaned = false;
        if (!$cleaned) {
            $cleaned = true;
            try { db()->exec("DELETE FROM miniapp_audit_logs WHERE created_at < DATE_SUB(NOW(), INTERVAL 7 DAY)"); } catch (Throwable $e) {}
        }
        $uid = $userId !== null ? (int)$userId : mx_uid();
        $name = trim($userName);
        if ($name === '' && $uid > 0) {
            $st = db()->prepare("SELECT COALESCE(NULLIF(real_name,''), username, '') FROM crm_users WHERE id=? LIMIT 1");
            $st->execute([$uid]);
            $name = (string)($st->fetchColumn() ?: '');
        }
        $ip = function_exists('client_ip') ? (string)client_ip() : (string)($_SERVER['REMOTE_ADDR'] ?? '');
        db()->prepare("INSERT INTO miniapp_audit_logs (user_id, user_name, action_key, action_label, target_type, target_id, target_name, detail, ip, created_at) VALUES (?,?,?,?,?,?,?,?,?,NOW())")
            ->execute([
                $uid,
                mb_substr($name, 0, 64),
                mb_substr($actionKey, 0, 64),
                mb_substr($actionLabel, 0, 64),
                mb_substr($targetType, 0, 32),
                $targetId,
                mb_substr($targetName, 0, 255),
                mb_substr($detail, 0, 500),
                mb_substr($ip, 0, 64),
            ]);
    } catch (Throwable $e) {
        // ignore logging failures
    }
}

function mx_audit_customer_name(int $cid): string {
    if ($cid <= 0) return '';
    try {
        $st = db()->prepare("SELECT COALESCE(NULLIF(customer_name,''), customer_code, '') FROM crm_customers WHERE id=? LIMIT 1");
        $st->execute([$cid]);
        return (string)($st->fetchColumn() ?: '');
    } catch (Throwable $e) { return ''; }
}

function mx_audit_visit_name(int $vid): string {
    if ($vid <= 0) return '';
    try {
        $st = db()->prepare("SELECT COALESCE(NULLIF(c.customer_name,''), NULLIF(v.title,''), CONCAT('拜访#', v.id)) FROM crm_visit_records v LEFT JOIN crm_customers c ON c.id=v.customer_id WHERE v.id=? LIMIT 1");
        $st->execute([$vid]);
        return (string)($st->fetchColumn() ?: ('拜访#' . $vid));
    } catch (Throwable $e) { return '拜访#' . $vid; }
}


function mapi_new_token(int $userId, string $openid = '', string $appid = ''): string {
    $token = bin2hex(random_bytes(32));
    db()->prepare("INSERT INTO miniapp_tokens (user_id, token, openid, appid, expires_at) VALUES (?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL 30 DAY))")
        ->execute([$userId, $token, $openid, $appid]);
    return $token;
}
/** 微信配置（用户填 appid/secret 后启用 wx.login） */
function mapi_wx_config(): array {
    $f = __DIR__ . '/wx_config.php';
    if (is_file($f)) { $c = include $f; if (is_array($c)) return $c; }
    return ['appid' => '', 'secret' => ''];
}


/** Compress an uploaded image in-place to <=1MB JPEG (max edge 1920). */
function mx_compress_upload_under_1mb(array &$file): bool {
    $maxBytes = 1048576;
    $maxEdge = 1920;
    $tmp = (string)($file['tmp_name'] ?? '');
    if ($tmp === '' || !is_file($tmp)) return false;
    $size = (int)($file['size'] ?? @filesize($tmp));
    if ($size > 0 && $size <= $maxBytes) return true;
    if (!function_exists('imagecreatefromstring') || !function_exists('imagejpeg')) {
        return $size <= $maxBytes;
    }
    $bin = @file_get_contents($tmp);
    if ($bin === false || $bin === '') return false;
    $src = @imagecreatefromstring($bin);
    if (!$src) return false;
    $w = imagesx($src);
    $h = imagesy($src);
    if ($w < 1 || $h < 1) { imagedestroy($src); return false; }

    $tryEncode = function ($img) use ($maxBytes) {
        $best = null;
        $bestLen = PHP_INT_MAX;
        foreach ([85, 75, 65, 55, 45, 35, 25, 18, 12] as $q) {
            ob_start();
            imagejpeg($img, null, $q);
            $jpeg = ob_get_clean();
            if ($jpeg === false || $jpeg === '') continue;
            $len = strlen($jpeg);
            if ($len < $bestLen) { $best = $jpeg; $bestLen = $len; }
            if ($len <= $maxBytes) return $jpeg;
        }
        return $best;
    };

    $scales = [];
    $edgeScale = min(1.0, $maxEdge / max($w, $h));
    $scales[] = $edgeScale;
    if ($size > 0) {
        $scales[] = min($edgeScale, sqrt($maxBytes / $size) * 0.95);
        $scales[] = min($edgeScale, sqrt($maxBytes / $size) * 0.7);
        $scales[] = min($edgeScale, sqrt($maxBytes / $size) * 0.5);
    }
    $scales = array_values(array_unique(array_map(function ($s) { return max(0.05, min(1.0, (float)$s)); }, $scales)));
    sort($scales);
    $scales = array_reverse($scales); // try larger first

    $okJpeg = null;
    foreach ($scales as $scale) {
        $nw = max(1, (int)round($w * $scale));
        $nh = max(1, (int)round($h * $scale));
        if ($nw === $w && $nh === $h) {
            $img = $src;
            $owned = false;
        } else {
            $img = imagecreatetruecolor($nw, $nh);
            if (!$img) continue;
            // white bg for PNG transparency
            $white = imagecolorallocate($img, 255, 255, 255);
            imagefill($img, 0, 0, $white);
            imagecopyresampled($img, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
            $owned = true;
        }
        $jpeg = $tryEncode($img);
        if ($owned) imagedestroy($img);
        if ($jpeg !== null && strlen($jpeg) <= $maxBytes) {
            $okJpeg = $jpeg;
            break;
        }
        if ($jpeg !== null && ($okJpeg === null || strlen($jpeg) < strlen($okJpeg))) {
            $okJpeg = $jpeg;
        }
    }
    imagedestroy($src);

    if ($okJpeg === null) return false;
    if (@file_put_contents($tmp, $okJpeg) === false) return false;
    clearstatcache(true, $tmp);
    $file['size'] = strlen($okJpeg);
    $file['type'] = 'image/jpeg';
    $base = pathinfo((string)($file['name'] ?? 'photo.jpg'), PATHINFO_FILENAME);
    if ($base === '') $base = 'photo';
    $file['name'] = $base . '.jpg';
    return $file['size'] <= $maxBytes;
}

/* ===== 小程序扩展：拜访来访 样品/待办/照片标注/时间段/示例数据 ===== */
function mx_ensure(): void {
    static $done = false; if ($done) return; $done = true;
    db()->exec("CREATE TABLE IF NOT EXISTS miniapp_visit_meta (
        visit_id INT PRIMARY KEY, start_time TIME NULL, end_time TIME NULL, notes TEXT NULL,
        is_demo TINYINT(1) NOT NULL DEFAULT 0, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    db()->exec("CREATE TABLE IF NOT EXISTS miniapp_photo_meta (
        file_id INT PRIMARY KEY, visit_id INT NOT NULL, model VARCHAR(120) NOT NULL DEFAULT '', note VARCHAR(500) NOT NULL DEFAULT '',
        is_demo TINYINT(1) NOT NULL DEFAULT 0, KEY idx_pm_visit (visit_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    db()->exec("CREATE TABLE IF NOT EXISTS miniapp_visit_samples (
        id INT AUTO_INCREMENT PRIMARY KEY, visit_id INT NOT NULL, model VARCHAR(120) NOT NULL, name VARCHAR(190) NOT NULL DEFAULT '',
        qty INT NOT NULL DEFAULT 0, note VARCHAR(500) NOT NULL DEFAULT '', photo_id INT NULL, sort INT NOT NULL DEFAULT 0,
        is_demo TINYINT(1) NOT NULL DEFAULT 0, created_by INT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        KEY idx_vs_visit (visit_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    db()->exec("CREATE TABLE IF NOT EXISTS miniapp_visit_todos (
        id INT AUTO_INCREMENT PRIMARY KEY, visit_id INT NOT NULL, customer_id INT NULL,
        type VARCHAR(32) NOT NULL DEFAULT 'other', content VARCHAR(500) NOT NULL,
        owner_user_id INT NULL, due_date DATE NULL, due_time TIME NULL, status ENUM('open','done') NOT NULL DEFAULT 'open',
        done_at DATETIME NULL, extra_json JSON NULL, created_by INT NULL, is_demo TINYINT(1) NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        KEY idx_vt_visit (visit_id), KEY idx_vt_owner (owner_user_id, status, due_date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    // Widen legacy ENUM('quote','sample','other') so matter presets can persist.
    try { db()->exec("ALTER TABLE miniapp_visit_todos MODIFY type VARCHAR(32) NOT NULL DEFAULT 'other'"); } catch (Throwable $e) {}
    try { db()->exec("ALTER TABLE miniapp_visit_todos ADD COLUMN related_dispatch_id INT NULL AFTER extra_json"); } catch (Throwable $e) {}
    try { db()->exec("ALTER TABLE miniapp_visit_samples ADD COLUMN related_dispatch_id INT NULL AFTER photo_id"); } catch (Throwable $e) {}
}
function mx_matter_types(): array {
    return [
        'catalog' => '发目录',
        'docs' => '发资料',
        'quote' => '发报价',
        'sample' => '寄样品',
        'eva' => '寄EVA',
        'booth' => '寄展箱',
        'other' => '其他',
    ];
}
function mx_matter_normalize_type($raw): string {
    $t = trim((string)$raw);
    $types = mx_matter_types();
    if (isset($types[$t])) return $t;
    // legacy aliases
    if ($t === '寄样') return 'sample';
    return 'other';
}
function mx_hm($t): string { $t = trim((string)$t); if ($t === '') return ''; return substr($t, 0, 5); }
function mx_time_in($t): ?string { $t = trim((string)$t); if (!preg_match('/^(\d{1,2}):(\d{2})/', $t, $m)) return null; return sprintf('%02d:%02d:00', (int)$m[1], (int)$m[2]); }
function mx_duration(string $s, string $e): string {
    if ($s === '' || $e === '') return '';
    $a = (int)substr($s,0,2)*60 + (int)substr($s,3,2); $b = (int)substr($e,0,2)*60 + (int)substr($e,3,2);
    $d = $b - $a; if ($d <= 0) return '';
    $h = intdiv($d, 60); $m = $d % 60;
    return ($h ? $h . '小时' : '') . ($m ? $m . '分' : '');
}
function mx_uid(): int { return (int)($_SESSION['user_id'] ?? 0); }
function mx_token(): string {
    $t = trim((string)($GLOBALS['mx_request_token'] ?? ''));
    if ($t !== '') return $t;
    return trim((string)($_GET['token'] ?? $_SERVER['HTTP_X_TOKEN'] ?? ''));
}
function mx_photo_url(int $fid): string { return 'https://novlight.com/miniapp/api/?action=photo&id=' . $fid . '&token=' . urlencode(mx_token()); }
function mx_business_card_url(int $fid): string { return 'https://novlight.com/miniapp/api/?action=customer_business_card&id=' . $fid . '&token=' . urlencode(mx_token()); }
/** Stream crm_customer_files image (absolute file_path) for WeChat <image>. */
function mx_stream_customer_file(array $f): void {
    $path = (string)($f['file_path'] ?? '');
    if ($path !== '' && isset($path[0]) && $path[0] !== '/') {
        $path = __DIR__ . '/../../artdon_erp/' . ltrim($path, '/');
    }
    // Restrict to CRM customer_files storage
    $root = realpath(__DIR__ . '/../../artdon_erp/storage/customer_files');
    $real = ($path !== '' && is_file($path)) ? realpath($path) : false;
    if ($root === false || $real === false || strpos($real, $root) !== 0) {
        http_response_code(404);
        exit;
    }
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
    if (function_exists('header_remove')) {
        header_remove('Set-Cookie');
        header_remove('Expires');
        header_remove('Pragma');
        header_remove('Cache-Control');
        header_remove('Content-Type');
        header_remove('Content-Encoding');
    }
    $mime = strtolower((string)($f['file_type'] ?? $f['mime_type'] ?? ''));
    if ($mime === '' || strpos($mime, 'image/') !== 0) $mime = 'image/jpeg';
    header('Content-Type: ' . $mime);
    header('Content-Length: ' . (string)filesize($real));
    header('Cache-Control: public, max-age=86400');
    header('X-Content-Type-Options: nosniff');
    readfile($real);
    exit;
}
/** Web CRM + miniapp: treat file_kind=image or image/* mime as photo. */
function mx_file_is_image(array $f): bool {
    if (($f['file_kind'] ?? '') === 'image') return true;
    if (!empty($f['is_image'])) return true;
    $mime = strtolower((string)($f['mime_type'] ?? ''));
    return strpos($mime, 'image/') === 0;
}
/** Stream visit photo without nginx gzip (WeChat <image> cannot decode gzip JPEGs). */
function mx_stream_visit_photo(array $f): void {
    $path = __DIR__ . '/../../artdon_erp/' . ltrim((string)($f['file_path'] ?? ''), '/');
    if (!is_file($path)) { http_response_code(404); exit; }
    // Release session lock before potentially long readfile — otherwise parallel
    // <image> loads + API calls serialize/hang on the same PHPSESSID.
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
    if (function_exists('header_remove')) {
        header_remove('Set-Cookie');
        header_remove('Expires');
        header_remove('Pragma');
        header_remove('Cache-Control');
        header_remove('Content-Type');
        // Do NOT send Content-Encoding: identity — WeChat image loader can hang on it.
        header_remove('Content-Encoding');
    }
    // gzip is disabled for /miniapp/api/ in nginx; serve raw bytes.
    header('Content-Type: ' . (($f['mime_type'] ?? '') ?: 'image/jpeg'));
    header('Content-Length: ' . (string)filesize($path));
    header('Cache-Control: public, max-age=86400');
    header('X-Content-Type-Options: nosniff');
    readfile($path);
    exit;
}
function mx_user_names(): array {
    static $m = null; if ($m !== null) return $m;
    $m = [];
    foreach (db()->query("SELECT id, real_name, username FROM crm_users") as $r) $m[(int)$r['id']] = $r['real_name'] ?: $r['username'];
    return $m;
}

/** 派工状态中文 */
function mx_dispatch_status_label(string $status): string {
    static $map = [
        'pending_accept' => '待接收',
        'accepted' => '已接收',
        'in_progress' => '进行中',
        'paused' => '暂停',
        'submitted' => '待确认',
        'returned' => '退回',
        'rejected' => '拒绝',
        'done' => '已完成',
        'cancelled' => '已取消',
    ];
    return $map[$status] ?? ($status !== '' ? $status : '');
}

/**
 * 读取派工摘要。cancelled 视为无效关联（可重新派工）。
 * @return array{id:int,task_no:string,status:string,status_label:string,created_by:int,assigned_to:int,can_cancel:bool,due_at:string,assignee_name:string,creator_name:string,title:string}
 */
function mx_dispatch_info(int $dispatchId): array {
    $empty = [
        'id' => 0, 'task_no' => '', 'status' => '', 'status_label' => '',
        'created_by' => 0, 'assigned_to' => 0, 'can_cancel' => false,
        'due_at' => '', 'assignee_name' => '', 'creator_name' => '', 'title' => '',
    ];
    if ($dispatchId <= 0) return $empty;
    try {
        $st = db()->prepare("SELECT t.id, t.task_no, t.status, t.created_by, t.assigned_to, t.due_at, t.title,
            COALESCE(NULLIF(au.real_name,''), au.username, '') AS assignee_name,
            COALESCE(NULLIF(cu.real_name,''), cu.username, '') AS creator_name
            FROM dispatch_next_tasks t
            LEFT JOIN crm_users au ON au.id = t.assigned_to
            LEFT JOIN crm_users cu ON cu.id = t.created_by
            WHERE t.id = ? AND t.is_deleted = 0 LIMIT 1");
        $st->execute([$dispatchId]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        if (!$r) return $empty;
        $status = (string)($r['status'] ?? '');
        if ($status === 'cancelled') return $empty; // 已撤回：前端可重新派
        $uid = mx_uid();
        $createdBy = (int)($r['created_by'] ?? 0);
        $canCancel = $status !== 'done' && ($createdBy === $uid || mx_is_audit_admin($uid));
        return [
            'id' => (int)$r['id'],
            'task_no' => (string)($r['task_no'] ?? ''),
            'status' => $status,
            'status_label' => mx_dispatch_status_label($status),
            'created_by' => $createdBy,
            'assigned_to' => (int)($r['assigned_to'] ?? 0),
            'can_cancel' => $canCancel,
            'due_at' => (string)($r['due_at'] ?? ''),
            'assignee_name' => (string)($r['assignee_name'] ?? ''),
            'creator_name' => (string)($r['creator_name'] ?? ''),
            'title' => (string)($r['title'] ?? ''),
        ];
    } catch (Throwable $e) {
        return $empty;
    }
}

/** 批量读取派工摘要 */
function mx_dispatch_info_map(array $ids): array {
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn($v) => $v > 0)));
    $out = [];
    if (!$ids) return $out;
    try {
        $in = implode(',', $ids);
        $uid = mx_uid();
        $isAdmin = mx_is_audit_admin($uid);
        $rows = db()->query("SELECT t.id, t.task_no, t.status, t.created_by, t.assigned_to, t.due_at, t.title,
            COALESCE(NULLIF(au.real_name,''), au.username, '') AS assignee_name,
            COALESCE(NULLIF(cu.real_name,''), cu.username, '') AS creator_name
            FROM dispatch_next_tasks t
            LEFT JOIN crm_users au ON au.id = t.assigned_to
            LEFT JOIN crm_users cu ON cu.id = t.created_by
            WHERE t.id IN ($in) AND t.is_deleted = 0")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $r) {
            $status = (string)($r['status'] ?? '');
            if ($status === 'cancelled') continue;
            $createdBy = (int)($r['created_by'] ?? 0);
            $id = (int)$r['id'];
            $out[$id] = [
                'id' => $id,
                'task_no' => (string)($r['task_no'] ?? ''),
                'status' => $status,
                'status_label' => mx_dispatch_status_label($status),
                'created_by' => $createdBy,
                'assigned_to' => (int)($r['assigned_to'] ?? 0),
                'can_cancel' => $status !== 'done' && ($createdBy === $uid || $isAdmin),
                'due_at' => (string)($r['due_at'] ?? ''),
                'assignee_name' => (string)($r['assignee_name'] ?? ''),
                'creator_name' => (string)($r['creator_name'] ?? ''),
                'title' => (string)($r['title'] ?? ''),
            ];
        }
    } catch (Throwable $e) {}
    return $out;
}

/**
 * 撤回派工：同网站 cancel_task（status=cancelled），并清空关联以便可再派。
 * 仅创建人或管理员可撤回；已完成不可撤回。
 */
function mx_dispatch_cancel(int $dispatchId): array {
    if ($dispatchId <= 0) throw new RuntimeException('参数错误');
    $uid = mx_uid();
    $st = db()->prepare('SELECT * FROM dispatch_next_tasks WHERE id=? AND is_deleted=0 LIMIT 1');
    $st->execute([$dispatchId]);
    $task = $st->fetch(PDO::FETCH_ASSOC);
    if (!$task) throw new RuntimeException('派工不存在');
    $status = (string)($task['status'] ?? '');
    if ($status === 'cancelled') throw new RuntimeException('该派工已撤回');
    if ($status === 'done') throw new RuntimeException('已完成的派工不能撤回');
    $createdBy = (int)($task['created_by'] ?? 0);
    if ($createdBy !== $uid && !mx_is_audit_admin($uid)) {
        throw new RuntimeException('只有创建人或管理员可以撤回派工');
    }
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $pdo->prepare("UPDATE dispatch_next_tasks SET status='cancelled', cancelled_at=NOW(), updated_at=NOW() WHERE id=?")
            ->execute([$dispatchId]);
        $linkedTable = (string)($task['linked_table'] ?? '');
        $linkedId = (int)($task['linked_id'] ?? 0);
        if (in_array($linkedTable, ['miniapp_visit_todos', 'miniapp_visit_samples'], true) && $linkedId > 0) {
            $pdo->prepare("UPDATE {$linkedTable} SET related_dispatch_id=NULL WHERE id=? AND related_dispatch_id=?")
                ->execute([$linkedId, $dispatchId]);
        }
        // 整单拜访派工
        $pdo->prepare('UPDATE crm_visit_records SET related_dispatch_id=NULL WHERE related_dispatch_id=?')
            ->execute([$dispatchId]);
        try {
            $pdo->prepare("INSERT INTO dispatch_next_logs(task_id,user_id,action_type,field_name,old_value,new_value,note,ip,user_agent,created_at) VALUES(?,?,?,?,?,?,?,?,?,NOW())")
                ->execute([$dispatchId, $uid, 'status', 'status', $status, 'cancelled', '小程序撤回派工', $_SERVER['REMOTE_ADDR'] ?? '', substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255)]);
        } catch (Throwable $e) {}
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    return [
        'dispatch_id' => $dispatchId,
        'task_no' => (string)($task['task_no'] ?? ''),
        'status' => 'cancelled',
        'status_label' => '已取消',
    ];
}

/** 派工优先级中文 */
function mx_dispatch_priority_label(string $p): string {
    static $map = [
        'normal' => '普通',
        'important' => '重要',
        'urgent' => '紧急',
        'today' => '今日优先',
    ];
    return $map[$p] ?? ($p !== '' ? $p : '普通');
}

function mx_dispatch_standalone_task_no(PDO $pdo): string {
    do {
        $no = 'DN' . date('ymdHis') . mt_rand(100, 999);
        $st = $pdo->prepare('SELECT COUNT(*) FROM dispatch_next_tasks WHERE task_no = ?');
        $st->execute([$no]);
    } while ((int)$st->fetchColumn() > 0);
    return $no;
}

function mx_dispatch_normalize_due(string $dueIn): string {
    $due = trim(str_replace('T', ' ', $dueIn));
    if ($due !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $due)) $due .= ' 18:00:00';
    if ($due !== '' && preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $due)) $due .= ':00';
    if ($due === '' || !strtotime($due)) {
        throw new RuntimeException('请填写截止日期');
    }
    return date('Y-m-d H:i:s', strtotime($due));
}

function mx_dispatch_normalize_priority($v): string {
    $v = (string)$v;
    return in_array($v, ['normal', 'important', 'urgent', 'today'], true) ? $v : 'normal';
}

function mx_dispatch_can_edit_row(array $task, int $uid): bool {
    $status = (string)($task['status'] ?? '');
    if (in_array($status, ['done', 'cancelled'], true)) return false;
    $createdBy = (int)($task['created_by'] ?? 0);
    $assignedTo = (int)($task['assigned_to'] ?? 0);
    return $createdBy === $uid || $assignedTo === $uid || mx_is_audit_admin($uid);
}

function mx_dispatch_can_complete_row(array $task, int $uid): bool {
    $status = (string)($task['status'] ?? '');
    if (in_array($status, ['done', 'cancelled'], true)) return false;
    $createdBy = (int)($task['created_by'] ?? 0);
    $assignedTo = (int)($task['assigned_to'] ?? 0);
    return $assignedTo === $uid || $createdBy === $uid || mx_is_audit_admin($uid);
}

/**
 * 小程序新建派工：写入网站同一张 dispatch_next_tasks
 * @param array $in title, assigned_to, due_at, project, description, priority, task_date
 */
function mx_dispatch_create(array $in): array {
    $uid = mx_uid();
    if ($uid <= 0) throw new RuntimeException('登录状态无效');
    $title = trim((string)($in['title'] ?? ''));
    if ($title === '') throw new RuntimeException('请填写任务标题');
    if (function_exists('mb_strlen') && mb_strlen($title, 'UTF-8') > 240) {
        $title = mb_substr($title, 0, 240, 'UTF-8');
    }
    $taskTypeIn = trim((string)($in['task_type'] ?? $in['kind'] ?? 'dispatch'));
    if (!in_array($taskTypeIn, ['personal', 'private', 'dispatch'], true)) $taskTypeIn = 'dispatch';
    $isPersonal = in_array($taskTypeIn, ['personal', 'private'], true);
    $assignee = (int)($in['assigned_to'] ?? 0);
    if ($isPersonal) {
        $assignee = $uid; // 个人待办固定本人负责
        $taskTypeIn = 'personal';
    }
    if ($assignee <= 0) throw new RuntimeException('请选择负责人');
    $st = db()->prepare("SELECT id FROM crm_users WHERE id = ? AND status = 'active' LIMIT 1");
    $st->execute([$assignee]);
    if (!(int)$st->fetchColumn()) throw new RuntimeException('负责人无效或已停用');
    $dueAt = mx_dispatch_normalize_due((string)($in['due_at'] ?? ''));
    $priority = mx_dispatch_normalize_priority($in['priority'] ?? 'normal');
    $project = trim((string)($in['project'] ?? ''));
    if (function_exists('mb_strlen') && mb_strlen($project, 'UTF-8') > 4000) {
        $project = mb_substr($project, 0, 4000, 'UTF-8');
    }
    $desc = trim((string)($in['description'] ?? ''));
    if (function_exists('mb_strlen') && mb_strlen($desc, 'UTF-8') > 8000) {
        $desc = mb_substr($desc, 0, 8000, 'UTF-8');
    }
    $taskDate = trim((string)($in['task_date'] ?? ''));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $taskDate)) $taskDate = date('Y-m-d');
    $status = ($isPersonal || $assignee === $uid) ? 'in_progress' : 'pending_accept';
    $dispatchMode = $isPersonal ? 'personal' : 'single';

    require_once __DIR__ . '/../../artdon_erp/dispatch_next_schema.php';
    if (function_exists('dispatch_next_init_schema')) dispatch_next_init_schema();

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $taskNo = mx_dispatch_standalone_task_no($pdo);
        $pdo->prepare("INSERT INTO dispatch_next_tasks(task_no,task_type,dispatch_mode,parent_group_id,title,project,description,priority,status,created_by,assigned_to,helper_ids_json,task_date,due_at,progress,is_read,linked_system,linked_table,linked_id,linked_title,linked_json,extra_json,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW(),NOW())")
            ->execute([
                $taskNo, $taskTypeIn, $dispatchMode, null, $title, $project, $desc, $priority,
                $status, $uid, $assignee, json_encode([], JSON_UNESCAPED_UNICODE),
                $taskDate, $dueAt, 0, ($isPersonal || $assignee === $uid) ? 1 : 0,
                null, null, null, null,
                json_encode(['source' => 'miniapp_dispatch_create'], JSON_UNESCAPED_UNICODE),
                json_encode(['source' => 'miniapp_dispatch_create', 'kind' => $isPersonal ? 'personal' : 'dispatch'], JSON_UNESCAPED_UNICODE),
            ]);
        $dispatchId = (int)$pdo->lastInsertId();
        if (!$isPersonal && $assignee !== $uid) {
            try {
                $pdo->prepare("INSERT INTO dispatch_next_notifications(recipient_id,sender_id,task_id,type,title,message,created_at) VALUES(?,?,?,?,?,?,NOW())")
                    ->execute([$assignee, $uid, $dispatchId, 'new_dispatch', '派工待接收', $title]);
            } catch (Throwable $e) {}
        }
        try {
            $note = $isPersonal ? '小程序新建个人待办' : '小程序新建派工';
            $pdo->prepare("INSERT INTO dispatch_next_logs(task_id,user_id,action_type,field_name,old_value,new_value,note,ip,user_agent,created_at) VALUES(?,?,?,?,?,?,?,?,?,NOW())")
                ->execute([$dispatchId, $uid, 'create', 'title', '', $title, $note, $_SERVER['REMOTE_ADDR'] ?? '', substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255)]);
        } catch (Throwable $e) {}
        $pdo->commit();
        return [
            'id' => $dispatchId,
            'dispatch_id' => $dispatchId,
            'task_no' => $taskNo,
            'task_type' => $taskTypeIn,
            'status' => $status,
            'status_label' => mx_dispatch_status_label($status),
            'message' => ($isPersonal ? '个人待办已创建：' : '派工已创建：') . $taskNo,
        ];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

/**
 * 小程序编辑派工：同网站可改字段 title/project/description/priority/assigned_to/due_at/status
 */
function mx_dispatch_update(array $in): array {
    $id = (int)($in['id'] ?? $in['dispatch_id'] ?? 0);
    if ($id <= 0) throw new RuntimeException('参数错误');
    $uid = mx_uid();
    $st = db()->prepare('SELECT * FROM dispatch_next_tasks WHERE id=? AND is_deleted=0 LIMIT 1');
    $st->execute([$id]);
    $task = $st->fetch(PDO::FETCH_ASSOC);
    if (!$task) throw new RuntimeException('派工不存在');
    $status = (string)($task['status'] ?? '');
    if ($status === 'cancelled') throw new RuntimeException('已撤回的派工不能修改');
    if ($status === 'done' && !isset($in['status'])) {
        throw new RuntimeException('已完成的派工不能修改');
    }
    if (!mx_dispatch_can_edit_row($task, $uid) && !isset($in['status'])) {
        throw new RuntimeException('没有修改该派工的权限');
    }

    $sets = [];
    $params = [];
    $changes = [];
    $map = [
        'title' => function ($v) {
            $v = trim((string)$v);
            if ($v === '') throw new RuntimeException('请填写任务标题');
            if (function_exists('mb_strlen') && mb_strlen($v, 'UTF-8') > 240) $v = mb_substr($v, 0, 240, 'UTF-8');
            return $v;
        },
        'project' => function ($v) {
            $v = trim((string)$v);
            if (function_exists('mb_strlen') && mb_strlen($v, 'UTF-8') > 4000) $v = mb_substr($v, 0, 4000, 'UTF-8');
            return $v;
        },
        'description' => function ($v) {
            $v = trim((string)$v);
            if (function_exists('mb_strlen') && mb_strlen($v, 'UTF-8') > 8000) $v = mb_substr($v, 0, 8000, 'UTF-8');
            return $v;
        },
        'priority' => function ($v) { return mx_dispatch_normalize_priority($v); },
        'assigned_to' => function ($v) {
            $aid = (int)$v;
            if ($aid <= 0) throw new RuntimeException('请选择负责人');
            $st = db()->prepare("SELECT id FROM crm_users WHERE id = ? AND status = 'active' LIMIT 1");
            $st->execute([$aid]);
            if (!(int)$st->fetchColumn()) throw new RuntimeException('负责人无效或已停用');
            return $aid;
        },
        'due_at' => function ($v) { return mx_dispatch_normalize_due((string)$v); },
        'task_date' => function ($v) {
            $v = trim((string)$v);
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) throw new RuntimeException('看板日期格式错误');
            return $v;
        },
        'status' => function ($v) {
            $all = ['pending_accept','accepted','in_progress','paused','submitted','returned','rejected','done','cancelled'];
            $v = (string)$v;
            if (!in_array($v, $all, true)) throw new RuntimeException('状态无效');
            return $v;
        },
    ];

    foreach ($map as $field => $norm) {
        if (!array_key_exists($field, $in)) continue;
        $old = $task[$field] ?? '';
        $new = $norm($in[$field]);
        if ((string)$old === (string)$new) continue;
        if ($field === 'status' && $new === 'done' && !mx_dispatch_can_complete_row($task, $uid)) {
            throw new RuntimeException('没有完成该派工的权限');
        }
        if ($field === 'status' && $new === 'cancelled') {
            throw new RuntimeException('请使用撤回接口取消派工');
        }
        if ($field !== 'status' && !mx_dispatch_can_edit_row($task, $uid)) {
            throw new RuntimeException('没有修改该派工的权限');
        }
        $sets[] = "{$field}=?";
        $params[] = $new;
        $changes[] = [$field, $old, $new];
    }

    if (!$sets) {
        return ['id' => $id, 'changed' => 0, 'task_no' => (string)($task['task_no'] ?? '')];
    }

    // status side effects
    foreach ($changes as $ch) {
        if ($ch[0] !== 'status') continue;
        if ($ch[2] === 'done') {
            $sets[] = 'completed_at=NOW()';
            $sets[] = 'progress=100';
        }
        if ($ch[2] === 'in_progress') {
            $sets[] = 'started_at=COALESCE(started_at,NOW())';
            $sets[] = 'completed_at=NULL';
        }
        if ($ch[2] === 'accepted') {
            $sets[] = 'accepted_at=COALESCE(accepted_at,NOW())';
        }
    }

    $params[] = $id;
    $pdo = db();
    $pdo->prepare('UPDATE dispatch_next_tasks SET ' . implode(',', $sets) . ', updated_at=NOW() WHERE id=?')->execute($params);
    foreach ($changes as $ch) {
        try {
            $pdo->prepare("INSERT INTO dispatch_next_logs(task_id,user_id,action_type,field_name,old_value,new_value,note,ip,user_agent,created_at) VALUES(?,?,?,?,?,?,?,?,?,NOW())")
                ->execute([$id, $uid, 'update', $ch[0], (string)$ch[1], (string)$ch[2], '小程序编辑派工', $_SERVER['REMOTE_ADDR'] ?? '', substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255)]);
        } catch (Throwable $e) {}
    }
    return [
        'id' => $id,
        'changed' => count($changes),
        'task_no' => (string)($task['task_no'] ?? ''),
        'message' => '已保存',
    ];
}

function mx_dispatch_detail(int $id): array {
    if ($id <= 0) throw new RuntimeException('参数错误');
    $uid = mx_uid();
    $st = db()->prepare("SELECT t.*,
            COALESCE(NULLIF(au.real_name,''), au.username, '') AS assignee_name,
            COALESCE(NULLIF(cu.real_name,''), cu.username, '') AS creator_name
            FROM dispatch_next_tasks t
            LEFT JOIN crm_users au ON au.id = t.assigned_to
            LEFT JOIN crm_users cu ON cu.id = t.created_by
            WHERE t.id=? AND t.is_deleted=0 LIMIT 1");
    $st->execute([$id]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    if (!$r) throw new RuntimeException('派工不存在');
    $createdBy = (int)($r['created_by'] ?? 0);
    $assignedTo = (int)($r['assigned_to'] ?? 0);
    if ($createdBy !== $uid && $assignedTo !== $uid && !mx_is_audit_admin($uid)) {
        throw new RuntimeException('没有查看该派工的权限');
    }
    $status = (string)($r['status'] ?? '');
    $linked = [];
    if (!empty($r['linked_json'])) {
        $decoded = json_decode((string)$r['linked_json'], true);
        if (is_array($decoded)) $linked = $decoded;
    }
    $dueAt = (string)($r['due_at'] ?? '');
    $isOverdue = $dueAt !== '' && !in_array($status, ['done', 'cancelled'], true) && substr($dueAt, 0, 10) < date('Y-m-d');
    $visitId = (int)($linked['visit_id'] ?? 0);
    $customerId = (int)($linked['customer_id'] ?? 0);
    $customerName = (string)($linked['customer_name'] ?? '');
    $matterLabel = (string)($linked['matter_label'] ?? $r['linked_title'] ?? '');
    $related = $customerName !== '' ? $customerName : ($matterLabel !== '' ? $matterLabel : (string)($r['linked_title'] ?? ''));
    if ($related === '' && $visitId > 0) $related = '拜访#' . $visitId;
    $taskType = (string)($r['task_type'] ?? 'dispatch');
    $kind = in_array($taskType, ['personal', 'private'], true) ? 'personal' : 'dispatch';
    return [
        'id' => (int)$r['id'],
        'task_no' => (string)$r['task_no'],
        'task_type' => $taskType,
        'kind' => $kind,
        'kind_label' => $kind === 'personal' ? '个人' : '派工',
        'title' => (string)$r['title'],
        'project' => (string)($r['project'] ?? ''),
        'description' => (string)($r['description'] ?? ''),
        'status' => $status,
        'status_label' => mx_dispatch_status_label($status),
        'priority' => (string)($r['priority'] ?? 'normal'),
        'priority_label' => mx_dispatch_priority_label((string)($r['priority'] ?? 'normal')),
        'created_by' => $createdBy,
        'creator_name' => (string)($r['creator_name'] ?? ''),
        'assigned_to' => $assignedTo,
        'assignee_name' => (string)($r['assignee_name'] ?? ''),
        'task_date' => (string)($r['task_date'] ?? ''),
        'due_at' => $dueAt,
        'due_date' => $dueAt !== '' ? substr($dueAt, 0, 10) : '',
        'due_time' => $dueAt !== '' && strlen($dueAt) >= 16 ? substr($dueAt, 11, 5) : '18:00',
        'progress' => (int)($r['progress'] ?? 0),
        'is_overdue' => $isOverdue,
        'can_cancel' => $status !== 'done' && $status !== 'cancelled' && ($createdBy === $uid || mx_is_audit_admin($uid)),
        'can_edit' => mx_dispatch_can_edit_row($r, $uid),
        'can_complete' => mx_dispatch_can_complete_row($r, $uid),
        'visit_id' => $visitId,
        'customer_id' => $customerId,
        'customer_name' => $customerName,
        'matter_label' => $matterLabel,
        'related_label' => $related,
        'created_at' => (string)($r['created_at'] ?? ''),
        'updated_at' => (string)($r['updated_at'] ?? ''),
    ];
}

function mx_todo_public(array $t): array {
    $names = mx_user_names();
    $t['id'] = (int)$t['id']; $t['visit_id'] = (int)$t['visit_id']; $t['customer_id'] = (int)$t['customer_id'];
    $t['owner_user_id'] = (int)$t['owner_user_id'];
    $t['owner_name'] = $names[$t['owner_user_id']] ?? '';
    $t['extra'] = json_decode((string)($t['extra_json'] ?? ''), true) ?: new stdClass();
    unset($t['extra_json']);
    $t['due_time'] = mx_hm($t['due_time'] ?? '');
    $t['is_overdue'] = $t['status'] === 'open' && $t['due_date'] && $t['due_date'] < date('Y-m-d');
    $types = mx_matter_types(); $t['type_label'] = $types[$t['type'] ?? ''] ?? ($t['type'] === 'sample' ? '寄样品' : '其他');
    $t['is_demo'] = (int)$t['is_demo'];
    $t['related_dispatch_id'] = (int)($t['related_dispatch_id'] ?? 0);
    $t['dispatch_task_no'] = '';
    $t['dispatch_status'] = '';
    $t['dispatch_status_label'] = '';
    $t['can_cancel_dispatch'] = false;
    if ($t['related_dispatch_id'] > 0) {
        $info = mx_dispatch_info($t['related_dispatch_id']);
        if ($info['id'] > 0 && $info['task_no'] !== '') {
            $t['related_dispatch_id'] = $info['id'];
            $t['dispatch_task_no'] = $info['task_no'];
            $t['dispatch_status'] = $info['status'];
            $t['dispatch_status_label'] = $info['status_label'];
            $t['can_cancel_dispatch'] = !empty($info['can_cancel']);
        } else {
            $t['related_dispatch_id'] = 0;
        }
    }
    return $t;
}
/** 读取待办并做权限检查（能看到拜访单即可操作） */
function mx_todo_row(int $id): array {
    $st = db()->prepare("SELECT * FROM miniapp_visit_todos WHERE id=?"); $st->execute([$id]);
    $t = $st->fetch(PDO::FETCH_ASSOC);
    if (!$t) throw new RuntimeException('待办不存在');
    $uid = mx_uid();
    if (!is_super_admin() && (int)$t['owner_user_id'] !== $uid && (int)$t['created_by'] !== $uid) crm_visit_row((int)$t['visit_id']);
    return $t;
}
function mx_todo_query(array $f): array {
    $uid = mx_uid();
    $where = ['v.deleted_at IS NULL', '(' . crm_visit_scope_sql('v') . " OR t.owner_user_id = $uid)"]; $p = [];
    if (!empty($f['visit_id'])) { $where[] = 't.visit_id = ?'; $p[] = (int)$f['visit_id']; }
    if (!empty($f['customer_id'])) { $where[] = 't.customer_id = ?'; $p[] = (int)$f['customer_id']; }
    if (!empty($f['mine'])) { $where[] = 't.owner_user_id = ?'; $p[] = $uid; }
    if (!empty($f['type']) && isset(mx_matter_types()[(string)$f['type']])) { $where[] = 't.type = ?'; $p[] = (string)$f['type']; }
    if (!empty($f['status']) && in_array($f['status'], ['open','done'], true)) { $where[] = 't.status = ?'; $p[] = $f['status']; }
    if (!empty($f['overdue'])) $where[] = "t.status='open' AND t.due_date < CURDATE()";
    if (($f['range'] ?? '') === 'today') $where[] = "((t.status='open' AND (t.due_date IS NULL OR t.due_date <= DATE_ADD(CURDATE(), INTERVAL 7 DAY))) OR (t.status='done' AND DATE(t.done_at)=CURDATE()))";
    if (!empty($f['keyword'])) { $where[] = '(t.content LIKE ? OR c.customer_name LIKE ?)'; $p[] = '%' . $f['keyword'] . '%'; $p[] = '%' . $f['keyword'] . '%'; }
    $sql = "SELECT t.*, c.customer_name, v.visit_type, v.visit_date FROM miniapp_visit_todos t
        JOIN crm_visit_records v ON v.id=t.visit_id LEFT JOIN crm_customers c ON c.id=t.customer_id
        WHERE " . implode(' AND ', $where) . " ORDER BY t.status='done', t.due_date IS NULL, t.due_date, t.id LIMIT 200";
    $st = db()->prepare($sql); $st->execute($p);
    return array_map('mx_todo_public', $st->fetchAll(PDO::FETCH_ASSOC));
}
function mx_visit_extra(array $row): array {
    $id = (int)$row['id'];
    $st = db()->prepare("SELECT * FROM miniapp_visit_meta WHERE visit_id=?"); $st->execute([$id]);
    $meta = $st->fetch(PDO::FETCH_ASSOC) ?: [];
    $s = mx_hm($meta['start_time'] ?? $row['visit_time'] ?? ''); $e = mx_hm($meta['end_time'] ?? '');
    $row['start_time'] = $s; $row['end_time'] = $e;
    $row['time_range'] = $s . ($e ? '–' . $e : '');
    $row['duration'] = mx_duration($s, $e);
    $row['visit_datetime'] = trim(($row['visit_date'] ?? '') . ' ' . $s);
    $row['notes'] = (string)($meta['notes'] ?? $row['planned_note'] ?? '');
    $row['is_demo'] = (int)($meta['is_demo'] ?? 0);
    $pm = [];
    $st = db()->prepare("SELECT * FROM miniapp_photo_meta WHERE visit_id=?"); $st->execute([$id]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $pm[(int)$r['file_id']] = $r;
    $photos = [];
    $filesOut = [];
    foreach ((array)($row['files'] ?? []) as $f) {
        $fid = (int)($f['id'] ?? 0);
        if ($fid > 0 && mx_file_is_image($f)) {
            $f['url'] = mx_photo_url($fid);
            $cat = trim((string)($f['photo_category'] ?? 'other'));
            if (!in_array($cat, ['group', 'site', 'sample', 'factory', 'other'], true)) $cat = 'other';
            $pModel = (string)($pm[$fid]['model'] ?? '');
            $pNote = (string)($pm[$fid]['note'] ?? '');
            $pThumbs = mx_naming_thumbs_for_text(trim($pModel . ' ' . $pNote), 1);
            $photos[] = ['id' => $fid, 'url' => $f['url'], 'model' => $pModel, 'note' => $pNote, 'name' => $f['original_name'] ?? '', 'photo_category' => $cat, 'thumb_url' => $pThumbs[0] ?? ''];
        }
        $filesOut[] = $f;
    }
    usort($photos, fn($a, $b) => $a['id'] <=> $b['id']);
    $row['files'] = $filesOut;
    $row['photos'] = $photos;
    $row['cover_url'] = $photos[0]['url'] ?? '';
    $st = db()->prepare("SELECT * FROM miniapp_visit_samples WHERE visit_id=? ORDER BY sort, id"); $st->execute([$id]);
    $matterTypes = mx_matter_types();
    $sampleRows = $st->fetchAll(PDO::FETCH_ASSOC);
    $dids = [];
    foreach ($sampleRows as $s0) {
        $did = (int)($s0['related_dispatch_id'] ?? 0);
        if ($did > 0) $dids[$did] = true;
    }
    $dispatchMap = mx_dispatch_info_map(array_keys($dids));
    $row['samples'] = array_map(function ($s) use ($matterTypes, $dispatchMap) {
        $s['id'] = (int)$s['id']; $s['qty'] = (int)$s['qty']; $s['photo_id'] = $s['photo_id'] ? (int)$s['photo_id'] : null;
        $m = (string)($s['model'] ?? '');
        if (isset($matterTypes[$m])) {
            $s['type'] = $m; $s['type_label'] = $matterTypes[$m]; $s['product_model'] = '';
        } else {
            // legacy product sample row
            $s['type'] = 'sample'; $s['type_label'] = '寄样品'; $s['product_model'] = $m;
        }
        $s['related_dispatch_id'] = (int)($s['related_dispatch_id'] ?? 0);
        $info = $dispatchMap[$s['related_dispatch_id']] ?? null;
        $s['dispatch_task_no'] = $info ? (string)$info['task_no'] : '';
        $s['dispatch_status'] = $info ? (string)$info['status'] : '';
        $s['dispatch_status_label'] = $info ? (string)$info['status_label'] : '';
        $s['can_cancel_dispatch'] = $info ? !empty($info['can_cancel']) : false;
        if ($s['dispatch_task_no'] === '') $s['related_dispatch_id'] = 0;
        // naming thumbs from product text in note / legacy model
        $hay = trim((string)($s['note'] ?? '') . ' ' . (string)($s['product_model'] ?? '') . ' ' . (string)($s['name'] ?? ''));
        $s['thumbs'] = $hay !== '' ? mx_naming_thumbs_for_text($hay, 3) : [];
        return $s;
    }, $sampleRows);
    $row['matters'] = $row['samples'];
    $row['todos'] = mx_todo_query(['visit_id' => $id]);
    $row['todo_done'] = count(array_filter($row['todos'], fn($t) => $t['status'] === 'done'));
    if (!empty($row['customer_id'])) {
        $st = db()->prepare("SELECT country, city, level FROM crm_customers WHERE id=?"); $st->execute([(int)$row['customer_id']]);
        $c = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        $row['customer_country'] = $c['country'] ?? ''; $row['customer_level'] = $c['level'] ?? '';
    }
    $row['type_label'] = ($row['visit_type'] ?? '') === 'customer_arrival' ? '来访' : '拜访';
    unset($row['result_history']);
    return $row;
}

/** 事项/待办派工：写入网站同一套 dispatch_next_tasks，不另起系统 */
function mx_item_dispatch_task_no(PDO $pdo): string {
    do {
        $no = 'VM' . date('ymdHis') . mt_rand(100, 999);
        $st = $pdo->prepare('SELECT COUNT(*) FROM dispatch_next_tasks WHERE task_no = ?');
        $st->execute([$no]);
    } while ((int)$st->fetchColumn() > 0);
    return $no;
}
function mx_item_dispatch_due_at(string $dueIn = ''): string {
    $due = trim(str_replace('T', ' ', $dueIn));
    if ($due !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $due)) $due .= ' 18:00:00';
    if ($due !== '' && preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $due)) $due .= ':00';
    $ts = $due !== '' ? strtotime($due) : false;
    if (!$ts || $ts <= time()) $ts = strtotime('tomorrow 18:00');
    return date('Y-m-d H:i:s', $ts);
}
function mx_item_dispatch_assignee(int $preferred, array $visit): int {
    $uid = (int)((current_user() ?: [])['id'] ?? mx_uid());
    $cand = $preferred > 0 ? $preferred : ((int)($visit['owner_user_id'] ?? 0) ?: $uid);
    $stmt = db()->prepare("SELECT id FROM crm_users WHERE id = ? AND status = 'active' LIMIT 1");
    $stmt->execute([$cand]);
    $valid = (int)$stmt->fetchColumn();
    if ($valid > 0) return $valid;
    if ($uid > 0) return $uid;
    throw new RuntimeException('没有可用的派工负责人。');
}
/**
 * @param array $opts keys: linked_table, linked_id, matter_type, title_suffix, description, assigned_to, due_at
 */
function mx_create_item_dispatch(int $visitId, array $opts): array {
    $visit = crm_visit_row($visitId);
    crm_require('visit.dispatch');
    require_once __DIR__ . '/../../artdon_erp/dispatch_next_schema.php';
    dispatch_next_init_schema();
    $linkedTable = (string)($opts['linked_table'] ?? '');
    $linkedId = (int)($opts['linked_id'] ?? 0);
    if (!in_array($linkedTable, ['miniapp_visit_todos', 'miniapp_visit_samples'], true) || $linkedId <= 0) {
        throw new RuntimeException('派工关联参数错误');
    }
    $pdo = db();
    // 已有派工则直接返回（与网站拜访派工“不重复创建”一致）
    $existId = 0;
    if ($linkedTable === 'miniapp_visit_todos') {
        $st = $pdo->prepare('SELECT related_dispatch_id FROM miniapp_visit_todos WHERE id=? AND visit_id=? LIMIT 1');
        $st->execute([$linkedId, $visitId]);
        $existId = (int)$st->fetchColumn();
    } else {
        $st = $pdo->prepare('SELECT related_dispatch_id FROM miniapp_visit_samples WHERE id=? AND visit_id=? LIMIT 1');
        $st->execute([$linkedId, $visitId]);
        $existId = (int)$st->fetchColumn();
    }
    if ($existId > 0) {
        $st = $pdo->prepare("SELECT id, task_no, status FROM dispatch_next_tasks WHERE id=? AND is_deleted=0 AND status<>'cancelled' LIMIT 1");
        $st->execute([$existId]);
        $ex = $st->fetch(PDO::FETCH_ASSOC);
        if ($ex) {
            return ['message' => '已存在派工：' . $ex['task_no'], 'dispatch_id' => (int)$ex['id'], 'task_no' => (string)$ex['task_no'], 'existing' => true];
        }
    }
    $st = $pdo->prepare("SELECT id, task_no FROM dispatch_next_tasks WHERE linked_system='crm' AND linked_table=? AND linked_id=? AND is_deleted=0 AND status<>'cancelled' ORDER BY id DESC LIMIT 1");
    $st->execute([$linkedTable, (string)$linkedId]);
    $ex2 = $st->fetch(PDO::FETCH_ASSOC);
    if ($ex2) {
        $pdo->prepare("UPDATE {$linkedTable} SET related_dispatch_id=? WHERE id=?")->execute([(int)$ex2['id'], $linkedId]);
        return ['message' => '已存在派工：' . $ex2['task_no'], 'dispatch_id' => (int)$ex2['id'], 'task_no' => (string)$ex2['task_no'], 'existing' => true];
    }

    $uid = (int)((current_user() ?: [])['id'] ?? mx_uid());
    if ($uid <= 0) throw new RuntimeException('登录状态无效。');
    $assignee = mx_item_dispatch_assignee((int)($opts['assigned_to'] ?? 0), $visit);
    $dueAt = mx_item_dispatch_due_at((string)($opts['due_at'] ?? ''));
    // 与网站派工中心一致：task_date 用当天看板日，due_at 才是截止；否则默认 due=明天时今天列表看不到
    $taskDate = date('Y-m-d');
    $matterType = mx_matter_normalize_type($opts['matter_type'] ?? 'other');
    $types = mx_matter_types();
    $typeLabel = $types[$matterType] ?? '其他';
    $suffix = trim((string)($opts['title_suffix'] ?? ''));
    $cust = trim((string)($visit['customer_name'] ?? ''));
    $title = '事项派工：' . $typeLabel . ($suffix !== '' ? ' · ' . $suffix : '') . ($cust !== '' ? ' · ' . $cust : '');
    if (function_exists('mb_strlen') && mb_strlen($title, 'UTF-8') > 240) $title = mb_substr($title, 0, 240, 'UTF-8');
    $project = trim(implode(' · ', array_filter([$cust, trim((string)($visit['customer_code'] ?? '')), $typeLabel])));
    if ($project === '') $project = $title;
    if (function_exists('mb_strlen') && mb_strlen($project, 'UTF-8') > 4000) $project = mb_substr($project, 0, 4000, 'UTF-8');
    $desc = trim((string)($opts['description'] ?? ''));
    if ($desc === '') {
        $desc = implode("\n", array_filter([
            '来源：CRM 小程序事项派工',
            '事项：' . $typeLabel,
            '客户：' . $cust,
            '拜访/来访 #' . $visitId,
            trim((string)($opts['content'] ?? '')),
        ]));
    }
    if (function_exists('mb_strlen') && mb_strlen($desc, 'UTF-8') > 8000) $desc = mb_substr($desc, 0, 8000, 'UTF-8');
    $linked = [
        'visit_id' => $visitId,
        'visit_type' => (string)($visit['visit_type'] ?? ''),
        'kind' => 'visit_matter',
        'matter_type' => $matterType,
        'matter_label' => $typeLabel,
        'customer_id' => (int)($visit['customer_id'] ?? 0),
        'customer_name' => $cust,
        'source_row' => $linkedTable,
        'source_id' => $linkedId,
    ];
    $pdo->beginTransaction();
    try {
        $taskNo = mx_item_dispatch_task_no($pdo);
        $pdo->prepare("INSERT INTO dispatch_next_tasks(task_no,task_type,dispatch_mode,parent_group_id,title,project,description,priority,status,created_by,assigned_to,helper_ids_json,task_date,due_at,progress,is_read,linked_system,linked_table,linked_id,linked_title,linked_json,extra_json,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW(),NOW())")
            ->execute([
                $taskNo, 'dispatch', 'single', null, $title, $project, $desc, 'important',
                $assignee === $uid ? 'in_progress' : 'pending_accept',
                $uid, $assignee, json_encode([], JSON_UNESCAPED_UNICODE),
                $taskDate, $dueAt, 0, $assignee === $uid ? 1 : 0,
                'crm', $linkedTable, (string)$linkedId, $typeLabel,
                json_encode($linked, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                json_encode(['source' => 'miniapp_matter_dispatch', 'matter_type' => $matterType], JSON_UNESCAPED_UNICODE),
            ]);
        $dispatchId = (int)$pdo->lastInsertId();
        $pdo->prepare("UPDATE {$linkedTable} SET related_dispatch_id=? WHERE id=?")->execute([$dispatchId, $linkedId]);
        // 拜访记录标记需要派工（不覆盖已有整次派工 ID）
        $pdo->prepare('UPDATE crm_visit_records SET need_dispatch = 1, updated_by = ?, updated_at = NOW() WHERE id = ?')
            ->execute([$uid, $visitId]);
        $pdo->prepare("INSERT INTO dispatch_next_notifications(recipient_id,sender_id,task_id,type,title,message,created_at) VALUES(?,?,?,?,?,?,NOW())")
            ->execute([$assignee, $uid, $dispatchId, 'new_dispatch', '事项派工待接收', $title]);
        $pdo->prepare("INSERT INTO dispatch_next_logs(task_id,user_id,action_type,field_name,old_value,new_value,note,ip,user_agent,created_at) VALUES(?,?,?,?,?,?,?,?,?,NOW())")
            ->execute([$dispatchId, $uid, 'visit_matter_dispatch', 'linked_id', '', (string)$linkedId, 'CRM 小程序事项/待办派工', $_SERVER['REMOTE_ADDR'] ?? '', substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255)]);
        if (function_exists('crm_log_event')) {
            crm_log_event('visit', 'visit_matter_dispatch', 'visit', (string)$visitId, null, [
                'dispatch_id' => $dispatchId, 'task_no' => $taskNo, 'assigned_to' => $assignee,
                'due_at' => $dueAt, 'matter_type' => $matterType, 'linked_table' => $linkedTable, 'linked_id' => $linkedId,
            ]);
        }
        if (function_exists('crm_customer_timeline_add') && (int)($visit['customer_id'] ?? 0) > 0) {
            crm_customer_timeline_add((int)$visit['customer_id'], 'visit_matter_dispatch', '已创建事项派工', $taskNo . ' · ' . $title, 'visit', (string)$visitId);
        }
        $pdo->commit();
        return ['message' => '派工已创建：' . $taskNo, 'dispatch_id' => $dispatchId, 'task_no' => $taskNo, 'existing' => false, 'assigned_to' => $assignee, 'due_at' => $dueAt];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}
function mx_naming_series_sql(): string {
    return "SELECT DISTINCT TRIM(COALESCE(NULLIF(web_series,''),NULLIF(series_name,''),NULLIF(product_name,''))) AS series
        FROM naming_models
        WHERE COALESCE(website_deleted,0)=0
        HAVING series<>''
        ORDER BY series ASC";
}
function mx_naming_series_of_row(array $r): string {
    foreach (['web_series','series_name','product_name'] as $k) {
        $v = trim((string)($r[$k] ?? ''));
        if ($v !== '') return $v;
    }
    return '';
}
function mx_naming_thumb_abs(string $path): string {
    $path = trim(str_replace('\\', '/', $path));
    if ($path === '') return '';
    if (preg_match('#^https?://#i', $path)) return $path;
    if (strpos($path, '//') === 0) return 'https:' . $path;
    // Local naming uploads are public under artdon_erp/
    if (strpos($path, 'uploads/naming/') === 0 || strpos($path, '/uploads/naming/') === 0) {
        return 'https://novlight.com/artdon_erp/' . ltrim($path, '/');
    }
    if (strpos($path, 'uploads/website/') === 0 || strpos($path, '/uploads/website/') === 0
        || strpos($path, 'uploads/products/') === 0 || strpos($path, '/uploads/products/') === 0) {
        return 'https://artdonlighting.com/' . ltrim($path, '/');
    }
    return '';
}

/** Prefer a miniapp-proxied URL so WeChat only needs novlight.com. */
function mx_naming_thumb_proxy(string $modelNo = '', int $id = 0): string {
    $q = [];
    if ($modelNo !== '') $q[] = 'model_no=' . rawurlencode($modelNo);
    if ($id > 0) $q[] = 'id=' . $id;
    if (!$q) return '';
    return 'https://novlight.com/miniapp/api/?action=naming_thumb&' . implode('&', $q) . '&token=' . urlencode(mx_token());
}

/** Clear session/json headers so WeChat <image> can decode the bytes (same as visit photo). */
function mx_clear_image_response_headers(): void {
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
    @ini_set('zlib.output_compression', '0');
    while (ob_get_level() > 0) { @ob_end_clean(); }
    if (function_exists('header_remove')) {
        header_remove('Set-Cookie');
        header_remove('Expires');
        header_remove('Pragma');
        header_remove('Cache-Control');
        header_remove('Content-Type');
        header_remove('Content-Encoding');
    }
}

/** Stream naming catalog bytes with clean headers; webp → jpeg for WeChat thumbs. */
function mx_stream_naming_image_bytes(string $bin, string $ctypeHint = ''): void {
    mx_clear_image_response_headers();
    $ctype = strtolower(trim(explode(';', $ctypeHint)[0]));
    $isWebp = (strpos($ctype, 'image/webp') === 0) || (strlen($bin) >= 12 && substr($bin, 0, 4) === 'RIFF' && substr($bin, 8, 4) === 'WEBP');
    if ($isWebp && function_exists('imagecreatefromstring') && function_exists('imagejpeg')) {
        $img = @imagecreatefromstring($bin);
        if ($img) {
            ob_start();
            imagejpeg($img, null, 82);
            $jpg = ob_get_clean();
            imagedestroy($img);
            if (is_string($jpg) && $jpg !== '') {
                $bin = $jpg;
                $ctype = 'image/jpeg';
            }
        }
    }
    if ($ctype === '' || strpos($ctype, 'image/') !== 0) $ctype = 'image/jpeg';
    header('Content-Type: ' . $ctype);
    header('Content-Length: ' . (string)strlen($bin));
    header('Cache-Control: public, max-age=86400');
    header('X-Content-Type-Options: nosniff');
    echo $bin;
    exit;
}

function mx_stream_naming_local_file(string $full): void {
    mx_clear_image_response_headers();
    $ext = strtolower(pathinfo($full, PATHINFO_EXTENSION));
    if ($ext === 'webp' && function_exists('imagecreatefromwebp') && function_exists('imagejpeg')) {
        $img = @imagecreatefromwebp($full);
        if ($img) {
            ob_start();
            imagejpeg($img, null, 82);
            $jpg = ob_get_clean();
            imagedestroy($img);
            if (is_string($jpg) && $jpg !== '') {
                header('Content-Type: image/jpeg');
                header('Content-Length: ' . (string)strlen($jpg));
                header('Cache-Control: public, max-age=86400');
                header('X-Content-Type-Options: nosniff');
                echo $jpg;
                exit;
            }
        }
    }
    $mime = ['jpg'=>'image/jpeg','jpeg'=>'image/jpeg','png'=>'image/png','webp'=>'image/webp','gif'=>'image/gif'][$ext] ?? 'image/jpeg';
    header('Content-Type: ' . $mime);
    header('Content-Length: ' . (string)filesize($full));
    header('Cache-Control: public, max-age=86400');
    header('X-Content-Type-Options: nosniff');
    readfile($full);
    exit;
}

function mx_naming_row_thumb(array $r): string {
    foreach (['image_path', 'web_image_url', 'source_image_url'] as $k) {
        $raw = trim((string)($r[$k] ?? ''));
        if ($raw === '') continue;
        $abs = mx_naming_thumb_abs($raw);
        if ($abs === '') continue;
        // Public on novlight.com — load directly (no token / no PHP session headers).
        if (preg_match('#^https://novlight\.com/#i', $abs)) {
            return $abs;
        }
        $no = trim((string)($r['model_no'] ?? ''));
        $id = (int)($r['id'] ?? 0);
        $proxy = mx_naming_thumb_proxy($no, $id);
        return $proxy !== '' ? $proxy : $abs;
    }
    return '';
}

function mx_naming_model_public(array $r): array {
    $series = mx_naming_series_of_row($r);
    $dims = trim(implode(' ', array_filter([
        trim((string)($r['web_dimensions'] ?? '')),
        trim((string)($r['dim_opening'] ?? '')),
        trim((string)($r['dim_outer_d'] ?? '')),
    ])));
    return [
        'id' => (int)($r['id'] ?? 0),
        'model_no' => (string)($r['model_no'] ?? ''),
        'series' => $series,
        'lamp_type' => trim((string)($r['lamp_type'] ?? $r['item_name'] ?? '')),
        'category' => trim((string)($r['category'] ?? '')),
        'dimensions' => $dims,
        'status' => trim((string)($r['status'] ?? '')),
        'thumb_url' => mx_naming_row_thumb($r),
    ];
}

/** Extract model numbers / series markers from free text for thumb lookup. */
function mx_extract_naming_keys(string $text): array {
    $text = trim($text);
    if ($text === '') return ['models' => [], 'series' => []];
    $models = [];
    $series = [];
    if (preg_match_all('/【\s*([^】]+?)\s*】/u', $text, $m)) {
        foreach ($m[1] as $s) {
            $s = trim((string)$s);
            if ($s !== '') $series[] = $s;
        }
    }
    // common naming codes: 93.06211 / 32.02511 / D2.03501
    if (preg_match_all('/\b([A-Za-z]?\d{1,3}\.\d{3,6}[A-Za-z0-9]*)\b/', $text, $m2)) {
        foreach ($m2[1] as $n) $models[] = $n;
    }
    return [
        'models' => array_values(array_unique($models)),
        'series' => array_values(array_unique($series)),
    ];
}

/** Resolve up to $limit thumb URLs for text that may contain model nos / series. */
function mx_naming_thumbs_for_text(string $text, int $limit = 3): array {
    $keys = mx_extract_naming_keys($text);
    $thumbs = [];
    $seen = [];
    try {
        if ($keys['models']) {
            $ph = implode(',', array_fill(0, count($keys['models']), '?'));
            $st = db()->prepare("SELECT id, model_no, web_image_url, source_image_url, web_size_image_url, image_path FROM naming_models WHERE model_no IN ($ph) LIMIT 20");
            $st->execute($keys['models']);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $u = mx_naming_row_thumb($r);
                if ($u === '' || isset($seen[$u])) continue;
                $seen[$u] = true;
                $thumbs[] = $u;
                if (count($thumbs) >= $limit) return $thumbs;
            }
        }
        foreach ($keys['series'] as $series) {
            if (count($thumbs) >= $limit) break;
            $st = db()->prepare("SELECT id, model_no, web_image_url, source_image_url, web_size_image_url, image_path FROM naming_models
                WHERE (web_series = ? OR series_name = ? OR product_name = ?)
                  AND (
                    (web_image_url IS NOT NULL AND web_image_url <> '')
                    OR (source_image_url IS NOT NULL AND source_image_url <> '')
                    OR (image_path IS NOT NULL AND image_path <> '')
                  )
                ORDER BY id DESC LIMIT 1");
            $st->execute([$series, $series, $series]);
            $r = $st->fetch(PDO::FETCH_ASSOC);
            if (!$r) continue;
            $u = mx_naming_row_thumb($r);
            if ($u === '' || isset($seen[$u])) continue;
            $seen[$u] = true;
            $thumbs[] = $u;
        }
    } catch (Throwable $e) {}
    return $thumbs;
}



function mx_meta_save(int $visitId, array $in): void {
    $s = mx_time_in($in['start_time'] ?? $in['visit_time'] ?? ''); $e = mx_time_in($in['end_time'] ?? '');
    db()->prepare("INSERT INTO miniapp_visit_meta (visit_id, start_time, end_time, notes) VALUES (?,?,?,?)
        ON DUPLICATE KEY UPDATE start_time=VALUES(start_time), end_time=VALUES(end_time), notes=VALUES(notes)")
        ->execute([$visitId, $s, $e, (string)($in['notes'] ?? $in['planned_note'] ?? '')]);
}
function mx_purge_visit_children(int $visitId): void {
    db()->prepare("DELETE FROM miniapp_visit_todos WHERE visit_id=?")->execute([$visitId]);
    db()->prepare("DELETE FROM miniapp_visit_samples WHERE visit_id=?")->execute([$visitId]);
    db()->prepare("DELETE FROM miniapp_photo_meta WHERE visit_id=?")->execute([$visitId]);
    db()->prepare("DELETE FROM miniapp_visit_meta WHERE visit_id=?")->execute([$visitId]);
}


/** 小程序查重：原因中文 */
function mapi_dup_reason_label(string $r): string {
    static $map = [
        'name_similarity' => '名称相似',
        'english_name_similarity' => '英文名相似',
        'email_match' => '邮箱相同',
        'phone_match' => '电话相同',
        'whatsapp_match' => 'WhatsApp相同',
        'domain_match' => '域名相同',
        'country_name_fuzzy' => '同国家名称相近',
        'contact_email_match' => '联系人邮箱相同',
        'contact_phone_match' => '联系人电话相同',
        'exact_name' => '完全同名',
        'contact_name_same' => '同公司联系人重名',
    ];
    return $map[$r] ?? $r;
}

/** 与官网一致：高风险重复判定 */
function mapi_dup_is_high_risk(array $match): bool {
    if ((int)($match['similarity'] ?? 0) >= 90) return true;
    $reasons = (array)($match['reasons'] ?? []);
    foreach (['email_match','domain_match','phone_match','whatsapp_match','contact_email_match','contact_phone_match','exact_name'] as $r) {
        if (in_array($r, $reasons, true)) return true;
    }
    return false;
}

/** 规范化查重匹配行（含中文原因） */
function mapi_dup_normalize_matches(array $matches): array {
    $out = [];
    foreach ($matches as $m) {
        if (!is_array($m)) continue;
        $reasons = array_values(array_unique(array_map('strval', (array)($m['reasons'] ?? []))));
        $m['reasons'] = $reasons;
        $m['reason_labels'] = array_map('mapi_dup_reason_label', $reasons);
        $m['high_risk'] = mapi_dup_is_high_risk($m) ? 1 : 0;
        $out[] = $m;
    }
    return $out;
}

/** 联系人重名提醒：在相似客户下查同名联系人 */
function mapi_dup_contact_warnings(array $matches, string $contactName): array {
    $contactName = trim($contactName);
    if ($contactName === '' || !$matches) return [];
    $warnings = [];
    $seen = [];
    foreach ($matches as $m) {
        $cid = (int)($m['customer_id'] ?? 0);
        if ($cid <= 0) continue;
        // 仅对名称高度相似或同名客户检查联系人重名
        $sim = (int)($m['similarity'] ?? 0);
        $reasons = (array)($m['reasons'] ?? []);
        if ($sim < 80 && !in_array('exact_name', $reasons, true) && !in_array('name_similarity', $reasons, true)) {
            continue;
        }
        try {
            $st = db()->prepare("SELECT id, name, email, phone, is_primary FROM crm_contacts WHERE customer_id=? AND deleted_at IS NULL AND name = ? LIMIT 5");
            $st->execute([$cid, $contactName]);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $c) {
                $key = $cid . ':' . (int)$c['id'];
                if (isset($seen[$key])) continue;
                $seen[$key] = 1;
                $warnings[] = [
                    'customer_id' => $cid,
                    'customer_name' => (string)($m['customer_name'] ?? ''),
                    'customer_code' => (string)($m['customer_code'] ?? ''),
                    'contact_id' => (int)$c['id'],
                    'contact_name' => (string)$c['name'],
                    'contact_email' => (string)($c['email'] ?? ''),
                    'contact_phone' => (string)($c['phone'] ?? ''),
                    'is_primary' => (int)($c['is_primary'] ?? 0),
                    'message' => '客户「' . ($m['customer_name'] ?? '') . '」下已有同名联系人「' . $c['name'] . '」',
                ];
            }
        } catch (Throwable $e) {}
    }
    return $warnings;
}

/** 组装查重结果（含完全同名标记） */
function mapi_dup_check_payload(array $input): array {
    if (!function_exists('crm_customer_duplicate_matches')) {
        require_once __DIR__ . '/../../artdon_erp/crm_customer.php';
    }
    $name = trim((string)($input['customer_name'] ?? ''));
    $matches = $name !== '' ? crm_customer_duplicate_matches($input) : [];
    $exactName = false;
    if ($name !== '') {
        try {
            $st = db()->prepare("SELECT id, customer_code, customer_name, country, city FROM crm_customers WHERE customer_name = ? AND deleted_at IS NULL ORDER BY id DESC LIMIT 5");
            $st->execute([$name]);
            $exactRows = $st->fetchAll(PDO::FETCH_ASSOC);
            if ($exactRows) {
                $exactName = true;
                $byId = [];
                foreach ($matches as $m) $byId[(int)($m['customer_id'] ?? 0)] = true;
                foreach ($exactRows as $row) {
                    $id = (int)$row['id'];
                    if (isset($byId[$id])) {
                        foreach ($matches as &$m) {
                            if ((int)($m['customer_id'] ?? 0) === $id) {
                                $m['similarity'] = max((int)($m['similarity'] ?? 0), 100);
                                $reasons = (array)($m['reasons'] ?? []);
                                if (!in_array('exact_name', $reasons, true)) $reasons[] = 'exact_name';
                                $m['reasons'] = $reasons;
                            }
                        }
                        unset($m);
                    } else {
                        $matches[] = [
                            'customer_id' => $id,
                            'customer_code' => $row['customer_code'] ?? '',
                            'customer_name' => $row['customer_name'],
                            'customer_name_en' => '',
                            'country' => $row['country'] ?? '',
                            'city' => $row['city'] ?? '',
                            'email' => '',
                            'phone' => '',
                            'whatsapp' => '',
                            'website' => '',
                            'similarity' => 100,
                            'reasons' => ['exact_name'],
                            'owner_name' => '',
                            'last_followup' => '',
                        ];
                    }
                }
                usort($matches, fn($a, $b) => ((int)($b['similarity'] ?? 0)) <=> ((int)($a['similarity'] ?? 0)));
                $matches = array_slice($matches, 0, 10);
            }
        } catch (Throwable $e) {}
    }
    $matches = mapi_dup_normalize_matches($matches);
    $highRisk = false;
    foreach ($matches as $m) {
        if (!empty($m['high_risk'])) { $highRisk = true; break; }
    }
    $contactName = trim((string)($input['contact_name'] ?? ''));
    $contactWarnings = mapi_dup_contact_warnings($matches, $contactName);
    return [
        'matches' => $matches,
        'exact_name' => $exactName ? 1 : 0,
        'high_risk' => $highRisk ? 1 : 0,
        'need_confirm' => ($exactName || $highRisk || !empty($matches) || !empty($contactWarnings)) ? 1 : 0,
        'contact_warnings' => $contactWarnings,
    ];
}

$action = trim((string)($_GET['action'] ?? ''));
$input = mapi_input();

try {

/** Tencent Cloud TC3 signed POST (reuse OCR secrets). */
function mx_tencent_tc3_post(string $service, string $host, string $action, string $version, array $payload, string $region = 'ap-guangzhou'): array {
    $configFile = '/www/server/artdon_secrets/tencent_ocr.php';
    $config = is_file($configFile) ? require $configFile : [];
    $secretId = trim((string)($config['secret_id'] ?? ''));
    $secretKey = trim((string)($config['secret_key'] ?? ''));
    $region = trim((string)($config['region'] ?? $region)) ?: $region;
    if ($secretId === '' || $secretKey === '') {
        throw new RuntimeException('腾讯云密钥未配置');
    }
    $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($body === false) throw new RuntimeException('请求编码失败');
    $timestamp = time();
    $date = gmdate('Y-m-d', $timestamp);
    $canonicalHeaders = "content-type:application/json; charset=utf-8\nhost:{$host}\n";
    $signedHeaders = 'content-type;host';
    $canonicalRequest = "POST\n/\n\n{$canonicalHeaders}\n{$signedHeaders}\n" . hash('sha256', $body);
    $scope = "{$date}/{$service}/tc3_request";
    $stringToSign = "TC3-HMAC-SHA256\n{$timestamp}\n{$scope}\n" . hash('sha256', $canonicalRequest);
    $secretDate = hash_hmac('sha256', $date, 'TC3' . $secretKey, true);
    $secretService = hash_hmac('sha256', $service, $secretDate, true);
    $secretSigning = hash_hmac('sha256', 'tc3_request', $secretService, true);
    $signature = hash_hmac('sha256', $stringToSign, $secretSigning);
    $authorization = "TC3-HMAC-SHA256 Credential={$secretId}/{$scope}, SignedHeaders={$signedHeaders}, Signature={$signature}";
    $ch = curl_init("https://{$host}");
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT => 25,
        CURLOPT_HTTPHEADER => [
            'Authorization: ' . $authorization,
            'Content-Type: application/json; charset=utf-8',
            'Host: ' . $host,
            'X-TC-Action: ' . $action,
            'X-TC-Timestamp: ' . (string)$timestamp,
            'X-TC-Version: ' . $version,
            'X-TC-Region: ' . $region,
        ],
        CURLOPT_POSTFIELDS => $body,
    ]);
    $resp = curl_exec($ch);
    $err = curl_error($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($resp === false || $err !== '') throw new RuntimeException('腾讯云网络失败：' . $err);
    $json = json_decode((string)$resp, true);
    $response = is_array($json) ? (array)($json['Response'] ?? []) : [];
    if (!empty($response['Error'])) {
        $msg = trim((string)($response['Error']['Message'] ?? 'API error'));
        $ec = trim((string)($response['Error']['Code'] ?? ''));
        throw new RuntimeException('腾讯云失败：' . $msg . ($ec !== '' ? " ({$ec})" : ''));
    }
    if ($code !== 200) throw new RuntimeException('腾讯云异常状态：' . $code);
    return $response;
}

/** zh→en batch translate for miniapp screenshot English mode. Keeps order; skips empty. One-directional; DB cache by sha1. */
function mx_tr_has_cjk(string $s): bool { return (bool)preg_match('/[\x{4e00}-\x{9fff}]/u', $s); }

function mx_tr_cache_ready(): bool {
    static $ok = null;
    if ($ok !== null) return $ok;
    try {
        db()->exec("CREATE TABLE IF NOT EXISTS crm_translate_cache (
            h CHAR(40) NOT NULL PRIMARY KEY,
            src TEXT NOT NULL,
            en TEXT NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $ok = true;
    } catch (Throwable $e) { $ok = false; }
    return $ok;
}

/** Parallel MyMemory requests; returns [idx => english|null]. Last-resort fallback. */
function mx_translate_multi_mymemory(array $items): array {
    $res = [];
    if (!$items) return $res;
    $mh = curl_multi_init();
    $hs = [];
    foreach ($items as $k => $text) {
        $ch = curl_init('https://api.mymemory.translated.net/get?q=' . rawurlencode($text) . '&langpair=zh-CN%7Cen-GB&de=artdon.crm%40novlight.com');
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 6, CURLOPT_TIMEOUT => 15, CURLOPT_USERAGENT => 'ArtdonMiniappTranslate/1.2']);
        curl_multi_add_handle($mh, $ch);
        $hs[$k] = $ch;
    }
    do { $st = curl_multi_exec($mh, $run); if ($run) curl_multi_select($mh, 1.0); } while ($run && $st == CURLM_OK);
    foreach ($hs as $k => $ch) {
        $body = curl_multi_getcontent($ch);
        curl_multi_remove_handle($mh, $ch);
        curl_close($ch);
        $json = json_decode((string)$body, true);
        $tr = trim(html_entity_decode((string)($json['responseData']['translatedText'] ?? ''), ENT_QUOTES, 'UTF-8'));
        $status = (int)($json['responseStatus'] ?? 0);
        if ($tr === '' || $status !== 200 || stripos($tr, 'MYMEMORY WARNING') !== false || stripos($tr, 'QUERY LENGTH LIMIT') !== false) { $res[$k] = null; continue; }
        $res[$k] = $tr;
    }
    curl_multi_close($mh);
    return $res;
}

/**
 * Tencent TMT primary translator.
 * Prefer TextTranslateBatch; fall back to concurrent TextTranslate; then MyMemory.
 * Returns [idx => english|null].
 */
function mx_translate_multi_tmt(array $items): array {
    $res = [];
    if (!$items) return $res;
    foreach ($items as $k => $_) $res[$k] = null;

    $ordered = [];
    foreach ($items as $k => $text) $ordered[] = [$k, (string)$text];
    $batches = [];
    $cur = [];
    $curLen = 0;
    foreach ($ordered as $pair) {
        $len = max(1, mb_strlen($pair[1]));
        if ($cur && ($curLen + $len > 1800 || count($cur) >= 16)) {
            $batches[] = $cur;
            $cur = [];
            $curLen = 0;
        }
        $cur[] = $pair;
        $curLen += $len;
    }
    if ($cur) $batches[] = $cur;

    $batchOk = true;
    foreach ($batches as $batch) {
        $list = [];
        $keys = [];
        foreach ($batch as [$k, $text]) {
            $keys[] = $k;
            $list[] = mb_substr($text, 0, 600);
        }
        try {
            $resp = mx_tencent_tc3_post(
                'tmt',
                'tmt.tencentcloudapi.com',
                'TextTranslateBatch',
                '2018-03-21',
                [
                    'Source' => 'zh',
                    'Target' => 'en',
                    'ProjectId' => 0,
                    'SourceTextList' => $list,
                ]
            );
            $outList = $resp['TargetTextList'] ?? null;
            if (!is_array($outList) || count($outList) !== count($keys)) {
                $batchOk = false;
                break;
            }
            foreach ($keys as $i => $k) {
                $tr = trim((string)($outList[$i] ?? ''));
                $res[$k] = $tr !== '' ? $tr : null;
            }
        } catch (Throwable $e) {
            $batchOk = false;
            break;
        }
    }
    if ($batchOk) {
        $all = true;
        foreach ($res as $v) { if ($v === null) { $all = false; break; } }
        if ($all) return $res;
    }

    $need = [];
    foreach ($items as $k => $text) {
        if (($res[$k] ?? null) === null) $need[$k] = mb_substr((string)$text, 0, 600);
    }
    if ($need) {
        $mh = curl_multi_init();
        $hs = [];
        $configFile = '/www/server/artdon_secrets/tencent_ocr.php';
        $config = is_file($configFile) ? require $configFile : [];
        $secretId = trim((string)($config['secret_id'] ?? ''));
        $secretKey = trim((string)($config['secret_key'] ?? ''));
        $region = trim((string)($config['region'] ?? 'ap-guangzhou')) ?: 'ap-guangzhou';
        if ($secretId !== '' && $secretKey !== '') {
            foreach ($need as $k => $text) {
                $payloadArr = [
                    'Source' => 'zh',
                    'Target' => 'en',
                    'ProjectId' => 0,
                    'SourceText' => $text,
                ];
                $body = json_encode($payloadArr, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                $host = 'tmt.tencentcloudapi.com';
                $service = 'tmt';
                $timestamp = time();
                $date = gmdate('Y-m-d', $timestamp);
                $canonicalHeaders = "content-type:application/json; charset=utf-8\nhost:{$host}\n";
                $signedHeaders = 'content-type;host';
                $canonicalRequest = "POST\n/\n\n{$canonicalHeaders}\n{$signedHeaders}\n" . hash('sha256', $body);
                $scope = "{$date}/{$service}/tc3_request";
                $stringToSign = "TC3-HMAC-SHA256\n{$timestamp}\n{$scope}\n" . hash('sha256', $canonicalRequest);
                $secretDate = hash_hmac('sha256', $date, 'TC3' . $secretKey, true);
                $secretService = hash_hmac('sha256', $service, $secretDate, true);
                $secretSigning = hash_hmac('sha256', 'tc3_request', $secretService, true);
                $signature = hash_hmac('sha256', $stringToSign, $secretSigning);
                $authorization = "TC3-HMAC-SHA256 Credential={$secretId}/{$scope}, SignedHeaders={$signedHeaders}, Signature={$signature}";
                $ch = curl_init("https://{$host}");
                curl_setopt_array($ch, [
                    CURLOPT_POST => true,
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_CONNECTTIMEOUT => 6,
                    CURLOPT_TIMEOUT => 20,
                    CURLOPT_HTTPHEADER => [
                        'Authorization: ' . $authorization,
                        'Content-Type: application/json; charset=utf-8',
                        'Host: ' . $host,
                        'X-TC-Action: TextTranslate',
                        'X-TC-Timestamp: ' . (string)$timestamp,
                        'X-TC-Version: 2018-03-21',
                        'X-TC-Region: ' . $region,
                    ],
                    CURLOPT_POSTFIELDS => $body,
                ]);
                curl_multi_add_handle($mh, $ch);
                $hs[$k] = $ch;
            }
            do { $st = curl_multi_exec($mh, $run); if ($run) curl_multi_select($mh, 1.0); } while ($run && $st == CURLM_OK);
            foreach ($hs as $k => $ch) {
                $raw = curl_multi_getcontent($ch);
                curl_multi_remove_handle($mh, $ch);
                curl_close($ch);
                $json = json_decode((string)$raw, true);
                $response = is_array($json) ? (array)($json['Response'] ?? []) : [];
                if (!empty($response['Error'])) continue;
                $tr = trim((string)($response['TargetText'] ?? ''));
                if ($tr !== '') $res[$k] = $tr;
            }
            curl_multi_close($mh);
        }
    }

    $still = [];
    foreach ($items as $k => $text) {
        if (($res[$k] ?? null) === null) $still[$k] = $text;
    }
    if ($still) {
        $mm = mx_translate_multi_mymemory($still);
        foreach ($mm as $k => $tr) {
            if ($tr !== null && $tr !== '') $res[$k] = $tr;
        }
    }
    return $res;
}

/**
 * Translate line-structured texts: CJK lines only; keep numbering/English.
 * @return array{0: array, 1: string} [texts, provider]
 */
function mx_translate_zh_en(array $texts): array {
    $out = [];
    $lines = [];
    $plan = [];
    foreach (array_values($texts) as $i => $t) {
        $t = (string)$t;
        $out[$i] = $t;
        if (trim($t) === '' || !mx_tr_has_cjk($t)) continue;
        $parts = preg_split('/\r?\n/', mb_substr($t, 0, 2000));
        $plan[$i] = $parts;
        foreach ($parts as $p) { $p = trim($p); if ($p !== '' && mx_tr_has_cjk($p)) $lines[$p] = null; }
    }
    if (!$lines) return [array_values($out), 'none'];
    $cacheOk = mx_tr_cache_ready();
    $map = [];
    $fromCache = 0;
    if ($cacheOk) {
        $keys = array_keys($lines);
        foreach (array_chunk($keys, 200) as $chunk) {
            $hs = array_map('sha1', $chunk);
            $q = db()->prepare('SELECT h, en FROM crm_translate_cache WHERE h IN (' . implode(',', array_fill(0, count($hs), '?')) . ')');
            $q->execute($hs);
            $byH = [];
            foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $en = (string)$r['en'];
                if (mx_tr_has_cjk($en)) continue;
                $byH[$r['h']] = $en;
            }
            foreach ($chunk as $src) if (isset($byH[sha1($src)])) { $map[$src] = $byH[sha1($src)]; $fromCache++; }
        }
    }
    $miss = array_values(array_filter(array_keys($lines), fn($s) => !isset($map[$s])));
    $provider = (!$miss && $fromCache) ? 'cache' : 'tmt';
    foreach (array_chunk($miss, 12) as $chunk) {
        $req = [];
        $pre = [];
        foreach ($chunk as $k => $src) {
            if (preg_match('/^(\s*(?:\d+[\.\)、．]|[-•·])\s*)(.*)$/u', $src, $m)) { $pre[$k] = $m[1]; $req[$k] = mb_substr($m[2], 0, 480); }
            else { $pre[$k] = ''; $req[$k] = mb_substr($src, 0, 480); }
        }
        $got = mx_translate_multi_tmt($req);
        foreach ($chunk as $k => $src) {
            $tr = $got[$k] ?? null;
            if ($tr === null || $tr === '') continue;
            $tr = str_replace(['（', '）', '，', '：', '；'], ['(', ')', ', ', ': ', '; '], $tr);
            $en = trim($pre[$k] === '' ? $tr : (rtrim(str_replace('、', '.', $pre[$k])) . ' ' . $tr));
            if (mx_tr_has_cjk($en)) continue;
            $map[$src] = $en;
            if ($cacheOk) {
                try { db()->prepare('INSERT INTO crm_translate_cache (h, src, en) VALUES (?,?,?) ON DUPLICATE KEY UPDATE en=VALUES(en), src=VALUES(src)')->execute([sha1($src), $src, $en]); } catch (Throwable $e) {}
            }
        }
    }
    foreach ($plan as $i => $parts) {
        $o = [];
        foreach ($parts as $p) {
            $tp = trim($p);
            $o[] = ($tp !== '' && isset($map[$tp])) ? $map[$tp] : $p;
        }
        $out[$i] = implode("\n", $o);
    }
    return [array_values($out), $provider];
}

    switch ($action) {
        // ===== 登录 =====
        case 'login': {
            $r = login_user(trim((string)($input['username'] ?? '')), (string)($input['password'] ?? ''));
            if (!$r['success']) mapi_out(false, $r['message']);
            $token = mapi_new_token((int)$r['user']['id']);
            $_SESSION['user_id'] = (int)$r['user']['id'];
            $pub = mx_user_public($r['user']);
            mx_audit_log('login', '登录', 'user', (int)$pub['id'], (string)$pub['real_name'], '账号密码登录', (int)$pub['id'], (string)$pub['real_name']);
            mapi_out(true, '登录成功', [
                'token' => $token,
                'user' => $pub,
            ]);
            break;
        }
        case 'wxlogin': {
            // 微信一键登录：code → openid → 找绑定 → 发 token
            $cfg = mapi_wx_config();
            if ($cfg['appid'] === '' || $cfg['secret'] === '') mapi_out(false, '未配置微信小程序');
            $code = trim((string)($input['code'] ?? ''));
            if ($code === '') mapi_out(false, '缺少 code');
            $url = 'https://api.weixin.qq.com/sns/jscode2session?appid=' . urlencode($cfg['appid']) . '&secret=' . urlencode($cfg['secret']) . '&js_code=' . urlencode($code) . '&grant_type=authorization_code';
            $resp = @file_get_contents($url);
            $j = json_decode((string)$resp, true);
            $openid = (string)($j['openid'] ?? '');
            if ($openid === '') mapi_out(false, '微信登录失败：' . ($j['errmsg'] ?? '未知错误'));
            $st = db()->prepare("SELECT b.user_id, u.real_name, u.username, u.status FROM miniapp_wx_bind b JOIN crm_users u ON u.id=b.user_id WHERE b.appid=? AND b.openid=? LIMIT 1");
            $st->execute([$cfg['appid'], $openid]);
            $bind = $st->fetch(PDO::FETCH_ASSOC);
            if (!$bind || $bind['status'] !== 'active') mapi_out(false, '该微信未绑定业务员账号，请先用账号密码登录绑定', ['code' => 'NEED_BIND', 'openid' => $openid]);
            $token = mapi_new_token((int)$bind['user_id'], $openid, $cfg['appid']);
            $_SESSION['user_id'] = (int)$bind['user_id'];
            $pub = mx_user_public(['id' => (int)$bind['user_id'], 'real_name' => $bind['real_name'] ?? '', 'username' => $bind['username'] ?? '']);
            mx_audit_log('login', '登录', 'user', (int)$pub['id'], (string)$pub['real_name'], '微信登录', (int)$pub['id'], (string)$pub['real_name']);
            mapi_out(true, '登录成功', ['token' => $token, 'user' => $pub]);
            break;
        }
        case 'wxbind': {
            // 已登录状态下绑定当前微信
            $me = mapi_auth($input);
            $cfg = mapi_wx_config();
            if ($cfg['appid'] === '' || $cfg['secret'] === '') mapi_out(false, '未配置微信小程序');
            $code = trim((string)($input['code'] ?? ''));
            $url = 'https://api.weixin.qq.com/sns/jscode2session?appid=' . urlencode($cfg['appid']) . '&secret=' . urlencode($cfg['secret']) . '&js_code=' . urlencode($code) . '&grant_type=authorization_code';
            $j = json_decode((string)@file_get_contents($url), true);
            $openid = (string)($j['openid'] ?? '');
            if ($openid === '') mapi_out(false, '微信绑定失败');
            db()->prepare("INSERT INTO miniapp_wx_bind (user_id, appid, openid) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE user_id=VALUES(user_id)")
                ->execute([(int)$me['user_id'], $cfg['appid'], $openid]);
            mapi_out(true, '绑定成功，下次可微信一键登录');
            break;
        }
        case 'logout': {
            $token = trim((string)($input['token'] ?? ''));
            $uid = 0; $uname = '';
            if ($token !== '') {
                try {
                    $st = db()->prepare("SELECT t.user_id, COALESCE(NULLIF(u.real_name,''), u.username, '') AS nm FROM miniapp_tokens t LEFT JOIN crm_users u ON u.id=t.user_id WHERE t.token=? LIMIT 1");
                    $st->execute([$token]);
                    $row = $st->fetch(PDO::FETCH_ASSOC);
                    if ($row) { $uid = (int)$row['user_id']; $uname = (string)$row['nm']; }
                } catch (Throwable $e) {}
                db()->prepare("DELETE FROM miniapp_tokens WHERE token=?")->execute([$token]);
            }
            if ($uid > 0) mx_audit_log('logout', '退出登录', 'user', $uid, $uname, '', $uid, $uname);
            mapi_out(true, '已退出');
            break;
        }

        // ===== 拜访/来访列表（近7天 / 地点 / 有照片 / 国家，对齐 CRM visit 筛选） =====
        case 'visits': {
            mapi_auth($input);
            $visitType = trim((string)($input['visit_type'] ?? ''));
            $recentDays = (int)($input['recent_days'] ?? 0);
            if ($recentDays < 0) $recentDays = 0;
            if ($recentDays > 90) $recentDays = 90;
            $location = trim((string)($input['location'] ?? ''));
            $country = trim((string)($input['country'] ?? ''));
            $hasPhotoRaw = $input['has_photo'] ?? $input['has_image'] ?? '';
            $hasPhoto = ($hasPhotoRaw === 1 || $hasPhotoRaw === '1' || $hasPhotoRaw === true || $hasPhotoRaw === 'true');
            $withMeta = !empty($input['with_meta']);
            $listArgs = [
                'visit_type' => $visitType,
                'status' => trim((string)($input['status'] ?? '')),
                'range' => trim((string)($input['range'] ?? '')),
                'keyword' => trim((string)($input['keyword'] ?? '')),
                'page' => (int)($input['page'] ?? 1),
                'page_size' => min(50, max(1, (int)($input['page_size'] ?? 20))),
            ];
            if ($recentDays > 0) $listArgs['recent_days'] = $recentDays;
            if ($location !== '') $listArgs['location'] = $location;
            if ($country !== '') {
                $listArgs['country'] = $country;
                $listArgs['country_keys'] = mx_expand_country_filter_keys($country);
            }
            if ($hasPhoto) $listArgs['has_photo'] = 1;
            $list = crm_visit_list($listArgs);
            // 精简返回字段（移动端）；带封面图绝对 URL，方便列表直接展示网站同步的照片
            $coverMap = [];
            $vids = array_values(array_filter(array_map(fn($r) => (int)($r['id'] ?? 0), $list['rows'])));
            if ($vids) {
                $in = implode(',', array_fill(0, count($vids), '?'));
                $st = db()->prepare("SELECT visit_id, MIN(id) AS fid FROM crm_visit_files WHERE deleted_at IS NULL AND file_kind='image' AND visit_id IN ($in) GROUP BY visit_id");
                $st->execute($vids);
                foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $c) $coverMap[(int)$c['visit_id']] = (int)$c['fid'];
            }
            $rows = array_map(function ($r) use ($coverMap) {
                $id = (int)$r['id'];
                $coverId = $coverMap[$id] ?? 0;
                $loc = trim((string)($r['location'] ?? ''));
                $cty = trim((string)($r['country'] ?? ''));
                if ($cty === '') $cty = trim((string)($r['customer_country'] ?? ''));
                $ctyDisp = mx_country_display($cty);
                if ($ctyDisp === '') $ctyDisp = $cty;
                return [
                    'id' => $id, 'visit_type' => $r['visit_type'], 'title' => $r['title'],
                    'customer_id' => (int)($r['customer_id'] ?? 0),
                    'customer_name' => $r['customer_name'] ?? '',
                    'customer_code' => trim((string)($r['customer_code'] ?? '')),
                    'visit_category' => $r['visit_category'] ?? '',
                    'visit_date' => $r['visit_date'], 'visit_time' => $r['visit_time'],
                    'status' => $r['status'], 'owner_name' => $r['owner_name'] ?? '',
                    'location' => $loc, 'country' => $ctyDisp,
                    'image_count' => (int)($r['image_count'] ?? 0), 'result_count' => (int)($r['result_count'] ?? 0),
                    'cover_url' => $coverId > 0 ? mx_photo_url($coverId) : '',
                ];
            }, $list['rows']);
            $out = ['rows' => $rows, 'has_more' => $list['has_more'], 'stats' => $list['stats']];
            if ($withMeta) {
                $scope = crm_visit_scope_sql('v');
                $metaWhere = "v.deleted_at IS NULL AND {$scope}";
                $metaParams = [];
                if ($visitType !== '') {
                    $metaWhere .= ' AND v.visit_type = ?';
                    $metaParams[] = $visitType;
                }
                $lst = db()->prepare("SELECT v.location AS name, COUNT(*) AS cnt FROM crm_visit_records v WHERE {$metaWhere} AND v.location IS NOT NULL AND TRIM(v.location) <> '' GROUP BY v.location ORDER BY cnt DESC, v.location ASC LIMIT 80");
                $lst->execute($metaParams);
                $out['locations'] = array_map(static function ($r) {
                    return ['name' => (string)$r['name'], 'cnt' => (int)$r['cnt']];
                }, $lst->fetchAll(PDO::FETCH_ASSOC));
                // collation-safe country meta (visit.general_ci vs customer.unicode_ci)
                try {
                    $cexpr = mx_country_coalesce_expr('v', 'c');
                    $cst = db()->prepare("SELECT {$cexpr} AS name, COUNT(*) AS cnt
                        FROM crm_visit_records v
                        LEFT JOIN crm_customers c ON c.id = v.customer_id
                        WHERE {$metaWhere}
                          AND {$cexpr} <> ''
                        GROUP BY name
                        ORDER BY cnt DESC, name ASC
                        LIMIT 200");
                    $cst->execute($metaParams);
                    $rawCountries = array_map(static function ($r) {
                        return ['name' => (string)$r['name'], 'cnt' => (int)$r['cnt']];
                    }, $cst->fetchAll(PDO::FETCH_ASSOC));
                    $out['countries'] = mx_country_merge_meta($rawCountries);
                } catch (Throwable $e) {
                    $out['countries'] = [];
                }
            }
            mapi_out(true, '', $out);
            break;
        }
        case 'visit': {
            mapi_auth($input);
            $id = (int)($input['id'] ?? 0);
            if ($id <= 0) mapi_out(false, '参数错误');
            $row = crm_visit_row($id);
            // 签到记录
            $st = db()->prepare("SELECT lat, lng, address, created_at FROM miniapp_checkins WHERE visit_id=? ORDER BY id DESC");
            $st->execute([$id]);
            $row['checkins'] = $st->fetchAll(PDO::FETCH_ASSOC);
            // 照片按分类分组（补绝对 HTTPS URL，与网站 crm_visit_files 对齐）
            $groups = ['group' => [], 'site' => [], 'sample' => [], 'factory' => [], 'other' => []];
            foreach ((array)($row['files'] ?? []) as $f) {
                if (!mx_file_is_image($f)) continue;
                $c = $f['photo_category'] ?? 'other';
                if (!isset($groups[$c])) $c = 'other';
                $f['url'] = mx_photo_url((int)$f['id']);
                $groups[$c][] = $f;
            }
            $row['photo_groups'] = $groups;
            mx_ensure();
            $row = mx_visit_extra($row);
            $row['related_dispatch_id'] = (int)($row['related_dispatch_id'] ?? 0);
            $row['need_dispatch'] = (int)($row['need_dispatch'] ?? 0);
            $row['dispatch_task_no'] = '';
            $row['dispatch_status'] = '';
            $row['dispatch_status_label'] = '';
            $row['can_cancel_dispatch'] = false;
            if ($row['related_dispatch_id'] > 0) {
                $info = mx_dispatch_info($row['related_dispatch_id']);
                if ($info['id'] > 0 && $info['task_no'] !== '') {
                    $row['related_dispatch_id'] = $info['id'];
                    $row['dispatch_task_no'] = $info['task_no'];
                    $row['dispatch_status'] = $info['status'];
                    $row['dispatch_status_label'] = $info['status_label'];
                    $row['can_cancel_dispatch'] = !empty($info['can_cancel']);
                } else {
                    $row['related_dispatch_id'] = 0;
                }
            }
            mx_audit_log('view_visit', '查看拜访详情', 'visit', $id, (string)($row['customer_name'] ?? $row['title'] ?? mx_audit_visit_name($id)));
            mapi_out(true, '', $row);
            break;
        }

        // ===== 新建/编辑（移动端简化版） =====
        case 'visit_save': {
            mapi_auth($input);
            $type = ($input['visit_type'] ?? 'customer_visit') === 'customer_arrival' ? 'customer_arrival' : 'customer_visit';
            $data = [
                'visit_id' => (int)($input['id'] ?? 0),
                'visit_type' => $type,
                'customer_id' => (int)($input['customer_id'] ?? 0),
                'contact_id' => (int)($input['contact_id'] ?? 0),
                'title' => trim((string)($input['title'] ?? '')),
                'purpose' => trim((string)($input['purpose'] ?? '')),
                'visit_category' => trim((string)($input['visit_category'] ?? '')),
                'visit_date' => trim((string)($input['visit_date'] ?? '')),
                'visit_time' => (string)(mx_time_in($input['start_time'] ?? $input['visit_time'] ?? '') ?? ''),
                'location' => trim((string)($input['location'] ?? '')),
                'visitor_count' => (int)($input['visitor_count'] ?? 0),
                'status' => 'pending_execute',  // 移动端简化：建单即待执行
                'client_request_id' => trim((string)($input['client_request_id'] ?? '')),
                // 来访接待需求
                'need_sample' => !empty($input['need_sample']), 'need_material' => !empty($input['need_material']),
                'need_quote' => !empty($input['need_quote']), 'need_demo' => !empty($input['need_demo']),
                'need_technical' => !empty($input['need_technical']), 'need_boss' => !empty($input['need_boss']),
                'need_meeting_room' => !empty($input['need_meeting_room']), 'need_factory_tour' => !empty($input['need_factory_tour']),
                'need_pickup' => !empty($input['need_pickup']), 'need_hotel' => !empty($input['need_hotel']),
                'need_catering' => !empty($input['need_catering']),
                'planned_note' => trim((string)($input['notes'] ?? $input['planned_note'] ?? '')),
            ];
            $isEdit = (int)($input['id'] ?? 0) > 0;
            $r = crm_visit_save($data);
            mx_ensure();
            $vidSaved = (int)($r['record']['id'] ?? 0);
            if ($vidSaved > 0) mx_meta_save($vidSaved, $input);
            $r = ['id' => $vidSaved, 'duplicate_request' => $r['duplicate_request'] ?? 0];
            $vname = mx_audit_visit_name($vidSaved);
            if ($isEdit) mx_audit_log('visit_edit', '编辑拜访', 'visit', $vidSaved, $vname);
            else mx_audit_log('visit_create', $type === 'customer_visit' ? '新建拜访' : '新建来访', 'visit', $vidSaved, $vname);
            mapi_out(true, $type === 'customer_visit' ? '拜访计划已创建' : '来访接待单已创建', $r);
            break;
        }

        // ===== GPS 签到 =====
        case 'visit_checkin': {
            $me = mapi_auth($input);
            $id = (int)($input['id'] ?? 0);
            $lat = (float)($input['lat'] ?? 0); $lng = (float)($input['lng'] ?? 0);
            if ($id <= 0 || $lat == 0 || $lng == 0) mapi_out(false, '参数错误');
            $row = crm_visit_row($id); // 顺带鉴权
            if ($row['visit_type'] !== 'customer_visit') mapi_out(false, '来访不需要签到');
            db()->prepare("INSERT INTO miniapp_checkins (visit_id, user_id, lat, lng, address) VALUES (?, ?, ?, ?, ?)")
                ->execute([$id, (int)$me['user_id'], $lat, $lng, substr(trim((string)($input['address'] ?? '')), 0, 255)]);
            db()->prepare("UPDATE crm_visit_records SET status='executing', updated_at=NOW() WHERE id=? AND status IN ('pending_execute','confirmed','pending_confirm')")
                ->execute([$id]);
            mapi_out(true, '签到成功');
            break;
        }

        // ===== 填结果 =====
        case 'visit_result': {
            mapi_auth($input);
            $id = (int)($input['id'] ?? 0);
            if ($id <= 0) mapi_out(false, '参数错误');
            $r = crm_visit_result_save($id, [
                'actual_people' => trim((string)($input['actual_people'] ?? '')),
                'customer_feedback' => trim((string)($input['customer_feedback'] ?? '')),
                'customer_needs' => trim((string)($input['customer_needs'] ?? '')),
                'products_discussed' => trim((string)($input['products_discussed'] ?? '')),
                'result' => trim((string)($input['result'] ?? '')),
                'result_note' => trim((string)($input['result_note'] ?? '')),
                'next_action' => trim((string)($input['next_action'] ?? '')),
                'next_followup_time' => trim((string)($input['next_followup_time'] ?? '')),
                'deal_probability' => (int)($input['deal_probability'] ?? 0),
                'need_quote' => !empty($input['need_quote']), 'need_material' => !empty($input['need_material']),
                'need_sample' => !empty($input['need_sample']),
            ]);
            mapi_out(true, '结果已提交', $r);
            break;
        }

        // ===== 客户选择器（近N天新增 / 有代码 / 国家） =====
        case 'customers': {
            $auth = mapi_auth($input);
            $uid = (int)($auth['user_id'] ?? $_SESSION['user_id'] ?? 0);
            $kw = trim((string)($input['keyword'] ?? $input['q'] ?? $input['search'] ?? ''));
            $page = max(1, (int)($input['page'] ?? 1));
            $ps = 20; $off = ($page - 1) * $ps;
            $recentDays = (int)($input['recent_days'] ?? 0);
            if ($recentDays < 0) $recentDays = 0;
            if ($recentDays > 90) $recentDays = 90;
            $hasCodeRaw = $input['has_code'] ?? '';
            $hasCode = ($hasCodeRaw === 1 || $hasCodeRaw === '1' || $hasCodeRaw === true || $hasCodeRaw === 'true');
            $hasQuoteRaw = $input['has_quote'] ?? '';
            $hasQuote = ($hasQuoteRaw === 1 || $hasQuoteRaw === '1' || $hasQuoteRaw === true || $hasQuoteRaw === 'true');
            $hasOrderRaw = $input['has_order'] ?? '';
            $hasOrder = ($hasOrderRaw === 1 || $hasOrderRaw === '1' || $hasOrderRaw === true || $hasOrderRaw === 'true');
            $level = trim((string)($input['level'] ?? $input['customer_level'] ?? ''));
            $country = trim((string)($input['country'] ?? ''));
            $withMeta = !empty($input['with_meta']); // countries/levels meta only when client asks (lean list)

            $where = 'c.deleted_at IS NULL';
            $params = [];
            if ($kw !== '') {
                $where .= ' AND (c.customer_name LIKE ? OR c.customer_name_en LIKE ? OR c.customer_code LIKE ? OR c.country LIKE ? OR c.city LIKE ?)';
                $like = '%' . $kw . '%';
                $params = array_merge($params, [$like, $like, $like, $like, $like]);
            }
            if ($recentDays > 0) {
                $where .= ' AND c.created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)';
                $params[] = $recentDays;
            }
            if ($hasCode) {
                $where .= " AND c.customer_code IS NOT NULL AND TRIM(c.customer_code) <> ''";
            }
            if ($hasQuote) {
                $where .= ' AND ' . mx_customer_quote_exists_condition();
            }
            if ($hasOrder) {
                $where .= ' AND ' . mx_customer_order_exists_condition();
            }
            if ($level !== '') {
                $where .= ' AND c.level = ?';
                $params[] = $level;
            }
            if ($country !== '') {
                $ckeys = mx_expand_country_filter_keys($country);
                if (!$ckeys) $ckeys = [$country];
                $ph = implode(',', array_fill(0, count($ckeys), '?'));
                $where .= " AND CONVERT(c.country USING utf8mb4) COLLATE utf8mb4_unicode_ci IN ($ph)";
                $params = array_merge($params, $ckeys);
            }
            if ($kw !== '') {
                $order = 'c.id DESC';
            } elseif ($recentDays > 0) {
                $order = 'c.created_at DESC, c.id DESC';
            } else {
                $order = '(c.owner_user_id = ?) DESC, c.updated_at DESC, c.id DESC';
                $params[] = $uid;
            }
            $st = db()->prepare("SELECT c.id, c.customer_name, c.customer_code, c.country, c.city, c.level, c.owner_user_id, c.created_at, COALESCE(NULLIF(u.real_name,''), u.username, '') AS owner_name FROM crm_customers c LEFT JOIN crm_users u ON u.id = c.owner_user_id WHERE $where ORDER BY $order LIMIT " . ($ps + 1) . " OFFSET $off");
            $st->execute($params);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
            $hasMore = count($rows) > $ps;
            $out = ['rows' => array_slice($rows, 0, $ps), 'has_more' => $hasMore];
            if ($withMeta) {
                $cst = db()->query("SELECT CONVERT(country USING utf8mb4) COLLATE utf8mb4_unicode_ci AS name, COUNT(*) AS cnt FROM crm_customers WHERE deleted_at IS NULL AND country IS NOT NULL AND TRIM(country) <> '' GROUP BY name ORDER BY cnt DESC, name ASC LIMIT 200");
                $rawCountries = array_map(static function ($r) {
                    return ['name' => (string)$r['name'], 'cnt' => (int)$r['cnt']];
                }, $cst->fetchAll(PDO::FETCH_ASSOC));
                $out['countries'] = mx_country_merge_meta($rawCountries);
                // customer levels — align CRM dictionary customer_level (P0–P5 / Blacklist)
                $levels = [];
                try {
                    @require_once __DIR__ . '/../../artdon_erp/crm_customer.php';
                    if (function_exists('crm_dictionary_keys')) {
                        foreach ((array)crm_dictionary_keys('customer_level') as $k) {
                            $k = trim((string)$k);
                            if ($k === '') continue;
                            $levels[] = ['key' => $k, 'label' => $k, 'name' => $k];
                        }
                    }
                } catch (Throwable $e) {}
                if (!$levels) {
                    try {
                        $lst = db()->query("SELECT level AS name, COUNT(*) AS cnt FROM crm_customers WHERE deleted_at IS NULL AND level IS NOT NULL AND CHAR_LENGTH(TRIM(level)) > 0 GROUP BY level ORDER BY level ASC");
                        foreach ($lst->fetchAll(PDO::FETCH_ASSOC) as $r) {
                            $n = trim((string)$r['name']);
                            if ($n === '') continue;
                            $levels[] = ['key' => $n, 'label' => $n, 'name' => $n, 'cnt' => (int)$r['cnt']];
                        }
                    } catch (Throwable $e) {}
                }
                $out['levels'] = $levels;
            }
            mapi_out(true, '', $out);
            break;
        }

        // ===== 照片上传（支持分类） =====
        case 'upload': {
            mapi_auth($input);
            $id = (int)($input['visit_id'] ?? $_POST['visit_id'] ?? 0);
            if ($id <= 0) mapi_out(false, '参数错误');
            crm_visit_row($id); // 鉴权
            if (empty($_FILES['file'])) mapi_out(false, '没有文件');
            $cat = trim((string)($input['photo_category'] ?? $_POST['photo_category'] ?? 'other'));
            $allowed = ['group', 'site', 'sample', 'factory', 'other'];
            if (!in_array($cat, $allowed, true)) $cat = 'other';
            // Server-side: force under 1MB (max edge 1920) before CRM store
            if (!mx_compress_upload_under_1mb($_FILES['file'])) {
                $sz = (int)($_FILES['file']['size'] ?? 0);
                if ($sz > 1048576) mapi_out(false, '图片过大，压缩后仍超过1MB，请换一张较小的图');
            }
            $r = crm_visit_upload_files($id, 'image', $_FILES['file']);
            foreach ((array)($r['uploaded'] ?? []) as $f) {
                if (!empty($f['id'])) db()->prepare("UPDATE crm_visit_files SET photo_category=? WHERE id=?")->execute([$cat, (int)$f['id']]);
                $pmModel = trim((string)($_POST['model'] ?? $input['model'] ?? '')); $pmNote = trim((string)($_POST['note'] ?? $input['note'] ?? ''));
                if (!empty($f['id'])) { mx_ensure(); db()->prepare("INSERT INTO miniapp_photo_meta (file_id, visit_id, model, note) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE model=VALUES(model), note=VALUES(note)")->execute([(int)$f['id'], $id, mb_substr($pmModel,0,120), mb_substr($pmNote,0,500)]); }
            }
            mx_audit_log('photo_upload', '上传照片', 'visit', $id, mx_audit_visit_name($id), $cat);
            mapi_out(true, '上传成功', $r);
            break;
        }

        // ===== 样品需求 =====
        case 'sample_save': {
            $me = mapi_auth($input);
            $id = (int)($input['visit_id'] ?? 0);
            $pname = trim((string)($input['product_name'] ?? ''));
            if ($id <= 0 || $pname === '') mapi_out(false, '拜访单和产品名称必填');
            crm_visit_row($id); // 鉴权
            db()->prepare("INSERT INTO miniapp_samples (visit_id, user_id, product_name, model, quantity, purpose, photo_url) VALUES (?, ?, ?, ?, ?, ?, ?)")
                ->execute([$id, (int)$me['user_id'], $pname, substr(trim((string)($input['model'] ?? '')), 0, 255), max(1, (int)($input['quantity'] ?? 1)), substr(trim((string)($input['purpose'] ?? '')), 0, 500), substr(trim((string)($input['photo_url'] ?? '')), 0, 500)]);
            mapi_out(true, '样品需求已提交', ['id' => (int)db()->lastInsertId()]);
            break;
        }
        case 'samples': {
            mapi_auth($input);
            $id = (int)($input['visit_id'] ?? 0);
            if ($id <= 0) mapi_out(false, '参数错误');
            crm_visit_row($id); // 鉴权
            $st = db()->prepare("SELECT s.*, u.real_name FROM miniapp_samples s LEFT JOIN crm_users u ON u.id=s.user_id WHERE s.visit_id=? ORDER BY s.id DESC");
            $st->execute([$id]);
            mapi_out(true, '', $st->fetchAll(PDO::FETCH_ASSOC));
            break;
        }

        // ===== 扩展：照片流（<image> 不能带 header，token 走 query） =====
        case 'naming_thumb': {
            // Stream naming catalog image (local file or remote URL) for WeChat <image>
            mapi_auth($input); mx_ensure();
            $id = (int)($input['id'] ?? 0);
            $modelNo = trim((string)($input['model_no'] ?? ''));
            $row = null;
            try {
                if ($id > 0) {
                    $st = db()->prepare('SELECT id, model_no, web_image_url, source_image_url, web_size_image_url, image_path FROM naming_models WHERE id=? LIMIT 1');
                    $st->execute([$id]);
                    $row = $st->fetch(PDO::FETCH_ASSOC) ?: null;
                } elseif ($modelNo !== '') {
                    $st = db()->prepare('SELECT id, model_no, web_image_url, source_image_url, web_size_image_url, image_path FROM naming_models WHERE model_no=? ORDER BY id DESC LIMIT 1');
                    $st->execute([$modelNo]);
                    $row = $st->fetch(PDO::FETCH_ASSOC) ?: null;
                }
            } catch (Throwable $e) { $row = null; }
            if (!$row) { http_response_code(404); exit; }
            $src = '';
            foreach (['image_path', 'web_image_url', 'source_image_url'] as $k) {
                $v = trim((string)($row[$k] ?? ''));
                if ($v !== '') { $src = $v; break; }
            }
            if ($src === '') { http_response_code(404); exit; }
            $abs = mx_naming_thumb_abs($src);
            // Relative / local uploads: read from disk first (avoid self-HTTP roundtrip).
            if (!preg_match('#^https?://#i', $src) && strpos($src, '//') !== 0) {
                $rel = ltrim(str_replace('\\', '/', $src), '/');
                $cands = [
                    __DIR__ . '/../../artdon_erp/' . $rel,
                    __DIR__ . '/../../' . $rel,
                    '/www/wwwroot/Artdon/artdon_erp/' . $rel,
                ];
                foreach ($cands as $c) {
                    if (is_file($c)) { mx_stream_naming_local_file($c); }
                }
            }
            $remote = $abs !== '' ? $abs : $src;
            if (!preg_match('#^https?://#i', $remote)) { http_response_code(404); exit; }
            $ch = curl_init($remote);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_TIMEOUT => 12,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_USERAGENT => 'ArtdonMiniappThumb/1.0',
            ]);
            $bin = curl_exec($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $ctype = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
            curl_close($ch);
            if ($bin === false || $code >= 400 || $bin === '') { http_response_code(404); exit; }
            mx_stream_naming_image_bytes($bin, $ctype);
        }
        case 'photo': {
            mapi_auth($input); mx_ensure();
            $fid = (int)($input['id'] ?? 0);
            $f = crm_visit_file_row($fid);
            mx_stream_visit_photo($f);
        }
        case 'customer_business_card': {
            // 客户名片原图（crm_customer_files.category=business_card）
            mapi_auth($input); mx_ensure();
            $fid = (int)($input['id'] ?? 0);
            if ($fid <= 0) { http_response_code(404); exit; }
            $st = db()->prepare("SELECT * FROM crm_customer_files WHERE id=? AND category='business_card' AND deleted_at IS NULL LIMIT 1");
            $st->execute([$fid]);
            $f = $st->fetch(PDO::FETCH_ASSOC);
            if (!$f) { http_response_code(404); exit; }
            mx_stream_customer_file($f);
        }
        case 'photo_meta_save': {
            mapi_auth($input); mx_ensure();
            $f = crm_visit_file_row((int)($input['file_id'] ?? 0));
            db()->prepare("INSERT INTO miniapp_photo_meta (file_id, visit_id, model, note) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE model=VALUES(model), note=VALUES(note)")
                ->execute([(int)$f['id'], (int)$f['visit_id'], mb_substr(trim((string)($input['model'] ?? '')), 0, 120), mb_substr(trim((string)($input['note'] ?? '')), 0, 500)]);
            mapi_out(true, '已保存');
        }
        case 'photo_delete': {
            mapi_auth($input); mx_ensure();
            $fid = (int)($input['file_id'] ?? 0);
            $f = crm_visit_file_row($fid);
            $vidPhoto = (int)($f['visit_id'] ?? 0);
            db()->prepare('UPDATE crm_visit_files SET deleted_at = NOW() WHERE id = ?')->execute([$fid]);
            db()->prepare('DELETE FROM miniapp_photo_meta WHERE file_id=?')->execute([$fid]);
            mx_audit_log('photo_delete', '删除照片', 'visit', $vidPhoto, mx_audit_visit_name($vidPhoto), 'file#' . $fid);
            mapi_out(true, '已删除');
        }
        case 'visit_samples_save': {
            $me = mapi_auth($input); mx_ensure();
            $vid = (int)($input['visit_id'] ?? 0);
            crm_visit_row($vid);
            $list = is_array($input['matters'] ?? null) ? $input['matters'] : (is_array($input['samples'] ?? null) ? $input['samples'] : []);
            $matterTypes = mx_matter_types();
            db()->beginTransaction();
            // 保留已派工关联：按 type|note|sort 回填
            $prevMap = [];
            $pst = db()->prepare("SELECT model, note, sort, related_dispatch_id FROM miniapp_visit_samples WHERE visit_id=?");
            $pst->execute([$vid]);
            foreach ($pst->fetchAll(PDO::FETCH_ASSOC) as $pr) {
                $did = (int)($pr['related_dispatch_id'] ?? 0);
                if ($did <= 0) continue;
                $key = trim((string)$pr['model']) . '|' . trim((string)$pr['note']) . '|' . (int)$pr['sort'];
                $prevMap[$key] = $did;
                $prevMap[trim((string)$pr['model']) . '|' . trim((string)$pr['note'])] = $did;
            }
            db()->prepare("DELETE FROM miniapp_visit_samples WHERE visit_id=?")->execute([$vid]);
            $ins = db()->prepare("INSERT INTO miniapp_visit_samples (visit_id, model, name, qty, note, photo_id, related_dispatch_id, sort, created_by) VALUES (?,?,?,?,?,?,?,?,?)");
            foreach (array_values($list) as $i => $s) {
                $type = trim((string)($s['type'] ?? ''));
                $modelIn = mb_substr(trim((string)($s['model'] ?? '')), 0, 120);
                if ($type === '' && isset($matterTypes[$modelIn])) $type = $modelIn;
                if ($type !== '' && isset($matterTypes[$type])) {
                    $model = $type;
                    $name = $matterTypes[$type];
                    $note = mb_substr(trim((string)($s['note'] ?? '')), 0, 500);
                } else {
                    // legacy: product model required
                    if ($modelIn === '') continue;
                    $model = $modelIn;
                    $name = mb_substr(trim((string)($s['name'] ?? '')), 0, 190);
                    $note = mb_substr(trim((string)($s['note'] ?? '')), 0, 500);
                }
                $keepId = (int)($s['related_dispatch_id'] ?? 0);
                if ($keepId <= 0) {
                    $keepId = (int)($prevMap[$model . '|' . $note . '|' . $i] ?? $prevMap[$model . '|' . $note] ?? 0);
                }
                $ins->execute([$vid, $model, $name, max(0, (int)($s['qty'] ?? 0)), $note, ((int)($s['photo_id'] ?? 0)) ?: null, $keepId ?: null, $i, (int)$me['user_id']]);
            }
            db()->commit();
            $extra = mx_visit_extra(crm_visit_row($vid));
            mx_audit_log('matters_save', '保存事项/产品', 'visit', $vid, mx_audit_visit_name($vid), count($list) . '项');
            mapi_out(true, '事项已保存', ['samples' => $extra['samples'], 'matters' => $extra['matters']]);
        }
        case 'todo_save': {
            $me = mapi_auth($input); mx_ensure();
            $id = (int)($input['id'] ?? 0);
            $todoIsEdit = $id > 0;
            $content = mb_substr(trim((string)($input['content'] ?? '')), 0, 500);
            if ($content === '') mapi_out(false, '请填写待办内容');
            $type = mx_matter_normalize_type($input['type'] ?? 'other');
            $owner = (int)($input['owner_user_id'] ?? 0) ?: (int)$me['user_id'];
            $due = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($input['due_date'] ?? '')) ? $input['due_date'] : null;
            $dueT = mx_time_in($input['due_time'] ?? '');
            $extra = is_array($input['extra'] ?? null) ? json_encode($input['extra'], JSON_UNESCAPED_UNICODE) : null;
            if ($id > 0) {
                mx_todo_row($id);
                db()->prepare("UPDATE miniapp_visit_todos SET type=?, content=?, owner_user_id=?, due_date=?, due_time=?, extra_json=? WHERE id=?")
                    ->execute([$type, $content, $owner, $due, $dueT, $extra, $id]);
            } else {
                $vid = (int)($input['visit_id'] ?? 0);
                $v = crm_visit_row($vid);
                db()->prepare("INSERT INTO miniapp_visit_todos (visit_id, customer_id, type, content, owner_user_id, due_date, due_time, extra_json, created_by) VALUES (?,?,?,?,?,?,?,?,?)")
                    ->execute([$vid, (int)$v['customer_id'], $type, $content, $owner, $due, $dueT, $extra, (int)$me['user_id']]);
                $id = (int)db()->lastInsertId();
            }
            $st = db()->prepare("SELECT * FROM miniapp_visit_todos WHERE id=?"); $st->execute([$id]);
            $todoRow = $st->fetch(PDO::FETCH_ASSOC);
            $vidTodo = (int)($todoRow['visit_id'] ?? ($input['visit_id'] ?? 0));
            mx_audit_log($todoIsEdit ? 'todo_edit' : 'todo_add', $todoIsEdit ? '编辑待办' : '添加待办', 'todo', $id, mb_substr($content, 0, 80), mx_audit_visit_name($vidTodo));
            mapi_out(true, '待办已保存', mx_todo_public($todoRow));
        }
        case 'todo_toggle': {
            mapi_auth($input); mx_ensure();
            $t = mx_todo_row((int)($input['id'] ?? 0));
            $new = isset($input['done']) ? (!empty($input['done']) ? 'done' : 'open') : ($t['status'] === 'done' ? 'open' : 'done');
            db()->prepare("UPDATE miniapp_visit_todos SET status=?, done_at=" . ($new === 'done' ? 'NOW()' : 'NULL') . " WHERE id=?")->execute([$new, (int)$t['id']]);
            if ($new === 'done') mx_audit_log('todo_complete', '完成待办', 'todo', (int)$t['id'], mb_substr((string)($t['content'] ?? ''), 0, 80));
            mapi_out(true, $new === 'done' ? '已完成' : '已恢复', ['id' => (int)$t['id'], 'status' => $new]);
        }
        case 'todo_delete': {
            mapi_auth($input); mx_ensure();
            $t = mx_todo_row((int)($input['id'] ?? 0));
            db()->prepare("DELETE FROM miniapp_visit_todos WHERE id=?")->execute([(int)$t['id']]);
            mx_audit_log('todo_delete', '删除待办', 'todo', (int)$t['id'], mb_substr((string)($t['content'] ?? ''), 0, 80));
            mapi_out(true, '已删除');
        }
        case 'todos': {
            mapi_auth($input); mx_ensure();
            mapi_out(true, '', ['rows' => mx_todo_query([
                'mine' => !empty($input['mine']), 'type' => (string)($input['type'] ?? ''), 'overdue' => !empty($input['overdue']),
                'range' => (string)($input['range'] ?? ''), 'status' => (string)($input['status'] ?? ''),
                'visit_id' => (int)($input['visit_id'] ?? 0), 'customer_id' => (int)($input['customer_id'] ?? 0),
                'keyword' => trim((string)($input['keyword'] ?? '')),
            ])]);
        }
        case 'today': {
            mapi_auth($input); mx_ensure();
            $list = crm_visit_list(['range' => 'today', 'page_size' => 50]);
            $visits = [];
            foreach ($list['rows'] as $r) {
                $st = db()->prepare("SELECT start_time, end_time FROM miniapp_visit_meta WHERE visit_id=?"); $st->execute([(int)$r['id']]);
                $m = $st->fetch(PDO::FETCH_ASSOC) ?: [];
                $s = mx_hm($m['start_time'] ?? $r['visit_time'] ?? ''); $e = mx_hm($m['end_time'] ?? '');
                $visits[] = ['id' => (int)$r['id'], 'visit_type' => $r['visit_type'], 'type_label' => $r['visit_type'] === 'customer_arrival' ? '来访' : '拜访',
                    'customer_name' => $r['customer_name'] ?? '', 'customer_id' => (int)$r['customer_id'], 'location' => $r['location'] ?? '',
                    'owner_name' => $r['owner_name'] ?? '', 'status' => $r['status'], 'start_time' => $s, 'end_time' => $e,
                    'time_range' => $s . ($e ? '–' . $e : ''), 'duration' => mx_duration($s, $e)];
            }
            usort($visits, fn($a, $b) => strcmp($a['start_time'], $b['start_time']));
            $todos = mx_todo_query(['range' => 'today']);
            $stats = ['visit' => 0, 'arrival' => 0, 'due' => 0, 'overdue' => 0];
            foreach ($visits as $v) $stats[$v['visit_type'] === 'customer_arrival' ? 'arrival' : 'visit']++;
            foreach ($todos as $t) { if ($t['status'] === 'open') { $stats['due']++; if ($t['is_overdue']) $stats['overdue']++; } }
            mapi_out(true, '', ['date' => date('Y-m-d'), 'visits' => $visits, 'todos' => $todos, 'stats' => $stats]);
        }
        case 'naming_series': {
            mapi_auth($input);
            try {
                $kw = trim((string)($input['keyword'] ?? $input['kw'] ?? $input['q'] ?? ''));
                $limit = (int)($input['limit'] ?? $input['page_size'] ?? 80);
                if ($limit < 1) $limit = 80;
                if ($limit > 200) $limit = 200;
                // filter + limit in SQL — avoid pulling entire naming catalog
                $sql = "SELECT DISTINCT TRIM(COALESCE(NULLIF(web_series,''),NULLIF(series_name,''),NULLIF(product_name,''))) AS series
                    FROM naming_models
                    WHERE COALESCE(website_deleted,0)=0
                    HAVING series<>''";
                $args = [];
                if ($kw !== '') {
                    $sql .= " AND series LIKE ?";
                    $args[] = '%' . $kw . '%';
                }
                $sql .= " ORDER BY series ASC LIMIT " . (int)$limit;
                $st = db()->prepare($sql);
                $st->execute($args);
                $rows = $st->fetchAll(PDO::FETCH_COLUMN) ?: [];
                mapi_out(true, '', ['rows' => array_values($rows), 'total' => count($rows)]);
            } catch (Throwable $e) {
                mapi_out(false, $e->getMessage() ?: '命名系列读取失败');
            }
            break;
        }
        case 'naming_models': {
            mapi_auth($input);
            try {
                $series = trim((string)($input['series'] ?? ''));
                $kw = trim((string)($input['keyword'] ?? $input['kw'] ?? $input['q'] ?? ''));
                $page = max(1, (int)($input['page'] ?? 1));
                $per = (int)($input['page_size'] ?? $input['per_page'] ?? 50);
                if ($per < 1) $per = 50; if ($per > 100) $per = 100;
                $where = ['COALESCE(website_deleted,0)=0'];
                $args = [];
                if ($series !== '') {
                    // website_display_name is PHP-computed only, not a DB column
                    $where[] = "(web_series = ? OR series_name = ? OR product_name = ? OR web_series LIKE ? OR series_name LIKE ? OR product_name LIKE ?)";
                    $like = '%' . $series . '%';
                    array_push($args, $series, $series, $series, $like, $like, $like);
                }
                if ($kw !== '') {
                    $where[] = "(model_no LIKE ? OR web_series LIKE ? OR series_name LIKE ? OR product_name LIKE ? OR lamp_type LIKE ? OR item_name LIKE ?)";
                    $like = '%' . $kw . '%';
                    array_push($args, $like, $like, $like, $like, $like, $like);
                }
                $sqlWhere = ' WHERE ' . implode(' AND ', $where);
                $cntSt = db()->prepare('SELECT COUNT(*) FROM naming_models' . $sqlWhere);
                $cntSt->execute($args);
                $total = (int)$cntSt->fetchColumn();
                $off = ($page - 1) * $per;
                $st = db()->prepare('SELECT id, model_no, web_series, series_name, product_name, lamp_type, item_name, category, status, web_dimensions, dim_opening, dim_outer_d, web_image_url, source_image_url, web_size_image_url, image_path FROM naming_models' . $sqlWhere . ' ORDER BY model_no ASC, id DESC LIMIT ' . (int)$per . ' OFFSET ' . (int)$off);
                $st->execute($args);
                $rows = array_map('mx_naming_model_public', $st->fetchAll(PDO::FETCH_ASSOC) ?: []);
                mapi_out(true, '', ['rows' => $rows, 'total' => $total, 'page' => $page, 'page_size' => $per, 'series' => $series]);
            } catch (Throwable $e) {
                mapi_out(false, $e->getMessage() ?: '命名型号读取失败');
            }
            break;
        }
        case 'todo_create_dispatch': {
            mapi_auth($input); mx_ensure();
            $todoId = (int)($input['todo_id'] ?? $input['id'] ?? 0);
            if ($todoId <= 0) mapi_out(false, '参数错误');
            try {
                $t = mx_todo_row($todoId);
                $due = trim((string)($input['due_at'] ?? ''));
                if ($due === '' && !empty($t['due_date'])) {
                    $due = $t['due_date'] . (trim((string)($t['due_time'] ?? '')) !== '' ? ' ' . substr((string)$t['due_time'], 0, 5) : ' 18:00');
                }
                $assignee = (int)($input['assigned_to'] ?? $input['owner_user_id'] ?? 0);
                if ($assignee <= 0) $assignee = (int)($t['owner_user_id'] ?? 0);
                $r = mx_create_item_dispatch((int)$t['visit_id'], [
                    'linked_table' => 'miniapp_visit_todos',
                    'linked_id' => $todoId,
                    'matter_type' => $t['type'] ?? 'other',
                    'title_suffix' => mb_substr(trim((string)($t['content'] ?? '')), 0, 40),
                    'content' => (string)($t['content'] ?? ''),
                    'description' => '',
                    'assigned_to' => $assignee,
                    'due_at' => $due,
                ]);
                mx_audit_log('dispatch_todo', '待办派工', 'todo', $todoId, mb_substr((string)($t['content'] ?? ''), 0, 80), (string)($r['task_no'] ?? ''));
                mapi_out(true, (string)($r['message'] ?? '派工已生成'), $r);
            } catch (Throwable $e) {
                mapi_out(false, $e->getMessage() ?: '创建派工失败');
            }
            break;
        }
        case 'matter_create_dispatch': {
            mapi_auth($input); mx_ensure();
            $matterId = (int)($input['matter_id'] ?? $input['sample_id'] ?? $input['id'] ?? 0);
            $vid = (int)($input['visit_id'] ?? 0);
            if ($matterId <= 0) mapi_out(false, '参数错误');
            try {
                $st = db()->prepare('SELECT * FROM miniapp_visit_samples WHERE id=? LIMIT 1');
                $st->execute([$matterId]);
                $m = $st->fetch(PDO::FETCH_ASSOC);
                if (!$m) mapi_out(false, '事项不存在');
                if ($vid > 0 && (int)$m['visit_id'] !== $vid) mapi_out(false, '事项与拜访不匹配');
                $vid = (int)$m['visit_id'];
                crm_visit_row($vid); // 鉴权
                $matterTypes = mx_matter_types();
                $model = (string)($m['model'] ?? '');
                $type = isset($matterTypes[$model]) ? $model : 'sample';
                $label = $matterTypes[$type] ?? '寄样品';
                $note = trim((string)($m['note'] ?? ''));
                $suffix = $note !== '' ? mb_substr($note, 0, 40) : ($m['product_model'] ?? $model);
                $assignee = (int)($input['assigned_to'] ?? $input['owner_user_id'] ?? 0);
                $due = trim((string)($input['due_at'] ?? ''));
                $r = mx_create_item_dispatch($vid, [
                    'linked_table' => 'miniapp_visit_samples',
                    'linked_id' => $matterId,
                    'matter_type' => $type,
                    'title_suffix' => (string)$suffix,
                    'content' => $label . ($note !== '' ? '：' . $note : ''),
                    'assigned_to' => $assignee,
                    'due_at' => $due,
                ]);
                mx_audit_log('dispatch_item', '事项派工', 'visit', $vid, mx_audit_visit_name($vid), $label . ' ' . (string)($r['task_no'] ?? ''));
                mapi_out(true, (string)($r['message'] ?? '派工已生成'), $r);
            } catch (Throwable $e) {
                mapi_out(false, $e->getMessage() ?: '创建派工失败');
            }
            break;
        }
        case 'visit_create_dispatch': {
            mapi_auth($input);
            $vid = (int)($input['visit_id'] ?? $input['id'] ?? 0);
            if ($vid <= 0) mapi_out(false, '参数错误');
            $kind = trim((string)($input['kind'] ?? 'visit_result'));
            if (!in_array($kind, ['visit_prepare', 'arrival_reception', 'visit_result'], true)) $kind = 'visit_result';
            try {
                $r = crm_visit_dispatch_placeholder($vid, $kind);
                mx_audit_log('dispatch_visit', '整单派工', 'visit', $vid, mx_audit_visit_name($vid), (string)($r['task_no'] ?? $kind));
                mapi_out(true, (string)($r['message'] ?? '派工已生成'), [
                    'dispatch_id' => (int)($r['dispatch_id'] ?? 0),
                    'task_no' => (string)($r['task_no'] ?? ''),
                    'existing' => !empty($r['existing']),
                    'related_dispatch_id' => (int)(($r['record']['related_dispatch_id'] ?? $r['dispatch_id'] ?? 0)),
                    'need_dispatch' => 1,
                ]);
            } catch (Throwable $e) {
                mapi_out(false, $e->getMessage() ?: '创建派工失败');
            }
            break;
        }
        case 'visit_delete': {
            mapi_auth($input); mx_ensure();
            $id = (int)($input['id'] ?? 0);
            $vname = mx_audit_visit_name($id);
            crm_visit_delete($id);
            mx_purge_visit_children($id);
            mx_audit_log('visit_delete', '删除拜访', 'visit', $id, $vname);
            mapi_out(true, '已删除');
        }
        case 'customer_delete': {
            mapi_auth($input);
            $id = (int)($input['id'] ?? $input['customer_id'] ?? 0);
            if ($id <= 0) mapi_out(false, '客户 ID 不正确');
            if (!function_exists('crm_customer_delete')) {
                require_once __DIR__ . '/../../artdon_erp/crm_customer.php';
            }
            $reason = trim((string)($input['delete_reason'] ?? $input['reason'] ?? '小程序删除'));
            $cname = mx_audit_customer_name($id);
            crm_customer_delete($id, $reason);
            mx_audit_log('customer_delete', '删除客户', 'customer', $id, $cname, $reason);
            mapi_out(true, '客户已删除', ['deleted_id' => $id]);
            break;
        }
        case 'customer_options': {
            mapi_auth($input);
            if (!function_exists('crm_dictionary_items')) {
                @require_once __DIR__ . '/../../artdon_erp/crm_settings_config.php';
            }
            $pick = function (string $dictKey) {
                try {
                    if (!function_exists('crm_dictionary_items')) return [];
                    $rows = crm_dictionary_items($dictKey);
                    $out = [];
                    foreach ((array)$rows as $r) {
                        if ((int)($r['is_enabled'] ?? 1) !== 1) continue;
                        $key = trim((string)($r['item_key'] ?? $r['key'] ?? ''));
                        if ($key === '') continue;
                        // 字典真实字段是 name_cn / name_en，兼容 item_label
                        $label = trim((string)($r['name_cn'] ?? $r['item_label'] ?? $r['label'] ?? ''));
                        if ($label === '') $label = trim((string)($r['short_name'] ?? $r['name_en'] ?? $key));
                        $out[] = [
                            'key' => $key,
                            'label' => $label ?: $key,
                            'name_en' => trim((string)($r['name_en'] ?? '')),
                            'short_name' => trim((string)($r['short_name'] ?? '')),
                        ];
                    }
                    return $out;
                } catch (Throwable $e) {
                    return [];
                }
            };
            $countries = $pick('country_region');
            // 补 ISO：与网页 countryKeyFromValue / regionItemsForCountry 一致
            $countryOpts = [];
            try {
                $countryRows = function_exists('crm_dictionary_items') ? crm_dictionary_items('country_region') : [];
                foreach ((array)$countryRows as $r) {
                    if ((int)($r['is_enabled'] ?? 1) !== 1) continue;
                    $key = trim((string)($r['item_key'] ?? ''));
                    if ($key === '') continue;
                    $extra = $r['extra_config'] ?? [];
                    if (is_string($extra)) $extra = json_decode($extra, true) ?: [];
                    $iso = strtoupper(trim((string)($extra['iso'] ?? $extra['country'] ?? $key)));
                    $label = trim((string)($r['name_cn'] ?? $r['item_label'] ?? ''));
                    if ($label === '') $label = trim((string)($r['short_name'] ?? $r['name_en'] ?? $key));
                    $aliases = $extra['aliases'] ?? [];
                    if (!is_array($aliases)) $aliases = [];
                    $countryOpts[] = [
                        'key' => $key,
                        'label' => $label ?: $key,
                        'name_cn' => $label ?: $key,
                        'name_en' => trim((string)($r['name_en'] ?? '')),
                        'short_name' => trim((string)($r['short_name'] ?? '')),
                        'iso' => $iso ?: strtoupper($key),
                        'aliases' => array_values(array_filter(array_map('strval', $aliases))),
                    ];
                }
            } catch (Throwable $e) {
                $countryOpts = array_map(static function ($c) {
                    return [
                        'key' => $c['key'],
                        'label' => $c['label'],
                        'name_cn' => $c['label'],
                        'name_en' => $c['name_en'] ?? '',
                        'short_name' => $c['short_name'] ?? '',
                        'iso' => strtoupper($c['key']),
                        'aliases' => [],
                    ];
                }, $countries);
            }

            // 城市默认懒加载（customer_cities），避免打开新建页一次拉全量城市拖慢/卡住
            $includeCities = ((string)($input['include_cities'] ?? '') === '1');
            $cityOpts = [];
            if ($includeCities) {
            try {
                $cityRows = function_exists('crm_dictionary_items') ? crm_dictionary_items('city_region') : [];
                foreach ((array)$cityRows as $r) {
                    if ((int)($r['is_enabled'] ?? 1) !== 1) continue;
                    $key = trim((string)($r['item_key'] ?? ''));
                    if ($key === '') continue;
                    $extra = $r['extra_config'] ?? [];
                    if (is_string($extra)) $extra = json_decode($extra, true) ?: [];
                    $label = trim((string)($r['name_cn'] ?? ''));
                    if ($label === '') $label = trim((string)($r['name_en'] ?? $r['short_name'] ?? $key));
                    $aliases = $extra['aliases'] ?? [];
                    if (!is_array($aliases)) $aliases = [];
                    $cityOpts[] = [
                        'key' => $key,
                        'label' => $label ?: $key,
                        'name_cn' => $label ?: $key,
                        'name_en' => trim((string)($r['name_en'] ?? '')),
                        'short_name' => trim((string)($r['short_name'] ?? '')),
                        'country' => strtoupper(trim((string)($extra['country'] ?? ''))),
                        'type' => trim((string)($extra['type'] ?? '')),
                        'aliases' => array_values(array_filter(array_map('strval', $aliases))),
                    ];
                }
            } catch (Throwable $e) {
                $cityOpts = [];
            }
            } // end if includeCities

            mapi_out(true, '', [
                'status' => $pick('customer_status') ?: [
                    ['key' => 'active', 'label' => 'active'],
                    ['key' => 'lead', 'label' => 'lead'],
                ],
                'level' => $pick('customer_level') ?: [
                    ['key' => 'P0', 'label' => 'P0'], ['key' => 'P1', 'label' => 'P1'],
                    ['key' => 'P2', 'label' => 'P2'], ['key' => 'P3', 'label' => 'P3'],
                    ['key' => 'P4', 'label' => 'P4'], ['key' => 'P5', 'label' => 'P5'],
                    ['key' => 'Blacklist', 'label' => 'Blacklist'],
                ],
                'lifecycle' => $pick('customer_lifecycle') ?: [
                    ['key' => 'lead', 'label' => '线索 lead'],
                    ['key' => 'official', 'label' => '正式 official'],
                    ['key' => 'quoting', 'label' => '报价中 quoting'],
                    ['key' => 'deal', 'label' => '成交 deal'],
                ],
                'source' => $pick('customer_source') ?: [
                    ['key' => 'manual_sales', 'label' => '业务手工'],
                    ['key' => 'website', 'label' => '官网 website'],
                    ['key' => 'exhibition', 'label' => '展会'],
                    ['key' => 'referral', 'label' => '转介绍'],
                    ['key' => 'linkedin', 'label' => 'LinkedIn'],
                    ['key' => 'email', 'label' => '邮件'],
                    ['key' => 'whatsapp', 'label' => 'WhatsApp'],
                    ['key' => 'other', 'label' => '其他'],
                ],
                'promotion_channels' => $pick('promotion_channel') ?: [
                    ['key' => 'email', 'label' => '邮件'],
                    ['key' => 'whatsapp', 'label' => 'WhatsApp个人'],
                    ['key' => 'wechat', 'label' => '微信个人'],
                    ['key' => 'phone', 'label' => '电话'],
                    ['key' => 'linkedin', 'label' => 'LinkedIn'],
                    ['key' => 'offline', 'label' => '展会/线下'],
                ],
                'countries' => $countryOpts,
                'cities' => $cityOpts,
            ]);
        }
        case 'customer_cities': {
            mapi_auth($input);
            if (!function_exists('crm_dictionary_items')) {
                @require_once __DIR__ . '/../../artdon_erp/crm_settings_config.php';
            }
            $iso = strtoupper(trim((string)($input['country'] ?? $input['iso'] ?? '')));
            $cityOpts = [];
            try {
                $cityRows = function_exists('crm_dictionary_items') ? crm_dictionary_items('city_region') : [];
                foreach ((array)$cityRows as $r) {
                    if ((int)($r['is_enabled'] ?? 1) !== 1) continue;
                    $key = trim((string)($r['item_key'] ?? ''));
                    if ($key === '') continue;
                    $extra = $r['extra_config'] ?? [];
                    if (is_string($extra)) $extra = json_decode($extra, true) ?: [];
                    $cIso = strtoupper(trim((string)($extra['country'] ?? '')));
                    if ($iso !== '' && $cIso !== $iso) continue;
                    $label = trim((string)($r['name_cn'] ?? ''));
                    if ($label === '') $label = trim((string)($r['name_en'] ?? $r['short_name'] ?? $key));
                    $aliases = $extra['aliases'] ?? [];
                    if (!is_array($aliases)) $aliases = [];
                    $cityOpts[] = [
                        'key' => $key,
                        'label' => $label ?: $key,
                        'name_cn' => $label ?: $key,
                        'name_en' => trim((string)($r['name_en'] ?? '')),
                        'short_name' => trim((string)($r['short_name'] ?? '')),
                        'country' => $cIso,
                        'type' => trim((string)($extra['type'] ?? '')),
                        'aliases' => array_values(array_filter(array_map('strval', $aliases))),
                    ];
                }
            } catch (Throwable $e) {
                $cityOpts = [];
            }
            mapi_out(true, '', ['cities' => $cityOpts, 'country' => $iso]);
        }
        case 'customer_duplicate_check': {
            mapi_auth($input); mx_ensure();
            $name = trim((string)($input['customer_name'] ?? ''));
            if ($name === '' && trim((string)($input['email'] ?? '')) === '' && trim((string)($input['phone'] ?? '')) === '') {
                mapi_out(true, '', ['matches' => [], 'exact_name' => 0, 'high_risk' => 0, 'need_confirm' => 0, 'contact_warnings' => []]);
            }
            mapi_out(true, '', mapi_dup_check_payload($input));
        }
        case 'customer_create': {
            mapi_auth($input); mx_ensure();
            if (!function_exists('crm_customer_create_confirmed')) {
                require_once __DIR__ . '/../../artdon_erp/crm_customer.php';
            }
            if (!function_exists('crm_import_dictionary_values')) {
                @require_once __DIR__ . '/../../artdon_erp/crm_customer.php';
            }
            if (!function_exists('crm_dictionary_items')) {
                @require_once __DIR__ . '/../../artdon_erp/crm_settings_config.php';
            }
            $name = trim((string)($input['customer_name'] ?? ''));
            $country = trim((string)($input['country'] ?? ''));
            if ($name === '') mapi_out(false, '请填写客户名称');
            if ($country === '') mapi_out(false, '请填写国家');
            $email = trim((string)($input['email'] ?? ''));
            if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) mapi_out(false, '邮箱格式不正确');
            $contactEmail = trim((string)($input['contact_email'] ?? ''));
            if ($contactEmail !== '' && !filter_var($contactEmail, FILTER_VALIDATE_EMAIL)) mapi_out(false, '联系人邮箱格式不正确');

            // 推广偏好：支持数组 / 逗号分隔 / 中文标签，写入与官网同一套 promotion_channel
            $normalizePromo = function ($raw, string $other = '') {
                $parts = [];
                if (is_array($raw)) {
                    foreach ($raw as $item) {
                        if (is_array($item)) $item = $item['key'] ?? $item['item_key'] ?? '';
                        $item = trim((string)$item);
                        if ($item !== '') $parts[] = $item;
                    }
                } else {
                    $parts = preg_split('/[,，;；、\s]+/u', trim((string)$raw), -1, PREG_SPLIT_NO_EMPTY) ?: [];
                }
                if ($other !== '') {
                    foreach (preg_split('/[,，;；、]+/u', $other, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $o) {
                        $o = trim((string)$o);
                        if ($o !== '') $parts[] = $o;
                    }
                }
                if (!$parts) return [];
                // 官网短标签 → 字典 key
                $alias = [
                    '邮件' => 'email', '邮箱' => 'email', 'mail' => 'email', 'edm' => 'edm',
                    'whatsapp' => 'whatsapp', 'wa' => 'whatsapp', 'whatsapp个人' => 'whatsapp',
                    '微信' => 'wechat', '微信个人' => 'wechat', 'weixin' => 'wechat', 'wx' => 'wechat',
                    '电话' => 'phone', 'tel' => 'phone', 'phone' => 'phone',
                    'linkedin' => 'linkedin', '领英' => 'linkedin',
                    '展会/线下' => 'offline', '展会线下' => 'offline', '展会' => 'offline', '线下' => 'offline', 'offline' => 'offline',
                    '微信群' => 'wechat_group', 'whatsapp群' => 'whatsapp_group',
                    '不推广' => 'no_promotion', '仅维护' => 'maintenance_only',
                ];
                $mapped = [];
                foreach ($parts as $p) {
                    $low = mb_strtolower(trim((string)$p));
                    if (isset($alias[$p])) $mapped[] = $alias[$p];
                    elseif (isset($alias[$low])) $mapped[] = $alias[$low];
                    else $mapped[] = $p;
                }
                if (function_exists('crm_import_dictionary_values')) {
                    $mapped = crm_import_dictionary_values('promotion_channel', implode(',', $mapped));
                }
                return array_values(array_unique(array_filter(array_map('strval', (array)$mapped))));
            };

            $promoChannels = $normalizePromo(
                $input['promotion_channels'] ?? ($input['promotion_methods'] ?? []),
                trim((string)($input['promotion_other'] ?? ''))
            );
            // 客户表 ENUM 无 phone/offline：电话跳过，展会/线下 → visit
            $customerPromoMap = ['offline' => 'visit', 'google_ads' => 'ads', 'social' => 'ads'];
            $customerPromoAllowed = ['email','whatsapp','wechat','wechat_group','whatsapp_group','linkedin','edm','ads','visit','manual_sales','automation','no_promotion'];
            $customerChannels = [];
            foreach ($promoChannels as $ch) {
                $ch = $customerPromoMap[$ch] ?? $ch;
                if ($ch === 'phone') continue; // 客户级无此 ENUM
                if (in_array($ch, $customerPromoAllowed, true)) $customerChannels[] = $ch;
            }
            $customerChannels = array_values(array_unique($customerChannels));

            $contactPromo = $normalizePromo(
                $input['contact_promotion_channels'] ?? ($input['contact_promotion_methods'] ?? []),
                trim((string)($input['contact_promotion_other'] ?? ''))
            );
            // 联系人 ENUM 含 phone/offline；过滤非法值
            $contactPromoAllowed = ['email','whatsapp','wechat','phone','linkedin','offline','edm','google_ads','social','referral','agent_dev','visit','wechat_group','whatsapp_group','manual_sales','automation','no_promotion','maintenance_only'];
            $contactChannels = array_values(array_unique(array_filter($contactPromo, fn($c) => in_array($c, $contactPromoAllowed, true))));

            $source = trim((string)($input['source'] ?? $input['source_tags'] ?? 'manual_sales')) ?: 'manual_sales';
            $payload = [
                'customer_name' => $name,
                'customer_name_en' => trim((string)($input['customer_name_en'] ?? '')),
                'customer_code' => trim((string)($input['customer_code'] ?? '')),
                'country' => $country,
                'city' => trim((string)($input['city'] ?? '')),
                'address' => trim((string)($input['address'] ?? '')),
                'website' => trim((string)($input['website'] ?? '')),
                'email' => $email,
                'phone' => trim((string)($input['phone'] ?? '')),
                'whatsapp' => trim((string)($input['whatsapp'] ?? '')),
                'status' => trim((string)($input['status'] ?? 'active')) ?: 'active',
                'level' => trim((string)($input['level'] ?? 'P3')) ?: 'P3',
                'lifecycle_key' => trim((string)($input['lifecycle_key'] ?? 'lead')) ?: 'lead',
                'source' => $source,
                'source_tags' => $input['source_tags'] ?? $source,
                'remark' => trim((string)($input['remark'] ?? '')),
                'promotion_channels' => $customerChannels,
                'business_card_token' => trim((string)($input['business_card_token'] ?? '')),
            ];

            $contactName = trim((string)($input['contact_name'] ?? ''));
            $contactPhone = trim((string)($input['contact_phone'] ?? ''));
            $contactWhatsapp = trim((string)($input['contact_whatsapp'] ?? ''));
            $contactWechat = trim((string)($input['contact_wechat'] ?? ''));
            $contactPosition = trim((string)($input['contact_position'] ?? ''));
            if ($contactName !== '' || $contactEmail !== '' || $contactPhone !== '' || $contactWhatsapp !== '' || $contactWechat !== '' || $contactChannels) {
                $cname = $contactName !== '' ? $contactName : ($contactEmail !== '' ? explode('@', $contactEmail)[0] : ($contactWechat !== '' ? $contactWechat : '联系人'));
                $contactRow = [
                    'name' => $cname,
                    'position' => $contactPosition,
                    'email' => $contactEmail,
                    'phone' => $contactPhone !== '' ? $contactPhone : trim((string)($input['phone'] ?? '')),
                    'whatsapp' => $contactWhatsapp,
                    'wechat' => $contactWechat,
                    'is_primary' => 1,
                ];
                if ($contactChannels) {
                    $contactRow['promotion_channels'] = $contactChannels;
                    $contactRow['contact_promotion_channels'] = $contactChannels;
                }
                $payload['contacts'] = [$contactRow];
                $payload['contact_name'] = $cname;
                $payload['contact_email'] = $contactEmail;
                $payload['contact_phone'] = $contactRow['phone'];
                $payload['contact_whatsapp'] = $contactWhatsapp;
                $payload['contact_wechat'] = $contactWechat;
                $payload['contact_position'] = $contactPosition;
            }

            // 查重：未确认时不盲传 force；有同名/高风险/相似客户则返回确认列表
            $confirmed = !empty($input['duplicate_risk_confirmed']) && in_array($input['duplicate_risk_confirmed'], [1, '1', true, 'true'], true);
            $dupInfo = mapi_dup_check_payload(array_merge($payload, [
                'contact_name' => trim((string)($input['contact_name'] ?? '')),
                'email' => $email !== '' ? $email : trim((string)($input['contact_email'] ?? '')),
                'phone' => trim((string)($input['phone'] ?? ($input['contact_phone'] ?? ''))),
                'whatsapp' => trim((string)($input['whatsapp'] ?? ($input['contact_whatsapp'] ?? ''))),
                'website' => trim((string)($input['website'] ?? '')),
                'customer_name_en' => trim((string)($input['customer_name_en'] ?? '')),
            ]));
            $needConfirm = !empty($dupInfo['need_confirm']);
            if ($needConfirm && !$confirmed) {
                $msg = !empty($dupInfo['exact_name'])
                    ? '已存在同名客户，请确认后继续'
                    : (!empty($dupInfo['high_risk']) ? '发现高风险重复客户，请确认后继续' : '发现疑似重复客户，请确认后继续');
                mapi_out(false, $msg, array_merge($dupInfo, ['code' => 'DUPLICATE_RISK']));
            }
            if ($confirmed) {
                // 用户已确认：允许越过同名校验与高风险拦截（对齐官网「继续新建」）
                $payload['duplicate_risk_confirmed'] = 1;
                $payload['entry_mode'] = 'force';
            }

            $result = crm_customer_create_confirmed($payload);
            // 若未确认却被写入暂存池（规则兜底），也当作需确认返回
            if (!empty($result['lead']) && empty($result['customer'])) {
                $matches = mapi_dup_normalize_matches((array)($result['matches'] ?? $dupInfo['matches'] ?? []));
                mapi_out(false, '发现疑似重复客户，已建议确认后继续', [
                    'code' => 'DUPLICATE_RISK',
                    'matches' => $matches,
                    'exact_name' => (int)($dupInfo['exact_name'] ?? 0),
                    'high_risk' => 1,
                    'need_confirm' => 1,
                    'contact_warnings' => $dupInfo['contact_warnings'] ?? [],
                    'lead_id' => (int)(($result['lead']['id'] ?? 0)),
                ]);
            }
            $cust = $result['customer'] ?? $result;
            $newCid = (int)($cust['id'] ?? 0);
            mx_audit_log('customer_create', '新建客户', 'customer', $newCid, (string)($cust['customer_name'] ?? $name));
            mapi_out(true, '客户已创建', [
                'customer' => $cust,
                'id' => $newCid,
                'created_contact_count' => (int)($result['created_contact_count'] ?? 0),
                'promotion_channels' => $customerChannels,
                'contact_promotion_channels' => $contactChannels,
            ]);
        }

        case 'customer_business_card_ocr': {
            // 复用官网腾讯 BusinessCardOCR：同一 crm_customer_business_card_ocr
            mapi_auth($input);
            if (!function_exists('crm_customer_business_card_ocr')) {
                require_once __DIR__ . '/../../artdon_erp/crm_customer.php';
            }
            $file = [];
            if (!empty($_FILES['image'])) $file = $_FILES['image'];
            elseif (!empty($_FILES['file'])) $file = $_FILES['file'];
            if (!$file) mapi_out(false, '请选择需要识别的名片图片');
            // 小程序上传可能超过 5MB：先压到 OCR 上限内（保留可读清晰度）
            $tmp = (string)($file['tmp_name'] ?? '');
            $size = (int)($file['size'] ?? 0);
            if ($size <= 0 && $tmp !== '' && is_file($tmp)) $size = (int)@filesize($tmp);
            if ($size > 5 * 1024 * 1024) {
                if (!mx_compress_upload_under_1mb($file)) {
                    mapi_out(false, '名片图片须小于 5MB，请换一张清晰照片');
                }
            }
            $result = crm_customer_business_card_ocr($file);
            $ocrName = '';
            if (is_array($result)) {
                $ocrName = (string)($result['company'] ?? $result['customer_name'] ?? $result['name'] ?? '');
            }
            mx_audit_log('business_card_ocr', '名片识别', 'customer', 0, $ocrName);
            mapi_out(true, '名片识别完成，请确认后应用到客户表单', $result);
        }

        case 'customer_detail': {
            mapi_auth($input); mx_ensure();
            $cid = (int)($input['id'] ?? 0);
            if ($cid <= 0) mapi_out(false, '客户不存在');
            if (!function_exists('crm_dictionary_items')) {
                @require_once __DIR__ . '/../../artdon_erp/crm_settings_config.php';
            }
            if (!function_exists('crm_customer_country_display_name')) {
                @require_once __DIR__ . '/../../artdon_erp/crm_customer.php';
            }
            $st = db()->prepare("SELECT c.id, c.customer_name, c.customer_name_en, c.customer_code, c.country, c.city, c.address, c.website, c.email, c.phone, c.whatsapp, c.wechat, c.linkedin, c.source, c.level, c.status, c.lifecycle_key, c.remark, c.customer_tags, c.owner_user_id, c.created_at, c.updated_at, COALESCE(NULLIF(u.real_name,''), u.username, '') AS owner_name FROM crm_customers c LEFT JOIN crm_users u ON u.id = c.owner_user_id WHERE c.id=? AND c.deleted_at IS NULL");
            $st->execute([$cid]);
            $c = $st->fetch(PDO::FETCH_ASSOC);
            if (!$c) mapi_out(false, '客户不存在');

            // 国家展示名（网页用字典中文名）
            $countryRaw = trim((string)($c['country'] ?? ''));
            $c['country_raw'] = $countryRaw;
            if ($countryRaw !== '' && function_exists('crm_customer_country_display_name')) {
                try { $c['country_display'] = crm_customer_country_display_name($countryRaw); }
                catch (Throwable $e) { $c['country_display'] = $countryRaw; }
            } else {
                $c['country_display'] = $countryRaw;
            }
            if ($c['country_display'] !== '') $c['country'] = $c['country_display'];

            // 标签中文
            $promoLabel = [
                'email' => '邮件', 'whatsapp' => 'WhatsApp个人', 'wechat' => '微信个人',
                'wechat_group' => '微信群', 'whatsapp_group' => 'WhatsApp群',
                'phone' => '电话', 'linkedin' => 'LinkedIn', 'offline' => '展会/线下',
                'edm' => 'EDM', 'ads' => '广告', 'google_ads' => 'Google Ads', 'social' => '社媒',
                'visit' => '主动拜访', 'manual_sales' => '业务手工', 'automation' => '自动化',
                'no_promotion' => '不推广', 'maintenance_only' => '仅维护',
                'referral' => '老客户转介绍', 'agent_dev' => '代理商开发',
            ];
            $lifecycleLabel = [
                'lead' => '线索', 'official' => '正式', 'quoting' => '报价中', 'deal' => '成交',
            ];
            $sourceLabel = [
                'manual_sales' => '业务手工', 'website' => '官网', 'exhibition' => '展会',
                'referral' => '转介绍', 'linkedin' => 'LinkedIn', 'email' => '邮件',
                'whatsapp' => 'WhatsApp', 'other' => '其他',
            ];
            $statusLabel = [
                'active' => '活跃', 'lead' => '线索', 'inactive' => '停用', 'blacklist' => '黑名单',
            ];
            $lk = trim((string)($c['lifecycle_key'] ?? ''));
            $c['lifecycle_label'] = $lifecycleLabel[$lk] ?? ($lk !== '' ? $lk : '');
            $sk = trim((string)($c['source'] ?? ''));
            $c['source_label'] = $sourceLabel[$sk] ?? ($sk !== '' ? $sk : '');
            $stKey = trim((string)($c['status'] ?? ''));
            $c['status_label'] = $statusLabel[$stKey] ?? ($stKey !== '' ? $stKey : '');

            // 多负责人
            $owners = [];
            try {
                $ost = db()->prepare("SELECT o.user_id, o.is_primary, o.role_type, COALESCE(NULLIF(u.real_name,''), u.username, '') AS name FROM crm_customer_owners o LEFT JOIN crm_users u ON u.id=o.user_id WHERE o.customer_id=? ORDER BY o.is_primary DESC, o.id ASC");
                $ost->execute([$cid]);
                foreach ($ost->fetchAll(PDO::FETCH_ASSOC) as $ow) {
                    $owners[] = [
                        'user_id' => (int)($ow['user_id'] ?? 0),
                        'name' => (string)($ow['name'] ?? ''),
                        'is_primary' => (int)($ow['is_primary'] ?? 0),
                        'role_type' => (string)($ow['role_type'] ?? ''),
                    ];
                }
            } catch (Throwable $e) {}
            if (!$owners && !empty($c['owner_name'])) {
                $owners[] = ['user_id' => (int)($c['owner_user_id'] ?? 0), 'name' => (string)$c['owner_name'], 'is_primary' => 1, 'role_type' => 'primary'];
            }
            $primaryNames = [];
            $assistNames = [];
            foreach ($owners as $ow) {
                if (!$ow['name']) continue;
                if ((int)$ow['is_primary'] === 1 || ($ow['role_type'] ?? '') === 'primary') $primaryNames[] = $ow['name'];
                else $assistNames[] = $ow['name'];
            }
            $c['owner_summary'] = trim(
                ($primaryNames ? ('第一：' . implode('、', array_unique($primaryNames))) : '') .
                ($assistNames ? (('；协助：') . implode('、', array_unique($assistNames))) : '')
            );
            if ($c['owner_summary'] === '' && !empty($c['owner_name'])) $c['owner_summary'] = (string)$c['owner_name'];

            // 推广偏好
            $promoKeys = [];
            try {
                $pst = db()->prepare("SELECT channel_key FROM crm_customer_promotion_channels WHERE customer_id=? ORDER BY id");
                $pst->execute([$cid]);
                $promoKeys = array_values(array_filter(array_map('strval', $pst->fetchAll(PDO::FETCH_COLUMN))));
            } catch (Throwable $e) {}
            $promotionChannels = array_map(static function ($k) use ($promoLabel) {
                return ['key' => $k, 'label' => $promoLabel[$k] ?? $k];
            }, $promoKeys);
            $c['promotion_channels'] = $promoKeys;
            $c['promotion_labels'] = array_map(static fn($x) => $x['label'], $promotionChannels);

            // 来源标签
            $sourceTags = [];
            try {
                $sst = db()->prepare("SELECT source_key FROM crm_customer_source_tags WHERE customer_id=? ORDER BY id");
                $sst->execute([$cid]);
                foreach ($sst->fetchAll(PDO::FETCH_COLUMN) as $skv) {
                    $skv = (string)$skv;
                    $sourceTags[] = ['key' => $skv, 'label' => $sourceLabel[$skv] ?? $skv];
                }
            } catch (Throwable $e) {}

            // 地址
            $addresses = [];
            try {
                $ast = db()->prepare("SELECT id, address_type, country, city, address, postal_code, contact_name, phone, is_primary FROM crm_customer_addresses WHERE customer_id=? AND deleted_at IS NULL ORDER BY is_primary DESC, id ASC");
                $ast->execute([$cid]);
                foreach ($ast->fetchAll(PDO::FETCH_ASSOC) as $a) {
                    $aCountry = trim((string)($a['country'] ?? ''));
                    if ($aCountry !== '' && function_exists('crm_customer_country_display_name')) {
                        try { $a['country_display'] = crm_customer_country_display_name($aCountry); }
                        catch (Throwable $e) { $a['country_display'] = $aCountry; }
                    } else {
                        $a['country_display'] = $aCountry;
                    }
                    $a['is_primary'] = (int)($a['is_primary'] ?? 0);
                    $addresses[] = $a;
                }
            } catch (Throwable $e) {}

            // 联系人 + 联系人推广偏好
            $contacts = [];
            try {
                $cst = db()->prepare("SELECT id, name, name_en, position, department, email, phone, whatsapp, wechat, linkedin, is_primary, remark FROM crm_contacts WHERE customer_id=? AND deleted_at IS NULL ORDER BY is_primary DESC, id ASC");
                $cst->execute([$cid]);
                $contacts = $cst->fetchAll(PDO::FETCH_ASSOC) ?: [];
                $contactIds = array_map(static fn($r) => (int)$r['id'], $contacts);
                $contactPromoMap = [];
                if ($contactIds) {
                    try {
                        $in = implode(',', array_fill(0, count($contactIds), '?'));
                        $cpst = db()->prepare("SELECT contact_id, channel FROM crm_contact_promotions WHERE contact_id IN ($in) ORDER BY id");
                        $cpst->execute($contactIds);
                        foreach ($cpst->fetchAll(PDO::FETCH_ASSOC) as $row) {
                            $cid2 = (int)$row['contact_id'];
                            $ch = (string)$row['channel'];
                            if (!isset($contactPromoMap[$cid2])) $contactPromoMap[$cid2] = [];
                            $contactPromoMap[$cid2][] = ['key' => $ch, 'label' => $promoLabel[$ch] ?? $ch];
                        }
                    } catch (Throwable $e) {}
                }
                foreach ($contacts as &$ct) {
                    $ct['is_primary'] = (int)($ct['is_primary'] ?? 0);
                    $ct['promotion_channels'] = $contactPromoMap[(int)$ct['id']] ?? [];
                    $ct['promotion_labels'] = array_map(static fn($x) => $x['label'], $ct['promotion_channels']);
                }
                unset($ct);
            } catch (Throwable $e) {
                $contacts = [];
            }

            $vl = crm_visit_list(['customer_id' => $cid, 'page_size' => 20]);
            $visits = array_map(fn($r) => [
                'id' => (int)$r['id'],
                'type_label' => $r['visit_type'] === 'customer_arrival' ? '来访' : '拜访',
                'visit_date' => $r['visit_date'],
                'visit_time' => mx_hm($r['visit_time']),
                'title' => $r['title'],
            ], $vl['rows'] ?? []);

            // 名片原图（crm_customer_files.category=business_card）
            $businessCards = [];
            try {
                $bst = db()->prepare("SELECT id, file_name, file_type, file_size, created_at FROM crm_customer_files WHERE customer_id=? AND category='business_card' AND deleted_at IS NULL ORDER BY id DESC");
                $bst->execute([$cid]);
                foreach ($bst->fetchAll(PDO::FETCH_ASSOC) as $bf) {
                    $bfid = (int)($bf['id'] ?? 0);
                    if ($bfid <= 0) continue;
                    $businessCards[] = [
                        'id' => $bfid,
                        'file_name' => (string)($bf['file_name'] ?? ''),
                        'file_type' => (string)($bf['file_type'] ?? ''),
                        'file_size' => (int)($bf['file_size'] ?? 0),
                        'created_at' => (string)($bf['created_at'] ?? ''),
                        'url' => mx_business_card_url($bfid),
                    ];
                }
            } catch (Throwable $e) {
                $businessCards = [];
            }

                        mx_audit_log('view_customer', '查看客户详情', 'customer', $cid, (string)($c['customer_name'] ?? mx_audit_customer_name($cid)));
mapi_out(true, '', [
                'customer' => $c,
                'contacts' => $contacts,
                'addresses' => $addresses,
                'promotion_channels' => $promotionChannels,
                'source_tags' => $sourceTags,
                'owners' => $owners,
                'business_cards' => $businessCards,
                'visits' => $visits,
                'todos' => mx_todo_query(['customer_id' => $cid, 'status' => 'open']),
            ]);
        }
        case 'users': {
            mapi_auth($input);
            mapi_out(true, '', db()->query("SELECT id, real_name, username FROM crm_users WHERE status='active' ORDER BY id")->fetchAll(PDO::FETCH_ASSOC));
        }
        case 'demo_clear': {
            mapi_auth($input); mx_ensure();
            $vids = array_map('intval', db()->query("SELECT visit_id FROM miniapp_visit_meta WHERE is_demo=1")->fetchAll(PDO::FETCH_COLUMN));
            $cids = array_map('intval', db()->query("SELECT id FROM crm_customers WHERE customer_tags='miniapp_demo'")->fetchAll(PDO::FETCH_COLUMN));
            if ($cids) $vids = array_values(array_unique(array_merge($vids, array_map('intval', db()->query("SELECT id FROM crm_visit_records WHERE customer_id IN (" . implode(',', $cids) . ")")->fetchAll(PDO::FETCH_COLUMN)))));
            $files = 0;
            foreach ($vids as $vid) {
                $st = db()->prepare("SELECT file_path FROM crm_visit_files WHERE visit_id=?"); $st->execute([$vid]);
                foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $p) { $abs = __DIR__ . '/../../artdon_erp/' . ltrim($p, '/'); if (is_file($abs)) { @unlink($abs); $files++; } }
                db()->prepare("DELETE FROM crm_visit_files WHERE visit_id=?")->execute([$vid]);
                db()->prepare("DELETE FROM crm_visit_results WHERE visit_id=?")->execute([$vid]);
                db()->prepare("UPDATE crm_tasks SET deleted_at=NOW() WHERE source_type IN ('visit','visit_action') AND (source_id=? OR source_id LIKE ?) AND deleted_at IS NULL")->execute([(string)$vid, $vid . ':%']);
                db()->prepare("DELETE FROM crm_visit_records WHERE id=?")->execute([$vid]);
                mx_purge_visit_children($vid);
            }
            db()->exec("DELETE FROM miniapp_visit_todos WHERE is_demo=1");
            db()->exec("DELETE FROM miniapp_visit_samples WHERE is_demo=1");
            foreach ($cids as $cid) {
                try { db()->prepare("DELETE FROM crm_customer_timeline WHERE customer_id=?")->execute([$cid]); } catch (Throwable $e) {}
                db()->prepare("DELETE FROM crm_customers WHERE id=?")->execute([$cid]);
            }
            mapi_out(true, '示例数据已清除', ['visits' => $vids, 'customers' => $cids, 'files' => $files]);
        }



        case 'audit_page': {
            // Client page-enter logs (home/today/create/edit). Detail opens also logged server-side.
            mapi_auth($input);
            $page = trim((string)($input['page'] ?? $input['page_key'] ?? ''));
            $map = [
                'home' => '进入首页',
                'today' => '进入今日待办',
                'customer_create' => '进入新建客户',
                'visit_edit' => '进入拜访编辑',
                'customers' => '进入客户列表',
                'visits' => '进入拜访列表',
                'customer_detail' => '查看客户详情',
                'visit_detail' => '查看拜访详情',
                'logs' => '查看操作日志',
                'dispatch' => '进入派工待办',
            ];
            if (!isset($map[$page])) mapi_out(true, 'ignored');
            // Skip list floods already excluded; customers/visits list page enter is OK once.
            $tid = (int)($input['target_id'] ?? $input['id'] ?? 0);
            $tname = trim((string)($input['target_name'] ?? ''));
            $ttype = '';
            if ($page === 'customer_detail' || $page === 'customer_create') $ttype = 'customer';
            if ($page === 'visit_detail' || $page === 'visit_edit') $ttype = 'visit';
            if ($tname === '' && $ttype === 'customer' && $tid > 0) $tname = mx_audit_customer_name($tid);
            if ($tname === '' && $ttype === 'visit' && $tid > 0) $tname = mx_audit_visit_name($tid);
            // Dedup: same user+page+target within 20s
            try {
                $st = db()->prepare("SELECT id FROM miniapp_audit_logs WHERE user_id=? AND action_key=? AND target_id=? AND created_at > DATE_SUB(NOW(), INTERVAL 20 SECOND) LIMIT 1");
                $st->execute([mx_uid(), 'page_' . $page, $tid]);
                if ($st->fetchColumn()) mapi_out(true, 'dedup');
            } catch (Throwable $e) {}
            mx_audit_log('page_' . $page, $map[$page], $ttype, $tid, $tname);
            mapi_out(true, 'ok');
            break;
        }

        case 'audit_logs': {
            mapi_auth($input);
            $uid = mx_uid();
            $isAdmin = mx_is_audit_admin($uid);
            $filterUid = (int)($input['user_id'] ?? 0);
            $page = max(1, (int)($input['page'] ?? 1));
            $ps = min(50, max(10, (int)($input['page_size'] ?? 30)));
            $off = ($page - 1) * $ps;
            $where = ['created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)'];
            $p = [];
            if (!$isAdmin) {
                $where[] = 'user_id = ?';
                $p[] = $uid;
            } elseif ($filterUid > 0) {
                $where[] = 'user_id = ?';
                $p[] = $filterUid;
            }
            $sqlWhere = implode(' AND ', $where);
            $st = db()->prepare("SELECT id, user_id, user_name, action_key, action_label, target_type, target_id, target_name, detail, created_at FROM miniapp_audit_logs WHERE $sqlWhere ORDER BY id DESC LIMIT " . ($ps + 1) . " OFFSET $off");
            $st->execute($p);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
            $hasMore = count($rows) > $ps;
            if ($hasMore) array_pop($rows);
            $list = [];
            foreach ($rows as $r) {
                $ts = strtotime((string)$r['created_at']);
                $timeShort = $ts ? date('m-d H:i', $ts) : '';
                $parts = [
                    (string)($r['user_name'] ?: '用户'),
                    (string)$r['action_label'],
                ];
                $tn = trim((string)$r['target_name']);
                if ($tn !== '') $parts[] = $tn;
                $parts[] = $timeShort;
                $line = implode(' · ', $parts);
                $list[] = [
                    'id' => (int)$r['id'],
                    'user_id' => (int)$r['user_id'],
                    'user_name' => (string)$r['user_name'],
                    'action_key' => (string)$r['action_key'],
                    'action_label' => (string)$r['action_label'],
                    'target_type' => (string)$r['target_type'],
                    'target_id' => (int)$r['target_id'],
                    'target_name' => (string)$r['target_name'],
                    'detail' => (string)$r['detail'],
                    'created_at' => (string)$r['created_at'],
                    'time_short' => $timeShort,
                    'line' => $line,
                ];
            }
            mapi_out(true, '', [
                'list' => $list,
                'page' => $page,
                'has_more' => $hasMore,
                'is_admin' => $isAdmin,
                'retain_days' => 7,
            ]);
            break;
        }

        case 'audit_users': {
            // Admin-only: people who have logs in last 7 days (+ all active optional)
            mapi_auth($input);
            if (!mx_is_audit_admin()) mapi_out(false, '无权限');
            $st = db()->query("SELECT DISTINCT user_id, user_name FROM miniapp_audit_logs WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) AND user_id > 0 ORDER BY user_name ASC");
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
            $list = [];
            foreach ($rows as $r) {
                $list[] = ['id' => (int)$r['user_id'], 'name' => (string)$r['user_name']];
            }
            mapi_out(true, '', ['list' => $list]);
            break;
        }


        case 'dispatch_list': {
            // 派工待办：与网站同一张 dispatch_next_tasks
            // kind=personal|dispatch ；scope=mine|created|assigned|all ；view_user_id=看某人
            mapi_auth($input);
            $uid = mx_uid();
            $isAdmin = mx_is_audit_admin($uid);
            $kind = trim((string)($input['kind'] ?? 'dispatch')); // personal | dispatch
            if (!in_array($kind, ['personal', 'dispatch'], true)) $kind = 'dispatch';
            $scope = trim((string)($input['scope'] ?? 'mine')); // mine | created | assigned | all
            $statusFilter = trim((string)($input['status'] ?? 'open')); // open | done | cancelled | all
            $kw = trim((string)($input['q'] ?? $input['keyword'] ?? ''));
            $viewUserId = (int)($input['view_user_id'] ?? $input['user_id'] ?? 0);
            $page = max(1, (int)($input['page'] ?? 1));
            $per = min(50, max(1, (int)($input['per_page'] ?? 30)));
            $offset = ($page - 1) * $per;

            // 非管理员只能看自己；管理员/主管可指定 view_user_id 看某人
            if ($viewUserId > 0 && !$isAdmin && $viewUserId !== $uid) {
                mapi_out(false, '无权查看他人派工');
            }
            $focusUid = $viewUserId > 0 ? $viewUserId : $uid;

            $where = ['t.is_deleted = 0'];
            $params = [];
            if ($kind === 'personal') {
                $where[] = "t.task_type IN ('personal','private')";
                $where[] = "COALESCE(t.converted_status,'normal') <> 'hidden'";
            } else {
                $where[] = "t.task_type = 'dispatch'";
            }

            // 按人：个人区看创建人或负责人；派工区看负责人或派出人（方便主管总览 A）
            // scope 在「看自己」时仍可用：我派出的 / 派给我的
            if ($viewUserId > 0 && $viewUserId !== $uid) {
                $where[] = '(t.assigned_to = ? OR t.created_by = ?)';
                $params[] = $focusUid;
                $params[] = $focusUid;
            } elseif ($scope === 'all' && $isAdmin) {
                // 管理员看全部（当前 kind）
            } elseif ($scope === 'created') {
                $where[] = 't.created_by = ?';
                $params[] = $uid;
            } elseif ($scope === 'assigned') {
                $where[] = 't.assigned_to = ?';
                $params[] = $uid;
            } else {
                $where[] = '(t.created_by = ? OR t.assigned_to = ?)';
                $params[] = $uid;
                $params[] = $uid;
            }

            if ($statusFilter === 'open') {
                $where[] = "t.status NOT IN ('done','cancelled')";
            } elseif ($statusFilter === 'done') {
                $where[] = "t.status = 'done'";
            } elseif ($statusFilter === 'cancelled') {
                $where[] = "t.status = 'cancelled'";
            }
            if ($kw !== '') {
                $where[] = '(t.task_no LIKE ? OR t.title LIKE ? OR t.project LIKE ? OR t.description LIKE ?)';
                $like = '%' . $kw . '%';
                array_push($params, $like, $like, $like, $like);
            }
            $sqlWhere = implode(' AND ', $where);
            $cntSt = db()->prepare("SELECT COUNT(*) FROM dispatch_next_tasks t WHERE {$sqlWhere}");
            $cntSt->execute($params);
            $total = (int)$cntSt->fetchColumn();
            $st = db()->prepare("SELECT t.id, t.task_no, t.task_type, t.title, t.project, t.description, t.status, t.priority,
                t.created_by, t.assigned_to, t.task_date, t.due_at, t.completed_at, t.cancelled_at,
                t.progress, t.linked_system, t.linked_table, t.linked_id, t.linked_title, t.linked_json,
                t.created_at, t.updated_at,
                COALESCE(NULLIF(au.real_name,''), au.username, '') AS assignee_name,
                COALESCE(NULLIF(cu.real_name,''), cu.username, '') AS creator_name
                FROM dispatch_next_tasks t
                LEFT JOIN crm_users au ON au.id = t.assigned_to
                LEFT JOIN crm_users cu ON cu.id = t.created_by
                WHERE {$sqlWhere}
                ORDER BY FIELD(t.status,'pending_accept','accepted','in_progress','paused','submitted','returned','rejected','done','cancelled'),
                    COALESCE(t.due_at,'2999-12-31'), t.id DESC
                LIMIT {$per} OFFSET {$offset}");
            $st->execute($params);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
            $list = [];
            foreach ($rows as $r) {
                $status = (string)($r['status'] ?? '');
                $createdBy = (int)($r['created_by'] ?? 0);
                $taskType = (string)($r['task_type'] ?? 'dispatch');
                $linked = [];
                if (!empty($r['linked_json'])) {
                    $decoded = json_decode((string)$r['linked_json'], true);
                    if (is_array($decoded)) $linked = $decoded;
                }
                $visitId = (int)($linked['visit_id'] ?? 0);
                $customerId = (int)($linked['customer_id'] ?? 0);
                $customerName = (string)($linked['customer_name'] ?? '');
                $matterLabel = (string)($linked['matter_label'] ?? $r['linked_title'] ?? '');
                $dueAt = (string)($r['due_at'] ?? '');
                $isOverdue = $dueAt !== '' && !in_array($status, ['done', 'cancelled'], true) && substr($dueAt, 0, 10) < date('Y-m-d');
                $related = $customerName !== '' ? $customerName : ($matterLabel !== '' ? $matterLabel : (string)($r['linked_title'] ?? ''));
                if ($related === '' && $visitId > 0) $related = '拜访#' . $visitId;
                $kindLabel = in_array($taskType, ['personal', 'private'], true) ? '个人' : '派工';
                $list[] = [
                    'id' => (int)$r['id'],
                    'task_no' => (string)$r['task_no'],
                    'task_type' => $taskType,
                    'kind' => in_array($taskType, ['personal', 'private'], true) ? 'personal' : 'dispatch',
                    'kind_label' => $kindLabel,
                    'title' => (string)$r['title'],
                    'project' => (string)($r['project'] ?? ''),
                    'description' => (string)($r['description'] ?? ''),
                    'status' => $status,
                    'status_label' => mx_dispatch_status_label($status),
                    'priority' => (string)($r['priority'] ?? 'normal'),
                    'priority_label' => mx_dispatch_priority_label((string)($r['priority'] ?? 'normal')),
                    'created_by' => $createdBy,
                    'creator_name' => (string)($r['creator_name'] ?? ''),
                    'assigned_to' => (int)($r['assigned_to'] ?? 0),
                    'assignee_name' => (string)($r['assignee_name'] ?? ''),
                    'task_date' => (string)($r['task_date'] ?? ''),
                    'due_at' => $dueAt,
                    'due_date' => $dueAt !== '' ? substr($dueAt, 0, 10) : '',
                    'due_time' => $dueAt !== '' && strlen($dueAt) >= 16 ? substr($dueAt, 11, 5) : '',
                    'completed_at' => (string)($r['completed_at'] ?? ''),
                    'progress' => (int)($r['progress'] ?? 0),
                    'is_overdue' => $isOverdue,
                    'can_cancel' => $status !== 'done' && $status !== 'cancelled' && ($createdBy === $uid || $isAdmin),
                    'can_edit' => mx_dispatch_can_edit_row($r, $uid),
                    'can_complete' => mx_dispatch_can_complete_row($r, $uid),
                    'visit_id' => $visitId,
                    'customer_id' => $customerId,
                    'customer_name' => $customerName,
                    'matter_label' => $matterLabel,
                    'related_label' => $related,
                    'linked_table' => (string)($r['linked_table'] ?? ''),
                    'linked_id' => (string)($r['linked_id'] ?? ''),
                    'created_at' => (string)($r['created_at'] ?? ''),
                    'updated_at' => (string)($r['updated_at'] ?? ''),
                ];
            }
            $viewName = '';
            if ($viewUserId > 0) {
                try {
                    $nst = db()->prepare("SELECT COALESCE(NULLIF(real_name,''), username, '') FROM crm_users WHERE id=? LIMIT 1");
                    $nst->execute([$viewUserId]);
                    $viewName = (string)$nst->fetchColumn();
                } catch (Throwable $e) {}
            }
            mapi_out(true, '', [
                'list' => $list,
                'total' => $total,
                'page' => $page,
                'per_page' => $per,
                'has_more' => ($offset + count($list)) < $total,
                'is_admin' => $isAdmin,
                'can_view_others' => $isAdmin,
                'kind' => $kind,
                'scope' => $scope,
                'view_user_id' => $viewUserId > 0 ? $viewUserId : 0,
                'view_user_name' => $viewName,
                'me_id' => $uid,
            ]);
            break;
        }


        case 'dispatch_create': {
            mapi_auth($input);
            try {
                $r = mx_dispatch_create($input);
                mx_audit_log('dispatch_create', '新建派工', 'dispatch', (int)($r['id'] ?? 0), (string)($r['task_no'] ?? ''), 'create');
                mapi_out(true, (string)($r['message'] ?? '已创建'), $r);
            } catch (Throwable $e) {
                mapi_out(false, $e->getMessage() ?: '创建失败');
            }
            break;
        }

        case 'dispatch_update': {
            mapi_auth($input);
            try {
                $r = mx_dispatch_update($input);
                mx_audit_log('dispatch_update', '编辑派工', 'dispatch', (int)($r['id'] ?? 0), (string)($r['task_no'] ?? ''), 'update');
                mapi_out(true, (string)($r['message'] ?? '已保存'), $r);
            } catch (Throwable $e) {
                mapi_out(false, $e->getMessage() ?: '保存失败');
            }
            break;
        }

        case 'dispatch_detail': {
            mapi_auth($input);
            try {
                $id = (int)($input['id'] ?? $input['dispatch_id'] ?? 0);
                $r = mx_dispatch_detail($id);
                mapi_out(true, '', $r);
            } catch (Throwable $e) {
                mapi_out(false, $e->getMessage() ?: '读取失败');
            }
            break;
        }

        case 'dispatch_cancel': {
            // 撤回派工（网站 status=cancelled），清空关联后可重新派
            mapi_auth($input);
            $id = (int)($input['id'] ?? $input['dispatch_id'] ?? $input['task_id'] ?? 0);
            try {
                $r = mx_dispatch_cancel($id);
                mx_audit_log('dispatch_cancel', '撤回派工', 'dispatch', $id, (string)($r['task_no'] ?? ''), 'cancelled');
                mapi_out(true, '已撤回派工', $r);
            } catch (Throwable $e) {
                mapi_out(false, $e->getMessage() ?: '撤回失败');
            }
            break;
        }

        case 'translate_batch': {
            // Screenshot EN mode: translate user-entered Chinese → English. Originals stay on client.
            // Primary engine: Tencent TMT; MyMemory last resort.
            mapi_auth($input);
            $texts = $input['texts'] ?? $input['list'] ?? [];
            if (!is_array($texts)) $texts = [];
            $texts = array_slice(array_values($texts), 0, 80);
            $clean = [];
            foreach ($texts as $t) $clean[] = mb_substr(trim((string)$t), 0, 2000);
            try {
                [$translated, $provider] = mx_translate_zh_en($clean);
                mapi_out(true, '', ['texts' => $translated, 'source' => 'zh', 'target' => 'en', 'provider' => $provider]);
            } catch (Throwable $e) {
                mapi_out(false, $e->getMessage() ?: '翻译失败');
            }
            break;
        }

        default:
            mapi_out(false, '未知接口: ' . $action);
    }
} catch (Throwable $e) {
    mapi_out(false, $e->getMessage());
}
