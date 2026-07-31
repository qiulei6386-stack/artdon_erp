# ARTDON 商业照明产品适配 V2
## 物料中心 × 商务中心 × 新加坡下单网站：总体架构与十阶段制作说明

**文件用途：** 本文件是 Codex 实施“产品适配 V2”的唯一主说明文件。  
**实施策略：** 旧版产品适配继续保留，不在旧代码上继续叠加修补；V2 在独立旁路目录和独立数据表中逐步完成，十个阶段逐步验收，全部通过后再切换原菜单入口。  
**目标：** 建立一套适合商业照明多品类、多系列、多渠道、多配置包的可配置产品规则系统。

---

# 0. Codex 开始前必须先读

Codex 在执行任何代码修改前，必须完整阅读本文件，并遵守以下原则：

1. 本项目不是普通页面改版，而是“产品配置规则引擎”重写。
2. 不继续修补现有 `adaptation/` 中已经反复修改的页面。
3. 旧版目录、旧表、旧数据暂时全部保留，只读或兼容使用。
4. V2 在独立目录中开发，未完成前不切换正式菜单。
5. 每次只执行当前阶段，不要跳跃开发后面的页面。
6. 每一阶段必须先完成、自测、修复、记录，再进入下一阶段。
7. 不允许页面看起来完成但使用静态演示数据冒充业务逻辑。
8. 不允许在 PHP 或 JavaScript 中写死所有产品分类和配置组。
9. 产品分类、配置组、配置选项、模板、规则、配置包必须数据化。
10. 新加坡下单网站不得直接读取全部物料，只读取已审批、已发布的渠道配置包。

---

# 1. 当前系统与 V2 开发位置

## 1.1 当前广州 ERP 根目录

```text
/www/wwwroot/Artdon/artdon_erp/
```

## 1.2 当前物料中心

```text
/www/wwwroot/Artdon/artdon_erp/material_center_v1/
```

## 1.3 当前旧版产品适配

```text
/www/wwwroot/Artdon/artdon_erp/material_center_v1/adaptation/
```

旧版暂时保留，用于：

- 对照现有数据；
- 保留历史配置；
- 保留旧 URL；
- 在 V2 开发期间继续查看；
- 最终迁移和兼容。

## 1.4 V2 建议开发目录

```text
/www/wwwroot/Artdon/artdon_erp/material_center_v1/adaptation_v2/
```

V2 测试入口：

```text
/material_center_v1/adaptation_v2/index.php
```

正式切换前，不修改左侧“产品适配”菜单目标地址。

## 1.5 数据库

```text
artdon_erp
```

V2 新表统一使用：

```text
mc_pa2_
```

不得继续用含义不清或与旧版冲突的表名。

---

# 2. 三个系统的业务边界

产品适配 V2 必须同时服务三个系统，但三个系统职责完全不同。

## 2.1 物料中心

物料中心是规则维护中心，负责：

- 产品分类；
- 产品系列；
- 配置组定义；
- 配置选项定义；
- 产品分类模板；
- 系列模板；
- 产品级差异；
- 正式物料过滤；
- 物料适配判断；
- 条件规则；
- 默认、候选、替代；
- 配置包；
- 渠道发布；
- 审批和版本。

物料中心回答：

```text
这个产品需要配置哪些内容？
每个内容从哪里选？
默认是什么？
什么条件下显示？
哪些组合不允许？
哪些需要审批？
最终可以发布成哪几套配置？
```

## 2.2 商务中心

商务中心不是规则维护中心。

商务中心只读取物料中心已经批准的：

- 产品配置结构；
- 可选正式物料；
- 默认物料；
- 允许替代；
- 价格影响；
- 交期影响；
- 审批要求；
- 已发布版本。

商务人员只在批准范围内选择，不允许修改底层适配规则。

## 2.3 新加坡下单网站

新加坡网站不是完整配置器，不允许客户自由组合所有物料。

网站只读取物料中心发布的“渠道配置包”。

例如：

```text
Standard
DALI
Premium
Ready Stock
Project Custom
```

每个配置包明确：

- 哪些物料完全锁定；
- 哪些选项允许选择；
- 哪些组合允许；
- 价格；
- MOQ；
- 库存；
- 交期；
- 生效时间；
- 渠道；
- 发布版本。

---

# 3. V2 的核心设计原则

以下四条是本项目最重要的设计原则：

## 3.1 模板定义配置结构

产品分类模板决定：

- 需要哪些配置组；
- 配置组顺序；
- 必选或可选；
- 单选或多选；
- 数据来源；
- 默认规则；
- 条件规则。

## 3.2 规则决定显示、允许和冲突

规则引擎决定：

- 某配置组是否显示；
- 某配置组是否必选；
- 哪些物料允许出现；
- 哪些组合禁止；
- 是否需要审批；
- 是否影响价格和交期。

## 3.3 具体产品只保存差异

大多数产品不应从零配置。

具体产品继承：

```text
系统通用模板
→ 产品分类模板
→ 产品系列模板
→ 具体产品差异
```

具体产品只保存与上级模板不同的部分。

## 3.4 渠道只读取发布配置包

