#!/usr/bin/env bash
# Static-check interpreter wrapper, not an OS sandbox. Refuse database-capable
# PHP builds; skip php.ini and disable socket, mail and subprocess functions.
set -euo pipefail
php_bin="${CRM_PHASE1_PHP_BIN:-/usr/bin/php}"
"$php_bin" -n -r 'exit((class_exists("PDO", false) && PDO::getAvailableDrivers()) || function_exists("mysqli_connect") || function_exists("mysql_connect") ? 2 : 0);'
exec "$php_bin" -n -d allow_url_fopen=0 \
  -d disable_functions=fsockopen,pfsockopen,stream_socket_client,stream_socket_server,mail,exec,shell_exec,system,passthru,proc_open,popen \
  "$@"
