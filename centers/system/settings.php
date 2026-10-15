<?php
declare(strict_types=1);

/** 设置中心：公司信息与 Logo、安全设置、系统信息。 */

require_once __DIR__ . '/bootstrap.php';

use Artdon\Centers\Base\Auth;
use Artdon\Centers\Base\Form;
use Artdon\Centers\Base\Layout;
use Artdon\Centers\Base\SystemSettings;
use Artdon\Centers\Base\Url;
use Artdon\Centers\System\SettingsService;

$user = Auth::requirePage();
Auth::requirePermissionPage('settings.view');
$service = new SettingsService($user);
$canEdit = $service->canEdit();
$api = Url::center('system', 'api.php');
$self = Url::center('system', 'settings.php');
$tabs = ['company' => '公司信息', 'security' => '安全设置', 'system' => '系统信息'];
$tab = (string) ($_GET['tab'] ?? 'company');
if (!isset($tabs[$tab])) {
    $tab = 'company';
}
$ro = $canEdit ? [] : ['readonly' => true];

Layout::start([
    'title' => '设置中心',
    'nav' => 'settings',
    'crumbs' => ['系统' => null, '设置中心' => null],
    'scripts' => [Url::centerAsset('system', 'settings.js')],
]);
?>
<div class="page-head">
  <div>
    <h1 class="page-title">设置中心</h1>
    <p class="page-sub"><?= $canEdit ? '修改后马上生效。' : '只读：修改设置需要“编辑设置”（settings.edit）权限。' ?></p>
  </div>
</div>
<nav class="tabs" aria-label="设置分类">
  <?php foreach ($tabs as $key => $label): $on = $key === $tab; ?>
    <a class="tab<?= $on ? ' is-active' : '' ?>" href="<?= Layout::e($self . '?tab=' . $key) ?>"<?= $on ? ' aria-current="page"' : '' ?>><?= Layout::e($label) ?></a>
  <?php endforeach; ?>
  <?php if (Auth::can('logs.view_own') || Auth::can('logs.view_all')): ?><a class="tab" href="<?= Layout::e(Url::center('system', 'logs.php')) ?>">日志</a><?php endif; ?>
</nav>

<?php if ($tab === 'company'): $company = $service->company(); $logo = SystemSettings::logo(); ?>
<div class="cols">
  <section class="card col-main">
    <form data-api action="<?= Layout::e($api) ?>?action=settings_company_save">
      <div class="card-head"><h2 class="card-title">公司信息</h2></div>
      <div class="card-body form-grid">
        <p class="alert alert-error form-error span-2" data-form-error hidden></p>
        <?php foreach (SettingsService::COMPANY_FIELDS as $key => [$label, $max, $required]): ?>
          <?= Form::input($key, $label, $company[$key], [], $ro + ['required' => $required, 'maxlength' => $max, 'type' => $key === 'company_email' ? 'email' : 'text', 'hint' => $key === 'system_name' ? '显示在登录页、侧栏和浏览器标题' : '']) ?>
        <?php endforeach; ?>
      </div>
      <?php if ($canEdit): ?><div class="card-foot"><button type="submit" class="btn btn-primary">保存公司信息</button></div><?php endif; ?>
    </form>
  </section>
  <section class="card col-side">
    <div class="card-head"><h2 class="card-title">Logo</h2></div>
    <div class="card-body stack">
      <div class="logo-preview" data-logo-preview>
        <?php if ($logo !== ''): ?><img src="<?= Layout::e($logo) ?>" alt="当前 Logo"><?php else: ?><span class="side-mark side-mark-lg" aria-hidden="true">A</span><span class="muted">还没有上传 Logo，显示默认字母</span><?php endif; ?>
      </div>
      <p class="muted" style="margin:0">显示在登录页和左侧栏。PNG、JPG 或 WebP，不超过 <?= (int) (SettingsService::LOGO_MAX_BYTES / 1000) ?>KB，建议正方形、透明背景。</p>
      <?php if ($canEdit): ?>
        <form data-api action="<?= Layout::e($api) ?>?action=settings_logo_save" class="stack" data-logo-form>
          <p class="alert alert-error form-error" data-form-error hidden></p>
          <input type="hidden" name="logo" data-logo-data>
          <div class="field"><label class="field-label" for="logo-file">选择图片</label><input class="input" id="logo-file" type="file" accept="image/png,image/jpeg,image/webp" data-logo-file data-max="<?= (int) SettingsService::LOGO_MAX_BYTES ?>" style="padding-top:5px"></div>
          <button type="submit" class="btn btn-primary" data-logo-submit disabled>上传 Logo</button>
        </form>
        <?php if ($logo !== ''): ?>
          <form data-api action="<?= Layout::e($api) ?>?action=settings_logo_remove"><button type="submit" class="btn btn-ghost">去掉 Logo，用默认字母</button></form>
        <?php endif; ?>
      <?php endif; ?>
    </div>
  </section>
</div>
<?php endif; ?>

<?php if ($tab === 'security'): $sec = SystemSettings::security(); ?>
<section class="card" style="max-width: 760px">
  <form data-api action="<?= Layout::e($api) ?>?action=settings_security_save">
    <div class="card-head"><h2 class="card-title">安全设置</h2></div>
    <div class="card-body form-grid">
      <p class="alert alert-error form-error span-2" data-form-error hidden></p>
      <?= Form::input('login_max_failures', '连续输错几次密码后锁定', (string) $sec['login_max_failures'], [], $ro + ['required' => true, 'inputmode' => 'numeric', 'hint' => '1 到 20 次']) ?>
      <?= Form::input('login_lock_minutes', '锁定多少分钟', (string) $sec['login_lock_minutes'], [], $ro + ['required' => true, 'inputmode' => 'numeric', 'hint' => '1 到 1440 分钟。管理员也可以在账号管理里提前解锁']) ?>
      <?= Form::input('password_min_length', '密码最短长度', (string) $sec['password_min_length'], [], $ro + ['required' => true, 'inputmode' => 'numeric', 'hint' => '8 到 32 位，同时必须包含字母和数字。只对之后设置的新密码生效']) ?>
      <?= Form::input('session_idle_minutes', '多久不操作自动退出（分钟）', (string) $sec['session_idle_minutes'], [], $ro + ['inputmode' => 'numeric', 'hint' => '填 0 表示不自动退出（关闭浏览器后退出）；或 10 到 1440 分钟']) ?>
    </div>
    <?php if ($canEdit): ?><div class="card-foot"><button type="submit" class="btn btn-primary">保存安全设置</button></div><?php endif; ?>
  </form>
</section>
<p class="muted">这些设置只作用于新系统，旧系统不受影响。</p>
<?php endif; ?>

<?php if ($tab === 'system'): ?>
<section class="card" style="max-width: 760px">
  <div class="card-head"><h2 class="card-title">系统信息</h2></div>
  <div class="card-body">
    <dl class="dl" style="grid-template-columns: 110px minmax(0, 1fr)">
      <?php foreach ($service->systemInfo() as $label => $value): ?>
        <dt><?= Layout::e($label) ?></dt><dd><?= Layout::e($value) ?></dd>
      <?php endforeach; ?>
    </dl>
  </div>
</section>
<?php endif; ?>
<?php
Layout::end();
