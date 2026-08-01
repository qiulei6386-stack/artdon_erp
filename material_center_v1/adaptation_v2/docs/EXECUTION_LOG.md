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
