#!/usr/bin/env bash
set -euo pipefail

script_dir="$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)"
repo_root="$(CDPATH= cd -- "$script_dir/.." && pwd)"
node_bin="${NODE_BIN:-node}"

cd "$repo_root"

if ! command -v "$node_bin" >/dev/null 2>&1; then
  echo "Node.js executable not found: $node_bin" >&2
  exit 1
fi

if ! git rev-parse --is-inside-work-tree >/dev/null 2>&1; then
  echo "JavaScript checks must run inside a Git worktree." >&2
  exit 1
fi

syntax_total=0
syntax_failed=0
while IFS= read -r js_file; do
  syntax_total=$((syntax_total + 1))
  if ! "$node_bin" --check "$js_file" >/dev/null 2>&1; then
    syntax_failed=$((syntax_failed + 1))
    printf 'JavaScript syntax failed: %s\n' "$js_file" >&2
  fi
done < <(git ls-files '*.js')

printf 'JavaScript syntax: %s checked, %s failed\n' "$syntax_total" "$syntax_failed"
if [ "$syntax_total" -eq 0 ]; then
  echo "No tracked JavaScript files were found; refusing a false-positive check." >&2
  exit 1
fi
if [ "$syntax_failed" -ne 0 ]; then
  exit 1
fi

static_tests=(
  tests/crm_marketing_mail_preview_runtime_test.js
  material_center_v1/tests/mm_static_test.js
  material_center_v1/tests/ui_static_test.js
  material_center_v1/tests/ui_contract_test.js
)

static_passed=0
for test_file in "${static_tests[@]}"; do
  printf 'JavaScript contract: %s\n' "$test_file"
  "$node_bin" "$test_file"
  static_passed=$((static_passed + 1))
done

printf 'JavaScript contracts: %s passed\n' "$static_passed"
