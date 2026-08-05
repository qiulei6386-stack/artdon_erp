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

## 2026-08-01 单产品工作台物料多选

执行范围：

- 用户反馈单产品配置工作台中电源等物料不能多选，只能选中一个。
- 本次只修改 V2 单产品工作台物料选择弹窗和 V2 产品配置保存接口。
- 不修改旧版产品适配业务、不修改旧 BOM、不修改正式物料数据。

完成内容：

- “选择正式物料”弹窗改为候选物料勾选模式，可一次选择多个物料后统一保存。
- 弹窗会回填当前已选物料，已选项打开后默认勾选。
- 取消勾选并保存即可从当前产品配置组移除该物料。
- 保存接口新增 `material_ids[]` 批量保存支持，后端会去重并一次写入多条 `mc_pa2_product_selected_options`。
- 接口保存顺序改为先校验再替换，避免参数错误时误清空旧选择。
- 保留模板数量上限能力：模板设置了 `max_select > 1` 时按上限限制；模板仍为单选时允许当前产品作为产品级覆盖多选。

测试记录：

- 本地 `git diff --check` 通过。
- 服务器 PHP 语法检查通过：`material_center_v1/adaptation_v2/index.php`。
- 服务器 PHP 语法检查通过：`material_center_v1/adaptation_v2/lib/foundation.php`。
- 本地抽取页面内嵌 JavaScript 做语法检查通过。

## 2026-08-01 单产品工作台配置逻辑弹窗

执行范围：

- 用户询问当前产品如何设置电源逻辑、芯片逻辑，并确认开始制作。
- 本次只修改 V2 单产品配置工作台、V2 API 和 V2 适配引擎。
- 不修改旧版产品适配业务、不修改旧 BOM、不修改模板本体。

完成内容：

- 单产品工作台的物料配置卡新增“设置逻辑”按钮。
- 新增“设置配置逻辑”弹窗，支持回填和保存当前产品级覆盖：
  - 是否必选、单选/多选、最少/最多选择、是否允许为空；
  - 物料分类、只用正式物料、关键词过滤；
  - 电源类型：外置、内置、INTRACK；
  - 功率范围、电流范围、电压范围、调光方式；
  - 芯片色温、最低 CRI；
  - 光学光束角和备注。
- 新增 API 动作 `product_group_logic_save`。
- 产品级逻辑保存到 `mc_pa2_product_group_configs.effective_settings_json` 的 `product_logic` / `behavior.material_filter`，只影响当前产品草稿。
- 保存后自动重新计算当前产品适配结果。
- 适配引擎新增读取产品级逻辑：
  - 电源按产品级功率、电流、电压、内置/外置/INTRACK、调光方式判断；
  - 芯片按产品级功率、色温、CRI 判断；
  - 光学按产品级光束角判断。

测试记录：

- 本地 `git diff --check` 通过。
- 本地抽取页面内嵌 JavaScript 做语法检查通过。
- 服务器 PHP 语法检查通过：`material_center_v1/adaptation_v2/index.php`。
- 服务器 PHP 语法检查通过：`material_center_v1/adaptation_v2/lib/foundation.php`。
- 服务器 PHP 语法检查通过：`material_center_v1/adaptation_v2/api/index.php`。

## 2026-08-01 单产品添加配置模板处理方式

执行范围：

- 用户要求单产品工作台“套用配置模板”改为更清晰的“添加”，并支持“添加并删除之前的”。
- 本次只修改 V2 单产品工作台和 V2 工作台准备逻辑，不修改旧版产品适配、不修改旧 BOM、不切正式菜单。

完成内容：

- 单产品工作台按钮改为“添加配置模板”。
- 弹窗标题改为“添加配置模板”。
- 处理方式由不可编辑说明改为可选下拉：
  - “添加”：保留当前配置，只补齐模板新增配置组；
  - “添加并移除旧配置组”：移除当前 V2 草稿中不属于新模板的旧配置组，再添加新模板配置组。
- 后端 `pa2_prepare_workspace` 增加 `applyMode`，支持 `append` 与 `replace`。
- `replace` 模式会同步删除被移除旧配置组下的 V2 草稿已选项和适配计算缓存；不影响旧 BOM、旧版适配和已发布历史版本。
- 为避免误清空，若新模板没有有效配置组，禁止执行“添加并移除旧配置组”。

测试记录：

- 本地 `git diff --check` 通过。
- 服务器 PHP 语法检查通过：`material_center_v1/adaptation_v2/index.php`、`material_center_v1/adaptation_v2/lib/foundation.php`。

