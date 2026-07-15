<?php

function crm_permission_definitions(): array
{
    return [
        ['dashboard.view', 'dashboard', 'view', '查看 CRM 工作台', 'low'],
        ['crm.preferences.edit', 'crm', 'preferences_edit', '修改 CRM 界面偏好', 'low'],
        ['crm.config.view', 'crm_config', 'view', '查看 CRM 配置', 'low'],
        ['online.view', 'online', 'view', '查看在线状态', 'low'],
        ['online.view_all', 'online', 'view_all', '查看全部在线人员', 'medium'],

        ['mail.view', 'mail', 'view', '邮箱中心：查看/收信/发信基础权限', 'low'],
        ['mail.view_own', 'mail', 'view_own', '查看本人邮箱', 'low'],
        ['mail.sync', 'mail', 'sync', '收取/同步邮件', 'medium'],
        ['mail.send', 'mail', 'send', '写信/回复/转发/发送邮件', 'medium'],
        ['mail.delete', 'mail', 'delete', '删除邮件', 'high'],
        ['mail.attachment_download', 'mail', 'attachment_download', '下载邮件附件', 'medium'],
        ['mail.link_customer', 'mail', 'link_customer', '邮件关联客户', 'medium'],
        ['mail.account_bind_own', 'mail', 'account_bind_own', '设置本人邮箱和签名', 'medium'],
        ['mail.account_manage_all', 'mail', 'account_manage_all', '帮助员工设置邮箱和签名', 'high'],
        ['mail.signature_manage_own', 'mail', 'signature_manage_own', '管理本人邮件签名', 'medium'],
        ['mail.signature_batch_apply', 'mail', 'signature_batch_apply', '批量应用邮件签名', 'high'],
        ['mail.view_logs', 'mail', 'view_logs', '查看邮件日志', 'medium'],
        ['mail.view_body', 'mail', 'view_body', '查看邮件正文', 'low'],
        ['mail.view_attachments', 'mail', 'view_attachments', '查看邮件附件', 'low'],

        ['customer.view', 'customer', 'view', '查看客户中心', 'low'],
        ['customer.view_all', 'customer', 'view_all', '查看全部客户', 'high'],
        ['customer.view_department', 'customer', 'view_department', '查看本部门客户', 'medium'],
        ['customer.create', 'customer', 'create', '新建客户', 'medium'],
        ['customer.edit', 'customer', 'edit', '编辑客户', 'medium'],
        ['customer.delete', 'customer', 'delete', '删除客户', 'high'],
        ['customer.restore', 'customer', 'restore', '恢复客户', 'high'],
        ['customer.force_delete', 'customer', 'force_delete', '强制删除客户', 'dangerous'],
        ['customer.import', 'customer', 'import', '导入客户', 'high'],
        ['customer.export', 'customer', 'export', '导出客户', 'high'],
        ['customer.assign', 'customer', 'assign', '分配客户负责人', 'medium'],
        ['customer.batch', 'customer', 'batch', '客户批量操作/分组', 'medium'],
        ['customer.transfer_public', 'customer', 'transfer_public', '转入公海池', 'high'],
        ['customer.claim_public', 'customer', 'claim_public', '领取公海客户', 'medium'],
        ['customer.lead_pool_view', 'customer', 'lead_pool_view', '查看客户暂存池', 'low'],
        ['customer.lead_pool', 'customer', 'lead_pool', '管理客户暂存池', 'medium'],
        ['customer.merge', 'customer', 'merge', '客户合并/查重处理', 'high'],
        ['customer.graph_manage', 'customer', 'graph_manage', '管理客户关系图谱', 'medium'],
        ['customer.timeline_view', 'customer', 'timeline_view', '查看客户时间线', 'low'],
        ['customer.event_manage', 'customer', 'event_manage', '管理客户重要事件', 'medium'],
        ['customer.file_upload', 'customer', 'file_upload', '上传客户文件', 'medium'],
        ['customer.view_logs', 'customer', 'view_logs', '查看客户日志', 'medium'],
        ['customer.mail_summary', 'customer', 'mail_summary', '查看客户邮件摘要', 'low'],
        ['customer.quote_summary', 'customer', 'quote_summary', '查看客户报价摘要', 'low'],
        ['customer.plm_summary', 'customer', 'plm_summary', '查看客户 PLM 摘要', 'low'],
        ['customer.bom_summary', 'customer', 'bom_summary', '查看客户 BOM 摘要', 'low'],
        ['customer.dispatch_summary', 'customer', 'dispatch_summary', '查看客户派工摘要', 'low'],
        ['customer.order_summary', 'customer', 'order_summary', '查看客户订单摘要', 'low'],
        ['customer.material_summary', 'customer', 'material_summary', '查看客户资料摘要', 'low'],
        ['customer.view_email', 'field', 'customer.view_email', '查看客户邮箱', 'low'],
        ['customer.view_phone', 'field', 'customer.view_phone', '查看客户电话', 'low'],
        ['customer.view_whatsapp', 'field', 'customer.view_whatsapp', '查看 WhatsApp', 'low'],
        ['customer.view_address', 'field', 'customer.view_address', '查看客户地址', 'low'],

        ['contact.view', 'contact', 'view', '查看联系人', 'low'],
        ['contact.create', 'contact', 'create', '新建联系人', 'medium'],
        ['contact.edit', 'contact', 'edit', '编辑联系人', 'medium'],
        ['contact.delete', 'contact', 'delete', '删除联系人', 'high'],
        ['follow.view', 'follow', 'view', '查看跟进', 'low'],
        ['follow.create', 'follow', 'create', '新建跟进', 'medium'],
        ['follow.edit', 'follow', 'edit', '编辑跟进', 'medium'],
        ['follow.delete', 'follow', 'delete', '删除跟进', 'high'],
        ['follow.view_all', 'follow', 'view_all', '查看全部跟进', 'high'],

        ['task.view', 'task', 'view', '查看任务与提醒中心', 'low'],
        ['task.view_all', 'task', 'view_all', '查看全部任务', 'high'],
        ['task.view_department', 'task', 'view_department', '查看本部门任务', 'medium'],
        ['task.create', 'task', 'create', '新建任务', 'medium'],
        ['task.edit', 'task', 'edit', '编辑任务', 'medium'],
        ['task.delete', 'task', 'delete', '删除任务', 'high'],
        ['task.complete', 'task', 'complete', '标记任务完成', 'medium'],
        ['task.delay', 'task', 'delay', '延期任务', 'medium'],
        ['sample.view', 'sample', 'view', '查看样品寄送', 'low'],
        ['sample.create', 'sample', 'create', '新建样品寄送', 'medium'],
        ['sample.edit', 'sample', 'edit', '编辑样品寄送', 'medium'],
        ['sample.upload_image', 'sample', 'upload_image', '上传寄样图片', 'medium'],
        ['sample.delete_image', 'sample', 'delete_image', '删除寄样图片', 'medium'],
        ['sample.upload_file', 'sample', 'upload_file', '上传寄样附件', 'medium'],
        ['sample.delete_file', 'sample', 'delete_file', '删除寄样附件', 'medium'],
        ['sample.tracking', 'sample', 'tracking', '修改快递单号', 'medium'],
        ['sample.shipped', 'sample', 'shipped', '标记样品已寄出', 'medium'],
        ['sample.signed', 'sample', 'signed', '标记样品已签收', 'medium'],
        ['sample.export', 'sample', 'export', '导出样品寄送', 'high'],

        ['promotion.view', 'promotion', 'view', '推广中心：查看/创建/执行基础权限', 'low'],
        ['promotion.task_create', 'promotion', 'task_create', '创建/编辑推广任务', 'medium'],
        ['promotion.execute', 'promotion', 'execute', '执行推广任务和手动执行', 'medium'],
        ['promotion.manage', 'promotion', 'manage', '管理推广状态/失败处理', 'medium'],
        ['promotion.analytics', 'promotion', 'analytics', '查看推广分析', 'low'],
        ['promotion.create_project', 'promotion', 'create_project', '创建推广项目', 'medium'],
        ['promotion.edit_project', 'promotion', 'edit_project', '编辑推广项目', 'medium'],
        ['promotion.delete_project', 'promotion', 'delete_project', '删除推广项目', 'high'],
        ['promotion.create_group', 'promotion', 'create_group', '创建推广分组', 'medium'],
        ['promotion.edit_group', 'promotion', 'edit_group', '编辑推广分组', 'medium'],
        ['promotion.delete_group', 'promotion', 'delete_group', '删除推广分组', 'high'],
        ['promotion.move_customer', 'promotion', 'move_customer', '移动推广客户/联系人', 'medium'],
        ['promotion.export', 'promotion', 'export', '导出推广数据', 'high'],

        ['logs.view_own', 'logs', 'view_own', '查看本人日志', 'low'],
        ['logs.view_department', 'logs', 'view_department', '查看部门日志', 'medium'],
        ['logs.view_all', 'logs', 'view_all', '查看全部日志', 'high'],
        ['settings.view', 'settings', 'view', '查看设置', 'low'],
    ];
}

