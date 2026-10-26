-- 売主仲介会社から担当エージェントへのメッセージ（★2026/10/3 追加ご依頼）
-- 回答画面の「3. メッセージ」に入力された内容。回答（承諾／候補日時では内見不可／成約・申込済み）と一緒に保存し、
-- 担当エージェントへのメール（M05／N01／N02）と担当者画面に表示する。
-- viewing-helper.php の viewingEnsureColumns() でも自動追加される。
ALTER TABLE property_viewings
  ADD COLUMN seller_message TEXT NULL DEFAULT NULL AFTER unavailable_reason,
  ADD COLUMN seller_message_at DATETIME NULL DEFAULT NULL AFTER seller_message;
