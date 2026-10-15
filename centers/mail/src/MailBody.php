<?php
declare(strict_types=1);

namespace Artdon\Centers\Mail;

/**
 * 邮件正文显示。正文页单独输出，放在沙箱 iframe 里（不允许脚本、表单、跳转顶层页面），并带严格的 CSP。
 * 这里只把正文里的图片地址改成新系统的附件地址：
 *   - cid:xxx（按 content_id 找附件）
 *   - 旧系统的 crm_api.php?action=mail_attachment_download&attachment_id=N
 *   - 直接指向 storage/mail_attachments/... 的地址
 * 同时去掉 script、meta 跳转、base、外部样式表等。
 */
final class MailBody
{
    /**
     * @param list<array<string, mixed>> $attachments 这封信的全部附件（含内嵌图片），需要 id、content_id、file_path 或 file_name
     * @param callable(int): string $url 附件编号 => 显示地址
     */
    public static function html(array $mail, array $attachments, callable $url): string
    {
        $html = (string) ($mail['body_html'] ?? '');
        if (trim($html) === '') {
            $text = (string) ($mail['body_text'] ?? '');
            if (trim($text) === '') {
                return '<p style="color:#64748b">（这封邮件没有正文）</p>';
            }
            $escaped = htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $linked = (string) preg_replace('~(https?://[^\s<>"\']+)~i', '<a href="$1">$1</a>', $escaped);
            return '<div style="white-space:pre-wrap;word-break:break-word">' . $linked . '</div>';
        }
        $byCid = [];
        $byFile = [];
        $ids = [];
        foreach ($attachments as $a) {
            $id = (int) $a['id'];
            $ids[$id] = true;
            $cid = strtolower(trim((string) ($a['content_id'] ?? ''), " <>\t"));
            if ($cid !== '') {
                $byCid[$cid] = $id;
            }
            $path = (string) ($a['file_path'] ?? '');
            if ($path !== '') {
                $byFile[basename($path)] = $id;
            }
        }
        $html = self::clean($html);
        $rewrite = static function (string $src) use ($byCid, $byFile, $ids, $url): ?string {
            $raw = html_entity_decode($src, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if (stripos($raw, 'cid:') === 0) {
                $cid = strtolower(trim(substr($raw, 4), " <>\t"));
                return isset($byCid[$cid]) ? $url($byCid[$cid]) : '';
            }
            if (stripos($raw, 'mail_attachment_download') !== false) {
                $query = (string) parse_url($raw, PHP_URL_QUERY);
                parse_str($query, $p);
                $id = (int) ($p['attachment_id'] ?? 0);
                return isset($ids[$id]) ? $url($id) : '';
            }
            if (stripos($raw, 'mail_attachments/') !== false) {
                $file = basename((string) parse_url($raw, PHP_URL_PATH));
                return isset($byFile[$file]) ? $url($byFile[$file]) : '';
            }
            return null;
        };
        return (string) preg_replace_callback('/(<img\b[^>]*?\bsrc\s*=\s*)(["\'])(.*?)\2/is', static function (array $m) use ($rewrite): string {
            $new = $rewrite($m[3]);
            return $new === null ? $m[0] : $m[1] . $m[2] . htmlspecialchars($new, ENT_QUOTES, 'UTF-8') . $m[2];
        }, $html);
    }

    /** 整页（给 iframe 用）。 */
    public static function page(string $inner): string
    {
        return '<!doctype html><html><head><meta charset="utf-8"><base target="_blank">'
            . '<style>html,body{margin:0;padding:0}body{padding:4px 2px 16px;font:14px/1.6 -apple-system,BlinkMacSystemFont,"Segoe UI","PingFang SC","Microsoft YaHei",sans-serif;color:#0f172a;word-wrap:break-word;overflow-wrap:anywhere}img{max-width:100%;height:auto}table{max-width:100%}pre{white-space:pre-wrap}blockquote{margin:8px 0;padding-left:12px;border-left:3px solid #cbd5e1;color:#475569}</style>'
            . '</head><body>' . $inner . '</body></html>';
    }

    /** 正文页的 CSP：不允许任何脚本、表单、插件；图片允许本站、data 和外部图片。 */
    public static function csp(): string
    {
        return "default-src 'none'; img-src 'self' data: https: http:; style-src 'unsafe-inline'; font-src data: https:; media-src 'none'; form-action 'none'; frame-ancestors 'self'; base-uri 'none'";
    }

    /** 去掉危险或无用的标签；事件属性一并去掉（虽然 CSP 已禁止脚本）。 */
    public static function clean(string $html): string
    {
        if (preg_match('/<body\b[^>]*>(.*)<\/body>/is', $html, $m)) {
            $styles = '';
            if (preg_match_all('/<style\b[^>]*>.*?<\/style>/is', $html, $sm)) {
                $styles = implode('', $sm[0]);
            }
            $html = $styles . $m[1];
        }
        $html = (string) preg_replace('/<(script|iframe|object|embed|applet|frameset|frame|noscript|template)\b.*?<\/\1\s*>/is', '', $html);
        $html = (string) preg_replace('/<\/?(script|iframe|object|embed|applet|frameset|frame|meta|base|link|form|input|button|textarea|select)\b[^>]*>/i', '', $html);
        $html = (string) preg_replace('/\son[a-z]+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $html);
        $html = (string) preg_replace('/(href|src)\s*=\s*(["\'])\s*(javascript|vbscript):[^"\']*\2/i', '$1=$2#$2', $html);
        return $html;
    }
}
