# BailingHub for CRMEB

让 CRMEB 后台拥有一个可以直接操作业务的 AI 助手。

安装并连接 [BailingHub](https://www.bailinghub.com/) 后，管理员无需离开 CRMEB 后台，就可以用自然语言查询经营信息、查找商品和订单，并在本人已有权限范围内执行商品、订单、用户、营销等后台操作。

它不是一个只会“创建商品”的单功能插件。商品创建与上架是我们已经完成真人验证的一条代表链路；插件真正提供的是一套面向 CRMEB 后台的通用 AI 操作入口。

> 当前版本：`2.4.1`<br>
> 适配范围：CRMEB-KY v6（ThinkPHP 5.1）<br>
> 许可证：Apache-2.0

## 它能带来什么

- **自然语言操作后台**：在 CRMEB 管理后台打开聊天窗口，直接描述想查询或处理的事情。
- **覆盖多个业务领域**：能力按商品、订单、售后、用户、营销、财务、统计和门店组织，不局限于某一个页面。
- **沿用 CRMEB 原有权限**：AI 只能执行当前登录管理员本来就有权执行的操作，不另建一套角色权限。
- **任务过程可查看**：可在 BailingHub 中查看任务执行过程与结果，便于复核。
- **提供安装整包**：使用宝塔或服务器文件管理器也能完成安装；熟悉 SSH 的用户也可以使用命令行安装。安装后仍需按引导完成 BailingHub 连接配置。
- **独立升级**：后续插件版本可通过 CRMEB 配置页上传签名升级包，不需要修改 CRMEB 核心代码。

## 适合谁

- 希望在现有 CRMEB 后台增加 AI 操作入口的商城运营团队；
- 经常需要查询商品、订单、用户或经营数据，希望减少重复点击的管理员；
- 希望让 AI 调用 CRMEB 已有后台能力，同时继续沿用 CRMEB 原生权限体系的企业；
- 想先在演示或测试环境验证 AI 商城助手，再决定是否接入正式业务的开发者和服务商。

## 能力领域

插件当前声明 37 项原子能力。它们是 AI 可以按任务组合使用的后台操作积木，而不是写死的一条固定流程。

| 领域 | 可用方向 |
|---|---|
| 商品 | 商品列表、详情、分类、创建、上下架、库存、价格、评论 |
| 订单 | 订单列表、详情、状态统计、发货、确认收货、备注 |
| 售后 | 售后列表、同意或拒绝申请；实际退款打款仍走原支付通道 |
| 用户 | 用户查询、详情、启用或禁用、会员等级 |
| 营销 | 优惠券、定向发券、秒杀、拼团、砍价活动查询 |
| 财务 | 提现查询、拒绝提现、资金流水；不代替支付通道完成打款 |
| 统计 | 经营总览、营业额趋势、商品销量排行 |
| 门店 | 分销员、门店与物流公司查询 |

实际可用能力取决于 CRMEB 版本、当前管理员权限、已安装业务模块和现有业务数据。这里的“37 项”是能力声明数量，不表示每项都已在所有 CRMEB 环境逐一真人执行。

## 已完成的真实验证

维护者已在一套全新的 **CRMEB-KY v6.0.0 演示环境**完成：

- 插件安装与配置页加载；
- CRMEB 后台聊天入口显示与对话；
- BailingHub 任务发起、执行与结果返回；
- 商品查询、创建和上下架代表链路。

商品链路用于证明“自然语言发起 → AI 选择后台能力 → CRMEB 按原生权限执行 → 返回结果”已经跑通，不代表插件只能处理商品，也不等于 37 项能力均已在每个版本逐项实测。

## 三步开始使用

### 1. 安装插件

普通用户下载 Release 中的：

```text
crmeb-bailinghub-web-install-2.4.1.zip
```

把它上传到 **CRMEB 根目录**并解压，然后按网页安装页提示完成安装。CRMEB 根目录中应同时存在 `app/`、`config/`、`public/`、`runtime/` 和 `vendor/`。

### 2. 连接 BailingHub

进入 CRMEB 后台：

```text
系统设置 → 百灵中枢配置
```

从你实际使用的 BailingHub 实例或具体租户控制台复制聊天入口嵌入代码，再配置接入方 token 和工具源签名密钥。

可连接三种来源：

1. 自己部署的 BailingHub 开源版；
2. 自己部署的 BailingHub 商业版具体租户；
3. 没有前两者时，注册在线体验租户用于快速体验。

在线体验环境只是一套商业版体验部署，并不是商业版的固定地址。商业版与在线体验都必须填写对应的**具体租户地址**，不能填写平台管理后台或 `https://trial.bailinghub.com` 根地址。

### 3. 在 CRMEB 后台发起任务

保存并验证聊天入口后，重新登录或强制刷新 CRMEB 后台。右下角出现聊天入口后，就可以直接描述任务。

例如：

- “查一下最近创建的商品。”
- “把这个商品下架。”
- “看看今天的订单情况。”
- “查询这个用户的基本信息。”

最终能否执行，以当前登录管理员在 CRMEB 中的原生角色和权限为准。

## 安装说明

### 环境要求

- CRMEB-KY v6，ThinkPHP 5.1 内核；当前真人验证环境为 fresh CRMEB-KY v6.0.0；
- PHP >= 7.1；
- PHP 扩展：JSON、cURL、OpenSSL、Zip；
- CRMEB 站点可被 BailingHub 回调，建议使用 HTTPS；
- 其他 CRMEB 产品线、历史版本与定制版本需要在实际环境单独验证。

本插件由 BailingHub 独立维护，不代表 CRMEB 官方出品、合作或背书。

### 方式一：网页安装整包（推荐）

网页安装适合使用宝塔、FTP 或服务器文件管理器的用户，不要求 Composer。

1. 将 `crmeb-bailinghub-web-install-2.4.1.zip` 上传到 CRMEB 根目录并解压一次。
2. 解压后确认根目录存在 `crmeb-bailinghub.zip`，并且 `public/` 中存在 `bailinghub-setup.php`。
3. 在 CRMEB 根目录的 `runtime/` 下创建一次性安装口令文件 `bailinghub_install.key`。

有 SSH 时可以执行：

```bash
cd /path/to/crmeb
php -r 'echo bin2hex(random_bytes(32)), PHP_EOL;' > runtime/bailinghub_install.key
chmod 600 runtime/bailinghub_install.key
```

没有 SSH 时，可以先打开安装页面，点击“在本机生成随机口令”，然后通过宝塔或 FTP 在 `runtime/bailinghub_install.key` 中写入同一串 64 位十六进制口令。

浏览器访问：

```text
https://你的CRMEB域名/bailinghub-setup.php
```

URL 中不要添加 `/public/`。正常部署的 CRMEB 站点运行目录已经指向 `public/`。

页面中应确认：

- 插件包已找到；
- `app`、`config`、`runtime`、`vendor` 目录可写；
- 一次性安装口令已就绪；
- PHP 已启用 ZipArchive。

安装成功后进入 `系统设置 → 百灵中枢配置`。确认配置页和版本信息正常后，立即删除：

```text
public/bailinghub-setup.php
```

安装成功后 `runtime/bailinghub_install.key` 会自动销毁；如页面提示未能删除，请手动删除。外层和内层安装 ZIP 也可在确认安装完成后删除。

### 方式二：ZIP 命令行安装

熟悉 SSH 的用户可以下载 `crmeb-bailinghub-2.4.1.zip`，在 CRMEB 根目录执行：

```bash
mkdir -p vendor/crmeb/bailinghub
unzip crmeb-bailinghub-2.4.1.zip -d vendor/crmeb/bailinghub
php vendor/crmeb/bailinghub/scripts/install.php
```

如果手里的文件名是 `crmeb-bailinghub.zip`，只需把上面 `unzip` 命令中的文件名换成实际名称。

安装脚本会复制应用代码、刷新插件配置并注册服务。执行完成后请重载该站点实际使用的 PHP-FPM，或重启 PHP 容器，再重新登录并强制刷新 CRMEB 后台。

### 方式三：Composer（仅限已有私有包源的团队）

当前 `2.4.1` 尚未发布到 Packagist，`composer require crmeb/bailinghub` 不是公开安装入口。已经把本插件登记到自有 Composer 制品库的团队可以执行：

```bash
cd /path/to/crmeb
composer config repositories.bailinghub composer https://你的受控制品库
composer require crmeb/bailinghub:2.4.1
php vendor/crmeb/bailinghub/scripts/install.php
```

Composer 不会执行依赖包自己的安装脚本，因此最后一条命令不可省略。完成后同样需要重载 Web 站点实际使用的 PHP-FPM。

### Docker 部署

网页安装适用于 CRMEB 代码目录通过 bind mount 挂载，并且 PHP 运行用户对代码目录有写权限的环境。若代码只存在于只读镜像中，请在镜像构建阶段放入插件并执行：

```bash
php vendor/crmeb/bailinghub/scripts/install.php
```

## 配置说明

### CRMEB 后台

登录后台，进入 **系统设置 → 百灵中枢配置**。

推荐从对应 BailingHub 实例或具体租户控制台的“聊天入口 → 嵌入”复制完整 `<script>`。插件会从中提取中枢地址和公开 entry key，保存后会清空临时输入，不长期保存整段 HTML。

| 配置项 | 用途 |
|---|---|
| 聊天入口嵌入代码（推荐） | 一次填入中枢地址和公开 entry key |
| 接入方 token | 用于 CRMEB 管理员身份与聊天链路 |
| 工具源签名密钥 | 用于保护能力清单拉取、工具调用和授权探针 |
| 百灵中枢地址 + 聊天入口 key（高级） | 仅在无法复制完整嵌入代码时成对填写 |

接入方 token 和工具源签名密钥不是二选一。要完整使用聊天和业务调用，两条安全链都需要配置。

`pub_xxx` 形式的聊天入口 key 是公开标识，不是密码，但它不能单独推断中枢地址。自建商业版和在线体验都必须使用包含租户标识的具体租户地址。

token 和签名密钥不会写入 CRMEB `system_config`，也不会回显到页面，只显示“已配置/未配置”。密码框留空保存表示保留原值；填写新值才会替换。

### BailingHub 控制台

登记 CRMEB 工具源：

```text
spec_url = https://你的CRMEB域名/bailing/tools.json
base_url = https://你的CRMEB域名
访问策略 = signed_required
签名密钥 = 与 CRMEB 后台填写的工具源签名密钥一致
```

`base_url` 应指向不会重写 URI 的源站地址。若 CDN 或网关改变请求路径或编码，签名校验可能失败。

### 验证接入

- 在配置页点击“验证聊天入口”，确认聊天组件可以加载；
- BailingHub 刷新工具源时，正确签名应返回 `200`，未签名和错误签名应返回 `401`；
- 刷新 CRMEB 后台，确认右下角出现聊天浮窗；
- 用普通管理员验证时，仅测试该管理员原本有权执行的操作；撤销 CRMEB 原生权限后，同一 AI 操作应立即被拒绝。

## 权限与安全边界

- **CRMEB 权限是唯一真值**：插件按当前登录管理员身份执行，并在操作发生时检查 CRMEB 当前账号、角色及菜单/API 权限。
- **不会绕过原后台权限**：禁用账号、无效账号、匿名主体或没有对应权限的管理员都会被拒绝。
- **敏感凭据独立保存**：token 与签名密钥保存在 `runtime/bailinghub-secrets/`，目录权限为 `0700`、秘密文件为 `0600`；页面不回显原值。
- **工具源受签名保护**：能力清单、工具调用和授权探针使用同一工具源密钥进行 HMAC-SHA256 校验。
- **资金打款不自动执行**：订单退款打款、提现通过打款等必须继续走 CRMEB 原支付通道并由管理员按原流程处理。
- **不修改业务表结构**：插件不创建或修改 CRMEB 业务表，不删除商城业务数据。
- **正式站先备份**：尽管插件只维护自身目录和配置，正式环境仍应先备份，并在测试或预发布环境完成验证。
- **多实例部署需共享秘密目录**：如果 CRMEB 运行在多台主机或多个容器，应把 `runtime/bailinghub-secrets/` 挂载到共享持久卷。

不要通过清空整个 `runtime/` 来清缓存，应只清理 CRMEB 自己的 cache 子目录，并保留插件私密存储。

## 升级

### 从 2.4.0 或更早版本升级到 2.4.1

旧版本首次升级需要人工桥接：

1. 备份 `vendor/crmeb/bailinghub/`、`app/bailing/`、`config/bailing.php` 和 `vendor/services.php`；
2. 解压完整 `crmeb-bailinghub-2.4.1.zip` 到临时目录；
3. 确认根目录存在 `plugin.json`、`checksums.json` 和 `plugin.sig`；
4. 用候选目录整体替换 `vendor/crmeb/bailinghub/`，不要叠加解压；
5. 在 CRMEB 根目录执行：

```bash
php vendor/crmeb/bailinghub/scripts/install.php
```

随后重载 PHP-FPM 或重启 PHP 容器，再重新登录并强制刷新后台。

`2.4.1` 不迁移旧 `system_config` 中的 token 与签名密钥，升级后需要在专用配置页重新输入。中枢地址与 entry key 会继续保留。

### 2.4.1 之后升级

进入“系统设置 → 百灵中枢配置”，在“维护与升级”中上传新的 `crmeb-bailinghub.zip`：

1. 点击“检查升级包”；
2. 插件检查发布签名、文件 SHA-256、版本及环境要求；
3. 预检通过后，由 CRMEB 超级管理员确认升级；
4. 升级期间会备份并切换完整插件目录，私密凭据目录原地保留；
5. 升级失败会尝试恢复旧版。

当前版本不会联网下载并执行远程 PHP 代码。请从可信发布渠道下载升级包，再通过后台本地上传。

## 卸载

在 CRMEB 根目录执行：

```bash
php vendor/crmeb/bailinghub/scripts/uninstall.php
```

只有通过 Composer 安装并确实需要移除依赖记录时，再执行：

```bash
composer remove crmeb/bailinghub
```

卸载脚本会删除插件应用目录、配置文件、私密存储、升级状态、插件自己的后台配置项和聊天组件标记块；不会删除 CRMEB 商品、订单、用户等业务数据。

## 常见问题

### 后台看不到“百灵中枢配置”或版本信息

先重载站点实际使用的 PHP-FPM，再重新登录并强制刷新后台。配置分组位于“系统设置”中。

### 聊天浮窗没有出现

确认复制的是自己部署的开源实例，或商业版/在线体验的具体租户嵌入代码；不要填写平台管理后台或 `https://trial.bailinghub.com` 根地址。点击“验证聊天入口”，保存后强制刷新后台。

### BailingHub 拉取工具失败或返回 401

确认访问策略为 `signed_required`、两侧签名密钥一致，并检查 `base_url` 是否经过会改写 URI 的 CDN 或网关。

### AI 会不会拥有超过当前管理员的权限

不会。AI 调用始终以当前 CRMEB 管理员为主体，并按 CRMEB 当下的角色和权限执行。权限撤销后，后续操作会立即按新权限拒绝。

### 已经使用 CRMEB 的自定义 JS，会不会被覆盖

插件只维护 `custom_admin_js` 中带 `bailinghub-widget-begin/end` 标记的一小段加载代码，标记块之外的自定义 JS 会保留。正式环境升级前仍建议备份。

### 网页安装页提示 404

确认 `bailinghub-setup.php` 位于当前站点实际使用的 `public/` 目录，并且访问的是这套 CRMEB 的域名。URL 中不要添加 `/public/`。

### 网页安装页提示目录不可写

把 `vendor/`、`app/`、`config/`、`runtime/` 的所有者或权限调整为 PHP 运行用户可写。宝塔常见用户为 `www`，Ubuntu 或 Docker 常见为 `www-data`。

## 技术参考

以下内容用于二次开发、审计和维护，普通安装使用不必先理解这些细节。

<details>
<summary>查看 37 项工具标识</summary>

| 模块 | 工具 |
|---|---|
| 商品 | `product_list` / `product_detail` / `product_category_tree` / `product_create` / `product_set_show` / `product_update_stock` / `product_update_price` / `product_reply_list` / `product_reply_audit` / `product_reply_answer` |
| 订单 | `order_list` / `order_detail` / `order_status_stats` / `order_delivery` / `order_take_delivery` / `order_remark` |
| 售后 | `refund_list` / `refund_agree` / `refund_refuse` |
| 用户 | `user_list` / `user_detail` / `user_set_status` / `user_level_list` |
| 营销 | `coupon_issue_list` / `coupon_grant` / `seckill_list` / `combination_list` / `bargain_list` |
| 财务 | `extract_list` / `extract_refuse` / `balance_bill_list` |
| 统计 | `stats_overview` / `stats_trade_trend` / `stats_product_rank` |
| 门店 | `agent_list` / `store_list` / `express_list` |

</details>

<details>
<summary>查看版本与低侵入安装边界</summary>

- 适配器版本：`2.4.1`；
- 工具集合版本：`2.3.0`；
- 配置结构版本：`2`；
- BailingHub 最低版本：`0.2.0`；
- 工具源端点：`/bailing/tools.json`；
- 插件目录：`vendor/crmeb/bailinghub/`；
- CRMEB 运行代码：`app/bailing/`；
- 插件配置：`config/bailing.php`；
- 私密凭据：`runtime/bailinghub-secrets/`；
- 升级暂存：`runtime/bailinghub-updates/`。

插件不执行数据库 DDL。安装过程只增加插件命名空间内的文件、CRMEB 原生配置分组和聊天组件标记块；卸载时只清理插件自己的内容。

</details>

<details>
<summary>维护者构建签名升级包</summary>

发布私钥必须保存在项目目录外、权限设为 `0600`，并通过环境变量传入：

```bash
BAILINGHUB_PLUGIN_SIGNING_KEY=/secure/path/release-private.pem \
  php scripts/build-upgrade-package.php --output /tmp/crmeb-bailinghub.zip
```

发布私钥不得上传到 GitHub、CRMEB 站点或任何插件 ZIP 中。

</details>

## License

[Apache License 2.0](LICENSE)
