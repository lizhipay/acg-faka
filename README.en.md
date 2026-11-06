<div align="center">

<a href="https://faka.wiki/en/"><img src="https://raw.githubusercontent.com/lizhipay/acg-faka/refs/heads/main/favicon.ico" width="104" height="104" alt="ACG SHOP"></a>

# ACG SHOP

An open-source, self-hosted shop for digital goods, built on PHP 8.<br>
Automatic card-key delivery, plugin-based payments, sub-stores, store-to-store supply and an app store, ready out of the box.

[简体中文](README.md) | [English](README.en.md) | [繁體中文](README.zh-TW.md) | [日本語](README.ja.md)

![PHP](https://img.shields.io/badge/PHP-8.0%2B-777BB4?style=for-the-badge&labelColor=444)
![MySQL](https://img.shields.io/badge/MYSQL-5.7%2B-4479A1?style=for-the-badge&labelColor=444)
![Stars](https://img.shields.io/github/stars/lizhipay/acg-faka?style=for-the-badge&label=STARS&labelColor=444&color=F5A623)
![License](https://img.shields.io/badge/LICENSE-MIT-1BC47D?style=for-the-badge&labelColor=444)

[Documentation](https://faka.wiki/en/) · [Live demo](#live-demo) · [Installation](#installation) · [Report a bug](https://github.com/lizhipay/acg-faka/issues)

</div>

## Legal notice

> This program is open source under the MIT License and completely free. It was created to give developers a chance to learn and study. Using it for any commercial purpose without the required legal qualifications is strictly prohibited — in particular, using it to build a platform that sells goods.
>
> When you use or study this program, you must strictly comply with all applicable laws and regulations. We ask everyone to act within the law, respect and uphold it, and avoid causing harm to society.
>
> By using this program, you confirm that you fully understand and accept everything in this legal notice.

## Sponsored

🚀 **ARM AI** — a token service for GPT / Claude / Gemini / Grok with very low rates, a balance that stays valid long-term, and refunds with no questions asked. Try it: <https://ai.arm.moe/>

## Live demo

| | URL | Account | Password |
| --- | --- | --- | --- |
| Storefront | <https://demo.faka.wiki> | 为了明天美好而战斗 | 123456 |
| Admin panel | <https://demo.faka.wiki/admin> | demo@demo.com | 123456 |

## Features

- **Products and delivery** — automatic card-key delivery, manual delivery (optionally delivered the moment payment arrives), card-key pre-selection (buyers pick a specific key, with an optional surcharge), categories and SKU options, wholesale pricing, flash sales, coupons, purchase limits, login-only products, custom order fields and email notifications.
- **Pricing and members** — separate guest and member prices, fully custom member and merchant levels, and per-level pricing for every product.
- **Payments** — every payment gateway is a plugin, so almost any channel can be connected; account balance and online top-ups are built in.
- **Sub-stores** — members can open their own sub-store on its own domain, list their own products, or resell the main store's.
- **Referral earnings** — member levels double as agent levels: a referrer earns the difference between what the buyer paid and the price at the referrer's own level.
- **Store-to-store supply** — connect another store's products from the admin panel and restock and deliver them automatically from your balance.
- **App store** — install plugins and themes from the admin panel; the core upgrades online with one click.
- **Languages and currencies** — Simplified Chinese, Traditional Chinese, English and Japanese built in, with your own site currency and exchange rate.
- **Security** — two-factor authentication and passkey sign-in, a hidden admin entrance, a Content Security Policy and other layers of protection.
- **Look and feel** — full desktop and mobile support, with many themes in different styles available in the app store.
- **Extensible** — more than 80 hook points let plugins take over ordering, delivery, payment and more. For example: game items sent straight to the player's inventory, software licences issued and balances topped up automatically, or forum and community VIP status granted on purchase.

## Installation

### Option 1: Docker (recommended)

```bash
docker run -d --name faka -p 80:80 -v acg_data:/data --restart unless-stopped ghcr.io/lizhipay/acg-faka:latest
```

The image bundles MariaDB and Redis. Once it starts, open `http://your-server-ip` and the installer comes up with the database details already filled in. Everything the site stores lives in the `/data` volume, so always mount it. To use a separate MySQL server, see the [Docker guide](https://faka.wiki/en/install/docker).

### Option 2: aaPanel or manual install

| Item | Requirement |
| --- | --- |
| PHP | 8.0 or newer (8.2 recommended) |
| MySQL | 5.7 or newer (MariaDB 10.3+ also works) |
| PHP extensions | `gd`, `curl`, `pdo`, `pdo_mysql`, `date`, `json`, `session`, `zip`, `bcmath` |
| URL rewriting | Required, see below |

1. Get the source: `composer create-project lizhipay/acg-faka`, or download a zip from [GitHub](https://github.com/lizhipay/acg-faka) or [faka.wiki/download.php](https://faka.wiki/download.php).
2. Set up URL rewriting. Apache needs nothing, because the root directory already ships a `.htaccess`. On Nginx, paste the whole block below into the site configuration (aaPanel: Website → URL rewrite).

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

   Copy the whole block, not just the final `location /`: the rules above it keep logs, configuration files, keys and similar files from being served. IIS rules are in the [rewrite guide](https://faka.wiki/en/install/rewrite).
3. Make sure `config`, `runtime`, `kernel/Install` and `assets/cache` are writable, then open the home page and follow the installer.
4. The admin panel is at `https://your-domain/admin`.

Step-by-step guides, including Windows, are in the [installation docs](https://faka.wiki/en/install/).

## Upgrading

The current version number sits in the bottom-left corner of the admin panel. Click it to check for a new version and upgrade online in one click; this works for Docker installs too. Back up your database first. For manual upgrades, see the [upgrade guide](https://faka.wiki/en/install/upgrade).

## Development

Plugins, payment plugins, hooks and the API are covered in the [developer docs](https://faka.wiki/en/dev/).

## Community

- Telegram: <https://t.me/mcyofficial>
- QQ group: 868709021
- Bug reports: [GitHub Issues](https://github.com/lizhipay/acg-faka/issues)

## License

[MIT](LICENSE)
