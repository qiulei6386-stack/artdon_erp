#!/bin/zsh

WORK_DIR="${0:A:h}"
CODEX_BIN="/Users/qiulei-office/.local/bin/codex"

if [[ ! -d "${WORK_DIR}" ]]; then
  echo "找不到广州 ERP 目录：${WORK_DIR}"
  echo "按回车键关闭窗口。"
  read -r
  exit 1
fi

if [[ ! -x "${CODEX_BIN}" ]]; then
  CODEX_BIN="$(command -v codex 2>/dev/null)"
fi

if [[ -z "${CODEX_BIN}" || ! -x "${CODEX_BIN}" ]]; then
  echo "找不到 Codex 命令，请先安装或检查 Codex。"
  echo "按回车键关闭窗口。"
  read -r
  exit 1
fi

cd "${WORK_DIR}" || exit 1
printf '\e]0;Codex - 广州 ERP\a'
exec "${CODEX_BIN}"
