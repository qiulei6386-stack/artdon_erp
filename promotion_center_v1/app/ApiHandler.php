<?php
declare(strict_types=1);

namespace Artdon\PromotionCenter;

class ApiHandler
{
    /** @var \PDO */
    private $pdo;

    /** @var PmAccess */
    private $access;

    /** @var LegacyCustomerAdapter */
    private $customers;

    /** @var GroupService|null */
    private $groups;

    /** @var LegacyPromotionHistoryAdapter */
    private $history;

    public function __construct(\PDO $pdo, PmAccess $access, ?LegacyCustomerAdapter $customers = null, ?GroupService $groups = null, ?LegacyPromotionHistoryAdapter $history = null)
    {
        $this->pdo = $pdo;
        $this->access = $access;
        $this->customers = $customers ?: new LegacyCustomerAdapter($pdo, $access);
        $this->groups = $groups;
        $this->history = $history ?: new LegacyPromotionHistoryAdapter($pdo, $access);
    }

    /** @param array<string,mixed> $input @return array{ok:bool,status:int,message:string,data:array<string,mixed>,error_code:string} */
    public function handle(string $action, array $input, string $method = 'GET'): array
    {
        try {
            $data = $this->dispatch($action, $input, strtoupper($method));
            return ['ok' => true, 'status' => 200, 'message' => '', 'data' => $data, 'error_code' => ''];
        } catch (PmHttpException $e) {
            return ['ok' => false, 'status' => $e->status, 'message' => $e->getMessage(), 'data' => [], 'error_code' => $e->errorCode];
        }
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    private function dispatch(string $action, array $input, string $method): array
    {
        if ($action === 'session') {
            return $this->session();
        }
        if ($action === 'customers' && $method === 'GET') {
            return $this->customers->list($input);
        }
        if ($action === 'customer' && $method === 'GET') {
            return $this->customers->detail((int) ($input['id'] ?? 0));
        }
        if ($action === 'customer_filters' && $method === 'GET') {
            return $this->customers->filters();
        }
        if ($action === 'groups' && $method === 'GET') {
            return ['rows' => $this->groupService()->listGroups()];
        }
        if ($action === 'group' && $method === 'GET') {
            return $this->groupService()->getGroup((int) ($input['id'] ?? 0), (int) ($input['page'] ?? 1), (int) ($input['page_size'] ?? 50));
        }
        if ($action === 'history' && $method === 'GET') {
            return $this->history();
        }
        if ($action === 'history') {
            throw new PmHttpException('历史推广是只读的', 405, 'METHOD');
        }
        if ($method !== 'POST') {
            throw new PmHttpException('请使用正确的请求方式', 405, 'METHOD');
        }
        if ($action === 'group_create') {
            return $this->groupService()->create($input);
        }
        if ($action === 'group_rename') {
            return $this->groupService()->rename($input);
        }
        if ($action === 'group_delete') {
            return $this->groupService()->delete($input);
        }
        if ($action === 'group_members_add') {
            return $this->groupService()->addMembers($input);
        }
        if ($action === 'group_members_remove') {
            return $this->groupService()->removeMembers($input);
        }
        if ($action === 'group_members_move') {
            return $this->groupService()->moveMembers($input);
        }
        if ($action === 'group_members_copy') {
            return $this->groupService()->copyMembers($input);
        }
        if ($action === 'undo') {
            return $this->groupService()->undo((string) ($input['token'] ?? ''));
        }
        throw new PmHttpException('没有这个接口', 404, 'NOT_FOUND');
    }

    /** @return array<string,mixed> */
    public function session(): array
    {
        $customer = $this->access->scope('customer');
        $promotion = $this->access->scope('promotion');
        return [
            'user' => [
                'id' => $this->access->userId(),
                'name' => $this->access->displayName(),
                'role_name' => $this->access->roleName(),
                'department_name' => $this->access->departmentName(),
            ],
            'permissions' => [
                'view' => $this->access->hasPermission('promotion.view'),
                'create_group' => $this->access->hasPermission('promotion.create_group'),
                'edit_group' => $this->access->hasPermission('promotion.edit_group'),
                'delete_group' => $this->access->hasPermission('promotion.delete_group'),
                'move_customer' => $this->access->hasPermission('promotion.move_customer'),
            ],
            'scopes' => [
                'customer' => $customer,
                'promotion' => $promotion,
            ],
            'scope_labels' => [
                'customer' => self::scopeLabel($customer['scope_type']),
                'promotion' => self::scopeLabel($promotion['scope_type']),
            ],
            'demo' => defined('PM_DEMO') && PM_DEMO,
            'phase' => 1,
        ];
    }

    /** @return array<string,mixed> */
    public function history(): array
    {
        return $this->history->list();
    }

    private function groupService(): GroupService
    {
        if ($this->groups === null) {
            $this->groups = new GroupService($this->pdo, $this->access, $this->customers);
        }
        return $this->groups;
    }

    public static function scopeLabel(string $type): string
    {
        $map = [
            'all' => '全部',
            'self' => '本人负责',
            'assigned' => '本人负责',
            'department' => '本部门',
            'team' => '本团队',
            'country' => '指定国家',
            'customer_group' => '本人负责',
            'none' => '无',
        ];
        return $map[$type] ?? $type;
    }
}
