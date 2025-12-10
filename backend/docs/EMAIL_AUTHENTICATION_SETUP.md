# Email Authentication Setup Guide

## 概要
このガイドでは、メール配信の信頼性と配信速度を向上させるためのSPF、DKIM、DMARC認証の設定方法を説明します。

## 現在の設定状況
- SMTPサーバー: Gmail (smtp.gmail.com)
- 送信元メールアドレス: ctha43843@gmail.com
- 認証状態: 基本的なSMTP認証のみ

## 推奨される改善方法

### 方法1: カスタムドメインのメール送信（推奨）

#### ステップ1: ドメインを準備
- 例: `rchukai.jp` または専用のサブドメイン `noreply@rchukai.jp`

#### ステップ2: SPFレコードの設定
DNSレコードに以下を追加（TXTレコード）:

```
v=spf1 include:_spf.google.com ~all
```

または、専用のメールサービスを使用する場合:

```
v=spf1 include:sendgrid.net ~all
```

#### ステップ3: DKIM署名の設定
1. メールサービスプロバイダー（SendGrid、Mailgunなど）からDKIMキーを取得
2. DNSにTXTレコードとして追加

例（SendGridの場合）:
```
Name: s1._domainkey.rchukai.jp
Value: [プロバイダーから提供されたキー]
```

#### ステップ4: DMARCポリシーの設定
DNSにDMARCレコードを追加:

```
Name: _dmarc.rchukai.jp
Type: TXT
Value: v=DMARC1; p=none; rua=mailto:dmarc@rchukai.jp
```

初期設定後、監視しながら段階的に厳格化:
- `p=none` → `p=quarantine` → `p=reject`

### 方法2: Gmail APIの使用（簡単）

現在のGmail SMTPの代わりに、Gmail APIを使用することで認証が強化されます。

1. Google Cloud Consoleでプロジェクトを作成
2. Gmail APIを有効化
3. OAuth 2.0認証情報を作成
4. PHPMailerのGmail APIプラグインを使用

## メール配信速度の改善

### 現在実装済み
- ✅ メール送信時間のロギング
- ✅ ビジネス/個人メールの分類
- ✅ 配信統計の追跡

### 今後の改善案

1. **メール送信キューの実装**
   - 非同期処理による高速化
   - バッチ送信

2. **専用メールサービスの使用**
   - SendGrid: 高い配信率、詳細な分析
   - Mailgun: 開発者向け、API中心
   - AWS SES: コスト効率が良い

3. **キャッシュと最適化**
   - SMTP接続のプーリング
   - DNSルックアップのキャッシュ

## ログの確認方法

1. 管理画面にアクセス: `/frontend/admin/email-logs.php`
2. フィルター機能で検索:
   - メールアドレス
   - ビジネス/個人タイプ
   - 送信ステータス
   - メールタイプ

3. 統計情報の確認:
   - 平均送信時間
   - ビジネス vs 個人の比較
   - 失敗率

## トラブルシューティング

### メールが届かない場合
1. ログでステータスを確認
2. エラーメッセージを確認
3. SPF/DKIM設定を確認: https://mxtoolbox.com/spf.aspx

### 配信が遅い場合
1. 平均送信時間を確認
2. ビジネスメールは個人より遅くなる傾向がある
3. 特定のドメインで問題がある場合は、そのドメインの設定を確認

## 次のステップ

1. データベースマイグレーションを実行:
   ```sql
   SOURCE backend/database/migrations/add_email_logs_table.sql;
   ```

2. 管理画面でログを確認: `/frontend/admin/email-logs.php`

3. 数日間ログを収集し、配信パターンを分析

4. 必要に応じてSPF/DKIM/DMARCを設定

