# Artdon ERP 工作上下文

## 2026-10-23：小程序名片 / 跟进 API 与前端字段对齐

- 本次完成：扩展 `miniapp/api` 与 CRM 拜访/客户模块，字段名与 yada-crm-miniprogram 体验版一致：`card_side` / `ocr` 名片上传；`business_card_back_token` 随 `customer_create` / `contact_save`；拜访类型 `video_meeting`、`daily_followup`；`visit_save` 使用 `participants_text`、`participant_count`、`background_check_notes`、`progress_update`（跟进与平时跟进必填）；附件 `photo_category` = `communication` / `progress` / `background_check`。新增 `includes/crm_visit_miniapp.php`、`miniapp/migrate.php` 补列、契约测试与 `docs/miniapp-api-card-followup.md`；网页 CRM 列表补充新拜访类型标签。
- 修改文件：`miniapp/api/index.php`、`includes/crm_visit_miniapp.php`、`crm_visit.php`、`crm_customer.php`、`miniapp/migrate.php`、`assets/crm/crm.js`、`tools/ci_php_checks.sh`、`tests/miniapp_card_followup_contract.php`、`docs/miniapp-api-card-followup.md`、本文件。
- 检查结果：`php -l miniapp/api/index.php` 及 touched CRM PHP 通过；`php tests/miniapp_card_followup_contract.php` 通过。云环境无 MySQL PDO，未做 live OCR/写库 E2E。
- 服务器部署情况：本环境未部署。合并分支后快进 `artdon_erp` 与 `miniapp/api` 至 `/www/wwwroot/Artdon/`，执行 `php miniapp/migrate.php`。
- Git 提交号及推送状态：`78458c0` 分支 `cursor/miniapp-card-followup-api-15dd`（推送后更新）。
- 尚未完成：合并后在生产库跑 migrate；用体验版小程序走通名片双面、视频会议/平时跟进保存与三类附件上传。

## 2026-10-22：易飞镜像新加坡 Feed API（芯片/驱动/电源）

- 本次完成：新增只读 `GET yifei_sync/feed.php`，供新加坡店（IP `43.160.202.212`）用 `X-Feed-Token` 分页拉取 `yf_invmb` 中芯片（`A5-1-%`）、驱动（`A5-2-%`）、电源（品名含「电源」且排除电线/线材/电缆/电源线）。独立密钥文件 `/www/server/artdon_secrets/yifei_feed.php`（示例 `feed_secret.example.php`），不复用 ingest 的 `yifei_sync.php` / `X-Yifei-Token`。`feed_brand.php` 统一品牌词典；`category` 冲突时 chip > driver > power。`api.php` 未改。
- 修改文件：`yifei_sync/feed.php`、`feed_lib.php`、`feed_brand.php`、`feed_secret.example.php`、`README.md`、`tests/yifei_sync_contract.php`、`.gitignore`、本文件。
- 检查结果：`php -l` 通过新增 PHP；`php tests/yifei_sync_contract.php` 通过（含 Feed 品类/品牌/鉴权/SQLite 样例及既有 ingest 契约）。本机 `php -S` curl：错误 token 401、非白名单 IP 403、正确 token 200 JSON。
- 服务器部署情况：本环境未部署 `/www/wwwroot/Artdon/artdon_erp/`。合并 `main` 后由既有 GitHub Actions 快进；运维须在服务器创建 `yifei_feed.php` 并写入真实 token（代码库不包含）。
- Git 提交号及推送状态：见分支 `cursor/yifei-sg-feed-api-7169`。
- 尚未完成：合并后在新加坡服务器用真实密钥 curl happy path；广州 Nginx 需保证 `X-Real-IP` 或 `X-Forwarded-For` 能反映新加坡出口 IP。

## 2026-10-21：BOM 编辑器从易飞带入单阶 BOM（覆盖物料行）

- 本次完成：`bom.php` 增加「从易飞带入 BOM」：输入易飞主件品号，确认后调用 `yifei_bom_load` 读取 `yf_detail` 单阶 `children`，清空并替换当前 BOM 物料表行；每行映射同 `yf_bom_picker_item`（品号/品名/规格/材料单价+制费+加工、`cost_amount` 逻辑）；`materialId` 留空。行数量取 `effective_qty`（组成用量÷底数×(1+损耗率)），与易飞查询页成本展开一致。空主件、无主件、无子件时 API 报错且前端不改动现有行。
- 修改文件：`bom.php`、`bom_api.php`、`yifei_sync/lib.php`、`tests/yifei_sync_contract.php`、本文件。
- 检查结果：`php -l` 通过相关 PHP；`php tests/yifei_sync_contract.php` 通过（含 A1-1-01-0381 五子件、COMP-BASE 有效用量 0.75、空/缺失主件、无成本权限、页面/API 接线）。云环境未登录 live `bom.php` 做点选验收。
- 服务器部署情况：本环境未部署。合并 `main` 后由既有 GitHub Actions 快进；无新 DDL。
- Git 提交号及推送状态：见分支 `cursor/yifei-bom-load-replace-ac85`。
- 尚未完成：合并后工厂在 live `bom.php` 用例 `A1-1-01-0003` 等验证覆盖替换与保存；无成本账号应能带入行但不带出金额。

## 2026-10-21：BOM 物料名称接入易飞镜像选料（临时桥）

- 本次完成：`bom.php` 物料名称输入联想与「…」选料弹窗增加易飞只读镜像 `yf_invmb` 搜索（品号/品名/规格模糊匹配，支持直接输入品号）；选中后带入品名、规格、品号（行内 `model`）、材料单价（MB057，需 `bom.cost_view`）、加工费列写入制费+加工（MB059+MB060）；不写共享物料主数据、`materialId` 留空。新增 `bom_api.php` 动作 `yifei_invmb_search` 与 `yifei_sync/lib.php` 映射函数 `yf_bom_picker_item`。
- 修改文件：`bom.php`、`bom_api.php`、`yifei_sync/lib.php`、`tests/yifei_sync_contract.php`、本文件。
- 检查结果：`php -l` 通过相关 PHP；`php tests/yifei_sync_contract.php` 通过（含选料映射与页面/API 接线断言）。云环境未登录 live `bom.php` 做点选验收。
- 服务器部署情况：本环境未快进 `/www/wwwroot/Artdon/artdon_erp/`。合并 `main` 后按既有流程 GitHub Actions 快进；无新 DDL，仅需已有 `yf_*` 镜像表与同步数据。
- Git 提交号及推送状态：见分支 `cursor/bom-yifei-material-picker-7cdf`。
- 尚未完成：合并后工厂在 live `bom.php` 编辑 BOM：物料名称输入品号应出现「易飞」联想；「…」切「易飞镜像」搜索并保存一行；无成本权限账号应能选料但不带出金额。
- 补充修复（同分支）：`yf_search` 非空关键词原先只匹配品号/品名且经 `yf_cstmb` UNION 偏成品号，未搜规格 MB003；改为直接 `yf_invmb` 品号/品名/规格三字段模糊匹配，与 BOM 主件无关。合同测试覆盖「前盖」「灯体」样例。

## 2026-10-21：小程序新增个人派工 1265 修复

- 本次完成：确认 `task_type` 与 `dispatch_mode` 是两个不同字段；单条个人派工应使用 `task_type=personal`、`dispatch_mode=single`。小程序创建接口原来将 `personal` 写入仅接受 `single/multi/plan/recurring` 的派工方式 ENUM，触发 1265。仅更正该映射，不改表、不调整标题/项目长度、不改变本人归属、状态、通知、日志或权限。
- 修改文件：`miniapp/api/index.php` 单行修复；新增 `tests/miniapp_dispatch_create_isolated.php`，登记 `tools/ci_php_checks.sh`，以及本记录。
- 检查结果：本地 PHP 8.0 静态解析通过；服务器临时目录的 PHP 8.0 语法检查及隔离真实处理函数回归通过。修复前线上源码副本准确复现 `dispatch_mode` 1265，修复后源码副本通过个人/私有/本人/他人派工、四种优先级、多行中文、截止标准化、校验、参数隔离与插入失败回滚。网页个人待办排序回归通过；小程序表单保存及失败重试合成回归 10/10。均未创建真实任务，未加载应用初始化或连接业务库。整库 CI 仍有前批已记录的客户负责人筛选基线问题，本批不改动或跳过。
- 服务器部署情况：功能 `a1e781a` 已先推送 GitHub，再同版快进部署。Git 跟踪副本与实际运行 API 分别通过 PHP 语法和实际处理函数隔离回归；线上 API 独有报价改动完整保留，仅应用已提交的同一行映射。已建立仓库外备份；1111 个范围外受保护文件摘要及原有两个无关未提交文件保持原样。未改数据库或重启服务。
- Git 提交号及推送状态：功能 `a1e781a` 已推送 main 并部署，本地/GitHub/服务器在该版核对一致；本提交仅补发布结果，随后同版同步，最终提交和校验留在仓库外运维记录。私有小程序仓库合成表单回归已单文件同步至 `e3c70fb`。无需重新上传或审核小程序。
- 尚未完成：真实微信保存由用户在原页面重试；隔离测试不冒充真实单据验收。本次修复与部署已完成。

## 2026-10-20：报价 Specification 字段顺序统一

- 本次完成：网页、PDF 与 Excel 统一规格字段相对顺序，覆盖自动生成、手工文字和已保存规格。只对明确连续编号的文字作稳定重排；系列、参数值、型号、续行、重复标签和自由备注保留，缺项不补。订单快照、费用和物料销售保持原路径；不读取最新 BOM 重建历史规格，不写回审核快照或主数据。
- 修改文件：`quotation.php`、`crm_quote_pdf.php`、`crm_quote_excel.php`、`assets/quote-spec-order.js`、`includes/quote_spec_order.php`、`assets/quote-display-text.js`；共享合成夹具、排序回归、既有显示/导出回归、CI 检查清单及本记录。
- 检查结果：本地 4 个 PHP 文件静态解析、网页 7 段内嵌 JS 及 27 套前端检查通过；新增排序包含 56 个共享夹具和 128 种插入顺序。隔离临时目录 PHP 8.0 的 4 项语法、424 项排序断言、实际 PDF/XLSX 文字及金额/快照保护回归通过。未操作真实业务单据。
- 补充检查：普通历史明细已有 `specification` 时，网页同样优先原文字再排序；编辑器的新参数明细没有旧规格串，仍实时生成。整库 GitHub 检查的唯一失败为既有客户负责人筛选回归；已核对父版本相同失败，本批不修改或跳过该检查。
- 服务器部署情况：功能提交 `5bb7a36` 已按先本地检查/提交推送、再同版快进同步执行，并在正式目录通过 PHP 语法、424 项排序断言、PDF/XLSX 输出及金额/快照保护复检。非空手工文字直接恢复自动生成的实际处理函数回归通过；公网两个脚本与本地内容摘要一致。每批均保留恢复备份，范围外受保护文件及已有两个无关未提交文件保持原样。
- Git 提交号及推送状态：功能 `5bb7a36` 已推送 main 并部署；本提交仅补发布结果记录，随后同版同步，不追加功能修改。最终提交及三方核对结果留在仓库外运维记录。
- 尚未完成：登录后的指定真实报价预览/导出仍需业务人员刷新后抽查；已有下载文件需重新导出。本次未提交真实保存/审核，不把合成回归冒充真实单据验收。


## 2026-10-19：易飞特征数据加工费同步

- 本次完成：定位录入品号「特征数据」为 INVMB 用户自定义数值列（多为 UDF*）+ DSCSYS/AT `DSCLB` Caption 标题；新增镜像表 `yf_item_feature` 与代理 `feature` 批次（`items`+`rows` 替换语义）。广州页「成本金额」在 MB059、MB060 均为空或 0 时加上特征合计，避免与已回写制费/加工双计。粗胚 `A4-2-01-0254` 合约目标 4.5626（3.18+0.05+1.095+0.2376）。明确不用 INVMI/INVMJ 属性码、BOMME/BOMMF。
- 修改文件：`yifei_agent/sync.py`、`yifei_agent/config.example.json`、`yifei_agent/README.md`、`yifei_sync/lib.php`、`yifei_sync/page.php`、`yifei_sync/api.php`、`yifei_sync/migrate.php`、`yifei_sync/README.md`、`tests/yifei_sync_contract.php`、本文件。
- 检查结果：`php -l` 通过相关 PHP；`python3 yifei_agent/sync.py --self-check` 通过；`php tests/yifei_sync_contract.php` 通过（含 A4-2-01-0254、双计防护、特征清空）。未做屏幕录像。
- 服务器部署情况：按本次要求不部署 `/www/wwwroot/Artdon/artdon_erp/`。合并后执行 `php yifei_sync/migrate.php`；Windows 覆盖 `sync.py` 后 `py -3.13 sync.py --full`；DSCSYS 需给只读账号 `DSCLB` SELECT。
- Git 提交号及推送状态：见分支 `cursor/yifei-feature-cost-c9b3` 最新提交。
- 尚未完成：现场核对 UDF 列号与 DSCLB 标题是否与配置一致；用 `bom.cost_view` 打开真实 `A4-2-01-0254` 对照易飞页头单位成本约 4.573。

## 2026-10-19：易飞成本金额改为材料+制费+加工

- 本次完成：查询页用来和易飞报表对照的主数字改为成本金额，等于单位标准材料 + 单位标准制费 + 单位标准加工（MB057+MB059+MB060）。人工、本阶栏和 CSTMB 账上最新单位成本都保留，但不再加进这个金额，CSTMB 也不再红字强调，并标明「不是成本金额」。列表同样把成本金额放在主列。BOM 表尾增加材料、制费、加工、成本金额四列单阶合计。
- 特征工艺：CNC 开料、加工在易飞特征数据 / 产品工艺路线上。公开说明里，非采购件的加工取工艺路线委外单价，执行「计算标准成本」后回写 MB060，制费回写 MB059，本阶在 MB062、MB063。这些列上一版已经在同步。不另读 BOMME、BOMMF，避免和回写后的会计栏重复，委外单价列名也没有核实。子件单位标准制造费用经常是 0，费用在主件本阶或已卷进主件单位标准制费/加工，所以子件制费+加工合计和主件成本金额会有差额，页面上有说明。
- 成品 A1-1-01-0381：单阶材料合计应对报表材料栏约 153.8348。页头成本金额对照报表成本金额约 171.9508。主件三项相加现场约 172.05，差约 0.1，可以接受，不再为这 0.1 加表。差额可能是人工尾数、进位，或尚未回写的特征工艺零头。
- 修改文件：`yifei_sync/lib.php`、`yifei_sync/page.php`、`yifei_sync/README.md`、`yifei_agent/README.md`、`tests/yifei_sync_contract.php`、本文件。没有改 `yifei_agent/sync.py`。
- 检查结果：`php -l` 通过 `yifei_sync/lib.php`、`yifei_sync/page.php`、`tests/yifei_sync_contract.php`。`php tests/yifei_sync_contract.php` 通过，其中包含代理自检、未登录跳转，以及本机 MySQL 上的 `migrate.php` 和 curl。样例主件成本金额为 172.4508（材料 171.9508 + 加工 0.5，人工 1 不计入，制费未同步）；表尾材料 126.3、制费 2、加工 2、成本金额 130.3。另一组 153.8348 + 10 + 8.116 = 171.9508，本阶制费不重复加。没有 `bom.cost_view` 时不输出这些金额。
- 服务器部署情况：按本次要求不部署，也没有把分支同步到 `/www/wwwroot/Artdon/artdon_erp/`。合并进 `main` 之后由既有 GitHub Actions 快进。这次没有新列；快进后执行一次 `php yifei_sync/migrate.php` 即可。Windows 上的 `sync.py` 若已经会推 MB057、MB059、MB060，不用重新打包，也不用为这一页再跑同步。若那份还是更早的版本，用当前 `yifei_agent` 覆盖后再执行 `py -3.13 sync.py --once`。
- Git 提交号及推送状态：本提交，分支 `cursor/yifei-cost-amount-79f8`。正式目录未快进。
- 尚未完成：合并后用有 `bom.cost_view` 的账号打开 A1-1-01-0381，页头成本金额应接近 171.9508（差约 0.1 可接受），列表不应再把 6.22 标成主数字，表尾能看到材料、制费、加工、成本金额合计。

## 2026-10-19：易飞标准材料成本与单阶材料合计

- 本次完成：易飞只读同步增加品号会计页标准成本，BOM 详情在保留 CSTMB 账上最新单位成本的同时，按单阶计算材料合计。材料合计 = 元件单位标准材料成本 × 组成用量 / 底数 × (1+损耗率)。底数空或 0 按 1，损耗空按 0。最近进价只保存对照，不进合计。标准进价列名未核实，不同步。MB059/MB060/MB063 按相邻栏位推断，代理发现列不存在会跳过，不让整次品号同步失败。旧批次没有这些键时不覆盖已有标准成本。
- 修改文件：`yifei_agent/sync.py`、`yifei_agent/README.md`、`yifei_sync/lib.php`、`yifei_sync/page.php`、`yifei_sync/migrate.php`、`yifei_sync/README.md`、`tests/yifei_sync_contract.php`、本文件。
- 检查结果：`php -l` 通过。`python3 yifei_agent/sync.py --self-check` 通过。`php tests/yifei_sync_contract.php` 通过。样例成品 A1-1-01-0381 的账上成本 6.229167、主件单位标准材料成本 171.9508 与单阶材料合计 126.3 同时出现；缺标准材料成本 1 个。126.3 是合成单阶数据，不是易飞报表实数。没有 `bom.cost_view` 时不输出这些金额。
- 服务器部署情况：按本次要求不部署，也没有把分支同步到 `/www/wwwroot/Artdon/artdon_erp/`。合并进 `main` 之后由既有 GitHub Actions 快进。快进后在正式目录执行 `php yifei_sync/migrate.php`（补列，不清数据），再在 Windows 上执行 `py -3.13 sync.py --once`。
- Git 提交号及推送状态：本提交，分支 `cursor/yifei-std-material-rollup-0a42`。正式目录未快进。
- 尚未完成：现场重跑同步后，用 A1-1-01-0381 对比页头 MB057 与表尾单阶材料合计是否接近 171.9508。若单阶仍有差距，原因可能是多阶 BOM、虚设件未展开、底数/损耗未重推，或报表改用最近进价。本阶段不展开多阶。

## 2026-10-19：易飞查询页登录跳转修复

- 本次完成：未登录打开 `yifei_sync/index.php` 时，登录地址改为站点根目录的 `login.php`（由脚本路径向上两级算出，例如 `/artdon_erp/login.php`），并用 `redirect` 带回原来的易飞查询页。不再跳到不存在的 `yifei_sync/login.php`。其他模块的相对 `login.php` 没有改。
- 修改文件：`yifei_sync/lib.php`、`yifei_sync/bootstrap.php`、`yifei_sync/index.php`、`yifei_sync/README.md`、`tests/yifei_sync_contract.php`、本文件。
- 检查结果：`php -l` 通过。`php tests/yifei_sync_contract.php` 通过。未登录请求 `/artdon_erp/yifei_sync/index.php?q=散热器` 返回 302，`Location` 为 `/artdon_erp/login.php?redirect=` 加上原来的查询页地址，不再指向 `yifei_sync/login.php`。`auth_safe_redirect` 接受该回跳地址。
- 服务器部署情况：按本次要求不部署，也没有把分支同步到 `/www/wwwroot/Artdon/artdon_erp/`。合并进 `main` 之后由既有 GitHub Actions 快进。
- Git 提交号及推送状态：本提交，分支 `cursor/yifei-login-redirect-ad9a`。正式目录未快进。
- 尚未完成：合并后用未登录浏览器打开 `/artdon_erp/yifei_sync/index.php`，应进入 `/artdon_erp/login.php`，登录成功后回到该查询页。

## 2026-10-19：易飞只读同步 P1

- 本次完成：广州增加易飞只读接收接口、`yf_*` 镜像表和中文查询页；仓库里另有 Windows 代理骨架。本地易飞（SQL Server 库 AT）只读查询后用 HTTPS 推送，广州不连接易飞，也不写回。字段只使用已核实的 INVMB（MB001 品号、MB002 品名、MB003 规格）、BOMMD（MD001 主件、MD003 元件、MD006 用量）、CSTMB（MB007 品号、MB002 期间、MB004 单据号、MB005 成本）。不使用 CSTLA、CSTMC。每个品号只保留最新期间的成本；较旧期间不会覆盖。char 尾部空格按 RTRIM 处理。
- 查询页 `yifei_sync/index.php` 沿用 BOM 登录（`bom.view`）。没有 `bom.cost_view` 时页面不输出成本数字。入口在 BOM 页页签和总览按钮、首页 BOM 卡片下的「易飞数据」，以及首页「研发 / 成本 / 生产」里的只读卡片。
- 修改文件：`yifei_sync/`（`api.php`、`migrate.php`、`lib.php`、`page.php`、`index.php`、`config.example.php`、`README.md`）、`yifei_agent/`（`sync.py`、`config.example.json`、`requirements.txt`、`README.md`、`fixtures/sample_batches.json`）、`bom.php`、`index.php`、`.gitignore`、`tests/yifei_sync_contract.php`、`tools/ci_php_checks.sh`、本文件。
- 检查结果：`php tests/yifei_sync_contract.php` 通过。其中包含建表脚本、幂等 upsert、尾部空格、BOM 用量合并、较旧期间不覆盖样例成本 27.564722、按品号和品名查询、无成本权限不输出金额、错误密钥 401、MySQL 上 `php yifei_sync/migrate.php`、curl 假数据、`python3 yifei_agent/sync.py --push-file`。未登录打开查询页会 302 到 `login.php`。浏览器里用样例数据查了品号 `A4-2-01-4286`、品名「散热器」、不存在的关键词，以及约 390px 窄屏。
- 服务器部署情况：按本次要求不部署，也没有把分支同步到 `/www/wwwroot/Artdon/artdon_erp/`。合并进 `main` 之后，正式目录由既有 GitHub Actions 快进；快进后再执行 `php yifei_sync/migrate.php`，并把共享密钥写到仓库外的 `/www/server/artdon_secrets/yifei_sync.php`。密钥不要提交。
- Git 提交号及推送状态：见本分支提交。正式目录未快进。
- 尚未完成：易飞那台 Windows 机器安装只读账号并填写本机 `yifei_agent/config.json` 后才能真正推送。本阶段不删除易飞里已经去掉的 BOM 行，也不做反写。增量水位默认关闭，需现场确认 `MODI_DATE` / `CREATE_DATE` 后再开。

## 2026-10-16：旧客户中心高级筛选 · 负责人两种匹配

- 上一步：邮箱系统第一批（一），`centers/mail/`，提交 `13582a3`。本次只改旧版 `crm.php` 客户中心高级筛选，不改新客户中心。
- 本次完成：高级筛选「负责人」旁增加匹配方式。**仅第一负责人**（默认）只匹配列表里显示的第一负责人：`crm_customer_owners` 里 `is_primary = 1` 或 `role_type = 'primary'` 的第一条，没有这种记录时才用客户表 `owner_user_id`。**含协助**保持原来的宽匹配（`owner_user_id` 或 `crm_customer_owners` 任意一行）。快捷筛选「我的客户」仍含协助人。列表和导出都走 `crm_customer_list`，参数名 `owner_match`：`primary`（缺省、空值或其他值）或 `any`。
- 新客户中心 `centers/customer` 的负责人下拉仍是「第一负责人或协助」（`CustomerScope::mineSql`），这次没有改。
- 修改文件：`crm.php`、`crm_customer.php`、`assets/crm/crm.js`、`assets/crm/crm.css`、`tests/crm_customer_owner_filter_contract.php`、`tools/ci_php_checks.sh`、本文件。
- 检查结果：见本分支提交说明。未连正式库，没有用 sukie / Winnie 的正式账号点选。
- 服务器部署情况：本环境不能把分支检出到 `/www/wwwroot/Artdon/artdon_erp/`。正式目录只在本分支合并进 `main` 后由 GitHub Actions 快进。
- Git 提交号及推送状态：见本分支提交。正式目录未快进。
- 尚未完成：合并后用正式账号在旧客户中心选 sukie「仅第一负责人」，结果里不应再出现只把 sukie 列为协助的客户；改选「含协助」后那些客户应重新出现。

