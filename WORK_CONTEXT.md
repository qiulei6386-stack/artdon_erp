# Artdon ERP 工作上下文

## 本次：CRM 客户属性按 GlobalCRM 风格重排

- 用户确认开始将 CRM 客户属性/档案页按参考图方向改造，要求保留现有功能，不能把编辑、联系人、客户群、地址、标签、名片等入口改没。
- `assets/crm/crm.js`：重排客户详情“档案 > 客户属性”子页；新增头像档案头、联系人/客户群/报价/跟进 KPI、基本信息、联系人、地址、客户群、标签、资料完整度、负责人、备注、名片图片等卡片区域。
- `assets/crm/crm.js`：保留原有 `data-archive-edit/save/cancel/missing` 事件；联系人/地址/客户群行仍可在档案编辑模式下双击进入原编辑弹窗；新增卡片内“添加联系人 / 添加地址 / 添加标签”按钮，复用现有弹窗；客户群继续复用原新增/编辑/停用/删除事件。
- `assets/crm/crm.css`：新增 `archive-crm-*` 样式，按卡片式档案页展示，并补充窄屏响应式，避免右侧属性页撑宽。
- 新增 `tests/crm_customer_archive_profile_layout_contract.php`，锁定档案页关键布局标记和旧功能事件入口，防止后续 UI 调整误删。
- 检查：本地 `git diff --check` 通过；Codex bundled Node 对 `assets/crm/crm.js` 语法检查通过；本机无 PHP，PHP 契约测试待服务器部署后执行。
- 部署：本条记录随功能提交推送 GitHub `main` 后，同步正式服务器 `/www/wwwroot/Artdon/artdon_erp/` 并复检；最终提交号以本次完成后的 HEAD 为准。

## 本次：CRM 客户中心新增微信群 / WhatsApp群快捷筛选

- 用户要求在 CRM 客户中心快捷筛选条增加两个筛选：`微信群`、`WhatsApp群`。
- `crm.php`：客户中心快捷筛选按钮新增 `has_wechat_group` / `has_whatsapp_group`。
- `assets/crm/crm.js`：筛选描述文案新增 `微信群` / `WhatsApp群`，确保点击后状态栏显示清晰。
- `crm_customer.php`：`crm_customer_list()` 的 `quick_filter` 新增微信群 / WhatsApp群条件；按 `crm_customer_chat_groups` 表筛选未删除、`status='active'` 且对应 `group_platform` 的客户。
- 新增 `tests/crm_customer_chat_group_filter_contract.php`，锁定按钮、前端文案和后端 SQL 条件。
- 检查：本地 `git diff --check` 通过；Codex bundled Node 对 `assets/crm/crm.js` 语法检查通过；本机无系统 PHP，PHP 语法和新增契约测试在服务器部署后执行。
- 部署：本条记录随功能提交推送 GitHub `main` 后，同步正式服务器 `/www/wwwroot/Artdon/artdon_erp/` 并复检；最终提交号以本次完成后的 HEAD 为准。

## 本次：派工待办新增固定待办入口

- 用户要求新增“固定待办”，用于每天、工作日、每周或每月都要检查的固定性工作，例如每天检查工程开发工作。
- `dispatch_next.php`：主操作区新增“固定待办”按钮；打开后显示固定待办标题、负责人、工作清单、固定规则。负责人默认勾选当前登录人，也可勾选多人；规则支持“每天 / 工作日 / 每周几 / 每月几号”，保存后提示“固定待办已保存”。
- `dispatch_next_api.php`：复用现有 `dispatch_next_groups.group_type='recurring'` 规则表，不新增数据库表；新增 `workdays` 工作日频率；规则中记录 `kind=fixed_todo` 与 `due_time`。
- `dispatch_next_api.php`：修复周期/固定规则生成任务时的截止时间逻辑；不再直接复用规则创建当天的完整 `due_at`，改为每次生成时按“生成日期 + 固定截止时刻”计算，避免未来自动生成的固定待办一生成就显示逾期。
- 新增 `tests/dispatch_fixed_todo_contract.php`，锁定固定待办入口、工作日频率、默认勾选自己、后端 `due_time` 与按当天生成截止时间等关键行为。
- 检查：本地 `git diff --check` 通过；Codex bundled Node 抽取 `dispatch_next.php` 内嵌脚本语法检查通过；本机无系统 PHP，PHP 语法和新增契约测试在服务器部署后执行。
- 部署：本条记录随功能提交推送 GitHub `main` 后，同步正式服务器 `/www/wwwroot/Artdon/artdon_erp/` 并复检；最终提交号以本次完成后的 HEAD 为准。

## 本次：派工待办方式列下拉文案去重

- 用户指出派工待办“方式”列行内下拉里出现重复文案：`个人` 两个、`派工` 三个。本次按用户指定改为清晰短标签：`个人 / 私人 / 派工 / 多派 / 计派 / 周派`。
- `dispatch_next.php`：更新 `methodLabel()`、`methodOptions()`、`tableMethodLabel()`、`blankMethodOptions()` 的显示文案；仅改前端展示标签，不改底层值 `personal/private/single/multi/plan/recurring`，不迁移数据。
- 新增 `tests/dispatch_method_label_contract.php`，锁定方式列标签不得再退回重复显示。
- 检查：本地 `git diff --check` 通过；Codex bundled Node 抽取 `dispatch_next.php` 内嵌脚本语法检查通过；本机无系统 PHP，PHP 语法和新增契约测试在服务器部署后执行。
- 部署：本条记录随功能提交推送 GitHub `main` 后，同步正式服务器 `/www/wwwroot/Artdon/artdon_erp/` 并复检；最终提交号以本次完成后的 HEAD 为准。

## 本次：报价系统同客户多订单合并出货

- 追加体验修复：用户看到 EX071 左侧 3 张订单，但合并弹窗容易误判“只拉出两张”。线上数据确认实际已拉出 3 张、8 行产品、1630 PCS；`quotation.php` 的合并出货提示文案改为明确显示“共 N 张订单 / M 行产品 / X PCS”，降低误读。
- 追加修复：用户反馈 EX071 合并出货时报“只能合并同一客户”；线上只读查询确认三张 EX071 页面客户均为 `OZONEPLUS`，但订单 `AT-260724EX071-2` 的 `customer_id=986`，另外两张旧订单 `customer_id` 为空，旧同客户判断优先按 customer_id 导致误判。
- `quote_order_api.php`：同客户判断改为优先使用规范化客户名；只有客户名缺失时才退回 `customer_id`。合并候选查询也改为 `customer_id` 相同或 `customer_name` 相同，兼容旧订单客户 ID 缺失。
- `tests/quote_combined_shipment_contract.php`：增加契约，锁定客户名相同但 customer_id 有空/有值混合时仍允许合并。
- 用户新增需求：同一客户可能一段时间下多张订单，但需要一起出货；这时 CI 应该是一张，而不是每张订单各出一张。
- 方案落地：只允许同一客户、同币种订单合并出货；合并后仍生成一个出货批次、一张 PL、一张 CI，但单证明细行保留 `Order No.`，方便客户按订单对账。
- `quote_order_api.php`：新增 `quote_shipment_orders` 关联表，支持一个出货批次关联多张订单；新增 `prepare_combined_shipment` 接口，自动收集同客户未出货产品；创建/编辑/删除出货批次会按实际订单行写入 `quote_shipment_items.order_id`，并回算所有相关订单的已出/未出状态。
- `quotation.php`：订单详情增加“合并出货”入口；出货弹窗新增“订单”列；保存时提交 `order_ids` 和行级 `order_id`；单证中心列表优先显示/搜索合并订单号。
- `quote_order_doc.php` / `quote_order_excel.php`：PL/CI HTML 与 Excel 均新增 `Order No.` 列；CI 分组继续优先按 `order_item_id`，兜底分组加入订单来源，避免不同订单相同型号被误合并。
- 新增 `tests/quote_combined_shipment_contract.php`，并更新 `tests/quote_ci_group_by_order_item_contract.php`，锁定合并出货关联、同客户限制、前端提交、单证 Order No. 与 CI 分组规则。
- 检查：本地 `git diff --check` 通过；本机无 `php` 命令，PHP 检查在服务器执行。正式服务器 `php -l quote_order_api.php`、`php -l quote_order_doc.php`、`php -l quote_order_excel.php`、`php tests/quote_combined_shipment_contract.php`、`php tests/quote_ci_group_by_order_item_contract.php`、`php tests/quote_packing_tail_cartons_contract.php`、`php tests/quote_shipment_split_pack_rows_contract.php`、`php tests/quote_shipment_partial_carton_contract.php`、`php tests/quote_shipment_edit_delete_contract.php` 均通过。
- 部署：功能提交 `0af70b68` 与测试补丁 `db3c94e2` 已推送 GitHub `main` 并同步正式服务器；本条上下文记录提交后以最终 HEAD 为准。

## 本次：报价系统 CI 按出货批次内订单明细汇总

- 用户确认开始修复出货资料单证逻辑：Packing List 可以按包装/箱规多行展示；Commercial Invoice 不能按包装资料拆行，必须按“当前出货批次内的订单明细行”汇总；不同出货批次不能跨批次合并。
- `quote_order_doc.php`：新增 `qd_build_ci_items()` 与分组键逻辑，CI 优先按 `order_item_id` 汇总；旧数据缺少 `order_item_id` 时按 `item_index + customer_code + product_code` 兜底；只汇总当前 shipment 传入的出货行，不跨 shipment。
- `quote_order_doc.php`：HTML CI 改用 `$ciItems` 渲染与统计；PL 继续使用 `$plItems = shipment items + carton detail rows`，不改变装箱/箱规展开逻辑。
- `quote_order_doc.php`：CI Excel 导出时传入 `$ciItems`，PL Excel 仍传原始 `$items` 并继续在 `quote_order_excel.php` 内展开 carton detail rows。
- 新增 `tests/quote_ci_group_by_order_item_contract.php`，锁定 CI 汇总维度、CI/PL 分流、HTML/Excel 一致性，防止后续把 CI 又接回包装明细。
- 检查：本地 `git diff --check` 通过；本机无 `php` 命令，PHP 检查在服务器执行。正式服务器 `php -l quote_order_doc.php`、`php -l quote_order_excel.php`、`php tests/quote_ci_group_by_order_item_contract.php`、`php tests/quote_packing_tail_cartons_contract.php`、`php tests/quote_shipment_split_pack_rows_contract.php`、`php tests/quote_shipment_partial_carton_contract.php` 均通过。
- 部署：功能提交 `7ed58f93aab5d127e9ce4ceaeaf606bc62c0971d` 已推送 GitHub `main` 并同步正式服务器；本条上下文记录提交后以最终 HEAD 为准。

## 本次：CRM AI获客 Bosnia 任务“搜不到客户”只读诊断

- 用户反馈再次查看 Bosnia 搜索任务，页面似乎搜不到客户，询问是否提示词问题。本次只读查询线上数据库与队列，不修改任务、不启动 worker、不调用搜索 API。
- 最新任务 `id=23`（`复制 - 筛选Bosnia建筑照明客户`）已重新执行成功进入后续流程；查询时从 `fetching_pages` 推进到 `identifying_company`，进度约 67%，`searched_pages=156`，`found_companies=7`，`last_error=NULL`。
- DataForSEO 搜索已成功：成功调用 22 次，返回 156 条网页结果；仍保留历史失败记录 36 次，错误为旧的 `Invalid Field: 'location_name'.`，这是修复前遗留，不是当前运行阻塞。
- 结论：不是“提示词完全搜不到”。关键词已能搜出网页，并出现 AL-LUX、Lumenia、Telemax、Elektro Tim、ELLUX 等相关线索；页面看到 0 时主要是因为 `parse_company` 公司识别队列还没消费完。
- 质量观察：候选中也混入 TRILUX、Anolis、印度/全球制造商、PDF/物流泛页面等不精准结果，说明提示词可用但偏宽。后续优化方向是加强本地经销/工程/lighting showroom/lighting contractor/rasvjeta 等词，并排除 manufacturer、global brand、shipping、PDF、marketplace 等泛结果。

## 本次：CRM AI获客已取消搜索任务允许重新启动

- 用户反馈“取消的任务，不能重新执行？”。检查确认前端已对 `cancelled` 状态显示“启动搜索任务”，但后端 `radar_task_start()` 仍把 `cancelled` 放在禁止启动列表，导致按钮与接口规则冲突。
- `radar.php`：`radar_task_start()` 移除 `cancelled` 禁止启动限制；已取消任务重新启动时会清空 `cancelled_at`，重新进入 `pending` 并生成新队列。
- `radar.php`：重新启动前清理旧的 `pending/failed/cancelled` 队列，避免上一次取消遗留的队列继续污染本轮关键词搜索统计；历史已抓取网页/候选客户不删除。
- 新增 `tests/crm_radar_cancelled_task_restart_contract.php`，锁定已取消任务必须可启动、仍禁止已完成/执行中任务启动、重启时清理旧 cancelled 队列。
- 待检查/部署：本地静态检查、提交推送、服务器 PHP 检查后更新最终 HEAD。

## 本次：CRM AI获客 DataForSEO Bosnia location_name 失败修复

- 用户取消 Bosnia 搜索任务后要求先改代码，改完再由用户重新开始。本次不启动任务、不手动调用 DataForSEO 搜索 API。
- 根因：DataForSEO 的 `location_name` 必须是其支持的完整地区名；旧逻辑把未映射国家文本原样传入，导致 `country=Bosnia` 被发成 `location_name=Bosnia`，API 返回 `Invalid Field: 'location_name'.`。
- `radar.php`：`radar_dataforseo_location_name()` 增加 Bosnia / BiH / BA / 波黑等别名映射到 `Bosnia and Herzegovina`；未知国家不再原样传给 DataForSEO，避免类似错误整批失败。
- `radar.php`：新增 DataForSEO 请求封装和 location 错误识别；如果 API 返回 `location_name` 无效，自动去掉 `location_name` 再重试一次，继续依赖关键词里的国家/城市/site:.ba 限定搜索地域。
- `tests/crm_radar_dataforseo_search_contract.php` 补充契约，锁定 Bosnia 映射、payload 可禁用 location、invalid location 自动无地区重试。
- 待检查/部署：本地语法与契约测试、提交推送、服务器 `git pull --ff-only` 与 PHP 检查后更新最终 HEAD。

## 本次：CRM AI获客 Bosnia 新任务只读复查

- 用户重新新建/复制 Bosnia 任务后要求检查。本次只做线上只读诊断，未修改任务数据、未启动 worker、未改代码、未手动调用搜索 API。
- 最新任务为 `id=23`，任务名 `复制 - 筛选Bosnia建筑照明客户`，`country=Bosnia`，`model_key=project_procurement`，`target_candidate_count=20`，当前 `task_status=searching`，`progress_percent=4`。
- 关键词已正确写入：共 23 条，包括 `Bosnia architectural lighting distributor`、`Bosnia commercial lighting supplier`、`Sarajevo architectural lighting`、`site:.ba architectural lighting projects` 等；这说明用户这次复制/填入关键词已生效，不再是空关键词问题。
- 队列状态：`generate_keywords` 已 done；已生成 23 个 `search_keyword` 队列，但目前均为 pending 且已尝试 1 次，`last_error=Ok.；Invalid Field: 'location_name'.`。
- 搜索调用记录：DataForSEO 已被调用 23 次，全部失败，返回 0 条结果；`crm_radar_raw_results` 尚无该任务网页结果。
- 根因判断：当前 DataForSEO payload 会把任务国家 `Bosnia` 传为 `location_name=Bosnia`；DataForSEO 不接受该 location_name。下一步应修复国家映射，例如将 Bosnia/BiH 映射为官方位置名或在不支持时不传 `location_name`，依赖关键词里的 Bosnia / site:.ba 限定。

## 本次：CRM AI获客允许复制关键词从提示词内容转入搜索关键词

- 用户追问“不能用我复制进来的关键词么？”。确认现状：第一版提示词区只保存/展示提示词思路，实际搜索只使用“已选关键词”框；如果用户把 ChatGPT 输出关键词粘贴在“提示词内容”框，旧逻辑不会执行这些关键词。
- `assets/crm/crm.js`：提示词模板区新增“使用提示词内容作为关键词”按钮，可识别每行一个关键词、带序号或项目符号的 ChatGPT 输出，并转入“已选关键词”框；过滤明显的中文提示说明行，避免把整段 prompt 当关键词。
- `assets/crm/crm.js`：保存并立即执行时，如果“已选关键词”为空但“提示词内容”里能识别出关键词，会自动转入并保存；若仍无关键词，前端拦截并提示“请先填写关键词，或点击使用提示词内容/模板生成关键词”，不再让空任务进入队列。
- `radar.php`：`radar_task_start()` 增加后端兜底校验；若任务自身和通用关键词库都没有可用关键词，直接拒绝启动并提示先填写/生成关键词，避免再次卡在 `generate_keywords` 队列。
- `tests/crm_radar_prompt_template_workflow_contract.php` 补充锁定“使用提示词内容作为关键词”、保存前自动转入、空关键词启动拦截和后端启动兜底。
- 检查：本地 `git diff --check` 通过；Codex bundled Node 对 `assets/crm/crm.js` 语法检查通过；正式服务器 `php -l radar.php`、`php -l radar_api.php`、`php tests/crm_radar_prompt_template_workflow_contract.php`、`php tests/crm_radar_task_editor_country_language_contract.php` 通过。
- 部署：功能提交 `0ed14884dfc0fa4c275ad237a91c99e76fb6b100` 已推送 GitHub `main`，并使用服务器自身 GitHub SSH 在 `/www/wwwroot/Artdon/artdon_erp/` 执行 `git pull --ff-only origin main` 同步到同一提交；最终上下文记录提交后以最终 Git HEAD 为准。

## 本次：CRM AI获客 Bosnia 任务停在生成关键词只读诊断

- 用户要求检查搜索任务 `筛选Bosnia建筑照明客户`，页面显示处于“生成关键词”。本次只做线上只读诊断，未修改任务数据、未启动 worker、未调用 DataForSEO/Brave 搜索 API。
- 线上任务 `id=22`：`country=Bosnia`，`model_key=project_procurement`，`target_candidate_count=20`，`task_status=generating_keywords`，`progress_percent=0`，`searched_pages=0`，`found_companies=0`，`last_error=没有可用关键词`。
- 队列状态：仅有 `generate_keywords` job `id=540`，`job_status=pending`，`attempts=2/3`，`scheduled_at=2026-08-07 15:26:01`，`last_error=没有可用关键词`，payload 为 `{"limit":0}`；尚未生成任何 `search_keyword` 队列，`crm_radar_usage` 无搜索调用记录。
- 根因：任务自身 `keywords_json=[]`；后端 `radar_task_keywords()` 在任务关键词为空时回退到 `crm_radar_search_keywords` 通用关键词库，但当前关键词库只有 `direct_buyer` 和 `design_influencer` 两类，`project_procurement` 通用关键词为空，因此 worker 抛出“没有可用关键词”。
- 关联体验问题：新建任务提示词模板第一版只负责把模板/提示词生成并填入“已选关键词”框；如果保存前未点击“从模板生成并替换关键词/追加模板关键词”，任务仍可保存并立即执行，导致空关键词任务进入队列后失败。后续建议增加保存前校验或在选择模板后自动填入关键词。
- 待用户确认：可选择一是编辑/复制任务，先用模板生成 Bosnia 工程型关键词再保存执行；二是由系统修复为“手动立即执行时关键词为空则禁止启动并提示先生成关键词”；三是增加 `project_procurement` 通用关键词库兜底。

## 本次：CRM AI获客新建任务接入提示词模板工作流

- 用户确认开始实施“新建搜索任务的关键词可由 ChatGPT 提示词辅助生成；既可手输关键词，也可下拉选择预设提示词”的第一版方案。
- 修复范围：CRM AI 获客搜索任务编辑器和搜索模板编辑器；不新增数据库表、不触发搜索任务、不调用 DataForSEO/Brave 搜索 API、不直接接入外部 ChatGPT 付费调用。
- `assets/crm/crm.js`：在新建/编辑搜索任务抽屉新增“提示词模板”区，可下拉读取启用的搜索模板，可手写提示词，可复制提示词给 ChatGPT；选择模板后自动带入国家、城市、客户模型、产品/项目上下文和提示词内容。
- `assets/crm/crm.js`：新增“从模板生成并替换关键词 / 追加模板关键词”按钮，调用现有 `radar_template_preview` 只生成预览关键词并写入“已选关键词”；最终搜索仍只按关键词框执行，便于人工检查和删改。
- `assets/crm/crm.js`：搜索模板编辑器新增“ChatGPT 提示词模板”输入框，支持 `{country}`、`{city}`、`{model}`、`{products}`、`{projects}`、`{client_types}`、`{exclude_keywords}` 占位符，并保存到现有 `config_json.ai_prompt_template`。
- `radar.php`：模板配置白名单加入 `ai_prompt_template`，兼容后续从结构化字段保存提示词模板。
- 新增 `tests/crm_radar_prompt_template_workflow_contract.php`，锁定任务编辑器提示词下拉、提示词复制、模板预览生成关键词、模板编辑器保存提示词模板等关键标记。
- 检查：本地 `git diff --check` 通过；Codex bundled Node 对 `assets/crm/crm.js` 语法检查通过；正式服务器 `php -l radar.php`、`php -l radar_api.php`、`php tests/crm_radar_prompt_template_workflow_contract.php`、`php tests/crm_radar_task_editor_country_language_contract.php`、`php tests/crm_radar_dataforseo_search_contract.php` 通过。
- 部署：功能提交 `2fe25ab651328bf8c1b59c6abaaf3cf692a2dfbe` 已推送 GitHub `main`，并使用服务器自身 GitHub SSH 在 `/www/wwwroot/Artdon/artdon_erp/` 执行 `git pull --ff-only origin main` 同步到同一提交；最终上下文记录提交后以最终 Git HEAD 为准。

## 本次：CRM AI获客任务列表“第 4 次 / 目标数量 50”文案澄清

- 用户指出 AI 获客任务列表里的“第 4 / 共 4 次”与“目标数量 50”放在一起容易误解。本次确认该 “4/4” 来自 `search_total_count/search_current_no`，表示关键词搜索/API 搜索批次数，不是目标客户数量。
- 修复范围：只调整 CRM AI 获客搜索任务列表卡片展示文案，不改任务执行逻辑、不改数据库、不触发搜索任务。
- `assets/crm/crm.js`：任务卡片从“第 X / 共 Y 次”改为“关键词搜索 X / Y 次”；下一行单独显示“目标客户 N 个”，并把“完成/等待/失败”明确为“完成关键词/等待/失败”，把“公司”改为“候选公司”，把最后的失败改为“队列失败”。
- 新增 `tests/crm_radar_task_count_display_contract.php`，锁定任务列表必须显示目标客户数量和清晰的关键词搜索进度，同时禁止恢复旧的“第 X / 共 Y 次”模糊文案。
- 检查：本地 `git diff --check` 通过；Codex bundled Node 对 `assets/crm/crm.js` 语法检查通过；正式服务器 `php -l radar.php`、`php -l radar_api.php`、`php tests/crm_radar_task_count_display_contract.php`、`php tests/crm_radar_task_editor_country_language_contract.php`、`php tests/crm_radar_task_last_error_contract.php` 通过。
- 部署：功能提交 `984dbe4` 与测试修正提交 `d990ede` 已推送 GitHub `main`，并使用服务器自身 GitHub SSH 在 `/www/wwwroot/Artdon/artdon_erp/` 执行 `git pull --ff-only origin main` 同步到 `d990ede584ed148daa48aa4c45fe815352ce5199`；最终上下文记录提交后以最终 Git HEAD 为准。

## 本次：DataForSEO 激活后重试任务并清理旧错误残留

- 用户完成 DataForSEO 账号激活后要求“再试一下”。本次对线上任务 `印度工程型客户-2`（`id=21`）执行重试，触发 DataForSEO 实际搜索请求。
- 重试前状态：任务已在账号未验证时耗尽 3 次重试，`task_status=partial_completed`，4 个 `search_keyword` 均为 failed，错误为 DataForSEO 40104 账号未验证。
- 通过现有 `radar_task_start(21)` 重新入队，并执行多轮 `radar_worker_run()`。重试后 DataForSEO 搜索已恢复：4 个 `search_keyword` 全部 done，任务进入 `waiting_analysis`，`progress_percent=100`，`searched_pages=9`，`found_companies=2`。
- 当前解析结果：抓到 9 条 DataForSEO 网页结果，4 条成功抓取、5 条因目标网站/平台反爬或页面抓取限制失败；生成候选客户 2 个，均为低分 D 级且偏制造工厂，需要人工审核或优化关键词/排除词。
- 发现并修复一个状态残留问题：任务已经搜索/解析成功后，`crm_radar_search_tasks.last_error` 仍残留旧的 DataForSEO 验证错误。`radar.php` 的 `radar_worker_update_task()` 现会读取仍处于 pending/running/failed 队列的有效错误；没有有效队列错误时清空任务级 `last_error`，避免页面误报旧错误。
- 新增 `tests/crm_radar_task_last_error_contract.php`，锁定 worker 更新任务状态时必须同步清理旧 `last_error`。
- 检查：本地 `git diff --check` 通过；候选文件已上传服务器 `/tmp/artdon_radar_last_error_candidate/`，正式服务器 `php -l radar.php`、`php -l radar_api.php`、`php tests/crm_radar_task_last_error_contract.php`、`php tests/crm_radar_task_editor_country_language_contract.php` 通过。
- 部署：功能提交 `1d4ebb6d2e4ddbe7bd9478f7085c18bebbc306a5` 已推送 GitHub `main`，并使用服务器自身 GitHub SSH `git pull --ff-only origin main` 快进同步正式服务器；已对任务 21 执行 `radar_worker_update_task(21)`，当前 `last_error=NULL`。最终上下文记录提交后以最终 Git HEAD 为准。

## 本次：CRM AI获客搜索失败原因只读诊断

- 用户反馈 AI 获客任务好像都搜索失败了。本次只做线上只读诊断，未修改业务代码、未启动/暂停任务、未修改数据库。
- 线上任务 `印度工程型客户-2`（`id=21`）已因上一轮“手动立即执行”修复正常进入队列：`task_status=searching`，`started_at=2026-08-07 14:44:01`，已生成 1 个 `generate_keywords` 完成任务和 4 个 `search_keyword` 任务。
- 当前失败根因来自 DataForSEO 账号状态：4 个 `search_keyword` 队列均为 `pending`，`attempts=2/3`，`last_error` 为 `Please verify your account before using the API. You can complete verification in the user panel: https://app.dataforseo.com/ .`；任务级 `last_error` 同样为该内容。
- 线上搜索服务配置只读核验：`dataforseo` 已启用、已有 API key、`result_limit=10`、优先级 20；`brave` 也启用且有 key，但优先级 100，当前优先走 DataForSEO。因此失败不是没填 API key，而是 DataForSEO 账户未完成验证。
- 旧任务 `印度工程型客户`（`id=20`）状态为 `waiting_analysis`，10 个 `search_keyword` 已 done，但 `searched_pages=0`、`found_companies=0`；该任务属于旧问题遗留，和当前 DataForSEO 验证错误不是同一表现。
- 下一步建议：用户先登录 `https://app.dataforseo.com/` 完成账号验证/激活；验证完成后可重新运行任务 21（或让 pending 队列下一轮重试）。若希望在 DataForSEO 未验证期间避免重复重试，可由用户明确授权后暂停任务 21 或临时降低/停用 DataForSEO 改走 Brave。

## 本次：CRM AI获客“手动立即执行”保存后未启动修复

- 用户反馈搜索任务选择“手动立即执行”并保存后没有执行。线上只读核验任务 `印度工程型客户-2`（`id=21`）：`execute_mode=manual`，但 `task_status=draft` 且 `crm_radar_job_queue` 为空，确认保存后未进入队列。
- 根因：前端 `assets/crm/crm.js` 的搜索任务编辑器提交时只调用 `radar_task_save`，保存成功后关闭抽屉并刷新列表，没有在 `execute_mode=manual` 时继续调用 `radar_task_start`；而后端启动队列的唯一入口是 `radar_task_start()`。
- 修复：`assets/crm/crm.js` 在保存任务成功后读取返回的任务 ID；若执行方式为 `manual/手动立即执行`，立即调用 `radar_task_start`，成功后提示“搜索任务已保存并启动”；定时、每日、每周任务仍保持只保存不立即启动。
- `tests/crm_radar_task_editor_country_language_contract.php` 增加契约标记，锁定手动立即执行必须在保存后调用 `radar_task_start`，防止再次退回只保存。
- 检查：本地 `git diff --check` 通过；Codex bundled Node 对 `assets/crm/crm.js` 语法检查通过；正式服务器 `php -l radar.php`、`php -l radar_api.php`、`php tests/crm_radar_task_editor_country_language_contract.php` 通过。
- 部署：功能提交 `343b0ecb6862fcc6d282f3853c81cefb2c74834b` 已推送 GitHub `main`，并使用服务器自身 GitHub SSH `git pull --ff-only origin main` 快进同步正式服务器 `/www/wwwroot/Artdon/artdon_erp/`；本地、GitHub、服务器三方一致。最终上下文记录提交后以最终 Git HEAD 为准。
- 说明：本次不自动启动既有线上草稿任务，避免未经再次确认触发 DataForSEO 付费搜索；修复上线后，用户重新保存“手动立即执行”的任务会自动入队，或手动点击“启动搜索任务”也可立即启动。

## 本次：CRM AI获客目标数量 10 但显示共 4 次的原因诊断

- 用户反馈 AI 获客搜索任务目标数量明明是 10，但任务列表显示“共 4 次”，要求先查原因；本次只做扫描和诊断，未改业务代码、未启动搜索任务、未修改数据库。
- 线上只读核验任务 `印度工程型客户-2`（`id=21`）：`target_candidate_count=10` 已正确保存；任务状态为 `draft`，国家/城市为 `India / Mumbai`，队列为空，关键词实际为 4 条：`India architectural lighting contractor`、`India commercial lighting project supplier`、`Mumbai hotel lighting supplier`、`Mumbai lighting importer`。
- 根因：列表卡片显示的“第 X / 共 Y 次”来自 `search_total_count`，后端 `radar_tasks_list()` 先统计 `crm_radar_job_queue` 里的 `search_keyword` 子任务数量；草稿任务没有队列时回退为 `count(radar_task_keywords($row))`，也就是关键词条数。因此 4 条关键词会显示“共 4 次”，与目标候选客户数量 10 不是同一个概念。
- 代码定位：`assets/crm/crm.js` 搜索任务卡片使用 `search_total_count` 渲染“共 N 次”；`radar.php` 的 `radar_task_start()` 启动时创建 `generate_keywords` 队列，Worker 再对每个关键词创建一条 `search_keyword` 队列；当前 `target_candidate_count` 仅用于保存/详情展示，没有参与关键词数量、搜索次数或停止条件计算。
- 当前线上 DataForSEO 服务已启用，`result_limit=10`，所以该任务运行时预期是 4 个关键词、每个关键词最多取 10 条 SERP 结果；“共 4 次”表示 API 关键词搜索调用次数，不表示目标客户数量只有 4。
- 建议下一步修复：把列表文案拆成“搜索关键词 4 次 / 目标客户 10 个”，并让后端返回 `target_candidate_count` 与 `estimated_result_count`；如需要更符合业务预期，再增加按目标数量动态扩展关键词或达到目标候选数后停止的逻辑。待用户确认后再实施。

## 本次：服务器 GitHub SSH deploy key 永久读写修复

- 用户已在 GitHub 仓库 `qiulei6386-stack/artdon_erp` 添加服务器新公钥 `artdon_erp_server_deploy_20260807`，并勾选写入权限。
- 服务器 `/root/.ssh/artdon_erp_github_deploy` 为本次在服务器生成的专用 Ed25519 deploy key；未复制本机 GitHub 私钥到服务器。
- 服务器 `/root/.ssh/config` 已配置 `Host github.com` 使用 `/root/.ssh/artdon_erp_github_deploy` 且 `IdentitiesOnly yes`。
- 验证结果：服务器执行 `ssh -o BatchMode=yes -T git@github.com` 返回 `Hi qiulei6386-stack/artdon_erp! You've successfully authenticated`；`git fetch origin main` 成功；`git push --dry-run origin HEAD:main` 返回 `Everything up-to-date`，说明读写权限链路可用且未产生远端写入。
- 闭环验证：本地记录提交并推送 GitHub 后，服务器已使用自身 GitHub SSH 能力执行 `git pull --ff-only origin main`，成功从 `9264624c` 快进到 `a423c6a8`，证明服务器现在可以直接读取 GitHub 并同步部署。
- 三方状态：首次闭环后本地、GitHub `origin/main`、服务器 `/www/wwwroot/Artdon/artdon_erp/` 均为 `a423c6a89fffabc3b7031a6cda5ca577c20a5696`；本条上下文记录再次提交同步后以最终 Git HEAD 为准。服务器仍保留历史未跟踪备份目录 `material_center_v1/adaptation_backup_*`、`quotation_color_blank_backup_20260731_192138/`，不纳入 Git，属于既有残留。

## 本次：恢复 artdon-erp SSH 并补齐 CRM AI获客修复上线

- 用户要求解决 SSH 问题。根因定位：本机 `~/.ssh/config` 中 `Host artdon-erp` 配置为 `User ubuntu` 且未指定 `IdentityFile`，而服务器 `119.91.27.19` 实际接受的是 `root + ~/.ssh/artdon_hongkong`。因此此前 `ssh artdon-erp` 一直返回 `Permission denied (publickey)`。
- 已在本机备份 SSH 配置到 `/Users/qiulei/.ssh/config.bak_artdon_erp_20260807_ssh_fix`，并将 `artdon-erp` 修正为 `User root`、`IdentityFile ~/.ssh/artdon_hongkong`、`IdentitiesOnly yes`。验证：`ssh artdon-erp "whoami"` 返回 `root`，可进入 `/www/wwwroot/Artdon/artdon_erp`。
- 服务器到 GitHub 的 SSH 仍未配置私钥：服务器 `/root/.ssh` 只有 `authorized_keys/known_hosts`，`git fetch origin main` 返回 `git@github.com: Permission denied (publickey)`。为避免把 GitHub 私钥传到服务器，本次采用已验证 GitHub HEAD 的本地 Git bundle 快进服务器，不在服务器保存 GitHub 私钥。
- 已确认本地 HEAD 与 GitHub `origin/main` 均为 `f6e4ed09dee26896ba035ba756eee41031ded3a9`，并通过 `/tmp/artdon_erp_main_f6e4ed0.bundle` 快进同步正式服务器 `/www/wwwroot/Artdon/artdon_erp/` 到同一提交。
- 服务器复检通过：`php -l radar.php`、`php -l radar_api.php`、`php tests/crm_radar_dataforseo_search_contract.php`、`php tests/crm_radar_task_editor_country_language_contract.php`。
- 三方核对：本地 HEAD、GitHub `main`、服务器 HEAD 均为 `f6e4ed09dee26896ba035ba756eee41031ded3a9`；服务器仍有历史未跟踪备份目录 `material_center_v1/adaptation_backup_*`、`quotation_color_blank_backup_20260731_192138/`，未纳入本次提交，属于既有残留。
- 待确认：如果后续希望服务器自己 `git pull` GitHub，需要在服务器安装一个 GitHub deploy key/专用 SSH key；这涉及把私钥放到服务器或在服务器生成新 key 后到 GitHub 添加公钥，需用户明确确认。

## 本次：CRM AI获客搜索任务草稿编辑入口修复（服务器同步待恢复 SSH）

- 用户反馈新建“印度工程型客户-2”后不能编辑。浏览器只读核验：任务 `id=21`，任务名 `印度工程型客户-2`，状态 `draft/草稿`，国家 `India`，城市 `Mumbai`，进度 `0%`，尚未启动；旧任务 `id=20` 仍被页面选中，状态 `waiting_analysis`。
- 根因定位：搜索任务操作面板对单选任务只提供“查看搜索任务 / 启动 / 暂停 / 复制 / 删除”等动作，没有“编辑搜索任务”入口；后端 `radar_task_save()` 实际允许 `draft/paused/failed/cancelled` 状态编辑，因此这是前端操作入口缺失。
- 修复范围：只改 CRM 客户雷达搜索任务操作面板，不改任务数据、不启动搜索、不改数据库结构。
- `assets/crm/crm.js`：单选任务状态为 `draft/paused/failed/cancelled` 时，在操作面板加入“编辑搜索任务”；点击后通过 `radar_task_get` 读取完整任务详情，再调用现有 `openTaskEditor(task)` 打开编辑抽屉；若状态不允许编辑，提示“请先复制任务再修改”。
- `tests/crm_radar_task_editor_country_language_contract.php`：补充锁定编辑入口、`editSearchTask()` 读取详情后打开编辑器、仅可编辑状态显示编辑动作。
- 检查：本地 `git diff --check` 通过；Codex bundled Node 对 `assets/crm/crm.js` 语法检查通过；bundled Python 静态标记检查通过；本机无系统 PHP，正式 PHP 合约测试需待服务器 SSH 恢复后执行。
- Git / 部署状态：功能提交 `98cc25d28b1a4759c73827374247959a431a5eca` 已推送 GitHub `main`；正式服务器 SSH 当前仍因 `artdon-erp` publickey 拒绝无法同步 `/www/wwwroot/Artdon/artdon_erp/`，因此线上尚未应用本修复。SSH 恢复后需按固定流程快进服务器、运行 PHP 语法/合约测试并核对三方一致。
- 使用说明：修复上线后，先清空旧任务选择，再勾选 `印度工程型客户-2`，右下“操作”中会出现“编辑搜索任务”。

## 本次：CRM AI获客搜索任务国家/语言默认值修复（服务器同步待恢复 SSH）

- 用户反馈 AI 获客里新建“印度工程型客户”搜索任务后，好像无法开始、需要编辑。浏览器只读核验后确认搜索任务列表接口登录态恢复，任务 `id=20` 当前已执行完搜索阶段：状态 `waiting_analysis`，`10/10` 个关键词任务完成，失败 0，但 `searched_pages=0`、`found_companies=0`。
- 根因定位：该任务国家/城市为 `India / Mumbai`，但 `keywords_json` 仍带有 `Vietnam architectural lighting distributor`、`Vietnam commercial lighting supplier`，且 `languages_json=["en","vi"]`；前端 `openTaskEditor()` 新建默认值硬编码为越南国家、胡志明市、Vietnam 关键词和 `en/vi` 语言，保存时也固定写入 `data.languages='en\nvi'`，导致印度任务可被创建/启动，但搜索条件明显不对。
- 同时发现 UI 行为坑：客户模型预设点击时原来是追加关键词/产品/项目，切换模型或国家时容易把旧国家/旧模型关键词叠在一起；任务进入 `waiting_analysis` 后，后端不允许直接编辑，只能复制/取消/重建后再按正确关键词跑。
- 修复范围：只改 CRM 客户雷达搜索任务编辑器默认值与前端保存逻辑，不改已存在任务数据、不直接触发付费搜索、不改数据库结构。
- `assets/crm/crm.js`：新建搜索任务默认国家、城市、关键词、语言改为空，不再默认越南；新增国家到搜索语言的映射，India 保存为 `en/hi`，Vietnam 才保存为 `en/vi`，Indonesia 保存为 `en/id`，阿联酋/沙特/Qatar 保存为 `en/ar`，未知国家为 `en/local`。
- `assets/crm/crm.js`：模型预设从“追加关键词/产品/项目”改为“替换当前预设内容”，并将 `{country}` / `{city}` 按当前输入落地，避免 India 任务继续夹带 Vietnam 关键词。
- 新增 `tests/crm_radar_task_editor_country_language_contract.php`，锁定不得恢复越南硬编码默认值、不得固定提交 `en/vi`、国家语言映射和模型预设替换行为。
- 检查：本地 `git diff --check` 通过；Codex bundled Node 对 `assets/crm/crm.js` 语法检查通过；本机无系统 PHP，使用 bundled Python 按新增 PHP 合约相同标记完成静态检查通过；正式 PHP 合约测试需待服务器 SSH 恢复后执行。
- Git / 部署状态：功能提交 `fbfbb977887f0a9adcaa5133bebae2820e2cee77` 已推送 GitHub `main`；正式服务器 SSH 当前仍因 `artdon-erp` publickey 拒绝无法同步 `/www/wwwroot/Artdon/artdon_erp/`，因此线上尚未应用本修复。SSH 恢复后需按固定流程快进服务器、运行 PHP 语法/合约测试并核对三方一致。
- 下一步：线上任务 `id=20` 已是 `waiting_analysis`，不能直接编辑；修复上线后建议复制或新建一条 India / Mumbai 工程型任务，确认关键词只包含 India/Mumbai 工程照明词后再启动。

## 本次：CRM AI获客接入 DataForSEO 搜索服务（服务器同步待恢复 SSH）

- 用户确认 Google 搜索接口放弃注册，改用 DataForSEO 作为 CRM / AI获客客户雷达的搜索 API。
- 修复范围：只改 CRM 客户雷达搜索服务适配，不改客户数据、不改任务数据、不改数据库结构。
- `radar.php` 在默认搜索服务配置中新增禁用状态的 `dataforseo` 服务位：默认 API 地址 `https://api.dataforseo.com/v3/serp/google/organic/live/advanced`，默认每次 10 条、单次费用参考 `0.002`，未启用前不会调用外网。
- `radar.php` 增加 DataForSEO 专用适配：识别 `service_key=dataforseo` 或 `api.dataforseo.com`；搜索时改用 `POST` JSON；凭证用 DataForSEO 官方 `API login:API password` 或 Base64 后的 `login:password` 转为 `Authorization: Basic ...`；按任务国家/语言生成 `location_name`、`language_code`；解析 `tasks[].result[].items[]` 中的 `organic` / `featured_snippet` 结果并写入现有 raw results 流程。
- `assets/crm/crm.js` 的搜索服务配置说明和占位文字增加 DataForSEO 填写方法，保留 Brave 配置提示。
- 新增 `tests/crm_radar_dataforseo_search_contract.php`，锁定 DataForSEO 默认服务位、POST + Basic Auth、payload 和结果解析，防止以后退回只支持 Brave。
- 检查：本地 `git diff --check` 通过；Codex bundled Node 对 `assets/crm/crm.js` 语法检查通过；本地 DataForSEO 静态合同扫描通过；本地环境无 `php`，正式服务器 SSH 当前因 `publickey` 拒绝，暂未能执行服务器 PHP 语法检查、合同测试和部署。
- DataForSEO 注册入口：`https://app.dataforseo.com/register`；注册后在 Dashboard 的 API Access 查看 API login 和 API password。注意 API password 不是登录密码。
- Git / 部署状态：本地提交 `3f362e0 Add DataForSEO radar search provider` 已推送 GitHub `main`；正式服务器 SSH 使用当前 `artdon-erp` 别名及现有 `artdon_hongkong`、`artdon_order`、`artdon_erp_github_write` 私钥均返回 `Permission denied (publickey)`，因此 `/www/wwwroot/Artdon/artdon_erp/` 尚未同步到该提交。服务器 SSH 恢复后需按固定流程快进同步并复检 PHP 语法和 `tests/crm_radar_dataforseo_search_contract.php`。

## 本次：出货批次支持同一产品多种装箱尺寸（已上线）

- 用户反馈新增出货批次时当前每个产品只有一行，但实际一个批次可能同一产品有两种或三种装箱尺寸，需要在同一出货批次中拆成多行填写。
- 修复范围：订单中心出货批次弹窗与保存校验，不改已有出货数据、不改 PL/CI 模板和数据库结构。
- `quotation.php` 在出货产品行的备注区域自动增加“新增装箱行 / 删除行”按钮；点击“新增装箱行”会复制当前产品行，保留型号与箱规选择能力，清空本次数量、箱数、N.W./G.W./CBM/备注，便于填写第二种或第三种装箱规格。
- `quote_order_api.php` 保存出货批次时允许同一订单产品拆成多条 `quote_shipment_items`，但按订单产品汇总校验总出货数量，防止多行合计超过未出货数量；新增批次和修改批次使用同一校验规则。
- 修改草稿出货批次时，后端会把同一产品已有的多条装箱行按多行回填，避免再次打开时被压回单行。
- 新增 `tests/quote_shipment_split_pack_rows_contract.php`，锁定前端拆分行按钮、后端同产品多行校验与编辑回填行为。
- 检查：本机 `git diff --check` 通过；使用 Codex 自带 Node 抽取 `quotation.php` 的 `<script>` 块解析通过；本机无系统 PHP，已将候选文件上传服务器 `/tmp/artdon_quote_shipment_split_candidate/`，用正式服务器 PHP 检查 `quotation.php`、`quote_order_api.php` 语法通过，新增拆分行合约、非整箱合约、旧尾箱/拼箱合约均通过。
- 部署：本记录随修复提交推送 GitHub `main` 后，以 Git bundle 快进同步正式服务器 `/www/wwwroot/Artdon/artdon_erp/`；同步后需正式目录复检并核对本地/GitHub/服务器 HEAD 一致。
- 状态记录提交以后以最终 Git HEAD 为准。

## 本次：订单出货批次非整箱不再自动进位箱数（已上线）

- 用户反馈订单 `AT-260629EX004` 新增出货批次时，未出货/本次出货只有 `132 PCS`，但系统按 `PCS/CTN 42` 自动带出 `4 CTNS`，并同步带出按 4 个标准箱计算的 N.W./G.W./CBM。
- 根因：`quotation.php` 出货弹窗新增批次和数量重算逻辑使用 `Math.ceil(qty / pcs_per_ctn)`，导致 `132 / 42 = 3.142...` 被静默进位成 4 个标准箱；这会让尾箱/拼箱场景的箱数、重量、CBM 看起来像已确认数据。
- 修复：新增 `shipmentExactCartons()`，只有本次出货数量能被 `PCS/CTN` 整除时才自动带出箱数；非整箱时箱数、按箱重量和 CBM 保持待确认，并在箱规提示/备注中写明“几整箱 + 尾数”，提醒手动确认箱数/重量或录入拼箱明细。
- 新增 `tests/quote_shipment_partial_carton_contract.php`，锁定新增批次/重算逻辑不得再用 `Math.ceil` 自动进位非整箱箱数。
- 检查：本机 `git diff --check` 通过；使用 Codex 自带 Node 抽取 `quotation.php` 的 `<script>` 块解析通过；本地计算验证 `132/42` 不自动出箱数、`126/42` 自动为 3；本机无系统 PHP，已将候选文件上传服务器 `/tmp/artdon_quote_shipment_partial_candidate/`，用正式服务器 PHP 检查 `quotation.php` 语法通过，新增合约测试通过。
- 部署：本记录随修复提交推送 GitHub `main` 后，以 Git bundle 快进同步正式服务器 `/www/wwwroot/Artdon/artdon_erp/`；同步后需正式目录复检并核对本地/GitHub/服务器 HEAD 一致。
- 说明：本次只改新增/重算出货批次的自动带数，不改已有出货批次数据、不改订单明细、不改 PL/CI 模板和数据库结构。
- 状态记录提交以后以最终 Git HEAD 为准。

## 本次：派工待办 @BOM 成本解析恢复（已上线）

- 用户反馈派工待办桌面表格里 `@BOM 52.07540` “解析不出来”，截图显示文本格式正确但没有变成可点联动标签。
- 根因：`dispatch_next.php` 桌面端可编辑的标题/项目单元格在近期响应速度优化中改成 `${esc(plain)}` 纯文本输出，绕过了 `renderLinkedText()`，因此 `@BOM` 不会生成 `data-linked-preview` 标签，也不会触发 `preview_link` 读取 BOM 成本。移动端和详情页原本仍走联动渲染。
- 修复：桌面端 `project` 和 `title` 可编辑单元格恢复使用 `renderLinkedText(plain)` 渲染显示，同时保留 `data-raw-value="${esc(plain)}"`，点击空白处编辑仍回到普通文本，点击联动标签才打开预览。
- 新增 `tests/dispatch_bom_linked_preview_contract.php`，锁定桌面项目/标题单元格必须渲染联动标签，防止再次退回纯文本。
- 检查：本机 `git diff --check` 通过；本机无 `php`，已将候选文件上传服务器 `/tmp/artdon_dispatch_bom_candidate/`，用正式服务器 PHP 检查 `dispatch_next.php` 语法通过，新增合约测试通过。
- 部署：本记录随修复提交推送 GitHub `main` 后，以 Git bundle 快进同步正式服务器 `/www/wwwroot/Artdon/artdon_erp/`；同步后需正式目录复检并核对本地/GitHub/服务器 HEAD 一致。
- 说明：本次只修前端解析触发层，不修改 BOM 数据、权限、成本计算逻辑或历史备份目录。
- 状态记录提交以后以最终 Git HEAD 为准。

## 本次：CRM 联系人电话区号支持模糊查找（已上线）

- 用户反馈 CRM“编辑联系人”弹窗中电话 / WhatsApp 的国家区号下拉已有 200 多个国家，`+86` 这类区号无法快速查找。
- 修复范围：只改 CRM 前端电话组合控件，不改客户、联系人、推广、报价或数据库结构。
- `assets/crm/crm.js` 将客户和联系人共用的电话控件升级为“区号搜索输入 + 区号下拉 + 电话号码”组合；输入 `86`、`+86`、`CN`、`中国` 等关键字会即时过滤国家 / 地区区号选项，Esc 清空恢复全部。
- 保存仍沿用原隐藏字段 / 组合读取逻辑，最终写入格式仍为 `+86 电话号码`，不会把搜索词写入业务字段。
- `assets/crm/crm.css` 增加电话区号搜索布局和移动端适配，避免联系人弹窗内区号和号码输入被挤压。
- 新增 `tests/crm_contact_phone_dial_search_contract.php`，锁定搜索输入、过滤函数、动态联系人编辑器绑定和样式，防止回退成普通下拉。
- 功能提交 `eb0f9d765f8ac6e2cad08c78a40c4bf70569a38d` 已推送 GitHub `main`，并以 Git bundle 快进同步正式服务器 `/www/wwwroot/Artdon/artdon_erp/`。
- 检查：本机 `node --check assets/crm/crm.js` 与 `git diff --check` 通过；正式服务器 `crm.php`、`tests/crm_contact_phone_dial_search_contract.php` PHP 语法通过，新增契约测试通过。
- 服务器仍保留既有物料中心/报价备份目录未跟踪；用户原有商务中心未提交/未跟踪文件不纳入本次提交、不覆盖。
- 状态记录提交以后以最终 Git HEAD 为准。

## 本次：产品参数支持类型模板与自定义字段（已上线）

- 用户反馈产品参数需要覆盖资料表字段，例如 Model、Cut-out Size、Dimensions、Power、Luminous flux、Adjustability、Beam Angle、CCT、CRI、UGR、Dimming method、IP rating、Best for；并且导轨灯、磁吸式、明装式、线性等产品所需参数不同，字段必须可自定义。
- 本次仍不新增数据库表，继续写入 `mc_products.snapshot_json.product_parameters`，不修改旧 BOM、不回写旧产品适配业务。
- `material_center_v1/product_parameters.php` 增加“参数模板 / 产品类型”：嵌入式、导轨灯、磁吸式、明装式、线性、自定义；增加规格表参数字段与自定义参数区。
- 自定义参数支持字段名称、参数值、单位、分组；提供嵌入式、导轨灯、磁吸式、明装式、线性快速字段按钮和空白字段按钮；打开弹窗可回填已保存自定义字段。
- `material_center_v1/api/v1/product-parameters.php` 增加新增字段和 `custom_fields` 数组保存净化，继续使用统一权限与 CSRF。
- 更新 `product_parameters_modal_contract.php` 锁定产品类型、自定义字段、图中规格字段和服务端保存白名单。
- 功能提交 `8019994538014ff2348a20f0a0b7fa7d8595747e` 已推送 GitHub `main` 并快进同步正式服务器。
- 正式服务器检查：`product_parameters.php`、`api/v1/product-parameters.php`、`product_parameters_modal_contract.php` PHP 语法通过；合同测试通过；页面 CLI 渲染生成 202622 字节 HTML，包含“参数模板 / 产品类型”和“自定义参数”。
- 状态记录提交以后以最终 Git HEAD 为准。

## 本次：物料中心产品参数弹窗统一为 V2 宽版样式（已上线）

- 用户要求“产品参数的弹窗，按图1来制作，要统一”。本次只调整 `material_center_v1/product_parameters.php` 的产品参数维护弹窗视觉与布局，不修改旧 BOM、不修改产品适配 V2 业务逻辑、不新增表。
- 弹窗改为 V2 逻辑弹窗同款宽版：顶部渐变标题区、文字“关闭”按钮、绿色虚线提示框、内部滚动正文、固定底部操作区。
- 参数表单从普通 3 列小弹窗改为 4 列宽版表单，分区为“电气参数 / 光学与外观 / 结构尺寸”，并增加共享主数据说明条。
- 保留原字段、原接口和原保存位置：`mc_products.snapshot_json.product_parameters`。
- 新增 `material_center_v1/tests/product_parameters_modal_contract.php`，锁定宽版弹窗、关闭按钮、绿色提示、四列布局、关键分区和字段，防止回退成普通小弹窗。
- 功能提交 `3edc350ce54a09c55bef5ba4359b841e4bbcfdf4` 已推送 GitHub `main` 并快进同步正式服务器。
- 正式服务器检查：`product_parameters.php`、`api/v1/product-parameters.php`、`product_parameters_modal_contract.php` PHP 语法通过；合同测试通过；页面 CLI 渲染生成 174376 字节 HTML 且包含 `mc-param-modal`。
- 状态记录提交以后以最终 Git HEAD 为准。

## 本次：产品适配 V2 设置逻辑弹窗字段分区修复（已上线）

- 用户反馈物料中心产品适配 V2 的“设置物料逻辑”弹窗里，光源、光学、电源看起来仍是同一套弹窗。
- 根因定位：前端 JS 已按 `chip / driver / optical / general` 给不相关字段加 `is-hidden` 并禁用，但 CSS 只隐藏了分区标题 `.pa2-logic-zone.is-hidden`，普通字段 `label[data-logic-zone]` 没被隐藏，导致电源弹窗仍露出芯片品牌/色温等字段。
- 修复：`material_center_v1/adaptation_v2/index.php` 增加通用隐藏规则 `[data-logic-zone].is-hidden{display:none!important}`，让芯片、电源、光学、通用配件字段真正按弹窗类型切换。
- 新增 `material_center_v1/tests/adaptation_v2_logic_dialog_contract.php`，防止以后再出现“字段被禁用但还可见”的回归。
- 更新 `material_center_v1/adaptation_v2/docs/EXECUTION_LOG.md`。
- Git / 部署：功能提交 `2f1b6f08a42e94d72808b78746b6c66d473f08f4` 已推送 GitHub `main`，并以 Git bundle 快进同步正式服务器 `/www/wwwroot/Artdon/artdon_erp/`。
- 检查：本地 `git diff --check` 通过；服务器正式目录 PHP 语法检查通过；新增合同测试 `adaptation_v2_logic_dialog_contract.php` 在正式目录通过。
- 本次不触碰旧 BOM、旧版产品适配或商务中心无关改动。

## 本次：物料中心新增产品参数功能（已上线）

- 用户明确“这里才是物料中心，需在物料中心加入产品参数功能”。本次把产品功率、电流、电压、光学、尺寸、安装、电源方式和调光方式做成物料中心产品主数据能力，而不是继续放在产品适配 V2 工作台的临时逻辑里。
- 新增 `material_center_v1/product_parameters.php`：左侧业务菜单增加“产品参数”，页面可搜索 `mc_products` 产品，显示图片、分类/系列、关键参数摘要、完整度和更新时间，并通过弹窗维护参数。
- 新增 `material_center_v1/api/v1/product-parameters.php`：保存接口经过统一登录、物料中心权限和 CSRF 校验；参数写入 `mc_products.snapshot_json.product_parameters`，不新增数据库表、不修改旧 BOM、不回写旧版产品适配。
- 修改 `material_center_v1/adaptation_v2/lib/foundation.php`：V2 技术范围解析优先读取物料中心产品参数，用于后续芯片、电源、光学适配判断。
- 修改 `material_center_v1/components/sidebar.php`：物料中心业务分组新增“产品参数”入口；产品适配入口仍指向 V2，不改物料中心主页。
- 更新 `material_center_v1/docs/CODEX_EXECUTION_LOG.md` 记录实现边界、存储位置和测试情况。
- 检查：本地 `git diff --check` 通过；本机无 PHP，已将候选文件传到服务器 `/tmp/artdon_product_params_candidate/` 并用服务器 PHP 完成 `product_parameters.php`、`product-parameters.php`、`foundation.php`、`sidebar.php` 语法检查，均通过。
- Git / 部署：功能提交 `d797901a42ba5b961d1e3b45f9f599231a0f5392` 已推送 GitHub `main`，并以 Git bundle 快进同步正式服务器 `/www/wwwroot/Artdon/artdon_erp/`。
- 部署注意：服务器原先有一批 CRM/报价文件显示为脏，但只读比较确认其内容与目标提交完全一致；3 个同内容未跟踪测试文件先移动备份到 `/tmp/artdon_same_target_untracked_backup_20260805_160514`，随后快进部署成功，文件在目标提交中已成为正式跟踪文件。
- 服务器复检：正式目录 `product_parameters.php`、`api/v1/product-parameters.php`、`adaptation_v2/lib/foundation.php`、`components/sidebar.php` PHP 语法通过；`product_parameters.php` CLI 渲染生成 171410 字节 HTML，页面包含“产品参数”7处。
- 记录补丁同步后需以最终 Git HEAD 为准；服务器除历史备份目录外无本次相关未跟踪文件。
- 注意：办公工作区仍有用户原有的商务中心未提交/未跟踪文件，本次不纳入提交、不覆盖。

## 本次：商务中心控制新加坡产品下架/重新上架（已上线）

- 用户要求商务中心支持产品停售下架。实现采用软下架，不删除产品、图片、询价、订单或审计历史。
- 广州端新增 `product_unpublish` 队列：只有 `cc_channel_entity_links.sync_status=published` 的产品可生成下架任务，载荷包含 SKU、来源 ID、下架原因和渠道状态版本；真实发送成功后实体映射更新为 `withdrawn`。重新上架继续使用完整 `product.upsert`，并把上次渠道同步时间纳入载荷，确保下架后重新上架生成新的幂等任务。
- “新加坡发布”页把产品渠道状态合并到已发布产品列表：已上架显示“下架”，点击要求填写原因；已下架显示“重新上架”；未发布显示“生成发布任务”。发送仍通过统一“真实发送”完成，失败可重试。
- 新加坡端接收签名 `product.unpublish`：按 SKU 将产品设为 `inactive`，关闭 `order_enabled`、`sample_enabled`，归档活动配置，并记录独立 `channel.product.unpublish` 审计；默认产品目录只读取 `active`，因此下架后列表、搜索、详情和配置入口均不可销售。再次 upsert 恢复 active 并建立新活动配置版本。
- 广州提交 `4bdc8914489545ab429e9c985d03083cc8177efe` 已推送并通过 Git bundle 快进部署；PHP 语法、JavaScript 语法和 11 项渠道契约通过。新加坡提交 `ba7e830714d8cb0739a3330c5763251cd67b9882` 已推送并快进部署；PHP 语法和 13 项接收契约通过。
- 本次没有擅自下架任何正式产品；需要用户在页面选择明确产品，或明确指定型号后再做真实下架验收。

## 本次：56.03711 产品详情页直接展示 A/B 配置（已完成）

- 用户再次反馈“配置没过来”。浏览器实际验收确认配置数据和配置服务均正常：`/configure/56.03711` 有 A/B 下拉选项，默认 B，服务端验证完成并启用加入项目按钮；问题是普通 `/product/56.03711` 产品详情页没有渲染活动配置，导致用户直观看不到。
- 新加坡修复提交 `4fde5b6702f75af9c48fd18b1d57b05d66cbe98d` 已推送 `artdon_order/main` 并通过 Git bundle 快进部署。产品详情页读取 `configuration_schema.options[configuration].values`，直接展示 Published configurations、Configuration A、Configuration B 和完整芯片/电源组合，并保留进入配置页的选择链接。
- 部署前已备份线上模板到 `/www/backup/artdon_order_20260728_admin/product_before_config_display_20260801.php`，原未提交模板改动保存为服务器 Git stash `before-config-display`；新提交已包含此前由本任务上线的媒体图片和询价价格兼容改动。
- 正式检查：`templates/product.php` PHP 语法通过，渠道契约 10 项通过。浏览器对产品详情页实测：Published configurations 1 处、Configuration A 1 处、Configuration B 1 处；A 显示科锐 CXA1816 + 锐高 PS-BOM-001020，B 显示科锐 CXA1816 + 伊戈尔 PS-BOM-000646。

## 本次：56.03711 新加坡图片与配置同步修复（已完成）

- 用户反馈通过商务中心发布 `56.03711 NOVAL RECESSED DOWNLIGHT` 后，新加坡站点没有同步图片和配置。
- 广州生产任务只读核验：`cc_channel_outbox.id=2` 已 `sent`，载荷完整包含产品图 `https://artdonlighting.com/uploads/website/products/2026/07/20260702_104652_noval-37_0dcda92c.webp`，以及 A/B 两套配置；新加坡返回 `SG-PRODUCT-39`。因此商务中心发送数据无缺失，图片问题位于新加坡接收端未消费 `image_url`。
- 新加坡修复提交 `b87559f` 已推送 `artdon_order/main`：接收端仅允许 HTTPS 和指定 Artdon 域名，限制 10MB，验证 JPEG/PNG/WebP，按 SHA-256 文件名写私有媒体库并登记 `cms_media`；响应增加 `media_synced` 与 `configuration_count`。候选 PHP 语法与 9 项契约通过。
- 广州提交 `612b5ff8dd803c35d25070613070df0821b575a4` 已推送并通过 Git bundle 快进部署：发布协议版本升级为 `2026-08-01.2`，可为同一产品生成新的幂等任务；正式 PHP 语法通过。
- 用户将新加坡 SSH 私钥复制到 `/Users/qiulei-office/Documents/QIUlei0207.pem` 后连接恢复。新加坡提交 `b87559f9dd9f7a3122c1ca3d83bd98ce094431a2` 已通过 Git bundle 快进部署到 `/www/wwwroot/artdon_order`；正式 PHP 语法和 9 项接收契约通过。
- 已从商务中心重新真实发布 `56.03711`：新任务 `cc_channel_outbox.id=3`、状态 `sent`、尝试 1 次、外部编号 `SG-PRODUCT-39`；新加坡响应 `media_synced=true`、`configuration_count=2`。
- 最终验收：新加坡产品 `id=39`、来源版本 `V1`；`image_path=media:channel_d3e60384fa127128582c53ac98428356`，媒体为有效 WebP `1200×1200 / 7340 bytes / active`；活动配置版本为 2，配置代码 A/B 共 2 套；产品页和配置页均 HTTP 200，页面实际引用渠道媒体 URL并显示配置 A、配置 B。

## 本次：商务中心真实发布到新加坡网站（已完成）

- 用户明确要求打通商务中心发布功能，不再通过 SSH 手工写入新加坡数据库，并指定继续用 `57.10511` 做真实链路验收。
- 广州端已实现：新加坡发布页读取物料中心当前已发布产品；生成带幂等键的 `product.upsert` 任务；HMAC-SHA256 签名真实发送；成功/失败响应、外部产品编号和重试状态回写 `cc_channel_outbox`、`cc_channel_entity_links`。
- 新加坡端已新增签名接收接口 `api/channel_product.php`：校验 5 分钟重放窗口、HMAC 和幂等键；按 SKU 新增/更新产品；生成新的活动配置版本并归档旧版本；记录 `audit_logs`。密钥只从 `/www/secure/artdon_singapore_channel.key` 读取，不进入 Git。
- 新加坡接收端最终提交 `4ea6485e1db911034c1d6683b4d088bf029f0bcc` 已推送 GitHub，并通过 Git bundle 快进同步服务器；正式 PHP 语法和 7 项接收契约通过。
- 广州端最终功能提交 `787b44d8814fda8c97d61d97fe06791abb07529d` 已推送 GitHub，并通过 Git bundle 快进同步服务器；JavaScript 语法、PHP 语法和 8 项真实发布契约通过。
- 两端同一 HMAC 密钥已安装到各自受保护的 `storage/channel_sync_secret`，属主为网站进程、权限 `0640`；密钥未提交 Git、未输出到聊天。原 `/www/secure` 方案因 PHP `open_basedir` 不可读而弃用。
- `57.10511` 已从商务中心服务真实发布：`cc_channel_outbox.id=1`、状态 `sent`、外部编号 `SG-PRODUCT-37`，实体映射状态 `published`。新加坡产品 `id=37` 已更新为来源 `artdon_erp_material_center_v2`、来源 ID `269`、版本 `V1`、状态 `active`、价格模式 `review`，活动配置包含 A/B 两套，渠道审计记录已写入。
- 线上验收：产品页与配置页均 HTTP 200，产品显示 `Request quote`，配置页生成 `57.10511-A`，活动配置 JSON 确认 A/B 共 2 套；重复发布通过 SKU upsert 与幂等键保护，不会重复建产品。

## 本次：商务中心 A/B 配置方案选择

- 用户确认物料中心发布配置已联动成功，并要求明确“哪个芯片 + 哪个电源”为配置 A、哪个为配置 B，且可在商务中心选择。
- 当前阶段采用发布快照同序配对：各配置组第 1 个选项组成 A，第 2 个组成 B；某组只有一个选项时作为所有方案共用项。按 `57.10511` 当前发布顺序，A 为 CXA1816 + PS-BOM-000641，B 为 CXA1512 + PS-BOM-000646。
- 商务产品仓库从真实发布快照生成结构化 `schemes`；详情抽屉将原始配置组展示升级为 A/B 方案卡，默认选择 A，点击可切换唯一选中方案。未发布配置不生成方案。
- 本阶段不直接修改生产配置数据，也不触碰正在并行编辑的物料中心 V2 文件；下一阶段再在物料中心增加显式方案命名、排序与发布维护入口，并让“加入报价”保存方案快照。
- 修改文件：`commercial_center_v1/app/Repositories/LegacyCatalogReadRepository.php`、`commercial_center_v1/assets/js/app.js`、`commercial_center_v1/assets/css/app.css`、`commercial_center_v1/tests/published_product_catalog_contract.php`、`WORK_CONTEXT.md`。
- 隔离检查：本机 JavaScript 语法与 `git diff --check` 通过；服务器 PHP 8.0 候选仓库语法和更新契约通过；连接生产库只读实测准确生成 A（CXA1816 + 000641）与 B（CXA1512 + 000646），测试未写入数据库。
- Git / 部署：功能提交 `4fb7e379d8b0a3035b90bacb7ca8af416fbe6df9` 已推送 GitHub并快进同步正式服务器；正式 PHP 语法、发布产品契约及安全扫描通过。功能提交同步时本地、GitHub、服务器一致。

## 本次续作：商务产品详情读取物料中心发布配置

- 用户复核发现 `57.10511` 虽已进入商务中心首屏并显示可报价，但详情抽屉的功率、光束角、光源、电源等仍全部是 `—`，指出这不是真正联动物料中心。
- 根因确认：商务产品卡片只携带命名中心基础字段，`assets/js/app.js` 直接把功率、光束角、输入电压、材质及 5 类配置写死为 `—`；上一轮只接入发布状态与排序，未接入发布快照，属于不完整修复。
- 生产库只读核对：`57.10511` 的已发布 V1 快照包含 2 个芯片/光源（`MC-260728-2D13A0 · 科锐CXA1816`、`MC-260728-801EB9 · 科锐CXA1512`）和 2 个外置电源（`PS-BOM-000641`、`PS-BOM-000646`，均为伊戈尔新款高P无频闪外置驱动）；光学组未选择，功率、光束角、电流、色温、CRI、IP 等技术范围为空。
- 修复：商务中心只读关联当前发布版本的最新 `published` 快照，在仓库层解析技术范围和全部配置组，再随产品卡片传给详情抽屉；抽屉动态展示真实发布版本、已选物料名称/编码和技术参数，源数据为空时才显示 `—`，不猜测、不伪造。
- 修改文件：`commercial_center_v1/app/Repositories/LegacyCatalogReadRepository.php`、`commercial_center_v1/views/product_library_v2.php`、`commercial_center_v1/assets/js/app.js`、`commercial_center_v1/tests/published_product_catalog_contract.php`、`WORK_CONTEXT.md`。
- 隔离检查：本机 `node --check` 与 `git diff --check` 通过；服务器 PHP 8.0 对候选仓库类和页面语法检查通过；更新后的发布产品契约通过。候选代码连接生产库只读实测返回 V1、3 个配置组，以及芯片/光源和外置电源各 2 个真实已选物料；技术范围空值保持为空，测试未修改数据库。
- Git / 部署：功能提交 `326adcd688009335ef1c9f1ac7c49a8da36d0a65` 已推送 GitHub，并以同一提交快进同步正式服务器。正式服务器仓库类与页面 PHP 语法、发布产品契约及商务中心安全扫描通过；正式只读查询返回 V1 和 3 个真实配置组。功能提交同步时本地、GitHub、服务器三方一致；服务器与本地原有并行/未跟踪文件保持原样。

## 本次：物料中心已发布产品进入商务中心

- 用户反馈产品 `57.10511` 在物料中心产品适配 V2 已发布，但商务中心产品库首屏看不到。
- 生产库只读核对：命名产品 `naming_models.id=269` 与物料中心产品 `mc_products.id=266` 均存在；产品配置 `mc_pa2_product_configs.id=1` 和当前版本 `V1` 均为 `published`，发布时间为 `2026-08-01 12:26:07`。
- 根因：商务中心仅按命名中心 `naming_models.updated_at` 排序并沿用旧状态，完全没有读取物料中心当前发布版本；该产品仍按 `2026-07-27` 的命名更新时间排在后续分页，且状态仍显示“已确认”。
- 修复：商务中心继续以命名产品为参数主数据，只读关联物料中心产品及当前发布版本；产品和版本均已发布时映射为“可报价”，并按发布时间倒序优先展示，未发布产品继续沿用原状态与原排序。
- 修改文件：`commercial_center_v1/app/Repositories/LegacyCatalogReadRepository.php`、新增 `commercial_center_v1/tests/published_product_catalog_contract.php`、`WORK_CONTEXT.md`。办公工作区原有物料中心 3 个未提交修改及商务中心命令文件删除、未跟踪资料均原样保留，不纳入本次修改。
- 隔离检查：服务器 PHP 8.0 对候选仓库类和新增契约语法检查通过；新增契约通过；候选代码连接生产库只读实测，产品库前三条的第一条已是 `57.10511`，状态“可报价”，`commercial_published_at=2026-08-01 12:26:07`，精确搜索状态统计为可报价 1。测试没有修改数据库数据。
- Git / 部署：功能提交 `71f8353fb0481b969cdd6e55b14f5329b869617a` 已推送 GitHub，并以同一提交快进同步到服务器唯一运行目录。正式服务器 PHP 语法、新增契约和商务中心安全扫描通过；正式查询确认产品库第一条为 `57.10511`、状态“可报价”。功能提交同步时本地、GitHub、服务器三方一致；服务器原有未跟踪备份目录保持原样。

## 本次：产品适配 V2 配置组定义中心扩展（已发布）

- 用户要求在 V2 配置组定义中心加上：配件、玻璃、蜂窝网、四叶片、光学膜。
- 本次只扩展 V2 配置组定义和默认行为；不修改旧版产品适配业务、不修改旧 BOM、不切换正式菜单。
- 新增迁移 `material_center_v1/adaptation_v2/database/migrations/20260801_010_accessory_group_definitions.php`。
- 新增配置组：`accessory` 配件、`glass` 玻璃、`honeycomb` 蜂窝网、`four_leaf_louver` 四叶片、`optical_film` 光学膜。
- 5 个配置组均为 `material_select`，默认从正式配件物料分类 `accessory` 中选择。
- 默认行为：配件/光学膜可多选；玻璃/蜂窝网/四叶片单选；玻璃、蜂窝网、四叶片、光学膜带候选关键词过滤。
- 新增契约测试 `material_center_v1/tests/adaptation_v2_accessory_groups_contract.php`。
- 本地检查：`git diff --check` 通过；旧版适配目录、旧适配 API、旧适配服务、旧迁移和旧 BOM diff 为 0 行。
- 发布：提交 `86f0004ec44bee0e15f3514de67544cefccec119` 已推送 GitHub `main`，并快进同步到正式服务器 `/www/wwwroot/Artdon/artdon_erp/`。正式服务器已执行 `php material_center_v1/adaptation_v2/tools/migrate.php up`，应用 `20260801_010_accessory_group_definitions`。
- 服务器复检：`adaptation_v2_accessory_groups_contract.php` 通过；`groups` 页面 CLI 渲染无 Fatal；5 个配置组和行为均已写入，`mc_pa2_schema_migrations=10`。

## 上次：产品适配 V2 第 10 阶段最终验收和切换评估（已发布）

- 用户要求继续余下步骤。按阶段纪律继续第 10 阶段。本阶段只做最终验收和切换评估，不切换正式菜单。
- 继续遵守边界：不修改旧版产品适配业务、不修改旧 BOM、不切换正式菜单；V2 仍在 `material_center_v1/adaptation_v2/` 旁路开发；新表继续使用 `mc_pa2_` 前缀。
- 新增 V2 迁移 `material_center_v1/adaptation_v2/database/migrations/20260801_009_phase10_cutover_readiness.php`，新增 `mc_pa2_cutover_audits`、`mc_pa2_cutover_check_items`。
- 第 10 阶段 API 状态更新为 `phase=10`，新增 `cutover_readiness`、`cutover_audit_record`。
- `pa2_cutover_readiness()` 检查旧版边界、正式菜单状态、第 2–9 阶段表、规则循环、配置包发布和真实业务回归要求。
- `index.php?view=cutover` 新增最终验收页，显示决策、阻断项和全量检查项。
- 当前预期结果是 blocked/不得切换正式菜单，因为配置包尚未发布，商务中心/新加坡网站真实接入和业务回归还未完成。
- 新增文档 `adaptation_v2/docs/10_CUTOVER_READINESS.md`，更新 `EXECUTION_LOG.md`。新增契约测试 `material_center_v1/tests/adaptation_v2_phase10_contract.php`。
- 本地检查：`git diff --check` 通过；旧版适配目录、旧适配 API、旧适配服务、旧迁移和旧 BOM diff 为 0 行。办公室电脑无 PHP，已使用服务器 `/tmp/artdon_pa2_phase10_candidate/` 对候选文件做 PHP 语法检查和契约测试，全部通过。
- 发布：第 10 阶段功能提交 `1dad932788bc48fcc3b6089c8a3a21e1f356f504` 已推送 GitHub `main`，并快进同步到正式服务器 `/www/wwwroot/Artdon/artdon_erp/`。正式服务器已执行 `php material_center_v1/adaptation_v2/tools/migrate.php up`，应用 `20260801_009_phase10_cutover_readiness`。
- 数据库：正式服务器当前 `mc_pa2_schema_migrations=9`、`mc_pa2_cutover_audits=0`、`mc_pa2_cutover_check_items=0`。审计表为 0 是因为本轮未绕过权限直接写入，需有权限账号在页面点击“记录本次验收”后写入。
- 服务器复检：`adaptation_v2/index.php`、`api/index.php`、`lib/foundation.php`、第 10 阶段迁移和阶段契约测试 PHP 语法通过；`material_center_v1/tests/adaptation_v2_phase10_contract.php` 全部通过；`cutover` 页面 CLI 渲染无 Fatal；API `status` 返回 `phase=10`。
- 最终评估：`status=blocked`、`ready_to_switch=false`、`decision=不得切换正式菜单`；当前阻断项为 `published_packages_exist` 和 `real_business_regression_required`。
- 旧版边界：未修改旧版 `material_center_v1/adaptation/` 业务、旧 BOM、旧适配 API、旧适配服务、旧迁移，也未切换正式菜单；V2 仍为独立旁路入口。
- 下一步需要人工业务动作：发布至少一个配置包，完成商务中心/新加坡网站真实读取和订单快照回归，然后再由用户明确授权是否切换正式菜单。

## 上次：产品适配 V2 第 9 阶段下游渠道接口（已发布）

- 用户要求继续余下步骤。按阶段纪律继续第 9 阶段，后续阶段可连续推进但必须单独迁移、测试、提交和记录。
- 继续遵守边界：不修改旧版产品适配业务、不修改旧 BOM、不切换正式菜单；V2 仍在 `material_center_v1/adaptation_v2/` 旁路开发；新表继续使用 `mc_pa2_` 前缀。
- 新增 V2 迁移 `material_center_v1/adaptation_v2/database/migrations/20260801_008_phase9_channel_api.php`，新增 `mc_pa2_channel_clients`、`mc_pa2_channel_package_snapshots`、`mc_pa2_channel_cache`、`mc_pa2_channel_access_logs`、`mc_pa2_channel_order_snapshots`。
- 第 9 阶段 API 状态更新为 `phase=9`，新增 `channel_clients`、`channel_packages`、`channel_package_detail`、`channel_order_snapshot`。
- 下游接口使用 HMAC-SHA256 签名，密钥从环境变量读取；只返回 `published` 配置包和 `published` 活动版本，草稿不暴露。
- `index.php?view=publish` 新增渠道发布页，显示客户端、签名说明、接口、缓存/快照/日志和配置包下游可见状态。
- 新增文档 `adaptation_v2/docs/09_CHANNEL_API.md`，更新 `EXECUTION_LOG.md`。新增契约测试 `material_center_v1/tests/adaptation_v2_phase9_contract.php`。
- 本地检查：`git diff --check` 通过；旧版适配目录、旧适配 API、旧适配服务和旧迁移 diff 为 0 行。办公室电脑无 PHP，已使用服务器 `/tmp/artdon_pa2_phase9_candidate/` 对候选文件做 PHP 语法检查和契约测试，全部通过。
- 发布：第 9 阶段功能提交 `caa9d39bbffbea25339f10e5db743dd47b01f9f8` 已推送 GitHub `main`，并快进同步到正式服务器 `/www/wwwroot/Artdon/artdon_erp/`。正式服务器已执行 `php material_center_v1/adaptation_v2/tools/migrate.php up`，应用 `20260801_008_phase9_channel_api`。
- 数据库：正式服务器当前 `mc_pa2_channel_clients=2`、`mc_pa2_channel_cache=2`、`mc_pa2_channel_access_logs=1`、`mc_pa2_schema_migrations=8`。包快照和订单快照尚为 0，需发布配置包并产生下游订单后写入。
- 服务器复检：`adaptation_v2/index.php`、`api/index.php`、`lib/foundation.php`、第 9 阶段迁移和阶段契约测试 PHP 语法通过；`material_center_v1/tests/adaptation_v2_phase9_contract.php` 全部通过；`publish` 页面 CLI 渲染无 Fatal；API `status` 返回 `phase=9`；未签名访问被拒绝并写访问日志。
- 下游只读核验：`commercial_visible=0`、`singapore_visible=0`，原因是第 8 阶段首批配置包仍为草稿；接口按规则不暴露草稿。
- 旧版边界：未修改旧版 `material_center_v1/adaptation/` 业务、旧 BOM、旧适配 API、旧适配服务、旧迁移，也未切换正式菜单；V2 仍为独立旁路入口。
- 待下一阶段：第 10 阶段迁移、全量测试和最终切换评估；未满足条件前不切正式菜单。

## 上次：产品适配 V2 第 8 阶段配置包中心（已发布）

- 用户要求继续余下步骤。按阶段纪律继续第 8 阶段，后续阶段可连续推进但必须单独迁移、测试、提交和记录。
- 继续遵守边界：不修改旧版产品适配业务、不修改旧 BOM、不切换正式菜单；V2 仍在 `material_center_v1/adaptation_v2/` 旁路开发；新表继续使用 `mc_pa2_` 前缀。
- 新增 V2 迁移 `material_center_v1/adaptation_v2/database/migrations/20260801_007_phase8_packages.php`，新增 `mc_pa2_config_packages`、`mc_pa2_config_package_versions`、`mc_pa2_config_package_groups`、`mc_pa2_config_package_options`。
- 首批配置包：Commercial Flexible、Singapore Standard、Singapore DALI、Singapore Ready Stock。
- 配置包组规则支持 `open`、`locked`、`range_limited`、`default_locked`，并记录允许范围、默认项、价格、MOQ、库存、交期。
- API 状态更新为 `phase=8`，新增 `packages`、`package_detail`、`package_save`、`package_version_prepare`、`package_group_save`、`package_option_save`、`package_preview`、`package_publish`。
- `index.php?view=packages` 新增配置包中心页面，显示版本、统计、验收检查、组规则和选项摘要。
- 新增文档 `adaptation_v2/docs/08_CONFIG_PACKAGE_CENTER.md`，更新 `EXECUTION_LOG.md`。新增契约测试 `material_center_v1/tests/adaptation_v2_phase8_contract.php`。
- 本地检查：`git diff --check` 通过；旧版适配目录、旧适配 API、旧适配服务和旧迁移 diff 为 0 行。办公室电脑无 PHP，已使用服务器 `/tmp/artdon_pa2_phase8_candidate/` 对候选文件做 PHP 语法检查和契约测试，全部通过。
- 发布：第 8 阶段功能提交 `4990059429e3132f552e79743927bde01aa54a3d` 已推送 GitHub `main`，并快进同步到正式服务器 `/www/wwwroot/Artdon/artdon_erp/`。正式服务器已执行 `php material_center_v1/adaptation_v2/tools/migrate.php up`，应用 `20260801_007_phase8_packages`。
- 数据库：正式服务器当前 `mc_pa2_config_packages=4`、`mc_pa2_config_package_versions=4`、`mc_pa2_config_package_groups=17`、`mc_pa2_config_package_options=13`、`mc_pa2_schema_migrations=7`。
- 服务器复检：`adaptation_v2/index.php`、`api/index.php`、`lib/foundation.php`、第 8 阶段迁移和阶段契约测试 PHP 语法通过；`material_center_v1/tests/adaptation_v2_phase8_contract.php` 全部通过；`home`、`packages` 页面 CLI 渲染无 Fatal；API `status` 返回 `phase=8`；四个配置包预览检查均通过。
- 旧版边界：未修改旧版 `material_center_v1/adaptation/` 业务、旧 BOM、旧适配 API、旧适配服务、旧迁移，也未切换正式菜单；V2 仍为独立旁路入口。
- 待下一阶段：第 10 阶段迁移、全量测试和最终切换评估；未满足条件前不切正式菜单。

## 上次：产品适配 V2 第 7 阶段产品差异、审批和版本（已发布）

- 用户要求继续，并询问余下步骤能否一起完成。按阶段纪律继续第 7 阶段，后续阶段可连续推进但必须单独迁移、测试、提交和记录。
- 继续遵守边界：不修改旧版产品适配业务、不修改旧 BOM、不切换正式菜单；V2 仍在 `material_center_v1/adaptation_v2/` 旁路开发；新表继续使用 `mc_pa2_` 前缀。
- 新增 V2 迁移 `material_center_v1/adaptation_v2/database/migrations/20260801_006_phase7_versions.php`，新增 `mc_pa2_product_version_events`、`mc_pa2_product_version_snapshots`、`mc_pa2_product_version_diffs`。
- 版本服务：生成版本事件、完整快照、版本差异；支持草稿提交、审批通过、驳回、发布、回滚。
- 发布保护：发布后清空活动草稿；再次编辑时从当前发布版本克隆下一版草稿，旧发布版本和快照保留。
- 锁定保护：已提交、已审批、已发布版本不能继续保存配置项，必须生成新草稿后再改。
- 发布提交 `362a8ed1392ef27a7e50ff4a4c35aa7a4d4b4cd5` 已推送 GitHub `main`，并快进同步到正式服务器 `/www/wwwroot/Artdon/artdon_erp/`。正式服务器已执行 `php material_center_v1/adaptation_v2/tools/migrate.php up`，应用 `20260801_006_phase7_versions`。
- 服务器复检通过：阶段契约测试、页面 CLI 渲染和 API `status phase=7` 均正常。

## 上次：产品适配 V2 第 6 阶段适配计算和冲突引擎（已发布）

- 用户要求继续第 6 步，并在完成后说明现在能验证什么。
- 继续遵守边界：不修改旧版产品适配业务、不修改旧 BOM、不切换正式菜单；V2 仍在 `material_center_v1/adaptation_v2/` 旁路开发；新表继续使用 `mc_pa2_` 前缀。
- 新增 V2 迁移 `material_center_v1/adaptation_v2/database/migrations/20260801_005_phase6_engine.php`，新增 `mc_pa2_adaptation_result_cache`、`mc_pa2_adaptation_conflicts`、`mc_pa2_adaptation_recalc_jobs`。
- 引擎服务：读取现有正式物料表和规格表，保守解析产品技术范围，计算候选物料的适配结论、匹配度、冲突字段、原因和规则轨迹。
- 结论类型：完全适配、条件适配、需要审批、不适配。资料不足时按条件适配，不误判为完全适配。
- 覆盖方向：电源、芯片、光学、接头、配件和通用物料类别；正式状态不足会转入需要审批。
- 缓存和重算：适配结果写入 V2 缓存表，冲突写入 V2 冲突表；保存产品配置后自动尝试重新计算；工作台提供手动“重新计算”。
- `api/index.php` 状态更新为 `phase=6`，新增动作 `workspace_recalculate`、`adaptation_results`，候选接口支持携带产品和配置组上下文返回即时适配结论。
- `index.php` 工作台显示卡片结论、候选弹窗结论、底部统计和重算按钮。
- 新增文档 `adaptation_v2/docs/06_ADAPTATION_ENGINE.md`，更新 `EXECUTION_LOG.md`。新增契约测试 `material_center_v1/tests/adaptation_v2_phase6_contract.php`。
- 本地检查：`git diff --check` 通过；办公室电脑无 PHP，已使用服务器 `/tmp/artdon_pa2_phase6_candidate/` 对候选文件做 PHP 语法检查和契约测试，全部通过。
- 发布：第 6 阶段功能提交 `e9bdf380b283bbf2657018e5eceb0cd02d21d175` 已推送 GitHub `main`，并快进同步到正式服务器 `/www/wwwroot/Artdon/artdon_erp/`。正式服务器已执行 `php material_center_v1/adaptation_v2/tools/migrate.php up`，应用 `20260801_005_phase6_engine`。
- 数据库：正式服务器当前 `mc_pa2_adaptation_result_cache=0`、`mc_pa2_adaptation_conflicts=0`、`mc_pa2_adaptation_recalc_jobs=0`、`mc_pa2_schema_migrations=5`。结果缓存数为 0 是正常初始状态，用户对具体产品生成 V2 草稿并点击“重新计算”后才写入。
- 服务器复检：`adaptation_v2/index.php`、`api/index.php`、`lib/foundation.php`、第 6 阶段迁移和阶段契约测试 PHP 语法通过；`material_center_v1/tests/adaptation_v2_phase6_contract.php` 全部通过；`home`、`workspace`、`products`、`rules`、`logs` 页面 CLI 渲染无 Fatal；API `status` 返回 `phase=6`；样品只读引擎核验：产品 `266` + 电源物料 `120198` 返回 `conditional_match` / `条件适配` / `76` 分。
- 旧版边界：未修改旧版 `material_center_v1/adaptation/` 业务、旧 BOM、旧适配 API、旧适配服务、旧迁移，也未切换正式菜单；V2 仍为独立旁路入口。
- 待下一阶段：第 7 阶段接产品差异、审批和版本；当前第 6 阶段不处理正式发布、审批流和下游配置包。

## 上次：产品适配 V2 第 5 阶段单产品配置工作台（已发布）

- 用户要求先进行第 5 步。按主说明进入第 5 阶段：单产品配置工作台。
- 继续遵守边界：不修改旧版产品适配业务、不修改旧 BOM、不切换正式菜单；V2 仍在 `material_center_v1/adaptation_v2/` 旁路开发；新表继续使用 `mc_pa2_` 前缀。
- 新增 V2 迁移 `material_center_v1/adaptation_v2/database/migrations/20260801_004_phase5_workspace.php`，新增 `mc_pa2_product_configs`、`mc_pa2_product_config_versions`、`mc_pa2_product_group_configs`、`mc_pa2_product_selected_options`。
- 工作台服务：按产品读取 `mc_products` 和 V2 分类映射，自动匹配产品级/系列/分类/系统模板；按模板继承结果生成产品配置草稿；读取工作台详情、配置组、已选项和检查摘要。
- 候选物料服务：只读现有 `mc_materials`、`mc_material_categories`、`mc_material_metadata`，按第 4 阶段配置组行为做轻量过滤；完整适配计算、打分、冲突和替代推荐留第 6 阶段。
- 保存能力：支持物料、属性、数值、文本、布尔配置项保存到 V2 草稿，不写旧 BOM、不写旧适配。
- `api/index.php` 状态更新为 `phase=5`，新增动作 `workspace`、`workspace_prepare`、`product_group_save`、`material_candidates`。
- `index.php` 的 `workspace` 从占位改为工作台：无产品时显示可选产品列表；有产品时显示产品摘要、模板来源、三步快速流程、动态配置卡片、需要补充数量、宽版物料选择弹窗、保存草稿和检查配置。
- 新增文档 `adaptation_v2/docs/05_PRODUCT_WORKSPACE.md`，更新 `EXECUTION_LOG.md`。新增契约测试 `material_center_v1/tests/adaptation_v2_phase5_contract.php`。
- 本地检查：`git diff --check` 通过；旧版适配目录、旧适配 API、旧适配服务和旧迁移 diff 为 0 行。办公室电脑无 PHP，已使用服务器 `/tmp/artdon_pa2_phase5_candidate/` 对候选文件做语法检查：`index.php`、`api/index.php`、`foundation.php`、第 5 阶段迁移和新增契约测试均无语法错误；候选 `adaptation_v2_phase5_contract.php` 全部通过。
- 发布：第 5 阶段功能提交 `075c40790c23f00a395adc28a9fd434b12749e8e` 已推送 GitHub `main`，并快进同步到正式服务器 `/www/wwwroot/Artdon/artdon_erp/`。正式服务器已执行 `php material_center_v1/adaptation_v2/tools/migrate.php up`，应用 `20260801_004_phase5_workspace`。
- 数据库：正式服务器当前 `mc_pa2_product_configs=0`、`mc_pa2_product_config_versions=0`、`mc_pa2_product_group_configs=0`、`mc_pa2_product_selected_options=0`、`mc_pa2_schema_migrations=4`。草稿配置数为 0 是正常初始状态，用户打开产品并点击“生成配置草稿”后才写入。
- 服务器复检：`adaptation_v2/index.php`、`api/index.php`、`lib/foundation.php`、第 5 阶段迁移和阶段契约测试 PHP 语法通过；`material_center_v1/tests/adaptation_v2_phase5_contract.php` 全部通过；`home`、`workspace`、`products`、`rules`、`logs` 页面 CLI 渲染无 Fatal；样品产品 `266` 可读取工作台详情，尚无草稿时模板回退为系统通用模板。
- 旧版边界：未修改旧版 `material_center_v1/adaptation/` 业务、旧 BOM、旧适配 API、旧适配服务、旧迁移，也未切换正式菜单；V2 仍为独立旁路入口。
- 待最终收尾：本条上下文记录提交并同步后，再核对本地/GitHub/服务器同一 HEAD。

## 上次：产品适配 V2 第 4 阶段配置组选项、物料来源和规则编辑器（已发布）

- 用户继续要求推进 V2。按主说明进入第 4 阶段：配置组选项、物料来源和规则编辑器。
- 继续遵守边界：不修改旧版产品适配业务、不修改旧 BOM、不切换正式菜单；V2 仍在 `material_center_v1/adaptation_v2/` 旁路开发；新表继续使用 `mc_pa2_` 前缀。
- 新增 V2 迁移 `material_center_v1/adaptation_v2/database/migrations/20260801_003_phase4_group_rules.php`，新增 `mc_pa2_group_behavior_settings` 和 `mc_pa2_rule_definitions`。
- `mc_pa2_group_behavior_settings` 保存配置组行为：物料来源、物料过滤器、属性来源、默认项规则、显示条件、必选/可选、单选/多选、选择数量限制和校验。
- `mc_pa2_rule_definitions` 保存配置组规则：触发配置组、判断方式、触发值、目标配置组、显示/隐藏/必选/可选/物料过滤/默认项/限制选项等动作。
- 新增 `track_system` 配置组和 `standard_track / intrack` 属性选项，并把 `track_system` 加入 `track_light_base` 模板。
- 写入验收种子规则：导轨灯选择 INTRACK 后显示 INTRACK 接头和 INTRACK 电源，隐藏普通接头和普通内置电源；普通导轨反向显示/隐藏；磁吸灯短款对磁吸头执行短款物料过滤。
- `foundation.php` 新增第 4 阶段服务函数：配置组行为保存、规则读取、规则保存、规则循环检测。保存规则时若形成循环依赖，会回滚并拒绝保存。
- `api/index.php` 状态更新为 `phase=4`，新增动作 `group_behavior_save`、`rules`、`rule_save`、`rule_cycle_check`。
- `index.php` 新增 `rules` 规则编辑器页面；配置组定义中心增加行为/来源设置区；首页显示行为设置数、规则数和循环数。
- 新增文档 `adaptation_v2/docs/04_GROUP_RULE_EDITOR.md`，更新 `EXECUTION_LOG.md`。新增契约测试 `material_center_v1/tests/adaptation_v2_phase4_contract.php`。
- 本地检查：`git diff --check` 通过；旧版适配目录、旧适配 API、旧适配服务和旧迁移 diff 为 0 行。办公室电脑无 PHP，已使用服务器 `/tmp/artdon_pa2_phase4_candidate/` 对候选文件做语法检查：`index.php`、`api/index.php`、`foundation.php`、第 4 阶段迁移和新增契约测试均无语法错误；候选 `adaptation_v2_phase4_contract.php` 全部通过。
- 发布：第 4 阶段功能提交 `b4f19ae916a543f78c82cc61a9720fd96b4a79dc` 已推送 GitHub `main`，并快进同步到正式服务器 `/www/wwwroot/Artdon/artdon_erp/`。正式服务器已执行 `php material_center_v1/adaptation_v2/tools/migrate.php up`，应用 `20260801_003_phase4_group_rules`。
- 数据库：正式服务器当前 `mc_pa2_group_behavior_settings=16`、`mc_pa2_rule_definitions=9`、`mc_pa2_group_option_definitions=18`、`mc_pa2_template_groups=17`、`mc_pa2_schema_migrations=3`；规则循环检测 `cycle_count=0`。
- 服务器复检：`adaptation_v2/index.php`、`api/index.php`、`lib/foundation.php`、第 4 阶段迁移和阶段契约测试 PHP 语法通过；`material_center_v1/tests/adaptation_v2_phase4_contract.php` 全部通过；`home`、`groups`、`rules`、`templates`、`logs` 页面 CLI 渲染无 Fatal。
- 旧版边界：未修改旧版 `material_center_v1/adaptation/` 业务、旧 BOM、旧适配 API、旧适配服务、旧迁移，也未切换正式菜单；V2 仍为独立旁路入口。
- 待最终收尾：本条上下文记录提交并同步后，再核对本地/GitHub/服务器同一 HEAD。

## 上次：产品适配 V2 第 3 阶段模板中心和继承引擎（已发布）

- 用户看过 V2 第 2 阶段后反馈“还比较生硬”，并要求继续。按主说明进入第 3 阶段：模板中心和继承引擎，同时把模板页从硬表格改为更柔和的卡片/工作台布局。
- 继续遵守边界：不修改旧版产品适配业务、不修改旧 BOM、不切换正式菜单；V2 仍在 `material_center_v1/adaptation_v2/` 旁路开发；新表继续使用 `mc_pa2_` 前缀。
- 新增 V2 模板迁移 `material_center_v1/adaptation_v2/database/migrations/20260801_002_phase3_templates.php`，新增 `mc_pa2_templates`、`mc_pa2_template_versions`、`mc_pa2_template_groups`。
- 迁移写入首批模板：`system_common` 系统通用模板、`track_light_base` 导轨灯模板、`recessed_base` 嵌入式模板、`magnetic_base` 磁吸式模板，并写入首批模板配置组。
- `foundation.php` 新增模板服务函数：模板列表、模板详情、直接配置组、继承链、有效配置组合并、模板新增/编辑、模板配置组加入/覆盖/禁用、发布版本、引用检查。继承按 `group_code` 合并，支持 `add / override / disable`。
- `api/index.php` 新增动作：`templates`、`template_detail`、`template_save`、`template_group_save`、`template_preview`、`template_publish`、`template_reference_check`。
- `index.php` 第 3 阶段页面：`templates` 从占位改为模板中心；`template_editor` 改为三栏模板编辑器，含左侧模板导航、中间模板结构编辑、右侧继承预览；页面样式调整为柔和卡片、工作台和继承流。
- 新增文档 `adaptation_v2/docs/03_TEMPLATE_INHERITANCE.md`，更新 `EXECUTION_LOG.md`。新增契约测试 `material_center_v1/tests/adaptation_v2_phase3_contract.php`。
- 本地检查：`git diff --check` 通过；旧版适配目录、旧适配 API、旧适配服务和旧迁移 diff 为 0 行。办公室电脑无 PHP，已使用服务器 PHP 对候选文件做语法检查：`index.php`、`api/index.php`、`foundation.php`、第 3 阶段迁移和新增契约测试均无语法错误；候选 `adaptation_v2_phase3_contract.php` 全部通过。
- 发布：第 3 阶段功能提交 `ae5f626b5ec2ae2d82614b52e389f10f62ef7282` 已推送 GitHub `main`，并快进同步到正式服务器 `/www/wwwroot/Artdon/artdon_erp/`。正式服务器已执行 `php material_center_v1/adaptation_v2/tools/migrate.php up`，应用 `20260801_002_phase3_templates`。
- 数据库：正式服务器当前 `mc_pa2_templates=4`、`mc_pa2_template_groups=16`、`mc_pa2_template_versions=0`、`mc_pa2_schema_migrations=2`。版本表为 0 是正常状态，发布版本功能已实现，但尚未由用户在页面点“发布模板”生成正式版本。
- 服务器复检：`adaptation_v2/index.php`、`api/index.php`、`lib/foundation.php`、第 3 阶段迁移和阶段契约测试 PHP 语法通过；`material_center_v1/tests/adaptation_v2_phase3_contract.php` 全部通过；`home`、`templates`、`template_editor`、`logs` 页面 CLI 渲染无 Fatal；继承预览核验为当前 4 个模板，`track_light_base` 继承链 2 层，继承后 9 个有效配置组。
- 旧版边界：未修改旧版 `material_center_v1/adaptation/` 业务、旧 BOM、旧适配 API、旧适配服务、旧迁移，也未切换正式菜单；V2 仍为独立旁路入口。
- 待最终收尾：本条上下文记录提交并同步后，再核对本地/GitHub/服务器同一 HEAD。

## 本次：产品适配 V2 第 2 阶段基础数据模型和分类/配置组中心（已发布）

- 用户要求继续产品适配 V2：不修改旧版产品适配业务、不修改旧 BOM、不切换正式菜单；V2 使用独立 `material_center_v1/adaptation_v2/`；V2 新表使用 `mc_pa2_` 前缀；完成第 1 阶段后不用停，进入第 2 阶段直至完成。
- 已确认用户提供的主说明 `/Users/qiulei-office/Downloads/ARTDON_PRODUCT_ADAPTATION_V2_MASTER_IMPLEMENTATION_SPEC.md` 与仓库 `material_center_v1/docs/ARTDON_PRODUCT_ADAPTATION_V2_MASTER_IMPLEMENTATION_SPEC.md` 完全一致。
- 第 2 阶段本地实现：新增 V2 基础库 `adaptation_v2/lib/foundation.php`，新增 V2 专用迁移器 `adaptation_v2/lib/migration_runner.php` 和 CLI 工具 `adaptation_v2/tools/migrate.php`，迁移文件为 `adaptation_v2/database/migrations/20260801_001_phase2_foundation.php`。
- 第 2 阶段新表仅限 V2 旁路：`mc_pa2_product_categories`、`mc_pa2_product_category_mappings`、`mc_pa2_group_definitions`、`mc_pa2_group_option_definitions`，并用 `mc_pa2_schema_migrations` 记录 V2 迁移账本。
- 第 2 阶段页面：`adaptation_v2/index.php` 升级为基础状态首页，新增产品分类中心、配置组定义中心和产品分类映射页面；模板中心、工作台、配置包、审批和发布仍保持后续阶段占位，不开发业务功能。
- 第 2 阶段 API：`adaptation_v2/api/index.php` 支持 `status/categories/category_save/groups/group_save/group_option_save/products/product_map_save`；写操作服务端检查 `adaptation_v2.*` 统一权限或既有 `material_center.adaptation.manage` 兼容权限，并写入既有 `mc_operation_logs`（`module=adaptation_v2`）。
- 统一权限：迁移会写入主说明要求的 `adaptation_v2.view/manage_category/manage_group_definition/manage_template/publish_template/configure_product/override_product/select_material/override_conflict/manage_rule/manage_package/approve/publish/view_price/manage_channel/view_log` 到 `crm_permissions`，不建立第二套账号或权限表。
- `material_center_v1/bootstrap.php` 仅补充识别 `/material_center_v1/adaptation_v2/api/` 为 JSON API，避免未登录或无权限时返回 HTML 重定向；不改旧版适配业务。
- 文档：新增 `adaptation_v2/docs/02_FOUNDATION_MODEL.md`，更新 `adaptation_v2/docs/EXECUTION_LOG.md`。新增 `material_center_v1/tests/adaptation_v2_phase2_contract.php` 锁定第 2 阶段边界和能力。
- 本地检查：`git diff --check` 通过；办公室电脑无 PHP。已将候选文件临时复制到正式服务器 `/tmp/artdon_pa2_phase2_candidate/` 做 PHP 语法和静态契约检查：`index.php`、`api/index.php`、`foundation.php`、`migration_runner.php`、`tools/migrate.php`、迁移文件和新增契约测试均无语法错误；`adaptation_v2_phase2_contract.php` 全部通过。
- 旧版边界检查：`git diff -- material_center_v1/adaptation material_center_v1/api/v1/adaptation.php material_center_v1/app/Services/AdaptationService.php material_center_v1/database/migrations` 为 0 行，本轮没有改旧版适配目录、旧适配 API、旧服务或旧迁移；未修改旧 BOM。
- 发布：第 2 阶段功能提交先推送到 GitHub 并同步服务器，随后为避免强推 GitHub 主分支，用普通 merge 提交 `35822157b4054e653b39f8c6e89066961d8441ca` 收尾；该提交已推送 GitHub `main` 并快进发布到正式服务器。
- 数据库：正式服务器已执行 `php material_center_v1/adaptation_v2/tools/migrate.php up`，应用 `20260801_001_phase2_foundation`。当前行数：`mc_pa2_schema_migrations=1`、`mc_pa2_product_categories=10`、`mc_pa2_product_category_mappings=0`、`mc_pa2_group_definitions=18`、`mc_pa2_group_option_definitions=13`、`adaptation_v2_permissions=16`。
- 服务器复检：V2 迁移二次执行返回 `applied=[]`（可重跑）；`bootstrap.php`、`adaptation_v2/index.php`、`adaptation_v2/api/index.php` PHP 语法通过；`material_center_v1/tests/adaptation_v2_phase2_contract.php` 全部通过；`home/categories/groups/products/logs` 五个 V2 页面 CLI 渲染无 Fatal。
- 旧版边界：正式菜单未切换，旧版 `material_center_v1/adaptation/` 仍保留；本轮未修改旧适配业务、旧适配 API、旧适配服务、旧 BOM 或旧 `mc_adaptation_*` 业务数据。
- 待最终收尾：本条上下文记录提交并同步后，再核对本地/GitHub/服务器同一 HEAD。

## 本次：产品适配 V2 第 1 阶段冻结、审计和蓝图落地（直接服务器发布）

- 用户提供主说明 `/Users/qiulei/Downloads/ARTDON_PRODUCT_ADAPTATION_V2_MASTER_IMPLEMENTATION_SPEC.md`，要求只执行“第 1 阶段：冻结旧版、审计和 V2 蓝图落地”，不修改旧版产品适配业务、不修改旧 BOM、不切换正式菜单，V2 使用独立 `adaptation_v2` 目录，后续新表统一使用 `mc_pa2_` 前缀，完成后停止等待验收，不进入第 2 阶段。
- 已完整阅读主说明，并复制到仓库 `material_center_v1/docs/ARTDON_PRODUCT_ADAPTATION_V2_MASTER_IMPLEMENTATION_SPEC.md`，作为后续阶段唯一主说明文件。
- 服务器备份已完成：旧 `material_center_v1/adaptation/` 目录备份到 `/www/wwwroot/Artdon/artdon_erp/material_center_v1/backups/adaptation_v2_phase1_20260731_223720/adaptation_directory.tar.gz`；旧适配相关 24 张表 SQL 备份到同目录 `old_adaptation_tables.sql`；表结构和行数审计为 `database_audit.json`，表清单为 `table_list.txt`。备份校验：目录包 `98e5704abf4c68f638b0d77cda2209606e3cd156e55593d17934f010abdc8801`，SQL `3f7b812caf311b1e2a0b2a7552cb02906d4171bec5986ff1bfa5b1605c4741c6`，审计 JSON `34ecf8712f425cb27f9b599a36eb667b9ffd6aae90378a7b880f9d4fe0d77701`。
- 本地新增 V2 旁路骨架：`material_center_v1/adaptation_v2/index.php` 为空首页并复用物料中心现有顶部和左侧布局；`material_center_v1/adaptation_v2/api/index.php` 只开放第 1 阶段状态接口；`material_center_v1/adaptation_v2/lib/response.php` 建立统一 JSON 响应；`material_center_v1/adaptation_v2/database/migrations/.gitkeep` 只保留迁移目录，不创建 `mc_pa2_*` 业务表。
- 文档落地：新增 `adaptation_v2/docs/01_CURRENT_AUDIT.md`、`01_ROUTE_MAP.md`、`01_DATABASE_AUDIT.md`、`EXECUTION_LOG.md`，记录旧功能清单、旧代码/接口/迁移/测试清单、V2 路由蓝图、旧表行数和备份位置。
- 新增 `material_center_v1/tests/adaptation_v2_phase1_contract.php`，用于固定第 1 阶段边界：正式菜单仍指旧版、V2 独立目录存在、API 不写业务、迁移目录无业务迁移、文档和执行日志齐全、上下文记录包含“停止等待验收”。
- 数据库变化：未创建、未修改、未删除任何业务表；未创建 `mc_pa2_*` 表；未修改旧 `mc_*` 表或旧 BOM。服务器只做备份和只读审计，没有直接编辑线上代码。
- 检查：本地 `git diff --check` 通过；本机无 PHP，已将候选文件临时复制到服务器 `/tmp/artdon_pa2_phase1_candidate/` 做只读检查，`adaptation_v2/index.php`、`adaptation_v2/api/index.php`、`adaptation_v2/lib/response.php`、`tests/adaptation_v2_phase1_contract.php` PHP 语法均通过；第 1 阶段契约 9 项通过。旧版 `adaptation/`、旧适配 API、旧适配服务、旧 adaptation JS 和旧适配迁移均无本地 diff。
- Git / 发布状态：本地已提交本阶段代码；当前 `main` 领先 `origin/main` 2 个提交（此前 CRM 全球电话区号提交 + 本次产品适配 V2 第 1 阶段提交）。`git push origin main` 被 GitHub 拒绝，原因是当前 SSH key 为 deploy key，缺少 `qiulei6386-stack/artdon_erp.git` 写权限；本机也未安装并登录 GitHub CLI `gh`，无法改用已认证账号推送。用户随后明确确认“直接推服务器，包含这两个提交”，因此本轮按用户授权临时绕过 GitHub，用本地 Git bundle 直接快进正式服务器；GitHub 仍待后续配置可写凭证后补同步。

## 本次：简化物料中心单产品配置工作台（已发布）

- 用户要求只改 `/material_center_v1/adaptation/` 的单产品工作台，入口首页和全部产品页暂时保留；目标是把默认六步工程流程改为“快速配置为主 + 高级设置按需打开”，不删除现有技术范围、配置组、规则、审批、版本、旧 BOM 或权限系统。
- 服务器备份：已备份当前 adaptation 目录到 `/www/wwwroot/Artdon/artdon_erp/material_center_v1/adaptation_workspace_simplify_backup_20260731_153302`；已导出 89 张 `mc_*` 表 SQL 到 `/www/wwwroot/Artdon/artdon_erp/material_center_v1/backups/mc_tables_workspace_simplify_20260731_153356.sql`（约 6.2MB）。另有表清单 JSON `/www/wwwroot/Artdon/artdon_erp/material_center_v1/backups/mc_tables_workspace_simplify_20260731_153302.json`。未修改数据库业务数据。
- 本地改造：`adaptation/index.php` 增加旧 `step=range/core/optional/rules/approval/version` 路由映射，高级设置可按旧 step 打开；`assets/js/adaptation-v3.js` 将默认工作台改成三步快速配置：确认配置来源、四个核心配置、检查并保存；完整技术范围、扩展可选、条件规则、例外审批、配置版本/发布历史保留在“高级设置”；没有配置组时右侧抽屉不再占位；点击配置项时打开宽版候选物料比较区；保存草稿只建立四个核心配置组骨架，不自动选物料；推荐复制产品不复制审批/发布状态。
- 样式：`assets/css/app.css` 增加快速工作台、核心配置行、动态缺失字段、检查结果、宽版候选区和底部操作条样式；1440 宽度下主工作区全宽，不保留空白右栏。
- 新增 `material_center_v1/tests/adaptation_quick_workspace_contract.php`，固定检查快速三步、四个核心配置、高级设置保留、右栏收起、宽版候选区和旧 step 兼容。
- 检查与部署：本地 `node --check material_center_v1/assets/js/adaptation-v3.js` 通过；`git diff --check` 通过；正式服务器 `php -l material_center_v1/adaptation/index.php` 和 `php -l material_center_v1/tests/adaptation_quick_workspace_contract.php` 通过；正式服务器专项契约 `adaptation_quick_workspace_contract.php` 9 项通过；服务器 PHP CLI 渲染 `product_id=82` 与旧 URL `step=rules` 均无 Fatal，并确认测试对象 `32.05315 BEAMX TRACK LIGHT` 的 `product_id=82`。服务器无 Node，JS 语法以本地 Node 检查为准。
- Git / 部署：功能提交 `f0237e7f49a50cc93927f3653043f0df45324d2e` 已推送 GitHub `main`，并通过 Git bundle 在正式服务器 `/www/wwwroot/Artdon/artdon_erp/` 仅快进发布。未修改、删除或回填任何 `mc_*` 业务数据、旧 BOM 或其它物料中心页面。服务器仍保留未跟踪备份目录 `material_center_v1/adaptation_backup_20260731_094029/` 和本次 `material_center_v1/adaptation_workspace_simplify_backup_20260731_153302/`，本次未纳入 Git。

## 本次：修复报价选择 EX097 客户不弹出佣金提醒（已发布）

- 用户反馈报价中心已建立佣金规则 `EX097`，但在新建报价选择客户 `EX097 | Glist lighting | 印度` 时，没有弹出“此客户需要确认佣金”提醒。
- 根因定位：报价页前端 `checkSelectedCustomerCommission()` 已正确传入 `customer_code:c.code`，但后端 `quote_commission_customer_check()` 对客户代码只错误匹配了 `quote_commission_rules.customer_id`，没有匹配新规则编辑器保存的“佣金对象”字段 `target_name`。因此截图中 `target_name=EX097`、`target_type=代理` 的启用规则不会被识别，`has_rule=false`，前端自然不弹窗。
- 修复：客户代码现在同时兼容早期 `customer_id=EX097` 写法和新规则 `target_name=EX097` 写法；历史佣金查询也兼容订单 `customer_json` 中保存的客户代码，不引用不存在的订单 `customer_code` 字段。
- 修改文件：`quote_api.php`、新增 `tests/commission_customer_code_rule_contract.php`、本上下文。未修改、删除或回填任何报价、订单、客户、佣金或其它业务数据。
- 检查与核验：`git diff --check` 通过；本地 Node 静态契约 5 项通过；正式服务器 `php -l quote_api.php` 通过，`tests/commission_customer_code_rule_contract.php` 5 项通过。正式库只读核验确认 `EX097` 规则真实字段为 `target_name=EX097`、`customer_id=''`、`target_type=agent`、`commission_value=3.0000`、币种 RMB；`quote_customer_selected/customer_has_commission_rule` 提醒为启用。
- Git / 部署：功能提交 `cb2aa44782ea9addc4d3453340c25a6a8ac4da38` 已推送 GitHub `main`，并通过 Git bundle 在正式服务器 `/www/wwwroot/Artdon/artdon_erp/` 仅快进发布。服务器复检通过；只读核验未修改任何业务数据。服务器仍保留此前未跟踪备份目录 `material_center_v1/adaptation_backup_20260731_094029/`，本次未触碰。

## 本次：历史报价列表显示前 4 个产品小图（已发布）

- 用户要求历史报价列表右侧图片区显示第 1–第 4 个产品图片，而不是只显示 1 张；图片卡片要缩窄到接近图片宽度，并向左靠近报价/订单信息，保证小显示器也能看到 4 张。
- 根因：前次为了提速，`init` 首屏只返回报价摘要并把完整 `items_json` 清成 `[]`；历史列表前端虽然已经有 `items.slice(0,4)`，但拿不到产品明细，只能退回 `product_json` 的首个产品。
- 修复：`quote_api.php` 的 `init` 摘要新增前 4 个产品的轻量缩略图数据，只包含图片、型号、名称、客户代码和颜色，并保留 `history_item_count`；完整报价明细仍然只在点击打开时按单读取，不恢复首屏全量明细下载。
- 布局：历史列表右侧图片区改成 `4 × 82px` 固定小卡、按内容宽度显示并靠近左侧报价信息；900px 以下压缩为 `4 × 70px`，700px 以下为 `4 × 64px`，仍保持 4 张一排。
- 修改文件：`quotation.php`、`quote_api.php`、新增 `tests/history_quote_products_contract.php`、本上下文。未修改、删除或回填任何报价、订单、客户、佣金、收款、出货或其它业务数据。
- 检查：`git diff --check` 通过；报价页 5 个内嵌 JavaScript 块 `node --check` 通过；服务器 PHP 对 `quotation.php`、`quote_api.php`、新增测试语法检查通过；正式服务器 `tests/history_quote_products_contract.php` 与 `tests/quotation_runtime_performance_contract.php` 均通过。
- Git / 部署：功能提交 `105807fb5c5540c2dfffbe6d7642ceede7b72694` 已推送 GitHub `main`，并用 SHA-256 为 `a7a43e74cea22b0034e54a74a377fdd12a0452388e0263ba298859d69f46b7a8` 的 Git bundle 在正式服务器仅快进发布。服务器 HEAD 为 `105807fb5c5540c2dfffbe6d7642ceede7b72694` 且工作树干净。

## 本次：修复报价首页误恢复订单与首页/佣金入口慢（已发布）

- 用户反馈直接打开 `https://novlight.com/artdon_erp/quotation.php` 时，有时会自动打开旧订单，例如 `AT-260728CN010`；报价首页打开慢，“佣金策略 → 报价/订单佣金”也慢。
- 根因一：报价页保存了 `artdon_quote_current_page` 和 `artdon_quote_open_context`，启动时会读取上次页面和上次打开对象；后续“CRM订单直达/自动打开订单”补丁又包装了 `restoreLastPage()`。因此普通首页 URL 没有任何订单参数时，也可能按浏览器旧 localStorage 回到订单页并打开旧订单。
- 修复一：普通打开 `quotation.php` 一律初始化为空白报价首页，并清除旧的打开上下文；只有 URL 明确带 `page=orders`、`#orders`、`order_id` 或 `order_no` 时才进入订单页/自动打开订单。订单直达功能保留，但不再污染普通首页入口。
- 根因二：首页 `renderDash()` 首屏会自动调用 `ensureDashOrderData()` 和 `ensureDashDocData()`，一打开报价首页就额外拉订单与单证接口；订单自动打开时还可能并发重复触发订单列表读取。
- 修复二：首页仪表盘首屏不再自动拉订单/单证，改为进入订单中心或单证中心后同步；订单列表读取增加 `ORDERS_LOADING_PROMISE` 并发复用，避免同一时刻重复请求。佣金页清除旧版“订单 + 报价阶段双接口混拉”的残留函数，只保留稳定版单数据源分页。
- 正式库只读扫描：报价 64、订单 14、订单产品 39、佣金快照 4、佣金产品行 1；佣金订单计数与列表 SQL 约 `0.2–0.3 ms`，确认不是业务数据量导致慢。扫描未写入任何业务数据。
- 修改文件：`quotation.php`、`tests/quotation_runtime_performance_contract.php`、`tests/commission_order_path_contract.php`、本上下文。未修改、删除或回填任何报价、订单、客户、佣金、收款、出货或其它业务数据；用户原有商务中心未跟踪/删除文件继续保持不动。
- 检查：`git diff --check` 通过；报价页 5 个内嵌 JavaScript 块 `node --check` 通过；服务器 PHP 对 `quotation.php`、`quote_api.php`、`quote_order_api.php` 语法检查通过；正式服务器专项契约 `quotation_runtime_performance_contract.php`、`commission_order_path_contract.php`、`commission_summary_page_contract.php`、`quote_save_identity_guard_contract.php` 全部通过。
- Git / 部署：功能提交 `eb8894c45406ff921b20d55bf1765d2bd75439b0` 已推送 GitHub `main`；因服务器无法直接 `git pull` GitHub（publickey 被拒），已用 SHA-256 为 `3d0bda2e749d1283f685b7eba127d611a008fd38f8fd5069b2f2fab2d77d0ed2` 的本地 Git bundle 在正式服务器仅快进发布。服务器 HEAD 为 `eb8894c45406ff921b20d55bf1765d2bd75439b0` 且工作树干净。
- 待用户登录浏览器强制刷新后验收：普通打开 `quotation.php` 应停留在空白报价首页，不再自动打开 `AT-260728CN010` 或其它旧订单；首页首屏不应再被订单/单证接口拖住；进入“佣金策略 → 报价/订单佣金”应只按当前来源读取一页数据。

## 本次：报价审核预览增加 MOQ 审核列（已发布）

- 用户要求在“报价审核预览”的“成本公式”后增加 `MOQ` 列：报价产品行已有 MOQ 时自动带入；没有填则显示为空，审核时可手工填写。
- 前端调整：审核弹窗表头改为 `成本公式 → MOQ → Specification`；每个产品行新增可编辑 `review-moq` 数字输入框，只读取已保存产品行的 `it.moq`，不额外用产品库默认值补空；审核提交时将 `moq` 和 `approved_moq` 写入审核产品快照。
- 后端调整：审核通过合并产品明细时，允许审核修改 MOQ，并保留空白；审核日志差异增加 MOQ 修改记录。产品、图片、规格和部件仍继续以已保存报价为准。
- 修改文件：`quotation.php`、`quote_api.php`、新增 `tests/quote_review_moq_contract.php`、本上下文。未修改报价、订单、客户、佣金或其它业务数据。
- 本地检查：`git diff --check` 通过；报价页 5 个内嵌 JavaScript 块 `node --check` 通过；借腾讯云 PHP 解释器对 `quotation.php`、`quote_api.php` 和新增契约测试文件做语法检查通过。
- Git / 部署：功能提交 `0bec910fc9abc1c0c648ea63abf3698ff3485f68` 已推送 GitHub `main`，并通过 SHA-256 为 `3e14631bf5dd82a257a2ef83eac85302e8ad958d3b3c2e65b1c8eb87c269aac7` 的 Git bundle 在正式服务器仅快进发布。服务器 `quotation.php`、`quote_api.php` 语法通过；`tests/quote_review_moq_contract.php` 和 `tests/quote_save_identity_guard_contract.php` 均通过。

## 本次：修复报价保存跨单覆盖防线（已发布）

- 用户要求先修复报价单保存问题，重点防止历史报价打开/另存版本时复用旧数据库 ID，导致 A 报价被 B 报价号和客户覆盖。
- 前端修复：`另存版本` 改为强制新增保存，不再携带当前 `S.currentQuoteId`；报价号规范化保留 `-V2/-V3` 版本尾巴；版本号正则修复为可正确识别已有版本；历史报价完整明细未加载完成时禁止保存；慢请求返回时如果用户已经打开其它报价，会丢弃旧响应，避免旧状态回写。
- 后端修复：`save_quote` 在执行 `UPDATE quote_orders` 前核对传入 `id` 对应的原报价号和客户；若页面提交的报价号或客户与该 ID 原记录不一致，直接拒绝保存并写入 `save_quote_identity_blocked` 日志，不再允许跨单或跨客户覆盖。
- 新增 `tests/quote_save_identity_guard_contract.php`，固定检查“另存版本必须新增、加载中禁止保存、旧响应不能覆盖新打开动作、后端拒绝 ID/报价号/客户不一致”的关键防线。
- 修改文件：`quotation.php`、`quote_api.php`、`tests/quote_save_identity_guard_contract.php`、本上下文。未修改或回填任何报价、订单、客户、佣金或其它业务数据；用户原有商务中心未提交文件继续保持不动。
- 本地检查：`git diff --check` 通过；报价页 5 个内嵌 JavaScript 块 `node --check` 通过；既有报价物料名称去重测试通过；借腾讯云 PHP 解释器对 `quotation.php`、`quote_api.php` 和新增契约测试文件做语法检查通过。
- Git / 部署：修复提交 `20e768e915d93f02c0b9d506769194b78fb3e0f3` 已推送 GitHub `main`，并通过 SHA-256 为 `67a28bec0e09443776b249fe507a83e9af70be332e784e91cf52bae1aed5a67e` 的 Git bundle 在正式服务器仅快进发布。服务器 `quotation.php`、`quote_api.php` 语法通过；`tests/quote_save_identity_guard_contract.php` 和 `tests/quotation_runtime_performance_contract.php` 均通过；三端已核对为同一提交。

## 本次：找回并恢复被覆盖的 AT-260730CN010 报价（已完成）

- 用户反馈 `AT-260730CN010` 已保存但历史报价中消失，约有几十项。正式库与日志只读扫描确认：该报价最后一次完整保存于 2026-07-30 20:14:58，客户 Youngjin EL Co., Ltd.，报价 ID 116，19 个产品、总数量 11,148、金额 RMB 511,574.60。
- 根因证据：20:19:56 同一报价 ID 116 被 Plus Light Tech 的 `AT-260730EX110` 覆盖，随后改号为 `AT-260727EX110`；ID 117 中的早期副本也被另存为单项 `AT-260730CN010-01`。这是数据库 ID 被错误复用造成的覆盖，不是用户没有保存。
- 经用户明确允许，使用服务器只读 sudo 解析 MySQL `mysql-bin.000186`，从 20:14:58 最后一次正确 `UPDATE quote_orders` 事务中完整提取原报价。恢复副本包含全部原始报价字段、19 个完整产品对象和配件数据。
- 校验：恢复副本报价号、客户、币种正确；19 项数量合计 11,148，与存储总数量一致；19 项金额合计 RMB 511,574.60，与存储总金额一致。恢复 JSON SHA-256：`3253f6dd63844e86d1b31ed03b59766d9eebc3ab06a69ab5588a61677b1a593a`。
- 经用户明确要求，已将恢复副本以新报价号 `AT-260730CN010-03` 写入正式库，新数据库 ID 为 127、版本号 3、审核状态待审核；保存后再次核对为19项、数量11,148、金额 RMB 511,574.60，全部一致。
- 写入前已确认目标报价号不存在，并备份当前 ID 116、117 及相关 CN010 行至 `/www/wwwroot/Artdon/artdon_erp/_codex_backups/recovered_quotes/before_AT-260730CN010-03_20260730_204404.json`，备份 SHA-256 为 `8c26889d5d954e697c407c40abf1a9e2cef1ab0a9e4e04cb6c6e621462051676`。恢复采用新 ID 插入，没有覆盖或修改现有 ID 116、117、`AT-260730CN010-01` 或其他报价。
- 仍需后续修复前端陈旧报价 ID 导致跨客户覆盖的问题；本次只按用户要求恢复数据，没有借机修改业务代码。

## 本次：佣金策略“新增规则”改为完整规则编辑器（已发布）

- 用户反馈原“新增规则”仅用浏览器输入框询问佣金对象，界面不正规，也无法在一次操作中配置完整规则。
- 重构：新增 1120px 正规规则编辑弹窗，按“规则与对象、适用范围、计算与结算、内部说明”四区组织；可一次设置规则名称、佣金对象及类型、联系方式、全部/客户/产品/分类/组合范围、佣金模式与数值、计算基准、币种、结算节点、初始结算状态、已结金额、启停状态和备注。
- 交互：客户、产品和分类提供现有数据候选；适用范围会联动启用对应条件；佣金模式联动数值提示；右侧实时生成规则摘要；保存前检查必填项和范围条件；保存按钮防重复提交并直接调用现有真实 `commission_rule_save` 接口。已有规则可双击表格空白处打开同一完整编辑器，原表格内联编辑和批量保存保持不变。
- 修改文件：`quotation.php`、新增 `tests/commission_rule_editor_contract.php`、本上下文。没有修改佣金计算公式、匹配优先级、历史订单快照或任何业务数据。
- 检查：`git diff --check`、报价页 5 个内嵌 JavaScript 块解析通过；腾讯云 PHP 对 `quotation.php` 语法检查通过，完整规则编辑器专项契约通过。
- Git / 部署：功能提交 `835ad5919affc04df5da69c7d7d13b043c33e328` 已推送 GitHub `main`，并通过 SHA-256 为 `7364f459d17a0ced93ea1f04a9b7a898bac88927f13d14d529f405bc231fa40a` 的 Git bundle 在正式服务器仅快进发布；服务器语法、规则编辑器和佣金汇总专项契约全部通过。

## 本次：重排佣金汇总财务表格（已发布）

- 用户反馈“佣金汇总”三张表格拥挤难看：表头与数据缺少间距，金额没有对齐，币种在独立列和金额中重复，蓝色订单按钮过于抢眼。
- 调整：为佣金汇总增加独立财务表格样式；表头固定、单元格增加边界和留白、金额统一右对齐并使用等宽数字、已收/待收/预计佣金分色；币种改为轻量标签且金额不再重复币种；客户、客户代码、订单链接和结算状态建立明确视觉层级；窄屏保持横向滚动。
- 修改文件：`quotation.php`、新增 `tests/commission_summary_layout_contract.php`、本上下文。只调整页面布局与金额显示，不修改佣金计算、筛选接口或任何业务数据。
- 检查：`git diff --check`、报价页 5 个内嵌 JavaScript 块解析通过；腾讯云 PHP 对 `quotation.php` 语法检查通过，佣金汇总布局专项契约通过。
- Git / 部署：功能提交 `04e90e7a1e8c87bb0633f6521a0af32ed13d9b02` 已推送 GitHub `main`，并通过 SHA-256 为 `0fad94f843956c87a7b8a2602c4422f2a7ab84b80c1acc34a75426653e511bca` 的 Git bundle 在正式服务器仅快进发布；服务器语法和布局专项契约通过。

## 本次：选择“客户”后产品预计佣金仍为 0（已发布）

- 用户截图反馈：订单 27 的产品行选择“客户”，佣金模式为“每件固定”、佣金值 1、数量 1000，但预计佣金仍显示 0.00。
- 根因：报价页面后段的草稿优化函数覆盖了早期实现；选择对象类型只写入 `target_type`，没有自动带入订单客户，也可能在所选值与旧字段相同时裁掉草稿，使该产品仍被判定为“不参与佣金”。前端计算公式和后端 `fixed_unit = qty × value` 公式本身正确，但参与状态没有被可靠开启。
- 修复：选择对象类型“客户”时自动带入当前订单客户名称作为佣金对象；编辑对象、模式、佣金值、计算基准等产品佣金字段时自动启用“参与佣金”；已有佣金字段也会被识别为已配置。产品行会即时显示预计佣金，保存时后端继续按数量复算。
- 修改文件：`quotation.php`、新增 `tests/commission_customer_estimate_contract.php`、本上下文。未修改或回填任何现有佣金、报价、订单或客户数据。
- 检查：`git diff --check`、报价页面 5 个内嵌 JavaScript 块解析、既有报价名称去重测试通过；腾讯云 PHP 语法和客户预计佣金专项契约 5 项全部通过。
- Git / 部署：功能提交 `c927098512da932840aefb817838583bc98dc1d3` 已推送 GitHub `main`，并通过 SHA-256 为 `5c6ff718959d3c17a9e1863d3f8f8831a343e8eeaa149c7629f8680ad96a3bcc` 的 Git bundle 在正式服务器仅快进发布。服务器语法和专项契约 5 项全部通过。
- 待用户强制刷新后在订单 27 产品行重新选择“客户”，确认自动带入“成勋照明”并显示预计佣金 RMB 1,000.00；保存会写真实佣金，本次没有代替用户提交。

## 本次：修复报价首页及历史报价打开缓慢（已发布）

- 用户反馈佣金修复后，`quotation.php` 首页仍然非常慢，“历史报价 → 打开”也需等待很久；截图中页面 HTML 已出现，但右上角仍为“未登录”、统计均为 0，说明核心初始化接口尚未完成。
- 根因一：`quote_api.php` 的公共入口在每一个请求前都重复执行报价核心、系统设置、价格策略、权限和审批共 4 套结构检查，其中包含大量 `INFORMATION_SCHEMA`、补字段和补索引探测。首页先调用 `auth_status` 再调用 `init`，历史报价详情又调用同一公共入口，因此三个场景都会重复承受结构扫描。
- 修复一：新增 `quote_runtime_schema_state` 数据库版本门控。只有首次安装或版本升级时运行完整结构迁移；版本命中后每个请求只读取一条状态记录，不再重复执行整套建表/补字段/补索引。客户结构也并入一次性迁移，`init` 不再再次扫描。
- 根因二：首页 `init` 已只返回历史报价摘要，但页面加载完成后仍通过 `list_quote_details` 立即拉取最多 1000 份完整报价，下载大量 `items_json` / `parts_json` 并重新渲染历史、审核和看板；历史报价“打开”也没有使用已经存在的 `get_quote_detail` 单条接口。
- 修复二：首页不再后台全量加载历史明细；历史列表继续使用轻量摘要，点击“打开”或“复制”时只请求当前一份报价详情并缓存。转订单弹窗同步等待该单详情加载完成，避免空产品或旧状态。
- 修改文件：`quote_api.php`、`quotation.php`、新增 `tests/quotation_runtime_performance_contract.php`、本上下文。未修改、回填或删除任何报价、订单、客户、物料或其他业务数据；用户原有商务中心文件保持不动。
- 检查：`git diff --check`、报价页面 5 个内嵌 JavaScript 块解析通过；腾讯云 PHP 对 `quote_api.php`、`quotation.php` 语法检查通过；报价运行性能专项契约 6 项全部通过。
- Git / 部署：功能提交 `81f4bb226c7c761494daf7933e3b169b8dea98b3` 已推送 GitHub `main`，并通过 SHA-256 为 `3b97630c1e95484efa48180cd7f27abf4d5e3f136b028df3c5d63e0b497ef019` 的 Git bundle 在正式服务器仅快进发布。
- 正式库已初始化 `quote_runtime_schema_state` 的 `quotation=2026073001` 结构版本元数据，使用户第一次刷新也直接走快速门控；只新增/更新结构版本元数据，没有修改报价、客户、订单、物料或其他业务数据。服务器两份 PHP 语法和 6 项专项契约通过。
- 待用户强制刷新实测：首页核心数据出现速度，以及“历史报价 → 打开”单条报价的速度。服务器部署账号无权读取 Nginx 访问日志，因此不能从该账号冒充浏览器端真实网络耗时验收；若仍慢，需要在用户已登录浏览器中捕获 Network 请求耗时。

## 本次：重新扫描并修复佣金打开/搜索慢、订单保存字段错位与客户 ID 变 0（已发布）

- 用户再次反馈报价／订单佣金页面打开和搜索约需 8 秒，保存订单 27 时仍报 `Incorrect integer value: 'AT-260724EX028' for column 'order_id'`，选择客户后客户 ID 显示为 0。
- 根因一：上次提交 `034d5ea` 只修复了 `quote_api.php` 中的同名佣金保存函数，但页面订单佣金实际调用 `quote_order_api.php`；后者仍把参数按 `quote_id, quote_no, order_no, order_id` 拼入 `quote_id, order_id, quote_no, order_no`，所以业务订单号仍会写入整型 `order_id`。本次已修正真实入口，并新增同时约束两套接口的专项契约。
- 根因二：正式库只读核对订单 27（`AT-260724EX028`）客户名称为“成勋照明”，但 `customer_id` 为空；现有 13 个订单的客户名称均正常，但 `customer_id` 全为空。前端 `customerDbId()` 只接受纯数字 `id`，没有读取 CRM 客户的 `crm_customer_id`，也不兼容 `crm_123` 标识。本次改为优先读取 `crm_customer_id` 并兼容两种 ID 形式，后续新建/更新订单会保存真实 CRM 客户 ID；未擅自回填历史订单。
- 根因三：正式库佣金列表、计数与搜索 SQL 实测均约 `0.2–0.4 ms`，数据量仅 13 单，不是 SQL 数据量导致 8 秒。真实订单接口在每次佣金读取前仍执行整套订单表结构检查，并在佣金结构函数中反复执行建表/补字段检查。本次佣金动作跳过全量订单结构扫描，佣金结构优先读取已存在的 `quote_commission_schema_state` 版本，只有首次安装/升级才执行迁移。
- 修改文件：`quote_order_api.php`、`quotation.php`、新增 `tests/commission_order_path_contract.php`、本上下文。用户原有商务中心命令文件删除和未跟踪文档保持不动。
- 检查：`git diff --check`、报价页内嵌 JavaScript 语法通过；腾讯云 PHP 对 `quote_order_api.php`、`quotation.php` 语法检查通过；专项契约 6 项全部通过。正式库扫描及性能测量均为只读，没有修改报价、订单、客户、佣金或其他业务数据。
- Git / 部署：功能提交 `e54ec4e40cca5de2818523475e4fa90d6a9bca29` 已推送 GitHub `main`，并通过 SHA-256 为 `0c97a6a4be8e3c9c6a4a809b760d9a9e3721c1371de26ffdfe7330e3d7d8ed7a` 的 Git bundle 在正式服务器仅快进发布。服务器 `quote_order_api.php`、`quotation.php` 语法及 6 项专项契约通过；未登录 CLI 请求被鉴权正确拒绝，未据此冒充登录后页面验收。
- 待用户登录实测：强制刷新后打开“报价／订单佣金”，记录打开及搜索耗时，并在订单 27 保存一笔实际佣金。因该动作会写真实佣金数据，本次没有代替用户在正式库提交；若仍慢，需从用户已登录页面捕获具体网络请求耗时继续定位。

## 本次：修复报价配件名称重复显示（已发布）

- 修复数字开头型号被误识别为品牌的问题，`2 Wire I-connector` 不再显示成 `2 2 Wire I-connector`。
- 报价物料选择、报价明细与预览共用同一套去重规则；相同名称与型号只显示一次。
- 保留正常的数字品牌，例如 `3M X100`，避免修复影响其他物料。
- 新增针对性自动测试，覆盖本次配件及正常品牌型号组合。

## 本次：稳定报价／订单佣金分页与保存提示（已发布）

- 用户反馈“报价／订单佣金”页切换下一页后无法稳定返回、列表会从 60 条突然变为 11 条、页面闪动，并在没有实际修改时弹出需要保存提示。
- 根因确认：旧页面把报价与订单的两套分页结果临时拼接。第一页同时请求并重复渲染两套数据，第二页却只按订单数重新计算总数，导致页码、总数和列表相互覆盖；这不是 CSS 主因。另有草稿逻辑在把值改回原值后仍保留空草稿，误判为未保存。
- 修复：改为明确的数据来源选择（订单 / 报价阶段），每种来源只使用自身后端分页和总数；同一次加载合并为一次稳定渲染，以加载状态淡化保留现有表格，避免清空后闪动。真实修改才进入草稿和离页确认，改回原值不会再提示保存。
- 保存规则未改变：仍须点击“批量保存”才写入，页面加载、翻页、筛选、展开产品行都不会自动保存或改写佣金数据。
- 修改文件：`quotation.php`、本上下文。未更改报价、订单、收款、出货、佣金历史或其他业务数据；用户原有未跟踪文件保持不动。
- Git / 部署：修复提交 `a167acdd9a87a0ff5a4095a30e401851cebd6c00` 已推送 GitHub `main`，并以受控 Git bundle 发布到正式服务器；服务器工作树干净，版本与该提交一致，且 `quotation.php` 服务器语法检查通过。

## 本次：报价总结显示最后一次跟进、真实 Excel 导出与可调列宽（待发布）

- 报价总结“报价明细”将“下次跟进时间”改为“最后一次跟进时间”，仅按同一报价 ID 和 `legacy` 来源读取 CRM 最新一条未删除跟进的实际记录时间 `contacted_at`；不会误带同一客户的其他报价或客户级跟进。
- 导出改为真正的 `.xlsx` 工作簿（不是文本伪装成 `.xls`）：仅含“报价明细”工作表、表头样式、边框、冻结首行、筛选器、自动换行和列宽；不附带统计、图表或其他页面数据。
- 网页端报价明细的每个表头右缘支持拖动调列宽，宽度保存在当前浏览器；不改变既有报价或订单数据。
- 修改文件：`quote_api.php`、`quotation.php`、`tests/quotation_summary_last_followup_excel_contract.php`、本上下文。只读关联 CRM 跟进数据，不修改报价、订单、CRM 跟进或任何业务数据。
- Git / 部署：功能提交 `23a79aac6d8129c93d36a83fca2453e6ead80942` 已推送 GitHub `main`，并以 SHA-256 `bd8dcbdc001747ac447d9c76099663a9c4373fdafff3fd00a45c50f8f5b86d79` 校验的 Git bundle 在正式服务器仅快进发布；未直接编辑服务器代码。

## 本次：恢复统一权限中心的 PLM 分类与梁文钊 PLM 授权（已发布）

- 用户反馈统一权限中心中“梁文钊”的 PLM 权限与“PLM 权限”页同时消失。正式库只读核对确认：`plm.view/create/edit/delete/export/admin` 六项权限记录仍在；页面的系统分类清单遗漏 `plm`，因此导航、人员授权页及权限统计都不显示 PLM。
- 同次核对确认梁文钊（账号 `LWZ`，工程部）当前没有 PLM 的个人覆盖或角色继承记录。权限审计没有显示曾被有意撤销 PLM 授权。因把权限补给整个工程部会影响同角色全部人员，本次不做角色级扩权。
- 修复：统一权限中心补回“PLM权限”中文页签、模块/域中文名与权限分类；权限中心加载时确保 PLM 六项基础权限登记；只为梁文钊恢复日常 PLM 的查看、新增、编辑、导出四项个人授权。删除和 PLM 管理员权限不自动授予，仍须管理员单独配置。
- 修改文件：`permissions.php`、`includes/artdon_sso_core.php`、`material_center_v1/tests/unified_permission_contract_test.php`、本上下文。未删除、改写或重置任何用户、角色、物料、PLM 项目或既有授权记录。
- 发布与验证：分类恢复提交 `f0e9f7af8a` 和范围收窄提交 `783c852a3b` 已依次推送 GitHub `main`，并以校验 Git bundle 在正式服务器仅快进发布。服务器 PHP 语法和统一权限专项回归通过；正式库核对梁文钊个人授权为 `plm.view/create/edit/export` 四项，工程部角色没有被授予任何 PLM 权限。

## 本次：产品适配重构为统一“产品配置工作台”（本地完成，待发布）

- 用户明确要求不再修补原来的“产品列表 + 配置总览 + 引导配置 + 批量矩阵”并列页面，而是按完整配置流程改为单一连续工作台：选择产品 → 核心必配 → 扩展可配 → 条件规则 → 检查发布。左侧物料中心菜单和顶部 ERP 导航保持不变。
- 页面已改为：默认不长期显示产品列表；“切换产品”打开抽屉，选择后锁定在顶部产品摘要，并使用 AJAX 更新 `product_id` 和浏览器前进/后退历史。主体按核心必配、扩展可配和下一步建议呈现；点击卡片再打开右侧配置详情抽屉。
- 右侧抽屉已支持 560–720px 拖动调整并记住个人宽度；候选物料、已选物料、默认、替代、条件、价格/交期和审批仍连接原有真实数据接口。候选不适配物料若有覆盖权限，必须填写“强制添加说明”，后端会标为需要审批并写入操作日志；普通物料不能借此绕过校验。
- 产品适配已新增不可变发布版本表 `mc_adaptation_published_versions`。发布时保留完整快照、版本号、发布人、发布时间和审批关联；商务中心优先读取最后一版已发布快照，后续草稿修改不再静默改变已发布商务数据。迁移尚未在正式库执行。
- 同时补齐并重写产品适配专项契约，使其校验新工作台结构、右侧抽屉、候选选择、强制说明、发布版本以及旧 URL/核心服务兼容；本机 JavaScript 语法与空白差异检查已通过。本机没有 PHP，PHP 语法、迁移和专项契约将在正式服务器同一提交上执行。
- 修改文件：`material_center_v1/adaptation/index.php`、`material_center_v1/api/v1/adaptation.php`、`material_center_v1/app/Services/AdaptationService.php`、`material_center_v1/assets/css/app.css`、`material_center_v1/assets/js/adaptation-shell.js`、`material_center_v1/database/migrations/20260729_021_adaptation_published_versions.php`、`material_center_v1/docs/product-adaptation-workbench-mapping.md`、多份 `material_center_v1/tests/*adaptation*contract*.php` 与本上下文。
- 发布前备份完成：本地 Git bundle `/tmp/artdon_adaptation_preworkbench_20260729.bundle`（SHA-256 `09d9a4bb8b733d21427b4eba5e8c6146f25ef793df1aa8caab40a485dde47d73`）；正式服务器数据库备份 `/www/wwwroot/Artdon/artdon_erp/_codex_backups/adaptation_workbench_20260729/database_20260729_094045.sql.gz`（SHA-256 `531fec41465d8f57f79d2b31d6c6c47fd8d5c2106df2e6fe48669b6ff49e748e`，已验证压缩包）。未删除、重置或修改任何现有产品、物料、配置、审批、BOM 或用户数据；用户原有商务中心未跟踪文件保持不动。
- 下一步：完成专项契约检查 → 提交并推送 GitHub → 使用已推送提交的校验 bundle 在正式服务器仅快进发布 → 执行迁移、PHP 语法和专项回归 → 核对本地、GitHub 和服务器 HEAD 一致。

### 发布完成（2026-07-29）

- 工作台重构已以提交 `d2dc9d6e76d379608e9ae1c96e1f68636110c416` 推送 GitHub `main` 并仅快进发布到正式服务器；发布包 SHA-256 为 `b45a632bf36933355e5683ed24504a01338a4f72bc27c7967f54c08f9139731d`。
- 正式库已应用 `20260729_021_adaptation_published_versions`，用于保存不可变的已发布配置版本；未删除或改写任何既有产品、物料、BOM、草稿配置、审批或历史商务数据。
- 正式服务器 PHP 语法检查通过：产品适配入口、接口、服务、迁移；专项回归通过：工作台、详情抽屉/候选物料、优先级布局、持续显示/电源范围、模板复用、批量快速规则、产品适配流程。服务器工作区干净。

## 本次：统一权限中心中文化与物料中心中文授权名称（待发布）

- 用户确认统一权限中心需要中文展示，并要求物料中心所有授权项在该中心明确标注为“物料中心权限”。
- 调整：统一权限中心标题、指标说明、系统授权概览、权限预设和高级模式开关均改为中文；“高级模式显示权限编码”仍可按需显示底层 `key`，普通模式不展示编码。
- 物料中心权限卡片现直接采用统一权限表中已维护的中文业务名称，例如“物料中心 - 查看物料 / 编辑采购价 / 维护产品适配 / 物料审批 / 维护产品电源规则”，不再把 `power_rules_manage` 等英文动作编码显示给授权人员；底层权限键、角色授权与现有授权数据均未改变。
- 修改文件：`permissions.php`、`material_center_v1/tests/unified_permission_contract_test.php`、本上下文。未读取、修改或删除任何物料、产品、用户、角色或既有权限数据；用户原有商务中心命令文件删除和未跟踪文档保持不动。
- 检查：本地空白差异检查通过；办公室电脑没有 PHP，已使用腾讯云 PHP 对两份本地 PHP 文件做只读语法检查，均通过。发布后将在正式服务器执行同一专项契约并核对版本一致。


## 本次：修复报价总结筛选数据类型导致页面读取失败（已发布）

- 用户打开“报价总结”时出现 `读取失败：(values || []).map is not a function`，所有统计卡、图表与明细因此未能加载。
- 根因确认：报价总结筛选接口收集负责人、客户、国家/地区时使用了临时数组引用，排序后的列表没有写回原变量；JSON 返回了键值对象，而页面下拉框按数组调用 `.map()`，导致首个筛选项渲染时中断。
- 修复：服务端现在逐项排序并转换为连续 JSON 列表；页面新增统一列表标准化，兼容旧缓存或异常接口暂时返回对象的情况。趋势、占比和排行也统一通过该标准化入口读取，单个接口形态异常不会再让整个报价总结空白。
- 修改文件：`quote_api.php`、`quotation.php`、`tests/quotation_summary_contract.php`、本上下文。未读取、修改或删除报价、订单、收款、出货及任何业务数据；用户原有商务中心命令文件删除和未跟踪文档保持不动。
- 检查：本地 `git diff --check` 与报价页内嵌 JavaScript 语法通过；办公室电脑未安装 PHP，因此使用腾讯云 PHP 对三份本地文件进行语法检查，全部通过。正式服务器在同一提交下通过 `quote_api.php`、`quotation.php` PHP 语法和 `quotation_summary_contract.php` 专项契约。
- Git / 部署：功能提交 `a1f9a183d7bbebdcf0a2d42adc9e685a311d0ad0` 已推送 GitHub `main`，并以 SHA-256 `896f644243ed08bf601178f81a039524cab025338b652ef79ebf0affeff76619` 校验的 Git bundle 在正式服务器仅快进发布。本条收尾记录将再次按相同流程同步并核对办公室本地、GitHub 与服务器提交一致。

## 本次：物料中心权限纳入统一权限中心（已发布）

- 用户要求物料中心的全部权限不要散落在独立入口，需在“统一权限中心”有明确的“物料中心权限”栏目。
- 已将现有完整 `material_center.*` 权限域加入统一权限中心的系统分类：包括访问、物料查看/新建/编辑/批量/生命周期、导入导出、采购价和供应商、产品适配、审批、文档、设置、敏感字段及电源规则等权限；角色模板、人员单独授权、拒绝权限与审批申请均沿用同一个后端权限源。
- 页面入口将显示为“统一权限中心 → 物料中心权限”；该页只读取并修改 `crm_permissions`、`crm_role_permissions` 与 `crm_user_permissions` 中既有的统一权限，不创建第二套权限数据，也不改变任何现有物料、产品或审批数据。
- 修改文件：`permissions.php`、`material_center_v1/tests/unified_permission_contract_test.php`、本上下文。正式服务器已通过 `permissions.php` PHP 语法检查与物料中心统一权限专项契约；没有修改任何物料、产品、审批或已有角色数据。
- Git / 部署：功能提交 `5d9b2656448bf274e6eeec3397e58d920497967c` 已推送 GitHub `main`，并以 SHA-256 校验的 Git bundle 在正式服务器仅快进发布。此收尾记录提交后将再次同步并核对办公室本地、GitHub 与服务器提交一致。

## 本次：新增报价总结 / Quotation Summary & Analysis（待发布）

- 新增报价二级菜单“报价总结”，独立读取现有报价、订单、出货与收款数据；不写入业务数据，不修改 CRM、原报价单、订单、PI/PL/CI/PDF 或既有导出流程。
- 页面提供统计日期、快捷时间、币种、负责人、客户、国家/地区、业务状态和关键词筛选；默认本月。总览、趋势、状态占比、负责人 / 客户 Top 10、可排序分页明细和 Excel 导出均按同一筛选条件联动。
- 新接口全部在 quote_api.php 服务端完成：总览与趋势使用 SQL 聚合；明细按页读取；RMB 与 USD 独立统计、展示和导出，不做换汇或混合相加。导出最多读取当前筛选下 10,000 条明细，避免前端一次下载完整报价数据。
- 修改文件：quotation.php、quote_api.php、新增 tests/quotation_summary_contract.php、本上下文。用户原有商务中心命令文件删除和未跟踪文档保持不动。
- 检查：本地 git diff --check、报价页全部内嵌 JavaScript 解析及接口静态契约通过；办公室电脑未安装 PHP，本机修改通过腾讯云 PHP 8.0 解释器语法检查。正式服务器同一提交通过 quotation.php、quote_api.php 与专项契约 PHP 语法、报价总结专项契约；未执行任何写数据库或真实业务操作。
- Git / 部署：功能提交 257ea52dd2e9fc103683225d67c920b467aada07 已推送 GitHub main。GitHub 自动部署之外，已用 SHA-256 为 c798486fd71fa9d74555452f52d43baa4ab7066a637c265451fbe3ea8095866b 的同一已推送 Git bundle 在正式服务器仅快进发布；服务器工作区干净，服务器、本地与 GitHub 当前 HEAD 一致。本条最终状态记录将随收尾提交再次同步三方。

## 本次：产品适配改为“配置总览 + 右侧真实编辑”工作台（已发布）

- 用户要求产品适配页面按参考图重做，不只调整外观：先清楚展示产品是否完整、缺什么、是否冲突和下一步动作；再在右侧直接处理当前配置组的候选物料。
- 默认入口现为“配置总览”：顶部显示真实产品图片、型号、名称、系列、完成度、缺失项、冲突项、待审批数和正式选项数；下方“下一步建议”自动定位第一个缺默认、冲突、待审批或未添加选项的配置组。
- 配置组改为总览卡片：每张卡片直接显示默认物料、正式/候选/冲突数量和当前状态。点击“管理 / 立即处理 / 添加选项”会复用原有真实配置组工作区，右侧加载该组真实候选物料、关键范围、默认设置、替代关系、适用条件、价格/交期和审批；保存后重新读取工作区与统计，不是静态演示页面。
- 保留“引导配置”作为逐组精细编辑入口；“批量矩阵”继续调用原有批量套用逻辑；配置模板、从产品复制、切换产品、检查配置与提交审批均保留为现有真实入口。产品切换弹窗使用真实产品列表与搜索，选择后会锁定到该产品工作区。
- 修改文件：`material_center_v1/adaptation/index.php`、`material_center_v1/assets/js/adaptation-shell.js`、`material_center_v1/assets/css/app.css`、新增 `material_center_v1/tests/adaptation_overview_dashboard_contract.php`、本上下文。未改动产品、物料、配置或审批数据库数据；用户原有商务中心命令文件删除及未跟踪文档保持不动。
- 检查：本地 JavaScript 语法和 `git diff --check` 通过；正式服务器 `material_center_v1/adaptation/index.php` PHP 语法，以及总览、工作区、关键范围、复用模板、批量规则和产品适配流程共 9 项专项回归均通过。应用内浏览器能够打开线上入口，但没有继承 ERP 登录态，停在登录页；因此不把未登录页面当作登录后视觉验收。用户登录后可直接核对“物料中心 → 产品适配”。
- Git / 部署：功能提交 `8c991a9c39a88db80cac20683f43a4776cf015b2` 已推送 GitHub，并以 SHA-256 `a3e104178aab58f91eaa34eb86f13b07d569b2d96ac5310289d4fab46927bf9f` 的 Git bundle 在正式服务器仅快进发布。此收尾记录提交后将按同一流程同步并再次核对办公室本地、GitHub 与服务器提交一致。

## 本次：派工待办多人负责人逐行显示与操作按钮对齐（已发布）

- 用户反馈多人派工的负责人名称被 `/` 拼在一起，阅读困难；多人派工与个人派工的操作按钮会因任务行高、完成状态或按钮数不同而出现左右不齐。
- 调整：多人负责人改为一人一行、列内居中，不再输出斜线；操作列改为统一满宽居中轨道，查看、修改/转派、删除等按钮无论两枚或三枚、普通或已完成行均使用相同间距和中心位置。
- 本次只调整派工列表显示与样式，不改派工数据、权限或操作逻辑；新增专项契约防止后续重新引入斜线或完成行左对齐。
- 检查：办公室本地内嵌 JavaScript 解析和 `git diff --check` 通过；正式服务器 `dispatch_next.php` PHP 语法及 `dispatch_multi_table_alignment_contract.php` 专项契约通过。
- Git / 部署：功能提交 `702bad5ee6345b13c607fcceed0b1314827b81b8` 已推送 GitHub，并通过 SHA 校验 Git bundle 在正式服务器仅快进发布。此记录提交后再次核对办公室本地、GitHub 与服务器提交一致；未触碰用户原有商务中心命令文件删除和未跟踪文档。

## 本次：修复统一 LOGO 的真实上传目录（已发布）

- 用户反馈“设置 → 企业信息”仍只看到旧版“LOGO 路径”三个文本框，无法选择文件上传；此前不能只凭代码结论称已完成。
- 运行环境复核：正式服务器当前代码已包含“选择并上传 LOGO”按钮和真实上传接口，但截图中的“三个路径输入框 + 本阶段先保存路径”是提交 `ff71c14` 之前的旧页面内容，需在用户浏览器强制刷新后重新进入企业信息验证。
- 同时发现生产 `uploads/company_brand` 目录不存在；根目录 `uploads` 属于部署账号 `ubuntu:ubuntu`（775），PHP-FPM 运行用户为 `www`，无法在该根目录创建新目录，因此旧接口即使显示上传按钮也会在首次上传时失败。
- 修复：统一 LOGO 改存入已经由 PHP-FPM 管理且公开可访问的 `uploads/dispatch_next/company_brand/`；继续兼容旧 `uploads/company_brand/` 路径，仍严格限制为本地图片白名单。上传后 CRM 与派工沿用同一设置和缓存版本 URL。
- 修改文件：`includes/settings_service.php`、`crm_api.php`、`tests/company_brand_logo_contract.php`、本上下文。未触碰用户原有商务中心命令文件删除与未跟踪文档。
- 检查：办公室本地 `git diff --check` 与 `node --check assets/crm/crm.js` 通过；正式服务器 `includes/settings_service.php`、`crm_api.php` PHP 语法和 `company_brand_logo_contract.php` 均通过。
- 运行验证：服务器以真实 PHP-FPM 用户 `www` 创建了 `uploads/dispatch_next/company_brand/`，目录所有者为 `www:www` 且该用户可写；没有上传、替换或删除任何实际公司 LOGO。
- Git / 部署：功能提交 `62af481348fc57f42fa35c6859d0ef689b026837` 已推送 GitHub，并以 SHA-256 校验 Git bundle 在正式服务器仅快进部署。当前记录收尾提交后将再次同步并核对本地、GitHub 与服务器提交一致。

## 本次：修复 Office 邮件兼容阅读版显示 HTML 标签（已发布）

- 用户反馈 CRM 邮箱此前“正文空白”问题修复后，当前邮件正文改为直接显示大量 `</span>`、`<td>` 等 Office HTML 标签。
- 只读核对生产数据确认：邮件 HTML 正文实际保存正常；问题来自兼容阅读版错误直接显示 `body_text`，而该字段在部分 Office/Outlook 邮件中会在正常文字后混入完整 HTML 片段。
- 修复：兼容阅读版检测到混入 HTML 时，在浏览器隔离解析为纯可读文字，再显示；移除脚本、样式、嵌入对象等非正文节点，保留可读邮件内容。没有把原始 HTML 直接插入页面，也没有放开外部脚本。
- 修改文件：`assets/crm/crm.js`、新增 `tests/crm_mail_office_readable_contract.php`、本上下文。用户原有商务中心命令文件删除和未跟踪文档均未触碰。
- 检查：办公室本地 JavaScript 语法与空白差异检查通过；正式服务器 `crm.php`、`crm_mail.php` PHP 语法和 Office 邮件专项契约通过。
- Git / 部署：功能提交 `ecb12764a407af8782a25f17d75fb9e51982e4a8` 已推送 GitHub，并用 SHA-256 `f0b92b5fa249eeaf30399537d510865105470abd41368ca065aaa7c01073bfca` 的 Git bundle 在正式服务器只快进部署。本记录收尾同步后再核对三方提交一致。

## 本次：CRM 与派工统一公司 LOGO 上传（已发布）

- 用户要求将 CRM 与派工顶部当前固定的 AD 图标改为可自行上传的公司 LOGO，并且两端一致。
- 新增统一品牌上传：在 CRM 的“设置 → 企业信息 → 统一顶部 LOGO”选择 JPG、PNG、WebP 或 GIF（最大 2MB）后上传一次，同时写入 CRM 与派工共用的公司设置；两端下次打开即使用同一图片。未上传时继续显示默认 AD 图标。
- 上传使用服务端真实文件校验、随机文件名和仅限 `uploads/company_brand/` 的公开路径白名单；不接受任意路径或 SVG。支持“恢复默认图标”，只取消当前引用，不删除旧上传文件，避免误删。
- 修改文件：`includes/settings_service.php`、`crm_api.php`、`crm.php`、`dispatch_next.php`、`assets/crm/crm.js`、`assets/crm/crm.css`、新增 `tests/company_brand_logo_contract.php`、本上下文。未触碰用户原有商务中心命令文件删除和未跟踪文档。
- 检查：办公室本地 JavaScript 语法、空白差异检查通过（办公室电脑没有 PHP）；正式服务器 `crm.php`、`crm_api.php`、`dispatch_next.php`、`includes/settings_service.php` PHP 语法与 `company_brand_logo_contract.php` 均通过，服务器工作区检查无本次差异。
- Git / 部署：功能提交 `ff71c14b021a543b1f39808f3684fb7579825a2c` 已推送 GitHub；通过 SHA-256 为 `918f79374394c70479acfe02d60bdc552db62804796353431c339ceae8f228e6` 的 Git bundle 在正式服务器仅快进合并发布，未直接编辑服务器代码。本记录收尾提交后再同步三方并核对最终提交一致。

## 本次：重排推广项目首页，突出项目重点并收起明细（已发布）

- 用户反馈推广项目首页把任务卡、项目总览、9 步、目标客户、邮件/人工规则、时间队列和日志全部纵向完整铺开，页面过长且看不出重点。
- 调整方向：首页第一屏仅保留项目状态、渠道、计划、风险提示、四项核心数量、9 步进度，以及“目标范围 / 执行概况”两张紧凑重点卡；客户名单、执行规则、时间队列和日志改为默认收起、按需展开。
- 保留所有既有数据和入口；本次只改变信息优先级与版式，不改推广项目、队列、客户、分组和发送逻辑。
- 检查：办公室本地 JavaScript 语法和空白差异检查通过；正式服务器 `crm.php` / `crm_marketing.php` PHP 语法及推广项目首页专项契约通过。
- Git / 部署：功能与本记录均先推送 GitHub，再以 SHA-256 校验的 Git bundle 在正式服务器仅快进合并部署；不直接编辑服务器文件。收尾时核对办公室本地、GitHub `main` 和正式服务器 `HEAD` 为同一提交。

## 本次：收窄任务中心报价流程的跟进属性面板（已发布）

- 用户反馈任务中心“报价订单流程”右侧“任务详情 / ACTIONS”属性面板过宽，在大屏上占用了不必要的横向空间。
- 根因：布局原来按比例分配右栏（最小 360px、`.42fr`），显示器越宽面板越宽。
- 调整：改为 320–340px 的紧凑固定区间，把多出的空间交还给左侧报价流程卡；收起状态仍为 42px，1100px 以下仍自动切换为单列，不增加滚动条。
- 检查：办公室本地 `git diff --check` 与 JavaScript 语法检查通过；正式服务器 `crm.php` PHP 语法和任务中心稳定性专项契约通过。
- Git / 部署：功能与本记录提交均先推送 GitHub，再以 SHA-256 校验的 Git bundle 在正式服务器仅快进合并部署；没有直接编辑服务器文件。收尾时核对办公室本地、GitHub `main` 和正式服务器 `HEAD` 为同一提交。

## 本次：报价订单流程搜索、完整筛选与 ACTIONS 联动（已发布）

- 用户反馈任务中心切到“报价订单流程”后，搜索 `EX022` 没有结果、筛选不足、页面顶部占用过多，并且右侧 ACTIONS 仍停留在普通任务菜单。
- 根因：搜索参数此前只用于 `crm_tasks` 的普通任务查询，报价流程的 `quote_orders` 与商务中心 `cc_quotes` 读取没有接收搜索条件；因此报价号、订单号无法筛选报价流程。右侧菜单只按“是否选中任务”决定内容，未按当前 `quote` 视图联动。
- 修复：报价流程搜索现在同时查询报价编号、订单编号、客户、联系人、负责人和商务中心报价快照；`EX022` 会直接过滤到对应报价，不再只过滤普通任务。前端同时保留相同字段的本地匹配，确保当前已加载数据立即响应。
- 新增紧凑筛选：全部、未跟进、跟进中、客户未回复、客户已回复、待审核、已驳回、未转订单、已转订单、待收款、待出货、待单证、流程完成；流程节点筛选仍可与上述状态组合使用。
- 版式：保留每单原有的报价→审核→回复→订单→收款→出货→单证卡片格式不变；报价视图隐藏与该页面重复的大 KPI 块，改用流程内紧凑统计与筛选条，减少无效留白。
- ACTIONS：切到“报价订单流程”后右侧立即切换为“报价订单筛选 / 报价流程工具”；支持刷新与清除筛选。选中某条报价后才追加该报价的具体操作，不再显示无关的普通任务菜单。
- 修改文件：`crm_task_center.php`、`assets/crm/crm.js`、`assets/crm/crm.css`、`crm.php`、`tests/crm_task_center_stability_contract.php`、本上下文。用户已有的商务中心命令文件删除和未跟踪文档均保持原样，未纳入提交。
- 检查：本地 `node --check assets/crm/crm.js` 与 `git diff --check` 通过；本机未安装 PHP。GitHub Actions `Validate and deploy production #663` 已成功；正式服务器 `crm_task_center.php`、`crm.php` PHP 语法及 `crm_task_center_stability_contract.php` 均通过。
- Git / 部署：功能提交 `3d9dd0662c761b1c5577f20fe5e0da5792016ce5` 已推送并由 GitHub Actions #663 自动部署成功。交接记录提交 `75f634b0fefc4c52e1475be5e1e5e0077caff9ae` 的 Actions #664 代码检查成功，但“传输已验证 Git 包”步骤失败；不是代码或检查失败。已将同一已推送提交制作 Git bundle，校验 SHA-256 `4eaa87aa4f2ae7b4ae437d17527e1aae6aae67395773fd170e3fbbddb6203f8c` 后在正式服务器仅快进合并，未直接编辑任何服务器文件；PHP 与专项契约再次通过。服务器本地 `origin/main` 追踪标记因服务器没有 GitHub SSH 拉取凭据仍停留在 `3d9dd06`，但服务器实际 `HEAD`、办公室本地和 GitHub `main` 均为 `75f634b`。本条更正记录提交后将再次按相同方式同步三方。

## 本次：任务中心稳定性与报价流程加载修复及历史重复跟进清理（已发布）

- 用户反馈报价/客户跟进可重复保存，历史中已出现同内容同时间的多条记录；本次已阻止后续重复写入。
- 新增“请求令牌”去重：客户跟进、报价跟进与手工新建任务在同一用户同一令牌下只会创建一条；前端保留令牌以支持断网后的安全重试，服务端也以唯一索引和查询作为最终保护。
- 客户跟进、报价跟进、任务、样品快捷操作、延期和完成操作增加保存中锁定；报价跟进弹窗改为释放旧监听器，避免反复打开后点击事件累积。
- 报价流程将原先每条报价各自查询“最新跟进 + 跟进次数”的 N+1 查询改为按 legacy / cc 两个来源批量汇总；任务中心非样品页面不再额外下载样品列表。详情请求增加选择标识校验，慢请求不能覆盖用户已切换的另一条详情。
- 对尚未接通的报价/订单/收款/物流等动作，界面现在直接禁用并标注“待接入”，不再让用户点击后才提示接口未接入。
- 修改文件：`crm_task_center.php`、`crm_customer.php`、`assets/crm/crm.js`、新增 `tests/crm_task_center_stability_contract.php`、本上下文。用户原有商务中心命令文件删除及未跟踪文档保持不动，不纳入提交。
- 已按用户授权清理历史重复：正式库只读扫描确认报价 `AT-260630EX022-04` 存在 3 组完全相同的跟进，共 11 条；每组保留最后写入的一版 `ID 3、20、22`，软删除旧版本 `ID 1、2、15、16、17、18、19、21`。所有记录均无附件、无客户时间线；清理前完整行备份保存于服务器 `_codex_backups/quote_followup_duplicate_cleanup_20260728T1330.json`，SHA-256 为 `6a5b99dc7be1c02e277829bdbadc4a520a8dee9cbe9562f4636c9849ecf796f8`。清理后该 11 条中活动记录为 3、软删除为 8，全库完全相同活动跟进组为 0。
- 检查：本地 `node --check assets/crm/crm.js`、`git diff --check` 通过；服务器同一提交通过 `crm_task_center.php` / `crm_customer.php` PHP 语法、`crm_task_center_stability_contract.php`、报价跟进事务契约和报价跟进界面契约。Git / 部署：功能提交已推送 GitHub，GitHub Actions 第 661 次运行自动检查和发布成功（3 分 33 秒）；服务器、GitHub 与本地代码均为 `db5606e`。本条上下文记录提交后会由同一自动流程发布并复核三方一致。

更新时间：2026-07-28

## 本次：重构新版 CRM 推广中心第 9 步最终确认页

- 用户明确指定本次只处理新版 CRM 推广中心“创建推广任务向导”的第 9 步，不处理旧版 CRM 补丁；目标是消除页面多层滚动，把邮件、名单、质量风险和生成队列动作整理为专业的最终确认页。
- 第 9 步现改为折叠卡片：默认仅展开“邮件预览”和“执行名单预览”；任务摘要、质量检查、异常明细默认折叠。发现无邮箱、黑名单、未分配邮箱、未分配执行人、时间或变量问题时，质量检查自动展开并显示风险数量。顶部加入“折叠全部 / 展开全部”。
- 邮件预览在主页面只保留紧凑头部、主图缩略和前段正文，不再在卡片内形成长滚动；“展开大预览”在弹窗内查看完整邮件。执行名单主页面只显示前 8 条；“查看全部”打开带搜索与 25 条分页的完整名单弹窗。任务摘要、质量检查、异常明细均按需展开，异常可按类型展开到客户与处理建议。
- 右侧保留并整理为固定项目操作、简版任务摘要和质量快照；操作仍是“保存草稿 / 保存为计划 / 生成执行队列”，没有移动或删除既有业务入口。桌面端右侧不再独立滚动；手机端第 9 步改为底部固定三按钮操作条。
- 生成队列前新增前端最终校验并定位质量卡片：无可执行目标、邮件内容为空、可用发件邮箱为 0、所有邮件客户无邮箱、未分配邮箱、未分配执行人、缺少计划时间、未识别变量都会阻止生成；只有部分客户无邮箱时会按当前“跳过 / 转线下”策略二次确认。服务端 `crm_marketing_queue_build()` 同步执行同类最终校验，避免绕过前端直接生成不一致的队列。
- 预览 / 正式发送一致性：服务端模板渲染补全发件人职位、手机及兼容变量，且与第 9 步预览使用同一含义；避免预览正确但实际入队邮件变量未替换。
- 修改文件：`assets/crm/crm.js`、`assets/crm/crm.css`、`crm.php`、`crm_marketing.php`、`tests/crm_marketing_wizard_mail_preview_contract.php`、本上下文。没有修改推广客户、分组、任务、发送队列或数据库数据；用户原有商务中心删除和未跟踪文档保持原样且未纳入提交。
- 检查：办公室本地 `node --check assets/crm/crm.js`、`node tests/crm_marketing_mail_preview_runtime_test.js`、`git diff --check` 通过。办公室电脑没有 PHP；服务器同一提交已通过 `crm.php` / `crm_marketing.php` PHP 8.0 语法、推广邮件预览专项契约和完整 1–9 步流程契约。首轮部署发现旧流程契约仍期望“可执行客户”按旧客户数组计算，已在本地修正为统一执行名单 `plan.items` 口径并重新部署；不是业务功能失败。
- Git / 部署：功能提交 `e0913e9` 与契约修正提交 `d61071e` 已推送 GitHub。服务器因运行账号没有 GitHub 拉取凭据，自动部署未及时接管；已使用与工作流一致的已推送提交 Git bundle、SHA-256 校验和仅快进合并部署，未在服务器直接改代码。部署后服务器 HEAD / `origin/main` 均为 `d61071e07332efc0179d8adc544de954c05dd7eb`，工作区干净。本条最终记录将作为最后收尾提交再同步三方。

## 本次：以腾讯云正式服务器为准恢复办公室本地版本

- 用户明确指定腾讯云正式目录 `/www/wwwroot/Artdon/artdon_erp/` 为最新版本，要求先核对服务器，再确认 GitHub，最后同步办公室本地；本轮暂停新的功能修改。
- 只读核对确认服务器工作区干净，服务器 `HEAD / origin/main` 与 GitHub `main` 均为 `d577fe60e65b847573a60b465d8cb3886aa75db7`；办公室本地仍停在旧提交 `3fd9f677292b7942f96ca4d55a96786ba7f21b04`。因此版本问题是办公室电脑未同步，并非服务器或 GitHub 落后。
- 同步前已备份办公室本地：`/Users/qiulei-office/Documents/Codex/_sync_backups/artdon_erp_office_20260728_071001/`，包含完整旧 Git bundle 和当前 `commercial_center_v1` 工作副本；bundle SHA-256 为 `370f01caa3bcce9c9610ae8621abba52e14b31668242f693347aa3764faf92e8`。
- 服务器备份位于 `_codex_backups/server_sync_20260728_071001/`：当前 Git bundle、`d577fe60e` 跟踪代码归档及完整数据库 SQL 压缩包。数据库使用一致性事务和 `--no-tablespaces` 重新完整导出，`gzip -t` 与 `-- Dump completed on 2026-07-28 7:12:03` 结束标记通过，数据库备份 SHA-256 为 `45ecbe21188e14001a91e7272cab48a368c35e28da651887e38c1488acb3dccf`。
- 办公室本地从 GitHub `main` 做纯快进同步 `3fd9f677 → d577fe60e`；服务器/GitHub带来的 7 个文件与本地未提交内容不重叠。本地原有商务中心命令文件删除及 9 个未跟踪文件全部保持原样，未提交、未删除、未覆盖。
- 回归：办公室本地 `assets/crm/crm.js` Node 语法、推广邮件预览运行时行为测试和 `git diff --check` 通过；服务器 `crm.php`、`crm_marketing.php` PHP 8.0 语法、推广邮件预览专项契约和 `git diff --check` 通过。服务器没有 Node，因此运行时行为测试由同一代码的办公室本地执行。5 个关键文件本地/服务器 SHA-256 逐项一致；线上 CRM 入口 66ms 返回统一登录 302，路由正常。
- 本轮除本上下文记录外没有修改功能代码、数据库或业务数据。该记录提交后按固定流程推送 GitHub 并等待自动部署，再核对办公室本地、GitHub、服务器最终提交一致。

## 本次：修复综合渠道旧任务被误判为“非邮件渠道”

- 用户在正式页面确认第 5 步测试邮件和第 9 步邮件预览已经显示，但两处都提示“当前任务不是邮件渠道”，导致已有邮件内容仍无法预览或测试发送。
- 生产数据库只读聚合核对确认根因：当前 2 个 `channel_key=whatsapp / campaign_type=whatsapp` 的推广任务都保存了邮件主题或正文；前端 `buildWizardMailPreviewItems()` 只按渠道枚举判定，完全忽略任务实际是否已有邮件内容，因此两个入口共用的预览清单为空。
- 本地修复：邮件预览和测试发送判定新增“已有邮件主题或正文”依据；综合/历史任务即使执行渠道为 WhatsApp，只要保留邮件内容，就允许按非群组人员生成逐封个性化预览；没有目标时使用示例人员。测试发送仍只投递到页面明确填写的测试邮箱，不生成或触发正式推广队列。真正没有邮件内容的非邮件任务仍保持禁用。
- 回归：新增可在 JavaScriptCore 和 Node 双运行时执行的行为测试，实际覆盖“WhatsApp + 邮件内容 + 人员”“WhatsApp + 邮件内容 + 无人员”“WhatsApp 无邮件内容”和普通邮件任务四种场景，不再只检查源码标记；邮件专项 PHP 契约同步约束内容回退和新缓存版本。
- 缓存与识别：`crm.php` 资源构建号更新为 `promotion-mail-detection-20260728-1`；第 9 步显示“邮件判定修复版 20260728-1”，便于用户确认浏览器已加载本次版本。
- 本地/隔离检查：本机 JavaScriptCore 语法、邮件预览运行时行为测试和 `git diff --check` 通过；服务器 `/tmp/artdon_promo_mail_20260728_bgosFE` 隔离候选副本 PHP 8.0 检查 379 个 PHP 文件语法及 31 项无数据库契约全部通过，邮件预览专项和 1–9 步流程专项均通过。服务器没有 Node，未在正式目录执行候选代码，也没有发送真实测试邮件。
- 修改文件：`assets/crm/crm.js`、`crm.php`、`tests/crm_marketing_wizard_mail_preview_contract.php`、新增 `tests/crm_marketing_mail_preview_runtime_test.js`、`tools/ci_js_checks.sh`、`WORK_CONTEXT.md`。用户原有商务中心命令文件删除和未跟踪文档保持原样，不纳入本次提交。
- Git / 部署：功能提交 `f24586ba5b0da45c7b058a6b54638ed4aa63a2b6` 已推送 GitHub，并由 `Validate and deploy production` 在检查通过后自动同步到 `/www/wwwroot/Artdon/artdon_erp/`；正式服务器再次通过 379 个 PHP 文件语法及 31 项无数据库契约。线上公开脚本已实际返回 `draftHasMailContent` 回退逻辑和“邮件判定修复版 20260728-1”，CRM 入口正常返回登录跳转。功能与本条最终状态记录均已按固定流程提交、推送和部署，收尾时本地 HEAD、GitHub `origin/main`、服务器 HEAD / `origin/main` 一致且服务器工作区干净。未发送真实测试邮件，用户登录后的第 5 / 9 步最终点击结果待反馈。

## 本次：第 9 步邮件预览改为第一屏强制可见

- 用户在正式页面复核后确认上一轮虽已同步成功，但编辑推广任务进入第 9 步仍看不到邮件预览和“发送测试邮件”；上一轮仅凭源码标记、契约和未登录路由即宣告恢复，缺少登录后真实界面验收，该结论已被用户实际结果否定。
- 入口核对：用户明确使用 `/www/wwwroot/Artdon/artdon_erp/crm.php` 对应的新 9 步向导，不是服务器根目录遗留的 6 步旧 `crm.php`；本次只修改 GitHub 管理的 `artdon_erp` 运行目录，不直接修改服务器旧文件。
- 进一步原因：第 9 步虽然已有预览渲染代码，但预览仍排在任务汇总和质量检查之后，且动态刷新会先插入另一组统计卡，再插入邮件内容；在当前全屏固定高度和多层旧样式叠加下，邮件预览与测试按钮没有被保证出现在第一屏。浏览器已经打开的旧 JavaScript 也不会因等待自动更新，必须重新加载页面；此前没有独立缓存构建号提示，用户无法判断当前加载版本。
- 修复：新增第 9 步置顶的“邮件预览与测试发送”强提醒区，DOM 顺序、动态刷新顺序和 CSS `order` 三层共同保证它位于汇总、质量检查、重复邮箱和执行名单之前；逐封上一封 / 下一封、主题、正文、测试收件人及“发送测试邮件”直接出现在第一屏。
- 容错：初始邮件轮播单独使用异常保护；即使预览计算失败，也会显示“邮件预览加载异常”而不是静默空白。页面新增可见版本标识“预览修复版 20260727-2”，`crm.php` 同时更新资源构建号为 `promotion-preview-20260727-2`，强制新页面请求新的 JavaScript / CSS URL。
- 回归约束：邮件专项契约现在检查预览区必须先于汇总和质量检查、动态刷新必须先输出邮件轮播、异常不得静默、置顶样式和显式缓存构建号必须存在；原项目操作右侧固定按钮和 1–9 步流程约束继续保留。
- 检查：本机 JavaScriptCore 语法和 `git diff --check` 通过；服务器 `/tmp/artdon_promo_preview_visible_20260727` 隔离候选副本使用 PHP 8.0 检查 `crm.php` / 专项契约语法、邮件预览契约和 1–9 步流程契约通过，全量 379 个 PHP 文件语法及 31 项无数据库契约全部通过。
- 视觉验收限制：本轮主动连接应用内浏览器时没有任何可用浏览器会话，无法访问用户登录态；没有再把源码和未登录 302 当作视觉通过。已请用户强制刷新并重新打开第 9 步，核对第一屏蓝框“邮件预览与测试发送”和版本标识；当前等待用户实际画面确认，若仍不显示，需要用户提供当时截图以按真实 DOM 继续定位。
- 修改文件：`assets/crm/crm.js`、`assets/crm/crm.css`、`crm.php`、`tests/crm_marketing_wizard_mail_preview_contract.php`、`WORK_CONTEXT.md`。没有发送真实测试邮件，没有修改推广任务、发送队列、客户数据或数据库结构；用户原有商务中心命令文件删除和未跟踪文档保持原样。
- Git / 部署：修复提交 `5375f1bf65732a5b6bb411732bdbcb753e8f33ac` 已推送 GitHub；GitHub Actions `Validate and deploy production #632` 的 PHP / JavaScript 检查及正式部署成功。正式服务器 `crm.php` PHP 8.0 语法、邮件预览专项契约和 1–9 步流程契约通过，服务器 HEAD / `origin/main` 与本地、GitHub 同一提交；线上公开 JavaScript / CSS 已实际返回版本标识、置顶结构和蓝框样式。本条最终部署状态记录将作为收尾提交再次按固定流程同步三方，用户登录后视觉确认仍待反馈。

## 本次：恢复推广任务第 9 步邮件预览与测试发信

- 用户复核发现编辑推广任务进入第 9 步后，原有的逐封邮件预览和“发送测试邮件”区域完全不显示，要求恢复可预览、可测试发信的版本并查明原因。
- 根因确认：第 9 步的服务端模板实际只输出空的 `data-promo-wizard-preview` 容器，邮件内容完全依赖弹窗渲染结束后的第二次 JavaScript 动态刷新；只要刷新链路因当前草稿数据、渠道判断或前端异常未完成，初始页面就会保持空白。原专项契约只检查预览构造器和测试发信代码是否存在，没有约束第 9 步初始 HTML 必须直接包含预览，因此此前按钮位置调整虽未删除预览函数，却没有发现这一脆弱链路。
- 修复：第 9 步初次渲染即直接输出逐封邮件预览、上一封 / 下一封和“发送测试邮件”，不再先显示空容器；初始控件先完成事件绑定，再执行动态刷新。动态刷新即使异常，也会保留已显示的邮件内容和测试发信操作，并给出提示，不再把整个区域变成空白。
- 补充兼容：自动偏好、客户偏好和混合渠道在尚未形成正式邮件队列时，也会生成示例邮件预览，便于先检查主题、正文和变量；测试发信仍使用当前预览人员变量，并只发送到页面中填写的测试邮箱。
- 右侧“项目操作”中的保存草稿、保存为计划、生成执行队列保持上一版固定显示；本次没有恢复重复的底部按钮，也没有改动推广任务、发送队列、客户数据或数据库结构。
- 回归契约新增约束：禁止第 9 步恢复为空预览占位；必须在初始渲染中直接输出邮件轮播，必须先绑定初始预览控件，并在动态刷新失败时保留内容；偏好和混合渠道必须具备预览回退。
- 检查：本机 JavaScriptCore 解析和 `git diff --check` 通过；服务器 `/tmp` 隔离候选副本及正式生产目录均使用 PHP 8.0 检查 379 个 PHP 文件语法、31 项无数据库契约全部通过，其中邮件预览专项契约和完整 1–9 步流程契约均通过。CRM 公开入口正常返回 302 登录跳转；没有使用真实邮箱发送测试邮件。
- 修改文件：`assets/crm/crm.js`、`tests/crm_marketing_wizard_mail_preview_contract.php`、`WORK_CONTEXT.md`；用户原有商务中心命令文件删除和未跟踪文档保持原样，不纳入本次提交。
- Git / 部署：功能与首版记录提交 `836ba7a71ae0be1cc4e3c73981726614af56e096` 已推送 GitHub；GitHub Actions `Validate and deploy production #630` 的 PHP / JavaScript 检查及正式部署成功，服务器以同一 Git 对象运行且工作区干净。该提交部署后，本地 HEAD、GitHub `origin/main` 与服务器 HEAD / `origin/main` 已核对一致；本条最终状态记录将作为收尾提交再次按固定流程同步三方。

## 本次：推广项目三项操作移至右侧固定显示

- 用户反馈编辑推广项目时“保存草稿、保存为计划、生成执行队列”在第 9 步也没有显示，并指出右侧“快捷检查”与现有步骤导航重复、作用有限。
- 根因与现状：三项真实操作此前依赖底部操作栏和第 9 步条件渲染；编辑项目固定从第 1 步打开，导致第 1–8 步只有“保存草稿 / 下一步”，底部栏在用户当前窗口布局下第 9 步仍不可见。右侧五项快捷检查本质上都只跳转到第 9 步。
- 调整：右侧原“快捷检查”完整替换为不可折叠、始终显示在最上方的“项目操作”，固定提供“保存草稿、保存为计划、生成执行队列”；第 1–9 步、新建和编辑均使用同一组按钮。保存为计划和生成执行队列仍执行既有 1–9 步完整校验，缺失内容会跳转到对应步骤，不绕过业务规则。
- 底部操作栏只保留取消、上一步和第 1–8 步的下一步；第 9 步不再依赖底部保存/执行按钮，也不保留第二套重复操作。右侧生成执行队列使用蓝色主按钮，项目操作区固定展开并与任务摘要、质量检查区分。
- 回归契约新增约束：三项动作必须位于 `renderWizardSidebar()` 的项目操作区、不得残留在 footer；禁止恢复 `data-promo-aside-check` 或“快捷检查”；原邮件逐人预览和 1–9 步流程约束继续保留。
- 检查：本机 JavaScriptCore 解析和 `git diff --check` 通过；本机无 Node/PHP CLI，服务器 `/tmp` 完整隔离候选副本使用 PHP 8.0 检查 379 个 PHP 文件语法和 31 项无数据库合同全部通过，其中更新后的按钮位置专项契约及原 1–9 步流程契约均通过。当前没有可连接的 ERP 登录态浏览器，未伪报登录后视觉点击。
- 修改文件：`assets/crm/crm.js`、`assets/crm/crm.css`、`tests/crm_marketing_wizard_mail_preview_contract.php`、`WORK_CONTEXT.md`。没有修改推广项目、发送队列、数据库结构或任何生产业务数据；用户误输入的“017”已明确忽略，没有查询或操作对应数据。
- Git / 部署：本上下文将与功能修改一起提交并推送 GitHub，通过自动检查部署同一提交后再核对本地、GitHub 与服务器三方一致。

## 本次：GitHub main 同步到家用电脑本地

- 按用户要求从 GitHub `origin/main` 同步当前家用电脑工作目录；开始前已读取本上下文并核对分支、远端和本地状态。
- 本地 `main` 原为 `6fac85e`，GitHub `origin/main` 为 `3fd9f677292b7942f96ca4d55a96786ba7f21b04`，本地单向落后 38 个提交、没有本地未推送提交；已使用 `git merge --ff-only origin/main` 纯快进同步，没有重置、强制覆盖或产生合并提交。
- 同步前后均保留用户已有的商务中心命令文件删除、未跟踪商务中心文档和命令文件；远端新增的 `.gitignore` 已忽略本地 PEM 文件，本次未读取、修改或纳入该敏感文件。
- 同步检查：本地 HEAD 与 GitHub `origin/main` 均为 `3fd9f677292b7942f96ca4d55a96786ba7f21b04`；正式服务器 HEAD 与服务器 `origin/main` 也为同一提交，服务器工作区干净。
- 本次没有修改业务代码、数据库或生产业务数据；仅按强制交接规则更新 `WORK_CONTEXT.md`。本上下文提交将单独推送 GitHub，并通过仓库自动测试部署流程同步服务器后再次核对三方一致。
- 尚未完成事项：无；用户可在当前家用电脑直接基于最新 `main` 继续工作。

## 本次：推广项目、客户推广池和客户分组移除重复 5MB 初始化数据

- 用户确认推广项目、客户推广池和客户分组三页每次打开都会重复下载约 5.2MB，要求修复。
- 生产只读字段拆分确认并非客户量、数据库或服务器负载问题：旧初始化会在所有推广子页同时返回最近 80 个推广任务的完整邮件正文、全部发件箱的完整个人签名和公司统一签名；当前数据分别约 4.11MB、1.91MB、1.25MB，且正文和签名中的内嵌图片会进一步降低压缩收益。
- 推广任务列表改为轻量摘要，只返回列表、状态、统计、规则 JSON、是否有正文及正文字节数，不再返回 `mail_body_html`；新增单任务详情读取，只有预览、编辑或复制后打开某一个任务时才读取该任务完整正文。
- 发件箱和公司签名初始化改为轻量元数据，只返回是否已设置签名及字节数；只有用户在邮件编辑器点击“插入签名”时才读取当前公司或个人签名，并在本次页面会话缓存。公司签名为空时仍保留回退当前个人签名的原行为。
- 任务预览、编辑、复制流程已接入按需详情；任务属性中的第 5 步完成状态改用 `has_mail_body`，无需正文即可正确显示。重命名任务只提交名称，服务端部分更新在缺少主题/正文/计划时间/备注字段时保留原值，避免轻量列表导致正文被意外清空。
- 新增无数据库契约 `crm_marketing_lazy_payload_contract.php` 并纳入 PHP CI；新增生产只读体积回归 `crm_marketing_bootstrap_payload_readonly_integration.php`，约束三页初始化均不泄漏完整正文或签名且每页原始响应不超过 512KB。
- 本机 41 个 JavaScript 文件语法、3 个 JavaScript 契约及 `git diff --check` 通过；服务器隔离候选目录 379 个 PHP 文件语法、31 个无数据库契约全部通过。
- 隔离候选连接生产数据库只读实测：推广项目 18,240B（gzip 3,969B，56ms）、客户推广池 60,359B（gzip 8,816B，90ms）、客户分组 46,235B（gzip 7,174B，10ms）。单个任务完整详情和签名仍可按需读取；测试未修改推广任务、客户、分组、发送队列或邮件数据。
- 修改范围：`crm_marketing.php`、`crm_api.php`、`assets/crm/crm.js`、PHP CI、两份新增测试及本上下文；用户原有商务中心命令文件删除及未跟踪文档保持原样。
- Git / 部署：功能提交 `17eca8ec7fee8a137076feb4d960c15758fac751` 已推送 `origin/main`；GitHub Actions `Validate and deploy production #626` 的 PHP / JavaScript 检查及正式部署成功，腾讯云正式目录已运行同一提交。
- 服务器正式复检：`crm_marketing.php`、`crm_api.php` PHP 8.0 语法，轻量响应专项契约、原 1–9 步流程契约和邮件预览契约全部通过；生产只读体积再次确认为推广项目 18,240B / gzip 3,969B / 48ms，客户推广池 60,359B / gzip 8,816B / 70ms，客户分组 46,235B / gzip 7,174B / 7ms。正式目录工作区干净，HEAD 与服务器 `origin/main` 一致。
- 线上入口仍按统一登录规则返回登录页，证明路由正常；当前自动化浏览器没有 ERP 登录态，因此没有伪报登录后点击性能。用户强制刷新后即可在浏览器网络面板或直接体感复核三页；本上下文收尾提交后会再次推送、部署并核对本地、GitHub、服务器三方一致。

## 本次：修复推广测试发信、逐人邮件预览和第 9 步操作栏

- 用户反馈推广向导“发送测试邮件”提示没有队列，第 9 步无法预览邮件或按人员切换下一封，并且保存草稿、执行、保存为计划等按钮被遮挡。
- 根因确认：前端把测试发信和邮件预览错误绑定到 `buildExecutionPlan().mailItems` 正式队列；当草稿还未选择或匹配正式发件箱时，已有客户/联系人也会被当成“没有可测试队列”。服务端 `crm_marketing_test_send()` 本身不依赖正式队列，会直接使用指定邮箱或当前账号可用邮箱。
- 新增草稿邮件预览清单：从当前已解析人员直接生成逐人预览，并尽量附加当前发件邮箱规则；即使尚未生成正式队列或还没有人员，也可用示例称呼检查主题、正文和变量。没有客户邮箱的人员明确标记“仅预览”，不会伪装成可正式发送。
- 第 9 步改为“上一封 / 下一封”循环预览，显示第几封与总数，可连续按人员检查称呼、变量、客户邮箱、发件箱和执行人；测试发送使用当前这一封的人员变量，但仍只投递到填写的测试邮箱。
- 第 9 步移除内容区内重复的第二套操作按钮；保存草稿、保存为计划、生成执行队列统一进入固定底部操作栏。内容滚动区增加底部留白，操作栏增加独立背景、层级和窄屏三按钮布局，避免按钮被遮挡或只显示一半。
- 新增无数据库契约 `crm_marketing_wizard_mail_preview_contract.php` 并纳入 PHP CI，约束测试发信不得依赖正式队列、逐人循环预览和单一底部操作栏。原 1–9 步流程契约继续通过。
- 检查：本机 41 个 JavaScript 文件语法、3 个前端合同及 `git diff --check` 通过；服务器只读归档隔离应用候选补丁后，377 个 PHP 文件语法、30 个无数据库合同全部通过。第一次专项契约因测试标记写得过严失败，仅调整测试断言后重跑成功；第一次全量检查因只读归档没有 Git 元数据被 CI 防误报守卫拒绝，在隔离目录初始化临时索引后完整通过，均未修改生产代码或数据。
- 修改范围：`assets/crm/crm.js`、`assets/crm/crm.css`、新增专项契约、PHP CI 清单和本上下文；用户原有商务中心命令文件删除及未跟踪文档保持原样。
- Git / 部署：功能提交 `f985e58ca914787d811cd742d840f26f2cbc8caa` 已推送 `origin/main`；GitHub Actions `Validate and deploy production #624` 的检查和正式部署均成功，腾讯云正式目录已快进到同一 Git 对象。
- 线上回归：生产专项契约 PHP 8.0 语法、邮件预览专项合同和原 1–9 步流程合同均通过；正式脚本已包含草稿预览构造器、最终“保存为计划”按钮和滚动区留白规则。CRM 入口未登录按统一规则返回 302 登录页，未使用真实邮箱发送测试邮件，也未写推广任务、发送队列或客户数据。功能部署后的本地、`origin/main`、服务器 HEAD 均为 `f985e58ca914787d811cd742d840f26f2cbc8caa`。

## 本次：修复 94.10012 报价误拉内部螺丝，并补齐人工修正入口

- 用户反馈报价系统产品 `94.10012` 的 Specification 错误出现 `Accessories: M3*6*7（螺纹直径3*螺杆长度6mm）...`，并要求修复“手动修正关键件”按钮，让业务人员遇到问题时可以自行修正。
- 生产只读核对确认：精确整灯 BOM 为 `bom_projects.id=1011`，共 30 个明细节点；其中第 20、22、23 项分别为 M3×6×7、M4×8、M3×5 装配螺丝。旧分类器把“螺丝 / 螺钉”当作 Accessories，并取第一项写入 `bom_quote_specs.id=132`。该记录于 2026-07-27 19:18 自动生成，光学为 `HERCULUX Dark Series 55@25`，Accessories 即上述 M3×6×7。
- 同时确认“手动修正关键件”按钮固定打开 `bom_quote_spec.php`，但本地、GitHub 基线和生产目录均不存在该文件，线上按钮必然无法使用。
- 报价关键件分类新增内部紧固件排除规则：螺丝、螺钉、螺栓、螺母、机牙、自攻、垫圈及对应英文紧固件不再进入客户可见 Accessories；读取既有旧记录时也会过滤该类误值。分类器版本升级为 v3，后续重新从 BOM 提取使用新规则。
- 新增真正可用的“人工修正报价关键元器件”页面：按当前型号精确读取单条记录，可分别维护 LED、电源、光学、接头、客户可选附件、其他、功率、尺寸、开孔和说明；支持逐项清空、重新从 BOM 提取、保存、保存并关闭。保存后记录转为人工维护并冻结，普通自动检查不会再次覆盖。
- 修正页面保存后通过同源窗口消息即时更新原报价页当前产品、产品库缓存和已加入的报价行，无需刷新整张报价单；修正窗口复用同一个页面，避免反复打开多个标签。
- 新增 `get_bom_quote_spec` 精确读取接口及操作日志；新增无数据库契约 `quote_manual_component_repair_contract.php` 并纳入 PHP CI。
- 检查：本机报价页 4 段内联 JavaScript、新修正页 JavaScript 语法及 `git diff --check` 通过；服务器隔离归档中 `quote_api.php`、`quotation.php`、`bom_quote_spec.php` PHP 8.0 语法和新增专项契约通过。第一次隔离检查因服务器 Git 安全目录保护失败，未修改业务代码或数据；改用只读归档后检查成功。
- 修改范围：`quote_api.php`、`quotation.php`、新增 `bom_quote_spec.php`、新增专项契约、PHP CI 清单和本上下文；用户原有商务中心命令文件删除及未跟踪文档保持原样，未纳入本轮。
- Git / 部署：业务提交 `4f1b7129876e934e09d7092cde969dabe665b060` 已推送 `origin/main`；GitHub Actions `Validate and deploy production #622` 的 PHP / JavaScript 检查及生产部署均成功，服务器以同一 Git 对象运行。
- 生产旧记录清理：已在事务中精确修正 `bom_quote_specs.id=132 / 94.10012`，仅清空错误 Accessories 并从 `quote_spec_json` 移除该项，保留光学 `HERCULUX Dark Series 55@25`；记录已设为人工维护 `auto_generated=0`。修改前完整备份位于服务器 `_codex_backups/bom_quote_specs_94.10012_id132_before_fastener_cleanup_20260727T1940.json`。
- 线上回归：生产 `quote_api.php`、`quotation.php`、`bom_quote_spec.php` PHP 8.0 语法及专项契约全部通过；新维护页返回统一登录 302 而非 404；修正后数据库 Accessories 为空、报价规格 JSON 仅保留 Optic。业务提交部署后的本地、`origin/main`、服务器 HEAD 均为 `4f1b7129876e934e09d7092cde969dabe665b060`。

## 本次：修复 CRM 推广向导分组人数为 0，并完成 1–9 步闭环校验

- 用户反馈新建推广任务第 2 步已经选择“印度（客户 188）”分组，但选中客户数、任务摘要及后续步骤仍全部显示 0，要求重新扫描第 1–9 步。
- 根因确认：推广任务首屏为提速不再预载客户池数据后，向导切换分组只更新 `group_key`，没有按分组重新读取客户；目标预览接口也只接受显式客户编号，因此第 2 步到第 9 步始终基于空的本地数组计算。分组下拉中的联系人数量还只统计“直接加入分组的联系人”，漏掉“分组客户名下的联系人”。
- 修复服务端统一客户范围解析器，支持“当前勾选、当前筛选结果、指定分组、按国家”四种来源，并在预览和最终保存时重新按服务器数据解析，不能再依赖前端传回的旧列表。单任务设 5000 个客户安全上限，超过时要求继续缩小筛选。
- 第 2 步切换来源、分组或国家后会即时读取并联动客户列表、配置摘要、联系人、渠道计划、风险统计和第 9 步确认；显示客户总数与可执行客户数，加载中和失败都有明确提示。分组下拉同时显示客户、联系人和可推广联系人。
- 服务端及前端共同执行黑名单 / 禁止推广策略；“阻止任务”不再只是展示文字。定时或自动任务必须有开始时间，新建任务可以正确保存为 `scheduled`；第 9 步保存或生成队列前会从第 1 步到第 9 步逐项复核并跳回错误步骤。只选择联系人的旧用法继续允许。
- 推广任务主记录、目标明细、数量回写和操作日志改为同一原子事务；先完成日志表结构守卫再开启事务，避免首次日志初始化触发 MySQL 隐式提交和 `There is no active transaction`。异常时整批回滚，不留下半个任务。
- 生产只读回归：印度分组解析为 188 个客户、193 个联系人、162 个可推广联系人，分组摘要和完整目标预览一致，耗时约 81–85 毫秒。事务回归实际生成 188 条目标后完整回滚，测试任务残留为 0。
- 回归过程中首次事务测试暴露日志初始化问题并留下 1 条带专用 `__CODEX_WIZARD_GROUP_TX__` 前缀的测试草稿；已按精确任务、目标和新日志编号清理，复核专用测试任务剩余 0，未删除既有同编号历史日志或任何业务任务。
- 新增无数据库 1–9 流程契约、生产只读分组回归和可回滚事务回归；本机 41 个 JavaScript 文件语法及 3 个前端契约通过，服务器隔离目录 371 个、正式目录 374 个 PHP 文件语法及 28 个无数据库契约通过，三份新增专项测试均通过，`git diff --check` 通过。
- 修改范围：`crm_marketing.php`、`assets/crm/crm.js`、PHP CI 清单、三份新增测试及本上下文。用户原有商务中心命令文件删除和未跟踪文档保持原样，未纳入本轮。
- Git / 部署：功能提交 `e2184c555226cabb8074436c268fb60ff6b2f7f1` 已推送 GitHub，并以同一 Git 对象快进同步腾讯云正式目录；服务器正式复检后印度分组读取耗时 58 毫秒，事务回归完整回滚且专用测试任务残留为 0。
- 线上复核：公开 CRM 脚本、本地文件和服务器文件 SHA-256 均为 `4d85a9d3809c7820e68b3d4fd26acfcb4d18e04b2b864f68329f3553e2440f08`；未登录 CRM 入口按统一规则 302 到登录页。当前 Codex 浏览器会话没有 ERP 登录态，因此没有伪报登录后的视觉点击通过；用户强制刷新后可直接复核第 2 步分组人数和第 1–9 步联动。
- 本上下文收尾记录已按固定流程推送并同步腾讯云；本地、GitHub `main` 与服务器正式目录最终 HEAD 已核对一致。

## 本次：修复 CRM 客户推广池分组首屏一直加载

- 用户反馈客户推广池优化后体感更慢，推广客户分组一直停留在“正在加载推广分组...”。
- 根因确认：首屏接口已在约 151–174 毫秒返回客户与分组数据，客户列表正常显示；但首屏总渲染流程只渲染客户列表和分页，漏掉 `renderGroups()`，而后续加载判断又因 `loaded_view=customer_pool` 不再发起第二次请求，导致分组占位文字永久保留。
- 修复：客户推广池首屏统一通过安全渲染调用输出分组；移除分组面板错误的初始 `hidden` 属性。没有新增网络请求、数据库查询或业务写入，不会抵消上一轮按页加载优化。
- 回归：分页契约新增“首屏必须渲染分组”和“分组面板不得初始隐藏”检查；本机 JavaScript 语法和差异检查通过，服务器隔离临时目录及正式目录 PHP 语法、分页契约均通过；生产数据库只读回归仍严格返回本页 50 条，耗时 44 毫秒。
- 修改范围：`assets/crm/crm.js`、`crm.php`、`tests/crm_marketing_pool_pagination_contract.php` 及本上下文。用户原有商务中心命令文件删除及未跟踪文档保持原样，未纳入本轮。
- 浏览器验收：线上 CRM 地址正常响应并按统一规则跳转登录页；当前自动化浏览器没有 ERP 登录态，因此未伪报登录后的视觉点击通过。用户强制刷新后即可直接复核客户推广池分组。
- Git / 部署：功能提交 `c6b361b1a58f44b8d6b05da1b128cebea372c30c` 已推送 GitHub，并以同一 Git 对象快进腾讯云正式目录；本上下文收尾提交后再次按固定流程推送、同步并核对三方一致。

## 本次：CRM 客户推广池全选与按页快速加载

- 用户反馈 CRM 客户推广池筛选后没有全选，并且刷新列表耗时很长；要求当前页显示多少条就只读取多少条。
- 客户列表表头新增“全选本页”复选框：可一次选择或取消当前筛选结果本页的全部客户；部分选择时显示半选状态，翻页仍保留已勾选客户，重新筛选或搜索时清空旧筛选选择，避免隐藏客户混入新批量操作。
- 客户推广池刷新改为只调用客户分页接口，不再重新初始化整个推广中心；点击客户行只更新当前客户和操作区，不再重复请求列表。
- 服务端客户池接口不再附带读取独立“联系人策略”列表，移除每次最多 500 个联系人的无效预加载；联系人仍只在“联系人策略”页通过独立接口读取，业务功能保持分离。
- 客户池所有路径改为轻量分页：不执行精确总数扫描，主查询严格只生成当前页 `50 / 100 / 200` 条完整客户记录；是否还有下一页使用单独的轻量存在性检查，不再多生成一条完整客户记录。分页栏相应显示“按页加载、第 N 页、本页 N 条、是否还有下一页”，避免把近似数量伪装成精确总数。
- 新增无数据库契约 `crm_marketing_pool_pagination_contract.php` 并纳入 PHP CI；新增生产数据库只读回归 `crm_marketing_pool_readonly_integration.php`，验证每页 50 条只返回 50 条、不返回联系人列表、不执行精确总数统计。
- 检查：本机 41 个 JavaScript 文件语法及 3 个前端契约通过，`git diff --check` 通过；服务器隔离目录使用生产 PHP 8.0 检查 369 个 PHP 文件语法和 27 个无数据库契约全部通过。生产数据库只读回归连续三次返回 50 条，耗时分别为 44、43、46 毫秒，未写入客户、联系人或推广业务数据。
- 功能提交 `0afc5d9394b9a893a5d9dab372d1c0f5a5d01088` 已推送 GitHub；GitHub Actions 第 615 次运行的 PHP / JavaScript 检查和自动部署均成功，同一提交已快进到腾讯云。部署后服务器 PHP 语法、分页契约、生产数据库只读回归和差异检查再次通过，50 条线上读取耗时 45 毫秒；线上 JavaScript / CSS 已包含“全选本页”，未登录 CRM 入口仍按统一登录规则返回 302。
- 修改范围：`crm_marketing.php`、CRM JavaScript / CSS、两份新增测试、PHP CI 清单和本上下文。用户原有商务中心命令文件删除及未跟踪文档保持原样，未纳入本轮。
- 本上下文收尾提交后再次等待自动部署，并核对本地、GitHub 与服务器三方最终一致；业务功能无待实现项，用户刷新 CRM 后可直接复核筛选结果和“全选本页”。

## 本次：CRM 新建客户补齐全球 249 项国家 / 地区电话区号

- 用户确认把“新建客户”的电话区号从现有 41 项扩展为 249 项，并要求以后可以在 CRM 内自行新增和维护。
- 新增独立国家 / 地区数据文件，按当前有效的 249 个 ISO 3166-1 alpha-2 项生成中文名、英文名、ISO 三位码、数字码、区域和国际电话区号；数据基于 Unicode CLDR 48 与 Google libphonenumber E.164 元数据，南极洲等未由 libphonenumber单列的 7 项补充其所属国际号码体系。
- “新建客户”电话和 WhatsApp 区号不再使用 41 项前端硬编码，统一读取启用状态的 `country_region` 字典；显示国旗、国家 / 地区名、ISO 编码和区号，常用国家置顶，同一区号对应多个国家时保留各自国家项。
- `CRM → 设置 → 字典配置 → 国家 / 地区预设` 新增 ISO 两位代码、国际电话区号、所属区域和常用置顶字段；管理员新增、编辑或停用国家后会直接影响新建客户区号下拉。前后端都校验区号必须为 `+` 加数字。
- 新增幂等数据版本 `20260727_country_region_249_v1`：现有 105 项保留名称、启用/停用状态和自定义置顶设置，只同步标准 ISO / 区号元数据并补入缺少项目；不会修改现有客户、联系人或电话号码。
- 新增无数据库契约和受控真实数据库集成测试；生产事务预演从 105 项成功得到 249 项后完整回滚，回滚后仍为 105 项，没有提前写入生产。
- 检查：本机 41 个 JavaScript 文件语法及 3 个前端契约通过，`git diff --check` 和 Shell 语法通过；服务器隔离候选目录使用生产 PHP 8.0 检查 372 个 PHP 文件语法和 27 个无数据库契约全部通过；真实数据库同步预演通过且已回滚。
- 修改范围：`crm_settings_config.php`、CRM JavaScript、249 项数据文件、两份测试、PHP CI 清单和本上下文。该提交因家用电脑 GitHub deploy key 无写权限未推送 GitHub；用户已确认和产品适配 V2 第 1 阶段一起直接推服务器，生产数据升级仍需通过登录页面或后续受控接口触发，本轮不会擅自写客户业务数据。

## 本次：产品适配关键范围入口与配置组简化

- 用户反馈生成标准配置后看不到“快速规则”，无法填写关键范围；同时“类型、类别、名称”在芯片组中重复显示“芯片”，不清楚配置组是否等同于批量套用。
- 生产只读核对确认最近生成的产品（含 `32.04520`）已正确建立芯片、光学、蜂巢网、玻璃等标准配置组，但关键范围均未填写；根因是生成后仍停留在“选项列表”，且快速规则入口被挤在右侧横向页签中，并非标准配置生成失败。
- 明确业务概念并写入页面：配置组是当前产品的一类选配结构，例如“芯片 / 光源”组包含关键范围、候选芯片、默认项和适用条件；“批量套用”是把已经配置好的整套配置组复制到其他产品，两者不再混为一谈。
- 标准配置生成成功后自动打开第一个组的“关键范围”；切换配置组会保留当前页签，可连续填写芯片、光学、蜂巢网、玻璃等范围。右侧标题区和空选项页都新增显眼的“填写关键范围”入口，原“快速规则”页签改为“关键范围（快速规则）”。
- 配置组卡片直接显示“关键范围：待填写 / 已填写 N 项 / 不允许选配”，并把重复的业务类型文案改为“必选 / 可选、单选 / 多选、候选物料来源”，让约 1000 个产品的配置进度更容易扫描。
- 配置组编辑由“配置组类型 / 对应物料类别 / 配置组名称”改为“配置用途 / 候选物料来源 / 页面显示名称”：选择用途后系统自动关联正确的正式物料类别并建议显示名称；只有自定义用途才需人工选择来源。服务端同步强制标准用途与物料类别映射，防止芯片用途误关联到配件等类别。
- 新增无数据库契约 `adaptation_quick_rule_discovery_contract.php` 并纳入 CI，无数据库 PHP 契约由 24 增至 25；扩展真实数据库集成测试，额外验证即使提交错误类别，芯片用途仍会保存为芯片物料来源。
- 检查与部署：本机 38 个 JavaScript 文件语法及 3 个前端契约通过；生产 PHP 8.0 对 355 个已跟踪 PHP 文件语法、25 个无数据库契约全部通过；真实数据库快速规则、批量复制和用途类别映射回归通过，临时测试产品残留为 0，`git diff --check` 通过。
- 功能提交 `15fc42deb20f3dc6d200bb0eb7d31db4a54162eb` 已推送 GitHub 并由自动流程部署到腾讯云；线上公开脚本已包含生成后自动打开关键范围、显眼入口和简化表单，未登录适配页仍按统一登录规则返回 302。
- 修改范围：产品适配页面、服务、JavaScript / CSS、两份适配测试、PHP CI 清单和本上下文；没有修改现有产品、适配组、快速规则或数据库结构。用户原有商务中心命令文件删除及未跟踪文档保持原样，未纳入本轮。

## 本次：报价历史跟进增加查看、修改和删除

- 用户要求已写入的报价跟进可以从“历史跟进”直接查看、修改和删除。
- 每条历史跟进新增“查看 / 修改 / 删除”操作：查看使用独立详情弹窗展示记录人、沟通时间、方式、结果、联系人、客户回复、下次跟进、沟通内容、下一步计划、附件说明和截图；修改会把原记录带回表单并明确显示“保存修改”；可随时取消修改恢复新建状态。
- 修改使用独立 `quote_followup_update` 接口，在事务内更新原记录、同步客户时间线，并按最新一条有效跟进重新计算报价任务的联系人、状态、下次提醒、完成信息和结果；操作日志在业务事务提交后写入，避免再次触发 MySQL 隐式提交问题。
- 删除使用独立 `quote_followup_delete` 接口：跟进及截图记录软删除，客户时间线中的派生展示同步移除，报价任务按剩余最新记录自动回算；没有剩余记录时任务恢复待处理并清空结果/提醒。操作日志保留用于审计，服务器上的截图文件保留以便误删恢复。
- 权限：有 `task.edit` 可修改可见任务的跟进；普通账号可删除自己创建的跟进，有 `task.delete` 的账号可删除其他人的跟进。前端按权限隐藏不可用按钮，后端再次校验任务可见范围和权限。
- 保存按钮增加请求期间禁用及“正在保存”提示，避免连续点击生成重复跟进；截图删除按钮同样只向有编辑权限的账号显示。
- 新增无数据库契约 `crm_quote_followup_history_actions_contract.php` 并纳入 CI，无数据库 PHP 契约由 23 增至 24；新增真实数据库集成测试，验证修改、客户时间线单条同步、任务完成状态、跟进/截图软删除、时间线移除、任务复位及测试数据/日志清理。
- 候选检查：生产 PHP 8.0 对 352 个已跟踪 PHP 文件语法和 24 个无数据库契约全部通过；真实数据库集成测试通过且测试残留为 0。本机 38 个 JavaScript 文件语法及 3 个前端契约通过，`git diff --check` 通过。
- 功能提交 `22328b64755666c9d9f5c8019e15b1200f9c4ea2` 已推送 GitHub；GitHub Actions 第 601 次运行的 PHP / JavaScript 检查和自动部署均成功，同一提交已快进到腾讯云。部署后服务器 PHP 语法、两份报价跟进契约及真实数据库更新/删除回归再次通过。
- 修改范围：`crm_task_center.php`、`crm_api.php`、CRM JavaScript / CSS、两份新增测试、CI 清单和本上下文；没有修改用户现有报价、跟进或客户业务数据，测试数据均已清理。用户原有商务中心命令文件删除及未跟踪文档保持原样，未纳入本轮。本上下文收尾提交后再次等待自动部署并核对三端最终一致。

## 本次：修复 CRM 新建报价跟进保存事务错误

- 用户反馈“CRM → 任务跟进”中新建报价跟进在保存时提示 `There is no active transaction`。
- 根因确认在 `crm_quote_followup_save()`：报价任务、跟进活动和客户时间线尚处于事务中时调用 `crm_log_event()`；日志函数首次调用会执行 `CREATE TABLE IF NOT EXISTS` 等初始化 DDL，MySQL 因 DDL 隐式提交事务，随后业务代码再次 `commit()` 就抛出该错误。
- 修复把任务、跟进活动和客户时间线作为同一业务事务提交，提交成功后再独立写操作日志；整段保存统一复用同一个 PDO 连接，异常时仅在事务仍有效时回滚。没有改动现有报价、客户或跟进数据。
- 新增 `tests/crm_quote_followup_transaction_contract.php` 并纳入 PHP CI，强制约束“开始事务 → 提交业务数据 → 写操作日志 → 重新加载结果”的顺序，并禁止把日志调用重新放回业务事务。
- 候选版本在服务器隔离目录使用生产 PHP 8.0 检查 370 个 PHP 文件语法全部通过；新增专项契约和现有派工可见性契约通过，`git diff --check` 通过。本机没有 PHP CLI，因此没有伪报本机 PHP 检查。
- 功能提交 `6d7c5f54cbb12f6a0fc0883e4421bad7e049a299` 已推送 GitHub；GitHub Actions 第 599 次运行的 PHP / JavaScript 检查和自动部署均成功，同一提交已快进到腾讯云。部署后服务器 PHP 语法与专项契约复检通过，公开入口按统一登录规则返回 302。
- 修改范围仅为 `crm_task_center.php`、新增专项测试、CI 清单和本上下文；用户原有商务中心命令文件删除及未跟踪文档保持原样，未纳入本轮。本上下文收尾提交后再次等待自动部署并核对本地、GitHub、服务器最终提交一致。

## 本次：产品适配快速规则与千款产品批量套用

- 用户确认产品适配不能逐款慢慢维护，需覆盖芯片、电源、光学、蜂巢网、玻璃及其他配件，并支持约 1000 个产品批量设置；默认操作必须简单、安全，资料不全时不能伪报适配。
- 产品适配标准框架新增独立“蜂巢网”和“玻璃”配置组；每个组新增“快速规则”页签，可直接设置允许 / 不允许，并按类别填写少量关键范围。芯片支持功率、电流、电压、封装和 LES；光学支持直径、高度、光束角、LES 和固定方式；蜂巢网 / 配件支持直径、厚度、接口和安装位置；玻璃支持直径、厚度和材质。蜂巢网与玻璃可明确设置能否同时安装。
- 候选物料不再只判断类别：系统按芯片、光学、蜂巢网、玻璃、配件和安装件的正式规格自动给出完全适配、条件适配、需要审批或不适配及具体原因；关键资料缺失时只标记“需要审批”。明确不适配选项成为审批硬门槛，不能通过“批准例外”绕过。
- 新增批量套用工作台：以当前产品为来源，可选择同系列、搜索后全选或手工勾选；默认“只补空白”，也可明确选择“覆盖同名配置组”；必须先预览新增、覆盖、跳过和已有审批数量，再确认执行。前端一次操作最多选 1000 个产品，并在后台自动按每 100 个分批提交，降低长请求超时风险。
- 批量内容包含配置组、快速规则、正式物料选项、默认项、适用条件、价格 / 交期和跨组选项冲突；有权限时可同时复制外置 / 内置、功率、电流、电压、空间、调光和质保等产品电源范围。每个目标产品独立事务，单个失败不会留下半套数据，也不影响其他目标；变更后的目标统一进入待重审，商务中心不会读取未重新批准的配置。
- 产品列表读取上限由 500 提升到 2000；服务端仍强制单次最多 1000 个目标，拒绝不存在或停用产品。新增配件直径和厚度字段，沿用物料中心动态字段与批量编辑能力。
- 新增迁移 `20260727_017_adaptation_quick_rules_batch`、无数据库契约 `adaptation_batch_quick_rules_contract.php` 和真实数据库集成测试 `adaptation_batch_quick_rules_integration.php`；无数据库 CI 契约由 21 增至 22。
- 生产迁移前只读预检：同步产品 243、适配组 25、适配选项 1、产品电源规则 0；新迁移、`rule_json`、配件直径和厚度列均不存在，符合首次迁移条件。迁移前已把四张相关表的结构与数据备份到服务器 `_codex_backups/mc_adaptation_20260727_batch_rules/before_migration_017.json.gz`，SHA-256 为 `d625a22a8191dae3580ad0547fcaa2ce77eaece6195446f2ea015f4ad2d6521f`。
- 迁移 `20260727_017_adaptation_quick_rules_batch` 已在生产登记 1 次；配件直径 / 厚度字段和分类映射各 2 条均就绪。迁移后产品、适配组、适配选项仍分别为 243、25、1，没有改动现有业务配置。
- 检查：本机 38 个 JavaScript 文件语法和 3 个静态 / 契约测试通过；GitHub 与服务器生产 PHP 8.0 对 351 个 PHP 文件语法、22 个无数据库契约全部通过；Shell 语法和 `git diff --check` 通过。服务器没有 Node.js，因此未把服务器端 Node 检查伪报为成功，前端检查由本机和 GitHub Actions 实际完成。
- 真实数据库回归通过两层：跟踪集成测试验证快速规则保存、只补空白、覆盖同名组、目标待重审和自动清理；额外深度测试用临时 50mm 蜂巢网与钢化玻璃验证规格候选、2 个选项批量复制，以及“蜂巢网与玻璃不能同时安装”的组合阻止原因。测试产品、物料和日志残留均为 0。
- Git / 部署：功能提交 `35e3a4951dec9fc55c497e73a1bc017158baf6d0` 已推送 GitHub；GitHub Actions 运行 `30228757324` 的测试与自动部署成功，同一提交已快进到腾讯云。公开站点和适配页按统一登录规则返回 302，适配 API 未登录返回 JSON 401；线上脚本已包含快速规则、100 个一批和批量完成逻辑。
- 浏览器验收：未登录会正确跳转统一登录页；当前浏览器没有 ERP 登录态，因此未伪报登录后的视觉点击验收。用户登录后可直接从“物料中心 → 产品适配”复核快速规则和批量套用弹窗。
- 修改范围：`material_center_v1/adaptation/index.php`、适配 API / 服务、适配 JavaScript / CSS、新迁移、两份测试、`tools/ci_php_checks.sh` 和本上下文；用户原有商务中心命令文件删除及未跟踪文档保持原样，未纳入本次。
- 待完成：本次收尾上下文提交后再次等待 GitHub 自动部署，并核对本地、GitHub、服务器最终提交一致；业务功能无待实现项，登录后的人工视觉复核可由用户直接进行。

## 本次：当前账号在负责人或派工人任一列时强制显示待办

- 用户要求派工待办和个人待办采用同一硬规则：当前账号只要出现在“负责人 `assigned_to`”或“派工来自 / 派工人 `created_by`”任一列，该待办就必须显示。
- 根因有三层：人员可见规则中的禁止名单可能覆盖当前账号关系；列表人员筛选会再次排除当前账号相关行；多人派工成员查询还有独立负责人筛选。日期、状态、优先级和逾期筛选属于用户主动筛选，本轮保持原行为。
- 修复 `dispatch_next_api.php`：把当前账号两列关系放在普通可见范围之外作为优先 OR 条件；个人和派工人员筛选先检查当前账号两列，命中即保留；多人组成员筛选同样加入当前账号作为派工人或负责人的强制保留条件。匹配使用账号 ID，不以同名字符串判断，避免同名用户误显示。
- 新增 `tests/dispatch_current_account_visibility_contract.php` 并加入 `tools/ci_php_checks.sh`，约束后台可见 SQL、两列判断、人员筛选优先顺序和多人组查询，防止后续回退。
- 生产只读核对：`qiulei / 邱磊`（账号 ID 1）当前有个人待办 26 条，其中仅派工人匹配 18、仅负责人匹配 4、两列同时匹配 4；派工待办 25 条，其中仅派工人匹配 17、仅负责人匹配 8。因此两列都必须纳入强制显示。核对未写数据库。
- 检查：生产 PHP 8.0 对候选版本 348 个已跟踪 PHP 文件语法检查全部通过，21 个无数据库契约全部通过；本地 38 个 JavaScript 文件语法及 3 个静态/契约测试通过，`git diff --check` 与 Shell 语法通过。候选测试只在服务器 `/tmp` 隔离目录运行并已清理。
- Git / 部署：功能与测试提交 `14ba9d80778c438957b6860330bbe07e173b623b` 已推送 GitHub；GitHub Actions 第 595 次运行的测试和部署任务均成功，并自动把同一提交快进到腾讯云。部署后服务器 PHP 语法和专项契约复检通过，派工页面公网返回 HTTP 200；核对时本地 HEAD、GitHub `main`、服务器 HEAD 与服务器 `origin/main` 四处均为该提交，服务器工作区干净。
- 用户原有商务中心命令文件删除及未跟踪文档保持原样，未纳入本轮；没有数据库结构或业务数据写入。本次收尾上下文提交后再次通过相同自动流程核对三端一致。

## 本次：建立可持续测试与安全自动部署工作流

- 完成腾讯云实例、站点目录、运行环境、Git、测试网址和安全基线只读扫描；确认生产为 Ubuntu 22.04、Nginx、宝塔 PHP-FPM 8.0、MySQL、原生 PHP 应用，站点目录 `/www/wwwroot/Artdon/artdon_erp`，Git `main` 与 `origin/main` 初始提交均为 `6fac85e`。
- 修复本机 SSH：保留腾讯云 RSA 公钥并追加办公电脑 `artdon_tencent` ED25519 公钥；`artdon` 和 `qiulei0207_office` 两个别名均已验证可登录。腾讯云下载的 RSA 私钥已安全复制到 `~/.ssh/qiulei0207_office.pem` 并设为 `600`。
- 原办公电脑 iCloud 仓库无法获得 Codex 写权限，经用户明确授权迁移到 `/Users/qiulei-office/Documents/Codex/Artdon/artdon_erp`，Git 历史与用户已有删除/未跟踪文档完整保留，私钥没有复制到新仓库；`AGENTS.md` 已把办公电脑唯一编辑源更新为新路径。
- 新增根目录 `/*.pem` 忽略规则，防止私钥误入 Git。旧 iCloud 仓库根目录仍有未跟踪 `qiulei0207_office.pem`，因原目录只读无法由本轮删除，需用户手工清理。
- 基线测试发现旧 `adaptation_workbench_contract.php` 仍断言已被适配工作流 v2 替代的字段、提示和电源错误文案；业务实现及新版 v2 契约正确，本轮只把旧契约更新为当前 `save_conditions / expected_json / failure_message`、完成度审批门槛和现行电源适配原因。
- 新增 `tools/ci_php_checks.sh`：用指定 PHP 执行全部已跟踪 PHP 文件语法检查，并运行 20 个不依赖生产配置、不会修改数据库的 PHP 契约测试。两个依赖真实系统配置的电源权限/Tab 测试明确不纳入无数据库 CI，仍保留为受控服务器测试；脚本在不属于 Git 工作区或未发现 PHP 文件时直接失败，避免“检查 0 个文件”误报通过。
- 新增 `tools/ci_js_checks.sh`：检查全部已跟踪 JavaScript 文件语法，并运行 3 个物料中心 MM/UI 静态、UI 契约及只读安全测试；同样拒绝非 Git 工作区和零文件误报。
- 重建 `.github/workflows/deploy.yml`：Pull Request 和 `main` 推送先执行 PHP 8.0 与 Node.js 20 检查；仅 `main` 全部通过后部署。部署不再要求服务器访问 GitHub，而是由 Actions 生成校验 Git bundle，通过固定 ED25519 主机公钥上传；服务器工作区非干净、bundle 哈希不符、提交号不符或不能快进时立即停止，部署后同步服务器 `origin/main`、再用生产 PHP 8.0 复检并验证公开登录路由。
- 新增 `.github/known_hosts` 和 `docs/DEPLOYMENT_WORKFLOW.md`，记录固定主机公钥、所需 GitHub Secrets、测试/部署顺序及生产数据库测试边界。
- 检查：本地 38 个 JavaScript 文件语法、3 个 Node 静态/契约测试、Shell 语法、Workflow YAML、固定 SSH 主机公钥、私钥忽略和 `git diff --check` 通过；服务器 `/tmp` 干净临时克隆使用生产 PHP 8.0 检查 347 个 PHP 文件语法和 20 个无数据库契约测试，全部通过。临时目录已清理，未修改生产目录或数据库。
- Git / 部署：工作流主体提交 `0c7042a8039c6d24484da7282e2d3571e5e39b92`、SSH 预检与诊断加固 `6136445c08b9e7c5c6eb5c59b4a1ffd719238509`、部署密钥身份诊断 `c4108c7f254a5b414fbf72c10e227226f0067402` 均已推送 GitHub 并以校验 Git bundle 快进到服务器；每次服务器复检均为 PHP 347/347、契约 20/20 通过。
- 首次自动运行确认现有 GitHub `SERVER_SSH_KEY` 未获服务器授权；只导出其 ED25519 公钥并核对指纹 `SHA256:EPXiCzpAVY8MqF4pvMnyU0nsxCuX3Q1dQyh7vZigNmo` 后加入 `authorized_keys`，没有输出或复制私钥。变更前备份为 `/home/ubuntu/.ssh/authorized_keys.codex-backup-20260727-actions`，原腾讯云 RSA 和办公电脑 ED25519 公钥均保留。
- 端到端验收提交 `65ad0bfaf9c4654920239d03ec03fb864c7858bf` 只用于验证自动化；GitHub Actions 第 593 次运行的“PHP and JavaScript checks”和“Deploy tested commit”两个任务均成功，Actions 独立完成测试、bundle 传输、服务器快进、生产 PHP 复检和公网路由检查。验收后本地 HEAD、GitHub `main`、服务器 HEAD 与服务器 `origin/main` 四处均为该提交，服务器工作区干净，`https://novlight.com/artdon_erp/` 返回 HTTP 200。
- 用户原有 `commercial_center_v1/离职中心CODEX.command` 删除、商务中心未跟踪文档和命令文件全部保留且未纳入本轮。唯一人工清理项是旧只读 iCloud 仓库根目录的未跟踪 `qiulei0207_office.pem`；新仓库没有该私钥且已忽略根目录 PEM。

## 本次：修复电源“确认并转正式”点击无响应

- 生产只读核对：用户电源 `PS-BOM-000647 / 伊戈尔 新款高P无频闪外置驱动` 仍为 `pending_review`，只有 `draft → pending_review` 的提交事件，没有 approve 生命周期或操作日志；账号 `qiulei` 为统一账号超级管理员，审批权限正常，因此没有擅自改动该条生产数据。
- 根因：统一来源整理改动为电源转正式入口新增了浏览器原生 `confirm()` 门槛；点击未进入 `material-master.php`，错误也只能显示短暂 Toast，造成“点了没反应”。
- 修复：去掉转正式入口对原生确认框的依赖，按钮点击后立即调用生命周期 API；按钮显示“正在转正式…”，请求期间禁用防止重复提交，成功显示“已转正式”，失败原因同时保留在抽屉内和 Toast。提交确认入口同步增加相同的可见进度和错误反馈。
- 回归：电源编辑器合同测试新增“审批不得依赖原生确认框”的约束；真实集成测试新增草稿提交、待确认审批、`official / is_official / allow_bom / allow_quote` 四项正式状态校验，并在 finally 中清理临时测试物料。
- 本地检查：电源脚本已通过 macOS JavaScriptCore 解析，两个改动测试文件已通过服务器 PHP CLI 对本地内容的输入语法检查，`git diff --check` 通过。本机没有 PHP CLI，且当前没有可连接的登录态内置浏览器，未伪报登录态点击验证。
- Git / 部署：功能与初始上下文提交 `3a979c82f85c29410dd990cda71d24d7df06728e` 已推送 GitHub；服务器自身没有 GitHub SSH 权限，因此使用 SHA-256 为 `9e17b78ad7231ce1528bacfff51db27bb4980552c1b31d76bfcef36b0beaf5c0` 的校验 Git bundle，把服务器从 `337e8d9e` 快进到 GitHub 同一 Git 对象，没有直接覆盖文件。
- 服务器复检：改动测试 PHP 语法、电源编辑器合同、来源整理合同、电源真实编辑 / 批量回滚 / 生命周期审批集成、六分类来源整理幂等集成全部通过；临时测试物料残留为 0。
- 线上核对：电源页未登录仍 302 到广州统一登录；公开电源脚本已包含“正在转正式…”新逻辑。用户电源仍为 `pending_review / is_official=0 / allow_bom=0 / allow_quote=0`，仍只有原提交事件，修复和测试没有代替用户审批或改动其状态。
- 修改文件：`material_center_v1/assets/js/power-editor.js`、`material_center_v1/tests/power_editor_contract_test.php`、`material_center_v1/tests/power_editor_integration.php`、`WORK_CONTEXT.md`。未修改数据库结构、旧 BOM 或用户现有电源状态；未触碰商务中心既存删除和未跟踪文件。
- 退出交接：用户已要求退出；当前功能已完成并上线，没有尚在执行的任务。下一次从用户实际点击“确认并转正式”的业务验收结果继续。

## 本次：统一七类来源物料整理逻辑

- 统一电源、芯片、光学、型材 / 散热件、接头 / 安装件、配件、包装七类来源行操作：待整理“整理”、待确认“确认”、草稿“编辑”、正式 / 停用 / 归档“查看”、异常 / 驳回“处理”、重复候选“对比合并”；电源原“设置”和其他分类原“查看来源”不再作为来源主操作。
- 分类页明确“新建”只用于 BOM 中不存在的手工新物料；全部物料页点击来源“整理”会跳到对应分类并自动打开同一来源记录，不要求重新新建。
- 新增统一 `SourceMaterialOrganizerService` 和 `api/v1/source-material.php`：按 `source_system + source_table + source_pk` 读取来源，保存 / 更新 `mc_materials` 草稿，事务内写来源快照、解析日志、置信度和唯一映射；来源记录行锁与唯一索引保证重复点击、重复请求仍复用同一草稿。
- 旧 BOM 同步继续只读；来源快照变化只把来源标记为 changed，绝不静默覆盖人工物料字段。整理抽屉会显示变化警告、只读原始资料、完整 JSON 快照和解析日志，人工重新核对保存后更新已审阅快照哈希。
- 七类分类页均使用本类别字段抽屉；补上原来缺失的型材和包装抽屉。芯片补系列并明确芯片类型、封装、LES / 芯片尺寸；光学补适配 LES、长宽、光束角、调焦、IES、配光曲线并限定七种光学类型；配件补接口，包装补刀模图 / 标签模板；各类补供应商和备注。
- 抽屉底部统一为取消、保存草稿、提交确认和按权限显示的确认并转正式；服务端对 `approve` 再校验 `material_center.approve`，普通用户不能靠显示按钮绕过权限。
- 新增迁移 `20260726_016_source_material_organizing` 及 down 回滚：来源映射增加单来源唯一索引、已审阅快照哈希和时间，补充分类字段和字段注册表映射。生产只读预检确认 1022 条来源、1 条映射、0 个单来源多映射冲突，具备增加唯一约束的条件。
- 新增来源整理静态契约与六类幂等集成测试；本地没有 PHP / Node CLI，已把本地副本放到服务器 `/tmp` 完成全量 PHP 语法检查，JavaScript 使用本机 JavaScriptCore 解析通过，来源整理、分类抽屉和电源统一抽屉契约通过，`git diff --check` 通过。一次性临时数据库预演因生产数据库账号没有 CREATE DATABASE 权限无法使用；迁移上线后已运行真实集成测试并完整清理测试数据。
- 功能提交 `a8194d68b97154f4e7564efdd296da4ef49828de` 已推送 GitHub 并快进同步服务器；首次幂等集成测试发现“同一秒原值重存时 PDO rowCount=0 被误判失败”，已在本地修复、语法检查后以提交 `2a5644fc04b9989d2ccdb97cef8a88186bc9e8ab` 推送并再次快进部署，没有在服务器直接修补。
- 生产迁移 `20260726_016_source_material_organizing` 已登记 1 次，第二次执行 `applied=[]`；迁移相关表备份位于服务器 `_codex_backups/mc_source_20260726_2322/database_after_migration_016.sql.gz`，SHA-256 为 `ae031119bfdf8fa79d609ded18667d8bb34751d4daeaca497d37417754dde9e9`，迁移文件自带 down 回滚。
- 服务器复检通过：六类来源草稿创建 / 重开 / 重存唯一映射 / 来源变化保护 / 提交审批 / 转正式集成，电源来源标准化、七类动态字段、电源单条与批量编辑、统一权限、来源列表、电源 UI / 路由 / 权限回归，以及七个分类页 CLI 渲染。包装当前没有旧 BOM 包装来源，但专用整理抽屉和后端类别逻辑已就绪。
- 生产收尾核对：来源记录 1022、来源映射 1、单来源多映射 0、测试物料 0、测试来源 0、迁移记录 1、唯一索引 1；旧 BOM 回归变化 0。用户原电源 `PS-BOM-000647` 当前为 `pending_review / is_official=0 / allow_quote=0`，没有被测试转正式或送入商务读取。
- 线上未登录电源 / 芯片页继续 302 到广州统一登录，来源 API 返回 JSON 401，新分类编辑脚本已生效。当前没有可连接的登录态浏览器会话，未伪报真实账号点击或视觉截图；服务、数据库、权限、页面渲染和公网守卫均已实际验证。本轮收尾上下文提交后再次核对本地、GitHub、服务器三方一致。

## 本次：电源页收口、四类物料设置抽屉、产品适配重建及商务审批读取

- 删除 `material_center_v1/material/power.php` 顶部“进入电源整理”入口，并删除独立 `power_standardization.php`、专属 JavaScript 和旧布局契约；旧 BOM 电源来源改为在电源页同一业务抽屉确认字段并直接建立物料中心草稿，旧 BOM 仍只读。
- 电源抽屉补齐草稿提交确认、待确认转正式；生命周期转正式时同步开启 `is_official`、`allow_bom`、`allow_quote`，停用/归档时关闭，避免状态与业务读取标志脱节。
- 芯片、光学、接头 / 安装件、配件四个分类页新增统一真实设置抽屉：基础资料和分类规格从字段注册表动态加载，保存到对应 `mc_material_*` 表；支持新建、草稿编辑、复制新增、引用检查、提交确认、清空可选字段和只读状态保护。
- 修复分类列表原始状态与中文展示状态混用的问题：草稿不再被抽屉误判为只读，待整理 / 待确认 / 正式 / 异常 Tab 按数据库状态过滤。
- 产品适配按固定三栏“产品列表 | 配置规则 | 选项详情”重建，保留现有整站外壳、左侧菜单和路由；支持九个标准配置组、正式物料候选、必选/可选/替代/条件/禁用、默认物料、明确条件失败原因、冲突、价格影响、交期影响、适配检查和版本审批。
- 产品电源适配复用现有真实产品电源规则，自动检查功率、输出电流/电压范围、灯体长宽高空间、安装方式、输出类型、调光、认证和供应商质保，返回具体不适配原因；没有规则时不伪造结论。
- 适配配置采用整份审批门槛：任一配置组、选项或冲突发生修改后产品标记“待重审”，商务中心不读取部分旧审批结果；全部重新审批后才恢复读取。
- 商务中心新增只读物料中心桥：按具体命名系统产品合并已批准适配组，只读取 `official + is_official + allow_quote` 的正式物料，并在标准报价产品配置中标识“物料中心已审批”；未批准、草稿、停用或正在重审的数据不会进入报价，商务中心没有向 `mc_` 表写入。
- 生产只读核对：用户刚设置的电源 `PS-BOM-000647 / 伊戈尔 新款高P无频闪外置驱动 / LS-8-70 LI1 EXC` 当前为 `draft`，`is_official=0`、`allow_quote=0`、批准适配引用为 0；因此目前没有同步到商务中心，这是符合审批数据流的结果。
- 检查：全部改动 PHP 文件部署前语法通过；JavaScript 6 个改动文件语法通过；分类抽屉、产品适配三栏工作流、商务审批读取桥、电源统一抽屉专项契约通过；物料中心 MM 静态安全、UI 静态和只读安全契约通过；`git diff --check` 通过。当前没有可连接的登录态浏览器窗口，未伪报登录后点击验证。
- 修改范围：物料中心电源 / 分类工作区、产品适配、相关服务/API/样式/脚本/文档和测试；商务中心配置仓库、配置引擎、标准报价脚本及读取桥测试；`WORK_CONTEXT.md`。没有修改旧 BOM 表，没有数据库结构变更，也没有触碰用户已有的商务中心命令文件删除和未跟踪文档。
- Git / 部署：功能提交 `1e4c9e2`、安全扫描测试误报修正 `9abbf40`、正式物料验收步骤修正 `dc74955`、统一权限验收修正 `dbd28ab` 均已依次推送 GitHub，并通过校验 Git bundle 快进同步服务器；没有数据库迁移。
- 服务器复检：全部改动 PHP 语法、商务安全扫描、报价中心回归、分类抽屉、产品适配、商务审批读取桥、电源统一抽屉和路由契约通过；真实分类字段、电源来源草稿、多电流/调光、批量执行/回滚、完整物料/供应商/生命周期/产品适配/统一权限、10k 分页、商务配置引擎和标准报价闭环回归通过，测试数据清理为 0。
- 商务读取桥使用生产事务验证：临时把用户草稿电源置为正式并建立批准适配时，商务仓库能够读取；把任一配置组改回草稿后整份产品适配立即隐藏，事务随后完整回滚。最终用户电源仍为 `draft / is_official=0 / allow_quote=0`，生产已批准商务适配选项仍为 0。
- 线上检查：电源页和产品适配页未登录均 302 到广州统一登录；已删除的 `power_standardization.php` 返回 404；新增分类抽屉脚本返回 200。当前无登录态自动化浏览器，登录后的视觉点击由用户使用现有统一账号复核。
- 当前服务器 HEAD 与服务器 `origin/main` 均为 `dbd28ab456cfb5356da6e63d19b01863198d1d07`，工作区干净；本轮上下文收尾提交后再次核对本地、GitHub 与服务器三方一致。

## 本次：记录物料中心正式建设总要求

- 已将用户本次明确提出的物料中心完整要求归档为 `material_center_v1/docs/ARTDON_MATERIAL_CENTER_FORMAL_REQUIREMENTS_20260726.md`，作为后续审计、实施、测试和验收的权威需求基线。
- 需求定位：物料中心是 Artdon ERP 的统一物料主数据中心，不是 BOM 附属清单；统一向 BOM、PLM、产品适配、商务报价、采购、库存、订单、新加坡下单网站及后续 MES/WMS 提供正式物料数据。
- 核心硬约束：保留现有外壳、固定左侧菜单和现有路由；复用广州 ERP 统一账号；新增表使用 `mc_` 前缀；旧 BOM 只读；禁止演示数据冒充完成；数据库变更必须幂等迁移、可回滚，重要写操作使用事务。
- 完成范围：真实工作台、八类物料、十状态生命周期、分类字段、来源标准化、批量设置、产品适配、供应商价格、替代版本、文档日志、完整表格能力、七层权限、四级设置以及测试、备份和回滚。
- 执行纪律：正式实施时先完整审计现有外壳，再连续完成各模块；不重新设计页面、不改变左侧菜单、不建立第二套账号、不修改旧 BOM；每个模块自行测试和修复后继续。
- 本轮仅负责忠实记录需求，没有开始新的业务代码或生产数据库变更。仓库已有早期长任务说明继续保留为历史资料；如有冲突，以本次带日期的正式要求及用户后续明确指令为准。
- 注意到当前线上物料中心此前已按用户指令改为红色主题，而本次文本写明保留青绿色主色；本轮只记录、不自行回改主题，正式实施前应以用户最新明确决定处理该冲突。
- 修改文件：新增上述正式要求文档并更新 `WORK_CONTEXT.md`；检查、提交、GitHub 推送、服务器同步和三方一致性以本轮最终结果为准。
- 需求归档提交 `55807c5921dddfbabd99b5cfe053ccdd09dff154` 已推送 GitHub 并通过已校验 Git bundle 快进同步服务器；文档 17 个编号章节、文件存在性和 `git diff --check` 通过，服务器工作区干净。最终上下文收尾提交后再次核对本地、GitHub 与服务器三方一致。

## 本次：物料中心全局主色由青绿色改为大红

- 将物料中心新旧两套 UI 的统一主操作色改为大红 `#D60000`，悬停色为深红 `#B00000`，选中/浅色背景为 `#FDECEC`；按钮、导航选中、链接、标签、勾选卡片、进度条及相关边框统一继承红色体系。
- 清除物料中心样式中原 `#0F8F9D` 及其配套青绿色硬编码；“确认并建立草稿”等现有主按钮同步变红。
- 设置默认值、服务端运行时兜底、内置主题和新装数据库默认值均改为红色；新增幂等迁移，把生产库已有的全局、用户/角色范围及活动主题主色统一改为红色，确保现有账号与以后新增页面一致。
- 新增全局红色主题契约测试，覆盖新旧 UI token、服务端兜底和生产数据迁移；服务器 `/tmp` 中相关 7 个 PHP 文件语法及主题契约通过，生产数据库事务预演确认默认值与 2 个活动主题均会改为红色并已回滚，当前没有需要改写的账号/角色范围主色记录。
- 修改文件：物料中心 token、公共样式、页面运行时兜底、两处设置页、基础迁移、红色主题迁移及两份测试、`WORK_CONTEXT.md`；未触碰用户已有的商务中心删除及未跟踪文件。提交、推送、服务器部署及三方一致性以本轮最终结果为准。
- 功能提交 `bd32ec1` 已推送 GitHub 并快进同步服务器；生产迁移 `20260726_014_red_primary_theme` 首次应用成功、第二次执行为空，当前数据库默认主色和 2 个活动主题均为 `#D60000`，没有账号/角色范围覆盖记录。
- 服务器 PHP 语法、全局红色主题契约、差异检查通过；线上 token 与旧版公共 CSS 均已返回红色主色、深红悬停和浅红选中背景。本轮收尾上下文提交后再次核对本地、GitHub、服务器三方一致。

## 本次：“确认并建立草稿”按钮颜色核对

- 只读确认该按钮仅使用默认 `.ui-btn` 主操作样式：正常填充为 `--ui-primary: #0f8f9d`，悬停填充为 `--ui-primary-hover: #0b7682`，文字为白色 `#fff`。
- 本次未修改业务代码、样式或生产数据，仅更新 `WORK_CONTEXT.md`；最终提交、推送、服务器同步与三方一致性以本轮收尾结果为准。

## 本次：纠正电源人工确认抽屉宽度并增加分区

- 按用户纠正恢复人工确认抽屉原有公共宽度（`ui-drawer-xl`，由系统设置控制 520–620px），删除上一版专属 920px 拉宽规则。
- 在原宽度内保持两列短字段，并按“基础确认”“功率与电压”“尺寸与性能”“输出电流选项”“调光方式”“重复与关联”分区；如 240V 等短值只使用半行输入框。
- 多电流、调光和重复候选保持原业务控件与动作；手机恢复单列，低高度继续保留滚动兜底。
- 修改 `power_standardization.php`、`assets/css/app.css`、布局契约和 `WORK_CONTEXT.md`；服务器 `/tmp` PHP 语法、两列/分区/原宽度契约及 `git diff --check` 通过。
- 纠正提交 `df28345` 已推送 GitHub并快进同步服务器；服务器 PHP 语法、布局契约和差异检查通过。
- 线上 CSS 已确认存在原宽度内两列规则且不存在 `#standardization-drawer{width:...}` 专属拉宽，服务器页面已确认包含四个主要分区标题；本轮收尾上下文提交后再次核对三方一致。

## 本次：电源人工确认抽屉两列紧凑布局

- 将 `PARSED → CONFIRMED / 人工确认电源字段` 抽屉从窄长单列改为最大 920px 的紧凑两列布局；安装方式、输出类型、功率档、质保及短数字字段两项并排。
- 原始资料压缩为同行摘要；输入框、置信度提示、区块间距和页脚适度收紧。输出电流、调光方式、重复候选和关联已有保持各自完整控件逻辑，不改变字段、数据或按钮行为。
- 常见电脑屏幕优先一屏显示主要内容；宽度小于 700px 时恢复单列，低高度和手机仍保留滚动兜底，避免内容被裁切。
- 新增 `power_standardization_layout_contract.php`，验证抽屉专属两列选择器、复杂控件保留及手机单列回退；服务器 `/tmp` PHP 语法与布局契约通过。当前无可连接的登录态浏览器窗口，未伪报真实点击或截图。
- 修改文件：`material_center_v1/assets/css/app.css`、`material_center_v1/tests/power_standardization_layout_contract.php`、`WORK_CONTEXT.md`；未触碰用户已有商务中心删除及未跟踪文件。
- 功能提交 `f0d54bc` 已推送 GitHub并快进同步服务器；服务器 PHP 语法、布局契约和 `git diff --check` 通过，线上 CSS 已确认包含抽屉两列与手机单列规则。
- 本轮收尾上下文提交后再次核对本地、GitHub 与服务器三方一致。

## 本次：定位 PARSED → CONFIRMED 电源人工确认弹窗

- 只读确认该弹窗位于 `material_center_v1/power_standardization.php`，标题为“人工确认电源字段”，属于旧 BOM 电源资料标准化流程，不是普通电源单条编辑抽屉。
- 弹窗由待整理/待确认记录的“审核”触发，读取解析候选值、原始资料、置信度、解析规则和重复候选；人工确认安装方式、输出类型、功率档、供应商质保、功率/电压/尺寸、输出电流及调光方式。
- 底部动作包括“关联已有”“暂不处理”“确认并建立草稿”；建立草稿后 staging 状态进入 `imported`，关联已有后进入 `confirmed`，同时写来源映射、确认人、确认时间和活动日志。
- 本次未修改业务代码或生产数据，仅更新 `WORK_CONTEXT.md`；最终提交、推送、服务器同步与三方一致性以本轮收尾结果为准。

## 本次：物料中心接入统一账号与权限中心

- 物料中心所有 Web 页面与 API 统一经过根系统账号会话守卫：未登录页面跳转根目录 `login.php` 并保留返回地址，未登录 API 返回 JSON 401；没有 `material_center.view` 时分别返回页面/API 403。
- 物料操作权限改为只读取统一 `crm_user_permissions`、`crm_role_permissions`、临时授权及超级管理员规则，删除运行时对独立 `mc_permission_grants` 和旧 `bom.view` / `bom.edit` 的权限回退。
- 新增幂等迁移 `20260726_013_unified_permission_center.php`，将 31 项 `material_center.*` 权限注册到统一 `crm_permissions`，为现有角色建立最小默认授权，并兼容迁移既有独立用户/角色授权；跨表迁移显式统一 collation。
- 物料中心独立权限写接口已停用并返回统一权限中心提示；设置页不再加载独立授权管理，具备权限的管理员从侧栏进入根系统“统一权限中心”维护账号、角色与权限。
- 部门权限模板已纳入物料权限：老板/管理员全权，工程、采购按职责授权，业务/生产/财务/只读角色默认仅查看；以后重新应用部门模板不会丢失物料权限。
- 新增统一权限契约测试，角色矩阵集成测试改为验证 `crm_roles` / `crm_role_permissions`；服务器 `/tmp` 部署前 PHP 语法与契约检查通过，生产迁移事务预演确认 31 项权限及管理员 31 项授权后回滚，未提前修改生产数据。
- 修改文件：`includes/user_admin_service.php`、物料中心 bootstrap、统一权限服务、旧权限 API、设置与侧栏、迁移和两份测试、`WORK_CONTEXT.md`；未触碰用户已有商务中心删除及未跟踪文件。
- 功能提交 `60ebce7` 已推送 GitHub并快进同步服务器；生产迁移首次应用 `20260726_013_unified_permission_center`，第二次执行为空，登记记录 1 条。最终权限：31 项；admin 31、manager 30、sales/finance 各 5 项。
- 服务器复检：相关 PHP 语法、统一权限契约、角色矩阵、`git diff --check` 通过，测试角色清理为 0；真实统一权限函数验证 admin 可查看/编辑/管理权限，sales 可查看但不可编辑或查看采购价。
- 线上未登录电源页返回 302 到 `/artdon_erp/login.php` 并携带原地址，未登录 health API 返回 JSON 401。自动化无法复用用户浏览器真实登录态，CLI 合成会话因 PHP-FPM 会话隔离未作为成功结果；未伪报登录后浏览器点击，待用户使用现有统一账号直接复核。
- 本轮收尾上下文提交后再次核对本地、GitHub 与服务器三方一致。

## 本次：物料中心与统一权限中心接入扫描

- 用户反馈线上电源页显示“未登录”，本次仅诊断扫描，未修改业务代码、账号、权限或生产数据库。
- 线上 `material_center_v1/material/power.php` 未登录访问直接返回 HTTP 200，并创建路径为 `/` 的 `PHPSESSID`；页面右上角显示“未登录”，没有跳转统一 `login.php`。
- 身份会话已部分复用统一系统：`material_center_v1/bootstrap.php` 引入根目录 `includes/bootstrap.php`，`mc_current_user()` 最终读取统一 `current_user()` 与 `crm_users`；统一登录和物料中心使用同名默认 PHP 会话及根路径 Cookie。
- 页面访问控制没有接通：电源页及公共 `_page.php` 未调用统一 `require_login()`，也未校验 `material_center.view`，导致未登录用户仍可进入并读取页面数据。
- 权限模型没有并入统一权限中心：物料中心定义独立 `mc_permissions` / `mc_permission_grants`，生产库有 31 个物料权限定义但授权记录为 0；统一 `crm_permissions` 中没有任何 `material_center.*` 权限，仅有旧 `bom.view` / `bom.edit`。物料权限服务在独立授权为空时按 `.view` 粗略回退 `bom.view`，其他操作统一回退 `bom.edit`。
- 结论：统一账号身份只完成了半接入，统一页面登录守卫、统一权限定义/角色授权、菜单可见性及逐操作权限尚未打通；需要另行实施统一权限接入，不能仅调整登录提示。
- 本次只更新 `WORK_CONTEXT.md`；检查、提交、推送、服务器同步和三方一致性以本轮收尾结果为准。

## 本次：服务器、本地与 GitHub 全仓扫描

- 按用户要求扫描本地工作目录、GitHub `origin/main` 与服务器运行目录；未修改业务代码或生产数据库。
- 扫描基线：本地 HEAD、GitHub `origin/main`、服务器 HEAD 均为 `ef3bf85fdc8e0a37b320f60cc6f4e6b10d4db830`，分支均为 `main`；服务器工作区干净，`git diff --check` 通过。
- 全部已跟踪文件使用校验和逐文件复扫：除本地用户已有删除 `commercial_center_v1/离职中心CODEX.command` 外，服务器与本地已存在文件内容一致；大量输出仅为文件时间戳不同，不是内容差异。
- 本地另有用户已有的商务中心未跟踪文档及命令文件，均保留原状且不纳入本次上下文提交。
- 本次只更新 `WORK_CONTEXT.md`；最终提交、GitHub 推送、服务器同步与三方一致性以本轮收尾结果为准。

## 本次：物料中心本地、GitHub、服务器扫描同步

- 按用户要求扫描本地、GitHub 与服务器，并在内容冲突时以服务器物料中心为准。
- 扫描前本地/GitHub HEAD 为 `55ef744`，服务器 HEAD 为 `f560697`；服务器 `material_center_v1` 工作区干净，无已跟踪修改或未跟踪文件。
- 三方 `material_center_v1` Git 树哈希均为 `89c2fbfa3c0a6308f976c6c5bba2b972b95bf0bc`，确认物料中心文件内容完全一致，不存在需要以服务器覆盖本地的内容差异。
- 唯一差异是服务器整个仓库落后 1 个仅修改 CRM 与上下文的提交；已将已推送 GitHub 的 `55ef744` 通过 Git bundle 快进同步服务器，未改变物料中心树。
- 服务器复检：`material_center_v1/index.php`、`bootstrap.php` PHP 语法通过，电源 UI 回归通过（source=200、legacy=1022、旧数据变化 0），`git diff --check` 通过，服务器工作区干净。
- 最终上下文提交、推送、服务器同步及三方一致性以本轮最终结果为准。

## 本次：CRM 报价跟进方案核对

- 报价流程和报价跟进任务中的“查看报价”已改名为“预览报价”，点击后在当前 CRM 页面打开大尺寸弹窗 iframe，不再切换页面；兼容旧 `quote_orders` 与新版 `cc_quotes`，并保留“新窗口打开”作为加载失败时的备用入口。
- 预览弹窗支持右上角关闭、Esc、点击遮罩关闭、加载提示和失败提示，手机端使用全屏预览。
- 报价预览交互契约已覆盖弹窗、iframe、关闭、Esc、备用入口、按钮名称和样式；JavaScript 语法及差异检查通过，部署状态以本轮最终提交为准。
- 沟通截图交互补齐：选择后立即显示本地缩略图并可逐张移除；支持把图片拖入上传区，也支持在跟进弹窗直接粘贴剪贴板截图；已上传截图可打开原图预览并软删除。
- 新增 `quote_followup_file_delete` 接口，删除前校验任务查看范围和编辑权限，保留物理文件以便审计恢复并写操作日志。
- 截图交互契约已覆盖预览、删除、拖入、粘贴、本地缩略图和后端删除接口；JavaScript 语法及差异检查通过，服务器 PHP 复检与部署状态以本轮最终提交为准。
- 修复任务中心“一键写邮件”打开后无法关闭：根因是邮件模块事件以前只在切换到邮箱页时初始化，从任务中心直接打开弹窗没有绑定关闭/取消事件。`openCompose()` 现强制幂等初始化邮件模块，并补充原生 dialog `cancel`（Esc）关闭。
- 按钮契约检查已覆盖跨模块初始化、右上角关闭、底部取消和 Esc 四条路径；JavaScript 语法及差异检查通过。当前自动化环境没有可连接的登录态浏览器窗口，未伪报线上人工点击。
- 报价跟进第二阶段已完成：跟进表单可从当前客户最近80封邮件中选择绑定邮件，可一次上传多张线下微信/电话/拜访沟通截图（jpg/png/webp/gif，单张不超过10MB）。
- 新增 `crm_quote_followup_files`，截图与具体跟进流水永久关联；跟进历史展示绑定邮件和截图入口。
- 报价跟进任务详情会自动拉取并展示完整跟进时间线；新增“一键写邮件”，自动预填报价号、联系人邮箱和正文，邮件实际发送成功后自动生成邮件跟进流水、绑定任务并设置三天后跟进。
- 任务详情新增“生成派工”，可选择执行人和截止时间，生成的派工自动关联 CRM 任务、报价和客户。
- 修改文件：`crm_task_center.php`、`crm_api.php`、`assets/crm/crm.js`、`WORK_CONTEXT.md`；未触碰用户已有的商务中心删除及未跟踪文档。
- 检查/部署：本地 JavaScript 语法和差异检查通过；服务器两份 PHP 语法通过，`crm_quote_followup_activities.mail_id`、`crm_quote_followup_files` 均已真实建立，`uploads` 目录可写。功能提交 `29eb76f` 已推送 GitHub并快进同步服务器；最终上下文提交后再次核对三方一致。
- 已按用户要求一次性实施报价跟进闭环：保留现有报价流程节点图，将“回复”升级为“报价跟进”，显示待首次跟进、已跟进等待回复、客户已回复、最近及下次跟进摘要。
- 新增 `crm_quote_followup_activities` 永久跟进流水，支持线上/线下、邮件/微信/WhatsApp/线上会议/电话/拜访/展会等渠道、联系人、沟通结果、下一步计划、下次时间、客户明确回复及附件/链接说明。
- “写跟进”“设置下次跟进”“标记客户已回复”统一打开报价跟进工作区；保存后同步更新或创建报价跟进任务、客户时间线和流程节点，填写下次时间会继续保留待办，客户明确回复且无下次安排时完成任务。
- 任务中心同时读取旧 `quote_orders` 和新版 `cc_quotes`；新版已审核/已发送报价自动生成跟进任务并进入同一流程中心。
- 修改文件：`crm_task_center.php`、`crm_api.php`、`assets/crm/crm.js`、`assets/crm/crm.css`、`WORK_CONTEXT.md`。未触碰商务中心既存删除及未跟踪文档。
- 首次服务器数据库验证发现 `cc_quotes` 与 `crm_tasks` 的字符排序规则不同；新版报价 ID 关联已显式统一为 `utf8mb4_unicode_ci` 后重新进入完整检查流程，未在服务器直接修补。
- 检查结果：本地 `node --check assets/crm/crm.js`、`git diff --check` 通过；服务器 `crm_task_center.php`、`crm_api.php` PHP 语法通过，真实执行建表及报价流程汇总成功，`crm_quote_followup_activities` 已建立并读取 35 条现有报价流程记录。当前生产库没有非测试新版 `cc_quotes` 报价，因此新版记录数为 0，但兼容查询及自动建任务路径已加载通过。
- Git/部署：功能提交 `03a9edd` 与排序规则修复 `94d5643` 已推送 GitHub并快进同步服务器；最终上下文提交后再次核对本地、GitHub、服务器三方一致。
- 只读核对现有报价与 CRM 任务中心：`crm_tasks` 已支持 `quote_followup`，旧 `quote_orders` 审核通过且未转订单时会自动生成报价跟进任务，默认 1 天提醒、3 天到期。
- CRM 报价流程现有客户未回复、写跟进、标记客户回复、设置下次跟进、创建商机等入口；客户跟进表和客户时间线可承接每次沟通记录。
- 建议在现有任务底座上补充结构化跟进活动：线上/线下分类、邮件/微信/WhatsApp/电话/拜访等渠道、联系人、结果、下一步与下次提醒，并补齐新报价主链 `cc_quotes`，不另建孤立任务系统。
- 用户复核入口后进一步确认：此前描述的是待实施方案，并未新增上述线上/线下跟进界面。当前已有入口是“CRM → 任务与提醒 → 报价订单流程中心”；现有自动报价跟进主要读取旧 `quote_orders`，使用新版 `cc_quotes` 报价时可能看不到对应记录或任何界面变化。
- 基于现有报价流程节点图的实施方向：保留现有节点布局，将“客户回复”节点扩展为报价跟进工作入口；点击后使用侧边抽屉记录线上/线下渠道、联系人、沟通结果、备注、附件及下次跟进时间，保存后写入统一跟进流水并生成或更新下一条任务提醒。流程节点只显示汇总状态和最近/下次跟进，详细历史进入抽屉查看；收到客户明确回复后再完成该节点并继续订单节点。
- 本次未修改业务代码或数据库，仅更新 `WORK_CONTEXT.md`；方案待用户确认实施范围。检查不适用，服务器部署情况与 Git 推送状态以本轮上下文提交为准。

## 本次：超过具体截止时间整行浅红提醒

- 未完成且未取消的待办一旦超过具体 `due_at` 时刻，前端优先使用后端实时 `due_state=overdue`，不再继续显示为“今日到期”。
- 电脑表格整行和手机任务卡统一使用浅红色 `#fff1f2` 填充；完成或取消后自动恢复完成态颜色。
- 修改文件：`dispatch_next.php`、`WORK_CONTEXT.md`。检查、提交、推送、部署与三方一致性以本轮最终结果为准。
- 功能提交 `f1b9c3f` 已推送 GitHub、同步服务器并通过本地 JavaScript、差异及服务器 PHP 语法检查；上下文收尾提交后再次核对三方一致。

## 本次：派工截止日期当天锁定负责人修改

- 截止日期当天 00:00 起，负责人不能再修改截止日期，只有该派工创建人可以修改；截止日期前一天及更早，负责人仍可修改。
- 后端 `update_task` 与 `update_cell` 统一强制校验；管理员或全局编辑权限也不能绕过“只有创建人”的截止日期规则。
- 普通任务列表与详情弹窗读取后端 `can_change_due_at`：无权时列表日期不再打开日历，详情日期控件禁用并显示规则说明。
- 修改文件：`dispatch_next_api.php`、`dispatch_next.php`、`WORK_CONTEXT.md`。检查、提交、推送、部署与三方一致性以本轮最终结果为准。
- 功能提交 `d0519b1` 已推送 GitHub 并同步服务器；本地 JavaScript、两份 PHP 语法、差异检查均通过。同期物料中心提交随主分支一并快进，未修改其内容；上下文收尾提交后再次核对三方一致。

## 本次：电源页统一单条 / 批量编辑

- 用同一套电源业务抽屉替换原前端假详情和旧通用新建弹窗：新建、点击单条、草稿编辑均使用分区表单，不使用表格式弹窗。
- 单条字段覆盖基本资料、功率与输入、恒流/恒压、多输出电流与默认电流、安装方式与尺寸、多调光与主调光、认证、供应商质保、MOQ、采购价、币种和交期；供应商质保与客户整灯质保保持分离。
- 批量设置采用逐项启用的业务卡片，支持只填空值/覆盖已有值、影响预览、跳过统计、事务执行、批次日志和回滚；未启用字段不修改。
- 电源页删除“更多”下拉，改为明确的整理、导入、导出、日志和视图设置入口；旧 BOM 来源仍只读，可从同一抽屉查看并进入标准化整理。
- 新增：`app/Services/PowerEditorService.php`、`api/v1/power-editor.php`、`assets/js/power-editor.js`、两份专项测试；修改电源工作区、公共底部布局、CSS、菜单契约和物料中心执行/测试文档。
- 检查：JavaScript 语法、UI static、UI contract/read-only safety、`git diff --check` 通过；相关 PHP 文件已通过服务器解释器标准输入只读语法检查；两份专项契约测试在服务器 `/tmp` 临时副本通过。浏览器运行时无可用会话，未伪报登录态点击验证。
- 首次服务器真实集成测试发现并阻止了批量目标 ID 被 `p.*` 同名列覆盖的问题；失败测试已自动清理数据，服务改用唯一 `target_material_id` 后进入全量复测。
- 部署与集成测试：业务提交 `f1fed2f`、诊断测试 `722f95f`、批量目标修复 `0f4db1f` 已推送 GitHub并快进同步服务器；真实新建、单条保存、多电流、多调光、两条批量预览/执行/回滚全部通过，测试数据自动清理；最终业务/数据/权限集成和 10k 分页回归通过。
- 未触碰：左侧菜单、旧 BOM 表、旧系统业务及现有商务中心/派工未提交文件。

## 本次：派工详情合并项目与详细说明

- 根据用户复核意见收窄范围：任务标题字段的结构、内容和位置保持原样，只在普通派工详情弹窗移除独立“详细说明”编辑框。
- 项目内容编辑框高度由 112px 扩大到 217px，完整包含原详细说明文本框、字段标题和间距所占区域；项目仍按原有 `project` 字段独立保存，不再合并、迁移或清空历史 `description` 数据，不修改多人派工详情展示。
- 根据用户截图进一步明确目标：任务标题行保持顶部原位置和原尺寸；项目内容行改为弹性填满左侧剩余高度，底部与右侧最下方“关联系统 / 关联标题”字段底部对齐，不再只使用固定高度。
- 修改文件：`dispatch_next.php`、`WORK_CONTEXT.md`。
- 前一版功能提交 `8895749` 已由纠正提交 `13017f8` 收窄：页面 JavaScript 经本地 Node 语法检查通过，`git diff --check` 通过，服务器 `php -l dispatch_next.php` 通过。
- Git/部署：`13017f8` 已推送 GitHub 并快进同步服务器；上下文收尾提交后再次核对三方一致。
- 标题行固定修正提交 `c6aa522` 已推送 GitHub、同步服务器并通过 PHP 语法复检；本轮上下文收尾提交后再次核对三方一致。
- 项目内容框完整高度修正提交 `881a378` 已推送 GitHub、同步服务器并通过本地 JavaScript及服务器 PHP 语法检查；上下文收尾提交后再次核对三方一致。
- 截图对齐修正提交 `2dfff8f` 已推送 GitHub、同步服务器并通过本地 JavaScript、差异及服务器 PHP 语法检查；项目框现按右栏实际高度自动伸展，最终上下文提交后再次核对三方一致。

## 本次：统一登录密码重置入口定位

- 只读确认入口为主页“用户权限中心” → “用户审核”，对应 `users.php`；在 Amy 用户行最右侧“操作”栏填写“新密码”并点击“重置密码”。
- 操作要求当前管理员具备 `users.reset_password` 权限；新密码必须满足系统密码强度规则，重置后 Amy 的 `force_password_change` 会设为 1。
- 本次未执行密码重置，未修改账号或生产数据库；仅更新 `WORK_CONTEXT.md`。

## 本次：Amy 账号密码存储方式核查

- 只读检查统一登录认证和管理员重置流程，确认 `crm_users` 仅保存由 PHP `password_hash(..., PASSWORD_DEFAULT)` 生成的单向 `password_hash`，登录通过 `password_verify()` 校验，不保存可读取的明文密码。
- 结论：Amy 的现有密码无法查询或还原；如已遗忘，只能由具备 `users.reset_password` 权限的管理员设置新密码，系统会将 `force_password_change` 设为 1。
- 本次未读取或导出密码哈希，未尝试破解密码，未修改账号、密码或生产数据库；仅更新 `WORK_CONTEXT.md`。待确认事项：如需重置密码，需指定新密码或授权生成临时密码。

## 本次：统一登录 Amy 账号临时锁定原因排查

- 只读检查生产环境统一登录用户与 `crm_login_logs`，确认 Amy（肖建青，用户 ID 9）账号状态为 `active`，不是管理员停用或永久锁定。
- 根因：来源 IP `120.230.46.219` 在 2026-07-25 22:50:35 至 22:57:59 连续出现 5 次密码错误，达到 `includes/auth.php` 固定的 5 次阈值；2026-07-26 09:28:50 再次密码错误后失败计数增至 6，并重新设置临时锁定至 09:43:50。
- 发现机制特点：锁定时间到期只允许再次验证，不会自动清零 `failed_login_count`；只有密码验证成功才清零。因此锁定到期后的下一次密码错误会立即再次锁定 15 分钟。
- 本次未修改业务代码、用户账号、密码或生产数据库，未执行解锁；仅更新 `WORK_CONTEXT.md`。待确认事项：如需立即解锁、重置失败计数或调整锁定策略，需另行授权实施。

## 本次：标准报价添加产品改为浏览器原生开关

- 复核发现锚点入口仍被 JavaScript `preventDefault()` 接管，并非真正独立兜底。
- 改为浏览器原生 checkbox + label 控制：顶部/底部添加、关闭和取消均不依赖 JavaScript即可切换右侧配置与报价汇总。
- JavaScript只负责产品数据、默认配置、编辑回填、配置校验和加入明细，不再控制“添加产品”点击是否能够打开。
- 修改文件：`commercial_center_v1/views/quote_standard.php`、`assets/js/quote_center.js`、`assets/css/app.css`、`tests/standard_quote_button_contract.php`、`WORK_CONTEXT.md`。
- 检查：JavaScript/PHP 语法、按钮契约、报价中心回归及安全扫描通过；线上 HTML 已验证顶部添加、底部添加、关闭、取消四个控件均连接同一个原生开关。
- Git/部署：修复提交 `db7d874` 已推送 GitHub 并同步服务器；上下文收尾提交后再次核对三方一致。

## 本次：标准报价“添加产品”原生兜底修复

- 前两轮仍依赖 JavaScript 事件成功挂载，用户现场再次确认点击无响应。
- 顶部和底部“添加产品”改为原生锚点入口；即使 JavaScript 未执行，也会通过 `:target` 直接打开右侧产品配置并隐藏报价汇总。
- JavaScript正常时继续负责产品数据加载、配置校验、加入报价及关闭后恢复汇总；移除会与原生锚点冲突的 `hidden` 状态。
- 按钮契约测试增加锚点、目标面板和 CSS 原生兜底断言。
- 修改文件：`commercial_center_v1/views/quote_standard.php`、`assets/js/quote_center.js`、`assets/css/app.css`、`tests/standard_quote_button_contract.php`、`WORK_CONTEXT.md`。
- 检查：JavaScript/PHP 语法、按钮契约、报价中心回归及安全扫描通过；线上 HTML 已确认同时包含两个原生添加入口与 `standard-product-config` 目标面板。
- Git/部署：修复提交 `334f731` 已推送 GitHub 并同步服务器；上下文收尾提交后再次核对三方一致。

## 本次：标准报价全按钮扫描与“添加产品”修复

- 用户反馈“添加产品”无法打开；线上文件与缓存版本一致，问题不是旧资源缓存。
- “添加产品”和底部“添加产品”改为独立直接事件绑定，不再依赖整页事件委托；配置取消/关闭同样独立绑定。
- 右栏增加明确的 `is-configuring` 状态、DOM 完整性保护及错误提示；添加/编辑时切换配置，取消/应用后恢复汇总。
- 新增标准报价按钮契约测试，覆盖保存、预览、打印、PDF、Excel、发送、提交审核、添加、批量、编辑、删除、取消和配置应用的按钮及处理器映射。
- 修改文件：`commercial_center_v1/views/quote_standard.php`、`assets/js/quote_center.js`、`assets/css/app.css`、`tests/standard_quote_button_contract.php`、`WORK_CONTEXT.md`。
- 检查：JavaScript/PHP 语法、按钮契约、报价中心回归和安全扫描通过；真实 CRM 客户、产品、配置、价格/BOM、毛利、保存、重开、编辑和提交审核闭环通过；同一不可变快照驱动预览/打印、PDF、Excel和发送附件/审计闭环通过，测试数据自动清理。
- Git/部署：功能提交 `4275382` 已推送 GitHub 并同步服务器；上下文收尾提交后再次核对三方一致。

## 本次：标准报价右栏按编辑状态切换

- 标准报价默认右栏显示报价汇总，不再常驻或浮动显示“产品与配置”遮挡页面。
- 点击“添加产品”或产品行“编辑”时，右栏切换为产品配置；校验应用、取消或关闭后自动恢复报价汇总。
- 编辑已有产品时锁定当前产品并回填其配置选项；新增产品时允许选择产品及配置。
- 保留标准报价回归基线要求的“产品适配规则”文案。
- 修改文件：`commercial_center_v1/views/quote_standard.php`、`assets/js/quote_center.js`、`assets/css/app.css`、`tests/quote_center_regression.php`、`WORK_CONTEXT.md`。
- 检查：JavaScript 语法、PHP 语法、报价中心回归、安全扫描均通过；自动回归已覆盖汇总/配置面板及显示切换函数。浏览器控制会话不可用，未执行自动点击测试。
- Git/部署：功能提交 `95995c4`、兼容文案修复 `4211650` 已推送 GitHub 并同步服务器；上下文收尾提交后再次核对三方一致。

## 本次：修复新建标准报价误带预设产品

- 根因：`views/quote_standard.php` 仍保留旧演示逻辑，初始化时直接截取产品目录前 8 项渲染为报价明细。
- 修复：新建标准报价默认明细改为空；产品目录只在用户点击“添加产品”后作为选择来源。已有报价重新打开时仍由正式 API 按数据库版本恢复，不受影响。
- 回归保护：`tests/quote_center_regression.php` 增加静态断言，禁止标准报价页重新引入产品目录预填逻辑。
- 修改文件：`commercial_center_v1/views/quote_standard.php`、`commercial_center_v1/tests/quote_center_regression.php`、`WORK_CONTEXT.md`。
- 检查：两份 PHP 文件语法通过；报价中心回归与安全扫描通过；线上标准报价页 HTTP 200，显示“共 0 项”，且不存在预设 `standard` 产品行。
- Git/部署：业务修复提交 `7dda2e2` 已推送 GitHub 并快进同步服务器；本轮上下文收尾提交后再次核对三方一致。

## 本次：报价逻辑 Step 9 + Step 10 合并完成

- 用户明确要求合并执行余下两步；新增统一审核队列、风险触发、完整详情、通过/驳回/要求修改/转上级审批及意见日志。
- 审核通过或客户确认报价可事务化转换到正式 `quote_sales_orders/items`，冻结转换快照并建立新旧关联；后续订单修改不反写报价。
- 完全复用旧 PI、CI、Packing List 正式模板入口，只增加字段映射与调用桥；模板源文件未修改。
- 最终迁移、闭环、模板哈希、回归、提交、部署及三方一致性见本轮最终结果。
- 数据库与验收：`015_approval_conversion.sql` 连续执行两次通过；风险触发、上级审批、通过、要求修改、正式订单/明细、单证草稿出货、三份单证快照、新旧关联及 `converted` 状态闭环通过。
- 模板保护：`crm_quote_pdf.php`、`crm_quote_excel.php`、`quote_order_pi_export.php`、`quote_order_doc.php`、`quote_order_pdf.php`、`quote_order_excel.php` 六个正式文件哈希与冻结基线一致，未修改模板。
- 回归与清理：底座、报价中心、安全扫描、审核页面 HTTP 200、未登录 API 401 通过；测试报价/订单/出货/单证全部清理，旧报价35条、旧订单8个。
- Git/部署：主提交 `f10faf9`、转换修复 `bcdc3c3`、旧表写入最小白名单 `d84d732` 已推送并同步；最终收尾提交后再次核对三方一致。

## 本次：报价逻辑十步实施 Step 8 统一输出与发送

- 严格只执行 Step 8，新增不可变输出快照，预览、打印、PDF、Excel和邮件附件统一读取同一报价版本数据。
- 草稿和待审核水印、审核通过无水印；输出文件保存路径、MIME、大小和 SHA-256，邮件保存收件人、抄送、时间、快照、附件和成功/失败日志。
- 三类报价编辑页接入统一预览、打印、PDF、Excel和发送操作；本步不进入 Step 9 或 Step 10。
- 最终迁移、验收、提交、部署和三方一致性见本轮最终结果。
- 数据库与验收：`014_quote_outputs.sql` 连续执行两次通过；同一不可变快照驱动草稿/待审核水印、正式无水印、预览/打印、真实 Chrome PDF、Excel和邮件附件/投递日志的闭环通过。
- 回归与清理：底座、报价中心、安全扫描通过，未登录输出 API 返回 401；测试报价、输出快照、附件/投递记录及物理测试文件全部清零，旧 `quote_orders` 保持 35 条。
- Git/部署：Step 8 主提交 `743ebb8` 和测试物理文件清理修复 `d6029e4` 已推送 GitHub并同步服务器；最终收尾提交后再次核对三方一致。

## 本次：报价逻辑十步实施 Step 7 定制品报价

- 严格只执行 Step 7，建立正式定制品报价服务、独立 API 和编辑页，修复 `custom&editor=1` 仍进入报价看板的问题。
- 基础信息、自由定制项、自定义字段、标准产品参考、目标/估算成本、毛利与核价/审核意见均进入统一报价版本和快照。
- 整单与明细附件使用既有正式附件表，新增排序元数据和项目/订单交接快照；上传限制类型、大小并保存 MIME、哈希和随机路径。
- 本步不进入 Step 8，不开发统一预览、PDF、Excel 或邮件发送。最终验收、提交、部署和三方一致性见本轮最终结果。
- 附件版本完整性：草稿再次保存和提交审核克隆版本时，明细附件均复制关联到新版本，重新打开不会丢附件；修复提交 `e44ee54`。
- 数据库与验收：`013_custom_quote_files.sql` 连续执行两次通过；真实 CRM 客户/参考产品、两项自由定制、金额/成本/毛利、编辑重开、整单/明细附件、跨版本继承、审核及项目/订单交接快照闭环通过。
- 回归与清理：底座、报价中心、安全扫描通过；定制编辑页 HTTP 200，未登录 API HTTP 401。测试报价及交接记录均清零，旧 `quote_orders` 保持 35 条。
- Git/部署：Step 7 主提交 `c31fa65` 和附件继承修复 `e44ee54` 已推送 GitHub并以同一提交快进同步服务器；最终收尾提交后再次核对三方一致。

## 本次：报价逻辑十步实施 Step 6 网站订单报价

- 严格只执行 Step 6，新增 `012_website_quote_import.sql`，建立网站订单完整来源快照和锁定字段解锁申请表；渠道订单号与幂等键双重去重，只创建 `cc_*` 表。
- 新增 `WebsiteQuoteRepository`、`WebsiteQuoteService` 和 `api/v1/website_quotes.php`：支持新加坡待审核订单正式载荷导入、业务员代客户建立、重复导入返回原报价、完整来源快照及哈希。
- 网站报价默认锁定型号、SKU、配置、原始数量、来源行和客户原始要求；审核可调整价格、折扣、运费、交期、付款和贸易条款。锁定字段必须申请解锁、填写原因、经 `edit_locked` 权限批准并一次性消费。
- 导入/代建自动进入 `pending_approval`；审核调整保存新版本，可审核通过或填写原因驳回，全部复用 Step 4 状态、版本、快照、审批和审计。
- 新增 `tests/website_quote_closure_smoke.php` 和 `docs/quote_logic/step06_website_order_quotes.md`。新加坡实时 API 仍明确为 `not_configured`，不伪造外部同步。
- 网站订单报价页已连接正式 API：支持真实 CRM 客户与网站销售产品选择、鉴权载荷导入、业务员代下单、重新打开、审核价格/折扣/运费/交期/条款、通过、驳回及数量解锁申请；不再使用页面演示数据或本机草稿。
- 本步不进入 Step 7，不开发定制品文件上传或真实转订单。最终迁移、验收、提交、部署和三方一致性见本轮最终结果。
- 数据库与验收：`012_website_quote_import.sql` 连续执行两次通过；导入幂等、完整快照、锁定拦截、批准解锁、审核调整、通过/驳回和业务员代下单全链路通过。测试数据已清理，`cc_quotes` 测试记录为 0，旧 `quote_orders` 保持 35 条。
- 回归与路由：商务中心底座、报价中心回归、安全扫描通过；网站报价页面 HTTP 200，未登录 API HTTP 401。
- Git/部署：Step 6 主提交 `0463857` 已推送 GitHub并以同一提交快进同步服务器；服务器 GitHub SSH 凭证不可用，部署改用已核对与 `origin/main` 一致的 Git bundle，不涉及服务器直接编辑。最终收尾提交后再次核对三方一致。

## 本次：报价逻辑十步实施 Step 5 标准品报价完整闭环

- 严格只执行 Step 5，新增 `StandardQuoteRepository`、`StandardQuoteService` 和 `api/v1/standard_quotes.php`，串联 CRM 客户/联系人、真实产品目录、配置适配规则、价格策略/客户等级/阶梯价、BOM 成本、佣金提醒及 Step 3/4 报价工作流。
- 重建现有标准品报价工作区的数据绑定，不改左侧菜单或 Header：正式保存替换本机草稿，支持保存后重新打开、继续编辑、配置校验、拖动排序、批量数量/折扣、固定汇总、MOQ/交期/佣金提醒和提交审核。
- 服务器不信任浏览器汇总；保存时重新读取客户、产品、配置、价格、成本和佣金并计算金额/毛利。手工改价受 `edit_price` 权限控制，POST 使用独立 CSRF。
- 新增 `tests/standard_quote_closure_smoke.php` 和 `docs/quote_logic/step05_standard_quote_closure.md`，用真实 CRM 客户、真实产品和真实配置/价格/BOM 来源完成可自动清理验收。
- 本步不进入 Step 6，不开发网站订单导入、定制附件或正式 PDF/Excel/邮件/转订单。
- 最终验收、提交、部署和三方一致性见本轮最终结果。
- 回归修复：旧报价中心回归要求标准品页面保留精确关键词“产品适配规则”，已补回兼容文案；修复提交 `7513e80` 已推送并部署。
- 验收：真实 CRM 客户、真实产品、配置护照、价格/BOM、金额/毛利、保存、重开和提交审核全链路通过；API 未登录返回 401，标准品页面返回 200。测试数据已清理，`cc_quotes` 测试记录为 0，旧 `quote_orders` 保持 35 条。
- 数据库/路由：本步无新数据库迁移；新增正式 API `api/v1/standard_quotes.php`，旧 URL 和页面路由不变。
- Git/部署：Step 5 主提交 `ac0c7a7` 及回归修复 `7513e80` 已推送 GitHub并同步服务器；最终收尾提交后再次核对本地、`origin/main`、服务器 HEAD 和 Step 5 文件哈希一致。

## 本次：报价逻辑十步实施 Step 4 状态流、权限、日志和版本快照

- 严格只执行 Step 4，新增统一状态机，覆盖 `draft`、`pricing`、`pending_approval`、`rejected`、`approved`、`sent`、`customer_confirmed`、`converted`、`voided`；非法跨状态拒绝，驳回和作废强制原因。
- 新增 `QuotePermissionService`，接入统一 ERP `commercial.quote.*` 权限并兼容旧 `quote_user_permissions`，覆盖查看、新建、编辑、删除、审核、驳回、导出、打印、发送、转订单、查看成本/利润、改价和修改锁定字段。
- 新增幂等迁移 `011_quote_workflow.sql`，建立独立审批、状态历史和详细审计表；记录操作人、时间、IP/User-Agent 哈希、报价编号/类型、对象、修改前后值和原因。
- 新增 `QuoteWorkflowService` 与 `QuoteWorkflowRepository`：提交、批准、发送和转订单前复制版本并生成正式快照；已审核修改前保留快照并保存为新草稿版本；历史版本可读取。
- 新增 `tests/quote_workflow_smoke.php` 和 `docs/quote_logic/step04_workflow_permissions_snapshots.md`。本步不进入 Step 5，不接标准品页面闭环、正式导出、发送或真实转订单。
- 最终迁移、验收、提交、部署和三方一致性见本轮最终结果。
- 安全修复：首次服务器安全扫描正确拦截动态 `cc_*` 表名 SQL；已全部改为明确固定表名并通过扫描，修复提交 `99ea0ef` 已推送和部署。
- 数据库与验收：`011_quote_workflow.sql` 连续执行两次通过并登记 SHA-256；无权限状态变化被拒绝，完整状态流、审批、审计、正式快照、已审核修订和历史版本验收通过。测试数据已清理，`cc_quotes` 测试记录为 0，旧 `quote_orders` 保持 35 条。
- Git/部署：Step 4 主提交 `5a4291e` 及安全修复 `99ea0ef` 已推送 GitHub并同步服务器；同期主分支包含物料中心独立提交，本轮未编辑或覆盖其内容。最终收尾提交后再次核对本地、`origin/main`、服务器 HEAD 和 Step 4 文件哈希一致。

## 本次：报价逻辑十步实施 Step 3 统一报价公共数据模型

- 严格只执行 Step 3，沿用现有空的 `cc_quotes`、`cc_quote_versions`、`cc_quote_items`、`cc_quote_item_snapshots` 作为唯一新报价主链，不建立第三套主表；旧 `quote_orders` 保持只读兼容。
- 新增幂等迁移 `commercial_center_v1/database/migrations/010_unified_quote_model.sql`，补齐报价头扩展、明细扩展、报价附件、明细附件、报价版本快照及旧报价映射六张 `cc_*` 表；不修改任何旧表。
- 新增统一 `QuoteService`、`QuoteRepository`、`QuoteAmountCalculator`、`QuoteNumberService`：三种报价共用保存、重新打开、编辑、金额/成本/毛利计算、编号、版本、快照和日志能力；网站订单强制保存来源订单及来源行快照并锁定。
- 新增 `tests/quote_model_smoke.php`，用于验证网站订单、标准品、定制品三种测试报价可保存并重开，标准品可编辑为版本 2，旧报价仍可读取；测试数据结束后从 `cc_*` 表清理。
- 新增 `docs/quote_logic/step03_unified_quote_model.md` 记录表映射、服务边界、保存规则、旧数据兼容和验收方法。本步不接页面保存 API，不执行 Step 4 的状态流、权限和正式审核快照。
- 修改文件与最终迁移、检查、提交、部署及三方一致性结果见本轮最终结果。
- 数据库迁移：生产库历史缺少既有 `007_commercial_foundation.sql`，已先按白名单补齐并重复执行验证；随后执行并重复验证 `010_unified_quote_model.sql`。两项迁移均登记 SHA-256 和 `applied` 状态，仅创建 `cc_*` 表，无旧表结构或旧数据修改。
- 验收：三种报价保存/重开通过，标准品编辑后版本 2 可读，旧报价兼容读取通过；验收测试数据已自动清理，`cc_quotes` 测试记录为 0，旧 `quote_orders` 前后保持 35 条。底座、产品目录、报价中心回归与安全扫描全部通过。
- Git/部署：Step 3 业务提交 `b2f89418e` 已推送 GitHub 并以同一提交快进同步服务器；本轮收尾提交后再次核对本地、`origin/main`、服务器 HEAD 和 Step 3 文件哈希一致。

## 本次：报价逻辑十步实施 Step 2 现有系统审计

- 严格只执行 Step 2，新建 `commercial_center_v1/docs/quote_logic/step02_existing_audit.md`；只读审计报价相关数据表与字段、商务中心及旧报价路由/API、保存/编辑/审核/价格/BOM/佣金/转订单能力，以及正式 PI、人民币订购合同、Commercial Invoice、Packing List 的 PDF、打印和 Excel 入口。
- 确认正式报价数据仍由旧 `quote_*` 模型承载：审计时 `quote_orders` 35 条、`quote_sales_orders` 8 条、`quote_shipments` 2 条、`quote_logs` 7705 条；预建 `cc_quotes`、`cc_quote_versions`、`cc_quote_items`、`cc_quote_item_snapshots` 均为空。后续必须先制定兼容迁移方案，不得重复建立第三套报价模型。
- 正式 PI/订购合同模板定位为根目录 `crm_quote_pdf.php` 和 `crm_quote_excel.php`；正式 CI/PL 定位为 `quote_order_doc.php` 和 `quote_order_excel.php`，并保留现有兼容桥。商务中心 `modules/documents` 的 `legacy_v1` 明确是演示模板，不得替代正式模板。
- 记录旧系统硬删除、运行时改表、JSON 明细/审核快照、定制编辑路由、报价发送和附件规范化等风险；本步未调用任何写数据库动作，未修改业务代码、数据库、菜单、UI、路由、接口或旧报价数据，未进入 Step 3。
- 修改文件：新增 `commercial_center_v1/docs/quote_logic/step02_existing_audit.md`，更新 `WORK_CONTEXT.md`；最终检查、提交、部署和三方一致性见本轮最终结果。
- 检查与部署：文档必需章节、空格错误、临时审计文件清理和只读边界检查通过；审计提交 `d8a6da78f5176cd9bf68f4a27a595e412855f825` 已推送 GitHub，并以同一提交快进同步服务器。服务器复检文档关键模板定位、Git 差异和文件哈希通过；本轮收尾提交完成后再次核对本地、`origin/main` 与服务器 HEAD 一致。

## 本次：报价逻辑十步实施 Step 1 UI 冻结

- 已完整读取工作区实际存在的 `commercial_center_v1/docs/Artdon_商务中心_报价逻辑十步实施说明(1).md`；用户指定的不带 `(1)` 文件名当前不存在。
- 严格只执行 Step 1，新建 `commercial_center_v1/docs/quote_logic/step01_ui_freeze.md`，记录完整菜单、产品报价双列菜单、页面/路由映射、旧新入口映射、冻结区域、允许修改区、不允许修改组件、报价页面与路由清单、回归测试和冻结文件 SHA-256 基线。
- 本步未修改菜单、Header、页面 UI、路由、接口、数据库结构或报价数据；未开发保存逻辑，未进入 Step 2。
- 发现并记录报价模板/阶梯价格仍为占位页、适配规则直接/旧入口分叉、定制编辑路由未落到独立编辑页、展示数据、本机草稿及正式报价 API 缺失等问题，留待收到明确指令后在后续步骤处理。
- 检查：冻结 UI 文件零差异、文档必需章节、文件哈希、报价中心回归、底座冒烟和安全扫描通过；六个产品报价入口、四种报价入口及四个旧入口均返回 HTTP 200 且无 PHP 错误。
- 修改文件：新增 `commercial_center_v1/docs/quote_logic/step01_ui_freeze.md`，更新 `WORK_CONTEXT.md`；最终检查、提交、部署和三方一致性见本轮最终结果。

## 本次退出记录

- 用户要求退出，本轮停止继续修改。
- 最近完成的 CRM 拜访/来访修复包括保存附件兼容、重复数据软删除、记录删除能力、列表布局、ACTIONS、图标模式、双击打开，以及客户名称/代码放大；相关业务提交均已推送并部署。
- 本次退出仅更新 `WORK_CONTEXT.md`，不纳入或覆盖工作区现有商务中心、文档及启动入口的无关改动。
- 最终退出记录提交、GitHub 推送、服务器同步及三方一致性见本轮最终结果。

## 本次：报价单中心超宽屏左右留白修复

- 根因：全局 `@media(min-width:1900px)` 将 `.content` 限制为 `1840px` 并居中；报价中心仅覆盖了内边距和高度，未覆盖最大宽度与自动外边距，因此超宽屏左右出现空白。
- 报价中心内容容器显式设置 `width:100%`、`max-width:none`、`margin:0`，从菜单右侧到浏览器右边完整铺满；页面内部按参考图保留必要的 20px 组件间距。
- 修改文件：`commercial_center_v1/assets/css/app.css`、`WORK_CONTEXT.md`；最终检查、提交、部署和三方一致性见本轮最终结果。

## 本次：报价单中心正式入口切换为完整截图版

- 根因：完整截图版工作台已经存在，但只挂在 `quote_mode=custom` 分支；正式地址 `page=quote_center` 仍渲染旧简化列表，导致内容不满屏、筛选项不足，并缺少“快速开始”和“帮助与支持”。
- 将 `page=quote_center` 默认入口直接切换到完整报价工作台：六块状态看板、完整筛选、分类标签、报价列表、底部分页、右侧快速开始及帮助支持全部在正式地址显示。
- 保留现有菜单配置和三类报价编辑路由不变；增加默认入口回归断言，防止再次退回旧简化页面。
- 修改文件：`commercial_center_v1/views/quote_center.php`、`tests/quote_center_regression.php`、`WORK_CONTEXT.md`；最终检查、提交、部署和三方一致性见本轮最终结果。

## 本次：指定 custom URL 按最新报价中心截图重做

- 用户明确指定 `index.php?page=quote_center&quote_mode=custom`，要求该页面按最新上传的“报价单中心”截图实现，不再显示此前定制品编辑页。
- 主工作区整体重建为截图结构：标题图标与说明、导入/导出/帮助/+新建报价单、6 张状态统计卡、完整搜索与 5 类筛选、4 个报价类型 Tab、10 行报价列表、状态标签、轻量文字操作、底部分页，以及右侧“快速开始”“帮助与支持”两组卡片。
- 页面保留现有正式商务中心 Header、侧栏和其他菜单；仅修改指定 URL 的内容区。
- 新建报价单按钮继续打开类型选择弹窗；网站订单、标准品、定制品及审核快捷入口保留路由。
- 修改文件：`commercial_center_v1/views/quote_custom.php`、`assets/css/app.css`、`tests/quote_center_regression.php`，以及本文件。
- 检查：PHP 语法、`git diff --check`、报价中心回归、底座与安全扫描通过；服务器以 `novlight.com` Host 实测指定 URL 返回 HTTP 200、无 PHP 错误，实际输出 10 条报价、6 张统计卡、5 个筛选下拉、4 个 Tab、4 个快速入口、2 个帮助入口，带版本号 CSS 返回 HTTP 200。当前会话仍无可用内置浏览器实例，无法生成线上截图做像素级视觉验收。
- 数据库/权限：无数据库、权限表或正式报价数据修改。
- Git/部署：业务提交 `cb29d8a` 已推送 GitHub并以同一提交快进同步服务器；不纳入现有无关改动。最终收尾提交及三方一致性见本轮最终结果。

## 本次：三类报价页面第二次纠偏与完整视觉数据

- 用户再次确认此前页面与参考图差距过大，并授权本轮全过程自行判断、不再中途确认。
- 根因复核：未登录 HTTP 验收时目录服务按权限返回空产品，三张页面退化为空白占位行，无法形成参考图中的高密度工作台；上一版又混用了动态日期和空字段，视觉对照失真。
- 本轮为三张页面增加只读预览回退数据：使用服务器真实产品目录中的 8 个真实型号、名称和网站图片 URL；仅在目录不可读/为空时用于撑起界面预览，不写数据库、不作为正式报价记录。
- 网站订单页按图补齐固定网站订单号、内部报价号、客户、联系人、业务员、日期、数量、配置和价格明细；标准品页补齐 8 项数量、配置、CNY 价格、折扣与备注；定制品页补齐 5 项自定义名称、完整规格、数量、目标价、报价价和备注。
- 标准品配置浮窗定位改为相对报价页面，避免随整个文档漂移；JavaScript 模块解析通过，PHP 语法和 `git diff --check` 通过。
- 修改文件：`commercial_center_v1/views/quote_center.php`、`views/quote_website.php`、`views/quote_standard.php`、`views/quote_custom.php`、`assets/css/app.css`，以及本文件。
- 数据库/权限：无数据库、权限表或正式报价数据修改。
- Git/部署：纠偏提交 `e1b2add` 已推送 GitHub并以同一提交快进同步服务器；服务器复检中网站/标准品/定制品页面分别输出 6/8/5 张真实产品图及完整明细行，三页均 HTTP 200、无 PHP 错误，参考订单号、客户、配置窗口和上传区关键词全部命中。当前无可用内置浏览器实例，截图级像素验收无法执行；最终收尾提交及三方一致性见本轮最终结果。

## 本次：三类报价页面按三张参考图分别重建

- 用户否定上一版通用报价编辑框架，要求三张参考图各自建立；本轮保留已完成的正式商务中心 Header、侧栏和 6 项产品报价菜单，仅彻底重建三类报价主工作区。
- 网站订单报价页独立实现：标题与来源、驳回/审核/转订单操作、五步审核流程、网站快照锁定提示、四栏订单资料、锁定产品明细表、右侧报价汇总、网站客户备注、内部审核备注和风险提醒。
- 标准品报价页独立实现：半自由报价资料区、8 行密集标准品明细、真实产品目录图片/型号接入、固定右侧汇总与 MOQ/交期/佣金提醒、底部内部流程，以及按参考图默认展开的产品选择与合法配置窗口。
- 定制品报价页独立实现：顶部操作工具栏、三列报价信息、产品图/参考图/尺寸图/客户文件四块上传区、高自由自定义产品明细、右侧自定义字段、可选参考产品、报价汇总、工程说明和底部内部流程。
- 修改文件：`commercial_center_v1/views/quote_center.php`、`assets/css/app.css`、`tests/quote_center_regression.php`；新增 `views/quote_website.php`、`views/quote_standard.php`、`views/quote_custom.php`，以及本文件。
- 检查：6 个 PHP 文件经服务器 PHP 8.0 标准输入语法检查通过，`git diff --check` 通过；部署后三张页面均返回 HTTP 200、无 PHP 错误，页面专属结构关键词零缺失、专属 CSS 资源返回 200；`quote_center_regression.php`、`bootstrap_smoke.php`、`safety_scan.php` 全部通过。当前仍无可用内置浏览器实例，无法完成截图式视觉验收。
- 数据库/权限/路由：无数据库、权限表和旧数据修改；沿用上一版三类报价路由、旧 URL 映射及现有权限边界。
- Git/部署：重建提交 `a9a86c3` 已推送 GitHub，并通过同一提交 Git bundle 快进同步服务器；未纳入现有启动脚本删除/重命名、未跟踪文档或服务器物料中心并行改动。最终收尾提交及三方一致性见本轮最终结果。

## 本次：商务中心报价单中心与三类报价页面

- 仅重组“产品报价”菜单为固定双列 6 项：报价单中心、报价产品库、报价模板、价格策略、阶梯价格、报价审核；其他菜单分组未修改。
- 报价单中心默认显示统一历史报价列表，右上角新增“+ 新建报价单”，通过两层弹窗选择网站订单、标准品或定制品报价并填写基础资料；标准品提供快速创建模式。
- 完成三类共享报价编辑框架：网站订单保留锁定快照与审核流程提示；标准品读取真实报价产品目录并提供芯片、电源、光学、调光、配件和冲突/审批规则配置入口；定制品支持自由明细、自定义字段及多类图片/文档上传选择。
- 明细支持动态增删、数量/价格/折扣/汇总计算、50 项以上内部滚动和固定表头；右侧汇总固定显示折扣、运费、税费、总金额及风险提醒。
- 报价审核保留独立统一队列及报价类型、负责人、客户、提交时间、风险等级、审核状态筛选，不新增第四套详情页。
- 旧入口映射：标准报价→标准品报价；快速报价→标准品快速创建；历史报价→报价单中心列表；产品与配置→物料与配件/产品适配规则。旧路由名仍由入口层接收，不删除旧页面、接口或数据。
- 修改文件：`commercial_center_v1/config/menu.php`、`index.php`、`assets/css/app.css`；新增 `assets/js/quote_center.js`、`views/quote_center.php`、`views/quote_approval.php`、`views/compatibility_rules.php`、`tests/quote_center_regression.php`，以及本文件。
- 数据库/权限：无数据库结构或数据修改；沿用现有统一登录、`commercial_center.view` 读取权限及既有审核/导出权限模型，不写旧权限表。
- 检查：`git diff --check` 通过；5 个修改/新增 PHP 文件经服务器 PHP 8.0 标准输入语法检查通过。部署后 `quote_center_regression.php`、`bootstrap_smoke.php`、`safety_scan.php` 全部通过；服务器 HTTP 实测报价中心、三类编辑页、审核页及标准报价/快速报价/历史报价/产品与配置 4 个旧 URL 均返回 200、无 PHP 错误，产品报价菜单唯一入口计数为 6，报价交互脚本资源返回 200。当前会话无可用内置浏览器实例，未完成截图式视觉验收。
- Git/部署：业务提交 `e375a42` 已推送 GitHub，并通过同一提交 Git bundle 快进同步服务器；未纳入或覆盖现有启动脚本删除/重命名、未跟踪文档及服务器物料中心并行改动。最终收尾提交及三方一致性见本轮最终结果。
- 未完成：正式报价保存、附件上传、审批写入、PDF/Excel/邮件动作仍需后端写入授权与接口契约；本轮提供正式页面、只读数据接入、草稿前端交互与安全路由映射，不伪造正式业务写入。

## 本次：本地、GitHub 与服务器只读扫描

- 已读取现有上下文并扫描本地仓库、刷新 GitHub `origin/main`、只读核对服务器运行目录。
- 本地 HEAD、GitHub `origin/main`、服务器 HEAD 均为 `d7ee10acd972570d889eb138b73ccfabc4ca9536`。
- 本地工作区存在用户/并行改动：商务中心启动脚本 1 项删除及疑似重命名文件、商务中心 7 份未跟踪文档、物料中心 7 份未跟踪文档；本轮未覆盖、未整理。
- 服务器工作区不干净：`material_center_v1/assets/css/app.css`、`assets/js/app.js`、`bootstrap.php`、`index.php` 4 个已跟踪文件内容偏离 HEAD，并有多批 `material_center_v1` 未跟踪目录/文件；本轮未修改、未部署。
- 已跟踪文件只读内容对比确认实际内容差异集中于上述 4 个物料中心文件；其余输出仅为时间戳差异。两个含中文名称的已跟踪启动脚本因 rsync 文件列表引用格式未纳入本次内容比对。
- 检查结果：本地/GitHub/服务器提交号一致，但本地与服务器工作区均非干净状态，当前不能认定三方文件内容完全一致。
- Git/部署：本次仅扫描、未改业务代码；扫描记录提交 `bc0c29a` 已推送 GitHub，并以同一提交快进同步服务器，未覆盖服务器物料中心现有差异。
- 下一步：先确认服务器物料中心 4 个已跟踪差异及未跟踪目录是否为应保留的线上工作，再决定从服务器回收至本地提交，或用 GitHub 已提交版本恢复服务器；不得直接覆盖。

## 本次：产品与配置页面按参考图重做

- 保留商务中心现有菜单栏和顶栏，只重做 `product_config` 内容区。
- 新页面接入真实产品目录及图片，完成三步流程、产品切换、七组配置选项、单选/多选、动态价格明细、A级折扣、MOQ/交期、配置摘要、清空、保存模板与确认加入报价交互。
- 顶栏当前页名称同步显示“产品与配置”，并处理未登录或目录为空时的字段空值，页面不输出 PHP 提示。
- 修改文件：`commercial_center_v1/views/product_config.php`、`assets/js/product_config.js`、`assets/css/app.css`、`index.php`、`WORK_CONTEXT.md`；最终检查、提交、部署和三方一致性见本轮最终结果。

## 本次：价格策略中心主色调整

- 将价格策略中心操作按钮、启用开关、编辑入口、添加入口、当前分页和抽屉操作按钮的青绿色统一替换为系统红色；表格悬停背景同步改为浅红。
- 保留启用状态等具有业务含义的绿色标签，不改动现有菜单栏及其他页面。
- 修改文件：`commercial_center_v1/assets/css/app.css`、`WORK_CONTEXT.md`；最终检查、提交、部署和三方一致性见本轮最终结果。

## 本次：价格策略中心按参考图完整落地

- 保留现有商务中心左侧菜单和顶部框架不变，仅实现“价格策略”内容页。
- 按参考图完成标题、5 项统计、筛选/批量工具栏、真实产品价格策略表格、分页、启用开关、右侧价格策略编辑抽屉、标准价、阶梯价、客户等级价、备注、保存与提交审批交互。
- 产品、图片、分类、状态与 BOM 成本读取真实当前页数据；策略草稿保存在浏览器本地，不写旧系统价格表。
- 修改文件：`commercial_center_v1/views/price_strategy.php`、`assets/css/app.css`、`assets/js/price_strategy.js`、`index.php`、`WORK_CONTEXT.md`。
- 检查：`git diff --check`、CSS 花括号、JavaScript、入口与视图 PHP 语法及菜单未修改检查均通过；业务提交 `1cb7c19` 已推送 GitHub并以同一提交快进同步服务器。
- 服务器复检：价格策略路由、入口/视图 PHP、真实产品目录读取、菜单零差异、Git 差异和工作区状态正常；最终三方提交一致性以本轮收尾提交核对结果为准。

## 本次：产品卡片拉宽后自动补齐修复

- 根因：上一版仅监听浏览器窗口 `resize`，未直接监听产品网格自身宽度；侧栏和布局变化可能漏算。同时 `app.js` 未带资源版本参数，浏览器可能继续使用旧脚本。
- 改为 `ResizeObserver` 直接监听产品网格宽度，宽度变窄或变宽均在稳定 250ms 后按真实列数 × 2 排重新查询；不支持时回退窗口监听。
- 页容量变化时按原首条产品偏移换算新页码，避免直接跳回第一页；`app.js` 增加基于文件修改时间的缓存版本参数，部署后强制获取新逻辑。
- 修改文件：`commercial_center_v1/assets/js/app.js`、`commercial_center_v1/index.php`、`WORK_CONTEXT.md`。
- 检查：`git diff --check`、JavaScript 语法、页容量变化后的首条偏移换算及本地 `index.php` 经服务器 PHP 标准输入语法检查均通过；业务提交 `7893c6a` 已推送 GitHub并以同一提交快进同步服务器。
- 服务器复检：网格 `ResizeObserver`、首条偏移页码换算、`app.js` 文件版本参数、入口 PHP 语法、Git 差异和工作区状态正常；最终三方提交一致性以本轮收尾提交核对结果为准。

## 本次：CRM 图标卡片双击与客户信息强化

- 拜访/来访卡片支持双击打开对应记录编辑窗口；双击卡片内按钮、链接或表单控件不会误触打开。
- 客户代码、客户名称和联系人拆分显示；图标模式下客户代码与名称放大到 16px，代码使用主色强调，联系人保持次级信息。
- 修改文件：`assets/crm/crm.js`、`assets/crm/crm.css`、`WORK_CONTEXT.md`；最终检查、提交、部署和三方一致性见本轮最终结果。

## 本次：报价产品卡片数量随页面宽度自适应

- 根因：卡片列数会随页面宽度变化，但每页固定加载 26 个；缩窄后列数减少，产品被排成三排以上而需要纵向滚动。
- 卡片模式改为读取浏览器实际 CSS 网格列数，以“当前列数 × 2 排”计算每页数量；跨过列数断点后 300ms 自动更新 `size` 并重新查询第一页。
- 服务端页容量放宽为 2–100 的安全范围，仍使用数据库 `LIMIT/OFFSET` 只拉取当前页；工具栏显示当前自动页容量。列表模式不触发卡片自适应。
- 修改文件：`commercial_center_v1/views/product_library_v2.php`、`assets/js/app.js`、`assets/css/app.css`、`WORK_CONTEXT.md`。
- 检查：`git diff --check`、JavaScript 语法、CSS 花括号结构、2/4/7/10/13 列容量算法及本地视图经服务器 PHP 标准输入语法检查均通过；业务提交 `3247131` 已推送 GitHub并以同一提交快进同步服务器。
- 服务器复检：列数 × 2 排算法、自动页容量入口/显示、视图 PHP 语法、Git 差异和工作区状态正常；最终三方提交一致性以本轮收尾提交核对结果为准。

## 本次：CRM 拜访/来访图标模式

- 在拜访/来访工具栏新增“列表 / 图标”显示切换；选择保存在浏览器 `localStorage`，再次进入页面保持上次模式。
- 图标模式使用自适应卡片网格，以“访/来”图标突出业务类型，并完整保留客户、联系人、日期、负责人、状态、后续需求及填结果/派工/删除操作；列表模式保持原样。
- 修改文件：`crm.php`、`assets/crm/crm.js`、`assets/crm/crm.css`、`WORK_CONTEXT.md`；最终检查、提交、部署和三方一致性见本轮最终结果。

## 本次：CRM 拜访页 ACTIONS 与列表空白布局修复

- 截图显示右侧 ACTIONS 为 `[object Object]`：拜访模块动态操作错误返回分组对象，而通用渲染器要求字符串数组；已改为直接返回操作名称数组。
- 截图显示记录被推到页面底部：`visit-main` 实际有工具栏、KPI、视图导航、列表 4 行子元素，但 CSS 只定义 3 行，列表成为隐式末行；已改为 `auto auto auto minmax(0, 1fr)`，记录从导航下方顶部连续排列。
- 修改文件：`assets/crm/crm.js`、`assets/crm/crm.css`、`WORK_CONTEXT.md`；最终检查、提交、部署和三方一致性见本轮最终结果。

## 本次：报价产品库多字段模糊搜索

- 根因：原搜索仅对型号、产品名、系列执行单段 `LIKE`，类别、灯具类型、网页尺寸、开孔及长宽高参数均未参与，多关键词也被当作完整字符串。
- 搜索改为最多 8 个关键词逐词 AND；每个关键词跨型号、产品名、系列、类别、灯具类型、状态、客户、备注、网页尺寸、开孔、外径、长宽高等字段模糊 OR 匹配。
- 搜索框停止输入 450ms 后自动提交，分类选择会保留当前值；数据库分页的总数和当前页使用同一搜索条件。
- 增加真实型号片段模糊查询冒烟检查。
- 修改文件：`commercial_center_v1/app/Repositories/LegacyCatalogReadRepository.php`、`views/product_library_v2.php`、`assets/js/app.js`、`tests/catalog_smoke.php`、`WORK_CONTEXT.md`。
- 检查：`git diff --check`、JavaScript 语法及 3 个修改 PHP 文件的服务器标准输入语法检查均通过；业务提交 `4b86cf8` 已推送 GitHub并以同一提交快进同步服务器。
- 服务器复检：真实数据库产品目录冒烟测试及真实型号片段模糊查询通过，多字段 `CONCAT_WS` 条件、自动提交入口、Git 差异和工作区状态正常；最终三方提交一致性以本轮收尾提交核对结果为准。

## 本次：CRM FPM Fileinfo 兼容与本人记录删除权限修复

- 用户实测 FPM 报 `Call to undefined function finfo_open()`；服务器 CLI 虽有 Fileinfo，但网站 FPM 扩展集不同。附件 MIME 检测改为按 `finfo` → `mime_content_type` → `getimagesize` → 上传类型逐级降级，不再硬依赖 Fileinfo。
- 删除权限由仅管理员拥有的 `visit.delete` 扩展为：管理员、记录创建人或负责人均可删除该记录；前端卡片和右侧 ACTIONS 使用同一判断显示删除入口，后端再次校验。
- 数据复核与清理：旧重复 ID 2–6、8–15 均已软删除；用户再次测试新增 ID 16（已完成来访）和 ID 17（重复草稿），已软删除 ID 17。因 ID 7 与 ID 16 内容相同，最终按截图业务类型保留最新有效来访 ID 16，并软删除旧拜访 ID 7；客户 159 在 2026-07-25 仅剩 1 条有效记录。
- 修改文件：`crm_visit.php`、`assets/crm/crm.js`、`WORK_CONTEXT.md`；最终检查、提交、部署和三方一致性见本轮最终结果。

## 本次：报价产品卡片间距与开孔参数优化

- 产品网格取消 `space-between` 产生的大间隔，改为固定 8px 间距；卡片以 156px 为最小宽度并等分剩余空间，参数区随卡片适度拉长。
- 卡片参数区在现有外径 × 高度尺寸下新增“开孔”一行，空值显示“—”，便于报价时直接查看开孔尺寸。
- 修改文件：`commercial_center_v1/assets/css/app.css`、`commercial_center_v1/views/product_library_v2.php`、`WORK_CONTEXT.md`。
- 检查：CSS 花括号结构、`git diff --check` 和本地视图经服务器 PHP 标准输入语法检查均通过；业务提交 `8ccf6dd` 已推送 GitHub并以同一提交快进同步服务器。
- 服务器复检：固定 8px 间距、自适应拉长列、开孔参数及视图 PHP 语法均通过；最终三方提交一致性以本轮收尾提交核对结果为准。

## 本次：报价产品库每页数量匹配两排

- 用户当前屏幕每排实际显示 13 张，原默认 24 张导致第二排只有 11 张、差 2 张。
- 报价产品库默认改为 26 个/页，选择项同步调整为 13/26/52 个每页；当前宽度下默认显示两排各 13 张，仍仅查询当前页。
- 修改文件：`commercial_center_v1/views/product_library_v2.php`、`WORK_CONTEXT.md`。
- 检查：`git diff --check` 和本地视图经服务器 PHP 标准输入语法检查均通过；业务提交 `ca7d81b` 已推送 GitHub并以同一提交快进同步服务器。
- 服务器复检：默认 26 个/页、13/26/52 分页选项及视图 PHP 语法均通过；最终三方提交一致性以本轮收尾提交核对结果为准。

## 本次：报价产品卡片按截图恢复紧凑尺寸

- 根据用户截图，撤销将卡片按 6–8 个 `1fr` 等分拉宽的布局；卡片恢复为接近产品图宽度的 156px 紧凑规格，宽屏使用 `auto-fill` 自动排列并在整行均匀分布。
- 产品图片区背景改为纯白并移除内边距，不再出现图片两侧大面积浅灰底；图片继续使用 `contain` 完整显示。
- 报价产品库默认改为 24 个/页，并增加 12/24/48 个每页选择；默认宽屏会自然形成第二排，同时仍只从数据库读取当前页。
- 修改文件：`commercial_center_v1/assets/css/app.css`、`commercial_center_v1/views/product_library_v2.php`、`WORK_CONTEXT.md`。
- 检查：CSS 花括号结构、`git diff --check` 和本地视图经服务器 PHP 标准输入语法检查均通过；业务提交 `dff64f6` 已推送 GitHub并以同一提交快进同步服务器。
- 服务器复检：156px 自动填充列、白色图片区、默认 24 个/页及视图 PHP 语法均通过；最终三方提交一致性以本轮收尾提交核对结果为准。

## 本次：CRM 拜访/来访删除能力与故障重复数据清理

- 用户确认此前保存故障和连续点击产生大量重复记录；服务器核对到客户 LF LIGHTING / Mr Han 的同内容记录 ID 2–15，共 14 条且各有 1 条关联任务。
- 新增 `visit_delete` API：校验 `visit.delete` 权限，以事务软删除拜访/来访、附件记录及来源为 `visit` / `visit_action` 的关联任务，保留操作日志和客户时间轴记录。
- 拜访/来访列表卡片新增删除按钮；选中记录后右侧 ACTIONS 正确切换到当前拜访/来访操作并提供编辑、填结果、跟进、派工、删除；客户详情中的“删除记录”也接入同一删除能力。
- 数据清理：已保留 ID 7（已完成、负责人 suk ie 的有效拜访），事务性软删除故障重复 ID 2–6、8–15、13 条主关联任务及 5 条后续动作任务；附件 0，ID 1 为 7 月 13 日另一客户记录未处理。恢复备份位于服务器 `/tmp/crm_visit_duplicate_backup_20260725.json`。
- 修改文件：`crm_visit.php`、`crm_api.php`、`crm.php`、`assets/crm/crm.js`、`WORK_CONTEXT.md`。
- 检查：`git diff --check`、JavaScript 语法、三个 PHP 文件的服务器 PHP 8.0 标准输入及部署后语法检查均通过；业务提交 `f6a78f8` 已推送 GitHub 并以同一提交快进部署服务器。清理后客户 159 在 2026-07-25 仅剩 ID 7 一条有效记录。

## 本次：商务中心产品页统一数据库分页

- 报价产品库和“产品与配置”统一改为服务端分页：数据库先执行匹配总数查询，再使用 `LIMIT/OFFSET` 只读取当前页产品，不再读取全部产品后由 PHP `array_slice`。
- BOM 成本只对当前页型号进行只读补充；产品分类和状态数量使用数据库聚合查询，不加载全部产品明细。
- 产品服务统一返回 `total/page/pages/page_size`；“产品与配置”新增 12/24/48 个每页选择、页码、上一页和下一页，并保留搜索与分类条件。
- 修改文件：`commercial_center_v1/app/Repositories/LegacyCatalogReadRepository.php`、`app/Services/CatalogCenterService.php`、`app/Controllers/DashboardController.php`、`views/product_library_v2.php`、`index.php`、`tests/catalog_smoke.php`、`WORK_CONTEXT.md`。
- 检查：`git diff --check`、6 个修改 PHP 文件语法检查、旧 `allProducts/array_slice` 模式清除检查均通过；业务提交 `b7c74e2` 已推送 GitHub并以同一提交快进同步服务器。
- 服务器复检：全部修改 PHP 文件语法、真实数据库产品总数/第一页/带 OFFSET 下一页查询、分类/状态聚合及只读目录冒烟测试均通过；服务器确认产品页面无旧全量切页代码，最终三方提交一致性以本轮收尾提交核对结果为准。

## 本次：报价产品网格合理最大列数修正

- 复核确认上一版在超宽内容区会计算出 12 列，默认 12 个产品全部进入第一排，因此页面没有第二排，并非第二排再次被裁剪。
- 改为按屏幕宽度分档自适应：超宽屏最多 8 列、1451–1800px 为 7 列、1101–1450px 为 6 列、761–1100px 为 4 列、手机为 2 列。
- 每档列宽均等分铺满整个内容区，不再出现固定 6 张靠左后右侧大片空白；默认 12 个产品在各桌面档位均会自然形成第二排。
- 删除运行时列数脚本，避免超宽屏把整页产品压成单排；列表模式不受影响。
- 修改文件：`commercial_center_v1/assets/css/app.css`、`commercial_center_v1/assets/js/app.js`、`WORK_CONTEXT.md`。
- 检查：`git diff --check`、JavaScript 语法和 700/1024/1366/1600/1920px 各断点的 12 产品行数检查均通过；业务提交 `bf94374` 已推送 GitHub并以同一提交快进同步服务器。
- 服务器复检：8/7/6 列桌面断点规则存在，旧运行时列数算法已删除，Git 差异与工作区状态正常；最终三方提交一致性以本轮收尾提交核对结果为准。

## 本次：报价产品卡片第二排裁剪修复

- 根因：旧桌面布局仍以更高选择器优先级强制产品网格固定高度、固定两行轨道并设置 `overflow:hidden`；自适应列数生成第二排后被父级和网格共同裁掉。
- 使用报价产品页专属高优先级规则解除内容区与网格的固定高度和溢出裁剪，网格行数改为内容自然增长，第二排及后续排完整显示。
- 修改文件：`commercial_center_v1/assets/css/app.css`、`WORK_CONTEXT.md`。
- 检查：CSS 花括号/覆盖规则检查和 `git diff --check` 均通过；业务提交 `1855bab` 已推送 GitHub并以同一提交快进同步服务器。
- 服务器复检：产品页专属高度、自然行轨道及溢出显示规则存在，Git 差异与工作区状态正常；最终三方提交一致性以本轮收尾提交核对结果为准。

## 本次：报价产品卡片真正自适应铺满

- 移除卡片模式每行最多 6 张和 1188px 宽度上限，产品网格重新使用整个内容区宽度。
- 根据网格实时宽度和当前产品数量自动计算列数，宽屏能显示更多产品；卡片等宽铺满整行，产品图片与卡片保持同宽。
- 当自然列数会导致最后一行只剩 1 张时，自动减少一列重新分配，兼顾铺满宽度与避免孤卡；列表模式不受影响。
- 修改文件：`commercial_center_v1/assets/css/app.css`、`commercial_center_v1/assets/js/app.js`、`WORK_CONTEXT.md`。
- 检查：`git diff --check`、CSS 花括号结构、`node --check assets/js/app.js` 及 900–2400px 多宽度列数算法检查均通过；业务提交 `9c207eb` 已推送 GitHub并以同一提交快进同步服务器。
- 服务器复检：服务器未安装 Node.js，使用本地 Node 语法检查结果；服务器已核对自适应/孤卡规则、Git 差异、工作区状态和 CSS/JS 文件哈希，均与本地一致；最终三方提交一致性以本轮收尾提交核对结果为准。

## 本次：操作确认模式调整

- 用户要求所有操作不再逐次确认；系统不提供全局“永不询问”选项，已将全局权限调整为当前可用的最宽松模式“低风险操作自动批准”。
- 普通和低风险操作将自动执行；重要、高风险或系统沙箱要求的操作仍可能由平台强制确认，不能通过仓库设置绕过。
- 本次无业务代码修改，仅更新 `WORK_CONTEXT.md`。

## 本次：报价产品库控制区合并单行

- 将产品总数/状态统计、搜索筛选、卡片/列表切换以及上一页/页码/下一页合并到同一条工具栏，桌面宽度充足时由原三行压缩为一行。
- 中小屏幕空间不足时允许工具栏分组自适应换行，避免控件挤压、截断或横向溢出。
- 修改文件：`commercial_center_v1/views/product_library_v2.php`、`commercial_center_v1/assets/css/app.css`、`WORK_CONTEXT.md`。
- 检查：CSS 花括号结构、`git diff --check`、本地视图经服务器 PHP 标准输入语法检查均通过；业务提交 `d9f0fad` 已推送 GitHub并以同一提交快进同步服务器。
- 服务器复检：视图 PHP 语法、单行工具栏结构/样式和 Git 工作区状态均通过；最终三方提交一致性以本轮收尾提交核对结果为准。

## 本次：报价产品库列表视图与孤行修复

- 修复卡片紧凑样式覆盖列表模式的问题：列表恢复为全宽单列、96px 图片的紧凑横向行，并增加平板和手机响应式布局。
- 卡片模式每行最多 6 张，默认 12 张/页时排列为两行各 6 张，避免超宽屏出现首行 11 张、第二行孤零零 1 张；较窄屏仍按可用空间自动换列。
- 视图模式由服务端直接输出，并在筛选、上一页和下一页链接中持续保留，避免切页后跳回卡片模式。
- 修改文件：`commercial_center_v1/assets/css/app.css`、`commercial_center_v1/views/product_library_v2.php`、`WORK_CONTEXT.md`。
- 检查：CSS 花括号结构、`git diff --check`、本地文件经服务器 PHP 标准输入语法检查均通过；业务提交 `cffb448` 已推送 GitHub并以同一提交快进同步服务器。
- 服务器复检：视图 PHP 语法、列表专属样式、卡片六列宽度上限和 Git 工作区状态均通过；最终三方提交一致性以本轮收尾提交核对结果为准。

## 本次：报价产品库卡片紧凑自适应排列

- 报价产品库卡片不再按固定 4/6 列或两行视口高度强制拉伸，改为以产品图宽度为卡片宽度的紧凑规格。
- 桌面端按 168–188px 卡片宽度自动填充并换行，窄屏按可用宽度自动缩放排列；图片与卡片保持同宽，图片使用 `contain` 完整展示。
- 修改文件：`commercial_center_v1/assets/css/app.css`、`WORK_CONTEXT.md`。
- 检查：CSS 花括号结构检查、`git diff --check` 均通过；业务提交 `adfe636` 已推送 GitHub，并从该提交生成 Git bundle 快进同步服务器（服务器直连 GitHub SSH 密钥被拒绝，未绕过 GitHub）。
- 部署：服务器已确认自适应列规则和本上下文记录存在；最终本地、GitHub、服务器提交一致性以本轮收尾提交核对结果为准。

## 本次：CRM 拜访/来访新建保存 JSON 报错修复

- 根因：新建主记录已成功写入，但选择图片/附件后，上传接口尝试在不可写的 `uploads` 根目录创建 `visit_files`，PHP 输出 `mkdir(): Permission denied` HTML 警告，前端 `res.json()` 因而报 `Unexpected token '<'`。
- 处理：新拜访附件改存到已有写权限且仍由受控下载接口读取的 `storage/visit_files/YYYYMM`；目录创建失败时抑制 PHP HTML 警告并返回业务错误；前端附件上传先读取文本再解析 JSON，异常响应改为可读提示。
- 修改文件：`crm_visit.php`、`assets/crm/crm.js`、`WORK_CONTEXT.md`。
- 数据核对：服务器已有来访 ID 2–5，其中 ID 2–5 为 2026-07-25 反复保存产生；未擅自删除，待用户确认哪些重复记录需要清理。
- 检查：`git diff --check`、`node --check assets/crm/crm.js`、服务器 PHP 8.0 标准输入语法检查均通过；最终提交、推送、部署、服务器目录创建/语法复检和三方提交一致性见本轮最终结果。

## 本次：广州系统与商务中心双击启动入口

- 按用户实际文件整理，将商务中心启动入口移动并重命名为 `commercial_center_v1/离务中心CODEX.command`，同时修正为从所在目录直接启动商务中心 Codex。
- 在仓库根目录新增 `广州系统-CODEX.command`，macOS 双击后进入广州 ERP 根目录并启动 Codex，终端标题显示“Codex - 广州 ERP”。
- 两个入口均保留 Codex 路径回退和中文错误提示；`zsh -n`、可执行权限、目标目录及 Codex 本机命令检查通过。
- Git 与部署：入口整理提交 `4ba5d41` 已推送 GitHub，并以同一提交快进同步服务器；两个入口文件及可执行权限已在服务器复核通过。

## 本次：商务中心 Codex 双击启动入口

- 升级仓库根目录 `CODEX.command`：macOS 双击后自动进入 `commercial_center_v1` 并启动 Codex，终端标题显示“Codex - 商务中心”。
- 增加商务中心目录和 Codex 可执行文件检查；固定路径不可用时会尝试系统 `PATH`，仍不可用则显示中文错误信息，不再无提示退出。
- 检查：`zsh -n CODEX.command`、可执行权限、商务中心目录、Codex 本机命令和 `git diff --check` 均通过。
- Git 与部署：启动入口提交 `bb0a01b` 已推送 GitHub，并以 Git bundle 将同一提交快进同步服务器；服务器没有 `zsh`，因此仅复核文件、权限和提交一致性，macOS `zsh` 语法检查已在本地完成。

## 本次：商务中心青绿色统一改为大红色

- 按产品详情截图要求，将商务中心产品详情 1–5 分区编号圆点使用的青绿色 `#0b8291` 统一替换为商务红 `#d92d20`；同色的抽屉主按钮源样式同步替换，商务中心内不再保留该青绿色。
- 为商务中心 `app.css` 与 `catalog.css` 增加基于文件修改时间的资源版本参数，部署后浏览器刷新会获取最新样式。
- 修改文件：`commercial_center_v1/assets/css/app.css`、`commercial_center_v1/index.php`、`WORK_CONTEXT.md`。
- 检查：`git diff --check` 通过；商务中心内旧色 `#0b8291` 检索结果为 0；服务器 `php -l commercial_center_v1/index.php` 通过，并确认大红色样式已生效于编号圆点和抽屉主按钮。
- Git 与部署：业务提交 `69f7015` 已推送 GitHub，并以同一提交快进同步到服务器唯一运行目录；业务提交同步时本地、GitHub、服务器三方一致。

## 本次：CRM 客户编辑后列表重复修复

- 现象：客户中心编辑客户后，同一客户代码和名称在列表中重复出现，视觉上像每次编辑都新增客户。
- 根因：客户本体没有重复新增；服务器 `EX137` 只有 1 条 `crm_customers` 记录，但历史地址数据有 3 条且全部标记为主地址。列表直接关联全部主地址，导致同一客户被一对多展开成 3 行。
- 处理：客户地址同步保存时强制最多一条主地址；客户列表关联地址和负责人时各自只选一条确定记录，即使存在历史脏数据也不会重复展示。
- 数据修复：已在服务器事务性清理现有多主地址标记；仅 `EX137` 受影响，清除 2 个多余主地址标记，复检多主地址客户数为 0。
- 修改文件：`crm_customer.php`、`WORK_CONTEXT.md`。
- 检查：`git diff --check`、本地文件经服务器 PHP 标准输入语法检查、服务器 `php -l crm_customer.php` 均通过；新列表 SQL 对 `EX137` 仅返回 1 行。
- Git 与部署：业务修复提交 `367f1a6` 已推送 GitHub；同步期间 GitHub 有商务中心并行提交，最终本地、GitHub、服务器统一到包含该修复的最新提交。服务器原并行改动已由保护性 stash 保存，未覆盖或删除。
- 待确认：用户刷新客户中心并再次编辑 `EX137`，确认列表始终只显示一行。

## 本次：物料中心 UI 静态资源缓存修复

- 用户反馈电源工作台部署后视觉无变化；核对确认本地、服务器页面/CSS/JS 文件哈希完全一致，服务器页面已包含新 5 Tab 结构且无统计卡片。
- 根因：统一页面框架引用 CSS/JavaScript 时没有缓存版本参数，浏览器可能继续复用旧静态资源。
- 处理：物料中心统一 CSS、组件 JavaScript和页面专属 JavaScript URL 增加基于本地文件修改时间的 `?v=` 参数；文件更新后浏览器会请求新资源。
- 修改仍仅限 `material_center_v1` 及本上下文；不涉及旧系统、旧数据库或旧 BOM 数据。

## 本次：电源工作台锁定 UI 纠偏

- 已暂停新增电源业务功能，按 `ARTDON_POWER_WORKBENCH_UI_CORRECTION_LOCKED_SPEC_V1.md` 仅重构电源工作台 UI 与交互。
- 删除 4 张统计卡片和模板化英文抬头；可见 Tab 从 7 个精简为全部、待整理、待确认、正式、异常 5 个。
- `source`、`duplicates`、`archived` 旧参数分别映射到来源、重复候选和归档筛选；旧页面、后端服务、适配器、权限和数据库能力均保留。
- 工具栏、全宽全高表格、自适应 8–100 行、固定底部分页及默认 520px 统一抽屉已重构；整行和“查看”统一打开抽屉。
- 新增 4 项 UI 专项自动化及 4 份纠偏文档；16 项旧功能完整映射，失联 0。
- 安全结果：旧 URL 失效 0、404 0、旧 BOM 数据变化 0、旧表结构变化 0；登录态多分辨率视觉验收仍作为后续人工检查，不扩展业务功能。
- 本轮发现 `commercial_center_v1` 存在其他并行工作产生的改动，未读取、未修改、未纳入本次提交。

## 本次退出记录

- 用户已要求退出，本轮工作结束。
- 已完成固定同步顺序与强制上下文记录规则落库；退出前无其他待执行代码修改。
- 本次退出记录将按本地提交 → 推送 GitHub → 同一提交同步服务器完成三方一致性核对。

## 本次：强制结束前记录上下文

- `AGENTS.md` 已规定：每次工作结束、退出、暂停或准备交接前必须更新 `WORK_CONTEXT.md`，即使没有代码修改也不得省略。
- 上下文记录必须随本次修改先提交并推送 GitHub，再同步服务器；结束时本地、GitHub、服务器三方记录必须一致。
- 本次规则修改按固定的本地 → GitHub → 服务器流程执行，最终提交号及同步结果见本轮最终结果。

## 本次：固定本地 → GitHub → 服务器同步顺序

- `AGENTS.md` 已明确规定唯一操作顺序：本地修改与检查 → 提交并推送 GitHub → 将 GitHub 同一提交同步服务器 → 服务器复检 → 核对本地、GitHub、服务器三方提交一致。
- 禁止先部署服务器再推 GitHub，禁止直接在服务器临时修补；服务器检查失败必须回到本地修复并重新走完整流程。
- 本次规则修改也严格按新顺序执行，最终提交号及三方同步结果见本轮最终结果。

## 本次：服务器仓库同步 GitHub

- 同步前：本地/GitHub 为 `96e9896`，服务器 Git HEAD 为 `c1ca156`，服务器有 23 项已跟踪状态和 70 个未跟踪文件，不能直接 pull。
- 保护：服务器原状态、二进制补丁和未跟踪文件归档已保存到 `/tmp/artdon_git_sync_backup_20260725/`；未执行清理、强制重置或删除。
- 比对：将 GitHub bundle 导入服务器后，以目标提交重建索引进行只读比对；发现服务器保留了 PHP 8.0 用户上下文修复，随后该修复已由提交 `520e10a` 推送 GitHub。
- 结果：服务器 `main`、服务器 `origin/main`、本地 `main` 和 GitHub `origin/main` 已统一到 `520e10a`；服务器已跟踪差异 0，额外 8 个未跟踪文件原样保留。
- 检查：服务器 `naming.php`、`plm.php`、`crm_plm_auth_lib.php` 及 PHP 8.0 下的 `MaterialCenterUserContext.php` 语法检查通过。
- Git：本条同步记录的最终提交号及服务器再次同步结果见本轮最终结果。

## 本次：物料中心 PHP-FPM 8.0 语法错误修复

- 根因：服务器命令行 PHP 为 8.1，但网站实际由 PHP-FPM 8.0 运行；`MaterialCenterUserContext` 使用了 PHP 8.1 才支持的 `readonly` 属性，导致网站解析失败。
- 处理：保留原有构造参数、公开属性名称和类型，将构造器属性提升改为 PHP 8.0 可解析的显式属性与赋值，没有改变权限接口或业务逻辑。
- 部署与检查：修复文件已从本地部署到 `/www/wwwroot/Artdon/artdon_erp/material_center_v1/`；目标文件分别通过 PHP 8.0 与服务器默认 PHP 语法检查，权限回归通过，整个 `material_center_v1` 的 PHP 文件已全部通过 PHP 8.0 语法扫描。
- 影响范围：仅修改物料中心用户上下文类及本上下文记录；旧系统、旧表结构和旧 BOM 数据变化均为 0。

## 本次：PLM 首页 “Access denied.” 修复

- 根因：本地及服务器 `plm.php` 权限为 `600`，服务器文件属主为 `ubuntu:ubuntu`，PHP-FPM 用户无法读取入口文件，因此在进入 PLM 业务权限判断前即返回 `Access denied.`。
- 处理：本地 `plm.php` 权限修正为 `644`，再从本地同步到唯一服务器运行目录 `/www/wwwroot/Artdon/artdon_erp/plm.php`，服务器权限确认是 `644`。
- 检查：本地 `git diff --check` 通过（本机无 PHP CLI）；服务器 `php -l plm.php` 通过。服务器本机默认虚拟主机不映射该路径，匿名 localhost 探测为 404，登录态页面需用户刷新确认。
- Git：文件内容无变化，Git 不记录普通文件 `600→644` 权限变化；本次以 `WORK_CONTEXT.md` 记录修复，提交号及推送状态见本轮最终结果。

## 本次：型号命名创建人/修改人显示“未识别账号”修复

- 根因：命名页面权限已使用统一 SSO `artdon_sso_current_user()`，但 `created_by` / `updated_by` 仍由旧函数单独扫描 Session/Cookie；统一登录有效但旧字段不存在时，会写入“未识别账号”。
- 处理：`nm_current_user()` 改为优先读取统一 SSO 的展示名、实名或账号，异常时才回退原有 Session/Cookie 兼容逻辑；版本更新为 `3.0.8.33`。
- 历史数据：已经写入“未识别账号”的旧记录无法可靠推断原操作人，不自动篡改；新建或后续编辑将写入当前统一登录账号。
- 检查与部署：`git diff --check` 通过；本机无 PHP CLI，部署前以服务器 PHP 检查本地输入通过；`naming.php` 已部署到 `/www/wwwroot/Artdon/artdon_erp/`，服务器 `php -l` 通过且权限为 `644`。
- Git：提交号及推送状态见本轮最终结果。

## 本次：型号命名系统认证库加载错误修复

- 根因：`crm_plm_auth_lib.php` 与入口保护文件在本地及服务器均为 `600` 权限，服务器 PHP-FPM 用户无法读取，因而报 `Failed opening required`；文件本身未缺失，引用路径正确。
- 处理：本地 `crm_plm_auth_lib.php`、`crm_plm_guard.php` 权限修正为 `644`，再从本地同步相同文件到唯一服务器运行目录 `/www/wwwroot/Artdon/artdon_erp/`。
- 检查：本地 `git diff --check` 通过（本机无 PHP CLI）；服务器 `crm_plm_auth_lib.php`、`crm_plm_guard.php`、`naming.php` 全部通过 `php -l`，认证库独立加载返回 `AUTH_LIB_LOAD_OK`。
- Git：文件内容无变化，Git 不记录普通文件 `600→644` 权限变化；本次以 `WORK_CONTEXT.md` 记录修复，提交号及推送状态见本轮最终结果。
- 待确认：用户刷新型号命名系统，确认原报错消失及登录态页面正常。

## 本次：电源工作台专项

- `tab=source` 空白根因是已读取的真实 `$rows` 被 iframe 分支忽略，并非无数据或查询失败。服务器旧源电源320条，适配器返回安全上限200条。
- 已改为工作台原生渲染来源、整理、确认、正式、重复、归档和全部数据；错误显示编号并记录日志。
- 新增字段、映射、日志和当前结果CSV导出入口；功率档继续使用真实维护能力。
- 五项专项自动化、PHP/JS、权限、路由和旧BOM只读回归通过；旧表结构及旧BOM变化0。
- 批量导入、解析规则和完整度规则仍明确标记尚未接入，没有伪造成功。

## 本次：物料中心 V3 M0–M13

- 建立13项旧功能到V3新入口映射；服务器新旧路由和页面渲染通过后，才隐藏重复左侧菜单，未删除旧页面/API。
- 左侧改为业务对象：工作台、物料库、供应商与价格、产品适配、替代与版本、数据接入、文档与日志、系统与设置。
- 新增电源统一工作台、六类统一类别工作台和产品适配入口。电源来源、确认、正式库和功率档在页内原位承载。
- M5–M12 复用并回归此前已完成的抽屉、自适应表格、分页、列设置、批量事务、生命周期、设置和字段权限。
- V3路由合同、PHP/JS、页面渲染、数据库、旧BOM行数和统一账号合同均通过；旧系统/旧表变化0。

## 本次续作：V2 A9–A11

- A9 已实现独立新建、编辑/复制草稿、审核生命周期、引用检查和草稿删除保护。
- A10 人工电源与旧BOM标准化共用正式结构；人工来源明确，未知字段保持 unknown。
- 服务器创建→引用检查→删除草稿合同通过，测试物料已删除；旧BOM无写入。
- V2 A0–A11 代码里程碑完成；剩余为有正式业务数据后的人工批量更新和多角色视觉验收。

## 本次续作：V2 A7–A8

- A7 新增29项设置定义，覆盖区域字号、颜色、布局、抽屉、表格、动画和展示模式；支持JSON导入导出，导入后仍须服务端校验保存。
- A8 新增服务端字段访问、敏感字段保护和数据范围解析；标准化详情 `raw_price` 默认受保护。
- `20260725_004_settings_v2` 已通过 up→down→up，服务器PHP和设置/权限/旧产品只读合同测试通过。
- 旧系统文件0、旧表结构变化0、旧BOM写入0。下一阶段为 A9 独立新建和生命周期页面。

## 本次续作：V2 A6

- 新增 `20260725_003_fields_batch_lifecycle`：字段注册、字段权限、批量任务/逐条快照、生命周期事件5张 `mc_` 表。
- 正式电源库接入复选框、选择上下文条、动态字段、只填空值/覆盖原值、预览及确认执行；服务端再次验证统一账号权限、字段权限、类型和事务。
- 迁移已在服务器通过 up→down→up；字段种子9项，批量任务0，旧 `bom_materials` 当前1022行且未写入。
- A7–A10 下一轮继续；没有伪造正式电源或批量成功数据。

## 本次：物料中心 V2 A0–A5

- 完成 A0 审计、A1 八大分组导航、A2 白色全宽框架、A3 520px可调抽屉、A4视口自适应表格与新分页、A5列拖动/列宽/适配/显示/密度/视图记忆基础。
- 本轮停在完整可运行的 A5 基础；A6 批量写入未用前端假保存实现，下一入口为字段注册中心、字段权限和服务端批量事务。
- 本地 UI、只读及 MM 静态测试通过；服务器全部 PHP 语法、MM schema/旧BOM行数及 F3/F4/F9 合同测试通过。
- 仅修改 `material_center_v1` 和本上下文；旧系统文件0、旧表结构变化0、旧BOM写入0；已同步服务器物料中心目录。

## 本次：物料中心 F0–F9 框架、设置、权限与匹配基础

- 完成：F0 四项审计；F1 白色专业侧栏；F2 唯一组件库；F3 四层设置结构、个人设置、白名单校验和审计；F4 广州统一账号适配及服务端权限；F5 完整分组菜单；复核沿用 F6–F8；F9 旧产品只读适配、产品电源规则与匹配模拟基础。
- 修改：仅 `material_center_v1` 内迁移、服务、API、页面、UI、测试和文档，以及本上下文文件；未修改商务中心或广州旧系统。
- 检查：UI static、UI contract/read-only、JavaScript 语法、服务器全部 PHP 语法、F3/F4/F9 合同、设置解析、旧产品只读和电源解析均通过；页面 CLI 渲染无错误。
- 数据库：迁移 `20260725_002_framework_settings_permissions_matching` 已通过 up→down→up；当前 35 张 `mc_*` 表、11 条 20–25W 暂存、0 条正式电源、0 条产品规则；旧表结构变化 0。
- 部署：相同文件已部署到 `/www/wwwroot/Artdon/artdon_erp/material_center_v1/`，没有直接编辑服务器。
- 浏览器：浏览器技能文件当前不可读取，服务器默认虚拟主机路径返回 404；登录态视觉与点击仍需从实际 ERP 域名复核。
- Git：功能提交 `848ad38`；推送状态见本轮最终结果。
- 下一步：人工确认试点未知字段并建立首批正式电源，再建立真实产品电源规则并运行模拟；不进入报价。

## 固定环境

- 本地工作目录（两台电脑）：
  - 家用电脑：`/Users/qiulei-home/Library/Mobile Documents/com~apple~CloudDocs/artdon/artdon_guangzhou/artdon_erp`
  - 办公电脑：`/Users/qiulei-office/Library/Mobile Documents/com~apple~CloudDocs/artdon/artdon_guangzhou/artdon_erp`
- 当前电脑：家用电脑（`qiulei-home`）
- GitHub：
  `git@github.com:qiulei6386-stack/artdon_erp.git`
- 主分支：`main`
- 服务器 SSH：`artdon`
- 唯一服务器运行目录：
  `/www/wwwroot/Artdon/artdon_erp/`

## 固定工作规则

- 先修改本地并检查，再提交、推送 GitHub，随后将 GitHub 同一提交同步服务器并复检。
- 本地、服务器和 GitHub 保持相同版本。
- 每次结束前更新本文件。

## 最近完成

- 电源人工确认的输出电流已改为多值编辑：同一条电源可新增/删除多个 mA 选项并选择默认电流；保存时同步默认/最小/最大值及完整 `mc_power_supply_current_options` 列表。
- `material_center_v1` 已完成 MM2–MM4 基础：20 个 `mc_*` 表、可回滚迁移、通用物料主数据、电源专用结构、旧 BOM 只读适配器、解析暂存/置信度/重复候选/旧源链接和操作日志。
- 20–25W 试点已从旧 BOM 只读暂存 11 条并生成 118 条解析候选；迁移已完成 up → down → up，重复迁移与重复暂存均保持幂等。
- 新增电源源数据、电源标准化工作台、正式电源库和功率档管理；正式电源当前 0 条，因为输出类型等字段尚未人工确认，没有猜测或自动正式化。
- `material_center_v1` 连续完成 UI U0–U7：完善统一设计令牌、唯一表单控件、Dropdown/Modal/ConfirmModal/Drawer/Toast、表格排序/选择/分页/列控制/密度、页面状态与响应式应用框架。
- 新增物料中心电源只读列表及详情抽屉、旧 BOM 物料源审计页、系统状态页；数据仅来自旧 `bom_materials` SELECT 或真实运行检查，没有写入与表结构变化。
- 补齐物料中心安全基线、禁止触碰、旧源审计、字段映射、物料/电源当前领域模型、进度、决策和测试文档。
- `material_center_v1` 已建立统一 UI 组件体系：设计令牌、浅色/深色/系统主题、排版与页面框架、按钮、表单、选择控件、卡片、徽章、Tooltip、下拉菜单、弹窗、抽屉、Toast、表格、分页和统一页面状态。
- 物料中心首页已迁移到统一组件，增加主题选择、客户展示模式、300ms 搜索提交、搜索清空、键盘打开详情、统一抽屉与前端分页。
- 新增组件展厅 `material_center_v1/ui/docs/component-gallery.php`，并补齐 UI 审计、进度、决策和测试清单；本轮没有修改商务中心、广州旧系统或数据库表结构。
- 新建 `material_center_v1` 只读基础版：位于广州 ERP 正确子目录，接入统一登录与旧 `bom_materials` 只读总览，包含搜索、分类筛选、统计、详情抽屉和健康检查；当前无写入 SQL、不展示价格与供应商。
- 已完成本地、GitHub、服务器三方同步审计：同步前已跟踪代码均为 `1b9436e`；服务器无已跟踪改动，GitHub 与本地一致。服务器缺少 GitHub SSH 凭据，后续由本地推送 GitHub并通过 Git bundle 快进服务器仓库。
- 已确认两个本地路径分别属于家用电脑 `qiulei-home` 和办公电脑 `qiulei-office`；每次使用当前电脑对应目录。
- 已确认服务器唯一运行目录为 `/www/wwwroot/Artdon/artdon_erp/`，后续不再同步外层 `/www/wwwroot/Artdon/`。
- 派工待办“完成 / 优先级 / 截止日期 / 负责人 / 方式 / 派工来自 / 操作”7 列已按设备视图锁定宽度：不再显示拖拽把手，也不参与窗口自适应缩放；标题、项目等列继续自适应。
- 派工待办表格整排表头文字已统一居中，所有设备视图同步生效，内容行原有对齐方式不变。
- 派工待办“优先级 / 负责人 / 方式”列已按文字压缩：桌面与横屏为 56 / 72 / 52px，手机竖屏为 50 / 72 / 50px；已有较宽个人设置会自动压到新宽度。
- 派工待办“优先级”和“方式”列已隐藏原生下拉箭头，文字区域仍可直接点击选择，新增行同步生效；另已修复方式列旧高优先级背景箭头样式覆盖问题。
- 派工待办“截止日期”列已收窄：桌面端与手机横屏统一为 70px，手机竖屏维持 48px；已有较宽的个人列宽设置会自动限制到新宽度。
- CRM 名片 OCR 图片保存：压缩到 500KB 内，关联客户，可在客户属性查看和删除。
- BOM 型号格式搜索不再错误命中其他 BOM 的物料明细。
- BOM 顶部抬头和总览工具区已压缩。
- BOM 总览的表格、图标、分类平铺已改为互斥视图并共用分页。
- BOM 图标宽屏布局为 9 列，每页 18 条；其余断点按列数乘两行分页。
- BOM 编辑成本单页面已压缩左侧列表、基础资料、工具栏和提示条，表格获得更多可视空间。

## 最近 Git 状态

- 最近业务提交：`565aeab`（新建物料中心只读基础版）。
- 协作规则与上下文文件已纳入 Git 管理；具体最新提交以 `git log -1` 为准。

## 本次检查与部署

- 修改文件：仅在 `material_center_v1` 新增/修改迁移、适配器、领域服务、API、页面、文档和测试，并按协作规则更新 `WORK_CONTEXT.md`。
- 本地检查：`git diff --check`、UI 静态/合同测试、MM 20 表结构测试、旧源只读扫描和全部 JavaScript `node --check` 通过；本机无 PHP CLI。
- 服务器部署：仅部署到 `/www/wwwroot/Artdon/artdon_erp/material_center_v1/`，未部署到外层目录。
- 服务器检查：模块全部 PHP 文件通过 `php -l`；解析器、迁移结构、旧 BOM 行数不变及幂等暂存测试通过；6 个 MM 页面/接口返回 HTTP 200。
- 数据库：迁移 `20260725_001_mm2_mm4_foundation` 已应用；20 个 `mc_*` 表，8 个功率档，11 条试点暂存，正式电源 0 条。
- Git：MM2–MM4 业务提交 `3700ad2` 已推送 `origin/main`；多输出电流提交完成后同步服务器仓库。

## 待办

- 下一步由有 BOM 编辑权限的用户在标准化工作台人工确认 11 条试点的输出类型、安装、质保和调光，再建立第一批正式电源。
- 本轮停止在 MM4，不进入产品电源适配或报价逻辑。
- 登录态真实物料数据与各业务人员常用浏览器仍需人工业务验收。

## 2026-07-26：物料中心本地与服务器只读扫描

- 扫描范围：本地 `material_center_v1/`、GitHub `origin/main` 和服务器 `/www/wwwroot/Artdon/artdon_erp/material_center_v1/`；本轮未修改、覆盖或删除物料中心业务文件。
- 三方提交：扫描时本地 HEAD、GitHub `origin/main`、服务器 HEAD 和服务器 `origin/main` 均为 `4440f471f5dc00cb49aa8cbb98c3111b2479ad88`。
- 本地状态：物料中心已跟踪文件无修改；有 7 份未跟踪的 UI/框架/电源工作台规格文档。仓库另有商务中心既存删除和未跟踪文件，本轮未触碰。
- 服务器状态：4 个已跟踪文件存在未提交修改：`bootstrap.php`、`index.php`、`assets/css/app.css`、`assets/js/app.js`；相对 HEAD 合计 25 行新增、241 行删除，其内容校验值均与本地不同。
- 服务器另有一套未跟踪框架：`README.md`、`adaptation/`、`components/`、`config/material_pages.php`、`data/`、`demo/`、`docs/CODEX_INTEGRATION_GUIDE.md`、`documents/`、`lib/`、`material/`、`settings/`、`substitute/`、`supplier/`。
- 文件规模：本地扫描到 148 个文件，服务器扫描到 162 个文件；忽略运行时目录后，主要内容差异即上述 4 个跟踪文件、服务器未跟踪框架和本地 7 份未跟踪文档。
- 检查结果：服务器物料中心全部 PHP 文件通过 `php -l`；服务器修改后的 `assets/js/app.js` 通过本地 Node `--check`。本机无 PHP CLI，因此未在本地重复 PHP 语法检查。
- 风险结论：服务器存在未进入 GitHub 的实质代码，不能直接用本地目录覆盖服务器；继续开发前必须先决定是将服务器框架审阅后回收至本地，还是确认废弃后再由 GitHub 版本替换。
- 部署情况：本轮业务代码未部署；仅将本扫描记录按固定 Git 流程提交、推送并同步服务器，且不得改变服务器物料中心现有工作树差异。
- 下一步：先审阅服务器 4 个跟踪差异及未跟踪框架的功能来源，再由用户确认“回收”或“废弃”；确认前不执行清理或覆盖。

## 2026-07-26：物料中心十阶段长任务完成

- 完成内容：严格按 `material_center_v1/docs/ARTDON_MATERIAL_CENTER_CODEX_LONG_RUN_MASTER_SPEC_V1.md` 连续完成十阶段，包括审计备份、`mc_*` 迁移与服务层、主数据生命周期、七类动态字段、旧 BOM 只读同步及导入、供应商价格/MOQ/交期/附件、产品适配、替代与引用回滚、文档/日志/设置/权限和最终测试。
- 修改范围：业务修改均在 `material_center_v1/`；未改变 `components/sidebar.php` 的菜单分组/名称/顺序，未重新设计现有 UI 外壳，未修改旧 BOM 表或旧系统业务。根目录仅追加本交接记录。
- 数据库：迁移 `001–012` 已应用且均含回滚；`010–012` 完成实际回滚/重迁移，`012` 完成 up→功能测试→down→up→功能测试。旧 `bom_materials` 保持 1022 行，结构签名不变。
- 检查：本地 3 个 JS/UI 合同测试、全量 JavaScript `node --check`、`git diff --check` 通过；服务器全量 PHP 语法和 19 个 PHP 合同/集成测试通过。10k 分页约 0.020 秒，六角色权限、并发编辑、价格导入、供应商附件、文档签名和替代回滚均通过。
- HTTP：全部正式菜单页面返回 200；未登录写入 API 返回 JSON 401，未出现 HTML 警告冒充 JSON。
- 清理：价格导入、供应商附件和权限测试残留均为 0；旧 BOM 同步第二次新增/变化均为 0。
- 备份：本地 `/tmp/material_center_pre_long_run_da70161.bundle`；服务器 `/tmp/material_center_v1_20260726_long_run_pre.tar.gz`；数据库 `/tmp/artdon_erp_20260726_long_run_pre.json.gz`。
- 部署：功能与交付文档提交 `46e70ec301bb416a4ce5acce32cece5c17ec2948` 已推送 GitHub 并 fast-forward 到服务器；核对时本地、`origin/main`、服务器三方一致。
- 已知限制：自动化环境没有可连接的登录态浏览器实例，Chrome/Edge/Safari 多分辨率真实截图需人工补验；已完成 HTTP、服务端渲染、静态 UI 合同和响应式 CSS 检查，未伪报浏览器视觉通过。
- 下一步：仅需业务人员按实际角色做登录态视觉/业务抽验；十阶段开发任务无未完成代码项。

## 2026-07-26：修复“全部物料”空白

- 根因：旧 BOM 已同步为 1022 条 `mc_source_records` 只读快照，但 `mc_materials` 当前为 0；“全部物料”原先只查询主数据表，因此页面正常渲染却没有记录。
- 修复：全部物料及七类页面合并展示未映射的 BOM 来源快照，明确标记“待整理 / 旧 BOM（只读）”；不自动正式化、不写旧表。
- 安全：来源记录没有可选复选框，详情抽屉隐藏编辑、复制、引用和状态动作；前端详情输出增加转义，避免来源文本造成 XSS。
- 服务器验证：全部物料实际渲染 1022 行；电源323、芯片550、光学95、型材/外壳16、接头21、配件17、包装0；只读来源可选择复选框为0。最终业务集成及10k分页测试通过。
- Git：修复提交 `763c91064f85f173e36a62f4368c609f1fec1eec` 已推送 GitHub并同步服务器。
- 浏览器：当前无可连接浏览器实例，未伪报登录态视觉验证；请刷新线上页面查看。

## 2026-07-26：补齐电源来源标准化 API 闭环

- 纠正问题：此前电源列表虽然显示323条只读来源，但当前页面没有连接整理动作；不能把旧试点页面的存在视为这批来源已接通。
- 完成入口：`material/power.php` 的每条旧 BOM 来源增加“整理”按钮，共323个；点击调用 `api/v1/power-standardization.php` 的 `stage_source`，成功后打开该条记录对应的标准化审核抽屉。
- 服务端流程：校验统一账号、权限、CSRF和电源类别；事务写入 staging、解析候选、置信度、重复候选、来源解析结果和操作日志。
- 人工确认：服务端再次校验安装方式、输出类型、功率档、输出电流和调光枚举；确认后创建 `draft` 且 `is_official=0` 的电源，保存动态电源字段、多个输出电流、调光、物料元数据、旧源链接和 `mc_source_mappings`。
- 旧数据安全：旧 `bom_materials` 仅只读；来源确认只更新 `mc_*`。重复提交会返回既有映射，不重复创建。
- 服务器测试：真实执行“来源→解析→详情→人工确认→草稿→来源映射→完整清理”通过；电源页面渲染323行和323个整理按钮；未登录 API 返回 JSON 401；解析器、工作台回归和最终业务集成通过。
- Git：业务提交 `6bafc1723a57aa189e53cc5893e8f7f32e97f292` 已推送 GitHub并同步服务器。

## 2026-07-26：修复物料页面“更多”无响应

- 根因：`.mc-dropdown` 使用绝对定位，但外层 `.mc-dropdown-wrap` 没有定位上下文，菜单打开后显示在错误位置；当前外壳资源 URL 也没有版本号，浏览器可能继续命中旧 CSS/JS。
- 修复：为菜单容器增加 `position:relative`；下拉绑定增加目标缺失保护；当前外壳 CSS、主脚本和物料动作脚本全部使用文件时间版本参数自动刷新缓存。
- 验证：下拉定位/缓存合同测试通过；服务器 PHP 语法通过；线上页面实际输出 `app.css?v=...`、`app.js?v=...`，获取到的新资源包含定位和事件修复。
- Git：业务提交 `875c9fc64012f98c90af8a5806ec4e43b1d1f43f` 已推送 GitHub并同步服务器。

## 2026-07-26：重整“更多”为真实业务菜单

- 问题：菜单打开后混入旧壳的字段、映射和异常面板；当前主要为来源数据，但原导出只查 `mc_materials`，会得到空清单。
- 修复：电源页主操作区直接提供“进入电源整理”；电源“更多”仅保留电源整理工作台、功率档管理、批量导入、导出当前清单、操作日志。其他分类仅显示通用的导入、当前分类导出和日志，不再出现电源专属入口。
- 导出：`api/v1/export.php?include_sources=1` 合并导出未映射的 BOM 只读来源；电源当前真实导出来源为323条，并明确标记“旧 BOM（只读）/待整理”。
- 验证：服务器菜单业务合同、PHP语法、不同分类菜单隔离、323条电源来源导出数据和未登录导出 JSON 401 均通过。
- Git：业务提交 `04a42b6efacf346868ee7081920bcba1fdcc5f1c` 已推送 GitHub并同步服务器。

## 2026-07-26：产品适配操作逻辑 V2 重构

- 完成内容：保留现有左侧菜单、顶部栏、三栏结构和全站视觉令牌，重构产品列表、配置规则和选项详情的完整操作顺序；产品和配置组切换均改为局部加载，不刷新整个页面。
- 产品列表：显示型号、名称、系列、真实图片、配置组/选项数量、审批状态和冲突状态；服务端搜索扩展到型号、名称和系列。
- 标准模板：首次空状态提供“生成标准配置”，预览并幂等建立芯片/光源、电源/驱动、光学/透镜、调光、附件、颜色、安装和特殊要求八组；重新套用只补齐或校正模板元数据，不重复插入。
- 配置组：新增业务类型、物料类别、必选/可选、单选/多选、最少/最多数量、启用状态和拖动排序；纯数字、测试词和无意义名称由服务端拒绝；删除前检查审批历史、报价和订单引用。
- 历史清理：线上唯一无选项、无审批的纯数字草稿组 `123` 已在迁移中安全转为标准 `电源 / 驱动`，未物理删除任何被引用数据。
- 物料选择：只读取对应分类的正式物料；支持品牌、型号、功率档、安装、输出类型/电流/电压、调光、质保、供应商和状态筛选；候选项返回完全适配、条件适配、需要审批或不适配及具体冲突原因，并支持批量添加。
- 默认/条件/审批：单选唯一默认，多选最少/最多及多默认；默认变更写日志；条件编辑器仅允许白名单字段、九种运算符和 AND/OR 组合；完成度检查必选组、选项、默认、冲突、停用物料、例外和不完整条件，不通过时服务端禁止审批。
- 商务中心：只读桥接增加 `g.status='approved' AND g.is_enabled=1` 门槛，停用组排除；没有通过物料中心审批的草稿不会同步。当前批准适配数为 0，符合当前没有完整审批版本的真实状态。
- 数据库：迁移 `20260726_015_adaptation_workflow_v2` 已应用；新增字段均在 `mc_adaptation_*` 表，未修改旧 BOM。迁移前备份位于服务器 `_codex_backups/adaptation_workflow_20260726_2235/db_before.json`；临时表 DDL `down → up` 往返通过。
- 修改文件：`material_center_v1/adaptation/index.php`、适配 API/服务/产品只读适配器、适配 JS/CSS、015 迁移和测试；商务中心仅修改只读配置仓库及契约测试；最终集成测试同步调整为验证“不完整配置不得审批”。
- 检查：本地差异/静态契约/JS 解析/PHP 输入语法通过；服务器 PHP 语法、产品适配契约、商务桥接契约、主规范、事务集成、10k 分页全部通过；测试残留为 0。线上页面未登录返回 302 至统一登录，API 返回 JSON 401。
- 浏览器限制：内置浏览器会话列表为空，无法执行登录态点击和截图；已完成服务端真实页面渲染、数据库工作区和 HTTP 验证，未伪报浏览器视觉通过。
- Git/部署：业务提交 `5f45e88b654444be042a3d1c758cd51335cc47bd`，日志字段修复提交 `b0dab17e1a0e6878b5e07a6a21c5b70bdec543dd`；两者均已推送 GitHub，并以同一 Git 对象快进服务器。
- 待业务数据：当前标准电源组候选正式物料为 0，因为现有已设置电源仍是草稿；需先按物料生命周期转正式，才会进入产品适配候选和后续审批。

## 2026-07-26：修复标准配置生成成功后误报失败

- 现象：点击“生成标准配置”后提示 `Cannot read properties of null (reading 'closest')`。
- 根因：请求成功后，异步提交回调继续访问浏览器已清空的 `event.currentTarget`；数据库生成逻辑没有失败。
- 修复：提交开始时固定保存表单、弹窗和提交按钮引用；模板生成、批量添加选项和保存条件三个异步入口统一修正，并兼容 `event.submitter` 为空。
- 数据核对：产品 `56.02312`、`56.02311` 均已成功且各自仅生成 8 个标准配置组；幂等键生效，无重复组。
- 回归：新增契约，禁止异步完成后使用 `closeModal(event.currentTarget.closest(...))`。

## 2026-07-27：物料中心与商务中心三类报价 / 新加坡渠道打通

- 报价模型：商务中心“新建报价”正式拆分为库存品、标准品、定制品三类；产品类型与销售渠道分离，广州直接销售和新加坡网站均作为渠道选择。原网站订单报价保留为未来新加坡订单回流和原始快照审核入口，不再冒充产品类型。
- 库存品：新增库存品报价服务、API、页面和前端闭环；只允许选择有效库存 SKU，保存时重新检查可销售库存，SKU 核心配置锁定；新加坡渠道只允许选择已完成模拟发布且允许下单的 SKU。
- 标准品：继续读取物料中心 `approved + enabled` 配置组与正式可报价物料；商务配置器显示审批版本、快速规则和候选匹配级别。报价行同时保存适配产品、审批版本、组/物料选择、配置护照哈希和完整适配快照。
- 定制品：保持高自由度字段、附件、成本、交期、审核及转项目/订单；若引用标准产品，同时固化该产品当时的已审批适配版本作为参考基线，定制字段仍独立形成护照。
- 报价中心：移除硬编码演示报价，改读真实 `cc_quotes`；增加真实统计、产品类型/状态/渠道筛选和三类快速入口。无数据时明确显示空状态。
- 新加坡渠道：新增公开套餐维护、发布前检查、产品发布队列、代客订单队列、幂等键、载荷哈希、失败重试、实体映射和模拟发送记录。适配器继续明确为 `not_configured`，模拟发送不会请求外部网站，也不会伪报线上成功。
- 数据库：应用 CREATE-only 迁移 `016_quote_channel_bridge.sql`，新增 `cc_quote_channel_context`、`cc_quote_item_adaptation_refs`、`cc_channel_outbox`、`cc_channel_entity_links`；未修改旧表、未写物料中心旧源。迁移前备份为服务器 `_codex_backups/cc_quote_bridge_20260727_102902/before_016.jsonl.gz`，SHA-256 `9cce07fa8604197bd0c1d51c3d98fcce8af9f18f94b94ddb18b0fea7ec54072a`。
- 自动回归：服务器 364 个 PHP 文件语法通过、26 个合同测试通过；库存 SKU→新加坡套餐→幂等产品发布→模拟发布→库存品报价→审核→代客订单→模拟发送真实写入回归通过并自动清理。原标准品、定制品、网站回流、统一报价模型、审批版本、输出单据和订单转换 7 组写入回归全部通过。
- 页面验收：浏览器实际打开线上报价中心、库存品、标准品和新加坡发布页面；三类入口、渠道选择、配置锁定说明、审批适配护照、套餐维护、待发送/重试与 `not_configured` 提示均正常渲染。浏览器没有 ERP 登录态，因此未在 UI 创建正式业务数据；已由服务端真实写入测试覆盖保存链路。
- Git/部署：业务提交 `1897e1ac3147c32dd112fa4f756aa997f150694b` 已推送 GitHub，并通过本机 Git bundle 在服务器从 `b752b637` 快进到相同 Git 对象；服务器自身未配置 GitHub SSH 密钥。
- 后续联调：新加坡网站建好后只需实现真实适配器并提供接口地址/凭据/签名规则，再由现有 outbox 调度发送；在此之前只能使用模拟发送。产品要进入标准品报价，仍须先在物料中心完成审批并启用；产品要进入新加坡直接下单，必须先有可售库存 SKU 和通过检查的公开套餐。

## 2026-07-27：产品适配批量维护、配置复用、状态筛选和图片补齐

- 标准配置：生成弹窗的 10 个标准组现已全部可勾选，提供全选、仅必选核心和清空；单品与批量生成均只处理所选组，幂等补齐，不重复插入。
- 配置复用：新增“套用现有配置”，可选择其他已配置产品作为来源，再勾选具体配置组，以“只补空白”或“覆盖同名组”方式套到当前产品；执行前必须预览，可选同时复制电源范围。
- 产品批量：左侧产品列表新增独立复选框、全选当前结果和清空；已选产品可批量生成所选标准配置，也可直接作为“当前来源产品”的批量套用目标，一次最多 1000 个并按 100 个分段请求。
- 状态筛选：新增全部、未配置、已配置、待审批、待重审、已启用和有冲突筛选，并显示当前结果与总数；服务端返回稳定的 `configuration_state`，不再只靠界面文字猜测。
- 产品图片：产品列表优先使用同步快照，快照缺图时实时回退命名系统的 `web_image_url / source_image_url / image_path`；加载失败自动显示占位图。正式同步读取 245 条，新增 2 条、更新 243 条；当前 229 条有图片，16 条源系统无图。型号 `32.05511` 已返回真实图片地址。
- 写入安全：选择性配置组由服务端再次验证必须属于标准模板或来源产品；套用仍沿用正式物料校验、冲突重建、待重审和操作日志规则。未修改旧 BOM、命名系统产品表或商务中心文件。
- 修改文件：`material_center_v1/adaptation/index.php`、`api/v1/adaptation.php`、`app/Services/AdaptationService.php`、`assets/css/app.css`、`assets/js/adaptation-shell.js`、两个适配批量测试，以及本上下文。
- 检查：本地 JavaScript 语法和差异检查通过；服务器 PHP 语法、批量/快速规则合同、快速规则发现合同、三栏工作台合同、适配 V2 合同、真实选择性生成/指定组套用写入回归、最终业务集成和 10k 分页均通过，测试残留为 0。
- 页面验收：服务器 CLI 页面真实渲染通过；线上浏览器访问会跳转 ERP 登录页，当前自动化会话无 ERP 登录态，因此未伪报登录态点击和视觉验收。
- Git/部署：业务提交 `e9551baef` 已推送 `origin/main`，并通过 Git bundle 快进服务器 `/www/wwwroot/Artdon/artdon_erp/`；本记录提交后继续按同一流程同步，最终以三方 HEAD 核对为准。
- 未完成事项：代码、数据同步和自动回归无遗留；业务人员登录后只需抽验产品筛选、多选、标准组勾选和单品套用四个入口。

## 2026-07-27：芯片规格模板、产品适配三级联动与商务报价桥接

- 芯片主数据：一个芯片料号现可维护多个具体规格组合，覆盖色温、CRI、SDCM、R9、光通量、光效、供应商规格号、采购价、库存和交期；默认出货规格唯一，历史色温范围原样迁入并标记待确认，不擅自拆分不存在的色温。
- 模板与版本：新增一个系统默认模板和多个命名模板；色温、显指、色容差均支持复选与自定义值，系统生成笛卡尔组合后仍可逐项取消无效组合。模板每次保存生成新版本，不会静默修改已用芯片；芯片记录已用模板版本和可同步状态。
- 批量维护：芯片清单支持勾选后批量套用规格模板，一次最多 1000 个；多个模板自动合并去重。执行前必须预览新增、保留、停用和审批保护数量；支持只补缺失或明确替换，已被审批产品引用的规格不会直接停用。
- 产品适配：进入页面时只显示产品列表；点击产品后显示配置总览和配置组；点击配置组后才显示选项详情。完整配置总览集中显示标准默认、可选项、禁止选配、快速规则、芯片具体规格和审批状态。
- 芯片适配：芯片物料提供全部规格能力，单个产品在芯片选项内勾选允许的规格子集并指定一个产品默认规格；后端验证规格归属和启用状态。新增芯片选项默认继承当前全部有效规格，批量套用配置时连同产品规格子集复制。
- 审批保护：产品审批新增“芯片选项必须选择具体规格”和“不得包含停用 / 待确认规格”两项检查；芯片主数据直接停用已经被审批产品引用的规格会被拒绝，避免已生效报价能力被静默改变。
- 商务中心：批准后的芯片物料选项按具体规格展开为报价选项，例如 `1507 · 3000K / CRI90 / SDCM≤3`。报价配置护照与适配快照保存物料 ID、规格 ID、色温、显指、色容差、R9、模板版本、供应商规格、价格、库存和交期；只有“默认物料 + 该物料默认规格”会成为组默认值。
- 数据库：迁移 `20260727_018_chip_specification_templates` 已应用，新增模板、模板版本、芯片已用模板、具体规格、产品规格关联和同步日志六张表；旧 BOM、命名产品和已有报价表未改。迁移后系统默认模板 1 个、历史芯片规格 1 条，测试夹具残留均为 0。
- 检查：全部新增/修改 PHP 文件语法、三个本地 JavaScript 静态测试、差异检查通过；服务器芯片模板/适配/报价桥接合同与真实写入回归、原批量适配、七类字段、最终验收、权限角色、来源整理、商务适配桥接和配置引擎报价快照回归全部通过。
- 页面验收：服务器真实渲染确认产品适配初始 `data-stage="products"`，配置总览与芯片规格弹窗存在；芯片页确认“规格组合”“模板管理”“批量套用规格模板”和新版脚本输出，两个表单不存在嵌套。自动化浏览器可访问站点但没有 ERP 登录态，正常跳回登录页，因此未伪报登录态点击或截图通过。
- Git/部署：业务及修复提交 `ed0b47a`、`e25154e`、`c9a2276`、`f48dbc3`、`c169907` 均已推送 GitHub，并以 Git bundle 快进服务器；本记录提交后继续同一流程，最终以本地、`origin/main`、服务器三方 HEAD 一致为准。
- 业务使用顺序：先在“物料中心 → 芯片 → 模板管理”维护常用模板，再在“规格组合”单个或批量套用；之后在“产品适配”选择芯片物料和该产品允许的具体规格，完成审批后，商务中心标准品报价即可直接选择具体芯片规格。

## 2026-07-28：CRM 推广第 7 步时间计划执行明细

- 用户确认时间计划不能只显示汇总，选择开始时间、发送间隔和时区后，必须能看到每一封邮件或每条人工任务的预计执行时间。
- 修复：第 7 步现将可执行目标分为自动邮件、人工执行、待处理和按规则跳过四类；自动邮件显示预计发送时间，WhatsApp / 微信 / 电话等人工渠道显示预计生成待办时间及执行人，不再因不是邮件渠道而显示空表。
- 未分配发件邮箱的邮件会保留在“待处理”明细，明确说明补齐邮箱前不会排入时间；无邮箱或重复邮箱按第 8 步策略显示为“已跳过”，不再把未执行目标伪装成已排期。
- 开始时间、执行方式、时区、发送间隔、每小时上限、每日上限以及邮箱/线下执行人选择发生变化时，第 7 步会自动重新计算预览；开始时间在手动、定时和自动三种执行方式下均可用于预览。
- 页面缓存版本已更新；新增时间计划合同测试并纳入 PHP CI。功能提交 `f052c1610ea37b92f0c90cd5e7fd03b1529550ed` 与本记录提交已推送 GitHub，并以同一 Git 对象快进腾讯云正式目录；服务器 PHP 语法、1–9 步流程、第 9 步邮件预览和时间计划合同均通过，三方 HEAD 已核对一致。

## 2026-07-28：派工截止日期修改限制

- 规则：普通账号不能修改当天到期或已经逾期的派工截止时间；管理员不受锁定和次数限制。默认每条单人派工或每个多人派工组从创建到结束累计最多修改 2 次，不会每天重置。
- 设置：入口已调整为“派工待办 → 更多设置 → 截止时间规则”；管理员可调整累计最多次数（0 表示不限）和“到期前禁改天数”（默认 0，即当天起锁定；例如设为 2，则到期前两天也锁定）。系统设置中心不再重复显示该业务规则。
- 强制执行：详情保存、表格单元格编辑、移动端编辑和多人派工的组级修改均由后端统一校验；次数记录独立保存到 `dispatch_next_due_change_events`，多人派工按组统一计数，不能仅靠改前端绕过。
- 使用体验：列表、详情和多人编辑弹窗会显示具体不可修改原因并禁用截止时间；累计次数汇总在单次请求中只查询一次，并有任务/多人组索引，避免为每条待办重复查询而影响派工页面速度。
- 回归：新增派工截止日期规则合同测试并纳入 PHP CI；部署后需完成服务器 PHP 语法、规则合同、当前账户可见性合同和三方 Git HEAD 核对。

## 2026-07-28：派工到期水红色提醒

- 默认视觉：今日到期的未完成待办使用浅水红 `#fff1f2`，已过期的未完成待办使用更明显的浅水红 `#ffe4e6`；严重逾期进一步加深，但不影响已完成或已取消任务。
- 自定义：进入“派工待办 → 更多设置 → 表格设置 PRO → 到期状态颜色”，可分别修改今日到期、逾期和严重逾期的底色、日期文字与左侧提示线；“到期提醒规则 → 到期行填充”默认已开启，仍可按个人需要关闭。

## 2026-07-28：简化派工到期填充

- 根因：页面末尾的旧式硬编码样式强制给快到期、明天到期、今日到期和逾期都填色，覆盖了“表格设置 PRO”中保存的个人颜色，造成颜色与设置不一致、整张表过于杂乱。
- 修复：填充分为“不填充（仅日期/左线/呼吸点）”“只填充今天到期与逾期（推荐）”和“全部到期阶段填充”三档；旧的“开启 6 档颜色”自动按推荐档解释，不再把整张表染成黄红色。
- 水红推荐色：设置弹窗增加“使用水红推荐色”，一次恢复今日到期、逾期和严重逾期的推荐色，并设为推荐填充档；用户仍可在同页逐项自定义任意颜色。

## 2026-07-28：派工两种到期填充与截止时间规则说明

- 到期填充重新收敛为两种：快到期和已过期。快到期天数现直接参与前端分类；设为 1 时只提示今天与明天，设为 0 时只提示当天，超过范围不填色。旧的明天、今天、严重逾期等多档填色不再参与表格。
- 设置页只保留“快到期填充”和“已过期填充”两项颜色，及“填充 / 不填充”两项选择；已过期使用红色、快到期使用浅橙色，均可恢复推荐色后再分别调整。
- 截止时间规则的后端限制已覆盖详情、表格单元格和多人组保存。当前登录账号为管理员时按既定规则不受限制；设置页明确提示应使用普通账号验证当天到期锁定和每日次数限制，避免把管理员可修改误判为规则失效。

## 2026-07-28：修复派工自定义填充色被旧规则覆盖

- 根因：旧版六档到期样式使用了带多个状态条件的 `!important` 选择器，优先级高于后加入的两种填充规则，导致列表仍显示旧的固定黄色和桃红色，而不是表格设置中保存的颜色。
- 修复：两种填充规则现使用同等状态条件与 `!important` 优先级；快到期直接读取 `--due-soon-bg`，已过期直接读取 `--due-overdue-bg`。例如用户保存 `#fffbea`、`#fdfaf7` 后，列表对应行将准确显示这两个值。
- 修改文件：`dispatch_next.php`、`WORK_CONTEXT.md`。
- 检查与部署：待完成本地静态检查、GitHub 推送、正式服务器快进与服务器回归后补充。

## 2026-07-28：个人待办截止锁定与统一新增派工窗口

- 截止时间规则：后端不再排除 `personal` 类型；普通账号的个人待办、单人派工和多人派工均遵守当天/逾期锁定及累计修改次数，管理员仍是唯一例外。

## 2026-07-28：澄清派工截止时间累计修改次数

- 用户明确“每条派工每日最多修改次数”的原意是每条任务总共只能改两次，而非隔天重新获得两次额度。
- 修复：设置项和界面改为“累计最多修改次数”；普通账号的单人、个人和多人组均按该任务/组全部历史截止时间变更记录累计计算，达到 2/2 后永久锁定该字段。管理员保持例外，填 0 仍表示不限次数。
- 兼容：已保存的旧 `max_changes_per_day` 数值会自动作为新累计上限读取；下一次保存后写入新字段 `max_changes_total`。旧审计记录会直接计入累计次数，不会被清零。
- 检查与部署：功能提交 `19868b1e37f9afe2b282220f63c8c8363f17abcf` 已推送 GitHub 并快进腾讯云正式目录；服务器已执行派工架构初始化以补齐累计计数索引，`dispatch_next_api.php`、`dispatch_next.php`、`dispatch_next_schema.php`、`includes/settings_service.php` 语法检查及派工截止规则合同测试均通过。

## 2026-07-28：产品适配首页版式重整

- 问题：未选择产品时，渐进式工作台把“产品列表”限制为 720px 居中窄栏，右侧留下大块空白，视觉和工作效率都很差。
- 调整：产品列表阶段改为占满工作区的产品目录；搜索、状态筛选和全选操作集中在顶部，产品改为自适应多列卡片，显示更大的图片、型号、名称、系列、配置数量、审批状态和冲突状态。选择产品后仍按原有联动进入“产品列表 + 配置组”，再点配置组才进入三栏选项详情，不改变业务流程。
- 同时：配置组阶段的左右栏比例加宽，避免配置规则仍被挤成窄栏。
- 检查与部署：功能提交 `6bb5ded13927bf4887f0f9509936386b6a3b7934` 已推送 GitHub 并快进腾讯云正式目录；服务器产品适配工作台合同测试通过。本地 `ui_static_test.js` 通过；服务器未安装 Node，因此该项静态检查仅在本地执行。
- 长工作清单：任务与多人组的 `project` 字段升级为 `TEXT`，接口、表格编辑、创建/修改/周期任务均支持最多 8,000 字，可保存多行编号工作事项。
- 创建窗口：新增个人、单人派工和多人派工改用统一结构；标题与时间优先，`项目 / 工作清单` 为全宽大编辑区，补充说明为次级可选项。个人待办负责人固定为本人；单人派工必须明确选择负责人；多人派工必须选择至少一名执行人，并使用统一滚动选择区。
- 错误体验：保存前校验标题、负责人和执行人；后端错误会显示为“保存失败：具体原因”，不再笼统显示接口异常。
- 修改文件：`dispatch_next.php`、`dispatch_next_api.php`、`dispatch_next_schema.php`、`tests/dispatch_due_change_policy_contract.php`、`WORK_CONTEXT.md`。
- 检查与部署：待完成本地静态检查、GitHub 推送、服务器数据库迁移、回归和三方版本核对后补充。

## 2026-07-28：派工待办五秒轻量同步与列表性能优化

- 实时体验：页面每 5 秒只检查派工任务和多人组是否有更新；没有变化时不会重复下载完整列表，发现创建、修改、完成或删除时才无感刷新。手动刷新、切回页面和重新进入页面仍会立即同步。
- 启动提速：用户资料、当前账号、个人筛选、提醒规则和表格偏好改为并行准备，减少初次打开时串行等待。
- 查询提速：任务列表不再为每条任务分别查询附件、图片、有效附件和步骤数量；现改为两次汇总查询后一次性回填，避免任务增加时查询次数线性放大。
- 轻量检查：同步版本从九张表的重复统计收敛为任务和多人组两张表，并为两张表的更新时间建立索引；通知仍按原独立周期刷新，避免遗漏红点更新。
- 数据安全：长项目/工作清单继续完整读取和保存，不因性能优化截断内容；不改变派工、权限、截止时间限制和历史数据。
- 回归：新增 `tests/dispatch_list_performance_contract.php`，校验不会恢复逐行统计、30 秒无条件全量刷新或 2 秒高频全量版本扫描；发布前后执行 PHP 语法、已有截止时间/可见性合同和三方版本核对。
- 发布：代码提交 `59fa4e7b53b8068ff36b6b364435e5c6b8700155` 已推送 GitHub，并以同一 Git bundle 快进正式服务器；服务器已确认两个更新时间索引存在。服务器 PHP 语法、性能合同、截止时间规则合同和当前账号可见性合同均通过；本地 JavaScript 语法和差异检查也通过。

## 2026-07-28：CRM 报价摘要与报价跟进窗口修复

- 报价预览：CRM 的“预览报价”不再嵌入商务中心完整报价页面；现仅显示报价号、客户、金额、日期、负责人、联系人、当前流程和已有跟进数，CRM 只承担联动查看与跟进入口。
- 跟进保存：核对发现此前“写跟进/标记客户回复”本身已成功入库，但截图上传失败后前端把整个操作误报为失败，导致同一内容被重复保存。现将保存和上传分开反馈：跟进保存成功后，即使图片失败也会明确提示“跟进已保存，但截图未上传”，并关闭窗口刷新记录，避免用户重复点击保存。
- 截图上传：上传响应统一按文本再解析，避免把服务器 HTML 错误抛成 `Unexpected token '<'`；超过 1.5MB 的 JPG/PNG/WebP 会在浏览器自动压缩，单次最多 4 张；后端也会返回明确的大小、临时文件和部分上传错误说明。
- 弹窗排版：报价跟进改为报价摘要头部、核心沟通字段、清晰的大文本区、独立截图卡片和压缩后的历史区；上传区支持点击、拖放和粘贴，按钮与历史操作保留。
- 回归：新增报价跟进 UI 合同测试，发布前后执行 JavaScript/PHP 语法、报价跟进事务/历史操作合同和三方版本核对。
- 发布：功能提交 `f8c649252749ec7803d317262d8df164028d2ce6` 已推送 GitHub 并以同一 Git bundle 快进正式服务器；服务器 `crm_task_center.php`、`crm_api.php` 语法通过，报价跟进 UI、事务、历史操作合同及历史操作真实写入回归均通过。

## 2026-07-28：CRM 报价真实产品摘要与单滚动跟进窗口

- 报价预览：保留 CRM 的轻量只读定位，但不再只显示空壳摘要；预览会从原报价或新版报价版本读取产品行，显示产品图片、型号、名称、规格、关键元器件、数量、单价和行金额。
- 订单联动：报价已转订单时，预览同时显示订单号、内部状态、收款状态、出货状态和预计出货日；尚未转订单时明确显示“尚未转订单”，不伪造订单资料。
- 跟进窗口：取消报价跟进内容区和历史区的嵌套滚动，统一交由弹窗主体垂直滚动；截图投放区同步压缩高度，避免内容尚未展开就出现多条滚动条。
- 回归与发布：业务提交 `89045193533138bed626bafc0832dc8a74825fb6` 已推送 GitHub，并以同一 Git bundle 快进正式服务器；服务器 `crm_task_center.php`、`crm_api.php` 语法通过，报价跟进 UI、事务、历史操作合同和历史操作真实写入回归均通过。发布记录提交后继续按同一流程同步，最终以三方 HEAD 核对为准。

## 2026-07-28：CRM 任务中心单一纵向滚动

- 根因：报价订单流程中心同时存在模块主滚动、报价列表内部滚动，以及右侧 ACTIONS 内部滚动；长报价列表会在左、右两侧各出现一条纵向滚动条。
- 修复：任务、样品和详情内容不再各自设置视口高度上限，由任务模块主内容区统一承载纵向滚动；报价流程右栏仅保留报价筛选、流程工具和当前报价操作，不再重复显示任务视图；任务页 ACTIONS 不再独立纵向滚动。
- 额外整理：工作台网格不再建立第二个纵向滚动容器；邮件三栏、表格横向滚动、下拉菜单、附件预览与弹窗主体滚动仍保留，因为它们属于独立交互区域而非同一页面内容的重复纵向滚动。
- 防回归：任务中心稳定性合同新增主滚动状态、任务列表无内部滚动和右栏限制检查。
- 本地检查：JavaScript 语法与空白差异检查通过；本机未安装 PHP，PHP 合同测试将在服务器部署后执行。浏览器自动会话未带 ERP 登录态，跳转登录页，未伪报页面视觉验收。
- 发布：功能提交 `fd15c77c216b5cbc1adf5efba715006103c19bde` 已推送 GitHub 并以同一 Git bundle 快进正式服务器；服务器 `crm_task_center.php`、`crm.php` 语法及任务中心稳定性合同均通过。发布记录提交后继续按同一流程同步，最终以三方 HEAD 核对为准。

## 2026-07-28：报价跟进历史截图可见性与上传确认

- 数据核查：正式服务器当前 `crm_quote_followup_files` 有效档案数为 0，存储目录文件数也为 0；用户截图对应的 2026-07-28 12:36 跟进记录同样为 0 张。因此旧截图并非被历史页遗漏，而是此前没有成功保存到服务器，无法恢复。
- 历史查看：每条历史跟进现明确显示“沟通截图 N 张”；详情始终显示截图区。存在图片时显示缩略图，点击可打开原图；没有图片时会明确说明该条没有成功保存截图，并提供“修改并补传图片”入口。
- 上传确认：保存跟进后，前端必须收到与所选数量一致的服务器 `saved_ids` 才会提示图片已保存；否则清晰提示“跟进已保存，但截图未上传”，避免把本地缩略图误认为已入库。
- 修改文件：`assets/crm/crm.js`、`assets/crm/crm.css`、`tests/crm_quote_followup_ui_contract.php`、`WORK_CONTEXT.md`。
- 检查与发布：本地 JavaScript 语法和差异检查通过；功能提交 `a0556afae7e9e3bdc2ab0728b30544903b5444d8` 已推送 GitHub 并以同一 Git bundle 快进正式服务器。服务器 `crm_task_center.php`、`crm_api.php` 语法和报价跟进 UI 合同均通过；发布记录提交后继续按同一流程同步，最终以三方 HEAD 核对为准。

## 2026-07-28：CRM Outlook / Foxmail 邮件正文空白修复

- 数据核查：用户反馈的 Boris 邮件在 CRM 数据库中并未丢失正文；对应记录 `2102` 的 HTML 正文约 440 KB、纯文本约 54 KB，纯文本开头即为 “Hi Amy / If we are going to develop…”。问题只发生在浏览器展示 Outlook / Office 完整 HTML 文档时，并非收信失败。
- 修复：识别含 Word / VML / Mso 标记的 Outlook / Office 邮件；当其已保存纯文本正文时，邮箱主阅读区及客户侧邮件预览改用兼容阅读版显示正文、换行和签名，避免隔离原 HTML 框渲染成空白。普通 HTML 邮件仍按原显示方式处理。
- 防回归与发布：新增 Office / Outlook 邮件正文可读性静态合同。本地 JavaScript 语法、差异检查通过；功能提交 `2081448751362c6f48b32cf6dfc16982e2899ee6` 已推送 GitHub 并以同一 Git bundle 快进正式服务器。服务器 `crm_mail.php`、`crm_api.php` 语法及新合同均通过；本发布记录提交后继续按同一流程同步，最终以三方 HEAD 核对为准。

## 2026-07-28：报价跟进沟通截图上传失败修复

- 数据核查：用户刚新增的 14:29 跟进记录 `29` 仍为 0 张，服务器全部有效报价跟进截图也是 0 张，证实不是历史展示漏图，而是文件没有上传成功。
- 根因：上传代码写入 `uploads/crm_quote_followups`，但生产环境 PHP-FPM 以 `www` 账户运行，而 `uploads` 根目录不允许 `www` 创建子目录；实际检查为 `www_can_write_uploads=no`。可写的应用存储目录 `storage/visit_files` 已验证为 `www_can_write_visit_storage=yes`。
- 修复与发布：截图改存 `storage/visit_files/quote_followups/{跟进ID}`；创建后检查可写权限，移动后再次检查实际文件已存在，失败会返回明确原因而不伪报上传成功。功能提交 `4b5388ad5cb8c0f23ea13302ddad9887957935b3` 已推送 GitHub 并以同一 Git bundle 快进正式服务器。服务器 PHP 语法、报价跟进 UI 合同均通过，并以 `www` 账户真实验证新目录可创建、可写、测试目录已清理；本发布记录提交后继续按同一流程同步，最终以三方 HEAD 核对为准。

## 2026-07-28：CRM 邮箱统计语义与未回复修复

- 数据核查：`tech@artdon.cn` 有效邮件共 159 封（收件箱 93、已发送 65、已归档 1）；已关联 1、未关联 158 是对全部有效邮件的可重叠分类，数值正确。未回复原显示 116 则确认错误：93 封收件箱邮件被历史逻辑误标，真正的已发未回复为 22 封。
- 修复：收件箱新邮件不再写入 `is_unreplied=1`；所有未回复侧栏/工作台统计和筛选仅计算已发送邮件；刷新时会自动清理非已发送邮件的历史误标。页面文案改为“已发未回复”，避免与收件箱未读混淆。
- 防回归与发布：新增邮箱统计语义合同。本地 JavaScript 语法和差异检查通过；功能提交 `292f81d9722aa6c9454008eddb9d249adeaefb09` 已推送 GitHub 并以同一 Git bundle 快进正式服务器。服务器 `crm_mail.php`、`crm.php` 语法与新合同通过；已对全部有效邮箱执行历史误标清理，`tech@artdon.cn` 清理后收件箱误标为 0、已发未回复为 22。本发布记录提交后继续按同一流程同步，最终以三方 HEAD 核对为准。

## 2026-07-28：产品适配完整模板、持续物料总览与内联电源范围

- 完整模板：将原本不够明确的“生成标准配置”改为“完整标准配置模板”。默认仍然全选 10 个标准配置组；重新套用时只补齐缺失组，不会删除或重复已有物料。特殊产品仍可在确认前取消不需要的组。
- 持续总览：产品适配右侧新增常驻“已选物料持续显示”卡片，列出每个配置组的默认物料（没有默认时显示候选物料）和状态。切换芯片、电源、光学时，已选芯片不会消失；点击任一条即可直接回到该组编辑。
- 关键范围：芯片和光学继续使用各自的范围字段；电源 / 驱动不再要求离开产品适配页前往独立页面。现在可直接维护内置/外置、恒流/恒压、功率、电流与电压上下限、最大长宽高、调光、质保和认证，并立即重算现有电源候选的适配状态。
- 安全与回归：新增产品适配持续显示与内联电源规则合同，保存后会将相关产品及电源组退回草稿并重算候选项，保留审批链路。功能提交 `67a06fab576393300d920b3411dd298238236b48` 及两次测试同步提交已推送并快进正式服务器；服务器 `AdaptationService.php`、适配 API 与页面语法均通过，新增持续显示/内联电源规则合同、原快速规则合同和工作流合同均通过。最终以本发布记录同步后的三方 HEAD 核对为准。

## 2026-07-28：派工列表操作按钮固定对齐

- 根因：操作列过去按实际权限直接省略“转派”或“催办”按钮；当某一行只剩查看和删除时，弹性居中会使两个图标向中间移动，无法与有三个或四个图标的行对齐。
- 修复：桌面表格操作列改为固定四个操作位：查看、转派或多人修改、催办、删除。无权限的操作位保留空位且不可点击，因此每一类图标在所有行始终处于同一水平位置；手机横屏继续保留“更多”菜单，避免强行压缩。
- 防回归：更新多人派工表格对齐合同，覆盖固定操作位及空位保留规则。
- 发布：待完成本地检查、GitHub 推送及正式服务器复检后补充提交号。

## 2026-07-28：产品适配可复用配置模板库

- 目标：把“同一电源 / 芯片 / 光学要重复选五次”的逐产品操作，改为一次保存、可反复映射的配置模板。模板可以自由勾选任意配置组，例如仅电源、芯片 + 电源、或芯片 + 电源 + 光学；未勾选的组绝不覆盖目标产品。
- 实现：新增 `mc_adaptation_reuse_templates` 数据表与产品适配模板库。模板记录来源产品、所选配置组和是否包含电源关键范围；保存后可从模板库选择一个或多个目标产品，先预览新增 / 覆盖 / 保留数量，再按“只补空白”或“覆盖同名组”执行。
- 安全规则：每次执行仍复用原有批量套用的正式物料、适配性、默认项、条件、冲突关系和审批校验。模板保留来源产品的已选配置组引用，因此来源产品的共用配置更新后，下一次套用会使用最新内容；如果来源组被删除，模板会明确标为需重建并禁止套用。
- 修改文件：`material_center_v1/database/migrations/20260728_019_adaptation_reuse_templates.php`、`material_center_v1/app/Services/AdaptationService.php`、`material_center_v1/api/v1/adaptation.php`、`material_center_v1/adaptation/index.php`、`material_center_v1/assets/js/adaptation-shell.js`、`material_center_v1/assets/css/app.css`、`material_center_v1/tests/adaptation_reuse_templates_contract.php`、`tools/ci_php_checks.sh`、`WORK_CONTEXT.md`。
- 检查与发布：待完成本地 JavaScript / 差异检查、提交推送、正式服务器迁移与 PHP 合同回归后补充提交号。

## 2026-07-28：派工操作列仅完成行对齐

- 问题修正：上一版把固定四个操作位应用到了全部派工行，导致未完成的个人派工和多人派工也被拉宽，破坏原有紧凑排版。
- 正确范围：未完成行恢复原有按实际可用操作紧凑显示；只有已完成或已取消行保留固定操作位，让“查看 / 删除”等剩余按钮跨行对齐。
- 防回归：多人派工表格合同明确验证固定操作位只能出现在完成或取消分支。
- 检查与发布：内联 JavaScript 语法和差异检查通过；功能提交 `5af255c4d78a74232bd8bd45bb296ebc38f1b0f5` 已推送 GitHub，并以同一 Git bundle 快进正式服务器。服务器 `dispatch_next.php` 语法与“仅完成或取消行使用固定操作位”合同均通过；本发布记录提交后继续同步，并以最终三方 HEAD 核对为准。

## 2026-07-28：仅修复 Boris Office 邮件的正文误判

- 数据核查：Boris Duhamel 对应邮件记录 `2102` 的 HTML 正文和纯文本正文均完整保存；纯文本以 “Hi Amy” 开头。问题不在收信或数据丢失。
- 根因：该封邮件的纯文本签名使用 `<amy@...>`、`<https://...>` 形式包裹地址。原 Office 兼容阅读器把这些地址误判成 HTML 标签，随后交给 HTML 解析器，造成正文被吞掉而只剩“描述自动生成”。
- 修复范围：只修正“地址包裹不属于 HTML 标签”的判定，不重写、不重新抓取、不批量修改任何邮件数据；真正含 Office HTML 标签的邮件继续走原兼容阅读逻辑。
- 检查与发布：本地以这封邮件的地址结构验证误判消失、真实 HTML 仍可识别，且 JavaScript 语法和差异检查通过。功能提交 `99f94680e1c443da701167ea202b0e86bcecfa1f` 已推送 GitHub 并以同一 Git bundle 快进正式服务器；服务器 `crm_mail.php` 语法、Office 正文兼容合同均通过。发布记录提交后继续同步，并以最终三方 HEAD 核对为准。

## 2026-07-28：产品适配工作区主次重排

- 问题：进入芯片、电源等具体配置组后，中间区域同时显示产品身份、完成度、大型配置总览、说明和配置组列表；真正要操作的配置组被连续摘要挤到下方，而右侧本来已存在“已选物料持续显示”。
- 调整：顶部产品型号、名称、系列和完成度压缩为一条紧凑摘要；编辑某个配置组时，中间不再重复显示配置总览，配置组工作区紧接在摘要下方。右侧仍保留已选物料持续显示，以便切换芯片、电源、光学时随时查看和返回编辑。
- 保留：未进入配置组时仍显示产品级配置总览；配置模板库、完整标准模板、从产品复制、批量套用和现有数据均未改变。
- 检查与发布：新增版面主次合同，验证编辑状态收起中间重复总览并压缩工作区摘要。本地 JavaScript 语法和差异检查通过；功能提交 `4a067786a4c780d48d3051406f6254339087e8d6` 已推送 GitHub 并快进正式服务器。服务器 `adaptation/index.php` 语法、版面主次合同、三栏工作流合同、可复用模板合同均通过；本记录同步后以三方 HEAD 核对为准。

## 2026-07-28：派工完成行操作按钮三格对齐

- 根因：完成行此前使用四格固定轨道（查看、修改/转派、催办、删除），而截图中的未完成个人及多人派工自然显示三格（查看、修改/转派、删除）；完成行的删除因此被推到第四格，和未完成行错位。
- 修复：完成或取消行统一使用三格轨道：查看固定第一格，修改/转派或催办固定第二格，删除固定第三格。中间无操作时仅隐藏该格，不改变左右按钮位置；未完成行不改变原有操作与排版。
- 检查与发布：更新多人派工表格对齐合同。本地差异检查通过；功能提交 `f5b59e40c539b7b2d9aab44da4859887c5530671` 已推送 GitHub 并快进正式服务器。服务器 `dispatch_next.php` 语法和对齐合同均通过；本记录同步后以三方 HEAD 核对为准。

## 2026-07-28：产品适配工作区交换与关键范围候选物料可见化

- 工作区：保留左侧产品列表；把“选项详情”移到中间主工作区，承载产品摘要、配置总览、关键范围、候选物料、默认项和条件；“配置组工作区”移到右侧，并以紧凑导航和已选物料摘要持续显示当前配置。
- 候选结果：保存芯片、电源、光学等关键范围后，前端立即请求该组的正式物料候选，并在中间主工作区明确显示完全适配、条件适配、需确认和不匹配的数量、物料编码、规格和原因；用户点“查看全部并选择”后才加入候选，系统不会自动写入物料。
- 真实数据验证：用户截图对应的配置组 `146` 当前有 3 个正式芯片候选，其中 2 个因电流资料缺失而“需确认”，1 个与 5–15W / 150–350mA / 12–37V 范围不匹配。旧页根因是保存关键范围只校验既有选项，未加载候选结果，因而误显示为空白。
- 防回归与发布：新增“选项主工作区与候选发现”合同，并更新旧快速规则合同以验证新的工作区文案。功能提交 `3418201bf88579cf8634872feb9c82536437d9fe` 和测试同步提交 `f5828353cba722eb0aae65f58852b5f985904317` 已推送 GitHub 并快进正式服务器；服务器页面语法、选项主区、版面主次、三栏工作流、关键范围、电源规则和模板合同均通过。发布记录同步后以三方 HEAD 核对为准。

## 2026-07-28：产品适配当前产品锁定与三栏宽度可调

- 当前产品：生成配置组或切换配置组后，左侧列表顶部固定显示“当前锁定产品”，包含型号、名称和系列；列表会自动定位到当前产品。点击“定位”会把左侧列表筛到当前产品，避免例如 `51.07518` 因排序位置而看起来消失。
- 三栏宽度：产品列表、中间选项主区、右侧配置组工作区之间新增两条可拖动分隔线。可拖动扩大左侧产品列表或右侧配置组工作区；宽度存于当前浏览器，下一次打开保持。窄屏自动改为纵向布局，不强制拖动。
- 布局修正：主工作区改用可收缩的中间列，右侧配置组不会被候选物料长内容挤到屏幕外；产品选择阶段仍保持原来的全宽产品浏览布局。
- 修改文件：`material_center_v1/adaptation/index.php`、`material_center_v1/assets/js/adaptation-shell.js`、`material_center_v1/assets/css/app.css` 及三个产品适配合同。
- 检查与发布：本地 JavaScript 语法、差异检查通过；功能提交 `6899f75949df901fcf18e3656eba60265bd4c5e8` 已推送 GitHub 并以同一 Git bundle 快进正式服务器。服务器页面语法、选项主区、版面主次、三栏工作流、快速规则和电源规则合同均通过；本记录同步后以三方 HEAD 核对为准。

## 2026-07-28：产品适配模糊搜索、审批入口与电源功率范围

- 搜索修复：左侧型号 / 名称 / 系列搜索恢复为输入后自动模糊检索（220ms 防抖），回车仍可搜索；“定位”只在当前结果中滚动到锁定产品，绝不再把搜索词强行改成完整型号或覆盖用户的筛选结果。
- 审批入口：选项详情标题区增加固定可见的“审批 / 发布”按钮，选中配置组后可直接打开审批标签并自动横向定位，不必再去最右侧寻找隐藏标签。
- 两块配置组信息：右侧“已选物料持续显示”和下方“配置组工作区”的标题、辅助文字和卡片高度统一，避免一块过小、一块过大的视觉断层。
- 电源功率：电源关键范围由单个“灯具功率”改为“灯具最低功率 / 灯具最高功率”。新迁移 `20260728_020_adaptation_power_range` 保留旧单值规则并回填为同一上下限；最高功率继续用于自动排除输出不足的电源。最低功率也会随产品模板复制，不会再丢失。
- 发布与检查：功能提交 `f894cf9d873f7aaeef0d3f4a2ae801c05b65ba57` 和测试修正 `3e472300b00212be60df58a1c609c12a6836ca84` 已推送 GitHub 并快进正式服务器；服务器迁移成功，页面与服务语法通过，选项主区、持续显示 / 电源范围、快速规则和可复用模板合同均通过。待本记录提交同步后核对三方 HEAD。

## 2026-07-28：产品适配电源最低功率真正参与候选判定

- 补齐缺口：前一版产品规则已能填写“灯具最低功率 / 最高功率”，但电源物料只有最大输出功率，最低值不能参与自动适配判定。本次新增电源物料字段“最低输出功率（W）”，并在电源编辑、批量设置、物料工作台和产品适配候选查询中完整接通。
- 判定规则：产品要求范围为 `A–B W` 时，候选电源必须满足“最低输出功率 ≤ A 且最高输出功率 ≥ B”。最低或最高资料缺失时只会显示“需要审批”，不会误报完全适配；最低值高于产品要求时明确显示不适配原因。
- 数据安全：不回填历史电源的最低输出功率，避免把额定功率误当作可用下限；现有资料仍可查看和编辑，补充最低值后即可获得自动判定。
- 发布与检查：功能提交 `f57707f9c5b729abe948ec7a06816d2c67fbda21` 已推送 GitHub 并以同一 Git 提交快进正式服务器，迁移 `20260728_021_power_output_range` 已成功执行。随后两条过时合同已更新为验证当前候选发现与功率范围行为（`e47b3edf4c30aeaf3d462c44b1e364f714d1ef5c`、`3ee1e391db3db1d7860e01fe3f48f2e58b0bbab6`）；持续显示/电源范围、选项工作区、快速规则、可复用模板、批量规则、布局和完整工作流合同均通过。发布记录同步后再核对三方 HEAD。

## 2026-07-28：产品适配参考工作台重构

- 视觉结构：总览页固定为“左侧配置总览 + 右侧 420px 真实选项编辑器”，顶部产品身份、完成度、缺失 / 冲突 / 待审批 / 正式选项指标合并为一张主卡；下方按四列展示配置组卡片，突出默认物料、完成状态和下一步动作。
- 真实逻辑保留：右侧候选物料仍按当前关键范围实时筛选，点击候选只会打开原有物料库进行勾选，不会自动写入产品。默认项、候选项、适用条件、替代关系、价格交期、审批发布、配置模板与批量矩阵均继续使用既有接口。
- 视图隔离：总览与“引导配置”不再共用挤压布局。引导配置恢复为可拖动的产品列表 / 主工作区 / 配置组工作区三栏，避免切换后留下空白轨道或把编辑器压成窄列。
- 回归与发布：功能提交 `606d5a29ae5a6d27ed832dfe327342c83a33d06f` 已推送 GitHub，并以同一 Git bundle 快进正式服务器。服务器页面语法及总览、三栏工作流、候选发现、快速规则、可复用模板、持续显示 / 电源范围、批量规则、完整工作流等 8 项合同全部通过。发布记录同步后核对三方 HEAD。

## 2026-07-29：产品配置工作台白屏修复

- 根因：工作台改版后，前端初始化仍在读取已从页面结构删除的 `data-rule-subtitle` 节点。首轮 `render()` 因空节点写入异常中断；产品总览本身处于隐藏状态，因而页面只剩下顶部和侧边栏，看起来像整块白屏。
- 修复：统一改为实际存在的“选项详情”副标题节点，并使用安全设置函数；未选产品时会正常展示“先选择一个产品”的工作台引导。
- 兜底：初始化增加可见的“工作台暂时无法加载 / 重新加载”提示。后续即使某段前端异常，也不会再呈现无信息的白屏，且不会影响或丢失已保存的产品配置数据。
- 回归与发布：功能提交 `6ebe8f135db303374525f2b4536439850b8d4abb` 已推送 GitHub 并以同一 Git bundle 快进正式服务器。服务器 `adaptation/index.php` 语法、产品适配抽屉候选、版面主次和工作台总览合同均通过；本记录同步后以三方 HEAD 核对为准。

## 2026-07-29：电源转正式读取统一采购价权限

- 现象与数据核查：梁诗尉（账号 `sweet`，用户 ID `11`）在统一权限中心已经保存了 `material_center.purchase_price.edit`（编辑采购价）和 `material_center.material.formalize`（物料转正式）两项直接授权；不是授权遗漏。
- 根因：电源编辑器在保存或转正式时，只读取已废弃的 `mc_permission_grants` 中 `material_center.field.sensitive`，没有读取统一权限中心的采购价编辑权限。因此，只要电源资料携带采购价，就会被错误拦截为“没有修改采购价的权限”。
- 修复：采购价判定现在先使用统一权限中心的 `material_center.purchase_price.edit`；旧敏感字段授权仅保留为兼容旧数据的后备路径。这样不会扩大未授权人员的权限，也不会影响其他物料或采购价规则。
- 回归与发布：功能提交 `c5e8adafd57133a0cdb7803561aa5dac95d0674f` 已推送 GitHub 并以校验过 SHA-256 的 Git bundle 快进正式服务器。服务器 PHP 语法与电源编辑授权合同通过；再以梁诗尉真实账号上下文验证：采购价编辑、转正式和电源编辑器采购价判定均为 `true`。本记录同步后以三方 HEAD 核对为准。

## 2026-07-29：订单中心出货批次可修改与删除

- 功能：订单中心每个仍为“草稿”的出货批次新增“修改批次”和“删除批次”。修改会完整回填批次号、日期、PL / CI 编号、运输资料、产品明细及拼箱资料；保存后重新计算订单已出货数量、未出货数量和收款摘要。
- 安全边界：只有尚未生成 PL / CI 且状态仍为草稿的批次可改或删除。已生成单证或已生效的批次会在服务端拒绝操作，避免误删已对外使用的出货记录。删除必须经过二次确认，并同时删除该草稿的产品明细与拼箱明细。
- 数量保护：修改时系统排除“当前批次”后重算其他批次已出数量；每个产品的本次出货数量不得超过订单尚可出货数量，不能通过前端绕过。
- 回归与发布：功能提交 `a586bad9797c8113c1fc712e1b0faf8f83c2b106` 已推送 GitHub 并以 Git bundle 快进正式服务器。服务器 `quote_order_api.php`、`quotation.php` 语法通过，出货编辑/删除合同的 9 项检查全部通过；本记录同步后以三方 HEAD 核对为准。

## 2026-07-29：产品适配工作台流程重构（V3）

- 目标：按最新规格把旧的“三栏卡片 + 右侧抽屉”适配页改为独立流程：适配首页、全部产品配置管理、单产品步骤化工作台、全宽候选物料比较。旧的产品、配置组、物料选项、条件、冲突、审批、发布版本和可复用模板数据均继续使用原表和原接口，不迁移或清空历史数据。
- 页面：入口首页只保留选产品、模板与批量操作；产品管理改为支持型号/名称/系列/类别模糊搜索及状态筛选的表格；选中产品后进入“技术范围 → 核心必配 → 扩展可配 → 条件规则 → 检查审批 → 版本发布”六步工作台。候选物料不再压缩进右侧抽屉，而是全宽显示规格、适配结果、原因与加入/例外操作。
- 技术范围：新增产品级 `mc_adaptation_product_profiles`，保存功率上下限、电流/电压范围、安装方式、尺寸、防护、认证、质保、LES/光学尺寸、光束角、调光和工程备注；保存时同步已有电源规则，且芯片/光学/电源候选判断会合并该产品级范围。
- 完成度：按技术范围 20%、核心必配 50%、扩展可配 10%、条件规则 10%、检查 10%计算。可选组可标记“允许选择、不适用、暂不提供、稍后处理”，其中仅“稍后处理”计入待处理项；核心组不能被误标为不适用。
- 安全：非正式物料不可直接加入；不适配正式物料必须填写工程例外原因并进入审批；单选组加入首个物料时自动设为默认项。所有关键写入继续通过原有服务端权限、正式物料和冲突校验。
- 发布与备份：先通过站点自身已授权的数据库连接，将适配相关表导出到服务器 `storage/backups/adaptation-pre-v3-20260729-1945.json`（3,979 bytes）；服务器 GitHub 部署密钥失效，因此使用已推送 GitHub 的同一 Git bundle 快进发布，不直接改服务器源码。
- 回归：迁移 `20260729_022_adaptation_product_profiles` 已成功执行；服务器 `adaptation/index.php`、`api/v1/adaptation.php`、`AdaptationService.php` 和迁移文件语法均通过。修复了无技术范围历史产品会在完成度计算时触发 PHP 致命错误的问题；实测服务正常返回 245 个产品、首个产品 10 个配置组和 20 个技术范围字段。正式浏览器当前没有登录态，只能到登录页，待有登录会话后可继续按页面检查清单复核。提交 `ac5e596815b2d1a28fc6939435b172f928ff4dbf`、`fb2110c8eb77265015fefa51372ff2b2128e5324`、`a5eeb37d344fcd841fe178daf4cde467a711d8d3` 已推送 GitHub 并快进服务器；三方 HEAD 已核对为 `a5eeb37d344fcd841fe178daf4cde467a711d8d3`。

## 2026-07-29：佣金汇总独立页面

- 调整：报价中心新增独立的“佣金汇总”页面；原“报价 / 订单佣金”页面只保留佣金规则和填写，不再把客户汇总卡片塞在编辑表格上方。
- 查询：独立页面可按关键词、客户、客户代码、负责人、币种、结算状态和订单日期范围筛选；分别展示币种总计、客户 / 客户代码汇总和订单明细。
- 数据：汇总直接读取订单、收款、佣金抵扣及已保存的订单 / 产品佣金记录；页面只读，不会改动任何佣金设置。新增接口 `commission_summary_list`，供独立页面使用。
- 检查：本地 JavaScript 语法与差异检查通过；正式服务器已完成 `quotation.php`、`quote_order_api.php`、`quote_api.php` 语法检查，以及独立页面合同检查，均通过。发布版本：`1e9b10f`。

## 2026-07-30：佣金汇总表格化与零佣金隐藏

- 调整：将佣金汇总的币种汇总改为表格，并保留客户 / 客户代码汇总和订单佣金明细表，三张表均按币种分开统计，不跨币种相加。
- 筛选：默认仅查询已启用佣金且预计佣金或已结佣金大于 0 的订单；没有佣金的订单不再进入佣金汇总，也不会占用客户汇总的金额。
- 保存规则：报价 / 订单佣金编辑不是自动保存；修改会保留在当前页面，必须点击“批量保存”后才写入并参与佣金汇总。刷新或离开未保存的编辑不会进入汇总。

## 2026-07-30：报价产品佣金“转订单后货款扣佣”保存修复

- 修复：按产品填写佣金时，“货款扣佣”以前被错误留在产品行草稿；真正保存佣金设置的报价主单没有同步该值，刷新后看起来像没有保存。现在产品行选择该项会写入对应报价主单，并显示为“转订单后货款扣佣”。
- 统计保护：产品行佣金保存后会按已启用项目重新汇总，不会再被报价主单默认的零佣金覆盖；报价转订单时会把该产品佣金总额和扣款方式一并带入订单。
- 业务边界：报价阶段即可保存；但真正从货款抵扣仍只会在订单已生成、且登记收款时执行。这样报价金额不会被提前扣减，也不会遗漏正式收款时的抵扣规则。

## 2026-07-31：产品适配停止开发并恢复上一可用版本

- 执行边界：按用户要求立即停止继续开发产品适配模块；本轮只做“备份当前错误版本 + 寻找最后可用版本 + 恢复 + 检查”，不重构业务、不改数据库、不删除任何 `mc_*` 业务数据。
- 错误版本备份：服务器当前错误适配目录已备份到 `/www/wwwroot/Artdon/artdon_erp/_codex_backups/adaptation_broken_backup_20260731_074053`，用于后续比较。
- 恢复点：选择 `a5eeb37d344fcd841fe178daf4cde467a711d8d3`，这是“基础页面修复中”占位页出现前最后一个仍包含最近产品、状态统计、全部产品、产品选择和单产品工作台的可用版本。
- 恢复内容：恢复 `material_center_v1/adaptation/index.php`、`material_center_v1/assets/js/adaptation-v3.js`、`material_center_v1/assets/css/app.css`、`material_center_v1/api/v1/adaptation.php`、`material_center_v1/app/Services/AdaptationService.php`；删除错误版本新增的 `material_center_v1/adaptation/docs/ADAPTATION_REPAIR_LOG.md`，并新增回滚报告 `material_center_v1/docs/ADAPTATION_ROLLBACK_REPORT.md` 和防占位回归测试 `material_center_v1/tests/adaptation_rollback_contract.php`。
- 检查结果：服务器 PHP 语法通过；`php material_center_v1/tests/adaptation_rollback_contract.php` 通过；占位词 `基础页面修复中/repairMode/mc-page--adaptation-baseline/renderPausedStep` 在运行文件中无命中；入口、`view=products`、`view=workspace&product_id=83`、旧入口 `product_id=83` 通过 CLI 渲染，均输出 V3 页面且无 Fatal；CSS/JS 静态资源 HTTP 200。
- 数据安全：只读查询确认 `mc_adaptation_groups=118`、`mc_adaptation_options=6` 等记录仍在；本轮未执行数据库写入、清空、迁移或结构变更。
- 发布：恢复提交 `0b334d7`、测试/CSS 修正 `f3fdb17`、测试标记修正 `f6e065d` 已推送 GitHub 并用 Git bundle 快进腾讯云服务器。功能恢复验证时本地、GitHub main、腾讯云服务器均为 `f6e065d3bbba61d697ca3d3ff5a8d6bee0346c0c`；本上下文记录会形成后续文档同步提交，文档同步后以当前 Git HEAD 为准。本轮恢复完成后停止，等待下一步指令。

## 2026-07-31：物料中心统一权限联动修复

- 根因：统一权限中心已经登记 `material_center.material.formalize`（物料转正式），但物料中心运行代码仍有多处使用旧的 `material_center.approve`、`material_center.power.confirm` 或笼统的 `material_center.material.lifecycle`。现有账号 `sweet` 只读检查显示 `formalize=1`、`lifecycle=0`，旧通用生命周期接口会因此拒绝转正式。
- 修复：在 `PermissionService` 统一新增 `allowsAny`、`requireAny`、`canFormalize`、`materialTransitionPermissions`、`requireMaterialTransition`；转正式优先认 `material_center.material.formalize`，并兼容旧 `approve`、`power.confirm`、`lifecycle`。停用、归档、删除草稿等也映射到对应细分权限，并兼容旧 lifecycle。
- 接入范围：`api/v1/material-master.php`、`api/v1/materials.php`、`api/v1/source-material.php`、`api/v1/category-fields.php`、`SourceMaterialOrganizerService`、`PowerEditorService`、`assets/js/power-editor.js`。电源待确认记录转正式现在直接调用生命周期，不再先强制保存而被 edit 权限误挡。
- 审计：服务器 `crm_permissions` 中共有 31 个 `material_center.*` 权限项；旧 `mc_permission_grants` 未发现物料中心残留授权；权限中心系统页仍通过 `material_center.` 前缀绑定物料中心。
- 测试：服务器 PHP 语法通过；本地 `node --check material_center_v1/assets/js/power-editor.js` 通过；服务器 `material_permission_contract.php`、`unified_permission_contract_test.php`、`source_material_organizer_contract.php`、`power_editor_contract_test.php` 均通过。报告写入 `material_center_v1/docs/MATERIAL_PERMISSION_AUDIT_REPORT.md`。

## 2026-07-31：产品适配主页与产品配置工作台视觉加强

- 范围：按用户提供的两张参考图，加强产品适配主页和单产品配置工作台；本轮只改前端渲染与样式，不改数据库结构、不迁移、不清空任何 `mc_*` 适配数据。
- 主页：`adaptation-v3.js` 重新组织为“全部产品配置”业务页，包含高级筛选、状态 tab、最近产品、产品表格、完成度圆环、配置/发布/冲突/待审批列、排序与当前筛选导出 CSV。保留“最近产品”“打开工作台”等恢复验收锚点，避免回到空白占位页。
- 工作台：单产品页改为参考图的产品 hero、五步流程（选择产品、核心必配、扩展可配、条件规则、检查发布）、核心/扩展模组卡和右侧配置抽屉。核心模组按芯片/光源、电源/驱动、光学/透镜、安装方式等识别；候选物料仍通过原 `candidates`、`add_options`、`set_default`、`approve` 等接口，沿用正式物料和审批规则。
- 回归保护：新增 `material_center_v1/tests/adaptation_visual_upgrade_contract.php`，检查新版主页/工作台关键结构，并阻止“基础页面修复中”“暂未开放”“repairMode”等占位逻辑回归。既有 `adaptation_rollback_contract.php` 同时保持通过。
- 发布与验证：提交 `6bbd9cc7b1f3fab159ef4da913f8cb20c90a2ec7` 已推送 GitHub，并用 Git bundle 快进腾讯云服务器。服务器 PHP 语法通过；回滚合同和视觉加强合同均通过；CLI 实际渲染 `index.php`、`?view=products`、`?view=workspace&product_id=83`、旧兼容 `?product_id=83` 均 OK，输出新版 V3 页面且无占位文字。

## 2026-07-31：佣金“报价 / 订单佣金”加载解耦提速

- 现象：佣金策略 → 报价 / 订单佣金进入后长时间停在“正在读取订单…”，用户现场约 10 秒才显示订单信息。
- 定位：服务器只读计时显示 `commission_order_list` 相关订单 SQL 约 `0.89ms`，当前订单 14 个、产品明细 39 行，数据库不是慢点。前端 `loadCommissionOrders()` 旧逻辑把订单列表与 `commission_options_list` 放在同一个 `Promise.all` 中，导致下拉选项/初始化接口慢时，订单数据也被一起阻塞。
- 修复：新增佣金下拉兜底选项，进入订单佣金页时先独立读取并渲染订单列表；`commission_options_list` 改为后台异步刷新下拉选项，不再阻塞订单信息。页面状态文字新增实际用时，方便现场判断接口耗时。
- 回归保护：新增 `tests/commission_order_loading_contract.php`，禁止 `loadCommissionOrders()` 再直接等待 `commission_options_list` 或使用 `Promise.all` 绑定选项与订单列表。
- 发布与验证：提交 `b9a3ad4a1eaaa82e2dda1e4703570840c2bc9561` 已推送 GitHub 并快进腾讯云服务器。服务器 `quotation.php` 语法通过，`commission_order_loading_contract.php`、`commission_order_path_contract.php`、`commission_summary_page_contract.php` 均通过。

## 2026-07-31：产品适配单产品工作台一屏化与宽版选择弹窗

- 范围：按用户最新截图与文字要求，继续调整物料中心“单产品配置工作台”；保留适配首页和全部产品页，不改数据库、不删除 `mc_*` 数据、不回滚旧 BOM、不继续开发核心规则/审批/发布新业务。
- 备份：动手前已在服务器备份当前适配目录到 `/www/wwwroot/Artdon/artdon_erp/material_center_v1/adaptation_one_screen_backup_20260731_170824`；并导出 89 张 `mc_*` 表到 `/www/wwwroot/Artdon/artdon_erp/material_center_v1/backups/mc_tables_one_screen_20260731_170824.sql`（约 6.2MB）。
- 页面：单产品工作台默认压缩为一屏主操作：顶部产品摘要、三步快速流程、配置来源、四个核心配置、最多四项缺失参数、检查摘要和底部保存/检查/提交动作；正常配置不再靠整页向下滚动完成。
- 物料选择：点击芯片/光源、电源/驱动、光学/透镜、安装方式等核心项后，使用居中的宽版弹窗展示候选物料；弹窗内部有固定标题、产品要求、筛选栏、独立滚动候选区和底部确认区。选择物料后关闭弹窗并回到主工作台。
- 缺失参数：缺失技术字段只显示前 4 项，点击“填写”打开小弹窗补单个参数；更多字段进入高级设置。高级设置改为覆盖式面板，内部滚动，技术范围、扩展可选、条件规则、例外审批、版本/历史能力仍保留但不默认铺在主页面。
- 修改文件：`material_center_v1/assets/js/adaptation-v3.js`、`material_center_v1/assets/css/app.css`、`material_center_v1/tests/adaptation_quick_workspace_contract.php`。
- 检查：本地 `node --check material_center_v1/assets/js/adaptation-v3.js` 与 `git diff --check` 通过；服务器 `php -l material_center_v1/adaptation/index.php`、`php -l material_center_v1/tests/adaptation_quick_workspace_contract.php` 通过；服务器 `adaptation_quick_workspace_contract.php`、`adaptation_rollback_contract.php`、`adaptation_visual_upgrade_contract.php` 全部通过；服务器 CLI 渲染 `?view=workspace&product_id=82` 输出 `adaptation-bootstrap`、`adaptation-v3.js` 和 `app.css`，无 Fatal Error。
- 发布：功能提交 `2c5828b2fb0286edc6eac577fe43a701d22cdff9`，恢复合同锚点补丁 `cbe9e5baea31a0b086e1958c4e9f766ec75a952e`、`78fd835f1fe5f5efbdd9ab0baa8ffca6f9331b1c`、`d819afe5e594c267a2a4a4c06474b0765df2efcf` 已推送 GitHub main，并用 Git bundle 快进腾讯云服务器。文档同步后以最终三方 HEAD 为准。

## 2026-07-31：产品适配快速工作台层级压缩与候选物料紧凑表格

- 范围：继续优化 `/material_center_v1/adaptation/index.php?view=workspace&product_id=100` 的快速配置工作台和正式物料选择弹窗；本轮只改前端层级、组件尺寸和选择交互，不改左侧菜单、ERP 顶部导航、旧 BOM、数据库结构或现有 `mc_*` 业务数据。
- 备份：修改前服务器目录已备份到 `/www/wwwroot/Artdon/artdon_erp/material_center_v1/adaptation_compact_workspace_backup_20260731_173620`；89 张 `mc_*` 表已导出到 `/www/wwwroot/Artdon/artdon_erp/material_center_v1/backups/mc_tables_compact_workspace_20260731_173657.sql`（6,378,417 bytes）。
- 主页面：顶部常驻按钮只保留“切换产品 / 高级设置 / 更多”；已确认配置来源折叠为 48–56px 摘要栏，包含来源、相似度、已确认状态、查看差异和更换来源；更换来源改为弹窗，集中放复制同系列、套用模板、读取 BOM、从空白开始。
- 核心配置：四个核心项保持两列两行，卡片高度压缩到约 90–110px，只显示图标、配置组名称、当前默认物料、3–4 项关键规格、匹配状态、正式选项数量和一个主按钮；按钮文案按状态区分采用建议、选择物料、更换、设置默认、查看条件或处理冲突。
- 底部与补充：需要补充区保持一行最多 4 项，点击填写打开小弹窗；检查摘要合并到底部固定操作栏，左侧最多显示 3 条检查摘要并提供“查看完整检查”，右侧固定保存草稿、检查配置、提交确认。
- 候选弹窗：芯片/光源、电源/驱动、光学/透镜和安装方式统一改为紧凑表格框架；弹窗固定标题栏、筛选栏、独立滚动候选列表和底部操作栏。候选行高度约 64–76px，小图使用 40–48px 占位；点击整行单选/多选，底部统一“确认选择”，不再在每行放大面积“选为默认”按钮。
- 不适配与详情：不适配行显示浅红背景、禁用选择框，并保留小文字“申请例外”；例外改为小弹窗填写原因和审批/备注。详情使用右侧 320px 面板，不放大候选行。
- 颜色：产品适配页内普通主操作统一青绿色，次操作白底灰边，警告操作橙色；红色仅用于不适配、危险和例外相关操作。
- 检查：本地 `node --check material_center_v1/assets/js/adaptation-v3.js` 与 `git diff --check` 通过；服务器 `php -l material_center_v1/adaptation/index.php`、`php -l material_center_v1/tests/adaptation_quick_workspace_contract.php` 通过；服务器 `adaptation_quick_workspace_contract.php`、`adaptation_rollback_contract.php`、`adaptation_visual_upgrade_contract.php` 全部通过；服务器 CLI 渲染 `?view=workspace&product_id=100` 输出 `adaptation-bootstrap`、`adaptation-v3.js` 和 `app.css`，无 Fatal Error；未登录 HTTP 访问返回 302 登录跳转属正常鉴权。
- 发布：功能提交 `c68cbf0d9eca7f06339f4d654f87da97e9bbbb0d` 已推送 GitHub main，并用 Git bundle 快进腾讯云服务器。文档同步后以最终三方 HEAD 为准。

## 2026-07-31：产品适配“需要补充”遮挡修复

- 范围：修复 `/material_center_v1/adaptation/index.php?view=workspace&product_id=67` 单产品配置工作台中“需要补充 N 项”被底部“配置检查 / 提交审批”栏遮挡的问题；本轮只改前端布局和参数补充交互，不改数据库结构、不修改旧 BOM、不影响其他物料中心页面。
- 备份：修改前服务器当前适配目录已备份到 `/www/wwwroot/Artdon/artdon_erp/material_center_v1/adaptation_missing_footer_backup_20260731_175410`。
- 布局：主工作区改为产品摘要、三步流程、配置来源摘要、四个核心配置、底部操作栏五行 grid；底部栏作为真实布局最后一行，不再 fixed 覆盖主内容，也不通过增加 `margin-bottom` 临时规避。
- 需要补充：主页面不再长期展开 4 张补充字段卡片；底部栏左侧显示“需要补充 N 项 + 立即补充”，无缺失时显示“必要技术参数已完整”。检查摘要合并在同一底部区域，显示通过、待确认和阻断数量。
- 弹窗：点击“立即补充”打开“补充必要技术参数”集中弹窗，宽度 720–860px、最大高度 70vh、两列字段布局、头部和底部固定、字段区内部滚动；支持一次填写多个字段，并提供“保存草稿”和“保存并重新检查”。
- 保存：保存参数继续调用现有 `save_technical_profile` 接口；“保存并重新检查”关闭弹窗后通过 AJAX 原位重新读取工作台数据，更新缺失数量、完成度和检查摘要，不做整页刷新，不改变页面位置。
- 检查：本地 `node --check material_center_v1/assets/js/adaptation-v3.js` 与 `git diff --check` 通过；服务器 `php -l material_center_v1/adaptation/index.php`、`php -l material_center_v1/tests/adaptation_quick_workspace_contract.php` 通过；服务器 `adaptation_quick_workspace_contract.php`、`adaptation_rollback_contract.php`、`adaptation_visual_upgrade_contract.php` 全部通过；服务器 CLI 渲染 `?view=workspace&product_id=67` 输出 `adaptation-bootstrap`、`adaptation-v3.js` 和 `app.css`，无 Fatal Error。
- 发布：功能提交 `c60e8f4edcc9302a5e42f6254cf68cb29896cdbc` 已推送 GitHub main，并用 Git bundle 快进腾讯云服务器。文档同步后以最终三方 HEAD 为准。

## 2026-07-31：产品适配配置模板页视觉重排

- 范围：按用户截图重排 `/material_center_v1/adaptation/index.php?view=template&product_id=67` 配置模板页；本轮只改产品适配前端模板页和契约测试，不改数据库结构、不写入或删除 `mc_*` 业务数据、不影响其他物料中心页面。
- 备份：修改前服务器当前适配目录已备份到 `/www/wwwroot/Artdon/artdon_erp/material_center_v1/adaptation_template_page_backup_20260731_180958`。
- 页面：配置模板页改为截图式双栏布局：顶部面包屑和标题说明、当前产品卡片（图片/产品编码/名称/更换产品）、左侧“通用模板 / 按产品分类 / 自定义分类模板”标签与模块卡片、右侧“自定义分类 / 分类规则”面板，以及底部选中统计和保存/套用按钮。
- 模板：左侧显示 10 个标准模块卡片（芯片/光源、电源/驱动、光学/透镜、安装方式、调光方式、蜂巢网、玻璃、附件配件、外观颜色、特殊要求）；核心必配默认勾选，保留真实 `template_key` checkbox 和现有 `apply_template` 提交，不破坏原模板套用逻辑。
- 分类规则：右侧先建立分类规则界面骨架，包含分类名称、适用产品类型、单选/多选、新增分类、已创建分类卡和选项 chip；当前仅作为页面管理入口提示，正式保存分类规则需后续接入独立规则接口。
- 交互：新增全选、重置、选择数量实时更新；“保存草稿”只给页面提示，不写数据库；“套用配置模板”继续调用现有产品适配模板接口。
- 回归保护：新增 `material_center_v1/tests/adaptation_template_page_contract.php`，锁定配置模板页的产品卡、三类标签、模块卡片、右侧规则面板、底部操作栏和真实模板提交字段。
- 检查：本地 `node --check material_center_v1/assets/js/adaptation-v3.js` 与 `git diff --check` 通过；本地无 PHP，已用服务器 `php -l` 检查新增测试文件语法。服务器 `php -l material_center_v1/adaptation/index.php`、`php -l material_center_v1/tests/adaptation_template_page_contract.php` 通过；服务器 `adaptation_template_page_contract.php`、`adaptation_quick_workspace_contract.php`、`adaptation_rollback_contract.php`、`adaptation_visual_upgrade_contract.php` 全部通过；服务器 CLI 渲染 `?view=template&product_id=67` 输出 `配置模板`、`adaptation-bootstrap` 和 `adaptation-v3.js`，无 Fatal Error。
- 发布：功能提交 `5e1b9d98e742214770eb68fcae5e274394911b69` 已推送 GitHub main，并用 Git bundle 快进腾讯云服务器。文档同步后以最终三方 HEAD 为准。

## 2026-08-08：CRM 客户列表表头升降序与群名列

- 需求：客户中心列表需要更直接的升序/降序操作，并在列表中显示客户关联的微信群 / WhatsApp 群名。
- 修复：完整表格列新增 `群名`，接口从 `crm_customer_chat_groups` 汇总未删除客户群，按 `微信群：群名；WhatsApp群：群名` 格式返回 `chat_group_names`；排序下拉新增等级、来源、负责人、联系人、群名、状态等字段。
- 追加：完整表格列新增 `最后推广`，从推广日志、推广任务目标执行时间和客户群 `last_promoted_at` 里取最近一次时间，支持下拉和表头升/降序排序。
- 追加：客户列表 `邮件 / 报价` 两列不再显示固定“无记录”，改为接口汇总最近邮件时间 `latest_mail_at` 和最近报价时间 `latest_quote_at`；同时支持表头和排序下拉升/降序。
- 交互：表头可排序列增加升降序提示，点击表头切换升序/降序并刷新当前列表；选择框和操作列不参与排序。紧凑默认布局仍保留代码/客户/国家，避免默认列表宽度被群名列撑大。
- 回归保护：新增 `tests/crm_customer_list_sort_chat_group_contract.php`，锁定群名列、后端群名汇总、表头排序事件、排序图标和后端排序字段。

## 2026-08-08：CRM 客户中心恢复全屏布局按钮

- 问题：客户中心列表/属性/还原的全屏逻辑、CSS 和事件绑定仍存在，但按钮入口在后续客户中心重构中从默认 ACTIONS 移除，只残留在“已删除客户”分支；页面上也没有生成 `data-customer-layout` 按钮，导致用户看不到入口。
- 修复：在客户中心筛选区新增常驻布局工具条 `列表全屏 / 属性全屏 / 还原`，使用原有 `data-customer-layout` 事件和 `applyLayoutMode()` 逻辑；工具条放在 split 外层，进入属性全屏后仍可点击还原。
- 调整：按用户反馈，布局按钮改为嵌入第一行搜索工具栏 `导入日志` 后方，占用原本空白区域，不再单独占一整行筛选区高度。
- 细节：初始化时调用 `applyLayoutMode(this.layoutMode, false)`，让上次保存的布局状态刷新后也能恢复，并同步按钮高亮。
- 回归保护：新增 `tests/crm_customer_layout_tools_contract.php`，锁定常驻按钮、事件绑定、布局类和初始化恢复逻辑。

## 2026-08-08：CRM 客户中心国家列中文显示

- 问题：客户中心列表“国家”列直接显示客户资料里的原始国家值，导致 `AE`、`QA`、`OM`、`IN` 等 ISO 编码原样露出；同一列里中英文/代码混排，识别成本高。
- 修复：客户列表接口在保留原始值 `country_raw` 的同时新增 `country_display`，优先按 `country_region` 国家字典返回中文名称，并用既有国家别名兜底；前端国家列优先显示中文名，旗帜继续按原始国家代码识别，鼠标标题保留“中文 / 原值”方便追溯。
- 范围：只改客户中心列表显示和接口返回字段，不修改客户资料数据库原值，不影响搜索筛选、国家统计或客户编辑保存。
- 回归保护：新增 `tests/crm_customer_country_display_contract.php`，锁定后端中文显示字段、字典/别名兜底以及前端优先显示中文字段。

## 2026-07-31：产品适配配置模板页逻辑打通

- 问题：配置模板页上一版主要是视觉界面，右侧“自定义分类 / 分类规则”、Tab、保存草稿等动作没有真正串到产品适配业务；服务端直开 `?view=template` 也未作为合法初始视图处理。
- 备份：部署前服务器相关文件已备份到 `/www/wwwroot/Artdon/artdon_erp/material_center_v1/adaptation_template_logic_backup_20260731_183153`，包含入口页、API、`AdaptationService`、前端 JS/CSS 和模板页契约测试。
- 后端：`adaptation.php` 新增 `save_template_category`、`delete_template_category` 动作；`AdaptationService` 新增 `saveTemplateCategory()`、`deleteTemplateCategory()`。自定义分类保存为当前产品的 `mc_adaptation_groups` 自定义配置组，`rule_json` 保存 `template_custom_category`、适用产品类型、字段名称、选项值和单/多选设置；不新增表、不改数据库结构。
- 前端：配置模板页 Tab 可切换；“按产品分类”按当前产品类型预选扩展模块；右侧建议分类可“加入”到表单，已保存分类可编辑、删除、新增选项、移除选项；“保存草稿”会保存当前标准模块和自定义分类并停留模板页；“套用配置模板”保存后进入产品工作台。
- 业务边界：自定义分类只建立配置组和分类规则说明，不凭空生成正式物料选项；后续仍需在工作台从正式物料库添加候选物料。删除分类走现有配置组删除规则，已审批、已启用或被报价/订单引用的配置组不会被删除。
- 入口：`adaptation/index.php` 允许服务端直接打开 `view=template` 和 `view=batch`，刷新页面不会再退回工作台或首页。
- 检查：本地 `node --check material_center_v1/assets/js/adaptation-v3.js`、`git diff --check` 通过；服务器 `php -l` 检查 `index.php`、`api/v1/adaptation.php`、`AdaptationService.php`、`adaptation_template_page_contract.php` 均通过；服务器 `adaptation_template_page_contract.php`、`adaptation_quick_workspace_contract.php`、`adaptation_rollback_contract.php`、`adaptation_visual_upgrade_contract.php` 全部通过；服务器 CLI 渲染 `?view=template&product_id=67` 输出正常；只做无写入检查，未向正式库插入测试分类。
- 发布：功能提交 `b26b2a840c3792de99405c3e6df5855f6b5e4fc2` 已推送 GitHub main，并用 Git bundle 快进腾讯云服务器。文档同步后以最终三方 HEAD 为准。

## 2026-07-31：报价系统颜色支持空白

- 问题：报价单工作区“颜色”下拉没有空白选项，配件或特殊产品没有颜色时会被迫选择一个颜色；旧逻辑还在多处默认回填 `White`，导致空色产品保存/打开后可能被自动带上颜色。
- 修复：`colorItems()` 统一在颜色下拉首位加入真实空白选项；`fillOptionSelect()` 改为保留显式 `value=""`，不再把空值误当成没有值。新报价、清空当前产品、选择产品、产品库清空默认均改为空白；打开历史报价明细时，如果明细已有 `color:''`，不会再被产品颜色或 `White` 覆盖。
- 范围：仅修改报价系统 `quotation.php` 的颜色下拉与默认值逻辑，不修改数据库、不删除业务数据、不影响物料中心。
- 回归保护：新增 `tests/quote_color_blank_contract.php`，锁定空白颜色选项、显式空值渲染、历史明细空色不被覆盖，以及禁止旧的强制 `White` 默认回归。
- 检查：本地 `git diff --check` 通过；本地无 PHP，已用服务器 `php -l` 通过检查 `quotation.php` 和新增测试文件；临时复制到服务器 `/tmp` 执行 `quote_color_blank_contract.php` 通过。

## 2026-07-31：产品适配“配置模板中心”动态化重建

- 范围：按用户最新文字要求，重新规划并开发 `/material_center_v1/adaptation/index.php?product_id=100&view=template`，将配置模板页从固定模块视觉页升级为“产品分类配置模板中心”。本轮涉及物料中心产品适配模板页、适配 API、`AdaptationService`、迁移和契约测试；不回滚旧 BOM、不删除现有 `mc_*` 业务数据、不影响其他物料分类页面。
- 备份：动手前服务器适配目录已备份到 `/www/wwwroot/Artdon/artdon_erp/material_center_v1/adaptation_template_rebuild_backup_20260731_192910`；89 张 `mc_*` 表已导出到 `/www/wwwroot/Artdon/artdon_erp/material_center_v1/backups/mc_tables_template_rebuild_20260731_192910.sql`（约 6.1MB）。
- 数据结构：新增迁移 `20260731_023_config_template_center.php`，创建 `mc_config_templates`、`mc_config_group_definitions`、`mc_config_template_groups`、`mc_config_group_options`、`mc_config_group_conditions`、`mc_config_group_material_filters`、`mc_config_template_versions`、`mc_config_template_logs`；写入 `config_template.*` 统一权限并预置导轨灯、嵌入式灯具、磁吸式灯具、系统通用模板示例。
- 后端：`AdaptationService` 新增模板中心读取、模板 CRUD、复制/停用/删除、配置组定义、模板配置组保存、属性组选项、显示条件、套用预览和套用到产品；套用默认 `fill_missing`，只补缺失配置组，保留已有选择，不直接覆盖已发布版本。
- 前端：`view=template` 改为渲染新的产品分类配置模板中心，包含当前产品/模板来源卡片、动态模板库卡片、左侧配置组列表、右侧配置组详情、底部保存/预览/套用操作栏和套用影响预览弹窗。页面数据来自 API/DB，不再用 PHP/JS 固定十个模块作为业务结构。
- 工作台联动：单产品快速工作台的核心配置名称改为来自动态配置组；“从空白开始”和“保存草稿”不再硬套 `light_source/power_driver/optical/installation`，改为读取当前产品匹配模板并走 `apply_config_template_to_product`。
- 回归保护：更新 `adaptation_template_page_contract.php`，锁定动态模板中心、DB/API/权限/日志、套用预览和非固定模板入口；更新 `adaptation_quick_workspace_contract.php`，防止快速工作台回退到固定四核心键或固定 template_keys。
- 检查：本地 `node --check material_center_v1/assets/js/adaptation-v3.js`、`git diff --check` 通过；服务器 `php -l` 检查 `AdaptationService.php`、`api/v1/adaptation.php`、新增迁移、模板契约测试和快速工作台契约测试均通过；临时同步到服务器 `/tmp/artdon_template_contract` 后执行 `adaptation_template_page_contract.php`、`master_spec_contract_test.php`、`adaptation_quick_workspace_contract.php` 全部通过。

## 2026-07-31：报价 PDF 产品字段中文兜底过滤

- 问题：报价预览正常，但导出 PDF 时产品明细出现中文。以 `AT-260731EX133` 为例，审核快照里的 `product.size` 保存为 `JB-M-AR 嵌入式低压导轨，L1000*W70*H52.2mm，黑色，不含尾盖`；PDF 导出使用审核快照重新拼 `Size or Drawing` 和 `Specification`，因此把中文 size 带进 PDF。网页预览使用当前前端实时英文规格，所以两边不一致。
- 修复：`crm_quote_pdf.php` 新增产品明细导出专用 CJK 过滤。`quote_display_size()` 遇到中文来源尺寸直接留空；`build_spec()` 和已保存 `specification` 清洗时跳过含中文的产品规格行，并在保存规格全是中文时继续尝试按结构化字段重建英文规格，不再把原始中文兜底吐回 PDF。
- 范围：只过滤 PDF 产品明细的 Size / Specification 相关产品字段；不全局删除中文，避免误伤公司资料、抬头、银行信息或系统提示。
- 回归保护：新增 `tests/quote_pdf_product_english_contract.php`，模拟含中文 `product.size` 和含中文 `specification` 的导出 payload，确认 PDF HTML 保留英文产品规格、不输出中文产品 size/spec。
- 检查：本地 `git diff --check` 通过；服务器 `php -l` 检查 `crm_quote_pdf.php` 和新增测试通过；服务器临时目录执行 `quote_pdf_product_english_contract.php` 通过；用修改后的临时 PDF 文件读取真实 `AT-260731EX133` 审核快照验证，`has_product_cjk=0`、`has_english=1`。
