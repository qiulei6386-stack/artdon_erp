<?php
declare(strict_types=1);

/** 邮箱系统接口。都要求登录；POST 要求 CSRF 令牌；具体权限在 Service 里检查。 */

require_once __DIR__ . '/bootstrap.php';

use Artdon\Centers\Base\Api;
use Artdon\Centers\Base\Flash;
use Artdon\Centers\Base\Url;
use Artdon\Centers\Base\ValidationException;
use Artdon\Centers\Customer\CustomerService;
use Artdon\Centers\Mail\MailAccounts;
use Artdon\Centers\Mail\Mailbox;

$owner = static function (array $input, array $user): int {
    $id = (int) ($input['user_id'] ?? 0);
    return $id > 0 ? $id : (int) $user['id'];
};
$ids = static function (array $input): array {
    $raw = $input['ids'] ?? [];
    return is_array($raw) ? $raw : [$raw];
};

Api::run([
    'account_save' => ['method' => 'POST', 'handler' => static function (array $input, array $user) use ($owner): array {
        $isNew = (int) ($input['id'] ?? 0) === 0;
        (new MailAccounts($user))->save($owner($input, $user), $input);
        Flash::set('success', $isNew ? '邮箱已绑定。建议点“测试”确认能正常收发。' : '邮箱设置已保存。');
        return ['message' => '已保存'];
    }],
    'account_default' => ['method' => 'POST', 'handler' => static function (array $input, array $user) use ($owner): array {
        (new MailAccounts($user))->setDefault($owner($input, $user), (int) ($input['id'] ?? 0));
        Flash::set('success', '已设为默认发信邮箱。');
        return ['message' => '已设为默认'];
    }],
    'account_enable' => ['method' => 'POST', 'handler' => static function (array $input, array $user) use ($owner): array {
        $on = !empty($input['enabled']);
        (new MailAccounts($user))->setEnabled($owner($input, $user), (int) ($input['id'] ?? 0), $on);
        Flash::set('success', $on ? '邮箱已启用。' : '邮箱已停用，停用后不再收信、不能用它发信。');
        return ['message' => '已保存'];
    }],
    'account_delete' => ['method' => 'POST', 'handler' => static function (array $input, array $user) use ($owner): array {
        (new MailAccounts($user))->delete($owner($input, $user), (int) ($input['id'] ?? 0));
        Flash::set('success', '邮箱已删除。已经收到的邮件仍然保留。');
        return ['message' => '已删除'];
    }],
    'account_test' => ['method' => 'POST', 'handler' => static function (array $input, array $user) use ($owner): array {
        $r = (new MailAccounts($user))->test($owner($input, $user), (int) ($input['id'] ?? 0), (string) ($input['type'] ?? 'imap'));
        return $r + ['message' => $r['message']];
    }],
    'mail_read' => ['method' => 'POST', 'handler' => static function (array $input, array $user) use ($ids): array {
        $read = !empty($input['read']);
        $n = (new Mailbox($user))->setRead($ids($input), $read);
        return ['message' => '已标记为' . ($read ? '已读' : '未读'), 'count' => $n];
    }],
    'mail_star' => ['method' => 'POST', 'handler' => static function (array $input, array $user) use ($ids): array {
        $on = !empty($input['starred']);
        (new Mailbox($user))->setStarred($ids($input), $on);
        return ['message' => $on ? '已加星标' : '已取消星标'];
    }],
    'mail_delete' => ['method' => 'POST', 'handler' => static function (array $input, array $user) use ($ids): array {
        $n = (new Mailbox($user))->delete($ids($input));
        Flash::set('success', '已删除 ' . $n . ' 封邮件，可在“已删除”里恢复。');
        return ['message' => '已删除', 'redirect' => (string) ($input['back'] ?? '') !== '' ? Url::safeRedirect((string) $input['back'], Url::center('mail', 'index.php')) : null];
    }],
    'mail_restore' => ['method' => 'POST', 'handler' => static function (array $input, array $user) use ($ids): array {
        $n = (new Mailbox($user))->restore($ids($input));
        Flash::set('success', '已恢复 ' . $n . ' 封邮件。');
        return ['message' => '已恢复'];
    }],
    'mail_link' => ['method' => 'POST', 'handler' => static function (array $input, array $user): array {
        $customer = (int) ($input['customer_id'] ?? 0);
        if ($customer <= 0 && !empty($input['pick'])) {
            throw new ValidationException(['customer_id' => '请输入名称后，从下拉列表里选择客户']);
        }
        (new Mailbox($user))->linkCustomer((int) ($input['id'] ?? 0), $customer);
        Flash::set('success', $customer > 0 ? '已关联客户。' : '已取消关联。');
        return ['message' => '已保存'];
    }],
    'customer_search' => ['method' => 'GET', 'handler' => static function (array $input, array $user): array {
        return ['items' => (new CustomerService($user))->search((string) ($_GET['q'] ?? ''))];
    }],
]);
