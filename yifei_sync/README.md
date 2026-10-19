# 易飞只读同步 · 广州接收端

本地鼎捷易飞（SQL Server，业务库 AT）只读推送到本系统。广州不连接易飞，本阶段也不写回易飞。

查询页：`yifei_sync/index.php`。未登录时跳到站点根目录的 `login.php`（例如 `/artdon_erp/login.php`），并带上原来的查询页地址；不会跳到 `yifei_sync/login.php`。  
接收接口：`POST yifei_sync/api.php`  
建表：`php yifei_sync/migrate.php`

## 合并之后再在服务器做（本 PR 不部署）

生产目录是 `/www/wwwroot/Artdon/artdon_erp/`，库名 `artdon_new_erp`。等这个提交进入 `main` 并且正式目录已经是同一个提交号之后：

1. 在正式目录执行 `php yifei_sync/migrate.php`。脚本建 `yf_invmb`、`yf_bommd`、`yf_cstmb`、`yf_sync_batches`。表已经存在时，会补上单位标准材料成本等会计栏位，以及 BOM 的底数、损耗率。可重复执行，不会清空数据。
2. 然后在易飞那台 Windows 机器上执行 `py -3.13 sync.py --once`（按那台机器的 Python 启动方式调整）。不重跑的话，品号主档没有单位标准材料成本，材料合计对不上易飞报表。旧代理推送的批次没有这些键，不会把已经写入的标准成本清掉。
3. 生成密钥，例如 `php -r "echo bin2hex(random_bytes(24)), PHP_EOL;"`。
4. 把密钥写到仓库外的 `/www/server/artdon_secrets/yifei_sync.php`：

```php
<?php
return array(
    'token' => '这里换成上一步的密钥',
);
```

不要把这个文件提交到 Git。本机联调可以用 `yifei_sync/config.local.php`（已忽略）或环境变量 `YIFEI_SYNC_TOKEN`。示例占位文字 `replace-with-a-long-random-token` 不能当作密钥。

`config.local.php` 里不要写 `allow_preview_without_login`。那是本机临时看页面用的，还必须同时有环境变量 `YIFEI_SYNC_ALLOW_PREVIEW=1`。正式服务器不要打开。

5. 同一把密钥只放在易飞那台 Windows 机器的 `yifei_agent/config.json`。接口地址示例：`https://novlight.com/yifei_sync/api.php`。
6. 用有 `bom.view` 的账号打开 BOM 页的「易飞数据」，或首页「研发 / 成本 / 生产」里的「易飞数据」。能看成本的人还需要 `bom.cost_view`，和现有 BOM 成本权限一致。没有这个权限时，账上成本、标准成本和材料合计都不输出。

## 接口

请求头：`X-Yifei-Token`（也接受 `Authorization: Bearer`）和 `Content-Type: application/json`。

`type` 三选一，单次最多 500 行。按品号、或主件+元件 upsert。成本按品号只留一行：后来的期间更旧时跳过，不覆盖已有的较新期间。

```bash
curl -sS -X POST 'https://novlight.com/yifei_sync/api.php' \
  -H 'Content-Type: application/json' \
  -H 'X-Yifei-Token: 同一把密钥' \
  -d '{"type":"invmb","batch_id":"demo-1","rows":[{"item_code":"A4-2-01-4286","item_name":"示例品名","spec":"示例规格"}]}'
```

成功时返回 `{"ok":true,...}`。密钥错误是 401，没建表是 503。

品号、品名、规格、主件、元件、期间、单据号入库前会去掉首尾空白，用来对齐易飞 char 字段的尾部空格（RTRIM）。

## 镜像字段

