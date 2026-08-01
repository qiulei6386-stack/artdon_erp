# 产品适配 V2 执行日志

## 2026-07-31 第 1 阶段：冻结旧版、审计和 V2 蓝图落地

执行范围：

- 已完整阅读 `material_center_v1/docs/ARTDON_PRODUCT_ADAPTATION_V2_MASTER_IMPLEMENTATION_SPEC.md`。
- 只执行第 1 阶段，不进入第 2 阶段。
- 未修改旧版产品适配业务。
- 未修改旧 BOM。
- 未切换正式菜单。
- V2 使用独立目录 `material_center_v1/adaptation_v2/`。
- 后续 V2 新表统一使用 `mc_pa2_` 前缀。

完成内容：

- 备份服务器旧 `adaptation/` 目录。
- 备份旧适配相关 24 张 `mc_*` 表。
- 审计旧页面、接口、服务、迁移、测试和旧功能清单。
- 新增 V2 独立入口空页面。
- 新增 V2 统一 API 响应工具和状态接口。
- 新增 V2 迁移目录占位，不执行建表。
- 新增第 1 阶段审计、路由、数据库和执行日志文档。
- 新增第 1 阶段契约测试。

服务器备份：

```text
/www/wwwroot/Artdon/artdon_erp/material_center_v1/backups/adaptation_v2_phase1_20260731_223720/
```

备份校验：

- `adaptation_directory.tar.gz`：`98e5704abf4c68f638b0d77cda2209606e3cd156e55593d17934f010abdc8801`
- `old_adaptation_tables.sql`：`3f7b812caf311b1e2a0b2a7552cb02906d4171bec5986ff1bfa5b1605c4741c6`
- `database_audit.json`：`34ecf8712f425cb27f9b599a36eb667b9ffd6aae90378a7b880f9d4fe0d77701`
- `table_list.txt`：`d3b9b86f6a875d361b897eb365daf2cdd80d9e7946195d7a5f4e64f516ce6218`

本阶段新增或修改文件：

- `material_center_v1/docs/ARTDON_PRODUCT_ADAPTATION_V2_MASTER_IMPLEMENTATION_SPEC.md`
- `material_center_v1/adaptation_v2/index.php`
- `material_center_v1/adaptation_v2/api/index.php`
- `material_center_v1/adaptation_v2/lib/response.php`
- `material_center_v1/adaptation_v2/database/migrations/.gitkeep`
- `material_center_v1/adaptation_v2/docs/01_CURRENT_AUDIT.md`
- `material_center_v1/adaptation_v2/docs/01_ROUTE_MAP.md`
- `material_center_v1/adaptation_v2/docs/01_DATABASE_AUDIT.md`
- `material_center_v1/adaptation_v2/docs/EXECUTION_LOG.md`
- `material_center_v1/tests/adaptation_v2_phase1_contract.php`
- `WORK_CONTEXT.md`

数据库变化：

- 无新业务表写入。
- 无 `mc_pa2_*` 表创建。
- 无旧 `mc_*` 业务表修改。
- 无旧 BOM 修改。

测试记录：

- 本地 `git diff --check` 通过。
- 本机无 PHP，已将候选文件临时复制到服务器 `/tmp/artdon_pa2_phase1_candidate/`，只用于检查，不覆盖线上项目。
- 服务器 PHP 语法检查通过：`adaptation_v2/index.php`、`adaptation_v2/api/index.php`、`adaptation_v2/lib/response.php`、`tests/adaptation_v2_phase1_contract.php`。
- 第 1 阶段契约 `tests/adaptation_v2_phase1_contract.php` 9 项通过。
- 待具备发布条件后再在服务器同一提交执行 V2 首页 HTTP 200、状态 API 和契约测试。

阶段停止点：

第 1 阶段本地完成后停止，等待验收；不得自动进入第 2 阶段。

发布状态：

- 本地提交已创建。
- GitHub 推送失败：当前 SSH key 是 deploy key，没有 `qiulei6386-stack/artdon_erp.git` 写权限。
- 本机未安装并登录 GitHub CLI `gh`，无法按 GitHub 发布流程改用已认证账号推送。
- 用户已明确确认“直接推服务器，包含这两个提交”，本轮按用户授权临时绕过 GitHub，用本地 Git bundle 直接快进正式服务器；GitHub 仍待后续补同步。

## 2026-08-01 第 2 阶段：基础数据模型和产品分类中心

执行范围：

- 用户要求第 1 阶段完成后不用停，继续进入第 2 阶段直至完成。
- 继续遵守：不修改旧版产品适配业务、不修改旧 BOM、不切换正式菜单。
- V2 继续使用独立目录 `material_center_v1/adaptation_v2/`。
- V2 新表继续统一使用 `mc_pa2_` 前缀。

完成内容：

- 新增 V2 专用迁移工具和迁移账本，迁移文件保存在 `adaptation_v2/database/migrations/`。
- 新增基础表：`mc_pa2_product_categories`、`mc_pa2_product_category_mappings`、`mc_pa2_group_definitions`、`mc_pa2_group_option_definitions`。
- 新增首批产品分类种子：导轨灯、嵌入式、磁吸式、明装式、线性、灯带、户外、柜体灯、电源、配件。
- 新增首批配置组种子：芯片/光源、电源/驱动、外置电源、INTRACK 电源、光学/透镜、普通导轨接头、INTRACK 接头、磁吸头、灯体长度、型材、灯带、扩散罩、吊线、端盖、安装方式、外观颜色、调光方式、特殊要求。
- 新增属性选项种子：灯体长度、安装方式、外观颜色、调光方式等。
- 新增 `adaptation_v2.*` 统一权限项，并授予系统角色默认权限，不建立第二套账号。
- V2 首页升级为第 2 阶段基础状态；新增产品分类中心、配置组定义中心和产品分类映射页面。
- V2 API 新增 `categories`、`category_save`、`groups`、`group_save`、`group_option_save`、`products`、`product_map_save` 等动作。
- 分类、配置组、组选项和产品映射写入会记录到既有 `mc_operation_logs`，`module=adaptation_v2`。
- 新增第 2 阶段文档和契约测试。

