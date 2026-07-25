# Artdon Material Center Complete Shell V1

这是完整的物料中心整站 UI 外壳，不包含数据库写入和正式业务逻辑。

## 已完成

- 固定左侧菜单，所有页面可点击打开
- 工作台
- 全部物料
- 电源、芯片、光学、型材、接头、配件、包装工作台
- 产品适配三栏页面
- 供应商与价格
- 替代与版本
- 数据接入
- 文档与日志
- 设置中心
- 搜索、Tabs、表格、分页
- 筛选抽屉、批量设置抽屉、详情抽屉、新建弹窗
- 视图设置、主题、字体、颜色、密度和客户展示模式
- 所有按钮都有前端交互反馈

## 业务逻辑接入

Codex 后续将演示数据替换为真实 Repository / Service，并接入：

- BOM 只读数据源
- mc_* 正式物料表
- 广州统一账号权限
- 物料保存、审核、停用和归档
- 产品适配计算
- 供应商价格
- Excel 导入导出
- 文档上传

## 部署

建议先解压到预览目录测试：

```text
/www/wwwroot/Artdon/artdon_erp/material_center_preview_v1/
```

系统会根据当前目录自动识别 Base URL，因此可以重命名目录。

确认后再合并到：

```text
/www/wwwroot/Artdon/artdon_erp/material_center_v1/
```

建议 PHP 7.4+。
