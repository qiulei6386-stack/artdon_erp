<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';

if (function_exists('is_logged_in') && is_logged_in()) {
    header('Location: ' . ($_GET['redirect'] ?? 'index.php'));
    exit;
}
$error = '';
$redirect = (string)($_GET['redirect'] ?? $_POST['redirect'] ?? 'index.php');
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $result = function_exists('login_user') ? login_user((string)($_POST['username'] ?? ''), (string)($_POST['password'] ?? '')) : ['success'=>false,'message'=>'统一登录不可用'];
    if (!empty($result['success'])) {
        header('Location: ' . $redirect);
        exit;
    }
    $error = (string)($result['message'] ?? '登录失败');
}
?><!doctype html><html lang="zh-CN"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>登录 · Artdon 商务中心</title><link rel="stylesheet" href="assets/css/app.css"></head><body><main class="cc-login"><section class="cc-login-card"><div class="brand cc-login-brand"><span>AD</span><div><strong>Artdon 商务中心 V1</strong><small>COMMERCIAL CENTER V1</small></div></div><h1>登录商务中心</h1><p>使用现有 Artdon ERP 统一账号登录。</p><?php if($error): ?><div class="cc-login-error"><?= cc_h($error) ?></div><?php endif; ?><form method="post"><input type="hidden" name="redirect" value="<?= cc_h($redirect) ?>"><label>账号<input name="username" autocomplete="username" required></label><label>密码<input type="password" name="password" autocomplete="current-password" required></label><button type="submit">登录</button></form><a class="cc-login-back" href="index.php">返回商务中心</a></section></main></body></html>
