<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

$user = current_user();
$displayName = trim((string) ($user['real_name'] ?? ''));
if ($displayName === '') {
    $displayName = (string) ($user['username'] ?? '');
}
$roleName = (string) ($user['role_name'] ?? '');
?>
<!doctype html>
<html lang="zh-CN">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex">
  <meta name="csrf-token" content="<?= h(csrf_token()) ?>">
  <title>推广中心</title>
  <link rel="stylesheet" href="prototype/styles.css">
  <link rel="stylesheet" href="assets/app.css">
</head>
<body>
  <div class="shell">
    <aside class="side">
      <div class="brand"><span class="brand-mark">推</span><span class="nav-label">推广中心</span></div>
      <nav>
        <button class="nav-item" type="button" data-nav="customers"><span class="nav-label">客户</span></button>
        <button class="nav-item" type="button" data-nav="groups"><span class="nav-label">客户分组</span></button>
        <button class="nav-item" type="button" data-nav="history"><span class="nav-label">历史推广</span></button>
      </nav>
      <div class="side-foot"><div class="nav-label">阶段 1 · 不发送邮件</div></div>
    </aside>
    <div class="main">
      <header class="top">
        <div class="crumbs" id="crumbs">推广中心</div>
        <div class="who">
          <span><?= h($displayName) ?></span>
          <span class="muted"><?= h($roleName) ?></span>
        </div>
      </header>
      <main class="page" id="view"></main>
    </div>
  </div>
  <script src="assets/app.js"></script>
</body>
</html>
