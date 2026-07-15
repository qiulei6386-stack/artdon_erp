<?php
require_once __DIR__ . '/radar_config.php';
require_once __DIR__ . '/radar_permissions.php';
require_once __DIR__ . '/crm_customer.php';

function radar_table_exists(string $table): bool
{
    $stmt = db()->prepare('SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
    $stmt->execute([$table]);
    return (int)$stmt->fetchColumn() > 0;
}

function radar_column_exists(string $table, string $column): bool
{
    $stmt = db()->prepare('SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
    $stmt->execute([$table, $column]);
    return (int)$stmt->fetchColumn() > 0;
}

function radar_log(string $action, $content = null, bool $success = true, string $error = ''): void
{
    if (function_exists('crm_log_event')) crm_log_event('radar', $action, 'radar', '', null, $content, $success, $error);
    if (!radar_table_exists('crm_radar_logs')) return;
    $user = function_exists('current_user') ? (current_user() ?: []) : [];
    db()->prepare('INSERT INTO crm_radar_logs (user_id, user_account, module_key, action_key, content_json, ip_address, success, error_message, created_at) VALUES (?, ?, "radar", ?, ?, ?, ?, ?, NOW())')
        ->execute([
            (int)($user['id'] ?? 0) ?: null,
            (string)($user['username'] ?? ''),
            $action,
            json_encode($content, JSON_UNESCAPED_UNICODE),
            $_SERVER['REMOTE_ADDR'] ?? '',
            $success ? 1 : 0,
            $error,
        ]);
}

function radar_secret_key(): string
{
    $config = function_exists('db_config') ? db_config() : [];
    $seed = (string)($config['app_key'] ?? $config['db_name'] ?? __DIR__);
    return hash('sha256', 'radar:' . $seed, true);
}

function radar_secret_encrypt(string $value): string
{
    if ($value === '') return '';
    if (!function_exists('openssl_encrypt')) return $value;
    $iv = random_bytes(16);
    $cipher = openssl_encrypt($value, 'AES-256-CBC', radar_secret_key(), OPENSSL_RAW_DATA, $iv);
    return base64_encode($iv . ($cipher ?: ''));
}

function radar_secret_decrypt(?string $value): string
{
    $value = (string)$value;
    if ($value === '') return '';
    if (!function_exists('openssl_decrypt')) return $value;
    $raw = base64_decode($value, true);
    if ($raw === false || strlen($raw) <= 16) return $value;
    $iv = substr($raw, 0, 16);
    $cipher = substr($raw, 16);
    $plain = openssl_decrypt($cipher, 'AES-256-CBC', radar_secret_key(), OPENSSL_RAW_DATA, $iv);
    return is_string($plain) ? $plain : '';
}

function radar_table_definitions(): array
{
    return [
        'crm_radar_seed_customers' => [
            'sql' => "CREATE TABLE IF NOT EXISTS crm_radar_seed_customers (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                customer_id INT UNSIGNED NULL,
                seed_name VARCHAR(190) NOT NULL,
                source_type VARCHAR(60) NOT NULL DEFAULT 'manual',
                country VARCHAR(120) NULL,
                industry VARCHAR(190) NULL,
                website VARCHAR(255) NULL,
                note VARCHAR(500) NULL,
                status VARCHAR(40) NOT NULL DEFAULT 'active',
                owner_user_id INT UNSIGNED NULL,
                created_by INT UNSIGNED NULL,
                updated_by INT UNSIGNED NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                deleted_at DATETIME NULL,
                KEY idx_radar_seed_customer (customer_id),
                KEY idx_radar_seed_status (status, deleted_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            'columns' => [
                'deleted_at' => 'DATETIME NULL',
                'owner_user_id' => 'INT UNSIGNED NULL',
                'local_name' => 'VARCHAR(190) NULL',
                'city' => 'VARCHAR(190) NULL',
                'company_type' => 'VARCHAR(120) NULL',
                'model_key' => "VARCHAR(60) NOT NULL DEFAULT 'direct_buyer'",
                'company_intro' => 'TEXT NULL',
                'main_products' => 'TEXT NULL',
                'project_types' => 'TEXT NULL',
                'inquiry_products' => 'TEXT NULL',
                'purchase_products' => 'TEXT NULL',
                'deal_status' => "VARCHAR(40) NOT NULL DEFAULT 'unknown'",
                'cooperation_status' => 'VARCHAR(80) NULL',
                'deal_amount' => 'DECIMAL(14,2) NOT NULL DEFAULT 0',
                'cooperation_duration' => 'VARCHAR(120) NULL',
                'match_reason' => 'TEXT NULL',
                'mismatch_reason' => 'TEXT NULL',
                'sample_type' => "VARCHAR(20) NOT NULL DEFAULT 'positive'",
                'sample_weight' => 'INT NOT NULL DEFAULT 50',
                'negative_reason' => 'VARCHAR(120) NULL',
                'contact_name' => 'VARCHAR(190) NULL',
                'contact_position' => 'VARCHAR(190) NULL',
                'contact_email' => 'VARCHAR(190) NULL',
                'contact_phone' => 'VARCHAR(120) NULL',
                'contact_whatsapp' => 'VARCHAR(120) NULL',
                'contact_linkedin' => 'VARCHAR(255) NULL',
                'contact_facebook' => 'VARCHAR(255) NULL',
            ],
        ],
        'crm_radar_profiles' => [
            'sql' => "CREATE TABLE IF NOT EXISTS crm_radar_profiles (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                seed_id BIGINT UNSIGNED NULL,
                customer_id INT UNSIGNED NULL,
                profile_name VARCHAR(190) NOT NULL,
                profile_json JSON NULL,
                keyword_json JSON NULL,
                excluded_json JSON NULL,
                status VARCHAR(40) NOT NULL DEFAULT 'draft',
                created_by INT UNSIGNED NULL,
                updated_by INT UNSIGNED NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                KEY idx_radar_profile_seed (seed_id),
                KEY idx_radar_profile_customer (customer_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            'columns' => [
                'excluded_json' => 'JSON NULL',
                'model_key' => "VARCHAR(60) NOT NULL DEFAULT 'direct_buyer'",
                'profile_title' => 'VARCHAR(190) NULL',
                'sample_count' => 'INT NOT NULL DEFAULT 0',
                'total_weight' => 'INT NOT NULL DEFAULT 0',
                'company_keywords_json' => 'JSON NULL',
                'product_keywords_json' => 'JSON NULL',
                'project_keywords_json' => 'JSON NULL',
                'city_keywords_json' => 'JSON NULL',
                'position_keywords_json' => 'JSON NULL',
                'contact_keywords_json' => 'JSON NULL',
                'positive_features_json' => 'JSON NULL',
                'negative_features_json' => 'JSON NULL',
                'recommended_terms_json' => 'JSON NULL',
                'excluded_terms_json' => 'JSON NULL',
                'scoring_rules_json' => 'JSON NULL',
                'seed_ids_json' => 'JSON NULL',
                'manual_override' => 'TINYINT(1) NOT NULL DEFAULT 0',
                'generated_at' => 'DATETIME NULL',
            ],
        ],
        'crm_radar_search_tasks' => [
            'sql' => "CREATE TABLE IF NOT EXISTS crm_radar_search_tasks (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                task_name VARCHAR(190) NOT NULL,
                seed_id BIGINT UNSIGNED NULL,
                profile_id BIGINT UNSIGNED NULL,
                country VARCHAR(120) NULL,
                search_count INT NOT NULL DEFAULT 0,
                min_score INT NOT NULL DEFAULT 0,
                task_status VARCHAR(40) NOT NULL DEFAULT 'draft',
                run_mode VARCHAR(40) NOT NULL DEFAULT 'manual',
                started_at DATETIME NULL,
                finished_at DATETIME NULL,
                created_by INT UNSIGNED NULL,
                updated_by INT UNSIGNED NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                KEY idx_radar_task_status (task_status),
                KEY idx_radar_task_seed (seed_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            'columns' => [
                'finished_at' => 'DATETIME NULL',
                'city' => 'VARCHAR(190) NULL',
                'model_key' => "VARCHAR(60) NOT NULL DEFAULT 'direct_buyer'",
                'seed_ids_json' => 'JSON NULL',
                'target_products' => 'TEXT NULL',
                'target_project_types' => 'TEXT NULL',
                'languages_json' => 'JSON NULL',
                'keyword_ids_json' => 'JSON NULL',
                'keywords_json' => 'JSON NULL',
                'exclude_keywords_json' => 'JSON NULL',
                'target_candidate_count' => 'INT NOT NULL DEFAULT 0',
                'must_have_website' => 'TINYINT(1) NOT NULL DEFAULT 1',
                'must_have_email' => 'TINYINT(1) NOT NULL DEFAULT 0',
                'must_have_contact' => 'TINYINT(1) NOT NULL DEFAULT 0',
                'allow_design_studio' => 'TINYINT(1) NOT NULL DEFAULT 1',
                'exclude_factory' => 'TINYINT(1) NOT NULL DEFAULT 1',
                'exclude_retailer' => 'TINYINT(1) NOT NULL DEFAULT 1',
                'exclude_decorative' => 'TINYINT(1) NOT NULL DEFAULT 1',
                'exclude_brand_branch' => 'TINYINT(1) NOT NULL DEFAULT 1',
                'check_crm_duplicate' => 'TINYINT(1) NOT NULL DEFAULT 1',
                'enrich_contacts' => 'TINYINT(1) NOT NULL DEFAULT 0',
                'verify_email' => 'TINYINT(1) NOT NULL DEFAULT 0',
                'auto_to_crm' => 'TINYINT(1) NOT NULL DEFAULT 0',
                'auto_send_email' => 'TINYINT(1) NOT NULL DEFAULT 0',
                'single_task_cost_limit' => 'DECIMAL(12,4) NOT NULL DEFAULT 0',
                'daily_cost_limit' => 'DECIMAL(12,4) NOT NULL DEFAULT 0',
                'daily_candidate_limit' => 'INT NOT NULL DEFAULT 0',
                'execute_mode' => "VARCHAR(40) NOT NULL DEFAULT 'manual'",
                'execute_at' => 'DATETIME NULL',
                'repeat_rule' => 'VARCHAR(80) NULL',
                'executor_user_id' => 'INT UNSIGNED NULL',
                'progress_percent' => 'INT NOT NULL DEFAULT 0',
                'searched_pages' => 'INT NOT NULL DEFAULT 0',
                'found_companies' => 'INT NOT NULL DEFAULT 0',
                'failed_count' => 'INT NOT NULL DEFAULT 0',
                'last_error' => 'VARCHAR(1000) NULL',
                'locked_at' => 'DATETIME NULL',
                'lock_token' => 'VARCHAR(80) NULL',
                'paused_at' => 'DATETIME NULL',
                'cancelled_at' => 'DATETIME NULL',
                'template_id' => 'BIGINT UNSIGNED NULL',
                'template_code' => 'VARCHAR(80) NULL',
                'sort_order' => 'INT NOT NULL DEFAULT 0',
                'deleted_at' => 'DATETIME NULL',
            ],
        ],
        'crm_radar_search_templates' => [
            'sql' => "CREATE TABLE IF NOT EXISTS crm_radar_search_templates (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                template_code VARCHAR(80) NOT NULL,
                template_name VARCHAR(190) NOT NULL,
                country VARCHAR(120) NULL,
                country_code VARCHAR(20) NULL,
                country_local VARCHAR(120) NULL,
                model_key VARCHAR(60) NOT NULL DEFAULT 'direct_buyer',
                search_service_key VARCHAR(80) NOT NULL DEFAULT 'brave',
                status VARCHAR(40) NOT NULL DEFAULT 'active',
                is_system TINYINT(1) NOT NULL DEFAULT 0,
                is_default TINYINT(1) NOT NULL DEFAULT 0,
                mode_default VARCHAR(40) NOT NULL DEFAULT 'standard',
                config_json JSON NULL,
                preset_keywords_json JSON NULL,
                exclude_keywords_json JSON NULL,
                warning_text VARCHAR(1000) NULL,
                use_count INT NOT NULL DEFAULT 0,
                generated_keyword_count INT NOT NULL DEFAULT 0,
                brave_call_count INT NOT NULL DEFAULT 0,
                result_count INT NOT NULL DEFAULT 0,
                company_count INT NOT NULL DEFAULT 0,
                grade_a_count INT NOT NULL DEFAULT 0,
                grade_b_count INT NOT NULL DEFAULT 0,
                grade_c_count INT NOT NULL DEFAULT 0,
                grade_d_count INT NOT NULL DEFAULT 0,
                precise_count INT NOT NULL DEFAULT 0,
                converted_count INT NOT NULL DEFAULT 0,
                avg_valid_customer_cost DECIMAL(12,4) NOT NULL DEFAULT 0,
                created_by INT UNSIGNED NULL,
                updated_by INT UNSIGNED NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                deleted_at DATETIME NULL,
                UNIQUE KEY uk_radar_template_code (template_code),
                KEY idx_radar_template_status (status, deleted_at),
                KEY idx_radar_template_country (country_code, model_key)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            'columns' => [
                'is_default' => 'TINYINT(1) NOT NULL DEFAULT 0',
                'avg_valid_customer_cost' => 'DECIMAL(12,4) NOT NULL DEFAULT 0',
            ],
        ],
        'crm_radar_template_usage' => [
            'sql' => "CREATE TABLE IF NOT EXISTS crm_radar_template_usage (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                template_id BIGINT UNSIGNED NULL,
                template_code VARCHAR(80) NULL,
                task_id BIGINT UNSIGNED NULL,
                mode_key VARCHAR(40) NULL,
                keyword_count INT NOT NULL DEFAULT 0,
                search_service_key VARCHAR(80) NULL,
                created_by INT UNSIGNED NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY idx_radar_template_usage_template (template_id),
                KEY idx_radar_template_usage_task (task_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        ],
        'crm_radar_search_keywords' => [
            'sql' => "CREATE TABLE IF NOT EXISTS crm_radar_search_keywords (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                task_id BIGINT UNSIGNED NULL,
                profile_id BIGINT UNSIGNED NULL,
                keyword VARCHAR(255) NOT NULL,
                keyword_type VARCHAR(60) NOT NULL DEFAULT 'include',
                language VARCHAR(40) NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY idx_radar_keyword_task (task_id),
                KEY idx_radar_keyword_profile (profile_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            'columns' => [
                'language' => 'VARCHAR(40) NULL',
                'country' => 'VARCHAR(120) NULL',
                'city' => 'VARCHAR(190) NULL',
                'model_key' => "VARCHAR(60) NOT NULL DEFAULT 'direct_buyer'",
                'keyword_category' => "VARCHAR(60) NOT NULL DEFAULT 'company_type'",
                'weight' => 'INT NOT NULL DEFAULT 50',
                'status' => "VARCHAR(40) NOT NULL DEFAULT 'active'",
                'source' => "VARCHAR(80) NOT NULL DEFAULT 'manual'",
                'usage_count' => 'INT NOT NULL DEFAULT 0',
                'found_count' => 'INT NOT NULL DEFAULT 0',
                'valid_count' => 'INT NOT NULL DEFAULT 0',
                'average_score' => 'DECIMAL(8,2) NOT NULL DEFAULT 0',
                'created_by' => 'INT UNSIGNED NULL',
                'updated_by' => 'INT UNSIGNED NULL',
                'updated_at' => 'DATETIME NULL',
                'deleted_at' => 'DATETIME NULL',
            ],
        ],
        'crm_radar_job_queue' => [
            'sql' => "CREATE TABLE IF NOT EXISTS crm_radar_job_queue (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                task_id BIGINT UNSIGNED NULL,
                job_type VARCHAR(80) NOT NULL,
                job_status VARCHAR(40) NOT NULL DEFAULT 'pending',
                payload_json JSON NULL,
                attempts INT NOT NULL DEFAULT 0,
                max_attempts INT NOT NULL DEFAULT 3,
                locked_at DATETIME NULL,
                scheduled_at DATETIME NULL,
                last_error VARCHAR(1000) NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                KEY idx_radar_job_status (job_status, scheduled_at),
                KEY idx_radar_job_task (task_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            'columns' => [
                'scheduled_at' => 'DATETIME NULL',
                'started_at' => 'DATETIME NULL',
                'finished_at' => 'DATETIME NULL',
                'next_retry_at' => 'DATETIME NULL',
                'lock_token' => 'VARCHAR(80) NULL',
                'result_json' => 'JSON NULL',
            ],
        ],
        'crm_radar_search_services' => [
            'sql' => "CREATE TABLE IF NOT EXISTS crm_radar_search_services (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                service_key VARCHAR(80) NOT NULL,
                service_name VARCHAR(120) NOT NULL,
                api_url VARCHAR(500) NULL,
                api_key_encrypted TEXT NULL,
                result_limit INT NOT NULL DEFAULT 5,
                daily_limit INT NOT NULL DEFAULT 100,
                is_enabled TINYINT(1) NOT NULL DEFAULT 0,
                priority_order INT NOT NULL DEFAULT 100,
                timeout_seconds INT NOT NULL DEFAULT 15,
                retry_count INT NOT NULL DEFAULT 2,
                cost_per_call DECIMAL(12,4) NOT NULL DEFAULT 0,
                config_json JSON NULL,
                created_by INT UNSIGNED NULL,
                updated_by INT UNSIGNED NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uk_radar_service_key (service_key),
                KEY idx_radar_service_enabled (is_enabled, priority_order)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            'columns' => ['config_json' => 'JSON NULL'],
        ],
        'crm_radar_raw_results' => [
            'sql' => "CREATE TABLE IF NOT EXISTS crm_radar_raw_results (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                task_id BIGINT UNSIGNED NOT NULL,
                job_id BIGINT UNSIGNED NULL,
                keyword VARCHAR(255) NULL,
                service_key VARCHAR(80) NULL,
                title VARCHAR(500) NULL,
                url VARCHAR(1000) NULL,
                snippet TEXT NULL,
                rank_no INT NOT NULL DEFAULT 0,
                query_time DATETIME NULL,
                country VARCHAR(120) NULL,
                city VARCHAR(190) NULL,
                language VARCHAR(40) NULL,
                fetch_status VARCHAR(40) NOT NULL DEFAULT 'pending',
                http_status INT NULL,
                fetched_at DATETIME NULL,
                page_title VARCHAR(500) NULL,
                page_description VARCHAR(1000) NULL,
                raw_text_summary MEDIUMTEXT NULL,
                is_company TINYINT(1) NOT NULL DEFAULT 0,
                fail_reason VARCHAR(1000) NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                KEY idx_radar_raw_task (task_id),
                KEY idx_radar_raw_url (url(190)),
                KEY idx_radar_raw_status (fetch_status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            'columns' => [
                'fail_reason' => 'VARCHAR(1000) NULL',
                'company_key' => 'VARCHAR(190) NULL',
                'candidate_id' => 'BIGINT UNSIGNED NULL',
                'page_type' => 'VARCHAR(80) NULL',
                'analysis_json' => 'JSON NULL',
            ],
        ],
        'crm_radar_candidates' => [
            'sql' => "CREATE TABLE IF NOT EXISTS crm_radar_candidates (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                task_id BIGINT UNSIGNED NULL,
                company_name VARCHAR(255) NOT NULL,
                country VARCHAR(120) NULL,
                website VARCHAR(255) NULL,
                industry VARCHAR(190) NULL,
                radar_score DECIMAL(8,2) NOT NULL DEFAULT 0,
                grade VARCHAR(10) NULL,
                review_status VARCHAR(40) NOT NULL DEFAULT 'pending',
                assigned_user_id INT UNSIGNED NULL,
                converted_customer_id INT UNSIGNED NULL,
                summary VARCHAR(1000) NULL,
                created_by INT UNSIGNED NULL,
                updated_by INT UNSIGNED NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                deleted_at DATETIME NULL,
                KEY idx_radar_candidate_task (task_id),
                KEY idx_radar_candidate_review (review_status, deleted_at),
                KEY idx_radar_candidate_grade (grade)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            'columns' => [
                'deleted_at' => 'DATETIME NULL',
                'local_name' => 'VARCHAR(255) NULL',
                'domain' => 'VARCHAR(190) NULL',
                'city' => 'VARCHAR(190) NULL',
                'address' => 'VARCHAR(500) NULL',
                'company_type' => 'VARCHAR(120) NULL',
                'model_key' => "VARCHAR(60) NOT NULL DEFAULT 'direct_buyer'",
                'founded_year' => 'VARCHAR(40) NULL',
                'company_intro' => 'TEXT NULL',
                'main_products' => 'TEXT NULL',
                'product_keywords_json' => 'JSON NULL',
                'project_types' => 'TEXT NULL',
                'service_industries' => 'TEXT NULL',
                'represented_brands' => 'TEXT NULL',
                'has_own_brand' => 'TINYINT(1) NOT NULL DEFAULT 0',
                'has_showroom' => 'TINYINT(1) NOT NULL DEFAULT 0',
                'has_projects' => 'TINYINT(1) NOT NULL DEFAULT 0',
                'has_design_team' => 'TINYINT(1) NOT NULL DEFAULT 0',
                'has_sales_team' => 'TINYINT(1) NOT NULL DEFAULT 0',
                'has_procurement_role' => 'TINYINT(1) NOT NULL DEFAULT 0',
                'contact_name' => 'VARCHAR(190) NULL',
                'contact_position' => 'VARCHAR(190) NULL',
                'email' => 'VARCHAR(190) NULL',
                'phone' => 'VARCHAR(120) NULL',
                'whatsapp' => 'VARCHAR(120) NULL',
                'linkedin' => 'VARCHAR(255) NULL',
                'facebook' => 'VARCHAR(255) NULL',
                'instagram' => 'VARCHAR(255) NULL',
                'source_url' => 'VARCHAR(1000) NULL',
                'source_title' => 'VARCHAR(500) NULL',
                'crawled_at' => 'DATETIME NULL',
                'last_verified_at' => 'DATETIME NULL',
                'data_completeness' => 'INT NOT NULL DEFAULT 0',
                'crm_duplicate_status' => "VARCHAR(80) NOT NULL DEFAULT 'new_customer'",
                'crm_duplicate_customer_id' => 'INT UNSIGNED NULL',
                'crm_duplicate_json' => 'JSON NULL',
                'match_reasons_json' => 'JSON NULL',
                'risk_warnings_json' => 'JSON NULL',
                'field_meta_json' => 'JSON NULL',
                'is_real_company' => 'TINYINT(1) NOT NULL DEFAULT 0',
                'has_independent_website' => 'TINYINT(1) NOT NULL DEFAULT 0',
                'is_vietnam_market' => 'TINYINT(1) NOT NULL DEFAULT 0',
                'excluded_reason' => 'VARCHAR(255) NULL',
                'reviewed_by' => 'INT UNSIGNED NULL',
                'reviewed_at' => 'DATETIME NULL',
                'converted_by' => 'INT UNSIGNED NULL',
                'converted_at' => 'DATETIME NULL',
                'assigned_owner_ids_json' => 'JSON NULL',
                'assigned_group_ids_json' => 'JSON NULL',
                'promotion_pool_requested' => 'TINYINT(1) NOT NULL DEFAULT 0',
                'conversion_note' => 'TEXT NULL',
            ],
        ],
        'crm_radar_candidate_contacts' => [
            'sql' => "CREATE TABLE IF NOT EXISTS crm_radar_candidate_contacts (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                candidate_id BIGINT UNSIGNED NOT NULL,
                contact_name VARCHAR(190) NULL,
                position VARCHAR(190) NULL,
                email VARCHAR(190) NULL,
                phone VARCHAR(120) NULL,
                linkedin VARCHAR(255) NULL,
                confidence_score DECIMAL(8,2) NOT NULL DEFAULT 0,
                source_url VARCHAR(500) NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY idx_radar_contact_candidate (candidate_id),
                KEY idx_radar_contact_email (email)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            'columns' => [
                'confidence_score' => 'DECIMAL(8,2) NOT NULL DEFAULT 0',
                'whatsapp' => 'VARCHAR(120) NULL',
                'facebook' => 'VARCHAR(255) NULL',
                'source_note' => 'VARCHAR(500) NULL',
                'is_verified' => 'TINYINT(1) NOT NULL DEFAULT 0',
            ],
        ],
        'crm_radar_candidate_sources' => [
            'sql' => "CREATE TABLE IF NOT EXISTS crm_radar_candidate_sources (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                candidate_id BIGINT UNSIGNED NOT NULL,
                source_type VARCHAR(80) NOT NULL,
                source_url VARCHAR(500) NULL,
                title VARCHAR(255) NULL,
                snippet TEXT NULL,
                captured_at DATETIME NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY idx_radar_source_candidate (candidate_id),
                KEY idx_radar_source_type (source_type)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            'columns' => ['captured_at' => 'DATETIME NULL'],
        ],
        'crm_radar_candidate_scores' => [
            'sql' => "CREATE TABLE IF NOT EXISTS crm_radar_candidate_scores (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                candidate_id BIGINT UNSIGNED NOT NULL,
                score_key VARCHAR(120) NOT NULL,
                score_value DECIMAL(8,2) NOT NULL DEFAULT 0,
                score_reason VARCHAR(500) NULL,
                detail_json JSON NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY idx_radar_score_candidate (candidate_id),
                KEY idx_radar_score_key (score_key)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            'columns' => ['detail_json' => 'JSON NULL'],
        ],
        'crm_radar_candidate_reviews' => [
            'sql' => "CREATE TABLE IF NOT EXISTS crm_radar_candidate_reviews (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                candidate_id BIGINT UNSIGNED NOT NULL,
                review_status VARCHAR(40) NOT NULL,
                review_note VARCHAR(1000) NULL,
                reviewed_by INT UNSIGNED NULL,
                reviewed_at DATETIME NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY idx_radar_review_candidate (candidate_id),
                KEY idx_radar_review_status (review_status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            'columns' => [
                'review_note' => 'VARCHAR(1000) NULL',
                'before_json' => 'JSON NULL',
                'after_json' => 'JSON NULL',
            ],
        ],
        'crm_radar_feedback' => [
            'sql' => "CREATE TABLE IF NOT EXISTS crm_radar_feedback (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                candidate_id BIGINT UNSIGNED NULL,
                customer_id INT UNSIGNED NULL,
                feedback_type VARCHAR(80) NOT NULL,
                feedback_note VARCHAR(1000) NULL,
                created_by INT UNSIGNED NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY idx_radar_feedback_candidate (candidate_id),
                KEY idx_radar_feedback_customer (customer_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            'columns' => [
                'feedback_note' => 'VARCHAR(1000) NULL',
                'task_id' => 'BIGINT UNSIGNED NULL',
                'keyword' => 'VARCHAR(255) NULL',
                'seed_id' => 'BIGINT UNSIGNED NULL',
                'original_score' => 'DECIMAL(8,2) NOT NULL DEFAULT 0',
            ],
        ],
        'crm_radar_blacklist' => [
            'sql' => "CREATE TABLE IF NOT EXISTS crm_radar_blacklist (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                blacklist_type VARCHAR(80) NOT NULL,
                blacklist_value VARCHAR(255) NOT NULL,
                reason VARCHAR(500) NULL,
                created_by INT UNSIGNED NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uk_radar_blacklist (blacklist_type, blacklist_value)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            'columns' => ['reason' => 'VARCHAR(500) NULL'],
        ],
        'crm_radar_logs' => [
            'sql' => "CREATE TABLE IF NOT EXISTS crm_radar_logs (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                user_id INT UNSIGNED NULL,
                user_account VARCHAR(120) NULL,
                module_key VARCHAR(80) NOT NULL DEFAULT 'radar',
                action_key VARCHAR(120) NOT NULL,
                content_json JSON NULL,
                ip_address VARCHAR(64) NULL,
                success TINYINT(1) NOT NULL DEFAULT 1,
                error_message VARCHAR(1000) NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY idx_radar_log_user (user_id),
                KEY idx_radar_log_action (action_key),
                KEY idx_radar_log_created (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            'columns' => ['error_message' => 'VARCHAR(1000) NULL'],
        ],
        'crm_radar_usage' => [
            'sql' => "CREATE TABLE IF NOT EXISTS crm_radar_usage (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                usage_date DATE NOT NULL,
                task_id BIGINT UNSIGNED NULL,
                usage_type VARCHAR(80) NOT NULL,
                provider VARCHAR(80) NULL,
                quantity INT NOT NULL DEFAULT 0,
                cost_amount DECIMAL(12,4) NOT NULL DEFAULT 0,
                detail_json JSON NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY idx_radar_usage_date (usage_date),
                KEY idx_radar_usage_task (task_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            'columns' => [
                'cost_amount' => 'DECIMAL(12,4) NOT NULL DEFAULT 0',
                'service_name' => 'VARCHAR(120) NULL',
                'request_type' => 'VARCHAR(80) NULL',
                'return_count' => 'INT NOT NULL DEFAULT 0',
                'success' => 'TINYINT(1) NOT NULL DEFAULT 1',
                'error_reason' => 'VARCHAR(1000) NULL',
            ],
        ],
        'crm_radar_settings' => [
            'sql' => "CREATE TABLE IF NOT EXISTS crm_radar_settings (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                setting_key VARCHAR(120) NOT NULL,
                setting_value TEXT NULL,
                setting_json JSON NULL,
                updated_by INT UNSIGNED NULL,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uk_radar_setting_key (setting_key)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            'columns' => ['setting_json' => 'JSON NULL'],
        ],
    ];
}

function radar_ensure_tables(bool $verbose = false): array
{
    radar_ensure_permissions();
    $results = [];
    foreach (radar_table_definitions() as $table => $definition) {
        try {
            $exists = radar_table_exists($table);
            db()->exec($definition['sql']);
            $results[] = ['table' => $table, 'action' => $exists ? 'checked' : 'created', 'success' => true, 'message' => $exists ? '表已存在' : '表已创建'];
            foreach (($definition['columns'] ?? []) as $column => $sql) {
                if (!radar_column_exists($table, $column)) {
                    db()->exec("ALTER TABLE {$table} ADD COLUMN {$column} {$sql}");
                    $results[] = ['table' => $table, 'field' => $column, 'action' => 'field_added', 'success' => true, 'message' => '字段已补齐'];
                } elseif ($verbose) {
                    $results[] = ['table' => $table, 'field' => $column, 'action' => 'field_checked', 'success' => true, 'message' => '字段已存在'];
                }
            }
        } catch (Throwable $e) {
            $results[] = ['table' => $table, 'action' => 'failed', 'success' => false, 'message' => $e->getMessage()];
            radar_log('database_upgrade_failed', ['table' => $table, 'message' => $e->getMessage()], false, $e->getMessage());
            throw new RuntimeException('客户雷达数据库升级失败：' . $table . ' - ' . $e->getMessage());
        }
    }
    if (radar_table_exists('crm_radar_search_templates')) {
        radar_template_ensure_presets(false);
    }
    radar_log('database_upgrade', ['results' => $results]);
    return $results;
}

function radar_settings_get(): array
{
    if (!radar_table_exists('crm_radar_settings')) return radar_default_settings();
    $settings = radar_default_settings();
    foreach (db()->query('SELECT setting_key, setting_json, setting_value FROM crm_radar_settings') as $row) {
        $key = (string)$row['setting_key'];
        $schema = radar_setting_schema()[$key] ?? null;
        if (!$schema) continue;
        $value = json_decode((string)($row['setting_json'] ?? ''), true);
        if ($value === null) $value = $row['setting_value'];
        $settings[$schema['section']][$key] = $value;
    }
    return $settings;
}

function radar_normalize_settings(array $input): array
{
    $settings = radar_default_settings();
    foreach (radar_setting_schema() as $key => $schema) {
        $raw = $input[$key] ?? ($settings[$schema['section']][$key] ?? null);
        if ($schema['type'] === 'bool') $value = !empty($raw) && (string)$raw !== '0' ? 1 : 0;
        elseif ($schema['type'] === 'int') $value = max($schema['min'], min($schema['max'], (int)$raw));
        elseif ($schema['type'] === 'decimal') $value = max($schema['min'], min($schema['max'], (float)$raw));
        else $value = mb_substr(trim((string)$raw), 0, (int)($schema['max'] ?? 255));
        $settings[$schema['section']][$key] = $value;
    }
    return $settings;
}

function radar_settings_save(array $input): array
{
    radar_ensure_tables();
    crm_require('radar_settings_manage');
    $before = radar_settings_get();
    $settings = radar_normalize_settings($input);
    $stmt = db()->prepare('INSERT INTO crm_radar_settings (setting_key, setting_value, setting_json, updated_by, updated_at, created_at) VALUES (?, ?, ?, ?, NOW(), NOW()) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value), setting_json=VALUES(setting_json), updated_by=VALUES(updated_by), updated_at=NOW()');
    foreach (radar_setting_schema() as $key => $schema) {
        $value = $settings[$schema['section']][$key];
        $stmt->execute([$key, is_scalar($value) ? (string)$value : '', json_encode($value, JSON_UNESCAPED_UNICODE), (int)(current_user()['id'] ?? 0) ?: null]);
    }
    $moduleBefore = (int)($before['basic']['module_enabled'] ?? 0);
    $moduleAfter = (int)($settings['basic']['module_enabled'] ?? 0);
    radar_log('settings_save', ['before' => $before, 'after' => $settings]);
    if ($moduleBefore !== $moduleAfter) radar_log($moduleAfter ? 'module_enabled' : 'module_disabled', ['enabled' => $moduleAfter]);
    return ['settings' => $settings];
}

function radar_counts(): array
{
    $empty = ['seed_customers' => 0, 'positive_seeds' => 0, 'negative_seeds' => 0, 'search_tasks' => 0, 'running_tasks' => 0, 'pending_candidates' => 0, 'grade_a_candidates' => 0, 'grade_b_candidates' => 0, 'converted_to_crm' => 0, 'duplicate_candidates' => 0, 'not_precise_candidates' => 0, 'today_tasks' => 0, 'today_candidates' => 0, 'month_candidates' => 0, 'month_cost' => 0, 'cost_per_valid_customer' => 0];
    if (!radar_table_exists('crm_radar_seed_customers')) return $empty;
    $scalar = function (string $sql) {
        return (float)(db()->query($sql)->fetchColumn() ?: 0);
    };
    $monthCost = $scalar("SELECT COALESCE(SUM(cost_amount),0) FROM crm_radar_usage WHERE DATE_FORMAT(usage_date,'%Y-%m')=DATE_FORMAT(CURDATE(),'%Y-%m')");
    $validCustomers = (int)$scalar("SELECT COUNT(*) FROM crm_radar_candidates WHERE deleted_at IS NULL AND review_status IN ('precise','basic_precise','normal','converted')");
    return [
        'seed_customers' => (int)$scalar("SELECT COUNT(*) FROM crm_radar_seed_customers WHERE deleted_at IS NULL"),
        'positive_seeds' => (int)$scalar("SELECT COUNT(*) FROM crm_radar_seed_customers WHERE deleted_at IS NULL AND sample_type='positive'"),
        'negative_seeds' => (int)$scalar("SELECT COUNT(*) FROM crm_radar_seed_customers WHERE deleted_at IS NULL AND sample_type='negative'"),
        'search_tasks' => (int)$scalar("SELECT COUNT(*) FROM crm_radar_search_tasks"),
        'running_tasks' => (int)$scalar("SELECT COUNT(*) FROM crm_radar_search_tasks WHERE task_status IN ('pending','generating_keywords','searching','fetching_pages','identifying_company','waiting_analysis')"),
        'pending_candidates' => (int)$scalar("SELECT COUNT(*) FROM crm_radar_candidates WHERE deleted_at IS NULL AND review_status='pending'"),
        'grade_a_candidates' => (int)$scalar("SELECT COUNT(*) FROM crm_radar_candidates WHERE deleted_at IS NULL AND grade='A'"),
        'grade_b_candidates' => (int)$scalar("SELECT COUNT(*) FROM crm_radar_candidates WHERE deleted_at IS NULL AND grade='B'"),
        'converted_to_crm' => (int)$scalar("SELECT COUNT(*) FROM crm_radar_candidates WHERE deleted_at IS NULL AND converted_customer_id IS NOT NULL"),
        'duplicate_candidates' => (int)$scalar("SELECT COUNT(*) FROM crm_radar_candidates WHERE deleted_at IS NULL AND (review_status='duplicate' OR crm_duplicate_status NOT IN ('新客户','new_customer','未检查'))"),
        'not_precise_candidates' => (int)$scalar("SELECT COUNT(*) FROM crm_radar_candidates WHERE deleted_at IS NULL AND review_status='not_precise'"),
        'today_tasks' => (int)$scalar("SELECT COUNT(*) FROM crm_radar_search_tasks WHERE DATE(created_at)=CURDATE()"),
        'today_candidates' => (int)$scalar("SELECT COUNT(*) FROM crm_radar_candidates WHERE deleted_at IS NULL AND DATE(created_at)=CURDATE()"),
        'month_candidates' => (int)$scalar("SELECT COUNT(*) FROM crm_radar_candidates WHERE deleted_at IS NULL AND DATE_FORMAT(created_at,'%Y-%m')=DATE_FORMAT(CURDATE(),'%Y-%m')"),
        'month_cost' => $monthCost,
        'cost_per_valid_customer' => $validCustomers ? round($monthCost / $validCustomers, 4) : 0,
    ];
}

function radar_stats(): array
{
    radar_ensure_tables();
    crm_require('radar_view');
    $series = function (string $sql): array {
        return db()->query($sql)->fetchAll();
    };
    $counts = radar_counts();
    $counts['feedback_total'] = (int)(db()->query('SELECT COUNT(*) FROM crm_radar_feedback')->fetchColumn() ?: 0);
    return [
        'counts' => $counts,
        'daily_candidates' => $series("SELECT DATE(created_at) label, COUNT(*) value FROM crm_radar_candidates WHERE deleted_at IS NULL AND created_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY) GROUP BY DATE(created_at) ORDER BY label"),
        'grade_distribution' => $series("SELECT COALESCE(grade,'D') label, COUNT(*) value FROM crm_radar_candidates WHERE deleted_at IS NULL GROUP BY COALESCE(grade,'D') ORDER BY label"),
        'model_distribution' => $series("SELECT COALESCE(model_key,'direct_buyer') label, COUNT(*) value FROM crm_radar_candidates WHERE deleted_at IS NULL GROUP BY COALESCE(model_key,'direct_buyer')"),
        'country_distribution' => $series("SELECT COALESCE(NULLIF(country,''),'未填写') label, COUNT(*) value FROM crm_radar_candidates WHERE deleted_at IS NULL GROUP BY COALESCE(NULLIF(country,''),'未填写') ORDER BY value DESC LIMIT 20"),
        'city_distribution' => $series("SELECT COALESCE(NULLIF(city,''),'未填写') label, COUNT(*) value FROM crm_radar_candidates WHERE deleted_at IS NULL GROUP BY COALESCE(NULLIF(city,''),'未填写') ORDER BY value DESC LIMIT 20"),
        'type_distribution' => $series("SELECT COALESCE(NULLIF(company_type,''),'未分类') label, COUNT(*) value FROM crm_radar_candidates WHERE deleted_at IS NULL GROUP BY COALESCE(NULLIF(company_type,''),'未分类') ORDER BY value DESC LIMIT 20"),
        'keyword_effectiveness' => $series("SELECT keyword label, found_count, valid_count, average_score FROM crm_radar_search_keywords WHERE deleted_at IS NULL ORDER BY valid_count DESC, found_count DESC LIMIT 20"),
        'source_effectiveness' => $series("SELECT COALESCE(source_type,'search_result') label, COUNT(*) value FROM crm_radar_candidate_sources GROUP BY COALESCE(source_type,'search_result') ORDER BY value DESC LIMIT 20"),
        'review_precision' => $series("SELECT review_status label, COUNT(*) value FROM crm_radar_candidates WHERE deleted_at IS NULL GROUP BY review_status ORDER BY value DESC"),
        'conversion_ratio' => $series("SELECT IF(converted_customer_id IS NULL,'未转CRM','已转CRM') label, COUNT(*) value FROM crm_radar_candidates WHERE deleted_at IS NULL GROUP BY IF(converted_customer_id IS NULL,'未转CRM','已转CRM')"),
    ];
}

function radar_recent_rows(): array
{
    if (!radar_table_exists('crm_radar_search_tasks')) return ['tasks' => [], 'candidates' => [], 'errors' => [], 'logs' => []];
    $tasks = db()->query('SELECT id, task_name, task_status, created_at FROM crm_radar_search_tasks ORDER BY id DESC LIMIT 8')->fetchAll();
    $candidates = db()->query('SELECT id, company_name, country, radar_score, grade, review_status, created_at FROM crm_radar_candidates WHERE deleted_at IS NULL ORDER BY id DESC LIMIT 8')->fetchAll();
    $errors = db()->query('SELECT id, action_key, error_message, created_at FROM crm_radar_logs WHERE success=0 ORDER BY id DESC LIMIT 8')->fetchAll();
    $logs = db()->query('SELECT id, action_key, user_account, success, error_message, created_at FROM crm_radar_logs ORDER BY id DESC LIMIT 20')->fetchAll();
    return ['tasks' => $tasks, 'candidates' => $candidates, 'errors' => $errors, 'logs' => $logs];
}

function radar_bootstrap(): array
{
    radar_ensure_permissions();
    crm_require('radar_view');
    $userId = (int)(current_user()['id'] ?? 0);
    $sessionKey = 'radar_view_logged_at_' . $userId;
    $lastViewLog = (int)($_SESSION[$sessionKey] ?? 0);
    if ($lastViewLog <= 0 || time() - $lastViewLog >= 600) {
        radar_log('view');
        $_SESSION[$sessionKey] = time();
    }
    return [
        'counts' => radar_counts(),
        'recent' => radar_recent_rows(),
        'settings' => radar_settings_get(),
        'permissions' => crm_permission_state(array_column(radar_permissions(), 0)),
        'tables_ready' => radar_table_exists('crm_radar_settings'),
    ];
}

function radar_model_options(): array
{
    return [
        'direct_buyer' => '直接采购型',
        'project_procurement' => '工程项目采购型',
        'design_influencer' => '设计影响型',
        'brand_oem' => '品牌/OEM型',
        'negative_sample' => '负向样本',
    ];
}

function radar_negative_reasons(): array
{
    return ['只做家居装饰灯','只做低端零售','自有制造工厂且不外采','纯电商卖家','纯电工材料零售商','业务已停止','官网失效','与商业照明无关','无项目能力','无进口或采购可能','重复公司','黑名单','其他'];
}

function radar_keyword_categories(): array
{
    return [
        'company_type' => '公司类型',
        'product' => '产品',
        'project' => '项目',
        'region' => '地区',
        'position' => '职位',
        'exclude' => '排除词',
    ];
}

function radar_template_modes(): array
{
    return [
        'compact' => ['label' => '精简模式', 'min' => 12, 'max' => 20, 'default_limit' => 16, 'result_limit' => 5],
        'standard' => ['label' => '标准模式', 'min' => 20, 'max' => 40, 'default_limit' => 32, 'result_limit' => 10],
        'deep' => ['label' => '深度模式', 'min' => 40, 'max' => 80, 'default_limit' => 60, 'result_limit' => 10],
    ];
}

function radar_template_json_keys(): array
{
    return ['default_cities','optional_cities','client_types_en','client_types_local','products_en','products_local','projects_en','projects_local','contact_positions_en','contact_positions_local','site_keywords','search_languages','pattern_templates','flags'];
}

function radar_template_presets(): array
{
    $vnExclude = [
        'en' => ['decorative chandelier','home decor lighting','solar street light manufacturer','LED bulb factory','electrical hardware shop','marketplace','ecommerce','used lighting','stage lighting','automotive lighting'],
        'local' => ['đèn trang trí gia đình','đèn chùm trang trí','cửa hàng điện dân dụng','bóng đèn LED','đèn sân khấu','đèn ô tô','đèn năng lượng mặt trời','sàn thương mại điện tử'],
    ];
    $idExclude = [
        'en' => ['decorative chandelier','home decor lighting','LED bulb factory','electrical hardware store','marketplace','ecommerce','stage lighting','automotive lighting','solar street light manufacturer'],
        'local' => ['lampu hias rumah','lampu gantung dekoratif','toko listrik','pabrik bohlam LED','lampu panggung','lampu otomotif','lampu tenaga surya','marketplace','toko online'],
    ];
    $vnDirect = [
        'template_name' => '越南｜建筑商业照明采购客户', 'template_code' => 'VN_DIRECT_BUYER', 'country' => '越南', 'country_code' => 'VN', 'country_local' => 'Việt Nam', 'model_key' => 'direct_buyer',
        'default_cities' => ['Ho Chi Minh City','Hanoi'], 'optional_cities' => ['Ho Chi Minh City','Hanoi','Da Nang','Hai Phong','Can Tho','Binh Duong','Dong Nai','Nha Trang'],
        'client_types_en' => ['architectural lighting supplier','architectural lighting distributor','commercial lighting supplier','lighting solution provider','project lighting company','lighting system integrator','lighting distributor','lighting importer','lighting showroom','lighting consultant with product supply','hotel lighting supplier','retail lighting supplier','office lighting supplier'],
        'client_types_local' => ['công ty chiếu sáng kiến trúc','nhà cung cấp chiếu sáng kiến trúc','nhà phân phối đèn chiếu sáng','nhà nhập khẩu đèn chiếu sáng','công ty giải pháp chiếu sáng','công ty chiếu sáng thương mại','công ty chiếu sáng dự án','đơn vị cung cấp đèn dự án','showroom đèn cao cấp','tư vấn chiếu sáng và cung cấp sản phẩm'],
        'products_en' => ['downlight','recessed spotlight','track light','magnetic track light','linear lighting','surface mounted light','pendant light','wall washer','outdoor architectural lighting'],
        'products_local' => ['đèn âm trần','đèn rọi âm trần','đèn rọi ray','đèn ray nam châm','đèn tuyến tính','đèn ốp nổi','đèn thả','đèn rọi tường','đèn chiếu sáng kiến trúc ngoài trời'],
        'projects_en' => ['hotel lighting','retail lighting','showroom lighting','restaurant lighting','office lighting','residential lighting','museum lighting','gallery lighting','villa lighting','commercial project lighting'],
        'projects_local' => ['chiếu sáng khách sạn','chiếu sáng cửa hàng','chiếu sáng showroom','chiếu sáng nhà hàng','chiếu sáng văn phòng','chiếu sáng nhà ở','chiếu sáng bảo tàng','chiếu sáng phòng trưng bày','chiếu sáng biệt thự','chiếu sáng dự án thương mại'],
        'preset_en' => ['Vietnam architectural lighting supplier','Vietnam architectural lighting distributor','Vietnam commercial lighting supplier','Vietnam lighting solution provider','Vietnam project lighting company','Vietnam lighting importer','Vietnam lighting showroom','Vietnam downlight distributor','Vietnam track lighting supplier','Vietnam magnetic track lighting supplier','Vietnam hotel lighting supplier','Vietnam retail lighting supplier','Hanoi architectural lighting supplier','Hanoi commercial lighting company','Ho Chi Minh City architectural lighting supplier','Ho Chi Minh City project lighting company','Da Nang commercial lighting supplier'],
        'preset_local' => ['công ty chiếu sáng kiến trúc Việt Nam','nhà cung cấp đèn chiếu sáng thương mại Việt Nam','công ty giải pháp chiếu sáng Việt Nam','nhà phân phối đèn chiếu sáng Việt Nam','nhà nhập khẩu đèn chiếu sáng Việt Nam','công ty chiếu sáng dự án Việt Nam','showroom đèn cao cấp Việt Nam','nhà cung cấp đèn âm trần Việt Nam','nhà phân phối đèn rọi ray Việt Nam','nhà cung cấp đèn ray nam châm Việt Nam','công ty chiếu sáng khách sạn Việt Nam','công ty chiếu sáng cửa hàng Việt Nam','công ty chiếu sáng kiến trúc Hà Nội','nhà cung cấp đèn dự án Hà Nội','công ty chiếu sáng kiến trúc Hồ Chí Minh','nhà cung cấp đèn thương mại Hồ Chí Minh'],
        'exclude' => $vnExclude, 'warning_text' => '',
    ];
    $vnDesign = $vnDirect;
    $vnDesign['template_name'] = '越南｜灯光设计与顾问公司'; $vnDesign['template_code'] = 'VN_DESIGN_INFLUENCER'; $vnDesign['model_key'] = 'design_influencer';
    $vnDesign['client_types_en'] = ['lighting design studio','architectural lighting designer','lighting consultant','independent lighting consultant','lighting specification consultant','hospitality lighting designer','retail lighting designer'];
    $vnDesign['client_types_local'] = ['công ty thiết kế chiếu sáng','studio thiết kế chiếu sáng','tư vấn thiết kế chiếu sáng','chuyên gia tư vấn chiếu sáng','thiết kế chiếu sáng kiến trúc','tư vấn chiếu sáng khách sạn','tư vấn chiếu sáng thương mại'];
    $vnDesign['preset_en'] = ['Vietnam lighting design studio','Vietnam architectural lighting consultant','Vietnam independent lighting designer','Vietnam hospitality lighting consultant','Vietnam retail lighting designer','Hanoi lighting design studio','Hanoi architectural lighting consultant','Ho Chi Minh City lighting design studio','Ho Chi Minh City lighting consultant','Da Nang lighting design consultant'];
    $vnDesign['preset_local'] = ['công ty thiết kế chiếu sáng Việt Nam','studio thiết kế chiếu sáng Việt Nam','tư vấn chiếu sáng kiến trúc Việt Nam','chuyên gia tư vấn chiếu sáng Việt Nam','thiết kế chiếu sáng khách sạn Việt Nam','thiết kế chiếu sáng thương mại Việt Nam','công ty thiết kế chiếu sáng Hà Nội','tư vấn chiếu sáng Hà Nội','studio thiết kế chiếu sáng Hồ Chí Minh','tư vấn chiếu sáng kiến trúc Hồ Chí Minh'];
    $vnDesign['warning_text'] = '该模板搜索的公司可能不是直接采购付款方，主要用于项目规格影响、品牌推荐和设计渠道开发。';
    $vnBrand = $vnDirect;
    $vnBrand['template_name'] = '越南｜建筑照明品牌与OEM客户'; $vnBrand['template_code'] = 'VN_BRAND_OEM'; $vnBrand['model_key'] = 'brand_oem';
    $vnBrand['client_types_en'] = ['architectural lighting brand Vietnam','lighting brand Vietnam office','lighting product company Vietnam','OEM lighting company Vietnam','private label lighting Vietnam','lighting sourcing company Vietnam','lighting product development Vietnam'];
    $vnBrand['client_types_local'] = ['thương hiệu chiếu sáng kiến trúc','thương hiệu đèn chiếu sáng','công ty phát triển sản phẩm chiếu sáng','công ty OEM đèn chiếu sáng','công ty gia công đèn chiếu sáng','công ty tìm nguồn cung ứng đèn'];
    $vnBrand['preset_en'] = ['architectural lighting brand Vietnam','international lighting brand Vietnam office','lighting product development Vietnam','OEM architectural lighting Vietnam','private label lighting Vietnam','lighting sourcing company Vietnam','Vietnam lighting procurement company','Vietnam lighting supply chain company'];
    $vnBrand['preset_local'] = ['thương hiệu chiếu sáng kiến trúc Việt Nam','công ty OEM đèn chiếu sáng Việt Nam','công ty gia công đèn chiếu sáng Việt Nam','công ty phát triển sản phẩm chiếu sáng Việt Nam'];
    $idDirect = [
        'template_name' => '印度尼西亚｜建筑商业照明采购客户', 'template_code' => 'ID_DIRECT_BUYER', 'country' => 'Indonesia', 'country_code' => 'ID', 'country_local' => 'Indonesia', 'model_key' => 'direct_buyer',
        'default_cities' => ['Jakarta','Surabaya','Bali / Denpasar'], 'optional_cities' => ['Jakarta','Surabaya','Bandung','Bali','Denpasar','Medan','Semarang','Makassar','Tangerang','Bekasi','Yogyakarta'],
        'client_types_en' => ['architectural lighting supplier','architectural lighting distributor','commercial lighting supplier','lighting solution provider','project lighting company','lighting system integrator','lighting distributor','lighting importer','lighting showroom','hotel lighting supplier','retail lighting supplier'],
        'client_types_local' => ['perusahaan pencahayaan arsitektural','pemasok pencahayaan arsitektural','distributor lampu','importir lampu','perusahaan solusi pencahayaan','pemasok lampu komersial','perusahaan pencahayaan proyek','kontraktor pencahayaan','showroom lampu','pemasok lampu hotel','pemasok lampu ritel'],
        'products_en' => ['downlight','recessed spotlight','track light','magnetic track light','linear lighting','surface mounted light','pendant light','wall washer','outdoor architectural lighting'],
        'products_local' => ['lampu downlight','lampu sorot tanam','lampu rel','lampu rel magnetik','lampu linear','lampu plafon','lampu gantung','lampu wall washer','lampu arsitektural luar ruangan'],
        'projects_en' => ['hotel lighting','resort lighting','retail lighting','restaurant lighting','office lighting','villa lighting','showroom lighting','museum lighting','commercial project lighting'],
        'projects_local' => ['pencahayaan hotel','pencahayaan resor','pencahayaan ritel','pencahayaan restoran','pencahayaan kantor','pencahayaan vila','pencahayaan showroom','pencahayaan museum','pencahayaan proyek komersial'],
        'preset_en' => ['Indonesia architectural lighting supplier','Indonesia architectural lighting distributor','Indonesia commercial lighting supplier','Indonesia lighting solution provider','Indonesia project lighting company','Indonesia lighting importer','Indonesia lighting showroom','Indonesia downlight distributor','Indonesia track lighting supplier','Indonesia magnetic track lighting supplier','Indonesia hotel lighting supplier','Indonesia retail lighting supplier','Jakarta architectural lighting supplier','Jakarta commercial lighting company','Surabaya architectural lighting supplier','Bali hotel lighting supplier','Bandung lighting solution provider'],
        'preset_local' => ['perusahaan pencahayaan arsitektural Indonesia','pemasok lampu komersial Indonesia','distributor lampu Indonesia','importir lampu Indonesia','perusahaan solusi pencahayaan Indonesia','perusahaan pencahayaan proyek Indonesia','kontraktor pencahayaan Indonesia','showroom lampu Indonesia','pemasok lampu downlight Indonesia','distributor lampu rel Indonesia','pemasok lampu rel magnetik Indonesia','pemasok pencahayaan hotel Indonesia','perusahaan pencahayaan arsitektural Jakarta','pemasok lampu komersial Jakarta','perusahaan pencahayaan proyek Surabaya','pemasok pencahayaan hotel Bali'],
        'exclude' => $idExclude, 'warning_text' => '',
    ];
    $idDesign = $idDirect;
    $idDesign['template_name'] = '印度尼西亚｜灯光设计与顾问公司'; $idDesign['template_code'] = 'ID_DESIGN_INFLUENCER'; $idDesign['model_key'] = 'design_influencer';
    $idDesign['client_types_en'] = ['lighting design studio','architectural lighting designer','lighting consultant','independent lighting consultant','hospitality lighting consultant','retail lighting designer'];
    $idDesign['client_types_local'] = ['konsultan desain pencahayaan','studio desain pencahayaan','desainer pencahayaan arsitektural','konsultan pencahayaan','konsultan pencahayaan hotel','konsultan pencahayaan komersial'];
    $idDesign['preset_en'] = ['Indonesia lighting design studio','Indonesia architectural lighting consultant','Indonesia independent lighting designer','Indonesia hospitality lighting consultant','Indonesia retail lighting designer','Jakarta lighting design studio','Jakarta architectural lighting consultant','Surabaya lighting consultant','Bali hospitality lighting designer'];
    $idDesign['preset_local'] = ['konsultan desain pencahayaan Indonesia','studio desain pencahayaan Indonesia','desainer pencahayaan arsitektural Indonesia','konsultan pencahayaan Indonesia','konsultan pencahayaan hotel Indonesia','konsultan pencahayaan komersial Indonesia','studio desain pencahayaan Jakarta','konsultan pencahayaan arsitektural Jakarta','konsultan pencahayaan Surabaya','desainer pencahayaan hotel Bali'];
    $idDesign['warning_text'] = '该模板主要寻找能够影响项目规格、品牌选择和产品参数的灯光设计公司，不一定是直接采购方。';
    $idBrand = $idDirect;
    $idBrand['template_name'] = '印度尼西亚｜建筑照明品牌与OEM客户'; $idBrand['template_code'] = 'ID_BRAND_OEM'; $idBrand['model_key'] = 'brand_oem';
    $idBrand['client_types_en'] = ['architectural lighting brand Indonesia','lighting brand Indonesia','OEM lighting company Indonesia','private label lighting Indonesia','lighting sourcing company Indonesia','lighting product development Indonesia','lighting procurement company Indonesia'];
    $idBrand['client_types_local'] = ['merek pencahayaan arsitektural','merek lampu Indonesia','perusahaan OEM lampu','perusahaan produksi lampu','perusahaan pengembangan produk pencahayaan','perusahaan pengadaan lampu','perusahaan sumber produk pencahayaan'];
    $idBrand['preset_en'] = ['architectural lighting brand Indonesia','lighting brand Indonesia','OEM architectural lighting Indonesia','private label lighting Indonesia','lighting product development Indonesia','lighting sourcing company Indonesia','lighting procurement company Indonesia'];
    $idBrand['preset_local'] = ['merek pencahayaan arsitektural Indonesia','perusahaan OEM lampu Indonesia','perusahaan produksi lampu Indonesia','perusahaan pengembangan produk pencahayaan Indonesia','perusahaan pengadaan lampu Indonesia'];

    $aeExclude = [
        'en' => ['decorative chandelier only','home decor lighting','wedding lighting','event lighting rental','stage lighting rental','party lighting','automotive lighting','car LED lights','LED screen','signage company','neon sign','solar street light manufacturer','electrical hardware shop','electrical spare parts','ecommerce marketplace','used lighting','residential bulb shop','Christmas lighting'],
        'local' => ['ثريات ديكور فقط','إضاءة منزلية','تأجير إضاءة حفلات','إضاءة مناسبات','إضاءة مسرح','إضاءة سيارات','شاشات LED','شركة لوحات إعلانية','لافتات نيون','متجر أدوات كهربائية','قطع غيار كهربائية','متجر إلكتروني','إضاءة مستعملة','لمبات منزلية','إضاءة احتفالات'],
    ];
    $aeCities = ['Dubai','Abu Dhabi','Sharjah','Ajman','Ras Al Khaimah','Fujairah','Al Ain','Umm Al Quwain'];
    $aeProductsEn = ['downlight','recessed spotlight','trimless downlight','adjustable downlight','track light','magnetic track light','linear lighting','surface mounted light','pendant light','wall washer','facade lighting','outdoor architectural lighting','landscape lighting','museum lighting','gallery lighting'];
    $aeProductsLocal = ['داون لايت','سبوت لايت مخفي','داون لايت بدون إطار','داون لايت قابل للتعديل','إضاءة مسار','إضاءة مسار مغناطيسي','إضاءة خطية','إضاءة سطحية','إضاءة معلقة','إضاءة غسيل الجدران','إضاءة واجهات','إضاءة معمارية خارجية','إضاءة مناظر طبيعية','إضاءة متاحف','إضاءة معارض'];
    $aeProjectsEn = ['luxury hotel','resort','shopping mall','luxury retail','jewellery store','fashion store','restaurant','café','office tower','commercial tower','villa','palace','residential tower','mosque','museum','gallery','exhibition','showroom','airport','metro','facade','landscape','hospitality','mixed-use development','interior fit-out','renovation'];
    $aeProjectsLocal = ['فندق فاخر','منتجع','مركز تسوق','متجر فاخر','متجر مجوهرات','متجر أزياء','مطعم','مقهى','برج مكاتب','برج تجاري','فيلا','قصر','برج سكني','مسجد','متحف','معرض فني','معرض','صالة عرض','مطار','مترو','واجهة','مناظر طبيعية','ضيافة','مشروع متعدد الاستخدامات','تشطيبات داخلية','تجديد'];
    $aeSite = ['site:.ae architectural lighting','site:.ae commercial lighting supplier','site:.ae MEP contractor','site:.ae electrical contractor','site:.ae fit-out contractor','site:.ae lighting consultant','site:.ae hospitality lighting','site:.ae project procurement','site:.ae lighting project contractor','site:.ae lighting brand'];
    $aeDirect = [
        'template_name' => '迪拜｜建筑商业照明采购客户', 'template_code' => 'AE_DIRECT_BUYER', 'country' => 'United Arab Emirates', 'country_code' => 'AE', 'country_local' => 'الإمارات', 'model_key' => 'direct_buyer',
        'default_cities' => ['Dubai','Abu Dhabi','Sharjah'], 'optional_cities' => $aeCities,
        'client_types_en' => ['architectural lighting supplier','architectural lighting distributor','commercial lighting supplier','lighting solution provider','project lighting supplier','lighting system integrator','lighting distributor','lighting importer','lighting trading company','lighting showroom','lighting wholesaler','specification lighting supplier','hospitality lighting supplier','hotel lighting supplier','retail lighting supplier','office lighting supplier','luxury lighting supplier','lighting solutions company'],
        'client_types_local' => ['شركة إضاءة معمارية','مورد إضاءة معمارية','موزع إضاءة معمارية','مورد إضاءة تجارية','شركة حلول إضاءة','مورد إضاءة مشاريع','موزع مصابيح','مستورد إضاءة','شركة تجارة إضاءة','معرض إضاءة','مورد إضاءة فنادق','مورد إضاءة ضيافة','مورد إضاءة متاجر','مورد إضاءة مكاتب','مورد إضاءة فاخرة'],
        'products_en' => $aeProductsEn, 'products_local' => $aeProductsLocal, 'projects_en' => $aeProjectsEn, 'projects_local' => $aeProjectsLocal, 'site_keywords' => $aeSite,
        'preset_en' => ['Dubai architectural lighting supplier','Dubai architectural lighting distributor','Dubai commercial lighting supplier','Dubai lighting solution provider','Dubai project lighting supplier','Dubai lighting importer','Dubai lighting trading company','Dubai lighting showroom','Dubai specification lighting supplier','Dubai hospitality lighting supplier','Dubai hotel lighting supplier','Dubai luxury lighting supplier','Dubai downlight supplier','Dubai trimless downlight supplier','Dubai track lighting distributor','Dubai magnetic track lighting supplier','Dubai linear lighting supplier','Dubai facade lighting supplier','Dubai landscape lighting supplier','Dubai retail lighting supplier','Dubai shopping mall lighting supplier','Abu Dhabi architectural lighting supplier','Abu Dhabi commercial lighting supplier','Abu Dhabi hospitality lighting supplier','Sharjah lighting distributor','Sharjah commercial lighting supplier','UAE architectural lighting supplier','UAE commercial lighting supplier','UAE lighting solution provider','UAE lighting importer','UAE lighting trading company'],
        'preset_local' => ['شركة إضاءة معمارية دبي','مورد إضاءة معمارية دبي','موزع إضاءة دبي','مورد إضاءة تجارية دبي','شركة حلول إضاءة دبي','مورد إضاءة مشاريع دبي','مستورد إضاءة دبي','شركة تجارة إضاءة دبي','معرض إضاءة دبي','مورد إضاءة فنادق دبي','مورد إضاءة ضيافة دبي','مورد إضاءة فاخرة دبي','مورد داون لايت دبي','مورد إضاءة مسار دبي','مورد إضاءة مسار مغناطيسي دبي','مورد إضاءة خطية دبي','مورد إضاءة واجهات دبي','شركة إضاءة معمارية أبوظبي','مورد إضاءة تجارية أبوظبي','موزع إضاءة الشارقة','مورد إضاءة معمارية الإمارات','شركة حلول إضاءة الإمارات'],
        'exclude' => $aeExclude, 'warning_text' => '',
        'pattern_templates' => ['{city} {client_type}','{city} lighting importer','{city} lighting showroom','{country} {product} supplier','{city} {project} lighting supplier','{client_type_local} {city}','{product_local} {client_type_local} {city}'],
    ];
    $aeProject = [
        'template_name' => '迪拜｜工程项目采购客户', 'template_code' => 'AE_PROJECT_PROCUREMENT', 'country' => 'United Arab Emirates', 'country_code' => 'AE', 'country_local' => 'الإمارات', 'model_key' => 'project_procurement',
        'default_cities' => ['Dubai','Abu Dhabi','Sharjah'], 'optional_cities' => $aeCities,
        'client_types_en' => ['MEP contractor','MEP contracting company','electrical contractor','electrical contracting company','electromechanical contractor','general contractor','main contractor','fit-out contractor','interior fit-out company','turnkey fit-out contractor','design and build company','EPC contractor','construction procurement company','project procurement company','project purchasing company','building materials procurement company','hospitality procurement company','hotel procurement company','hotel fit-out contractor','retail fit-out contractor','office fit-out contractor','villa contractor','lighting project contractor','lighting installation contractor','electrical project contractor','building services contractor','project management company','construction project management','interior contracting company','joinery and fit-out company','commercial interior contractor','hospitality interior contractor'],
        'client_types_local' => ['شركة مقاولات كهروميكانيكية','مقاول ميكانيكا وكهرباء','شركة مقاولات كهربائية','مقاول كهرباء','شركة مقاولات عامة','مقاول رئيسي','شركة تشطيبات داخلية','مقاول تشطيبات','شركة تصميم وتنفيذ','شركة مقاولات فنادق','شركة تجهيز فنادق','شركة تجهيز متاجر','شركة تجهيز مكاتب','شركة مشتريات مشاريع','شركة مشتريات إنشائية','شركة توريد مواد مشاريع','شركة إدارة مشاريع','شركة مقاولات داخلية','مقاول مشاريع إضاءة','مقاول تركيب إضاءة','مقاول أعمال كهربائية','شركة خدمات مباني'],
        'products_en' => $aeProductsEn, 'products_local' => $aeProductsLocal,
        'projects_en' => ['hotel project','luxury hotel project','resort project','hospitality project','shopping mall project','luxury retail project','jewellery store project','fashion store project','restaurant project','café project','office fit-out project','commercial tower project','residential tower project','villa project','palace project','museum project','gallery project','mosque project','airport project','metro project','showroom project','mixed-use development','facade project','landscape project','interior fit-out project','renovation project'],
        'projects_local' => ['مشروع فندق','مشروع فندق فاخر','مشروع منتجع','مشروع ضيافة','مشروع مركز تسوق','مشروع متجر فاخر','مشروع متجر مجوهرات','مشروع متجر أزياء','مشروع مطعم','مشروع مقهى','مشروع تجهيز مكاتب','مشروع برج تجاري','مشروع برج سكني','مشروع فيلا','مشروع قصر','مشروع متحف','مشروع معرض','مشروع مسجد','مشروع مطار','مشروع مترو','مشروع صالة عرض','مشروع متعدد الاستخدامات','مشروع واجهات','مشروع مناظر طبيعية','مشروع تشطيبات داخلية','مشروع تجديد'],
        'contact_positions_en' => ['Procurement Director','Head of Procurement','Procurement Manager','Project Procurement Manager','Purchasing Manager','Senior Buyer','Project Buyer','Materials Manager','Supply Chain Manager','Commercial Manager','Project Director','Project Manager','MEP Director','MEP Manager','Electrical Manager','Electrical Project Manager','Technical Manager','Construction Manager','Fit-out Project Manager','Interior Project Manager','Contracts Manager','Estimation Manager','Tender Manager','Quantity Surveyor','Senior Quantity Surveyor'],
        'contact_positions_local' => ['مدير المشتريات','رئيس المشتريات','مدير مشتريات المشاريع','مدير المشروع','مدير مشاريع','مدير الكهرباء','مدير الأعمال الكهروميكانيكية','مدير المواد','مدير سلسلة التوريد','مدير العقود','مدير المناقصات','مدير التقدير','مهندس كهرباء','مهندس مشروع'],
        'site_keywords' => ['site:.ae MEP contractor','site:.ae electrical contractor','site:.ae fit-out contractor','site:.ae project procurement','site:.ae lighting project contractor'],
        'preset_en' => ['Dubai MEP contractor lighting projects','Dubai MEP contracting company','Dubai electrical contractor commercial projects','Dubai electrical contracting company','Dubai electromechanical contractor','Dubai fit-out contractor lighting procurement','Dubai interior fit-out company','Dubai hotel fit-out contractor','Dubai hospitality fit-out contractor','Dubai retail fit-out contractor','Dubai office fit-out contractor','Dubai turnkey fit-out company','Dubai design and build contractor','Dubai project procurement company','Dubai construction procurement company','Dubai hotel procurement company','Dubai hospitality procurement company','Dubai lighting project contractor','Dubai lighting installation contractor','Dubai electrical project contractor','Dubai interior contracting company','Dubai commercial interior contractor','Dubai hospitality interior contractor','Dubai project management company','Dubai building materials procurement','Dubai MEP contractor hotel project','Dubai MEP contractor shopping mall','Dubai fit-out contractor luxury retail','Dubai contractor lighting supplier','Dubai project purchasing lighting','Abu Dhabi MEP contractor','Abu Dhabi electrical contracting company','Abu Dhabi hotel fit-out contractor','Abu Dhabi project procurement company','Abu Dhabi lighting project contractor','Sharjah MEP contractor','Sharjah electrical contractor','Sharjah fit-out company','UAE MEP contracting company','UAE electrical contractor','UAE project procurement company','UAE hotel fit-out contractor','UAE hospitality procurement company','UAE lighting project contractor'],
        'preset_local' => ['شركة مقاولات كهروميكانيكية دبي','مقاول ميكانيكا وكهرباء دبي','شركة مقاولات كهربائية دبي','مقاول كهرباء دبي','شركة تشطيبات داخلية دبي','مقاول تشطيبات دبي','شركة تصميم وتنفيذ دبي','شركة تجهيز فنادق دبي','شركة تجهيز متاجر دبي','شركة تجهيز مكاتب دبي','شركة مشتريات مشاريع دبي','شركة مشتريات إنشائية دبي','شركة توريد مواد مشاريع دبي','مقاول مشاريع إضاءة دبي','مقاول تركيب إضاءة دبي','مقاول أعمال كهربائية دبي','شركة إدارة مشاريع دبي','شركة مقاولات داخلية دبي','شركة مقاولات كهروميكانيكية أبوظبي','شركة مقاولات كهربائية أبوظبي','شركة تجهيز فنادق أبوظبي','شركة مشتريات مشاريع أبوظبي','شركة مقاولات كهروميكانيكية الشارقة','شركة مقاولات كهربائية الشارقة','شركة مشتريات مشاريع الإمارات','مقاول مشاريع إضاءة الإمارات'],
        'exclude' => $aeExclude,
        'warning_text' => '该模板搜索工程项目采购客户，不按普通灯具经销商逻辑判断；重点看项目、材料采购、MEP/电气/Fit-out/采购部门和商业工程采购可能。',
        'pattern_templates' => ['{city} MEP contractor','{city} electrical contractor','{city} fit-out contractor','{city} hotel fit-out contractor','{city} project procurement company','{city} lighting project contractor','{project} contractor {city}','{project} procurement {city}','contractor lighting procurement {city}','project buyer construction {city}','{client_type_local} {city}'],
    ];
    $aeDesign = $aeDirect;
    $aeDesign['template_name'] = '迪拜｜灯光设计与顾问公司'; $aeDesign['template_code'] = 'AE_DESIGN_INFLUENCER'; $aeDesign['model_key'] = 'design_influencer'; $aeDesign['default_cities'] = ['Dubai','Abu Dhabi'];
    $aeDesign['client_types_en'] = ['lighting design studio','architectural lighting designer','lighting consultant','independent lighting consultant','lighting specification consultant','hospitality lighting consultant','luxury hotel lighting designer','retail lighting designer','museum lighting consultant','facade lighting designer','landscape lighting designer','lighting design consultancy','MEP lighting consultant'];
    $aeDesign['client_types_local'] = ['استوديو تصميم إضاءة','مصمم إضاءة معمارية','استشاري إضاءة','استشاري تصميم إضاءة','استشاري مواصفات إضاءة','استشاري إضاءة فنادق','مصمم إضاءة متاجر','استشاري إضاءة متاحف','مصمم إضاءة واجهات','مصمم إضاءة مناظر طبيعية','مكتب استشارات إضاءة'];
    $aeDesign['preset_en'] = ['Dubai lighting design studio','Dubai architectural lighting consultant','Dubai independent lighting designer','Dubai lighting specification consultant','Dubai hospitality lighting consultant','Dubai luxury hotel lighting designer','Dubai retail lighting designer','Dubai museum lighting consultant','Dubai facade lighting designer','Dubai landscape lighting designer','Dubai lighting design consultancy','Dubai MEP lighting consultant','Abu Dhabi lighting design studio','Abu Dhabi architectural lighting consultant','Abu Dhabi hospitality lighting designer','UAE lighting design studio','UAE architectural lighting consultant','UAE lighting specification consultant'];
    $aeDesign['preset_local'] = ['استوديو تصميم إضاءة دبي','مصمم إضاءة معمارية دبي','استشاري إضاءة دبي','استشاري تصميم إضاءة دبي','استشاري مواصفات إضاءة دبي','استشاري إضاءة فنادق دبي','مصمم إضاءة متاجر دبي','استشاري إضاءة متاحف دبي','مصمم إضاءة واجهات دبي','مصمم إضاءة مناظر طبيعية دبي','استوديو تصميم إضاءة أبوظبي','استشاري إضاءة معمارية أبوظبي','استشاري تصميم إضاءة الإمارات'];
    $aeDesign['warning_text'] = '该模板主要搜索能够影响灯具品牌、规格、配光、IES和项目选型的设计顾问公司，不一定是最终采购付款方。';
    $aeDesign['site_keywords'] = ['site:.ae lighting consultant','site:.ae lighting design','site:.ae architectural lighting consultant','site:.ae hospitality lighting'];
    $aeDesign['pattern_templates'] = ['{city} lighting design studio','{city} lighting consultant','{city} lighting specification consultant','{project} lighting designer {city}','{client_type_local} {city}'];
    $aeBrand = $aeDirect;
    $aeBrand['template_name'] = '迪拜｜建筑照明品牌与OEM客户'; $aeBrand['template_code'] = 'AE_BRAND_OEM'; $aeBrand['model_key'] = 'brand_oem';
    $aeBrand['client_types_en'] = ['architectural lighting brand UAE','lighting brand Dubai','lighting brand Middle East office','lighting product company UAE','OEM lighting company UAE','private label lighting UAE','lighting sourcing company Dubai','lighting procurement company UAE','lighting supply chain company Dubai','lighting product development UAE','lighting trading group Dubai','international lighting brand Dubai office','lighting distributor Middle East','lighting group GCC'];
    $aeBrand['client_types_local'] = ['علامة تجارية للإضاءة المعمارية','علامة تجارية للإضاءة','شركة منتجات إضاءة','شركة تصنيع إضاءة حسب الطلب','شركة إضاءة بعلامة خاصة','شركة توريد إضاءة','شركة مشتريات إضاءة','شركة سلسلة توريد إضاءة','شركة تطوير منتجات إضاءة','مجموعة تجارة إضاءة','مكتب علامة إضاءة دولية','موزع إضاءة في الشرق الأوسط'];
    $aeBrand['preset_en'] = ['architectural lighting brand Dubai','architectural lighting brand UAE','international lighting brand Dubai office','lighting brand Middle East office','lighting product company Dubai','OEM architectural lighting UAE','private label lighting Dubai','lighting sourcing company Dubai','lighting procurement company UAE','lighting supply chain company Dubai','lighting product development UAE','lighting trading group Dubai','lighting distributor Middle East','lighting brand GCC','UAE lighting procurement manager','Dubai lighting supply chain manager','UAE lighting product manager'];
    $aeBrand['preset_local'] = ['علامة تجارية للإضاءة المعمارية دبي','علامة تجارية للإضاءة الإمارات','شركة منتجات إضاءة دبي','شركة تصنيع إضاءة حسب الطلب الإمارات','شركة إضاءة بعلامة خاصة دبي','شركة توريد إضاءة دبي','شركة مشتريات إضاءة الإمارات','شركة سلسلة توريد إضاءة دبي','شركة تطوير منتجات إضاءة الإمارات','مجموعة تجارة إضاءة دبي','موزع إضاءة الشرق الأوسط','مكتب علامة إضاءة دولية دبي'];
    $aeBrand['warning_text'] = '国际品牌、区域办公室及大型贸易集团可能拥有固定供应链。此模板用于寻找OEM、私有标签、补充产品、非标项目和供应链合作机会，不按普通经销商逻辑开发。';
    $aeBrand['site_keywords'] = ['site:.ae lighting brand','site:.ae lighting product company','site:.ae lighting procurement','site:.ae lighting supply chain'];
    $aeBrand['pattern_templates'] = ['{city} lighting brand','{city} OEM lighting','{city} private label lighting','{city} lighting procurement manager','{city} lighting supply chain','{client_type_local} {city}'];

    return [$vnDirect, $vnDesign, $vnBrand, $idDirect, $idDesign, $idBrand, $aeDirect, $aeProject, $aeDesign, $aeBrand];
}

function radar_template_config_from_preset(array $p): array
{
    return [
        'default_cities' => $p['default_cities'] ?? [],
        'optional_cities' => $p['optional_cities'] ?? [],
        'client_types_en' => $p['client_types_en'] ?? [],
        'client_types_local' => $p['client_types_local'] ?? [],
        'products_en' => $p['products_en'] ?? [],
        'products_local' => $p['products_local'] ?? [],
        'projects_en' => $p['projects_en'] ?? [],
        'projects_local' => $p['projects_local'] ?? [],
        'contact_positions_en' => $p['contact_positions_en'] ?? [],
        'contact_positions_local' => $p['contact_positions_local'] ?? [],
        'site_keywords' => $p['site_keywords'] ?? [],
        'search_languages' => ['en', 'local'],
        'pattern_templates' => $p['pattern_templates'] ?? [
            '{country} {client_type}',
            '{city} {client_type}',
            '{client_type_local} {city}',
            '{country} {product} supplier',
            '{city} {product} supplier',
            '{product_local} {client_type_local} {city}',
            '{country} {project} supplier',
            '{city} {project} supplier',
            '{client_type} {product} {country}',
            '{client_type} {project} {country}',
        ],
        'flags' => [
            'include_city' => 1, 'include_country' => 1, 'search_official_site' => 1, 'search_directory' => 1,
            'search_project_case' => 1, 'search_showroom' => 1, 'search_contact_page' => 1,
            'search_linkedin_company' => 0, 'search_facebook_company' => 0,
        ],
        'defaults' => ['result_limit' => 10, 'max_keywords' => 40, 'target_candidate_count' => 30, 'min_score' => 60],
    ];
}

function radar_template_ensure_presets(bool $force = false, string $onlyCode = ''): int
{
    if (!radar_table_exists('crm_radar_search_templates')) return 0;
    $count = 0;
    foreach (radar_template_presets() as $p) {
        if ($onlyCode !== '' && $onlyCode !== $p['template_code']) continue;
        $st = db()->prepare('SELECT id FROM crm_radar_search_templates WHERE template_code=? LIMIT 1');
        $st->execute([$p['template_code']]);
        $id = (int)($st->fetchColumn() ?: 0);
        if ($id && !$force) continue;
        $config = radar_template_config_from_preset($p);
        $preset = ['en' => $p['preset_en'] ?? [], 'local' => $p['preset_local'] ?? []];
        $exclude = $p['exclude'] ?? ['en' => [], 'local' => []];
        $params = [$p['template_code'], $p['template_name'], $p['country'], $p['country_code'], $p['country_local'], $p['model_key'], 'brave', 'active', 1, $p['template_code'] === 'VN_DIRECT_BUYER' ? 1 : 0, 'standard', radar_json($config), radar_json($preset), radar_json($exclude), (string)($p['warning_text'] ?? '')];
        if ($id) {
            db()->prepare('UPDATE crm_radar_search_templates SET template_code=?,template_name=?,country=?,country_code=?,country_local=?,model_key=?,search_service_key=?,status=?,is_system=?,is_default=?,mode_default=?,config_json=?,preset_keywords_json=?,exclude_keywords_json=?,warning_text=?,updated_at=NOW(),deleted_at=NULL WHERE id=?')
                ->execute(array_merge($params, [$id]));
        } else {
            db()->prepare('INSERT INTO crm_radar_search_templates (template_code,template_name,country,country_code,country_local,model_key,search_service_key,status,is_system,is_default,mode_default,config_json,preset_keywords_json,exclude_keywords_json,warning_text,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW(),NOW())')
                ->execute($params);
        }
        $count++;
    }
    return $count;
}

function radar_arr($value): array
{
    if (is_array($value)) return array_values(array_filter(array_map('trim', $value), fn($v) => $v !== ''));
    $value = trim((string)$value);
    if ($value === '') return [];
    $parts = preg_split('/[\r\n,，;；]+/u', $value) ?: [];
    return array_values(array_filter(array_map('trim', $parts), fn($v) => $v !== ''));
}

function radar_json($value): string
{
    return json_encode($value ?: [], JSON_UNESCAPED_UNICODE);
}

function radar_decode_json($value): array
{
    $decoded = json_decode((string)$value, true);
    return is_array($decoded) ? $decoded : [];
}

function radar_seed_input(array $input): array
{
    $models = radar_model_options();
    $model = (string)($input['model_key'] ?? 'direct_buyer');
    if (!isset($models[$model])) $model = 'direct_buyer';
    $sampleType = (string)($input['sample_type'] ?? 'positive');
    if (!in_array($sampleType, ['positive', 'negative'], true)) $sampleType = 'positive';
    if ($model === 'negative_sample') $sampleType = 'negative';
    return [
        'seed_name' => mb_substr(trim((string)($input['seed_name'] ?? $input['company_name'] ?? '')), 0, 190),
        'local_name' => mb_substr(trim((string)($input['local_name'] ?? '')), 0, 190),
        'website' => mb_substr(trim((string)($input['website'] ?? '')), 0, 255),
        'country' => mb_substr(trim((string)($input['country'] ?? '')), 0, 120),
        'city' => mb_substr(trim((string)($input['city'] ?? '')), 0, 190),
        'company_type' => mb_substr(trim((string)($input['company_type'] ?? '')), 0, 120),
        'model_key' => $model,
        'company_intro' => trim((string)($input['company_intro'] ?? '')),
        'main_products' => trim((string)($input['main_products'] ?? '')),
        'project_types' => trim((string)($input['project_types'] ?? '')),
        'inquiry_products' => trim((string)($input['inquiry_products'] ?? '')),
        'purchase_products' => trim((string)($input['purchase_products'] ?? '')),
        'deal_status' => in_array((string)($input['deal_status'] ?? 'unknown'), ['yes','no','unknown'], true) ? (string)($input['deal_status'] ?? 'unknown') : 'unknown',
        'cooperation_status' => mb_substr(trim((string)($input['cooperation_status'] ?? '')), 0, 80),
        'deal_amount' => max(0, (float)($input['deal_amount'] ?? 0)),
        'cooperation_duration' => mb_substr(trim((string)($input['cooperation_duration'] ?? '')), 0, 120),
        'match_reason' => trim((string)($input['match_reason'] ?? '')),
        'mismatch_reason' => trim((string)($input['mismatch_reason'] ?? '')),
        'sample_type' => $sampleType,
        'sample_weight' => max(0, min(100, (int)($input['sample_weight'] ?? 50))),
        'negative_reason' => mb_substr(trim((string)($input['negative_reason'] ?? '')), 0, 120),
        'contact_name' => mb_substr(trim((string)($input['contact_name'] ?? '')), 0, 190),
        'contact_position' => mb_substr(trim((string)($input['contact_position'] ?? '')), 0, 190),
        'contact_email' => mb_substr(trim((string)($input['contact_email'] ?? '')), 0, 190),
        'contact_phone' => mb_substr(trim((string)($input['contact_phone'] ?? '')), 0, 120),
        'contact_whatsapp' => mb_substr(trim((string)($input['contact_whatsapp'] ?? '')), 0, 120),
        'contact_linkedin' => mb_substr(trim((string)($input['contact_linkedin'] ?? '')), 0, 255),
        'contact_facebook' => mb_substr(trim((string)($input['contact_facebook'] ?? '')), 0, 255),
        'note' => mb_substr(trim((string)($input['note'] ?? '')), 0, 500),
        'status' => in_array((string)($input['status'] ?? 'active'), ['active','inactive','deleted'], true) ? (string)($input['status'] ?? 'active') : 'active',
    ];
}

function radar_seed_select_sql(): string
{
    return 'SELECT s.*, cu.username AS created_by_name, uu.username AS updated_by_name FROM crm_radar_seed_customers s LEFT JOIN crm_users cu ON cu.id=s.created_by LEFT JOIN crm_users uu ON uu.id=s.updated_by';
}

function radar_seed_list(array $input): array
{
    radar_ensure_tables();
    crm_require('radar_view');
    $page = max(1, (int)($input['page'] ?? 1));
    $pageSize = max(10, min(100, (int)($input['page_size'] ?? 20)));
    $where = ['s.deleted_at IS NULL'];
    $params = [];
    $q = trim((string)($input['q'] ?? ''));
    if ($q !== '') {
        $where[] = '(s.seed_name LIKE ? OR s.local_name LIKE ? OR s.website LIKE ? OR s.city LIKE ? OR s.main_products LIKE ?)';
        for ($i = 0; $i < 5; $i++) $params[] = '%' . $q . '%';
    }
    foreach (['model_key','sample_type','status','country'] as $key) {
        if (($input[$key] ?? '') !== '') {
            $where[] = "s.$key = ?";
            $params[] = (string)$input[$key];
        }
    }
    $whereSql = implode(' AND ', $where);
    $stmt = db()->prepare("SELECT COUNT(*) FROM crm_radar_seed_customers s WHERE $whereSql");
    $stmt->execute($params);
    $total = (int)$stmt->fetchColumn();
    $stmt = db()->prepare(radar_seed_select_sql() . " WHERE $whereSql ORDER BY s.id DESC LIMIT $pageSize OFFSET " . (($page - 1) * $pageSize));
    $stmt->execute($params);
    return ['rows' => $stmt->fetchAll(), 'total' => $total, 'page' => $page, 'page_size' => $pageSize, 'models' => radar_model_options(), 'negative_reasons' => radar_negative_reasons()];
}

function radar_seed_get(int $id): array
{
    radar_ensure_tables();
    crm_require('radar_view');
    $stmt = db()->prepare(radar_seed_select_sql() . ' WHERE s.id=? AND s.deleted_at IS NULL LIMIT 1');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if (!$row) throw new RuntimeException('种子客户不存在');
    return ['row' => $row];
}

function radar_seed_save(array $input): array
{
    radar_ensure_tables();
    crm_require('radar_seed_manage');
    $data = radar_seed_input($input);
    if ($data['seed_name'] === '') throw new RuntimeException('公司名称不能为空');
    $id = (int)($input['id'] ?? 0);
    $userId = (int)(current_user()['id'] ?? 0) ?: null;
    if ($id > 0) {
        $sql = 'UPDATE crm_radar_seed_customers SET seed_name=?, local_name=?, website=?, country=?, city=?, company_type=?, model_key=?, company_intro=?, main_products=?, project_types=?, inquiry_products=?, purchase_products=?, deal_status=?, cooperation_status=?, deal_amount=?, cooperation_duration=?, match_reason=?, mismatch_reason=?, sample_type=?, sample_weight=?, negative_reason=?, contact_name=?, contact_position=?, contact_email=?, contact_phone=?, contact_whatsapp=?, contact_linkedin=?, contact_facebook=?, note=?, status=?, updated_by=?, updated_at=NOW() WHERE id=? AND deleted_at IS NULL';
        db()->prepare($sql)->execute(array_merge(array_values($data), [$userId, $id]));
        radar_log('seed_update', ['id' => $id, 'name' => $data['seed_name']]);
    } else {
        $sql = 'INSERT INTO crm_radar_seed_customers (seed_name, local_name, website, country, city, company_type, model_key, company_intro, main_products, project_types, inquiry_products, purchase_products, deal_status, cooperation_status, deal_amount, cooperation_duration, match_reason, mismatch_reason, sample_type, sample_weight, negative_reason, contact_name, contact_position, contact_email, contact_phone, contact_whatsapp, contact_linkedin, contact_facebook, note, status, created_by, updated_by, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW(),NOW())';
        db()->prepare($sql)->execute(array_merge(array_values($data), [$userId, $userId]));
        $id = (int)db()->lastInsertId();
        radar_log('seed_create', ['id' => $id, 'name' => $data['seed_name']]);
    }
    return radar_seed_get($id);
}

function radar_seed_soft_delete(array $input): array
{
    radar_ensure_tables();
    crm_require('radar_seed_manage');
    $id = (int)($input['id'] ?? 0);
    if ($id <= 0) throw new RuntimeException('缺少种子客户ID');
    db()->prepare("UPDATE crm_radar_seed_customers SET status='deleted', deleted_at=NOW(), updated_by=? WHERE id=? AND deleted_at IS NULL")->execute([(int)(current_user()['id'] ?? 0) ?: null, $id]);
    radar_log('seed_delete', ['id' => $id]);
    return ['id' => $id];
}

function radar_seed_status(array $input): array
{
    radar_ensure_tables();
    crm_require('radar_seed_manage');
    $id = (int)($input['id'] ?? 0);
    $status = in_array((string)($input['status'] ?? ''), ['active','inactive'], true) ? (string)$input['status'] : 'inactive';
    db()->prepare('UPDATE crm_radar_seed_customers SET status=?, updated_by=?, updated_at=NOW() WHERE id=? AND deleted_at IS NULL')->execute([$status, (int)(current_user()['id'] ?? 0) ?: null, $id]);
    radar_log($status === 'inactive' ? 'seed_disable' : 'seed_enable', ['id' => $id]);
    return ['id' => $id, 'status' => $status];
}

function radar_seed_batch(array $input): array
{
    radar_ensure_tables();
    crm_require('radar_seed_manage');
    $ids = array_values(array_filter(array_map('intval', (array)($input['ids'] ?? []))));
    if (!$ids) throw new RuntimeException('请选择种子客户');
    $action = (string)($input['batch_action'] ?? '');
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    if ($action === 'model') {
        $model = (string)($input['model_key'] ?? '');
        if (!isset(radar_model_options()[$model])) throw new RuntimeException('客户模型不正确');
        db()->prepare("UPDATE crm_radar_seed_customers SET model_key=?, updated_by=?, updated_at=NOW() WHERE id IN ($placeholders) AND deleted_at IS NULL")->execute(array_merge([$model, (int)(current_user()['id'] ?? 0) ?: null], $ids));
        radar_log('seed_batch_model', ['ids' => $ids, 'model' => $model]);
    } elseif ($action === 'weight') {
        $weight = max(0, min(100, (int)($input['sample_weight'] ?? 50)));
        db()->prepare("UPDATE crm_radar_seed_customers SET sample_weight=?, updated_by=?, updated_at=NOW() WHERE id IN ($placeholders) AND deleted_at IS NULL")->execute(array_merge([$weight, (int)(current_user()['id'] ?? 0) ?: null], $ids));
        radar_log('seed_batch_weight', ['ids' => $ids, 'weight' => $weight]);
    } else {
        throw new RuntimeException('不支持的批量操作');
    }
    return ['updated' => count($ids)];
}

function radar_seed_initial_rows(): array
{
    return [
        ['seed_name'=>'CROLED Lighting JSC','website'=>'https://croled.vn','country'=>'越南','city'=>'胡志明市，河内设分公司','company_type'=>'建筑照明解决方案 / 项目照明供应','model_key'=>'direct_buyer','sample_weight'=>100,'sample_type'=>'positive','main_products'=>'嵌入式灯具, 轨道灯, 磁吸灯, 线性灯, 户外灯','project_types'=>'建筑照明, 项目照明, 商业照明','purchase_products'=>'中国商业照明产品, 防眩灯具, 高显色灯具, 定制角度灯具','match_reason'=>'建筑照明解决方案、项目照明供应、商业照明产品，有设计能力，可能采购中国商业照明产品，重视防眩、显色、角度和定制。','status'=>'active'],
        ['seed_name'=>'ADES Lighting Vietnam','website'=>'https://adeslighting.com','country'=>'越南','city'=>'河内，胡志明设办公室','company_type'=>'照明顾问 / 项目照明供应','model_key'=>'direct_buyer','sample_weight'=>95,'sample_type'=>'positive','main_products'=>'项目照明, 中高端商业照明, 定制照明','project_types'=>'中高端项目, 灯光设计项目','purchase_products'=>'OEM, ODM, 定制灯具','match_reason'=>'照明顾问、项目照明供应、灯光设计、中高端项目，可能有OEM、ODM和定制需求。','status'=>'active'],
        ['seed_name'=>'Alis Lighting','website'=>'https://alis-lighting.vn','country'=>'越南','city'=>'河内，胡志明设Showroom','company_type'=>'高端建筑照明 / 展厅 / 欧洲品牌代理','model_key'=>'direct_buyer','sample_weight'=>90,'sample_type'=>'positive','main_products'=>'高端建筑照明, 非标产品, 小批量定制','project_types'=>'项目案例, 展厅销售, 灯光设计','purchase_products'=>'私有标签, 非标产品, 小批量定制灯具','match_reason'=>'高端建筑照明、灯光设计、产品选型、欧洲品牌代理、展厅、项目案例，存在非标产品、小批量定制和私有标签机会。','status'=>'active'],
        ['seed_name'=>'ASA Lighting Design Studios','website'=>'https://asalightingdesign.com','country'=>'越南','city'=>'胡志明市','company_type'=>'专业灯光设计事务所','model_key'=>'design_influencer','sample_weight'=>80,'sample_type'=>'positive','main_products'=>'IES, CAD, BIM, 规格书, 样品支持','project_types'=>'灯光设计, 项目规格影响, 参数推荐','purchase_products'=>'规格书支持, 样品支持, 技术资料','match_reason'=>'专业灯光设计事务所，影响项目规格、品牌和灯具参数推荐，需要IES、CAD、BIM、规格书和样品支持，通常不是最终付款采购方。','status'=>'active'],
        ['seed_name'=>'Unios Vietnam','website'=>'https://unios.com','country'=>'越南','city'=>'河内、胡志明','company_type'=>'国际建筑照明品牌 / 本地团队','model_key'=>'brand_oem','sample_weight'=>65,'sample_type'=>'positive','main_products'=>'建筑照明品牌产品体系, 配件, 定制合作','project_types'=>'品牌渠道, 产品开发, 供应链合作','purchase_products'=>'OEM, 供应链, 配件, 定制合作','match_reason'=>'国际建筑照明品牌，越南本地团队，自有产品体系，可能存在OEM、供应链、配件和定制合作，目标联系人为采购、供应链、产品经理。','status'=>'active'],
    ];
}

function radar_seed_import_initial(): int
{
    $count = 0;
    foreach (radar_seed_initial_rows() as $row) {
        $stmt = db()->prepare('SELECT id FROM crm_radar_seed_customers WHERE deleted_at IS NULL AND (website=? OR seed_name=?) LIMIT 1');
        $stmt->execute([$row['website'], $row['seed_name']]);
        if ($stmt->fetchColumn()) continue;
        radar_seed_save($row);
        $count++;
    }
    return $count;
}

function radar_seed_import_text(array $input): array
{
    radar_ensure_tables();
    crm_require('radar_seed_manage');
    $text = trim((string)($input['text'] ?? ''));
    if ($text === '') throw new RuntimeException('请粘贴要导入的种子客户');
    $count = 0;
    foreach (preg_split('/\r\n|\r|\n/', $text) ?: [] as $line) {
        $line = trim($line);
        if ($line === '') continue;
        $cols = str_getcsv($line);
        if (count($cols) < 2 || in_array(trim($cols[0]), ['公司名称','seed_name','company'], true)) continue;
        $row = [
            'seed_name' => $cols[0] ?? '',
            'website' => $cols[1] ?? '',
            'country' => $cols[2] ?? '',
            'city' => $cols[3] ?? '',
            'model_key' => $cols[4] ?? 'direct_buyer',
            'sample_weight' => $cols[5] ?? 50,
            'sample_type' => $cols[6] ?? 'positive',
            'company_type' => $cols[7] ?? '',
            'main_products' => $cols[8] ?? '',
            'match_reason' => $cols[9] ?? '',
            'status' => 'active',
        ];
        $stmt = db()->prepare('SELECT id FROM crm_radar_seed_customers WHERE deleted_at IS NULL AND (website=? OR seed_name=?) LIMIT 1');
        $stmt->execute([$row['website'], $row['seed_name']]);
        if ($stmt->fetchColumn()) continue;
        radar_seed_save($row);
        $count++;
    }
    radar_log('seed_import', ['count' => $count]);
    return ['imported' => $count];
}

function radar_keyword_initial_rows(): array
{
    $en = ['Vietnam architectural lighting distributor','Vietnam commercial lighting supplier','Vietnam lighting solution provider','Vietnam lighting project company','Vietnam lighting consultant','Vietnam track lighting distributor','Vietnam downlight supplier','Vietnam magnetic track lighting','Vietnam hotel lighting supplier','Vietnam retail lighting company','Vietnam lighting showroom','Vietnam architectural lighting design','Hanoi commercial lighting','Ho Chi Minh architectural lighting'];
    $vi = ['công ty chiếu sáng kiến trúc','giải pháp chiếu sáng','đèn chiếu sáng thương mại','đèn âm trần','đèn rọi ray','đèn ray nam châm','thiết kế chiếu sáng','tư vấn chiếu sáng','nhà phân phối đèn','showroom đèn cao cấp','chiếu sáng khách sạn','chiếu sáng cửa hàng','chiếu sáng văn phòng','chiếu sáng bảo tàng'];
    $rows = [];
    foreach ($en as $kw) $rows[] = ['keyword'=>$kw,'language'=>'en','country'=>'越南','model_key'=>stripos($kw, 'design') !== false || stripos($kw, 'consultant') !== false ? 'design_influencer' : 'direct_buyer','keyword_category'=>stripos($kw, 'Hanoi') !== false || stripos($kw, 'Ho Chi Minh') !== false ? 'region' : (stripos($kw, 'downlight') !== false || stripos($kw, 'track') !== false ? 'product' : 'company_type'),'weight'=>70,'source'=>'initial'];
    foreach ($vi as $kw) $rows[] = ['keyword'=>$kw,'language'=>'vi','country'=>'越南','model_key'=>mb_stripos($kw, 'thiết kế') !== false || mb_stripos($kw, 'tư vấn') !== false ? 'design_influencer' : 'direct_buyer','keyword_category'=>mb_stripos($kw, 'đèn') !== false ? 'product' : 'company_type','weight'=>70,'source'=>'initial'];
    return $rows;
}

function radar_keyword_input(array $input): array
{
    $model = (string)($input['model_key'] ?? 'direct_buyer');
    if (!isset(radar_model_options()[$model])) $model = 'direct_buyer';
    $cat = (string)($input['keyword_category'] ?? $input['keyword_type'] ?? 'company_type');
    if (!isset(radar_keyword_categories()[$cat])) $cat = 'company_type';
    return [
        'keyword' => mb_substr(trim((string)($input['keyword'] ?? '')), 0, 255),
        'language' => mb_substr(trim((string)($input['language'] ?? 'en')), 0, 40),
        'country' => mb_substr(trim((string)($input['country'] ?? '')), 0, 120),
        'city' => mb_substr(trim((string)($input['city'] ?? '')), 0, 190),
        'model_key' => $model,
        'keyword_category' => $cat,
        'keyword_type' => $cat === 'exclude' ? 'exclude' : 'include',
        'weight' => max(0, min(100, (int)($input['weight'] ?? 50))),
        'status' => in_array((string)($input['status'] ?? 'active'), ['active','inactive','deleted'], true) ? (string)($input['status'] ?? 'active') : 'active',
        'source' => mb_substr(trim((string)($input['source'] ?? 'manual')), 0, 80),
    ];
}

function radar_keyword_save(array $input): array
{
    radar_ensure_tables();
    crm_require('radar_rule_manage');
    $data = radar_keyword_input($input);
    if ($data['keyword'] === '') throw new RuntimeException('关键词不能为空');
    $id = (int)($input['id'] ?? 0);
    $userId = (int)(current_user()['id'] ?? 0) ?: null;
    if ($id > 0) {
        db()->prepare('UPDATE crm_radar_search_keywords SET keyword=?, language=?, country=?, city=?, model_key=?, keyword_category=?, keyword_type=?, weight=?, status=?, source=?, updated_by=?, updated_at=NOW() WHERE id=? AND deleted_at IS NULL')
            ->execute(array_merge(array_values($data), [$userId, $id]));
        radar_log('keyword_update', ['id' => $id, 'keyword' => $data['keyword']]);
    } else {
        db()->prepare('INSERT INTO crm_radar_search_keywords (keyword, language, country, city, model_key, keyword_category, keyword_type, weight, status, source, created_by, updated_by, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,NOW(),NOW())')
            ->execute(array_merge(array_values($data), [$userId, $userId]));
        $id = (int)db()->lastInsertId();
        radar_log('keyword_create', ['id' => $id, 'keyword' => $data['keyword']]);
    }
    return ['row' => radar_keyword_get_row($id)];
}

function radar_keyword_get_row(int $id): array
{
    $stmt = db()->prepare('SELECT * FROM crm_radar_search_keywords WHERE id=? AND deleted_at IS NULL LIMIT 1');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if (!$row) throw new RuntimeException('关键词不存在');
    return $row;
}

function radar_keywords_list(array $input): array
{
    radar_ensure_tables();
    crm_require('radar_view');
    $where = ['deleted_at IS NULL'];
    $params = [];
    foreach (['model_key','language','country','status'] as $key) {
        if (($input[$key] ?? '') !== '') {
            $where[] = "$key=?";
            $params[] = (string)$input[$key];
        }
    }
    $q = trim((string)($input['q'] ?? ''));
    if ($q !== '') { $where[] = 'keyword LIKE ?'; $params[] = '%' . $q . '%'; }
    $stmt = db()->prepare('SELECT * FROM crm_radar_search_keywords WHERE ' . implode(' AND ', $where) . ' ORDER BY id DESC LIMIT 500');
    $stmt->execute($params);
    return ['rows' => $stmt->fetchAll(), 'models' => radar_model_options(), 'categories' => radar_keyword_categories()];
}

function radar_keyword_delete(array $input): array
{
    radar_ensure_tables();
    crm_require('radar_rule_manage');
    $id = (int)($input['id'] ?? 0);
    $status = (string)($input['status'] ?? 'deleted');
    if ($status === 'inactive') {
        db()->prepare("UPDATE crm_radar_search_keywords SET status='inactive', updated_by=?, updated_at=NOW() WHERE id=? AND deleted_at IS NULL")->execute([(int)(current_user()['id'] ?? 0) ?: null, $id]);
        radar_log('keyword_disable', ['id' => $id]);
    } else {
        db()->prepare("UPDATE crm_radar_search_keywords SET status='deleted', deleted_at=NOW(), updated_by=?, updated_at=NOW() WHERE id=? AND deleted_at IS NULL")->execute([(int)(current_user()['id'] ?? 0) ?: null, $id]);
        radar_log('keyword_delete', ['id' => $id]);
    }
    return ['id' => $id];
}

function radar_keyword_import_text(array $input): array
{
    radar_ensure_tables();
    crm_require('radar_rule_manage');
    $text = trim((string)($input['text'] ?? ''));
    if ($text === '') throw new RuntimeException('请粘贴要导入的关键词');
    $count = 0;
    foreach (preg_split('/\r\n|\r|\n/', $text) ?: [] as $line) {
        $line = trim($line);
        if ($line === '') continue;
        $cols = str_getcsv($line);
        if (in_array(trim($cols[0] ?? ''), ['关键词','keyword'], true)) continue;
        $row = [
            'keyword' => $cols[0] ?? '',
            'language' => $cols[1] ?? 'en',
            'country' => $cols[2] ?? '越南',
            'city' => $cols[3] ?? '',
            'model_key' => $cols[4] ?? 'direct_buyer',
            'keyword_category' => $cols[5] ?? 'company_type',
            'weight' => $cols[6] ?? 50,
            'status' => 'active',
            'source' => 'import',
        ];
        $stmt = db()->prepare('SELECT id FROM crm_radar_search_keywords WHERE deleted_at IS NULL AND keyword=? AND language=? LIMIT 1');
        $stmt->execute([$row['keyword'], $row['language']]);
        if ($stmt->fetchColumn()) continue;
        radar_keyword_save($row);
        $count++;
    }
    radar_log('keyword_import', ['count' => $count]);
    return ['imported' => $count];
}

function radar_import_initial_data(): array
{
    radar_ensure_tables();
    crm_require('radar_seed_manage');
    $seeds = radar_seed_import_initial();
    $keywords = 0;
    foreach (radar_keyword_initial_rows() as $row) {
        $stmt = db()->prepare('SELECT id FROM crm_radar_search_keywords WHERE deleted_at IS NULL AND keyword=? AND language=? LIMIT 1');
        $stmt->execute([$row['keyword'], $row['language']]);
        if ($stmt->fetchColumn()) continue;
        radar_keyword_save($row);
        $keywords++;
    }
    radar_generate_all_profiles(false);
    radar_log('initial_import', ['seeds' => $seeds, 'keywords' => $keywords]);
    return ['seeds' => $seeds, 'keywords' => $keywords];
}

function radar_base_profile_rules(string $model): array
{
    $rules = [
        'direct_buyer' => [
            'company' => ['Architectural Lighting','Lighting Solution Provider','Commercial Lighting Supplier','Project Lighting Company','Lighting Distributor','Lighting Showroom','Lighting Consultant with Product Supply'],
            'product' => ['Downlight','Spotlight','Track Light','Magnetic Track','Linear Lighting','Custom Lighting','OEM','ODM'],
            'project' => ['Hotel Lighting','Retail Lighting','Office Lighting','Project Supply'],
            'position' => ['Owner','Director','Sales Manager','Project Manager','Purchasing Manager'],
            'contact' => ['procurement','project','sales','lighting consultant'],
        ],
        'design_influencer' => [
            'company' => ['Lighting Design Studio','Independent Lighting Consultant','Lighting Specification Consultant'],
            'product' => ['DIALux','IES','BIM','CAD'],
            'project' => ['Hospitality Lighting Design','Retail Lighting Design','Museum Lighting Design'],
            'position' => ['Lighting Designer','Principal Designer','Project Director'],
            'contact' => ['designer','principal','specification','consultant'],
        ],
        'brand_oem' => [
            'company' => ['Architectural Lighting Brand','Vietnam Office','Lighting Experience Centre'],
            'product' => ['OEM Lighting','Private Label Lighting','Contract Manufacturing'],
            'project' => ['Product Development','Asia Sourcing','Supply Chain'],
            'position' => ['Procurement','Supply Chain','Product Manager'],
            'contact' => ['procurement','sourcing','product manager','supply chain'],
        ],
    ];
    return $rules[$model] ?? $rules['direct_buyer'];
}

function radar_build_profile(string $model): array
{
    $stmt = db()->prepare("SELECT * FROM crm_radar_seed_customers WHERE deleted_at IS NULL AND status='active' AND sample_type='positive' AND model_key=?");
    $stmt->execute([$model]);
    $seeds = $stmt->fetchAll();
    $base = radar_base_profile_rules($model);
    $collect = function (string $field) use ($seeds) {
        $out = [];
        foreach ($seeds as $seed) $out = array_merge($out, radar_arr($seed[$field] ?? ''));
        return array_values(array_unique(array_filter($out)));
    };
    $seedIds = array_map(fn($r) => (int)$r['id'], $seeds);
    return [
        'model_key' => $model,
        'profile_title' => radar_model_options()[$model] ?? $model,
        'sample_count' => count($seeds),
        'total_weight' => array_sum(array_map(fn($r) => (int)$r['sample_weight'], $seeds)),
        'company_keywords' => array_values(array_unique(array_merge($base['company'], $collect('company_type')))),
        'product_keywords' => array_values(array_unique(array_merge($base['product'], $collect('main_products'), $collect('purchase_products'), $collect('inquiry_products')))),
        'project_keywords' => array_values(array_unique(array_merge($base['project'], $collect('project_types')))),
        'city_keywords' => array_values(array_unique(array_filter(array_map(fn($r) => (string)$r['city'], $seeds)))),
        'position_keywords' => $base['position'],
        'contact_keywords' => $base['contact'],
        'positive_features' => array_values(array_unique(array_merge($collect('match_reason'), $base['company']))),
        'negative_features' => radar_negative_reasons(),
        'recommended_terms' => array_values(array_unique(array_merge($base['company'], $base['product'], $base['project']))),
        'excluded_terms' => ['home decor lighting','low end retail','electric material shop','e-commerce only'],
        'scoring_rules' => ['公司类型匹配 +25','产品关键词匹配 +25','项目能力匹配 +20','职位/联系人匹配 +15','越南重点城市 +10','负向原因命中 -50'],
        'seed_ids' => $seedIds,
    ];
}

function radar_profile_upsert(array $profile, bool $manualOverride = false, bool $forceOverwrite = false): void
{
    $stmt = db()->prepare('SELECT id, manual_override FROM crm_radar_profiles WHERE model_key=? LIMIT 1');
    $stmt->execute([$profile['model_key']]);
    $row = $stmt->fetch();
    if ($row && (int)$row['manual_override'] === 1 && !$manualOverride && !$forceOverwrite) return;
    $data = [
        $profile['profile_title'],
        $profile['model_key'],
        $profile['profile_title'],
        $profile['sample_count'],
        $profile['total_weight'],
        radar_json($profile['company_keywords']),
        radar_json($profile['product_keywords']),
        radar_json($profile['project_keywords']),
        radar_json($profile['city_keywords']),
        radar_json($profile['position_keywords']),
        radar_json($profile['contact_keywords']),
        radar_json($profile['positive_features']),
        radar_json($profile['negative_features']),
        radar_json($profile['recommended_terms']),
        radar_json($profile['excluded_terms']),
        radar_json($profile['scoring_rules']),
        radar_json($profile['seed_ids']),
        $manualOverride ? 1 : 0,
        (int)(current_user()['id'] ?? 0) ?: null,
    ];
    if ($row) {
        db()->prepare('UPDATE crm_radar_profiles SET profile_name=?, profile_title=?, sample_count=?, total_weight=?, company_keywords_json=?, product_keywords_json=?, project_keywords_json=?, city_keywords_json=?, position_keywords_json=?, contact_keywords_json=?, positive_features_json=?, negative_features_json=?, recommended_terms_json=?, excluded_terms_json=?, scoring_rules_json=?, seed_ids_json=?, manual_override=?, updated_by=?, generated_at=NOW(), updated_at=NOW() WHERE model_key=?')
            ->execute(array_merge([$profile['profile_title']], array_slice($data, 2), [$profile['model_key']]));
    } else {
        db()->prepare('INSERT INTO crm_radar_profiles (profile_name, model_key, profile_title, sample_count, total_weight, company_keywords_json, product_keywords_json, project_keywords_json, city_keywords_json, position_keywords_json, contact_keywords_json, positive_features_json, negative_features_json, recommended_terms_json, excluded_terms_json, scoring_rules_json, seed_ids_json, manual_override, created_by, updated_by, generated_at, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW(),NOW(),NOW())')
            ->execute(array_merge($data, [(int)(current_user()['id'] ?? 0) ?: null]));
    }
}

function radar_generate_all_profiles(bool $force = false): array
{
    radar_ensure_tables();
    crm_require('radar_profile_manage');
    $models = ['direct_buyer','design_influencer','brand_oem'];
    foreach ($models as $model) radar_profile_upsert(radar_build_profile($model), false, $force);
    radar_log('profile_generate', ['models' => $models, 'force' => $force]);
    return radar_profiles_list([]);
}

function radar_profiles_list(array $input): array
{
    radar_ensure_tables();
    crm_require('radar_view');
    $existing = db()->query("SELECT COUNT(*) FROM crm_radar_profiles WHERE model_key IN ('direct_buyer','design_influencer','brand_oem')")->fetchColumn();
    if ((int)$existing < 3 && crm_can('radar_profile_manage')) {
        foreach (['direct_buyer','design_influencer','brand_oem'] as $model) radar_profile_upsert(radar_build_profile($model), false);
    }
    $rows = db()->query("SELECT p.*, u.username AS updated_by_name FROM crm_radar_profiles p LEFT JOIN crm_users u ON u.id=p.updated_by WHERE p.model_key IN ('direct_buyer','design_influencer','brand_oem') ORDER BY FIELD(p.model_key,'direct_buyer','design_influencer','brand_oem')")->fetchAll();
    foreach ($rows as &$row) {
        foreach (['company_keywords','product_keywords','project_keywords','city_keywords','position_keywords','contact_keywords','positive_features','negative_features','recommended_terms','excluded_terms','scoring_rules','seed_ids'] as $key) {
            $row[$key] = radar_decode_json($row[$key . '_json'] ?? '[]');
        }
    }
    return ['rows' => $rows, 'models' => radar_model_options()];
}

function radar_profile_save(array $input): array
{
    radar_ensure_tables();
    crm_require('radar_profile_manage');
    $model = (string)($input['model_key'] ?? '');
    if (!in_array($model, ['direct_buyer','design_influencer','brand_oem'], true)) throw new RuntimeException('客户模型不正确');
    $profile = radar_build_profile($model);
    foreach (['company_keywords','product_keywords','project_keywords','city_keywords','position_keywords','contact_keywords','positive_features','negative_features','recommended_terms','excluded_terms','scoring_rules'] as $key) {
        $profile[$key] = radar_arr($input[$key] ?? []);
    }
    radar_profile_upsert($profile, true);
    radar_log('profile_manual_update', ['model' => $model]);
    return radar_profiles_list([]);
}

function radar_export_rows(string $type): array
{
    radar_ensure_tables();
    crm_require($type === 'keywords' ? 'radar_rule_manage' : 'radar_seed_manage');
    if ($type === 'keywords') {
        radar_log('keyword_export');
        $rows = db()->query("SELECT keyword, language, country, city, model_key, keyword_category, weight, status, source, usage_count, found_count, valid_count, average_score FROM crm_radar_search_keywords WHERE deleted_at IS NULL ORDER BY id DESC")->fetchAll();
    } else {
        radar_log('seed_export');
        $rows = db()->query("SELECT seed_name, local_name, website, country, city, company_type, model_key, company_intro, main_products, project_types, inquiry_products, purchase_products, deal_status, cooperation_status, deal_amount, cooperation_duration, match_reason, mismatch_reason, sample_type, sample_weight, negative_reason, contact_name, contact_position, contact_email, contact_phone, contact_whatsapp, contact_linkedin, contact_facebook, note, status, created_at, updated_at FROM crm_radar_seed_customers WHERE deleted_at IS NULL ORDER BY id DESC")->fetchAll();
    }
    return ['rows' => $rows];
}

function radar_task_status_options(): array
{
    return [
        'draft' => '草稿',
        'pending' => '等待执行',
        'generating_keywords' => '正在生成关键词',
        'searching' => '正在搜索',
        'fetching_pages' => '正在解析网页',
        'identifying_company' => '正在识别公司',
        'waiting_analysis' => '等待后续分析',
        'completed' => '已完成',
        'partial_completed' => '部分完成',
        'paused' => '已暂停',
        'cancelled' => '已取消',
        'failed' => '执行失败',
    ];
}

function radar_task_modes(): array
{
    return ['manual' => '手动立即执行', 'scheduled' => '指定时间执行', 'daily' => '每日执行', 'weekly' => '每周执行'];
}

function radar_bool(array $input, string $key, int $default = 0): int
{
    if (!array_key_exists($key, $input)) return $default;
    return !empty($input[$key]) && (string)$input[$key] !== '0' ? 1 : 0;
}

function radar_task_input(array $input, ?array $old = null): array
{
    $model = (string)($input['model_key'] ?? ($old['model_key'] ?? 'direct_buyer'));
    if (!isset(radar_model_options()[$model]) || $model === 'negative_sample') $model = 'direct_buyer';
    $mode = (string)($input['execute_mode'] ?? ($old['execute_mode'] ?? 'manual'));
    if (!isset(radar_task_modes()[$mode])) $mode = 'manual';
    $status = (string)($input['task_status'] ?? ($old['task_status'] ?? 'draft'));
    if (!isset(radar_task_status_options()[$status])) $status = 'draft';
    return [
        'task_name' => mb_substr(trim((string)($input['task_name'] ?? ($old['task_name'] ?? ''))), 0, 190),
        'country' => mb_substr(trim((string)($input['country'] ?? ($old['country'] ?? ''))), 0, 120),
        'city' => mb_substr(trim((string)($input['city'] ?? ($old['city'] ?? ''))), 0, 190),
        'model_key' => $model,
        'seed_ids_json' => json_encode(array_values(array_filter(array_map('intval', (array)($input['seed_ids'] ?? radar_decode_json($old['seed_ids_json'] ?? '[]'))))), JSON_UNESCAPED_UNICODE),
        'target_products' => trim((string)($input['target_products'] ?? ($old['target_products'] ?? ''))),
        'target_project_types' => trim((string)($input['target_project_types'] ?? ($old['target_project_types'] ?? ''))),
        'languages_json' => json_encode(radar_arr($input['languages'] ?? radar_decode_json($old['languages_json'] ?? '[]')), JSON_UNESCAPED_UNICODE),
        'keyword_ids_json' => json_encode(array_values(array_filter(array_map('intval', (array)($input['keyword_ids'] ?? radar_decode_json($old['keyword_ids_json'] ?? '[]'))))), JSON_UNESCAPED_UNICODE),
        'keywords_json' => json_encode(radar_arr($input['keywords'] ?? radar_decode_json($old['keywords_json'] ?? '[]')), JSON_UNESCAPED_UNICODE),
        'exclude_keywords_json' => json_encode(radar_arr($input['exclude_keywords'] ?? radar_decode_json($old['exclude_keywords_json'] ?? '[]')), JSON_UNESCAPED_UNICODE),
        'target_candidate_count' => max(0, min(1000, (int)($input['target_candidate_count'] ?? ($old['target_candidate_count'] ?? 30)))),
        'min_score' => max(0, min(100, (int)($input['min_score'] ?? ($old['min_score'] ?? 60)))),
        'must_have_website' => radar_bool($input, 'must_have_website', (int)($old['must_have_website'] ?? 1)),
        'must_have_email' => radar_bool($input, 'must_have_email', (int)($old['must_have_email'] ?? 0)),
        'must_have_contact' => radar_bool($input, 'must_have_contact', (int)($old['must_have_contact'] ?? 0)),
        'allow_design_studio' => radar_bool($input, 'allow_design_studio', (int)($old['allow_design_studio'] ?? 1)),
        'exclude_factory' => radar_bool($input, 'exclude_factory', (int)($old['exclude_factory'] ?? 1)),
        'exclude_retailer' => radar_bool($input, 'exclude_retailer', (int)($old['exclude_retailer'] ?? 1)),
        'exclude_decorative' => radar_bool($input, 'exclude_decorative', (int)($old['exclude_decorative'] ?? 1)),
        'exclude_brand_branch' => radar_bool($input, 'exclude_brand_branch', (int)($old['exclude_brand_branch'] ?? 1)),
        'check_crm_duplicate' => radar_bool($input, 'check_crm_duplicate', (int)($old['check_crm_duplicate'] ?? 1)),
        'enrich_contacts' => radar_bool($input, 'enrich_contacts', (int)($old['enrich_contacts'] ?? 0)),
        'verify_email' => radar_bool($input, 'verify_email', (int)($old['verify_email'] ?? 0)),
        'auto_to_crm' => 0,
        'auto_send_email' => 0,
        'single_task_cost_limit' => max(0, (float)($input['single_task_cost_limit'] ?? ($old['single_task_cost_limit'] ?? 0))),
        'daily_cost_limit' => max(0, (float)($input['daily_cost_limit'] ?? ($old['daily_cost_limit'] ?? 0))),
        'daily_candidate_limit' => max(0, (int)($input['daily_candidate_limit'] ?? ($old['daily_candidate_limit'] ?? 0))),
        'execute_mode' => $mode,
        'execute_at' => trim((string)($input['execute_at'] ?? ($old['execute_at'] ?? ''))) ?: null,
        'repeat_rule' => mb_substr(trim((string)($input['repeat_rule'] ?? ($old['repeat_rule'] ?? ''))), 0, 80),
        'executor_user_id' => (int)($input['executor_user_id'] ?? ($old['executor_user_id'] ?? 0)) ?: null,
        'task_status' => $status,
    ];
}

function radar_task_select_sql(): string
{
    return 'SELECT t.*, cu.username AS created_by_name, uu.username AS updated_by_name, eu.username AS executor_name FROM crm_radar_search_tasks t LEFT JOIN crm_users cu ON cu.id=t.created_by LEFT JOIN crm_users uu ON uu.id=t.updated_by LEFT JOIN crm_users eu ON eu.id=t.executor_user_id';
}

function radar_task_order_sql(string $alias = 't'): string
{
    $prefix = $alias !== '' ? $alias . '.' : '';
    return "CASE WHEN {$prefix}sort_order>0 THEN 0 ELSE 1 END ASC, {$prefix}sort_order ASC, {$prefix}id DESC";
}

function radar_ensure_default_search_service(): void
{
    $stmt = db()->prepare('SELECT id FROM crm_radar_search_services WHERE service_key=? LIMIT 1');
    $stmt->execute(['manual_config']);
    if ($stmt->fetchColumn()) return;
    db()->prepare('INSERT INTO crm_radar_search_services (service_key, service_name, api_url, result_limit, daily_limit, is_enabled, priority_order, timeout_seconds, retry_count, cost_per_call, config_json, created_at, updated_at) VALUES ("manual_config","合法搜索API配置位","",5,100,0,100,15,2,0,?,NOW(),NOW())')
        ->execute([json_encode(['note' => '填写合法网页搜索API地址和密钥后启用；默认不调用外网。'], JSON_UNESCAPED_UNICODE)]);
}

function radar_ensure_test_task(): void
{
    $stmt = db()->prepare('SELECT id FROM crm_radar_search_tasks WHERE task_name=? LIMIT 1');
    $stmt->execute(['越南精准客户第一轮测试']);
    if ($stmt->fetchColumn()) return;
    $seedStmt = db()->query("SELECT id FROM crm_radar_seed_customers WHERE deleted_at IS NULL AND seed_name IN ('CROLED Lighting JSC','ADES Lighting Vietnam','Alis Lighting') ORDER BY FIELD(seed_name,'CROLED Lighting JSC','ADES Lighting Vietnam','Alis Lighting')");
    $seedIds = array_map('intval', $seedStmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
    $userId = (int)(current_user()['id'] ?? 0) ?: null;
    $data = radar_task_input([
        'task_name' => '越南精准客户第一轮测试',
        'country' => '越南',
        'city' => '河内、胡志明市',
        'model_key' => 'direct_buyer',
        'seed_ids' => $seedIds,
        'target_candidate_count' => 30,
        'min_score' => 60,
        'must_have_website' => 1,
        'must_have_email' => 0,
        'exclude_decorative' => 1,
        'exclude_factory' => 1,
        'exclude_brand_branch' => 1,
        'check_crm_duplicate' => 1,
        'enrich_contacts' => 0,
        'verify_email' => 0,
        'task_status' => 'draft',
        'execute_mode' => 'manual',
        'languages' => ['en','vi'],
        'keywords' => ['Vietnam architectural lighting distributor','Vietnam commercial lighting supplier'],
    ]);
    db()->prepare('INSERT INTO crm_radar_search_tasks (task_name,country,city,model_key,seed_ids_json,target_products,target_project_types,languages_json,keyword_ids_json,keywords_json,exclude_keywords_json,target_candidate_count,min_score,must_have_website,must_have_email,must_have_contact,allow_design_studio,exclude_factory,exclude_retailer,exclude_decorative,exclude_brand_branch,check_crm_duplicate,enrich_contacts,verify_email,auto_to_crm,auto_send_email,single_task_cost_limit,daily_cost_limit,daily_candidate_limit,execute_mode,execute_at,repeat_rule,executor_user_id,task_status,created_by,updated_by,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW(),NOW())')
        ->execute(array_merge(array_values($data), [$userId, $userId]));
    radar_log('task_test_draft_created', ['task_name' => '越南精准客户第一轮测试']);
}

function radar_tasks_list(array $input): array
{
    radar_ensure_tables();
    crm_require('radar_view');
    radar_ensure_test_task();
    $where = ['t.deleted_at IS NULL'];
    $params = [];
    if (($input['status'] ?? '') !== '') { $where[] = 't.task_status=?'; $params[] = (string)$input['status']; }
    if (($input['model_key'] ?? '') !== '') { $where[] = 't.model_key=?'; $params[] = (string)$input['model_key']; }
    $q = trim((string)($input['q'] ?? ''));
    if ($q !== '') { $where[] = '(t.task_name LIKE ? OR t.country LIKE ? OR t.city LIKE ?)'; $params[] = "%$q%"; $params[] = "%$q%"; $params[] = "%$q%"; }
    $sqlWhere = implode(' AND ', $where);
    $stmt = db()->prepare(radar_task_select_sql() . " WHERE $sqlWhere ORDER BY " . radar_task_order_sql('t') . " LIMIT 100");
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
    $ids = array_values(array_filter(array_map(static fn($r) => (int)($r['id'] ?? 0), $rows)));
    $stats = [];
    if ($ids) {
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $jobStats = db()->prepare("SELECT task_id,
            SUM(CASE WHEN job_status='done' THEN 1 ELSE 0 END) done_count,
            SUM(CASE WHEN job_status='failed' THEN 1 ELSE 0 END) failed_count,
            SUM(CASE WHEN job_status='running' THEN 1 ELSE 0 END) running_count,
            SUM(CASE WHEN job_status='pending' THEN 1 ELSE 0 END) pending_count,
            COUNT(*) total_count
            FROM crm_radar_job_queue
            WHERE job_type='search_keyword' AND task_id IN ($ph)
            GROUP BY task_id");
        $jobStats->execute($ids);
        foreach ($jobStats->fetchAll() as $s) $stats[(int)$s['task_id']] = $s;
    }
    foreach ($rows as &$row) {
        $taskId = (int)($row['id'] ?? 0);
        $s = $stats[$taskId] ?? [];
        $total = (int)($s['total_count'] ?? 0);
        if ($total <= 0) $total = count(radar_task_keywords($row));
        $done = (int)($s['done_count'] ?? 0);
        $failed = (int)($s['failed_count'] ?? 0);
        $running = (int)($s['running_count'] ?? 0);
        $pending = (int)($s['pending_count'] ?? 0);
        $finished = $done + $failed;
        $current = $total > 0 ? min($total, $finished + (($running + $pending) > 0 ? 1 : 0)) : 0;
        $row['search_total_count'] = $total;
        $row['search_done_count'] = $done;
        $row['search_failed_count'] = $failed;
        $row['search_running_count'] = $running;
        $row['search_pending_count'] = $pending;
        $row['search_current_no'] = $current;
    }
    unset($row);
    return ['rows' => $rows, 'statuses' => radar_task_status_options(), 'models' => radar_model_options(), 'modes' => radar_task_modes()];
}

function radar_task_get(int $id): array
{
    radar_ensure_tables();
    crm_require('radar_view');
    $stmt = db()->prepare(radar_task_select_sql() . ' WHERE t.id=? AND t.deleted_at IS NULL LIMIT 1');
    $stmt->execute([$id]);
    $task = $stmt->fetch();
    if (!$task) throw new RuntimeException('搜索任务不存在');
    foreach (['seed_ids','languages','keyword_ids','keywords','exclude_keywords'] as $key) {
        $task[$key] = radar_decode_json($task[$key . '_json'] ?? '[]');
    }
    $jobs = db()->prepare('SELECT job_type, job_status, COUNT(*) c, SUM(CASE WHEN last_error IS NOT NULL AND last_error<>"" THEN 1 ELSE 0 END) errors FROM crm_radar_job_queue WHERE task_id=? GROUP BY job_type, job_status ORDER BY job_type, job_status');
    $jobs->execute([$id]);
    $raw = db()->prepare('SELECT * FROM crm_radar_raw_results WHERE task_id=? ORDER BY id DESC LIMIT 80');
    $raw->execute([$id]);
    $logs = db()->prepare("SELECT * FROM crm_radar_logs WHERE module_key='radar' AND content_json LIKE ? ORDER BY id DESC LIMIT 80");
    try { $logs->execute(['%"task_id":' . $id . '%']); $logRows = $logs->fetchAll(); } catch (Throwable $e) { $logRows = []; }
    $usage = db()->prepare('SELECT * FROM crm_radar_usage WHERE task_id=? ORDER BY id DESC LIMIT 80');
    $usage->execute([$id]);
    return ['task' => $task, 'jobs' => $jobs->fetchAll(), 'raw_results' => $raw->fetchAll(), 'logs' => $logRows, 'usage' => $usage->fetchAll()];
}

function radar_task_save(array $input): array
{
    radar_ensure_tables();
    crm_require('radar_task_create');
    $id = (int)($input['id'] ?? 0);
    $old = null;
    if ($id > 0) {
        $st = db()->prepare('SELECT * FROM crm_radar_search_tasks WHERE id=? LIMIT 1');
        $st->execute([$id]);
        $old = $st->fetch();
        if (!$old) throw new RuntimeException('搜索任务不存在');
        if (!in_array((string)$old['task_status'], ['draft','paused','failed','cancelled'], true)) throw new RuntimeException('执行中的任务不可直接修改，请先暂停或复制任务。');
    }
    $data = radar_task_input($input, $old ?: null);
    if ($data['task_name'] === '') throw new RuntimeException('任务名称不能为空');
    $userId = (int)(current_user()['id'] ?? 0) ?: null;
    if ($id > 0) {
        db()->prepare('UPDATE crm_radar_search_tasks SET task_name=?,country=?,city=?,model_key=?,seed_ids_json=?,target_products=?,target_project_types=?,languages_json=?,keyword_ids_json=?,keywords_json=?,exclude_keywords_json=?,target_candidate_count=?,min_score=?,must_have_website=?,must_have_email=?,must_have_contact=?,allow_design_studio=?,exclude_factory=?,exclude_retailer=?,exclude_decorative=?,exclude_brand_branch=?,check_crm_duplicate=?,enrich_contacts=?,verify_email=?,auto_to_crm=?,auto_send_email=?,single_task_cost_limit=?,daily_cost_limit=?,daily_candidate_limit=?,execute_mode=?,execute_at=?,repeat_rule=?,executor_user_id=?,task_status=?,updated_by=?,updated_at=NOW() WHERE id=?')
            ->execute(array_merge(array_values($data), [$userId, $id]));
        radar_log('task_update', ['task_id' => $id, 'name' => $data['task_name']]);
    } else {
        db()->prepare('INSERT INTO crm_radar_search_tasks (task_name,country,city,model_key,seed_ids_json,target_products,target_project_types,languages_json,keyword_ids_json,keywords_json,exclude_keywords_json,target_candidate_count,min_score,must_have_website,must_have_email,must_have_contact,allow_design_studio,exclude_factory,exclude_retailer,exclude_decorative,exclude_brand_branch,check_crm_duplicate,enrich_contacts,verify_email,auto_to_crm,auto_send_email,single_task_cost_limit,daily_cost_limit,daily_candidate_limit,execute_mode,execute_at,repeat_rule,executor_user_id,task_status,created_by,updated_by,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW(),NOW())')
            ->execute(array_merge(array_values($data), [$userId, $userId]));
        $id = (int)db()->lastInsertId();
        radar_log('task_create', ['task_id' => $id, 'name' => $data['task_name']]);
    }
    return radar_task_get($id);
}

function radar_task_keywords(array $task, int $limit = 0): array
{
    $keywords = radar_decode_json($task['keywords_json'] ?? '[]');
    $ids = radar_decode_json($task['keyword_ids_json'] ?? '[]');
    if ($ids) {
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $st = db()->prepare("SELECT keyword FROM crm_radar_search_keywords WHERE id IN ($ph) AND deleted_at IS NULL AND status='active' ORDER BY weight DESC,id ASC");
        $st->execute($ids);
        $keywords = array_merge($keywords, $st->fetchAll(PDO::FETCH_COLUMN) ?: []);
    }
    if (!$keywords) {
        $st = db()->prepare("SELECT keyword FROM crm_radar_search_keywords WHERE deleted_at IS NULL AND status='active' AND model_key=? AND keyword_type<>'exclude' ORDER BY weight DESC,id ASC LIMIT 20");
        $st->execute([(string)($task['model_key'] ?? 'direct_buyer')]);
        $keywords = $st->fetchAll(PDO::FETCH_COLUMN) ?: [];
    }
    $keywords = array_values(array_unique(array_filter(array_map('trim', $keywords))));
    return $limit > 0 ? array_slice($keywords, 0, $limit) : $keywords;
}

function radar_queue_job(int $taskId, string $type, array $payload = [], int $maxAttempts = 3): int
{
    db()->prepare('INSERT INTO crm_radar_job_queue (task_id, job_type, job_status, payload_json, attempts, max_attempts, scheduled_at, created_at, updated_at) VALUES (?, ?, "pending", ?, 0, ?, NOW(), NOW(), NOW())')
        ->execute([$taskId, $type, json_encode($payload, JSON_UNESCAPED_UNICODE), $maxAttempts]);
    return (int)db()->lastInsertId();
}

function radar_task_start(int $id): array
{
    radar_ensure_tables();
    crm_require('radar_task_run');
    $detail = radar_task_get($id);
    $task = $detail['task'];
    if (in_array((string)$task['task_status'], ['cancelled','completed','searching','generating_keywords','fetching_pages','identifying_company','waiting_analysis'], true)) {
        throw new RuntimeException('当前状态不能启动：' . (radar_task_status_options()[$task['task_status']] ?? $task['task_status']));
    }
    db()->prepare("DELETE FROM crm_radar_job_queue WHERE task_id=? AND job_status IN ('pending','failed')")->execute([$id]);
    radar_queue_job($id, 'generate_keywords', ['limit' => 0]);
    db()->prepare("UPDATE crm_radar_search_tasks SET task_status='pending', progress_percent=0, searched_pages=0, found_companies=0, failed_count=0, started_at=NULL, finished_at=NULL, last_error=NULL, paused_at=NULL, cancelled_at=NULL, updated_at=NOW() WHERE id=?")->execute([$id]);
    radar_log('task_start', ['task_id' => $id]);
    return radar_task_get($id);
}

function radar_task_control(int $id, string $action): array
{
    radar_ensure_tables();
    if ($action === 'pause') crm_require('radar_task_pause'); else crm_require('radar_task_run');
    $map = [
        'pause' => ["paused", 'paused_at=NOW()'],
        'resume' => ["pending", 'paused_at=NULL'],
        'cancel' => ["cancelled", 'cancelled_at=NOW()'],
    ];
    if (!isset($map[$action])) throw new RuntimeException('不支持的任务操作');
    [$status, $extra] = $map[$action];
    db()->prepare("UPDATE crm_radar_search_tasks SET task_status=?, $extra, updated_at=NOW() WHERE id=?")->execute([$status, $id]);
    if ($action === 'cancel') db()->prepare("UPDATE crm_radar_job_queue SET job_status='cancelled', finished_at=NOW(), updated_at=NOW() WHERE task_id=? AND job_status IN ('pending','running')")->execute([$id]);
    if ($action === 'resume') db()->prepare("UPDATE crm_radar_job_queue SET job_status='pending', scheduled_at=NOW(), updated_at=NOW() WHERE task_id=? AND job_status='paused'")->execute([$id]);
    radar_log('task_' . $action, ['task_id' => $id]);
    return radar_task_get($id);
}

function radar_task_delete(int $id): array
{
    radar_ensure_tables();
    crm_require('radar_task_run');
    if ($id <= 0) throw new RuntimeException('搜索任务不存在');
    $detail = radar_task_get($id);
    $task = $detail['task'];
    if (in_array((string)($task['task_status'] ?? ''), ['generating_keywords','searching','fetching_pages','identifying_company','waiting_analysis'], true)) {
        throw new RuntimeException('执行中的任务请先暂停或取消后再删除');
    }
    db()->prepare("UPDATE crm_radar_job_queue SET job_status='cancelled', finished_at=NOW(), updated_at=NOW() WHERE task_id=? AND job_status IN ('pending','running','paused')")
        ->execute([$id]);
    db()->prepare("UPDATE crm_radar_search_tasks SET task_status='cancelled', cancelled_at=COALESCE(cancelled_at,NOW()), deleted_at=NOW(), updated_at=NOW() WHERE id=? AND deleted_at IS NULL")
        ->execute([$id]);
    radar_log('task_delete', ['task_id' => $id, 'name' => (string)($task['task_name'] ?? '')]);
    return ['deleted' => true, 'id' => $id];
}

function radar_task_reorder(array $input): array
{
    radar_ensure_tables();
    crm_require('radar_task_run');
    $ids = $input['ids'] ?? [];
    if (is_string($ids)) $ids = preg_split('/[\s,]+/', $ids, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    if (!is_array($ids)) $ids = [];
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
    if (!$ids) throw new RuntimeException('没有可保存的任务顺序');
    $stmt = db()->prepare('UPDATE crm_radar_search_tasks SET sort_order=?, updated_at=NOW() WHERE id=? AND deleted_at IS NULL');
    $order = 10;
    $saved = 0;
    foreach ($ids as $id) {
        $stmt->execute([$order, $id]);
        $saved += $stmt->rowCount() > 0 ? 1 : 0;
        $order += 10;
    }
    radar_log('task_reorder', ['ids' => $ids, 'saved' => $saved]);
    return ['saved' => $saved, 'ids' => $ids];
}

function radar_task_copy(int $id): array
{
    radar_ensure_tables();
    crm_require('radar_task_create');
    $detail = radar_task_get($id);
    $task = $detail['task'];
    $task['task_name'] = '复制 - ' . (string)$task['task_name'];
    $task['task_status'] = 'draft';
    unset($task['id']);
    return radar_task_save($task);
}

function radar_template_select_sql(): string
{
    return 'SELECT t.*, cu.username AS created_by_name, uu.username AS updated_by_name FROM crm_radar_search_templates t LEFT JOIN crm_users cu ON cu.id=t.created_by LEFT JOIN crm_users uu ON uu.id=t.updated_by';
}

function radar_template_decode(array $row): array
{
    $row['config'] = radar_decode_json($row['config_json'] ?? '{}');
    $row['preset_keywords'] = radar_decode_json($row['preset_keywords_json'] ?? '{}');
    $row['exclude_keywords'] = radar_decode_json($row['exclude_keywords_json'] ?? '{}');
    return $row;
}

function radar_template_effect_stats(int $templateId): array
{
    $empty = ['use_count' => 0, 'generated_keyword_count' => 0, 'brave_call_count' => 0, 'result_count' => 0, 'company_count' => 0, 'grade_a_count' => 0, 'grade_b_count' => 0, 'grade_c_count' => 0, 'grade_d_count' => 0, 'precise_count' => 0, 'converted_count' => 0, 'avg_valid_customer_cost' => 0];
    if ($templateId <= 0 || !radar_column_exists('crm_radar_search_tasks', 'template_id')) return $empty;
    $taskIds = db()->prepare('SELECT id FROM crm_radar_search_tasks WHERE template_id=?');
    $taskIds->execute([$templateId]);
    $ids = array_map('intval', $taskIds->fetchAll(PDO::FETCH_COLUMN) ?: []);
    $empty['use_count'] = count($ids);
    if (!$ids) return $empty;
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $empty['generated_keyword_count'] = (int)(db()->query("SELECT COALESCE(SUM(keyword_count),0) FROM crm_radar_template_usage WHERE template_id=" . (int)$templateId)->fetchColumn() ?: 0);
    $calls = db()->prepare("SELECT COUNT(*) FROM crm_radar_usage WHERE task_id IN ($ph) AND provider='search_keyword'");
    $calls->execute($ids);
    $empty['brave_call_count'] = (int)($calls->fetchColumn() ?: 0);
    $st = db()->prepare("SELECT COUNT(*) FROM crm_radar_raw_results WHERE task_id IN ($ph)");
    $st->execute($ids);
    $empty['result_count'] = (int)$st->fetchColumn();
    $st = db()->prepare("SELECT grade, review_status, converted_customer_id FROM crm_radar_candidates WHERE deleted_at IS NULL AND task_id IN ($ph)");
    $st->execute($ids);
    $cost = 0.0;
    $valid = 0;
    foreach ($st->fetchAll() as $row) {
        $empty['company_count']++;
        $grade = strtoupper((string)($row['grade'] ?? 'D'));
        if ($grade === 'A') $empty['grade_a_count']++;
        elseif ($grade === 'B') $empty['grade_b_count']++;
        elseif ($grade === 'C') $empty['grade_c_count']++;
        else $empty['grade_d_count']++;
        if (in_array((string)$row['review_status'], ['precise','basic_precise'], true)) { $empty['precise_count']++; $valid++; }
        if (!empty($row['converted_customer_id'])) $empty['converted_count']++;
    }
    $usage = db()->prepare("SELECT COALESCE(SUM(cost_amount),0) FROM crm_radar_usage WHERE task_id IN ($ph)");
    $usage->execute($ids);
    $cost = (float)$usage->fetchColumn();
    $empty['avg_valid_customer_cost'] = $valid ? round($cost / $valid, 4) : 0;
    return $empty;
}

function radar_templates_list(array $input = []): array
{
    radar_ensure_tables();
    crm_require('radar_template_view');
    $where = ['t.deleted_at IS NULL'];
    $params = [];
    if (($input['status'] ?? '') !== '') { $where[] = 't.status=?'; $params[] = (string)$input['status']; }
    if (($input['country_code'] ?? '') !== '') { $where[] = 't.country_code=?'; $params[] = (string)$input['country_code']; }
    if (($input['model_key'] ?? '') !== '') { $where[] = 't.model_key=?'; $params[] = (string)$input['model_key']; }
    $q = trim((string)($input['q'] ?? ''));
    if ($q !== '') { $where[] = '(t.template_name LIKE ? OR t.template_code LIKE ? OR t.country LIKE ?)'; $params[] = "%$q%"; $params[] = "%$q%"; $params[] = "%$q%"; }
    $stmt = db()->prepare(radar_template_select_sql() . ' WHERE ' . implode(' AND ', $where) . ' ORDER BY t.is_default DESC,t.country_code ASC,t.model_key ASC,t.id ASC LIMIT 200');
    $stmt->execute($params);
    $rows = [];
    foreach ($stmt->fetchAll() as $row) {
        $row = radar_template_decode($row);
        $row['stats'] = radar_template_effect_stats((int)$row['id']);
        $rows[] = $row;
    }
    return ['rows' => $rows, 'modes' => radar_template_modes(), 'models' => radar_model_options()];
}

function radar_template_get(int $id): array
{
    radar_ensure_tables();
    crm_require('radar_template_view');
    $stmt = db()->prepare(radar_template_select_sql() . ' WHERE t.id=? AND t.deleted_at IS NULL LIMIT 1');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if (!$row) throw new RuntimeException('搜索模板不存在');
    $row = radar_template_decode($row);
    $row['stats'] = radar_template_effect_stats($id);
    return ['template' => $row];
}

function radar_template_input(array $input, ?array $old = null): array
{
    $code = preg_replace('/[^A-Z0-9_\-]/', '', strtoupper((string)($input['template_code'] ?? ($old['template_code'] ?? ''))));
    $name = mb_substr(trim((string)($input['template_name'] ?? ($old['template_name'] ?? ''))), 0, 190);
    $model = (string)($input['model_key'] ?? ($old['model_key'] ?? 'direct_buyer'));
    if (!isset(radar_model_options()[$model]) || $model === 'negative_sample') $model = 'direct_buyer';
    $config = is_array($input['config'] ?? null)
        ? $input['config']
        : radar_decode_json($input['config_json'] ?? ($old['config_json'] ?? '{}'));
    if (!$config) {
        foreach (radar_template_json_keys() as $key) $config[$key] = radar_arr($input[$key] ?? (($old['config'][$key] ?? [])));
        $config['defaults'] = [
            'result_limit' => max(1, min(20, (int)($input['default_result_limit'] ?? ($old['config']['defaults']['result_limit'] ?? 10)))),
            'max_keywords' => max(1, min(80, (int)($input['default_max_keywords'] ?? ($old['config']['defaults']['max_keywords'] ?? 40)))),
            'target_candidate_count' => max(1, min(1000, (int)($input['default_candidate_count'] ?? ($old['config']['defaults']['target_candidate_count'] ?? 30)))),
            'min_score' => max(0, min(100, (int)($input['default_min_score'] ?? ($old['config']['defaults']['min_score'] ?? 60)))),
        ];
    }
    $preset = radar_decode_json($input['preset_keywords_json'] ?? ($old['preset_keywords_json'] ?? '{}'));
    if (!$preset) $preset = ['en' => radar_arr($input['preset_en'] ?? []), 'local' => radar_arr($input['preset_local'] ?? [])];
    $exclude = radar_decode_json($input['exclude_keywords_json'] ?? ($old['exclude_keywords_json'] ?? '{}'));
    if (!$exclude) $exclude = ['en' => radar_arr($input['exclude_en'] ?? []), 'local' => radar_arr($input['exclude_local'] ?? [])];
    return [
        'template_code' => $code,
        'template_name' => $name,
        'country' => mb_substr(trim((string)($input['country'] ?? ($old['country'] ?? ''))), 0, 120),
        'country_code' => mb_substr(trim((string)($input['country_code'] ?? ($old['country_code'] ?? ''))), 0, 20),
        'country_local' => mb_substr(trim((string)($input['country_local'] ?? ($old['country_local'] ?? ''))), 0, 120),
        'model_key' => $model,
        'search_service_key' => preg_replace('/[^a-z0-9_\-]/i', '', (string)($input['search_service_key'] ?? ($old['search_service_key'] ?? 'brave'))) ?: 'brave',
        'status' => in_array((string)($input['status'] ?? ($old['status'] ?? 'active')), ['active','inactive'], true) ? (string)($input['status'] ?? ($old['status'] ?? 'active')) : 'active',
        'is_system' => (int)($old['is_system'] ?? 0),
        'is_default' => radar_bool($input, 'is_default', (int)($old['is_default'] ?? 0)),
        'mode_default' => isset(radar_template_modes()[(string)($input['mode_default'] ?? ($old['mode_default'] ?? 'standard'))]) ? (string)($input['mode_default'] ?? ($old['mode_default'] ?? 'standard')) : 'standard',
        'config_json' => radar_json($config),
        'preset_keywords_json' => radar_json($preset),
        'exclude_keywords_json' => radar_json($exclude),
        'warning_text' => mb_substr(trim((string)($input['warning_text'] ?? ($old['warning_text'] ?? ''))), 0, 1000),
    ];
}

function radar_template_save(array $input): array
{
    radar_ensure_tables();
    $id = (int)($input['id'] ?? 0);
    crm_require($id > 0 ? 'radar_template_edit' : 'radar_template_create');
    $old = null;
    if ($id > 0) {
        $old = radar_template_get($id)['template'];
        if (!empty($old['is_system']) && !has_permission('radar_template_restore') && !is_super_admin()) throw new RuntimeException('系统预设模板不能直接修改，请复制后编辑。');
    }
    $data = radar_template_input($input, $old);
    if ($data['template_code'] === '' || $data['template_name'] === '') throw new RuntimeException('模板名称和模板代码不能为空');
    $userId = (int)(current_user()['id'] ?? 0) ?: null;
    if ($id > 0) {
        db()->prepare('UPDATE crm_radar_search_templates SET template_code=?,template_name=?,country=?,country_code=?,country_local=?,model_key=?,search_service_key=?,status=?,is_system=?,is_default=?,mode_default=?,config_json=?,preset_keywords_json=?,exclude_keywords_json=?,warning_text=?,updated_by=?,updated_at=NOW() WHERE id=?')
            ->execute(array_merge(array_values($data), [$userId, $id]));
        radar_log('template_edit', ['template_id' => $id, 'template_code' => $data['template_code']]);
    } else {
        db()->prepare('INSERT INTO crm_radar_search_templates (template_code,template_name,country,country_code,country_local,model_key,search_service_key,status,is_system,is_default,mode_default,config_json,preset_keywords_json,exclude_keywords_json,warning_text,created_by,updated_by,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW(),NOW())')
            ->execute(array_merge(array_values($data), [$userId, $userId]));
        $id = (int)db()->lastInsertId();
        radar_log('template_create', ['template_id' => $id, 'template_code' => $data['template_code']]);
    }
    return radar_template_get($id);
}

function radar_template_status(array $input): array
{
    radar_ensure_tables();
    crm_require('radar_template_disable');
    $id = (int)($input['id'] ?? 0);
    $status = (string)($input['status'] ?? 'inactive');
    if (!in_array($status, ['active','inactive'], true)) throw new RuntimeException('模板状态无效');
    db()->prepare('UPDATE crm_radar_search_templates SET status=?, updated_by=?, updated_at=NOW() WHERE id=? AND deleted_at IS NULL')
        ->execute([$status, (int)(current_user()['id'] ?? 0) ?: null, $id]);
    radar_log($status === 'active' ? 'template_enable' : 'template_disable', ['template_id' => $id]);
    return radar_template_get($id);
}

function radar_template_delete(int $id): array
{
    radar_ensure_tables();
    crm_require('radar_template_delete');
    $tpl = radar_template_get($id)['template'];
    if (!empty($tpl['is_system'])) throw new RuntimeException('系统预设模板不允许物理删除，只能停用或恢复默认。');
    db()->prepare("UPDATE crm_radar_search_templates SET status='deleted', deleted_at=NOW(), updated_by=?, updated_at=NOW() WHERE id=?")->execute([(int)(current_user()['id'] ?? 0) ?: null, $id]);
    radar_log('template_delete', ['template_id' => $id]);
    return ['deleted' => true];
}

function radar_template_copy(array $input): array
{
    radar_ensure_tables();
    crm_require('radar_template_create');
    $tpl = radar_template_get((int)($input['id'] ?? 0))['template'];
    $copyCode = preg_replace('/[^A-Z0-9_\-]/', '', strtoupper((string)($input['template_code'] ?? ($tpl['template_code'] . '_COPY_' . date('His')))));
    $copyName = mb_substr(trim((string)($input['template_name'] ?? ($tpl['template_name'] . ' - 复制'))), 0, 190);
    $data = $tpl;
    $data['template_code'] = $copyCode;
    $data['template_name'] = $copyName;
    $data['is_system'] = 0;
    $data['is_default'] = 0;
    $data['status'] = 'active';
    unset($data['id']);
    $saved = radar_template_save($data);
    radar_log('template_copy', ['source_id' => (int)$tpl['id'], 'new_id' => (int)$saved['template']['id']]);
    return $saved;
}

function radar_template_restore(int $id): array
{
    radar_ensure_tables();
    crm_require('radar_template_restore');
    $tpl = radar_template_get($id)['template'];
    if (empty($tpl['is_system'])) throw new RuntimeException('只有系统预设模板可恢复默认。');
    radar_template_ensure_presets(true, (string)$tpl['template_code']);
    radar_log('template_restore', ['template_id' => $id, 'template_code' => $tpl['template_code']]);
    return radar_template_get($id);
}

function radar_keyword_canonical(string $keyword): string
{
    $tokens = preg_split('/\s+/u', mb_strtolower(trim($keyword))) ?: [];
    $tokens = array_values(array_unique(array_filter($tokens, static fn($t) => $t !== '')));
    sort($tokens);
    return implode(' ', $tokens);
}

function radar_template_fill_pattern(string $pattern, array $vars): string
{
    foreach ($vars as $key => $value) $pattern = str_replace('{' . $key . '}', (string)$value, $pattern);
    return trim(preg_replace('/\s+/u', ' ', $pattern));
}

function radar_template_search_country(array $tpl): string
{
    $code = strtoupper((string)($tpl['country_code'] ?? ''));
    if ($code === 'VN') return 'Vietnam';
    if ($code === 'ID') return 'Indonesia';
    if ($code === 'AE') return 'UAE';
    return (string)($tpl['country'] ?? $tpl['country_code'] ?? '');
}

function radar_template_preview(array $input): array
{
    radar_ensure_tables();
    crm_require('radar_template_view');
    $tpl = radar_template_get((int)($input['id'] ?? 0))['template'];
    $mode = (string)($input['mode'] ?? ($tpl['mode_default'] ?? 'standard'));
    $modes = radar_template_modes();
    if (!isset($modes[$mode])) $mode = 'standard';
    $config = $tpl['config'] ?? [];
    $preset = $tpl['preset_keywords'] ?? [];
    $exclude = $tpl['exclude_keywords'] ?? [];
    $cities = radar_arr($input['cities'] ?? ($config['default_cities'] ?? []));
    $products = radar_arr($input['products'] ?? array_slice($config['products_en'] ?? [], 0, 3));
    $projects = radar_arr($input['projects'] ?? array_slice($config['projects_en'] ?? [], 0, 3));
    $clientTypes = radar_arr($input['client_types'] ?? array_slice($config['client_types_en'] ?? [], 0, 4));
    $localTypes = radar_arr($input['client_types_local'] ?? array_slice($config['client_types_local'] ?? [], 0, 4));
    $localProducts = radar_arr($input['products_local'] ?? array_slice($config['products_local'] ?? [], 0, 3));
    $siteKeywords = radar_arr($config['site_keywords'] ?? []);
    $patterns = $config['pattern_templates'] ?? [];
    $limit = max($modes[$mode]['min'], min($modes[$mode]['max'], (int)($input['max_keywords'] ?? ($modes[$mode]['default_limit'] ?? 32))));
    $rows = [];
    $seen = [];
    $add = static function (string $keyword, string $lang, int $weight, string $source) use (&$rows, &$seen, $limit) {
        $keyword = trim(preg_replace('/\s+/u', ' ', $keyword));
        if ($keyword === '' || mb_strlen($keyword) > 120) return;
        $canon = radar_keyword_canonical($keyword);
        if (isset($seen[$canon]) || count($rows) >= $limit) return;
        $seen[$canon] = true;
        $rows[] = ['keyword' => $keyword, 'language' => $lang, 'weight' => $weight, 'source' => $source, 'enabled' => 1];
    };
    $presetEn = array_values($preset['en'] ?? []);
    $presetLocal = array_values($preset['local'] ?? []);
    $presetMax = max(count($presetEn), count($presetLocal));
    $presetReserve = $mode === 'compact' ? min(8, max(4, intdiv($limit, 2))) : min(max(8, intdiv($limit, 2)), $limit);
    for ($i = 0; $i < $presetMax; $i++) {
        if (isset($presetEn[$i])) $add((string)$presetEn[$i], 'en', 100, 'preset');
        if (isset($presetLocal[$i])) $add((string)$presetLocal[$i], 'local', 96, 'preset');
        if (count($rows) >= $presetReserve) break;
    }
    $countrySearch = radar_template_search_country($tpl);
    if ($mode === 'compact') {
        $city0 = $cities[0] ?? '';
        $city1 = $cities[1] ?? $city0;
        $city2 = $cities[2] ?? $city1;
        $ct0 = $clientTypes[0] ?? '';
        $ct1 = $clientTypes[1] ?? $ct0;
        $ct2 = $clientTypes[2] ?? $ct1;
        $ct3 = $clientTypes[3] ?? $ct2;
        $ct4 = $clientTypes[4] ?? $ct3;
        $product0 = $products[0] ?? '';
        $product1 = $products[1] ?? $product0;
        $product2 = $products[2] ?? $product1;
        $project0 = $projects[0] ?? '';
        $project1 = $projects[1] ?? $project0;
        $project2 = $projects[2] ?? $project1;
        $localType0 = $localTypes[0] ?? '';
        if (($tpl['model_key'] ?? '') === 'project_procurement') {
            $balanced = [
                [$siteKeywords[0] ?? '', 'en'],
                [$siteKeywords[1] ?? '', 'en'],
                [$city0 . ' ' . $ct0, 'en'],
                [$city0 . ' ' . $ct1, 'en'],
                [$city0 . ' ' . $ct2, 'en'],
                [$city0 . ' ' . $ct3, 'en'],
                [$city1 . ' ' . $ct4, 'en'],
                [$project0 . ' contractor ' . $city0, 'en'],
                [$project1 . ' procurement ' . $city0, 'en'],
                ['contractor lighting procurement ' . $city0, 'en'],
                [$localType0 . ' ' . $city0, 'local'],
            ];
        } elseif (($tpl['model_key'] ?? '') === 'design_influencer') {
            $balanced = [
                [$siteKeywords[0] ?? '', 'en'],
                [$siteKeywords[1] ?? '', 'en'],
                [$city0 . ' lighting design studio', 'en'],
                [$city0 . ' lighting consultant', 'en'],
                [$city0 . ' lighting specification consultant', 'en'],
                [$project0 . ' lighting designer ' . $city0, 'en'],
                [$project2 . ' lighting consultant ' . $city1, 'en'],
                [$localType0 . ' ' . $city0, 'local'],
            ];
        } elseif (($tpl['model_key'] ?? '') === 'brand_oem') {
            $balanced = [
                [$siteKeywords[0] ?? '', 'en'],
                [$siteKeywords[1] ?? '', 'en'],
                [$city0 . ' lighting brand', 'en'],
                [$city0 . ' OEM lighting', 'en'],
                [$city0 . ' private label lighting', 'en'],
                [$city0 . ' lighting procurement manager', 'en'],
                [$city1 . ' lighting supply chain', 'en'],
                [$city0 . ' lighting product development', 'en'],
                [$localType0 . ' ' . $city0, 'local'],
            ];
        } else {
            $balanced = [
                [$siteKeywords[0] ?? '', 'en'],
                [$siteKeywords[1] ?? '', 'en'],
                [$city0 . ' ' . $ct0, 'en'],
                [$city1 . ' ' . $ct0, 'en'],
                [$city2 . ' ' . $project0 . ' lighting supplier', 'en'],
                [$countrySearch . ' ' . $product0 . ' supplier', 'en'],
                [$countrySearch . ' ' . $product1 . ' supplier', 'en'],
                [$countrySearch . ' ' . $product2 . ' supplier', 'en'],
                [$city0 . ' ' . $product0 . ' supplier', 'en'],
                [$localType0 . ' ' . $city0, 'local'],
            ];
        }
        foreach ($balanced as [$keyword, $lang]) {
            $add((string)$keyword, (string)$lang, 88, 'generated');
            if (count($rows) >= $limit) break;
        }
    }
    foreach ($patterns as $pattern) {
        foreach ($cities ?: [''] as $city) {
            foreach ($clientTypes ?: [''] as $ct) {
                foreach ($products ?: [''] as $product) {
                    foreach ($projects ?: [''] as $project) {
                        $add(radar_template_fill_pattern((string)$pattern, [
                            'country' => $countrySearch,
                            'country_local' => $tpl['country_local'] ?: $tpl['country'],
                            'city' => $city,
                            'client_type' => $ct,
                            'client_type_local' => $localTypes[0] ?? $ct,
                            'product' => $product,
                            'product_local' => $localProducts[0] ?? $product,
                            'project' => $project,
                            'project_local' => $project,
                        ]), (strpos((string)$pattern, '_local') !== false ? 'local' : 'en'), 80, 'generated');
                        if (count($rows) >= $limit) break 4;
                    }
                }
            }
        }
        if (count($rows) >= $limit) break;
    }
    usort($rows, static fn($a, $b) => ($b['weight'] <=> $a['weight']));
    $resultLimit = max(1, min(20, (int)($input['result_limit'] ?? ($config['defaults']['result_limit'] ?? $modes[$mode]['result_limit']))));
    $service = db()->prepare('SELECT service_key, service_name, is_enabled, cost_per_call FROM crm_radar_search_services WHERE service_key=? LIMIT 1');
    $service->execute([(string)($tpl['search_service_key'] ?? 'brave')]);
    $svc = $service->fetch() ?: ['service_key' => (string)($tpl['search_service_key'] ?? 'brave'), 'service_name' => '未配置', 'is_enabled' => 0, 'cost_per_call' => 0];
    radar_log('template_preview', ['template_id' => (int)$tpl['id'], 'mode' => $mode, 'keyword_count' => count($rows)]);
    return [
        'template' => $tpl,
        'mode' => $mode,
        'keywords' => $rows,
        'exclude_keywords' => $exclude,
        'estimated' => [
            'keyword_count' => count($rows),
            'api_calls' => count($rows),
            'max_results' => count($rows) * $resultLimit,
            'cost' => round(count($rows) * (float)($svc['cost_per_call'] ?? 0), 4),
        ],
        'search_service' => $svc,
        'inputs' => ['cities' => $cities, 'products' => $products, 'projects' => $projects, 'client_types' => $clientTypes],
    ];
}

function radar_template_create_task(array $input): array
{
    radar_ensure_tables();
    crm_require('radar_template_create_task');
    $preview = radar_template_preview($input);
    $tpl = $preview['template'];
    $keywordRows = radar_decode_json($input['keywords_json'] ?? '');
    if (!$keywordRows) $keywordRows = $preview['keywords'];
    $keywords = array_values(array_filter(array_map(static fn($r) => !empty($r['enabled']) ? (string)($r['keyword'] ?? '') : '', $keywordRows)));
    $config = $tpl['config'] ?? [];
    $defaults = $config['defaults'] ?? [];
    $taskInput = [
        'task_name' => mb_substr(trim((string)($input['task_name'] ?? ($tpl['template_name'] . ' - 草稿'))), 0, 190),
        'country' => $tpl['country'],
        'city' => implode('、', $preview['inputs']['cities'] ?? []),
        'model_key' => $tpl['model_key'],
        'target_products' => implode(', ', $preview['inputs']['products'] ?? []),
        'target_project_types' => implode(', ', $preview['inputs']['projects'] ?? []),
        'languages' => ['en', 'local'],
        'keywords' => $keywords,
        'exclude_keywords' => array_merge($preview['exclude_keywords']['en'] ?? [], $preview['exclude_keywords']['local'] ?? []),
        'target_candidate_count' => (int)($input['target_candidate_count'] ?? ($defaults['target_candidate_count'] ?? 30)),
        'min_score' => (int)($input['min_score'] ?? ($defaults['min_score'] ?? 60)),
        'must_have_website' => 1,
        'must_have_email' => 0,
        'must_have_contact' => 0,
        'allow_design_studio' => 1,
        'exclude_factory' => $tpl['model_key'] === 'direct_buyer' ? 1 : 0,
        'exclude_retailer' => 1,
        'exclude_decorative' => 1,
        'exclude_brand_branch' => $tpl['model_key'] === 'direct_buyer' ? 1 : 0,
        'check_crm_duplicate' => 1,
        'enrich_contacts' => 0,
        'verify_email' => 0,
        'auto_to_crm' => 0,
        'auto_send_email' => 0,
        'execute_mode' => 'manual',
        'task_status' => 'draft',
    ];
    $data = radar_task_input($taskInput);
    $userId = (int)(current_user()['id'] ?? 0) ?: null;
    db()->prepare('INSERT INTO crm_radar_search_tasks (task_name,country,city,model_key,seed_ids_json,target_products,target_project_types,languages_json,keyword_ids_json,keywords_json,exclude_keywords_json,target_candidate_count,min_score,must_have_website,must_have_email,must_have_contact,allow_design_studio,exclude_factory,exclude_retailer,exclude_decorative,exclude_brand_branch,check_crm_duplicate,enrich_contacts,verify_email,auto_to_crm,auto_send_email,single_task_cost_limit,daily_cost_limit,daily_candidate_limit,execute_mode,execute_at,repeat_rule,executor_user_id,task_status,created_by,updated_by,template_id,template_code,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW(),NOW())')
        ->execute(array_merge(array_values($data), [$userId, $userId, (int)$tpl['id'], (string)$tpl['template_code']]));
    $taskId = (int)db()->lastInsertId();
    db()->prepare('INSERT INTO crm_radar_template_usage (template_id,template_code,task_id,mode_key,keyword_count,search_service_key,created_by,created_at) VALUES (?,?,?,?,?,?,?,NOW())')
        ->execute([(int)$tpl['id'], (string)$tpl['template_code'], $taskId, (string)$preview['mode'], count($keywords), (string)$tpl['search_service_key'], $userId]);
    db()->prepare('UPDATE crm_radar_search_templates SET use_count=use_count+1, generated_keyword_count=generated_keyword_count+?, updated_at=NOW() WHERE id=?')
        ->execute([count($keywords), (int)$tpl['id']]);
    radar_log('template_create_task', ['template_id' => (int)$tpl['id'], 'task_id' => $taskId, 'keyword_count' => count($keywords)]);
    return ['task_id' => $taskId, 'task' => radar_task_get($taskId)['task'], 'preview' => $preview];
}

function radar_enabled_search_service(): ?array
{
    radar_ensure_default_search_service();
    $row = db()->query('SELECT * FROM crm_radar_search_services WHERE is_enabled=1 ORDER BY priority_order ASC,id ASC LIMIT 1')->fetch();
    return $row ?: null;
}

function radar_search_services_list(): array
{
    radar_ensure_tables();
    crm_require('radar_settings_manage');
    radar_ensure_default_search_service();
    $rows = db()->query('SELECT id, service_key, service_name, api_url, CASE WHEN api_key_encrypted IS NULL OR api_key_encrypted="" THEN 0 ELSE 1 END AS has_api_key, result_limit, daily_limit, is_enabled, priority_order, timeout_seconds, retry_count, cost_per_call, config_json, updated_at FROM crm_radar_search_services ORDER BY priority_order ASC,id ASC')->fetchAll();
    return ['rows' => $rows];
}

function radar_search_service_save(array $input): array
{
    radar_ensure_tables();
    crm_require('radar_settings_manage');
    radar_ensure_default_search_service();
    $id = (int)($input['id'] ?? 0);
    $key = preg_replace('/[^a-z0-9_\-]/i', '', (string)($input['service_key'] ?? 'manual_config')) ?: 'manual_config';
    $name = mb_substr(trim((string)($input['service_name'] ?? '合法搜索API配置位')), 0, 120);
    $apiUrl = mb_substr(trim((string)($input['api_url'] ?? '')), 0, 500);
    $apiKey = trim((string)($input['api_key'] ?? ''));
    $resultLimit = max(1, min(50, (int)($input['result_limit'] ?? 5)));
    $dailyLimit = max(0, (int)($input['daily_limit'] ?? 100));
    $enabled = radar_bool($input, 'is_enabled', 0);
    $priority = max(1, min(9999, (int)($input['priority_order'] ?? 100)));
    $timeout = max(3, min(120, (int)($input['timeout_seconds'] ?? 15)));
    $retry = max(0, min(10, (int)($input['retry_count'] ?? 2)));
    $cost = max(0, (float)($input['cost_per_call'] ?? 0));
    $userId = (int)(current_user()['id'] ?? 0) ?: null;
    $encryptedKey = $apiKey !== '' ? radar_secret_encrypt($apiKey) : '';
    if ($id > 0) {
        $sql = 'UPDATE crm_radar_search_services SET service_key=?, service_name=?, api_url=?, result_limit=?, daily_limit=?, is_enabled=?, priority_order=?, timeout_seconds=?, retry_count=?, cost_per_call=?, updated_by=?, updated_at=NOW()' . ($apiKey !== '' ? ', api_key_encrypted=?' : '') . ' WHERE id=?';
        $params = [$key,$name,$apiUrl,$resultLimit,$dailyLimit,$enabled,$priority,$timeout,$retry,$cost,$userId];
        if ($apiKey !== '') $params[] = $encryptedKey;
        $params[] = $id;
        db()->prepare($sql)->execute($params);
    } else {
        db()->prepare('INSERT INTO crm_radar_search_services (service_key, service_name, api_url, api_key_encrypted, result_limit, daily_limit, is_enabled, priority_order, timeout_seconds, retry_count, cost_per_call, created_by, updated_by, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,NOW(),NOW())')
            ->execute([$key,$name,$apiUrl,$encryptedKey,$resultLimit,$dailyLimit,$enabled,$priority,$timeout,$retry,$cost,$userId,$userId]);
        $id = (int)db()->lastInsertId();
    }
    radar_log('search_service_save', ['service_id' => $id, 'service_key' => $key, 'enabled' => $enabled]);
    return radar_search_services_list();
}

function radar_usage_today(?int $taskId = null): array
{
    $params = [];
    $where = 'usage_date=CURDATE()';
    if ($taskId) { $where .= ' AND task_id=?'; $params[] = $taskId; }
    $st = db()->prepare("SELECT COALESCE(SUM(cost_amount),0) cost, COALESCE(SUM(quantity),0) quantity, COUNT(*) calls FROM crm_radar_usage WHERE $where");
    $st->execute($params);
    return $st->fetch() ?: ['cost' => 0, 'quantity' => 0, 'calls' => 0];
}

function radar_check_limits(array $task, ?array $service = null): void
{
    $settings = radar_settings_get();
    $today = radar_usage_today((int)$task['id']);
    $allToday = radar_usage_today(null);
    $taskLimit = (float)($task['single_task_cost_limit'] ?: ($settings['cost']['single_task_cost_limit'] ?? 0));
    $dailyLimit = (float)($task['daily_cost_limit'] ?: ($settings['cost']['daily_ai_cost_limit'] ?? 0));
    if ($taskLimit > 0 && (float)$today['cost'] >= $taskLimit) throw new RuntimeException('已达到单任务费用上限');
    if ($dailyLimit > 0 && (float)$allToday['cost'] >= $dailyLimit) throw new RuntimeException('已达到每日费用上限');
    if ($service && (int)$service['daily_limit'] > 0 && (int)$allToday['calls'] >= (int)$service['daily_limit']) throw new RuntimeException('已达到搜索服务每日调用上限');
}

function radar_record_usage(int $taskId, ?array $service, string $type, int $quantity, float $cost, bool $success, string $error = ''): void
{
    db()->prepare('INSERT INTO crm_radar_usage (usage_date, task_id, usage_type, provider, service_name, request_type, quantity, return_count, cost_amount, success, error_reason, detail_json, created_at) VALUES (CURDATE(),?,?,?,?,?,?,?,?,?,?,?,NOW())')
        ->execute([$taskId, $type, (string)($service['service_key'] ?? ''), (string)($service['service_name'] ?? ''), $type, $quantity, $quantity, $cost, $success ? 1 : 0, $error, json_encode(['service' => $service ? (int)$service['id'] : 0], JSON_UNESCAPED_UNICODE)]);
}

function radar_is_brave_search_service(array $service): bool
{
    $key = strtolower((string)($service['service_key'] ?? ''));
    $url = strtolower((string)($service['api_url'] ?? ''));
    return strpos($key, 'brave') !== false || strpos($url, 'api.search.brave.com') !== false;
}

function radar_first_language(array $task): string
{
    $languages = radar_decode_json($task['languages_json'] ?? '');
    foreach ($languages as $lang) {
        $lang = strtolower(trim((string)$lang));
        if ($lang !== '') return $lang;
    }
    return '';
}

function radar_country_code(array $task): string
{
    $country = trim((string)($task['country'] ?? ''));
    $supported = ['AR','AU','AT','BE','BR','CA','CL','DK','FI','FR','DE','GR','HK','IN','ID','IT','JP','KR','MY','MX','NL','NZ','NO','CN','PL','PT','PH','RU','SA','ZA','ES','SE','CH','TW','TR','GB','US','ALL'];
    if (preg_match('/^[a-z]{2}$/i', $country)) {
        $code = strtoupper($country);
        return in_array($code, $supported, true) ? $code : 'ALL';
    }
    $map = [
        'vietnam' => 'ALL',
        'viet nam' => 'ALL',
        '越南' => 'ALL',
        'china' => 'CN',
        '中国' => 'CN',
        'hong kong' => 'HK',
        '香港' => 'HK',
        'usa' => 'US',
        'united states' => 'US',
        '美国' => 'US',
        'uae' => 'AE',
        'dubai' => 'AE',
        '阿联酋' => 'AE',
        'india' => 'IN',
        '印度' => 'IN',
        'singapore' => 'SG',
        '新加坡' => 'SG',
        'germany' => 'DE',
        '德国' => 'DE',
        'france' => 'FR',
        '法国' => 'FR',
        'uk' => 'GB',
        'united kingdom' => 'GB',
        '英国' => 'GB',
    ];
    if ($country === '') return '';
    $code = $map[strtolower($country)] ?? 'ALL';
    return in_array($code, $supported, true) ? $code : 'ALL';
}

function radar_search_service_url(array $service, array $task, string $keyword, int $limit): string
{
    $apiUrl = trim((string)($service['api_url'] ?? ''));
    $params = ['q' => $keyword, 'count' => $limit];
    if (radar_is_brave_search_service($service)) {
        $params['result_filter'] = 'web';
        $params['extra_snippets'] = 'true';
        $country = radar_country_code($task);
        if ($country !== '') $params['country'] = $country;
        $lang = radar_first_language($task);
        if ($lang !== '') $params['search_lang'] = $lang;
    }
    return $apiUrl . (strpos($apiUrl, '?') === false ? '?' : '&') . http_build_query($params);
}

function radar_search_service_headers(array $service, string $apiKey): string
{
    $headers = "Accept: application/json\r\n";
    $headers .= "User-Agent: Artdon-CRM-Radar/1.0\r\n";
    $apiKey = str_replace(["\r", "\n"], '', $apiKey);
    if ($apiKey === '') return $headers;
    if (radar_is_brave_search_service($service)) {
        return $headers . "X-Subscription-Token: $apiKey\r\n";
    }
    return $headers . "Authorization: Bearer $apiKey\r\n";
}

function radar_search_service_items(array $json): array
{
    if (isset($json['web']['results']) && is_array($json['web']['results'])) return $json['web']['results'];
    if (isset($json['items']) && is_array($json['items'])) return $json['items'];
    if (isset($json['results']) && is_array($json['results'])) return $json['results'];
    if (isset($json['data']) && is_array($json['data'])) return $json['data'];
    return [];
}

function radar_search_item_snippet(array $item): string
{
    $snippet = (string)($item['snippet'] ?? $item['description'] ?? '');
    if (!empty($item['extra_snippets']) && is_array($item['extra_snippets'])) {
        $extra = array_values(array_filter(array_map('strval', $item['extra_snippets'])));
        if ($extra) $snippet = trim($snippet . "\n" . implode("\n", array_slice($extra, 0, 3)));
    }
    return $snippet;
}

function radar_search_service_call(array $task, string $keyword): array
{
    $service = radar_enabled_search_service();
    if (!$service) {
        radar_record_usage((int)$task['id'], null, 'search_keyword', 0, 0, false, '未启用搜索服务');
        throw new RuntimeException('未启用搜索服务，请在搜索服务配置中填写合法 API 并启用。');
    }
    radar_check_limits($task, $service);
    $apiUrl = trim((string)($service['api_url'] ?? ''));
    if ($apiUrl === '') throw new RuntimeException('搜索服务 API 地址未配置');
    $limit = max(1, min(20, (int)($service['result_limit'] ?? 5)));
    $url = radar_search_service_url($service, $task, $keyword, $limit);
    $apiKey = radar_secret_decrypt($service['api_key_encrypted'] ?? '');
    $headers = radar_search_service_headers($service, $apiKey);
    $context = stream_context_create(['http' => ['timeout' => max(3, (int)$service['timeout_seconds']), 'ignore_errors' => true, 'header' => $headers]]);
    $raw = @file_get_contents($url, false, $context);
    if ($raw === false || $raw === '') {
        radar_record_usage((int)$task['id'], $service, 'search_keyword', 0, (float)$service['cost_per_call'], false, '搜索API请求失败');
        throw new RuntimeException('搜索API请求失败');
    }
    $json = json_decode($raw, true);
    if (!is_array($json)) throw new RuntimeException('搜索API返回不是JSON');
    if (!empty($json['error'])) {
        $detail = (string)($json['error']['detail'] ?? $json['error']['message'] ?? '搜索API返回错误');
        $status = (string)($json['error']['status'] ?? $json['error']['code'] ?? '');
        $message = trim(($status !== '' ? $status . ' ' : '') . $detail);
        radar_record_usage((int)$task['id'], $service, 'search_keyword', 0, (float)$service['cost_per_call'], false, $message);
        throw new RuntimeException($message);
    }
    $items = radar_search_service_items($json);
    $rows = [];
    foreach ((array)$items as $idx => $item) {
        if (!is_array($item)) continue;
        $rows[] = [
            'title' => (string)($item['title'] ?? $item['name'] ?? ''),
            'url' => (string)($item['url'] ?? $item['link'] ?? ''),
            'snippet' => radar_search_item_snippet($item),
            'rank_no' => $idx + 1,
        ];
    }
    radar_record_usage((int)$task['id'], $service, 'search_keyword', count($rows), (float)$service['cost_per_call'], true);
    return ['service' => $service, 'rows' => array_slice($rows, 0, $limit)];
}

function radar_fetch_url_summary(string $url, int $timeout = 12): array
{
    $context = stream_context_create(['http' => ['timeout' => $timeout, 'follow_location' => 1, 'max_redirects' => 3, 'header' => "User-Agent: ArtdonRadar/1.0\r\nAccept: text/html,text/plain,*/*\r\n"]]);
    $html = @file_get_contents($url, false, $context, 0, 1024 * 512);
    $status = 0;
    foreach (($http_response_header ?? []) as $h) {
        if (preg_match('/^HTTP\/\S+\s+(\d+)/', $h, $m)) { $status = (int)$m[1]; break; }
    }
    if ($html === false || $html === '') throw new RuntimeException('网页抓取失败');
    $title = preg_match('/<title[^>]*>(.*?)<\/title>/is', $html, $m) ? trim(html_entity_decode(strip_tags($m[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8')) : '';
    $desc = preg_match('/<meta[^>]+name=["\']description["\'][^>]+content=["\']([^"\']*)/is', $html, $m) ? trim(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8')) : '';
    $text = trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags(preg_replace('/<(script|style)[\s\S]*?<\/\1>/i', ' ', $html)), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
    return ['http_status' => $status, 'page_title' => mb_substr($title, 0, 500), 'page_description' => mb_substr($desc, 0, 1000), 'raw_text_summary' => mb_substr($text, 0, 4000)];
}

function radar_domain_from_url(string $url): string
{
    $url = trim($url);
    if ($url === '') return '';
    if (!preg_match('/^https?:\/\//i', $url)) $url = 'https://' . $url;
    $host = strtolower((string)(parse_url($url, PHP_URL_HOST) ?: ''));
    $host = preg_replace('/^www\./', '', $host) ?: $host;
    return mb_substr($host, 0, 190);
}

function radar_root_url(string $url): string
{
    $domain = radar_domain_from_url($url);
    if ($domain === '') return '';
    $scheme = preg_match('/^http:\/\//i', $url) ? 'http' : 'https';
    return $scheme . '://' . $domain;
}

function radar_contains_any(string $text, array $needles): bool
{
    $text = mb_strtolower($text);
    foreach ($needles as $needle) {
        if ($needle !== '' && mb_strpos($text, mb_strtolower($needle)) !== false) return true;
    }
    return false;
}

function radar_extract_first_email(string $text): string
{
    return preg_match('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', $text, $m) ? strtolower($m[0]) : '';
}

function radar_extract_first_phone(string $text): string
{
    if (!preg_match('/(?:\+?84|0)(?:[\s.\-()]?\d){8,12}/', $text, $m)) return '';
    return trim($m[0]);
}

function radar_extract_social_url(string $text, string $host): string
{
    $pattern = '/https?:\/\/(?:www\.)?' . preg_quote($host, '/') . '\/[^\s"\'<>]+/i';
    return preg_match($pattern, $text, $m) ? mb_substr(rtrim($m[0], ".,;，。；)）]】"), 0, 255) : '未找到';
}

function radar_extract_founded_year(string $text): string
{
    if (preg_match('/(?:founded|established|since|thành lập)[^\d]{0,20}((?:19|20)\d{2})/iu', $text, $m)) return $m[1];
    return '未找到';
}

function radar_keyword_label(string $text, array $map, string $fallback = '未找到'): string
{
    $hits = [];
    foreach ($map as $label => $needles) {
        if (radar_contains_any($text, (array)$needles)) $hits[] = $label;
    }
    return $hits ? implode('；', array_values(array_unique($hits))) : $fallback;
}

function radar_candidate_field_names(): array
{
    return [
        'company_name','local_name','website','domain','country','city','address','company_type','model_key','founded_year','company_intro',
        'main_products','product_keywords','project_types','service_industries','represented_brands','has_own_brand','has_showroom','has_projects',
        'has_design_team','has_sales_team','has_procurement_role','contact_name','contact_position','email','phone','whatsapp','linkedin','facebook',
        'instagram','source_url','source_title','crawled_at','last_verified_at','data_completeness','crm_duplicate_status','radar_score','grade',
        'match_reasons','risk_warnings','review_status',
    ];
}

function radar_candidate_grade(float $score): string
{
    if ($score >= 80) return 'A';
    if ($score >= 65) return 'B';
    if ($score >= 50) return 'C';
    return 'D';
}

function radar_candidate_type_options(): array
{
    return [
        '建筑照明解决方案商','商业照明供应商','灯具经销商','灯具进口商','工程照明公司','项目照明承包商','高端灯具展厅',
        '照明顾问公司','灯光设计事务所','建筑设计公司','室内设计公司','国际灯具品牌','越南本地灯具品牌','灯具制造工厂',
        '装饰灯零售商','电工材料零售商','纯电商卖家','其他',
    ];
}

function radar_review_status_options(): array
{
    return [
        'pending' => '待审核',
        'precise' => '精准',
        'basic_precise' => '基本精准',
        'normal' => '一般',
        'not_precise' => '不精准',
        'need_more_info' => '待补资料',
        'duplicate' => '重复客户',
        'blacklisted' => '黑名单',
        'converted' => '已转CRM',
        'archived' => '已归档',
    ];
}

function radar_allowed_convert_statuses(): array
{
    return ['precise', 'basic_precise', 'normal'];
}

function radar_classify_company(string $text, string $model): string
{
    if (radar_contains_any($text, ['lighting design studio','lighting designer','thiết kế chiếu sáng','dialux','lighting consultant'])) return '灯光设计事务所';
    if (radar_contains_any($text, ['architectural lighting brand','lighting brand','experience centre','product development'])) return '国际灯具品牌';
    if (radar_contains_any($text, ['factory','manufacturer','manufacturing','nhà máy','sản xuất'])) return '灯具制造工厂';
    if (radar_contains_any($text, ['decorative','chandelier','home decor','đèn trang trí'])) return '装饰灯零售商';
    if (radar_contains_any($text, ['showroom','cao cấp'])) return '高端灯具展厅';
    if (radar_contains_any($text, ['distributor','distribution','nhà phân phối'])) return '灯具经销商';
    if (radar_contains_any($text, ['contractor','project lighting','engineering'])) return '工程照明公司';
    if (radar_contains_any($text, ['solution provider','lighting solution','giải pháp chiếu sáng'])) return '建筑照明解决方案商';
    if ($model === 'design_influencer') return '照明顾问公司';
    if ($model === 'brand_oem') return '越南本地灯具品牌';
    return radar_contains_any($text, ['commercial lighting','architectural lighting']) ? '商业照明供应商' : '其他';
}

function radar_score_candidate(array $facts, array $task): array
{
    $model = (string)($facts['model_key'] ?: ($task['model_key'] ?? 'direct_buyer'));
    $text = (string)$facts['analysis_text'];
    $rules = [];
    $risks = [];
    $score = 0;
    $add = static function (string $key, int $value, string $reason) use (&$score, &$rules) {
        $score += $value;
        $rules[] = ['key' => $key, 'value' => $value, 'reason' => $reason];
    };
    if ($model === 'design_influencer') {
        if (radar_contains_any($text, ['lighting design studio','independent lighting consultant','thiết kế chiếu sáng'])) $add('design_studio', 20, '属于专业灯光设计事务所或独立照明顾问');
        if (radar_contains_any($text, ['hotel','hospitality','retail','museum','commercial','office','restaurant'])) $add('project_type', 20, '有酒店、商业、文化、零售或办公项目线索');
        if (radar_contains_any($text, ['team','principal','designer','director'])) $add('design_team', 10, '公开出现设计团队或负责人信息');
        if (radar_contains_any($text, ['ies','dialux','bim','cad'])) $add('design_tools', 10, '出现 IES、DIALux、BIM 或 CAD 能力');
        if (radar_contains_any($text, ['international','award','large project','global'])) $add('large_project', 15, '有国际或大型项目迹象');
        if (radar_contains_any($text, ['specification','consultant','recommend','brand'])) $add('spec_influence', 15, '有品牌推荐或规格制定影响力');
        if (radar_meaningful_value($facts['email'] ?? '') || radar_meaningful_value($facts['phone'] ?? '')) $add('contact', 10, '存在项目负责人或公开联系方式');
        $risks[] = '设计影响型，可能不是直接付款采购方';
    } elseif ($model === 'brand_oem') {
        if (radar_contains_any($text, ['architectural lighting brand','lighting brand','brand'])) $add('own_brand', 15, '有自有或明确建筑照明品牌');
        if (radar_contains_any($text, ['vietnam office','hanoi','ho chi minh','experience centre','showroom'])) $add('vn_office', 10, '有越南办公室、展厅或体验中心迹象');
        if (radar_contains_any($text, ['product development','r&d','研发','design and manufacture'])) $add('product_dev', 10, '出现产品研发或产品体系线索');
        if (radar_contains_any($text, ['procurement','supply chain','product manager','purchasing'])) $add('procurement_role', 20, '出现采购、供应链或产品岗位线索');
        if (radar_contains_any($text, ['asia sourcing','outsourcing','contract manufacturing','oem'])) $add('asia_sourcing', 15, '有亚洲制造、外包或 OEM 迹象');
        if (radar_contains_any($text, ['custom','private label','odm','oem'])) $add('custom_oem', 15, '存在定制、私有标签或补充产品机会');
        if (radar_contains_any($text, ['downlight','spotlight','track light','linear','architectural lighting'])) $add('artdon_fit', 15, '产品方向与 Artdon 商业照明匹配');
        if (radar_contains_any($text, ['factory','manufacturing']) && !radar_contains_any($text, ['oem','outsourcing','sourcing'])) $risks[] = '完全自有工厂且供应链可能封闭';
    } else {
        if (radar_contains_any($text, ['lighting design','thiết kế chiếu sáng']) && radar_contains_any($text, ['supplier','product','downlight','track light','lighting solution'])) $add('design_and_supply', 20, '同时提供灯光设计和照明产品/供应服务');
        if (radar_contains_any($text, ['architectural lighting','commercial lighting','chiếu sáng kiến trúc','chiếu sáng thương mại'])) $add('commercial_lighting', 15, '有建筑照明或商业照明业务');
        if (radar_contains_any($text, ['hotel','retail','restaurant','office','showroom','hospitality'])) $add('project_types', 15, '出现酒店、零售、餐厅、办公或展厅项目');
        if (radar_contains_any($text, ['downlight','spotlight','track light','magnetic track','linear lighting','đèn âm trần','đèn rọi ray'])) $add('product_fit', 15, '产品包含筒灯、射灯、轨道灯、磁吸灯或线性灯');
        if (radar_contains_any($text, ['hanoi','ha noi','ho chi minh','hcmc','saigon','hồ chí minh'])) $add('major_city', 8, '有河内、胡志明或主要城市线索');
        if (radar_contains_any($text, ['project','case study','portfolio','dự án'])) $add('project_case', 8, '官网或页面出现真实项目案例线索');
        if (radar_contains_any($text, ['sales','project manager','procurement','purchasing']) || (string)$facts['email'] !== '') $add('contact_role', 7, '有销售、项目、采购或邮箱联系方式');
        if (radar_contains_any($text, ['import','oem','odm','custom','private label'])) $add('custom_oem', 7, '有进口、OEM、ODM 或定制迹象');
        if (radar_meaningful_value($facts['email'] ?? '') && radar_meaningful_value($facts['phone'] ?? '')) $add('email_phone', 5, '同时有有效邮箱和电话');
        if (radar_contains_any($text, ['decorative','chandelier','home decor','đèn trang trí'])) { $score -= 25; $risks[] = '业务偏装饰灯'; $rules[] = ['key' => 'decorative', 'value' => -25, 'reason' => '可能只做家居装饰灯']; }
        if (radar_contains_any($text, ['retail only','shop online','ecommerce','shopee','lazada'])) { $score -= 20; $risks[] = '只做低端零售或电商'; $rules[] = ['key' => 'retail', 'value' => -20, 'reason' => '可能只做低端零售']; }
        if (radar_contains_any($text, ['factory','manufacturer','manufacturing'])) { $score -= 15; $risks[] = '可能为制造工厂'; $rules[] = ['key' => 'factory', 'value' => -15, 'reason' => '明确为制造工厂']; }
        if (empty($facts['website'])) { $score -= 10; $risks[] = '没有官网或公开业务资料'; }
        if (radar_contains_any($text, ['lighting design']) && !radar_contains_any($text, ['product','supplier','distributor'])) { $score -= 10; $risks[] = '可能只做灯光设计'; }
        if (radar_contains_any($text, ['official branch','vietnam branch','subsidiary'])) { $score -= 15; $risks[] = '国际品牌直属分公司'; }
    }
    if (!radar_meaningful_value($facts['email'] ?? '')) $risks[] = '邮箱未验证';
    if (!radar_meaningful_value($facts['contact_name'] ?? '')) $risks[] = '联系人未验证';
    if ((int)$facts['data_completeness'] < 45) $risks[] = '资料不足';
    if (count($facts['source_urls'] ?? []) <= 1) $risks[] = '数据来源单一';
    $score = max(0, min(100, $score));
    return ['score' => $score, 'grade' => radar_candidate_grade($score), 'rules' => $rules, 'risks' => array_values(array_unique($risks))];
}

function radar_candidate_match_reasons(array $facts, array $task, array $score): array
{
    $model = (string)($facts['model_key'] ?: ($task['model_key'] ?? 'direct_buyer'));
    $reasons = [];
    $matched = array_values(array_filter(array_map(static fn($r) => (string)($r['reason'] ?? ''), $score['rules'] ?? [])));
    if ($model === 'design_influencer') $reasons[] = '与ASA相似：属于专业灯光设计/顾问类型，具备项目规格影响力线索';
    elseif ($model === 'brand_oem') $reasons[] = '与Unios相似：属于建筑照明品牌或产品体系公司，可能存在OEM或供应链合作';
    else $reasons[] = '与CROLED/ADES/Alis相似：匹配项目照明、商业照明产品、设计或供应能力特征';
    if ($matched) $reasons[] = '具体特征：' . implode('；', array_slice($matched, 0, 5));
    return $reasons;
}

function radar_meaningful_value($value): bool
{
    if (is_array($value) || $value === null) return false;
    $text = trim((string)$value);
    if ($text === '') return false;
    return !in_array(mb_strtolower($text), ['未找到', 'unknown', 'n/a', 'na', 'none', 'null', '-'], true);
}

function radar_duplicate_check(array $facts): array
{
    if (!radar_table_exists('crm_customers')) return ['status' => 'new_customer', 'matches' => []];
    $domain = (string)($facts['domain'] ?? '');
    $name = trim((string)($facts['company_name'] ?? ''));
    $factPhone = radar_meaningful_value($facts['phone'] ?? '') ? preg_replace('/\D+/', '', (string)$facts['phone']) : '';
    $emailDomain = '';
    if (radar_meaningful_value($facts['email'] ?? '') && strpos((string)$facts['email'], '@') !== false) $emailDomain = strtolower(substr(strrchr((string)$facts['email'], '@'), 1));
    $hasFollowups = radar_table_exists('crm_customer_followups');
    $hasPromo = radar_table_exists('crm_customer_promotion_channels');
    $hasQuotes = radar_table_exists('quotes');
    $lastFollowSql = $hasFollowups ? '(SELECT MAX(f.followup_time) FROM crm_customer_followups f WHERE f.customer_id=c.id AND f.deleted_at IS NULL)' : 'NULL';
    $promoSql = $hasPromo ? 'EXISTS(SELECT 1 FROM crm_customer_promotion_channels pc WHERE pc.customer_id=c.id)' : '0';
    $quoteSql = $hasQuotes ? 'EXISTS(SELECT 1 FROM quotes q WHERE q.customer_id=c.id)' : '0';
    $rows = db()->query("SELECT c.id,c.customer_code,c.customer_name,c.customer_name_en,c.country,c.status,c.website,c.email,c.phone,c.address,u.username AS owner_name,{$lastFollowSql} AS last_followup_at,{$promoSql} AS has_promotion,{$quoteSql} AS has_quote,0 AS has_order FROM crm_customers c LEFT JOIN crm_users u ON u.id=c.owner_user_id WHERE c.deleted_at IS NULL ORDER BY c.updated_at DESC LIMIT 800")->fetchAll();
    $matches = [];
    foreach ($rows as $row) {
        $reasons = [];
        $score = 0;
        $rowName = trim((string)$row['customer_name']);
        $rowNameEn = trim((string)($row['customer_name_en'] ?? ''));
        if ($name !== '' && mb_strtolower($name) === mb_strtolower($rowName)) { $score = max($score, 100); $reasons[] = '公司名称完全相同'; }
        if ($name !== '' && $rowNameEn !== '' && mb_strtolower($name) === mb_strtolower($rowNameEn)) { $score = max($score, 96); $reasons[] = '英文名称相同'; }
        $rowDomain = radar_domain_from_url((string)($row['website'] ?: $row['email']));
        if ($domain !== '' && $rowDomain !== '' && $domain === $rowDomain) { $score = max($score, 94); $reasons[] = '官网域名相同'; }
        if ($emailDomain !== '' && $rowDomain !== '' && $emailDomain === $rowDomain) { $score = max($score, 88); $reasons[] = '邮箱域名相同'; }
        $rowPhone = radar_meaningful_value($row['phone'] ?? '') ? preg_replace('/\D+/', '', (string)$row['phone']) : '';
        if ($factPhone !== '' && $rowPhone !== '' && strlen($factPhone) >= 6 && $factPhone === $rowPhone) { $score = max($score, 90); $reasons[] = '电话相同'; }
        if ($name !== '' && $rowName !== '') {
            similar_text(mb_strtolower($name), mb_strtolower($rowName), $pct);
            if ($pct >= 78) { $score = max($score, (int)$pct); $reasons[] = '公司名称近似匹配'; }
        }
        if ($score >= 70) {
            $row['similarity'] = $score;
            $row['reasons'] = $reasons;
            $matches[] = $row;
        }
    }
    usort($matches, static fn($a, $b) => ($b['similarity'] <=> $a['similarity']));
    $top = $matches[0]['similarity'] ?? 0;
    $status = $top >= 94 ? '完全重复' : ($top >= 84 ? '高度疑似重复' : ($top >= 70 ? '同公司不同拼写' : '新客户'));
    return ['status' => $status, 'matches' => array_slice($matches, 0, 5)];
}

function radar_analyze_raw_result(int $rawId, array $task): array
{
    $st = db()->prepare('SELECT * FROM crm_radar_raw_results WHERE id=? AND task_id=? LIMIT 1');
    $st->execute([$rawId, (int)$task['id']]);
    $raw = $st->fetch();
    if (!$raw) throw new RuntimeException('原始搜索结果不存在');
    $url = (string)($raw['url'] ?? '');
    $domain = radar_domain_from_url($url);
    $text = trim(implode(' ', [(string)$raw['title'], (string)$raw['snippet'], (string)$raw['page_title'], (string)$raw['page_description'], (string)$raw['raw_text_summary']]));
    $lowerUrl = strtolower($url);
    $path = strtolower((string)(parse_url($url, PHP_URL_PATH) ?: ''));
    $isRootLike = $path === '' || $path === '/' || (bool)preg_match('#^/(en|vi|vn|home|index\.php)?/?$#', $path);
    $isDirectory = radar_contains_any($lowerUrl, ['directory','yellowpages','/list','top-','facebook.com','linkedin.com','instagram.com']);
    $isArticle = !$isRootLike && (radar_contains_any($path, ['/news','/article','/blog','press-release']) || radar_contains_any($lowerUrl, ['/blogs/','/article/','/articles/']));
    $isCompany = !$isDirectory && !$isArticle && $domain !== '' && radar_contains_any($text, ['lighting','chiếu sáng','đèn','architectural','commercial','design','project','showroom','supplier','brand']);
    $companyName = trim((string)($raw['page_title'] ?: $raw['title']));
    $companyName = preg_replace('/\s*[\-|–|—|:].*$/u', '', $companyName) ?: $companyName;
    $companyName = mb_substr(trim($companyName), 0, 255);
    if ($companyName === '' && $domain !== '') $companyName = preg_replace('/\..*$/', '', $domain);
    $email = radar_extract_first_email($text);
    $phone = radar_extract_first_phone($text);
    $model = (string)($task['model_key'] ?? 'direct_buyer');
    $type = radar_classify_company($text, $model);
    $productKeywords = [];
    foreach (['Downlight' => ['downlight','đèn âm trần'], 'Spotlight' => ['spotlight','đèn rọi'], 'Track Light' => ['track light','đèn rọi ray'], 'Magnetic Track' => ['magnetic track','ray nam châm'], 'Linear Lighting' => ['linear lighting','linear light'], 'Outdoor Light' => ['outdoor','facade','landscape']] as $label => $needles) {
        if (radar_contains_any($text, $needles)) $productKeywords[] = $label;
    }
    $projectTypes = radar_keyword_label($text, [
        '酒店' => ['hotel','hospitality','khách sạn'],
        '零售' => ['retail','shop','store','cửa hàng'],
        '办公' => ['office','văn phòng'],
        '餐厅' => ['restaurant','cafe'],
        '展厅' => ['showroom'],
        '博物馆/文化' => ['museum','gallery','bảo tàng'],
    ]);
    $serviceIndustries = radar_keyword_label($text, [
        '酒店商业' => ['hotel','hospitality','restaurant','retail'],
        '办公空间' => ['office','workplace'],
        '文化展陈' => ['museum','gallery','exhibition'],
        '建筑景观' => ['facade','landscape','architectural'],
    ]);
    $representedBrands = radar_contains_any($text, ['brand','代理','distributor','exclusive']) ? '页面出现品牌/代理线索，品牌名未验证' : '未找到';
    $hasShowroom = radar_contains_any($text, ['showroom','experience centre','experience center','展厅']);
    $hasProjects = radar_contains_any($text, ['project','case study','portfolio','dự án']);
    $hasDesignTeam = radar_contains_any($text, ['lighting design','designer','design team','dialux','ies','thiết kế']);
    $hasSalesTeam = radar_contains_any($text, ['sales','sales team','business development','contact us']);
    $hasProcurementRole = radar_contains_any($text, ['procurement','purchasing','supply chain','product manager']);
    $hasOwnBrand = radar_contains_any($text, ['our brand','own brand','product development','architectural lighting brand']);
    $facts = [
        'company_name' => $companyName ?: '未找到',
        'local_name' => '未找到',
        'website' => radar_root_url($url),
        'domain' => $domain,
        'country' => (string)($task['country'] ?: ($raw['country'] ?? '越南')),
        'city' => (string)($task['city'] ?: ($raw['city'] ?? '未找到')),
        'address' => '未找到',
        'company_type' => $type,
        'model_key' => $model,
        'founded_year' => radar_extract_founded_year($text),
        'company_intro' => mb_substr((string)($raw['page_description'] ?: $raw['snippet'] ?: '未找到'), 0, 1000),
        'main_products' => $productKeywords ? implode('；', $productKeywords) : (radar_contains_any($text, ['lighting','đèn']) ? '照明产品，具体品类未验证' : '未找到'),
        'product_keywords' => $productKeywords,
        'project_types' => $projectTypes,
        'service_industries' => $serviceIndustries,
        'represented_brands' => $representedBrands,
        'has_own_brand' => $hasOwnBrand ? 1 : 0,
        'has_showroom' => $hasShowroom ? 1 : 0,
        'has_projects' => $hasProjects ? 1 : 0,
        'has_design_team' => $hasDesignTeam ? 1 : 0,
        'has_sales_team' => $hasSalesTeam ? 1 : 0,
        'has_procurement_role' => $hasProcurementRole ? 1 : 0,
        'email' => $email ?: '未找到',
        'phone' => $phone ?: '未找到',
        'whatsapp' => radar_contains_any($text, ['whatsapp']) ? ($phone ?: 'AI推测：页面出现WhatsApp但号码未验证') : '未找到',
        'contact_name' => '未找到',
        'contact_position' => '未找到',
        'linkedin' => radar_extract_social_url($text . ' ' . $url, 'linkedin.com'),
        'facebook' => radar_extract_social_url($text . ' ' . $url, 'facebook.com'),
        'instagram' => radar_extract_social_url($text . ' ' . $url, 'instagram.com'),
        'source_url' => $url,
        'source_title' => (string)($raw['title'] ?: $raw['page_title']),
        'analysis_text' => $text,
        'source_urls' => [$url],
    ];
    $completenessFields = ['company_name','website','domain','country','city','company_type','company_intro','main_products','project_types','service_industries','email','phone','linkedin','facebook'];
    $found = 0;
    foreach ($completenessFields as $field) if (($facts[$field] ?? '') !== '' && ($facts[$field] ?? '') !== '未找到') $found++;
    $facts['data_completeness'] = (int)round($found * 100 / count($completenessFields));
    $score = radar_score_candidate($facts, $task);
    $duplicate = !empty($task['check_crm_duplicate']) ? radar_duplicate_check($facts) : ['status' => '未检查', 'matches' => []];
    if ($duplicate['status'] !== '新客户' && $duplicate['status'] !== '未检查') $score['risks'][] = 'CRM已有相似客户';
    $facts['match_reasons'] = radar_candidate_match_reasons($facts, $task, $score);
    $facts['risk_warnings'] = array_values(array_unique($score['risks']));
    $facts['score'] = $score['score'];
    $facts['grade'] = $score['grade'];
    $facts['score_rules'] = $score['rules'];
    $facts['duplicate'] = $duplicate;
    $facts['is_real_company'] = $isCompany ? 1 : 0;
    $facts['has_independent_website'] = (!$isDirectory && $domain !== '') ? 1 : 0;
    $facts['is_vietnam_market'] = radar_contains_any($text . ' ' . $url, ['vietnam','viet nam','hanoi','ho chi minh','hồ chí minh','.vn']) ? 1 : 0;
    $facts['excluded_reason'] = $isCompany ? '' : ($isDirectory ? '目录/社交/聚合页面' : ($isArticle ? '新闻或文章页面' : '未识别为目标公司'));
    return ['raw' => $raw, 'facts' => $facts];
}

function radar_save_candidate_from_analysis(array $analysis, array $task): array
{
    $facts = $analysis['facts'];
    $raw = $analysis['raw'];
    $domain = (string)$facts['domain'];
    if ((int)$facts['is_real_company'] !== 1 || $domain === '') {
        db()->prepare("UPDATE crm_radar_raw_results SET is_company=0, page_type=?, analysis_json=?, updated_at=NOW() WHERE id=?")
            ->execute([(string)$facts['excluded_reason'], json_encode($facts, JSON_UNESCAPED_UNICODE), (int)$raw['id']]);
        return ['candidate_id' => 0, 'excluded' => true, 'reason' => (string)$facts['excluded_reason']];
    }
    $existing = db()->prepare('SELECT id FROM crm_radar_candidates WHERE task_id=? AND domain=? AND deleted_at IS NULL LIMIT 1');
    $existing->execute([(int)$task['id'], $domain]);
    $candidateId = (int)($existing->fetchColumn() ?: 0);
    $fieldMeta = [];
    $now = date('Y-m-d H:i:s');
    foreach (radar_candidate_field_names() as $field) {
        $value = $facts[$field] ?? '';
        $metaValue = is_array($value) ? implode('；', $value) : (string)$value;
        $found = $value !== '' && $value !== '未找到' && $value !== [] && $value !== null;
        $fieldMeta[$field] = [
            'value' => $metaValue,
            'source_url' => (string)$facts['source_url'],
            'confidence' => in_array($field, ['website','domain','company_name','source_url'], true) ? 90 : ($found ? 60 : 0),
            'manual_confirmed' => 0,
            'verified_fact' => $found && strpos($metaValue, 'AI推测') === false ? 1 : 0,
            'updated_at' => $now,
        ];
    }
    $userId = (int)(current_user()['id'] ?? 0) ?: null;
    $data = [
        'task_id' => (int)$task['id'],
        'company_name' => (string)$facts['company_name'],
        'local_name' => (string)$facts['local_name'],
        'country' => (string)$facts['country'],
        'city' => (string)$facts['city'],
        'address' => (string)$facts['address'],
        'website' => (string)$facts['website'],
        'domain' => $domain,
        'company_type' => (string)$facts['company_type'],
        'model_key' => (string)$facts['model_key'],
        'founded_year' => (string)$facts['founded_year'],
        'company_intro' => (string)$facts['company_intro'],
        'main_products' => (string)$facts['main_products'],
        'product_keywords_json' => json_encode($facts['product_keywords'] ?? [], JSON_UNESCAPED_UNICODE),
        'project_types' => (string)$facts['project_types'],
        'service_industries' => (string)$facts['service_industries'],
        'represented_brands' => (string)$facts['represented_brands'],
        'has_own_brand' => (int)$facts['has_own_brand'],
        'has_showroom' => (int)$facts['has_showroom'],
        'has_projects' => (int)$facts['has_projects'],
        'has_design_team' => (int)$facts['has_design_team'],
        'has_sales_team' => (int)$facts['has_sales_team'],
        'has_procurement_role' => (int)$facts['has_procurement_role'],
        'contact_name' => (string)$facts['contact_name'],
        'contact_position' => (string)$facts['contact_position'],
        'email' => (string)$facts['email'],
        'phone' => (string)$facts['phone'],
        'whatsapp' => (string)$facts['whatsapp'],
        'linkedin' => (string)$facts['linkedin'],
        'facebook' => (string)$facts['facebook'],
        'instagram' => (string)$facts['instagram'],
        'source_url' => (string)$facts['source_url'],
        'source_title' => (string)$facts['source_title'],
        'data_completeness' => (int)$facts['data_completeness'],
        'crm_duplicate_status' => (string)$facts['duplicate']['status'],
        'crm_duplicate_customer_id' => (int)($facts['duplicate']['matches'][0]['id'] ?? 0) ?: null,
        'crm_duplicate_json' => json_encode($facts['duplicate']['matches'], JSON_UNESCAPED_UNICODE),
        'radar_score' => (float)$facts['score'],
        'grade' => (string)$facts['grade'],
        'match_reasons_json' => json_encode($facts['match_reasons'], JSON_UNESCAPED_UNICODE),
        'risk_warnings_json' => json_encode($facts['risk_warnings'], JSON_UNESCAPED_UNICODE),
        'field_meta_json' => json_encode($fieldMeta, JSON_UNESCAPED_UNICODE),
        'is_real_company' => (int)$facts['is_real_company'],
        'has_independent_website' => (int)$facts['has_independent_website'],
        'is_vietnam_market' => (int)$facts['is_vietnam_market'],
        'excluded_reason' => (string)$facts['excluded_reason'],
        'updated_by' => $userId,
    ];
    if ($candidateId > 0) {
        $sets = [];
        $params = [];
        foreach ($data as $column => $value) {
            $sets[] = $column . '=?';
            $params[] = $value;
        }
        $params[] = $candidateId;
        db()->prepare('UPDATE crm_radar_candidates SET ' . implode(',', $sets) . ', crawled_at=NOW(), last_verified_at=NOW(), updated_at=NOW() WHERE id=?')
            ->execute($params);
    } else {
        $data['created_by'] = $userId;
        $columns = array_keys($data);
        $values = array_values($data);
        db()->prepare('INSERT INTO crm_radar_candidates (' . implode(',', $columns) . ',crawled_at,last_verified_at,created_at,updated_at) VALUES (' . implode(',', array_fill(0, count($columns), '?')) . ',NOW(),NOW(),NOW(),NOW())')
            ->execute($values);
        $candidateId = (int)db()->lastInsertId();
    }
    db()->prepare('INSERT INTO crm_radar_candidate_sources (candidate_id,source_type,source_url,title,snippet,captured_at,created_at) VALUES (?,?,?,?,?,NOW(),NOW())')
        ->execute([$candidateId, 'search_result', (string)$facts['source_url'], (string)$facts['source_title'], (string)($raw['snippet'] ?? '')]);
    db()->prepare('DELETE FROM crm_radar_candidate_scores WHERE candidate_id=?')->execute([$candidateId]);
    $scoreStmt = db()->prepare('INSERT INTO crm_radar_candidate_scores (candidate_id,score_key,score_value,score_reason,detail_json,created_at) VALUES (?,?,?,?,?,NOW())');
    foreach ($facts['score_rules'] as $rule) $scoreStmt->execute([$candidateId, (string)$rule['key'], (float)$rule['value'], (string)$rule['reason'], json_encode($rule, JSON_UNESCAPED_UNICODE)]);
    db()->prepare("UPDATE crm_radar_raw_results SET is_company=1, company_key=?, candidate_id=?, page_type='company', analysis_json=?, updated_at=NOW() WHERE id=?")
        ->execute([$domain, $candidateId, json_encode($facts, JSON_UNESCAPED_UNICODE), (int)$raw['id']]);
    radar_log('candidate_analyzed', ['task_id' => (int)$task['id'], 'candidate_id' => $candidateId, 'raw_result_id' => (int)$raw['id'], 'score' => (float)$facts['score']]);
    return ['candidate_id' => $candidateId, 'excluded' => false, 'grade' => $facts['grade'], 'score' => $facts['score']];
}

function radar_candidate_select_sql(): string
{
    return 'SELECT c.*, t.task_name FROM crm_radar_candidates c LEFT JOIN crm_radar_search_tasks t ON t.id=c.task_id';
}

function radar_candidates_list(array $input = []): array
{
    radar_ensure_tables();
    crm_require('radar_candidate_view');
    $where = ['c.deleted_at IS NULL'];
    $params = [];
    $map = ['city' => 'c.city', 'model_key' => 'c.model_key', 'company_type' => 'c.company_type', 'grade' => 'c.grade', 'review_status' => 'c.review_status', 'task_id' => 'c.task_id'];
    foreach ($map as $key => $col) {
        if (($input[$key] ?? '') !== '') { $where[] = "$col=?"; $params[] = $input[$key]; }
    }
    if (($input['q'] ?? '') !== '') {
        $q = '%' . trim((string)$input['q']) . '%';
        $where[] = '(c.company_name LIKE ? OR c.domain LIKE ? OR c.website LIKE ? OR c.email LIKE ? OR c.country LIKE ? OR c.city LIKE ? OR c.company_type LIKE ? OR c.main_products LIKE ? OR c.project_types LIKE ? OR c.match_reasons_json LIKE ?)';
        array_push($params, $q, $q, $q, $q, $q, $q, $q, $q, $q, $q);
    }
    if (($input['duplicate'] ?? '') !== '') {
        if ((string)$input['duplicate'] === 'new') $where[] = "c.crm_duplicate_status IN ('新客户','new_customer','未检查')";
        else $where[] = "c.crm_duplicate_status NOT IN ('新客户','new_customer','未检查')";
    }
    if (($input['has_website'] ?? '') !== '') $where[] = ((int)$input['has_website'] ? 'c.website<>""' : '(c.website IS NULL OR c.website="")');
    if (($input['has_email'] ?? '') !== '') $where[] = ((int)$input['has_email'] ? 'c.email<>"" AND c.email<>"未找到"' : '(c.email IS NULL OR c.email="" OR c.email="未找到")');
    $countryWhere = $where;
    $countryParams = $params;
    if (($input['country'] ?? '') !== '') {
        if ((string)$input['country'] === '未标记国家') $where[] = '(c.country IS NULL OR c.country="")';
        else { $where[] = 'c.country=?'; $params[] = $input['country']; }
    }
    $page = max(1, (int)($input['page'] ?? 1));
    $pageSize = max(10, min(100, (int)($input['page_size'] ?? 20)));
    $sqlWhere = implode(' AND ', $where);
    $count = db()->prepare('SELECT COUNT(*) FROM crm_radar_candidates c WHERE ' . $sqlWhere);
    $count->execute($params);
    $countryStmt = db()->prepare('SELECT COALESCE(NULLIF(c.country, ""), "未标记国家") AS country, COUNT(*) AS total FROM crm_radar_candidates c WHERE ' . implode(' AND ', $countryWhere) . ' GROUP BY COALESCE(NULLIF(c.country, ""), "未标记国家") ORDER BY total DESC, country ASC LIMIT 40');
    $countryStmt->execute($countryParams);
    $stmt = db()->prepare(radar_candidate_select_sql() . ' WHERE ' . $sqlWhere . ' ORDER BY COALESCE(NULLIF(c.country, ""), "未标记国家") ASC, COALESCE(NULLIF(c.city, ""), "") ASC, c.radar_score DESC, c.id DESC LIMIT ' . $pageSize . ' OFFSET ' . (($page - 1) * $pageSize));
    $stmt->execute($params);
    return ['rows' => $stmt->fetchAll(), 'total' => (int)$count->fetchColumn(), 'page' => $page, 'page_size' => $pageSize, 'country_counts' => $countryStmt->fetchAll()];
}

function radar_candidate_get(int $id): array
{
    radar_ensure_tables();
    crm_require('radar_candidate_view');
    $stmt = db()->prepare(radar_candidate_select_sql() . ' WHERE c.id=? AND c.deleted_at IS NULL LIMIT 1');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if (!$row) throw new RuntimeException('候选客户不存在');
    foreach (['product_keywords_json','crm_duplicate_json','match_reasons_json','risk_warnings_json','field_meta_json'] as $key) $row[$key . '_decoded'] = radar_decode_json($row[$key] ?? '[]');
    $sources = db()->prepare('SELECT * FROM crm_radar_candidate_sources WHERE candidate_id=? ORDER BY id DESC LIMIT 30');
    $sources->execute([$id]);
    $scores = db()->prepare('SELECT * FROM crm_radar_candidate_scores WHERE candidate_id=? ORDER BY id ASC');
    $scores->execute([$id]);
    return ['candidate' => $row, 'sources' => $sources->fetchAll(), 'scores' => $scores->fetchAll()];
}

function radar_candidate_reanalyze(int $id): array
{
    radar_ensure_tables();
    crm_require('radar_task_run');
    $raw = db()->prepare('SELECT r.* FROM crm_radar_raw_results r WHERE r.candidate_id=? ORDER BY r.id DESC LIMIT 1');
    $raw->execute([$id]);
    $rawRow = $raw->fetch();
    if (!$rawRow) throw new RuntimeException('没有找到该候选客户的原始来源');
    $task = db()->prepare('SELECT * FROM crm_radar_search_tasks WHERE id=? LIMIT 1');
    $task->execute([(int)$rawRow['task_id']]);
    $taskRow = $task->fetch();
    if (!$taskRow) throw new RuntimeException('搜索任务不存在');
    $result = radar_save_candidate_from_analysis(radar_analyze_raw_result((int)$rawRow['id'], $taskRow), $taskRow);
    return radar_candidate_get((int)$result['candidate_id']);
}

function radar_candidate_recheck_duplicate(int $id): array
{
    radar_ensure_tables();
    crm_require('radar_candidate_view');
    $detail = radar_candidate_get($id);
    $c = $detail['candidate'];
    $dup = radar_duplicate_check(['company_name' => $c['company_name'], 'domain' => $c['domain'], 'email' => $c['email'], 'phone' => $c['phone']]);
    db()->prepare('UPDATE crm_radar_candidates SET crm_duplicate_status=?, crm_duplicate_customer_id=?, crm_duplicate_json=?, updated_at=NOW() WHERE id=?')
        ->execute([(string)$dup['status'], (int)($dup['matches'][0]['id'] ?? 0) ?: null, json_encode($dup['matches'], JSON_UNESCAPED_UNICODE), $id]);
    radar_log('candidate_duplicate_recheck', ['candidate_id' => $id, 'status' => $dup['status']]);
    return radar_candidate_get($id);
}

function radar_candidate_manual_save(array $input): array
{
    radar_ensure_tables();
    crm_require('radar_candidate_review');
    $id = (int)($input['id'] ?? 0);
    if ($id <= 0) throw new RuntimeException('候选客户ID无效');
    $type = trim((string)($input['company_type'] ?? ''));
    $model = trim((string)($input['model_key'] ?? ''));
    $note = trim((string)($input['review_note'] ?? ''));
    if ($type !== '' && !in_array($type, radar_candidate_type_options(), true)) throw new RuntimeException('客户分类不在允许范围内');
    if ($model !== '' && !in_array($model, ['direct_buyer','design_influencer','brand_oem'], true)) throw new RuntimeException('客户模型无效');
    $before = radar_candidate_get($id)['candidate'];
    $sets = [];
    $params = [];
    if ($type !== '') { $sets[] = 'company_type=?'; $params[] = $type; }
    if ($model !== '') { $sets[] = 'model_key=?'; $params[] = $model; }
    if (!$sets && $note === '') throw new RuntimeException('没有需要保存的内容');
    if ($sets) {
        $sets[] = 'updated_by=?';
        $params[] = (int)(current_user()['id'] ?? 0) ?: null;
        $params[] = $id;
        db()->prepare('UPDATE crm_radar_candidates SET ' . implode(',', $sets) . ', updated_at=NOW() WHERE id=?')->execute($params);
    }
    if ($note !== '') {
        db()->prepare('INSERT INTO crm_radar_candidate_reviews (candidate_id,review_status,review_note,reviewed_by,reviewed_at,created_at) VALUES (?,?,?,?,NOW(),NOW())')
            ->execute([$id, (string)($before['review_status'] ?? 'pending'), $note, (int)(current_user()['id'] ?? 0) ?: null]);
    }
    radar_log('candidate_manual_save', ['candidate_id' => $id, 'before' => ['company_type' => $before['company_type'] ?? '', 'model_key' => $before['model_key'] ?? ''], 'after' => ['company_type' => $type, 'model_key' => $model]]);
    return radar_candidate_get($id);
}

function radar_candidate_status(array $input): array
{
    radar_ensure_tables();
    crm_require('radar_candidate_review');
    $ids = array_values(array_filter(array_map('intval', (array)($input['ids'] ?? [($input['id'] ?? 0)]))));
    if (!$ids) throw new RuntimeException('请选择候选客户');
    $status = (string)($input['status'] ?? 'pending');
    if (!array_key_exists($status, radar_review_status_options())) throw new RuntimeException('不支持的审核状态');
    if ($status === 'converted') throw new RuntimeException('已转CRM状态只能由转入CRM操作生成');
    $note = mb_substr(trim((string)($input['review_note'] ?? '')), 0, 1000);
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $deleted = $status === 'archived' || $status === 'blacklisted' ? ', deleted_at=NOW()' : '';
    $beforeStmt = db()->prepare("SELECT id, review_status FROM crm_radar_candidates WHERE id IN ($ph)");
    $beforeStmt->execute($ids);
    $before = $beforeStmt->fetchAll();
    db()->prepare("UPDATE crm_radar_candidates SET review_status=?, reviewed_by=?, reviewed_at=NOW(), updated_by=?, updated_at=NOW() $deleted WHERE id IN ($ph)")
        ->execute(array_merge([$status, (int)(current_user()['id'] ?? 0) ?: null, (int)(current_user()['id'] ?? 0) ?: null], $ids));
    $reviewStmt = db()->prepare('INSERT INTO crm_radar_candidate_reviews (candidate_id,review_status,review_note,reviewed_by,reviewed_at,before_json,after_json,created_at) VALUES (?,?,?,?,NOW(),?,?,NOW())');
    foreach ($ids as $id) {
        $old = null;
        foreach ($before as $row) if ((int)$row['id'] === (int)$id) $old = $row;
        $reviewStmt->execute([$id, $status, $note, (int)(current_user()['id'] ?? 0) ?: null, json_encode($old ?: [], JSON_UNESCAPED_UNICODE), json_encode(['review_status' => $status, 'review_note' => $note], JSON_UNESCAPED_UNICODE)]);
    }
    if ($status === 'blacklisted') {
        $rows = db()->prepare("SELECT domain, company_name FROM crm_radar_candidates WHERE id IN ($ph)");
        $rows->execute($ids);
        $ins = db()->prepare('INSERT IGNORE INTO crm_radar_blacklist (blacklist_type,blacklist_value,reason,created_by,created_at) VALUES (?,?,?,?,NOW())');
        foreach ($rows->fetchAll() as $row) {
            $value = trim((string)($row['domain'] ?: $row['company_name']));
            if ($value !== '') $ins->execute([$row['domain'] ? 'domain' : 'company', $value, $note ?: '人工加入黑名单', (int)(current_user()['id'] ?? 0) ?: null]);
        }
    }
    radar_log('candidate_status_update', ['ids' => $ids, 'status' => $status, 'note' => $note]);
    return ['updated' => count($ids), 'status' => $status];
}

function radar_review_options(): array
{
    radar_ensure_tables();
    crm_require('radar_candidate_view');
    $current = current_user() ?: [];
    $canAssignAll = has_permission('customer.assign') || is_super_admin();
    $users = [];
    if ($canAssignAll) {
        $users = db()->query("SELECT u.id, u.username, u.real_name, u.english_name, d.name AS department_name FROM crm_users u LEFT JOIN crm_departments d ON d.id=u.department_id WHERE u.status='active' ORDER BY d.sort_order, d.id, u.real_name, u.username")->fetchAll();
    } elseif (!empty($current['id'])) {
        $users = [[
            'id' => (int)$current['id'],
            'username' => (string)($current['username'] ?? ''),
            'real_name' => (string)($current['real_name'] ?? ''),
            'english_name' => (string)($current['english_name'] ?? ''),
            'department_name' => (string)($current['department_name'] ?? ''),
        ]];
    }
    $groups = radar_table_exists('crm_customer_groups') ? db()->query('SELECT id, group_name FROM crm_customer_groups WHERE deleted_at IS NULL ORDER BY sort_order, id')->fetchAll() : [];
    return ['users' => $users, 'groups' => $groups, 'statuses' => radar_review_status_options(), 'can_assign_all' => $canAssignAll];
}

function radar_candidate_contacts_for_convert(int $candidateId, array $candidate): array
{
    $contacts = [];
    $stmt = db()->prepare('SELECT * FROM crm_radar_candidate_contacts WHERE candidate_id=? ORDER BY id ASC');
    $stmt->execute([$candidateId]);
    foreach ($stmt->fetchAll() as $row) {
        if (trim((string)($row['contact_name'] ?? '')) === '' && trim((string)($row['email'] ?? '')) === '') continue;
        $contacts[] = [
            'name' => (string)($row['contact_name'] ?: '待验证联系人'),
            'position' => (string)($row['position'] ?? ''),
            'email' => (string)($row['email'] ?? ''),
            'phone' => (string)($row['phone'] ?? ''),
            'whatsapp' => (string)($row['whatsapp'] ?? ''),
            'linkedin' => (string)($row['linkedin'] ?? ''),
            'is_primary' => count($contacts) === 0 ? 1 : 0,
            'contact_sources' => 'manual',
            'remark' => trim('AI客户雷达导入；来源：' . (string)($row['source_url'] ?? '') . '；状态：' . (!empty($row['is_verified']) ? '已验证' : '待验证')),
        ];
    }
    if (!$contacts && (trim((string)($candidate['contact_name'] ?? '')) !== '' || trim((string)($candidate['email'] ?? '')) !== '')) {
        $contacts[] = [
            'name' => (string)($candidate['contact_name'] ?: '待验证联系人'),
            'position' => (string)($candidate['contact_position'] ?? ''),
            'email' => (string)($candidate['email'] ?? ''),
            'phone' => (string)($candidate['phone'] ?? ''),
            'whatsapp' => (string)($candidate['whatsapp'] ?? ''),
            'linkedin' => (string)($candidate['linkedin'] ?? ''),
            'is_primary' => 1,
            'contact_sources' => 'manual',
            'remark' => trim('AI客户雷达导入；来源：' . (string)($candidate['source_url'] ?? '') . '；状态：待验证'),
        ];
    }
    return $contacts;
}

function radar_customer_level_for_grade(string $grade): string
{
    return ['A' => '重点潜在客户', 'B' => '优质潜在客户', 'C' => '普通潜在客户'][$grade] ?? '普通潜在客户';
}

function radar_candidate_convert_to_crm(array $input): array
{
    radar_ensure_tables();
    crm_require('radar_candidate_to_crm');
    crm_require('customer.create');
    $id = (int)($input['id'] ?? 0);
    if ($id <= 0) throw new RuntimeException('候选客户ID无效');
    $detail = radar_candidate_get($id);
    $c = $detail['candidate'];
    if (!in_array((string)($c['review_status'] ?? 'pending'), radar_allowed_convert_statuses(), true)) throw new RuntimeException('只有精准、基本精准、一般状态可以转入CRM。');
    if (!empty($c['converted_customer_id'])) throw new RuntimeException('该候选客户已转入CRM，不能重复创建。');
    $dup = radar_duplicate_check(['company_name' => $c['company_name'], 'domain' => $c['domain'], 'email' => $c['email'], 'phone' => $c['phone'], 'website' => $c['website']]);
    if (!in_array((string)$dup['status'], ['新客户', 'new_customer', '未检查'], true) && empty($input['duplicate_confirmed'])) {
        db()->prepare('UPDATE crm_radar_candidates SET crm_duplicate_status=?, crm_duplicate_customer_id=?, crm_duplicate_json=?, updated_at=NOW() WHERE id=?')
            ->execute([(string)$dup['status'], (int)($dup['matches'][0]['id'] ?? 0) ?: null, json_encode($dup['matches'], JSON_UNESCAPED_UNICODE), $id]);
        throw new RuntimeException('转入前发现CRM疑似重复，请先确认或选择现有客户合并。');
    }
    $existingCustomerId = (int)($input['existing_customer_id'] ?? 0);
    if ($existingCustomerId > 0) {
        crm_customer_get($existingCustomerId);
        db()->prepare("UPDATE crm_radar_candidates SET review_status='duplicate', converted_customer_id=?, converted_by=?, converted_at=NOW(), conversion_note=?, updated_by=?, updated_at=NOW() WHERE id=?")
            ->execute([$existingCustomerId, (int)(current_user()['id'] ?? 0) ?: null, mb_substr(trim((string)($input['note'] ?? '选择CRM现有客户合并')), 0, 1000), (int)(current_user()['id'] ?? 0) ?: null, $id]);
        crm_customer_log('radar_candidate_link_existing', 'customer', $existingCustomerId, $existingCustomerId, null, ['candidate_id' => $id, 'candidate' => $c], 'AI客户雷达候选客户关联到现有CRM客户');
        radar_log('candidate_link_existing_crm', ['candidate_id' => $id, 'customer_id' => $existingCustomerId]);
        return ['customer_id' => $existingCustomerId, 'mode' => 'linked_existing'];
    }
    $ownerIds = array_values(array_filter(array_unique(array_map('intval', (array)($input['owner_user_ids'] ?? [])))));
    if (!$ownerIds) $ownerIds = [(int)(current_user()['id'] ?? 0)];
    if (!(has_permission('customer.assign') || is_super_admin())) $ownerIds = [(int)(current_user()['id'] ?? 0)];
    $ownerId = (int)($ownerIds[0] ?? 0);
    if ($ownerId <= 0) throw new RuntimeException('请选择负责人');
    $groupIds = array_values(array_filter(array_unique(array_map('intval', (array)($input['group_ids'] ?? [])))));
    $contacts = radar_candidate_contacts_for_convert($id, $c);
    $reasons = radar_decode_json($c['match_reasons_json'] ?? '[]');
    $risks = radar_decode_json($c['risk_warnings_json'] ?? '[]');
    $note = trim((string)($input['note'] ?? ''));
    $radarRemark = "来源：AI客户雷达\n搜索任务：" . (string)($c['task_name'] ?? '-') . "\n搜索任务编号：" . (string)($c['task_id'] ?? '-') . "\n候选ID：{$id}\n发现时间：" . (string)($c['created_at'] ?? '') . "\n原始匹配分：" . (string)($c['radar_score'] ?? 0) . "\n匹配等级：" . (string)($c['grade'] ?? '') . "\n客户模型：" . (string)($c['model_key'] ?? '') . "\n匹配理由：" . implode('；', array_map('strval', $reasons)) . "\n风险提示：" . implode('；', array_map('strval', $risks)) . "\n数据来源：" . (string)($c['source_url'] ?? '');
    $payload = [
        'customer_name' => (string)($c['company_name'] ?? ''),
        'customer_name_en' => (string)($c['local_name'] ?? ''),
        'country' => (string)($c['country'] ?? ''),
        'city' => (string)($c['city'] ?? ''),
        'address' => (string)($c['address'] ?? ''),
        'website' => (string)($c['website'] ?? ''),
        'email' => (string)($c['email'] ?? ''),
        'phone' => (string)($c['phone'] ?? ''),
        'whatsapp' => (string)($c['whatsapp'] ?? ''),
        'source' => 'website',
        'source_tags' => ['website'],
        'level' => radar_customer_level_for_grade((string)($c['grade'] ?? 'C')),
        'status' => 'lead',
        'lifecycle_key' => 'lead',
        'owner_user_id' => $ownerId,
        'owner_user_ids' => $ownerIds,
        'group_ids' => $groupIds,
        'promotion_status' => 'not_promoted',
        'contacts_json' => json_encode($contacts, JSON_UNESCAPED_UNICODE),
        'remark' => trim($note . "\n" . $radarRemark),
        'entry_mode' => 'force',
        'duplicate_risk_confirmed' => 1,
    ];
    $created = crm_customer_create_confirmed($payload);
    $customerId = (int)(($created['customer']['id'] ?? 0) ?: ($created['id'] ?? 0));
    if ($customerId <= 0) throw new RuntimeException('CRM客户创建失败');
    db()->prepare("UPDATE crm_customers SET source='AI客户雷达', updated_by=?, updated_at=NOW() WHERE id=?")->execute([(int)(current_user()['id'] ?? 0) ?: null, $customerId]);
    if (count($ownerIds) > 1) crm_batch_assign([$customerId], $ownerId, $ownerIds);
    if (!empty($input['join_promotion_pool'])) {
        db()->prepare('INSERT INTO crm_customer_promotion_status (customer_id,status,updated_by,updated_at) VALUES (?,"not_promoted",?,NOW()) ON DUPLICATE KEY UPDATE updated_by=VALUES(updated_by), updated_at=NOW()')
            ->execute([$customerId, (int)(current_user()['id'] ?? 0) ?: null]);
    }
    db()->prepare("UPDATE crm_radar_candidates SET review_status='converted', converted_customer_id=?, converted_by=?, converted_at=NOW(), assigned_owner_ids_json=?, assigned_group_ids_json=?, promotion_pool_requested=?, conversion_note=?, updated_by=?, updated_at=NOW() WHERE id=?")
        ->execute([$customerId, (int)(current_user()['id'] ?? 0) ?: null, json_encode($ownerIds, JSON_UNESCAPED_UNICODE), json_encode($groupIds, JSON_UNESCAPED_UNICODE), !empty($input['join_promotion_pool']) ? 1 : 0, mb_substr($note, 0, 1000), (int)(current_user()['id'] ?? 0) ?: null, $id]);
    crm_customer_log('radar_convert_to_crm', 'customer', $customerId, $customerId, null, [
        'source' => 'AI客户雷达',
        'candidate_id' => $id,
        'task_name' => (string)($c['task_name'] ?? ''),
        'task_id' => (int)($c['task_id'] ?? 0),
        'found_at' => (string)($c['created_at'] ?? ''),
        'score' => (float)($c['radar_score'] ?? 0),
        'grade' => (string)($c['grade'] ?? ''),
        'model_key' => (string)($c['model_key'] ?? ''),
        'match_reasons' => $reasons,
        'risk_warnings' => $risks,
        'source_url' => (string)($c['source_url'] ?? ''),
        'owner_user_ids' => $ownerIds,
        'group_ids' => $groupIds,
    ], 'AI客户雷达转入CRM');
    radar_log('candidate_convert_to_crm', ['candidate_id' => $id, 'customer_id' => $customerId, 'owner_user_ids' => $ownerIds, 'group_ids' => $groupIds]);
    return ['customer_id' => $customerId, 'created_contact_count' => count($contacts), 'mode' => 'created'];
}

function radar_feedback_save(array $input): array
{
    radar_ensure_tables();
    crm_require('radar_feedback_submit');
    $candidateId = (int)($input['candidate_id'] ?? 0);
    $customerId = (int)($input['customer_id'] ?? 0);
    $type = trim((string)($input['feedback_type'] ?? ''));
    $allowed = ['非常精准','基本精准','一般','不精准','已联系','有回复','有询价','已报价','已寄样','有项目','已成交','无回复','邮箱退信','明确拒绝','非目标客户','重复客户'];
    if (!in_array($type, $allowed, true)) throw new RuntimeException('反馈类型无效');
    $candidate = [];
    if ($candidateId > 0) $candidate = radar_candidate_get($candidateId)['candidate'];
    db()->prepare('INSERT INTO crm_radar_feedback (candidate_id,customer_id,task_id,keyword,seed_id,feedback_type,feedback_note,original_score,created_by,created_at) VALUES (?,?,?,?,?,?,?,?,?,NOW())')
        ->execute([$candidateId ?: null, $customerId ?: null, (int)($candidate['task_id'] ?? 0) ?: null, mb_substr(trim((string)($input['keyword'] ?? '')), 0, 255), (int)($input['seed_id'] ?? 0) ?: null, $type, mb_substr(trim((string)($input['feedback_note'] ?? '')), 0, 1000), (float)($candidate['radar_score'] ?? 0), (int)(current_user()['id'] ?? 0) ?: null]);
    radar_log('feedback_save', ['candidate_id' => $candidateId, 'customer_id' => $customerId, 'feedback_type' => $type]);
    return ['saved' => true];
}

function radar_worker_claim_job(): ?array
{
    $token = bin2hex(random_bytes(8));
    db()->exec("UPDATE crm_radar_job_queue SET job_status='pending', lock_token=NULL, locked_at=NULL, updated_at=NOW() WHERE job_status='running' AND locked_at < DATE_SUB(NOW(), INTERVAL 20 MINUTE)");
    $row = db()->query("SELECT q.* FROM crm_radar_job_queue q JOIN crm_radar_search_tasks t ON t.id=q.task_id WHERE q.job_status='pending' AND (q.scheduled_at IS NULL OR q.scheduled_at<=NOW()) AND t.deleted_at IS NULL AND t.task_status NOT IN ('paused','cancelled','completed') ORDER BY " . radar_task_order_sql('t') . ", q.id ASC LIMIT 1")->fetch();
    if (!$row) return null;
    $lock = db()->prepare("UPDATE crm_radar_job_queue SET job_status='running', attempts=attempts+1, started_at=COALESCE(started_at,NOW()), locked_at=NOW(), lock_token=?, updated_at=NOW() WHERE id=? AND job_status='pending'");
    $lock->execute([$token, (int)$row['id']]);
    if ($lock->rowCount() <= 0) return null;
    $row['lock_token'] = $token;
    return $row;
}

function radar_worker_finish_job(array $job, bool $ok, array $result = [], string $error = ''): void
{
    if ($ok) {
        db()->prepare("UPDATE crm_radar_job_queue SET job_status='done', result_json=?, last_error=NULL, finished_at=NOW(), locked_at=NULL, lock_token=NULL, updated_at=NOW() WHERE id=?")
            ->execute([json_encode($result, JSON_UNESCAPED_UNICODE), (int)$job['id']]);
        return;
    }
    $attempts = (int)$job['attempts'] + 1;
    $max = (int)($job['max_attempts'] ?? 3);
    if ($attempts < $max) {
        db()->prepare("UPDATE crm_radar_job_queue SET job_status='pending', last_error=?, next_retry_at=DATE_ADD(NOW(), INTERVAL ? MINUTE), scheduled_at=DATE_ADD(NOW(), INTERVAL ? MINUTE), locked_at=NULL, lock_token=NULL, updated_at=NOW() WHERE id=?")
            ->execute([$error, max(1, $attempts), max(1, $attempts), (int)$job['id']]);
    } else {
        db()->prepare("UPDATE crm_radar_job_queue SET job_status='failed', last_error=?, finished_at=NOW(), locked_at=NULL, lock_token=NULL, updated_at=NOW() WHERE id=?")
            ->execute([$error, (int)$job['id']]);
    }
}

function radar_worker_update_task(int $taskId): void
{
    $counts = db()->prepare("SELECT job_status, COUNT(*) c FROM crm_radar_job_queue WHERE task_id=? GROUP BY job_status");
    $counts->execute([$taskId]);
    $map = [];
    foreach ($counts->fetchAll() as $r) $map[(string)$r['job_status']] = (int)$r['c'];
    $total = array_sum($map);
    $done = (int)($map['done'] ?? 0);
    $failed = (int)($map['failed'] ?? 0);
    $pending = (int)($map['pending'] ?? 0) + (int)($map['running'] ?? 0);
    $progress = $total > 0 ? (int)floor(($done + $failed) * 100 / $total) : 0;
    $rawCount = (int)db()->query('SELECT COUNT(*) FROM crm_radar_raw_results WHERE task_id=' . (int)$taskId)->fetchColumn();
    $status = null;
    if ($total > 0 && $pending === 0) $status = $failed > 0 ? 'partial_completed' : 'waiting_analysis';
    $sql = 'UPDATE crm_radar_search_tasks SET progress_percent=?, searched_pages=?, failed_count=?, updated_at=NOW()' . ($status ? ', task_status=?, finished_at=NOW()' : '') . ' WHERE id=?';
    $params = [$progress, $rawCount, $failed];
    if ($status) $params[] = $status;
    $params[] = $taskId;
    db()->prepare($sql)->execute($params);
}

function radar_worker_process_job(array $job): void
{
    $st = db()->prepare('SELECT * FROM crm_radar_search_tasks WHERE id=? LIMIT 1');
    $st->execute([(int)$job['task_id']]);
    $task = $st->fetch();
    if (!$task) throw new RuntimeException('主任务不存在');
    if (in_array((string)$task['task_status'], ['paused','cancelled'], true)) throw new RuntimeException('主任务已暂停或取消');
    $payload = json_decode((string)($job['payload_json'] ?? '{}'), true) ?: [];
    if ($job['job_type'] === 'generate_keywords') {
        db()->prepare("UPDATE crm_radar_search_tasks SET task_status='generating_keywords', started_at=COALESCE(started_at,NOW()), updated_at=NOW() WHERE id=?")->execute([(int)$task['id']]);
        $limit = max(0, (int)($payload['limit'] ?? 0));
        $keywords = radar_task_keywords($task, $limit);
        if (!$keywords) throw new RuntimeException('没有可用关键词');
        foreach ($keywords as $kw) radar_queue_job((int)$task['id'], 'search_keyword', ['keyword' => $kw], 3);
        radar_log('task_keywords_generated', ['task_id' => (int)$task['id'], 'keywords' => $keywords]);
        return;
    }
    if ($job['job_type'] === 'search_keyword') {
        db()->prepare("UPDATE crm_radar_search_tasks SET task_status='searching', updated_at=NOW() WHERE id=?")->execute([(int)$task['id']]);
        $keyword = trim((string)($payload['keyword'] ?? ''));
        if ($keyword === '') throw new RuntimeException('关键词为空');
        $res = radar_search_service_call($task, $keyword);
        foreach ($res['rows'] as $row) {
            if (trim((string)$row['url']) === '') continue;
            db()->prepare('INSERT INTO crm_radar_raw_results (task_id, job_id, keyword, service_key, title, url, snippet, rank_no, query_time, country, city, language, fetch_status, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,NOW(),?,?,?,"pending",NOW(),NOW())')
                ->execute([(int)$task['id'], (int)$job['id'], $keyword, (string)($res['service']['service_key'] ?? ''), mb_substr((string)$row['title'],0,500), mb_substr((string)$row['url'],0,1000), (string)$row['snippet'], (int)$row['rank_no'], (string)$task['country'], (string)$task['city'], '']);
            radar_queue_job((int)$task['id'], 'fetch_page', ['raw_result_id' => (int)db()->lastInsertId()], 2);
        }
        return;
    }
    if ($job['job_type'] === 'fetch_page') {
        db()->prepare("UPDATE crm_radar_search_tasks SET task_status='fetching_pages', updated_at=NOW() WHERE id=?")->execute([(int)$task['id']]);
        $rawId = (int)($payload['raw_result_id'] ?? 0);
        $raw = db()->prepare('SELECT * FROM crm_radar_raw_results WHERE id=? AND task_id=? LIMIT 1');
        $raw->execute([$rawId, (int)$task['id']]);
        $row = $raw->fetch();
        if (!$row) throw new RuntimeException('原始搜索结果不存在');
        try {
            $summary = radar_fetch_url_summary((string)$row['url']);
            db()->prepare("UPDATE crm_radar_raw_results SET fetch_status='fetched', http_status=?, fetched_at=NOW(), page_title=?, page_description=?, raw_text_summary=?, updated_at=NOW() WHERE id=?")
                ->execute([$summary['http_status'], $summary['page_title'], $summary['page_description'], $summary['raw_text_summary'], $rawId]);
            radar_queue_job((int)$task['id'], 'parse_company', ['raw_result_id' => $rawId], 1);
        } catch (Throwable $e) {
            db()->prepare("UPDATE crm_radar_raw_results SET fetch_status='failed', fail_reason=?, updated_at=NOW() WHERE id=?")->execute([$e->getMessage(), $rawId]);
            radar_log('page_fetch_failed', ['task_id' => (int)$task['id'], 'raw_result_id' => $rawId, 'url' => (string)$row['url']], true, $e->getMessage());
            return;
        }
        return;
    }
    if ($job['job_type'] === 'parse_company') {
        db()->prepare("UPDATE crm_radar_search_tasks SET task_status='identifying_company', updated_at=NOW() WHERE id=?")->execute([(int)$task['id']]);
        $rawId = (int)($payload['raw_result_id'] ?? 0);
        $result = radar_save_candidate_from_analysis(radar_analyze_raw_result($rawId, $task), $task);
        if (empty($result['excluded'])) {
            db()->prepare('UPDATE crm_radar_search_tasks SET found_companies=(SELECT COUNT(*) FROM crm_radar_candidates WHERE task_id=? AND deleted_at IS NULL), updated_at=NOW() WHERE id=?')
                ->execute([(int)$task['id'], (int)$task['id']]);
        }
        return;
    }
    throw new RuntimeException('未知队列类型：' . $job['job_type']);
}

function radar_worker_run(int $limit = 10): array
{
    radar_ensure_tables();
    $settings = radar_settings_get();
    if (empty($settings['task']['worker_enabled'])) return ['processed' => 0, 'status' => 'disabled', 'message' => '后台任务未启用'];
    $processed = 0; $failed = 0; $errors = [];
    for ($i = 0; $i < $limit; $i++) {
        $job = radar_worker_claim_job();
        if (!$job) break;
        try {
            radar_worker_process_job($job);
            radar_worker_finish_job($job, true);
        } catch (Throwable $e) {
            $willRetry = ((int)($job['attempts'] ?? 0) + 1) < (int)($job['max_attempts'] ?? 3);
            radar_worker_finish_job($job, false, [], $e->getMessage());
            db()->prepare('UPDATE crm_radar_search_tasks SET last_error=?, updated_at=NOW()' . ($willRetry ? '' : ', failed_count=failed_count+1') . ' WHERE id=?')->execute([$e->getMessage(), (int)$job['task_id']]);
            if ($willRetry) {
                radar_log('job_retry', ['task_id' => (int)$job['task_id'], 'job_id' => (int)$job['id'], 'type' => $job['job_type'], 'next_attempt' => (int)($job['attempts'] ?? 0) + 2], true, $e->getMessage());
            } else {
                $failed++;
                $errors[] = ['job_id' => (int)$job['id'], 'error' => $e->getMessage()];
                radar_log('job_failed', ['task_id' => (int)$job['task_id'], 'job_id' => (int)$job['id'], 'type' => $job['job_type']], false, $e->getMessage());
            }
        }
        radar_worker_update_task((int)$job['task_id']);
        $processed++;
    }
    return ['processed' => $processed, 'failed' => $failed, 'errors' => $errors];
}

function radar_upgrade_run(): array
{
    crm_require('radar_settings_manage');
    $results = radar_ensure_tables(true);
    $initial = radar_import_initial_data();
    radar_ensure_default_search_service();
    radar_ensure_test_task();
    return ['results' => $results, 'tables' => array_keys(radar_table_definitions()), 'settings' => radar_settings_get(), 'initial' => $initial];
}
