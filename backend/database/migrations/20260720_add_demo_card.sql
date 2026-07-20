-- 体験版（デモ）名刺のサポート。
-- プロモーション用に、SMS認証なしでチャットを試せる名刺を1枚用意するための列。
--
-- 設計上の要点:
--   デモ名刺では訪問者ごとに別セッションを発行する。ダミーの電話番号を
--   chat_verified_phones へ登録して認証を迂回する方式は採らない。あの方式では
--   1つの電話番号が1つの last_session_id しか持てないため（chat-phone-helper.php の
--   chatFindSessionByVerifiedPhone は LIMIT 1）、体験者全員が同一セッションを共有し、
--   他人のチャット履歴・ヒアリング内容が見えてしまう。

ALTER TABLE business_cards
    ADD COLUMN is_demo TINYINT(1) NOT NULL DEFAULT 0 AFTER is_published;

-- デモセッションは通常の相談履歴と混ざらないよう印を付け、expires_at で自動失効させる。
ALTER TABLE chat_sessions
    ADD COLUMN is_demo TINYINT(1) NOT NULL DEFAULT 0 AFTER visitor_identifier;

ALTER TABLE chat_sessions
    ADD COLUMN expires_at DATETIME NULL AFTER is_demo;

ALTER TABLE chat_sessions
    ADD INDEX idx_chat_sessions_demo_expiry (is_demo, expires_at);
