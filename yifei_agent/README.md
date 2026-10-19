# 易飞只读同步代理

跑在能访问鼎捷易飞 SQL Server 的 Windows 机器上。每隔约 10 分钟只读查询业务库 **AT**，用 HTTPS 推到广州。这个程序不写易飞。

把整个 `yifei_agent` 目录复制到 Windows 即可，不必复制整套 CRM。

## 1. 只读 SQL 账号

在 SQL Server 2008 R2 上用管理员执行。密码不要写进仓库：

```sql
CREATE LOGIN yifei_readonly WITH PASSWORD = '请换成强密码', CHECK_POLICY = ON;
GO
USE AT;
GO
CREATE USER yifei_readonly FOR LOGIN yifei_readonly;
GO
EXEC sp_addrolemember 'db_datareader', 'yifei_readonly';
GO
```

只加入 `db_datareader`。不要加入 `db_datawriter`、`db_owner` 或其他可写角色。

## 2. 安装

安装 Python 3.8 或更新版本，以及 Windows 自带的 ODBC 驱动（连接串里的 `SQL Server`）。然后：

```bat
cd C:\artdon\yifei_agent
py -3 -m pip install -r requirements.txt
copy config.example.json config.json
```

`config.json` 已被 Git 忽略。`config.example.json` 里的密码和 token 必须保持空白。

编辑 `config.json`：

- `sqlserver.server`：易飞 SQL Server 地址
- `sqlserver.database`：`AT`
- `sqlserver.user` / `password`：上面的只读账号
- `guangzhou.url`：`https://novlight.com/yifei_sync/api.php`
- `guangzhou.token`：与服务器 `/www/server/artdon_secrets/yifei_sync.php` 里的 `token` 相同
- `interval_minutes`：`10`

先看将要执行的语句，不会连数据库：

```bat
py -3 sync.py --print-sql
py -3 sync.py --self-check
```

再同步一轮：

```bat
py -3 sync.py --once
```

本版增加了品号会计页的标准成本。部署到广州并执行 `php yifei_sync/migrate.php` 之后，在这台 Windows 机器上再跑一次全量，品号才会带上单位标准材料成本：

```bat
py -3.13 sync.py --once
```

请把 `py -3.13` 换成这台机器实际的 Python 启动方式。只升级广州、不重跑这句时，页面上的标准成本仍是空的。旧程序推上来的品号没有这些字段，不会把已经同步的标准成本清掉。

日志在 `logs/sync.log`。失败会按 2 秒、5 秒、10 秒重试。400 和 401 不重试。同一时间第二次启动会直接跳过。

## 3. 每 10 分钟

用任务计划程序调用 `--once`，不要用 `--loop` 再叠加计划任务。

```bat
schtasks /Create /TN "YifeiReadonlySync" /SC MINUTE /MO 10 /RU SYSTEM /TR "py -3 C:\artdon\yifei_agent\sync.py --once --config C:\artdon\yifei_agent\config.json"
```

请把 `py -3` 换成这台机器上 Python 的实际路径。计划任务的「起始于」设为 `C:\artdon\yifei_agent`。

## 4. 读哪些字段

char 列一律 `RTRIM`，否则尾部空格会让同一个品号对不上。

品号 INVMB：

```sql
SELECT
    RTRIM(MB001) AS item_code,
    RTRIM(ISNULL(MB002, '')) AS item_name,
    RTRIM(ISNULL(MB003, '')) AS spec,
    RTRIM(ISNULL(MB025, '')) AS item_attr,
    MB050 AS latest_purchase,
    MB057 AS std_material,
    MB058 AS std_labor,
    MB059 AS std_mfg,
    MB060 AS std_subcontract,
    MB061 AS level_labor,
    MB062 AS level_mfg,
    MB063 AS level_process,
    MB096 AS hour_base
FROM INVMB
WHERE RTRIM(ISNULL(MB001, '')) <> ''
```

MB001 品号，MB002 品名，MB003 规格。样例品号 `A4-2-01-4286`。

会计页标准成本（材料合计用 MB057，不用最近进价，也不用采购资料二的标准进价）：

