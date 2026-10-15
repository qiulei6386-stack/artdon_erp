<?php
declare(strict_types=1);

/** 个人账号：查看和修改自己的资料、修改密码、查看最近登录记录。 */

require_once __DIR__ . '/bootstrap.php';

use Artdon\Centers\Base\Auth;
use Artdon\Centers\Base\Flash;
use Artdon\Centers\Base\Form;
use Artdon\Centers\Base\Http;
use Artdon\Centers\Base\Layout;
use Artdon\Centers\Base\Url;
use Artdon\Centers\Base\ValidationException;
use Artdon\Centers\System\ProfileService;

$user = Auth::requirePage();
$userId = (int) $user['id'];
$service = new ProfileService();
$self = Url::center('system', 'profile.php');

$profileErrors = [];
$passwordErrors = [];
$profileInput = null;

if (Http::isPost()) {
    $mode = (string) ($_POST['mode'] ?? '');
    if (!Http::csrfValid()) {
        Flash::set('error', '页面已过期，请重新提交。');
        Http::redirect($self);
    }
    if ($mode === 'profile') {
        try {
            $service->updateProfile($userId, $_POST);
            Flash::set('success', '资料已保存。');
            Http::redirect($self);
        } catch (ValidationException $e) {
            $profileErrors = $e->fieldErrors();
            $profileInput = $_POST;
        }
    } elseif ($mode === 'password') {
        try {
            $service->changePassword($userId, $_POST);
            logout_user();
            Http::redirect(Url::center('system', 'login.php') . '?changed=1');
        } catch (ValidationException $e) {
            $passwordErrors = $e->fieldErrors();
        }
    }
}

$profile = $service->profile($userId) ?? [];
$form = $profileInput ?? $profile;
$departments = $service->departments();
$currentDeptId = (int) ($profile['department_id'] ?? 0);
if ($currentDeptId !== 0 && !isset($departments[$currentDeptId])) {
    $departments[$currentDeptId] = (string) ($profile['department_name'] ?? ('部门 #' . $currentDeptId)) . '（已停用）';
}
$logins = $service->recentLogins($userId);
$mustChange = (int) ($profile['force_password_change'] ?? 0) === 1;

$statusLabels = [
    'active' => ['正常', 'badge-good'],
    'pending' => ['待审核', 'badge-warn'],
    'disabled' => ['已停用', 'badge-muted'],
    'rejected' => ['已拒绝', 'badge-bad'],
    'locked' => ['已锁定', 'badge-bad'],
];
$loginLabels = [
    'success' => ['登录成功', 'badge-good'],
    'fail' => ['登录失败', 'badge-bad'],
    'logout' => ['退出', ''],
];
$status = $statusLabels[(string) ($profile['status'] ?? '')] ?? [(string) ($profile['status'] ?? '—'), ''];

Layout::start([
    'title' => '个人账号',
    'nav' => 'profile',
    'crumbs' => ['系统' => null, '个人账号' => null],
]);
?>
<div class="page-head">
  <div>
    <h1 class="page-title">个人账号</h1>
    <p class="page-sub">修改自己的资料和密码。角色和权限由管理员在权限中心分配。</p>
  </div>
</div>

<?php if ($mustChange): ?>
  <div class="alert alert-warning" role="alert">你的密码是管理员设置的初始密码或重置后的密码，请先<a href="#password">修改为自己的密码</a>。</div>
<?php endif; ?>
<?php if ($profileErrors || $passwordErrors): ?>
  <div class="alert alert-error" role="alert">有内容需要修改，请看输入框下方的提示。</div>
<?php endif; ?>

