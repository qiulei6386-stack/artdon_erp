# -*- coding: utf-8 -*-
"""把鼎捷易飞 AT 库的品号、BOM、最新成本只读推到广州 ArtDon。

只执行 SELECT / 指定的 SET。不要给这个进程写库权限。
char 字段在 SQL 里用 RTRIM，避免尾部空格变成不同的品号。
"""

from __future__ import annotations

import argparse
import json
import logging
import os
import re
import sys
import time
import urllib.error
import urllib.request
from decimal import Decimal
from pathlib import Path

ROOT = Path(__file__).resolve().parent
IDENT = re.compile(r"^[A-Z][A-Z0-9_]{0,30}$")

# 品号会计页。证据见 yifei_agent/README.md。
# confirmed：公开 SQL 注释或触发器写明了中文含义。
# inferred：夹在已核实栏位之间，对应画面上相邻的制费/加工；库里没有该列时跳过，不让整次品号同步失败。
INVMB_EXTRA = (
    ("MB025", "item_attr", "char", "confirmed"),
    ("MB050", "latest_purchase", "num", "confirmed"),
    ("MB057", "std_material", "num", "confirmed"),
    ("MB058", "std_labor", "num", "confirmed"),
    ("MB059", "std_mfg", "num", "inferred"),
    ("MB060", "std_subcontract", "num", "inferred"),
    ("MB061", "level_labor", "num", "confirmed"),
    ("MB062", "level_mfg", "num", "confirmed"),
    ("MB063", "level_process", "num", "inferred"),
    ("MB096", "hour_base", "num", "confirmed"),
)
BOMMD_EXTRA = (
    ("MD007", "base_qty", "num", "confirmed"),
    ("MD008", "scrap_rate", "num", "confirmed"),
)

# 录入品号信息「特征数据」。栏位标题来自 DSCLB；金额在 INVMB 数值列（多为 UDF*）。
FEATURE_CLASS_IDS = (
    "INVMI02",
    "INVI02",
    "INVI05",
    "INVMB",
    "INVMI01",
)
FEATURE_OWNER_HINTS = ("INVMB", "INVMI02", "INVI02")
CAPTION_IGNORE = re.compile(
    r"^(?:自订栏位|自訂欄位|自定义字段|用户自定义|使用者自訂|預留欄位|预留字段|"
    r"特性\d+|特殊特性\d+)$",
    re.IGNORECASE,
)
UDF_COLUMN = re.compile(r"^UDF\d+$", re.IGNORECASE)


def assert_ident(name: str) -> str:
    if not IDENT.fullmatch(name or ""):
        raise ValueError("日期列名不安全：" + str(name))
    return name


def sql_head(sql: str) -> str:
    for line in sql.splitlines():
        stripped = line.strip()
        if not stripped or stripped.startswith("--"):
            continue
        return stripped.split(None, 1)[0].upper()
    return ""


def assert_read_only(sql: str) -> None:
    body = sql.strip()
    if body.endswith(";"):
        body = body[:-1].rstrip()
    if ";" in body:
        raise RuntimeError("拒绝一次执行多条 SQL")
    head = sql_head(sql)
    upper = body.upper()
    if head == "SET":
        if "NOCOUNT" in upper or "TRANSACTION ISOLATION LEVEL READ UNCOMMITTED" in upper:
            return
        raise RuntimeError("拒绝执行的 SET")
    if head not in ("SELECT", "WITH"):
        raise RuntimeError("拒绝执行非只读语句")


def extra_fields(spec, present=None):
    """present 为 None 时列出全部；为集合时只留库里真实存在的列。"""
    fields = []
    skipped = []
    for column, alias, kind, _evidence in spec:
        assert_ident(column)
        if present is not None and column not in present:
            skipped.append(column)
            continue
        if kind == "char":
            fields.append("RTRIM(ISNULL(%s, '')) AS %s" % (column, alias))
        else:
            fields.append("%s AS %s" % (column, alias))
    return fields, skipped


def invmb_sql(date_columns=None, watermark: str = "", present=None):
    """INVMB.MB001 品号，MB002 品名，MB003 规格，以及会计页标准成本。"""
    date_columns = list(date_columns or [])
    fields = [
        "RTRIM(MB001) AS item_code",
        "RTRIM(ISNULL(MB002, '')) AS item_name",
        "RTRIM(ISNULL(MB003, '')) AS spec",
    ]
    extra, skipped = extra_fields(INVMB_EXTRA, present)
    fields.extend(extra)
    params = []
    for col in date_columns:
        assert_ident(col)
        fields.append("RTRIM(ISNULL(%s, '')) AS wm_%s" % (col, col))
    sql = (
        "-- INVMB.MB001 品号，MB002 品名，MB003 规格。\n"
        "-- MB057 单位标准材料成本，MB058 单位标准人工成本，MB061 本阶人工，MB062 本阶制费，MB096 工时底数。\n"
        "-- MB059/MB060/MB063 是制费、加工的相邻栏位；列不存在时运行期会跳过。\n"
        "-- MB050 最近进价，只作对照，不参与材料合计。char 列一律 RTRIM。\n"
        "SELECT\n    " + ",\n    ".join(fields) + "\n"
        "FROM INVMB\n"
        "WHERE RTRIM(ISNULL(MB001, '')) <> ''"
    )
    if watermark and date_columns:
        parts = []
        for col in date_columns:
            assert_ident(col)
            parts.append("RTRIM(ISNULL(%s, '')) > ?" % col)
            params.append(watermark)
        sql += "\n  AND (" + " OR ".join(parts) + ")"
    return sql, params, skipped