function crm_ensure_permissions(): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    $stmt = db()->prepare('INSERT INTO crm_permissions (permission_key, module, action, description, risk_level, created_at) VALUES (?, ?, ?, ?, ?, NOW()) ON DUPLICATE KEY UPDATE module=VALUES(module), action=VALUES(action), description=VALUES(description), risk_level=VALUES(risk_level)');
    foreach (crm_permission_definitions() as $permission) {
        $stmt->execute($permission);
    }
    db()->exec("INSERT IGNORE INTO crm_role_permissions (role_id, permission_key)
        SELECT r.id, p.permission_key FROM crm_roles r JOIN crm_permissions p
        WHERE r.role_key = 'super_admin' AND p.permission_key IN (SELECT permission_key FROM crm_permissions WHERE module IN ('dashboard','crm','crm_config','online','mail','customer','contact','follow','task','sample','promotion','logs','field'))");
    db()->exec("INSERT IGNORE INTO crm_role_permissions (role_id, permission_key)
        SELECT r.id, p.permission_key FROM crm_roles r JOIN crm_permissions p
        WHERE r.role_key = 'manager' AND p.permission_key IN (
            'dashboard.view','crm.preferences.edit','crm.config.view','online.view','online.view_all',
            'mail.view','mail.view_own','mail.sync','mail.send','mail.delete','mail.attachment_download','mail.link_customer','mail.account_bind_own','mail.account_manage_all','mail.signature_manage_own','mail.view_logs','mail.view_body','mail.view_attachments',
            'customer.view','customer.view_all','customer.view_department','customer.create','customer.edit','customer.delete','customer.restore','customer.import','customer.export','customer.assign','customer.batch','customer.transfer_public','customer.claim_public','customer.lead_pool_view','customer.lead_pool','customer.merge','customer.graph_manage','customer.timeline_view','customer.event_manage','customer.file_upload','customer.view_logs','customer.mail_summary','customer.quote_summary','customer.plm_summary','customer.bom_summary','customer.dispatch_summary','customer.order_summary','customer.material_summary','customer.view_email','customer.view_phone','customer.view_whatsapp','customer.view_address',
            'contact.view','contact.create','contact.edit','contact.delete','follow.view','follow.create','follow.edit','follow.delete','follow.view_all',
            'task.view','task.view_department','task.create','task.edit','task.complete','task.delay','sample.view','sample.create','sample.edit','sample.upload_image','sample.delete_image','sample.upload_file','sample.delete_file','sample.tracking','sample.shipped','sample.signed','sample.export',
            'promotion.view','promotion.task_create','promotion.execute','promotion.manage','promotion.analytics','promotion.create_project','promotion.edit_project','promotion.create_group','promotion.edit_group','promotion.move_customer',
            'logs.view_own','logs.view_department','settings.view'
        )");
    db()->exec("INSERT IGNORE INTO crm_role_permissions (role_id, permission_key)
        SELECT r.id, p.permission_key FROM crm_roles r JOIN crm_permissions p
        WHERE r.role_key IN ('sales','marketing') AND p.permission_key IN (
            'dashboard.view','crm.preferences.edit','online.view',
            'mail.view','mail.view_own','mail.sync','mail.send','mail.attachment_download','mail.link_customer','mail.account_bind_own','mail.signature_manage_own','mail.view_body','mail.view_attachments',
            'customer.view','customer.create','customer.edit','customer.batch','customer.claim_public','customer.mail_summary','customer.quote_summary','customer.material_summary','customer.view_email','customer.view_phone','customer.view_whatsapp','customer.view_address',
            'contact.view','contact.create','contact.edit','follow.view','follow.create','follow.edit',
            'task.view','task.create','task.edit','task.complete','task.delay','sample.view','sample.create','sample.edit','sample.upload_image','sample.delete_image','sample.upload_file','sample.delete_file','sample.tracking','sample.shipped','sample.signed',
            'promotion.view','promotion.task_create','promotion.execute','promotion.analytics','promotion.create_project','promotion.edit_project','promotion.create_group','promotion.edit_group','promotion.move_customer',
            'logs.view_own'
        )");
    db()->exec("INSERT IGNORE INTO crm_role_permissions (role_id, permission_key)
        SELECT r.id, p.permission_key FROM crm_roles r JOIN crm_permissions p
        WHERE r.role_key IN ('viewer','finance') AND p.permission_key IN ('dashboard.view','customer.view','contact.view','follow.view','promotion.view','mail.view','logs.view_own')");
    $aliasPairs = [
        'mail.view' => ['mail.view_own','mail.sync','mail.send','mail.compose','mail.reply','mail.attachment_download','mail.link_customer','mail.account_bind_own','mail.signature_manage_own','mail.view_body','mail.view_attachments','mail.view_logs'],
        'promotion.view' => ['promotion.task_create','promotion.execute','promotion.manage','promotion.analytics','promotion.create_project','promotion.edit_project','promotion.create_group','promotion.edit_group','promotion.move_customer'],
        'customer.view' => ['customer.mail_summary','customer.quote_summary','customer.plm_summary','customer.bom_summary','customer.dispatch_summary','customer.order_summary','customer.material_summary','customer.timeline_view','customer.view_email','customer.view_phone','customer.view_whatsapp','customer.view_address'],
    ];
    foreach ($aliasPairs as $parent => $children) {
        foreach ($children as $child) {
            db()->prepare("DELETE up FROM crm_user_permissions up
                LEFT JOIN crm_user_permissions parent_up ON parent_up.user_id = up.user_id AND parent_up.permission_key = ? AND parent_up.effect = 'allow'
                LEFT JOIN crm_users u ON u.id = up.user_id
                LEFT JOIN crm_role_permissions rp ON rp.role_id = u.role_id AND rp.permission_key = ?
                WHERE up.permission_key = ? AND up.effect = 'deny' AND (parent_up.user_id IS NOT NULL OR rp.role_id IS NOT NULL)")
                ->execute([$parent, $parent, $child]);
        }
    }
}

function crm_can(string $permission): bool
{
    crm_ensure_permissions();
    return $permission === '' || is_super_admin() || has_permission($permission);
}

function crm_require(string $permission): void
{
    crm_ensure_permissions();
    if (!crm_can($permission)) {
        if (basename($_SERVER['SCRIPT_NAME'] ?? '') === 'crm_api.php') {
            api_response(false, '权限不足', [], 'PERMISSION_DENIED');
        }
        http_response_code(403);
        exit('权限不足');
    }
}

function crm_allowed_modules(): array
{
    return array_filter(crm_modules(), fn($module) => crm_can($module['permission'] ?? ''));
}
