<?php
/**
 * Promotion Center entry gate.
 * Unified auth: require_login + promotion.view.
 * Prefer new SPA at promotion/app/ when present; otherwise keep unfinished Office UI.
 * Does not touch CRM marketing (crm_marketing.php / crm_marketing_*).
 */
require_once __DIR__ . '/includes/bootstrap.php';
require_login();
require_permission('promotion.view');

$spa = __DIR__ . '/promotion/app/index.html';
if (is_file($spa)) {
    header('Location: promotion/app/');
    exit;
}

header('Location: promotion/index.php');
exit;