## 2026-10-16：邮箱系统第一批（一）按原型重做界面 · 风格 C（PR #26）

- 起因：用户给了推广中心原型（`preview.html`），认为第一版邮箱界面太简单，“只学了颜色风格，功能差得远”。用户选择：先重做邮箱、再改客户中心；整体改用原型的**风格 C（卡片画廊 + 侧边抽屉）**；邮箱主界面用“工作台 + 三栏收件箱”。
- 本次完成：
  - 底座 `base.css` 换成风格 C：深色侧栏、浅灰底、白色大圆角卡片带阴影，主色靛蓝 #4f46e5、强调玫红 #e11d48，字号 13.5px、圆角 10/14px（客户中心、系统中心同时变色，结构不变）。新增公共组件：侧栏里当前中心的二级导航（`Layout::start` 的 `subnav`）、指标卡 + 迷你柱状图、标签胶囊 / 多维筛选矩阵、进度条、行为徽章、卡片画廊 + 右侧抽屉、首字母头像、列表卡片。
  - 邮件工作台 `centers/mail/index.php`：4 个指标卡（今日收信带 7 天柱状图和比昨天、收件箱未读及其中客户来信、待回复跟进及最早几天没回音、本周客户来信带柱状图及 30 天往来客户数）；“最近 30 天收件”四维标签（分类：报价 / 样品 / 售后 / 推广 / 普通带数量；客户关联；处理状态；附件与时间），点标签直接进入收件箱筛选；客户来信还没回复；发出后没回音；往来最多的客户（30 天，进度条、收/发徽章）；右侧抽屉：可能是客户的来信（一键关联）、邮箱状态。全部是数据库真实数据（`MailDashboard`）。
  - 收件箱 `inbox.php` 三栏：文件夹（未读数）| 列表（搜索、时间、分类标签带数量、未读 / 有附件 / 客户邮件 / 未关联标签、首字母头像、分类与客户标签、星标、批量操作）| 右侧直接读信（不跳页；关联客户卡片、附件卡片、正文沙箱 iframe；“这个客户的全部邮件”）。手机上打开邮件只显示读信栏，带返回。`read.php` 改为转到收件箱里打开。
  - 邮件分类规则与旧系统一致（手动分类优先，否则按主题关键词，推广 → 报价 → 样品 → 售后），SQL 筛选与 PHP 判断同一套关键词。
  - 邮箱设置 `accounts.php`：统计卡 + 邮箱卡片画廊（状态、默认、30 天收发、未读、总数）+ 右侧抽屉（测试收发、设置明细、签名预览、设为默认 / 停用 / 删除）。
- 修改文件：`centers/_base/assets/base.css`、`centers/_base/src/Layout.php`、`centers/mail/{index,inbox,read,accounts}.php`、`centers/mail/src/{Mailbox,MailDashboard,MailAccounts,MailView}.php`、`centers/mail/assets/{mail.css,mail.js}`、`centers/mail/tests/mailbox_contract.php`、本文件。
- 检查结果：`mailbox_contract.php` 99 项通过（新增分类与筛选一致性、工作台统计、邮箱统计）；本地浏览器邮箱 63 项通过（工作台、三栏读信、标签筛选、关联、批量、邮箱设置卡片与抽屉、手机读信与返回、只读查看、权限）；客户中心与系统中心浏览器测试回归通过；`tools/ci_php_checks.sh` 契约 87 项、`tools/ci_js_checks.sh` 26 项通过。
- 部署情况更正：10-15 18:49 用户叫停前，合并 PR #25 的请求已经执行，第一版邮箱界面（`13582a3`）当时已合并并部署到服务器（部署成功）。重做的界面没有进 PR #25，改为从 main 新建分支 `claude/mail-1a-ui` 提 PR #26。用户 10-16 确认“上线吧”。
- 下一步：PR #26 合并部署后，服务器运行 `php centers/_base/tools/mail_setup.php`；然后按同样结构改客户中心；再做邮箱第一批（二）写信、回复、转发、草稿。

## 2026-10-15：邮箱系统第一批（一）· 邮箱账号、收件箱、读信（新系统）

- 上一步：PR #24 邮箱规则清单，服务器 `fe61a74`。用户确认 6 项决定：过渡期旧定时任务继续收信、新系统发信时自己立即发；一次性工具迁移加密种子；`mail.account_manage_all` 只管账号不能读信，读别人的信需要新权限 `mail.view_all` 且留日志；客户详情邮件标签按 `customer.mail_summary`；撤回保留（改名“发送撤回通知”）；产品资料附件、Office 预览放第三批。
- 本次完成（`centers/mail/`，只读写数据库，不引用旧代码）：
  - 邮箱设置 `accounts.php`：绑定多个邮箱（常用邮箱类型自动填服务器）、修改（授权码留空不改）、测试收信 / 发信（自写 socket IMAP/SMTP，显示服务器原因）、设为默认、停用、删除（只做标记，已收邮件保留；旧系统会连邮件一起删）；发件人名称、签名。授权码与旧系统同算法加密，页面和日志里都不出现。
  - 邮箱 `index.php`：收件箱、已发送、未读、星标、未回复（发出 3–60 天、对方之后没来信、没标记已处理）、有附件、已关联 / 未关联客户、已删除；多邮箱时可按邮箱筛选；搜索主题、发件人、收件人、正文、附件名；已读未读、时间筛选；分页 30 封；批量标已读 / 未读、加星标、删除、恢复；列表点星标直接切换。“已发送”隐藏重复（规则同旧系统，改在 PHP 里算）。
  - 读信 `read.php`：收发件人、抄送、时间、收信邮箱；附件下载（`mail.attachment_download`，路径必须在 storage 下）；正文 `body.php` 放在无脚本沙箱 iframe 里并带 CSP，去掉脚本、事件、表单、meta 跳转，内嵌图片（cid、旧系统附件地址、storage 路径）换成新地址；打开即标已读。关联客户：按客户/联系人邮箱、客户域名给建议（公共邮箱不猜、只建议看得到的客户），一键关联或搜索选择，自动对上联系人；可取消关联。
  - 删除只置 `is_deleted=1`（保留文件夹）；旧系统删除的（folder=deleted）也算已删除，恢复时按来源回到收件箱或已发送。
  - 查看全部邮件：有 `mail.view_all` 可选人查看，只读、不改已读状态，每次读信记日志 `mail_view_other`。
  - 导航“邮箱系统”可点击。工具 `centers/_base/tools/mail_setup.php`：从旧配置 app_key / db_name / 程序目录里找出能解开已有授权码的种子写入 `centers/config.php`，补权限 `mail.view_all`、`customer.mail_summary`。
- 修改文件：`centers/mail/{index,read,body,attachment,accounts,api,bootstrap}.php`、`centers/mail/src/{MailCrypto,MailProtocol,DeliveryUncertain,MailAccounts,Mailbox,MailBody,MailView}.php`、`centers/mail/assets/{mail.css,mail.js}`、`centers/mail/tests/{schema,mailbox_contract}.php`、`centers/_base/tools/mail_setup.php`、`centers/_base/src/Nav.php`、`centers/README.md`、`tools/ci_php_checks.sh`、本文件。
- 检查结果：`mailbox_contract.php` 83 项通过（加密与旧系统兼容、种子识别、账号权限、假服务器测试登录、列表各文件夹、已发送去重、未回复、读信、附件权限与路径、查看别人邮件只读留日志、正文清理）；本地浏览器邮箱 50 项通过（含手机宽度、脚本不执行、内嵌图片、下载文件名）；`tools/ci_php_checks.sh` 契约 87 项、`tools/ci_js_checks.sh` 26 项通过。
- 需要用户在服务器做：部署后运行 `php centers/_base/tools/mail_setup.php`，再到权限中心把 `mail.view_all` 分给需要的人。
- 尚未完成 / 下一步：邮箱第一批（二）写信、回复、全部回复、转发、附件、草稿、立即发送（新系统自己发，写成旧系统格式的已发送记录）；然后第二批（新收信程序与定时发送、撤回通知、从邮件建客户、客户详情邮件标签、未回复提醒、新邮件通知）。

## 2026-10-15：邮箱系统 · 旧邮箱规则清单（待用户确认，无代码修改）

- 上一步：PR #23 客户中心第二批（三），服务器 `3d29df0`（已确认部署成功）。客户中心除邮件/报价/订单汇总外已完成。
- 本次完成：读旧代码 `crm_mail.php`（5665 行）、`crm_mail_cron.php`、`crm_mail_sent_visibility.php`，整理邮箱规则清单交用户确认。要点：
  - 表：crm_user_mail_accounts、crm_mails、crm_mail_attachments、crm_mail_drafts、crm_mail_sync_logs、crm_mail_send_jobs、crm_mail_signature_templates。
  - 邮箱密码用 AES-256-CBC 加密，密钥由旧配置 includes/config.php 的 app_key（没有则 db_name）算出；新系统要用同一个种子才能解密。
  - 收信和定时发送由服务器定时任务 `php crm_mail_cron.php` 做（默认每 3 分钟、每次 30 封，首次补收 300 封；失败按 2/5/10/20 或 5/10/30/60 分钟退避）。收件箱和发件箱都从 IMAP 收；用自写的 socket IMAP/SMTP。
  - 只能看自己的邮箱；有 mail.account_manage_all 的管理员可以“代操作”别人的邮箱（包括读信）。
  - 自动关联客户：发件人/收件人邮箱等于客户邮箱或联系人邮箱。未回复：发出 3 天没有对方回信、没有跟进记录、没标记已处理。撤回：实际是给收件人发一封“撤回通知”。
  - 附件存在 storage/mail_attachments/{用户}/{邮件}/，原始邮件存 storage/mail_raw/；旧代码有按路径直接访问附件的写法。
  - 另有：签名模板与批量应用、产品资料（datasheet）PDF 附件（用 Chrome 生成）、Office 附件预览（LibreOffice）、远程图片代理、从邮件建客户/暂存/跟进/任务/派工、新邮件通知、诊断。
- 待用户确认的决定：过渡期收信谁来做、密钥种子迁移、管理员能否读别人的信、客户详情邮件标签的可见范围、撤回通知是否保留、产品资料/Office 预览是否还在用、分批范围。
- 修改文件：本文件。
- 下一步：用户确认后开始邮箱系统第一批。

## 2026-10-15：客户中心第二批（三）· 导入、导出、名片识别（新系统）

- 上一步：PR #22 第二批（二），服务器 `d4d51fb`（已确认部署成功）。
- 本次完成：
  - 导入 `centers/customer/import.php`：上传 .xlsx / .csv（UTF-8 或 GBK；服务器没有 ZipArchive 时用内置解压读 .xlsx）→ 逐行检查（名称、国家必填，国家/等级/阶段/来源/推广方式按中文、英文或代码认，分组按名称，负责人按姓名或账号且需要分配权限，联系人 1-3，已有同名、客户代码重复、文件内重复不导入，疑似重复给提示）→ 确认导入。高度疑似重复按查重规则进暂存池或不导入；准入规则为“只有导入的进暂存池”时全部进暂存池。预览存在服务器临时目录，2 小时有效、只有上传人能用、同一份不能导入两次。可下载模板。需要 `customer.import`。
  - 导出：客户列表“导出”按当前标签和筛选导出看得到的客户为 CSV（UTF-8 带 BOM，Excel 直接打开；表头与导入一致，可改后再导入；防公式注入；最多 2 万个）。需要 `customer.export`。导入、导出都写客户日志和敏感审计。
  - 名片识别：新建客户弹窗“识别名片”，调用腾讯云名片识别（密钥读服务器 `/www/server/artdon_secrets/tencent_ocr.php`，签名方式同旧系统），自动填客户和主联系人；照片压到 1MB 内暂存在 `storage/customer_files/pending_business_cards`，建客户时移到 `storage/customer_files/{客户编号}/` 并记 `crm_customer_files`（category=business_card）；进暂存池的，确认入库时一起存。详情概览显示名片图（跟着客户可见范围），`customer.file_delete` 可删除。
- 修改文件：`centers/customer/{import.php,export.php,card.php,index.php,view.php,lead.php,api.php}`、`centers/customer/src/{Spreadsheet,CustomerImport,CustomerExport,BusinessCard,CustomerService,LeadPool,CustomerLog}.php`、`centers/customer/assets/*`、`centers/customer/tests/{import_export_contract,schema}.php`、`centers/README.md`、`tools/ci_php_checks.sh`、本文件。
- 检查结果：`import_export_contract.php` 60 项（含 GBK、Excel、无 ZipArchive 解压、导入规则、导出范围、名片解析与签名、名片文件权限）及客户中心前三组契约全部通过；本地浏览器导入导出 10 项及其他九组全部通过；`tools/ci_php_checks.sh` 契约 86 项全部通过；`tools/ci_js_checks.sh` 26 项通过。
- 待用户验证：正式环境名片识别（需要服务器上已有腾讯云密钥文件，旧系统在用应该已有）。
- 尚未完成 / 下一步：客户中心第三批（详情里的邮件、报价、订单等汇总，等对应中心做好再接）；按原计划下一个是邮箱系统。

## 2026-10-15：客户中心第二批（二）· 分组、多地址、聊天群、客户关系（新系统）

- 上一步：PR #21 第二批（一）暂存池与合并，服务器 `f01a45e`（已确认部署成功）。
- 本次完成：
  - 分组 `centers/customer/groups.php`：新建、改名、颜色、排序、删除（软删除，客户不受影响，不再参与数据范围；重建同名分组时启用原分组并清空旧关联）；客户列表按分组筛选；勾选客户后批量“加入 / 只放在 / 移出”分组；新建、编辑客户时勾选分组。管理需要 `customer.batch`（与旧系统一致）。
  - 详情新标签“地址”：新增、编辑、设为主地址、删除（主地址不能删）；主地址变化同步客户资料的国家、城市、地址（旧系统显示也用主地址）。类型读字典 address_type。
  - 详情新标签“聊天群”：微信群 / WhatsApp 群，群主、群里的联系人（只能选本客户联系人）、是否可用于推广、状态（正常 / 暂停 / 已失效）、备注；新增、编辑、删除（软删除）。
  - 详情新标签“关系”：登记“对方是这个客户的 ××”（类型读字典 customer_relation_type），输入名称、代码或编号查找对方客户（只能选看得到的）；两边都显示（母公司 / 子公司自动反过来），看不到的客户不显示名称；删除。需要 `customer.graph_manage`。
  - 地址、聊天群需要 `customer.edit`，且客户在自己范围内（公海先领取）。合并客户后只保留一个主地址。
  - 底座：`.table-wrap` 加 `position: relative`，修复手机上表格里隐藏的读屏文字把整页撑出横向滚动的问题（所有列表页都受益）。
- 修改文件：`centers/customer/{groups.php,index.php,view.php,api.php}`、`centers/customer/src/{GroupService,CustomerDetails,CustomerService,CustomerScope,CustomerForm,CustomerMerger,CustomerLog}.php`、`centers/customer/assets/*`、`centers/customer/tests/details_contract.php`、`centers/_base/assets/base.css`、`centers/README.md`、`tools/ci_php_checks.sh`、本文件。
- 检查结果：`details_contract.php` 49 项及客户中心前两组契约全部通过；本地浏览器：分组/地址/聊天群/关系 27 项、暂存池与合并 22 项、客户中心 39 项、系统中心七组全部通过；`tools/ci_php_checks.sh` 契约 85 项全部通过；`tools/ci_js_checks.sh` 26 项通过。
- 尚未完成 / 下一步：第二批（三）导入导出、名片识别。

## 2026-10-15：客户中心第二批（一）· 暂存池处理与合并客户（新系统）

- 上一步：PR #20 客户中心第一批，服务器 `9a56758`。第二批分三小批：（一）暂存池与合并（本次）；（二）分组管理、多地址、聊天群、关系图谱；（三）导入导出、名片识别。
- 本次完成：
  - 暂存池 `centers/customer/leads.php`（待处理 / 有疑似重复 / 已处理 / 已丢弃，搜索）与 `lead.php`：打开时重新查重；确认入库（核对、修改资料后建成正式客户，联系人一起建；负责人默认暂存里写的负责人或提交人）；关联已有客户（可选把联系人补过去，邮箱或电话已有的跳过）；丢弃（必须写原因）。处理后通知提交人。能读旧系统写入的各种格式（raw_ 字段、contacts_json、contact_ 字段、逗号分隔），格式不对的联系人邮箱挪到备注不丢。两人同时处理时只有一个成功。
  - 合并客户：列表勾选 2-10 个 →“合并”选主客户；详情“查重”标签 →“合并进来”。做法同旧系统（转移联系人、地址、聊天群、跟进、文件、事件、时间轴、日志、任务、拜访、商机、推广、邮件、草稿、暂存池、报价订单、报价客户等；唯一性表复制去重；被合并客户的负责人变协助；被合并客户软删除，原因“合并到客户 #编号：名称”）。新增：主客户空着的资料用被合并客户补上；只保留一个主联系人；主客户在公海时第一个负责人成为第一负责人。需要 `customer.merge`。
  - 底座 `Db::columns()`、`Db::insertIgnore()`。
- 修改文件：`centers/customer/{leads.php,lead.php,index.php,view.php,api.php}`、`centers/customer/src/{LeadPool,CustomerMerger,CustomerService,CustomerLog}.php`、`centers/customer/assets/*`、`centers/customer/tests/{leads_merge_contract,schema}.php`、`centers/_base/src/Db.php`、`centers/README.md`、`tools/ci_php_checks.sh`、本文件。
- 检查结果：`leads_merge_contract.php` 46 项、`customer_contract.php` 99 项通过；本地浏览器暂存池与合并 22 项、客户中心 39 项通过；`tools/ci_php_checks.sh` 契约 84 项全部通过；`tools/ci_js_checks.sh` 26 项通过。
- 尚未完成 / 下一步：第二批（二）分组管理、多地址、聊天群、关系图谱。

## 2026-10-15：客户中心第一批（新系统）

- 上一步：PR #19 记录第二批范围，服务器 `649701d`。
- 本次完成（按已确认的 9 项决定）：
  - 列表 `centers/customer/index.php`：我的客户、全部客户、公海、已删除（按权限显示），搜索（名称、代码、邮箱、电话、联系人）、国家（代码/中文/英文都能匹配旧数据）、等级、阶段、负责人、7/15/30 天未跟进与从未跟进、新增时间、没有联系人；批量分配、批量转入公海；公海领取；已删除恢复。
  - 详情 `centers/customer/view.php`：概览（资料、负责人、资料还缺、最近跟进）、联系人（新增、编辑、删除、主联系人、已离职）、跟进（只读）、日志（新旧系统写的客户日志）；编辑、分配、转入公海、删除、领取、恢复。
  - 新建：先查重（和全部未删除客户比对，规则同旧系统，公共邮箱如 gmail 不按域名算重复），有疑似重复先列出让用户确认；再按正式库 `crm_rule_configs` 的准入规则和查重规则决定直接建成正式客户或进入暂存池（`crm_lead_pool`，资料格式旧系统能读，暂存池处理第二批前仍在旧系统做）。同名、客户代码不能重复。
  - 编辑不能改负责人；分配要 `customer.assign`，替换原负责人并通知；转入公海清掉全部负责人；公海客户要先领取才能编辑和维护联系人；删除要填原因，只做软删除和恢复。
  - 客户数据写正式库已有表：crm_customers、crm_customer_addresses（主地址同步）、crm_customer_owners、source_tags、promotion_channels、promotion_status、protection、crm_contacts；日志写 crm_logs（前缀“[新系统]”）、crm_customer_timeline、crm_sensitive_audit_logs、crm_audit_logs。
  - 底座：`Layout` 支持页面样式表 `styles`；`base.js` 接口表单成功时触发可取消的 `centers:api-success` 事件；导航“客户中心”可用。
- 修改文件：`centers/customer/**`（新）、`centers/_base/src/{Layout,Nav}.php`、`centers/_base/assets/base.js`、`centers/system/tests/stage1_contract.php`、`centers/README.md`、`tools/ci_php_checks.sh`、本文件。
- 检查结果：`customer_contract.php` 99 项通过（含查全部客户、公共邮箱、准入规则各模式、可见范围、公海、分配、删除恢复、联系人）；本地无头浏览器客户中心 39 项及其他七组全部通过；`tools/ci_php_checks.sh` 契约 83 项全部通过；`tools/ci_js_checks.sh` 26 项通过。
- 尚未完成 / 下一步：客户中心第二批：暂存池处理、合并、分组管理、导入导出、名片识别、多地址、聊天群、关系图谱。

## 2026-10-15：客户中心范围补充（无代码修改）

- 上一步：PR #18 底座收尾，服务器 `4c25a7e`。
- 用户确认：名片识别、多地址、聊天群、关系图谱都还在用，排进客户中心第二批（与暂存池处理、合并、分组管理、导入导出一起）。
- 已查明：名片识别调用腾讯云 OCR，密钥在服务器 `/www/server/artdon_secrets/tencent_ocr.php`（不在仓库里）；名片图片存在 `storage/customer_files/` 下。新系统做名片识别时读同一个密钥文件和同一个存储目录，不调用旧代码。
- 准入规则：新系统直接读正式库 `crm_rule_configs` 里的配置（customer_admission、duplicate_detection、lead_pool），不需要用户手动提供。
- 修改文件：本文件。
- 下一步：客户中心第一批。

## 2026-10-15：底座收尾 · 站内通知与业务公共件（新系统）

- 上一步：PR #17 记录客户规则清单，服务器 `7739bde`。用户回复“按你建议的”，客户中心 9 项决定全部确认：
  1. 可见范围：`customer.view_all` / `customer.view_department` 继续有效，权限中心设置的客户数据范围同时生效，取较大者；
  2. 公海单独页面，有领取权限的人都能看到并领取；转入公海时清掉全部负责人；
  3. 查重查全部客户（不再只看最近 500 个）；准入规则沿用正式库 `crm_rule_configs` 现有配置；
  4. 改负责人只能通过“分配”，需要 `customer.assign`，编辑页不能改负责人；
  5. 新系统不做强制删除，只做删除与恢复；
  6. 不做自动掉公海，继续手动转入；
  7. 暂存池“关联已有客户”时可选择把联系人补到已有客户；
  8. 字典先只读沿用旧数据，维护功能以后加到新系统设置中心；
  9. 分三批：第一批列表（我的/全部/公海+筛选）、详情（概览、联系人、日志）、新建（查重+暂存池）、编辑、分配、公海转入与领取、删除与恢复；第二批暂存池处理、合并、分组管理、导入导出；第三批邮件、报价、订单等汇总。
  仍待用户答复：名片识别、多地址、聊天群、关系图谱是否还在用（用的话排进第二批）；正式库准入规则配置（`crm_rule_configs` 中 customer_admission、duplicate_detection、lead_pool）。
- 本次完成：
  - 站内通知：顶栏铃铛（未读数）、`centers/system/notifications.php`（全部/未读、点开标为已读并跳转、全部标为已读）。写正式库已有的 `sys_notifications`（只用建表时就有的字段，类型以 `centers.` 开头，新系统只显示自己的通知，不动旧系统通知）。
  - 权限申请通知：提交时通知所有能审批的人（按审批规则逐个判断，不含申请人和停用账号；紧急申请标题带【紧急】）；批准、拒绝、延长、撤销时通知申请人或授权人。
  - 业务公共件：`Dictionary`（只读字典）、`DataScope`（数据范围按决定 1 计算）、`UserDirectory`（正常账号，按部门分组）。
- 修改文件：`centers/_base/src/{Notify,Dictionary,DataScope,UserDirectory,Layout,Icon}.php`、`centers/_base/assets/base.css`、`centers/_base/tests/{harness,services_contract}.php`、`centers/system/{notifications.php,api.php}`、`centers/system/src/RequestService.php`、`centers/README.md`、`tools/ci_php_checks.sh`、本文件。
- 检查结果：`services_contract.php` 47 项及前六组契约测试全部通过；本地无头浏览器七组全部通过（通知 11 项、设置 26 项等）。`tools/ci_php_checks.sh` 契约 82 项全部通过；`tools/ci_js_checks.sh` 26 项通过。
- 尚未完成 / 下一步：明天开始客户中心第一批。

## 2026-10-15：客户中心 · 旧客户规则清单（待用户确认，无代码修改）

