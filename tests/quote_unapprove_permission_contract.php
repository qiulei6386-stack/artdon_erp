<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$core = file_get_contents($root . '/includes/artdon_sso_core.php');
$api = file_get_contents($root . '/quote_api.php');
$page = file_get_contents($root . '/quotation.php');
$permissionsPage = file_get_contents($root . '/permissions.php');

$checks = [
    'central permission registry includes quote.unapprove' =>
        str_contains($core, "['quote.unapprove', 'quote', 'unapprove', '报价退审', 'dangerous']"),
    'central quote feature map exposes quote_unapprove separately' =>
        str_contains($core, "'quote_unapprove' => 'unapprove'")
        && str_contains($core, "'quote_unapprove' => \$perm('quote.unapprove')"),
    'review view is allowed by approve or unapprove permission' =>
        str_contains($core, "'quote_review_view' => \$perm('quote.approve') || \$perm('quote.unapprove') || \$admin")
        && str_contains($core, "\$feature === 'quote_review_view'")
        && str_contains($core, "artdon_sso_can('quote', 'approve', \$user) || artdon_sso_can('quote', 'unapprove', \$user)"),
    'permission center displays unapprove as Chinese退审' =>
        str_contains($permissionsPage, "'unapprove' => '退审'"),
    'legacy quote permission table and defaults include quote_unapprove' =>
        str_contains($api, "'quote_approve','quote_unapprove','quote_delete'")
        && str_contains($api, 'quote_unapprove TINYINT(1) NOT NULL DEFAULT 0')
        && str_contains($api, "'quote_unapprove'=>\"TINYINT(1) NOT NULL DEFAULT 0\""),
    'unapprove api action uses quote_unapprove instead of quote_approve' =>
        str_contains($api, "'unapprove_quote'=>'quote_unapprove'")
        && !str_contains($api, "'unapprove_quote'=>'quote_approve'"),
    'unapprove api has a dedicated server-side permission guard' =>
        str_contains($api, 'function quote_require_unapprover')
        && str_contains($api, "quote_require_unapprover(\$__quote_user,\$__quote_perms);"),
    'approval/reject api still uses approver guard' =>
        substr_count($api, "quote_require_approver(\$__quote_user,\$__quote_perms);") >= 2,
    'front-end reverse approval button uses quote_unapprove' =>
        str_contains($page, "hasPerm('quote_unapprove')")
        && str_contains($page, 'function quoteApprovalActionsHtml')
        && str_contains($page, '当前账号没有退审权限'),
    'front-end review modal keeps approve and unapprove actions separate' =>
        str_contains($page, 'function quoteReviewModalActionButtons')
        && str_contains($page, "return hasPerm('quote_unapprove')?")
        && str_contains($page, "return hasPerm('quote_approve')?"),
];

$failed = [];
foreach ($checks as $name => $ok) {
    if (!$ok) $failed[] = $name;
}
if ($failed) {
    fwrite(STDERR, "quote unapprove permission contract failed:\n- " . implode("\n- ", $failed) . "\n");
    exit(1);
}
echo "quote unapprove permission contract passed\n";
