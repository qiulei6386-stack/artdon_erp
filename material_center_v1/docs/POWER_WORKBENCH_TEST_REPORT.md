# 电源工作台测试报告

## 来源数据空白根因

不是无数据，也不是适配器或 SQL 失败。服务器旧 BOM 识别到320条电源，适配器按安全上限返回200条。旧实现读取后没有渲染 `$rows`，而是改用 iframe 嵌入旧页面；统一登录响应/嵌入初始化失败时整个内容区空白。

修复后工作台直接通过 `MaterialReadRepository` 原生渲染真实记录，并关联暂存状态、SHA-256、映射状态和重复风险。异常显示 `PWB-*` 错误编号、重试按钮并写 PHP 错误日志。

## 自动化结果

- action binding：通过
- permission/sensitive field：通过
- old BOM read-only regression：通过，旧 BOM 总行数1022
- route/legacy URL contract：通过
- all seven Tab service queries：通过
- source adapter：通过，返回200条
- PHP/JavaScript syntax：通过
- UI/static/read-only contract：通过

## 数据现状

- 旧 BOM 电源：320条（页面安全上限200）
- 20–25W暂存试点：11条
- 正式电源：0
- 重复候选：0
- 停用/归档：0

空集合均显示专业空状态，没有假数据。
