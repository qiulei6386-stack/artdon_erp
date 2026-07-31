<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$page = (string) file_get_contents($root . '/adaptation/index.php');
$api = (string) file_get_contents($root . '/api/v1/adaptation.php');
$service = (string) file_get_contents($root . '/app/Services/AdaptationService.php');
$script = (string) file_get_contents($root . '/assets/js/adaptation-v3.js');
$style = (string) file_get_contents($root . '/assets/css/app.css');
$migration = (string) file_get_contents($root . '/database/migrations/20260731_023_config_template_center.php');

$checks = [
    '配置模板页定位为产品分类配置模板中心'
        => str_contains($script, '产品分类配置模板中心')
            && str_contains($script, '模板只定义“这个类别的产品需要配置什么，以及怎么配置”')
            && str_contains($script, 'mc-v3-template-center')
            && str_contains($style, '.mc-v3-template-center'),
    'view=template 不再渲染旧固定模板页'
        => str_contains($script, "else if (state.screen === 'template') renderConfigTemplateCenter();")
            && !str_contains($script, "else if (state.screen === 'template') renderTemplate();"),
    '当前产品/模板来源卡片包含产品与继承信息'
        => str_contains($script, 'mc-v3-template-product-card--rich')
            && str_contains($script, '产品分类')
            && str_contains($script, '产品系列')
            && str_contains($script, '当前使用模板')
            && str_contains($script, '继承层级：指定产品 > 系列 > 分类 > 系统通用'),
    '模板库是动态模板卡片而非写死九宫格'
        => str_contains($script, 'configTemplates()')
            && str_contains($script, 'data-v3-config-template-card')
            && str_contains($script, 'groups_by_template')
            && str_contains($script, '当前模板配置组')
            && !str_contains($script, '十个标准模块完整显示'),
    '配置组详情支持类型、选项、来源、条件、价格交期审批'
        => str_contains($script, '配置组类型')
            && str_contains($script, '数据来源')
            && str_contains($script, '选项管理')
            && str_contains($script, '显示条件')
            && str_contains($script, '价格 / 交期 / 审批')
            && str_contains($script, 'save_config_template_group')
            && str_contains($script, 'save_config_group_option')
            && str_contains($script, 'save_config_group_condition'),
    '模板套用先预览影响并默认保留当前选择'
        => str_contains($script, 'preview_config_template_apply')
            && str_contains($script, 'apply_config_template_to_product')
            && str_contains($script, '套用模板影响预览')
            && str_contains($script, '保留当前已选物料')
            && str_contains($service, 'previewConfigTemplateApply')
            && str_contains($service, 'applyConfigTemplateToProduct')
            && str_contains($service, "'fill_missing'"),
    '服务端提供模板中心 API 与精细权限'
        => str_contains($api, "'config_templates'")
            && str_contains($api, "'save_config_template'")
            && str_contains($api, "'copy_config_template'")
            && str_contains($api, "'disable_config_template'")
            && str_contains($api, "'save_config_group_definition'")
            && str_contains($api, "'save_config_template_group'")
            && str_contains($api, "'preview_config_template_apply'")
            && str_contains($api, 'config_template.apply'),
    '服务层从数据库读取模板/组/选项/条件/筛选'
        => str_contains($service, 'configTemplateCenter')
            && str_contains($service, 'configTemplates')
            && str_contains($service, 'configTemplateGroups')
            && str_contains($service, 'configGroupOptions')
            && str_contains($service, 'configGroupConditions')
            && str_contains($service, 'configGroupMaterialFilters')
            && str_contains($service, 'dynamic_template_group')
            && str_contains($service, 'attribute_options'),
    '迁移创建完整模板中心数据结构'
        => str_contains($migration, 'mc_config_templates')
            && str_contains($migration, 'mc_config_group_definitions')
            && str_contains($migration, 'mc_config_template_groups')
            && str_contains($migration, 'mc_config_group_options')
            && str_contains($migration, 'mc_config_group_conditions')
            && str_contains($migration, 'mc_config_group_material_filters')
            && str_contains($migration, 'mc_config_template_versions')
            && str_contains($migration, 'mc_config_template_logs'),
    '迁移写入模板权限并预置业务示例'
        => str_contains($migration, 'config_template.view')
            && str_contains($migration, 'config_template.create_group')
            && str_contains($migration, 'config_template.manage_condition')
            && str_contains($migration, 'track_lighting_template')
            && str_contains($migration, 'recessed_lighting_template')
            && str_contains($migration, 'magnetic_lighting_template')
            && str_contains($migration, 'intrack_driver')
            && str_contains($migration, 'connector_wire')
            && str_contains($migration, 'body_length')
            && str_contains($migration, 'waterproof_structure'),
    '单产品工作台核心配置名称来自动态配置组'
        => str_contains($script, 'const groupLabel = group =>')
            && str_contains($script, 'coreGroups().slice(0, 4)')
            && str_contains($script, 'groupLabel(group)')
            && str_contains($script, '属性选项'),
    '页面为模板库 + 左右工作区 + 底部操作栏'
        => str_contains($style, '.mc-v3-template-library')
            && str_contains($style, '.mc-v3-config-template-workspace')
            && str_contains($style, '.mc-v3-template-group-list')
            && str_contains($style, '.mc-v3-template-detail-panel')
            && str_contains($style, '.mc-v3-template-center-footer')
            && str_contains($style, '.mc-v3-template-preview-box'),
    '服务端直开 template/batch 视图'
        => str_contains($page, "['home', 'products', 'workspace', 'template', 'batch']")
            && str_contains($page, "'template' => '配置模板'")
            && str_contains($page, "'batch' => '批量矩阵'"),
];

$failed = [];
foreach ($checks as $label => $passed) {
    echo ($passed ? '[PASS] ' : '[FAIL] ') . $label . PHP_EOL;
    if (!$passed) $failed[] = $label;
}

if ($failed) {
    fwrite(STDERR, 'adaptation template page contract failed: ' . implode('；', $failed) . PHP_EOL);
    exit(1);
}

echo "adaptation template page contract passed.\n";