def bommd_sql(date_columns=None, watermark: str = "", present=None):
    """BOMMD.MD001 主件，MD003 元件，MD006 组成用量，MD007 底数，MD008 损耗率。"""
    date_columns = list(date_columns or [])
    fields = [
        "RTRIM(MD001) AS parent_code",
        "RTRIM(MD003) AS component_code",
        "MD006 AS qty",
    ]
    extra, skipped = extra_fields(BOMMD_EXTRA, present)
    fields.extend(extra)
    params = []
    for col in date_columns:
        assert_ident(col)
        fields.append("RTRIM(ISNULL(%s, '')) AS wm_%s" % (col, col))
    sql = (
        "-- BOMMD.MD001 主件，MD003 元件，MD006 组成用量，MD007 底数，MD008 损耗率。char 列一律 RTRIM。\n"
        "SELECT\n    " + ",\n    ".join(fields) + "\n"
        "FROM BOMMD\n"
        "WHERE RTRIM(ISNULL(MD001, '')) <> ''\n"
        "  AND RTRIM(ISNULL(MD003, '')) <> ''"
    )
    if watermark and date_columns:
        parts = []
        for col in date_columns:
            assert_ident(col)
            parts.append("RTRIM(ISNULL(%s, '')) > ?" % col)
            params.append(watermark)
        sql += "\n  AND (" + " OR ".join(parts) + ")"
    return sql, params, skipped


def cstmb_sql():
    """每个品号取 CSTMB 最新期间一行的 MB005。MB004 是单据号，不是品号。"""
    sql = """
-- CSTMB.MB002 期间，MB004 单据号（不是品号），MB007 品号，MB005 成本。
-- 不读 CSTLA / CSTMC。每个品号只取最新期间一行；同一期间多张单据时取单据号较大的一行。
SELECT item_code, period, doc_no, cost
FROM (
    SELECT
        RTRIM(MB007) AS item_code,
        RTRIM(MB002) AS period,
        RTRIM(ISNULL(MB004, '')) AS doc_no,
        MB005 AS cost,
        ROW_NUMBER() OVER (
            PARTITION BY RTRIM(MB007)
            ORDER BY RTRIM(MB002) DESC, RTRIM(ISNULL(MB004, '')) DESC
        ) AS rn
    FROM CSTMB
    WHERE RTRIM(ISNULL(MB007, '')) <> ''
) AS latest_cost
WHERE rn = 1
""".strip()
    return sql, []


def default_queries():
    return {
        "invmb": invmb_sql()[0],
        "bommd": bommd_sql()[0],
        "cstmb": cstmb_sql()[0],
        "feature": feature_sql_template(),
    }


def feature_sql_template() -> str:
    return (
        "-- 特征数据：INVMB 数值栏 + DSCLB.Caption 对应列名（见 README）。\n"
        "-- 运行期按 INFORMATION_SCHEMA 与 DSCLB 生成列清单，不在此固定 UDF 编号。\n"
        "SELECT RTRIM(MB001) AS item_code FROM INVMB WHERE RTRIM(ISNULL(MB001, '')) <> ''"
    )


def caption_usable(name: str, column: str) -> bool:
    text = rtrim_text(name)
    col = (column or "").strip().upper()
    if not text or not col:
        return False
    if text.upper() == col:
        return False
    if CAPTION_IGNORE.match(text):
        return False
    for bad in ("用户自定义字段", "使用者自訂欄位", "自定义字段"):
        if bad in text:
            return False
    return True


def dsclb_caption_sql(database: str) -> str:
    table = ("%s.dbo.DSCLB" % database) if database else "DSCLB"
    return (
        "-- DSCLB：LB005=Caption，LB006=列名，LB009 简体 / LB008 繁体。\n"
        "SELECT\n"
        "    RTRIM(ISNULL(LB001, '')) AS lb001,\n"
        "    RTRIM(LB006) AS column_name,\n"
        "    RTRIM(ISNULL(LB002, '')) AS class_id,\n"
        "    RTRIM(ISNULL(LB004, '')) AS owner_name,\n"
        "    RTRIM(ISNULL(LB009, '')) AS caption_chs,\n"
        "    RTRIM(ISNULL(LB008, '')) AS caption_cht\n"
        "FROM %s\n"
        "WHERE RTRIM(ISNULL(LB005, '')) = 'Caption'\n"
        "  AND RTRIM(ISNULL(LB006, '')) <> ''"
    ) % table


