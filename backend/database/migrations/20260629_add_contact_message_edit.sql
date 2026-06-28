-- 担当連絡（顧客↔担当）メッセージの「編集」「取り消し（unsend）」対応。
-- 顧客が自分の送信済みメッセージを後から編集・取り消しできるようにする。
--   edited_at  … 最後に編集した時刻（NULL=未編集）。UIで「編集済み」を表示。
--   deleted_at … 取り消した時刻（NULL=有効）。ソフト削除し、本文は表示時にマスクする。

ALTER TABLE chat_messages
  ADD COLUMN edited_at TIMESTAMP NULL DEFAULT NULL AFTER read_at,
  ADD COLUMN deleted_at TIMESTAMP NULL DEFAULT NULL AFTER edited_at;
