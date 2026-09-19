# Yggdrasil API for Blessing Skin

PKUMinecraft 维护的 Blessing Skin 皮肤站插件，基于 [bs-community/yggdrasil-api](https://github.com/bs-community/yggdrasil-api) 修改。

支持外置登录、正版账号绑定、MUA 联合认证，以及配合 Trusted Bridge Auth 在服务器之间切换身份。

## 能做什么

- **外置登录**：使用皮肤站账号登录 Minecraft，并使用皮肤站的皮肤和披风。
- **正版绑定**：把皮肤站账号与正版账号关联，之后可以用正版账号进入服务器，保留原有角色数据。
- **跨服身份切换**：进入正版服时使用绑定的正版身份，返回本地服时恢复原来的角色。两边的角色名可以不同，本地 UUID 不变。
- **MUA 联合认证**：支持联盟成员站之间的认证与角色同步。

正版绑定需要验证玩家确实拥有该正版账号。已有绑定继续有效，无需重新绑定。跨服跳转仍需满足两边服务器的白名单要求。

## 安装

适用于 Blessing Skin 5.x，当前使用 PHP 8.1 验证。

1. 将源码放入皮肤站的 `plugins/yggdrasil-api` 目录。
2. 在皮肤站后台启用插件。
3. 在皮肤站根目录执行：

```sh
php plugins/yggdrasil-api/install-premium.php
php artisan view:clear
```

已有站点升级时也要执行上述命令，并保留数据库、配置和密钥。

基础外置登录配置见[上游 Wiki](https://github.com/bs-community/yggdrasil-api/wiki)。正版绑定和跨服功能还需部署 **Trusted Bridge Auth 4.1.0**：皮肤站与本地代理配置相同的 API 密钥，两服代理分别使用 `local` 和 `premium` 身份模式。密钥不随本仓库提供。

## 玩家如何绑定

1. 登录皮肤站，创建角色，在“绑定正版账号”页面填写正版用户名。
2. 用任意支持正版登录的启动器登录该账号，使用 Minecraft 1.20.5 或更高版本连接服务器。
3. 将连接提示中的六位验证码填回皮肤站，确认绑定。

例如，皮肤站角色叫 `LocalPlayer`，正版账号叫 `PremiumPlayer`：绑定后可以用 `PremiumPlayer` 登录，继续使用本地服中 `LocalPlayer` 的背包和进度；跳转到正版服时则使用 `PremiumPlayer` 的正版身份。

## 许可

采用 [MIT License](LICENSE)，保留原作者 printempw 及上游贡献者的版权声明。