本阶段新增或修改文件：

- `material_center_v1/adaptation_v2/index.php`
- `material_center_v1/adaptation_v2/api/index.php`
- `material_center_v1/adaptation_v2/lib/foundation.php`
- `material_center_v1/adaptation_v2/lib/migration_runner.php`
- `material_center_v1/adaptation_v2/tools/migrate.php`
- `material_center_v1/adaptation_v2/database/migrations/20260801_001_phase2_foundation.php`
- `material_center_v1/adaptation_v2/docs/02_FOUNDATION_MODEL.md`
- `material_center_v1/adaptation_v2/docs/EXECUTION_LOG.md`
- `material_center_v1/tests/adaptation_v2_phase2_contract.php`
- `WORK_CONTEXT.md`

数据库变化：

- 只新增 V2 旁路基础表和 V2 权限项。
- 未修改、删除或回写旧 `adaptation/` 业务表。
- 未修改旧 BOM。
- 未切换正式菜单。

测试记录：

- 待本地静态检查、服务器 PHP 语法检查、V2 迁移执行、阶段契约测试和 HTTP/API 验证后补充。

## 2026-08-01 第 3 阶段：模板中心和继承引擎

执行范围：

- 用户反馈第 2 阶段页面“还比较生硬”，要求继续。
- 按主说明进入第 3 阶段：模板中心和继承引擎。
- 继续不修改旧版产品适配业务、不修改旧 BOM、不切换正式菜单。

完成内容：

- 新增 V2 模板迁移 `20260801_002_phase3_templates.php`。
- 新增 `mc_pa2_templates`、`mc_pa2_template_versions`、`mc_pa2_template_groups`。
- 写入首批模板：系统通用模板、导轨灯模板、嵌入式模板、磁吸式模板。
- 写入首批模板配置组。
- 实现父模板继承、配置组覆盖、禁用父模板配置组、最终有效配置组预览。
- 实现模板新增/编辑、配置组加入/覆盖、发布模板版本、引用检查 API。
- `templates` 页面从占位改为模板中心；`template_editor` 改为三栏模板编辑器。
- 页面视觉从硬表格调整为卡片、工作台和柔和状态区。
- 新增第 3 阶段文档和契约测试。

本阶段新增或修改文件：

- `material_center_v1/adaptation_v2/database/migrations/20260801_002_phase3_templates.php`
- `material_center_v1/adaptation_v2/lib/foundation.php`
- `material_center_v1/adaptation_v2/api/index.php`
- `material_center_v1/adaptation_v2/index.php`
- `material_center_v1/adaptation_v2/docs/03_TEMPLATE_INHERITANCE.md`
- `material_center_v1/adaptation_v2/docs/EXECUTION_LOG.md`
- `material_center_v1/tests/adaptation_v2_phase3_contract.php`
- `WORK_CONTEXT.md`

数据库变化：

- 只新增 V2 模板相关 `mc_pa2_*` 表和种子。
- 未修改旧 `mc_adaptation_*`、旧 BOM 或正式菜单。

测试记录：

- 本地 `git diff --check` 通过。
- 旧版适配目录、旧适配 API、旧适配服务和旧迁移 diff 为 0 行。
- 候选文件已复制到服务器 `/tmp/artdon_pa2_phase3_candidate/` 检查，PHP 语法通过：`index.php`、`api/index.php`、`foundation.php`、第 3 阶段迁移和新增契约测试。
- 候选 `adaptation_v2_phase3_contract.php` 通过。
- 正式服务器已执行 `php material_center_v1/adaptation_v2/tools/migrate.php up`，应用 `20260801_002_phase3_templates`。
- 正式服务器 PHP 语法检查通过：`adaptation_v2/index.php`、`api/index.php`、`lib/foundation.php`、第 3 阶段迁移、阶段契约测试。
- 正式服务器 `adaptation_v2_phase3_contract.php` 通过。
- 正式服务器页面渲染通过：`home`、`templates`、`template_editor`、`logs`。
- 正式服务器继承预览核验：当前 4 个模板，`track_light_base` 继承链 2 层，继承后 9 个有效配置组。

## 2026-08-01 第 4 阶段：配置组选项、物料来源和规则编辑器

执行范围：

- 用户要求继续。
- 按主说明进入第 4 阶段：配置组选项、物料来源和规则编辑器。
- 继续不修改旧版产品适配业务、不修改旧 BOM、不切换正式菜单。

完成内容：

