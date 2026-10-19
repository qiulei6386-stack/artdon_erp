# 易飞只读同步 · 广州接收端

本地鼎捷易飞（SQL Server，业务库 AT）只读推送到本系统。广州不连接易飞，本阶段也不写回易飞。

查询页：`yifei_sync/index.php`  
接收接口：`POST yifei_sync/api.php`  
建表：`php yifei_sync/migrate.php`

## 合并之后再在服务器做（本 PR 不部署）

生产目录是 `/www/wwwroot/Artdon/artdon_erp/`，库名 `artdon_new_erp`。等这个提交进入 `main` 并且正式目录已经是同一个提交号之后：

1. 在正式目录执行 `php yifei_sync/migrate.php`。脚本只建 `yf_invmb`、`yf_bommd`、`yf_cstmb`、`yf_sync_batches`，可重复执行，不会清空数据。
2. 生成密钥，例如 `php -r "echo bin2hex(random_bytes(24)), PHP_EOL;"`。
3. 把密钥写到仓库外的 `/www/server/artdon_secrets/yifei_sync.php`：

```php
<?php
return array(
    'token' => '这里换成上一步的密钥',
);
```

不要把这个文件提交到 Git。本机联调可以用 `yifei_sync/config.local.php`（已忽略）或环境变量 `YIFEI_SYNC_TOKEN`。示例占位文字 `replace-with-a-long-random-token` 不能当作密钥。

`config.local.php` 里不要写 `allow_preview_without_login`。那是本机临时看页面用的，还必须同时有环境变量 `YIFEI_SYNC_ALLOW_PREVIEW=1`。正式服务器不要打开。

4. 同一把密钥只放在易飞那台 Windows 机器的 `yifei_agent/config.json`。接口地址示例：`https://novlight.com/yifei_sync/api.php`。
5. 用有 `bom.view` 的账号打开 BOM 页的「易飞数据」，或首页「研发 / 成本 / 生产」里的「易飞数据」。能看成本的人还需要 `bom.cost_view`，和现有 BOM 成本权限一致。

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
| `yf_invmb` | 品号 `item_code` | INVMB.MB001 品号，MB002 品名，MB003 规格 |
| `yf_bommd` | 主件 + 元件 | BOMMD.MD001 主件，MD003 元件，MD006 用量 |
| `yf_cstmb` | 品号 | CSTMB.MB007 品号，MB002 期间，MB004 单据号（不是品号），MB005 成本 |
| `yf_sync_batches` | 自增 | 每次请求的批次日志 |

不建 CSTLA、CSTMC。同一请求里重复的 BOM 主件+元件会把用量加总后再 upsert。易飞里已经删除的 BOM 行，本阶段不会从镜像里删掉。

## 明确不做

不写回易飞，不改推广模块，不改微信小程序。