商务中心和新加坡网站不得直接读取草稿模板或全部正式物料。

只读取：

```text
已审批
已发布
当前有效
目标渠道匹配
```

的配置包版本。

---

# 4. 关键概念和统一命名

旧版最容易混乱的原因之一，是“分类、配置组、选项、物料”混在一起。本项目必须统一术语。

## 4.1 产品分类 Product Category

代表产品业务类别：

- 导轨灯；
- 嵌入式灯具；
- 磁吸式灯具；
- 明装式灯具；
- 吊灯；
- 线性灯；
- 灯带；
- 户外灯；
- 柜体灯；
- 电源；
- 配件；
- 自定义类别。

产品分类不是物料分类。

## 4.2 产品系列 Product Series

例如：

- LANKY；
- ARTAX；
- REDLINE；
- INTERO；
- 普通导轨系列；
- INTRACK 系列；
- 20mm 磁吸系列；
- 35mm 线性系列。

## 4.3 配置组 Configuration Group

代表一个产品需要配置的业务项目：

- 芯片 / 光源；
- 电源 / 驱动；
- 外置电源；
- INTRACK 电源；
- 光学 / 透镜；
- 普通导轨接头；
- INTRACK 接头；
- 磁吸头；
- 灯体长度；
- 型材；
- 灯带；
- 扩散罩；
- 吊线；
- 端盖；
- 安装方式；
- 外观颜色；
- 特殊要求。

配置组必须可新增，不允许写死。

## 4.4 配置组类型 Group Type

至少支持：

### A. 物料选择组 material_select

选项来自正式物料库。

### B. 属性选择组 enum_select

选项来自自定义字典，不一定对应物料。

### C. 混合选择组 hybrid_select

先选择属性，再筛选正式物料。

### D. 数值输入组 number_input

例如：

- 灯体长度；
- 开孔尺寸；
- 灯带长度；
- 功率；
- 数量。

### E. 文本输入组 text_input

例如特殊要求。

### F. 布尔开关组 boolean

例如：

- 是否需要应急；
- 是否防水；
- 是否客户可选。

## 4.5 配置选项 Configuration Option

配置组选项可以是：

- 一条正式物料；
- 一个属性值；
- 一个数值范围；
- 一个文本值；
- 一个布尔值。

## 4.6 产品配置 Product Configuration

某个具体产品在某个草稿或发布版本中的配置结果。

## 4.7 配置包 Configuration Package

面向渠道、客户或库存场景发布的一套可下单组合。
---

# 5. 商业照明分类模板示例

以下示例不是写死代码，而是首批数据库种子模板。管理员以后必须可以自行修改。

## 5.1 导轨灯模板

建议配置组：

1. 芯片 / 光源；
2. 光学 / 透镜；
3. 接头系统；
4. 接头线制；
5. 普通导轨接头；
6. INTRACK 接头；
7. 普通内置电源；
8. INTRACK 电源；
9. 调光方式；
10. 蜂巢网；
11. 玻璃；
12. 附件；
13. 外观颜色；
14. 特殊要求。

规则：

```text
接头系统 = 普通导轨
→ 显示普通导轨接头
→ 显示普通内置电源
→ 隐藏 INTRACK 接头
→ 隐藏 INTRACK 电源
```

```text
接头系统 = INTRACK
→ 显示 INTRACK 接头
→ 显示 INTRACK 电源
→ 显示接头线制
→ 隐藏普通导轨接头
→ 隐藏普通内置电源
```

## 5.2 嵌入式灯具模板

建议配置组：

1. 芯片 / 光源；
2. 光学 / 透镜；
3. 外置电源；
4. 安装弹簧；
5. 开孔尺寸；
6. 调光方式；
7. 蜂巢网；
8. 玻璃；
9. 防眩附件；
10. 面环颜色；
11. 特殊要求。

特点：

- 默认不显示导轨接头；
- 外置电源为核心物料；
- 蜂巢网和玻璃通常为可选；
- 开孔尺寸可来自产品资料或数值组。

## 5.3 磁吸式灯具模板

建议配置组：

1. 磁吸头；
2. 磁吸式电源；
3. 芯片 / 光源；
4. 光学；
5. 灯体长度；
6. 磁吸安装形式；
7. 调光方式；
8. 外观颜色；
9. 特殊要求。

属性选项：

```text
灯体长度：
- 短款
- 长款
```

规则：

```text
灯体长度 = 短款
→ 只显示短款灯体和兼容磁吸头
→ 只显示短款允许的芯片、光学和电源
```

```text
灯体长度 = 长款
→ 显示长款灯体和对应物料
```

磁吸灯默认不建立蜂巢网、玻璃等组，除非具体系列需要。

## 5.4 明装式灯具模板

建议配置组：

- 芯片；
- 光学；
- 内置电源；
- 灯体尺寸；
- 安装底盘；
- 调光；
- 外观颜色；
- 玻璃 / 扩散罩；
- 特殊要求。

## 5.5 线性灯模板

建议配置组：

