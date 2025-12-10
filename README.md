# 不動産AI名刺システム

不動産エージェント向けのデジタル名刺作成システム。QRコード付きで、不動産テックツールと連携し、顧客とのコミュニケーションを強化します。

## 機能概要

1. **デジタル名刺作成**: 個人情報、会社情報、挨拶文などを入力してデジタル名刺を作成
2. **QRコード生成**: 自動でQRコードを生成し、名刺に印刷可能
3. **不動産テックツール連携**: 全国マンションDB、物件提案ロボ、AI査定などのツールと統合
4. **コミュニケーション機能**: LINE、SNS等へのワンタップ連携
5. **管理画面**: ユーザー管理、アクセスログ、決済管理

## システム要件

- PHP 7.4以上
- MySQL 5.7以上
- Apache/Nginx
- Composer (Stripe SDK等の依存関係)

## インストール

### 1. データベースのセットアップ

```bash
mysql -u root -p < backend/database/schema.sql
```

### 2. 設定ファイルの編集

`backend/config/config.php` と `backend/config/database.php` を環境に合わせて編集してください。

### 3. ディレクトリ権限の設定

```bash
chmod -R 755 backend/uploads
mkdir -p backend/uploads/qr_codes
mkdir -p backend/uploads/photo
mkdir -p backend/uploads/logo
```

### 4. Stripe設定（本番環境）

環境変数または `backend/config/config.php` でStripeキーを設定してください。

```php
define('STRIPE_PUBLISHABLE_KEY', 'your_publishable_key');
define('STRIPE_SECRET_KEY', 'your_secret_key');
define('STRIPE_WEBHOOK_SECRET', 'your_webhook_secret');
```

### 5. Composer依存関係のインストール

```bash
cd backend
composer install
```

必要なパッケージ:
- stripe/stripe-php
- phpqrcode/qrcode (QRコード生成用)

## ディレクトリ構造

```
.
├── backend/
│   ├── api/              # APIエンドポイント
│   │   ├── auth/         # 認証関連
│   │   ├── business-card/ # ビジネスカード関連
│   │   ├── admin/        # 管理画面API
│   │   ├── payment/      # 決済処理
│   │   └── qr-code/      # QRコード生成
│   ├── config/           # 設定ファイル
│   ├── database/         # データベーススキーマ
│   ├── includes/         # 共通関数
│   └── uploads/          # アップロードファイル
├── frontend/
│   ├── admin/            # 管理画面
│   ├── assets/           # CSS, JS, 画像
│   ├── index.php         # ランディングページ
│   ├── register.php      # 登録フォーム
│   ├── card.php          # 公開名刺表示
│   └── edit.php          # 編集画面
└── README.md
```

## APIエンドポイント

### 認証
- `POST /backend/api/auth/register.php` - ユーザー登録
- `POST /backend/api/auth/login.php` - ログイン
- `POST /backend/api/auth/logout.php` - ログアウト
- `GET /backend/api/auth/verify.php` - メール認証

### ビジネスカード
- `GET /backend/api/business-card/get.php` - ビジネスカード取得
- `POST /backend/api/business-card/update.php` - ビジネスカード更新
- `GET /backend/api/business-card/public.php?slug=xxx` - 公開名刺取得
- `POST /backend/api/business-card/upload.php` - ファイルアップロード

### 決済
- `POST /backend/api/payment/create-intent.php` - 決済意図作成
- `POST /backend/api/payment/webhook.php` - Stripe Webhook

### 管理画面
- `POST /backend/api/admin/login.php` - 管理者ログイン
- `GET /backend/api/admin/users.php` - ユーザー一覧取得
- `GET /backend/api/admin/export-csv.php` - CSV出力

## 価格設定

### 新規ユーザー
- 初期費用: ¥30,000（税別）
- 月額費用: ¥500（税別）

### 既存ユーザー
- 初期費用: ¥20,000（税別）

### 無料版
- 決済不要で作成可能（限定機能）

## 主要機能の実装状況

- [x] ユーザー登録・認証
- [x] ビジネスカード作成・編集
- [x] テックツール連携
- [x] QRコード生成
- [x] 決済処理（Stripe統合準備完了）
- [x] 管理画面
- [x] アクセスログ
- [ ] OCR機能（名刺読み取り）
- [ ] メール送信機能
- [ ] 画像リサイズ機能

## 注意事項

1. 本番環境では必ず環境変数を使用して機密情報を管理してください
2. StripeのWebhook URLを設定し、決済完了通知を受信できるようにしてください
3. メール送信機能は実装が必要です（現在はコメントアウト）
4. QRコード生成ライブラリ（phpqrcode等）のインストールが必要です

## ライセンス

プロプライエタリ

## 問い合わせ

リニュアル仲介株式会社
info@rchukai.jp

