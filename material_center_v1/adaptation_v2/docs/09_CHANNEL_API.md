# 产品适配 V2 第 9 阶段：下游渠道接口

日期：2026-08-01

## 边界

- 不修改旧版产品适配业务。
- 不修改旧 BOM。
- 不切换正式菜单。
- 只在 `material_center_v1/adaptation_v2/` 继续开发。
- 新表全部使用 `mc_pa2_` 前缀。
- 下游接口只读已发布配置包版本，草稿、未发布、停用版本不得暴露。

## 新增数据表

- `mc_pa2_channel_clients`：下游客户端，包含渠道、启停、是否要求签名和密钥环境变量名。
- `mc_pa2_channel_package_snapshots`：已发布配置包下游载荷快照。
- `mc_pa2_channel_cache`：渠道接口缓存。
- `mc_pa2_channel_access_logs`：渠道接口访问日志。
- `mc_pa2_channel_order_snapshots`：下游订单配置快照，保存下单时使用的配置包版本和载荷哈希。

## 首批客户端

- `commercial_center`：商务中心，渠道 `commercial`。
- `singapore_site`：新加坡网站，渠道 `singapore`。

密钥不写入数据库。数据库只记录环境变量名：

- `PA2_CHANNEL_SECRET_COMMERCIAL_CENTER`
- `PA2_CHANNEL_SECRET_SINGAPORE_SITE`

## 签名

Headers：

- `X-PA2-Client`
- `X-PA2-Timestamp`
- `X-PA2-Signature`

签名基串：

```text
timestamp + "\n" + client_code + "\n" + raw_body
```

算法：

```text
HMAC-SHA256(base_string, client_secret)
```

时间戳允许 300 秒误差。

## API

### 读取已发布配置包列表

`GET /material_center_v1/adaptation_v2/api/index.php?action=channel_packages`

返回当前客户端所属渠道下，状态为 `published` 且活动版本状态为 `published` 的配置包。

### 读取单个已发布配置包

`GET /material_center_v1/adaptation_v2/api/index.php?action=channel_package_detail&package_code=singapore_standard`

若配置包仍是草稿或未发布，接口返回错误，不返回草稿内容。

### 保存下游订单快照

`POST /material_center_v1/adaptation_v2/api/index.php?action=channel_order_snapshot`

必要字段：

- `external_order_no`
- `package_code`
- `order_payload`

订单快照会保存当前已发布配置包载荷，避免后续配置修改影响历史订单判断。

## 缓存

- 列表缓存键：`packages:{channel_code}`
- 详情缓存键：`package:{channel_code}:{package_code}`
- 默认缓存时间：300 秒。

## 日志

渠道接口每次成功读取或写入订单快照会写 `mc_pa2_channel_access_logs`。

签名失败、客户端错误等异常也会尝试写入访问日志，但不会泄露密钥。

## 当前限制

- 第 9 阶段只完成 V2 侧接口和数据结构。
- 商务中心和新加坡网站尚未改造为正式调用方。
- 因第 8 阶段首批配置包默认仍是草稿，下游接口默认返回空列表；必须在配置包中心发布后才会返回。
- 正式菜单仍不切换。
