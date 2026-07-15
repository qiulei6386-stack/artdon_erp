<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/user_admin_service.php';
require_login();
require_permission('users.view');
ensure_user_create_permission();
ensure_user_profile_schema();
ensure_default_departments();

$message = $error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = $_POST['action'] ?? '';
    $userId = (int)($_POST['user_id'] ?? 0);
    $target = null;
    if ($userId) {
        $stmt = db()->prepare('SELECT * FROM crm_users WHERE id = ?');
        $stmt->execute([$userId]);
        $target = $stmt->fetch();
    }
    try {
        if ($action === 'create_user') {
            admin_create_user($_POST);
            $message = '账号已创建，首次登录将要求修改密码。';
        } else {
        if (!$target) throw new RuntimeException('用户不存在。');
        if ((int)$target['is_super_admin'] === 1 && !is_super_admin()) throw new RuntimeException('普通管理员不可管理 super_admin。');
        $operator = current_user();
        $before = $target;
        if ($action === 'approve') {
            require_permission('users.approve');
            $deptId = user_admin_resolve_department_id($_POST);
            $roleId = (int)($_POST['role_id'] ?? 0);
            $position = trim((string)($_POST['position'] ?? ($target['position'] ?? '')));
            if (!$deptId || !$roleId) throw new RuntimeException('审核必须分配部门和角色。');
            db()->prepare("UPDATE crm_users SET status = 'active', department_id = ?, role_id = ?, position = ?, approved_at = NOW(), approved_by = ?, updated_at = NOW() WHERE id = ?")->execute([$deptId, $roleId, $position, $operator['id'], $userId]);
            $logAction = 'approve';
        } elseif ($action === 'reject') {
            require_permission('users.reject');
            $reason = trim($_POST['reason'] ?? '');
            db()->prepare("UPDATE crm_users SET status = 'rejected', rejected_at = NOW(), rejected_by = ?, reject_reason = ?, updated_at = NOW() WHERE id = ?")->execute([$operator['id'], $reason, $userId]);
            $logAction = 'reject';
        } elseif ($action === 'disable') {
            require_permission('users.disable');
            db()->prepare("UPDATE crm_users SET status = 'disabled', updated_at = NOW() WHERE id = ?")->execute([$userId]);
            $logAction = 'disable';
        } elseif ($action === 'enable') {
            require_permission('users.enable');
            db()->prepare("UPDATE crm_users SET status = 'active', updated_at = NOW() WHERE id = ?")->execute([$userId]);
            $logAction = 'enable';
        } elseif ($action === 'reset_password') {
            require_permission('users.reset_password');
            $new = $_POST['new_password'] ?: bin2hex(random_bytes(6));
            if (!password_is_strong($new)) throw new RuntimeException(password_strength_message());
            db()->prepare('UPDATE crm_users SET password_hash = ?, force_password_change = 1, updated_at = NOW() WHERE id = ?')->execute([password_hash($new, PASSWORD_DEFAULT), $userId]);
            $logAction = 'reset_password';
            $message = '密码已重置：' . $new;
        } elseif ($action === 'assign_profile') {
            require_permission('users.assign_role');
            require_permission('users.assign_department');
            $deptId = user_admin_resolve_department_id($_POST);
            $roleId = (int)($_POST['role_id'] ?? 0);
            $position = trim((string)($_POST['position'] ?? ($target['position'] ?? '')));
            if (!$deptId || !$roleId) throw new RuntimeException('必须选择部门和角色。');
            db()->prepare('UPDATE crm_users SET department_id = ?, role_id = ?, position = ?, updated_at = NOW() WHERE id = ?')->execute([$deptId, $roleId, $position, $userId]);
            $logAction = 'assign_profile';
        } else {
            throw new RuntimeException('未知操作。');
        }
        $stmt = db()->prepare('SELECT * FROM crm_users WHERE id = ?');
        $stmt->execute([$userId]);
        $after = $stmt->fetch();
        if (in_array($logAction, ['approve', 'reject', 'disable', 'enable'], true)) {
            db()->prepare('INSERT INTO crm_user_approval_logs (user_id, action, operator_id, operator_name, reason, before_json, after_json, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())')
                ->execute([$userId, $logAction, $operator['id'], $operator['username'], $_POST['reason'] ?? '', json_encode($before, JSON_UNESCAPED_UNICODE), json_encode($after, JSON_UNESCAPED_UNICODE)]);
        }
        audit_log('user_' . $logAction, 'users', 'user', $userId, $before, $after);
        if (!$message) $message = '操作成功。';
        }
    } catch (Throwable $e) {
        $error = ($e instanceof PDOException && $e->getCode() === '23000') ? '用户名或邮箱已存在。' : $e->getMessage();
    }
}

