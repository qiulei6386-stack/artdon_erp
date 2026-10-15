<?php
declare(strict_types=1);

namespace Artdon\Centers\Customer;

use Artdon\Centers\Base\Db;
use Artdon\Centers\Base\Dictionary;
use Artdon\Centers\Base\Permission;
use Artdon\Centers\Base\PermissionException;
use Artdon\Centers\Base\UserDirectory;
use Artdon\Centers\Base\ValidationException;
use PDO;
use RuntimeException;
use Throwable;

/**
 * 导入客户（customer.import）：上传 CSV / Excel → 预览（逐行检查）→ 确认导入。
 * 规则沿用旧系统：名称、国家必填；同名、客户代码已存在或文件里重复的不导入；高度疑似重复按查重规则进暂存池或不导入；
 * 其余直接建成正式客户（准入规则为“只有导入的客户进入暂存池”时全部进暂存池）。
 * 预览结果存在服务器临时目录，2 小时内有效，只有上传人能用。
 */
final class CustomerImport
{
    /** 表头别名 → 字段。比较时不分大小写、去掉空格。 */
    public const HEADERS = [
        'customer_code' => ['客户代码', '客户编号', '代码', 'code', 'customer_code'],
        'customer_name' => ['客户名称', '客户名', '公司名称', '公司', 'name', 'company', 'customer_name'],
        'customer_name_en' => ['英文名称', '英文名', 'english name', 'customer_name_en'],
        'country' => ['国家', '国家地区', '国家/地区', '国家代码', 'country'],
        'city' => ['城市', '地区', 'city'],
        'address' => ['地址', '详细地址', 'address'],
        'website' => ['网站', '网址', 'website', 'url'],
        'email' => ['邮箱', '公司邮箱', '邮件', 'email', 'e-mail', 'mail'],
        'phone' => ['电话', '手机', 'phone', 'tel', 'mobile', 'telephone'],
        'whatsapp' => ['whatsapp', 'wa'],
        'wechat' => ['微信', 'wechat'],
        'level' => ['客户等级', '等级', 'level'],
        'lifecycle_key' => ['阶段', '生命周期', '业务阶段', 'lifecycle'],
        'source_tags' => ['来源', '客户来源', '来源标签', 'source', 'source_tags'],
        'promotion_channels' => ['推广方式', '推广渠道', 'promotion_channels'],
        'group_names' => ['分组', '客户分组', 'group'],
        'owner' => ['负责人', '第一负责人', 'owner'],
        'owner_user_id' => ['负责人id', 'owner_user_id'],
        'remark' => ['备注', 'remark', 'note'],
        'contacts_json' => ['联系人明细json', 'contacts_json'],
    ];

    /** 联系人列：联系人1姓名、联系人1邮箱……（1 到 3）。 */
    private const CONTACT_FIELDS = ['name' => ['姓名', '', 'name'], 'position' => ['职位', 'position'], 'email' => ['邮箱', 'email'], 'phone' => ['电话', 'phone'], 'whatsapp' => ['whatsapp'], 'wechat' => ['微信', 'wechat']];

    private const TTL = 7200;

    /** @var PDO */
    private $pdo;

    /** @var array<string, mixed> */
    private $operator;

    public function __construct(array $operator, ?PDO $pdo = null)
    {
        $this->operator = $operator;
        $this->pdo = $pdo ?? Db::pdo();
        if (!Permission::allows($operator, 'customer.import')) {
            throw new PermissionException('没有导入客户的权限（customer.import）');
        }
    }

    /** 模板的表头。 @return list<string> */
    public static function templateHeaders(): array
    {
        return ['客户名称', '英文名称', '国家', '城市', '地址', '网站', '邮箱', '电话', 'WhatsApp', '微信', '客户代码', '等级', '阶段', '来源', '推广方式', '分组', '负责人', '备注',
            '联系人1姓名', '联系人1职位', '联系人1邮箱', '联系人1电话', '联系人1WhatsApp', '联系人2姓名', '联系人2职位', '联系人2邮箱', '联系人2电话', '联系人2WhatsApp'];
    }