<div class="cols">
  <div class="col-main">
    <section class="card" aria-labelledby="h-profile">
      <div class="card-head"><h2 class="card-title" id="h-profile">基本资料</h2></div>
      <form method="post" action="<?= Layout::e($self) ?>">
        <?= Layout::csrfField() ?>
        <input type="hidden" name="mode" value="profile">
        <div class="card-body form-grid">
          <?= Form::input('username', '账号', (string) ($form['username'] ?? ''), $profileErrors, ['required' => true, 'autocomplete' => 'username', 'maxlength' => 80, 'hint' => '用于登录，只能使用字母、数字、点、下划线或横线']) ?>
          <?= Form::input('real_name', '姓名', (string) ($form['real_name'] ?? ''), $profileErrors, ['required' => true, 'autocomplete' => 'name', 'maxlength' => 120]) ?>
          <?= Form::input('english_name', '英文名', (string) ($form['english_name'] ?? ''), $profileErrors, ['maxlength' => 120]) ?>
          <?= Form::input('email', '邮箱', (string) ($form['email'] ?? ''), $profileErrors, ['type' => 'email', 'autocomplete' => 'email', 'maxlength' => 190]) ?>
          <?= Form::input('phone', '手机 / 电话', (string) ($form['phone'] ?? ''), $profileErrors, ['type' => 'tel', 'autocomplete' => 'tel', 'maxlength' => 60]) ?>
          <?= Form::input('position', '职位', (string) ($form['position'] ?? ''), $profileErrors, ['maxlength' => 120]) ?>
          <?= Form::select('department_id', '部门', $departments, (string) ($form['department_id'] ?? ''), $profileErrors, ['placeholder' => '未分配']) ?>
          <?= Form::readonly('角色', (string) ($profile['role_name'] ?? ''), '角色由管理员分配') ?>
        </div>
        <div class="card-foot"><button type="submit" class="btn btn-primary">保存资料</button></div>
      </form>
    </section>

    <section class="card" id="password" aria-labelledby="h-password">
      <div class="card-head"><h2 class="card-title" id="h-password">修改密码</h2></div>
      <form method="post" action="<?= Layout::e($self) ?>#password">
        <?= Layout::csrfField() ?>
        <input type="hidden" name="mode" value="password">
        <input type="text" name="username" value="<?= Layout::e((string) ($profile['username'] ?? '')) ?>" autocomplete="username" class="sr-only" tabindex="-1" aria-hidden="true">
        <div class="card-body form-grid">
          <div class="span-2"><?= Form::input('old_password', '当前密码', '', $passwordErrors, ['type' => 'password', 'required' => true, 'autocomplete' => 'current-password']) ?></div>
          <?= Form::input('new_password', '新密码', '', $passwordErrors, ['type' => 'password', 'required' => true, 'autocomplete' => 'new-password', 'minlength' => 8, 'hint' => ProfileService::strengthMessage()]) ?>
          <?= Form::input('confirm_password', '确认新密码', '', $passwordErrors, ['type' => 'password', 'required' => true, 'autocomplete' => 'new-password', 'minlength' => 8]) ?>
        </div>
        <div class="card-foot"><span class="muted" style="margin-right:auto">修改后需要用新密码重新登录</span><button type="submit" class="btn btn-primary">修改密码</button></div>
      </form>
    </section>
  </div>

  <div class="col-side">
    <section class="card" aria-labelledby="h-account">
      <div class="card-head"><h2 class="card-title" id="h-account">账号信息</h2></div>
      <div class="card-body">
        <dl class="dl">
          <dt>账号</dt><dd class="mono"><?= Layout::e((string) ($profile['username'] ?? '')) ?></dd>
          <dt>状态</dt><dd><span class="badge <?= Layout::e($status[1]) ?>"><?= Layout::e($status[0]) ?></span><?php if ((int) ($profile['is_super_admin'] ?? 0) === 1): ?> <span class="badge badge-brand">超级管理员</span><?php endif; ?></dd>
          <dt>角色</dt><dd><?= Layout::e((string) ($profile['role_name'] ?? '') ?: '未分配') ?></dd>
          <dt>部门</dt><dd><?= Layout::e((string) ($profile['department_name'] ?? '') ?: '未分配') ?></dd>
          <dt>最近登录</dt><dd class="mono"><?= Layout::e((string) ($profile['last_login_at'] ?? '') ?: '—') ?></dd>
          <dt>登录 IP</dt><dd class="mono"><?= Layout::e((string) ($profile['last_login_ip'] ?? '') ?: '—') ?></dd>
          <dt>创建时间</dt><dd class="mono"><?= Layout::e((string) ($profile['created_at'] ?? '') ?: '—') ?></dd>
        </dl>
      </div>
    </section>

    <section class="card" aria-labelledby="h-logins">
      <div class="card-head"><h2 class="card-title" id="h-logins">最近登录记录</h2></div>
      <?php if (!$logins): ?>
        <div class="card-body muted">暂无记录</div>
      <?php else: ?>
        <div class="table-wrap">
          <table class="table">
            <thead><tr><th scope="col">时间</th><th scope="col">结果</th><th scope="col">IP</th></tr></thead>
            <tbody>
            <?php foreach ($logins as $log): $label = $loginLabels[(string) $log['status']] ?? [(string) $log['status'], '']; ?>
              <tr>
                <td class="mono"><?= Layout::e(substr((string) $log['created_at'], 5, 11)) ?></td>
                <td><span class="badge <?= Layout::e($label[1]) ?>" title="<?= Layout::e((string) ($log['message'] ?? '')) ?>"><?= Layout::e($label[0]) ?></span></td>
                <td class="mono"><?= Layout::e((string) ($log['ip'] ?? '')) ?></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </section>
  </div>
</div>
<?php
Layout::end();