| 易飞画面 | 列 | JSON | 依据 |
| --- | --- | --- | --- |
| 品号属性 | MB025 | `item_attr` | 已核实。公开 SQL 注释写明 MB025 品号属性。P 采购、M 自制、S 委外、Y 虚设、C 配置。 |
| 最近进价 | MB050 | `latest_purchase` | 已核实。公开查询把它标成最近进价。只保存对照，不进材料合计。画面上的含税勾选可能使库里的数与含税价不同。 |
| 单位标准材料成本 | MB057 | `std_material` | 已核实。公开 SQL 注释写明 MB057 材料成本。采购件是录入值，自制件可由「计算标准成本」从下阶回写。 |
| 单位标准人工成本 | MB058 | `std_labor` | 已核实。同一注释写明 MB058 人工成本。 |
| 单位标准制造费用 | MB059 | `std_mfg` | 推断。夹在已核实的 MB058 与 MB061 之间，对应画面上的制费。库里没有该列时跳过，不让整次品号同步失败。 |
| 单位标准加工费用 | MB060 | `std_subcontract` | 推断。同上，对应画面上的加工。列不存在就跳过。 |
| 本阶人工 | MB061 | `level_labor` | 已核实。注释与工时底数触发器都写了 MB061。 |
| 本阶制费 | MB062 | `level_mfg` | 已核实。同一触发器把 MB062 写成工时底数的制费。 |
| 本阶加工 | MB063 | `level_process` | 推断。紧挨 MB062，对应画面上的本阶加工。列不存在就跳过。 |
| 工时底数 | MB096 | `hour_base` | 已核实。触发器在更新 MB096 时重算本阶人工和本阶制费，类型 decimal(7,4)。 |

标准成本合计不是单独一列，广州用材料、人工、制费、加工四项里已经同步到的数字相加。标准工时、标准机时、标准进价的列名没有核实，不同步。MB042 是领料码，不是标准进价。MB064、MB065 是库存数量和库存金额，不是标准成本。

同步前会读 `INFORMATION_SCHEMA.COLUMNS`。候选列不在 AT 库里就从 SELECT 去掉并写日志。字段清单读不到或是空的，才退回查询全部候选列。

BOM BOMMD：

```sql
SELECT
    RTRIM(MD001) AS parent_code,
    RTRIM(MD003) AS component_code,
    MD006 AS qty,
    MD007 AS base_qty,
    MD008 AS scrap_rate
FROM BOMMD
WHERE RTRIM(ISNULL(MD001, '')) <> ''
  AND RTRIM(ISNULL(MD003, '')) <> ''
```

MD001 主件，MD003 元件，MD006 组成用量，MD007 底数，MD008 损耗率（Numeric(5,4)，0.25 表示 25%）。样例主件 `A4-2-01-4286` 的元件 `A4-2-01-0211`、`A4-2-01-0967`，用量各 1。同一主件+元件出现多行时，代理会把用量加总，底数和损耗率保留第一行。SQL 里底数是 NULL 时按 1 推送，损耗率是 NULL 时按 0 推送。材料成本的有效用量是 `组成用量 / 底数 × (1+损耗率)`。

成本 CSTMB（约 5802 行；不读 CSTLA、CSTMC）：

```sql
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
```

MB002 是期间，MB004 是单据号（不是品号），MB007 才是品号，成本取 MB005。每个品号只推最新期间那一行。样例品号 `A4-2-01-4286` 的成本是 `27.564722`。

## 5. 增量

默认每轮全量 upsert。全量对这三张表足够，而且不依赖尚未在现场核对的日期列。

`incremental.enabled` 设为 `true` 之后，品号和 BOM 才会按 `MODI_DATE`、`CREATE_DATE` 做水位（仍对这两列 `RTRIM`）。请先在 AT 库确认这两列确实存在、并且是可按字符串比较的日期，再打开。成本始终按最新期间全量计算。水位写在 `state/watermark.json`。`--full` 忽略水位。

易飞里删除的 BOM 行不会从广州删掉。本阶段只有 upsert，没有反写，也没有删除同步。

不连 SQL Server、只重推一份 JSON 时：

```bat
py -3 sync.py --push-file fixtures\sample_batches.json --config config.json
```
