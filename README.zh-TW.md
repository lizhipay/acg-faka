<div align="center">

<a href="https://faka.wiki/zh-tw/"><img src="https://raw.githubusercontent.com/lizhipay/acg-faka/refs/heads/main/favicon.ico" width="104" height="104" alt="異次元店鋪系統"></a>

# 異次元店鋪系統

以 PHP 8 打造的開源自動發卡與個人商店系統。<br>
卡密自動發貨、外掛式金流、分站、商店對接、應用商店，開箱即用。

[简体中文](README.md) | [English](README.en.md) | [繁體中文](README.zh-TW.md) | [日本語](README.ja.md)

![PHP](https://img.shields.io/badge/PHP-8.0%2B-777BB4?style=for-the-badge&labelColor=444)
![MySQL](https://img.shields.io/badge/MYSQL-5.7%2B-4479A1?style=for-the-badge&labelColor=444)
![Stars](https://img.shields.io/github/stars/lizhipay/acg-faka?style=for-the-badge&label=STARS&labelColor=444&color=F5A623)
![License](https://img.shields.io/badge/LICENSE-MIT-1BC47D?style=for-the-badge&labelColor=444)

[文件](https://faka.wiki/zh-tw/) · [線上示範](#線上示範) · [安裝](#安裝) · [問題回報](https://github.com/lizhipay/acg-faka/issues)

</div>

## 法律聲明

> 本商城程式基於 MIT 授權開源，並且完全免費。本程式的初衷是為開發者提供學習與研究的機會。未取得合法資質，嚴禁將本程式用於任何商業用途，尤其禁止利用本程式架設平台進行商品銷售。
>
> 使用者在使用或學習本程式時，必須嚴格遵守法律法規。我們提倡依法行事，尊重法律、堅守法律，避免對社會造成不良影響。
>
> 使用本程式即表示您已充分理解並同意本法律聲明的所有內容。

## 廣告

🚀 **ARM AI** — GPT / Claude / Gemini / Grok Token 服務平台，超低倍率、餘額長期有效並支援無理由退款。立即體驗：<https://ai.arm.moe/>

## 線上示範

| | 網址 | 帳號 | 密碼 |
| --- | --- | --- | --- |
| 前台 | <https://demo.faka.wiki> | 为了明天美好而战斗 | 123456 |
| 後台 | <https://demo.faka.wiki/admin> | demo@demo.com | 123456 |

## 功能一覽

- **商品與發貨**：卡密自動發貨、手動發貨（可開啟「付款即發貨」）、卡密預選（買家自選卡號，可另外加價）、種類與 SKU 規格、批發價、限時秒殺、優惠券、限購、強制登入購買、自訂下單欄位、郵件通知。
- **價格與會員**：訪客價與會員價分開設定，會員等級、商戶等級完全自訂，每個商品都能依會員等級單獨定價。
- **金流**：付款閘道全部外掛化，幾乎任何付款管道都能串接；內建餘額付款與線上儲值。
- **分站**：會員可以開設自己的分站並綁定獨立網域，自行上架商品，也可以代售主站商品。
- **推廣分潤**：會員等級即代理等級，推廣人賺取買家實付金額與自身等級價之間的差價。
- **商店對接**：在後台直接對接其他商店的商品，以餘額自動進貨、自動發貨。
- **應用商店**：在後台安裝外掛與主題；核心程式可一鍵線上升級。
- **多語系與多幣別**：內建簡體中文、繁體中文、English、日本語，可自訂網站幣別與匯率。
- **安全**：兩步驟驗證與通行金鑰（Passkey）登入、後台安全入口、內容安全政策（CSP）等多層防護。
- **介面**：電腦與手機完整適配，應用商店裡有多套風格各異的主題。
- **二次開發**：80 多個 Hook 掛載點，外掛可以接管下單、發貨、付款等流程。例如：遊戲道具購買後即時發到玩家背包、商業軟體自動授權與餘額儲值、論壇／社群 VIP 自動開通。

## 安裝

### 方式一：Docker（推薦）

```bash
docker run -d --name faka -p 80:80 -v acg_data:/data --restart unless-stopped ghcr.io/lizhipay/acg-faka:latest
```

映像檔內建 MariaDB 與 Redis，啟動後開啟 `http://伺服器IP` 即可進入安裝精靈，資料庫資訊已自動填好。網站的所有資料都在 `/data` 磁碟區裡，務必掛載。需要使用獨立的 MySQL 時，請參考 [Docker 安裝文件](https://faka.wiki/zh-tw/install/docker)。

### 方式二：寶塔面板（aaPanel）／手動安裝

| 項目 | 要求 |
| --- | --- |
| PHP | 8.0 以上（推薦 8.2） |
| MySQL | 5.7 以上（MariaDB 10.3+ 亦可） |
| PHP 擴充 | `gd`、`curl`、`pdo`、`pdo_mysql`、`date`、`json`、`session`、`zip`、`bcmath` |
| URL 重寫（偽靜態） | 必須設定，見下方 |

1. 取得原始碼：`composer create-project lizhipay/acg-faka`，或從 [GitHub](https://github.com/lizhipay/acg-faka)、[faka.wiki/download.php](https://faka.wiki/download.php) 下載壓縮檔。
2. 設定 URL 重寫：Apache 不用設定，根目錄已附 `.htaccess`；Nginx 請把下面整段貼進網站設定（寶塔：網站 → 偽靜態）。

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

   請整段複製，不要只抄最後的 `location /`：前面幾條負責擋住日誌、設定檔、金鑰等不該被存取的檔案。IIS 規則見[偽靜態文件](https://faka.wiki/zh-tw/install/rewrite)。
3. 確認 `config`、`runtime`、`kernel/Install`、`assets/cache` 目錄可寫入，然後開啟網站首頁，依安裝精靈完成安裝。
4. 後台網址：`https://你的網域/admin`

更詳細的步驟（含 Windows）見[安裝文件](https://faka.wiki/zh-tw/install/)。

## 升級

後台左下角會顯示目前版本號，點擊即可檢查新版本並一鍵線上升級，Docker 部署同樣適用。升級前請先備份資料庫；手動升級的方法見[升級文件](https://faka.wiki/zh-tw/install/upgrade)。

## 二次開發

外掛、金流外掛、Hook 與 API 串接的說明見[開發者文件](https://faka.wiki/zh-tw/dev/)。

## 交流與回報

- Telegram：<https://t.me/mcyofficial>
- QQ 群：868709021
- 問題回報：[GitHub Issues](https://github.com/lizhipay/acg-faka/issues)

## 開源授權

[MIT](LICENSE)
