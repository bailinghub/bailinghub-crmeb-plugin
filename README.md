# CRMEB 百灵中枢（BailingHub）接入插件

面向 **CRMEB-KY v6（ThinkPHP 5.1）** 的独立 [BailingHub](https://www.bailinghub.com/) 接入适配器：安装并完成中枢连接配置后，商城可发布标准工具源，CRMEB 管理后台可嵌入百灵聊天组件，管理员能够在原后台通过自然语言调用其已有业务能力。

当前真人验证范围为维护者控制的 fresh CRMEB-KY v6.0.0 演示环境，已完成安装、配置、聊天以及商品创建、查询和上下架代表链路。其他 CRMEB 产品线、历史版本与全部 37 项能力仍应按实际环境、权限和业务规则分别验证。本适配器由 BailingHub 独立维护，不代表 CRMEB 官方出品、合作或背书。

插件基于 **ACC（[Agent Capability Contract](https://agentcapability.org/)）** 开放标准构建：声明既有系统的能力，而不是发明新接口。

## 架构原则

### 1. 元能力（原子能力），不声明组合能力

一个工具 = 一个资源 + 一个动作。插件不提供"创建商品并上架再发券"这类工作流式组合能力——**组合与编排是中枢 AI 的事**。如果插件声明的是组合好的流程，中枢 AI 就失去了自由组合的空间，只能死板调用。37 个原子能力 = AI 手里的积木，场景由 AI 自己拼。

### 2. 声明既有能力，不发明新流程

插件声明的是 CRMEB 系统**本来就有**的业务能力（与后台按钮同一语义）。ACC 的定位：*"ACC controls reach. The business system controls authority."*——插件只管能力的可达性声明，CRMEB 自己的登录账号、角色和权限配置是最终授权的唯一真值。

登录票据只证明“当前是谁”：CRMEB 用自己的管理员 JWT 签发包含管理员 ID 的票据，中枢回调时原样传递这个主体。票据不携带角色清单，中枢也不判断 CRMEB 权限。每次工具真正执行前，插件把该工具映射到等价的 CRMEB 原生菜单、按钮或 API，并实时读取 `system_admin → system_role.rules → system_menus` 做裁决。角色被停用、权限被收回后，下一次 AI 调用立即按 CRMEB 当前配置拒绝，不存在插件权限快照。

本插件用于演示“AI 直接操作现有后台能力”：只要当前管理员在 CRMEB 后台拥有该原生操作权限，AI 调用就直接执行，不再叠加一套中枢人工审批；后台没有权限，AI 调用直接返回 403。写操作使用 `low` 或 `medium` 风险级，`medium` 表示直接执行并保留审计记录，不表示等待审批。匿名主体、无效账号、禁用账号或缺少原生权限都会被 CRMEB 业务端拒绝。

### 3. 低侵入安装（正式站点先备份并验证）

插件不修改 CRMEB 核心代码或业务表，只维护自己命名空间内的文件、配置记录与脚本标记块：

| 动作 | 内容 | 性质 |
|---|---|---|
| 新增文件 | `app/bailing/`、`config/bailing.php`、`vendor/crmeb/bailinghub/`；首次打开专用配置页后新增 `runtime/bailinghub-secrets/`；首次使用升级器后新增 `runtime/bailinghub-updates/` | 私密目录权限为 `0700`、秘密文件为 `0600`；升级暂存与私密存储彼此隔离 |
| 追加配置行 | `eb_system_config_tab` +1 行、`eb_system_config` +3 行 | CRMEB 原生配置机制只保存嵌入临时输入、中枢地址和公开 entry key；不创建 token/签名密钥行 |
| 历史配置收口 | 升级旧安装时删除插件自有的 `bailing_route`、`bailing_access_token`、`bailing_sign_secret` 历史行 | 旧秘密不迁移，避免继续被通用按名配置接口读取；管理员需要在专用页面重新输入 |
| 标记块注入 | `custom_admin_js` 值内追加 `/* bailinghub-widget-begin/end */` 标记块 | 标记块只加载同源动态入口 `/bailing/admin-bundle`；块外内容（你的其他自定义 JS）原样保留 |
| 数据库结构 | **无任何 DDL**（不建表、不改字段、不加索引） | 不改变业务表结构；正式站点仍应先备份并在测试环境验证 |

卸载脚本只清理插件自身目录、配置记录、私密存储、升级状态与脚本标记块，不删除 CRMEB 业务数据；如外部权限、缓存或人工配置发生过变化，仍需由运维人员按实际环境复核。

### 4. 版本化，可持续升级

- **适配器版本**描述安装包、配置页和升级器代码；当前为 `2.4.1`
- **能力集合版本** `SPEC_VERSION` 输出在 `tools.json` 的 `info.version`；当前仍为 `2.3.0`，只有工具契约变化时才递增
- **配置结构版本**独立记录持久化边界；当前为 `2`，表示秘密改存于插件自有 runtime 存储（旧通用配置值不迁移）
- **MINOR**（新增工具/可选参数）：向后兼容，中枢重新拉取即获得新能力
- **MAJOR**（删工具/改 path/改必填参数）：会提前在 changelog 声明，旧版本工具按 ACC `enabled: false` 语义先下线再移除，不突然消失
- `2.4.1` 的配置界面通过小型同源加载器加载，不再受 CRMEB 单条配置值长度限制；“维护与升级”中会显示三个版本，并支持超级管理员上传受签名保护的本地升级包

## 功能

- **工具源端点**：自动注册受签名保护的 `/bailing/tools.json`（OpenAPI 3.0 规范）+ 工具调用端点，供中枢拉取与回调
- **37 项原子能力声明**：按商品、订单、售后、用户、营销、财务、统计与门店组织（见下表）；实际可用性取决于 CRMEB-KY v6 版本、当前管理员权限和业务数据，本次真人验证不等于 37 项均已逐项执行
- **签名验签**：内置 HMAC-SHA256 验签（`sha256=` 签名头）；同一工具源密钥保护 spec 拉取、工具调用和授权探针，中枢"签所发即所发"
- **后台配置页**：明确区分自建开源版、自建商业版具体租户与在线体验租户；三种来源都优先粘贴对应控制台生成的完整聊天入口 `<script>`，高级区才允许分开填写中枢地址和公开 entry key
- **秘密不回显**：接入方 token 与工具源签名密钥只通过专用安全接口写入 `runtime/bailinghub-secrets/`；不进入 `system_config`，页面只显示是否已配置，留空保存表示保留私密存储中的原值
- **版本与升级**：配置页显示适配器、工具清单和配置结构版本；上传本地签名包后先预检，再由超级管理员确认升级
- **显式连通验证**：仅在管理员点击“验证聊天入口”后由浏览器发起最长 5 秒的检查，不在每次业务请求中阻塞探测外网
- **聊天组件**：配置聊天入口后，`/bailing/admin-bundle` 在已登录后台按需加载百灵官方 `widget.js` 聊天浮窗；该动态入口不带静态文件扩展名，可直接适配常见 Nginx/宝塔伪静态规则；`custom_admin_js` 只保留小型加载器，不影响你已有的自定义 JS
- **原生权限唯一真值**：所有工具都要求 CRMEB 登录管理员主体（`onBehalfOf`），并在执行瞬间按 CRMEB 当前账号、角色与菜单/API 权限裁决；中枢和插件不维护第二套角色
- **演示直通**：后台已有的写操作不再声明中枢人工审批；`medium` 风险操作直接执行并留痕

## 内置工具清单（37 个，全部为原子能力）

| 模块 | 工具 | 说明 |
|---|---|---|
| 商品 | product_list / product_detail / product_category_tree | 商品列表、详情、分类树 |
| 商品 | product_create | 创建商品（含主图/单位/运费模板/分类校验） |
| 商品 | product_set_show / product_update_stock / product_update_price | 上下架、改库存、改价 |
| 商品 | product_reply_list / product_reply_audit / product_reply_answer | 评论查询、审核、商家回复 |
| 订单 | order_list / order_detail / order_status_stats | 订单列表、详情（含商品明细）、状态统计 |
| 订单 | order_delivery / order_take_delivery / order_remark | 发货、确认收货、改备注 |
| 售后 | refund_list / refund_agree / refund_refuse | 售后列表、同意、拒绝（实际打款走支付通道，需在后台人工执行） |
| 用户 | user_list / user_detail / user_set_status / user_level_list | 用户查询、详情、启禁用、会员等级 |
| 营销 | coupon_issue_list / coupon_grant | 优惠券查询、定向发券 |
| 营销 | seckill_list / combination_list / bargain_list | 秒杀/拼团/砍价活动查询 |
| 财务 | extract_list / extract_refuse / balance_bill_list | 提现列表、拒绝提现（退回余额）、资金流水 |
| 统计 | stats_overview / stats_trade_trend / stats_product_rank | 经营总览、营业额趋势、销量排行 |
| 门店 | agent_list / store_list / express_list | 分销员、门店、物流公司 |

> 设计原则：涉及资金打款通道的操作（订单退款打款、提现通过打款）**不在插件内实现**——这些必须走原支付通道（微信/支付宝 SDK），由管理员在后台人工执行，插件只做查询与状态流转。

## 环境要求

- CRMEB-KY v6（ThinkPHP 5.1 内核；当前真人验证版本为 fresh v6.0.0）
- PHP >= 7.1
- 站点可公网访问（中枢需要回调），建议 HTTPS

## 安装

### 方式一：网页安装整包（推荐给大多数 CRMEB 站长）

适合使用宝塔面板、FTP 或服务器文件管理器的用户。整个过程只需要上传一个 ZIP、解压一次，再用文件管理器放置一枚一次性安装口令；不要求 SSH 或 Composer。

#### 第一步：确认 CRMEB 根目录

CRMEB 根目录里应该能同时看到 `app/`、`config/`、`public/`、`runtime/`、`vendor/` 等目录。不要把插件上传到 CRMEB 后台页面文件夹或前端源码目录。

#### 第二步：上传并解压网页安装整包

把 `crmeb-bailinghub-web-install.zip` 上传到 CRMEB 根目录，并在当前目录解压一次。安装整包会自动把网页安装器和插件载荷放到各自的正确位置。

上传完成后的目录应类似：

```text
你的 CRMEB 根目录/
├── app/
├── config/
├── public/
│   ├── index.php
│   └── bailinghub-setup.php
├── runtime/
├── vendor/
└── crmeb-bailinghub.zip
```

外层的 `crmeb-bailinghub-web-install.zip` 解压后可以立即删除；内层 `crmeb-bailinghub.zip` 请保留到网页安装完成。若没有网页安装整包，也仍可按上述最终位置分别上传 `webinstaller/bailinghub-setup.php` 和 `crmeb-bailinghub.zip`。

#### 第三步：浏览器打开安装页面

如果你的 CRMEB 后台是：

```text
https://shop.example.com/admin/
```

安装页面就是：

```text
https://shop.example.com/bailinghub-setup.php
```

使用 IP 访问时同理，例如：

```text
http://服务器IP/bailinghub-setup.php
```

URL 中**不要加 `/public/`**。正常部署的 CRMEB 已经把网站运行目录指向 `public/`。

页面打开后先确认：

- “插件包”显示已找到 `crmeb-bailinghub.zip`
- “目录写权限”显示正常
- `app`、`config`、`runtime`、`vendor` 均没有不可写提示

安装页不会把“能访问页面”当作服务器权限。第一次打开时表单保持禁用，请按页面提示：

1. 点击“在本机生成随机口令”，得到 64 位十六进制随机串；生成过程只发生在浏览器本地，不会上传。
2. 使用宝塔/FTP 文件管理器，在 CRMEB 根目录创建 `runtime/bailinghub_install.key`，文件内容只放该随机串；条件允许时把文件权限设为 `600`。
3. 刷新安装页，确认“一次性安装口令”显示已就绪，再把同一随机串填入表单。

有 SSH 时也可以在服务器执行：

```bash
php -r 'echo bin2hex(random_bytes(32)), PHP_EOL;' > runtime/bailinghub_install.key
chmod 600 runtime/bailinghub_install.key
```

安装器不会回显服务器文件里的口令。口令校验发生在任何安装写入之前；错误或缺失口令不会解压、复制、写配置或生成锁文件。

先确认你的 BailingHub 来源，再到**对应实例或具体租户**的控制台「聊天入口 → 嵌入」复制完整 `<script>`：

1. **自建开源版**：进入自己部署的开源版控制台复制。
2. **自建商业版**：进入准备接入这套 CRMEB 的**具体租户控制台**复制；不要使用平台管理后台地址。
3. **在线体验租户**：只有在前两种都没有、只想快速体验时，才到 `https://trial.bailinghub.com/register/` 申请空间，进入分配到的租户控制台复制。

在线体验环境只是我们提供的一套商业版体验部署，不等于“商业版”这个产品，也不是所有商业版用户的固定地址。三种来源的推荐操作完全相同：粘贴完整嵌入代码，让插件同时解析中枢地址和公开 entry key。

如果暂时没有嵌入代码，也可以留空，或展开高级设置并**成对**填写“百灵中枢地址 + 聊天入口 key”。自建开源版可使用实例根地址；自建商业版或在线体验都必须使用包含明确租户标识的具体租户地址。裸 `pub_xxx` 无法推断中枢地址，`https://trial.bailinghub.com` 根地址也不代表任何租户。确认检查通过后，点击一次“开始安装”。

#### 第四步：确认安装成功

页面应显示以下结果：

- 插件包已就位到 `vendor/crmeb/bailinghub/`
- 应用代码已复制到 `app/bailing/`
- 已创建 `config/bailing.php`
- 已注册 `BailingService`
- 已生成 `runtime/bailinghub_install.lock`
- `runtime/bailinghub_install.key` 已自动销毁

随后访问或刷新一次 CRMEB 后台，再进入：

```text
系统设置 → 百灵中枢配置
```

看到“快速接入”配置卡、版本信息及高级设置，即表示插件安装完成。已有 token 与签名密钥只显示“已配置”，不会回显原值。

#### 第五步：删除网页安装器

安装成功后，请删除：

```text
public/bailinghub-setup.php
```

ZIP 安装包也可以删除。不要删除 `vendor/crmeb/bailinghub/`、`app/bailing/` 或 `runtime/bailinghub_install.lock`，它们属于已安装插件。

如果安装结果提示口令文件未能自动删除，请手动删除 `runtime/bailinghub_install.key`；锁文件已生成时安装不会被再次执行。

#### 网页安装常见问题

**访问安装页面提示 404**  
通常是 `bailinghub-setup.php` 没有放进当前站点实际使用的 `public/` 目录，或当前访问的域名/IP 不是这套 CRMEB。

**页面提示“未找到插件包”**  
确认文件名中包含 `bailinghub` 且扩展名为 `.zip`，并放在 CRMEB 根目录或 `public/` 目录。

**页面提示目录不可写**  
把 `vendor/`、`app/`、`config/`、`runtime/` 的所有者设置为 PHP 运行用户（宝塔通常是 `www`，Ubuntu/Docker 常见为 `www-data`），再刷新安装页面。

**页面提示“安装口令未就绪”或“安装口令错误”**

确认 `runtime/bailinghub_install.key` 位于 CRMEB 根目录的 `runtime/` 下，内容只有 64 位十六进制随机串，没有引号或其他说明文字。页面输入必须与文件内容一致；安装成功后该文件会自动删除，不能重复使用。

**页面提示 PHP 未启用 ZipArchive**  
在 PHP 扩展管理中启用 `zip` 扩展，然后重启 PHP 服务并重新打开安装页面。

### 方式二：Composer（仅适用于已配置该包源的环境）

当前 `2.4.1` **尚未发布到 Packagist**，因此 `composer require crmeb/bailinghub` 不是本次 CRMEB 应用市场的公开安装入口。普通用户请使用上面的网页安装整包，熟悉 SSH 的开发者可使用下面的 ZIP 命令行方式。

包内仍保留标准 Composer 元数据，供已经把本插件登记到自有 Composer 制品库的团队使用。Composer 不会执行依赖包自己的 `scripts`；在这种受控环境中，安装依赖后还必须显式运行本插件安装脚本：

```bash
cd /path/to/crmeb
composer config repositories.bailinghub composer https://你的受控制品库
composer require crmeb/bailinghub:2.4.1
php vendor/crmeb/bailinghub/scripts/install.php
```

安装脚本会复制运行代码、刷新插件配置并兜底注册 `BailingService`。完成后仍需按提示重载站点实际使用的 PHP-FPM。

### 方式三：ZIP 命令行安装

仅适合熟悉 SSH 和命令行的开发者。当前发布 ZIP 内是平铺的插件文件，因此应直接解压进最终包目录：

```bash
# 1. 在 CRMEB 根目录执行，把插件解压到最终包目录
mkdir -p vendor/crmeb/bailinghub
unzip crmeb-bailinghub.zip -d vendor/crmeb/bailinghub

# 2. 执行安装脚本（复制应用代码 + 配置 + 注册服务，一步到位）
php vendor/crmeb/bailinghub/scripts/install.php
```

> ZIP 安装会得到与受控制品库 Composer 安装相同的插件目录与服务注册结果——`install.php` 内置了服务注册兜底逻辑，不依赖 Composer 环境。

> 执行 `install.php` 后请重载这个站点实际使用的 PHP-FPM（Docker 中重启 PHP 容器），再重新登录并强制刷新 CRMEB 后台。CLI 进程与 Web PHP-FPM 不是同一个运行时，只在 CLI 执行 `opcache_reset()` 不足以让旧 Worker 立即加载新服务和路由。

### 方式四：Docker 部署注意

网页安装适用于 CRMEB 代码目录以 **bind mount** 方式挂载、并且 PHP 用户对代码目录有写权限的 Docker 部署。本插件使用复制而非软链，天然兼容挂载目录。

如果代码只存在于只读镜像中，网页安装器无法写入；应在镜像构建阶段放入插件，并执行 `php vendor/crmeb/bailinghub/scripts/install.php`，或把安装动作加入部署流程。

## 升级

### 从 2.4.0 或更早版本首次桥接到 2.4.1

`2.3.x` 及更早版本没有后台升级器；`2.4.0` 在部分 CRMEB 环境会因通用配置字段长度限制而无法加载完整增强界面，所以也不应依赖页面内升级按钮。统一按下面步骤人工桥接一次：

1. 备份 `vendor/crmeb/bailinghub/`、`app/bailing/`、`config/bailing.php` 和 `vendor/services.php`。
2. 将完整 `crmeb-bailinghub.zip` 解压到临时目录，确认根目录存在 `plugin.json`、`checksums.json` 和 `plugin.sig`。
3. 用候选目录**整体替换** `vendor/crmeb/bailinghub/`，不要在旧目录上叠加解压。
4. 在 CRMEB 根目录执行：

```bash
php vendor/crmeb/bailinghub/scripts/install.php
```

安装脚本会先构建完整的 `app/bailing/` 候选副本再切换，并原子刷新插件生成的 `config/bailing.php`，以移除旧版 secret 读取代码；实际 hub/entry 值仍保存在数据库中，不会因此丢失。新基线不会迁移旧 `system_config` 中的 token/签名密钥：首次启动会精确删除这两行，必须在专用配置页重新输入，随后只保存到 `runtime/bailinghub-secrets/`。**手工桥接后必须重载站点实际使用的 PHP-FPM（或重启 PHP 容器）**，再重新登录并强制刷新 CRMEB 后台。仅在 CLI 中执行 `opcache_reset()` 不能替代对 Web PHP-FPM 的重载。配置页应显示适配器 `2.4.1`、工具清单 `2.3.0`、配置结构 `2`。

网页安装整包只负责首次安装，不要删除安装锁并把它当成升级器重复运行。

### 2.4.1 之后的后台升级

进入“系统设置 → 百灵中枢配置”，在版本卡片中选择新的 `crmeb-bailinghub.zip`：

1. “检查升级包”只把文件上传到非 Web 的 `runtime/bailinghub-updates/` 暂存区。
2. 插件核对发布签名、文件 SHA-256、SemVer、PHP/CRMEB 兼容性和配置结构版本。
3. 预检通过后才显示“升级到 x.y.z”；只有 CRMEB 超级管理员可以确认。
4. 升级器锁定并发操作、备份 `vendor` 与运行副本、切换完整目录并记录非敏感升级历史；`runtime/bailinghub-secrets/` 原地保留且绝不复制进升级备份或发布包。
5. 任一步骤失败都会尝试恢复旧版，不把半套代码留在线上。

第一阶段没有“联网检查最新版”：在建立公开、不可变且有签名的适配器发布渠道前，插件不会自行下载或执行远程 PHP 代码。

### 维护者构建升级包

发布私钥必须保存在项目目录外、权限为 `0600`，并通过环境变量传入：

```bash
BAILINGHUB_PLUGIN_SIGNING_KEY=/secure/path/release-private.pem \
  php scripts/build-upgrade-package.php --output /tmp/crmeb-bailinghub.zip
```

构建器只打包受控目录，拒绝私钥、测试、网页安装器和临时文件；生成包内 `checksums.json` 与 `plugin.sig`。私钥绝不能上传到 CRMEB 站点或放进 ZIP。

## 配置

### 1. CRMEB 后台

登录后台 → **系统设置 → 百灵中枢配置**。页面先让你确认中枢来源：

- **自建开源版**：使用自己部署的开源版实例。
- **自建商业版**：选择准备接入 CRMEB 的具体租户，不能使用平台管理后台地址。
- **在线体验租户**：仅用于没有自建开源版、也没有自建商业版时的快速体验；先注册并进入分配到的租户空间。在线体验不是商业版的同义词。

无论选择哪一种来源，推荐方式都是从相应实例或**具体租户**控制台「聊天入口 → 嵌入」复制完整 `<script>`：

| 配置项 | 来源 | 说明 |
|---|---|---|
| 聊天入口嵌入代码（推荐） | 对应中枢控制台「聊天入口 → 嵌入」 | 自动提取中枢地址和 entry key；成功保存后临时输入立即清空，不长期保存整段 HTML |
| 接入方 token | 同一实例/租户控制台「接入方」 | CRMEB 管理员换取聊天票据和访问中枢的身份凭据；与聊天入口配合使用 |
| 工具源签名密钥 | 同一实例/租户控制台「工具源」 | 独立保护 spec 拉取、工具调用和授权探针；必须与中枢工具源登记值一致 |
| 百灵中枢地址（高级） | 对应实例或具体租户地址 | 只在无法粘贴嵌入代码时使用；必须和下面的 entry key 成对填写 |
| 聊天入口 key（高级） | 对应控制台「聊天入口」 | 形如 `pub_xxx` 的公开标识，不是秘密；无法单独推断中枢地址 |

“完整嵌入代码”和“中枢地址 + entry key”是同一聊天入口信息的两种填写方式，前者推荐，后者只放在高级设置中；不要两种方式同时提交。接入方 token 与工具源签名密钥不是二选一：前者保护管理员身份和聊天链路，后者保护工具源与工具调用链路；要完整体验聊天和可调用工具，两条安全链都需要配置。

token 和签名密钥永远不会写入 CRMEB `system_config` 或回显到页面，界面只显示“已配置/未配置”。密码框留空保存表示保留 `runtime/bailinghub-secrets/` 中的原值；填写新值才会原子替换。旧版通用配置表中的秘密会被删除且不迁移，因此首次使用新基线必须重新输入；若旧行或对应缓存无法清理，当前请求会失败关闭，不能继续进入通用配置 API。普通管理员只能查看状态，只有超级管理员能保存。旧版的“路由 key”从未被运行时使用，`2.4.1` 已将它移除。

不要用“清空整个 `runtime/`”的方式清缓存；只清理 CRMEB 自己的 cache 子目录，并保留 `runtime/bailinghub-secrets/`。当前存储适用于同机多 PHP-FPM Worker；如果 CRMEB 运行在多台主机或多个容器，必须把该目录挂到所有实例共享的持久卷，否则各实例会看到不同的凭据状态。

粘贴嵌入代码后，页面会解析并立即清空原始输入。点击“验证聊天入口”会显式检查 `widget.js` 与该入口配置，最长等待 5 秒；服务器只保存规范化的中枢地址与 entry key。请始终从控制台复制标准嵌入代码；带临时 `data-ticket` 的运行时代码会被拒绝。

### 2. BailingHub 中枢控制台

登记工具源：

- **spec_url** = `https://{你的域名}/bailing/tools.json`
- **base_url** = `https://{你的域名}`
- **访问策略** = **签名保护（`signed_required`）**
- **签名密钥** = 与 CRMEB 后台“工具源签名密钥”完全相同

> ⚠️ base_url 必须指向**源站直连地址**。中枢"签所发即所发"——若经过会重写/重编码 URI 的 CDN 或网关，验签必挂。

### 3. 验证

- 中枢刷新工具源时，三探针必须为：正确签名 `200` / 未签名 `401` / 错误签名 `401`
- 中枢控制台应能拉取到工具列表（商品查询/商品创建/订单查询等）
- 刷新 CRMEB 后台，右下角出现聊天浮窗，可直接对话操作商城
- 用普通管理员测试时：后台有权限的操作应由 AI 直接执行；在 CRMEB 角色中取消对应权限后，同一操作应立即返回 403

## 卸载

```bash
php vendor/crmeb/bailinghub/scripts/uninstall.php
composer remove crmeb/bailinghub
```

卸载脚本会清理：`app/bailing/` 应用目录、`config/bailing.php`、`runtime/bailinghub-secrets/` 私密存储、插件升级状态、后台配置项与分组、`custom_admin_js` 中的聊天组件标记块；不会删除其他 runtime 业务数据。

## 常见问题

**Q: 后台看不到「百灵中枢配置」标签？**
A: 强制刷新（Cmd/Ctrl+Shift+R）或重新登录。配置分组挂在「系统设置」下，数据在安装后首个请求自动写入。

**Q: 聊天浮窗没出现？**
A: 先确认复制的是自建开源实例、或自建商业版/在线体验的**具体租户**控制台嵌入代码，再点击“验证聊天入口”，成功后保存并强制刷新后台。`https://trial.bailinghub.com` 根地址无法确定租户，根 `/widget.js` 返回 404 是预期隔离行为。手工安装后若版本卡片也未出现，请先重载 Web 站点实际使用的 PHP-FPM。

**Q: 中枢拉取工具失败/验签 401？**
A: 确认工具源访问策略是“签名保护（signed_required）”、两边签名密钥一致，并检查 base_url 是否为源站直连（不要套会改写 URI 的 CDN/网关）。刷新工具源时应看到正签 200 / 未签 401 / 错签 401。

**Q: AI 的权限是在中枢还是插件里配置？**
A: 都不是。聊天票据只携带 CRMEB 管理员 ID；插件在每次执行时读取 CRMEB 原生账号、角色和 `system_menus` 权限。你只需要在 CRMEB 后台维护原来的管理员角色，权限变更会直接作用于 AI。

**Q: 我在 custom_admin_js 里已有自己的 JS？**
A: 插件只维护 `/* bailinghub-widget-begin/end */` 标记块内的小型同源加载器，不会动你已有的代码；完整增强界面由无扩展名动态入口 `/bailing/admin-bundle` 提供，不会把 token 或签名密钥写入该公开脚本。

## License

Apache-2.0