    /**
     * 读文件并逐行检查，结果存到临时文件，返回预览编号。
     *
     * @param array{tmp_name?: string, name?: string, size?: int, error?: int} $file
     */
    public function upload(array $file): string
    {
        $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error === UPLOAD_ERR_NO_FILE || empty($file['tmp_name'])) {
            throw new ValidationException(['file' => '请选择要导入的文件']);
        }
        if ($error !== UPLOAD_ERR_OK) {
            throw new ValidationException(['file' => '上传失败（错误码 ' . $error . '），文件可能太大']);
        }
        if ((int) ($file['size'] ?? 0) > 10 * 1024 * 1024) {
            throw new ValidationException(['file' => '文件不能超过 10MB']);
        }
        $ext = strtolower((string) pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
        if (!in_array($ext, ['csv', 'xlsx', 'txt'], true)) {
            throw new ValidationException(['file' => '请上传 .xlsx 或 .csv 文件']);
        }
        try {
            $matrix = Spreadsheet::read((string) $file['tmp_name'], (string) $file['name']);
        } catch (RuntimeException $e) {
            throw new ValidationException(['file' => $e->getMessage()]);
        }
        $preview = $this->preview($matrix);
        $preview['file_name'] = mb_substr((string) $file['name'], 0, 120, 'UTF-8');
        $token = bin2hex(random_bytes(16));
        $this->save($token, $preview);
        CustomerLog::write('customer_import_preview', 0, '预览客户导入', $preview['file_name'] . '：共 ' . count($preview['rows']) . ' 行，可导入 ' . $preview['valid'] . ' 行', null, ['rows' => count($preview['rows']), 'valid' => $preview['valid']], $this->operator, 'import', $token);
        return $token;
    }

    /**
     * 逐行检查（不写库）。
     *
     * @param list<list<string>> $matrix
     * @return array{rows: list<array<string, mixed>>, unknown: list<string>, valid: int, warnings: int}
     */
    public function preview(array $matrix): array
    {
        $headerIndex = null;
        foreach ($matrix as $i => $line) {
            if (array_filter($line, static function (string $v): bool {
                return $v !== '';
            })) {
                $headerIndex = $i;
                break;
            }
        }
        if ($headerIndex === null) {
            throw new ValidationException(['file' => '文件是空的']);
        }
        [$map, $unknown] = self::mapHeaders($matrix[$headerIndex]);
        if (!in_array('customer_name', $map, true)) {
            throw new ValidationException(['file' => '没有找到“客户名称”这一列，请用模板的表头']);
        }
        $body = array_slice($matrix, $headerIndex + 1, null, true);
        $body = array_filter($body, static function (array $line): bool {
            return (bool) array_filter($line, static function (string $v): bool {
                return $v !== '';
            });
        });
        if (count($body) > Spreadsheet::MAX_ROWS) {
            throw new ValidationException(['file' => '一次最多导入 ' . Spreadsheet::MAX_ROWS . ' 行，请分成几个文件']);
        }
        if (!$body) {
            throw new ValidationException(['file' => '表头下面没有数据']);
        }
        $canAssign = Permission::allows($this->operator, 'customer.assign');
        $groups = array_flip(array_map('mb_strtolower', GroupService::options($this->pdo)));
        $seenNames = [];
        $seenCodes = [];
        $rows = [];
        $valid = 0;
        $warnCount = 0;
        foreach ($body as $i => $line) {
            $raw = [];
            foreach ($map as $col => $field) {
                $value = trim((string) ($line[$col] ?? ''));
                if ($value !== '' && (!isset($raw[$field]) || $raw[$field] === '')) {
                    $raw[$field] = $value;
                }
            }
            $errors = [];
            $warnings = [];
            $data = $this->normalize($raw, $errors, $warnings, $canAssign, $groups);
            $name = $data['customer_name'];
            if ($name !== '') {
                $key = mb_strtolower($name, 'UTF-8');
                if (isset($seenNames[$key])) {
                    $errors[] = '文件里有同名客户（第 ' . $seenNames[$key] . ' 行）';
                } else {
                    $seenNames[$key] = $i + 1;
                }
                $stmt = $this->pdo->prepare('SELECT id FROM crm_customers WHERE customer_name = ? AND deleted_at IS NULL LIMIT 1');
                $stmt->execute([$name]);
                if ($stmt->fetchColumn()) {
                    $errors[] = '已有同名客户';
                }
            }
            if ($data['customer_code'] !== '') {
                $key = mb_strtolower($data['customer_code'], 'UTF-8');
                if (isset($seenCodes[$key])) {
                    $errors[] = '文件里客户代码重复（第 ' . $seenCodes[$key] . ' 行）';
                } else {
                    $seenCodes[$key] = $i + 1;
                }
                $stmt = $this->pdo->prepare('SELECT id FROM crm_customers WHERE customer_code = ? AND deleted_at IS NULL LIMIT 1');
                $stmt->execute([$data['customer_code']]);
                if ($stmt->fetchColumn()) {
                    $errors[] = '客户代码已被使用';
                }
            }
            $matches = [];
            if (!$errors && $name !== '') {
                $first = $data['contacts'][0] ?? [];
                $matches = DuplicateFinder::find($data + ['contact_email' => $first['email'] ?? '', 'contact_phone' => $first['phone'] ?? ''], 0, $this->pdo);
                if ($matches) {
                    $warnings[] = '疑似重复：' . implode('、', array_map(static function (array $m): string {
                        return $m['customer_name'] . ' ' . $m['similarity'] . '%';
                    }, array_slice($matches, 0, 3)));
                }
            }
            $ok = !$errors;
            $valid += $ok ? 1 : 0;
            $warnCount += $ok && $warnings ? 1 : 0;
            $rows[] = ['row_no' => $i + 1, 'data' => $data, 'errors' => $errors, 'warnings' => $warnings, 'high_risk' => DuplicateFinder::hasHighRisk($matches), 'matches' => array_slice($matches, 0, 5)];
        }
        return ['rows' => $rows, 'unknown' => $unknown, 'valid' => $valid, 'warnings' => $warnCount];
    }

