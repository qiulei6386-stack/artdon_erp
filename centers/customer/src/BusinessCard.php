<?php
declare(strict_types=1);

namespace Artdon\Centers\Customer;

use Artdon\Centers\Base\Db;
use Artdon\Centers\Base\NotFoundException;
use Artdon\Centers\Base\Permission;
use Artdon\Centers\Base\PermissionException;
use Artdon\Centers\Base\ValidationException;
use PDO;
use RuntimeException;

/**
 * 名片识别（腾讯云名片识别 BusinessCardOCR），做法与旧系统一致：
 *   密钥读服务器 /www/server/artdon_secrets/tencent_ocr.php（不在代码仓库里）；
 *   图片压到 1MB 以内再识别；识别后图片先放 storage/customer_files/pending_business_cards（24 小时内有效），
 *   新建客户时移到 storage/customer_files/{客户编号}/ 并在 crm_customer_files 记一条 category = business_card。
 * 识别需要 customer.create；看名片跟着客户可见范围；删除需要 customer.file_delete。
 */
final class BusinessCard
{
    public const SECRET_FILE = '/www/server/artdon_secrets/tencent_ocr.php';
    public const MAX_BYTES = 1048576;

    /** @var PDO */
    private $pdo;

    /** @var array<string, mixed> */
    private $operator;

    /** @var callable(string, array<string, string>): string 发请求（测试时替换） */
    private $transport;

    /** @var string */
    private $storage;

    /** @var array<string, mixed>|null 密钥配置（测试时直接传入） */
    private $config;

    public function __construct(array $operator, ?PDO $pdo = null, ?callable $transport = null, ?string $storage = null, ?array $config = null)
    {
        $this->config = $config;
        $this->operator = $operator;
        $this->pdo = $pdo ?? Db::pdo();
        $this->transport = $transport ?? [self::class, 'curlPost'];
        $this->storage = rtrim($storage ?? ARTDON_APP_ROOT . '/storage/customer_files', '/');
    }

    /**
     * 识别一张名片。返回客户字段、联系人字段和图片令牌（新建客户时带上令牌，名片图会存到客户下）。
     *
     * @param array{tmp_name?: string, name?: string, size?: int, error?: int} $file
     * @return array<string, mixed>
     */
    public function recognize(array $file): array
    {
        if (!Permission::allows($this->operator, 'customer.create')) {
            throw new PermissionException('没有新建客户的权限（customer.create）');
        }
        $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        $tmp = (string) ($file['tmp_name'] ?? '');
        if ($error === UPLOAD_ERR_NO_FILE || $tmp === '' || !is_file($tmp)) {
            throw new ValidationException(['card' => '请选择名片照片']);
        }
        if ($error !== UPLOAD_ERR_OK) {
            throw new ValidationException(['card' => '上传失败（错误码 ' . $error . '），照片可能太大']);
        }
        $bytes = self::shrink((string) file_get_contents($tmp));
        $info = @getimagesizefromstring($bytes);
        if (!$info || !in_array($info['mime'] ?? '', ['image/jpeg', 'image/png'], true)) {
            throw new ValidationException(['card' => '只支持 JPG、PNG 照片']);
        }
        if ($info[0] < 15 || $info[1] < 15) {
            throw new ValidationException(['card' => '照片太小，识别不了']);
        }
        $config = $this->config ?? (is_file(self::SECRET_FILE) ? (array) require self::SECRET_FILE : []);
        $response = $this->call($bytes, $config);
        $result = self::parse($response);
        $result['token'] = $this->storePending($bytes);
        CustomerLog::write('business_card_ocr', 0, '名片识别', implode('、', array_unique(array_column($result['raw'], 'name'))), null, ['request_id' => $result['request_id']], $this->operator, 'customer_scan', $result['request_id']);
        return $result;
    }

