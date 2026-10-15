<?php
declare(strict_types=1);

/** 客户中心接口。都要求登录、POST、CSRF 令牌，具体权限在 Service 里检查。 */

require_once __DIR__ . '/bootstrap.php';

use Artdon\Centers\Base\Api;
use Artdon\Centers\Base\Flash;
use Artdon\Centers\Base\Url;
use Artdon\Centers\Customer\ContactService;
use Artdon\Centers\Customer\CustomerMerger;
use Artdon\Centers\Customer\CustomerService;
use Artdon\Centers\Customer\LeadPool;
use Artdon\Centers\Customer\DuplicateFinder;
use Artdon\Centers\Customer\Country;

$customers = static function (array $user): CustomerService {
    return new CustomerService($user);
};
$contacts = static function (array $user): ContactService {
    return new ContactService($user);
};
$id = static function (array $input, string $key = 'id'): int {
    return (int) ($input[$key] ?? 0);
};
$ids = static function (array $input): array {
    $raw = $input['ids'] ?? [];
    return is_array($raw) ? $raw : [];
};

Api::run([
    'customer_create' => ['method' => 'POST', 'handler' => static function (array $input, array $user) use ($customers): array {
        $r = $customers($user)->create($input);
        if ($r['status'] === 'duplicates') {
            $matches = array_map(static function (array $m): array {
                return [
                    'id' => $m['customer_id'],
                    'name' => $m['customer_name'] . ($m['customer_name_en'] !== '' && $m['customer_name_en'] !== $m['customer_name'] ? ' / ' . $m['customer_name_en'] : ''),
                    'country' => Country::label($m['country']) . ($m['city'] !== '' ? ' · ' . $m['city'] : ''),
                    'owner' => $m['owner_name'] !== '' ? $m['owner_name'] : '公海',
                    'similarity' => $m['similarity'],
                    'reasons' => DuplicateFinder::reasonText($m['reasons']),
                    'url' => Url::center('customer', 'view.php?id=' . $m['customer_id']),
                ];
            }, $r['matches']);
            return ['status' => 'duplicates', 'matches' => $matches, 'message' => '找到疑似重复的客户'];
        }
        if ($r['status'] === 'lead_pool') {
            Flash::set('warning', $r['reason'] . '（暂存池编号 ' . $r['lead_id'] . '）。暂存池目前在旧系统处理，新系统第二批会加上。');
            return ['status' => 'lead_pool', 'message' => '已进入暂存池'];
        }
        Flash::set('success', '客户已新建。');
        return ['status' => 'created', 'redirect' => Url::center('customer', 'view.php?id=' . $r['id']), 'message' => '已新建'];
    }],
    'customer_update' => ['method' => 'POST', 'handler' => static function (array $input, array $user) use ($customers, $id): array {
        $changed = $customers($user)->update($id($input), $input);
        Flash::set('success', $changed ? '客户资料已保存。' : '没有修改。');
        return ['message' => '已保存'];
    }],
    'customer_assign' => ['method' => 'POST', 'handler' => static function (array $input, array $user) use ($customers, $ids): array {
        $assistants = $input['assistant_ids'] ?? [];
        $n = $customers($user)->assign($ids($input), (int) ($input['owner_user_id'] ?? 0), is_array($assistants) ? $assistants : [], trim((string) ($input['note'] ?? '')));
        Flash::set('success', '已分配 ' . $n . ' 个客户。');
        return ['message' => '已分配'];
    }],
    'customer_transfer_public' => ['method' => 'POST', 'handler' => static function (array $input, array $user) use ($customers, $ids): array {
        $n = $customers($user)->transferPublic($ids($input), (string) ($input['reason'] ?? ''));
        Flash::set('success', '已将 ' . $n . ' 个客户转入公海。');
        return ['message' => '已转入公海', 'redirect' => !empty($input['back']) ? Url::center('customer', 'index.php?tab=public') : null];
    }],
    'customer_claim' => ['method' => 'POST', 'handler' => static function (array $input, array $user) use ($customers, $id): array {
        $customers($user)->claim($id($input));
        Flash::set('success', '已领取，你现在是这个客户的负责人。');
        return ['message' => '已领取', 'redirect' => Url::center('customer', 'view.php?id=' . $id($input))];
    }],
    'customer_delete' => ['method' => 'POST', 'handler' => static function (array $input, array $user) use ($customers, $id): array {
        $customers($user)->delete($id($input), (string) ($input['reason'] ?? ''));
        Flash::set('success', '客户已删除，可在“已删除”里恢复。');
        return ['message' => '已删除', 'redirect' => Url::center('customer', 'index.php')];
    }],
    'customer_restore' => ['method' => 'POST', 'handler' => static function (array $input, array $user) use ($customers, $id): array {
        $customers($user)->restore($id($input));
        Flash::set('success', '客户已恢复。');
        return ['message' => '已恢复'];
    }],
    'contact_create' => ['method' => 'POST', 'handler' => static function (array $input, array $user) use ($contacts, $id): array {
        $contacts($user)->create($id($input, 'customer_id'), $input);
        Flash::set('success', '联系人已添加。');
        return ['message' => '已添加'];
    }],
    'contact_update' => ['method' => 'POST', 'handler' => static function (array $input, array $user) use ($contacts, $id): array {
        $contacts($user)->update($id($input), $input);
        Flash::set('success', '联系人已保存。');
        return ['message' => '已保存'];
    }],
    'contact_delete' => ['method' => 'POST', 'handler' => static function (array $input, array $user) use ($contacts, $id): array {
        $contacts($user)->delete($id($input));
        Flash::set('success', '联系人已删除。');
        return ['message' => '已删除'];
    }],
    'customer_merge' => ['method' => 'POST', 'handler' => static function (array $input, array $user) use ($ids): array {
        $list = $ids($input);
        if (!empty($input['other_id'])) {
            $list[] = (int) $input['other_id'];
        }
        $r = (new CustomerMerger($user))->merge($list, (int) ($input['master_id'] ?? 0));
        Flash::set('success', '已合并 ' . count($r['merged_ids']) . ' 个客户到这里，联系人、跟进等记录都已转过来。');
        return ['message' => '已合并', 'redirect' => Url::center('customer', 'view.php?id=' . $r['master_id'])];
    }],
    'lead_confirm' => ['method' => 'POST', 'handler' => static function (array $input, array $user) use ($id): array {
        $customerId = (new LeadPool($user))->confirm($id($input, 'lead_id'), $input);
        Flash::set('success', '已确认入库，成为正式客户。');
        return ['message' => '已入库', 'redirect' => Url::center('customer', 'view.php?id=' . $customerId)];
    }],
    'lead_attach' => ['method' => 'POST', 'handler' => static function (array $input, array $user) use ($id): array {
        $added = (new LeadPool($user))->attach($id($input, 'lead_id'), $id($input, 'customer_id'), !empty($input['add_contacts']));
        Flash::set('success', '已关联到已有客户' . ($added ? '，补上了 ' . $added . ' 个联系人' : '') . '。');
        return ['message' => '已关联', 'redirect' => Url::center('customer', 'leads.php')];
    }],
    'lead_reject' => ['method' => 'POST', 'handler' => static function (array $input, array $user) use ($id): array {
        (new LeadPool($user))->reject($id($input, 'lead_id'), (string) ($input['reason'] ?? ''));
        Flash::set('success', '已丢弃。');
        return ['message' => '已丢弃', 'redirect' => Url::center('customer', 'leads.php')];
    }],
]);
