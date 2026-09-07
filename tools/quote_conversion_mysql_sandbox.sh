#!/usr/bin/env bash
# Opt-in, disposable MySQL 5.7 instance. Never reads application configuration.
set -euo pipefail

if [ "${CRM_PHASE1_SANDBOX_RUN:-}" != "1" ]; then
  echo 'Refusing to start: set CRM_PHASE1_SANDBOX_RUN=1 for this isolated test only.' >&2
  exit 2
fi
script_dir="$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)"
repo_root="$(CDPATH= cd -- "$script_dir/.." && pwd)"
mysqld_bin="${CRM_PHASE1_MYSQLD_BIN:-/www/server/mysql/bin/mysqld}"
mysqladmin_bin="${CRM_PHASE1_MYSQLADMIN_BIN:-/www/server/mysql/bin/mysqladmin}"
mysql_bin="${CRM_PHASE1_MYSQL_BIN:-/www/server/mysql/bin/mysql}"
php_bin="${CRM_PHASE1_PHP_BIN:-/usr/bin/php}"
for binary in "$mysqld_bin" "$mysqladmin_bin" "$mysql_bin" "$php_bin"; do
  [ -x "$binary" ] || { echo "Missing test dependency: $binary" >&2; exit 2; }
done
command -v timeout >/dev/null
command -v openssl >/dev/null
id mysql >/dev/null
[ "$(id -u)" = 0 ] || { echo 'This runner requires permission to start the sandbox as the existing mysql OS user.' >&2; exit 2; }
mysql_version="$("$mysqld_bin" --no-defaults --version)"
case "$mysql_version" in *'Ver 5.7.'*) ;; *) echo 'This bounded runner is verified for MySQL 5.7 only.' >&2; exit 2;; esac
available_kb="$(awk '/MemAvailable:/ {print $2}' /proc/meminfo)"
[ "${available_kb:-0}" -ge 524288 ] || { echo 'Not enough available memory for isolated database tests.' >&2; exit 2; }
disk_kb="$(df -Pk /tmp | awk 'NR==2 {print $4}')"
[ "${disk_kb:-0}" -ge 1048576 ] || { echo 'Not enough temporary disk space.' >&2; exit 2; }

php_args=(-n -d extension=mysqlnd -d extension=pdo -d extension=pdo_mysql)
"$php_bin" "${php_args[@]}" -r 'exit(extension_loaded("pdo_mysql") && function_exists("pcntl_fork") && function_exists("proc_open") ? 0 : 2);'
for test in quote_conversion_mysql.php; do
  [ -f "$repo_root/tests/$test" ] || { echo "Missing isolated test: $test" >&2; exit 2; }
  "$php_bin" "${php_args[@]}" -l "$repo_root/tests/$test"
done

sandbox_dir="$(mktemp -d /tmp/crm-phase1-mysql-20260906-XXXXXXXX)"
case "$sandbox_dir" in /tmp/crm-phase1-mysql-20260906-????????) ;; *) echo 'Unexpected sandbox path.' >&2; exit 2;; esac
mkdir "$sandbox_dir/data"
install -m 600 "$repo_root/tests/fixtures/crm-phase1-mysql-marker.txt" "$sandbox_dir/.crm-phase1-mysql"
chown mysql:mysql "$sandbox_dir" "$sandbox_dir/data"
socket_path="$sandbox_dir/mysql.sock"
cleanup() {
  local result=$?
  trap - EXIT INT TERM
  if [ -S "$socket_path" ]; then
    local cleanup_identity expected_identity
    expected_identity="$(printf '%s\t%s\t1' "$sandbox_dir/data/" "$socket_path")"
    if ! cleanup_identity="$(timeout 5 "$mysql_bin" --no-defaults --protocol=SOCKET --socket="$socket_path" --user=root --batch --skip-column-names -e 'SELECT @@datadir, @@socket, @@skip_networking')" || [ "$cleanup_identity" != "$expected_identity" ]; then
      echo "Refusing to stop an unverified instance; inspect this exact sandbox: $sandbox_dir" >&2
      result=1
    elif timeout 30 "$mysqladmin_bin" --no-defaults --protocol=SOCKET --socket="$socket_path" --user=root shutdown; then
      echo "Sandbox database stopped; retained test artifacts: $sandbox_dir"
    else
      echo "Sandbox shutdown needs attention: $sandbox_dir (no broad process kill attempted)" >&2
      result=1
    fi
  elif [ -f "$sandbox_dir/mysql.pid" ]; then
    echo "Sandbox PID file remains without a socket; inspect this exact instance: $sandbox_dir" >&2
    result=1
  else
    echo "No sandbox listener remains; retained test artifacts: $sandbox_dir"
  fi
  exit "$result"
}
trap cleanup EXIT
trap 'exit 130' INT TERM
mysql_options=(--no-defaults --user=mysql --datadir="$sandbox_dir/data" --socket="$socket_path"
  --pid-file="$sandbox_dir/mysql.pid" --log-error="$sandbox_dir/mysql.log"
  --skip-networking --skip-log-bin --performance-schema=OFF --max-allowed-packet=64M
  --innodb-buffer-pool-size=64M --innodb-log-file-size=8M --innodb-log-files-in-group=2
  --innodb-read-io-threads=1 --innodb-write-io-threads=1 --max-connections=12 --table-open-cache=64)
echo "Initializing isolated database: $sandbox_dir"
timeout 45 "$mysqld_bin" "${mysql_options[@]}" --initialize-insecure
timeout 30 "$mysqld_bin" "${mysql_options[@]}" --daemonize
timeout 15 "$mysqladmin_bin" --no-defaults --protocol=SOCKET --socket="$socket_path" --user=root ping
actual="$("$mysql_bin" --no-defaults --protocol=SOCKET --socket="$socket_path" --user=root --batch --skip-column-names -e 'SELECT @@datadir, @@socket, @@skip_networking')"
expected="$(printf '%s\t%s\t1' "$sandbox_dir/data/" "$socket_path")"
[ "$actual" = "$expected" ] || { echo 'Instance identity mismatch; refusing schema creation.' >&2; exit 2; }
suffix="$(openssl rand -hex 6)"
[[ "$suffix" =~ ^[a-f0-9]{12}$ ]] || exit 2
quote_schema="quote_conversion_$suffix"
"$mysql_bin" --no-defaults --protocol=SOCKET --socket="$socket_path" --user=root -e "CREATE DATABASE $quote_schema CHARACTER SET utf8mb4;"
export CRM_PHASE1_MYSQL_TEST=1 CRM_PHASE1_MYSQL_SOCKET="$socket_path" CRM_PHASE1_MYSQL_SCHEMA="$quote_schema"
timeout 60 "$php_bin" "${php_args[@]}" "$repo_root/tests/quote_conversion_mysql.php"
echo 'Quotation conversion isolated MySQL tests passed. No production database connection was used.'
