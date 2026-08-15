<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$js = file_get_contents($root . '/assets/crm/crm.js');
$css = file_get_contents($root . '/assets/crm/crm.css');
if ($js === false || $css === false) {
    fwrite(STDERR, "Cannot read CRM mail reader navigation sources\n");
    exit(1);
}

$markers = [
    [$js, 'currentListRows: []', '邮件列表保留当前页顺序'],
    [$js, 'this.currentListRows = (rows || []).slice();', '渲染列表时保存顺序'],
    [$js, 'mailReaderNavigationState: function (mail)', '正文上一封下一封状态计算'],
    [$js, 'renderMailReaderNavigation: function (mail)', '正文导航按钮渲染'],
    [$js, 'data-mail-reader-prev', '上一封按钮标记'],
    [$js, 'data-mail-reader-next', '下一封按钮标记'],
    [$js, 'openAdjacentMail: function (direction)', '上一封下一封打开逻辑'],
    [$js, 'this.page -= 1', '上一封支持跨到上一页'],
    [$js, 'this.page += 1', '下一封支持跨到下一页'],
    [$js, "root.querySelector('[data-mail-reader-prev]')", '上一封事件绑定'],
    [$js, "root.querySelector('[data-mail-reader-next]')", '下一封事件绑定'],
    [$css, '.mail-reader-nav', '正文导航样式'],
    [$css, 'margin-left: auto;', '正文导航靠右显示'],
    [$css, '.mail-reader-nav button:disabled', '边界按钮禁用样式'],
];

foreach ($markers as [$source, $needle, $label]) {
    if (strpos($source, $needle) === false) {
        fwrite(STDERR, "Missing CRM mail reader navigation marker: {$label}\n");
        exit(1);
    }
}

echo "crm_mail_reader_navigation_contract ok\n";
