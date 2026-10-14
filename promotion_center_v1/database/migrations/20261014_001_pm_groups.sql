-- 推广中心 V1 阶段 1：客户分组。不建 campaigns / deliveries，不写邮件队列。

CREATE TABLE IF NOT EXISTS pm_groups (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(120) NOT NULL,
    color VARCHAR(16) NOT NULL DEFAULT '#3b4fd8',
    visibility VARCHAR(20) NOT NULL DEFAULT 'team',
    created_by INT UNSIGNED NOT NULL,
    deleted_at DATETIME NULL,
    deleted_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    active_name VARCHAR(120) AS (IF(deleted_at IS NULL, name, NULL)) STORED,
    PRIMARY KEY (id),
    UNIQUE KEY uq_pm_groups_active_name (active_name),
    KEY idx_pm_groups_owner (created_by, deleted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS pm_group_members (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    group_id BIGINT UNSIGNED NOT NULL,
    customer_id BIGINT UNSIGNED NOT NULL,
    added_by INT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_pm_group_member (group_id, customer_id),
    KEY idx_pm_member_customer (customer_id),
    KEY idx_pm_member_group (group_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS pm_audit_logs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    action VARCHAR(64) NOT NULL,
    group_id BIGINT UNSIGNED NULL,
    actor_id INT UNSIGNED NOT NULL,
    customer_count INT NOT NULL DEFAULT 0,
    detail_json JSON NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_pm_audit_group (group_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS pm_undo (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    token CHAR(32) NOT NULL,
    actor_id INT UNSIGNED NOT NULL,
    action VARCHAR(40) NOT NULL,
    payload_json JSON NOT NULL,
    expires_at DATETIME NOT NULL,
    used_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_pm_undo_token (token),
    KEY idx_pm_undo_actor (actor_id, expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