## 2026-08-01 产品适配正式入口切到 V2

执行范围：

- 用户明确要求旧版产品适配不要作为入口；点击“产品适配”应进入当前 V2 版本。
- 本次只切换入口和兼容跳转；不删除旧版目录、不删除旧数据、不修改旧 BOM。

完成内容：

- 物料中心侧边栏“产品适配”链接从 `adaptation/index.php` 改为 `adaptation_v2/index.php`。
- 物料中心首页“产品适配存在冲突”和“快速进入 / 产品适配”链接改为 V2。
- `material_center_v1/product_adaptation.php` 兼容入口改为跳转 V2。
- `material_center_v1/module.php` 中适配总览、芯片适配规则、光学适配规则、适配冲突映射改为 V2。
- 旧入口 `material_center_v1/adaptation/index.php` 增加 302 兼容跳转：
  - 旧首页跳 V2 首页；
  - 旧 `view=products` 跳 V2 全部产品；
  - 旧 `product_id=...` 跳 V2 单产品工作台。
- V2 页面顶部和最终验收页文案改为“正式入口已切到 V2”，不再显示“返回旧版产品适配”。

测试记录：

- 本地 `git diff --check` 通过。
- 入口静态核验通过：侧边栏、物料中心首页产品适配链接、`product_adaptation.php`、`module.php`、旧 `adaptation/index.php` 均指向 V2 或跳转 V2。
- 物料中心首页仍保留为 `material_center_v1/index.php` 工作台，只修改其中“产品适配”链接，不把首页本身改成 V2。
- 服务器 PHP 语法检查通过：`adaptation/index.php`、`adaptation_v2/index.php`、`adaptation_v2/api/index.php`、`adaptation_v2/lib/foundation.php`、`components/sidebar.php`、`product_adaptation.php`、`index.php`、`module.php`、`app/Support/helpers.php`。

## 2026-08-01 模板配置组回填编辑

执行范围：

- 用户要求模板编辑器中配置组的继承动作、选择方式、排序、数量限制、必选/可空、价格/交期/审批等信息都可以回填并编辑保存。
- 本次只修改 V2 模板编辑器页面，不修改旧版产品适配、不修改旧 BOM、不切正式菜单。

完成内容：

- 直接配置组列表每项新增“编辑”按钮。
- 点击“编辑”会把该配置组的配置组、继承动作、选择方式、排序、最少、最多、必选、允许为空、影响价格、影响交期、需要审批全部回填到上方表单。
- 上方表单增加“清空为新增”和“保存配置组设置”，同一表单既可新增，也可编辑已有配置组。
- 配置组卡片摘要补充显示数量限制、允许为空、价格、交期、审批状态，避免只看到简略信息。

测试记录：

- 本地 `git diff --check` 通过。
- 服务器 PHP 语法检查通过：`material_center_v1/adaptation_v2/index.php`。

## 2026-08-01 单产品工作台配置来源打通

执行范围：

- 用户提出分类和模板中心是方法，但单产品工作台也需要能直接设置。
- 本次只修改 V2 独立目录 `adaptation_v2`，不修改旧版产品适配、不修改旧 BOM、不切正式菜单。

完成内容：

- 单产品配置工作台新增“设置分类”和“套用模板”两个弹窗入口。
- “设置分类”可在当前产品上保存 V2 产品分类、系列编码，并可同时选择模板。
- “套用模板”可直接为当前产品套用指定配置模板，例如嵌入式产品可直接套用嵌入式模板。
- 新增 API `workspace_source_save`，把产品分类映射、来源模板和工作台草稿准备串起来。
- 套用模板后会补齐模板里的配置组；已选择的物料和属性不会被清空。

测试记录：

- 本地 `git diff --check` 通过。
- 服务器 PHP 语法检查通过：`material_center_v1/adaptation_v2/lib/foundation.php`、`api/index.php`、`index.php`。
- 服务器 API 状态核验通过：`workspace_source_save` 已进入允许动作列表。
- 服务器工作台 CLI 渲染通过：HTML 中确认存在 `pa2-workspace-category-dialog`、`pa2-workspace-template-dialog`。
- 命令行渲染没有登录用户权限上下文，因此按钮文字是否显示以网页登录配置权限账号为准。

## 2026-08-01 模板编辑器配置组移除入口

执行范围：

- 用户反馈模板编辑器中已有配置组只有状态标记，没有“移除”入口。
- 本次只修改 V2 模板编辑器页面，不修改旧版产品适配、不修改旧 BOM、不切正式菜单。

完成内容：

