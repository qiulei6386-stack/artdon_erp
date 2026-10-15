<?php
declare(strict_types=1);

namespace Artdon\Centers\System;

use Artdon\Centers\Base\Audit;
use Artdon\Centers\Base\Db;
use Artdon\Centers\Base\NotFoundException;
use Artdon\Centers\Base\Permission;
use Artdon\Centers\Base\PermissionException;
use Artdon\Centers\Base\Validator;
use PDO;
use RuntimeException;

/**
 * 部门管理：列表、新建、编辑、停用、启用。读写 crm_departments。
 * 查看需要 users.view；新建、编辑、停用、启用需要 users.manage_departments。
 * 部门下还有账号时不能停用，避免账号挂在停用的部门上。
 */
final class DepartmentService
{
    /** @var PDO */
    private $pdo;

    /** @var array<string, mixed> */
    private $operator;

    public function __construct(array $operator, ?PDO $pdo = null)
    {
        $this->operator = $operator;
        $this->pdo = $pdo ?? Db::pdo();
    }

    /** 全部部门（含停用），带账号数和负责人姓名。 @return list<array<string, mixed>> */
    public function list(): array
    {
        return $this->pdo->query(
            'SELECT d.id, d.name, d.code, d.manager_user_id, d.status, d.sort_order, m.real_name AS manager_name,'
            . ' (SELECT COUNT(*) FROM crm_users u WHERE u.department_id = d.id) AS user_count'
            . ' FROM crm_departments d LEFT JOIN crm_users m ON m.id = d.manager_user_id'
            . " ORDER BY CASE WHEN d.status = 'active' THEN 0 ELSE 1 END, d.sort_order ASC, d.id ASC"
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    /** 可以设为负责人的账号（正常状态）。 @return array<int, string> */
    public function managerOptions(): array
    {
        $list = [];
        foreach ($this->pdo->query("SELECT id, real_name, username FROM crm_users WHERE status = 'active' ORDER BY real_name ASC, id ASC")->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $list[(int) $row['id']] = $row['real_name'] . '（' . $row['username'] . '）';
        }
        return $list;
    }

    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM crm_departments WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function create(array $input): int
    {
        $this->requireManage();
        $v = new Validator($input);
        $name = $v->text('name', '部门名称', ['required' => true, 'max' => 100]);
        $code = $v->text('code', '部门编码', ['max' => 60, 'pattern' => '/^[a-z][a-z0-9_]{1,59}$/', 'pattern_message' => '编码只能用小写字母、数字、下划线，以字母开头']);
        $sort = $v->id('sort_order', '排序');
        $manager = $v->id('manager_user_id', '负责人');
        $this->checkName($v, $name, 0);
        if ($code !== '' && $this->exists('code', $code, 0)) {
            $v->fail('code', '编码已被其他部门使用');
        }
        $this->checkManager($v, $manager);
        $v->check();
        if ($code === '') {
            $code = $this->generateCode();
        }
        $now = Db::now();
        $this->pdo->prepare("INSERT INTO crm_departments (name, code, manager_user_id, status, sort_order, created_at, updated_at) VALUES (?, ?, ?, 'active', ?, ?, ?)")
            ->execute([$name, $code, $manager ?: null, $sort, $now, $now]);
        $id = (int) $this->pdo->lastInsertId();
        Audit::log('department_create', 'users', 'department', $id, null, ['name' => $name, 'code' => $code, 'sort_order' => $sort, 'manager_user_id' => $manager ?: null], $this->operator);
        return $id;
    }

    /** 编辑名称、排序、负责人（编码不能改，其他数据可能按编码识别部门）。 */
    public function update(int $id, array $input): array
    {
        $this->requireManage();
        $before = $this->find($id);
        if (!$before) {
            throw new NotFoundException('部门不存在');
        }
        $v = new Validator($input);
        $name = $v->text('name', '部门名称', ['required' => true, 'max' => 100]);
        $sort = $v->id('sort_order', '排序');
        $manager = $v->id('manager_user_id', '负责人');
        $this->checkName($v, $name, $id);
        if ($manager !== (int) ($before['manager_user_id'] ?? 0)) {
            $this->checkManager($v, $manager);
        }
        $v->check();
        $this->pdo->prepare('UPDATE crm_departments SET name = ?, sort_order = ?, manager_user_id = ?, updated_at = ? WHERE id = ?')
            ->execute([$name, $sort, $manager ?: null, Db::now(), $id]);
        $after = $this->find($id) ?? [];
        Audit::log('department_update', 'users', 'department', $id, self::view($before), self::view($after), $this->operator);
        return $after;
    }

    public function disable(int $id): array
    {
        $this->requireManage();
        $before = $this->find($id);
        if (!$before) {
            throw new NotFoundException('部门不存在');
        }
        if ((string) $before['status'] !== 'active') {
            throw new RuntimeException('部门已经是停用状态');
        }
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM crm_users WHERE department_id = ?');
        $stmt->execute([$id]);
        $users = (int) $stmt->fetchColumn();
        if ($users > 0) {
            throw new RuntimeException('部门下还有 ' . $users . ' 个账号，请先把他们调到其他部门再停用');
        }
        $this->pdo->prepare("UPDATE crm_departments SET status = 'disabled', updated_at = ? WHERE id = ?")->execute([Db::now(), $id]);
        Audit::log('department_disable', 'users', 'department', $id, ['status' => 'active'], ['status' => 'disabled'], $this->operator);
        return $this->find($id) ?? [];
    }

    public function enable(int $id): array
    {
        $this->requireManage();
        $before = $this->find($id);
        if (!$before) {
            throw new NotFoundException('部门不存在');
        }
        $v = new Validator([]);
        $this->checkName($v, (string) $before['name'], $id);
        if ($v->errors()) {
            throw new RuntimeException('已有同名的启用部门，请先改名再启用');
        }
        $this->pdo->prepare("UPDATE crm_departments SET status = 'active', updated_at = ? WHERE id = ?")->execute([Db::now(), $id]);
        Audit::log('department_enable', 'users', 'department', $id, ['status' => $before['status']], ['status' => 'active'], $this->operator);
        return $this->find($id) ?? [];
    }

    private function checkName(Validator $v, string $name, int $exceptId): void
    {
        if ($name === '') {
            return;
        }
        $stmt = $this->pdo->prepare("SELECT id FROM crm_departments WHERE name = ? AND status = 'active' AND id <> ? LIMIT 1");
        $stmt->execute([$name, $exceptId]);
        if ($stmt->fetchColumn()) {
            $v->fail('name', '已有同名部门');
        }
    }

    private function checkManager(Validator $v, int $userId): void
    {
        if ($userId > 0 && !isset($this->managerOptions()[$userId])) {
            $v->fail('manager_user_id', '请选择正常状态的账号');
        }
    }

    private function exists(string $column, string $value, int $exceptId): bool
    {
        $column = $column === 'code' ? 'code' : 'name';
        $stmt = $this->pdo->prepare("SELECT id FROM crm_departments WHERE {$column} = ? AND id <> ? LIMIT 1");
        $stmt->execute([$value, $exceptId]);
        return (bool) $stmt->fetchColumn();
    }

    private function generateCode(): string
    {
        do {
            $code = 'dept_' . bin2hex(random_bytes(3));
        } while ($this->exists('code', $code, 0));
        return $code;
    }

    private function requireManage(): void
    {
        if (!Permission::allows($this->operator, 'users.manage_departments')) {
            throw new PermissionException('没有管理部门的权限（users.manage_departments）');
        }
    }

    private static function view(array $row): array
    {
        return array_intersect_key($row, array_flip(['name', 'code', 'sort_order', 'manager_user_id', 'status']));
    }
}
