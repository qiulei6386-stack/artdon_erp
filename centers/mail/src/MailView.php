<?php
declare(strict_types=1);

namespace Artdon\Centers\Mail;

use Artdon\Centers\Base\Layout;
use Artdon\Centers\Base\Url;

/** 邮箱页面共用的小工具。 */
final class MailView
{
    /** 列表时间：今天显示时分，今年显示月日，更早显示年月日。 */
    public static function shortTime(string $time): string
    {
        $ts = strtotime($time);
        if (!$ts) {
            return '';
        }
        if (date('Y-m-d', $ts) === date('Y-m-d')) {
            return date('H:i', $ts);
        }
        return date('Y', $ts) === date('Y') ? date('n月j日', $ts) : date('Y-m-d', $ts);
    }

    public static function size(int $bytes): string
    {
        if ($bytes >= 1048576) {
            return round($bytes / 1048576, 1) . ' MB';
        }
        return max(1, (int) round($bytes / 1024)) . ' KB';
    }

    /** 附件地址。 */
    public static function attachmentUrl(int $id, int $owner, bool $inline): string
    {
        return Url::center('mail', 'attachment.php?' . http_build_query(array_filter(['id' => $id, 'user_id' => $owner, 'inline' => $inline ? 1 : 0])));
    }

    /** 邮箱主人切换（有“查看全部邮件”权限时）。 */
    public static function ownerSelect(array $names, int $selfId, int $current, string $action, array $keep = []): string
    {
        $h = '<form class="mail-owner" method="get" action="' . Layout::e($action) . '">';
        foreach ($keep as $k => $v) {
            $h .= '<input type="hidden" name="' . Layout::e((string) $k) . '" value="' . Layout::e((string) $v) . '">';
        }
        $h .= '<label class="sr-only" for="mail-owner">查看谁的邮件</label><select class="input select" id="mail-owner" name="user_id" data-autosubmit>';
        $h .= '<option value="' . $selfId . '">我的邮件</option>';
        foreach ($names as $uid => $name) {
            if ((int) $uid === $selfId) {
                continue;
            }
            $h .= '<option value="' . (int) $uid . '"' . ((int) $uid === $current ? ' selected' : '') . '>' . Layout::e((string) $name) . '</option>';
        }
        return $h . '</select><noscript><button type="submit" class="btn btn-sm">查看</button></noscript></form>';
    }

    /**
     * 邮箱系统的二级导航。$active：dashboard / inbox / sent / unreplied / accounts。
     *
     * @return list<array{label: string, href: string, active: bool, count?: int}>
     */
    public static function subnav(string $active, int $owner = 0, int $unread = 0, bool $accounts = true): array
    {
        $q = $owner > 0 ? ['user_id' => $owner] : [];
        $u = static function (string $page, array $extra = []) use ($q): string {
            $query = http_build_query($q + $extra);
            return Url::center('mail', $page) . ($query !== '' ? '?' . $query : '');
        };
        $items = [
            ['label' => '邮件工作台', 'href' => $u('index.php'), 'active' => $active === 'dashboard'],
            ['label' => '收件箱', 'href' => $u('inbox.php'), 'active' => $active === 'inbox', 'count' => $unread],
            ['label' => '已发送', 'href' => $u('inbox.php', ['folder' => 'sent']), 'active' => $active === 'sent'],
            ['label' => '待回复跟进', 'href' => $u('inbox.php', ['folder' => 'unreplied']), 'active' => $active === 'unreplied'],
        ];
        if ($accounts) {
            $items[] = ['label' => '邮箱设置', 'href' => Url::center('mail', 'accounts.php'), 'active' => $active === 'accounts'];
        }
        return $items;
    }

    /** 首字母头像。 */
    public static function letter(string $name, string $email = ''): string
    {
        $base = trim($name) !== '' ? trim($name) : trim($email);
        $first = $base === '' ? '?' : mb_substr(preg_replace('/^["\'\s]+/u', '', $base) ?: $base, 0, 1, 'UTF-8');
        $n = (int) (abs(crc32(strtolower($email !== '' ? $email : $base))) % 8);
        return '<span class="letter' . ($n > 0 ? ' letter-' . $n : '') . '" aria-hidden="true">' . Layout::e($first) . '</span>';
    }

    /** 分类标签。 */
    public static function categoryPill(string $cat): string
    {
        $cls = ['quote' => 'pill-brand', 'sample' => 'pill-warn', 'service' => 'pill-accent', 'promotion' => 'pill-info'][$cat] ?? '';
        if ($cls === '') {
            return '';
        }
        return '<span class="pill ' . $cls . '">' . Layout::e(Mailbox::CATEGORIES[$cat] ?? $cat) . '</span>';
    }

    /** 距今多久：“3 天前”“2 小时前”。 */
    public static function ago(string $time): string
    {
        $ts = strtotime($time);
        if (!$ts) {
            return '';
        }
        $d = time() - $ts;
        if ($d < 3600) {
            return max(1, (int) floor($d / 60)) . ' 分钟前';
        }
        if ($d < 86400) {
            return (int) floor($d / 3600) . ' 小时前';
        }
        return (int) floor($d / 86400) . ' 天前';
    }

    /** 迷你柱状图。 @param list<int> $values */
    public static function bars(array $values, array $labels = [], string $extra = ''): string
    {
        $h = '<div class="mini-bars' . ($extra !== '' ? ' ' . $extra : '') . '" aria-hidden="true">';
        $last = count($values) - 1;
        foreach (MailDashboard::bars($values) as $i => $p) {
            $h .= '<span' . ($i === $last ? ' class="is-today"' : '') . ' style="height:' . max(4, $p) . '%" title="' . Layout::e(($labels[$i] ?? '') . '：' . (int) $values[$i]) . '"></span>';
        }
        return $h . '</div>';
    }
}