- 型材；
- 灯体长度；
- 灯板 / 灯带；
- 电源；
- 扩散罩；
- 端盖；
- 连接件；
- 吊线；
- 安装件；
- 调光控制；
- 外观颜色。

## 5.6 灯带模板

建议配置组：

- 灯带类型；
- 输入电压；
- 功率 / 米；
- 色温；
- CRI；
- 防水等级；
- 剪切长度；
- 型材；
- 扩散罩；
- 电源；
- 控制器；
- 连接线；
- 接头。

## 5.7 电源与配件模板

电源或独立配件产品可采用简化模板：

- 电气规格；
- 安装方式；
- 认证；
- 防护等级；
- 尺寸；
- 颜色；
- 配套附件；
- 包装；
- 渠道配置包。

---

# 6. 模板继承和覆盖算法

模板必须有明确继承关系。

## 6.1 模板层级

```text
系统通用模板
→ 产品分类模板
→ 产品系列模板
→ 具体产品模板
→ 渠道配置包
```

## 6.2 合并键

配置组必须通过稳定的 `group_code` 合并，不通过中文名称合并。

例如：

```text
chip
optical
track_system
track_wire
intrack_connector
intrack_driver
magnetic_head
body_length
```

## 6.3 继承规则

子模板可以：

- 继承配置组；
- 覆盖配置组规则；
- 禁用父模板配置组；
- 增加配置组；
- 修改顺序；
- 修改数据来源过滤；
- 修改显示条件；
- 修改默认项。

子模板不能直接修改父模板记录。

## 6.4 差异保存

具体产品只保存：

- 新增组；
- 禁用组；
- 覆盖字段；
- 覆盖规则；
- 选项差异；
- 产品级默认。

## 6.5 预览

套用模板前必须显示：

- 最终有效配置组；
- 继承来源；
- 当前层新增；
- 当前层覆盖；
- 被禁用项；
- 可能冲突。

---

# 7. 规则引擎

不得使用 `eval()`、任意 PHP 表达式、任意 JavaScript 或 SQL 作为规则。

## 7.1 条件来源

规则条件可以引用：

- 产品字段；
- 产品分类；
- 产品系列；
- 产品技术范围；
- 其他配置组选择；
- 正式物料字段；
- 客户要求；
- 渠道；
- 库存状态；
- 质保要求；
- 环境要求。

## 7.2 运算符

至少支持：

```text
=
!=
>
>=
<
<=
IN
NOT IN
BETWEEN
CONTAINS
NOT CONTAINS
EXISTS
NOT EXISTS
```

## 7.3 逻辑关系

支持：

- AND；
- OR；
- 条件组嵌套。

## 7.4 动作

规则动作至少支持：

- 显示配置组；
- 隐藏配置组；
- 设为必选；
- 设为可选；
- 设为不适用；
- 限制物料候选；
- 限制属性选项；
- 设置默认项；
- 清空不兼容选择；
- 要求审批；
- 添加警告；
- 阻止发布；
- 增加价格；
- 增加交期。

## 7.5 执行顺序

推荐顺序：

```text
读取模板继承结果
→ 应用产品级覆盖
→ 应用渠道配置包覆盖
→ 应用当前用户选择
→ 执行显示规则
→ 执行候选过滤
→ 执行适配检查
→ 生成警告、冲突和审批项
```

## 7.6 循环检测

必须检测：

- A 显示 B，B 又控制 A；
- A 默认 B，B 默认 A；
- 替代关系循环；
- 条件依赖死循环。
---

# 8. 物料适配判断

物料选择组读取正式物料库。

## 8.1 通用正式物料限制

默认只允许：

```text
status = formal
approval_status = approved
is_formal = 1
is_disabled = 0
is_archived = 0
```

## 8.2 电源适配

判断：

- 产品功率范围；
- 芯片电流；
- 芯片电压；
- 输出类型；
- 输出电压；
- 电源尺寸；
- 安装方式；
- 调光；
- 防护等级；
- 认证；
- 供应商质保。

## 8.3 芯片适配

判断：

- 功率；
- 电流；
- 电压；
- LES；
- 尺寸；
- 色温；
- CRI；
- 光通量；
- 光效；
- 散热要求；
- 光学兼容。

## 8.4 光学适配

判断：

- 光学类型；
- LES；
- 直径；
- 高度；
- 光束角；
- 光型；
- 安装结构；
- 灯体空间。

## 8.5 接头和结构件

判断：

- 系统类型；
- 线制；
- 接口；
- 电气规格；
- 安装方式；
- 颜色；
- 尺寸；
- 承重；
- 防护等级。

## 8.6 结果等级

统一为：

- `perfect`：完全适配；
- `conditional`：条件适配；
- `approval_required`：需要审批；
- `incompatible`：不适配。

每个结果必须返回：

- 结论；
- 匹配度；
- 匹配字段；
- 冲突字段；
- 原因；
- 建议操作。

---

# 9. 配置包和渠道发布

## 9.1 配置包类型

建议首批支持：

- 商务中心灵活包；
- 新加坡 Standard；
- 新加坡 DALI；
- 新加坡 Premium；
- 新加坡 Ready Stock；
- 指定客户包；
- 项目定制包。