def load_dsclb_captions(conn, cfg: dict) -> dict:
    """返回 {COLUMN: caption}，优先客户自订 LB001=3，再客制/预设。"""
    databases = []
    feat_cfg = cfg.get("features") or {}
    if feat_cfg.get("dsclb_database"):
        databases.append(str(feat_cfg["dsclb_database"]).strip())
    databases.extend(["DSCSYS", "AT"])
    merged = {}
    priority = {}
    for db in databases:
        if not db:
            continue
        sql = dsclb_caption_sql(db)
        try:
            assert_read_only(sql)
            rows = fetch_all(conn, sql)
        except Exception as exc:
            logging.warning("读取 %s.DSCLB 失败：%s", db, exc)
            continue
        for row in rows:
            col = rtrim_text(row.get("column_name")).upper()
            if not col:
                continue
            class_id = rtrim_text(row.get("class_id"))
            owner = rtrim_text(row.get("owner_name"))
            if class_id and class_id not in FEATURE_CLASS_IDS and owner not in FEATURE_OWNER_HINTS:
                continue
            cap = rtrim_text(row.get("caption_chs")) or rtrim_text(row.get("caption_cht"))
            if not caption_usable(cap, col):
                continue
            kind_pri = 0
            lb001 = rtrim_text(row.get("lb001")) if "lb001" in row else ""
            if lb001 == "3":
                kind_pri = 3
            elif lb001 == "2":
                kind_pri = 2
            else:
                kind_pri = 1
            old = priority.get(col, -1)
            if kind_pri >= old:
                priority[col] = kind_pri
                merged[col] = cap
        if merged:
            logging.info("DSCLB 自 %s 载入 %s 个特征栏标题", db, len(merged))
            break
    return merged


def config_feature_columns(cfg: dict) -> dict:
    out = {}
    for entry in (cfg.get("features") or {}).get("columns") or []:
        if not isinstance(entry, dict):
            continue
        col = rtrim_text(entry.get("column")).upper()
        name = rtrim_text(entry.get("name"))
        if not col or not name:
            continue
        out[col] = name
    return out


def invmb_column_types(conn) -> dict:
    sql = (
        "SELECT COLUMN_NAME, DATA_TYPE FROM INFORMATION_SCHEMA.COLUMNS "
        "WHERE TABLE_NAME = 'INVMB' AND TABLE_SCHEMA = SCHEMA_NAME()"
    )
    types = {}
    try:
        for row in fetch_all(conn, sql):
            col = str(row.get("COLUMN_NAME") or "").strip().upper()
            if col:
                types[col] = str(row.get("DATA_TYPE") or "").lower()
    except Exception as exc:
        logging.warning("读取 INVMB 类型失败：%s", exc)
    return types


def numeric_invmb_types() -> set:
    return {
        "decimal",
        "numeric",
        "float",
        "real",
        "money",
        "smallmoney",
        "int",
        "bigint",
        "smallint",
        "tinyint",
    }


def discover_feature_fields(conn, cfg: dict) -> list:
    """[(column, caption, sort_index), ...]"""
    present = present_columns(conn, "INVMB") or set()
    types = invmb_column_types(conn)
    captions = load_dsclb_captions(conn, cfg)
    captions.update(config_feature_columns(cfg))
    fields = []
    sort = 0
    for col in sorted(present):
        cap = captions.get(col)
        if not cap:
            continue
        dtype = types.get(col, "")
        is_udf = bool(UDF_COLUMN.match(col))
        if dtype in numeric_invmb_types():
            if is_udf or caption_usable(cap, col):
                assert_ident(col)
                fields.append((col, cap, sort))
                sort += 1
            continue
        if dtype in ("varchar", "nvarchar", "char", "nchar") and caption_usable(cap, col):
            assert_ident(col)
            fields.append((col, cap, sort))
            sort += 1
    if not fields:
        logging.warning("未发现可同步的特征金额栏；请检查 DSCLB 权限或 features.columns 配置")
    else:
        logging.info("特征栏：%s", ", ".join("%s(%s)" % (c, n) for c, n, _ in fields[:12]) + ("..." if len(fields) > 12 else ""))
    return fields


