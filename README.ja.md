<div align="center">

<a href="https://faka.wiki/ja/"><img src="https://raw.githubusercontent.com/lizhipay/acg-faka/refs/heads/main/favicon.ico" width="104" height="104" alt="異次元ストアシステム"></a>

# 異次元ストアシステム

PHP 8 で作られた、オープンソースの自動発送ストアシステム。<br>
カードキーの自動発送、プラグイン式の決済、サブストア、ストア間連携、アプリストアをすぐに使えます。

[简体中文](README.md) | [English](README.en.md) | [繁體中文](README.zh-TW.md) | [日本語](README.ja.md)

![PHP](https://img.shields.io/badge/PHP-8.0%2B-777BB4?style=for-the-badge&labelColor=444)
![MySQL](https://img.shields.io/badge/MYSQL-5.7%2B-4479A1?style=for-the-badge&labelColor=444)
![Stars](https://img.shields.io/github/stars/lizhipay/acg-faka?style=for-the-badge&label=STARS&labelColor=444&color=F5A623)
![License](https://img.shields.io/badge/LICENSE-MIT-1BC47D?style=for-the-badge&labelColor=444)

[ドキュメント](https://faka.wiki/ja/) · [デモ](#デモ) · [インストール](#インストール) · [不具合の報告](https://github.com/lizhipay/acg-faka/issues)

</div>

## 法的声明

> 本プログラムは MIT ライセンスで公開されているオープンソースソフトウェアで、完全に無料です。本プログラムは、開発者に学習と研究の機会を提供することを目的としています。必要な法的資格を取得せずに、本プログラムをいかなる商業目的にも使用することを固く禁じます。特に、本プログラムを使って商品を販売するプラットフォームを構築することは禁止します。
>
> 本プログラムを使用または学習する際は、法令を厳守してください。私たちは法に則って行動し、法を尊重・遵守し、社会に悪影響を与えないことを求めます。
>
> 本プログラムを使用した時点で、本法的声明のすべての内容を十分に理解し、同意したものとみなします。

## 広告

🚀 **ARM AI** — GPT / Claude / Gemini / Grok のトークンサービス。超低レート、残高は長期有効、理由を問わない返金にも対応しています。今すぐ試す：<https://ai.arm.moe/>

## デモ

| | URL | アカウント | パスワード |
| --- | --- | --- | --- |
| ストア | <https://demo.faka.wiki> | 为了明天美好而战斗 | 123456 |
| 管理画面 | <https://demo.faka.wiki/admin> | demo@demo.com | 123456 |

## 主な機能

- **商品と発送**：カードキーの自動発送、手動発送（「支払い後すぐ発送」も設定可能）、カードキーの事前選択（購入者がキーを選べ、個別に追加料金も設定可能）、種類と SKU、まとめ買い価格、タイムセール、クーポン、購入数制限、ログイン必須の商品、注文時の入力項目のカスタマイズ、メール通知。
- **価格と会員**：ゲスト価格と会員価格を個別に設定でき、会員ランクと販売者ランクは自由に定義できます。商品ごとに会員ランク別の価格も設定可能です。
- **決済**：決済ゲートウェイはすべてプラグイン式で、ほぼあらゆる決済手段を接続できます。残高払いとオンラインチャージを標準搭載しています。
- **サブストア**：会員は独自ドメインで自分のサブストアを開設し、自分の商品を出品したり、本店の商品を代理販売したりできます。
- **紹介報酬**：会員ランクがそのまま代理店ランクになり、紹介者は購入者の支払額と自分のランク価格との差額を受け取ります。
- **ストア間連携**：管理画面から他のストアの商品を接続し、残高で自動的に仕入れ・発送できます。
- **アプリストア**：管理画面からプラグインやテーマをインストールでき、本体はワンクリックでオンラインアップグレードできます。
- **多言語・多通貨**：簡体字中国語・繁体字中国語・English・日本語を標準搭載し、サイトの通貨と為替レートも自由に設定できます。
- **セキュリティ**：二段階認証とパスキーによるログイン、管理画面の隠し入口、コンテンツセキュリティポリシー（CSP）など、多層的に保護しています。
- **画面**：PC とスマートフォンに完全対応し、アプリストアには雰囲気の異なるテーマが多数そろっています。
- **拡張性**：80 以上のフックポイントがあり、注文・発送・決済などの処理をプラグインで置き換えられます。たとえば、購入したゲームアイテムをプレイヤーの持ち物へ即時に送る、業務ソフトのライセンス発行や残高チャージを自動化する、フォーラムやコミュニティの VIP を自動で付与する、といった使い方ができます。

## インストール

### 方法 1：Docker（おすすめ）

```bash
docker run -d --name faka -p 80:80 -v acg_data:/data --restart unless-stopped ghcr.io/lizhipay/acg-faka:latest
```

イメージには MariaDB と Redis が含まれています。起動後に `http://サーバーのIP` を開くとインストールウィザードが表示され、データベース情報は入力済みです。サイトのデータはすべて `/data` ボリュームに保存されるので、必ずマウントしてください。独立した MySQL を使う場合は [Docker のドキュメント](https://faka.wiki/ja/install/docker)を参照してください。

### 方法 2：aaPanel（宝塔）／手動インストール

| 項目 | 要件 |
| --- | --- |
| PHP | 8.0 以上（8.2 推奨） |
| MySQL | 5.7 以上（MariaDB 10.3 以上も可） |
| PHP 拡張 | `gd`、`curl`、`pdo`、`pdo_mysql`、`date`、`json`、`session`、`zip`、`bcmath` |
| URL リライト | 必須（下記参照） |

1. ソースを入手します：`composer create-project lizhipay/acg-faka`、または [GitHub](https://github.com/lizhipay/acg-faka) か [faka.wiki/download.php](https://faka.wiki/download.php) から zip をダウンロードします。
2. URL リライトを設定します。Apache はルートに `.htaccess` が含まれているので設定不要です。Nginx の場合は、次のブロックをまるごとサイト設定に貼り付けてください（aaPanel：Website → URL rewrite）。

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

   最後の `location /` だけでなく、必ずブロック全体をコピーしてください。前半のルールが、ログ・設定ファイル・鍵など公開してはいけないファイルへのアクセスを防いでいます。IIS 用のルールは[リライトのドキュメント](https://faka.wiki/ja/install/rewrite)にあります。
3. `config`、`runtime`、`kernel/Install`、`assets/cache` に書き込みできることを確認してからトップページを開き、インストールウィザードに沿ってインストールします。
4. 管理画面：`https://あなたのドメイン/admin`

Windows を含む詳しい手順は[インストールのドキュメント](https://faka.wiki/ja/install/)を参照してください。

## アップグレード

管理画面の左下に現在のバージョンが表示されます。クリックすると新しいバージョンを確認でき、ワンクリックでオンラインアップグレードできます（Docker でも同様です）。アップグレード前にデータベースをバックアップしてください。手動での方法は[アップグレードのドキュメント](https://faka.wiki/ja/install/upgrade)を参照してください。

## 開発者向け

プラグイン、決済プラグイン、フック、API 連携については[開発者向けドキュメント](https://faka.wiki/ja/dev/)を参照してください。

## コミュニティ

- Telegram：<https://t.me/mcyofficial>
- QQ グループ：868709021
- 不具合の報告：[GitHub Issues](https://github.com/lizhipay/acg-faka/issues)

## ライセンス

[MIT](LICENSE)
