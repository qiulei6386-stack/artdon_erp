# Artdon ERP 协作规则

## 固定同步流程

1. 所有代码修改以本地工作目录为唯一编辑源：
   `/Users/qiulei-office/Library/Mobile Documents/com~apple~CloudDocs/artdon/artdon_guangzhou/artdon_erp`
2. 不直接在服务器上临时编辑代码。先修改本地文件，再执行与改动相称的语法、静态和功能检查。
3. 检查通过后，将相同文件部署到服务器运行目录：
   - `/www/wwwroot/Artdon/`
   - `/www/wwwroot/Artdon/artdon_erp/`
4. 服务器部署后再次执行适用的 PHP 语法检查。
5. 将本地改动提交到当前 Git 分支，并推送到 GitHub `origin`。
6. 本地、服务器运行目录和 GitHub 必须保持同一版本；不得只修改其中一处。
7. 不覆盖用户已有的无关改动，不使用破坏性 Git 命令。

## 上下文记录

1. 每次工作结束前更新仓库根目录的 `WORK_CONTEXT.md`。
2. 至少记录：
   - 本次完成内容
   - 修改文件
   - 检查结果
   - 服务器部署情况
   - Git 提交号及推送状态
   - 尚未完成、待确认或下一步事项
3. 新一轮工作开始时先读取 `WORK_CONTEXT.md`，在现有成果上继续，不重复或回退已经完成的修改。

