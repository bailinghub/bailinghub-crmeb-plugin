# BailingHub for CRMEB v2.4.1

BailingHub for CRMEB 是一款面向 CRMEB-KY v6 的独立开源接入插件。安装并完成 BailingHub 中枢连接后，管理员可以留在 CRMEB 原后台，用自然语言查询数据、管理商品、处理订单与售后，以及调用用户、营销、财务、统计和门店等已有后台能力。

## 这个版本带来了什么

- CRMEB 后台内嵌 BailingHub AI 操作助手，无需在多个后台之间来回切换。
- 能力清单按商品、订单、售后、用户、营销、财务、统计和门店组织。
- AI 发起操作时继续使用 CRMEB 当前管理员的账号与原生权限。
- 支持自建开源版、自建商业版具体租户，或 BailingHub 在线体验租户。
- 配置页可查看版本与安全上传本地升级包，便于后续维护。

## 代表性验证

维护者已在 fresh CRMEB-KY v6.0.0 隔离演示环境完成插件安装、中枢配置、后台会话，以及商品创建、详情查询和上下架的代表性链路。这说明 AI 调用结果、CRMEB 原生业务记录与 BailingHub 任务留痕可以对应。

该证据不代表 37 项能力已在所有 CRMEB 版本和外部生产环境逐项验证。其他 CRMEB 产品线或历史版本请先在测试环境验证。

## 下载与安装

请从本 Release 的 **Assets** 下载：

- `crmeb-bailinghub-web-install-2.4.1.zip`：适合宝塔、FTP 或文件管理器用户的网页安装整包。
- `crmeb-bailinghub-2.4.1.zip`：适合熟悉 SSH 的用户手工安装，也是后续后台离线升级使用的签名插件包。
- `SHA256SUMS.txt`：用于校验下载文件。

GitHub 自动生成的 `Source code (zip)` 和 `Source code (tar.gz)` 是源码快照，**不是可直接上传安装的插件包**。

正式站点安装前请先备份，并在测试环境完成验证。安装后进入 CRMEB 后台的“系统设置 → 百灵中枢配置”，按引导粘贴对应 BailingHub 实例或租户生成的完整聊天入口嵌入代码，再完成 token 与工具源签名配置。

## 开源与边界

本插件源码不加密，采用 Apache-2.0 许可证。插件本身免费；服务器、模型、托管和定制实施费用不包含在免费插件中。

BailingHub for CRMEB 是独立 Adapter，不代表 CRMEB 官方出品、合作、背书或全版本兼容。