- 新增 V2 迁移 `20260801_003_phase4_group_rules.php`。
- 新增 `mc_pa2_group_behavior_settings`，保存配置组来源、过滤器、默认项、显示条件、数量限制和校验规则。
- 新增 `mc_pa2_rule_definitions`，保存配置组之间的显示、隐藏、必选、可选、物料过滤、默认项和选项限制规则。
- 新增 `track_system` 配置组和 `standard_track / intrack` 属性选项。
- 将 `track_system` 加入 `track_light_base` 模板。
- 写入 INTRACK 显示/隐藏普通接头、电源的首批规则。
- 写入磁吸灯短款过滤磁吸头的首批规则。
- 服务层新增配置组行为保存、规则保存、规则读取和规则循环检测。
- API 新增 `group_behavior_save`、`rules`、`rule_save`、`rule_cycle_check`。
- 页面新增 `rules` 规则编辑器，配置组定义中心增加行为设置区。
- 新增第 4 阶段文档和契约测试。

本阶段新增或修改文件：

- `material_center_v1/adaptation_v2/database/migrations/20260801_003_phase4_group_rules.php`
- `material_center_v1/adaptation_v2/lib/foundation.php`
- `material_center_v1/adaptation_v2/api/index.php`
- `material_center_v1/adaptation_v2/index.php`
- `material_center_v1/adaptation_v2/docs/04_GROUP_RULE_EDITOR.md`
- `material_center_v1/adaptation_v2/docs/EXECUTION_LOG.md`
- `material_center_v1/tests/adaptation_v2_phase4_contract.php`
- `WORK_CONTEXT.md`

数据库变化：

- 只新增 V2 第 4 阶段 `mc_pa2_*` 表和种子规则。
- 未修改旧 `mc_adaptation_*`、旧 BOM 或正式菜单。

测试记录：

- 本地 `git diff --check` 通过。
- 旧版适配目录、旧适配 API、旧适配服务和旧迁移 diff 为 0 行。
- 候选文件已复制到服务器 `/tmp/artdon_pa2_phase4_candidate/` 检查，PHP 语法通过：`index.php`、`api/index.php`、`foundation.php`、第 4 阶段迁移和新增契约测试。
- 候选 `adaptation_v2_phase4_contract.php` 通过。
- 正式服务器已执行 `php material_center_v1/adaptation_v2/tools/migrate.php up`，应用 `20260801_003_phase4_group_rules`。
- 正式服务器 PHP 语法检查通过：`adaptation_v2/index.php`、`api/index.php`、`lib/foundation.php`、第 4 阶段迁移、阶段契约测试。
- 正式服务器 `adaptation_v2_phase4_contract.php` 通过。
- 正式服务器页面渲染通过：`home`、`groups`、`rules`、`templates`、`logs`。
- 正式服务器只读核验：`mc_pa2_group_behavior_settings=16`、`mc_pa2_rule_definitions=9`、`mc_pa2_group_option_definitions=18`、`mc_pa2_template_groups=17`、`mc_pa2_schema_migrations=3`。
- 正式服务器规则循环检测：`cycle_count=0`、`phase4_rules=9`。

## 2026-08-01 第 5 阶段：单产品配置工作台

执行范围：

- 用户要求先进行第 5 步。
- 按主说明进入第 5 阶段：单产品配置工作台。
- 继续不修改旧版产品适配业务、不修改旧 BOM、不切换正式菜单。

完成内容：

- 新增 V2 迁移 `20260801_004_phase5_workspace.php`。
- 新增 `mc_pa2_product_configs`、`mc_pa2_product_config_versions`、`mc_pa2_product_group_configs`、`mc_pa2_product_selected_options`。
- 实现按产品查找 V2 分类和来源模板。
- 实现按模板继承结果生成产品配置草稿。
- 实现工作台详情读取、配置组检查摘要、候选物料读取和配置项保存。
- API 新增 `workspace`、`workspace_prepare`、`product_group_save`、`material_candidates`。
- 页面 `workspace` 从占位改为单产品工作台：产品摘要、三步快速流程、动态配置卡片、需要补充数量、宽版候选物料弹窗、保存草稿和检查摘要。
- 新增第 5 阶段文档和契约测试。

本阶段新增或修改文件：

- `material_center_v1/adaptation_v2/database/migrations/20260801_004_phase5_workspace.php`
- `material_center_v1/adaptation_v2/lib/foundation.php`
- `material_center_v1/adaptation_v2/api/index.php`
- `material_center_v1/adaptation_v2/index.php`
- `material_center_v1/adaptation_v2/docs/05_PRODUCT_WORKSPACE.md`
- `material_center_v1/adaptation_v2/docs/EXECUTION_LOG.md`
- `material_center_v1/tests/adaptation_v2_phase5_contract.php`
- `WORK_CONTEXT.md`

数据库变化：

- 只新增 V2 第 5 阶段 `mc_pa2_*` 表。
- 未修改旧 `mc_adaptation_*`、旧 BOM 或正式菜单。

测试记录：

- 本地 `git diff --check` 通过。
- 旧版适配目录、旧适配 API、旧适配服务和旧迁移 diff 为 0 行。
- 候选文件已复制到服务器 `/tmp/artdon_pa2_phase5_candidate/` 检查，PHP 语法通过：`index.php`、`api/index.php`、`foundation.php`、第 5 阶段迁移和新增契约测试。
- 候选 `adaptation_v2_phase5_contract.php` 通过。
- 正式服务器已执行 `php material_center_v1/adaptation_v2/tools/migrate.php up`，应用 `20260801_004_phase5_workspace`。
- 正式服务器 PHP 语法检查通过：`adaptation_v2/index.php`、`api/index.php`、`lib/foundation.php`、第 5 阶段迁移、阶段契约测试。
- 正式服务器 `adaptation_v2_phase5_contract.php` 通过。
- 正式服务器页面渲染通过：`home`、`workspace`、`products`、`rules`、`logs`。
- 正式服务器只读核验：`mc_pa2_product_configs=0`、`mc_pa2_product_config_versions=0`、`mc_pa2_product_group_configs=0`、`mc_pa2_product_selected_options=0`、`mc_pa2_schema_migrations=4`。草稿配置数为 0 是正常初始状态，用户打开产品并点击“生成配置草稿”后才写入。
- 正式服务器样品工作台只读核验：可读取产品 `266`，当前尚无配置草稿，模板回退为系统通用模板。

