# セルフィンPro 会員判定API連携

不動産AI名刺のチャットでお客様に登録いただいたメールアドレスを、サーバー側から
セルフィンProの確認APIへ送信し、**登録有無のみ**を受け取って案内文を出し分ける。

## 1. 連携仕様（双方合意済み）

| 項目 | 内容 |
| --- | --- |
| エンドポイント | `POST https://self-in.com/api/v1/member/check` |
| Content-Type | `application/x-www-form-urlencoded` |
| リクエスト | `key={APIキー}&mail={メールアドレス}` |
| 認証 | APIキー（**ヘッダーではなくPOSTパラメータ**）＋ アクセス元IP制限 |
| 呼び出し元IP | `85.131.209.117`（本番Xserverのグローバルアドレス。先方ホワイトリスト登録済み） |
| レスポンス | `{"exists": true}` / `{"exists": false}` |
| 401 | IP制限またはAPIキーのチェックに問題あり。先方へ状況確認を依頼する |

- 個人情報保護の観点から、初期実装では登録有無のみを受け取る（会社名・契約情報・プラン情報は受け取らない）。
- 呼び出しは**必ずサーバー経由**。フロントエンドから直接叩かない（IP制限とAPIキー秘匿のため）。
- IPアドレスは通常変わらないが、サーバー移行・メンテナンス等で変更される可能性はゼロではない。
  変更時は事前にセルフィン側へ共有し、ホワイトリストを更新してもらう運用とする。

## 2. APIキーの設定

APIキーはリポジトリに含めない。次のいずれかで設定する。

```php
// backend/config/secrets.php （.gitignore 済み）
define('SELFIN_MEMBER_CHECK_KEY', '＜セルフィンから発行されたAPIキー＞');
```

```bash
# または環境変数
SELFIN_MEMBER_CHECK_KEY=＜セルフィンから発行されたAPIキー＞
```

未設定の場合は連携を行わず、チャットの挙動は連携前とまったく同じになる（案内文を出さない）。

その他の設定（`backend/config/config.php`）:

| 定数 | 既定値 | 用途 |
| --- | --- | --- |
| `SELFIN_MEMBER_CHECK_URL` | `https://self-in.com/api/v1/member/check` | 問い合わせ先 |
| `SELFIN_MEMBER_CHECK_KEY` | （空） | APIキー |
| `SELFIN_MEMBER_CHECK_TIMEOUT` | `8`（秒） | タイムアウト |
| `SELFIN_MEMBER_CHECK_ENABLED` | 有効 | `0` で連携のみ停止 |

## 3. 動作

1. チャットの本人登録（SMS認証 → お名前 → メールアドレス）でメールアドレスが登録される。
2. `backend/api/chat/profile/save.php` がサーバー側からセルフィンPro確認APIを呼ぶ。
3. `exists` の結果に応じた案内文をレスポンスに含め、チャットへボット発言として表示する。

```text
exists = true（ご利用中）
  セルフィンProをご利用いただきありがとうございます。
  不動産AI名刺内の「不動産テックツール」からセルフィンProをはじめ各種ツールをご利用いただけます。
  また、いずれか1つのツールで利用登録いただくと、他の対象ツールも共通アカウントでご利用いただけます。

exists = false（未利用）
  不動産AI名刺内の「不動産テックツール」からセルフィンProをご利用いただけます。
  また、いずれか1つのツールで利用登録いただくと、他の対象ツールも共通アカウントでご利用いただけます。
  まずは不動産テックツールよりご登録ください。
```

判定できなかった場合（APIキー未設定・通信失敗・401・想定外レスポンス）は**案内文を出さない**。
本人情報の登録処理自体は必ず完了させ、チャットは従来どおり進む。

判定結果は `chat_leads.structured_data` にキャッシュする（`_selfin_pro_exists` /
`_selfin_pro_email_hash` / `_selfin_pro_checked_at` / `_selfin_pro_notified_at`）。
同じメールアドレスで外部APIを繰り返し呼ばず、同じ案内をチャット内で繰り返さない。
メールアドレス自体は重複保存せず、突合用のハッシュのみを保持する。

## 4. 疎通確認

本番サーバー上で実行する（IP制限のため手元のPCからは401になる）。

```bash
php backend/scripts/test_selfin_member_check.php user@example.com
```

設定値（APIキーは伏せ字）、HTTPステータス、`exists` の判定、実際にチャットへ表示される
案内文を出力する。401の場合は切り分け手順を表示する。

## 5. 関連ファイル

| ファイル | 役割 |
| --- | --- |
| `backend/config/config.php` | 接続設定（URL・APIキー・タイムアウト・有効化フラグ） |
| `backend/includes/selfin-member-helper.php` | API呼び出し・レスポンス解析・案内文・結果キャッシュ |
| `backend/api/chat/profile/save.php` | メールアドレス登録時に判定し `selfin_message` を返す |
| `assets/js/chat-widget.js` | 返ってきた案内文をチャットに表示 |
| `backend/scripts/test_selfin_member_check.php` | 疎通確認スクリプト |
