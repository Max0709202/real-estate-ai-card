# Admin/Client システム実装ガイド

## 概要

このシステムでは、以下の2つの権限レベルを提供します：

- **Admin**: 全ての顧客の内容を編集・閲覧可能
- **Client**: 全ての顧客の内容を閲覧のみ（編集不可）

## データベースセットアップ

### 1. スキーマ更新の実行

```sql
-- backend/database/admin_system_updates.sql を実行
SOURCE backend/database/admin_system_updates.sql;
```

または、MySQLコマンドラインで：

```bash
mysql -u your_username -p your_database < backend/database/admin_system_updates.sql
```

### 2. 既存のadminsテーブル確認

既に `admins` テーブルが存在する場合、以下のカラムが追加されます：

- `password_reset_token` - パスワードリセットトークン
- `password_reset_token_expires_at` - トークン有効期限
- `email_verified` - メール認証フラグ
- `verification_token` - 認証トークン
- `verification_token_expires_at` - 認証トークン有効期限

## 管理者アカウントの作成

### 方法1: API経由で作成

```bash
curl -X POST http://your-domain/backend/api/admin/register.php \
  -H "Content-Type: application/json" \
  -d '{
    "email": "admin@example.com",
    "password": "SecurePassword123!",
    "role": "admin"
  }'
```

### 方法2: データベースに直接挿入

```sql
INSERT INTO admins (email, password_hash, role, email_verified) 
VALUES (
    'admin@example.com',
    '$2y$10$...', -- hashPassword()で生成されたハッシュ
    'admin',
    1
);
```

**注意**: パスワードは `hashPassword()` 関数でハッシュ化する必要があります。

## ログイン方法

### Admin/Client ログイン

1. ブラウザで `http://your-domain/frontend/admin/login.php` にアクセス
2. メールアドレスとパスワードを入力
3. メール認証が完了している必要があります

### メール認証

新規アカウント作成後：
1. 登録メールアドレスに認証メールが送信されます
2. メール内のリンクをクリックして認証を完了
3. 認証後、ログインページからログイン可能

## パスワードリセット

1. ログインページで「パスワードを忘れた場合」をクリック
2. メールアドレスを入力
3. リセットメールが送信されます
4. メール内のリンクから新しいパスワードを設定

## ダッシュボード機能

### Admin ダッシュボード

- **URL**: `http://your-domain/frontend/admin/dashboard.php`
- **機能**:
  - 全顧客のビジネスカード一覧表示
  - 各カードの編集（編集ボタン）
  - 最終変更日時・変更者IDの表示
  - カードの閲覧（新規タブで開く）

### Client ダッシュボード

- **URL**: `http://your-domain/frontend/admin/dashboard.php`
- **機能**:
  - 全顧客のビジネスカード一覧表示（閲覧のみ）
  - 編集ボタンは表示されません
  - 最終変更日時・変更者IDの表示
  - カードの閲覧（新規タブで開く）

## 変更追跡機能

### 自動追跡

全てのビジネスカード編集操作は自動的に追跡されます：

- **誰が変更したか**: Admin/Client/User
- **いつ変更したか**: タイムスタンプ
- **何を変更したか**: フィールド名、旧値、新値

### 変更履歴の表示

ダッシュボードの上部に、システム全体の最終変更情報が表示されます：

```
最終変更日時 2025年11月22日13時58分 変更者ID admin@example.com [ADMIN]
```

各カードの行にも、そのカードの最終変更情報が表示されます。

## セキュリティ

### 認証

- メール認証必須（新規アカウント）
- パスワードはハッシュ化して保存
- セッション管理による認証状態の維持

### 権限チェック

- Admin: 全てのカードを編集可能
- Client: 閲覧のみ（編集不可）
- 通常ユーザー: 自分のカードのみ編集可能

## ファイル構成

### 新規作成ファイル

```
frontend/admin/
├── login.php              # Admin/Client ログインページ
├── dashboard.php          # ダッシュボード（Admin/Client共通）
├── edit-card.php          # カード編集ページ（Admin専用）
├── logout.php             # ログアウト
├── forgot-password.php    # パスワードリセット依頼
├── reset-password.php     # パスワードリセット実行
└── verify.php             # メール認証ページ

backend/api/admin/
├── register.php           # Admin/Client アカウント作成API
└── verify-email.php       # メール認証API

backend/api/middleware/
└── admin-auth.php         # Admin認証ミドルウェア

backend/includes/
└── change-tracker.php    # 変更追跡ヘルパー関数
```

### 更新ファイル

- `backend/api/business-card/update.php` - Admin編集対応、変更追跡追加
- `backend/api/business-card/get.php` - Admin編集対応
- `frontend/edit.php` - Admin編集モード対応
- `frontend/assets/js/edit.js` - Admin編集モード対応

## トラブルシューティング

### ログインできない

1. メール認証が完了しているか確認
2. パスワードが正しいか確認
3. データベースの `admins` テーブルを確認

### 編集できない（Client権限）

- Client権限では編集できません
- Admin権限が必要です
- 管理者に連絡して権限を変更してもらってください

### 変更履歴が表示されない

1. `business_card_changes` テーブルが作成されているか確認
2. データベース接続を確認
3. エラーログを確認

## サポート

問題が発生した場合は、エラーログを確認してください：

- PHPエラーログ: `error_log()` で記録
- データベースエラー: MySQLエラーログ









