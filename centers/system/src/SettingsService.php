<?php
declare(strict_types=1);

namespace Artdon\Centers\System;

use Artdon\Centers\Base\Audit;
use Artdon\Centers\Base\Db;
use Artdon\Centers\Base\Permission;
use Artdon\Centers\Base\PermissionException;
use Artdon\Centers\Base\SystemSettings;
use Artdon\Centers\Base\ValidationException;
use Artdon\Centers\Base\Validator;
use PDO;

/**
 * 设置中心：公司信息、Logo、安全设置、系统信息。读写 crm_system_settings。
 * 查看 settings.view；保存 settings.edit。
 *
 * 公司信息沿用表里已有的键；Logo 以图片数据存在 centers.logo（不写服务器文件，避免目录权限问题）；
 * 安全设置以 JSON 存在 centers.security。
 */
final class SettingsService
{
    public const COMPANY_FIELDS = [
        'system_name' => ['系统名称', 120, true],
        'company_name' => ['公司中文名', 190, true],
        'company_name_en' => ['公司英文名', 190, false],
        'company_short_name' => ['公司简称', 60, false],
        'company_address' => ['公司地址', 255, false],
        'company_phone' => ['公司电话', 60, false],
        'company_email' => ['公司邮箱', 190, false],
        'company_website' => ['公司网站', 255, false],
    ];

    /** Logo 图片最大字节数（存进设置表的 TEXT 字段，编码后约 1.4 倍）。 */
    public const LOGO_MAX_BYTES = 45000;

    /** @var PDO */
    private $pdo;

    /** @var array<string, mixed> */
    private $operator;

    public function __construct(array $operator, ?PDO $pdo = null)
    {
        $this->operator = $operator;
        $this->pdo = $pdo ?? Db::pdo();
    }

    public function canEdit(): bool
    {
        return Permission::allows($this->operator, 'settings.edit');
    }

    /** @return array<string, string> */
    public function company(): array
    {
        $out = [];
        foreach (array_keys(self::COMPANY_FIELDS) as $key) {
            $out[$key] = SystemSettings::get($key);
        }
        return $out;
    }

    public function saveCompany(array $input): array
    {
        $this->requireEdit();
        $before = $this->company();
        $v = new Validator($input);
        $next = [];
        foreach (self::COMPANY_FIELDS as $key => [$label, $max, $required]) {
            $next[$key] = $key === 'company_email'
                ? $v->email($key, $label)
                : $v->text($key, $label, ['required' => $required, 'max' => $max]);
        }
        if ($next['company_website'] !== '' && !preg_match('#^(https?://)?[a-z0-9.-]+\.[a-z]{2,}(/\S*)?$#i', $next['company_website'])) {
            $v->fail('company_website', '网站地址格式不正确，例如 www.artdon.com');
        }
        $v->check();
        foreach ($next as $key => $value) {
            if ($value !== $before[$key]) {
                $this->put($key, $value, 'company', self::COMPANY_FIELDS[$key][0]);
            }
        }
        SystemSettings::clearCache();
        Audit::log('settings_company_update', 'settings', 'company', 'company_profile', $before, $next, $this->operator);
        return $next;
    }

    /** 保存 Logo。$dataUrl 为浏览器读出的 data:image/png;base64,... */
    public function saveLogo(string $dataUrl): void
    {
        $this->requireEdit();
        if (!preg_match('#^data:image/(png|jpeg|webp);base64,([A-Za-z0-9+/=]+)$#', $dataUrl, $m)) {
            throw new ValidationException(['logo' => '请选择 PNG、JPG 或 WebP 图片']);
        }
        $binary = base64_decode($m[2], true);
        if ($binary === false || $binary === '') {
            throw new ValidationException(['logo' => '图片内容不正确']);
        }
        if (strlen($binary) > self::LOGO_MAX_BYTES) {
            throw new ValidationException(['logo' => '图片太大，请压缩到 ' . (int) (self::LOGO_MAX_BYTES / 1000) . 'KB 以内']);
        }
        $info = @getimagesizefromstring($binary);
        if (!$info || $info[0] < 16 || $info[1] < 16 || $info[0] > 2048 || $info[1] > 2048) {
            throw new ValidationException(['logo' => '图片尺寸应在 16 到 2048 像素之间']);
        }
        $mime = (string) ($info['mime'] ?? '');
        if (!in_array($mime, ['image/png', 'image/jpeg', 'image/webp'], true)) {
            throw new ValidationException(['logo' => '请选择 PNG、JPG 或 WebP 图片']);
        }
        $this->put('centers.logo', 'data:' . $mime . ';base64,' . base64_encode($binary), 'company', '新系统 Logo');
        SystemSettings::clearCache();
        Audit::log('settings_logo_update', 'settings', 'company', 'logo', null, ['mime' => $mime, 'bytes' => strlen($binary), 'width' => $info[0], 'height' => $info[1]], $this->operator);
    }

    public function removeLogo(): void
    {
        $this->requireEdit();
        $this->put('centers.logo', '', 'company', '新系统 Logo');
        SystemSettings::clearCache();
        Audit::log('settings_logo_remove', 'settings', 'company', 'logo', null, null, $this->operator);
    }