    /**
     * 导入预览里可以导入的行。返回每行结果。
     *
     * @return array{created: int, lead_pool: int, failed: int, skipped: int}
     */
    public function commit(string $token): array
    {
        $preview = $this->load($token);
        if (!empty($preview['result'])) {
            throw new RuntimeException('这份文件已经导入过了');
        }
        $customers = new CustomerService($this->operator, $this->pdo);
        $poolAll = RuleConfig::leadPoolEnabled() && RuleConfig::admissionMode() === 'import_only';
        $action = RuleConfig::duplicateAction();
        $sum = ['created' => 0, 'lead_pool' => 0, 'failed' => 0, 'skipped' => 0];
        foreach ($preview['rows'] as &$row) {
            if ($row['errors']) {
                $row['result'] = ['status' => 'skipped', 'message' => implode('；', $row['errors'])];
                $sum['skipped']++;
                continue;
            }
            $data = $row['data'];
            try {
                if ($row['high_risk'] && $action === 'block') {
                    $row['result'] = ['status' => 'skipped', 'message' => '高度疑似重复，按查重规则不导入'];
                    $sum['skipped']++;
                    continue;
                }
                $toPool = $poolAll || ($row['high_risk'] && in_array($action, ['lead_pool', 'admin_confirm'], true) && RuleConfig::leadPoolEnabled());
                if ($toPool) {
                    $leadId = $customers->submitLead($data, $data['contacts'], (int) $data['owner_user_id'], [], $row['matches'], '客户导入：' . $preview['file_name']);
                    $row['result'] = ['status' => 'lead_pool', 'message' => '进入暂存池 #' . $leadId, 'lead_id' => $leadId];
                    $sum['lead_pool']++;
                    continue;
                }
                $id = $customers->createConfirmed($data, $data['contacts'], (int) $data['owner_user_id'], '导入客户');
                $row['result'] = ['status' => 'created', 'message' => '已导入', 'customer_id' => $id];
                $sum['created']++;
            } catch (ValidationException $e) {
                $row['result'] = ['status' => 'failed', 'message' => implode('；', $e->fieldErrors())];
                $sum['failed']++;
            } catch (Throwable $e) {
                $row['result'] = ['status' => 'failed', 'message' => $e->getMessage()];
                $sum['failed']++;
            }
        }
        unset($row);
        $preview['result'] = $sum;
        $this->save($token, $preview);
        CustomerLog::write('customer_import_commit', 0, '导入客户', $preview['file_name'] . '：导入 ' . $sum['created'] . '，进暂存池 ' . $sum['lead_pool'] . '，失败 ' . $sum['failed'] . '，跳过 ' . $sum['skipped'], null, $sum, $this->operator, 'import', $token);
        return $sum;
    }