def feature_invmb_sql(fields, date_columns=None, watermark: str = "", present=None):
    date_columns = list(date_columns or [])
    if not fields:
        return "SELECT RTRIM(MB001) AS item_code FROM INVMB WHERE 1=0", [], []
    select_parts = ["RTRIM(MB001) AS item_code"]
    for col, _cap, _sort in fields:
        select_parts.append("%s AS f_%s" % (col, col))
    params = []
    for col in date_columns:
        assert_ident(col)
        select_parts.append("RTRIM(ISNULL(%s, '')) AS wm_%s" % (col, col))
    sql = (
        "-- 特征数据金额：INVMB 用户自定义/客制 Caption 列。\n"
        "SELECT\n    " + ",\n    ".join(select_parts) + "\n"
        "FROM INVMB\n"
        "WHERE RTRIM(ISNULL(MB001, '')) <> ''"
    )
    if watermark and date_columns:
        parts = []
        for col in date_columns:
            parts.append("RTRIM(ISNULL(%s, '')) > ?" % col)
            params.append(watermark)
        sql += "\n  AND (" + " OR ".join(parts) + ")"
    return sql, params, []


def invmb_rows_to_features(rows: list, fields: list) -> tuple:
    """返回 (feature_rows, items_touched)"""
    feature_rows = []
    items = []
    for row in rows:
        code = rtrim_text(row.get("item_code"))
        if not code:
            continue
        items.append(code)
        for col, cap, sort_no in fields:
            raw = row.get("f_" + col)
            if raw is None:
                continue
            amount = optional_number(raw)
            if amount is None or amount == "0":
                continue
            feature_rows.append({
                "item_code": code,
                "feature_code": col,
                "feature_name": cap,
                "amount": amount,
                "sort_no": sort_no,
            })
    return feature_rows, items


def chunk_feature_payload(feature_rows: list, all_items: list, max_rows: int):
    """同一品号的特征行不拆到不同批次。items 含本批扫描到的全部品号（无特征也要列出以便清空）。 """
    max_rows = max(1, min(int(max_rows), 500))
    by_item = {}
    for row in feature_rows:
        by_item.setdefault(row["item_code"], []).append(row)
    order = []
    seen = set()
    for code in all_items:
        text = rtrim_text(code)
        if text and text not in seen:
            seen.add(text)
            order.append(text)
    for code in by_item:
        if code not in seen:
            order.append(code)
    batches = []
    cur_rows = []
    cur_items = []

    def flush():
        nonlocal cur_rows, cur_items
        if cur_items:
            batches.append((list(cur_items), list(cur_rows)))
        cur_rows = []
        cur_items = []

    for code in order:
        rows = by_item.get(code, [])
        add_rows = len(rows)
        if cur_items and (len(cur_rows) + add_rows > max_rows or len(cur_items) + 1 > max_rows):
            flush()
        cur_items.append(code)
        cur_rows.extend(rows)
    flush()
    if not batches and order:
        batches.append((order, []))
    return batches


def push_feature_payload(cfg: dict, batch_prefix: str, index: int, items: list, rows: list) -> None:
    payload = {
        "type": "feature",
        "batch_id": "%s-feature-%d" % (batch_prefix, index),
        "items": items,
        "rows": rows,
    }
    push_payload(cfg, payload)


def sync_features(cfg: dict, conn, full: bool, state: dict, state_path: Path, stamp: str) -> None:
    fields = discover_feature_fields(conn, cfg)
    if not fields:
        return
    columns = incremental_columns(cfg, full)
    present = present_columns(conn, "INVMB")
    watermark = "" if full else str(state.get("feature") or "")
    sql, params, _skipped = feature_invmb_sql(fields, columns, watermark, present)
    logging.info("读取 feature")
    rows = fetch_all(conn, sql, params)
    watermark_next = max_watermark(rows, columns) if columns else ""
    feature_rows, _items = invmb_rows_to_features(rows, fields)
    size = int(cfg.get("batch_size") or 200)
    touched = list(dict.fromkeys(
        rtrim_text(r.get("item_code")) for r in rows if rtrim_text(r.get("item_code"))
    ))
    if not touched:
        logging.info("feature 没有品号行")
    else:
        batches = chunk_feature_payload(feature_rows, touched, size)
        for idx, (batch_items, batch_rows) in enumerate(batches, start=1):
            push_feature_payload(cfg, stamp, idx, batch_items, batch_rows)
        logging.info("feature 推送完成，%s 个品号，%s 行特征", len(touched), len(feature_rows))
    if watermark_next:
        state["feature"] = watermark_next
        write_state(state_path, state)


def rtrim_text(value) -> str:
    if value is None:
        return ""
    text = re.sub(r"[\x00-\x1f\x7f]+", " ", str(value).replace("\ufeff", ""))
    return re.sub(r" {2,}", " ", text).strip(" ")


def as_number(value):
    if value is None:
        return "0"
    if isinstance(value, Decimal):
        return format(value, "f")
    if isinstance(value, float):
        return format(value, ".6f").rstrip("0").rstrip(".") or "0"
    if isinstance(value, int) and not isinstance(value, bool):
        return str(value)
    return str(value).strip()


