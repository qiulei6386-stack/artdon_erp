<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

$script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
$api = dirname($script, 2) . '/api.php';
$login = function_exists('pm_login_url') ? pm_login_url() : (dirname($script, 3) . '/login.php');
?>
<!doctype html>
<html lang="zh-CN">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex">
  <title>推广中心</title>
  <link rel="stylesheet" href="styles.css">
</head>
<body>
  <div class="shell">
    <aside class="side">
      <div class="brand"><span class="brand-mark">推</span><span class="nav-label">推广中心</span></div>
      <nav id="side-nav"></nav>
      <div class="side-foot"><div id="perm-info" class="perm-info"></div><div class="nav-label" style="margin-top:8px">客户和分组读取客户中心。推广列表、执行看板和向导仍是演示数据，不发送邮件。</div><button class="btn sm ghost side-toggle" id="side-toggle" type="button" aria-label="收起或展开导航">«</button></div>
    </aside>
    <div class="main">
      <header class="top">
        <div class="crumbs" id="crumbs"></div>
        <div class="who"><span class="proto-tag">客户中心</span><label for="role-select" class="muted">演示身份</label><select id="role-select" class="select" style="width:auto;height:32px"></select></div>
      </header>
      <main class="page" id="view"></main>
    </div>
  </div>
  <script>
    window.PM_LIVE = {
      api: <?= json_encode($api, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
      loginUrl: <?= json_encode($login, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
      csrf: <?= json_encode(csrf_token(), JSON_UNESCAPED_UNICODE) ?>,
      ready: false
    };
  </script>
  <script src="data.js"></script>
  <script src="live.js"></script>
  <script src="app.js"></script>
  <script src="wizard.js"></script>
  <script src="board.js"></script>
</body>
</html>