    /** @return array<string, mixed> */
    public function load(string $token): array
    {
        if (!preg_match('/^[a-f0-9]{32}$/', $token)) {
            throw new RuntimeException('导入预览不存在或已过期，请重新上传');
        }
        $path = self::path($token);
        $raw = is_file($path) && filemtime($path) > time() - self::TTL ? file_get_contents($path) : false;
        $data = $raw !== false ? json_decode((string) $raw, true) : null;
        if (!is_array($data) || (int) ($data['user_id'] ?? 0) !== (int) $this->operator['id']) {
            throw new RuntimeException('导入预览不存在或已过期，请重新上传');
        }
        return $data;
    }

    // ---------------------------------------------------------------- 内部

    /**
     * @param list<string> $header
     * @return array{0: array<int, string>, 1: list<string>}
     */
    public static function mapHeaders(array $header): array
    {
        $lookup = [];
        foreach (self::HEADERS as $field => $aliases) {
            foreach ($aliases as $a) {
                $lookup[self::key($a)] = $field;
            }
        }
        for ($n = 1; $n <= 3; $n++) {
            foreach (self::CONTACT_FIELDS as $field => $aliases) {
                foreach ($aliases as $a) {
                    $lookup[self::key('联系人' . $n . $a)] = 'contact' . $n . '_' . $field;
                    $lookup[self::key('contact' . $n . '_' . $field)] = 'contact' . $n . '_' . $field;
                }
            }
        }
        $lookup[self::key('主联系人')] = 'contact1_name';
        $lookup[self::key('主联系人邮箱')] = 'contact1_email';
        $lookup[self::key('主联系人电话')] = 'contact1_phone';
        $map = [];
        $unknown = [];
        foreach ($header as $i => $h) {
            $k = self::key($h);
            if ($k === '') {
                continue;
            }
            if (isset($lookup[$k])) {
                $map[$i] = $lookup[$k];
            } else {
                $unknown[] = $h;
            }
        }
        return [$map, $unknown];
    }

    private static function key(string $s): string
    {
        return (string) preg_replace('/[\s_\-\/（）()]+/u', '', mb_strtolower(trim($s), 'UTF-8'));
    }

