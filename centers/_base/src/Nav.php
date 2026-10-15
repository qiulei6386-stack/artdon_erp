<?php
declare(strict_types=1);

namespace Artdon\Centers\Base;

/**
 * 左侧导航。四个中心 + 其他功能 + 系统中心，按当前用户权限过滤。
 *
 * 还没有重做的模块先指向旧页面（legacy = true，导航上标“旧版”）。
 * 某个中心上线后，只需要把它的 href 改成新地址、去掉 legacy。
 */
final class Nav
{
    /**
     * @return list<array{key: string, title: string, items: list<array<string, mixed>>}>
     */
    public static function definition(): array
    {
        return [
            [
                'key' => 'centers',
                'title' => '',
                'items' => [
                    ['key' => 'customer', 'label' => '客户中心', 'icon' => 'customers', 'href' => Url::app('crm.php#customers'), 'permission' => 'customer.view', 'legacy' => true],
                    ['key' => 'promotion', 'label' => '推广中心', 'icon' => 'promotion', 'href' => Url::app('crm.php#promotion'), 'permission' => 'promotion.view', 'legacy' => true],
                    ['key' => 'mail', 'label' => '邮箱系统', 'icon' => 'mail', 'href' => Url::app('crm.php#mail'), 'permission' => 'mail.view', 'legacy' => true],
                    ['key' => 'followup', 'label' => '业务跟进', 'icon' => 'followup', 'href' => Url::app('crm.php#visits'), 'permission' => 'visit.view', 'legacy' => true],
                ],
            ],
            [
                'key' => 'others',
                'title' => '其他功能',
                'items' => [
                    ['key' => 'workspace', 'label' => '工作台', 'href' => Url::app('crm.php#workspace'), 'permission' => 'dashboard.view', 'legacy' => true],
                    ['key' => 'ai', 'label' => 'AI 中心', 'href' => Url::app('crm.php#ai'), 'permission' => 'radar_view', 'legacy' => true],
                    ['key' => 'materials', 'label' => '资料生成', 'href' => Url::app('crm.php#materials'), 'permission' => 'customer.view', 'legacy' => true],
                    ['key' => 'linkage', 'label' => '联动中心', 'href' => Url::app('crm.php#linkage'), 'permission' => 'linkage.view', 'legacy' => true],
                    ['key' => 'portal', 'label' => '业务门户', 'href' => Url::app('index.php'), 'permission' => '', 'legacy' => true],
                ],
            ],
            [
                'key' => 'system',
                'title' => '系统',
                'items' => [
                    ['key' => 'profile', 'label' => '个人账号', 'icon' => 'user', 'href' => Url::center('system', 'profile.php'), 'permission' => '', 'legacy' => false],
                    ['key' => 'accounts', 'label' => '账号管理', 'icon' => 'users', 'href' => Url::app('users.php'), 'permission' => 'users.view', 'legacy' => true],
                    ['key' => 'permissions', 'label' => '权限中心', 'icon' => 'shield', 'href' => Url::app('permissions.php'), 'permission' => 'users.view', 'legacy' => true],
                    ['key' => 'settings', 'label' => '设置中心', 'icon' => 'settings', 'href' => Url::app('settings.php'), 'permission' => 'settings.view', 'legacy' => true],
                    ['key' => 'logs', 'label' => '日志', 'icon' => 'log', 'href' => Url::app('logs.php'), 'permission' => 'logs.view_own', 'legacy' => true],
                ],
            ],
        ];
    }

    /**
     * 当前用户能看到的导航，active 标出当前页。空分组不返回。
     *
     * @param callable(string): bool $can 权限判断，默认 Auth::can
     * @return list<array{key: string, title: string, items: list<array<string, mixed>>}>
     */
    public static function visible(string $activeKey, ?callable $can = null): array
    {
        $can = $can ?? static function (string $permission): bool {
            return Auth::can($permission);
        };
        $groups = [];
        foreach (self::definition() as $group) {
            $items = [];
            foreach ($group['items'] as $item) {
                $permission = (string) $item['permission'];
                if ($permission !== '' && !$can($permission)) {
                    continue;
                }
                $item['active'] = $item['key'] === $activeKey;
                $items[] = $item;
            }
            if ($items) {
                $group['items'] = $items;
                $groups[] = $group;
            }
        }
        return $groups;
    }
}
