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
