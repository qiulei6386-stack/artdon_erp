#!/usr/bin/env bash
# One-time release from the verified 2026-09-06 live baseline. No DB operations.
set -euo pipefail
task_root=/www/wwwroot/Artdon/artdon_erp
if [ -n "${CRM_RELEASE_REHEARSAL_ROOT:-}" ]; then
  case "$CRM_RELEASE_REHEARSAL_ROOT" in /tmp/crm-release-rehearsal-*/repo) task_root="$CRM_RELEASE_REHEARSAL_ROOT";; *) exit 2;; esac
fi
cd "$task_root"
task_target="${1:?Pass the exact already-pushed commit}"
[[ "$task_target" =~ ^[a-f0-9]{40}$ ]] || exit 2
task_old=a3e59891e2337b445e8ed5a0e0a0a0d4c6e1c441
test "$(git rev-parse HEAD)" = "$task_old"
test "$(git rev-parse origin/main)" = "$task_target"
git merge-base --is-ancestor "$task_old" "$task_target"
git diff --cached --quiet
test -z "$(git diff --name-only --diff-filter=U)"
task_scope=(); task_existing=(); task_protected=()
declare -A task_in_scope=()
while IFS= read -r -d '' task_path; do
  case "$task_path" in
    WORK_CONTEXT.md|crm*.php|dispatch_next.php|assets/crm/*|assets/dispatch/recent-create.js|docs/crm-*.md|docs/dispatch-new-gradient.md|tests/crm_*|tests/dispatch_*|tests/fixtures/crm-phase1-mysql-marker.txt|tools/ci_js_checks.sh|tools/ci_php_checks.sh|tools/crm_phase1_*|tools/deploy_crm_unified_release.sh|commercial_center_v1/tests/quote_product_channel_contract.php|material_center_v1/tests/adaptation_active_route_contract.php|material_center_v1/tests/adaptation_batch_quick_rules_contract.php|material_center_v1/tests/adaptation_quick_rule_discovery_contract.php|material_center_v1/tests/adaptation_reuse_templates_contract.php|material_center_v1/tests/adaptation_workbench_contract.php|material_center_v1/tests/route_mapping_v3_test.php) ;;
    *) echo "Refusing out-of-scope change: $task_path" >&2; exit 2;;
  esac
  task_scope+=("$task_path"); task_in_scope["$task_path"]=1
  task_baseline="$task_old"
  case "$task_path" in
    crm.php|assets/crm/crm.js|crm_api.php|crm_task_center.php|crm_marketing.php) task_baseline=ad5c8ea2ab5d8411225e94b3cedc5e21de001f3d;;
    assets/crm/crm.css|crm_ai.php|crm_customer.php|crm_visit.php|crm_settings_config.php|tests/crm_visit_image_upload_delete_contract.php|tests/crm_combined_shipment_flow_contract.php|tests/crm_visit_dedup_status_contract.php) task_baseline=161c6c6;;
  esac
  if git cat-file -e "$task_baseline:$task_path" 2>/dev/null; then
    test -f "$task_path" && test ! -L "$task_path"
    test "$(git hash-object "$task_path")" = "$(git rev-parse "$task_baseline:$task_path")" || { echo "Live file changed since verified baseline: $task_path" >&2; exit 2; }
    task_existing+=("$task_path")
  else
    test ! -e "$task_path" || { echo "Unexpected live file: $task_path" >&2; exit 2; }
  fi
done < <(git diff --name-only -z "$task_old" "$task_target")
test "${#task_scope[@]}" -gt 20
while IFS= read -r -d '' task_path; do
  if [ -z "${task_in_scope[$task_path]:-}" ]; then task_protected+=("$task_path"); fi
done < <({ git diff --name-only -z; git ls-files --others --exclude-standard -z; })
task_backup=$(mktemp -d /tmp/artdon-crm-release-20260906-XXXXXXXX)
chmod 700 "$task_backup"
tar -czf "$task_backup/before-files.tar.gz" -- "${task_existing[@]}"
if [ "${#task_protected[@]}" -gt 0 ]; then sha256sum -- "${task_protected[@]}" > "$task_backup/protected.sha256"; fi
printf '%s\0' "${task_scope[@]}" > "$task_backup/released-paths.nul"
git status --porcelain=v1 > "$task_backup/before-status.txt"
printf 'Recoverable backup: %s\n' "$task_backup"
git stash push --include-untracked -m "crm-unified-before-${task_target:0:12}" -- "${task_existing[@]}"
task_stash=$(git rev-parse stash@{0})
printf '%s\n' "$task_stash" > "$task_backup/stash.txt"
printf 'Retained scoped stash: %s\n' "$task_stash"
# Unrelated dirty files remain untouched and are not part of the release.
if ! git merge --ff-only "$task_target"; then
  echo "Fast-forward refused. Restoring retained scoped changes without dropping backup." >&2
  git stash apply "$task_stash"
  exit 1
fi
test "$(git rev-parse HEAD)" = "$task_target"
for task_path in "${task_scope[@]}"; do
  test "$(git hash-object "$task_path")" = "$(git rev-parse "$task_target:$task_path")" || { echo "Release file differs: $task_path" >&2; exit 1; }
done
if [ -f "$task_backup/protected.sha256" ]; then sha256sum --check --status "$task_backup/protected.sha256"; fi
printf 'Release verified: %s; released files: %s; unchanged protected files: %s; backup: %s\n' "$task_target" "${#task_scope[@]}" "${#task_protected[@]}" "$task_backup"
git status --short