## 2026-08-01 第 6 阶段：适配计算和冲突引擎

执行范围：

- 用户要求继续第 6 步。
- 本阶段只开发 V2 独立适配计算层。
- 继续不修改旧版产品适配业务、不修改旧 BOM、不切换正式菜单。

完成内容：

- 新增 V2 迁移 `20260801_005_phase6_engine.php`。
- 新增 `mc_pa2_adaptation_result_cache`、`mc_pa2_adaptation_conflicts`、`mc_pa2_adaptation_recalc_jobs`。
- 实现产品技术范围保守解析：功率、电流、光束角、CCT、CRI、IP、INTRACK 标记。
- 实现电源、芯片、光学、接头、配件和通用物料候选适配判断。
- 实现四类结论：完全适配、条件适配、需要审批、不适配。
- 实现匹配度、冲突字段、原因和规则轨迹。
- 实现结果缓存、冲突明细和重新计算任务。
- 保存产品配置后自动尝试重新计算。
- API 新增 `workspace_recalculate`、`adaptation_results`，候选物料接口可返回即时适配结论。
- 工作台卡片、底部栏和候选弹窗显示第 6 阶段适配结论。
- 新增第 6 阶段文档和契约测试。

本阶段新增或修改文件：

- `material_center_v1/adaptation_v2/database/migrations/20260801_005_phase6_engine.php`
- `material_center_v1/adaptation_v2/lib/foundation.php`
- `material_center_v1/adaptation_v2/api/index.php`
- `material_center_v1/adaptation_v2/index.php`
- `material_center_v1/adaptation_v2/docs/06_ADAPTATION_ENGINE.md`
- `material_center_v1/adaptation_v2/docs/EXECUTION_LOG.md`
- `material_center_v1/tests/adaptation_v2_phase6_contract.php`
- `WORK_CONTEXT.md`

数据库变化：

- 只新增 V2 第 6 阶段 `mc_pa2_*` 表。
- 未修改旧 `mc_adaptation_*`、旧 BOM 或正式菜单。

测试记录：

- 本地 `git diff --check` 通过；办公室电脑无 PHP，已使用服务器 `/tmp/artdon_pa2_phase6_candidate/` 对候选文件做 PHP 语法和契约测试。
- 候选文件 PHP 语法通过：`index.php`、`api/index.php`、`foundation.php`、第 6 阶段迁移和新增契约测试。
- 候选 `adaptation_v2_phase6_contract.php` 通过。
- 发布提交 `e9bdf380b283bbf2657018e5eceb0cd02d21d175` 已推送 GitHub `main`，并用 Git bundle 快进同步到正式服务器 `/www/wwwroot/Artdon/artdon_erp/`。
- 正式服务器已执行 `php material_center_v1/adaptation_v2/tools/migrate.php up`，应用 `20260801_005_phase6_engine`。
- 正式服务器 PHP 语法检查通过：`adaptation_v2/index.php`、`api/index.php`、`lib/foundation.php`、第 6 阶段迁移、阶段契约测试。
- 正式服务器 `adaptation_v2_phase6_contract.php` 通过。
- 正式服务器页面渲染通过：`home`、`workspace`、`products`、`rules`、`logs`。
- 正式服务器 API 状态返回 `phase=6`。
- 正式服务器只读核验：`mc_pa2_adaptation_result_cache=0`、`mc_pa2_adaptation_conflicts=0`、`mc_pa2_adaptation_recalc_jobs=0`、`mc_pa2_schema_migrations=5`。结果缓存数为 0 是正常初始状态，用户对具体产品生成 V2 草稿并重新计算后才写入。
- 正式服务器样品引擎只读核验：产品 `266` + 电源物料 `120198` 返回 `conditional_match` / `条件适配` / `76` 分，无 Fatal Error。

## 2026-08-01 第 7 阶段：产品差异、审批和版本

执行范围：

- 用户要求继续，并询问余下步骤能否一起完成。
- 按阶段纪律继续第 7 阶段，后续阶段可连续推进但不混合提交。
- 本阶段只开发 V2 产品版本生命周期，不修改旧版产品适配业务、不修改旧 BOM、不切换正式菜单。

完成内容：

- 新增 V2 迁移 `20260801_006_phase7_versions.php`。
- 新增 `mc_pa2_product_version_events`、`mc_pa2_product_version_snapshots`、`mc_pa2_product_version_diffs`。
- 实现产品配置版本事件、快照、差异比较。
- 实现草稿提交、审批通过、驳回、发布、回滚。
- 发布后清空活动草稿；再次编辑时从当前发布版本克隆新草稿，保护旧发布版本。
- 保存配置时拒绝修改已提交、已审批或已发布版本。
- API 新增 `product_versions`、`product_version_diff`、`product_version_submit`、`product_version_approve`、`product_version_reject`、`product_version_publish`、`product_version_rollback`。
- 工作台显示版本状态、版本列表和审批/发布/回滚动作。
- 新增第 7 阶段文档和契约测试。

