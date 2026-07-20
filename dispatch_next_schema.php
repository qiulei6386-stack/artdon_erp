<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/artdon_sso_core.php';

function dispatch_next_db(): PDO
{
    $pdo = artdon_sso_db();
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    return $pdo;
}

function dispatch_next_column_exists(PDO $pdo, string $table, string $column): bool
{
    $st = $pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1");
    $st->execute([$table, $column]);
    return (bool)$st->fetch();
}

function dispatch_next_add_column_if_missing(PDO $pdo, string $table, string $column, string $definition): void
{
    if (!dispatch_next_column_exists($pdo, $table, $column)) {
        $pdo->exec("ALTER TABLE `{$table}` ADD COLUMN {$definition}");
    }
}

function dispatch_next_seed_step_templates(PDO $pdo): int
{
    $templates = [
        ['个人通用', 'personal', [
            '明确任务目标','整理所需资料','开始执行','中途检查','补充遗漏','完成并自检','记录结果','归档'
        ]],
        ['工程开发', 'engineering', [
            '确认客户需求 / 样品要求','核对型号、尺寸、功率、角度、颜色','结构评估 / 安装方式确认','绘制 2D / 3D 图纸','输出 BOM / 关键物料清单','成本初算 / 与报价联动','打样 / 试装','温升测试','光学测试 / IES','问题修改','客户确认资料','资料归档'
        ]],
        ['采购', 'purchase', [
            '确认采购需求','核对规格 / 型号 / 数量','查找供应商','询价 / 比价','确认样品或规格书','下采购单','跟进交期','到料检查','异常反馈','入库 / 交给对应部门','采购资料归档'
        ]],
        ['业务', 'sales', [
            '确认客户需求','整理产品资料 / 图片 / 参数','确认价格基础','生成报价','发报价给客户','跟进客户反馈','样品确认','订单条件确认','付款 / 定金确认','交期确认','出货跟进','客户回访'
        ]],
        ['跟单', 'followup', [
            '核对订单资料','核对型号 / 数量 / 颜色 / 包装','确认交期','跟进物料到位','跟进生产排期','跟进车间进度','包装资料确认','验货 / 拍照','出货文件准备','物流 / 快递 / 货代确认','尾款 / 回款提醒','完成归档'
        ]],
    ];
    $created = 0;
    foreach ($templates as $index => $tpl) {
        [$name, $type, $items] = $tpl;
        $st = $pdo->prepare("SELECT id FROM dispatch_next_step_templates WHERE template_type=? AND scope='system' AND is_system=1 LIMIT 1");
        $st->execute([$type]);
        $templateId = (int)$st->fetchColumn();
        if ($templateId <= 0) {
            $pdo->prepare("INSERT INTO dispatch_next_step_templates(template_name,template_type,scope,department,owner_id,is_system,is_active,sort_order,created_by,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?,NOW(),NOW())")
                ->execute([$name, $type, 'system', null, null, 1, 1, ($index + 1) * 10, 0]);
            $templateId = (int)$pdo->lastInsertId();
            $created++;
        }
        $cnt = $pdo->prepare("SELECT COUNT(*) FROM dispatch_next_step_template_items WHERE template_id=?");
        $cnt->execute([$templateId]);
        if ((int)$cnt->fetchColumn() > 0) continue;
        $ins = $pdo->prepare("INSERT INTO dispatch_next_step_template_items(template_id,step_name,default_owner_role,default_due_offset_days,note,sort_order,created_at,updated_at) VALUES(?,?,?,?,?,?,NOW(),NOW())");
        foreach ($items as $i => $stepName) {
            $ins->execute([$templateId, $stepName, '', null, '', ($i + 1) * 10]);
        }
    }
    return $created;
}

