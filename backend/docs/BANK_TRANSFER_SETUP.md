# Bank Transfer Setup and Testing Guide

## 概要
このプロジェクトでは、Stripeを使用した日本の銀行振込（JP Bank Transfer）機能が実装されています。

## 現在の実装状況

✅ **既に実装済み:**
- 銀行振込のUI表示（登録フォームのStep 6）
- Payment Intent作成時の銀行振込対応
- 振込先情報表示ページ（`bank-transfer-info.php`）
- データベーススキーマ（`payment_method` フィールド）
- Webhook処理（振込確認後の自動処理）

## Stripe設定が必要な項目

### 1. Stripe Dashboardでの設定確認

1. **Stripe Dashboard にログイン**
   - https://dashboard.stripe.com/

2. **日本での銀行振込機能の有効化**
   - Settings → Payment methods
   - 「Bank transfers」セクションで「Japanese bank transfers」を有効化
   - テストモードと本番モードの両方で有効化が必要

3. **Cash Balanceの有効化**（必要に応じて）
   - Settings → Payment methods
   - 「Customer balance」を有効化
   - 銀行振込には `customer_balance` が必要

### 2. Webhookエンドポイントの設定

1. **Webhookエンドポイントを設定**
   - Developers → Webhooks
   - エンドポイントURL: `https://your-domain.com/backend/api/payment/webhook.php`
   - 以下のイベントを選択:
     - `payment_intent.succeeded`
     - `payment_intent.payment_failed`
     - `payment_intent.canceled`
     - `customer.balance_transaction.created`（銀行振込確認用）

2. **Webhookシークレットを取得**
   - Webhookエンドポイント作成後、シークレットをコピー
   - `backend/config/config.php` または環境変数に設定

## 銀行振込の有効化確認

現在のコードでは、無料ユーザー以外は銀行振込オプションが表示されます。

```php
// frontend/register.php (line 662-667)
<?php if ($userType !== 'free'): ?>
<label class="payment-option">
    <input type="radio" name="payment_method" value="bank_transfer">
    <span>お振込み</span>
</label>
<?php endif; ?>
```

**銀行振込を常に有効にする場合:**
- 上記の `if ($userType !== 'free')` 条件を削除するか、常に表示したい場合は条件を外す

## テスト方法

### ステップ1: テスト環境での確認

1. **テストモードで登録フローを開始**
   ```
   http://your-domain.com/frontend/new_register.php?type=new
   ```

2. **すべてのステップを完了**
   - Step 1: アカウント作成
   - Step 2-5: 各種情報入力
   - Step 6: 決済画面

3. **銀行振込を選択**
   - 「お振込み」ラジオボタンを選択
   - 「次へ」ボタンをクリック

4. **振込先情報の確認**
   - `bank-transfer-info.php` ページが表示される
   - 以下の情報が表示されることを確認:
     - 振込金額
     - 銀行名
     - 支店名・支店番号
     - 口座種別（普通/当座）
     - 口座番号
     - 口座名義
     - 参照番号（振込人名義に追加する番号）

### ステップ2: Stripeテストモードでの確認

1. **Stripe Dashboard → Payments**
   - テストモードで作成されたPayment Intentを確認
   - ステータスが `requires_action` になっていることを確認

2. **銀行振込のテスト**
   - Stripeが提供するテスト用の銀行口座情報を使用
   - 実際の振込は不要（Stripeが自動でシミュレート）

### ステップ3: 振込確認のテスト

1. **Stripe Dashboard → Developers → Events**
   - テストイベントを送信:
     - Payment Intent → `payment_intent.succeeded` を手動でトリガー

2. **データベースの確認**
   ```sql
   SELECT * FROM payments WHERE payment_method = 'bank_transfer';
   SELECT * FROM business_cards WHERE payment_status = 'paid';
   ```

3. **自動処理の確認**
   - 振込確認後、以下が自動実行される:
     - `payment_status` が `pending` → `completed` に更新
     - `business_cards.is_published` が `TRUE` に更新
     - QRコード発行のトリガー

### ステップ4: 本番環境でのテスト

⚠️ **本番環境でのテスト時は注意:**
- 実際の振込が発生するため、少額でテスト
- 振込確認まで1-2営業日かかる場合があります

## トラブルシューティング

### 問題1: 銀行振込オプションが表示されない

**解決方法:**
- `frontend/register.php` の条件を確認
- `$userType` が `'free'` でないことを確認

### 問題2: 振込先情報が取得できない

**エラーログを確認:**
```php
// backend/api/payment/create-intent.php のエラー
error_log("Create Payment Intent Error: " . $e->getMessage());
```

**確認事項:**
- Stripe APIキーが正しく設定されているか
- テストモード/本番モードの設定が正しいか
- Stripeで日本銀行振込が有効化されているか

### 問題3: 振込確認後にステータスが更新されない

**Webhookの確認:**
1. Stripe Dashboard → Developers → Webhooks
2. イベントログでエラーを確認
3. Webhookシークレットが正しく設定されているか確認

**手動で確認:**
```sql
-- 未完了の銀行振込を確認
SELECT * FROM payments 
WHERE payment_method = 'bank_transfer' 
AND payment_status = 'pending';
```

## 実装されている機能

### 1. Payment Intent作成
```php
// backend/api/payment/create-intent.php (line 194-227)
// 銀行振込用のPaymentIntentを作成
$paymentIntent = PaymentIntent::create([
    'payment_method_types' => ['customer_balance'],
    'payment_method_options' => [
        'customer_balance' => [
            'funding_type' => 'bank_transfer',
            'bank_transfer' => [
                'type' => 'jp_bank_transfer'
            ]
        ]
    ]
]);
```

### 2. 振込先情報の取得
```php
// frontend/bank-transfer-info.php (line 43-65)
// Stripeから振込先情報を取得して表示
```

### 3. 自動確認処理
```php
// backend/api/payment/webhook.php (line 43-87)
// payment_intent.succeeded イベントで自動的にステータス更新
```

## 次のステップ

1. ✅ Stripe Dashboardで銀行振込を有効化
2. ✅ Webhookエンドポイントを設定
3. ✅ テストモードで動作確認
4. ✅ 本番環境で動作確認

## 参考リンク

- [Stripe日本銀行振込ドキュメント](https://stripe.com/docs/payments/bank-transfers)
- [Stripe Customer Balance](https://stripe.com/docs/payments/customer-balance)
- [Stripe Webhooks](https://stripe.com/docs/webhooks)