- 模板编辑器的每个配置组卡片新增“移除”按钮。
- 移除采用 V2 模板继承动作 `disable`，不物理删除历史记录；右侧继承预览会从最终有效结构中移除该组。
- 已移除的配置组显示“已移除”，并提供“重新加入”按钮用于恢复。
- 配置组动作显示由英文 `add/override/disable` 改为中文“新增/覆盖/已移除”。
- 表单提交支持确认提示，避免误移除。

测试记录：

- 本地 `git diff --check` 通过。
- 服务器 PHP 语法检查通过：`material_center_v1/adaptation_v2/index.php`。

## 2026-08-01 产品分类中心和模板中心新增弹窗化

执行范围：

- 用户反馈模板中心、产品分类中心仍有页面内联新增表单，没有改成弹窗。
- 本次补齐用户截图中两个页面：`view=categories`、`view=templates`。
- 本次只调整 V2 页面交互，不修改数据库、不修改旧版产品适配、不修改旧 BOM。

完成内容：

- 产品分类中心顶部新增“新增分类”按钮，点击打开“新增产品分类”弹窗。
- 产品分类中心表格内联编辑表单改为“编辑”按钮，点击打开“编辑产品分类”弹窗。
- 模板中心顶部新增“新增模板”按钮，点击打开“新增配置模板”弹窗。
- 模板中心不再在当前页直接铺新增模板输入框。

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

## 2026-08-01 模板逻辑与单产品自定义逻辑打通

执行范围：

- 用户指出“电源逻辑、芯片逻辑”也需要模板，既要能选模板，也要能单产品自填、自定义。
- 本次只修改 V2 独立目录 `adaptation_v2`，不修改旧版产品适配、不修改旧 BOM、不新增旧表。

完成内容：

- 模板配置组保存时写入 `mc_pa2_template_groups.settings_json`，支持模板默认逻辑。
- 模板配置组编辑表单增加物料分类、正式物料过滤、关键词、电源类型、功率/电流/电压范围、调光、色温、显指、光束角和备注。
- 模板配置组列表增加逻辑摘要标签，例如“外置电源”“正式物料”“功率范围”“电流范围”。
- 生成或套用单产品 V2 草稿时，产品配置组会继承模板默认逻辑。
- 若单产品已有自定义逻辑，重新套用模板时保留产品自定义，避免被模板覆盖。
- 单产品“设置逻辑”弹窗增加“逻辑来源”：
  - 使用模板逻辑；
  - 自定义覆盖当前产品；
  - 不使用逻辑 / 清空当前产品逻辑。
- 适配计算继续读取产品草稿中的 `product_logic` 和 `behavior.material_filter`，因此模板继承和产品自定义都会参与候选判断。

测试记录：

- 本地 `git diff --check` 通过。
- 使用服务器 PHP 对本地新代码执行语法检查通过：`material_center_v1/adaptation_v2/index.php`、`material_center_v1/adaptation_v2/lib/foundation.php`。
- 待上线后在真实页面验证：模板编辑器中模板逻辑回填/保存；单产品工作台中“使用模板逻辑 / 自定义覆盖当前产品 / 清空当前产品逻辑”保存并重新计算。

## 2026-08-01 单产品配置 A/B/C 方案组合选择

执行范围：

- 用户要求物料中心配置支持类似截图中的“配置 A / B / C”组合选择。
- 本次只基于 V2 当前已选配置组生成组合方案，不修改旧版产品适配、不修改旧 BOM、不新增数据库表。

完成内容：

- 单产品工作台根据各配置组已选项顺序自动生成配置方案：
  - 每个配置组第 1 个选项组成配置 A；
  - 每个配置组第 2 个选项组成配置 B；
  - 每个配置组第 3 个选项组成配置 C；
  - 只有一个选项的配置组会复用到所有方案。
- 工作台新增“配置方案 · 当前版本”区域，展示每个方案中的芯片、电源、光学等已选物料。
- 支持在草稿/驳回状态下点击“设为采用”，保存默认采用方案到当前版本 `configuration_snapshot_json.selected_scheme`。
- 版本快照新增 `schemes`，发布后商务中心读取物料中心发布的方案，保留用户在物料中心选择的默认方案。
- 生成下一版草稿时会继承上一版本已选择的默认方案。

测试记录：

- 本地 `git diff --check` 通过。
- 使用服务器 PHP 对本地新代码执行语法检查通过：
  - `material_center_v1/adaptation_v2/index.php`
  - `material_center_v1/adaptation_v2/lib/foundation.php`
  - `material_center_v1/adaptation_v2/api/index.php`
  - `commercial_center_v1/app/Repositories/LegacyCatalogReadRepository.php`

