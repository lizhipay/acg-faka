<div align="center">

<a href="https://faka.wiki/zh-cn/"><img src="https://raw.githubusercontent.com/lizhipay/acg-faka/refs/heads/main/favicon.ico" width="104" height="104" alt="异次元店铺系统"></a>

# 异次元店铺系统

基于 PHP 8 的开源自动发卡与个人店铺系统。<br>
卡密自动发货、插件化支付、分站、店铺对接、应用商店，开箱即用。

[简体中文](README.md) | [English](README.en.md) | [繁體中文](README.zh-TW.md) | [日本語](README.ja.md)

![PHP](https://img.shields.io/badge/PHP-8.0%2B-777BB4?style=for-the-badge&labelColor=444)
![MySQL](https://img.shields.io/badge/MYSQL-5.7%2B-4479A1?style=for-the-badge&labelColor=444)
![Stars](https://img.shields.io/github/stars/lizhipay/acg-faka?style=for-the-badge&label=STARS&labelColor=444&color=F5A623)
![License](https://img.shields.io/badge/LICENSE-MIT-1BC47D?style=for-the-badge&labelColor=444)

[文档](https://faka.wiki/zh-cn/) · [在线演示](#在线演示) · [安装](#安装) · [问题反馈](https://github.com/lizhipay/acg-faka/issues)

</div>

## 法律声明

> 本商城程序基于 MIT 协议开源，并且完全免费。该程序的初衷是为开发者提供学习和研究的机会。未取得合法资质，严禁将本程序用于任何商业用途，尤其是禁止利用本程序搭建平台进行商品销售。
>
> 用户在使用或学习本程序时，必须严格遵守法律法规。我们提倡依法行事，尊重法律，坚守法律，避免对社会产生不良影响。
>
> 使用本程序即表示您已充分理解并同意本法律声明的所有内容。

## 广告

🚀 **ARM AI** — GPT / Claude / Gemini / Grok Token 服务平台，超低倍率、余额长期有效并支持无理由退款。立即体验：<https://ai.arm.moe/>

## 在线演示

| | 地址 | 账号 | 密码 |
| --- | --- | --- | --- |
| 前台 | <https://demo.faka.wiki> | 为了明天美好而战斗 | 123456 |
| 后台 | <https://demo.faka.wiki/admin> | demo@demo.com | 123456 |

## 功能一览

- **商品与发货**：卡密自动发货、手动发货（可开启「付款即发货」）、卡密预选（买家自选卡号，可单独加价）、种类与 SKU 规格、批发价、限时秒杀、优惠券、限购、强制登录购买、自定义下单控件、邮件通知。
- **价格与会员**：游客价与会员价分开设置，会员等级、商户等级完全自定义，每个商品都能按会员等级单独定价。
- **支付**：支付网关全部插件化，几乎任何支付渠道都能接入；内置余额支付与在线充值。
- **分站**：会员可以开通自己的分站并绑定独立域名，自行上架商品，也可以代售主站商品。
- **推广分成**：会员等级即代理等级，推广人赚取买家实付金额与自身等级价之间的差价。
- **店铺对接**：在后台直接对接其他店铺的商品，用余额自动进货、自动发货。
- **应用商店**：在后台安装插件与模板；核心程序可一键在线升级。
- **多语言与多币种**：内置简体中文、繁体中文、English、日本語，可自定义站点货币与汇率。
- **安全**：两步验证与通行密钥（Passkey）登录、后台安全入口、内容安全策略（CSP）等多层防护。
- **界面**：PC 与手机端完整适配，应用商店里有多套风格各异的模板。
- **二次开发**：80 多个 Hook 埋点，插件可以接管下单、发货、支付等流程。比如：游戏道具购买后即时发到玩家背包、商业软件自动授权与余额充值、论坛 / 社区 VIP 自动开通。

## 安装

### 方式一：Docker（推荐）

```bash
docker run -d --name faka -p 80:80 -v acg_data:/data --restart unless-stopped ghcr.io/lizhipay/acg-faka:latest
```

镜像自带 MariaDB 与 Redis，启动后打开 `http://服务器IP` 即可进入安装向导，数据库信息已经自动填好。站点的全部数据都在 `/data` 卷里，务必挂载。需要使用独立的 MySQL 时，请参考 [Docker 安装文档](https://faka.wiki/zh-cn/install/docker)。

### 方式二：宝塔面板 / 手动安装

| 项目 | 要求 |
| --- | --- |
| PHP | 8.0 及以上（推荐 8.2） |
| MySQL | 5.7 及以上（MariaDB 10.3+ 亦可） |
| PHP 扩展 | `gd`、`curl`、`pdo`、`pdo_mysql`、`date`、`json`、`session`、`zip`、`bcmath` |
| 伪静态 | 必须配置，见下方 |

1. 获取源码：`composer create-project lizhipay/acg-faka`，或从 [GitHub](https://github.com/lizhipay/acg-faka)、[faka.wiki/download.php](https://faka.wiki/download.php) 下载压缩包。
2. 配置伪静态：Apache 无需配置，根目录已自带 `.htaccess`；Nginx 请把下面整段粘进站点配置（宝塔：网站 → 伪静态）。

   ```nginx
   if ($uri ~* "^/(?!(?-i:index\.php)(/|$)).*\.php(/|$)")             { return 404; }

   location ~* ^/(runtime|kernel|config|vendor)/                { return 404; }
   location ~  (^|/)(runtime|\.acgcache)(/|$)                   { return 404; }
   location ~  /\.(?!well-known)                                { return 404; }
   location ~* \.(log|sql|sqlite|db|db-wal|db-shm|bak|old|save|orig|swp|swo|tmp|ini|lock|key|pem|crt|cer|der|p12|pfx|p8|keystore|jks|asc|gpg|ppk|env|secret)(\.[0-9]+)?$  { return 404; }
   location ~* (~|composer\.(json|lock)|package(-lock)?\.json)$ { return 404; }
   location / {
       try_files $uri $uri/ /index.php?s=$uri&$args;
   }
   ```

   请整段复制，不要只抄最后的 `location /`：前面几条负责挡住日志、配置、密钥等不该被访问的文件。IIS 规则见[伪静态文档](https://faka.wiki/zh-cn/install/rewrite)。
3. 确认 `config`、`runtime`、`kernel/Install`、`assets/cache` 目录可写，然后访问网站首页，按安装向导完成安装。
4. 后台地址：`https://你的域名/admin`

更详细的步骤（含 Windows）见[安装文档](https://faka.wiki/zh-cn/install/)。

## 升级

后台左下角显示当前版本号，点击即可检查新版本并一键在线升级，Docker 部署同样适用。升级前请先备份数据库；手动升级的方法见[升级文档](https://faka.wiki/zh-cn/install/upgrade)。

## 二次开发

插件、支付插件、Hook 与 API 对接的说明见[开发者文档](https://faka.wiki/zh-cn/dev/)。

## 交流与反馈

- Telegram：<https://t.me/mcyofficial>
- QQ 群：868709021
- 问题反馈：[GitHub Issues](https://github.com/lizhipay/acg-faka/issues)

## 开源协议

[MIT](LICENSE)
