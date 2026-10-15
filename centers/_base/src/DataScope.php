<?php
declare(strict_types=1);

namespace Artdon\Centers\Base;

/**
 * 数据范围：某人在某个模块能看到哪些数据。各业务中心据此拼自己的查询条件。
 *
 * 两个来源取范围大的（已和用户确认）：
 *   1. 权限：超级管理员、{模块}.view_all → 全部；{模块}.view_department → 本部门
 *   2. 权限中心“人员权限”里给这个人设的数据范围（crm_user_scopes）
 *      all 全部；department、team 本部门（新系统暂无团队，按部门算）；country 指定国家；
 *      customer_group 指定客户分组；self、assigned 本人；none 都不能看（只有权限也没给更大范围时才生效）
 * “本人”的具体含义（负责人、协助人、创建人……）由各中心决定。
 */
final class DataScope
{
    /**
     * @return array{all: bool, none: bool, self: bool, department_ids: list<int>, countries: list<string>, group_ids: list<int>}
     */
    public static function resolve(array $user, string $module): array
    {
        $out = ['all' => false, 'none' => false, 'self' => true, 'department_ids' => [], 'countries' => [], 'group_ids' => []];
        if ((int) ($user['id'] ?? 0) <= 0) {
            return ['all' => false, 'none' => true, 'self' => false, 'department_ids' => [], 'countries' => [], 'group_ids' => []];
        }
        $setting = Permission::scope($user, $module);
        $type = $setting['scope_type'];
        if ((int) ($user['is_super_admin'] ?? 0) === 1 || $type === 'all' || Permission::allows($user, $module . '.view_all')) {
            $out['all'] = true;
            return $out;
        }
        $departmentId = (int) ($user['department_id'] ?? 0);
        if ($departmentId > 0 && (in_array($type, ['department', 'team'], true) || Permission::allows($user, $module . '.view_department'))) {
            $out['department_ids'] = [$departmentId];
        }
        if ($type === 'country') {
            $out['countries'] = self::split($setting['scope_value']);
        }
        if ($type === 'customer_group') {
            $out['group_ids'] = array_values(array_unique(array_filter(array_map('intval', self::split($setting['scope_value'])))));
        }
        if ($type === 'none' && !$out['department_ids']) {
            $out['none'] = true;
            $out['self'] = false;
        }
        return $out;
    }

    /** 一句话说明，显示在列表页上方。 */
    public static function describe(array $scope): string
    {
        if ($scope['all']) {
            return '全部';
        }
        if ($scope['none']) {
            return '没有查看范围';
        }
        $parts = ['本人'];
        if ($scope['department_ids']) {
            $parts[] = '本部门';
        }
        if ($scope['countries']) {
            $parts[] = '国家：' . implode('、', $scope['countries']);
        }
        if ($scope['group_ids']) {
            $parts[] = '指定分组 ' . count($scope['group_ids']) . ' 个';
        }
        return implode(' + ', $parts);
    }

    /** @return list<string> */
    private static function split(string $value): array
    {
        $parts = preg_split('/[\s,，、;；]+/u', trim($value)) ?: [];
        return array_values(array_unique(array_filter(array_map('trim', $parts), static function (string $v): bool {
            return $v !== '';
        })));
    }
}