- 上一步部署情况：PR #16（设置中心与日志）合并后自动部署成功，服务器 `5b35609`。
- 本次完成：读旧代码 `crm_customer.php`、`crm_settings_config.php`、`crm_api.php`，整理客户模块规则清单交用户确认：可见范围（view_all / view_department / 本人负责或创建；旧代码不用权限中心的数据范围）、新建必填与查重（只比对最近更新的 500 个客户）、准入规则与暂存池、公海（无自动掉公海，保护期字段未使用，普通员工看不到公海）、分配（编辑客户时只要有 customer.edit 就能改负责人）、删除/恢复/强制删除/合并、分组与字典、导入导出、详情页标签。
- 待用户确认的决定：可见范围是否同时采用数据范围设置、公海可见与领取、查重改查全库、改负责人必须有分配权限、强制删除是否不做、是否自动掉公海、暂存池关联已有客户时是否补联系人、字典维护位置、第一批范围。
- 修改文件：本文件。
- 尚未完成 / 下一步：用户确认清单后开始客户中心第一批。

## 2026-10-15：系统中心第 4 步 · 设置中心与日志（新系统）

- 上一步部署情况：PR #15（权限申请与临时授权）合并后自动部署成功，服务器 `d7e9c4f`。
- 本次完成：`centers/system/settings.php` 三个分类：①公司信息（系统名称、公司中英文名、简称、地址、电话、邮箱、网站；沿用 `crm_system_settings` 里已有的键，与旧系统是同一份数据）与 Logo（PNG/JPG/WebP，45KB 内，16-2048 像素，浏览器先预览；以图片数据存 `centers.logo`，不写服务器文件；显示在登录页和侧栏，可去掉恢复字母 A）；②安全设置（存 `centers.security`，只作用于新系统：连续失败锁定次数 1-20、锁定分钟 1-1440、密码最短长度 8-32、闲置自动退出 0 或 10-1440 分钟）；③系统信息（程序版本、PHP、数据库、时区、账号/角色/部门/权限项数量、会话名）。`centers/system/logs.php`：登录日志（账号、结果、来源新/旧系统、日期筛选）和操作日志（操作人、模块、动作、日期筛选，详情弹窗显示修改前后），每页 50 条。导航“设置中心”可用，新增“日志”（`logs.view_own` 或 `logs.view_all` 可见；Nav 权限支持“a|b”）。登录页增加闲置退出提示。旧系统的修复表结构、备份功能不搬。
- 底座：新增 `SystemSettings`（读设置、安全设置带范围保护、Logo 校验）、`Pager`（带省略号的分页条）；`Password` 最短长度和规则说明跟随安全设置；`Auth` 锁定次数和时长读安全设置，新增闲置自动退出（写登录日志）；`Layout` 有 Logo 时显示 Logo；新账号自动生成的密码长度跟随最短长度。
- 接口：settings_company_save、settings_logo_save、settings_logo_remove、settings_security_save，都写 `crm_audit_logs`。
- 修改文件：`centers/_base/src/{SystemSettings,Pager,Password,Auth,Layout,Nav}.php`、`centers/_base/assets/base.css`、`centers/_base/tests/harness.php`、`centers/system/{settings.php,logs.php,login.php,profile.php,api.php}`、`centers/system/assets/settings.js`、`centers/system/src/{SettingsService,LogService,AccountService,ProfileService}.php`、`centers/system/tests/{settings_contract,stage1_contract,accounts_contract}.php`、`centers/README.md`、`tools/ci_php_checks.sh`、本文件。
- 检查结果：`settings_contract.php` 62 项及前五组契约测试全部通过；本地 SQLite + 无头浏览器六组全部通过（设置与日志 26 项）。`tools/ci_php_checks.sh` 契约 81 项全部通过；`tools/ci_js_checks.sh` 26 项通过。
- 尚未完成 / 下一步：整理旧客户模块业务规则清单（查重、保护期、归属分配、可见范围），交用户确认后开始客户中心。审批通知仍未做。

## 2026-10-15：权限中心第 3 批 · 权限申请与临时授权（新系统）

- 上一步部署情况：PR #14（人员权限）合并后自动部署成功，服务器 `715de44`。
- 本次完成：`centers/system/requests.php`，分类：我的申请（所有人）、待我审批（有审批权限的人）、全部申请（可按状态筛选）、临时授权（撤销、延长）。申请：只能选自己还没有的权限，填原因、紧急程度、需要天数（1/3/7/30/90）；同一权限同时只能有一个待审批申请；可取消自己的待审批申请。批准生成临时授权（1-90 天，永久仅超管），拒绝须填原因，撤销须填原因，延长到指定日期当天结束且最多到今天起 90 天内。过期按时间判断，不改数据。导航“系统”下新增“权限申请”（所有人可见），权限中心“申请与授权”标签可用。接口加入 request_submit / request_cancel / request_approve / request_reject / grant_revoke / grant_extend。
- 审批规则（按权限判断，不按角色名称）：不能批自己的；超管和 `dangerous.permission_admin` 可批全部；`permission_request.approve_all` 可批全部普通权限；`permission_request.approve_department` 只能批本部门普通权限；后两者只能批自己也有的权限；高风险（风险等级 high/dangerous 或 `dangerous.` 开头）只能由超管或权限管理员批，且必须写审批备注。撤销和延长：超管、权限管理员或 `permission_request.revoke`。所有操作写 `crm_permission_request_logs` 和 `crm_audit_logs`。
- 暂未做：提交申请后给审批人发通知（新系统还没有通知功能，审批人需打开“待我审批”查看）。
- 修改文件：`centers/_base/src/Nav.php`、`centers/system/{requests.php,api.php}`、`centers/system/src/{RequestService,PermissionTabs}.php`、`centers/system/tests/requests_contract.php`、`centers/README.md`、`tools/ci_php_checks.sh`、本文件。
- 检查结果：`requests_contract.php` 45 项及前四组契约测试全部通过；本地 SQLite + 无头浏览器五组共 100 项全部通过（申请与授权 19 项）。`tools/ci_php_checks.sh`：语法 696 个文件 0 失败，契约 80 项全部通过；`tools/ci_js_checks.sh` 26 项通过。
- 尚未完成 / 下一步：系统中心第 4 步设置中心。

## 2026-10-15：权限中心第 2 批 · 人员权限（新系统）

- 上一步部署情况：PR #13（权限中心第 1 批）合并后自动部署成功，服务器 `cf5f791`。
- 本次完成：`centers/system/people.php`。左侧人员列表（搜索、个人设置数、超管和状态标记），右侧：①有效的临时授权；②数据范围（客户、联系人、跟进、拜访、商机、邮箱、推广、任务、报价、订单、日志，以及此人已有设置的其他模块；类型：只看本人 / 本人和分配给我 / 本团队 / 本部门 / 指定国家 / 指定客户分组 / 全部 / 都不能看；指定国家或分组时必填取值）；③权限明细：每项显示是否生效和来源（超级管理员 / 个人拒绝 / 临时授权 / 个人允许 / 角色），每项可选“跟随角色 / 允许 / 拒绝”，改动时实时显示结果；搜索、只看已生效、只看个人设置、未保存提示。权限中心“人员权限”标签可用。接口加入 people_permissions_save、people_scopes_save。
- 规则：查看 `users.view`；修改个人权限和数据范围 `users.assign_permission`。不能改自己的；超级管理员不需要单独设置；非超管不能给别人“允许”自己没有的权限（“拒绝”不受限）。“跟随角色”删除个人记录；数据范围设回“只看本人”删除记录（无记录即只看本人）。写 `crm_audit_logs`，记录前后值。
- 修改文件：`centers/_base/assets/base.css`、`centers/system/{people.php,roles.php,api.php}`、`centers/system/src/{PeopleService,PermissionTabs}.php`、`centers/system/assets/people.js`、`centers/system/tests/people_contract.php`、`centers/README.md`、`tools/ci_php_checks.sh`、本文件。
- 检查结果：`people_contract.php` 30 项及前三个契约测试（62、48、36 项）全部通过；本地 SQLite + 无头浏览器四组共 81 项全部通过（人员权限 14 项：来源显示、实时结果、保存、只看个人设置、数据范围取值必填与保存、自己/超管只读、无分配权限只读）；1366 和手机宽截图核对，修正手机上左侧列表宽度和待审核状态显示。`tools/ci_php_checks.sh`：语法 693 个文件 0 失败，契约 79 项全部通过；`tools/ci_js_checks.sh` 26 项通过。
- 尚未完成 / 下一步：权限中心第 3 批申请与授权；设置中心。

## 2026-10-15：权限中心第 1 批 · 部门、角色、角色权限矩阵（新系统）

- 上一步部署情况：PR #12（账号管理）合并后自动部署成功，服务器 `71a4da2`。
- 权限中心分三批：① 部门与角色（本次）② 人员权限 ③ 权限申请与临时授权。用户已知悉：新系统审批规则按权限判断（审批全部 / 审批本部门 / 高风险需权限管理员 / 永久授权仅超管），不再按角色名称写死。
- 本次完成：`centers/system/roles.php`（左侧角色列表含账号数，右侧角色信息与权限矩阵：按模块分组、模块中文名、风险标签、搜索、只看已勾选、模块全选、计数、未保存提示、离开页面提醒），`centers/system/departments.php`（部门列表含编码、负责人、账号数、排序、状态；新建、编辑、停用、启用），权限中心标签栏（人员权限、申请与授权显示“建设中”），导航“权限中心”可用。接口加入 role_create / role_update / role_disable / role_enable / role_permissions_save / department_create / department_update / department_disable / department_enable。
- 规则：查看 `users.view`；角色增改停启 `users.manage_roles`；改角色权限 `dangerous.permission_admin`；部门增改停启 `users.manage_departments`。super_admin 角色只有超管能改；非超管只能给角色加自己也有的权限（可以去掉自己没有的）；有账号在用的角色、有账号的部门不能停用；角色编码、部门编码建好后不能改；部门编码留空自动生成 `dept_xxxxxx`。所有操作写 `crm_audit_logs`，角色权限记录增加和去掉的项。
- 底座：`[hidden]` 强制隐藏（修复带 display 样式的元素用 hidden 属性藏不住）；表单数组字段 `name[]`；`data-array` 让全不选时也提交空数组；接口返回 `redirect` 时跳转；`Layout::start` 支持 `scripts`；`Url::centerAsset()`。
- 修改文件：`centers/_base/**`、`centers/system/{roles.php,departments.php,api.php}`、`centers/system/src/{RoleService,DepartmentService,PermissionTabs}.php`、`centers/system/assets/roles.js`、`centers/system/tests/permission_center_contract.php`、`centers/README.md`、`tools/ci_php_checks.sh`、本文件。
- 检查结果：`permission_center_contract.php` 36 项、`accounts_contract.php` 48 项、`stage1_contract.php` 62 项全部通过；本地 SQLite + 无头浏览器：权限中心 23 项、账号管理 21 项、登录与个人账号 23 项全部通过；1440、1366、手机宽截图核对。`tools/ci_php_checks.sh`：语法 690 个文件 0 失败，契约 78 项全部通过；`tools/ci_js_checks.sh` 26 项通过。
- 尚未完成 / 下一步：权限中心第 2 批人员权限；第 3 批申请与授权；设置中心。

## 2026-10-15：系统中心第 2 步 · 账号管理（新系统）

- 上一步部署情况：PR #11（新系统独立版本）合并后自动部署成功，服务器 `e455c43`；用户在服务器运行 `php centers/_base/tools/init_config.php`，生成 `centers/config.php`（库 `artdon_new_erp`，17 个账号，属主 www，权限 640），工作区干净。
- 本次完成：新增账号管理页 `centers/system/accounts.php` 与接口 `centers/system/api.php`。功能：账号列表（按状态标签、搜索账号/姓名/邮箱/电话、部门、角色筛选，待审核排最前，每页 50 个），新建（不填密码自动生成 10 位强密码，只显示一次；新账号为正常状态、首次登录须改密码），编辑资料/部门/角色，审核通过（必须分配部门和角色），拒绝（必须填原因），停用，启用（同时清除登录失败锁定），解除临时锁定，重置密码（可指定或自动生成，重置后须改密码并解锁）。写 `crm_audit_logs` 和 `crm_user_approval_logs`。
- 权限：查看 `users.view`；新建和改基本资料 `users.create`；改部门 `users.assign_department`；改角色 `users.assign_role`；审核 `users.approve`；拒绝 `users.reject`；停用 `users.disable`；启用和解锁 `users.enable`；重置密码 `users.reset_password`。只有部分权限时只改得了对应字段。非超级管理员不能管理超级管理员账号；不能停用自己；页面上不能设置超级管理员。
- 与旧账号管理的差别：新建账号**不改动任何角色的权限**（旧代码每次新建都会清空并重写 6 个系统角色的权限），**不给账号写个人权限**，权限统一跟着角色走；部门只能从已有部门选，不能手输新名字自动建部门。
- 底座新增：`Api`（接口路由：登录、POST 必带 CSRF、权限、异常转统一错误，校验错误返回 422 并带 data.errors）、`PermissionException`、`NotFoundException`、`ErrorCode::BAD_REQUEST`；`Form::textarea()` 及字段 id 前缀；`base.js` 弹窗（`data-open-dialog` + `data-fill`）、接口表单（`data-api`，错误显示到对应字段、成功后刷新、新密码弹窗）；`base.css` 弹窗、状态标签页、筛选、分页、行内菜单样式。导航“账号管理”改为可用。
- 修改文件：`centers/_base/**`、`centers/system/accounts.php`、`centers/system/api.php`、`centers/system/src/AccountService.php`、`centers/system/tests/accounts_contract.php`、`centers/README.md`、`tools/ci_php_checks.sh`、本文件。
- 检查结果：`accounts_contract.php` 48 项通过，`stage1_contract.php` 62 项通过；本地 SQLite + 无头浏览器 21 项通过（新建重复报错、生成密码弹窗、审核、停用后不能登录、启用、重置密码后引导改密码、编辑、搜索、状态筛选、接口无 CSRF 拒绝、无权限看不到入口且接口 403、控制台无报错），1366 宽和手机宽截图核对。`tools/ci_php_checks.sh`：语法 684 个文件 0 失败，契约 77 项全部通过；`tools/ci_js_checks.sh` 26 项通过。
- 尚未完成 / 下一步：第 3 步权限中心（角色、角色权限、个人授权、临时授权、权限申请审批、部门管理）；第 4 步设置中心。新系统暂无自助注册页（旧系统注册的待审核账号可在这里审核）。

## 2026-10-15：新系统改为完全独立（不引用旧代码、不共用登录）

- 用户决定：新系统只用数据库里的数据，不调用旧系统任何代码；新旧系统**不共用登录**；新系统写完后旧代码全部删除。上一条记录里“调用 includes/ 函数、会话与旧系统通用”的做法作废。
- 本次完成：`centers/_base/` 去掉对 `includes/` 的全部引用，新增自己的 `Config`（读 `centers/config.php`，不进 Git）、`Db`（PDO 直连 MySQL，时间由 PHP 生成）、`Session`（会话名 `ARTDON_CENTERS`，与旧系统 `PHPSESSID` 分开）、`Csrf`、`Password`、`Audit`（写 `crm_audit_logs`、`crm_login_logs`，登录日志带“[新系统]”标记）、`Permission`（超级管理员 → 个人拒绝 → 有效临时授权 → 个人允许 → 角色权限；只读，不改授权数据）、`Auth`（登录规则：不存在/密码错误提示相同、5 次失败锁 15 分钟、pending/disabled/rejected/locked 不能登录、成功清零失败次数并记录时间和 IP）。`bootstrap.php` 统一出错页：缺配置时显示 503“新系统还没有完成配置”。导航只列新系统模块，未完成的显示“建设中”不可点击，不再链接旧页面。登录页去掉旧注册页链接。新增 `centers/_base/tools/init_config.php`：服务器上一次性复制数据库连接信息到 `centers/config.php`（先测试连接；权限 640，root 运行时属主改为 www）。`.gitignore` 加入 `centers/config.php`。
- 修改文件：`centers/**`、`.gitignore`、本文件。
- 检查结果：`php centers/system/tests/stage1_contract.php` 62 项通过（含：新系统代码不引用旧文件、不调用旧函数、不在运行时改表；登录锁定、状态拦截、日志；权限五层顺序与不修改授权数据；资料与密码规则）。本地 SQLite + 无头浏览器 23 项通过（含：使用自己的会话 `ARTDON_CENTERS`、不带旧会话；缺配置时 503 页面；5 次错误锁定）。`tools/ci_php_checks.sh`：语法 677 个文件 0 失败，契约 76 项全部通过；`tools/ci_js_checks.sh` 26 项通过。
- 服务器部署情况：合并后需要用户在服务器运行一次 `php centers/_base/tools/init_config.php`，否则新页面显示“还没有完成配置”。
- 尚未完成 / 下一步：第 2 步账号管理（新建账号不会改动角色权限，这是旧代码的行为，新系统不沿用）；第 3 步权限中心；第 4 步设置中心。

## 2026-10-15：CRM 拆分重构 · 公共底座与系统中心第 1 步（登录、个人账号）

- 背景：用户决定把 CRM 拆成客户中心、推广中心、邮箱系统、业务跟进四个中心，先做公共底座；工作台、AI 中心、资料生成、联动中心、数据分析归入“其他功能”本轮不动；数据库沿用现有表；**新建期间不修改旧 CRM 任何代码（含 `includes/`）**；界面以“风格 A：建筑光影极简风”为基础收紧（黑色侧栏、暖白底，橙色只用于主按钮和当前选中，状态色另用绿黄红）。方案写在 claude.ai 文档《CRM 拆分重构方案》。根目录 novlight.com 的旧统一登录（`artdon_users`）是否可删未确认，本次不碰。
- 本次完成：新建 `centers/`。`centers/_base/` 为公共底座：类自动加载、页面外框（侧栏导航 + 顶栏 + 用户菜单）、地址推算（子目录或根目录部署都可用）、登录与权限检查（全部调用 `includes/` 的 `current_user()`、`has_permission()`、`logout_user()`）、接口响应与错误码（格式同 `api_response()`，另带 HTTP 状态码）、参数校验、表单控件、一次性提示、线条图标、共用样式 `base.css` 与脚本 `base.js`（不加载外部字体）。`centers/system/` 为系统中心第 1 步：新登录页（调用 `login_user()`，同表同规则，失败 5 次锁 15 分钟，会话与旧系统通用）、退出（只接受带 CSRF 的 POST）、个人账号页（改资料、改密码、账号信息、最近登录记录）。
- 与旧页面的差别：个人资料部门改为从启用部门中选择（旧页面可手输新名字自动建部门）；新密码不能与旧密码相同；校验错误显示在对应字段下；不在运行时检查或修改表结构；账号被停用后访问新页面会被退出；`force_password_change=1` 的账号用新登录页登录后会被引导到改密码（旧登录页没有这一步）。
- 导航：四个中心、其他功能、账号管理、权限中心、设置中心、日志暂时指向旧页面并标“旧版”，按权限显示。
- 修改文件：新增 `centers/README.md`、`centers/_base/**`、`centers/system/**`；`tools/ci_php_checks.sh` 加入 `centers/system/tests/stage1_contract.php`；修复既有测试 `tests/crm_workspace_isolated.php`（10-14 跟进记录 P1 让 `crm_visit_list()` 调用新函数 `crm_visit_category_terms()`，测试只摘取了前者导致报错，现改为两个一起摘取，只改测试不改业务代码）；本文件。旧 CRM 与 `includes/` 无改动。
- 检查结果：新增 PHP 全部 `php -l` 通过（本机 PHP 8.3，代码避开 8.1+ 语法以兼容 CI 的 PHP 8.0）；`node --check centers/_base/assets/base.js` 通过。`php centers/system/tests/stage1_contract.php` 42 项全部通过（内存 SQLite，表结构取自 `sql/001`、`sql/002`；含跳转过滤与 `auth_safe_redirect()` 一致、改密码后可用旧 `login_user()` 登录、中心代码无建表改表语句）。临时副本 + SQLite 替身 + 本地 PHP 服务器 + 无头浏览器走通 19 项：未登录跳转并带回跳地址、错密码提示、登录回跳、资料字段错误、保存与刷新不重复提示、改密码后重新登录、伪造令牌退出无效、用户菜单退出、初始密码引导、待审核不能登录、手机宽度导航、超管导航、控制台无报错；1440、1366、390 宽截图核对。`tools/ci_php_checks.sh` 全量：语法 0 失败；修复既有测试后契约全部通过；`tools/ci_js_checks.sh` 26 项通过。
- 服务器部署情况：PR #9 经用户确认后合并（rebase），GitHub Actions 运行 38664932471：检查通过；部署步骤已把服务器快进到 `0c7f776`（用户在宝塔终端确认 `git log -1` 为 `0c7f776`，与 GitHub 一致），但最后在服务器上跑 `tools/ci_php_checks.sh` 失败，所以 Actions 显示红色。原因：服务器上 8 个旧文件权限为 640（`miniapp/migrate.php`、`promotion/api.php`、`api_live.php`、`cron.php`、`index.php`、`migrate_v2.php`、`promo_lib.php`、`track.php`，其中 `migrate_v2.php` 属主为 root），部署用户读不了，`php -l` 报 `Could not open input file`；10-14 04:39 那次部署失败也是同一原因。用户已在服务器执行 `chown www:www promotion/migrate_v2.php && chmod 644 miniapp/migrate.php promotion/*.php`（只改权限，Git 不跟踪，工作区仍干净）。服务器 PHP 8.0 有 `pdo_sqlite`，新契约测试可在服务器运行。用户用正式账号在新登录页登录正常，个人账号页显示正常，进旧 CRM 无需再登录。
- 部署传输较慢（约 17 分钟），部署脚本每次传完整仓库的 Git 包，后续可改为只传增量，需用户同意后再改工作流。
- Git 提交号及推送状态：`5d11753`→`main` 上为 rebase 后的提交，`main` HEAD `0c7f776`；本记录更新另走一个 PR，合并后再次部署，用来验证服务器检查能否全部通过。
- 尚未完成 / 下一步：系统中心第 2 步账号管理；第 3 步权限中心；第 4 步设置中心。界面样稿（客户中心列表与详情）待用户确认。迁移脚本执行器等到第一次需要新表时再加。

## 2026-10-14：小程序跟进记录 P1（仍走拜访接口）

- 本次完成：跟进记录与拜访/来访共用 `crm_visit_records`。`visit_type` 固定 `customer_visit`，`visit_category` 固定 `跟进记录`，`purpose` 只能是电话、视频电话、视频会议、当面、其他。标题空着时写成 `跟进记录：{客户}（{方式}）`。不新建跟进接口，不写 `crm_customer_followups`。照片、事项、待办仍用原来的拜访单接口。地点可以不填。跟进记录不能签到。
- 列表：`crm_visit_list` 增加可选的 `visit_category` 精确条件，以及 `exclude_visit_category`。小程序 `visits` 在没有传入分类时默认排除 `跟进记录`，所以现有拜访/来访列表不会混进跟进。传 `visit_category=跟进记录` 时只返回跟进，并强制 `visit_type=customer_visit`。客户详情和今日列表会带 `record_kind=followup`、`type_label=跟进`。今日拜访计数不含跟进。
- 小程序前端：本仓库只有 `miniapp/api/index.php`、`miniapp/migrate.php`、`miniapp/api/wx_config.example.php`。没有 `app.json`、`wxml`，也没有 `wechat-miniprogram` 目录或子模块。第三页签「跟进」、编辑页 `type=followup`、客户详情「新建跟进」要改在小程序工程里，本环境没有那份源码，也没有微信开发者工具或 `miniprogram-ci`，不能上传体验版。
- 小程序工程接上后：拜访页加第三页签，请求 `action=visits` 且 `visit_category=跟进记录`。新建/编辑走现有 `visit_save`，带 `visit_category=跟进记录`（或 `record_kind=followup` / `type=followup`）、`purpose` 为上面五项之一、`customer_id`、日期。地点可空。照片、事项、待办继续用这条拜访单的 id。客户详情用返回的 `record_kind` 显示「跟进」，新建按钮提交同一套 `visit_save`。`visits` 返回的 `followup.purposes` 是方式列表。
- 网站：拜访/来访/外出列表请求带 `exclude_visit_category=跟进记录`。客户详情里这类记录标成「跟进」，编辑时跟进方式必填、地点选填，类型保持「跟进记录」。没有改网站客户跟进页，也没有把「跟进记录」放进新建拜访的类型下拉。
- 修改文件：`crm_visit.php`、`miniapp/api/index.php`、`assets/crm/crm.js`、`tests/crm_visit_followup_category_contract.php`、`tools/ci_php_checks.sh`、本文件。
- 检查结果：`php -l` 通过 `crm_visit.php`、`miniapp/api/index.php`、`tests/crm_visit_followup_category_contract.php`。`php tests/crm_visit_followup_category_contract.php` 与 `php tests/crm_visit_list_search_contract.php` 通过。`node --check assets/crm/crm.js` 通过。另外跑过既有拜访契约：权限、卡片动态、去重状态、图片上传通过；派工联动和结果历史两条在 `origin/main` 上就已经对不上现有文案，不是这次改动引入的。未连正式库，未用正式账号登录。
- 服务器部署情况：本环境无法 SSH 正式机，未把本分支检出到 `/www/wwwroot/Artdon/artdon_erp/`。正式目录只在本分支合并进 `main` 后由 GitHub Actions 快进。三方 HEAD 一致留到那次快进之后。
- Git 提交号及推送状态：见本分支提交。正式目录未快进。
- 尚未完成：小程序工程加上「跟进」页签和「新建跟进」后，用体验版走一遍新建、列表、详情、照片、事项、待办。合并后网站拜访列表不应出现分类为跟进记录的行；客户详情里这些行应显示为跟进。

