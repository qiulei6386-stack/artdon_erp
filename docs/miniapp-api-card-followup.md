# 小程序 API：名片 / 多联系人 / 跟进扩展

鉴权与现有接口一致：`token` 请求体字段或 `X-Token` 请求头。响应格式：`{ success, message, data }`。

字段名与前端 [yada-crm-miniprogram](https://github.com/qiulei6386-stack/yada-crm-miniprogram) 体验版一致，请勿改用别名。

## 1. 名片正反面（按联系人）

| action | 方法 | 说明 |
|--------|------|------|
| `customer_business_card_ocr` | POST multipart | 正面 OCR。`card_side=front`（默认），`ocr=1`（默认）。字段：`image` 或 `file`。返回 `business_card_front_token`、`card_side`、`ocr` 及识别字段。 |
| `customer_business_card_upload` | POST multipart | 背面存档，**不做 OCR**。`card_side=back`，`ocr=0`。返回 `business_card_back_token`、`card_side`、`ocr`。 |
| `contact_save` | POST JSON | 创建/更新联系人并绑定名片 token。 |
| `customer_create` | POST JSON | 单联系人或 `contacts[]`；每项可含 `business_card_front_token`、`business_card_back_token`（与正面 token 并列提交）。 |
| `customer_detail` | POST JSON | 每个联系人含 `business_cards.front` / `business_cards.back`（含 `url`）。 |
| `customer_business_card` | GET | 流式读取名片图，`id` + `token`。 |

**上传公共参数**

- `card_side`：`front` \| `back`（兼容旧 `side`）
- `ocr`：`1` 做 OCR（仅正面）；`0` 仅暂存 token（背面强制 `ocr=0`）

## 2. 拜访类型扩展

| visit_type | 中文 | record_kind |
|------------|------|-------------|
| `customer_visit` | 拜访 | `visit` |
| `customer_arrival` | 来访 | `arrival` |
| `video_meeting` | 视频会议 | `video_meeting` |
| `daily_followup` | 平时跟进 | `daily_followup` |

原有 `visit_category=跟进记录`（P1 跟进）**不变**，仍映射为 `customer_visit` + 分类「跟进记录」。

`visits` 列表 `with_meta=1` 时返回 `visit_types` 元数据。

## 3. visit_save 扩展字段

| 字段 | 说明 |
|------|------|
| `visit_type` | 见上表 |
| `participants_text` | 参会人姓名（兼容 `participant_names` / 字符串 `participants`） |
| `participant_count` | 人数（与 `visitor_count` 取较大写入） |
| `background_check_notes` | 背调文字（兼容 `background_check_note`） |
| `communication_content` | 沟通内容（响应标签 `communication_content_label` =「沟通内容」） |
| `progress_update` | 跟进进度；**跟进记录**或 `visit_type=daily_followup` 时必填 |

## 4. 附件上传

| action | 方法 | 参数 |
|--------|------|------|
| `visit_attachment_upload` | POST multipart | `visit_id`，`file`，`photo_category`：`communication` \| `progress` \| `background_check` |
| `visit_file` | GET | `id` + `token`，下载/预览拜访附件 |
| `upload` | POST multipart | 现场照片等；`photo_category` 亦可为 `communication` / `progress` / `background_check` |

`visit` 详情额外返回：

- `communication_content`、`progress_update`、`background_check_notes`
- `participants_text`、`participant_count`
- `communication_attachments`、`progress_attachments`、`background_check_attachments`（含 `url`）

兼容：`followup_progress`、`followup_progress_attachments`、`text_record`（等同沟通内容）仍可能在响应中出现。

## 5. 拜访/来访详情下的「跟进记录」时间线（子记录）

与并列 Tab `visit_category=跟进记录`、平时跟进 `daily_followup`、视频会议 `video_meeting` **无关**。子跟进只挂在某条**拜访**或**来访**详情下（`visit_type` 为 `customer_visit` / `customer_arrival`）。

数据表：`visit_followup_logs`（`parent_visit_id`、`follow_date`、`content`、`created_by`、`created_at`）。图片复用 `crm_visit_files`，列 `followup_log_id` = 子记录 id，`photo_category` = `visit_followup`。

| action | 方法 | 说明 |
|--------|------|------|
| `visit_followup_list` | POST JSON | `visit_id`（或 `parent_visit_id`）。返回 `followup_records[]` |
| `visit_followup_save` | POST JSON | 新建/更新：`visit_id`、`follow_date`（`YYYY-MM-DD`）、`content`；更新时加 `id` 或 `followup_id` |
| `visit_followup_delete` | POST JSON | `id` 或 `followup_id`；建议带 `visit_id` |
| `visit_followup_attachment_upload` | POST multipart | `visit_id`、`followup_id`（子记录 id）、`file` |

`action=visit` 详情在 `supports_child_followup_logs=true` 时附带 `followup_records[]`，每条含：

- `id` / `followup_id`、`follow_date`、`content`、`created_by`、`created_at`
- `attachments[]`：`id`、`url`、`photo_url`（图片）、`file_name`、`mime_type` 等

## 6. 部署

1. 合并后 GitHub Actions 快进 `artdon_erp` 至 `/www/wwwroot/Artdon/artdon_erp/`。
2. 同步 `miniapp/api/` 至 `/www/wwwroot/Artdon/miniapp/api/`（与仓库一致）。
3. 执行：`php miniapp/migrate.php`（CLI）；线上 `mx_ensure()` 会自动补列。
4. 数据库：`artdon_new_erp`。新增列/表见 `miniapp/migrate.php`（含 `visit_followup_logs`、`crm_visit_files.followup_log_id`）。