## 9.2 锁定模式

每个配置组在配置包中可以设置：

- 完全锁定；
- 允许指定选项；
- 允许在批准范围选择；
- 不对外显示；
- 仅内部使用。

## 9.3 配置包字段

- 包名称；
- 包编码；
- 产品；
- 渠道；
- 币种；
- MOQ；
- 库存；
- 交期；
- 默认选项；
- 允许选项；
- 锁定选项；
- 价格影响；
- 生效时间；
- 失效时间；
- 审批状态；
- 发布状态；
- 版本。

## 9.4 新加坡 Ready Stock

Ready Stock 必须：

- 所有关键物料锁定；
- 只允许库存范围；
- 读取实时或同步库存；
- 显示明确交期；
- 禁止客户替换关键物料；
- 订单保存配置包版本快照。

## 9.5 订单快照

新加坡网站或商务中心下单时，必须保存：

- 产品配置版本；
- 配置包版本；
- 最终选项；
- 单价；
- 价格规则；
- 交期；
- 库存；
- 渠道；
- 时间。

后续模板或包更新不得改变历史订单。

---

# 10. V2 页面地图和路由

V2 使用统一入口：

```text
/adaptation_v2/index.php
```

## 10.1 首页

```text
/adaptation_v2/index.php?view=home
```

显示：

- 最近工作；
- 未配置；
- 配置中；
- 待检查；
- 待审批；
- 已发布；
- 有冲突；
- 快速入口。

## 10.2 全部产品

```text
/adaptation_v2/index.php?view=products
```

表格查看产品配置状态。

## 10.3 产品分类中心

```text
/adaptation_v2/index.php?view=categories
```

维护：

- 产品分类；
- 父子分类；
- 分类编码；
- 分类说明；
- 默认模板；
- 启停状态；
- 产品数量。

## 10.4 配置组定义中心

```text
/adaptation_v2/index.php?view=groups
```

维护系统内置和自定义配置组。

## 10.5 模板中心

```text
/adaptation_v2/index.php?view=templates
```

查看：

- 通用模板；
- 分类模板；
- 系列模板；
- 产品模板；
- 使用范围；
- 版本；
- 状态。

## 10.6 模板编辑器

```text
/adaptation_v2/index.php?view=template_editor&template_id=123
```

编辑模板结构和规则。

## 10.7 单产品配置工作台

```text
/adaptation_v2/index.php?view=workspace&product_id=100
```

默认快速模式：

1. 配置来源；
2. 核心配置；
3. 检查和保存。

高级设置：

- 技术范围；
- 扩展选项；
- 条件规则；
- 替代；
- 审批；
- 版本。

## 10.8 配置包中心

```text
/adaptation_v2/index.php?view=packages&product_id=100
```

## 10.9 渠道发布

```text
/adaptation_v2/index.php?view=publish
```

## 10.10 审批中心

```text
/adaptation_v2/index.php?view=approvals
```

## 10.11 日志与版本

```text
/adaptation_v2/index.php?view=logs
```

---

# 11. 界面设计要求

## 11.1 全局

- 保持物料中心现有左侧菜单和顶部栏；
- 白色、浅灰、青绿色；
- 红色仅用于危险和阻断；
- 普通主按钮使用青绿色；
- 1440×900 和 1920×1080 均可用；
- 主操作尽量一屏完成；
- 复杂选择使用宽版弹窗；
- 不长期保留空白右栏；
- 表单较长时抽屉内部滚动。

## 11.2 模板编辑器

推荐三段式：

### 左侧：模板 / 分类导航

宽度约 260px：

- 产品分类；
- 系列；
- 模板列表；
- 搜索；
- 新建模板。

### 中间：配置组结构

显示当前模板配置组：

- 拖动排序；
- 类型；
- 必选 / 可选；
- 单选 / 多选；
- 来源；
- 规则数量；
- 启停；
- 新增配置组。

### 右侧：配置组规则

只有点击中间配置组后打开。

Tab：

- 基本设置；
- 数据来源；
- 选项管理；
- 显示条件；
- 默认设置；
- 价格 / 交期；
- 审批设置。

## 11.3 单产品配置工作台

默认快速模式一屏显示：

- 产品摘要；
- 配置来源；
- 核心配置；
- 需要补充；
- 检查摘要；
- 保存 / 提交。

复杂物料选择使用宽版表格弹窗。

## 11.4 配置包页面

按产品显示：

- 包名称；
- 渠道；
- 锁定程度；
- 允许选项；
- 价格；
- MOQ；
- 库存；
- 交期；
- 状态；
- 版本；
- 发布操作。
---

# 12. 数据库结构

以下为建议表结构，Codex 可按现有数据库规范调整字段类型，但不得削弱业务能力。

## 12.1 产品分类

```text
mc_pa2_product_categories
```

字段：

- id；
- category_code；
- category_name；
- parent_id；
- description；
- default_template_id；
- sort_order；
- is_enabled；
- created_by；
- updated_by；
- created_at；
- updated_at。

## 12.2 产品分类映射

```text
mc_pa2_product_category_mappings
```

字段：