    /**
     * 把识别结果整理成客户、联系人字段（规则同旧系统）。
     *
     * @param array<string, mixed> $response 腾讯接口返回的 Response
     * @return array<string, mixed>
     */
    public static function parse(array $response): array
    {
        if (!empty($response['Error'])) {
            $code = (string) ($response['Error']['Code'] ?? '');
            throw new RuntimeException('名片识别失败：' . (string) ($response['Error']['Message'] ?? '') . ($code !== '' ? '（' . $code . '）' : ''));
        }
        $raw = [];
        $grouped = [];
        foreach ((array) ($response['BusinessCardInfos'] ?? []) as $item) {
            $name = trim((string) ($item['Name'] ?? ''));
            $value = trim((string) ($item['Value'] ?? ''));
            if ($name === '' || $value === '') {
                continue;
            }
            $raw[] = ['name' => $name, 'value' => $value];
            if (!in_array($value, $grouped[$name] ?? [], true)) {
                $grouped[$name][] = $value;
            }
        }
        if (!$raw) {
            throw new RuntimeException('照片里没有识别到名片内容，请换一张清晰的照片');
        }
        $pick = static function (array $names) use ($grouped): string {
            foreach ($names as $n) {
                if (!empty($grouped[$n][0])) {
                    return (string) $grouped[$n][0];
                }
            }
            return '';
        };
        $companies = array_values(array_unique(array_merge($grouped['公司'] ?? [], $grouped['公司英文'] ?? [], $grouped['英文公司'] ?? [])));
        $cn = '';
        $en = '';
        foreach ($companies as $c) {
            if ($cn === '' && preg_match('/[\x{4e00}-\x{9fff}]/u', $c)) {
                $cn = $c;
            } elseif ($en === '' && preg_match('/[A-Za-z]/', $c)) {
                $en = $c;
            }
        }
        if ($cn === '') {
            $cn = $companies[0] ?? '';
            if ($cn === $en) {
                $en = '';
            }
        }
        $names = array_values(array_unique(array_merge($grouped['姓名'] ?? [], $grouped['英文姓名'] ?? [])));
        $nameEn = '';
        foreach ($names as $n) {
            if (preg_match('/[A-Za-z]/', $n)) {
                $nameEn = $n;
                break;
            }
        }
        $address = $pick(['地址', '英文地址']);
        $phone = $pick(['手机', '电话']);
        $email = $pick(['邮箱']);
        $country = self::guessCountry($address . ' ' . $phone);
        return [
            'customer' => ['customer_name' => $cn, 'customer_name_en' => $en, 'country' => $country, 'address' => $address, 'website' => $pick(['网址', '网站']), 'email' => $email, 'phone' => $phone],
            'contact' => ['name' => $names[0] ?? '', 'name_en' => $nameEn !== ($names[0] ?? '') ? $nameEn : '', 'position' => $pick(['职位', '英文职位']), 'email' => $email, 'phone' => $phone, 'wechat' => $pick(['微信'])],
            'raw' => $raw,
            'request_id' => (string) ($response['RequestId'] ?? ''),
        ];
    }

    /** 由地址、电话猜国家（返回字典代码，猜不出为空）。 */
    public static function guessCountry(string $text): string
    {
        $text = mb_strtolower($text, 'UTF-8');
        $rules = [
            'HK' => ['香港', 'hong kong', '+852'], 'MO' => ['澳门', 'macau', 'macao', '+853'], 'TW' => ['台湾', 'taiwan', '+886'], 'CN' => ['中国', 'china', '+86'],
            'AU' => ['澳大利亚', 'australia', '+61'], 'US' => ['美国', 'united states', 'usa', '+1 '], 'GB' => ['英国', 'united kingdom', 'england', '+44'], 'IN' => ['印度', 'india', '+91'],
            'ID' => ['印度尼西亚', 'indonesia', '+62'], 'VN' => ['越南', 'vietnam', '+84'], 'TH' => ['泰国', 'thailand', '+66'], 'MY' => ['马来西亚', 'malaysia', '+60'], 'SG' => ['新加坡', 'singapore', '+65'],
            'PH' => ['菲律宾', 'philippines', '+63'], 'KR' => ['韩国', 'korea', '+82'], 'JP' => ['日本', 'japan', '+81'], 'AE' => ['阿联酋', 'united arab emirates', 'uae', 'dubai', '+971'],
            'SA' => ['沙特', 'saudi', '+966'], 'DE' => ['德国', 'germany', '+49'], 'FR' => ['法国', 'france', '+33'], 'IT' => ['意大利', 'italy', '+39'], 'ES' => ['西班牙', 'spain', '+34'],
            'NL' => ['荷兰', 'netherlands', '+31'], 'CA' => ['加拿大', 'canada'], 'BR' => ['巴西', 'brazil', '+55'], 'MX' => ['墨西哥', 'mexico', '+52'], 'ZA' => ['南非', 'south africa', '+27'],
        ];
        // 印度尼西亚要先于印度判断
        $order = array_merge(['ID'], array_diff(array_keys($rules), ['ID']));
        foreach ($order as $code) {
            foreach ($rules[$code] as $needle) {
                if (mb_strpos($text, $needle) !== false) {
                    return Country::options() && !isset(Country::options()[$code]) ? '' : $code;
                }
            }
        }
        return '';
    }