## 2026-10-14：分组页只选国家也列出客户

- 本次完成：`#/groups` 右侧「搜索客户中心」在关键词为空、但已选择国家时，列出该国家里当前登录用户可见的客户中心客户。可见范围仍是 `promotion.view` 与 `get_user_scope('customer')` 的交集，没有放宽。只输入关键词时行为与原来相同。关键词和国家同时有时，两个条件一起生效。关键词为空且国家为「全部国家」时不请求客户列表，仍显示空状态。
- 原因：`customers` 接口本来就接受空关键词加国家条件（`LegacyCustomerAdapter::list` 只在关键词非空时加关键词条件）。原型 `live.js` 在关键词为空时不请求，`app.js` 的搜索面板也在关键词为空时丢掉已经返回的行。
- 修改文件：`promotion_center_v1/prototype/live.js`、`promotion_center_v1/prototype/app.js`、本文件。未改 `promotion_center_v1/api.php`、`LegacyCustomerAdapter.php`、门户根 `index.php`、现网 `promotion/`、`crm_marketing_*`、`includes/permission.php`。
- 检查结果：`node --check` 通过 `prototype/app.js`、`live.js`、`data.js`、`wizard.js`、`board.js`。无头浏览器加载原型脚本，并用模拟的 `customers` 接口操作真实输入框和国家下拉：打开分组时关键词为空、国家为「全部国家」，不请求客户列表，提示可输入关键词或选择国家；只选阿联酋时请求 `country=AE` 且不带 `q`，列出 Sunrise Oasis Lighting LLC 与 Gulf Lights Trading，不列出印尼的 Mega Sakti；清回「全部国家」后列表回到空状态，且没有空条件的客户请求；只输入 `004` 列出 EX004 与 DE004；`004` 加阿联酋只留下 Sunrise Oasis Lighting LLC。未连正式库，未用正式账号登录。
- 服务器部署情况：本环境无法 SSH 正式机，未把本分支检出到 `/www/wwwroot/Artdon/artdon_erp/`。正式目录只在本分支合并进 `main` 后由 GitHub Actions 快进。三方 HEAD 一致留到那次快进之后。
- Git 提交号及推送状态：见本分支提交。正式目录未快进。
- 尚未完成：合并后用正式账号打开 `prototype/#/groups`，只选国家应出现该国范围内客户，另一位业务员的客户仍不出现。执行看板待确认项仍是 `EXECUTION_BOARD.md` 第 7 节。

## 2026-10-14：原型入口读取真实客户中心

- 本次完成：门户仍进 `promotion_center_v1/prototype/`。登录且具备 `promotion.view` 后，原型里的客户列表、客户详情、`#/groups` 搜索加客户改为读取客户中心。范围是 `get_user_scope('customer')` 与客户中心规则的交集，不放大。分组读写本模块 `pm_*` 表（新建、重命名、软删、加入、移出、移动、复制、约 10 秒撤销）。未登录打开原型会转到登录页；未登录调用 `api.php` 返回 JSON `AUTH_REQUIRED`。推广列表、执行看板、新建向导仍用演示数据，向导第 2 步标明不会读客户中心、也不会发信。不建 `crm_mail_send_jobs`。
- 修改文件：`promotion_center_v1/api.php`、`bootstrap.php`、`migrate.php`、`app/`、`database/migrations/20261014_001_pm_groups.sql`、`tests/phase1_isolated.php`、`prototype/index.php`、`prototype/index.html`、`prototype/live.js`、`prototype/app.js`、`prototype/wizard.js`、`index.html`、`docs/DESIGN.md`、本文件。未改门户根 `index.php`、现网 `promotion/`、`crm_marketing_*`、`includes/permission.php`。
- 检查结果：`php promotion_center_v1/tests/phase1_isolated.php` 输出「阶段 1 验收全部通过」（含越权客户不可见、联系人姓名和邮箱可搜、未登录与无 `promotion.view` 被拒绝、不写邮件队列表、上述禁止文件无 diff）。新增与改动 PHP 经 `php -l`。`node --check` 通过 `prototype/app.js`、`live.js`、`wizard.js`。本机 `php -S`：未登录 `prototype/` 与 `prototype/index.php` 302 到 `/login.php`；`api.php?action=customers` 与分组写入均为 401 `请先登录`；`index.html` 只跳到 `index.php`，不含客户数据。用模拟接口渲染原型：`#/groups` 能搜到范围内客户并显示「加入本组」，客户列表和详情显示接口数据，向导仍是演示名单。未录屏，未用浏览器登录正式账号。
- 服务器部署情况：本环境无法 SSH 正式机（此前为 `Permission denied (publickey)`），未把本分支检出到 `/www/wwwroot/Artdon/artdon_erp/`。正式目录只在本分支合并进 `main` 后由 GitHub Actions 快进。分组表由第一次分组接口里的 `PmSchema::apply` 或 CLI `php promotion_center_v1/migrate.php` 创建（`CREATE TABLE IF NOT EXISTS`）。若正式库按上一节执行过 DROP，合并后再进分组页会重建空表。三方 HEAD 一致留到那次快进之后。
- Git 提交号及推送状态：功能提交 `84467861c144f1f9b2b410093f521d607149f67d`。分支 `cursor/promotion-live-customers-39ae` 已推送 `origin`。Ready PR：https://github.com/qiulei6386-stack/artdon_erp/pull/6 。正式目录未快进。
- 尚未完成：合并后 Actions 快进；用正式账号打开 `prototype/#/groups`，确认能搜到自己范围内的真实客户，另一位业务员的客户不出现。执行看板待确认项仍是 `EXECUTION_BOARD.md` 第 7 节。部署用户打不开既有 8 个 PHP 文件的问题未在本次处理。

## 2026-10-14：撤销推广中心阶段 1 独立 PHP 模块