| 表 | 主键 | 来源 |
| --- | --- | --- |
| `yf_invmb` | 品号 `item_code` | INVMB.MB001 品号，MB002 品名，MB003 规格。另见下表会计栏位 |
| `yf_bommd` | 主件 + 元件 | BOMMD.MD001 主件，MD003 元件，MD006 组成用量，MD007 底数，MD008 损耗率 |
| `yf_cstmb` | 品号 | CSTMB.MB007 品号，MB002 期间，MB004 单据号（不是品号），MB005 账上最新单位成本 |
| `yf_sync_batches` | 自增 | 每次请求的批次日志 |

`yf_invmb` 会计栏位：

| 列 | 易飞 | 页面 | 依据 |
| --- | --- | --- | --- |
| `item_attr` | MB025 | 品号属性 | 已核实 |
| `latest_purchase` | MB050 | 最近进价（不参与材料合计） | 已核实。不拿它算材料合计 |
| `std_material` | MB057 | 单位标准材料成本 | 已核实。材料合计用这一列 |
| `std_labor` | MB058 | 单位标准人工成本 | 已核实 |
| `std_mfg` | MB059 | 单位标准制费 | 推断。代理发现列不存在会跳过 |
| `std_subcontract` | MB060 | 单位标准加工 | 推断。列不存在会跳过 |
| `level_labor` | MB061 | 本阶人工 | 已核实 |
| `level_mfg` | MB062 | 本阶制费 | 已核实 |
| `level_process` | MB063 | 本阶加工 | 推断。列不存在会跳过 |
| `hour_base` | MB096 | 工时底数 | 已核实 |

标准成本合计由材料、人工、制费、加工四项里非空的值相加，未同步的项会在页面上点名。标准进价的列名没有核实，不入库。MB042 是领料码。

材料合计（单阶，对应易飞标准成本报表的材料合计口径，但只展开当前 `yf_bommd` 直接子件）：

`元件 MB057 × MD006 / MD007 × (1 + MD008)`

底数为空或 0 时按 1，损耗率为空时按 0。MD008 是比率，0.25 表示 25%。单位标准材料成本是 NULL 的子件算入「缺标准材料成本」，不把 NULL 当成 0。已经同步且值为 0 的，按 0 计入合计。

不建 CSTLA、CSTMC。同一请求里重复的 BOM 主件+元件会把用量加总后再 upsert；底数和损耗率留第一行。请求里没有的键不会覆盖该列。易飞里已经删除的 BOM 行，本阶段不会从镜像里删掉。

## 核对成品 A1-1-01-0381

易飞标准成本报表头上的最新成本约 **6.229167**，底部材料合计约 **171.9508**。6.229167 是 CSTMB.MB005，页面上标成「账上最新单位成本(CSTMB)」，它本来就不是报表底部那个数。

重跑 `py -3.13 sync.py --once` 之后，用有 `bom.cost_view` 的账号打开这个成品：

1. 「账上最新单位成本(CSTMB)」仍应约 6.229167。
2. 页头「单位标准材料成本」是主件自己的 MB057。易飞做过计算标准成本时，这个数通常就是报表回写后的材料合计，目标约 171.9508。
3. 表尾「材料合计」是上面的单阶公式。子件 A5-2-06-0029 的单位标准材料成本应为 74，用量 1、底数 1、损耗 0 时，该行材料成本为 74。
4. A4-2-01-0235 的单位标准材料成本若是 22.400，而报表材料成本是 29.100，两者相除约 1.299107，不是整数用量。先看该行底数和损耗，以及它是不是还有下阶。不要改用标准进价或最近进价去凑 29.100。采购资料二上的 74 / 63.717、26 / 23.5 分别是标准进价和最近进价，默认不参与材料合计。
5. 单阶合计若仍离 171.9508 有差距，先看「缺标准材料成本」的个数，再和页头的主件 MB057 对比。仍对不上时，常见原因是多阶 BOM、虚设件（MB025=Y，报表会展开，本页不展开）、底数或损耗还没重推、报表勾了「材料成本以最近进价计算」。本阶段不自动展开多阶。

## 明确不做

不写回易飞，不改推广模块，不改微信小程序。
