<?php
declare(strict_types=1);

namespace Artdon\Centers\Base;

/**
 * 左侧导航。只列新系统里的模块，按当前用户权限过滤。
 * permission 可写成“a|b”表示有任意一个即可。
 * 还没做好的模块显示为“建设中”，不可点击；做好后把 ready 改为 true 并填上 href。
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
                    ['key' => 'customer', 'label' => '客户中心', 'icon' => 'customers', 'href' => '', 'permission' => 'customer.view', 'ready' => false],
                    ['key' => 'promotion', 'label' => '推广中心', 'icon' => 'promotion', 'href' => '', 'permission' => 'promotion.view', 'ready' => false],
                    ['key' => 'mail', 'label' => '邮箱系统', 'icon' => 'mail', 'href' => '', 'permission' => 'mail.view', 'ready' => false],
                    ['key' => 'followup', 'label' => '业务跟进', 'icon' => 'followup', 'href' => '', 'permission' => 'visit.view', 'ready' => false],
                ],
            ],
            [
                'key' => 'system',
                'title' => '系统',
                'items' => [
                    ['key' => 'profile', 'label' => '个人账号', 'icon' => 'user', 'href' => Url::center('system', 'profile.php'), 'permission' => '', 'ready' => true],
                    ['key' => 'accounts', 'label' => '账号管理', 'icon' => 'users', 'href' => Url::center('system', 'accounts.php'), 'permission' => 'users.view', 'ready' => true],
                    ['key' => 'permissions', 'label' => '权限中心', 'icon' => 'shield', 'href' => Url::center('system', 'roles.php'), 'permission' => 'users.view', 'ready' => true],
                    ['key' => 'requests', 'label' => '权限申请', 'icon' => 'lock', 'href' => Url::center('system', 'requests.php'), 'permission' => '', 'ready' => true],
                    ['key' => 'settings', 'label' => '设置中心', 'icon' => 'settings', 'href' => Url::center('system', 'settings.php'), 'permission' => 'settings.view', 'ready' => true],
                    ['key' => 'logs', 'label' => '日志', 'icon' => 'log', 'href' => Url::center('system', 'logs.php'), 'permission' => 'logs.view_own|logs.view_all', 'ready' => true],
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
                if ($permission !== '' && !self::allowsAny($permission, $can)) {
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

    /** permission 可以写成“a|b”，有其中任意一个即可。 */
    private static function allowsAny(string $permission, callable $can): bool
    {
        foreach (explode('|', $permission) as $one) {
            if ($one !== '' && $can($one)) {
                return true;
            }
        }
        return false;
    }
}
