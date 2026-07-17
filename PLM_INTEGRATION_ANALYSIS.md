# PLM 系统集成分析

## 1. PLM 系统概述

Artdon 的 PLM（Product Lifecycle Management）系统是一个专业的商业照明研发样品管理系统，用于管理产品从开发到测试的全生命周期。

## 2. PLM 数据库结构

### 2.1 核心表

| 表名 | 用途 | 关键字段 |
|------|------|--------|
| `plm_projects` | 项目管理 | id, name, customer, engineer, model_prefix, status, due_date, image_path |
| `plm_models` | 产品型号 | id, project_id, name, model, power, beam, cct, status, sample_no |
| `plm_tests` | 测试记录 | id, project_id, model_id, test_type, status, test_date, operator, result |
| `plm_test_files` | 测试文件 | id, project_id, model_id, test_id, category, file_name, file_path |
| `plm_files` | 项目文件 | id, project_id, model_id, test_id, category, file_name, file_path |
| `plm_flow_steps` | 工序流程 | id, project_id, step_name, status, operator, started_at, finished_at |

### 2.2 文件存储

- **存储位置**：`uploads/` 目录
- **文件类型**：
  - 产品图片（image_path）
  - 测试报告（PDF、Excel）
  - 设计文件（CAD、3D 模型）
  - 其他技术文档

## 3. PLM 与 CRM 的集成点

### 3.1 客户关联

- **PLM 项目** ↔ **CRM 客户**：通过 `customer` 字段关联
- **PLM 工程师** ↔ **CRM 用户**：通过 `engineer` 字段关联

### 3.2 文件转发

PLM 中的文件（图片、测试资料、设计文档等）可以在 CRM 中被检索并作为附件转发给客户，包括：

1. **邮件转发**：将 PLM 文件作为邮件附件发送给客户
2. **WhatsApp 转发**：将 PLM 文件通过 WhatsApp 发送给客户
3. **客户 360° 视图**：在客户详情页展示相关的 PLM 项目和文件

### 3.3 搜索集成

CRM 的 `plmFileSearch` 功能已经实现了对 PLM 文件的搜索，支持：

- 按文件名搜索
- 按型号搜索
- 按文件类型搜索（测试资料、产品图片等）

## 4. 重构中的 PLM 集成方案

### 4.1 后端集成 (`src/Services/PlmService.php`)

创建专门的 `PlmService` 类来处理 PLM 相关的业务逻辑：

```php
class PlmService {
    // 搜索 PLM 文件
    public function searchFiles(string $keyword, int $limit = 100): array
    
    // 获取项目的所有文件
    public function getProjectFiles(int $projectId): array
    
    // 获取型号的所有文件
    public function getModelFiles(int $modelId): array
    
    // 获取测试的所有文件
    public function getTestFiles(int $testId): array
    
    // 获取文件下载 URL
    public function getFileDownloadUrl(int $fileId): string
    
    // 获取项目详情（用于客户 360° 视图）
    public function getProjectDetails(int $projectId): array
}
```

### 4.2 前端集成

#### 4.2.1 PLM 文件搜索组件

在邮件撰写、WhatsApp 消息发送等界面中添加"选择 PLM 资料"功能：

```javascript
// 搜索 PLM 文件
async function searchPlmFiles(keyword) {
    return await crmApi.plmFileSearch(keyword);
}

// 显示搜索结果
function renderPlmSearchResults(files) {
    // 展示文件列表，支持选择
}

// 添加文件作为附件
function addPlmFileAsAttachment(fileId) {
    // 将 PLM 文件添加到邮件或 WhatsApp 消息的附件列表
}
```

#### 4.2.2 客户 360° 视图中的 PLM 项目

在客户详情页展示该客户相关的 PLM 项目：

```javascript
// 获取客户相关的 PLM 项目
async function getCustomerPlmProjects(customerId) {
    // 从 CRM 客户表中获取 customer 字段
    // 在 PLM 中搜索匹配的项目
}
```

### 4.3 API 端点

新增 API 端点用于 PLM 集成：

| 端点 | 方法 | 用途 |
|------|------|------|
| `/api/plm_file_search` | POST | 搜索 PLM 文件 |
| `/api/plm_project_files` | GET | 获取项目的所有文件 |
| `/api/plm_model_files` | GET | 获取型号的所有文件 |
| `/api/plm_file_download` | GET | 下载 PLM 文件 |
| `/api/customer_plm_projects` | GET | 获取客户相关的 PLM 项目 |

## 5. 实现步骤

### 第一阶段：后端集成

1. 创建 `PlmService` 类，实现 PLM 文件搜索和获取功能
2. 在 `CrmController` 中添加 PLM 相关的 API 端点
3. 实现文件下载和安全访问控制

### 第二阶段：前端集成

1. 在邮件撰写界面添加"选择 PLM 资料"按钮
2. 实现 PLM 文件搜索和选择功能
3. 在客户 360° 视图中展示相关的 PLM 项目

### 第三阶段：增强功能

1. 支持文件预览（图片、PDF）
2. 支持批量选择和转发
3. 记录文件转发日志

## 6. 安全考量

- **文件访问控制**：确保用户只能访问其有权限的文件
- **文件下载限制**：限制文件下载速率，防止滥用
- **病毒扫描**：对上传的文件进行病毒扫描
- **路径遍历防护**：防止用户通过路径遍历访问系统文件

## 7. 性能优化

- **缓存**：缓存常用的搜索结果
- **分页**：对搜索结果进行分页处理
- **索引**：在数据库中为常用的搜索字段创建索引
- **CDN**：对大文件使用 CDN 加速下载
