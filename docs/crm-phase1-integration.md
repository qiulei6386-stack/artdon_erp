# CRM 第一阶段受控集成记录

日期：2026-09-06。业务候选保持 `371637c` 的代码，本轮只补测试、检查工具和记录。只同步独立分支 `codex/crm-phase1-safety-20260906`；不合入 main，不部署。

## 已验证的范围

| 检查 | 结果与证据边界 |
| --- | --- |
| 原方法前端模型 | 29 + 25 + 7 = 61 场景通过；网络和 DOM 为替身 |
| 后端轻量专项 | 动作权限契约、样品防重、邮箱选择、推广执行安全、邮件数值计时 5 套通过 |
| 真实 Chrome 组件 | 17 场景通过，零页面网络请求；快速切客户、迟到响应、保存防重、角色按钮夹具、原生弹窗关闭/ESC 等。不是完整应用或真实角色账号 |
| 样品真实 MySQL | 原函数在独立 InnoDB 表运行；双进程真实 GET_LOCK 等待，同 token 一建一重放，不同 token 允许真实第二单，参数冲突拒绝，失败回滚/重试，提交后通知/跟进防重 |
| 营销真实 MySQL | 实际 UPDATE JOIN 与关联禁联 SQL、14 种策略夹具、5 个双连接真实锁等待竞争；暂停/取消先行与领取先行、重复领取、事务回滚、混合人工汇总、历史目标保护 |

MySQL 使用独立 `--no-defaults --skip-networking` 的 5.7.43 实例，PHP 8.1.2。没有加载应用配置、连接生产数据库、运行正式营销 worker、发送邮件或真实通知。最小表结构与权限替身不等于线上 schema、真实 HTTP 鉴权或全链路验收。