function dispatch_next_init_schema(): array
{
    $pdo = dispatch_next_db();
    $sql = [];
    $sql[] = "CREATE TABLE IF NOT EXISTS dispatch_next_tasks (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        task_no VARCHAR(40) NOT NULL,
        task_type ENUM('personal','dispatch','private') NOT NULL DEFAULT 'personal',
        dispatch_mode ENUM('single','multi','plan','recurring') NOT NULL DEFAULT 'single',
        parent_group_id BIGINT UNSIGNED NULL,
        title VARCHAR(240) NOT NULL,
        project VARCHAR(180) NULL,
        description TEXT NULL,
        priority ENUM('normal','important','urgent','today') NOT NULL DEFAULT 'normal',
        status ENUM('pending_accept','accepted','in_progress','paused','submitted','returned','rejected','done','cancelled') NOT NULL DEFAULT 'in_progress',
        created_by INT UNSIGNED NOT NULL,
        assigned_to INT UNSIGNED NOT NULL,
        helper_ids_json JSON NULL,
        task_date DATE NOT NULL,
        due_at DATETIME NULL,
        accepted_at DATETIME NULL,
        started_at DATETIME NULL,
        completed_at DATETIME NULL,
        cancelled_at DATETIME NULL,
        progress TINYINT UNSIGNED NOT NULL DEFAULT 0,
        is_read TINYINT(1) NOT NULL DEFAULT 0,
        read_at DATETIME NULL,
        remind_count INT UNSIGNED NOT NULL DEFAULT 0,
        last_reminded_at DATETIME NULL,
        linked_system VARCHAR(60) NULL,
        linked_table VARCHAR(80) NULL,
        linked_id VARCHAR(80) NULL,
        linked_title VARCHAR(240) NULL,
        linked_json JSON NULL,
        extra_json JSON NULL,
        sort_order INT NOT NULL DEFAULT 0,
        is_deleted TINYINT(1) NOT NULL DEFAULT 0,
        deleted_at DATETIME NULL,
        deleted_by INT UNSIGNED NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_dispatch_next_task_no (task_no),
        KEY idx_dispatch_next_tasks_date (task_date),
        KEY idx_dispatch_next_tasks_assigned (assigned_to, task_date),
        KEY idx_dispatch_next_tasks_created (created_by, task_date),
        KEY idx_dispatch_next_tasks_group (parent_group_id),
        KEY idx_dispatch_next_tasks_status (status, is_deleted)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    $sql[] = "CREATE TABLE IF NOT EXISTS dispatch_next_groups (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        group_no VARCHAR(40) NOT NULL,
        group_type ENUM('multi','recurring') NOT NULL DEFAULT 'multi',
        title VARCHAR(240) NOT NULL,
        project VARCHAR(180) NULL,
        description TEXT NULL,
        created_by INT UNSIGNED NOT NULL,
        assignee_ids_json JSON NULL,
        total_count INT UNSIGNED NOT NULL DEFAULT 0,
        done_count INT UNSIGNED NOT NULL DEFAULT 0,
        status VARCHAR(40) NOT NULL DEFAULT 'active',
        task_date DATE NOT NULL,
        due_at DATETIME NULL,
        recurring_rule_json JSON NULL,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_dispatch_next_group_no (group_no),
        KEY idx_dispatch_next_groups_date (task_date),
        KEY idx_dispatch_next_groups_type (group_type, is_active)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    $sql[] = "CREATE TABLE IF NOT EXISTS dispatch_next_comments (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        task_id BIGINT UNSIGNED NOT NULL,
        user_id INT UNSIGNED NOT NULL,
        comment TEXT NOT NULL,
        progress TINYINT UNSIGNED NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_dispatch_next_comments_task (task_id, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    $sql[] = "CREATE TABLE IF NOT EXISTS dispatch_next_attachments (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        task_id BIGINT UNSIGNED NOT NULL,
        comment_id BIGINT UNSIGNED NULL,
        user_id INT UNSIGNED NOT NULL,
        file_kind ENUM('image','attachment') NOT NULL DEFAULT 'attachment',
        file_name VARCHAR(255) NOT NULL,
        file_path VARCHAR(500) NOT NULL,
        file_type VARCHAR(120) NULL,
        file_size BIGINT UNSIGNED NOT NULL DEFAULT 0,
        expires_at DATETIME NULL,
        is_deleted TINYINT(1) NOT NULL DEFAULT 0,
        deleted_at DATETIME NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_dispatch_next_attachments_task (task_id, is_deleted),
        KEY idx_dispatch_next_attachments_expire (expires_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    $sql[] = "CREATE TABLE IF NOT EXISTS dispatch_next_steps (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        task_id BIGINT UNSIGNED NULL,
        group_id BIGINT UNSIGNED NULL,
        step_name VARCHAR(240) NOT NULL,
        owner_id INT UNSIGNED NULL,
        status ENUM('pending','in_progress','blocked','done','cancelled') NOT NULL DEFAULT 'pending',
        due_at DATETIME NULL,
        note TEXT NULL,
        sort_order INT NOT NULL DEFAULT 0,
        child_task_id BIGINT UNSIGNED NULL,
        started_at DATETIME NULL,
        completed_at DATETIME NULL,
        created_by INT UNSIGNED NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        is_deleted TINYINT(1) NOT NULL DEFAULT 0,
        KEY idx_dispatch_next_steps_task (task_id, sort_order),
        KEY idx_dispatch_next_steps_group (group_id, sort_order)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    $sql[] = "CREATE TABLE IF NOT EXISTS dispatch_next_step_templates (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        template_name VARCHAR(160) NOT NULL,
        template_type VARCHAR(40) NOT NULL DEFAULT 'custom',
        scope VARCHAR(30) NOT NULL DEFAULT 'private',
        department VARCHAR(120) NULL,
        owner_id INT UNSIGNED NULL,
        is_system TINYINT(1) NOT NULL DEFAULT 0,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        sort_order INT NOT NULL DEFAULT 0,
        created_by INT UNSIGNED NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        KEY idx_dispatch_next_step_templates_type (template_type, scope, is_active),
        KEY idx_dispatch_next_step_templates_owner (owner_id, is_active)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    $sql[] = "CREATE TABLE IF NOT EXISTS dispatch_next_step_template_items (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        template_id BIGINT UNSIGNED NOT NULL,
        step_name VARCHAR(240) NOT NULL,
        default_owner_role VARCHAR(80) NULL,
        default_due_offset_days INT NULL,
        note TEXT NULL,
        sort_order INT NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        KEY idx_dispatch_next_step_template_items_tpl (template_id, sort_order)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    $sql[] = "CREATE TABLE IF NOT EXISTS dispatch_next_notifications (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        recipient_id INT UNSIGNED NOT NULL,
        sender_id INT UNSIGNED NULL,
        task_id BIGINT UNSIGNED NULL,
        type ENUM('new_dispatch','urge','accepted','done','returned') NOT NULL DEFAULT 'new_dispatch',
        title VARCHAR(220) NOT NULL,
        message TEXT NULL,
        is_read TINYINT(1) NOT NULL DEFAULT 0,
        read_at DATETIME NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_dispatch_next_notifications_recipient (recipient_id, is_read, created_at),
        KEY idx_dispatch_next_notifications_task (task_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    $sql[] = "CREATE TABLE IF NOT EXISTS dispatch_next_logs (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        task_id BIGINT UNSIGNED NULL,
        user_id INT UNSIGNED NOT NULL,
        action_type VARCHAR(80) NOT NULL,
        field_name VARCHAR(80) NULL,
        old_value TEXT NULL,
        new_value TEXT NULL,
        note TEXT NULL,
        change_count INT UNSIGNED NOT NULL DEFAULT 1,
        ip VARCHAR(80) NULL,
        user_agent VARCHAR(255) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NULL,
        KEY idx_dispatch_next_logs_task (task_id, created_at),
        KEY idx_dispatch_next_logs_user (user_id, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    $sql[] = "CREATE TABLE IF NOT EXISTS dispatch_next_user_prefs (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id INT UNSIGNED NOT NULL,
        pref_key VARCHAR(120) NOT NULL,
        pref_value MEDIUMTEXT NULL,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_dispatch_next_user_pref (user_id, pref_key)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    $sql[] = "CREATE TABLE IF NOT EXISTS dispatch_next_task_values (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        task_id BIGINT UNSIGNED NOT NULL,
        field_key VARCHAR(120) NOT NULL,
        field_value MEDIUMTEXT NULL,
        updated_by INT UNSIGNED NULL,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_dispatch_next_task_value (task_id, field_key),
        KEY idx_dispatch_next_task_values_field (field_key)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    $sql[] = "CREATE TABLE IF NOT EXISTS dispatch_next_task_orders (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id INT UNSIGNED NOT NULL,
        task_id BIGINT UNSIGNED NOT NULL,
        table_type ENUM('personal','dispatch','done') NOT NULL DEFAULT 'personal',
        sort_order INT NOT NULL DEFAULT 0,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_dispatch_next_task_order (user_id, task_id, table_type),
        KEY idx_dispatch_next_task_orders_user_type (user_id, table_type, sort_order)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    $sql[] = "CREATE TABLE IF NOT EXISTS dispatch_next_custom_fields (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id INT UNSIGNED NOT NULL,
        field_key VARCHAR(120) NOT NULL,
        field_label VARCHAR(120) NOT NULL,
        field_type ENUM('text','textarea','number','date','datetime','select','user') NOT NULL DEFAULT 'text',
        options_json JSON NULL,
        is_enabled TINYINT(1) NOT NULL DEFAULT 1,
        sort_order INT NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_dispatch_next_custom_field (user_id, field_key),
        KEY idx_dispatch_next_custom_fields_user (user_id, is_enabled, sort_order)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    foreach ($sql as $stmt) {
        $pdo->exec($stmt);
    }
    dispatch_next_add_column_if_missing($pdo, 'dispatch_next_steps', 'group_id', 'group_id BIGINT UNSIGNED NULL AFTER task_id');
    dispatch_next_add_column_if_missing($pdo, 'dispatch_next_steps', 'created_by', 'created_by INT UNSIGNED NULL AFTER completed_at');
    dispatch_next_add_column_if_missing($pdo, 'dispatch_next_steps', 'is_deleted', 'is_deleted TINYINT(1) NOT NULL DEFAULT 0 AFTER updated_at');
    dispatch_next_add_column_if_missing($pdo, 'dispatch_next_logs', 'change_count', 'change_count INT UNSIGNED NOT NULL DEFAULT 1 AFTER note');
    $pdo->exec("ALTER TABLE dispatch_next_steps MODIFY task_id BIGINT UNSIGNED NULL");
    $seeded = dispatch_next_seed_step_templates($pdo);
    return ['tables' => 13, 'prefix' => 'dispatch_next_', 'database' => (string)$pdo->query('SELECT DATABASE()')->fetchColumn(), 'step_templates_seeded' => $seeded];
}

if (basename((string)($_SERVER['SCRIPT_NAME'] ?? '')) === 'dispatch_next_schema.php') {
    artdon_sso_require_page('dispatch');
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => true, 'data' => dispatch_next_init_schema()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