$status = $_GET['status'] ?? '';
$where = $status ? 'WHERE u.status = ?' : '';
$stmt = db()->prepare("SELECT u.*, r.role_name, d.name AS department_name FROM crm_users u LEFT JOIN crm_roles r ON r.id = u.role_id LEFT JOIN crm_departments d ON d.id = u.department_id {$where} ORDER BY u.id DESC LIMIT 200");
$stmt->execute($status ? [$status] : []);
$users = $stmt->fetchAll();
$roles = db()->query("SELECT id, role_name, role_key FROM crm_roles WHERE status = 'active' ORDER BY id")->fetchAll();
$departments = db()->query("SELECT id, name, code FROM crm_departments WHERE status = 'active' ORDER BY sort_order, id")->fetchAll();
require_once __DIR__ . '/includes/layout.php';
page_header('用户管理', ['ui_type' => 'permission', 'page' => 'users']);
if ($message) flash($message, 'success');
if ($error) flash($error, 'error');
?>
<?php if (has_permission('users.create')): ?>
<section class="user-create-panel">
  <div>
    <span>User Create</span>
    <h2>新增账号</h2>
    <p>由管理员创建的账号直接进入正常状态，首次登录必须修改初始密码。</p>
  </div>
  <form method="post" class="user-create-form">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="create_user">
    <label>用户名<input name="username" required autocomplete="off" placeholder="例如 qiulei"></label>
    <label>姓名<input name="real_name" required placeholder="中文姓名"></label>
    <label>英文名<input name="english_name" placeholder="English name"></label>
    <label>邮箱<input type="email" name="email" required placeholder="name@company.com"></label>
    <label>手机<input name="phone" placeholder="手机号"></label>
    <label>部门<input name="department_name" list="department-options" required data-department-input placeholder="可选择，也可直接输入新部门"></label>
    <label>职位<input name="position" placeholder="例如 业务主管 / 工程师 / 采购"></label>
    <label>角色
      <select name="role_id" required>
        <option value="">选择角色</option>
        <?php foreach ($roles as $r): ?><option value="<?= h($r['id']) ?>"><?= h($r['role_name']) ?></option><?php endforeach; ?>
      </select>
    </label>
    <label>初始密码<input type="password" name="password" required autocomplete="new-password" placeholder="至少8位，含字母和数字"></label>
    <button type="submit">创建账号</button>
  </form>
</section>
<?php endif; ?>
<form method="get" class="toolbar">
  <label>状态筛选
    <select name="status" onchange="this.form.submit()">
      <option value="">全部</option>
      <?php foreach (['pending'=>'待审核','active'=>'正常','disabled'=>'禁用','rejected'=>'驳回'] as $k => $v): ?>
      <option value="<?= h($k) ?>" <?= $status === $k ? 'selected' : '' ?>><?= h($v) ?></option>
      <?php endforeach; ?>
    </select>
  </label>
</form>
<table class="data-table">
  <thead><tr><th>ID</th><th>账号</th><th>姓名</th><th>邮箱</th><th>状态</th><th>部门</th><th>职位</th><th>角色</th><th>操作</th></tr></thead>
  <tbody>
  <?php foreach ($users as $u): ?>
    <tr>
      <td><?= h($u['id']) ?></td><td><?= h($u['username']) ?></td><td><?= h($u['real_name']) ?></td><td><?= h($u['email']) ?></td><td><?= h($u['status']) ?></td><td><?= h($u['department_name']) ?></td><td><?= h($u['position'] ?? '') ?></td><td><?= h($u['role_name']) ?></td>
      <td class="actions">
        <?php if ($u['status'] === 'pending'): ?>
        <form method="post" class="inline-form"><?= csrf_field() ?><input type="hidden" name="action" value="approve"><input type="hidden" name="user_id" value="<?= h($u['id']) ?>">
          <input name="department_name" list="department-options" required value="<?= h($u['department_name'] ?? '') ?>" placeholder="部门">
          <input name="position" value="<?= h($u['position'] ?? '') ?>" placeholder="职位">
          <select name="role_id" required><?php foreach ($roles as $r): ?><option value="<?= h($r['id']) ?>"><?= h($r['role_name']) ?></option><?php endforeach; ?></select>
          <button>审核通过</button>
        </form>
        <form method="post" class="inline-form"><?= csrf_field() ?><input type="hidden" name="action" value="reject"><input type="hidden" name="user_id" value="<?= h($u['id']) ?>"><input name="reason" placeholder="驳回原因"><button class="danger">驳回</button></form>
        <?php endif; ?>
        <?php if ($u['status'] === 'active'): ?>
        <form method="post" class="inline-form"><?= csrf_field() ?><input type="hidden" name="action" value="disable"><input type="hidden" name="user_id" value="<?= h($u['id']) ?>"><button class="danger">禁用</button></form>
        <form method="post" class="inline-form"><?= csrf_field() ?><input type="hidden" name="action" value="assign_profile"><input type="hidden" name="user_id" value="<?= h($u['id']) ?>">
          <input name="department_name" list="department-options" required value="<?= h($u['department_name'] ?? '') ?>" placeholder="部门">
          <input name="position" value="<?= h($u['position'] ?? '') ?>" placeholder="职位">
          <select name="role_id" required><?php foreach ($roles as $r): ?><option value="<?= h($r['id']) ?>" <?= (int)$u['role_id'] === (int)$r['id'] ? 'selected' : '' ?>><?= h($r['role_name']) ?></option><?php endforeach; ?></select>
          <button>分配</button>
        </form>
        <?php elseif ($u['status'] === 'disabled'): ?>
        <form method="post" class="inline-form"><?= csrf_field() ?><input type="hidden" name="action" value="enable"><input type="hidden" name="user_id" value="<?= h($u['id']) ?>"><button>启用</button></form>
        <?php endif; ?>
        <form method="post" class="inline-form"><?= csrf_field() ?><input type="hidden" name="action" value="reset_password"><input type="hidden" name="user_id" value="<?= h($u['id']) ?>"><input name="new_password" placeholder="新密码"><button>重置密码</button></form>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
<datalist id="department-options">
  <?php foreach ($departments as $d): ?>
  <option value="<?= h($d['name']) ?>"></option>
  <?php endforeach; ?>
</datalist>
<?php page_footer(); ?>