## 2026-08-01 配置 A/B/C 改为空白手工搭配

执行范围：

- 用户反馈自动按第 1 / 第 2 个物料生成配置 A/B 不符合实际使用，需要系统只提供空白方案位，由业务人员自行加入和搭配。
- 本次只修改产品适配 V2 `adaptation_v2` 与 `mc_pa2_` 数据结构中的版本快照 JSON，不修改旧版产品适配、不修改旧 BOM、不新增数据库表。

完成内容：

- 单产品工作台的配置方案固定显示 A / B / C 三个空白方案位。
- 取消按选项顺序自动组合芯片、电源、光学等配置。
- 新增“编辑方案”弹窗，可从当前草稿已加入的正式物料/属性选项中，按配置组选入当前方案。
- 支持“保存方案”和“保存并采用”，采用状态保存到 `configuration_snapshot_json.selected_scheme`。
- 每个方案的手工搭配保存到 `configuration_snapshot_json.manual_schemes`，发布快照中的 `schemes` 也读取手工方案。
- 生成下一版草稿时继承已保存的手工 A/B/C 方案。

测试记录：

- 本地 `git diff --check` 通过。
- 使用服务器 PHP 对本地新代码执行语法检查通过：
  - `material_center_v1/adaptation_v2/index.php`
  - `material_center_v1/adaptation_v2/lib/foundation.php`
  - `material_center_v1/adaptation_v2/api/index.php`
- 待上线后在真实页面验证：空白 A/B/C 显示、编辑方案弹窗回填、保存并采用、刷新后不丢失、发布快照包含手工方案。

## 2026-08-01 配置方案卡片支持双击弹窗

执行范围：

- 用户要求配置 A/B/C 空白方案位可直接双击弹窗，减少操作步骤。
- 本次只修改产品适配 V2 单产品工作台前端交互，不修改旧版产品适配、不修改旧 BOM、不改数据库。

完成内容：

- 配置方案卡片增加双击打开“编辑配置方案”弹窗。
- 保留“编辑方案”按钮单击打开弹窗，避免不习惯双击的用户找不到入口。
- 卡片 hover 增加轻微提示效果，title 提示“双击编辑这个配置方案”。

测试记录：

- 待上线前执行语法检查和三端版本校验。

补充修复：

- 双击绑定原先跟随草稿可编辑状态输出，非草稿/驳回版本的方案卡片不会带 `data-open-scheme-editor`，导致双击无反应。
- 改为只要用户有产品配置权限，A/B/C 卡片就能双击打开弹窗；如果当前版本锁定，弹窗内提示先生成下一版草稿。
- JS 改为事件委托绑定，避免卡片/按钮结构变化后漏绑事件。

## 2026-08-01 单产品芯片/电源/光学逻辑重分区

执行范围：

- 用户反馈单产品“设置逻辑”弹窗中芯片逻辑字段不对，芯片不应出现电源类型、调光方式等电源字段。
- 本次只修改产品适配 V2 单产品工作台和 V2 后台逻辑保存/适配判断，不修改旧版产品适配、不修改旧 BOM、不新增数据库表。

完成内容：

- “设置逻辑”弹窗按配置组自动切换：
  - 芯片 / 光源：芯片品牌、芯片型号/系列、色温、显指、芯片工作电流/正向电压。
  - 电源 / 驱动：电源类型、功率范围、输出电流、输出电压、调光方式。
  - 光学 / 透镜：光束角、光学类型、材质/表面、光学关键词。
  - 通用配置：只保留基础规则和物料关键词。
- 隐藏字段自动禁用，不再随表单提交，避免芯片继续误保存调光方式/电源类型。
- 后台保存时按配置组类型净化逻辑字段：
  - 芯片剔除电源类型、功率和调光字段；
  - 电源剔除芯片和光学字段；
  - 光学剔除电源和芯片字段。
- 适配计算新增芯片品牌/系列、光学类型/材质/关键词的条件匹配判断。

测试记录：

- 本地 `git diff --check` 通过。
- 使用服务器 PHP 对本地新代码执行语法检查通过：
  - `material_center_v1/adaptation_v2/index.php`
  - `material_center_v1/adaptation_v2/lib/foundation.php`
- 待上线后在真实页面打开芯片/电源/光学三类弹窗确认字段分区。

## 2026-08-01 单产品逻辑弹窗按业务分类再次细化

执行范围：