首次营销实跑因测试每 50ms 查询 MySQL 5.7 锁信息缓存而未观测到锁等待，样品套件已通过。测试改为 250ms 采样、显式关闭结果集并记录限定测试连接的诊断后，在全新实例全部通过；真实锁等待断言保留，未改业务代码。缓存依据：[MySQL 5.7 官方说明](https://dev.mysql.com/doc/refman/5.7/en/innodb-information-schema-internal-data.html)。

最终通过的临时实例为 `/tmp/crm-phase1-mysql-20260906-annT7Hn1`；首轮实例为 `/tmp/crm-phase1-mysql-20260906-lpbaFaME`。两个实例均正常关闭，复查无 socket/PID 文件，保留测试合成数据，不删除历史业务记录。

## 复现方式与安全边界

轻量检查：

```sh
NODE_BIN=/path/to/node bash tools/ci_js_checks.sh
PHP_BIN=/path/to/php bash tools/ci_php_checks.sh
```

两套普通 CI 保留原有检查，新增 3 套前端和 5 套后端轻量专项；JavaScript 语法检查包含 `.cjs`。PHP 契约检查收集全部失败，最终仍以非零状态退出，不跳过或掩盖已有失败。

借用服务器解释器做静态检查时，本轮显式使用 `PHP_BIN=/absolute/test-copy/tools/crm_phase1_php_static.sh`。它使用 `php -n`、拒绝带数据库驱动的解释器并禁用常见 socket、邮件与子进程函数；**不是操作系统级沙箱**，只适用于已审查的静态检查清单。不要把任意 PHP 或包含应用启动文件的测试交给它，更不能批量执行 `tests/*`。

真实浏览器组件：

```sh
CRM_PLAYWRIGHT_MODULE=/path/to/playwright \
CRM_CHROME_PATH=/path/to/chrome \
/path/to/node tests/crm_phase1_browser_test.cjs
```

测试启动临时浏览器，不使用个人浏览器资料。Playwright/浏览器必须已安装；不会自动下载。截图保存于输出中明确列出的系统临时目录，仅是测试夹具页面，不作为 CRM 统一 UI 设计稿。

真实数据库（仅在已批准、具备现成 MySQL 5.7/PHP pdo_mysql/pcntl 的隔离测试主机）：

```sh
CRM_PHASE1_SANDBOX_RUN=1 bash tools/crm_phase1_mysql_sandbox.sh
```

该工具要求现有 mysql 系统用户及启动权限，先核对内存/磁盘，然后创建全新 `/tmp/crm-phase1-mysql-*` 数据目录。仅 UNIX socket，内存缓存有界；不读取项目数据库配置，不重启既有 MySQL，不监听 TCP。关闭前再次校验实例身份；不匹配则拒绝关闭，不进行宽泛进程终止。保留临时数据，每次创建新的空测试库，不能对既有库重跑。它不属于自动 CI；缺依赖时拒绝，不自动安装或改服务器配置。

## 全仓检查与旧契约分类

初轮检查 535 个 PHP 文件语法全部通过；43 个静态契约中 33 个通过、10 个失败。CRM 三个失败的原断言在 main `a3e5989`、旧线上基线 `ad5c8ea`、候选 `8919b91` 都已不匹配，由本地源码/提交核对确认；没有宣称在三个历史版本上完成 PHP 动态复跑。

修正三个旧 CRM 契约后，最终 PHP 8.1 检查为 **535 个语法全部通过，43 套契约 36 通过、7 失败**；本次 CI 清单内 CRM 检查均通过，但全仓仍非绿色。Node 检查为 **48 个 JS/CJS 语法全部通过、7 套检查全部通过**。

现有 GitHub 工作流使用 PHP 8.0。另以现有 PHP 8.0.26 进行 535 个文件纯语法检查，全部通过；该解释器内置数据库驱动，安全 wrapper 按设计拒绝运行静态套件，本轮没有绕过保护执行它。因此完整契约实跑版本是 8.1，8.0 动态兼容性仍需在独立 CI 环境确认。

- 营销池旧断言只接受固定 `skip_count = 1`，现有实现已改为默认跳过总数、显式 `exact_count` 时统计；新契约检查分页上限、双路径及两处精确统计消费，并拒绝 4 个强制统计开关的破坏样本。
- 向导旧断言只接受单个 `$groupId` 的写法，现有实现已支持多个组的客户/联系人成员并集及客户去重；新契约检查组存在、单/多组范围、权限条件和跨页收集，并拒绝 3 个移除关键步骤的破坏样本。
- 邮件预览旧断言要求过时发布标签和空附件直调 SMTP。现有代码早已通过带内嵌附件的发送服务同步调用 SMTP；新契约验证真实资源版本、附件清理和非正式营销队列路径，补充实际测试收件人绑定/邮箱格式校验以及 5 个缓存或收件人破坏样本，不回退业务实现。这里只读源码，没有发送测试邮件。

以下 7 项涉及的模块代码与 main 相比未变，仍需后续逐项区分旧断言和真实功能问题；本轮未修改或跳过它们：

| 失败检查 | 当前失败信息 |
| --- | --- |
| `commercial_center_v1/tests/quote_product_channel_contract.php` | Singapore 适配器必须保持未配置/离线 |
| `tests/dispatch_multi_table_alignment_contract.php` | 多派操作区旧样式模板标记缺失 |
| `material_center_v1/tests/adaptation_batch_quick_rules_contract.php` | 缺少“不能用例外审批绕过”标记 |
| `material_center_v1/tests/adaptation_quick_rule_discovery_contract.php` | 缺少“配置组工作区”标记 |
| `material_center_v1/tests/adaptation_reuse_templates_contract.php` | 缺少 `data-reuse-template-open` 标记 |
| `material_center_v1/tests/adaptation_workbench_contract.php` | 缺少“产品列表”标记 |
| `material_center_v1/tests/route_mapping_v3_test.php` | 导航缺少 `product_adaptation.php` |

另记旧风险：营销池排序在状态和更新时间相同的情况下没有唯一 ID 兜底。需使用合成数据补跨页稳定性测试，不能把当前静态分页契约通过当作已证明不存在漏项。

## 尚未通过的发布门槛

- 完整 CRM 应用在独立测试库启动，使用真实受限角色验证页面、API 与权限拒绝；现有角色夹具不能替代后端鉴权。
- 受控接收端验证邮件队列到 SMTP 的状态、失败和重试；不向真实客户测试发送，不把入队算发送成功。
- 线上同构表结构、迁移/结构维护和其他模块联动验证；这次最小表结构没有覆盖这些边界。
- 全仓静态契约剩余失败分类与修复；专项通过不代表全仓 CI 绿色。
- 正式上线单独授权。main push 会自动部署，因此测试通过也不能直接推 main。

UI 全量统一和实测提速仍是后续阶段；本轮没有重排整套界面，也没有证明邮件列表速度已提升。