- id；
- product_id；
- category_id；
- series_code；
- source_type；
- confidence；
- confirmed_by；
- confirmed_at。

## 12.3 配置组定义

```text
mc_pa2_group_definitions
```

字段：

- id；
- group_code；
- group_name；
- group_type；
- icon；
- description；
- is_system；
- is_enabled；
- created_by；
- updated_by；
- created_at；
- updated_at。

## 12.4 配置组选项字典

```text
mc_pa2_group_option_definitions
```

字段：

- id；
- group_definition_id；
- option_code；
- option_name；
- option_image；
- description；
- is_default；
- is_enabled；
- sort_order；
- price_effect_json；
- lead_time_effect_json；
- settings_json。

## 12.5 配置组物料过滤器

```text
mc_pa2_group_material_filters
```

字段：

- id；
- group_definition_id；
- material_category；
- filter_field；
- operator；
- filter_value_json；
- is_required；
- sort_order。

## 12.6 模板主表

```text
mc_pa2_templates
```

字段：

- id；
- template_code；
- template_name；
- template_level；
- scope_type；
- product_category_id；
- series_code；
- product_id；
- parent_template_id；
- active_version_id；
- status；
- is_enabled；
- created_by；
- updated_by；
- created_at；
- updated_at。

## 12.7 模板版本

```text
mc_pa2_template_versions
```

字段：

- id；
- template_id；
- version_no；
- status；
- snapshot_json；
- created_by；
- approved_by；
- published_by；
- created_at；
- approved_at；
- published_at。

## 12.8 模板配置组

```text
mc_pa2_template_groups
```

字段：

- id；
- template_version_id；
- group_definition_id；
- group_code；
- group_name_override；
- group_type_override；
- is_required；
- selection_mode；
- allow_empty；
- min_select；
- max_select；
- allow_default；
- customer_selectable；
- affects_price；
- affects_lead_time；
- requires_approval；
- sort_order；
- is_enabled；
- inheritance_action；
- settings_json。

## 12.9 模板配置组规则

```text
mc_pa2_template_group_rules
```

字段：

- id；
- template_group_id；
- rule_name；
- priority；
- condition_logic；
- is_enabled；
- stop_on_match；
- created_by；
- updated_by。

## 12.10 规则条件

```text
mc_pa2_rule_conditions
```

字段：

- id；
- rule_id；
- condition_group；
- left_source_type；
- left_source_key；
- operator；
- right_value_json；
- sort_order。

## 12.11 规则动作

```text
mc_pa2_rule_actions
```

字段：

- id；
- rule_id；
- action_type；
- target_group_code；
- action_value_json；
- sort_order。

## 12.12 产品配置主表

```text
mc_pa2_product_configs
```

字段：

- id；
- product_id；
- product_category_id；
- source_template_id；
- active_draft_version_id；
- active_published_version_id；
- status；
- owner_user_id；
- created_at；
- updated_at。

## 12.13 产品配置版本

```text
mc_pa2_product_config_versions
```

字段：

- id；
- product_config_id；
- version_no；
- source_template_version_id；
- status；
- configuration_snapshot_json；
- technical_range_json；
- created_by；
- submitted_by；
- approved_by；
- published_by；
- created_at；
- submitted_at；
- approved_at；
- published_at。

## 12.14 产品配置组

```text
mc_pa2_product_group_configs
```

字段：

- id；
- product_config_version_id；
- group_code；
- group_definition_id；
- effective_settings_json；
- status；
- is_overridden；
- override_source；
- sort_order。

## 12.15 产品选项

```text
mc_pa2_product_selected_options
```

字段：

- id；
- product_group_config_id；
- option_type；
- material_id；
- option_definition_id；
- numeric_value；
- text_value；
- boolean_value；
- is_default；
- option_role；
- condition_json；
- approval_status；
- price_effect_json；
- lead_time_effect_json；
- sort_order。

## 12.16 适配结果缓存

```text
mc_pa2_fit_results
```

字段：

- id；
- product_config_version_id；
- group_code；
- candidate_material_id；
- fit_level；
- fit_score；
- matched_fields_json；
- conflict_fields_json；
- reasons_json；
- calculated_at；
- calculation_version。

## 12.17 配置包

```text
mc_pa2_packages
```

字段：

- id；
- package_code；
- package_name；
- product_id；
- channel_code；
- package_type；
- active_version_id；
- status；
- is_enabled；
- valid_from；
- valid_to；
- created_by；
- updated_by。

## 12.18 配置包版本

```text
mc_pa2_package_versions
```

字段：

- id；
- package_id；
- version_no；
- source_product_config_version_id；
- currency；
- moq；
- stock_mode；
- lead_time_days；
- price_json；
- snapshot_json；
- status；
- approved_by；
- published_by；
- approved_at；
- published_at。

## 12.19 配置包配置组

```text
mc_pa2_package_groups
```

字段：

- id；
- package_version_id；
- group_code；
- visibility；
- lock_mode；
- is_required；
- min_select；
- max_select；
- default_option_reference；
- sort_order。

## 12.20 配置包选项

```text
mc_pa2_package_options
```