本阶段新增或修改文件：

- `material_center_v1/adaptation_v2/database/migrations/20260801_006_phase7_versions.php`
- `material_center_v1/adaptation_v2/lib/foundation.php`
- `material_center_v1/adaptation_v2/api/index.php`
- `material_center_v1/adaptation_v2/index.php`
- `material_center_v1/adaptation_v2/docs/07_VERSION_APPROVAL.md`
- `material_center_v1/adaptation_v2/docs/EXECUTION_LOG.md`
- `material_center_v1/tests/adaptation_v2_phase7_contract.php`
- `WORK_CONTEXT.md`

数据库变化：

- 只新增 V2 第 7 阶段 `mc_pa2_*` 表。
- 未修改旧 `mc_adaptation_*`、旧 BOM 或正式菜单。

测试记录：

- 本地 `git diff --check` 通过；旧版适配目录、旧适配 API、旧适配服务和旧迁移 diff 为 0 行。
- 候选文件已复制到服务器 `/tmp/artdon_pa2_phase7_candidate/` 检查，PHP 语法通过：`index.php`、`api/index.php`、`foundation.php`、第 7 阶段迁移和新增契约测试。
- 候选 `adaptation_v2_phase7_contract.php` 通过。
- 发布提交 `362a8ed1392ef27a7e50ff4a4c35aa7a4d4b4cd5` 已推送 GitHub `main`，并用 Git bundle 快进同步到正式服务器 `/www/wwwroot/Artdon/artdon_erp/`。
- 正式服务器已执行 `php material_center_v1/adaptation_v2/tools/migrate.php up`，应用 `20260801_006_phase7_versions`。
- 正式服务器 PHP 语法检查通过：`adaptation_v2/index.php`、`api/index.php`、`lib/foundation.php`、第 7 阶段迁移、阶段契约测试。
- 正式服务器 `adaptation_v2_phase7_contract.php` 通过。
- 正式服务器页面渲染通过：`home`、`workspace`、`products`、`rules`、`logs`。
- 正式服务器 API 状态返回 `phase=7`。
- 正式服务器只读核验：`mc_pa2_product_version_events=0`、`mc_pa2_product_version_snapshots=0`、`mc_pa2_product_version_diffs=0`、`mc_pa2_schema_migrations=6`。事件/快照/差异为 0 是正常初始状态，用户提交/审批/发布后才写入。
- 正式服务器样品版本只读核验：产品 `266` 可读取 `version_count=1`，无 Fatal Error。

## 2026-08-01 第 8 阶段：配置包中心

执行范围：

- 用户要求继续余下步骤。
- 本阶段开发 V2 配置包中心，作为第 9 阶段商务中心/新加坡网站下游接口的前置数据模型。
- 本阶段仍不修改旧版产品适配业务、不修改旧 BOM、不切换正式菜单。

完成内容：

- 新增 V2 迁移 `20260801_007_phase8_packages.php`。
- 新增 `mc_pa2_config_packages`、`mc_pa2_config_package_versions`、`mc_pa2_config_package_groups`、`mc_pa2_config_package_options`。
- 首批种子配置包：Commercial Flexible、Singapore Standard、Singapore DALI、Singapore Ready Stock。
- 每个首批配置包带 1 个草稿版本、配置组规则和必要选项。
- 支持锁定模式：`open`、`locked`、`range_limited`、`default_locked`。
- 组规则承载允许范围、默认项、价格、MOQ、库存和交期规则。
- 选项承载默认、锁定、价格差异、MOQ、库存、交期和规则 JSON。
- API 新增配置包列表、详情、保存、准备草稿、保存组、保存选项、预览、发布。
- 页面新增配置包中心，显示版本、统计、预览检查、组规则和选项摘要。
- 发布前检查：Ready Stock 关键物料锁定、Standard 指定光学/颜色范围、DALI 固定 DALI、电包版本可追溯。

本阶段新增或修改文件：

- `material_center_v1/adaptation_v2/database/migrations/20260801_007_phase8_packages.php`
- `material_center_v1/adaptation_v2/lib/foundation.php`
- `material_center_v1/adaptation_v2/api/index.php`
- `material_center_v1/adaptation_v2/index.php`
- `material_center_v1/adaptation_v2/docs/08_CONFIG_PACKAGE_CENTER.md`
- `material_center_v1/adaptation_v2/docs/EXECUTION_LOG.md`
- `material_center_v1/tests/adaptation_v2_phase8_contract.php`
- `WORK_CONTEXT.md`

数据库变化：

- 只新增 V2 第 8 阶段 `mc_pa2_*` 表。
- 未修改旧 `mc_adaptation_*`、旧 BOM 或正式菜单。

测试记录：

