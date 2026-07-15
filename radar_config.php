<?php

function radar_default_settings(): array
{
    return [
        'basic' => [
            'module_enabled' => 0,
            'default_country' => '',
            'default_search_count' => 20,
            'default_min_score' => 70,
            'default_daily_tasks' => 5,
            'default_daily_candidate_limit' => 100,
        ],
        'cost' => [
            'daily_ai_cost_limit' => 0,
            'monthly_ai_cost_limit' => 0,
            'single_task_cost_limit' => 0,
            'contact_enrich_daily_limit' => 0,
            'email_verify_daily_limit' => 0,
        ],
        'task' => [
            'max_retry_count' => 3,
            'company_analysis_timeout' => 60,
            'task_execution_timeout' => 600,
            'cron_secret' => '',
            'worker_enabled' => 0,
        ],
    ];
}

function radar_setting_schema(): array
{
    return [
        'module_enabled' => ['section' => 'basic', 'type' => 'bool'],
        'default_country' => ['section' => 'basic', 'type' => 'string', 'max' => 120],
        'default_search_count' => ['section' => 'basic', 'type' => 'int', 'min' => 0, 'max' => 1000],
        'default_min_score' => ['section' => 'basic', 'type' => 'int', 'min' => 0, 'max' => 100],
        'default_daily_tasks' => ['section' => 'basic', 'type' => 'int', 'min' => 0, 'max' => 1000],
        'default_daily_candidate_limit' => ['section' => 'basic', 'type' => 'int', 'min' => 0, 'max' => 10000],
        'daily_ai_cost_limit' => ['section' => 'cost', 'type' => 'decimal', 'min' => 0, 'max' => 1000000],
        'monthly_ai_cost_limit' => ['section' => 'cost', 'type' => 'decimal', 'min' => 0, 'max' => 10000000],
        'single_task_cost_limit' => ['section' => 'cost', 'type' => 'decimal', 'min' => 0, 'max' => 1000000],
        'contact_enrich_daily_limit' => ['section' => 'cost', 'type' => 'int', 'min' => 0, 'max' => 100000],
        'email_verify_daily_limit' => ['section' => 'cost', 'type' => 'int', 'min' => 0, 'max' => 100000],
        'max_retry_count' => ['section' => 'task', 'type' => 'int', 'min' => 0, 'max' => 20],
        'company_analysis_timeout' => ['section' => 'task', 'type' => 'int', 'min' => 5, 'max' => 3600],
        'task_execution_timeout' => ['section' => 'task', 'type' => 'int', 'min' => 30, 'max' => 86400],
        'cron_secret' => ['section' => 'task', 'type' => 'string', 'max' => 190],
        'worker_enabled' => ['section' => 'task', 'type' => 'bool'],
    ];
}
