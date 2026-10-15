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
}
