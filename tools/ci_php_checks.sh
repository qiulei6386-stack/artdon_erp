#!/usr/bin/env bash
set -euo pipefail

script_dir="$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)"
repo_root="$(CDPATH= cd -- "$script_dir/.." && pwd)"
php_bin="${PHP_BIN:-php}"

cd "$repo_root"

if ! command -v "$php_bin" >/dev/null 2>&1 && [ ! -x "$php_bin" ]; then
  echo "PHP executable not found: $php_bin" >&2
  exit 1
fi

if ! git rev-parse --is-inside-work-tree >/dev/null 2>&1; then
  echo "PHP checks must run inside a Git worktree." >&2
  exit 1
fi

lint_total=0
lint_failed=0
while IFS= read -r php_file; do
  lint_total=$((lint_total + 1))
  if ! "$php_bin" -l "$php_file" >/dev/null 2>&1; then
    lint_failed=$((lint_failed + 1))
    printf 'PHP syntax failed: %s\n' "$php_file" >&2
  fi
done < <(git ls-files '*.php')

printf 'PHP syntax: %s checked, %s failed\n' "$lint_total" "$lint_failed"
if [ "$lint_total" -eq 0 ]; then
  echo "No tracked PHP files were found; refusing a false-positive check." >&2
  exit 1
fi
if [ "$lint_failed" -ne 0 ]; then
  exit 1
fi

contract_tests=(
  commercial_center_v1/tests/material_center_adaptation_contract.php
  commercial_center_v1/tests/quote_product_channel_contract.php
  commercial_center_v1/tests/quote_center_regression.php
  commercial_center_v1/tests/safety_scan.php
  commercial_center_v1/tests/standard_quote_button_contract.php
  tests/crm_marketing_pool_pagination_contract.php
  tests/crm_marketing_lazy_payload_contract.php
  tests/crm_marketing_wizard_flow_contract.php
  tests/crm_marketing_wizard_mail_preview_contract.php
  tests/crm_marketing_schedule_preview_contract.php
  tests/crm_country_phone_presets_contract.php
  tests/crm_quote_followup_history_actions_contract.php
  tests/crm_quote_followup_transaction_contract.php
  tests/dispatch_current_account_visibility_contract.php
  tests/dispatch_due_change_policy_contract.php
  tests/dispatch_multi_table_alignment_contract.php
  tests/quote_manual_component_repair_contract.php
  material_center_v1/tests/adaptation_batch_quick_rules_contract.php
  material_center_v1/tests/adaptation_quick_rule_discovery_contract.php
  material_center_v1/tests/adaptation_reuse_templates_contract.php
  material_center_v1/tests/adaptation_priority_layout_contract.php
  material_center_v1/tests/adaptation_option_workspace_and_discovery_contract.php
  material_center_v1/tests/adaptation_workbench_contract.php
  material_center_v1/tests/category_editor_drawer_contract.php
  material_center_v1/tests/dropdown_contract_test.php
  material_center_v1/tests/material_more_menu_contract.php
  material_center_v1/tests/master_spec_contract_test.php
  material_center_v1/tests/power_editor_contract_test.php
  material_center_v1/tests/power_ui_interaction_test.php
  material_center_v1/tests/power_ui_route_test.php
  material_center_v1/tests/power_ui_tab_test.php
  material_center_v1/tests/power_workbench_action_test.php
  material_center_v1/tests/power_workbench_route_test.php
  material_center_v1/tests/product_adaptation_workflow_contract.php
  material_center_v1/tests/red_primary_theme_contract.php
  material_center_v1/tests/route_mapping_v3_test.php
  material_center_v1/tests/source_material_organizer_contract.php
  material_center_v1/tests/unified_permission_contract_test.php
)

contract_passed=0
for test_file in "${contract_tests[@]}"; do
  printf 'PHP contract: %s\n' "$test_file"
  "$php_bin" "$test_file"
  contract_passed=$((contract_passed + 1))
done

printf 'PHP contracts: %s passed\n' "$contract_passed"
