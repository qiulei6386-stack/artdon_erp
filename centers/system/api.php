<?php
declare(strict_types=1);

/** 系统中心接口。所有写操作都要求登录、POST、CSRF 令牌，具体权限在 AccountService 里检查。 */

require_once __DIR__ . '/bootstrap.php';

use Artdon\Centers\Base\Api;
use Artdon\Centers\Base\Flash;
use Artdon\Centers\System\AccountService;

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
]);
