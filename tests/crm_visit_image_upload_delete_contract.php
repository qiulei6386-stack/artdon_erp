<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$visit = file_get_contents($root . '/crm_visit.php');
$js = file_get_contents($root . '/assets/crm/crm.js');
$css = file_get_contents($root . '/assets/crm/crm.css');

if (in_array(false, [$visit, $js, $css], true)) {
    throw new RuntimeException('CRM visit image upload sources are not readable');
}

foreach ([
    [$visit, '2097152', '后端图片上传限制为 2MB'],
    [$visit, '超过 2MB 图片限制。', '后端错误提示为 2MB'],
    [$js, '单张 <= 2MB', '拜访图片上传区域提示 2MB'],
    [$js, 'file.size > 2097152', '前端本地图片校验 2MB'],
    [$js, '拜访图片单张不能超过 2MB，请压缩后再上传。', '上传前阻止超过 2MB 图片'],
    [$js, '超过 2MB，不能上传', '本地预览标红超限图片'],
    [$js, 'visit-thumb-delete', '图片缩略图显示独立删除按钮'],
    [$js, 'data-visit-remove-local-file', '待上传图片也有移除按钮'],
    [$js, 'removeLocalFile: function', '待上传图片可从文件选择框移除'],
    [$js, 'appendLocalFiles: function', '分多次选择图片会追加到待上传队列'],
    [$js, 'pendingLocalFiles: function', '预览、删除和上传共用累计文件队列'],
    [$js, 'input._crmVisitFiles = merged', '新选择的图片保留在累计文件队列'],
    [$js, 'files.splice(index, 1)', '移除图片只删除指定的待上传文件'],
    [$js, "self.uploadFileInput(visitId, 'image', imageFiles)", '上传使用累计图片队列而非最后一次文件框选择'],
    [$js, '已移除待上传图片', '移除待上传图片成功提示'],
    [$js, 'data-visit-delete-file', '删除按钮绑定文件删除行为'],
    [$js, "images.innerHTML = self.fileListHtml(files, 'image')", '删除后刷新图片列表'],
    [$js, "attachments.innerHTML = self.fileListHtml(files, 'attachment')", '删除后刷新附件列表'],
    [$css, '.visit-thumb-delete', '图片删除按钮样式'],
    [$css, '.visit-thumb.is-invalid', '超限图片预览样式'],
] as [$source, $needle, $label]) {
    if (!str_contains($source, $needle)) {
        throw new RuntimeException('缺少：' . $label);
    }
}

echo "crm_visit_image_upload_delete_contract: OK\n";