    public function saveSecurity(array $input): array
    {
        $this->requireEdit();
        $before = SystemSettings::security();
        $v = new Validator($input);
        $next = [
            'login_max_failures' => $v->id('login_max_failures', '失败次数', true),
            'login_lock_minutes' => $v->id('login_lock_minutes', '锁定分钟', true),
            'password_min_length' => $v->id('password_min_length', '密码最短长度', true),
            'session_idle_minutes' => $v->id('session_idle_minutes', '闲置退出分钟'),
        ];
        if (!isset($v->errors()['login_max_failures']) && ($next['login_max_failures'] < 1 || $next['login_max_failures'] > 20)) {
            $v->fail('login_max_failures', '请填 1 到 20');
        }
        if (!isset($v->errors()['login_lock_minutes']) && ($next['login_lock_minutes'] < 1 || $next['login_lock_minutes'] > 1440)) {
            $v->fail('login_lock_minutes', '请填 1 到 1440');
        }
        if (!isset($v->errors()['password_min_length']) && ($next['password_min_length'] < 8 || $next['password_min_length'] > 32)) {
            $v->fail('password_min_length', '请填 8 到 32');
        }
        if (!isset($v->errors()['session_idle_minutes']) && $next['session_idle_minutes'] !== 0 && ($next['session_idle_minutes'] < 10 || $next['session_idle_minutes'] > 1440)) {
            $v->fail('session_idle_minutes', '请填 0（不自动退出）或 10 到 1440');
        }
        $v->check();
        $this->put('centers.security', (string) json_encode($next), 'security', '新系统安全设置');
        SystemSettings::clearCache();
        Audit::log('settings_security_update', 'settings', 'security', 'security', $before, $next, $this->operator);
        return $next;
    }

    /** @return array<string, string> 名称 => 值 */
    public function systemInfo(): array
    {
        $driver = (string) $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $dbVersion = '';
        $dbName = '';
        try {
            $dbVersion = (string) $this->pdo->getAttribute(PDO::ATTR_SERVER_VERSION);
            if ($driver === 'mysql') {
                $dbName = (string) $this->pdo->query('SELECT DATABASE()')->fetchColumn();
            }
        } catch (\Throwable $e) {
            $dbVersion = '读取失败';
        }
        $count = function (string $sql): string {
            try {
                return (string) $this->pdo->query($sql)->fetchColumn();
            } catch (\Throwable $e) {
                return '—';
            }
        };
        return [
            '程序版本' => self::gitVersion(),
            'PHP 版本' => PHP_VERSION,
            '数据库' => $driver . ' ' . $dbVersion . ($dbName !== '' ? '（' . $dbName . '）' : ''),
            '时区' => date_default_timezone_get() . '，现在 ' . date('Y-m-d H:i:s'),
            '账号' => $count('SELECT COUNT(*) FROM crm_users') . ' 个，其中正常 ' . $count("SELECT COUNT(*) FROM crm_users WHERE status = 'active'") . ' 个，待审核 ' . $count("SELECT COUNT(*) FROM crm_users WHERE status = 'pending'") . ' 个',
            '角色' => $count('SELECT COUNT(*) FROM crm_roles') . ' 个',
            '部门' => $count("SELECT COUNT(*) FROM crm_departments WHERE status = 'active'") . ' 个启用',
            '权限项' => $count('SELECT COUNT(*) FROM crm_permissions') . ' 项',
            '登录会话名' => session_name() ?: '—',
        ];
    }

    /** 当前部署的 Git 提交号（只读 .git 目录里的文件，不执行命令）。 */
    public static function gitVersion(): string
    {
        $git = ARTDON_APP_ROOT . '/.git';
        $head = @file_get_contents($git . '/HEAD');
        if ($head === false) {
            return '未知';
        }
        $head = trim($head);
        if (strpos($head, 'ref: ') !== 0) {
            return substr($head, 0, 7);
        }
        $ref = substr($head, 5);
        $hash = @file_get_contents($git . '/' . $ref);
        if ($hash === false) {
            $packed = (string) @file_get_contents($git . '/packed-refs');
            if (preg_match('/^([0-9a-f]{40}) ' . preg_quote($ref, '/') . '$/m', $packed, $m)) {
                $hash = $m[1];
            }
        }
        return $hash ? substr(trim((string) $hash), 0, 7) . '（' . basename($ref) . '）' : '未知';
    }

    private function put(string $key, string $value, string $group, string $description): void
    {
        $stmt = $this->pdo->prepare('SELECT id FROM crm_system_settings WHERE setting_key = ? LIMIT 1');
        $stmt->execute([$key]);
        $id = $stmt->fetchColumn();
        if ($id) {
            $this->pdo->prepare('UPDATE crm_system_settings SET setting_value = ?, updated_by = ?, updated_at = ? WHERE id = ?')
                ->execute([$value, (int) ($this->operator['id'] ?? 0) ?: null, Db::now(), (int) $id]);
            return;
        }
        $this->pdo->prepare('INSERT INTO crm_system_settings (setting_key, setting_value, setting_group, description, updated_by, updated_at) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([$key, $value, $group, $description, (int) ($this->operator['id'] ?? 0) ?: null, Db::now()]);
    }

    private function requireEdit(): void
    {
        if (!$this->canEdit()) {
            throw new PermissionException('没有修改设置的权限（settings.edit）');
        }
    }
}
