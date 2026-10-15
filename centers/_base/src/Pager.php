<?php
declare(strict_types=1);

namespace Artdon\Centers\Base;

/** 分页条：第一页、最后一页、当前页前后各两页，中间用省略号。 */
final class Pager
{
    /**
     * @param array{page: int, pages: int, total: int} $result
     * @param callable(int): string $link 页码 => 地址
     */
    public static function render(array $result, callable $link, string $unit = '条'): string
    {
        $page = (int) $result['page'];
        $pages = (int) $result['pages'];
        $html = '<nav class="pager" aria-label="分页"><span>第 ' . $page . ' / ' . $pages . ' 页，共 ' . (int) $result['total'] . ' ' . Layout::e($unit) . '</span>';
        if ($pages <= 1) {
            return $html . '</nav>';
        }
        $html .= '<div class="pager-links">';
        if ($page > 1) {
            $html .= '<a href="' . Layout::e($link($page - 1)) . '" aria-label="上一页">‹</a>';
        }
        $last = 0;
        for ($p = 1; $p <= $pages; $p++) {
            if ($p !== 1 && $p !== $pages && abs($p - $page) > 2) {
                continue;
            }
            if ($last && $p - $last > 1) {
                $html .= '<span aria-hidden="true">…</span>';
            }
            $html .= $p === $page
                ? '<span class="is-current" aria-current="page">' . $p . '</span>'
                : '<a href="' . Layout::e($link($p)) . '">' . $p . '</a>';
            $last = $p;
        }
        if ($page < $pages) {
            $html .= '<a href="' . Layout::e($link($page + 1)) . '" aria-label="下一页">›</a>';
        }
        return $html . '</div></nav>';
    }
}