字段：

- id；
- package_group_id；
- product_selected_option_id；
- is_allowed；
- is_default；
- is_locked；
- price_effect_json；
- lead_time_effect_json；
- inventory_reference_json。

## 12.21 渠道发布

```text
mc_pa2_channel_publications
```

字段：

- id；
- channel_code；
- product_id；
- package_version_id；
- publication_status；
- payload_snapshot_json；
- published_at；
- unpublished_at；
- published_by。

## 12.22 审批和日志

```text
mc_pa2_approvals
mc_pa2_approval_logs
mc_pa2_operation_logs
mc_pa2_change_logs
```
---

# 13. API 规范

接口统一返回：

```json
{
  "success": true,
  "message": "操作成功",
  "data": {},
  "errors": [],
  "request_id": "..."
}
```

## 13.1 分类

```text
GET  /api/categories
POST /api/categories
PUT  /api/categories/{id}
```

## 13.2 配置组定义

```text
GET  /api/group-definitions
POST /api/group-definitions
PUT  /api/group-definitions/{id}
```

## 13.3 模板

```text
GET  /api/templates
POST /api/templates
GET  /api/templates/{id}
POST /api/templates/{id}/versions
POST /api/templates/{id}/preview
POST /api/templates/{id}/publish
```

## 13.4 产品配置

```text
GET  /api/products/{productId}/configuration
POST /api/products/{productId}/initialize
PUT  /api/product-config-versions/{versionId}
POST /api/product-config-versions/{versionId}/check
POST /api/product-config-versions/{versionId}/submit
POST /api/product-config-versions/{versionId}/publish
```

## 13.5 候选物料

```text
GET /api/product-config-versions/{versionId}/groups/{groupCode}/candidates
```

返回：

- 正式候选；
- 匹配等级；
- 匹配度；
- 冲突原因；
- 是否已选；
- 是否默认；
- 是否需要审批。

## 13.6 配置包

```text
GET  /api/products/{productId}/packages
POST /api/products/{productId}/packages
POST /api/packages/{packageId}/versions
POST /api/package-versions/{versionId}/preview
POST /api/package-versions/{versionId}/publish
```

## 13.7 渠道读取接口

商务中心：

```text
GET /api/channels/commercial/products/{productId}/packages
```

新加坡网站：

```text
GET /api/channels/singapore/products/{productId}/packages
GET /api/channels/singapore/packages/{packageVersionId}
```

渠道接口只返回发布版本，不返回草稿或内部敏感字段。

---

# 14. 权限

至少支持：

```text
adaptation_v2.view
adaptation_v2.manage_category
adaptation_v2.manage_group_definition
adaptation_v2.manage_template
adaptation_v2.publish_template
adaptation_v2.configure_product
adaptation_v2.override_product
adaptation_v2.select_material
adaptation_v2.override_conflict
adaptation_v2.manage_rule
adaptation_v2.manage_package
adaptation_v2.approve
adaptation_v2.publish
adaptation_v2.view_price
adaptation_v2.manage_channel
adaptation_v2.view_log
```

角色建议：

- 超级管理员；
- 物料管理员；
- 工程；
- 采购；
- 商务；
- 审批人；
- 渠道管理员；
- 只读用户。

服务端必须校验权限，不能只隐藏前端按钮。
---

# 15. 十个实施阶段

以下十个阶段必须严格按顺序执行。每个阶段完成后，Codex 必须生成阶段记录，并暂停等待验收或明确继续指令。不得跳过。

---

## 第 1 阶段：冻结旧版、审计和 V2 蓝图落地

### 目标

确认旧版状态，建立旁路目录、备份、文档和路由框架，不开发业务页面。

### 必做

1. 备份旧 `adaptation/`；
2. 备份所有旧产品适配相关 `mc_*` 表；
3. 扫描旧页面、接口、表和字段；
4. 列出旧功能清单；
5. 建立 `adaptation_v2/`；
6. 建立统一 Layout 接入；
7. 建立统一 API 响应；
8. 建立数据库迁移目录；
9. 建立执行日志；
10. 建立 V2 入口空页面。

### 输出文件

```text
adaptation_v2/docs/01_CURRENT_AUDIT.md
adaptation_v2/docs/01_ROUTE_MAP.md
adaptation_v2/docs/01_DATABASE_AUDIT.md
adaptation_v2/docs/EXECUTION_LOG.md
```

### 验收

- 旧版仍正常；
- V2 首页 HTTP 200；
- V2 使用现有左侧菜单和顶部布局；
- 没有新业务表写入；
- 没有修改旧 BOM。

---

## 第 2 阶段：基础数据模型和产品分类中心

### 目标

完成产品分类、产品映射和配置组定义基础。

### 必做

1. 创建分类相关表；
2. 创建配置组定义表；
3. 创建属性选项定义表；
4. 建立产品分类中心页面；
5. 建立配置组定义中心页面；
6. 支持新增、编辑、启停、排序；
7. 支持父子分类；
8. 支持产品分类映射；
9. 接入统一权限；
10. 写日志。

### 首批分类种子

