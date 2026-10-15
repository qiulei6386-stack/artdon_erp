<?php
declare(strict_types=1);

namespace Artdon\Centers\Customer;

use Artdon\Centers\Base\DataScope;
use Artdon\Centers\Base\Permission;

/**
 * 客户可见范围（用户已确认）：
 *   - 本人：第一负责人、协助负责人或创建人是自己；
 *   - 权限 customer.view_all / customer.view_department 与权限中心设置的客户数据范围取较大者；
 *   - 公海（没有任何负责人的客户）单独看：有领取、转入公海、分配权限，或能看全部的人可以看。
 */
final class CustomerScope
{
    /**
     * 可见条件（不含删除状态）。
     *
     * @param list<mixed> $params
     */
    public static function sql(array $user, array &$params, string $alias = 'c'): string
    {
        $scope = DataScope::resolve($user, 'customer');
        if ($scope['all']) {
            return '1=1';
        }
        if ($scope['none']) {
            return '1=0';
        }
        $userId = (int) ($user['id'] ?? 0);
        $parts = [self::mineSql($userId, $params, $alias) . ' OR ' . $alias . '.created_by = ?'];
        $params[] = $userId;
        if ($scope['department_ids']) {
            $marks = implode(',', array_fill(0, count($scope['department_ids']), '?'));
            $parts[] = $alias . '.owner_user_id IN (SELECT du.id FROM crm_users du WHERE du.department_id IN (' . $marks . '))';
            $parts[] = 'EXISTS (SELECT 1 FROM crm_customer_owners dco JOIN crm_users du2 ON du2.id = dco.user_id WHERE dco.customer_id = ' . $alias . '.id AND du2.department_id IN (' . $marks . '))';
            foreach ([1, 2] as $_) {
                foreach ($scope['department_ids'] as $id) {
                    $params[] = $id;
                }
            }
        }
        if ($scope['countries']) {
            $values = [];
            foreach ($scope['countries'] as $c) {
                foreach (Country::variants($c) as $v) {
                    $values[] = $v;
                }
            }
            $values = array_values(array_unique($values));
            $parts[] = $alias . '.country IN (' . implode(',', array_fill(0, count($values), '?')) . ')';
            foreach ($values as $v) {
                $params[] = $v;
            }
        }
        if ($scope['group_ids']) {
            $parts[] = 'EXISTS (SELECT 1 FROM crm_customer_group_relations sgr JOIN crm_customer_groups sg ON sg.id = sgr.group_id AND sg.deleted_at IS NULL WHERE sgr.customer_id = ' . $alias . '.id AND sgr.group_id IN (' . implode(',', array_fill(0, count($scope['group_ids']), '?')) . '))';
            foreach ($scope['group_ids'] as $id) {
                $params[] = $id;
            }
        }
        return '(' . implode(' OR ', $parts) . ')';
    }

    /** 我负责的（第一负责人或协助）。 @param list<mixed> $params */
    public static function mineSql(int $userId, array &$params, string $alias = 'c'): string
    {
        $params[] = $userId;
        $params[] = $userId;
        return '(' . $alias . '.owner_user_id = ? OR EXISTS (SELECT 1 FROM crm_customer_owners mco WHERE mco.customer_id = ' . $alias . '.id AND mco.user_id = ?))';
    }

    /** 公海：没有任何负责人。 */
    public static function publicSql(string $alias = 'c'): string
    {
        return '(' . $alias . '.owner_user_id IS NULL AND NOT EXISTS (SELECT 1 FROM crm_customer_owners pco WHERE pco.customer_id = ' . $alias . '.id))';
    }

    public static function canSeePublic(array $user): bool
    {
        foreach (['customer.claim_public', 'customer.transfer_public', 'customer.assign'] as $p) {
            if (Permission::allows($user, $p)) {
                return true;
            }
        }
        return DataScope::resolve($user, 'customer')['all'];
    }

    public static function describe(array $user): string
    {
        return DataScope::describe(DataScope::resolve($user, 'customer'));
    }
}