def optional_number(value):
    """可选金额。SQL NULL 保持 JSON null；有值时最多 6 位小数，避免广州拒收。"""
    if value is None:
        return None
    text = as_number(value).strip()
    if text == "":
        return None
    rendered = format(Decimal(text).quantize(Decimal("0.000001")), "f")
    if "." in rendered:
        rendered = rendered.rstrip("0").rstrip(".")
    return rendered or "0"


def copy_spec_fields(out, row, spec, null_defaults=None):
    """源行没有的键不输出，避免旧批次把已同步的标准成本覆盖成空。"""
    null_defaults = null_defaults or {}
    for _column, alias, kind, _evidence in spec:
        if alias not in row:
            continue
        if kind == "char":
            out[alias] = rtrim_text(row.get(alias))
            continue
        raw = row.get(alias)
        if raw is None and alias in null_defaults:
            out[alias] = null_defaults[alias]
        else:
            out[alias] = optional_number(raw)
    return out


def collapse_bom(rows):
    """同一请求里相同主件+元件只留一行，用量相加。底数和损耗率留第一行。"""
    ordered = []
    acc = {}
    extras = {}
    for row in rows:
        parent = rtrim_text(row.get("parent_code"))
        component = rtrim_text(row.get("component_code"))
        key = (parent, component)
        qty = as_number(row.get("qty"))
        if key not in acc:
            ordered.append(key)
            acc[key] = Decimal("0")
            extras[key] = copy_spec_fields({}, row, BOMMD_EXTRA, {"base_qty": "1", "scrap_rate": "0"})
        acc[key] += Decimal(qty or "0")
    out = []
    for key in ordered:
        item = {
            "parent_code": key[0],
            "component_code": key[1],
            "qty": format(acc[key], "f"),
        }
        item.update(extras[key])
        out.append(item)
    return out


def public_row(kind: str, row: dict) -> dict:
    if kind == "invmb":
        out = {
            "item_code": rtrim_text(row.get("item_code")),
            "item_name": rtrim_text(row.get("item_name")),
            "spec": rtrim_text(row.get("spec")),
        }
        return copy_spec_fields(out, row, INVMB_EXTRA)
    if kind == "bommd":
        out = {
            "parent_code": rtrim_text(row.get("parent_code")),
            "component_code": rtrim_text(row.get("component_code")),
            "qty": as_number(row.get("qty")),
        }
        return copy_spec_fields(out, row, BOMMD_EXTRA, {"base_qty": "1", "scrap_rate": "0"})
    return {
        "item_code": rtrim_text(row.get("item_code")),
        "period": rtrim_text(row.get("period")),
        "doc_no": rtrim_text(row.get("doc_no")),
        "cost": as_number(row.get("cost")),
    }


def max_watermark(rows, columns) -> str:
    best = ""
    for row in rows:
        for col in columns:
            value = rtrim_text(row.get("wm_" + col))
            if value > best:
                best = value
    return best


def chunked(rows, size):
    size = max(1, min(int(size), 500))
    for start in range(0, len(rows), size):
        yield rows[start:start + size]


def load_config(path: Path) -> dict:
    data = json.loads(path.read_text(encoding="utf-8-sig"))
    if not isinstance(data, dict):
        raise ValueError("配置文件必须是 JSON 对象")
    return data


def setup_log(path: Path) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    logging.basicConfig(
        level=logging.INFO,
        format="%(asctime)s %(levelname)s %(message)s",
        handlers=[
            logging.FileHandler(path, encoding="utf-8"),
            logging.StreamHandler(sys.stdout),
        ],
    )


class SyncSkipped(Exception):
    pass


class RunLock:
    def __init__(self, path: Path):
        self.path = path
        self.handle = None

    def __enter__(self):
        self.path.parent.mkdir(parents=True, exist_ok=True)
        self.handle = open(self.path, "a+", encoding="utf-8")
        if os.name == "nt":
            import msvcrt
            try:
                msvcrt.locking(self.handle.fileno(), msvcrt.LK_NBLCK, 1)
            except OSError:
                raise SyncSkipped("已有同步在运行，本次跳过")
        else:
            import fcntl
            try:
                fcntl.flock(self.handle.fileno(), fcntl.LOCK_EX | fcntl.LOCK_NB)
            except OSError:
                raise SyncSkipped("已有同步在运行，本次跳过")
        return self

    def __exit__(self, exc_type, exc, tb):
        if not self.handle:
            return
        if os.name == "nt":
            import msvcrt
            try:
                self.handle.seek(0)
                msvcrt.locking(self.handle.fileno(), msvcrt.LK_UNLCK, 1)
            except OSError:
                pass
        else:
            import fcntl
            fcntl.flock(self.handle.fileno(), fcntl.LOCK_UN)
        self.handle.close()


def read_state(path: Path) -> dict:
    if not path.is_file():
        return {}
    try:
        data = json.loads(path.read_text(encoding="utf-8"))
    except ValueError:
        return {}
    return data if isinstance(data, dict) else {}


