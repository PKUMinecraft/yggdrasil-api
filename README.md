# Yggdrasil API for Blessing Skin

PKUMinecraft 维护的 [bs-community/yggdrasil-api](https://github.com/bs-community/yggdrasil-api) 分支，提供 authlib-injector 外置登录、正版账号绑定、Trusted Bridge Auth 身份解析，以及 MUA 联合认证与多后端支持。

本项目是 Blessing Skin 的 PHP 插件，不是 Velocity 插件。跨服跳转与游戏客户端验证由另行部署的 Trusted Bridge Auth 完成。

## 环境与安装

- 插件声明支持 Blessing Skin Server `^5.0.0`；当前部署在 PHP 8.1 环境验证。
- 使用皮肤站已有的 Composer 依赖，不需要单独构建前端资源。
- 正版绑定和身份解析需要服务器能够访问 Mojang API 与 sessionserver，并通过 HTTPS 对外提供服务。
- 验证码绑定需要 Trusted Bridge Auth 4.1.0，以及 Minecraft Java Edition 1.20.5 或更高版本。

将源码放到皮肤站根目录的 `plugins/yggdrasil-api`，在后台启用插件，然后在皮肤站根目录运行：

```sh
php plugins/yggdrasil-api/install-premium.php
php artisan view:clear
```

首次安装必须先启用插件，创建基础表，再运行增量安装脚本。已有站点升级也需要运行该脚本；脚本可重复执行，只补充所需表和列，不修改已有绑定。按部署方式重载 PHP-FPM；使用常驻队列时运行 `php artisan queue:restart`。

升级时保留皮肤站数据库、`.env`、`storage` 和已有密钥。不要通过更换 UUID 算法来启用正版绑定，否则可能影响服务器按 UUID 读取的玩家数据。

## 正版绑定

1. 玩家登录皮肤站并创建角色，在“绑定正版账号”页面提交正版用户名。申请有效期为 15 分钟。
2. 使用任意支持正版登录的启动器，以该正版账号登录 Minecraft 并连接部署了 Trusted Bridge Auth 的服务器，无需外置登录。
3. 代理执行 Minecraft 加密登录握手，皮肤站通过 Mojang `hasJoined` 验证会话。客户端收到六位数字验证码，玩家在皮肤站填写后完成绑定。

查询用户名只能确认账号存在，不能证明账号归属；新绑定必须经过正版会话验证与网页确认。验证码支持前导零，有效期为 10 分钟，数据库仅保存与正版 UUID 关联的 SHA-256 摘要，成功使用后删除。同一正版 UUID 共用五次错误尝试额度；重新提交网页申请不会重置额度，新的正版验证会话才会重新发码。同一验证会话不能重复发码。

已有完成的绑定继续有效，身份接口标记为 `legacy`，无需重新绑定；通过新流程完成的绑定标记为 `login_code`。一个皮肤站账号只能绑定一个正版 UUID，一个正版 UUID 也只能绑定一个皮肤站账号。

## 跨服身份

本地角色与正版角色分别保留各自的 UUID 和名字。例如本地角色名为 `LocalPlayer`、绑定的正版名为 `PremiumPlayer`：

| 位置 | 身份来源 | UUID |
| --- | --- | --- |
| PKUMC / `identity-mode=local` | 皮肤站角色 | 已保存的本地 UUID，保留原有数据 |
| THUnion / `identity-mode=premium` | 绑定的 Mojang 账号 | 经绑定关系解析的正版 UUID |
| 返回本地服务器 | 重新检查绑定与角色归属 | 恢复原本的本地 UUID |

这不是允许任意指定正版 UUID 的 mapped 模式。皮肤站检查账号、绑定和角色归属；Trusted Bridge Auth 负责两端传输票据、签名、有效期及双向 TLS 验证。THUnion 的普通直接登录必须执行正版认证。两端应同时部署 Trusted Bridge Auth 4.1.0，不能与 4.0.x 混用桥接控制协议。

从正版服首次进入本地服时，必须有绑定该正版 UUID 的皮肤站账号和角色。没有绑定、没有角色、账号不可用时拒绝跳转；多个角色且没有可校验的返回 UUID 时也拒绝自动选择，玩家应先登录指定本地角色再跨服。两服白名单仍由各服务器独立管理，本插件不授予或绕过白名单。

## 密钥配置

仓库不包含生产密钥、密码、数据库、日志或 TLS 身份文件。以下密钥用途不同，不应互相复用：

| 配置 | 存储位置 | 用途 |
| --- | --- | --- |
| Bridge API Bearer 密钥 | 皮肤站 `storage/app/trusted-bridge-api.secret` | 允许本地代理查询绑定身份并验证正版会话 |
| Yggdrasil RSA 私钥 | 皮肤站数据库选项 `ygg_private_key` | 签名角色材质属性；首次启用自动生成 |
| MUA 成员密钥 | 数据库选项 `union_member_key` | 可选的联盟通信 |
| Bridge 票据签名密钥、TLS 私钥 | Velocity 的配置目录 | 两服代理之间的可信连接，不属于此 PHP 插件 |

首次配置时，可在皮肤站根目录用下面命令创建 API 密钥；文件已存在时命令会拒绝覆盖：

```sh
php -r 'umask(0077); $f = fopen("storage/app/trusted-bridge-api.secret", "x"); if (!$f) { exit(1); } fwrite($f, bin2hex(random_bytes(32)).PHP_EOL); fclose($f);'
```

将文件属主设为实际运行 PHP 的用户，并限制权限为 `600`。把相同内容通过安全渠道配置到本地 Velocity 的 `skin-api-secret-file` 所指文件中，不要粘贴进源码、命令示例、Issue 或日志。示例代理配置：

```properties
identity-mode=local
skin-api-url=https://skin.example.com/api/trusted-bridge/
skin-api-secret-file=skin-api.secret
```

接口拒绝缺失、错误或少于 64 字符的密钥。生产站点应关闭 `YGG_VERBOSE_LOG`，详细认证日志可能包含会话令牌；排障日志也不应提交到仓库。`.gitignore` 仅防止常见敏感文件被误提交，不能替代提交前检查。

## API

标准 Yggdrasil 路由位于 `routes.php`：

```text
POST /api/yggdrasil/authserver/authenticate
POST /api/yggdrasil/authserver/refresh
POST /api/yggdrasil/authserver/validate
POST /api/yggdrasil/authserver/invalidate
POST /api/yggdrasil/authserver/signout
POST /api/yggdrasil/sessionserver/session/minecraft/join
GET  /api/yggdrasil/sessionserver/session/minecraft/hasJoined
GET  /api/yggdrasil/sessionserver/session/minecraft/profile/{uuid}
POST /api/yggdrasil/api/profiles/minecraft
```

Bridge API 接收 JSON，必须带 `Authorization: Bearer <API_SECRET>`；在 `bootstrap.php` 注册，应用 `throttle:180,1` 限流：

| POST 路径 | 请求字段 | 返回内容 |
| --- | --- | --- |
| `/api/trusted-bridge/candidate` | `uuid`, `username` | 是否存在有效的待绑定申请；不代表认证成功 |
| `/api/trusted-bridge/verify-login` | `username`, `server_id` | Mojang 验证成功后返回 `code`, `expires_in` |
| `/api/trusted-bridge/resolve` | `direction`, `uuid`，可选 `local_uuid` | `local`, `premium`, `assurance` |

`direction=outbound` 的 `uuid` 是本地 UUID；`direction=inbound` 的 `uuid` 是正版 UUID，`local_uuid` 用于校验返回角色。UUID 接受有连字符或无连字符的格式，返回格式为 32 位小写十六进制。正版 profile 向 Mojang 查询并缓存 60 秒。

身份解析拒绝时使用结构化 `error`：`LOCAL_ACCOUNT_MISSING`、`BINDING_REQUIRED`、`CHARACTER_REQUIRED`、`CHARACTER_AMBIGUOUS`、`CHARACTER_CHANGED`、`ACCOUNT_UNAVAILABLE`。调用端应将未知错误与上游不可用转换为通用提示，不向玩家展示原始响应。

## MUA 与多后端

后台配置 `union_api_root` 与 `union_member_key` 后启用联盟同步。角色新增、删除、改名会同步到中央站；入站 `/api/union/member/*` 回调需要验签，公开 hello 端点除外。多后端会话与跨站同名处理沿用本分支实现。联盟 blacklist / OAuth2 扩展未实现，不应据此假定其可用。

## 验证

在已安装并启用该插件的 Blessing Skin 环境执行：

```sh
find plugins/yggdrasil-api -path '*/.git' -prune -o -name '*.php' -exec php -l {} \;
php plugins/yggdrasil-api/tests/premium-regression.php /path/to/blessing-skin
```

回归脚本使用 SQLite 内存数据库、内存缓存和模拟 Mojang 响应，检查旧绑定兼容、不同角色名、身份错误码、验证码过期、单次使用及错误次数限制。需要 PHP SQLite 扩展；脚本不会迁移生产数据库。完整客户端握手和跨服传输需运行 Trusted Bridge Auth 项目的集成测试。

## 上游与许可

保留原作者 printempw 及上游贡献者的版权声明，采用 [MIT License](LICENSE)。[CHANGELOG.md](CHANGELOG.md) 保留上游历史，本分支新增功能见本文及 Git 提交历史。基础外置登录配置可参考[上游 Wiki](https://github.com/bs-community/yggdrasil-api/wiki)。
