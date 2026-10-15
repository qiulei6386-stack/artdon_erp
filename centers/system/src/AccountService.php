<?php
declare(strict_types=1);

namespace Artdon\Centers\System;

use Artdon\Centers\Base\Audit;
use Artdon\Centers\Base\NotFoundException;
use Artdon\Centers\Base\PermissionException;
use Artdon\Centers\Base\Db;
use Artdon\Centers\Base\Password;
use Artdon\Centers\Base\Permission;
use Artdon\Centers\Base\ValidationException;
use Artdon\Centers\Base\Validator;
use PDO;
use RuntimeException;

/**
 * 账号管理：列表、新建、编辑、审核、拒绝、停用、启用、解锁、重置密码。读写 crm_users。
 *
 * 权限：
 *   查看列表 users.view；新建和编辑基本资料 users.create；改部门 users.assign_department；
 *   改角色 users.assign_role；审核 users.approve；拒绝 users.reject；停用 users.disable；
 *   启用和解锁 users.enable；重置密码 users.reset_password。
 * 保护规则：
 *   不是超级管理员的人不能管理超级管理员账号；不能停用或拒绝自己；页面上不能设置超级管理员。
 * 新建账号只写这个账号本身，不改动任何角色的权限，也不给账号写个人权限，权限统一跟着角色走。
 */
final class AccountService
{
    public const STATUS_LABELS = [
        'pending' => '待审核',
        'active' => '正常',
        'disabled' => '已停用',
        'rejected' => '已拒绝',
        'locked' => '已锁定',
    ];

    public const PER_PAGE = 50;

    /** @var PDO */
    private $pdo;

    /** @var array<string, mixed> 操作人（crm_users 一行） */
    private $operator;

    public function __construct(array $operator, ?PDO $pdo = null)
    {
        $this->operator = $operator;
        $this->pdo = $pdo ?? Db::pdo();
    }

    // ---------------------------------------------------------------- 查询

    /**
     * @param array<string, mixed> $filters status、q、department_id、role_id
     * @return array{rows: list<array<string, mixed>>, total: int, page: int, pages: int}
     */
    public function list(array $filters, int $page = 1): array
    {
        [$where, $params] = $this->filterSql($filters);
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM crm_users u' . $where);
        $stmt->execute($params);
        $total = (int) $stmt->fetchColumn();
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $page = max(1, min($page, $pages));
        $offset = ($page - 1) * self::PER_PAGE;
        $stmt = $this->pdo->prepare(
            'SELECT u.id, u.username, u.real_name, u.english_name, u.email, u.phone, u.position, u.department_id, u.role_id,'
            . ' u.status, u.is_super_admin, u.force_password_change, u.locked_until, u.failed_login_count, u.last_login_at, u.created_at,'
            . ' r.role_name, d.name AS department_name'
            . ' FROM crm_users u'
            . ' LEFT JOIN crm_roles r ON r.id = u.role_id'
            . ' LEFT JOIN crm_departments d ON d.id = u.department_id'
            . $where
            . " ORDER BY CASE WHEN u.status = 'pending' THEN 0 ELSE 1 END, u.id DESC"
            . ' LIMIT ' . self::PER_PAGE . ' OFFSET ' . $offset
        );
        $stmt->execute($params);
        return ['rows' => $stmt->fetchAll(PDO::FETCH_ASSOC), 'total' => $total, 'page' => $page, 'pages' => $pages];
    }