def write_state(path: Path, state: dict) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(json.dumps(state, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")


def connect_sql(cfg: dict):
    try:
        import pyodbc
    except ImportError as exc:
        raise RuntimeError("缺少 pyodbc。请先执行 pip install -r requirements.txt") from exc
    sql = cfg.get("sqlserver") or {}
    password = sql.get("password") or ""
    if password == "":
        raise RuntimeError("sqlserver.password 为空。请在本机 config.json 填写只读账号密码，不要写进示例文件。")
    driver = sql.get("driver") or "SQL Server"
    parts = [
        "DRIVER={%s}" % driver,
        "SERVER=%s" % sql.get("server", "127.0.0.1"),
        "DATABASE=%s" % sql.get("database", "AT"),
        "UID=%s" % sql.get("user", ""),
        "PWD=%s" % password,
    ]
    if driver != "SQL Server":
        parts.append("TrustServerCertificate=yes")
    timeout = int(sql.get("timeout_seconds") or 15)
    conn = pyodbc.connect(";".join(parts), readonly=True, timeout=timeout)
    cursor = conn.cursor()
    guarded(cursor, "SET NOCOUNT ON")
    guarded(cursor, "SET TRANSACTION ISOLATION LEVEL READ UNCOMMITTED")
    return conn


def guarded(cursor, sql: str, params=()):
    assert_read_only(sql)
    return cursor.execute(sql, params)


def fetch_all(conn, sql: str, params=()):
    cursor = conn.cursor()
    guarded(cursor, sql, params)
    columns = [item[0] for item in cursor.description]
    rows = []
    for record in cursor.fetchall():
        rows.append({columns[i]: record[i] for i in range(len(columns))})
    return rows


def present_columns(conn, table: str):
    """返回该表实际列名。读不到或为空时返回 None，调用方改查全部候选列。"""
    sql = (
        "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS "
        "WHERE TABLE_NAME = ? AND TABLE_SCHEMA = SCHEMA_NAME()"
    )
    try:
        rows = fetch_all(conn, sql, (table,))
    except Exception:
        logging.warning("无法读取 %s 字段清单，按全部字段查询", table)
        return None
    names = set()
    for row in rows:
        for value in row.values():
            text = str(value or "").strip().upper()
            if text:
                names.add(text)
    if not names:
        logging.warning("%s 字段清单为空，按全部字段查询", table)
        return None
    return names


def post_json(url: str, token: str, payload: dict, timeout: int, verify: bool):
    data = json.dumps(payload, ensure_ascii=False).encode("utf-8")
    request = urllib.request.Request(url, data=data, method="POST")
    request.add_header("Content-Type", "application/json; charset=utf-8")
    request.add_header("X-Yifei-Token", token)
    request.add_header("User-Agent", "ArtdonYifeiAgent/1.0")
    context = None
    if not verify:
        import ssl
        context = ssl._create_unverified_context()
        logging.warning("已关闭 TLS 校验，只建议在本机排错时使用")
    try:
        with urllib.request.urlopen(request, timeout=timeout, context=context) as response:
            body = response.read().decode("utf-8", "replace")
            return response.status, body
    except urllib.error.HTTPError as exc:
        body = exc.read().decode("utf-8", "replace")
        return exc.code, body


def push_payload(cfg: dict, payload: dict) -> None:
    remote = cfg.get("guangzhou") or {}
    url = remote.get("url") or ""
    token = remote.get("token") or ""
    if not url or not token:
        raise RuntimeError("guangzhou.url 和 guangzhou.token 不能为空")
    if "replace-with" in token:
        raise RuntimeError("token 仍是占位文字")
    timeout = int(remote.get("timeout_seconds") or 60)
    verify = bool(remote.get("verify_tls", True))
    delays = (2, 5, 10)
    last = ""
    for attempt in range(len(delays) + 1):
        try:
            status, body = post_json(url, token, payload, timeout, verify)
        except urllib.error.URLError as exc:
            last = str(exc.reason)
            status = 0
        else:
            parsed = None
            try:
                parsed = json.loads(body)
            except ValueError:
                parsed = None
            if status == 200 and isinstance(parsed, dict) and parsed.get("ok") is True:
                logging.info("推送 %s 批次 %s 成功 %s 行", payload.get("type"), payload.get("batch_id"), len(payload.get("rows") or []))
                return
            last = "HTTP %s %s" % (status, body[:300])
            if status in (400, 401, 403, 415):
                break
        if attempt < len(delays):
            logging.warning("推送失败，将重试：%s", last)
            time.sleep(delays[attempt])
    raise RuntimeError("推送失败：" + last)


def push_rows(cfg: dict, kind: str, rows, batch_prefix: str) -> None:
    size = int(cfg.get("batch_size") or 200)
    total = 0
    for index, part in enumerate(chunked(list(rows), size), start=1):
        payload = {
            "type": kind,
            "batch_id": "%s-%s-%d" % (batch_prefix, kind, index),
            "rows": part,
        }
        push_payload(cfg, payload)
        total += len(part)
    logging.info("%s 推送完成，共 %s 行", kind, total)


def incremental_columns(cfg: dict, full: bool):
    inc = cfg.get("incremental") or {}
    if full or not inc.get("enabled"):
        return []
    columns = inc.get("date_columns") or ["MODI_DATE", "CREATE_DATE"]
    return [assert_ident(col) for col in columns]


def sync_database(cfg: dict, full: bool) -> None:
    state_path = ROOT / (cfg.get("state_file") or "state/watermark.json")
    state = {} if full else read_state(state_path)
    columns = incremental_columns(cfg, full)
    conn = connect_sql(cfg)
    try:
        stamp = time.strftime("%Y%m%d%H%M%S")
        plan = (
            ("invmb", "INVMB", invmb_sql, False),
            ("bommd", "BOMMD", bommd_sql, False),
            ("cstmb", "", cstmb_sql, True),
        )
        for kind, table, builder, always_full in plan:
            present = None
            if table:
                present = present_columns(conn, table)
                if present is None:
                    logging.warning("%s 未能确认字段清单，按全部候选列查询", kind)
            if always_full:
                built = builder()
                used_columns = []
            else:
                watermark = "" if full else str(state.get(kind) or "")
                built = builder(columns, watermark, present)
                used_columns = columns
            if len(built) == 3:
                sql, params, skipped = built
            else:
                sql, params = built
                skipped = []
            if skipped:
                logging.warning("%s 跳过库里没有的列：%s", kind, ", ".join(skipped))
            logging.info("读取 %s", kind)
            rows = fetch_all(conn, sql, params)
            watermark_next = max_watermark(rows, used_columns) if used_columns else ""
            if kind == "bommd":
                rows = collapse_bom(rows)
            else:
                rows = [public_row(kind, row) for row in rows]
            if rows:
                push_rows(cfg, kind, rows, stamp)
            else:
                logging.info("%s 没有新行", kind)
            if watermark_next:
                state[kind] = watermark_next
                write_state(state_path, state)
        sync_features(cfg, conn, full, state, state_path, stamp)
    finally:
        conn.close()


def push_file(cfg: dict, path: Path) -> None:
    data = json.loads(path.read_text(encoding="utf-8-sig"))
    if isinstance(data, dict):
        data = [data]
    if not isinstance(data, list):
        raise RuntimeError("推送文件必须是批次对象或批次数组")
    for index, batch in enumerate(data, start=1):
        if not isinstance(batch, dict):
            raise RuntimeError("第 %s 个批次不是对象" % index)
        kind = batch.get("type")
        rows = batch.get("rows") or []
        if kind == "bommd":
            rows = collapse_bom(rows)
        elif kind == "feature":
            payload = {
                "type": kind,
                "batch_id": batch.get("batch_id") or time.strftime("%Y%m%d%H%M%S") + "-" + str(kind) + "-" + str(index),
                "items": batch.get("items") or [],
                "rows": rows,
            }
            push_payload(cfg, payload)
            continue
        else:
            rows = [public_row(kind, row) for row in rows]
        payload = {
            "type": kind,
            "batch_id": batch.get("batch_id") or time.strftime("%Y%m%d%H%M%S") + "-" + str(kind) + "-" + str(index),
            "rows": rows,
        }
        push_payload(cfg, payload)


def self_check() -> int:
    queries = default_queries()
    blob = "\n".join(queries.values())
    executable = "\n".join(line for line in blob.splitlines() if not line.strip().startswith("--"))
    required = [
        "RTRIM(MB001)",
        "RTRIM(ISNULL(MB002, ''))",
        "RTRIM(ISNULL(MB003, ''))",
        "RTRIM(MD001)",
        "RTRIM(MD003)",
        "MD006",
        "RTRIM(MB007)",
        "RTRIM(MB002)",
        "RTRIM(ISNULL(MB004, ''))",
        "MB005",
        "ROW_NUMBER()",
        "MB025",
        "MB050",
        "MB057",
        "MB058",
        "MB061",
        "MB062",
        "MB096",
        "MD007",
        "MD008",
    ]
    missing = [item for item in required if item not in blob]
    for name in ("CSTLA", "CSTMC"):
        if name in executable:
            print("不应读取 " + name)
            return 1
    for sql in queries.values():
        assert_read_only(sql)
    bad = assert_read_only
    try:
        bad("UPDATE INVMB SET MB001 = MB001")
    except RuntimeError:
        pass
    else:
        print("写语句没有被拒绝")
        return 1
    if missing:
        print("缺少 " + ", ".join(missing))
        return 1
    if not caption_usable("数控开料", "UDF51"):
        print("特征标题过滤失败")
        return 1
    if caption_usable("用户自定义字段7", "UDF57") or caption_usable("特性01", "MB546"):
        print("应忽略默认特征标题")
        return 1
    chunks = chunk_feature_payload(
        [{"item_code": "A", "feature_code": "U1", "feature_name": "n", "amount": "1", "sort_no": 0}],
        ["A", "B"],
        500,
    )
    if not chunks or chunks[0][0] != ["A", "B"] or len(chunks[0][1]) != 1:
        print("特征批次应带上全部 items 且保留特征行")
        return 1
    sample = [{"parent_code": "A ", "component_code": "B ", "qty": 1}, {"parent_code": "A", "component_code": "B", "qty": "2"}]
    folded = collapse_bom(sample)
    if len(folded) != 1 or Decimal(folded[0]["qty"]) != Decimal("3"):
        print("BOM 合并失败")
        return 1
    if folded[0]["parent_code"] != "A":
        print("RTRIM 语义失败")
        return 1
    if "base_qty" in folded[0] or "scrap_rate" in folded[0]:
        print("旧 BOM 行不应带出底数")
        return 1
    rich = collapse_bom([
        {"parent_code": "A ", "component_code": "B ", "qty": 1, "base_qty": None, "scrap_rate": "0.25"},
        {"parent_code": "A", "component_code": "B", "qty": 2, "base_qty": 9, "scrap_rate": 0},
    ])
    if len(rich) != 1 or Decimal(rich[0]["qty"]) != Decimal("3"):
        print("带底数的 BOM 合并失败")
        return 1
    if rich[0].get("base_qty") != "1" or rich[0].get("scrap_rate") != "0.25":
        print("底数空值应按 1，损耗率应保留第一行")
        return 1
    shown = public_row("invmb", {
        "item_code": "A5-2-06-0029",
        "item_name": "驱动",
        "spec": "",
        "item_attr": "P ",
        "std_material": Decimal("74"),
        "std_labor": None,
    })
    if shown.get("std_material") != "74" or "std_mfg" in shown or shown.get("item_attr") != "P" or shown.get("std_labor") is not None:
        print("品号标准成本输出不正确")
        return 1
    bare = public_row("invmb", {"item_code": "A", "item_name": "", "spec": ""})
    if "std_material" in bare or "latest_purchase" in bare:
        print("缺字段不应输出标准成本")
        return 1
    narrowed, _params, skipped = invmb_sql(present={"MB001"})
    narrowed_sql = "\n".join(line for line in narrowed.splitlines() if not line.strip().startswith("--"))
    if "std_material" in narrowed_sql or "MB057" not in skipped:
        print("缺列时应跳过 MB057")
        return 1
    print("self-check ok")
    return 0


def main(argv=None) -> int:
    parser = argparse.ArgumentParser(description="易飞 AT 只读同步到广州")
    parser.add_argument("--config", default=str(ROOT / "config.json"))
    parser.add_argument("--once", action="store_true", help="同步一轮后退出")
    parser.add_argument("--loop", action="store_true", help="按 interval_minutes 循环")
    parser.add_argument("--full", action="store_true", help="忽略水位，全量读取")
    parser.add_argument("--print-sql", action="store_true", help="打印只读查询，不连接数据库")
    parser.add_argument("--self-check", action="store_true", help="检查 SQL 为只读且含 RTRIM")
    parser.add_argument("--push-file", help="不连 SQL Server，把 JSON 批次推到广州")
    args = parser.parse_args(argv)
    if args.self_check:
        return self_check()
    if args.print_sql:
        for name, sql in default_queries().items():
            print("-- " + name)
            print(sql)
            print()
        return 0
    config_path = Path(args.config)
    if not config_path.is_file():
        print("找不到配置：" + str(config_path) + "。请复制 config.example.json 为 config.json。", file=sys.stderr)
        return 1
    cfg = load_config(config_path)
    log_path = ROOT / (cfg.get("log_file") or "logs/sync.log")
    setup_log(log_path)
    if args.push_file:
        with RunLock(ROOT / "state" / "sync.lock"):
            push_file(cfg, Path(args.push_file))
        return 0

    def run():
        with RunLock(ROOT / "state" / "sync.lock"):
            sync_database(cfg, args.full)

    if args.loop:
        minutes = int(cfg.get("interval_minutes") or 10)
        while True:
            try:
                run()
            except SyncSkipped as exc:
                logging.warning(str(exc))
            except Exception:
                logging.exception("本轮同步失败")
            time.sleep(max(1, minutes) * 60)
    try:
        run()
    except SyncSkipped as exc:
        logging.warning(str(exc))
        return 0
    return 0


if __name__ == "__main__":
    try:
        sys.exit(main())
    except Exception as exc:
        logging.exception("同步失败")
        print(str(exc), file=sys.stderr)
        sys.exit(1)
