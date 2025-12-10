# Bank Transfer Troubleshooting Guide

## 問題: 銀行振込情報が取得できない

### 考えられる原因

1. **Stripe Dashboardでの設定不足**
   - Japanese bank transfers が有効化されていない
   - Customer balance が有効化されていない

2. **PaymentIntentの作成方法の問題**
   - PaymentIntentが正しく確認されていない
   - 支払い方法が正しく添付されていない

3. **APIレスポンスの構造の違い**
   - StripeのAPIバージョンによる差異
   - テストモードと本番モードの動作の違い

## 修正内容

### 1. PaymentIntent作成の改善 (`backend/api/payment/create-intent.php`)
- PaymentIntent作成時に `confirm: true` を設定
- `payment_method_data` を適切に設定
- 詳細なエラーログを追加

### 2. 銀行振込情報取得の改善 (`frontend/bank-transfer-info.php`)
- 複数の方法で情報取得を試行
- PaymentIntentの詳細ログを出力
- より詳細なエラーメッセージを表示

## デバッグ方法

### ステップ1: エラーログを確認
```bash
# PHPエラーログを確認
tail -f /path/to/php/error_log

# 以下の情報が記録されます:
# - PaymentIntent Status
# - PaymentIntent Data (JSON)
# - Next action information
# - Bank transfer info extraction
```

### ステップ2: Stripe Dashboardで確認
1. Stripe Dashboard → Payments
2. 作成されたPaymentIntentを確認
3. ステータスと詳細情報を確認

### ステップ3: PaymentIntentを手動で確認
```php
// デバッグ用コード（一時的に追加）
$paymentIntent = PaymentIntent::retrieve($paymentIntentId);
error_log("Full PaymentIntent: " . json_encode($paymentIntent, JSON_PRETTY_PRINT));
```

## 確認事項チェックリスト

- [ ] Stripe Dashboard → Settings → Payment methods
  - [ ] "Bank transfers" が有効
  - [ ] "Japanese bank transfers" が有効
  - [ ] "Customer balance" が有効

- [ ] テストモード/本番モード
  - [ ] 使用中のAPIキーが正しいモードか確認
  - [ ] テストモードで試している場合は、テスト設定を確認

- [ ] PaymentIntentの状態
  - [ ] ステータスが `requires_action` になっているか
  - [ ] `next_action` が存在するか
  - [ ] `next_action.type` が `display_bank_transfer_instructions` か

## 代替案: 手動銀行振込情報の実装

もしStripeの自動機能が利用できない場合、手動で銀行口座情報を表示する方法もあります:

1. 設定ファイルに銀行口座情報を追加
2. PaymentIntentが取得できない場合に手動情報を表示

この実装が必要な場合はお知らせください。