    /** 各状态的账号数（不受状态筛选影响，受其他筛选影响）。 @return array<string, int> */
    public function statusCounts(array $filters): array
    {
        unset($filters['status']);
        [$where, $params] = $this->filterSql($filters);
        $stmt = $this->pdo->prepare('SELECT u.status, COUNT(*) AS n FROM crm_users u' . $where . ' GROUP BY u.status');
        $stmt->execute($params);
        $counts = ['all' => 0];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $counts[(string) $row['status']] = (int) $row['n'];
            $counts['all'] += (int) $row['n'];
        }
        return $counts;
    }

    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT u.*, r.role_name, d.name AS department_name FROM crm_users u'
            . ' LEFT JOIN crm_roles r ON r.id = u.role_id LEFT JOIN crm_departments d ON d.id = u.department_id'
            . ' WHERE u.id = ? LIMIT 1'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /** 启用中的部门。 @return array<int, string> */
    public function departments(): array
    {
        return $this->options("SELECT id, name FROM crm_departments WHERE status = 'active' ORDER BY sort_order ASC, id ASC");
    }

    /** 启用中的角色。 @return array<int, string> */
    public function roles(): array
    {
        return $this->options("SELECT id, role_name AS name FROM crm_roles WHERE status = 'active' ORDER BY id ASC");
    }

    // ---------------------------------------------------------------- 新建与编辑

    /**
     * 新建账号：直接为正常状态，首次登录必须修改密码。不填密码时自动生成。
     *
     * @return array{id: int, password: string, generated: bool}
     */
    public function create(array $input): array
    {
        $this->requirePermission('users.create');
        $v = new Validator($input);
        $data = $this->validateProfile($v, $input, 0);
        $departmentId = $v->id('department_id', '部门', true);
        $roleId = $v->id('role_id', '角色', true);
        $this->checkDepartment($v, $departmentId, 0);
        $this->checkRole($v, $roleId, 0);
        $password = $v->raw('password');
        $generated = false;
        if ($password === '') {
            $password = self::generatePassword();
            $generated = true;
        } elseif (!Password::isStrong($password)) {
            $v->fail('password', Password::rule());
        }
        $v->check();

        $now = Db::now();
        $this->pdo->prepare(
            'INSERT INTO crm_users (username, password_hash, real_name, english_name, email, phone, position, department_id, role_id, status, is_super_admin, force_password_change, failed_login_count, approved_at, approved_by, created_at, updated_at)'
            . " VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'active', 0, 1, 0, ?, ?, ?, ?)"
        )->execute([
            $data['username'], Password::hash($password), $data['real_name'], $data['english_name'], $data['email'], $data['phone'], $data['position'],
            $departmentId, $roleId, $now, $this->operatorId(), $now, $now,
        ]);
        $id = (int) $this->pdo->lastInsertId();
        $after = $this->auditView($this->find($id) ?? []);
        Audit::log('user_create', 'users', 'user', $id, null, $after, $this->operator);
        $this->approvalLog($id, 'approve', '管理员新建账号', null, $after);
        return ['id' => $id, 'password' => $password, 'generated' => $generated];
    }

    /**
     * 编辑账号资料。基本资料需要 users.create；改部门需要 users.assign_department；改角色需要 users.assign_role。
     * 没有对应权限的字段保持原值。
     */
    public function update(int $id, array $input): array
    {
        $before = $this->editable($id);
        $canBasic = $this->can('users.create');
        $canDept = $this->can('users.assign_department');
        $canRole = $this->can('users.assign_role');
        if (!$canBasic && !$canDept && !$canRole) {
            throw new PermissionException('没有编辑账号的权限');
        }

        $v = new Validator($input);
        $data = $canBasic ? $this->validateProfile($v, $input, $id) : [];
        $departmentId = (int) ($before['department_id'] ?? 0);
        $roleId = (int) ($before['role_id'] ?? 0);
        if ($canDept && array_key_exists('department_id', $input)) {
            $departmentId = $v->id('department_id', '部门', true);
            $this->checkDepartment($v, $departmentId, (int) ($before['department_id'] ?? 0));
        }
        if ($canRole && array_key_exists('role_id', $input)) {
            $roleId = $v->id('role_id', '角色', true);
            $this->checkRole($v, $roleId, (int) ($before['role_id'] ?? 0));
        }
        $v->check();

        $sets = ['department_id = ?', 'role_id = ?', 'updated_at = ?'];
        $params = [$departmentId ?: null, $roleId ?: null, Db::now()];
        foreach ($data as $column => $value) {
            $sets[] = $column . ' = ?';
            $params[] = $value;
        }
        $params[] = $id;
        $this->pdo->prepare('UPDATE crm_users SET ' . implode(', ', $sets) . ' WHERE id = ?')->execute($params);

        $after = $this->find($id) ?? [];
        Audit::log('user_update', 'users', 'user', $id, $this->auditView($before), $this->auditView($after), $this->operator);
        return $after;
    }

    // ---------------------------------------------------------------- 状态

    /** 审核通过：必须指定部门和角色。 */
    public function approve(int $id, array $input): array
    {
        $this->requirePermission('users.approve');
        $before = $this->editable($id);
        if ((string) $before['status'] !== 'pending') {
            throw new RuntimeException('只有待审核的账号可以审核通过');
        }
        $v = new Validator($input);
        $departmentId = $v->id('department_id', '部门', true);
        $roleId = $v->id('role_id', '角色', true);
        $position = $v->text('position', '职位', ['max' => 120, 'default' => (string) ($before['position'] ?? '')]);
        $this->checkDepartment($v, $departmentId, 0);
        $this->checkRole($v, $roleId, 0);
        $v->check();
        $now = Db::now();
        $this->pdo->prepare("UPDATE crm_users SET status = 'active', department_id = ?, role_id = ?, position = ?, approved_at = ?, approved_by = ?, updated_at = ? WHERE id = ?")
            ->execute([$departmentId, $roleId, $position, $now, $this->operatorId(), $now, $id]);
        return $this->afterStatusChange($id, 'approve', $before, '');
    }

    /** 拒绝注册申请：必须填写原因。 */
    public function reject(int $id, array $input): array
    {
        $this->requirePermission('users.reject');
        $before = $this->editable($id, true);
        if ((string) $before['status'] !== 'pending') {
            throw new RuntimeException('只有待审核的账号可以拒绝');
        }
        $v = new Validator($input);
        $reason = $v->text('reason', '拒绝原因', ['required' => true, 'max' => 500]);
        $v->check();
        $now = Db::now();
        $this->pdo->prepare("UPDATE crm_users SET status = 'rejected', rejected_at = ?, rejected_by = ?, reject_reason = ?, updated_at = ? WHERE id = ?")
            ->execute([$now, $this->operatorId(), $reason, $now, $id]);
        return $this->afterStatusChange($id, 'reject', $before, $reason);
    }

    /** 停用：账号不能再登录。 */
    public function disable(int $id, array $input = []): array
    {
        $this->requirePermission('users.disable');
        $before = $this->editable($id, true);
        if ((string) $before['status'] !== 'active') {
            throw new RuntimeException('只有正常状态的账号可以停用');
        }
        $reason = trim((string) ($input['reason'] ?? ''));
        $this->pdo->prepare("UPDATE crm_users SET status = 'disabled', updated_at = ? WHERE id = ?")->execute([Db::now(), $id]);
        return $this->afterStatusChange($id, 'disable', $before, mb_substr($reason, 0, 500, 'UTF-8'));
    }

    /** 启用：已停用、已拒绝或已锁定的账号恢复为正常，同时清除登录失败锁定。 */
    public function enable(int $id): array
    {
        $this->requirePermission('users.enable');
        $before = $this->editable($id);
        if (!in_array((string) $before['status'], ['disabled', 'rejected', 'locked'], true)) {
            throw new RuntimeException('这个账号不需要启用');
        }
        $this->pdo->prepare("UPDATE crm_users SET status = 'active', failed_login_count = 0, locked_until = NULL, updated_at = ? WHERE id = ?")->execute([Db::now(), $id]);
        return $this->afterStatusChange($id, 'enable', $before, '');
    }

    /** 解除登录失败造成的临时锁定。 */
    public function unlock(int $id): array
    {
        $this->requirePermission('users.enable');
        $before = $this->editable($id);
        $this->pdo->prepare('UPDATE crm_users SET failed_login_count = 0, locked_until = NULL, updated_at = ? WHERE id = ?')->execute([Db::now(), $id]);
        $after = $this->find($id) ?? [];
        Audit::log('user_unlock', 'users', 'user', $id, ['locked_until' => $before['locked_until'], 'failed_login_count' => $before['failed_login_count']], ['locked_until' => null, 'failed_login_count' => 0], $this->operator);
        return $after;
    }

    /**
     * 重置密码：不填时自动生成。重置后对方下次登录必须修改密码，同时解除临时锁定。
     *
     * @return array{password: string, generated: bool}
     */
    public function resetPassword(int $id, array $input): array
    {
        $this->requirePermission('users.reset_password');
        $this->editable($id);
        $v = new Validator($input);
        $password = $v->raw('new_password');
        $generated = false;
        if ($password === '') {
            $password = self::generatePassword();
            $generated = true;
        } elseif (!Password::isStrong($password)) {
            $v->fail('new_password', Password::rule());
        }
        $v->check();
        $this->pdo->prepare('UPDATE crm_users SET password_hash = ?, force_password_change = 1, failed_login_count = 0, locked_until = NULL, updated_at = ? WHERE id = ?')
            ->execute([Password::hash($password), Db::now(), $id]);
        Audit::log('user_reset_password', 'users', 'user', $id, null, ['force_password_change' => 1], $this->operator);
        return ['password' => $password, 'generated' => $generated];
    }

    /** 生成密码（10 位，或安全设置要求的更长位数）：去掉容易看混的字符，保证同时有字母和数字。 */
    public static function generatePassword(): string
    {
        $letters = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ';
        $digits = '23456789';
        $all = $letters . $digits;
        $chars = [$letters[random_int(0, strlen($letters) - 1)], $digits[random_int(0, strlen($digits) - 1)]];
        $length = max(10, Password::minLength());
        while (count($chars) < $length) {
            $chars[] = $all[random_int(0, strlen($all) - 1)];
        }
        for ($i = count($chars) - 1; $i > 0; $i--) {
            $j = random_int(0, $i);
            [$chars[$i], $chars[$j]] = [$chars[$j], $chars[$i]];
        }
        return implode('', $chars);
    }

    // ---------------------------------------------------------------- 内部

    /** @return array{0: string, 1: list<mixed>} */
    private function filterSql(array $filters): array
    {
        $where = [];
        $params = [];
        $status = (string) ($filters['status'] ?? '');
        if (isset(self::STATUS_LABELS[$status])) {
            $where[] = 'u.status = ?';
            $params[] = $status;
        }
        $q = trim((string) ($filters['q'] ?? ''));
        if ($q !== '') {
            $like = '%' . addcslashes($q, '\\%_') . '%';
            $where[] = '(u.username LIKE ? OR u.real_name LIKE ? OR u.english_name LIKE ? OR u.email LIKE ? OR u.phone LIKE ?)';
            array_push($params, $like, $like, $like, $like, $like);
        }
        if ((int) ($filters['department_id'] ?? 0) > 0) {
            $where[] = 'u.department_id = ?';
            $params[] = (int) $filters['department_id'];
        }
        if ((int) ($filters['role_id'] ?? 0) > 0) {
            $where[] = 'u.role_id = ?';
            $params[] = (int) $filters['role_id'];
        }
        return [$where ? ' WHERE ' . implode(' AND ', $where) : '', $params];
    }

    /** @return array<string, mixed> username、real_name、english_name、email、phone、position */
    private function validateProfile(Validator $v, array $input, int $exceptId): array
    {
        $data = [
            'username' => $v->text('username', '账号', [
                'required' => true,
                'pattern' => '/^[a-zA-Z0-9_.-]{2,80}$/',
                'pattern_message' => '账号只能使用字母、数字、点、下划线或横线，长度 2-80',
            ]),
            'real_name' => $v->text('real_name', '姓名', ['required' => true, 'max' => 120]),
            'english_name' => $v->text('english_name', '英文名', ['max' => 120]),
            'email' => $v->email('email', '邮箱'),
            'phone' => $v->text('phone', '电话', ['max' => 60]),
            'position' => $v->text('position', '职位', ['max' => 120]),
        ];
        if ($data['username'] !== '' && !isset($v->errors()['username']) && $this->taken('username', $data['username'], $exceptId)) {
            $v->fail('username', '账号已被使用，请换一个');
        }
        if ($data['email'] !== '' && !isset($v->errors()['email']) && $this->taken('email', $data['email'], $exceptId)) {
            $v->fail('email', '邮箱已被其他账号使用');
        }
        $data['email'] = $data['email'] !== '' ? $data['email'] : null;
        return $data;
    }

    private function checkDepartment(Validator $v, int $id, int $current): void
    {
        if ($id > 0 && $id !== $current && !isset($this->departments()[$id])) {
            $v->fail('department_id', '请选择有效的部门');
        }
    }

    private function checkRole(Validator $v, int $id, int $current): void
    {
        if ($id > 0 && $id !== $current && !isset($this->roles()[$id])) {
            $v->fail('role_id', '请选择有效的角色');
        }
    }

    private function taken(string $column, string $value, int $exceptId): bool
    {
        $column = $column === 'email' ? 'email' : 'username';
        $stmt = $this->pdo->prepare("SELECT id FROM crm_users WHERE {$column} = ? AND id <> ? LIMIT 1");
        $stmt->execute([$value, $exceptId]);
        return (bool) $stmt->fetchColumn();
    }

    /** 读取要操作的账号，并检查保护规则。 */
    private function editable(int $id, bool $notSelf = false): array
    {
        $row = $this->find($id);
        if (!$row) {
            throw new NotFoundException('账号不存在');
        }
        if ((int) $row['is_super_admin'] === 1 && (int) ($this->operator['is_super_admin'] ?? 0) !== 1) {
            throw new PermissionException('只有超级管理员可以管理超级管理员账号');
        }
        if ($notSelf && (int) $row['id'] === $this->operatorId()) {
            throw new RuntimeException('不能对自己的账号做这个操作');
        }
        return $row;
    }

    private function afterStatusChange(int $id, string $action, array $before, string $reason): array
    {
        $after = $this->find($id) ?? [];
        Audit::log('user_' . $action, 'users', 'user', $id, $this->auditView($before), $this->auditView($after), $this->operator);
        $this->approvalLog($id, $action, $reason, $this->auditView($before), $this->auditView($after));
        return $after;
    }

    private function approvalLog(int $userId, string $action, string $reason, ?array $before, ?array $after): void
    {
        try {
            $this->pdo->prepare('INSERT INTO crm_user_approval_logs (user_id, action, operator_id, operator_name, reason, before_json, after_json, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
                ->execute([
                    $userId, $action, $this->operatorId(), (string) ($this->operator['username'] ?? ''), $reason,
                    $before === null ? null : json_encode($before, JSON_UNESCAPED_UNICODE),
                    $after === null ? null : json_encode($after, JSON_UNESCAPED_UNICODE),
                    Db::now(),
                ]);
        } catch (\Throwable $e) {
            error_log('centers approval log failed: ' . $e->getMessage());
        }
    }

    /** 写日志时只保留资料字段，不含密码哈希。 */
    private function auditView(array $row): array
    {
        $keys = ['username', 'real_name', 'english_name', 'email', 'phone', 'position', 'department_id', 'department_name', 'role_id', 'role_name', 'status'];
        return array_intersect_key($row, array_flip($keys));
    }

    /** @return array<int, string> */
    private function options(string $sql): array
    {
        $list = [];
        foreach ($this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $list[(int) $row['id']] = (string) $row['name'];
        }
        return $list;
    }

    private function can(string $permission): bool
    {
        return Permission::allows($this->operator, $permission);
    }

    private function requirePermission(string $permission): void
    {
        if (!$this->can($permission)) {
            throw new PermissionException('没有权限（' . $permission . '）');
        }
    }

    private function operatorId(): int
    {
        return (int) ($this->operator['id'] ?? 0);
    }
}