- 本地 `git diff --check` 通过；旧版适配目录、旧适配 API、旧适配服务和旧迁移 diff 为 0 行。
- 候选文件已复制到服务器 `/tmp/artdon_pa2_phase8_candidate/` 检查，PHP 语法通过：`index.php`、`api/index.php`、`foundation.php`、第 8 阶段迁移和新增契约测试。
- 候选 `adaptation_v2_phase8_contract.php` 通过。
- 发布提交 `4990059429e3132f552e79743927bde01aa54a3d` 已推送 GitHub `main`，并用 Git bundle 快进同步到正式服务器 `/www/wwwroot/Artdon/artdon_erp/`。
- 正式服务器已执行 `php material_center_v1/adaptation_v2/tools/migrate.php up`，应用 `20260801_007_phase8_packages`。
- 正式服务器 PHP 语法检查通过：`adaptation_v2/index.php`、`api/index.php`、`lib/foundation.php`、第 8 阶段迁移、阶段契约测试。
- 正式服务器 `adaptation_v2_phase8_contract.php` 通过。
- 正式服务器页面渲染通过：`home`、`packages`。
- 正式服务器 API 状态返回 `phase=8`、`phase_name=配置包中心`，并包含 `package_publish`。
- 正式服务器数据库核验：`mc_pa2_config_packages=4`、`mc_pa2_config_package_versions=4`、`mc_pa2_config_package_groups=17`、`mc_pa2_config_package_options=13`、`mc_pa2_schema_migrations=7`。
- 正式服务器配置包预览核验：Commercial Flexible、Singapore Standard、Singapore DALI、Singapore Ready Stock 均有 `draft-1` 版本；Ready Stock 锁定组 4 个；Standard 范围限定组 2 个；DALI 锁定组 2 个；四个包的预览检查均通过。

## 2026-08-01 第 9 阶段：下游渠道接口

执行范围：

- 用户要求继续余下步骤。
- 本阶段开发 V2 下游只读接口，为商务中心和新加坡网站接入准备。
- 本阶段仍不修改旧版产品适配业务、不修改旧 BOM、不切换正式菜单。

完成内容：

- 新增 V2 迁移 `20260801_008_phase9_channel_api.php`。
- 新增 `mc_pa2_channel_clients`、`mc_pa2_channel_package_snapshots`、`mc_pa2_channel_cache`、`mc_pa2_channel_access_logs`、`mc_pa2_channel_order_snapshots`。
- 首批渠道客户端：`commercial_center`、`singapore_site`。
- 签名机制：`X-PA2-Client`、`X-PA2-Timestamp`、`X-PA2-Signature`，HMAC-SHA256，密钥从服务器环境变量读取。
- 下游接口只读 `published` 配置包和 `published` 活动版本，不暴露草稿。
- API 新增 `channel_clients`、`channel_packages`、`channel_package_detail`、`channel_order_snapshot`。
- 新增渠道缓存、发布包载荷快照、访问日志、订单配置快照。
- 页面新增渠道发布状态页，显示客户端、接口、签名方式、缓存/快照/日志和配置包下游可见状态。

本阶段新增或修改文件：

- `material_center_v1/adaptation_v2/database/migrations/20260801_008_phase9_channel_api.php`
- `material_center_v1/adaptation_v2/lib/foundation.php`
- `material_center_v1/adaptation_v2/api/index.php`
- `material_center_v1/adaptation_v2/index.php`
- `material_center_v1/adaptation_v2/docs/09_CHANNEL_API.md`
- `material_center_v1/adaptation_v2/docs/EXECUTION_LOG.md`
- `material_center_v1/tests/adaptation_v2_phase9_contract.php`
- `WORK_CONTEXT.md`

数据库变化：

- 只新增 V2 第 9 阶段 `mc_pa2_*` 表。
- 未修改旧 `mc_adaptation_*`、旧 BOM 或正式菜单。

测试记录：

- 本地 `git diff --check` 通过；旧版适配目录、旧适配 API、旧适配服务和旧迁移 diff 为 0 行。
- 候选文件已复制到服务器 `/tmp/artdon_pa2_phase9_candidate/` 检查，PHP 语法通过：`index.php`、`api/index.php`、`foundation.php`、第 9 阶段迁移和新增契约测试。
- 候选 `adaptation_v2_phase9_contract.php` 通过。
- 发布提交 `caa9d39bbffbea25339f10e5db743dd47b01f9f8` 已推送 GitHub `main`，并用 Git bundle 快进同步到正式服务器 `/www/wwwroot/Artdon/artdon_erp/`。
- 正式服务器已执行 `php material_center_v1/adaptation_v2/tools/migrate.php up`，应用 `20260801_008_phase9_channel_api`。
- 正式服务器 PHP 语法检查通过：`adaptation_v2/index.php`、`api/index.php`、`lib/foundation.php`、第 9 阶段迁移、阶段契约测试。
- 正式服务器 `adaptation_v2_phase9_contract.php` 通过。
- 正式服务器页面渲染通过：`publish`。
- 正式服务器 API 状态返回 `phase=9`，并包含 `channel_packages`。
- 正式服务器数据库核验：`mc_pa2_channel_clients=2`、`mc_pa2_schema_migrations=8`。
- 下游只读核验：`commercial_visible=0`、`singapore_visible=0`，原因是第 8 阶段首批配置包仍为草稿；接口按规则不暴露草稿。
- 缓存核验：调用只读函数后 `mc_pa2_channel_cache=2`。
- 签名核验：未签名访问 `channel_packages` 返回失败，消息为“缺少渠道签名。”，并写入访问日志，`mc_pa2_channel_access_logs=1`。

## 2026-08-01 第 10 阶段：最终验收和切换评估

执行范围：

- 用户要求继续余下步骤。
- 本阶段只做 V2 最终验收、切换评估、阻断清单和审计记录。
- 本阶段不切换正式菜单，不修改旧版产品适配业务，不修改旧 BOM。

完成内容：