    /**
     * 把一行整理成新建客户用的资料。
     *
     * @param array<string, string> $raw
     * @param list<string> $errors
     * @param list<string> $warnings
     * @param array<string, int> $groups 小写分组名 => 编号
     * @return array<string, mixed>
     */
    private function normalize(array $raw, array &$errors, array &$warnings, bool $canAssign, array $groups): array
    {
        $g = static function (string $k) use ($raw): string {
            return $raw[$k] ?? '';
        };
        $data = [];
        foreach (['customer_name', 'customer_name_en', 'customer_code', 'city', 'address', 'phone', 'whatsapp', 'wechat', 'remark', 'email', 'website'] as $k) {
            $data[$k] = $g($k);
        }
        if ($data['customer_name'] === '') {
            $errors[] = '缺少客户名称';
        }
        $country = $g('country');
        if ($country === '') {
            $errors[] = '缺少国家';
        } elseif (Country::options() && !isset(Country::options()[Country::normalize($country)])) {
            $errors[] = '国家“' . $country . '”不认识';
        }
        $data['country'] = $country === '' ? '' : Country::normalize($country);
        if ($data['email'] !== '' && !filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            $errors[] = '邮箱格式不正确';
        }
        if ($data['website'] !== '') {
            $site = preg_match('#^https?://#i', $data['website']) ? $data['website'] : 'https://' . $data['website'];
            if (!filter_var($site, FILTER_VALIDATE_URL) || strpos((string) parse_url($site, PHP_URL_HOST), '.') === false) {
                $errors[] = '网站格式不正确';
            }
        }
        foreach (['level' => ['customer_level', '等级'], 'lifecycle_key' => ['customer_lifecycle', '阶段']] as $field => [$dict, $label]) {
            $v = $g($field);
            $data[$field] = '';
            if ($v !== '') {
                $key = self::dictKey($dict, $v);
                if ($key === '') {
                    $warnings[] = $label . '“' . $v . '”不认识，用默认值';
                } else {
                    $data[$field] = $key;
                }
            }
        }
        foreach (['source_tags' => ['customer_source', '来源'], 'promotion_channels' => ['promotion_channel', '推广方式']] as $field => [$dict, $label]) {
            $v = $g($field);
            if ($v === '') {
                continue; // 不提交 → 用默认值
            }
            $keys = [];
            foreach (preg_split('/[,，、;；\/]+/u', $v, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $part) {
                $key = self::dictKey($dict, trim($part));
                if ($key === '') {
                    $warnings[] = $label . '“' . trim($part) . '”不认识，已忽略';
                } else {
                    $keys[] = $key;
                }
            }
            $data[$field] = array_values(array_unique($keys));
        }
        if ($g('group_names') !== '') {
            $ids = [];
            foreach (preg_split('/[,，、;；]+/u', $g('group_names'), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $part) {
                $k = mb_strtolower(trim($part), 'UTF-8');
                if (isset($groups[$k])) {
                    $ids[] = $groups[$k];
                } else {
                    $warnings[] = '分组“' . trim($part) . '”不存在，已忽略';
                }
            }
            $data['group_ids'] = $ids;
        }
        $owner = 0;
        $ownerText = $g('owner_user_id') !== '' ? $g('owner_user_id') : $g('owner');
        if ($ownerText !== '') {
            if (!$canAssign) {
                $warnings[] = '没有分配权限，负责人设为你自己';
            } else {
                foreach (UserDirectory::active() as $u) {
                    if ((string) $u['id'] === $ownerText || mb_strtolower($u['username'], 'UTF-8') === mb_strtolower($ownerText, 'UTF-8') || $u['name'] === $ownerText) {
                        $owner = $u['id'];
                        break;
                    }
                }
                if ($owner === 0) {
                    $warnings[] = '负责人“' . $ownerText . '”找不到，设为你自己';
                }
            }
        }
        $data['owner_user_id'] = $owner ?: (int) $this->operator['id'];
        $contacts = [];
        $json = json_decode($g('contacts_json'), true);
        foreach (is_array($json) ? $json : [] as $c) {
            if (is_array($c)) {
                $contacts[] = $c;
            }
        }
        for ($n = 1; $n <= 3; $n++) {
            $c = [];
            foreach (array_keys(self::CONTACT_FIELDS) as $f) {
                $c[$f] = $g('contact' . $n . '_' . $f);
            }
            if (implode('', $c) !== '') {
                $contacts[] = $c;
            }
        }
        $clean = [];
        foreach ($contacts as $i => $c) {
            try {
                $clean[] = ContactService::check($c);
            } catch (ValidationException $e) {
                $errors[] = '联系人' . ($i + 1) . '：' . implode('；', $e->fieldErrors());
            }
        }
        $data['contacts'] = $clean;
        return $data;
    }

    /** 字典值：按键、中文名、英文名都能认。 */
    private static function dictKey(string $dict, string $value): string
    {
        $v = mb_strtolower(trim($value), 'UTF-8');
        foreach (Dictionary::items($dict) as $item) {
            if (mb_strtolower($item['key'], 'UTF-8') === $v || mb_strtolower($item['label'], 'UTF-8') === $v || ($item['label_en'] !== '' && mb_strtolower($item['label_en'], 'UTF-8') === $v)) {
                return $item['key'];
            }
        }
        return '';
    }

    private function save(string $token, array $data): void
    {
        $data['user_id'] = (int) $this->operator['id'];
        if (file_put_contents(self::path($token), (string) json_encode($data, JSON_UNESCAPED_UNICODE)) === false) {
            throw new RuntimeException('服务器临时目录不可写，无法保存导入预览');
        }
        foreach ((array) glob(sys_get_temp_dir() . '/artdon_centers_import_*.json') as $old) {
            if (is_string($old) && is_file($old) && filemtime($old) < time() - self::TTL) {
                @unlink($old);
            }
        }
    }

    private static function path(string $token): string
    {
        return sys_get_temp_dir() . '/artdon_centers_import_' . $token . '.json';
    }
}