    /** 新建客户后把名片图存到客户下。令牌无效或文件已过期时什么都不做。 */
    public function attach(int $customerId, string $token): ?int
    {
        $token = strtolower(trim($token));
        if ($customerId <= 0 || !preg_match('/^[a-f0-9]{40}$/', $token)) {
            return null;
        }
        $source = $this->storage . '/pending_business_cards/' . $token . '.jpg';
        if (!is_file($source)) {
            return null;
        }
        $dir = $this->storage . '/' . $customerId;
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        if (!is_dir($dir) || !is_writable($dir)) {
            throw new RuntimeException('客户名片目录不可写');
        }
        $name = 'business_card_' . date('Ymd_His') . '.jpg';
        $target = $dir . '/' . bin2hex(random_bytes(6)) . '_' . $name;
        if (!@rename($source, $target) && !(@copy($source, $target) && @unlink($source))) {
            throw new RuntimeException('名片图片保存失败');
        }
        $size = (int) (filesize($target) ?: 0);
        $this->pdo->prepare('INSERT INTO crm_customer_files (customer_id, file_name, file_path, file_type, file_size, category, remark, uploaded_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$customerId, $name, $target, 'image/jpeg', $size, 'business_card', '名片识别原图（已压缩）', (int) $this->operator['id'], Db::now()]);
        $id = (int) $this->pdo->lastInsertId();
        CustomerLog::write('business_card_save', $customerId, '保存名片图片', $name, null, ['file_id' => $id, 'file_size' => $size], $this->operator, 'customer_file', (string) $id);
        return $id;
    }

    /** 客户的名片图。 @return list<array<string, mixed>> */
    public function forCustomer(int $customerId): array
    {
        if (!Db::tableExists('crm_customer_files')) {
            return [];
        }
        $stmt = $this->pdo->prepare("SELECT f.id, f.file_name, f.file_size, f.created_at, COALESCE(NULLIF(u.real_name, ''), u.username) AS uploader
            FROM crm_customer_files f LEFT JOIN crm_users u ON u.id = f.uploaded_by WHERE f.customer_id = ? AND f.category = 'business_card' AND f.deleted_at IS NULL ORDER BY f.id DESC");
        $stmt->execute([$customerId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** 取一张名片图（要能看到这个客户）。 @return array<string, mixed> */
    public function file(int $fileId): array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM crm_customer_files WHERE id = ? AND category = 'business_card' AND deleted_at IS NULL");
        $stmt->execute([$fileId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new NotFoundException('名片图片不存在');
        }
        (new CustomerService($this->operator, $this->pdo))->get((int) $row['customer_id']);
        $real = realpath((string) $row['file_path']);
        $root = realpath($this->storage);
        if ($real === false || $root === false || strpos($real, $root . DIRECTORY_SEPARATOR) !== 0) {
            throw new NotFoundException('名片图片文件不存在');
        }
        $row['real_path'] = $real;
        return $row;
    }

    public function delete(int $fileId): int
    {
        if (!Permission::allows($this->operator, 'customer.file_delete')) {
            throw new PermissionException('没有删除客户文件的权限（customer.file_delete）');
        }
        $row = $this->file($fileId);
        $this->pdo->prepare('UPDATE crm_customer_files SET deleted_at = ?, deleted_by = ? WHERE id = ?')->execute([Db::now(), (int) $this->operator['id'], $fileId]);
        @unlink((string) $row['real_path']);
        CustomerLog::write('business_card_delete', (int) $row['customer_id'], '删除名片图片', (string) $row['file_name'], ['file_name' => $row['file_name']], ['deleted' => true], $this->operator, 'customer_file', (string) $fileId);
        return (int) $row['customer_id'];
    }

    // ---------------------------------------------------------------- 内部

    /** 大于 1MB 时缩小并转成 JPG（没有 GD 扩展时只接受 5MB 以内的原图）。 */
    private static function shrink(string $bytes): string
    {
        if ($bytes === '') {
            throw new ValidationException(['card' => '读取照片失败']);
        }
        if (strlen($bytes) <= self::MAX_BYTES) {
            return $bytes;
        }
        if (!function_exists('imagecreatefromstring')) {
            throw new ValidationException(['card' => '照片不能超过 1MB']);
        }
        $src = @imagecreatefromstring($bytes);
        if (!$src) {
            throw new ValidationException(['card' => '照片格式无法处理']);
        }
        $w = imagesx($src);
        $h = imagesy($src);
        $best = null;
        foreach ([1920, 1600, 1280, 1024] as $edge) {
            $scale = min(1.0, $edge / max($w, $h));
            $img = imagecreatetruecolor(max(1, (int) round($w * $scale)), max(1, (int) round($h * $scale)));
            imagefill($img, 0, 0, imagecolorallocate($img, 255, 255, 255));
            imagecopyresampled($img, $src, 0, 0, 0, 0, imagesx($img), imagesy($img), $w, $h);
            foreach ([85, 70, 55, 40] as $q) {
                ob_start();
                imagejpeg($img, null, $q);
                $jpeg = (string) ob_get_clean();
                if ($jpeg !== '' && strlen($jpeg) <= self::MAX_BYTES) {
                    $best = $jpeg;
                    break 2;
                }
            }
            imagedestroy($img);
        }
        imagedestroy($src);
        if ($best === null) {
            throw new ValidationException(['card' => '照片压缩后仍超过 1MB，请换一张']);
        }
        return $best;
    }

    /** 识别后的图片先暂存，返回令牌。 */
    private function storePending(string $bytes): string
    {
        $dir = $this->storage . '/pending_business_cards';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        if (!is_dir($dir) || !is_writable($dir)) {
            throw new RuntimeException('名片图片目录不可写');
        }
        foreach ((array) glob($dir . '/*.jpg') as $old) {
            if (is_string($old) && is_file($old) && (int) filemtime($old) < time() - 86400) {
                @unlink($old);
            }
        }
        $token = bin2hex(random_bytes(20));
        $path = $dir . '/' . $token . '.jpg';
        $info = @getimagesizefromstring($bytes);
        if (($info['mime'] ?? '') === 'image/png' && function_exists('imagecreatefromstring')) {
            $img = @imagecreatefromstring($bytes);
            if ($img) {
                $jpg = imagecreatetruecolor(imagesx($img), imagesy($img));
                imagefill($jpg, 0, 0, imagecolorallocate($jpg, 255, 255, 255));
                imagecopy($jpg, $img, 0, 0, 0, 0, imagesx($img), imagesy($img));
                ob_start();
                imagejpeg($jpg, null, 85);
                $bytes = (string) ob_get_clean();
                imagedestroy($img);
                imagedestroy($jpg);
            }
        }
        if (file_put_contents($path, $bytes) === false) {
            throw new RuntimeException('名片图片保存失败');
        }
        return $token;
    }

    /** @return array<string, mixed> */
    private function call(string $image, array $config): array
    {
        $id = trim((string) ($config['secret_id'] ?? ''));
        $key = trim((string) ($config['secret_key'] ?? ''));
        $region = trim((string) ($config['region'] ?? 'ap-guangzhou'));
        $host = trim((string) ($config['endpoint'] ?? 'ocr.tencentcloudapi.com'));
        if ($id === '' || $key === '') {
            throw new RuntimeException('名片识别还没有在服务器上配置密钥');
        }
        $payload = (string) json_encode(['ImageBase64' => base64_encode($image), 'Config' => json_encode(['RetImageType' => 'PROPROCESS'])], JSON_UNESCAPED_SLASHES);
        $ts = time();
        $date = gmdate('Y-m-d', $ts);
        $canonical = "POST\n/\n\ncontent-type:application/json; charset=utf-8\nhost:{$host}\n\ncontent-type;host\n" . hash('sha256', $payload);
        $scope = "{$date}/ocr/tc3_request";
        $toSign = "TC3-HMAC-SHA256\n{$ts}\n{$scope}\n" . hash('sha256', $canonical);
        $kDate = hash_hmac('sha256', $date, 'TC3' . $key, true);
        $kService = hash_hmac('sha256', 'ocr', $kDate, true);
        $kSigning = hash_hmac('sha256', 'tc3_request', $kService, true);
        $signature = hash_hmac('sha256', $toSign, $kSigning);
        $body = ($this->transport)('https://' . $host, [
            'Authorization' => "TC3-HMAC-SHA256 Credential={$id}/{$scope}, SignedHeaders=content-type;host, Signature={$signature}",
            'Content-Type' => 'application/json; charset=utf-8', 'Host' => $host, 'X-TC-Action' => 'BusinessCardOCR', 'X-TC-Timestamp' => (string) $ts,
            'X-TC-Version' => '2018-11-19', 'X-TC-Region' => $region, '__body' => $payload,
        ]);
        $json = json_decode($body, true);
        if (!is_array($json) || !isset($json['Response']) || !is_array($json['Response'])) {
            throw new RuntimeException('名片识别服务返回格式不正确');
        }
        return $json['Response'];
    }

    /** @param array<string, string> $headers */
    public static function curlPost(string $url, array $headers): string
    {
        $body = $headers['__body'];
        unset($headers['__body']);
        $lines = [];
        foreach ($headers as $k => $v) {
            $lines[] = $k . ': ' . $v;
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_TIMEOUT => 30, CURLOPT_HTTPHEADER => $lines, CURLOPT_POSTFIELDS => $body]);
        $result = curl_exec($ch);
        $error = curl_error($ch);
        curl_close($ch);
        if ($result === false || $error !== '') {
            throw new RuntimeException('名片识别服务连接失败：' . $error);
        }
        return (string) $result;
    }
}