- 用户明确：阶段 1 做成的独立登录 PHP 模块是错的，必须删掉，不要在服务器上留垃圾。真正要改的是静态原型 `promotion_center_v1/prototype/`（例如 `#/groups`）。门户首页卡片继续进 prototype，不改门户 `index.php`。
- 本次完成：从仓库删除 `promotion_center_v1/api.php`、`bootstrap.php`、`index.php`（保留落地页 `index.html`）、`migrate.php`、`config.php`，以及整目录 `app/`、`assets/`、`database/`、`tests/` 和 `docs/PHASE1.md`。`DESIGN.md` 状态改回「设计与原型」，并注明独立 PHP 壳已撤销。保留 `prototype/` 全部、`index.html`，以及 `DESIGN.md`、`CREATE_FLOW.md`、`EXECUTION_BOARD.md`、`UI_GUIDELINES.md`。紧接本节的「阶段 1（登录、只读客户、自有分组）」是已撤销工作的历史记录，对应代码已不在仓库里。
- 修改文件：上述删除项、`promotion_center_v1/docs/DESIGN.md`、本文件。未改门户 `index.php`（快捷入口和业务经营卡片的推广 `href` 仍是 `promotion_center_v1/prototype/`）、现网 `promotion/`、任何 `crm_marketing_*`、`includes/permission.php`。
- 检查结果：误做路径已不在工作树。`node --check` 通过 `prototype/app.js`、`board.js`、`data.js`、`wizard.js`。`php -l index.php` 通过。相对 `origin/main` 的差异不含门户 `index.php`、`prototype/`、`promotion/`、`crm_marketing_*`、`includes/permission.php`。
- 服务器部署情况：本环境无法 SSH 正式机，未连库，未猜数据库密码。误做提交 `f5269b9bca41908a75c8391b3b5111841c3ab20a` 的部署 run [38506212861](https://github.com/qiulei6386-stack/artdon_erp/actions/runs/38506212861) 已把这些 PHP 文件 Fast-forward 进正式目录（日志 `Updating f5a25c5b3..f5269b9bc`），作业随后仍因既有 8 个 PHP「Could not open input file」失败。该作业没有执行 `migrate.php`。合并本清理后，正式机需再快进同一提交，Git 才会从运行目录删掉这些文件。协调者还需在正式库执行 `DROP TABLE IF EXISTS pm_undo, pm_audit_logs, pm_group_members, pm_groups;`（仅当有人跑过迁移时这些表才存在）。
- Git 提交号及推送状态：删除提交 `174d370b4f8e49bc3a14d7eefcc00d2ff8ea7b4a`，分支 `cursor/revert-promotion-phase1-php-3875`。本记录的提交号补在其后。正式目录在合并进 `main` 并快进之前仍是误做提交 `f5269b9`。
- 尚未完成：合并后正式机快进以删除误做文件，并执行上面的 DROP。真实客户中心对接改在 prototype，本任务不实现。门户「禁止部署清掉未入库入口」的记录保留在下方，未删。执行看板待确认项仍是 EXECUTION_BOARD.md 第7节。

## 2026-10-14：推广中心 V1 阶段 1（登录、只读客户、自有分组）

- 本次完成：按阶段 1 清单只改 `promotion_center_v1/`。登录复用现网会话；无 `promotion.view` 不能进。客户只经 `LegacyCustomerAdapter`，范围是 `get_user_scope('customer')` 与客户中心规则的交集。分组写入 `pm_groups` / `pm_group_members`，支持新建、重命名、软删、加入、移出、移动、复制和 10 秒撤销。历史页只读 CRM 任务和旧中心活动。不发送邮件，不建 `crm_mail_send_jobs`。
- 修改文件：`promotion_center_v1/config.php`、`bootstrap.php`、`index.php`、`api.php`、`migrate.php`、`app/`、`assets/app.css`、`assets/app.js`、`database/migrations/20261014_001_pm_groups.sql`、`tests/phase1_isolated.php`、`docs/PHASE1.md`、`docs/DESIGN.md`（状态一行）、本文件。未改 `crm_marketing_*`、CRM 内推广界面、`promotion/`、`includes/permission.php`。
- 检查结果：`php promotion_center_v1/tests/phase1_isolated.php` 输出「阶段 1 验收全部通过」。新增 PHP 经 `php -l`。`node --check promotion_center_v1/assets/app.js` 通过。本机浏览器截图核对列表、德国筛选、北欧灯饰详情（多邮箱、离职、退订、三条渠道、可推广群）、分组搜不到乙的客户、历史只读，以及无 `promotion.view` 的拒绝页。按用户要求不录屏。验收以该测试和截图为准。细节写在 `docs/PHASE1.md`。
- 服务器部署情况：未同步正式目录 `/www/wwwroot/Artdon/artdon_erp/`。云端没有部署私钥，不能把功能分支检出到服务器。正式目录只在合并进 `main` 后由 GitHub Actions 快进。三方 HEAD 一致留到那次快进之后。现网 `promotion.php` 本机未登录仍 302 到登录页，源文件未改。
- Git 提交号及推送状态：分支 `cursor/promotion-center-phase1-1b2a` 已变基到 `origin/main` `f5a25c5b342971409124fa7efd526bfdd5e756b7`。阶段 1 代码提交 `d366553`。正式目录未快进。
- 尚未完成：合并后 Actions 快进；正式登录后用 5 家客户对照 `crm_api` 客户列表；点开正式 `promotion.php` 做发送页回归。执行看板待确认项仍是 `EXECUTION_BOARD.md` 第 7 节。阶段 2 才处理时区、无归属弹窗和排除规则。

## 2026-10-14：门户推广中心改打开 V1 原型，并禁止部署清掉未入库入口

- 正式部署快进时清掉了服务器上未入库的 `index.php`（备份在 `/tmp/artdon-predeploy-20261014080436`，并复制到 `artdon_erp/_codex_backups/`）。门户「推广中心」因此又指回旧 `promotion.php`。
- `index.php` 快捷入口和业务经营卡片的推广 `href` 改为 `promotion_center_v1/prototype/`。说明和标签里的「执行看板」保持不变。未改 `promotion/` 发送引擎、`crm.php` 及其他模块入口。
- `promotion_center_v1/` 增加静态 `index.html`，说明这是演示原型，并链接到 `prototype/`。
- `.github/workflows/deploy.yml` 去掉「只脏了 index.php 和未跟踪的 promotion_center_v1 就备份后 `git checkout` / `rm -rf`」以及脏工作区仍 `read-tree --reset` 的路径。工作区只要不干净，打印 `git status --short` 后以非零退出，不改本地文件。快进成功后检查两处 `['key' => 'promotion'` 入口都包含 `promotion_center_v1/prototype/`，仍指向 `promotion.php` 则部署失败。
- 检查：`php -l index.php` 通过；源码确认两处推广入口都是 `promotion_center_v1/prototype/`。部署脚本用临时仓库复现：脏的门户文件和未跟踪原型目录会中止部署且文件还在；干净仓库可以快进；旧 `promotion.php` 入口会在快进后被拒绝。
- 本轮不直接部署。合并进 `main` 后，现有 GitHub Actions 会在校验通过后快进正式目录。门户入口提交 `c262c858047d93ab3acf00e5de1ae21b51693fec`；禁止清掉未入库入口的部署脚本与本记录在同一提交。
- 尚未完成：部署用户打不开 8 个既有 PHP 文件的原因仍未查清；执行看板待确认项仍是 EXECUTION_BOARD.md 第7节。

## 2026-10-14：同步推广中心 V1 到正式服务器

- 用户要求按固定顺序同步。本机复检：`node --check` 通过 `promotion_center_v1/prototype/app.js`、`board.js`、`data.js`、`wizard.js`；`php -l index.php` 通过（本机 PHP 8.3.6）。未改业务逻辑。
- 开始时本分支与 GitHub 均为 `c3708e7e8dfe9d2c4b125cab03e64bbb480491af`。PR #2 已于 2026-10-14T00:00:18Z 快进合并进 `main`，合并结果就是该提交。合并前 `origin/main` 为 `a30a2fef4d3477fc9c3d17f4c5595d2a6b96c315`，是本分支祖先。正式目录按 GitHub Actions 只快进 `main`，没有把功能分支单独检出到服务器。
- 本机没有部署私钥。`119.91.27.19:22` 可以连通，主机密钥与 `.github/known_hosts` 一致；`ssh ubuntu@119.91.27.19` 的结果是 `Permission denied (publickey)`。实际连接由 GitHub Actions 的部署密钥完成。
- 部署 run [38487613052](https://github.com/qiulei6386-stack/artdon_erp/actions/runs/38487613052)（`c3708e7`）在服务器停止：工作区不干净，仅有 ` M index.php` 和 `?? promotion_center_v1/`。这次没有移动 HEAD。
- 随后 `main` 上的部署脚本改为先处理这两项。run [38487919147](https://github.com/qiulei6386-stack/artdon_erp/actions/runs/38487919147)（`fefe519`）把未入库副本备份到 `/tmp/artdon-predeploy-20261014080436` 后清掉工作区中的这两项，接着更新 `refs/remotes/deploy/main` 失败：`unable to append to '.git/logs/refs/remotes/deploy/main': Permission denied`。
- run [38488124152](https://github.com/qiulei6386-stack/artdon_erp/actions/runs/38488124152)（`0a91e58`）再次快进时，文件已写入，HEAD 没跟上：`fatal: update_ref failed for ref 'HEAD': unable to append to '.git/logs/HEAD': Permission denied`，同时输出 `Updating a30a2fef..0a91e586`。当时服务器 HEAD 仍是 `a30a2fe`，索引已是待发布内容。
- run [38488476539](https://github.com/qiulei6386-stack/artdon_erp/actions/runs/38488476539)（`5850ee4`）把 HEAD 对齐到该提交。紧接着的 run [38488503566](https://github.com/qiulei6386-stack/artdon_erp/actions/runs/38488503566) 日志为 `Updating 5850ee41..e6979c9d` 且 `Fast-forward` 只改了 `.github/workflows/deploy.yml`。脚本在语法检查之前会核对 `git rev-parse HEAD` 与 GitHub 提交一致，检查已开始，因此服务器 HEAD 已是 `e6979c9de0bde1e8c302aee36dd477f299d2fd8a`。作业结论仍是失败。
- 服务器 PHP 8.0 复检：632 个文件里 8 个 `php -l` 非零：`miniapp/migrate.php`、`promotion/api.php`、`promotion/api_live.php`、`promotion/cron.php`、`promotion/index.php`、`promotion/migrate_v2.php`、`promotion/promo_lib.php`、`promotion/track.php`。原文都是 `Could not open input file`。这 8 个文件相对 `a30a2fe` 没有差异。本机 PHP 8.3 和 GitHub Actions 的 PHP 8.0 都能解析它们，所以不是这批推广改动引入的语法错误，是部署用户打不开服务器上的这 8 个既有文件。公开登录页校验步骤没有执行。未登录访问 `https://novlight.com/artdon_erp/` 会转到登录页，HTTP 200。
- 官网静态原型已可打开。`board.js` 与 `index.html` 的 sha256 与本仓库一致（`board.js` 为 `fa8fbc62d6ef78352a8e531d9133276b7877763719f3421c9a07a8a88b719602`）。地址：https://novlight.com/artdon_erp/promotion_center_v1/prototype/index.html#/board （演示数据，不登录也能看）。
- run [38488939780](https://github.com/qiulei6386-stack/artdon_erp/actions/runs/38488939780) 把 `b5b0f12` 快进到服务器。run [38488965085](https://github.com/qiulei6386-stack/artdon_erp/actions/runs/38488965085) 日志为 `Updating b5b0f1216..023521f7c` 且 Fast-forward 只改了 `WORK_CONTEXT.md`。复检在 HEAD 核对之后才开始，因此当时服务器 HEAD 是 `023521f7c7a61ed1f3849488329549944b652865`。作业因上述 8 个文件打不开而失败。
- 本记录提交继续按同一快进同步。若这 8 个文件仍打不开，作业会再次标红，HEAD 仍可与 GitHub 对齐。未入库副本留在 `/tmp/artdon-predeploy-20261014080436`，没有用它覆盖仓库文件。
- 尚未完成：部署用户为何打不开那 8 个文件；未入库副本是否与仓库版本不同。执行看板待确认项仍是 EXECUTION_BOARD.md 第7节。

## 2026-10-13：推广中心 V1 执行看板

- 用户要求：推广中心增加“执行看板”，一场推广发完/跑完后一页看全执行情况（场次摘要、总进度、发不了/异常及原因、发送时间线、发信箱维度、线下执行、效果粗览）；可按场次切换，默认进行中或最近一场；筛选仅异常/仅线下未清/仅失败；空状态写“还没有执行数据”；只做 promotion_center_v1，不改 CRM 内部旧推广。
- 新增 promotion_center_v1/docs/EXECUTION_BOARD.md（入口、默认场次、各区块口径、筛选、权限范围、正式数据来源、待确认）；DESIGN.md 增 D15、页面表“执行看板”、第4阶段含执行看板、第13节第6项。
- 原型：新增 prototype/board.js（独立页 #/board、#/board/<编号>）；左侧导航“执行看板”；推广详情与推广列表增加“查看执行”；看板用新建向导同一套规则按冻结参数重算名单，再按原型“现在”模拟发送结果（含邮箱 SMTP 登录失败自动暂停、日额度触顶顺延、推广暂停、结果不确定）；线下待办与“人工待办”共用数据，登记结果后看板同步；推广详情“收件对象”和“核对”改用同一份执行数据；业务员看非自己创建的推广只显示自己负责的客户。wizard.js 的 plan() 增加可选参数（按创建人、提交时刻、当时邮箱用量计算），不传时行为不变。data.js 新增演示推广 pm-1011 与各场执行参数。styles.css 增加看板样式及 1280/1366/1920/2560 与矮屏适配。
- 门户 index.php“推广中心”卡片标签与说明增加“执行看板”（门户入口仍指向旧 promotion.php，第5阶段切换）。未改 crm_* 文件。
- 检查：node --check 通过；php -l index.php 通过；本地开发服务器登录门户确认标签显示；无头浏览器看板自测58项、向导自测56项、分组自测15项全部通过；1280×720、1366×768、1440×900、1920×1080、2560×1440 截图核对；浏览器实测13步（门户标签、列表/详情进入看板、默认场次、原因筛选、四种筛选、线下明细弹窗、登记结果后同步、切换场次含CRM暂停与草稿空状态、业务员视角、1280宽）通过。
- 服务器同步结果见文首「2026-10-14：同步推广中心 V1 到正式服务器」。
- 待用户确认：EXECUTION_BOARD.md 第7节（邮箱出错自动暂停还是转其他邮箱、打开/点击追踪、看板是否导出），以及此前 CREATE_FLOW.md 第3节、DESIGN.md 第13节事项。

## 2026-10-13：推广中心 V1 新建推广主流程（九步向导）

- 用户给出主流程：名称 → 选客户（分组或直接勾选，按偏好分邮件/线下）→ 富文本邮件 → 变量预览 → 试发 → 发送时间（马上/指定/按国家工作时间，全员计划含发不了原因）→ 发信方式（负责人/均分/指定/手填）→ 线下明细；无归属客户弹窗决定公司邮箱或排除。并列出需定清的点。
- 新增 promotion_center_v1/docs/CREATE_FLOW.md：步骤与完成条件；逐条定下规则（预览两种模式、空变量用默认称呼否则不发、签名跟发件邮箱、图片内嵌与附件上限、时区来源与各国周末、发不了原因及对应CRM字段、均分余数、手填对不上拦截并提供显式自动填、发信方式四选一、同邮箱只发一封、邮件+线下两行、线下执行人/话术/复制/截图/逾期/微信群按群合并、每个正式邮箱试发、审批批准后才生效、确认时冻结、额度80%预警与顺延）；第3节为待确认默认值。顺序调整一处：发信方式放在试发和发送时间之前（试发要覆盖每个正式邮箱，计划时间依赖邮箱额度）。
- DESIGN.md：新增 D14、页面表、pm_* 表字段、审批补充规则、第13节第5项；UI_GUIDELINES.md：步骤条、底部固定栏、计划表组件。
- 原型：新增 prototype/wizard.js（九步向导），app.js 去掉旧单页编辑并导出共用函数，人工待办登记结果必须上传截图；data.js 补客户偏好渠道、离职、推广暂停/黑名单、DNS、无归属、无邮箱业务员刘洋、各国时区与周末、邮箱签名；styles.css 增加向导样式及各断点适配。?wzstep=N 可直接打开预填的第N步。
- 检查：node --check 通过；无头浏览器向导自测56项（含各类发不了原因、同邮箱去重、默认称呼、均分余数、手填拦截与自动填、额度顺延与预警、试发失效、沙特周末与德国时区换算、过去时间拦截、提交冻结与生成线下待办、业务员只见本人邮箱与客户、无邮箱业务员、截图必传）及分组自测15项全部通过；浏览器实测九步全流程、富文本工具栏、超链接校验、附件超限提示、预览代入、手填合计、试发失效、时间校验、无归属弹窗、1280宽适配通过（测试员未选联系结果导致的保存提示属预期行为）；1280×720、1366×768、1440×900、1920×1080、2560×1440 截图核对。
- 本轮仍为设计与静态原型，未写PHP业务代码，未改数据库。云端环境无法访问服务器运行目录，未执行服务器同步；需在本地电脑拉取本分支提交后按GitHub→同提交服务器流程同步并核对三方提交号。
- 待用户确认：CREATE_FLOW.md 第3节默认值（默认时区、附件/图片上限、额度预警比例、线下截止天数、“客户暂停”是否含客户中心状态、节假日、是否允许强制发送），DESIGN.md 第13节其余事项，以及对向导原型的意见。

## 2026-10-13：推广中心 V1 设计修订（统一权限、原邮箱系统、屏幕适配、客户分组）

- 用户要求：权限用统一权限不另做；发信用原邮箱系统；兼顾小屏笔记本与32寸显示器；推广中心可建客户分组，分组后可互移、删除、增加、减少。
- DESIGN.md 重写：权限全部走 includes/permission.php（has_permission/require_permission/get_user_scope），复用现有 promotion.* 权限点，新增 promotion.approve、promotion.exempt_small、promotion.use_any_mailbox 三个；审批规则改为按权限表达；推广数据范围用 get_user_scope('promotion')，客户数据用 get_user_scope('customer')。现有 permission_alias_keys() 把 promotion.* 细分权限别名到 promotion.view，计划先补发显式授权再去掉别名，需用户确认。
- 发信：新模块不自带SMTP，按节奏把到期邮件写入 crm_mail_send_jobs（job_id 以 pm_ 开头），状态从原邮件队列回读，已发邮件进入发件箱和客户时间线；暂停/停止时取消已放入队列未发的任务。需给 crm_mail.php 补充：指定账号入队、取消不转草稿、额外信头（List-Unsubscribe）、来源标记。
- 客户分组：pm_groups/pm_group_members，一个客户可在多个分组；新建、重命名、删除（软删可撤销）、添加客户（筛选面板）、移出、移到/复制到其他分组，均可撤销；业务员只看到和操作自己负责的客户；推广提交时冻结名单，后续分组变动不影响；CRM旧分组一次性导入。
- 屏幕适配：≤1280 导航收为图标栏、次要列隐藏；≤1440 添加客户面板改为抽屉；高度≤820 紧凑模式；≥1920/≥2400 放宽内容宽度和多栏；导航可手动收起（宽于1280时）。
- 原型同步更新（data.js 按权限点定义五种身份、分组页、响应式样式）。检查：node --check 通过；无头浏览器自测15项（移动+撤销、移出+撤销、删除+撤销、导航收起、业务员范围与无删除按钮）全部通过；1280×720、1366×768、1440×900、1920×1080、2560×1440 截图核对；浏览器实测新建（含校验）、筛选添加、移动、复制、重命名、业务员范围、推广引用分组通过。
- 本轮未写PHP业务代码，未改数据库。云端环境无法访问服务器运行目录，未执行服务器同步；需在本地电脑拉取本分支提交后按GitHub→同提交服务器流程同步并核对三方提交号。
- 待用户确认：DESIGN.md 第13节（第3.4节权限别名处理方案、是否需要打开/点击追踪、放行进程并入 crm_mail_cron.php 还是独立定时任务、模块入口与菜单位置），以及对分组和屏幕适配原型的意见。

## 2026-10-13：推广中心 V1 第一阶段（设计与界面原型）

- 用户确认：界面基础全部重做（不用CRM界面，也不用 material_center_v1/ui）；保留现有审批规则；CRM历史推广保留并在新模块展示；独立推广中心 promotion/ 不再使用，由新模块替换。
- 新增 promotion_center_v1/docs/DESIGN.md：目标范围、已确认决策、角色映射、审批规则、统一状态机及CRM历史状态映射、页面清单、pm_ 前缀数据表、旧表适配器边界、发送规则（含修复不确定发送重试与卡住问题）、并行期屏蔽/频控规则、六阶段计划、待确认事项。
- 新增 promotion_center_v1/docs/UI_GUIDELINES.md（界面原则、视觉变量、组件、响应式）与 promotion_center_v1/prototype/（静态原型：工作台、推广列表含CRM历史、新建/编辑、详情、审批、人工待办、设置；可切换管理员/市场/业务员/只读身份查看差异）。原型只用虚构演示数据，不连接数据库、不发送邮件，无PHP代码。
- 检查：node 语法检查通过；Chrome 1440 宽截图8页；浏览器实测业务员创建→超出免审额度触发审批→测试信及改内容后失效→提交→管理员退回（无原因被拦截）→业务员看到退回原因→业务员间数据隔离→只读身份无新建与设置权限，全部通过。
- 待用户确认：DESIGN.md 第12节（role_key 映射与 manager 审批权、是否要打开/点击追踪、分组沿用CRM还是重建、入口位置），以及对原型界面的意见。确认后进入第2阶段（骨架、登录角色、适配器、列表与CRM历史只读）。
- 未执行服务器同步，原因同前；原型目录为静态文件，同步到服务器后可直接访问，如不希望公开访问可在同步时排除或加访问限制。

## 2026-10-13：推广模块重构路线建议

- 用户说明：CRM全系统要按模块逐个重构，客户中心每天使用；CRM推广不好用，界面不参考CRM，界面要重做。用户询问是在外面重新写推广，还是改CRM内的推广。
- 建议：在CRM外按 commercial_center_v1 的模式新建独立推广模块。具体为独立目录、独立前缀表、旧表经适配器只读、不改旧菜单、删除目录即可回滚。CRM推广v2中已验证的发送规则移植到新服务层，并修复已知的不确定发送重试和卡在发送中的问题；不沿用 promotion/ 旧引擎和无源码SPA。新旧两套并行期间，禁联、退订和频控需要跨两套系统检查。
- 本轮未修改代码，仅更新本记录。待用户确认：界面是否以 material_center_v1/ui 组件为基础；审批规则；旧推广历史是否在新模块只读展示；独立 promotion/ 是否仍在使用。
- 未执行服务器同步，原因同前。

## 2026-10-13：推广中心界面方案讨论

- 用户询问推广中心界面方案。本轮只给出方案建议，未修改代码。
- 建议方向：不在无源码的独立推广中心SPA上改版，而是按 docs/crm-ui-contract.md 并入CRM统一界面。创建流程复用CRM五步向导；补齐CRM侧没有的审批队列、发件额度和设置页面；按后端真实权限和状态显示操作。分阶段：先止血（隐藏SPA中后端不支持的按钮），再迁移，最后下线独立入口。
- 待用户确认：独立推广中心是否仍在正式使用；是否同意以CRM推广为唯一入口；审批规则（市场始终审批、业务员超过20封审批）是否保留。确认后再出线框图或实施。
- 未执行服务器同步，原因同上一节。

## 2026-10-13：推广中心模块结构文档

- 用户要求通读推广中心相关目录，整理接口调用、核心数据结构与隐藏业务规则。本轮新增 docs/promotion-center-module.md：组件与入口、角色模型与数据可见范围、api_live.php（9个）与 api.php（64个）接口表（权限/参数/调用方）、promo_* 表字段（基础表无建表语句，按SQL用法推断并标注）、promo_settings 键、活动创建/发件人分配/审批/状态机/发送闸门/cron引擎/追踪退订/智能发送等规则、与CRM推广的边界、19项前后端不一致及维护注意事项。
- 结论要点：推广中心无任何导出逻辑；promotion.* 细分权限经别名全部落到 promotion.view，viewer/finance 默认持有；SPA按角色名字符串判定 admin/marketing/sales，状态与审批判断与后端存在多处不一致（详见文档第7节）。安全细节不写入公开仓库。
- 检查：仅源码阅读及已构建SPA bundle提取，未在运行环境验证接口行为，未修改业务代码。
- 云端环境无法访问服务器运行目录，未执行服务器同步；需在本地电脑拉取本分支提交后按GitHub→同提交服务器流程同步并核对三方提交号。

## 2026-10-13：推广系统只读扫描

- 用户要求扫描推广系统。范围为独立推广中心（promotion.php、promotion/目录及已构建SPA）与CRM推广（crm_marketing三份模块、发送队列worker、五步向导及旧向导前端）。本轮在云端工作环境执行，只读源码并运行仓库CI脚本；未连接正式服务器或数据库，未发送邮件，未修改业务代码。
- 本机PHP8.3全量语法/74套契约、JS全量语法/26套契约通过。CI未覆盖独立推广中心接口、定时发送与追踪脚本；CRM推广MySQL集成和浏览器套件也不在CI内。
- 结论：CRM推广v2主流程（预览确认、冻结内容、逐账号签名与限速、原子领取、禁联过滤、人工结果必填）与设计文档一致。另发现权限校验范围、推广邮件重复发送/卡住、独立推广中心若干接口防护三类需要修复的问题，按高/中/低分级。公开仓库不记录可利用细节，完整报告与证据位置留在仓库外。SPA默认调用真实接口，仅?demo=1使用演示数据。
- 待用户确认：正式环境是否仍在使用独立推广中心及其定时任务；Web服务器是否可直接访问推广目录下的迁移脚本与上传目录；正式库是否有卡住的推广队列。确认后再决定修复范围或下线独立入口。
- 本轮仅修改本记录。云端环境无法访问服务器运行目录，未执行服务器同步；需在本地电脑拉取本提交后，按GitHub→同提交服务器流程同步并核对三方提交号。

## 2026-10-09：手动报价说明与表单参数联动修正

- 用户反馈手动修正说明后，左侧填写的参数不再显示。原因是上一版完整Specification覆盖了后续参数生成，Power输入另缺实时刷新事件。本轮把手动产品说明与功率、角度、色温、显指、IP及四项备注分开，参数按当前行输入实时生成，已删改说明保留；清空参数不恢复产品默认值。
- 新标记quote_spec_display_mode=live_parameters仅在报价行产品JSON中使用。可编辑报价打开或预览时，在浏览器内接续旧手动文字，拆出已识别的旧参数和对应尾部备注，保存整张报价时持久化。未标记的已审核/已发送历史文字及订单独立说明保持原样，无数据库迁移。
- 修改范围：quotation.php、quote-display-text.js、两份报价导出、新纯函数includes/quote_display_text.php、三份既有测试及共享合成夹具、本记录，共10文件。仅报价显示，不改BOM、物料、来源规格、成本版本及金额逻辑。
- 候选检查：619项PHP语法/74套契约、96项JS语法/26套契约、7段报价内嵌脚本通过；旧英文PDF和手动规格契约通过。390/768/1440合成浏览器使用真实输入监听验证手动编辑后改参数、清空、JSON重载、价格保持、锁定及布局；前后端共享7种用例核对预览/PDF函数及实际Excel XML一致，含产品型号小数点不被误当行号删除。
- 正式只读事务确认新标记和空说明通过原BOM保存校验，来源关键件篡改仍被拒绝。功能20d9447525a57fe9dcd0327035758c67576468c7已先推GitHub main再限域同版上线，正式619项PHP语法/74套契约通过；正式域名提供的脚本与本机摘要一致，6类受保护数据摘要及35项无关文件/元数据保持。
- 未保存真实测试报价；交互验收为合成浏览器，未冒称正式单据全流程。型号行号识别的边界保护及本收尾记录随后按同流程同步并复检，详细摘要和恢复副本留仓库外私有运维目录。

## 2026-10-09：仅报价展示文字编辑

- 用户明确授权在报价系统修改，BOM和物料保持。本轮增加“修改报价文字”弹窗，当前产品行的完整Specification可人工修改、翻译或清空，支持恢复自动生成；应用后由保存整张报价单持久化。手动文字固定，调整配置需显式恢复自动后再编辑。
- 展示字段quote_spec_display_override仅保存在报价行产品JSON中，不覆盖quote_spec、BOM版本、成本或主数据。新选产品使用原始资料，同一报价各行独立；显式空字符串不会回退原中文。已审核/已发送报价及无修改权限保持只读，旧BOM一致性校验保持。
- 预览、审核预览、PDF和Excel采用同一人工文字，保留HTML/XML转义；没有人工文字的历史单据走原逻辑。转单携带显示文字，后续订单自身修改的说明优先，不被来源报价覆盖。
- 修改范围为quotation.php、两份报价导出、新前端JS/CSS、两份回归及CI清单、本记录；不改BOM/物料模块、表结构或主数据，不迁移历史报价。
- 候选检查：618项PHP语法/74套契约、96项JS语法/26套契约、报价7段内嵌脚本语法通过；无外网浏览器390/768/1440验证修改/删除/恢复、JSON重载、行隔离、价格保持、权限/审核/发送锁定、过期弹窗及布局。新增PDF HTML及Excel XML真实构造回归覆盖清空、手动中文、转义、转单独立说明；旧英文导出及旧手动规格契约通过。
- 生产只读事务验证新增展示字段（含空串）通过原保存校验，改动原quote_spec仍被拒绝；记录BOM、物料、参考版本和历史报价摘要。未保存真实测试单据。
- 功能22b367ae11c56174e950b0c8475ed36949247d40已先推GitHub main再限域同版发布。正式618项PHP语法/74套契约通过；域名提供的新JS/CSS与本机摘要一致。6类受保护数据（BOM原行/审批快照/物料/物料状态/成本版本/历史报价）上线前后摘要相同，35项无关现用文件和元数据保持。详细摘要与恢复副本留私有运维目录。
- 浏览器验收使用无外网合成页面，未保存正式测试报价；正式保存校验通过只读原函数验证，不冒称完成真实单据全流程操作。收尾记录及夹具倍率数值类型修正随后按同一流程同步，无剩余功能待办。

## 2026-10-09：报价关键件中文无法手动修正的只读诊断

- 用户要求查因，本轮不调整业务逻辑或保存真实报价。正式与本机 HEAD 均为 a06618483037f7a1a2f1ef023ac20fe93b242c30，报价页面及两个版本模块摘要一致；现用其他模块改动保持。
- 原因：有 bom_version 的产品隐藏手动修正入口；quoteSpecMatchesProduct 拒绝旧独立规格编辑器回写；保存校验要求 quote_spec 与所选 BOM 版本完全一致。该限制始于9月21日版本绑定，10月6日预审当前参考成本接入后扩大了适用范围，报价自身未审核也会触发。
- 样本的 Optic 中文来自 BOM 反光纸内部规格；关键件识别按“反光”归类，提取不到品牌型号时回退到原规格。旧编辑页成功提示没有检查父页面是否实际接受修改，可能造成“已保存但报价未改变”的误导。
- 验证：生产只读事务运行原函数，原始版本校验通过，清空 Optic 后被关键件一致性校验拒绝；未调用保存接口。前端原函数隔离复现未绑定可回写、版本绑定拒绝回写、按钮隐藏及中文回退，共5项断言通过。不是全页面交互验收。
- 建议下一步：独立保存客户可见报价文字的人工修正/隐藏值，与 BOM 用料、成本、版本摘要分离；不能只恢复按钮或直接改写 BOM 原始规格。本轮未实施该方案。
- 本轮仓库仅更新本记录，检查文档差异与范围后按既定 GitHub→同提交服务器流程同步，不部署业务功能、不迁移数据。提交号以本节文档提交及部署核对输出为准，详细样本与复现脚本仅留本机私有维护目录。

## 2026-10-06：物料反查规格与完整费用修正

- 用户反馈“使用BOM”混入不同规格，以及物料库仅显示材料单价。反查改为核对当前物料ID关联的名称、型号、完整规格、已知单位/品牌和有效行校验；移除名称包含匹配的宽泛候选。结果显示实际用料名称/规格/数量，并可打开对应BOM行。
- 新增只读bom_material_usage投影，分页物料库按BOM分项显示加工费、两次表面处理及费用、材料单价和单件合计；不同费用组合保留独立方案。原库内材料单价明确标注未含加工/表面；无相符BOM费用显示未配置，不推定加工费为零，也不把合计写回材料单价造成重复计费。
- 修改bom.php、bom-lifecycle.js、bom_lifecycle/bom_material_read模块、新投影模块及三份回归文件；不迁移表结构、不修改正式物料/BOM/报价金额。仅物料库分页请求费用，编辑器轻量全库选择保持；读取一次仅保留一个BOM的原行，保留原JSON键顺序以核对历史行摘要。
- 验证：同名不同尺寸、不同单位/品牌、过期行关联排除；完整单件费用、数量小计、多费用方案、隐藏成本权限及原工作流隔离MySQL回归通过。真实库只读抽查同名三种螺丝各自返回27/14/3条且规格一致；外环6.31+1.46+1.70+1.50=10.97。20/50/500条分页峰值6MiB，最大页约434KB；这些为服务器函数样本，不代表整页加载承诺。JavaScript语法及25套契约通过；候选PHP全量语法和73套契约已通过。正式同版发布收尾待补。
- 发布完成：功能337022b1790090e6e7cc2b0d81aa7ef1656f2009及表头范围修正f6aee24e3ba317b85e93e54155557e7090dd8323均先推GitHub main再限域同版部署。正式617项PHP语法/73套契约通过；94项JS语法/25套契约通过，隔离MySQL通过。35项服务器无关文件和元数据保持；BOM原行、审核快照、报价、订单及目标报价策略上线前后核验保持。
- 已登录正式浏览器验证物料库费用分项、单件合计、同规格27行反查及完整规格展示；BOM编辑器原单价列保持。只读验收未保存测试业务单据，正式库无本轮迁移写入。不同加工/表面方案分别列出，缺匹配来源时显示未配置；不自动推断规格或补价格。恢复副本位于站点外私有目录，详细业务样本仅留本机运维记录。
- 跳转明细行前同步完成文本框高度计算，避免异步展开规格导致定位漂移。该显示修正和收尾记录随后按同流程同步并核对本机/GitHub/服务器同一提交；无正式业务数据写入。

## 2026-10-06：BOM三阶段、物料版本及报价联动

- 用户已批准七项整体实施，不再逐项确认：草稿/预审/终审、历史未审迁为预审并重同步、全部有效BOM行纳入物料库、调价审计、终审双成本、新报价当前成本与旧报价保留、物料反查。此规则取代9月30日首次冻结未审价的规则。
- 本地实现：新bom_lifecycle模块及显式迁移CLI；预审保存同步不可变成本版本，终审方案锁定、审批快照与当前参考成本分离；确认标准价变动同事务更新引用预审/终审和对应价格策略，未确认价保留独立来源值。
- 物料通过独立行索引关联，历史/终审原行及审批快照不改写。缺单位/身份疑点/标准价未确认的记录保留待核对状态，不推定PCS或确认价；页面有待核对筛选、逐笔价格记录和精确/文字候选反查。BOM行专用价与随库标准价可选，变更需原因。
- 报价保存成本版本和材料价格摘要；同型号多BOM需要明确选择，已保存报价提示新版本后显式更新；已审核/已发送报价需另存版本。迁移和自动同步不更新报价/订单正文或金额。
- 检查通过：隔离MySQL验证迁移计划变化拦截、私有备份、重试、原行/快照保持、预审保存、确认/待确认调价、终审双成本、版本防篡改、引用反查；原工作流并发与回滚回归通过。合成实际浏览器验证新阶段编辑/锁定、价格原因与历史/反查弹窗；旧物料分页在390/768/1280、报价版本在390/768/1440通过。
- 功能eb994b021a6b13f8c7a4aa8893957ec6565b5528及零成本保护3f719cf7ff2343b481f8453869b9cc87dba1bdd6均先推GitHub main再限域部署，35项现用无关文件摘要和元数据保持。部署前隔离及部署后正式616项PHP语法、73套契约通过；93项既有JS语法/25套契约、新JS单独语法通过。预审零成本不能直接作为有效报价成本，终审明确零价允许。
- 正式迁移已完成：464份历史未审转预审、2份保留终审、1份保留草稿；11290条非空明细建立关联、7条空行跳过，新增3724条待核对物料，更新198条价格策略。BOM原行/合计、5份快照、300份报价及142份订单迁移前后摘要一致。7份型号缺失/不规范或零成本保留异常，不猜型号或虚构价格。完整恢复资料留站点外私有目录，不上传仓库。
- 显示格式补充f9a6be0633ea678d41ba4f5b7d9f08fc5c88496f已先推GitHub main再同版部署，正式语法/契约复检通过。按计划摘要修正383条同物料ID的显示格式关联，原行/快照/历史单据摘要仍一致。现有7314条精确关联、3724条临时身份关联、252条身份疑点关联；疑点不参与标准价传播。有效库共4932条，新增条目单位和标准价待确认，不能将入库视为已完成采购资料核实。
- 正式登录浏览器实点已验证：预审编辑器与当前成本、逐笔价格记录、物料库4932条/核对状态/使用BOM反查、报价产品库同步新成本。没有保存真实测试BOM或报价；业务写入、并发和异常回滚通过隔离数据库验收。上述七项功能、授权迁移均已完成，7份源数据异常及252条身份疑点保留待业务核实。最终收尾记录随后按同流程同步，恢复记录与详细异常清单留仓库外。

## 2026-10-04：新建个人待办置顶并滚动定位

- 用户要求新建个人待办保存后显示在个人列表最上面，页面跟随滚动。个人/私人待办创建时仅给创建者写入自动顶部顺序，与任务创建在同一事务内；多次新增按新ID在前。列表刷新保持，拖动后沿用手动顺序，旧任务和派工排序规则保留。
- 个人新增不再进入原来的5秒底部停留逻辑。滚动定位选择实际可见的表格行/手机卡片；首次打开提醒不覆盖创建目标。原来的当天18:00默认值保持。
- 修改：dispatch_next.php、dispatch_next_api.php、PHP隔离回归、既有JS运行回归、PHP检查清单及本记录。未迁移表结构、改历史任务或创建正式测试待办。
- 验证：本地93项JS语法/25套契约通过，包含15项新增高亮/顺序/滚动原函数用例；当天18点回归通过。隔离服务器副本612项PHP语法/72套契约通过，覆盖新建顺序、刷新读回、连续新增、手动排序、用户隔离和事务失败回滚。合成浏览器页面验证真实滚动与可见行选择，不替代正式创建验收。
- 发布完成：功能提交1e145611d62f14dd6274d76f6ebce7188fc02637已先推GitHub main，再限域部署正式目录，本地/GitHub/服务器同版且页面与API摘要一致。正式612项PHP语法/72套契约通过，登录入口HTTP200；只读核对排序列为有符号整数、任务/顺序/日志三表均支持事务。35项现用无关修改及元数据保持，发布前文件已留恢复副本。
- 桌面表格和手机卡片两种合成页面均已实点验证：新建后首行出现新待办，浏览器从底部平滑滚回目标。未在正式系统创建测试待办。GitHub自动工作流本次查询尚在排队；实际发布依据已通过的本地/隔离检查执行，正式复检通过。本节收尾记录随后按同一流程同步，无剩余功能待办。

## 2026-10-04：快捷新增默认当天18点截止

- 用户要求派工待办表格底部“选择截止时间”默认当天18:00。个人和派工快捷新增行现在显示当天日期，提示完整时间，自动保存携带明确的18:00；当天已过18点也不顺延。手动改选日期保留时分，刷新后的新空行按当日生成默认值。既有任务及截止时间修改权限规则保持。
- 修改文件：dispatch_next.php 和本记录。仅调整快捷新增行的显示、草稿默认值和日历选日时间保留；没有修改API、数据库或其他模块。
- 检查：本地JavaScript语法和25套既有契约通过，页面内嵌JavaScript语法通过。隔离执行原函数验证个人/派工保存载荷、晚上仍当天18点、手工时分保留、跨天及空行不创建任务。当前电脑无PHP可执行文件，PHP检查在服务器独立临时Git源码副本以PHP8.0运行，71套契约通过；未加载正式业务配置或保存真实任务。
- 发布完成：功能提交1f6176fca1eed7dbecd1803f1d61a7ec7e4d4e59已先推送GitHub main，再限域快进唯一正式目录；本地、GitHub与正式HEAD一致。GitHub验证任务通过，正式611项PHP语法与71套契约复检通过。生产其他13项已跟踪修改及未跟踪文件共35项逐一核对内容与元数据保持，发布前文件已留恢复副本。
- 正式登录浏览器刷新后，个人和派工两处快捷新增行均显示10/04，旧占位文字消失；18:00载荷及手动更改由上述原函数隔离验证覆盖，没有创建真实任务。无剩余功能待办。本节收尾记录随后单独按GitHub main→同提交限域部署同步，不追加业务修改。

## 2026-09-30：未审核BOM首次成本同步

- 发布收尾：功能bf86768aac92419b3d56d2c234150a8b04340037已先同步既定三个GitHub分支并限域部署；正式611项PHP语法/71套契约通过，无关现用文件摘要保持。生产按已核对计划哈希补齐有效缺失成本及相关价格策略缓存，保存发布/缓存恢复副本；上线后报价成本映射及版本目录均能读到新增发布，原有全部发布、BOM审核/版本元数据、快照及历史报价元数据逐项保持。未创建真实报价或提交审核。
- 正式浏览器已恢复可用，已登录报价产品库实际搜索本轮两份新增成本（含待审核BOM）均显示成本和新同步时间。首次写入、并发和审核切换在独立合成数据库验证，不在真实BOM上代替用户保存/审核。不再以当前桌面锁屏描述本轮结论。
- 剩余不是同步阻断，而是源资料异常：零成本、缺规范型号/绑定的既有单仍无法正常取价；其中已有冻结记录的单未覆盖、未猜型号或补价格。具体单号/型号/成本及恢复路径仅在私有运维记录，需用户补齐源资料后再发布。本收尾记录随后单独按三分支→同提交限域部署流程同步，不追加业务改动。
- 用户要求补齐未审核BOM至报价中心，并确认“首次保存同步未审核冻结成本，之后改价需审核才更新”。保留既有审核/历史冻结版本，不代替审核，不改历史报价与订单。沿用本机现有工作树，服务器其他现用修改保持。
- 增加明确扫描校验值的补齐工具，仅插入缺失、有效、人民币、可匹配型号且正成本的未审核发布；扫描后内容变化即拒绝整笔写入。发布与对应价格策略缓存同事务，使用现有审核优先/同级较高成本规则，不任意覆盖同型号版本。零成本、无明细/型号、无效金额、过大明细或非人民币成本保留草稿并提示原因，不静默当有效成本发布。
- 日常保存采用同一发布锁：首次有效保存冻结一份未审核成本，再次保存不更新冻结版；审核通过后才发布新成本。已作废发布不自动复活。保存反馈、BOM状态和报价来源明确区分未审核冻结与审核快照，不伪造审核版本或快照。
- 文件：bom.php、成本发布/工作流/报价版本模块、新同步模块及CLI、两份新增合成回归、既有工作流数据库回归与PHP检查清单、本记录，共11文件。源码与合成夹具不含正式数据或配置；私有扫描、恢复及发布记录留仓库外。
- 候选验证：新增首次保存/重试/并发、改草稿冻结、审核切换、实际改单后的扫描失效、缓存失败事务回滚、补齐待审/草稿、已有发布与快照保持等独立无网络MySQL回归通过；原撤回审核/快照作废/报价版本完整回归通过，两个实例均已停止。候选620项PHP语法、71套契约及本地93项JS语法、25套契约通过。正式只读扫描确认存在缺失发布项，其中零成本一项暂不发布；候选扫描峰值4MiB，不读取原图。
- 当前待发布：起点13ef5f6。接下来先推送既定三个GitHub分支，再限域同版部署、正式复检并按扫描哈希执行用户授权的补齐；生产执行前保存发布/缓存与单据元数据恢复记录。正式登录浏览器因Mac锁屏尚未补验，不把合成或只读函数测试冒充正式端到端实点。

## 2026-09-30：共享物料库内存与分页修复

- 发布收尾：功能0812e0b7678d02cd76c5cb48a9a3179e0d1c2f24已同步既定三个GitHub分支并限域同版部署；正式607项PHP语法/70套契约通过，本地93项JS语法/25套契约通过。三份核心源码摘要本地与服务器相同，服务器其他现用修改保持，发布恢复副本保留。首次部署命令传短提交号被一致性检查拒绝，未更改文件；随后使用完整提交号成功。
- 部署后正式路径只读复验第一页、第二页、关键词搜索及最大原图/缩略图通过；20条摘要含筛选选项约9KB，样本约0.01秒，非整页速度承诺。未登录HTTP请求仍返回401 JSON，未放宽权限。没有触发正式物料保存、同步或审核。正式登录浏览器实点仍因Mac锁屏待补，不把合成测试与纯函数只读检查当作端到端正式验收。
- 本轮四份已停机合成数据库固定路径/标记/无进程和打开文件核验后已永久清理，释放约0.91GiB，可由夹具重建；正式数据库、候选源码及发布恢复副本未删除。本节收尾记录随后单独提交并同版同步，不追加业务功能变化。
- 用户要求继续修复，沿用现有可用本地工作树（本机没有规则中的另两台电脑路径）。共享物料库改为服务端分页/搜索/日期及分类品牌供应商筛选；摘要不传原图，缩略图只在可见区按需读取，最多3个并行请求并限制页内缓存。读列表不再初始化表结构或自动同步重量型材，显式同步按钮保留。
- 编辑器全库联想/选择仍分批读取轻量元数据，保留原有分类匹配、最近选择和价格规则。页面请求序号防止旧结果覆盖新筛选，失败显示明确HTTP/空响应信息及重试，不继续假装读取中。下拉选项来自有效物料，保存后的筛选不会被基础选项刷新静默清空。
- 物料文字/价格编辑不传图片时保留原图，明确上传或清除才替换；隐藏价格/供应商保存时保留原值。类似件、重复检查和匹配不再拉取原图。Excel仍导出全部筛选结果而非当前页，原图逐张显式读取，仅导出时加载，不改原文件。
- 检查：70套PHP契约通过；前端25套契约通过。桌面1280/768/390合成浏览器验证分页、全库搜索、失败重试、旧响应隔离及不丢图的编辑；隔离MySQL在128MiB下验证超过128MiB的图片库摘要分页、Unicode/字面量搜索、权限、日期、缩略图与保存图像保持/替换/清除，峰值约28MiB。测试夹具两次失败均已修正并重跑通过，不记失败为通过。
- 正式只读事务候选抽查：20/50/500条摘要读取成功；最大原图内容摘要一致，缩略图在原128MiB限制下成功，单图峰值约46MiB。没有保存/审核/同步/删除真实业务数据，没有提高服务器内存配置。
- 本轮文件为bom.php、bom_api.php、独立读取模块、三份新回归、既有BOM查询契约、两份检查清单和本记录。起点fde82d6；候选最后复检后将先推送既定三个分支，再限域同版部署、正式只读复检。服务器其他现用修改必须保持。正式浏览器实点因Mac锁屏仍待补验，不能声称全流程正式实点已通过。

## 2026-09-30：共享物料库持续报错只读诊断

- 用户要求查因。正式 BOM 页面/接口与本地源码摘要一致，正式 HEAD 仍 fde82d6；服务器已有其他模块未同步修改，本轮保持原样。
- 正式错误及访问日志确认 materials_list 多次在 bom_api.php:34 JSON 编码处耗尽 128MiB 内存，HTTP 500 返回零字节；不是仅前端 JSON 解析格式问题。只读事务聚合确认整库图片字段明显大于文本字段。
- materials_list 仍 SELECT * / fetchAll 读取全部有效物料及原图，前端分页与筛选仅发生在完整下载之后；同一加载函数还用于编辑器联想/选料。列表读取会运行重量型材自动同步和初始化逻辑，存在读写耦合。失败后页面仍保留读取中状态，提示未区分 HTTP 状态与空响应。
- 建议真正服务端分页/筛选，摘要不携带原图，当前页缩略图独立按需读取，编辑/选料/导入导出兼容校验；自动型材同步与查询分离，补充明确错误及重试。修复须在原 128MiB 限制下验证，保留原图、成本及审核快照，不仅调大内存。
- 本轮仅查日志、核对源码及数据库只读聚合，无业务代码/数据修改，无真实接口写入、推送或部署。只更新本地本记录，尚未提交；待用户修复指示后依固定流程同步。

## 2026-09-24：恢复合并出货PL/CI原PDF与Excel版式

- 收尾：功能提交2d4bf0382036dc4fb70ff28ea1773a06eaacb75a已推送main、codex/crm-unified-release-20260906、codex/quote-memory-20260914并限域部署广州，正式604项PHP语法/69套回归全部通过；服务器无关修改摘要核对一致，恢复副本保留。部署后正式路径只读复验待装计划和两份旧批次的PL/CI共六组PDF/XLSX成功，图片与数量保留；新计划样本约0.36–0.41秒，含官网图片的旧批次约4.3秒（仅本次样本，非全部订单速度承诺）。
- 浏览器已打开原CI预览，但部署后最后刷新遇到Mac锁屏，工具无法解锁；已请求用户方便时解锁，不要求登录数据库。因此本轮不声称“部署后浏览器实点下载端到端全部通过”。服务器导出及合成浏览器下载事件已经分别验证，正式浏览器这一项仍待补验。
- 本轮两个临时MySQL实例均停止，逐项核验标记、无套接字/PID、无进程、打开文件或挂载后，仅删除这两份可由夹具重建的合成数据库，释放约1.16GiB。正式业务数据、源码候选、私有样张和恢复副本未删除。本收尾记录随后单独提交并按相同三分支/同版部署流程同步，无追加业务改动。

- 用户批准“改”。原quote_order_doc的帮助函数和HTML页面拆为includes/quote_document_template.php、quote_document_page.php，新批次通过quote_batch_template_adapter.php适配并共用；CI恢复11列（图片、尺寸、客户/厂家型号、颜色、HS Code），PL恢复15列（图片、PCS/CTN、长宽高、箱数、净毛重、体积），保留原抬头、条款、汇总与不显示银行/蓝章的既有规则，不再使用简化6/8列表格和伪.xls。
- 新批次Excel复用quote_order_excel原生XLSX样式、合并单元格、图片与A4打印设置；修正原导出重复行导致标题丢失、CI标题高度、公式缓存/逐行两位金额、体积四位和图片宽度。PDF数字不拆行，续页页边距及汇总区防拆分页。通过PDF/表格技能实际渲染检查，不以下载成功代替版式验收。
- 装箱按本版明细拆行，混箱包装列合并且箱数/净毛重/体积只计一次；未装箱草稿保留所选产品并标注待装，签发版缺装箱则拒绝不完整导出。已签发/历史替代/取消/草稿状态在PDF/XLSX均保留；预览不提供仅本机有效的编辑，避免与下载内容分叉。
- 新保存计划冻结原订单单证元数据、设置、尺寸和HS Code；旧计划只读回退原订单保存信息，不查实时客户/产品主档。数量、价格、箱规仍取选择的计划/签发版，图片逐张安全缩略，不改原图、不改历史数量或签发记录。
- 修改文件：三份新共用模板/适配器、quote_order_doc.php、quote_order_excel.php、quote_batch_document.php、includes/quote_shipment_document.php、quote_shipment_batch.php及八份相关回归测试。无关本地/服务器修改保持。
- 验证：候选604项PHP语法、69套契约通过；本地客户端及390/768/1280合成浏览器下载回归通过。独立MySQL两次完整回归，覆盖混单/部分出货、拆箱、追加/撤回、并发、重试、回滚、历史签发与旧元数据回退，测试实例均已停止。六份合成PDF/XLSX经实际渲染检查；PDF约0.35–0.46秒/份，导出峰值约28MiB（仅合成样本）。
- 正式数据在只读事务下核对：一份待装计划与两份旧出货批次各自PL/CI均生成PDF/XLSX成功；待装计划所选656件、两张图片保留，原业务源数据未变。隔离源码目录无正式图片，私有验收脚本仅安全读取原图到内存；生产路径不需此适配。未发送邮件、签发、确认出货或改真实订单。
- 当前发布前记录：功能起点33cf05b；本地代码已完成上述候选验证，下一步将本节同提交推送既定三个GitHub分支，再限域部署广州、正式复检与三方版本核对。私有样张/运维脚本不入仓库。原模板字体文件本就未安装，保留既有字体声明及回退，不声称补装字体。

## 2026-09-24：用户指出PL/CI未沿用原格式（只读核对）

- 确认新装运批次走quote_batch_document.php→qsd_html/qsd_excel简化输出，未复用旧quote_order_doc.php的既有版式和Excel导出。新CI仅6列、新PL按箱8列，原CI有图片、尺寸、客户型号、厂家型号、颜色、HS Code等11列，原PL有图片、分列长宽高、PCS/CTN等15列；不是仅下载入口问题，模板兼容验收遗漏。
- 原模板及旧批次导出代码仍保留，未被删除；应将新批次/混箱的数据适配至原PDF和Excel模板，保留抬头、条款、列顺序、图片、签章/页脚原有规则与打印样式，不以简化新表替换。之前数值/下载验证不能代替原格式一致性验收。
- 本轮用户询问确认，只检查源码并记录结论，未修改功能、提交、推送或部署；正式与GitHub功能版仍为33cf05b。下一步待用户指示按原格式修复，并分别与原PDF/Excel样张逐项对照。

## 2026-09-24：合并出货、包装资料与单证下载联动修复

- 收尾状态：功能版732a650已同步三个既定GitHub分支及广州目录，601项PHP语法/69套契约复检通过；新增真实下载事件合成回归在390/768/1280通过。正式只读实点确认已完结旧单不再入候选、保存计划可见、重新打开计划后精确包装方案及跨产品代码提示正确；未点击保存、签发或实际出货。
- 正式PL文件已在本机找到并解析，页数、草稿标记和所选数量正确；PL/CI预览与PDF/Excel服务器响应正常。但本机后续连续下载未出现新增下载记录，已观察到已安装应用接管链接，追加download语义后仍需用户手动确认，不能把HTTP成功或合成测试写成所有正式下载端到端通过。已发起一次手动下载确认，不更改浏览器设置或权限。订单PI独立路径本次未完成正式下载补验。
- 仍待核对一份历史批次汇总与逐行数量冲突；只隔离再次出货风险，没有擅自更改真实历史数量。此收尾记录随后单独按三分支→同提交限域部署流程同步，无追加业务数据变更。
- 功能f4a2d43及历史官网图片兼容5bafb27已按三分支同版部署，正式601项PHP语法/69套契约通过，无关现用改动保持。登录后实点已确认真实待装批次显示、PL/CI选择入口及草稿预览；服务器下载响应正常，浏览器首份PL下载完成。发现本机安装应用会接管普通下载导航，追加下载语义及实际download事件回归，随后按同版流程补验，未改浏览器权限。
- 三个本次停机合成验收库已逐项核验标记、进程、打开文件和挂载后永久清理约1.73GiB，可由夹具重建；正式数据库及发布恢复副本保留。
- 首次发布f4a2d43后，真实旧单证图片渲染补验发现历史官网资源地址被过严拒绝。补充仅允许已验证官网固定产品图片路径，强制HTTPS、公网地址固定、无跳转/代理/凭据、8MB与15秒总预算；取图后缩略并内嵌，同张图片仅读取一次，浏览器渲染器仍禁网络。此兼容修正同样本地测试→GitHub→同版部署，不在服务器直接改源码。
- 用户要求三个问题一起修复并测试。候选、展开产品、保存/签发统一可出量规则：排除已关闭/已出货、费用项、被其他计划全部占用及历史缓存高于出货明细的异常订单；编辑本批时扣除自身占用。未重写真实历史数量。
- 订单详情统一列出待装计划、签发版、实际出货与旧批次，并按关联去重、显示本单参与数量。保存待装计划不伪装实际出货；保留旧批次查看、草稿编辑及单证管理入口。
- 装箱按型号提供精确客户产品代码、通用及其他变体方案，显式选择后仅补装未分配数量，生成整箱组/尾箱，不覆盖手工装箱；其他变体须确认，尾箱估算重量须核对后方能签发。重新打开旧计划也读取包装选项。
- PL/CI入口不再打开新建出货；选择已有批次后可独立预览、下载PDF/Excel。计划修改后原签发版仍保留并标记历史；下载/预览不写出货状态或签发时间。订单PI走原独立路径，未混为同一根因。
- 旧单证图像改为逐张、安全尺寸内导出缩略图，保留原图/快照；大于安全上限或缺失明细明确拒绝，不以空图伪装成功。PDF保留浏览器沙箱、禁止外部网络资源、限制并发和执行时间。PDF技能用于实际合成单证渲染与逐页数量/金额/布局核对，并据此调整列宽及分页。
- 候选验证：601项PHP语法/69套契约、92项JS语法/24套契约通过；独立MySQL覆盖跨单混箱、部分出货、撤下再加回、占用、重复提交、版本冲突、回滚、旧新批次去重和轻量读取；合成浏览器390/768/1280三宽度验证选择、装箱和独立下载链接。20次8MB图片缩略测试峰值约26MiB；合成PL/CI实际PDF与Excel结构/数量/金额通过，最终单次PDF约0.6–0.8秒（非全站性能承诺）。
- 正式数据仅只读核对：被反馈旧已出订单排除、待装计划可查、包装代码精确/其他变体区分正确；历史数量冲突保持原样。具体业务证据和运维脚本留仓库外，不上传客户资料。
- 起点131469e；候选阶段浏览器登录曾过期，后续已恢复并完成上述只读实点，以本节最上方收尾状态为准。测试不发送邮件、不创建真实订单/出货、不修改历史业务事实。

## 2026-09-24：订单中心批次缺失只读诊断

- 追加截图反馈PL/CI按钮不能下载。已从实际quote-shipment-batch.js提取覆盖函数做无网络运行验证：quickGenerateOrderDoc忽略传入pl/ci，两个按钮均只open(orderId)进入装运工作台，未查询已有批次或导出；这是新工作台覆盖旧单证入口的回归。另新批次quote_order_doc非Excel路径重定向quote_batch_document时未保留type/format，新页只输出合并PL+CI打印HTML，未提供独立PDF下载路径。修复应拆开新建出货与查看/下载单证、多批次显式选择、预览不改变出货事实，不能简单恢复会调用mark_document_generated的旧路径。
- 用户文字提PI，截图实际为PL/CI。订单PDF/PI走独立exportOrderDoc路径，未被此覆盖函数替换；本轮没有证据确认PI导出也故障，需具体错误或后续安全验收，不能把已证实的PL/CI原因扩大到PI。仍未修复、推送或部署，未调用有写入副作用的正式单证接口。
- 追加反馈已出订单仍被合并候选带出、已有装箱资料不回填。只读复现已完结/已出货旧单因明细合计低于订购量仍命中qb_candidates；候选只排除取消/作废，既不阻止完成状态与数量冲突，也未扣除待装计划占用/排除费用项（展开和保存层另有扣除，导致口径不一）。需异常隔离、复核后留痕解锁，不能把差额直接视为可再次出货。
- 新qb_items与装运前端未接入包装资料查询，新增箱直接空箱规/零重量，旧qo_prepare_items具备的packaging_options/profile未迁入新工作台。正式相关产品确有已维护箱规，但仅一个客户产品代码精确匹配，另一变体缺精确或通用配置；旧fallback还会按型号取其他客户产品代码，修复不能照搬此风险。建议精确/通用匹配、多方案显式选择、跨变体确认、按所选量生成整箱与尾箱并保留人工调整，不覆盖已保存历史装箱。
- 上述追加仍仅只读诊断及本地记录，没有代码修复、业务数据变更、推送或部署。
- 用户反馈客户已有出货而订单详情提示暂无批次。正式只读事务确认：新装运计划存在且仍为planning，只有save事件，尚无正式shipment关联；订单详情只查询旧quote_shipments/quote_shipment_items，没有并入quote_shipment_plans，导致已保存待装计划被空态隐藏。另两份历史订单批次均能被现有查询命中，不是所有历史出货丢失。
- 另发现一份历史批次头部/订单缓存数量与逐行明细合计不一致，暂未确定真实出货量，不能擅自按任一数值改历史记录。具体私有证据和只读诊断脚本保留仓库外work/crm_audit/ex028_shipments_readonly_20260924.php，不上传客户明细。
- 建议详情统一展示待装、已签发、实际出货及历史批次，计划与正式记录关联去重，显示本单参与数量；只有两类皆无才显示无批次。禁止将保存计划自动判成已出货。历史数量差异需核对后走留痕更正。
- 本轮仅诊断和本地记录，无业务代码/数据修改，无推送或部署。正式HEAD仍131469e，与本地一致，四份相关源码摘要相同；无关现用修改保持。等待用户修复指示，不因上下文同步惯例扩大只读请求为外部写入。

## 2026-09-21：BOM审核快照作废

- 发布收尾：功能c21114d已原子同步main/现用发布分支/本工作分支，限定16文件同版部署到广州，部署前备份且无关现用文件摘要保持。正式597项PHP语法/68套契约通过，本地89项JS语法/24套契约通过。最后正式浏览器实点时Mac锁屏，已请求解锁；该项待补，不能宣称正式页面全部验收完成。原未保存报价页保留，没有作废任何真实快照。
- 本次3个已停机合成验收库逐项核验路径、标记、进程、打开文件和挂载后永久清理约762MiB，可由夹具重建；正式数据及回滚备份保留。本收尾记录随后按相同三分支→限定文件同版部署流程同步，不追加功能或业务数据改动。
- 用户确认加入。快照查看区新增折叠作废入口，仅退审权限且可查看成本者可操作，原因必填；记录操作者、时间、原因和替代版本。采用现有工作流事件作为追加式作废记录，原快照正文不修改、不删除，无新表迁移。
- 作废当前报价取价版本必须明确选择同BOM同型号未作废的历史版替代，或暂停此BOM新报价取价；不自动回退。作废历史版不改变当前发布版。重新审核生成新快照可以恢复取价，不能恢复已作废版本。
- 报价版本列表/预览显示作废或暂停并禁止采用；保存事务再校验且与BOM发布共用锁，拦截过期页面、复制新单和新增重复引用。原报价已有引用允许保留，原价格/关键件/订单不改；旧无版本单据涉及暂停项时仅允许保留原行。原单界面提示失效来源。
- 验证：候选597项PHP语法/68套契约通过；隔离MySQL验证替代/暂停/历史引用保留、新引用拦截、原因与权限、错误归属、回滚、幂等及双连接冲突。首次作废后大图报价保存/审核仍在128MiB限制内通过，转订单及原金额回归通过。两套离线浏览器390/768/1440验证必填、显式选择、重复点击、失败保留、成功刷新、失效禁用和转义，截图已核对。
- 起点7261330；本轮此时尚未发布。按既定三分支先推送、同版本限域部署、正式只读页面复检收尾。不作废/保存/审核真实业务记录；隔离合成验收不能冒充正式业务写入实测。现用无关改动必须保持。

## 2026-09-21：报价BOM多版本、成本及关键件同源

- 正式补验完成：解锁后保留原未保存页面，另开独立空白报价页；D2.03501默认读取当前快照，三个历史/当前快照及各自审核时间、当前发布时间正常显示。逐项打开历史版和当前版成本/关键件对比，返回列表及关闭后仍用原快照，数量/单价未变；截图无弹窗遮挡或文字挤压。此真实样本三个成本相同，价差/降价仍以此前隔离验证为依据；没有点击确认采用、保存、审核或创建真实报价。多版本正式只读实点不再待解锁。
- 本轮无功能改动，仅更新本记录；基线925e24a已与三个GitHub分支和正式目录同版。该记录按相同流程提交推送、限定单文件同步并复检；不新增测试数据库、不改生产业务数据。
- 发布收尾：功能c8489e1已先同步main/现用发布分支/本工作分支，再限定14文件同版上线；正式596项PHP语法/68套契约通过，无关现用文件摘要未变。正式独立Chrome实点52.07523的新入口和历史未审核冻结说明，无保存/审核/写入报价。只读确认D2.03501已有3个快照、52.05528有1个；继续正式多版本预览时Mac锁屏，已请用户解锁，不能把隔离三屏通过当成正式多版本实点完成。追加无快照提示措辞与缓存版本，按同版流程复验同步。
- 两个本轮合成验收库已停机并核验路径/标记/进程/打开文件/挂载后永久清理约434MiB，可由测试夹具重建；正式库和回滚副本保留。最终需解锁后只读补验多版本列表、差异预览、取消；不替用户选择并保存实际业务报价。
- 用户明确开始实施。报价产品区新增“BOM 版本 / 更新”，按10条分页显示所属BOM、客户/用途、版本、快照号、基础成本、审核及当前发布时间；预览成本/关键件差异后才采用。历史版本可显式选择，旧报价不自动改价，已审核报价仅查看，订单快照不改。
- 新选产品默认使用唯一匹配BOM当前正式快照，多份已审核BOM必须明确选择。成本和关键件来自同一不可变快照，不再全量扫描草稿和图片；只按需读取单个版本物料，过大明确拒绝。历史未审核成本仍冻结，不伪造审批时间。
- 保存核验版本摘要、成本和关键件；快速换产品/报价、异步旧响应、确认期间新审批均防串位。手工售价、额外部件、数量与备注保留；自动售价按原规则重新计算，零成本及降价不回退旧成本。无数据库迁移、不重写历史业务单据。
- 检查：88项JS语法/24套前端契约通过；候选596项PHP语法/68套契约通过。独立MySQL覆盖重新审核由50降至20、关键件换版、旧版本不变、草稿隔离、多BOM选择、错型号/篡改/发布竞态拒绝；既有大图128MiB报价保存、审核、转订单、并发和回滚回归通过。390/768/1440离线浏览器涵盖分页、预览取消/采用、只读、失败重试、竞态和转义，截图已核对。
- 修改范围14文件：报价页面/API、新版本服务/脚本/样式、合成测试与CI清单、本记录。起点8d4e8b1；当前尚未发布，按既定三分支推送→同版限定部署→正式复检执行。真实单据没有保存、审批或换价；正式只读实点将在上线后补验，不能将隔离通过冒充正式全流程写入验收。

## 2026-09-21：BOM待审撤回

- 用户确认新增“撤回审核”。待审BOM由提交本人或管理员且具编辑权限撤回，原因必填，记录操作者、时间与版本；回草稿后可修改和重新提交。身份按登录账号核验，不按显示姓名判断；无法核对提交账号的旧记录仅管理员可撤回。
- 沿用事务锁、版本校验和幂等，审核先完成则拒绝撤回；已审核继续使用退审。保留历史快照和已发布报价成本，不重写历史业务数据，无数据库迁移。
- 检查：85项JS语法/23套前端契约通过，隔离候选600项PHP语法/67套契约通过；独立无网络MySQL验证提交人/管理员、同名不同账号拒绝、原因必填、重复请求、撤回后保存重提、审核与撤回双连接竞争、快照及成本不变。离线实际页面验证取消、撤回后解锁、重新提交，以及390/768/1440宽度按钮可见。
- 改动限定BOM页面/API/工作流、三份合成回归及本记录，共7文件。按既定先仓库后三方同版流程发布；上述测试不代表在真实业务单据上撤回或审核，没有替用户操作实际BOM。详细部署与恢复记录留在仓库之外。
- 发布完成：功能提交a7a16cf已先同步三个既定分支，再同版部署；部署前备份、限定文件和其他现用文件保持校验通过。正式594项PHP语法/67套契约通过。登录后的正式页面实点确认待审撤回按钮、原因提示、取消后仍待审且锁定；实际撤回/保存/重提仅用合成数据验收，没有操作真实单据。临时测试实例停止并逐项核验后清理约106MiB，可由夹具重建。本条仅补发布及验收结果，随后按相同流程同步，不追加业务改动。

## 2026-09-18：推广执行一致性修复（已发布，正式实点部分待解锁）

- 发布收尾：功能4d1fe86、日志兼容5c15619、排除清单185574c均已先同步main/现用发布分支/本工作分支，服务器当前功能版185574c；两次限域部署均保护无关现用文件。正式594项PHP语法和67套契约通过；最终候选602项PHP语法/67套契约、85项JS语法/23套前端契约、25种离线浏览器布局和严格独立MySQL回归通过。
- 已授权历史补齐完成：执行前专用快照，事务补齐个人工作后逐项核对执行人，重跑预检无遗漏/无重复/无冲突；邮件队列元数据和历史目标结果与执行前完全一致，未重发邮件、未新增收件对象。详细数量与快照位置仅记入仓库外私有报告，不随源码公开。
- 正式独立Chrome页面实点已确认列表真实邮件统计、中文待人工状态及计划开始时间；人工窗口实点发现排除邮箱残留后已修复并新增回归。最后重开窗口及查看冻结内容时Mac锁屏，已请用户解锁；不能将离线通过写成最终正式实点全部完成，也未冒称所有员工已收到弹出通知。原用户客户群编辑窗口未改动。
- 本轮追加两个合成测试库已核验停机、标记、进程、打开文件和挂载后永久删除约279MiB，可由夹具重建；正式库与回滚备份保留。本收尾记录按同版流程随后发布；解锁后只读复验人工清单/查看内容/任务列表，不提交真实执行结果。历史排除群仍需新补充项目预览确认，不能自动补发。

- 功能4d1fe86已先原子同步三个既定分支，再限定18文件同版上线；正式594项PHP语法及67套契约通过，无关现用文件摘要未变。人工待办补齐预检无冲突；首次应用遇到日志channel_key非空约束，事务已核验完整回滚、邮件及历史目标未变。补正维护日志渠道，同时使独立数据库夹具与正式非空约束一致，增加日志失败整批回滚和单条审计幂等验证；按固定流程复验、再发布后重试，不直接改正式代码或表结构。
- 正式独立页验收发现人工清单仍混入缺邮箱/重复邮箱等排除对象；补齐全部渠道排除区、禁止绕过预览登记结果的后端保护，并修正详情人工目标总数排除已跳过项。新增缺邮箱、重复邮箱、未知渠道的浏览器回归及真实数据库拒绝测试；不改历史目标或自动转换渠道。

- 用户确认：联系人明确设置优先且说明覆盖原因；每个实际发件账号使用自己的签名，缺失阻止确认。修复偏好模式真实群展开/去重、明确排除原因；保留禁联/离职/停用限制，不自动改渠道。
- 邮件、人工完成、人工补救与排除分别统计；列表及详细报表以邮件队列为准。人工必须填写实际结果，父任务锁与幂等保护防重复；个人待办完成与推广结果联动，到执行时间提醒。旧已确认人工工作可通过受控 CLI 先预检再补齐，不能新增收件人或触发邮件。
- 新队列共享不可变正文/签名模板并冻结变量，发送前验摘要；发送器逐封读取，不批量载入大图。不改历史正文、签名、发送事实；原排除对象必须另行预览确认，不重跑整个旧项目。
- 范围：推广主服务/投递服务/新增执行事实模块、CRM任务中心/通知/API、推广页面和样式、合成回归、CLI与通用说明。详细内部扫描报告保留在仓库外，不随代码发布。
- 候选阶段已通过：602项PHP语法/67套后端契约，85项JS语法/23套前端契约；禁网络独立MySQL覆盖群去重、联系人覆盖、签名/正文冻结、人工权限/必填/重复提交、任务联动、补齐幂等和队列不变，既有并发/发送/样品回归通过。五宽度25种布局和短屏、签名、变量、最终预览及人工结果的离线浏览器通过。通知开始时间/本人范围/暂停保护与详细报表SMTP口径测试通过；此处候选结果不代替上述正式验收状态。
- 本次5个临时测试库已逐一核验标记、进程、打开文件及挂载后永久清理，共约469MiB，可由合成夹具重建，正式库和备份未动；候选源码仍保留待发布。
- 起点15c9d52；用户已批准本批三分支同步、同版部署和历史已确认人工待办补齐。本条随功能提交按固定流程发布，随后补正式复检与一致性结果；记录编写时正式数据尚未修改。服务器无关现用修改必须保持，正式旧队列绝不重发。

## 2026-09-18：按用户确认的四类渠道实施筛选修复

- 发布与正式验收：功能提交3bebcd8先同步main/现用发布分支/本工作分支，再限定13文件同版上线，591项PHP语法及66套契约通过，无关现用文件摘要不变；本地85项JS语法、23套前端回归通过。独立Chrome正式页实点四类34/204/172/251与只读统计一致，WhatsApp个人第2/5页、切换群回第1页、暂停/不推广说明显示正常。CN010在微信个人下可搜到并显示缺微信号；再次点该分类保留关键词且仍为1条，点全部清空并回1714条。正式截图核对说明未裁切；没有保存客户或触发推广。测试实例精确核验后永久清理约42MiB，可由夹具重建，正式数据和回滚备份保留。本条为随后同版发布的收尾记录，不追加业务更改。
- 保留四个入口，改为客户主档渠道或未删除联系人active渠道并集，参数绑定EXISTS防重复；号码/群存在不自动归类。分页内显示设定来源、缺号码、群暂停/失效/不推广及禁联提示，实际发送限制不变，不修改原业务资料。
- compact/full均接入同一只读解释函数；取消当前分类只取消分类，保留关键词和高级条件，“全部”仍清空。列表说明专用自动行高，避免紧凑模式裁切。
- 本地前端23套回归通过；独立候选PHP语法和66套契约通过；禁网络独立MySQL矩阵验证四类、多联系人、数据缺失、停用/删除、权限范围、分页及源资料不变。正式只读独立集合/完整分页核对得到34/204/172/251，均无漏重，50条以内的提示数据约6–10KB；该数字为全库不附加权限/搜索的当时样本，不承诺每个用户相同。
- 三屏离线浏览器初测仅自身裁切断言漏掉父单元格限制，截图发现后修复并新增父边界断言，390/768/1280复测和目视检查通过。当前尚未发布，下一步补最终检查→提交推送→限定同版部署→线上复检；不把离线验收写成正式实点。测试实例已停止，后续按精确路径核验清理；原正式其他模块改动不动。

## 2026-09-18：CRM四类客户渠道筛选只读扫描

- 核对四份相关正式文件与本地摘要相同；只读事务统计全部1714个未删除客户。当前个人分类按号码非空、群分类按active群存在，不读取客户/联系人推广渠道，造成“联系方式存在”和“推广方式设定”语义不一致。群展示/筛选/实际执行对暂停、失效和不用于推广亦口径不同。
- 正式当前筛选命中微信个人73、WhatsApp个人1114、微信群184、WhatsApp群244；主档设定对应26/156/142/106，不将差异全部判成脏数据。进一步确认关键词/高级条件残留、再点同渠道清空所有条件、无命中解释；旧群字段与新字段冲突为兼容风险，当前筛选用新字段，不误判为直接原因。
- 私有报告位于仓库外 work/crm_audit/customer_channel_filter_scan_20260918.md。只读统计未调用有DDL/DML的初始化入口；未进行本轮正式浏览器点击验收。没有业务代码修改、客户数据写入、推送或部署；HEAD仍81abb12，本记录仅本地更新。正式其他模块现用未提交改动保持原样。
- 建议区分已设推广渠道/实际资料/当前可执行，明确来源与不可执行原因，多渠道非互斥、客户去重，统一群状态与筛选交互，再补隔离矩阵回归。当前用户仅要求扫描，等待修复指示，不因记录要求执行外部发布。

## 2026-09-15：订单列表取消内部滚动、可选5/10条

- 发布及实点：b9cfa15已同步三个既定分支并同版上线，正式588项PHP语法/65套契约复检通过，无关文件保持。在独立Chrome验收标签实测默认5单/22页→选择10单/11页→下一页→恢复5单，金额合计不变；读页显示146–202ms，首次样本540ms。刷新仍记住5单；正式截图确认列表内滚动条已取消，页面整体可自然延长。原用户订单标签未刷新、未提交任何业务操作。本记录随后单独同版同步。
- 用户截图显示旧.order-list固定max-height让20单只露出两张卡片。本轮显式取消列表max-height/overflow，分页内容完整展开；默认5单，可选10/20并本机记住选择，前后端支持5/10，保留旧API20/50兼容。卡片压为编号/状态、客户/负责人、金额/数量/出货/欠款三组，来源报价和日期仍在详情；不改变财务统计或业务数据。
- 修改分页样式/脚本/服务、报价页缓存版本、三套回归及说明。83项JS语法/22套契约，588项PHP语法/65套契约通过；390/768/1280实际样式的合成浏览器验证5/10/20、选择保存、无内部裁切/滚动、翻页/搜索/重试，1280截图确实展开5张卡片。候选对正式107单只读确认每页5/10/20对应22/11/6页，未建测试库、未改数据库结构。
- 起点fe124e4，服务器范围无差异。接下来按既定流程提交推送、同版部署并复检；当前记录不冒充已完成正式页面实点。

## 2026-09-15：订单中心真分页与慢查询修复

- 发布收尾：功能ceeb968已先原子同步既定三个GitHub分支，再同版部署；新增生成列/覆盖索引已完成，原现用无关文件摘要保持。正式588项PHP语法/65套契约复检通过；只读事务逐单对比107单金额/数量/收款/状态全部一致，6页无漏重，旧单搜索通过。正式首屏20单含全局统计约17,851字节、5–11ms，PHP峰值2MiB；是后端读取样本，不是浏览器整页时间。
- 正式UI未完成：独立验收标签已建立，浏览器扩展入口超时，转原生标签输入地址时标点遗漏，随后尝试粘贴纠正时Mac锁屏，已询问用户解锁。原报价邮件标签的2位联系人及正文未关闭/刷新/提交。不得将合成浏览器通过写成正式实点通过。解锁后需在独立页粘贴正确网址，核对首屏、翻页、旧单搜索、详情及合并候选，不提交真实出货。
- 本轮3个已停机测试库经固定路径、标记、进程/打开文件/挂载核验后永久清理约661MiB，无回收副本，可重建；正式库和回滚备份未动。本收尾记录随后单独提交并同版同步，不追加功能改变。
- 最终候选：83项JS语法/22套前端契约、588项PHP语法/65套后端契约和三屏浏览器复验通过；新隔离数据库含覆盖索引复测首屏6ms，口径和原文一致。正式Chrome原标签页有用户已选联系人及编辑正文，不能刷新/关闭，将新开独立页做只读验收。
- 用户明确要求分页并修复极慢打开。新增服务端20/50条分页、全量搜索/组合筛选/稳定排序，数值投影及只读事务；全局看板、全筛选金额与客户/负责人字典独立于当前页。轻量费用生成列完全沿用旧列表表达式、自动随明细维护并带覆盖索引；发布显式初始化，不在列表请求建表。旧接口兼容提速，详情/图片及合并出货独立候选保留。
- 客户端移除全量列表请求，防抖、重复合并、旧响应隔离、失败重试、异步资源就绪等待、旧单全量跳转；金额放在列表上方，筛选区紧凑，小屏不挤字。未更改历史金额、原图、审核或提交真实出货。
- 初轮真实隔离MySQL5.7：107单逐单与旧版金额/数量/状态一致，分页不漏重、原图及JSON摘要不变、改费项自动维护；首屏7ms、PHP峰值2MiB（仅隔离后台值）。生成列下实际多单混装、部分出、加撤、装箱、版本/幂等/并发锁与回滚通过，峰值46MiB；390/768/1280模拟浏览器与截图检查通过。初次浏览器等待断言缺少加载态空值处理，修正测试后通过。588项PHP语法/65套契约、22套前端契约通过，最后收尾改动待最终复检。
- 文件：分页脚本/样式/服务/显式安装器、报价页面/API、四套新回归、出货隔离夹具、CI清单、说明及本记录。基线5807f5e，正式本范围无现用差异；尚未推送/部署，接下来最终检查→提交推送→同版安装/发布→正式只读验收。临时库已停机，收尾按精确名单清理，不碰正式库。

## 2026-09-15：报价邮件同客户联系人复选

- 发布收尾：功能提交c8b3710已同步既定三个分支并同版部署，正式584项PHP语法/64套契约通过，无关现用文件摘要保持。正式Chrome对用户截图同一报价实点，确认逐行复选、同时选2人、全选13个有效邮箱、清空至0；截图检查无复选/邮箱文字拥挤，只有弹窗主滚动。未点击准备/预览生成，不创建新正式草稿、不发邮件；正式窗口留在清空状态。小屏与真实草稿多人/抄送链路由前述隔离测试覆盖，不冒充正式试发。两个本轮停止的测试库按标记/路径/进程/挂载核验后永久清理约85MiB，无回收副本，可重建。此收尾记录单独同版同步，不追加功能修改。
- 用户确认仅同一客户多个联系人，随后要求修改截图中的单选入口。已改为姓名/邮箱复选行、搜索、全选当前结果、清空和已选人数，逐人可设收件/抄送；只有弹窗主滚动，不嵌套列表滚动。无有效邮箱说明原因，禁联/离职/退订不入候选。保留正文、签名、PDF/Excel、测试邮箱和发送预览。
- 服务端接受收件/抄送数组、去重、限制名单规模并逐个校验本客户范围；测试模式完全丢弃客户选择。多个联系人不虚挂单一contact_id，初始联系人ID作为草稿元数据保留，实际发送To/CC继续由既有发送记录完整记录；不自动发信，不变更客户/报价/审核数据，旧单选请求兼容。
- 检查：80项JS语法/21套契约、584项PHP语法/64套契约通过；390/768/1280合成浏览器搜索保留、全选/清空、抄送切换、多收件提交、测试模式隔离、失败重试、预览及跳转通过，390截图目视检查。修复搜索失焦清空提示导致按钮位置跳动的问题。首轮数据库夹具误读draft_get包装返回，修正测试后独立MySQL实际双收件+抄送草稿、预览、历史、幂等及跨客户/禁联拒绝全部通过，未接SMTP或正式数据库。
- 文件：两份邮件脚本、服务、报价页版本、三套回归、说明及本记录；基线3f26188a，服务器该范围无未同步差异。本记录连同前两次只读扫描记录一并按本地→GitHub→同版正式发布流程同步；尚不代表订单分页或CRM全UI已实施。正式页面只读复核待发布后进行，不创建业务草稿、不发送。

## 2026-09-15：CRM UI统一历史状态核对

- 用户询问是否因忘记确认导致UI未统一。复核9月6日整体扫描、docs/crm-ui-contract.md、合并发布记录及本会话授权：用户曾同意整体治理，随后指定新建推广作为样板并授权修改；不能归因为用户未批准。
- 已有公共表单/焦点/操作区/手机折行及部分菜单、推广流程调整；规范明确“不表示全系统已经迁移”，未有全模块弹窗、菜单、复选框、字号/密度全部迁移验收完成的证据。应区分已做局部治理与尚未完成的全量视觉/交互统一。
- 本轮仅核对历史记录，不是现站全量复扫；未修改功能、推送或部署，仅更新本地上下文。后续如实施，先盘点现用组件差异，制定统一组件和逐模块验收清单，避免覆盖式改色冒充完成。

## 2026-09-14：订单中心打开慢与分页只读扫描

- 用户本轮只要求扫描。正式与本地功能提交均为3f26188a，订单页/API无服务器差异。未修改业务代码、数据库或部署；此条仅本地扫描记录，等待实施授权后随下一次正常发布同步，不因扫描自动推送或发布。
- 正式只读事务、单语句8秒限制下：107订单、326明细、78收款；现用列表SQL在8.349秒因诊断限时中断；前20张纯摘要0.005秒/5188字节；全部明细仅数值汇总0.001秒。该样本不是HTTP/浏览器整页耗时。诊断未执行API初始化、建表或状态更新。
- 代码证据：quote_order_api.php qo_list_orders固定LIMIT 2000且两项全表分组；qo_virtual_item_sql_expr重复LOWER/CONCAT/LIKE扫描含大图片的item_json和specification，列表两次CASE复用表达式。浏览器renderOrders全量筛选/排序/绘制；详情已经light按需读取。EXPLAIN显示列表排序及全明细聚合。
- 建议真正服务端分页默认20、可选50，服务端全量搜索/筛选/排序，当前页轻摘要及单单详情/懒加载图；费用项明确轻量标记，旧数据分批核验补齐，保持原数量/费用口径，不删图、不盲目改金额。总计/欠款/客户筛选字典独立于当前页；合并出货候选已有独立分页，需保持跨页多单选择、部分出货和剩余数量。首页/刷新共用DB.orders亦需拆解，避免分页后总计少算和直达旧单失败。
- 验收建议：首页/翻页/搜索/详情分别计时，弱网/小屏、跨页搜索、总计与原账一致、合并出货跨页不漏单。未进行本轮正式页面计时或性能修复；下一步需用户授权实施完整分页及轻量统计。

## 2026-09-14：报价邮件发送预览

- 收尾修正：签名只读回归、390/768/1280整套浏览器测试、80项JS语法/21套契约及本地报价页PHP语法再次通过；报价页共享预览缓存版本升至20260914-2。本记录与只读修正一并提交发布，发布后按相同流程复检与核对三方提交；不增加发送或历史数据修改。
- 发布与现场核对：bd724f6 已同步既定三个 GitHub 分支及广州正式目录，部署后584项PHP语法/64套契约通过，其他现用改动摘要不变。只读查看到用户正在操作的正式报价预览，实际收件人、正文、签名、审核版本和PDF附件链接均已出现；未替用户发送、切换页面或再建草稿。现场签名保留了编辑器属性，追加移除contenteditable/autofocus并补浏览器回归，避免预览可编辑。CRM独立入口及Excel附件已通过合成/隔离回归，当前不打断用户操作做实点，实际投递未验收。本轮两个停止的隔离数据库按精确名单检查后永久清理约85MiB，无回收副本，可由测试重建。
- 用户要求增加发送预览。报价邮件窗口新增预览按钮：生成草稿和真实附件后集中显示用途、发件/收件/抄送/密送、主题、正文/签名及审核版本，支持PDF预览与Excel下载。继续仅转到CRM，不发送；同内容重复预览复用草稿，改动后新草稿保留旧版本，界面明确提示。
- CRM报价邮件附件栏同步增加发送预览，使用当前未保存编辑内容，服务端按实际账号替换变量并核验版本及附件；内容读取期间改变时拒绝旧预览。预览不保存、不入队、不产生发送记录；待上传附件明确仅列名称。正文用无脚本/无同源权限沙箱，外链图片默认禁止，按用户显式点击加载。
- 文件范围：共享预览脚本、两份邮件入口脚本、报价/CRM加载、预览API、两套现有回归和说明/本记录，共10文件。起点e987354；按本地检查→推送→同版发布→复检执行，不改历史单据。
- 检查：80项JS语法/21套契约、584项PHP语法/64套契约通过；390/768/1280合成浏览器预览/附件/返回修改/幂等/脚本隔离通过，移动端预览截图已检查；随后补CRM当前内容及竞态回归。独立无网络MySQL运行实际预览分支，验证未保存内容、文件链接、缺件/错账号/CSRF拒绝、草稿不变与零发送任务。没有实际发信或创建正式新草稿；桌面此前锁屏，正式页面实点仍待补验。
- 最后外链图片用例的请求事件先于拦截回调，初次计数断言过早；测试改为等待响应完成后检查，完整浏览器回归通过。默认零外链请求、显式加载及事件脚本隔离均验证。最终后端和第二次隔离数据库回归通过，候选尚未部署；电脑已可读取，发布后补已有验收草稿的只读预览。

## 2026-09-14：报价测试邮件、简短正文及完整发送记录

- 用户要求增加指定邮箱测试、正式/测试收件人和发送时间记录，以及小正文框。已补用途选择、单一测试邮箱、3行简短正文（预填说明、自动签名）、CRM测试提示及统一记录列表/刷新/更早记录；两种模式均先到CRM核对后手动发送，不新增自动直发。
- 测试用途由服务端保存，排队和投递前均锁定测试地址、禁止CC/BCC及删除主题测试标识。记录按每次队列受理保存发件账号/名称、To/CC/BCC、主题、时间、报价版本；取消重发保留旧记录，结果不明/失败不冒充成功，旧记录不推断补造。仅新增独立用途和发送尝试表，不修改历史报价/审核/出货或扩大其他用户邮件可见范围。
- 改动范围：两份报价邮件脚本、服务/API、报价脚本版本、三套合成测试、说明和本记录，共10文件。起始本地/仓库/服务器96e0bd3；准备按检查→推送→同版发布→复检流程上线。
- 候选检查：79项JS语法/21套契约及390/768/1280合成浏览器交互通过；3行正文、小屏、邮箱校验、失败幂等重试、模式切换、历史刷新/分页及CRM元数据通过，390截图已目视检查。隔离MySQL实际Excel与草稿、用途/目的邮箱保护、HTML转义/签名、日志初始化、事务回滚、CC/BCC快照、取消重发、用户隔离、失败/未知状态与分页通过。最小PHP环境缺少mbstring的正文检查已改用Unicode正则，并安排最终全量复检。
- 本轮未创建正式草稿、未触发发送；桌面仍锁定，新增正式页面实点及实际收件验收待解锁/明确发送确认，不冒充已实发。此前获批验收草稿保持原样。
- 最终候选584项PHP语法/64套契约及第二次独立MySQL回归均通过；前端无后续改动。以下进入同版本发布，实际正式发送验收仍未执行。
- 首版dde8a77同版上线，正式584项PHP语法/64套契约通过；新增表已建立。正式只读历史查询发现旧邮件队列表与新增表排序规则不一致，补充连接比较显式排序规则，并将隔离夹具改为正式旧表的unicode排序规则，继续复检后发布。无实际邮件或新草稿创建。
- 发布完成：兼容补丁4d830f4已先推送三个既定分支，再同版上线；正式584项PHP语法/64套契约及正式只读历史查询通过，新界面公开脚本与本地摘要一致，既有无关文件保持不变，回滚副本保留。本轮用途与发送尝试表均为0行，无实际试发或正式发送。三个本轮隔离数据库均已停止并经精确名单/标记/进程/挂载核验后永久清理，共约127MiB，无回收副本，可重建。当前仅缺解锁后的页面实点及用户确认后的指定邮箱投递验收；本条为随后同版同步的发布记录，不追加功能变更。

## 2026-09-14：已审核报价一键加入 CRM 邮件草稿

- 正式草稿验收获批后发现生成失败：现用 tools 目录为运维用户私有，网站用户无法读取 CLI 生成脚本；合成目录权限与正式目录不同，此前未覆盖。生成器移到应用可读 includes 目录，保持 CLI-only 门禁并增加读取检查，不放宽 tools 权限。失败发生在写草稿之前，无不完整草稿、无邮件发送、无报价改动；修复后继续同版发布及正式验收。
- 生成权限修复513c1ab发布后，当前获批报价双附件只读生成通过；正式写草稿进一步暴露日志懒初始化DDL隐式提交问题。将日志结构初始化移到事务之前，并在隔离回归加入首次日志建表副作用。此前测试替身未模拟该副作用，不能据此声称正式事务已通过；仅恢复本轮获批验收草稿，不动其他邮件或报价。
- 本次修复5848910已同步三个既定分支并同版部署，正式584项PHP语法/64套契约通过；隔离MySQL首次日志初始化、实际草稿生成、幂等与回滚实测通过。生成器迁移保持运维目录权限不变；发布重命名的旧路径曾被校验误归为无关文件，已精确排除该迁移路径后确认其他文件摘要未变，并修正后续发布脚本。
- 按用户“只生成验收草稿”授权，仅恢复本轮操作留下的单封草稿双附件，标题标为“验收测试”，收件人改为指定测试邮箱；只读核对两个文件存在且摘要匹配、草稿未入队、未发送，原报价未改。此项是精确草稿修复，不冒充修复后的正式界面全链路重建验收。随后电脑锁屏，CRM跳转、实际预览及正常保存的正式实点尚待解锁；没有发送验收邮件。
- 本轮新增的一个隔离验收数据库已停止，经标记、进程、打开文件及挂载检查后永久清理约42MiB，无回收副本，合成测试可重建。当前本地/仓库/服务器功能版本为5848910；本条随记录提交按固定流程同步后再次核对三方版本。
- 用户同意开始实施报价 PDF/Excel 邮件联动。已新增历史/审核通过报价的“发送报价邮件”，格式与联系人选择、服务器同一审核快照文件生成、当前发件账号签名、CRM 草稿跳转及实际附件核对入口；不自动发送。
- 补齐生成幂等、账号/用户权限、反审/版本和附件摘要校验、排队防双发、撤销回草稿及成功发送记录。只增加独立邮件包表，不改历史报价、客户、审核或出货数据。普通邮件与旧导出入口保留。
- 测试：隔离 MySQL 实际草稿和 Excel 生成、失败回滚、重试、错账号/用户、换版、篡改及撤销/成功记录通过，测试进程峰值约2MiB（不包含子进程）；普通网站用户沙箱 Chrome 单页及32行多页 PDF/XLSX生成通过，首次小样约2.3秒，不能作为大报价整页性能承诺。PDF逐页核对后修正表头及汇总跨页；XLSX读取核对金额、图片记录及既有导出公式契约，未冒充 Excel 原生打开测试。
- 390/768/1280合成浏览器选择、失败重试、跳转、预览入口及草稿元数据保留通过。最终候选79项JS语法/21套契约、584项PHP语法/64套契约通过。新增检查加入固定清单；正式Chrome已回到登录页，已请用户重新登录，未把模拟页面视为正式实点。
- 修改范围：独立服务/API/CLI生成器、两个轻量前端脚本、报价和CRM接入、旧导出CLI受限复用、合成测试/说明/本记录。本地起点75e2bf7；尚未发布，下一步先提交推送，再限定同版部署复检和正式只读页面验收。不向真实客户测试发送。
- 首次发布72b5321已同版上线，584项PHP语法/64套契约复检通过，无关文件保持不变；独立表初始化及正式小快照只读核验通过，未创建草稿/邮件。进一步检查发现FPM禁用proc_close而CLI没有，本地补齐受限进程回收兼容，按正式禁用列表重测后再次同版发布；不放宽服务器安全配置。
- 发布收尾：兼容修复71b7aac已先同步三个既定GitHub分支，再同版部署；正式配置下以普通网站用户生成32行带图PDF/XLSX通过，584项PHP语法/64套契约再次通过，公开脚本摘要相同，未登录接口返回登录跳转，回滚备份与无关现用改动保留。正式小快照金额/版本只读核验通过；邮件包仍为0条，没有生成真实草稿或试发。
- 本轮两个隔离数据库均已关闭，固定名单/标记/进程/打开文件/挂载核验后永久清理，约84MiB，无回收副本，合成夹具可重建。当前仅缺登录后的正式页面点击与实际邮箱投递验收；已请用户重新登录，不声明该部分完成。此条只补发布记录，随后按同样流程同步，不追加功能变更。

## 2026-09-14：解锁后补做正式合并出货页面验收

- 用户解锁后，在正式Chrome重新登录报价系统。首页、订单中心、客户订单筛选、原报错订单详情及合并出货新工作台均成功打开，未见原内存报错；候选摘要及逐单产品加载可用。
- 未保存页面实测：第一张订单选3件、第二张选2件，汇总2单5件；同箱放入第一单1件与第二单2件后待装2件；撤下整箱后正确回到1单2件、0箱，核对金额2×7.90=15.80。未保存的PL/CI预览被明确拦截，关闭时提示未保存，确认放弃后返回原订单中心，仍为未出货且无新增出货批次。
- 正式桌面截图排版抽查通过；小屏、三单反复增减、保存/签发/实出及并发等沿用上一轮合成浏览器和隔离MySQL测试，不冒充本轮正式写入或真实多人测试。本次未保存待装计划、签发单证、提交出货或修改历史记录。
- 本轮只修改本记录，功能仍为e5f7c67，起始三方版本83f9df3；按既定流程提交推送本记录并同版部署复检。本次补齐的是正式只读/未保存交互验收，不承诺覆盖全部历史单据与生产写入场景。

## 2026-09-14：合并出货批次完整流程实施

- 用户同意完整方案并要求尽可能一次交付。采用独立计划/占用/版本表，保留旧版历史口径；正式确认才事务内写入原出货账并保留原图。补齐产品选择、保存后追加订单、装箱分配、撤箱/拆箱、签发版本、取消、管理员误确认归档更正，以及权限/CSRF/版本/幂等/新旧入口占用校验。
- 更正扫描描述：最终页面资产中原本已有订单级复选；本次是补齐产品级和批次全流程。说明见docs/shipment-batch-workflow-20260914.md。
- 测试：隔离MySQL在128MiB限制下完成多单增减、逐项装箱、版本、回滚、正式出货与归档更正，峰值约46MiB；大图片逐字一致。1280/768/390合成浏览器交互与布局通过；实测修复保存后关闭按钮未恢复，以及HTTP请求编号兼容问题。全量PHP/前端回归通过，最终候选校验持续进行。
- 未改正式历史单据，未提交真实出货。电脑锁屏，正式已登录页面点击验收待用户解锁。接下来按本地检查→推送→同版部署→复检执行；本条不是上线完成声明。
- 发布结果：功能提交 `e5f7c672b778b4878b47cc3da38ca3cadc042616` 已原子推送 main、现用发布分支与本次修复分支，再限定14文件部署同版；无关现用文件摘要保持一致，部署前回滚副本保留。正式578项PHP语法及63套契约通过；前端75项语法及20套契约通过。新增计划、占用、版本、单证和更正归档表已初始化，没有迁移或改写历史业务记录。
- 上线只读复检：候选5张订单约0.003秒/5.7KB，单张15行订单约0.057秒/13.5KB，PHP峰值4MiB，数据库连通正常。仅为后端读取样本，不代表整页打开耗时。正式登录页面的点击验收仍因电脑锁屏未完成，已请用户解锁；不把合成浏览器验收等同正式实点。
- 本轮新建5个隔离测试库均已停止，并经固定名单、标记、进程/打开文件/挂载核验后永久清理，释放约1.94GiB，无回收副本；未动正式库及发布回滚备份。本条仅补发布与验收记录，随后同版同步，不追加功能变更。

## 2026-09-14：获批清理临时验收数据库

- 用户明确批准清理56个约11GiB的隔离测试库。固定名单逐项校验目录、隔离标记、无挂载/进程引用/打开文件后已永久删除全部56项；无回收副本，未动正式数据库、日志或发布回滚备份。
- 释放约11.02GiB；根磁盘使用率79%降到67%，可用约31.32GiB；正式mysqld原进程保持运行。私有操作脚本与结果保留于仓库外work/crm_audit。
- 无业务代码修改、数据库写入或部署；HEAD仍405ed2a，本地上下文记录未提交/推送。本次不涉及合并出货实施，方案仍待确认。

## 2026-09-14：合并出货完整流程重扫与方案

- 用户要求重扫并给出多订单分批、反复增减和装不下撤货的完整方案。已只读核对quotation.php、quote_order_api.php、quote_order_doc.php与正式文件摘要一致。
- 确认默认全余量、编辑不能追加新订单、草稿混入实出及佣金节点、生成单证锁修改、拼箱仅总数校验等问题；方案覆盖计划占用/实出分离、可调整批次、逐项装箱、单证修订、客户识别、并发/幂等、历史保守过渡及完整验收。
- 私有报告：仓库外 `work/crm_audit/combined_shipment_plan_20260914.md`。本轮无功能改动，无真实单据写入、提交、推送或部署；HEAD仍405ed2a。记录仅本地更新，待方案确认再实施并按固定流程同步。

## 2026-09-14：服务器空间只读扫描

- 按用户要求扫描数据库目录、临时验收目录、网站日志及备份占用；确认临时隔离验收库残留为优先清理候选，运行数据库及恢复日志不按垃圾处理。详细私有运维记录在仓库外 `work/crm_audit/server_disk_scan_20260914.md`。
- 未删除文件、修改数据库、停止服务或部署；当前仅扫描授权，不推送或同步外部系统。本次只更新本地上下文，代码HEAD仍为 `405ed2a`；此记录尚未提交/推送。
- 下一步：待用户确认清理范围后逐项目标核验再处理；复制与可恢复备份依赖未确认，数据库日志暂不清理。

## 2026-09-14：报价/订单内存与逐单读取修复

- 从现用提交建立完整独立本地副本，保留原工作树及其未提交诊断记录，未覆盖失效对象引用。用户要求对报价系统一并扫描修复；本批针对已发现的重复加载、出货并发与订单切换问题，范围见 `docs/quote-memory-scan-20260914.md`。
- 合并出货先取摘要再逐张读取，准备只读并显示进度；订单概览与图片按需加载，原文导出保留。出货验证、对账、相关佣金不读取无关完整快照；保存时数据库事务内复制原始图片和快照；创建/编辑/删除共享订单锁。PL/CI 保留原图和抬头，不重复嵌入整份图片JSON。缺失历史明细停止并提示核对，不在打开窗口时自动改写。
- 检查：新增逐单请求/失败重试/错对象/旧响应隔离测试通过；既有前端19套、后端62套契约通过。独立无网络数据库在128MiB限制下验证超过128MiB的历史数据准备/保存/编辑、图片快照逐字一致、费用标识、超量拒绝、回滚和跨连接锁；单证数量金额及原图通过。报价保存/审核与大图片转单、完整原文响应、重复/并发及回滚复验通过。一次测试连接中断后核实临时实例已退出，重跑通过；不把中断记为通过。
- 原报错案例使用候选纯函数只读事务核验成功；未创建真实出货、审批或改动历史单据，未提高生产内存或数据库参数。详情图片与完整正式页面仍需发布后抽查，接口读取耗时不等于整页速度。
- 本记录随限定代码、合成测试和通用扫描说明提交；待按“本地检查→仓库推送→同版部署→正式复检”完成发布及三方核对。私有案例与运维脚本留仓库外，不上传业务资料或凭据。
- 发布完成：功能提交 `6a067a5` 已先推送 main、现用发布分支和本次修复分支，再限定14文件同版部署；部署备份及其他现用文件摘要保持校验通过。正式574项PHP语法/62套契约与同版19套前端契约通过；上线纯函数只读复核原案例通过，原图分段读取与数据库原文件摘要相同。未触发真实出货或审核。电脑锁屏导致登录后的页面点击验收尚未完成，需解锁后续验。本条仅补发布结果，随后同版同步，不追加功能修改。

## 2026-09-10：报价审核费用倍率修复

- 用户查因后要求修复。费用行不再自动补倍率，界面显示“不适用倍率”；后端保留费用原有倍率元数据，不把旧页面补值当作真实改价。普通产品保留未设置与合法零倍率，拒绝负数及非有限倍率；真实数量、单价、倍率和MOQ变更仍需原因及确认，版本及金额保护保留。
- 文件范围：报价页面、报价接口、既有前后端合成回归及本记录，共5文件。前端72项语法/18套检查、隔离源码PHP语法及61套契约通过。独立无网络数据库实测实际保存/审核代码，覆盖费用、折扣、缺省/零倍率、真实改价、快照、回滚、并发和128MiB下大图片；实例已关闭。
- 候选纯函数只读复核指定案例：错误倍率差异已消除，总额不变。没有提交真实报价审核、修改历史单据或调整生产数据库配置。本条随限定修复提交推送并同版发布；最终提交、备份及三方核对留私有运维记录，公开摘要不含业务资料。
- 发布完成：用户明确批准三个既有分支及同版部署后，含功能 `acb4c49` 的 `a96fa4a` 已先同步仓库再同版上线。部署前备份、限定文件与无关现用文件保持校验通过；正式572项PHP语法/61套契约、同版72项JS语法/18套契约复检通过。正式纯函数只读复核原案例，错误差异为空，总额不变；没有代替用户提交真实审核。本条仅补发布结果并按同样流程同步，不追加功能修改。

## 2026-09-10：BOM 修复及验收摘要

- 后续只读续验：已读到完整历史快照的物料、规格、数量、价格状态、单价及合计，内容与对应明细一致；重新加载已发布页面后，已审核单的导航搜索可用、编辑字段仍锁定，顶部显示明细已读取。本次仅更新验收记录，没有修改功能或真实单据。桌面再次锁定，另一个快照切换及待审核单搜索的正式实点仍待补验，不能据此声称全部页面验收完成。
- 保存、审核和快照修复 `064fbe3` 已同步发布，历史成本冻结已完成；不重写历史报价。保存冲突、重复请求、审核锁定、事务回滚、快照归属及零值保护已纳入回归。
- 报价与价格策略统一采用发布成本，同型号优先正式审核来源，同级维持较高成本规则；初始化不改变原取价。PLM及旧命名入口不能静默覆盖已有审核内容。
- 既有检查：572项PHP语法和61套后端检查、72项JS语法和18套前端检查通过；隔离数据库覆盖冻结校验回滚、真实缓存同步、零成本、多版本及并发审核，离线实际页面测试通过。
- 本次只读页面抽查：列表图片、型号搜索、日期范围提示、BOM明细、历史冻结标识、待审/已审编辑锁定及快照列表已验证。完整快照内容的最后页面核对因桌面中途不可用而暂停，不记为通过；没有保存、审核或删除真实单据。
- 抽查补齐两项界面细节：审核锁定不再禁用列表搜索；明细加载完成后清除“正在读取”提示。修改仅涉及 `bom.php`、合成浏览器测试和本记录；新增测试确认搜索可用且审核单仍不可编辑，前端72项语法/18套契约及离线页面测试通过。
- 两项界面补丁 `e96704b` 已先同步仓库，再同版发布；发布后572项PHP语法/61套检查通过，无关现用文件保持不变。后续仅需桌面恢复后核对两项补丁和完整快照内容的正式页面；不把离线测试视为正式实点。公开摘要不含服务器、账号或业务资料，详细运维记录单独保留。

## 2026-09-09：报价首页128MB内存修复（已发布，页面验收通过）

- 用户要求修复报价入口。此候选从已发布版本独立建立，不混入尚未验收的BOM工作流改动；不改变报价金额、历史单据、审核状态或服务器内存配置。
- 首屏物料SQL仅取名称/规格/价格等必要字段和图片存在标记；做物料PI时经原报价权限读取单张原图，原图仍随该条报价保存。补载失败不偷偷加入缺图明细；重复点击、切换报价/币种、错对象回执有保护。
- 报价历史逐条读取并只返回预览，完整图片/物料仍通过原单据详情接口按单获取；读取后恢复数据库游标模式。
- 候选检查：完整PHP语法566文件、59项契约，JS语法70文件、17项契约均通过；真实数据只读投影在128MB限制下成功，编码体积约4.44MB、实测峰值约60MiB（这是读取投影检查，不冒充完整登录浏览器实测）。单张原图补载核对原内容一致，报价金额/审核运行测试通过；尚需发布后实际页面核对。
- 文件范围：报价接口/页面、轻量物料读取模块、合成PHP/JS测试、检查清单及本记录，共8文件。仅允许列表源码/合成测试上传隔离目录，不包含配置、凭据、业务资料或内部报告。
- 修复提交 `5349112e9b8e6b6a41c620dd02c6f68ee5d49fd5` 已先同步GitHub main、发布分支及独立修复分支，再同版部署广州正式运行目录；部署前备份并核对无关现用文件未变化。服务器复检566个PHP文件及59项契约通过，正式函数只读128MB投影及原图一致性检查通过。本条为随后同步的记录更新，不改变功能代码。
- 2026-09-09续验：用户解锁后通过正式已登录Chrome打开报价首页，数据看板和历史列表完成加载，未出现原内存错误。只读抽查历史报价，完整抬头/付款条款/产品图片/规格/单价/数量均显示，逐行金额相加与预览总额及历史列表一致；没有保存、审核、转订单或修改真实单据。浏览器扩展导航曾超时，改用原生屏幕操作完成核对，不把工具等待时间当作页面性能数据。
- 功能提交及上一条发布记录 `07ce9023b8e626b2fd3525253f73da018a87b4aa` 的本地/GitHub/服务器一致性已核对；本次仅同步页面验收记录，不改功能代码。原BOM保存/审核/快照工作流修改仍在原工作树另行保留，未随此入口修复上线；本次通过的是报价入口及单据只读抽查，不代表BOM专项或全量历史金额核账完成。

## 2026-09-09：BOM 总览搜索与图片

- 用户批准保留服务器现用审核、快照及轻量加载改动，并与本次修复一并同步。保留版本已单独提交 `7be4c61`，不覆盖其他模块现用改动。
- 已修复：物料搜索独立只读查询，保留名称/客户/型号搜索；输入防抖、旧响应隔离、失败提示与重试、筛选回第一页，并提供明确的跨全部时间查找入口。
- 图片仅查询当前页（最多18项）的短地址，不读取全部BOM明细或整个命名资料库；图片懒加载并复用页内缓存。无图片与读取失败明确区分；物料数量读取轻量摘要，不再把未加载明细误记为0。总览不再额外预读上次编辑的完整BOM。
- 检查：69项JS/CJS语法、16套前端回归通过；实际页面的合成浏览器测试覆盖搜索、翻页、日期、竞态、失败重试及390/768/1600宽度，手机搜索框完整入屏并经截图复核。564项PHP语法、58套后端检查通过，包含BOM搜索权限/预算与原审核快照检查；正式数据库只读验证通过，不修改业务数据。
- 已通过限定10文件发布演练：逐字核对现用BOM保留版本后再合并，其他模块文件摘要保持一致。当前页图片地址的HTTP只读可用性检查通过。
- 发布：用户明确批准本批10文件向公开仓库发布后，含功能提交 `735e448` 的 `dd19a2a` 已同步 main/发布分支并同版本部署正式服务器。部署前备份、现用BOM版本保留及其他文件摘要保持校验通过。
- 上线复检：正式目录564项PHP语法和58套检查通过；同版本69项前端语法和16套回归再次通过，公网BOM脚本与本地摘要相同。正式桌面浏览器已实测初始图片、物料搜索、翻页补图和从第二页搜索型号回第一页；未编辑或保存业务单据。本条仅补发布结果，随后同版本同步记录，不改动功能代码。
- 公开仓库只含源代码、合成测试及通用修复摘要；详细运维、正式数据统计与恢复记录保存在仓库之外。

## 2026-09-08：拜访填写结果时的历史参考

- 已完成：结果窗口显示最近一次结果及更早记录；没有历史结果时展开原拜访备注，明确标记为计划内容；长文字按需展开全文，不使用小块内部滚动条。历史只读，与本次输入分离，保留明确的复制入口。
- 每次进入结果窗口重新读取当前记录；失败、错误对象或过时响应不打开空白/错误表单。没有修改结果保存接口或历史业务数据。
- 文件范围：CRM页面脚本和样式、新增合成回归、前端检查清单及本记录，共5文件。
- 检查通过：67项JS/CJS语法、15套前端回归；离线浏览器390/768/1280三种宽度下，计划备注/历史结果、展开全文、更早记录、显式复制和模拟保存通过。使用合成数据，未提交真实拜访结果。
- 发布状态：用户明确批准本批公开同步后，含功能提交 `8d0ccd7` 的 `1ea29d5` 已同步main/发布分支并同版本部署。限定5文件演练、备份及无关文件保持校验通过；公网JS/CSS与本地摘要一致，部署后同版67项语法/15套前端检查再次通过。本条仅补公开发布摘要，随后同版同步记录。
- 正式实点：原备注参考及全文展开正常；有多条结果的记录能显示最近一次并展开更早记录。验收未填写、保存或覆盖真实业务内容。服务器未配置可直接调用的Node，前端自动检查使用本地运行时验证同版代码，不声称在服务器运行Node测试；正式页面另已实点验证。

## 2026-09-08：七项问题统一修复

- 已完成：拼箱数量与规格显示、客户群显示、合并出货选择、PDF 附件预览、两类邮件正文截断、推广确认超时后的执行结果核实。详细修复及使用注意见 `docs/seven-issues-20260908.md`。
- 修改范围：报价页面、订单接口、PL/Excel 输出、CRM 客户/邮件/推广接口与页面脚本，配套合成测试和说明，共22文件。
- 已通过：561项 PHP 语法检查、56套后端检查；66项 JS/CJS 语法检查、14套前端回归；独立数据库出货/推广回归及离线浏览器布局与交互检查。
- 上线复查：561项 PHP 语法与56套后端检查、66项 JS/CJS 语法与14套前端回归再次通过；同版代码的独立数据库出货/推广测试通过，测试实例已关闭。公开静态文件摘要与本地一致。
- 正式页面：登录页面加载正常；原反馈 PDF 已在桌面浏览器实际显示，客户群名称、负责人和关联联系人已实际列出；原反馈邮件正文完整性只读核对通过。
- 验收边界：没有在真实业务单据上提交出货或触发真实推广确认，没有发送真实客户测试邮件，没有批量重写历史业务数据。写入、回滚、重复确认与模拟发送在隔离环境验证；桌面 PDF 验收不代表所有终端。
- 发布状态：功能提交 `84e4a31af7f85232951280bd8f7a1fa5139784c7` 已同步 main/发布分支，并同版本部署及复查通过。限定范围演练、正式备份和无关文件保持校验通过。本条仅补发布摘要，随后同版本同步记录，不再改动功能代码。
- 隐私处理：依用户确认，本文件只保留公开修复、测试及发布摘要；内部运维和详细案例记录留在仓库之外。本次待发布提交不包含此前未公开的内部记录提交；不改写既有远端历史。