- 导轨灯；
- 嵌入式；
- 磁吸式；
- 明装式；
- 线性；
- 灯带；
- 户外；
- 柜体灯；
- 电源；
- 配件。

### 验收

- 管理员可以新增产品分类；
- 管理员可以新增自定义配置组；
- 新增配置组不需要修改 PHP 固定代码；
- 分类和配置组可启停；
- 旧系统不受影响。

---

## 第 3 阶段：模板中心和继承引擎

### 目标

完成通用、分类、系列和产品模板。

### 必做

1. 建立模板表和版本表；
2. 建立模板配置组表；
3. 实现父模板继承；
4. 实现配置组覆盖和禁用；
5. 实现差异预览；
6. 建立模板列表；
7. 建立模板编辑器；
8. 支持拖动排序；
9. 支持发布模板版本；
10. 实现模板引用检查。

### 首批模板

- 系统通用模板；
- 导轨灯模板；
- 嵌入式模板；
- 磁吸式模板。

### 验收

- 模板可以继承；
- 子模板不修改父模板；
- 套用前显示差异；
- 可以新增自定义配置组；
- 模板版本可追溯。

---

## 第 4 阶段：配置组选项、物料来源和规则编辑器

### 目标

让配置组真正可配置。

### 必做

1. 物料选择组；
2. 属性选择组；
3. 混合选择组；
4. 数值组；
5. 文本组；
6. 物料过滤器；
7. 属性选项管理；
8. 显示条件；
9. 必选 / 可选；
10. 单选 / 多选；
11. 选择数量限制；
12. 默认项规则；
13. 可视化条件编辑器；
14. 规则循环检测。

### 验收示例

导轨灯选择 INTRACK 后：

- 显示 INTRACK 接头；
- 显示 INTRACK 电源；
- 隐藏普通接头；
- 隐藏普通内置电源。

磁吸灯选择短款后：

- 只显示短款相关物料。

---

## 第 5 阶段：单产品配置工作台

### 目标

建立简单、可用、模板驱动的产品配置页面。

### 默认流程

```text
确认配置来源
→ 设置核心配置
→ 检查和保存
```

### 必做

1. 产品摘要；
2. 模板来源；
3. 模板继承结果；
4. 核心配置；
5. 动态配置组；
6. 需要补充；
7. 宽版物料选择弹窗；
8. 选项默认、候选、替代；
9. 保存草稿；
10. 配置检查摘要；
11. 高级设置入口；
12. 一屏主要操作。

### 验收

- 导轨灯、嵌入式、磁吸式显示不同配置组；
- 新增自定义配置组能出现在工作台；
- 没有空白右栏；
- 普通配置不需要填写所有字段；
- 缺什么补什么。

---

## 第 6 阶段：适配计算和冲突引擎

### 目标

候选物料有真实适配结论。

### 必做

1. 电源适配；
2. 芯片适配；
3. 光学适配；
4. 接头适配；
5. 配件适配；
6. 技术范围；
7. 匹配度；
8. 冲突原因；
9. 条件适配；
10. 例外审批；
11. 适配结果缓存；
12. 重新计算机制。

### 验收

每条候选必须显示：

- 完全适配；
- 条件适配；
- 需要审批；
- 不适配；
- 明确原因。

---

## 第 7 阶段：产品差异、审批和版本

### 目标

具体产品只保存差异，配置可审批和发布。

### 必做

1. 产品配置版本；
2. 草稿；
3. 提交；
4. 审批；
5. 驳回；
6. 发布；
7. 产品级覆盖；
8. 版本比较；
9. 历史快照；
10. 审批日志；
11. 旧发布版本保护；
12. 回滚。

### 验收

- 发布 V1 后修改产生 V2 草稿；
- 历史订单仍引用 V1；
- 产品级覆盖不修改模板；
- 审批记录完整。

---

## 第 8 阶段：配置包中心

### 目标

建立商务中心和新加坡网站可使用的配置包。

### 必做

1. 配置包主表；
2. 配置包版本；
3. 配置包配置组；
4. 配置包选项；
5. 锁定模式；
6. 允许范围；
7. 默认项；
8. 价格；
9. MOQ；
10. 库存；
11. 交期；
12. 有效期；
13. 配置包预览；
14. 发布。

### 首批测试包

- Commercial Flexible；
- Singapore Standard；
- Singapore DALI；
- Singapore Ready Stock。

### 验收

- Ready Stock 关键物料全部锁定；
- Standard 仅开放指定光学和颜色；
- DALI 固定 DALI 电源；
- 包版本可追溯。

---

## 第 9 阶段：商务中心和新加坡接口

### 目标

打通两个下游系统。

### 商务中心

读取：

- 已发布配置结构；
- 默认；
- 可选；
- 价格影响；
- 交期影响；
- 审批要求。

### 新加坡网站

读取：

- 已发布配置包；
- 锁定项；
- 允许项；
- 库存；
- 价格；
- MOQ；
- 交期。

### 必做

1. 渠道 API；
2. 权限 / 签名；
3. 缓存；
4. 版本号；
5. 失效处理；
6. 订单快照；
7. 商务中心测试；
8. 新加坡站测试；
9. 错误回退；
10. 日志。

