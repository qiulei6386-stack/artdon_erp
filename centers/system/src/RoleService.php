<?php
declare(strict_types=1);

namespace Artdon\Centers\System;

use Artdon\Centers\Base\Audit;
use Artdon\Centers\Base\Db;
use Artdon\Centers\Base\NotFoundException;
use Artdon\Centers\Base\Permission;
use Artdon\Centers\Base\PermissionException;
use Artdon\Centers\Base\ValidationException;
use Artdon\Centers\Base\Validator;
use PDO;
use RuntimeException;
use Throwable;

/**
 * 角色与角色权限。读写 crm_roles、crm_role_permissions，权限清单来自 crm_permissions。
 *
 * 权限：查看 users.view；新建、编辑、停用、启用角色 users.manage_roles；
 *       修改角色权限 dangerous.permission_admin。
 * 保护规则：
 *   - 角色编码 super_admin 的角色只有超级管理员能改；
 *   - 不是超级管理员的人，只能给角色加上自己也有的权限（防止给自己所在角色“加权”越级）；
 *   - 角色下还有账号时不能停用。
 */
final class RoleService
{
    public const RISK_LABELS = ['low' => '低', 'medium' => '中', 'high' => '高', 'dangerous' => '高危'];

    /** 模块显示名。没列出的模块直接显示编码。 */
    public const MODULE_LABELS = [
        'dashboard' => '工作台', 'customer' => '客户', 'contact' => '联系人', 'follow' => '跟进', 'visit' => '拜访',
        'opportunity' => '商机', 'mail' => '邮箱', 'promotion' => '推广', 'task' => '任务', 'sample' => '样品',
        'quote' => '报价', 'order' => '订单', 'dispatch' => '派工', 'bom' => 'BOM', 'plm' => 'PLM', 'naming' => '命名',
        'datasheet' => '资料', 'material_center' => '物料中心', 'ai' => 'AI', 'radar' => '雷达', 'crm' => 'CRM',
        'online' => '在线状态', 'users' => '账号', 'permission_request' => '权限申请', 'dangerous' => '高危操作',
        'field' => '字段', 'import' => '导入', 'export' => '导出', 'logs' => '日志', 'settings' => '设置',
        'notifications' => '通知', 'linkage' => '联动', 'finance' => '财务', 'commission' => '提成',
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

    // ---------------------------------------------------------------- 角色

    /** 全部角色，带账号数和权限数。 @return list<array<string, mixed>> */
    public function list(): array
    {
        return $this->pdo->query(
            'SELECT r.id, r.role_key, r.role_name, r.description, r.is_system, r.status,'
            . ' (SELECT COUNT(*) FROM crm_users u WHERE u.role_id = r.id) AS user_count,'
            . ' (SELECT COUNT(*) FROM crm_role_permissions rp WHERE rp.role_id = r.id) AS permission_count'
            . ' FROM crm_roles r'
            . " ORDER BY CASE WHEN r.status = 'active' THEN 0 ELSE 1 END, r.id ASC"
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM crm_roles WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function create(array $input): int
    {
        $this->require('users.manage_roles');
        $v = new Validator($input);
        $key = $v->text('role_key', '角色编码', ['required' => true, 'max' => 80, 'pattern' => '/^[a-z][a-z0-9_]{1,79}$/', 'pattern_message' => '编码只能用小写字母、数字、下划线，以字母开头']);
        $name = $v->text('role_name', '角色名称', ['required' => true, 'max' => 120]);
        $desc = $v->text('description', '说明', ['max' => 255]);
        if ($key !== '' && !isset($v->errors()['role_key'])) {
            $stmt = $this->pdo->prepare('SELECT id FROM crm_roles WHERE role_key = ? LIMIT 1');
            $stmt->execute([$key]);
            if ($stmt->fetchColumn()) {
                $v->fail('role_key', '编码已被使用');
            }
        }
        $this->checkName($v, $name, 0);
        $v->check();
        $now = Db::now();
        $this->pdo->prepare("INSERT INTO crm_roles (role_key, role_name, description, is_system, status, created_at, updated_at) VALUES (?, ?, ?, 0, 'active', ?, ?)")
            ->execute([$key, $name, $desc, $now, $now]);
        $id = (int) $this->pdo->lastInsertId();
        Audit::log('role_create', 'users', 'role', $id, null, ['role_key' => $key, 'role_name' => $name, 'description' => $desc], $this->operator);
        return $id;
    }

    /** 编辑名称和说明（编码不能改）。 */
    public function update(int $id, array $input): array
    {
        $this->require('users.manage_roles');
        $before = $this->editable($id);
        $v = new Validator($input);
        $name = $v->text('role_name', '角色名称', ['required' => true, 'max' => 120]);
        $desc = $v->text('description', '说明', ['max' => 255]);
        $this->checkName($v, $name, $id);
        $v->check();
        $this->pdo->prepare('UPDATE crm_roles SET role_name = ?, description = ?, updated_at = ? WHERE id = ?')->execute([$name, $desc, Db::now(), $id]);
        $after = $this->find($id) ?? [];
        Audit::log('role_update', 'users', 'role', $id, ['role_name' => $before['role_name'], 'description' => $before['description']], ['role_name' => $name, 'description' => $desc], $this->operator);
        return $after;
    }

    public function disable(int $id): array
    {
        $this->require('users.manage_roles');
        $before = $this->editable($id);
        if ((string) $before['status'] !== 'active') {
            throw new RuntimeException('角色已经是停用状态');
        }
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM crm_users WHERE role_id = ?');
        $stmt->execute([$id]);
        $users = (int) $stmt->fetchColumn();
        if ($users > 0) {
            throw new RuntimeException('还有 ' . $users . ' 个账号使用这个角色，请先改为其他角色再停用');
        }
        $this->pdo->prepare("UPDATE crm_roles SET status = 'disabled', updated_at = ? WHERE id = ?")->execute([Db::now(), $id]);
        Audit::log('role_disable', 'users', 'role', $id, ['status' => 'active'], ['status' => 'disabled'], $this->operator);
        return $this->find($id) ?? [];
    }

    public function enable(int $id): array
    {
        $this->require('users.manage_roles');
        $before = $this->editable($id);
        $this->pdo->prepare("UPDATE crm_roles SET status = 'active', updated_at = ? WHERE id = ?")->execute([Db::now(), $id]);
        Audit::log('role_enable', 'users', 'role', $id, ['status' => $before['status']], ['status' => 'active'], $this->operator);
        return $this->find($id) ?? [];
    }

    // ---------------------------------------------------------------- 角色权限

    /**
     * 权限清单，按模块分组。
     *
     * @return list<array{module: string, label: string, permissions: list<array<string, string>>}>
     */
    public function catalog(): array
    {
        $rows = $this->pdo->query('SELECT permission_key, module, action, description, risk_level FROM crm_permissions ORDER BY module ASC, permission_key ASC')->fetchAll(PDO::FETCH_ASSOC);
        $groups = [];
        foreach ($rows as $row) {
            $module = (string) $row['module'];
            if (!isset($groups[$module])) {
                $groups[$module] = ['module' => $module, 'label' => self::MODULE_LABELS[$module] ?? $module, 'permissions' => []];
            }
            $groups[$module]['permissions'][] = [
                'key' => (string) $row['permission_key'],
                'label' => trim((string) $row['description']) !== '' ? (string) $row['description'] : (string) $row['action'],
                'risk' => (string) $row['risk_level'],
            ];
        }
        // 常用模块排在前面，其他按编码
        $order = array_flip(array_keys(self::MODULE_LABELS));
        uasort($groups, static function (array $a, array $b) use ($order): int {
            return [$order[$a['module']] ?? 999, $a['module']] <=> [$order[$b['module']] ?? 999, $b['module']];
        });
        return array_values($groups);
    }

    /** 角色已有的权限编码。 @return list<string> */
    public function permissionsOf(int $roleId): array
    {
        $stmt = $this->pdo->prepare('SELECT permission_key FROM crm_role_permissions WHERE role_id = ? ORDER BY permission_key');
        $stmt->execute([$roleId]);
        return array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /** 当前操作人能否修改角色权限。 */
    public function canEditPermissions(): bool
    {
        return Permission::allows($this->operator, 'dangerous.permission_admin');
    }

    /** 当前操作人能授予的权限（超级管理员：全部；其他人：自己有的）。 */
    public function grantable(string $permission): bool
    {
        return (int) ($this->operator['is_super_admin'] ?? 0) === 1 || Permission::allows($this->operator, $permission);
    }

    /**
     * 保存角色权限：以提交的勾选结果为准，增加和去掉的都会记日志。
     *
     * @param list<string> $keys
     * @return array{added: list<string>, removed: list<string>}
     */
    public function savePermissions(int $roleId, array $keys): array
    {
        $this->require('dangerous.permission_admin');
        $this->editable($roleId);
        $valid = array_flip(array_map('strval', $this->pdo->query('SELECT permission_key FROM crm_permissions')->fetchAll(PDO::FETCH_COLUMN)));
        $wanted = [];
        foreach ($keys as $key) {
            $key = (string) $key;
            if (!isset($valid[$key])) {
                throw new ValidationException(['permissions' => '权限不存在：' . $key]);
            }
            $wanted[$key] = true;
        }
        $current = array_flip($this->permissionsOf($roleId));
        $added = array_values(array_diff(array_keys($wanted), array_keys($current)));
        $removed = array_values(array_diff(array_keys($current), array_keys($wanted)));
        $denied = array_values(array_filter($added, function (string $key): bool {
            return !$this->grantable($key);
        }));
        if ($denied) {
            throw new PermissionException('不能授予自己没有的权限：' . implode('、', array_slice($denied, 0, 5)) . (count($denied) > 5 ? ' 等 ' . count($denied) . ' 项' : ''));
        }
        if (!$added && !$removed) {
            return ['added' => [], 'removed' => []];
        }
        $this->pdo->beginTransaction();
        try {
            $insert = $this->pdo->prepare('INSERT INTO crm_role_permissions (role_id, permission_key, created_at) VALUES (?, ?, ?)');
            foreach ($added as $key) {
                $insert->execute([$roleId, $key, Db::now()]);
            }
            $delete = $this->pdo->prepare('DELETE FROM crm_role_permissions WHERE role_id = ? AND permission_key = ?');
            foreach ($removed as $key) {
                $delete->execute([$roleId, $key]);
            }
            $this->pdo->commit();
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
        Permission::clearCache();
        Audit::log('role_permissions_update', 'users', 'role', $roleId, ['removed' => $removed], ['added' => $added], $this->operator);
        return ['added' => $added, 'removed' => $removed];
    }

    // ---------------------------------------------------------------- 内部

    private function editable(int $id): array
    {
        $role = $this->find($id);
        if (!$role) {
            throw new NotFoundException('角色不存在');
        }
        if ((string) $role['role_key'] === 'super_admin' && (int) ($this->operator['is_super_admin'] ?? 0) !== 1) {
            throw new PermissionException('只有超级管理员可以修改“超级管理员”角色');
        }
        return $role;
    }

    private function checkName(Validator $v, string $name, int $exceptId): void
    {
        if ($name === '') {
            return;
        }
        $stmt = $this->pdo->prepare('SELECT id FROM crm_roles WHERE role_name = ? AND id <> ? LIMIT 1');
        $stmt->execute([$name, $exceptId]);
        if ($stmt->fetchColumn()) {
            $v->fail('role_name', '已有同名角色');
        }
    }

    private function require(string $permission): void
    {
        if (!Permission::allows($this->operator, $permission)) {
            throw new PermissionException('没有权限（' . $permission . '）');
        }
    }
}
