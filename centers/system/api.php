<?php
declare(strict_types=1);

/** 系统中心接口。所有写操作都要求登录、POST、CSRF 令牌，具体权限在各个 Service 里检查。 */

require_once __DIR__ . '/bootstrap.php';

use Artdon\Centers\Base\Api;
use Artdon\Centers\Base\Flash;
use Artdon\Centers\Base\Notify;
use Artdon\Centers\System\AccountService;
use Artdon\Centers\System\DepartmentService;
use Artdon\Centers\System\PeopleService;
use Artdon\Centers\System\RequestService;
use Artdon\Centers\System\RoleService;
use Artdon\Centers\System\SettingsService;

$accounts = static function (array $user): AccountService {
    return new AccountService($user);
};
$id = static function (array $input): int {
    return (int) ($input['id'] ?? 0);
};

Api::run([
    'account_create' => ['method' => 'POST', 'handler' => static function (array $input, array $user) use ($accounts): array {
        $r = $accounts($user)->create($input);
        Flash::set('success', '账号已创建。对方首次登录时需要修改密码。');
        return ['id' => $r['id'], 'password' => $r['password'], 'message' => '账号已创建'];
    }],
    'account_update' => ['method' => 'POST', 'handler' => static function (array $input, array $user) use ($accounts, $id): array {
        $accounts($user)->update($id($input), $input);
        Flash::set('success', '账号资料已保存。');
        return ['message' => '已保存'];
    }],
    'account_approve' => ['method' => 'POST', 'handler' => static function (array $input, array $user) use ($accounts, $id): array {
        $row = $accounts($user)->approve($id($input), $input);
        Flash::set('success', '已审核通过：' . $row['real_name'] . '（' . $row['username'] . '）。');
        return ['message' => '已审核通过'];
    }],
    'account_reject' => ['method' => 'POST', 'handler' => static function (array $input, array $user) use ($accounts, $id): array {
        $row = $accounts($user)->reject($id($input), $input);
        Flash::set('success', '已拒绝：' . $row['real_name'] . '（' . $row['username'] . '）。');
        return ['message' => '已拒绝'];
    }],
    'account_disable' => ['method' => 'POST', 'handler' => static function (array $input, array $user) use ($accounts, $id): array {
        $row = $accounts($user)->disable($id($input), $input);
        Flash::set('success', '已停用：' . $row['real_name'] . '（' . $row['username'] . '），对方将不能再登录。');
        return ['message' => '已停用'];
    }],
    'account_enable' => ['method' => 'POST', 'handler' => static function (array $input, array $user) use ($accounts, $id): array {
        $row = $accounts($user)->enable($id($input));
        Flash::set('success', '已启用：' . $row['real_name'] . '（' . $row['username'] . '）。');
        return ['message' => '已启用'];
    }],
    'account_unlock' => ['method' => 'POST', 'handler' => static function (array $input, array $user) use ($accounts, $id): array {
        $row = $accounts($user)->unlock($id($input));
        Flash::set('success', '已解除锁定：' . $row['real_name'] . '（' . $row['username'] . '）。');
        return ['message' => '已解除锁定'];
    }],
    'account_reset_password' => ['method' => 'POST', 'handler' => static function (array $input, array $user) use ($accounts, $id): array {
        $r = $accounts($user)->resetPassword($id($input), $input);
        Flash::set('success', '密码已重置。对方下次登录时需要修改密码。');
        return ['password' => $r['password'], 'message' => '密码已重置'];
    }],

    // ---------- 角色 ----------
    'role_create' => ['method' => 'POST', 'handler' => static function (array $input, array $user): array {
        $id = (new RoleService($user))->create($input);
        Flash::set('success', '角色已创建，请勾选它的权限。');
        return ['id' => $id, 'redirect' => 'roles.php?role=' . $id, 'message' => '角色已创建'];
    }],
    'role_update' => ['method' => 'POST', 'handler' => static function (array $input, array $user) use ($id): array {
        (new RoleService($user))->update($id($input), $input);
        Flash::set('success', '角色已保存。');
        return ['message' => '已保存'];
    }],
    'role_disable' => ['method' => 'POST', 'handler' => static function (array $input, array $user) use ($id): array {
        (new RoleService($user))->disable($id($input));
        Flash::set('success', '角色已停用。');
        return ['message' => '已停用'];
    }],
    'role_enable' => ['method' => 'POST', 'handler' => static function (array $input, array $user) use ($id): array {
        (new RoleService($user))->enable($id($input));
        Flash::set('success', '角色已启用。');
        return ['message' => '已启用'];
    }],
    'role_permissions_save' => ['method' => 'POST', 'handler' => static function (array $input, array $user): array {
        $keys = isset($input['permissions']) && is_array($input['permissions']) ? array_values($input['permissions']) : [];
        $r = (new RoleService($user))->savePermissions((int) ($input['role_id'] ?? 0), $keys);
        Flash::set('success', $r['added'] || $r['removed']
            ? '权限已保存：增加 ' . count($r['added']) . ' 项，去掉 ' . count($r['removed']) . ' 项。这个角色下的账号马上生效。'
            : '没有改动。');
        return ['added' => $r['added'], 'removed' => $r['removed'], 'message' => '已保存'];
    }],

    // ---------- 部门 ----------
    'department_create' => ['method' => 'POST', 'handler' => static function (array $input, array $user): array {
        $newId = (new DepartmentService($user))->create($input);
        Flash::set('success', '部门已创建。');
        return ['id' => $newId, 'message' => '部门已创建'];
    }],
    'department_update' => ['method' => 'POST', 'handler' => static function (array $input, array $user) use ($id): array {
        (new DepartmentService($user))->update($id($input), $input);
        Flash::set('success', '部门已保存。');
        return ['message' => '已保存'];
    }],
    'department_disable' => ['method' => 'POST', 'handler' => static function (array $input, array $user) use ($id): array {
        (new DepartmentService($user))->disable($id($input));
        Flash::set('success', '部门已停用。');
        return ['message' => '已停用'];
    }],
    'department_enable' => ['method' => 'POST', 'handler' => static function (array $input, array $user) use ($id): array {
        (new DepartmentService($user))->enable($id($input));
        Flash::set('success', '部门已启用。');
        return ['message' => '已启用'];
    }],

    // ---------- 人员权限 ----------
    'people_permissions_save' => ['method' => 'POST', 'handler' => static function (array $input, array $user): array {
        $choices = [];
        foreach ($input as $key => $value) {
            if (is_string($key) && preg_match('/^perm\[(.+)\]$/', $key, $m)) {
                $choices[$m[1]] = (string) $value;
            }
        }
        $r = (new PeopleService($user))->saveOverrides((int) ($input['user_id'] ?? 0), $choices);
        Flash::set('success', $r['changed'] ? '个人设置已保存：改动 ' . $r['changed'] . ' 项，马上生效。' : '没有改动。');
        return ['changed' => $r['changed'], 'message' => '已保存'];
    }],
    'people_scopes_save' => ['method' => 'POST', 'handler' => static function (array $input, array $user): array {
        $scopes = [];
        foreach ($input as $key => $value) {
            if (is_string($key) && preg_match('/^scope\[([a-z0-9_]+)\]\[(type|value)\]$/', $key, $m)) {
                $scopes[$m[1]][$m[2]] = (string) $value;
            }
        }
        $r = (new PeopleService($user))->saveScopes((int) ($input['user_id'] ?? 0), $scopes);
        Flash::set('success', $r['changed'] ? '数据范围已保存：改动 ' . $r['changed'] . ' 个模块。' : '没有改动。');
        return ['changed' => $r['changed'], 'message' => '已保存'];
    }],

    // ---------- 权限申请与临时授权 ----------
    'request_submit' => ['method' => 'POST', 'handler' => static function (array $input, array $user): array {
        $newId = (new RequestService($user))->submit($input);
        Flash::set('success', '申请已提交，等待审批。');
        return ['id' => $newId, 'redirect' => 'requests.php?view=mine', 'message' => '已提交'];
    }],
    'request_cancel' => ['method' => 'POST', 'handler' => static function (array $input, array $user) use ($id): array {
        (new RequestService($user))->cancel($id($input));
        Flash::set('success', '申请已取消。');
        return ['message' => '已取消'];
    }],
    'request_approve' => ['method' => 'POST', 'handler' => static function (array $input, array $user) use ($id): array {
        (new RequestService($user))->approve($id($input), $input);
        Flash::set('success', '已批准，对方马上获得这项权限，到期自动失效。');
        return ['message' => '已批准'];
    }],
    'request_reject' => ['method' => 'POST', 'handler' => static function (array $input, array $user) use ($id): array {
        (new RequestService($user))->reject($id($input), $input);
        Flash::set('success', '已拒绝申请。');
        return ['message' => '已拒绝'];
    }],
    'grant_revoke' => ['method' => 'POST', 'handler' => static function (array $input, array $user) use ($id): array {
        (new RequestService($user))->revoke($id($input), $input);
        Flash::set('success', '临时授权已撤销，马上失效。');
        return ['message' => '已撤销'];
    }],
    'grant_extend' => ['method' => 'POST', 'handler' => static function (array $input, array $user) use ($id): array {
        (new RequestService($user))->extend($id($input), $input);
        Flash::set('success', '临时授权已延长。');
        return ['message' => '已延长'];
    }],
    'settings_company_save' => ['method' => 'POST', 'handler' => static function (array $input, array $user): array {
        (new SettingsService($user))->saveCompany($input);
        Flash::set('success', '公司信息已保存。');
        return ['message' => '已保存'];
    }],
    'settings_logo_save' => ['method' => 'POST', 'handler' => static function (array $input, array $user): array {
        (new SettingsService($user))->saveLogo(is_string($input['logo'] ?? null) ? $input['logo'] : '');
        Flash::set('success', 'Logo 已更新。');
        return ['message' => '已更新'];
    }],
    'settings_logo_remove' => ['method' => 'POST', 'handler' => static function (array $input, array $user): array {
        (new SettingsService($user))->removeLogo();
        Flash::set('success', '已去掉 Logo，改用默认字母。');
        return ['message' => '已去掉'];
    }],
    'settings_security_save' => ['method' => 'POST', 'handler' => static function (array $input, array $user): array {
        (new SettingsService($user))->saveSecurity($input);
        Flash::set('success', '安全设置已保存，马上生效。');
        return ['message' => '已保存'];
    }],
    'notification_read_all' => ['method' => 'POST', 'handler' => static function (array $input, array $user): array {
        $n = Notify::markAllRead((int) $user['id']);
        Flash::set('success', $n > 0 ? '已将 ' . $n . ' 条通知标为已读。' : '没有未读通知。');
        return ['message' => '已全部标为已读'];
    }],
]);