### 验收

- 下游不能读取草稿；
- 下游不能越过包规则；
- 订单保存版本快照；
- 发布更新不改变历史订单。

---

## 第 10 阶段：迁移、全量测试和切换

### 目标

迁移旧数据，完成全站验收并安全切换。

### 必做

1. 旧数据映射；
2. 模板映射；
3. 配置组选项迁移；
4. 历史审批保留；
5. 旧 URL 兼容；
6. 性能测试；
7. 权限测试；
8. 安全测试；
9. 业务验收；
10. 回滚方案；
11. 菜单切换；
12. 旧版只读保留。

### 切换条件

只有以下全部通过才能切换：

- 导轨灯完整流程；
- 嵌入式完整流程；
- 磁吸灯完整流程；
- 商务中心读取；
- 新加坡配置包读取；
- 审批发布；
- 版本快照；
- 回滚演练。
---

# 16. Codex 每阶段执行纪律

每阶段必须：

1. 先读取本文件；
2. 只执行本阶段；
3. 开始前备份；
4. 完成数据库迁移；
5. 完成功能；
6. 完成 UI；
7. 完成 API；
8. 完成权限；
9. 完成日志；
10. 完成自动或手工测试；
11. 修复全部阻断错误；
12. 更新 `EXECUTION_LOG.md`；
13. 输出本阶段修改文件；
14. 输出本阶段数据库变化；
15. 输出本阶段测试结果；
16. 停止，等待验收或下一阶段指令。

不得：

- 未完成当前阶段就进入下一阶段；
- 用静态数据冒充完成；
- 修改旧 BOM；
- 切换正式菜单；
- 删除旧版；
- 建立第二套账号；
- 写死配置分类和组；
- 把所有功能塞进一个页面；
- 用红色作为普通按钮；
- 让重要流程依赖整页长滚动。

---

# 17. 最终验收清单

## 数据架构

- [ ] 产品分类数据化
- [ ] 配置组数据化
- [ ] 配置选项数据化
- [ ] 模板继承
- [ ] 产品差异
- [ ] 规则引擎
- [ ] 配置包
- [ ] 渠道发布
- [ ] 版本快照
- [ ] 操作日志

## 产品类别

- [ ] 导轨灯
- [ ] 嵌入式
- [ ] 磁吸式
- [ ] 明装式
- [ ] 线性灯
- [ ] 灯带
- [ ] 电源 / 配件
- [ ] 自定义类别

## 工作台

- [ ] 模板自动套用
- [ ] 具体产品只保存差异
- [ ] 动态配置组
- [ ] 宽版物料比较
- [ ] 缺什么补什么
- [ ] 适配结果明确
- [ ] 一屏主要操作
- [ ] 高级能力按需打开

## 下游

- [ ] 商务中心只读已发布配置
- [ ] 新加坡网站只读配置包
- [ ] Ready Stock 锁定
- [ ] Standard / DALI / Premium
- [ ] 订单保存版本快照

## 安全和运维

- [ ] 统一账号
- [ ] 服务端权限
- [ ] CSRF
- [ ] SQL 注入防护
- [ ] XSS 防护
- [ ] 迁移可重跑
- [ ] 回滚可执行
- [ ] PHP 无 Warning / Notice / Fatal
- [ ] 控制台无阻断错误
- [ ] 旧版可回退

---

# 18. 发给 Codex 的启动指令

将本文件放入：

```text
/www/wwwroot/Artdon/artdon_erp/material_center_v1/docs/
```

文件名：

```text
ARTDON_PRODUCT_ADAPTATION_V2_MASTER_IMPLEMENTATION_SPEC.md
```

然后发给 Codex：

```text
请完整阅读：

/www/wwwroot/Artdon/artdon_erp/material_center_v1/docs/ARTDON_PRODUCT_ADAPTATION_V2_MASTER_IMPLEMENTATION_SPEC.md

这是产品适配 V2 的唯一主说明文件。

当前只执行“第 1 阶段：冻结旧版、审计和 V2 蓝图落地”。

要求：

1. 不修改旧版产品适配业务。
2. 不修改旧 BOM。
3. 不切换正式菜单。
4. V2 使用独立目录 adaptation_v2。
5. V2 新表使用 mc_pa2_ 前缀。
6. 完成本阶段全部任务和测试。
7. 更新执行日志和审计文档。
8. 完成后停止，不进入第 2 阶段，等待验收。
```

---

# 19. 完成定义

本项目不是“页面能打开”就算完成。

最终必须形成：

```text
产品分类中心
+ 配置组定义中心
+ 模板继承中心
+ 规则引擎
+ 单产品配置
+ 适配计算
+ 产品差异
+ 审批版本
+ 配置包
+ 商务中心接口
+ 新加坡发布接口
```

最终系统必须做到：

> 产品类别可以无限扩展。

> 配置组可以完全自定义。

> 大多数产品通过模板继承，不需要逐个重做。

> 商务中心只在批准范围内报价。

> 新加坡网站只显示锁定的发布配置包。

> 旧版可以安全回退。