- 新增 V2 迁移 `20260801_009_phase10_cutover_readiness.php`。
- 新增 `mc_pa2_cutover_audits`、`mc_pa2_cutover_check_items`。
- 实现 `pa2_cutover_readiness()`：检查旧版边界、正式菜单状态、第 2–9 阶段表、规则循环、配置包发布和真实业务回归要求。
- 实现 `pa2_record_cutover_audit()`：把本次 readiness 结果写入审计表和检查项表。
- API 新增 `cutover_readiness`、`cutover_audit_record`。
- 页面新增 `cutover` 最终验收视图，显示当前决策、阻断项和全量检查项。
- 当前逻辑会明确输出“不允许切换正式菜单”，直到已发布配置包和真实业务回归完成。

本阶段新增或修改文件：

- `material_center_v1/adaptation_v2/database/migrations/20260801_009_phase10_cutover_readiness.php`
- `material_center_v1/adaptation_v2/lib/foundation.php`
- `material_center_v1/adaptation_v2/api/index.php`
- `material_center_v1/adaptation_v2/index.php`
- `material_center_v1/adaptation_v2/docs/10_CUTOVER_READINESS.md`
- `material_center_v1/adaptation_v2/docs/EXECUTION_LOG.md`
- `material_center_v1/tests/adaptation_v2_phase10_contract.php`
- `WORK_CONTEXT.md`

数据库变化：

- 只新增 V2 第 10 阶段 `mc_pa2_*` 表。
- 未修改旧 `mc_adaptation_*`、旧 BOM 或正式菜单。

测试记录：

- 本地 `git diff --check` 通过；旧版适配目录、旧适配 API、旧适配服务、旧迁移和旧 BOM diff 为 0 行。
- 候选文件已复制到服务器 `/tmp/artdon_pa2_phase10_candidate/` 检查，PHP 语法通过：`index.php`、`api/index.php`、`foundation.php`、第 10 阶段迁移和新增契约测试。
- 候选 `adaptation_v2_phase10_contract.php` 通过。
- 发布提交 `1dad932788bc48fcc3b6089c8a3a21e1f356f504` 已推送 GitHub `main`，并用 Git bundle 快进同步到正式服务器 `/www/wwwroot/Artdon/artdon_erp/`。
- 正式服务器已执行 `php material_center_v1/adaptation_v2/tools/migrate.php up`，应用 `20260801_009_phase10_cutover_readiness`。
- 正式服务器 PHP 语法检查通过：`adaptation_v2/index.php`、`api/index.php`、`lib/foundation.php`、第 10 阶段迁移、阶段契约测试。
- 正式服务器 `adaptation_v2_phase10_contract.php` 通过。
- 正式服务器页面渲染通过：`cutover`。
- 正式服务器 API 状态返回 `phase=10`，并包含 `cutover_readiness`。
- 正式服务器数据库核验：`mc_pa2_schema_migrations=9`、`mc_pa2_cutover_audits=0`、`mc_pa2_cutover_check_items=0`。审计表为 0 是因为本轮未绕过权限直接写入，需有权限账号在页面点击“记录本次验收”后写入。
- 最终切换评估核验：`status=blocked`、`ready_to_switch=false`、`decision=不得切换正式菜单`。
- 当前阻断项：`published_packages_exist`、`real_business_regression_required`。

## 2026-08-01 配置组定义中心扩展：配件、玻璃、蜂窝网、四叶片、光学膜

执行范围：

- 用户要求在配置组定义中心加上配件、玻璃、蜂窝网、四叶片、光学膜。
- 本次只扩展 V2 配置组定义和默认行为，不修改旧版产品适配业务、不修改旧 BOM、不切换正式菜单。

完成内容：

- 新增 V2 迁移 `20260801_010_accessory_group_definitions.php`。
- 新增配置组：`accessory` 配件、`glass` 玻璃、`honeycomb` 蜂窝网、`four_leaf_louver` 四叶片、`optical_film` 光学膜。
- 5 个配置组均为 `material_select`，来源均为正式物料 `mc_material_accessory` 对应的 `accessory` 物料分类。
- 默认行为：
  - 配件：可选，多选。
  - 玻璃：可选，单选，候选关键词“玻璃”。
  - 蜂窝网：可选，单选，候选关键词“蜂”。
  - 四叶片：可选，单选，候选关键词“四叶片”。
  - 光学膜：可选，多选，候选关键词“膜”。
- 新增契约测试 `material_center_v1/tests/adaptation_v2_accessory_groups_contract.php`。

测试记录：

- 本地 `git diff --check` 通过；旧版适配目录、旧适配 API、旧适配服务、旧迁移和旧 BOM diff 为 0 行。
- 候选文件已复制到服务器 `/tmp/artdon_pa2_accessory_groups_candidate/` 检查，第 10 追加迁移和契约测试 PHP 语法通过。
- 候选 `adaptation_v2_accessory_groups_contract.php` 通过。
- 发布提交 `86f0004ec44bee0e15f3514de67544cefccec119` 已推送 GitHub `main`，并用 Git bundle 快进同步到正式服务器 `/www/wwwroot/Artdon/artdon_erp/`。
- 正式服务器已执行 `php material_center_v1/adaptation_v2/tools/migrate.php up`，应用 `20260801_010_accessory_group_definitions`。
- 正式服务器 `adaptation_v2_accessory_groups_contract.php` 通过。
- 正式服务器 `groups` 页面 CLI 渲染无 Fatal。
- 正式服务器数据库核验：5 个配置组均存在，且默认行为均为 `official_material` / `accessory`；配件和光学膜为多选，玻璃、蜂窝网、四叶片为单选；`mc_pa2_schema_migrations=10`。

## 2026-08-01 配置组定义中心 UI 优化：新增配置组改为窄版弹窗

