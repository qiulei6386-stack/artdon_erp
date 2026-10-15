<?php
declare(strict_types=1);

namespace Artdon\Centers\System;

use Artdon\Centers\Base\Layout;
use Artdon\Centers\Base\Url;

/** 权限中心各页面顶部的标签栏。没做好的标签显示为“建设中”。 */
final class PermissionTabs
{
    public static function render(string $active): string
    {
        $tabs = [
            'roles' => ['角色与权限', Url::center('system', 'roles.php')],
            'departments' => ['部门', Url::center('system', 'departments.php')],
            'people' => ['人员权限', ''],
            'requests' => ['申请与授权', ''],
        ];
        $html = '<nav class="tabs" aria-label="权限中心">';
        foreach ($tabs as $key => [$label, $href]) {
            if ($href === '') {
                $html .= '<span class="tab is-pending" aria-disabled="true">' . Layout::e($label) . '<span class="tab-count">建设中</span></span>';
                continue;
            }
            $on = $key === $active;
            $html .= '<a class="tab' . ($on ? ' is-active' : '') . '" href="' . Layout::e($href) . '"' . ($on ? ' aria-current="page"' : '') . '>' . Layout::e($label) . '</a>';
        }
        return $html . '</nav>';
    }
}
