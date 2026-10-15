<?php
declare(strict_types=1);

namespace Artdon\Centers\System;

use Artdon\Centers\Base\Audit;
use Artdon\Centers\Base\Db;
use Artdon\Centers\Base\NotFoundException;
use Artdon\Centers\Base\Permission;
use Artdon\Centers\Base\PermissionException;
use Artdon\Centers\Base\ValidationException;
use PDO;
use RuntimeException;
use Throwable;

/**
 * 人员权限：查看某个人最终有哪些权限及来源，给个人单独允许或拒绝某项权限，设置数据范围。
 * 读写 crm_user_permissions、crm_user_scopes；读取 crm_role_permissions、crm_permission_grants。
 *
 * 权限：查看 users.view；修改个人权限和数据范围 users.assign_permission。
 * 保护规则：
 *   - 不能修改自己的个人权限和数据范围（防止给自己加权）；
 *   - 不是超级管理员的人不能修改超级管理员账号，也不能给别人“允许”自己没有的权限（“拒绝”不受限）。
 */
final class PeopleService
{
    public const SCOPE_TYPES = [
        'self' => '只看本人的',
        'assigned' => '本人的和分配给我的',
        'team' => '本团队',
        'department' => '本部门',
        'country' => '指定国家',
        'customer_group' => '指定客户分组',
        'all' => '全部',
        'none' => '都不能看',
    ];

    /** 可设置数据范围的模块。 */
    public const SCOPE_MODULES = [
        'customer' => '客户',
        'contact' => '联系人',
        'follow' => '跟进',
        'visit' => '拜访',
        'opportunity' => '商机',
        'mail' => '邮箱',
        'promotion' => '推广',
        'task' => '任务',
        'quote' => '报价',
        'order' => '订单',
        'logs' => '日志',
    ];

    /** @var PDO */
    private $pdo;

    /** @var array<string, mixed> */
    private $operator;

    public function __construct(array $operator, ?PDO $pdo = null)
    {
        $this->operator = $operator;
        $this->pdo = $pdo ?? Db::pdo();
    }

    /** 账号列表（用于左侧选择），带个人权限设置数。 @return list<array<string, mixed>> */
    public function users(string $q = ''): array
    {
        $where = '';
        $params = [];
        $q = trim($q);
        if ($q !== '') {
            $like = '%' . addcslashes($q, '\\%_') . '%';
            $where = ' WHERE (u.username LIKE ? OR u.real_name LIKE ? OR u.email LIKE ?)';
            $params = [$like, $like, $like];
        }
        $stmt = $this->pdo->prepare(
            'SELECT u.id, u.username, u.real_name, u.status, u.is_super_admin, r.role_name, d.name AS department_name,'
            . ' (SELECT COUNT(*) FROM crm_user_permissions up WHERE up.user_id = u.id) AS override_count'
            . ' FROM crm_users u LEFT JOIN crm_roles r ON r.id = u.role_id LEFT JOIN crm_departments d ON d.id = u.department_id'
            . $where
            . " ORDER BY CASE WHEN u.status = 'active' THEN 0 ELSE 1 END, u.real_name ASC, u.id ASC LIMIT 300"
        );
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function user(int $id): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT u.id, u.username, u.real_name, u.status, u.is_super_admin, u.role_id, u.department_id, r.role_name, d.name AS department_name'
            . ' FROM crm_users u LEFT JOIN crm_roles r ON r.id = u.role_id LEFT JOIN crm_departments d ON d.id = u.department_id WHERE u.id = ? LIMIT 1'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * 某人的权限明细：按模块分组，每项给出角色是否有、个人设置、有效临时授权、最终结果和来源。
     *
     * @return list<array{module: string, label: string, permissions: list<array<string, mixed>>}>
     */
    public function effective(int $userId): array
    {
        $user = $this->user($userId);
        if (!$user) {
            throw new NotFoundException('账号不存在');
        }
        $super = (int) $user['is_super_admin'] === 1;
        $role = [];
        if ((int) $user['role_id'] > 0) {
            $stmt = $this->pdo->prepare('SELECT permission_key FROM crm_role_permissions WHERE role_id = ?');
            $stmt->execute([(int) $user['role_id']]);
            $role = array_flip(array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN)));
        }
        $overrides = $this->overrides($userId);
        $grants = [];
        foreach ($this->activeGrants($userId) as $g) {
            $grants[(string) $g['permission_key']] = (string) ($g['expires_at'] ?? '');
        }

