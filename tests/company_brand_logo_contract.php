<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$settings = file_get_contents($root . '/includes/settings_service.php');
$api = file_get_contents($root . '/crm_api.php');
$crm = file_get_contents($root . '/crm.php');
$dispatch = file_get_contents($root . '/dispatch_next.php');
$js = file_get_contents($root . '/assets/crm/crm.js');
$css = file_get_contents($root . '/assets/crm/crm.css');

if (in_array(false, [$settings, $api, $crm, $dispatch, $js, $css], true)) {
    throw new RuntimeException('company brand logo sources are not readable');
}

foreach ([
    [$settings, 'function company_brand_logo_path', '共享 LOGO 安全路径服务'],
    [$settings, 'function company_brand_upload_relative_dir', '共享 LOGO 上传目录服务'],
    [$settings, 'uploads/dispatch_next/company_brand', '共享 LOGO 可写目录白名单'],
    [$settings, 'uploads/(?:company_brand|dispatch_next/company_brand)', '历史与当前 LOGO 路径兼容白名单'],
    [$settings, 'function company_brand_logo_url', '共享 LOGO 缓存版本 URL'],
    [$api, "\$action === 'company_logo_upload'", 'LOGO 上传接口'],
    [$api, 'company_brand_upload_relative_dir()', 'LOGO 上传可写目录'],
    [$api, "\$action === 'company_logo_reset'", 'LOGO 恢复默认接口'],
    [$api, "'image/webp' => 'webp'", 'WebP 上传校验'],
    [$api, '2 * 1024 * 1024', '2MB 上传限制'],
    [$api, "save_app_setting('company_logo'", 'CRM 统一 LOGO 保存'],
    [$api, "save_app_setting('topbar_logo'", '派工统一 LOGO 保存'],
    [$crm, 'data-company-logo-file', 'CRM LOGO 选择入口'],
    [$crm, 'data-company-logo-upload', 'CRM LOGO 上传按钮'],
    [$crm, '上传一次，同时应用到 CRM 和派工左上角', '统一应用说明'],
    [$crm, 'company_brand_logo_url($companySettings)', 'CRM 顶部 LOGO 读取'],
    [$dispatch, 'company_brand_logo_url($companySettings)', '派工顶部 LOGO 读取'],
    [$js, 'uploadCompanyLogo: function', '浏览器 LOGO 上传行为'],
    [$js, "data.append('logo', file)", '浏览器文件字段'],
    [$js, 'resetCompanyLogo: function', '浏览器恢复默认行为'],
    [$css, '.company-logo-upload {', 'LOGO 上传区域样式'],
    [$css, '.status-logo img {', 'CRM 顶部 LOGO 图片样式'],
] as [$source, $needle, $label]) {
    if (!str_contains($source, $needle)) {
        throw new RuntimeException('缺少：' . $label);
    }
}

echo "company_brand_logo_contract: OK\n";