执行范围：

- 用户要求将“新增配置组”从页面内联填空表单改为弹窗新建，减少主页面占用，并提升视觉设计。
- 本次只修改 V2 页面 `material_center_v1/adaptation_v2/index.php`，不修改旧版产品适配业务、不修改旧 BOM、不切换正式菜单、不修改数据库结构。

完成内容：

- 配置组定义中心顶部新增“新增配置组”按钮。
- 原页面内联新增表单移入窄版弹窗 `pa2-group-create-dialog`。
- 弹窗支持组编码、配置组名称、组类型、排序、图标、启用状态、说明等字段。
- 弹窗增加编码提示，便于后续继续创建 `glass`、`honeycomb`、`optical_film` 等规则化配置组。
- 增加前端打开/关闭逻辑；保存仍沿用原有 `group_save` API。

测试记录：

- 本地 `git diff --check` 通过。
- 服务器 PHP 语法检查通过：`material_center_v1/adaptation_v2/index.php`。

## 2026-08-01 配置组定义中心表格去内联输入

执行范围：

- 用户要求配置组定义中心“所有新建”都改为弹窗方式，不准在当前页直接填空。
- 本次只调整 V2 配置组定义中心页面交互，不修改数据库、不修改旧版产品适配、不修改旧 BOM。

完成内容：

- 保留“新增配置组”弹窗。
- 新增“新增属性选项”弹窗；原表格里的“选项编码 / 选项名称 / 默认”内联新增表单已移除。
- “编辑配置组”从表格内联输入改为弹窗。
- “编辑行为 / 来源”从表格 details 内联表单改为弹窗。
- 配置组定义中心表格现在只展示数据和操作按钮，不在当前页直接铺输入框。

测试记录：

- 本地 `git diff --check` 通过。
- 服务器 PHP 语法检查通过：`material_center_v1/adaptation_v2/index.php`。

## 2026-08-01 配置组定义中心滚动修复

执行范围：

- 用户反馈配置组定义中心页面无法继续向下滚动，导致排序靠后的配件、玻璃、蜂窝网、四叶片、光学膜看不到。
- 本次只修复 V2 页面滚动和快速定位交互，不修改数据库、不修改旧版产品适配、不修改旧 BOM。

原因判断：

- 物料中心通用布局中 `mc-main` 使用 `overflow:hidden`，页面需要自己提供可滚动容器。
- V2 根页面虽然使用了 `mc-page`，但自定义网格布局和面板 `overflow:hidden` 会在当前分辨率下裁剪长表格，造成“看得到上面、滚不到下面”。

完成内容：

- V2 根容器 `mc-pa2-page` 明确设置 `height:100%`、`overflow:auto`、`grid-auto-rows:max-content` 和底部留白。
- V2 面板不再裁剪长内容。
- 顶部新增配件组快捷入口增加 `scrollIntoView` 兜底，点击“蜂窝网 / 四叶片”等按钮可直接滚到目标行。

测试记录：

- 本地 `git diff --check` 通过。
- 服务器 PHP 语法检查通过：`material_center_v1/adaptation_v2/index.php`。

## 2026-08-01 配置组定义中心新增配件组定位

执行范围：

- 用户反馈看不到此前新增的配件、玻璃、蜂窝网、四叶片、光学膜配置组。
- 经服务器只读核验，5 个配置组均已存在并启用，排序为 121–125；列表默认从排序 10 开始展示，需向下滚动才能看到。

完成内容：

- 在配置组定义中心顶部增加“新增配件配置组已创建”快速定位条。
- 快速定位条列出配件、玻璃、蜂窝网、四叶片、光学膜。
- 点击任一名称可跳转到对应表格行，并对目标行做浅黄色高亮。
- 本次只修改 V2 页面展示，不修改数据库、不修改旧版产品适配、不修改旧 BOM。

测试记录：

- 服务器只读核验：`accessory`、`glass`、`honeycomb`、`four_leaf_louver`、`optical_film` 均存在且启用。
- 本地 `git diff --check` 通过。
- 服务器 PHP 语法检查通过：`material_center_v1/adaptation_v2/index.php`。
- 服务器 `groups` 页面 CLI 渲染成功，HTML 中确认存在 `pa2-group-create-dialog` 和 `data-open-group-create`。

## 2026-08-01 配置组定义中心中文化显示

执行范围：

- 用户指出配置组定义中心里 `material_select`、`official_material`、`chip`、`single` 等英文编码不适合直接显示给业务用户。
- 本次只做 V2 页面展示层中文化，不修改保存值、不修改数据库、不修改旧版产品适配、不修改旧 BOM。

完成内容：

- 配置组类型显示改为中文，例如 `material_select` 显示为“物料选择”。
- 行为来源显示改为中文，例如 `material` / `official_material` 显示为“物料 / 正式物料”。
- 物料分类显示改为中文，例如 `chip` 显示为“芯片 / 光源”，`power_supply` 显示为“电源 / 驱动”。
- 选择方式显示改为中文，例如 `single` / `multiple` 显示为“单选 / 多选”。
- 物料过滤器优先显示中文摘要，例如“物料状态：正式物料”“审核要求：必须已审核”；原始 JSON 仅保留在“查看原始条件”的高级展开里。
- “编辑行为”中的物料分类由手填英文编码改为中文下拉，提交值仍为系统编码。

测试记录：

- 本地 `git diff --check` 通过。
- 服务器 PHP 语法检查通过：`material_center_v1/adaptation_v2/index.php`。