        $groups = (new RoleService($this->operator, $this->pdo))->catalog();
        foreach ($groups as &$group) {
            foreach ($group['permissions'] as &$p) {
                $key = $p['key'];
                $override = $overrides[$key] ?? '';
                $hasRole = isset($role[$key]);
                $hasGrant = array_key_exists($key, $grants);
                if ($super) {
                    [$on, $source] = [true, '超级管理员'];
                } elseif ($override === 'deny') {
                    [$on, $source] = [false, '个人拒绝'];
                } elseif ($hasGrant) {
                    [$on, $source] = [true, '临时授权'];
                } elseif ($override === 'allow') {
                    [$on, $source] = [true, '个人允许'];
                } elseif ($hasRole) {
                    [$on, $source] = [true, '角色'];
                } else {
                    [$on, $source] = [false, ''];
                }
                $p += ['role' => $hasRole, 'override' => $override, 'grant' => $hasGrant, 'grant_expires' => $grants[$key] ?? '', 'on' => $on, 'source' => $source];
            }
            unset($p);
        }
        unset($group);
        return $groups;
    }

    /** 个人设置。 @return array<string, string> 权限编码 => allow|deny */
    public function overrides(int $userId): array
    {
        $stmt = $this->pdo->prepare('SELECT permission_key, effect FROM crm_user_permissions WHERE user_id = ?');
        $stmt->execute([$userId]);
        $map = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $map[(string) $row['permission_key']] = (string) $row['effect'];
        }
        return $map;
    }

    /** 有效的临时授权。 @return list<array<string, mixed>> */
    public function activeGrants(int $userId): array
    {
        if (!Db::tableExists('crm_permission_grants')) {
            return [];
        }
        $now = Db::now();
        $stmt = $this->pdo->prepare("SELECT id, permission_key, expires_at, granted_by_name, starts_at FROM crm_permission_grants WHERE user_id = ? AND effect = 'allow' AND status = 'active' AND starts_at <= ? AND (expires_at IS NULL OR expires_at > ?) ORDER BY expires_at IS NULL, expires_at ASC");
        $stmt->execute([$userId, $now, $now]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** 当前操作人能否修改这个人的权限；不能时返回原因，能时返回空字符串。 */
    public function editBlockedReason(array $target): string
    {
        if (!Permission::allows($this->operator, 'users.assign_permission')) {
            return '只读：修改个人权限需要“分配用户权限”（users.assign_permission）';
        }
        if ((int) $target['id'] === (int) ($this->operator['id'] ?? 0)) {
            return '不能修改自己的个人权限和数据范围';
        }
        if ((int) $target['is_super_admin'] === 1) {
            return '超级管理员拥有全部权限，不需要单独设置';
        }
        return '';
    }

    /** 当前操作人能否给别人“允许”这项权限。 */
    public function canAllow(string $permission): bool
    {
        return (int) ($this->operator['is_super_admin'] ?? 0) === 1 || Permission::allows($this->operator, $permission);
    }

    /**
     * 保存个人设置。$choices：权限编码 => inherit（跟随角色）| allow | deny。没提交的权限不变。
     *
     * @param array<string, string> $choices
     * @return array{changed: int}
     */
    public function saveOverrides(int $userId, array $choices): array
    {
        $target = $this->editable($userId);
        $valid = array_flip(array_map('strval', $this->pdo->query('SELECT permission_key FROM crm_permissions')->fetchAll(PDO::FETCH_COLUMN)));
        $current = $this->overrides($userId);
        $changes = [];
        foreach ($choices as $key => $choice) {
            $key = (string) $key;
            $choice = (string) $choice;
            if (!isset($valid[$key])) {
                throw new ValidationException(['permissions' => '权限不存在：' . $key]);
            }
            if (!in_array($choice, ['inherit', 'allow', 'deny'], true)) {
                throw new ValidationException(['permissions' => '设置值不正确：' . $key]);
            }
            $before = $current[$key] ?? 'inherit';
            if ($before === $choice) {
                continue;
            }
            if ($choice === 'allow' && !$this->canAllow($key)) {
                throw new PermissionException('不能给别人允许自己没有的权限：' . $key);
            }
            $changes[$key] = [$before, $choice];
        }
        if (!$changes) {
            return ['changed' => 0];
        }
        $this->pdo->beginTransaction();
        try {
            $delete = $this->pdo->prepare('DELETE FROM crm_user_permissions WHERE user_id = ? AND permission_key = ?');
            $insert = $this->pdo->prepare('INSERT INTO crm_user_permissions (user_id, permission_key, effect, created_at) VALUES (?, ?, ?, ?)');
            foreach ($changes as $key => [$before, $after]) {
                $delete->execute([$userId, $key]);
                if ($after !== 'inherit') {
                    $insert->execute([$userId, $key, $after, Db::now()]);
                }
            }
            $this->pdo->commit();
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
        Permission::clearCache();
        $before = [];
        $after = [];
        foreach ($changes as $key => [$b, $a]) {
            $before[$key] = $b;
            $after[$key] = $a;
        }
        Audit::log('user_permissions_update', 'users', 'user', $userId, $before, $after, $this->operator);
        return ['changed' => count($changes)];
    }

    /** 数据范围设置。 @return array<string, array{type: string, value: string}> */
    public function scopes(int $userId): array
    {
        $stmt = $this->pdo->prepare('SELECT module, scope_type, scope_value FROM crm_user_scopes WHERE user_id = ?');
        $stmt->execute([$userId]);
        $map = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $map[(string) $row['module']] = ['type' => (string) $row['scope_type'], 'value' => (string) ($row['scope_value'] ?? '')];
        }
        return $map;
    }

    /** 要显示的数据范围模块：固定列表 + 这个人已有设置的其他模块。 @return array<string, string> */
    public function scopeModules(int $userId): array
    {
        $modules = self::SCOPE_MODULES;
        foreach (array_keys($this->scopes($userId)) as $module) {
            if (!isset($modules[$module])) {
                $modules[$module] = $module;
            }
        }
        return $modules;
    }

    /**
     * 保存数据范围。$input：模块 => [type, value]。设为“只看本人的”时删除记录（没有记录即为只看本人）。
     *
     * @param array<string, array{type?: string, value?: string}> $input
     */
    public function saveScopes(int $userId, array $input): array
    {
        $this->editable($userId);
        $allowed = $this->scopeModules($userId);
        $current = $this->scopes($userId);
        $errors = [];
        $changes = [];
        foreach ($input as $module => $item) {
            $module = (string) $module;
            if (!isset($allowed[$module])) {
                throw new ValidationException(['scope' => '不能设置这个模块：' . $module]);
            }
            $type = (string) ($item['type'] ?? 'self');
            $value = trim((string) ($item['value'] ?? ''));
            if (!isset(self::SCOPE_TYPES[$type])) {
                $errors['scope[' . $module . '][type]'] = '范围不正确';
                continue;
            }
            if (in_array($type, ['country', 'customer_group'], true)) {
                if ($value === '') {
                    $errors['scope[' . $module . '][value]'] = '请填写' . ($type === 'country' ? '国家' : '客户分组') . '，多个用逗号分隔';
                    continue;
                }
                $value = mb_substr($value, 0, 255, 'UTF-8');
            } else {
                $value = '';
            }
            $before = $current[$module] ?? ['type' => 'self', 'value' => ''];
            if ($before['type'] !== $type || $before['value'] !== $value) {
                $changes[$module] = [$before, ['type' => $type, 'value' => $value]];
            }
        }
        if ($errors) {
            throw new ValidationException($errors);
        }
        if (!$changes) {
            return ['changed' => 0];
        }
        $delete = $this->pdo->prepare('DELETE FROM crm_user_scopes WHERE user_id = ? AND module = ?');
        $insert = $this->pdo->prepare('INSERT INTO crm_user_scopes (user_id, module, scope_type, scope_value, created_at) VALUES (?, ?, ?, ?, ?)');
        foreach ($changes as $module => [$before, $after]) {
            $delete->execute([$userId, $module]);
            if ($after['type'] !== 'self') {
                $insert->execute([$userId, $module, $after['type'], $after['value'] !== '' ? $after['value'] : null, Db::now()]);
            }
        }
        Audit::log('user_scopes_update', 'users', 'user', $userId, array_map(static function (array $c): array {
            return $c[0];
        }, $changes), array_map(static function (array $c): array {
            return $c[1];
        }, $changes), $this->operator);
        return ['changed' => count($changes)];
    }

    private function editable(int $userId): array
    {
        $target = $this->user($userId);
        if (!$target) {
            throw new NotFoundException('账号不存在');
        }
        $reason = $this->editBlockedReason($target);
        if ($reason !== '') {
            if (strpos($reason, '只读') === 0) {
                throw new PermissionException($reason);
            }
            throw new RuntimeException($reason);
        }
        return $target;
    }
}
