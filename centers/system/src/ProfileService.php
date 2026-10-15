<?php
declare(strict_types=1);

namespace Artdon\Centers\System;

use Artdon\Centers\Base\Audit;
use Artdon\Centers\Base\Db;
use Artdon\Centers\Base\Password;
use Artdon\Centers\Base\Validator;
use Artdon\Centers\Base\ValidationException;
use PDO;

/**
 * 个人账号：查看和修改自己的资料、修改密码。读写 crm_users 表。
 *
 * 规则：账号只能用字母、数字、点、下划线、横线（2-80 位）且不能与他人重复；姓名必填；
 * 邮箱可空但不能与他人重复；部门只能从启用中的部门选择（原部门已停用可保持不变）；
 * 新密码至少 8 位含字母和数字、不能与当前密码相同。错误按字段返回。
 */
final class ProfileService
{
    /** @var PDO */
    private $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Db::pdo();
    }

    /** 账号资料，含角色名、部门名。不存在时返回 null。 */
    public function profile(int $userId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT u.id, u.username, u.real_name, u.english_name, u.email, u.phone, u.position,'
            . ' u.department_id, u.role_id, u.status, u.is_super_admin, u.force_password_change,'
            . ' u.last_login_at, u.last_login_ip, u.created_at, r.role_name, d.name AS department_name'
            . ' FROM crm_users u'
            . ' LEFT JOIN crm_roles r ON r.id = u.role_id'
            . ' LEFT JOIN crm_departments d ON d.id = u.department_id'
            . ' WHERE u.id = ? LIMIT 1'
        );
        $stmt->execute([$userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /** 可选部门：启用中的部门，按排序。 @return array<int, string> */
    public function departments(): array
    {
        $rows = $this->pdo->query("SELECT id, name FROM crm_departments WHERE status = 'active' ORDER BY sort_order ASC, id ASC")->fetchAll(PDO::FETCH_ASSOC);
        $list = [];
        foreach ($rows as $row) {
            $list[(int) $row['id']] = (string) $row['name'];
        }
        return $list;
    }

    /** 最近的登录记录。 @return list<array<string, mixed>> */
    public function recentLogins(int $userId, int $limit = 8): array
    {
        $limit = max(1, min(50, $limit));
        $stmt = $this->pdo->prepare('SELECT status, ip, message, created_at FROM crm_login_logs WHERE user_id = ? ORDER BY id DESC LIMIT ' . $limit);
        $stmt->execute([$userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * 修改自己的资料。成功返回修改后的资料；校验失败抛出 ValidationException。
     *
     * @param array<string, mixed> $input username、real_name、english_name、email、phone、position、department_id
     */
    public function updateProfile(int $userId, array $input): array
    {
        $before = $this->profile($userId);
        if (!$before) {
            throw new ValidationException(['username' => '账号不存在']);
        }

        $v = new Validator($input);
        $username = $v->text('username', '账号', [
            'required' => true,
            'pattern' => '/^[a-zA-Z0-9_.-]{2,80}$/',
            'pattern_message' => '账号只能使用字母、数字、点、下划线或横线，长度 2-80',
        ]);
        $realName = $v->text('real_name', '姓名', ['required' => true, 'max' => 120]);
        $englishName = $v->text('english_name', '英文名', ['max' => 120]);
        $email = $v->email('email', '邮箱');
        $phone = $v->text('phone', '电话', ['max' => 60]);
        $position = $v->text('position', '职位', ['max' => 120]);
        $departmentId = $v->id('department_id', '部门');

        if ($username !== '' && !isset($v->errors()['username']) && $this->exists('username', $username, $userId)) {
            $v->fail('username', '账号已被使用，请换一个');
        }
        if ($email !== '' && !isset($v->errors()['email']) && $this->exists('email', $email, $userId)) {
            $v->fail('email', '邮箱已被其他账号使用');
        }
        $currentDept = (int) ($before['department_id'] ?? 0);
        if ($departmentId !== 0 && $departmentId !== $currentDept && !isset($this->departments()[$departmentId])) {
            $v->fail('department_id', '请选择有效的部门');
        }
        $v->check();

        $this->pdo->prepare(
            'UPDATE crm_users SET username = ?, real_name = ?, english_name = ?, email = ?, phone = ?, position = ?, department_id = ?, updated_at = ? WHERE id = ?'
        )->execute([
            $username,
            $realName,
            $englishName,
            $email !== '' ? $email : null,
            $phone,
            $position,
            $departmentId !== 0 ? $departmentId : null,
            Db::now(),
            $userId,
        ]);

        $after = $this->profile($userId) ?? [];
        Audit::log('user_self_update_profile', 'profile', 'user', $userId, self::auditView($before), self::auditView($after));
        return $after;
    }

    /**
     * 修改自己的密码。成功后调用方应退出登录，让用户用新密码重新登录。
     *
     * @param array<string, mixed> $input old_password、new_password、confirm_password
     */
    public function changePassword(int $userId, array $input): void
    {
        $v = new Validator($input);
        $old = $v->raw('old_password');
        $new = $v->raw('new_password');
        $confirm = $v->raw('confirm_password');
        if ($old === '') {
            $v->fail('old_password', '请输入当前密码');
        }
        if ($new === '') {
            $v->fail('new_password', '请输入新密码');
        }
        if ($confirm === '') {
            $v->fail('confirm_password', '请再输入一次新密码');
        }
        $v->check();

        $stmt = $this->pdo->prepare('SELECT password_hash FROM crm_users WHERE id = ? LIMIT 1');
        $stmt->execute([$userId]);
        $hash = (string) $stmt->fetchColumn();
        if (!Password::verify($old, $hash)) {
            $v->fail('old_password', '当前密码不正确');
        } elseif (!Password::isStrong($new)) {
            $v->fail('new_password', Password::RULE);
        } elseif ($new === $old) {
            $v->fail('new_password', '新密码不能与当前密码相同');
        } elseif ($new !== $confirm) {
            $v->fail('confirm_password', '两次输入的新密码不一致');
        }
        $v->check();

        $this->pdo->prepare('UPDATE crm_users SET password_hash = ?, force_password_change = 0, updated_at = ? WHERE id = ?')
            ->execute([Password::hash($new), Db::now(), $userId]);
        Audit::log('change_password', 'profile', 'user', $userId);
    }

    private function exists(string $column, string $value, int $exceptId): bool
    {
        $column = $column === 'email' ? 'email' : 'username';
        $stmt = $this->pdo->prepare("SELECT id FROM crm_users WHERE {$column} = ? AND id <> ? LIMIT 1");
        $stmt->execute([$value, $exceptId]);
        return (bool) $stmt->fetchColumn();
    }

    /** 写日志时只保留资料字段，不记录密码哈希等。 */
    private static function auditView(array $row): array
    {
        $keys = ['username', 'real_name', 'english_name', 'email', 'phone', 'position', 'department_id', 'department_name'];
        return array_intersect_key($row, array_flip($keys));
    }
}