- 用户反馈芯片、电源、光学的设置逻辑弹窗仍然过于相似，光学应重点配置尺寸，芯片应重点配置电流/电压，电源应重点配置输出电流/电压和调光方式。
- 本次继续只修改产品适配 V2 `adaptation_v2` 与 `mc_pa2_` 逻辑，不修改旧版产品适配、不修改旧 BOM、不新增数据库表。

完成内容：

- 弹窗标题、提示语和头部色块按配置组类型自动切换为“芯片 / 光源”“电源 / 驱动”“光学 / 透镜”“通用配件 / 其他分类”。
- 芯片逻辑只保留品牌/系列、色温、显指、芯片工作电流和正向电压。
- 电源逻辑只保留电源类型、功率范围、输出电流、输出电压和调光方式。
- 光学逻辑新增外径/口径、厚度/高度、尺寸关键词，并保留光束角、光学类型、材质/表面和光学关键词。
- 通用配件逻辑新增规格/尺寸、材质、颜色/表面、用途/安装位置关键词，用于玻璃、蜂窝网、四叶片、光学膜、包装等还没有专门弹窗的分类。
- 后台保存时继续按类型净化字段，防止芯片保存电源字段、电源保存光学字段。
- 适配引擎增加光学尺寸结构化比较；候选物料没有结构化尺寸时降为条件确认，不直接误判不适配。
- 适配引擎增加通用配件关键词判断，候选说明未命中时提示人工确认。

测试记录：

- 本地 `git diff --check` 通过。
- 使用服务器 PHP 对本地新代码执行语法检查通过：
  - `material_center_v1/adaptation_v2/index.php`
  - `material_center_v1/adaptation_v2/lib/foundation.php`
- 待上线后在真实页面分别打开芯片、电源、光学和配件配置组，确认弹窗字段、保存回填和重新计算结果。

## 2026-08-05 单产品设置逻辑弹窗字段隐藏修复

执行范围：

- 用户反馈物料中心产品适配 V2 中“设置物料逻辑”时，光源、光学、电源弹窗仍然显示得一样。
- 本次只修复 V2 单产品工作台逻辑弹窗的前端分区显示，不修改旧版产品适配、不修改旧 BOM、不改数据库。

完成内容：

- 根因：JS 已经按 `chip / driver / optical / general` 给不相关字段加 `is-hidden` 并禁用，但 CSS 只隐藏 `.pa2-logic-zone.is-hidden` 分区标题，未隐藏普通字段 `label[data-logic-zone]`。
- 修复：CSS 增加 `[data-logic-zone].is-hidden{display:none!important}`，使芯片、电源、光学、通用配件字段真正按弹窗类型隐藏。
- 新增合同测试 `material_center_v1/tests/adaptation_v2_logic_dialog_contract.php`，要求所有逻辑字段都能按 `data-logic-zone` 隐藏，并确认服务器保存端仍按类型净化字段。

测试记录：

- 本地 `git diff --check` 通过。
- 服务器 `/tmp` 候选目录 PHP 语法检查通过：
  - `material_center_v1/adaptation_v2/index.php`
  - `material_center_v1/adaptation_v2/lib/foundation.php`
  - `material_center_v1/tests/adaptation_v2_logic_dialog_contract.php`
- 新增合同测试 `adaptation_v2_logic_dialog_contract.php` 通过。
- 正式服务器已同步提交 `2f1b6f08a42e94d72808b78746b6c66d473f08f4`；正式目录再次执行上述 PHP 语法检查和合同测试，均通过。

## 2026-08-01 单产品入口增加图片与图标/列表视图

执行范围：

- 用户反馈单产品配置工作台的产品选择页需要拉出产品图片，并支持图标视图与列表视图。
- 本次只修改产品适配 V2 页面展示，不修改旧版产品适配、不修改旧 BOM、不改数据库。

完成内容：

- “选择产品进入工作台”默认改为图标/卡片视图，产品卡片显示缩略图、型号、名称、V2 分类和系列。
- 增加“图标视图 / 列表视图”切换按钮，保留搜索关键词和当前视图。
- 列表视图新增产品缩略图列，不再只有纯文字。
- 产品图片读取既有 `image_url` 快照字段；无图或图片加载失败时显示统一占位图标。
- 卡片整块可点击进入单产品配置工作台，减少点小按钮的操作成本。

测试记录：

- 本地 `git diff --check` 通过。
- 使用服务器 PHP 对本地新代码执行语法检查通过：
  - `material_center_v1/adaptation_v2/index.php`
- 待上线后在真实页面验证图片加载、无图占位、视图切换、搜索后保持视图、点击卡片进入工作台。
